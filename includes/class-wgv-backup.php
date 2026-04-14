<?php
/**
 * Orchestrates the full backup process.
 *
 * WGV_Backup is responsible for dumping the WordPress database,
 * archiving the uploads directory, packaging a full wp-content ZIP,
 * and handing each archive off to WGV_Drive for upload.
 */

defined( 'ABSPATH' ) || exit;

class WGV_Backup {

	/** Temporary staging directory for all in-flight backup files. */
	private string $temp_dir = '';

	private WGV_Settings  $settings;
	private WGV_Drive     $drive;
	private WGV_Notifier  $notifier;
	private WGV_Retention $retention;

	public function __construct(
		WGV_Settings $settings,
		WGV_Drive $drive,
		WGV_Notifier $notifier,
		WGV_Retention $retention
	) {
		$this->settings  = $settings;
		$this->drive     = $drive;
		$this->notifier  = $notifier;
		$this->retention = $retention;
		$this->temp_dir  = WP_CONTENT_DIR . '/wgv-temp/';
	}

	// -------------------------------------------------------------------------
	// Public backup entry points
	// -------------------------------------------------------------------------

	/**
	 * Export the WordPress database and upload it as a gzipped SQL file.
	 *
	 * Attempts mysqldump first; falls back to a pure-PHP exporter when exec()
	 * is disabled. Skips transient and WooCommerce session tables in the PHP
	 * path.
	 *
	 * @return bool True on success, false on failure.
	 */
	public function run_database_backup(): bool {
		$log_id   = $this->insert_log_entry( 'database' );
		$datetime = gmdate( 'Y-m-d_H-i-s' );
		$hash     = substr( md5( get_site_url() ), 0, 8 );
		$base     = $this->temp_dir . "wgv_database_{$datetime}_{$hash}";
		$sql_file = $base . '.sql';
		$gz_file  = $base . '.sql.gz';

		try {
			$this->ensure_temp_dir();

			$exported = false;

			if ( $this->is_exec_available() ) {
				$exported = $this->dump_via_mysqldump( $sql_file );
			}

			if ( ! $exported ) {
				$exported = $this->dump_via_php( $sql_file );
			}

			if ( ! $exported || ! file_exists( $sql_file ) ) {
				throw new \RuntimeException( 'Database export produced no output file.' );
			}

			$gz_file = $this->gzip_file( $sql_file );

			if ( empty( $gz_file ) || ! file_exists( $gz_file ) ) {
				throw new \RuntimeException( 'Gzip compression of SQL file failed.' );
			}

			return $this->finalize_backup( $log_id, 'database', $gz_file, basename( $gz_file ) );

		} catch ( \Exception $e ) {
			$this->handle_failure(
				$log_id,
				'database',
				$e->getMessage(),
				[ $sql_file, $gz_file ]
			);
			return false;
		}
	}

	/**
	 * Archive the uploads directory and upload it as a ZIP file.
	 *
	 * Files larger than 100 MB and hidden files (names starting with ".") are
	 * silently skipped; the backup is not failed for oversized files.
	 *
	 * @return bool True on success, false on failure.
	 */
	public function run_uploads_backup(): bool {
		$log_id      = $this->insert_log_entry( 'uploads' );
		$datetime    = gmdate( 'Y-m-d_H-i-s' );
		$hash        = substr( md5( get_site_url() ), 0, 8 );
		$file_name   = "wgv_uploads_{$datetime}_{$hash}.zip";
		$zip_path    = $this->temp_dir . $file_name;
		$uploads_dir = WP_CONTENT_DIR . '/uploads/';

		try {
			$this->ensure_temp_dir();

			if ( ! class_exists( 'ZipArchive' ) ) {
				throw new \RuntimeException( 'ZipArchive PHP extension is not available.' );
			}

			$zip = new \ZipArchive();
			if ( $zip->open( $zip_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) !== true ) {
				throw new \RuntimeException( 'Could not create uploads ZIP archive.' );
			}

			$this->add_dir_to_zip( $zip, $uploads_dir, $uploads_dir, [] );
			$zip->close();

			return $this->finalize_backup( $log_id, 'uploads', $zip_path, $file_name );

		} catch ( \Exception $e ) {
			$this->handle_failure( $log_id, 'uploads', $e->getMessage(), [ $zip_path ] );
			return false;
		}
	}

