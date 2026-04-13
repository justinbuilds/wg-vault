<?php
/**
 * Registers and renders the WG Vault admin page.
 *
 * Hooks into the WordPress admin menu to add a top-level page and
 * enqueues the plugin stylesheet. The full settings form and tabbed
 * UI will be built out in a future iteration.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu',            'wgv_register_admin_menu' );
add_action( 'admin_enqueue_scripts', 'wgv_enqueue_admin_assets' );

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
		'dashicons-vault',
		80
	);
}

/**
 * Enqueue the plugin admin stylesheet on WG Vault pages only.
 *
 * @param string $hook_suffix The current admin page hook.
 */
function wgv_enqueue_admin_assets( string $hook_suffix ): void {
	if ( $hook_suffix !== 'toplevel_page_wg-vault' ) {
		return;
	}

	wp_enqueue_style(
		'wgv-admin-styles',
		WGV_PLUGIN_URL . 'admin/admin-styles.css',
		[],
		WGV_VERSION
	);
}

/**
 * Render the main WG Vault admin page.
 */
function wgv_render_admin_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to access this page.', 'wg-vault' ) );
	}
	?>
	<div class="wrap wgv-wrap">
		<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
		<p class="wgv-tagline">
			<?php esc_html_e( 'Lightweight backups with Google Drive integration — by Webganics.', 'wg-vault' ); ?>
		</p>

		<div class="wgv-notice wgv-notice--info">
			<?php esc_html_e( 'WG Vault is active. Full settings and backup controls are coming soon.', 'wg-vault' ); ?>
		</div>
	</div>
	<?php
}
