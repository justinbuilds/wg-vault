<?php
/**
 * Manages wp-cron scheduling for automated backups.
 *
 * WGV_Scheduler registers or re-registers the recurring cron event
 * based on the schedule stored in wgv_settings, and hooks the backup
 * callback to the wgv_scheduled_backup action.
 */

defined( 'ABSPATH' ) || exit;

class WGV_Scheduler {

	/** The cron hook name used for scheduled backup events. */
	const CRON_HOOK = 'wgv_scheduled_backup';

	public function __construct() {
		// Dependencies (WGV_Settings, WGV_Backup) will be injected here.
	}

	/**
	 * Register cron and action hooks.
	 * Called by WGV_Loader::boot_components().
	 */
	public function register(): void {
		add_action( self::CRON_HOOK, [ $this, 'run_backup' ] );
		$this->maybe_schedule();
	}

	/**
	 * Schedule the recurring event if it is not already registered.
	 */
	public function maybe_schedule(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			// Default to daily; full schedule management implemented later.
			wp_schedule_event( time(), 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Callback fired by wp-cron to trigger a backup run.
	 */
	public function run_backup(): void {
		// Will delegate to WGV_Backup::run() once implemented.
	}
}
