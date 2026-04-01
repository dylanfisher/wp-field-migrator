<?php

namespace WPFM;

if (!defined('ABSPATH')) {
	exit;
}

require_once WPFM_PLUGIN_DIR . 'includes/class-field-access.php';
require_once WPFM_PLUGIN_DIR . 'includes/class-migration-service.php';
require_once WPFM_PLUGIN_DIR . 'includes/class-admin-page.php';

class Plugin {
	public static function init() {
		add_action('plugins_loaded', [__CLASS__, 'boot']);
	}

	public static function activate() {
		update_option('wpfm_version', WPFM_VERSION);
	}

	public static function deactivate() {
		// Reserved for future cleanup tasks at deactivation time.
	}

	public static function boot() {
		if (!is_admin()) {
			return;
		}

		$field_access = new Field_Access();
		$service      = new Migration_Service($field_access);
		$admin_page   = new Admin_Page($service);

		$admin_page->register();
	}
}
