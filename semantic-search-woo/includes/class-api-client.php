<?php
if (!defined('ABSPATH')) exit;

class SSW_API_Client {

    private string $api_url;
    private string $license_key;
    private int    $limit;

    public function __construct(string $api_url, string $license_key, int $limit = 10) {
        $this->api_url   = rtrim($api_url, '/');
        $this->license_key = $license_key;
        $this->limit     = $limit;
    }

    /**
     * Search products via your FastAPI.
     * Returns array of WooCommerce product IDs, ranked by relevance.
     * Returns empty array on any failure (triggers fallback).
     */
    public function search(string $query): array {
        $enable_intent = get_option('ssw_enable_intent', 0);
        $llm_provider = get_option('ssw_llm_provider', '');
        $llm_model = get_option('ssw_llm_model', '');
        $encrypted_key = get_option('ssw_llm_api_key', '');
        if ($llm_provider && $llm_model && $encrypted_key) {
            $payload['llm_provider'] = $llm_provider;
            $payload['llm_model'] = $llm_model;
            $payload['llm_api_key_encrypted'] = $encrypted_key;
        }
        
        $payload = [
            'license_key' => $this->license_key,
            'query'     => $query,
            'limit'     => $this->limit
        ];
        
        // Only include intent setting if enabled
        if ($enable_intent) {
            $payload['enable_intent'] = true;
        }
        
        // Include LLM configuration if set
        if ($llm_provider && $llm_model && $encrypted_key) {
            $payload['llm_provider'] = $llm_provider;
            $payload['llm_model'] = $llm_model;
            $payload['llm_api_key_encrypted'] = $encrypted_key;
        }
        
        $response = wp_remote_post($this->api_url . '/api/search', [
            'timeout' => 10,      // 10 seconds max — fallback if slow
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => json_encode($payload)
        ]);

        // Network error or timeout → return empty (fallback to native search)
        if (is_wp_error($response)) {
            error_log('[SSW] API error: ' . $response->get_error_message());
            return [];
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            error_log('[SSW] API returned HTTP ' . $code);
            return [];
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (empty($body['results'])) {
            return [];
        }

        // Return only the WooCommerce product IDs in ranked order
        return array_map(
            fn($r) => (int) $r['product_id'],
            $body['results']
        );
    }
}