<?php
/**
 * Plugin Name:       Semantic Search for WooCommerce
 * Plugin URI:        https://yoursite.com/semantic-search
 * Description:       AI-powered semantic search for WooCommerce stores.
 *                    Replaces keyword search with vector-based semantic search.
 * Version:           0.2.0
 * Author:            Your Name
 * Author URI:        https://yoursite.com
 * License:           GPL v2 or later
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * WC requires at least: 7.0
 * WC tested up to:      10.0
 * Text Domain:       semantic-search-woo
 */

if (!defined('ABSPATH')) exit;

// ── Constants ──────────────────────────────────────────────────────────────────

define('SSW_VERSION',    '0.2.0');
define('SSW_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SSW_PLUGIN_URL', plugin_dir_url(__FILE__));
define('SSW_PLUGIN_FILE', __FILE__);


// ── Activation ────────────────────────────────────────────────────────────────

register_activation_hook(__FILE__, 'ssw_activate');

function ssw_activate(): void {
    // Set default options on first activation
    if (!get_option('ssw_api_url')) {
        update_option('ssw_api_url',      'http://127.0.0.1:8000');
        update_option('ssw_result_limit', 10);
    }

    // Generate webhook secret if not set
    if (!get_option('ssw_webhook_secret')) {
        update_option('ssw_webhook_secret', bin2hex(random_bytes(16)));
    }

    // Flush rewrite rules
    flush_rewrite_rules();
}


// ── Deactivation ──────────────────────────────────────────────────────────────

register_deactivation_hook(__FILE__, 'ssw_deactivate');

function ssw_deactivate(): void {
    $license_key = get_option('ssw_license_key', '');

    // Remove webhooks from WooCommerce on deactivation
    if (!empty($license_key)) {
        $manager = new SSW_Webhook_Manager($license_key);
        $manager->delete_existing();
    }

    flush_rewrite_rules();
}


// ── Uninstall ─────────────────────────────────────────────────────────────────
// Full cleanup handled by uninstall.php


// ── Check Dependencies ────────────────────────────────────────────────────────

add_action('admin_notices', 'ssw_check_dependencies');

function ssw_check_dependencies(): void {
    if (!class_exists('WooCommerce')) {
        echo '<div class="notice notice-error">
            <p>
                <strong>Semantic Search for WooCommerce</strong>
                requires WooCommerce to be installed and active.
            </p>
        </div>';
    }
}


// ── Declare WooCommerce Compatibility ─────────────────────────────────────────

add_action('before_woocommerce_init', function () {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            __FILE__,
            true
        );
    }
});


// ── Autoload Classes ──────────────────────────────────────────────────────────

function ssw_autoload(): void {
    $files = [
        // Core includes (load first — admin depends on these)
        SSW_PLUGIN_DIR . 'includes/class-api-client.php',
        SSW_PLUGIN_DIR . 'includes/class-sync.php',
        SSW_PLUGIN_DIR . 'includes/class-webhook-manager.php',
        SSW_PLUGIN_DIR . 'includes/class-search.php',

        // Admin (load after includes)
        SSW_PLUGIN_DIR . 'admin/class-admin.php',
    ];

    foreach ($files as $file) {
        if (file_exists($file)) {
            require_once $file;
        } else {
            // Log missing file in debug mode
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("[SSW] Missing file: {$file}");
            }
        }
    }
}

ssw_autoload();


// ── Bootstrap ─────────────────────────────────────────────────────────────────

add_action('plugins_loaded', 'ssw_init');

function ssw_init(): void {
    // Bail if WooCommerce not active
    if (!class_exists('WooCommerce')) return;

    $api_url     = get_option('ssw_api_url',      '');
    $license_key = get_option('ssw_license_key',  '');
    $limit       = (int) get_option('ssw_result_limit', 10);

    // Boot admin panel (always, for any admin user)
    if (is_admin()) {
        new SSW_Admin();
    }

    // Boot search interception (front-end only, requires config)
    if (!is_admin() && !empty($api_url) && !empty($license_key)) {
        new SSW_Search($api_url, $license_key, $limit);
    }
}