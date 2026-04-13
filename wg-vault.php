<?php
/**
 * Plugin Name: WG Vault
 * Plugin URI:  https://webganics.com/wg-vault
 * Description: Lightweight WordPress backup plugin with Google Drive integration. Schedule automatic backups and store them securely in Google Drive.
 * Version:     1.0.0
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Author:      Webganics
 * Author URI:  https://webganics.com
 * License:     GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wg-vault
 * Domain Path: /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'WGV_VERSION',         '1.0.0' );
define( 'WGV_PLUGIN_DIR',      plugin_dir_path( __FILE__ ) );
define( 'WGV_PLUGIN_URL',      plugin_dir_url( __FILE__ ) );
define( 'WGV_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

require_once WGV_PLUGIN_DIR . 'includes/class-wgv-activator.php';
require_once WGV_PLUGIN_DIR . 'includes/class-wgv-deactivator.php';

register_activation_hook( __FILE__, [ 'WGV_Activator', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'WGV_Deactivator', 'deactivate' ] );

require_once WGV_PLUGIN_DIR . 'includes/class-wgv-loader.php';

add_action( 'plugins_loaded', [ 'WGV_Loader', 'init' ] );
