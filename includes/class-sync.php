<?php
if (!defined('ABSPATH')) exit;

class SSW_Sync {

    private SSW_API_Client $api;
    private string         $license_key;
    private int            $batch_size;

    public function __construct(string $license_key) {
        $this->license_key = $license_key;
        $this->api         = new SSW_API_Client(
            get_option('ssw_api_url'),
            $license_key
        );
        $this->batch_size  = $this->get_batch_size();
    }

    private function get_batch_size(): int {
        $sync_pages = get_option('ssw_sync_pages', 0);
        $sync_posts = get_option('ssw_sync_posts', 0);
        
        $total = $this->get_total_products();
        if ($sync_pages) {
            $total += $this->get_total_pages();
        }
        if ($sync_posts) {
            $total += $this->get_total_posts();
        }
        
        if ($total >= 1000) return 50;
        if ($total >= 200)  return 20;
        return 10;
    }

    public function get_total_products(): int {
        $result = wc_get_products([
            'status' => 'publish',
            'limit'  => 1,
            'return' => 'ids',
            'type'   => ['simple', 'variable']
        ]);
        $query = new WP_Query([
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'tax_query'      => []
        ]);
        return (int) $query->found_posts;
    }

    public function get_total_pages(): int {
        $query = new WP_Query([
            'post_type'      => 'page',
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'fields'         => 'ids'
        ]);
        return (int) $query->found_posts;
    }

    public function get_total_posts(): int {
        $query = new WP_Query([
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'fields'         => 'ids'
        ]);
        return (int) $query->found_posts;
    }

    public function start_sync(): array {
        $sync_pages = get_option('ssw_sync_pages', 0);
        $sync_posts = get_option('ssw_sync_posts', 0);
        
        $total = $this->get_total_products();
        if ($sync_pages) {
            $total += $this->get_total_pages();
        }
        if ($sync_posts) {
            $total += $this->get_total_posts();
        }
        
        $total_batch = (int) ceil($total / $this->batch_size);

        // Save sync state to WP options
        update_option('ssw_sync_status',       'running');
        update_option('ssw_sync_total',         $total);
        update_option('ssw_sync_total_batches', $total_batch);
        update_option('ssw_sync_current_batch', 0);
        update_option('ssw_sync_processed',     0);
        update_option('ssw_sync_failed',        0);
        update_option('ssw_sync_started_at',    time());
        update_option('ssw_sync_content_type',  'all'); // Track what we're syncing

        return [
            'status'        => 'started',
            'total'         => $total,
            'total_batches' => $total_batch,
            'batch_size'    => $this->batch_size
        ];
    }

    public function process_next_batch(): array {
        $status        = get_option('ssw_sync_status', 'idle');
        $current_batch = (int) get_option('ssw_sync_current_batch', 0);
        $total_batches = (int) get_option('ssw_sync_total_batches', 0);
        $processed     = (int) get_option('ssw_sync_processed', 0);
        $failed        = (int) get_option('ssw_sync_failed', 0);
        $total         = (int) get_option('ssw_sync_total', 0);
        $sync_pages    = get_option('ssw_sync_pages', 0);
        $sync_posts    = get_option('ssw_sync_posts', 0);

        if ($status !== 'running') {
            return ['status' => $status, 'message' => 'No sync running'];
        }

        if ($current_batch >= $total_batches) {
            update_option('ssw_sync_status', 'complete');
            return [
                'status'     => 'complete',
                'processed'  => $processed,
                'failed'     => $failed,
                'total'      => $total,
                'percentage' => 100
            ];
        }

        // Fetch next batch of content based on what's enabled
        $next_batch  = $current_batch + 1;
        $offset      = $current_batch * $this->batch_size;
        
        $products = $this->fetch_products($offset, $this->batch_size);
        $pages    = $sync_pages ? $this->fetch_pages($offset, $this->batch_size) : [];
        $posts    = $sync_posts ? $this->fetch_posts($offset, $this->batch_size) : [];

        $total_items = count($products) + count($pages) + count($posts);

        if ($total_items === 0) {
            update_option('ssw_sync_status', 'complete');
            return [
                'status'     => 'complete',
                'processed'  => $processed,
                'failed'     => $failed,
                'total'      => $total,
                'percentage' => 100
            ];
        }

        // Format for API
        $formatted_products = array_filter(array_map([$this, 'format_product'], $products));
        $formatted_pages    = $sync_pages ? array_filter(array_map([$this, 'format_page'], $pages)) : [];
        $formatted_posts    = $sync_posts ? array_filter(array_map([$this, 'format_post'], $posts)) : [];

        // Send to your FastAPI
        $result = $this->send_batch(
            array_values($formatted_products),
            array_values($formatted_pages),
            array_values($formatted_posts),
            $next_batch,
            $total_batches
        );

        // Update progress
        $new_processed = $processed + $result['success_count'];
        $new_failed    = $failed    + $result['failed_count'];

        update_option('ssw_sync_current_batch', $next_batch);
        update_option('ssw_sync_processed',     $new_processed);
        update_option('ssw_sync_failed',        $new_failed);

        $percentage = $total > 0
            ? (int) round(($new_processed / $total) * 100)
            : 0;

        $is_complete = $next_batch >= $total_batches;
        if ($is_complete) {
            update_option('ssw_sync_status', 'complete');
        }

        return [
            'status'        => $is_complete ? 'complete' : 'running',
            'current_batch' => $next_batch,
            'total_batches' => $total_batches,
            'processed'     => $new_processed,
            'failed'        => $new_failed,
            'total'         => $total,
            'percentage'    => min($percentage, 100)
        ];
    }

