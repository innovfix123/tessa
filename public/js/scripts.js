(function () {
    'use strict';

    var initialized = false;
    var lastGenerationId = null;
    var lastForm = {};
    var lastGeneratedScripts = [];

    function escapeHtml(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    async function requestJson(url, options) {
        var requestOptions = Object.assign({ credentials: 'same-origin' }, options || {});
        requestOptions.headers = Object.assign({
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        }, requestOptions.headers || {});
        var res = await fetch(url, requestOptions);
        var data = await res.json().catch(function () { return {}; });
        if (!res.ok) {
            var msg = (data && data.error) ? data.error : ((data && data.message) ? data.message : 'Request failed');
            throw new Error(msg);
        }
        if (data && data.ok === false && data.error) {
            throw new Error(data.error);
        }
        return data;
    }

    function getConfig() {
        var c = window.__PORTAL_CONFIG || {};
        return c.scripts || null;
    }

    function buildFormHtml(cfg) {
        var langs = cfg.languages || [];
        var cats = cfg.categories || [];
        var langOpts = langs.map(function (l) {
            return '<option value="' + escapeHtml(l.value) + '">' + escapeHtml(l.label) + '</option>';
        }).join('');
        var catOpts = cats.map(function (c) {
            return '<option value="' + escapeHtml(c.value) + '">' + escapeHtml(c.label) + '</option>';
        }).join('');
        return (
            '<div class="scripts-subtabs">' +
            '<button type="button" class="scripts-subtab active" data-scripts-sub="generate">Generate</button>' +
            '<button type="button" class="scripts-subtab" data-scripts-sub="library">Library</button>' +
            '</div>' +
            '<div id="scriptsPanelGenerate" class="scripts-panel">' +
            '<div class="scripts-form-grid">' +
            '<div class="scripts-field"><label for="scriptsLang">Language</label><select id="scriptsLang" class="scripts-input">' + langOpts + '</select></div>' +
            '<div class="scripts-field"><label for="scriptsCat">Category</label><select id="scriptsCat" class="scripts-input">' + catOpts + '</select></div>' +
            '<div class="scripts-field scripts-field-span2"><label for="scriptsCount">Number of scripts</label><select id="scriptsCount" class="scripts-input">' +
            [1, 2, 3, 4, 5, 6, 7, 8, 9, 10].map(function (n) { return '<option value="' + n + '"' + (n === 5 ? ' selected' : '') + '>' + n + '</option>'; }).join('') +
            '</select></div>' +
            '<div class="scripts-field scripts-field-span2"><label for="scriptsBrief">Your idea <span class="scripts-hint">(e.g. late-night train journey, crush at college, lonely in a new city, breakup recovery)</span></label>' +
            '<textarea id="scriptsBrief" class="scripts-textarea" rows="3" placeholder="Describe the scene, emotion, or story angle you want the scripts built around"></textarea></div>' +
            '</div>' +
            '<div class="scripts-actions"><button type="button" id="scriptsGenerateBtn" class="btn btn-primary">Generate</button>' +
            '<span id="scriptsGenStatus" class="scripts-status"></span></div>' +
            '<div id="scriptsResults" class="scripts-results"></div>' +
            '</div>' +
            '<div id="scriptsPanelLibrary" class="scripts-panel hidden">' +
            '<div class="scripts-lib-filters">' +
            '<select id="scriptsLibLang" class="scripts-input scripts-input-sm"><option value="">All languages</option>' + langOpts + '</select>' +
            '<select id="scriptsLibCat" class="scripts-input scripts-input-sm"><option value="">All categories</option>' + catOpts + '</select>' +
            '<button type="button" id="scriptsLibRefresh" class="btn">Refresh</button>' +
            '</div>' +
            '<div id="scriptsLibList" class="scripts-lib-list"></div>' +
            '</div>'
        );
    }

    function buildStatsHtml() {
        return (
            '<div class="scripts-stats-wrap">' +
            '<h2 class="scripts-page-title">Script generation — overview</h2>' +
            '<p class="scripts-lead">Usage across the content &amp; marketing team (Hi-Ma scripts).</p>' +
            '<div id="scriptsStatsBody" class="scripts-stats-body"><p class="scripts-muted">Loading…</p></div>' +
            '</div>'
        );
    }

    async function loadStats() {
        var body = document.getElementById('scriptsStatsBody');
        if (!body) return;
        try {
            var data = await requestJson('/api/scripts/stats');
            var s = data.stats || {};
            var byLang = (s.by_language || []).map(function (r) {
                return '<tr><td>' + escapeHtml(r.label || r.language) + '</td><td>' + r.generations + '</td></tr>';
            }).join('');
            var byCat = (s.by_category || []).map(function (r) {
                return '<tr><td>' + escapeHtml(r.category) + '</td><td>' + r.generations + '</td></tr>';
            }).join('');
            var byUser = (s.by_user || []).map(function (r) {
                return '<tr><td>' + escapeHtml(r.name) + '</td><td>' + r.generations + '</td></tr>';
            }).join('');
            var recent = (s.recent || []).map(function (r) {
                return '<li><strong>' + escapeHtml(r.user_name) + '</strong> · ' + escapeHtml(r.language) + ' · ' + escapeHtml(r.category) + ' · ' + r.script_count + ' scripts · <span class="scripts-muted">' + escapeHtml(r.created_at || '') + '</span></li>';
            }).join('');
            body.innerHTML =
                '<div class="scripts-stat-cards">' +
                '<div class="scripts-stat-card"><div class="scripts-stat-num">' + (s.total_generations || 0) + '</div><div class="scripts-stat-label">Generation runs</div></div>' +
                '<div class="scripts-stat-card"><div class="scripts-stat-num">' + (s.total_scripts_generated || 0) + '</div><div class="scripts-stat-label">Scripts produced</div></div>' +
                '<div class="scripts-stat-card"><div class="scripts-stat-num">' + (s.library_items_saved || 0) + '</div><div class="scripts-stat-label">Saved to library</div></div>' +
                '</div>' +
                '<div class="scripts-stats-tables">' +
                '<div class="scripts-stats-col"><h3>By language</h3><table class="scripts-table"><thead><tr><th>Language</th><th>Runs</th></tr></thead><tbody>' + (byLang || '<tr><td colspan="2">No data</td></tr>') + '</tbody></table></div>' +
                '<div class="scripts-stats-col"><h3>By category</h3><table class="scripts-table"><thead><tr><th>Category</th><th>Runs</th></tr></thead><tbody>' + (byCat || '<tr><td colspan="2">No data</td></tr>') + '</tbody></table></div>' +
                '<div class="scripts-stats-col scripts-stats-col-wide"><h3>By team member</h3><table class="scripts-table"><thead><tr><th>Name</th><th>Runs</th></tr></thead><tbody>' + (byUser || '<tr><td colspan="2">No data</td></tr>') + '</tbody></table></div>' +
                '</div>' +
                '<div class="scripts-recent"><h3>Recent activity</h3><ul class="scripts-recent-list">' + (recent || '<li class="scripts-muted">No activity yet</li>') + '</ul></div>';
        } catch (e) {
            body.innerHTML = '<p class="scripts-error">' + escapeHtml(e.message || 'Failed to load stats') + '</p>';
        }
    }

    function setGenStatus(msg, isError) {
        var el = document.getElementById('scriptsGenStatus');
        if (!el) return;
        el.textContent = msg || '';
        el.className = 'scripts-status' + (isError ? ' scripts-status-error' : '');
    }

    function renderScriptCards(scripts, formVals) {
        var wrap = document.getElementById('scriptsResults');
        if (!wrap) return;
        if (!scripts || !scripts.length) {
            wrap.innerHTML = '<p class="scripts-muted">No scripts returned.</p>';
            return;
        }
        lastGeneratedScripts = scripts.slice();
        wrap.innerHTML = '<h3 class="scripts-results-title">Generated scripts</h3><div class="scripts-card-grid">' + scripts.map(function (text, i) {
            return (
                '<article class="scripts-card" data-idx="' + i + '">' +
                '<div class="scripts-card-body">' + escapeHtml(text).replace(/\n/g, '<br>') + '</div>' +
                '<div class="scripts-card-actions">' +
                '<button type="button" class="btn scripts-copy-btn" data-idx="' + i + '">Copy</button>' +
                '<button type="button" class="btn scripts-save-btn" data-idx="' + i + '">Save to library</button>' +
                '</div></article>'
            );
        }).join('') + '</div>';

        wrap.querySelectorAll('.scripts-copy-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var idx = parseInt(btn.getAttribute('data-idx'), 10);
                var t = lastGeneratedScripts[idx] || '';
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(t).then(function () {
                        btn.textContent = 'Copied!';
                        setTimeout(function () { btn.textContent = 'Copy'; }, 1500);
                    });
                }
            });
        });
        wrap.querySelectorAll('.scripts-save-btn').forEach(function (btn) {
            btn.addEventListener('click', async function () {
                var idx = parseInt(btn.getAttribute('data-idx'), 10);
                var text = lastGeneratedScripts[idx];
                if (!text) return;
                btn.disabled = true;
                try {
                    await requestJson('/api/scripts/library', {
                        method: 'POST',
                        body: JSON.stringify({
                            body: text,
                            language: formVals.language,
                            category: formVals.category,
                            script_generation_id: lastGenerationId
                        })
                    });
                    btn.textContent = 'Saved';
                } catch (e) {
                    btn.textContent = 'Error';
                    alert(e.message || 'Save failed');
                }
                setTimeout(function () { btn.disabled = false; btn.textContent = 'Save to library'; }, 2000);
            });
        });
    }

    async function runGenerate() {
        var lang = document.getElementById('scriptsLang');
        var cat = document.getElementById('scriptsCat');
        var count = document.getElementById('scriptsCount');
        var brief = document.getElementById('scriptsBrief');
        var btn = document.getElementById('scriptsGenerateBtn');
        if (!lang || !btn) return;

        var payload = {
            language: lang.value,
            category: cat.value,
            count: parseInt(count.value, 10),
            creative_brief: (brief && brief.value.trim()) ? brief.value.trim() : null
        };
        lastForm = payload;
        btn.disabled = true;
        setGenStatus('Generating… (may take up to a minute)');
        try {
            var data = await requestJson('/api/scripts/generate', {
                method: 'POST',
                body: JSON.stringify(payload)
            });
            lastGenerationId = data.generation && data.generation.id;
            var scripts = (data.generation && data.generation.scripts) || [];
            renderScriptCards(scripts, {
                language: payload.language,
                category: payload.category
            });
            setGenStatus('Done.');
        } catch (e) {
            setGenStatus(e.message || 'Generation failed', true);
        }
        btn.disabled = false;
    }

    async function loadLibrary() {
        var list = document.getElementById('scriptsLibList');
        if (!list) return;
        list.innerHTML = '<p class="scripts-muted">Loading…</p>';
        var lang = document.getElementById('scriptsLibLang');
        var cat = document.getElementById('scriptsLibCat');
        var q = '?scope=library';
        if (lang && lang.value) q += '&language=' + encodeURIComponent(lang.value);
        if (cat && cat.value) q += '&category=' + encodeURIComponent(cat.value);
        try {
            var data = await requestJson('/api/scripts' + q);
            var items = data.items || [];
            if (!items.length) {
                list.innerHTML = '<p class="scripts-muted">No saved scripts yet.</p>';
                return;
            }
            list.innerHTML = items.map(function (it) {
                return (
                    '<article class="scripts-lib-item" data-id="' + it.id + '">' +
                    '<div class="scripts-lib-meta">' + escapeHtml(it.language) + ' · ' + escapeHtml(it.category) + '</div>' +
                    '<div class="scripts-lib-body">' + escapeHtml(it.body).replace(/\n/g, '<br>') + '</div>' +
                    '<div class="scripts-lib-actions">' +
                    '<button type="button" class="btn scripts-lib-copy">Copy</button>' +
                    '<button type="button" class="btn scripts-lib-del">Remove</button>' +
                    '</div></article>'
                );
            }).join('');

            items.forEach(function (it) {
                var art = list.querySelector('.scripts-lib-item[data-id="' + it.id + '"]');
                if (!art) return;
                var copyBtn = art.querySelector('.scripts-lib-copy');
                if (copyBtn) {
                    copyBtn.addEventListener('click', function () {
                        if (navigator.clipboard && navigator.clipboard.writeText) {
                            navigator.clipboard.writeText(it.body || '');
                        }
                    });
                }
            });
            list.querySelectorAll('.scripts-lib-del').forEach(function (btn) {
                btn.addEventListener('click', async function () {
                    var art = btn.closest('.scripts-lib-item');
                    var id = art && art.getAttribute('data-id');
                    if (!id) return;
                    if (!confirm('Remove this script from your library?')) return;
                    try {
                        await requestJson('/api/scripts/library/' + encodeURIComponent(id), { method: 'DELETE' });
                        art.remove();
                    } catch (e) {
                        alert(e.message || 'Delete failed');
                    }
                });
            });
        } catch (e) {
            list.innerHTML = '<p class="scripts-error">' + escapeHtml(e.message) + '</p>';
        }
    }

    function bindGenerateUi() {
        var genBtn = document.getElementById('scriptsGenerateBtn');
        if (genBtn) genBtn.addEventListener('click', runGenerate);

        document.querySelectorAll('.scripts-subtab').forEach(function (tab) {
            tab.addEventListener('click', function () {
                var sub = tab.getAttribute('data-scripts-sub');
                document.querySelectorAll('.scripts-subtab').forEach(function (t) { t.classList.toggle('active', t === tab); });
                var pGen = document.getElementById('scriptsPanelGenerate');
                var pLib = document.getElementById('scriptsPanelLibrary');
                if (pGen) pGen.classList.toggle('hidden', sub !== 'generate');
                if (pLib) pLib.classList.toggle('hidden', sub !== 'library');
                if (sub === 'library') loadLibrary();
            });
        });

        var refBtn = document.getElementById('scriptsLibRefresh');
        if (refBtn) refBtn.addEventListener('click', loadLibrary);
    }

    function init() {
        var root = document.getElementById('scriptsRoot');
        if (!root) return;
        var cfg = getConfig();
        if (!cfg) return;

        if (!initialized) {
            if (cfg.isStatsOnly) {
                root.innerHTML = buildStatsHtml();
            } else {
                root.innerHTML = '<h2 class="scripts-page-title">Hi-Ma script generator</h2>' +
                    '<p class="scripts-lead">Generate on-brand ad scripts from your brief. Tone matches winning references; output in the language you choose.</p>' +
                    buildFormHtml(cfg);
                bindGenerateUi();
            }
            initialized = true;
        }

        if (cfg.isStatsOnly) {
            loadStats();
        }
    }

    window.ScriptsModule = { init: init };
})();