	/**
	 * Create a full backup: entire wp-content directory plus a fresh DB dump.
	 *
	 * The generated database .sql.gz is embedded at the root of the ZIP.
	 * Excluded from the ZIP: wgv-temp/, wgv-logs/, node_modules/, .git/,
	 * and any .zip files directly under wp-content/.
	 *
	 * @return bool True on success, false on failure.
	 */
	public function run_full_backup(): bool {
		$log_id   = $this->insert_log_entry( 'full' );
		$datetime = gmdate( 'Y-m-d_H-i-s' );
		$hash     = substr( md5( get_site_url() ), 0, 8 );

		$db_base   = $this->temp_dir . "wgv_db_tmp_{$datetime}_{$hash}";
		$db_sql    = $db_base . '.sql';
		$db_gz     = '';
		$file_name = "wgv_full_{$datetime}_{$hash}.zip";
		$zip_path  = $this->temp_dir . $file_name;

		try {
			$this->ensure_temp_dir();

			// --- Database export ---
			$exported = false;
			if ( $this->is_exec_available() ) {
				$exported = $this->dump_via_mysqldump( $db_sql );
			}
			if ( ! $exported ) {
				$exported = $this->dump_via_php( $db_sql );
			}
			if ( ! $exported || ! file_exists( $db_sql ) ) {
				throw new \RuntimeException( 'Database export produced no output file.' );
			}

			$db_gz = $this->gzip_file( $db_sql );
			if ( empty( $db_gz ) || ! file_exists( $db_gz ) ) {
				throw new \RuntimeException( 'Gzip compression of SQL file failed.' );
			}

			// --- wp-content ZIP ---
			if ( ! class_exists( 'ZipArchive' ) ) {
				throw new \RuntimeException( 'ZipArchive PHP extension is not available.' );
			}

			$zip = new \ZipArchive();
			if ( $zip->open( $zip_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) !== true ) {
				throw new \RuntimeException( 'Could not create full backup ZIP archive.' );
			}

			$wp_content = WP_CONTENT_DIR . '/';
			$exclude    = [
				rtrim( $this->temp_dir, '/' ),
				WP_CONTENT_DIR . '/wgv-logs',
				WP_CONTENT_DIR . '/node_modules',
				WP_CONTENT_DIR . '/.git',
			];

			$this->add_dir_to_zip( $zip, $wp_content, $wp_content, $exclude );

			// Embed the database dump at the ZIP root.
			$zip->addFile( $db_gz, basename( $db_gz ) );
			$zip->close();

			// DB temp file is no longer needed once it's inside the ZIP.
			if ( file_exists( $db_gz ) ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				@unlink( $db_gz );
			}

			return $this->finalize_backup( $log_id, 'full', $zip_path, $file_name );

		} catch ( \Exception $e ) {
			$this->handle_failure(
				$log_id,
				'full',
				$e->getMessage(),
				[ $db_sql, $db_gz, $zip_path ]
			);
			return false;
		}
	}

	// -------------------------------------------------------------------------
	// Private helpers — logging
	// -------------------------------------------------------------------------

