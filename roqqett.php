<?php

/**
 * @wordpress-plugin
 * Plugin Name:     Roqqett
 * Plugin URI:      https://roqqett.com
 * Description:     Roqqett Pay and Roqqett Checkout allow users to pay using their banking apps, creating a great shopping experience, whilst reducing fees, fraud and friction for your business.
 * Version:         1.1.4
 * Author:          Roqqett Ltd
 * Author URI:      https://roqqett.com
 * Developer:       Roqqett Ltd
 * Developer URI:   https://roqqett.com
 * License:         AGPL-3.0-only
 * License URI:     http://www.gnu.org/licenses/agpl-3.0.txt
 *
 * WC requires at least: 2.2
 * WC tested up to: 6.4.1
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// TODO add to functions.php namespaced

function add_roqqett_gateway($gateways)
{
    $gateways[] = '\Roqqett\Gateway';
    return $gateways;
}

function init_roqqett_gateway()
{
    flush_rewrite_rules();
    require plugin_dir_path(__FILE__) . 'Gateway.php';
}

function roqqett_is_woocommerce_active()
{
    // Test to see if WooCommerce is active (including network activated).
    $plugin_path = trailingslashit(WP_PLUGIN_DIR) . 'woocommerce/woocommerce.php';

    return (
        in_array($plugin_path, wp_get_active_and_valid_plugins())
    );
}

if (roqqett_is_woocommerce_active()) {
    add_filter('woocommerce_payment_gateways', 'add_roqqett_gateway');
    add_action('plugins_loaded', 'init_roqqett_gateway');
}

function roqqett_plugin_row_meta($plugin_meta, $plugin_file, $plugin_data, $status)
{
    if (strpos($plugin_file, "roqqett.php") !== false) {
        // Replace visit slot
        $plugin_meta[2] = "<a href=\"https://roqqett.com/support\" aria-label=\"Get support\" target=\"_blank\" rel=\"noreferrer\">Get support</a>";
    }

    return $plugin_meta;
}

function roqqett_plugin_action_links($plugin_links, $plugin_file)
{
    if (strpos($plugin_file, "roqqett.php") !== false) {
        array_unshift($plugin_links, "<a href=\"/wp-admin/admin.php?page=wc-settings&tab=checkout&section=roqqett\">Settings</a>");
    }
    return $plugin_links;
}

add_filter("plugin_row_meta", "roqqett_plugin_row_meta", 2, 10);
add_filter("plugin_action_links", "roqqett_plugin_action_links", 10, 2);
