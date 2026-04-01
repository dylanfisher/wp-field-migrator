<?php

namespace WPFM;

if (!defined('ABSPATH')) {
	exit;
}

class Admin_Page {
	private const PAGE_SLUG = 'wp-field-migrator';
	private const RUN_HISTORY_OPTION = 'wpfm_run_history';
	private const RUN_HISTORY_LIMIT = 25;
	private const RUN_BATCH_LOG_LIMIT = 500;

	private $service;

	public function __construct(Migration_Service $service) {
		$this->service = $service;
	}

	public function register() {
		add_action('admin_menu', [$this, 'register_menu']);
		add_action('wp_ajax_wpfm_fields_for_post_type', [$this, 'ajax_fields_for_post_type']);
		add_action('wp_ajax_wpfm_run_migration_batch', [$this, 'ajax_run_migration_batch']);
		add_action('wp_ajax_wpfm_preview_batch', [$this, 'ajax_preview_batch']);
		add_action('wp_ajax_wpfm_start_run', [$this, 'ajax_start_run']);
		add_action('wp_ajax_wpfm_finalize_run', [$this, 'ajax_finalize_run']);
		add_action('admin_post_wpfm_preview', [$this, 'handle_preview_post']);
		add_action('admin_post_wpfm_export_dry_run_csv', [$this, 'handle_export_dry_run_csv']);
		add_action('admin_post_wpfm_download_run_report', [$this, 'handle_download_run_report']);
	}

	public function register_menu() {
		add_management_page(
			__('Field Migrator', 'wp-field-migrator'),
			__('Field Migrator', 'wp-field-migrator'),
			'manage_options',
			self::PAGE_SLUG,
			[$this, 'render_page']
		);

		$basename_slug = plugin_basename(WPFM_PLUGIN_FILE);
		if ($basename_slug !== self::PAGE_SLUG) {
			add_submenu_page(
				null,
				__('Field Migrator', 'wp-field-migrator'),
				__('Field Migrator', 'wp-field-migrator'),
				'manage_options',
				$basename_slug,
				[$this, 'render_page']
			);
		}

		add_submenu_page(
			null,
			__('Field Migrator', 'wp-field-migrator'),
			__('Field Migrator', 'wp-field-migrator'),
			'manage_options',
			self::PAGE_SLUG,
			[$this, 'render_page']
		);
	}

	public function render_page() {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You do not have permission to access this page.', 'wp-field-migrator'));
		}

		$config         = $this->get_config($_GET);
		$errors         = [];
		$preview_result = null;

		$flash_key = isset($_GET['wpfm_result']) ? sanitize_key(wp_unslash($_GET['wpfm_result'])) : '';
		if ($flash_key !== '') {
			$stored = get_transient('wpfm_result_' . get_current_user_id() . '_' . $flash_key);
			if (is_array($stored)) {
				$config         = isset($stored['config']) && is_array($stored['config']) ? $stored['config'] : $config;
				$errors         = isset($stored['errors']) && is_array($stored['errors']) ? $stored['errors'] : [];
				$preview_result = isset($stored['preview']) && is_array($stored['preview']) ? $stored['preview'] : null;
			}
			delete_transient('wpfm_result_' . get_current_user_id() . '_' . $flash_key);
		}

