(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', init);

    function init() {
        const root = document.getElementById('czar-ai-chatbot');
        if (!root) {
            return;
        }

        const cfg = JSON.parse(root.dataset.config || '{}');
        root.innerHTML = '' +
            '<button class="czar-chat-launcher czar-chat-' + escapeHtml(cfg.position || 'right') + '" type="button">Chat</button>' +
            '<div class="czar-chat-panel">' +
                '<div class="czar-chat-head"><strong>' + escapeHtml(cfg.title || 'Store Assistant') + '</strong><button type="button" data-action="reset">Reset</button></div>' +
                '<div class="czar-chat-body"><div class="czar-chat-message czar-chat-assistant">' + escapeHtml(cfg.welcomeMessage || '') + '</div></div>' +
                '<div class="czar-chat-prompts"></div>' +
                '<form class="czar-chat-form"><input type="text" placeholder="Ask a question" /><button type="submit">Send</button></form>' +
            '</div>';

        const launcher = root.querySelector('.czar-chat-launcher');
        const panel = root.querySelector('.czar-chat-panel');
        const body = root.querySelector('.czar-chat-body');
        const form = root.querySelector('.czar-chat-form');
        const input = form.querySelector('input');
        const prompts = root.querySelector('.czar-chat-prompts');

        (cfg.starterPrompts || []).forEach((prompt) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'czar-chat-prompt';
            button.textContent = prompt;
            button.addEventListener('click', function () {
                input.value = prompt;
                form.dispatchEvent(new Event('submit', { cancelable: true }));
            });
            prompts.appendChild(button);
        });

        launcher.style.backgroundColor = cfg.themeColor || '#0f4c81';
        launcher.addEventListener('click', async function () {
            panel.classList.toggle('is-open');
            if (panel.classList.contains('is-open')) {
                await request(cfg.startUrl);
                const history = await request(cfg.historyUrl);
                renderHistory(body, history.data.messages || [], cfg.welcomeMessage || '');
            }
        });

        root.querySelector('[data-action="reset"]').addEventListener('click', async function () {
            await request(cfg.resetUrl);
            body.innerHTML = '<div class="czar-chat-message czar-chat-assistant">' + escapeHtml(cfg.welcomeMessage || '') + '</div>';
        });

        form.addEventListener('submit', async function (event) {
            event.preventDefault();
            const message = input.value.trim();
            if (!message) {
                return;
            }

            appendMessage(body, message, 'user');
            input.value = '';
            const response = await request(cfg.messageUrl, { message: message });
            appendMessage(body, response.data.answer || '', 'assistant');
        });
    }

    async function request(url, payload) {
        const body = new URLSearchParams(payload || {});
        const response = await fetch(url, {
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

    function renderHistory(container, messages, welcomeMessage) {
        const html = [];
        if (welcomeMessage) {
            html.push('<div class="czar-chat-message czar-chat-assistant">' + escapeHtml(welcomeMessage) + '</div>');
        }
        messages.forEach((message) => {
            const content = message.message_text || message.response_text || '';
            html.push('<div class="czar-chat-message czar-chat-' + escapeHtml(message.role || 'assistant') + '">' + escapeHtml(content) + '</div>');
        });
        container.innerHTML = html.join('');
        container.scrollTop = container.scrollHeight;
    }

    function appendMessage(container, text, role) {
        const div = document.createElement('div');
        div.className = 'czar-chat-message czar-chat-' + role;
        div.textContent = text;
        container.appendChild(div);
        container.scrollTop = container.scrollHeight;
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
