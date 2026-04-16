<?php
if (!defined('ABSPATH')) exit;

class SSW_Admin {

    public function __construct() {
        add_action('admin_menu',            [$this, 'add_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);

        // AJAX handlers
        add_action('wp_ajax_ssw_dashboard_stats',   [$this, 'ajax_dashboard_stats']);
        add_action('wp_ajax_ssw_analytics_data',    [$this, 'ajax_analytics_data']);
        add_action('wp_ajax_ssw_token_usage_summary', [$this, 'ajax_token_usage_summary']);
        add_action('wp_ajax_ssw_token_usage_models',  [$this, 'ajax_token_usage_models']);
        add_action('wp_ajax_ssw_token_usage_hourly',  [$this, 'ajax_token_usage_hourly']);
        add_action('wp_ajax_ssw_token_usage_stats',   [$this, 'ajax_token_usage_stats']);
        add_action('wp_ajax_ssw_test_connection',   [$this, 'ajax_test_connection']);
        add_action('wp_ajax_ssw_save_settings',     [$this, 'ajax_save_settings']);
        add_action('wp_ajax_ssw_register_webhooks', [$this, 'ajax_register_webhooks']);
        add_action('wp_ajax_ssw_start_sync',        [$this, 'ajax_start_sync']);
        add_action('wp_ajax_ssw_next_batch',        [$this, 'ajax_next_batch']);
        add_action('wp_ajax_ssw_cancel_sync',       [$this, 'ajax_cancel_sync']);
        add_action('wp_ajax_ssw_status_check',      [$this, 'ajax_status_check']);
        add_action('wp_ajax_ssw_reset_sync', [$this, 'ajax_reset_sync']);
        
        // Setup page AJAX handler
        add_action('wp_ajax_ssw_complete_setup',    [$this, 'ajax_complete_setup']);
    }


    // ── Menu ───────────────────────────────────────────────────────────────────

    public function add_menu(): void {
        // Check if setup is completed
        $setup_completed = get_option('ssw_setup_completed', false);
        
        if (!$setup_completed) {
            // Show setup page only
            add_submenu_page(
                null, // Hide from menu
                'Semantic Search Setup',
                'Semantic Search Setup',
                'manage_options',
                'semantic-search-setup',
                [$this, 'render_setup_page']
            );
        } else {
            // Show full admin menu
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

        // Usage module JS
        wp_enqueue_script(
            'ssw-usage',
            SSW_PLUGIN_URL . 'admin/assets/js/usage.js',
            ['jquery', 'chartjs', 'ssw-admin'],
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
                <a href="#usage">💰 Usage</a>
                <a href="#settings">⚙️ Settings</a>
                <a href="#status">🔌 Status</a>
            </div>

            <!-- Tab Panels -->
            <?php
            require_once SSW_PLUGIN_DIR . 'admin/views/page-dashboard.php';
            require_once SSW_PLUGIN_DIR . 'admin/views/page-analytics.php';
            require_once SSW_PLUGIN_DIR . 'admin/views/page-usage.php';
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


    // ── AJAX: Token Usage Summary ─────────────────────────────────────────────

    public function ajax_token_usage_summary(): void {
        check_ajax_referer('ssw_nonce', 'nonce');

        $license_key = get_option('ssw_license_key', '');
        if (empty($license_key)) {
            wp_send_json_error(['message' => 'License key not set']);
        }

        $api_url = get_option('ssw_api_url', '');
        if (empty($api_url)) {
            wp_send_json_error(['message' => 'API URL not set']);
        }

        $response = wp_remote_get(
            rtrim($api_url, '/') . '/api/token-usage/me/summary',
            [
                'timeout' => 10,
                'headers' => [
                    'Authorization' => 'Bearer ' . $license_key,
                ],
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


    // ── AJAX: Token Usage Models ───────────────────────────────────────────────

    public function ajax_token_usage_models(): void {
        check_ajax_referer('ssw_nonce', 'nonce');

        $license_key = get_option('ssw_license_key', '');
        $api_url = get_option('ssw_api_url', '');

        if (empty($license_key) || empty($api_url)) {
            wp_send_json_error(['message' => 'License key or API URL not set']);
        }

        $response = wp_remote_get(
            rtrim($api_url, '/') . '/api/token-usage/me/models',
            [
                'timeout' => 10,
                'headers' => [
                    'Authorization' => 'Bearer ' . $license_key,
                ],
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


    // ── AJAX: Token Usage Hourly ───────────────────────────────────────────────

    public function ajax_token_usage_hourly(): void {
        check_ajax_referer('ssw_nonce', 'nonce');

        $license_key = get_option('ssw_license_key', '');
        $api_url = get_option('ssw_api_url', '');
        $hours_back = (int) ($_POST['hours_back'] ?? 24);

        if (empty($license_key) || empty($api_url)) {
            wp_send_json_error(['message' => 'License key or API URL not set']);
        }

        $response = wp_remote_get(
            rtrim($api_url, '/') . '/api/token-usage/me/hourly?hours_back=' . $hours_back,
            [
                'timeout' => 10,
                'headers' => [
                    'Authorization' => 'Bearer ' . $license_key,
                ],
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


    // ── AJAX: Token Usage Stats ────────────────────────────────────────────────

    public function ajax_token_usage_stats(): void {
        check_ajax_referer('ssw_nonce', 'nonce');

        $license_key = get_option('ssw_license_key', '');
        $api_url = get_option('ssw_api_url', '');

        if (empty($license_key) || empty($api_url)) {
            wp_send_json_error(['message' => 'License key or API URL not set']);
        }

        $response = wp_remote_get(
            rtrim($api_url, '/') . '/api/token-usage/me/stats',
            [
                'timeout' => 10,
                'headers' => [
                    'Authorization' => 'Bearer ' . $license_key,
                ],
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


    // ── AJAX: Test Connection ──────────────────────────────────────────────────

    public function ajax_test_connection(): void {
        check_ajax_referer('ssw_nonce', 'nonce');

        $api_url     = get_option('ssw_api_url', '');
        $license_key = get_option('ssw_license_key', '');
        $llm_api_key_encrypted = get_option('ssw_llm_api_key', '');
        $llm_provider = get_option('ssw_llm_provider', '');
        $llm_model = get_option('ssw_llm_model', '');

        if (empty($api_url) || empty($license_key)) {
            wp_send_json_error(['message' => 'API URL or License Key not set']);
        }

        $start    = microtime(true);
        $response = wp_remote_post(
            $api_url . '/api/test-connection',
            [
                'timeout' => 10, // Increased timeout for LLM testing
                'headers' => ['Content-Type' => 'application/json'],
                'body'    => json_encode([
                    'license_key' => $license_key,
                    'llm_api_key_encrypted' => $llm_api_key_encrypted,
                    'llm_provider' => $llm_provider,
                    'llm_model' => $llm_model
                ])
            ]
        );
        $ms = round((microtime(true) - $start) * 1000);

        if (is_wp_error($response)) {
            wp_send_json_error(['message' => $response->get_error_message()]);
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code === 200 && $body['success']) {
            $llm_status = 'LLM not configured';
            if ($body['llm_configured']) {
                $llm_status = $body['llm_working'] ? 'LLM working' : 'LLM configured but not working';
            }
            wp_send_json_success([
                'message' => "Connected in {$ms}ms — API is reachable ({$llm_status})"
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
        $llm_provider = sanitize_text_field($_POST['llm_provider'] ?? '');
        $llm_model    = sanitize_text_field($_POST['llm_model']    ?? '');
        $llm_api_key  = sanitize_text_field($_POST['llm_api_key'] ?? '');
        $sync_pages   = (isset($_POST['sync_pages']) && (int)$_POST['sync_pages'] === 1) ? 1 : 0;
        $sync_posts   = (isset($_POST['sync_posts']) && (int)$_POST['sync_posts'] === 1) ? 1 : 0;
        $existing     = get_option('ssw_llm_api_key', '');
        $final = null;
        
        if ($llm_api_key !== '') {
            // New API key provided - encrypt it
            $secret = $license_key;
            $iv = random_bytes(16);
            $key = hash('sha256', $secret, true);

            $encrypted = openssl_encrypt(
                $llm_api_key,
                'AES-256-CBC',
                $key,
                OPENSSL_RAW_DATA, // Get raw bytes instead of base64
                $iv
            );

            // Store as one clean base64 string: [16 bytes IV][Raw Encrypted Data]
            $final = base64_encode($iv . $encrypted);
        } else {
            // No new API key - keep existing one
            $final = $existing;;
        }

        if (empty($api_url)) {
            wp_send_json_error(['message' => 'API URL is required']);
        }

        update_option('ssw_api_url',      rtrim($api_url, '/'));
        update_option('ssw_result_limit', max(1, min(50, $result_limit)));
        update_option('ssw_enable_intent', $enable_intent);
        update_option('ssw_sync_pages', $sync_pages);
        update_option('ssw_sync_posts', $sync_posts);

        // Save LLM settings
        update_option('ssw_llm_provider', $llm_provider);
        update_option('ssw_llm_model', $llm_model);
        update_option('ssw_llm_api_key', $final);

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


    // ── AJAX: Cancel Sync ─────────────────────────────────────────────────────

    public function ajax_cancel_sync(): void {
        check_ajax_referer('ssw_nonce', 'nonce');

        $license_key = get_option('ssw_license_key', '');
        if (empty($license_key)) {
            wp_send_json_error(['message' => 'License key not set']);
        }

        $sync   = new SSW_Sync($license_key);
        $result = $sync->cancel_sync();

        if ($result['success']) {
            // Also notify backend about cancellation
            $api_url = get_option('ssw_api_url', '');
            if (!empty($api_url)) {
                wp_remote_post(
                    $api_url . '/api/sync/cancel',
                    [
                        'timeout' => 5,
                        'headers' => [
                            'Authorization' => 'Bearer ' . $license_key,
                            'Content-Type' => 'application/json'
                        ],
                        'body' => json_encode([
                            'license_key' => $license_key
                        ])
                    ]
                );
            }
        }

        wp_send_json($result);
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

    // ── Setup Page Renderer ───────────────────────────────────────────────────────

    public function render_setup_page(): void {
        ?>
        <div id="ssw-wrap" class="wrap">
            <div class="ssw-setup-container">
                <div class="ssw-setup-header">
                    <h1>⚡ Welcome to Semantic Search</h1>
                    <p class="ssw-setup-subtitle">Let's get your AI-powered search configured in just a few steps</p>
                </div>

                <div class="ssw-setup-card">
                    <form id="ssw-setup-form">
                        <div class="ssw-form-section">
                            <h3>🔑 License Configuration</h3>
                            <p>Enter your license key to activate the semantic search functionality.</p>
                            
                            <div class="ssw-form-field">
                                <label for="license_key">License Key</label>
                                <input type="text" id="license_key" name="license_key" placeholder="Enter your license key" required>
                                <small>Your license key can be found in your purchase confirmation email</small>
                            </div>
                        </div>

                        <div class="ssw-form-section">
                            <h3>🌐 API Configuration</h3>
                            <p>Configure the connection to your semantic search API.</p>
                            
                            <div class="ssw-form-field">
                                <label for="api_url">API URL</label>
                                <input type="url" id="api_url" name="api_url" value="http://127.0.0.1:8000" required>
                                <small>The URL where your semantic search API is hosted</small>
                            </div>

                            <div class="ssw-form-field">
                                <label for="result_limit">Search Results Limit</label>
                                <input type="number" id="result_limit" name="result_limit" value="10" min="1" max="50" required>
                                <small>Maximum number of search results to return</small>
                            </div>
                        </div>

                        <div class="ssw-form-section">
                            <h3>🛒 WooCommerce Integration</h3>
                            <p>Optional: Add WooCommerce API credentials for webhook integration.</p>
                            
                            <div class="ssw-form-field">
                                <label for="wc_key">WooCommerce Consumer Key</label>
                                <input type="text" id="wc_key" name="wc_key" placeholder="Optional - for webhook integration">
                                <small>Generate this in WooCommerce > Settings > Advanced > REST API</small>
                            </div>

                            <div class="ssw-form-field">
                                <label for="wc_secret">WooCommerce Consumer Secret</label>
                                <input type="password" id="wc_secret" name="wc_secret" placeholder="Optional - for webhook integration">
                                <small>Keep this secret and secure</small>
                            </div>
                        </div>

                        <div class="ssw-setup-actions">
                            <button type="submit" class="ssw-btn ssw-btn-primary">
                                <span class="ssw-btn-text">Complete Setup & Activate Search</span>
                                <span class="ssw-btn-spinner" style="display: none;">⏳</span>
                            </button>
                            <div id="ssw-setup-message" class="ssw-message" style="display: none;"></div>
                        </div>
                    </form>
                </div>

                <div class="ssw-setup-footer">
                    <p>Need help? <a href="https://czargroup.net/support" target="_blank">Visit our support center</a></p>
                </div>
            </div>
        </div>

        <style>
        .ssw-setup-container {
            max-width: 800px;
            margin: 40px auto;
            padding: 0 20px;
        }
        .ssw-setup-header {
            text-align: center;
            margin-bottom: 40px;
        }
        .ssw-setup-header h1 {
            font-size: 2.5em;
            margin-bottom: 10px;
            color: #23282d;
        }
        .ssw-setup-subtitle {
            font-size: 1.2em;
            color: #666;
            margin: 0;
        }
        .ssw-setup-card {
            background: #fff;
            border: 1px solid #ccd0d4;
            border-radius: 8px;
            padding: 40px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .ssw-form-section {
            margin-bottom: 40px;
            padding-bottom: 30px;
            border-bottom: 1px solid #eee;
        }
        .ssw-form-section:last-of-type {
            border-bottom: none;
            margin-bottom: 0;
        }
        .ssw-form-section h3 {
            margin-bottom: 10px;
            color: #23282d;
            font-size: 1.3em;
        }
        .ssw-form-section p {
            margin-bottom: 20px;
            color: #666;
            font-style: italic;
        }
        .ssw-form-field {
            margin-bottom: 25px;
        }
        .ssw-form-field label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #23282d;
        }
        .ssw-form-field input {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
            box-sizing: border-box;
        }
        .ssw-form-field input:focus {
            border-color: #0073aa;
            box-shadow: 0 0 0 2px rgba(0,115,170,0.2);
            outline: none;
        }
        .ssw-form-field small {
            display: block;
            margin-top: 5px;
            color: #666;
            font-size: 12px;
        }
        .ssw-setup-actions {
            text-align: center;
            margin-top: 30px;
        }
        .ssw-btn {
            background: #0073aa;
            color: white;
            border: none;
            padding: 14px 30px;
            border-radius: 4px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.3s;
        }
        .ssw-btn:hover {
            background: #005a87;
        }
        .ssw-btn:disabled {
            background: #ccc;
            cursor: not-allowed;
        }
        .ssw-message {
            margin-top: 20px;
            padding: 15px;
            border-radius: 4px;
            font-weight: 500;
        }
        .ssw-message.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .ssw-message.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .ssw-setup-footer {
            text-align: center;
            margin-top: 30px;
            color: #666;
        }
        .ssw-setup-footer a {
            color: #0073aa;
            text-decoration: none;
        }
        .ssw-setup-footer a:hover {
            text-decoration: underline;
        }
        </style>

        <script>
        jQuery(document).ready(function($) {
            $('#ssw-setup-form').on('submit', function(e) {
                e.preventDefault();
                
                var $btn = $('.ssw-btn');
                var $btnText = $('.ssw-btn-text');
                var $btnSpinner = $('.ssw-btn-spinner');
                var $message = $('#ssw-setup-message');
                
                // Show loading state
                $btn.prop('disabled', true);
                $btnText.hide();
                $btnSpinner.show();
                $message.hide();
                
                // Collect form data
                var formData = {
                    license_key: $('#license_key').val(),
                    api_url: $('#api_url').val(),
                    result_limit: $('#result_limit').val(),
                    wc_key: $('#wc_key').val(),
                    wc_secret: $('#wc_secret').val()
                };
                
                // Submit via AJAX
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'ssw_complete_setup',
                        nonce: '<?php echo wp_create_nonce("ssw_nonce"); ?>',
                        ...formData
                    },
                    success: function(response) {
                        if (response.success) {
                            $message.removeClass('error').addClass('success').text(response.data.message).show();
                            
                            // Redirect to main admin page after 2 seconds
                            setTimeout(function() {
                                window.location.href = '<?php echo admin_url("admin.php?page=semantic-search"); ?>';
                            }, 2000);
                        } else {
                            $message.removeClass('success').addClass('error').text(response.data.message).show();
                        }
                    },
                    error: function() {
                        $message.removeClass('success').addClass('error').text('An unexpected error occurred. Please try again.').show();
                    },
                    complete: function() {
                        // Restore button state
                        $btn.prop('disabled', false);
                        $btnText.show();
                        $btnSpinner.hide();
                    }
                });
            });
        });
        </script>
        <?php
    }

    // ── AJAX: Complete Setup ─────────────────────────────────────────────────────

    public function ajax_complete_setup(): void {
        check_ajax_referer('ssw_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        $license_key  = sanitize_text_field($_POST['license_key'] ?? '');
        $api_url      = sanitize_text_field($_POST['api_url'] ?? '');
        $result_limit = (int) ($_POST['result_limit'] ?? 10);
        $wc_key       = sanitize_text_field($_POST['wc_key'] ?? '');
        $wc_secret    = sanitize_text_field($_POST['wc_secret'] ?? '');

        if (empty($license_key)) {
            wp_send_json_error(['message' => 'License key is required']);
        }

        if (empty($api_url)) {
            wp_send_json_error(['message' => 'API URL is required']);
        }

        // Save settings
        update_option('ssw_license_key', $license_key);
        update_option('ssw_api_url', rtrim($api_url, '/'));
        update_option('ssw_result_limit', max(1, min(50, $result_limit)));
        
        if (!empty($wc_key)) {
            update_option('ssw_wc_key', $wc_key);
        }
        if (!empty($wc_secret)) {
            update_option('ssw_wc_secret', $wc_secret);
        }

        // Mark setup as completed
        update_option('ssw_setup_completed', true);

        wp_send_json_success(['message' => 'Setup completed successfully! Redirecting to your dashboard...']);
    }
}