<?php
/**
 * Plugin Name: Field Migrator
 * Plugin URI:  https://example.com
 * Description: Preview and migrate field data between fields (including ACF fields) on WordPress posts.
 * Version:     0.1.0
 * Author:      Walters
 * License:     GPL-2.0-or-later
 * Text Domain: wp-field-migrator
 */

if (!defined('ABSPATH')) {
	exit;
}

define('WPFM_VERSION', '0.1.0');
define('WPFM_PLUGIN_FILE', __FILE__);
define('WPFM_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WPFM_PLUGIN_URL', plugin_dir_url(__FILE__));

if (!defined('WPFM_UPDATE_SOURCE_URL')) {
	define('WPFM_UPDATE_SOURCE_URL', 'https://github.com/dylanfisher/wp-field-migrator');
}

if (!defined('WPFM_UPDATE_BRANCH')) {
	define('WPFM_UPDATE_BRANCH', 'main');
}

require_once WPFM_PLUGIN_DIR . 'includes/class-plugin.php';
require_once WPFM_PLUGIN_DIR . 'includes/plugin-update-checker/plugin-update-checker.php';

add_action('plugins_loaded', static function () {
	$config = apply_filters(
		'wpfm_update_checker_config',
		[
			'url'    => WPFM_UPDATE_SOURCE_URL,
			'branch' => WPFM_UPDATE_BRANCH,
		]
	);

	$url = isset($config['url']) ? trim((string) $config['url']) : '';
	if ($url === '') {
		return;
	}

	$checker = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		$url,
		WPFM_PLUGIN_FILE,
		'wp-field-migrator'
	);

	$branch = isset($config['branch']) ? trim((string) $config['branch']) : '';
	if ($branch !== '') {
		$checker->setBranch($branch);
	}
});

register_activation_hook(WPFM_PLUGIN_FILE, ['\\WPFM\\Plugin', 'activate']);
register_deactivation_hook(WPFM_PLUGIN_FILE, ['\\WPFM\\Plugin', 'deactivate']);

\WPFM\Plugin::init();
