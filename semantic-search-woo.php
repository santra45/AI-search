<?php
/**
 * Plugin Name:       Semantic Search for WooCommerce
 * Plugin URI:        https://enabling-fancy-fox.ngrok-free.app/onboarding/
 * Description:       AI-powered semantic search for WooCommerce stores.
 *                    Replaces keyword search with vector-based semantic search.
 * Version:           0.2.0
 * Author:            Czar Group
 * Author URI:        https://czargroup.net
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
        update_option('ssw_sync_pages', 0);  // Default: don't sync pages
        update_option('ssw_sync_posts', 0);  // Default: don't sync posts
    }

    // Generate webhook secret if not set
    if (!get_option('ssw_webhook_secret')) {
        update_option('ssw_webhook_secret', bin2hex(random_bytes(16)));
    }

    // Mark setup as incomplete - user needs to complete initial setup
    update_option('ssw_setup_completed', false);

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


// ── Plugin Row Meta ────────────────────────────────────────────────────────────

add_filter('plugin_row_meta', 'ssw_plugin_row_meta', 10, 2);

function ssw_plugin_row_meta(array $links, string $file): array {
    if (plugin_basename(__FILE__) !== $file) {
        return $links;
    }

    // Only show setup link if setup is not completed
    if (!get_option('ssw_setup_completed', false)) {
        $setup_url = admin_url('admin.php?page=semantic-search-setup');
        $links[] = '<a href="' . esc_url($setup_url) . '" style="color: #d63638; font-weight: bold;">⚡ Complete Setup</a>';
    }

    return $links;
}

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
    
    // Show setup notice if not completed
    if (is_admin() && !get_option('ssw_setup_completed', false)) {
        $setup_url = admin_url('admin.php?page=semantic-search-setup');
        echo '<div class="notice notice-warning">
            <p>
                <strong>Semantic Search for WooCommerce</strong>
                is almost ready! <a href="' . esc_url($setup_url) . '" style="font-weight: bold;">Complete the setup</a> to activate AI-powered search.
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

        // Public (frontend functionality)
        SSW_PLUGIN_DIR . 'public/class-shortcode.php',

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
        'permission_callback' => '__return_true',
        'args'                => [
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

    // Frontend search endpoint
    register_rest_route('ssw/v1', '/search', [
        'methods'             => 'POST',
        'callback'           => 'ssw_frontend_search_endpoint',
        'permission_callback' => '__return_true',
        'args'                => [
            'query' => [
                'required'          => true,
                'validate_callback' => function($param) {
                    return is_string($param) && !empty($param);
                }
            ],
            'limit' => [
                'required'          => false,
                'validate_callback' => function($param) {
                    return is_numeric($param) && $param > 0;
                },
                'default'           => 12
            ],
            'page' => [
                'required'          => false,
                'validate_callback' => function($param) {
                    return is_numeric($param) && $param > 0;
                },
                'default'           => 1
            ],
            'filters' => [
                'required'          => false,
                'validate_callback' => function($param) {
                    return is_array($param);
                },
                'default'           => []
            ]
        ]
    ]);

    // Suggestions endpoint
    register_rest_route('ssw/v1', '/suggestions', [
        'methods'             => 'GET',
        'callback'           => 'ssw_suggestions_endpoint',
        'permission_callback' => '__return_true',
        'args'                => [
            'query' => [
                'required'          => true,
                'validate_callback' => function($param) {
                    return is_string($param) && !empty($param);
                }
            ],
            'limit' => [
                'required'          => false,
                'validate_callback' => function($param) {
                    return is_numeric($param) && $param > 0;
                },
                'default'           => 5
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
    
    // Debug logging
    error_log('[SSW] Fallback search - Original keywords: ' . print_r($keywords, true));
    error_log('[SSW] Fallback search - Original query: ' . $query);
    
    try {
        $products = ssw_keyword_search($keywords, $query, $limit);
        error_log('[SSW] Fallback search - Products found: ' . count($products));
        
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

function ssw_keyword_search(array $keywords, string $original_query, int $limit, int $page): array {
    // Stop words to filter out (similar to Python version)
    $stop_words = [
        'a', 'an', 'and', 'are', 'as', 'at', 'be', 'by', 'for', 'from',
        'has', 'he', 'in', 'is', 'it', 'its', 'of', 'on', 'that', 'the',
        'to', 'was', 'will', 'with', 'i', 'you', 'your', 'we', 'our',
        'they', 'them', 'their', 'this', 'these', 'those', 'or', 'but',
        'not', 'no', 'can', 'could', 'would', 'should', 'have', 'had',
        'what', 'when', 'where', 'why', 'how', 'who', 'which', 'if',
        'do', 'does', 'did', 'get', 'got', 'go', 'went', 'come', 'came'
    ];
    
    // Extract meaningful keywords from original query
    $cleaned = preg_replace('/[^\w\s]/', ' ', strtolower($original_query));
    $words = array_filter(array_map('trim', explode(' ', $cleaned)));
    $filtered_keywords = [];
    
    foreach ($words as $word) {
        if (!in_array($word, $stop_words) && (strlen($word) > 1 || is_numeric($word))) {
            if (!in_array($word, $filtered_keywords)) {
                $filtered_keywords[] = $word;
            }
        }
    }
    
    // Use filtered keywords if available, otherwise use original keywords
    $search_keywords = !empty($filtered_keywords) ? $filtered_keywords : $keywords;
    
    // Debug logging
    error_log('[SSW] Original keywords array: ' . print_r($keywords, true));
    error_log('[SSW] Filtered keywords: ' . print_r($filtered_keywords, true));
    error_log('[SSW] Final search keywords: ' . print_r($search_keywords, true));
    
    $all_product_ids = [];
    
    // 1. Search by categories
    if (!empty($search_keywords)) {
        $category_terms = [];
        foreach ($search_keywords as $keyword) {
            $cat_terms = get_terms([
                'taxonomy'   => 'product_cat',
                'name__like' => $keyword,
                'fields'     => 'ids'
            ]);
            
            if (!empty($cat_terms) && !is_wp_error($cat_terms)) {
                $category_terms = array_merge($category_terms, $cat_terms);
            }
        }
        
        if (!empty($category_terms)) {
            $args = [
                'post_type'      => 'product',
                'post_status'    => 'publish',
                'posts_per_page' => -1, // Get all matching
                'fields'         => 'ids',
                'tax_query'      => [
                    [
                        'taxonomy' => 'product_cat',
                        'field'    => 'term_id',
                        'terms'    => array_unique($category_terms),
                        'operator' => 'IN'
                    ]
                ]
            ];
            
            $category_query = new WP_Query($args);
            $all_product_ids = array_merge($all_product_ids, $category_query->posts);
            error_log('[SSW] Category search found: ' . count($category_query->posts) . ' products');
        }
    }
    
    // 2. Search by tags
    if (!empty($search_keywords)) {
        $tag_terms = [];
        foreach ($search_keywords as $keyword) {
            $tag_matches = get_terms([
                'taxonomy'   => 'product_tag',
                'name__like' => $keyword,
                'fields'     => 'ids'
            ]);
            
            if (!empty($tag_matches) && !is_wp_error($tag_matches)) {
                $tag_terms = array_merge($tag_terms, $tag_matches);
            }
        }
        
        if (!empty($tag_terms)) {
            $args = [
                'post_type'      => 'product',
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'tax_query'      => [
                    [
                        'taxonomy' => 'product_tag',
                        'field'    => 'term_id',
                        'terms'    => array_unique($tag_terms),
                        'operator' => 'IN'
                    ]
                ]
            ];
            
            $tag_query = new WP_Query($args);
            $all_product_ids = array_merge($all_product_ids, $tag_query->posts);
            error_log('[SSW] Tag search found: ' . count($tag_query->posts) . ' products');
        }
    }
    
    // 3. Search by title/description/SKU
    if (!empty($search_keywords)) {
        $sku_meta_query = ['relation' => 'OR'];
        foreach ($search_keywords as $keyword) {
            $sku_meta_query[] = [
                'key'     => '_sku',
                'value'   => $keyword,
                'compare' => 'LIKE'
            ];
        }
        
        $args = [
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            's'             => implode(' ', $search_keywords),
            'meta_query'     => $sku_meta_query
        ];
        
        $title_query = new WP_Query($args);
        $all_product_ids = array_merge($all_product_ids, $title_query->posts);
        error_log('[SSW] Title/SKU search found: ' . count($title_query->posts) . ' products');
    }
    
    // Remove duplicates and limit results
    $unique_product_ids = array_unique($all_product_ids);
    $limited_product_ids = array_slice($unique_product_ids, 0, $limit);
    
    error_log('[SSW] Total unique products found: ' . count($unique_product_ids));
    error_log('[SSW] Returning products: ' . print_r($limited_product_ids, true));
    
    $products = [];
    foreach ($unique_product_ids as $product_id) {
        $product = wc_get_product($product_id);
        if ($product) {
            $products[] = ssw_format_product_for_api($product);
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
    
    // Get min and max prices for variable products
    $min_price = $product->get_price();
    $max_price = $product->get_price();
    
    if ($product->get_type() === 'variable') {
        $variation_prices = $product->get_variation_prices();
        if (!empty($variation_prices['price'])) {
            $min_price = min($variation_prices['price']);
            $max_price = max($variation_prices['price']);
        }
    }
    
    return [
        'id'           => $product->get_id(),
        'name'         => $product->get_name(),
        'price'        => $product->get_price(),
        'min_price'    => $min_price,
        'max_price'    => $max_price,
        'regular_price' => $product->get_regular_price(),
        'sale_price'   => $product->get_sale_price(),
        'permalink'    => $product->get_permalink(),
        'stock_status' => $product->get_stock_status(),
        'categories'   => $categories,
        'images'       => [['src' => $image_url]],
        'image'        => $image_url,
        'sku'          => $product->get_sku(),
        'type'         => $product->get_type(),
        'on_sale'      => $product->is_on_sale()
    ];
}

function ssw_frontend_search_endpoint(WP_REST_Request $request): WP_REST_Response {
    $query = $request->get_param('query');
    $limit = (int) $request->get_param('limit');
    $page = (int) $request->get_param('page');
    
    $start_time = microtime(true);
    
    try {
        // Try semantic search first if API is configured
        $api_url = get_option('ssw_api_url', '');
        $license_key = get_option('ssw_license_key', '');
        
        if (!empty($api_url) && !empty($license_key)) {
            $api_client = new SSW_API_Client($api_url, $license_key, $limit);
            $product_ids = $api_client->search($query);
            
            if (!empty($product_ids)) {
                $products = ssw_get_products_by_ids($product_ids, $limit, $page, []);
                $search_time = round(microtime(true) - $start_time, 3);
                
                return new WP_REST_Response([
                    'success' => true,
                    'data' => [
                        'products' => $products,
                        'total' => count($product_ids),
                        'total_pages' => ceil(count($product_ids) / $limit),
                        'current_page' => $page,
                        'search_time' => $search_time
                    ]
                ], 200);
            }
        }
        
        // Fallback to keyword search
        $keywords = explode(' ', $query);
        $products = ssw_keyword_search($keywords, $query, $limit, $page);
        $search_time = round(microtime(true) - $start_time, 3);
        
        return new WP_REST_Response([
            'success' => true,
            'data' => [
                'products' => $products,
                'total' => count($products),
                'total_pages' => ceil(count($products) / $limit),
                'current_page' => $page,
                'search_time' => $search_time
            ]
        ], 200);
        
    } catch (Exception $e) {
        error_log('[SSW] Frontend search error: ' . $e->getMessage());
        
        return new WP_REST_Response([
            'success' => false,
            'error' => 'Search failed',
            'data' => [
                'products' => [],
                'total' => 0,
                'total_pages' => 0,
                'current_page' => $page
            ]
        ], 500);
    }
}

function ssw_suggestions_endpoint(WP_REST_Request $request): WP_REST_Response {
    $query = $request->get_param('query');
    $limit = (int) $request->get_param('limit');
    
    try {
        $suggestions = [];
        
        // Get product name suggestions
        $product_suggestions = ssw_get_product_suggestions($query, $limit);
        foreach ($product_suggestions as $suggestion) {
            $suggestions[] = [
                'text' => $suggestion,
                'type' => 'auto_complete'
            ];
        }
        
        // Get "did you mean" suggestions (basic implementation)
        $did_you_mean = ssw_get_did_you_mean_suggestions($query, 2);
        foreach ($did_you_mean as $suggestion) {
            $suggestions[] = [
                'text' => $suggestion,
                'type' => 'did_you_mean'
            ];
        }
        
        return new WP_REST_Response([
            'success' => true,
            'data' => array_slice($suggestions, 0, $limit)
        ], 200);
        
    } catch (Exception $e) {
        error_log('[SSW] Suggestions error: ' . $e->getMessage());
        
        return new WP_REST_Response([
            'success' => false,
            'data' => []
        ], 500);
    }
}

function ssw_get_products_by_ids(array $product_ids, int $limit, int $page, array $filters): array {
    $offset = ($page - 1) * $limit;
    $paged_ids = array_slice($product_ids, $offset, $limit);
    
    $args = [
        'post_type' => 'product',
        'post__in' => $paged_ids,
        'posts_per_page' => $limit,
        'orderby' => 'post__in',
        'post_status' => 'publish'
    ];
    
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

function ssw_keyword_search_with_filters(array $keywords, string $original_query, int $limit, int $page, array $filters): array {
    $offset = ($page - 1) * $limit;
    
    $args = [
        'post_type' => 'product',
        'post_status' => 'publish',
        'posts_per_page' => $limit,
        'offset' => $offset,
        'orderby' => 'title',
        'order' => 'ASC'
    ];
    
    $tax_query = ['relation' => 'OR'];
    $meta_query = ['relation' => 'OR'];
    
    if (!empty($keywords)) {
        $category_terms = [];
        foreach ($keywords as $keyword) {
            $cat_terms = get_terms([
                'taxonomy' => 'product_cat',
                'name__like' => $keyword,
                'fields' => 'ids'
            ]);
            
            if (!empty($cat_terms) && !is_wp_error($cat_terms)) {
                $category_terms = array_merge($category_terms, $cat_terms);
            }
        }
        
        if (!empty($category_terms)) {
            $tax_query[] = [
                'taxonomy' => 'product_cat',
                'field' => 'term_id',
                'terms' => array_unique($category_terms),
                'operator' => 'IN'
            ];
        }
        
        foreach ($keywords as $keyword) {
            $meta_query[] = [
                'key' => '_sku',
                'value' => $keyword,
                'compare' => 'LIKE'
            ];
        }
    }
    
    if (count($tax_query) > 1) {
        $args['tax_query'] = $tax_query;
    }
    
    if (count($meta_query) > 1) {
        $args['meta_query'] = $meta_query;
    }
    
    $args['s'] = $original_query;
    
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

function ssw_get_product_suggestions(string $query, int $limit): array {
    $args = [
        'post_type' => 'product',
        'post_status' => 'publish',
        'posts_per_page' => $limit,
        's' => $query,
        'orderby' => 'title',
        'order' => 'ASC'
    ];
    
    $products = [];
    $query_obj = new WP_Query($args);
    
    if ($query_obj->have_posts()) {
        while ($query_obj->have_posts()) {
            $query_obj->the_post();
            $products[] = get_the_title();
        }
    }
    
    wp_reset_postdata();
    return $products;
}

function ssw_get_did_you_mean_suggestions(string $query, int $limit): array {
    // Basic implementation - could be enhanced with more sophisticated algorithms
    $suggestions = [];
    
    // Get all product titles
    $args = [
        'post_type' => 'product',
        'post_status' => 'publish',
        'posts_per_page' => 100,
        'orderby' => 'title',
        'order' => 'ASC'
    ];
    
    $query_obj = new WP_Query($args);
    $titles = [];
    
    if ($query_obj->have_posts()) {
        while ($query_obj->have_posts()) {
            $query_obj->the_post();
            $titles[] = get_the_title();
        }
    }
    
    wp_reset_postdata();
    
    // Simple string similarity check
    foreach ($titles as $title) {
        $similarity = similar_text(strtolower($query), strtolower($title), $percent);
        if ($percent > 50 && $percent < 100) { // Similar but not exact match
            $suggestions[] = $title;
            if (count($suggestions) >= $limit) {
                break;
            }
        }
    }
    
    return $suggestions;
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

    // Boot frontend shortcode (always available)
    if (!is_admin()) {
        $api_client = new SSW_API_Client($api_url, $license_key, $limit);
        new SSW_Shortcode($api_client);
    }
}

// AJAX handler for add to cart
add_action('wp_ajax_ssw_add_to_cart', 'ssw_ajax_add_to_cart');
add_action('wp_ajax_nopriv_ssw_add_to_cart', 'ssw_ajax_add_to_cart');

function ssw_ajax_add_to_cart(): void {
    check_ajax_referer('ssw_add_to_cart_nonce', 'nonce');
    
    $product_id = intval($_POST['product_id']);
    $quantity = isset($_POST['quantity']) ? intval($_POST['quantity']) : 1;
    
    if ($product_id <= 0) {
        wp_send_json_error(['message' => 'Invalid product ID']);
    }
    
    $product = wc_get_product($product_id);
    if (!$product) {
        wp_send_json_error(['message' => 'Product not found']);
    }
    
    if (!$product->is_purchasable()) {
        wp_send_json_error(['message' => 'Product is not purchasable']);
    }
    
    if (!$product->is_in_stock()) {
        wp_send_json_error(['message' => 'Product is out of stock']);
    }
    
    $cart_item_key = WC()->cart->add_to_cart($product_id, $quantity);
    
    if ($cart_item_key) {
        WC()->cart->calculate_totals();
        
        // Return fragments for cart update
        ob_start();
        woocommerce_mini_cart();
        $mini_cart = ob_get_clean();
        
        wp_send_json_success([
            'message' => 'Product added to cart',
            'fragments' => [
                'div.widget_shopping_cart_content' => $mini_cart
            ],
            'cart_hash' => WC()->cart->get_cart_hash()
        ]);
    } else {
        wp_send_json_error(['message' => 'Failed to add product to cart']);
    }
}