(function(w, d) {
    'use strict';

    var CFG = w.pcmScenarioScanData || {};
    var SECTION = 'pcm-feature-scenario-scan';
    var APP = 'pcm-scenario-app';
    var MAX_REQ = 2;
    var BATCH = 1;
    var RULES = {
        setCookie: ['anonymous_set_cookie', 'cookie_on_anonymous', 'set_cookie_anonymous'],
        warmMiss: ['no_store_public', 'cache_control_no_store', 'cache_control_not_public'],
        mobile: ['vary_user_agent', 'vary_high_cardinality_user_agent'],
        cookie: ['vary_cookie', 'vary_high_cardinality_cookie'],
        bypass: ['no_store_public', 'cache_control_not_public']
    };

    if (!CFG.featureEnabled) {
        return;
    }

    var esc = w.pcmEscapeHtml || function(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    };

    function ready(fn) {
        if ('function' === typeof w.pcmOnSectionReady) {
            w.pcmOnSectionReady(SECTION, fn);
            return;
        }
        if ('loading' === d.readyState) {
            d.addEventListener('DOMContentLoaded', fn, { once: true });
            return;
        }
        fn();
    }

    function num(value, fallback) {
        var parsed = parseInt(value, 10);
        return Number.isFinite(parsed) ? parsed : fallback;
    }

    function host(value) {
        return String(value || '').toLowerCase().replace(/:\d+$/, '');
    }

    function hide(node, hidden) {
        if (node) {
            node.classList.toggle('pcm-hidden', !!hidden);
        }
    }

    function msg(node, text, tone) {
        var base;
        var hidden;
        if (!node) {
            return;
        }
        hidden = node.classList.contains('pcm-hidden');
        base = node.getAttribute('data-base-class');
        if (!base) {
            base = String(node.className || '')
                .replace(/\bpcm-hidden\b/g, '')
                .replace(/\bis-(info|success|warning|error)\b/g, '')
                .replace(/\s+/g, ' ')
                .trim() || 'pcm-scenario-message';
            node.setAttribute('data-base-class', base);
        }
        node.className = base + (tone ? ' is-' + tone : '') + (hidden ? ' pcm-hidden' : '');
        node.textContent = text || '';
    }

    function shortUrl(url, limit) {
        var value = String(url || '');
        var max = limit || 70;
        try {
            var parsed = new w.URL(value);
            value = parsed.pathname + parsed.search;
            if (!value || '/' === value) {
                value = parsed.origin + '/';
            }
        } catch (error) {}
        return value.length > max ? value.slice(0, Math.max(0, max - 3)) + '...' : value;
    }

    function markup(maxUrls) {
        return [
            '<div class="pcm-scenario-shell">',
                '<div class="pcm-scenario-builder-grid">',
                    '<section class="pcm-scenario-panel pcm-scenario-panel-builder">',
                        '<div class="pcm-scenario-panel-head"><div><h4 class="pcm-section-subhead">Source & URL Checklist</h4><p class="pcm-scenario-subcopy">Resolve URLs, edit the checklist, and keep the scan focused on the pages that matter.</p></div><span id="pcm-scenario-selection-count" class="pcm-scenario-count-badge">0 / ' + String(maxUrls) + ' URLs selected</span></div>',
                        '<fieldset class="pcm-scenario-source-fieldset"><legend class="screen-reader-text">URL source</legend><label class="pcm-scenario-radio"><input type="radio" name="pcm_scenario_source" value="top_pages" checked /> <span>Top Pages</span></label><label class="pcm-scenario-radio"><input type="radio" name="pcm_scenario_source" value="sitemap" /> <span>Sitemap</span></label><label class="pcm-scenario-radio"><input type="radio" name="pcm_scenario_source" value="custom" /> <span>Custom List</span></label></fieldset>',
                        '<p id="pcm-scenario-source-status" class="pcm-scenario-message" aria-live="polite"></p>',
                        '<div class="pcm-scenario-url-panel"><div class="pcm-scenario-url-panel-head"><h5 class="pcm-scenario-mini-title">Editable URL checklist</h5><span id="pcm-scenario-loaded-count" class="pcm-scenario-mini-badge">0 loaded</span></div><div id="pcm-scenario-url-feedback" class="pcm-scenario-message" aria-live="polite"></div><div id="pcm-scenario-url-empty" class="pcm-scenario-empty">Choose a source or add a same-host URL to begin.</div><ul id="pcm-scenario-url-list" class="pcm-scenario-url-list"></ul><div class="pcm-scenario-add-row"><input type="url" id="pcm-scenario-add-url-input" class="pcm-scenario-url-input" placeholder="https://example.com/about/" /><button type="button" class="pcm-btn-secondary" id="pcm-scenario-add-url-btn">Add URL</button></div><p id="pcm-scenario-limit-note" class="pcm-scenario-helper">Only same-host HTTP(S) URLs are allowed. Duplicate URLs are ignored.</p></div>',
                    '</section>',
                    '<section class="pcm-scenario-panel pcm-scenario-panel-controls">',
                        '<div class="pcm-scenario-panel-head"><div><h4 class="pcm-section-subhead">Variant Matrix</h4><p class="pcm-scenario-subcopy">Compare warm, cold, device, cookie, and query-string scenarios before you run the scan.</p></div><div class="pcm-scenario-preset-row"><button type="button" class="pcm-btn-secondary" id="pcm-scenario-preset-recommended">Recommended</button><button type="button" class="pcm-btn-secondary" id="pcm-scenario-preset-full">Full Matrix</button></div></div>',
                        '<div class="pcm-scenario-variant-grid"><fieldset class="pcm-scenario-variant-card"><legend class="pcm-scenario-group-label">Temperature</legend><label class="pcm-scenario-check"><input type="checkbox" id="pcm-variant-warm" checked /> <span>Warm</span></label><label class="pcm-scenario-check"><input type="checkbox" id="pcm-variant-cold" /> <span>Cold</span></label></fieldset><fieldset class="pcm-scenario-variant-card"><legend class="pcm-scenario-group-label">Device</legend><label class="pcm-scenario-check"><input type="checkbox" id="pcm-variant-desktop" checked /> <span>Desktop</span></label><label class="pcm-scenario-check"><input type="checkbox" id="pcm-variant-mobile" /> <span>Mobile</span></label></fieldset><fieldset class="pcm-scenario-variant-card"><legend class="pcm-scenario-group-label">Cookie Mode</legend><label class="pcm-scenario-check"><input type="checkbox" id="pcm-variant-anon" checked /> <span>Anonymous</span></label><label class="pcm-scenario-check"><input type="checkbox" id="pcm-variant-cookie" /> <span>Logged-in Cookie</span></label></fieldset></div>',
                        '<div class="pcm-scenario-query-card"><label for="pcm-scenario-query-params" class="pcm-scenario-query-label">Extra query-string variants</label><input type="text" id="pcm-scenario-query-params" class="pcm-scenario-qp-input" placeholder="utm_source=google, fbclid=abc" /><p class="pcm-scenario-helper">Comma-separated key=value pairs are appended as extra warm anonymous desktop variants.</p><label class="pcm-scenario-check pcm-scenario-check-inline"><input type="checkbox" id="pcm-scenario-skip-warmup" /> <span>Skip cache warm-up</span></label></div>',
                        '<div class="pcm-scenario-scope"><p id="pcm-scenario-probe-count" class="pcm-scenario-probe-count" aria-live="polite"></p><p id="pcm-scenario-truncation-warning" class="pcm-scenario-truncation-warning pcm-hidden" aria-live="polite"></p></div>',
                        '<div class="pcm-scenario-run-row"><button type="button" class="pcm-btn-primary" id="pcm-scenario-run-btn">Run Scenario Scan</button><button type="button" class="pcm-btn-secondary pcm-hidden" id="pcm-scenario-cancel-btn">Cancel</button><span id="pcm-scenario-run-status" class="pcm-scenario-message" aria-live="polite"></span></div>',
                        '<div id="pcm-scenario-progress-wrap" class="pcm-scenario-progress-wrap pcm-hidden"><div id="pcm-scenario-progress-bar" class="pcm-scenario-progress-bar" role="progressbar" aria-valuemin="0" aria-valuemax="0" aria-valuenow="0"><div id="pcm-scenario-progress-fill" class="pcm-scenario-progress-fill"></div></div><div class="pcm-scenario-progress-meta"><span id="pcm-scenario-progress-text" class="pcm-scenario-progress-text"></span><span id="pcm-scenario-current-url" class="pcm-scenario-current-url"></span></div></div>',
                    '</section>',
                '</div>',
                '<section id="pcm-scenario-results" class="pcm-scenario-results pcm-hidden" tabindex="-1"><div class="pcm-scenario-results-head"><div><h4 class="pcm-section-subhead">Results</h4><div id="pcm-scenario-results-meta" class="pcm-scenario-results-meta"></div></div><div class="pcm-scenario-export-controls"><label for="pcm-scenario-export-format" class="pcm-scenario-export-label">Export</label><select id="pcm-scenario-export-format" class="pcm-scenario-export-select"><option value="csv">CSV</option><option value="json">JSON</option></select><button type="button" class="pcm-btn-secondary" id="pcm-scenario-export-btn" disabled>Download</button></div></div><div id="pcm-scenario-results-summary" class="pcm-scenario-results-summary"></div><div id="pcm-scenario-recommendations" class="pcm-scenario-recommendations"></div><div id="pcm-scenario-playbook" class="pcm-scenario-playbook pcm-hidden"></div><div id="pcm-scenario-results-body" class="pcm-scenario-results-body"></div></section>',
                '<section class="pcm-scenario-panel pcm-scenario-recent-panel"><div class="pcm-scenario-panel-head"><div><h4 class="pcm-section-subhead">Recent Scans</h4><p class="pcm-scenario-subcopy">Open saved runs without rerunning the matrix.</p></div></div><div id="pcm-scenario-recent-status" class="pcm-scenario-message" aria-live="polite"></div><div id="pcm-scenario-recent-list" class="pcm-scenario-recent-list"></div></section>',
            '</div>'
        ].join('');
    }

    ready(function() {
        var card = d.getElementById(SECTION);
        var mount;
        var ui;
        var state;

        if (!card || card.getAttribute('data-scenario-ready') || card.querySelector('.pcm-scenario-disabled-state')) {
            return;
        }

        mount = card.querySelector('#' + APP) || d.createElement('div');
        mount.id = APP;
        if (!mount.parentNode) {
            card.appendChild(mount);
        }
        card.setAttribute('data-scenario-ready', '1');
        mount.innerHTML = markup(num(CFG.maxUrls, 20));

        ui = {
            app: mount,
            source: mount.querySelectorAll('input[name="pcm_scenario_source"]'),
            sourceStatus: mount.querySelector('#pcm-scenario-source-status'),
            selection: mount.querySelector('#pcm-scenario-selection-count'),
            loaded: mount.querySelector('#pcm-scenario-loaded-count'),
            urlFeedback: mount.querySelector('#pcm-scenario-url-feedback'),
            urlEmpty: mount.querySelector('#pcm-scenario-url-empty'),
            urlList: mount.querySelector('#pcm-scenario-url-list'),
            addInput: mount.querySelector('#pcm-scenario-add-url-input'),
            addBtn: mount.querySelector('#pcm-scenario-add-url-btn'),
            limit: mount.querySelector('#pcm-scenario-limit-note'),
            warm: mount.querySelector('#pcm-variant-warm'),
            cold: mount.querySelector('#pcm-variant-cold'),
            desktop: mount.querySelector('#pcm-variant-desktop'),
            mobile: mount.querySelector('#pcm-variant-mobile'),
            anon: mount.querySelector('#pcm-variant-anon'),
            cookie: mount.querySelector('#pcm-variant-cookie'),
            query: mount.querySelector('#pcm-scenario-query-params'),
            skipWarmup: mount.querySelector('#pcm-scenario-skip-warmup'),
            presetRecommended: mount.querySelector('#pcm-scenario-preset-recommended'),
            presetFull: mount.querySelector('#pcm-scenario-preset-full'),
            probe: mount.querySelector('#pcm-scenario-probe-count'),
            truncation: mount.querySelector('#pcm-scenario-truncation-warning'),
            run: mount.querySelector('#pcm-scenario-run-btn'),
            cancel: mount.querySelector('#pcm-scenario-cancel-btn'),
            runStatus: mount.querySelector('#pcm-scenario-run-status'),
            progressWrap: mount.querySelector('#pcm-scenario-progress-wrap'),
            progressBar: mount.querySelector('#pcm-scenario-progress-bar'),
            progressFill: mount.querySelector('#pcm-scenario-progress-fill'),
            progressText: mount.querySelector('#pcm-scenario-progress-text'),
            currentUrl: mount.querySelector('#pcm-scenario-current-url'),
            results: mount.querySelector('#pcm-scenario-results'),
            resultsMeta: mount.querySelector('#pcm-scenario-results-meta'),
            resultsSummary: mount.querySelector('#pcm-scenario-results-summary'),
            recs: mount.querySelector('#pcm-scenario-recommendations'),
            playbook: mount.querySelector('#pcm-scenario-playbook'),
            resultsBody: mount.querySelector('#pcm-scenario-results-body'),
            exportFormat: mount.querySelector('#pcm-scenario-export-format'),
            exportBtn: mount.querySelector('#pcm-scenario-export-btn'),
            recentStatus: mount.querySelector('#pcm-scenario-recent-status'),
            recentList: mount.querySelector('#pcm-scenario-recent-list')
        };

        state = {
            maxUrls: num(CFG.maxUrls, 20),
            maxTotal: num(CFG.maxTotal, 60),
            siteHost: host(CFG.siteHost),
            siteName: String(CFG.siteName || CFG.siteHost || 'site'),
            source: 'top_pages',
            sources: { top_pages: [], sitemap: [], custom: [] },
            loaded: { top_pages: false, sitemap: false, custom: true },
            seed: 1,
            flashId: '',
            running: false,
            cancelRequested: false,
            cancelSent: false,
            token: '',
            runId: 0,
            total: 0,
            done: 0,
            active: 0,
            startedUrls: [],
            live: {},
            order: [],
            variants: [],
            dropped: [],
            last: null,
            recent: [],
            playbookId: 0
        };

        function post(action, data, timeout) {
            var body = data || {};
            body.action = action;
            body.nonce = 'function' === typeof w.pcmGetCacheabilityNonce ? w.pcmGetCacheabilityNonce() : '';
            return w.pcmPost(body, { timeout: timeout || 20000 }).then(function(payload) {
                var error;
                if (payload && payload.success) {
                    return payload.data || {};
                }
                error = new Error('Unexpected Scenario Scan response.');
                error.payload = payload;
                throw error;
            });
        }

        function err(error, fallback) {
            if (error && error.payload && error.payload.data && error.payload.data.message) {
                return String(error.payload.data.message);
            }
            if (error && error.message && !/^http_\d+$/.test(error.message) && 'timeout' !== error.message) {
                return String(error.message);
            }
            if (error && (error.isTimeout || error.message === 'timeout')) {
                return 'The request timed out. Try again with fewer URLs or variants.';
            }
            return fallback || 'Scenario Scan could not complete that request.';
        }

        function code(error) {
            return error && error.payload && error.payload.data && error.payload.data.code ? String(error.payload.data.code) : '';
        }

        function sameUrl(raw) {
            var parsed;
            var value = String(raw || '').trim();
            if (!value) {
                return { ok: false, message: 'Enter a same-host URL before adding it.' };
            }
            try {
                parsed = new w.URL(value);
            } catch (error) {
                return { ok: false, message: 'Enter a valid absolute HTTP(S) URL.' };
            }
            if (!/^https?:$/i.test(parsed.protocol)) {
                return { ok: false, message: 'Only HTTP(S) URLs can be scanned.' };
            }
            parsed.hash = '';
            if (state.siteHost && host(parsed.host) !== state.siteHost) {
                return { ok: false, message: 'Only URLs on ' + state.siteHost + ' can be scanned.' };
            }
            return { ok: true, url: parsed.toString() };
        }

        function list() {
            return state.sources[state.source] || [];
        }

        function sourceLabel(source) {
            return { top_pages: 'Top Pages', sitemap: 'Sitemap', custom: 'Custom List' }[source] || 'Selected Source';
        }

        function item(url, source, views) {
            return { id: 'scenario-url-' + String(state.seed++), url: url, source: source, views: Math.max(0, num(views, 0)), checked: true, locked: false };
        }

        function applyLocks(rows) {
            return (Array.isArray(rows) ? rows : []).map(function(entry, index) {
                entry.locked = index >= state.maxUrls;
                if (entry.locked) {
                    entry.checked = false;
                }
                return entry;
            });
        }

        function normalizeItems(rows, source) {
            var seen = {};
            return applyLocks((Array.isArray(rows) ? rows : []).map(function(row) {
                var parsed = sameUrl(row && row.url ? row.url : row);
                return parsed.ok ? item(parsed.url, source, row && row.views ? row.views : 0) : null;
            }).filter(function(entry) {
                if (!entry || seen[entry.url]) {
                    return false;
                }
                seen[entry.url] = true;
                return true;
            }));
        }

        function snapshot() {
            var selected = list().filter(function(entry) { return !!entry.checked && !entry.locked; }).map(function(entry) { return entry.url; });
            var config = variants();
            var rows = variantRows(config);
            var probes = selected.length * rows.length;
            var allow = selected.length ? Math.max(1, Math.floor(state.maxTotal / selected.length)) : 0;
            return { selected: selected, config: config, rows: rows, probes: probes, dropped: probes > state.maxTotal ? rows.slice(allow) : [] };
        }

        function variants() {
            var params = String(ui.query.value || '').split(/[\n,]+/).map(function(part) { return part.trim(); }).filter(Boolean);
            return { warm: ui.warm.checked, cold: ui.cold.checked, desktop: ui.desktop.checked, mobile: ui.mobile.checked, no_cookie: ui.anon.checked, cookie: ui.cookie.checked, query_params: params, skip_warmup: ui.skipWarmup.checked };
        }

        function variantRows(config) {
            var rows = [];
            var temps = [];
            var devices = [];
            var cookies = [];
            if (config.warm) { temps.push({ id: 'warm', label: 'Warm' }); }
            if (config.cold) { temps.push({ id: 'cold', label: 'Cold' }); }
            if (config.desktop) { devices.push({ id: 'desktop', label: 'Desktop' }); }
            if (config.mobile) { devices.push({ id: 'mobile', label: 'Mobile' }); }
            if (config.no_cookie) { cookies.push({ id: 'no_cookie', label: 'Anonymous' }); }
            if (config.cookie) { cookies.push({ id: 'cookie', label: 'Logged-in Cookie' }); }
            temps.forEach(function(temp) {
                devices.forEach(function(device) {
                    cookies.forEach(function(cookieMode) {
                        rows.push({ id: temp.id + '_' + device.id + '_' + cookieMode.id, label: temp.label + ' / ' + device.label + ' / ' + cookieMode.label, temp: temp.id, device: device.id, cookie_mode: cookieMode.id });
                    });
                });
            });
            (config.query_params || []).forEach(function(param) {
                rows.push({ id: 'qp_' + String(param).toLowerCase().replace(/[^a-z0-9_-]/g, ''), label: 'Query: ?' + param, temp: 'warm', device: 'desktop', cookie_mode: 'no_cookie' });
            });
            return rows;
        }

        function renderList() {
            var rows = list();
            ui.urlList.innerHTML = rows.map(function(entry) {
                var badges = '';
                if ('top_pages' === entry.source) {
                    badges += '<span class="pcm-scenario-inline-badge">' + esc(String(entry.views) + (1 === entry.views ? ' view' : ' views')) + '</span>';
                } else if ('custom' === entry.source) {
                    badges += '<span class="pcm-scenario-inline-badge is-neutral">Custom</span>';
                } else if ('sitemap' === entry.source) {
                    badges += '<span class="pcm-scenario-inline-badge is-neutral">Sitemap</span>';
                }
                if (!entry.checked && !entry.locked) {
                    badges += '<span class="pcm-scenario-inline-badge is-muted">Excluded</span>';
                }
                if (entry.locked) {
                    badges += '<span class="pcm-scenario-inline-badge is-muted">Limit reached</span>';
                }
                return '<li class="pcm-scenario-url-item' + (!entry.checked ? ' is-unchecked' : '') + (entry.locked ? ' is-locked' : '') + (state.flashId === entry.id ? ' is-highlighted' : '') + '" data-id="' + esc(entry.id) + '"><label class="pcm-scenario-url-label"><input type="checkbox" data-act="toggle" data-id="' + esc(entry.id) + '"' + (entry.checked ? ' checked' : '') + ((state.running || entry.locked) ? ' disabled' : '') + ' /><span class="pcm-scenario-url-copy"><span class="pcm-scenario-url-path">' + esc(shortUrl(entry.url, 72)) + '</span><code class="pcm-scenario-url-full">' + esc(entry.url) + '</code></span><span class="pcm-scenario-url-meta">' + badges + '</span></label><div class="pcm-scenario-url-actions"><button type="button" class="pcm-btn-text pcm-scenario-url-move" data-act="move-up" data-id="' + esc(entry.id) + '"' + ((state.running || 0 === rows.indexOf(entry)) ? ' disabled' : '') + '>Up</button><button type="button" class="pcm-btn-text pcm-scenario-url-move" data-act="move-down" data-id="' + esc(entry.id) + '"' + ((state.running || rows.length - 1 === rows.indexOf(entry)) ? ' disabled' : '') + '>Down</button><button type="button" class="pcm-btn-text pcm-scenario-url-remove" data-act="remove" data-id="' + esc(entry.id) + '"' + (state.running ? ' disabled' : '') + '>Remove</button></div></li>';
            }).join('');
            hide(ui.urlEmpty, rows.length > 0);
        }

        function sync() {
            var snap = snapshot();
            var limit = list().length >= state.maxUrls;
            Array.prototype.forEach.call(ui.source, function(input) {
                input.disabled = state.running;
                input.checked = input.value === state.source;
            });
            ui.selection.textContent = String(snap.selected.length) + ' / ' + String(state.maxUrls) + ' URLs selected';
            ui.loaded.textContent = String(list().length) + ' loaded';
            ui.selection.classList.toggle('is-limit', limit);
            ui.addInput.disabled = state.running || limit;
            ui.addBtn.disabled = state.running || limit;
            ui.query.disabled = state.running;
            ui.skipWarmup.disabled = state.running;
            ui.warm.disabled = state.running;
            ui.cold.disabled = state.running;
            ui.desktop.disabled = state.running;
            ui.mobile.disabled = state.running;
            ui.anon.disabled = state.running;
            ui.cookie.disabled = state.running;
            ui.presetRecommended.disabled = state.running;
            ui.presetFull.disabled = state.running;
            ui.run.disabled = state.running || !snap.selected.length || !snap.rows.length;
            hide(ui.run, state.running);
            hide(ui.cancel, !state.running);
            msg(ui.limit, limit ? 'URL limit reached. Remove or reorder URLs before adding another.' : 'Only same-host HTTP(S) URLs are allowed. Duplicate URLs are ignored.', limit ? 'warning' : '');
            msg(ui.probe, String(snap.selected.length) + ' URLs x ' + String(snap.rows.length) + ' variants = ' + String(snap.probes) + ' probes', snap.probes > state.maxTotal ? 'warning' : 'info');
            hide(ui.truncation, !snap.dropped.length);
            msg(ui.truncation, snap.dropped.length ? 'Probe limit reached. The server will skip: ' + snap.dropped.map(function(row) { return row.label; }).join(', ') + '.' : '', snap.dropped.length ? 'warning' : '');
            ui.exportBtn.disabled = !(state.last && state.last.results && state.last.results.length);
        }

        function applyRecommended() {
            ui.warm.checked = true; ui.cold.checked = false; ui.desktop.checked = true; ui.mobile.checked = false; ui.anon.checked = true; ui.cookie.checked = false; ui.skipWarmup.checked = false; ui.query.value = ''; sync(); msg(ui.runStatus, 'Recommended preset applied: Warm / Desktop / Anonymous.', 'info');
        }

        function applyFull() {
            ui.warm.checked = true; ui.cold.checked = true; ui.desktop.checked = true; ui.mobile.checked = true; ui.anon.checked = true; ui.cookie.checked = true; sync(); msg(ui.runStatus, 'Full Matrix enabled. Expect a longer scan if many URLs are selected.', 'warning');
        }

        function loadSource(source) {
            state.source = source;
            renderList();
            sync();
            if ('custom' === source) {
                msg(ui.sourceStatus, 'Custom list ready. Add the exact same-host URLs you want to scan.', 'info');
                return Promise.resolve();
            }
            if (state.loaded[source]) {
                msg(ui.sourceStatus, sourceLabel(source) + ' is ready to edit.', 'info');
                return Promise.resolve();
            }
            msg(ui.sourceStatus, 'Loading ' + sourceLabel(source).toLowerCase() + '...', 'info');
            return post('pcm_scenario_scan_resolve_urls', { source: source }, 25000).then(function(data) {
                state.maxUrls = num(data.max_urls, state.maxUrls);
                state.siteHost = host(data.site_host || state.siteHost);
                state.sources[source] = normalizeItems(data.urls, source);
                state.loaded[source] = true;
                if (state.source === source) {
                    renderList();
                    sync();
                    msg(ui.sourceStatus, 'Loaded ' + String(state.sources[source].length) + ' URLs from ' + sourceLabel(source) + '.', 'success');
                }
            }).catch(function(error) {
                state.sources[source] = [];
                renderList();
                sync();
                msg(ui.sourceStatus, err(error, 'Unable to resolve Scenario Scan URLs.'), 'error');
            });
        }

        function addUrl() {
            var parsed = sameUrl(ui.addInput.value);
            var match;
            if (!parsed.ok) {
                msg(ui.urlFeedback, parsed.message, 'error');
                return;
            }
            if (list().length >= state.maxUrls) {
                msg(ui.urlFeedback, 'URL limit reached. Remove one of the current URLs to add another.', 'warning');
                return;
            }
            match = list().filter(function(entry) { return entry.url === parsed.url; })[0];
            if (match) {
                match.checked = true;
                state.flashId = match.id;
                renderList();
                sync();
                msg(ui.urlFeedback, 'That URL is already in the checklist. It has been re-selected.', 'warning');
                w.setTimeout(function() { state.flashId = ''; renderList(); }, 1500);
                return;
            }
            list().push(item(parsed.url, state.source, 0));
            state.sources[state.source] = applyLocks(list());
            ui.addInput.value = '';
            renderList();
            sync();
            msg(ui.urlFeedback, 'Added ' + shortUrl(parsed.url, 60) + ' to the checklist.', 'success');
        }

        function validateAddInput(showMessage) {
            var value = String(ui.addInput.value || '').trim();
            var parsed;

            ui.addInput.classList.remove('is-valid', 'is-invalid');

            if (!value) {
                if (showMessage) {
                    msg(ui.urlFeedback, '', '');
                }
                return;
            }

            parsed = sameUrl(value);
            if (!parsed.ok) {
                ui.addInput.classList.add('is-invalid');
                if (showMessage) {
                    msg(ui.urlFeedback, parsed.message, 'error');
                }
                return;
            }

            ui.addInput.classList.add('is-valid');
            if (showMessage) {
                msg(ui.urlFeedback, 'URL is valid and matches this site.', 'success');
            }
        }

        mount.addEventListener('click', function(event) {
            var target = event.target.closest('[data-act]');
            var found;
            var rows;
            var index;
            var swap;
            if (!target) { return; }
            if ('remove' === target.getAttribute('data-act')) {
                state.sources[state.source] = applyLocks(list().filter(function(entry) { return entry.id !== target.getAttribute('data-id'); }));
                renderList();
                sync();
                msg(ui.urlFeedback, 'URL removed from the checklist.', 'info');
            } else if ('move-up' === target.getAttribute('data-act') || 'move-down' === target.getAttribute('data-act')) {
                rows = list().slice();
                index = rows.findIndex(function(entry) { return entry.id === target.getAttribute('data-id'); });
                if (-1 === index) { return; }
                if ('move-up' === target.getAttribute('data-act') && index > 0) {
                    swap = rows[index - 1];
                    rows[index - 1] = rows[index];
                    rows[index] = swap;
                } else if ('move-down' === target.getAttribute('data-act') && index < rows.length - 1) {
                    swap = rows[index + 1];
                    rows[index + 1] = rows[index];
                    rows[index] = swap;
                }
                state.sources[state.source] = applyLocks(rows);
                renderList();
                sync();
                msg(ui.urlFeedback, 'Checklist order updated.', 'info');
            } else if ('load-run' === target.getAttribute('data-act')) {
                loadRun(num(target.getAttribute('data-id'), 0));
            } else if ('open-playbook' === target.getAttribute('data-act')) {
                openPlaybook(String(target.getAttribute('data-rule') || '').split('|').filter(Boolean));
            } else if ('close-playbook' === target.getAttribute('data-act')) {
                clearPlaybook();
            } else if ('toggle' === target.getAttribute('data-act')) {
                found = list().filter(function(entry) { return entry.id === target.getAttribute('data-id'); })[0];
                if (found && !found.locked) { found.checked = !!target.checked; renderList(); sync(); }
            }
        });

        mount.addEventListener('change', function(event) {
            if (event.target && 'pcm_scenario_source' === event.target.name && event.target.checked) {
                msg(ui.urlFeedback, '', '');
                loadSource(event.target.value);
                return;
            }
            sync();
        });

        ui.addBtn.addEventListener('click', addUrl);
        ui.addInput.addEventListener('input', function() { validateAddInput(false); });
        ui.addInput.addEventListener('blur', function() { validateAddInput(true); });
        ui.addInput.addEventListener('keydown', function(event) { if ('Enter' === event.key) { event.preventDefault(); addUrl(); } });
        ui.presetRecommended.addEventListener('click', applyRecommended);
        ui.presetFull.addEventListener('click', applyFull);
        ui.run.addEventListener('click', begin);
        ui.cancel.addEventListener('click', cancel);
        ui.exportBtn.addEventListener('click', downloadCurrent);

        applyRecommended();
        loadSource('top_pages');
        loadRecent();

        function clearPlaybook() {
            ui.playbook.innerHTML = '';
            hide(ui.playbook, true);
        }

        function normalizeVariants(rows) {
            var seen = {};
            return (Array.isArray(rows) ? rows : []).map(function(row) {
                var variant = row || {};
                var id = String(variant.id || variant.variant_id || '');
                return id && !seen[id] ? (seen[id] = true, { id: id, label: String(variant.label || variant.variant_label || id), temp: String(variant.temp || ''), device: String(variant.device || ''), cookie_mode: String(variant.cookie_mode || '') }) : null;
            }).filter(Boolean);
        }

        function normalized(payload) {
            var variants = normalizeVariants(payload.variant_ids || state.variants);
            var order = {};
            var urls = {};
            var groups = [];

            variants.forEach(function(variant, index) { order[variant.id] = index; });
            (payload.results || []).forEach(function(group, index) {
                var url = group && group.url ? String(group.url) : '';
                if (!url) { return; }
                if (!urls[url]) {
                    urls[url] = { url: url, variants: [], idx: index };
                    groups.push(urls[url]);
                }
                (group.variants || []).forEach(function(row) {
                    var variantId = String((row && row.variant_id) || '');
                    var meta = variants.filter(function(variant) { return variant.id === variantId; })[0] || {};
                    urls[url].variants.push({ variant_id: variantId, label: String((row && row.label) || meta.label || variantId), status: String((row && row.status) || 'ok'), error: String((row && (row.error || row.error_message)) || ''), http_code: num(row && row.http_code, 0), elapsed_ms: num(row && row.elapsed_ms, 0), cache_headers: row && row.cache_headers && 'object' === typeof row.cache_headers ? row.cache_headers : {}, temp: String((row && row.temp) || meta.temp || ''), device: String((row && row.device) || meta.device || ''), cookie_mode: String((row && row.cookie_mode) || meta.cookie_mode || '') });
                });
            });
            groups.forEach(function(group) {
                group.variants.sort(function(left, right) {
                    var leftPos = Object.prototype.hasOwnProperty.call(order, left.variant_id) ? order[left.variant_id] : 9999;
                    var rightPos = Object.prototype.hasOwnProperty.call(order, right.variant_id) ? order[right.variant_id] : 9999;
                    return leftPos - rightPos;
                });
            });
            groups.sort(function(left, right) { return left.idx - right.idx; });
            return { run_id: num(payload.run_id, state.runId), url_count: num(payload.url_count, groups.length), variant_count: num(payload.variant_count, variants.length), variant_ids: variants, dropped_variants: normalizeVariants(payload.dropped_variants || state.dropped), scanned_at: String(payload.scanned_at || ''), status: String(payload.status || 'complete'), results: groups.map(function(group) { return { url: group.url, variants: group.variants }; }) };
        }

        function verdict(row) { return String(row && row.cache_headers && row.cache_headers.verdict || '').toLowerCase(); }
        function hit(row) { return /(^|_)hit$/.test(verdict(row)) || 'edge_hit' === verdict(row) || 'batcache_hit' === verdict(row); }
        function miss(row) { return /miss|expired|revalidated|stale/.test(verdict(row)); }
        function bypass(row) { return 'bypass' === verdict(row) || 'dynamic' === verdict(row); }
        function setCookie(row) { return !!(row && row.cache_headers && (row.cache_headers.has_set_cookie || row.cache_headers.set_cookie)); }
        function verdictLabel(row) { return 'error' === row.status ? 'Error' : (!verdict(row) ? 'Unknown' : String(verdict(row)).replace(/_/g, ' ').toUpperCase()); }
        function tone(row) { return 'error' === row.status ? 'danger' : (hit(row) ? 'good' : (bypass(row) ? 'danger' : (miss(row) ? 'warning' : 'info'))); }
        function signal(row) {
            var headers = row.cache_headers || {};
            return headers.x_cache ? 'x-cache: ' + headers.x_cache : (headers.cf_status ? 'cf: ' + headers.cf_status : (headers.x_nananana ? 'batcache: ' + headers.x_nananana : '--'));
        }

        function analyze(group) {
            var anonCookie = false;
            var allBypass = group.variants.length > 0;
            var warmMiss = false;
            var mobile = false;
            var cookiePairs = {};
            var devicePairs = {};
            var cookieMiss = false;
            var coldFaster = '';
            var divergent = {};

            group.variants.forEach(function(row) {
                var base;
                if ('no_cookie' === row.cookie_mode && setCookie(row)) { anonCookie = true; }
                if ('warm' === row.temp && !hit(row) && 'error' !== row.status) { warmMiss = true; }
                if (!bypass(row)) { allBypass = false; }
                if (row.temp && row.device && 0 !== String(row.variant_id || '').indexOf('qp_')) {
                    base = row.temp + '|' + row.device;
                    cookiePairs[base] = cookiePairs[base] || {};
                    cookiePairs[base][row.cookie_mode] = row;
                }
                if (row.temp && row.cookie_mode && 0 !== String(row.variant_id || '').indexOf('qp_')) {
                    base = row.temp + '|' + row.cookie_mode;
                    devicePairs[base] = devicePairs[base] || {};
                    devicePairs[base][row.device] = row;
                }
            });

            Object.keys(cookiePairs).forEach(function(key) {
                var pair = cookiePairs[key];
                if (pair.no_cookie && pair.cookie) {
                    if ((pair.no_cookie.status + pair.no_cookie.http_code + verdict(pair.no_cookie) + setCookie(pair.no_cookie)) !== (pair.cookie.status + pair.cookie.http_code + verdict(pair.cookie) + setCookie(pair.cookie))) {
                        divergent[pair.no_cookie.variant_id] = true;
                        divergent[pair.cookie.variant_id] = true;
                    }
                    if (hit(pair.no_cookie) && !hit(pair.cookie)) { cookieMiss = true; }
                }
            });

            Object.keys(devicePairs).forEach(function(key) {
                var pair = devicePairs[key];
                if (pair.desktop && pair.mobile && (pair.desktop.status + pair.desktop.http_code + verdict(pair.desktop) + String(pair.desktop.cache_headers && pair.desktop.cache_headers.vary || '')) !== (pair.mobile.status + pair.mobile.http_code + verdict(pair.mobile) + String(pair.mobile.cache_headers && pair.mobile.cache_headers.vary || ''))) {
                    mobile = true;
                }
            });

            group.variants.forEach(function(row) {
                var pair = group.variants.filter(function(other) { return other.temp !== row.temp && other.device === row.device && other.cookie_mode === row.cookie_mode && 0 !== String(other.variant_id || '').indexOf('qp_'); })[0];
                if (!coldFaster && 'warm' === row.temp && pair && 'cold' === pair.temp && row.elapsed_ms && pair.elapsed_ms && pair.elapsed_ms + 100 < row.elapsed_ms) {
                    coldFaster = shortUrl(group.url, 48) + ' cold (' + String(pair.elapsed_ms) + 'ms) was faster than warm (' + String(row.elapsed_ms) + 'ms).';
                }
            });

            return { anonCookie: anonCookie, allBypass: allBypass, warmMiss: warmMiss, mobile: mobile, cookieMiss: cookieMiss, coldFaster: coldFaster, divergent: divergent };
        }

        function recommendations(payload) {
            var output = [];
            payload.results.forEach(function(group) {
                var info = analyze(group);
                var path = shortUrl(group.url, 54);
                if (info.anonCookie) { output.push({ severity: 'broken', title: 'Set-Cookie on anonymous traffic', copy: path + ' sends Set-Cookie on anonymous requests. That commonly prevents edge caching.', rule: RULES.setCookie.join('|') }); }
                if (info.warmMiss) { output.push({ severity: 'degraded', title: 'Warm probe still missed cache', copy: path + ' still missed cache on a warm probe. Check cache-control, broad vary headers, or short TTLs.', rule: RULES.warmMiss.join('|') }); }
                if (info.mobile) { output.push({ severity: 'degraded', title: 'Mobile and desktop diverge', copy: path + ' behaves differently on mobile versus desktop. Check for Vary: User-Agent or device plugins.', rule: RULES.mobile.join('|') }); }
                if (info.allBypass) { output.push({ severity: 'broken', title: 'Every variant bypassed cache', copy: path + ' bypassed cache across every scanned scenario.', rule: RULES.bypass.join('|') }); }
                if (info.cookieMiss) { output.push({ severity: 'degraded', title: 'Cookie variant causes a cache drop', copy: path + ' loses cacheability when a logged-in cookie is present.', rule: RULES.cookie.join('|') }); }
                if (info.coldFaster) { output.push({ severity: 'info', title: 'Cold probe was faster than warm', copy: info.coldFaster, rule: '' }); }
            });
            if (!output.length && payload.results.length) {
                output.push({ severity: 'info', title: 'No major cache drift detected', copy: 'The scanned results stayed broadly consistent across the selected Scenario Scan variants.', rule: '' });
            }
            return output;
        }

        function focusResults() {
            hide(ui.results, false);
            w.setTimeout(function() {
                if ('function' === typeof ui.results.focus) { ui.results.focus({ preventScroll: true }); }
                if ('function' === typeof ui.results.scrollIntoView) { ui.results.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
            }, 32);
        }

        function progress(mode, done, total, current, inflight) {
            var max = Math.max(1, num(total, 0));
            var value = Math.max(0, Math.min(max, num(done, 0)));
            hide(ui.progressWrap, false);
            ui.progressBar.setAttribute('aria-valuemax', String(max));
            ui.progressBar.setAttribute('aria-valuenow', String(value));
            ui.progressFill.style.width = String((value / max) * 100) + '%';
            ui.progressText.textContent = 'warm' === mode ? ('Warming cache ' + String(value) + ' of ' + String(max) + ' URLs') : (String(value) + ' of ' + String(max) + ' URLs complete' + (num(inflight, 0) > 0 ? ' - ' + String(num(inflight, 0)) + ' in flight' : ''));
            ui.currentUrl.textContent = current ? shortUrl(current, 64) : '';
        }

        function resetProgress() {
            hide(ui.progressWrap, true);
            ui.progressFill.style.width = '0%';
            ui.progressBar.setAttribute('aria-valuemax', '0');
            ui.progressBar.setAttribute('aria-valuenow', '0');
            ui.progressText.textContent = '';
            ui.currentUrl.textContent = '';
        }

        function livePayload(status, scannedAt) {
            return normalized({ run_id: state.runId, url_count: state.total || state.startedUrls.length, variant_count: state.variants.length, variant_ids: state.variants, dropped_variants: state.dropped, scanned_at: scannedAt || '', status: status || 'running', results: state.order.map(function(url) { return state.live[url]; }).filter(Boolean) });
        }

        function merge(results) {
            normalized({ results: results, variant_ids: state.variants }).results.forEach(function(group) {
                var map = {};
                if (!state.live[group.url]) {
                    state.live[group.url] = { url: group.url, variants: [] };
                    state.order.push(group.url);
                }
                state.live[group.url].variants.forEach(function(row) { map[row.variant_id || row.label] = row; });
                group.variants.forEach(function(row) { map[row.variant_id || row.label] = row; });
                state.live[group.url].variants = Object.keys(map).map(function(key) { return map[key]; });
            });
        }

        function render(payload, focus) {
            var data = normalized(payload);
            state.last = data;
            hide(ui.results, false);
            ui.resultsMeta.innerHTML = '<span class="pcm-scenario-inline-badge is-' + ('cancelled' === data.status ? 'warning' : ('running' === data.status ? 'info' : 'good')) + '">' + esc(('running' === data.status ? 'Running' : ('cancelled' === data.status ? 'Cancelled' : 'Complete'))) + '</span><span class="pcm-scenario-inline-badge is-neutral">' + esc(String(data.url_count) + ' URLs') + '</span><span class="pcm-scenario-inline-badge is-neutral">' + esc(String(data.variant_count) + ' variants') + '</span>' + (data.scanned_at ? '<span class="pcm-scenario-inline-badge is-neutral">' + esc(new Date(String(data.scanned_at).replace(' UTC', 'Z').replace(' ', 'T')).toLocaleString()) + '</span>' : '');
            ui.resultsSummary.innerHTML = (data.dropped_variants.length ? '<div class="pcm-scenario-callout is-warning">Probe limit trimmed the matrix. Skipped variants: ' + esc(data.dropped_variants.map(function(variant) { return variant.label; }).join(', ')) + '.</div>' : '') + ('running' === data.status ? '<div class="pcm-scenario-callout is-info">Scenario Scan is still running. Results update as each URL finishes.</div>' : '') + ('cancelled' === data.status && !data.results.length ? '<div class="pcm-scenario-callout is-warning">The scan was cancelled before any URL finished.</div>' : '');
            ui.recs.innerHTML = data.results.length ? recommendations(data).map(function(rec) { return '<article class="pcm-scenario-recommendation is-' + esc(rec.severity) + '"><div class="pcm-scenario-recommendation-head"><span class="pcm-scenario-chip is-' + ('broken' === rec.severity ? 'danger' : rec.severity) + '">' + esc('broken' === rec.severity ? 'Broken' : ('degraded' === rec.severity ? 'Degraded' : 'Info')) + '</span></div><h5 class="pcm-scenario-recommendation-title">' + esc(rec.title) + '</h5><p class="pcm-scenario-recommendation-copy">' + esc(rec.copy) + '</p>' + (rec.rule ? '<button type="button" class="pcm-btn-text" data-act="open-playbook" data-rule="' + esc(rec.rule) + '">Open playbook</button>' : '') + '</article>'; }).join('') : '<div class="pcm-scenario-empty">Run or load a Scenario Scan to generate recommendations.</div>';
            ui.resultsBody.innerHTML = data.results.length ? data.results.map(function(group) {
                var info = analyze(group);
                return '<article class="pcm-scenario-result-card' + (Object.keys(info.divergent).length ? ' is-cookie-divergent' : '') + '"><div class="pcm-scenario-result-head"><div class="pcm-scenario-result-title-wrap"><h5 class="pcm-scenario-result-title"><a class="pcm-scenario-result-link" href="' + esc(group.url) + '" target="_blank" rel="noopener noreferrer">' + esc(shortUrl(group.url, 76)) + '</a></h5><code class="pcm-scenario-result-subtitle">' + esc(group.url) + '</code></div><div class="pcm-scenario-result-flags">' + (Object.keys(info.divergent).length ? '<span class="pcm-scenario-chip is-warning">Cookie divergence</span>' : '<span class="pcm-scenario-inline-badge is-good">Stable so far</span>') + (info.anonCookie ? '<span class="pcm-scenario-chip is-danger">Set-Cookie on anonymous</span>' : '') + (info.allBypass ? '<span class="pcm-scenario-chip is-danger">All BYPASS</span>' : '') + (info.mobile ? '<span class="pcm-scenario-chip is-warning">Mobile divergence</span>' : '') + '</div></div><div class="pcm-scenario-result-table-wrap"><table class="pcm-scenario-result-table"><thead><tr><th>Variant</th><th>HTTP</th><th>Time</th><th>Verdict</th><th>Cache Headers</th><th>Cache-Control</th><th>Age</th><th>Vary</th><th>Set-Cookie</th></tr></thead><tbody>' + group.variants.map(function(row) { return '<tr' + (info.divergent[row.variant_id] ? ' class="is-cookie-divergent"' : '') + '><td><div class="pcm-scenario-variant-label">' + esc(row.label) + '</div><div class="pcm-scenario-variant-meta">' + esc([row.temp, row.device, row.cookie_mode].filter(Boolean).join(' / ')) + '</div>' + (row.error ? '<div class="pcm-scenario-variant-error">' + esc(row.error) + '</div>' : '') + '</td><td>' + esc(row.http_code ? String(row.http_code) : '--') + '</td><td>' + esc(row.elapsed_ms ? String(row.elapsed_ms) + 'ms' : '--') + '</td><td><span class="pcm-scenario-chip is-' + esc(tone(row)) + '">' + esc(verdictLabel(row)) + '</span></td><td>' + esc(signal(row)) + '</td><td>' + esc(row.cache_headers && row.cache_headers.cache_control || '--') + '</td><td>' + esc(row.cache_headers && row.cache_headers.age || '--') + '</td><td>' + esc(row.cache_headers && row.cache_headers.vary || '--') + '</td><td>' + esc(row.cache_headers && row.cache_headers.set_cookie ? shortUrl(row.cache_headers.set_cookie, 96) : '--') + '</td></tr>'; }).join('') + '</tbody></table></div></article>';
            }).join('') : '<div class="pcm-scenario-empty">' + esc('running' === data.status ? 'Scenario Scan has started. Results appear here as URLs finish.' : 'No completed Scenario Scan results are available yet.') + '</div>';
            clearPlaybook();
            sync();
            if (focus) { focusResults(); }
        }

        function renderRecent() {
            ui.recentList.innerHTML = state.recent.length ? state.recent.map(function(run) {
                return '<article class="pcm-scenario-recent-item' + (state.last && state.last.run_id === num(run.id, 0) ? ' is-active' : '') + '"><div class="pcm-scenario-recent-info"><h5 class="pcm-scenario-recent-title">' + esc((run.completed_at || run.started_at || run.created_at) ? new Date(String(run.completed_at || run.started_at || run.created_at).replace(' UTC', 'Z').replace(' ', 'T')).toLocaleString() : 'Saved run') + '</h5><div class="pcm-scenario-recent-meta"><span class="pcm-scenario-inline-badge is-' + ('cancelled' === run.status ? 'warning' : ('running' === run.status ? 'info' : 'good')) + '">' + esc('running' === run.status ? 'Running' : ('cancelled' === run.status ? 'Cancelled' : 'Complete')) + '</span><span class="pcm-scenario-inline-badge is-neutral">' + esc(String(run.url_count) + ' URLs') + '</span><span class="pcm-scenario-inline-badge is-neutral">' + esc(String(run.variant_count) + ' variants') + '</span></div></div><button type="button" class="pcm-btn-text" data-act="load-run" data-id="' + esc(String(run.id)) + '"' + (state.running ? ' disabled' : '') + '>View</button></article>';
            }).join('') : '<div class="pcm-scenario-empty">No saved Scenario Scan runs yet.</div>';
        }

        function loadRecent() {
            msg(ui.recentStatus, 'Loading recent Scenario Scan runs...', 'info');
            post('pcm_scenario_scan_recent', {}, 20000).then(function(data) {
                state.recent = Array.isArray(data.runs) ? data.runs : [];
                renderRecent();
                msg(ui.recentStatus, state.recent.length ? '' : 'No saved Scenario Scan runs are available yet.', state.recent.length ? '' : 'info');
            }).catch(function(error) {
                state.recent = [];
                renderRecent();
                msg(ui.recentStatus, err(error, 'Unable to load recent Scenario Scan runs.'), 'error');
            });
        }

        function loadRun(id) {
            state.running = false;
            state.cancelRequested = false;
            state.cancelSent = false;
            state.active = 0;
            resetProgress();
            sync();
            msg(ui.runStatus, 'Loading saved Scenario Scan run...', 'info');
            post('pcm_scenario_scan_load', { run_id: id }, 30000).then(function(data) {
                render(data, true);
                msg(ui.runStatus, 'Loaded saved Scenario Scan run #' + String(id) + '.', 'success');
                renderRecent();
            }).catch(function(error) {
                msg(ui.runStatus, err(error, 'Unable to load that saved Scenario Scan run.'), 'error');
            });
        }

        function csv(payload) {
            var head = ['URL', 'Variant', 'Status', 'HTTP Status', 'Response Time (ms)', 'Cache Verdict', 'Cache-Control', 'Age', 'Vary', 'x-cache', 'x-nananana', 'Set-Cookie', 'Error'];
            var rows = [head.join(',')];
            payload.results.forEach(function(group) {
                group.variants.forEach(function(row) {
                    var headers = row.cache_headers || {};
                    rows.push([group.url, row.label, row.status, row.http_code, row.elapsed_ms, verdictLabel(row), headers.cache_control || '', headers.age || '', headers.vary || '', headers.x_cache || '', headers.x_nananana || '', headers.set_cookie || '', row.error || ''].map(function(value) { return '"' + String(value == null ? '' : value).replace(/"/g, '""') + '"'; }).join(','));
                });
            });
            return '\uFEFF' + rows.join('\r\n');
        }

        function filename(ext) {
            var date = (state.last && state.last.scanned_at ? new Date(String(state.last.scanned_at).replace(' UTC', 'Z').replace(' ', 'T')) : new Date()).toISOString().slice(0, 10);
            var slug = String(state.siteName || state.siteHost || 'site').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '') || 'site';
            return 'scenario-scan-' + slug + '-' + date + '.' + ext;
        }

        function downloadCurrent() {
            var blob;
            var link;
            if (!(state.last && state.last.results && state.last.results.length)) {
                msg(ui.runStatus, 'Run or load a Scenario Scan before exporting results.', 'warning');
                return;
            }
            blob = new w.Blob(['json' === ui.exportFormat.value ? JSON.stringify(state.last, null, 2) : csv(state.last)], { type: 'json' === ui.exportFormat.value ? 'application/json;charset=utf-8' : 'text/csv;charset=utf-8' });
            link = d.createElement('a');
            link.href = w.URL.createObjectURL(blob);
            link.download = filename('json' === ui.exportFormat.value ? 'json' : 'csv');
            d.body.appendChild(link);
            link.click();
            d.body.removeChild(link);
            w.setTimeout(function() { w.URL.revokeObjectURL(link.href); }, 1000);
        }

        function openPlaybook(ids) {
            var tries = Array.isArray(ids) ? ids.slice() : [];
            var pid = ++state.playbookId;
            function step() {
                if (pid !== state.playbookId) { return; }
                if (!tries.length) {
                    ui.playbook.innerHTML = '<div class="pcm-scenario-empty">No guided remediation playbook is available for this recommendation yet.</div>';
                    hide(ui.playbook, false);
                    return;
                }
                post('pcm_playbook_lookup', { rule_id: tries.shift() }, 20000).then(function(data) {
                    if (pid !== state.playbookId) { return; }
                    if (!(data.playbook && data.playbook.meta && data.playbook.meta.playbook_id)) { step(); return; }
                    ui.playbook.innerHTML = '<div class="pcm-scenario-playbook-head"><div><h5 class="pcm-scenario-playbook-title">' + esc(data.playbook.meta.title || data.playbook.meta.playbook_id) + '</h5><p class="pcm-scenario-playbook-meta">Severity: ' + esc(data.playbook.meta.severity || 'warning') + '</p></div><button type="button" class="pcm-btn-text" data-act="close-playbook">Close</button></div><div class="pcm-scenario-playbook-body">' + (data.playbook.html_body || '') + '</div>';
                    hide(ui.playbook, false);
                    if ('function' === typeof ui.playbook.scrollIntoView) { ui.playbook.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); }
                }).catch(function(error) {
                    if (404 === error.status) { step(); return; }
                    ui.playbook.innerHTML = '<div class="pcm-scenario-callout is-danger">' + esc(err(error, 'Unable to load the guided remediation playbook.')) + '</div>';
                    hide(ui.playbook, false);
                });
            }
            ui.playbook.innerHTML = '<div class="pcm-scenario-callout is-info">Loading playbook...</div>';
            hide(ui.playbook, false);
            step();
        }

        function resetLive(snap) {
            state.cancelRequested = false;
            state.cancelSent = false;
            state.token = '';
            state.runId = 0;
            state.total = snap.selected.length;
            state.done = 0;
            state.active = 0;
            state.startedUrls = snap.selected.slice();
            state.live = {};
            state.order = [];
            state.variants = normalizeVariants(snap.rows);
            state.dropped = [];
            state.last = null;
            clearPlaybook();
            resetProgress();
        }

        function stopRequestError(error) {
            return 'scan_not_found' === code(error) || 'missing_scan_token' === code(error) || 404 === error.status || 409 === error.status;
        }

        function requestCancel() {
            if (state.cancelSent || !state.token) { return Promise.resolve(); }
            state.cancelSent = true;
            return post('pcm_scenario_scan_cancel', { scan_token: state.token }, 15000).catch(function(error) {
                if (stopRequestError(error)) { return {}; }
                throw error;
            });
        }

        function warm() {
            return new Promise(function(resolve, reject) {
                function step() {
                    if (state.cancelRequested) {
                        requestCancel().finally(function() { resolve({ cancelled: true }); });
                        return;
                    }
                    post('pcm_scenario_scan_warmup', { scan_token: state.token }, 20000).then(function(data) {
                        progress('warm', num(data.warmed, 0), num(data.total, 0), data.current || '', 0);
                        if (data.done) { resolve(data); return; }
                        step();
                    }).catch(function(error) {
                        if (state.cancelRequested && stopRequestError(error)) { resolve({ cancelled: true }); return; }
                        reject(error);
                    });
                }
                step();
            });
        }

        function queue() {
            return new Promise(function(resolve, reject) {
                var settled = false;
                function done(status, scannedAt) { if (!settled) { settled = true; resolve(livePayload(status, scannedAt)); } }
                function fail(error) { if (!settled) { settled = true; reject(error); } }
                function maybeFinish(scannedAt) { if (state.active > 0 || settled) { return; } done(state.cancelRequested ? 'cancelled' : 'complete', scannedAt || ''); }
                function worker() {
                    state.active += 1;
                    post('pcm_scenario_scan_next', { scan_token: state.token, batch_size: BATCH }, 65000).then(function(data) {
                        state.active -= 1;
                        state.runId = num(data.run_id, state.runId);
                        state.total = num(data.total, state.total);
                        state.done = Math.min(state.total, state.done + num(data.processed, 0));
                        state.variants = normalizeVariants(data.variant_ids || state.variants);
                        state.dropped = normalizeVariants(data.dropped_variants || state.dropped);
                        if (data.results && data.results.length) { merge(data.results); }
                        progress('scan', state.done, state.total, data.current || '', num(data.inflight, 0));
                        render(livePayload(data.done ? 'complete' : 'running', data.scanned_at || ''), false);
                        if (data.cancelled || data.done) { done(data.cancelled ? 'cancelled' : 'complete', data.scanned_at || ''); return; }
                        if (state.cancelRequested) { maybeFinish(data.scanned_at || ''); return; }
                        if (num(data.remaining, 0) > 0 && state.active < MAX_REQ) { while (!settled && state.active < MAX_REQ) { worker(); } return; }
                        maybeFinish(data.scanned_at || '');
                    }).catch(function(error) {
                        state.active -= 1;
                        if (state.cancelRequested && stopRequestError(error)) { maybeFinish(''); return; }
                        fail(error);
                    });
                }
                while (state.active < Math.min(MAX_REQ, Math.max(1, state.total))) { worker(); }
            });
        }

        function finish(payload) {
            state.running = false;
            state.cancelRequested = false;
            state.cancelSent = false;
            state.active = 0;
            state.token = '';
            resetProgress();
            render(payload, true);
            msg(ui.runStatus, 'cancelled' === payload.status ? 'Scenario Scan cancelled. Partial results were kept.' : 'Scenario Scan complete.', 'cancelled' === payload.status ? 'warning' : 'success');
            loadRecent();
        }

        function begin() {
            var snap = snapshot();
            if (state.running) { return; }
            if (!snap.selected.length) { msg(ui.runStatus, 'Select at least one URL before starting Scenario Scan.', 'warning'); return; }
            if (!snap.rows.length) { msg(ui.runStatus, 'Select at least one variant before starting Scenario Scan.', 'warning'); return; }
            state.running = true;
            resetLive(snap);
            sync();
            msg(ui.runStatus, 'Creating Scenario Scan queue...', 'info');
            render(livePayload('running', ''), false);
            post('pcm_scenario_scan_run', { urls: snap.selected, variants: JSON.stringify(snap.config) }, 30000).then(function(data) {
                state.runId = num(data.run_id, 0);
                state.token = String(data.scan_token || '');
                state.total = num(data.total, snap.selected.length);
                state.variants = normalizeVariants(data.variant_ids || state.variants);
                state.dropped = normalizeVariants(data.dropped_variants || []);
                if (state.cancelRequested) { return requestCancel().then(function() { return livePayload('cancelled', ''); }); }
                msg(ui.runStatus, data.warmup_required ? 'Scenario Scan queue ready. Warming cache...' : (state.dropped.length ? 'Probe limit reached. Some variants will be skipped.' : 'Scenario Scan running...'), state.dropped.length ? 'warning' : 'info');
                return data.warmup_required ? warm().then(function(result) { return result && result.cancelled ? livePayload('cancelled', '') : queue(); }) : queue();
            }).then(finish).catch(function(error) {
                state.running = false;
                state.cancelRequested = false;
                state.cancelSent = false;
                state.active = 0;
                resetProgress();
                sync();
                msg(ui.runStatus, err(error, 'Scenario Scan failed before it could complete.'), 'error');
                if (state.order.length) { render(livePayload('cancelled', ''), false); }
            });
        }

        function cancel() {
            if (!state.running) { return; }
            state.cancelRequested = true;
            sync();
            msg(ui.runStatus, 'Cancelling Scenario Scan... waiting for in-flight probes to stop.', 'warning');
            requestCancel().catch(function(error) {
                msg(ui.runStatus, err(error, 'Cancellation was requested, but the server did not confirm it.'), 'warning');
            });
        }
    });
})(window, document);
