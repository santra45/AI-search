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
                'nonce' => wp_create_nonce('wp_rest'),
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'texts' => [
                    'searchPlaceholder' => __('Search products...', 'semantic-search-woo'),
                    'searching' => __('Searching...', 'semantic-search-woo'),
                    'noResults' => __('No products found', 'semantic-search-woo'),
                    'addToCart' => __('Add to cart', 'semantic-search-woo'),
                    'selectOptions' => __('Select options', 'semantic-search-woo'),
                    'outOfStock' => __('Out of stock', 'semantic-search-woo'),
                    'didYouMean' => __('Did you mean:', 'semantic-search-woo'),
                    'suggestions' => __('Suggestions:', 'semantic-search-woo'),
                    'filters' => __('Filters', 'semantic-search-woo'),
                    'categories' => __('Categories', 'semantic-search-woo'),
                    'priceRange' => __('Price Range', 'semantic-search-woo'),
                    'clearFilters' => __('Clear filters', 'semantic-search-woo'),
                    'loadMore' => __('Load more', 'semantic-search-woo'),
                    'searchingProducts' => __('Searching products...', 'semantic-search-woo')
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
