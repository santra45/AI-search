<?php
if (!defined('ABSPATH')) exit;

class SSW_Admin {

    public function __construct() {
        add_action('admin_menu',            [$this, 'add_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);

        // AJAX handlers
        add_action('wp_ajax_ssw_dashboard_stats',   [$this, 'ajax_dashboard_stats']);
        add_action('wp_ajax_ssw_analytics_data',    [$this, 'ajax_analytics_data']);
        add_action('wp_ajax_ssw_test_connection',   [$this, 'ajax_test_connection']);
        add_action('wp_ajax_ssw_save_settings',     [$this, 'ajax_save_settings']);
        add_action('wp_ajax_ssw_register_webhooks', [$this, 'ajax_register_webhooks']);
        add_action('wp_ajax_ssw_start_sync',        [$this, 'ajax_start_sync']);
        add_action('wp_ajax_ssw_next_batch',        [$this, 'ajax_next_batch']);
        add_action('wp_ajax_ssw_status_check',      [$this, 'ajax_status_check']);
        add_action('wp_ajax_ssw_reset_sync', [$this, 'ajax_reset_sync']);
    }


    // ── Menu ───────────────────────────────────────────────────────────────────

    public function add_menu(): void {
        add_menu_page(
            'Semantic Search',
            'Semantic Search',
            'manage_options',
            'semantic-search',
            [$this, 'render_page'],
            'dashicons-search',
            58
        );
    }


    // ── Assets ─────────────────────────────────────────────────────────────────

    public function enqueue_assets(string $hook): void {
        if ($hook !== 'toplevel_page_semantic-search') return;

        // Chart.js from CDN
        wp_enqueue_script(
            'chartjs',
            'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js',
            [],
            '4.4.0',
            true
        );

        // Plugin CSS
        wp_enqueue_style(
            'ssw-admin',
            SSW_PLUGIN_URL . 'admin/assets/css/admin.css',
            [],
            SSW_VERSION
        );

        // Plugin JS
        wp_enqueue_script(
            'ssw-admin',
            SSW_PLUGIN_URL . 'admin/assets/js/admin.js',
            ['jquery', 'chartjs'],
            SSW_VERSION,
            true
        );

        // Pass config to JS
        $sync        = new SSW_Sync(get_option('ssw_license_key', ''));
        $progress    = $sync->get_progress();
        $license_key = get_option('ssw_license_key', '');

        wp_localize_script('ssw-admin', 'SSW_Config', [
            'nonce'               => wp_create_nonce('ssw_nonce'),
            'ajaxurl'             => admin_url('admin-ajax.php'),
            'current_license_key' => $license_key,
            'sync_running'        => $progress['status'] === 'running',
            'plugin_url'          => SSW_PLUGIN_URL,
        ]);
    }


    // ── Page Renderer ──────────────────────────────────────────────────────────

    public function render_page(): void {
        ?>
        <div id="ssw-wrap" class="wrap">

            <h1>
                ⚡ Semantic Search
                <span class="ssw-badge plan" style="font-size:12px;margin-left:8px;">
                    v<?= SSW_VERSION ?>
                </span>
            </h1>

            <!-- Nav Tabs -->
            <div class="ssw-nav-tabs">
                <a href="#dashboard" class="active">📊 Dashboard</a>
                <a href="#analytics">📈 Analytics</a>
                <a href="#settings">⚙️ Settings</a>
                <a href="#status">🔌 Status</a>
            </div>

            <!-- Tab Panels -->
            <?php
            require_once SSW_PLUGIN_DIR . 'admin/views/page-dashboard.php';
            require_once SSW_PLUGIN_DIR . 'admin/views/page-analytics.php';
            require_once SSW_PLUGIN_DIR . 'admin/views/page-settings.php';
            require_once SSW_PLUGIN_DIR . 'admin/views/page-status.php';
            ?>

        </div>
        <?php
    }


    // ── AJAX: Dashboard Stats ──────────────────────────────────────────────────

    public function ajax_dashboard_stats(): void {
        check_ajax_referer('ssw_nonce', 'nonce');

        $license_key = get_option('ssw_license_key', '');
        if (empty($license_key)) {
            wp_send_json_error(['message' => 'License key not set']);
        }

        $api_url  = get_option('ssw_api_url', '');
        $response = wp_remote_get(
            $api_url . '/api/dashboard/stats',
            [
                'timeout' => 10,
                'headers' => [
                    'Authorization' => 'Bearer ' . $license_key
                ]
            ]
        );

        if (is_wp_error($response)) {
            wp_send_json_error(['message' => $response->get_error_message()]);
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200) {
            wp_send_json_error(['message' => $body['detail'] ?? 'API error']);
        }

        wp_send_json_success($body);
    }


    // ── AJAX: Analytics Data ───────────────────────────────────────────────────

    public function ajax_analytics_data(): void {
        check_ajax_referer('ssw_nonce', 'nonce');

        $license_key = get_option('ssw_license_key', '');
        $api_url     = get_option('ssw_api_url', '');
        $days        = (int) ($_POST['days'] ?? 7);

        $base = $api_url . '/api/analytics/';
        $key  = '?days=' . $days;

        // Fetch all three endpoints in parallel using WordPress HTTP API
        $endpoints = [
            'summary'      => $base . 'summary'      . $key,
            'top_queries'  => $base . 'top-queries'  . $key,
            'zero_results' => $base . 'zero-results' . $key,
        ];

        $results = [];
        foreach ($endpoints as $key_name => $url) {
            $res = wp_remote_get($url, [
                'timeout' => 10,
                'headers' => [
                    'Authorization' => 'Bearer ' . $license_key
                ]
            ]);
            if (!is_wp_error($res) && wp_remote_retrieve_response_code($res) === 200) {
                $results[$key_name] = json_decode(wp_remote_retrieve_body($res), true);
            } else {
                $results[$key_name] = [];
            }
        }

        wp_send_json_success([
            'summary'      => $results['summary'],
            'top_queries'  => $results['top_queries']['queries']  ?? [],
            'zero_results' => $results['zero_results']['queries'] ?? [],
        ]);
    }


    // ── AJAX: Test Connection ──────────────────────────────────────────────────

    public function ajax_test_connection(): void {
        check_ajax_referer('ssw_nonce', 'nonce');

        $api_url     = get_option('ssw_api_url', '');
        $license_key = get_option('ssw_license_key', '');

        if (empty($api_url) || empty($license_key)) {
            wp_send_json_error(['message' => 'API URL or License Key not set']);
        }

        $start    = microtime(true);
        $response = wp_remote_post(
            $api_url . '/api/search',
            [
                'timeout' => 6,
                'headers' => ['Content-Type' => 'application/json'],
                'body'    => json_encode([
                    'license_key' => $license_key,
                    'query'       => 'test connection',
                    'limit'       => 1
                ])
            ]
        );
        $ms = round((microtime(true) - $start) * 1000);

        if (is_wp_error($response)) {
            wp_send_json_error(['message' => $response->get_error_message()]);
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code === 200) {
            wp_send_json_success([
                'message' => "Connected in {$ms}ms — API is reachable"
            ]);
        } elseif ($code === 403) {
            wp_send_json_error(['message' => 'Invalid license key']);
        } else {
            wp_send_json_error(['message' => "API returned HTTP {$code}"]);
        }
    }

    // ── AJAX: Save Settings ────────────────────────────────────────────────────

    public function ajax_save_settings(): void {
        check_ajax_referer('ssw_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        $api_url      = sanitize_text_field($_POST['api_url']      ?? '');
        $license_key  = sanitize_text_field($_POST['license_key']  ?? '');
        $result_limit = (int) ($_POST['result_limit'] ?? 10);
        $wc_key       = sanitize_text_field($_POST['wc_key']       ?? '');
        $wc_secret    = sanitize_text_field($_POST['wc_secret']    ?? '');
        $enable_intent = (isset($_POST['enable_intent']) && (int)$_POST['enable_intent'] === 1) ? 1 : 0;

        if (empty($api_url)) {
            wp_send_json_error(['message' => 'API URL is required']);
        }

        update_option('ssw_api_url',      rtrim($api_url, '/'));
        update_option('ssw_result_limit', max(1, min(50, $result_limit)));
        update_option('ssw_enable_intent', $enable_intent);

        // Always update the license key option (even if empty to clear it)
        update_option('ssw_license_key', $license_key);
        if (!empty($wc_key)) {
            update_option('ssw_wc_key',    $wc_key);
        }
        if (!empty($wc_secret)) {
            update_option('ssw_wc_secret', $wc_secret);
        }

        wp_send_json_success(['message' => 'Settings saved']);
    }


    // ── AJAX: Register Webhooks ────────────────────────────────────────────────

    public function ajax_register_webhooks(): void {
        check_ajax_referer('ssw_nonce', 'nonce');

        $license_key = get_option('ssw_license_key', '');
        $wc_key      = get_option('ssw_wc_key',      '');
        $wc_secret   = get_option('ssw_wc_secret',   '');

        if (empty($license_key)) {
            wp_send_json_error(['message' => 'License key not set']);
        }

        if (empty($wc_key) || empty($wc_secret)) {
            wp_send_json_error([
                'message' => 'WooCommerce Consumer Key and Secret are required. ' .
                            'Add them in Settings before registering webhooks.'
            ]);
        }

        $manager = new SSW_Webhook_Manager($license_key);
        $result  = $manager->register();

        if ($result['success']) {
            wp_send_json_success([
                'registered' => $result['registered'],
                'message'    => $result['registered'] . ' webhooks registered'
            ]);
        } else {
            wp_send_json_error(['message' => $result['message']]);
        }
    }


    // ── AJAX: Start Sync ───────────────────────────────────────────────────────

    public function ajax_start_sync(): void {
        check_ajax_referer('ssw_nonce', 'nonce');

        $license_key = get_option('ssw_license_key', '');
        if (empty($license_key)) {
            wp_send_json_error(['message' => 'License key not set']);
        }

        $sync   = new SSW_Sync($license_key);
        $result = $sync->start_sync();

        wp_send_json_success($result);
    }


    // ── AJAX: Next Batch ───────────────────────────────────────────────────────

    public function ajax_next_batch(): void {
        check_ajax_referer('ssw_nonce', 'nonce');

        $license_key = get_option('ssw_license_key', '');
        $sync        = new SSW_Sync($license_key);
        $result      = $sync->process_next_batch();

        wp_send_json_success($result);
    }


    // ── AJAX: Status Check ─────────────────────────────────────────────────────

    public function ajax_status_check(): void {
        check_ajax_referer('ssw_nonce', 'nonce');

        $api_url     = get_option('ssw_api_url', '');
        $license_key = get_option('ssw_license_key', '');

        if (empty($api_url) || empty($license_key)) {
            wp_send_json_error(['message' => 'API URL or License Key not set']);
        }

        $response = wp_remote_get(
            $api_url . '/api/status',
            [
                'timeout' => 8,
                'headers' => [
                    'Authorization' => 'Bearer ' . $license_key
                ]
            ]
        );

        if (is_wp_error($response)) {
            wp_send_json_success([
                'api_reachable' => false,
                'license_valid' => false,
                'client_name'   => '',
                'plan'          => '',
                'domain'        => '',
                'indexed_count' => 0,
                'webhooks_ok'   => false,
                'search_active' => false
            ]);
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200) {
            wp_send_json_success([
                'api_reachable' => true,
                'license_valid' => false,
                'client_name'   => '',
                'plan'          => '',
                'domain'        => '',
                'indexed_count' => 0,
                'webhooks_ok'   => false,
                'search_active' => false
            ]);
        }

        // Check webhooks registered
        $webhooks_ok = $this->check_webhooks_registered();

        wp_send_json_success([
            'api_reachable' => true,
            'license_valid' => true,
            'client_name'   => $body['client_name']   ?? '',
            'plan'          => $body['plan']           ?? '',
            'domain'        => $body['domain']         ?? '',
            'indexed_count' => $body['indexed_count']  ?? 0,
            'webhooks_ok'   => $webhooks_ok,
            'search_active' => !empty(get_option('ssw_license_key'))
                               && !empty(get_option('ssw_api_url'))
        ]);
    }


    // ── Helpers ────────────────────────────────────────────────────────────────

    private function check_webhooks_registered(): bool {
        $registered = get_option('ssw_webhooks_registered', []);
        return count($registered) >= 3;
    }

    /**
     * Reset sync state
     */
    public function ajax_reset_sync(): void {
        check_ajax_referer('ssw_nonce', 'nonce');

        $sync = new SSW_Sync(get_option('ssw_license_key', ''));
        $sync->reset();

        wp_send_json_success(['message' => 'Sync state reset']);
    }
}