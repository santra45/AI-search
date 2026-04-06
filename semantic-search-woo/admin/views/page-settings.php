<?php
$is_configured = !empty(get_option('ssw_license_key'))
              && !empty(get_option('ssw_wc_key'))
              && !empty(get_option('ssw_api_url'));

if (!$is_configured): ?>
    <div class="ssw-notice info" style="margin-bottom:20px;">
        <span style="font-size:18px;">👋</span>
        <div>
            <strong>Welcome! Complete these steps to activate semantic search:</strong>
            <ol style="margin:8px 0 0 16px;font-size:13px;line-height:1.8;">
                <li>Enter your <strong>API URL</strong> and <strong>License Key</strong></li>
                <li>Generate a <strong>WooCommerce REST API key</strong>
                    (Read/Write) and paste it below</li>
                <li>Click <strong>Save Settings</strong>
                    — webhooks register automatically</li>
                <li>Click <strong>Sync All Products</strong>
                    to index your catalog</li>
            </ol>
        </div>
    </div>
<?php endif; ?>

<?php if (!defined('ABSPATH')) exit;

$api_url     = get_option('ssw_api_url',      'http://127.0.0.1:8000');
$license_key = get_option('ssw_license_key',  '');
$limit       = get_option('ssw_result_limit', 10);

$sync     = new SSW_Sync($license_key);
$progress = $sync->get_progress();

$last_sync = $progress['started_at']
    ? date('d M Y, H:i', $progress['started_at'])
    : 'Never';

$sync_status_text = match($progress['status']) {
    'complete' => '✅ Complete',
    'running'  => '⏳ Running...',
    'cancelled' => '⏹️ Cancelled',
    'idle'     => '—',
    default    => '—'
};
?>