    private function fetch_products(int $offset, int $limit): array {
        return wc_get_products([
            'status'  => 'publish',
            'limit'   => $limit,
            'offset'  => $offset,
            'type'    => ['simple', 'variable'],
            'return'  => 'objects'
        ]);
    }

    private function fetch_pages(int $offset, int $limit): array {
        $query = new WP_Query([
            'post_type'      => 'page',
            'post_status'    => 'publish',
            'posts_per_page' => $limit,
            'offset'         => $offset,
            'fields'         => 'ids'
        ]);
        return $query->posts;
    }

    private function fetch_posts(int $offset, int $limit): array {
        $query = new WP_Query([
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => $limit,
            'offset'         => $offset,
            'fields'         => 'ids'
        ]);
        return $query->posts;
    }

    private function extract_gender(array $tags): string {
        if (empty($tags)) return '';

        $map = [
            'Men'   => ['men', 'male', 'gents'],
            'Women' => ['women', 'female', 'ladies'],
            'Kids'  => ['kids', 'child', 'boy', 'girl']
        ];

        foreach ($tags as $tag) {
            // Just in case koi object pass ho jaye, handle it
            $tagName = is_object($tag) ? $tag->name : (string)$tag;
            $tagName = strtolower($tagName);

            foreach ($map as $gender => $keywords) {
                foreach ($keywords as $keyword) {
                    if (strpos($tagName, $keyword) !== false) {
                        return $gender;
                    }
                }
            }
        }

        return '';
    }

    private function format_product($product): ?array {
        if (!$product) return null;
        
        $terms = wc_get_product_terms($product->get_id(), 'product_cat');
        $cats = [];
        if (!empty($terms)) {
            usort($terms, function($a, $b){
                return count(get_ancestors($b->term_id,'product_cat')) - count(get_ancestors($a->term_id,'product_cat'));
            });
            $term = $terms[0];
            $ancestors = array_reverse(get_ancestors($term->term_id,'product_cat'));
            foreach ($ancestors as $ancestor_id) {
                $ancestor = get_term($ancestor_id,'product_cat');
                if ($ancestor) {
                    $cats[] = $ancestor->name;
                }
            }
            $cats[] = $term->name;
        }
        $tags  = wp_get_post_terms($product->get_id(), 'product_tag', ['fields' => 'names']);
        $gender = $this->extract_gender($tags);
        $image = wp_get_attachment_url($product->get_image_id());
        $price = $product->get_price() ?: '0';
        $currency = get_woocommerce_currency();
        $currency_symbol = get_woocommerce_currency_symbol();
        $sku = $product->get_sku();
        if (!$sku && $product->is_type('variable')) {
            $children = $product->get_children();
            if (!empty($children)) {
                $variation = wc_get_product($children[0]);
                if ($variation) {
                    $sku = $variation->get_sku();
                }
            }
        }
        $brand = '';
        $brand_terms = wp_get_post_terms($product->get_id(), 'product_brand', ['fields' => 'names']);
        if (!empty($brand_terms)) {
            $brand = implode(', ', $brand_terms);
        }

        if (!$brand) {
            $brand = $product->get_attribute('pa_brand');
        }

        // ── Build attributes array ─────────────────────────────────────────────
        $attributes = [];
        foreach ($product->get_attributes() as $attr_key => $attr) {
            $attr_name = wc_attribute_label($attr_key, $product);
            $options   = [];

            if ($attr->is_taxonomy()) {
                $terms = wc_get_product_terms(
                    $product->get_id(),
                    $attr->get_name(),
                    ['fields' => 'names']
                );
                $options = is_array($terms) ? $terms : [];
            } else {
                $options = $attr->get_options();
            }

            if (!empty($options)) {
                $attributes[] = [
                    'name'    => $attr_name,
                    'options' => $options
                ];
            }
        }

        return [
            'sku'               => $sku,
            'product_id'        => (string) $product->get_id(),
            'name'              => $product->get_name(),
            'categories'        => implode(' > ', $cats),
            'tags'              => implode(' ', is_array($tags) ? $tags : []),
            'gender'            => $gender,
            'description'       => $product->get_description(),
            'brand'             => $brand,
            'short_description' => $product->get_short_description(),
            'price'             => (float) $price,
            'currency'          => $currency,
            'currency_symbol'   => $currency_symbol,
            'regular_price'     => (float) ($product->get_regular_price() ?: $price),
            'sale_price'        => (float) ($product->get_sale_price() ?: 0),
            'on_sale'           => (bool)  $product->is_on_sale(),
            'permalink'         => get_permalink($product->get_id()),
            'image_url'         => $image ?: '',
            'stock_status'      => $product->get_stock_status(),
            'average_rating'    => (float) $product->get_average_rating(),
            'attributes'        => $attributes   // ← now included
        ];
    }

