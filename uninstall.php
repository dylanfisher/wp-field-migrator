<?php

if (!defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

// Reserved for plugin-owned cleanup when persistent plugin options are added.
delete_option('wpfm_version');
