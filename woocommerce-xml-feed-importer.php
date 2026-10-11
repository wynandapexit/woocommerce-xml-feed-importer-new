<?php
/**
 * Plugin Name: WooCommerce XML Feed Importer
 * Plugin URI: https://github.com/wynandapexit/woocommerce-xml-feed-importer-new
 * Description: Import XML and CSV product feeds into WooCommerce with manual trigger buttons for on-demand imports.
 * Version: 2.0.2
 * Author: Wynand Apexit
 * Author URI: https://github.com/wynandapexit
 * License: GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wpfi
 * Requires PHP: 7.4
 * Requires WP: 5.0
 * Requires Plugins: woocommerce
 */

if (!defined('ABSPATH')) {
    exit('No direct script access allowed');
}

if (!defined('WPFI_VERSION')) {
    define('WPFI_VERSION', '2.0.2');
    define('WPFI_PATH', plugin_dir_path(__FILE__));
    define('WPFI_URL', plugin_dir_url(__FILE__));
}

if (!function_exists('is_plugin_active')) {
    include_once ABSPATH . 'wp-admin/includes/plugin.php';
}

if (!is_plugin_active('woocommerce/woocommerce.php')) {
    add_action('admin_notices', function () {
        echo '<div class="notice notice-error is-dismissible"><p><strong>WooCommerce XML Feed Importer:</strong> This plugin requires WooCommerce to be installed and activated.</p></div>';
    });
    return;
}

require_once WPFI_PATH . 'includes/class-wpfi-logger.php';
require_once WPFI_PATH . 'includes/class-wpfi-feed-repository.php';
require_once WPFI_PATH . 'includes/class-wpfi-importer.php';
require_once WPFI_PATH . 'includes/class-wpfi-scheduler.php';
require_once WPFI_PATH . 'includes/class-wpfi-admin.php';
require_once WPFI_PATH . 'includes/class-wpfi-plugin.php';

add_action('init', function () {
    WPFI_Plugin::instance()->boot();
}, 5);

register_activation_hook(__FILE__, ['WPFI_Plugin', 'activate']);
register_deactivation_hook(__FILE__, ['WPFI_Plugin', 'deactivate']);
