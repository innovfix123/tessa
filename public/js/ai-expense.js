(function () {
    'use strict';

    var FEATURE_LABELS = {
        logs_text: 'Logs · typed',
        logs_slack: 'Logs · Slack',
        logs: 'Logs'
    };

    function requestJson(url) {
        if (window.MeetingModule && MeetingModule.requestJson) {
            return MeetingModule.requestJson(url);
        }
        return fetch(url, {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (r) { return r.json(); });
    }

    function escapeHtml(v) {
        if (window.MeetingModule && MeetingModule.escapeHtml) return MeetingModule.escapeHtml(v);
        var d = document.createElement('div');
        d.textContent = v == null ? '' : String(v);
        return d.innerHTML;
    }

    function inr(n) {
        n = Number(n) || 0;
        return '₹' + n.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function usd(n) {
        return '$' + (Number(n) || 0).toFixed(4);
    }

    function featureLabel(key) {
        return FEATURE_LABELS[key] || key;
    }

    function dayLabel(iso) {
        var parts = (iso || '').split('-');
        if (parts.length !== 3) return iso;
        var d = new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]));
        return d.toLocaleDateString('en-US', { weekday: 'short', day: 'numeric', month: 'short' });
    }

    function render() {
        var root = document.getElementById('ai_expenseView');
        if (!root) return;
        root.innerHTML = '<div class="aix-wrap"><div class="aix-loading">Loading AI expense…</div></div>';

        requestJson('/api/ai-usage?days=30').then(function (data) {
            root.innerHTML = buildHtml(data);
        }).catch(function () {
            root.innerHTML = '<div class="aix-wrap"><div class="aix-loading">Failed to load AI expense.</div></div>';
        });
    }

    function buildHtml(data) {
        var total = data.total || { calls: 0, cost_usd: 0, cost_inr: 0 };
        var rate = data.usd_to_inr || 85;
        var days = data.days || [];
        var byFeature = data.by_feature || {};

        var html = '<div class="aix-wrap">';
        html += '<div class="aix-header">'
            + '<div><h1 class="aix-title">AI Expense</h1>'
            + '<p class="aix-sub">Tracked AI spend, last ' + (data.range_days || 30) + ' days · approx ₹' + rate + '/$1</p></div>'
            + '</div>';

        // Summary cards
        html += '<div class="aix-cards">'
            + '<div class="aix-card"><div class="aix-card-n">' + inr(total.cost_inr) + '</div><div class="aix-card-l">total spend</div></div>'
            + '<div class="aix-card"><div class="aix-card-n aix-muted">' + usd(total.cost_usd) + '</div><div class="aix-card-l">in USD</div></div>'
            + '<div class="aix-card"><div class="aix-card-n">' + (total.calls || 0) + '</div><div class="aix-card-l">AI calls</div></div>'
            + '</div>';

        // Per-feature breakdown
        var fKeys = Object.keys(byFeature);
        if (fKeys.length) {
            html += '<div class="aix-section-h">By feature</div><div class="aix-feature-row">';
            fKeys.forEach(function (k) {
                html += '<span class="aix-chip"><b>' + escapeHtml(featureLabel(k)) + '</b> ' + inr((byFeature[k] || 0) * rate) + '</span>';
            });
            html += '</div>';
        }

        // Day-wise table
        html += '<div class="aix-section-h">Day-wise</div>';
        if (!days.length) {
            html += '<div class="aix-empty">No AI usage recorded yet.</div>';
        } else {
            html += '<table class="aix-table"><thead><tr>'
                + '<th>Date</th><th class="aix-r">Calls</th><th class="aix-r">Cost (₹)</th><th class="aix-r">Cost ($)</th><th>Breakdown</th>'
                + '</tr></thead><tbody>';
            days.forEach(function (d) {
                var bf = d.by_feature || {};
                var parts = Object.keys(bf).map(function (k) {
                    return escapeHtml(featureLabel(k)) + ' (' + bf[k].calls + ')';
                });
                html += '<tr>'
                    + '<td>' + escapeHtml(dayLabel(d.date)) + '</td>'
                    + '<td class="aix-r">' + (d.calls || 0) + '</td>'
                    + '<td class="aix-r aix-strong">' + inr(d.cost_inr) + '</td>'
                    + '<td class="aix-r aix-muted">' + usd(d.cost_usd) + '</td>'
                    + '<td class="aix-bd">' + parts.join(' · ') + '</td>'
                    + '</tr>';
            });
            html += '</tbody></table>';
        }

        html += '<p class="aix-note">Covers AI calls instrumented for cost tracking (currently the Logs feature). Slack API calls are free and not billed.</p>';
        html += '</div>';
        return html;
    }

    window.AiExpenseModule = { render: render };
})();
