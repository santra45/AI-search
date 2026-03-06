<?php
if (!defined('ABSPATH')) exit;

class SSW_Webhook_Manager {

    private string $license_key;
    private string $api_url;
    private string $wh_secret;

    // WooCommerce REST API credentials
    private string $wc_key;
    private string $wc_secret;
    private string $wc_url;

    public function __construct(string $license_key) {
        $this->license_key = $license_key;
        $this->api_url     = get_option('ssw_api_url', '');
        $this->wh_secret   = get_option('ssw_webhook_secret', $this->generate_secret());

        // WooCommerce uses the site URL for REST API
        $this->wc_url    = get_site_url();
        $this->wc_key    = get_option('ssw_wc_key',    '');
        $this->wc_secret = get_option('ssw_wc_secret', '');
    }


    // ── Public API ─────────────────────────────────────────────────────────────

    /**
     * Register all three webhooks with WooCommerce.
     * Called when license key is saved in settings.
     */
    public function register(): array {
        if (empty($this->api_url) || empty($this->license_key)) {
            return [
                'success'    => false,
                'registered' => 0,
                'message'    => 'API URL or license key not set'
            ];
        }

        // Get client_id from license key via API
        $client_id = $this->get_client_id();
        if (!$client_id) {
            return [
                'success'    => false,
                'registered' => 0,
                'message'    => 'Could not validate license key'
            ];
        }

        // Delete old webhooks first to avoid duplicates
        $this->delete_existing();

        $topics = [
            'product.created' => 'product-created',
            'product.updated' => 'product-updated',
            'product.deleted' => 'product-deleted',
        ];

        $registered = [];
        $failed     = [];

        foreach ($topics as $topic => $endpoint) {
            $delivery_url = $this->api_url
                . '/api/webhook/' . $endpoint
                . '?client_id=' . urlencode($client_id);

            $result = $this->create_webhook(
                name:         'Semantic Search — ' . ucfirst(str_replace('.', ' ', $topic)),
                topic:        $topic,
                delivery_url: $delivery_url
            );

            if ($result['success']) {
                $registered[] = [
                    'id'    => $result['id'],
                    'topic' => $topic,
                    'url'   => $delivery_url
                ];
            } else {
                $failed[] = ['topic' => $topic, 'error' => $result['message']];
            }
        }

        // Store registered webhook IDs for status checking + cleanup
        update_option('ssw_webhooks_registered', $registered);
        update_option('ssw_webhook_secret',      $this->wh_secret);

        if (count($registered) === count($topics)) {
            return [
                'success'    => true,
                'registered' => count($registered),
                'message'    => 'All webhooks registered successfully'
            ];
        }

        return [
            'success'    => count($registered) > 0,
            'registered' => count($registered),
            'failed'     => $failed,
            'message'    => count($registered) . ' of ' . count($topics) . ' webhooks registered'
        ];
    }


    /**
     * Delete all webhooks registered by this plugin.
     * Called on deactivation and before re-registering.
     */
    public function delete_existing(): void {
        $registered = get_option('ssw_webhooks_registered', []);

        if (empty($registered)) {
            // Try fetching all webhooks and delete ours by name pattern
            $this->delete_by_name_pattern('Semantic Search');
            return;
        }

        foreach ($registered as $wh) {
            if (!empty($wh['id'])) {
                $this->delete_webhook((int) $wh['id']);
            }
        }

        update_option('ssw_webhooks_registered', []);
    }


    /**
     * Check if all three webhooks are active.
     */
    public function check_status(): array {
        $registered = get_option('ssw_webhooks_registered', []);

        if (count($registered) < 3) {
            return [
                'all_active' => false,
                'count'      => count($registered),
                'webhooks'   => $registered
            ];
        }

        // Verify each webhook still exists in WooCommerce
        $active = 0;
        foreach ($registered as $wh) {
            if ($this->webhook_exists((int) $wh['id'])) {
                $active++;
            }
        }

        return [
            'all_active' => $active === 3,
            'count'      => $active,
            'webhooks'   => $registered
        ];
    }


    // ── WooCommerce REST API Calls ──────────────────────────────────────────────

    private function create_webhook(
        string $name,
        string $topic,
        string $delivery_url
    ): array {

        $response = $this->wc_request('POST', 'webhooks', [
            'name'         => $name,
            'topic'        => $topic,
            'delivery_url' => $delivery_url,
            'secret'       => $this->wh_secret,
            'status'       => 'active'
        ]);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'message' => $response->get_error_message()
            ];
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (in_array($code, [200, 201])) {
            return [
                'success' => true,
                'id'      => $body['id'],
                'message' => 'Created'
            ];
        }

        return [
            'success' => false,
            'message' => $body['message'] ?? "HTTP {$code}"
        ];
    }


    private function delete_webhook(int $id): void {
        $this->wc_request('DELETE', "webhooks/{$id}", ['force' => true]);
    }


    private function webhook_exists(int $id): bool {
        $response = $this->wc_request('GET', "webhooks/{$id}");

        if (is_wp_error($response)) return false;

        return wp_remote_retrieve_response_code($response) === 200;
    }


    private function delete_by_name_pattern(string $pattern): void {
        $response = $this->wc_request('GET', 'webhooks', ['per_page' => 50]);

        if (is_wp_error($response)) return;

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (!is_array($body)) return;

        foreach ($body as $wh) {
            if (str_contains($wh['name'] ?? '', $pattern)) {
                $this->delete_webhook((int) $wh['id']);
            }
        }
    }


    // ── License Key → Client ID ────────────────────────────────────────────────

    private function get_client_id(): ?string {
        $response = wp_remote_get(
            $this->api_url . '/api/status?license_key=' . urlencode($this->license_key),
            ['timeout' => 8]
        );

        if (is_wp_error($response)) return null;

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200) return null;

        return $body['client_id'] ?? null;
    }


    // ── WooCommerce API Helper ─────────────────────────────────────────────────

    private function wc_request(string $method, string $endpoint, array $data = []) {
        // Require WC credentials — no silent fallback
        if (empty($this->wc_key) || empty($this->wc_secret)) {
            return new WP_Error(
                'ssw_missing_credentials',
                'WooCommerce Consumer Key and Secret are required. ' .
                'Add them in Semantic Search → Settings.'
            );
        }

        $url = $this->wc_url . '/wp-json/wc/v3/' . $endpoint;

        $args = [
            'method'  => $method,
            'timeout' => 15,
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Basic ' . base64_encode(
                    $this->wc_key . ':' . $this->wc_secret
                )
            ]
        ];

        if ($method === 'GET' && !empty($data)) {
            $url = add_query_arg($data, $url);
        } elseif (!empty($data)) {
            $args['body'] = json_encode($data);
        }

        return wp_remote_request($url, $args);
    }


    // ── Secret Generator ───────────────────────────────────────────────────────

    private function generate_secret(): string {
        $secret = bin2hex(random_bytes(16));
        update_option('ssw_webhook_secret', $secret);
        return $secret;
    }
}