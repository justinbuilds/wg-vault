<?php
/**
 * Sends email alerts and writes structured log entries for backup events.
 *
 * WGV_Notifier dispatches failure alert emails to the configured address
 * and appends timestamped lines to a rotating log file stored in
 * wp-content/wgv-logs/. All behaviour is gated by plugin settings.
 */

defined( 'ABSPATH' ) || exit;

class WGV_Notifier {

	private WGV_Settings $settings;

	/** Absolute path to the active log file. */
	private string $log_file;

	/** Maximum log file size in bytes before rotation (5 MB). */
	private int $max_log_size;

	public function __construct( WGV_Settings $settings ) {
		$this->settings     = $settings;
		$this->log_file     = WP_CONTENT_DIR . '/wgv-logs/wgv-backup.log';
		$this->max_log_size = 5 * 1024 * 1024;
	}

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Send a failure alert email and log the error.
	 *
	 * Writes the error to the log file regardless of whether an alert_email
	 * is configured. The email is only sent when alert_email is non-empty.
	 *
	 * @param string $context       Human-readable label for the step that failed.
	 * @param string $error_message Detail of the error that occurred.
	 */
	public function send_failure_alert( string $context, string $error_message ): void {
		$this->write_log( 'ERROR', $context . ': ' . $error_message );

		$alert_email = $this->settings->get( 'alert_email', '' );

		if ( empty( $alert_email ) ) {
			return;
		}

		$blogname    = get_bloginfo( 'name' );
		$site_url    = get_site_url();
		$backup_type = $this->settings->get( 'backup_type', 'database' );
		$date        = wp_date( 'F j, Y' );
		$now         = wp_date( 'F j, Y g:i a' );
		$log_url     = admin_url( 'admin.php?page=wg-vault&tab=backup-log' );

		$subject = sprintf( '[WG Vault] Backup Failed – %s – %s', $blogname, $date );

		$body  = "Site: {$site_url}\n";
		$body .= "Backup Type: {$backup_type}\n";
		$body .= "Failed Step: {$context}\n";
		$body .= "Error: {$error_message}\n";
		$body .= "Time: {$now}\n";
		$body .= "View Log: {$log_url}\n";

		wp_mail( $alert_email, $subject, $body );
	}

	/**
	 * Append a timestamped line to the log file.
	 *
	 * Returns early when log_enabled is false. Rotates the log file to
	 * wgv-backup.log.bak when it reaches the maximum size before writing.
	 *
	 * @param string $level   Log level label, e.g. 'INFO' or 'ERROR'.
	 * @param string $message Human-readable message to record.
	 */
	public function write_log( string $level, string $message ): void {
		if ( ! $this->settings->get( 'log_enabled', true ) ) {
			return;
		}

		$log_dir = dirname( $this->log_file );
		if ( ! is_dir( $log_dir ) ) {
			wp_mkdir_p( $log_dir );
		}

		if ( file_exists( $this->log_file )
			&& filesize( $this->log_file ) >= $this->max_log_size
		) {
			rename( $this->log_file, $this->log_file . '.bak' );
		}

		$datetime = wp_date( 'Y-m-d H:i:s' );
		$line     = "[{$datetime}] [{$level}] {$message}\n";

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $this->log_file, $line, FILE_APPEND | LOCK_EX );
	}

	/**
	 * Log an informational message to the backup log file.
	 *
	 * @param string $message Human-readable informational message.
	 */
	public function log_info( string $message ): void {
		$this->write_log( 'INFO', $message );
	}

	/**
	 * Log an error message to the backup log file and the server error log.
	 *
	 * @param string $message Human-readable description of the error.
	 */
	public function log_error( string $message ): void {
		$this->write_log( 'ERROR', $message );
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( '[WG Vault] ' . $message );
	}
}
