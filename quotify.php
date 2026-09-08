<?php
/**
 * Plugin Name: Quotify
 * Plugin URI:  https://github.com/akosiraffytot/quotify-wp
 * Description: Counts pages from any website's XML sitemap and returns a tiered price with a checkout link.
 * Version:     1.0.1
 * Author:      Rafael Mendoza
 * Author URI:  https://akosiraffytot.dev/
 * License:     GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Text Domain: qtfy
 *
 * @package Quotify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'QUOTIFY_VERSION', '1.0.1' );
define( 'QUOTIFY_PATH', plugin_dir_path( __FILE__ ) );
define( 'QUOTIFY_URL', plugin_dir_url( __FILE__ ) );
define( 'QUOTIFY_FILE', __FILE__ );

require_once QUOTIFY_PATH . 'includes/class-quotify-admin.php';
require_once QUOTIFY_PATH . 'includes/class-quotify-updater.php';

Quotify\Updater::init();

/**
 * Boot the plugin.
 *
 * @return void
 */
function quotify_boot(): void {
	Quotify\Admin::init();
}
add_action( 'plugins_loaded', 'quotify_boot' );
