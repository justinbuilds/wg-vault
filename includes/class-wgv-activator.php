<?php
/**
 * Handles everything that should run when the plugin is activated.
 *
 * WGV_Activator creates the backup-log database table, seeds the default
 * settings in wp_options, and ensures the logs/temp directories exist with
 * the correct access controls.
 */

defined( 'ABSPATH' ) || exit;

class WGV_Activator {

	/**
	 * Run on plugin activation.
	 */
	public static function activate(): void {
		self::create_backup_log_table();
		self::create_restore_log_table();
		self::seed_default_settings();
		self::create_protected_directory( WP_CONTENT_DIR . '/wgv-logs' );
		self::create_protected_directory( WP_CONTENT_DIR . '/wgv-temp' );
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Create (or upgrade) the wgv_backup_log table via dbDelta().
	 */
	private static function create_backup_log_table(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table_name      = $wpdb->prefix . 'wgv_backup_log';
		$charset_collate = $wpdb->get_charset_collate();

		// dbDelta requires: each column on its own line, two spaces before the
		// column definition, and the closing paren + ENGINE on its own line.
		$sql = "CREATE TABLE {$table_name} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  backup_type VARCHAR(20) NOT NULL DEFAULT '',
  status VARCHAR(20) NOT NULL DEFAULT '',
  file_name VARCHAR(255) NOT NULL DEFAULT '',
  file_size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
  drive_file_id VARCHAR(255) NOT NULL DEFAULT '',
  drive_folder_id VARCHAR(255) NOT NULL DEFAULT '',
  started_at DATETIME NULL DEFAULT NULL,
  completed_at DATETIME NULL DEFAULT NULL,
  error_message TEXT NULL DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id)
) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * Create (or upgrade) the wgv_restore_log table via dbDelta().
	 */
	private static function create_restore_log_table(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table_name      = $wpdb->prefix . 'wgv_restore_log';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  restore_type VARCHAR(20) NOT NULL DEFAULT '',
  source VARCHAR(20) NOT NULL DEFAULT '',
  drive_file_id VARCHAR(255) NOT NULL DEFAULT '',
  file_name VARCHAR(255) NOT NULL DEFAULT '',
  status VARCHAR(20) NOT NULL DEFAULT '',
  pre_backup_status VARCHAR(20) NOT NULL DEFAULT 'skipped',
  notes TEXT NULL DEFAULT NULL,
  restored_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id)
) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * Insert default settings into wp_options if the key does not already exist.
	 *
	 * add_option() is a no-op when the key is present, so it is safe to call on
	 * every activation without overwriting values the user has already saved.
	 */
	private static function seed_default_settings(): void {
		$defaults = [
			'backup_type'          => 'database',
			'frequency'            => 'daily',
			'retention_enabled'    => true,
			'retention_count'      => 7,
			'drive_client_id'      => '',
			'drive_client_secret'  => '',
			'drive_access_token'   => '',
			'drive_refresh_token'  => '',
			'drive_folder_name'    => 'WG Vault – ' . sanitize_text_field( get_bloginfo( 'name' ) ),
			'drive_folder_id'      => '',
			'alert_email'          => get_option( 'admin_email' ),
			'log_enabled'          => true,
			'last_backup_at'       => '',
			'last_backup_status'   => '',
		];

		add_option( 'wgv_settings', $defaults, '', 'no' );
	}

	/**
	 * Create a directory that is protected from direct HTTP access.
	 *
	 * Places an empty index.php and a Deny-all .htaccess inside the directory
	 * so that web requests cannot enumerate or download its contents.
	 *
	 * @param string $dir Absolute path to the directory (no trailing slash).
	 */
	private static function create_protected_directory( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		$index_file = $dir . '/index.php';
		if ( ! file_exists( $index_file ) ) {
			file_put_contents( $index_file, "<?php\n// Silence is golden.\n" );
		}

		$htaccess_file = $dir . '/.htaccess';
		if ( ! file_exists( $htaccess_file ) ) {
			file_put_contents( $htaccess_file, "Deny from all\n" );
		}
	}
}
