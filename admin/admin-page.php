<?php
/**
 * Registers and renders the WG Vault admin page.
 *
 * Provides a tabbed admin interface for managing backup settings,
 * Google Drive credentials, retention policy, and backup history.
 */

defined( 'ABSPATH' ) || exit;

// ---------------------------------------------------------------------------
// Hook registrations
// ---------------------------------------------------------------------------

add_action( 'admin_menu',                      'wgv_register_admin_menu' );
add_action( 'admin_enqueue_scripts',           'wgv_enqueue_admin_assets' );
add_action( 'admin_init',                      'wgv_process_settings_form' );
add_action( 'admin_post_wgv_disconnect_drive', 'wgv_handle_disconnect_drive' );

// ---------------------------------------------------------------------------
// Menu & asset registration
// ---------------------------------------------------------------------------

/**
 * Register the WG Vault top-level admin menu item.
 */
function wgv_register_admin_menu(): void {
	add_menu_page(
		__( 'WG Vault', 'wg-vault' ),
		__( 'WG Vault', 'wg-vault' ),
		'manage_options',
		'wg-vault',
		'wgv_render_admin_page',
		'dashicons-cloud-upload',
		80
	);
}

/**
 * Enqueue the plugin admin stylesheet on WG Vault pages only.
 *
 * @param string $hook_suffix The current admin page hook.
 */
function wgv_enqueue_admin_assets( string $hook_suffix ): void {
	if ( 'toplevel_page_wg-vault' !== $hook_suffix ) {
		return;
	}

	wp_enqueue_style(
		'wgv-admin-styles',
		WGV_PLUGIN_URL . 'admin/admin-styles.css',
		[],
		WGV_VERSION
	);
}

// ---------------------------------------------------------------------------
// Form handlers
// ---------------------------------------------------------------------------

/**
 * Process settings form submissions, hooked into admin_init.
 *
 * Responds only when the wgv_action field is present. After saving,
 * redirects back to the same tab with a success query parameter.
 */
function wgv_process_settings_form(): void {
	if ( empty( $_POST['wgv_action'] ) || 'save_settings' !== $_POST['wgv_action'] ) {
		return;
	}

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to do this.', 'wg-vault' ) );
	}

	check_admin_referer( 'wgv_settings_save' );

	$settings = new WGV_Settings();
	$tab      = sanitize_key( $_POST['wgv_tab'] ?? 'general' );

	switch ( $tab ) {
		case 'general':
			$old_frequency = $settings->get( 'frequency', 'daily' );

			$settings->save( [
				'backup_type' => sanitize_text_field( $_POST['backup_type'] ?? '' ),
				'frequency'   => sanitize_text_field( $_POST['frequency'] ?? '' ),
				'alert_email' => sanitize_email( $_POST['alert_email'] ?? '' ),
				'log_enabled' => isset( $_POST['log_enabled'] ),
			] );

			$new_frequency = $settings->get( 'frequency', 'daily' );

			if ( $new_frequency !== $old_frequency ) {
				do_action( 'wgv_frequency_changed', $new_frequency );
			}
			break;

		case 'google-drive':
			$data = [
				'drive_client_id'   => sanitize_text_field( $_POST['drive_client_id'] ?? '' ),
				'drive_folder_name' => sanitize_text_field( $_POST['drive_folder_name'] ?? '' ),
			];
			// Only overwrite the secret when a new value is explicitly submitted.
			if ( ! empty( $_POST['drive_client_secret'] ) ) {
				$data['drive_client_secret'] = sanitize_text_field( $_POST['drive_client_secret'] );
			}
			$settings->save( $data );
			break;

		case 'retention':
			$settings->save( [
				'retention_enabled' => isset( $_POST['retention_enabled'] ),
				'retention_count'   => absint( $_POST['retention_count'] ?? 7 ),
			] );
			break;
	}

	wp_safe_redirect(
		add_query_arg(
			[ 'page' => 'wg-vault', 'tab' => $tab, 'wgv_saved' => '1' ],
			admin_url( 'admin.php' )
		)
	);
	exit;
}

/**
 * Handle a manual backup request submitted via admin-post.php.
 */
