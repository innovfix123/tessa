/* Grammar Fix — adds a small inline icon to any textarea or [contenteditable]
 * marked with [data-grammar-fix]. On click, sends the current value to
 * /api/tessa/grammar and replaces the value with the corrected version.
 *
 * Usage:
 *   <textarea data-grammar-fix></textarea>
 *
 * The script auto-attaches to all matching elements on DOMContentLoaded and
 * watches the DOM for new ones (so dynamically rendered modals work too).
 */
(function () {
    'use strict';

    var ATTR = 'data-grammar-fix';
    var ATTACHED_FLAG = '_grammarFixAttached';

    function getValue(el) {
        if (el.tagName === 'TEXTAREA' || el.tagName === 'INPUT') return el.value || '';
        return el.innerText || el.textContent || '';
    }

    function setValue(el, val) {
        if (el.tagName === 'TEXTAREA' || el.tagName === 'INPUT') {
            el.value = val;
            el.dispatchEvent(new Event('input', { bubbles: true }));
            el.dispatchEvent(new Event('change', { bubbles: true }));
        } else {
            el.innerText = val;
            el.dispatchEvent(new Event('input', { bubbles: true }));
        }
    }

    function showToast(msg, isError) {
        var t = document.createElement('div');
        t.className = 'gf-toast' + (isError ? ' gf-toast-error' : '');
        t.textContent = msg;
        document.body.appendChild(t);
        setTimeout(function () { t.classList.add('gf-toast-show'); }, 10);
        setTimeout(function () {
            t.classList.remove('gf-toast-show');
            setTimeout(function () { t.remove(); }, 300);
        }, 2400);
    }

    function buildButton() {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'gf-btn';
        btn.title = 'Fix grammar (AI)';
        btn.setAttribute('aria-label', 'Fix grammar');
        btn.innerHTML =
            '<svg class="gf-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
                '<path d="M4 7V4h16v3"/><path d="M9 20h6"/><path d="M12 4v16"/>' +
            '</svg>' +
            '<span class="gf-spin" hidden>' +
                '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M21 12a9 9 0 1 1-6.2-8.55" /></svg>' +
            '</span>';
        return btn;
    }

    function setBusy(btn, busy) {
        var icon = btn.querySelector('.gf-icon');
        var spin = btn.querySelector('.gf-spin');
        if (busy) {
            if (icon) icon.style.display = 'none';
            if (spin) spin.removeAttribute('hidden');
            btn.classList.add('gf-busy');
            btn.disabled = true;
        } else {
            if (icon) icon.style.display = '';
            if (spin) spin.setAttribute('hidden', '');
            btn.classList.remove('gf-busy');
            btn.disabled = false;
        }
    }

    async function runFix(el, btn) {
        var text = getValue(el).trim();
        if (!text) {
            showToast('Nothing to fix yet.', false);
            return;
        }
        if (text.length > 5000) {
            showToast('Text is too long (max 5000 characters).', true);
            return;
        }

        setBusy(btn, true);
        try {
            var res = await fetch('/api/tessa/grammar', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({ text: text })
            });
            var body = await res.json().catch(function () { return {}; });
            if (!res.ok || !body.ok) {
                showToast(body.error || 'Grammar fix failed. Try again.', true);
                return;
            }
            if (!body.changed) {
                showToast('Looks good — no changes needed.', false);
                return;
            }
            setValue(el, body.text);
            showToast('Grammar fixed.', false);
        } catch (e) {
            showToast('Network error. Try again.', true);
        } finally {
            setBusy(btn, false);
        }
    }

    function attach(el) {
        if (!el || el[ATTACHED_FLAG]) return;
        el[ATTACHED_FLAG] = true;

        // Wrap the field in a relative container so the button can position
        // absolutely without disturbing surrounding layout.
        var wrap = document.createElement('span');
        wrap.className = 'gf-wrap';
        var parent = el.parentNode;
        if (!parent) return;
        parent.insertBefore(wrap, el);
        wrap.appendChild(el);

        var btn = buildButton();
        wrap.appendChild(btn);
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            runFix(el, btn);
        });
    }

    function attachAll(root) {
        var scope = root || document;
        var nodes = scope.querySelectorAll('[' + ATTR + ']');
        for (var i = 0; i < nodes.length; i++) attach(nodes[i]);
    }

    // Auto-attach on initial load and watch for new matching elements.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { attachAll(); });
    } else {
        attachAll();
    }

    var observer = new MutationObserver(function (mutations) {
        for (var m = 0; m < mutations.length; m++) {
            var added = mutations[m].addedNodes;
            for (var n = 0; n < added.length; n++) {
                var node = added[n];
                if (node.nodeType !== 1) continue;
                if (node.matches && node.matches('[' + ATTR + ']')) attach(node);
                if (node.querySelectorAll) attachAll(node);
            }
        }
    });
    observer.observe(document.documentElement, { childList: true, subtree: true });

    window.GrammarFix = { attach: attach, attachAll: attachAll };
})();
