<?php

namespace WPFM;

if (!defined('ABSPATH')) {
	exit;
}

class Migration_Service {
	private $field_access;

	public function __construct(Field_Access $field_access) {
		$this->field_access = $field_access;
	}

	public function preview(array $config) {
		$page     = isset($config['preview_page']) ? max(1, absint($config['preview_page'])) : 1;
		$per_page = isset($config['preview_per_page']) ? max(1, min(200, absint($config['preview_per_page']))) : 25;
		$query    = $this->query_preview_page($config['post_type'], $page, $per_page);
		$post_ids = is_array($query->posts) ? $query->posts : [];
		$rows     = [];

		foreach ($post_ids as $post_id) {
			$source_value = $this->field_access->get_value($post_id, $config['source_field']);
			if ($this->field_access->is_empty($source_value)) {
				continue;
			}

			$target_value = $this->field_access->get_value($post_id, $config['target_field']);
			$will_change  = !$this->field_access->values_equal($source_value, $target_value);

			$rows[] = [
				'post_id'       => $post_id,
				'post_title'    => get_the_title($post_id),
				'source_value'  => $source_value,
				'target_value'  => $target_value,
				'new_value'     => $source_value,
				'will_change'   => $will_change,
				'edit_post_url' => get_edit_post_link($post_id, 'raw'),
			];
		}

		return [
			'rows'            => $rows,
			'queried_posts'   => count($post_ids),
			'eligible_posts'  => count($rows),
			'posts_to_update' => count(array_filter($rows, static function ($row) {
				return !empty($row['will_change']);
			})),
			'page'            => $page,
			'per_page'        => $per_page,
			'total_posts'     => (int) $query->found_posts,
			'total_pages'     => (int) $query->max_num_pages,
			'has_previous'    => $page > 1,
			'has_next'        => $page < (int) $query->max_num_pages,
		];
	}

	public function migrate(array $config) {
		$cursor             = 0;
		$total_processed    = 0;
		$total_updated      = 0;
		$total_skipped      = 0;
		$total_skipped_same = 0;
		$total_failed       = [];
		$batch_size         = isset($config['migration_batch_size']) ? max(1, min(1000, absint($config['migration_batch_size']))) : 200;

		do {
			$batch = $this->migrate_batch($config, $cursor, $batch_size);
			$cursor = (int) $batch['next_cursor'];

			$total_processed += (int) $batch['processed'];
			$total_updated += (int) $batch['updated'];
			$total_skipped += (int) $batch['skipped_empty_source'];
			$total_skipped_same += (int) $batch['skipped_unchanged'];
			$total_failed = array_merge($total_failed, $batch['failed_ids']);
		} while (!empty($batch['has_more']));

		return [
			'processed'             => $total_processed,
			'updated'               => $total_updated,
			'skipped_empty_source'  => $total_skipped,
			'skipped_unchanged'     => $total_skipped_same,
			'failed_ids'            => $total_failed,
			'total_posts'           => $this->count_total_posts($config['post_type']),
		];
	}

	public function preview_batch(array $config, $cursor, $batch_size) {
		$cursor     = max(0, absint($cursor));
		$batch_size = max(1, min(1000, absint($batch_size)));
		$post_ids   = $this->query_post_ids_after_cursor($config['post_type'], $cursor, $batch_size);

		$rows        = [];
		$processed   = 0;
		$next_cursor = $cursor;

		foreach ($post_ids as $post_id) {
			$processed++;
			$next_cursor = $post_id;

			$source_value = $this->field_access->get_value($post_id, $config['source_field']);
			if ($this->field_access->is_empty($source_value)) {
				continue;
			}

			$target_value = $this->field_access->get_value($post_id, $config['target_field']);
			$will_change  = !$this->field_access->values_equal($source_value, $target_value);

			$rows[] = [
				'post_id'       => $post_id,
				'post_title'    => get_the_title($post_id),
				'source_value'  => $source_value,
				'target_value'  => $target_value,
				'new_value'     => $source_value,
				'will_change'   => $will_change,
				'edit_post_url' => get_edit_post_link($post_id, 'raw'),
			];
		}

		return [
			'cursor'          => $cursor,
			'next_cursor'     => $next_cursor,
			'batch_size'      => $batch_size,
			'processed'       => $processed,
			'rows'            => $rows,
			'eligible_posts'  => count($rows),
			'posts_to_update' => count(array_filter($rows, static function ($row) {
				return !empty($row['will_change']);
			})),
			'has_more'        => count($post_ids) === $batch_size,
			'total_posts'     => $this->count_total_posts($config['post_type']),
		];
	}

	public function migrate_batch(array $config, $cursor, $batch_size) {
		$cursor     = max(0, absint($cursor));
		$batch_size = max(1, min(1000, absint($batch_size)));
		$post_ids   = $this->query_post_ids_after_cursor($config['post_type'], $cursor, $batch_size);

		$updated              = 0;
		$processed            = 0;
		$skipped_empty_source = 0;
		$skipped_unchanged    = 0;
		$failed_ids           = [];
		$next_cursor          = $cursor;

		foreach ($post_ids as $post_id) {
			$processed++;
			$next_cursor = $post_id;

			$source_value = $this->field_access->get_value($post_id, $config['source_field']);
			if ($this->field_access->is_empty($source_value)) {
				$skipped_empty_source++;
				continue;
			}

			$target_value = $this->field_access->get_value($post_id, $config['target_field']);
			if ($this->field_access->values_equal($source_value, $target_value)) {
				$skipped_unchanged++;
				continue;
			}

			$result = $this->field_access->update_value($post_id, $config['target_field'], $source_value);
			if ($result) {
				$updated++;
				continue;
			}

			$failed_ids[] = $post_id;
		}

		return [
			'cursor'               => $cursor,
			'next_cursor'          => $next_cursor,
			'batch_size'           => $batch_size,
			'processed'            => $processed,
			'updated'              => $updated,
			'skipped_empty_source' => $skipped_empty_source,
			'skipped_unchanged'    => $skipped_unchanged,
			'failed_ids'           => $failed_ids,
			'has_more'             => count($post_ids) === $batch_size,
			'total_posts'          => $this->count_total_posts($config['post_type']),
		];
	}

	private function query_preview_page($post_type, $page, $per_page) {
		$query = new \WP_Query([
			'post_type'              => $post_type,
			'post_status'            => 'any',
			'posts_per_page'         => $per_page,
			'paged'                  => $page,
			'fields'                 => 'ids',
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'no_found_rows'          => false,
			'ignore_sticky_posts'    => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		]);

		return $query;
	}

	private function query_post_ids_after_cursor($post_type, $cursor, $limit) {
		global $wpdb;

		$sql = $wpdb->prepare(
			"
			SELECT ID
			FROM {$wpdb->posts}
			WHERE post_type = %s
				AND ID > %d
				AND post_status NOT IN ('trash', 'auto-draft')
			ORDER BY ID ASC
			LIMIT %d
			",
			$post_type,
			$cursor,
			$limit
		);

		$rows = $wpdb->get_col($sql);
		if (!is_array($rows)) {
			return [];
		}

		return array_map('absint', $rows);
	}

	private function count_total_posts($post_type) {
		global $wpdb;

		$sql = $wpdb->prepare(
			"
			SELECT COUNT(1)
			FROM {$wpdb->posts}
			WHERE post_type = %s
				AND post_status NOT IN ('trash', 'auto-draft')
			",
			$post_type
		);

		$total = $wpdb->get_var($sql);
		return absint($total);
	}
}