		$available_fields = $this->get_available_fields($config['post_type'], $config['show_private_keys']);
		$run_history      = $this->get_run_history();
		$this->render_markup($config, $errors, $available_fields, $preview_result, $run_history);
	}

	public function handle_preview_post() {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You do not have permission to run previews.', 'wp-field-migrator'));
		}

		check_admin_referer('wpfm_migrate', 'wpfm_nonce');

		$config = $this->get_config($_POST);

		$nav = isset($_POST['preview_nav']) ? sanitize_key(wp_unslash($_POST['preview_nav'])) : '';
		if ($nav === 'prev') {
			$config['preview_page'] = max(1, $config['preview_page'] - 1);
		} elseif ($nav === 'next') {
			$config['preview_page'] = $config['preview_page'] + 1;
		}

		$errors = $this->validate_config($config);
		$preview_result = null;

		if (empty($errors)) {
			$preview_result = $this->service->preview($config);
			$config['preview_page'] = isset($preview_result['page']) ? (int) $preview_result['page'] : $config['preview_page'];
		}

		$this->store_flash_and_redirect($config, $errors, $preview_result);
	}

	public function ajax_fields_for_post_type() {
		if (!current_user_can('manage_options')) {
			wp_send_json_error(['message' => __('Unauthorized.', 'wp-field-migrator')], 403);
		}

		check_ajax_referer('wpfm_fields_nonce', 'nonce');

		$post_type = isset($_POST['post_type']) ? sanitize_key(wp_unslash($_POST['post_type'])) : '';
		if ($post_type === '' || !post_type_exists($post_type)) {
			wp_send_json_error(['message' => __('Invalid post type.', 'wp-field-migrator')], 400);
		}

		$show_private_keys = isset($_POST['show_private_keys']) && wp_unslash($_POST['show_private_keys']) === '1';
		wp_send_json_success(['fields' => $this->get_available_fields($post_type, $show_private_keys)]);
	}

	public function ajax_run_migration_batch() {
		if (!current_user_can('manage_options')) {
			wp_send_json_error(['message' => __('Unauthorized.', 'wp-field-migrator')], 403);
		}

		check_ajax_referer('wpfm_migrate_async_nonce', 'nonce');

		$config = $this->get_config($_POST);
		$errors = $this->validate_config($config);
		if (!empty($errors)) {
			wp_send_json_error(['message' => implode(' ', $errors)], 400);
		}

		$cursor     = isset($_POST['cursor']) ? absint(wp_unslash($_POST['cursor'])) : 0;
		$batch_size = $config['migration_batch_size'];
		$result     = $this->service->migrate_batch($config, $cursor, $batch_size);
		$run_id     = isset($_POST['run_id']) ? sanitize_text_field(wp_unslash($_POST['run_id'])) : '';

		if ($run_id !== '') {
			$this->append_run_batch_log($run_id, $cursor, $result);
			if (empty($result['has_more'])) {
				$this->finalize_run($run_id, 'completed', '');
			}
		}

		wp_send_json_success($result);
	}

	public function ajax_preview_batch() {
		if (!current_user_can('manage_options')) {
			wp_send_json_error(['message' => __('Unauthorized.', 'wp-field-migrator')], 403);
		}

		check_ajax_referer('wpfm_migrate_async_nonce', 'nonce');

		$config = $this->get_config($_POST);
		$errors = $this->validate_config($config);
		if (!empty($errors)) {
			wp_send_json_error(['message' => implode(' ', $errors)], 400);
		}

		$cursor     = isset($_POST['cursor']) ? absint(wp_unslash($_POST['cursor'])) : 0;
		$batch_size = $config['migration_batch_size'];
		$result     = $this->service->preview_batch($config, $cursor, $batch_size);
		$result['rows'] = $this->format_preview_rows_for_json($result['rows']);

		wp_send_json_success($result);
	}

	public function ajax_start_run() {
		if (!current_user_can('manage_options')) {
			wp_send_json_error(['message' => __('Unauthorized.', 'wp-field-migrator')], 403);
		}

		check_ajax_referer('wpfm_migrate_async_nonce', 'nonce');

		$config = $this->get_config($_POST);
		$errors = $this->validate_config($config);
		if (!empty($errors)) {
			wp_send_json_error(['message' => implode(' ', $errors)], 400);
		}

		$run = $this->create_run($config);
		wp_send_json_success(['run_id' => $run['id']]);
	}

	public function ajax_finalize_run() {
		if (!current_user_can('manage_options')) {
			wp_send_json_error(['message' => __('Unauthorized.', 'wp-field-migrator')], 403);
		}

		check_ajax_referer('wpfm_migrate_async_nonce', 'nonce');

		$run_id  = isset($_POST['run_id']) ? sanitize_text_field(wp_unslash($_POST['run_id'])) : '';
		$status  = isset($_POST['status']) ? sanitize_key(wp_unslash($_POST['status'])) : 'failed';
		$message = isset($_POST['message']) ? sanitize_text_field(wp_unslash($_POST['message'])) : '';
		if ($run_id === '') {
			wp_send_json_error(['message' => __('Missing run ID.', 'wp-field-migrator')], 400);
		}

		if (!in_array($status, ['completed', 'failed', 'stopped'], true)) {
			$status = 'failed';
		}

		$this->finalize_run($run_id, $status, $message);
		wp_send_json_success(['run_id' => $run_id, 'status' => $status]);
	}

	public function handle_export_dry_run_csv() {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You do not have permission to export dry-run data.', 'wp-field-migrator'));
		}

		check_admin_referer('wpfm_migrate', 'wpfm_nonce');

		$config = $this->get_config($_POST);
		$errors = $this->validate_config($config);
		if (!empty($errors)) {
			wp_die(esc_html(implode(' ', $errors)));
		}

		$filename = sprintf(
			'wpfm-dry-run-%s-%s.csv',
			sanitize_key($config['post_type']),
			gmdate('Ymd-His')
		);

		nocache_headers();
		header('Content-Type: text/csv; charset=utf-8');
		header('Content-Disposition: attachment; filename=' . $filename);

		$output = fopen('php://output', 'w');
		if ($output === false) {
			wp_die(esc_html__('Could not open output stream.', 'wp-field-migrator'));
		}

		fputcsv($output, ['post_id', 'post_title', 'source_value', 'target_value', 'new_value', 'will_change', 'edit_post_url']);

		$cursor = 0;
		$loops = 0;
		$batch_size = $config['migration_batch_size'];
		do {
			$loops++;
			$result = $this->service->preview_batch($config, $cursor, $batch_size);
			$rows   = isset($result['rows']) && is_array($result['rows']) ? $result['rows'] : [];

			foreach ($rows as $row) {
				fputcsv($output, [
					isset($row['post_id']) ? absint($row['post_id']) : 0,
					isset($row['post_title']) ? (string) $row['post_title'] : '',
					$this->csv_value(isset($row['source_value']) ? $row['source_value'] : null),
					$this->csv_value(isset($row['target_value']) ? $row['target_value'] : null),
					$this->csv_value(isset($row['new_value']) ? $row['new_value'] : null),
					!empty($row['will_change']) ? 'yes' : 'no',
					isset($row['edit_post_url']) ? (string) $row['edit_post_url'] : '',
				]);
			}

			$cursor = isset($result['next_cursor']) ? absint($result['next_cursor']) : $cursor;
			$has_more = !empty($result['has_more']);
		} while ($has_more && $loops < 20000);

		fclose($output);
		exit;
	}

	public function handle_download_run_report() {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You do not have permission to download run reports.', 'wp-field-migrator'));
		}

		check_admin_referer('wpfm_download_run_report', 'wpfm_nonce');
		$run_id = isset($_GET['run_id']) ? sanitize_text_field(wp_unslash($_GET['run_id'])) : '';
		if ($run_id === '') {
			wp_die(esc_html__('Missing run ID.', 'wp-field-migrator'));
		}

		$run = $this->get_run_by_id($run_id);
		if (!$run) {
			wp_die(esc_html__('Run report not found.', 'wp-field-migrator'));
		}

		$filename = sprintf('wpfm-run-report-%s.csv', preg_replace('/[^a-zA-Z0-9\-_]/', '-', $run_id));
		nocache_headers();
		header('Content-Type: text/csv; charset=utf-8');
		header('Content-Disposition: attachment; filename=' . $filename);

		$output = fopen('php://output', 'w');
		if ($output === false) {
			wp_die(esc_html__('Could not open output stream.', 'wp-field-migrator'));
		}

		$summary = isset($run['summary']) && is_array($run['summary']) ? $run['summary'] : [];
		$config  = isset($run['config']) && is_array($run['config']) ? $run['config'] : [];

		fputcsv($output, ['type', 'run_id', 'status', 'started_at_utc', 'finished_at_utc', 'post_type', 'source_field', 'target_field', 'batch_size', 'processed', 'updated', 'skipped_empty_source', 'skipped_unchanged', 'failed_count', 'failed_ids', 'message']);
		fputcsv($output, [
			'summary',
			isset($run['id']) ? (string) $run['id'] : '',
			isset($run['status']) ? (string) $run['status'] : '',
			isset($run['started_at']) ? (string) $run['started_at'] : '',
			isset($run['finished_at']) ? (string) $run['finished_at'] : '',
			isset($config['post_type']) ? (string) $config['post_type'] : '',
			isset($config['source_field']) ? (string) $config['source_field'] : '',
			isset($config['target_field']) ? (string) $config['target_field'] : '',
			isset($config['migration_batch_size']) ? absint($config['migration_batch_size']) : 0,
			isset($summary['processed']) ? absint($summary['processed']) : 0,
			isset($summary['updated']) ? absint($summary['updated']) : 0,
			isset($summary['skipped_empty_source']) ? absint($summary['skipped_empty_source']) : 0,
			isset($summary['skipped_unchanged']) ? absint($summary['skipped_unchanged']) : 0,
			isset($summary['failed_count']) ? absint($summary['failed_count']) : 0,
			isset($summary['failed_ids']) && is_array($summary['failed_ids']) ? implode('|', array_map('strval', $summary['failed_ids'])) : '',
			isset($run['message']) ? (string) $run['message'] : '',
		]);

		fputcsv($output, ['type', 'batch_index', 'started_cursor', 'next_cursor', 'processed', 'updated', 'skipped_empty_source', 'skipped_unchanged', 'failed_ids']);
		$batches = isset($run['batches']) && is_array($run['batches']) ? $run['batches'] : [];
		foreach ($batches as $batch) {
			if (!is_array($batch)) {
				continue;
			}
			fputcsv($output, [
				'batch',
				isset($batch['index']) ? absint($batch['index']) : 0,
				isset($batch['cursor']) ? absint($batch['cursor']) : 0,
				isset($batch['next_cursor']) ? absint($batch['next_cursor']) : 0,
				isset($batch['processed']) ? absint($batch['processed']) : 0,
				isset($batch['updated']) ? absint($batch['updated']) : 0,
				isset($batch['skipped_empty_source']) ? absint($batch['skipped_empty_source']) : 0,
				isset($batch['skipped_unchanged']) ? absint($batch['skipped_unchanged']) : 0,
				isset($batch['failed_ids']) && is_array($batch['failed_ids']) ? implode('|', array_map('strval', $batch['failed_ids'])) : '',
			]);
		}

		fclose($output);
		exit;
	}

	private function get_config($input) {
		$post_type = isset($input['post_type']) ? sanitize_key(wp_unslash($input['post_type'])) : 'post';

		$source_select = isset($input['source_field']) ? sanitize_text_field(wp_unslash($input['source_field'])) : '';
		$target_select = isset($input['target_field']) ? sanitize_text_field(wp_unslash($input['target_field'])) : '';
		$source_custom = isset($input['source_field_custom']) ? sanitize_text_field(wp_unslash($input['source_field_custom'])) : '';
		$target_custom = isset($input['target_field_custom']) ? sanitize_text_field(wp_unslash($input['target_field_custom'])) : '';

		$source_is_custom = $source_select === '__custom__';
		$target_is_custom = $target_select === '__custom__';
		$source_field     = $source_is_custom ? $source_custom : $source_select;
		$target_field     = $target_is_custom ? $target_custom : $target_select;

		$show_private_keys = isset($input['show_private_keys']) && wp_unslash($input['show_private_keys']) === '1';

		$preview_page      = isset($input['preview_page']) ? absint(wp_unslash($input['preview_page'])) : 1;
		$preview_per_page  = isset($input['preview_per_page']) ? absint(wp_unslash($input['preview_per_page'])) : 25;
		$migration_batch   = isset($input['migration_batch_size']) ? absint(wp_unslash($input['migration_batch_size'])) : 200;

		return [
			'post_type'            => $post_type,
			'source_field'         => $source_field,
			'target_field'         => $target_field,
			'source_is_custom'     => $source_is_custom,
			'target_is_custom'     => $target_is_custom,
			'source_custom'        => $source_custom,
			'target_custom'        => $target_custom,
			'show_private_keys'    => $show_private_keys,
			'preview_page'         => max(1, $preview_page),
			'preview_per_page'     => max(1, min(200, $preview_per_page)),
			'migration_batch_size' => max(1, min(1000, $migration_batch)),
		];
	}

	private function validate_config(array $config) {
		$errors = [];

		if (empty($config['post_type']) || !post_type_exists($config['post_type'])) {
			$errors[] = __('Select a valid post type.', 'wp-field-migrator');
		}

		if (empty($config['source_field'])) {
			$errors[] = __('Source field is required.', 'wp-field-migrator');
		}

		if (empty($config['target_field'])) {
			$errors[] = __('Target field is required.', 'wp-field-migrator');
		}

		if (!empty($config['source_field']) && !empty($config['target_field']) && $config['source_field'] === $config['target_field']) {
			$errors[] = __('Source and target fields must be different.', 'wp-field-migrator');
		}

		return $errors;
	}

	private function render_markup(array $config, array $errors, array $available_fields, $preview_result, array $run_history) {
		$post_types = get_post_types(['show_ui' => true], 'objects');
		$fields_nonce = wp_create_nonce('wpfm_fields_nonce');
		$migrate_nonce = wp_create_nonce('wpfm_migrate_async_nonce');

		$source_select_value = $config['source_is_custom'] ? '__custom__' : $config['source_field'];
		$target_select_value = $config['target_is_custom'] ? '__custom__' : $config['target_field'];
		?>
		<div class="wrap">
			<h1><?php esc_html_e('Field Migrator', 'wp-field-migrator'); ?></h1>
			<p><?php esc_html_e('Preview is paginated. Migration runs asynchronously in keyset batches and skips unchanged/empty rows.', 'wp-field-migrator'); ?></p>
			<p><strong><?php esc_html_e('Warning:', 'wp-field-migrator'); ?></strong> <?php esc_html_e('Back up your database before using this tool. Migrations can irreversibly modify post data.', 'wp-field-migrator'); ?></p>

			<?php foreach ($errors as $error) : ?>
				<div class="notice notice-error"><p><?php echo esc_html($error); ?></p></div>
			<?php endforeach; ?>

			<form id="wpfm-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
				<?php wp_nonce_field('wpfm_migrate', 'wpfm_nonce'); ?>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><label for="post_type"><?php esc_html_e('Post Type', 'wp-field-migrator'); ?></label></th>
							<td>
								<select name="post_type" id="post_type">
									<?php foreach ($post_types as $post_type) : ?>
										<option value="<?php echo esc_attr($post_type->name); ?>" <?php selected($config['post_type'], $post_type->name); ?>>
											<?php echo esc_html($post_type->labels->singular_name . ' (' . $post_type->name . ')'); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="source_field"><?php esc_html_e('Source Field', 'wp-field-migrator'); ?></label></th>
							<td>
								<?php $this->render_field_select('source_field', $available_fields, $source_select_value); ?>
								<div id="source_field_custom_wrap" style="margin-top:8px; <?php echo $config['source_is_custom'] ? '' : 'display:none;'; ?>">
									<input type="text" class="regular-text" id="source_field_custom" name="source_field_custom" value="<?php echo esc_attr($config['source_custom']); ?>" placeholder="description" />
								</div>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="target_field"><?php esc_html_e('Target Field', 'wp-field-migrator'); ?></label></th>
							<td>
								<?php $this->render_field_select('target_field', $available_fields, $target_select_value); ?>
								<div id="target_field_custom_wrap" style="margin-top:8px; <?php echo $config['target_is_custom'] ? '' : 'display:none;'; ?>">
									<input type="text" class="regular-text" id="target_field_custom" name="target_field_custom" value="<?php echo esc_attr($config['target_custom']); ?>" placeholder="location" />
								</div>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e('Preview Pagination', 'wp-field-migrator'); ?></th>
							<td>
								<label>
									<?php esc_html_e('Page', 'wp-field-migrator'); ?>
									<input type="number" id="preview_page" name="preview_page" min="1" value="<?php echo esc_attr((string) $config['preview_page']); ?>" />
								</label>
								<label style="margin-left:16px;">
									<?php esc_html_e('Per Page', 'wp-field-migrator'); ?>
									<input type="number" id="preview_per_page" name="preview_per_page" min="1" max="200" value="<?php echo esc_attr((string) $config['preview_per_page']); ?>" />
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="migration_batch_size"><?php esc_html_e('Migration Batch Size', 'wp-field-migrator'); ?></label></th>
							<td>
								<input type="number" id="migration_batch_size" name="migration_batch_size" min="1" max="1000" value="<?php echo esc_attr((string) $config['migration_batch_size']); ?>" />
								<p class="description"><?php esc_html_e('Async migration runs all posts in batches using cursor pagination (ID > last_id).', 'wp-field-migrator'); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e('Field Visibility', 'wp-field-migrator'); ?></th>
							<td>
								<label for="show_private_keys">
									<input type="checkbox" id="show_private_keys" name="show_private_keys" value="1" <?php checked(!empty($config['show_private_keys'])); ?> />
									<?php esc_html_e('Show internal/private meta keys (starting with _)', 'wp-field-migrator'); ?>
								</label>
							</td>
						</tr>
					</tbody>
				</table>

				<p class="submit">
					<input type="hidden" id="preview_nav_field" name="preview_nav" value="" />
					<button type="submit" name="action" value="wpfm_preview" onclick="document.getElementById('preview_nav_field').value='';" class="button button-secondary" title="<?php echo esc_attr__('Run preview for the selected page and per-page size.', 'wp-field-migrator'); ?>"><?php esc_html_e('Preview Changes', 'wp-field-migrator'); ?></button>
					<button type="submit" name="action" value="wpfm_preview" onclick="document.getElementById('preview_nav_field').value='prev';" class="button" title="<?php echo esc_attr__('Go to previous preview page and refresh results.', 'wp-field-migrator'); ?>"><?php esc_html_e('Prev Page', 'wp-field-migrator'); ?></button>
					<button type="submit" name="action" value="wpfm_preview" onclick="document.getElementById('preview_nav_field').value='next';" class="button" title="<?php echo esc_attr__('Go to next preview page and refresh results.', 'wp-field-migrator'); ?>"><?php esc_html_e('Next Page', 'wp-field-migrator'); ?></button>
				</p>
				<p class="submit" style="margin-top:0;">
					<button type="button" id="wpfm-preview-all-async" class="button" title="<?php echo esc_attr__('Scan all posts in async batches and append preview rows to the table.', 'wp-field-migrator'); ?>"><?php esc_html_e('Preview All Changes (Append)', 'wp-field-migrator'); ?></button>
					<button type="button" id="wpfm-preview-all-stop" class="button" style="display:none;" title="<?php echo esc_attr__('Stop appending additional preview rows after the current batch.', 'wp-field-migrator'); ?>"><?php esc_html_e('Stop Preview Appending', 'wp-field-migrator'); ?></button>
					<button type="submit" name="action" value="wpfm_export_dry_run_csv" class="button" title="<?php echo esc_attr__('Download CSV of all preview rows across all batches without writing any changes.', 'wp-field-migrator'); ?>"><?php esc_html_e('Export Dry-Run CSV (All Posts)', 'wp-field-migrator'); ?></button>
					<button type="button" id="wpfm-run-async" class="button button-primary" title="<?php echo esc_attr__('Run migration across all posts in async batches; updates only rows that need changes.', 'wp-field-migrator'); ?>"><?php esc_html_e('Run Migration (Async, All Posts)', 'wp-field-migrator'); ?></button>
				</p>
			</form>

			<div id="wpfm-migration-status" class="notice" style="display:none;"><p></p></div>

			<h2><?php esc_html_e('Run History', 'wp-field-migrator'); ?></h2>
			<?php if (empty($run_history)) : ?>
				<p><?php esc_html_e('No migration runs recorded yet.', 'wp-field-migrator'); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e('Started', 'wp-field-migrator'); ?></th>
							<th><?php esc_html_e('Status', 'wp-field-migrator'); ?></th>
							<th><?php esc_html_e('Post Type', 'wp-field-migrator'); ?></th>
							<th><?php esc_html_e('Mapping', 'wp-field-migrator'); ?></th>
							<th><?php esc_html_e('Processed', 'wp-field-migrator'); ?></th>
							<th><?php esc_html_e('Updated', 'wp-field-migrator'); ?></th>
							<th><?php esc_html_e('Failed IDs', 'wp-field-migrator'); ?></th>
							<th><?php esc_html_e('Report', 'wp-field-migrator'); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($run_history as $run) : ?>
							<?php
							$summary = isset($run['summary']) && is_array($run['summary']) ? $run['summary'] : [];
							$config_row = isset($run['config']) && is_array($run['config']) ? $run['config'] : [];
							$failed_ids = isset($summary['failed_ids']) && is_array($summary['failed_ids']) ? $summary['failed_ids'] : [];
							$download_url = wp_nonce_url(
								add_query_arg(
									[
										'action' => 'wpfm_download_run_report',
										'run_id' => isset($run['id']) ? sanitize_text_field((string) $run['id']) : '',
									],
									admin_url('admin-post.php')
								),
								'wpfm_download_run_report',
								'wpfm_nonce'
							);
							?>
							<tr>
								<td><?php echo esc_html($this->format_run_time(isset($run['started_at']) ? (string) $run['started_at'] : '')); ?></td>
								<td><?php echo esc_html($this->format_run_status(isset($run['status']) ? (string) $run['status'] : '')); ?></td>
								<td><?php echo esc_html(isset($config_row['post_type']) ? (string) $config_row['post_type'] : ''); ?></td>
								<td><?php echo esc_html(sprintf('%s -> %s', isset($config_row['source_field']) ? (string) $config_row['source_field'] : '', isset($config_row['target_field']) ? (string) $config_row['target_field'] : '')); ?></td>
								<td><?php echo esc_html((string) (isset($summary['processed']) ? absint($summary['processed']) : 0)); ?></td>
								<td><?php echo esc_html((string) (isset($summary['updated']) ? absint($summary['updated']) : 0)); ?></td>
								<td><?php echo esc_html($failed_ids ? implode(', ', array_map('strval', $failed_ids)) : '0'); ?></td>
								<td><a href="<?php echo esc_url($download_url); ?>"><?php esc_html_e('Download CSV', 'wp-field-migrator'); ?></a></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<?php if (is_array($preview_result)) : ?>
				<h2><?php esc_html_e('Preview Results', 'wp-field-migrator'); ?></h2>
				<p>
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: page, 2: total pages, 3: scanned count, 4: posts to update */
							__('Page %1$d of %2$d. Scanned %3$d posts on this page. %4$d would be updated.', 'wp-field-migrator'),
							(int) $preview_result['page'],
							max(1, (int) $preview_result['total_pages']),
							(int) $preview_result['queried_posts'],
							(int) $preview_result['posts_to_update']
						)
					);
					?>
				</p>

				<?php if (empty($preview_result['rows'])) : ?>
					<p><?php esc_html_e('No matching posts found on this preview page.', 'wp-field-migrator'); ?></p>
				<?php else : ?>
					<table class="widefat striped" id="wpfm-preview-table">
						<thead>
							<tr>
								<th><?php esc_html_e('Post', 'wp-field-migrator'); ?></th>
								<th><?php esc_html_e('Source Value', 'wp-field-migrator'); ?></th>
								<th><?php esc_html_e('Current Target', 'wp-field-migrator'); ?></th>
								<th><?php esc_html_e('New Target', 'wp-field-migrator'); ?></th>
								<th><?php esc_html_e('Will Change', 'wp-field-migrator'); ?></th>
							</tr>
						</thead>
						<tbody id="wpfm-preview-tbody">
							<?php foreach ($preview_result['rows'] as $row) : ?>
								<tr>
									<td>
										<a href="<?php echo esc_url($row['edit_post_url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($row['post_title'] ?: ('#' . $row['post_id'])); ?></a>
										(<?php echo esc_html((string) $row['post_id']); ?>)
									</td>
									<td><?php echo esc_html($this->format_value($row['source_value'])); ?></td>
									<td><?php echo esc_html($this->format_value($row['target_value'])); ?></td>
									<td><?php echo esc_html($this->format_value($row['new_value'])); ?></td>
									<td>
										<?php if (!empty($row['will_change'])) : ?>
											<strong><?php esc_html_e('Yes', 'wp-field-migrator'); ?></strong>
										<?php else : ?>
											<?php esc_html_e('No', 'wp-field-migrator'); ?>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<script>
		(function() {
			const postType = document.getElementById('post_type');
			const source = document.getElementById('source_field');
			const target = document.getElementById('target_field');
			const sourceCustomWrap = document.getElementById('source_field_custom_wrap');
			const targetCustomWrap = document.getElementById('target_field_custom_wrap');
			const sourceCustom = document.getElementById('source_field_custom');
			const targetCustom = document.getElementById('target_field_custom');
			const showPrivate = document.getElementById('show_private_keys');
			const runAsync = document.getElementById('wpfm-run-async');
			const previewAllAsync = document.getElementById('wpfm-preview-all-async');
			const previewAllStop = document.getElementById('wpfm-preview-all-stop');
			const statusBox = document.getElementById('wpfm-migration-status');
			let previewAppendStopRequested = false;

			if (!postType || !source || !target) {
				return;
			}

			function setStatus(message, kind) {
				if (!statusBox) {
					return;
				}
				statusBox.style.display = '';
				statusBox.className = 'notice ' + (kind || 'notice-info');
				const p = statusBox.querySelector('p');
				if (p) {
					p.textContent = message;
				}
			}

			function hasOption(selectEl, value) {
				for (let i = 0; i < selectEl.options.length; i++) {
					if (selectEl.options[i].value === value) {
						return true;
					}
				}
				return false;
			}

			function toggleCustom(selectEl, wrapEl) {
				if (!wrapEl) {
					return;
				}
				wrapEl.style.display = selectEl.value === '__custom__' ? '' : 'none';
			}

			function populateSelect(selectEl, groupedFields, currentValue, customInput) {
				selectEl.innerHTML = '';

				const blank = document.createElement('option');
				blank.value = '';
				blank.textContent = '<?php echo esc_js(__('Select a field', 'wp-field-migrator')); ?>';
				selectEl.appendChild(blank);

				Object.keys(groupedFields).forEach(function(groupLabel) {
					const fields = groupedFields[groupLabel];
					if (!fields || !Object.keys(fields).length) {
						return;
					}

					const group = document.createElement('optgroup');
					group.label = groupLabel;

					Object.keys(fields).forEach(function(key) {
						const option = document.createElement('option');
						option.value = key;
						option.textContent = fields[key];
						group.appendChild(option);
					});

					selectEl.appendChild(group);
				});

				const custom = document.createElement('option');
				custom.value = '__custom__';
				custom.textContent = '<?php echo esc_js(__('Custom meta key...', 'wp-field-migrator')); ?>';
				selectEl.appendChild(custom);

				if (currentValue && hasOption(selectEl, currentValue)) {
					selectEl.value = currentValue;
					return;
				}

				if (currentValue) {
					selectEl.value = '__custom__';
					if (customInput) {
						customInput.value = currentValue;
					}
					return;
				}

				selectEl.value = '';
			}

			function refreshFieldOptions() {
				const currentSource = source.value === '__custom__' ? sourceCustom.value : source.value;
				const currentTarget = target.value === '__custom__' ? targetCustom.value : target.value;
				const params = new URLSearchParams();
				params.append('action', 'wpfm_fields_for_post_type');
				params.append('nonce', '<?php echo esc_js($fields_nonce); ?>');
				params.append('post_type', postType.value);
				params.append('show_private_keys', showPrivate && showPrivate.checked ? '1' : '0');

				fetch(ajaxurl, {
					method: 'POST',
					headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
					body: params.toString()
				}).then(function(r) { return r.json(); }).then(function(data) {
					if (!data || !data.success || !data.data || !data.data.fields) {
						return;
					}
					populateSelect(source, data.data.fields, currentSource, sourceCustom);
					populateSelect(target, data.data.fields, currentTarget, targetCustom);
					toggleCustom(source, sourceCustomWrap);
					toggleCustom(target, targetCustomWrap);
				});
			}

			function buildResolvedField(selectEl, customEl) {
				if (!selectEl) {
					return '';
				}
				if (selectEl.value === '__custom__') {
					return customEl ? customEl.value.trim() : '';
				}
				return selectEl.value;
			}

			function ensurePreviewTable() {
				let table = document.getElementById('wpfm-preview-table');
				let tbody = document.getElementById('wpfm-preview-tbody');
				if (table && tbody) {
					return tbody;
				}

				const container = document.querySelector('.wrap');
				if (!container) {
					return null;
				}

				const heading = document.createElement('h2');
				heading.textContent = '<?php echo esc_js(__('Preview Results', 'wp-field-migrator')); ?>';
				container.appendChild(heading);

				table = document.createElement('table');
				table.id = 'wpfm-preview-table';
				table.className = 'widefat striped';
				table.innerHTML =
					'<thead><tr>' +
					'<th><?php echo esc_js(__('Post', 'wp-field-migrator')); ?></th>' +
					'<th><?php echo esc_js(__('Source Value', 'wp-field-migrator')); ?></th>' +
					'<th><?php echo esc_js(__('Current Target', 'wp-field-migrator')); ?></th>' +
					'<th><?php echo esc_js(__('New Target', 'wp-field-migrator')); ?></th>' +
					'<th><?php echo esc_js(__('Will Change', 'wp-field-migrator')); ?></th>' +
					'</tr></thead>' +
					'<tbody id=\"wpfm-preview-tbody\"></tbody>';
				container.appendChild(table);

				return document.getElementById('wpfm-preview-tbody');
			}

			function appendPreviewRows(rows) {
				const tbody = ensurePreviewTable();
				if (!tbody || !Array.isArray(rows)) {
					return;
				}

				rows.forEach(function(row) {
					const tr = document.createElement('tr');

					const postTd = document.createElement('td');
					const link = document.createElement('a');
					link.href = row.edit_post_url || '#';
					link.target = '_blank';
					link.rel = 'noopener noreferrer';
					link.textContent = row.post_title || ('#' + String(row.post_id || ''));
					postTd.appendChild(link);
					postTd.appendChild(document.createTextNode(' (' + String(row.post_id || '') + ')'));
					tr.appendChild(postTd);

					const sourceTd = document.createElement('td');
					sourceTd.textContent = row.source_display || '';
					tr.appendChild(sourceTd);

					const targetTd = document.createElement('td');
					targetTd.textContent = row.target_display || '';
					tr.appendChild(targetTd);

					const newTd = document.createElement('td');
					newTd.textContent = row.new_display || '';
					tr.appendChild(newTd);

					const changeTd = document.createElement('td');
					if (row.will_change) {
						const strong = document.createElement('strong');
						strong.textContent = '<?php echo esc_js(__('Yes', 'wp-field-migrator')); ?>';
						changeTd.appendChild(strong);
					} else {
						changeTd.textContent = '<?php echo esc_js(__('No', 'wp-field-migrator')); ?>';
					}
					tr.appendChild(changeTd);

					tbody.appendChild(tr);
				});
			}

			async function runPreviewAllAsync() {
				if (!previewAllAsync) {
					return;
				}

				const sourceField = buildResolvedField(source, sourceCustom);
				const targetField = buildResolvedField(target, targetCustom);
				if (!sourceField || !targetField) {
					setStatus('<?php echo esc_js(__('Source and target fields are required.', 'wp-field-migrator')); ?>', 'notice-error');
					return;
				}
				if (sourceField === targetField) {
					setStatus('<?php echo esc_js(__('Source and target fields must be different.', 'wp-field-migrator')); ?>', 'notice-error');
					return;
				}

				previewAppendStopRequested = false;
				previewAllAsync.disabled = true;
				if (previewAllStop) {
					previewAllStop.style.display = '';
					previewAllStop.disabled = false;
				}

				let cursor = 0;
				let loops = 0;
				let processed = 0;
				let appended = 0;

				setStatus('<?php echo esc_js(__('Starting preview append across all posts...', 'wp-field-migrator')); ?>', 'notice-info');

				while (true) {
					if (previewAppendStopRequested) {
						setStatus('<?php echo esc_js(__('Preview appending stopped.', 'wp-field-migrator')); ?>', 'notice-warning');
						break;
					}

					loops += 1;
					const params = new URLSearchParams();
					params.append('action', 'wpfm_preview_batch');
					params.append('nonce', '<?php echo esc_js($migrate_nonce); ?>');
					params.append('post_type', postType.value);
					params.append('source_field', source.value === '__custom__' ? '__custom__' : sourceField);
					params.append('target_field', target.value === '__custom__' ? '__custom__' : targetField);
					params.append('source_field_custom', source.value === '__custom__' ? sourceField : '');
					params.append('target_field_custom', target.value === '__custom__' ? targetField : '');
					params.append('migration_batch_size', document.getElementById('migration_batch_size').value || '200');
					params.append('cursor', String(cursor));

					let data;
					try {
						const response = await fetch(ajaxurl, {
							method: 'POST',
							headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
							body: params.toString()
						});
						data = await response.json();
					} catch (err) {
						setStatus('<?php echo esc_js(__('Preview request failed.', 'wp-field-migrator')); ?>', 'notice-error');
						break;
					}

					if (!data || !data.success || !data.data) {
						setStatus((data && data.data && data.data.message) ? data.data.message : '<?php echo esc_js(__('Preview append failed.', 'wp-field-migrator')); ?>', 'notice-error');
						break;
					}

					appendPreviewRows(data.data.rows || []);
					processed += Number(data.data.processed || 0);
					appended += Number(data.data.eligible_posts || 0);
					cursor = Number(data.data.next_cursor || cursor);

					const total = Number(data.data.total_posts || 0);
					setStatus(
						'<?php echo esc_js(__('Appending preview rows...', 'wp-field-migrator')); ?> ' +
						processed + '/' + total + ' | ' +
						'<?php echo esc_js(__('rows appended', 'wp-field-migrator')); ?>: ' + appended,
						'notice-info'
					);

					if (!data.data.has_more) {
						setStatus(
							'<?php echo esc_js(__('Preview append complete.', 'wp-field-migrator')); ?> ' +
							'<?php echo esc_js(__('Processed', 'wp-field-migrator')); ?>: ' + processed + ', ' +
							'<?php echo esc_js(__('Rows appended', 'wp-field-migrator')); ?>: ' + appended,
							'notice-success'
						);
						break;
					}

					if (loops > 20000) {
						setStatus('<?php echo esc_js(__('Preview append stopped after too many batches.', 'wp-field-migrator')); ?>', 'notice-warning');
						break;
					}
				}

				previewAllAsync.disabled = false;
				if (previewAllStop) {
					previewAllStop.style.display = 'none';
				}
			}

			async function runMigrationAsync() {
				if (!runAsync) {
					return;
				}

				if (!window.confirm('<?php echo esc_js(__('This migration can irreversibly modify post data across all matching posts. Make sure you have a database backup. Continue?', 'wp-field-migrator')); ?>')) {
					return;
				}

				const sourceField = buildResolvedField(source, sourceCustom);
				const targetField = buildResolvedField(target, targetCustom);
				if (!sourceField || !targetField) {
					setStatus('<?php echo esc_js(__('Source and target fields are required.', 'wp-field-migrator')); ?>', 'notice-error');
					return;
				}
				if (sourceField === targetField) {
					setStatus('<?php echo esc_js(__('Source and target fields must be different.', 'wp-field-migrator')); ?>', 'notice-error');
					return;
				}

				runAsync.disabled = true;
				let runId = '';
				let cursor = 0;
				let loops = 0;
				let processed = 0;
				let updated = 0;
				let skippedEmpty = 0;
				let skippedUnchanged = 0;
				let failed = 0;

				setStatus('<?php echo esc_js(__('Migration started...', 'wp-field-migrator')); ?>', 'notice-info');

				try {
					const startParams = new URLSearchParams();
					startParams.append('action', 'wpfm_start_run');
					startParams.append('nonce', '<?php echo esc_js($migrate_nonce); ?>');
					startParams.append('post_type', postType.value);
					startParams.append('source_field', source.value === '__custom__' ? '__custom__' : sourceField);
					startParams.append('target_field', target.value === '__custom__' ? '__custom__' : targetField);
					startParams.append('source_field_custom', source.value === '__custom__' ? sourceField : '');
					startParams.append('target_field_custom', target.value === '__custom__' ? targetField : '');
					startParams.append('migration_batch_size', document.getElementById('migration_batch_size').value || '200');
					const startResponse = await fetch(ajaxurl, {
						method: 'POST',
						headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
						body: startParams.toString()
					});
					const startData = await startResponse.json();
					if (!startData || !startData.success || !startData.data || !startData.data.run_id) {
						setStatus('<?php echo esc_js(__('Could not initialize migration run log.', 'wp-field-migrator')); ?>', 'notice-error');
						runAsync.disabled = false;
						return;
					}
					runId = String(startData.data.run_id);
				} catch (err) {
					setStatus('<?php echo esc_js(__('Could not initialize migration run log.', 'wp-field-migrator')); ?>', 'notice-error');
					runAsync.disabled = false;
					return;
				}

				while (true) {
					loops += 1;
					const params = new URLSearchParams();
					params.append('action', 'wpfm_run_migration_batch');
					params.append('nonce', '<?php echo esc_js($migrate_nonce); ?>');
					params.append('post_type', postType.value);
					params.append('source_field', source.value === '__custom__' ? '__custom__' : sourceField);
					params.append('target_field', target.value === '__custom__' ? '__custom__' : targetField);
					params.append('source_field_custom', source.value === '__custom__' ? sourceField : '');
					params.append('target_field_custom', target.value === '__custom__' ? targetField : '');
					params.append('migration_batch_size', document.getElementById('migration_batch_size').value || '200');
					params.append('run_id', runId);
					params.append('cursor', String(cursor));

					let data;
					try {
						const response = await fetch(ajaxurl, {
							method: 'POST',
							headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
							body: params.toString()
						});
						data = await response.json();
					} catch (err) {
						setStatus('<?php echo esc_js(__('Migration request failed.', 'wp-field-migrator')); ?>', 'notice-error');
						if (runId) {
							await finalizeRun(runId, 'failed', '<?php echo esc_js(__('Migration request failed.', 'wp-field-migrator')); ?>');
						}
						runAsync.disabled = false;
						return;
					}

					if (!data || !data.success || !data.data) {
						setStatus((data && data.data && data.data.message) ? data.data.message : '<?php echo esc_js(__('Migration failed.', 'wp-field-migrator')); ?>', 'notice-error');
						if (runId) {
							await finalizeRun(runId, 'failed', (data && data.data && data.data.message) ? String(data.data.message) : '<?php echo esc_js(__('Migration failed.', 'wp-field-migrator')); ?>');
						}
						runAsync.disabled = false;
						return;
					}

					processed += Number(data.data.processed || 0);
					updated += Number(data.data.updated || 0);
					skippedEmpty += Number(data.data.skipped_empty_source || 0);
					skippedUnchanged += Number(data.data.skipped_unchanged || 0);
					failed += Array.isArray(data.data.failed_ids) ? data.data.failed_ids.length : 0;
					cursor = Number(data.data.next_cursor || cursor);

					const total = Number(data.data.total_posts || 0);
					setStatus(
						'<?php echo esc_js(__('Running migration...', 'wp-field-migrator')); ?> ' +
						processed + '/' + total +
						' | <?php echo esc_js(__('updated', 'wp-field-migrator')); ?>: ' + updated +
						', <?php echo esc_js(__('unchanged', 'wp-field-migrator')); ?>: ' + skippedUnchanged +
						', <?php echo esc_js(__('empty source', 'wp-field-migrator')); ?>: ' + skippedEmpty,
						'notice-info'
					);

					if (!data.data.has_more) {
						setStatus(
							'<?php echo esc_js(__('Migration complete.', 'wp-field-migrator')); ?> ' +
							'<?php echo esc_js(__('Processed', 'wp-field-migrator')); ?>: ' + processed + ', ' +
							'<?php echo esc_js(__('Updated', 'wp-field-migrator')); ?>: ' + updated + ', ' +
							'<?php echo esc_js(__('Unchanged', 'wp-field-migrator')); ?>: ' + skippedUnchanged + ', ' +
							'<?php echo esc_js(__('Empty source', 'wp-field-migrator')); ?>: ' + skippedEmpty + ', ' +
							'<?php echo esc_js(__('Failed', 'wp-field-migrator')); ?>: ' + failed,
							failed > 0 ? 'notice-warning' : 'notice-success'
						);
						runAsync.disabled = false;
						window.setTimeout(function() { window.location.reload(); }, 1000);
						return;
					}

					if (loops > 20000) {
						setStatus('<?php echo esc_js(__('Migration stopped after too many batches.', 'wp-field-migrator')); ?>', 'notice-warning');
						if (runId) {
							await finalizeRun(runId, 'stopped', '<?php echo esc_js(__('Stopped after too many batches.', 'wp-field-migrator')); ?>');
						}
						runAsync.disabled = false;
						return;
					}
				}
			}

			async function finalizeRun(runId, status, message) {
				if (!runId) {
					return;
				}

				try {
					const params = new URLSearchParams();
					params.append('action', 'wpfm_finalize_run');
					params.append('nonce', '<?php echo esc_js($migrate_nonce); ?>');
					params.append('run_id', runId);
					params.append('status', status || 'failed');
					params.append('message', message || '');
					await fetch(ajaxurl, {
						method: 'POST',
						headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
						body: params.toString()
					});
				} catch (err) {
					// Intentionally ignored: finalize failures should not block UI flow.
				}
			}

			source.addEventListener('change', function() { toggleCustom(source, sourceCustomWrap); });
			target.addEventListener('change', function() { toggleCustom(target, targetCustomWrap); });
			postType.addEventListener('change', refreshFieldOptions);
			if (showPrivate) {
				showPrivate.addEventListener('change', refreshFieldOptions);
			}
			if (runAsync) {
				runAsync.addEventListener('click', runMigrationAsync);
			}
			if (previewAllAsync) {
				previewAllAsync.addEventListener('click', runPreviewAllAsync);
			}
			if (previewAllStop) {
				previewAllStop.addEventListener('click', function() {
					previewAppendStopRequested = true;
					previewAllStop.disabled = true;
				});
			}
		})();
		</script>
		<?php
	}

	private function store_flash_and_redirect(array $config, array $errors, $preview_result) {
		$flash_key = wp_generate_password(12, false, false);
		set_transient(
			'wpfm_result_' . get_current_user_id() . '_' . $flash_key,
			[
				'config'  => $config,
				'errors'  => $errors,
				'preview' => $preview_result,
			],
			5 * MINUTE_IN_SECONDS
		);

		$redirect = add_query_arg(
			[
				'page'        => self::PAGE_SLUG,
				'wpfm_result' => $flash_key,
			],
			admin_url('tools.php')
		);

		wp_safe_redirect($redirect);
		exit;
	}

	private function format_preview_rows_for_json(array $rows) {
		$formatted = [];

		foreach ($rows as $row) {
			if (!is_array($row)) {
				continue;
			}

			$formatted[] = [
				'post_id'       => isset($row['post_id']) ? absint($row['post_id']) : 0,
				'post_title'    => isset($row['post_title']) ? (string) $row['post_title'] : '',
				'edit_post_url' => isset($row['edit_post_url']) ? (string) $row['edit_post_url'] : '',
				'source_display'=> $this->format_value(isset($row['source_value']) ? $row['source_value'] : null),
				'target_display'=> $this->format_value(isset($row['target_value']) ? $row['target_value'] : null),
				'new_display'   => $this->format_value(isset($row['new_value']) ? $row['new_value'] : null),
				'will_change'   => !empty($row['will_change']),
			];
		}

		return $formatted;
	}

	private function csv_value($value) {
		if (is_array($value) || is_object($value)) {
			return (string) wp_json_encode($value);
		}
		if ($value === null) {
			return '';
		}
		return (string) $value;
	}

	private function create_run(array $config) {
		$history = $this->get_run_history();
		$run = [
			'id'         => wp_generate_uuid4(),
			'status'     => 'running',
			'started_at' => gmdate('Y-m-d H:i:s'),
			'finished_at'=> '',
			'message'    => '',
			'user_id'    => get_current_user_id(),
			'config'     => [
				'post_type'            => $config['post_type'],
				'source_field'         => $config['source_field'],
				'target_field'         => $config['target_field'],
				'migration_batch_size' => $config['migration_batch_size'],
			],
			'summary'    => [
				'processed'            => 0,
				'updated'              => 0,
				'skipped_empty_source' => 0,
				'skipped_unchanged'    => 0,
				'failed_count'         => 0,
				'failed_ids'           => [],
			],
			'batches'    => [],
		];

		array_unshift($history, $run);
		$history = array_slice($history, 0, self::RUN_HISTORY_LIMIT);
		$this->save_run_history($history);

		return $run;
	}

	private function append_run_batch_log($run_id, $cursor, array $batch) {
		$history = $this->get_run_history();
		foreach ($history as &$run) {
			if (!is_array($run) || !isset($run['id']) || (string) $run['id'] !== (string) $run_id) {
				continue;
			}

			$summary = isset($run['summary']) && is_array($run['summary']) ? $run['summary'] : [];
			$summary['processed'] = (isset($summary['processed']) ? absint($summary['processed']) : 0) + (isset($batch['processed']) ? absint($batch['processed']) : 0);
			$summary['updated'] = (isset($summary['updated']) ? absint($summary['updated']) : 0) + (isset($batch['updated']) ? absint($batch['updated']) : 0);
			$summary['skipped_empty_source'] = (isset($summary['skipped_empty_source']) ? absint($summary['skipped_empty_source']) : 0) + (isset($batch['skipped_empty_source']) ? absint($batch['skipped_empty_source']) : 0);
			$summary['skipped_unchanged'] = (isset($summary['skipped_unchanged']) ? absint($summary['skipped_unchanged']) : 0) + (isset($batch['skipped_unchanged']) ? absint($summary['skipped_unchanged']) : 0);

			$failed_ids = isset($summary['failed_ids']) && is_array($summary['failed_ids']) ? $summary['failed_ids'] : [];
			$batch_failed = isset($batch['failed_ids']) && is_array($batch['failed_ids']) ? array_map('absint', $batch['failed_ids']) : [];
			if (!empty($batch_failed)) {
				$failed_ids = array_values(array_unique(array_merge($failed_ids, $batch_failed)));
			}
			$summary['failed_ids'] = $failed_ids;
			$summary['failed_count'] = count($failed_ids);
			$run['summary'] = $summary;

			$batches = isset($run['batches']) && is_array($run['batches']) ? $run['batches'] : [];
			$batches[] = [
				'index'                => count($batches) + 1,
				'cursor'               => absint($cursor),
				'next_cursor'          => isset($batch['next_cursor']) ? absint($batch['next_cursor']) : absint($cursor),
				'processed'            => isset($batch['processed']) ? absint($batch['processed']) : 0,
				'updated'              => isset($batch['updated']) ? absint($batch['updated']) : 0,
				'skipped_empty_source' => isset($batch['skipped_empty_source']) ? absint($batch['skipped_empty_source']) : 0,
				'skipped_unchanged'    => isset($batch['skipped_unchanged']) ? absint($batch['skipped_unchanged']) : 0,
				'failed_ids'           => $batch_failed,
			];
			if (count($batches) > self::RUN_BATCH_LOG_LIMIT) {
				$batches = array_slice($batches, -1 * self::RUN_BATCH_LOG_LIMIT);
			}
			$run['batches'] = $batches;
			$run['status'] = 'running';
			break;
		}
		unset($run);

		$this->save_run_history($history);
	}

	private function finalize_run($run_id, $status, $message) {
		$history = $this->get_run_history();
		foreach ($history as &$run) {
			if (!is_array($run) || !isset($run['id']) || (string) $run['id'] !== (string) $run_id) {
				continue;
			}
			$run['status'] = $status;
			$run['finished_at'] = gmdate('Y-m-d H:i:s');
			$run['message'] = (string) $message;
			break;
		}
		unset($run);

		$this->save_run_history($history);
	}

	private function get_run_by_id($run_id) {
		$history = $this->get_run_history();
		foreach ($history as $run) {
			if (!is_array($run)) {
				continue;
			}
			if (isset($run['id']) && (string) $run['id'] === (string) $run_id) {
				return $run;
			}
		}
		return null;
	}

	private function get_run_history() {
		$history = get_option(self::RUN_HISTORY_OPTION, []);
		return is_array($history) ? $history : [];
	}

	private function save_run_history(array $history) {
		update_option(self::RUN_HISTORY_OPTION, array_values($history), false);
	}

	private function format_run_time($utc_time) {
		if ($utc_time === '') {
			return '';
		}
		$ts = strtotime($utc_time . ' UTC');
		if ($ts === false) {
			return $utc_time;
		}
		return wp_date('Y-m-d H:i:s', $ts);
	}

	private function format_run_status($status) {
		switch ($status) {
			case 'completed':
				return __('Completed', 'wp-field-migrator');
			case 'failed':
				return __('Failed', 'wp-field-migrator');
			case 'stopped':
				return __('Stopped', 'wp-field-migrator');
			case 'running':
				return __('Running', 'wp-field-migrator');
			default:
				return (string) $status;
		}
	}

	private function render_field_select($name, array $available_fields, $selected_value) {
		?>
		<select name="<?php echo esc_attr($name); ?>" id="<?php echo esc_attr($name); ?>">
			<option value=""><?php esc_html_e('Select a field', 'wp-field-migrator'); ?></option>
			<?php foreach ($available_fields as $group_label => $group_fields) : ?>
				<?php if (empty($group_fields) || !is_array($group_fields)) : ?>
					<?php continue; ?>
				<?php endif; ?>
				<optgroup label="<?php echo esc_attr($group_label); ?>">
					<?php foreach ($group_fields as $value => $label) : ?>
						<option value="<?php echo esc_attr($value); ?>" <?php selected($selected_value, $value); ?>><?php echo esc_html($label); ?></option>
					<?php endforeach; ?>
				</optgroup>
			<?php endforeach; ?>
			<option value="__custom__" <?php selected($selected_value, '__custom__'); ?>><?php esc_html_e('Custom meta key...', 'wp-field-migrator'); ?></option>
		</select>
		<?php
	}

	private function get_available_fields($post_type, $include_private_keys = false) {
		$core_group_label    = __('Core Fields', 'wp-field-migrator');
		$acf_active_label    = __('ACF Fields (Active)', 'wp-field-migrator');
		$acf_inactive_label  = __('ACF Fields (Inactive)', 'wp-field-migrator');
		$detected_meta_label = __('Detected Meta Keys', 'wp-field-migrator');

		$groups = [
			$core_group_label => [
				'core:post_title'   => __('Core: Post Title', 'wp-field-migrator'),
				'core:post_content' => __('Core: Post Content', 'wp-field-migrator'),
				'core:post_excerpt' => __('Core: Post Excerpt', 'wp-field-migrator'),
			],
			$acf_active_label    => [],
			$acf_inactive_label  => [],
			$detected_meta_label => [],
		];

		$used_keys = array_fill_keys(array_keys($groups[$core_group_label]), true);

		if (function_exists('acf_get_field_groups') && function_exists('acf_get_fields')) {
			$active_groups = acf_get_field_groups(['post_type' => $post_type, 'active' => true]);
			if (is_array($active_groups)) {
				foreach ($active_groups as $group) {
					$group_fields = acf_get_fields($group);
					if (is_array($group_fields)) {
						$this->collect_acf_field_choices($group_fields, $groups[$acf_active_label], $used_keys, $include_private_keys);
					}
				}
			}

			$inactive_groups = acf_get_field_groups(['post_type' => $post_type, 'active' => false]);
			if (is_array($inactive_groups)) {
				foreach ($inactive_groups as $group) {
					$group_fields = acf_get_fields($group);
					if (is_array($group_fields)) {
						$this->collect_acf_field_choices($group_fields, $groups[$acf_inactive_label], $used_keys, $include_private_keys);
					}
				}
			}
		}

		$this->collect_detected_meta_choices($post_type, $groups[$detected_meta_label], $used_keys, $include_private_keys);

		return array_filter($groups, static function ($group_fields) {
			return is_array($group_fields) && !empty($group_fields);
		});
	}

	private function collect_detected_meta_choices($post_type, array &$choices, array &$used_keys, $include_private_keys = false) {
		global $wpdb;

		$sql = $wpdb->prepare(
			"
			SELECT pm.meta_key, COUNT(*) AS key_count
			FROM {$wpdb->postmeta} pm
			INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			WHERE p.post_type = %s
			GROUP BY pm.meta_key
			ORDER BY key_count DESC, pm.meta_key ASC
			LIMIT 300
			",
			$post_type
		);

		$rows = $wpdb->get_results($sql, ARRAY_A);
		if (!is_array($rows)) {
			return;
		}

		foreach ($rows as $row) {
			if (!is_array($row) || !isset($row['meta_key'])) {
				continue;
			}

			$key = (string) $row['meta_key'];
			if ($key === '' || isset($used_keys[$key])) {
				continue;
			}
			if (!$include_private_keys && strpos($key, '_') === 0) {
				continue;
			}

			$count = isset($row['key_count']) ? absint($row['key_count']) : 0;
			$choices[$key] = sprintf(__('Meta: %1$s (used %2$d times)', 'wp-field-migrator'), $key, $count);
			$used_keys[$key] = true;
		}
	}

	private function collect_acf_field_choices(array $acf_fields, array &$choices, array &$used_keys, $include_private_keys = false) {
		foreach ($acf_fields as $acf_field) {
			if (!is_array($acf_field)) {
				continue;
			}

			if (!empty($acf_field['name'])) {
				$name = (string) $acf_field['name'];
				if (!$include_private_keys && strpos($name, '_') === 0) {
					continue;
				}
				if (!isset($used_keys[$name])) {
					$label = !empty($acf_field['label']) ? (string) $acf_field['label'] : $name;
					$type  = !empty($acf_field['type']) ? (string) $acf_field['type'] : 'field';
					$choices[$name] = sprintf(__('ACF: %1$s (%2$s) [%3$s]', 'wp-field-migrator'), $label, $name, $type);
					$used_keys[$name] = true;
				}
			}

			if (!empty($acf_field['sub_fields']) && is_array($acf_field['sub_fields'])) {
				$this->collect_acf_field_choices($acf_field['sub_fields'], $choices, $used_keys, $include_private_keys);
			}
		}
	}

	private function format_value($value) {
		if (is_array($value) || is_object($value)) {
			$encoded = wp_json_encode($value);
			return $this->truncate((string) $encoded, 180);
		}

		if ($value === null) {
			return '';
		}

		return $this->truncate((string) $value, 180);
	}

	private function truncate($text, $length) {
		if (function_exists('mb_strlen') && function_exists('mb_substr')) {
			if (mb_strlen($text) <= $length) {
				return $text;
			}
			return mb_substr($text, 0, $length - 3) . '...';
		}

		if (strlen($text) <= $length) {
			return $text;
		}

		return substr($text, 0, $length - 3) . '...';
	}
}
