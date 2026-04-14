/* ============================================================
   Semantic Search for WooCommerce — Admin JS
   ============================================================ */

(function ($) {
    'use strict';

    // ── Config ──────────────────────────────────────────────────
    const SSW = window.SSW_Config || {};
    const nonce   = SSW.nonce   || '';
    const ajaxurl = SSW.ajaxurl || window.ajaxurl;

    // ── Init ────────────────────────────────────────────────────
    $(document).ready(function () {
        SSW_Tabs.init();
        SSW_Dashboard.init();
        SSW_Analytics.init();
        SSW_Settings.init();
        SSW_Status.init();
        SSW_Sync.init();
    });
    $(document).on('click', '#ssw-change-api-key', function () {
        $('#ssw-llm-api-key').prop('disabled', false).focus();
        $('#ssw-llm-api-key').val('');
    });


    // ============================================================
    // TABS
    // ============================================================
    const SSW_Tabs = {
        init() {
            // Highlight active tab from URL hash
            const hash = window.location.hash || '#dashboard';
            this.activate(hash);

            $('.ssw-nav-tabs a').on('click', function (e) {
                e.preventDefault();
                const target = $(this).attr('href');
                SSW_Tabs.activate(target);
                history.replaceState(null, '', target);
            });
        },

        activate(hash) {
            // Nav links
            $('.ssw-nav-tabs a').removeClass('active');
            $(`.ssw-nav-tabs a[href="${hash}"]`).addClass('active');

            // Panels
            $('.ssw-tab-panel').hide();
            $(`${hash}-panel`).show();
        }
    };


    // ============================================================
    // HELPERS
    // ============================================================

    function ajax(action, data = {}) {
        return $.post(ajaxurl, { action, nonce, ...data });
    }

    function hasLicenseKey() {
        return !!(SSW.current_license_key && String(SSW.current_license_key).trim().length > 0);
    }

    function formatNumber(n) {
        return Number(n).toLocaleString();
    }

    function formatMs(ms) {
        return ms < 1000 ? `${ms}ms` : `${(ms / 1000).toFixed(1)}s`;
    }

    function timeAgo(isoString) {
        const diff = Math.floor((Date.now() - new Date(isoString)) / 1000);
        if (diff < 60)   return `${diff}s ago`;
        if (diff < 3600) return `${Math.floor(diff / 60)}m ago`;
        if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`;
        return `${Math.floor(diff / 86400)}d ago`;
    }

    function showInlineResult($el, message, type = 'success') {
        $el.text(message)
           .removeClass('success error')
           .addClass(`visible ${type}`);

        setTimeout(() => $el.removeClass('visible'), 4000);
    }

    function quotaBarClass(pct) {
        if (pct >= 90) return 'danger';
        if (pct >= 70) return 'warning';
        return '';
    }

    function renderQuotaBar(used, limit, label, $container) {
        const pct      = limit > 0 ? Math.min(Math.round(used / limit * 100), 100) : 0;
        const barClass = quotaBarClass(pct);

        $container.html(`
            <div class="ssw-quota-item">
                <div class="ssw-quota-header">
                    <span class="ssw-quota-label">${label}</span>
                    <span class="ssw-quota-numbers">
                        ${formatNumber(used)} / ${formatNumber(limit)}
                    </span>
                </div>
                <div class="ssw-quota-bar-wrap">
                    <div class="ssw-quota-bar ${barClass}"
                         style="width:${pct}%"></div>
                </div>
                <div class="ssw-quota-pct">${pct}% used</div>
            </div>
        `);
    }


    // ============================================================
    // DASHBOARD
    // ============================================================
    const SSW_Dashboard = {
        init() {
            if (!$('#dashboard-panel').length) return;
            this.load();
        },

        load() {
            const $panel = $('#dashboard-panel');
            $panel.find('.ssw-loading').show();

            if (!hasLicenseKey()) {
                $panel.find('.ssw-content').hide();
                $panel.find('.ssw-loading').hide();
                return;
            }

            ajax('ssw_dashboard_stats').done(res => {
                if (!res.success) {
                    const msg = 'Failed to load dashboard data.';
                    $panel.find('.ssw-loading').html(
                        `<div class="ssw-notice error">${escHtml(msg)}</div>`
                    );
                    return;
                }
                try {
                    this.render(res.data);
                } catch (e) {
                    $panel.find('.ssw-loading').html(
                        '<div class="ssw-notice error">Failed to load dashboard data.</div>'
                    );
                }
            }).fail(() => {
                $panel.find('.ssw-loading').html(
                    '<div class="ssw-notice error">Failed to load dashboard data.</div>'
                );
            });
        },

        render(data) {
            // ── Stat Cards ──────────────────────────────────────
            $('#ssw-stat-searches').text(formatNumber(data.searches.used));
            $('#ssw-stat-products').text(formatNumber(data.products.indexed));
            $('#ssw-stat-plan').text(data.plan.charAt(0).toUpperCase() + data.plan.slice(1));
            $('#ssw-stat-ingestions').text(formatNumber(data.ingestions.this_month));

            // ── Quota Bars ──────────────────────────────────────
            renderQuotaBar(
                data.searches.used,
                data.searches.limit,
                'Search Quota This Month',
                $('#ssw-quota-searches')
            );

            renderQuotaBar(
                data.products.indexed,
                data.products.limit,
                'Product Index Quota',
                $('#ssw-quota-products')
            );

            // ── Recent Searches Table ───────────────────────────
            const $tbody = $('#ssw-recent-searches tbody');
            $tbody.empty();

            if (!data.recent_searches.length) {
                $tbody.html(`
                    <tr>
                        <td colspan="5">
                            <div class="ssw-empty">
                                <div class="ssw-empty-icon">🔍</div>
                                <p>No searches yet. Once customers search your store, they'll appear here.</p>
                            </div>
                        </td>
                    </tr>
                `);
            } else {
                data.recent_searches.forEach(row => {
                    const cachedBadge = row.cached
                        ? '<span class="ssw-badge cached">⚡ Cached</span>'
                        : '<span class="ssw-badge miss">API</span>';

                    const resultsBadge = row.results_count === 0
                        ? '<span class="ssw-badge zero">0</span>'
                        : `<strong>${row.results_count}</strong>`;

                    $tbody.append(`
                        <tr>
                            <td class="query-cell">${escHtml(row.query)}</td>
                            <td>${resultsBadge}</td>
                            <td>${formatMs(row.response_time_ms)}</td>
                            <td>${cachedBadge}</td>
                            <td>${timeAgo(row.searched_at)}</td>
                        </tr>
                    `);
                });
            }

            // Hide loading, show content
            $('#dashboard-panel .ssw-loading').hide();
            $('#dashboard-panel .ssw-content').show();
        }
    };


    // ============================================================
    // ANALYTICS
    // ============================================================
    const SSW_Analytics = {
        chart:  null,
        days:   7,

        init() {
            if (!$('#analytics-panel').length) return;

            // Day range selector
            $('#ssw-days-filter').on('change', (e) => {
                this.days = parseInt(e.target.value);
                this.load();
            });

            this.load();
        },

        load() {
            const $panel = $('#analytics-panel');
            $panel.find('.ssw-loading').show();

            if (!hasLicenseKey()) {
                $panel.find('.ssw-content').hide();
                $panel.find('.ssw-loading').hide();
                return;
            }

            ajax('ssw_analytics_data', { days: this.days }).done(res => {
                if (!res.success) {
                    const msg = 'Failed to load analytics data.';
                    $panel.find('.ssw-loading').html(
                        `<div class="ssw-notice error">${escHtml(msg)}</div>`
                    );
                    return;
                }
                try {
                    this.render(res.data);
                } catch (e) {
                    $panel.find('.ssw-loading').html(
                        '<div class="ssw-notice error">Failed to load analytics data.</div>'
                    );
                }
            }).fail(() => {
                $panel.find('.ssw-loading').html(
                    '<div class="ssw-notice error">Failed to load analytics data.</div>'
                );
            });
        },

        render(data) {
            // ── Summary Stats ───────────────────────────────────
            $('#ssw-cache-hit-rate').text(data.summary.cache_hit_rate + '%');
            $('#ssw-avg-response').text(formatMs(data.summary.avg_response_ms));
            $('#ssw-zero-result-rate').text(data.summary.zero_result_rate + '%');
            $('#ssw-total-searches').text(formatNumber(data.summary.total_searches));

            // ── Chart ───────────────────────────────────────────
            this.renderChart(data.summary.daily_volume);

            // ── Top Queries Table ───────────────────────────────
            const $top = $('#ssw-top-queries tbody');
            $top.empty();

            if (!data.top_queries.length) {
                $top.html('<tr><td colspan="4"><div class="ssw-empty"><p>No data yet.</p></div></td></tr>');
            } else {
                data.top_queries.forEach((row, i) => {
                    $top.append(`
                        <tr>
                            <td style="color:var(--ssw-gray-400);font-weight:600;">#${i + 1}</td>
                            <td class="query-cell">${escHtml(row.query)}</td>
                            <td><strong>${formatNumber(row.count)}x</strong></td>
                            <td>${row.avg_results.toFixed(1)}</td>
                        </tr>
                    `);
                });
            }

            // ── Zero Results Table ──────────────────────────────
            const $zero = $('#ssw-zero-results tbody');
            $zero.empty();

            if (!data.zero_results.length) {
                $zero.html(`
                    <tr>
                        <td colspan="2">
                            <div class="ssw-empty">
                                <div class="ssw-empty-icon">✅</div>
                                <p>No zero-result searches. Great!</p>
                            </div>
                        </td>
                    </tr>
                `);
            } else {
                data.zero_results.forEach(row => {
                    $zero.append(`
                        <tr>
                            <td class="query-cell">${escHtml(row.query)}</td>
                            <td>
                                <span class="ssw-badge zero">${row.count}x</span>
                            </td>
                        </tr>
                    `);
                });
            }

            $('#analytics-panel .ssw-loading').hide();
            $('#analytics-panel .ssw-content').show();
        },

        renderChart(volumeData) {
            const ctx = document.getElementById('ssw-volume-chart');
            if (!ctx) return;

            // Destroy previous chart instance if re-rendering
            if (this.chart) {
                this.chart.destroy();
            }

            this.chart = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels:   volumeData.labels,
                    datasets: [{
                        label:           'Searches',
                        data:            volumeData.values,
                        backgroundColor: 'rgba(34, 113, 177, 0.15)',
                        borderColor:     'rgba(34, 113, 177, 0.8)',
                        borderWidth:     2,
                        borderRadius:    4,
                    }]
                },
                options: {
                    responsive:          true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                stepSize: 1,
                                color:    '#a7aaad',
                                font:     { size: 11 }
                            },
                            grid: { color: '#f0f0f1' }
                        },
                        x: {
                            ticks: {
                                color: '#a7aaad',
                                font:  { size: 11 }
                            },
                            grid: { display: false }
                        }
                    }
                }
            });
        }
    };


    // ============================================================
    // SETTINGS
    // ============================================================
    const SSW_Settings = {
        init() {
            if (!$('#settings-panel').length) return;

            // Test connection button
            $('#ssw-test-connection').on('click', () => this.testConnection());

            // Register webhooks button
            $('#ssw-register-webhooks').on('click', () => this.registerWebhooksManual());

            // LLM Provider change handler
            $('#ssw-llm-provider').on('change', () => this.handleProviderChange());

            // Save settings — re-register webhooks if license key changed
            $('#ssw-settings-form').on('submit', (e) => {
                e.preventDefault();
                this.saveSettings();
            });

            // Initialize provider-specific fields on page load
            this.handleProviderChange();
        },

        handleProviderChange() {
            const provider = $('#ssw-llm-provider').val();
            const $modelRow = $('#ssw-llm-model-row');
            const $apiKeyRow = $('#ssw-llm-api-key-row');
            const $modelSelect = $('#ssw-llm-model');

            // Model options for each provider
            const models = {
                gemini: [
                    { value: 'gemini-3.1-pro-preview', text: 'Gemini 3.1 Pro (Latest)' },
                    { value: 'gemini-2.5-pro', text: 'Gemini 2.5 Pro (Stable)' },
                    { value: 'gemini-2.5-flash', text: 'Gemini 2.5 Flash (Fast)' },
                    { value: 'gemini-2.5-flash-lite', text: 'Gemini 2.5 Flash Lite (Budget)' },
                    { value: 'gemma-3-27b-it', text: 'Gemma 3 27B (Free)' }
                ],
                openai: [
                    { value: 'gpt-5.4', text: 'GPT-5.4 (Latest)' },
                    { value: 'gpt-5.4-mini', text: 'GPT-5.4 Mini (Fast)' },
                    { value: 'gpt-5.4-nano', text: 'GPT-5.4 Nano (Budget)' },
                    { value: 'gpt-5.2', text: 'GPT-5.2 (Previous Gen)' }
                ],
                anthropic: [
                    { value: 'claude-opus-4-6', text: 'Claude Opus 4.6 (Most Powerful)' },
                    { value: 'claude-sonnet-4-6', text: 'Claude Sonnet 4.6 (Balanced)' },
                    { value: 'claude-haiku-4-5-20251001', text: 'Claude Haiku 4.5 (Fast)' },
                    { value: 'claude-3-5-sonnet-20241022', text: 'Claude 3.5 Sonnet (Legacy)' }
                ]
            };

            const apiKeyInstructions = {
                gemini: `
                    Get your API key from Google AI Studio.<br>
                    <strong>How to get this:</strong>
                    Go to <a href="https://aistudio.google.com/app/api-keys" target="_blank">
                    Google AI Studio → API Keys</a>
                    → <strong>Create API Key</strong> → copy the key here.
                `,
                openai: `
                    Get your API key from the OpenAI developer dashboard.<br>
                    <strong>How to get this:</strong>
                    Go to <a href="https://platform.openai.com/api-keys" target="_blank">
                    OpenAI Platform → API Keys</a>
                    → <strong>Create new secret key</strong> → copy the key here.
                    Note: Ensure your account has a funded usage balance.
                `,
                anthropic: `
                    Get your API key from the Anthropic Console.<br>
                    <strong>How to get this:</strong>
                    Go to <a href="https://platform.claude.com/settings/keys" target="_blank">
                    Anthropic Console → Settings → API Keys</a>
                    → <strong>Create Key</strong> → copy the key here.
                `
            };

            if (provider && models[provider]) {
                // Show model and API key rows
                $modelRow.show();
                $apiKeyRow.show();

                // Update API key instruction note
                $('#ssw-llm-api-key-desc').html(apiKeyInstructions[provider]);

                $modelSelect.empty();
                $modelSelect.append('<option value="">Select a model...</option>');

                // Add provider-specific models
                models[provider].forEach(model => {
                    $modelSelect.append(`<option value="${model.value}">${model.text}</option>`);
                });

                // Set current selection if exists
                const currentModel = $modelSelect.data('current') || '';
                if (currentModel && $modelSelect.find(`option[value="${currentModel}"]`).length) {
                    $modelSelect.val(currentModel);
                } else {
                    $modelSelect.val('');
                }
            } else {
                // Hide rows if no provider selected
                $modelRow.hide();
                $apiKeyRow.hide();
                $('#ssw-llm-api-key-desc').html('');
            }
            let previousProvider = $('#ssw-llm-provider').data('current') || '';
            $('#ssw-llm-provider').on('change', function () {
                const newProvider = $(this).val();
                if (previousProvider && newProvider !== previousProvider) {
                    $('#ssw-llm-model').val('');
                    $('#ssw-llm-api-key').val('').prop('disabled', false);
                }
                previousProvider = newProvider;
            });
        },

        testConnection() {
            const $btn    = $('#ssw-test-connection');
            const $result = $('#ssw-test-result');

            $btn.prop('disabled', true).html(
                '<span class="ssw-spinner"></span> Testing...'
            );

            ajax('ssw_test_connection').done(res => {
                if (res.success) {
                    showInlineResult($result, '✅ ' + res.data.message, 'success');
                } else {
                    showInlineResult($result, '❌ ' + res.data.message, 'error');
                }
            }).fail(() => {
                showInlineResult($result, '❌ Request failed', 'error');
            }).always(() => {
                $btn.prop('disabled', false).text('Test Connection');
            });
        },

        saveSettings() {
            const $btn    = $('#ssw-save-settings');
            const $result = $('#ssw-save-result');
            const prevKey = SSW.current_license_key || '';
            const newKey  = $('#ssw-license-key').val().trim();
            const wcKey   = $('#ssw-wc-key').val().trim();
            const wcSecret = $('#ssw-wc-secret').val().trim();
            const keyChanged = newKey !== prevKey && newKey.length > 0;

            $btn.prop('disabled', true).html(
                '<span class="ssw-spinner"></span> Saving...'
            );

            ajax('ssw_save_settings', {
                api_url:      $('#ssw-api-url').val().trim(),
                license_key:  newKey,
                result_limit: $('#ssw-result-limit').val(),
                wc_key:       wcKey,
                wc_secret:    wcSecret,
                enable_intent: $('#ssw-enable-intent').is(':checked') ? 1 : 0,
                llm_provider: $('#ssw-llm-provider').val(),
                llm_model: $('#ssw-llm-model').val(),
                llm_api_key: $('#ssw-llm-api-key').is(':disabled') ? '' : $('#ssw-llm-api-key').val().trim(),
                sync_pages:   $('#ssw-sync-pages').is(':checked') ? 1 : 0,
                sync_posts:   $('#ssw-sync-posts').is(':checked') ? 1 : 0
            }).done(res => {
                if (res.success) {
                    showInlineResult($result, '✅ Settings saved', 'success');
                    SSW.current_license_key = newKey;

                    // Only auto-register webhooks if we have everything needed
                    if (keyChanged && wcKey && wcSecret) {
                        this.registerWebhooks($result);
                    } else if (keyChanged && (!wcKey || !wcSecret)) {
                        showInlineResult(
                            $result,
                            '✅ Settings saved — add WC Keys to enable webhooks',
                            'success'
                        );
                    }
                } else {
                    showInlineResult($result, '❌ ' + res.data.message, 'error');
                }
            }).fail(() => {
                showInlineResult($result, '❌ Save failed', 'error');
            }).always(() => {
                $btn.prop('disabled', false).text('Save Settings');
            });
        },

        registerWebhooks($result) {
            showInlineResult($result, '⏳ Registering webhooks...', 'success');

            ajax('ssw_register_webhooks').done(res => {

                if (res.success) {
                    showInlineResult(
                        $result,
                        `✅ ${res.data.registered} webhooks registered`,
                        'success'
                    );
                    SSW_Status.load();
                } else {
                    showInlineResult(
                        $result,
                        '❌ ' + res.data.message,
                        'error'
                    );
                }
            }).fail(function() {
                showInlineResult($result, '❌ Request failed', 'error');
            });
        },

        registerWebhooksManual() {
            const $btn    = $('#ssw-register-webhooks');
            const $result = $('#ssw-webhook-result');

            $btn.prop('disabled', true).html(
                '<span class="ssw-spinner dark"></span> Registering...'
            );

            ajax('ssw_register_webhooks').done(res => {

                if (res.success) {
                    showInlineResult(
                        $result,
                        `✅ ${res.data.registered} webhooks registered`,
                        'success'
                    );
                    SSW_Status.load();

                } else {
                    showInlineResult(
                        $result,
                        '❌ ' + (res.data.message || 'Registration failed'),
                        'error'
                    );
                }
            }).fail(() => {
                showInlineResult($result, '❌ Request failed', 'error');
            }).always(() => {
                $btn.prop('disabled', false).text('🔗 Re-register Webhooks');
            });
        }
    };


    // ============================================================
    // SYNC
    // ============================================================
    const SSW_Sync = {
        running: false,

        init() {
            if (!$('#settings-panel').length) return;

            $('#ssw-sync-btn').on('click', () => this.start());
            $('#ssw-cancel-sync-btn').on('click', () => this.cancel());

            // Resume if sync was running when page loaded
            if (SSW.sync_running) {
                this.running = true;
                this.showProgress();
                this.processNext();
            }
        },

        start() {
            if (!confirm('This will re-index all products. Continue?')) return;

            const $btn = $('#ssw-sync-btn');
            $btn.prop('disabled', true).html(
                '<span class="ssw-spinner"></span> Starting...'
            );

            ajax('ssw_start_sync').done(res => {
                if (!res.success) {
                    alert('Failed to start: ' + res.data.message);
                    $btn.prop('disabled', false).text('🔄 Sync All Products');
                    return;
                }

                this.running = true;
                this.showProgress();
                this.updateProgress({
                    percentage:    0,
                    processed:     0,
                    total:         res.data.total,
                    current_batch: 0,
                    total_batches: res.data.total_batches
            });
            this.processNext();
        });
    },

    cancel() {
        if (!confirm('Are you sure you want to cancel the sync? This will stop the indexing process.')) return;

        const $cancelBtn = $('#ssw-cancel-sync-btn');
        $cancelBtn.prop('disabled', true).html(
            '<span class="ssw-spinner"></span> Cancelling...'
        );

        ajax('ssw_cancel_sync').done(res => {
            if (res.success) {
                this.running = false;
                this.hideProgress();
                
                // Update UI to show cancelled state
                $('#ssw-sync-btn').prop('disabled', false).text('🔄 Sync All Products');
                $('#ssw-sync-status-text').text('⏹️ Cancelled');
                
                // Hide cancel button
                $cancelBtn.remove();
                
                // Show success message
                alert(`Sync cancelled. ${res.processed} products were indexed (${res.percentage}% complete).`);
            } else {
                alert('Failed to cancel sync: ' + (res.message || 'Unknown error'));
                $cancelBtn.prop('disabled', false).text('⏹️ Cancel Sync');
            }
        }).fail(() => {
            alert('Failed to cancel sync. Please try again.');
            $cancelBtn.prop('disabled', false).text('⏹️ Cancel Sync');
        });
    },

    processNext() {
        if (!this.running) return;

        ajax('ssw_next_batch').done(res => {
            if (!res.success) {
                this.onError('Batch processing failed.');
                return;
            }

                this.updateProgress(res.data);

                if (res.data.status === 'complete') {
                    this.onComplete(res.data);
                } else {
                    setTimeout(() => this.processNext(), 400);
                }
            }).fail(() => {
                // Network blip — retry after 3s
                setTimeout(() => this.processNext(), 3000);
            });
        },

        updateProgress(data) {
            const pct = data.percentage || 0;

            $('#ssw-progress-fill').css('width', pct + '%');
            $('#ssw-progress-pct').text(pct + '%');
            $('#ssw-progress-processed').text(
                `${formatNumber(data.processed)} / ${formatNumber(data.total)} products`
            );
            $('#ssw-progress-batch').text(
                `Batch ${data.current_batch} of ${data.total_batches}`
            );
            $('#ssw-sync-status-text').text('Running...');
        },

        onComplete(data) {
            this.running = false;
            this.hideProgress();

            $('#ssw-sync-btn').prop('disabled', false).text('🔄 Sync All Products');
            $('#ssw-sync-status-text').text('✅ Complete');
            $('#ssw-last-sync').text('Just now');

            let msg = `✅ Sync complete — ${formatNumber(data.processed)} products indexed.`;
            if (data.failed > 0) msg += ` (${data.failed} failed)`;
            alert(msg);
        },

        onError(msg) {
            this.running = false;
            this.hideProgress();
            $('#ssw-sync-btn').prop('disabled', false).text('🔄 Sync All Products');
            $('#ssw-sync-status-text').text('❌ Error');
            alert('Sync error: ' + msg);
        },

        showProgress() {
            $('#ssw-progress-wrap').slideDown(200);
            $('#ssw-sync-btn').prop('disabled', true).html(
                '<span class="ssw-spinner"></span> Syncing...'
            );
            
            // Add cancel button if it doesn't exist
            if (!$('#ssw-cancel-sync-btn').length) {
                const $cancelBtn = $('<button>')
                    .attr('id', 'ssw-cancel-sync-btn')
                    .addClass('ssw-btn ssw-btn-secondary')
                    .css('margin-left', '10px')
                    .text('⏹️ Cancel Sync')
                    .on('click', () => this.cancel());
                
                $('#ssw-sync-btn').after($cancelBtn);
            }
        },

        hideProgress() {
            $('#ssw-progress-wrap').slideUp(200);
            // Remove cancel button
            $('#ssw-cancel-sync-btn').remove();
        }
    };


    // ============================================================
    // STATUS
    // ============================================================
    const SSW_Status = {
        init() {
            if (!$('#status-panel').length) return;

            $('#ssw-run-diagnostic').on('click', () => this.load());
            $('#ssw-reset-sync').on('click',     () => this.resetSync());
            this.load();
        },

        load() {
            const $btn     = $('#ssw-run-diagnostic');
            const $loading = $('#ssw-status-loading');
            const $list    = $('#ssw-status-list');

            $btn.prop('disabled', true).html(
                '<span class="ssw-spinner dark"></span> Checking...'
            );
            $loading.show();
            $list.empty();

            ajax('ssw_status_check').done(res => {

                if (res.success) {
                    this.render(res.data);
                } else {
                    $list.html(
                        '<div class="ssw-notice error">❌ ' +
                        (res.data?.message || 'Status check failed') +
                        '</div>'
                    );
                    $('#ssw-account-details').html('<div class="ssw-notice error">Please check your API URL and License Key.</div>');
                }
            }).fail(xhr => {
                console.error('Status check failed:', xhr.responseText);
                $list.html(
                    '<div class="ssw-notice error">Could not reach API server.</div>'
                );
            }).always(() => {
                $loading.hide();
                $btn.prop('disabled', false).text('🔄 Run Diagnostic');
            });
        },

        render(data) {
            const rows = [
                {
                    ok:    data.api_reachable,
                    icon:  data.api_reachable ? '✅' : '❌',
                    title: 'API Server',
                    desc:  data.api_reachable
                            ? 'Server is reachable'
                            : 'Cannot reach API — check API URL in Settings',
                    value: data.api_reachable ? 'Online' : 'Offline'
                },
                {
                    ok:    data.license_valid,
                    icon:  data.license_valid ? '✅' : '❌',
                    title: 'License Key',
                    desc:  data.license_valid
                            ? 'Valid and active'
                            : 'Invalid or expired — check license key in Settings',
                    value: data.plan
                            ? data.plan.charAt(0).toUpperCase() + data.plan.slice(1) + ' plan'
                            : '—'
                },
                {
                    ok:    data.indexed_count > 0,
                    icon:  data.indexed_count > 0 ? '✅' : '⚠️',
                    title: 'Products Indexed',
                    desc:  data.indexed_count > 0
                            ? `${formatNumber(data.indexed_count)} products in search index`
                            : 'No products indexed — go to Settings and run Sync',
                    value: formatNumber(data.indexed_count)
                },
                {
                    ok:    data.webhooks_ok,
                    icon:  data.webhooks_ok ? '✅' : '⚠️',
                    title: 'Webhooks',
                    desc:  data.webhooks_ok
                            ? '3 webhooks registered and active'
                            : 'Webhooks not registered — go to Settings to register',
                    value: data.webhooks_ok ? '3 active' : 'Not set'
                },
                {
                    ok:    data.search_active,
                    icon:  data.search_active ? '✅' : '❌',
                    title: 'Search Active',
                    desc:  data.search_active
                            ? 'Semantic search is intercepting store queries'
                            : 'Search not active — check API URL and License Key',
                    value: data.search_active ? 'ON' : 'OFF'
                }
            ];

            const $list = $('#ssw-status-list');
            $list.empty();

            rows.forEach(row => {
                const cls = row.ok ? 'ok' : 'warn';
                $list.append(`
                    <div class="ssw-status-row ${cls}">
                        <div class="ssw-status-icon">${row.icon}</div>
                        <div class="ssw-status-info">
                            <div class="ssw-status-title">${row.title}</div>
                            <div class="ssw-status-desc">${row.desc}</div>
                        </div>
                        <div class="ssw-status-value">${row.value}</div>
                    </div>
                `);
            });

            // Account details
            $('#ssw-account-details').html(`
                <div class="ssw-account-grid">
                    <div class="ssw-account-item">
                        <div class="ssw-account-item-label">Client</div>
                        <div class="ssw-account-item-value">
                            ${escHtml(data.client_name || '—')}
                        </div>
                    </div>
                    <div class="ssw-account-item">
                        <div class="ssw-account-item-label">Plan</div>
                        <div class="ssw-account-item-value">
                            ${escHtml(data.plan || '—')}
                        </div>
                    </div>
                    <div class="ssw-account-item">
                        <div class="ssw-account-item-label">Domain</div>
                        <div class="ssw-account-item-value">
                            ${escHtml(data.domain || '—')}
                        </div>
                    </div>
                    <div class="ssw-account-item">
                        <div class="ssw-account-item-label">Products Indexed</div>
                        <div class="ssw-account-item-value">
                            ${formatNumber(data.indexed_count)}
                        </div>
                    </div>
                </div>
            `);
        },

        resetSync() {
            if (!confirm('Reset sync state? This does not delete indexed products.')) return;

            ajax('ssw_reset_sync').done(res => {
                if (res.success) location.reload();
            });
        }
    };


    // ============================================================
    // UTILS
    // ============================================================

    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

}(jQuery));