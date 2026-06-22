/* ──────────────────────────────────────────────────────────────────────────
   JP AI Command Center  (window.JpAI)

   Loaded only for JP (jp_ai_mode). A single chat that replaces the sidebar:
   JP types → POST /api/jp/ai-command → { reply, action, chat_id }. The reply is
   rendered in the chat; the action is dispatched to open a section or pre-fill
   the task modal. A floating "Back to AI" button is injected whenever JP leaves
   the chat for a section, so he can always return.

   Reuses:
   - window.TessaChatModule.formatTessaReply  (markdown → HTML)
   - window.MeetingModule.switchView / escapeHtml
   - window.TasksModule.openTaskSlideover(null, prefill)
   ────────────────────────────────────────────────────────────────────────── */
(function () {
    'use strict';

    var config = window.__PORTAL_CONFIG || {};
    var currentChatId = null;
    var bound = false;
    var sending = false;
    var _typingSeq = 0;

    function lsKey() { return 'jpAiChatId:' + (config.userId || ''); }

    function csrf() {
        var m = document.querySelector('meta[name="csrf-token"]');
        return m ? m.content : '';
    }

    function escHtml(v) {
        if (window.MeetingModule && window.MeetingModule.escapeHtml) return window.MeetingModule.escapeHtml(v);
        return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function fmtReply(text) {
        if (window.TessaChatModule && window.TessaChatModule.formatTessaReply) {
            return window.TessaChatModule.formatTessaReply(text);
        }
        return '<p>' + escHtml(text) + '</p>';
    }

    function messagesEl() { return document.getElementById('jpAiMessages'); }

    // ── Rendering ────────────────────────────────────────────────────────────
    function showEmptyState() {
        var c = messagesEl();
        if (!c) return;
        var first = (config.userName || 'JP').split(' ')[0];
        c.innerHTML =
            '<div class="jp-ai-empty">' +
                '<span class="jp-ai-empty-spark">✦</span>' +
                '<h3>Hi ' + escHtml(first) + ', I\'m Tessa.</h3>' +
                '<p>Ask me to open any section or assign a task — I\'ll take you straight there.<br>' +
                'Try “show sign-in status”, “my team\'s leave”, or “assign a task to Fida”.</p>' +
            '</div>';
    }

    function appendMessage(role, htmlOrText, isHtml) {
        var c = messagesEl();
        if (!c) return null;
        var empty = c.querySelector('.jp-ai-empty');
        if (empty) empty.remove();

        var row = document.createElement('div');
        row.className = 'tessa-msg';

        var avatar = document.createElement('div');
        if (role === 'user') {
            avatar.className = 'tessa-msg-avatar user-avatar';
            avatar.textContent = (config.userName || 'J').charAt(0).toUpperCase();
        } else {
            avatar.className = 'tessa-msg-avatar tessa-avatar';
            avatar.textContent = 'T';
        }

        var content = document.createElement('div');
        content.className = 'tessa-msg-content';
        if (isHtml) content.innerHTML = htmlOrText;
        else content.textContent = htmlOrText;

        row.appendChild(avatar);
        row.appendChild(content);
        c.appendChild(row);
        c.scrollTop = c.scrollHeight;
        return row;
    }

    function appendTyping() {
        var c = messagesEl();
        if (!c) return null;
        var id = 'jpAiTyping' + (++_typingSeq);
        var row = document.createElement('div');
        row.className = 'tessa-msg';
        row.id = id;
        row.innerHTML =
            '<div class="tessa-msg-avatar tessa-avatar">T</div>' +
            '<div class="tessa-msg-content"><span class="jp-ai-dots"><span></span><span></span><span></span></span></div>';
        c.appendChild(row);
        c.scrollTop = c.scrollHeight;
        return id;
    }

    function removeTyping(id) {
        if (!id) return;
        var el = document.getElementById(id);
        if (el) el.remove();
    }

    // ── History ──────────────────────────────────────────────────────────────
    function loadHistory() {
        if (!currentChatId) { showEmptyState(); return; }
        fetch('/api/tessa/chats/' + currentChatId + '/messages', {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) {
                if (!data || !data.messages || !data.messages.length) { showEmptyState(); return; }
                var c = messagesEl();
                if (c) c.innerHTML = '';
                data.messages.forEach(function (m) {
                    if (m.role === 'user') appendMessage('user', m.content || m.text || '', false);
                    else if (m.role === 'assistant') appendMessage('assistant', fmtReply(m.content || m.text || ''), true);
                });
            })
            .catch(function () {
                // Stale / deleted chat id — start fresh.
                currentChatId = null;
                try { localStorage.removeItem(lsKey()); } catch (e) {}
                showEmptyState();
            });
    }

    function startNewChat() {
        currentChatId = null;
        try { localStorage.removeItem(lsKey()); } catch (e) {}
        var c = messagesEl();
        if (c) c.innerHTML = '';
        showEmptyState();
        var input = document.getElementById('jpAiInput');
        if (input) { input.value = ''; input.style.height = 'auto'; input.focus(); }
        syncSendBtn();
    }

    // ── Send ─────────────────────────────────────────────────────────────────
    function syncSendBtn() {
        var input = document.getElementById('jpAiInput');
        var btn = document.getElementById('jpAiSendBtn');
        if (input && btn) btn.disabled = sending || !input.value.trim();
    }

    function handleSend() {
        var input = document.getElementById('jpAiInput');
        if (!input || sending) return;
        var text = input.value.trim();
        if (!text) return;

        sending = true;
        input.value = '';
        input.style.height = 'auto';
        syncSendBtn();

        appendMessage('user', text, false);
        var typingId = appendTyping();

        fetch('/api/jp/ai-command', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrf(),
            },
            body: JSON.stringify({ message: text, chat_id: currentChatId }),
        })
            .then(function (r) { return r.json().catch(function () { return {}; }); })
            .then(function (data) {
                removeTyping(typingId);
                sending = false;
                syncSendBtn();

                if (data && data.chat_id) {
                    currentChatId = data.chat_id;
                    try { localStorage.setItem(lsKey(), String(currentChatId)); } catch (e) {}
                }

                var reply = (data && data.reply) ? data.reply : 'Sorry, something went wrong. Please try again.';
                appendMessage('assistant', fmtReply(reply), true);

                if (data && data.action && data.action.type && data.action.type !== 'none') {
                    // Let the reply paint first, then act.
                    setTimeout(function () { dispatch(data.action); }, 350);
                }
            })
            .catch(function () {
                removeTyping(typingId);
                sending = false;
                syncSendBtn();
                appendMessage('assistant', '<p>Sorry, I hit a network error. Please try again.</p>', true);
            });
    }

    // ── Action dispatch ──────────────────────────────────────────────────────
    // Every section view key present in the DOM for JP (features + the AI chat +
    // the meetings base view). We toggle visibility against THIS list rather than
    // trusting switchView's internal view list, which has proven unreliable here.
    function allViewKeys() {
        return (config.features || []).concat(['ai', 'meetings']);
    }

    // Show exactly one section, hide all others. Deterministic — cannot miss.
    function showOnlySection(section) {
        allViewKeys().forEach(function (v) {
            var el = document.getElementById(v + 'View');
            if (el) el.classList.toggle('hidden', v !== section);
        });
    }

    // Some sections (policies, employee records, …) embed a lazy iframe whose
    // real URL sits in data-src and is only set when the section opens. Trigger
    // that here so EVERY section shows content, not a blank frame.
    function lazyLoadIframes(section) {
        var view = document.getElementById(section + 'View');
        if (!view) return;
        view.querySelectorAll('iframe[data-src]').forEach(function (f) {
            if (!f.getAttribute('src')) f.setAttribute('src', f.getAttribute('data-src'));
        });
    }

    // Open a portal section. switchView() renders the section content (via
    // onSwitchView) and sets the active sidebar link so the hash syncs correctly
    // (no meetings-cascade). showOnlySection() then forces the visibility so the
    // section reliably appears even though switchView's own toggle does not, and
    // lazyLoadIframes() makes sure any embedded sheet/iframe actually loads.
    function openSection(section, tab) {
        if (!section) return;
        // The section's view element must exist in the DOM. If it doesn't, the AI
        // named a section that isn't rendered for JP — most often a STALE cached
        // portal.js/jp-ai.js from before the section was wired (an open tab keeps
        // the old script until reloaded). Surface it instead of failing silently.
        if (!document.getElementById(section + 'View')) {
            try { console.warn('[JP-AI] openSection: missing #' + section + 'View — likely a stale cached script; hard-reload.'); } catch (e) {}
            appendMessage('assistant', '<p>I couldn’t open that section just now. Please hard-refresh the page (Ctrl/Cmd-Shift-R) and try again.</p>', true);
            return;
        }
        if (window.MeetingModule && window.MeetingModule.switchView) {
            window.MeetingModule.switchView(section);
        }
        showOnlySection(section);
        lazyLoadIframes(section);
        injectBackBtn();
        if (tab) selectLeaveTab(tab);
    }

    // The Leave section has My Leaves / Team Leave sub-tabs and renders async, so
    // poll briefly for the tab button after switching and click it. "team" =
    // people who report to JP; "mine" = his own leave.
    function selectLeaveTab(tab) {
        var sel = tab === 'team' ? '[data-lvtab="team"]' : '[data-lvtab="mine"]';
        var tries = 0;
        var iv = setInterval(function () {
            var btn = document.querySelector('#leaveView ' + sel);
            if (btn) { btn.click(); clearInterval(iv); }
            else if (++tries > 25) clearInterval(iv); // give up after ~2.5s
        }, 100);
    }

    function dispatch(action) {
        if (!action || !action.type) return;
        var p = action.params || {};
        try {
            if (action.type === 'open_section') {
                if (p.section) openSection(p.section, p.tab);
                return;
            }

            if (action.type === 'open_task_new') {
                var prefill = {
                    assignee_id: p.assignee_id ? parseInt(p.assignee_id, 10) : null,
                    title: p.title || null,
                };
                if (window.TasksModule && window.TasksModule.openTaskSlideover) {
                    window.TasksModule.openTaskSlideover(null, prefill);
                } else {
                    openSection('tasks');
                }
                return;
            }

            if (action.type === 'open_task_edit') {
                var taskId = p.task_id ? parseInt(p.task_id, 10) : null;
                if (taskId && window.TasksModule && window.TasksModule.openTaskSlideover) {
                    window.TasksModule.openTaskSlideover(taskId);
                }
                return;
            }
        } catch (e) {
            console.error('[JpAI] dispatch error', action, e);
        }
    }

    // ── Floating "Back to AI" button ─────────────────────────────────────────
    // The section links are hidden, so this button is JP's way back to the chat.
    function backToAi() {
        if (window.MeetingModule && window.MeetingModule.switchView) {
            window.MeetingModule.switchView('ai'); // onSwitchView('ai') removes the button
        }
        showOnlySection('ai'); // deterministic — guarantee the chat is shown back
    }

    function injectBackBtn() {
        if (document.getElementById('jpAiBackBtn')) return;
        var btn = document.createElement('button');
        btn.id = 'jpAiBackBtn';
        btn.type = 'button';
        btn.className = 'jp-ai-back-btn';
        btn.innerHTML =
            '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>' +
            '<span>Back to AI</span>';
        btn.addEventListener('click', backToAi);
        document.body.appendChild(btn);
    }

    // Called by portal.js onSwitchView when the AI view is (re)entered.
    function onReturnToAi() {
        var btn = document.getElementById('jpAiBackBtn');
        if (btn) btn.remove();
    }

    // ── Bind / render ────────────────────────────────────────────────────────
    function renderView() {
        var view = document.getElementById('aiView');
        if (!view) return;

        if (!bound) {
            bound = true;

            try { currentChatId = parseInt(localStorage.getItem(lsKey()), 10) || null; } catch (e) { currentChatId = null; }

            var input = document.getElementById('jpAiInput');
            var sendBtn = document.getElementById('jpAiSendBtn');

            if (input) {
                input.addEventListener('input', function () {
                    input.style.height = 'auto';
                    input.style.height = Math.min(input.scrollHeight, 160) + 'px';
                    syncSendBtn();
                });
                input.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter' && !e.shiftKey) {
                        e.preventDefault();
                        handleSend();
                    }
                });
            }
            if (sendBtn) sendBtn.addEventListener('click', handleSend);

            view.querySelectorAll('.jp-ai-chip').forEach(function (chip) {
                chip.addEventListener('click', function () {
                    var msg = chip.getAttribute('data-msg');
                    if (msg === '__clear__') { startNewChat(); return; }
                    var inp = document.getElementById('jpAiInput');
                    if (inp) {
                        inp.value = msg || '';
                        syncSendBtn();
                        handleSend();
                    }
                });
            });

            loadHistory();
        }

        var inp2 = document.getElementById('jpAiInput');
        if (inp2) setTimeout(function () { inp2.focus(); }, 50);
    }

    window.JpAI = {
        renderView: renderView,
        dispatch: dispatch,
        onReturnToAi: onReturnToAi,
        startNewChat: startNewChat,
    };
})();
