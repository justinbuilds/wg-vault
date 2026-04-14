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
		require_once $includes . 'class-wgv-notifier.php';
		require_once $includes . 'class-wgv-drive.php';
		require_once $includes . 'class-wgv-retention.php';
		require_once $includes . 'class-wgv-backup.php';
		require_once $includes . 'class-wgv-scheduler.php';
	}

	/**
	 * Boot each plugin component and wire all dependencies.
	 */
	private static function boot_components(): void {
		$settings  = new WGV_Settings();
		$settings->register();

		$notifier  = new WGV_Notifier( $settings );
		$drive     = new WGV_Drive( $settings, $notifier );
		$retention = new WGV_Retention( $settings, $drive );
		$backup    = new WGV_Backup( $settings, $drive, $notifier, $retention );
		$scheduler = new WGV_Scheduler( $settings, $backup, $notifier );

		$scheduler->register();

		// When frequency changes via settings save, reschedule the cron event.
		add_action(
			'wgv_frequency_changed',
			static function ( string $frequency ) use ( $scheduler ): void {
				$scheduler->schedule( $frequency );
			}
		);

		// Manual backup handler — triggered via AJAX to avoid HTTP timeouts.
		add_action(
			'wp_ajax_wgv_run_backup',
			static function () use ( $backup ): void {
				check_ajax_referer( 'wgv_ajax_backup', 'nonce' );

				if ( ! current_user_can( 'manage_options' ) ) {
					wp_send_json_error( [ 'message' => 'Unauthorized' ] );
				}

				set_time_limit( 300 );

				$allowed = [ 'database', 'uploads', 'full' ];
				$type    = sanitize_text_field( $_POST['backup_type'] ?? 'database' );

				if ( ! in_array( $type, $allowed, true ) ) {
					$type = 'database';
				}

				try {
					match ( $type ) {
						'uploads' => $backup->run_uploads_backup(),
						'full'    => $backup->run_full_backup(),
						default   => $backup->run_database_backup(),
					};

					wp_send_json_success( [ 'message' => 'Backup completed successfully' ] );
				} catch ( \Throwable $e ) {
					wp_send_json_error( [ 'message' => 'Backup failed: ' . $e->getMessage() ] );
				}
			}
		);

		// Google Drive OAuth callback.
		add_action( 'admin_post_wgv_oauth_callback', [ $drive, 'handle_oauth_callback' ] );

		// List Drive folders for the folder picker modal.
		add_action(
			'wp_ajax_wgv_list_folders',
			static function () use ( $drive ): void {
				check_ajax_referer( 'wgv_ajax_backup', 'nonce' );
				if ( ! current_user_can( 'manage_options' ) ) {
					wp_send_json_error();
				}
				$parent_id = sanitize_text_field( $_POST['parent_id'] ?? 'root' );
				wp_send_json_success( $drive->list_folders( $parent_id ) );
			}
		);

		// List backup files inside a Drive folder (Restore tab).
		add_action(
			'wp_ajax_wgv_list_backups',
			static function () use ( $drive ): void {
				check_ajax_referer( 'wgv_ajax_backup', 'nonce' );
				if ( ! current_user_can( 'manage_options' ) ) {
					wp_send_json_error();
				}
				$folder_id = sanitize_text_field( $_POST['folder_id'] ?? '' );
				if ( empty( $folder_id ) ) {
					wp_send_json_error( 'No folder ID' );
				}
				wp_send_json_success( $drive->list_backups_in_folder( $folder_id ) );
			}
		);

		// Create a new Drive folder from the folder picker.
		add_action(
			'wp_ajax_wgv_create_folder',
			static function () use ( $drive ): void {
				check_ajax_referer( 'wgv_ajax_backup', 'nonce' );
				if ( ! current_user_can( 'manage_options' ) ) {
					wp_send_json_error();
				}
				$name      = sanitize_text_field( $_POST['folder_name'] ?? '' );
				$parent_id = sanitize_text_field( $_POST['parent_id'] ?? 'root' );
				if ( empty( $name ) ) {
					wp_send_json_error( 'No folder name provided' );
				}
				$result = $drive->create_folder( $name, $parent_id );
				if ( empty( $result ) ) {
					wp_send_json_error( 'Failed to create folder' );
				}
				wp_send_json_success( $result );
			}
		);

		if ( is_admin() ) {
			require_once WGV_PLUGIN_DIR . 'admin/admin-page.php';
		}
	}
}
