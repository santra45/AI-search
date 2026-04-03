/* ============================================================
   USAGE Module for Semantic Search
   ============================================================ */

(function ($) {
    'use strict';

    const SSW_Usage = {
        hourlyChart: null,
        queryTypesChart: null,

        init() {
            if (!$('#usage-panel').length) return;
            this.load();
        },

        load() {
            const $panel = $('#usage-panel');
            $panel.find('.ssw-loading').show();

            if (!window.SSW_Config || !window.SSW_Config.current_license_key) {
                $panel.find('.ssw-content').hide();
                $panel.find('.ssw-loading').hide();
                return;
            }

            // Load all data in parallel
            $.when(
                this.ajax('ssw_token_usage_stats'),
                this.ajax('ssw_token_usage_models'),
                this.ajax('ssw_token_usage_hourly', { hours_back: 24 })
            ).done((statsRes, modelsRes, hourlyRes) => {
                try {
                    this.renderSummary(statsRes[0]);
                    this.renderModels(modelsRes[0]);
                    this.renderHourly(hourlyRes[0]);
                    this.renderQueryTypes(modelsRes[0]);
                } catch (e) {
                    $panel.find('.ssw-loading').html(
                        '<div class="ssw-notice error">Failed to load usage data.</div>'
                    );
                }
            }).fail(() => {
                $panel.find('.ssw-loading').html(
                    '<div class="ssw-notice error">Failed to load usage data.</div>'
                );
            }).always(() => {
                $panel.find('.ssw-loading').hide();
                $panel.find('.ssw-content').show();
            });
        },

        ajax(action, data = {}) {
            const SSW = window.SSW_Config || {};
            const nonce = SSW.nonce || '';
            const ajaxurl = SSW.ajaxurl || window.ajaxurl;
            
            return $.post(ajaxurl, { action, nonce, ...data });
        },

        renderSummary(res) {
            if (!res.success || !res.data) return;
            const data = res.data.data || res.data;
            
            $('#ssw-usage-total-requests').text(this.formatNumber(data.total_requests || 0));
            $('#ssw-usage-total-tokens').text(this.formatNumber(data.total_tokens || 0));
            $('#ssw-usage-total-cost').text('$' + Number(data.total_cost || 0).toFixed(6));
        },

        renderModels(res) {
            if (!res.success || !res.data) return;
            const models = res.data.data?.models || [];
            const $tbody = $('#ssw-usage-models-tbody');
            $tbody.empty();

            if (!models.length) {
                $tbody.html('<tr><td colspan="6"><div class="ssw-empty"><p>No usage data yet.</p></div></td></tr>');
                return;
            }

            models.forEach(model => {
                $tbody.append(`
                    <tr>
                        <td>${this.escHtml(model.llm_provider)}</td>
                        <td>${this.escHtml(model.llm_model)}</td>
                        <td><span class="ssw-badge">${this.escHtml(model.query_type)}</span></td>
                        <td>${this.formatNumber(model.request_count)}</td>
                        <td>${this.formatNumber(model.total_tokens)}</td>
                        <td>$${Number(model.total_cost || 0).toFixed(6)}</td>
                    </tr>
                `);
            });
        },

        renderHourly(res) {
            if (!res.success || !res.data) return;
            const hourly = res.data.data?.hourly_data || [];
            const ctx = document.getElementById('ssw-usage-hourly-chart');
            if (!ctx) return;

            const labels = hourly.map(h => new Date(h.hour).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }));
            const tokens = hourly.map(h => h.total_tokens || 0);
            const costs = hourly.map(h => h.total_cost || 0);

            if (this.hourlyChart) this.hourlyChart.destroy();

            this.hourlyChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels,
                    datasets: [
                        {
                            label: 'Tokens',
                            data: tokens,
                            borderColor: 'rgba(34, 113, 177, 0.8)',
                            backgroundColor: 'rgba(34, 113, 177, 0.1)',
                            yAxisID: 'y',
                        },
                        {
                            label: 'Cost ($)',
                            data: costs,
                            borderColor: 'rgba(22, 163, 74, 0.8)',
                            backgroundColor: 'rgba(22, 163, 74, 0.1)',
                            yAxisID: 'y1',
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: {
                        mode: 'index',
                        intersect: false,
                    },
                    scales: {
                        y: {
                            type: 'linear',
                            display: true,
                            position: 'left',
                        },
                        y1: {
                            type: 'linear',
                            display: true,
                            position: 'right',
                            grid: {
                                drawOnChartArea: false,
                            },
                        }
                    }
                }
            });
        },

        renderQueryTypes(res) {
            if (!res.success || !res.data) return;
            const models = res.data.data?.models || [];
            const ctx = document.getElementById('ssw-usage-query-types-chart');
            if (!ctx) return;

            // Group by query type
            const queryTypes = {};
            models.forEach(model => {
                const type = model.query_type;
                if (!queryTypes[type]) {
                    queryTypes[type] = { requests: 0, tokens: 0, cost: 0 };
                }
                queryTypes[type].requests += model.request_count;
                queryTypes[type].tokens += model.total_tokens;
                queryTypes[type].cost += model.total_cost || 0;
            });

            const labels = Object.keys(queryTypes);
            const data = labels.map(type => queryTypes[type].cost);

            if (this.queryTypesChart) this.queryTypesChart.destroy();

            this.queryTypesChart = new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: labels.map(l => l.replace('_', ' ').toUpperCase()),
                    datasets: [{
                        data,
                        backgroundColor: [
                            'rgba(34, 113, 177, 0.8)',
                            'rgba(22, 163, 74, 0.8)',
                            'rgba(249, 115, 22, 0.8)',
                        ],
                        borderWidth: 2,
                        borderColor: '#fff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom'
                        }
                    }
                }
            });
        },

        formatNumber(n) {
            return Number(n).toLocaleString();
        },

        escHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    };

    // Auto-initialize when document is ready
    $(document).ready(function () {
        // Initialize Usage module
        if (typeof SSW_Usage !== 'undefined') {
            SSW_Usage.init();
        }
    });

    // Make it available globally
    window.SSW_Usage = SSW_Usage;

})(jQuery);
