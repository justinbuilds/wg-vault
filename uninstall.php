<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * Removes all plugin options and scheduled events from the database.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Remove all plugin settings stored under the single options key.
delete_option( 'wgv_settings' );

// Clear any scheduled backup events.
wp_clear_scheduled_hook( 'wgv_scheduled_backup' );
