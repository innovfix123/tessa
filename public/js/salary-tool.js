(function () {
    'use strict';

    // Standalone CTC <-> breakup calculator. Talks to /api/salary-tool, which
    // reuses the SAME engine/slabs/rules as the offer-letter Annexure-I autofill
    // (LetterSalaryCalculator) so the two can never drift.

    var _state = {
        mode: 'ctc',            // 'ctc' (forward) | 'basic' (backward)
        category: 'fulltime',   // fulltime | intern | freelancer
        period: 'annual',       // annual | monthly  (ctc mode only)
        amount: ''
    };
    var _bound = false;
    var _timer = null;

    function requestJson(url, options) {
        if (window.MeetingModule && MeetingModule.requestJson) {
            return MeetingModule.requestJson(url, options);
        }
        return fetch(url, Object.assign({
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        }, options || {})).then(function (r) {
            return r.json().then(function (body) {
                if (!r.ok) {
                    var err = new Error(body.error || body.message || 'Request failed');
                    err.status = r.status;
                    throw err;
                }
                return body;
            });
        });
    }

    function escapeHtml(v) {
        if (window.MeetingModule && MeetingModule.escapeHtml) return MeetingModule.escapeHtml(v);
        var d = document.createElement('div');
        d.textContent = v == null ? '' : String(v);
        return d.innerHTML;
    }

    function inr(n) {
        n = Math.round(Number(n) || 0);
        return '₹' + n.toLocaleString('en-IN');
    }

    function parseAmount(v) {
        var n = parseFloat(String(v == null ? '' : v).replace(/[₹,\s]/g, ''));
        return isFinite(n) && n >= 0 ? n : null;
    }

    function ensureShell() {
        var root = document.getElementById('salary_toolView');
        if (!root || root.getAttribute('data-st-built')) return root;
        root.setAttribute('data-st-built', '1');
        root.innerHTML =
            '<div class="st-wrap">' +
                '<div class="st-header">' +
                    '<h1 class="st-title">Salary Tool</h1>' +
                    '<p class="st-sub">CTC &harr; salary breakup — same slabs &amp; rules as the offer letters.</p>' +
                '</div>' +
                '<div class="st-panel">' +
                    '<div class="st-controls">' +
                        '<div class="st-field">' +
                            '<span class="st-field-lbl">Calculate</span>' +
                            '<div class="st-seg" id="stMode">' +
                                '<button type="button" class="st-seg-btn st-on" data-val="ctc">From CTC</button>' +
                                '<button type="button" class="st-seg-btn" data-val="basic">From Basic</button>' +
                            '</div>' +
                        '</div>' +
                        '<div class="st-field">' +
                            '<span class="st-field-lbl">Type</span>' +
                            '<div class="st-seg" id="stCat">' +
                                '<button type="button" class="st-seg-btn st-on" data-val="fulltime">Full-time</button>' +
                                '<button type="button" class="st-seg-btn" data-val="intern">Intern</button>' +
                                '<button type="button" class="st-seg-btn" data-val="freelancer">Freelancer</button>' +
                            '</div>' +
                        '</div>' +
                    '</div>' +
                    '<div class="st-input-row">' +
                        '<label class="st-input-lbl" id="stInputLbl" for="stAmount">Annual CTC</label>' +
                        '<div class="st-input-wrap">' +
                            '<span class="st-rupee">₹</span>' +
                            '<input type="text" inputmode="numeric" autocomplete="off" id="stAmount" class="st-input" placeholder="0">' +
                            '<div class="st-period" id="stPeriod">' +
                                '<button type="button" class="st-period-btn st-on" data-val="annual">/yr</button>' +
                                '<button type="button" class="st-period-btn" data-val="monthly">/mo</button>' +
                            '</div>' +
                        '</div>' +
                    '</div>' +
                    '<div class="st-note" id="stNote"></div>' +
                '</div>' +
                '<div class="st-result" id="stResult"><div class="st-empty">Enter an amount to see the breakup.</div></div>' +
            '</div>';
        return root;
    }

    function syncInputUi() {
        var lbl = document.getElementById('stInputLbl');
        var period = document.getElementById('stPeriod');
        var input = document.getElementById('stAmount');
        if (_state.mode === 'basic') {
            if (lbl) lbl.textContent = 'Monthly Basic';
            if (period) period.style.display = 'none';
            if (input) input.placeholder = 'e.g. 25000';
        } else {
            if (lbl) lbl.textContent = _state.period === 'monthly' ? 'Monthly CTC' : 'Annual CTC';
            if (period) period.style.display = '';
            if (input) input.placeholder = _state.period === 'monthly' ? 'e.g. 50000' : 'e.g. 600000';
        }
    }

    function setSeg(containerId, val) {
        var c = document.getElementById(containerId);
        if (!c) return;
        c.querySelectorAll('[data-val]').forEach(function (b) {
            b.classList.toggle('st-on', b.getAttribute('data-val') === val);
        });
    }

    function row(label, monthly, annual, cls) {
        return '<tr' + (cls ? ' class="' + cls + '"' : '') + '>' +
            '<td class="st-c-lbl">' + escapeHtml(label) + '</td>' +
            '<td class="st-r">' + inr(monthly) + '</td>' +
            '<td class="st-r">' + inr(annual) + '</td>' +
        '</tr>';
    }

    function groupRow(label) {
        return '<tr class="st-grp"><td colspan="3">' + escapeHtml(label) + '</td></tr>';
    }

    function renderResult(b) {
        var el = document.getElementById('stResult');
        if (!el) return;
        var ctcCls = 'st-row-ctc' + (_state.mode === 'basic' ? ' st-derived' : '');
        // TDS is intentionally not computed here (it varies by regime/declarations).
        // Above ₹12 LPA it becomes material, so flag that the Net Take-home is
        // pre-TDS. Rendered ABOVE the table (not just below it) so it can't be
        // missed without scrolling.
        var tdsNote = '';
        if (b.annual_ctc > 1200000) {
            tdsNote = '<div class="st-tds-note st-tds-note-top">⚠️ <b>TDS not included — CTC is above ₹12 LPA.</b> ' +
                'TDS is shown as ₹0 below; above ₹12 LPA it depends on the employee’s tax regime &amp; declarations and must be calculated separately. ' +
                'Please ask <b>Shoyab</b> or the <b>Finance Team’s Accounts head</b> to prepare the TDS calculation. ' +
                'The Net Take-home below is therefore <b>pre-TDS</b>.</div>';
        }
        var html =
            tdsNote +
            '<table class="st-table">' +
                '<thead><tr><th>Component</th><th class="st-r">Monthly</th><th class="st-r">Annual</th></tr></thead>' +
                '<tbody>' +
                    row('Cost to Company (CTC)', b.monthly_ctc, b.annual_ctc, ctcCls) +
                    groupRow('Earnings') +
                    row('Basic', b.basic_monthly, b.basic_annual) +
                    row('HRA', b.hra_monthly, b.hra_annual) +
                    row('Other Allowance', b.other_monthly, b.other_annual) +
                    row('Gross Salary', b.gross_monthly, b.gross_annual, 'st-row-sum') +
                    groupRow('Employer Contribution (part of CTC)') +
                    row('Employer PF', b.employer_pf_monthly, b.employer_pf_annual) +
                    row('Employer ESI', b.employer_esi_monthly, b.employer_esi_annual) +
                    groupRow('Deductions from Gross') +
                    row('Employee PF', b.employee_pf_monthly, b.employee_pf_annual) +
                    row('Employee ESI', b.employee_esi_monthly, b.employee_esi_annual) +
                    row('Professional Tax', b.professional_tax_monthly, b.professional_tax_annual) +
                    row('TDS', b.tds_monthly, b.tds_annual) +
                    row('Total Deductions', b.total_deductions_monthly, b.total_deductions_annual, 'st-row-sum') +
                    row('Net Take-home', b.net_monthly, b.net_annual, 'st-row-net') +
                '</tbody>' +
            '</table>' +
            '<p class="st-formula">CTC = Gross + Employer PF + Employer ESI · Basic = 50% of CTC · HRA = 50% of Basic' +
                (_state.mode === 'basic' ? ' · CTC derived from Basic (Basic = 50% of CTC)' : '') + '</p>';
        el.innerHTML = html;
    }

    function compute() {
        var note = document.getElementById('stNote');
        var amt = parseAmount(_state.amount);
        if (amt === null || amt === 0) {
            var res = document.getElementById('stResult');
            if (res) res.innerHTML = '<div class="st-empty">Enter an amount to see the breakup.</div>';
            if (note) note.textContent = '';
            return;
        }
        var payload = {
            mode: _state.mode,
            amount: amt,
            employee_category: _state.category
        };
        if (_state.mode === 'ctc') payload.period = _state.period;

        requestJson('/api/salary-tool', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        }).then(function (data) {
            if (data && data.breakup) {
                renderResult(data.breakup);
                if (note) note.textContent = '';
            }
        }).catch(function (err) {
            if (note) note.textContent = (err && err.status === 403)
                ? 'You do not have access to the Salary Tool.'
                : 'Could not calculate. Check the amount and try again.';
        });
    }

    function scheduleCompute() {
        if (_timer) clearTimeout(_timer);
        _timer = setTimeout(compute, 250);
    }

    function bindEvents() {
        if (_bound) return;
        _bound = true;

        var mode = document.getElementById('stMode');
        if (mode) mode.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-val]');
            if (!btn) return;
            _state.mode = btn.getAttribute('data-val');
            setSeg('stMode', _state.mode);
            syncInputUi();
            compute();
        });

        var cat = document.getElementById('stCat');
        if (cat) cat.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-val]');
            if (!btn) return;
            _state.category = btn.getAttribute('data-val');
            setSeg('stCat', _state.category);
            compute();
        });

        var period = document.getElementById('stPeriod');
        if (period) period.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-val]');
            if (!btn) return;
            _state.period = btn.getAttribute('data-val');
            setSeg('stPeriod', _state.period);
            syncInputUi();
            compute();
        });

        var input = document.getElementById('stAmount');
        if (input) {
            input.addEventListener('input', function () {
                _state.amount = input.value;
                scheduleCompute();
            });
            input.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') { e.preventDefault(); compute(); }
            });
        }
    }

    function render() {
        ensureShell();
        bindEvents();
        syncInputUi();
    }

    window.SalaryToolModule = { render: render };
})();
