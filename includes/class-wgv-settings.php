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
}
