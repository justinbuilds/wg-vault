<?php
/**
 * Handles all communication with the Google Drive API.
 *
 * WGV_Drive manages OAuth 2.0 authentication (using stored tokens from
 * wgv_settings), uploads backup archives to a dedicated Google Drive
 * folder, and returns the remote file ID on success.
 *
 * All HTTP calls use wp_remote_*() — no Composer or SDK required.
 */

defined( 'ABSPATH' ) || exit;

class WGV_Drive {

	/** Google Drive API base URL. */
	const API_BASE = 'https://www.googleapis.com';

	/** Google OAuth 2.0 token endpoint. */
	const TOKEN_URL = 'https://oauth2.googleapis.com/token';

	/**
	 * OAuth 2.0 access token retrieved from wgv_settings.
	 */
	private string $access_token = '';

	public function __construct() {
		// Access token and credentials will be loaded from WGV_Settings.
	}

	/**
	 * Upload a local file to Google Drive.
	 *
	 * @param  string $file_path Absolute path to the archive to upload.
	 * @return string            Google Drive file ID of the uploaded file.
	 * @throws RuntimeException  On API or auth failure.
	 */
	public function upload( string $file_path ): string {
		// Full implementation:
		// 1. ensure_valid_token()    — refresh OAuth token if expired
		// 2. ensure_folder_exists()  — create/locate WG Vault Drive folder
		// 3. multipart_upload()      — POST file to Drive API
		// 4. Return file ID from response.

		throw new \RuntimeException( 'WGV_Drive::upload() is not yet implemented.' );
	}

	/**
	 * Refresh the access token using the stored refresh token.
	 */
	private function refresh_token(): void {
		// Will POST to TOKEN_URL with client_id, client_secret, refresh_token.
	}

	/**
	 * Return the Drive folder ID for WG Vault, creating it if absent.
	 */
	private function ensure_folder_exists(): string {
		// Will query Drive API for a folder named 'WG Vault' and create
		// one if it does not exist.
		return '';
	}
}
