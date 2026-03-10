/**
 * Scenario Scan — multi-variant cache diagnostics UI.
 *
 * Probes URLs through combinations of warm/cold, cookie/no-cookie,
 * mobile/desktop, and query-param variants, then renders a comparison
 * table so cache behaviour differences are immediately visible.
 */
(function(window, document) {
    'use strict';

    var SECTION_ID = 'pcm-feature-scenario-scan';
    var esc = window.pcmEscapeHtml || function(s) {
        return String(s).replace(/[&<>"']/g, function(c) {
            return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
        });
    };

    window.pcmOnSectionReady = window.pcmOnSectionReady || function(id, fn) {
        var el = document.getElementById(id);
        if (!el) return;
        if (el.getAttribute('data-lazy-loaded')) { fn(el); return; }
        el.addEventListener('pcm-lazy-hydrated', function() { fn(el); });
    };

    window.pcmOnSectionReady(SECTION_ID, function(card) {
        var sourceRadios   = card.querySelectorAll('input[name="pcm_scenario_source"]');
        var customTextarea = document.getElementById('pcm-scenario-custom-urls');
        var urlPreview     = document.getElementById('pcm-scenario-url-preview');
        var runBtn         = document.getElementById('pcm-scenario-run-btn');
        var statusEl       = document.getElementById('pcm-scenario-run-status');
        var resultsWrap    = document.getElementById('pcm-scenario-results');
        var resultsBody    = document.getElementById('pcm-scenario-results-body');
        var qpInput        = document.getElementById('pcm-scenario-query-params');

        if (!runBtn) return;

        var resolvedUrls = [];
        var running = false;

        // ── Source selection ────────────────────────────────────────────

        function getSource() {
            for (var i = 0; i < sourceRadios.length; i++) {
                if (sourceRadios[i].checked) return sourceRadios[i].value;
            }
            return 'top_pages';
        }

        function onSourceChange() {
            var src = getSource();
            if (src === 'custom') {
                customTextarea.classList.remove('pcm-hidden');
                urlPreview.innerHTML = '';
                resolvedUrls = [];
            } else {
                customTextarea.classList.add('pcm-hidden');
                resolveUrls(src);
            }
        }

        function resolveUrls(source) {
            urlPreview.innerHTML = '<em>Loading URLs\u2026</em>';
            window.pcmPost({
                action: 'pcm_scenario_scan_resolve_urls',
                nonce: window.pcmGetCacheabilityNonce(),
                source: source
            }).then(function(res) {
                if (res && res.success && res.data && res.data.urls) {
                    resolvedUrls = res.data.urls;
                    renderUrlPreview();
                } else {
                    urlPreview.innerHTML = '<em>No URLs found for this source.</em>';
                    resolvedUrls = [];
                }
            }).catch(function() {
                urlPreview.innerHTML = '<em>Failed to resolve URLs.</em>';
                resolvedUrls = [];
            });
        }

        function renderUrlPreview() {
            if (!resolvedUrls.length) {
                urlPreview.innerHTML = '<em>No URLs found.</em>';
                return;
            }
            var html = '<span class="pcm-scenario-url-count">' + resolvedUrls.length + ' URLs</span><ul class="pcm-scenario-url-list">';
            for (var i = 0; i < Math.min(resolvedUrls.length, 10); i++) {
                html += '<li>' + esc(resolvedUrls[i]) + '</li>';
            }
            if (resolvedUrls.length > 10) {
                html += '<li><em>\u2026and ' + (resolvedUrls.length - 10) + ' more</em></li>';
            }
            html += '</ul>';
            urlPreview.innerHTML = html;
        }

        for (var i = 0; i < sourceRadios.length; i++) {
            sourceRadios[i].addEventListener('change', onSourceChange);
        }

        // Initial load.
        onSourceChange();

        // ── Variant config builder ─────────────────────────────────────

        function getVariantConfig() {
            var cfg = {};
            var checkboxes = {
                warm: 'pcm_scenario_warm',
                cold: 'pcm_scenario_cold',
                desktop: 'pcm_scenario_desktop',
                mobile: 'pcm_scenario_mobile',
                no_cookie: 'pcm_scenario_no_cookie',
                cookie: 'pcm_scenario_cookie'
            };
            var keys = Object.keys(checkboxes);
            for (var i = 0; i < keys.length; i++) {
                var el = card.querySelector('input[name="' + checkboxes[keys[i]] + '"]');
                if (el && el.checked) cfg[keys[i]] = true;
            }

            // Query params.
            if (qpInput && qpInput.value.trim()) {
                cfg.query_params = qpInput.value.trim().split(',').map(function(s) { return s.trim(); }).filter(Boolean);
            }

            return cfg;
        }

        // ── Collect URLs ───────────────────────────────────────────────

        function collectUrls() {
            var src = getSource();
            if (src === 'custom') {
                var raw = (customTextarea.value || '').trim();
                if (!raw) return [];
                return raw.split(/[\n,]+/).map(function(s) { return s.trim(); }).filter(function(s) { return s.indexOf('http') === 0; });
            }
            return resolvedUrls.slice();
        }

        // ── Run scan ───────────────────────────────────────────────────

        function processScenarioQueue(scanToken, total) {
            function processNext() {
                return window.pcmPost({
                    action: 'pcm_scenario_scan_next',
                    nonce: window.pcmGetCacheabilityNonce(),
                    scan_token: scanToken
                }).then(function(res) {
                    if (!res || !res.success || !res.data) {
                        throw new Error((res && res.data && res.data.message) || 'Processing failed');
                    }
                    if (!res.data.done) {
                        var scanned = total - res.data.remaining;
                        statusEl.textContent = 'Scanning\u2026 ' + scanned + '/' + total + ' URLs probed.';
                        return processNext();
                    }
                    return res.data;
                });
            }
            return processNext();
        }

        runBtn.addEventListener('click', function() {
            if (running) return;
            var urls = collectUrls();
            if (!urls.length) {
                statusEl.textContent = 'No URLs to scan. Select a source or enter URLs.';
                return;
            }

            var config = getVariantConfig();
            running = true;
            runBtn.disabled = true;
            runBtn.textContent = 'Starting\u2026';
            statusEl.textContent = 'Starting scenario scan\u2026';
            resultsWrap.classList.add('pcm-hidden');

            var body = {
                action: 'pcm_scenario_scan_run',
                nonce: window.pcmGetCacheabilityNonce(),
                variants: JSON.stringify(config)
            };
            for (var u = 0; u < urls.length; u++) {
                body['urls[' + u + ']'] = urls[u];
            }

            window.pcmPost(body).then(function(res) {
                if (!res || !res.success || !res.data || !res.data.scan_token) {
                    throw new Error((res && res.data && res.data.message) || 'Unable to start scan');
                }

                var total = res.data.total;
                runBtn.textContent = 'Scanning\u2026';
                statusEl.textContent = 'Scanning\u2026 0/' + total + ' URLs probed.';

                return processScenarioQueue(res.data.scan_token, total);
            }).then(function(d) {
                if (!d) return;
                statusEl.innerHTML = '<small>Scanned ' + d.url_count + ' URL(s) \u00d7 ' + d.variant_count + ' variant(s) at ' + esc(d.scanned_at) + '</small>';
                renderResults(d);
                resultsWrap.classList.remove('pcm-hidden');
            }).catch(function(err) {
                window.pcmHandleError('Scenario Scan', err, statusEl);
            }).finally(function() {
                running = false;
                runBtn.disabled = false;
                runBtn.textContent = 'Run Scenario Scan';
            });
        });

        // ── Result rendering ───────────────────────────────────────────

        function renderResults(data) {
            if (!data.results || !data.results.length) {
                resultsBody.innerHTML = '<p>No results.</p>';
                return;
            }

            var variantIds = data.variant_ids || [];
            var html = '';

            for (var r = 0; r < data.results.length; r++) {
                var row = data.results[r];
                html += '<div class="pcm-scenario-url-block">';
                html += '<h5 class="pcm-scenario-url-heading">' + esc(row.url) + '</h5>';
                html += '<div class="pcm-scenario-variants-scroll"><table class="pcm-scenario-table">';

                // Header row.
                html += '<thead><tr><th class="pcm-scenario-th-metric">Metric</th>';
                for (var v = 0; v < row.variants.length; v++) {
                    html += '<th class="pcm-scenario-th-variant">' + esc(row.variants[v].label) + '</th>';
                }
                html += '</tr></thead><tbody>';

                // HTTP Code row.
                html += '<tr><td class="pcm-scenario-td-label">HTTP Status</td>';
                for (v = 0; v < row.variants.length; v++) {
                    var vr = row.variants[v];
                    if (vr.status === 'error') {
                        html += '<td class="pcm-scenario-td pcm-scenario-bad">Error</td>';
                    } else {
                        var codeClass = vr.http_code >= 200 && vr.http_code < 400 ? 'pcm-scenario-good' : 'pcm-scenario-bad';
                        html += '<td class="pcm-scenario-td ' + codeClass + '">' + vr.http_code + '</td>';
                    }
                }
                html += '</tr>';

                // Response time row.
                html += '<tr><td class="pcm-scenario-td-label">Response Time</td>';
                for (v = 0; v < row.variants.length; v++) {
                    var ms = row.variants[v].elapsed_ms;
                    var timeClass = ms < 500 ? 'pcm-scenario-good' : (ms < 2000 ? 'pcm-scenario-warn' : 'pcm-scenario-bad');
                    html += '<td class="pcm-scenario-td ' + timeClass + '">' + ms + ' ms</td>';
                }
                html += '</tr>';

                // Cache verdict row.
                html += '<tr><td class="pcm-scenario-td-label">Cache Verdict</td>';
                for (v = 0; v < row.variants.length; v++) {
                    var ch = row.variants[v].cache_headers;
                    if (!ch) {
                        html += '<td class="pcm-scenario-td">—</td>';
                    } else {
                        var verdictClass = 'pcm-scenario-neutral';
                        if (/hit|active/.test(ch.verdict)) verdictClass = 'pcm-scenario-good';
                        if (/miss|broken/.test(ch.verdict)) verdictClass = 'pcm-scenario-warn';
                        html += '<td class="pcm-scenario-td ' + verdictClass + '">' + esc(ch.verdict.replace(/_/g, ' ')) + '</td>';
                    }
                }
                html += '</tr>';

                // Cache-Control row.
                html += renderHeaderRow('Cache-Control', 'cache_control', row.variants);

                // Age row.
                html += renderHeaderRow('Age', 'age', row.variants);

                // Vary row.
                html += renderHeaderRow('Vary', 'vary', row.variants);

                // x-cache row.
                html += renderHeaderRow('x-cache', 'x_cache', row.variants);

                // x-nananana row.
                html += renderHeaderRow('x-nananana', 'x_nananana', row.variants);

                // Set-Cookie warning.
                html += '<tr><td class="pcm-scenario-td-label">Set-Cookie</td>';
                for (v = 0; v < row.variants.length; v++) {
                    var hasCk = row.variants[v].cache_headers && row.variants[v].cache_headers.has_set_cookie;
                    html += '<td class="pcm-scenario-td ' + (hasCk ? 'pcm-scenario-bad' : 'pcm-scenario-good') + '">'
                        + (hasCk ? 'Yes' : 'No') + '</td>';
                }
                html += '</tr>';

                html += '</tbody></table></div></div>';
            }

            resultsBody.innerHTML = html;
        }

        function renderHeaderRow(label, key, variants) {
            var hasAny = false;
            for (var v = 0; v < variants.length; v++) {
                if (variants[v].cache_headers && variants[v].cache_headers[key]) hasAny = true;
            }
            if (!hasAny) return '';

            var html = '<tr><td class="pcm-scenario-td-label">' + esc(label) + '</td>';
            for (var v2 = 0; v2 < variants.length; v2++) {
                var val = variants[v2].cache_headers ? variants[v2].cache_headers[key] : '';
                html += '<td class="pcm-scenario-td">' + (val ? esc(val) : '—') + '</td>';
            }
            html += '</tr>';
            return html;
        }
    });

})(window, document);
