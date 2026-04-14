<?php
/**
 * Handles everything that should run when the plugin is deactivated.
 *
 * WGV_Deactivator clears all scheduled backup events from wp-cron so
 * no background tasks fire after the plugin is disabled. Settings and
 * backup data are intentionally preserved; uninstall.php handles removal.
 */

defined( 'ABSPATH' ) || exit;

class WGV_Deactivator {

	/**
	 * Run on plugin deactivation.
	 *
	 * Removes all WG Vault cron events without touching stored settings
	 * or backup archives (those are cleaned up on full uninstall).
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'wgv_scheduled_backup' );
	}
}
