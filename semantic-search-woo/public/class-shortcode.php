<?php
if (!defined('ABSPATH')) exit;

class SSW_Shortcode {

    private SSW_API_Client $api;
    private string $plugin_url;

    public function __construct(SSW_API_Client $api) {
        $this->api = $api;
        $this->plugin_url = plugin_dir_url(dirname(__FILE__));
        
        add_shortcode('semantic_search', [$this, 'render_search_shortcode']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public function enqueue_assets(): void {
        // Only load assets on pages that might contain the shortcode
        global $post;
        
        if (is_a($post, 'WP_Post') && has_shortcode($post->post_content, 'semantic_search')) {
            wp_enqueue_style(
                'semantic-search-css',
                $this->plugin_url . 'public/css/semantic-search.css',
                [],
                SSW_VERSION
            );

            wp_enqueue_script(
                'semantic-search-js',
                $this->plugin_url . 'public/js/semantic-search.js',
                ['jquery'],
                SSW_VERSION,
                true
            );

            // Pass configuration to JavaScript
            wp_localize_script('semantic-search-js', 'semanticSearchConfig', [
                'apiUrl' => rest_url('ssw/v1/'),
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('wp_rest'),
                'addToCartNonce' => wp_create_nonce('ssw_add_to_cart_nonce'),
                'licenseKey' => get_option('ssw_license_key', ''),
                'currency' => get_woocommerce_currency(),
                'currencySymbol' => get_woocommerce_currency_symbol(),
                'currencyPosition' => get_option('woocommerce_currency_pos'),
                'decimalSeparator' => wc_get_price_decimal_separator(),
                'thousandSeparator' => wc_get_price_thousand_separator(),
                'decimals' => wc_get_price_decimals(),
                'locale' => get_locale(),
                'texts' => [
                    'addToCart' => __('Add to Cart', 'semantic-search-woo'),
                    'selectOptions' => __('Select Options', 'semantic-search-woo'),
                    'outOfStock' => __('Out of Stock', 'semantic-search-woo'),
                    'loading' => __('Loading...', 'semantic-search-woo'),
                    'noResults' => __('No products found', 'semantic-search-woo'),
                    'error' => __('An error occurred. Please try again.', 'semantic-search-woo'),
                    'didYouMean' => __('Did you mean:', 'semantic-search-woo'),
                    'suggestions' => __('Suggestions:', 'semantic-search-woo'),
                    'filters' => __('Filters', 'semantic-search-woo'),
                    'categories' => __('Categories', 'semantic-search-woo'),
                    'priceRange' => __('Price Range', 'semantic-search-woo'),
                    'clearFilters' => __('Clear filters', 'semantic-search-woo'),
                    'loadMore' => __('Load more', 'semantic-search-woo'),
                    'searchingProducts' => __('Searching products...', 'semantic-search-woo'),
                    'searchPlaceholder' => __('Search products...', 'semantic-search-woo'),
                    'searching' => __('Searching...', 'semantic-search-woo')
                ]
            ]);
        }
    }

    public function render_search_shortcode($atts): string {
        // Default attributes
        $atts = shortcode_atts([
            'placeholder' => __('Search products...', 'semantic-search-woo'),
            'limit' => 12,
            'show_history' => 'true',
            'layout' => 'default',
            'columns' => 4
        ], $atts, 'semantic_search');

        // Convert string attributes to proper types
        $atts['show_history'] = filter_var($atts['show_history'], FILTER_VALIDATE_BOOLEAN);
        $atts['limit'] = (int) $atts['limit'];
        $atts['columns'] = (int) $atts['columns'];

        ob_start();
        include dirname(__FILE__) . '/templates/search-form.php';
        return ob_get_clean();
    }
}
