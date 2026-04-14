<?php
/**
 * Enforces the backup retention policy.
 *
 * WGV_Retention queries the backup log for successful backups, then deletes
 * the oldest Drive files and log rows whenever the stored count exceeds the
 * configured retention limit.
 */

defined( 'ABSPATH' ) || exit;

class WGV_Retention {

	private WGV_Settings $settings;
	private WGV_Drive    $drive;

	public function __construct( WGV_Settings $settings, WGV_Drive $drive ) {
		$this->settings = $settings;
		$this->drive    = $drive;
	}

	/**
	 * Apply the retention policy after a successful backup completes.
	 *
	 * Reads retention_count from settings, fetches all successful backup log
	 * rows ordered newest-first, and deletes any rows (plus their Drive files)
	 * that exceed the allowed count.
	 */
	public function enforce(): void {
		if ( ! $this->settings->get( 'retention_enabled', true ) ) {
			return;
		}

		$retention_count = (int) $this->settings->get( 'retention_count', 7 );

		global $wpdb;
		$table = $wpdb->prefix . 'wgv_backup_log';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			"SELECT id, drive_file_id, file_name FROM {$table} WHERE status = 'success' ORDER BY completed_at DESC"
		);

		if ( ! is_array( $rows ) || count( $rows ) <= $retention_count ) {
			return;
		}

		$to_delete = array_slice( $rows, $retention_count );

		foreach ( $to_delete as $row ) {
			if ( ! empty( $row->drive_file_id ) ) {
				$deleted = $this->drive->delete_file( $row->drive_file_id );

				if ( ! $deleted ) {
					$wpdb->update(
						$table,
						[ 'error_message' => 'retention_delete_failed' ],
						[ 'id' => $row->id ],
						[ '%s' ],
						[ '%d' ]
					);
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					error_log( '[WG Vault] Retention: failed to delete Drive file ' . $row->drive_file_id );
					continue;
				}
			}

			$wpdb->delete( $table, [ 'id' => $row->id ], [ '%d' ] );

			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[WG Vault] Retention: deleted ' . $row->file_name );
		}
	}
}
