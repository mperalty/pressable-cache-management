/**
 * Layered Probe Runner — Edge, Origin & Object-Cache side-by-side UI.
 *
 * Waits for the lazy-hydrated event on #pcm-feature-layered-probe, then
 * wires up the URL input, run button, and result rendering.
 */
(function(window, document) {
    'use strict';

    var SECTION_ID = 'pcm-feature-layered-probe';
    var esc = window.pcmEscapeHtml || function(s) { return String(s).replace(/[&<>"']/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); };

    window.pcmOnSectionReady = window.pcmOnSectionReady || function(id, fn) {
        var el = document.getElementById(id);
        if (!el) return;
        if (el.getAttribute('data-lazy-loaded')) { fn(el); return; }
        el.addEventListener('pcm-lazy-hydrated', function() { fn(el); });
    };

    window.pcmOnSectionReady(SECTION_ID, function(card) {
        var urlInput   = document.getElementById('pcm-probe-url');
        var runBtn     = document.getElementById('pcm-probe-run-btn');
        var statusEl   = document.getElementById('pcm-probe-status');
        var resultsEl  = document.getElementById('pcm-probe-results');
        var rawWrap    = document.getElementById('pcm-probe-raw-toggle-wrap');
        var rawToggle  = document.getElementById('pcm-probe-raw-toggle');
        var rawBody    = document.getElementById('pcm-probe-raw-headers');

        if (!urlInput || !runBtn) return;

        var running = false;

        runBtn.addEventListener('click', function() {
            if (running) return;
            var url = urlInput.value.trim();
            if (!url) { statusEl.textContent = 'Enter a URL to probe.'; return; }

            running = true;
            runBtn.disabled = true;
            runBtn.textContent = 'Probing\u2026';
            statusEl.textContent = 'Running edge, origin, and object-cache probes\u2026';
            resultsEl.classList.add('pcm-hidden');
            rawWrap.classList.add('pcm-hidden');

            window.pcmPost({
                action: 'pcm_layered_probe',
                nonce: window.pcmGetCacheabilityNonce(),
                url: url
            }, { timeout: 35000 }).then(function(res) {
                running = false;
                runBtn.disabled = false;
                runBtn.textContent = 'Run Probe';

                if (!res || !res.success) {
                    var msg = (res && res.data && res.data.message) ? res.data.message : 'Probe failed.';
                    statusEl.textContent = msg;
                    return;
                }

                var d = res.data;
                statusEl.innerHTML = '<small>Probed at ' + esc(d.probed_at) + '</small>';
                renderEdge(d.edge);
                renderOrigin(d.origin);
                renderObjectCache(d.object_cache);
                renderRawHeaders(d);
                resultsEl.classList.remove('pcm-hidden');
                rawWrap.classList.remove('pcm-hidden');

            }).catch(function(err) {
                running = false;
                runBtn.disabled = false;
                runBtn.textContent = 'Run Probe';
                window.pcmHandleError('Layered Probe', err, statusEl);
            });
        });

        // Raw headers toggle
        if (rawToggle && rawBody) {
            rawToggle.addEventListener('click', function() {
                var hidden = rawBody.classList.toggle('pcm-hidden');
                rawToggle.textContent = hidden ? 'Show Raw Headers' : 'Hide Raw Headers';
            });
        }

        // ── Renderers ──────────────────────────────────────────────────────

        function renderEdge(data) {
            var el = document.getElementById('pcm-probe-edge-body');
            if (!el) return;
            if (data.status === 'error') {
                el.innerHTML = errBlock(data.error, data.elapsed_ms);
                return;
            }
            el.innerHTML = httpBadge(data.http_code, data.elapsed_ms)
                + verdictRow(data.headers.verdict)
                + headerRow('x-cache', data.headers.x_cache)
                + headerRow('x-nananana', data.headers.x_nananana)
                + headerRow('cf-cache-status', data.headers.cf_status)
                + headerRow('cache-control', data.headers.cache_control)
                + headerRow('age', data.headers.age)
                + headerRow('vary', data.headers.vary)
                + cookieWarning(data.headers.has_set_cookie)
                + headerRow('server', data.headers.server);
        }

        function renderOrigin(data) {
            var el = document.getElementById('pcm-probe-origin-body');
            if (!el) return;
            if (data.status === 'error') {
                el.innerHTML = errBlock(data.error, data.elapsed_ms);
                return;
            }
            el.innerHTML = httpBadge(data.http_code, data.elapsed_ms)
                + verdictRow(data.headers.verdict)
                + headerRow('cache-control', data.headers.cache_control)
                + headerRow('x-nananana', data.headers.x_nananana)
                + headerRow('vary', data.headers.vary)
                + cookieWarning(data.headers.has_set_cookie)
                + headerRow('x-powered-by', data.headers.x_powered_by)
                + headerRow('server', data.headers.server);
        }

        function renderObjectCache(data) {
            var el = document.getElementById('pcm-probe-objcache-body');
            if (!el) return;

            if (data.status === 'basic') {
                el.innerHTML = '<div class="pcm-probe-kv"><span class="pcm-probe-k">Backend</span><span class="pcm-probe-v">'
                    + esc(data.class) + '</span></div>'
                    + '<div class="pcm-probe-kv"><span class="pcm-probe-k">Available</span><span class="pcm-probe-v">'
                    + (data.available ? 'Yes' : 'No') + '</span></div>'
                    + '<p class="pcm-probe-note">Enable Object Cache Intelligence for detailed metrics.</p>';
                return;
            }

            if (data.status === 'empty') {
                el.innerHTML = '<p class="pcm-probe-note">No snapshot available. Run a refresh from Cache Overview first.</p>';
                return;
            }

            var hitClass = 'pcm-probe-neutral';
            if (data.hit_ratio !== null) {
                hitClass = data.hit_ratio >= 80 ? 'pcm-probe-good' : (data.hit_ratio >= 50 ? 'pcm-probe-warn' : 'pcm-probe-bad');
            }

            el.innerHTML = metricCard('Hit Ratio', data.hit_ratio !== null ? data.hit_ratio + '%' : '—', hitClass)
                + metricCard('Memory Pressure', data.memory_pressure !== null ? data.memory_pressure + '%' : '—',
                    data.memory_pressure !== null && data.memory_pressure >= 85 ? 'pcm-probe-bad' : 'pcm-probe-neutral')
                + metricCard('Evictions', data.evictions !== null ? data.evictions.toLocaleString() : '—',
                    data.evictions !== null && data.evictions >= 100 ? 'pcm-probe-warn' : 'pcm-probe-neutral')
                + (data.provider ? '<div class="pcm-probe-kv"><span class="pcm-probe-k">Provider</span><span class="pcm-probe-v">' + esc(data.provider) + '</span></div>' : '')
                + (data.taken_at ? '<div class="pcm-probe-kv"><span class="pcm-probe-k">Snapshot</span><span class="pcm-probe-v">' + esc(data.taken_at) + '</span></div>' : '');
        }

        function renderRawHeaders(d) {
            if (!rawBody) return;
            var html = '';
            if (d.edge && d.edge.raw_headers) {
                html += '<h5>Edge Response Headers</h5>' + headersTable(d.edge.raw_headers);
            }
            if (d.origin && d.origin.raw_headers) {
                html += '<h5>Origin Response Headers</h5>' + headersTable(d.origin.raw_headers);
            }
            rawBody.innerHTML = html || '<p>No headers captured.</p>';
        }

        // ── Helpers ────────────────────────────────────────────────────────

        function httpBadge(code, ms) {
            var cls = code >= 200 && code < 400 ? 'pcm-probe-good' : 'pcm-probe-bad';
            return '<div class="pcm-probe-http-badge ' + cls + '">'
                + '<span class="pcm-probe-http-code">HTTP ' + esc(String(code)) + '</span>'
                + '<span class="pcm-probe-http-time">' + esc(String(ms)) + ' ms</span>'
                + '</div>';
        }

        function verdictRow(verdict) {
            if (!verdict || verdict === 'unknown') return '';
            var label = verdict.replace(/_/g, ' ');
            var cls = 'pcm-probe-neutral';
            if (/hit|active/.test(verdict)) cls = 'pcm-probe-good';
            if (/miss|broken/.test(verdict)) cls = 'pcm-probe-warn';
            return '<div class="pcm-probe-verdict ' + cls + '">' + esc(label) + '</div>';
        }

        function headerRow(name, value) {
            if (!value) return '';
            return '<div class="pcm-probe-kv"><span class="pcm-probe-k">' + esc(name) + '</span><span class="pcm-probe-v">' + esc(value) + '</span></div>';
        }

        function cookieWarning(hasCookie) {
            if (!hasCookie) return '';
            return '<div class="pcm-probe-kv pcm-probe-warn-row"><span class="pcm-probe-k">set-cookie</span><span class="pcm-probe-v">Present (may break caching)</span></div>';
        }

        function metricCard(label, value, cls) {
            return '<div class="pcm-probe-metric ' + (cls || '') + '">'
                + '<span class="pcm-probe-metric-value">' + esc(String(value)) + '</span>'
                + '<span class="pcm-probe-metric-label">' + esc(label) + '</span>'
                + '</div>';
        }

        function errBlock(msg, ms) {
            return '<div class="pcm-probe-http-badge pcm-probe-bad">'
                + '<span class="pcm-probe-http-code">Error</span>'
                + '<span class="pcm-probe-http-time">' + esc(String(ms || 0)) + ' ms</span>'
                + '</div>'
                + '<p class="pcm-probe-note">' + esc(msg) + '</p>';
        }

        function headersTable(headers) {
            var html = '<table class="pcm-probe-headers-table"><tbody>';
            var keys = Object.keys(headers);
            for (var i = 0; i < keys.length; i++) {
                html += '<tr><td class="pcm-probe-ht-name">' + esc(keys[i]) + '</td><td class="pcm-probe-ht-val">' + esc(headers[keys[i]]) + '</td></tr>';
            }
            html += '</tbody></table>';
            return html;
        }
    });

})(window, document);