function wgv_handle_manual_backup(): void {
	check_admin_referer( 'wgv_manual_backup' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to do this.', 'wg-vault' ) );
	}

	$allowed     = [ 'database', 'uploads', 'full' ];
	$backup_type = sanitize_text_field( $_POST['backup_type'] ?? 'database' );
	if ( ! in_array( $backup_type, $allowed, true ) ) {
		$backup_type = 'database';
	}

	/**
	 * Fires when a manual backup is requested from the admin UI.
	 *
	 * @param string $backup_type One of 'database', 'uploads', or 'full'.
	 */
	do_action( 'wgv_run_manual_backup', $backup_type );

	wp_safe_redirect(
		add_query_arg(
			[ 'page' => 'wg-vault', 'tab' => 'backup-log', 'wgv_backup_triggered' => '1' ],
			admin_url( 'admin.php' )
		)
	);
	exit;
}

/**
 * Handle a Google Drive disconnect request submitted via admin-post.php.
 *
 * Clears all stored OAuth tokens and the cached account email, then
 * redirects back to the Google Drive tab.
 */
function wgv_handle_disconnect_drive(): void {
	check_admin_referer( 'wgv_disconnect_drive' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to do this.', 'wg-vault' ) );
	}

	$settings = new WGV_Settings();
	$settings->set( 'drive_access_token',  '' );
	$settings->set( 'drive_refresh_token', '' );
	$settings->set( 'drive_account_email', '' );
	$settings->set( 'drive_folder_id',     '' );

	wp_safe_redirect(
		add_query_arg(
			[ 'page' => 'wg-vault', 'tab' => 'google-drive', 'wgv_disconnected' => '1' ],
			admin_url( 'admin.php' )
		)
	);
	exit;
}

// ---------------------------------------------------------------------------
// Page render
// ---------------------------------------------------------------------------

/**
 * Render the main WG Vault admin page.
 */
function wgv_render_admin_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to access this page.', 'wg-vault' ) );
	}

	$settings    = new WGV_Settings();
	$current_tab = wgv_get_current_tab();

	$tabs = [
		'general'      => __( 'General', 'wg-vault' ),
		'google-drive' => __( 'Google Drive', 'wg-vault' ),
		'retention'    => __( 'Retention', 'wg-vault' ),
		'backup-log'   => __( 'Backup Log', 'wg-vault' ),
	];
	?>
	<div class="wrap wgv-wrap">
		<h1 class="wgv-page-title">
			<span class="dashicons dashicons-cloud-upload"></span>
			<?php esc_html_e( 'WG Vault', 'wg-vault' ); ?>
		</h1>

		<?php wgv_render_admin_notices(); ?>

		<nav class="wgv-tab-nav" aria-label="<?php esc_attr_e( 'Settings tabs', 'wg-vault' ); ?>">
			<?php foreach ( $tabs as $slug => $label ) : ?>
				<a href="<?php echo esc_url( wgv_get_tab_url( $slug ) ); ?>"
				   class="wgv-tab-link<?php echo $slug === $current_tab ? ' wgv-tab-link--active' : ''; ?>"
				   <?php echo $slug === $current_tab ? 'aria-current="page"' : ''; ?>>
					<?php echo esc_html( $label ); ?>
				</a>
			<?php endforeach; ?>
		</nav>

		<div class="wgv-tab-content">
			<?php
			switch ( $current_tab ) {
				case 'general':
					wgv_render_tab_general( $settings );
					break;
				case 'google-drive':
					wgv_render_tab_google_drive( $settings );
					break;
				case 'retention':
					wgv_render_tab_retention( $settings );
					break;
				case 'backup-log':
					wgv_render_tab_backup_log();
					break;
			}
			?>
		</div>
	</div>
	<?php
}

// ---------------------------------------------------------------------------
// Admin notice rendering
// ---------------------------------------------------------------------------

/**
 * Render contextual notices based on query-string flags set after redirects.
 */
function wgv_render_admin_notices(): void {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended
	if ( ! empty( $_GET['wgv_saved'] ) ) {
		echo '<div class="wgv-notice wgv-notice--success">'
			. esc_html__( 'Settings saved.', 'wg-vault' )
			. '</div>';
	}

	if ( ! empty( $_GET['wgv_disconnected'] ) ) {
		echo '<div class="wgv-notice wgv-notice--info">'
			. esc_html__( 'Google Drive disconnected. Tokens have been cleared.', 'wg-vault' )
			. '</div>';
	}

	if ( ! empty( $_GET['wgv_backup_triggered'] ) ) {
		echo '<div class="wgv-notice wgv-notice--success">'
			. esc_html__( 'Manual backup has been triggered.', 'wg-vault' )
			. '</div>';
	}
	// phpcs:enable
}

