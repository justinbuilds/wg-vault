<?php
/**
 * Orchestrates the full backup process.
 *
 * WGV_Backup is responsible for dumping the WordPress database,
 * optionally archiving site files, packaging everything into a ZIP,
 * and handing the archive path off to WGV_Drive for upload.
 */

defined( 'ABSPATH' ) || exit;

class WGV_Backup {

	/**
	 * Temporary directory used to stage backup files before archiving.
	 */
	private string $staging_dir = '';

	public function __construct() {
		$this->staging_dir = WGV_PLUGIN_DIR . 'logs/';
	}

	/**
	 * Execute a complete backup run.
	 *
	 * Returns the absolute path to the created archive on success,
	 * or throws a RuntimeException on failure.
	 *
	 * @throws RuntimeException
	 */
	public function run(): string {
		// Full implementation:
		// 1. dump_database()  — export DB to SQL file in staging_dir
		// 2. archive_files()  — optionally add wp-content files to ZIP
		// 3. create_archive() — compress staging_dir into a single ZIP
		// 4. Return archive path for WGV_Drive to upload.

		throw new \RuntimeException( 'WGV_Backup::run() is not yet implemented.' );
	}

	/**
	 * Dump the WordPress database to a .sql file in the staging directory.
	 */
	private function dump_database(): string {
		// Will use mysqldump via exec() or a pure-PHP SQL exporter.
		return '';
	}

	/**
	 * Create a ZIP archive from the staged files and return its path.
	 */
	private function create_archive(): string {
		// Will use ZipArchive (PHP built-in, no Composer required).
		return '';
	}
}
