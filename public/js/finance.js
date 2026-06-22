(function () {
    'use strict';

    var config = window.__PORTAL_CONFIG || {};
    if (config.layout === 'simple') return;

    function escapeHtml(v) { return MeetingModule.escapeHtml(v); }
    function requestJson(url, options) { return MeetingModule.requestJson(url, options); }

    function fmtINR(n) {
        if (n == null) return '—';
        return Number(n).toLocaleString('en-IN', { maximumFractionDigits: 2 });
    }

    function formatAmountWithCurrency(amount, currency) {
        var n = parseFloat(amount);
        if (isNaN(n)) return '—';
        var ccy = (currency || 'INR').toUpperCase();
        var symbols = { INR: '₹', USD: '$', EUR: '€', GBP: '£', AED: 'AED ', SGD: 'S$', AUD: 'A$', CAD: 'C$', JPY: '¥' };
        var locale = (ccy === 'INR') ? 'en-IN' : 'en-US';
        var formatted = n.toLocaleString(locale, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        return (symbols[ccy] || (ccy + ' ')) + formatted;
    }

    /* ── Revenue Dashboard (live data from API) ── */

    async function renderRevenue() {
        var root = document.getElementById('revenueView');
        if (!root) return;

        root.innerHTML = '<div class="rev-wrap"><div class="kpi-status-msg">Loading revenue data...</div></div>';

        var rows = [];
        try {
            var payload = await requestJson('/api/revenue/daily-payout?from=2026-03-31');
            rows = payload.rows || [];
        } catch (e) {
            root.innerHTML = '<div class="rev-wrap"><div class="kpi-status-msg" style="color:#c0392b;">Failed to load revenue data. Please try again.</div></div>';
            return;
        }

        if (!rows.length) {
            root.innerHTML = '<div class="rev-wrap"><div class="kpi-status-msg">No revenue data available from April 2026 onwards.</div></div>';
            return;
        }

        // Map DB columns to display fields
        var daily = rows.map(function (r) {
            var dateStr = (r.date || '').substring(0, 10);
            var d = new Date(dateStr + 'T00:00:00');
            var grossRevenue = Number(r.revenue || 0);
            var revenueGst = grossRevenue * 18 / 118;       // GST included in revenue
            var netRevenue = grossRevenue - revenueGst;
            var googleSpend = Number(r.google_spend || 0);
            var metaSpendBase = Number(r.meta_spend || 0);
            var metaGst = metaSpendBase * 0.18;              // 18% GST on Meta
            var metaSpendTotal = metaSpendBase + metaGst;
            var totalAdsSpend = googleSpend + metaSpendTotal;
            var creatorPayout = Number(r.payout_paid || 0);
            var agoraCost = Number(r.agora_cost_inr || 0);
            var profit = netRevenue - totalAdsSpend - creatorPayout - agoraCost;
            return {
                date: dateStr,
                dayLabel: d.toLocaleDateString('en-IN', { timeZone: 'Asia/Kolkata', day: '2-digit', month: 'short', year: 'numeric' }),
                grossRevenue: grossRevenue,
                revenueGst: revenueGst,
                netRevenue: netRevenue,
                googleSpend: googleSpend,
                metaSpendBase: metaSpendBase,
                metaGst: metaGst,
                metaSpendTotal: metaSpendTotal,
                totalAdsSpend: totalAdsSpend,
                creatorPayout: creatorPayout,
                agoraCost: agoraCost,
                profit: profit
            };
        });

        // Sort ascending by date
        daily.sort(function (a, b) { return a.date < b.date ? -1 : a.date > b.date ? 1 : 0; });

        function fmt(n) { return '₹' + Number(Math.round(n)).toLocaleString('en-IN'); }
        function num(n) { return Number(n).toLocaleString('en-IN'); }
        function cls(n) { return n >= 0 ? 'rev-positive' : 'rev-negative'; }

        // Group by week (Mon–Sun)
        var weeks = [];
        var currentWeek = null;
        daily.forEach(function (r) {
            var d = new Date(r.date + 'T00:00:00');
            var day = d.getDay();
            var mon = new Date(d);
            mon.setDate(mon.getDate() - ((day + 6) % 7));
            var weekKey = mon.toISOString().split('T')[0];
            if (!currentWeek || currentWeek.key !== weekKey) {
                currentWeek = {
                    key: weekKey,
                    label: mon.toLocaleDateString('en-IN', { timeZone: 'Asia/Kolkata', day: '2-digit', month: 'short' }),
                    grossRevenue: 0, revenueGst: 0, netRevenue: 0, googleSpend: 0, metaSpendTotal: 0, metaGst: 0, totalAdsSpend: 0, creatorPayout: 0, agoraCost: 0, profit: 0, days: 0
                };
                weeks.push(currentWeek);
            }
            currentWeek.grossRevenue += r.grossRevenue;
            currentWeek.revenueGst += r.revenueGst;
            currentWeek.netRevenue += r.netRevenue;
            currentWeek.googleSpend += r.googleSpend;
            currentWeek.metaSpendTotal += r.metaSpendTotal;
            currentWeek.metaGst += r.metaGst;
            currentWeek.totalAdsSpend += r.totalAdsSpend;
            currentWeek.creatorPayout += r.creatorPayout;
            currentWeek.agoraCost += r.agoraCost;
            currentWeek.profit += r.profit;
            currentWeek.days++;
        });

        // View toggle state
        var viewMode = 'daily';

        function renderView() {
            var totalDays = daily.length;
            var html = '<div class="rev-wrap">';

            html += '<div class="rev-header"><h2 class="rev-title">Revenue & Payout Dashboard</h2><span class="rev-subtitle">March 31 2026 onwards &middot; ' + totalDays + ' days</span></div>';

            // Toggle buttons
            html += '<div class="rev-toggle">';
            html += '<button class="btn btn-sm rev-toggle-btn' + (viewMode === 'daily' ? ' rev-toggle-btn--active' : '') + '" data-view="daily">Daily</button>';
            html += '<button class="btn btn-sm rev-toggle-btn' + (viewMode === 'weekly' ? ' rev-toggle-btn--active' : '') + '" data-view="weekly">Weekly</button>';
            html += '</div>';

            var data = viewMode === 'weekly' ? weeks : daily;
            var dateHeader = viewMode === 'weekly' ? 'Week Of' : 'Date';

            // Table
            html += '<div class="rev-table-wrap"><table class="rev-table">';
            html += '<thead><tr><th>' + dateHeader + '</th><th class="th-revenue">Gross Revenue</th><th class="th-gst">GST (18%)</th><th class="th-revenue">Net Revenue</th><th class="th-spend">Google Spend</th><th class="th-spend">Meta + GST</th><th class="th-spend">Total Ads</th><th class="th-payout">Creator Payout</th><th class="th-agora">Agora Cost</th><th class="th-profit">Profit</th></tr></thead>';
            html += '<tbody>';
            for (var j = data.length - 1; j >= 0; j--) {
                var row = data[j];
                var label = viewMode === 'weekly' ? row.label + ' (' + row.days + 'd)' : row.dayLabel;
                html += '<tr>';
                html += '<td>' + label + '</td>';
                html += '<td class="td-revenue">' + fmt(row.grossRevenue) + '</td>';
                html += '<td class="td-gst">' + fmt(row.revenueGst) + '</td>';
                html += '<td class="td-revenue">' + fmt(row.netRevenue) + '</td>';
                html += '<td class="td-spend">' + fmt(row.googleSpend) + '</td>';
                html += '<td class="td-spend">' + fmt(row.metaSpendTotal) + '</td>';
                html += '<td class="td-spend">' + fmt(row.totalAdsSpend) + '</td>';
                html += '<td class="td-payout">' + fmt(row.creatorPayout) + '</td>';
                html += '<td class="td-agora">' + fmt(row.agoraCost) + '</td>';
                html += '<td class="' + cls(row.profit) + '">' + fmt(row.profit) + '</td>';
                html += '</tr>';
            }
            html += '</tbody></table></div>';

            html += '</div>';
            root.innerHTML = html;

            // Bind toggle buttons
            root.querySelectorAll('.rev-toggle-btn').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    viewMode = btn.getAttribute('data-view');
                    renderView();
                });
            });
        }

        renderView();
    }

    /* ── Invoice Submissions ── */
    var invDateFrom = '';
    var invDateTo = '';
    var invSearchQuery = '';
    var invFilterVendor = '';
    var invFilterUploadedBy = '';
    var invSortBy = 'created_at';
    var invSortDir = 'desc';
    var invSearchDebounce = null;
    var invoiceTab = 'invoices';

    function fmtUploadDateTime(iso) {
        if (!iso) return '';
        try {
            var d = new Date(iso);
            return d.toLocaleString('en-IN', {
                timeZone: 'Asia/Kolkata',
                day: '2-digit', month: 'short', year: 'numeric',
                hour: '2-digit', minute: '2-digit', hour12: true
            });
        } catch (e) { return iso; }
    }

    var INVOICE_SORT_OPTIONS = [
        { value: 'created_at|desc',   label: 'Newest first' },
        { value: 'created_at|asc',    label: 'Oldest first' },
        { value: 'invoice_date|desc', label: 'Invoice date: newest → oldest' },
        { value: 'invoice_date|asc',  label: 'Invoice date: oldest → newest' },
        { value: 'vendor_name|asc',   label: 'Vendor: A → Z' },
        { value: 'vendor_name|desc',  label: 'Vendor: Z → A' },
        { value: 'amount|desc',       label: 'Amount: high → low' },
        { value: 'amount|asc',        label: 'Amount: low → high' }
    ];

    async function renderInvoices() {
        var root = document.getElementById('invoicesView');
        if (!root) return;

        root.innerHTML = '<div class="inv-wrap"><div class="kpi-status-msg">Loading invoices...</div></div>';

        try {
            var url = '/api/invoice-submissions';
            var params = [];
            if (invDateFrom) params.push('from=' + encodeURIComponent(invDateFrom));
            if (invDateTo) params.push('to=' + encodeURIComponent(invDateTo));
            if (invSearchQuery) params.push('search=' + encodeURIComponent(invSearchQuery));
            if (invFilterVendor) params.push('vendor=' + encodeURIComponent(invFilterVendor));
            if (invFilterUploadedBy) params.push('uploaded_by=' + encodeURIComponent(invFilterUploadedBy));
            if (invSortBy) params.push('sort_by=' + encodeURIComponent(invSortBy));
            if (invSortDir) params.push('sort_dir=' + encodeURIComponent(invSortDir));
            if (params.length) url += '?' + params.join('&');

            var payload = await requestJson(url);
            var submissions = payload.submissions || [];
            var isReviewer = payload.isReviewer === true;
            var canDownloadAll = payload.canDownloadAll === true;
            var vendors = payload.vendors || [];
            var uploaders = payload.uploaders || [];

            var title = isReviewer ? 'Invoice Collection' : 'My Invoices';
            var countText = submissions.length > 0 ? '<span class="inv-count">' + submissions.length + ' invoice' + (submissions.length > 1 ? 's' : '') + '</span>' : '';

            // Build datalist suggestions from vendors + service names + uploader names
            var suggestionSet = {};
            vendors.forEach(function (v) { if (v) suggestionSet[v] = true; });
            submissions.forEach(function (s) {
                if (s.service) suggestionSet[s.service] = true;
                if (s.invoiceNumber) suggestionSet[s.invoiceNumber] = true;
                if (s.userName) suggestionSet[s.userName] = true;
            });
            var suggestionsHtml = Object.keys(suggestionSet).sort().map(function (v) {
                return '<option value="' + escapeHtml(v) + '">';
            }).join('');

            var vendorOptionsHtml = '<option value="">All vendors</option>' + vendors.map(function (v) {
                var sel = (v === invFilterVendor) ? ' selected' : '';
                return '<option value="' + escapeHtml(v) + '"' + sel + '>' + escapeHtml(v) + '</option>';
            }).join('');

            var uploaderSelectHtml = '';
            if (isReviewer && uploaders.length) {
                var uploaderOptionsHtml = '<option value="">All uploaders</option>' + uploaders.map(function (u) {
                    var sel = (String(u.id) === String(invFilterUploadedBy)) ? ' selected' : '';
                    return '<option value="' + u.id + '"' + sel + '>' + escapeHtml(u.name) + '</option>';
                }).join('');
                uploaderSelectHtml = '<select class="inv-filter-select" id="invUploadedBy" title="Filter by uploader">' + uploaderOptionsHtml + '</select>';
            }

            var sortOptionsHtml = INVOICE_SORT_OPTIONS.map(function (opt) {
                var current = invSortBy + '|' + invSortDir;
                var sel = (opt.value === current) ? ' selected' : '';
                return '<option value="' + opt.value + '"' + sel + '>' + escapeHtml(opt.label) + '</option>';
            }).join('');

            var hasAnyFilter = !!(invSearchQuery || invDateFrom || invDateTo || invFilterVendor || invFilterUploadedBy);
            var clearBtnHtml = hasAnyFilter ? '<button type="button" class="inv-filter-clear-btn" id="invClearBtn">Clear</button>' : '';

            // Selection (reviewers only) — checkboxes per file row + a "Select all" bar above the list.
            var selectableCount = submissions.filter(function (s) { return !!s.filePath; }).length;
            var canSelect = canDownloadAll && selectableCount > 0;
            var selectAllHtml = canSelect
                ? '<div class="inv-select-all"><label><input type="checkbox" class="inv-check" id="invSelectAll"> Select all</label></div>'
                : '';

            // Filter bar — search, vendor, uploaded-by, datetime range, sort
            var filterHtml = '<div class="inv-filters">' +
                '<div class="inv-filter-row inv-filter-row-top">' +
                    '<input type="search" class="inv-search-input" id="invSearch" list="invSearchSuggest" placeholder="Search vendor, service, invoice number, uploader…" value="' + escapeHtml(invSearchQuery) + '" autocomplete="off">' +
                    '<datalist id="invSearchSuggest">' + suggestionsHtml + '</datalist>' +
                    '<button type="button" class="btn btn-primary inv-new-btn" id="invNewBtn">+ Submit Invoice</button>' +
                '</div>' +
                '<div class="inv-filter-row">' +
                    '<select class="inv-filter-select" id="invVendor" title="Filter by company name">' + vendorOptionsHtml + '</select>' +
                    uploaderSelectHtml +
                    '<label class="inv-date-label">From</label>' +
                    '<input type="datetime-local" class="inv-date-input" id="invDateFrom" value="' + escapeHtml(invDateFrom) + '">' +
                    '<label class="inv-date-label">To</label>' +
                    '<input type="datetime-local" class="inv-date-input" id="invDateTo" value="' + escapeHtml(invDateTo) + '">' +
                    '<label class="inv-date-label">Sort</label>' +
                    '<select class="inv-filter-select inv-sort-select" id="invSort">' + sortOptionsHtml + '</select>' +
                    '<button type="button" class="inv-filter-apply-btn" id="invFilterBtn">Apply</button>' +
                    clearBtnHtml +
                    (canSelect ? '<button type="button" class="inv-download-all-btn" id="invDownloadAllBtn" title="Download selected invoices (or all if none selected) as ZIP">Download All</button>' : '') +
                '</div>' +
                '</div>';

            var fileIcon = '<svg class="inv-file-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></svg>';

            // List
            var listHtml = '';
            if (submissions.length === 0) {
                listHtml = '<div class="inv-empty"><svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="opacity:0.3;margin-bottom:12px"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></svg><div>No invoices found</div><div style="font-size:12px;margin-top:4px">Try adjusting filters or upload a new invoice using the button above</div></div>';
            } else {
                listHtml = '<div class="inv-list">';
                listHtml += submissions.map(function (s) {
                    var dateStr = new Date(s.invoiceDate).toLocaleDateString('en-IN', { timeZone: 'Asia/Kolkata', day: '2-digit', month: 'short', year: 'numeric' });
                    var invNo = s.invoiceNumber || ('INV' + s.id);
                    var amountText = formatAmountWithCurrency(s.amount, s.currency);
                    // Format: Vendor Name — Invoice Date — Invoice Number — Amount with Currency
                    var displayName = escapeHtml(s.vendorName) + '  &mdash;  ' + escapeHtml(dateStr) + '  &mdash;  ' + escapeHtml(invNo) + '  &mdash;  ' + escapeHtml(amountText);
                    var fileHtml = s.filePath
                        ? '<a href="' + escapeHtml(s.filePath) + '" target="_blank" class="inv-file-link">' + fileIcon + '<span>' + displayName + '</span></a>'
                        : '<span class="inv-no-file">' + displayName + '</span>';
                    var metaParts = [];
                    metaParts.push('Uploaded ' + escapeHtml(fmtUploadDateTime(s.createdAt)));
                    if (isReviewer && s.userName) metaParts.push('by <strong>' + escapeHtml(s.userName) + '</strong>');
                    if (s.notes) metaParts.push(escapeHtml(s.notes));
                    var metaHtml = '<div class="inv-card-meta">' + metaParts.join(' &middot; ') + '</div>';

                    var selectHtml = (canDownloadAll && s.filePath)
                        ? '<input type="checkbox" class="inv-check inv-card-select" data-invoice-id="' + s.id + '" title="Select invoice">'
                        : '';

                    return '<div class="card inv-card">' +
                        '<div class="inv-card-header">' + selectHtml + fileHtml + '</div>' +
                        '<div class="inv-card-body">' + metaHtml + '</div>' +
                        '</div>';
                }).join('') + '</div>';
            }

            root.innerHTML = '<div class="inv-wrap">' +
                '<div class="inv-header"><h2 class="inv-title">' + title + '</h2>' + countText + '</div>' +
                filterHtml + selectAllHtml + listHtml + '</div>';

            // Bind events
            var searchInput = document.getElementById('invSearch');
            if (searchInput) {
                // Restore focus + cursor after re-render so typing isn't disrupted.
                if (window.__invSearchHadFocus) {
                    searchInput.focus();
                    var cur = window.__invSearchCursor;
                    if (cur != null) try { searchInput.setSelectionRange(cur, cur); } catch (e) {}
                    window.__invSearchHadFocus = false;
                    window.__invSearchCursor = null;
                }
                searchInput.addEventListener('input', function () {
                    if (invSearchDebounce) clearTimeout(invSearchDebounce);
                    invSearchDebounce = setTimeout(function () {
                        window.__invSearchHadFocus = (document.activeElement === searchInput);
                        window.__invSearchCursor = searchInput.selectionStart;
                        invSearchQuery = searchInput.value.trim();
                        renderInvoices();
                    }, 300);
                });
                // Apply immediately on Enter or datalist selection.
                searchInput.addEventListener('change', function () {
                    if (invSearchDebounce) clearTimeout(invSearchDebounce);
                    window.__invSearchHadFocus = (document.activeElement === searchInput);
                    window.__invSearchCursor = searchInput.selectionStart;
                    invSearchQuery = searchInput.value.trim();
                    renderInvoices();
                });
            }

            var vendorSel = document.getElementById('invVendor');
            if (vendorSel) vendorSel.onchange = function () { invFilterVendor = vendorSel.value; renderInvoices(); };

            var uploaderSel = document.getElementById('invUploadedBy');
            if (uploaderSel) uploaderSel.onchange = function () { invFilterUploadedBy = uploaderSel.value; renderInvoices(); };

            var sortSel = document.getElementById('invSort');
            if (sortSel) sortSel.onchange = function () {
                var parts = sortSel.value.split('|');
                invSortBy = parts[0] || 'created_at';
                invSortDir = parts[1] || 'desc';
                renderInvoices();
            };

            document.getElementById('invFilterBtn').onclick = function () {
                invDateFrom = document.getElementById('invDateFrom').value;
                invDateTo = document.getElementById('invDateTo').value;
                renderInvoices();
            };
            var clearBtn = document.getElementById('invClearBtn');
            if (clearBtn) clearBtn.onclick = function () {
                invDateFrom = ''; invDateTo = '';
                invSearchQuery = ''; invFilterVendor = ''; invFilterUploadedBy = '';
                renderInvoices();
            };

            var newBtn = document.getElementById('invNewBtn');
            if (newBtn) newBtn.onclick = function () { showInvoiceUploadModal(); };

            // Invoice selection + ZIP download (reviewers only).
            var dlAllBtn = document.getElementById('invDownloadAllBtn');

            function invAllSelectBoxes() {
                return Array.prototype.slice.call(document.querySelectorAll('.inv-card-select'));
            }
            function invCheckedIds() {
                return invAllSelectBoxes().filter(function (cb) { return cb.checked; })
                    .map(function (cb) { return cb.getAttribute('data-invoice-id'); });
            }
            function invUpdateSelectionUI() {
                var boxes = invAllSelectBoxes();
                var checked = invCheckedIds().length;
                var selAll = document.getElementById('invSelectAll');
                if (selAll) {
                    selAll.checked = (checked > 0 && checked === boxes.length);
                    selAll.indeterminate = (checked > 0 && checked < boxes.length);
                }
                if (dlAllBtn) dlAllBtn.textContent = checked > 0 ? ('Download (' + checked + ')') : 'Download All';
            }

            var selAllBox = document.getElementById('invSelectAll');
            if (selAllBox) selAllBox.onchange = function () {
                var on = selAllBox.checked;
                invAllSelectBoxes().forEach(function (cb) { cb.checked = on; });
                invUpdateSelectionUI();
            };
            invAllSelectBoxes().forEach(function (cb) { cb.addEventListener('change', invUpdateSelectionUI); });

            if (dlAllBtn) dlAllBtn.onclick = function () {
                // Download the checked rows, or all visible rows when nothing is checked.
                var ids = invCheckedIds();
                if (!ids.length) ids = invAllSelectBoxes().map(function (cb) { return cb.getAttribute('data-invoice-id'); });
                if (!ids.length) return;
                window.open('/api/invoice-submissions/download-all?ids=' + encodeURIComponent(ids.join(',')), '_blank');
            };

        } catch (err) {
            console.error('Invoices load failed', err);
            root.innerHTML = '<div class="inv-wrap"><div class="inv-header"><h2 class="inv-title">Invoices</h2></div><div class="inv-empty">Unable to load invoices.</div></div>';
        }
    }

    function showInvoiceUploadModal() {
        var overlay = document.createElement('div');
        overlay.className = 'modal-overlay inv-modal-overlay';
        overlay.innerHTML = '<div class="modal inv-modal">' +
            '<div class="inv-modal-header"><h3>Submit Invoices</h3><button type="button" class="inv-modal-close" id="invModalClose">&times;</button></div>' +
            '<div class="inv-modal-body">' +
            '<div class="inv-field"><label>Invoice Files (PDF, JPG, PNG, WEBP, or ZIP)</label>' +
            '<div class="inv-dropzone" id="invDropzone">' +
            '<svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" opacity="0.4"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>' +
            '<div>Drop one or more files here or <span style="color:#60a5fa;text-decoration:underline">click to browse</span></div>' +
            '<div style="font-size:11px;color:#52525b;margin-top:4px">PDF, JPG, PNG, WEBP — max 10MB each, up to 20 files per upload. For larger batches, upload a .ZIP (up to 50 invoices, 50MB total).</div>' +
            '<input type="file" id="invFile" accept=".pdf,.jpg,.jpeg,.png,.webp,.zip" multiple style="display:none"></div>' +
            '<div class="inv-file-preview" id="invFilePreview"></div></div>' +
            '<div class="inv-upload-progress" id="invUploadProgress"></div>' +
            '</div>' +
            '<div class="inv-modal-footer"><button type="button" class="btn btn-outline" id="invModalCancel">Cancel</button><button type="button" class="btn btn-primary btn-lg" id="invModalSubmit">Upload</button></div>' +
            '</div>';
        document.body.appendChild(overlay);

        var fileInput = document.getElementById('invFile');
        var dropzone = document.getElementById('invDropzone');
        var preview = document.getElementById('invFilePreview');
        var progress = document.getElementById('invUploadProgress');
        var selectedFiles = [];  // Accumulator across multiple drops/picks.

        function fmtSize(bytes) {
            return bytes < 1048576 ? Math.round(bytes / 1024) + ' KB' : (bytes / 1048576).toFixed(1) + ' MB';
        }

        function refreshPreview() {
            if (!selectedFiles.length) { preview.innerHTML = ''; return; }
            preview.innerHTML = selectedFiles.map(function (f, i) {
                var icon = (f.name.toLowerCase().endsWith('.zip')) ? '🗜️' : '📄';
                return '<div class="inv-file-row">' +
                    '<span>' + icon + ' ' + escapeHtml(f.name) + ' <span style="color:#71717a">(' + fmtSize(f.size) + ')</span></span>' +
                    '<button type="button" class="inv-file-remove" data-idx="' + i + '" title="Remove">&times;</button>' +
                    '</div>';
            }).join('');
            preview.querySelectorAll('.inv-file-remove').forEach(function (btn) {
                btn.onclick = function () {
                    var idx = parseInt(btn.getAttribute('data-idx'), 10);
                    selectedFiles.splice(idx, 1);
                    refreshPreview();
                };
            });
        }

        function addFiles(fileList) {
            if (!fileList) return;
            for (var i = 0; i < fileList.length; i++) {
                selectedFiles.push(fileList[i]);
            }
            refreshPreview();
        }

        dropzone.onclick = function (e) {
            // Don't re-open file dialog when removing chips inside the preview area.
            if (e.target.closest('.inv-file-remove')) return;
            fileInput.click();
        };
        dropzone.ondragover = function (e) { e.preventDefault(); dropzone.classList.add('inv-dragover'); };
        dropzone.ondragleave = function () { dropzone.classList.remove('inv-dragover'); };
        dropzone.ondrop = function (e) {
            e.preventDefault();
            dropzone.classList.remove('inv-dragover');
            if (e.dataTransfer.files && e.dataTransfer.files.length) addFiles(e.dataTransfer.files);
        };
        fileInput.onchange = function () { addFiles(fileInput.files); fileInput.value = ''; };

        function close() { overlay.remove(); }
        document.getElementById('invModalClose').onclick = close;
        document.getElementById('invModalCancel').onclick = close;
        overlay.onclick = function (e) { if (e.target === overlay) close(); };

        document.getElementById('invModalSubmit').onclick = function () {
            if (!selectedFiles.length) { alert('Please select at least one file.'); return; }
            // PHP-FPM allows max 20 file uploads per request — enforce on the client.
            var nonZipCount = selectedFiles.filter(function (f) { return !f.name.toLowerCase().endsWith('.zip'); }).length;
            if (nonZipCount > 20) {
                alert('Up to 20 individual files per upload. For larger batches, upload them as a single .ZIP file.');
                return;
            }

            var btn = document.getElementById('invModalSubmit');
            btn.disabled = true;
            var hasZip = selectedFiles.some(function (f) { return f.name.toLowerCase().endsWith('.zip'); });
            btn.textContent = hasZip || selectedFiles.length > 1 ? 'Uploading & extracting…' : 'Uploading…';
            progress.textContent = 'Processing ' + selectedFiles.length + ' file' + (selectedFiles.length > 1 ? 's' : '') + '. This can take a moment for invoices with AI extraction.';

            var formData = new FormData();
            formData.append('action', 'submit');
            selectedFiles.forEach(function (f) { formData.append('files[]', f); });

            fetch('/api/invoice-submissions', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: formData
            }).then(function (r) { return r.json().then(function (b) { return { ok: r.ok, body: b }; }); }).then(function (res) {
                if (res.ok && res.body.ok) {
                    var b = res.body;
                    var created = b.created != null ? b.created : (b.id ? 1 : 0);
                    var failed = b.failed || 0;
                    if (failed === 0) {
                        close();
                        renderInvoices();
                        return;
                    }
                    // Show per-file summary so the user knows which ones failed and why.
                    progress.innerHTML = '<div style="color:#4ade80;margin-bottom:6px">' + created + ' invoice' + (created !== 1 ? 's' : '') + ' created.</div>' +
                        '<div style="color:#fca5a5;margin-bottom:6px">' + failed + ' failed:</div>' +
                        '<ul style="margin:0;padding-left:20px;color:#a1a1aa;font-size:12px">' +
                            (b.errors || []).map(function (e) { return '<li>' + escapeHtml(e.name) + ' — ' + escapeHtml(e.error) + '</li>'; }).join('') +
                        '</ul>';
                    btn.textContent = 'Done';
                    btn.disabled = false;
                    btn.onclick = function () { close(); renderInvoices(); };
                } else {
                    var errMsg = (res.body && (res.body.error || res.body.message)) || '';
                    if (res.body && res.body.errors) {
                        var errs = res.body.errors;
                        if (Array.isArray(errs)) {
                            errMsg = errs.map(function (e) { return e.name + ': ' + e.error; }).join('\n');
                        } else {
                            errMsg = Object.keys(errs).map(function (k) { return errs[k].join(', '); }).join('\n');
                        }
                    }
                    alert(errMsg || 'Upload failed');
                    btn.disabled = false;
                    btn.textContent = 'Upload';
                    progress.textContent = '';
                }
            }).catch(function () {
                alert('Upload failed. Please try again.');
                btn.disabled = false;
                btn.textContent = 'Upload';
                progress.textContent = '';
            });
        };
    }

    var reconPage = 1;
    var reconPerPage = 25;
    var reconFilter = 'all';
    var reconSubTab = 'transactions';
    var reconDateFrom = '';
    var reconDateTo = '';

    async function renderReconciliation(root, tabsHtml) {
        var month = new Date().toISOString().slice(0, 7);
        root.innerHTML = '<div class="inv-wrap recon-wrap">' +
            '<div class="inv-header"><h2 class="inv-title">Reconciliation</h2></div>' +
            tabsHtml +
            '<div class="kpi-status-msg">Loading reconciliation...</div></div>';

        try {
            var payload = await requestJson('/api/invoice-reconciliation?month=' + encodeURIComponent(month));
            var stats = payload.stats || {};
            var transactions = payload.transactions || [];
            var unmatchedInvoices = payload.unmatchedInvoices || [];

            function formatAmount(amt) {
                var n = parseFloat(amt);
                if (isNaN(n)) return '₹0';
                return '₹' + n.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            }

            var statsHtml = '<div class="inv-summary">' +
                '<div class="inv-summary-card"><div class="inv-summary-number">' + stats.totalTransactions + '</div><div class="inv-summary-label">Total Transactions</div></div>' +
                '<div class="inv-summary-card" style="border-color:#22c55e"><div class="inv-summary-number" style="color:#22c55e">' + stats.matched + '</div><div class="inv-summary-label">Matched</div></div>' +
                '<div class="inv-summary-card inv-summary-pending"><div class="inv-summary-number">' + stats.unmatchedTransactions + '</div><div class="inv-summary-label">Unmatched Txns</div></div>' +
                '<div class="inv-summary-card inv-summary-missing"><div class="inv-summary-number">' + stats.unmatchedInvoices + '</div><div class="inv-summary-label">Unmatched Invoices</div></div>' +
                '</div>';

            // Sub-tabs: Bank Transactions | Unmatched Invoices
            var subTabsHtml = '<div class="recon-subtabs">' +
                '<button class="recon-subtab' + (reconSubTab === 'transactions' ? ' recon-subtab-active' : '') + '" data-subtab="transactions">' +
                    'Bank Transactions <span class="recon-subtab-count">' + transactions.length + '</span></button>' +
                '<button class="recon-subtab' + (reconSubTab === 'unmatched' ? ' recon-subtab-active' : '') + '" data-subtab="unmatched">' +
                    'Unmatched Invoices <span class="recon-subtab-count recon-subtab-count-warn">' + unmatchedInvoices.length + '</span></button>' +
                '</div>';

            var actionsHtml = '<div class="inv-filters">' +
                (reconSubTab === 'transactions' ?
                    '<select class="inv-filter-select" id="reconFilterSelect">' +
                        '<option value="all"' + (reconFilter === 'all' ? ' selected' : '') + '>All Transactions</option>' +
                        '<option value="unmatched"' + (reconFilter === 'unmatched' ? ' selected' : '') + '>Unmatched Only</option>' +
                        '<option value="matched"' + (reconFilter === 'matched' ? ' selected' : '') + '>Matched Only</option>' +
                        '<option value="credit"' + (reconFilter === 'credit' ? ' selected' : '') + '>Credits Only</option>' +
                        '<option value="debit"' + (reconFilter === 'debit' ? ' selected' : '') + '>Debits Only</option>' +
                    '</select>' +
                    '<div class="recon-date-filter">' +
                        '<input type="date" class="input recon-date-input" id="reconDateFrom" value="' + reconDateFrom + '" title="From date">' +
                        '<span class="recon-date-sep">to</span>' +
                        '<input type="date" class="input recon-date-input" id="reconDateTo" value="' + reconDateTo + '" title="To date">' +
                        (reconDateFrom || reconDateTo ? '<button type="button" class="recon-date-clear" id="reconDateClear" title="Clear dates">&times;</button>' : '') +
                    '</div>' : '') +
                '<button type="button" class="inv-stmt-btn" id="reconUploadBtn">' +
                    '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;margin-right:4px"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>' +
                    'Upload Statement</button>' +
                '<button type="button" class="btn btn-primary inv-new-btn" id="reconMatchBtn">' +
                    '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;margin-right:4px"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>' +
                    'Match Invoices</button>' +
                '</div>';

            var contentHtml = '';

            if (reconSubTab === 'transactions') {
                // Filter transactions
                var filtered = transactions;
                if (reconFilter === 'unmatched') filtered = filtered.filter(function (t) { return t.matchStatus === 'unmatched'; });
                else if (reconFilter === 'matched') filtered = filtered.filter(function (t) { return t.matchStatus === 'matched'; });
                else if (reconFilter === 'credit') filtered = filtered.filter(function (t) { return t.type === 'credit'; });
                else if (reconFilter === 'debit') filtered = filtered.filter(function (t) { return t.type === 'debit'; });

                // Date range filter
                if (reconDateFrom) filtered = filtered.filter(function (t) { return t.date >= reconDateFrom; });
                if (reconDateTo) filtered = filtered.filter(function (t) { return t.date <= reconDateTo; });

                // Pagination
                var totalPages = Math.max(1, Math.ceil(filtered.length / reconPerPage));
                if (reconPage > totalPages) reconPage = totalPages;
                var startIdx = (reconPage - 1) * reconPerPage;
                var pageItems = filtered.slice(startIdx, startIdx + reconPerPage);

                if (filtered.length > 0) {
                    var showing = 'Showing ' + (startIdx + 1) + '–' + Math.min(startIdx + reconPerPage, filtered.length) + ' of ' + filtered.length;
                    contentHtml = '<div class="recon-table-header">' +
                        '<span class="recon-showing">' + showing + '</span>' +
                    '</div>' +
                    '<div class="recon-table-wrap">' +
                    '<table class="recon-table">' +
                        '<thead><tr>' +
                            '<th class="recon-th-date">Date</th>' +
                            '<th class="recon-th-desc">Description</th>' +
                            '<th class="recon-th-ref">Reference</th>' +
                            '<th class="recon-th-amount">Amount</th>' +
                            '<th class="recon-th-balance">Balance</th>' +
                            '<th class="recon-th-status">Status</th>' +
                        '</tr></thead>' +
                        '<tbody>' +
                        pageItems.map(function (tx) {
                            var dateStr = new Date(tx.date).toLocaleDateString('en-IN', { timeZone: 'Asia/Kolkata', day: '2-digit', month: 'short', year: '2-digit' });
                            var isCredit = tx.type === 'credit';
                            var amtClass = isCredit ? 'recon-amt-credit' : 'recon-amt-debit';
                            var amtPrefix = isCredit ? '+' : '-';
                            var matchClass = tx.matchStatus === 'matched' ? 'recon-status-matched' : tx.matchStatus === 'ignored' ? 'recon-status-ignored' : 'recon-status-unmatched';
                            var matchLabel = tx.matchStatus === 'matched' ? 'Matched' : tx.matchStatus === 'ignored' ? 'Ignored' : 'Unmatched';
                            var desc = tx.description || '';
                            var matchedInfo = '';
                            if (tx.matchedInvoice) {
                                matchedInfo = '<div class="recon-linked">' + escapeHtml(tx.matchedInvoice.vendorName) + ' — ' + formatAmount(tx.matchedInvoice.amount) + '</div>';
                            }
                            return '<tr class="recon-row">' +
                                '<td class="recon-td-date">' + dateStr + '</td>' +
                                '<td class="recon-td-desc">' + escapeHtml(desc) + matchedInfo + '</td>' +
                                '<td class="recon-td-ref">' + (tx.reference ? escapeHtml(tx.reference.length > 16 ? '...' + tx.reference.slice(-12) : tx.reference) : '<span style="color:#3f3f46">—</span>') + '</td>' +
                                '<td class="recon-td-amount ' + amtClass + '">' + amtPrefix + formatAmount(tx.amount) + '</td>' +
                                '<td class="recon-td-balance">' + (tx.balance !== null ? formatAmount(tx.balance) : '<span style="color:#3f3f46">—</span>') + '</td>' +
                                '<td class="recon-td-status"><span class="recon-status ' + matchClass + '">' + matchLabel + '</span></td>' +
                            '</tr>';
                        }).join('') +
                        '</tbody></table></div>';

                    if (totalPages > 1) {
                        var pagBtns = '';
                        pagBtns += '<button class="recon-page-btn" data-page="' + (reconPage - 1) + '"' + (reconPage === 1 ? ' disabled' : '') + '>&laquo; Prev</button>';
                        var startP = Math.max(1, reconPage - 2);
                        var endP = Math.min(totalPages, startP + 4);
                        if (endP - startP < 4) startP = Math.max(1, endP - 4);
                        for (var p = startP; p <= endP; p++) {
                            pagBtns += '<button class="recon-page-btn' + (p === reconPage ? ' recon-page-active' : '') + '" data-page="' + p + '">' + p + '</button>';
                        }
                        pagBtns += '<button class="recon-page-btn" data-page="' + (reconPage + 1) + '"' + (reconPage === totalPages ? ' disabled' : '') + '>Next &raquo;</button>';
                        contentHtml += '<div class="recon-pagination">' + pagBtns + '</div>';
                    }
                } else {
                    contentHtml = '<div class="recon-empty-state">' +
                        '<svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#27272a" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>' +
                        '<div class="recon-empty-title">No transactions found</div>' +
                        '<div class="recon-empty-text">' + (reconFilter !== 'all' ? 'Try changing the filter or ' : '') + 'Upload a bank statement to get started.</div>' +
                        '<button type="button" class="btn btn-primary inv-new-btn" id="reconEmptyUpload" style="margin-top:12px">' +
                            '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;margin-right:4px"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>' +
                            'Upload Statement</button>' +
                    '</div>';
                }

            } else {
                // Unmatched Invoices tab
                if (unmatchedInvoices.length > 0) {
                    contentHtml = '<div class="recon-table-wrap"><table class="recon-table">' +
                        '<thead><tr>' +
                            '<th>Vendor</th>' +
                            '<th class="recon-th-amount">Amount</th>' +
                            '<th class="recon-th-date">Date</th>' +
                            '<th>Submitted By</th>' +
                            '<th>Action</th>' +
                        '</tr></thead>' +
                        '<tbody>' +
                        unmatchedInvoices.map(function (inv) {
                            var dateStr = new Date(inv.invoiceDate).toLocaleDateString('en-IN', { timeZone: 'Asia/Kolkata', day: '2-digit', month: 'short', year: '2-digit' });
                            return '<tr class="recon-row">' +
                                '<td style="font-weight:600;color:#fafafa">' + escapeHtml(inv.vendorName) + '</td>' +
                                '<td class="recon-td-amount recon-amt-debit">' + formatAmount(inv.amount) + '</td>' +
                                '<td class="recon-td-date">' + dateStr + '</td>' +
                                '<td>' + escapeHtml(inv.userName) + '</td>' +
                                '<td><button type="button" class="recon-match-btn" data-inv-id="' + inv.id + '" data-inv-vendor="' + escapeHtml(inv.vendorName) + '" data-inv-amount="' + inv.amount + '">Match</button></td>' +
                            '</tr>';
                        }).join('') +
                        '</tbody></table></div>';
                } else {
                    contentHtml = '<div class="recon-empty-state">' +
                        '<svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#22c55e" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>' +
                        '<div class="recon-empty-title" style="color:#4ade80">All invoices matched!</div>' +
                        '<div class="recon-empty-text">Every submitted invoice has a corresponding bank transaction.</div>' +
                    '</div>';
                }
            }

            root.innerHTML = '<div class="inv-wrap recon-wrap">' +
                '<div class="inv-header"><h2 class="inv-title">Reconciliation — ' + escapeHtml(month) + '</h2></div>' +
                tabsHtml + statsHtml + subTabsHtml + actionsHtml + contentHtml + '</div>';

            // Bind main tab events
            root.querySelectorAll('.inv-tab').forEach(function (tab) {
                tab.onclick = function () {
                    invoiceTab = tab.getAttribute('data-tab');
                    renderInvoices();
                };
            });

            // Sub-tab events
            root.querySelectorAll('.recon-subtab').forEach(function (btn) {
                btn.onclick = function () {
                    reconSubTab = btn.getAttribute('data-subtab');
                    reconPage = 1;
                    reconFilter = 'all';
                    renderReconciliation(root, tabsHtml);
                };
            });

            // Manual match buttons
            root.querySelectorAll('.recon-match-btn').forEach(function (btn) {
                btn.onclick = function () {
                    showManualMatchModal(
                        parseInt(btn.getAttribute('data-inv-id')),
                        btn.getAttribute('data-inv-vendor'),
                        btn.getAttribute('data-inv-amount'),
                        root, tabsHtml
                    );
                };
            });

            // Filter
            var filterSelect = document.getElementById('reconFilterSelect');
            if (filterSelect) filterSelect.onchange = function () {
                reconFilter = this.value;
                reconPage = 1;
                renderReconciliation(root, tabsHtml);
            };

            // Date filter
            var dateFrom = document.getElementById('reconDateFrom');
            var dateTo = document.getElementById('reconDateTo');
            if (dateFrom) dateFrom.onchange = function () {
                reconDateFrom = this.value;
                reconPage = 1;
                renderReconciliation(root, tabsHtml);
            };
            if (dateTo) dateTo.onchange = function () {
                reconDateTo = this.value;
                reconPage = 1;
                renderReconciliation(root, tabsHtml);
            };
            var dateClear = document.getElementById('reconDateClear');
            if (dateClear) dateClear.onclick = function () {
                reconDateFrom = '';
                reconDateTo = '';
                reconPage = 1;
                renderReconciliation(root, tabsHtml);
            };

            // Pagination
            root.querySelectorAll('.recon-page-btn').forEach(function (btn) {
                btn.onclick = function () {
                    var pg = parseInt(btn.getAttribute('data-page'));
                    if (pg >= 1 && pg <= totalPages) {
                        reconPage = pg;
                        renderReconciliation(root, tabsHtml);
                        var tw = root.querySelector('.recon-table-wrap');
                        if (tw) tw.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    }
                };
            });

            var uploadBtn = document.getElementById('reconUploadBtn');
            if (uploadBtn) uploadBtn.onclick = function () { showUploadStatementModal(); };
            var emptyUpload = document.getElementById('reconEmptyUpload');
            if (emptyUpload) emptyUpload.onclick = function () { showUploadStatementModal(); };

            var matchBtn = document.getElementById('reconMatchBtn');
            if (matchBtn) {
                matchBtn.onclick = async function () {
                    matchBtn.disabled = true;
                    matchBtn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;margin-right:4px;animation:stmt-pulse 1s infinite"><circle cx="12" cy="12" r="10"/></svg>Matching...';
                    try {
                        var result = await requestJson('/api/invoice-submissions', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ action: 'run_matching' })
                        });
                        reconPage = 1;
                        renderReconciliation(root, tabsHtml);
                    } catch (err) {
                        alert('Matching failed: ' + (err.message || 'Unknown error'));
                        matchBtn.disabled = false;
                        matchBtn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;margin-right:4px"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>Match Invoices';
                    }
                };
            }

        } catch (err) {
            console.error('Reconciliation load failed', err);
            root.innerHTML = '<div class="inv-wrap"><div class="inv-header"><h2 class="inv-title">Reconciliation</h2></div>' + tabsHtml + '<div class="inv-empty">Unable to load reconciliation data.</div></div>';
            root.querySelectorAll('.inv-tab').forEach(function (tab) {
                tab.onclick = function () { invoiceTab = tab.getAttribute('data-tab'); renderInvoices(); };
            });
        }
    }

    function showManualMatchModal(invoiceId, vendorName, amount, reconRoot, reconTabsHtml) {
        var overlay = document.createElement('div');
        overlay.className = 'modal-overlay';
        overlay.innerHTML = '<div class="modal-content" style="max-width:620px;width:90vw">' +
            '<h3 class="modal-title">Match Invoice — ' + escapeHtml(vendorName) + ' (₹' + parseFloat(amount).toLocaleString('en-IN') + ')</h3>' +
            '<div class="mm-search-wrap">' +
                '<input type="text" id="mmSearchInput" class="input stmt-field-input" placeholder="Search by description, amount, or reference..." autofocus>' +
            '</div>' +
            '<div id="mmResults" class="mm-results"><div class="mm-hint">Type to search unmatched bank transactions</div></div>' +
            '<div class="modal-actions">' +
                '<button type="button" class="btn" id="mmCancelBtn">Cancel</button>' +
            '</div>' +
        '</div>';
        document.body.appendChild(overlay);

        function close() { overlay.remove(); }
        overlay.addEventListener('click', function (e) { if (e.target === overlay) close(); });
        document.getElementById('mmCancelBtn').onclick = close;

        var searchTimer = null;
        var searchInput = document.getElementById('mmSearchInput');
        var resultsDiv = document.getElementById('mmResults');

        function formatAmt(a) {
            var n = parseFloat(a);
            return isNaN(n) ? '₹0' : '₹' + n.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

        async function doSearch(query) {
            resultsDiv.innerHTML = '<div class="mm-hint">Searching...</div>';
            try {
                var res = await fetch('/api/invoice-submissions', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify({ action: 'search_transactions', query: query, invoice_id: invoiceId }),
                    credentials: 'same-origin'
                });
                var data = await res.json();
                if (!data.ok || !data.transactions || data.transactions.length === 0) {
                    resultsDiv.innerHTML = '<div class="mm-hint">No matching transactions found</div>';
                    return;
                }
                resultsDiv.innerHTML = data.transactions.map(function (tx) {
                    var dateStr = new Date(tx.date).toLocaleDateString('en-IN', { timeZone: 'Asia/Kolkata', day: '2-digit', month: 'short', year: '2-digit' });
                    return '<div class="mm-tx-row" data-tx-id="' + tx.id + '">' +
                        '<div class="mm-tx-main">' +
                            '<div class="mm-tx-desc">' + escapeHtml(tx.description) + '</div>' +
                            '<div class="mm-tx-meta">' +
                                '<span>' + dateStr + '</span>' +
                                (tx.reference ? '<span>Ref: ' + escapeHtml(tx.reference.length > 16 ? '...' + tx.reference.slice(-12) : tx.reference) + '</span>' : '') +
                            '</div>' +
                        '</div>' +
                        '<div class="mm-tx-amount">' + formatAmt(tx.amount) + '</div>' +
                        '<button type="button" class="mm-select-btn">Select</button>' +
                    '</div>';
                }).join('');

                resultsDiv.querySelectorAll('.mm-select-btn').forEach(function (btn) {
                    btn.onclick = async function () {
                        var txId = parseInt(btn.closest('.mm-tx-row').getAttribute('data-tx-id'));
                        btn.disabled = true;
                        btn.textContent = 'Matching...';
                        try {
                            var mRes = await fetch('/api/invoice-submissions', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                                body: JSON.stringify({ action: 'manual_match', invoice_id: invoiceId, transaction_id: txId }),
                                credentials: 'same-origin'
                            });
                            var mData = await mRes.json();
                            if (!mRes.ok || !mData.ok) throw new Error(mData.error || 'Failed');
                            close();
                            renderReconciliation(reconRoot, reconTabsHtml);
                        } catch (err) {
                            btn.disabled = false;
                            btn.textContent = 'Select';
                            alert('Match failed: ' + (err.message || 'Unknown error'));
                        }
                    };
                });
            } catch (err) {
                resultsDiv.innerHTML = '<div class="mm-hint" style="color:#f87171">Search failed: ' + escapeHtml(err.message || 'Unknown error') + '</div>';
            }
        }

        // Auto-search with vendor name on open
        searchInput.value = vendorName;
        doSearch(vendorName);

        searchInput.addEventListener('input', function () {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(function () {
                var q = searchInput.value.trim();
                if (q.length >= 2) doSearch(q);
                else resultsDiv.innerHTML = '<div class="mm-hint">Type at least 2 characters to search</div>';
            }, 300);
        });
    }

    function showUploadStatementModal() {
        var overlay = document.createElement('div');
        overlay.className = 'stmt-upload-overlay';

        var currentMonth = new Date().toISOString().slice(0, 7);

        overlay.innerHTML =
            '<div class="stmt-upload-page">' +
                '<button type="button" class="stmt-upload-back" id="stmtBackBtn">' +
                    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5"/><path d="m12 19-7-7 7-7"/></svg>' +
                    'Back to Invoices' +
                '</button>' +
                '<h1 class="stmt-upload-heading">Upload Bank Statement</h1>' +
                '<p class="stmt-upload-subtext">Upload your bank statement to automatically extract and parse all transactions for reconciliation. XLS/XLSX files are parsed instantly; PDF/CSV use AI parsing.</p>' +

                '<form id="stmtForm" class="stmt-upload-form">' +
                    '<div class="stmt-field">' +
                        '<label class="stmt-field-label" for="stmtBank">Bank Name</label>' +
                        '<input type="text" class="input stmt-field-input" id="stmtBank" placeholder="e.g. HDFC, SBI, ICICI, Axis">' +
                    '</div>' +

                    '<div class="stmt-field">' +
                        '<label class="stmt-field-label" for="stmtMonth">Statement Month</label>' +
                        '<input type="month" class="input stmt-field-input" id="stmtMonth" value="' + currentMonth + '">' +
                    '</div>' +

                    '<div class="stmt-field">' +
                        '<label class="stmt-field-label">Statement File</label>' +
                        '<div class="stmt-dropzone" id="stmtDropzone">' +
                            '<div class="stmt-dropzone-icon">' +
                                '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">' +
                                    '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>' +
                                    '<polyline points="17 8 12 3 7 8"/>' +
                                    '<line x1="12" y1="3" x2="12" y2="15"/>' +
                                '</svg>' +
                            '</div>' +
                            '<div class="stmt-dropzone-text">Drag & drop your file here or <strong>browse</strong></div>' +
                            '<div class="stmt-dropzone-formats">Supported formats</div>' +
                            '<div class="stmt-format-chips">' +
                                '<span class="stmt-format-chip">PDF</span>' +
                                '<span class="stmt-format-chip">CSV</span>' +
                                '<span class="stmt-format-chip">TXT</span>' +
                                '<span class="stmt-format-chip">XLS</span>' +
                                '<span class="stmt-format-chip">XLSX</span>' +
                            '</div>' +
                        '</div>' +
                        '<input type="file" id="stmtFile" accept=".pdf,.csv,.txt,.xls,.xlsx" style="display:none">' +
                        '<div id="stmtFileInfo" style="display:none"></div>' +
                    '</div>' +

                    '<button type="submit" class="btn btn-primary stmt-submit-btn" id="stmtSubmitBtn" disabled>' +
                        '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>' +
                        'Upload & Parse Statement' +
                    '</button>' +

                    '<div class="stmt-progress" id="stmtProgress">' +
                        '<div class="stmt-progress-bar-wrap"><div class="stmt-progress-bar" id="stmtProgressBar"></div></div>' +
                        '<div class="stmt-progress-steps">' +
                            '<div class="stmt-step" id="stmtStep1">' +
                                '<div class="stmt-step-icon">1</div>' +
                                '<div class="stmt-step-content"><div class="stmt-step-title">Uploading file</div><div class="stmt-step-detail" id="stmtStep1Detail">Sending file to server...</div></div>' +
                            '</div>' +
                            '<div class="stmt-step" id="stmtStep2">' +
                                '<div class="stmt-step-icon">2</div>' +
                                '<div class="stmt-step-content"><div class="stmt-step-title" id="stmtStep2Title">Parsing transactions</div><div class="stmt-step-detail" id="stmtStep2Detail">Reading and extracting transactions from your file...</div></div>' +
                            '</div>' +
                            '<div class="stmt-step" id="stmtStep3">' +
                                '<div class="stmt-step-icon">3</div>' +
                                '<div class="stmt-step-content"><div class="stmt-step-title">Saving to database</div><div class="stmt-step-detail" id="stmtStep3Detail">Storing parsed transactions for reconciliation...</div></div>' +
                            '</div>' +
                        '</div>' +
                    '</div>' +

                    '<div class="stmt-result" id="stmtResult">' +
                        '<div class="stmt-result-title" id="stmtResultTitle"></div>' +
                        '<div class="stmt-result-detail" id="stmtResultDetail"></div>' +
                        '<div class="stmt-result-actions" id="stmtResultActions"></div>' +
                    '</div>' +
                '</form>' +
            '</div>';

        document.body.appendChild(overlay);

        function close() { overlay.remove(); }

        // Back button
        document.getElementById('stmtBackBtn').onclick = close;

        // Dropzone logic
        var dropzone = document.getElementById('stmtDropzone');
        var fileInput = document.getElementById('stmtFile');
        var fileInfo = document.getElementById('stmtFileInfo');
        var submitBtn = document.getElementById('stmtSubmitBtn');

        function formatFileSize(bytes) {
            if (bytes < 1024) return bytes + ' B';
            if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
            return (bytes / 1048576).toFixed(1) + ' MB';
        }

        function showSelectedFile(file) {
            fileInfo.style.display = 'block';
            fileInfo.innerHTML = '<div class="stmt-dropzone-file">' +
                '<span class="stmt-dropzone-file-name">' + escapeHtml(file.name) + '</span>' +
                '<span class="stmt-dropzone-file-size">' + formatFileSize(file.size) + '</span>' +
                '<button type="button" class="stmt-dropzone-file-remove" id="stmtFileRemove">Remove</button>' +
                '</div>';
            submitBtn.disabled = false;
            document.getElementById('stmtFileRemove').onclick = function () {
                fileInput.value = '';
                fileInfo.style.display = 'none';
                fileInfo.innerHTML = '';
                submitBtn.disabled = true;
            };
        }

        dropzone.onclick = function () { fileInput.click(); };
        fileInput.onchange = function () {
            if (fileInput.files && fileInput.files[0]) showSelectedFile(fileInput.files[0]);
        };

        dropzone.addEventListener('dragover', function (e) {
            e.preventDefault(); dropzone.classList.add('drag-over');
        });
        dropzone.addEventListener('dragleave', function () {
            dropzone.classList.remove('drag-over');
        });
        dropzone.addEventListener('drop', function (e) {
            e.preventDefault(); dropzone.classList.remove('drag-over');
            if (e.dataTransfer.files && e.dataTransfer.files[0]) {
                fileInput.files = e.dataTransfer.files;
                showSelectedFile(e.dataTransfer.files[0]);
            }
        });

        // Progress helpers
        function setStep(stepNum, state) {
            var el = document.getElementById('stmtStep' + stepNum);
            if (!el) return;
            el.className = 'stmt-step' + (state ? ' ' + state : '');
            var iconEl = el.querySelector('.stmt-step-icon');
            if (state === 'done') iconEl.textContent = '\u2713';
            else if (state === 'error') iconEl.textContent = '\u2717';
        }

        function setProgressBar(pct) {
            var bar = document.getElementById('stmtProgressBar');
            bar.classList.remove('indeterminate');
            bar.style.width = pct + '%';
        }

        function setProgressIndeterminate() {
            var bar = document.getElementById('stmtProgressBar');
            bar.classList.add('indeterminate');
            bar.style.width = '';
        }

        // Form submit
        document.getElementById('stmtForm').onsubmit = async function (e) {
            e.preventDefault();
            if (!fileInput.files || !fileInput.files[0]) return;

            var selectedFile = fileInput.files[0];
            var fileExt = selectedFile.name.split('.').pop().toLowerCase();
            var isSpreadsheet = (fileExt === 'xls' || fileExt === 'xlsx');

            var progress = document.getElementById('stmtProgress');
            var result = document.getElementById('stmtResult');
            submitBtn.disabled = true;
            submitBtn.style.display = 'none';
            progress.classList.add('active');
            result.classList.remove('active');

            // Update step labels based on file type
            var step2Title = document.getElementById('stmtStep2Title');
            var step2Detail = document.getElementById('stmtStep2Detail');
            if (isSpreadsheet) {
                step2Title.textContent = 'Parsing spreadsheet';
                step2Detail.textContent = 'Directly reading columns from your ' + fileExt.toUpperCase() + ' file...';
            } else {
                step2Title.textContent = 'AI parsing transactions';
                step2Detail.textContent = 'Tessa AI is identifying and structuring each transaction (30-60s)...';
            }

            // Step 1: Upload
            setStep(1, 'active');
            setProgressBar(15);

            try {
                var formData = new FormData();
                formData.append('action', 'upload_statement');
                formData.append('bank_name', document.getElementById('stmtBank').value.trim());
                formData.append('month', document.getElementById('stmtMonth').value);
                formData.append('file', selectedFile);

                await new Promise(function (r) { setTimeout(r, 300); });
                setStep(1, 'done');
                document.getElementById('stmtStep1Detail').textContent = 'File uploaded (' + formatFileSize(selectedFile.size) + ')';

                // Step 2: Parsing
                setStep(2, 'active');
                if (isSpreadsheet) {
                    setProgressBar(40);
                } else {
                    setProgressIndeterminate();
                }

                var res = await fetch('/api/invoice-submissions', {
                    method: 'POST',
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    body: formData,
                    credentials: 'same-origin'
                });
                var data = await res.json();

                if (!res.ok || !data.ok) throw new Error(data.error || data.message || 'Failed to parse statement');

                var txCount = data.transactionsCount || 0;

                // Step 2 done
                setStep(2, 'done');
                step2Detail.textContent = txCount + ' transactions extracted';
                setProgressBar(80);

                // Step 3: Saving
                setStep(3, 'active');
                setProgressBar(90);
                await new Promise(function (r) { setTimeout(r, 400); });
                setStep(3, 'done');
                document.getElementById('stmtStep3Detail').textContent = txCount + ' transactions saved successfully';
                setProgressBar(100);

                // Show success result
                result.className = 'stmt-result active success';
                document.getElementById('stmtResultTitle').textContent = txCount + ' Transaction' + (txCount !== 1 ? 's' : '') + ' Imported Successfully';
                document.getElementById('stmtResultDetail').innerHTML =
                    '<div style="display:flex;gap:16px;flex-wrap:wrap;margin:8px 0 4px">' +
                        '<div><span style="color:#71717a;font-size:.75rem">File</span><br><span style="color:#e4e4e7">' + escapeHtml(selectedFile.name) + '</span></div>' +
                        '<div><span style="color:#71717a;font-size:.75rem">Bank</span><br><span style="color:#e4e4e7">' + (escapeHtml(document.getElementById('stmtBank').value.trim()) || 'N/A') + '</span></div>' +
                        '<div><span style="color:#71717a;font-size:.75rem">Month</span><br><span style="color:#e4e4e7">' + escapeHtml(document.getElementById('stmtMonth').value) + '</span></div>' +
                        '<div><span style="color:#71717a;font-size:.75rem">Method</span><br><span style="color:#e4e4e7">' + (isSpreadsheet ? 'Direct Parse (instant)' : 'AI Parsing') + '</span></div>' +
                    '</div>' +
                    '<p style="margin:10px 0 0;color:#a1a1aa">You can now run AI matching to reconcile these transactions with submitted invoices.</p>';
                document.getElementById('stmtResultActions').innerHTML =
                    '<button type="button" class="stmt-result-btn stmt-result-btn-primary" id="stmtGoRecon">View Reconciliation</button>' +
                    '<button type="button" class="stmt-result-btn stmt-result-btn-secondary" id="stmtUploadAnother">Upload Another</button>';
                document.getElementById('stmtGoRecon').onclick = function () { close(); invoiceTab = 'reconciliation'; renderInvoices(); };
                document.getElementById('stmtUploadAnother').onclick = function () {
                    close();
                    showUploadStatementModal();
                };

                // Scroll result into view
                result.scrollIntoView({ behavior: 'smooth', block: 'nearest' });

            } catch (err) {
                // Mark current active step as error
                for (var s = 1; s <= 3; s++) {
                    var stepEl = document.getElementById('stmtStep' + s);
                    if (stepEl && stepEl.classList.contains('active')) {
                        setStep(s, 'error');
                        break;
                    }
                }
                setProgressBar(0);

                result.className = 'stmt-result active error';
                document.getElementById('stmtResultTitle').textContent = 'Upload Failed';
                document.getElementById('stmtResultDetail').textContent = err.message || 'Something went wrong while parsing the statement.';
                document.getElementById('stmtResultActions').innerHTML =
                    '<button type="button" class="stmt-result-btn stmt-result-btn-primary" id="stmtRetryBtn">Try Again</button>' +
                    '<button type="button" class="stmt-result-btn stmt-result-btn-secondary" id="stmtCancelBtn2">Cancel</button>';
                document.getElementById('stmtRetryBtn').onclick = function () {
                    close();
                    showUploadStatementModal();
                };
                document.getElementById('stmtCancelBtn2').onclick = close;

                result.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }
        };
    }

    function showNewInvoiceModal() { showInvoiceUploadModal(); }
    function _oldShowNewInvoiceModal_removed(categories) {
        var overlay = document.createElement('div');
        overlay.className = 'stmt-upload-overlay';
        overlay.innerHTML =
            '<div class="stmt-upload-page">' +
                '<button type="button" class="stmt-upload-back" id="invBackBtn">' +
                    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5"/><path d="m12 19-7-7 7-7"/></svg>' +
                    'Back to Invoices' +
                '</button>' +
                '<h1 class="stmt-upload-heading">Submit Invoice</h1>' +
                '<p class="stmt-upload-subtext">Upload your invoice file and AI will automatically extract vendor, amount, date, and match it with bank transactions.</p>' +
                '<form id="invNewForm" class="stmt-upload-form">' +
                    '<div class="stmt-field">' +
                        '<label class="stmt-field-label">Invoice File</label>' +
                        '<div class="stmt-dropzone" id="invDropzone">' +
                            '<div class="stmt-dropzone-icon">' +
                                '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">' +
                                    '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>' +
                                    '<polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/>' +
                                '</svg>' +
                            '</div>' +
                            '<div class="stmt-dropzone-text">Drag & drop your invoice or <strong>browse</strong></div>' +
                            '<div class="stmt-dropzone-formats">Supported formats</div>' +
                            '<div class="stmt-format-chips">' +
                                '<span class="stmt-format-chip">PDF</span>' +
                                '<span class="stmt-format-chip">JPG</span>' +
                                '<span class="stmt-format-chip">PNG</span>' +
                                '<span class="stmt-format-chip">WEBP</span>' +
                            '</div>' +
                        '</div>' +
                        '<input type="file" id="invFile" accept=".pdf,.jpg,.jpeg,.png,.webp" style="display:none">' +
                        '<div id="invFileInfo" style="display:none"></div>' +
                    '</div>' +
                    '<div class="stmt-field">' +
                        '<label class="stmt-field-label" for="invNotes">Notes (optional)</label>' +
                        '<input type="text" class="input stmt-field-input" id="invNotes" placeholder="Any additional context...">' +
                    '</div>' +
                    '<button type="submit" class="btn btn-primary stmt-submit-btn" id="invSubmitBtn" disabled>' +
                        '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>' +
                        'Upload & Process Invoice' +
                    '</button>' +
                    '<div class="stmt-progress" id="invProgress">' +
                        '<div class="stmt-progress-bar-wrap"><div class="stmt-progress-bar" id="invProgressBar"></div></div>' +
                        '<div class="stmt-progress-steps">' +
                            '<div class="stmt-step" id="invStep1">' +
                                '<div class="stmt-step-icon">1</div>' +
                                '<div class="stmt-step-content"><div class="stmt-step-title">Uploading invoice</div><div class="stmt-step-detail" id="invStep1Detail">Sending file to server...</div></div>' +
                            '</div>' +
                            '<div class="stmt-step" id="invStep2">' +
                                '<div class="stmt-step-icon">2</div>' +
                                '<div class="stmt-step-content"><div class="stmt-step-title">AI extracting details</div><div class="stmt-step-detail" id="invStep2Detail">Reading vendor, amount, and date from your invoice...</div></div>' +
                            '</div>' +
                            '<div class="stmt-step" id="invStep3">' +
                                '<div class="stmt-step-icon">3</div>' +
                                '<div class="stmt-step-content"><div class="stmt-step-title">Matching transaction</div><div class="stmt-step-detail" id="invStep3Detail">Finding matching bank transaction...</div></div>' +
                            '</div>' +
                        '</div>' +
                    '</div>' +
                    '<div class="stmt-result" id="invResult">' +
                        '<div class="stmt-result-title" id="invResultTitle"></div>' +
                        '<div class="stmt-result-detail" id="invResultDetail"></div>' +
                        '<div class="stmt-result-actions" id="invResultActions"></div>' +
                    '</div>' +
                '</form>' +
            '</div>';
        document.body.appendChild(overlay);

        function close() { overlay.remove(); }
        document.getElementById('invBackBtn').onclick = close;

        // Dropzone
        var dropzone = document.getElementById('invDropzone');
        var fileInput = document.getElementById('invFile');
        var fileInfo = document.getElementById('invFileInfo');
        var submitBtn = document.getElementById('invSubmitBtn');

        function formatFileSize(bytes) {
            if (bytes < 1024) return bytes + ' B';
            if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
            return (bytes / 1048576).toFixed(1) + ' MB';
        }

        function showFile(file) {
            fileInfo.style.display = 'block';
            fileInfo.innerHTML = '<div class="stmt-dropzone-file">' +
                '<span class="stmt-dropzone-file-name">' + escapeHtml(file.name) + '</span>' +
                '<span class="stmt-dropzone-file-size">' + formatFileSize(file.size) + '</span>' +
                '<button type="button" class="stmt-dropzone-file-remove" id="invFileRemove">Remove</button></div>';
            submitBtn.disabled = false;
            document.getElementById('invFileRemove').onclick = function () {
                fileInput.value = '';
                fileInfo.style.display = 'none';
                submitBtn.disabled = true;
            };
        }

        dropzone.onclick = function () { fileInput.click(); };
        fileInput.onchange = function () { if (fileInput.files[0]) showFile(fileInput.files[0]); };
        dropzone.addEventListener('dragover', function (e) { e.preventDefault(); dropzone.classList.add('drag-over'); });
        dropzone.addEventListener('dragleave', function () { dropzone.classList.remove('drag-over'); });
        dropzone.addEventListener('drop', function (e) {
            e.preventDefault(); dropzone.classList.remove('drag-over');
            if (e.dataTransfer.files[0]) { fileInput.files = e.dataTransfer.files; showFile(e.dataTransfer.files[0]); }
        });

        // Progress helpers
        function setStep(n, state) {
            var el = document.getElementById('invStep' + n);
            if (!el) return;
            el.className = 'stmt-step' + (state ? ' ' + state : '');
            var icon = el.querySelector('.stmt-step-icon');
            if (state === 'done') icon.textContent = '\u2713';
            else if (state === 'error') icon.textContent = '\u2717';
        }
        function setBar(pct) {
            var bar = document.getElementById('invProgressBar');
            bar.classList.remove('indeterminate');
            bar.style.width = pct + '%';
        }
        function setBarIndeterminate() {
            var bar = document.getElementById('invProgressBar');
            bar.classList.add('indeterminate');
            bar.style.width = '';
        }

        // Submit
        document.getElementById('invNewForm').onsubmit = async function (e) {
            e.preventDefault();
            if (!fileInput.files[0]) return;

            var progress = document.getElementById('invProgress');
            var result = document.getElementById('invResult');
            submitBtn.disabled = true;
            submitBtn.style.display = 'none';
            progress.classList.add('active');
            result.classList.remove('active');

            setStep(1, 'active');
            setBar(15);

            try {
                var formData = new FormData();
                formData.append('action', 'submit');
                formData.append('file', fileInput.files[0]);
                formData.append('notes', document.getElementById('invNotes').value.trim());

                await new Promise(function (r) { setTimeout(r, 300); });
                setStep(1, 'done');
                document.getElementById('invStep1Detail').textContent = 'File uploaded (' + formatFileSize(fileInput.files[0].size) + ')';

                setStep(2, 'active');
                setBarIndeterminate();

                var res = await fetch('/api/invoice-submissions', {
                    method: 'POST',
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    body: formData,
                    credentials: 'same-origin'
                });
                var data = await res.json();
                if (!res.ok || !data.ok) throw new Error(data.error || data.message || 'Failed');

                setStep(2, 'done');
                document.getElementById('invStep2Detail').textContent =
                    (data.vendorName || 'Unknown') + ' — ₹' + (data.amount || 0) + ' — ' + (data.invoiceDate || 'N/A');
                setBar(75);

                setStep(3, 'active');
                setBar(85);
                await new Promise(function (r) { setTimeout(r, 400); });
                setStep(3, 'done');

                var matchText = 'No matching bank transaction found';
                if (data.verificationStatus === 'verified') matchText = 'Matched with bank transaction (confidence: ' + data.matchConfidence + '%)';
                else if (data.verificationStatus === 'mismatch') matchText = 'Possible match found (confidence: ' + data.matchConfidence + '%) — needs review';
                document.getElementById('invStep3Detail').textContent = matchText;
                setBar(100);

                // Format category
                var catLabel = (data.category || 'general').replace(/_/g, ' ');
                catLabel = catLabel.charAt(0).toUpperCase() + catLabel.slice(1);

                result.className = 'stmt-result active success';
                document.getElementById('invResultTitle').textContent = 'Invoice Submitted Successfully';
                document.getElementById('invResultDetail').innerHTML =
                    '<div style="display:flex;gap:16px;flex-wrap:wrap;margin:8px 0 4px">' +
                        '<div><span style="color:#71717a;font-size:.75rem">Vendor</span><br><span style="color:#e4e4e7">' + escapeHtml(data.vendorName || 'Unknown') + '</span></div>' +
                        '<div><span style="color:#71717a;font-size:.75rem">Amount</span><br><span style="color:#e4e4e7">₹' + (data.amount || 0) + '</span></div>' +
                        '<div><span style="color:#71717a;font-size:.75rem">Date</span><br><span style="color:#e4e4e7">' + escapeHtml(data.invoiceDate || 'N/A') + '</span></div>' +
                        '<div><span style="color:#71717a;font-size:.75rem">Category</span><br><span style="color:#e4e4e7">' + escapeHtml(catLabel) + '</span></div>' +
                    '</div>' +
                    '<p style="margin:10px 0 0;color:#a1a1aa">' + escapeHtml(matchText) + '</p>';
                document.getElementById('invResultActions').innerHTML =
                    '<button type="button" class="stmt-result-btn stmt-result-btn-primary" id="invGoBack">Back to Invoices</button>' +
                    '<button type="button" class="stmt-result-btn stmt-result-btn-secondary" id="invUploadAnother">Upload Another</button>';
                document.getElementById('invGoBack').onclick = function () { close(); renderInvoices(); };
                document.getElementById('invUploadAnother').onclick = function () { close(); showNewInvoiceModal(categories); };

                result.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            } catch (err) {
                for (var s = 1; s <= 3; s++) {
                    var stepEl = document.getElementById('invStep' + s);
                    if (stepEl && stepEl.classList.contains('active')) { setStep(s, 'error'); break; }
                }
                setBar(0);
                result.className = 'stmt-result active error';
                document.getElementById('invResultTitle').textContent = 'Submission Failed';
                document.getElementById('invResultDetail').textContent = err.message || 'Something went wrong.';
                document.getElementById('invResultActions').innerHTML =
                    '<button type="button" class="stmt-result-btn stmt-result-btn-primary" id="invRetry">Try Again</button>' +
                    '<button type="button" class="stmt-result-btn stmt-result-btn-secondary" id="invCancel2">Cancel</button>';
                document.getElementById('invRetry').onclick = function () { close(); showNewInvoiceModal(categories); };
                document.getElementById('invCancel2').onclick = close;
                result.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }
        };
    }

    window.FinanceModule = {
        renderRevenue: renderRevenue,
        renderInvoices: renderInvoices,
        renderReconciliation: renderReconciliation,
        showInvoiceUploadModal: showInvoiceUploadModal,
        showManualMatchModal: showManualMatchModal,
        showUploadStatementModal: showUploadStatementModal,
        showNewInvoiceModal: showNewInvoiceModal,
        fmtINR: fmtINR
    };
})();
