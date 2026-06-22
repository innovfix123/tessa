(function () {
    'use strict';

    var config = window.__PORTAL_CONFIG || {};
    function escapeHtml(v) { return MeetingModule.escapeHtml(v); }

    var TESSA_STORAGE_KEY = 'tessa_chats_' + (config.userId || 'anon');

    function formatTessaReply(text) {
        if (!text || typeof text !== 'string') return '';
        var s = escapeHtml(text);
        s = s.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
        s = s.replace(/\*(.+?)\*/g, '<em>$1</em>');
        s = s.replace(/`(.+?)`/g, '<code>$1</code>');
        var lines = s.split(/\n/);
        var out = [];
        var inList = false;
        var paraLines = [];
        var tableRows = [];
        function flushPara() {
            if (paraLines.length) {
                out.push('<p>' + paraLines.join('<br>') + '</p>');
                paraLines.length = 0;
            }
        }
        function flushTable() {
            if (tableRows.length === 0) return;
            var html = '<div class="tessa-table-wrap"><table><thead><tr>';
            tableRows[0].forEach(function (cell) { html += '<th>' + cell + '</th>'; });
            html += '</tr></thead><tbody>';
            for (var r = 1; r < tableRows.length; r++) {
                html += '<tr>';
                tableRows[r].forEach(function (cell) { html += '<td>' + cell + '</td>'; });
                html += '</tr>';
            }
            html += '</tbody></table></div>';
            out.push(html);
            tableRows.length = 0;
        }
        for (var i = 0; i < lines.length; i++) {
            var line = lines[i];
            var bullet = /^[\-\*]\s+(.+)$/.exec(line);
            var isTableRow = /^\s*\|.+\|\s*$/.test(line);
            var isTableSep = /^\s*\|[\s\-:|]+\|\s*$/.test(line);
            var h3 = /^##\s+(.+)$/.exec(line);
            var h4 = /^###\s+(.+)$/.exec(line);
            var isHr = /^[\*\-\_]{3,}\s*$/.test(line.trim());
            if (bullet) {
                flushPara();
                flushTable();
                if (!inList) { out.push('<ul>'); inList = true; }
                out.push('<li>' + bullet[1] + '</li>');
            } else if (isTableRow && !isTableSep) {
                flushPara();
                if (inList) { out.push('</ul>'); inList = false; }
                var cells = line.split('|').slice(1, -1).map(function (c) { return c.trim(); });
                if (cells.length) tableRows.push(cells);
            } else if (isTableSep) {
                flushPara();
                if (inList) { out.push('</ul>'); inList = false; }
            } else if (h3) {
                flushPara();
                flushTable();
                if (inList) { out.push('</ul>'); inList = false; }
                out.push('<h3>' + h3[1] + '</h3>');
            } else if (h4) {
                flushPara();
                flushTable();
                if (inList) { out.push('</ul>'); inList = false; }
                out.push('<h4>' + h4[1] + '</h4>');
            } else if (isHr) {
                flushPara();
                flushTable();
                if (inList) { out.push('</ul>'); inList = false; }
                out.push('<hr>');
            } else if (line.trim()) {
                flushTable();
                if (inList) { out.push('</ul>'); inList = false; }
                paraLines.push(line);
            } else {
                flushPara();
                flushTable();
                if (inList) { out.push('</ul>'); inList = false; }
            }
        }
        flushPara();
        flushTable();
        if (inList) out.push('</ul>');
        return out.join('');
    }

    function attachSignoffNavLinks(container) {
        if (!container) return;
        var tables = container.querySelectorAll('.tessa-table-wrap');
        tables.forEach(function (tableWrap) {
            var table = tableWrap.querySelector('table');
            if (!table) return;
            var headerRow = table.querySelector('thead tr');
            var bodyRows = table.querySelectorAll('tbody tr');
            if (!headerRow || !bodyRows.length) return;
            var headerCells = headerRow.querySelectorAll('th');
            var itemIdx = -1;
            var statusIdx = -1;
            var detailsIdx = -1;
            for (var i = 0; i < headerCells.length; i++) {
                var h = (headerCells[i].textContent || '').toLowerCase();
                if ((h.indexOf('item') >= 0 || h.indexOf('section') >= 0) && itemIdx < 0) itemIdx = i;
                if (h.indexOf('status') >= 0) statusIdx = i;
                if (h.indexOf('details') >= 0) detailsIdx = i;
            }
            if (itemIdx < 0 || statusIdx < 0) return;
            bodyRows.forEach(function (row) {
                var cells = row.querySelectorAll('td');
                if (cells.length <= Math.max(itemIdx, statusIdx)) return;
                var statusText = (cells[statusIdx].textContent || '').toLowerCase();
                var itemCellEmpty = !(cells[itemIdx].textContent || '').trim();
                var isGroupHeader = itemCellEmpty && !statusText.trim();
                if (!isGroupHeader && statusText.indexOf('pending') < 0 && statusText.indexOf('overdue') < 0 && statusText.indexOf('missing') < 0 && statusText.indexOf('at 0') < 0) return;
                var itemText = (cells[itemIdx].textContent || '').trim();
                var detailsText = (detailsIdx >= 0 && cells[detailsIdx]) ? (cells[detailsIdx].textContent || '').trim() : '';
                var type = null;
                var meetingTitlePart = null;
                var meetingKeyFromDetails = null;
                if (/daily\s*report/i.test(itemText)) {
                    type = 'daily_report';
                } else if (/kpi/i.test(itemText)) {
                    type = 'kpi';
                } else if (/meeting|@/i.test(itemText)) {
                    type = 'meeting';
                    var combined = itemText + ' ' + detailsText;
                    var mt = combined.match(/([^@]+?)\s*@\s*\d/);
                    meetingTitlePart = mt ? mt[1].replace(/^meeting[s]?\s*:?\s*/i, '').trim() : (itemText.replace(/^meeting[s]?\s*:?\s*/i, '').trim() || null);
                } else if (/\s*-\s*agenda\s*$/i.test(itemText)) {
                    type = 'agenda';
                    meetingTitlePart = itemText.replace(/\s*-\s*agenda\s*$/i, '').trim();
                } else if (/\s*-\s*notes?\s*(\(mom\))?\s*$/i.test(itemText)) {
                    type = 'notes';
                    meetingTitlePart = itemText.replace(/\s*-\s*notes?\s*(\(mom\))?\s*$/i, '').trim();
                }
                if (!type && detailsText) {
                    var mk2 = detailsText.match(/\(([a-z][a-z0-9_]*-[a-z0-9_\-]+)\)/i);
                    if (mk2 || /meeting/i.test(detailsText)) {
                        type = 'meeting';
                        if (mk2) meetingKeyFromDetails = mk2[1];
                    }
                }
                if (!type && cells[0]) {
                    var firstCellText = (cells[0].textContent || '').trim();
                    if (/daily\s*report/i.test(firstCellText)) type = 'daily_report';
                    else if (/kpi/i.test(firstCellText)) type = 'kpi';
                }
                if (!type) return;
                var itemCell = isGroupHeader && cells[0] ? cells[0] : cells[itemIdx];
                itemCell.classList.add('tessa-signoff-link');
                itemCell.style.cursor = 'pointer';
                itemCell.title = 'Click to complete';
                itemCell.onclick = function () {
                    if (type === 'daily_report') {
                        if (MeetingModule && MeetingModule.switchView) MeetingModule.switchView('daily');
                        return;
                    }
                    if (type === 'kpi') {
                        if (MeetingModule && MeetingModule.switchView) MeetingModule.switchView('kpi');
                        return;
                    }
                    if (type === 'meeting') {
                        var meetingId = null;
                        if (meetingTitlePart && MeetingModule && MeetingModule.meetings) {
                            var todayName = new Date().toLocaleDateString('en-US', { timeZone: 'Asia/Kolkata', weekday: 'long' });
                            var candidates = MeetingModule.meetings.filter(function (m) {
                                return (m.title || '').indexOf(meetingTitlePart) >= 0 || meetingTitlePart.indexOf(m.title || '') >= 0;
                            });
                            var m = candidates.find(function (x) { return x.day === todayName; }) || candidates[0];
                            if (m) meetingId = m.id;
                        }
                        if (meetingId && MeetingModule && MeetingModule.openMeetingById) {
                            MeetingModule.openMeetingById(meetingId);
                        }
                        if (MeetingModule && MeetingModule.switchView) MeetingModule.switchView('meetings');
                        return;
                    }
                    var meetingId = null;
                    if (meetingTitlePart && MeetingModule && MeetingModule.meetings) {
                        var todayName = new Date().toLocaleDateString('en-US', { timeZone: 'Asia/Kolkata', weekday: 'long' });
                        var candidates = MeetingModule.meetings.filter(function (m) {
                            return (m.title || '').indexOf(meetingTitlePart) >= 0 || meetingTitlePart.indexOf(m.title || '') >= 0;
                        });
                        var m = candidates.find(function (x) { return x.day === todayName; }) || candidates[0];
                        if (m) meetingId = m.id;
                    }
                    if (meetingId && MeetingModule) {
                        var tab = type === 'agenda' ? 'agenda' : (type === 'notes' ? 'notes' : 'actions');
                        if (MeetingModule.openMeetingById) MeetingModule.openMeetingById(meetingId);
                        MeetingModule.switchView('meetings');
                        setTimeout(function () {
                            var tabEl = document.querySelector('.mtg-tab[data-tab="' + tab + '"]');
                            if (tabEl) tabEl.click();
                        }, 150);
                    } else if (MeetingModule && MeetingModule.switchView) {
                        MeetingModule.switchView('meetings');
                    }
                };
            });
        });
    }

    function typeTessaReplyIntoElement(contentCell, reply, scrollContainer, onComplete) {
        if (!contentCell || !reply) {
            if (onComplete) onComplete();
            return;
        }
        var len = reply.length;
        var durationMs = Math.min(2200, Math.max(600, len * 4));
        var startTime = null;
        var lastPos = 0;
        function frame(ts) {
            if (!startTime) startTime = ts;
            var elapsed = ts - startTime;
            var t = Math.min(1, elapsed / durationMs);
            var eased = t < 0.5 ? 2 * t * t : 1 - Math.pow(-2 * t + 2, 2) / 2;
            var pos = Math.round(eased * len);
            if (pos !== lastPos) {
                lastPos = pos;
                contentCell.innerHTML = formatTessaReply(reply.slice(0, pos));
                if (scrollContainer) scrollContainer.scrollTop = scrollContainer.scrollHeight;
            }
            if (pos < len) {
                requestAnimationFrame(frame);
            } else {
                attachSignoffNavLinks(scrollContainer);
                if (onComplete) onComplete();
            }
        }
        requestAnimationFrame(frame);
    }

    function tessaGetChats() {
        try {
            var raw = localStorage.getItem(TESSA_STORAGE_KEY);
            var data = raw ? JSON.parse(raw) : null;
            if (data && Array.isArray(data.chats)) return data;
        } catch (e) { /* ignore */ }
        return { chats: [], activeId: null };
    }

    function tessaSaveChats(data) {
        try {
            localStorage.setItem(TESSA_STORAGE_KEY, JSON.stringify(data));
        } catch (e) { /* ignore */ }
    }

    function tessaGenerateId() {
        return 'tessa_' + Date.now() + '_' + Math.random().toString(36).slice(2, 9);
    }

    function tessaIsDbId(id) {
        return /^\d+$/.test(String(id));
    }

    function tessaEnsureSingleChat() {
        var data = tessaGetChats();
        if (!data.chats || !data.chats.length) {
            var chat = tessaCreateNewChat();
            data.chats = [chat];
            data.activeId = chat.id;
            tessaSaveChats(data);
        }
        if (!data.activeId) {
            data.activeId = data.chats[0].id;
            tessaSaveChats(data);
        }
        return data;
    }

    function tessaFetchMessages(chatId, cb) {
        fetch('/api/tessa/chats/' + chatId + '/messages', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.ok ? r.json() : Promise.reject(); })
            .then(function (body) {
                var msgs = (body.messages || []).map(function (m) {
                    var txt = m.content || m.text || '';
                    return { role: m.role, text: txt, content: txt, ts: m.created_at ? new Date(m.created_at).getTime() : Date.now() };
                });
                if (msgs.length && msgs[0].role === 'user') {
                    var u = config.userName || 'Team';
                    var greeting = 'How can I help, ' + u + '?';
                    msgs.unshift({ role: 'assistant', text: greeting, html: 'How can I help, ' + escapeHtml(u) + '?', ts: Date.now() });
                }
                if (cb) cb(msgs);
            })
            .catch(function () { if (cb) cb(null); });
    }

    function tessaCreateNewChat() {
        var userName = config.userName || 'Team';
        var greeting = 'How can I help, ' + escapeHtml(userName) + '?';
        var chat = {
            id: tessaGenerateId(),
            title: 'New chat',
            is_pinned: false,
            is_archived: false,
            createdAt: Date.now(),
            messages: [{ role: 'assistant', text: 'How can I help, ' + userName + '?', html: greeting, ts: Date.now() }]
        };
        return chat;
    }


    function tessaRenderMessages(chatId) {
        var container = document.getElementById('tessaMessages');
        if (!container) return;
        container.innerHTML = '';
        var data = tessaGetChats();
        var chat = (data.chats || []).find(function (c) { return c.id === chatId; });
        if (!chat || !chat.messages || !chat.messages.length) return;
        var userName = config.userName || 'User';
        var userInitial = (userName.charAt(0) || 'U').toUpperCase();

        chat.messages.forEach(function (msg, idx) {
            var isAssistant = msg.role === 'assistant';
            var msgEl = document.createElement('div');
            msgEl.className = 'tessa-msg';
            var avatar = document.createElement('div');
            avatar.className = 'tessa-msg-avatar ' + (isAssistant ? 'tessa-avatar' : 'user-avatar');
            avatar.textContent = isAssistant ? 'T' : userInitial;
            var content = document.createElement('div');
            content.className = 'tessa-msg-content';
            msgEl.appendChild(avatar);
            msgEl.appendChild(content);
            container.appendChild(msgEl);

            if (isAssistant && msg.html) {
                if (msg.lines && msg.lines.length) {
                    var u = config.userName || 'Team';
                    content.innerHTML = 'How can I help, ' + escapeHtml(u) + '?';
                } else {
                    content.innerHTML = msg.html;
                }
            } else if (isAssistant && msg.text) {
                content.innerHTML = formatTessaReply(msg.text);
            } else if (!isAssistant && msg.text) {
                content.textContent = msg.text;
            }
        });
        attachSignoffNavLinks(container);

        var hasUserMessages = (chat.messages || []).some(function (m) { return m.role === 'user'; });
        if (!hasUserMessages && (config.hasSignoff || (config.features || []).indexOf('signoff') >= 0)) {
            var quickWrap = document.createElement('div');
            quickWrap.className = 'tessa-quick-actions';
            var signInChip = document.createElement('button');
            signInChip.type = 'button';
            signInChip.className = 'tessa-quick-action-chip';
            signInChip.textContent = 'Sign In';
            signInChip.addEventListener('click', function () {
                quickWrap.remove();
                var input = document.getElementById('tessaInput');
                if (!input) return;
                input.value = 'Sign in for today';
                tessaAutoResizeTextarea(input);
                if (document.getElementById('tessaSendBtn')) document.getElementById('tessaSendBtn').disabled = false;
                setTimeout(function () { handleTessaSend(); }, 50);
            });
            quickWrap.appendChild(signInChip);
            var signOffChip = document.createElement('button');
            signOffChip.type = 'button';
            signOffChip.className = 'tessa-quick-action-chip';
            signOffChip.textContent = 'Sign Off';
            signOffChip.addEventListener('click', function () {
                quickWrap.remove();
                var input = document.getElementById('tessaInput');
                if (!input) return;
                input.value = 'Sign off for today';
                tessaAutoResizeTextarea(input);
                if (document.getElementById('tessaSendBtn')) document.getElementById('tessaSendBtn').disabled = false;
                setTimeout(function () { handleTessaSend(); }, 50);
            });
            quickWrap.appendChild(signOffChip);
            var pendingChip = document.createElement('button');
            pendingChip.type = 'button';
            pendingChip.className = 'tessa-quick-action-chip';
            pendingChip.textContent = 'Pending Work';
            pendingChip.addEventListener('click', function () {
                quickWrap.remove();
                var input = document.getElementById('tessaInput');
                if (!input) return;
                input.value = 'Show my pending work';
                tessaAutoResizeTextarea(input);
                if (document.getElementById('tessaSendBtn')) document.getElementById('tessaSendBtn').disabled = false;
                setTimeout(function () { handleTessaSend(); }, 50);
            });
            quickWrap.appendChild(pendingChip);
            container.appendChild(quickWrap);
        }

        container.scrollTop = container.scrollHeight;
    }

    function tessaAutoSendSignoff() {
        tessaEnsureSingleChat();
        var input = document.getElementById('tessaInput');
        if (!input) return;
        input.value = 'Sign off for today';
        tessaAutoResizeTextarea(input);
        setTimeout(function () { handleTessaSend(); }, 50);
    }

    function tessaDetectSteps(msg) {
        var m = (msg || '').toLowerCase();
        if (/leave|sick|emergency|menstrual|period|wfh|work from home|day off|not coming/.test(m)) {
            return ['Processing your leave request...', 'Almost done...'];
        }
        if (/cancel.*leave|withdraw.*leave/.test(m)) {
            return ['Finding your leave request...', 'Cancelling...'];
        }
        if (/leave.*(balance|remaining|how many)|(balance|remaining|how many).*leave/.test(m)) {
            return ['Checking your leave balance...'];
        }
        if (/sign.?off|sign me off|end.?of.?day/.test(m)) {
            return ['Checking your pending items...', 'Preparing sign-off...'];
        }
        if (/sign.?in|good morning|morning briefing|start.*day/.test(m)) {
            return ['Preparing your morning briefing...'];
        }
        if (/pending|what.*do|to.?do/.test(m)) {
            return ['Checking your pending work...'];
        }
        if (/meeting/.test(m)) {
            return ['Reviewing your meetings...'];
        }
        if (/daily.?report|report/.test(m)) {
            return ['Looking at daily reports...'];
        }
        if (/kpi|target|metric/.test(m)) {
            return ['Analyzing KPI status...'];
        }
        if (/slack|send|dm|message/.test(m)) {
            return ['Sending message...'];
        }
        return ['Thinking...'];
    }

    function tessaStartSearchStatus(contentEl, userMessage) {
        if (!contentEl) return null;
        var steps = tessaDetectSteps(userMessage);
        var idx = 0;
        contentEl.innerHTML = '<span class="tessa-search-step">' + steps[0] + '</span>';
        contentEl.classList.add('tessa-searching');
        if (steps.length <= 1) return null;
        var interval = setInterval(function () {
            idx++;
            if (idx >= steps.length) { clearInterval(interval); return; }
            var span = contentEl.querySelector('.tessa-search-step');
            if (!span) { clearInterval(interval); return; }
            span.style.opacity = '0';
            setTimeout(function () {
                span.textContent = steps[idx];
                span.style.opacity = '1';
            }, 300);
        }, 2000);
        return interval;
    }

    function handleTessaSend() {
        var input = document.getElementById('tessaInput');
        var sendBtn = document.getElementById('tessaSendBtn');
        if (!input || !sendBtn) return;
        var text = (input.value || '').trim();
        if (!text) return;
        var data = tessaGetChats();
        var activeId = data.activeId;
        var chat = (data.chats || []).find(function (c) { return c.id === activeId; });
        if (!chat) return;
        input.value = '';
        tessaAutoResizeTextarea(input);
        sendBtn.disabled = true;

        chat.messages = chat.messages || [];
        chat.messages.push({ role: 'user', text: text, ts: Date.now() });
        if (chat.title === 'New chat' || !chat.title) {
            chat.title = text.length > 40 ? text.substring(0, 37) + '...' : text;
        }
        tessaSaveChats(data);

        var container = document.getElementById('tessaMessages');
        if (!container) return;
        var userName = config.userName || 'User';
        var userInitial = (userName.charAt(0) || 'U').toUpperCase();
        var userRow = document.createElement('div');
        userRow.className = 'tessa-msg';
        userRow.innerHTML = '<div class="tessa-msg-avatar user-avatar">' + escapeHtml(userInitial) + '</div><div class="tessa-msg-content">' + escapeHtml(text) + '</div>';
        container.appendChild(userRow);

        var typingRow = document.createElement('div');
        typingRow.className = 'tessa-msg';
        typingRow.id = 'tessaTypingRow';
        typingRow.innerHTML = '<div class="tessa-msg-avatar tessa-avatar tessa-loading">T</div><div class="tessa-msg-content"></div>';
        container.appendChild(typingRow);
        container.scrollTop = container.scrollHeight;
        var searchInterval = tessaStartSearchStatus(typingRow.querySelector('.tessa-msg-content'), text);

        var apiMessages = (chat.messages || []).map(function (m) {
            return { role: m.role === 'user' ? 'user' : 'assistant', content: m.text || m.content || '' };
        });
        var chatIdForApi = tessaIsDbId(chat.id) ? parseInt(chat.id, 10) : null;

        fetch('/api/tessa/chat', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({ messages: apiMessages, chat_id: chatIdForApi })
        }).then(function (res) {
            if (res.status === 401) {
                return { ok: false, body: { _sessionExpired: true } };
            }
            return res.json().catch(function () { return {}; }).then(function (body) { return { ok: res.ok, status: res.status, body: body }; });
        }).then(function (result) {
            clearInterval(searchInterval);
            var typingEl = document.getElementById('tessaTypingRow');
            if (result.body._sessionExpired) {
                var reply = 'Your session has expired. Redirecting to login...';
                setTimeout(function () { window.location.href = '/login'; }, 2000);
            } else {
                var reply = result.ok && result.body.reply ? result.body.reply : (result.body.error || result.body.message || 'Sorry, I encountered an error. Please try again.');
            }
            var formatted = formatTessaReply(reply);
            if (result.body.chat_id && !tessaIsDbId(chat.id)) {
                var oldId = chat.id;
                chat.id = String(result.body.chat_id);
                if (data.activeId === oldId) {
                    data.activeId = chat.id;
                }
                tessaSaveChats(data);
            }
            sendBtn.disabled = false;
            if (typingEl) {
                var avatarEl = typingEl.querySelector('.tessa-msg-avatar');
                if (avatarEl) avatarEl.classList.remove('tessa-loading');
                var contentCell = typingEl.querySelector('.tessa-msg-content');
                if (contentCell) {
                    contentCell.classList.remove('tessa-searching');
                    typeTessaReplyIntoElement(contentCell, reply, container, function () {
                        typingEl.removeAttribute('id');
                        chat.messages.push({ role: 'assistant', text: reply, html: formatted, ts: Date.now() });
                        tessaSaveChats(data);
                        container.scrollTop = container.scrollHeight;
                    });
                } else {
                    typingEl.removeAttribute('id');
                    chat.messages.push({ role: 'assistant', text: reply, html: formatted, ts: Date.now() });
                    tessaSaveChats(data);
                    container.scrollTop = container.scrollHeight;
                }
            } else {
                chat.messages.push({ role: 'assistant', text: reply, html: formatted, ts: Date.now() });
                tessaSaveChats(data);
            }
        }).catch(function (err) {
            clearInterval(searchInterval);
            var typingEl = document.getElementById('tessaTypingRow');
            var fallback = err && err.message && err.message.indexOf('Unauthenticated') !== -1
                ? 'Your session has expired. Please refresh the page to log in again.'
                : 'Sorry, I encountered an error. Please try again.';
            var fallbackFormatted = formatTessaReply(fallback);
            sendBtn.disabled = false;
            if (typingEl) {
                var avatarEl = typingEl.querySelector('.tessa-msg-avatar');
                if (avatarEl) avatarEl.classList.remove('tessa-loading');
                var contentCell = typingEl.querySelector('.tessa-msg-content');
                if (contentCell) {
                    contentCell.classList.remove('tessa-searching');
                    typeTessaReplyIntoElement(contentCell, fallback, container, function () {
                        typingEl.removeAttribute('id');
                        chat.messages.push({ role: 'assistant', text: fallback, html: fallbackFormatted, ts: Date.now() });
                        tessaSaveChats(data);
                        container.scrollTop = container.scrollHeight;
                    });
                } else {
                    typingEl.removeAttribute('id');
                    chat.messages.push({ role: 'assistant', text: fallback, html: fallbackFormatted, ts: Date.now() });
                    tessaSaveChats(data);
                    container.scrollTop = container.scrollHeight;
                }
            } else {
                chat.messages.push({ role: 'assistant', text: fallback, html: fallbackFormatted, ts: Date.now() });
                tessaSaveChats(data);
            }
        });
    }

    function tessaAutoResizeTextarea(el) {
        if (!el) return;
        el.style.height = 'auto';
        el.style.height = Math.min(el.scrollHeight, 200) + 'px';
    }

    function tessaClosePlusMenu() {
        var menu = document.getElementById('tessaPlusMenu');
        if (menu) menu.classList.add('hidden');
    }

    function renderTessa() {
        var tessaView = document.getElementById('tessaView');
        if (!tessaView) return;
        var alreadyBound = tessaView.hasAttribute('data-tessa-bound');

        var data = tessaEnsureSingleChat();
        var activeChat = (data.chats || []).find(function (c) { return c.id === data.activeId; });
        if (activeChat && tessaIsDbId(data.activeId) && (!activeChat.messages || !activeChat.messages.length)) {
            tessaFetchMessages(data.activeId, function (msgs) {
                if (msgs && msgs.length) {
                    activeChat.messages = msgs;
                    tessaSaveChats(data);
                }
                tessaRenderMessages(data.activeId);
            });
        } else {
            tessaRenderMessages(data.activeId);
        }

        if (!alreadyBound) {
            tessaView.setAttribute('data-tessa-bound', 'true');

            var plusBtn = document.getElementById('tessaPlusBtn');
            var plusMenu = document.getElementById('tessaPlusMenu');
            if (plusBtn && plusMenu) {
                plusBtn.addEventListener('click', function (e) {
                    e.stopPropagation();
                    plusMenu.classList.toggle('hidden');
                });
                plusMenu.querySelectorAll('.tessa-plus-menu-item').forEach(function (item) {
                    item.addEventListener('click', function () {
                        if (item.getAttribute('data-action') === 'assign-task') {
                            tessaOpenTaskModal();
                        }
                        tessaClosePlusMenu();
                    });
                });
            }
            document.addEventListener('click', function () { tessaClosePlusMenu(); });

            var trackerBtn = document.getElementById('tessaTaskTrackerBtn');
            if (trackerBtn) trackerBtn.addEventListener('click', function () { MeetingModule.switchView('tasks'); });

            var clearBtn = document.getElementById('tessaClearChatBtn');
            if (clearBtn) clearBtn.addEventListener('click', function () {
                var newChat = tessaCreateNewChat();
                var data = tessaGetChats();
                data.chats.push(newChat);
                data.activeId = newChat.id;
                tessaSaveChats(data);
                tessaRenderMessages(newChat.id);
                var inp = document.getElementById('tessaInput');
                if (inp) { inp.value = ''; tessaAutoResizeTextarea(inp); }
                var sb = document.getElementById('tessaSendBtn');
                if (sb) sb.disabled = true;
            });

            var input = document.getElementById('tessaInput');
            var sendBtn = document.getElementById('tessaSendBtn');
            if (input) {
                input.addEventListener('input', function () {
                    tessaAutoResizeTextarea(input);
                    if (sendBtn) sendBtn.disabled = !(input.value || '').trim();
                });
                input.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter' && !e.shiftKey) {
                        e.preventDefault();
                        handleTessaSend();
                    }
                });
                if (sendBtn) sendBtn.disabled = !(input.value || '').trim();
            }
            if (sendBtn) sendBtn.addEventListener('click', handleTessaSend);

            document.querySelectorAll('.tessa-persist-chip').forEach(function (chip) {
                chip.addEventListener('click', function () {
                    var action = chip.getAttribute('data-tessa-action');
                    if (action === 'clear') {
                        // Trigger same logic as the top-right new-chat button
                        var clearBtn = document.getElementById('tessaClearChatBtn');
                        if (clearBtn) clearBtn.click();
                        return;
                    }
                    var input = document.getElementById('tessaInput');
                    if (!input) return;
                    if (action === 'pending') input.value = 'Show my pending work';
                    else if (action === 'signin') input.value = 'Sign in for today';
                    else if (action === 'signoff') input.value = 'Sign off for today';
                    tessaAutoResizeTextarea(input);
                    if (sendBtn) sendBtn.disabled = false;
                    setTimeout(function () { handleTessaSend(); }, 50);
                });
            });
        }
    }

    // Reference to TasksModule for task modal
    function tessaOpenTaskModal() { return TasksModule.tessaOpenTaskModal(); }

    window.TessaChatModule = {
        renderTessa: renderTessa,
        formatTessaReply: formatTessaReply,
        typeTessaReplyIntoElement: typeTessaReplyIntoElement,
        attachSignoffNavLinks: attachSignoffNavLinks,
        tessaAutoSendSignoff: tessaAutoSendSignoff,
        tessaClosePlusMenu: tessaClosePlusMenu
    };
})();
