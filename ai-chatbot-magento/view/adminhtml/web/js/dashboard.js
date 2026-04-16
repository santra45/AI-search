(function () {
    'use strict';

    const cfg = window.AiChatbotDashboard || {};

    document.addEventListener('DOMContentLoaded', init);

    function init() {
        if (!cfg.ajaxUrl) {
            return;
        }

        bindTabs();
        bindActions();
        renderSettings();
        loadDashboard();
        loadConversations();
        loadUsage();
        loadSync();
    }

    function bindTabs() {
        document.querySelectorAll('.aic-tabs button').forEach((button) => {
            button.addEventListener('click', () => {
                document.querySelectorAll('.aic-tabs button').forEach((item) => item.classList.remove('active'));
                document.querySelectorAll('.aic-panel').forEach((panel) => panel.classList.remove('active'));
                button.classList.add('active');
                document.querySelector('[data-panel="' + button.dataset.tab + '"]').classList.add('active');
            });
        });
    }

    function bindActions() {
        document.getElementById('aic-start-sync').addEventListener('click', () => syncAction('start_sync'));
        document.getElementById('aic-next-batch').addEventListener('click', () => syncAction('next_batch'));
        document.getElementById('aic-cancel-sync').addEventListener('click', () => syncAction('cancel_sync'));
        document.getElementById('aic-reset-sync').addEventListener('click', () => syncAction('reset_sync'));
    }

    function renderSettings() {
        const settings = cfg.settings || {};
        document.getElementById('aic-settings-summary').innerHTML = [
            ['Enabled', settings.enabled ? 'Yes' : 'No'],
            ['API URL', settings.api_url || '(using Semantic Search fallback if configured)'],
            ['LLM Provider', settings.llm_provider || '(fallback)'],
            ['LLM Model', settings.llm_model || '(fallback)'],
            ['Widget Title', settings.title || 'Store Assistant'],
            ['Auto Sync', settings.auto_sync_enabled ? 'Yes' : 'No']
        ].map((row) => '<div class="aic-row"><strong>' + escapeHtml(row[0]) + '</strong><span>' + escapeHtml(row[1]) + '</span></div>').join('');
    }

    async function loadDashboard() {
        const response = await request('dashboard_data');
        text('aic-chats-today', response.data.chats_today || 0);
        text('aic-chats-month', response.data.chats_month || 0);
        text('aic-unresolved', response.data.unresolved_chats || 0);
        text('aic-total-cost', '$' + Number(response.data.total_cost || 0).toFixed(4));
        document.getElementById('aic-content-counts').innerHTML = Object.entries(response.data.content_counts || {}).map(([key, value]) => {
            return '<div class="aic-row"><strong>' + escapeHtml(key) + '</strong><span>' + value + '</span></div>';
        }).join('');
    }

    async function loadConversations() {
        const response = await request('conversations_data');
        const conversations = response.data.conversations || [];
        const list = document.getElementById('aic-conversation-list');
        list.innerHTML = conversations.map((item) => {
            return '<button class="aic-conversation" data-id="' + escapeHtml(item.conversation_id) + '">' + escapeHtml(item.session_id) + '<small>' + escapeHtml(item.last_message_at || '') + '</small></button>';
        }).join('') || '<p>No conversations yet.</p>';

        list.querySelectorAll('button[data-id]').forEach((button) => {
            button.addEventListener('click', async () => {
                const transcript = await request('conversation_history', { conversation_id: button.dataset.id });
                const history = transcript.data.messages || [];
                document.getElementById('aic-conversation-history').innerHTML = history.map((item) => {
                    const content = item.message_text || item.response_text || '';
                    return '<div class="aic-message"><strong>' + escapeHtml(item.role) + '</strong><p>' + escapeHtml(content) + '</p></div>';
                }).join('');
            });
        });
    }

    async function loadUsage() {
        const response = await request('usage_data', { days: 30 });
        const summary = response.data.summary || {};
        const models = response.data.models || [];
        document.getElementById('aic-usage-summary').innerHTML =
            '<div class="aic-row"><strong>Total requests</strong><span>' + (summary.total_requests || 0) + '</span></div>' +
            '<div class="aic-row"><strong>Total tokens</strong><span>' + (summary.total_tokens || 0) + '</span></div>' +
            '<div class="aic-row"><strong>Total cost</strong><span>$' + Number(summary.total_cost || 0).toFixed(4) + '</span></div>';
        document.getElementById('aic-usage-models').innerHTML = models.map((item) => {
            return '<div class="aic-row"><strong>' + escapeHtml(item.llm_provider + ' / ' + item.llm_model) + '</strong><span>' + escapeHtml(item.query_type) + ' · $' + Number(item.total_cost || 0).toFixed(4) + '</span></div>';
        }).join('') || '<p>No usage yet.</p>';
    }

    async function loadSync() {
        const response = await request('sync_data');
        renderSync(response.data);
    }

    async function syncAction(actionName) {
        const feedback = document.getElementById('aic-sync-feedback');
        try {
            const response = await request(actionName);
            renderSync(response.data);
            feedback.textContent = 'Sync action completed.';
        } catch (error) {
            feedback.textContent = error.message;
        }
    }

    function renderSync(data) {
        const local = data.local_state || data;
        document.getElementById('aic-sync-status').innerHTML =
            '<div class="aic-row"><strong>Status</strong><span>' + escapeHtml(local.status || 'idle') + '</span></div>' +
            '<div class="aic-row"><strong>Current type</strong><span>' + escapeHtml(local.current_type || '-') + '</span></div>' +
            '<div class="aic-row"><strong>Processed</strong><span>' + (local.processed || 0) + ' / ' + (local.total || 0) + '</span></div>' +
            '<div class="aic-row"><strong>Product ready</strong><span>' + ((data.product_ready || false) ? 'Yes' : 'No') + '</span></div>';
    }

    async function request(actionName, payload) {
        const body = new URLSearchParams(Object.assign({}, payload || {}, { action_name: actionName, form_key: cfg.formKey }));
        const response = await fetch(cfg.ajaxUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: body.toString(),
            credentials: 'same-origin'
        });
        const json = await response.json();
        if (!json.success) {
            throw new Error(json.message || 'Request failed.');
        }
        return json;
    }

    function text(id, value) {
        const el = document.getElementById(id);
        if (el) {
            el.textContent = value;
        }
    }

    function escapeHtml(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }
}());
