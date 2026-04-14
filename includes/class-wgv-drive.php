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

	/** Google OAuth 2.0 authorization endpoint. */
	const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

	private WGV_Settings  $settings;
	private WGV_Notifier  $notifier;

	public function __construct( WGV_Settings $settings, WGV_Notifier $notifier ) {
		$this->settings  = $settings;
		$this->notifier  = $notifier;
		$this->maybe_show_auth_key_notice();
	}

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Build and return the Google OAuth2 authorization URL.
	 *
	 * Returns an empty string when client_id or client_secret are not set.
	 */
	public function get_auth_url(): string {
		$client_id     = $this->settings->get( 'drive_client_id', '' );
		$client_secret = $this->settings->get( 'drive_client_secret', '' );

		if ( empty( $client_id ) || empty( $client_secret ) ) {
			return '';
		}

		return self::AUTH_URL . '?' . http_build_query(
			[
				'client_id'     => $client_id,
			'redirect_uri'  => admin_url( 'admin-post.php?action=wgv_oauth_callback' ),
			'response_type' => 'code',
				'scope'         => 'https://www.googleapis.com/auth/drive.file',
				'access_type'   => 'offline',
				'prompt'        => 'consent',
			]
		);
	}

	/**
	 * Exchange an authorization code for access and refresh tokens.
	 *
	 * Stores the full token JSON (plus a created timestamp) as an encrypted
	 * string in drive_access_token, and stores the refresh token separately
	 * in drive_refresh_token.
	 *
	 * @param  string $code Authorization code returned by Google.
	 * @return bool         True on success, false on failure.
	 */
	public function exchange_code_for_tokens( string $code ): bool {
		$response = wp_remote_post(
			self::TOKEN_URL,
			[
				'timeout' => 30,
				'body'    => [
					'code'          => $code,
					'client_id'     => $this->settings->get( 'drive_client_id', '' ),
					'client_secret' => $this->settings->get( 'drive_client_secret', '' ),
				'redirect_uri'  => admin_url( 'admin-post.php?action=wgv_oauth_callback' ),
				'grant_type'    => 'authorization_code',
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			$this->notifier->log_error( 'Token exchange request failed: ' . $response->get_error_message() );
			return false;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $body['access_token'] ) ) {
			$this->notifier->log_error( 'Token exchange: missing access_token in response.' );
			return false;
		}

		$body['created'] = time();

		$this->settings->set(
			'drive_access_token',
			$this->encrypt( wp_json_encode( $body ) )
		);

		if ( ! empty( $body['refresh_token'] ) ) {
			$this->settings->set(
				'drive_refresh_token',
				$this->encrypt( $body['refresh_token'] )
			);
		}

		return true;
	}

	/**
	 * Refresh the stored access token using the refresh token.
	 *
	 * @return bool True on success, false on failure.
	 */
	public function refresh_access_token(): bool {
		$refresh_raw = $this->settings->get( 'drive_refresh_token', '' );

		if ( empty( $refresh_raw ) ) {
			return false;
		}

		$refresh_token = $this->decrypt( $refresh_raw );

		$response = wp_remote_post(
			self::TOKEN_URL,
			[
				'timeout' => 30,
				'body'    => [
					'refresh_token' => $refresh_token,
					'client_id'     => $this->settings->get( 'drive_client_id', '' ),
					'client_secret' => $this->settings->get( 'drive_client_secret', '' ),
					'grant_type'    => 'refresh_token',
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			$this->notifier->log_error( 'Token refresh request failed: ' . $response->get_error_message() );
			return false;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $body['access_token'] ) ) {
			$this->notifier->log_error( 'Token refresh: missing access_token in response.' );
			return false;
		}

		$body['created'] = time();

		// Google only returns a new refresh_token in rare cases; preserve the
		// existing one when the response omits it.
		if ( empty( $body['refresh_token'] ) ) {
			$body['refresh_token'] = $refresh_token;
		}

		$this->settings->set(
			'drive_access_token',
			$this->encrypt( wp_json_encode( $body ) )
		);

		return true;
	}

	/**
	 * Return the current access token string, refreshing it when expired.
	 *
	 * @return string Access token, or empty string when unavailable.
	 */
	public function get_access_token(): string {
		$raw = $this->settings->get( 'drive_access_token', '' );

		if ( empty( $raw ) ) {
			return '';
		}

		$data = json_decode( $this->decrypt( $raw ), true );

		if ( ! is_array( $data ) || empty( $data['access_token'] ) ) {
			return '';
		}

		$created    = isset( $data['created'] ) ? (int) $data['created'] : 0;
		$expires_in = isset( $data['expires_in'] ) ? (int) $data['expires_in'] : 3600;

		// Refresh 60 seconds before actual expiry to avoid edge-case failures.
		if ( time() >= $created + $expires_in - 60 ) {
			if ( ! $this->refresh_access_token() ) {
				return '';
			}

			$raw  = $this->settings->get( 'drive_access_token', '' );
			$data = json_decode( $this->decrypt( $raw ), true );
		}

		return $data['access_token'] ?? '';
	}

	/**
	 * Return the Drive folder ID for the given name, creating it if needed.
	 *
	 * Caches the folder ID in drive_folder_id after the first lookup.
	 *
	 * @param  string $folder_name Human-readable folder name.
	 * @return string              Folder ID string, or empty string on failure.
	 */
	public function get_or_create_folder( string $folder_name ): string {
		$token = $this->get_access_token();

		if ( empty( $token ) ) {
			return '';
		}

		// Search for an existing folder with this name.
		$query    = sprintf(
			"name='%s' and mimeType='application/vnd.google-apps.folder' and trashed=false",
			addslashes( $folder_name )
		);
		$response = wp_remote_get(
			self::API_BASE . '/drive/v3/files?' . http_build_query(
				[
					'q'      => $query,
					'fields' => 'files(id,name)',
				]
			),
			[
				'headers' => [ 'Authorization' => 'Bearer ' . $token ],
				'timeout' => 30,
			]
		);

		if ( ! is_wp_error( $response ) ) {
			$body = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( ! empty( $body['files'][0]['id'] ) ) {
				$folder_id = $body['files'][0]['id'];
				$this->settings->set( 'drive_folder_id', $folder_id );
				return $folder_id;
			}
		}

		// Folder not found — create it.
		$response = wp_remote_post(
			self::API_BASE . '/drive/v3/files',
			[
				'headers' => [
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				],
				'body'    => wp_json_encode(
					[
						'name'     => $folder_name,
						'mimeType' => 'application/vnd.google-apps.folder',
					]
				),
				'timeout' => 30,
			]
		);

		if ( is_wp_error( $response ) ) {
			$this->notifier->log_error( 'Drive folder creation failed: ' . $response->get_error_message() );
			return '';
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $body['id'] ) ) {
			$this->notifier->log_error( 'Drive folder creation: no ID in response.' );
			return '';
		}

		$this->settings->set( 'drive_folder_id', $body['id'] );
		return $body['id'];
	}

	/**
	 * Upload a local file to Google Drive via multipart upload.
	 *
	 * @param  string $local_path Absolute path to the file to upload.
	 * @param  string $file_name  File name to use on Google Drive.
	 * @param  string $folder_id  ID of the destination Drive folder.
	 * @return string             Drive file ID on success, empty string on failure.
	 */
	public function upload_file( string $local_path, string $file_name, string $folder_id ): string {
		$token = $this->get_access_token();

		if ( empty( $token ) ) {
			return '';
		}

		if ( ! is_readable( $local_path ) ) {
			$this->notifier->log_error( 'upload_file: file not readable.' );
			return '';
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$file_contents = file_get_contents( $local_path );

		if ( $file_contents === false ) {
			$this->notifier->log_error( 'upload_file: could not read file contents.' );
			return '';
		}

		$mime_type = mime_content_type( $local_path );
		if ( ! $mime_type ) {
			$mime_type = 'application/octet-stream';
		}

		$boundary = '---WGVBoundary' . wp_generate_password( 20, false );
		$metadata = wp_json_encode(
			[
				'name'    => $file_name,
				'parents' => [ $folder_id ],
			]
		);

		$body  = "--{$boundary}\r\n";
		$body .= "Content-Type: application/json; charset=UTF-8\r\n\r\n";
		$body .= $metadata . "\r\n";
		$body .= "--{$boundary}\r\n";
		$body .= "Content-Type: {$mime_type}\r\n\r\n";
		$body .= $file_contents . "\r\n";
		$body .= "--{$boundary}--";

		$response = wp_remote_post(
			self::API_BASE . '/upload/drive/v3/files?uploadType=multipart',
			[
				'headers' => [
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => "multipart/related; boundary={$boundary}",
				],
				'body'    => $body,
				'timeout' => 120,
			]
		);

		if ( is_wp_error( $response ) ) {
			$this->notifier->log_error( 'Drive upload failed: ' . $response->get_error_message() );
			return '';
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $data['id'] ) ) {
			$this->notifier->log_error( 'Drive upload: no file ID in response.' );
			return '';
		}

		return $data['id'];
	}

	/**
	 * Delete a file from Google Drive by its file ID.
	 *
	 * @param  string $drive_file_id Google Drive file ID to delete.
	 * @return bool                  True on success (HTTP 204), false on failure.
	 */
	public function delete_file( string $drive_file_id ): bool {
		$token = $this->get_access_token();

		if ( empty( $token ) ) {
			return false;
		}

		$response = wp_remote_request(
			self::API_BASE . '/drive/v3/files/' . rawurlencode( $drive_file_id ),
			[
				'method'  => 'DELETE',
				'headers' => [ 'Authorization' => 'Bearer ' . $token ],
				'timeout' => 30,
			]
		);

		if ( is_wp_error( $response ) ) {
			$this->notifier->log_error( 'Drive delete failed: ' . $response->get_error_message() );
			return false;
		}

		return 204 === (int) wp_remote_retrieve_response_code( $response );
	}

	/**
	 * Return true when a refresh token is stored (i.e. the user has connected).
	 */
	public function is_connected(): bool {
		return ! empty( $this->settings->get( 'drive_refresh_token', '' ) );
	}

	/**
	 * Return the Google account email for the connected user.
	 *
	 * The result is cached in drive_account_email after the first successful
	 * API call. Returns an empty string on failure.
	 */
	public function get_connected_email(): string {
		$cached = $this->settings->get( 'drive_account_email', '' );

		if ( ! empty( $cached ) ) {
			return $cached;
		}

		$token = $this->get_access_token();

		if ( empty( $token ) ) {
			return '';
		}

		$response = wp_remote_get(
			self::API_BASE . '/oauth2/v2/userinfo',
			[
				'headers' => [ 'Authorization' => 'Bearer ' . $token ],
				'timeout' => 15,
			]
		);

		if ( is_wp_error( $response ) ) {
			return '';
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $body['email'] ) ) {
			return '';
		}

		$email = sanitize_email( $body['email'] );
		$this->settings->set( 'drive_account_email', $email );

		return $email;
	}

	/**
	 * Handle the OAuth 2.0 callback from Google.
	 *
	 * Registered on admin_post_wgv_oauth_callback. Exchanges the returned
	 * authorization code for tokens and redirects back to the Google Drive tab.
	 */
	public function handle_oauth_callback(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'wg-vault' ) );
		}

		$drive_tab_url = add_query_arg(
			[ 'page' => 'wg-vault', 'tab' => 'google-drive' ],
			admin_url( 'admin.php' )
		);

		if ( empty( $_GET['code'] ) ) {
			wp_safe_redirect( add_query_arg( 'wgv_error', 'oauth_failed', $drive_tab_url ) );
			exit;
		}

		$code = sanitize_text_field( wp_unslash( $_GET['code'] ) );

		if ( $this->exchange_code_for_tokens( $code ) ) {
			wp_safe_redirect( add_query_arg( 'wgv_connected', '1', $drive_tab_url ) );
		} else {
			wp_safe_redirect( add_query_arg( 'wgv_error', 'oauth_failed', $drive_tab_url ) );
		}

		exit;
	}

	// -------------------------------------------------------------------------
	// Encryption helpers
	// -------------------------------------------------------------------------

	/**
	 * Return true when strong encryption is available and AUTH_KEY is usable.
	 */
	private function can_encrypt(): bool {
		return function_exists( 'openssl_encrypt' )
			&& defined( 'AUTH_KEY' )
			&& AUTH_KEY !== 'put your unique phrase here'
			&& strlen( AUTH_KEY ) >= 32
			&& defined( 'AUTH_SALT' )
			&& strlen( AUTH_SALT ) >= 16;
	}

	/**
	 * Encrypt a string value for safe storage in wp_options.
	 *
	 * Uses AES-256-CBC when OpenSSL and a strong AUTH_KEY are available,
	 * otherwise falls back to base64 encoding.
	 *
	 * @param  string $value Plaintext value to encrypt.
	 * @return string        Encrypted (or base64-encoded) string.
	 */
	private function encrypt( string $value ): string {
		if ( ! $this->can_encrypt() ) {
			return base64_encode( $value ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}

		$iv        = substr( AUTH_SALT, 0, 16 );
		$encrypted = openssl_encrypt( $value, 'AES-256-CBC', AUTH_KEY, 0, $iv );

		return $encrypted !== false ? $encrypted : base64_encode( $value ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Decrypt a previously encrypted value.
	 *
	 * Falls back to base64 decoding when encryption was not available at
	 * write time, or when the decryption itself fails.
	 *
	 * @param  string $value Encrypted (or base64-encoded) string.
	 * @return string        Plaintext value.
	 */
	private function decrypt( string $value ): string {
		if ( ! $this->can_encrypt() ) {
			return (string) base64_decode( $value ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		}

		$iv        = substr( AUTH_SALT, 0, 16 );
		$decrypted = openssl_decrypt( $value, 'AES-256-CBC', AUTH_KEY, 0, $iv );

		if ( $decrypted !== false ) {
			return $decrypted;
		}

		// The value may have been stored before encryption was enabled.
		return (string) base64_decode( $value ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
	}

	// -------------------------------------------------------------------------
	// Admin notices
	// -------------------------------------------------------------------------

	/**
	 * Register an admin notice when AUTH_KEY is missing or uses the default.
	 */
	private function maybe_show_auth_key_notice(): void {
		if ( ! is_admin() ) {
			return;
		}

		if ( $this->is_auth_key_weak() ) {
			add_action( 'admin_notices', [ $this, 'render_auth_key_notice' ] );
		}
	}

	/**
	 * Return true when AUTH_KEY is absent, is the placeholder, or is too short.
	 */
	private function is_auth_key_weak(): bool {
		return ! defined( 'AUTH_KEY' )
			|| AUTH_KEY === 'put your unique phrase here'
			|| strlen( AUTH_KEY ) < 32;
	}

	/**
	 * Render the weak-AUTH_KEY admin notice.
	 */
	public function render_auth_key_notice(): void {
		echo '<div class="notice notice-warning is-dismissible"><p>'
			. esc_html__(
				'WG Vault: AUTH_KEY is missing or uses the default placeholder value. Google Drive credentials are stored with reduced security. Please define a strong AUTH_KEY in wp-config.php.',
				'wg-vault'
			)
			. '</p></div>';
	}
}