	/**
	 * Insert an in-progress log row and return its auto-increment ID.
	 */
	private function insert_log_entry( string $type ): int {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'wgv_backup_log',
			[
				'backup_type' => $type,
				'status'      => 'in_progress',
				'started_at'  => current_time( 'mysql', true ),
			],
			[ '%s', '%s', '%s' ]
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Update an existing log row's status and any extra columns.
	 *
	 * @param int    $id     Row ID returned by insert_log_entry().
	 * @param string $status New status value (e.g. 'success', 'failed').
	 * @param array  $extra  Additional column => value pairs to merge.
	 */
	private function update_log_entry( int $id, string $status, array $extra = [] ): void {
		global $wpdb;

		$data   = array_merge( [ 'status' => $status ], $extra );
		$format = array_fill( 0, count( $data ), '%s' );

		$wpdb->update(
			$wpdb->prefix . 'wgv_backup_log',
			$data,
			[ 'id' => $id ],
			$format,
			[ '%d' ]
		);
	}

	// -------------------------------------------------------------------------
	// Private helpers — utilities
	// -------------------------------------------------------------------------

	/** Return filesize in bytes, or 0 when the file does not exist. */
	private function get_file_size( string $path ): int {
		return file_exists( $path ) ? (int) filesize( $path ) : 0;
	}

	/**
	 * Return true when exec() is available and not in disabled_functions.
	 */
	private function is_exec_available(): bool {
		if ( ! function_exists( 'exec' ) ) {
			return false;
		}

		$disabled = (string) ini_get( 'disable_functions' );
		if ( empty( $disabled ) ) {
			return true;
		}

		$disabled_list = array_map( 'trim', explode( ',', $disabled ) );
		return ! in_array( 'exec', $disabled_list, true );
	}

	/**
	 * Gzip-compress a file using gzencode(), delete the source, and return
	 * the path to the new .gz file on success or an empty string on failure.
	 */
	private function gzip_file( string $source ): string {
		$dest = $source . '.gz';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$data = file_get_contents( $source );
		if ( $data === false ) {
			return '';
		}

		$compressed = gzencode( $data, 6 );
		if ( $compressed === false ) {
			return '';
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( file_put_contents( $dest, $compressed ) === false ) {
			return '';
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		@unlink( $source );

		return $dest;
	}

	/**
	 * Recursively add a directory tree to an open ZipArchive.
	 *
	 * Hidden files (basename starting with ".") and files larger than 100 MB
	 * are skipped. Any file whose real path begins with an entry in $exclude
	 * is also skipped. .zip files located directly under $base are excluded
	 * to avoid including old archive files in a fresh backup.
	 *
	 * @param \ZipArchive $zip     Open archive instance.
	 * @param string      $dir     Absolute path to the directory to add.
	 * @param string      $base    Root path stripped from entries to form the
	 *                             relative path stored inside the ZIP.
	 * @param string[]    $exclude Absolute paths whose subtrees should be omitted.
	 */
	private function add_dir_to_zip( \ZipArchive $zip, string $dir, string $base, array $exclude ): void {
		$dir  = rtrim( $dir, '/' ) . '/';
		$base = rtrim( $base, '/' ) . '/';

		if ( ! is_dir( $dir ) ) {
			return;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \RecursiveDirectoryIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $iterator as $file ) {
			/** @var \SplFileInfo $file */
			$real_path = $file->getRealPath();
			$filename  = $file->getFilename();

			// Skip hidden files and directories.
			if ( isset( $filename[0] ) && $filename[0] === '.' ) {
				continue;
			}

			// Skip any excluded subtrees.
			$skip = false;
			foreach ( $exclude as $excl ) {
				if ( $excl !== '' && strpos( $real_path, rtrim( $excl, '/' ) ) === 0 ) {
					$skip = true;
					break;
				}
			}
			if ( $skip ) {
				continue;
			}

			$rel_path = substr( $real_path, strlen( $base ) );

			if ( $file->isDir() ) {
				$zip->addEmptyDir( $rel_path );
				continue;
			}

			// Skip .zip files at the root level of $base.
			if ( $file->getExtension() === 'zip'
				&& rtrim( $file->getPath(), '/' ) === rtrim( $base, '/' )
			) {
				continue;
			}

			// Skip oversized files — log the skip but do not fail.
			if ( $file->getSize() > 100 * 1024 * 1024 ) {
				$this->notifier->log_info(
					sprintf( 'Skipped file exceeding 100 MB size limit: %s', $rel_path )
				);
				continue;
			}

			$zip->addFile( $real_path, $rel_path );
		}
	}

	// -------------------------------------------------------------------------
	// Private helpers — database export
	// -------------------------------------------------------------------------

	/**
	 * Export the database using mysqldump via exec().
	 *
	 * @param  string $sql_file Destination .sql file path.
	 * @return bool             True when the file was created and is non-empty.
	 */
	private function dump_via_mysqldump( string $sql_file ): bool {
		$cmd = sprintf(
			'mysqldump --no-tablespaces -u%s -p%s -h%s %s > %s 2>&1',
			escapeshellarg( DB_USER ),
			escapeshellarg( DB_PASSWORD ),
			escapeshellarg( DB_HOST ),
			escapeshellarg( DB_NAME ),
			escapeshellarg( $sql_file )
		);

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		exec( $cmd, $output, $return_code );

		return 0 === $return_code
			&& file_exists( $sql_file )
			&& filesize( $sql_file ) > 0;
	}

	/**
	 * Export the database using pure PHP when exec() is unavailable.
	 *
	 * Processes tables in batches of 500 rows. Skips tables whose names
	 * contain _transient_, _site_transient_, or wc_session_.
	 *
	 * @param  string $sql_file Destination .sql file path.
	 * @return bool             True when the file was created and is non-empty.
	 */
	private function dump_via_php( string $sql_file ): bool {
		global $wpdb;

		$skip_patterns = [ '_transient_', '_site_transient_', 'wc_session_' ];
		$batch_size    = 500;

		$tables = $wpdb->get_results( 'SHOW TABLES', ARRAY_N );
		if ( empty( $tables ) ) {
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$handle = fopen( $sql_file, 'w' );
		if ( ! $handle ) {
			return false;
		}

		fwrite( $handle, "-- WG Vault database export\n" );
		fwrite( $handle, '-- Generated: ' . gmdate( 'Y-m-d H:i:s' ) . " UTC\n\n" );
		fwrite( $handle, "SET FOREIGN_KEY_CHECKS=0;\n\n" );

		foreach ( $tables as $table_row ) {
			$table = $table_row[0];

			$skip = false;
			foreach ( $skip_patterns as $pattern ) {
				if ( strpos( $table, $pattern ) !== false ) {
					$skip = true;
					break;
				}
			}
			if ( $skip ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$create_row = $wpdb->get_row( "SHOW CREATE TABLE `{$table}`", ARRAY_N );
			if ( ! empty( $create_row[1] ) ) {
				fwrite( $handle, "DROP TABLE IF EXISTS `{$table}`;\n" );
				fwrite( $handle, $create_row[1] . ";\n\n" );
			}

			$offset = 0;
			do {
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT * FROM `{$table}` LIMIT %d OFFSET %d",
						$batch_size,
						$offset
					),
					ARRAY_A
				);

				if ( empty( $rows ) ) {
					break;
				}

				$columns = '`' . implode( '`, `', array_keys( $rows[0] ) ) . '`';

				foreach ( $rows as $row ) {
					$values = array_map(
						static function ( $val ): string {
							if ( null === $val ) {
								return 'NULL';
							}
							return "'" . esc_sql( $val ) . "'";
						},
						array_values( $row )
					);
					fwrite( $handle, "INSERT INTO `{$table}` ({$columns}) VALUES (" . implode( ', ', $values ) . ");\n" );
				}

				$offset += $batch_size;
			} while ( count( $rows ) === $batch_size );

			fwrite( $handle, "\n" );
		}

		fwrite( $handle, "SET FOREIGN_KEY_CHECKS=1;\n" );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $handle );

		return file_exists( $sql_file ) && filesize( $sql_file ) > 0;
	}

	// -------------------------------------------------------------------------
	// Private helpers — post-backup orchestration
	// -------------------------------------------------------------------------

	/**
	 * Upload the finished backup file to Drive, update logs and settings, and
	 * enforce the retention policy.
	 *
	 * @param  int    $log_id    Log row to update.
	 * @param  string $type      Backup type label ('database', 'uploads', 'full').
	 * @param  string $file_path Absolute path to the local archive.
	 * @param  string $file_name Filename to use on Google Drive.
	 * @return bool              True on success, false on failure.
	 */
	private function finalize_backup( int $log_id, string $type, string $file_path, string $file_name ): bool {
		$file_size   = $this->get_file_size( $file_path );
		$folder_name = $this->settings->get( 'drive_folder_name', 'WG Vault' );

		$folder_id = $this->drive->get_or_create_folder( $folder_name );
		if ( empty( $folder_id ) ) {
			$this->handle_failure( $log_id, $type, 'Could not get or create Drive folder.', [ $file_path ] );
			return false;
		}

		$drive_file_id = $this->drive->upload_file( $file_path, $file_name, $folder_id );
		if ( empty( $drive_file_id ) ) {
			$this->handle_failure( $log_id, $type, 'Drive upload failed.', [ $file_path ] );
			return false;
		}

		// Remove local temp file immediately after confirmed upload.
		if ( file_exists( $file_path ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			@unlink( $file_path );
		}

		$now = current_time( 'mysql', true );

		$this->update_log_entry(
			$log_id,
			'success',
			[
				'file_name'       => $file_name,
				'file_size_bytes' => (string) $file_size,
				'drive_file_id'   => $drive_file_id,
				'drive_folder_id' => $folder_id,
				'completed_at'    => $now,
			]
		);

		$this->retention->enforce();

		$this->settings->set( 'last_backup_at', $now );
		$this->settings->set( 'last_backup_status', 'success' );

		$this->notifier->log_info(
			sprintf(
				'%s backup completed successfully: %s (Drive ID: %s)',
				ucfirst( $type ),
				$file_name,
				$drive_file_id
			)
		);

		return true;
	}

	/**
	 * Record a failure in the log, send an alert, update settings, and clean
	 * up any partial temp files.
	 *
	 * @param int      $log_id       Log row to update.
	 * @param string   $context      Human-readable label for the failing step.
	 * @param string   $error_message Error detail to store and send.
	 * @param string[] $temp_files   Absolute paths of partial files to delete.
	 */
	private function handle_failure(
		int $log_id,
		string $context,
		string $error_message,
		array $temp_files = []
	): void {
		$this->update_log_entry(
			$log_id,
			'failed',
			[
				'completed_at'  => current_time( 'mysql', true ),
				'error_message' => $error_message,
			]
		);

		$this->notifier->send_failure_alert( $context, $error_message );

		$this->settings->set( 'last_backup_status', 'failed' );

		foreach ( $temp_files as $file ) {
			if ( ! empty( $file ) && file_exists( $file ) ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				@unlink( $file );
			}
		}
	}

	/** Create the temp directory if it does not yet exist. */
	private function ensure_temp_dir(): void {
		if ( ! is_dir( $this->temp_dir ) ) {
			wp_mkdir_p( $this->temp_dir );
		}
	}
}