<div id="settings-panel" class="ssw-tab-panel" style="display:none;">

    <!-- ── API Settings ──────────────────────────────────────────────────────── -->
    <div class="ssw-card">
        <div class="ssw-card-header">
            <h2>API Configuration</h2>
        </div>
        <div class="ssw-card-body">

            <form id="ssw-settings-form">

                <table class="ssw-form-table">
                    <tr>
                        <th>
                            <label for="ssw-api-url">API URL</label>
                        </th>
                        <td>
                            <input
                                type="url"
                                id="ssw-api-url"
                                value="<?= esc_attr($api_url) ?>"
                                placeholder="https://api.yourdomain.com"
                            />
                            <p class="ssw-field-desc">
                                The URL of your Semantic Search API server.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th>
                            <label for="ssw-license-key">License Key</label>
                        </th>
                        <td>
                            <textarea
                                id="ssw-license-key"
                                rows="3"
                                placeholder="eyJhbGci..."
                            ><?= esc_attr($license_key) ?></textarea>
                            <p class="ssw-field-desc">
                                Your license key from the dashboard.
                                Webhooks will be registered automatically when you save a new key.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th>
                            <label for="ssw-result-limit">Results Limit</label>
                        </th>
                        <td>
                            <input
                                type="number"
                                id="ssw-result-limit"
                                value="<?= esc_attr($limit) ?>"
                                min="1"
                                max="50"
                                style="max-width:80px;"
                            />
                            <p class="ssw-field-desc">
                                Maximum number of products to show per search (1–50).
                            </p>
                        </td>
                        <tr>
                            <th><label for="ssw-wc-key">WC Consumer Key</label></th>
                            <td>
                                <input
                                    type="text"
                                    id="ssw-wc-key"
                                    value="<?= esc_attr(get_option('ssw_wc_key', '')) ?>"
                                    placeholder="ck_xxxxxxxxxxxxxxxxxxxx"
                                />
                                <p class="ssw-field-desc">
                                    Required for automatic webhook registration.<br>
                                    <strong>How to get this:</strong>
                                    Go to
                                    <a href="<?= admin_url('admin.php?page=wc-settings&tab=advanced&section=keys') ?>"
                                    target="_blank">
                                    WooCommerce → Settings → Advanced → REST API
                                    </a>
                                    → Add Key → set Permissions to <strong>Read/Write</strong>
                                    → copy the Consumer Key here.
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="ssw-wc-secret">WC Consumer Secret</label></th>
                            <td>
                                <input
                                    type="password"
                                    id="ssw-wc-secret"
                                    value="<?= esc_attr(get_option('ssw_wc_secret', '')) ?>"
                                    placeholder="cs_xxxxxxxxxxxxxxxxxxxx"
                                />
                                <p class="ssw-field-desc">
                                    Copy the Consumer Secret from the same page.<br>
                                    <strong>Save it immediately</strong> —
                                    WooCommerce only shows it once.
                                </p>
                            </td>
                        </tr>
                    </tr>
                    <tr>
                        <th>
                            <label for="ssw-enable-intent">Enable Intent Analyzer</label>
                        </th>
                        <td>
                            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                                <input
                                    type="checkbox"
                                    id="ssw-enable-intent"
                                    name="enable_intent"
                                    value="1"
                                    <?= checked(get_option('ssw_enable_intent', 0), 1, false) ?>
                                />
                                <span>Extract price filters and attributes from search queries</span>
                            </label>
                            <p class="ssw-field-desc">
                                When enabled, uses AI to analyze search queries for price ranges, 
                                stock status, and product attributes (e.g., "red shoes under $50").
                                <br><strong>Note:</strong> This adds an extra API call but provides more accurate filtering.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th>
                            <label for="ssw-llm-provider">LLM Provider</label>
                        </th>
                        <td>
                            <select id="ssw-llm-provider" name="llm_provider" style="min-width:200px;">
                                <option value="">Select LLM Provider</option>
                                <option value="gemini" <?= selected(get_option('ssw_llm_provider', ''), 'gemini') ?>>
                                    Google Gemini
                                </option>
                                <option value="openai" <?= selected(get_option('ssw_llm_provider', ''), 'openai') ?>>
                                    OpenAI ChatGPT
                                </option>
                                <option value="anthropic" <?= selected(get_option('ssw_llm_provider', ''), 'anthropic') ?>>
                                    Anthropic Claude
                                </option>
                            </select>
                            <p class="ssw-field-desc">
                                Choose the LLM provider for product re-ranking and semantic analysis.
                            </p>
                        </td>
                    </tr>
                    <tr id="ssw-llm-model-row" style="display:none;">
                        <th>
                            <label for="ssw-llm-model">LLM Model</label>
                        </th>
                        <td>
                            <select id="ssw-llm-model" name="llm_model"
                            data-current="<?php echo esc_attr(get_option('ssw_llm_model', '')); ?>" style="min-width:200px;">
                                <option value="">Select Provider First</option>
                            </select>
                            <p class="ssw-field-desc">
                                Select the specific model to use for the selected provider.
                            </p>
                        </td>
                    </tr>
                    <tr id="ssw-llm-api-key-row" style="display:none;">
                        <th>
                            <label for="ssw-llm-api-key">LLM API Key</label>
                        </th>
                        <td>
                            <input
                                type="password"
                                id="ssw-llm-api-key"
                                name="llm_api_key"
                                value="**************************"
                                placeholder="Enter your API key..."
                                style="min-width:300px;"
                                <?php echo get_option('ssw_llm_api_key') ? 'disabled' : ''; ?>
                            />
                            <p class="ssw-field-desc" id="ssw-llm-api-key-desc">
                                <!-- Populated dynamically by handleProviderChange() -->
                            </p>
                            <?php if (get_option('ssw_llm_api_key')): ?>
                                <button type="button" class="ssw-btn ssw-btn-primary" id="ssw-change-api-key">Change API Key</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>

                <div style="margin-top:20px;padding-top:16px;border-top:1px solid var(--ssw-gray-200);">
                    <div class="ssw-btn-group">
                        <button type="submit" id="ssw-save-settings" class="ssw-btn ssw-btn-primary">
                            💾 Save Settings
                        </button>
                        <button type="button" id="ssw-test-connection" class="ssw-btn ssw-btn-secondary">
                            🔌 Test Connection
                        </button>
                        <span id="ssw-test-result"  class="ssw-inline-result"></span>
                        <span id="ssw-save-result"  class="ssw-inline-result"></span>
                    </div>
                </div>

            </form>

        </div>
    </div>

    <!-- ── Product Sync ───────────────────────────────────────────────────────── -->
    <div class="ssw-card">
        <div class="ssw-card-header">
            <h2>Product Sync</h2>
        </div>
        <div class="ssw-card-body">

            <!-- Sync Info -->
            <table class="ssw-form-table" style="margin-bottom:20px;">
                <tr>
                    <th>Status</th>
                    <td>
                        <span id="ssw-sync-status-text">
                            <?= esc_html($sync_status_text) ?>
                        </span>
                    </td>
                </tr>
                <tr>
                    <th>Last Sync</th>
                    <td>
                        <span id="ssw-last-sync">
                            <?= esc_html($last_sync) ?>
                        </span>
                    </td>
                </tr>
                <tr>
                    <th>Products Synced</th>
                    <td>
                        <?= (int) $progress['processed'] ?>
                        /
                        <?= (int) $progress['total'] ?>
                    </td>
                </tr>
            </table>

            <!-- Progress Bar (hidden when idle) -->
            <div id="ssw-progress-wrap"
                 style="<?= in_array($progress['status'], ['running', 'cancelled']) ? '' : 'display:none;' ?>
                        margin-bottom:20px;">

                <div class="ssw-progress-track">
                    <div class="ssw-progress-fill"
                         id="ssw-progress-fill"
                         style="width:<?= (int) $progress['percentage'] ?>%;">
                    </div>
                </div>

                <div class="ssw-progress-meta">
                    <span id="ssw-progress-processed">
                        <?= (int) $progress['processed'] ?> /
                        <?= (int) $progress['total'] ?> products
                    </span>
                    <span id="ssw-progress-pct">
                        <?= (int) $progress['percentage'] ?>%
                    </span>
                </div>

                <p id="ssw-progress-batch"
                   style="font-size:12px;color:var(--ssw-gray-400);margin-top:6px;">
                    Batch <?= (int) $progress['current_batch'] ?>
                    of <?= (int) $progress['total_batches'] ?>
                </p>

            </div>

            <!-- Sync Button -->
            <div class="ssw-btn-group">
                <button
                    id="ssw-sync-btn"
                    class="ssw-btn ssw-btn-primary"
                    <?= $progress['status'] === 'running' ? 'disabled' : '' ?>
                >
                    <?= $progress['status'] === 'running'
                        ? '<span class="ssw-spinner"></span> Syncing...'
                        : ' Sync All Products' ?>
                </button>
                
                <?php if ($progress['status'] === 'running'): ?>
                <button
                    id="ssw-cancel-sync-btn"
                    class="ssw-btn ssw-btn-secondary"
                    style="margin-left: 10px;"
                >
                    Cancel Sync
                </button>
                <?php endif; ?>
            </div>

            <p style="font-size:12px;color:var(--ssw-gray-400);margin-top:10px;">
                Embeds all published products into the semantic search index.
                New and updated products sync automatically via webhooks —
                only use this for a full re-index.
            </p>

        </div>
    </div>

    <!-- ── Webhooks ───────────────────────────────────────────────────────────── -->
    <div class="ssw-card">
        <div class="ssw-card-header">
            <h2>Webhooks</h2>
        </div>
        <div class="ssw-card-body">

            <?php
            $registered = get_option('ssw_webhooks_registered', []);
            $topics     = [
                'product.created' => 'Product Created',
                'product.updated' => 'Product Updated',
                'product.deleted' => 'Product Deleted',
            ];
            ?>

            <div class="ssw-webhook-list" style="margin-bottom:20px;">
                <?php foreach ($topics as $topic => $label): ?>
                    <?php
                    $is_registered = in_array($topic, array_column($registered, 'topic'));
                    $status_class  = $is_registered ? 'ok'    : 'error';
                    $status_text   = $is_registered ? '✅ Registered' : '❌ Not registered';
                    ?>
                    <div class="ssw-webhook-item">
                        <span class="ssw-webhook-topic"><?= esc_html($topic) ?></span>
                        <span class="ssw-webhook-status <?= $status_class ?>">
                            <?= $status_text ?>
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="ssw-btn-group">
                <button
                    type="button"
                    id="ssw-register-webhooks"
                    class="ssw-btn ssw-btn-secondary"
                >
                    🔗 Re-register Webhooks
                </button>
                <span id="ssw-webhook-result" class="ssw-inline-result"></span>
            </div>

            <p style="font-size:12px;color:var(--ssw-gray-400);margin-top:10px;">
                Webhooks keep your search index in sync automatically
                when products are added, updated, or deleted.
                They are registered automatically when you save your license key.
            </p>

        </div>
    </div>

</div><!-- /#settings-panel -->