<?php
/**
 * Handles everything that should run when the plugin is activated.
 *
 * WGV_Activator seeds the default settings in wp_options and ensures
 * the logs directory exists with the correct permissions.
 */

defined( 'ABSPATH' ) || exit;

class WGV_Activator {

	/**
	 * Run on plugin activation.
	 *
	 * Seeds default wgv_settings if none exist yet and creates
	 * the local logs directory for temporary backup archives.
	 */
	public static function activate(): void {
		self::seed_default_settings();
		self::create_logs_directory();
	}

	/**
	 * Insert default settings into wp_options if the key does not exist.
	 */
	private static function seed_default_settings(): void {
		if ( get_option( 'wgv_settings' ) === false ) {
			$defaults = [
				'backup_schedule'     => 'daily',
				'backup_type'         => 'full',
				'retention_count'     => 7,
				'notify_on_success'   => false,
				'notify_on_failure'   => true,
				'notify_email'        => get_option( 'admin_email' ),
				'drive_client_id'     => '',
				'drive_client_secret' => '',
				'drive_access_token'  => '',
			];

			add_option( 'wgv_settings', $defaults, '', 'no' );
		}
	}

	/**
	 * Ensure the logs/ directory exists and is writable.
	 */
	private static function create_logs_directory(): void {
		$logs_dir = WGV_PLUGIN_DIR . 'logs';

		if ( ! is_dir( $logs_dir ) ) {
			wp_mkdir_p( $logs_dir );
		}
	}
}