    private function format_page($page): ?array {
        if (!$page) return null;
        
        $author_id = get_post_field('post_author', $page);
        $author = get_the_author_meta('display_name', $author_id);
        
        return [
            'page_id'   => (string) $page,
            'title'     => get_the_title($page),
            'content'   => wp_strip_all_tags(get_post_field('post_content', $page)),
            'excerpt'   => get_the_excerpt($page),
            'permalink' => get_permalink($page),
            'author'    => $author,
            'date'      => get_post_field('post_date', $page),
            'status'    => 'publish'
        ];
    }

    private function format_post($post): ?array {
        if (!$post) return null;
        
        $author_id = get_post_field('post_author', $post);
        $author = get_the_author_meta('display_name', $author_id);
        
        $categories = wp_get_post_categories($post, ['fields' => 'names']);
        $tags = wp_get_post_tags($post, ['fields' => 'names']);
        
        return [
            'post_id'    => (string) $post,
            'title'      => get_the_title($post),
            'content'    => wp_strip_all_tags(get_post_field('post_content', $post)),
            'excerpt'    => get_the_excerpt($post),
            'permalink'  => get_permalink($post),
            'author'     => $author,
            'date'       => get_post_field('post_date', $post),
            'categories' => implode(', ', $categories),
            'tags'       => implode(', ', $tags),
            'status'     => 'publish'
        ];
    }

    private function send_batch(array $products, array $pages, array $posts, int $batch_num, int $total_batches): array {
        $api_url = get_option('ssw_api_url');
        $encrypted_key = get_option('ssw_llm_api_key', '');

        $payload = [
            'license_key'   => $this->license_key,
            'products'      => $products,
            'pages'         => $pages,
            'posts'         => $posts,
            'batch_number'  => $batch_num,
            'total_batches' => $total_batches,
            'llm_api_key_encrypted' => $encrypted_key
        ];

        $response = wp_remote_post($api_url . '/api/sync/batch', [
            'timeout' => 120,    // 2 min per batch — embedding takes time
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => json_encode($payload)
        ]);

        $total_items = count($products) + count($pages) + count($posts);

        if (is_wp_error($response)) {
            error_log('[SSW Sync] Batch failed: ' . $response->get_error_message());
            return ['success_count' => 0, 'failed_count' => $total_items];
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        return [
            'success_count' => $body['success_count'] ?? 0,
            'failed_count'  => $body['failed_count']  ?? 0
        ];
    }

    public function get_progress(): array {
        return [
            'status'        => get_option('ssw_sync_status', 'idle'),
            'total'         => (int) get_option('ssw_sync_total', 0),
            'processed'     => (int) get_option('ssw_sync_processed', 0),
            'failed'        => (int) get_option('ssw_sync_failed', 0),
            'total_batches' => (int) get_option('ssw_sync_total_batches', 0),
            'current_batch' => (int) get_option('ssw_sync_current_batch', 0),
            'percentage'    => $this->calculate_percentage(),
            'started_at'    => get_option('ssw_sync_started_at', 0)
        ];
    }

    private function calculate_percentage(): int {
        $total     = (int) get_option('ssw_sync_total', 0);
        $processed = (int) get_option('ssw_sync_processed', 0);
        if ($total === 0) return 0;
        return min((int) round(($processed / $total) * 100), 100);
    }

    public function cancel_sync(): array {
        $status = get_option('ssw_sync_status', 'idle');
        
        if ($status !== 'running') {
            return [
                'success' => false,
                'message' => 'No sync is currently running'
            ];
        }

        // Update sync status to cancelled
        update_option('ssw_sync_status', 'cancelled');
        
        // Get current progress for response
        $progress = $this->get_progress();
        
        return [
            'success' => true,
            'message' => 'Sync cancelled successfully',
            'processed' => $progress['processed'],
            'total' => $progress['total'],
            'percentage' => $progress['percentage']
        ];
    }

    public function reset(): void {
        update_option('ssw_sync_status',        'idle');
        update_option('ssw_sync_total',          0);
        update_option('ssw_sync_total_batches',  0);
        update_option('ssw_sync_current_batch',  0);
        update_option('ssw_sync_processed',      0);
        update_option('ssw_sync_failed',         0);
    }
}