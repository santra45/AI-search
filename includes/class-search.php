<?php
if (!defined('ABSPATH')) exit;

class SSW_Search {

    private SSW_API_Client $api;

    public function __construct(string $api_url, string $license_key) {
        $limit     = (int) get_option('ssw_result_limit', 10);
        $this->api = new SSW_API_Client($api_url, $license_key, $limit);

        // Hook into WooCommerce product query
        add_action('pre_get_posts', [$this, 'intercept_search']);
    }

    public function intercept_search(\WP_Query $query): void {
        // Only intercept front-end search queries
        if (is_admin())           return;
        if (!$query->is_search()) return;
        if (!$query->is_main_query()) return;

        $search_term = get_search_query();
        if (empty(trim($search_term))) return;

        // Call your semantic search API
        $product_ids = $this->api->search($search_term);

        // Empty response → API failed → let WooCommerce handle it normally
        if (empty($product_ids)) {
            error_log('[SSW] Falling back to default WooCommerce search');
            return;
        }

        // Tell WooCommerce: show ONLY these products in this exact order
        $query->set('post__in',      $product_ids);
        $query->set('orderby',       'post__in');   // preserve ranking order
        $query->set('posts_per_page', count($product_ids));

        // Remove default keyword filtering — we've already done it
        $query->set('s', '');
    }
}