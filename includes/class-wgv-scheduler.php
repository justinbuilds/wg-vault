<?php
/**
 * Manages wp-cron scheduling for automated backups.
 *
 * WGV_Scheduler registers custom cron intervals, schedules/unschedules the
 * recurring backup event, and delegates to WGV_Backup when the event fires.
 */

defined( 'ABSPATH' ) || exit;

class WGV_Scheduler {

	/** The cron hook name used for scheduled backup events. */
	const CRON_HOOK = 'wgv_scheduled_backup';

	/** Maps settings frequency values to WP-Cron interval names. */
	private const FREQUENCY_MAP = [
		'hourly'         => 'wgv_hourly',
		'every_2_hours'  => 'wgv_every_2_hours',
		'every_4_hours'  => 'wgv_every_4_hours',
		'every_6_hours'  => 'wgv_every_6_hours',
		'every_12_hours' => 'wgv_every_12_hours',
		'daily'          => 'daily',
	];

	private WGV_Settings $settings;
	private WGV_Backup   $backup;
	private WGV_Notifier $notifier;

	public function __construct(
		WGV_Settings $settings,
		WGV_Backup $backup,
		WGV_Notifier $notifier
	) {
		$this->settings = $settings;
		$this->backup   = $backup;
		$this->notifier = $notifier;
	}

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Register cron intervals, and the cron event action hook.
	 * Called by WGV_Loader::boot_components().
	 */
	public function register(): void {
		add_filter( 'cron_schedules', [ $this, 'add_cron_intervals' ] );
		add_action( self::CRON_HOOK, [ $this, 'handle_cron_event' ] );
	}

	/**
	 * Add custom cron interval definitions to WordPress.
	 *
	 * @param  array $schedules Existing registered schedules.
	 * @return array            Updated schedules array.
	 */
	public function add_cron_intervals( array $schedules ): array {
		$schedules['wgv_hourly'] = [
			'interval' => 3600,
			'display'  => __( 'Every Hour', 'wg-vault' ),
		];
		$schedules['wgv_every_2_hours'] = [
			'interval' => 7200,
			'display'  => __( 'Every 2 Hours', 'wg-vault' ),
		];
		$schedules['wgv_every_4_hours'] = [
			'interval' => 14400,
			'display'  => __( 'Every 4 Hours', 'wg-vault' ),
		];
		$schedules['wgv_every_6_hours'] = [
			'interval' => 21600,
			'display'  => __( 'Every 6 Hours', 'wg-vault' ),
		];
		$schedules['wgv_every_12_hours'] = [
			'interval' => 43200,
			'display'  => __( 'Every 12 Hours', 'wg-vault' ),
		];

		return $schedules;
	}

	/**
	 * Schedule (or reschedule) the backup cron event with the given frequency.
	 *
	 * Clears any existing event before scheduling the new one so that
	 * interval changes take effect immediately.
	 *
	 * @param string $frequency One of the keys defined in FREQUENCY_MAP.
	 */
	public function schedule( string $frequency ): void {
		$interval = self::FREQUENCY_MAP[ $frequency ] ?? 'daily';
		$this->unschedule();
		wp_schedule_event( time(), $interval, self::CRON_HOOK );
	}

	/**
	 * Remove any existing scheduled backup cron event.
	 */
	public function unschedule(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Return a human-readable string for when the next backup is due.
	 *
	 * @return string Formatted date/time, or 'Not scheduled'.
	 */
	public function get_next_run(): string {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );

		if ( ! $timestamp ) {
			return __( 'Not scheduled', 'wg-vault' );
		}

		return date_i18n( 'F j, Y g:i a', $timestamp );
	}

	/**
	 * Callback fired by WP-Cron on the wgv_scheduled_backup hook.
	 *
	 * Reads the configured backup type and delegates to the appropriate
	 * WGV_Backup method. Errors are logged via the notifier.
	 */
	public function handle_cron_event(): void {
		$backup_type = $this->settings->get( 'backup_type', 'database' );

		try {
			match ( $backup_type ) {
				'uploads' => $this->backup->run_uploads_backup(),
				'full'    => $this->backup->run_full_backup(),
				default   => $this->backup->run_database_backup(),
			};
		} catch ( \Exception $e ) {
			$this->notifier->log_error( 'Scheduled backup failed: ' . $e->getMessage() );
		}
	}
}
