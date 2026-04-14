<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * Removes all plugin options, the backup-log database table, scheduled events,
 * and the local logs/temp directories. Files stored on Google Drive are never
 * touched by this routine.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// Remove all plugin settings.
delete_option( 'wgv_settings' );

// Drop the backup-log table.
$table_name = $wpdb->prefix . 'wgv_backup_log';
$wpdb->query( "DROP TABLE IF EXISTS `{$table_name}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

// Drop the restore-log table.
$table_name = $wpdb->prefix . 'wgv_restore_log';
$wpdb->query( "DROP TABLE IF EXISTS `{$table_name}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

// Clear any scheduled backup events.
wp_clear_scheduled_hook( 'wgv_scheduled_backup' );

// Recursively delete wgv-logs/ and wgv-temp/.
wgv_uninstall_delete_directory( WP_CONTENT_DIR . '/wgv-logs' );
wgv_uninstall_delete_directory( WP_CONTENT_DIR . '/wgv-temp' );

/**
 * Recursively delete a directory and all of its contents.
 *
 * Uses a plain function (no class) because uninstall.php runs in a context
 * where the plugin classes are not guaranteed to be loaded.
 *
 * @param string $dir Absolute path to the directory to remove.
 */
function wgv_uninstall_delete_directory( string $dir ): void {
	if ( ! is_dir( $dir ) ) {
		return;
	}

	$items = scandir( $dir );
	foreach ( $items as $item ) {
		if ( '.' === $item || '..' === $item ) {
			continue;
		}

		$path = $dir . DIRECTORY_SEPARATOR . $item;

		if ( is_dir( $path ) ) {
			wgv_uninstall_delete_directory( $path );
		} else {
			@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
	}

	@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
}
