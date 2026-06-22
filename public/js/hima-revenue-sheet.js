(function () {
    'use strict';

    var config = window.__PORTAL_CONFIG || {};
    if (config.layout === 'simple') return;

    function escapeHtml(v) { return MeetingModule.escapeHtml(v); }
    function requestJson(url, options) { return MeetingModule.requestJson(url, options); }

    var ROOT_ID = 'hima_revenue_sheetView';

    // Single source of truth for formulas. Mirrored 1:1 in
    // app/Services/HimaRevenueFormula.php and verified against the live
    // Google Sheet via `php artisan hima-revenue:verify-formulas`.
    var PAYIN_COMMISSION_PCT = 1.23;
    var GST_RATE = 0.18;
    var AGORA_RATE = 0.035;

    function n(v) { return (v == null || v === '' || isNaN(Number(v))) ? 0 : Number(v); }
    function r2(v) { return Math.round(v * 100) / 100; }

    function computeRow(m) {
        var collection = n(m.collection);
        var zocketWithoutGst = n(m.zocket_meta_ads_without_gst);
        var himaCreator = n(m.hima_creator);
        var g1WithoutGst = n(m.g_ads_1_without_gst);
        var g2WithoutGst = n(m.g_ads_2_without_gst);
        var payout = n(m.payout);
        var day0Revenue = n(m.day0_revenue);

        var actualCollection = collection - (PAYIN_COMMISSION_PCT / 100) * collection;
        var collectionWithoutGst = collection / (1 + GST_RATE);

        var zocketWithGst = r2(zocketWithoutGst * (1 + GST_RATE));
        var mainMetaWithGst = r2(himaCreator * (1 + GST_RATE));
        var g1WithGst = r2(g1WithoutGst * (1 + GST_RATE));
        var g2WithGst = r2(g2WithoutGst * (1 + GST_RATE));

        var totalMetaWithGst = zocketWithGst + mainMetaWithGst;
        var totalGAdsWithGst = g1WithGst + g2WithGst;
        var totalAdsWithGst = totalMetaWithGst + totalGAdsWithGst;

        var totalMetaWithoutGst = zocketWithoutGst + himaCreator;
        var totalGAdsWithoutGst = g1WithoutGst + g2WithoutGst;
        var totalAdsWithoutGst = totalMetaWithoutGst + totalGAdsWithoutGst;

        var profit = actualCollection - (totalMetaWithGst + totalGAdsWithGst + payout);
        var roas = totalAdsWithGst > 0 ? collection / totalAdsWithGst : null;
        var day0Roas = totalAdsWithGst > 0 ? (day0Revenue / totalAdsWithGst) * 100 : null;

        var agoraCharges = Math.round(AGORA_RATE * actualCollection);
        var gstCollected = collection * (GST_RATE / (1 + GST_RATE));
        var claimTaxGst = (zocketWithGst + mainMetaWithGst + g1WithGst + g2WithGst) * (GST_RATE / (1 + GST_RATE));
        var gstPayable = gstCollected - claimTaxGst;
        var realProfit = profit - gstPayable - agoraCharges;

        return {
            payin_commission_pct: PAYIN_COMMISSION_PCT,
            actual_collection: actualCollection,
            collection_without_gst: collectionWithoutGst,
            zocket_meta_ads_with_gst: zocketWithGst,
            main_meta_ads_with_gst: mainMetaWithGst,
            g_ads_1_with_gst: g1WithGst,
            g_ads_2_with_gst: g2WithGst,
            total_meta_ads_spend_with_gst: totalMetaWithGst,
            total_g_ads_spend_with_gst: totalGAdsWithGst,
            total_ads_spend_with_gst: totalAdsWithGst,
            total_meta_ads_spend_without_gst: totalMetaWithoutGst,
            total_g_ads_spend_without_gst: totalGAdsWithoutGst,
            total_ads_spend_without_gst: totalAdsWithoutGst,
            profit: profit,
            roas: roas,
            day0_roas: day0Roas,
            agora_charges: agoraCharges,
            gst_collected: gstCollected,
            claim_tax_gst: claimTaxGst,
            gst_payable: gstPayable,
            real_profit: realProfit
        };
    }

    function fmtMoney(v) {
        if (v == null || isNaN(v)) return '';
        return Number(v).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    function fmtMoneyR(v) {
        if (v == null || isNaN(v)) return '';
        return Math.round(Number(v)).toLocaleString('en-IN');
    }
    function fmtNum(v, decimals) {
        if (v == null || isNaN(v)) return '';
        decimals = decimals == null ? 2 : decimals;
        return Number(v).toLocaleString('en-IN', { minimumFractionDigits: decimals, maximumFractionDigits: decimals });
    }

    // Manual fields (the only thing we persist).
    var MANUAL_FIELDS = [
        'collection',
        'zocket_meta_ads_without_gst',
        'hima_creator',
        'g_ads_1_without_gst',
        'g_ads_2_without_gst',
        'payout',
        'day0_revenue'
    ];

    // Column descriptors for the table. `kind` controls rendering:
    //   'serial', 'date', 'day', 'manual', 'formula', 'constant'
    var COLUMNS = [
        { key: 'serial_no', label: 'Sl.', kind: 'serial', width: 50 },
        { key: 'date', label: 'Date', kind: 'date', width: 92, sticky: true },
        { key: 'day', label: 'Day', kind: 'day', width: 84 },
        { key: 'collection', label: 'Collection', kind: 'manual', width: 130, hint: 'suggested_collection' },
        { key: 'actual_collection', label: 'Actual Collection', kind: 'formula', width: 140, formula: 'Collection − (Payin Commission/100) × Collection' },
        { key: 'collection_without_gst', label: 'Collection Without GST', kind: 'formula', width: 160, formula: 'Collection / 1.18' },
        { key: 'payin_commission_pct', label: 'Payin Commission (%)', kind: 'constant', width: 110, formula: 'Constant 1.23' },
        { key: 'zocket_meta_ads_without_gst', label: 'Zocket Meta Ads Without GST', kind: 'manual', width: 160 },
        { key: 'zocket_meta_ads_with_gst', label: 'Zocket Meta Ads With GST', kind: 'formula', width: 160, formula: 'round(Zocket Meta Without GST × 1.18, 2)' },
        { key: 'hima_creator', label: 'Hima Creator', kind: 'manual', width: 130 },
        { key: 'main_meta_ads_with_gst', label: 'Main Meta Ads With GST', kind: 'formula', width: 160, formula: 'round(Hima Creator × 1.18, 2)' },
        { key: 'g_ads_1_without_gst', label: 'G Ads 1st Without GST', kind: 'manual', width: 150 },
        { key: 'g_ads_1_with_gst', label: 'G Ads 1st With GST', kind: 'formula', width: 150, formula: 'round(G Ads 1st Without GST × 1.18, 2)' },
        { key: 'g_ads_2_without_gst', label: 'G Ads 2nd Without GST', kind: 'manual', width: 150 },
        { key: 'g_ads_2_with_gst', label: 'G Ads 2nd With GST', kind: 'formula', width: 150, formula: 'round(G Ads 2nd Without GST × 1.18, 2)' },
        { key: 'total_meta_ads_spend_with_gst', label: 'Total Meta Ads With GST', kind: 'formula', width: 160, formula: 'Zocket Meta With GST + Main Meta With GST' },
        { key: 'total_g_ads_spend_with_gst', label: 'Total G Ads With GST', kind: 'formula', width: 150, formula: 'G Ads 1st With GST + G Ads 2nd With GST' },
        { key: 'total_ads_spend_with_gst', label: 'Total Ads Spend With GST', kind: 'formula', width: 160, formula: 'Total Meta With GST + Total G Ads With GST' },
        { key: 'payout', label: 'Payout', kind: 'manual', width: 130, hint: 'suggested_payout' },
        { key: 'profit', label: 'Profit', kind: 'formula', width: 140, formula: 'Actual Collection − (Total Meta With GST + Total G Ads With GST + Payout)' },
        { key: 'roas', label: 'ROAS', kind: 'formula', width: 80, formula: 'Collection / Total Ads Spend With GST', decimals: 2 },
        { key: 'day0_revenue', label: 'Day0 Revenue', kind: 'manual', width: 130 },
        { key: 'day0_roas', label: 'Day0 ROAS', kind: 'formula', width: 100, formula: '(Day0 Revenue / Total Ads Spend With GST) × 100', decimals: 2 },
        { key: 'agora_charges', label: 'Agora Charges', kind: 'formula', width: 130, formula: 'round(3.5% × Actual Collection)' },
        { key: 'gst_collected', label: 'GST Collected', kind: 'formula', width: 130, formula: 'Collection × 0.18 / 1.18' },
        { key: 'claim_tax_gst', label: 'Claim Tax (GST)', kind: 'formula', width: 130, formula: '(All ads With GST) × 0.18 / 1.18' },
        { key: 'gst_payable', label: 'GST Payable', kind: 'formula', width: 130, formula: 'GST Collected − Claim Tax (GST)' },
        { key: 'real_profit', label: 'Real Profit', kind: 'formula', width: 140, formula: 'Profit − GST Payable − Agora Charges' },
        { key: 'total_meta_ads_spend_without_gst', label: 'Total Meta Without GST', kind: 'formula', width: 160, formula: 'Zocket Meta Without GST + Hima Creator' },
        { key: 'total_g_ads_spend_without_gst', label: 'Total G Ads Without GST', kind: 'formula', width: 160, formula: 'G Ads 1st Without GST + G Ads 2nd Without GST' },
        { key: 'total_ads_spend_without_gst', label: 'Total Ads Spend Without GST', kind: 'formula', width: 170, formula: 'Total Meta Without GST + Total G Ads Without GST' }
    ];

    var state = {
        canEdit: false,
        currentMonth: null,           // 'YYYY-MM'
        months: [],
        rows: [],                     // server rows for currentMonth (each has manual + suggested_*)
        // Pending PUTs keyed by `${date}` so multiple field edits within the
        // debounce window collapse into one request.
        pendingPuts: {},
        pendingTimers: {},
        saveTokens: {}
    };

    function monthLabel(ym) {
        var parts = ym.split('-');
        var d = new Date(Number(parts[0]), Number(parts[1]) - 1, 1);
        return d.toLocaleDateString('en-IN', { month: 'long', year: 'numeric' });
    }

    async function loadMonths() {
        try {
            var r = await requestJson('/api/hima-revenue-sheet/months');
            state.months = r.months || [];
        } catch (e) {
            state.months = [];
        }
    }

    async function loadMonth(ym) {
        var r = await requestJson('/api/hima-revenue-sheet?month=' + encodeURIComponent(ym));
        state.canEdit = !!r.can_edit;
        state.currentMonth = r.month || ym;
        state.rows = r.rows || [];
    }

    function flushPending(date) {
        clearTimeout(state.pendingTimers[date]);
        state.pendingTimers[date] = null;
        var payload = state.pendingPuts[date];
        if (!payload || Object.keys(payload).length === 0) return;
        delete state.pendingPuts[date];
        var token = (state.saveTokens[date] = (state.saveTokens[date] || 0) + 1);
        requestJson('/api/hima-revenue-sheet/' + date, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        }).catch(function () {
            // Stay quiet on transient failures; user will retry on next blur.
            // We could surface a toast here later if desired.
        }).finally(function () {
            // If a newer save raced this one, ignore stale completion.
            if (state.saveTokens[date] !== token) return;
        });
    }

    function queueSave(date, field, value) {
        state.pendingPuts[date] = state.pendingPuts[date] || {};
        state.pendingPuts[date][field] = value;
        clearTimeout(state.pendingTimers[date]);
        state.pendingTimers[date] = setTimeout(function () { flushPending(date); }, 600);
    }

    function parseInput(raw) {
        if (raw == null) return null;
        var s = String(raw).replace(/[, ₹]/g, '').trim();
        if (s === '') return null;
        var v = Number(s);
        return isNaN(v) ? null : v;
    }

    function findRow(date) {
        for (var i = 0; i < state.rows.length; i++) if (state.rows[i].date === date) return state.rows[i];
        return null;
    }

    function renderCell(col, row, idx) {
        var computed = computeRow(row);
        var rawVal;

        if (col.kind === 'serial') {
            return '<td class="hrs-cell hrs-c-serial">' + (idx + 1) + '</td>';
        }
        if (col.kind === 'date') {
            var d = new Date(row.date + 'T00:00:00');
            var label = d.toLocaleDateString('en-IN', { day: '2-digit', month: 'short' });
            return '<td class="hrs-cell hrs-c-date hrs-sticky-col">' + escapeHtml(label) + '</td>';
        }
        if (col.kind === 'day') {
            return '<td class="hrs-cell hrs-c-day">' + escapeHtml(row.day || '') + '</td>';
        }
        if (col.kind === 'constant') {
            return '<td class="hrs-cell hrs-c-formula" title="' + escapeHtml(col.formula || '') + '">' + fmtNum(computed[col.key], 2) + '</td>';
        }
        if (col.kind === 'formula') {
            var v = computed[col.key];
            var display = (col.decimals != null) ? fmtNum(v, col.decimals) : fmtMoneyR(v);
            return '<td class="hrs-cell hrs-c-formula" title="' + escapeHtml(col.formula || '') + '">' + display + '</td>';
        }
        // manual
        rawVal = row[col.key];
        var hint = col.hint && row[col.hint] != null ? row[col.hint] : null;
        var input = '<input type="text" inputmode="decimal" class="hrs-input" data-date="' + row.date + '" data-field="' + col.key + '"' +
            (state.canEdit ? '' : ' disabled') +
            ' value="' + (rawVal == null ? '' : escapeHtml(String(rawVal))) + '">';
        var hintEl = '';
        if (hint != null && (rawVal == null || rawVal === '')) {
            hintEl = '<button type="button" class="hrs-hint" data-date="' + row.date + '" data-field="' + col.key + '" data-value="' + hint + '" title="Use API value">↩ ' + fmtMoneyR(hint) + '</button>';
        }
        return '<td class="hrs-cell hrs-c-manual">' + input + hintEl + '</td>';
    }

    function renderTotalsRow() {
        // Sum every numeric column across the month (manual + computed).
        var sums = {};
        COLUMNS.forEach(function (c) { sums[c.key] = 0; });
        var manualSums = {};
        MANUAL_FIELDS.forEach(function (k) { manualSums[k] = 0; });

        state.rows.forEach(function (row) {
            var c = computeRow(row);
            COLUMNS.forEach(function (col) {
                if (col.kind === 'manual') sums[col.key] += n(row[col.key]);
                else if (col.kind === 'formula' && col.key !== 'roas' && col.key !== 'day0_roas') {
                    sums[col.key] += n(c[col.key]);
                }
            });
        });

        var html = '<tr class="hrs-totals-row">';
        COLUMNS.forEach(function (col, i) {
            if (col.kind === 'serial') { html += '<td class="hrs-cell hrs-totals-cell">Σ</td>'; return; }
            if (col.kind === 'date') { html += '<td class="hrs-cell hrs-totals-cell hrs-sticky-col">Total</td>'; return; }
            if (col.kind === 'day' || col.kind === 'constant') { html += '<td class="hrs-cell hrs-totals-cell"></td>'; return; }
            if (col.kind === 'formula' && (col.key === 'roas' || col.key === 'day0_roas')) {
                // Ratios don't sum meaningfully — leave blank.
                html += '<td class="hrs-cell hrs-totals-cell"></td>';
                return;
            }
            html += '<td class="hrs-cell hrs-totals-cell">' + fmtMoneyR(sums[col.key]) + '</td>';
        });
        html += '</tr>';
        return html;
    }

    function renderTable() {
        var html = '<div class="hrs-table-scroll"><table class="hrs-table"><thead><tr>';
        COLUMNS.forEach(function (col) {
            var cls = 'hrs-th';
            if (col.kind === 'manual') cls += ' hrs-th-manual';
            if (col.kind === 'formula' || col.kind === 'constant') cls += ' hrs-th-formula';
            if (col.sticky) cls += ' hrs-sticky-col';
            var title = col.formula ? ' title="' + escapeHtml(col.formula) + '"' : '';
            html += '<th class="' + cls + '" style="min-width:' + col.width + 'px"' + title + '>' + escapeHtml(col.label) + '</th>';
        });
        html += '</tr></thead><tbody>';
        state.rows.forEach(function (row, i) {
            html += '<tr class="hrs-row" data-date="' + row.date + '">';
            COLUMNS.forEach(function (col) {
                html += renderCell(col, row, i);
            });
            html += '</tr>';
        });
        html += renderTotalsRow();
        html += '</tbody></table></div>';
        return html;
    }

    function renderMonthStrip() {
        var html = '<div class="hrs-month-strip">';
        state.months.forEach(function (ym) {
            var active = ym === state.currentMonth ? ' hrs-month-tab--active' : '';
            html += '<button type="button" class="hrs-month-tab' + active + '" data-month="' + ym + '">' + escapeHtml(monthLabel(ym)) + '</button>';
        });
        html += '</div>';
        return html;
    }

    function recomputeRowDisplay(date) {
        var root = document.getElementById(ROOT_ID);
        if (!root) return;
        var tr = root.querySelector('.hrs-row[data-date="' + date + '"]');
        if (!tr) return;
        var row = findRow(date);
        if (!row) return;
        var c = computeRow(row);
        var cells = tr.children;
        COLUMNS.forEach(function (col, i) {
            if (col.kind !== 'formula' && col.kind !== 'constant') return;
            var v = c[col.key];
            var display = (col.decimals != null) ? fmtNum(v, col.decimals) : fmtMoneyR(v);
            if (col.kind === 'constant') display = fmtNum(c[col.key], 2);
            cells[i].textContent = display;
        });
        // Refresh totals
        var totalsTr = root.querySelector('.hrs-totals-row');
        if (totalsTr) {
            var newTotals = renderTotalsRow();
            var wrap = document.createElement('tbody');
            wrap.innerHTML = newTotals;
            totalsTr.parentNode.replaceChild(wrap.firstChild, totalsTr);
        }
    }

    function wireHandlers() {
        var root = document.getElementById(ROOT_ID);
        if (!root) return;

        // Month tab clicks
        root.querySelectorAll('.hrs-month-tab').forEach(function (b) {
            b.onclick = async function () {
                var ym = b.getAttribute('data-month');
                if (ym === state.currentMonth) return;
                await loadMonth(ym);
                paint();
            };
        });

        // Hint affordance — fill an empty cell with API value.
        root.querySelectorAll('.hrs-hint').forEach(function (h) {
            h.onclick = function () {
                if (!state.canEdit) return;
                var date = h.getAttribute('data-date');
                var field = h.getAttribute('data-field');
                var v = Number(h.getAttribute('data-value')) || 0;
                var input = root.querySelector('.hrs-input[data-date="' + date + '"][data-field="' + field + '"]');
                if (!input) return;
                input.value = v.toString();
                applyInputChange(input, true);
            };
        });

        if (!state.canEdit) return;

        // Manual cell edits — debounced autosave + blur flush.
        root.querySelectorAll('.hrs-input').forEach(function (inp) {
            inp.oninput = function () { applyInputChange(inp, false); };
            inp.onblur = function () { applyInputChange(inp, true); };
        });
    }

    function applyInputChange(inp, flushNow) {
        var date = inp.getAttribute('data-date');
        var field = inp.getAttribute('data-field');
        var parsed = parseInput(inp.value);
        var row = findRow(date);
        if (!row) return;
        row[field] = parsed;
        recomputeRowDisplay(date);
        // If user cleared the cell, also re-evaluate the hint affordance.
        if ((parsed == null) && row['suggested_' + field.split('_')[0]] != null) {
            // best-effort UI nicety; hide the hint until next render
        }
        queueSave(date, field, parsed);
        if (flushNow) flushPending(date);
    }

    function paint() {
        var root = document.getElementById(ROOT_ID);
        if (!root) return;
        var html = '<div class="hrs-wrap">' +
            '<div class="hrs-header">' +
                '<h2>Hima Revenue Sheet</h2>' +
                (state.canEdit ? '' : '<span class="hrs-viewonly-pill">View only</span>') +
            '</div>' +
            renderMonthStrip() +
            renderTable() +
            '</div>';
        root.innerHTML = html;
        wireHandlers();
    }

    async function render() {
        var root = document.getElementById(ROOT_ID);
        if (!root) return;
        root.innerHTML = '<div class="hrs-wrap"><div class="kpi-status-msg" style="padding:24px">Loading…</div></div>';

        try {
            await loadMonths();
            var defaultMonth = state.months[state.months.length - 1] ||
                new Date().toISOString().slice(0, 7);
            await loadMonth(defaultMonth);
            paint();
        } catch (e) {
            root.innerHTML = '<div class="hrs-wrap"><div class="kpi-status-msg" style="padding:24px;color:#f87171">Failed to load: ' + escapeHtml(e.message || 'Request failed') + '</div></div>';
        }
    }

    window.HimaRevenueSheetModule = { render: render };
})();
