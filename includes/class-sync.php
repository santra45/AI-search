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
        $total = $this->get_total_products();
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

    public function start_sync(): array {
        $total       = $this->get_total_products();
        $total_batch = (int) ceil($total / $this->batch_size);

        // Save sync state to WP options
        update_option('ssw_sync_status',       'running');
        update_option('ssw_sync_total',         $total);
        update_option('ssw_sync_total_batches', $total_batch);
        update_option('ssw_sync_current_batch', 0);
        update_option('ssw_sync_processed',     0);
        update_option('ssw_sync_failed',        0);
        update_option('ssw_sync_started_at',    time());

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

        // Fetch next batch of products
        $next_batch  = $current_batch + 1;
        $offset      = $current_batch * $this->batch_size;
        $products    = $this->fetch_products($offset, $this->batch_size);

        if (empty($products)) {
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
        $formatted = array_map([$this, 'format_product'], $products);
        $formatted = array_filter($formatted); // remove nulls

        // Send to your FastAPI
        $result = $this->send_batch(
            array_values($formatted),
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
    private function send_batch(array $products, int $batch_num, int $total_batches): array {
        $api_url = get_option('ssw_api_url');
        $encrypted_key = get_option('ssw_llm_api_key', '');

        $response = wp_remote_post($api_url . '/api/sync/batch', [
            'timeout' => 120,    // 2 min per batch — embedding takes time
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => json_encode([
                'license_key'   => $this->license_key,
                'products'      => $products,
                'batch_number'  => $batch_num,
                'total_batches' => $total_batches,
                'llm_api_key_encrypted' => $encrypted_key
            ])
        ]);

        if (is_wp_error($response)) {
            error_log('[SSW Sync] Batch failed: ' . $response->get_error_message());
            return ['success_count' => 0, 'failed_count' => count($products)];
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

    public function reset(): void {
        update_option('ssw_sync_status',        'idle');
        update_option('ssw_sync_total',          0);
        update_option('ssw_sync_total_batches',  0);
        update_option('ssw_sync_current_batch',  0);
        update_option('ssw_sync_processed',      0);
        update_option('ssw_sync_failed',         0);
    }
}