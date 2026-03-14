/**
 * Layered Probe Runner - Edge, Origin, and Object Cache side-by-side UI.
 *
 * Waits for the lazy-hydrated event on #pcm-feature-layered-probe, then
 * wires up the URL input, run button, and result rendering.
 */
(function(window, document) {
    'use strict';

    var SECTION_ID = 'pcm-feature-layered-probe';
    var esc = window.pcmEscapeHtml || function(s) {
        return String(s).replace(/[&<>"']/g, function(c) {
            return {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#39;'
            }[c];
        });
    };

    window.pcmOnSectionReady = window.pcmOnSectionReady || function(id, fn) {
        var el = document.getElementById(id);
        if (!el) return;
        if (el.getAttribute('data-lazy-loaded')) {
            fn(el);
            return;
        }
        el.addEventListener('pcm-lazy-hydrated', function() {
            fn(el);
        });
    };

    window.pcmOnSectionReady(SECTION_ID, function() {
        var urlInput = document.getElementById('pcm-probe-url');
        var runBtn = document.getElementById('pcm-probe-run-btn');
        var statusEl = document.getElementById('pcm-probe-status');
        var resultsEl = document.getElementById('pcm-probe-results');
        var rawWrap = document.getElementById('pcm-probe-raw-toggle-wrap');
        var rawToggle = document.getElementById('pcm-probe-raw-toggle');
        var rawBody = document.getElementById('pcm-probe-raw-headers');

        if (!urlInput || !runBtn) return;

        var running = false;

        runBtn.addEventListener('click', function() {
            if (running) return;

            var url = urlInput.value.trim();
            if (!url) {
                statusEl.textContent = 'Enter a URL to probe.';
                return;
            }

            running = true;
            runBtn.disabled = true;
            runBtn.textContent = 'Probing...';
            statusEl.textContent = 'Running edge, origin, and object-cache probes...';
            resultsEl.classList.add('pcm-hidden');
            rawWrap.classList.add('pcm-hidden');

            window.pcmPost(
                {
                    action: 'pcm_layered_probe',
                    nonce: window.pcmGetCacheabilityNonce(),
                    url: url
                },
                { timeout: 35000 }
            ).then(function(res) {
                running = false;
                runBtn.disabled = false;
                runBtn.textContent = 'Run Probe';

                if (!res || !res.success) {
                    var msg = (res && res.data && res.data.message) ? res.data.message : 'Probe failed.';
                    statusEl.textContent = msg;
                    return;
                }

                var data = res.data;
                statusEl.innerHTML = '<small>Probed at ' + esc(data.probed_at) + '</small>';
                renderEdge(data.edge);
                renderOrigin(data.origin);
                renderObjectCache(data.object_cache);
                renderRawHeaders(data);
                resultsEl.classList.remove('pcm-hidden');
                rawWrap.classList.remove('pcm-hidden');
            }).catch(function(err) {
                running = false;
                runBtn.disabled = false;
                runBtn.textContent = 'Run Probe';
                window.pcmHandleError('Layered Probe', err, statusEl);
            });
        });

        if (rawToggle && rawBody) {
            rawToggle.addEventListener('click', function() {
                var hidden = rawBody.classList.toggle('pcm-hidden');
                rawToggle.textContent = hidden ? 'Show Raw Headers' : 'Hide Raw Headers';
            });
        }

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

            el.innerHTML = '<div class="pcm-probe-kv"><span class="pcm-probe-k">Backend</span><span class="pcm-probe-v">'
                + esc(data.class || 'none') + '</span></div>'
                + '<div class="pcm-probe-kv"><span class="pcm-probe-k">Available</span><span class="pcm-probe-v">'
                + (data.available ? 'Yes' : 'No') + '</span></div>'
                + '<div class="pcm-probe-kv"><span class="pcm-probe-k">External</span><span class="pcm-probe-v">'
                + (data.external ? 'Yes' : 'No') + '</span></div>'
                + '<p class="pcm-probe-note">Showing basic object-cache backend detection only.</p>';
        }

        function renderRawHeaders(data) {
            if (!rawBody) return;

            var html = '';
            if (data.edge && data.edge.raw_headers) {
                html += '<h5>Edge Response Headers</h5>' + headersTable(data.edge.raw_headers);
            }
            if (data.origin && data.origin.raw_headers) {
                html += '<h5>Origin Response Headers</h5>' + headersTable(data.origin.raw_headers);
            }

            rawBody.innerHTML = html || '<p>No headers captured.</p>';
        }

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
