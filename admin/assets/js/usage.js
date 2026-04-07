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
                    this.renderEstimatedCost(hourlyRes[0]);
                    this.renderTodayUsage(hourlyRes[0]);
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
            const payload  = res.data?.data?.data || res.data?.data || res.data;
            const totals = payload.totals || {};
            $('#ssw-usage-total-requests').text(this.formatNumber(totals.total_requests || 0));
            $('#ssw-usage-total-tokens').text(this.formatNumber(totals.total_tokens || 0));
            $('#ssw-usage-total-cost').text('$' + Number(totals.total_cost || 0).toFixed(6));
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

            // Clear any existing chart
            if (this.hourlyChart) {
                this.hourlyChart.destroy();
                this.hourlyChart = null;
            }

            // Handle empty data
            if (!hourly.length) {
                // Show empty state message
                ctx.parentElement.innerHTML = '<div class="ssw-empty"><p>No hourly usage data yet.</p></div>';
                return;
            }

            const labels = hourly.map(h => new Date(h.hour).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }));
            const tokens = hourly.map(h => h.total_tokens || 0);
            const costs = hourly.map(h => h.total_cost || 0);

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

            // Clear any existing chart
            if (this.queryTypesChart) {
                this.queryTypesChart.destroy();
                this.queryTypesChart = null;
            }

            // Handle empty data
            if (!models.length) {
                // Show empty state message
                ctx.parentElement.innerHTML = '<div class="ssw-empty"><p>No query type data yet.</p></div>';
                return;
            }

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

        renderEstimatedCost(res) {
            if (!res.success || !res.data) return;

            const hourly = res.data.data?.hourly_data || [];
            const $tbody = $('#ssw-usage-estimated-cost-tbody');
            $tbody.empty();

            if (!hourly.length) {
                $tbody.html('<tr><td colspan="5"><div class="ssw-empty"><p>No usage data for cost estimation.</p></div></td></tr>');
                return;
            }

            // Aggregate hourly → daily
            let dailyData = this.aggregateHourlyToDaily(hourly);

            if (!dailyData.length) {
                $tbody.html('<tr><td colspan="5"><div class="ssw-empty"><p>Insufficient data for cost estimation.</p></div></td></tr>');
                return;
            }

            // Remove outliers (important before calculations)
            dailyData = this.removeOutliers(dailyData, 'cost');

            const now = new Date();
            const daysInMonth = new Date(now.getFullYear(), now.getMonth() + 1, 0).getDate();

            const estimates = [
                {
                    period: 'Last 24h (Actual)',
                    requests: dailyData[0]?.requests || 0,
                    cost: dailyData[0]?.cost || 0
                },
                {
                    period: '3-Day Weighted Avg',
                    requests: this.calculateWeightedAverage(dailyData.slice(0, 3), 'requests'),
                    cost: this.calculateWeightedAverage(dailyData.slice(0, 3), 'cost')
                },
                {
                    period: '7-Day Weighted Avg',
                    requests: this.calculateWeightedAverage(dailyData.slice(0, 7), 'requests'),
                    cost: this.calculateWeightedAverage(dailyData.slice(0, 7), 'cost')
                },
                {
                    period: 'Trend Adjusted',
                    requests: this.calculateProjectedMonthlyRequests(dailyData) / daysInMonth,
                    cost: this.calculateProjectedMonthlyCost(dailyData) / daysInMonth
                }
            ];

            const confidence = dailyData.length >= 7 ? 'High' : 'Low';

            estimates.forEach((estimate) => {
                const monthlyCost = estimate.cost * daysInMonth;
                const trend = this.getTrendIndicator(dailyData, estimate.cost);

                $tbody.append(`
                    <tr>
                        <td><strong>${this.escHtml(estimate.period)}</strong></td>
                        <td>${this.formatNumber(Math.round(estimate.requests || 0))}</td>
                        <td>$${Number(estimate.cost || 0).toFixed(6)}</td>
                        <td><strong>$${Number(monthlyCost || 0).toFixed(4)}</strong></td>
                        <td>${trend} (${confidence})</td>
                    </tr>
                `);
            });
        },

        calculateWeightedAverage(data, key) {
            if (!data.length) return 0;

            let totalWeight = 0;
            let weightedSum = 0;

            data.forEach((item, index) => {
                const weight = data.length - index;
                weightedSum += (item[key] || 0) * weight;
                totalWeight += weight;
            });

            return weightedSum / totalWeight;
        },

        removeOutliers(data, key) {
            if (data.length < 4) return data;

            const values = data.map(d => d[key]).sort((a, b) => a - b);

            const q1 = values[Math.floor(values.length * 0.25)];
            const q3 = values[Math.floor(values.length * 0.75)];
            const iqr = q3 - q1;

            return data.filter(d => {
                const v = d[key];
                return v >= (q1 - 1.5 * iqr) && v <= (q3 + 1.5 * iqr);
            });
        },

        calculateTrendSlope(data, key) {
            if (data.length < 2) return 0;

            let sumDiff = 0;

            for (let i = 1; i < data.length; i++) {
                sumDiff += (data[i - 1][key] - data[i][key]);
            }

            return sumDiff / (data.length - 1);
        },

        calculateProjectedMonthlyCost(dailyData) {
            if (!dailyData.length) return 0;

            const slope = this.calculateTrendSlope(dailyData, 'cost');
            let current = dailyData[0]?.cost || 0;
            let total = 0;

            for (let i = 0; i < 30; i++) {
                current += slope;
                total += Math.max(current, 0);
            }

            return total;
        },

        calculateProjectedMonthlyRequests(dailyData) {
            if (!dailyData.length) return 0;

            const slope = this.calculateTrendSlope(dailyData, 'requests');
            let current = dailyData[0]?.requests || 0;
            let total = 0;

            for (let i = 0; i < 30; i++) {
                current += slope;
                total += Math.max(current, 0);
            }

            return total;
        },

        getTrendIndicator(data, currentCost) {
            const slope = this.calculateTrendSlope(data, 'cost');

            if (slope > 0.001) return '📈 Increasing';
            if (slope < -0.001) return '📉 Decreasing';
            return '➡️ Stable';
        },

        renderTodayUsage(res) {
            if (!res.success || !res.data) return;
            const hourly = res.data.data?.hourly_data || [];
            const $tbody = $('#ssw-today-usage-tbody');
            $tbody.empty();

            if (!hourly.length) {
                $tbody.html('<tr><td colspan="7"><div class="ssw-empty"><p>No usage data for today.</p></div></td></tr>');
                return;
            }

            // Get today's date and filter hourly data for today
            const today = new Date().toDateString();
            const todayData = hourly.filter(h => 
                new Date(h.hour).toDateString() === today
            );

            if (!todayData.length) {
                $tbody.html('<tr><td colspan="7"><div class="ssw-empty"><p>No usage data for today.</p></div></td></tr>');
                return;
            }

            // Sort by time (newest first)
            todayData.sort((a, b) => new Date(b.hour) - new Date(a.hour));

            todayData.forEach(hour => {
                const time = new Date(hour.hour).toLocaleTimeString([], { 
                    hour: '2-digit', 
                    minute: '2-digit' 
                });
                const provider = hour.llm_provider || 'Unknown';
                const model = hour.llm_model || 'Unknown';
                const type = hour.query_type || 'Unknown';
                const requests = hour.request_count || 0;
                const tokens = hour.total_tokens || 0;
                const cost = hour.total_cost || 0;

                $tbody.append(`
                    <tr>
                        <td><strong>${time}</strong></td>
                        <td><span class="ssw-badge">${this.escHtml(provider)}</span></td>
                        <td>${this.escHtml(model)}</td>
                        <td><span class="ssw-badge">${this.escHtml(type)}</span></td>
                        <td>${this.formatNumber(requests)}</td>
                        <td>${this.formatNumber(tokens)}</td>
                        <td><strong>$${Number(cost).toFixed(6)}</strong></td>
                    </tr>
                `);
            });
        },

        aggregateHourlyToDaily(hourly) {
            const dailyMap = new Map();
            
            hourly.forEach(hour => {
                const date = new Date(hour.hour).toDateString();
                if (!dailyMap.has(date)) {
                    dailyMap.set(date, {
                        date: date,
                        requests: 0,
                        cost: 0,
                        tokens: 0
                    });
                }
                const day = dailyMap.get(date);
                day.requests += (hour.request_count || 0);
                day.cost += (hour.total_cost || 0);
                day.tokens += (hour.total_tokens || 0);
            });

            return Array.from(dailyMap.values()).sort((a, b) => 
                new Date(b.date) - new Date(a.date)
            );
        },

        calculateAverage(data, field) {
            if (!data.length) return 0;
            const sum = data.reduce((acc, item) => acc + (item[field] || 0), 0);
            return sum / data.length;
        },

        calculateTrend(dailyData, currentCost) {
            if (dailyData.length < 2) return '<span class="ssw-badge neutral">—</span>';
            
            const previousCost = dailyData[1]?.cost || 0;
            if (previousCost === 0) return '<span class="ssw-badge neutral">—</span>';
            
            const change = ((currentCost - previousCost) / previousCost) * 100;
            
            if (Math.abs(change) < 5) {
                return '<span class="ssw-badge neutral">→ Stable</span>';
            } else if (change > 0) {
                return `<span class="ssw-badge danger">↑ ${Math.abs(change).toFixed(1)}%</span>`;
            } else {
                return `<span class="ssw-badge success">↓ ${Math.abs(change).toFixed(1)}%</span>`;
            }
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