// ---------------------------------------------------------------------------
// Tab renderers
// ---------------------------------------------------------------------------

/**
 * Render the General settings tab.
 *
 * @param WGV_Settings $settings The settings instance.
 */
function wgv_render_tab_general( WGV_Settings $settings ): void {
	$backup_type = $settings->get( 'backup_type', 'database' );
	$frequency   = $settings->get( 'frequency', 'daily' );
	$alert_email = $settings->get( 'alert_email', '' );
	$log_enabled = (bool) $settings->get( 'log_enabled', true );

	$next_backup = wp_next_scheduled( 'wgv_scheduled_backup' );
	if ( $next_backup ) {
		$next_backup_display = wp_date(
			get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
			$next_backup
		);
	} else {
		$next_backup_display = __( 'Not scheduled', 'wg-vault' );
	}

	$backup_types = [
		'database' => __( 'Database Only', 'wg-vault' ),
		'uploads'  => __( 'Database + Uploads', 'wg-vault' ),
		'full'     => __( 'Full Site', 'wg-vault' ),
	];

	$frequencies = [
		'hourly'        => __( 'Every Hour', 'wg-vault' ),
		'every_2_hours' => __( 'Every 2 Hours', 'wg-vault' ),
		'every_4_hours' => __( 'Every 4 Hours', 'wg-vault' ),
		'every_6_hours' => __( 'Every 6 Hours', 'wg-vault' ),
		'every_12_hours'=> __( 'Every 12 Hours', 'wg-vault' ),
		'daily'         => __( 'Daily', 'wg-vault' ),
	];
	?>

	<div class="notice notice-info is-dismissible">
		<p>
			<strong><?php esc_html_e( 'Tip: WP-Cron reliability', 'wg-vault' ); ?></strong> &mdash;
			<?php esc_html_e( 'For reliable scheduled backups, consider setting up a server-side cron job. Add this to your crontab:', 'wg-vault' ); ?>
			<br>
			<code>*/5 * * * * curl -s <?php echo esc_url( site_url( '/wp-cron.php?doing_wp_cron' ) ); ?></code>
			<br>
			<?php esc_html_e( 'or use:', 'wg-vault' ); ?>
			<code>wp cron event run --due-now --path=<?php echo esc_html( rtrim( ABSPATH, '/' ) ); ?></code>
		</p>
	</div>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
		<?php wp_nonce_field( 'wgv_settings_save' ); ?>
		<input type="hidden" name="wgv_action" value="save_settings">
		<input type="hidden" name="wgv_tab"    value="general">

		<div class="wgv-form-section">
			<div class="wgv-form-row">
				<label><?php esc_html_e( 'Backup Type', 'wg-vault' ); ?></label>
				<div class="wgv-radio-group">
					<?php foreach ( $backup_types as $value => $label ) : ?>
						<label class="wgv-radio-label">
							<input type="radio"
							       name="backup_type"
							       value="<?php echo esc_attr( $value ); ?>"
							       <?php checked( $backup_type, $value ); ?>>
							<?php echo esc_html( $label ); ?>
						</label>
					<?php endforeach; ?>
				</div>
			</div>

			<div class="wgv-form-row">
				<label for="wgv-frequency"><?php esc_html_e( 'Backup Frequency', 'wg-vault' ); ?></label>
				<select id="wgv-frequency" name="frequency">
					<?php foreach ( $frequencies as $value => $label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>"
							<?php selected( $frequency, $value ); ?>>
							<?php echo esc_html( $label ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>

			<div class="wgv-form-row">
				<label for="wgv-alert-email"><?php esc_html_e( 'Alert Email', 'wg-vault' ); ?></label>
				<input type="email"
				       id="wgv-alert-email"
				       name="alert_email"
				       value="<?php echo esc_attr( $alert_email ); ?>"
				       class="regular-text">
			</div>

			<div class="wgv-form-row">
				<label class="wgv-checkbox-label">
					<input type="checkbox"
					       name="log_enabled"
					       value="1"
					       <?php checked( $log_enabled ); ?>>
					<?php esc_html_e( 'Enable Log File', 'wg-vault' ); ?>
				</label>
			</div>

			<div class="wgv-form-row">
				<span class="wgv-form-label"><?php esc_html_e( 'Next Scheduled Backup', 'wg-vault' ); ?></span>
				<p class="wgv-field-value <?php echo $next_backup ? 'wgv-field-value--scheduled' : 'wgv-field-value--unscheduled'; ?>">
					<?php echo esc_html( $next_backup_display ); ?>
				</p>
			</div>
		</div>

		<div class="wgv-form-actions">
			<button type="submit" class="button button-primary wgv-button-save">
				<?php esc_html_e( 'Save Settings', 'wg-vault' ); ?>
			</button>
		</div>
	</form>
	<?php
}

/**
 * Render the Google Drive settings tab.
 *
 * @param WGV_Settings $settings The settings instance.
 */
function wgv_render_tab_google_drive( WGV_Settings $settings ): void {
	$client_id     = $settings->get( 'drive_client_id', '' );
	$client_secret = $settings->get( 'drive_client_secret', '' );
	$refresh_token = $settings->get( 'drive_refresh_token', '' );
	$account_email = $settings->get( 'drive_account_email', '' );
	$folder_name   = $settings->get( 'drive_folder_name', '' );
	$is_connected  = ! empty( $refresh_token );
	$can_connect   = ! empty( $client_id ) && ! empty( $client_secret );

	$redirect_uri = admin_url( 'admin.php?page=wg-vault&tab=google-drive' );
	$oauth_url    = 'https://accounts.google.com/o/oauth2/auth?' . http_build_query( [
		'client_id'     => $client_id,
		'redirect_uri'  => $redirect_uri,
		'response_type' => 'code',
		'scope'         => 'https://www.googleapis.com/auth/drive.file',
		'access_type'   => 'offline',
		'prompt'        => 'consent',
	] );
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
		<?php wp_nonce_field( 'wgv_settings_save' ); ?>
		<input type="hidden" name="wgv_action" value="save_settings">
		<input type="hidden" name="wgv_tab"    value="google-drive">

		<div class="wgv-form-section">
			<div class="wgv-form-row">
				<label for="wgv-drive-client-id"><?php esc_html_e( 'Google Client ID', 'wg-vault' ); ?></label>
				<input type="text"
				       id="wgv-drive-client-id"
				       name="drive_client_id"
				       value="<?php echo esc_attr( $client_id ); ?>"
				       class="regular-text">
			</div>

			<div class="wgv-form-row">
				<label for="wgv-drive-client-secret"><?php esc_html_e( 'Google Client Secret', 'wg-vault' ); ?></label>
				<input type="password"
				       id="wgv-drive-client-secret"
				       name="drive_client_secret"
				       autocomplete="new-password"
				       placeholder="<?php echo $client_secret ? esc_attr__( 'Leave blank to keep existing secret', 'wg-vault' ) : ''; ?>"
				       class="regular-text">
			</div>

			<div class="wgv-form-row">
				<span class="wgv-form-label"><?php esc_html_e( 'Connection Status', 'wg-vault' ); ?></span>
				<?php if ( $is_connected ) : ?>
					<p class="wgv-connection-status wgv-connection-status--connected">
						<span class="dashicons dashicons-yes-alt"></span>
						<?php
						esc_html_e( 'Connected', 'wg-vault' );
						if ( $account_email ) {
							echo ' &mdash; ' . esc_html( $account_email );
						}
						?>
					</p>
				<?php else : ?>
					<p class="wgv-connection-status wgv-connection-status--disconnected">
						<span class="dashicons dashicons-dismiss"></span>
						<?php esc_html_e( 'Not Connected', 'wg-vault' ); ?>
					</p>
				<?php endif; ?>
			</div>

			<div class="wgv-form-row">
				<span class="wgv-form-label"><?php esc_html_e( 'Google Drive OAuth', 'wg-vault' ); ?></span>
				<div class="wgv-button-group">
					<?php if ( $can_connect ) : ?>
						<a href="<?php echo esc_url( $oauth_url ); ?>"
						   class="button button-secondary">
							<?php esc_html_e( 'Connect to Google Drive', 'wg-vault' ); ?>
						</a>
					<?php else : ?>
						<button type="button" class="button button-secondary" disabled
						        title="<?php esc_attr_e( 'Enter Client ID and Client Secret above and save first.', 'wg-vault' ); ?>">
							<?php esc_html_e( 'Connect to Google Drive', 'wg-vault' ); ?>
						</button>
						<span class="wgv-field-hint">
							<?php esc_html_e( 'Save your Client ID and Secret first to enable this button.', 'wg-vault' ); ?>
						</span>
					<?php endif; ?>
				</div>
			</div>

			<div class="wgv-form-row">
				<label for="wgv-drive-folder-name"><?php esc_html_e( 'Drive Folder Name', 'wg-vault' ); ?></label>
				<input type="text"
				       id="wgv-drive-folder-name"
				       name="drive_folder_name"
				       value="<?php echo esc_attr( $folder_name ); ?>"
				       class="regular-text">
			</div>

			<?php if ( $is_connected ) : ?>
				<div class="wgv-form-row">
					<span class="wgv-form-label"><?php esc_html_e( 'Disconnect', 'wg-vault' ); ?></span>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wgv-inline-form">
						<?php wp_nonce_field( 'wgv_disconnect_drive' ); ?>
						<input type="hidden" name="action" value="wgv_disconnect_drive">
						<button type="submit" class="button wgv-button-disconnect">
							<?php esc_html_e( 'Disconnect from Google Drive', 'wg-vault' ); ?>
						</button>
					</form>
				</div>
			<?php endif; ?>
		</div>

		<div class="wgv-form-actions">
			<button type="submit" class="button button-primary wgv-button-save">
				<?php esc_html_e( 'Save Settings', 'wg-vault' ); ?>
			</button>
		</div>
	</form>
	<?php
}

/**
 * Render the Retention Policy tab.
 *
 * @param WGV_Settings $settings The settings instance.
 */
function wgv_render_tab_retention( WGV_Settings $settings ): void {
	$retention_enabled = (bool) $settings->get( 'retention_enabled', true );
	$retention_count   = (int) $settings->get( 'retention_count', 7 );
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
		<?php wp_nonce_field( 'wgv_settings_save' ); ?>
		<input type="hidden" name="wgv_action" value="save_settings">
		<input type="hidden" name="wgv_tab"    value="retention">

		<div class="wgv-form-section">
			<div class="wgv-form-row">
				<label class="wgv-checkbox-label">
					<input type="checkbox"
					       name="retention_enabled"
					       value="1"
					       <?php checked( $retention_enabled ); ?>>
					<?php esc_html_e( 'Enable Retention Policy', 'wg-vault' ); ?>
				</label>
			</div>

			<div class="wgv-form-row">
				<label for="wgv-retention-count"><?php esc_html_e( 'Keep Last X Backups', 'wg-vault' ); ?></label>
				<input type="number"
				       id="wgv-retention-count"
				       name="retention_count"
				       value="<?php echo esc_attr( $retention_count ); ?>"
				       min="1"
				       max="365"
				       class="small-text">
				<p class="wgv-field-hint">
					<?php esc_html_e( 'Older backups will be automatically deleted from Google Drive after each successful backup.', 'wg-vault' ); ?>
				</p>
			</div>
		</div>

		<div class="wgv-form-actions">
			<button type="submit" class="button button-primary wgv-button-save">
				<?php esc_html_e( 'Save Settings', 'wg-vault' ); ?>
			</button>
		</div>
	</form>
	<?php
}

/**
 * Render the Backup Log tab with log table and manual backup triggers.
 */
function wgv_render_tab_backup_log(): void {
	global $wpdb;

	$table_name = $wpdb->prefix . 'wgv_backup_log';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$rows = $wpdb->get_results(
		"SELECT id, backup_type, status, file_name, file_size_bytes, error_message, created_at
		 FROM {$table_name}
		 ORDER BY created_at DESC
		 LIMIT 50"
	);

	$status_map = [
		'success'     => [ 'label' => __( 'Success', 'wg-vault' ),     'class' => 'wgv-badge--success' ],
		'failed'      => [ 'label' => __( 'Failed', 'wg-vault' ),      'class' => 'wgv-badge--failed' ],
		'in_progress' => [ 'label' => __( 'In Progress', 'wg-vault' ), 'class' => 'wgv-badge--in-progress' ],
	];
	?>
	<div class="wgv-backup-log">

		<div class="wgv-manual-backup-bar">
			<h3><?php esc_html_e( 'Run a Manual Backup', 'wg-vault' ); ?></h3>
			<div class="wgv-manual-backup-buttons">

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'wgv_manual_backup' ); ?>
					<input type="hidden" name="action"      value="wgv_manual_backup">
					<input type="hidden" name="backup_type" value="database">
					<button type="submit" class="button wgv-button-backup">
						<?php esc_html_e( 'Run Database Backup Now', 'wg-vault' ); ?>
					</button>
				</form>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'wgv_manual_backup' ); ?>
					<input type="hidden" name="action"      value="wgv_manual_backup">
					<input type="hidden" name="backup_type" value="uploads">
					<button type="submit" class="button wgv-button-backup">
						<?php esc_html_e( 'Run Uploads Backup Now', 'wg-vault' ); ?>
					</button>
				</form>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'wgv_manual_backup' ); ?>
					<input type="hidden" name="action"      value="wgv_manual_backup">
					<input type="hidden" name="backup_type" value="full">
					<button type="submit" class="button wgv-button-backup">
						<?php esc_html_e( 'Run Full Backup Now', 'wg-vault' ); ?>
					</button>
				</form>

			</div>
		</div>

		<h3><?php esc_html_e( 'Backup History', 'wg-vault' ); ?></h3>

		<?php if ( empty( $rows ) ) : ?>
			<p class="wgv-empty-state"><?php esc_html_e( 'No backups have been run yet.', 'wg-vault' ); ?></p>
		<?php else : ?>
			<table class="widefat wgv-log-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Date', 'wg-vault' ); ?></th>
						<th><?php esc_html_e( 'Type', 'wg-vault' ); ?></th>
						<th><?php esc_html_e( 'Status', 'wg-vault' ); ?></th>
						<th><?php esc_html_e( 'File Name', 'wg-vault' ); ?></th>
						<th><?php esc_html_e( 'File Size', 'wg-vault' ); ?></th>
						<th><?php esc_html_e( 'Error', 'wg-vault' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rows as $row ) : ?>
						<?php
						$status_info = $status_map[ $row->status ] ?? [
							'label' => esc_html( ucfirst( $row->status ) ),
							'class' => 'wgv-badge--neutral',
						];
						$date_display = $row->created_at
							? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $row->created_at ) )
							: '&mdash;';
						?>
						<tr>
							<td class="wgv-col-date">
								<?php echo esc_html( $date_display ); ?>
							</td>
							<td class="wgv-col-type">
								<?php echo esc_html( ucfirst( $row->backup_type ) ); ?>
							</td>
							<td class="wgv-col-status">
								<span class="wgv-badge <?php echo esc_attr( $status_info['class'] ); ?>">
									<?php echo esc_html( $status_info['label'] ); ?>
								</span>
							</td>
							<td class="wgv-col-filename">
								<?php echo $row->file_name ? esc_html( $row->file_name ) : '&mdash;'; ?>
							</td>
							<td class="wgv-col-filesize">
								<?php echo $row->file_size_bytes ? esc_html( wgv_format_bytes( (int) $row->file_size_bytes ) ) : '&mdash;'; ?>
							</td>
							<td class="wgv-col-error">
								<?php echo $row->error_message ? esc_html( $row->error_message ) : '&mdash;'; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
	<?php
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Return the current admin tab slug, defaulting to 'general'.
 */
function wgv_get_current_tab(): string {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'general';
	$valid = [ 'general', 'google-drive', 'retention', 'backup-log' ];
	return in_array( $tab, $valid, true ) ? $tab : 'general';
}

/**
 * Return the admin URL for a given tab.
 *
 * @param string $tab Tab slug.
 */
function wgv_get_tab_url( string $tab ): string {
	return add_query_arg( [ 'page' => 'wg-vault', 'tab' => $tab ], admin_url( 'admin.php' ) );
}

/**
 * Format a byte count as a human-readable string (B, KB, MB, GB).
 *
 * @param int $bytes Raw byte count.
 */
function wgv_format_bytes( int $bytes ): string {
	if ( $bytes >= 1073741824 ) {
		return number_format( $bytes / 1073741824, 2 ) . ' GB';
	}
	if ( $bytes >= 1048576 ) {
		return number_format( $bytes / 1048576, 2 ) . ' MB';
	}
	if ( $bytes >= 1024 ) {
		return number_format( $bytes / 1024, 2 ) . ' KB';
	}
	return $bytes . ' B';
}
