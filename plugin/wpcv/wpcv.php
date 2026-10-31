<?php
/**
 * Plugin Name: WPCV
 * Description: A starting point for custom WordPress functionality.
 * Version: 0.1.0
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

define( 'WPCV_VERSION', '0.1.0' );
define( 'WPCV_PLUGIN_FILE', __FILE__ );
define( 'WPCV_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPCV_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once WPCV_PLUGIN_DIR . 'includes/class-plugin.php';

register_activation_hook( __FILE__, array( \WPCV\Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \WPCV\Plugin::class, 'deactivate' ) );

add_action( 'plugins_loaded', array( \WPCV\Plugin::class, 'boot' ) );
