<?php
if (!defined('ABSPATH')) exit;

class SSW_Search {

    private SSW_API_Client $api;

    public function __construct(string $api_url, string $license_key, int $limit) {
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

        // Check if we should include pages/posts in search
        $sync_pages = get_option('ssw_sync_pages', 0);
        $sync_posts = get_option('ssw_sync_posts', 0);

        // If pages or posts are enabled, use the general search method
        if ($sync_pages || $sync_posts) {
            $this->intercept_general_search($query, $search_term);
        } else {
            // Otherwise use the original product-only search
            $this->intercept_product_search($query, $search_term);
        }
    }

    private function intercept_product_search(\WP_Query $query, string $search_term): void {
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

    private function intercept_general_search(\WP_Query $query, string $search_term): void {
        // Call your semantic search API for all content types
        $results = $this->api->search_all($search_term);

        // Empty response → API failed → let WordPress handle it normally
        if (empty($results)) {
            error_log('[SSW] Falling back to default WordPress search');
            return;
        }

        // Separate results by content type
        $product_ids = [];
        $page_ids = [];
        $post_ids = [];

        foreach ($results as $result) {
            $content_type = $result['content_type'] ?? 'product';
            if ($content_type === 'product' && isset($result['product_id'])) {
                $product_ids[] = (int) $result['product_id'];
            } elseif ($content_type === 'page' && isset($result['page_id'])) {
                $page_ids[] = (int) $result['page_id'];
            } elseif ($content_type === 'post' && isset($result['post_id'])) {
                $post_ids[] = (int) $result['post_id'];
            }
        }

        // Build combined post IDs array
        $all_ids = array_merge($product_ids, $page_ids, $post_ids);

        if (empty($all_ids)) {
            return;
        }

        // Set post types based on what we have results for
        $post_types = [];
        if (!empty($product_ids)) {
            $post_types[] = 'product';
        }
        if (!empty($page_ids)) {
            $post_types[] = 'page';
        }
        if (!empty($post_ids)) {
            $post_types[] = 'post';
        }

        // Configure the query to show only the results we got from semantic search
        $query->set('post__in', $all_ids);
        $query->set('post_type', $post_types);
        $query->set('orderby', 'post__in');  // preserve ranking order
        $query->set('posts_per_page', count($all_ids));

        // Remove default keyword filtering — we've already done it
        $query->set('s', '');
    }
}