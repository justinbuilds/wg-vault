<?php
/**
 * Sends email notifications for backup events.
 *
 * WGV_Notifier dispatches success or failure emails to the address
 * configured in wgv_settings, using wp_mail() so the site's existing
 * mail setup (SMTP plugins, etc.) is respected automatically.
 */

defined( 'ABSPATH' ) || exit;

class WGV_Notifier {

	/**
	 * Recipient email address from wgv_settings['notify_email'].
	 */
	private string $to = '';

	public function __construct() {
		// Email address and notification preferences loaded from WGV_Settings.
	}

	/**
	 * Send a backup-success notification.
	 *
	 * @param string $archive_name  Filename of the completed backup archive.
	 * @param string $drive_file_id Google Drive file ID of the uploaded backup.
	 */
	public function send_success( string $archive_name, string $drive_file_id ): void {
		// Will compose and send a success email via wp_mail().
	}

	/**
	 * Send a backup-failure notification.
	 *
	 * @param string     $context   Human-readable description of the step that failed.
	 * @param \Throwable $error     The exception or error that caused the failure.
	 */
	public function send_failure( string $context, \Throwable $error ): void {
		// Will compose and send a failure email via wp_mail().
	}

	/**
	 * Log an error message to the server error log.
	 *
	 * Used by WGV_Drive and other components to record non-fatal API and
	 * authentication failures without sending an email notification.
	 *
	 * @param string $message Human-readable description of the error.
	 */
	public static function log_error( string $message ): void {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( '[WG Vault] ' . $message );
	}

	/**
	 * Build a plain-text email body from a template string and variables.
	 */
	private function build_message( string $template, array $vars ): string {
		return str_replace( array_keys( $vars ), array_values( $vars ), $template );
	}
}
