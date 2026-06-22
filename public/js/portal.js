(function () {
    'use strict';

    var config = window.__PORTAL_CONFIG || {};

    // Active tab for the dashboard notification center (Tessa/Slack/Gmail/Leaves).
    // Module-scoped so it persists across dashboard re-renders.
    var dashActiveTab = 'tessa';

    // Meow sound — prefers a real audio file in /sounds/, falls back to Web Audio synthesis.
    var _meowAudioCtx = null;
    var _meowFile = null;          // cached HTMLAudioElement once we know a file exists
    var _meowFileTried = false;
    var _meowFileAvailable = false;
    var MEOW_FILE_CANDIDATES = ['/sounds/meow.wav', '/sounds/meow.mp3'];

    function probeMeowFile() {
        if (_meowFileTried) return;
        _meowFileTried = true;
        var idx = 0;
        function tryNext() {
            if (idx >= MEOW_FILE_CANDIDATES.length) return;
            var src = MEOW_FILE_CANDIDATES[idx++];
            try {
                var a = new Audio(src);
                a.preload = 'auto';
                a.volume = 0.7;
                var done = false;
                a.addEventListener('canplaythrough', function () {
                    if (done) return; done = true;
                    _meowFileAvailable = true; _meowFile = a;
                }, { once: true });
                a.addEventListener('error', function () {
                    if (done) return; done = true;
                    tryNext();
                }, { once: true });
                a.load();
            } catch (e) { tryNext(); }
        }
        tryNext();
    }
    probeMeowFile();

    function synthMeow() {
        try {
            if (!_meowAudioCtx) {
                var Ctx = window.AudioContext || window.webkitAudioContext;
                if (!Ctx) { console.warn('PortalSounds: Web Audio API not supported'); return; }
                _meowAudioCtx = new Ctx();
            }
            if (_meowAudioCtx.state === 'suspended') _meowAudioCtx.resume();
            var ctx = _meowAudioCtx;
            var t0 = ctx.currentTime;
            var dur = 0.62;

            // Sawtooth carrier — rich harmonics so the lowpass resonance has something to colour.
            var osc = ctx.createOscillator();
            osc.type = 'sawtooth';
            osc.frequency.setValueAtTime(360, t0);
            osc.frequency.exponentialRampToValueAtTime(720, t0 + 0.16);   // up on "ee"
            osc.frequency.setValueAtTime(720, t0 + 0.28);
            osc.frequency.exponentialRampToValueAtTime(480, t0 + dur);    // glide down on "ow"

            // Vibrato — gives the wobbly cat quality.
            var lfo = ctx.createOscillator(); lfo.frequency.value = 6;
            var lfoGain = ctx.createGain();   lfoGain.gain.value = 28;
            lfo.connect(lfoGain).connect(osc.frequency);

            // Resonant lowpass — sweeps cutoff to mimic mouth opening then closing.
            // Lowpass (vs bandpass) keeps the fundamental + lower harmonics audible.
            var lpf = ctx.createBiquadFilter();
            lpf.type = 'lowpass';
            lpf.Q.value = 9;   // strong resonance peak = vowel character
            lpf.frequency.setValueAtTime(900, t0);
            lpf.frequency.exponentialRampToValueAtTime(2600, t0 + 0.18);  // mouth opens
            lpf.frequency.setValueAtTime(2600, t0 + 0.30);
            lpf.frequency.exponentialRampToValueAtTime(1100, t0 + dur);   // mouth closes

            // Loud amp envelope — peak 0.6 (well below clipping but clearly audible).
            var amp = ctx.createGain();
            amp.gain.setValueAtTime(0.0001, t0);
            amp.gain.exponentialRampToValueAtTime(0.6, t0 + 0.05);
            amp.gain.setValueAtTime(0.6, t0 + 0.42);
            amp.gain.exponentialRampToValueAtTime(0.0001, t0 + dur + 0.05);

            osc.connect(lpf).connect(amp).connect(ctx.destination);
            osc.start(t0); lfo.start(t0);
            var stopAt = t0 + dur + 0.1;
            osc.stop(stopAt); lfo.stop(stopAt);
        } catch (e) {
            console.error('PortalSounds.synthMeow failed:', e);
        }
    }

    function playMeow() {
        if (_meowFileAvailable && _meowFile) {
            try { _meowFile.currentTime = 0; _meowFile.play().catch(function () { synthMeow(); }); return; }
            catch (e) { /* fall through */ }
        }
        synthMeow();
    }

    function showMeowIntroToastOnce() {
        try {
            if (localStorage.getItem('meow_intro_seen') === '1') return;
            localStorage.setItem('meow_intro_seen', '1');
        } catch (e) { /* localStorage may be blocked */ }

        var t = document.createElement('div');
        t.className = 'meow-intro-toast';
        t.innerHTML =
            '<div class="meow-intro-icon">🐱</div>' +
            '<div class="meow-intro-body">' +
                '<div class="meow-intro-title">That meow was us!</div>' +
                '<div class="meow-intro-sub">A sound plays when you sign in or sign off. Switch it off in <b>My Profile → Preferences</b> any time.</div>' +
            '</div>' +
            '<button type="button" class="meow-intro-close" aria-label="Dismiss">&times;</button>';
        document.body.appendChild(t);
        requestAnimationFrame(function () { t.classList.add('meow-intro-show'); });
        var dismiss = function () {
            t.classList.remove('meow-intro-show');
            setTimeout(function () { if (t.parentNode) t.parentNode.removeChild(t); }, 300);
        };
        t.querySelector('.meow-intro-close').onclick = dismiss;
        setTimeout(dismiss, 8000);
    }

    window.PortalSounds = { playMeow: playMeow };

    function maybeMeow() {
        if (window.__PORTAL_CONFIG && window.__PORTAL_CONFIG.meowSoundEnabled) {
            playMeow();
            showMeowIntroToastOnce();
        }
    }

    if (config.layout === 'simple') return;

    var weekCache = {};
    var currentReportDate = new Date();
    var currentKpiWeekStart = startOfWeek(new Date());
    var activeKpiPerson;
    var activePerson;
    var activeDailyPerson;
    var activeDailyAppFilter = 'all';
    // Groups always shown regardless of project filter (cross-project notes etc).
    var DR_FILTER_STICKY_GROUPS = ['Daily Summary', 'Video Handoffs'];
    try {
        var savedDailyAppFilter = window.localStorage && localStorage.getItem('dailyAppFilter');
        if (savedDailyAppFilter) activeDailyAppFilter = savedDailyAppFilter;
    } catch (e) { /* private mode / SSR */ }

    var kpiDefinitions = (config.kpiDefinitions || {
        kpiGroups: [],
        kpiGroupsByPerson: {},
        aggregation: {},
        teamKpis: [],
        marketingKpiPeople: []
    });

    function getKpiGroups() { return kpiDefinitions.kpiGroups || []; }
    function getKpiGroupsByPerson() { return kpiDefinitions.kpiGroupsByPerson || {}; }
    function getAggregation() { return kpiDefinitions.aggregation || {}; }
    function getTeamKpis() { return kpiDefinitions.teamKpis || []; }
    function getMarketingKpiPeople() { return kpiDefinitions.marketingKpiPeople || []; }

    var _mkpi = getMarketingKpiPeople();
    var _team = getTeamKpis();
    var _byPerson = getKpiGroupsByPerson();
    var _firstUserId = Object.keys(_byPerson)[0];
    if (_mkpi.length) activeKpiPerson = _mkpi[0].id;
    if (_team.length) activePerson = _team[0].id;
    if (_firstUserId) activePerson = _firstUserId;

    async function refreshKpiDefinitions(userId, userIds) {
        try {
            var url = '/api/kpi-definitions?';
            if (userId) url += 'user_id=' + encodeURIComponent(userId);
            else if (userIds && userIds.length) url += 'user_ids=' + userIds.map(encodeURIComponent).join(',');
            var res = await requestJson(url);
            if (res.ok && res.definitions) {
                kpiDefinitions = Object.assign({}, kpiDefinitions, res.definitions);
            }
        } catch (err) {
            console.error('refreshKpiDefinitions failed', err);
        }
    }

    function slugifyKey(s) {
        return String(s || '').toLowerCase().replace(/\s+/g, '_').replace(/[^a-z0-9_]/g, '');
    }

    function showKpiModal(opts, callback) {
        var type = opts.type || 'edit_label';
        var title = opts.title || 'KPI';
        var bodyHtml = '';
        var fieldIds = [];

        if (type === 'edit_label') {
            bodyHtml = '<div class="mtg-modal-field mtg-modal-field-full"><label for="kpiModalLabel">Label</label><input id="kpiModalLabel" type="text" value="' + escapeHtml(opts.label || '') + '" placeholder="KPI display name"></div>';
            fieldIds = ['kpiModalLabel'];
        } else if (type === 'add_group') {
            bodyHtml = '<div class="mtg-modal-field mtg-modal-field-full"><label for="kpiModalGroupName">Group Name</label><input id="kpiModalGroupName" type="text" placeholder="e.g. Support Tickets"></div>';
            fieldIds = ['kpiModalGroupName'];
        } else if (type === 'add_person') {
            bodyHtml = '<div class="mtg-modal-field"><label for="kpiModalUserId">User ID</label><input id="kpiModalUserId" type="number" placeholder="e.g. 11" min="1"></div>';
            fieldIds = ['kpiModalUserId'];
        } else if (type === 'add_kpi') {
            bodyHtml = '<div class="mtg-modal-field mtg-modal-field-full"><label for="kpiModalLabel">Label</label><input id="kpiModalLabel" type="text" placeholder="e.g. Creator applications received"></div>' +
                '<div class="mtg-modal-field mtg-modal-field-full"><label for="kpiModalFieldKey">Field Key</label><input id="kpiModalFieldKey" type="text" placeholder="e.g. applications_received"><span class="mtg-modal-hint">Auto-generated from label if left blank</span></div>' +
                '<div class="mtg-modal-field mtg-modal-field-full"><label for="kpiModalAggregation">Aggregation</label><select id="kpiModalAggregation"><option value="sum">Sum</option><option value="avg">Avg</option><option value="latest">Latest</option></select></div>';
            fieldIds = ['kpiModalLabel', 'kpiModalFieldKey', 'kpiModalAggregation'];
        } else if (type === 'add_kpi_person') {
            bodyHtml = '<div class="mtg-modal-field mtg-modal-field-full"><label for="kpiModalLabel">Label</label><input id="kpiModalLabel" type="text" placeholder="e.g. Weekly qualified leads"></div>' +
                '<div class="mtg-modal-field mtg-modal-field-full"><label for="kpiModalFieldKey">Field Key</label><input id="kpiModalFieldKey" type="text" placeholder="e.g. weekly_leads"><span class="mtg-modal-hint">Auto-generated from label if left blank</span></div>';
            fieldIds = ['kpiModalLabel', 'kpiModalFieldKey'];
        }

        var overlay = document.createElement('div');
        overlay.id = 'kpiModalOverlay';
        overlay.className = 'mtg-modal-overlay';
        overlay.innerHTML = '<div class="mtg-modal">' +
            '<div class="mtg-modal-header"><h3 class="mtg-modal-title">' + escapeHtml(title) + '</h3><button type="button" class="mtg-modal-close" id="kpiModalClose">&#x2715;</button></div>' +
            '<form id="kpiModalForm"><div class="mtg-modal-body">' + bodyHtml + '</div>' +
            '<div class="mtg-modal-footer"><button type="button" class="btn btn-outline" id="kpiModalCancel">Cancel</button><button type="submit" class="btn btn-primary btn-lg">Save</button></div></form></div>';
        document.body.appendChild(overlay);

        var labelEl = document.getElementById('kpiModalLabel');
        var keyEl = document.getElementById('kpiModalFieldKey');
        if (labelEl && keyEl && (type === 'add_kpi' || type === 'add_kpi_person')) {
            labelEl.addEventListener('input', function () {
                if (!keyEl.dataset.manual) keyEl.value = slugifyKey(labelEl.value);
            });
            keyEl.addEventListener('input', function () { keyEl.dataset.manual = '1'; });
        }

        function closeModal() {
            var el = document.getElementById('kpiModalOverlay');
            if (el) el.remove();
        }

        function collectData() {
            var data = {};
            if (document.getElementById('kpiModalLabel')) data.label = document.getElementById('kpiModalLabel').value.trim();
            if (document.getElementById('kpiModalFieldKey')) data.fieldKey = document.getElementById('kpiModalFieldKey').value.trim();
            if (document.getElementById('kpiModalAggregation')) data.aggregation = document.getElementById('kpiModalAggregation').value;
            if (document.getElementById('kpiModalGroupName')) data.groupName = document.getElementById('kpiModalGroupName').value.trim();
            if (document.getElementById('kpiModalUserId')) data.userId = document.getElementById('kpiModalUserId').value.trim();
            return data;
        }

        document.getElementById('kpiModalForm').onsubmit = function (e) {
            e.preventDefault();
            var data = collectData();
            if (type === 'edit_label' && (!data.label)) return;
            if (type === 'add_group' && (!data.groupName)) return;
            if (type === 'add_person' && (!data.userId || parseInt(data.userId, 10) <= 0)) return;
            if ((type === 'add_kpi' || type === 'add_kpi_person') && (!data.label)) return;
            if ((type === 'add_kpi' || type === 'add_kpi_person') && !data.fieldKey) data.fieldKey = slugifyKey(data.label);
            if ((type === 'add_kpi' || type === 'add_kpi_person') && !data.fieldKey) return;
            closeModal();
            callback(data);
        };

        document.getElementById('kpiModalClose').onclick = closeModal;
        document.getElementById('kpiModalCancel').onclick = closeModal;
        overlay.onclick = function (e) { if (e.target === overlay) closeModal(); };

        if (keyEl && (type === 'add_kpi' || type === 'add_kpi_person') && labelEl) {
            keyEl.value = slugifyKey(labelEl.value);
        }
    }

    function requestJson(url, options) { return MeetingModule.requestJson(url, options); }
    function escapeHtml(v) { return MeetingModule.escapeHtml(v); }

    // Tessa chat functions extracted to tessa-chat.js — accessible via window.TessaChatModule
    function formatTessaReply(text) { return TessaChatModule.formatTessaReply(text); }
    function attachSignoffNavLinks(container) { return TessaChatModule.attachSignoffNavLinks(container); }
    function typeTessaReplyIntoElement(c, r, s, cb) { return TessaChatModule.typeTessaReplyIntoElement(c, r, s, cb); }

    function weekKey(d) { return MeetingModule.weekKey(d); }
    function addDays(d, n) { return MeetingModule.addDays(d, n); }
    function formatDate(d) { return MeetingModule.formatDate(d); }

    // ---- Folder-aware upload helpers --------------------------------------
    // Shared by the daily-report upload panel's folder picker AND its drag-drop
    // dropzone, so a dragged folder behaves identically to a picked one.

    // Common video extensions, used as a fallback when the browser hasn't
    // populated File.type (happens on Windows for .mkv/.mov/etc., and for files
    // renamed without an OS MIME hint).
    var VIDEO_FALLBACK_EXTS = ['mp4','mov','m4v','mkv','avi','webm','wmv','flv','3gp','mpeg','mpg','ts','mts'];

    // Filter a File list down to those matching an HTML "accept" attribute.
    // MIME-pattern accept ("video/*"): keep files whose type starts with the
    // prefix; for video/* with an empty type, fall back to VIDEO_FALLBACK_EXTS by
    // extension so the file isn't silently dropped. Extension accept (".csv,.txt"):
    // match by lowercased extension. Empty/null accept: keep everything. Pure —
    // returns a NEW array, no DOM or alerts.
    function filterFilesByAccept(files, acceptAttr) {
        var picked = Array.from(files);
        if (acceptAttr && acceptAttr.indexOf('/') !== -1) {
            var prefix = acceptAttr.replace(/\*$/, '');
            var isVideoAccept = prefix === 'video/';
            picked = picked.filter(function (f) {
                var t = f.type || '';
                if (t.indexOf(prefix) === 0) return true;
                if (isVideoAccept && !t) {
                    var dot = f.name.lastIndexOf('.');
                    if (dot !== -1) {
                        var ext = f.name.slice(dot + 1).toLowerCase();
                        if (VIDEO_FALLBACK_EXTS.indexOf(ext) !== -1) return true;
                    }
                }
                return false;
            });
        } else if (acceptAttr) {
            var exts = acceptAttr.split(',').map(function (e) { return e.trim().replace(/^\./, '').toLowerCase(); }).filter(Boolean);
            if (exts.length) {
                picked = picked.filter(function (f) {
                    var dot = f.name.lastIndexOf('.');
                    var ext = dot === -1 ? '' : f.name.slice(dot + 1).toLowerCase();
                    return exts.indexOf(ext) !== -1;
                });
            }
        }
        return picked;
    }

    // Derive the top-level folder label for a File, or '' if it wasn't part of a
    // folder upload. Two sources: the folder PICKER (webkitdirectory) populates
    // file.webkitRelativePath ("Batch/sub/clip.mp4"), which survives
    // filterFilesByAccept; DRAG-DROP Files have an empty webkitRelativePath, so
    // readDroppedEntries stamps file._folderName during the recursive walk. The
    // label is always the FIRST path segment, so nested subfolders collapse under
    // the one top-level folder ("one folder, only videos").
    function folderNameOf(file) {
        if (file && file._folderName) return file._folderName;
        var rel = file && file.webkitRelativePath;
        if (rel && rel.indexOf('/') !== -1) return rel.slice(0, rel.indexOf('/'));
        return '';
    }

    // Run async tasks over `items` with at most `limit` in flight at once.
    // CRITICAL for folder uploads: php-fpm here has only pm.max_children=5 workers
    // for the WHOLE site, so firing every file in a folder at once exhausts the
    // pool — uploads get cut mid-stream (UPLOAD_ERR_PARTIAL 422 / 502 / 504) and
    // the portal stalls for everyone. That's why a few hand-picked files upload
    // fine but "a folder of videos" fails. Large video uploads are bandwidth-bound
    // anyway, so parallelism wouldn't speed them up. `taskFn(item, index)` MUST
    // resolve (never reject); resolves once every item has settled.
    function runWithConcurrency(items, limit, taskFn) {
        return new Promise(function (resolve) {
            var n = items.length;
            if (!n) { resolve(); return; }
            var idx = 0, active = 0, done = 0;
            function pump() {
                while (active < limit && idx < n) {
                    var cur = idx++;
                    active++;
                    Promise.resolve(taskFn(items[cur], cur)).then(function () {
                        active--; done++;
                        if (done >= n) resolve();
                        else pump();
                    });
                }
            }
            pump();
        });
    }

    // Max simultaneous uploads — kept well below php-fpm's pm.max_children (5) so a
    // folder upload can never monopolise the pool or stall the site for others.
    var UPLOAD_CONCURRENCY = 2;

    // Flatten one level of arrays without Array.prototype.flat (older browsers).
    function _flattenFileArrays(arrays) {
        return Array.prototype.concat.apply([], arrays);
    }

    // Drain a DirectoryReader fully. readEntries() returns at most ~100 entries
    // per call in Chrome, so we keep calling the SAME reader until it hands back
    // an empty batch — otherwise files silently go missing in large folders.
    function readAllDirectoryEntries(reader) {
        var all = [];
        return new Promise(function (resolve) {
            function pump() {
                reader.readEntries(function (batch) {
                    if (!batch.length) { resolve(all); return; }
                    all = all.concat(Array.prototype.slice.call(batch));
                    pump();
                }, function () { resolve(all); }); // read error — stop with what we have
            }
            pump();
        });
    }

    // Resolve ONE FileSystemEntry to a flat File[]. Recurses into directories.
    // `topName` is the FIRST-level folder label, threaded down so every file
    // (however deeply nested) is stamped with file._folderName = topName.
    // Errors resolve to [] so one unreadable file can't abort the whole drop.
    function readEntryRecursive(entry, topName) {
        if (!entry) return Promise.resolve([]);
        if (entry.isFile) {
            return new Promise(function (resolve) {
                entry.file(function (file) {
                    // File objects accept arbitrary JS props; this rides along
                    // through filterFilesByAccept + uploadOne to the server.
                    if (topName) { try { file._folderName = topName; } catch (e) {} }
                    resolve([file]);
                }, function () { resolve([]); });
            });
        }
        if (entry.isDirectory) {
            return readAllDirectoryEntries(entry.createReader())
                .then(function (children) {
                    return Promise.all(children.map(function (child) {
                        return readEntryRecursive(child, topName);
                    }));
                })
                .then(_flattenFileArrays);
        }
        return Promise.resolve([]);
    }

    // Read a drop event's DataTransfer into a flat File[], expanding any dropped
    // folder(s) recursively via the webkitGetAsEntry/readEntries API. Falls back
    // to dataTransfer.files when the entries API is unavailable or nothing dropped
    // was a directory. NOTE: the items list is emptied once the drop handler
    // returns, so entries (and the fallback file snapshot) are captured
    // synchronously here, before any async work.
    function readDroppedEntries(dataTransfer) {
        var filesSnapshot = dataTransfer ? Array.from(dataTransfer.files) : [];
        var items = dataTransfer && dataTransfer.items;
        if (!items || !items.length) return Promise.resolve(filesSnapshot);
        var entries = [];
        var hasDir = false;
        for (var i = 0; i < items.length; i++) {
            var entry = items[i].webkitGetAsEntry ? items[i].webkitGetAsEntry() : null;
            if (entry) {
                entries.push(entry);
                if (entry.isDirectory) hasDir = true;
            }
        }
        if (!hasDir) return Promise.resolve(filesSnapshot); // plain multi-file drop
        return Promise.all(entries.map(function (entry) {
            // Only directory drops carry a folder label; loose files dropped
            // alongside a folder stay label-less (→ loose cards).
            var topName = entry.isDirectory ? entry.name : '';
            return readEntryRecursive(entry, topName);
        }))
            .then(_flattenFileArrays)
            .then(function (files) { return files.length ? files : filesSnapshot; });
    }

    function startOfWeek(date) {
        var d = new Date(date);
        var day = d.getDay();
        var diff = day === 0 ? -6 : 1 - day;
        d.setDate(d.getDate() + diff);
        d.setHours(0, 0, 0, 0);
        return d;
    }
    function dateKey(d) {
        var y = d.getFullYear(), m = d.getMonth() + 1, day = d.getDate();
        return y + '-' + (m < 10 ? '0' : '') + m + '-' + (day < 10 ? '0' : '') + day;
    }

    function getDailyConfig() { return config.dailyReports || {}; }
    function getKpiConfig() { return config.kpi || {}; }

    function dashboardToneClass(tone) {
        if (tone === 'green') return 'dash-status-green';
        if (tone === 'red') return 'dash-status-red';
        return 'dash-status-grey';
    }

    function dashMeetingModuleId(meetingKey, recurrence, dayName) {
        if (recurrence !== 'daily_weekdays' || dayName === 'Monday') return meetingKey;
        return meetingKey + '-' + dayName.slice(0, 3).toLowerCase();
    }

    /* ──────────────────────────────────────────────────────────────────
     * Friday Work-Quality Review widget (managers only)
     * Data from GET /api/manager-review — non-managers get null (403 caught).
     * ────────────────────────────────────────────────────────────────── */

    var FWR_CATEGORIES = [
        { key: 'deliverables', label: 'Deliverables' },
        { key: 'quality', label: 'Quality of Work' }
    ];

    function buildFridayReviewHtml(data) {
        if (!data || !Array.isArray(data.weeks) || data.weeks.length === 0) return '';
        var cards = data.weeks.map(buildReviewWeekCardHtml).join('');
        if (!cards) return '';
        return '<div id="friday-review" class="fwr-section">' + cards + '</div>';
    }

    function buildReviewWeekCardHtml(week) {
        if (!week || !Array.isArray(week.subordinates) || week.subordinates.length === 0) return '';

        var rangeLabel = week.weekRange || week.weekLabel || week.weekKey;
        var badge = week.isOverdue
            ? '<span class="fwr-overdue-badge">Overdue</span>'
            : '<span class="fwr-pending-badge">This week</span>';

        var subtitle = week.isOverdue
            ? 'Pending submission for week of ' + escapeHtml(rangeLabel) + '. Please complete it now.'
            : 'Week of ' + escapeHtml(rangeLabel) + ' · Rate each team member before you sign off.';

        var rows = week.subordinates.map(function (s, idx) {
            // A report already rated this week is locked: its stars are immutable
            // (the backend silently ignores re-rating them), so we pre-fill and
            // disable them rather than asking the manager to re-rate. This is the
            // common case after a team reorg, where only the transferred-in
            // reports are still pending.
            var locked = !!s.alreadyRated;
            var lockedVals = { deliverables: Number(s.ratingDeliverables) || 0, quality: Number(s.ratingQuality) || 0 };
            var catRows = FWR_CATEGORIES.map(function (cat) {
                var current = locked ? lockedVals[cat.key] : 0;
                var stars = '';
                for (var i = 1; i <= 5; i++) {
                    var on = i <= current ? ' fwr-star-on' : '';
                    stars += '<button type="button" class="fwr-star' + on + '" data-row="' + idx + '" data-cat="' + cat.key + '" data-value="' + i + '"' + (locked ? ' disabled' : '') + '>★</button>';
                }
                return '<div class="fwr-cat-row"><span class="fwr-cat-label">' + escapeHtml(cat.label) + '</span><div class="fwr-stars" data-row="' + idx + '" data-cat="' + cat.key + '">' + stars + '</div></div>';
            }).join('');
            return '<div class="fwr-row' + (locked ? ' fwr-row-rated' : '') + '" data-sub-id="' + s.id + '" data-row="' + idx + '">' +
                '<div class="fwr-person">' +
                    '<span class="fwr-name">' + escapeHtml(s.name) + '</span>' +
                    '<span class="fwr-role">' + escapeHtml(s.role || '') + '</span>' +
                    (locked ? '<span class="fwr-rated-tag">Already rated</span>' : '') +
                '</div>' +
                '<div class="fwr-cats">' + catRows + '</div>' +
                '</div>';
        }).join('');

        var classes = 'fwr-card' + (week.isOverdue ? ' fwr-card-overdue' : '');

        return '<div class="' + classes + '" data-week-key="' + escapeHtml(week.weekKey) + '">' +
            '<div class="fwr-head">' +
                '<div class="fwr-head-text">' +
                    '<h3>Work Quality Review</h3>' +
                    '<div class="fwr-sub">' + subtitle + '</div>' +
                '</div>' +
                badge +
            '</div>' +
            '<div class="fwr-rows">' + rows + '</div>' +
            '<div class="fwr-footer">' +
                '<span class="fwr-status"></span>' +
                '<button type="button" class="btn btn-primary fwr-submit" disabled>Submit reviews</button>' +
            '</div>' +
        '</div>';
    }

    function wireFridayReviewHandlers(root, data) {
        if (!data || !Array.isArray(data.weeks)) return;
        data.weeks.forEach(function (week) {
            var card = root.querySelector('.fwr-card[data-week-key="' + week.weekKey + '"]');
            if (card) wireReviewWeekCard(card, week);
        });
    }

    function wireReviewWeekCard(card, week) {
        var state = week.subordinates.map(function (s) {
            if (s.alreadyRated) {
                // Locked: seed with the submitted stars so it counts as rated and
                // the manager only has to rate the still-pending reports.
                return {
                    id: s.id,
                    deliverables: Number(s.ratingDeliverables) || 0,
                    quality: Number(s.ratingQuality) || 0,
                    locked: true
                };
            }
            return { id: s.id, deliverables: 0, quality: 0, locked: false };
        });

        var statusEl = card.querySelector('.fwr-status');
        var submitBtn = card.querySelector('.fwr-submit');

        function refreshSubmitState() {
            var allRated = state.every(function (r) { return r.deliverables >= 1 && r.quality >= 1; });
            submitBtn.disabled = !allRated;
            statusEl.textContent = allRated
                ? 'Ready to submit.'
                : 'Rate both categories for every team member to enable Submit.';
        }

        card.querySelectorAll('.fwr-stars').forEach(function (starsEl) {
            var row = Number(starsEl.getAttribute('data-row'));
            var cat = starsEl.getAttribute('data-cat');
            if (state[row] && state[row].locked) return; // already rated — immutable
            starsEl.querySelectorAll('.fwr-star').forEach(function (star) {
                star.addEventListener('click', function () {
                    var value = Number(star.getAttribute('data-value'));
                    if (state[row][cat] === value) value = 0;
                    state[row][cat] = value;
                    starsEl.querySelectorAll('.fwr-star').forEach(function (s) {
                        s.classList.toggle('fwr-star-on', Number(s.getAttribute('data-value')) <= value);
                    });
                    refreshSubmitState();
                });
            });
        });

        submitBtn.addEventListener('click', async function () {
            submitBtn.disabled = true;
            submitBtn.textContent = 'Submitting…';
            try {
                var payload = {
                    week_key: week.weekKey,
                    items: state.map(function (r) {
                        return {
                            subordinate_id: r.id,
                            rating_deliverables: r.deliverables,
                            rating_quality: r.quality,
                        };
                    }),
                };
                await requestJson('/api/manager-review', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload),
                });
                submitBtn.textContent = '✓ Submitted';
                statusEl.textContent = 'Reviews submitted successfully.';
                card.classList.remove('fwr-card-overdue');
                card.classList.add('fwr-card-submitted');
                card.querySelectorAll('.fwr-star').forEach(function (s) { s.disabled = true; });
                setTimeout(function () {
                    card.classList.add('fwr-card-fading');
                    setTimeout(function () {
                        if (card.parentNode) card.parentNode.removeChild(card);
                    }, 350);
                }, 1500);
            } catch (e) {
                submitBtn.disabled = false;
                submitBtn.textContent = 'Submit reviews';
                statusEl.textContent = 'Submit failed: ' + (e.message || 'error');
            }
        });

        refreshSubmitState();
    }

    /* ──────────────────────────────────────────────────────────────────
     * Creative category — Krishnan/Kishore set a daily work-focus note that
     * their direct reports see on the dashboard + as a modal on sign-in.
     * Data from GET /api/creative-category: { setter|null, viewer|null }.
     * A user can be both (e.g. Kishore: sets his sub-team's, sees Krishnan's).
     * ────────────────────────────────────────────────────────────────── */

    function buildCreativeCategoryHtml(cc) {
        if (!cc || (!cc.setter && !cc.viewer)) return '';
        var html = '';

        // Viewer card — what your manager set (read-only). Title reflects scope.
        if (cc.viewer && cc.viewer.category) {
            var vTitle = cc.viewer.scope === 'week' ? 'This Week’s Focus' : 'Today’s Focus';
            html += '<div class="dash-focus-card dash-focus-viewer">' +
                '<div class="dash-focus-head"><span class="dash-focus-icon">🎬</span><span class="dash-focus-title">' + vTitle + '</span></div>' +
                '<div class="dash-focus-category">' + escapeHtml(cc.viewer.category) + '</div>' +
                '<div class="dash-focus-by">— ' + escapeHtml(cc.viewer.authorName || 'your manager') +
                    (cc.viewer.setOn ? ' · ' + escapeHtml(cc.viewer.setOn) : '') + '</div>' +
            '</div>';
        }

        // Setter card — your own input for your team (editable any day).
        if (cc.setter) {
            var cur = cc.setter.category || '';
            var sIsWeek = cc.setter.scope === 'week';
            html += '<div class="dash-focus-card dash-focus-setter" id="dashFocusCard">' +
                '<div class="dash-focus-head"><span class="dash-focus-icon">🎬</span><span class="dash-focus-title">Your team’s creative category</span></div>' +
                '<div class="dash-focus-sub">Your direct reports see this on their dashboard. Set it for today or the whole week.</div>' +
                '<div class="cc-scope-group" role="radiogroup" aria-label="Focus scope">' +
                    '<label class="cc-scope-opt"><input type="radio" name="dashFocusScope" value="day"' + (sIsWeek ? '' : ' checked') + '><span>Today</span></label>' +
                    '<label class="cc-scope-opt"><input type="radio" name="dashFocusScope" value="week"' + (sIsWeek ? ' checked' : '') + '><span>This week</span></label>' +
                '</div>' +
                '<textarea id="dashFocusInput" class="dash-focus-input" rows="2" maxlength="500" placeholder="e.g. You will be working on lip-syncing videos today">' + escapeHtml(cur) + '</textarea>' +
                '<div class="dash-focus-actions">' +
                    '<span class="dash-focus-status" id="dashFocusStatus">' + (cc.setter.setOn ? ('Last set ' + escapeHtml(cc.setter.setOn)) : 'Not set yet') + '</span>' +
                    '<button type="button" class="btn btn-primary dash-focus-save" id="dashFocusSave">Save</button>' +
                '</div>' +
            '</div>';
        }
        return html;
    }

    // Read the selected scope ('day'|'week') from a named radio group. Defaults
    // to 'day' if the group isn't present (e.g. a viewer-only render).
    function readFocusScope(scopeName) {
        var el = scopeName && document.querySelector('input[name="' + scopeName + '"]:checked');
        return (el && el.value === 'week') ? 'week' : 'day';
    }

    // Shared save — POSTs the category (+scope) and reflects the result on the
    // dashboard card (if present). Used by both the inline card and the modal.
    function saveCreativeCategory(input, btn, statusEl, cc, onDone, scopeName) {
        var val = (input && input.value || '').trim();
        if (!val) { if (statusEl) statusEl.textContent = 'Enter a category first'; if (input) input.focus(); return; }
        var scope = readFocusScope(scopeName);
        if (btn) { btn.disabled = true; btn.textContent = 'Saving…'; }
        requestJson('/api/creative-category', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ category: val, scope: scope }) })
            .then(function (d) {
                if (cc && cc.setter) { cc.setter.category = d.category; cc.setter.setOn = d.setOn; cc.setter.isToday = d.isToday; cc.setter.scope = d.scope; cc.setter.isCurrent = d.isCurrent; }
                var cardInput = document.getElementById('dashFocusInput');
                if (cardInput && cardInput !== input) cardInput.value = d.category;
                // Keep the dashboard card's scope radios in sync after a modal save.
                var cardScope = document.querySelector('input[name="dashFocusScope"][value="' + d.scope + '"]');
                if (cardScope) cardScope.checked = true;
                var cardStatus = document.getElementById('dashFocusStatus');
                if (cardStatus) cardStatus.textContent = 'Saved ✓ · ' + (d.scope === 'week' ? 'this week' : d.setOn);
                if (btn) { btn.disabled = false; btn.textContent = 'Save'; }
                if (statusEl && statusEl !== cardStatus) statusEl.textContent = 'Saved ✓';
                if (onDone) onDone();
            })
            .catch(function (e) {
                if (btn) { btn.disabled = false; btn.textContent = 'Save'; }
                if (statusEl) statusEl.textContent = (e && e.message) || 'Save failed';
            });
    }

    function wireCreativeCategory(root, cc, todayKey) {
        if (!cc || (!cc.setter && !cc.viewer)) return;

        var saveBtn = document.getElementById('dashFocusSave');
        if (saveBtn) saveBtn.addEventListener('click', function () {
            saveCreativeCategory(document.getElementById('dashFocusInput'), saveBtn, document.getElementById('dashFocusStatus'), cc, null, 'dashFocusScope');
        });

        // Sign-in modal — once per day (dismissal remembered like the morning quote).
        if (!window.__dashSignedIn) return;
        var dismissKey = 'tessa.creativeCategory.dismissed.' + todayKey;
        var dismissed = false;
        try { dismissed = localStorage.getItem(dismissKey) === '1'; } catch (e) {}
        if (dismissed) return;
        // Nothing to show: not a setter, and manager hasn't set anything.
        if (!cc.setter && !(cc.viewer && cc.viewer.category)) return;
        // A setter who already set a still-current WEEKLY focus isn't nagged to
        // re-post each day this week — unless their own manager posted a note to
        // surface. (A day-scoped focus still prompts the next day.)
        if (cc.setter && cc.setter.scope === 'week' && cc.setter.isCurrent && !(cc.viewer && cc.viewer.category)) return;
        showCreativeCategoryModal(cc, dismissKey);
    }

    function showCreativeCategoryModal(cc, dismissKey) {
        if (document.getElementById('ccModalOverlay')) return; // already open — avoid stacking on re-render
        var hasViewer = cc.viewer && cc.viewer.category;
        var hasSetter = !!cc.setter;

        var bodyParts = [];
        if (hasViewer) {
            var mvLabel = (cc.viewer.scope === 'week' ? 'This week’s focus from ' : 'Today’s focus from ') + escapeHtml(cc.viewer.authorName || 'your manager');
            bodyParts.push(
                '<div class="cc-modal-section">' +
                    '<div class="cc-modal-label">' + mvLabel + '</div>' +
                    '<div class="dash-focus-modal-category">' + escapeHtml(cc.viewer.category) + '</div>' +
                '</div>'
            );
        }
        if (hasSetter) {
            var msIsWeek = cc.setter && cc.setter.scope === 'week';
            bodyParts.push(
                '<div class="cc-modal-section">' +
                    '<div class="cc-modal-label">Set your team’s creative category</div>' +
                    '<div class="cc-scope-group" role="radiogroup" aria-label="Focus scope">' +
                        '<label class="cc-scope-opt"><input type="radio" name="ccModalScope" value="day"' + (msIsWeek ? '' : ' checked') + '><span>Today</span></label>' +
                        '<label class="cc-scope-opt"><input type="radio" name="ccModalScope" value="week"' + (msIsWeek ? ' checked' : '') + '><span>This week</span></label>' +
                    '</div>' +
                    '<textarea id="ccModalInput" class="dash-focus-input" rows="3" maxlength="500" placeholder="e.g. You will be working on lip-syncing videos today">' + escapeHtml((cc.setter && cc.setter.category) || '') + '</textarea>' +
                    '<div class="cc-modal-hint">Your direct reports will see this on their dashboard.</div>' +
                '</div>'
            );
        }

        var footer = hasSetter
            ? '<button type="button" class="btn btn-outline" id="ccModalLater">Later</button><button type="button" class="btn btn-primary" id="ccModalSave">Save</button>'
            : '<button type="button" class="btn btn-primary" id="ccModalOk">Got it</button>';
        var title = (hasSetter && !hasViewer)
            ? 'Set your creative category'
            : ((hasViewer && cc.viewer.scope === 'week') ? 'This week’s focus' : 'Today’s focus');

        var overlay = document.createElement('div');
        overlay.className = 'mtg-modal-overlay';
        overlay.id = 'ccModalOverlay';
        overlay.innerHTML = '<div class="mtg-modal cc-modal">' +
            '<div class="mtg-modal-header"><h3 class="mtg-modal-title">🎬 ' + escapeHtml(title) + '</h3><button type="button" class="mtg-modal-close" id="ccModalClose">✕</button></div>' +
            '<div class="mtg-modal-body">' + bodyParts.join('') + '</div>' +
            '<div class="mtg-modal-footer">' + footer + '</div>' +
        '</div>';
        document.body.appendChild(overlay);

        function close() {
            try { localStorage.setItem(dismissKey, '1'); } catch (e) {}
            overlay.remove();
        }
        overlay.querySelector('#ccModalClose').addEventListener('click', close);
        var later = overlay.querySelector('#ccModalLater'); if (later) later.addEventListener('click', close);
        var ok = overlay.querySelector('#ccModalOk'); if (ok) ok.addEventListener('click', close);
        overlay.addEventListener('click', function (e) { if (e.target === overlay) close(); });
        var save = overlay.querySelector('#ccModalSave');
        if (save) save.addEventListener('click', function () {
            saveCreativeCategory(overlay.querySelector('#ccModalInput'), save, null, cc, close, 'ccModalScope');
        });
    }

    // Turn the sign-off status `items` (returned in the 422 body when sign-off is
    // blocked) into a human bullet list of exactly what's still pending, so the
    // "cannot sign off" alert names each blocker instead of being generic. Returns
    // '' when there's nothing usable so callers can fall back to the raw message.
    function buildPendingItemsText(items) {
        if (!Array.isArray(items)) return '';
        var blocking = items.filter(function (it) { return it && it.blocks; });
        if (!blocking.length) return '';
        return blocking.map(function (it) {
            var line = '• ' + (it.label || it.type || 'Item');
            if (it.detail) line += ' — ' + it.detail;
            // Daily Report hides which fields are empty behind "N of M filled" —
            // name them so people don't have to hunt.
            if (it.type === 'daily_report' && Array.isArray(it.missing) && it.missing.length) {
                line += '\n   Missing: ' + it.missing.map(function (m) { return m.label || m.key; }).join(', ');
            }
            return line;
        }).join('\n');
    }

    // "Name" <email> → Name; bare email → the email. Used on Gmail cards; the
    // detail modal still shows the full raw sender. Shared by the dashboard
    // Gmail tab and the Archives Gmail history.
    function gmailSenderName(raw) {
        raw = (raw || '').trim();
        if (!raw) return '';
        var m = raw.match(/^\s*"?([^"<]*?)"?\s*<([^>]+)>\s*$/);
        if (m) {
            var name = (m[1] || '').trim();
            return name || (m[2] || '').trim();
        }
        return raw;
    }

    // Decode a base64url Gmail body part to UTF-8 text.
    function gmailB64UrlToUtf8(data) {
        try {
            var b64 = String(data || '').replace(/-/g, '+').replace(/_/g, '/');
            var bin = atob(b64);
            var bytes = new Uint8Array(bin.length);
            for (var i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
            return new TextDecoder('utf-8').decode(bytes);
        } catch (e) { return ''; }
    }

    // Walk a Gmail message payload and return the best body for display: prefer
    // the text/html part (rendered properly in a sandboxed iframe), else the
    // text/plain part. Returns { type: 'html' | 'text' | '', value }.
    function gmailExtractBody(payload) {
        var plain = '', html = '';
        (function walk(p) {
            if (!p || (plain && html)) return;
            var mt = (p.mimeType || '').toLowerCase();
            var data = p.body && p.body.data;
            if (data) {
                if (mt === 'text/html' && !html) html = gmailB64UrlToUtf8(data);
                else if (mt === 'text/plain' && !plain) plain = gmailB64UrlToUtf8(data);
            }
            if (p.parts) for (var i = 0; i < p.parts.length; i++) walk(p.parts[i]);
        })(payload);
        if (html.trim()) return { type: 'html', value: html };
        if (plain.trim()) return { type: 'text', value: plain.trim() };
        return { type: '', value: '' };
    }

    // Escape text, then turn URLs / www. / bare emails into clickable links
    // (new tab). Single pass so a URL containing an "@" isn't re-matched as an
    // email. Safe: anchors are only added to already-escaped text.
    function gmailLinkify(text) {
        var safe = escapeHtml(String(text || ''));
        var re = /(https?:\/\/[^\s<]+|www\.[^\s<]+)|([A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,})/gi;
        return safe.replace(re, function (match, url, email) {
            if (url) {
                var trail = '';
                var t = url.match(/[.,;:!?)\]}'"]+$/);   // keep sentence punctuation out of the link
                if (t) { trail = t[0]; url = url.slice(0, -t[0].length); }
                var href = /^www\./i.test(url) ? 'http://' + url : url;
                return '<a href="' + href + '" target="_blank" rel="noopener noreferrer">' + url + '</a>' + trail;
            }
            return '<a href="mailto:' + email + '">' + email + '</a>';
        });
    }

    // Wrap an email's HTML in a locked-down document for a sandboxed iframe. The
    // CSP blocks every remote load (scripts, styles, remote/tracker images, CSS
    // url() backgrounds, webfonts) — only embedded data: images render — while
    // keeping the email's own inline styles. <base target=_blank> opens its links
    // in a new tab. (The iframe itself carries no allow-scripts/allow-same-origin.)
    function gmailIframeSrcdoc(html) {
        return '<!doctype html><html><head><meta charset="utf-8">' +
            '<base target="_blank">' +
            '<meta http-equiv="Content-Security-Policy" content="default-src \'none\'; img-src data:; style-src \'unsafe-inline\'; font-src data:">' +
            '<style>html,body{margin:0}body{background:#fff;color:#1f2937;font:14px/1.55 -apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;padding:14px;word-break:break-word;overflow-wrap:anywhere}img{max-width:100%;height:auto}a{color:#1a56db}table{max-width:100%}</style>' +
            '</head><body>' + String(html || '') + '</body></html>';
    }

    // Stored received_at is an ISO datetime (UTC) → readable IST.
    function gmailFormatDateTime(val) {
        if (!val) return '';
        var d = new Date(val);
        if (isNaN(d.getTime())) return '';
        return d.toLocaleString('en-IN', {
            timeZone: 'Asia/Kolkata',
            day: '2-digit', month: 'short', year: 'numeric',
            hour: '2-digit', minute: '2-digit',
        });
    }

    // Read-only Gmail detail modal opened from a card. Shows the REAL email —
    // the stored snippet instantly, then the full body fetched live: HTML
    // rendered in a sandboxed, script-disabled, CSP-locked iframe (tracker images
    // blocked), or plain text linkified — plus category / priority / received
    // meta. Backs both the dashboard Gmail tab and the Archives history.
    function openGmailInsightDetails(g) {
        var existing = document.getElementById('gmDetailOverlay');
        if (existing) existing.remove();

        function metaRow(label, value) {
            if (!value) return '';
            return '<div class="mi-detail-row"><span class="mi-detail-label">' + escapeHtml(label) + '</span>' +
                '<span class="mi-detail-value">' + escapeHtml(value) + '</span></div>';
        }
        var rowsHtml = metaRow('From', g.sender || '') +
            metaRow('Category', g.category || '') +
            metaRow('Priority', g.priority || '') +
            metaRow('Received', gmailFormatDateTime(g.received_at));

        var overlay = document.createElement('div');
        overlay.id = 'gmDetailOverlay';
        overlay.className = 'mtg-modal-overlay';
        overlay.innerHTML =
            '<div class="mtg-modal mi-detail-modal">' +
                '<div class="mtg-modal-header">' +
                    '<h3 class="mtg-modal-title">📧 ' + escapeHtml(g.subject || '(no subject)') + '</h3>' +
                    '<button type="button" class="mtg-modal-close" id="gmDetailClose">&#x2715;</button>' +
                '</div>' +
                '<div class="mtg-modal-body mi-detail-body">' +
                    '<div id="gmDetailBody" class="mi-detail-desc gm-detail-body"></div>' +
                    '<div id="gmDetailLoading" class="gm-detail-loading">Loading full email…</div>' +
                    '<div class="mi-detail-meta">' + rowsHtml + '</div>' +
                '</div>' +
            '</div>';
        document.body.appendChild(overlay);

        // Seed with the stored snippet (linkified). innerHTML is safe here —
        // gmailLinkify escapes first and only adds our own anchor tags.
        var bodyEl = overlay.querySelector('#gmDetailBody');
        bodyEl.innerHTML = gmailLinkify(g.snippet || '');

        function onKey(e) { if (e.key === 'Escape') closeModal(); }
        function closeModal() {
            var el = document.getElementById('gmDetailOverlay');
            if (el) el.remove();
            document.removeEventListener('keydown', onKey);
        }
        document.addEventListener('keydown', onKey);
        overlay.querySelector('#gmDetailClose').onclick = closeModal;
        overlay.onclick = function (e) { if (e.target === overlay) closeModal(); };

        // Fetch the real, full email body and swap it in.
        function clearLoading() {
            var l = overlay.querySelector('#gmDetailLoading');
            if (l) l.remove();
        }
        function showPreviewNote() {
            if (overlay.querySelector('#gmDetailNote')) return;
            var note = document.createElement('div');
            note.id = 'gmDetailNote';
            note.className = 'gm-detail-loading';
            note.textContent = '(preview — couldn’t load full email)';
            bodyEl.parentNode.insertBefore(note, bodyEl.nextSibling);
        }
        if (!g.gmail_message_id) { clearLoading(); return; }
        requestJson('/api/google/gmail/messages/' + encodeURIComponent(g.gmail_message_id))
            .then(function (resp) {
                if (!document.body.contains(bodyEl)) return;   // modal closed or replaced mid-fetch
                var body = gmailExtractBody(resp && resp.data && resp.data.payload);
                if (body.type === 'html') {
                    var frame = document.createElement('iframe');
                    frame.className = 'gm-detail-frame';
                    frame.setAttribute('sandbox', 'allow-popups allow-popups-to-escape-sandbox');
                    frame.srcdoc = gmailIframeSrcdoc(body.value);   // property assign → no markup breakout
                    bodyEl.classList.remove('mi-detail-desc', 'gm-detail-body');
                    bodyEl.textContent = '';
                    bodyEl.appendChild(frame);
                } else if (body.type === 'text') {
                    bodyEl.innerHTML = gmailLinkify(body.value);
                } else {
                    showPreviewNote();
                }
                clearLoading();
            })
            .catch(function () {
                if (!document.body.contains(bodyEl)) return;
                clearLoading();
                showPreviewNote();
            });
    }

    async function renderDashboard() {
        var root = document.getElementById('dashboardView');
        if (!root) return;

        // Reset scroll. Sign-off re-renders the dashboard from a tall
        // working view (lots of cards) into a short signed-off view (just
        // the cat). Without this, the user's old scroll position is below
        // the new content and they see an empty page.
        try { window.scrollTo({ top: 0, behavior: 'instant' }); }
        catch (e) { window.scrollTo(0, 0); }
        root.scrollTop = 0;

        var today = new Date();
        var dateLabel = today.toLocaleDateString('en-IN', { timeZone: 'Asia/Kolkata', weekday: 'long', day: '2-digit', month: 'short' });
        // Awake cat (for loading)
        var catSvg = '<svg class="dash-loading-cat" viewBox="0 0 80 80" width="120" height="120" fill="none" stroke="#3f3f46" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">' +
                '<path d="M20 32 L16 18 L26 26 Z"/>' +
                '<path d="M60 32 L64 18 L54 26 Z"/>' +
                '<circle cx="40" cy="36" r="16"/>' +
                '<circle cx="34" cy="33" r="1.5" fill="#3f3f46" stroke="none"/>' +
                '<circle cx="46" cy="33" r="1.5" fill="#3f3f46" stroke="none"/>' +
                '<path d="M38 38 Q40 40 42 38"/>' +
                '<line x1="26" y1="35" x2="18" y2="33"/>' +
                '<line x1="26" y1="37" x2="18" y2="38"/>' +
                '<line x1="54" y1="35" x2="62" y2="33"/>' +
                '<line x1="54" y1="37" x2="62" y2="38"/>' +
                '<path d="M30 50 Q32 65 40 68 Q48 65 50 50"/>' +
                '<path d="M50 58 Q56 62 62 56"/>' +
            '</svg>';

        // Tessa the neon-outline cat — a single 2D clip (public/video/tessa-cat-wake.mp4)
        // that opens on the resting cat and, on sign-in, wakes and walks off to the
        // right. Paused on frame 0 it is the idle pose; the click handlers below play
        // it (sped up) as the wake animation. `mix-blend-mode: screen` (CSS) drops the
        // clip's pure-black background so only the neon lines show over the near-black
        // dashboard. One cat everywhere — see cat_signin_plan.md for why the earlier
        // two-illustration (sleeping PNG + awake SVG) approach was abandoned.
        var sleepCatSvg = '<video class="dash-sleep-cat-img dash-cat-video" id="dashCatVideo" muted playsinline preload="auto" width="380" height="228" aria-hidden="true">' +
                '<source src="/video/tessa-cat-wake.mp4?v=2" type="video/mp4">' +
            '</video>';

        // First name extracted from config.userName, used in the wake greeting
        // and the "see you tomorrow" message so they feel personal.
        var firstName = (config.userName || 'there').split(' ')[0];

        // Awake-cat line-art used only by the "Sign off for today" pill in
        // the signed-in header. (Style mismatch with the sleeping PNG is
        // acknowledged v2 debt — see cat_signin_plan.md.)
        var awakeCatHeaderSvg = '<svg viewBox="0 0 80 80" width="28" height="28" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
                '<path d="M20 32 L16 18 L26 26 Z"/>' +
                '<path d="M60 32 L64 18 L54 26 Z"/>' +
                '<circle cx="40" cy="36" r="16"/>' +
                '<circle cx="34" cy="33" r="1.5" fill="currentColor" stroke="none"/>' +
                '<circle cx="46" cy="33" r="1.5" fill="currentColor" stroke="none"/>' +
                '<path d="M38 38 Q40 40 42 38"/>' +
                '<line x1="26" y1="35" x2="18" y2="33"/>' +
                '<line x1="26" y1="37" x2="18" y2="38"/>' +
                '<line x1="54" y1="35" x2="62" y2="33"/>' +
                '<line x1="54" y1="37" x2="62" y2="38"/>' +
                '<path d="M30 50 Q32 65 40 68 Q48 65 50 50"/>' +
                '<path d="M50 58 Q56 62 62 56"/>' +
            '</svg>';

        // ── Cat-wake video helpers (shared by the sign-in and undo-sign-off
        //    handlers below). The clip plays once, sped up, on an explicit user
        //    click, so it stays within prefers-reduced-motion intent — which we
        //    still honour by skipping playback and resolving quickly. ──
        var CAT_VIDEO_SECONDS = 5.034;   // source clip length (from the MP4 metadata)
        var CAT_WAKE_SPEED = 1.5;        // play the wake+walk a bit faster than real-time
        var prefersReducedMotion = (function () {
            try { return window.matchMedia('(prefers-reduced-motion: reduce)').matches; }
            catch (e) { return false; }
        })();

        // Paint the resting first frame without playing it (the idle pose).
        function primeCatVideo(video) {
            if (!video) return;
            var paint = function () { try { video.currentTime = 0; } catch (e) {} };
            if (video.readyState >= 2) paint();
            else video.addEventListener('loadeddata', paint, { once: true });
        }

        // Play the wake+walk clip once. onProgress(fraction 0..1) fires as it
        // plays (used to reveal the quote "meanwhile"); onEnd fires exactly once
        // when the clip finishes or is skipped. Never throws — sign-in must not
        // hang on a video glitch (the caller also arms a wall-clock fallback).
        function playCatVideo(video, opts) {
            opts = opts || {};
            var onProgress = opts.onProgress || function () {};
            var onEnd = opts.onEnd || function () {};
            var done = false;
            var finish = function () { if (done) return; done = true; onEnd(); };
            if (!video || prefersReducedMotion) {
                onProgress(1);
                setTimeout(finish, prefersReducedMotion ? 350 : 0);
                return;
            }
            video.addEventListener('ended', finish, { once: true });
            video.addEventListener('timeupdate', function () {
                if (video.duration) onProgress(video.currentTime / video.duration);
            });
            try {
                video.currentTime = 0;
                video.playbackRate = CAT_WAKE_SPEED;
                var p = video.play();
                if (p && p.catch) p.catch(function () { /* caller's wall-clock fallback finishes it */ });
            } catch (e) { /* caller's wall-clock fallback finishes it */ }
        }

        // Check if dashboard is enabled — persists via DailySignin/DailySignoff tables.
        //
        // Always re-fetch from the server, because the inlined config.signedInToday/
        // signedOffToday flags are baked into the HTML and can be stale: a browser
        // (especially Safari/iOS) that serves the dashboard from disk cache after a
        // refresh would otherwise replay the morning's "not signed off" state and
        // show the sign-in toggle even after the user has signed off. Sooraj hit
        // this repeatedly. We keep the inlined flags as a fallback so the
        // dashboard still renders if /api/dashboard-state is unreachable.
        //
        // `cache: 'no-store'` plus the server's no-store header plus a per-request
        // cache-buster plus verifying the response's `date` field is today's
        // IST date is belt-and-suspenders-and-duct-tape: Safari/iOS still
        // heuristically caches the GET in some scenarios and replays a stale
        // "signedIn:true" response from a previous day, so the dashboard
        // renders "Sign off for today" before the user has actually signed in.
        // Sneha hit this 2026-05-25 — she saw the Sign-Off pill on first load
        // even though she had no DailySignin row for the day.
        var todayKey = dateKey(today);
        var todayIstKey = (function () {
            try { return new Date().toLocaleDateString('en-CA', { timeZone: 'Asia/Kolkata' }); }
            catch (_) { return todayKey; }
        })();
        var weeklyTimesheetDue = false;
        try {
            var state = await requestJson('/api/dashboard-state?_=' + Date.now(), { cache: 'no-store' });
            // Reject stale cached responses where the server-reported date
            // doesn't match today's IST date. Drop into catch → fall back to
            // the PHP-baked config flags (regenerated fresh per page-load).
            if (!state || state.date !== todayIstKey) {
                throw new Error('stale dashboard-state response (date=' + (state && state.date) + ', expected=' + todayIstKey + ')');
            }
            window.__dashSignedIn = !!state.signedIn;
            window.__dashSignedOff = !!state.signedOff;
            weeklyTimesheetDue = !!state.weeklyTimesheetDue;
        } catch (e) {
            if (window.__dashSignedIn === undefined) {
                window.__dashSignedIn = !!config.signedInToday;
            }
            if (window.__dashSignedOff === undefined) {
                window.__dashSignedOff = !!config.signedOffToday;
            }
        }

        // Keep the sidebar lock in sync with the state we just resolved — this
        // covers first paint plus every sign-in/off transition that routes back
        // through renderDashboard.
        applySigninLockUi();

        // Signed off for the day — show a closing-out view rather than the
        // working dashboard. Without this, a stale DailySignin record from
        // earlier in the day makes the toggle reappear and any second sign-off
        // attempt errors with "Already signed off".
        if (window.__dashSignedOff) {
            // The sleeping cat is clickable to wake + come back to work — same
            // mental model as the not-signed-in cat. confirm() preserves the
            // accidental-tap defense (stray phone taps on the signed-off cat
            // were resurrecting people's working state). Keep the explicit
            // "Signed off by mistake?" / Undo Sign-Off link below as a
            // discoverable secondary path for users who don't realise the cat
            // itself is interactive.
            root.innerHTML = '<div class="dash-wrap">' +
                '<div class="dash-header">' +
                '<div><h2>Dashboard</h2><div class="dash-meta">' + today.toLocaleDateString('en-IN', { timeZone: 'Asia/Kolkata', weekday: 'long', day: '2-digit', month: 'short' }) + '</div></div>' +
                '</div>' +
                '<div class="dash-cat-idle">' +
                    '<button type="button" class="dash-cat-wake-zone" id="dashUndoWakeZone" aria-label="Come back to today\'s working dashboard (undoes today\'s sign-off)" title="Click Tessa to come back to work">' +
                        sleepCatSvg +
                        '<span class="dash-cat-zzz" aria-hidden="true">💤</span>' +
                    '</button>' +
                    '<span class="dash-cat-greeting" id="dashCatGreeting" role="status">Welcome back, ' + escapeHtml(firstName) + '</span>' +
                    '<span class="dash-cat-msg">See you tomorrow, ' + escapeHtml(firstName) + '.</span>' +
                    '<div style="margin-top:16px;text-align:center;">' +
                        '<a href="#" id="dashUndoReveal" style="font-size:12px;color:#52525b;text-decoration:none;border-bottom:1px dashed #3f3f46;padding-bottom:1px;">Signed off by mistake?</a>' +
                        '<button type="button" class="signoff-undo-btn" id="dashUndoSignoffBtn" hidden>Undo Sign-Off</button>' +
                    '</div>' +
                '</div>' +
            '</div>';
            (function () {
                var undoWakeZone = document.getElementById('dashUndoWakeZone');
                var undoGreeting = document.getElementById('dashCatGreeting');
                var video = document.getElementById('dashCatVideo');
                if (!undoWakeZone) return;
                primeCatVideo(video);
                undoWakeZone.addEventListener('click', function () {
                    if (undoWakeZone.disabled) return;
                    if (!confirm('Come back to today\'s working dashboard?')) return;
                    undoWakeZone.disabled = true;
                    undoWakeZone.classList.add('is-waking');
                    if (undoGreeting) undoGreeting.classList.add('is-visible');

                    // Render only once BOTH the request has succeeded and the
                    // wake clip has finished (or been skipped/timed out).
                    var apiDone = false, videoDone = false, aborted = false;
                    var finish = function () {
                        if (aborted || !apiDone || !videoDone) return;
                        var idle = document.querySelector('.dash-cat-idle');
                        if (idle) idle.classList.add('is-finishing');
                        setTimeout(renderDashboard, 200);
                    };
                    playCatVideo(video, { onEnd: function () { videoDone = true; finish(); } });
                    setTimeout(function () { if (!videoDone) { videoDone = true; finish(); } },
                        Math.round((CAT_VIDEO_SECONDS / CAT_WAKE_SPEED) * 1000) + 600);

                    requestJson('/api/signoff', { method: 'DELETE' }).then(function () {
                        window.__dashSignedOff = false;
                        applySigninLockUi();
                        maybeMeow();
                        apiDone = true;
                        finish();
                    }).catch(function (err) {
                        aborted = true;
                        if (video) { try { video.pause(); video.currentTime = 0; } catch (e) {} }
                        undoWakeZone.classList.remove('is-waking');
                        if (undoGreeting) undoGreeting.classList.remove('is-visible');
                        undoWakeZone.disabled = false;
                        alert((err && err.message) ? err.message : 'Failed to wake Tessa.');
                    });
                });
            })();
            var revealLink = document.getElementById('dashUndoReveal');
            var undoBtn = document.getElementById('dashUndoSignoffBtn');
            if (revealLink && undoBtn) revealLink.addEventListener('click', function (e) {
                e.preventDefault();
                revealLink.hidden = true;
                undoBtn.hidden = false;
            });
            if (undoBtn) undoBtn.addEventListener('click', function () {
                if (!confirm('Undo today\'s sign-off?')) return;
                undoBtn.disabled = true;
                undoBtn.textContent = 'Undoing...';
                requestJson('/api/signoff', { method: 'DELETE' }).then(function () {
                    window.__dashSignedOff = false;
                    renderDashboard();
                }).catch(function (err) {
                    undoBtn.disabled = false;
                    undoBtn.textContent = 'Undo Sign-Off';
                    alert((err && err.message) ? err.message : 'Failed to undo sign-off');
                });
            });
            return;
        }

        var dashEnabled = window.__dashSignedIn;
        if (!dashEnabled) {
            root.innerHTML = '<div class="dash-wrap">' +
                '<div class="dash-header">' +
                '<div><h2>Dashboard</h2><div class="dash-meta">' + today.toLocaleDateString('en-IN', { timeZone: 'Asia/Kolkata', weekday: 'long', day: '2-digit', month: 'short' }) + '</div></div>' +
                '</div>' +
                '<div class="dash-cat-idle">' +
                    '<button type="button" class="dash-cat-wake-zone" id="dashCatWakeZone" aria-label="Sign in to start your day" title="Click Tessa to sign in">' +
                        sleepCatSvg +
                        '<span class="dash-cat-zzz" aria-hidden="true">💤</span>' +
                    '</button>' +
                    '<span class="dash-cat-greeting" id="dashCatGreeting" role="status">Good morning, ' + escapeHtml(firstName) + '</span>' +
                    '<blockquote class="dash-cat-quote" id="dashCatQuote" role="status" aria-live="polite" hidden></blockquote>' +
                    '<span class="dash-cat-msg" id="dreamStoryMsg">💤</span>' +
                '</div>' +
            '</div>';

            // Pre-fetch today's motivational quote in parallel with the
            // dream-story so it's already cached on window.__morningQuote
            // by the time the user clicks Tessa to sign in. Same quote is
            // reused as the banner on the working dashboard below.
            if (!window.__morningQuote) {
                requestJson('/api/tessa/morning-quote')
                    .then(function (data) {
                        if (data && data.quote) window.__morningQuote = data.quote;
                    })
                    .catch(function () { /* falls back at click time */ });
            }

            // Cat is the only click target. confirm() preserves the
            // accidental-tap defense (Sooraj kept hitting the old toggle by
            // accident on his phone). On click Tessa wakes and walks off to the
            // right (the clip, sped up); the motivational quote fades in partway
            // through so it appears while she's walking. The dashboard renders
            // only once BOTH the sign-in request and the clip have finished.
            (function () {
                var wakeZone = document.getElementById('dashCatWakeZone');
                var greeting = document.getElementById('dashCatGreeting');
                var quoteEl = document.getElementById('dashCatQuote');
                var video = document.getElementById('dashCatVideo');
                if (!wakeZone) return;
                primeCatVideo(video);

                var quoteShown = false;
                var showQuote = function () {
                    if (quoteShown || !quoteEl) return;
                    quoteShown = true;
                    var show = function (q) {
                        quoteEl.textContent = q;
                        quoteEl.hidden = false;
                        requestAnimationFrame(function () { quoteEl.classList.add('is-visible'); });
                    };
                    if (window.__morningQuote) {
                        show(window.__morningQuote);
                    } else {
                        requestJson('/api/tessa/morning-quote')
                            .then(function (data) {
                                if (data && data.quote) { window.__morningQuote = data.quote; show(data.quote); }
                            })
                            .catch(function () { /* skip quote on failure */ });
                    }
                };

                wakeZone.addEventListener('click', function () {
                    if (wakeZone.disabled) return;
                    if (!confirm('Sign in to start your day?')) return;
                    wakeZone.disabled = true;
                    wakeZone.classList.add('is-waking');
                    if (greeting) greeting.classList.add('is-visible');

                    var apiDone = false, videoDone = false, aborted = false;
                    var finish = function () {
                        if (aborted || !apiDone || !videoDone) return;
                        var idle = document.querySelector('.dash-cat-idle');
                        if (idle) idle.classList.add('is-finishing');
                        setTimeout(renderDashboard, 200);
                    };

                    // Reveal the quote on a fixed ~700ms timer — decoupled from
                    // video playback so it always appears "meanwhile" Tessa walks
                    // off, even if the clip's timeupdate is flaky or it can't play.
                    // finish() waits for the clip to end (~3s), so the quote stays
                    // on screen for the whole walk.
                    setTimeout(function () { if (!aborted) showQuote(); }, 700);

                    playCatVideo(video, { onEnd: function () { videoDone = true; finish(); } });
                    setTimeout(function () { if (!videoDone) { videoDone = true; finish(); } },
                        Math.round((CAT_VIDEO_SECONDS / CAT_WAKE_SPEED) * 1000) + 600);

                    requestJson('/api/signin', { method: 'POST' }).then(function () {
                        window.__dashSignedIn = true;
                        applySigninLockUi();
                        maybeMeow();
                        apiDone = true;
                        finish();
                    }).catch(function () {
                        aborted = true;
                        if (video) { try { video.pause(); video.currentTime = 0; } catch (e) {} }
                        wakeZone.classList.remove('is-waking');
                        if (greeting) greeting.classList.remove('is-visible');
                        wakeZone.disabled = false;
                        alert('Sign-in failed — please check your connection and try again.');
                    });
                });
            })();

            // Fetch AI dream story with dreaming intro + slow typing
            (function () {
                var msgEl = document.getElementById('dreamStoryMsg');
                if (!msgEl) return;
                var userName = config.userName || 'friend';
                var fetchStart = Date.now();

                // Phase 1: "zzz... dreaming..." animation while API loads
                var dreamingFrames = ['zzz', 'zzz.', 'zzz..', 'zzz...', 'zzz... dreaming', 'zzz... dreaming.', 'zzz... dreaming..', 'zzz... dreaming...'];
                var frame = 0;
                msgEl.textContent = 'zzz';
                var loadingInterval = setInterval(function () {
                    frame = (frame + 1) % dreamingFrames.length;
                    msgEl.textContent = dreamingFrames[frame];
                }, 400);

                // Phase 2: Fetch story, then wait for minimum 2.5s of dreaming animation
                var storyReady = null;
                requestJson('/api/tessa/dream-story?name=' + encodeURIComponent(userName) + '&_t=' + Date.now())
                    .then(function (data) { storyReady = data.story || fallbackStory(userName); })
                    .catch(function () { storyReady = fallbackStory(userName); })
                    .finally(function () {
                        var elapsed = Date.now() - fetchStart;
                        var wait = Math.max(0, 2500 - elapsed);
                        setTimeout(function () {
                            clearInterval(loadingInterval);
                            typeText(msgEl, storyReady);
                        }, wait);
                    });

                function fallbackStory(name) {
                    var first = name.split(' ')[0];
                    return "Zzz... I'm dreaming that " + first + " bought me a giant fish... then we napped on clouds together... Zzz 😴";
                }

                function typeText(el, text) {
                    var i = 0;
                    el.textContent = '';
                    var interval = setInterval(function () {
                        if (i < text.length) {
                            el.textContent += text.charAt(i);
                            i++;
                        } else {
                            clearInterval(interval);
                        }
                    }, 75);
                }
            })();

            return;
        }

        root.innerHTML = '<div class="dash-wrap"><div class="dash-loading">' + catSvg.replace('width="120" height="120"', 'width="64" height="64"') +
            '<span class="dash-loading-text">Loading...</span>' +
        '</div></div>';

        try {
            var results = await Promise.all([
                requestJson('/api/leave/team-on-leave-today'),
                requestJson('/api/leave/team-pending'),
                requestJson('/api/tessa/tasks/my-action-needed'),
                requestJson('/api/meetings/pending-notes'),
                requestJson('/api/daily-reports/pending'),
                requestJson('/api/tickets/pending').catch(function () { return { items: [] }; }),
                requestJson('/api/manager-review').catch(function () { return null; }),
                requestJson('/api/notes').catch(function () { return { notes: [] }; }),
                requestJson('/api/tessa/tasks/extension-inbox').catch(function () { return { items: [] }; }),
                requestJson('/api/tessa/tasks/blocker-inbox').catch(function () { return { items: [] }; }),
                requestJson('/api/tessa/checklists?filter=mine').catch(function () { return { checklists: [] }; }),
                requestJson('/api/tessa/checklists?filter=assigned').catch(function () { return { checklists: [] }; }),
                requestJson('/api/tessa/tasks/verification-inbox').catch(function () { return { items: [] }; }),
                requestJson('/api/manager-notifications').catch(function () { return { items: [] }; }),
                requestJson('/api/slack/insights').catch(function () { return { insights: [] }; }),
                requestJson('/api/gmail/insights').catch(function () { return { insights: [] }; }),
                requestJson('/api/bills/pending-summary').catch(function () { return { count: 0, items: [] }; }),
                requestJson('/api/announcements').catch(function () { return { announcements: [] }; }),
                requestJson('/api/creative-category').catch(function () { return null; }),
                requestJson('/api/claude-context/pending').catch(function () { return { items: [] }; }),
                requestJson('/api/rewards/pools/pending').catch(function () { return { pending: [] }; }),
                requestJson('/api/kpi-report/pending').catch(function () { return { items: [] }; })
            ]);
            var onLeave = (results[0].on_leave || []);
            var pendingLeave = (results[1].pending_requests || []);
            var myItems = (results[2].items || []);
            var pendingNotes = config.portal === "tech_lead" ? [] : (results[3].items || []);
            var pendingReports = config.portal === "tech_lead" ? [] : (results[4].items || []);
            var pendingTickets = (results[5].items || []);
            var managerReview = results[6]; // null for non-managers (403)
            var dashNotes = (results[7].notes || []);
            var extensionItems = (results[8].items || []);
            var blockerItems = (results[9].items || []);
            var myChecklists = (results[10].checklists || []);
            var assignedChecklists = (results[11].checklists || []);
            var verificationItems = (results[12].items || []);
            var mgrNotifications = (results[13].items || []);
            var meetingInsights = (results[14] && results[14].insights) || [];
            var gmailInsights = (results[15] && results[15].insights) || [];
            var billsPending = (results[16] && results[16].items) || [];
            var announcements = (results[17] && results[17].announcements) || [];
            var creativeCategory = results[18]; // { setter|null, viewer|null } or null
            // Claude Context pending days — the post-rollback (2026-06-18)
            // replacement for the Daily Report pending card. Empty for the
            // Daily Reports allow-list (their daily-report card shows instead).
            var pendingClaudeContext = (results[19] && results[19].items) || [];
            // Reward Pool payouts awaiting the payer (Ayush). Empty/403 for everyone else.
            var pendingRewardPools = (results[20] && results[20].pending) || [];
            // KPI Report (Fri–Mon) — team members whose weekly KPI notes this
            // manager still has to fill. Empty outside the window or for non-fillers.
            var pendingKpiReports = (results[21] && results[21].items) || [];

            // --- Leave section ---
            var leaveHtml = '';
            if (onLeave.length > 0) {
                leaveHtml = '<div class="dash-leave-card">' +
                    '<div class="dash-leave-card-header"><span>On Leave Today</span>' +
                    '<span class="dash-leave-card-count">' + onLeave.length + '</span></div>' +
                    '<div class="dash-leave-list">';
                for (var i = 0; i < onLeave.length; i++) {
                    var item = onLeave[i];
                    var leaveSlug = (item.leave_type && item.leave_type.slug) || 'unknown';
                    var leaveName = (item.leave_type && item.leave_type.name) || 'Leave';
                    var dateRange = item.total_days > 1
                        ? (item.start_date + ' to ' + item.end_date)
                        : item.start_date;
                    leaveHtml += '<div class="dash-leave-item">' +
                        '<span class="dash-leave-name">' + escapeHtml(item.user.name) + '</span>' +
                        '<div class="dash-leave-info">' +
                        '<span class="dash-leave-type dash-leave-type--' + escapeHtml(leaveSlug) + '">' + escapeHtml(leaveName) + '</span>' +
                        (item.total_days > 1 ? '<span class="dash-leave-dates">' + escapeHtml(dateRange) + '</span>' : '') +
                        '</div>' +
                        '</div>';
                }
                leaveHtml += '</div></div>';
            }

            // --- Pending leave requests ---
            var pendingLeaveHtml = '';
            if (pendingLeave.length > 0) {
                pendingLeaveHtml = '<div class="dash-section" id="dashPendingLeave">' +
                    '<div class="dash-section-header">' +
                    '<h3>Leave Requests</h3>' +
                    '<span class="dash-section-count">' + pendingLeave.length + '</span>' +
                    '</div>' +
                    '<div class="dash-leave-requests">';
                for (var p = 0; p < pendingLeave.length; p++) {
                    var lr = pendingLeave[p];
                    var lrSlug = (lr.leave_type && lr.leave_type.slug) || 'unknown';
                    var lrName = (lr.leave_type && lr.leave_type.name) || 'Leave';
                    var lrUser = (lr.user && lr.user.name) || '';
                    var lrDates;
                    if (lrSlug === 'compensate') {
                        lrDates = 'Off ' + lr.start_date + ' · Work ' + (lr.compensation_date || '—');
                    } else if (lr.start_date === lr.end_date) {
                        lrDates = lr.start_date;
                    } else {
                        lrDates = lr.start_date + ' → ' + lr.end_date;
                    }
                    // A cancellation request on an already-approved leave stays
                    // status='approved' with cancellation_requested_at set, so it
                    // is NOT pending. It needs the cancellation review endpoint —
                    // the regular Approve/Reject (→ /review) rejects non-pending
                    // leaves with "Only pending leave requests can be reviewed."
                    var lrCancelReq = lr.status === 'approved' && lr.cancellation_requested_at;
                    var lrActions = lrCancelReq
                        ? '<button type="button" class="btn btn-success btn-sm dash-leave-approve-cancel" data-id="' + lr.id + '">Approve Cancellation</button>' +
                          '<button type="button" class="btn btn-outline-danger btn-sm dash-leave-reject-cancel" data-id="' + lr.id + '">Reject Cancellation</button>'
                        : '<button type="button" class="btn btn-success btn-sm dash-leave-approve" data-id="' + lr.id + '">Approve</button>' +
                          '<button type="button" class="btn btn-outline-danger btn-sm dash-leave-reject" data-id="' + lr.id + '">Reject</button>';
                    pendingLeaveHtml += '<div class="dash-leave-request" data-leave-id="' + lr.id + '">' +
                        '<div class="dash-leave-request-info">' +
                            '<div class="dash-leave-request-top">' +
                                '<span class="dash-leave-request-name">' + escapeHtml(lrUser) + '</span>' +
                                '<span class="dash-leave-type dash-leave-type--' + escapeHtml(lrSlug) + '">' + escapeHtml(lrName) + '</span>' +
                            '</div>' +
                            '<span class="dash-leave-request-dates">' + escapeHtml(lrDates) + '</span>' +
                            (lrCancelReq
                                ? '<span class="dash-leave-request-reason" style="color:#ef4444;font-weight:600;">⚠ Cancellation requested' + (lr.cancellation_reason ? ': ' + escapeHtml(lr.cancellation_reason) : '') + '</span>'
                                : (lr.reason ? '<span class="dash-leave-request-reason">' + escapeHtml(lr.reason) + '</span>' : '')) +
                        '</div>' +
                        '<div class="dash-leave-request-actions">' +
                            lrActions +
                        '</div>' +
                    '</div>';
                }
                pendingLeaveHtml += '</div></div>';
            }

            // --- My tasks section ---
            function buildTaskRowHtml(t, showAssignee) {
                var deadlineDate = new Date(t.deadline);
                var deadlineLabel = deadlineDate.toLocaleDateString('en-IN', { timeZone: 'Asia/Kolkata', day: '2-digit', month: 'short' });
                var priorityCls = 'dash-priority--' + (t.priority || 'medium');
                var overdueCls = t.is_overdue ? ' dash-task-overdue' : '';
                var blockerCls = t.blocker_status === 'blocked' ? ' dash-task-blocked' : '';
                var days = t.days_since_update;
                var staleLabel = '';
                if (days === null) {
                    staleLabel = '<span class="dash-task-stale dash-task-stale--never">No update yet</span>';
                } else if (days >= 2) {
                    staleLabel = '<span class="dash-task-stale dash-task-stale--old">' + days + 'd ago</span>';
                } else if (days === 1) {
                    staleLabel = '<span class="dash-task-stale dash-task-stale--yesterday">1d ago</span>';
                }
                return '<div class="dash-task-item' + overdueCls + blockerCls + '" data-task-id="' + t.id + '">' +
                    '<div class="dash-task-main">' +
                    '<span class="dash-task-title">' + escapeHtml(t.title) + '</span>' +
                    (showAssignee && t.assigned_to ? '<span class="dash-task-assignee">' + escapeHtml(t.assigned_to.name) + '</span>' : '') +
                    '</div>' +
                    '<div class="dash-task-meta">' +
                    staleLabel +
                    (t.blocker_status === 'blocked' ? '<span class="dash-task-badge dash-task-badge--blocked">Blocked</span>' : '') +
                    (t.is_overdue ? '<span class="dash-task-badge dash-task-badge--overdue">Overdue</span>' : '') +
                    '<span class="dash-task-badge ' + priorityCls + '">' + escapeHtml(t.priority) + '</span>' +
                    '<span class="dash-task-deadline">Due ' + escapeHtml(deadlineLabel) + '</span>' +
                    '<span class="dash-task-progress">' + (t.progress || 0) + '%</span>' +
                    '</div>' +
                    '</div>';
            }

            var myTasksHtml = '';
            if (myItems.length > 0) {
                // Group items by task ID
                var grouped = {};
                var groupOrder = [];
                for (var k = 0; k < myItems.length; k++) {
                    var mi = myItems[k];
                    if (!grouped[mi.id]) {
                        grouped[mi.id] = { title: mi.title, progress: mi.progress || 0, dates: [] };
                        groupOrder.push(mi.id);
                    }
                    grouped[mi.id].dates.push(mi);
                }

                myTasksHtml = '<div class="dash-section" id="dashMySection">' +
                    '<div class="dash-tessa-cards">';
                for (var g = 0; g < groupOrder.length; g++) {
                    var taskId = groupOrder[g];
                    var group = grouped[taskId];
                    var hasOverdue = group.dates.some(function (d) { return !d.is_today; });
                    var cardCls = 'dash-tessa-card' + (hasOverdue ? ' dash-tessa-card--overdue' : '');
                    var qKey = taskId + '_' + group.dates[0].checkin_date;
                    var fallbackQ = 'How\'s <strong>' + escapeHtml(group.title) + '</strong> going?';

                    myTasksHtml += '<div class="' + cardCls + '" data-task-group="' + taskId + '" data-q-key="' + escapeHtml(qKey) + '" data-fallback="' + escapeHtml(fallbackQ) + '">' +
                        '<div class="dash-tessa-label">EOD Update</div>' +
                        '<div class="dash-tessa-header">' +
                            '<div class="dash-tessa-q">' +
                                '<span class="dash-tessa-avatar">T</span>' +
                                '<span class="dash-tessa-qtext"><em class="dash-tessa-thinking">Tessa is thinking...</em></span>' +
                            '</div>' +
                            '<a href="#" class="dash-tessa-open" data-task-id="' + taskId + '">Open ↗</a>' +
                        '</div>';

                    // Date rows within the card
                    for (var d = 0; d < group.dates.length; d++) {
                        var dt = group.dates[d];
                        var dateBadgeCls = dt.is_today ? 'dash-tessa-datebadge dash-tessa-datebadge--today' : 'dash-tessa-datebadge dash-tessa-datebadge--missed';
                        var placeholder = dt.is_today ? 'What\'s the update for today?' : 'What happened on ' + dt.date_label + '?';
                        myTasksHtml += '<div class="dash-tessa-daterow" data-task-id="' + taskId + '" data-checkin-date="' + escapeHtml(dt.checkin_date) + '" data-progress="' + (group.progress) + '">' +
                            '<span class="' + dateBadgeCls + '">' + escapeHtml(dt.date_label) + '</span>' +
                            '<div class="dash-tessa-daterow-form">' +
                                '<textarea class="input dash-tessa-note" placeholder="' + escapeHtml(placeholder) + '" rows="1"></textarea>' +
                                '<button type="button" class="dash-tessa-submit" title="Submit">&#10148;</button>' +
                            '</div>' +
                            '<span class="dash-tessa-error"></span>' +
                        '</div>';
                    }

                    myTasksHtml += '</div>';
                }
                myTasksHtml += '</div></div>';
            }

            // --- Meeting notes, daily reports, claude-context & reward-pool pending cards ---
            var pendingCardsHtml = '';
            if (pendingNotes.length > 0 || pendingReports.length > 0 || pendingTickets.length > 0 || pendingClaudeContext.length > 0 || pendingRewardPools.length > 0) {
                pendingCardsHtml = '<div class="dash-section">' +
                    '<div class="dash-section-header">' +
                    '<h3>Pending Updates</h3>' +
                    '<span class="dash-section-count">' + (pendingNotes.length + pendingReports.length + pendingTickets.length + pendingClaudeContext.length + pendingRewardPools.length) + '</span>' +
                    '</div>' +
                    '<div class="dash-pending-cards">';

                // Ticket cards (tickets assigned to me, not yet resolved)
                for (var ti = 0; ti < pendingTickets.length; ti++) {
                    var tk = pendingTickets[ti];
                    var priorityCls = 'dash-pending-card--ticket-' + (tk.priority || 'low');
                    var metaText = (tk.reporterName ? 'From ' + tk.reporterName + ' · ' : '') +
                        (tk.priority ? tk.priority.charAt(0).toUpperCase() + tk.priority.slice(1) + ' priority' : '') +
                        (tk.status === 'in_progress' ? ' · In progress' : '');
                    pendingCardsHtml += '<div class="dash-pending-card dash-pending-card--ticket ' + priorityCls + '" data-ticket-id="' + tk.id + '">' +
                        '<div class="dash-pending-card-icon">🎫</div>' +
                        '<div class="dash-pending-card-body">' +
                        '<span class="dash-pending-card-label">Ticket</span>' +
                        '<span class="dash-pending-card-title">' + escapeHtml(tk.title) + '</span>' +
                        '<span class="dash-pending-card-meta">' + escapeHtml(metaText) + '</span>' +
                        '</div>' +
                        '<button class="dash-pending-card-btn dash-ticket-open-btn" data-ticket-id="' + tk.id + '">Open ↗</button>' +
                        '</div>';
                }

                // Meeting note cards
                for (var mi = 0; mi < pendingNotes.length; mi++) {
                    var mn = pendingNotes[mi];
                    pendingCardsHtml += '<div class="dash-pending-card dash-pending-card--notes' + (mn.isOverdue ? ' dash-pending-card--overdue' : '') + '" data-meeting-key="' + escapeHtml(mn.meetingKey) + '">' +
                        '<div class="dash-pending-card-icon">📝</div>' +
                        '<div class="dash-pending-card-body">' +
                        '<span class="dash-pending-card-label">Meeting Notes</span>' +
                        '<span class="dash-pending-card-title">' + escapeHtml(mn.title) + '</span>' +
                        '<span class="dash-pending-card-meta">' + escapeHtml(mn.dayOfWeek + ', ' + mn.time) + '</span>' +
                        '</div>' +
                        '<button class="dash-pending-card-btn dash-meeting-update-btn" data-meeting-key="' + escapeHtml(mn.meetingKey) + '">Update ↗</button>' +
                        '</div>';
                }

                // Daily report cards
                for (var ri = 0; ri < pendingReports.length; ri++) {
                    var rp = pendingReports[ri];
                    pendingCardsHtml += '<div class="dash-pending-card dash-pending-card--report' + (rp.isOverdue ? ' dash-pending-card--overdue' : '') + '">' +
                        '<div class="dash-pending-card-icon">📊</div>' +
                        '<div class="dash-pending-card-body">' +
                        '<span class="dash-pending-card-label">Daily Report</span>' +
                        '<span class="dash-pending-card-title">' + escapeHtml(rp.dayLabel) + '</span>' +
                        '<span class="dash-pending-card-meta">KPI metrics not updated</span>' +
                        '</div>' +
                        '<button class="dash-pending-card-btn dash-report-update-btn" data-report-date="' + escapeHtml(rp.date) + '">Update ↗</button>' +
                        '</div>';
                }

                // Claude Context cards — replaces the Daily Report card for staff
                // moved off Daily Reports (2026-06-18). It's logged via Claude over
                // MCP, so the card opens the Claude Context tab (history) rather
                // than a fill-in form.
                for (var ci = 0; ci < pendingClaudeContext.length; ci++) {
                    var cc = pendingClaudeContext[ci];
                    pendingCardsHtml += '<div class="dash-pending-card dash-pending-card--claude' + (cc.isOverdue ? ' dash-pending-card--overdue' : '') + '">' +
                        '<div class="dash-pending-card-icon">🧠</div>' +
                        '<div class="dash-pending-card-body">' +
                        '<span class="dash-pending-card-label">Claude Context</span>' +
                        '<span class="dash-pending-card-title">' + escapeHtml(cc.dayLabel) + '</span>' +
                        '<span class="dash-pending-card-meta">End-of-day summary not logged</span>' +
                        '</div>' +
                        '<button class="dash-pending-card-btn dash-claude-context-open-btn">View ↗</button>' +
                        '</div>';
                }

                // Reward Pool payouts (payer / Ayush only) — one summary card → Pay tab.
                if (pendingRewardPools.length > 0) {
                    var poolTotal = pendingRewardPools.reduce(function (s, p) { return s + Number(p.amount || 0); }, 0);
                    pendingCardsHtml += '<div class="dash-pending-card dash-pending-card--reward-pool">' +
                        '<div class="dash-pending-card-icon">🏆</div>' +
                        '<div class="dash-pending-card-body">' +
                        '<span class="dash-pending-card-label">Reward Pool</span>' +
                        '<span class="dash-pending-card-title">' + pendingRewardPools.length + ' team pool' + (pendingRewardPools.length > 1 ? 's' : '') + ' awaiting payout</span>' +
                        '<span class="dash-pending-card-meta">₹' + poolTotal.toLocaleString('en-IN') + ' total</span>' +
                        '</div>' +
                        '<button class="dash-pending-card-btn dash-reward-pool-open-btn">Pay ↗</button>' +
                        '</div>';
                }

                pendingCardsHtml += '</div></div>';
            }

            // KPI Report (Fri–Mon) — managers fill weekly KPI tracking notes for
            // their team. Surfaced as its own card directly above the Work Quality
            // Review panel (both are weekly manager duties wrapped up before
            // sign-off). Mirrors the notify:kpi-report Slack nudge (same people,
            // same Fri–Mon window). Click → KPI Report page, wired by the shared
            // .dash-pending-card--kpi-report handler (finds it anywhere in root).
            var kpiReportCardHtml = '';
            if (pendingKpiReports.length > 0) {
                var kpiNames = pendingKpiReports.map(function (p) { return p.name; });
                var kpiNamesLabel = kpiNames.length > 3
                    ? kpiNames.slice(0, 3).join(', ') + ' +' + (kpiNames.length - 3) + ' more'
                    : kpiNames.join(', ');
                var kpiPlural = pendingKpiReports.length > 1 ? 's' : '';
                kpiReportCardHtml = '<div class="dash-section">' +
                    '<div class="dash-pending-cards">' +
                    '<div class="dash-pending-card dash-pending-card--kpi-report">' +
                    '<div class="dash-pending-card-icon">📈</div>' +
                    '<div class="dash-pending-card-body">' +
                    '<span class="dash-pending-card-label">KPI Report</span>' +
                    '<span class="dash-pending-card-title">' + pendingKpiReports.length + ' team member' + kpiPlural + '’ KPI report' + kpiPlural + ' to fill this week</span>' +
                    '<span class="dash-pending-card-meta">' + escapeHtml(kpiNamesLabel) + '</span>' +
                    '</div>' +
                    '<button class="dash-pending-card-btn dash-kpi-report-open-btn">Fill ↗</button>' +
                    '</div>' +
                    '</div></div>';
            }

            // --- Friday Work-Quality review widget (managers/leads only) ---
            var fridayReviewHtml = buildFridayReviewHtml(managerReview);

            // --- Daily Checklists assigned to me ---
            // The assignee sees their checklists here on the dashboard so the
            // boxes are right where they sign in / out for the day. The Tasks
            // → Checklists tab only shows what *I* assigned to others.
            //
            // Once a box is ticked the row drops out of view for the rest of
            // the day — the dashboard should feel emptier as the day goes on,
            // not list a pile of struck-through completed items. The textarea
            // beside each open item lets the assignee leave a same-day update
            // for the assigner; notes persist regardless of check state.
            var checklistHtml = '';
            if (myChecklists.length > 0) {
                var openChecklists = myChecklists.map(function (c) {
                    var openItems = c.items.filter(function (it) { return !it.checked_today; });
                    return { c: c, openItems: openItems };
                }).filter(function (x) { return x.openItems.length > 0; });
                var totalDone = 0, totalItems = 0;
                myChecklists.forEach(function (c) { totalDone += c.done_today; totalItems += c.item_count; });
                if (openChecklists.length > 0) {
                    checklistHtml = '<div class="dash-section" id="dashChecklistsSection">' +
                        '<div class="dash-section-header">' +
                            '<h3>My Checklists</h3>' +
                            '<span class="dash-section-count">' + totalDone + '/' + totalItems + '</span>' +
                        '</div>';
                    openChecklists.forEach(function (entry) {
                        var c = entry.c;
                        checklistHtml += '<div class="dash-checklist-card" data-checklist-id="' + c.id + '">' +
                            '<div class="dash-checklist-head">' +
                                '<div>' +
                                    '<div class="dash-checklist-title">' + escapeHtml(c.title) + '</div>' +
                                    '<div class="dash-checklist-meta">From ' + escapeHtml(c.assigner ? c.assigner.name : '—') + '</div>' +
                                '</div>' +
                                '<span class="dash-checklist-progress">' + c.done_today + '/' + c.item_count + '</span>' +
                            '</div>' +
                            (c.description ? '<div class="dash-checklist-desc">' + escapeHtml(c.description) + '</div>' : '') +
                            '<div class="dash-checklist-items">';
                        entry.openItems.forEach(function (it) {
                            var noteVal = it.note_today ? escapeHtml(it.note_today) : '';
                            checklistHtml += '<div class="dash-checklist-item" data-item-id="' + it.id + '">' +
                                '<label class="dash-checklist-item-label">' +
                                    '<input type="checkbox" class="dash-checklist-check" data-checklist-id="' + c.id + '" data-item-id="' + it.id + '">' +
                                    '<span>' + escapeHtml(it.title) + '</span>' +
                                '</label>' +
                                '<textarea class="dash-checklist-note" data-checklist-id="' + c.id + '" data-item-id="' + it.id + '" rows="1" placeholder="Add an update (optional)">' + noteVal + '</textarea>' +
                            '</div>';
                        });
                        checklistHtml += '</div></div>';
                    });
                    checklistHtml += '</div>';
                }
            }

            // --- Today's checklist updates from my assignees ---
            // Per spec: assigner sees same-day update notes posted by their
            // assignees, every day there is one. Rendered as a flat per-item
            // feed (assignee · item title · update text). Only shows items
            // with notes today; nothing renders when the team has been quiet.
            var assigneeUpdatesHtml = '';
            var assigneeUpdates = [];
            assignedChecklists.forEach(function (c) {
                c.items.forEach(function (it) {
                    if (it.note_today && it.note_today.trim() !== '') {
                        assigneeUpdates.push({
                            assignee: c.assignee ? c.assignee.name : '—',
                            checklist: c.title,
                            item: it.title,
                            note: it.note_today,
                            checked: !!it.checked_today,
                        });
                    }
                });
            });
            if (assigneeUpdates.length > 0) {
                assigneeUpdatesHtml = '<div class="dash-section" id="dashChecklistUpdatesSection">' +
                    '<div class="dash-section-header">' +
                        '<h3>Checklist Updates Today</h3>' +
                        '<span class="dash-section-count">' + assigneeUpdates.length + '</span>' +
                        '<button type="button" class="dash-checklist-clear-btn" id="dashChecklistClearBtn">Clear</button>' +
                    '</div>' +
                    '<div class="dash-checklist-updates">';
                assigneeUpdates.forEach(function (u) {
                    assigneeUpdatesHtml += '<div class="dash-checklist-update' + (u.checked ? ' dash-checklist-update-done' : '') + '">' +
                        '<div class="dash-checklist-update-head">' +
                            '<span class="dash-checklist-update-who">' + escapeHtml(u.assignee) + '</span>' +
                            '<span class="dash-checklist-update-where">' + escapeHtml(u.checklist) + ' › ' + escapeHtml(u.item) + '</span>' +
                            (u.checked ? '<span class="dash-checklist-update-tick">✓ done</span>' : '') +
                        '</div>' +
                        '<div class="dash-checklist-update-body">' + escapeHtml(u.note) + '</div>' +
                    '</div>';
                });
                assigneeUpdatesHtml += '</div></div>';
            }

            // --- Team-handoff one-liners (Krishnan-only by data) ---
            // Each row is a sender/receiver choice picked on a daily report.
            // Hidden entirely when there are none, so users without team
            // handoffs (everyone except Krishnan today) see nothing.
            var mgrNotifHtml = '';
            if (mgrNotifications.length > 0) {
                mgrNotifHtml = '<div class="dash-section mgr-notif-section" id="dashMgrNotifSection">' +
                    '<div class="dash-section-header mgr-notif-head">' +
                        '<h3 class="mgr-notif-title">Team updates</h3>' +
                        '<span class="dash-section-count">' + mgrNotifications.length + '</span>' +
                        '<button type="button" class="mgr-notif-clear" id="dashMgrNotifClearBtn">Clear all</button>' +
                    '</div>' +
                    '<div class="mgr-notif-list">';
                mgrNotifications.forEach(function (n) {
                    var when = '';
                    try {
                        var t = new Date(n.at);
                        when = t.toLocaleTimeString('en-IN', { timeZone: 'Asia/Kolkata', hour: '2-digit', minute: '2-digit' });
                    } catch (e) { /* leave blank */ }
                    // Probation-ending notifications carry a Release Letter action
                    // that deep-links HR into the Letters composer for that user.
                    var actionHtml = '';
                    if (n.source === 'probation_ending' && n.source_ref) {
                        actionHtml = '<button type="button" class="btn btn-sm btn-primary mgr-notif-action" ' +
                            'data-action="release-letter" data-user-id="' + escapeHtml(String(n.source_ref)) + '">Release Letter</button>';
                    } else if (n.source === 'hiring_provision' && n.source_ref) {
                        // Feature 3: provisioner (Fida/Yuvanesh) ticks their account-setup task
                        // straight from the dashboard. The backend infers tessa vs workspace from the viewer.
                        actionHtml = '<button type="button" class="btn btn-sm btn-primary mgr-notif-action" ' +
                            'data-action="provision-done" data-candidate-id="' + escapeHtml(String(n.source_ref)) + '">✓ Mark done</button>';
                    }
                    mgrNotifHtml += '<div class="mgr-notif-item">' +
                        '<span class="mgr-notif-msg">' + escapeHtml(n.message) + '</span>' +
                        (when ? '<span class="mgr-notif-time">' + escapeHtml(when) + '</span>' : '') +
                        actionHtml +
                    '</div>';
                });
                mgrNotifHtml += '</div></div>';
            }

            // --- Deadline extension inbox (reporter sees notices + approvals) ---
            var extensionHtml = '';
            if (extensionItems.length > 0) {
                extensionHtml = '<div class="dash-section" id="dashExtensionSection">' +
                    '<div class="dash-section-header">' +
                        '<h3>Deadline Extensions</h3>' +
                        '<span class="dash-section-count">' + extensionItems.length + '</span>' +
                    '</div>' +
                    '<div class="dash-extension-list">';
                for (var ei = 0; ei < extensionItems.length; ei++) {
                    var ex = extensionItems[ei];
                    var assigneeName = (ex.assignee && ex.assignee.name) ? ex.assignee.name : 'Assignee';
                    var dayLabel = ex.days === 1 ? '1 day' : (ex.days + ' days');
                    var deadlineSrc = ex.kind === 'approval' ? ex.proposed_deadline : ex.deadline;
                    var deadlineLabel = '';
                    if (deadlineSrc) {
                        var d = new Date(deadlineSrc);
                        deadlineLabel = d.toLocaleDateString('en-IN', { timeZone: 'Asia/Kolkata', day: '2-digit', month: 'short' });
                    }
                    var actionsHtml;
                    var bodyText;
                    if (ex.kind === 'notice') {
                        bodyText = escapeHtml(assigneeName) + ' extended <strong>' + escapeHtml(ex.title) + '</strong> by ' + dayLabel +
                            (deadlineLabel ? '. New deadline: ' + escapeHtml(deadlineLabel) : '') + '.';
                        actionsHtml = '<button type="button" class="btn btn-outline-secondary btn-sm dash-ext-clear" data-task-id="' + ex.id + '">Got it</button>';
                    } else {
                        bodyText = escapeHtml(assigneeName) + ' is requesting +' + dayLabel + ' on <strong>' + escapeHtml(ex.title) + '</strong>' +
                            (deadlineLabel ? ' (proposed: ' + escapeHtml(deadlineLabel) + ')' : '') +
                            '. This is extension #' + (ex.extension_count + 1) + '.';
                        actionsHtml = '<button type="button" class="btn btn-success btn-sm dash-ext-approve" data-task-id="' + ex.id + '">Approve</button>' +
                            '<button type="button" class="btn btn-outline-danger btn-sm dash-ext-reject" data-task-id="' + ex.id + '">Reject</button>';
                    }
                    extensionHtml += '<div class="dash-extension-item dash-extension-item--' + ex.kind + '" data-task-id="' + ex.id + '">' +
                        '<div class="dash-extension-body">' + bodyText + '</div>' +
                        '<div class="dash-extension-actions">' + actionsHtml + '</div>' +
                    '</div>';
                }
                extensionHtml += '</div></div>';
            }

            // --- Blocker inbox (reporter sees blockers raised by their assignees) ---
            // One-line banner with Open + Clear notification. Open reveals all
            // blocker details inline. Clear dismisses every current blocker from
            // this inbox in one shot (assignees still see them on their tasks).
            var blockerHtml = '';
            if (blockerItems.length > 0) {
                var bCount = blockerItems.length;
                var bWord = bCount === 1 ? 'blocker' : 'blockers';
                blockerHtml = '<div class="dash-section" id="dashBlockerSection">' +
                    '<div class="dash-blocker-banner">' +
                        '<span class="dash-blocker-banner-text">You have <strong>' + bCount + '</strong> ' + bWord + ' on your tasks assigned</span>' +
                        '<div class="dash-blocker-banner-actions">' +
                            '<button type="button" class="btn btn-outline-secondary btn-sm dash-blocker-open-all" aria-expanded="false" aria-controls="dashBlockerDetails">Open</button>' +
                            '<button type="button" class="btn btn-outline-danger btn-sm dash-blocker-clear-all">Clear notification</button>' +
                        '</div>' +
                    '</div>' +
                    '<div class="dash-blocker-details" id="dashBlockerDetails" hidden>';
                for (var bi = 0; bi < blockerItems.length; bi++) {
                    var bk = blockerItems[bi];
                    var bkAssignee = (bk.task && bk.task.assignee && bk.task.assignee.name) ? bk.task.assignee.name : 'Assignee';
                    var bkTitle = (bk.task && bk.task.title) ? bk.task.title : 'Task';
                    var bkTaskId = (bk.task && bk.task.id) ? bk.task.id : '';
                    var bkAgo = '';
                    if (bk.created_at) {
                        var bDiffSec = Math.floor((Date.now() - new Date(bk.created_at).getTime()) / 1000);
                        if (isNaN(bDiffSec)) bkAgo = '';
                        else if (bDiffSec < 60) bkAgo = 'just now';
                        else if (bDiffSec < 3600) bkAgo = Math.floor(bDiffSec / 60) + 'm ago';
                        else if (bDiffSec < 86400) bkAgo = Math.floor(bDiffSec / 3600) + 'h ago';
                        else if (bDiffSec < 86400 * 7) bkAgo = Math.floor(bDiffSec / 86400) + 'd ago';
                        else bkAgo = new Date(bk.created_at).toLocaleDateString('en-IN', { timeZone: 'Asia/Kolkata', day: 'numeric', month: 'short' });
                    }
                    blockerHtml += '<div class="dash-blocker-row" data-task-id="' + bkTaskId + '">' +
                        '<div class="dash-blocker-row-headline"><strong>' + escapeHtml(bkAssignee) + '</strong> is blocked on <a href="#" class="dash-blocker-task-link" data-task-id="' + bkTaskId + '">' + escapeHtml(bkTitle) + '</a></div>' +
                        (bk.note ? '<div class="dash-blocker-row-note">"' + escapeHtml(bk.note) + '"</div>' : '') +
                        (bkAgo ? '<div class="dash-blocker-row-meta">' + escapeHtml(bkAgo) + '</div>' : '') +
                    '</div>';
                }
                blockerHtml += '</div></div>';
            }

            // --- Suggestions from Huddles (extracted from Slack huddle AI notes) ---
            // Hide the whole section when there's nothing — no empty-state copy.
            var meetingInsightsHtml = '';
            if (meetingInsights.length > 0) {
            // Group notifications by meeting occurrence (meeting_id + date) — one
            // box per meeting. Preserve the controller's priority/recency order
            // for both the groups (first-seen) and the rows inside them.
            var miGroups = [];
            var miGroupIndex = {};
            for (var mi = 0; mi < meetingInsights.length; mi++) {
                var ins = meetingInsights[mi];
                var gKey = (ins.meeting_id || 'm') + '|' + (ins.meeting_date || '');
                if (miGroupIndex[gKey] === undefined) {
                    miGroupIndex[gKey] = miGroups.length;
                    miGroups.push({ title: ins.meeting_label || ins.meeting_title || 'Huddle', date: ins.meeting_date || '', items: [] });
                }
                miGroups[miGroupIndex[gKey]].items.push(ins);
            }

            meetingInsightsHtml = '<div class="dash-section dash-mi-section" id="dashMeetingInsightsSection">' +
                '<div class="dash-section-header">' +
                    '<h3>Suggestions from Huddles</h3>' +
                    '<span class="dash-section-count">' + meetingInsights.length + '</span>' +
                    '<button type="button" class="dash-mi-clear-all" id="dashMiClearAll" title="Clear all from your dashboard — they stay in Slack Insights history" style="margin-left:auto;font-size:11px;color:#9ca3af;background:transparent;border:1px solid #3f3f46;border-radius:12px;padding:3px 10px;cursor:pointer">Clear dashboard</button>' +
                '</div>';

            for (var g = 0; g < miGroups.length; g++) {
                var grp = miGroups[g];
                var grpLabel = escapeHtml(grp.title) + (grp.date ? ' · ' + escapeHtml(grp.date) : '');
                meetingInsightsHtml += '<div class="dash-mi-group">' +
                    '<div class="dash-mi-group-header">' +
                        '<span class="dash-mi-group-title">' + grpLabel + '</span>' +
                        '<span class="dash-mi-group-count">' + grp.items.length + '</span>' +
                        '<button type="button" class="dash-mi-group-clear" title="Remove this meeting\'s notifications from the dashboard — they stay in Slack Insights history">Clear</button>' +
                    '</div>' +
                    '<div class="dash-mi-group-list">';
                for (var gi = 0; gi < grp.items.length; gi++) {
                    var gIns = grp.items[gi];
                    // Compact row: title (click opens the detail modal) + actions.
                    // No metadata on the dashboard — full detail lives in the modal.
                    meetingInsightsHtml += '<div class="dash-mi-row" data-insight-id="' + gIns.id + '" data-audience="' + escapeHtml(gIns.audience || 'personal') + '">' +
                        '<button type="button" class="dash-mi-row-title" data-insight-id="' + gIns.id + '" title="View details">' + escapeHtml(gIns.title) + '</button>' +
                        (gIns.delegated_to ? '<span class="dash-mi-assignee" title="You assigned this" style="font-size:11px;color:#a5b4fc;margin-left:6px;white-space:nowrap">→ ' + escapeHtml(gIns.delegated_to) + '</span>' : '') +
                        '<div class="dash-mi-row-actions">' +
                            '<button type="button" class="btn btn-success btn-sm dash-mi-add-task" data-insight-id="' + gIns.id + '"' + (gIns.suggested_assignee_id ? ' data-assignee="' + gIns.suggested_assignee_id + '"' : '') + '>Add to Task</button>' +
                            '<div class="dash-mi-snooze-wrap">' +
                                '<button type="button" class="btn btn-outline-secondary btn-sm dash-mi-snooze-toggle" data-insight-id="' + gIns.id + '">Set Reminder ▾</button>' +
                                '<div class="dash-mi-snooze-menu" hidden>' +
                                    '<button type="button" data-snooze="1h">In 1 hour</button>' +
                                    '<button type="button" data-snooze="4h">In 4 hours</button>' +
                                    '<button type="button" data-snooze="tomorrow">Tomorrow 9 AM</button>' +
                                    '<button type="button" data-snooze="custom">Custom…</button>' +
                                '</div>' +
                            '</div>' +
                            '<button type="button" class="btn btn-outline-secondary btn-sm dash-mi-ignore" data-insight-id="' + gIns.id + '" title="Ignore — still visible in Slack Insights history">Ignore</button>' +
                        '</div>' +
                    '</div>';
                }
                meetingInsightsHtml += '</div></div>';
            }
            meetingInsightsHtml += '</div>';
            }

            // --- Awaiting verification (reporter sees tasks an assignee marked
            // completed that are waiting for them to verify & close) ---
            var verificationHtml = '';
            if (verificationItems.length > 0) {
                verificationHtml = '<div class="dash-section" id="dashVerifySection">' +
                    '<div class="dash-section-header">' +
                        '<h3>Awaiting your verification</h3>' +
                        '<span class="dash-section-count">' + verificationItems.length + '</span>' +
                    '</div>' +
                    '<div class="dash-verify-list">';
                for (var vi = 0; vi < verificationItems.length; vi++) {
                    var vk = verificationItems[vi];
                    var vkAssigneeName = (vk.assignee && vk.assignee.name) ? vk.assignee.name : 'Assignee';
                    var vkAgo = '';
                    if (vk.completed_at) {
                        var vDiffSec = Math.floor((Date.now() - new Date(vk.completed_at).getTime()) / 1000);
                        if (isNaN(vDiffSec)) vkAgo = '';
                        else if (vDiffSec < 60) vkAgo = 'just now';
                        else if (vDiffSec < 3600) vkAgo = Math.floor(vDiffSec / 60) + 'm ago';
                        else if (vDiffSec < 86400) vkAgo = Math.floor(vDiffSec / 3600) + 'h ago';
                        else if (vDiffSec < 86400 * 7) vkAgo = Math.floor(vDiffSec / 86400) + 'd ago';
                        else vkAgo = new Date(vk.completed_at).toLocaleDateString('en-IN', { timeZone: 'Asia/Kolkata', day: 'numeric', month: 'short' });
                    }
                    verificationHtml += '<div class="dash-verify-item" data-task-id="' + vk.id + '">' +
                        '<div class="dash-verify-body">' +
                            '<div class="dash-verify-headline"><strong>' + escapeHtml(vkAssigneeName) + '</strong> marked <strong>' + escapeHtml(vk.title) + '</strong> complete</div>' +
                            (vk.proof_note ? '<div class="dash-verify-note">"' + escapeHtml(vk.proof_note) + '"</div>' : '') +
                            (vkAgo ? '<div class="dash-verify-meta">Completed ' + escapeHtml(vkAgo) + ' · waiting to verify & close</div>' : '<div class="dash-verify-meta">Waiting to verify &amp; close</div>') +
                        '</div>' +
                        '<div class="dash-verify-actions">' +
                            '<button type="button" class="btn btn-outline-secondary btn-sm dash-verify-open" data-task-id="' + vk.id + '">Open task</button>' +
                            '<button type="button" class="btn btn-success btn-sm dash-verify-close" data-task-id="' + vk.id + '">Verify &amp; close</button>' +
                        '</div>' +
                    '</div>';
                }
                verificationHtml += '</div></div>';
            }

            // --- Notes / Checklist section ---
            // Reminder mode is single-choice: either a recurring interval, a
            // one-shot datetime, or none. The "datetime" option in the select
            // reveals a sibling datetime-local input.
            function dnOrdinal(n) {
                var s = ['th', 'st', 'nd', 'rd'], v = n % 100;
                return n + (s[(v - 20) % 10] || s[v] || s[0]);
            }
            // A yyyy-mm-dd value (this month, day clamped to month length) to
            // seed the calendar input. Only the day-of-month is ever stored.
            function dnMonthlyDateValue(day) {
                var now = new Date();
                var y = now.getFullYear(), m = now.getMonth();
                var dim = new Date(y, m + 1, 0).getDate();
                var d = Math.min(day || now.getDate(), dim);
                var pad = function (n) { return n < 10 ? '0' + n : n; };
                return y + '-' + pad(m + 1) + '-' + pad(d);
            }

            function buildReminderControls(interval, remindAt, day, noteId) {
                var modeIsDt = !!remindAt;
                var modeIsMonthly = !!day;
                var modeIsNone = !interval && !remindAt && !day;
                function opt(val, label, selected) {
                    return '<option value="' + val + '"' + (selected ? ' selected' : '') + '>' + label + '</option>';
                }
                var opts = opt('', 'No reminder', modeIsNone) +
                    opt('10', 'Every 10 mins', interval == 10) +
                    opt('15', 'Every 15 mins', interval == 15) +
                    opt('30', 'Every 30 mins', interval == 30) +
                    opt('45', 'Every 45 mins', interval == 45) +
                    opt('60', 'Every 60 mins', interval == 60) +
                    opt('datetime', 'At specific date & time…', modeIsDt) +
                    opt('monthly', 'Monthly on a day…', modeIsMonthly);
                var noteAttr = noteId ? ' data-note-id="' + noteId + '"' : '';
                var dtAttr = modeIsDt ? '' : ' hidden';
                var monthlyAttr = modeIsMonthly ? '' : ' hidden';
                return '<div class="dn-reminder-wrap"' + noteAttr + '>' +
                    '<select class="dn-reminder-select input input-sm">' + opts + '</select>' +
                    '<input type="datetime-local" class="input input-sm dn-reminder-datetime" value="' + (remindAt || '') + '"' + dtAttr + '>' +
                    '<input type="date" class="input input-sm dn-reminder-monthly" value="' + (day ? dnMonthlyDateValue(day) : '') + '"' + monthlyAttr + '>' +
                    '<div class="dn-reminder-hint"' + (modeIsMonthly ? '' : ' hidden') + '>' + (day ? 'Reminds on the ' + dnOrdinal(day) + ' of every month' : '') + '</div>' +
                    '</div>';
            }

            function readReminderPayload(scopeEl) {
                var sel = scopeEl.querySelector('.dn-reminder-select');
                var dt = scopeEl.querySelector('.dn-reminder-datetime');
                var md = scopeEl.querySelector('.dn-reminder-monthly');
                if (!sel) return { reminder_interval: null, reminder_at: null, reminder_day: null };
                if (sel.value === 'datetime') {
                    return { reminder_interval: null, reminder_at: (dt && dt.value) ? dt.value : null, reminder_day: null };
                }
                if (sel.value === 'monthly') {
                    var day = (md && md.value) ? (parseInt(md.value.split('-')[2], 10) || null) : null;
                    return { reminder_interval: null, reminder_at: null, reminder_day: day };
                }
                return { reminder_interval: sel.value ? parseInt(sel.value, 10) : null, reminder_at: null, reminder_day: null };
            }

            function wireReminderToggle(wrap, onCommit) {
                var sel = wrap.querySelector('.dn-reminder-select');
                var dt = wrap.querySelector('.dn-reminder-datetime');
                var md = wrap.querySelector('.dn-reminder-monthly');
                var hint = wrap.querySelector('.dn-reminder-hint');
                if (!sel || !dt) return;
                var pad = function (n) { return n < 10 ? '0' + n : n; };
                function refreshHint() {
                    if (!hint) return;
                    if (sel.value === 'monthly' && md && md.value) {
                        hint.textContent = 'Reminds on the ' + dnOrdinal(parseInt(md.value.split('-')[2], 10)) + ' of every month';
                        hint.hidden = false;
                    } else {
                        hint.hidden = true;
                    }
                }
                sel.addEventListener('change', function () {
                    if (sel.value === 'datetime') {
                        if (md) md.hidden = true;
                        dt.hidden = false;
                        if (!dt.value) {
                            var d = new Date();
                            d.setMinutes(d.getMinutes() + 30);
                            dt.value = d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) +
                                'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
                        }
                        refreshHint();
                        try { dt.focus(); } catch (_) {}
                        if (onCommit && dt.value) onCommit();
                    } else if (sel.value === 'monthly') {
                        dt.hidden = true;
                        if (md) {
                            md.hidden = false;
                            if (!md.value) md.value = dnMonthlyDateValue(new Date().getDate());
                        }
                        refreshHint();
                        try { md.focus(); } catch (_) {}
                        if (onCommit && md && md.value) onCommit();
                    } else {
                        dt.hidden = true;
                        if (md) md.hidden = true;
                        refreshHint();
                        if (onCommit) onCommit();
                    }
                });
                dt.addEventListener('change', function () {
                    if (sel.value === 'datetime' && onCommit) onCommit();
                });
                if (md) md.addEventListener('change', function () {
                    refreshHint();
                    if (sel.value === 'monthly' && onCommit) onCommit();
                });
            }

            var notesHtml = '';
            var noteCards = '';
            var scheduledCards = '';
            var scheduledCount = 0;
            function dnUncheckedItemsHtml(dn) {
                var h = '';
                for (var ii = 0; ii < dn.items.length; ii++) {
                    var it = dn.items[ii];
                    if (it.checked) continue;
                    h += '<label class="dn-check-item" data-real-idx="' + ii + '">' +
                        '<input type="checkbox" class="dn-checkbox" data-note-id="' + dn.id + '" data-idx="' + ii + '">' +
                        '<span class="dn-check-text">' + escapeHtml(it.text) + '</span>' +
                        '</label>';
                }
                return h;
            }
            function dnActiveCard(dn, monthly) {
                var badge = monthly ? '<div class="dn-monthly-badge">📅 Monthly · ' + dnOrdinal(dn.reminder_day) + '</div>' : '';
                return '<div class="dn-card" data-note-id="' + dn.id + '">' +
                    badge +
                    '<div class="dn-card-items">' + dnUncheckedItemsHtml(dn) + '</div>' +
                    '<div class="dn-card-footer">' +
                        buildReminderControls(dn.reminder_interval, dn.reminder_at, dn.reminder_day, dn.id) +
                        '<div class="dn-card-actions">' +
                            '<button type="button" class="dn-edit-btn" data-note-id="' + dn.id + '" title="Edit">✏️</button>' +
                            '<button type="button" class="dn-del-btn" data-note-id="' + dn.id + '" title="Delete">🗑️</button>' +
                        '</div>' +
                    '</div>' +
                '</div>';
            }
            function dnScheduledCard(dn) {
                var firstItem = (dn.items && dn.items.length) ? dn.items[0].text : (dn.title || 'Reminder');
                var more = (dn.items && dn.items.length > 1) ? ' +' + (dn.items.length - 1) : '';
                return '<div class="dn-card dn-card--scheduled" data-note-id="' + dn.id + '">' +
                    '<div class="dn-sched-main">' +
                        '<span class="dn-sched-badge">' + dnOrdinal(dn.reminder_day) + '</span>' +
                        '<span class="dn-sched-text">' + escapeHtml(firstItem) + escapeHtml(more) + '</span>' +
                    '</div>' +
                    '<div class="dn-card-actions">' +
                        '<button type="button" class="dn-edit-btn" data-note-id="' + dn.id + '" title="Edit">✏️</button>' +
                        '<button type="button" class="dn-del-btn" data-note-id="' + dn.id + '" title="Delete">🗑️</button>' +
                    '</div>' +
                '</div>';
            }
            for (var ni = 0; ni < dashNotes.length; ni++) {
                var dn = dashNotes[ni];
                var unchecked = (dn.items || []).filter(function (it) { return !it.checked; });
                if (dn.reminder_day) {
                    // Monthly reminder: active card only on its due day (while it
                    // still has pending items); every other day it lives in the
                    // collapsed Scheduled drawer so it doesn't clutter the page.
                    if (dn.reminder_due_today && unchecked.length > 0) {
                        noteCards += dnActiveCard(dn, true);
                    } else {
                        scheduledCards += dnScheduledCard(dn);
                        scheduledCount++;
                    }
                    continue;
                }
                if (unchecked.length === 0) continue;
                noteCards += dnActiveCard(dn, false);
            }
            var scheduledHtml = scheduledCards
                ? '<div class="dn-scheduled" id="dnScheduled">' +
                    '<button type="button" class="dn-scheduled-toggle" id="dnSchedToggle">' +
                        '<span class="dn-sched-caret">▸</span> Scheduled monthly reminders (' + scheduledCount + ')' +
                    '</button>' +
                    '<div class="dn-scheduled-list hidden" id="dnSchedList">' + scheduledCards + '</div>' +
                '</div>'
                : '';
            notesHtml = '<div class="dash-section" id="dashNotesSection">' +
                '<div class="dash-section-header">' +
                    '<h3>Reminders</h3>' +
                    '<button type="button" class="dn-add-btn" id="dnAddBtn">+ Add Reminder</button>' +
                '</div>' +
                '<div class="dn-cards" id="dnCards">' + noteCards + '</div>' +
                scheduledHtml +
            '</div>';

            // Morning motivational quote banner — shown once per day on the
            // working dashboard. Dismissal stored in localStorage keyed by
            // today's IST date so it doesn't reappear after dismissal but
            // does reappear fresh tomorrow. Quote text is pre-fetched into
            // window.__morningQuote during the wake transition; if missing
            // (e.g. user already signed in before page reload), the banner
            // div is rendered empty and populated by an in-flight fetch.
            var morningQuoteHtml = '';
            (function () {
                var quoteDateKey = 'tessa.morningQuote.dismissed.' + todayIstKey;
                var dismissed = false;
                try { dismissed = localStorage.getItem(quoteDateKey) === '1'; } catch (e) {}
                if (dismissed) return;
                var quoteText = window.__morningQuote || '';
                morningQuoteHtml =
                    '<div class="dash-morning-quote" id="dashMorningQuote" data-date="' + escapeHtml(todayIstKey) + '"' + (quoteText ? '' : ' data-pending="1"') + '>' +
                        '<div class="dash-morning-quote-icon" aria-hidden="true">' +
                            '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21V11a4 4 0 0 1 4-4h1a4 4 0 0 0-4 4v3h4v7H3zM13 21V11a4 4 0 0 1 4-4h1a4 4 0 0 0-4 4v3h4v7h-5z"/></svg>' +
                        '</div>' +
                        '<div class="dash-morning-quote-body">' +
                            '<div class="dash-morning-quote-label">A note to start your day</div>' +
                            '<div class="dash-morning-quote-text" id="dashMorningQuoteText">' + (quoteText ? escapeHtml(quoteText) : 'Loading...') + '</div>' +
                        '</div>' +
                        '<button type="button" class="dash-morning-quote-close" id="dashMorningQuoteClose" aria-label="Dismiss for today" title="Dismiss for today">' +
                            '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>' +
                        '</button>' +
                    '</div>';
            })();

            // ----- Notification center: tabs below the Reminders section -----
            // Tessa = the native dashboard cards; Slack = huddle suggestions;
            // Gmail = AI-classified important emails; Leaves = leave widgets,
            // shown to HR (company-wide) and to managers who have leave content.
            var hasLeaveTab = !!config.isHr || pendingLeave.length > 0 || onLeave.length > 0;
            if (dashActiveTab === 'leaves' && !hasLeaveTab) dashActiveTab = 'tessa';

            // Bills/Reimbursements/Travel awaiting payment (admins only — the
            // endpoint returns empty for everyone else). Mirrors the manager-
            // notification card; "Open Pay Queue" jumps to the Bills view.
            var billsHtml = '';
            if (billsPending.length > 0) {
                billsHtml = '<div class="dash-section mgr-notif-section" id="dashBillsSection">' +
                    '<div class="dash-section-header mgr-notif-head">' +
                        '<h3 class="mgr-notif-title">Awaiting your payment</h3>' +
                        '<span class="dash-section-count">' + billsPending.length + '</span>' +
                        '<button type="button" class="mgr-notif-clear" id="dashBillsOpenBtn">Open Pay Queue</button>' +
                    '</div>' +
                    '<div class="mgr-notif-list">';
                billsPending.forEach(function (b) {
                    var amt = '₹' + Number(b.amount || 0).toLocaleString('en-IN');
                    var tlabel = b.type === 'reimbursement' ? 'Reimbursement' : (b.type === 'travel' ? 'Travel' : 'Bill');
                    billsHtml += '<div class="mgr-notif-item">' +
                        '<span class="mgr-notif-msg">' + escapeHtml((b.submitter || 'Someone') + ' · ' + tlabel + ' · ' + (b.title || '')) + '</span>' +
                        '<span class="mgr-notif-time">' + escapeHtml(amt) + '</span>' +
                    '</div>';
                });
                billsHtml += '</div></div>';
            }

            // Mandatory Weekly Timesheet card (Fri–Sun, pending) — pinned first so
            // it's the most prominent thing in the Tessa panel. Red accent = blocks
            // sign-off until submitted. "Fill now" jumps to the Weekly Timesheet view.
            var weeklyTimesheetHtml = '';
            if (weeklyTimesheetDue) {
                weeklyTimesheetHtml = '<div class="dash-section mgr-notif-section" id="dashWeeklyTimesheetSection" style="border:1px solid rgba(239,68,68,0.45)">' +
                    '<div class="dash-section-header mgr-notif-head">' +
                        '<h3 class="mgr-notif-title">Weekly Timesheet — Mandatory</h3>' +
                        '<button type="button" class="mgr-notif-clear" id="dashWeeklyTimesheetOpenBtn">Fill now</button>' +
                    '</div>' +
                    '<div class="mgr-notif-list">' +
                        '<div class="mgr-notif-item"><span class="mgr-notif-msg">Submit your weekly timesheet (regular + overtime hours and what you worked on) before you sign off this week.</span></div>' +
                    '</div></div>';
            }

            var tessaPanelHtml = weeklyTimesheetHtml + billsHtml + mgrNotifHtml + extensionHtml + blockerHtml + verificationHtml +
                kpiReportCardHtml + fridayReviewHtml + checklistHtml + assigneeUpdatesHtml + myTasksHtml + pendingCardsHtml;
            if (!tessaPanelHtml) tessaPanelHtml = '<div class="dash-tab-empty">You\'re all caught up — nothing needs your attention.</div>';

            var slackPanelHtml = meetingInsightsHtml || '<div class="dash-tab-empty">No huddle suggestions right now.</div>';
            var gmailPanelHtml = renderGmailInsights(gmailInsights);
            var leavesPanelHtml = '';
            if (hasLeaveTab) {
                leavesPanelHtml = pendingLeaveHtml + leaveHtml;
                if (!leavesPanelHtml) leavesPanelHtml = '<div class="dash-tab-empty">No leave activity right now.</div>';
            }

            var tessaCount = myItems.length + pendingNotes.length + pendingReports.length + pendingTickets.length +
                extensionItems.length + blockerItems.length + verificationItems.length + mgrNotifications.length +
                billsPending.length + (weeklyTimesheetDue ? 1 : 0);

            function dashTabBtn(key, label, count) {
                return '<button type="button" class="dash-tab' + (dashActiveTab === key ? ' active' : '') + '" data-dashtab="' + key + '">' +
                    label + (count > 0 ? ' <span class="dash-tab-badge">' + count + '</span>' : '') + '</button>';
            }
            function dashPanel(key, html) {
                return '<div class="dash-tab-panel' + (dashActiveTab === key ? ' active' : '') + '" data-dashpanel="' + key + '">' + html + '</div>';
            }
            // Calendar tab — only for users with the personal Calendar feature.
            // Content (today + upcoming notes from their Google Calendar) is
            // lazy-loaded after render by the calendar.js module; badge updates then.
            var hasCalendarTab = (config.features || []).indexOf('calendar') !== -1;
            var dashTabsHtml = '<div class="dash-tabs">' +
                dashTabBtn('tessa', 'Tessa', tessaCount) +
                dashTabBtn('slack', 'Slack', meetingInsights.length) +
                dashTabBtn('gmail', 'Gmail', gmailInsights.length) +
                (hasCalendarTab ? dashTabBtn('calendar', 'Calendar', 0) : '') +
                (hasLeaveTab ? dashTabBtn('leaves', 'Leaves', pendingLeave.length) : '') +
                '</div>';
            var dashPanelsHtml = dashPanel('tessa', tessaPanelHtml) +
                dashPanel('slack', slackPanelHtml) +
                dashPanel('gmail', gmailPanelHtml) +
                (hasCalendarTab ? dashPanel('calendar', '<div class="dash-tab-empty">Loading your calendar…</div>') : '') +
                (hasLeaveTab ? dashPanel('leaves', leavesPanelHtml) : '');

            // --- Company-wide announcements (Feature 8) ---
            // Shown to everyone for ~7 days; dismissal is per-browser (localStorage).
            var annDismissed = {};
            try { annDismissed = JSON.parse(localStorage.getItem('tessaDismissedAnnouncements') || '{}') || {}; } catch (e) { annDismissed = {}; }
            var annHtml = '';
            var visibleAnn = announcements.filter(function (a) { return !annDismissed[a.id]; });
            if (visibleAnn.length) {
                annHtml = '<div id="dashAnnouncements" style="display:flex;flex-direction:column;gap:10px;margin-bottom:16px">';
                visibleAnn.forEach(function (a) {
                    annHtml += '<div class="dash-announcement-card" style="position:relative;background:linear-gradient(135deg,#1e3a5f,#2a5298);border:1px solid #3b82f6;border-radius:12px;padding:14px 40px 14px 16px;color:#e8f0fe">' +
                        '<div style="font-weight:700;font-size:14px;margin-bottom:2px">' + escapeHtml(a.title) + '</div>' +
                        '<div style="font-size:13px;color:#cdd9f0">' + escapeHtml(a.body) + '</div>' +
                        '<button type="button" class="dash-announcement-dismiss" data-ann-id="' + escapeHtml(String(a.id)) + '" aria-label="Dismiss" style="position:absolute;top:8px;right:10px;background:transparent;border:0;color:#9db4e0;font-size:18px;line-height:1;cursor:pointer">&times;</button>' +
                    '</div>';
                });
                annHtml += '</div>';
            }

            var creativeCategoryHtml = buildCreativeCategoryHtml(creativeCategory);

            root.innerHTML = '<div class="dash-wrap">' +
                '<div class="dash-header">' +
                '<div><h2>Dashboard</h2><div class="dash-meta">' + escapeHtml(dateLabel) + '</div></div>' +
                '<div class="dash-header-right">' +
                '<button type="button" class="dash-signoff-pill" id="dashAwakeCatMascot" aria-label="Tuck the cat in and sign off for the day" title="Click to tuck the cat in and sign off for today">' +
                    awakeCatHeaderSvg +
                    '<span class="dash-signoff-pill-label">Sign off for today</span>' +
                '</button>' +
                '</div>' +
                '</div>' +
                '<div class="dash-cols">' +
                '<div class="dash-main-col">' +
                annHtml +
                morningQuoteHtml +
                creativeCategoryHtml +
                notesHtml +
                dashTabsHtml +
                dashPanelsHtml +
                '</div>' +
                '</div>' +
                '</div>';

            if (fridayReviewHtml) wireFridayReviewHandlers(root, managerReview);
            wireCreativeCategory(root, creativeCategory, todayIstKey);

            // Dismiss a company-wide announcement (Feature 8) — remembered per-browser.
            root.querySelectorAll('.dash-announcement-dismiss').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var id = btn.getAttribute('data-ann-id');
                    var d = {};
                    try { d = JSON.parse(localStorage.getItem('tessaDismissedAnnouncements') || '{}') || {}; } catch (e) { d = {}; }
                    d[id] = 1;
                    try { localStorage.setItem('tessaDismissedAnnouncements', JSON.stringify(d)); } catch (e) {}
                    var card = btn.closest('.dash-announcement-card');
                    if (card) card.remove();
                });
            });

            // "Open Pay Queue" on the bills awaiting-payment card → Bills view.
            var dashBillsOpenBtn = document.getElementById('dashBillsOpenBtn');
            if (dashBillsOpenBtn) dashBillsOpenBtn.addEventListener('click', function () {
                if (window.MeetingModule && MeetingModule.switchView) MeetingModule.switchView('bills');
            });

            // "Fill now" on the mandatory weekly-timesheet card → Weekly Timesheet view.
            var dashWtsOpenBtn = document.getElementById('dashWeeklyTimesheetOpenBtn');
            if (dashWtsOpenBtn) dashWtsOpenBtn.addEventListener('click', function () {
                if (window.MeetingModule && MeetingModule.switchView) MeetingModule.switchView('weeklyTimesheet');
            });

            // Wire checklist checkboxes + adjacent update textareas.
            //
            // Checking the box: POST {checked, note} together so any text the
            // assignee just typed lands in the same row before the item is
            // removed from view. The row then fades out — server keeps the
            // completion row so it doesn't reappear until tomorrow.
            //
            // Note textarea: debounced auto-save while the item is still open
            // (250ms after last keystroke), plus a final flush on blur.
            root.querySelectorAll('.dash-checklist-check').forEach(function (cb) {
                cb.addEventListener('change', function () {
                    if (!cb.checked) return; // dashboard only ever ticks ON
                    var clid = cb.getAttribute('data-checklist-id');
                    var itemId = cb.getAttribute('data-item-id');
                    var row = cb.closest('.dash-checklist-item');
                    var noteEl = row ? row.querySelector('.dash-checklist-note') : null;
                    var note = noteEl ? noteEl.value : '';
                    cb.disabled = true;
                    if (noteEl) noteEl.disabled = true;
                    requestJson('/api/tessa/checklists/' + clid + '/items/' + itemId + '/toggle', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ checked: true, note: note })
                    }).then(function () {
                        var card = cb.closest('.dash-checklist-card');
                        if (row) {
                            row.classList.add('dash-checklist-item-fade');
                            setTimeout(function () {
                                row.remove();
                                if (card) {
                                    var remaining = card.querySelectorAll('.dash-checklist-item').length;
                                    var badge = card.querySelector('.dash-checklist-progress');
                                    if (badge) {
                                        var parts = badge.textContent.split('/');
                                        var total = parseInt(parts[1], 10) || 0;
                                        badge.textContent = (total - remaining) + '/' + total;
                                    }
                                    if (remaining === 0) card.remove();
                                }
                                var section = document.getElementById('dashChecklistsSection');
                                if (section && section.querySelectorAll('.dash-checklist-item').length === 0) {
                                    section.remove();
                                }
                            }, 200);
                        }
                    }).catch(function (err) {
                        cb.checked = false;
                        cb.disabled = false;
                        if (noteEl) noteEl.disabled = false;
                        alert((err && err.message) ? err.message : 'Failed to update');
                    });
                });
            });

            root.querySelectorAll('.dash-checklist-note').forEach(function (ta) {
                var pending = null;
                function flush() {
                    if (pending) { clearTimeout(pending); pending = null; }
                    var clid = ta.getAttribute('data-checklist-id');
                    var itemId = ta.getAttribute('data-item-id');
                    requestJson('/api/tessa/checklists/' + clid + '/items/' + itemId + '/note', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ note: ta.value })
                    }).catch(function () { /* swallow — they can retry by editing */ });
                }
                ta.addEventListener('input', function () {
                    if (pending) clearTimeout(pending);
                    pending = setTimeout(flush, 250);
                });
                ta.addEventListener('blur', function () { if (pending) flush(); });
            });

            // "Clear" on the assigner's Checklist Updates Today section —
            // server-side stamps assigner_dismissed_at on every same-day note
            // row in this user's checklists. The notes themselves stay (the
            // assignee never sees their text vanish); only this dashboard
            // feed hides them. If the assignee edits a note later today, the
            // row's dismissal is reset and it re-appears here.
            var clearBtn = document.getElementById('dashChecklistClearBtn');
            if (clearBtn) {
                clearBtn.addEventListener('click', function () {
                    if (!confirm('Clear today\'s checklist updates from the dashboard? The notes themselves are kept.')) return;
                    clearBtn.disabled = true;
                    requestJson('/api/tessa/checklists/updates/clear', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: '{}'
                    }).then(function () {
                        var section = document.getElementById('dashChecklistUpdatesSection');
                        if (section) section.remove();
                    }).catch(function (err) {
                        clearBtn.disabled = false;
                        alert((err && err.message) ? err.message : 'Failed to clear');
                    });
                });
            }

            // Team updates clear: stamps dismissed_at on every active row for
            // this manager. Resubmissions of the same choice on the daily
            // report reset dismissed_at to null and the row resurfaces here.
            var mgrNotifClearBtn = document.getElementById('dashMgrNotifClearBtn');
            if (mgrNotifClearBtn) {
                mgrNotifClearBtn.addEventListener('click', function () {
                    if (!confirm('Clear all team updates from the dashboard?')) return;
                    mgrNotifClearBtn.disabled = true;
                    requestJson('/api/manager-notifications/clear', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: '{}'
                    }).then(function () {
                        var section = document.getElementById('dashMgrNotifSection');
                        if (section) section.remove();
                    }).catch(function (err) {
                        mgrNotifClearBtn.disabled = false;
                        alert((err && err.message) ? err.message : 'Failed to clear');
                    });
                });
            }

            // Release Letter: probation-ending notifications deep-link HR into the
            // Letters composer prefilled for that candidate (defaults to an Offer
            // Letter — HR can switch the type in the picker). Mirrors hiring.js.
            var mgrNotifSection = document.getElementById('dashMgrNotifSection');
            if (mgrNotifSection) {
                mgrNotifSection.addEventListener('click', function (e) {
                    if (!e.target || !e.target.closest) return;
                    var btn = e.target.closest('[data-action="release-letter"]');
                    if (btn) {
                        var uid = btn.getAttribute('data-user-id');
                        if (window.MeetingModule && MeetingModule.switchView) MeetingModule.switchView('letters');
                        if (window.LettersModule && LettersModule.composeForUser) {
                            setTimeout(function () { LettersModule.composeForUser(uid, 'offer', null); }, 200);
                        }
                        return;
                    }
                    // Feature 3: provisioner ticks their account-setup task from the dashboard.
                    // The backend infers tessa vs workspace from the viewer and clears this nudge.
                    var provBtn = e.target.closest('[data-action="provision-done"]');
                    if (provBtn) {
                        var cid = provBtn.getAttribute('data-candidate-id');
                        provBtn.disabled = true; provBtn.textContent = '…';
                        requestJson('/api/hiring/candidates/' + cid + '/provisioning/mark', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ done: true }) })
                            .then(function () { renderDashboard(); })
                            .catch(function (err) {
                                provBtn.disabled = false; provBtn.textContent = '✓ Mark done';
                                alert((err && err.message) || 'Could not mark done.');
                            });
                        return;
                    }
                });
            }

            // Click the awake-cat mascot to tuck it in and sign off (symmetric
            // to clicking the sleeping cat's nose to wake + sign in). POST
            // /api/signoff — DELETE /api/signin would only remove the sign-in
            // row without creating a sign-off, leaving team-wide sign-off
            // counts stuck at zero.
            var awakeCatBtn = document.getElementById('dashAwakeCatMascot');
            if (awakeCatBtn) awakeCatBtn.addEventListener('click', function () {
                if (awakeCatBtn.disabled) return;
                if (!confirm('Sign off for today?')) return;
                awakeCatBtn.disabled = true;
                awakeCatBtn.classList.add('is-tucking');
                requestJson('/api/signoff', { method: 'POST' }).then(function () {
                    window.__dashSignedOff = true;
                    applySigninLockUi();
                    maybeMeow();
                    setTimeout(renderDashboard, 800);
                }).catch(function (err) {
                    awakeCatBtn.classList.remove('is-tucking');
                    awakeCatBtn.disabled = false;
                    var msg = (err && err.message) ? err.message : 'Sign off failed.';
                    // Stale tab whose state lags behind the server — sync and
                    // re-render rather than redirecting to "complete pending
                    // items" which is the wrong message for this case.
                    if (/already signed off/i.test(msg)) {
                        window.__dashSignedOff = true;
                        alert('You\'ve already signed off for today.');
                        renderDashboard();
                    } else if (/cannot sign off|pending/i.test(msg) && window.MeetingModule && MeetingModule.switchView) {
                        var pending = buildPendingItemsText(err && err.data && err.data.items);
                        alert((pending
                            ? 'Cannot sign off yet — still pending:\n\n' + pending
                            : msg) + '\n\nOpening the Sign Off page so you can complete these.');
                        MeetingModule.switchView('signoff');
                    } else {
                        alert(msg);
                    }
                });
            });

            // Morning motivational quote banner — dismiss + lazy fetch.
            // Dismiss persists for the day via localStorage. If the user
            // landed on the signed-in dashboard without going through the
            // wake transition (e.g. page reload), window.__morningQuote
            // is empty and we fetch on demand to populate the banner.
            (function () {
                var banner = document.getElementById('dashMorningQuote');
                if (!banner) return;
                var textEl = document.getElementById('dashMorningQuoteText');
                var closeBtn = document.getElementById('dashMorningQuoteClose');
                if (closeBtn) closeBtn.addEventListener('click', function () {
                    var key = 'tessa.morningQuote.dismissed.' + banner.getAttribute('data-date');
                    try { localStorage.setItem(key, '1'); } catch (e) {}
                    banner.classList.add('is-dismissing');
                    setTimeout(function () { banner.remove(); }, 200);
                });
                if (banner.getAttribute('data-pending') === '1' && textEl) {
                    requestJson('/api/tessa/morning-quote')
                        .then(function (data) {
                            if (data && data.quote) {
                                window.__morningQuote = data.quote;
                                textEl.textContent = data.quote;
                                banner.removeAttribute('data-pending');
                            } else {
                                banner.remove();
                            }
                        })
                        .catch(function () { banner.remove(); });
                }
            })();

            // Pending card click handlers (card or button click)
            root.querySelectorAll('.dash-pending-card--notes').forEach(function (card) {
                function openNotes() {
                    var key = card.getAttribute('data-meeting-key');
                    if (MeetingModule && MeetingModule.openMeetingById) {
                        MeetingModule.openMeetingById(key);
                        setTimeout(function () {
                            var notesTab = document.querySelector('.mtg-tab[data-tab="notes"]');
                            if (notesTab) notesTab.click();
                        }, 100);
                    }
                }
                card.addEventListener('click', openNotes);
            });

            root.querySelectorAll('.dash-pending-card--report').forEach(function (card) {
                function openReport() {
                    if (MeetingModule && MeetingModule.switchView) {
                        MeetingModule.switchView('daily');
                    }
                }
                card.addEventListener('click', openReport);
            });

            root.querySelectorAll('.dash-pending-card--claude').forEach(function (card) {
                function openClaudeContext() {
                    if (MeetingModule && MeetingModule.switchView) {
                        MeetingModule.switchView('claude_context');
                    }
                }
                card.addEventListener('click', openClaudeContext);
            });

            root.querySelectorAll('.dash-pending-card--reward-pool').forEach(function (card) {
                card.addEventListener('click', function () {
                    if (MeetingModule && MeetingModule.switchView) {
                        MeetingModule.switchView('rewards');
                    }
                });
            });

            root.querySelectorAll('.dash-pending-card--kpi-report').forEach(function (card) {
                card.addEventListener('click', function () {
                    if (MeetingModule && MeetingModule.switchView) {
                        MeetingModule.switchView('kpi_report');
                    }
                });
            });

            root.querySelectorAll('.dash-pending-card--ticket').forEach(function (card) {
                function openTicket() {
                    if (MeetingModule && MeetingModule.switchView) {
                        MeetingModule.switchView('tickets');
                    }
                }
                card.addEventListener('click', openTicket);
            });

            // Leave approve/reject handlers
            root.querySelectorAll('.dash-leave-approve, .dash-leave-reject').forEach(function (btn) {
                btn.addEventListener('click', async function () {
                    var leaveId = btn.getAttribute('data-id');
                    var action = btn.classList.contains('dash-leave-approve') ? 'approve' : 'reject';
                    var card = btn.closest('.dash-leave-request');
                    btn.disabled = true;
                    btn.textContent = action === 'approve' ? 'Approving...' : 'Rejecting...';

                    try {
                        await requestJson('/api/leave/requests/' + leaveId + '/review', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ action: action })
                        });

                        card.style.transition = 'opacity 0.3s';
                        card.style.opacity = '0';
                        setTimeout(function () {
                            card.remove();
                            var section = document.getElementById('dashPendingLeave');
                            if (section && !section.querySelector('.dash-leave-request')) {
                                section.remove();
                            }
                            // Re-render dashboard to refresh on-leave list
                            if (action === 'approve') renderDashboard();
                        }, 300);
                    } catch (err) {
                        btn.disabled = false;
                        btn.textContent = action === 'approve' ? 'Approve' : 'Reject';
                        alert(err.message || 'Failed to ' + action);
                    }
                });
            });

            // Cancellation-request cards (approved leave + cancellation_requested_at)
            // use the dedicated /review-cancellation endpoint — the regular /review
            // path above only accepts pending leaves. Mirrors the HR Leave page.
            root.querySelectorAll('.dash-leave-approve-cancel, .dash-leave-reject-cancel').forEach(function (btn) {
                btn.addEventListener('click', async function () {
                    var leaveId = btn.getAttribute('data-id');
                    var approve = btn.classList.contains('dash-leave-approve-cancel');
                    var card = btn.closest('.dash-leave-request');
                    var note = '';
                    if (approve) {
                        if (!confirm('Approve cancellation? This leave will be cancelled.')) return;
                    } else {
                        note = prompt('Reason for declining the cancellation (optional):');
                        if (note === null) return; // cancelled the prompt
                    }
                    btn.disabled = true;
                    btn.textContent = approve ? 'Cancelling...' : 'Declining...';

                    try {
                        await requestJson('/api/leave/requests/' + leaveId + '/review-cancellation', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify(approve ? { action: 'approve' } : { action: 'reject', note: note || '' })
                        });

                        card.style.transition = 'opacity 0.3s';
                        card.style.opacity = '0';
                        setTimeout(function () {
                            card.remove();
                            var section = document.getElementById('dashPendingLeave');
                            if (section && !section.querySelector('.dash-leave-request')) {
                                section.remove();
                            }
                            // Approving a cancellation frees that day → refresh on-leave list.
                            if (approve) renderDashboard();
                        }, 300);
                    } catch (err) {
                        btn.disabled = false;
                        btn.textContent = approve ? 'Approve Cancellation' : 'Reject Cancellation';
                        alert(err.message || 'Failed to update cancellation');
                    }
                });
            });

            // Awaiting verification — row / "Open task" opens the slide-over to
            // review the work; "Verify & close" calls the reporter verify endpoint.
            function removeVerifyItem(item) {
                if (!item) return;
                item.style.transition = 'opacity 0.2s';
                item.style.opacity = '0';
                setTimeout(function () {
                    item.remove();
                    var section = document.getElementById('dashVerifySection');
                    if (section && !section.querySelector('.dash-verify-item')) {
                        section.remove();
                    } else if (section) {
                        var countEl = section.querySelector('.dash-section-count');
                        if (countEl) countEl.textContent = section.querySelectorAll('.dash-verify-item').length;
                    }
                }, 200);
            }

            // Blocker inbox handlers: Open toggles the details panel; Clear
            // notification dismisses every current blocker in one POST and
            // removes the section.
            var blockerOpenBtn = root.querySelector('.dash-blocker-open-all');
            var blockerDetails = root.querySelector('#dashBlockerDetails');
            if (blockerOpenBtn && blockerDetails) {
                blockerOpenBtn.addEventListener('click', function () {
                    var willShow = blockerDetails.hasAttribute('hidden');
                    if (willShow) blockerDetails.removeAttribute('hidden');
                    else blockerDetails.setAttribute('hidden', '');
                    blockerOpenBtn.setAttribute('aria-expanded', willShow ? 'true' : 'false');
                    blockerOpenBtn.textContent = willShow ? 'Hide' : 'Open';
                });
            }

            root.querySelectorAll('.dash-blocker-task-link').forEach(function (link) {
                link.addEventListener('click', function (e) {
                    e.preventDefault();
                    var tid = parseInt(link.getAttribute('data-task-id'), 10);
                    if (!tid) return;
                    if (window.TasksModule && window.TasksModule.openTaskSlideover) {
                        window.TasksModule.openTaskSlideover(tid);
                    } else if (MeetingModule && MeetingModule.switchView) {
                        MeetingModule.switchView('tasks');
                    }
                });
            });

            var blockerClearAllBtn = root.querySelector('.dash-blocker-clear-all');
            if (blockerClearAllBtn) {
                blockerClearAllBtn.addEventListener('click', async function () {
                    var section = document.getElementById('dashBlockerSection');
                    var buttons = section ? section.querySelectorAll('button') : [];
                    buttons.forEach(function (b) { b.disabled = true; });
                    blockerClearAllBtn.textContent = 'Clearing...';
                    try {
                        await requestJson('/api/tessa/tasks/blockers/dismiss-all', { method: 'POST' });
                        if (section) {
                            section.style.transition = 'opacity 0.2s';
                            section.style.opacity = '0';
                            setTimeout(function () { section.remove(); }, 200);
                        }
                    } catch (err) {
                        buttons.forEach(function (b) { b.disabled = false; });
                        blockerClearAllBtn.textContent = 'Clear notification';
                        alert((err && err.message) || 'Failed to clear notification');
                    }
                });
            }

            root.querySelectorAll('.dash-verify-item').forEach(function (item) {
                item.addEventListener('click', function (e) {
                    if (e.target.closest('button')) return;
                    var tid = parseInt(item.getAttribute('data-task-id'), 10);
                    if (!tid) return;
                    if (window.TasksModule && window.TasksModule.openTaskSlideover) {
                        window.TasksModule.openTaskSlideover(tid);
                    } else if (MeetingModule && MeetingModule.switchView) {
                        MeetingModule.switchView('tasks');
                    }
                });
            });

            root.querySelectorAll('.dash-verify-open').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var tid = parseInt(btn.getAttribute('data-task-id'), 10);
                    if (!tid) return;
                    if (window.TasksModule && window.TasksModule.openTaskSlideover) {
                        window.TasksModule.openTaskSlideover(tid);
                    } else if (MeetingModule && MeetingModule.switchView) {
                        MeetingModule.switchView('tasks');
                    }
                });
            });

            root.querySelectorAll('.dash-verify-close').forEach(function (btn) {
                btn.addEventListener('click', async function () {
                    var taskId = btn.getAttribute('data-task-id');
                    var item = btn.closest('.dash-verify-item');
                    var siblings = item ? item.querySelectorAll('button') : [];
                    siblings.forEach(function (b) { b.disabled = true; });
                    btn.textContent = 'Closing...';
                    try {
                        await requestJson('/api/tessa/tasks/' + taskId + '/verify', { method: 'POST' });
                        removeVerifyItem(item);
                    } catch (err) {
                        siblings.forEach(function (b) { b.disabled = false; });
                        btn.innerHTML = 'Verify &amp; close';
                        alert((err && err.message) || 'Failed to verify task');
                    }
                });
            });

            // ── Suggestion (huddle insight) handlers ──────────────────────
            // Compact rows show the title + actions; clicking the title opens a
            // read-only detail modal. Ignore/Clear/Clear-all all dismiss (hide
            // from the dashboard but keep the row in Slack Insights history).
            function removeMeetingInsightItem(item) {
                if (!item) return;
                var group = item.closest('.dash-mi-group');
                item.style.transition = 'opacity 0.2s';
                item.style.opacity = '0';
                setTimeout(function () {
                    item.remove();
                    // Update (or remove) the row's meeting box.
                    if (group) {
                        var gRemaining = group.querySelectorAll('.dash-mi-row').length;
                        var gCount = group.querySelector('.dash-mi-group-count');
                        if (gCount) gCount.textContent = gRemaining;
                        if (gRemaining === 0) group.remove();
                    }
                    var section = document.getElementById('dashMeetingInsightsSection');
                    if (!section) return;
                    var remaining = section.querySelectorAll('.dash-mi-row').length;
                    var countEl = section.querySelector('.dash-section-count');
                    if (countEl) countEl.textContent = remaining;
                    if (remaining === 0) section.remove();
                }, 200);
            }

            function createTaskFromInsight(insightId, assignedTo, button, item) {
                var siblings = item ? item.querySelectorAll('button') : [];
                siblings.forEach(function (b) { b.disabled = true; });
                var originalLabel = button.textContent;
                button.textContent = 'Creating…';
                var body = {};
                if (assignedTo) body.assigned_to = parseInt(assignedTo, 10);
                requestJson('/api/slack/insights/' + insightId + '/create-task', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(body)
                }).then(function () {
                    button.textContent = 'Task Created';
                    setTimeout(function () { removeMeetingInsightItem(item); }, 400);
                }).catch(function (err) {
                    siblings.forEach(function (b) { b.disabled = false; });
                    button.textContent = originalLabel;
                    alert((err && err.message) || 'Failed to create task from insight');
                });
            }

            // Dismiss = hide from the dashboard feed but keep the record (still
            // listed under Slack Insights → History). Shared by Ignore/Clear/Clear-all.
            function dismissInsightReq(insightId) {
                return requestJson('/api/slack/insights/' + insightId, {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ status: 'dismissed' })
                });
            }

            function findMeetingInsight(id) {
                id = parseInt(id, 10);
                for (var k = 0; k < meetingInsights.length; k++) {
                    if (parseInt(meetingInsights[k].id, 10) === id) return meetingInsights[k];
                }
                return null;
            }

            // Read-only detail modal (opened from a row title). Built on the shared
            // mtg-modal overlay; renders only the fields that are present.
            function openMeetingInsightDetails(ins) {
                var existing = document.getElementById('miDetailOverlay');
                if (existing) existing.remove();

                var typeLabels = { action_item: 'Action item', reminder: 'Reminder', follow_up: 'Follow-up', decision: 'Decision' };
                var typeIcons  = { action_item: '📋', reminder: '⏰', follow_up: '🔄', decision: '✅' };
                var icon       = typeIcons[ins.type] || '💡';
                var typeLabel  = typeLabels[ins.type] || ins.type;

                var assignerName  = (ins.assigned_by && ins.assigned_by.name) ? ins.assigned_by.name : (ins.mentioned_by || '');
                var suggestedName = (ins.suggested_assignee && ins.suggested_assignee.name) ? ins.suggested_assignee.name : '';
                var meetingVal    = (ins.meeting_label || ins.meeting_title)
                    ? escapeHtml(ins.meeting_label || ins.meeting_title) + (ins.meeting_date ? ' · ' + escapeHtml(ins.meeting_date) : '')
                    : '';
                function metaRow(label, valueHtml) {
                    if (!valueHtml) return '';
                    return '<div class="mi-detail-row"><span class="mi-detail-label">' + escapeHtml(label) + '</span>' +
                        '<span class="mi-detail-value">' + valueHtml + '</span></div>';
                }

                var rowsHtml = '';
                rowsHtml += metaRow('Type', '<span class="dash-mi-chip dash-mi-chip--type dash-mi-chip--type-' + escapeHtml(ins.type) + '">' + escapeHtml(typeLabel) + '</span>');
                rowsHtml += metaRow('Priority', '<span class="dash-mi-chip dash-mi-chip--pri dash-mi-chip--pri-' + escapeHtml(ins.priority) + '">' + escapeHtml(ins.priority) + '</span>');
                rowsHtml += metaRow('Assigned by', assignerName ? escapeHtml(assignerName) : '');
                rowsHtml += metaRow('Suggested assignee', suggestedName ? escapeHtml(suggestedName) : '');
                rowsHtml += metaRow('Due', ins.due_date ? escapeHtml(ins.due_date) : '');
                rowsHtml += metaRow('Meeting', meetingVal);

                var overlay = document.createElement('div');
                overlay.id = 'miDetailOverlay';
                overlay.className = 'mtg-modal-overlay';
                overlay.innerHTML =
                    '<div class="mtg-modal mi-detail-modal">' +
                        '<div class="mtg-modal-header">' +
                            '<h3 class="mtg-modal-title">' + icon + ' ' + escapeHtml(ins.title) + '</h3>' +
                            '<button type="button" class="mtg-modal-close" id="miDetailClose">&#x2715;</button>' +
                        '</div>' +
                        '<div class="mtg-modal-body mi-detail-body">' +
                            (ins.summary ? '<div class="mi-detail-desc">' + escapeHtml(ins.summary) + '</div>' : '') +
                            '<div class="mi-detail-meta">' + rowsHtml + '</div>' +
                        '</div>' +
                    '</div>';
                document.body.appendChild(overlay);

                function onKey(e) { if (e.key === 'Escape') closeModal(); }
                function closeModal() {
                    var el = document.getElementById('miDetailOverlay');
                    if (el) el.remove();
                    document.removeEventListener('keydown', onKey);
                }
                document.addEventListener('keydown', onKey);
                overlay.querySelector('#miDetailClose').onclick = closeModal;
                overlay.onclick = function (e) { if (e.target === overlay) closeModal(); };
            }

            root.querySelectorAll('.dash-mi-add-task').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var item = btn.closest('.dash-mi-row');
                    var insightId = btn.getAttribute('data-insight-id');
                    var assignedTo = btn.getAttribute('data-assignee') || null;
                    createTaskFromInsight(insightId, assignedTo, btn, item);
                });
            });

            root.querySelectorAll('.dash-mi-snooze-toggle').forEach(function (btn) {
                btn.addEventListener('click', function (e) {
                    e.stopPropagation();
                    var wrap = btn.parentNode;
                    var menu = wrap.querySelector('.dash-mi-snooze-menu');
                    if (!menu) return;
                    var wasHidden = menu.hasAttribute('hidden');
                    document.querySelectorAll('.dash-mi-snooze-menu').forEach(function (m) { m.setAttribute('hidden', ''); });
                    if (wasHidden) menu.removeAttribute('hidden');
                    var dismiss = function (ev) {
                        if (wrap.contains(ev.target)) return;
                        menu.setAttribute('hidden', '');
                        document.removeEventListener('mousedown', dismiss, true);
                    };
                    setTimeout(function () { document.addEventListener('mousedown', dismiss, true); }, 0);
                });
            });

            root.querySelectorAll('.dash-mi-snooze-menu button[data-snooze]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var preset = btn.getAttribute('data-snooze');
                    var wrap = btn.closest('.dash-mi-snooze-wrap');
                    var item = btn.closest('.dash-mi-row');
                    var insightId = item.getAttribute('data-insight-id');
                    var menu = wrap.querySelector('.dash-mi-snooze-menu');
                    if (menu) menu.setAttribute('hidden', '');

                    var until = null;
                    var now = new Date();
                    if (preset === '1h') until = new Date(now.getTime() + 60 * 60 * 1000);
                    else if (preset === '4h') until = new Date(now.getTime() + 4 * 60 * 60 * 1000);
                    else if (preset === 'tomorrow') {
                        until = new Date(now.getTime() + 24 * 60 * 60 * 1000);
                        until.setHours(9, 0, 0, 0);
                    } else if (preset === 'custom') {
                        var pad = function (n) { return n < 10 ? '0' + n : n; };
                        var defaultDt = new Date(now.getTime() + 60 * 60 * 1000);
                        var defaultStr = defaultDt.getFullYear() + '-' + pad(defaultDt.getMonth() + 1) + '-' + pad(defaultDt.getDate()) + ' ' +
                            pad(defaultDt.getHours()) + ':' + pad(defaultDt.getMinutes());
                        var input = prompt('Snooze until (YYYY-MM-DD HH:MM):', defaultStr);
                        if (!input) return;
                        var parsed = new Date(input.replace(' ', 'T'));
                        if (isNaN(parsed.getTime()) || parsed.getTime() <= now.getTime()) {
                            alert('Please enter a future date in YYYY-MM-DD HH:MM format');
                            return;
                        }
                        until = parsed;
                    }
                    if (!until) return;

                    requestJson('/api/slack/insights/' + insightId + '/snooze', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ until: until.toISOString() })
                    }).then(function () {
                        removeMeetingInsightItem(item);
                    }).catch(function (err) {
                        alert((err && err.message) || 'Failed to snooze insight');
                    });
                });
            });

            // Ignore: dismiss a single notification (hide from dashboard, keep in history).
            root.querySelectorAll('.dash-mi-ignore').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var item = btn.closest('.dash-mi-row');
                    var insightId = btn.getAttribute('data-insight-id');
                    var siblings = item ? item.querySelectorAll('button') : [];
                    siblings.forEach(function (b) { b.disabled = true; });
                    dismissInsightReq(insightId).then(function () {
                        removeMeetingInsightItem(item);
                    }).catch(function (err) {
                        siblings.forEach(function (b) { b.disabled = false; });
                        alert((err && err.message) || 'Failed to clear notification');
                    });
                });
            });

            // Per-box "Clear": dismiss every notification in one meeting box at
            // once (view-only — records stay in Slack Insights history).
            root.querySelectorAll('.dash-mi-group-clear').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var group = btn.closest('.dash-mi-group');
                    if (!group) return;
                    var rows = Array.prototype.slice.call(group.querySelectorAll('.dash-mi-row'));
                    if (!rows.length) return;
                    var titleEl = group.querySelector('.dash-mi-group-title');
                    var label = titleEl ? titleEl.textContent : 'this meeting';
                    if (!confirm('Clear all ' + rows.length + ' notification' + (rows.length === 1 ? '' : 's') + ' from "' + label + '"?\nThey stay available in Slack Insights history.')) return;
                    btn.disabled = true;
                    group.querySelectorAll('button').forEach(function (b) { b.disabled = true; });
                    var jobs = rows.map(function (row) {
                        return dismissInsightReq(row.getAttribute('data-insight-id'))
                            .then(function () { return { row: row, ok: true }; })
                            .catch(function () { return { row: row, ok: false }; });
                    });
                    Promise.all(jobs).then(function (results) {
                        var failedRows = [];
                        results.forEach(function (r) {
                            if (r.ok) removeMeetingInsightItem(r.row);
                            else failedRows.push(r.row);
                        });
                        if (failedRows.length) {
                            btn.disabled = false;
                            failedRows.forEach(function (row) {
                                row.querySelectorAll('button').forEach(function (b) { b.disabled = false; });
                            });
                            alert(failedRows.length + ' notification' + (failedRows.length === 1 ? '' : 's') + ' could not be cleared. Please try again.');
                        }
                    });
                });
            });

            // "Clear dashboard": dismiss the whole feed at once (soft — records
            // stay in Slack Insights history). One call, then drop the section.
            var miClearAllBtn = root.querySelector('#dashMiClearAll');
            if (miClearAllBtn) miClearAllBtn.addEventListener('click', function () {
                if (!confirm('Clear all suggestions from your dashboard? They stay available in your Slack Insights history.')) return;
                miClearAllBtn.disabled = true;
                requestJson('/api/slack/insights', { method: 'DELETE' }).then(function () {
                    var sec = document.getElementById('dashMeetingInsightsSection');
                    if (sec) sec.remove();
                }).catch(function (err) {
                    miClearAllBtn.disabled = false;
                    alert((err && err.message) || 'Failed to clear suggestions');
                });
            });

            // Row title → read-only detail modal.
            root.querySelectorAll('.dash-mi-row-title').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var ins = findMeetingInsight(btn.getAttribute('data-insight-id'));
                    if (ins) openMeetingInsightDetails(ins);
                });
            });

            // ===== Gmail tab: AI-classified important emails =====
            // Mirrors the Slack insight cards/actions but personal-only and hitting
            // /api/gmail/insights. (Function declarations are hoisted, so the
            // assembly above can call renderGmailInsights before this point.)
            function findGmailInsight(id) {
                id = parseInt(id, 10);
                for (var k = 0; k < gmailInsights.length; k++) {
                    if (parseInt(gmailInsights[k].id, 10) === id) return gmailInsights[k];
                }
                return null;
            }

            function renderGmailInsights(list) {
                if (!config.googleConnected) {
                    return '<div class="dash-gm-empty">' +
                        '<p>Connect your Gmail to surface important emails — meetings, clients, approvals and alerts — here.</p>' +
                        '<button type="button" class="btn btn-primary btn-sm" id="dashGmConnect">Connect Gmail in Profile</button>' +
                        '</div>';
                }
                if (!list || list.length === 0) {
                    return '<div class="dash-gm-empty"><p>No important emails right now.</p></div>';
                }
                var rows = '';
                for (var i = 0; i < list.length; i++) {
                    var g = list[i];
                    rows += '<div class="dash-gm-card" data-insight-id="' + g.id + '">' +
                        '<button type="button" class="dash-gm-open" data-insight-id="' + g.id + '" title="View details">' +
                            '<span class="dash-gm-title">' + escapeHtml(g.subject || '(no subject)') + '</span>' +
                            (g.sender ? '<span class="dash-gm-sender">' + escapeHtml(gmailSenderName(g.sender)) + '</span>' : '') +
                        '</button>' +
                        '<div class="dash-gm-actions">' +
                            '<button type="button" class="btn btn-success btn-sm dash-gm-add-task" data-insight-id="' + g.id + '">Add to Task</button>' +
                            '<div class="dash-gm-snooze-wrap">' +
                                '<button type="button" class="btn btn-outline-secondary btn-sm dash-gm-snooze-toggle" data-insight-id="' + g.id + '">Set Reminder ▾</button>' +
                                '<div class="dash-gm-snooze-menu" hidden>' +
                                    '<button type="button" data-snooze="1h">In 1 hour</button>' +
                                    '<button type="button" data-snooze="4h">In 4 hours</button>' +
                                    '<button type="button" data-snooze="tomorrow">Tomorrow 9 AM</button>' +
                                    '<button type="button" data-snooze="custom">Custom…</button>' +
                                '</div>' +
                            '</div>' +
                            '<button type="button" class="btn btn-outline-secondary btn-sm dash-gm-ignore" data-insight-id="' + g.id + '">Ignore</button>' +
                        '</div>' +
                    '</div>';
                }
                return '<div class="dash-gm-list">' + rows + '</div>';
            }

            function removeGmailInsightItem(item) {
                if (!item) return;
                item.style.transition = 'opacity 0.2s';
                item.style.opacity = '0';
                setTimeout(function () {
                    item.remove();
                    var list = root.querySelector('.dash-gm-list');
                    var remaining = list ? list.querySelectorAll('.dash-gm-card').length : 0;
                    var badge = root.querySelector('.dash-tab[data-dashtab="gmail"] .dash-tab-badge');
                    if (badge) { if (remaining > 0) badge.textContent = remaining; else badge.remove(); }
                    if (remaining === 0 && list && list.parentNode) {
                        list.parentNode.innerHTML = '<div class="dash-gm-empty"><p>No important emails right now.</p></div>';
                    }
                }, 200);
            }

            var gmConnectBtn = document.getElementById('dashGmConnect');
            if (gmConnectBtn) gmConnectBtn.addEventListener('click', function () {
                if (MeetingModule && MeetingModule.switchView) MeetingModule.switchView('profile');
            });

            root.querySelectorAll('.dash-gm-open').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var g = findGmailInsight(btn.getAttribute('data-insight-id'));
                    if (g) openGmailInsightDetails(g);
                });
            });

            root.querySelectorAll('.dash-gm-add-task').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var item = btn.closest('.dash-gm-card');
                    var insightId = btn.getAttribute('data-insight-id');
                    var siblings = item ? item.querySelectorAll('button') : [];
                    siblings.forEach(function (b) { b.disabled = true; });
                    var orig = btn.textContent;
                    btn.textContent = 'Creating…';
                    requestJson('/api/gmail/insights/' + insightId + '/create-task', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({})
                    }).then(function () {
                        btn.textContent = 'Task Created';
                        setTimeout(function () { removeGmailInsightItem(item); }, 400);
                    }).catch(function (err) {
                        siblings.forEach(function (b) { b.disabled = false; });
                        btn.textContent = orig;
                        alert((err && err.message) || 'Failed to create task from email');
                    });
                });
            });

            root.querySelectorAll('.dash-gm-snooze-toggle').forEach(function (btn) {
                btn.addEventListener('click', function (e) {
                    e.stopPropagation();
                    var wrap = btn.parentNode;
                    var menu = wrap.querySelector('.dash-gm-snooze-menu');
                    if (!menu) return;
                    var wasHidden = menu.hasAttribute('hidden');
                    document.querySelectorAll('.dash-gm-snooze-menu').forEach(function (m) { m.setAttribute('hidden', ''); });
                    if (wasHidden) menu.removeAttribute('hidden');
                    var dismiss = function (ev) {
                        if (wrap.contains(ev.target)) return;
                        menu.setAttribute('hidden', '');
                        document.removeEventListener('mousedown', dismiss, true);
                    };
                    setTimeout(function () { document.addEventListener('mousedown', dismiss, true); }, 0);
                });
            });

            root.querySelectorAll('.dash-gm-snooze-menu button[data-snooze]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var preset = btn.getAttribute('data-snooze');
                    var wrap = btn.closest('.dash-gm-snooze-wrap');
                    var item = btn.closest('.dash-gm-card');
                    var insightId = item.getAttribute('data-insight-id');
                    var menu = wrap.querySelector('.dash-gm-snooze-menu');
                    if (menu) menu.setAttribute('hidden', '');

                    var until = null;
                    var now = new Date();
                    if (preset === '1h') until = new Date(now.getTime() + 60 * 60 * 1000);
                    else if (preset === '4h') until = new Date(now.getTime() + 4 * 60 * 60 * 1000);
                    else if (preset === 'tomorrow') {
                        until = new Date(now.getTime() + 24 * 60 * 60 * 1000);
                        until.setHours(9, 0, 0, 0);
                    } else if (preset === 'custom') {
                        var pad = function (n) { return n < 10 ? '0' + n : n; };
                        var defaultDt = new Date(now.getTime() + 60 * 60 * 1000);
                        var defaultStr = defaultDt.getFullYear() + '-' + pad(defaultDt.getMonth() + 1) + '-' + pad(defaultDt.getDate()) + ' ' +
                            pad(defaultDt.getHours()) + ':' + pad(defaultDt.getMinutes());
                        var input = prompt('Snooze until (YYYY-MM-DD HH:MM):', defaultStr);
                        if (!input) return;
                        var parsed = new Date(input.replace(' ', 'T'));
                        if (isNaN(parsed.getTime()) || parsed.getTime() <= now.getTime()) {
                            alert('Please enter a future date in YYYY-MM-DD HH:MM format');
                            return;
                        }
                        until = parsed;
                    }
                    if (!until) return;

                    requestJson('/api/gmail/insights/' + insightId + '/snooze', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ until: until.toISOString() })
                    }).then(function () {
                        removeGmailInsightItem(item);
                    }).catch(function (err) {
                        alert((err && err.message) || 'Failed to snooze email');
                    });
                });
            });

            root.querySelectorAll('.dash-gm-ignore').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var item = btn.closest('.dash-gm-card');
                    var insightId = btn.getAttribute('data-insight-id');
                    var siblings = item ? item.querySelectorAll('button') : [];
                    siblings.forEach(function (b) { b.disabled = true; });
                    requestJson('/api/gmail/insights/' + insightId, {
                        method: 'PUT',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ status: 'dismissed' })
                    }).then(function () {
                        removeGmailInsightItem(item);
                    }).catch(function (err) {
                        siblings.forEach(function (b) { b.disabled = false; });
                        alert((err && err.message) || 'Failed to clear email');
                    });
                });
            });

            // ===== Notification-center tab switching (pure show/hide) =====
            root.querySelectorAll('.dash-tab').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    dashActiveTab = btn.getAttribute('data-dashtab');
                    root.querySelectorAll('.dash-tab').forEach(function (b) { b.classList.toggle('active', b === btn); });
                    root.querySelectorAll('.dash-tab-panel').forEach(function (p) {
                        p.classList.toggle('active', p.getAttribute('data-dashpanel') === dashActiveTab);
                    });
                });
            });

            // Lazy-load the personal Calendar card (today + upcoming notes from
            // the user's own Google Calendar) and set its badge to today's count.
            if (hasCalendarTab && window.TessaCalendar) {
                var _calPanel = root.querySelector('.dash-tab-panel[data-dashpanel="calendar"]');
                if (_calPanel) window.TessaCalendar.fillDashboardUpcoming(_calPanel);
            }

            // Deadline extension handlers
            function removeExtensionItem(item) {
                if (!item) return;
                item.style.transition = 'opacity 0.2s';
                item.style.opacity = '0';
                setTimeout(function () {
                    item.remove();
                    var section = document.getElementById('dashExtensionSection');
                    if (section && !section.querySelector('.dash-extension-item')) {
                        section.remove();
                    } else if (section) {
                        var countEl = section.querySelector('.dash-section-count');
                        if (countEl) {
                            var remaining = section.querySelectorAll('.dash-extension-item').length;
                            countEl.textContent = remaining;
                        }
                    }
                }, 200);
            }

            root.querySelectorAll('.dash-ext-clear').forEach(function (btn) {
                btn.addEventListener('click', async function () {
                    var taskId = btn.getAttribute('data-task-id');
                    var item = btn.closest('.dash-extension-item');
                    btn.disabled = true;
                    try {
                        await requestJson('/api/tessa/tasks/' + taskId + '/clear-extension-notice', { method: 'POST' });
                        removeExtensionItem(item);
                    } catch (err) {
                        btn.disabled = false;
                        alert((err && err.message) || 'Failed to clear notice');
                    }
                });
            });

            root.querySelectorAll('.dash-ext-approve, .dash-ext-reject').forEach(function (btn) {
                btn.addEventListener('click', async function () {
                    var taskId = btn.getAttribute('data-task-id');
                    var isApprove = btn.classList.contains('dash-ext-approve');
                    var item = btn.closest('.dash-extension-item');
                    var siblings = item ? item.querySelectorAll('button') : [];
                    siblings.forEach(function (b) { b.disabled = true; });
                    btn.textContent = isApprove ? 'Approving...' : 'Rejecting...';
                    var url = '/api/tessa/tasks/' + taskId + (isApprove ? '/approve-extension' : '/deny-extension');
                    try {
                        await requestJson(url, { method: 'POST' });
                        removeExtensionItem(item);
                    } catch (err) {
                        siblings.forEach(function (b) { b.disabled = false; });
                        btn.textContent = isApprove ? 'Approve' : 'Reject';
                        alert((err && err.message) || 'Failed to ' + (isApprove ? 'approve' : 'reject'));
                    }
                });
            });

            // Parse health & progress from note text
            function parseNoteHealth(note) {
                var lower = (note || '').toLowerCase();
                if (/\b(blocked|stuck|waiting for|waiting on|can'?t proceed)\b/.test(lower)) return 'blocked';
                if (/\b(risk|delay|might not|slow|behind|concern)\b/.test(lower)) return 'at_risk';
                return 'on_track';
            }
            function parseNoteProgress(note, fallback) {
                var m = (note || '').match(/(\d{1,3})\s*%/);
                if (m) { var v = parseInt(m[1], 10); if (v >= 0 && v <= 100) return v; }
                return fallback;
            }

            // Tessa date row submit handlers
            root.querySelectorAll('.dash-tessa-daterow').forEach(function (row) {
                var taskId = parseInt(row.getAttribute('data-task-id'), 10);
                var checkinDate = row.getAttribute('data-checkin-date');
                var currentProgress = parseInt(row.getAttribute('data-progress'), 10) || 0;
                var noteEl = row.querySelector('.dash-tessa-note');
                var submitBtn = row.querySelector('.dash-tessa-submit');
                var errorEl = row.querySelector('.dash-tessa-error');

                submitBtn.addEventListener('click', async function () {
                    var note = noteEl.value.trim();
                    if (!note) { noteEl.focus(); noteEl.placeholder = 'Please type an update...'; return; }

                    submitBtn.disabled = true;
                    submitBtn.textContent = 'Submitting...';
                    errorEl.textContent = '';

                    try {
                        var res = await requestJson('/api/tessa/tasks/' + taskId + '/checkins', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({
                                health_status: parseNoteHealth(note),
                                progress: parseNoteProgress(note, currentProgress),
                                note: note,
                                checkin_date: checkinDate
                            })
                        });
                        if (res.error) throw new Error(res.error);

                        // Fade out the date row
                        row.style.transition = 'opacity 0.3s, max-height 0.3s';
                        row.style.opacity = '0';
                        setTimeout(function () {
                            row.remove();
                            // If no date rows left in this card, remove the whole card
                            var card = root.querySelector('.dash-tessa-card[data-task-group="' + taskId + '"]');
                            if (card && !card.querySelector('.dash-tessa-daterow')) {
                                card.style.transition = 'opacity 0.3s';
                                card.style.opacity = '0';
                                setTimeout(function () {
                                    card.remove();
                                    var section = document.getElementById('dashMySection');
                                    if (section && !section.querySelector('.dash-tessa-card')) {
                                        section.remove();
                                    }
                                }, 300);
                            }
                        }, 300);
                    } catch (err) {
                        submitBtn.disabled = false;
                        submitBtn.textContent = 'Submit';
                        errorEl.textContent = err.message || 'Failed to submit';
                    }
                });

                // Toggle submit button style
                noteEl.addEventListener('input', function () {
                    submitBtn.classList.toggle('has-text', !!noteEl.value.trim());
                });

                // Enter key submits
                noteEl.addEventListener('keydown', function (ev) {
                    if (ev.key === 'Enter' && !ev.shiftKey) {
                        ev.preventDefault();
                        submitBtn.click();
                    }
                });
            });

            // "Open ↗" links → navigate to task
            root.querySelectorAll('.dash-tessa-open').forEach(function (link) {
                link.addEventListener('click', function (e) {
                    e.preventDefault();
                    var id = parseInt(link.getAttribute('data-task-id'), 10);
                    if (MeetingModule && MeetingModule.switchView) MeetingModule.switchView('tasks');
                    setTimeout(function () {
                        if (TasksModule && TasksModule.openTaskModal) TasksModule.openTaskModal(id);
                    }, 300);
                });
            });

            // Team task rows → navigate to task modal
            root.querySelectorAll('.dash-task-item[data-task-id]').forEach(function (row) {
                row.addEventListener('click', function (e) {
                    e.preventDefault();
                    var id = parseInt(row.getAttribute('data-task-id'), 10);
                    if (MeetingModule && MeetingModule.switchView) MeetingModule.switchView('tasks');
                    setTimeout(function () {
                        if (TasksModule && TasksModule.openTaskModal) TasksModule.openTaskModal(id);
                    }, 300);
                });
            });

            // Fetch AI-generated questions (cached in localStorage per day + item keys)
            if (myItems.length > 0) {
                var todayKey = dateKey(today);
                var cacheKey = 'tessa_q_' + (config.userId || '') + '_' + todayKey;
                var itemKeys = myItems.map(function (mi) { return mi.id + '_' + mi.checkin_date; }).sort().join(',');
                var cached = null;
                try {
                    var raw = localStorage.getItem(cacheKey);
                    if (raw) {
                        var parsed = JSON.parse(raw);
                        // Valid if same item keys (tasks haven't changed)
                        if (parsed.itemKeys === itemKeys && parsed.questions) {
                            cached = parsed.questions;
                        }
                    }
                } catch (e) { /* ignore */ }

                function applyQuestions(questions) {
                    root.querySelectorAll('.dash-tessa-card[data-q-key]').forEach(function (card) {
                        var key = card.getAttribute('data-q-key');
                        var qEl = card.querySelector('.dash-tessa-qtext');
                        if (qEl && questions[key]) {
                            qEl.innerHTML = questions[key];
                        } else if (qEl) {
                            qEl.innerHTML = card.getAttribute('data-fallback') || '';
                        }
                    });
                }

                if (cached) {
                    // Use cached questions — no API call
                    applyQuestions(cached);
                } else {
                    // Fetch from OpenRouter, then cache
                    requestJson('/api/tessa/tasks/checkin-questions').then(function (data) {
                        var questions = data.questions || {};
                        applyQuestions(questions);
                        // Cache for today
                        try {
                            localStorage.setItem(cacheKey, JSON.stringify({ itemKeys: itemKeys, questions: questions }));
                        } catch (e) { /* ignore */ }
                    }).catch(function () {
                        root.querySelectorAll('.dash-tessa-card[data-q-key]').forEach(function (card) {
                            var qEl = card.querySelector('.dash-tessa-qtext');
                            if (qEl) qEl.innerHTML = card.getAttribute('data-fallback') || '';
                        });
                    });
                }
            }

            // --- Dashboard Notes event handlers ---
            var dnCardsEl = document.getElementById('dnCards');

            // Scheduled (off-day) monthly reminders: expand/collapse the drawer.
            var dnSchedToggle = document.getElementById('dnSchedToggle');
            if (dnSchedToggle) dnSchedToggle.onclick = function () {
                var list = document.getElementById('dnSchedList');
                if (!list) return;
                var nowHidden = list.classList.toggle('hidden');
                var caret = dnSchedToggle.querySelector('.dn-sched-caret');
                if (caret) caret.textContent = nowHidden ? '▸' : '▾';
            };

            // "Add Note" → insert inline creation card
            var addBtn = document.getElementById('dnAddBtn');
            if (addBtn) addBtn.onclick = function () {
                if (dnCardsEl.querySelector('.dn-card--new')) return;
                var card = document.createElement('div');
                card.className = 'dn-card dn-card--new';
                card.innerHTML = '<div class="dn-new-items"></div>' +
                    '<div class="dn-new-input-row">' +
                        '<input type="text" class="input input-sm dn-new-input" placeholder="Add item and press Enter...">' +
                    '</div>' +
                    '<div class="dn-card-footer">' +
                        buildReminderControls(null, null, null, null) +
                        '<div class="dn-new-actions">' +
                            '<button type="button" class="btn btn-sm dn-new-cancel">Cancel</button>' +
                            '<button type="button" class="btn btn-primary btn-sm dn-new-save">Save</button>' +
                        '</div>' +
                    '</div>';
                dnCardsEl.prepend(card);

                wireReminderToggle(card.querySelector('.dn-reminder-wrap'));

                var inp = card.querySelector('.dn-new-input');
                var itemsWrap = card.querySelector('.dn-new-items');
                inp.focus();

                function addNewItem(text) {
                    if (!text) return;
                    var row = document.createElement('div');
                    row.className = 'dn-new-item-row';
                    row.innerHTML = '<span class="dn-new-item-text">' + escapeHtml(text) + '</span>' +
                        '<button type="button" class="dn-new-item-rm">\u00d7</button>';
                    row.querySelector('.dn-new-item-rm').onclick = function () { row.remove(); };
                    itemsWrap.appendChild(row);
                }

                inp.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        var t = inp.value.trim();
                        addNewItem(t);
                        inp.value = '';
                    }
                });

                card.querySelector('.dn-new-cancel').onclick = function () { card.remove(); };

                card.querySelector('.dn-new-save').onclick = async function () {
                    var items = [];
                    itemsWrap.querySelectorAll('.dn-new-item-text').forEach(function (el) {
                        items.push({ text: el.textContent.trim(), checked: false });
                    });
                    var pending = inp.value.trim();
                    if (pending) items.push({ text: pending, checked: false });
                    if (items.length === 0) { inp.focus(); return; }
                    var reminder = readReminderPayload(card);
                    if (card.querySelector('.dn-reminder-select').value === 'datetime' && !reminder.reminder_at) {
                        alert('Pick a date and time for the reminder.');
                        return;
                    }
                    if (card.querySelector('.dn-reminder-select').value === 'monthly' && !reminder.reminder_day) {
                        alert('Pick a day for the monthly reminder.');
                        return;
                    }
                    var saveBtn = card.querySelector('.dn-new-save');
                    saveBtn.disabled = true;
                    saveBtn.textContent = 'Saving...';
                    try {
                        await requestJson('/api/notes', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify(Object.assign({ items: items }, reminder))
                        });
                        renderDashboard();
                    } catch (err) {
                        saveBtn.disabled = false;
                        saveBtn.textContent = 'Save';
                        alert(err.message || 'Failed to save');
                    }
                };
            };

            // Checkbox toggle → fade out checked item, remove card if all done
            root.querySelectorAll('.dn-checkbox').forEach(function (cb) {
                cb.onchange = async function () {
                    var noteId = parseInt(cb.getAttribute('data-note-id'), 10);
                    var idx = parseInt(cb.getAttribute('data-idx'), 10);
                    var note = dashNotes.find(function (n) { return n.id === noteId; });
                    if (!note) return;
                    var updatedItems = note.items.map(function (it, i) {
                        return { text: it.text, checked: i === idx ? true : it.checked };
                    });
                    note.items = updatedItems;
                    var label = cb.closest('.dn-check-item');
                    if (label) {
                        label.style.transition = 'opacity 0.3s, max-height 0.3s';
                        label.style.opacity = '0';
                        label.style.maxHeight = '0';
                        label.style.overflow = 'hidden';
                    }
                    var card = cb.closest('.dn-card');
                    var allDone = !updatedItems.some(function (it) { return !it.checked; });
                    setTimeout(function () {
                        if (label) label.remove();
                        if (allDone && card) {
                            card.style.transition = 'opacity 0.3s';
                            card.style.opacity = '0';
                            setTimeout(function () { card.remove(); }, 300);
                        }
                    }, 300);
                    try {
                        await requestJson('/api/notes/' + noteId, {
                            method: 'PUT',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ items: updatedItems })
                        });
                    } catch (err) {
                        renderDashboard();
                    }
                };
            });

            // Reminder controls on inline cards: PUT immediately on change.
            // For "datetime" mode we wait until the datetime input has a value.
            root.querySelectorAll('.dn-card .dn-reminder-wrap[data-note-id]').forEach(function (wrap) {
                var noteId = parseInt(wrap.getAttribute('data-note-id'), 10);
                wireReminderToggle(wrap, async function () {
                    var payload = readReminderPayload(wrap);
                    if (wrap.querySelector('.dn-reminder-select').value === 'datetime' && !payload.reminder_at) {
                        return; // wait for user to pick a datetime
                    }
                    if (wrap.querySelector('.dn-reminder-select').value === 'monthly' && !payload.reminder_day) {
                        return; // wait for user to pick a day
                    }
                    try {
                        await requestJson('/api/notes/' + noteId, {
                            method: 'PUT',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify(payload)
                        });
                    } catch (err) { /* silent */ }
                });
            });

            // Delete buttons
            root.querySelectorAll('.dn-del-btn').forEach(function (btn) {
                btn.onclick = async function () {
                    var id = parseInt(btn.getAttribute('data-note-id'), 10);
                    if (!confirm('Delete this note?')) return;
                    var card = btn.closest('.dn-card');
                    btn.disabled = true;
                    try {
                        await requestJson('/api/notes/' + id, { method: 'DELETE' });
                        card.style.transition = 'opacity 0.3s';
                        card.style.opacity = '0';
                        setTimeout(function () { card.remove(); }, 300);
                    } catch (err) {
                        btn.disabled = false;
                    }
                };
            });

            // Edit buttons
            root.querySelectorAll('.dn-edit-btn').forEach(function (btn) {
                btn.onclick = function () {
                    var noteId = parseInt(btn.getAttribute('data-note-id'), 10);
                    var note = dashNotes.find(function (n) { return n.id === noteId; });
                    if (!note) return;
                    var card = btn.closest('.dn-card');
                    card.classList.add('dn-card--editing');
                    var editItems = '';
                    for (var ei = 0; ei < note.items.length; ei++) {
                        editItems += '<div class="dn-new-item-row" data-idx="' + ei + '">' +
                            '<span class="dn-new-item-text">' + escapeHtml(note.items[ei].text) + '</span>' +
                            (note.items[ei].checked ? '<span class="dn-edit-done-tag">done</span>' : '') +
                            '<button type="button" class="dn-new-item-rm">×</button>' +
                            '</div>';
                    }
                    card.innerHTML = '<div class="dn-new-items">' + editItems + '</div>' +
                        '<div class="dn-new-input-row">' +
                            '<input type="text" class="input input-sm dn-new-input" placeholder="Add item and press Enter...">' +
                        '</div>' +
                        '<div class="dn-card-footer">' +
                            buildReminderControls(note.reminder_interval, note.reminder_at, note.reminder_day, null) +
                            '<div class="dn-new-actions">' +
                                '<button type="button" class="btn btn-sm dn-new-cancel">Cancel</button>' +
                                '<button type="button" class="btn btn-primary btn-sm dn-new-save">Save</button>' +
                            '</div>' +
                        '</div>';
                    wireReminderToggle(card.querySelector('.dn-reminder-wrap'));
                    var inp = card.querySelector('.dn-new-input');
                    var itemsWrap = card.querySelector('.dn-new-items');
                    inp.focus();
                    card.querySelectorAll('.dn-new-item-rm').forEach(function (rm) {
                        rm.onclick = function () { rm.closest('.dn-new-item-row').remove(); };
                    });
                    inp.addEventListener('keydown', function (e) {
                        if (e.key === 'Enter') {
                            e.preventDefault();
                            var t = inp.value.trim();
                            if (!t) return;
                            var row = document.createElement('div');
                            row.className = 'dn-new-item-row';
                            row.innerHTML = '<span class="dn-new-item-text">' + escapeHtml(t) + '</span>' +
                                '<button type="button" class="dn-new-item-rm">×</button>';
                            row.querySelector('.dn-new-item-rm').onclick = function () { row.remove(); };
                            itemsWrap.appendChild(row);
                            inp.value = '';
                        }
                    });
                    card.querySelector('.dn-new-cancel').onclick = function () { renderDashboard(); };
                    card.querySelector('.dn-new-save').onclick = async function () {
                        var items = [];
                        itemsWrap.querySelectorAll('.dn-new-item-row').forEach(function (row) {
                            var text = row.querySelector('.dn-new-item-text').textContent.trim();
                            var origIdx = row.getAttribute('data-idx');
                            var checked = origIdx !== null && note.items[parseInt(origIdx, 10)] ? note.items[parseInt(origIdx, 10)].checked : false;
                            items.push({ text: text, checked: checked });
                        });
                        var pending = inp.value.trim();
                        if (pending) items.push({ text: pending, checked: false });
                        if (items.length === 0) { inp.focus(); return; }
                        var reminder = readReminderPayload(card);
                        if (card.querySelector('.dn-reminder-select').value === 'datetime' && !reminder.reminder_at) {
                            alert('Pick a date and time for the reminder.');
                            return;
                        }
                        if (card.querySelector('.dn-reminder-select').value === 'monthly' && !reminder.reminder_day) {
                            alert('Pick a day for the monthly reminder.');
                            return;
                        }
                        var saveBtn = card.querySelector('.dn-new-save');
                        saveBtn.disabled = true;
                        saveBtn.textContent = 'Saving...';
                        try {
                            await requestJson('/api/notes/' + noteId, {
                                method: 'PUT',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify(Object.assign({ items: items }, reminder))
                            });
                            renderDashboard();
                        } catch (err) {
                            saveBtn.disabled = false;
                            saveBtn.textContent = 'Save';
                            alert(err.message || 'Failed to save');
                        }
                    };
                };
            });

        } catch (e) {
            root.innerHTML = '<div class="dash-wrap"><div class="kpi-status-msg">Unable to load dashboard: ' + escapeHtml(e.message || 'Request failed') + '</div></div>';
        }
    }

    function dailyFieldList() {
        var out = [];
        getKpiGroups().forEach(function (group) {
            group.fields.forEach(function (field) {
                out.push({ key: field.key, label: field.label, group: group.name });
            });
        });
        return out;
    }

    function stripCommas(v) {
        return typeof v === 'string' ? v.replace(/,/g, '') : v;
    }
    function formatNum(n) {
        if (n === '' || n === null || n === undefined || isNaN(n)) return '';
        var num = typeof n === 'string' ? parseFloat(n) : n;
        if (isNaN(num)) return '';
        return num.toLocaleString('en-IN');
    }
    function computeWeeklySummaryLocal(days, fields, aggMap) {
        aggMap = aggMap || getAggregation();
        var result = {};
        fields.forEach(function (f) {
            var agg = aggMap[f.key] || 'sum';
            if (f.input_type === 'status') {
                var lastStatus = '';
                (days || []).forEach(function (d) {
                    var v = d.entries && d.entries[f.key] !== undefined ? String(d.entries[f.key]).trim() : '';
                    if (v !== '') lastStatus = v;
                });
                result[f.key] = lastStatus;
                return;
            }
            var vals = [];
            (days || []).forEach(function (d) {
                var v = d.entries && d.entries[f.key] !== undefined ? d.entries[f.key] : '';
                var raw = stripCommas(String(v));
                if (raw !== '' && !isNaN(parseFloat(raw))) vals.push(parseFloat(raw));
            });
            if (!vals.length) { result[f.key] = ''; return; }
            var total;
            if (agg === 'sum') total = vals.reduce(function (a, b) { return a + b; }, 0);
            else if (agg === 'avg') total = Math.round((vals.reduce(function (a, b) { return a + b; }, 0) / vals.length) * 100) / 100;
            else total = vals[vals.length - 1];
            result[f.key] = formatNum(total);
        });
        return result;
    }

    function aggLabel(key, aggMap) {
        aggMap = aggMap || getAggregation();
        var a = aggMap[key] || 'sum';
        if (a === 'sum') return 'Sum';
        if (a === 'avg') return 'Avg';
        return 'Latest';
    }

    async function fetchWeeklyDailySummary(wk, userIdOverride) {
        var dc = getDailyConfig();
        var kc = getKpiConfig();
        var uid = userIdOverride || (kc.userIds && kc.userIds[0]) || dc.userId || config.userId;
        return requestJson('/api/daily-reports?user_id=' + encodeURIComponent(uid) + '&week_key=' + encodeURIComponent(wk));
    }

    async function saveDailyEntry(reportDate, fieldKey, value, userIdOverride) {
        var dc = getDailyConfig();
        var kc = getKpiConfig();
        var uid = userIdOverride || dc.userId || (kc.userIds && kc.userIds[0]) || config.userId;
        await requestJson('/api/daily-reports', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'save_entry', userId: uid, reportDate: reportDate, fieldKey: fieldKey, value: value })
        });
    }

    var activeUploadPanel = null;

    function formatFileSize(bytes) {
        if (bytes < 1048576) return Math.round(bytes / 1024) + ' KB';
        return (bytes / 1048576).toFixed(1) + ' MB';
    }

    function fileThumbHtml(u) {
        var imageExts = ['png', 'jpg', 'jpeg', 'svg', 'webp', 'gif'];
        var videoExts = ['mp4', 'mov', 'avi', 'mkv', 'webm'];
        var ext = (u.file_type || '').toLowerCase();
        if (imageExts.indexOf(ext) !== -1) {
            return '<img src="' + escapeHtml(u.file_path) + '" alt="" class="dr-ucard-img">';
        }
        if (videoExts.indexOf(ext) !== -1) {
            return '<div class="dr-ucard-icon dr-ucard-icon-video"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="5 3 19 12 5 21 5 3"/></svg><span>' + ext.toUpperCase() + '</span></div>';
        }
        return '<div class="dr-ucard-icon"><span>' + ext.toUpperCase() + '</span></div>';
    }

    // ----- Video handoff pipeline -----
    // A compact "N videos" metric row inside the daily-report table — each day
    // cell opens a slide-over panel (same pattern as the AI Videos Generated
    // upload cell). Shown for Anaz (#18, reworker) and Krishnan (#20, lead).
    var DR_VH_GROUP = 'Video Handoffs';
    var VH_PLAYABLE_EXTS = ['mp4', 'webm', 'mov', 'm4v', 'ogv', 'ogg'];
    var vhWeekData = null;          // last fetched /api/video-handoffs payload
    var activeVhPanelKey = null;    // "creatorId:dateKey" of the open panel, or null
    // Logged-in user id as string. Set inside renderDailyReports. Used to
    // branch render paths: '18' = Anaz (editor), '20' = Krishnan (rich
    // read-only with yellow/blue status), other (JP/Ayush/creator) = read-only
    // updated-only view.
    var vhViewer = '';
    // 'editor' (Anaz), 'viewer' (Krishnan/admin/creator) or null. Driven by
    // config.dailyReports.videoHandoffsView so the backend stays the single
    // source of truth for who can see this section.
    var vhView = null;

    // The big card thumbnail — a play icon + format label (mirrors the
    // AI Videos Generated upload card). Clickable: opens the preview modal.
    function vhPlayThumb(item) {
        var ext = (item.fileType || 'video').toUpperCase();
        return '<button type="button" class="vh-card-thumb vh-play" ' +
            'data-src="' + escapeHtml(item.filePath) + '" ' +
            'data-type="' + escapeHtml(item.fileType || '') + '" ' +
            'data-name="' + escapeHtml(item.fileName) + '" title="Preview video">' +
            '<span class="vh-thumb-inner">' +
                '<svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="5 3 19 12 5 21 5 3"/></svg>' +
                '<span class="vh-thumb-ext">' + escapeHtml(ext) + '</span>' +
            '</span>' +
        '</button>';
    }

    // Compact play button for a reworked-version row inside a card.
    function vhMiniPlay(item) {
        return '<button type="button" class="vh-mini-btn vh-play" ' +
            'data-src="' + escapeHtml(item.filePath) + '" ' +
            'data-type="' + escapeHtml(item.fileType || '') + '" ' +
            'data-name="' + escapeHtml(item.fileName) + '" title="Preview">' +
            '<svg width="11" height="11" viewBox="0 0 24 24" fill="currentColor" stroke="none"><polygon points="5 3 19 12 5 21 5 3"/></svg>' +
        '</button>';
    }

    function vhDownloadIcon() {
        return '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>';
    }

    // One creator's day-level video status — counts of raws and how many
    // of them have at least one reworked version. Used by both render paths
    // (Anaz cells show rawCount; Krishnan cells flip yellow→blue when every
    // raw has been reworked).
    function vhCreatorDateStatus(row, dateKey) {
        var rawCount = 0;
        var fullyUpdatedRawCount = 0;
        var awaitingCount = 0, changesCount = 0, approvedCount = 0;
        ((row.days && row.days[dateKey]) || []).forEach(function (v) {
            rawCount++;
            if (v.complete) fullyUpdatedRawCount++;
            if (v.reviewState === 'awaiting_review') awaitingCount++;
            else if (v.reviewState === 'changes_requested') changesCount++;
            else if (v.reviewState === 'approved') approvedCount++;
        });
        return {
            rawCount: rawCount, fullyUpdatedRawCount: fullyUpdatedRawCount,
            awaitingCount: awaitingCount, changesCount: changesCount, approvedCount: approvedCount,
        };
    }

    function vhFormatTimestamp(iso) {
        if (!iso) return '';
        try {
            return new Date(iso).toLocaleString('en-IN', {
                timeZone: 'Asia/Kolkata', day: 'numeric', month: 'short',
                hour: 'numeric', minute: '2-digit', hour12: true,
            });
        } catch (e) { return ''; }
    }

    // The creator approval loop for one raw deliverable. Rendered only once
    // Anaz has uploaded a rework (reviewState !== 'none'). The OWNING creator
    // gets interactive Yes/No (No reveals a change-request textarea); everyone
    // else — Anaz included — sees the state + full feedback thread read-only,
    // so Anaz can read exactly what to fix.
    function buildVhReviewBlock(v, isOwner, canEdit) {
        if (!v || !v.reviewState || v.reviewState === 'none') return '';
        var state = v.reviewState;
        var history = v.reviewHistory || [];
        var html = '<div class="vh-review">';

        if (state === 'awaiting_review') {
            if (isOwner) {
                html += '<div class="vh-review-prompt">Happy with this version?</div>' +
                    '<div class="vh-review-btns">' +
                        '<button type="button" class="vh-review-yes" data-raw-id="' + v.rawId + '">Yes</button>' +
                        '<button type="button" class="vh-review-no" data-raw-id="' + v.rawId + '">No</button>' +
                    '</div>' +
                    '<div class="vh-review-form" data-raw-id="' + v.rawId + '" style="display:none">' +
                        '<textarea class="vh-review-text" rows="3" placeholder="What needs to change? Be specific so Anas can fix it."></textarea>' +
                        '<button type="button" class="vh-review-submit" data-raw-id="' + v.rawId + '">Send to Anas</button>' +
                    '</div>';
            } else {
                html += '<div class="vh-review-state vh-review-waiting">Waiting for the creator to review</div>';
            }
        } else if (state === 'changes_requested') {
            var changesMsg = isOwner
                ? 'You requested changes — waiting for Anas to re-upload'
                : (canEdit ? 'Changes requested — please re-upload a corrected version'
                           : 'Changes requested — waiting for Anas to re-upload');
            html += '<div class="vh-review-state vh-review-changes">' + changesMsg + '</div>';
        } else if (state === 'approved') {
            html += '<div class="vh-review-state vh-review-approved">' +
                (isOwner ? '✓ You approved this video' : '✓ Approved by the creator') + '</div>';
        }

        // Full back-and-forth thread (oldest first), visible to all viewers.
        if (history.length) {
            html += '<div class="vh-review-thread">';
            history.forEach(function (h) {
                var chip = h.verdict === 'approved'
                    ? '<span class="vh-rt-chip vh-rt-approved">Approved</span>'
                    : '<span class="vh-rt-chip vh-rt-changes">Changes</span>';
                var fb = h.feedback ? '<span class="vh-rt-fb">' + escapeHtml(h.feedback) + '</span>' : '';
                html += '<div class="vh-review-thread-item">' + chip + fb +
                    '<span class="vh-rt-at">' + escapeHtml(vhFormatTimestamp(h.at)) + '</span></div>';
            });
            html += '</div>';
        }

        return html + '</div>';
    }

    // The three deliverable crops, in display order.
    var VH_RATIOS = ['1:1', '9:16', '16:9'];

    // One reworked-video row (mini play + name + download + Anaz-only delete),
    // with a read-only "Updated by … · <time>" caption for viewers. Reused by
    // each ratio box and by the legacy/unsorted section.
    function buildVhUpdatedItem(uv, canEdit) {
        // Label + download filename use the standardized name once the raw is
        // approved; fall back to Anaz's original upload name.
        var uvName = uv.downloadName || uv.fileName;
        var dlAttr = uv.downloadName ? ' download="' + escapeHtml(uv.downloadName) + '"' : ' download';
        var html = '<div class="vh-updated-item">' +
            vhMiniPlay(uv) +
            '<span class="vh-updated-name" title="' + escapeHtml(uvName) + '">' + escapeHtml(uvName) + '</span>' +
            '<a href="' + escapeHtml(uv.filePath) + '"' + dlAttr + ' class="vh-mini-btn" title="Download">' + vhDownloadIcon() + '</a>' +
            (canEdit
                ? '<button type="button" class="vh-del-btn" data-handoff-id="' + uv.handoffId + '" title="Delete updated video">&times;</button>'
                : '') +
        '</div>';
        if (!canEdit && uv.updatedAt) {
            var by = uv.updatedBy ? ('Updated by ' + uv.updatedBy) : 'Updated';
            html += '<div class="vh-updated-meta">' + escapeHtml(by) + ' &middot; ' +
                escapeHtml(vhFormatTimestamp(uv.updatedAt)) + '</div>';
        }
        return html;
    }

    // One ratio slot (1:1 / 9:16 / 16:9). Red until a video of that exact ratio
    // is present, then green. Anaz gets a single-file upload that REPLACES the
    // current crop; the box only accepts its own ratio (client pre-check +
    // server ffprobe verification).
    function buildVhRatioBox(v, ratioKey, canEdit) {
        var vids = (v.updatedVideos || []).filter(function (uv) { return uv.ratio === ratioKey; });
        var filled = vids.length > 0;
        var html = '<div class="vh-ratio-box ' + (filled ? 'vh-ratio-filled' : 'vh-ratio-empty') + '">' +
            '<div class="vh-ratio-head">' +
                '<span class="vh-ratio-dot"></span>' +
                '<span class="vh-ratio-label">' + escapeHtml(ratioKey) + '</span>' +
            '</div>';
        if (filled) {
            html += '<div class="vh-updated-list">';
            vids.forEach(function (uv) { html += buildVhUpdatedItem(uv, canEdit); });
            html += '</div>';
        } else if (!canEdit) {
            html += '<div class="vh-ratio-missing">Not uploaded yet</div>';
        }
        if (canEdit) {
            html += '<label class="vh-add-btn vh-ratio-add">' +
                (filled ? 'Replace ' + escapeHtml(ratioKey) : '+ ' + escapeHtml(ratioKey) + ' video') +
                '<input type="file" class="vh-add-input" data-raw-id="' + v.rawId + '" data-ratio="' + escapeHtml(ratioKey) + '" accept="video/*" style="display:none">' +
            '</label>';
        }
        return html + '</div>';
    }

    // Videos uploaded before the per-ratio boxes shipped (ratio = null). Shown
    // in their own "Unsorted" section so old deliverables stay reachable; there
    // is no upload control (you can't add to legacy).
    function buildVhLegacySection(v, canEdit) {
        if (!v.hasLegacyVideos) return '';
        var vids = (v.updatedVideos || []).filter(function (uv) { return !uv.ratio; });
        if (!vids.length) return '';
        var html = '<div class="vh-legacy-section">' +
            '<div class="vh-legacy-head">Unsorted (pre-ratio)</div>' +
            '<div class="vh-updated-list">';
        vids.forEach(function (uv) { html += buildVhUpdatedItem(uv, canEdit); });
        return html + '</div></div>';
    }

    // One raw video as a card tile (red = pending, green = all 3 ratios done) —
    // preview, status, download, the three ratio boxes, any legacy videos, and
    // the creator approval loop. When `canEdit` is false (Krishnan/creator
    // read-only view) the per-box upload + delete-X are omitted and update
    // timestamps show as captions. `creatorId` identifies whose row this is, so
    // the owning creator gets the interactive Yes/No once the deliverable is complete.
    function buildVhRawBox(v, canEdit, creatorId) {
        if (typeof canEdit === 'undefined') canEdit = true;
        var isUpdated = v.status === 'updated';
        var uploadedCaption = !canEdit && v.uploadedAt
            ? '<div class="vh-card-meta">Uploaded ' + escapeHtml(vhFormatTimestamp(v.uploadedAt)) + '</div>'
            : '';
        // Standardized name, assigned once Anaz reworks the raw (null before).
        var assignedBadge = v.assignedName
            ? '<div class="vh-card-assigned" title="Standardized name">' + escapeHtml(v.assignedName) + '</div>'
            : '';
        // Creator's description entered at upload time.
        var descCaption = v.description
            ? '<div class="vh-card-desc" title="Creator description">' + escapeHtml(v.description) + '</div>'
            : '';
        var html = '<div class="vh-card ' + (isUpdated ? 'vh-card-updated' : 'vh-card-pending') + '">' +
            vhPlayThumb(v) +
            '<div class="vh-card-body">' +
                '<div class="vh-card-name" title="' + escapeHtml(v.fileName) + '">' + escapeHtml(v.fileName) + '</div>' +
                assignedBadge +
                descCaption +
                uploadedCaption +
                '<div class="vh-card-row">' +
                    '<a href="' + escapeHtml(v.filePath) + '" download class="vh-link" title="Download raw video">Download raw</a>' +
                '</div>';
        // The three ratio boxes (1:1 / 9:16 / 16:9).
        html += '<div class="vh-ratio-grid">';
        VH_RATIOS.forEach(function (rk) { html += buildVhRatioBox(v, rk, canEdit); });
        html += '</div>';
        // Any pre-ratio (legacy) videos.
        html += buildVhLegacySection(v, canEdit);
        // Creator approval loop — reviewState is gated server-side to 'none'
        // until all 3 ratios exist, so the Yes/No only appears once complete.
        html += buildVhReviewBlock(v, String(creatorId) === vhViewer, canEdit);
        return html + '</div></div>';
    }

    // The video tiles for ONE creator on ONE date — the slide-over body.
    // Anaz (canEdit) and Krishnan (read-only) both see raw cards with nested
    // reworked versions; Krishnan's variant drops the upload + delete controls
    // and adds upload/update timestamps via buildVhRawBox(v, false).
    //
    // The "Download all (.zip)" toolbar link bundles raws + reworked versions
    // for everyone who can view (Anaz uses it to grab the batch for offline
    // rework; the creator uses it to grab Anas's reworked copies in one shot).
    function buildVhPanelBody(vhData, creatorId, dateKey) {
        var rows = (vhData && vhData.rows) || [];
        var canEdit = !!(vhData && vhData.canEdit);
        var row = rows.find(function (r) { return String(r.creatorId) === String(creatorId); });
        var dayVideos = (row && row.days && row.days[dateKey]) || [];
        var inner = '';
        dayVideos.forEach(function (v) { inner += buildVhRawBox(v, canEdit, creatorId); });
        if (!inner) {
            return '<div class="vh-empty">' +
                (canEdit ? 'No videos for this day.' : 'No videos from this creator for this day.') + '</div>';
        }
        var zipUrl = '/api/video-handoffs/zip?creator_id=' + encodeURIComponent(creatorId) + '&date=' + encodeURIComponent(dateKey);
        var toolbar = '<div class="vh-panel-toolbar">' +
            '<a href="' + zipUrl + '" class="vh-toolbar-link" title="Download raw + reworked videos as ZIP">' +
                vhDownloadIcon() + ' <span>Download all (.zip)</span>' +
            '</a>' +
        '</div>';
        return toolbar + '<div class="vh-creator-videos">' + inner + '</div>';
    }

    // The "Video Handoffs" table block: a group header + one row PER content
    // creator, each day cell a clickable status button that opens the panel.
    // Anaz's cells show "N videos" (count of raws). Krishnan's cells flip
    // yellow → blue once every raw for the day has at least one reworked
    // version (partial reworks stay yellow per the agreed status rule).
    function buildVideoHandoffGroup(vhData, dateCols) {
        var rows = (vhData && vhData.rows) || [];
        var canEdit = !!(vhData && vhData.canEdit);
        var colspan = dateCols.length + 2;
        var bodyRows = '';
        rows.forEach(function (row) {
            var cellsHtml = '';
            var weekTotal = 0;
            dateCols.forEach(function (col) {
                var status = vhCreatorDateStatus(row, col.dateKey);
                var rawCount = status.rawCount;
                weekTotal += rawCount;
                var cls = col.isToday ? ' dr-today' : '';
                if (rawCount === 0) {
                    cellsHtml += '<td class="dr-cell dr-cell-locked' + cls + '">—</td>';
                    return;
                }
                var active = (activeVhPanelKey === row.creatorId + ':' + col.dateKey) ? ' dr-up-cell-active' : '';
                var stateClass = '';
                var label;
                if (canEdit) {
                    // Anaz — unchanged
                    label = rawCount + ' video' + (rawCount === 1 ? '' : 's');
                } else {
                    // Krishnan/creator — yellow until ALL raws have at least
                    // one rework ("Disha sent 2 videos" / "Updated by Anas").
                    // On the creator's OWN row, surface the approval loop so
                    // they notice without opening every panel: a rework awaiting
                    // their Yes/No flags the cell for review; once every raw is
                    // approved it turns green.
                    var isOwnRow = String(row.creatorId) === vhViewer;
                    if (isOwnRow && status.awaitingCount > 0) {
                        stateClass = ' vh-cell-review';
                        label = 'Review ' + status.awaitingCount + ' video' + (status.awaitingCount === 1 ? '' : 's');
                    } else if (isOwnRow && status.changesCount > 0) {
                        stateClass = ' vh-cell-pending';
                        label = 'Changes sent';
                    } else if (isOwnRow && rawCount > 0 && status.approvedCount === rawCount) {
                        stateClass = ' vh-cell-approved';
                        label = 'Approved';
                    } else {
                        var allDone = status.fullyUpdatedRawCount === rawCount;
                        stateClass = allDone ? ' vh-cell-updated' : ' vh-cell-pending';
                        var nameForLabel = isOwnRow ? 'You' : row.creatorName;
                        label = allDone
                            ? 'Updated by Anas'
                            : (nameForLabel + ' sent ' + rawCount + ' video' + (rawCount === 1 ? '' : 's'));
                    }
                }
                cellsHtml += '<td class="dr-cell dr-cell-upload' + cls + '">' +
                    '<button type="button" class="dr-up-cell-btn vh-cell-btn' + stateClass + active + '" ' +
                    'data-vh-creator="' + escapeHtml(String(row.creatorId)) + '" ' +
                    'data-vh-date="' + escapeHtml(col.dateKey) + '">' +
                    escapeHtml(label) + ' &#9662;</button></td>';
            });
            bodyRows += '<tr class="dr-data-row" data-group="' + escapeHtml(DR_VH_GROUP) + '">' +
                '<td class="dr-metric-cell">' + escapeHtml(row.creatorName) +
                    (row.isActive ? '' : ' <span class="vh-inactive">(inactive)</span>') + '</td>' +
                cellsHtml +
                '<td class="dr-cell dr-summary-cell"><span class="dr-summary-val">' +
                    (weekTotal || '—') + '</span></td></tr>';
        });
        var html = '<tr class="dr-group-row" data-group="' + escapeHtml(DR_VH_GROUP) + '">' +
            '<td colspan="' + colspan + '">' + escapeHtml(DR_VH_GROUP) + '</td></tr>';
        if (!bodyRows) {
            html += '<tr class="dr-data-row" data-group="' + escapeHtml(DR_VH_GROUP) + '">' +
                '<td colspan="' + colspan + '" class="vh-empty">' +
                (canEdit ? 'No videos from the content team this week.'
                         : 'No videos from the content team this week.') + '</td></tr>';
        }
        return html + bodyRows;
    }

    // In-browser video preview. mp4/webm/mov play inline; other containers
    // (avi/mkv/wmv) can't, so the modal degrades to a download prompt.
    function openVideoModal(src, fileType, name) {
        var existing = document.getElementById('vhVideoModal');
        if (existing) existing.remove();
        var ext = (fileType || '').toLowerCase();
        var bodyHtml = VH_PLAYABLE_EXTS.indexOf(ext) !== -1
            ? '<video class="vh-video-player" src="' + escapeHtml(src) + '" controls autoplay preload="metadata"></video>'
            : '<div class="vh-video-noplay"><p>In-browser preview is not supported for <strong>.' + escapeHtml(ext) +
              '</strong> files.</p><a href="' + escapeHtml(src) + '" download class="btn btn-primary">Download to view</a></div>';
        var overlay = document.createElement('div');
        overlay.id = 'vhVideoModal';
        overlay.className = 'vh-video-modal-overlay';
        overlay.innerHTML = '<div class="vh-video-modal">' +
            '<div class="vh-video-modal-head">' +
                '<span class="vh-video-modal-title" title="' + escapeHtml(name) + '">' + escapeHtml(name) + '</span>' +
                '<button type="button" class="vh-video-modal-close" id="vhVideoModalClose">&times;</button>' +
            '</div>' +
            '<div class="vh-video-modal-body">' + bodyHtml + '</div>' +
            '<div class="vh-video-modal-foot"><a href="' + escapeHtml(src) + '" download class="vh-download-link">Download</a></div>' +
        '</div>';
        document.body.appendChild(overlay);
        function closeModal() { overlay.remove(); }
        overlay.addEventListener('click', function (e) { if (e.target === overlay) closeModal(); });
        document.getElementById('vhVideoModalClose').addEventListener('click', closeModal);
    }

    function vhDateLabel(dateKey) {
        var d = new Date(dateKey + 'T00:00:00');
        return d.toLocaleDateString('en-IN', { timeZone: 'Asia/Kolkata', weekday: 'short', day: 'numeric', month: 'short' });
    }

    // Keeps the .dr-up-cell-active highlight on the count buttons in sync with
    // whichever creator+date panel is open.
    function vhSyncCellActive() {
        document.querySelectorAll('.vh-cell-btn').forEach(function (btn) {
            var key = btn.getAttribute('data-vh-creator') + ':' + btn.getAttribute('data-vh-date');
            btn.classList.toggle('dr-up-cell-active', key === activeVhPanelKey);
        });
    }

    // Opens (or toggles closed) the slide-over panel for one creator + date —
    // same placement and box styling as the AI Videos Generated upload panel.
    function renderVideoHandoffPanel(creatorId, dateKey) {
        var key = creatorId + ':' + dateKey;
        var existing = document.getElementById('drVideoHandoffPanel');
        if (existing && activeVhPanelKey === key) {
            existing.remove();
            activeVhPanelKey = null;
            vhSyncCellActive();
            return;
        }
        if (existing) existing.remove();
        var uploadPanel = document.getElementById('drUploadPanel');
        if (uploadPanel) uploadPanel.remove();
        activeVhPanelKey = key;

        var row = ((vhWeekData && vhWeekData.rows) || []).find(function (r) {
            return String(r.creatorId) === String(creatorId);
        });
        var creatorName = row ? row.creatorName : 'Creator';

        var panel = document.createElement('div');
        panel.id = 'drVideoHandoffPanel';
        panel.className = 'dr-upload-panel';
        panel.innerHTML =
            '<div class="dr-up-header">' +
                '<div class="dr-up-title">' + escapeHtml(creatorName) + ' &mdash; ' + escapeHtml(vhDateLabel(dateKey)) + '</div>' +
                '<button type="button" class="dr-up-close" id="vhPanelClose">&times;</button>' +
            '</div>' +
            '<div class="vh-panel-body" id="vhPanelBody">' + buildVhPanelBody(vhWeekData, creatorId, dateKey) + '</div>';

        var tableWrap = document.querySelector('.dr-table-wrap');
        if (tableWrap) {
            tableWrap.parentNode.insertBefore(panel, tableWrap.nextSibling);
        } else {
            var dc = document.getElementById('dailyReportContent');
            if (dc) dc.appendChild(panel);
        }
        document.getElementById('vhPanelClose').onclick = function () {
            panel.remove();
            activeVhPanelKey = null;
            vhSyncCellActive();
        };
        vhSyncCellActive();
        wireVideoHandoffPanelHandlers(panel, creatorId, dateKey);
    }

    // Re-fetch the week and re-render the open panel in place (after an upload
    // or delete) so it stays open and the card colour flips live.
    function refreshVideoHandoffPanel(creatorId, dateKey) {
        var wk = vhWeekData && vhWeekData.weekKey;
        if (!wk) { renderDailyReports(); return; }
        requestJson('/api/video-handoffs?week_key=' + encodeURIComponent(wk)).then(function (data) {
            vhWeekData = data;
            var body = document.getElementById('vhPanelBody');
            var panel = document.getElementById('drVideoHandoffPanel');
            if (body && panel && activeVhPanelKey === creatorId + ':' + dateKey) {
                body.innerHTML = buildVhPanelBody(vhWeekData, creatorId, dateKey);
                wireVideoHandoffPanelHandlers(panel, creatorId, dateKey);
            }
        }).catch(function () { renderDailyReports(); });
    }

    // Classify width/height into '1:1'|'9:16'|'16:9' or null — mirrors the
    // server's App\Services\VideoAspectRatio so the client can reject an
    // obviously-wrong file before uploading.
    function vhClassifyWh(w, h) {
        if (!w || !h) return null;
        var r = w / h;
        var targets = { '1:1': 1.0, '9:16': 0.5625, '16:9': 1.77778 };
        var best = null, bestErr = Infinity;
        Object.keys(targets).forEach(function (k) {
            var err = Math.abs(r - targets[k]) / targets[k];
            if (err < bestErr) { bestErr = err; best = k; }
        });
        return bestErr <= 0.04 ? best : null;
    }

    // Reads a file's aspect ratio in-browser via an offscreen <video>. Resolves
    // '1:1'|'9:16'|'16:9' or null for playable containers, or 'unknown' for ones
    // the browser can't decode (avi/mkv/wmv) — those defer to the server's
    // ffprobe. videoWidth/Height are DISPLAY dims, so rotation is already applied.
    function vhDetectRatioClient(file) {
        return new Promise(function (resolve) {
            var ext = (file.name.split('.').pop() || '').toLowerCase();
            if (VH_PLAYABLE_EXTS.indexOf(ext) === -1) { resolve('unknown'); return; }
            var url = URL.createObjectURL(file);
            var vid = document.createElement('video');
            var done = false;
            function finish(val) {
                if (done) return;
                done = true;
                try { URL.revokeObjectURL(url); } catch (e) {}
                resolve(val);
            }
            vid.preload = 'metadata';
            vid.onloadedmetadata = function () {
                // Zero dims = browser couldn't measure; defer to the server
                // rather than risk a false reject of a valid file.
                if (!vid.videoWidth || !vid.videoHeight) { finish('unknown'); return; }
                finish(vhClassifyWh(vid.videoWidth, vid.videoHeight));
            };
            vid.onerror = function () { finish('unknown'); };
            setTimeout(function () { finish('unknown'); }, 8000);
            vid.src = url;
        });
    }

    // Anaz uploads a reworked video into one ratio box. One request per file;
    // the box's ratio rides along and is enforced both client-side (instant) and
    // server-side (ffprobe). A box holds one crop, so a new upload replaces it.
    function uploadVideoHandoffFiles(rawId, files, creatorId, dateKey, intendedRatio) {
        var list = Array.from(files).filter(function (f) {
            return (f.type || '').indexOf('video/') === 0;
        });
        if (!list.length) {
            alert('No video files found in selection.');
            return;
        }
        // Server cap is 1000 MB per file (VideoHandoffController::MAX_MB).
        // Pre-check client-side so the user gets an immediate, specific error
        // instead of a nginx 413 surfacing as the misleading "network error".
        var VH_MAX_MB = 1000;
        var errors = [];
        var body = document.getElementById('vhPanelBody');
        if (body) {
            var note = document.createElement('div');
            note.className = 'vh-upload-note';
            note.textContent = 'Uploading ' + intendedRatio + ' video…';
            body.insertBefore(note, body.firstChild);
        }
        runWithConcurrency(list, UPLOAD_CONCURRENCY, function (file) {
            return new Promise(function (resolve) {
                if (file.size > VH_MAX_MB * 1024 * 1024) {
                    var sizeMb = (file.size / 1024 / 1024).toFixed(0);
                    errors.push(file.name + ': too large (' + sizeMb + ' MB, max ' + VH_MAX_MB + ' MB). Please compress before uploading.');
                    resolve();
                    return;
                }
                // Instant ratio gate for playable containers; 'unknown' (non-
                // playable container) defers to the server's ffprobe check.
                vhDetectRatioClient(file).then(function (clientRatio) {
                    if (clientRatio !== 'unknown' && clientRatio !== intendedRatio) {
                        errors.push(file.name + ': looks like ' + (clientRatio || 'a non-standard ratio') +
                            ', but the ' + intendedRatio + ' box only accepts ' + intendedRatio + ' videos.');
                        resolve();
                        return;
                    }
                    var formData = new FormData();
                    formData.append('action', 'upload');
                    formData.append('raw_upload_id', rawId);
                    formData.append('ratio', intendedRatio);
                    formData.append('file', file);
                    fetch('/api/video-handoffs', {
                        method: 'POST', credentials: 'same-origin',
                        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        body: formData
                    }).then(function (r) {
                        // nginx returns HTML for 413, so r.json() would SyntaxError and
                        // land in .catch() as the misleading "network error". Branch on
                        // status first to give a usable hint.
                        if (r.status === 413) {
                            return { ok: false, error: 'File too large for server (max ' + VH_MAX_MB + ' MB). Please compress before uploading.' };
                        }
                        if (r.status === 504 || r.status === 502) {
                            return { ok: false, error: 'Upload timed out at the server. Try again on a faster connection or compress the file.' };
                        }
                        return r.json().catch(function () {
                            return { ok: false, error: 'Server returned an unexpected response (HTTP ' + r.status + ').' };
                        });
                    }).then(function (b) {
                        if (!b.ok) errors.push(file.name + ': ' + (b.error || 'failed'));
                    }).catch(function () {
                        // Genuine network drop — fetch didn't even get a status.
                        errors.push(file.name + ': network dropped. Check your connection and retry.');
                    }).finally(function () {
                        resolve();
                    });
                });
            });
        }).then(function () {
            if (errors.length) alert('Some videos failed:\n' + errors.join('\n'));
            refreshVideoHandoffPanel(creatorId, dateKey);
        });
    }

    // Wires preview / upload / delete inside an open slide-over panel.
    function wireVideoHandoffPanelHandlers(panel, creatorId, dateKey) {
        if (!panel) return;
        panel.querySelectorAll('.vh-play').forEach(function (btn) {
            btn.addEventListener('click', function () {
                openVideoModal(btn.getAttribute('data-src'), btn.getAttribute('data-type'), btn.getAttribute('data-name'));
            });
        });
        panel.querySelectorAll('.vh-add-input').forEach(function (input) {
            input.addEventListener('change', function () {
                uploadVideoHandoffFiles(input.getAttribute('data-raw-id'), input.files, creatorId, dateKey, input.getAttribute('data-ratio'));
                input.value = '';
            });
        });
        panel.querySelectorAll('.vh-del-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                if (!confirm('Delete this updated video?')) return;
                btn.disabled = true;
                fetch('/api/video-handoffs', {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify({ action: 'delete', id: parseInt(btn.getAttribute('data-handoff-id'), 10) })
                }).then(function (r) { return r.json(); }).then(function (body) {
                    if (body.ok) { refreshVideoHandoffPanel(creatorId, dateKey); }
                    else { alert(body.error || 'Delete failed'); btn.disabled = false; }
                }).catch(function () { btn.disabled = false; });
            });
        });

        // Creator approval loop — Yes approves (terminal), No reveals the
        // change-request textarea, submit DMs Anas with the feedback.
        panel.querySelectorAll('.vh-review-yes').forEach(function (btn) {
            btn.addEventListener('click', function () {
                if (!confirm('Approve this video? This closes the feedback and lets Anas know you\'re happy.')) return;
                submitVhReview(btn.getAttribute('data-raw-id'), 'approved', '', creatorId, dateKey, btn);
            });
        });
        panel.querySelectorAll('.vh-review-no').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var form = panel.querySelector('.vh-review-form[data-raw-id="' + btn.getAttribute('data-raw-id') + '"]');
                if (form) {
                    form.style.display = 'flex';
                    var ta = form.querySelector('.vh-review-text');
                    if (ta) ta.focus();
                }
            });
        });
        panel.querySelectorAll('.vh-review-submit').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var rawId = btn.getAttribute('data-raw-id');
                var form = panel.querySelector('.vh-review-form[data-raw-id="' + rawId + '"]');
                var ta = form ? form.querySelector('.vh-review-text') : null;
                var text = ta ? ta.value.trim() : '';
                if (!text) { if (ta) ta.focus(); alert('Please describe what needs to change.'); return; }
                submitVhReview(rawId, 'changes_requested', text, creatorId, dateKey, btn);
            });
        });
    }

    // Posts a creator verdict (approve / request changes) and re-renders the
    // open panel so the new state + thread show immediately.
    function submitVhReview(rawId, verdict, feedback, creatorId, dateKey, btn) {
        if (btn) btn.disabled = true;
        fetch('/api/video-handoffs', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ action: 'review', raw_upload_id: parseInt(rawId, 10), verdict: verdict, feedback: feedback })
        }).then(function (r) {
            return r.json().catch(function () { return { ok: false, error: 'Server returned an unexpected response (HTTP ' + r.status + ').' }; });
        }).then(function (body) {
            if (body.ok) { refreshVideoHandoffPanel(creatorId, dateKey); }
            else { alert(body.error || 'Could not save your feedback.'); if (btn) btn.disabled = false; }
        }).catch(function () {
            alert('Network error — please try again.'); if (btn) btn.disabled = false;
        });
    }

    // Wires the "N videos" count buttons in the table to open the panel.
    function wireVideoHandoffCells(container) {
        if (!container) return;
        container.querySelectorAll('.vh-cell-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                renderVideoHandoffPanel(btn.getAttribute('data-vh-creator'), btn.getAttribute('data-vh-date'));
            });
        });
    }

    // Renders a radio group inside an upload/textarea panel so the report owner
    // can pick a status answer alongside the upload (e.g. "Sent to Anas" for
    // Krishnan's video team, "Received and done" for Anas). Saves through the
    // daily-reports endpoint with action=save_choice. No-op when the field has
    // no choices; read-only when the viewer is not the report owner.
    function mountDrChoiceSection(panel, opts) {
        if (!panel || !opts || !Array.isArray(opts.choices) || opts.choices.length === 0) return;
        var section = document.createElement('div');
        section.className = 'dr-up-choice';
        var groupName = 'drUpChoice_' + opts.fieldKey + '_' + opts.reportDate;
        var disabledAttr = opts.isOwner ? '' : ' disabled';
        var optsHtml = opts.choices.map(function (c) {
            var checked = c.value === opts.choiceValue ? ' checked' : '';
            return '<label class="dr-up-choice-opt">' +
                '<input type="radio" name="' + escapeHtml(groupName) + '" value="' + escapeHtml(c.value) + '"' + checked + disabledAttr + '>' +
                '<span>' + escapeHtml(c.label) + '</span>' +
            '</label>';
        }).join('');
        section.innerHTML =
            '<div class="dr-up-choice-label">Status</div>' +
            '<div class="dr-up-choice-opts">' + optsHtml + '</div>' +
            '<div class="dr-up-choice-status" aria-live="polite"></div>';
        panel.appendChild(section);

        if (!opts.isOwner) return;

        var statusEl = section.querySelector('.dr-up-choice-status');
        section.querySelectorAll('input[type=radio]').forEach(function (radio) {
            radio.addEventListener('change', function () {
                if (!radio.checked) return;
                statusEl.textContent = 'Saving…';
                statusEl.className = 'dr-up-choice-status';
                requestJson('/api/daily-reports', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'save_choice',
                        userId: opts.userId,
                        reportDate: opts.reportDate,
                        fieldKey: opts.fieldKey,
                        choiceValue: radio.value
                    })
                }).then(function () {
                    statusEl.textContent = 'Saved';
                    statusEl.className = 'dr-up-choice-status dr-up-choice-saved';
                    var btn = document.querySelector('.dr-up-cell-btn[data-field="' + opts.fieldKey.replace(/"/g, '\\"') + '"][data-date="' + opts.reportDate + '"]');
                    if (btn) btn.setAttribute('data-choice-value', radio.value);
                    // Re-render the whole report so the cell's chip reflects the
                    // new selection. Mirrors the post-upload refresh path.
                    setTimeout(function () { renderDailyReports(); }, 300);
                }).catch(function (e) {
                    statusEl.textContent = (e && e.message) || 'Save failed';
                    statusEl.className = 'dr-up-choice-status dr-up-choice-failed';
                });
            });
        });
    }

    function renderTextareaPanel(userId, fieldKey, fieldLabel, reportDate, useScriptWording, choices, choiceValue) {
        var panelEl = document.getElementById('drUploadPanel');
        if (panelEl && activeUploadPanel === fieldKey + ':' + reportDate) {
            panelEl.remove();
            activeUploadPanel = null;
            return;
        }
        if (panelEl) panelEl.remove();
        var vhPanelEl = document.getElementById('drVideoHandoffPanel');
        if (vhPanelEl) { vhPanelEl.remove(); activeVhPanelKey = null; }
        activeUploadPanel = fieldKey + ':' + reportDate;

        var dateObj = new Date(reportDate + 'T00:00:00');
        var dateLabel = dateObj.toLocaleDateString('en-IN', { timeZone: 'Asia/Kolkata', weekday: 'short', day: 'numeric', month: 'short' });

        var saveLabel = useScriptWording ? 'Save Script' : 'Save';
        var updateLabel = useScriptWording ? 'Update Script' : 'Update';
        var placeholderText = useScriptWording ? 'Paste or type your script here...' : 'Type your entry here...';
        var loadingText = useScriptWording ? 'Loading scripts...' : 'Loading entries...';
        var emptyText = useScriptWording ? 'No scripts yet. Paste your script above and save.' : 'No entries yet. Type above and save.';
        var emptyAlert = useScriptWording ? 'Please enter script content.' : 'Please enter content.';
        var deleteConfirm = useScriptWording ? 'Delete this script?' : 'Delete this entry?';
        var loadFailText = useScriptWording ? 'Failed to load scripts.' : 'Failed to load entries.';

        // Only the report owner may add a new entry. Managers/admins viewing
        // someone else's report see the existing entries (and can edit/delete
        // them via the popup) but get no "add entry" compose box.
        var isOwner = String(userId) === String(config.userId);
        var composeHtml = isOwner
            ? '<div class="dr-ta-compose">' +
                '<textarea class="input dr-ta-input" id="drTaInput" rows="8" placeholder="' + escapeHtml(placeholderText) + '" data-grammar-fix></textarea>' +
                '<div class="dr-ta-actions">' +
                    '<button type="button" class="dr-ta-save-btn" id="drTaSave">' + escapeHtml(saveLabel) + '</button>' +
                '</div>' +
              '</div>'
            : '';

        var panel = document.createElement('div');
        panel.id = 'drUploadPanel';
        panel.className = 'dr-upload-panel';
        panel.innerHTML =
            '<div class="dr-up-header">' +
                '<div class="dr-up-title">' + escapeHtml(fieldLabel) + ' &mdash; ' + escapeHtml(dateLabel) + '</div>' +
                '<button type="button" class="dr-up-close" id="drUpClose">&times;</button>' +
            '</div>' +
            composeHtml +
            '<div class="dr-up-grid" id="drUpGrid"><div class="dr-up-loading">' + escapeHtml(loadingText) + '</div></div>';

        var tableWrap = document.querySelector('.dr-table-wrap');
        if (tableWrap) {
            tableWrap.parentNode.insertBefore(panel, tableWrap.nextSibling);
        } else {
            document.getElementById('dailyReportContent').appendChild(panel);
        }
        setTimeout(function () { panel.scrollIntoView({ behavior: 'smooth', block: 'start' }); }, 100);

        mountDrChoiceSection(panel, {
            userId: userId, fieldKey: fieldKey, reportDate: reportDate,
            choices: choices, choiceValue: choiceValue, isOwner: isOwner
        });

        document.getElementById('drUpClose').onclick = function () {
            panel.remove();
            activeUploadPanel = null;
        };

        var drTaSaveBtn = document.getElementById('drTaSave');
        if (drTaSaveBtn) drTaSaveBtn.onclick = function () {
            var content = document.getElementById('drTaInput').value.trim();
            if (!content) { alert(emptyAlert); return; }
            var btn = document.getElementById('drTaSave');
            var editId = btn.getAttribute('data-edit-id');
            btn.disabled = true;
            btn.textContent = editId ? 'Updating...' : 'Saving...';

            function doSave() {
                fetch('/api/creative-uploads', {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify({ action: 'save_text', user_id: userId, field_key: fieldKey, report_date: reportDate, content: content })
                }).then(function (r) { return r.json(); }).then(function (body) {
                    if (body.ok) {
                        document.getElementById('drTaInput').value = '';
                        btn.disabled = false;
                        btn.textContent = saveLabel;
                        btn.removeAttribute('data-edit-id');
                        loadTaUploads();
                        renderDailyReports();
                    } else {
                        alert(body.error || 'Save failed');
                        btn.disabled = false;
                        btn.textContent = editId ? updateLabel : saveLabel;
                    }
                }).catch(function () {
                    alert('Save failed. Try again.');
                    btn.disabled = false;
                    btn.textContent = editId ? updateLabel : saveLabel;
                });
            }

            // If editing, delete old first then save new
            if (editId) {
                fetch('/api/creative-uploads', {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify({ action: 'delete', id: parseInt(editId, 10) })
                }).then(function () { doSave(); }).catch(function () { doSave(); });
            } else {
                doSave();
            }
        };

        // Popup showing the FULL uploaded script. Editing happens inside the
        // popup itself so it works for managers too (who have no compose box).
        function openScriptModal(u, startInEdit) {
            var existing = document.getElementById('drScriptModal');
            if (existing) existing.remove();

            var subParts = [];
            if (u.uploaded_by_name) subParts.push('by ' + u.uploaded_by_name);
            subParts.push(dateLabel);

            var overlay = document.createElement('div');
            overlay.className = 'modal-overlay';
            overlay.id = 'drScriptModal';
            overlay.innerHTML =
                '<div class="modal-content dr-script-modal">' +
                    '<div class="dr-script-modal-head">' +
                        '<div class="dr-script-modal-titles">' +
                            '<div class="dr-script-modal-title">' + escapeHtml(u.file_name || (useScriptWording ? 'Script' : 'Entry')) + '</div>' +
                            '<div class="dr-script-modal-sub">' + escapeHtml(subParts.join(' · ')) + '</div>' +
                        '</div>' +
                        '<button type="button" class="dr-up-close" id="drScriptModalClose">&times;</button>' +
                    '</div>' +
                    '<div class="dr-script-modal-body" id="drScriptBody"></div>' +
                    '<div class="dr-script-modal-actions" id="drScriptActions"></div>' +
                '</div>';
            document.body.appendChild(overlay);

            var bodyEl = overlay.querySelector('#drScriptBody');
            var actionsEl = overlay.querySelector('#drScriptActions');

            function closeModal() { overlay.remove(); document.removeEventListener('keydown', onEsc); }
            function onEsc(e) { if (e.key === 'Escape') closeModal(); }
            document.addEventListener('keydown', onEsc);
            overlay.addEventListener('click', function (e) { if (e.target === overlay) closeModal(); });
            document.getElementById('drScriptModalClose').onclick = closeModal;

            function renderRead() {
                bodyEl.className = 'dr-script-modal-body';
                bodyEl.innerHTML = escapeHtml(u.content || '');
                // Content-creation team entries are owner-only: a manager/admin
                // viewing someone else's report can READ the entry but gets no
                // Edit/Delete (config.dailyReportOwnerOnlyUserIds). The owner
                // themselves still sees both.
                var ownerOnlyIds = config.dailyReportOwnerOnlyUserIds || [];
                if (!isOwner && ownerOnlyIds.indexOf(Number(userId)) !== -1) {
                    actionsEl.innerHTML = '<span style="font-size:12px;color:#71717a">View only</span>';
                    return;
                }
                actionsEl.innerHTML =
                    '<button type="button" class="dr-ta-edit-btn" id="drScriptEdit">Edit</button>' +
                    '<button type="button" class="dr-ta-del-btn" id="drScriptDel">Delete</button>';
                document.getElementById('drScriptEdit').onclick = renderEdit;
                document.getElementById('drScriptDel').onclick = function () {
                    if (!confirm(deleteConfirm)) return;
                    var delBtn = document.getElementById('drScriptDel');
                    delBtn.disabled = true;
                    delBtn.textContent = '...';
                    fetch('/api/creative-uploads', {
                        method: 'POST', credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        body: JSON.stringify({ action: 'delete', id: parseInt(u.id, 10) })
                    }).then(function (r) { return r.json(); }).then(function (body) {
                        if (body.ok) { closeModal(); loadTaUploads(); renderDailyReports(); }
                        else { alert(body.error || 'Delete failed'); delBtn.disabled = false; delBtn.textContent = 'Delete'; }
                    }).catch(function () { delBtn.disabled = false; delBtn.textContent = 'Delete'; });
                };
            }

            function renderEdit() {
                bodyEl.className = 'dr-script-modal-body dr-script-modal-editing';
                bodyEl.innerHTML = '';
                var ta = document.createElement('textarea');
                ta.className = 'input dr-script-edit-ta';
                ta.id = 'drScriptEditTa';
                ta.value = u.content || '';
                bodyEl.appendChild(ta);
                ta.focus();
                actionsEl.innerHTML =
                    '<button type="button" class="dr-ta-edit-btn" id="drScriptSave">' + escapeHtml(updateLabel) + '</button>' +
                    '<button type="button" class="dr-ta-del-btn" id="drScriptCancel">Cancel</button>';
                document.getElementById('drScriptCancel').onclick = renderRead;
                document.getElementById('drScriptSave').onclick = function () {
                    var content = ta.value.trim();
                    if (!content) { alert(emptyAlert); return; }
                    var saveBtn = document.getElementById('drScriptSave');
                    saveBtn.disabled = true;
                    saveBtn.textContent = 'Saving...';
                    // Replace the entry: delete the old one, then save the new text.
                    fetch('/api/creative-uploads', {
                        method: 'POST', credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        body: JSON.stringify({ action: 'delete', id: parseInt(u.id, 10) })
                    }).then(function () {
                        return fetch('/api/creative-uploads', {
                            method: 'POST', credentials: 'same-origin',
                            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                            body: JSON.stringify({ action: 'save_text', user_id: userId, field_key: fieldKey, report_date: reportDate, content: content })
                        });
                    }).then(function (r) { return r.json(); }).then(function (body) {
                        if (body && body.ok) { closeModal(); loadTaUploads(); renderDailyReports(); }
                        else { alert((body && body.error) || 'Save failed'); saveBtn.disabled = false; saveBtn.textContent = updateLabel; }
                    }).catch(function () {
                        alert('Save failed. Try again.'); saveBtn.disabled = false; saveBtn.textContent = updateLabel;
                    });
                };
            }

            if (startInEdit) renderEdit(); else renderRead();
        }

        function loadTaUploads() {
            var grid = document.getElementById('drUpGrid');
            if (!grid) return;
            fetch('/api/creative-uploads?user_id=' + encodeURIComponent(userId) + '&report_date=' + encodeURIComponent(reportDate) + '&field_key=' + encodeURIComponent(fieldKey), {
                credentials: 'same-origin', headers: { 'Accept': 'application/json' }
            }).then(function (r) { return r.json(); }).then(function (data) {
                var uploads = data.uploads || [];
                if (!uploads.length) {
                    grid.innerHTML = '<div class="dr-up-empty">' + escapeHtml(emptyText) + '</div>';
                    return;
                }
                var uploadMap = {};
                var html = '';
                uploads.forEach(function (u) {
                    uploadMap[u.id] = u;
                    var preview = u.content ? (u.content.length > 200 ? u.content.substring(0, 200) + '...' : u.content) : u.file_name;
                    var isTruncated = u.content && u.content.length > 200;
                    html += '<div class="dr-ta-card dr-ta-card-clickable" data-id="' + u.id + '" title="Click to view the full script">' +
                        '<div class="dr-ta-card-header">' +
                            '<span class="dr-ta-card-title">' + escapeHtml(u.file_name) + '</span>' +
                            (u.uploaded_by_name ? '<span class="dr-ta-card-by">by ' + escapeHtml(u.uploaded_by_name) + '</span>' : '') +
                            '<div class="dr-ta-card-actions">' +
                                '<button type="button" class="dr-ta-edit-btn" data-id="' + u.id + '" title="Edit">Edit</button>' +
                                '<button type="button" class="dr-ta-del-btn" data-id="' + u.id + '" title="Delete">Delete</button>' +
                            '</div>' +
                        '</div>' +
                        '<div class="dr-ta-card-body">' + escapeHtml(preview) + '</div>' +
                        (isTruncated ? '<div class="dr-ta-card-more">Click to view full script &rsaquo;</div>' : '') +
                    '</div>';
                });
                grid.innerHTML = html;

                // Click a card (anywhere except its action buttons) to open a
                // popup with the entire uploaded script + Edit/Delete actions.
                grid.querySelectorAll('.dr-ta-card-clickable').forEach(function (card) {
                    card.addEventListener('click', function (e) {
                        if (e.target.closest('.dr-ta-card-actions')) return;
                        var u = uploadMap[card.getAttribute('data-id')];
                        if (u) openScriptModal(u);
                    });
                });

                // Edit button opens the popup straight in edit mode — this
                // path works for managers too (no compose box on the page).
                grid.querySelectorAll('.dr-ta-edit-btn').forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        var u = uploadMap[btn.getAttribute('data-id')];
                        if (u) openScriptModal(u, true);
                    });
                });

                // Delete buttons
                grid.querySelectorAll('.dr-ta-del-btn').forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        if (!confirm(deleteConfirm)) return;
                        btn.disabled = true;
                        btn.textContent = '...';
                        fetch('/api/creative-uploads', {
                            method: 'POST', credentials: 'same-origin',
                            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                            body: JSON.stringify({ action: 'delete', id: parseInt(btn.getAttribute('data-id'), 10) })
                        }).then(function (r) { return r.json(); }).then(function (body) {
                            if (body.ok) { loadTaUploads(); renderDailyReports(); }
                            else { alert(body.error || 'Delete failed'); btn.disabled = false; btn.textContent = 'Delete'; }
                        }).catch(function () { btn.disabled = false; btn.textContent = 'Delete'; });
                    });
                });
            }).catch(function () {
                grid.innerHTML = '<div class="dr-up-empty">' + escapeHtml(loadFailText) + '</div>';
            });
        }

        loadTaUploads();
    }

    function renderUploadPanel(userId, fieldKey, fieldLabel, reportDate, acceptAttr, maxMb, choices, choiceValue) {
        var panelEl = document.getElementById('drUploadPanel');
        if (panelEl && activeUploadPanel === fieldKey + ':' + reportDate) {
            panelEl.remove();
            activeUploadPanel = null;
            return;
        }
        if (panelEl) panelEl.remove();
        var vhPanelEl = document.getElementById('drVideoHandoffPanel');
        if (vhPanelEl) { vhPanelEl.remove(); activeVhPanelKey = null; }
        activeUploadPanel = fieldKey + ':' + reportDate;

        var dateObj = new Date(reportDate + 'T00:00:00');
        var dateLabel = dateObj.toLocaleDateString('en-IN', { timeZone: 'Asia/Kolkata', weekday: 'short', day: 'numeric', month: 'short' });
        var acceptDisplay;
        if (!acceptAttr) {
            acceptDisplay = 'Any file';
        } else if (acceptAttr === 'video/*') {
            acceptDisplay = 'Any video format';
        } else if (acceptAttr.indexOf('/') !== -1) {
            acceptDisplay = acceptAttr;
        } else {
            acceptDisplay = acceptAttr.replace(/\./g, '').toUpperCase().replace(/,/g, ', ');
        }

        var panel = document.createElement('div');
        panel.id = 'drUploadPanel';
        panel.className = 'dr-upload-panel';
        // Content creators get a description box on the raw-video field so they
        // can note what each video is about while uploading. Only the owner
        // composing their own report sees it; the note rides along with each
        // file in uploadFiles() and is shown to Anas & Krishnan on the cards.
        var descBoxHtml = (fieldKey === 'ai_videos_generated' && String(userId) === String(config.userId))
            ? '<div class="dr-up-desc">' +
                '<button type="button" class="dr-up-desc-toggle" id="drUpDescToggle">' +
                    '+ Add description <span class="dr-up-desc-opt">(optional)</span></button>' +
                '<div class="dr-up-desc-field" id="drUpDescField" style="display:none">' +
                    '<label class="dr-up-desc-label" for="drUpDesc">Video description ' +
                        '<span class="dr-up-desc-opt">(shown to Anas &amp; Krishnan)</span></label>' +
                    '<textarea class="input dr-up-desc-input" id="drUpDesc" rows="2" ' +
                        'placeholder="What is this video about? Applies to the file(s) you upload next."></textarea>' +
                '</div>' +
              '</div>'
            : '';

        // "No video for this day" — only the owner of the AI-videos upload field
        // sees it. Lets a creator close out a no-output day without uploading
        // (hits CreativeUploadController mark_no_video, which writes value '0').
        var noVideoHtml = (fieldKey === 'ai_videos_generated' && String(userId) === String(config.userId))
            ? '<div class="dr-up-novideo-row">' +
                '<button type="button" class="dr-up-novideo" id="drUpNoVideo">No video for this day</button>' +
              '</div>'
            : '';

        panel.innerHTML =
            '<div class="dr-up-header">' +
                '<div class="dr-up-title">' + escapeHtml(fieldLabel) + ' &mdash; ' + escapeHtml(dateLabel) + '</div>' +
                '<button type="button" class="dr-up-close" id="drUpClose">&times;</button>' +
            '</div>' +
            descBoxHtml +
            '<div class="dr-up-dropzone" id="drUpDropzone">' +
                '<svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" opacity="0.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>' +
                '<div class="dr-up-drop-text">Drop files here or <span class="dr-up-browse">click to browse</span> ' +
                    '<span class="dr-up-or">or</span> <span class="dr-up-browse" id="drUpBrowseFolder">choose folder</span>' +
                '</div>' +
                '<div class="dr-up-drop-hint">Accepted: ' + escapeHtml(acceptDisplay) + ' (max ' + Math.min(maxMb || 1024, 1000) + ' MB per file — server cap is 1 GB. Compress larger videos before uploading.)</div>' +
                '<div class="dr-up-drop-hint">Tip: you can drag a whole folder straight onto this box — every video inside uploads automatically.</div>' +
                '<input type="file" id="drUpFileInput" multiple accept="' + escapeHtml(acceptAttr) + '" style="display:none">' +
                '<input type="file" id="drUpFolderInput" webkitdirectory directory multiple style="display:none">' +
            '</div>' +
            noVideoHtml +
            '<div class="dr-up-grid" id="drUpGrid"><div class="dr-up-loading">Loading uploads...</div></div>';

        var tableWrap = document.querySelector('.dr-table-wrap');
        if (tableWrap) {
            tableWrap.parentNode.insertBefore(panel, tableWrap.nextSibling);
        } else {
            document.getElementById('dailyReportContent').appendChild(panel);
        }

        mountDrChoiceSection(panel, {
            userId: userId, fieldKey: fieldKey, reportDate: reportDate,
            choices: choices, choiceValue: choiceValue,
            isOwner: String(userId) === String(config.userId)
        });

        document.getElementById('drUpClose').onclick = function () {
            panel.remove();
            activeUploadPanel = null;
        };

        // Optional description box — revealed on demand so the upload panel
        // stays clean for creators who don't want to add a note.
        var descToggle = document.getElementById('drUpDescToggle');
        if (descToggle) descToggle.addEventListener('click', function () {
            var field = document.getElementById('drUpDescField');
            if (!field) return;
            var show = field.style.display === 'none';
            field.style.display = show ? '' : 'none';
            descToggle.classList.toggle('dr-up-desc-toggle-open', show);
            if (show) {
                var ta = document.getElementById('drUpDesc');
                if (ta) ta.focus();
            }
        });

        var dropzone = document.getElementById('drUpDropzone');
        var fileInput = document.getElementById('drUpFileInput');
        var folderInput = document.getElementById('drUpFolderInput');
        var browseFolderBtn = document.getElementById('drUpBrowseFolder');

        dropzone.addEventListener('click', function (e) {
            // Folder shortcut has its own handler — don't trigger file picker
            // when the user actually clicked "choose folder".
            if (e.target && e.target.id === 'drUpBrowseFolder') return;
            fileInput.click();
        });
        dropzone.addEventListener('dragover', function (e) { e.preventDefault(); dropzone.classList.add('dr-up-dragover'); });
        dropzone.addEventListener('dragleave', function () { dropzone.classList.remove('dr-up-dragover'); });
        dropzone.addEventListener('drop', function (e) {
            e.preventDefault();
            dropzone.classList.remove('dr-up-dragover');
            // Expand any dropped folder(s) recursively, then filter to the
            // accepted types so a dragged folder behaves like the folder picker.
            // readDroppedEntries captures from e.dataTransfer synchronously.
            readDroppedEntries(e.dataTransfer).then(function (files) {
                if (!files.length) return; // empty folder / nothing readable
                var picked = filterFilesByAccept(files, acceptAttr);
                if (!picked.length) {
                    alert('No files in what you dropped match the accepted file types.');
                    return;
                }
                uploadFiles(picked);
            });
        });
        fileInput.addEventListener('change', function () {
            if (fileInput.files.length) uploadFiles(fileInput.files);
            fileInput.value = '';
        });
        if (browseFolderBtn) {
            browseFolderBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                folderInput.click();
            });
        }
        folderInput.addEventListener('change', function () {
            if (!folderInput.files.length) return;
            // Filter the folder to the accepted types so non-matching files
            // don't 422 server-side or surface as "X errors" to the user.
            var picked = filterFilesByAccept(folderInput.files, acceptAttr);
            if (!picked.length) {
                alert('No files in that folder match the accepted file types.');
                folderInput.value = '';
                return;
            }
            uploadFiles(picked);
            folderInput.value = '';
        });

        // "No video for this day" — mark the cell done with zero videos instead of
        // leaving it stuck on "Upload" (which blocks daily-report sign-off).
        var noVideoBtn = document.getElementById('drUpNoVideo');
        if (noVideoBtn) {
            noVideoBtn.addEventListener('click', function () {
                if (!confirm('Mark this day as "No video"? Your daily report will be updated with 0 videos for this field.')) return;
                noVideoBtn.disabled = true;
                noVideoBtn.textContent = 'Saving...';
                var fd = new FormData();
                fd.append('action', 'mark_no_video');
                fd.append('user_id', userId);
                fd.append('field_key', fieldKey);
                fd.append('report_date', reportDate);
                fetch('/api/creative-uploads', {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    body: fd
                }).then(function (r) { return r.json(); }).then(function (b) {
                    if (!b || !b.ok) {
                        alert((b && b.error) || 'Could not mark "No video". Please try again.');
                        noVideoBtn.disabled = false;
                        noVideoBtn.textContent = 'No video for this day';
                        return;
                    }
                    panel.remove();
                    activeUploadPanel = null;
                    renderDailyReports();
                }).catch(function () {
                    alert('Network error — could not mark "No video".');
                    noVideoBtn.disabled = false;
                    noVideoBtn.textContent = 'No video for this day';
                });
            });
        }

        // nginx + php-fpm both cap uploads at 1024 MiB (1 GB) on the tessa
        // host as of 2026-05-26. Subtract a small headroom (24 MiB) for
        // multipart boundaries + form fields so a file that just barely
        // fits doesn't get killed by nginx with a 413 mid-upload. KPI
        // `upload_max_mb` can still be lower — we use the stricter of two.
        var SERVER_MAX_MB = 1024;
        var MULTIPART_HEADROOM_MB = 24;
        var effectiveMaxMb = Math.min(maxMb || SERVER_MAX_MB, SERVER_MAX_MB - MULTIPART_HEADROOM_MB);

        function _formatSize(bytes) {
            if (bytes >= 1024 * 1024 * 1024) return (bytes / (1024 * 1024 * 1024)).toFixed(1).replace(/\.0$/, '') + ' GB';
            if (bytes >= 1024 * 1024) return (bytes / (1024 * 1024)).toFixed(0) + ' MB';
            return (bytes / 1024).toFixed(0) + ' KB';
        }

        function uploadFiles(files) {
            var grid = document.getElementById('drUpGrid');
            // Description box (raw-video field only) — one note for this batch.
            var descEl = document.getElementById('drUpDesc');
            var desc = descEl ? descEl.value.trim() : '';
            var errored = [];
            // Upload at most UPLOAD_CONCURRENCY at a time. Firing a whole folder in
            // parallel exhausted the 5-worker fpm pool and cut uploads mid-stream
            // (the classic "couldn't upload the folder"); the limiter fixes that.
            runWithConcurrency(Array.from(files), UPLOAD_CONCURRENCY, uploadOne)
                .then(function () { finishBatch(errored); });

            function uploadOne(file) {
                return new Promise(function (resolve) {
                    if (file.size > effectiveMaxMb * 1024 * 1024) {
                        var lbl = _formatSize(file.size) + ', max ' + effectiveMaxMb + ' MB';
                        errored.push(file.name + ' — too large (' + lbl + '). Please compress before uploading.');
                        resolve();
                        return;
                    }
                    var placeholder = document.createElement('div');
                    placeholder.className = 'dr-ucard dr-ucard-uploading';
                    placeholder.innerHTML = '<div class="dr-ucard-thumb"><div class="dr-ucard-icon"><span>...</span></div></div><div class="dr-ucard-info"><span class="dr-ucard-name">Uploading ' + escapeHtml(file.name) + ' (' + _formatSize(file.size) + ')</span></div>';
                    grid.appendChild(placeholder);

                    var formData = new FormData();
                    formData.append('action', 'upload');
                    formData.append('user_id', userId);
                    formData.append('field_key', fieldKey);
                    formData.append('report_date', reportDate);
                    formData.append('file', file);
                    if (desc) formData.append('description', desc);
                    // Folder uploads carry the folder label so loadUploads() can
                    // group them into one named card; loose uploads send nothing.
                    var folderName = folderNameOf(file);
                    if (folderName) formData.append('folder_name', folderName);
                    fetch('/api/creative-uploads', {
                        method: 'POST', credentials: 'same-origin',
                        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        body: formData
                    }).then(function (r) {
                        // nginx returns its own HTML page for 413, so r.json() would
                        // SyntaxError and fall into .catch() as "network error" —
                        // historically the most misleading symptom of an oversized
                        // upload. Branch on status first to give a usable hint.
                        if (r.status === 413) {
                            return { ok: false, error: 'File too large for server (limit 1 GB). Please compress before uploading.' };
                        }
                        if (r.status === 504 || r.status === 502) {
                            return { ok: false, error: 'Upload timed out at the server. Try again on a faster connection or compress the file.' };
                        }
                        return r.json().catch(function () {
                            return { ok: false, error: 'Server returned an unexpected response (HTTP ' + r.status + ').' };
                        });
                    }).then(function (body) {
                        placeholder.remove();
                        if (!body.ok) {
                            // Laravel auto-validation (e.g. "file" rule failing on
                            // UPLOAD_ERR_PARTIAL) returns {message, errors} not
                            // {error}. The "file" key starts with "The file field"
                            // and almost always means the upload was cut mid-stream
                            // — surface that explicitly instead of cryptic "failed".
                            var msg = body.error;
                            if (!msg && body.message && /^The file field/.test(body.message)) {
                                msg = 'Upload was interrupted (' + _formatSize(file.size) + '). Slow or unstable connection — please retry.';
                            }
                            errored.push(file.name + ': ' + (msg || body.message || 'failed'));
                        }
                    }).catch(function () {
                        placeholder.remove();
                        // Genuine network drop — fetch didn't even get a status.
                        errored.push(file.name + ' — network dropped (' + _formatSize(file.size) + '). Check your connection and retry.');
                    }).finally(function () {
                        resolve();
                    });
                });
            }
        }

        function finishBatch(errors) {
            if (errors.length) alert('Some files failed:\n' + errors.join('\n'));
            loadUploads();
            renderDailyReports();
        }

        function loadUploads() {
            var grid = document.getElementById('drUpGrid');
            if (!grid) return;
            fetch('/api/creative-uploads?user_id=' + encodeURIComponent(userId) + '&report_date=' + encodeURIComponent(reportDate) + '&field_key=' + encodeURIComponent(fieldKey), {
                credentials: 'same-origin', headers: { 'Accept': 'application/json' }
            }).then(function (r) { return r.json(); }).then(function (data) {
                var uploads = data.uploads || [];
                if (!uploads.length) {
                    grid.innerHTML = '<div class="dr-up-empty">No files uploaded yet. Drop files above to get started.</div>';
                    return;
                }
                // One flat card's inner markup (thumb + info + delete). Reused
                // for loose uploads AND for rows inside a folder card.
                function ucardHtml(u) {
                    return '<div class="dr-ucard">' +
                        '<a href="' + escapeHtml(u.file_path) + '" target="_blank" class="dr-ucard-thumb" title="Open file">' + fileThumbHtml(u) + '</a>' +
                        '<div class="dr-ucard-info">' +
                            '<a href="' + escapeHtml(u.file_path) + '" target="_blank" class="dr-ucard-name" title="' + escapeHtml(u.file_name) + '">' + escapeHtml(u.file_name) + '</a>' +
                            '<span class="dr-ucard-meta">' + formatFileSize(u.file_size) + (u.uploaded_by_name ? ' · by ' + escapeHtml(u.uploaded_by_name) : '') + '</span>' +
                            (u.content ? '<span class="dr-ucard-desc" title="' + escapeHtml(u.content) + '">' + escapeHtml(u.content) + '</span>' : '') +
                        '</div>' +
                        '<button type="button" class="dr-ucard-del" data-id="' + u.id + '" title="Delete">' +
                            '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>' +
                        '</button>' +
                    '</div>';
                }

                // Single pass: build ordered blocks. A loose upload is its own
                // block; uploads sharing a folder_name collapse into one folder
                // block created at first sighting (preserves created_at order).
                var blocks = [];         // {type:'loose', u} | {type:'folder', name, items:[]}
                var folderIndex = {};    // folder_name -> blocks[] index
                uploads.forEach(function (u) {
                    var fname = u.folder_name || '';
                    if (!fname) { blocks.push({ type: 'loose', u: u }); return; }
                    if (folderIndex[fname] === undefined) {
                        folderIndex[fname] = blocks.length;
                        blocks.push({ type: 'folder', name: fname, items: [] });
                    }
                    blocks[folderIndex[fname]].items.push(u);
                });

                var html = '';
                blocks.forEach(function (b, bi) {
                    if (b.type === 'loose') { html += ucardHtml(b.u); return; }
                    var n = b.items.length;
                    html += '<div class="dr-folder-card" data-folder-idx="' + bi + '">' +
                        '<button type="button" class="dr-folder-head" aria-expanded="false">' +
                            '<svg class="dr-folder-chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>' +
                            '<svg class="dr-folder-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>' +
                            '<span class="dr-folder-name" title="' + escapeHtml(b.name) + '">' + escapeHtml(b.name) + '</span>' +
                            '<span class="dr-folder-count">(' + n + ' video' + (n === 1 ? '' : 's') + ')</span>' +
                        '</button>' +
                        '<div class="dr-folder-body">' + b.items.map(ucardHtml).join('') + '</div>' +
                    '</div>';
                });
                grid.innerHTML = html;

                // Expand/collapse (client-side only) — toggle a class + aria.
                grid.querySelectorAll('.dr-folder-head').forEach(function (head) {
                    head.addEventListener('click', function () {
                        var card = head.closest('.dr-folder-card');
                        if (!card) return;
                        var open = card.classList.toggle('dr-folder-open');
                        head.setAttribute('aria-expanded', open ? 'true' : 'false');
                    });
                });

                // Per-file delete — unchanged wiring; also covers rows nested
                // inside folder cards because they reuse .dr-ucard-del[data-id].
                grid.querySelectorAll('.dr-ucard-del').forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        if (!confirm('Delete this file?')) return;
                        btn.disabled = true;
                        fetch('/api/creative-uploads', {
                            method: 'POST', credentials: 'same-origin',
                            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                            body: JSON.stringify({ action: 'delete', id: parseInt(btn.getAttribute('data-id'), 10) })
                        }).then(function (r) { return r.json(); }).then(function (body) {
                            if (body.ok) { loadUploads(); renderDailyReports(); }
                            else { alert(body.error || 'Delete failed'); btn.disabled = false; }
                        }).catch(function () { btn.disabled = false; });
                    });
                });
            }).catch(function () {
                grid.innerHTML = '<div class="dr-up-empty">Failed to load uploads.</div>';
            });
        }

        loadUploads();
    }

    async function renderDailyReports() {
        var root = document.getElementById('dailyView');
        if (!root) return;
        var dc = getDailyConfig();
        var teamMembers = dc.teamMembers || [];
        var hasTeam = teamMembers.length > 1;
        var userId;
        if (hasTeam) {
            if (!activeDailyPerson) activeDailyPerson = String(teamMembers[0].id);
            userId = activeDailyPerson;
        } else {
            userId = dc.userId || (dc.userIds && dc.userIds[0]) || config.userId;
        }
        var editable = dc.editable !== false;
        var selectedMember = hasTeam ? teamMembers.find(function (m) { return String(m.id) === String(userId); }) : null;
        // Team Lead – Operations: when Nitha views her OWN report in a team view, title it
        // by her role (dc.label = "Team Lead - Operations") instead of "Name ()". Scoped to
        // this portal so other managers' own-report headers are unchanged.
        var isOwnReport = selectedMember && String(selectedMember.id) === String(config.userId);
        var label = selectedMember
            ? (isOwnReport && config.portal === 'team_lead_operations'
                ? (dc.label || selectedMember.name)
                : selectedMember.name + ' (' + selectedMember.project + ')')
            : (dc.label || 'Daily Reports');
        var ownerRoleSlug = selectedMember ? (selectedMember.roleSlug || '') : (config.portal || '');
        var useScriptWording = ownerRoleSlug === 'content_creator' || ownerRoleSlug === 'content_lead';

        // Video handoff pipeline visibility is keyed to the logged-in user
        // — not the selected tab. The backend stamps dc.videoHandoffsView as
        // 'editor' (Anaz #18), 'viewer' (Krishnan/admin/creator) or null.
        // `vhViewer`/`vhView` are module-scope so the panel/group helpers can
        // branch without prop-drilling.
        vhViewer = String(config.userId);
        vhView = dc.videoHandoffsView || null;
        var showVideoHandoffs = vhView !== null;
        activeVhPanelKey = null;

        var personSelectorHtml = '';
        if (hasTeam) {
            personSelectorHtml = '<div class="daily-person-strip-wrap"><div class="daily-person-strip" id="dailyPersonStrip">' +
                teamMembers.map(function (m) {
                    var active = String(m.id) === String(userId) ? ' active' : '';
                    return '<button type="button" class="daily-person-chip' + active + '" data-id="' + escapeHtml(String(m.id)) + '">' + escapeHtml(m.name) + '</button>';
                }).join('') + '</div></div>';
        }

        root.innerHTML = '<div class="daily-wrap">' + personSelectorHtml + '<div id="dailyReportContent"><div class="kpi-status-msg">Loading daily report...</div></div></div>';

        root.querySelectorAll('.daily-person-chip').forEach(function (chip) {
            chip.addEventListener('click', function () {
                activeDailyPerson = chip.getAttribute('data-id');
                renderDailyReports();
                syncPortalHash();
            });
        });

        var dailyWeekStart = startOfWeek(currentReportDate);
        var wk = weekKey(dailyWeekStart);
        var todayStr = dateKey(new Date());

        try {
            var weekData = await fetchWeeklyDailySummary(wk, userId);
            var targetsPayload = { targets: {} };
            try {
                var kpiData = await requestJson('/api/kpi?week_key=' + encodeURIComponent(wk) + '&user_id=' + encodeURIComponent(userId));
                targetsPayload = kpiData.data || { targets: {} };
            } catch (err) {
                console.error('KPI data fetch failed in renderDailyReports', err);
            }
            var targets = targetsPayload.targets || {};

            var vhData = null;
            if (showVideoHandoffs) {
                try {
                    vhData = await requestJson('/api/video-handoffs?week_key=' + encodeURIComponent(wk));
                } catch (vhErr) {
                    console.error('Video handoffs fetch failed', vhErr);
                    vhData = { rows: [], canEdit: false };
                }
            }
            vhWeekData = vhData;

            var daysArr = weekData.days || [];
            var fields = weekData.fields || [];
            var aggMap = weekData.aggregation || {};
            var metaHints = weekData.metaHints || {};
            var dayMap = {};
            var choicesMap = {};
            daysArr.forEach(function (d) {
                dayMap[d.reportDate] = d.entries || {};
                choicesMap[d.reportDate] = d.choices || {};
            });

            var dateCols = [];
            for (var i = 0; i < 7; i++) {
                var d = addDays(dailyWeekStart, i);
                var dk = dateKey(d);
                dateCols.push({
                    date: d,
                    dateKey: dk,
                    label: d.toLocaleDateString('en-IN', { timeZone: 'Asia/Kolkata', weekday: 'short' }),
                    dateLabel: d.toLocaleDateString('en-IN', { timeZone: 'Asia/Kolkata', day: '2-digit', month: 'short' }),
                    editable: editable,
                    isToday: dk === todayStr,
                    entries: dayMap[dk] || {},
                    choices: choicesMap[dk] || {}
                });
            }

            var summary = computeWeeklySummaryLocal(daysArr, fields, aggMap);
            var weekEnd = addDays(dailyWeekStart, 6);
            var videoGroupHtml = showVideoHandoffs ? buildVideoHandoffGroup(vhData, dateCols) : '';

            var metaText = editable ? 'Click any editable cell to update. Weekly KPIs auto-calculate from this table.' : 'Read-only view of ' + escapeHtml(label) + '.';
            var reportHtml = '<div class="daily-header"><div><h2>Daily Reports — ' + escapeHtml(label) + '</h2><div class="daily-meta">' + metaText + '</div></div>';
            reportHtml += '<div class="daily-date-nav"><button class="mtg-nav-btn" id="dailyPrevWeek">&#8592;</button><div class="daily-date-pill">' + escapeHtml(formatDate(dailyWeekStart)) + ' — ' + escapeHtml(formatDate(weekEnd)) + '</div><button class="mtg-nav-btn" id="dailyNextWeek">&#8594;</button></div></div>';
            // Excel export over a custom date range (Shoyab / Finance only). Pulls
            // every person currently visible in this tab; server re-authorizes each.
            if (config.canExportDailyReports) {
                reportHtml += '<div class="daily-export" style="margin:8px 0;display:flex;align-items:center;gap:6px;flex-wrap:wrap;font-size:13px;">'
                    + '<span style="font-weight:600;">Export to Excel:</span> '
                    + '<input type="date" id="dailyExportFrom" class="daily-export-date"> '
                    + '<span>to</span> '
                    + '<input type="date" id="dailyExportTo" class="daily-export-date"> '
                    + '<button class="btn-secondary" id="dailyExportBtn" type="button">Download .xlsx</button>'
                    + '</div>';
            }
            if (editable) reportHtml += '<div class="kpi-status-msg" id="dailySaveStatus">Auto-saves on blur. Click any cell to update.</div>';

            if (fields.length === 0 && !videoGroupHtml) {
                reportHtml += '<div class="kpi-empty-state"><p>No KPIs assigned yet.</p></div>';
            } else {
                var uniqueGroups = [];
                fields.forEach(function (f) {
                    if (uniqueGroups.indexOf(f.group) === -1) uniqueGroups.push(f.group);
                });
                var filterableGroups = uniqueGroups.filter(function (g) { return DR_FILTER_STICKY_GROUPS.indexOf(g) === -1; });
                if (filterableGroups.length > 1) {
                    if (activeDailyAppFilter !== 'all' && filterableGroups.indexOf(activeDailyAppFilter) === -1) activeDailyAppFilter = 'all';
                    reportHtml += '<div class="dr-app-filter"><label class="dr-app-filter-label">Project:</label><select class="input input-sm dr-app-filter-select" id="dailyAppFilter">' +
                        '<option value="all"' + (activeDailyAppFilter === 'all' ? ' selected' : '') + '>All Projects</option>' +
                        filterableGroups.map(function (g) { return '<option value="' + escapeHtml(g) + '"' + (activeDailyAppFilter === g ? ' selected' : '') + '>' + escapeHtml(g) + '</option>'; }).join('') +
                        '</select></div>';
                }

                reportHtml += '<div class="dr-table-wrap"><table class="dr-table"><thead><tr><th class="dr-metric-th">Metric</th>';
                dateCols.forEach(function (col) {
                var cls = col.isToday ? ' dr-today' : '';
                reportHtml += '<th class="dr-day-th' + cls + '"><div class="dr-day-name">' + escapeHtml(col.label) + '</div><div class="dr-day-date">' + escapeHtml(col.dateLabel) + '</div>' + (col.editable ? '<div class="dr-editable-tag">Editable</div>' : '') + '</th>';
            });
            reportHtml += '<th class="dr-summary-th">Weekly</th></tr></thead><tbody>';

            var lastGroup = '';
            fields.forEach(function (f) {
                if (f.group !== lastGroup) {
                    lastGroup = f.group;
                    reportHtml += '<tr class="dr-group-row" data-group="' + escapeHtml(f.group) + '"><td colspan="' + (dateCols.length + 2) + '">' + escapeHtml(f.group) + '</td></tr>';
                }
                var syncBadge = f.auto_sync ? '<span class="dr-auto-sync-badge">API</span>' : '';
                var uploadBadge = f.input_type === 'upload' ? '<span class="dr-upload-badge">Upload</span>' : (f.input_type === 'textarea' && useScriptWording ? '<span class="dr-upload-badge">Script</span>' : '');
                var isSpendRow = /_daily_ad_spend$/.test(f.key);
                var isCpaRow = /_cpa$|_cpp$/.test(f.key);
                var teamHint = f.is_team_total ? '<div class="dr-team-hint">Team total — only add your own work, team uploads auto-counted</div>' : '';
                reportHtml += '<tr class="dr-data-row' + (isSpendRow ? ' dr-row-spend' : '') + (isCpaRow ? ' dr-row-spacer' : '') + '" data-group="' + escapeHtml(f.group) + '"><td class="dr-metric-cell">' + escapeHtml(f.label) + syncBadge + uploadBadge + teamHint + '</td>';
                dateCols.forEach(function (col) {
                    var val = col.entries[f.key] || '';
                    var hasVal = val !== '';
                    var cls = col.isToday ? ' dr-today' : '';
                    var filledCls = hasVal ? ' dr-cell-filled' : '';
                    if (f.input_type === 'status' && col.editable) {
                        var statusVal = val || '';
                        var statusCls = statusVal === 'Done' ? ' dr-status-done' : (statusVal === 'Not Done' ? ' dr-status-notdone' : '');
                        reportHtml += '<td class="dr-cell dr-cell-status' + cls + '">' +
                            '<select class="dr-status-select' + statusCls + '" data-field="' + escapeHtml(f.key) + '" data-date="' + escapeHtml(col.dateKey) + '">' +
                            '<option value=""' + (!statusVal ? ' selected' : '') + '>—</option>' +
                            '<option value="Done"' + (statusVal === 'Done' ? ' selected' : '') + '>Done</option>' +
                            '<option value="Not Done"' + (statusVal === 'Not Done' ? ' selected' : '') + '>Not Done</option>' +
                            '</select></td>';
                    } else if ((f.input_type === 'upload' || f.input_type === 'textarea') && col.editable) {
                        var count = parseInt(val, 10) || 0;
                        var rawAccept = (f.upload_accept || '').trim();
                        var acceptAttr;
                        if (rawAccept === '' || rawAccept === '*' || rawAccept === '*/*') {
                            acceptAttr = '';
                        } else if (rawAccept.indexOf('/') !== -1) {
                            // MIME pattern like "video/*" — pass through.
                            acceptAttr = rawAccept;
                        } else {
                            acceptAttr = rawAccept.split(',').map(function (e) { return '.' + e.trim(); }).join(',');
                        }
                        var isTextarea = f.input_type === 'textarea';
                        var hasChoices = Array.isArray(f.choices) && f.choices.length > 0;
                        var choiceVal = (col.choices && col.choices[f.key]) || '';
                        var choiceLabel = '';
                        if (hasChoices && choiceVal) {
                            var match = f.choices.find(function (c) { return c.value === choiceVal; });
                            choiceLabel = match ? match.label : '';
                        }
                        var cellLabel;
                        if (isTextarea) {
                            if (count > 0) {
                                var noun = useScriptWording ? (count > 1 ? 'scripts' : 'script') : (count > 1 ? 'entries' : 'entry');
                                cellLabel = count + ' ' + noun + ' &#9662;';
                            } else {
                                cellLabel = 'Add &#9662;';
                            }
                        } else if (count > 0) {
                            cellLabel = count + ' file' + (count > 1 ? 's' : '') + ' &#9662;';
                        } else if (f.key === 'ai_videos_generated' && val === '0') {
                            // Explicitly marked "No video for this day" (daily_reports.value = '0').
                            cellLabel = 'No video &#9662;';
                        } else {
                            cellLabel = 'Upload &#9662;';
                        }
                        // The old "Pick status" sender/receiver choice chip was
                        // retired with the Video Handoff pipeline. Data
                        // attributes stay for any existing choice-aware popup
                        // wiring but no visual chip renders.
                        var choicesAttr = hasChoices ? escapeHtml(JSON.stringify(f.choices)) : '';
                        var activeClass = (activeUploadPanel === f.key + ':' + col.dateKey) ? ' dr-up-cell-active' : '';
                        reportHtml += '<td class="dr-cell dr-cell-upload' + cls + '">' +
                            '<button type="button" class="dr-up-cell-btn' + activeClass + '" data-field="' + escapeHtml(f.key) + '" data-label="' + escapeHtml(f.label) + '" data-date="' + escapeHtml(col.dateKey) + '" data-user="' + escapeHtml(String(userId)) + '" data-accept="' + escapeHtml(acceptAttr) + '" data-max-mb="' + (f.upload_max_mb || 10) + '" data-input-type="' + escapeHtml(f.input_type) + '" data-script-wording="' + (useScriptWording ? '1' : '0') + '" data-choices="' + choicesAttr + '" data-choice-value="' + escapeHtml(choiceVal) + '">' + cellLabel + '</button>' +
                            '</td>';
                    } else if (f.input_type === 'text_multiline') {
                        if (col.editable) {
                            reportHtml += '<td class="dr-cell dr-cell-edit dr-cell-multiline' + cls + filledCls + '"><textarea class="input dr-input dr-input-multiline" data-field="' + escapeHtml(f.key) + '" data-date="' + escapeHtml(col.dateKey) + '" rows="3" placeholder="—">' + escapeHtml(val) + '</textarea></td>';
                        } else {
                            reportHtml += '<td class="dr-cell dr-cell-locked dr-cell-multiline' + cls + filledCls + '">' + (val ? escapeHtml(val) : '—') + '</td>';
                        }
                    } else if (col.editable && !f.auto_sync && f.input_type !== 'upload' && f.input_type !== 'textarea' && f.input_type !== 'status' && f.input_type !== 'text_multiline') {
                        var hintVal = (metaHints[f.key] && metaHints[f.key][col.dateKey]) || '';
                        var hintHtml = hintVal ? '<div class="dr-meta-hint" title="From Meta Ads upload">' + escapeHtml(hintVal) + '</div>' : '';
                        reportHtml += '<td class="dr-cell dr-cell-edit' + cls + filledCls + '"><input class="input dr-input" data-field="' + escapeHtml(f.key) + '" data-date="' + escapeHtml(col.dateKey) + '" value="' + escapeHtml(val) + '" placeholder="—">' + hintHtml + '</td>';
                    } else {
                        reportHtml += '<td class="dr-cell dr-cell-locked' + cls + filledCls + '">' + escapeHtml(val || '—') + '</td>';
                    }
                });
                var sumVal = summary[f.key] || '';
                var tgt = targets[f.key] || '';
                var sumNum = parseFloat(stripCommas(sumVal));
                var tgtNum = parseFloat(stripCommas(tgt));
                var hasSum = sumVal !== '' && !isNaN(sumNum);
                var hasTgt = tgt !== '' && !isNaN(tgtNum) && tgtNum > 0;
                var prgPct = 0, prgCls = 'dr-prog-neutral';
                var badgeHtml = '';
                if (hasSum && hasTgt) {
                    prgPct = Math.min(Math.round((sumNum / tgtNum) * 100), 100);
                    if (sumNum >= tgtNum) { prgCls = 'dr-prog-met'; badgeHtml = '<span class="dr-badge dr-badge-met">&#10003; Met</span>'; }
                    else { prgCls = 'dr-prog-low'; badgeHtml = '<span class="dr-badge dr-badge-low">' + prgPct + '%</span>'; }
                } else if (hasSum) { prgPct = 100; }
                reportHtml += '<td class="dr-cell dr-summary-cell"><span class="dr-summary-val">' + escapeHtml(sumVal || '—') + '</span>' + (hasTgt ? '<span class="dr-summary-tgt">/ ' + escapeHtml(tgt) + '</span>' : '') + badgeHtml + '<div class="dr-prog-wrap"><div class="dr-prog-bar ' + prgCls + '" style="width:' + prgPct + '%"></div></div><span class="dr-summary-agg">' + aggLabel(f.key, aggMap) + '</span></td></tr>';
            });
                reportHtml += videoGroupHtml;
                reportHtml += '</tbody></table></div>';
            }

            var dailyContentEl = document.getElementById('dailyReportContent');
            if (dailyContentEl) dailyContentEl.innerHTML = reportHtml;

            var appFilter = document.getElementById('dailyAppFilter');
            if (appFilter) {
                function applyAppFilter(val) {
                    var rows = dailyContentEl.querySelectorAll('tr[data-group]');
                    rows.forEach(function (row) {
                        var g = row.getAttribute('data-group');
                        var sticky = DR_FILTER_STICKY_GROUPS.indexOf(g) !== -1;
                        row.style.display = (sticky || val === 'all' || g === val) ? '' : 'none';
                    });
                }
                appFilter.addEventListener('change', function () {
                    activeDailyAppFilter = appFilter.value;
                    try { if (window.localStorage) localStorage.setItem('dailyAppFilter', activeDailyAppFilter); } catch (e) {}
                    applyAppFilter(activeDailyAppFilter);
                });
                if (activeDailyAppFilter !== 'all') applyAppFilter(activeDailyAppFilter);
            }

            var prevBtn = document.getElementById('dailyPrevWeek');
            if (prevBtn) prevBtn.addEventListener('click', function () { currentReportDate = addDays(dailyWeekStart, -7); renderDailyReports(); syncPortalHash(); });
            var nextBtn = document.getElementById('dailyNextWeek');
            if (nextBtn) nextBtn.addEventListener('click', function () { currentReportDate = addDays(dailyWeekStart, 7); renderDailyReports(); syncPortalHash(); });

            if (config.canExportDailyReports) {
                var exFrom = document.getElementById('dailyExportFrom');
                var exTo = document.getElementById('dailyExportTo');
                var exBtn = document.getElementById('dailyExportBtn');
                var exNow = new Date();
                function exYmd(d) {
                    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
                }
                // Default range: 1st of the current month → today.
                if (exFrom && !exFrom.value) exFrom.value = exYmd(new Date(exNow.getFullYear(), exNow.getMonth(), 1));
                if (exTo && !exTo.value) exTo.value = exYmd(exNow);
                if (exBtn) exBtn.addEventListener('click', function () {
                    var from = exFrom ? exFrom.value : '';
                    var to = exTo ? exTo.value : '';
                    if (!from || !to) { alert('Pick both a "from" and "to" date.'); return; }
                    if (from > to) { alert('"From" date must be on or before "to" date.'); return; }
                    var dc = getDailyConfig();
                    var ids = (dc.userIds && dc.userIds.length ? dc.userIds : [config.userId]).join(',');
                    var url = '/api/daily-reports/export?from=' + encodeURIComponent(from) + '&to=' + encodeURIComponent(to) + '&user_ids=' + encodeURIComponent(ids);
                    var a = document.createElement('a');
                    a.href = url;
                    document.body.appendChild(a);
                    a.click();
                    a.remove();
                });
            }

            if (editable) {
                // In-place updates after auto-save: keep focus where the user is typing.
                // Re-rendering the whole table on every save destroys focus and forces the
                // user to click the next cell.
                function setFilledState(el) {
                    var td = el.closest('td');
                    if (!td) return;
                    var hasVal = (el.value || '').trim() !== '';
                    td.classList.toggle('dr-cell-filled', hasVal);
                }

                function recomputeWeeklyForField(fieldKey) {
                    var inputs = root.querySelectorAll('.dr-input[data-field="' + fieldKey.replace(/"/g, '\\"') + '"]');
                    if (!inputs.length) return;
                    var nums = [];
                    var latest = '';
                    inputs.forEach(function (inp) {
                        var raw = (inp.value || '').replace(/[₹%,\s]/g, '').trim();
                        if (raw !== '') {
                            latest = inp.value;
                            var n = parseFloat(raw);
                            if (!isNaN(n)) nums.push(n);
                        }
                    });
                    var agg = aggMap[fieldKey];
                    var summary = '';
                    if (agg === 'sum' && nums.length) {
                        summary = String(nums.reduce(function (a, b) { return a + b; }, 0) + 0);
                    } else if (agg === 'avg' && nums.length) {
                        summary = String(Math.round(nums.reduce(function (a, b) { return a + b; }, 0) / nums.length * 100) / 100);
                    } else if (agg === 'latest') {
                        summary = latest || '';
                    }
                    var row = inputs[0].closest('tr');
                    if (!row) return;
                    var valEl = row.querySelector('.dr-summary-cell .dr-summary-val');
                    if (valEl) valEl.textContent = summary || '—';
                }

                var _drSaveTimers = {};
                var _drSaveSeq = {};

                async function commitDailySave(el) {
                    var fieldKey = el.getAttribute('data-field');
                    var dateKey = el.getAttribute('data-date');
                    var key = fieldKey + '|' + dateKey;
                    var status = document.getElementById('dailySaveStatus');
                    var seq = (_drSaveSeq[key] = (_drSaveSeq[key] || 0) + 1);
                    var value = el.value;
                    if (status) { status.textContent = 'Saving…'; status.className = 'kpi-status-msg'; }
                    try {
                        await saveDailyEntry(dateKey, fieldKey, value, userId);
                        // Ignore stale responses if user typed again before save returned.
                        if (_drSaveSeq[key] !== seq) return;
                        if (status) { status.textContent = 'Saved'; status.className = 'kpi-status-msg saved'; }
                        setFilledState(el);
                        recomputeWeeklyForField(fieldKey);
                    } catch (e) {
                        console.error('Save failed', e);
                        if (status) { status.textContent = (e && e.message) || 'Save failed'; status.className = 'kpi-status-msg'; }
                    }
                }

                function scheduleDailySave(el) {
                    var key = el.getAttribute('data-field') + '|' + el.getAttribute('data-date');
                    clearTimeout(_drSaveTimers[key]);
                    _drSaveTimers[key] = setTimeout(function () { commitDailySave(el); }, 600);
                }

                // Grow a multi-line content box to fit all its text so there is
                // never an inner scrollbar — the whole update stays readable for
                // the writer and for managers reviewing the report.
                function drAutoGrow(el) {
                    el.style.height = 'auto';
                    el.style.height = el.scrollHeight + 'px';
                }
                root.querySelectorAll('.dr-input-multiline').forEach(function (ta) {
                    drAutoGrow(ta);
                    ta.addEventListener('input', function () { drAutoGrow(ta); });
                });

                root.querySelectorAll('.dr-input').forEach(function (input) {
                    // Auto-save while typing (debounced) — saves without the user pressing anything.
                    input.addEventListener('input', function () { scheduleDailySave(input); });
                    // Blur flushes any pending debounced save immediately.
                    input.addEventListener('blur', function () {
                        var key = input.getAttribute('data-field') + '|' + input.getAttribute('data-date');
                        clearTimeout(_drSaveTimers[key]);
                        commitDailySave(input);
                    });
                    // Enter commits without losing focus, so the user can keep tabbing.
                    input.addEventListener('keydown', function (e) {
                        // Multi-line content boxes keep Enter for newlines; they
                        // still auto-save via the debounced input + blur handlers.
                        if (e.key === 'Enter' && input.tagName !== 'TEXTAREA') {
                            e.preventDefault();
                            var key = input.getAttribute('data-field') + '|' + input.getAttribute('data-date');
                            clearTimeout(_drSaveTimers[key]);
                            commitDailySave(input);
                        }
                    });
                });
                root.querySelectorAll('.dr-status-select').forEach(function (sel) {
                    sel.addEventListener('change', async function () {
                        var status = document.getElementById('dailySaveStatus');
                        try {
                            await saveDailyEntry(sel.getAttribute('data-date'), sel.getAttribute('data-field'), sel.value, userId);
                            if (status) { status.textContent = 'Saved'; status.className = 'kpi-status-msg saved'; }
                            sel.classList.remove('dr-status-done', 'dr-status-notdone');
                            if (sel.value === 'Done') sel.classList.add('dr-status-done');
                            else if (sel.value === 'Not Done') sel.classList.add('dr-status-notdone');
                            recomputeWeeklyForField(sel.getAttribute('data-field'));
                        } catch (e) {
                            console.error('Save failed', e);
                            if (status) { status.textContent = e.message || 'Save failed'; status.className = 'kpi-status-msg'; }
                        }
                    });
                });
                root.querySelectorAll('.dr-up-cell-btn').forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        var inputType = btn.getAttribute('data-input-type') || 'upload';
                        var choicesRaw = btn.getAttribute('data-choices') || '';
                        var choices = null;
                        if (choicesRaw) {
                            try { choices = JSON.parse(choicesRaw); } catch (e) { choices = null; }
                        }
                        var choiceValue = btn.getAttribute('data-choice-value') || '';
                        if (inputType === 'textarea') {
                            renderTextareaPanel(
                                btn.getAttribute('data-user'),
                                btn.getAttribute('data-field'),
                                btn.getAttribute('data-label'),
                                btn.getAttribute('data-date'),
                                btn.getAttribute('data-script-wording') === '1',
                                choices,
                                choiceValue
                            );
                        } else {
                            renderUploadPanel(
                                btn.getAttribute('data-user'),
                                btn.getAttribute('data-field'),
                                btn.getAttribute('data-label'),
                                btn.getAttribute('data-date'),
                                btn.getAttribute('data-accept'),
                                parseInt(btn.getAttribute('data-max-mb'), 10) || 10,
                                choices,
                                choiceValue
                            );
                        }
                    });
                });
            }

            if (videoGroupHtml) wireVideoHandoffCells(dailyContentEl);
        } catch (e) {
            console.error('renderDailyReports failed', e);
            var dailyContentEl = document.getElementById('dailyReportContent');
            if (dailyContentEl) {
                dailyContentEl.innerHTML = '<div class="kpi-status-msg">Unable to load daily report: ' + escapeHtml(e.message || 'Request failed') + '</div>';
            } else {
                root.innerHTML = '<div class="kpi-status-msg">Unable to load daily report: ' + escapeHtml(e.message || 'Request failed') + '</div>';
            }
        }
    }

    async function ensureWeekLoaded(wk, userIdOverride) {
        var kc = getKpiConfig();
        var uid = userIdOverride || (getDailyConfig().userId) || (kc.userIds && kc.userIds[0]) || config.userId;
        var cacheKey = wk + ':' + uid;
        if (weekCache[cacheKey]) return;
        try {
            var data = await requestJson('/api/kpi?week_key=' + encodeURIComponent(wk) + '&user_id=' + encodeURIComponent(uid));
            weekCache[cacheKey] = data.data || { entries: {}, targets: {}, ceoNote: '' };
        } catch (err) {
            console.error('ensureWeekLoaded failed', err);
            weekCache[cacheKey] = { entries: {}, targets: {}, ceoNote: '' };
        }
    }

    function personWeekData(wk, userIdOverride) {
        var kc = getKpiConfig();
        var uid = userIdOverride || (kc.userIds && kc.userIds[0]) || (getDailyConfig().userId) || 5;
        var cacheKey = wk + ':' + uid;
        return weekCache[cacheKey] || { entries: {}, targets: {} };
    }

    async function saveKpiTarget(fieldKey, value, userIdOverride) {
        var kc = getKpiConfig();
        var uid = userIdOverride || (kc.userIds && kc.userIds[0]) || (getDailyConfig().userId) || 5;
        var wk = weekKey(currentKpiWeekStart);
        await requestJson('/api/kpi', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'save_target', userId: uid, weekKey: wk, fieldKey: fieldKey, value: value })
        });
        delete weekCache[wk + ':' + uid];
        await ensureWeekLoaded(wk, uid);
    }

    function buildKpiCard(f, current, targets, previous, editable, canManage) {
        var defId = f.id ? ' data-def-id="' + escapeHtml(String(f.id)) + '"' : '';
        var actual = current[f.key] || '';
        var target = targets[f.key] || '';
        var prev = previous[f.key] || '';
        var actualNum = parseFloat(stripCommas(actual));
        var targetNum = parseFloat(stripCommas(target));
        var hasActual = actual !== '' && !isNaN(actualNum);
        var hasTarget = target !== '' && !isNaN(targetNum);
        var status = 'neutral';
        var badgeText = 'No target';
        var pct = 0;
        if (hasActual && hasTarget && targetNum > 0) {
            pct = Math.min(Math.round((actualNum / targetNum) * 100), 100);
            if (actualNum >= targetNum) { status = 'met'; badgeText = 'Target met'; }
            else { status = 'not-met'; badgeText = Math.round((actualNum / targetNum) * 100) + '% of target'; }
        } else if (hasActual && !hasTarget) { pct = 100; badgeText = 'No target set'; }
        var targetInputHtml = editable !== false
            ? '<div class="kpi-input-row"><div class="kpi-field"><div class="kpi-input-label">Target</div><input class="input kpi-input target-input" data-field="' + escapeHtml(f.key) + '" value="' + escapeHtml(target) + '" placeholder="Set target"></div></div>'
            : '';
        var manageBtns = (canManage && f.id) ? '<div class="kpi-card-actions"><button type="button" class="kpi-btn-edit" data-id="' + escapeHtml(String(f.id)) + '" data-label="' + escapeHtml(f.label) + '" title="Edit">&#9998;</button><button type="button" class="kpi-btn-del" data-id="' + escapeHtml(String(f.id)) + '" title="Delete">&#128465;</button></div>' : '';
        return '<article class="card kpi-card"' + defId + '><div class="kpi-label-row"><div class="kpi-label">' + escapeHtml(f.label) + '</div>' + manageBtns + '</div><div class="kpi-actual-display' + (hasActual ? '' : ' empty') + '">' + (hasActual ? escapeHtml(actual) : '—') + '</div><div class="kpi-target-row">' + (hasTarget ? 'Target: ' + escapeHtml(target) : '') + '<span class="badge kpi-badge ' + status + '">' + badgeText + '</span></div><div class="kpi-bar-wrap"><div class="kpi-bar ' + status + '" style="width:' + pct + '%"></div></div><div class="kpi-prev-value">Prev: ' + escapeHtml(prev || '—') + '</div><div class="kpi-source-note">Actuals auto-computed from Daily Reports</div>' + targetInputHtml + '</article>';
    }

    async function loadKpiWeekData(targetWeek) {
        var res = await requestJson('/api/kpi?week_key=' + encodeURIComponent(targetWeek));
        return res.items || {};
    }

    async function renderKpis() {
        var root = document.getElementById('kpiView');
        if (!root) return;
        var kc = getKpiConfig();
        var canManage = !!kc.canManage;
        var canSetTarget = 'canSetTarget' in kc ? !!kc.canSetTarget : kc.editable !== false;
        var editable = kc.editable !== false;
        var userIds = kc.userIds;
        var isMultiPerson = userIds && userIds.length > 1;
        var byPerson = getKpiGroupsByPerson();
        var effectiveUserId = isMultiPerson ? (activePerson || (userIds && userIds[0])) : (userIds && userIds[0]);
        var groups = (byPerson[effectiveUserId] && byPerson[effectiveUserId].groups) ? byPerson[effectiveUserId].groups : getKpiGroups();

        var wk = weekKey(currentKpiWeekStart);
        var pwk = weekKey(addDays(currentKpiWeekStart, -7));
        var kpiWeekEnd = addDays(currentKpiWeekStart, 6);
        var isCurrentWeek = weekKey(startOfWeek(new Date())) === wk;

        await ensureWeekLoaded(wk, effectiveUserId);
        var summaryPayload = await Promise.all([
            fetchWeeklyDailySummary(wk, effectiveUserId),
            fetchWeeklyDailySummary(pwk, effectiveUserId)
        ]).catch(function () { return [{}, {}]; });
        var current = (summaryPayload[0] && summaryPayload[0].summary) || {};
        var targets = personWeekData(wk, effectiveUserId).targets || {};
        var previous = (summaryPayload[1] && summaryPayload[1].summary) || {};

        var title = 'KPI Tracker';
        var personSelectorHtml = '';
        if (isMultiPerson && Object.keys(byPerson).length) {
            var isCooView = userIds && userIds.length > 1;
            if (isCooView) {
                personSelectorHtml = '<div class="kpi-person-strip-wrap"><div class="kpi-person-strip">' +
                    Object.keys(byPerson).map(function (pid) {
                        var p = byPerson[pid];
                        var active = String(pid) === String(effectiveUserId) ? ' active' : '';
                        return '<button type="button" class="kpi-person-chip' + active + '" data-id="' + escapeHtml(pid) + '">'
                            + escapeHtml(p.name || pid)
                            + '</button>';
                    }).join('') + '</div></div>';
            } else {
                personSelectorHtml = '<div class="kpi-person-pills">' + Object.keys(byPerson).map(function (pid) {
                    var p = byPerson[pid];
                    var active = String(pid) === String(effectiveUserId) ? ' active' : '';
                    var projectName = p.projectName || p.name || pid;
                    var personName = p.name || '';
                    var reportingManager = p.reportingManager ? (' <span class="kpi-pill-manager">→ ' + escapeHtml(p.reportingManager) + '</span>') : '';
                    return '<button type="button" class="kpi-person-pill' + active + '" data-id="' + escapeHtml(pid) + '">'
                        + '<span class="kpi-pill-project">' + escapeHtml(projectName) + '</span>'
                        + '<span class="kpi-pill-person">' + escapeHtml(personName) + '</span>'
                        + reportingManager
                        + '</button>';
                }).join('') + '</div>';
            }
        }

        var weekNavHtml = '<div class="kpi-week-nav">' +
            '<button class="mtg-nav-btn" id="kpiPrevWeek">&#8592;</button>' +
            '<div class="kpi-week-pill' + (isCurrentWeek ? '' : ' kpi-week-past') + '">' +
            escapeHtml(formatDate(currentKpiWeekStart)) + ' — ' + escapeHtml(formatDate(kpiWeekEnd)) +
            (isCurrentWeek ? ' <span class="kpi-week-badge">This week</span>' : '') +
            '</div>' +
            '<button class="mtg-nav-btn" id="kpiNextWeek"' + (isCurrentWeek ? ' disabled' : '') + '>&#8594;</button>' +
            (!isCurrentWeek ? '<button class="kpi-today-btn" id="kpiToday">Today</button>' : '') +
            '</div>';

        var groupsHtml = '';
        var addGroupBtn = '';
        if (groups.length) {
            groupsHtml = groups.map(function (group) {
                var cards = group.fields.map(function (f) { return buildKpiCard(f, current, targets, previous, canSetTarget, canManage); }).join('');
                var addBtn = canManage ? '<button type="button" class="kpi-add-btn" data-group="' + escapeHtml(group.name) + '">+ Add KPI</button>' : '';
                return '<div class="kpi-group-block" data-group="' + escapeHtml(group.name) + '"><div class="kpi-group-title">' + escapeHtml(group.name) + '</div><div class="kpi-grid">' + cards + '</div>' + addBtn + '</div>';
            }).join('');
            addGroupBtn = canManage ? '<div class="kpi-add-group-wrap"><button type="button" class="kpi-add-group-btn">+ Add Group</button></div>' : '';
        } else {
            groupsHtml = '<div class="kpi-empty-state"><p>No KPIs assigned yet.</p>' +
                (canManage ? '<div class="kpi-empty-actions"><button type="button" class="kpi-add-group-btn">+ Add Group</button></div>' : '') + '</div>';
        }

        root.innerHTML = '<div class="kpi-wrap">' + personSelectorHtml +
            '<div class="kpi-header"><h2>' + escapeHtml(title) + '</h2>' + weekNavHtml + '</div>' +
            '<div class="kpi-status-msg" id="kpiStatus">Weekly actuals are auto-calculated from Daily Reports. Targets auto-save on blur.</div>' +
            groupsHtml + addGroupBtn + '</div>';

        var kpiPrevBtn = document.getElementById('kpiPrevWeek');
        if (kpiPrevBtn) kpiPrevBtn.addEventListener('click', function () { currentKpiWeekStart = addDays(currentKpiWeekStart, -7); renderKpis(); syncPortalHash(); });
        var kpiNextBtn = document.getElementById('kpiNextWeek');
        if (kpiNextBtn) kpiNextBtn.addEventListener('click', function () { if (!isCurrentWeek) { currentKpiWeekStart = addDays(currentKpiWeekStart, 7); renderKpis(); syncPortalHash(); } });
        var kpiTodayBtn = document.getElementById('kpiToday');
        if (kpiTodayBtn) kpiTodayBtn.addEventListener('click', function () { currentKpiWeekStart = startOfWeek(new Date()); renderKpis(); syncPortalHash(); });

        root.querySelectorAll('.kpi-person-pill').forEach(function (btn) {
            btn.onclick = function () {
                activePerson = btn.getAttribute('data-id');
                renderKpis();
                syncPortalHash();
            };
        });
        root.querySelectorAll('.kpi-person-chip').forEach(function (chip) {
            chip.onclick = function () {
                activePerson = chip.getAttribute('data-id');
                renderKpis();
                syncPortalHash();
            };
        });

        root.querySelectorAll('.target-input').forEach(function (input) {
            if (!canSetTarget) { input.disabled = true; return; }
            input.addEventListener('blur', async function () {
                var status = document.getElementById('kpiStatus');
                try {
                    await saveKpiTarget(input.getAttribute('data-field'), input.value, effectiveUserId);
                    if (status) { status.textContent = 'Saved'; status.className = 'kpi-status-msg saved'; }
                    renderKpis();
                } catch (e) {
                    console.error('Save failed', e);
                    if (status) { status.textContent = e.message || 'Save failed'; status.className = 'kpi-status-msg'; }
                }
            });
        });

        if (canManage) {
            root.querySelectorAll('.kpi-btn-edit').forEach(function (btn) {
                btn.onclick = function () {
                    var id = btn.getAttribute('data-id');
                    var label = btn.getAttribute('data-label') || '';
                    showKpiModal({ type: 'edit_label', title: 'Edit KPI Label', label: label }, function (data) {
                        requestJson('/api/kpi-definitions', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'update', id: id, fieldLabel: data.label }) })
                            .then(function () { return refreshKpiDefinitions(effectiveUserId, userIds); }).then(renderKpis)
                            .catch(function (e) { alert(e.message || 'Update failed'); });
                    });
                };
            });
            root.querySelectorAll('.kpi-btn-del').forEach(function (btn) {
                btn.onclick = function () {
                    if (!confirm('Delete this KPI? Historical data will remain.')) return;
                    var id = btn.getAttribute('data-id');
                    requestJson('/api/kpi-definitions', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'delete', id: id }) })
                        .then(function () { return refreshKpiDefinitions(effectiveUserId, userIds); }).then(renderKpis)
                        .catch(function (e) { alert(e.message || 'Delete failed'); });
                };
            });
            root.querySelectorAll('.kpi-add-btn').forEach(function (btn) {
                btn.onclick = function () {
                    var groupName = btn.getAttribute('data-group') || 'Metrics';
                    var payload = { action: 'create', groupName: groupName, fieldKey: '', fieldLabel: '', aggregation: 'sum', userId: effectiveUserId };
                    showKpiModal({ type: 'add_kpi', title: 'Add KPI', groupName: groupName }, function (data) {
                        payload.fieldKey = data.fieldKey; payload.fieldLabel = data.label; payload.aggregation = data.aggregation || 'sum';
                        requestJson('/api/kpi-definitions', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) })
                            .then(function () { return refreshKpiDefinitions(effectiveUserId, userIds); }).then(renderKpis)
                            .catch(function (e) { alert(e.message || 'Create failed'); });
                    });
                };
            });
            var addGroupBtnEl = root.querySelector('.kpi-add-group-btn');
            if (addGroupBtnEl) addGroupBtnEl.onclick = function () {
                var payload = { action: 'create_group', groupName: '', userId: effectiveUserId };
                showKpiModal({ type: 'add_group', title: 'Add KPI Group' }, function (data) {
                    payload.groupName = data.groupName;
                    requestJson('/api/kpi-definitions', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) })
                        .then(function () { return refreshKpiDefinitions(effectiveUserId, userIds); }).then(renderKpis)
                        .catch(function (e) { alert(e.message || 'Create failed'); });
                });
            };
        }
    }

    async function renderMarketingKpis() {
        var root = document.getElementById('mkpiView');
        if (!root) return;
        var kc = getKpiConfig();
        var canManage = !!kc.canManage;
        var canSetTarget = 'canSetTarget' in kc ? !!kc.canSetTarget : kc.editable !== false;
        var marketingKpiPeople = getMarketingKpiPeople();
        if (!marketingKpiPeople.length) {
            root.innerHTML = '<div class="kpi-wrap"><div class="kpi-empty-state"><p>No KPI people configured yet.</p></div></div>';
            return;
        }

        var effectiveUserId = activeKpiPerson || String(marketingKpiPeople[0].id);
        var person = marketingKpiPeople.find(function (p) { return String(p.id) === String(effectiveUserId); }) || marketingKpiPeople[0];
        effectiveUserId = String(person.id);

        var wk = weekKey(currentKpiWeekStart);
        var prevWk = weekKey(addDays(currentKpiWeekStart, -7));
        var mkpiWeekEnd = addDays(currentKpiWeekStart, 6);
        var mkpiIsCurrentWeek = weekKey(startOfWeek(new Date())) === wk;

        await ensureWeekLoaded(wk, effectiveUserId);
        var summaryPayload = await Promise.all([
            fetchWeeklyDailySummary(wk, effectiveUserId),
            fetchWeeklyDailySummary(prevWk, effectiveUserId)
        ]).catch(function () { return [{}, {}]; });
        var current = (summaryPayload[0] && summaryPayload[0].summary) || {};
        var previous = (summaryPayload[1] && summaryPayload[1].summary) || {};
        var personWeek = personWeekData(wk, effectiveUserId);
        var targets = personWeek.targets || {};

        var grouped = {};
        (person.fields || []).forEach(function (f) {
            var groupName = (f.group && String(f.group).trim()) ? String(f.group).trim() : 'Metrics';
            if (!grouped[groupName]) grouped[groupName] = [];
            grouped[groupName].push(f);
        });
        var groupNames = Object.keys(grouped);

        var personTableHtml = '<div class="kpi-person-table-wrap"><table class="kpi-person-table"><thead><tr><th>Name</th><th>Role</th><th>Project</th><th>Reporting Manager</th></tr></thead><tbody>' +
            marketingKpiPeople.map(function (p) {
                var active = String(p.id) === String(effectiveUserId) ? ' active-row' : '';
                var project = p.project || '—';
                var reportingManager = p.reportingManager || '—';
                return '<tr class="kpi-person-row' + active + '" data-id="' + escapeHtml(String(p.id)) + '"><td>' + escapeHtml(p.name || '') + '</td><td>' + escapeHtml(p.role || '') + '</td><td>' + escapeHtml(project) + '</td><td>' + escapeHtml(reportingManager) + '</td></tr>';
            }).join('') +
            '</tbody></table></div>';

        var mkpiWeekNav = '<div class="kpi-week-nav">' +
            '<button class="mtg-nav-btn" id="mkpiPrevWeek">&#8592;</button>' +
            '<div class="kpi-week-pill' + (mkpiIsCurrentWeek ? '' : ' kpi-week-past') + '">' +
            escapeHtml(formatDate(currentKpiWeekStart)) + ' — ' + escapeHtml(formatDate(mkpiWeekEnd)) +
            (mkpiIsCurrentWeek ? ' <span class="kpi-week-badge">This week</span>' : '') +
            '</div>' +
            '<button class="mtg-nav-btn" id="mkpiNextWeek"' + (mkpiIsCurrentWeek ? ' disabled' : '') + '>&#8594;</button>' +
            (!mkpiIsCurrentWeek ? '<button class="kpi-today-btn" id="mkpiToday">Today</button>' : '') +
            '</div>';

        var groupsHtml = groupNames.length ? groupNames.map(function (groupName) {
            var cards = grouped[groupName].map(function (f) {
                return buildKpiCard(f, current, targets, previous, canSetTarget, canManage);
            }).join('');
            var addBtn = canManage ? '<button type="button" class="kpi-add-btn kpi-add-field-mkpi-btn" data-group="' + escapeHtml(groupName) + '" data-person-id="' + escapeHtml(effectiveUserId) + '">+ Add KPI</button>' : '';
            return '<div class="kpi-group-block" data-group="' + escapeHtml(groupName) + '"><div class="kpi-group-title">' + escapeHtml(groupName) + '</div><div class="kpi-grid">' + cards + '</div>' + addBtn + '</div>';
        }).join('') : '<div class="kpi-empty-state"><p>No KPIs assigned yet.</p></div>';

        var addPersonHtml = canManage ? '<div class="kpi-add-group-wrap"><button type="button" class="kpi-add-person-mkpi-btn">+ Add Person</button></div>' : '';
        var noteHtml = '<div class="note-box"><h4>CEO Note</h4><textarea id="ceoNoteInput" rows="4" placeholder="Add weekly feedback...">' + escapeHtml(personWeek.ceoNote || '') + '</textarea><button id="saveCeoNoteBtn">Save CEO Note</button></div>';

        root.innerHTML = '<div class="kpi-wrap">' + personTableHtml +
            '<div class="kpi-header"><h2>KPI Tracker</h2>' + mkpiWeekNav + '</div>' +
            '<div class="kpi-status-msg" id="mkpiStatus">Weekly actuals are auto-calculated from Daily Reports. Targets auto-save on blur.</div>' +
            groupsHtml + addPersonHtml + noteHtml + '</div>';

        var mkpiPrevBtn = document.getElementById('mkpiPrevWeek');
        if (mkpiPrevBtn) mkpiPrevBtn.addEventListener('click', function () { currentKpiWeekStart = addDays(currentKpiWeekStart, -7); renderMarketingKpis(); syncPortalHash(); });
        var mkpiNextBtn = document.getElementById('mkpiNextWeek');
        if (mkpiNextBtn) mkpiNextBtn.addEventListener('click', function () { if (!mkpiIsCurrentWeek) { currentKpiWeekStart = addDays(currentKpiWeekStart, 7); renderMarketingKpis(); syncPortalHash(); } });
        var mkpiTodayBtn = document.getElementById('mkpiToday');
        if (mkpiTodayBtn) mkpiTodayBtn.addEventListener('click', function () { currentKpiWeekStart = startOfWeek(new Date()); renderMarketingKpis(); syncPortalHash(); });

        root.querySelectorAll('.kpi-person-row').forEach(function (row) {
            row.onclick = function () {
                activeKpiPerson = row.getAttribute('data-id');
                renderMarketingKpis();
                syncPortalHash();
            };
        });

        root.querySelectorAll('.target-input').forEach(function (input) {
            if (!canSetTarget) { input.disabled = true; return; }
            input.addEventListener('blur', async function () {
                var status = document.getElementById('mkpiStatus');
                try {
                    await saveKpiTarget(input.getAttribute('data-field'), input.value, effectiveUserId);
                    if (status) { status.textContent = 'Saved'; status.className = 'kpi-status-msg saved'; }
                    renderMarketingKpis();
                } catch (e) {
                    if (status) { status.textContent = e.message || 'Save failed'; status.className = 'kpi-status-msg'; }
                }
            });
        });

        var saveBtn = document.getElementById('saveCeoNoteBtn');
        if (saveBtn) saveBtn.onclick = async function () {
            var note = document.getElementById('ceoNoteInput');
            try {
                await requestJson('/api/kpi', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'save_ceo_note', userId: effectiveUserId, weekKey: wk, note: note ? note.value : '' })
                });
                saveBtn.textContent = 'Saved';
                setTimeout(function () { saveBtn.textContent = 'Save CEO Note'; }, 1000);
            } catch (e) {
                saveBtn.textContent = e.message || 'Save failed';
            }
        };

        if (canManage) {
            root.querySelectorAll('.kpi-btn-edit').forEach(function (btn) {
                btn.onclick = function () {
                    var id = btn.getAttribute('data-id');
                    var label = btn.getAttribute('data-label') || '';
                    showKpiModal({ type: 'edit_label', title: 'Edit KPI Label', label: label }, function (data) {
                        requestJson('/api/kpi-definitions', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'update', id: id, fieldLabel: data.label }) })
                            .then(function () { return refreshKpiDefinitions(effectiveUserId, kc.userIds); }).then(renderMarketingKpis)
                            .catch(function (e) { alert(e.message || 'Update failed'); });
                    });
                };
            });
            root.querySelectorAll('.kpi-btn-del').forEach(function (btn) {
                btn.onclick = function () {
                    if (!confirm('Delete this KPI?')) return;
                    var id = btn.getAttribute('data-id');
                    requestJson('/api/kpi-definitions', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'delete', id: id }) })
                        .then(function () { return refreshKpiDefinitions(effectiveUserId, kc.userIds); }).then(renderMarketingKpis)
                        .catch(function (e) { alert(e.message || 'Delete failed'); });
                };
            });
            root.querySelectorAll('.kpi-add-field-mkpi-btn').forEach(function (btn) {
                btn.onclick = function () {
                    var pid = btn.getAttribute('data-person-id') || effectiveUserId;
                    var groupName = btn.getAttribute('data-group') || 'Metrics';
                    showKpiModal({ type: 'add_kpi', title: 'Add KPI', groupName: groupName }, function (data) {
                        requestJson('/api/kpi-definitions', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ action: 'create', userId: pid, groupName: groupName, fieldKey: data.fieldKey, fieldLabel: data.label, aggregation: data.aggregation || 'sum' })
                        })
                            .then(function () { return refreshKpiDefinitions(pid, kc.userIds); }).then(renderMarketingKpis)
                            .catch(function (e) { alert(e.message || 'Create failed'); });
                    });
                };
            });
            var addPersonMkpi = root.querySelector('.kpi-add-person-mkpi-btn');
            if (addPersonMkpi) addPersonMkpi.onclick = function () {
                showKpiModal({ type: 'add_person', title: 'Add Person' }, function (data) {
                    requestJson('/api/kpi-definitions', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'create_person', userId: parseInt(data.userId, 10) }) })
                        .then(function () { return refreshKpiDefinitions(null, kc.userIds); }).then(renderMarketingKpis)
                        .catch(function (e) { alert(e.message || 'Create failed'); });
                });
            };
        }
    }

    /* ── Tickets ── */

    var ticketsFilterStatus = '';
    var ticketsFilterCategory = '';

    async function renderTickets() {
        var root = document.getElementById('ticketsView');
        if (!root) return;

        root.innerHTML = '<div class="tkt-wrap"><div class="kpi-status-msg">Loading tickets...</div></div>';

        try {
            var url = '/api/tickets';
            var params = [];
            if (ticketsFilterStatus) params.push('status=' + encodeURIComponent(ticketsFilterStatus));
            if (ticketsFilterCategory) params.push('category=' + encodeURIComponent(ticketsFilterCategory));
            if (params.length) url += '?' + params.join('&');

            var payload = await requestJson(url);
            var tickets = payload.tickets || [];
            var userId = config.userId;

            var statusColors = { open: '#ef4444', in_progress: '#f59e0b', resolved: '#22c55e', closed: '#6b7280' };
            var priorityColors = { high: '#ef4444', medium: '#f59e0b', low: '#3b82f6' };

            var filtersHtml = '<div class="tkt-filters">' +
                '<select class="tkt-filter-select" id="tktStatusFilter"><option value="">All Statuses</option><option value="open"' + (ticketsFilterStatus === 'open' ? ' selected' : '') + '>Open</option><option value="in_progress"' + (ticketsFilterStatus === 'in_progress' ? ' selected' : '') + '>In Progress</option><option value="resolved"' + (ticketsFilterStatus === 'resolved' ? ' selected' : '') + '>Resolved</option><option value="closed"' + (ticketsFilterStatus === 'closed' ? ' selected' : '') + '>Closed</option></select>' +
                '<select class="tkt-filter-select" id="tktCategoryFilter"><option value="">All Categories</option><option value="technical"' + (ticketsFilterCategory === 'technical' ? ' selected' : '') + '>Technical</option><option value="ai"' + (ticketsFilterCategory === 'ai' ? ' selected' : '') + '>AI</option></select>' +
                '<button type="button" class="btn btn-primary tkt-new-btn" id="tktNewBtn">+ New Ticket</button>' +
                '</div>';

            var listHtml = '';
            if (tickets.length === 0) {
                listHtml = '<div class="tkt-empty">No tickets found.</div>';
            } else {
                listHtml = '<div class="tkt-list">' + tickets.map(function (t) {
                    var sc = statusColors[t.status] || '#6b7280';
                    var pc = priorityColors[t.priority] || '#6b7280';
                    var statusLabel = (t.status || '').replace(/_/g, ' ');
                    var isAssignee = t.assigneeId === userId;
                    var actionsHtml = '';
                    if (isAssignee || config.portal === 'tech_lead' || config.portal === 'ceo' || config.portal === 'coo') {
                        if (t.status === 'open') actionsHtml = '<button class="btn btn-sm tkt-action-btn" data-id="' + t.id + '" data-status="in_progress">Start</button>';
                        else if (t.status === 'in_progress') actionsHtml = '<button class="btn btn-sm tkt-action-btn" data-id="' + t.id + '" data-status="resolved">Resolve</button>';
                        else if (t.status === 'resolved') actionsHtml = '<button class="btn btn-sm tkt-action-btn" data-id="' + t.id + '" data-status="closed">Close</button>';
                    }
                    return '<div class="card tkt-card">' +
                        '<div class="tkt-card-header">' +
                        '<h4 class="tkt-card-title">' + escapeHtml(t.title) + '</h4>' +
                        '<span class="badge tkt-badge" style="background:' + sc + '">' + escapeHtml(statusLabel) + '</span>' +
                        '</div>' +
                        (t.description ? '<p class="tkt-card-desc">' + escapeHtml(t.description).replace(/\\n/g, '<br>') + '</p>' : '') +
                        '<div class="tkt-card-meta">' +
                        '<span class="badge tkt-badge tkt-badge-outline" style="border-color:' + pc + ';color:' + pc + '">' + escapeHtml(t.priority) + '</span>' +
                        '<span class="badge tkt-badge tkt-badge-outline">' + escapeHtml(t.category) + '</span>' +
                        '<span class="tkt-meta-text">By: ' + escapeHtml(t.reporterName) + '</span>' +
                        '<span class="tkt-meta-text">Assigned: ' + escapeHtml(t.assigneeName) + '</span>' +
                        (t.createdAt ? '<span class="tkt-meta-text">' + new Date(t.createdAt).toLocaleDateString('en-IN', { timeZone: 'Asia/Kolkata', day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }) + '</span>' : '') +
                        '</div>' +
                        (actionsHtml ? '<div class="tkt-card-actions">' + actionsHtml + '</div>' : '') +
                        '</div>';
                }).join('') + '</div>';
            }

            root.innerHTML = '<div class="tkt-wrap">' +
                '<div class="tkt-header"><h2 class="tkt-title">Tickets</h2></div>' +
                filtersHtml + listHtml + '</div>';

            document.getElementById('tktStatusFilter').onchange = function () {
                ticketsFilterStatus = this.value;
                renderTickets();
            };
            document.getElementById('tktCategoryFilter').onchange = function () {
                ticketsFilterCategory = this.value;
                renderTickets();
            };

            var newBtn = document.getElementById('tktNewBtn');
            if (newBtn) {
                newBtn.onclick = function () { showNewTicketModal(); };
            }

            root.querySelectorAll('.tkt-action-btn').forEach(function (btn) {
                btn.onclick = async function () {
                    var id = btn.getAttribute('data-id');
                    var status = btn.getAttribute('data-status');
                    btn.disabled = true;
                    btn.textContent = 'Updating...';
                    try {
                        await requestJson('/api/tickets/' + id, {
                            method: 'PUT',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ status: status })
                        });
                        renderTickets();
                    } catch (err) {
                        btn.disabled = false;
                        btn.textContent = 'Retry';
                        console.error('Ticket update failed', err);
                    }
                };
            });

        } catch (err) {
            console.error('Tickets load failed', err);
            root.innerHTML = '<div class="tkt-wrap"><div class="tkt-header"><h2 class="tkt-title">Tickets</h2></div><div class="tkt-empty">Unable to load tickets.</div></div>';
        }
    }

    function showNewTicketModal() {
        var overlay = document.createElement('div');
        overlay.className = 'modal-overlay';
        overlay.innerHTML = '<div class="modal-content">' +
            '<h3 class="modal-title">New Ticket</h3>' +
            '<form id="tktNewForm" class="change-password-form">' +
            '<label for="tktTitle">Title</label>' +
            '<input type="text" id="tktTitle" required placeholder="Brief summary of the issue">' +
            '<label for="tktDescription">Description</label>' +
            '<textarea id="tktDescription" rows="4" placeholder="Detailed description (optional)" data-grammar-fix></textarea>' +
            '<label for="tktCategory">Category</label>' +
            '<select id="tktCategory" required><option value="technical">Technical</option><option value="ai">AI</option></select>' +
            '<label for="tktPriority">Priority</label>' +
            '<select id="tktPriority" required><option value="medium">Medium</option><option value="high">High</option><option value="low">Low</option></select>' +
            '<label for="tktAssignee">Assignee</label>' +
            '<select id="tktAssignee"><option value="">Auto (based on category)</option></select>' +
            '<p id="tktFormStatus" class="change-password-status"></p>' +
            '<div class="modal-actions">' +
            '<button type="button" class="btn btn-outline" id="tktCancelBtn">Cancel</button>' +
            '<button type="submit" class="btn btn-primary" id="tktSubmitBtn">Create Ticket</button>' +
            '</div></form></div>';
        document.body.appendChild(overlay);

        function close() { overlay.remove(); }
        overlay.addEventListener('click', function (e) { if (e.target === overlay) close(); });
        document.getElementById('tktCancelBtn').onclick = close;

        var assigneeSelect = document.getElementById('tktAssignee');
        requestJson('/api/tickets/assignees').then(function (payload) {
            var users = (payload && payload.users) || [];
            users.forEach(function (u) {
                var opt = document.createElement('option');
                opt.value = String(u.id);
                opt.textContent = u.name;
                assigneeSelect.appendChild(opt);
            });
        }).catch(function (err) {
            console.error('Failed to load ticket assignees', err);
        });

        document.getElementById('tktNewForm').onsubmit = async function (e) {
            e.preventDefault();
            var statusEl = document.getElementById('tktFormStatus');
            var submitBtn = document.getElementById('tktSubmitBtn');
            submitBtn.disabled = true;
            submitBtn.textContent = 'Creating...';
            statusEl.textContent = '';
            try {
                var body = {
                    title: document.getElementById('tktTitle').value.trim(),
                    description: document.getElementById('tktDescription').value.trim(),
                    category: document.getElementById('tktCategory').value,
                    priority: document.getElementById('tktPriority').value
                };
                var assigneeVal = assigneeSelect.value;
                if (assigneeVal) body.assignee_id = parseInt(assigneeVal, 10);
                await requestJson('/api/tickets', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(body)
                });
                close();
                renderTickets();
            } catch (err) {
                statusEl.textContent = err.message || 'Failed to create ticket';
                statusEl.style.color = '#ef4444';
                submitBtn.disabled = false;
                submitBtn.textContent = 'Create Ticket';
            }
        };
    }

    /* ── KRAs (CEO sees team, others see themselves) ── */
    // Preview mode: non-CEO users see preview cards until team has logged
    // enough data. CEO always sees real scores regardless of this flag.
    var MS_PREVIEW_MODE = false;
    var MS_USER_CACHE = null;
    var MS_SELECTED_USER_ID = null;
    var MS_SELF_USER = null;
    var MS_IS_CEO_MODE = false;

    function msScoreClass(score) {
        if (score == null) return 'ms-score--skip';
        if (score >= 4.0) return 'ms-score--good';
        if (score >= 3.0) return 'ms-score--ok';
        return 'ms-score--bad';
    }

    function msFormatScore(score) {
        return score == null ? '—' : score.toFixed(1);
    }

    function msStarsHtml(score) {
        if (score == null) return '<span class="ms-stars ms-stars--empty">★★★★★</span>';
        var rounded = Math.round(score);
        var html = '';
        for (var i = 1; i <= 5; i++) {
            html += '<span class="ms-star' + (i <= rounded ? ' ms-star--on' : '') + '">★</span>';
        }
        return '<span class="ms-stars">' + html + '</span>';
    }

    // KRA weights — sum to 1.0
    var MS_KRA_WEIGHTS = { discipline: 0.34, deliverables: 0.33, manager_review: 0.33 };
    var MS_KRA_ORDER = ['discipline', 'deliverables', 'manager_review'];

    var MS_KRA_META = {
        discipline: {
            label: 'Discipline',
            icon: '🎯',
            tagline: 'Did you follow the process?',
            signals: 'Sign-in/off · Daily KPI reports · Meeting notes · Task check-ins'
        },
        deliverables: {
            label: 'Deliverables',
            icon: '🚀',
            tagline: 'Manager rating',
            signals: 'Weekly manager review — Deliverables'
        },
        manager_review: {
            label: 'Manager Review',
            icon: '📋',
            tagline: 'Quality of Work',
            signals: 'Weekly manager review — Quality of Work'
        }
    };

    function msComposite(kras) {
        var total = 0;
        MS_KRA_ORDER.forEach(function (k) {
            total += (kras[k] || 0) * MS_KRA_WEIGHTS[k];
        });
        return Math.round(total * 10) / 10;
    }

    function msLoadingShell(monthLabel) {
        return '<div class="ms-wrap">' +
            '<div class="ms-header">' +
                '<div class="ms-header-left">' +
                    '<h2 class="ms-title">Team KRAs</h2>' +
                    '<div class="ms-sub">Loading ' + escapeHtml(monthLabel || 'this month') + '…</div>' +
                '</div>' +
            '</div>' +
            '<div class="ms-section"><div class="ms-kra-cards">' +
                '<div class="ms-kra-card" style="opacity:.4"><div class="ms-kra-head"><span class="ms-kra-icon">🎯</span><div class="ms-kra-name">Work Discipline</div></div><div class="ms-kra-score">—<span>/5</span></div></div>' +
                '<div class="ms-kra-card" style="opacity:.4"><div class="ms-kra-head"><span class="ms-kra-icon">🚀</span><div class="ms-kra-name">Deliverables</div></div><div class="ms-kra-score">—<span>/5</span></div></div>' +
                '<div class="ms-kra-card" style="opacity:.4"><div class="ms-kra-head"><span class="ms-kra-icon">✨</span><div class="ms-kra-name">Quality of Work</div></div><div class="ms-kra-score">—<span>/5</span></div></div>' +
            '</div></div>' +
        '</div>';
    }

    function msEmptyShell(msg) {
        return '<div class="ms-wrap">' +
            '<div class="ms-header"><div class="ms-header-left"><h2 class="ms-title">Team KRAs</h2><div class="ms-sub">' + escapeHtml(msg) + '</div></div></div>' +
        '</div>';
    }

    function msOpenHowto() {
        var existing = document.getElementById('msHowtoOverlay');
        if (existing) { existing.remove(); return; }
        var overlay = document.createElement('div');
        overlay.id = 'msHowtoOverlay';
        overlay.className = 'ms-modal-overlay';
        overlay.innerHTML =
            '<div class="ms-modal" role="dialog" aria-modal="true">' +
                '<div class="ms-modal-header">' +
                    '<div>' +
                        '<div class="ms-modal-eyebrow">KRA Framework</div>' +
                        '<h3>How your scorecard is calculated</h3>' +
                    '</div>' +
                    '<button type="button" class="ms-modal-close" id="msHowtoClose" aria-label="Close">&times;</button>' +
                '</div>' +
                '<div class="ms-modal-body">' +
                    '<p class="ms-modal-intro">Your scorecard rolls up <strong>3 Key Result Areas</strong>. Each is scored 0–5 and combined into an overall monthly score.</p>' +

                    '<div class="ms-kra-row">' +
                        '<div class="ms-kra-row-icon">🎯</div>' +
                        '<div class="ms-kra-row-body">' +
                            '<div class="ms-kra-row-title">Work Discipline <span class="ms-kra-row-weight">25%</span></div>' +
                            '<div class="ms-kra-row-desc">Process hygiene — daily habits that keep the team in sync.</div>' +
                            '<div class="ms-kra-row-signals">Sign-in/off · Daily KPI reports · Meeting notes · Task check-ins</div>' +
                        '</div>' +
                    '</div>' +

                    '<div class="ms-kra-row">' +
                        '<div class="ms-kra-row-icon">🚀</div>' +
                        '<div class="ms-kra-row-body">' +
                            '<div class="ms-kra-row-title">Deliverables <span class="ms-kra-row-weight">50%</span></div>' +
                            '<div class="ms-kra-row-desc">What you shipped and whether it landed on time.</div>' +
                            '<div class="ms-kra-row-signals"><strong>All roles:</strong> Tasks · Tickets · Action items on deadline</div>' +
                            '<div class="ms-kra-row-signals"><strong>Engineering:</strong> Sprint stories closed · Releases shipped on schedule</div>' +
                            '<div class="ms-kra-row-signals"><strong>Marketing:</strong> Campaigns launched · Ad reports submitted on time</div>' +
                        '</div>' +
                    '</div>' +

                    '<div class="ms-kra-row">' +
                        '<div class="ms-kra-row-icon">✨</div>' +
                        '<div class="ms-kra-row-body">' +
                            '<div class="ms-kra-row-title">Quality of Work <span class="ms-kra-row-weight">25%</span></div>' +
                            '<div class="ms-kra-row-desc">How solid the work was — rework and escalations cost points.</div>' +
                            '<div class="ms-kra-row-signals"><strong>All roles:</strong> Ticket reopens · Blocked / escalated items</div>' +
                            '<div class="ms-kra-row-signals"><strong>Engineering:</strong> Release bugs · Sprint carry-overs</div>' +
                            '<div class="ms-kra-row-signals"><strong>Marketing:</strong> Campaign performance vs target · KPI accuracy</div>' +
                        '</div>' +
                    '</div>' +

                    '<div class="ms-formula">' +
                        '<div class="ms-formula-label">Overall score</div>' +
                        '<div class="ms-formula-expr">0.25 × Discipline  +  0.50 × Deliverables  +  0.25 × Quality</div>' +
                    '</div>' +

                    '<div class="ms-notes">' +
                        '<div class="ms-note"><span class="ms-note-icon">✓</span><div><strong>Fair scoring.</strong> Signals that don\'t apply on a given day (e.g. no meetings to own) are skipped — they don\'t count against you.</div></div>' +
                        '<div class="ms-note"><span class="ms-note-icon">✓</span><div><strong>Weekends, holidays and occasional approved leaves are not scored.</strong> However, repeated or excessive leaves will affect your score — attendance is part of discipline.</div></div>' +
                        '<div class="ms-note"><span class="ms-note-icon">✓</span><div><strong>Only weekly and monthly scores are shown.</strong> Daily scores stay private to Tessa.</div></div>' +
                        '<div class="ms-note ms-note--privacy"><span class="ms-note-icon">🔒</span><div><strong>KRA scorecards are reviewed by the CEO</strong> as part of monthly performance management.</div></div>' +
                    '</div>' +

                    '<div class="ms-legend-grid">' +
                        '<div class="ms-legend-chip"><span class="ms-legend-dot ms-legend-dot--good"></span><div><div class="ms-legend-chip-title">4.0 +</div><div class="ms-legend-chip-desc">Great</div></div></div>' +
                        '<div class="ms-legend-chip"><span class="ms-legend-dot ms-legend-dot--ok"></span><div><div class="ms-legend-chip-title">3.0 – 3.9</div><div class="ms-legend-chip-desc">Room to improve</div></div></div>' +
                        '<div class="ms-legend-chip"><span class="ms-legend-dot ms-legend-dot--bad"></span><div><div class="ms-legend-chip-title">Below 3.0</div><div class="ms-legend-chip-desc">Needs attention</div></div></div>' +
                    '</div>' +
                '</div>' +
            '</div>';
        document.body.appendChild(overlay);
        function close() { overlay.remove(); document.removeEventListener('keydown', onEsc); }
        function onEsc(e) { if (e.key === 'Escape') close(); }
        overlay.addEventListener('click', function (e) { if (e.target === overlay) close(); });
        document.getElementById('msHowtoClose').addEventListener('click', close);
        document.addEventListener('keydown', onEsc);
    }

    async function msOpenHistory(userId, selectedUser) {
        var existing = document.getElementById('msHistoryOverlay');
        if (existing) { existing.remove(); return; }

        var overlay = document.createElement('div');
        overlay.id = 'msHistoryOverlay';
        overlay.className = 'ms-modal-overlay';
        var titleName = selectedUser ? selectedUser.name : 'Your';
        overlay.innerHTML =
            '<div class="ms-modal" role="dialog" aria-modal="true">' +
                '<div class="ms-modal-header">' +
                    '<div>' +
                        '<div class="ms-modal-eyebrow">For Reference</div>' +
                        '<h3>' + escapeHtml(titleName) + ' Previous KRAs</h3>' +
                    '</div>' +
                    '<button type="button" class="ms-modal-close" id="msHistoryClose" aria-label="Close">&times;</button>' +
                '</div>' +
                '<div class="ms-modal-body" id="msHistoryBody">' +
                    '<div class="ms-loading-text">Loading previous weeks…</div>' +
                '</div>' +
            '</div>';
        document.body.appendChild(overlay);

        function close() { overlay.remove(); document.removeEventListener('keydown', onEsc); }
        function onEsc(e) { if (e.key === 'Escape') close(); }
        overlay.addEventListener('click', function (e) { if (e.target === overlay) close(); });
        document.getElementById('msHistoryClose').addEventListener('click', close);
        document.addEventListener('keydown', onEsc);

        var bodyEl = document.getElementById('msHistoryBody');
        try {
            var url = '/api/my-kras/history' + (userId ? '?user_id=' + encodeURIComponent(userId) : '');
            var resp = await requestJson(url);
            var weeks = (resp && resp.weeks) || [];
            if (!weeks.length) {
                bodyEl.innerHTML = '<div class="ms-loading-text">No previous KRA weeks yet — they\'ll show up here once a few weeks have elapsed.</div>';
                return;
            }
            var html = '<table class="ms-history-table">' +
                '<thead><tr>' +
                    '<th class="ms-hist-week">Week</th>' +
                    '<th class="ms-hist-score">Composite</th>' +
                    '<th class="ms-hist-kra">Discipline</th>' +
                    '<th class="ms-hist-kra">Deliverables</th>' +
                    '<th class="ms-hist-kra">Quality</th>' +
                '</tr></thead><tbody>';
            weeks.forEach(function (w) {
                var compCls = msScoreClass(w.composite);
                var disc = (w.kras && w.kras.discipline != null) ? w.kras.discipline.toFixed(1) : '—';
                var deliv = (w.kras && w.kras.deliverables != null) ? w.kras.deliverables.toFixed(1) : '—';
                var qual = (w.kras && w.kras.manager_review != null) ? w.kras.manager_review.toFixed(1) : '—';
                html += '<tr>' +
                    '<td class="ms-hist-week">' + escapeHtml(w.weekLabel) + '</td>' +
                    '<td class="ms-hist-score ' + compCls + '">' + msFormatScore(w.composite) + '</td>' +
                    '<td class="ms-hist-kra">' + disc + '</td>' +
                    '<td class="ms-hist-kra">' + deliv + '</td>' +
                    '<td class="ms-hist-kra">' + qual + '</td>' +
                '</tr>';
            });
            html += '</tbody></table>';
            bodyEl.innerHTML = html;
        } catch (err) {
            bodyEl.innerHTML = '<div class="ms-loading-text">' + escapeHtml((err && err.message) || 'Unable to load history.') + '</div>';
        }
    }

    function msRoleLabel(role) {
        if (!role) return '';
        return role.replace(/_/g, ' ').replace(/\b\w/g, function (c) { return c.toUpperCase(); });
    }

    function msBuildPreviewHtml(data, selectedUser) {
        var subtitle = escapeHtml(data.monthLabel);
        if (selectedUser && selectedUser.role) {
            subtitle = escapeHtml(msRoleLabel(selectedUser.role)) + ' · ' + subtitle;
        }

        var html = '';

        // Header — no score badge while in preview
        html += '<div class="ms-header">' +
            '<div class="ms-header-left">' +
                '<h2 class="ms-title">' + escapeHtml(selectedUser ? selectedUser.name : 'Team KRAs') + ' ' +
                    '<button type="button" class="ms-info-btn" id="msInfoBtn" aria-label="How KRAs are calculated" title="How KRAs are calculated">' +
                        '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>' +
                    '</button>' +
                    '<button type="button" class="ms-history-btn" id="msHistoryBtn" aria-label="View previous KRAs" title="Previous KRAs">' +
                        '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><polyline points="3 3 3 8 8 8"/><polyline points="12 7 12 12 15 14"/></svg>' +
                        '<span>Previous KRAs</span>' +
                    '</button>' +
                '</h2>' +
                '<div class="ms-sub">' + subtitle + '</div>' +
            '</div>' +
            '</div>';

        // Big preview card
        html += '<div class="ms-preview-card">' +
            '<div class="ms-preview-icon">' +
                '<svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' +
                    '<circle cx="12" cy="12" r="10"/>' +
                    '<polyline points="12 6 12 12 16 14"/>' +
                '</svg>' +
            '</div>' +
            '<div class="ms-preview-title">Scores will update next week</div>' +
            '<div class="ms-preview-desc">The team is logging their first full week of data. Real KRA scores for <strong>' + escapeHtml(selectedUser ? selectedUser.name : 'the team') + '</strong> will go live once we have a complete week of sign-ins, reports, notes, and delivery signals.</div>' +
            '<div class="ms-preview-meta">Next refresh: <strong>Monday, April 20</strong></div>' +
        '</div>';

        // Ghost KRA cards so the CEO can still see the layout
        html += '<div class="ms-section"><h3 class="ms-section-title">3 Key Result Areas <span class="ms-section-sub">preview</span></h3><div class="ms-kra-cards">';
        MS_KRA_ORDER.forEach(function (key) {
            var meta = MS_KRA_META[key];
            var weightPct = Math.round(MS_KRA_WEIGHTS[key] * 100);
            html += '<div class="ms-kra-card ms-kra-card--ghost">' +
                '<div class="ms-kra-head">' +
                    '<span class="ms-kra-icon">' + meta.icon + '</span>' +
                    '<div class="ms-kra-name">' + escapeHtml(meta.label) + '</div>' +
                    '<div class="ms-kra-weight">Weight ' + weightPct + '%</div>' +
                '</div>' +
                '<div class="ms-kra-score-row">' + msStarsHtml(null) + '<span class="ms-kra-score-num">—<span>/5</span></span></div>' +
                '<div class="ms-kra-tagline">' + escapeHtml(meta.tagline) + '</div>' +
                '<div class="ms-kra-signals">' + escapeHtml(meta.signals) + '</div>' +
                '</div>';
        });
        html += '</div></div>';

        return html;
    }

    function msBuildScorecardHtml(data, selectedUser) {
        // CEO always sees real scores; non-CEO users see preview until we flip the flag
        if (MS_PREVIEW_MODE && !MS_IS_CEO_MODE) {
            return msBuildPreviewHtml(data, selectedUser);
        }

        var monthCls = msScoreClass(data.monthAverage);
        var coverage = data.coverage || {};
        var coverageMsg = '';
        if (coverage.signals_with_data != null && coverage.signals_with_data < coverage.signals_total) {
            coverageMsg = 'Based on ' + coverage.signals_with_data + ' of ' + coverage.signals_total + ' KRAs — more data needed for a full picture.';
        }

        var subtitle = escapeHtml(data.monthLabel);
        if (selectedUser && selectedUser.role) {
            subtitle = escapeHtml(msRoleLabel(selectedUser.role)) + ' · ' + subtitle;
        }

        var html = '';

        // Header
        html += '<div class="ms-header">' +
            '<div class="ms-header-left">' +
                '<h2 class="ms-title">' + escapeHtml(selectedUser ? selectedUser.name : 'Team KRAs') + ' ' +
                    '<button type="button" class="ms-info-btn" id="msInfoBtn" aria-label="How KRAs are calculated" title="How KRAs are calculated">' +
                        '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>' +
                    '</button>' +
                    '<button type="button" class="ms-history-btn" id="msHistoryBtn" aria-label="View previous KRAs" title="Previous KRAs">' +
                        '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><polyline points="3 3 3 8 8 8"/><polyline points="12 7 12 12 15 14"/></svg>' +
                        '<span>Previous KRAs</span>' +
                    '</button>' +
                '</h2>' +
                '<div class="ms-sub">' + subtitle + '</div>' +
                (coverageMsg ? '<div class="ms-coverage">' + escapeHtml(coverageMsg) + '</div>' : '') +
            '</div>' +
            '<div class="ms-header-badge ' + monthCls + '">' + msFormatScore(data.monthAverage) + ' <span>/ 5</span></div>' +
            '</div>';

        // KRA cards
        html += '<div class="ms-section"><h3 class="ms-section-title">3 Key Result Areas</h3><div class="ms-kra-cards">';
        MS_KRA_ORDER.forEach(function (key) {
            var meta = MS_KRA_META[key];
            var score = data.kras[key];
            var cls = msScoreClass(score);
            var weightPct = Math.round(MS_KRA_WEIGHTS[key] * 100);
            html += '<div class="ms-kra-card ' + cls + '">' +
                '<div class="ms-kra-head">' +
                    '<span class="ms-kra-icon">' + meta.icon + '</span>' +
                    '<div class="ms-kra-name">' + escapeHtml(meta.label) + '</div>' +
                    '<div class="ms-kra-weight">Weight ' + weightPct + '%</div>' +
                '</div>' +
                '<div class="ms-kra-score-row">' + msStarsHtml(score) + '<span class="ms-kra-score-num">' + msFormatScore(score) + '<span>/5</span></span></div>' +
                '<div class="ms-kra-tagline">' + escapeHtml(meta.tagline) + '</div>' +
                '<div class="ms-kra-signals">' + escapeHtml(meta.signals) + '</div>' +
                '</div>';
        });
        html += '</div></div>';

        return html;
    }

    async function msLoadAndRenderForUser(userId) {
        var bodyEl = document.getElementById('msScorecardBody');
        if (!bodyEl) return;
        bodyEl.innerHTML = '<div class="ms-loading-text">Loading scorecard…</div>';

        var selectedUser = null;
        if (userId && MS_USER_CACHE) {
            selectedUser = MS_USER_CACHE.find(function (u) { return u.id === userId; });
        } else if (!userId && MS_SELF_USER) {
            selectedUser = MS_SELF_USER;
        }
        MS_SELECTED_USER_ID = userId;

        try {
            var url = '/api/my-kras' + (userId ? '?user_id=' + encodeURIComponent(userId) : '');
            var resp = await requestJson(url);
            var data = resp && resp.data;
            if (!data) {
                bodyEl.innerHTML = '<div class="ms-loading-text">No scorecard data for this month.</div>';
                return;
            }
            bodyEl.innerHTML = msBuildScorecardHtml(data, selectedUser);
            var infoBtn = document.getElementById('msInfoBtn');
            if (infoBtn) infoBtn.addEventListener('click', msOpenHowto);
            var historyBtn = document.getElementById('msHistoryBtn');
            if (historyBtn) historyBtn.addEventListener('click', function () { msOpenHistory(userId, selectedUser); });
        } catch (err) {
            var msg = (err && err.message) || 'Unable to load scorecard.';
            bodyEl.innerHTML = '<div class="ms-loading-text">' + escapeHtml(msg) + '</div>';
        }
    }

    var MS_TEAM_MONTH = null;
    var MS_TEAM_DATA = null;
    var MS_TEAM_SORT = null; // { idx: <weekIndex int> | 'name' | 'avg', dir: 'asc' | 'desc' }
    // Score-range filter on the monthly average. 0–5 = inactive (show all,
    // including not-yet-rated rows). `focus` restores caret across repaint.
    var MS_TEAM_RANGE = { min: 0, max: 5, focus: null };

    function msSortInd(idx) {
        var active = MS_TEAM_SORT && String(MS_TEAM_SORT.idx) === String(idx);
        if (!active) return '<span class="ms-tt-sort-ind">&#8645;</span>';
        return '<span class="ms-tt-sort-ind ms-tt-sort-ind--active">' +
            (MS_TEAM_SORT.dir === 'asc' ? '&#9650;' : '&#9660;') + '</span>';
    }

    function msMonthClosedHtml() {
        return '<div class="ms-wrap"><div class="ms-closed-msg">' +
            '<div class="ms-closed-icon"><svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg></div>' +
            '<h3 class="ms-closed-title">KRA scores are published every Monday</h3>' +
            '<p class="ms-closed-desc">Your previous week\'s KRA scorecard will be available here next Monday.</p>' +
            '<button type="button" class="ms-history-btn ms-history-btn--inline" id="msHistoryBtn" aria-label="View previous KRAs" title="Previous KRAs">' +
                '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><polyline points="3 3 3 8 8 8"/><polyline points="12 7 12 12 15 14"/></svg>' +
                '<span>View Previous KRAs</span>' +
            '</button>' +
            '</div></div>';
    }

    function msTeamScoreClass(score) {
        if (score == null) return '';
        if (score >= 4) return 'ms-tcell--good';
        if (score >= 3) return 'ms-tcell--avg';
        return 'ms-tcell--poor';
    }

    // Monthly average = mean of an employee's published-week scores (null if
    // none yet). This is the number the score-range filter and Avg column use.
    function msTeamAvg(emp, weeks) {
        var sum = 0, n = 0;
        weeks.forEach(function (w) {
            var s = emp.scores[w.key];
            if (s != null) { sum += s; n++; }
        });
        return n ? (sum / n) : null;
    }

    function msTeamTableHtml(data) {
        var weeks = data.weeks || [];
        var emps = data.employees || [];
        var rMin = MS_TEAM_RANGE.min, rMax = MS_TEAM_RANGE.max;
        var rangeActive = (rMin > 0 || rMax < 5);

        var html = '<div class="ms-team-header">' +
            '<h2 class="ms-title">Team KRAs</h2>' +
            '<div class="ms-kra-filter">' +
                '<span class="ms-kra-filter-lbl">Avg score</span>' +
                '<input type="number" id="msRangeMin" class="ms-kra-range" min="0" max="5" step="0.1" value="' + rMin + '" aria-label="Minimum average score">' +
                '<span class="ms-kra-filter-dash">–</span>' +
                '<input type="number" id="msRangeMax" class="ms-kra-range" min="0" max="5" step="0.1" value="' + rMax + '" aria-label="Maximum average score">' +
                (rangeActive ? '<button type="button" class="ms-kra-range-clear" id="msRangeClear" title="Clear score filter">&#10005;</button>' : '') +
            '</div>' +
            '<div class="ms-month-nav">' +
                '<button type="button" class="ms-month-btn" id="msMonthPrev">' +
                    '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>' +
                '</button>' +
                '<span class="ms-month-label">' + escapeHtml(data.monthLabel) + '</span>' +
                '<button type="button" class="ms-month-btn" id="msMonthNext">' +
                    '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>' +
                '</button>' +
            '</div>' +
        '</div>';

        if (!weeks.length) {
            html += '<div class="ms-loading-text">No week data for this month yet.</div>';
            return html;
        }

        // Precompute each employee's monthly average once.
        var avgById = {};
        emps.forEach(function (e) { avgById[e.id] = msTeamAvg(e, weeks); });

        // Score-range filter. Default 0–5 keeps everyone (incl. not-yet-rated
        // "—" rows). Narrowing drops rows whose average is outside the range,
        // and the no-average rows (they can't satisfy a numeric range).
        var rows = emps.slice();
        if (rangeActive) {
            rows = rows.filter(function (e) {
                var a = avgById[e.id];
                return a != null && a >= rMin && a <= rMax;
            });
        }
        var shownCount = rows.length, totalCount = emps.length;

        // Sort a copy so the original payload order is preserved (and used
        // when no sort is active). Unrated cells ("—") always sink to the
        // bottom so "no data" is never confused with a low score.
        if (MS_TEAM_SORT) {
            var sort = MS_TEAM_SORT;
            rows.sort(function (e1, e2) {
                if (sort.idx === 'name') {
                    var r = (e1.name || '').localeCompare(e2.name || '');
                    return sort.dir === 'asc' ? r : -r;
                }
                var s1, s2;
                if (sort.idx === 'avg') {
                    s1 = avgById[e1.id]; s2 = avgById[e2.id];
                } else {
                    var wk = weeks[sort.idx];
                    var key = wk && wk.key;
                    s1 = key ? e1.scores[key] : null;
                    s2 = key ? e2.scores[key] : null;
                }
                var n1 = (s1 == null), n2 = (s2 == null);
                if (n1 && n2) return 0;
                if (n1) return 1;
                if (n2) return -1;
                return sort.dir === 'asc' ? (s1 - s2) : (s2 - s1);
            });
        }

        html += '<div class="ms-team-table-wrap"><table class="ms-team-table">';
        html += '<thead><tr><th class="ms-tt-emp ms-tt-sortable" data-sort-idx="name">Employee' + msSortInd('name') + '</th>';
        weeks.forEach(function (w, i) {
            html += '<th class="ms-tt-week ms-tt-sortable" data-sort-idx="' + i + '">' + escapeHtml(w.label) + msSortInd(i) + '</th>';
        });
        html += '<th class="ms-tt-week ms-tt-sortable ms-tt-avg" data-sort-idx="avg">Avg' + msSortInd('avg') + '</th>';
        html += '</tr></thead><tbody>';

        rows.forEach(function (emp) {
            html += '<tr>';
            html += '<td class="ms-tt-emp"><div class="ms-tt-name">' + escapeHtml(emp.name) + '</div><div class="ms-tt-role">' + escapeHtml(emp.role || '') + '</div></td>';
            weeks.forEach(function (w) {
                var sc = emp.scores[w.key];
                html += '<td class="ms-tt-score ' + msTeamScoreClass(sc) + '">' + (sc != null ? sc.toFixed(1) : '—') + '</td>';
            });
            var av = avgById[emp.id];
            html += '<td class="ms-tt-score ms-tt-avg ' + msTeamScoreClass(av) + '">' + (av != null ? av.toFixed(1) : '—') + '</td>';
            html += '</tr>';
        });

        if (!rows.length) {
            html += '<tr><td class="ms-tt-emp" colspan="' + (weeks.length + 2) + '" style="text-align:center;color:#71717a;padding:18px">No employees with an average in ' + rMin + '–' + rMax + '.</td></tr>';
        }

        html += '</tbody></table></div>';
        if (rangeActive) {
            html += '<div class="ms-kra-filter-hint">Showing ' + shownCount + ' of ' + totalCount + ' · average ' + rMin + '–' + rMax + '</div>';
        }
        return html;
    }

    async function msLoadTeamTable(month) {
        var root = document.getElementById('my_scoreView');
        if (!root) return;

        var body = document.getElementById('msTeamBody');
        if (!body) {
            root.innerHTML = '<div class="ms-wrap"><div id="msTeamBody"><div class="ms-loading-text">Loading team KRAs…</div></div></div>';
            body = document.getElementById('msTeamBody');
        } else {
            body.innerHTML = '<div class="ms-loading-text">Loading team KRAs…</div>';
        }

        try {
            var url = '/api/my-kras/team-table' + (month ? '?month=' + encodeURIComponent(month) : '');
            var resp = await requestJson(url);
            MS_TEAM_MONTH = resp.month;
            MS_TEAM_DATA = resp;
            msPaintTeamTable(body);
        } catch (err) {
            body.innerHTML = '<div class="ms-loading-text">' + escapeHtml((err && err.message) || 'Unable to load team table.') + '</div>';
        }
    }

    // Render the cached team table and (re)wire month-nav + sortable headers.
    // Called on initial load and on every header click — no refetch needed
    // since all employees + scores are already in MS_TEAM_DATA.
    function msPaintTeamTable(body) {
        if (!body || !MS_TEAM_DATA) return;
        body.innerHTML = msTeamTableHtml(MS_TEAM_DATA);

        var prevBtn = document.getElementById('msMonthPrev');
        var nextBtn = document.getElementById('msMonthNext');
        if (prevBtn) prevBtn.addEventListener('click', function () { msShiftMonth(-1); });
        if (nextBtn) nextBtn.addEventListener('click', function () { msShiftMonth(1); });

        body.querySelectorAll('.ms-tt-sortable').forEach(function (th) {
            th.addEventListener('click', function () {
                var raw = th.getAttribute('data-sort-idx');
                var idx = (raw === 'name' || raw === 'avg') ? raw : parseInt(raw, 10);
                if (MS_TEAM_SORT && String(MS_TEAM_SORT.idx) === String(idx)) {
                    MS_TEAM_SORT.dir = MS_TEAM_SORT.dir === 'asc' ? 'desc' : 'asc';
                } else {
                    // First click on a week sorts ascending → lowest scores
                    // (the 2.x ratings) surface at the top.
                    MS_TEAM_SORT = { idx: idx, dir: 'asc' };
                }
                msPaintTeamTable(body);
            });
        });

        // Score-range filter. Clamp to 0–5, keep min ≤ max, and live-filter as
        // the user types. innerHTML is replaced on repaint, so remember which
        // box had focus and restore the caret to the end after repainting.
        var minEl = document.getElementById('msRangeMin');
        var maxEl = document.getElementById('msRangeMax');
        function clampNum(v, fallback) {
            v = parseFloat(v);
            if (isNaN(v)) return fallback;
            return Math.min(5, Math.max(0, v));
        }
        function onRangeInput(which) {
            MS_TEAM_RANGE.focus = which;
            var lo = clampNum(minEl.value, 0);
            var hi = clampNum(maxEl.value, 5);
            if (which === 'min' && lo > hi) hi = lo;
            if (which === 'max' && hi < lo) lo = hi;
            MS_TEAM_RANGE.min = lo;
            MS_TEAM_RANGE.max = hi;
            msPaintTeamTable(body);
        }
        if (minEl) {
            minEl.addEventListener('focus', function () { MS_TEAM_RANGE.focus = 'min'; });
            minEl.addEventListener('input', function () { onRangeInput('min'); });
        }
        if (maxEl) {
            maxEl.addEventListener('focus', function () { MS_TEAM_RANGE.focus = 'max'; });
            maxEl.addEventListener('input', function () { onRangeInput('max'); });
        }
        var clearBtn = document.getElementById('msRangeClear');
        if (clearBtn) clearBtn.addEventListener('click', function () {
            MS_TEAM_RANGE.min = 0;
            MS_TEAM_RANGE.max = 5;
            MS_TEAM_RANGE.focus = null;
            msPaintTeamTable(body);
        });

        if (MS_TEAM_RANGE.focus) {
            var refocus = document.getElementById(MS_TEAM_RANGE.focus === 'min' ? 'msRangeMin' : 'msRangeMax');
            if (refocus) {
                refocus.focus();
                var val = refocus.value;
                try { refocus.setSelectionRange(val.length, val.length); } catch (e) { /* number inputs may not support selection */ }
            }
        }
    }

    function msShiftMonth(dir) {
        if (!MS_TEAM_MONTH) return;
        var parts = MS_TEAM_MONTH.split('-');
        var d = new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1 + dir, 1);
        var now = new Date();
        if (d > now) return;
        var m = String(d.getMonth() + 1).padStart(2, '0');
        msLoadTeamTable(d.getFullYear() + '-' + m);
    }

    async function renderMyScore() {
        var root = document.getElementById('my_scoreView');
        if (!root) return;

        root.innerHTML = msLoadingShell('');

        if (MS_USER_CACHE === null) {
            // Distinguish "genuinely not a CEO" (403) from a transient failure
            // (network / 419 / 5xx — e.g. during a route+config cache rebuild).
            // Only a real 403 may latch non-CEO mode; a transient failure must
            // NOT, or a CEO gets permanently stuck on the "scores published
            // Monday" wall for the rest of the tab session with no retry.
            var ceoCheckFailed = false;
            try {
                var uRes = await fetch('/api/my-kras/users', {
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                });
                if (uRes.ok) {
                    var uData = await uRes.json().catch(function () { return {}; });
                    MS_USER_CACHE = (uData && uData.users) || [];
                    MS_IS_CEO_MODE = true;
                } else if (uRes.status === 403) {
                    MS_USER_CACHE = [];
                    MS_IS_CEO_MODE = false;
                } else {
                    ceoCheckFailed = true;
                }
            } catch (err) {
                ceoCheckFailed = true;
            }

            if (ceoCheckFailed) {
                // Leave the cache null so the next open retries instead of
                // demoting a CEO to the non-CEO closed-month screen.
                MS_USER_CACHE = null;
                MS_IS_CEO_MODE = false;
                root.innerHTML = '<div class="ms-wrap"><div class="ms-loading-text">' +
                    'Couldn’t load Team KRAs right now. ' +
                    '<button type="button" class="btn btn-sm btn-outline-secondary" id="msRetryBtn">Retry</button>' +
                    '</div></div>';
                var rb = document.getElementById('msRetryBtn');
                if (rb) rb.addEventListener('click', function () { renderMyScore(); });
                return;
            }
        }

        if (MS_IS_CEO_MODE) {
            msLoadTeamTable(null);
            return;
        }

        // JP/Bala/Nandha/Ayush are entirely excluded from KRA. (JP is CEO so
        // the check above already returned with the team table — minus these
        // users; this branch covers the non-CEO excluded leaders.)
        if (config.kraExcluded) {
            root.innerHTML = '<div class="ms-wrap"><div class="ms-closed-msg">' +
                '<div class="ms-closed-icon"><svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M9 12h6"/></svg></div>' +
                '<h3 class="ms-closed-title">KRAs don’t apply to your role</h3>' +
                '<p class="ms-closed-desc">The KRA scorecard isn’t used for your role, so there’s nothing to show here.</p>' +
                '</div></div>';
            return;
        }

        var today = new Date();
        if (today.getDay() !== 1) {
            root.innerHTML = msMonthClosedHtml();
            var historyBtnClosed = document.getElementById('msHistoryBtn');
            if (historyBtnClosed) {
                historyBtnClosed.addEventListener('click', function () {
                    msOpenHistory(null, { id: 0, name: config.userName || 'Your', role: config.role || '' });
                });
            }
            return;
        }

        MS_SELF_USER = { id: 0, name: config.userName || 'Your', role: config.role || '' };
        root.innerHTML = '<div class="ms-wrap"><div id="msScorecardBody"></div></div>';
        msLoadAndRenderForUser(null);
    }

    // HR functions extracted to hr-portal.js — accessible via window.HRModule
    function renderEmployees() { return HRModule.renderEmployees(); }
    function renderProfile() { return HRModule.renderProfile(); }
    function renderLeave() { return HRModule.renderLeave(); }

    // ── Team Leave (JP-only): one sorted row per employee, click → detail ──
    var teamLeaveState = { month: null };

    function tlYm(d) {
        return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0');
    }
    function tlMonthLabel(d) {
        return d.toLocaleDateString('en-IN', { month: 'long', year: 'numeric' });
    }
    function tlMonthOptions(selected) {
        // Only this month + previous month (no tab strip).
        var now = new Date(); now.setDate(1);
        var prev = new Date(now); prev.setMonth(prev.getMonth() - 1);
        return [now, prev].map(function (d) {
            var v = tlYm(d);
            return '<option value="' + v + '"' + (selected === v ? ' selected' : '') + '>' +
                escapeHtml(tlMonthLabel(d)) + '</option>';
        }).join('');
    }

    function tlWhen(l) {
        if (l.from_time && l.to_time) {
            return l.start_date + ' · ' + l.from_time + '–' + l.to_time + (l.hours ? ' (' + l.hours + 'h)' : '');
        }
        if (l.start_date === l.end_date) {
            return l.start_date + ' · ' + (l.total_days || 1) + 'd';
        }
        return l.start_date + ' → ' + l.end_date + ' · ' + (l.total_days || 1) + 'd';
    }

    function openTlPersonModal(person, monthLabel) {
        var existing = document.getElementById('tlPersonModal');
        if (existing) existing.remove();

        var items = (person.leaves || []).map(function (l) {
            return '<div class="tl-m-item tl-m-item--' + escapeHtml(l.slug) + '">' +
                '<div class="tl-m-item-top">' +
                    '<span class="tl-m-type">' + escapeHtml(l.type) + '</span>' +
                    '<span class="tl-m-when">' + escapeHtml(tlWhen(l)) + '</span>' +
                '</div>' +
                (l.reason ? '<div class="tl-m-reason">' + escapeHtml(l.reason) + '</div>' : '') +
            '</div>';
        }).join('');

        var overlay = document.createElement('div');
        overlay.className = 'modal-overlay';
        overlay.id = 'tlPersonModal';
        overlay.innerHTML = '<div class="modal-content tl-modal">' +
            '<div class="tl-m-head">' +
                '<div>' +
                    '<div class="tl-m-name">' + escapeHtml(person.name) + '</div>' +
                    '<div class="tl-m-sub">' + escapeHtml(monthLabel) + ' · ' + person.total +
                        ' leave' + (person.total === 1 ? '' : 's') + '</div>' +
                '</div>' +
                '<button type="button" class="modal-close" id="tlMClose">&times;</button>' +
            '</div>' +
            '<div class="tl-m-body">' + (items || '<div class="tl-m-empty">No leaves.</div>') + '</div>' +
        '</div>';
        document.body.appendChild(overlay);

        function close() { overlay.remove(); document.removeEventListener('keydown', onEsc); }
        function onEsc(e) { if (e.key === 'Escape') close(); }
        document.addEventListener('keydown', onEsc);
        overlay.addEventListener('click', function (e) { if (e.target === overlay) close(); });
        document.getElementById('tlMClose').onclick = close;
    }

    function tlTableHtml(data) {
        var heads = (data.types || []).map(function (t) {
            return '<th class="tl-th-num">' + escapeHtml(t.label) + (t.is_hourly ? ' (hrs)' : '') + '</th>';
        }).join('');

        var people = data.people || [];
        var rows;
        if (people.length) {
            rows = people.map(function (p, i) {
                var tds = (data.types || []).map(function (t) {
                    var m = (p.metrics && p.metrics[t.slug]) || { days: 0, hours: 0 };
                    var zero, val;
                    if (t.is_hourly) {
                        var h = m.hours || 0;
                        zero = !h;
                        val = zero ? '–' : ((Math.round(h * 10) / 10) + 'h');
                    } else {
                        var d = m.days || 0;
                        zero = !d;
                        val = zero ? '–' : d;
                    }
                    return '<td class="tl-td-num' + (zero ? ' tl-zero' : '') + '">' + val + '</td>';
                }).join('');
                return '<tr class="tl-row" data-idx="' + i + '">' +
                    '<td class="tl-td-name">' + escapeHtml(p.name) + '</td>' +
                    tds +
                    '<td class="tl-td-total">' + (p.total_days || 0) + '</td>' +
                '</tr>';
            }).join('');
        } else {
            rows = '<tr><td class="tl-empty" colspan="' + ((data.types || []).length + 2) + '">' +
                'No leaves in ' + escapeHtml(data.month_label || 'this month') + '</td></tr>';
        }

        return '<table class="tl-table"><thead><tr>' +
            '<th class="tl-th-name">Employee</th>' + heads + '<th class="tl-th-num">Total (days)</th>' +
            '</tr></thead><tbody>' + rows + '</tbody></table>';
    }

    async function renderTeamLeave() {
        var root = document.getElementById('team_leaveView');
        if (!root) return;
        if (!teamLeaveState.month) teamLeaveState.month = tlYm(new Date());
        root.innerHTML = '<div class="tl-wrap"><div class="kpi-status-msg">Loading team leave…</div></div>';
        try {
            var data = await requestJson('/api/leave/team-overview?month=' + encodeURIComponent(teamLeaveState.month));
            teamLeaveState.month = data.month || teamLeaveState.month;
            root.innerHTML = '<div class="tl-wrap">' +
                '<div class="tl-head">' +
                    '<div>' +
                        '<h2 class="tl-title">Team Leave</h2>' +
                        '<div class="tl-sub">All employees · approved leaves · ranked by total leave days · click a row for details</div>' +
                    '</div>' +
                    '<select class="tl-month-select" id="tlMonthSel">' + tlMonthOptions(teamLeaveState.month) + '</select>' +
                '</div>' +
                tlTableHtml(data) +
            '</div>';

            var sel = document.getElementById('tlMonthSel');
            if (sel) sel.onchange = function () { teamLeaveState.month = sel.value; renderTeamLeave(); };

            var people = data.people || [];
            root.querySelectorAll('.tl-row').forEach(function (tr) {
                tr.onclick = function () {
                    var p = people[parseInt(tr.getAttribute('data-idx'), 10)];
                    if (p) openTlPersonModal(p, data.month_label || '');
                };
            });
        } catch (e) {
            root.innerHTML = '<div class="tl-wrap"><div class="kpi-status-msg" style="color:#f87171">Failed to load team leave.</div></div>';
        }
    }

    function renderHRDashboard() { return HRModule.renderHRDashboard(); }
    function renderLetters() {
        if (window.LettersModule && typeof window.LettersModule.render === 'function') {
            window.LettersModule.render();
        }
    }

    /* ── Manager Ratings (CEO only) ── */
    function mrScoreClass(n) {
        if (n == null) return '';
        if (n >= 4) return 'mr-score--good';
        if (n >= 3) return 'mr-score--ok';
        return 'mr-score--bad';
    }

    function mrCellHtml(cell) {
        if (!cell) return '<span class="mr-empty-cell">—</span>';
        return '<span class="mr-num ' + mrScoreClass(cell.d) + '">' + cell.d + '</span>' +
            '<span class="mr-sep">/</span>' +
            '<span class="mr-num ' + mrScoreClass(cell.q) + '">' + cell.q + '</span>';
    }

    async function renderManagerRatings() {
        var root = document.getElementById('manager_ratingsView');
        if (!root) return;

        root.innerHTML = '<div class="mr-wrap"><div class="mr-loading">Loading manager ratings…</div></div>';

        try {
            var resp = await requestJson('/api/manager-ratings/overview');
            var weeks = (resp && resp.weeks) || [];
            var managers = (resp && resp.managers) || [];

            var html = '<div class="mr-wrap">';
            html += '<div class="mr-header">' +
                '<h2 class="mr-title">Manager Ratings</h2>' +
                '<div class="mr-hint">D / Q · 1–5 scale</div>' +
            '</div>';

            if (!managers.length) {
                html += '<div class="mr-empty">No managers with active direct reports yet.</div></div>';
                root.innerHTML = html;
                return;
            }

            if (!weeks.length) {
                html += '<div class="mr-empty">No completed weeks yet — ratings appear on the Monday after the Fri–Sun review window.</div></div>';
                root.innerHTML = html;
                return;
            }

            var colCount = 1 + weeks.length;
            html += '<table class="mr-flat"><thead><tr>' +
                '<th class="mr-flat-th-name">Team Member</th>';
            weeks.forEach(function (w) {
                html += '<th class="mr-flat-th-week">' + escapeHtml(w.label) + '</th>';
            });
            html += '</tr></thead><tbody>';

            managers.forEach(function (mgr) {
                html += '<tr class="mr-flat-group"><td colspan="' + colCount + '">' +
                    '<span class="mr-flat-mgr-name">' + escapeHtml(mgr.name) + '</span>' +
                    (mgr.role ? '<span class="mr-flat-mgr-role">' + escapeHtml(mgr.role) + '</span>' : '') +
                '</td></tr>';

                mgr.subordinates.forEach(function (sub) {
                    html += '<tr class="mr-flat-row">' +
                        '<td class="mr-flat-name">' + escapeHtml(sub.name) +
                            (sub.role ? '<span class="mr-flat-sub-role">' + escapeHtml(sub.role) + '</span>' : '') +
                        '</td>';
                    weeks.forEach(function (w) {
                        html += '<td class="mr-flat-cell">' + mrCellHtml(sub.cells[w.key]) + '</td>';
                    });
                    html += '</tr>';
                });
            });
            html += '</tbody></table>';

            html += '</div>';
            root.innerHTML = html;
        } catch (err) {
            root.innerHTML = '<div class="mr-wrap"><div class="mr-empty">' + escapeHtml((err && err.message) || 'Unable to load manager ratings.') + '</div></div>';
        }
    }

    /* ──────────────────────────────────────────────────────────────────
     * KPI Report — managers add weekly tracking notes (Fri–Mon, never locked);
     * everyone views their own scorecard read-only; JP views all + manages KPI
     * defs. Data: GET /api/kpi-report/{people,user/{id}} · POST user/{id}/week.
     * ────────────────────────────────────────────────────────────────── */
    var kpirState = { people: null, tab: 'me' };

    function kpirWeekRange(weekKey) {
        var fri = new Date(weekKey + 'T00:00:00');
        if (isNaN(fri.getTime())) return weekKey;
        var mon = new Date(fri); mon.setDate(fri.getDate() - 4);
        var opt = { month: 'short', day: 'numeric' };
        return mon.toLocaleDateString('en-IN', opt) + ' – ' + fri.toLocaleDateString('en-IN', opt);
    }

    function kpirStatusClass(status) {
        if (status === 'met') return 'kpir-st-met';
        if (status === 'missed') return 'kpir-st-missed';
        if (status === 'partial') return 'kpir-st-partial';
        return '';
    }

    async function renderKpiReport() {
        var root = document.getElementById('kpi_reportView');
        if (!root) return;
        root.innerHTML = '<div class="kpir-wrap"><div class="kpir-loading">Loading KPI report…</div></div>';
        try {
            var people = await requestJson('/api/kpi-report/people');
            kpirState.people = people;
            kpirState.tab = (people.me && people.me.hasKpis) ? 'me'
                : (people.team && people.team.length) ? 'team'
                : (people.isAdmin && people.all && people.all.length) ? 'all' : 'me';
            kpirRender(root);
        } catch (err) {
            root.innerHTML = '<div class="kpir-wrap"><div class="kpir-empty">' + escapeHtml((err && err.message) || 'Unable to load KPI report.') + '</div></div>';
        }
    }

    function kpirRender(root) {
        var p = kpirState.people;
        var tabs = [];
        if (p.me && p.me.hasKpis) tabs.push({ key: 'me', label: 'My KPIs' });
        if (p.team && p.team.length) tabs.push({ key: 'team', label: 'My Team', count: p.team.length });
        if (p.isAdmin && p.all && p.all.length) tabs.push({ key: 'all', label: 'All Employees', count: p.all.length });

        var html = '<div class="kpir-wrap">';
        html += '<div class="kpir-header"><h2 class="kpir-title">KPI Report</h2>' +
            '<div class="kpir-hint">' + (p.isWindowOpen ? 'Weekly notes open · ' + escapeHtml(p.weekLabel) : 'Managers fill weekly notes Fri–Mon') + '</div></div>';
        if (tabs.length > 1) {
            html += '<div class="kpir-tabs">';
            tabs.forEach(function (t) {
                html += '<button class="kpir-tab' + (kpirState.tab === t.key ? ' active' : '') + '" data-tab="' + t.key + '">' +
                    escapeHtml(t.label) + (t.count ? ' <span class="kpir-tab-count">' + t.count + '</span>' : '') + '</button>';
            });
            html += '</div>';
        }
        html += '<div class="kpir-body" id="kpir-body"></div></div>';
        root.innerHTML = html;

        root.querySelectorAll('.kpir-tab').forEach(function (b) {
            b.addEventListener('click', function () { kpirState.tab = b.getAttribute('data-tab'); kpirRender(root); });
        });

        var body = root.querySelector('#kpir-body');
        if (kpirState.tab === 'me') kpirRenderMe(body);
        else if (kpirState.tab === 'team') kpirRenderList(body, p.team, 'team');
        else kpirRenderList(body, p.all, 'all');
    }

    function kpirRenderMe(body) {
        var me = kpirState.people.me;
        if (!me || !me.hasKpis) {
            body.innerHTML = '<div class="kpir-empty">You don\'t have any KPIs assigned yet. Your KPIs will appear here once set.</div>';
            return;
        }
        body.innerHTML = '<div class="kpir-detail" id="kpir-detail"></div>';
        kpirLoadDetail(body.querySelector('#kpir-detail'), me.id);
    }

    function kpirRenderList(body, list, kind) {
        if (!list || !list.length) { body.innerHTML = '<div class="kpir-empty">Nobody to show here.</div>'; return; }
        var html = '<div class="kpir-split"><div class="kpir-people">';
        if (kind === 'all') html += '<input type="text" class="kpir-search" placeholder="Search people…">';
        html += '<div class="kpir-people-list">';
        list.forEach(function (s) {
            var badge = '';
            if (kind === 'team') {
                badge = s.weekFilled ? '<span class="kpir-badge kpir-badge-done" title="This week filled">✓</span>'
                    : s.weekPartial ? '<span class="kpir-badge kpir-badge-partial" title="Partly filled">◐</span>'
                    : '<span class="kpir-badge kpir-badge-todo" title="Not filled this week">○</span>';
            }
            html += '<button class="kpir-person" data-id="' + s.id + '">' +
                '<span class="kpir-person-meta"><span class="kpir-person-name">' + escapeHtml(s.name) + '</span>' +
                '<span class="kpir-person-role">' + escapeHtml(s.role || '') + '</span></span>' + badge + '</button>';
        });
        html += '</div></div><div class="kpir-detail" id="kpir-detail"><div class="kpir-empty">Select a person to view their KPI report.</div></div></div>';
        body.innerHTML = html;

        var detail = body.querySelector('#kpir-detail');
        body.querySelectorAll('.kpir-person').forEach(function (b) {
            b.addEventListener('click', function () {
                body.querySelectorAll('.kpir-person').forEach(function (x) { x.classList.remove('active'); });
                b.classList.add('active');
                kpirLoadDetail(detail, Number(b.getAttribute('data-id')));
            });
        });
        var search = body.querySelector('.kpir-search');
        if (search) search.addEventListener('input', function () {
            var q = search.value.toLowerCase();
            body.querySelectorAll('.kpir-person').forEach(function (b) {
                var n = b.querySelector('.kpir-person-name').textContent.toLowerCase();
                b.style.display = n.indexOf(q) >= 0 ? '' : 'none';
            });
        });
    }

    async function kpirLoadDetail(el, userId) {
        el.innerHTML = '<div class="kpir-loading">Loading…</div>';
        try {
            var data = await requestJson('/api/kpi-report/user/' + userId);
            el.innerHTML = kpirDetailHtml(data);
            kpirWireDetail(el, data);
        } catch (err) {
            el.innerHTML = '<div class="kpir-empty">' + escapeHtml((err && err.message) || 'Unable to load.') + '</div>';
        }
    }

    function kpirDetailHtml(data) {
        var items = data.items || [];
        var admin = kpirState.people && kpirState.people.isAdmin;
        var manageBtn = admin ? '<button class="kpir-manage-btn" data-uid="' + data.subject.id + '">Manage KPIs</button>' : '';
        var head = '<div class="kpir-detail-head"><div class="kpir-detail-name">' + escapeHtml(data.subject.name) +
            '<span class="kpir-detail-role">' + escapeHtml(data.subject.role || '') + '</span></div>' + manageBtn + '</div>';

        if (!items.length) {
            return head + '<div class="kpir-empty">No KPIs defined' + (admin ? ' yet — use “Manage KPIs” to add some.' : ' for this person yet.') + '</div>';
        }

        var weekKey = data.weekKey;
        var currentWeek = (data.weeks || []).filter(function (w) { return w.weekKey === weekKey; })[0];
        var editable = data.canEdit && data.isWindowOpen && (data.editableWeeks || []).indexOf(weekKey) >= 0;

        var html = head;
        if (data.canEdit) {
            html += '<div class="kpir-window ' + (editable ? 'open' : 'closed') + '">' +
                (editable ? 'Editable now · week of ' + escapeHtml(kpirWeekRange(weekKey)) : 'Notes are editable Fri–Mon only') + '</div>';
        }

        html += '<table class="kpir-table"><thead><tr><th class="kpir-th-kpi">KPI</th><th class="kpir-th-target">Target</th><th class="kpir-th-note">This week\'s update</th></tr></thead><tbody>';
        items.forEach(function (it) {
            var entry = currentWeek && currentWeek.entries[it.id];
            var noteText = entry ? (entry.text || '') : '';
            var cell = editable
                ? '<textarea class="kpir-note-input" data-item="' + it.id + '" rows="2" placeholder="Add this week\'s update…">' + escapeHtml(noteText) + '</textarea>'
                : (noteText ? '<div class="kpir-note">' + escapeHtml(noteText) + '</div>' : '<span class="kpir-note-empty">—</span>');
            html += '<tr><td class="kpir-kpi"><div class="kpir-kpi-name">' + escapeHtml(it.name) + '</div>' +
                (it.description ? '<div class="kpir-kpi-desc">' + escapeHtml(it.description) + '</div>' : '') +
                (it.weight ? '<span class="kpir-kpi-weight">weight ' + it.weight + '</span>' : '') + '</td>' +
                '<td class="kpir-target">' + escapeHtml(it.target || '—') + '</td>' +
                '<td class="kpir-notecell">' + cell + '</td></tr>';
        });
        html += '</tbody></table>';

        if (editable) {
            html += '<div class="kpir-actions"><span class="kpir-save-status"></span>' +
                '<button class="btn btn-primary kpir-save" data-uid="' + data.subject.id + '" data-week="' + weekKey + '">Save week</button></div>';
        }

        if (data.months && data.months.length) {
            html += '<div class="kpir-section-title">Monthly summary <span class="kpir-ai-tag">AI</span></div>';
            data.months.forEach(function (m) {
                html += '<div class="kpir-month"><div class="kpir-month-head"><span class="kpir-month-label">' + escapeHtml(m.label) + '</span>' +
                    (m.overallPct != null ? '<span class="kpir-month-pct">' + m.overallPct + '% of target</span>' : '') + '</div>';
                if (m.overall && m.overall.summary) html += '<div class="kpir-month-summary">' + escapeHtml(m.overall.summary) + '</div>';
                items.forEach(function (it) {
                    var pi = m.perItem && m.perItem[it.id];
                    if (!pi) return;
                    html += '<div class="kpir-month-kpi"><span class="kpir-mk-name">' + escapeHtml(it.name) + '</span>' +
                        (pi.percentageMet != null ? '<span class="kpir-mk-pct ' + kpirStatusClass(pi.status) + '">' + pi.percentageMet + '%</span>' : '') +
                        (pi.summary ? '<div class="kpir-mk-sum">' + escapeHtml(pi.summary) + '</div>' : '') + '</div>';
                });
                html += '</div>';
            });
        }

        var past = (data.weeks || []).filter(function (w) { return w.weekKey !== weekKey && Object.keys(w.entries || {}).length; });
        if (past.length) {
            html += '<details class="kpir-history"><summary>Past weeks (' + past.length + ')</summary>';
            past.forEach(function (w) {
                html += '<div class="kpir-pastweek"><div class="kpir-pastweek-label">' + escapeHtml(w.label) + '</div>';
                items.forEach(function (it) {
                    var e = w.entries[it.id];
                    if (e && e.text) html += '<div class="kpir-pastrow"><span class="kpir-pastrow-kpi">' + escapeHtml(it.name) + ':</span> ' + escapeHtml(e.text) + '</div>';
                });
                html += '</div>';
            });
            html += '</details>';
        }
        return html;
    }

    function kpirWireDetail(el, data) {
        var manageBtn = el.querySelector('.kpir-manage-btn');
        if (manageBtn) manageBtn.addEventListener('click', function () { kpirRenderManage(el, data.subject); });

        var btn = el.querySelector('.kpir-save');
        if (!btn) return;
        btn.addEventListener('click', async function () {
            var items = [];
            el.querySelectorAll('.kpir-note-input').forEach(function (t) {
                items.push({ kpi_item_id: Number(t.getAttribute('data-item')), report_text: t.value });
            });
            var status = el.querySelector('.kpir-save-status');
            btn.disabled = true; btn.textContent = 'Saving…';
            try {
                await requestJson('/api/kpi-report/user/' + btn.getAttribute('data-uid') + '/week', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ week_key: btn.getAttribute('data-week'), items: items })
                });
                btn.textContent = '✓ Saved'; if (status) status.textContent = 'Saved.';
                var active = document.querySelector('.kpir-person.active .kpir-badge');
                if (active) { active.className = 'kpir-badge kpir-badge-done'; active.textContent = '✓'; }
                setTimeout(function () { btn.disabled = false; btn.textContent = 'Save week'; }, 1500);
            } catch (err) {
                btn.disabled = false; btn.textContent = 'Save week';
                if (status) status.textContent = 'Save failed: ' + ((err && err.message) || 'error');
            }
        });
    }

    /* ── JP-only KPI definition management ── */
    async function kpirRenderManage(el, subject) {
        el.innerHTML = '<div class="kpir-loading">Loading…</div>';
        var data;
        try { data = await requestJson('/api/kpi-report/user/' + subject.id); }
        catch (err) { el.innerHTML = '<div class="kpir-empty">' + escapeHtml((err && err.message) || 'Unable to load.') + '</div>'; return; }

        var html = '<div class="kpir-detail-head"><div class="kpir-detail-name">Manage KPIs · ' + escapeHtml(subject.name) + '</div>' +
            '<button class="kpir-manage-done">← Back</button></div><div class="kpir-mgr-status"></div><div class="kpir-mgr-list">';
        (data.items || []).forEach(function (it) {
            html += '<div class="kpir-mgr-row" data-id="' + it.id + '">' +
                '<input class="kpir-mgr-name" value="' + escapeHtml(it.name) + '" placeholder="KPI name">' +
                '<input class="kpir-mgr-target" value="' + escapeHtml(it.target || '') + '" placeholder="Target">' +
                '<input class="kpir-mgr-weight" type="number" min="0" max="100" value="' + (it.weight != null ? it.weight : '') + '" placeholder="Wt">' +
                '<button class="kpir-mgr-save" title="Save changes">Save</button>' +
                '<button class="kpir-mgr-del" title="Remove KPI">✕</button></div>';
        });
        html += '</div><div class="kpir-mgr-add">' +
            '<input class="kpir-add-name" placeholder="New KPI name"><input class="kpir-add-target" placeholder="Target">' +
            '<input class="kpir-add-weight" type="number" min="0" max="100" placeholder="Wt">' +
            '<button class="kpir-add-btn">+ Add KPI</button></div>';
        el.innerHTML = html;

        var status = el.querySelector('.kpir-mgr-status');
        var say = function (m, ok) { if (status) { status.textContent = m; status.className = 'kpir-mgr-status' + (ok ? ' ok' : m ? ' err' : ''); } };

        el.querySelector('.kpir-manage-done').addEventListener('click', function () { kpirLoadDetail(el, subject.id); });

        el.querySelectorAll('.kpir-mgr-row').forEach(function (row) {
            var id = row.getAttribute('data-id');
            row.querySelector('.kpir-mgr-save').addEventListener('click', async function () {
                var w = row.querySelector('.kpir-mgr-weight').value;
                try {
                    await requestJson('/api/kpi-report/items/' + id, {
                        method: 'PATCH', headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            name: row.querySelector('.kpir-mgr-name').value,
                            target: row.querySelector('.kpir-mgr-target').value,
                            weight: w === '' ? null : Number(w)
                        })
                    });
                    say('Saved.', true);
                } catch (err) { say((err && err.message) || 'Save failed.'); }
            });
            row.querySelector('.kpir-mgr-del').addEventListener('click', async function () {
                if (!confirm('Remove this KPI? Past notes are kept but it leaves the active scorecard.')) return;
                try { await requestJson('/api/kpi-report/items/' + id, { method: 'DELETE' }); row.remove(); say('Removed.', true); }
                catch (err) { say((err && err.message) || 'Remove failed.'); }
            });
        });

        el.querySelector('.kpir-add-btn').addEventListener('click', async function () {
            var name = el.querySelector('.kpir-add-name').value.trim();
            if (!name) { say('Enter a KPI name.'); return; }
            var w = el.querySelector('.kpir-add-weight').value;
            try {
                await requestJson('/api/kpi-report/items', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        user_id: subject.id, name: name,
                        target: el.querySelector('.kpir-add-target').value,
                        weight: w === '' ? null : Number(w)
                    })
                });
                kpirRenderManage(el, subject);
            } catch (err) { say((err && err.message) || 'Add failed.'); }
        });
    }

    /* ── Mission Control ── */
    function fmtIndianINR(n) {
        var num = Number(n);
        if (!isFinite(num)) return '0';
        var abs = Math.abs(num);
        var sign = num < 0 ? '-' : '';
        var str = Math.round(abs).toString();
        // Indian comma: last 3 digits, then groups of 2.
        if (str.length <= 3) return sign + str;
        var last3 = str.slice(-3);
        var rest = str.slice(0, -3);
        rest = rest.replace(/\B(?=(\d{2})+(?!\d))/g, ',');
        return sign + rest + ',' + last3;
    }

    function fmtCr(n, decimals) {
        var d = (typeof decimals === 'number') ? decimals : 2;
        var num = Number(n);
        if (!isFinite(num)) return '0';
        return num.toFixed(d);
    }

    function fmtAsOf(iso) {
        if (!iso) return '';
        var d = new Date(iso);
        if (isNaN(d.getTime())) return iso;
        return d.toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' });
    }

    async function renderMission() {
        var root = document.getElementById('missionView');
        if (!root) return;
        root.innerHTML = '<div class="msn-wrap"><div class="kpi-status-msg" style="padding:24px">Loading mission data...</div></div>';

        var apiData;
        try {
            apiData = await requestJson('/api/mission');
        } catch (err) {
            root.innerHTML = '<div class="msn-wrap"><div class="kpi-status-msg" style="padding:24px;color:#f87171">Failed to load mission data.</div></div>';
            return;
        }

        var mission = apiData.mission || {};
        var totalTarget = mission.target_cr || 200;
        var deadlineStr = mission.deadline || '2027-03-31';
        var totalCurrent = mission.current_cr || 0;
        var totalCurrentInr = mission.current_inr || 0;
        var paceCr = mission.pace_projected_cr || 0;
        var dailyRunRateCr = mission.daily_run_rate_cr || 0;
        var dailyRunRateLakh = Math.round(dailyRunRateCr * 100); // Cr → Lakh
        var asOf = mission.as_of || null;
        var projects = apiData.projects || [];
        var overallPct = totalTarget > 0 ? (totalCurrent / totalTarget) * 100 : 0;
        var overallPctRounded = Math.round(overallPct * 10) / 10;

        var deadline = new Date(deadlineStr);
        var now = new Date();
        var daysLeft = Math.max(0, Math.ceil((deadline - now) / (1000 * 60 * 60 * 24)));
        var monthsLeft = Math.round(daysLeft / 30);
        var deadlineLabel = deadline.toLocaleDateString('en-IN', { month: 'long', year: 'numeric' });
        var paceColor = paceCr >= totalTarget ? '#22c55e' : (paceCr >= totalTarget * 0.5 ? '#f59e0b' : '#ef4444');

        var html = '<div class="msn-wrap">';

        // ── Header strip with freshness + refresh ──
        html += '<div class="msn-toolbar">' +
            '<div class="msn-toolbar-left">' +
                (asOf ? '<span class="msn-asof">Data as of ' + escapeHtml(fmtAsOf(asOf)) + '</span>' : '') +
            '</div>' +
            '<button type="button" class="msn-refresh" id="msnRefreshBtn" title="Refresh now">' +
                '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M23 4v6h-6"/><path d="M1 20v-6h6"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10"/><path d="M20.49 15A9 9 0 0 1 5.64 18.36L1 14"/></svg>' +
                'Refresh' +
            '</button>' +
        '</div>';

        // ── Mission banner ──
        html += '<div class="msn-banner">' +
            '<div class="msn-banner-left">' +
                '<div class="msn-banner-label">MISSION</div>' +
                '<div class="msn-banner-amount">&#8377;' + totalTarget + ' Cr</div>' +
                '<div class="msn-banner-sub">Total Revenue Target &middot; By ' + escapeHtml(deadlineLabel) + '</div>' +
            '</div>' +
            '<div class="msn-banner-center">' +
                '<div class="msn-ring-wrap">' +
                    '<svg class="msn-ring" viewBox="0 0 120 120">' +
                        '<circle cx="60" cy="60" r="52" fill="none" stroke="#1e1e21" stroke-width="10"/>' +
                        '<circle cx="60" cy="60" r="52" fill="none" stroke="' + (overallPct >= 50 ? '#22c55e' : overallPct >= 25 ? '#f59e0b' : '#ef4444') + '" stroke-width="10" stroke-linecap="round" stroke-dasharray="' + (326.7 * overallPct / 100).toFixed(1) + ' 326.7" transform="rotate(-90 60 60)"/>' +
                    '</svg>' +
                    '<div class="msn-ring-text">' +
                        '<div class="msn-ring-pct">' + overallPctRounded + '%</div>' +
                        '<div class="msn-ring-label">achieved</div>' +
                    '</div>' +
                '</div>' +
            '</div>' +
            '<div class="msn-banner-right">' +
                '<div class="msn-stat-row">' +
                    '<div class="msn-stat-val">&#8377;' + fmtCr(totalCurrent) + ' Cr</div>' +
                    '<div class="msn-stat-lbl">Revenue So Far' + (totalCurrentInr ? ' &middot; &#8377;' + fmtIndianINR(totalCurrentInr) : '') + '</div>' +
                '</div>' +
                '<div class="msn-stat-row"><div class="msn-stat-val">' + monthsLeft + ' months</div><div class="msn-stat-lbl">' + daysLeft + ' days remaining</div></div>' +
                '<div class="msn-stat-row"><div class="msn-stat-val" style="color:' + paceColor + '">&#8377;' + fmtCr(paceCr) + ' Cr</div><div class="msn-stat-lbl">On pace by ' + escapeHtml(deadlineLabel) + (dailyRunRateLakh > 0 ? ' &middot; &#8377;' + dailyRunRateLakh + ' L/day' : '') + '</div></div>' +
            '</div>' +
        '</div>';

        // ── Track cards (one per project) ──
        html += '<div class="msn-track-grid">';
        projects.forEach(function (p) {
            var pct = p.target > 0 ? (p.current / p.target) * 100 : 0;
            var pctRounded = Math.round(pct * 10) / 10;
            var isLive = p.source === 'live';
            var statusPill = isLive
                ? '<span class="msn-track-status msn-track-status-live">LIVE</span>'
                : (p.source === 'snapshot'
                    ? '<span class="msn-track-status msn-track-status-snapshot">Snapshot</span>'
                    : '<span class="msn-track-status msn-track-status-awaiting">Awaiting data</span>');

            // Growth pill — week-over-week percent change.
            var growthHtml = '';
            if (typeof p.week_growth_pct === 'number') {
                var pos = p.week_growth_pct >= 0;
                var arrow = pos ? '▲' : '▼';
                growthHtml = '<span class="msn-track-growth ' + (pos ? 'pos' : 'neg') + '">' + arrow + ' ' + Math.abs(p.week_growth_pct).toFixed(1) + '% wow</span>';
            }

            html += '<div class="msn-track-card">' +
                '<div class="msn-track-head">' +
                    '<div class="msn-track-name"><span class="msn-track-dot" style="background:' + p.color + '"></span>' + escapeHtml(p.name) + '</div>' +
                    '<div class="msn-track-head-right">' + growthHtml + statusPill + '</div>' +
                '</div>' +
                '<div class="msn-track-numbers">' +
                    '<div class="msn-track-amount"><span style="color:' + p.color + '">&#8377;' + fmtCr(p.current || 0) + '</span><span class="msn-track-amount-tgt"> / &#8377;' + p.target + ' Cr</span></div>' +
                    '<div class="msn-track-pct">' + pctRounded + '%</div>' +
                '</div>' +
                (p.current_inr ? '<div class="msn-track-rupees">&#8377;' + fmtIndianINR(p.current_inr) + '</div>' : '') +
                '<div class="msn-track-bar"><div class="msn-track-bar-fill" style="width:' + Math.min(pct, 100) + '%;background:' + p.color + '"></div></div>';

            if (isLive && p.daily_data && p.daily_data.length) {
                html += '<div class="msn-track-sparkline">' + buildSparkline(p.daily_data, p.color) + '</div>';
                var firstDate = p.daily_data[0] && p.daily_data[0].date;
                var lastDate = p.daily_data[p.daily_data.length - 1] && p.daily_data[p.daily_data.length - 1].date;
                html += '<div class="msn-track-spark-axis">' +
                    '<span>' + escapeHtml(fmtAsOf(firstDate)) + '</span>' +
                    '<span>' + escapeHtml(fmtAsOf(lastDate)) + '</span>' +
                '</div>';
                var todayLine = '';
                if (p.today_inr) {
                    todayLine = 'Latest day: <strong>&#8377;' + fmtIndianINR(p.today_inr) + '</strong>';
                }
                if (p.pace_cr) {
                    var paceLine = 'At this pace: &#8377;' + fmtCr(p.pace_cr) + ' Cr by ' + escapeHtml(deadlineLabel);
                    html += '<div class="msn-track-pace">' + (todayLine ? todayLine + ' &middot; ' : '') + paceLine + '</div>';
                } else if (todayLine) {
                    html += '<div class="msn-track-pace">' + todayLine + '</div>';
                }
            } else if (p.source === 'snapshot') {
                html += '<div class="msn-track-empty">Lifetime snapshot &middot; ' + escapeHtml(fmtAsOf(p.as_of || p.snapshot_date)) + '</div>';
            } else {
                html += '<div class="msn-track-empty">Connect ' + escapeHtml(p.name) + ' API to start tracking</div>';
            }

            html += '</div>';
        });
        html += '</div>';

        html += '</div>';
        root.innerHTML = html;

        var refreshBtn = document.getElementById('msnRefreshBtn');
        if (refreshBtn) {
            refreshBtn.onclick = function () { renderMission(); };
        }
    }

    function buildSparkline(dailyData, color) {
        if (!dailyData || !dailyData.length) return '';
        var w = 600, hh = 110, padX = 6, padY = 12;
        var values = dailyData.map(function (d) { return Number(d.revenue_inr) || 0; });
        var dates = dailyData.map(function (d) { return d.date; });
        var maxV = Math.max.apply(null, values);
        var minV = Math.min.apply(null, values);
        var range = Math.max(1, maxV - minV);
        var stepX = values.length > 1 ? (w - padX * 2) / (values.length - 1) : 0;
        var coords = values.map(function (v, i) {
            var x = padX + i * stepX;
            var y = padY + (hh - padY * 2) * (1 - (v - minV) / range);
            return { x: x, y: y, v: v, d: dates[i] };
        });
        var pts = coords.map(function (c) { return c.x.toFixed(1) + ',' + c.y.toFixed(1); });
        var areaPts = (padX).toFixed(1) + ',' + (hh - padY).toFixed(1) + ' '
            + pts.join(' ') + ' '
            + (padX + (values.length - 1) * stepX).toFixed(1) + ',' + (hh - padY).toFixed(1);
        var lastIdx = values.length - 1;
        var lastC = coords[lastIdx];
        // Baseline at zero or min — gives a reference for "is it growing?"
        var baselineY = (hh - padY).toFixed(1);
        // Per-point markers (hidden, revealed on hover via CSS)
        var dots = coords.map(function (c) {
            var label = (c.d || '') + ' · ₹' + (c.v >= 100000
                ? (c.v / 100000).toFixed(2) + ' L'
                : c.v.toLocaleString('en-IN'));
            return '<circle class="msn-spark-dot" cx="' + c.x.toFixed(1) + '" cy="' + c.y.toFixed(1)
                + '" r="3" fill="' + color + '" opacity="0"><title>' + label + '</title></circle>';
        }).join('');
        return '<svg viewBox="0 0 ' + w + ' ' + hh + '" preserveAspectRatio="none" class="msn-spark-svg">' +
            '<line x1="' + padX + '" y1="' + baselineY + '" x2="' + (w - padX) + '" y2="' + baselineY + '" stroke="#27272a" stroke-width="1" stroke-dasharray="3 3"/>' +
            '<polygon points="' + areaPts + '" fill="' + color + '" fill-opacity="0.15"/>' +
            '<polyline points="' + pts.join(' ') + '" fill="none" stroke="' + color + '" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>' +
            dots +
            '<circle cx="' + lastC.x.toFixed(1) + '" cy="' + lastC.y.toFixed(1) + '" r="3.5" fill="' + color + '"/>' +
            '<circle cx="' + lastC.x.toFixed(1) + '" cy="' + lastC.y.toFixed(1) + '" r="6" fill="' + color + '" fill-opacity="0.25"/>' +
            '</svg>';
    }

    /* ── Meta & Google Ads — delegated to MarketingModule (marketing.js) ── */
    function fmtINR(n) { return window.MarketingModule.fmtINR(n); }
    function renderMetaAds() { return window.MarketingModule.renderMetaAds(); }
    function renderGoogleAds() { return window.MarketingModule.renderGoogleAds(); }
    function showMetaUploadModal(p) { return window.MarketingModule.showMetaUploadModal(p); }
    function showGoogleAdsUploadModal(p) { return window.MarketingModule.showGoogleAdsUploadModal(p); }


    /* ── Finance — delegated to FinanceModule (finance.js) ── */
    function renderRevenue() { return window.FinanceModule.renderRevenue(); }
    function renderInvoices() { return window.FinanceModule.renderInvoices(); }
    function showInvoiceUploadModal() { return window.FinanceModule.showInvoiceUploadModal(); }
    function showManualMatchModal(a, b, c, d, e) { return window.FinanceModule.showManualMatchModal(a, b, c, d, e); }
    function showUploadStatementModal() { return window.FinanceModule.showUploadStatementModal(); }
    function showNewInvoiceModal() { return window.FinanceModule.showNewInvoiceModal(); }


    async function renderSignoff() {
        var root = document.getElementById('signoffView');
        if (!root) return;

        root.innerHTML = '<div class="signoff-wrap"><div class="kpi-status-msg">Loading sign-off status...</div></div>';

        try {
            var today = new Date();
            var dateStr = dateKey(today);
            var dateLabel = today.toLocaleDateString('en-IN', { timeZone: 'Asia/Kolkata', weekday: 'long', day: '2-digit', month: 'short' });
            var payload = await requestJson('/api/signoff-status?date=' + encodeURIComponent(dateStr));
            var items = payload.items || [];
            var dayName = payload.dayName || 'Monday';
            var signedOff = payload.signedOff === true;
            var signedOffAt = payload.signedOffAt || '';
            var canSignOff = payload.canSignOff === true;

            var pendingCount = items.filter(function (it) { return it.status === 'pending'; }).length;

            if (signedOff) {
                var timeStr = signedOffAt ? new Date(signedOffAt).toLocaleTimeString('en-IN', { timeZone: 'Asia/Kolkata', hour: 'numeric', minute: '2-digit' }) : '';
                root.innerHTML = '<div class="signoff-wrap">' +
                    '<div class="signoff-header"><h2>Daily Sign-Off</h2><div class="signoff-meta">' + escapeHtml(dateLabel) + '</div></div>' +
                    '<div class="signoff-done">' +
                    '<div class="signoff-done-icon">&#10003;</div>' +
                    '<h3>Signed off at ' + escapeHtml(timeStr) + '</h3>' +
                    '<p>All tasks completed for today. Great work!</p>' +
                    '<button type="button" class="signoff-undo-btn" id="signoffUndoBtn">Undo Sign-Off</button>' +
                    '</div></div>';
                var undoBtn = document.getElementById('signoffUndoBtn');
                if (undoBtn) {
                    undoBtn.onclick = async function () {
                        if (!confirm('Undo today\'s sign-off? You\'ll be able to sign off again later.')) return;
                        undoBtn.disabled = true;
                        undoBtn.textContent = 'Undoing...';
                        try {
                            await requestJson('/api/signoff', { method: 'DELETE' });
                            window.__dashSignedOff = false;
                            renderSignoff();
                        } catch (err) {
                            undoBtn.disabled = false;
                            undoBtn.textContent = 'Undo Sign-Off';
                            alert(err.message || 'Failed to undo sign-off');
                        }
                    };
                }
                return;
            }

            var itemsHtml = items.map(function (it) {
                var statusClass = it.status === 'complete' ? 'signoff-item-complete' : 'signoff-item-pending';
                var dotClass = it.status === 'complete' ? 'dash-status-green' : 'dash-status-red';
                var clickable = it.status === 'pending';
                var dataAttrs = '';
                if (clickable) {
                    dataAttrs = ' data-type="' + escapeHtml(it.type || '') + '"';
                    if (it.meetingKey) dataAttrs += ' data-meeting-key="' + escapeHtml(it.meetingKey || '') + '"';
                    if (it.recurrence) dataAttrs += ' data-recurrence="' + escapeHtml(it.recurrence || '') + '"';
                }
                var row = '<div class="signoff-item ' + statusClass + '"' + dataAttrs + '>' +
                    '<span class="signoff-dot ' + dotClass + '"></span>' +
                    '<div class="signoff-item-content">' +
                    '<div class="signoff-item-label">' + escapeHtml(it.label || '') + '</div>' +
                    '<div class="signoff-item-detail">' + escapeHtml(it.detail || '') + '</div>' +
                    (clickable ? '<div class="signoff-item-hint">Click to complete &gt;</div>' : '') +
                    '</div></div>';
                return row;
            }).join('');

            var summaryText = pendingCount > 0
                ? pendingCount + ' item' + (pendingCount === 1 ? '' : 's') + ' pending. Complete them to sign off.'
                : 'All items complete! You\'re good to go.';

            var btnDisabled = !canSignOff ? ' disabled' : '';
            var btnClass = canSignOff ? 'signoff-btn signoff-btn-active' : 'signoff-btn signoff-btn-disabled';

            root.innerHTML = '<div class="signoff-wrap">' +
                '<div class="signoff-header">' +
                '<div><h2>Daily Sign-Off</h2><div class="signoff-meta">' + escapeHtml(dateLabel) + '</div></div>' +
                '<button type="button" class="signoff-refresh-btn" id="signoffRefreshBtn">Refresh</button>' +
                '</div>' +
                '<p class="signoff-intro">Complete all items before signing off for the day.</p>' +
                '<div class="signoff-list">' + itemsHtml + '</div>' +
                '<div class="signoff-footer">' +
                '<p class="signoff-summary">' + escapeHtml(summaryText) + '</p>' +
                '<button type="button" class="' + btnClass + '" id="signoffSubmitBtn"' + btnDisabled + '>Sign Off</button>' +
                '</div></div>';

            var refreshBtn = document.getElementById('signoffRefreshBtn');
            if (refreshBtn) {
                refreshBtn.onclick = function () { renderSignoff(); };
            }

            var submitBtn = document.getElementById('signoffSubmitBtn');
            if (submitBtn && canSignOff) {
                submitBtn.onclick = async function () {
                    submitBtn.disabled = true;
                    submitBtn.textContent = 'Signing off...';
                    try {
                        await requestJson('/api/signoff', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({}) });
                        window.__dashSignedOff = true;
                        applySigninLockUi();
                        renderSignoff();
                    } catch (err) {
                        submitBtn.disabled = false;
                        submitBtn.textContent = 'Sign Off';
                        var pending = buildPendingItemsText(err && err.data && err.data.items);
                        alert(pending ? ('Cannot sign off yet — still pending:\n\n' + pending) : (err.message || 'Sign off failed'));
                        renderSignoff();
                    }
                };
            }

            root.querySelectorAll('.signoff-item[data-type]').forEach(function (el) {
                el.style.cursor = 'pointer';
                el.onclick = function () {
                    var type = el.getAttribute('data-type');
                    var meetingKey = el.getAttribute('data-meeting-key');
                    var recurrence = el.getAttribute('data-recurrence') || 'none';

                    if (type === 'daily_report') {
                        activeDailyPerson = String(config.userId || '');
                        if (MeetingModule && MeetingModule.switchView) MeetingModule.switchView('daily');
                        return;
                    }
                    if (type === 'weekly_timesheet') {
                        if (MeetingModule && MeetingModule.switchView) MeetingModule.switchView('weeklyTimesheet');
                        return;
                    }
                    if (type === 'claude_context') {
                        if (MeetingModule && MeetingModule.switchView) MeetingModule.switchView('claude_context');
                        return;
                    }
                    if ((type === 'agenda' || type === 'notes') && meetingKey && MeetingModule) {
                        var tab = type === 'agenda' ? 'agenda' : 'notes';
                        if (MeetingModule.openMeetingById) MeetingModule.openMeetingById(meetingKey);
                        MeetingModule.switchView('meetings');
                        setTimeout(function () {
                            var tabEl = document.querySelector('.mtg-tab[data-tab="' + tab + '"]');
                            if (tabEl) tabEl.click();
                        }, 150);
                    }
                };
            });
        } catch (err) {
            root.innerHTML = '<div class="signoff-wrap"><div class="kpi-status-msg">Unable to load sign-off status: ' + escapeHtml(err.message || 'Request failed') + '</div></div>';
        }
    }

    // Tessa chat storage & core functions — now in tessa-chat.js

    // tessaFetchMessages, tessaCreateNewChat — now in tessa-chat.js

    // Task functions extracted to tasks.js — accessible via window.TasksModule

    function tessaClosePlusMenu() {
        var menu = document.getElementById('tessaPlusMenu');
        if (menu) menu.classList.add('hidden');
    }

    function renderTasks(filter) { return TasksModule.renderTasks(filter); }
    function tessaOpenTaskModal() { return TasksModule.tessaOpenTaskModal(); }

    function renderTessa() { return TessaChatModule.renderTessa(); }
    function tessaAutoSendSignoff() { return TessaChatModule.tessaAutoSendSignoff(); }

    function syncPortalHash() {
        var view = document.querySelector('.top-nav-link.active');
        var viewName = (view && view.getAttribute('data-view')) || 'meetings';
        if (viewName === 'meetings') {
            if (window.MeetingModule && window.MeetingModule.syncHash) {
                window.MeetingModule.syncHash();
            }
            return;
        }
        var hash = (location.hash || '').replace(/^#/, '');
        var params = {};
        hash.split('&').forEach(function (pair) {
            var parts = pair.split('=');
            if (parts.length === 2) {
                params[decodeURIComponent(parts[0])] = decodeURIComponent(parts[1]);
            }
        });
        params.view = viewName;
        if (viewName === 'daily') {
            if (activeDailyPerson) params.dailyPerson = activeDailyPerson;
            if (currentReportDate) params.dailyDate = dateKey(currentReportDate);
        } else if (viewName === 'mkpi') {
            var personId = activeKpiPerson;
            if (personId) params.kpiPerson = personId;
            if (currentKpiWeekStart) params.kpiWeek = dateKey(currentKpiWeekStart);
        }
        var newHash = Object.keys(params).map(function (key) {
            return encodeURIComponent(key) + '=' + encodeURIComponent(params[key]);
        }).join('&');
        location.hash = newHash;
    }

    function restorePortalHash() {
        var hash = (location.hash || '').replace(/^#/, '');
        if (!hash) return false;
        var params = {};
        hash.split('&').forEach(function (pair) {
            var parts = pair.split('=');
            if (parts.length === 2) {
                params[decodeURIComponent(parts[0])] = decodeURIComponent(parts[1]);
            }
        });
        var restored = false;
        if (params.dailyPerson) {
            activeDailyPerson = params.dailyPerson;
            restored = true;
        }
        if (params.dailyDate) {
            try {
                var dailyDate = new Date(params.dailyDate + 'T00:00:00');
                if (!isNaN(dailyDate.getTime())) {
                    currentReportDate = dailyDate;
                    restored = true;
                }
            } catch (e) {
                console.warn('Invalid dailyDate in hash:', params.dailyDate);
            }
        }
        if (params.kpiPerson) {
            if (params.view === 'mkpi') {
                activeKpiPerson = params.kpiPerson;
            }
            restored = true;
        }
        if (params.kpiWeek) {
            try {
                var kpiWeekDate = new Date(params.kpiWeek + 'T00:00:00');
                if (!isNaN(kpiWeekDate.getTime())) {
                    currentKpiWeekStart = startOfWeek(kpiWeekDate);
                    restored = true;
                }
            } catch (e) {
                console.warn('Invalid kpiWeek in hash:', params.kpiWeek);
            }
        }
        return restored;
    }

    // ── Floating Tessa Chat Widget ──
    (function initTessaWidget() {
        var fab = document.getElementById('tessaFab');
        var widget = document.getElementById('tessaWidget');
        var closeBtn = document.getElementById('tessaWidgetClose');
        var input = document.getElementById('tessaWidgetInput');
        var sendBtn = document.getElementById('tessaWidgetSend');
        var msgContainer = document.getElementById('tessaWidgetMessages');
        if (!fab || !widget) return;

        var widgetChatId = null;
        var widgetMessages = [];
        var widgetBusy = false;

        fab.onclick = function () {
            var isOpen = !widget.classList.contains('hidden');
            if (isOpen) {
                widget.classList.add('hidden');
                fab.classList.remove('active');
            } else {
                widget.classList.remove('hidden');
                fab.classList.add('active');
                if (input) input.focus();
            }
        };

        if (closeBtn) closeBtn.onclick = function () {
            widget.classList.add('hidden');
            fab.classList.remove('active');
        };

        // Quick action chips
        widget.querySelectorAll('.tessa-widget-chip').forEach(function (chip) {
            chip.onclick = function () {
                var msg = chip.getAttribute('data-msg');
                if (msg && !widgetBusy) {
                    if (input) input.value = msg;
                    sendWidgetMessage(msg);
                }
            };
        });

        // Send handler
        if (sendBtn) sendBtn.onclick = function () {
            var text = (input.value || '').trim();
            if (!text || widgetBusy) return;
            sendWidgetMessage(text);
        };

        if (input) {
            input.onkeydown = function (e) {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    sendBtn.click();
                }
            };
            input.oninput = function () {
                input.style.height = 'auto';
                input.style.height = Math.min(input.scrollHeight, 80) + 'px';
            };
        }

        function sendWidgetMessage(text) {
            if (widgetBusy) return;
            widgetBusy = true;
            input.value = '';
            input.style.height = 'auto';

            // Remove welcome if present
            var welcome = msgContainer.querySelector('.tessa-widget-welcome');
            if (welcome) welcome.remove();
            // Hide chips after first message
            var chips = widget.querySelector('.tessa-widget-chips');
            if (chips) chips.style.display = 'none';

            // Add user message
            widgetMessages.push({ role: 'user', content: text });
            appendWidgetMsg('user', text);

            // Show loading with spinning avatar + search step text (matching main Tessa chat)
            var typingEl = document.createElement('div');
            typingEl.className = 'tw-msg tw-msg-tessa';
            typingEl.id = 'twTyping';
            typingEl.innerHTML = '<span class="tw-msg-avatar tw-avatar-loading">T</span><div class="tw-msg-bubble tw-msg-searching"><span class="tw-search-step"></span></div>';
            msgContainer.appendChild(typingEl);
            msgContainer.scrollTop = msgContainer.scrollHeight;

            // Cycle through contextual search steps
            var twSteps = twDetectSteps(text);
            var twStepIdx = 0;
            var twStepEl = typingEl.querySelector('.tw-search-step');
            if (twStepEl) twStepEl.textContent = twSteps[0];
            var twStepInterval = twSteps.length > 1 ? setInterval(function () {
                twStepIdx++;
                if (twStepIdx >= twSteps.length || !twStepEl) { clearInterval(twStepInterval); return; }
                twStepEl.style.opacity = '0';
                setTimeout(function () {
                    if (twStepEl) { twStepEl.textContent = twSteps[twStepIdx]; twStepEl.style.opacity = '1'; }
                }, 300);
            }, 2000) : null;

            // Call Tessa API
            var payload = { messages: widgetMessages.map(function (m) { return { role: m.role, content: m.content }; }) };
            if (widgetChatId) payload.chat_id = widgetChatId;

            fetch('/api/tessa/chat', {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify(payload)
            }).then(function (r) { return r.json(); }).then(function (data) {
                if (twStepInterval) clearInterval(twStepInterval);
                var typing = document.getElementById('twTyping');
                if (typing) typing.remove();

                if (data.ok && data.reply) {
                    widgetChatId = data.chat_id || widgetChatId;
                    widgetMessages.push({ role: 'assistant', content: data.reply });
                    // Typewriter effect matching main Tessa chat
                    appendWidgetMsgAnimated('tessa', data.reply);
                    if (data.task_created && document.getElementById('tasksGridBody')) {
                        renderTasks();
                    }
                } else {
                    appendWidgetMsg('tessa', 'Sorry, something went wrong. Please try again.');
                }
                widgetBusy = false;
            }).catch(function () {
                if (twStepInterval) clearInterval(twStepInterval);
                var typing = document.getElementById('twTyping');
                if (typing) typing.remove();
                appendWidgetMsg('tessa', 'Connection error. Please try again.');
                widgetBusy = false;
            });
        }

        function twDetectSteps(msg) {
            var m = (msg || '').toLowerCase();
            if (/task|assign/.test(m)) return ['Processing your request...', 'Setting up task...'];
            if (/pending|what.*do/.test(m)) return ['Checking your pending work...'];
            if (/meeting|agenda/.test(m)) return ['Reviewing your meetings...'];
            if (/sign.?off/.test(m)) return ['Checking your pending items...', 'Preparing sign-off...'];
            if (/sign.?in|good morning|morning/.test(m)) return ['Preparing your morning briefing...'];
            if (/leave|sick|wfh/.test(m)) return ['Processing your leave request...'];
            return ['Thinking...'];
        }

        function appendWidgetMsgAnimated(role, content) {
            var isUser = role === 'user';
            var cls = isUser ? 'tw-msg tw-msg-user' : 'tw-msg tw-msg-tessa';
            var avatarLetter = isUser ? ((config.userName || 'U').charAt(0).toUpperCase()) : 'T';
            var el = document.createElement('div');
            el.className = cls;
            el.innerHTML = '<span class="tw-msg-avatar">' + avatarLetter + '</span>' +
                '<div class="tw-msg-bubble"></div>';
            msgContainer.appendChild(el);

            var bubble = el.querySelector('.tw-msg-bubble');
            var formatted = formatWidgetReply(content, false);
            var len = formatted.length;
            var duration = Math.min(1800, Math.max(400, len * 3));
            var startTime = null;
            var lastPos = 0;

            function frame(ts) {
                if (!startTime) startTime = ts;
                var elapsed = ts - startTime;
                var t = Math.min(1, elapsed / duration);
                var eased = t < 0.5 ? 2 * t * t : 1 - Math.pow(-2 * t + 2, 2) / 2;
                var pos = Math.round(eased * len);
                if (pos !== lastPos) {
                    lastPos = pos;
                    bubble.innerHTML = formatted.slice(0, pos);
                    msgContainer.scrollTop = msgContainer.scrollHeight;
                }
                if (pos < len) {
                    requestAnimationFrame(frame);
                } else {
                    // After animation: show people suggestions if Tessa is asking about assignment
                    if (!isUser) showPeopleSuggestionsIfNeeded(content);
                }
            }
            requestAnimationFrame(frame);
        }

        function showPeopleSuggestionsIfNeeded(reply) {
            var lower = (reply || '').toLowerCase();
            // Only show when Tessa is specifically asking WHO to assign to — not on any other question
            var lastLine = lower.trim().split('\n').pop().trim();
            if (!/who.*(assign|should)/.test(lastLine) && !/assigned to\s*\?/.test(lastLine)) return;

            // Remove any existing suggestions
            var existing = msgContainer.querySelector('.tw-people-suggestions');
            if (existing) existing.remove();

            var teamMembers = config.TEAM_MEMBERS || [];
            var allMembers = config.MODAL_PEOPLE || [];
            var myId = config.userId || 0;

            // Filter out self
            teamMembers = teamMembers.filter(function (p) { return p.id !== myId; });
            var otherMembers = allMembers.filter(function (p) {
                return p.id !== myId && !teamMembers.find(function (t) { return t.id === p.id; });
            });

            var suggestionsEl = document.createElement('div');
            suggestionsEl.className = 'tw-people-suggestions';

            var html = '';
            if (teamMembers.length > 0) {
                html += '<div class="tw-people-label">Your Team</div>';
                html += '<div class="tw-people-chips">';
                teamMembers.forEach(function (p) {
                    html += '<button type="button" class="tw-person-chip" data-name="' + escapeHtml(p.name) + '">' +
                        '<span class="tw-person-initial">' + (p.name || '?').charAt(0).toUpperCase() + '</span>' +
                        escapeHtml(p.name) +
                    '</button>';
                });
                html += '</div>';
            }

            html += '<button type="button" class="tw-show-all-btn" id="twShowAllBtn">Show all members</button>';
            html += '<div class="tw-people-chips tw-all-members hidden" id="twAllMembers">';
            otherMembers.forEach(function (p) {
                html += '<button type="button" class="tw-person-chip tw-person-other" data-name="' + escapeHtml(p.name) + '">' +
                    '<span class="tw-person-initial">' + (p.name || '?').charAt(0).toUpperCase() + '</span>' +
                    escapeHtml(p.name) +
                '</button>';
            });
            html += '</div>';

            suggestionsEl.innerHTML = html;
            msgContainer.appendChild(suggestionsEl);
            msgContainer.scrollTop = msgContainer.scrollHeight;

            // Show all toggle
            var showAllBtn = document.getElementById('twShowAllBtn');
            if (showAllBtn) {
                showAllBtn.onclick = function () {
                    var allEl = document.getElementById('twAllMembers');
                    if (allEl) {
                        allEl.classList.toggle('hidden');
                        showAllBtn.textContent = allEl.classList.contains('hidden') ? 'Show all members' : 'Hide others';
                    }
                    msgContainer.scrollTop = msgContainer.scrollHeight;
                };
            }

            // Click on a person chip = send their name as message
            suggestionsEl.querySelectorAll('.tw-person-chip').forEach(function (chip) {
                chip.onclick = function () {
                    var name = chip.getAttribute('data-name');
                    if (name && !widgetBusy) {
                        suggestionsEl.remove();
                        sendWidgetMessage(name);
                    }
                };
            });
        }

        function appendWidgetMsg(role, content) {
            var isUser = role === 'user';
            var cls = isUser ? 'tw-msg tw-msg-user' : 'tw-msg tw-msg-tessa';
            var avatarLetter = isUser ? ((config.userName || 'U').charAt(0).toUpperCase()) : 'T';
            var el = document.createElement('div');
            el.className = cls;
            el.innerHTML = '<span class="tw-msg-avatar">' + avatarLetter + '</span>' +
                '<div class="tw-msg-bubble">' + formatWidgetReply(content, isUser) + '</div>';
            msgContainer.appendChild(el);
            msgContainer.scrollTop = msgContainer.scrollHeight;
        }

        function formatWidgetReply(text, isUser) {
            if (isUser) return escapeHtml(text);
            // Simple markdown: bold, links, line breaks
            var s = escapeHtml(text);
            s = s.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
            s = s.replace(/\*(.+?)\*/g, '<strong>$1</strong>');
            s = s.replace(/\n/g, '<br>');
            return s;
        }
    })();

    /* ── AI Meeting Scheduler ─────────────────────────────────────── */
    function renderSchedule() {
        var root = document.getElementById('scheduleView');
        if (!root) return;
        var people = config.MODAL_PEOPLE || [];

        // Build time options (9:00 AM to 7:00 PM in 30-min steps)
        var timeOpts = '';
        for (var th = 9; th <= 18; th++) {
            for (var tm = 0; tm < 60; tm += 30) {
                if (th === 18 && tm > 0) break;
                var h12 = th > 12 ? th - 12 : (th === 0 ? 12 : th);
                var ap = th >= 12 ? 'PM' : 'AM';
                var val = (th < 10 ? '0' : '') + th + ':' + (tm === 0 ? '00' : tm);
                var lbl = h12 + ':' + (tm === 0 ? '00' : tm) + ' ' + ap;
                var sel = (th === 10 && tm === 0) ? ' selected' : '';
                timeOpts += '<option value="' + val + '"' + sel + '>' + lbl + '</option>';
            }
        }

        // Build attendee chips HTML
        var chipsHtml = '';
        for (var pi = 0; pi < people.length; pi++) {
            chipsHtml += '<span class="sched-person" data-id="' + people[pi].id + '" style="display:inline-block;padding:5px 12px;margin:3px;border-radius:16px;border:1px solid #3f3f46;background:transparent;color:#9ca3af;font-size:12px;cursor:pointer;transition:all 0.15s">' + escapeHtml(people[pi].name) + '</span>';
        }

        // Default date = tomorrow
        var tmrw = new Date(); tmrw.setDate(tmrw.getDate() + 1);
        var defDate = tmrw.getFullYear() + '-' + String(tmrw.getMonth() + 1).padStart(2, '0') + '-' + String(tmrw.getDate()).padStart(2, '0');

        root.innerHTML = '<div class="emp-wrap">' +
            '<div class="emp-header"><h2 class="emp-title">Schedule Meeting</h2></div>' +
            '<div style="max-width:720px">' +
                // Title
                '<div style="margin-bottom:14px">' +
                    '<label style="font-size:12px;color:#6b7280;display:block;margin-bottom:4px">Meeting Title</label>' +
                    '<input type="text" id="schedTitle" class="prof-input" placeholder="e.g. Sprint Review, Design Sync" style="font-size:14px">' +
                '</div>' +
                // Attendees
                '<div style="margin-bottom:14px">' +
                    '<label style="font-size:12px;color:#6b7280;display:block;margin-bottom:4px">Attendees <span id="schedAttendeeCnt" style="color:#3b82f6">(0 selected)</span></label>' +
                    '<input type="text" id="schedPeopleSearch" class="prof-input" placeholder="Search people..." style="font-size:13px;margin-bottom:6px">' +
                    '<div id="schedPeopleChips" style="max-height:120px;overflow-y:auto;padding:4px 0">' + chipsHtml + '</div>' +
                '</div>' +
                // Date + Time + Mode row
                '<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px">' +
                    '<div style="flex:1;min-width:140px">' +
                        '<label style="font-size:12px;color:#6b7280;display:block;margin-bottom:4px">Date</label>' +
                        '<input type="date" id="schedDate" class="prof-input" value="' + defDate + '" style="font-size:13px">' +
                    '</div>' +
                    '<div style="flex:1;min-width:140px">' +
                        '<label style="font-size:12px;color:#6b7280;display:block;margin-bottom:4px">Preferred Time</label>' +
                        '<select id="schedTime" class="prof-input" style="font-size:13px">' + timeOpts + '</select>' +
                    '</div>' +
                    '<div style="flex:1;min-width:180px">' +
                        '<label style="font-size:12px;color:#6b7280;display:block;margin-bottom:4px">Time Mode</label>' +
                        '<div style="display:flex;border-radius:8px;overflow:hidden;border:1px solid #3f3f46">' +
                            '<button id="schedModeFlexible" class="sched-mode active" style="flex:1;padding:8px;font-size:12px;border:none;background:#1a1a2e;color:#a5b4fc;cursor:pointer;font-weight:600">Flexible</button>' +
                            '<button id="schedModeFixed" class="sched-mode" style="flex:1;padding:8px;font-size:12px;border:none;background:transparent;color:#6b7280;cursor:pointer">Fixed (Priority)</button>' +
                        '</div>' +
                    '</div>' +
                '</div>' +
                // Find button
                '<button id="schedFindBtn" style="width:100%;padding:12px;border-radius:8px;border:none;background:#3b82f6;color:#fff;font-size:14px;font-weight:600;cursor:pointer;margin-bottom:20px">Find Available Slots</button>' +
                '<div id="schedResult"></div>' +
            '</div>' +
            '<div style="max-width:720px;margin-top:32px">' +
                '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">' +
                    '<h3 style="font-size:16px;font-weight:600;color:#f0f0f0;margin:0">Your Scheduled Meetings</h3>' +
                '</div>' +
                '<div id="schedMeetingsList"></div>' +
            '</div>' +
        '</div>';

        var titleEl = document.getElementById('schedTitle');
        var dateEl = document.getElementById('schedDate');
        var timeEl = document.getElementById('schedTime');
        var findBtn = document.getElementById('schedFindBtn');
        var resultEl = document.getElementById('schedResult');
        var searchEl = document.getElementById('schedPeopleSearch');
        var cntEl = document.getElementById('schedAttendeeCnt');
        var selectedIds = [];
        var timeMode = 'flexible';

        // Floor the date picker at today (users are IST) so past dates can't be chosen
        (function () {
            var t = new Date();
            dateEl.min = t.getFullYear() + '-' + String(t.getMonth() + 1).padStart(2, '0') + '-' + String(t.getDate()).padStart(2, '0');
        })();

        // Time mode toggle
        document.getElementById('schedModeFlexible').addEventListener('click', function () {
            timeMode = 'flexible';
            this.style.background = '#1a1a2e'; this.style.color = '#a5b4fc'; this.style.fontWeight = '600';
            document.getElementById('schedModeFixed').style.background = 'transparent';
            document.getElementById('schedModeFixed').style.color = '#6b7280';
            document.getElementById('schedModeFixed').style.fontWeight = 'normal';
        });
        document.getElementById('schedModeFixed').addEventListener('click', function () {
            timeMode = 'fixed';
            this.style.background = '#1a1a2e'; this.style.color = '#f59e0b'; this.style.fontWeight = '600';
            document.getElementById('schedModeFlexible').style.background = 'transparent';
            document.getElementById('schedModeFlexible').style.color = '#6b7280';
            document.getElementById('schedModeFlexible').style.fontWeight = 'normal';
        });

        // Attendee chip click
        root.querySelectorAll('.sched-person').forEach(function (chip) {
            chip.addEventListener('click', function () {
                var id = Number(chip.getAttribute('data-id'));
                var idx = selectedIds.indexOf(id);
                if (idx > -1) {
                    selectedIds.splice(idx, 1);
                    chip.style.background = 'transparent';
                    chip.style.color = '#9ca3af';
                    chip.style.borderColor = '#3f3f46';
                } else {
                    selectedIds.push(id);
                    chip.style.background = '#3b82f620';
                    chip.style.color = '#60a5fa';
                    chip.style.borderColor = '#3b82f6';
                }
                cntEl.textContent = '(' + selectedIds.length + ' selected)';
            });
        });

        // Search filter
        searchEl.addEventListener('input', function () {
            var q = searchEl.value.toLowerCase();
            root.querySelectorAll('.sched-person').forEach(function (chip) {
                var name = chip.textContent.toLowerCase();
                chip.style.display = (!q || name.includes(q)) ? 'inline-block' : 'none';
            });
        });

        findBtn.addEventListener('click', doAnalyze);

        loadScheduledMeetings();

        function loadScheduledMeetings() {
            var listEl = document.getElementById('schedMeetingsList');
            if (!listEl) return;
            listEl.innerHTML = '<div style="text-align:center;padding:20px;color:#6b7280;font-size:13px">Loading...</div>';

            fetch('/api/meetings/schedule/list', {
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            }).then(function (r) { return r.json(); }).then(function (data) {
                if (!data.ok || !data.meetings || data.meetings.length === 0) {
                    listEl.innerHTML = '<div style="text-align:center;padding:24px;color:#6b7280;font-size:13px;border:1px dashed #3f3f46;border-radius:10px">No scheduled meetings yet</div>';
                    return;
                }
                var recLabels = { none: 'One-time', weekly: 'Weekly', daily_weekdays: 'Mon–Fri', tue_to_fri: 'Tue–Fri', mon_thu: 'Mon & Thu', mon_wed_fri: 'Mon, Wed & Fri', monthly_first: '1st weekday/mo' };
                var html = '';
                for (var mi = 0; mi < data.meetings.length; mi++) {
                    var mt = data.meetings[mi];
                    var recLabel = recLabels[mt.recurrence] || mt.recurrence;
                    html += '<div class="sched-meeting-row" data-id="' + mt.id + '" style="display:flex;align-items:center;gap:10px;padding:12px 14px;background:#1a1a2e;border-radius:10px;margin-bottom:8px">' +
                        '<div style="flex:1;min-width:0">' +
                            '<div style="font-size:14px;color:#f0f0f0;font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">' + escapeHtml(mt.title) + '</div>' +
                            '<div style="font-size:12px;color:#6b7280;margin-top:2px">' +
                                escapeHtml(mt.day_of_week) + ' · ' + escapeHtml(mt.time) +
                                ' · <span style="color:#8b5cf6">' + escapeHtml(recLabel) + '</span>' +
                                (mt.attendees.length ? ' · ' + escapeHtml(mt.attendees.join(', ')) : '') +
                            '</div>' +
                        '</div>' +
                        '<button class="sched-del-btn" data-id="' + mt.id + '" data-title="' + escapeHtml(mt.title) + '" style="padding:6px 12px;border-radius:6px;border:1px solid #ef4444;background:transparent;color:#ef4444;font-size:12px;cursor:pointer;white-space:nowrap;transition:all 0.15s" onmouseover="this.style.background=\'#ef444420\'" onmouseout="this.style.background=\'transparent\'">Delete</button>' +
                    '</div>';
                }
                listEl.innerHTML = html;

                listEl.querySelectorAll('.sched-del-btn').forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        var meetingId = btn.getAttribute('data-id');
                        var meetingTitle = btn.getAttribute('data-title');
                        if (!confirm('Delete "' + meetingTitle + '"? This cannot be undone.')) return;

                        btn.disabled = true;
                        btn.textContent = 'Deleting...';

                        fetch('/api/meetings/schedule/delete', {
                            method: 'POST', credentials: 'same-origin',
                            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                            body: JSON.stringify({ id: Number(meetingId) })
                        }).then(function (r) { return r.json(); }).then(function (res) {
                            if (res.ok) {
                                var row = btn.closest('.sched-meeting-row');
                                if (row) {
                                    row.style.transition = 'opacity 0.3s';
                                    row.style.opacity = '0';
                                    setTimeout(function () { row.remove(); loadScheduledMeetings(); }, 300);
                                }
                            } else {
                                btn.disabled = false;
                                btn.textContent = 'Delete';
                                alert(res.error || 'Failed to delete meeting');
                            }
                        }).catch(function () { btn.disabled = false; btn.textContent = 'Delete'; });
                    });
                });
            }).catch(function () {
                listEl.innerHTML = '<div style="text-align:center;padding:20px;color:#f87171;font-size:13px">Failed to load meetings</div>';
            });
        }

        function doAnalyze() {
            var title = titleEl.value.trim();
            if (!title) { titleEl.focus(); return alert('Please enter a meeting title'); }
            if (selectedIds.length === 0) return alert('Please select at least one attendee');

            findBtn.disabled = true;
            findBtn.textContent = 'Checking calendars...';
            resultEl.innerHTML = '<div style="text-align:center;padding:40px;color:#a5b4fc">' +
                '<div style="font-size:24px;margin-bottom:8px">🔍</div>' +
                'Tessa is checking everyone\'s calendar...' +
            '</div>';

            var payload = {
                title: title,
                attendee_ids: selectedIds,
                date: dateEl.value,
                time: timeEl.value,
                time_mode: timeMode,
                duration: 30
            };

            fetch('/api/meetings/schedule/analyze', {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify(payload)
            }).then(function (r) { return r.json(); }).then(function (data) {
                findBtn.disabled = false;
                findBtn.textContent = 'Find Available Slots';
                if (data.ok) {
                    renderResult(data);
                } else {
                    resultEl.innerHTML = '<div style="padding:20px;color:#f87171;text-align:center">' + escapeHtml(data.error || 'Failed to analyze') + '</div>';
                }
            }).catch(function () {
                findBtn.disabled = false;
                findBtn.textContent = 'Find Available Slots';
                resultEl.innerHTML = '<div style="padding:20px;color:#f87171;text-align:center">Request failed. Please try again.</div>';
            });
        }

        function renderResult(data) {
            var p = data.parsed;
            var users = data.users || [];
            var suggested = data.suggested || [];
            var timePassed = data.time_passed;

            var html = '';

            // Parsed summary
            var modeBadge = p.time_mode === 'fixed'
                ? '<span style="font-size:11px;padding:3px 8px;border-radius:5px;background:#ef444420;color:#f87171;font-weight:600">Fixed (Priority)</span>'
                : '<span style="font-size:11px;padding:3px 8px;border-radius:5px;background:#10b98120;color:#34d399">Flexible</span>';
            html += '<div style="padding:14px 16px;background:#1a1a2e;border-radius:10px;margin-bottom:16px">' +
                '<div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center">' +
                    '<span style="font-size:14px;color:#f0f0f0;font-weight:600">' + escapeHtml(p.title) + '</span>' +
                    '<span style="font-size:11px;padding:3px 8px;border-radius:5px;background:#3b82f620;color:#3b82f6">' + escapeHtml(p.day + ', ' + p.date) + '</span>' +
                    (p.preferred_time ? '<span style="font-size:11px;padding:3px 8px;border-radius:5px;background:#f59e0b20;color:#f59e0b">' + escapeHtml(p.preferred_time) + '</span>' : '') +
                    modeBadge +
                '</div>' +
                '<div style="margin-top:6px;display:flex;gap:6px;flex-wrap:wrap">' +
                    p.attendees.map(function (n) { return '<span style="font-size:12px;padding:2px 8px;border-radius:12px;background:#8b5cf620;color:#8b5cf6">' + escapeHtml(n) + '</span>'; }).join('') +
                '</div>' +
                (p.unresolved && p.unresolved.length ? '<div style="margin-top:6px;font-size:11px;color:#f87171">Could not find: ' + escapeHtml(p.unresolved.join(', ')) + '</div>' : '') +
            '</div>';

            // Availability grid
            html += '<div style="margin-bottom:16px">' +
                '<div style="font-size:14px;font-weight:600;color:#f0f0f0;margin-bottom:10px">Availability</div>' +
                '<div style="overflow-x:auto">' +
                '<div style="min-width:600px">';

            // Time header (9 AM to 7 PM)
            html += '<div style="display:flex;margin-left:100px;margin-bottom:4px">';
            for (var h = 9; h <= 18; h++) {
                var lbl = h > 12 ? (h - 12) + 'PM' : (h === 12 ? '12PM' : h + 'AM');
                html += '<div style="flex:1;font-size:10px;color:#6b7280;text-align:left">' + lbl + '</div>';
            }
            html += '</div>';

            // "Now" marker / past-shading for today (IST values come from the server
            // so the grid matches the slot filtering exactly).
            var totalMin = (19 - 9) * 60; // 600 min (9 AM–7 PM grid)
            var isToday = !!data.is_today;
            var nowMin = (typeof data.now_minutes === 'number') ? data.now_minutes : 0;
            var pastPct = isToday ? Math.max(0, Math.min(100, ((Math.min(nowMin, 1140) - 540) / totalMin) * 100)) : 0;
            var pastOverlayHtml = (isToday && pastPct > 0)
                ? '<div title="Already passed" style="position:absolute;left:0;top:0;width:' + pastPct + '%;height:100%;background:#9ca3af22;z-index:1;pointer-events:none"></div>'
                : '';
            var nowLineHtml = (isToday && pastPct > 0 && pastPct < 100)
                ? '<div title="Now" style="position:absolute;left:' + pastPct + '%;top:0;width:2px;height:100%;background:#f59e0b;z-index:3;pointer-events:none"></div>'
                : '';

            // Per-user rows
            for (var u = 0; u < users.length; u++) {
                var usr = users[u];
                html += '<div style="display:flex;align-items:center;margin-bottom:3px">' +
                    '<div style="width:100px;font-size:12px;color:#d1d5db;white-space:nowrap;overflow:hidden;text-overflow:ellipsis" title="' + escapeHtml(usr.name) + '">' +
                        escapeHtml(usr.name.split(' ')[0]) + (usr.is_me ? ' (you)' : '') +
                    '</div>' +
                    '<div style="flex:1;height:28px;background:#0f0f1a;border-radius:4px;position:relative;overflow:hidden">' +
                    pastOverlayHtml;

                // Draw busy blocks (above the past-shading so they stay visible)
                for (var b = 0; b < usr.busy.length; b++) {
                    var block = usr.busy[b];
                    var left = ((block.start - 540) / totalMin) * 100;
                    var width = ((block.end - block.start) / totalMin) * 100;
                    if (left < 0) { width += left; left = 0; }
                    if (left + width > 100) width = 100 - left;
                    html += '<div title="' + escapeHtml(block.title + ' (' + block.time + ')') + '" style="position:absolute;z-index:2;left:' + left + '%;width:' + width + '%;height:100%;background:#ef444480;border-radius:3px;display:flex;align-items:center;justify-content:center;font-size:9px;color:#fca5a5;overflow:hidden;white-space:nowrap;padding:0 2px">' +
                        escapeHtml(block.title.length > 12 ? block.title.substring(0, 12) + '..' : block.title) +
                    '</div>';
                }

                html += nowLineHtml + '</div></div>';
            }

            html += '</div></div></div>';
            if (isToday && pastPct > 0) {
                html += '<div style="font-size:11px;color:#6b7280;margin:-8px 0 16px 100px">⏱️ Shaded area &amp; amber line = already passed today.</div>';
            }

            // Suggested slots
            // Fixed mode — show clash resolution panel
            var fixedClashes = data.fixed_clashes || [];
            if (p.time_mode === 'fixed' && timePassed) {
                html += '<div style="padding:16px;background:#f59e0b15;border:1px solid #f59e0b40;border-radius:10px;margin-bottom:16px;text-align:center">' +
                    '<div style="font-size:20px;margin-bottom:6px">⏰</div>' +
                    '<div style="font-size:14px;font-weight:600;color:#fbbf24">' + escapeHtml(p.preferred_time) + ' has already passed today</div>' +
                    '<div style="font-size:12px;color:#6b7280;margin-top:4px">Pick a later time or another date to book.</div>' +
                '</div>';
            } else if (p.time_mode === 'fixed' && fixedClashes.length > 0) {
                html += '<div style="padding:14px 16px;background:#f59e0b10;border:1px solid #f59e0b40;border-radius:10px;margin-bottom:16px">' +
                    '<div style="font-size:14px;font-weight:600;color:#fbbf24;margin-bottom:10px">⚠️ Clashes at ' + escapeHtml(p.preferred_time) + ' — Resolve to book</div>';
                for (var fc = 0; fc < fixedClashes.length; fc++) {
                    var clash = fixedClashes[fc];
                    html += '<div class="sched-fixed-clash" data-key="' + escapeHtml(clash.meeting_key) + '" style="display:flex;align-items:center;gap:8px;padding:10px 12px;background:#1a1a2e;border-radius:8px;margin-bottom:6px;flex-wrap:wrap">' +
                        '<div style="flex:1;min-width:150px">' +
                            '<div style="font-size:13px;color:#f0f0f0;font-weight:500">' + escapeHtml(clash.title) + '</div>' +
                            '<div style="font-size:11px;color:#6b7280">' + escapeHtml(clash.user_name) + ' · ' + escapeHtml(clash.time) + '</div>' +
                        '</div>' +
                        '<button class="sched-clash-skip" data-key="' + escapeHtml(clash.meeting_key) + '" data-date="' + escapeHtml(p.date) + '" style="font-size:11px;padding:5px 10px;border-radius:5px;border:1px solid #f59e0b;background:transparent;color:#f59e0b;cursor:pointer">Skip for this day</button>' +
                        '<button class="sched-clash-resched" data-key="' + escapeHtml(clash.meeting_key) + '" data-date="' + escapeHtml(p.date) + '" style="font-size:11px;padding:5px 10px;border-radius:5px;border:1px solid #8b5cf6;background:transparent;color:#8b5cf6;cursor:pointer">Suggest new time</button>' +
                        '<div class="sched-resched-result" data-key="' + escapeHtml(clash.meeting_key) + '" style="width:100%;display:none;margin-top:6px"></div>' +
                    '</div>';
                }
                html += '<button class="sched-slot-btn" data-time="' + escapeHtml(p.preferred_time) + '" data-end="" style="width:100%;margin-top:10px;padding:10px;border-radius:8px;border:none;background:#f59e0b;color:#000;font-size:13px;font-weight:600;cursor:pointer">Book Anyway at ' + escapeHtml(p.preferred_time) + ' (with clashes)</button>' +
                '</div>';
            } else if (p.time_mode === 'fixed' && fixedClashes.length === 0) {
                html += '<div style="padding:16px;background:#10b98120;border:1px solid #10b98140;border-radius:10px;margin-bottom:16px;text-align:center">' +
                    '<div style="font-size:20px;margin-bottom:6px">✅</div>' +
                    '<div style="font-size:14px;font-weight:600;color:#34d399">All clear at ' + escapeHtml(p.preferred_time) + '!</div>' +
                    '<div style="font-size:12px;color:#6b7280;margin-top:4px">Everyone is free at this time</div>' +
                    '<button class="sched-slot-btn" data-time="' + escapeHtml(p.preferred_time) + '" data-end="" style="margin-top:12px;padding:10px 24px;border-radius:8px;border:none;background:#10b981;color:#fff;font-size:14px;font-weight:600;cursor:pointer">Book Now</button>' +
                '</div>';
            }

            html += '<div style="font-size:14px;font-weight:600;color:#f0f0f0;margin-bottom:10px">' + (p.time_mode === 'fixed' ? 'Other Available Slots' : 'Suggested Time Slots') + '</div>' +
                '<div id="schedSlots" style="display:flex;flex-wrap:wrap;gap:8px">';

            if (suggested.length === 0) {
                html += '<div style="width:100%;padding:18px;border:1px dashed #3f3f46;border-radius:10px;text-align:center;color:#6b7280;font-size:13px">No free times left for this day — try a later time or another date.</div>';
            }

            for (var s = 0; s < suggested.length; s++) {
                var slot = suggested[s];
                if (slot.passed) continue;
                var bgColor = slot.available ? '#10b98120' : '#f59e0b15';
                var borderColor = slot.available ? '#10b981' : '#f59e0b';
                var textColor = slot.available ? '#34d399' : '#fbbf24';
                var statusLabel = slot.available ? 'All free' : slot.clash_count + ' clash';

                html += '<div class="sched-slot-card" style="padding:12px 16px;border-radius:10px;border:1px solid ' + borderColor + ';background:' + bgColor + ';min-width:200px">' +
                    '<div style="display:flex;justify-content:space-between;align-items:center">' +
                        '<div>' +
                            '<div style="font-size:14px;font-weight:600;color:' + textColor + '">' + escapeHtml(slot.time) + ' - ' + escapeHtml(slot.end_time) + '</div>' +
                            '<div style="font-size:11px;color:' + textColor + ';margin-top:2px">' + statusLabel + '</div>' +
                        '</div>' +
                        '<button class="sched-slot-btn" data-time="' + escapeHtml(slot.time) + '" data-end="' + escapeHtml(slot.end_time) + '" style="padding:6px 14px;border-radius:6px;border:none;background:' + (slot.available ? '#10b981' : '#3b82f6') + ';color:#fff;font-size:12px;font-weight:600;cursor:pointer">Book</button>' +
                    '</div>';

                // Show clashes with skip buttons
                if (slot.clashes && slot.clashes.length) {
                    for (var ci = 0; ci < slot.clashes.length; ci++) {
                        var clashTitle = slot.clashes[ci];
                        var clashUser = slot.clash_users[ci] || '';
                        // Find the meeting_key for this clash from users data
                        var clashKey = '';
                        for (var ui = 0; ui < users.length; ui++) {
                            for (var bi = 0; bi < (users[ui].busy || []).length; bi++) {
                                if (users[ui].busy[bi].title === clashTitle && users[ui].busy[bi].start < slot.end_minutes && users[ui].busy[bi].end > slot.start_minutes) {
                                    clashKey = users[ui].busy[bi].meeting_key || '';
                                    break;
                                }
                            }
                            if (clashKey) break;
                        }
                        html += '<div style="display:flex;align-items:center;gap:6px;margin-top:6px;padding:4px 8px;background:#ef444415;border-radius:6px">' +
                            '<span style="font-size:11px;color:#fca5a5;flex:1">' + escapeHtml(clashUser + ': ' + clashTitle) + '</span>' +
                            (clashKey ? '<button class="sched-skip-btn" data-key="' + escapeHtml(clashKey) + '" data-date="' + escapeHtml(p.date) + '" style="font-size:10px;padding:3px 8px;border-radius:4px;border:1px solid #f59e0b;background:transparent;color:#f59e0b;cursor:pointer;white-space:nowrap">Skip for this day</button>' : '') +
                        '</div>';
                    }
                }

                html += '</div>';
            }

            html += '</div>';

            resultEl.innerHTML = html;

            // Slot click → create meeting
            resultEl.querySelectorAll('.sched-slot-btn').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var time = btn.getAttribute('data-time');
                    if (!confirm('Create "' + p.title + '" at ' + time + ' on ' + p.date + '?')) return;

                    btn.disabled = true;
                    btn.style.opacity = '0.5';

                    fetch('/api/meetings/schedule/create', {
                        method: 'POST', credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        body: JSON.stringify({
                            title: p.title,
                            date: p.date,
                            time: time,
                            attendees: p.attendee_ids
                        })
                    }).then(function (r) { return r.json(); }).then(function (res) {
                        if (res.ok) {
                            loadScheduledMeetings();
                            resultEl.innerHTML = '<div style="text-align:center;padding:40px">' +
                                '<div style="font-size:40px;margin-bottom:12px">✅</div>' +
                                '<h3 style="color:#34d399;margin-bottom:6px">Meeting Created!</h3>' +
                                '<div style="color:#9ca3af;font-size:14px">' +
                                    '<strong>' + escapeHtml(res.meeting.title) + '</strong><br>' +
                                    escapeHtml(res.meeting.day + ', ' + res.meeting.date + ' at ' + res.meeting.time) + '<br>' +
                                    'with ' + escapeHtml(res.meeting.attendees.join(', ')) +
                                '</div>' +
                                '<button id="schedNewBtn" style="margin-top:16px;padding:8px 20px;border-radius:8px;border:1px solid #3f3f46;background:transparent;color:#a5b4fc;cursor:pointer;font-size:13px">Schedule Another</button>' +
                            '</div>';
                            document.getElementById('schedNewBtn').addEventListener('click', function () {
                                titleEl.value = '';
                                resultEl.innerHTML = '';
                                selectedIds.length = 0;
                                cntEl.textContent = '(0 selected)';
                                root.querySelectorAll('.sched-person').forEach(function (chip) {
                                    chip.style.background = 'transparent';
                                    chip.style.color = '#9ca3af';
                                    chip.style.borderColor = '#3f3f46';
                                });
                                titleEl.focus();
                            });
                        } else {
                            btn.disabled = false;
                            btn.style.opacity = '1';
                            alert(res.error || 'Failed to create meeting');
                        }
                    }).catch(function () { btn.disabled = false; btn.style.opacity = '1'; });
                });
            });

            // Fixed-mode: skip clash buttons
            resultEl.querySelectorAll('.sched-clash-skip').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var key = btn.getAttribute('data-key');
                    var date = btn.getAttribute('data-date');
                    btn.disabled = true; btn.textContent = 'Skipping...';
                    fetch('/api/meetings/schedule/skip', {
                        method: 'POST', credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        body: JSON.stringify({ meeting_key: key, date: date })
                    }).then(function (r) { return r.json(); }).then(function (res) {
                        if (res.ok) {
                            btn.textContent = 'Skipped!'; btn.style.color = '#10b981'; btn.style.borderColor = '#10b981';
                            var card = btn.closest('.sched-fixed-clash');
                            if (card) { card.style.opacity = '0.4'; }
                            setTimeout(function () { doAnalyze(); }, 600);
                        }
                    });
                });
            });

            // Fixed-mode: suggest reschedule buttons
            resultEl.querySelectorAll('.sched-clash-resched').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var key = btn.getAttribute('data-key');
                    var date = btn.getAttribute('data-date');
                    btn.disabled = true; btn.textContent = 'Finding...';
                    var resultDiv = resultEl.querySelector('.sched-resched-result[data-key="' + key + '"]');

                    fetch('/api/meetings/schedule/reschedule', {
                        method: 'POST', credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        body: JSON.stringify({ meeting_key: key, date: date })
                    }).then(function (r) { return r.json(); }).then(function (res) {
                        btn.textContent = 'Suggest new time'; btn.disabled = false;
                        if (res.ok && res.data && res.data.suggestions && res.data.suggestions.length) {
                            var sh = '<div style="font-size:11px;color:#9ca3af;margin-bottom:4px">Move "' + escapeHtml(res.data.title) + '" to:</div><div style="display:flex;gap:6px;flex-wrap:wrap">';
                            for (var si = 0; si < res.data.suggestions.length; si++) {
                                var sg = res.data.suggestions[si];
                                sh += '<span style="padding:4px 10px;border-radius:5px;background:#8b5cf620;color:#a78bfa;font-size:12px;cursor:default">' + escapeHtml(sg.time) + ' - ' + escapeHtml(sg.end_time) + '</span>';
                            }
                            sh += '</div>';
                            resultDiv.innerHTML = sh;
                            resultDiv.style.display = 'block';
                        } else {
                            resultDiv.innerHTML = '<div style="font-size:11px;color:#f87171">No alternative times found</div>';
                            resultDiv.style.display = 'block';
                        }
                    });
                });
            });

            // Skip meeting buttons
            resultEl.querySelectorAll('.sched-skip-btn').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var meetingKey = btn.getAttribute('data-key');
                    var skipDate = btn.getAttribute('data-date');
                    btn.disabled = true;
                    btn.textContent = 'Skipping...';

                    fetch('/api/meetings/schedule/skip', {
                        method: 'POST', credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        body: JSON.stringify({ meeting_key: meetingKey, date: skipDate })
                    }).then(function (r) { return r.json(); }).then(function (res) {
                        if (res.ok) {
                            btn.textContent = 'Skipped!';
                            btn.style.color = '#10b981';
                            btn.style.borderColor = '#10b981';
                            // Re-analyze to refresh availability
                            setTimeout(function () { doAnalyze(); }, 500);
                        } else {
                            btn.disabled = false;
                            btn.textContent = 'Skip for this day';
                        }
                    }).catch(function () { btn.disabled = false; btn.textContent = 'Skip for this day'; });
                });
            });
        }
    }

    /* ── Google Page ──────────────────────────────────────────────── */
    function renderGoogle() {
        var root = document.getElementById('googleView');
        if (!root) return;
        root.innerHTML = '<div class="emp-wrap"><div class="kpi-status-msg">Loading Google...</div></div>';

        fetch('/api/google/status', {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (r) { return r.json(); }).then(function (statusData) {

            if (!statusData.connected) {
                root.innerHTML = '<div class="emp-wrap">' +
                    '<div class="emp-header"><h2 class="emp-title">Google</h2></div>' +
                    '<div style="text-align:center;padding:60px 20px">' +
                        '<svg width="48" height="48" viewBox="0 0 24 24" style="margin-bottom:16px"><path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 01-2.2 3.32v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.1z" fill="#4285F4"/><path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/><path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#FBBC05"/><path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/></svg>' +
                        '<h3 style="color:#f0f0f0;margin-bottom:8px">Connect Your Google Account</h3>' +
                        '<p style="color:#9ca3af;font-size:14px;max-width:400px;margin:0 auto 20px">Connect Google to access Gmail, Calendar, and Drive directly from Tessa.</p>' +
                        '<button id="googleGoConnect" style="padding:10px 24px;border-radius:8px;border:none;background:#4285F4;color:#fff;font-size:14px;font-weight:600;cursor:pointer">Go to Profile to Connect</button>' +
                    '</div></div>';
                var goBtn = document.getElementById('googleGoConnect');
                if (goBtn) goBtn.onclick = function () { if (MeetingModule && MeetingModule.switchView) MeetingModule.switchView('profile'); };
                return;
            }

            // Connected
            root.innerHTML = '<div class="emp-wrap">' +
                '<div class="emp-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">' +
                    '<div>' +
                        '<h2 class="emp-title">Google</h2>' +
                        '<div style="font-size:12px;color:#34d399;margin-top:2px">Connected as ' + escapeHtml(statusData.email || statusData.name || '') + '</div>' +
                    '</div>' +
                '</div>' +
                '<div id="googleTabs" style="display:flex;gap:6px;margin-bottom:16px">' +
                    '<button class="google-tab active" data-tab="gmail" style="padding:5px 14px;border-radius:16px;border:1px solid #3f3f46;background:#1a1a2e;color:#a5b4fc;font-size:12px;cursor:pointer">Gmail</button>' +
                    '<button class="google-tab" data-tab="calendar" style="padding:5px 14px;border-radius:16px;border:1px solid #3f3f46;background:transparent;color:#6b7280;font-size:12px;cursor:pointer">Calendar</button>' +
                    '<button class="google-tab" data-tab="drive" style="padding:5px 14px;border-radius:16px;border:1px solid #3f3f46;background:transparent;color:#6b7280;font-size:12px;cursor:pointer">Drive</button>' +
                '</div>' +
                '<div id="googleContent"></div>' +
            '</div>';

            var contentEl = document.getElementById('googleContent');
            var currentTab = 'gmail';

            root.querySelectorAll('.google-tab').forEach(function (tab) {
                tab.addEventListener('click', function () {
                    root.querySelectorAll('.google-tab').forEach(function (t) { t.classList.remove('active'); t.style.background = 'transparent'; t.style.color = '#6b7280'; });
                    tab.classList.add('active'); tab.style.background = '#1a1a2e'; tab.style.color = '#a5b4fc';
                    currentTab = tab.getAttribute('data-tab');
                    loadTab();
                });
            });

            loadTab();

            function loadTab() {
                contentEl.innerHTML = '<div class="kpi-status-msg">Loading...</div>';
                if (currentTab === 'gmail') loadGmail();
                else if (currentTab === 'calendar') loadCalendar();
                else if (currentTab === 'drive') loadDrive();
            }

            function loadGmail() {
                fetch('/api/google/gmail/messages?max=15', {
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                }).then(function (r) { return r.json(); }).then(function (data) {
                    var msgs = (data.ok && data.data) ? data.data.messages : [];
                    if (!msgs.length) { contentEl.innerHTML = '<div style="text-align:center;padding:40px;color:#6b7280">No recent emails</div>'; return; }
                    var html = '';
                    for (var i = 0; i < msgs.length; i++) {
                        var m = msgs[i];
                        var isUnread = (m.labelIds || []).indexOf('UNREAD') > -1;
                        html += '<div style="padding:12px 14px;margin-bottom:4px;background:#1a1a2e;border-radius:8px;border-left:3px solid ' + (isUnread ? '#3b82f6' : 'transparent') + '">' +
                            '<div style="display:flex;justify-content:space-between;gap:8px">' +
                                '<div style="flex:1;min-width:0">' +
                                    '<div style="font-size:13px;color:#f0f0f0;font-weight:' + (isUnread ? '600' : '400') + ';white-space:nowrap;overflow:hidden;text-overflow:ellipsis">' + escapeHtml(m.subject || '(no subject)') + '</div>' +
                                    '<div style="font-size:11px;color:#6b7280;margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">' + escapeHtml(m.from || '') + '</div>' +
                                '</div>' +
                                '<div style="font-size:10px;color:#6b7280;white-space:nowrap">' + escapeHtml((m.date || '').split(',')[0] || '') + '</div>' +
                            '</div>' +
                            (m.snippet ? '<div style="font-size:12px;color:#9ca3af;margin-top:4px;line-height:1.4;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden">' + escapeHtml(m.snippet) + '</div>' : '') +
                        '</div>';
                    }
                    contentEl.innerHTML = html;
                }).catch(function () { contentEl.innerHTML = '<div style="text-align:center;padding:30px;color:#f87171">Failed to load Gmail</div>'; });
            }

            function loadCalendar() {
                var today = new Date().toISOString().split('T')[0];
                fetch('/api/google/calendar/events?date=' + today, {
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                }).then(function (r) { return r.json(); }).then(function (data) {
                    var events = (data.ok && data.data) ? data.data : [];
                    if (!events.length) { contentEl.innerHTML = '<div style="text-align:center;padding:40px;color:#6b7280">No events today</div>'; return; }
                    var html = '<div style="margin-bottom:8px;font-size:13px;color:#9ca3af">' + events.length + ' event(s) today</div>';
                    for (var i = 0; i < events.length; i++) {
                        var ev = events[i];
                        var startTime = ev.start ? new Date(ev.start).toLocaleTimeString('en-IN', { timeZone: 'Asia/Kolkata', hour: '2-digit', minute: '2-digit' }) : 'All day';
                        var endTime = ev.end ? new Date(ev.end).toLocaleTimeString('en-IN', { timeZone: 'Asia/Kolkata', hour: '2-digit', minute: '2-digit' }) : '';
                        html += '<div style="padding:12px 14px;margin-bottom:6px;background:#1a1a2e;border-radius:8px;border-left:3px solid #4285F4">' +
                            '<div style="display:flex;justify-content:space-between;align-items:start">' +
                                '<div>' +
                                    '<div style="font-size:13px;color:#f0f0f0;font-weight:500">' + escapeHtml(ev.title) + '</div>' +
                                    '<div style="font-size:11px;color:#6b7280;margin-top:2px">' + escapeHtml(startTime + (endTime ? ' - ' + endTime : '')) + '</div>' +
                                '</div>' +
                                (ev.meet_link ? '<a href="' + escapeHtml(ev.meet_link) + '" target="_blank" style="font-size:11px;color:#34d399;text-decoration:none;white-space:nowrap">Join Meet ↗</a>' : '') +
                            '</div>' +
                            (ev.attendees && ev.attendees.length ? '<div style="font-size:10px;color:#6b7280;margin-top:4px">' + escapeHtml(ev.attendees.slice(0, 5).join(', ')) + (ev.attendees.length > 5 ? ' +' + (ev.attendees.length - 5) : '') + '</div>' : '') +
                        '</div>';
                    }
                    contentEl.innerHTML = html;
                }).catch(function () { contentEl.innerHTML = '<div style="text-align:center;padding:30px;color:#f87171">Failed to load Calendar</div>'; });
            }

            function loadDrive() {
                fetch('/api/google/drive/files?pageSize=15', {
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                }).then(function (r) { return r.json(); }).then(function (data) {
                    var files = (data.ok && data.data && data.data.files) ? data.data.files : [];
                    if (!files.length) { contentEl.innerHTML = '<div style="text-align:center;padding:40px;color:#6b7280">No files found</div>'; return; }
                    var typeIcons = { 'application/vnd.google-apps.document': '📄', 'application/vnd.google-apps.spreadsheet': '📊', 'application/vnd.google-apps.presentation': '📑', 'application/vnd.google-apps.folder': '📁', 'application/pdf': '📕' };
                    var html = '';
                    for (var i = 0; i < files.length; i++) {
                        var f = files[i];
                        var icon = typeIcons[f.mimeType] || '📄';
                        var modified = f.modifiedTime ? new Date(f.modifiedTime).toLocaleDateString('en-IN', { timeZone: 'Asia/Kolkata', day: '2-digit', month: 'short' }) : '';
                        html += '<a href="' + escapeHtml(f.webViewLink || '#') + '" target="_blank" style="display:flex;align-items:center;gap:10px;padding:10px 14px;margin-bottom:4px;background:#1a1a2e;border-radius:8px;text-decoration:none">' +
                            '<span style="font-size:20px">' + icon + '</span>' +
                            '<div style="flex:1;min-width:0">' +
                                '<div style="font-size:13px;color:#f0f0f0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">' + escapeHtml(f.name) + '</div>' +
                                '<div style="font-size:11px;color:#6b7280">' + escapeHtml(modified) + '</div>' +
                            '</div>' +
                        '</a>';
                    }
                    contentEl.innerHTML = html;
                }).catch(function () { contentEl.innerHTML = '<div style="text-align:center;padding:30px;color:#f87171">Failed to load Drive</div>'; });
            }

        }).catch(function () {
            root.innerHTML = '<div class="emp-wrap"><div class="emp-empty">Failed to load Google. Please try again.</div></div>';
        });
    }

    /* ── GitHub Page ───────────────────────────────────────────────── */
    function renderGitHub() {
        var root = document.getElementById('githubView');
        if (!root) return;
        root.innerHTML = '<div class="emp-wrap"><div class="kpi-status-msg">Loading GitHub...</div></div>';

        fetch('/api/github/status', {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (r) { return r.json(); }).then(function (statusData) {

            if (!statusData.connected) {
                root.innerHTML = '<div class="emp-wrap">' +
                    '<div class="emp-header"><h2 class="emp-title">GitHub</h2></div>' +
                    '<div style="text-align:center;padding:60px 20px">' +
                        '<svg width="64" height="64" viewBox="0 0 24 24" fill="#6b7280" style="margin-bottom:16px"><path d="M12 0C5.37 0 0 5.37 0 12c0 5.31 3.435 9.795 8.205 11.385.6.105.825-.255.825-.57 0-.285-.015-1.23-.015-2.235-3.015.555-3.795-.735-4.035-1.41-.135-.345-.72-1.41-1.23-1.695-.42-.225-1.02-.78-.015-.795.945-.015 1.62.87 1.845 1.23 1.08 1.815 2.805 1.305 3.495.99.105-.78.42-1.305.765-1.605-2.67-.3-5.46-1.335-5.46-5.925 0-1.305.465-2.385 1.23-3.225-.12-.3-.54-1.53.12-3.18 0 0 1.005-.315 3.3 1.23.96-.27 1.98-.405 3-.405s2.04.135 3 .405c2.295-1.56 3.3-1.23 3.3-1.23.66 1.65.24 2.88.12 3.18.765.84 1.23 1.905 1.23 3.225 0 4.605-2.805 5.625-5.475 5.925.435.375.81 1.095.81 2.22 0 1.605-.015 2.895-.015 3.3 0 .315.225.69.825.57A12.02 12.02 0 0024 12c0-6.63-5.37-12-12-12z"/></svg>' +
                        '<h3 style="color:#f0f0f0;margin-bottom:8px">Connect Your GitHub</h3>' +
                        '<p style="color:#9ca3af;font-size:14px;max-width:400px;margin:0 auto 20px">Connect your GitHub account to track branches, PRs, commits, and create branches directly from tasks.</p>' +
                        '<button id="ghGoConnect" style="padding:10px 24px;border-radius:8px;border:none;background:#333;color:#fff;font-size:14px;font-weight:600;cursor:pointer">Go to Profile to Connect</button>' +
                    '</div></div>';
                var goBtn = document.getElementById('ghGoConnect');
                if (goBtn) goBtn.onclick = function () { if (MeetingModule && MeetingModule.switchView) MeetingModule.switchView('profile'); };
                return;
            }

            // Connected — show GitHub dashboard
            root.innerHTML = '<div class="emp-wrap">' +
                '<div class="emp-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">' +
                    '<div>' +
                        '<h2 class="emp-title">GitHub</h2>' +
                        '<div style="font-size:12px;color:#34d399;margin-top:2px">Connected as @' + escapeHtml(statusData.username || '') + '</div>' +
                    '</div>' +
                '</div>' +
                '<div id="ghRepoSelect" style="margin-bottom:16px"></div>' +
                '<div id="ghTabs" style="display:flex;gap:6px;margin-bottom:16px">' +
                    '<button class="gh-tab active" data-tab="prs" style="padding:5px 14px;border-radius:16px;border:1px solid #3f3f46;background:#1a1a2e;color:#a5b4fc;font-size:12px;cursor:pointer">Pull Requests</button>' +
                    '<button class="gh-tab" data-tab="commits" style="padding:5px 14px;border-radius:16px;border:1px solid #3f3f46;background:transparent;color:#6b7280;font-size:12px;cursor:pointer">Commits</button>' +
                    '<button class="gh-tab" data-tab="branches" style="padding:5px 14px;border-radius:16px;border:1px solid #3f3f46;background:transparent;color:#6b7280;font-size:12px;cursor:pointer">Branches</button>' +
                    '<button class="gh-tab" data-tab="activity" style="padding:5px 14px;border-radius:16px;border:1px solid #3f3f46;background:transparent;color:#6b7280;font-size:12px;cursor:pointer">Activity</button>' +
                '</div>' +
                '<div id="ghContent"></div>' +
            '</div>';

            var contentEl = document.getElementById('ghContent');
            var repoSelectEl = document.getElementById('ghRepoSelect');
            var currentRepo = null;
            var currentTab = 'prs';

            // Tab handlers
            root.querySelectorAll('.gh-tab').forEach(function (tab) {
                tab.addEventListener('click', function () {
                    root.querySelectorAll('.gh-tab').forEach(function (t) { t.classList.remove('active'); t.style.background = 'transparent'; t.style.color = '#6b7280'; });
                    tab.classList.add('active'); tab.style.background = '#1a1a2e'; tab.style.color = '#a5b4fc';
                    currentTab = tab.getAttribute('data-tab');
                    loadTabContent();
                });
            });

            // Load repos first
            contentEl.innerHTML = '<div class="kpi-status-msg">Loading repositories...</div>';
            fetch('/api/github/repos', {
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            }).then(function (r) { return r.json(); }).then(function (data) {
                var repos = (data.ok && data.data) ? data.data : [];
                if (!repos.length) { repoSelectEl.innerHTML = '<div style="color:#6b7280;font-size:13px">No repositories found</div>'; return; }

                var selHtml = '<select id="ghRepoDropdown" class="prof-input" style="font-size:13px;max-width:350px">';
                for (var i = 0; i < repos.length && i < 20; i++) {
                    selHtml += '<option value="' + escapeHtml(repos[i].full_name) + '">' + escapeHtml(repos[i].full_name) + (repos[i].private ? ' (private)' : '') + '</option>';
                }
                selHtml += '</select>';
                repoSelectEl.innerHTML = selHtml;

                currentRepo = repos[0].full_name;
                document.getElementById('ghRepoDropdown').addEventListener('change', function () {
                    currentRepo = this.value;
                    loadTabContent();
                });
                loadTabContent();
            });

            function loadTabContent() {
                if (!currentRepo) return;
                var parts = currentRepo.split('/');
                var owner = parts[0]; var repo = parts[1];
                contentEl.innerHTML = '<div class="kpi-status-msg">Loading...</div>';

                if (currentTab === 'activity') {
                    fetch('/api/github/activity', { credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(function (r) { return r.json(); }).then(function (data) { renderActivity(data.ok ? data.data : []); });
                    return;
                }

                var url = '/api/github/repos/' + owner + '/' + repo + '/' + (currentTab === 'prs' ? 'pulls' : currentTab);
                fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.json(); }).then(function (data) {
                    var items = data.ok ? data.data : [];
                    if (currentTab === 'prs') renderPRs(items);
                    else if (currentTab === 'commits') renderCommits(items);
                    else if (currentTab === 'branches') renderBranches(items);
                });
            }

            function renderPRs(prs) {
                if (!prs.length) { contentEl.innerHTML = '<div style="text-align:center;padding:40px;color:#6b7280">No open pull requests</div>'; return; }
                var html = '';
                for (var i = 0; i < prs.length; i++) {
                    var pr = prs[i];
                    var stColor = pr.state === 'open' ? '#10b981' : (pr.merged_at ? '#8b5cf6' : '#ef4444');
                    var stLabel = pr.merged_at ? 'Merged' : pr.state;
                    html += '<div style="padding:12px 14px;margin-bottom:8px;background:#1a1a2e;border-radius:10px;border-left:3px solid ' + stColor + '">' +
                        '<div style="display:flex;align-items:start;gap:8px">' +
                            '<div style="flex:1">' +
                                '<div style="font-weight:600;font-size:13px;color:#f0f0f0">#' + pr.number + ' ' + escapeHtml(pr.title) + '</div>' +
                                '<div style="font-size:11px;color:#6b7280;margin-top:3px">' + escapeHtml(pr.head.ref) + ' → ' + escapeHtml(pr.base.ref) + ' · by ' + escapeHtml(pr.user.login) + '</div>' +
                            '</div>' +
                            '<span style="font-size:11px;padding:3px 8px;border-radius:5px;background:' + stColor + '20;color:' + stColor + ';font-weight:500">' + stLabel + '</span>' +
                        '</div></div>';
                }
                contentEl.innerHTML = html;
            }

            function renderCommits(commits) {
                if (!commits.length) { contentEl.innerHTML = '<div style="text-align:center;padding:40px;color:#6b7280">No commits found</div>'; return; }
                var html = '';
                for (var i = 0; i < commits.length && i < 20; i++) {
                    var c = commits[i];
                    var msg = (c.commit.message || '').split('\n')[0];
                    var sha = (c.sha || '').substring(0, 7);
                    var author = c.commit.author.name || '';
                    var date = c.commit.author.date ? new Date(c.commit.author.date).toLocaleDateString('en-IN', { timeZone: 'Asia/Kolkata', day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }) : '';
                    html += '<div style="padding:10px 14px;margin-bottom:4px;background:#1a1a2e;border-radius:8px;display:flex;gap:10px;align-items:start">' +
                        '<code style="color:#f59e0b;font-size:12px;white-space:nowrap">' + sha + '</code>' +
                        '<div style="flex:1;min-width:0">' +
                            '<div style="font-size:13px;color:#f0f0f0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">' + escapeHtml(msg) + '</div>' +
                            '<div style="font-size:11px;color:#6b7280">' + escapeHtml(author) + ' · ' + escapeHtml(date) + '</div>' +
                        '</div></div>';
                }
                contentEl.innerHTML = html;
            }

            function renderBranches(branches) {
                if (!branches.length) { contentEl.innerHTML = '<div style="text-align:center;padding:40px;color:#6b7280">No branches found</div>'; return; }
                var html = '';
                for (var i = 0; i < branches.length; i++) {
                    var b = branches[i];
                    var isDefault = b.name === 'main' || b.name === 'master';
                    html += '<div style="padding:10px 14px;margin-bottom:4px;background:#1a1a2e;border-radius:8px;display:flex;align-items:center;gap:10px">' +
                        '<span style="font-size:13px;color:' + (isDefault ? '#f59e0b' : '#60a5fa') + ';font-weight:' + (isDefault ? '600' : '400') + '">' + escapeHtml(b.name) + '</span>' +
                        (isDefault ? '<span style="font-size:10px;padding:2px 6px;border-radius:4px;background:#f59e0b20;color:#f59e0b">default</span>' : '') +
                        '<code style="font-size:11px;color:#6b7280;margin-left:auto">' + (b.commit.sha || '').substring(0, 7) + '</code>' +
                    '</div>';
                }
                contentEl.innerHTML = html;
            }

            function renderActivity(events) {
                if (!events.length) { contentEl.innerHTML = '<div style="text-align:center;padding:40px;color:#6b7280">No recent activity</div>'; return; }
                var typeIcons = { PushEvent: '📦', PullRequestEvent: '🔀', CreateEvent: '🌿', DeleteEvent: '🗑️', IssuesEvent: '🎫', IssueCommentEvent: '💬', WatchEvent: '⭐', ForkEvent: '🍴' };
                var html = '';
                for (var i = 0; i < events.length && i < 20; i++) {
                    var ev = events[i];
                    var icon = typeIcons[ev.type] || '📌';
                    var repoName = ev.repo ? ev.repo.name : '';
                    var desc = ev.type.replace('Event', '');
                    if (ev.type === 'PushEvent') desc = 'Pushed ' + ((ev.payload && ev.payload.commits) ? ev.payload.commits.length : '') + ' commit(s)';
                    if (ev.type === 'PullRequestEvent' && ev.payload) desc = ev.payload.action + ' PR #' + (ev.payload.pull_request ? ev.payload.pull_request.number : '');
                    if (ev.type === 'CreateEvent' && ev.payload) desc = 'Created ' + (ev.payload.ref_type || '') + ' ' + (ev.payload.ref || '');
                    var date = ev.created_at ? new Date(ev.created_at).toLocaleDateString('en-IN', { timeZone: 'Asia/Kolkata', day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }) : '';
                    html += '<div style="padding:10px 14px;margin-bottom:4px;background:#1a1a2e;border-radius:8px;display:flex;gap:10px;align-items:start">' +
                        '<span style="font-size:16px">' + icon + '</span>' +
                        '<div style="flex:1">' +
                            '<div style="font-size:13px;color:#f0f0f0">' + escapeHtml(desc) + '</div>' +
                            '<div style="font-size:11px;color:#6b7280">' + escapeHtml(repoName) + ' · ' + escapeHtml(date) + '</div>' +
                        '</div></div>';
                }
                contentEl.innerHTML = html;
            }

        }).catch(function () {
            root.innerHTML = '<div class="emp-wrap"><div class="emp-empty">Failed to load GitHub. Please try again.</div></div>';
        });
    }

    /* ── Slack Insights Page ─────────────────────────────────────── */
    // Clean insight detail modal (shared by the dashboard-style cards + Archives).
    function openInsightDetailModal(ins) {
        var existing = document.getElementById('miDetailOverlay');
        if (existing) existing.remove();
        var typeLabels = { action_item: 'Action item', reminder: 'Reminder', follow_up: 'Follow-up', decision: 'Decision' };
        var typeIcons  = { action_item: '📋', reminder: '⏰', follow_up: '🔄', decision: '✅' };
        var icon       = typeIcons[ins.type] || '💡';
        var typeLabel  = typeLabels[ins.type] || ins.type;
        var assignerName  = (ins.assigned_by && ins.assigned_by.name) ? ins.assigned_by.name : (ins.mentioned_by || '');
        var suggestedName = (ins.suggested_assignee && ins.suggested_assignee.name) ? ins.suggested_assignee.name : '';
        var meetingVal    = (ins.meeting_label || ins.meeting_title) ? escapeHtml(ins.meeting_label || ins.meeting_title) + (ins.meeting_date ? ' · ' + escapeHtml(ins.meeting_date) : '') : '';
        function metaRow(label, valueHtml) {
            if (!valueHtml) return '';
            return '<div class="mi-detail-row"><span class="mi-detail-label">' + escapeHtml(label) + '</span>' +
                '<span class="mi-detail-value">' + valueHtml + '</span></div>';
        }
        var rowsHtml = '';
        rowsHtml += metaRow('Type', '<span class="dash-mi-chip dash-mi-chip--type dash-mi-chip--type-' + escapeHtml(ins.type) + '">' + escapeHtml(typeLabel) + '</span>');
        rowsHtml += metaRow('Priority', '<span class="dash-mi-chip dash-mi-chip--pri dash-mi-chip--pri-' + escapeHtml(ins.priority) + '">' + escapeHtml(ins.priority) + '</span>');
        rowsHtml += metaRow('Assigned by', assignerName ? escapeHtml(assignerName) : '');
        rowsHtml += metaRow('Suggested assignee', suggestedName ? escapeHtml(suggestedName) : '');
        rowsHtml += metaRow('Due', ins.due_date ? escapeHtml(ins.due_date) : '');
        rowsHtml += metaRow('Meeting', meetingVal);
        var overlay = document.createElement('div');
        overlay.id = 'miDetailOverlay';
        overlay.className = 'mtg-modal-overlay';
        overlay.innerHTML =
            '<div class="mtg-modal mi-detail-modal">' +
                '<div class="mtg-modal-header">' +
                    '<h3 class="mtg-modal-title">' + icon + ' ' + escapeHtml(ins.title) + '</h3>' +
                    '<button type="button" class="mtg-modal-close" id="miDetailClose">&#x2715;</button>' +
                '</div>' +
                '<div class="mtg-modal-body mi-detail-body">' +
                    (ins.summary ? '<div class="mi-detail-desc">' + escapeHtml(ins.summary) + '</div>' : '') +
                    '<div class="mi-detail-meta">' + rowsHtml + '</div>' +
                '</div>' +
            '</div>';
        document.body.appendChild(overlay);
        function onKey(e) { if (e.key === 'Escape') closeModal(); }
        function closeModal() {
            var el = document.getElementById('miDetailOverlay');
            if (el) el.remove();
            document.removeEventListener('keydown', onKey);
        }
        document.addEventListener('keydown', onKey);
        overlay.querySelector('#miDetailClose').onclick = closeModal;
        overlay.onclick = function (e) { if (e.target === overlay) closeModal(); };
    }

    /* ── Archives (consolidated Slack / GitHub / Google) ───────────── */
    function renderArchives() {
        var view = document.getElementById('archivesView');
        if (!view) return;
        if (!view.__archWired) {
            view.__archWired = true;
            view.querySelectorAll('.arch-tabs .dash-tab[data-archtab]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var tab = btn.getAttribute('data-archtab');
                    view.querySelectorAll('.arch-tabs .dash-tab').forEach(function (b) {
                        b.classList.toggle('active', b === btn);
                    });
                    view.querySelectorAll('.arch-panel').forEach(function (p) {
                        p.classList.toggle('hidden', p.getAttribute('data-archpanel') !== tab);
                    });
                    archRenderTab(tab);
                });
            });
        }
        var activeBtn = view.querySelector('.arch-tabs .dash-tab.active');
        archRenderTab(activeBtn ? activeBtn.getAttribute('data-archtab') : 'slack');
    }

    /* ── Employee Records (HR): two tabs — ESIC Sheet (Google embed) + Employee Documents (Tessa-native) ── */
    function renderHrRecords() {
        var view = document.getElementById('hr_recordsView');
        if (!view) return;
        function activate(tab) {
            view.querySelectorAll('.hr-rec-tabs .dash-tab').forEach(function (b) {
                b.classList.toggle('active', b.getAttribute('data-hrtab') === tab);
            });
            view.querySelectorAll('.hr-rec-panel').forEach(function (p) {
                var match = p.getAttribute('data-hrpanel') === tab;
                p.classList.toggle('hidden', !match);
                if (match) {
                    // ESIC Sheet: lazy-load its iframe from data-src the first time it's shown.
                    var f = p.querySelector('iframe[data-src]');
                    if (f && !f.getAttribute('src')) f.setAttribute('src', f.getAttribute('data-src'));
                }
            });
            // Employee Documents is a Tessa-native, restyled Drive browser (no iframe) — (re)render
            // it on activation. ESIC Sheet stays a lazy-loaded Google iframe above.
            if (tab === 'drive' && window.HRModule && HRModule.renderDriveBrowser) {
                HRModule.renderDriveBrowser();
            }
        }
        if (!view.__hrWired) {
            view.__hrWired = true;
            view.querySelectorAll('.hr-rec-tabs .dash-tab[data-hrtab]').forEach(function (btn) {
                btn.addEventListener('click', function () { activate(btn.getAttribute('data-hrtab')); });
            });
        }
        var activeBtn = view.querySelector('.hr-rec-tabs .dash-tab.active');
        activate(activeBtn ? activeBtn.getAttribute('data-hrtab') : 'sheet');
    }

    function archRenderTab(tab) {
        if (tab === 'github') { renderGitHub(); return; }
        if (tab === 'google') { renderGoogleArchive(); return; }
        renderSlack();
    }

    // Google archive tab: saved Gmail insight cards (dashboard suggestions) +
    // live Drive files, kept as separate sub-tabs. Gmail has a Clear history.
    function renderGoogleArchive() {
        var root = document.getElementById('googleView');
        if (!root) return;
        root.innerHTML = '<div class="emp-wrap"><div class="kpi-status-msg">Loading Google...</div></div>';

        var tabActive = 'padding:5px 14px;border-radius:16px;border:1px solid #3f3f46;background:#1a1a2e;color:#a5b4fc;font-size:12px;cursor:pointer';
        var tabIdle = 'padding:5px 14px;border-radius:16px;border:1px solid #3f3f46;background:transparent;color:#6b7280;font-size:12px;cursor:pointer';

        fetch('/api/google/status', {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (r) { return r.json(); }).then(function (statusData) {
            var connected = !!(statusData && statusData.connected);
            root.innerHTML = '<div class="emp-wrap">' +
                '<div class="emp-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">' +
                    '<div><h2 class="emp-title">Google</h2>' +
                        (connected
                            ? '<div style="font-size:12px;color:#34d399;margin-top:2px">Connected as ' + escapeHtml(statusData.email || statusData.name || '') + '</div>'
                            : '<div style="font-size:12px;color:#9ca3af;margin-top:2px">Not connected — showing saved Gmail history</div>') +
                    '</div>' +
                '</div>' +
                '<div style="display:flex;gap:6px;margin-bottom:16px">' +
                    '<button class="g-arch-tab active" data-gtab="gmail" style="' + tabActive + '">Gmail</button>' +
                    '<button class="g-arch-tab" data-gtab="drive" style="' + tabIdle + '">Drive</button>' +
                '</div>' +
                '<div id="gArchContent"></div>' +
            '</div>';

            var contentEl = document.getElementById('gArchContent');
            var currentTab = 'gmail';

            root.querySelectorAll('.g-arch-tab').forEach(function (tab) {
                tab.addEventListener('click', function () {
                    root.querySelectorAll('.g-arch-tab').forEach(function (t) { t.classList.remove('active'); t.setAttribute('style', tabIdle); });
                    tab.classList.add('active'); tab.setAttribute('style', tabActive);
                    currentTab = tab.getAttribute('data-gtab');
                    loadGTab();
                });
            });
            loadGTab();

            function loadGTab() {
                contentEl.innerHTML = '<div class="kpi-status-msg">Loading...</div>';
                if (currentTab === 'drive') loadDriveFiles(); else loadGmailInsights();
            }

            function loadGmailInsights() {
                fetch('/api/gmail/insights?archive=1', {
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                }).then(function (r) { return r.json(); }).then(function (data) {
                    var list = (data.ok && data.insights) ? data.insights : [];
                    var head = '<div style="display:flex;justify-content:flex-end;margin-bottom:10px">' +
                        '<button id="gmailClearHistory" class="arch-clear-btn" type="button"' + (list.length ? '' : ' disabled') + '>Clear history</button></div>';
                    if (!list.length) {
                        contentEl.innerHTML = head + '<div style="text-align:center;padding:40px;color:#6b7280">No saved Gmail insights.</div>';
                        wireGmailClear();
                        return;
                    }
                    var rows = '';
                    for (var i = 0; i < list.length; i++) {
                        var g = list[i];
                        rows += '<div class="dash-gm-card" data-insight-id="' + g.id + '">' +
                            '<button type="button" class="dash-gm-open" data-insight-id="' + g.id + '" title="View details">' +
                                '<span class="dash-gm-title">' + escapeHtml(g.subject || '(no subject)') + '</span>' +
                                (g.sender ? '<span class="dash-gm-sender">' + escapeHtml(gmailSenderName(g.sender)) + '</span>' : '') +
                            '</button>' +
                            '<div class="dash-gm-actions">' +
                                '<button type="button" class="btn btn-outline-secondary btn-sm g-arch-ignore" data-insight-id="' + g.id + '">Ignore</button>' +
                            '</div>' +
                        '</div>';
                    }
                    contentEl.innerHTML = head + '<div class="dash-gm-list">' + rows + '</div>';
                    wireGmailClear();
                    contentEl.querySelectorAll('.dash-gm-open').forEach(function (btn) {
                        btn.addEventListener('click', function () {
                            var id = parseInt(btn.getAttribute('data-insight-id'), 10);
                            var found = null;
                            for (var j = 0; j < list.length; j++) { if (parseInt(list[j].id, 10) === id) { found = list[j]; break; } }
                            if (found) openGmailInsightDetails(found);
                        });
                    });
                    contentEl.querySelectorAll('.g-arch-ignore').forEach(function (btn) {
                        btn.addEventListener('click', function () {
                            var id = btn.getAttribute('data-insight-id');
                            fetch('/api/gmail/insights/' + id, {
                                method: 'PUT', credentials: 'same-origin',
                                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                                body: JSON.stringify({ status: 'dismissed' })
                            }).then(function () {
                                var card = btn.closest('.dash-gm-card');
                                if (card) { card.style.transition = 'opacity 0.2s'; card.style.opacity = '0'; setTimeout(function () { card.remove(); }, 200); }
                            });
                        });
                    });
                }).catch(function () { contentEl.innerHTML = '<div style="text-align:center;padding:30px;color:#f87171">Failed to load Gmail insights</div>'; });
            }

            function wireGmailClear() {
                var btn = document.getElementById('gmailClearHistory');
                if (!btn) return;
                btn.onclick = function () {
                    if (!confirm('Permanently delete all your saved Gmail insights? This cannot be undone.')) return;
                    btn.disabled = true; btn.textContent = 'Clearing...';
                    fetch('/api/gmail/insights', {
                        method: 'DELETE', credentials: 'same-origin',
                        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                    }).then(function (r) { return r.json(); }).then(function () { loadGmailInsights(); })
                      .catch(function () { btn.disabled = false; btn.textContent = 'Clear history'; });
                };
            }

            function loadDriveFiles() {
                if (!connected) { contentEl.innerHTML = '<div style="text-align:center;padding:40px;color:#6b7280">Connect Google in Profile to view Drive files.</div>'; return; }
                fetch('/api/google/drive/files?pageSize=15', {
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                }).then(function (r) { return r.json(); }).then(function (data) {
                    var files = (data.ok && data.data && data.data.files) ? data.data.files : [];
                    if (!files.length) { contentEl.innerHTML = '<div style="text-align:center;padding:40px;color:#6b7280">No files found</div>'; return; }
                    var typeIcons = { 'application/vnd.google-apps.document': '📄', 'application/vnd.google-apps.spreadsheet': '📊', 'application/vnd.google-apps.presentation': '📑', 'application/vnd.google-apps.folder': '📁', 'application/pdf': '📕' };
                    var html = '';
                    for (var i = 0; i < files.length; i++) {
                        var f = files[i];
                        var icon = typeIcons[f.mimeType] || '📄';
                        var modified = f.modifiedTime ? new Date(f.modifiedTime).toLocaleDateString('en-IN', { timeZone: 'Asia/Kolkata', day: '2-digit', month: 'short' }) : '';
                        html += '<a href="' + escapeHtml(f.webViewLink || '#') + '" target="_blank" style="display:flex;align-items:center;gap:10px;padding:10px 14px;margin-bottom:4px;background:#1a1a2e;border-radius:8px;text-decoration:none">' +
                            '<span style="font-size:20px">' + icon + '</span>' +
                            '<div style="flex:1;min-width:0">' +
                                '<div style="font-size:13px;color:#f0f0f0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">' + escapeHtml(f.name) + '</div>' +
                                '<div style="font-size:11px;color:#6b7280">' + escapeHtml(modified) + '</div>' +
                            '</div>' +
                        '</a>';
                    }
                    contentEl.innerHTML = html;
                }).catch(function () { contentEl.innerHTML = '<div style="text-align:center;padding:30px;color:#f87171">Failed to load Drive</div>'; });
            }

        }).catch(function () { root.innerHTML = '<div class="emp-wrap"><div class="emp-empty">Failed to load Google. Please try again.</div></div>'; });
    }

    function renderSlack() {
        var root = document.getElementById('slackView');
        if (!root) return;
        root.innerHTML = '<div class="emp-wrap"><div class="kpi-status-msg">Loading Slack...</div></div>';

        // Check connection status first
        fetch('/api/slack/status', {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (r) { return r.json(); }).then(function (statusData) {

            if (!statusData.connected) {
                root.innerHTML = '<div class="emp-wrap">' +
                    '<div class="emp-header"><h2 class="emp-title">Slack</h2></div>' +
                    '<div style="text-align:center;padding:60px 20px">' +
                        '<svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="#4A154B" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="margin-bottom:16px"><path d="M14.5 10c-.83 0-1.5-.67-1.5-1.5v-5c0-.83.67-1.5 1.5-1.5s1.5.67 1.5 1.5v5c0 .83-.67 1.5-1.5 1.5z"/><path d="M20.5 10H19V8.5c0-.83.67-1.5 1.5-1.5s1.5.67 1.5 1.5-.67 1.5-1.5 1.5z"/><path d="M9.5 14c.83 0 1.5.67 1.5 1.5v5c0 .83-.67 1.5-1.5 1.5S8 21.33 8 20.5v-5c0-.83.67-1.5 1.5-1.5z"/><path d="M3.5 14H5v1.5c0 .83-.67 1.5-1.5 1.5S2 16.33 2 15.5 2.67 14 3.5 14z"/><path d="M14 14.5c0-.83.67-1.5 1.5-1.5h5c.83 0 1.5.67 1.5 1.5s-.67 1.5-1.5 1.5h-5c-.83 0-1.5-.67-1.5-1.5z"/><path d="M14 20.5c0-.83.67-1.5 1.5-1.5s1.5.67 1.5 1.5-.67 1.5-1.5 1.5-1.5-.67-1.5-1.5z"/><path d="M10 9.5C10 10.33 9.33 11 8.5 11h-5C2.67 11 2 10.33 2 9.5S2.67 8 3.5 8h5c.83 0 1.5.67 1.5 1.5z"/><path d="M10 3.5C10 4.33 9.33 5 8.5 5S7 4.33 7 3.5 7.67 2 8.5 2s1.5.67 1.5 1.5z"/></svg>' +
                        '<h3 style="color:#f0f0f0;margin-bottom:8px">Connect Your Slack</h3>' +
                        '<p style="color:#9ca3af;font-size:14px;max-width:400px;margin:0 auto 20px">Connect your Slack account so Tessa can read AI-generated meeting/huddle notes and surface action items, decisions, and reminders on your dashboard.</p>' +
                        '<button id="slackGoConnect" style="padding:10px 24px;border-radius:8px;border:none;background:#4A154B;color:#fff;font-size:14px;font-weight:600;cursor:pointer">Go to Profile to Connect</button>' +
                    '</div>' +
                '</div>';
                var goBtn = document.getElementById('slackGoConnect');
                if (goBtn) goBtn.onclick = function () {
                    if (MeetingModule && MeetingModule.switchView) MeetingModule.switchView('profile');
                };
                return;
            }

            // Connected — show full Slack page
            var teamName = statusData.team_name || 'Slack';
            root.innerHTML = '<div class="emp-wrap">' +
                '<div class="emp-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">' +
                    '<div>' +
                        '<h2 class="emp-title">Slack Insights · History</h2>' +
                        '<div style="font-size:12px;color:#34d399;margin-top:2px">Connected to ' + escapeHtml(teamName) + ' · extracted from huddle AI notes (auto-synced every 30 min)</div>' +
                    '</div>' +
                '</div>' +
                '<div id="slackFilterTabs" style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:16px">' +
                    '<button class="slack-tab active" data-filter="all" style="padding:5px 14px;border-radius:16px;border:1px solid #3f3f46;background:#1a1a2e;color:#a5b4fc;font-size:12px;cursor:pointer">All</button>' +
                    '<button class="slack-tab" data-filter="action_item" style="padding:5px 14px;border-radius:16px;border:1px solid #3f3f46;background:transparent;color:#6b7280;font-size:12px;cursor:pointer">Action Items</button>' +
                    '<button class="slack-tab" data-filter="reminder" style="padding:5px 14px;border-radius:16px;border:1px solid #3f3f46;background:transparent;color:#6b7280;font-size:12px;cursor:pointer">Reminders</button>' +
                    '<button class="slack-tab" data-filter="follow_up" style="padding:5px 14px;border-radius:16px;border:1px solid #3f3f46;background:transparent;color:#6b7280;font-size:12px;cursor:pointer">Follow-ups</button>' +
                    '<button class="slack-tab" data-filter="decision" style="padding:5px 14px;border-radius:16px;border:1px solid #3f3f46;background:transparent;color:#6b7280;font-size:12px;cursor:pointer">Decisions</button>' +
                '</div>' +
                '<div id="slackInsightsList"></div>' +
            '</div>';

            var currentFilter = 'all';
            var allInsights = [];

            // Tab click handlers
            root.querySelectorAll('.slack-tab').forEach(function (tab) {
                tab.addEventListener('click', function () {
                    root.querySelectorAll('.slack-tab').forEach(function (t) {
                        t.classList.remove('active');
                        t.style.background = 'transparent';
                        t.style.color = '#6b7280';
                    });
                    tab.classList.add('active');
                    tab.style.background = '#1a1a2e';
                    tab.style.color = '#a5b4fc';
                    currentFilter = tab.getAttribute('data-filter');
                    renderCards(filterInsights(allInsights, currentFilter));
                });
            });

            loadInsights();

            function loadInsights() {
                fetch('/api/slack/insights?archive=1', {
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                }).then(function (r) { return r.json(); }).then(function (data) {
                    allInsights = (data.ok && data.insights) ? data.insights : [];
                    renderCards(filterInsights(allInsights, currentFilter));
                });
            }

            function filterInsights(insights, filter) {
                if (filter === 'all') return insights;
                return insights.filter(function (i) { return i.type === filter; });
            }

            function renderCards(insights) {
                var listEl = document.getElementById('slackInsightsList');
                if (!insights || insights.length === 0) {
                    listEl.innerHTML = '<div style="text-align:center;padding:50px 20px">' +
                        '<div style="font-size:40px;margin-bottom:12px">💬</div>' +
                        '<h3 style="color:#f0f0f0;margin-bottom:6px">No insights yet</h3>' +
                        '<p style="color:#6b7280;font-size:13px">Insights are extracted automatically from your Slack huddle AI notes every 30 minutes. They\'ll appear here once your next huddle finishes.</p>' +
                    '</div>';
                    return;
                }

                var typeIcons = { action_item: '📋', reminder: '⏰', follow_up: '🔄', decision: '✅' };

                // Group by meeting occurrence (meeting_id + date) — one clean box per
                // meeting, mirroring the dashboard's "Suggestions from Huddles" layout.
                var groups = [];
                var groupIndex = {};
                for (var i = 0; i < insights.length; i++) {
                    var gins = insights[i];
                    var gKey = (gins.meeting_id || 'm') + '|' + (gins.meeting_date || '');
                    if (groupIndex[gKey] === undefined) {
                        groupIndex[gKey] = groups.length;
                        groups.push({ title: gins.meeting_label || gins.meeting_title || 'Huddle', date: gins.meeting_date || '', items: [] });
                    }
                    groups[groupIndex[gKey]].items.push(gins);
                }

                var html = '';
                for (var grpI = 0; grpI < groups.length; grpI++) {
                    var grp = groups[grpI];
                    var grpLabel = escapeHtml(grp.title) + (grp.date ? ' · ' + escapeHtml(grp.date) : '');
                    html += '<div class="dash-mi-group">' +
                        '<div class="dash-mi-group-header">' +
                            '<span class="dash-mi-group-title">' + grpLabel + '</span>' +
                            '<span class="dash-mi-group-count">' + grp.items.length + '</span>' +
                        '</div>' +
                        '<div class="dash-mi-group-list">';
                    for (var rowI = 0; rowI < grp.items.length; rowI++) {
                        var it = grp.items[rowI];
                        var icon = typeIcons[it.type] || '💡';
                        var actioned = (it.status === 'actioned');
                        html += '<div class="dash-mi-row" data-insight-id="' + it.id + '"' + (actioned ? ' style="opacity:0.55"' : '') + '>' +
                            '<button type="button" class="dash-mi-row-title" data-insight-id="' + it.id + '" title="View details">' + icon + ' ' + escapeHtml(it.title) + '</button>' +
                            '<div class="dash-mi-row-actions">' +
                                (actioned
                                    ? '<span style="font-size:11px;color:#10b981;padding:6px 10px">Task created</span>'
                                    : '<button type="button" class="btn btn-success btn-sm slk-arch-task" data-insight-id="' + it.id + '">+ Task</button>') +
                            '</div>' +
                        '</div>';
                    }
                    html += '</div></div>';
                }
                listEl.innerHTML = html;

                // Title → clean detail modal (same component the dashboard uses)
                listEl.querySelectorAll('.dash-mi-row-title').forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        var id = parseInt(btn.getAttribute('data-insight-id'), 10);
                        var ins = allInsights.filter(function (x) { return x.id === id; })[0];
                        if (ins) openInsightDetailModal(ins);
                    });
                });

                // + Task
                listEl.querySelectorAll('.slk-arch-task').forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        var id = btn.getAttribute('data-insight-id');
                        var row = btn.closest('.dash-mi-row');
                        btn.disabled = true; btn.textContent = 'Creating...';
                        fetch('/api/slack/insights/' + id + '/create-task', {
                            method: 'POST', credentials: 'same-origin',
                            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                        }).then(function (r) { return r.json(); }).then(function (data) {
                            if (data.ok) {
                                var actEl = row ? row.querySelector('.dash-mi-row-actions') : null;
                                if (actEl) actEl.innerHTML = '<span style="font-size:11px;color:#10b981;padding:6px 10px">Task created</span>';
                                if (row) row.style.opacity = '0.55';
                            } else {
                                btn.disabled = false; btn.textContent = '+ Task';
                                alert(data.error || 'Failed to create task');
                            }
                        }).catch(function () { btn.disabled = false; btn.textContent = '+ Task'; });
                    });
                });

            }

        }).catch(function () {
            root.innerHTML = '<div class="emp-wrap"><div class="emp-empty">Failed to load Slack. Please try again.</div></div>';
        });
    }

    /* ── Notes (Google Keep style) ── */
    var _notesCache = null;

    function renderNotes() {
        var root = document.getElementById('notesView');
        if (!root) return;
        root.innerHTML = '<div class="notes-wrap"><div class="notes-loading">Loading notes...</div></div>';
        loadNotes();
    }

    function loadNotes() {
        var root = document.getElementById('notesView');
        if (!root) return;

        requestJson('/api/notes').then(function (data) {
            _notesCache = data.notes || [];
            renderNotesUI(root);
        }).catch(function () {
            root.innerHTML = '<div class="notes-wrap"><div class="notes-loading">Failed to load notes.</div></div>';
        });
    }

    function renderNotesUI(root) {
        var notes = (_notesCache || []).filter(function (n) { return n.title || n.body; });
        var pinned = notes.filter(function (n) { return n.is_pinned; });
        var others = notes.filter(function (n) { return !n.is_pinned; });

        var html = '<div class="notes-wrap">';
        html += '<div class="notes-input-bar">' +
            '<input type="text" class="notes-input-title" id="notesNewTitle" placeholder="Title">' +
            '<textarea class="notes-input-body" id="notesNewBody" placeholder="Take a note..." rows="1" data-grammar-fix></textarea>' +
            '<div class="notes-input-actions hidden" id="notesInputActions">' +
            '<button type="button" class="btn btn-primary btn-sm" id="notesAddBtn">Save</button>' +
            '<button type="button" class="btn btn-sm" id="notesCancelBtn">Cancel</button>' +
            '</div></div>';

        if (pinned.length) {
            html += '<div class="notes-section-label">PINNED</div>';
            html += '<div class="notes-grid">';
            pinned.forEach(function (n) { html += noteCard(n); });
            html += '</div>';
        }

        if (others.length) {
            if (pinned.length) html += '<div class="notes-section-label">OTHERS</div>';
            html += '<div class="notes-grid">';
            others.forEach(function (n) { html += noteCard(n); });
            html += '</div>';
        }

        if (!notes.length) {
            html += '<div class="notes-empty">No notes yet. Start by taking a note above.</div>';
        }

        html += '</div>';
        root.innerHTML = html;

        var titleInput = document.getElementById('notesNewTitle');
        var bodyInput = document.getElementById('notesNewBody');
        var actionsRow = document.getElementById('notesInputActions');

        function showActions() { actionsRow.classList.remove('hidden'); bodyInput.rows = 3; }
        titleInput.addEventListener('focus', showActions);
        bodyInput.addEventListener('focus', showActions);

        document.getElementById('notesCancelBtn').addEventListener('click', function () {
            titleInput.value = '';
            bodyInput.value = '';
            actionsRow.classList.add('hidden');
            bodyInput.rows = 1;
        });

        document.getElementById('notesAddBtn').addEventListener('click', function () {
            var title = titleInput.value.trim();
            var body = bodyInput.value.trim();
            if (!title && !body) return;
            requestJson('/api/notes', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ title: title || null, body: body || null })
            }).then(function () { loadNotes(); });
        });

        root.querySelectorAll('.note-card').forEach(function (card) {
            var noteId = card.getAttribute('data-note-id');
            var note = notes.find(function (n) { return n.id == noteId; });
            if (!note) return;

            card.querySelector('.note-edit-btn').addEventListener('click', function (e) {
                e.stopPropagation();
                openNoteEditor(note);
            });

            card.querySelector('.note-pin-btn').addEventListener('click', function (e) {
                e.stopPropagation();
                requestJson('/api/notes/' + noteId, {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ is_pinned: !note.is_pinned })
                }).then(function () { loadNotes(); });
            });

            card.querySelector('.note-delete-btn').addEventListener('click', function (e) {
                e.stopPropagation();
                if (!confirm('Delete this note?')) return;
                requestJson('/api/notes/' + noteId, { method: 'DELETE' }).then(function () { loadNotes(); });
            });

            card.addEventListener('click', function () { openNoteEditor(note); });
        });
    }

    function noteCard(n) {
        var title = escapeHtml(n.title || '');
        var body = escapeHtml(n.body || '');
        var pinIcon = n.is_pinned
            ? '<svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" stroke="currentColor" stroke-width="2"><path d="M12 2L9.19 8.63 2 9.24l5.46 4.73L5.82 21 12 17.27 18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2z"/></svg>'
            : '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2L9.19 8.63 2 9.24l5.46 4.73L5.82 21 12 17.27 18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2z"/></svg>';
        var dateStr = new Date(n.updated_at).toLocaleDateString('en-IN', { timeZone: 'Asia/Kolkata', month: 'short', day: 'numeric' });

        return '<div class="note-card" data-note-id="' + n.id + '">' +
            '<div class="note-card-top">' +
            (title ? '<div class="note-card-title">' + title + '</div>' : '') +
            '</div>' +
            (body ? '<div class="note-card-body">' + body.replace(/\n/g, '<br>') + '</div>' : '') +
            '<div class="note-card-footer">' +
            '<span class="note-card-date">' + dateStr + '</span>' +
            '<span class="note-card-actions">' +
            '<button type="button" class="note-edit-btn" title="Edit"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg></button>' +
            '<button type="button" class="note-pin-btn" title="' + (n.is_pinned ? 'Unpin' : 'Pin') + '">' + pinIcon + '</button>' +
            '<button type="button" class="note-delete-btn" title="Delete"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/></svg></button>' +
            '</span></div></div>';
    }

    function openNoteEditor(note) {
        var overlay = document.createElement('div');
        overlay.className = 'note-editor-overlay';
        overlay.innerHTML = '<div class="note-editor-modal">' +
            '<input type="text" class="note-editor-title" value="' + escapeHtml(note.title || '') + '" placeholder="Title">' +
            '<textarea class="note-editor-body" placeholder="Note..." rows="8" data-grammar-fix>' + escapeHtml(note.body || '') + '</textarea>' +
            '<div class="note-editor-actions">' +
            '<button type="button" class="btn btn-primary btn-sm" id="noteEditorSave">Save</button>' +
            '<button type="button" class="btn btn-sm" id="noteEditorClose">Cancel</button>' +
            '</div></div>';
        document.body.appendChild(overlay);

        overlay.addEventListener('click', function (e) { if (e.target === overlay) closeEditor(); });
        overlay.querySelector('#noteEditorClose').addEventListener('click', closeEditor);
        overlay.querySelector('#noteEditorSave').addEventListener('click', function () {
            var t = overlay.querySelector('.note-editor-title').value.trim();
            var b = overlay.querySelector('.note-editor-body').value.trim();
            requestJson('/api/notes/' + note.id, {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ title: t || null, body: b || null })
            }).then(function () { closeEditor(); loadNotes(); });
        });

        function closeEditor() { overlay.remove(); }
    }

    // ── Birthday celebration ──
    // Self-contained party-popper/confetti burst (no external lib). Pieces are
    // position:fixed + pointer-events:none (see app.css) so they never block
    // the UI, and the layer auto-clears after the fall completes.
    function fireBdayConfetti(force) {
        var box = document.getElementById('bdayConfetti');
        if (!box) return;
        box.innerHTML = '';
        var colors = ['#db2777', '#9333ea', '#f59e0b', '#10b981', '#3b82f6', '#ef4444', '#fde047'];
        var poppers = ['🎉', '🎊', '🥳', '🎂', '✨', '🎈'];
        for (var i = 0; i < 170; i++) {
            var p = document.createElement('span');
            if (i % 5 === 0) {
                p.className = 'bday-emoji';
                p.textContent = poppers[Math.floor(Math.random() * poppers.length)];
            } else {
                p.className = 'bday-piece';
                p.style.background = colors[Math.floor(Math.random() * colors.length)];
            }
            p.style.left = (Math.random() * 100) + 'vw';
            p.style.setProperty('--dx', (Math.random() * 30 - 15).toFixed(1) + 'vw');
            p.style.animationDelay = (Math.random() * 1.4).toFixed(2) + 's';
            p.style.animationDuration = (3 + Math.random() * 2.5).toFixed(2) + 's';
            box.appendChild(p);
        }
        // Huge corner party-popper blasts + a big center pop.
        var bl = document.createElement('span');
        bl.className = 'bday-blast bday-blast--l';
        bl.textContent = '🎉';
        var br = document.createElement('span');
        br.className = 'bday-blast bday-blast--r';
        br.textContent = '🎉';
        var cheer = document.createElement('span');
        cheer.className = 'bday-cheer';
        cheer.textContent = '🥳 Happy Birthday! 🥳';
        box.appendChild(bl);
        box.appendChild(br);
        box.appendChild(cheer);

        box.classList.add('bday-confetti--on');
        // force = manual/demo test: defeat the prefers-reduced-motion CSS that
        // otherwise display:none's the whole layer, so it's always visible.
        if (force) box.style.setProperty('display', 'block', 'important');
        setTimeout(function () {
            box.classList.remove('bday-confetti--on');
            box.style.removeProperty('display');
            box.innerHTML = '';
        }, 8000);
    }

    // Manual replay from devtools: fireBirthday()  (ignores the once-per-session
    // guard and prefers-reduced-motion — purely for testing/demo).
    window.fireBirthday = function () { fireBdayConfetti(true); };

    function renderBirthdayBanner() {
        var el = document.getElementById('birthdayBanner');
        if (!el) return;
        // ?birthday=demo (or #birthday=demo / =1 / =test) forces the full
        // celebration on load regardless of DOB / session guard.
        if (/[?#&]birthday=(demo|test|1)\b/i.test(location.href)) {
            el.className = 'side-top-bar birthday-banner birthday-banner--me';
            el.innerHTML = '<span class="bday-ico">🎉</span> Happy Birthday! 🎂 The whole team is wishing you a wonderful year ahead. <em>(demo)</em>';
            el.style.display = '';
            document.body.classList.add('bday-mode-self');
            fireBdayConfetti(true);
            return;
        }
        var list = config.todaysBirthdays || [];
        var mine = !!(config.myBirthday && config.myBirthday.is);

        if (!list.length && !mine) { el.style.display = 'none'; return; }

        if (mine) {
            var myName = ((config.myBirthday && config.myBirthday.name) || '').split(' ')[0];
            el.className = 'side-top-bar birthday-banner birthday-banner--me';
            el.innerHTML = '<span class="bday-ico">🎉</span> Happy Birthday, <strong>' +
                escapeHtml(myName) + '</strong>! 🎂 The whole team is wishing you a wonderful year ahead.';
            document.body.classList.add('bday-mode-self');
            el.style.display = '';
            // Party-poppers are ONLY for the birthday person. Once per day per
            // browser session (the "sign-in" moment), not every view switch.
            try {
                var key = 'bdayCelebrated:' + new Date().toISOString().slice(0, 10) + ':me';
                if (!sessionStorage.getItem(key)) {
                    sessionStorage.setItem(key, '1');
                    fireBdayConfetti();
                }
            } catch (e) {
                fireBdayConfetti();
            }
        } else {
            var names = list.map(function (b) { return escapeHtml(b.name); });
            var joined = names.length === 1 ? names[0]
                : names.slice(0, -1).join(', ') + ' & ' + names[names.length - 1];
            el.className = 'side-top-bar birthday-banner birthday-banner--notify';
            el.innerHTML = '<span class="bday-ico">🎂</span> It\'s <strong>' + joined +
                '</strong>\'s birthday today — don\'t forget to wish ' +
                (names.length === 1 ? 'them' : 'them all') + ' a happy birthday! 🎉';
            el.style.display = '';
            // No party-poppers for non-birthday people — just the reminder.
        }
    }

    // ── Holiday + Birthday Calendar ──
    var holidayCalendarRendered = false;
    function renderHolidayCalendar() {
        var grid = document.getElementById('holidayCalendarGrid');
        var legend = document.getElementById('holidayLegend');
        var upcoming = document.getElementById('holidayUpcomingList');
        if (!grid) return;
        if (holidayCalendarRendered) return;
        holidayCalendarRendered = true;

        var holidays = config.holidays || {};
        var birthdays = config.birthdays || [];
        var bdayByMd = {};
        birthdays.forEach(function (b) {
            if (!b || !b.md) return;
            (bdayByMd[b.md] = bdayByMd[b.md] || []).push(b.name);
        });

        var today = new Date();
        var todayStr = today.getFullYear() + '-' + String(today.getMonth() + 1).padStart(2, '0') + '-' + String(today.getDate()).padStart(2, '0');
        var todayMd = String(today.getMonth() + 1).padStart(2, '0') + '-' + String(today.getDate()).padStart(2, '0');
        var year = 2026;
        var monthNames = ['January','February','March','April','May','June','July','August','September','October','November','December'];
        var dayLabels = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];

        if (legend) {
            legend.innerHTML =
                '<div class="holiday-legend">' +
                    '<span class="holiday-legend-item"><span class="holiday-legend-dot holiday-legend-dot--holiday"></span> Company Holiday</span>' +
                    '<span class="holiday-legend-item"><span class="holiday-legend-dot holiday-legend-dot--birthday"></span> Birthday</span>' +
                    '<span class="holiday-legend-item"><span class="holiday-legend-dot holiday-legend-dot--weekoff"></span> Weekend</span>' +
                    '<span class="holiday-legend-item"><span class="holiday-legend-dot holiday-legend-dot--today"></span> Today</span>' +
                '</div>';
        }

        var html = '<div class="holiday-calendar-grid">';
        for (var m = 0; m < 12; m++) {
            html += '<div class="holiday-month">';
            html += '<div class="holiday-month-header">' + monthNames[m] + '</div>';
            html += '<div class="holiday-day-grid">';
            for (var d = 0; d < 7; d++) {
                html += '<div class="holiday-day-label">' + dayLabels[d] + '</div>';
            }

            var firstDay = new Date(year, m, 1);
            var daysInMonth = new Date(year, m + 1, 0).getDate();
            var startDow = (firstDay.getDay() + 6) % 7; // Mon=0

            for (var blank = 0; blank < startDow; blank++) {
                html += '<div class="holiday-day holiday-day--empty"></div>';
            }

            for (var day = 1; day <= daysInMonth; day++) {
                var mdStr = String(m + 1).padStart(2, '0') + '-' + String(day).padStart(2, '0');
                var dateStr = year + '-' + mdStr;
                var dateObj = new Date(year, m, day);
                var dow = dateObj.getDay();
                var isWeekend = dow === 0 || dow === 6;
                var isHoliday = holidays.hasOwnProperty(dateStr);
                var bdayNames = bdayByMd[mdStr] || null;
                var isBirthday = !!bdayNames;
                var isToday = dateStr === todayStr;

                var cls = 'holiday-day';
                // Birthday tint takes precedence over weekend grey but yields
                // to the company-holiday amber. When both fall on the same
                // day we add both classes so the CSS can show a split tint.
                if (isHoliday) cls += ' holiday-day--holiday';
                else if (isBirthday) cls += ' holiday-day--birthday';
                else if (isWeekend) cls += ' holiday-day--weekoff';
                if (isHoliday && isBirthday) cls += ' holiday-day--bday-also';
                if (isToday) cls += ' holiday-day--today';
                if (isHoliday || isBirthday) cls += ' holiday-day--clickable';

                var tipParts = [];
                if (isHoliday) tipParts.push(holidays[dateStr]);
                if (isBirthday) tipParts.push('🎂 ' + bdayNames.join(', '));
                var tooltip = tipParts.length ? ' title="' + escapeHtml(tipParts.join(' • ')) + '"' : '';

                // Stash the names/holiday in data attrs so a click handler
                // can pop a small panel without rebuilding tooltip parsing.
                var dataAttrs = '';
                if (isBirthday) {
                    dataAttrs += ' data-bday-names="' + escapeHtml(bdayNames.join('|')) + '"';
                }
                if (isHoliday) {
                    dataAttrs += ' data-holiday-name="' + escapeHtml(holidays[dateStr]) + '"';
                }
                if (isHoliday || isBirthday) {
                    dataAttrs += ' data-cell-date="' + escapeHtml(dateStr) + '" role="button" tabindex="0"';
                }

                html += '<div class="' + cls + '"' + tooltip + dataAttrs + '>' + day + '</div>';
            }

            html += '</div></div>';
        }
        html += '</div>';
        grid.innerHTML = html;

        if (upcoming) {
            var upcomingHolidays = [];
            Object.keys(holidays).sort().forEach(function (d) {
                if (d >= todayStr) {
                    upcomingHolidays.push({ date: d, name: holidays[d] });
                }
            });

            // Only this year's remaining birthdays (today → Dec 31). Past
            // birthdays from earlier in the year are dropped so the list
            // doesn't wrap around into next year.
            var upcomingBirthdays = birthdays.filter(function (b) {
                return b && b.md && b.md >= todayMd;
            });

            var uhtml = '<div class="holiday-upcoming-wrap">';

            if (upcomingHolidays.length) {
                uhtml += '<div class="holiday-upcoming"><h3 class="holiday-upcoming-title">Upcoming Holidays</h3>';
                upcomingHolidays.forEach(function (h) {
                    var dt = new Date(h.date + 'T00:00:00');
                    var formatted = dt.toLocaleDateString('en-IN', { timeZone: 'Asia/Kolkata', weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' });
                    var isPast = h.date < todayStr;
                    uhtml += '<div class="holiday-upcoming-item' + (isPast ? ' holiday-upcoming-item--past' : '') + '">' +
                        '<span class="holiday-upcoming-dot"></span>' +
                        '<span class="holiday-upcoming-date">' + formatted + '</span>' +
                        '<span class="holiday-upcoming-name">' + escapeHtml(h.name) + '</span>' +
                    '</div>';
                });
                uhtml += '</div>';
            }

            if (upcomingBirthdays.length) {
                uhtml += '<div class="holiday-upcoming holiday-upcoming--birthdays"><h3 class="holiday-upcoming-title">Upcoming Birthdays</h3>';
                upcomingBirthdays.forEach(function (b) {
                    var parts = (b.md || '').split('-');
                    var mn = parseInt(parts[0], 10) - 1;
                    var dn = parseInt(parts[1], 10);
                    var dt = new Date(year, mn, dn);
                    // Birth-year is never sent — render month + day only.
                    var formatted = dt.toLocaleDateString('en-IN', { timeZone: 'Asia/Kolkata', weekday: 'short', day: 'numeric', month: 'short' });
                    var isToday = b.md === todayMd;
                    uhtml += '<div class="holiday-upcoming-item' + (isToday ? ' holiday-upcoming-item--bday-today' : '') + '">' +
                        '<span class="holiday-upcoming-dot holiday-upcoming-dot--birthday"></span>' +
                        '<span class="holiday-upcoming-date">' + formatted + '</span>' +
                        '<span class="holiday-upcoming-name">' + (isToday ? '🎂 ' : '') + escapeHtml(b.name) + '</span>' +
                    '</div>';
                });
                uhtml += '</div>';
            }

            uhtml += '</div>';
            upcoming.innerHTML = uhtml;
        }

        _wireHolidayDayClicks(grid);
    }

    // Wire click + keyboard activation on highlighted calendar cells so
    // tapping a pink/amber date reveals who/what it's for. One popover at a
    // time; click outside or press ESC to dismiss.
    function _wireHolidayDayClicks(grid) {
        if (!grid || grid.__bdayClicksWired) return;
        grid.__bdayClicksWired = true;

        function closePopover() {
            var existing = document.getElementById('holidayDayPopover');
            if (existing) existing.remove();
            document.removeEventListener('mousedown', _bdayOutside, true);
            document.removeEventListener('keydown', _bdayEsc, true);
        }
        function _bdayOutside(e) {
            var pop = document.getElementById('holidayDayPopover');
            if (pop && !pop.contains(e.target) && !e.target.closest('.holiday-day--clickable')) {
                closePopover();
            }
        }
        function _bdayEsc(e) { if (e.key === 'Escape') closePopover(); }

        function openPopover(cell) {
            closePopover();
            var dateStr = cell.getAttribute('data-cell-date') || '';
            var bdayRaw = cell.getAttribute('data-bday-names') || '';
            var holidayName = cell.getAttribute('data-holiday-name') || '';
            var names = bdayRaw ? bdayRaw.split('|').filter(Boolean) : [];

            var dateLabel = dateStr;
            try {
                var dt = new Date(dateStr + 'T00:00:00');
                if (!isNaN(dt.getTime())) {
                    dateLabel = dt.toLocaleDateString('en-IN', {
                        timeZone: 'Asia/Kolkata',
                        weekday: 'long', day: 'numeric', month: 'long', year: 'numeric'
                    });
                }
            } catch (e) {}

            var html = '<div class="holiday-popover-head">' + escapeHtml(dateLabel) + '</div>';
            if (holidayName) {
                html += '<div class="holiday-popover-row"><span class="holiday-popover-tag holiday-popover-tag--holiday">Holiday</span>'
                    + '<span class="holiday-popover-name">' + escapeHtml(holidayName) + '</span></div>';
            }
            if (names.length) {
                html += '<div class="holiday-popover-section">🎂 ' + (names.length > 1 ? 'Birthdays' : 'Birthday') + '</div>';
                names.forEach(function (n) {
                    html += '<div class="holiday-popover-row"><span class="holiday-popover-name">' + escapeHtml(n) + '</span></div>';
                });
            }

            var pop = document.createElement('div');
            pop.id = 'holidayDayPopover';
            pop.className = 'holiday-popover';
            pop.innerHTML = html;
            document.body.appendChild(pop);

            var rect = cell.getBoundingClientRect();
            var top = rect.bottom + 6 + window.scrollY;
            var left = rect.left + (rect.width / 2) - (pop.offsetWidth / 2) + window.scrollX;
            // Keep within viewport: 8px gutter on either side.
            left = Math.max(8, Math.min(window.innerWidth - pop.offsetWidth - 8, left));
            pop.style.top = top + 'px';
            pop.style.left = left + 'px';

            // Defer the outside-click listener so the click that opened the
            // popover doesn't immediately close it.
            setTimeout(function () {
                document.addEventListener('mousedown', _bdayOutside, true);
                document.addEventListener('keydown', _bdayEsc, true);
            }, 0);
        }

        grid.addEventListener('click', function (e) {
            var cell = e.target.closest('.holiday-day--clickable');
            if (!cell || !grid.contains(cell)) return;
            openPopover(cell);
        });
        grid.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter' && e.key !== ' ') return;
            var cell = e.target.closest('.holiday-day--clickable');
            if (!cell) return;
            e.preventDefault();
            openPopover(cell);
        });
    }

    // ── HR Attendance (Shoyab + HR allow-list) ──
    // mode: 'daily' shows the per-employee roster for one date; 'monthly'
    // hides the daily view entirely and only renders the monthly summary.
    var _attendanceState = { date: null, mode: 'daily' };
    function _attendanceTodayStr() {
        // Always returns today's date as the backend sees it (Asia/Kolkata).
        // The earlier hand-rolled offset math was double-counting +5:30 for
        // browsers already in IST and returning tomorrow's date — which
        // legitimately has zero sign-ins, so the whole roster read "Not
        // signed in". `en-CA` formats as YYYY-MM-DD by default.
        try {
            return new Intl.DateTimeFormat('en-CA', {
                timeZone: 'Asia/Kolkata',
                year: 'numeric', month: '2-digit', day: '2-digit',
            }).format(new Date());
        } catch (e) {
            // Fall back to local-browser date if Intl can't handle the
            // timezone (very old browsers). Better than returning the wrong
            // day silently.
            var d = new Date();
            return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
        }
    }
    function _attendanceFormatDateLabel(dateStr) {
        try {
            var dt = new Date(dateStr + 'T00:00:00');
            return dt.toLocaleDateString('en-IN', { timeZone: 'Asia/Kolkata', weekday: 'long', day: 'numeric', month: 'short', year: 'numeric' });
        } catch (e) { return dateStr; }
    }
    function _attendanceMonth() {
        return (_attendanceState.date || _attendanceTodayStr()).slice(0, 7);
    }
    function renderAttendance() {
        var root = document.getElementById('attendanceView');
        if (!root) return;
        if (!_attendanceState.date) _attendanceState.date = _attendanceTodayStr();

        var mode = _attendanceState.mode === 'monthly' ? 'monthly' : 'daily';
        var month = _attendanceMonth();
        var dailyExport = '/api/hr/attendance/daily?format=xlsx&date=' + encodeURIComponent(_attendanceState.date);
        var monthlyExport = '/api/hr/attendance/monthly?format=xlsx&month=' + encodeURIComponent(month);

        var dailyControls =
            '<button type="button" class="att-nav-btn" id="attPrevDay" aria-label="Previous day">&lt;</button>' +
            '<input type="date" id="attDate" class="att-date-input" value="' + _attendanceState.date + '">' +
            '<button type="button" class="att-nav-btn" id="attNextDay" aria-label="Next day">&gt;</button>' +
            '<button type="button" class="att-today-btn" id="attTodayBtn">Today</button>' +
            '<a class="att-export-btn" href="' + dailyExport + '">Export day · XLSX</a>';

        var monthlyControls =
            '<input type="month" id="attMonthInput" class="att-date-input" value="' + month + '">' +
            '<a class="att-export-btn" href="' + monthlyExport + '">Export month · XLSX</a>';

        root.innerHTML =
            '<div class="att-wrap">' +
                '<div class="att-header">' +
                    '<div>' +
                        '<h2 class="att-title">Attendance</h2>' +
                        '<p class="att-sub">' +
                            (mode === 'monthly'
                                ? 'Per-employee summary of working days, sign-ins, WFH, leaves and permission for the month.'
                                : 'Live roster of who signed in, who\'s on leave, and who filed their daily report.') +
                        '</p>' +
                    '</div>' +
                    '<div class="att-controls">' +
                        (mode === 'monthly' ? monthlyControls : dailyControls) +
                    '</div>' +
                '</div>' +
                '<div class="att-tabs" role="tablist">' +
                    '<button type="button" role="tab" class="att-tab' + (mode === 'daily' ? ' is-active' : '') + '" data-att-mode="daily">Daily</button>' +
                    '<button type="button" role="tab" class="att-tab' + (mode === 'monthly' ? ' is-active' : '') + '" data-att-mode="monthly">Monthly Summary</button>' +
                '</div>' +
                (mode === 'monthly'
                    ? '<div class="att-monthly" id="attMonthly"><div class="att-loading">Loading monthly summary…</div></div>'
                    : '<div class="att-summary" id="attSummary"></div>' +
                      '<div class="att-body" id="attBody"><div class="att-loading">Loading roster…</div></div>') +
            '</div>';

        function attach(id, fn, ev) {
            var el = document.getElementById(id);
            if (el) el[ev || 'onclick'] = fn;
        }
        root.querySelectorAll('[data-att-mode]').forEach(function (btn) {
            btn.onclick = function () {
                var next = btn.getAttribute('data-att-mode');
                if (next === _attendanceState.mode) return;
                _attendanceState.mode = next === 'monthly' ? 'monthly' : 'daily';
                renderAttendance();
            };
        });

        if (mode === 'daily') {
            attach('attPrevDay', function () { _shiftAttendanceDate(-1); });
            attach('attNextDay', function () { _shiftAttendanceDate(1); });
            attach('attTodayBtn', function () { _attendanceState.date = _attendanceTodayStr(); renderAttendance(); });
            var dateInput = document.getElementById('attDate');
            if (dateInput) dateInput.onchange = function () {
                var v = dateInput.value;
                if (!/^\d{4}-\d{2}-\d{2}$/.test(v)) return;
                _attendanceState.date = v;
                renderAttendance();
            };
            _loadAttendanceDaily();
        } else {
            var monthInput = document.getElementById('attMonthInput');
            if (monthInput) monthInput.onchange = function () {
                var v = monthInput.value;
                if (!/^\d{4}-\d{2}$/.test(v)) return;
                // Anchor the daily date to the first of the chosen month so
                // the "Daily" tab opens on a sensible date for that month.
                _attendanceState.date = v + '-01';
                renderAttendance();
            };
            _loadAttendanceMonthly();
        }
    }
    function _shiftAttendanceDate(delta) {
        var d = new Date(_attendanceState.date + 'T00:00:00');
        d.setDate(d.getDate() + delta);
        _attendanceState.date = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
        renderAttendance();
    }
    async function _loadAttendanceDaily() {
        var body = document.getElementById('attBody');
        var summary = document.getElementById('attSummary');
        if (!body) return;
        try {
            var data = await requestJson('/api/hr/attendance/daily?date=' + encodeURIComponent(_attendanceState.date));
            if (!data || !data.ok) throw new Error('Bad response');
            var s = data.summary || {};
            var dateLabel = _attendanceFormatDateLabel(data.date);
            if (summary) {
                var absentSub = (s.onLeaveCount || 0) + ' on leave · ' + (s.missingCount || 0) + ' no sign-in';
                // Surface a company holiday tag on the date label so HR can
                // see at a glance why the absent count is 0.
                var holidayBadge = data.isHoliday
                    ? ' <span class="att-holiday-badge">' + escapeHtml(data.holidayLabel || 'Holiday') + '</span>'
                    : '';
                summary.innerHTML =
                    '<div class="att-summary-label">' + escapeHtml(dateLabel) + holidayBadge + '</div>' +
                    '<div class="att-cards">' +
                        _attCard('Total active', s.totalCount || 0, 'total') +
                        _attCard('Signed in', s.signedInCount || 0, 'in') +
                        _attCard('WFH', s.wfhCount || 0, 'wfh') +
                        _attCard('Absent', s.absentCount || 0, 'miss', absentSub) +
                        _attCard('Reports filed', s.reportSubmittedCount || 0, 'report-in') +
                        _attCard('Reports pending', s.reportMissingCount || 0, 'report-miss') +
                    '</div>';
            }
            var items = Array.isArray(data.items) ? data.items.slice() : [];
            // Group order: on leave > not signed in > signed in > holiday.
            // Within each group, signed-off rows sink to the bottom so HR
            // sees active people first.
            var statusOrder = { on_leave: 0, missing: 1, signed_in: 2, wfh: 2, holiday: 3 };
            items.sort(function (a, b) {
                var ao = statusOrder[a.status] != null ? statusOrder[a.status] : 99;
                var bo = statusOrder[b.status] != null ? statusOrder[b.status] : 99;
                if (ao !== bo) return ao - bo;
                if (a.status === 'signed_in') {
                    var aOff = !!a.signedOffAt, bOff = !!b.signedOffAt;
                    if (aOff !== bOff) return aOff ? 1 : -1;
                }
                return (a.userName || '').localeCompare(b.userName || '');
            });
            if (!items.length) {
                body.innerHTML = '<div class="att-empty">No active employees found.</div>';
                return;
            }
            var rows = items.map(_attRow).join('');
            body.innerHTML =
                '<table class="att-table">' +
                    '<thead><tr>' +
                        '<th>Name</th><th>Role</th><th>Status</th><th>Sign-in</th><th>Sign-off</th><th>Daily report</th><th>Notes</th>' +
                    '</tr></thead>' +
                    '<tbody>' + rows + '</tbody>' +
                '</table>';
        } catch (err) {
            console.error('attendance daily failed', err);
            body.innerHTML = '<div class="att-error">Failed to load roster. Try again.</div>';
            if (summary) summary.innerHTML = '';
        }
    }
    function _attCard(label, value, kind, sub) {
        return '<div class="att-card att-card--' + kind + '">' +
            '<div class="att-card-val">' + value + '</div>' +
            '<div class="att-card-lbl">' + escapeHtml(label) + '</div>' +
            (sub ? '<div class="att-card-sub">' + escapeHtml(sub) + '</div>' : '') +
        '</div>';
    }
    function _attReportPill(it) {
        // Not expected — most non-KPI roles. Render a dash so HR can tell
        // it apart from a missing submission.
        if (!it.dailyReportExpected) return '<span class="att-muted">—</span>';
        if (it.dailyReportSubmitted) {
            return '<span class="att-pill att-pill--rep-in">Submitted</span>';
        }
        // On-leave or company-holiday + no report: don't flag it red; HR knows why.
        if (it.status === 'on_leave') return '<span class="att-muted">On leave</span>';
        if (it.status === 'holiday') return '<span class="att-muted">Holiday</span>';
        return '<span class="att-pill att-pill--rep-miss">Not submitted</span>';
    }
    function _attRow(it) {
        var status = it.status;
        var statusLabel = status === 'on_leave' ? 'On leave'
            : status === 'signed_in' ? (it.signedOffAt ? 'Signed off' : 'Signed in')
            : status === 'wfh' ? 'WFH'
            : status === 'holiday' ? 'Holiday'
            : 'Not signed in';
        var statusCls = status === 'on_leave' ? 'att-pill--leave'
            : status === 'signed_in' ? (it.signedOffAt ? 'att-pill--off' : 'att-pill--in')
            : status === 'wfh' ? 'att-pill--wfh'
            : status === 'holiday' ? 'att-pill--holiday'
            : 'att-pill--miss';
        var notes = '';
        if (it.leave) {
            var bits = [];
            if (it.leave.type) bits.push(escapeHtml(it.leave.type));
            if (it.leave.start_date && it.leave.end_date && it.leave.start_date !== it.leave.end_date) {
                bits.push(escapeHtml(it.leave.start_date) + ' → ' + escapeHtml(it.leave.end_date));
            }
            if (it.leave.reason) bits.push('“' + escapeHtml(it.leave.reason) + '”');
            if (Array.isArray(it.leave.hourly) && it.leave.hourly.length) {
                it.leave.hourly.forEach(function (h) {
                    var pieces = [];
                    if (h.type) pieces.push(escapeHtml(h.type));
                    if (h.from_time && h.to_time) pieces.push(escapeHtml(h.from_time) + '–' + escapeHtml(h.to_time));
                    else if (h.hours) pieces.push(h.hours + ' hr');
                    bits.push(pieces.join(' '));
                });
            }
            notes = bits.join(' · ');
        }
        var dotMap = {
            green: 'emp-dot-ok',
            yellow: 'emp-dot-warn',
            red: 'emp-dot-danger',
            gray: 'emp-dot-gray',
            outline: 'emp-dot-outline'
        };
        var signinDotCls = dotMap[it.signin_indicator] || 'emp-dot-outline';
        var signinDot = '<span class="emp-dot ' + signinDotCls + '"></span> ';
        var signinExtra = it.signin_delayed ? ' <span class="att-tag-delayed">Delayed</span>' : '';
        return '<tr class="att-row att-row--' + status + '">' +
            '<td class="att-name">' + escapeHtml(it.userName || '') + '</td>' +
            '<td class="att-role">' + escapeHtml(it.role || '—') + '</td>' +
            '<td><span class="att-pill ' + statusCls + '">' + statusLabel + '</span></td>' +
            '<td class="att-time">' + signinDot + (it.signedInAt ? escapeHtml(it.signedInAt) : '—') + signinExtra + '</td>' +
            '<td class="att-time">' + (it.signedOffAt ? escapeHtml(it.signedOffAt) : '—') + '</td>' +
            '<td class="att-report">' + _attReportPill(it) + '</td>' +
            '<td class="att-notes">' + (notes || '—') + '</td>' +
        '</tr>';
    }
    async function _loadAttendanceMonthly() {
        var box = document.getElementById('attMonthly');
        if (!box) return;
        var month = _attendanceMonth();
        box.innerHTML = '<div class="att-loading">Loading monthly summary…</div>';
        try {
            var data = await requestJson('/api/hr/attendance/monthly?month=' + encodeURIComponent(month));
            if (!data || !data.ok) throw new Error('Bad response');
            var label = escapeHtml(data.monthLabel || month);
            var working = data.workingDays || 0;
            // The month picker + export button live in the page header now,
            // so the in-panel head just labels the month and shows the
            // working-day denominator that the table is computed against.
            var head = '<div class="att-monthly-head">' +
                '<h3>' + label + '</h3>' +
                '<span class="att-monthly-meta">' + working + ' working day' + (working === 1 ? '' : 's') + ' counted</span>' +
            '</div>';
            if (!Array.isArray(data.items) || !data.items.length || working === 0) {
                box.innerHTML = head + '<div class="att-empty">No working-day data for this month yet.</div>';
                return;
            }
            var rows = data.items.map(function (r) {
                var permission = (r.permissionHours || 0);
                var permissionLabel = permission > 0 ? permission.toFixed(1).replace(/\.0$/, '') + ' hr' : '0';
                // Mid-month joiners get a small joining badge next to the
                // name so HR sees why their working-day count is lower than
                // the month total at the top.
                var joinBadge = '';
                if (r.joiningDate && r.joiningDate >= data.month + '-01') {
                    var jd = new Date(r.joiningDate + 'T00:00:00');
                    if (!isNaN(jd.getTime())) {
                        var jLabel = jd.toLocaleDateString('en-IN', { day: 'numeric', month: 'short' });
                        joinBadge = ' <span class="att-join-badge" title="Joined ' + escapeHtml(r.joiningDate) + '">joined ' + escapeHtml(jLabel) + '</span>';
                    }
                }
                var missed = (r.missedLoginDays != null)
                    ? r.missedLoginDays
                    : Math.max(0, (r.workingDays || 0) - (r.signedInDays || 0) - (r.leaveDays || 0));
                return '<tr>' +
                    '<td class="att-name">' + escapeHtml(r.userName || '') + joinBadge + '</td>' +
                    '<td class="att-role">' + escapeHtml(r.role || '—') + '</td>' +
                    '<td class="att-num">' + (r.workingDays || 0) + '</td>' +
                    '<td class="att-num att-num--in">' + (r.signedInDays || 0) + '</td>' +
                    '<td class="att-num att-num--miss">' + missed + '</td>' +
                    '<td class="att-num att-num--wfh">' + (r.wfhDays || 0) + '</td>' +
                    '<td class="att-num att-num--perm">' + permissionLabel + '</td>' +
                    '<td class="att-num att-num--leave">' + (r.leaveDays || 0) + '</td>' +
                    '<td class="att-num att-num--comp">' + (r.compensatedDays || 0) + '</td>' +
                '</tr>';
            }).join('');
            box.innerHTML = head +
                '<table class="att-table att-table--monthly">' +
                    '<thead><tr>' +
                        '<th>Name</th><th>Role</th>' +
                        '<th title="Weekdays from joining date (or month start) up to today, minus company holidays">Working days</th>' +
                        '<th title="Days the person worked — Tessa sign-in OR approved WFH. New joiners are credited for the gap between DOJ and their first sign-in.">Logged in</th>' +
                        '<th title="Working days without a sign-in, WFH approval, or leave">Missed login</th>' +
                        '<th title="Sub-detail of Logged in — days approved as Work From Home">WFH</th><th>Permission</th><th>Leaves</th>' +
                        '<th title="Compensate swaps: weekdays taken off in exchange for a weekend worked. Info column — does not affect working-day totals.">Compensated</th>' +
                    '</tr></thead>' +
                    '<tbody>' + rows + '</tbody>' +
                '</table>';
        } catch (err) {
            console.error('attendance monthly failed', err);
            box.innerHTML = '<div class="att-error">Failed to load monthly summary.</div>';
        }
    }

    var teamStatusMounted = false;
    var _signinStatusRefreshTimer = null;
    function _clearSigninStatusTimer() {
        if (_signinStatusRefreshTimer) {
            clearInterval(_signinStatusRefreshTimer);
            _signinStatusRefreshTimer = null;
        }
    }
    function _signinInitials(name) {
        var parts = String(name || '').trim().split(/\s+/).filter(Boolean);
        if (!parts.length) return '?';
        if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
        return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
    }
    // Map sign-in indicator -> constellation node colour theme.
    function _signinNodeTone(it) {
        var ind = it.indicator || 'outline';
        if (ind === 'green') return 'green';
        if (ind === 'red') return 'red';
        if (ind === 'yellow') return 'yellow';
        if (ind === 'gray') return 'amber';   // leave / holiday
        return 'purple';                       // outline = before 10:00
    }
    // Spread N nodes across elliptical orbit rings (rx, ry as % of the stage).
    function _signinRingAlloc(n) {
        var rings = [
            { rx: 15, ry: 14, cap: 6 },
            { rx: 26, ry: 24, cap: 12 },
            { rx: 36, ry: 33, cap: 18 },
            { rx: 44, ry: 40, cap: 28 },
            { rx: 48, ry: 45, cap: 9999 }
        ];
        var out = [], remaining = n;
        for (var i = 0; i < rings.length && remaining > 0; i++) {
            var c = Math.min(rings[i].cap, remaining);
            out.push({ rx: rings[i].rx, ry: rings[i].ry, count: c });
            remaining -= c;
        }
        return out;
    }
    function _sinIcon(name) {
        var paths = {
            check: '<path d="M20 6 9 17l-5-5"/>',
            x: '<path d="M18 6 6 18M6 6l12 12"/>',
            umbrella: '<path d="M12 2v2"/><path d="M4.6 12a7.5 7.5 0 0 1 14.8 0z"/><path d="M12 12v6a2 2 0 0 0 4 0"/>',
            clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
            refresh: '<path d="M21 12a9 9 0 1 1-2.64-6.36"/><path d="M21 3v5h-5"/>'
        };
        return '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' + (paths[name] || '') + '</svg>';
    }

    function _bindSigninTooltips(root) {
        var tip = document.getElementById('sinTooltip');
        var inner = document.getElementById('sinTooltipInner');
        if (!tip || !inner) return;

        var statusLabels = { green: 'Signed In', red: 'Not Signed In', yellow: '10:00 – 10:30 AM', amber: 'On Leave / Holiday', purple: 'Before 10:00 AM', outline: 'Not Signed In' };
        var statusColors = { green: '#34d399', red: '#f87171', yellow: '#fde047', amber: '#f59e0b', purple: '#a855f7', outline: '#6b7280' };
        var mtgStatusLabel = { green: 'Attended', red: 'Absent', gray: 'Notes pending' };

        root.querySelectorAll('.sin-node[data-sin-tip]').forEach(function (node) {
            node.addEventListener('mouseenter', function (e) {
                var d;
                try { d = JSON.parse(node.getAttribute('data-sin-tip')); } catch (_) { return; }

                var statusLabel = statusLabels[d.indicator] || 'Unknown';
                var statusColor = statusColors[d.indicator] || '#6b7280';
                var delayedBadge = d.delayed ? '<span style="margin-left:6px;font-size:10px;color:#fb923c;font-weight:600;">· Late</span>' : '';
                var timeRow = d.signedInAt ? '<div class="sin-tip-time">Signed in at ' + escapeHtml(d.signedInAt) + (d.delayed ? ' (after 10:30 AM)' : '') + '</div>' : '';

                var mtgsHtml = '';
                if (d.meetings && d.meetings.length) {
                    var rows = d.meetings.map(function (m) {
                        var st = m.status || 'gray';
                        var lbl = escapeHtml((m.title || 'Meeting') + (m.time ? ' · ' + m.time : ''));
                        return '<div class="sin-tip-mtg-row"><span class="sin-tip-mtg-dot ' + st + '"></span><span>' + lbl + ' — ' + (mtgStatusLabel[st] || st) + '</span></div>';
                    }).join('');
                    mtgsHtml = '<div class="sin-tip-mtgs-label">Meetings</div>' + rows;
                }

                inner.innerHTML =
                    '<div class="sin-tip-name">' + escapeHtml(d.name) + '</div>' +
                    (d.designation ? '<div class="sin-tip-role">' + escapeHtml(d.designation) + '</div>' : '') +
                    '<div class="sin-tip-status">' +
                        '<span class="sin-tip-status-dot" style="background:' + statusColor + ';color:' + statusColor + '"></span>' +
                        '<span style="color:' + statusColor + '">' + statusLabel + '</span>' + delayedBadge +
                    '</div>' +
                    timeRow +
                    mtgsHtml;

                var rect = node.getBoundingClientRect();
                var tw = 220;
                var left = rect.left + rect.width / 2 - tw / 2;
                var top = rect.top - 8;

                // Clamp to viewport
                if (left + tw > window.innerWidth - 12) left = window.innerWidth - tw - 12;
                if (left < 8) left = 8;

                tip.style.left = left + 'px';
                tip.style.top = top + 'px';
                tip.style.width = tw + 'px';
                tip.style.transform = 'translateY(-100%) translateY(-4px) scale(0.97)';
                tip.style.transformOrigin = 'bottom center';

                requestAnimationFrame(function () {
                    tip.classList.add('sin-tooltip-visible');
                    tip.style.transform = 'translateY(-100%) translateY(-4px) scale(1)';
                });
            });

            node.addEventListener('mouseleave', function () {
                tip.classList.remove('sin-tooltip-visible');
            });
        });
    }

    function renderSigninStatus() {
        var root = document.getElementById('signin_statusView');
        if (!root) return;
        _clearSigninStatusTimer();

        async function loadGrid() {
            try {
                var data = await requestJson('/api/admin/signin-status');
                if (!data || !data.ok) throw new Error('Bad response');
                var items = data.items || [];
                var updated = '';
                if (data.updatedAt) {
                    try {
                        updated = new Date(data.updatedAt).toLocaleTimeString('en-IN', {
                            hour: '2-digit', minute: '2-digit', hour12: true, timeZone: 'Asia/Kolkata'
                        });
                    } catch (_) {}
                }

                var cSigned = 0, cDelayed = 0, cMissing = 0, cLeave = 0, cBefore = 0, cApproach = 0;
                items.forEach(function (it) {
                    var ind = it.indicator || 'outline';
                    if (ind === 'green') { cSigned++; if (it.delayed) cDelayed++; }
                    else if (ind === 'red') cMissing++;
                    else if (ind === 'yellow') cApproach++;
                    else if (ind === 'gray') cLeave++;
                    else cBefore++;
                });
                var total = items.length;
                var mtgLabel = { green: 'Attended', red: 'Absent', gray: 'Notes not updated' };

                // Position nodes around elliptical orbit rings.
                var alloc = _signinRingAlloc(items.length);
                var orbitHtml = '', nodeHtml = '', idx = 0;
                alloc.forEach(function (r, ri) {
                    orbitHtml += '<div class="sin-orbit" style="width:' + (2 * r.rx) + '%;height:' + (2 * r.ry) + '%"></div>';
                    var ringNodes = '';
                    for (var i = 0; i < r.count && idx < items.length; i++, idx++) {
                        var it = items[idx];
                        var ang = (-90 + (360 * i / r.count) + (ri % 2 ? (180 / r.count) : 0)) * Math.PI / 180;
                        var left = 50 + r.rx * Math.cos(ang);
                        var top = 50 + r.ry * Math.sin(ang);
                        var tone = _signinNodeTone(it);
                        var dots = (it.meetings || []).slice(0, 8).map(function (m) {
                            var st = m.status || 'gray';
                            return '<span class="sin-mtg sin-mtg-' + st + '"></span>';
                        }).join('');
                        var mtgWrap = dots ? '<div class="sin-node-mtgs">' + dots + '</div>' : '';
                        var late = it.delayed ? '<span class="sin-late"></span>' : '';
                        var tipData = JSON.stringify({
                            name: it.name || '',
                            designation: it.designation || '',
                            indicator: it.indicator || 'outline',
                            delayed: !!it.delayed,
                            signedInAt: it.signedInAt || '',
                            meetings: (it.meetings || []).slice(0, 8)
                        });
                        ringNodes += '<div class="sin-node sin-tone-' + tone + '" style="left:' + left.toFixed(2) + '%;top:' + top.toFixed(2) + '%" data-sin-tip="' + escapeHtml(tipData) + '">' +
                            '<div class="sin-orb">' +
                                '<span class="sin-orb-init">' + escapeHtml(_signinInitials(it.name)) + '</span>' +
                                late + mtgWrap +
                            '</div>' +
                            '<div class="sin-node-name">' + escapeHtml(it.name || '') + '</div>' +
                        '</div>';
                    }
                    nodeHtml += '<div class="sin-ring sin-ring-' + ri + '">' + ringNodes + '</div>';
                });

                var legend = '<div class="sin-legend">' +
                    '<span class="sin-legend-item"><span class="sin-leg-dot sin-tone-green"></span>Signed In <b>' + cSigned + '</b></span>' +
                    '<span class="sin-legend-item"><span class="sin-leg-dot sin-tone-red"></span>Not Signed In <b>' + cMissing + '</b></span>' +
                    (cApproach ? '<span class="sin-legend-item"><span class="sin-leg-dot sin-tone-yellow"></span>10:00–10:30 <b>' + cApproach + '</b></span>' : '') +
                    '<span class="sin-legend-item"><span class="sin-leg-dot sin-tone-amber"></span>Leave / Holiday <b>' + cLeave + '</b></span>' +
                    '<span class="sin-legend-item"><span class="sin-leg-dot sin-tone-purple"></span>Before 10:00 <b>' + cBefore + '</b></span>' +
                '</div>';

                var summary = '<div class="sin-stats">' +
                    '<div class="sin-stat sin-tone-green"><span class="sin-stat-ic">' + _sinIcon('check') + '</span><div><div class="sin-stat-label">Signed In</div><div class="sin-stat-num">' + cSigned + '</div></div></div>' +
                    '<div class="sin-stat sin-tone-red"><span class="sin-stat-ic">' + _sinIcon('x') + '</span><div><div class="sin-stat-label">Not Signed In</div><div class="sin-stat-num">' + cMissing + '</div></div></div>' +
                    '<div class="sin-stat sin-tone-amber"><span class="sin-stat-ic">' + _sinIcon('umbrella') + '</span><div><div class="sin-stat-label">Leave / Holiday</div><div class="sin-stat-num">' + cLeave + '</div></div></div>' +
                    '<div class="sin-stat sin-tone-purple"><span class="sin-stat-ic">' + _sinIcon('clock') + '</span><div><div class="sin-stat-label">Before 10:00 AM</div><div class="sin-stat-num">' + cBefore + '</div></div></div>' +
                '</div>';

                root.innerHTML = '<div class="sin-wrap">' +
                    '<div class="sin-head">' +
                        '<div><h2 class="sin-title">Sign-In Status</h2>' + legend + '</div>' +
                        '<div class="sin-head-right">' +
                            '<span class="sin-updated">' + (updated ? 'Last updated: ' + escapeHtml(updated) : '') + '</span>' +
                            '<button type="button" class="sin-refresh" id="sinRefreshBtn" title="Refresh">' + _sinIcon('refresh') + '</button>' +
                        '</div>' +
                    '</div>' +
                    '<div class="sin-note">Green + Delayed dot = signed in after 10:30 AM · meeting dots: green attended, red absent, grey notes pending</div>' +
                    '<div class="sin-stage">' +
                        '<div class="sin-stars"></div>' +
                        orbitHtml +
                        '<div class="sin-hub"><div class="sin-hub-inner">' +
                            '<div class="sin-hub-label">Today\'s Attendance</div>' +
                            '<div class="sin-hub-num">' + cSigned + '</div>' +
                            '<div class="sin-hub-sub">of ' + total + '</div>' +
                        '</div></div>' +
                        nodeHtml +
                        '<div class="sin-tooltip" id="sinTooltip"><div class="sin-tooltip-inner" id="sinTooltipInner"></div></div>' +
                    '</div>' +
                    summary +
                '</div>';

                var rb = document.getElementById('sinRefreshBtn');
                if (rb) rb.onclick = function () { loadGrid(); };

                _bindSigninTooltips(root);
            } catch (err) {
                console.error('signin status failed', err);
                root.innerHTML = '<div class="si-error">Failed to load sign-in status.</div>';
            }
        }

        root.innerHTML = '<div class="si-loading">Loading sign-in status…</div>';
        loadGrid();
        _signinStatusRefreshTimer = setInterval(loadGrid, 60000);
    }
    function renderTeamStatus() {
        var root = document.getElementById('team_statusView');
        if (!root) return;
        if (teamStatusMounted) return;
        if (!window.TeamStatusTable) {
            root.innerHTML = '<div style="padding:24px;color:#f87171">Team Status widget failed to load.</div>';
            return;
        }
        teamStatusMounted = true;
        window.TeamStatusTable.mount({ rootEl: root });
    }

    function onSwitchView(view) {
        if (view !== 'signin_status') _clearSigninStatusTimer();
        // Remember the active tab (per-user) so a refresh / auto-refresh / browser-tab
        // switch restores it instead of resetting to the first sidebar item.
        try { localStorage.setItem('portalActiveView:' + (config.userId || ''), view); } catch (e) {}
        if (view === 'dashboard') renderDashboard();
        if (view === 'tessa') renderTessa();
        if (view === 'tasks') renderTasks();
        if (view === 'checklists' && window.TasksModule && window.TasksModule.renderChecklists) window.TasksModule.renderChecklists();
        if (view === 'notes') renderNotes();
        if (view === 'logs' && window.LogsModule) LogsModule.render();
        if (view === 'claude_context' && window.ClaudeContextModule) ClaudeContextModule.render();
        if (view === 'ai_expense' && window.AiExpenseModule) AiExpenseModule.render();
        if (view === 'daily') {
            restorePortalHash();
            renderDailyReports();
        }
        if (view === 'mkpi') {
            restorePortalHash();
            renderMarketingKpis();
        }
        if (view === 'tickets') renderTickets();
        if (view === 'revenue') renderRevenue();
        if (view === 'hima_revenue_sheet' && window.HimaRevenueSheetModule) window.HimaRevenueSheetModule.render();
        if (view === 'invoices') renderInvoices();
        if (view === 'meta_ads') renderMetaAds();
        if (view === 'google_ads') renderGoogleAds();
        if (view === 'mission') renderMission();
        if (view === 'employees') renderEmployees();
        if (view === 'hr_dashboard') renderHRDashboard();
        if (view === 'letters') renderLetters();
        if (view === 'salary_tool' && window.SalaryToolModule) SalaryToolModule.render();
        if (view === 'profile') renderProfile();
        if (view === 'leave') renderLeave();
        if (view === 'team_leave') renderTeamLeave();
        if (view === 'holidays') renderHolidayCalendar();
        if (view === 'my_score') renderMyScore();
        if (view === 'manager_ratings') renderManagerRatings();
        if (view === 'kpi_report') renderKpiReport();
        if (view === 'slack') renderSlack();
        if (view === 'schedule') renderSchedule();
        if (view === 'github') renderGitHub();
        if (view === 'google') renderGoogle();
        if (view === 'archives') renderArchives();
        if (view === 'hr_records') renderHrRecords();
        if (view === 'policies') {
            // Lazy-load the embedded policy handbook the first time it's opened.
            var pf = document.querySelector('#policiesView iframe[data-src]');
            if (pf && !pf.getAttribute('src')) pf.setAttribute('src', pf.getAttribute('data-src'));
        }
        if (view === 'network_leverage') renderNetworkLeverage();
        if (view === 'team_status') renderTeamStatus();
        if (view === 'org' && window.InnovfixOrgChart) window.InnovfixOrgChart.mount('orgView');
        if (view === 'timesheets' && window.TessaTimesheets) window.TessaTimesheets.render(document.getElementById('timesheetsView'));
        if (view === 'weeklyTimesheet' && window.WeeklyTimesheet) window.WeeklyTimesheet.render(document.getElementById('weeklyTimesheetView'));
        if (view === 'workforceAdmin' && window.WorkforceAdmin) window.WorkforceAdmin.render(document.getElementById('workforceAdminView'));
        if (view === 'timesheetTracker' && window.TimesheetTracker) window.TimesheetTracker.render(document.getElementById('timesheetTrackerView'));
        if (view === 'rewardPool' && window.RewardPool) window.RewardPool.render(document.getElementById('rewardPoolView'));
        if (view === 'rewards' && window.Rewards) window.Rewards.render(document.getElementById('rewardsView'));
        if (view === 'bills' && window.Bills) window.Bills.render(document.getElementById('billsView'));
        if (view === 'hiring' && window.Hiring) window.Hiring.render(document.getElementById('hiringView'));
        if (view === 'calendar' && window.TessaCalendar) window.TessaCalendar.render(document.getElementById('calendarView'));
        if (view === 'attendance') renderAttendance();
        if (view === 'signin_status') renderSigninStatus();
        // JP AI Command Center: (re)bind the chat and drop the floating "Back to
        // AI" button now that JP is back on the chat view.
        if (view === 'ai' && window.JpAI) {
            window.JpAI.renderView();
            window.JpAI.onReturnToAi();
        }
        setTimeout(syncPortalHash, 100);
    }

    // ── Daily sign-in gate ───────────────────────────────────────────────
    // Until the user completes their daily sign-in, the portal is locked to
    // the Leave section only. Dashboard stays reachable because that is where
    // the sign-in toggle lives. The gate only applies to portals that
    // actually have the Dashboard sign-in flow — a portal with no Dashboard
    // tab has no way to sign in, so gating it would lock the user out.
    var SIGNIN_GATE_ALLOWED = { dashboard: true, leave: true, ai: true };
    var signinGateApplies = (config.features || []).indexOf('dashboard') !== -1;
    var _signinToastTimer = null;

    // ── New-hire onboarding lock ─────────────────────────────────────────────
    // A freshly-onboarded hire is restricted to My Profile until they complete
    // the checklist (required fields + mandatory docs), then "Finish onboarding".
    var onboardingLocked = !!config.onboardingLocked;
    // Feature 4: views a locked new joiner may use. Profile + Checklist while on
    // probation; Daily Reports is added once their profile is complete. Everything
    // else stays blocked until probation ends (then config.onboardingLocked is false).
    var onboardingAllowedViews = (config.onboardingAllowedViews && config.onboardingAllowedViews.length)
        ? config.onboardingAllowedViews
        : ['profile'];

    function _obCsrf() { var m = document.querySelector('meta[name="csrf-token"]'); return m ? m.content : ''; }

    function showOnboardingToast() {
        var el = document.getElementById('signinGateToast');
        if (!el) { el = document.createElement('div'); el.id = 'signinGateToast'; el.className = 'signin-gate-toast'; el.setAttribute('role', 'status'); document.body.appendChild(el); }
        el.textContent = onboardingAllowedViews.indexOf('daily_reports') === -1
            ? 'Complete your profile to unlock full access.'
            : 'Full access unlocks when your probation ends.';
        el.classList.add('show');
        if (_signinToastTimer) clearTimeout(_signinToastTimer);
        _signinToastTimer = setTimeout(function () { el.classList.remove('show'); }, 3600);
    }

    function renderOnboardingBanner() {
        var host = document.querySelector('.side-content');
        if (!host || document.getElementById('onboardingBanner')) return;
        var bar = document.createElement('div');
        bar.className = 'onboarding-banner';
        bar.id = 'onboardingBanner';
        host.insertBefore(bar, host.firstChild);
        paintOnboardingBanner(bar);
    }

    function paintOnboardingBanner(bar) {
        bar.innerHTML = '<div class="ob-title">Welcome to InnovFix! 🎉</div><div class="ob-hint">Loading your onboarding checklist…</div>';
        fetch('/api/hiring/onboarding', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                var s = (res && res.status) || { fields: [], docs: [], complete: false };
                var items = (s.fields || []).concat(s.docs || []);
                var html = '<div class="ob-title">Welcome to InnovFix! Complete your onboarding to get started.</div>';
                html += '<div class="ob-items">';
                items.forEach(function (it) { html += '<span class="ob-item ' + (it.done ? 'done' : '') + '">' + (it.done ? '✓ ' : '○ ') + it.label + '</span>'; });
                html += '</div>';
                html += '<div class="ob-actions"><span class="ob-hint">Fill these in below under <b>My Profile</b> — personal details + upload documents.</span>';
                html += '<button class="ob-refresh" id="obRefresh">Refresh</button>';
                html += '<button class="ob-finish" id="obFinish"' + (s.complete ? '' : ' disabled') + '>Finish onboarding</button>';
                html += '</div>';
                bar.innerHTML = html;
                var ref = document.getElementById('obRefresh'); if (ref) ref.onclick = function () { paintOnboardingBanner(bar); };
                var fin = document.getElementById('obFinish'); if (fin) fin.onclick = finishOnboarding;
            })
            .catch(function () { bar.innerHTML = '<div class="ob-title">Welcome!</div><div class="ob-hint">Could not load your checklist — refresh the page.</div>'; });
    }

    function finishOnboarding() {
        fetch('/api/hiring/onboarding/complete', { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': _obCsrf() } })
            .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
            .then(function (res) {
                if (!res.ok) { var bar = document.getElementById('onboardingBanner'); if (bar) paintOnboardingBanner(bar); alert((res.j && res.j.error) || 'Please complete all items first.'); return; }
                location.reload();
            })
            .catch(function () { alert('Could not finish onboarding. Please try again.'); });
    }

    // Declutter the sidebar while the workday is locked (not yet signed in, or
    // already signed off). guardSwitchView already BLOCKS navigation to
    // everything but Dashboard + Leave; this hides those blocked nav items
    // (and Change Password) via a body class so the locked portal shows only
    // the profile photo, monthly KRA, Dashboard/Leave and Logout. The blade
    // sets this class on first paint; here we keep it in sync live as the
    // sign-in/off state flips, so it clears the instant the user signs in.
    function applySigninLockUi() {
        if (!signinGateApplies) return;
        var locked = !window.__dashSignedIn || window.__dashSignedOff;
        document.body.classList.toggle('is-signin-locked', locked);
    }

    function showSigninGateToast(blockedView) {
        var labelEl = document.querySelector('.top-nav-link[data-view="' + blockedView + '"] .side-nav-label');
        var name = labelEl ? labelEl.textContent.trim() : 'that section';
        var el = document.getElementById('signinGateToast');
        if (!el) {
            el = document.createElement('div');
            el.id = 'signinGateToast';
            el.className = 'signin-gate-toast';
            el.setAttribute('role', 'status');
            document.body.appendChild(el);
        }
        // Two locked states: never signed in today, or already signed off.
        // Use different wording so the user knows whether to sign in or to
        // wake the cat back up.
        el.textContent = window.__dashSignedOff
            ? 'You\'ve signed off for today — wake the cat to come back to ' + name + '.'
            : 'Sign in to start your day before opening ' + name + '.';
        el.classList.add('show');
        if (_signinToastTimer) clearTimeout(_signinToastTimer);
        _signinToastTimer = setTimeout(function () { el.classList.remove('show'); }, 3600);
    }

    // Passed to MeetingModule.switchView — returns the view to actually show.
    // Sidebar is locked to Dashboard + Leave both when the user hasn't yet
    // signed in today AND when they've already signed off (workday is done
    // until they deliberately wake the cat back up).
    function guardSwitchView(view, opts) {
        if (onboardingLocked) {
            if (onboardingAllowedViews.indexOf(view) !== -1) return view;
            if (!(opts && opts.initial)) showOnboardingToast();
            return 'profile';
        }
        if (!signinGateApplies) return view;
        var locked = !window.__dashSignedIn || window.__dashSignedOff;
        if (!locked) return view;
        if (SIGNIN_GATE_ALLOWED[view]) return view;
        if (!(opts && opts.initial)) showSigninGateToast(view);
        return 'dashboard';
    }

    // ── Network Leverage ──
    var nlWeekStart = startOfWeek(new Date());
    var NL_USER_ID = 4; // Ayush
    var nlShowForm = false;

    function nlWeekKey() { return weekKey(nlWeekStart); }
    function nlFormatDate(d) {
        var dt = new Date(d);
        return dt.toLocaleDateString('en-GB', { timeZone: 'Asia/Kolkata', day: 'numeric', month: 'short', year: 'numeric' });
    }

    function nlBuildEventCard(ev, isAyush) {
        var contactLines = ev.contacts ? ev.contacts.split('\n').map(function(l){return l.trim();}).filter(Boolean) : [];
        var linkedinLines = ev.linkedin_urls ? ev.linkedin_urls.split('\n').map(function(l){return l.trim();}).filter(Boolean) : [];
        var contactsHtml = contactLines.map(function (name, i) {
            var url = linkedinLines[i] || '';
            var badge = url && url.match(/linkedin\.com/i)
                ? '<a href="' + escapeHtml(url) + '" target="_blank" rel="noopener" class="nl-li-badge"><svg width="14" height="14" fill="currentColor" viewBox="0 0 24 24"><path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433a2.062 2.062 0 01-2.063-2.065 2.064 2.064 0 112.063 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z"/></svg> LinkedIn</a>'
                : '';
            return '<div class="nl-contact-row"><span class="nl-contact-name">' + escapeHtml(name) + '</span>' + badge + '</div>';
        }).join('');

        var attendeeInfo = ev.co_attendees
            ? escapeHtml(ev.co_attendees) + (ev.attendee_count ? ' (' + ev.attendee_count + ' attendees)' : '')
            : (isAyush ? 'Only Me' : 'Ayush');

        var deleteBtn = isAyush ? '<button class="nl-delete-btn" data-event-id="' + ev.id + '" title="Delete">&times;</button>' : '';

        return '<div class="nl-event-card">' +
            '<div class="nl-event-header">' +
                '<div class="nl-event-title">' + escapeHtml(ev.event_name) + '</div>' +
                '<div class="nl-event-meta">' +
                    '<span class="nl-event-date"><svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg> ' + nlFormatDate(ev.event_date) + '</span>' +
                    '<span class="nl-event-attendees"><svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg> ' + attendeeInfo + '</span>' +
                '</div>' +
                deleteBtn +
            '</div>' +
            (contactLines.length > 0 ? (
                '<div class="nl-event-contacts">' +
                    '<div class="nl-event-contacts-title">Connections <span class="nl-contact-count">' + contactLines.length + '</span></div>' +
                    contactsHtml +
                '</div>'
            ) : '') +
        '</div>';
    }

    async function renderNetworkLeverage() {
        var root = document.getElementById('network_leverageView');
        if (!root) return;
        root.innerHTML = '<div class="kpi-wrap"><div class="kpi-status-msg">Loading...</div></div>';

        var isAyush = config.userId === NL_USER_ID;
        var wk = nlWeekKey();
        var wkEnd = addDays(nlWeekStart, 6);
        var isCurrentWeek = weekKey(startOfWeek(new Date())) === wk;

        var weekNavHtml = '<div class="kpi-week-nav">' +
            '<button class="mtg-nav-btn" id="nlPrevWeek">&#8592;</button>' +
            '<div class="kpi-week-pill' + (isCurrentWeek ? '' : ' kpi-week-past') + '">' +
            escapeHtml(formatDate(nlWeekStart)) + ' — ' + escapeHtml(formatDate(wkEnd)) +
            (isCurrentWeek ? ' <span class="kpi-week-badge">This week</span>' : '') +
            '</div>' +
            '<button class="mtg-nav-btn" id="nlNextWeek"' + (isCurrentWeek ? ' disabled' : '') + '>&#8594;</button>' +
            (!isCurrentWeek ? '<button class="kpi-today-btn" id="nlToday">Today</button>' : '') +
            '</div>';

        try {
            var res = await requestJson('/api/network-leverage?week_key=' + encodeURIComponent(wk) + '&user_id=' + NL_USER_ID);
            var events = res.events || [];
            var allEvents = res.allEvents || [];

            var bodyHtml;
            if (nlShowForm && isAyush) {
                var todayStr = new Date().toISOString().slice(0, 10);
                bodyHtml = '<div class="nl-card">' +
                    '<div class="nl-section">' +
                        '<div class="nl-section-title"><svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg> Event Details</div>' +
                        '<div class="nl-row-2">' +
                            '<div class="nl-field">' +
                                '<label class="nl-label">Event / Conference</label>' +
                                '<input type="text" class="input nl-input" id="nlEventName" placeholder="e.g. TechSummit 2026, SaaS Connect...">' +
                            '</div>' +
                            '<div class="nl-field">' +
                                '<label class="nl-label">Event Date</label>' +
                                '<div class="nl-date-wrap"><input type="date" class="input nl-input nl-date-input" id="nlEventDate" value="' + todayStr + '"></div>' +
                            '</div>' +
                        '</div>' +
                        '<div class="nl-row-2">' +
                            '<div class="nl-field">' +
                                '<label class="nl-label">Who Attended With You</label>' +
                                '<div class="nl-attendee-row">' +
                                    '<input type="text" class="input nl-input" id="nlCoAttendees" placeholder="Names of co-attendees">' +
                                    '<label class="nl-only-me"><input type="checkbox" id="nlOnlyMe"> Only Me</label>' +
                                '</div>' +
                            '</div>' +
                            '<div class="nl-field">' +
                                '<label class="nl-label">Total Attendees</label>' +
                                '<input type="number" class="input nl-input" id="nlAttendeeCount" placeholder="0" min="0">' +
                            '</div>' +
                        '</div>' +
                    '</div>' +
                    '<div class="nl-divider"></div>' +
                    '<div class="nl-section">' +
                        '<div class="nl-section-title"><svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg> Connections Made</div>' +
                        '<div class="nl-field">' +
                            '<label class="nl-label">People Leveraged</label>' +
                            '<textarea class="input nl-input nl-textarea" id="nlContacts" rows="3" placeholder="One name per line"></textarea>' +
                        '</div>' +
                        '<div class="nl-field">' +
                            '<label class="nl-label">LinkedIn Profiles <span class="nl-required">required</span></label>' +
                            '<textarea class="input nl-input nl-textarea" id="nlLinkedin" rows="3" placeholder="One LinkedIn URL per line"></textarea>' +
                        '</div>' +
                    '</div>' +
                    '<div class="nl-save-wrap">' +
                        '<button class="btn btn-primary" id="nlSaveBtn">Save Event</button>' +
                        '<button class="btn btn-ghost" id="nlCancelBtn">Cancel</button>' +
                        '<span id="nlSaveStatus" class="nl-save-status"></span>' +
                    '</div>' +
                '</div>';
            } else {
                var cardsHtml = events.length > 0
                    ? events.map(function (ev) { return nlBuildEventCard(ev, isAyush); }).join('')
                    : '<div class="nl-empty-state"><svg width="48" height="48" fill="none" stroke="#3f3f46" stroke-width="1.5" viewBox="0 0 24 24"><path d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg><p>No events recorded this week</p></div>';
                var addBtn = isAyush && isCurrentWeek
                    ? '<button class="btn btn-primary nl-add-btn" id="nlAddEvent"><svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14m-7-7h14"/></svg> Add Event</button>'
                    : '';
                bodyHtml = addBtn + '<div class="nl-events-list">' + cardsHtml + '</div>';
            }

            // Full history, newest first — always shown (in the list view) below
            // the weekly section so events from any week/month are visible without
            // clicking through weeks. Hidden while the add form is open.
            var allEventsHtml = '';
            if (!nlShowForm && allEvents.length > 0) {
                var allCardsHtml = allEvents.map(function (ev) { return nlBuildEventCard(ev, isAyush); }).join('');
                allEventsHtml = '<div class="nl-all-events">' +
                    '<div class="nl-all-events-header">' +
                        '<svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/></svg> ' +
                        'All Events <span class="nl-contact-count">' + allEvents.length + '</span>' +
                    '</div>' +
                    '<div class="nl-events-list">' + allCardsHtml + '</div>' +
                '</div>';
            }

            root.innerHTML = '<div class="kpi-wrap">' +
                '<div class="kpi-header"><h2>Network Leverage' + (isAyush ? '' : ' — Ayush') + '</h2>' + weekNavHtml + '</div>' +
                bodyHtml + allEventsHtml + '</div>';

            // week nav handlers
            document.getElementById('nlPrevWeek').onclick = function () { nlShowForm = false; nlWeekStart = addDays(nlWeekStart, -7); renderNetworkLeverage(); };
            var nextBtn = document.getElementById('nlNextWeek');
            if (nextBtn) nextBtn.onclick = function () { if (!isCurrentWeek) { nlShowForm = false; nlWeekStart = addDays(nlWeekStart, 7); renderNetworkLeverage(); } };
            var todayBtn = document.getElementById('nlToday');
            if (todayBtn) todayBtn.onclick = function () { nlShowForm = false; nlWeekStart = startOfWeek(new Date()); renderNetworkLeverage(); };

            // add event
            var addEventBtn = document.getElementById('nlAddEvent');
            if (addEventBtn) addEventBtn.onclick = function () { nlShowForm = true; renderNetworkLeverage(); };

            // cancel
            var cancelBtn = document.getElementById('nlCancelBtn');
            if (cancelBtn) cancelBtn.onclick = function () { nlShowForm = false; renderNetworkLeverage(); };

            // only-me toggle
            var onlyMeCb = document.getElementById('nlOnlyMe');
            if (onlyMeCb) {
                onlyMeCb.onchange = function () {
                    var coField = document.getElementById('nlCoAttendees');
                    var countField = document.getElementById('nlAttendeeCount');
                    if (onlyMeCb.checked) {
                        coField.value = ''; coField.disabled = true; coField.placeholder = 'Only Me';
                        countField.value = ''; countField.disabled = true; countField.placeholder = '—';
                    } else {
                        coField.disabled = false; coField.placeholder = 'Names of co-attendees';
                        countField.disabled = false; countField.placeholder = '0';
                    }
                };
            }

            // delete event
            root.querySelectorAll('.nl-delete-btn').forEach(function (btn) {
                btn.onclick = async function () {
                    if (!confirm('Delete this event?')) return;
                    btn.disabled = true;
                    try {
                        await requestJson('/api/network-leverage/' + btn.getAttribute('data-event-id'), { method: 'DELETE' });
                        renderNetworkLeverage();
                    } catch (err) { btn.disabled = false; }
                };
            });

            // save
            var saveButton = document.getElementById('nlSaveBtn');
            if (saveButton) {
                saveButton.onclick = async function () {
                    var status = document.getElementById('nlSaveStatus');
                    var eventName = document.getElementById('nlEventName').value.trim();
                    var eventDate = document.getElementById('nlEventDate').value;
                    var onlyMe = document.getElementById('nlOnlyMe').checked;
                    var coAttendees = onlyMe ? null : document.getElementById('nlCoAttendees').value.trim();
                    var attendeeCount = onlyMe ? null : (parseInt(document.getElementById('nlAttendeeCount').value) || null);
                    var contacts = document.getElementById('nlContacts').value.trim();
                    var linkedin = document.getElementById('nlLinkedin').value.trim();

                    status.textContent = ''; status.style.color = '';
                    if (!eventName) { status.textContent = 'Event name is required.'; status.style.color = '#ef4444'; return; }
                    if (!eventDate) { status.textContent = 'Event date is required.'; status.style.color = '#ef4444'; return; }
                    if (!linkedin) { status.textContent = 'LinkedIn profiles are required.'; status.style.color = '#ef4444'; return; }

                    saveButton.disabled = true; saveButton.textContent = 'Saving...';
                    try {
                        await requestJson('/api/network-leverage', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({
                                user_id: NL_USER_ID,
                                week_key: wk,
                                event_date: eventDate,
                                event_name: eventName,
                                co_attendees: coAttendees || null,
                                attendee_count: attendeeCount,
                                contacts: contacts || null,
                                linkedin_urls: linkedin
                            })
                        });
                        nlShowForm = false;
                        renderNetworkLeverage();
                    } catch (err) {
                        status.textContent = err.message || 'Save failed.';
                        status.style.color = '#ef4444';
                        saveButton.disabled = false; saveButton.textContent = 'Save Event';
                    }
                };
            }
        } catch (err) {
            root.innerHTML = '<div class="kpi-wrap"><div class="kpi-empty-state"><p>Failed to load Network Leverage data.</p></div></div>';
        }
    }

    // Seed the daily sign-in flags before the first view switch so the
    // sign-in gate has an authoritative value on first paint. renderDashboard()
    // still re-fetches /api/dashboard-state and corrects these if they drift.
    if (window.__dashSignedIn === undefined) window.__dashSignedIn = !!config.signedInToday;
    if (window.__dashSignedOff === undefined) window.__dashSignedOff = !!config.signedOffToday;

    MeetingModule.init({
        portal: config.portal,
        userId: config.userId,
        hasPreviousMinutes: config.hasPreviousMinutes !== false,
        // JP AI mode adds the 'ai' view (not in features) so switchView can reach
        // the #aiView section; all 44 real sections stay in config.features.
        views: config.jp_ai_mode ? (config.features || []).concat(['ai']) : (config.features || ['meetings']),
        onSwitchView: onSwitchView,
        guardSwitchView: guardSwitchView,
        MODAL_PEOPLE: config.MODAL_PEOPLE,
        ACTION_OWNERS: config.ACTION_OWNERS,
        KPI_GROUPS: config.KPI_GROUPS
    });

    // Restore the last-used tab (saved in onSwitchView) so a refresh, the visibility/
    // heartbeat auto-reload, or a browser-tab switch stays put instead of resetting to the
    // first sidebar item. guardSwitchView (inside switchView) still forces dashboard/leave
    // when the sign-in gate is active and profile when onboarding-locked; an unknown or
    // no-longer-available saved view falls back to the first feature.
    location.hash = '';
    var savedView = null;
    try { savedView = localStorage.getItem('portalActiveView:' + (config.userId || '')); } catch (e) {}
    // In JP AI mode the only persistent destinations are his two sidebar items
    // (AI + Dashboard). Every other section is transient — reached via the AI and
    // closed back to it — so a stale saved view like 'meetings' must NOT restore;
    // it falls back to the 'ai' home. Outside AI mode, any feature view restores.
    var savedViewValid = savedView && (config.jp_ai_mode
        ? (savedView === 'ai' || savedView === 'dashboard')
        : (config.features && config.features.indexOf(savedView) !== -1));
    if (!savedViewValid) savedView = null;
    // JP AI mode lands on the AI chat by default instead of the first sidebar item.
    var defaultView = config.jp_ai_mode ? 'ai' : ((config.features && config.features.length) ? config.features[0] : 'dashboard');
    var initialView = onboardingLocked ? 'profile' : (savedView || defaultView);
    if (initialView !== 'meetings') {
        MeetingModule.switchView(initialView, { initial: true });
        // JP AI mode: MeetingModule.init() runs loadMeetings().then(restoreFromHash),
        // and restoreFromHash() defaults to switchView('meetings') whenever the hash
        // is empty — a fast meetings load would clobber the AI view we just set.
        // Stamp the hash now (same value the +100ms syncPortalHash would set) so
        // restoreFromHash sees a non-meetings view and stands down. Closes the race.
        if (config.jp_ai_mode) syncPortalHash();
    } else if (hashView === 'meetings' && hash) {
        setTimeout(function () {
            if (window.MeetingModule && window.MeetingModule.restoreFromHash) {
                window.MeetingModule.restoreFromHash();
            }
        }, 100);
    }
    if (onboardingLocked) renderOnboardingBanner();

    // Birthday banner + sign-in celebration (data is already in config —
    // no fetch, same as the holiday calendar).
    renderBirthdayBanner();

    window.addEventListener('hashchange', function () {
        var hash = (location.hash || '').replace(/^#/, '');
        if (!hash) return;
        var params = {};
        hash.split('&').forEach(function (pair) {
            var parts = pair.split('=');
            if (parts.length === 2) {
                params[decodeURIComponent(parts[0])] = decodeURIComponent(parts[1]);
            }
        });
        var view = params.view || 'meetings';
        if (view === 'meetings') {
            if (window.MeetingModule && window.MeetingModule.restoreFromHash) {
                window.MeetingModule.restoreFromHash();
            }
        } else {
            restorePortalHash();
            if (view === 'daily') renderDailyReports();
            else if (view === 'mkpi') renderMarketingKpis();
            else if (view === 'tessa') MeetingModule.switchView('tessa');
            else if (view === 'tasks') MeetingModule.switchView('tasks');
        }
    });

    /* ── Sidebar profile photo: self-service upload ── */
    (function () {
        var btn = document.getElementById('sideNavAvatar');
        var input = document.getElementById('sideNavAvatarInput');
        if (!btn || !input) return;

        var ALLOWED = ['image/jpeg', 'image/png', 'image/webp'];
        var MAX = 4 * 1024 * 1024;
        var msgEl = null, msgTimer = null;

        function flash(text, ok) {
            if (msgTimer) clearTimeout(msgTimer);
            if (!msgEl) {
                msgEl = document.createElement('div');
                msgEl.style.cssText = 'font-size:9px;text-align:center;margin-top:4px;line-height:1.3;';
                btn.parentNode.appendChild(msgEl);
            }
            msgEl.textContent = text;
            msgEl.style.color = ok ? '#4ade80' : '#f87171';
            msgTimer = setTimeout(function () { if (msgEl) msgEl.textContent = ''; }, 4000);
        }

        function applyPhoto(url) {
            var cur = document.getElementById('sideNavAvatarImg');
            if (cur && cur.tagName === 'IMG') {
                cur.src = url;
            } else if (cur) {
                var img = document.createElement('img');
                img.id = 'sideNavAvatarImg';
                img.alt = 'Profile photo';
                img.src = url;
                cur.parentNode.replaceChild(img, cur);
            }
            // Reflect on the user's own My Profile card if it is open. Scoped to
            // #profileView so the Team directory avatars are never touched.
            var pv = document.getElementById('profileView');
            if (pv) {
                pv.querySelectorAll('.emp-card-avatar').forEach(function (av) {
                    av.innerHTML = '';
                    var im = document.createElement('img');
                    im.src = url;
                    im.alt = '';
                    im.style.cssText = 'width:100%;height:100%;border-radius:10px;object-fit:cover;display:block';
                    av.appendChild(im);
                });
            }
        }

        // Avatar click opens a small View / Change menu instead of jumping
        // straight to the file picker. Menu + viewer are body-appended so the
        // sidebar's overflow:hidden can't clip them.
        var menuEl = null, modalEl = null;

        function photoUrl() {
            var el = document.getElementById('sideNavAvatarImg');
            return (el && el.tagName === 'IMG') ? el.getAttribute('src') : null;
        }

        function onDocClick(e) {
            if (menuEl && !menuEl.contains(e.target) && e.target !== btn && !btn.contains(e.target)) closeMenu();
        }
        function onMenuKey(e) { if (e.key === 'Escape') closeMenu(); }

        function closeMenu() {
            if (menuEl) { menuEl.remove(); menuEl = null; }
            document.removeEventListener('click', onDocClick, true);
            document.removeEventListener('keydown', onMenuKey);
        }

        function openMenu() {
            if (menuEl) { closeMenu(); return; } // toggle
            var hasPhoto = !!photoUrl();
            menuEl = document.createElement('div');
            menuEl.className = 'side-nav-avatar-menu';
            menuEl.innerHTML =
                '<button type="button" class="side-nav-avatar-menu-item" data-act="view">' +
                    '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"/><circle cx="12" cy="12" r="3"/></svg>' +
                    'View photo</button>' +
                '<button type="button" class="side-nav-avatar-menu-item" data-act="edit">' +
                    '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>' +
                    (hasPhoto ? 'Change photo' : 'Add photo') + '</button>';
            document.body.appendChild(menuEl);

            var r = btn.getBoundingClientRect();
            var left = r.right + 8;
            if (left + menuEl.offsetWidth > window.innerWidth - 8) left = r.left - menuEl.offsetWidth - 8;
            var top = Math.min(window.innerHeight - menuEl.offsetHeight - 8, r.top);
            menuEl.style.left = Math.max(8, left) + 'px';
            menuEl.style.top = Math.max(8, top) + 'px';

            menuEl.querySelector('[data-act="view"]').addEventListener('click', function () { closeMenu(); openModal(); });
            menuEl.querySelector('[data-act="edit"]').addEventListener('click', function () { closeMenu(); input.click(); });

            // Defer so the opening click doesn't immediately close it.
            setTimeout(function () {
                document.addEventListener('click', onDocClick, true);
                document.addEventListener('keydown', onMenuKey);
            }, 0);
        }

        function onModalKey(e) { if (e.key === 'Escape') closeModal(); }
        function closeModal() {
            if (modalEl) { modalEl.remove(); modalEl = null; }
            document.removeEventListener('keydown', onModalKey);
        }

        function openModal() {
            closeModal();
            var url = photoUrl();
            modalEl = document.createElement('div');
            modalEl.className = 'pf-photo-modal';
            modalEl.innerHTML =
                '<div class="pf-photo-box">' +
                    '<button type="button" class="pf-photo-close" aria-label="Close">&#10005;</button>' +
                    (url
                        ? '<img src="' + url + '" alt="Profile photo" class="pf-photo-img">'
                        : '<div class="pf-photo-empty">No profile photo yet.<br><button type="button" class="pf-photo-add">Add a photo</button></div>') +
                '</div>';
            document.body.appendChild(modalEl);

            modalEl.addEventListener('click', function (e) {
                if (e.target === modalEl || e.target.classList.contains('pf-photo-close')) closeModal();
            });
            var addBtn = modalEl.querySelector('.pf-photo-add');
            if (addBtn) addBtn.addEventListener('click', function () { closeModal(); input.click(); });
            document.addEventListener('keydown', onModalKey);
        }

        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            openMenu();
        });

        input.addEventListener('change', function () {
            var file = input.files && input.files[0];
            if (!file) return;
            if (ALLOWED.indexOf(file.type) === -1) {
                flash('Use a JPG, PNG or WebP image.', false);
                input.value = '';
                return;
            }
            if (file.size > MAX) {
                flash('Image must be 4 MB or smaller.', false);
                input.value = '';
                return;
            }

            var fd = new FormData();
            fd.append('photo', file);
            btn.classList.add('is-uploading');

            fetch('/api/profile/photo', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: fd
            }).then(function (r) {
                return r.json().then(function (data) { return { ok: r.ok, data: data }; });
            }).then(function (res) {
                if (!res.ok || !res.data || !res.data.ok || !res.data.profile_photo) {
                    var m = (res.data && (res.data.error
                        || (res.data.errors && res.data.errors.photo && res.data.errors.photo[0])))
                        || 'Upload failed. Please try again.';
                    flash(m, false);
                    return;
                }
                var u = res.data.profile_photo;
                applyPhoto(u + (u.indexOf('?') === -1 ? '?t=' : '&t=') + Date.now());
                flash('Profile photo updated.', true);
            }).catch(function () {
                flash('Upload failed. Please try again.', false);
            }).finally(function () {
                btn.classList.remove('is-uploading');
                input.value = '';
            });
        });
    })();
})();
