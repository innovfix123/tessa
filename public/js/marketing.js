(function () {
    'use strict';
    var config = window.__PORTAL_CONFIG || {};
    function escapeHtml(v) { return MeetingModule.escapeHtml(v); }
    function requestJson(url, options) { return MeetingModule.requestJson(url, options); }

    // Anirudh runs Hima ads region-wise only. For him we hide Sudar/Thedal,
    // hide the Campaign/Region toggle (force region), and show a per-date
    // language-level region spend table instead of campaign rows.
    var IS_ANIRUDH_VIEW = (config.userName || '') === 'Anirudh';

    var REGION_LANG_ORDER = ['tamil', 'telugu', 'kannada', 'malayalam', 'bengali', 'hindi'];
    var REGION_LANG_LABEL = {
        tamil:     'Tamil',
        telugu:    'Telugu',
        kannada:   'Kannada',
        malayalam: 'Malayalam',
        bengali:   'Bengali',
        hindi:     'Hindi / Other'
    };

    function renderRegionTable(regionRows, sourceLabel) {
        if (!regionRows || !regionRows.length) {
            return '<div class="meta-empty">No ' + escapeHtml(sourceLabel) + ' Ads data uploaded yet. Upload a region CSV to populate this table.</div>';
        }
        var html = '<div class="meta-table-wrap"><table class="meta-table mr-region-table">' +
            '<thead><tr>' +
                '<th>Date</th>';
        REGION_LANG_ORDER.forEach(function (lang) {
            html += '<th class="meta-th-num">' + escapeHtml(REGION_LANG_LABEL[lang]) + '</th>';
        });
        html += '<th class="meta-th-num">Total (INR)</th>' +
            '</tr></thead><tbody>';

        regionRows.forEach(function (row) {
            var dateObj = new Date(row.date + 'T00:00:00');
            var dateLbl = dateObj.toLocaleDateString('en-IN', { timeZone: 'Asia/Kolkata', day: '2-digit', month: 'short', year: 'numeric', weekday: 'short' });
            html += '<tr>' +
                '<td>' + escapeHtml(dateLbl) + '</td>';
            REGION_LANG_ORDER.forEach(function (lang) {
                var v = row.languages && row.languages[lang];
                html += '<td class="meta-td-num">' + (v ? fmtINR(v) : '—') + '</td>';
            });
            html += '<td class="meta-td-num meta-td-strong">' + fmtINR(row.total) + '</td>' +
            '</tr>';
        });

        html += '</tbody></table></div>';
        return html;
    }

/* ── Meta Ads Reports ── */
var metaDateFrom = '';
var metaDateTo = '';
var metaCampaign = '';
var metaProject = '';
var metaPage = 1;
var metaPerPage = 25;

function fmtINR(n) {
    if (n == null) return '—';
    return Number(n).toLocaleString('en-IN', { maximumFractionDigits: 2 });
}

function formatRegionUploadResult(body) {
    var lines = ['Region report processed: ' + body.total_rows + ' rows, ' + body.dates_processed + ' date(s).', '', 'Daily Report auto-filled:'];
    var labels = { tamil: 'Tamil', telugu: 'Telugu', kannada: 'Kannada', malayalam: 'Malayalam', bengali: 'Bengali', hindi: 'Hindi' };
    var dates = body.auto_filled || {};
    for (var date in dates) {
        var t = dates[date].totals || {};
        lines.push(date + ':');
        var parts = [];
        for (var lang in labels) {
            if (t[lang] !== undefined) parts.push('  ' + labels[lang] + ': ' + fmtINR(t[lang]));
        }
        lines.push(parts.join('\n'));
    }
    return lines.join('\n');
}

async function renderMetaAds() {
    var root = document.getElementById('meta_adsView');
    if (!root) return;

    // Anirudh sees Hima only — preselect on first load.
    if (IS_ANIRUDH_VIEW && !metaProject) metaProject = 'hima';

    root.innerHTML = '<div class="meta-wrap"><div class="kpi-status-msg">Loading Meta Ads data...</div></div>';

    try {
        var url = '/api/meta-ad-reports';
        var params = [];
        if (metaProject) params.push('project=' + encodeURIComponent(metaProject));
        if (metaDateFrom) params.push('from=' + encodeURIComponent(metaDateFrom));
        if (metaDateTo) params.push('to=' + encodeURIComponent(metaDateTo));
        if (metaCampaign) params.push('campaign=' + encodeURIComponent(metaCampaign));
        if (params.length) url += '?' + params.join('&');

        var payload = await requestJson(url);
        var reports = payload.reports || [];
        var summary = payload.summary || {};

        // Pagination
        var totalRows = reports.length;
        var totalPages = Math.max(1, Math.ceil(totalRows / metaPerPage));
        if (metaPage > totalPages) metaPage = totalPages;
        var startIdx = (metaPage - 1) * metaPerPage;
        var pageRows = reports.slice(startIdx, startIdx + metaPerPage);

        // Project tabs
        var projects = payload.projects || {};
        var projectKeys = Object.keys(projects);
        if (IS_ANIRUDH_VIEW) {
            projectKeys = projectKeys.filter(function (k) { return k === 'hima'; });
        }
        var projectTabsHtml = '<div class="meta-project-tabs">';
        if (!IS_ANIRUDH_VIEW) {
            projectTabsHtml += '<button class="meta-proj-tab' + (!metaProject ? ' meta-proj-active' : '') + '" data-proj="">All</button>';
        }
        projectKeys.forEach(function (k) {
            projectTabsHtml += '<button class="meta-proj-tab' + (metaProject === k ? ' meta-proj-active' : '') + '" data-proj="' + k + '">' + escapeHtml(projects[k]) + '</button>';
        });
        projectTabsHtml += '</div>';

        // Date coverage tracker per project
        var coverage = payload.coverage || {};
        var coverageHtml = '<div class="meta-coverage">' +
            '<div class="meta-coverage-header">' +
            '<span class="meta-coverage-title">Upload Tracker <span class="meta-coverage-sub">(last 7 days)</span></span>' +
            '<span class="meta-coverage-legend">' +
            '<span class="meta-legend-item"><span class="meta-dot meta-dot-ok"></span> Uploaded</span>' +
            '<span class="meta-legend-item"><span class="meta-dot meta-dot-miss"></span> Missing</span>' +
            '</span></div>';

        projectKeys.forEach(function (proj) {
            var days = coverage[proj] || [];
            var upCount = days.filter(function (c) { return c.uploaded; }).length;
            var missCount = days.length - upCount;
            coverageHtml += '<div class="meta-cov-project">' +
                '<div class="meta-cov-proj-label">' + escapeHtml(projects[proj]) +
                ' <span class="meta-cov-proj-stat">' + upCount + '/7</span></div>' +
                '<div class="meta-coverage-grid">';

            days.forEach(function (c) {
                var cls = c.uploaded ? 'meta-day-ok' : 'meta-day-miss';
                var dateObj = new Date(c.date + 'T00:00:00');
                var label = dateObj.toLocaleDateString('en-IN', { timeZone: 'Asia/Kolkata', day: '2-digit', month: 'short' });
                var tooltip = projects[proj] + ' — ' + c.date + ' (' + c.day + ')';
                if (c.uploaded) {
                    tooltip += ' — ' + c.rows + ' rows, ' + fmtINR(c.spend) + ' INR';
                } else {
                    tooltip += ' — Not uploaded';
                }
                coverageHtml += '<div class="meta-day ' + cls + '" title="' + escapeHtml(tooltip) + '" data-date="' + c.date + '" data-proj="' + proj + '">' +
                    '<div class="meta-day-label">' + c.day.substring(0, 2) + '</div>' +
                    '<div class="meta-day-date">' + label + '</div>' +
                    (c.uploaded ? '<div class="meta-day-spend">' + fmtINR(c.spend) + '</div>' : '<div class="meta-day-miss-icon">!</div>') +
                    '</div>';
            });

            coverageHtml += '</div></div>';
        });

        coverageHtml += '</div>';

        // Summary cards
        var summaryHtml = '<div class="meta-summary">' +
            '<div class="meta-stat"><div class="meta-stat-val">' + fmtINR(summary.total_spend) + '</div><div class="meta-stat-lbl">Total Spend (INR)</div></div>' +
            '<div class="meta-stat"><div class="meta-stat-val">' + fmtINR(summary.total_impressions) + '</div><div class="meta-stat-lbl">Impressions</div></div>' +
            '<div class="meta-stat"><div class="meta-stat-val">' + fmtINR(summary.total_reach) + '</div><div class="meta-stat-lbl">Reach</div></div>' +
            '<div class="meta-stat"><div class="meta-stat-val">' + fmtINR(summary.total_installs) + '</div><div class="meta-stat-lbl">App Installs</div></div>' +
            '<div class="meta-stat"><div class="meta-stat-val">' + fmtINR(summary.total_results) + '</div><div class="meta-stat-lbl">Results</div></div>' +
            '<div class="meta-stat"><div class="meta-stat-val">' + fmtINR(summary.total_first_purchases) + '</div><div class="meta-stat-lbl">1st Purchases</div></div>' +
            '</div>';

        // Filters
        var filterHtml = '<div class="meta-filters">' +
            '<label class="meta-date-label" for="metaDateFrom">From</label>' +
            '<input type="date" class="meta-date-input" id="metaDateFrom" value="' + escapeHtml(metaDateFrom) + '">' +
            '<label class="meta-date-label" for="metaDateTo">To</label>' +
            '<input type="date" class="meta-date-input" id="metaDateTo" value="' + escapeHtml(metaDateTo) + '">' +
            (IS_ANIRUDH_VIEW ? '' : '<input type="text" class="meta-campaign-input" id="metaCampaignFilter" placeholder="Search campaign..." value="' + escapeHtml(metaCampaign) + '">') +
            '<button type="button" class="meta-filter-btn" id="metaFilterBtn">Filter</button>' +
            (metaDateFrom || metaDateTo || metaCampaign ? '<button type="button" class="meta-clear-btn" id="metaClearBtn">Clear</button>' : '') +
            '<button type="button" class="btn btn-primary" id="metaUploadBtn">+ Upload CSV</button>' +
            '</div>';

        // Table — Anirudh sees a region-spend table; everyone else sees campaign rows.
        var tableHtml = '';
        if (IS_ANIRUDH_VIEW) {
            tableHtml = renderRegionTable(payload.region_uploads || [], 'Meta');
        } else if (reports.length === 0) {
            tableHtml = '<div class="meta-empty">No Meta Ads data found. Upload a CSV export from Meta Ads Manager.</div>';
        } else {
            tableHtml = '<div class="meta-table-wrap"><table class="meta-table">' +
                '<thead><tr>' +
                '<th>Date</th>' +
                '<th>Campaign</th>' +
                '<th>Ad Set</th>' +
                '<th>Ad</th>' +
                '<th class="meta-num">Spend</th>' +
                '<th class="meta-num">Reach</th>' +
                '<th class="meta-num">Impr.</th>' +
                '<th class="meta-num">Results</th>' +
                '<th class="meta-num">CPR</th>' +
                '<th class="meta-num">Installs</th>' +
                '<th class="meta-num">CPI</th>' +
                '<th class="meta-num">Purchases</th>' +
                '<th class="meta-num">CPP</th>' +
                '<th class="meta-num">CPC</th>' +
                '<th class="meta-num">CTR%</th>' +
                '</tr></thead><tbody>';

            pageRows.forEach(function (r) {
                tableHtml += '<tr>' +
                    '<td class="meta-date-cell">' + escapeHtml(r.reporting_starts) + '</td>' +
                    '<td class="meta-text-cell" title="' + escapeHtml(r.campaign_name) + '">' + escapeHtml(r.campaign_name.length > 40 ? r.campaign_name.substring(0, 40) + '...' : r.campaign_name) + '</td>' +
                    '<td class="meta-text-cell" title="' + escapeHtml(r.ad_set_name) + '">' + escapeHtml(r.ad_set_name.length > 30 ? r.ad_set_name.substring(0, 30) + '...' : r.ad_set_name) + '</td>' +
                    '<td class="meta-text-cell" title="' + escapeHtml(r.ad_name) + '">' + escapeHtml(r.ad_name.length > 30 ? r.ad_name.substring(0, 30) + '...' : r.ad_name) + '</td>' +
                    '<td class="meta-num">' + fmtINR(r.amount_spent) + '</td>' +
                    '<td class="meta-num">' + fmtINR(r.reach) + '</td>' +
                    '<td class="meta-num">' + fmtINR(r.impressions) + '</td>' +
                    '<td class="meta-num">' + (r.results || 0) + '</td>' +
                    '<td class="meta-num">' + (r.cost_per_result != null ? fmtINR(r.cost_per_result) : '—') + '</td>' +
                    '<td class="meta-num">' + (r.app_installs || 0) + '</td>' +
                    '<td class="meta-num">' + (r.cost_per_install != null ? fmtINR(r.cost_per_install) : '—') + '</td>' +
                    '<td class="meta-num">' + (r.new_user_first_purchase || 0) + '</td>' +
                    '<td class="meta-num">' + (r.cost_per_first_purchase != null ? fmtINR(r.cost_per_first_purchase) : '—') + '</td>' +
                    '<td class="meta-num">' + (r.cpc != null ? fmtINR(r.cpc) : '—') + '</td>' +
                    '<td class="meta-num">' + (r.ctr != null ? r.ctr + '%' : '—') + '</td>' +
                    '</tr>';
            });

            tableHtml += '</tbody></table></div>';
        }

        // Pagination bar — skipped for Anirudh since the table is hidden.
        var paginationHtml = '';
        if (!IS_ANIRUDH_VIEW && totalRows > metaPerPage) {
            var showFrom = startIdx + 1;
            var showTo = Math.min(startIdx + metaPerPage, totalRows);
            paginationHtml = '<div class="meta-pagination">' +
                '<span class="meta-page-info">Showing ' + showFrom + '–' + showTo + ' of ' + totalRows + '</span>' +
                '<div class="meta-page-btns">';

            // First + Prev
            paginationHtml += '<button class="meta-page-btn" data-page="1"' + (metaPage <= 1 ? ' disabled' : '') + '>&laquo;</button>';
            paginationHtml += '<button class="meta-page-btn" data-page="' + (metaPage - 1) + '"' + (metaPage <= 1 ? ' disabled' : '') + '>&lsaquo; Prev</button>';

            // Page numbers (show up to 5 around current)
            var startP = Math.max(1, metaPage - 2);
            var endP = Math.min(totalPages, metaPage + 2);
            if (startP > 1) paginationHtml += '<span class="meta-page-dots">...</span>';
            for (var p = startP; p <= endP; p++) {
                paginationHtml += '<button class="meta-page-btn' + (p === metaPage ? ' meta-page-active' : '') + '" data-page="' + p + '">' + p + '</button>';
            }
            if (endP < totalPages) paginationHtml += '<span class="meta-page-dots">...</span>';

            // Next + Last
            paginationHtml += '<button class="meta-page-btn" data-page="' + (metaPage + 1) + '"' + (metaPage >= totalPages ? ' disabled' : '') + '>Next &rsaquo;</button>';
            paginationHtml += '<button class="meta-page-btn" data-page="' + totalPages + '"' + (metaPage >= totalPages ? ' disabled' : '') + '>&raquo;</button>';

            // Per page selector
            paginationHtml += '<select class="meta-perpage-select" id="metaPerPageSel">';
            [25, 50, 100].forEach(function (n) {
                paginationHtml += '<option value="' + n + '"' + (metaPerPage === n ? ' selected' : '') + '>' + n + '/page</option>';
            });
            paginationHtml += '</select>';

            paginationHtml += '</div></div>';
        }

        var headerCount = IS_ANIRUDH_VIEW ? '' : '<span class="meta-count">' + totalRows + ' rows</span>';
        root.innerHTML = '<div class="meta-wrap">' +
            '<div class="meta-header"><h2 class="meta-title">Meta Ads Reports</h2>' + headerCount + '</div>' +
            projectTabsHtml + coverageHtml + summaryHtml + filterHtml + tableHtml + paginationHtml + '</div>';

        // Bind events — open native date picker on click
        var fromInput = document.getElementById('metaDateFrom');
        var toInput = document.getElementById('metaDateTo');
        fromInput.onclick = function () { if (fromInput.showPicker) fromInput.showPicker(); };
        toInput.onclick = function () { if (toInput.showPicker) toInput.showPicker(); };

        document.getElementById('metaFilterBtn').onclick = function () {
            metaDateFrom = document.getElementById('metaDateFrom').value;
            metaDateTo = document.getElementById('metaDateTo').value;
            var campaignEl = document.getElementById('metaCampaignFilter');
            metaCampaign = campaignEl ? campaignEl.value : '';
            metaPage = 1;
            renderMetaAds();
        };
        var clrBtn = document.getElementById('metaClearBtn');
        if (clrBtn) clrBtn.onclick = function () { metaDateFrom = ''; metaDateTo = ''; metaCampaign = ''; metaProject = ''; metaPage = 1; renderMetaAds(); };

        document.getElementById('metaUploadBtn').onclick = function () { showMetaUploadModal(); };

        // Project tab clicks
        root.querySelectorAll('.meta-proj-tab').forEach(function (tab) {
            tab.onclick = function () {
                metaProject = tab.getAttribute('data-proj');
                metaPage = 1;
                renderMetaAds();
            };
        });

        // Date tile clicks — uploaded: filter to that date+project, missing: prompt upload
        root.querySelectorAll('.meta-day').forEach(function (tile) {
            tile.style.cursor = 'pointer';
            tile.onclick = function () {
                var d = tile.getAttribute('data-date');
                var p = tile.getAttribute('data-proj') || '';
                if (tile.classList.contains('meta-day-ok')) {
                    metaProject = p;
                    metaDateFrom = d;
                    metaDateTo = d;
                    metaPage = 1;
                    renderMetaAds();
                } else {
                    showMetaUploadModal(p);
                }
            };
        });

        // Pagination events
        root.querySelectorAll('.meta-page-btn').forEach(function (btn) {
            btn.onclick = function () {
                var pg = parseInt(btn.getAttribute('data-page'), 10);
                if (pg >= 1 && pg <= totalPages && pg !== metaPage) {
                    metaPage = pg;
                    renderMetaAds();
                }
            };
        });
        var perPageSel = document.getElementById('metaPerPageSel');
        if (perPageSel) perPageSel.onchange = function () {
            metaPerPage = parseInt(perPageSel.value, 10);
            metaPage = 1;
            renderMetaAds();
        };

    } catch (err) {
        console.error('Meta Ads load failed', err);
        root.innerHTML = '<div class="meta-wrap"><div class="meta-header"><h2 class="meta-title">Meta Ads Reports</h2></div><div class="meta-empty">Unable to load data.</div></div>';
    }
}

function showMetaUploadModal(preselect) {
    if (IS_ANIRUDH_VIEW) preselect = 'hima';
    var projOptions = IS_ANIRUDH_VIEW
        ? '<option value="hima" selected>Hima</option>'
        : ('<option value="">-- Select Project --</option>' +
            '<option value="hima"' + (preselect === 'hima' ? ' selected' : '') + '>Hima</option>' +
            '<option value="sudar"' + (preselect === 'sudar' ? ' selected' : '') + '>Sudar</option>' +
            '<option value="thedal"' + (preselect === 'thedal' ? ' selected' : '') + '>Thedal</option>');
    // Anirudh is Hima-only — show a read-only label (not a dead, disabled dropdown);
    // the hidden input keeps metaProjSelect.value === 'hima' for the submit handler.
    var projectFieldHtml = IS_ANIRUDH_VIEW
        ? '<div class="inv-field"><label>Project</label><div class="meta-proj-locked">Hima</div><input type="hidden" id="metaProjSelect" value="hima"></div>'
        : '<div class="inv-field"><label>Project</label><select class="meta-proj-select" id="metaProjSelect">' + projOptions + '</select></div>';
    var reportTypeFieldHtml = IS_ANIRUDH_VIEW
        ? '<input type="hidden" name="metaReportType" value="region">'
        : '<div class="inv-field"><label>Report Type</label>' +
            '<div class="inv-toggle-group">' +
            '<label class="inv-toggle-opt"><input type="radio" name="metaReportType" value="campaign" checked> Campaign</label>' +
            '<label class="inv-toggle-opt"><input type="radio" name="metaReportType" value="region"> Region</label>' +
            '</div></div>';

    var metaSvgIcon = '<svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" opacity="0.4"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>';
    function metaDropzoneField(zoneId, inputId, previewId, title, hint) {
        return '<div class="inv-field">' +
            '<div class="inv-dropzone" id="' + zoneId + '">' + metaSvgIcon +
            (title ? '<div style="font-size:15px;font-weight:700;color:#f4f4f5;margin-bottom:3px">' + title + '</div>' : '') +
            '<div>Drop CSV here or <span style="color:#60a5fa;text-decoration:underline">click to browse</span></div>' +
            '<div style="font-size:11px;color:#52525b;margin-top:4px">' + hint + '</div>' +
            '<input type="file" id="' + inputId + '" accept=".csv,.txt" style="display:none"></div>' +
            '<div class="inv-file-preview" id="' + previewId + '"></div></div>';
    }
    // Anirudh uploads three Meta ad accounts (OG / Test / Zocket), each a fixed
    // filename; everyone else keeps the generic one + optional second file.
    var metaDropzonesHtml = IS_ANIRUDH_VIEW
        ? (metaDropzoneField('metaDropzone', 'metaFile', 'metaFilePreview', 'OG Account', 'Meta-OG-language-wise-spends.csv — CSV, max 20MB') +
            metaDropzoneField('metaDropzone2', 'metaFile2', 'metaFilePreview2', 'Test Account', 'Meta-Test-language-wise-spends.csv — CSV, max 20MB') +
            metaDropzoneField('metaDropzone3', 'metaFile3', 'metaFilePreview3', 'Zocket Account', 'Meta-Zocket-language-wise-spends.csv — CSV, max 20MB'))
        : ('<div class="inv-field"><label>CSV File (exported from Meta Ads Manager)</label>' +
            '<div class="inv-dropzone" id="metaDropzone">' + metaSvgIcon +
            '<div>Drop CSV here or <span style="color:#60a5fa;text-decoration:underline">click to browse</span></div>' +
            '<div style="font-size:11px;color:#52525b;margin-top:4px">CSV or TXT — max 20MB</div>' +
            '<input type="file" id="metaFile" accept=".csv,.txt" style="display:none"></div>' +
            '<div class="inv-file-preview" id="metaFilePreview"></div></div>' +
            '<div class="inv-field"><label>Second file (optional) — summed with the first by matching date</label>' +
            '<div class="inv-dropzone" id="metaDropzone2">' + metaSvgIcon +
            '<div>Drop second CSV here or <span style="color:#60a5fa;text-decoration:underline">click to browse</span></div>' +
            '<div style="font-size:11px;color:#52525b;margin-top:4px">Optional — CSV or TXT — max 20MB</div>' +
            '<input type="file" id="metaFile2" accept=".csv,.txt" style="display:none"></div>' +
            '<div class="inv-file-preview" id="metaFilePreview2"></div></div>');

    var overlay = document.createElement('div');
    overlay.className = 'inv-modal-overlay';
    overlay.innerHTML = '<div class="inv-modal">' +
        '<div class="inv-modal-header"><h3>Upload Meta Ads CSV</h3><button type="button" class="inv-modal-close" id="metaModalClose">&times;</button></div>' +
        '<div class="inv-modal-body">' +
        reportTypeFieldHtml +
        projectFieldHtml +
        metaDropzonesHtml +
        '</div>' +
        '<div class="inv-modal-footer"><button type="button" class="btn btn-outline" id="metaModalCancel">Cancel</button><button type="button" class="btn btn-primary btn-lg" id="metaModalSubmit">Upload & Import</button></div>' +
        '</div>';
    document.body.appendChild(overlay);

    function showF(input, preview) {
        if (input.files[0]) {
            var f = input.files[0];
            var sz = f.size < 1048576 ? Math.round(f.size / 1024) + ' KB' : (f.size / 1048576).toFixed(1) + ' MB';
            preview.innerHTML = '<span>' + escapeHtml(f.name) + ' (' + sz + ')</span>';
        }
    }

    function fileMatches(name, expected) {
        var norm = function (s) { return String(s).toLowerCase().replace(/[^a-z0-9]/g, ''); };
        return norm(name).indexOf(norm(expected)) !== -1;
    }

    function wireDropzone(input, dropzone, preview, expectedName) {
        function accept(files) {
            if (!files || !files[0]) return;
            if (expectedName && !fileMatches(files[0].name, expectedName)) {
                alert('Wrong file for this box.\n\nThis box only accepts the file named:\n"' + expectedName + '"\n\nYou selected:\n"' + files[0].name + '"');
                try { input.value = ''; } catch (e) {}
                preview.innerHTML = '';
                return;
            }
            if (input.files !== files) input.files = files;
            showF(input, preview);
        }
        dropzone.onclick = function () { input.click(); };
        dropzone.ondragover = function (e) { e.preventDefault(); dropzone.classList.add('inv-dragover'); };
        dropzone.ondragleave = function () { dropzone.classList.remove('inv-dragover'); };
        dropzone.ondrop = function (e) { e.preventDefault(); dropzone.classList.remove('inv-dragover'); accept(e.dataTransfer.files); };
        input.onchange = function () { accept(input.files); };
    }

    var fileInput = document.getElementById('metaFile');
    var fileInput2 = document.getElementById('metaFile2');
    var fileInput3 = IS_ANIRUDH_VIEW ? document.getElementById('metaFile3') : null;
    if (IS_ANIRUDH_VIEW) {
        wireDropzone(fileInput, document.getElementById('metaDropzone'), document.getElementById('metaFilePreview'), 'Meta-OG-language-wise-spends');
        wireDropzone(fileInput2, document.getElementById('metaDropzone2'), document.getElementById('metaFilePreview2'), 'Meta-Test-language-wise-spends');
        wireDropzone(fileInput3, document.getElementById('metaDropzone3'), document.getElementById('metaFilePreview3'), 'Meta-Zocket-language-wise-spends');
    } else {
        wireDropzone(fileInput, document.getElementById('metaDropzone'), document.getElementById('metaFilePreview'), 'Ad-Set-wise-spends-for-Tessa');
        wireDropzone(fileInput2, document.getElementById('metaDropzone2'), document.getElementById('metaFilePreview2'), 'Ad-Set-wise-spends-for-Tessa');
    }

    function close() { overlay.remove(); }
    document.getElementById('metaModalClose').onclick = close;
    document.getElementById('metaModalCancel').onclick = close;
    overlay.onclick = function (e) { if (e.target === overlay) close(); };

    document.getElementById('metaModalSubmit').onclick = function () {
        var proj = document.getElementById('metaProjSelect').value;
        if (!proj) { alert('Please select a project.'); return; }
        var file = fileInput.files[0];
        var file2 = fileInput2.files[0];
        var file3 = fileInput3 ? fileInput3.files[0] : null;
        if (IS_ANIRUDH_VIEW) {
            if (!file || !file2 || !file3) { alert('Please select all three files (OG, Test and Zocket).'); return; }
        } else if (!file) {
            alert('Please select a CSV file.'); return;
        }

        // Anirudh's view has a hidden input (type="hidden" doesn't match :checked); fall back to that.
        var reportType = (document.querySelector('input[name="metaReportType"]:checked')
            || document.querySelector('input[name="metaReportType"][type="hidden"]')
            || {}).value || 'campaign';

        var btn = document.getElementById('metaModalSubmit');
        btn.disabled = true;
        btn.textContent = 'Uploading & importing...';

        var formData = new FormData();
        formData.append('action', 'upload');
        formData.append('project', proj);
        formData.append('file', file);
        if (file2) formData.append('file2', file2);
        if (file3) formData.append('file3', file3);
        if (reportType === 'region') formData.append('type', 'region');

        fetch('/api/meta-ad-reports', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: formData
        }).then(function (r) { return r.json().then(function (b) { return { ok: r.ok, body: b }; }); }).then(function (res) {
            if (res.ok && res.body.ok) {
                var msg;
                if (res.body.type === 'region') {
                    msg = formatRegionUploadResult(res.body);
                } else {
                    msg = 'Imported ' + res.body.inserted + ' rows.';
                    if (res.body.skipped > 0) msg += ' Skipped ' + res.body.skipped + ' duplicates.';
                    if (res.body.errors && res.body.errors.length > 0) msg += '\n\nWarnings:\n' + res.body.errors.join('\n');
                }
                alert(msg);
                close();
                renderMetaAds();
            } else {
                alert(res.body.error || res.body.message || 'Upload failed');
                btn.disabled = false;
                btn.textContent = 'Upload & Import';
            }
        }).catch(function () {
            alert('Upload failed. Please try again.');
            btn.disabled = false;
            btn.textContent = 'Upload & Import';
        });
    };
}

/* ── Google Ads Reports ── */
var gadsDateFrom = '';
var gadsDateTo = '';
var gadsCampaign = '';
var gadsProject = '';
var gadsPage = 1;
var gadsPerPage = 25;

async function renderGoogleAds() {
    var root = document.getElementById('google_adsView');
    if (!root) return;

    if (IS_ANIRUDH_VIEW && !gadsProject) gadsProject = 'hima';

    root.innerHTML = '<div class="meta-wrap"><div class="kpi-status-msg">Loading Google Ads data...</div></div>';

    try {
        var url = '/api/google-ad-reports';
        var params = [];
        if (gadsProject) params.push('project=' + encodeURIComponent(gadsProject));
        if (gadsDateFrom) params.push('from=' + encodeURIComponent(gadsDateFrom));
        if (gadsDateTo) params.push('to=' + encodeURIComponent(gadsDateTo));
        if (gadsCampaign) params.push('campaign=' + encodeURIComponent(gadsCampaign));
        if (params.length) url += '?' + params.join('&');

        var payload = await requestJson(url);
        var reports = payload.reports || [];
        var summary = payload.summary || {};

        // Pagination
        var totalRows = reports.length;
        var totalPages = Math.max(1, Math.ceil(totalRows / gadsPerPage));
        if (gadsPage > totalPages) gadsPage = totalPages;
        var startIdx = (gadsPage - 1) * gadsPerPage;
        var pageRows = reports.slice(startIdx, startIdx + gadsPerPage);

        // Project tabs
        var projects = payload.projects || {};
        var projectKeys = Object.keys(projects);
        if (IS_ANIRUDH_VIEW) {
            projectKeys = projectKeys.filter(function (k) { return k === 'hima'; });
        }
        var projectTabsHtml = '<div class="meta-project-tabs">';
        if (!IS_ANIRUDH_VIEW) {
            projectTabsHtml += '<button class="meta-proj-tab' + (!gadsProject ? ' meta-proj-active' : '') + '" data-proj="">All</button>';
        }
        projectKeys.forEach(function (k) {
            projectTabsHtml += '<button class="meta-proj-tab' + (gadsProject === k ? ' meta-proj-active' : '') + '" data-proj="' + k + '">' + escapeHtml(projects[k]) + '</button>';
        });
        projectTabsHtml += '</div>';

        // Date coverage tracker per project
        var coverage = payload.coverage || {};
        var coverageHtml = '<div class="meta-coverage">' +
            '<div class="meta-coverage-header">' +
            '<span class="meta-coverage-title">Upload Tracker <span class="meta-coverage-sub">(last 7 days)</span></span>' +
            '<span class="meta-coverage-legend">' +
            '<span class="meta-legend-item"><span class="meta-dot meta-dot-ok"></span> Uploaded</span>' +
            '<span class="meta-legend-item"><span class="meta-dot meta-dot-miss"></span> Missing</span>' +
            '</span></div>';

        projectKeys.forEach(function (proj) {
            var days = coverage[proj] || [];
            var upCount = days.filter(function (c) { return c.uploaded; }).length;
            coverageHtml += '<div class="meta-cov-project">' +
                '<div class="meta-cov-proj-label">' + escapeHtml(projects[proj]) +
                ' <span class="meta-cov-proj-stat">' + upCount + '/7</span></div>' +
                '<div class="meta-coverage-grid">';

            days.forEach(function (c) {
                var cls = c.uploaded ? 'meta-day-ok' : 'meta-day-miss';
                var dateObj = new Date(c.date + 'T00:00:00');
                var label = dateObj.toLocaleDateString('en-IN', { timeZone: 'Asia/Kolkata', day: '2-digit', month: 'short' });
                var tooltip = projects[proj] + ' — ' + c.date + ' (' + c.day + ')';
                if (c.uploaded) {
                    tooltip += ' — ' + c.rows + ' rows, ' + fmtINR(c.spend) + ' INR';
                } else {
                    tooltip += ' — Not uploaded';
                }
                coverageHtml += '<div class="meta-day ' + cls + '" title="' + escapeHtml(tooltip) + '" data-date="' + c.date + '" data-proj="' + proj + '">' +
                    '<div class="meta-day-label">' + c.day.substring(0, 2) + '</div>' +
                    '<div class="meta-day-date">' + label + '</div>' +
                    (c.uploaded ? '<div class="meta-day-spend">' + fmtINR(c.spend) + '</div>' : '<div class="meta-day-miss-icon">!</div>') +
                    '</div>';
            });

            coverageHtml += '</div></div>';
        });

        coverageHtml += '</div>';

        // Summary cards
        var summaryHtml = '<div class="meta-summary">' +
            '<div class="meta-stat"><div class="meta-stat-val">' + fmtINR(summary.total_spend) + '</div><div class="meta-stat-lbl">Total Spend (INR)</div></div>' +
            '<div class="meta-stat"><div class="meta-stat-val">' + fmtINR(summary.total_purchases) + '</div><div class="meta-stat-lbl">Purchases</div></div>' +
            '<div class="meta-stat"><div class="meta-stat-val">' + fmtINR(summary.total_purchase_value) + '</div><div class="meta-stat-lbl">Purchase Value (INR)</div></div>' +
            '</div>';

        // Filters
        var filterHtml = '<div class="meta-filters">' +
            '<label class="meta-date-label" for="gadsDateFrom">From</label>' +
            '<input type="date" class="meta-date-input" id="gadsDateFrom" value="' + escapeHtml(gadsDateFrom) + '">' +
            '<label class="meta-date-label" for="gadsDateTo">To</label>' +
            '<input type="date" class="meta-date-input" id="gadsDateTo" value="' + escapeHtml(gadsDateTo) + '">' +
            (IS_ANIRUDH_VIEW ? '' : '<input type="text" class="meta-campaign-input" id="gadsCampaignFilter" placeholder="Search campaign..." value="' + escapeHtml(gadsCampaign) + '">') +
            '<button type="button" class="meta-filter-btn" id="gadsFilterBtn">Filter</button>' +
            (gadsDateFrom || gadsDateTo || gadsCampaign ? '<button type="button" class="meta-clear-btn" id="gadsClearBtn">Clear</button>' : '') +
            '<button type="button" class="btn btn-primary" id="gadsUploadBtn">+ Upload CSV</button>' +
            '</div>';

        // Table — Anirudh sees a region-spend table; everyone else sees campaign rows.
        var tableHtml = '';
        if (IS_ANIRUDH_VIEW) {
            tableHtml = renderRegionTable(payload.region_uploads || [], 'Google');
        } else if (reports.length === 0) {
            tableHtml = '<div class="meta-empty">No Google Ads data found. Upload a CSV export from Google Ads.</div>';
        } else {
            tableHtml = '<div class="meta-table-wrap"><table class="meta-table">' +
                '<thead><tr>' +
                '<th>Date</th>' +
                '<th>Campaign</th>' +
                '<th class="meta-num">Cost</th>' +
                '<th class="meta-num">Avg CPC</th>' +
                '<th class="meta-num">CTR%</th>' +
                '<th class="meta-num">CPI</th>' +
                '<th class="meta-num">CPR</th>' +
                '<th class="meta-num">CPFTD</th>' +
                '<th class="meta-num">CP D1MP</th>' +
                '<th class="meta-num">Purchases</th>' +
                '<th class="meta-num">CPP</th>' +
                '<th class="meta-num">Purch. Value</th>' +
                '</tr></thead><tbody>';

            pageRows.forEach(function (r) {
                tableHtml += '<tr>' +
                    '<td class="meta-date-cell">' + escapeHtml(r.reporting_date) + '</td>' +
                    '<td class="meta-text-cell" title="' + escapeHtml(r.campaign_name) + '">' + escapeHtml(r.campaign_name.length > 50 ? r.campaign_name.substring(0, 50) + '...' : r.campaign_name) + '</td>' +
                    '<td class="meta-num">' + fmtINR(r.cost) + '</td>' +
                    '<td class="meta-num">' + (r.avg_cpc != null ? fmtINR(r.avg_cpc) : '—') + '</td>' +
                    '<td class="meta-num">' + (r.ctr != null ? r.ctr + '%' : '—') + '</td>' +
                    '<td class="meta-num">' + (r.cpi != null ? fmtINR(r.cpi) : '—') + '</td>' +
                    '<td class="meta-num">' + (r.cpr != null ? fmtINR(r.cpr) : '—') + '</td>' +
                    '<td class="meta-num">' + (r.cpftd != null ? fmtINR(r.cpftd) : '—') + '</td>' +
                    '<td class="meta-num">' + (r.cp_d1mp != null ? fmtINR(r.cp_d1mp) : '—') + '</td>' +
                    '<td class="meta-num">' + (r.purchases || 0) + '</td>' +
                    '<td class="meta-num">' + (r.cpp != null ? fmtINR(r.cpp) : '—') + '</td>' +
                    '<td class="meta-num">' + fmtINR(r.purchase_value) + '</td>' +
                    '</tr>';
            });

            tableHtml += '</tbody></table></div>';
        }

        // Pagination bar — skipped for Anirudh.
        var paginationHtml = '';
        if (!IS_ANIRUDH_VIEW && totalRows > gadsPerPage) {
            var showFrom = startIdx + 1;
            var showTo = Math.min(startIdx + gadsPerPage, totalRows);
            paginationHtml = '<div class="meta-pagination">' +
                '<span class="meta-page-info">Showing ' + showFrom + '–' + showTo + ' of ' + totalRows + '</span>' +
                '<div class="meta-page-btns">';

            paginationHtml += '<button class="meta-page-btn" data-page="1"' + (gadsPage <= 1 ? ' disabled' : '') + '>&laquo;</button>';
            paginationHtml += '<button class="meta-page-btn" data-page="' + (gadsPage - 1) + '"' + (gadsPage <= 1 ? ' disabled' : '') + '>&lsaquo; Prev</button>';

            var startP = Math.max(1, gadsPage - 2);
            var endP = Math.min(totalPages, gadsPage + 2);
            if (startP > 1) paginationHtml += '<span class="meta-page-dots">...</span>';
            for (var p = startP; p <= endP; p++) {
                paginationHtml += '<button class="meta-page-btn' + (p === gadsPage ? ' meta-page-active' : '') + '" data-page="' + p + '">' + p + '</button>';
            }
            if (endP < totalPages) paginationHtml += '<span class="meta-page-dots">...</span>';

            paginationHtml += '<button class="meta-page-btn" data-page="' + (gadsPage + 1) + '"' + (gadsPage >= totalPages ? ' disabled' : '') + '>Next &rsaquo;</button>';
            paginationHtml += '<button class="meta-page-btn" data-page="' + totalPages + '"' + (gadsPage >= totalPages ? ' disabled' : '') + '>&raquo;</button>';

            paginationHtml += '<select class="meta-perpage-select" id="gadsPerPageSel">';
            [25, 50, 100].forEach(function (n) {
                paginationHtml += '<option value="' + n + '"' + (gadsPerPage === n ? ' selected' : '') + '>' + n + '/page</option>';
            });
            paginationHtml += '</select>';

            paginationHtml += '</div></div>';
        }

        var headerCount = IS_ANIRUDH_VIEW ? '' : '<span class="meta-count">' + totalRows + ' rows</span>';
        root.innerHTML = '<div class="meta-wrap">' +
            '<div class="meta-header"><h2 class="meta-title">Google Ads Reports</h2>' + headerCount + '</div>' +
            projectTabsHtml + coverageHtml + summaryHtml + filterHtml + tableHtml + paginationHtml + '</div>';

        // Bind events
        var fromInput = document.getElementById('gadsDateFrom');
        var toInput = document.getElementById('gadsDateTo');
        fromInput.onclick = function () { if (fromInput.showPicker) fromInput.showPicker(); };
        toInput.onclick = function () { if (toInput.showPicker) toInput.showPicker(); };

        document.getElementById('gadsFilterBtn').onclick = function () {
            gadsDateFrom = document.getElementById('gadsDateFrom').value;
            gadsDateTo = document.getElementById('gadsDateTo').value;
            var campaignEl = document.getElementById('gadsCampaignFilter');
            gadsCampaign = campaignEl ? campaignEl.value : '';
            gadsPage = 1;
            renderGoogleAds();
        };
        var clrBtn = document.getElementById('gadsClearBtn');
        if (clrBtn) clrBtn.onclick = function () { gadsDateFrom = ''; gadsDateTo = ''; gadsCampaign = ''; gadsProject = ''; gadsPage = 1; renderGoogleAds(); };

        document.getElementById('gadsUploadBtn').onclick = function () { showGoogleAdsUploadModal(); };

        // Project tab clicks
        root.querySelectorAll('.meta-proj-tab').forEach(function (tab) {
            tab.onclick = function () {
                gadsProject = tab.getAttribute('data-proj');
                gadsPage = 1;
                renderGoogleAds();
            };
        });

        // Date tile clicks
        root.querySelectorAll('.meta-day').forEach(function (tile) {
            tile.style.cursor = 'pointer';
            tile.onclick = function () {
                var d = tile.getAttribute('data-date');
                var p = tile.getAttribute('data-proj') || '';
                if (tile.classList.contains('meta-day-ok')) {
                    gadsProject = p;
                    gadsDateFrom = d;
                    gadsDateTo = d;
                    gadsPage = 1;
                    renderGoogleAds();
                } else {
                    showGoogleAdsUploadModal(p);
                }
            };
        });

        // Pagination events
        root.querySelectorAll('.meta-page-btn').forEach(function (btn) {
            btn.onclick = function () {
                var pg = parseInt(btn.getAttribute('data-page'), 10);
                if (pg >= 1 && pg <= totalPages && pg !== gadsPage) {
                    gadsPage = pg;
                    renderGoogleAds();
                }
            };
        });
        var perPageSel = document.getElementById('gadsPerPageSel');
        if (perPageSel) perPageSel.onchange = function () {
            gadsPerPage = parseInt(perPageSel.value, 10);
            gadsPage = 1;
            renderGoogleAds();
        };

    } catch (err) {
        console.error('Google Ads load failed', err);
        root.innerHTML = '<div class="meta-wrap"><div class="meta-header"><h2 class="meta-title">Google Ads Reports</h2></div><div class="meta-empty">Unable to load data.</div></div>';
    }
}

function showGoogleAdsUploadModal(preselect) {
    if (IS_ANIRUDH_VIEW) preselect = 'hima';
    var projOptions = IS_ANIRUDH_VIEW
        ? '<option value="hima" selected>Hima</option>'
        : ('<option value="">-- Select Project --</option>' +
            '<option value="hima"' + (preselect === 'hima' ? ' selected' : '') + '>Hima</option>' +
            '<option value="sudar"' + (preselect === 'sudar' ? ' selected' : '') + '>Sudar</option>' +
            '<option value="thedal"' + (preselect === 'thedal' ? ' selected' : '') + '>Thedal</option>');
    // Anirudh is Hima-only — read-only label instead of a disabled dropdown.
    var projectFieldHtml = IS_ANIRUDH_VIEW
        ? '<div class="inv-field"><label>Project</label><div class="meta-proj-locked">Hima</div><input type="hidden" id="gadsProjSelect" value="hima"></div>'
        : '<div class="inv-field"><label>Project</label><select class="meta-proj-select" id="gadsProjSelect">' + projOptions + '</select></div>';
    var reportTypeFieldHtml = IS_ANIRUDH_VIEW
        ? '<input type="hidden" name="gadsReportType" value="region">'
        : '<div class="inv-field"><label>Report Type</label>' +
            '<div class="inv-toggle-group">' +
            '<label class="inv-toggle-opt"><input type="radio" name="gadsReportType" value="campaign" checked> Campaign</label>' +
            '<label class="inv-toggle-opt"><input type="radio" name="gadsReportType" value="region"> Region</label>' +
            '</div></div>';
    var overlay = document.createElement('div');
    overlay.className = 'inv-modal-overlay';
    overlay.innerHTML = '<div class="inv-modal">' +
        '<div class="inv-modal-header"><h3>Upload Google Ads CSV</h3><button type="button" class="inv-modal-close" id="gadsModalClose">&times;</button></div>' +
        '<div class="inv-modal-body">' +
        reportTypeFieldHtml +
        projectFieldHtml +
        '<div class="inv-field"><label>Exported from Google Ads</label>' +
        '<div class="inv-dropzone" id="gadsDropzone">' +
        '<svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" opacity="0.4"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>' +
        '<div style="font-size:15px;font-weight:700;color:#f4f4f5;margin-bottom:3px">Hima App 1st Account</div>' +
        '<div>Drop CSV here or <span style="color:#60a5fa;text-decoration:underline">click to browse</span></div>' +
        '<div style="font-size:11px;color:#52525b;margin-top:4px">CSV or TXT — max 20MB</div>' +
        '<input type="file" id="gadsFile" accept=".csv,.txt" style="display:none"></div>' +
        '<div class="inv-file-preview" id="gadsFilePreview"></div></div>' +
        '<div class="inv-field"><label>Optional — summed with the first by matching date</label>' +
        '<div class="inv-dropzone" id="gadsDropzone2">' +
        '<svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" opacity="0.4"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>' +
        '<div style="font-size:15px;font-weight:700;color:#f4f4f5;margin-bottom:3px">Hima Creator Account</div>' +
        '<div>Drop second CSV here or <span style="color:#60a5fa;text-decoration:underline">click to browse</span></div>' +
        '<div style="font-size:11px;color:#52525b;margin-top:4px">Optional — CSV or TXT — max 20MB</div>' +
        '<input type="file" id="gadsFile2" accept=".csv,.txt" style="display:none"></div>' +
        '<div class="inv-file-preview" id="gadsFilePreview2"></div></div>' +
        '</div>' +
        '<div class="inv-modal-footer"><button type="button" class="btn btn-outline" id="gadsModalCancel">Cancel</button><button type="button" class="btn btn-primary btn-lg" id="gadsModalSubmit">Upload & Import</button></div>' +
        '</div>';
    document.body.appendChild(overlay);

    function showF(input, preview) {
        if (input.files[0]) {
            var f = input.files[0];
            var sz = f.size < 1048576 ? Math.round(f.size / 1024) + ' KB' : (f.size / 1048576).toFixed(1) + ' MB';
            preview.innerHTML = '<span>' + escapeHtml(f.name) + ' (' + sz + ')</span>';
        }
    }

    function fileMatches(name, expected) {
        var norm = function (s) { return String(s).toLowerCase().replace(/[^a-z0-9]/g, ''); };
        return norm(name).indexOf(norm(expected)) !== -1;
    }

    function wireDropzone(input, dropzone, preview, expectedName) {
        function accept(files) {
            if (!files || !files[0]) return;
            if (expectedName && !fileMatches(files[0].name, expectedName)) {
                alert('Wrong file for this box.\n\nThis box only accepts the file named:\n"' + expectedName + '"\n\nYou selected:\n"' + files[0].name + '"');
                try { input.value = ''; } catch (e) {}
                preview.innerHTML = '';
                return;
            }
            if (input.files !== files) input.files = files;
            showF(input, preview);
        }
        dropzone.onclick = function () { input.click(); };
        dropzone.ondragover = function (e) { e.preventDefault(); dropzone.classList.add('inv-dragover'); };
        dropzone.ondragleave = function () { dropzone.classList.remove('inv-dragover'); };
        dropzone.ondrop = function (e) { e.preventDefault(); dropzone.classList.remove('inv-dragover'); accept(e.dataTransfer.files); };
        input.onchange = function () { accept(input.files); };
    }

    var fileInput = document.getElementById('gadsFile');
    var fileInput2 = document.getElementById('gadsFile2');
    // Match only the stable account prefix so name variants (Region Wise / Ad group
    // Wise) and "(1)" copy suffixes all pass; wrong-box files are still rejected.
    wireDropzone(fileInput, document.getElementById('gadsDropzone'), document.getElementById('gadsFilePreview'), 'Ac#1 Google ads User Acquisition');
    wireDropzone(fileInput2, document.getElementById('gadsDropzone2'), document.getElementById('gadsFilePreview2'), 'Ac#2 Google ads Creator Acquisition');

    function close() { overlay.remove(); }
    document.getElementById('gadsModalClose').onclick = close;
    document.getElementById('gadsModalCancel').onclick = close;
    overlay.onclick = function (e) { if (e.target === overlay) close(); };

    document.getElementById('gadsModalSubmit').onclick = function () {
        var proj = document.getElementById('gadsProjSelect').value;
        if (!proj) { alert('Please select a project.'); return; }
        var file = fileInput.files[0];
        if (!file) { alert('Please select a CSV file.'); return; }
        var file2 = fileInput2.files[0];

        var reportType = (document.querySelector('input[name="gadsReportType"]:checked')
            || document.querySelector('input[name="gadsReportType"][type="hidden"]')
            || {}).value || 'campaign';

        var btn = document.getElementById('gadsModalSubmit');
        btn.disabled = true;
        btn.textContent = 'Uploading & importing...';

        var formData = new FormData();
        formData.append('action', 'upload');
        formData.append('project', proj);
        formData.append('file', file);
        if (file2) formData.append('file2', file2);
        if (reportType === 'region') formData.append('type', 'region');

        fetch('/api/google-ad-reports', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: formData
        }).then(function (r) { return r.json().then(function (b) { return { ok: r.ok, body: b }; }); }).then(function (res) {
            if (res.ok && res.body.ok) {
                var msg;
                if (res.body.type === 'region') {
                    msg = formatRegionUploadResult(res.body);
                } else {
                    msg = 'Imported ' + res.body.inserted + ' rows.';
                    if (res.body.skipped > 0) msg += ' Skipped ' + res.body.skipped + ' duplicates.';
                    if (res.body.errors && res.body.errors.length > 0) msg += '\n\nWarnings:\n' + res.body.errors.join('\n');
                }
                alert(msg);
                close();
                renderGoogleAds();
            } else {
                alert(res.body.error || res.body.message || 'Upload failed');
                btn.disabled = false;
                btn.textContent = 'Upload & Import';
            }
        }).catch(function () {
            alert('Upload failed. Please try again.');
            btn.disabled = false;
            btn.textContent = 'Upload & Import';
        });
    };
}

    window.MarketingModule = {
        renderMetaAds: renderMetaAds,
        renderGoogleAds: renderGoogleAds,
        showMetaUploadModal: showMetaUploadModal,
        showGoogleAdsUploadModal: showGoogleAdsUploadModal,
        fmtINR: fmtINR
    };
})();
