(function () {
    'use strict';

    let cfg = {};

    const state = {
        analyticsDays: 7,
        volumeChart: null,
        hourlyChart: null,
        queryTypeChart: null,
        syncing: false,
    };

    document.addEventListener('DOMContentLoaded', init);

    function init() {
        cfg = window.SemanticSearchDashboard || {};
        if (!cfg.ajaxUrl) {
            return;
        }

        state.syncing = !!(cfg.sync && cfg.sync.status === 'running');
        bindTabs();
        hydrateSettings();
        bindActions();
        renderSync(cfg.sync || {});
        loadAll();
        if (state.syncing) {
            processNextBatch();
        }
    }

    function bindTabs() {
        document.querySelectorAll('.ssm-tabs button').forEach((button) => {
            button.addEventListener('click', () => {
                document.querySelectorAll('.ssm-tabs button').forEach((item) => item.classList.remove('active'));
                document.querySelectorAll('.ssm-panel').forEach((panel) => panel.classList.remove('active'));
                button.classList.add('active');
                document.querySelector('[data-panel="' + button.dataset.tab + '"]').classList.add('active');
            });
        });
    }

    function hydrateSettings() {
        const settings = cfg.settings || {};
        setValue('ssm-api-url', settings.api_url || '');
        setValue('ssm-api-key', settings.api_key || '');
        setValue('ssm-license-key', settings.license_key || '');
        setValue('ssm-result-limit', settings.result_limit || 10);
        setChecked('ssm-enable-intent', !!settings.enable_intent);
        setValue('ssm-llm-provider', settings.llm_provider || '');
        setValue('ssm-llm-model', settings.llm_model || '');
        setValue('ssm-llm-api-key', settings.llm_api_key || '');
        setChecked('ssm-auto-sync-enabled', !!settings.auto_sync_enabled);
        setValue('ssm-cron-expression', settings.cron_expression || '0 * * * *');
        setValue('ssm-batch-size', settings.batch_size || 20);
    }

    function bindActions() {
        document.getElementById('ssm-settings-form').addEventListener('submit', saveSettings);
        document.getElementById('ssm-test-connection').addEventListener('click', testConnection);
        document.getElementById('ssm-start-sync').addEventListener('click', startSync);
        document.getElementById('ssm-cancel-sync').addEventListener('click', cancelSync);
        document.getElementById('ssm-reset-sync').addEventListener('click', resetSync);
        document.getElementById('ssm-refresh-status').addEventListener('click', loadStatus);
        document.getElementById('ssm-analytics-days').addEventListener('change', (event) => {
            state.analyticsDays = parseInt(event.target.value, 10);
            loadAnalytics();
        });
    }

    async function loadAll() {
        await Promise.allSettled([loadDashboard(), loadAnalytics(), loadUsage(), loadStatus()]);
    }

    async function loadDashboard() {
        const response = await request('dashboard_data');
        const data = response.data;
        text('ssm-search-count', formatNumber(data.searches.used));
        text('ssm-index-count', formatNumber(data.products.indexed));
        text('ssm-ingestion-count', formatNumber(data.ingestions.this_month));
        text('ssm-plan-name', data.plan || '-');
        renderQuota('ssm-search-quota', 'Search quota', data.searches.used, data.searches.limit);
        renderQuota('ssm-product-quota', 'Product quota', data.products.indexed, data.products.limit);
        renderRecentSearches(data.recent_searches || []);
    }

    async function loadAnalytics() {
        const response = await request('analytics_data', { days: state.analyticsDays });
        const summary = response.data.summary || {};
        const topQueries = (response.data.top_queries || {}).queries || [];
        const zeroResults = (response.data.zero_results || {}).queries || [];

        text('ssm-cache-rate', (summary.cache_hit_rate || 0) + '%');
        text('ssm-avg-response', (summary.avg_response_ms || 0) + 'ms');
        text('ssm-zero-rate', (summary.zero_result_rate || 0) + '%');
        text('ssm-total-searches', formatNumber(summary.total_searches || 0));

        renderTable('ssm-top-queries', topQueries, function (row) {
            return '<tr><td>' + escapeHtml(row.query) + '</td><td>' + formatNumber(row.count) + '</td><td>' + Number(row.avg_results || 0).toFixed(1) + '</td></tr>';
        });
        renderTable('ssm-zero-results', zeroResults, function (row) {
            return '<tr><td>' + escapeHtml(row.query) + '</td><td>' + formatNumber(row.count) + '</td></tr>';
        });
        renderBarChart('ssm-volume-chart', summary.daily_volume || { labels: [], values: [] }, 'Searches', 'volumeChart');
    }

    async function loadUsage() {
        const response = await request('usage_data');
        const summary = response.data.summary || {};
        const models = response.data.models && response.data.models.data ? response.data.models.data.models : [];
        const hourly = response.data.hourly && response.data.hourly.data ? response.data.hourly.data.hourly_data : [];
        const stats = response.data.stats && response.data.stats.data ? response.data.stats.data : {};
        const usageByType = stats.usage_by_type || [];

        text('ssm-usage-requests', formatNumber(summary.data ? summary.data.total_requests : 0));
        text('ssm-usage-tokens', formatNumber(summary.data ? summary.data.total_tokens : 0));
        text('ssm-usage-cost', formatCurrency(summary.data ? summary.data.total_cost : 0));

        renderTable('ssm-usage-models', models, function (row) {
            return '<tr><td>' + escapeHtml(row.llm_provider || '-') + '</td><td>' + escapeHtml(row.llm_model || '-') + '</td><td>' + escapeHtml(row.query_type || '-') + '</td><td>' + formatNumber(row.request_count || 0) + '</td><td>' + formatCurrency(row.total_cost || 0) + '</td></tr>';
        });

        renderLineChart(
            'ssm-hourly-chart',
            {
                labels: hourly.map((row) => row.hour),
                values: hourly.map((row) => row.total_cost || 0),
            },
            'Hourly cost',
            'hourlyChart'
        );
        renderPieChart(
            'ssm-query-type-chart',
            usageByType.map((row) => row.query_type || 'unknown'),
            usageByType.map((row) => row.total_cost || 0),
            'queryTypeChart'
        );
    }

    async function loadStatus() {
        const response = await request('status_data');
        const data = response.data;
        text('ssm-connection-pill', data.api_reachable ? 'Connected' : 'Connection issue');
        renderStatusRows(data);
        renderAccount(data);
        renderDebug(data);
    }

    async function saveSettings(event) {
        event.preventDefault();
        const feedback = document.getElementById('ssm-settings-feedback');

        try {
            const payload = collectSettingsPayload();
            await request('save_settings', payload, 'POST');
            cfg.settings = payload;
            setFeedback(feedback, 'Settings saved successfully.', 'success');
            await loadStatus();
        } catch (error) {
            setFeedback(feedback, error.message, 'error');
        }
    }

    async function testConnection() {
        const feedback = document.getElementById('ssm-settings-feedback');
        try {
            const response = await request('test_connection');
            setFeedback(feedback, response.data.message || 'Connection successful.', 'success');
        } catch (error) {
            setFeedback(feedback, error.message, 'error');
        }
    }

    async function startSync() {
        const feedback = document.getElementById('ssm-sync-feedback');
        try {
            const response = await request('start_sync', {}, 'POST');
            state.syncing = response.data.status === 'running';
            renderSync(response.data);
            setFeedback(feedback, 'Full catalog sync started.', 'success');
            if (state.syncing) {
                processNextBatch();
            }
        } catch (error) {
            setFeedback(feedback, error.message, 'error');
        }
    }

    async function processNextBatch() {
        if (!state.syncing) {
            return;
        }

        try {
            const response = await request('next_batch', {}, 'POST');
            renderSync(response.data);
            if (response.data.status === 'running') {
                setTimeout(processNextBatch, 500);
            } else {
                state.syncing = false;
            }
        } catch (error) {
            state.syncing = false;
            setFeedback(document.getElementById('ssm-sync-feedback'), error.message, 'error');
        }
    }

    async function cancelSync() {
        const feedback = document.getElementById('ssm-sync-feedback');
        try {
            const response = await request('cancel_sync', {}, 'POST');
            state.syncing = false;
            renderSync(response.data);
            setFeedback(feedback, 'Sync cancelled.', 'success');
        } catch (error) {
            setFeedback(feedback, error.message, 'error');
        }
    }

    async function resetSync() {
        const feedback = document.getElementById('ssm-sync-feedback');
        try {
            const response = await request('reset_sync', {}, 'POST');
            renderSync(response.data);
            setFeedback(feedback, 'Sync state reset.', 'success');
        } catch (error) {
            setFeedback(feedback, error.message, 'error');
        }
    }

    function renderSync(sync) {
        text('ssm-sync-status', title(sync.status || 'idle'));
        text('ssm-sync-progress', (sync.percentage || 0) + '%');
        text('ssm-sync-processed', formatNumber(sync.processed || 0) + ' / ' + formatNumber(sync.total || 0));
        document.getElementById('ssm-progress-bar').style.width = (sync.percentage || 0) + '%';
        text('ssm-sync-detail', sync.last_error ? 'Last error: ' + sync.last_error : 'Batch ' + (sync.current_batch || 0) + ' of ' + (sync.total_batches || 0) + '.');
    }

    function renderQuota(targetId, label, used, limit) {
        const percentage = limit ? Math.min(Math.round((used / limit) * 100), 100) : 0;
        document.getElementById(targetId).innerHTML =
            '<p>' + label + ': ' + formatNumber(used) + ' / ' + formatNumber(limit) + '</p>' +
            '<div class="ssm-progress"><div style="width:' + percentage + '%;background:#0f4c81;height:100%;"></div></div>';
    }

    function renderRecentSearches(rows) {
        renderTable('ssm-recent-searches', rows, function (row) {
            return '<tr><td>' + escapeHtml(row.query) + '</td><td>' + formatNumber(row.results_count || 0) + '</td><td>' + (row.response_time_ms || 0) + 'ms</td><td>' + (row.cached ? 'Cached' : 'API') + '</td></tr>';
        });
    }

    function renderStatusRows(data) {
        const rows = [
            ['API server', data.api_reachable ? 'Reachable' : 'Unavailable'],
            ['License', data.license_valid ? 'Valid' : 'Missing or invalid'],
            ['Search active', data.search_active ? 'Enabled' : 'Disabled'],
            ['Automatic sync', data.automatic_sync_ok ? 'Observers + cron ready' : 'Unavailable'],
            ['Current sync', title(data.sync_status || 'idle')],
        ];
        document.getElementById('ssm-status-rows').innerHTML = rows.map(function (row) {
            return '<div class="ssm-status-row"><strong>' + row[0] + '</strong><span>' + row[1] + '</span></div>';
        }).join('');
    }

    function renderAccount(data) {
        const rows = [
            ['Client', data.client_name || '-'],
            ['Plan', data.plan || '-'],
            ['Domain', data.domain || '-'],
            ['Indexed products', formatNumber(data.indexed_count || 0)],
        ];
        document.getElementById('ssm-account-details').innerHTML = rows.map(function (row) {
            return '<div class="ssm-status-row"><strong>' + row[0] + '</strong><span>' + escapeHtml(row[1]) + '</span></div>';
        }).join('');
    }

    function renderDebug(data) {
        const rows = [
            ['Module enabled', cfg.settings.enabled ? 'Yes' : 'No'],
            ['Scheduled sync', data.cron_enabled ? 'Enabled' : 'Disabled'],
            ['Last progress', String(data.sync_progress || 0) + '%'],
            ['Last error', data.last_error || '-'],
            ['Endpoint URL', cfg.settings.api_url || '-'],
            ['Result limit', String(cfg.settings.result_limit || 10)],
        ];
        document.getElementById('ssm-debug-details').innerHTML = rows.map(function (row) {
            return '<div class="ssm-debug-row"><strong>' + row[0] + '</strong><span>' + escapeHtml(row[1]) + '</span></div>';
        }).join('');
    }

    function collectSettingsPayload() {
        return {
            api_url: getValue('ssm-api-url'),
            api_key: getValue('ssm-api-key'),
            license_key: getValue('ssm-license-key'),
            result_limit: getValue('ssm-result-limit'),
            enable_intent: document.getElementById('ssm-enable-intent').checked ? 1 : 0,
            llm_provider: getValue('ssm-llm-provider'),
            llm_model: getValue('ssm-llm-model'),
            llm_api_key: getValue('ssm-llm-api-key'),
            auto_sync_enabled: document.getElementById('ssm-auto-sync-enabled').checked ? 1 : 0,
            cron_expression: getValue('ssm-cron-expression'),
            batch_size: getValue('ssm-batch-size'),
        };
    }

    async function request(actionName, payload, method) {
        const body = new URLSearchParams(Object.assign({}, payload || {}, { action_name: actionName, form_key: cfg.formKey }));
        const response = await fetch(cfg.ajaxUrl, {
            method: method || 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: body.toString(),
            credentials: 'same-origin',
        });
        const json = await response.json();
        if (!json.success) {
            throw new Error(json.message || 'Request failed.');
        }
        return json;
    }

    function renderTable(targetId, rows, renderer) {
        const target = document.getElementById(targetId);
        if (!rows || rows.length === 0) {
            target.innerHTML = '<tr><td colspan="5">No data yet.</td></tr>';
            return;
        }
        target.innerHTML = rows.map(renderer).join('');
    }

    function renderBarChart(canvasId, data, label, stateKey) {
        if (typeof Chart === 'undefined') {
            return;
        }
        destroyChart(stateKey);
        state[stateKey] = new Chart(document.getElementById(canvasId), {
            type: 'bar',
            data: {
                labels: data.labels || [],
                datasets: [{ label: label, data: data.values || [], backgroundColor: '#87c5ff', borderColor: '#0f4c81', borderWidth: 2 }],
            },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } },
        });
    }

    function renderLineChart(canvasId, data, label, stateKey) {
        if (typeof Chart === 'undefined') {
            return;
        }
        destroyChart(stateKey);
        state[stateKey] = new Chart(document.getElementById(canvasId), {
            type: 'line',
            data: {
                labels: data.labels || [],
                datasets: [{ label: label, data: data.values || [], borderColor: '#0f4c81', backgroundColor: 'rgba(15,76,129,0.15)', fill: true }],
            },
            options: { responsive: true, maintainAspectRatio: false },
        });
    }

    function renderPieChart(canvasId, labels, values, stateKey) {
        if (typeof Chart === 'undefined') {
            return;
        }
        destroyChart(stateKey);
        state[stateKey] = new Chart(document.getElementById(canvasId), {
            type: 'doughnut',
            data: { labels: labels, datasets: [{ data: values, backgroundColor: ['#0f4c81', '#3aa0ff', '#78c0ff', '#dcefff'] }] },
            options: { responsive: true, maintainAspectRatio: false },
        });
    }

    function destroyChart(key) {
        if (state[key]) {
            state[key].destroy();
        }
    }

    function setFeedback(element, message, type) {
        element.textContent = message;
        element.className = 'ssm-feedback ' + type;
    }

    function text(id, value) {
        const element = document.getElementById(id);
        if (element) {
            element.textContent = value;
        }
    }

    function setValue(id, value) {
        const element = document.getElementById(id);
        if (element) {
            element.value = value;
        }
    }

    function getValue(id) {
        return document.getElementById(id).value;
    }

    function setChecked(id, value) {
        const element = document.getElementById(id);
        if (element) {
            element.checked = value;
        }
    }

    function formatNumber(value) {
        return Number(value || 0).toLocaleString();
    }

    function formatCurrency(value) {
        return '$' + Number(value || 0).toFixed(4);
    }

    function title(value) {
        return String(value || '').replace(/(^|_)([a-z])/g, function (_, prefix, chr) {
            return (prefix ? ' ' : '') + chr.toUpperCase();
        });
    }

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }
}());
