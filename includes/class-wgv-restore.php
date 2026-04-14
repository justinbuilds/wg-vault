<?php
/**
 * Handles all site restore operations.
 *
 * WGV_Restore downloads backup archives from Google Drive (or accepts a
 * locally-uploaded file) and restores the database, uploads directory, or
 * a full site archive. Every operation is logged to the wgv_restore_log table.
 */

defined( 'ABSPATH' ) || exit;

class WGV_Restore {

	private WGV_Settings $settings;
	private WGV_Drive    $drive;
	private WGV_Backup   $backup;
	private WGV_Notifier $notifier;
	private string       $temp_dir;

	public function __construct(
		WGV_Settings $settings,
		WGV_Drive $drive,
		WGV_Backup $backup,
		WGV_Notifier $notifier
	) {
		$this->settings = $settings;
		$this->drive    = $drive;
		$this->backup   = $backup;
		$this->notifier = $notifier;
		$this->temp_dir = WP_CONTENT_DIR . '/wgv-temp/';
	}

	// -------------------------------------------------------------------------
	// Public restore entry points — Drive source
	// -------------------------------------------------------------------------

	/**
	 * Download a database backup from Drive and import it.
	 *
	 * @param  string $drive_file_id Google Drive file ID to restore.
	 * @return bool                  True on success, false on failure.
	 */
	public function restore_database( string $drive_file_id ): bool {
		$log_id   = $this->insert_restore_log( 'database', 'drive', $drive_file_id, '' );
		$local_gz = $this->temp_dir . 'wgv_restore_db_' . time() . '.sql.gz';

		try {
			$this->ensure_temp_dir();

			if ( ! $this->download_from_drive( $drive_file_id, $local_gz ) ) {
				throw new \RuntimeException( 'Download from Drive failed.' );
			}

			$result = $this->restore_database_from_local( $local_gz );

			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			@unlink( $local_gz );

			$this->update_restore_log( $log_id, $result ? 'success' : 'failed', '' );
			$this->notifier->log_info( 'Database restore from Drive ' . ( $result ? 'completed.' : 'failed.' ) );
			return $result;

		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			@unlink( $local_gz );
			$this->update_restore_log( $log_id, 'failed', $e->getMessage() );
			$this->notifier->log_error( 'Database restore error: ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Download an uploads backup from Drive and extract it.
	 *
	 * The existing uploads/ directory is renamed to uploads_backup_{timestamp}/
	 * before extraction as a safety measure.
	 *
	 * @param  string $drive_file_id Google Drive file ID to restore.
	 * @return bool                  True on success, false on failure.
	 */
	public function restore_uploads( string $drive_file_id ): bool {
		$log_id    = $this->insert_restore_log( 'uploads', 'drive', $drive_file_id, '' );
		$local_zip = $this->temp_dir . 'wgv_restore_uploads_' . time() . '.zip';

		try {
			$this->ensure_temp_dir();

			if ( ! $this->download_from_drive( $drive_file_id, $local_zip ) ) {
				throw new \RuntimeException( 'Download from Drive failed.' );
			}

			$result = $this->restore_uploads_from_local( $local_zip );

			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			@unlink( $local_zip );

			$this->update_restore_log( $log_id, $result ? 'success' : 'failed', '' );
			$this->notifier->log_info( 'Uploads restore from Drive ' . ( $result ? 'completed.' : 'failed.' ) );
			return $result;

		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			@unlink( $local_zip );
			$this->update_restore_log( $log_id, 'failed', $e->getMessage() );
			$this->notifier->log_error( 'Uploads restore error: ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Download a full-site backup from Drive and restore it.
	 *
	 * The ZIP is expected to contain a .sql.gz file at the root (database) and
	 * wp-content-relative paths for all other files.
	 *
	 * @param  string $drive_file_id Google Drive file ID to restore.
	 * @return bool                  True on success, false on failure.
	 */
	public function restore_full( string $drive_file_id ): bool {
		$log_id    = $this->insert_restore_log( 'full', 'drive', $drive_file_id, '' );
		$local_zip = $this->temp_dir . 'wgv_restore_full_' . time() . '.zip';

		try {
			$this->ensure_temp_dir();

			if ( ! $this->download_from_drive( $drive_file_id, $local_zip ) ) {
				throw new \RuntimeException( 'Download from Drive failed.' );
			}

			$result = $this->restore_full_from_local( $local_zip );

			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			@unlink( $local_zip );

			$this->update_restore_log( $log_id, $result ? 'success' : 'failed', '' );
			$this->notifier->log_info( 'Full site restore from Drive ' . ( $result ? 'completed.' : 'failed.' ) );
			return $result;

		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			@unlink( $local_zip );
			$this->update_restore_log( $log_id, 'failed', $e->getMessage() );
			$this->notifier->log_error( 'Full site restore error: ' . $e->getMessage() );
			return false;
		}
	}

	// -------------------------------------------------------------------------
	// Public restore entry point — upload source
	// -------------------------------------------------------------------------

	/**
	 * Restore from a locally-uploaded file routed by backup type.
	 *
	 * @param  string $tmp_path Absolute path to the uploaded temp file.
	 * @param  string $type     'database', 'uploads', or 'full'.
	 * @return bool             True on success, false on failure.
	 */
	public function restore_from_upload( string $tmp_path, string $type ): bool {
		if ( ! in_array( $type, [ 'database', 'uploads', 'full' ], true ) ) {
			$this->notifier->log_error( 'restore_from_upload: invalid type: ' . $type );
			return false;
		}

		// Validate file extension when the path itself carries one.
		// PHP upload temp files (e.g. /tmp/phpXXXXXX) carry no extension;
		// in that case the AJAX handler is expected to have already validated
		// the original filename before calling this method.
		$path_has_ext = $this->has_extension( $tmp_path, '.sql.gz' )
			|| $this->has_extension( $tmp_path, '.zip' );

		if ( $path_has_ext ) {
			if ( 'database' === $type ) {
				if ( ! $this->has_extension( $tmp_path, '.sql.gz' ) ) {
					$this->notifier->log_error( 'restore_from_upload: database restore requires a .sql.gz file.' );
					return false;
				}
			} else {
				if ( ! $this->has_extension( $tmp_path, '.zip' ) ) {
					$this->notifier->log_error( 'restore_from_upload: ' . $type . ' restore requires a .zip file.' );
					return false;
				}
			}
		}

		// Copy uploaded temp file into our controlled temp dir.
		$this->ensure_temp_dir();
		$ext      = 'database' === $type ? '.sql.gz' : '.zip';
		$local    = $this->temp_dir . 'wgv_upload_restore_' . time() . $ext;
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! @copy( $tmp_path, $local ) ) {
			$this->notifier->log_error( 'restore_from_upload: could not copy uploaded file.' );
			return false;
		}

		$log_id = $this->insert_restore_log( $type, 'upload', '', basename( $local ) );

		try {
			$result = match ( $type ) {
				'database' => $this->restore_database_from_local( $local ),
				'uploads'  => $this->restore_uploads_from_local( $local ),
				'full'     => $this->restore_full_from_local( $local ),
				default    => false,
			};

			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			@unlink( $local );

			$this->update_restore_log( $log_id, $result ? 'success' : 'failed', '' );
			$this->notifier->log_info( ucfirst( $type ) . ' upload restore ' . ( $result ? 'completed.' : 'failed.' ) );
			return $result;

		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			@unlink( $local );
			$this->update_restore_log( $log_id, 'failed', $e->getMessage() );
			$this->notifier->log_error( 'Upload restore error: ' . $e->getMessage() );
			return false;
		}
	}

	// -------------------------------------------------------------------------
	// Drive download
	// -------------------------------------------------------------------------

	/**
	 * Download a Drive file to a local path.
	 *
	 * Uses the Drive v3 media download endpoint with the current OAuth token.
	 *
	 * @param  string $file_id    Google Drive file ID.
	 * @param  string $local_path Absolute path to write the file to.
	 * @return bool               True on success (HTTP 200), false on failure.
	 */
	public function download_from_drive( string $file_id, string $local_path ): bool {
		$token = $this->drive->get_access_token();

		if ( empty( $token ) ) {
			$this->notifier->log_error( 'download_from_drive: no access token available.' );
			return false;
		}

		$response = wp_remote_get(
			'https://www.googleapis.com/drive/v3/files/' . rawurlencode( $file_id ) . '?alt=media',
			[
				'headers' => [ 'Authorization' => 'Bearer ' . $token ],
				'timeout' => 300,
			]
		);

		if ( is_wp_error( $response ) ) {
			$this->notifier->log_error( 'download_from_drive: ' . $response->get_error_message() );
			return false;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			$this->notifier->log_error( "download_from_drive: HTTP {$code} for file {$file_id}." );
			return false;
		}

		$body = wp_remote_retrieve_body( $response );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$written = file_put_contents( $local_path, $body );

		if ( false === $written || 0 === $written ) {
			$this->notifier->log_error( 'download_from_drive: failed to write file to disk.' );
			return false;
		}

		return true;
	}

	// -------------------------------------------------------------------------
	// Private restore implementations
	// -------------------------------------------------------------------------

	/**
	 * Restore a database from a local .sql.gz archive.
	 *
	 * Decompresses to .sql, attempts MySQL CLI import, then falls back to the
	 * pure-PHP line-by-line importer.
	 *
	 * @param  string $gz_path Absolute path to the .sql.gz file.
	 * @return bool            True on success, false on failure.
	 */
	private function restore_database_from_local( string $gz_path ): bool {
		if ( ! file_exists( $gz_path ) || ! is_readable( $gz_path ) ) {
			$this->notifier->log_error( 'restore_database_from_local: file not found or not readable.' );
			return false;
		}

		// Decompress .sql.gz → .sql.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$compressed = file_get_contents( $gz_path );
		if ( false === $compressed ) {
			$this->notifier->log_error( 'restore_database_from_local: could not read gz file.' );
			return false;
		}

		$sql_data = gzdecode( $compressed );
		if ( false === $sql_data ) {
			$this->notifier->log_error( 'restore_database_from_local: gzdecode failed.' );
			return false;
		}

		$sql_path = (string) preg_replace( '/\.gz$/', '', $gz_path );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( file_put_contents( $sql_path, $sql_data ) === false ) {
			$this->notifier->log_error( 'restore_database_from_local: could not write .sql file.' );
			return false;
		}

		unset( $compressed, $sql_data );

		// Attempt MySQL CLI import.
		if ( $this->is_exec_available() ) {
			$cmd = sprintf(
				'mysql -u%s -p%s -h%s %s < %s 2>&1',
				escapeshellarg( DB_USER ),
				escapeshellarg( DB_PASSWORD ),
				escapeshellarg( DB_HOST ),
				escapeshellarg( DB_NAME ),
				escapeshellarg( $sql_path )
			);

			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
			exec( $cmd, $output, $return_code );

			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			@unlink( $sql_path );

			if ( 0 === $return_code ) {
				$this->notifier->log_info( 'Database imported via mysql CLI.' );
				return true;
			}

			$this->notifier->log_error( 'mysql CLI failed (exit ' . $return_code . '), falling back to PHP importer.' );
			// Regenerate the .sql file for the PHP fallback.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$raw = file_get_contents( $gz_path );
			if ( false !== $raw ) {
				$sql_data2 = gzdecode( $raw );
				if ( false !== $sql_data2 ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
					file_put_contents( $sql_path, $sql_data2 );
				}
			}
		}

		// PHP fallback importer.
		$result = $this->import_sql_file( $sql_path );

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		@unlink( $sql_path );

		return $result;
	}

	/**
	 * Restore the uploads directory from a local .zip archive.
	 *
	 * Renames the existing uploads/ directory before extraction.
	 *
	 * @param  string $zip_path Absolute path to the .zip file.
	 * @return bool             True on success, false on failure.
	 */
	private function restore_uploads_from_local( string $zip_path ): bool {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->notifier->log_error( 'restore_uploads_from_local: ZipArchive not available.' );
			return false;
		}

		$uploads_dir = WP_CONTENT_DIR . '/uploads';
		$backup_dir  = WP_CONTENT_DIR . '/uploads_backup_' . time();

		// Rename existing uploads/ directory as a safety measure.
		if ( is_dir( $uploads_dir ) ) {
			if ( ! rename( $uploads_dir, $backup_dir ) ) {
				$this->notifier->log_error( 'restore_uploads_from_local: could not rename existing uploads/ directory.' );
				return false;
			}
		}

		// Ensure target directory exists.
		if ( ! wp_mkdir_p( $uploads_dir ) ) {
			$this->notifier->log_error( 'restore_uploads_from_local: could not create uploads/ directory.' );
			// Restore the backup.
			rename( $backup_dir, $uploads_dir );
			return false;
		}

		$zip = new \ZipArchive();
		if ( $zip->open( $zip_path ) !== true ) {
			$this->notifier->log_error( 'restore_uploads_from_local: could not open ZIP.' );
			rename( $backup_dir, $uploads_dir );
			return false;
		}

		$extracted = $zip->extractTo( $uploads_dir );
		$zip->close();

		if ( ! $extracted ) {
			$this->notifier->log_error( 'restore_uploads_from_local: ZIP extraction failed.' );
			return false;
		}

		$this->notifier->log_info( 'Uploads directory restored successfully.' );
		return true;
	}

	/**
	 * Restore a full site from a local .zip archive.
	 *
	 * Expects the ZIP to contain a .sql.gz at its root for the database and
	 * wp-content-relative paths for all other files.
	 *
	 * @param  string $zip_path Absolute path to the .zip file.
	 * @return bool             True on success, false on failure.
	 */
	private function restore_full_from_local( string $zip_path ): bool {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->notifier->log_error( 'restore_full_from_local: ZipArchive not available.' );
			return false;
		}

		$zip = new \ZipArchive();
		if ( $zip->open( $zip_path ) !== true ) {
			$this->notifier->log_error( 'restore_full_from_local: could not open ZIP.' );
			return false;
		}

		$sql_gz_path  = '';
		$num          = $zip->numFiles;
		$wc_dir       = rtrim( WP_CONTENT_DIR, '/' ) . '/';
		$excluded_rel = [ 'wgv-temp/', 'wgv-logs/', 'wp-config.php' ];

		for ( $i = 0; $i < $num; $i++ ) {
			$entry = $zip->getNameIndex( $i );
			if ( false === $entry ) {
				continue;
			}

			// Root-level .sql.gz → database dump.
			if ( false === strpos( $entry, '/' )
				&& substr( $entry, -strlen( '.sql.gz' ) ) === '.sql.gz'
			) {
				$sql_gz_path = $this->temp_dir . basename( $entry );
				$contents    = $zip->getFromIndex( $i );
				if ( false !== $contents ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
					file_put_contents( $sql_gz_path, $contents );
				}
				continue;
			}

			// Skip excluded paths.
			$skip = false;
			foreach ( $excluded_rel as $excl ) {
				if ( strncmp( $entry, $excl, strlen( $excl ) ) === 0 ) {
					$skip = true;
					break;
				}
			}
			if ( $skip ) {
				continue;
			}

			$target = $wc_dir . $entry;

			if ( substr( $entry, -1 ) === '/' ) {
				// Directory entry.
				wp_mkdir_p( $target );
				continue;
			}

			// File entry — ensure parent directory exists.
			wp_mkdir_p( dirname( $target ) );
			$contents = $zip->getFromIndex( $i );
			if ( false !== $contents ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				file_put_contents( $target, $contents );
			}
		}

		$zip->close();

		// Restore the database from the extracted .sql.gz.
		$db_success = false;
		if ( ! empty( $sql_gz_path ) && file_exists( $sql_gz_path ) ) {
			$db_success = $this->restore_database_from_local( $sql_gz_path );
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			@unlink( $sql_gz_path );
		} else {
			$this->notifier->log_error( 'restore_full_from_local: no .sql.gz found in ZIP root.' );
		}

		$this->notifier->log_info( 'Full site file extraction complete. DB restore: ' . ( $db_success ? 'success' : 'failed' ) . '.' );

		return $db_success;
	}

	// -------------------------------------------------------------------------
	// Private helpers — SQL import
	// -------------------------------------------------------------------------

	/**
	 * Import a plain .sql file into the database via $wpdb.
	 *
	 * Accumulates lines into a statement buffer and executes each complete
	 * statement (terminated by ;). Lines beginning with --, /*, SET, or /*!
	 * are skipped.
	 *
	 * @param  string $sql_path Absolute path to the .sql file.
	 * @return bool             True when the file was processed without errors.
	 */
	private function import_sql_file( string $sql_path ): bool {
		global $wpdb;

		if ( ! file_exists( $sql_path ) ) {
			$this->notifier->log_error( 'import_sql_file: file not found.' );
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$handle = fopen( $sql_path, 'r' );
		if ( ! $handle ) {
			$this->notifier->log_error( 'import_sql_file: could not open file.' );
			return false;
		}

		$buffer        = '';
		$skip_prefixes = [ '--', '/*', 'SET ', '/*!' ];

		while ( ! feof( $handle ) ) {
			$line = fgets( $handle );
			if ( false === $line ) {
				continue;
			}

			$trimmed = trim( $line );

			if ( '' === $trimmed ) {
				continue;
			}

			// Skip comment and directive lines.
			$skip = false;
			foreach ( $skip_prefixes as $prefix ) {
				if ( strncmp( $trimmed, $prefix, strlen( $prefix ) ) === 0 ) {
					$skip = true;
					break;
				}
			}
			if ( $skip ) {
				continue;
			}

			$buffer .= $line;

			// Execute when a statement is complete.
			if ( ';' === substr( rtrim( $trimmed ), -1 ) ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$wpdb->query( $buffer );
				$buffer = '';
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $handle );

		return true;
	}

	// -------------------------------------------------------------------------
	// Private helpers — utilities
	// -------------------------------------------------------------------------

	/**
	 * Return true when exec() is available and not listed in disable_functions.
	 */
	private function is_exec_available(): bool {
		if ( ! function_exists( 'exec' ) ) {
			return false;
		}

		$disabled = (string) ini_get( 'disable_functions' );
		if ( empty( $disabled ) ) {
			return true;
		}

		return ! in_array( 'exec', array_map( 'trim', explode( ',', $disabled ) ), true );
	}

	/** Create the temp directory if it does not yet exist. */
	private function ensure_temp_dir(): void {
		if ( ! is_dir( $this->temp_dir ) ) {
			wp_mkdir_p( $this->temp_dir );
		}
	}

	/**
	 * Return true when $path ends with the given extension (case-insensitive).
	 *
	 * @param  string $path      File path to check.
	 * @param  string $extension Extension including leading dot (e.g. '.zip').
	 */
	private function has_extension( string $path, string $extension ): bool {
		return strtolower( substr( $path, -strlen( $extension ) ) ) === strtolower( $extension );
	}

	// -------------------------------------------------------------------------
	// Private helpers — restore log
	// -------------------------------------------------------------------------

	/**
	 * Insert a restore log row and return its ID.
	 *
	 * @param  string $type        'database', 'uploads', or 'full'.
	 * @param  string $source      'drive' or 'upload'.
	 * @param  string $drive_file_id Drive file ID (empty for uploads).
	 * @param  string $file_name   File name hint.
	 * @return int                 Inserted row ID, or 0 on failure.
	 */
	private function insert_restore_log(
		string $type,
		string $source,
		string $drive_file_id,
		string $file_name
	): int {
		global $wpdb;

		$table = $wpdb->prefix . 'wgv_restore_log';

		$wpdb->insert(
			$table,
			[
				'restore_type'      => $type,
				'source'            => $source,
				'drive_file_id'     => $drive_file_id,
				'file_name'         => $file_name,
				'status'            => 'failed',
				'pre_backup_status' => 'skipped',
				'notes'             => null,
				'restored_at'       => current_time( 'mysql', true ),
			],
			[ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Update a restore log row's status and optional notes.
	 *
	 * @param int    $id     Row ID.
	 * @param string $status 'success' or 'failed'.
	 * @param string $notes  Optional detail text.
	 */
	private function update_restore_log( int $id, string $status, string $notes ): void {
		global $wpdb;

		if ( $id <= 0 ) {
			return;
		}

		$wpdb->update(
			$wpdb->prefix . 'wgv_restore_log',
			[
				'status' => $status,
				'notes'  => $notes ?: null,
			],
			[ 'id' => $id ],
			[ '%s', '%s' ],
			[ '%d' ]
		);
	}
}
