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

// ── REST API Endpoints ────────────────────────────────────────────────────────

add_action('rest_api_init', 'ssw_register_rest_routes');

function ssw_register_rest_routes(): void {
    register_rest_route('ssw/v1', '/search-fallback', [
        'methods'             => 'POST',
        'callback'           => 'ssw_search_fallback_endpoint',
        'permission_callback' => 'ssw_verify_license_key',
        'args'                => [
            'license_key' => [
                'required'          => true,
                'validate_callback' => function($param) {
                    return is_string($param) && !empty($param);
                }
            ],
            'keywords'    => [
                'required'          => true,
                'validate_callback' => function($param) {
                    return is_array($param);
                }
            ],
            'query'       => [
                'required'          => true,
                'validate_callback' => function($param) {
                    return is_string($param) && !empty($param);
                }
            ],
            'limit'       => [
                'required'          => false,
                'validate_callback' => function($param) {
                    return is_numeric($param) && $param > 0;
                },
                'default'           => 10
            ]
        ]
    ]);
}

function ssw_verify_license_key(WP_REST_Request $request): bool {
    $license_key = $request->get_param('license_key');
    $stored_key  = get_option('ssw_license_key', '');
    
    return !empty($license_key) && $license_key === $stored_key;
}

function ssw_search_fallback_endpoint(WP_REST_Request $request): WP_REST_Response {
    $keywords = $request->get_param('keywords');
    $query    = $request->get_param('query');
    $limit    = (int) $request->get_param('limit');
    if (empty($limit)) $limit = 10;
    
    try {
        $products = ssw_keyword_search($keywords, $query, $limit);
        
        return new WP_REST_Response([
            'success' => true,
            'results' => $products,
            'count'   => count($products)
        ], 200);
        
    } catch (Exception $e) {
        error_log('[SSW] Fallback search error: ' . $e->getMessage());
        
        return new WP_REST_Response([
            'success' => false,
            'error'   => 'Search failed',
            'results' => []
        ], 500);
    }
}

function ssw_keyword_search(array $keywords, string $original_query, int $limit): array {
    $args = [
        'post_type'      => 'product',
        'post_status'    => 'publish',
        'posts_per_page' => $limit,
        'orderby'        => 'title',
        'order'          => 'ASC'
    ];
    
    $tax_query = ['relation' => 'OR'];
    $meta_query = ['relation' => 'OR'];
    $attribute_terms = [];
    
    // Search in categories
    if (!empty($keywords)) {
        $category_terms = [];
        $tag_terms = [];
        
        foreach ($keywords as $keyword) {
            // Find categories matching keyword
            $cat_terms = get_terms([
                'taxonomy'   => 'product_cat',
                'name__like' => $keyword,
                'fields'     => 'ids'
            ]);
            
            if (!empty($cat_terms) && !is_wp_error($cat_terms)) {
                $category_terms = array_merge($category_terms, $cat_terms);
            }
            
            // Find tags matching keyword
            $tag_matches = get_terms([
                'taxonomy'   => 'product_tag',
                'name__like' => $keyword,
                'fields'     => 'ids'
            ]);
            
            if (!empty($tag_matches) && !is_wp_error($tag_matches)) {
                $tag_terms = array_merge($tag_terms, $tag_matches);
            }

            // Search in product attributes
            $attribute_taxonomies = wc_get_attribute_taxonomies();

            if (!empty($attribute_taxonomies)) {
                foreach ($attribute_taxonomies as $attribute) {

                    $taxonomy = wc_attribute_taxonomy_name($attribute->attribute_name);

                    $attr_terms = get_terms([
                        'taxonomy'   => $taxonomy,
                        'name__like' => $keyword,
                        'fields'     => 'ids'
                    ]);

                    if (!empty($attr_terms) && !is_wp_error($attr_terms)) {
                        $attribute_terms[$taxonomy] = isset($attribute_terms[$taxonomy])
                            ? array_merge($attribute_terms[$taxonomy], $attr_terms)
                            : $attr_terms;
                    }
                }
            }
        }
        
        if (!empty($category_terms)) {
            $tax_query[] = [
                'taxonomy' => 'product_cat',
                'field'    => 'term_id',
                'terms'    => array_unique($category_terms),
                'operator' => 'IN'
            ];
        }
        
        if (!empty($tag_terms)) {
            $tax_query[] = [
                'taxonomy' => 'product_tag',
                'field'    => 'term_id',
                'terms'    => array_unique($tag_terms),
                'operator' => 'IN'
            ];
        }

        // Add attribute filters
        if (!empty($attribute_terms)) {

            foreach ($attribute_terms as $taxonomy => $terms) {

                $tax_query[] = [
                    'taxonomy' => $taxonomy,
                    'field'    => 'term_id',
                    'terms'    => array_unique($terms),
                    'operator' => 'IN'
                ];
            }
        }
    }
    
    // Search in SKU and title
    foreach ($keywords as $keyword) {
        $meta_query[] = [
            'key'     => '_sku',
            'value'   => $keyword,
            'compare' => 'LIKE'
        ];
    }
    
    // Add taxonomy query if we have filters
    if (count($tax_query) > 1) {
        $args['tax_query'] = $tax_query;
    }
    
    // Add meta query if we have SKU searches
    if (count($meta_query) > 1) {
        $args['meta_query'] = $meta_query;
    }
    
    // Also search in product title as fallback
    $args['s'] = implode(' ', $keywords);
    
    $query = new WP_Query($args);
    $products = [];
    
    if ($query->have_posts()) {
        while ($query->have_posts()) {
            $query->the_post();
            $product = wc_get_product(get_the_ID());
            
            if ($product) {
                $products[] = ssw_format_product_for_api($product);
            }
        }
    }
    
    wp_reset_postdata();
    return $products;
}

function ssw_format_product_for_api(WC_Product $product): array {
    $image_id = $product->get_image_id();
    $image_url = $image_id ? wp_get_attachment_url($image_id) : '';
    
    $categories = [];
    $category_terms = get_the_terms($product->get_id(), 'product_cat');
    if ($category_terms && !is_wp_error($category_terms)) {
        foreach ($category_terms as $term) {
            $categories[] = [
                'id'   => $term->term_id,
                'name' => $term->name
            ];
        }
    }
    
    return [
        'id'           => $product->get_id(),
        'name'         => $product->get_name(),
        'price'        => $product->get_price(),
        'permalink'    => $product->get_permalink(),
        'stock_status' => $product->get_stock_status(),
        'categories'   => $categories,
        'images'       => [['src' => $image_url]],
        'sku'          => $product->get_sku()
    ];
}


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