/**
 * Timesheet AI Assistant — chat UI module
 *
 * Exposes window.TimesheetAssistant.mount(containerEl) — renders a chat panel
 * into the given container. Used by:
 *   - timesheets.js (Chat tab inside #timesheetsView for employees)
 *   - admin dashboard (#timesheetAssistantView for admin on-behalf-of logging)
 */
(function () {
  'use strict';

  const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';

  async function api(url, opts = {}) {
    const res = await fetch('/api' + url, {
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': csrf(),
        Accept: 'application/json',
        ...(opts.headers || {}),
      },
      credentials: 'same-origin',
      method: opts.method || 'GET',
      body: opts.body ? JSON.stringify(opts.body) : undefined,
    });
    const json = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(json.error || 'Request failed');
    return json;
  }

  function el(tag, attrs, ...kids) {
    const e = document.createElement(tag);
    if (attrs) {
      for (const k in attrs) {
        if (k === 'style') Object.assign(e.style, attrs[k]);
        else if (k === 'on') Object.assign(e, attrs[k]);
        else if (k.startsWith('on') && typeof attrs[k] === 'function') e.addEventListener(k.slice(2), attrs[k]);
        else if (attrs[k] !== undefined && attrs[k] !== null) e.setAttribute(k, attrs[k]);
      }
    }
    for (const k of kids.flat(Infinity)) {
      if (k == null || k === false) continue;
      e.appendChild(typeof k === 'string' ? document.createTextNode(k) : k);
    }
    return e;
  }

  const styles = `
    .ts-asst-shell { display:flex; flex-direction:column; height:100%; min-height:520px; max-height:calc(100vh - 220px); padding:4px; gap:12px; }
    .ts-asst-head { display:flex; align-items:center; gap:10px; padding:12px 14px; background:#0f0f11; border:1px solid #27272a; border-radius:8px; }
    .ts-asst-head h3 { color:#fafafa; font-size:14px; margin:0; font-weight:600; }
    .ts-asst-head p { color:#a1a1aa; font-size:12px; margin:2px 0 0; }
    .ts-asst-msgs { flex:1; overflow-y:auto; padding:14px; background:#0f0f11; border:1px solid #27272a; border-radius:8px; display:flex; flex-direction:column; gap:10px; }
    .ts-asst-msg { display:flex; gap:10px; max-width:88%; }
    .ts-asst-msg.user { align-self:flex-end; flex-direction:row-reverse; }
    .ts-asst-bubble { padding:10px 14px; border-radius:10px; font-size:13px; line-height:1.5; white-space:pre-wrap; word-break:break-word; }
    .ts-asst-msg.user .ts-asst-bubble { background:#3b82f6; color:#fff; }
    .ts-asst-msg.assistant .ts-asst-bubble { background:#27272a; color:#e4e4e7; }
    .ts-asst-confirm { background:#0f0f11; border:1px solid #3b82f6; border-radius:10px; padding:14px; color:#e4e4e7; font-size:13px; }
    .ts-asst-confirm h4 { margin:0 0 10px; color:#93c5fd; font-size:13px; font-weight:600; }
    .ts-asst-confirm dl { display:grid; grid-template-columns:auto 1fr; gap:6px 14px; margin:0 0 12px; }
    .ts-asst-confirm dt { color:#a1a1aa; font-size:12px; }
    .ts-asst-confirm dd { margin:0; color:#fafafa; font-size:13px; }
    .ts-asst-actions { display:flex; gap:8px; }
    .ts-asst-btn { padding:7px 14px; border-radius:8px; border:1px solid transparent; cursor:pointer; font-size:13px; font-weight:500; font-family:inherit; transition:background 0.15s, border-color 0.15s; }
    .ts-asst-btn-primary { background:#3b82f6; color:#fff; border-color:#3b82f6; }
    .ts-asst-btn-primary:hover { background:#2563eb; border-color:#2563eb; }
    .ts-asst-btn-ghost { background:#18181b; color:#a1a1aa; border-color:#27272a; }
    .ts-asst-btn-ghost:hover { background:#27272a; border-color:#3f3f46; color:#fafafa; }
    .ts-asst-input-row { display:flex; gap:8px; align-items:flex-end; }
    .ts-asst-input { flex:1; min-height:42px; max-height:160px; padding:10px 12px; background:#0f0f11; border:1px solid #27272a; border-radius:8px; color:#fafafa; font-size:13px; font-family:inherit; resize:none; transition:border-color 0.15s; }
    .ts-asst-input:focus { outline:none; border-color:#3b82f6; }
    .ts-asst-input::placeholder { color:#52525b; }
    .ts-asst-send { padding:10px 18px; background:#3b82f6; color:#fff; border:1px solid #3b82f6; border-radius:8px; cursor:pointer; font-weight:500; font-size:13px; font-family:inherit; transition:background 0.15s; }
    .ts-asst-send:hover { background:#2563eb; border-color:#2563eb; }
    .ts-asst-send:disabled { background:#3f3f46; border-color:#3f3f46; cursor:wait; }
    .ts-asst-status { font-size:12px; color:#71717a; padding:4px 8px; }
    .ts-asst-status.error { color:#f87171; }
    .ts-asst-status.success { color:#4ade80; }
    .ts-asst-typing { color:#a1a1aa; font-style:italic; padding:6px 14px; font-size:12px; }
  `;

  function ensureStyles() {
    if (document.getElementById('ts-asst-styles')) return;
    const s = document.createElement('style');
    s.id = 'ts-asst-styles';
    s.textContent = styles;
    document.head.appendChild(s);
  }

  function mount(container) {
    if (!container) return;
    ensureStyles();

    let history = [];
    let pendingPayload = null;

    container.innerHTML = '';
    const shell = el('div', { class: 'ts-asst-shell' });
    const head = el('div', { class: 'ts-asst-head' },
      el('div', null,
        el('h3', null, 'Tessa Timesheet Assistant'),
        el('p', null, 'Chat with Tessa to log a timesheet quickly.')
      )
    );
    const msgs = el('div', { class: 'ts-asst-msgs' });
    const status = el('div', { class: 'ts-asst-status' });
    const inputRow = el('div', { class: 'ts-asst-input-row' });
    const input = el('textarea', { class: 'ts-asst-input', placeholder: 'e.g. "I worked 3 hours overtime last night, polishing the campaign brief"', rows: 1 });
    const sendBtn = el('button', { class: 'ts-asst-send' }, 'Send');

    inputRow.append(input, sendBtn);
    shell.append(head, msgs, status, inputRow);
    container.append(shell);

    function addMessage(role, text) {
      const wrap = el('div', { class: 'ts-asst-msg ' + role },
        el('div', { class: 'ts-asst-bubble' }, text)
      );
      msgs.append(wrap);
      msgs.scrollTop = msgs.scrollHeight;
    }

    function addConfirmCard(payload) {
      const card = el('div', { class: 'ts-asst-confirm' });
      card.append(el('h4', null, 'Confirm timesheet entry'));
      const dl = el('dl');
      const labels = {
        target_user: 'For',
        work_date: 'Date',
        start_time: 'Start',
        end_time: 'End',
        type: 'Type',
        description: 'What was done',
      };
      const order = ['target_user', 'work_date', 'start_time', 'end_time', 'type', 'description'];
      for (const k of order) {
        if (k === 'target_user' && !payload[k]) continue;
        if (payload[k] == null || payload[k] === '') continue;
        dl.append(el('dt', null, labels[k] || k));
        dl.append(el('dd', null, String(payload[k])));
      }
      card.append(dl);

      const actions = el('div', { class: 'ts-asst-actions' });
      const submitBtn = el('button', { class: 'ts-asst-btn ts-asst-btn-primary' }, 'Submit');
      const cancelBtn = el('button', { class: 'ts-asst-btn ts-asst-btn-ghost' }, 'Cancel');
      submitBtn.addEventListener('click', () => submitPayload(payload, card, submitBtn, cancelBtn));
      cancelBtn.addEventListener('click', () => {
        card.remove();
        pendingPayload = null;
        addMessage('assistant', 'Cancelled. What would you like to change?');
      });
      actions.append(submitBtn, cancelBtn);
      card.append(actions);
      msgs.append(card);
      msgs.scrollTop = msgs.scrollHeight;
    }

    async function submitPayload(payload, card, submitBtn, cancelBtn) {
      submitBtn.disabled = true;
      cancelBtn.disabled = true;
      submitBtn.textContent = 'Saving…';
      try {
        const result = await api('/timesheet-assistant/submit', { method: 'POST', body: { payload } });
        card.remove();
        pendingPayload = null;
        status.className = 'ts-asst-status success';
        status.textContent = result.message || 'Saved.';
        addMessage('assistant', `Done — ${result.message || 'timesheet logged'}. Anything else?`);
        history = []; // reset for next entry
        // Notify host page that a timesheet was saved (e.g. so the Manual tab can refresh).
        document.dispatchEvent(new CustomEvent('timesheet:saved', { detail: result.timesheet }));
      } catch (err) {
        submitBtn.disabled = false;
        cancelBtn.disabled = false;
        submitBtn.textContent = 'Submit';
        status.className = 'ts-asst-status error';
        status.textContent = err.message || 'Failed to save.';
      }
    }

    async function send() {
      const text = input.value.trim();
      if (!text) return;
      input.value = '';
      addMessage('user', text);
      history.push({ role: 'user', content: text });
      sendBtn.disabled = true;

      const typing = el('div', { class: 'ts-asst-typing' }, 'Tessa is thinking…');
      msgs.append(typing);

      try {
        const result = await api('/timesheet-assistant/message', { method: 'POST', body: { message: text, history } });
        typing.remove();
        if (result.reply) {
          addMessage('assistant', result.reply);
          history.push({ role: 'assistant', content: result.reply });
        }
        if (result.payload) {
          pendingPayload = result.payload;
          addConfirmCard(result.payload);
        }
        status.className = 'ts-asst-status';
        status.textContent = '';
      } catch (err) {
        typing.remove();
        status.className = 'ts-asst-status error';
        status.textContent = err.message || 'Failed to reach Tessa.';
      } finally {
        sendBtn.disabled = false;
        input.focus();
      }
    }

    sendBtn.addEventListener('click', send);
    input.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        send();
      }
    });

    // Auto-resize textarea
    input.addEventListener('input', () => {
      input.style.height = 'auto';
      input.style.height = Math.min(input.scrollHeight, 160) + 'px';
    });

    addMessage('assistant', 'Hi! Tell me what you worked on and I\'ll help you log it. For example: "I worked 3 hours overtime last night fixing the login bug".');
    setTimeout(() => input.focus(), 50);
  }

  window.TimesheetAssistant = { mount };
})();
