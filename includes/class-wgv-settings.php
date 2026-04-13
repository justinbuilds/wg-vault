<?php
/**
 * Manages reading and writing plugin settings stored in wp_options.
 *
 * WGV_Settings provides a centralised API for all other classes to
 * retrieve or update values within the single 'wgv_settings' option key.
 * It also registers the Settings API sections and fields used on the
 * admin page.
 */

defined( 'ABSPATH' ) || exit;

class WGV_Settings {

	/** The single wp_options key that stores all plugin settings. */
	const OPTION_KEY = 'wgv_settings';

	/**
	 * Cached copy of the settings array for the current request.
	 */
	private array $settings = [];

	public function __construct() {
		$stored = get_option( self::OPTION_KEY, [] );
		$this->settings = is_array( $stored ) ? $stored : [];
	}

	/**
	 * Register WordPress Settings API hooks.
	 * Called by WGV_Loader::boot_components().
	 */
	public function register(): void {
		// Settings API registration and sanitisation callbacks will be
		// implemented here in a future iteration.
	}

	/**
	 * Return a single setting value, falling back to $default if absent.
	 */
	public function get( string $key, mixed $default = null ): mixed {
		return $this->settings[ $key ] ?? $default;
	}

	/**
	 * Persist an updated value for a single setting key.
	 */
	public function set( string $key, mixed $value ): void {
		$this->settings[ $key ] = $value;
		update_option( self::OPTION_KEY, $this->settings, 'no' );
	}

	/**
	 * Return the full settings array.
	 */
	public function all(): array {
		return $this->settings;
	}

	/**
	 * Sanitize and merge a partial settings array, then persist to wp_options.
	 *
	 * Each recognised key is run through an appropriate sanitiser. Unknown keys
	 * are ignored. Booleans should be passed as native bool values (already
	 * resolved from isset() checks in the calling code).
	 *
	 * @param array $data Key/value pairs to merge into the stored settings.
	 */
	public function save( array $data ): void {
		$allowed_backup_types = [ 'database', 'uploads', 'full' ];
		$allowed_frequencies  = [ 'hourly', 'every_2_hours', 'every_4_hours', 'every_6_hours', 'every_12_hours', 'daily' ];

		$sanitizers = [
			'backup_type'         => static function ( $v ) use ( $allowed_backup_types ): string {
				$v = sanitize_text_field( $v );
				return in_array( $v, $allowed_backup_types, true ) ? $v : 'database';
			},
			'frequency'           => static function ( $v ) use ( $allowed_frequencies ): string {
				$v = sanitize_text_field( $v );
				return in_array( $v, $allowed_frequencies, true ) ? $v : 'daily';
			},
			'alert_email'         => 'sanitize_email',
			'retention_count'     => static function ( $v ): int {
				return max( 1, min( 365, absint( $v ) ) );
			},
			'retention_enabled'   => 'boolval',
			'log_enabled'         => 'boolval',
			'drive_client_id'     => 'sanitize_text_field',
			'drive_client_secret' => 'sanitize_text_field',
			'drive_folder_name'   => 'sanitize_text_field',
			'drive_account_email' => 'sanitize_email',
		];

		foreach ( $data as $key => $value ) {
			if ( isset( $sanitizers[ $key ] ) ) {
				$this->settings[ $key ] = ( $sanitizers[ $key ] )( $value );
			}
		}

		update_option( self::OPTION_KEY, $this->settings, 'no' );
	}
}
