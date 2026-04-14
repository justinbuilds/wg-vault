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
		require_once $includes . 'class-wgv-restore.php';
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
		$restore   = new WGV_Restore( $settings, $drive, $backup, $notifier );

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

		// Restore a backup from Google Drive (pre-backup runs first).
		add_action(
			'wp_ajax_wgv_restore_backup',
			static function () use ( $restore, $backup ): void {
				check_ajax_referer( 'wgv_ajax_backup', 'nonce' );

				if ( ! current_user_can( 'manage_options' ) ) {
					wp_send_json_error( [ 'message' => 'Unauthorized' ] );
				}

				set_time_limit( 600 );

				$source  = sanitize_text_field( $_POST['source'] ?? 'drive' );
				$type    = sanitize_text_field( $_POST['type'] ?? 'database' );
				$file_id = sanitize_text_field( $_POST['file_id'] ?? '' );

				// Run a pre-restore backup so the current state is preserved.
				$pre_backup_result = match ( $type ) {
					'database' => $backup->run_database_backup(),
					'uploads'  => $backup->run_uploads_backup(),
					'full'     => $backup->run_full_backup(),
					default    => false
				};

				if ( ! $pre_backup_result ) {
					wp_send_json_error( [ 'message' => 'Pre-restore backup failed. Restore aborted.' ] );
				}

				$result = match ( [ $source, $type ] ) {
					[ 'drive', 'database' ] => $restore->restore_database( $file_id ),
					[ 'drive', 'uploads' ]  => $restore->restore_uploads( $file_id ),
					[ 'drive', 'full' ]     => $restore->restore_full( $file_id ),
					default                 => false
				};

				if ( $result ) {
					wp_send_json_success( [ 'message' => 'Restore completed successfully.' ] );
				} else {
					wp_send_json_error( [ 'message' => 'Restore failed. Check the restore log.' ] );
				}
			}
		);

		// Restore a backup from a locally-uploaded file.
		add_action(
			'wp_ajax_wgv_restore_upload',
			static function () use ( $restore ): void {
				check_ajax_referer( 'wgv_ajax_backup', 'nonce' );

				if ( ! current_user_can( 'manage_options' ) ) {
					wp_send_json_error( 'Unauthorized' );
				}

				set_time_limit( 600 );

				if ( empty( $_FILES['backup_file'] ) ) {
					wp_send_json_error( 'No file uploaded' );
				}

				$type      = sanitize_text_field( $_POST['type'] ?? 'database' );
				$orig_name = sanitize_file_name( $_FILES['backup_file']['name'] ?? '' );

				// Validate original filename extension before passing to restore.
				$is_sql_gz = substr( strtolower( $orig_name ), -7 ) === '.sql.gz';
				$is_zip    = substr( strtolower( $orig_name ), -4 ) === '.zip';

				if ( 'database' === $type && ! $is_sql_gz ) {
					wp_send_json_error( [ 'message' => 'Database restores require a .sql.gz file.' ] );
				}
				if ( 'database' !== $type && ! $is_zip ) {
					wp_send_json_error( [ 'message' => ucfirst( $type ) . ' restores require a .zip file.' ] );
				}

				$tmp    = $_FILES['backup_file']['tmp_name'];
				$result = $restore->restore_from_upload( $tmp, $type );

				if ( $result ) {
					wp_send_json_success( [ 'message' => 'Upload restore completed successfully.' ] );
				} else {
					wp_send_json_error( [ 'message' => 'Upload restore failed.' ] );
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
