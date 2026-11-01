<?php
/**
 * Plugin Name: WPCV
 * Description: Manage CV entries and display a chronological, filterable CV with the [wpcv] shortcode.
 * Version: 0.4.1
 * Requires at least: 6.5
 * Requires PHP: 8.2
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wpcv
 * Update URI: false
 *
 * @package WPCV
 */

defined( 'ABSPATH' ) || exit;

define( 'WPCV_VERSION', '0.4.1' );
define( 'WPCV_PLUGIN_FILE', __FILE__ );
define( 'WPCV_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPCV_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once WPCV_PLUGIN_DIR . 'includes/class-entries.php';
require_once WPCV_PLUGIN_DIR . 'includes/class-shortcode.php';
require_once WPCV_PLUGIN_DIR . 'includes/class-importer.php';
require_once WPCV_PLUGIN_DIR . 'includes/class-dashboard-import.php';
require_once WPCV_PLUGIN_DIR . 'includes/class-plugin.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once WPCV_PLUGIN_DIR . 'includes/class-cli.php';
	\WP_CLI::add_command( 'wpcv', \WPCV\CLI::class );
}

register_activation_hook( __FILE__, array( \WPCV\Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \WPCV\Plugin::class, 'deactivate' ) );

add_action( 'plugins_loaded', array( \WPCV\Plugin::class, 'boot' ) );
