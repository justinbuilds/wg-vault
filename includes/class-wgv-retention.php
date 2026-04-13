<?php
/**
 * Enforces the backup retention policy.
 *
 * WGV_Retention lists existing backup files on Google Drive (and locally),
 * then deletes the oldest ones once the configured retention count is
 * exceeded. This keeps storage usage predictable.
 */

defined( 'ABSPATH' ) || exit;

class WGV_Retention {

	/**
	 * Maximum number of backup archives to retain.
	 * Loaded from wgv_settings['retention_count'].
	 */
	private int $max_backups = 7;

	public function __construct() {
		// Retention count will be read from WGV_Settings.
	}

	/**
	 * Apply the retention policy after a successful backup.
	 *
	 * Lists all remote backup files, sorts by age descending, and
	 * deletes any that exceed $max_backups.
	 */
	public function enforce(): void {
		// Full implementation:
		// 1. list_remote_backups()  — fetch file list from Drive via WGV_Drive
		// 2. Sort by createdTime ascending
		// 3. Delete oldest files until count <= max_backups
		// 4. Optionally clean up local staging files.
	}

	/**
	 * Delete a single backup archive from Google Drive by file ID.
	 */
	private function delete_remote( string $file_id ): void {
		// Will call WGV_Drive to send a DELETE request to the Drive API.
	}
}
