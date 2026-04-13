<?php
/**
 * Bootstraps all plugin components.
 *
 * WGV_Loader is the central orchestrator. It requires every class file and
 * delegates initialisation to each component on the plugins_loaded hook.
 */

defined( 'ABSPATH' ) || exit;

class WGV_Loader {

	/**
	 * Initialise the plugin — called on the plugins_loaded hook.
	 */
	public static function init(): void {
		self::load_dependencies();
		self::boot_components();
	}

	/**
	 * Require all class files that make up the plugin.
	 */
	private static function load_dependencies(): void {
		$includes = WGV_PLUGIN_DIR . 'includes/';

		require_once $includes . 'class-wgv-settings.php';
		require_once $includes . 'class-wgv-scheduler.php';
		require_once $includes . 'class-wgv-backup.php';
		require_once $includes . 'class-wgv-drive.php';
		require_once $includes . 'class-wgv-retention.php';
		require_once $includes . 'class-wgv-notifier.php';
	}

	/**
	 * Boot each plugin component.
	 */
	private static function boot_components(): void {
		$settings = new WGV_Settings();
		$settings->register();

		$drive = new WGV_Drive( $settings );
		add_action( 'admin_post_wgb_oauth_callback', [ $drive, 'handle_oauth_callback' ] );

		( new WGV_Scheduler() )->register();

		if ( is_admin() ) {
			require_once WGV_PLUGIN_DIR . 'admin/admin-page.php';
		}
	}
}
