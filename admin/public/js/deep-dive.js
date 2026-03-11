/**
 * Lazy-load Deep Dive section content using <template> tags.
 *
 * Each .pcm-lazy-section card renders only its header server-side.
 * The heavy body content lives inside a <template class="pcm-lazy-template">.
 * An IntersectionObserver hydrates the content into the DOM when the card
 * first scrolls into (or near) the viewport, then removes the skeleton.
 */
(function(){
    var sections = document.querySelectorAll('.pcm-lazy-section');
    if (!sections.length) return;

    function hydrateSection(card) {
        if (card.getAttribute('data-lazy-loaded')) return;
        card.setAttribute('data-lazy-loaded', '1');

        var tpl = card.querySelector('.pcm-lazy-template');
        var skeleton = card.querySelector('.pcm-lazy-skeleton');

        if (tpl) {
            var content = document.importNode(tpl.content, true);
            // Insert template content right before the template element
            tpl.parentNode.insertBefore(content, tpl);
            tpl.remove();
        }

        if (skeleton) {
            skeleton.remove();
        }

        // Dispatch a custom event so other scripts can react after hydration
        card.dispatchEvent(new CustomEvent('pcm-lazy-hydrated', { bubbles: true }));
    }

    if ('IntersectionObserver' in window) {
        var observer = new IntersectionObserver(function(entries) {
            entries.forEach(function(entry) {
                if (entry.isIntersecting) {
                    observer.unobserve(entry.target);
                    hydrateSection(entry.target);
                }
            });
        }, {
            // Start loading slightly before the section is in view
            rootMargin: '200px 0px'
        });

        sections.forEach(function(section) {
            observer.observe(section);
        });
    } else {
        // Fallback: hydrate all sections immediately for older browsers
        sections.forEach(function(section) {
            hydrateSection(section);
        });
    }

    // Also handle anchor-nav clicks: if a user clicks a nav link targeting a
    // lazy section, hydrate it immediately so the JS that initialises that
    // section can find its DOM nodes.
    var anchorNav = document.getElementById('pcm-deep-dive-nav');
    if (anchorNav) {
        anchorNav.addEventListener('click', function(e) {
            var link = e.target.closest('a[href^="#"]');
            if (!link) return;
            var target = document.querySelector(link.getAttribute('href'));
            if (target && target.classList.contains('pcm-lazy-section')) {
                hydrateSection(target);
            }
        });
    }

    // Expose globally so other code can force-hydrate a section if needed
    window.pcmHydrateLazySection = hydrateSection;
})();

/**
 * Helper: run a callback after a lazy section is hydrated.
 * If the section is already hydrated (or not lazy), runs immediately.
 * @param {string} sectionId - The id of the .pcm-lazy-section element.
 * @param {Function} initFn - Initialisation function to call once DOM is ready.
 */
window.pcmOnSectionReady = function(sectionId, initFn) {
    var card = document.getElementById(sectionId);
    if (!card) return;

    // Already hydrated or not a lazy section
    if (card.getAttribute('data-lazy-loaded') || !card.classList.contains('pcm-lazy-section')) {
        initFn();
        return;
    }

    // Wait for hydration
    card.addEventListener('pcm-lazy-hydrated', function handler() {
        card.removeEventListener('pcm-lazy-hydrated', handler);
        initFn();
    });
};

(function(){
        if (window.pcmRenderDeepDiveDependencyError) return;

        var escapeHtml = window.pcmEscapeHtml;

        function payloadError(payload, fallback) {
            if (payload && payload.data && payload.data.message) return payload.data.message;
            if (payload && payload.message) return payload.message;
            return fallback || 'Unexpected AJAX response.';
        }

        window.pcmRenderDeepDiveDependencyError = function(targetEl, dependencyLabel, retryAction, error, fallbackMessage) {
            if (!targetEl) return;
            var raw = error && error.message ? error.message : (fallbackMessage || 'Unexpected AJAX response.');
            var detail;
            if (error && (error.isTimeout || raw === 'timeout')) {
                detail = 'The request timed out. This can happen when the object cache server is slow to respond. Click Retry or use the Refresh button to try again.';
            } else if (error && error.status >= 500) {
                detail = 'Server error (HTTP ' + error.status + '). Check your PHP error logs for details.';
            } else if (error && error.status >= 400) {
                detail = 'Request failed (HTTP ' + error.status + '). The nonce may have expired — try reloading the page.';
            } else {
                detail = raw;
            }
            if (targetEl.style && targetEl.style.display === 'none') {
                targetEl.style.display = 'block';
            }
            targetEl.innerHTML = '<div class="pcm-inline-error" role="alert" aria-live="assertive" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">'
                + '<span>' + escapeHtml(dependencyLabel) + ': ' + escapeHtml(detail) + '</span>'
                + (retryAction ? '<button type="button" class="pcm-btn-text" data-action="pcm-retry" data-retry-action="' + escapeHtml(retryAction) + '">Retry</button>' : '')
                + '</div>';
        };

        window.pcmRenderSkeletonRows = function(targetEl, rowCount, widths) {
            if (!targetEl) return;
            var totalRows = Math.max(1, Number(rowCount) || 1);
            var rowWidths = Array.isArray(widths) ? widths : [];
            var html = '<div class="pcm-skeleton-block" aria-hidden="true">';
            for (var i = 0; i < totalRows; i++) {
                var width = rowWidths[i] || '100%';
                html += '<div class="pcm-skeleton" style="width:' + width + ';"></div>';
            }
            html += '</div>';
            targetEl.innerHTML = html;
        };

        window.pcmPayloadErrorMessage = payloadError;
        window.pcmRenderDeepDiveDependencyError = function(targetEl, dependencyLabel, retryAction, error, fallbackMessage) {
            if (!targetEl) return;
            var raw = error && error.message ? error.message : (fallbackMessage || 'Unexpected AJAX response.');
            var detail;
            if (error && (error.isTimeout || raw === 'timeout')) {
                detail = 'The request timed out. This can happen when the object cache server is slow to respond. Click Retry or use the Refresh button to try again.';
            } else if (error && error.status >= 500) {
                detail = 'Server error (HTTP ' + error.status + '). Check your PHP error logs for details.';
            } else if (error && error.status === 404) {
                detail = 'The requested route diagnosis was not found. Run a fresh scan to regenerate cacheability details for this route.';
            } else if (error && error.status === 403) {
                detail = 'Permission denied or your session expired. Reload the page and try again.';
            } else if (error && error.status >= 400) {
                detail = 'Request failed (HTTP ' + error.status + ').';
            } else {
                detail = raw;
            }
            if (targetEl.style && targetEl.style.display === 'none') {
                targetEl.style.display = 'block';
            }
            targetEl.innerHTML = '<div class="pcm-inline-error" role="alert" aria-live="assertive" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">'
                + '<span>' + escapeHtml(dependencyLabel) + ': ' + escapeHtml(detail) + '</span>'
                + (retryAction ? '<button type="button" class="pcm-btn-text" data-action="pcm-retry" data-retry-action="' + escapeHtml(retryAction) + '">Retry</button>' : '')
                + '</div>';
        };
    })();

window.pcmOnSectionReady('pcm-feature-cacheability-advisor', function(){
(function(){
        if (typeof window.pcmGetCacheabilityNonce !== 'function' || !window.pcmGetCacheabilityNonce()) return;
        var runBtn = document.getElementById('pcm-advisor-run-btn');
        var runStatus = document.getElementById('pcm-advisor-run-status');
        var scoreWrap = document.getElementById('pcm-advisor-template-scores');
        var findingsWrap = document.getElementById('pcm-advisor-findings');
        var sensitivityWrap = document.getElementById('pcm-advisor-sensitivity');
        var diagnosisWrap = document.getElementById('pcm-advisor-diagnosis');
        var playbookWrap = document.getElementById('pcm-advisor-playbook');
        var section = document.getElementById('pcm-feature-cacheability-advisor');
        var currentRunId = 0;
        var currentRunResults = [];
        if (!runBtn || !runStatus || !scoreWrap || !findingsWrap || !sensitivityWrap || !diagnosisWrap || !playbookWrap || !section) return;

        function showError(targetEl, retryAction, error, fallbackMessage) {
            window.pcmRenderDeepDiveDependencyError(targetEl, 'Cacheability Advisor', retryAction, error, fallbackMessage);
        }

        function hasDiagnosisData(result) {
            return !!(result
                && result.diagnosis
                && typeof result.diagnosis === 'object'
                && Object.keys(result.diagnosis).length);
        }

        function findResultByUrl(url) {
            if (!url || !Array.isArray(currentRunResults)) return null;
            for (var i = 0; i < currentRunResults.length; i++) {
                if ((currentRunResults[i] && currentRunResults[i].url) === url) {
                    return currentRunResults[i];
                }
            }
            return null;
        }

        function renderDiagnosisUnavailable(message, url) {
            var html = '<div class="pcm-panel-text">';
            if (url) {
                html += '<p><strong>URL:</strong> <span style="word-break:break-word;">' + escapeHtml(url) + '</span></p>';
            }
            html += '<em>' + escapeHtml(message || 'No route diagnosis is available yet.') + '</em></div>';
            diagnosisWrap.innerHTML = html;
        }

        function renderScores(results) {
            if (!Array.isArray(results) || !results.length) {
                scoreWrap.innerHTML = '<em>No results available yet.</em>';
                return;
            }

            var agg = {};
            results.forEach(function(row){
                var type = row.template_type || 'unknown';
                var score = Number(row.score || 0);
                if (!agg[type]) agg[type] = { total: 0, count: 0 };
                agg[type].total += score;
                agg[type].count += 1;
            });

            var html = '<div class="pcm-score-list">';
            Object.keys(agg).sort().forEach(function(type){
                var avg = Math.round(agg[type].total / Math.max(1, agg[type].count));
                var scoreClass = avg > 80 ? 'is-good' : (avg >= 50 ? 'is-warn' : 'is-bad');
                html += '<div class="pcm-score-item">'
                    + '<div class="pcm-score-meta"><strong>' + escapeHtml(type) + '</strong><span>' + agg[type].count + ' URLs</span></div>'
                    + '<div class="pcm-score-bar"><span class="pcm-score-fill ' + scoreClass + '" style="width:' + Math.max(0, Math.min(100, avg)) + '%;"></span><span class="pcm-score-value">' + avg + '/100</span></div>'
                    + '</div>';
            });
            html += '</div>';

            html += '<div style="margin-top:8px;">';
            html += renderCollapsibleSection('pcm-sampled-routes-panel', 'Sampled routes', function(){
                var routesHtml = '<ul style="margin:4px 0 0;padding-left:18px;">';
                results.slice(0, 20).forEach(function(row){
                    var routeUrl = row.url || '';
                    routesHtml += '<li><button type="button" class="pcm-btn-text" data-action="open-diagnosis" data-url="' + escapeHtml(routeUrl) + '" style="padding:0;word-break:break-word;text-align:left;">' + escapeHtml(routeUrl) + '</button></li>';
                });
                routesHtml += '</ul>';
                return routesHtml;
            }());
            html += '</div>';
            scoreWrap.innerHTML = html;
        }

        function renderCollapsibleSection(id, label, contentHtml) {
            return '<div class="pcm-collapsible-panel">'
                + '<button type="button" class="pcm-btn-text" data-action="toggle-panel" data-target="' + escapeHtml(id) + '" aria-expanded="false">Show ' + escapeHtml(label) + '</button>'
                + '<div id="' + escapeHtml(id) + '" style="display:none;margin-top:6px;">' + contentHtml + '</div>'
                + '</div>';
        }

        function chips(items) {
            if (!Array.isArray(items) || !items.length) return '<em>None</em>';
            return items.map(function(item){
                var reason = item && item.reason ? item.reason : (item && item.label ? item.label : 'signal');
                var evidence = item && item.evidence ? (' <span style="color:#6b7280;">(' + escapeHtml(typeof item.evidence === 'string' ? item.evidence : JSON.stringify(item.evidence)) + ')</span>') : '';
                return '<span class="pcm-advisor-chip">' + escapeHtml(reason) + evidence + '</span>';
            }).join('');
        }

        function formatTimingValue(value) {
            if (value === null || typeof value === 'undefined' || value === '') return null;
            var num = Number(value);
            if (!isFinite(num)) return null;
            return num;
        }

        function renderTimingGrid(timing) {
            timing = timing && typeof timing === 'object' ? timing : {};
            var total = formatTimingValue(timing.total_time || timing.total || (timing.total_ms ? Number(timing.total_ms) / 1000 : null));
            if (total === null || total <= 0) {
                return '<em>Timing unavailable.</em>';
            }
            return '<div class="pcm-timing-row"><span>Total</span><div class="pcm-timing-track"><span style="width:100%;"></span></div><strong>' + escapeHtml(total.toFixed(3) + 's') + '</strong></div>';
        }

        function severityBadge(severity) {
            var normalized = String(severity || 'info').toLowerCase();
            var map = { critical: 'is-critical', warning: 'is-warning', info: 'is-info' };
            var cls = map[normalized] || 'is-info';
            return '<span class="pcm-severity-badge ' + cls + '">' + escapeHtml(normalized) + '</span>';
        }

        function insightPanel(hasValues, body) {
            if (!hasValues) return '';
            return '<div class="pcm-insight-panel">' + body + '</div>';
        }

        function renderDiagnosis(payload) {
            var diagnosis = payload && payload.diagnosis ? payload.diagnosis : {};
            var data = diagnosis.diagnosis || diagnosis || {};
            var trace = data.decision_trace || {};
            var probe = data.probe || diagnosis.probe || {};
            var probeTiming = probe.timing || data.timing || diagnosis.timing || {};
            var contentLength = probe.headers && probe.headers['content-length'] ? probe.headers['content-length'] : null;
            if (Array.isArray(contentLength)) contentLength = contentLength[0];
            var responseSize = probe.response_size;
            if ((responseSize === null || typeof responseSize === 'undefined' || responseSize === '') && typeof probe.response_bytes !== 'undefined') {
                responseSize = probe.response_bytes;
            }
            if ((responseSize === null || typeof responseSize === 'undefined' || responseSize === '') && contentLength) {
                responseSize = contentLength;
            }
            var parsedResponseSize = Number(responseSize);
            var responseSizeLabel = isFinite(parsedResponseSize) && parsedResponseSize >= 0
                ? String(Math.round(parsedResponseSize))
                : String(responseSize || 0);
            var poisoning = Array.isArray(trace.poisoning_signals) ? trace.poisoning_signals : [];

            var topCookieSignals = poisoning.filter(function(p){ return p.type === 'cookie'; }).slice(0, 5);
            var topHeaderSignals = poisoning.filter(function(p){ return p.type === 'header'; }).slice(0, 5);

            diagnosisWrap.innerHTML = [
                '<div class="pcm-diagnosis-grid">',
                    '<div class="pcm-diagnosis-card"><dt>URL</dt><dd>' + escapeHtml(diagnosis.url || payload.url || '') + '</dd></div>',
                    '<div class="pcm-diagnosis-card"><dt>Final URL</dt><dd>' + escapeHtml(probe.effective_url || diagnosis.url || '') + '</dd></div>',
                    '<div class="pcm-diagnosis-card"><dt>Redirect chain</dt><dd>' + escapeHtml((probe.redirect_chain || []).join(' \u2192 ') || 'None') + '</dd></div>',
                    '<div class="pcm-diagnosis-card"><dt>Response size</dt><dd>' + escapeHtml(responseSizeLabel) + ' bytes</dd></div>',
                '</div>',
                '<div class="pcm-diagnosis-section"><strong>Why bypassed (Edge)</strong><div>' + chips(trace.edge_bypass_reasons || []) + '</div></div>',
                insightPanel(
                    (trace.edge_bypass_reasons || []).length > 0,
                    '<strong>What this means</strong>These response headers are preventing the edge CDN from caching this route. Every request hits your origin server, increasing load and latency.' +
                    '<ul>' +
                    '<li><code>cache_control_non_public</code> — A plugin or theme is sending <code>Cache-Control: private</code> or <code>no-store</code>. Check for caching/security plugins overriding headers on public pages.</li>' +
                    '<li><code>vary_cookie</code> — The <code>Vary: Cookie</code> header tells the edge every unique cookie creates a new cache variant, effectively disabling caching. Find the plugin adding this header and restrict it to authenticated pages only.</li>' +
                    '</ul>'
                ),
                '<div class="pcm-diagnosis-section"><strong>Why bypassed (Batcache)</strong><div>' + chips(trace.batcache_bypass_reasons || []) + '</div></div>',
                insightPanel(
                    (trace.batcache_bypass_reasons || []).length > 0,
                    '<strong>What this means</strong>Batcache (page cache) is skipping this route. The page is regenerated from PHP/MySQL on every anonymous hit.' +
                    '<ul>' +
                    '<li><code>vary_cookie</code> — A <code>Vary: Cookie</code> header is present. Batcache cannot serve cached pages when responses vary by cookie.</li>' +
                    '<li><code>set_cookie_present</code> — A <code>Set-Cookie</code> header is being sent on an anonymous request. Common sources: analytics plugins, consent banners, or session starters. Move cookie-setting to client-side JavaScript or restrict to authenticated users.</li>' +
                    '</ul>'
                ),
                '<div class="pcm-diagnosis-section"><strong>Poisoning cookies</strong><div>' + chips(topCookieSignals.map(function(p){ return { reason: p.key, evidence: p.evidence }; })) + '</div></div>',
                insightPanel(
                    topCookieSignals.length > 0,
                    '<strong>What this means</strong>These cookies are being set on anonymous responses. Each unique cookie value fragments the cache, creating &ldquo;cache poisoning&rdquo; &mdash; where the CDN stores many near-identical variants, lowering hit rates.' +
                    '<ul>' +
                    '<li>Identify the plugin or theme setting each cookie. If not essential for server-side logic (e.g., analytics tracking, A/B testing), move it to client-side JavaScript.</li>' +
                    '<li>If the cookie must be server-side, ensure it is only set on authenticated or POST requests.</li>' +
                    '</ul>'
                ),
                '<div class="pcm-diagnosis-section"><strong>Poisoning headers</strong><div>' + chips(topHeaderSignals.map(function(p){ return { reason: p.key, evidence: p.evidence }; })) + '</div></div>',
                insightPanel(
                    topHeaderSignals.length > 0,
                    '<strong>What this means</strong>These response headers create unnecessary cache variation or explicitly block caching.' +
                    '<ul>' +
                    '<li><code>vary</code> — Review what values the Vary header includes. <code>Vary: Cookie</code> on public pages is almost always wrong. <code>Vary: Accept-Encoding</code> is fine.</li>' +
                    '<li><code>cache-control</code> — Look for <code>no-store</code>, <code>private</code>, or <code>max-age=0</code> on pages that should be public.</li>' +
                    '<li><code>pragma</code> — Legacy header; <code>Pragma: no-cache</code> should be removed from public responses.</li>' +
                    '<li><code>x-forwarded-host</code> / <code>x-forwarded-proto</code> — These in Vary can cause origin-level cache fragmentation.</li>' +
                    '</ul>'
                ),
                '<div class="pcm-diagnosis-section"><strong>Route risk badges</strong><div>' + chips(trace.route_risk_labels || []) + '</div></div>',
                insightPanel(
                    (trace.route_risk_labels || []).length > 0,
                    '<strong>What this means</strong>These badges flag routes that may cause performance or reliability problems.' +
                    '<ul>' +
                    '<li><code>fragile</code> — Cacheability score is below 60 or bypass indicators detected. This route is vulnerable to traffic spikes. Review bypass reasons above and fix them.</li>' +
                    '<li><code>expensive</code> — Response time exceeded 1.2s. Consider query optimization, reducing plugin overhead, or enabling object caching for heavy database queries.</li>' +
                    '<li><code>cold</code> — The <code>x-cache</code> header indicates a cache miss. Normal after a purge, but if it persists, caching may be broken for this route.</li>' +
                    '</ul>'
                ),
                '<div class="pcm-diagnosis-section"><strong>Timing</strong><div class="pcm-timing-grid">' + renderTimingGrid(probeTiming) + '</div></div>'
            ].join('');
        }

        function loadRouteDiagnosis(runId, url, preferredResult) {
            if (!runId || !url) return Promise.resolve();
            var inlineResult = preferredResult || findResultByUrl(url);
            if (hasDiagnosisData(inlineResult)) {
                renderDiagnosis({
                    url: url,
                    diagnosis: inlineResult.diagnosis
                });
                return Promise.resolve();
            }
            window.pcmRenderSkeletonRows(diagnosisWrap, 8, ['40%', '90%', '82%', '88%', '76%', '92%', '85%', '67%']);
            return window.pcmPost({ action: 'pcm_cacheability_route_diagnosis', nonce: window.pcmGetCacheabilityNonce(), run_id: String(runId), url: url })
                .then(function(payload){
                    if (!payload || !payload.success || !payload.data) {
                        throw new Error(window.pcmPayloadErrorMessage(payload, 'Unable to load diagnosis endpoint.'));
                    }
                    if (payload.data.available === false) {
                        renderDiagnosisUnavailable(payload.data.message, url);
                        return;
                    }
                    renderDiagnosis(payload.data);
                })
                .catch(function(error){
                    if (error && error.status === 404) {
                        renderDiagnosisUnavailable('No route diagnosis is available for this URL yet. Run a fresh scan to populate diagnosis details.', url);
                        return;
                    }
                    showError(diagnosisWrap, 'reload-section', error);
                });
        }

        var escapeHtml = window.pcmEscapeHtml;

        function renderPlaybook(playbook, ruleId, progress) {
            if (!playbook || !playbook.meta || !playbook.meta.playbook_id) {
                playbookWrap.style.display = 'none';
                playbookWrap.innerHTML = '';
                return;
            }

            var checklist = (progress && progress.checklist) ? progress.checklist : {};
            var verification = (progress && progress.verification) ? progress.verification : {};
            var checkedOne = checklist.step_1 ? 'checked' : '';
            var checkedTwo = checklist.step_2 ? 'checked' : '';
            var checkedThree = checklist.verify ? 'checked' : '';
            var verificationSummary = verification.status ? (verification.status + ' (' + (verification.checked_at || 'n/a') + ')') : 'Not run yet';

            playbookWrap.style.display = 'block';
            playbookWrap.innerHTML = [
                '<div style="display:flex;justify-content:space-between;align-items:center;gap:10px;">',
                    '<h4 style="margin:0;">Playbook: ' + escapeHtml(playbook.meta.title || playbook.meta.playbook_id) + '</h4>',
                    '<button type="button" class="pcm-btn-text" data-action="close-playbook">Close</button>',
                '</div>',
                '<p style="margin:6px 0 8px;color:#4b5563;"><strong>Severity:</strong> ' + escapeHtml(playbook.meta.severity || 'warning') + '</p>',
                '<div class="pcm-playbook-body" style="font-size:13px;line-height:1.5;">' + (playbook.html_body || '') + '</div>',
                '<hr/>',
                '<div>',
                    '<label><input type="checkbox" data-check="step_1" ' + checkedOne + '> Step 1 complete</label><br>',
                    '<label><input type="checkbox" data-check="step_2" ' + checkedTwo + '> Step 2 complete</label><br>',
                    '<label><input type="checkbox" data-check="verify" ' + checkedThree + '> Verification complete</label>',
                '</div>',
                '<p style="margin-top:10px;display:flex;gap:8px;align-items:center;">',
                    '<button type="button" class="pcm-btn-primary" data-action="save-progress" data-playbook-id="' + escapeHtml(playbook.meta.playbook_id) + '">Save progress</button>',
                    '<button type="button" class="pcm-btn-secondary" data-action="verify" data-playbook-id="' + escapeHtml(playbook.meta.playbook_id) + '" data-rule-id="' + escapeHtml(ruleId) + '">Run post-fix verification</button>',
                    '<span data-role="verify-status" style="color:#374151;">Last verification: ' + escapeHtml(verificationSummary) + '</span>',
                '</p>'
            ].join('');
            playbookWrap.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }

        function renderFindings(findings) {
            if (!Array.isArray(findings) || !findings.length) {
                findingsWrap.innerHTML = '<em>No findings on latest run.</em>';
                playbookWrap.style.display = 'none';
                playbookWrap.innerHTML = '';
                return;
            }

            var grouped = {};
            findings.forEach(function(row){
                var rule = row.rule_id || 'unknown_rule';
                var sev = row.severity || 'warning';
                var key = rule + '|' + sev;
                if (!grouped[key]) {
                    grouped[key] = {
                        rule: rule,
                        severity: sev,
                        urls: [],
                        playbook: row.playbook_lookup || {}
                    };
                }
                if (row.url) {
                    grouped[key].urls.push(row.url);
                }
                if (!grouped[key].playbook.available && row.playbook_lookup && row.playbook_lookup.available) {
                    grouped[key].playbook = row.playbook_lookup;
                }
            });

            var html = '<div class="pcm-findings-list">';
            Object.keys(grouped).slice(0, 25).forEach(function(key){
                var group = grouped[key];
                var uniqueUrls = [];
                var seen = {};
                group.urls.forEach(function(url){
                    if (!seen[url]) {
                        seen[url] = true;
                        uniqueUrls.push(url);
                    }
                });

                html += '<div class="pcm-finding-item">';
                html += '<div style="display:flex;justify-content:space-between;gap:8px;align-items:flex-start;"><div><strong>' + escapeHtml(group.rule) + '</strong></div>' + severityBadge(group.severity) + '</div>';
                if (uniqueUrls.length) {
                    html += '<div class="pcm-finding-urls">';
                    uniqueUrls.forEach(function(url){
                        html += '<div><button type="button" class="pcm-btn-text" data-action="open-diagnosis" data-url="' + escapeHtml(url) + '" style="padding:0;font-size:12px;word-break:break-word;text-align:left;">' + escapeHtml(url) + '</button></div>';
                    });
                    html += '</div>';
                }
                if (group.playbook.available) {
                    html += '<button type="button" class="pcm-btn-text" data-action="open-playbook" data-rule-id="' + escapeHtml(group.rule) + '">Open playbook</button>';
                }
                html += '</div>';
            });
            html += '</div>';
            findingsWrap.innerHTML = html;
        }


        function renderSensitivity(payload) {
            var topRoutes = payload && payload.data && Array.isArray(payload.data.top_routes) ? payload.data.top_routes : [];
            var summary = payload && payload.data && payload.data.summary ? payload.data.summary : {};

            if (!topRoutes.length) {
                sensitivityWrap.innerHTML = '<em>No route sensitivity data yet.</em>';
                return;
            }

            var html = '';
            html += '<p style="margin:0 0 8px;color:#4b5563;">High-sensitivity routes: 24h=' + Number(summary.high_24h || 0) + ', 7d=' + Number(summary.high_7d || 0) + '</p>';
            html += renderCollapsibleSection('pcm-route-sensitivity-panel', 'route sensitivity', (function(){
                var sensitivityHtml = '<ul style="margin:0;padding-left:18px;">';
                topRoutes.forEach(function(row){
                    var metrics = row.metrics || {};
                    var reasons = Array.isArray(metrics.reasons) ? metrics.reasons.join(', ') : '';
                    sensitivityHtml += '<li><strong>' + escapeHtml(row.route || row.url || 'unknown') + '</strong> '
                        + '<span style="text-transform:uppercase;font-size:11px;border:1px solid #d1d5db;padding:1px 4px;border-radius:4px;">' + escapeHtml(row.memcache_sensitivity || 'low') + '</span>'
                        + ' — score ' + Number(metrics.score || 0)
                        + ', hit ' + (metrics.hit_ratio === null || typeof metrics.hit_ratio === 'undefined' ? 'n/a' : Number(metrics.hit_ratio).toFixed(2) + '%')
                        + ', evictions ' + (metrics.evictions === null || typeof metrics.evictions === 'undefined' ? 'n/a' : Number(metrics.evictions))
                        + (reasons ? '<br><span style="font-size:12px;color:#6b7280;">Signals: ' + escapeHtml(reasons) + '</span>' : '')
                        + '</li>';
                });
                sensitivityHtml += '</ul>';
                return sensitivityHtml;
            }()));
            sensitivityWrap.innerHTML = html;
        }

        function loadRunDetails(runId) {
            window.pcmRenderSkeletonRows(scoreWrap, 4, ['100%', '100%', '100%', '85%']);
            window.pcmRenderSkeletonRows(findingsWrap, 4, ['100%', '94%', '100%', '88%']);
            window.pcmRenderSkeletonRows(sensitivityWrap, 4, ['100%', '86%', '92%', '74%']);
            var resultsRequest = window.pcmPost({ action: 'pcm_cacheability_scan_results', nonce: window.pcmGetCacheabilityNonce(), run_id: String(runId) });
            var findingsRequest = window.pcmPost({ action: 'pcm_cacheability_scan_findings', nonce: window.pcmGetCacheabilityNonce(), run_id: String(runId) });
            var sensitivityRequest = window.pcmPost({ action: 'pcm_route_memcache_sensitivity', nonce: window.pcmGetCacheabilityNonce(), run_id: String(runId) }, { timeout: 30000 });

            return Promise.allSettled([resultsRequest, findingsRequest, sensitivityRequest]).then(function(settled){
                var resultsPayload = settled[0] && settled[0].status === 'fulfilled' ? settled[0].value : null;
                var findingsPayload = settled[1] && settled[1].status === 'fulfilled' ? settled[1].value : null;
                var sensitivityPayload = settled[2] && settled[2].status === 'fulfilled' ? settled[2].value : null;

                if (!resultsPayload || !resultsPayload.success) {
                    throw new Error(window.pcmPayloadErrorMessage(resultsPayload, 'Unable to load cacheability results endpoint.'));
                }

                currentRunResults = (resultsPayload && resultsPayload.success && resultsPayload.data && Array.isArray(resultsPayload.data.results)) ? resultsPayload.data.results : [];
                renderScores(currentRunResults);

                if (!findingsPayload || !findingsPayload.success) {
                    findingsWrap.innerHTML = '<em>Unable to load findings for the latest run.</em>';
                } else {
                    renderFindings(findingsPayload && findingsPayload.success ? findingsPayload.data.findings : []);
                }

                if (!sensitivityPayload || !sensitivityPayload.success) {
                    sensitivityWrap.innerHTML = '<em>Route sensitivity insights are unavailable for this run.</em>';
                } else {
                    renderSensitivity(sensitivityPayload);
                }

                var firstResult = currentRunResults[0] || null;
                if (firstResult && firstResult.url) {
                    return loadRouteDiagnosis(runId, firstResult.url, firstResult);
                }
                renderDiagnosisUnavailable('Run a scan to generate route diagnosis details.');
            });
        }

        function loadLatestRun() {
            return window.pcmPost({ action: 'pcm_cacheability_scan_status', nonce: window.pcmGetCacheabilityNonce() }).then(function(payload){
                if (!payload || !payload.success || !payload.data || !payload.data.run || !payload.data.run.id) {
                    runStatus.textContent = 'No scan runs found yet.';
                    renderScores([]);
                    renderFindings([]);
                    renderDiagnosisUnavailable('No scan runs found yet. Run a scan to generate route diagnosis details.');
                    return;
                }

                var run = payload.data.run;
                currentRunId = Number(run.id || 0);
                runStatus.textContent = 'Latest run #' + run.id + ' — ' + (run.status || 'unknown');
                return loadRunDetails(run.id);
            });
        }

        function processScanQueue(runId, total) {
            var remaining = total;
            var storageFailures = 0;
            function processNext() {
                return window.pcmPost({ action: 'pcm_cacheability_scan_process_next', nonce: window.pcmGetCacheabilityNonce(), run_id: runId })
                    .then(function(payload) {
                        if (!payload || !payload.success || !payload.data) {
                            throw new Error('Scan processing failed');
                        }
                        if (payload.data.stored === false) {
                            storageFailures++;
                        }
                        remaining = payload.data.remaining || 0;
                        var scanned = total - remaining;
                        runStatus.textContent = 'Scanning… ' + scanned + '/' + total + ' URLs processed.';
                        if (!payload.data.done) {
                            return processNext();
                        }
                        if (storageFailures > 0) {
                            return { storageFailures: storageFailures };
                        }
                    });
            }
            return processNext();
        }

        runBtn.addEventListener('click', function(){
            runBtn.disabled = true;
            runBtn.style.opacity = '0.6';
            runStatus.textContent = 'Starting scan…';
            window.pcmPost({ action: 'pcm_cacheability_scan_start', nonce: window.pcmGetCacheabilityNonce() })
                .then(function(payload){
                    if (!payload || !payload.success || !payload.data || !payload.data.run_id) {
                        throw new Error('Unable to start run');
                    }
                    var runId = payload.data.run_id;
                    var total = payload.data.remaining || 0;
                    if (total === 0) {
                        throw new Error('No URLs to scan. Check that your site has published content.');
                    }
                    runStatus.textContent = 'Scanning… 0/' + total + ' URLs processed.';
                    return processScanQueue(runId, total).then(function(queueResult) {
                        if (queueResult && queueResult.storageFailures > 0) {
                            runStatus.textContent = 'Scan completed for run #' + runId + ' with ' + queueResult.storageFailures + ' storage error(s). Check PHP error logs.';
                        } else {
                            runStatus.textContent = 'Scan completed for run #' + runId + '.';
                        }
                        return loadRunDetails(runId);
                    });
                })
                .catch(function(error){
                    showError(scoreWrap, 'reload-section', error);
                    runStatus.textContent = 'Scan failed.';
                })
                .finally(function(){
                    runBtn.disabled = false;
                    runBtn.style.opacity = '';
                });
        });

        findingsWrap.addEventListener('click', function(event){
            var diagnosisTrigger = event.target.closest('[data-action="open-diagnosis"]');
            if (diagnosisTrigger) {
                var diagnosisUrl = diagnosisTrigger.getAttribute('data-url') || '';
                if (diagnosisUrl) {
                    loadRouteDiagnosis(currentRunId, diagnosisUrl);
                }
                return;
            }

            var trigger = event.target.closest('[data-action="open-playbook"]');
            if (!trigger) return;
            var ruleId = trigger.getAttribute('data-rule-id') || '';
            if (!ruleId) return;

            window.pcmPost({ action: 'pcm_playbook_lookup', nonce: window.pcmGetCacheabilityNonce(), rule_id: ruleId })
                .then(function(payload){
                    if (!payload || !payload.success || !payload.data || !payload.data.available) {
                        throw new Error('Playbook unavailable');
                    }
                    renderPlaybook(payload.data.playbook, ruleId, payload.data.progress || {});
                })
                .catch(function(error){
                    showError(playbookWrap, 'reload-section', error);
                });
        });

        section.addEventListener('click', function(event){
            var toggle = event.target.closest('[data-action="toggle-panel"]');
            if (!toggle) return;
            var targetId = toggle.getAttribute('data-target') || '';
            var panel = targetId ? document.getElementById(targetId) : null;
            if (!panel) return;
            var isOpen = panel.style.display !== 'none';
            panel.style.display = isOpen ? 'none' : 'block';
            toggle.setAttribute('aria-expanded', isOpen ? 'false' : 'true');
            var labelText = (toggle.textContent || '').replace(/^\s*(Show|Hide)\s+/i, '');
            toggle.textContent = (isOpen ? 'Show ' : 'Hide ') + labelText;
        });

        section.addEventListener('click', function(event){
            var retry = event.target.closest('[data-action="pcm-retry"]');
            if (!retry) return;
            loadLatestRun().catch(function(error){ showError(scoreWrap, 'reload-section', error); });
        });

        playbookWrap.addEventListener('click', function(event){
            var trigger = event.target.closest('[data-action]');
            if (!trigger) return;
            var action = trigger.getAttribute('data-action');

            if (action === 'close-playbook') {
                playbookWrap.style.display = 'none';
                return;
            }

            if (action === 'save-progress') {
                var playbookId = trigger.getAttribute('data-playbook-id') || '';
                if (!playbookId) return;
                var checklist = {};
                playbookWrap.querySelectorAll('input[data-check]').forEach(function(box){
                    checklist[box.getAttribute('data-check')] = !!box.checked;
                });

                window.pcmPost({
                    action: 'pcm_playbook_progress_save',
                    nonce: window.pcmGetCacheabilityNonce(),
                    playbook_id: playbookId,
                    checklist: JSON.stringify(checklist)
                }).then(function(){
                    runStatus.textContent = 'Playbook progress saved.';
                }).catch(function(error){
                    window.pcmHandleError('Save Playbook Progress', error, runStatus);
                });
                return;
            }

            if (action === 'verify') {
                var pbId = trigger.getAttribute('data-playbook-id') || '';
                var ruleId = trigger.getAttribute('data-rule-id') || '';
                if (!pbId || !ruleId) return;

                var statusEl = playbookWrap.querySelector('[data-role="verify-status"]');
                if (statusEl) statusEl.textContent = 'Verification running…';

                window.pcmPost({
                    action: 'pcm_playbook_verify',
                    nonce: window.pcmGetCacheabilityNonce(),
                    playbook_id: pbId,
                    rule_id: ruleId
                }).then(function(payload){
                    if (!payload || !payload.success || !payload.data) {
                        throw new Error('Verification failed');
                    }
                    if (statusEl) {
                        statusEl.textContent = 'Last verification: ' + (payload.data.status || 'unknown') + ' (run #' + (payload.data.run_id || 'n/a') + ')';
                    }
                    runStatus.textContent = payload.data.message || 'Verification complete.';
                }).catch(function(error){
                    window.pcmHandleError('Run Verification', error, statusEl || runStatus);
                });
            }
        });

        scoreWrap.addEventListener('click', function(event){
            var trigger = event.target.closest('[data-action="open-diagnosis"]');
            if (!trigger) return;
            var url = trigger.getAttribute('data-url') || '';
            if (!url) return;
            loadRouteDiagnosis(currentRunId, url);
        });

        loadLatestRun().catch(function(error){
            showError(scoreWrap, 'reload-section', error);
            runStatus.textContent = 'Unable to load scan run.';
        });
    })();
});

window.pcmOnSectionReady('pcm-feature-cache-overview', function(){
(function(){
    if (typeof window.pcmGetCacheabilityNonce !== 'function' || !window.pcmGetCacheabilityNonce()) return;
    var escapeHtml = window.pcmEscapeHtml;
    var refreshBtn = document.getElementById('pcm-cache-overview-refresh');
    var statusEl   = document.getElementById('pcm-cache-overview-status');
    var cardsEl    = document.getElementById('pcm-cache-overview-cards');
    var trendEl    = document.getElementById('pcm-cache-overview-trend');
    var section    = document.getElementById('pcm-feature-cache-overview');
    if (!refreshBtn || !cardsEl || !trendEl || !section) return;

    function showError(targetEl, error) {
        window.pcmRenderDeepDiveDependencyError(targetEl, 'Cache Overview', 'reload-section', error);
    }

    function renderCard(label, value, statusClass) {
        return '<div class="pcm-cache-insight-card">' +
            '<div class="pcm-ci-label">' + escapeHtml(label) + '</div>' +
            '<div class="pcm-ci-value' + (statusClass ? ' ' + statusClass : '') + '">' + value + '</div>' +
        '</div>';
    }

    function statusIcon(ok) {
        return ok
            ? '<span class="dashicons dashicons-yes-alt" style="color:#16a34a;vertical-align:middle;margin-right:2px;" aria-hidden="true"></span>'
            : '<span class="dashicons dashicons-dismiss" style="color:#dc2626;vertical-align:middle;margin-right:2px;" aria-hidden="true"></span>';
    }

    /* ── SVG line chart (reused from former OCI handler) ── */
    function lineChartSvg(values, labels, threshold, opts) {
        opts = opts || {};
        var width = 520, height = 140;
        var pad = { top: 12, right: 12, bottom: 20, left: 28 };
        var valid = values.filter(function(v){ return v != null; });
        var maxVal = valid.length ? Math.max.apply(null, valid.concat([threshold || 0])) : 100;
        maxVal = Math.max(maxVal, opts.minMax || 100);
        var innerW = width - pad.left - pad.right;
        var innerH = height - pad.top - pad.bottom;
        var safeLen = Math.max(values.length - 1, 1);
        function xAt(i) { return pad.left + ((innerW * i) / safeLen); }
        function yAt(v) { return pad.top + innerH - ((Math.max(v, 0) / maxVal) * innerH); }

        var linePath = '', areaPath = '', started = false;
        values.forEach(function(v, i){
            if (v == null) {
                if (started) { areaPath += ' L ' + xAt(i - 1).toFixed(2) + ' ' + (pad.top + innerH).toFixed(2) + ' Z'; started = false; }
                return;
            }
            var x = xAt(i).toFixed(2), y = yAt(v).toFixed(2);
            if (!started) {
                linePath += (linePath ? ' M ' : 'M ') + x + ' ' + y;
                areaPath += (areaPath ? ' M ' : 'M ') + x + ' ' + (pad.top + innerH).toFixed(2) + ' L ' + x + ' ' + y;
                started = true;
            } else {
                linePath += ' L ' + x + ' ' + y;
                areaPath += ' L ' + x + ' ' + y;
            }
            if (i === values.length - 1) { areaPath += ' L ' + x + ' ' + (pad.top + innerH).toFixed(2) + ' Z'; }
        });

        var thresholdY = threshold != null ? yAt(threshold).toFixed(2) : null;
        var xTicks = labels.map(function(l, i){
            if (i === 0 || i === labels.length - 1 || i === Math.floor(labels.length / 2))
                return '<text x="' + xAt(i).toFixed(2) + '" y="' + (height - 4) + '" text-anchor="middle" fill="#64748b" font-size="10">' + l + '</text>';
            return '';
        }).join('');

        return [
            '<svg viewBox="0 0 ' + width + ' ' + height + '" preserveAspectRatio="xMidYMid meet" role="img" aria-label="' + (opts.label || 'Trend chart') + '">',
            '<line x1="' + pad.left + '" y1="' + (pad.top + innerH) + '" x2="' + (width - pad.right) + '" y2="' + (pad.top + innerH) + '" stroke="#e2e8f0" stroke-width="1"/>',
            thresholdY ? '<line x1="' + pad.left + '" y1="' + thresholdY + '" x2="' + (width - pad.right) + '" y2="' + thresholdY + '" stroke="#dd3a03" stroke-width="1" stroke-dasharray="4 4"/>' : '',
            areaPath ? '<path d="' + areaPath + '" fill="rgba(3,252,194,0.18)"/>' : '',
            linePath ? '<path d="' + linePath + '" fill="none" stroke="' + (opts.color || '#03fcc2') + '" stroke-width="2"/>' : '',
            xTicks,
            '</svg>'
        ].join('');
    }

    /* ── Browser-side Batcache probe ── */
    var ddBatcacheNonce = (window.pcmDeepDiveData && window.pcmDeepDiveData.nonces && window.pcmDeepDiveData.nonces.batcache) || '';
    var ddSiteUrl       = (window.pcmDeepDiveData && window.pcmDeepDiveData.siteUrl) || '/';
    var ddBatcacheMaxAge = (window.pcmDeepDiveData && window.pcmDeepDiveData.batcacheMaxAge) || null;

    function probeBatcacheFromBrowser() {
        if (!ddBatcacheNonce) return;

        fetch(ddSiteUrl, {
            method: 'GET',
            cache: 'reload',
            credentials: 'omit',
            redirect: 'follow',
            headers: { 'Pragma': 'no-cache' }
        })
        .then(function(resp) {
            var xNananana    = resp.headers.get('x-nananana') || '';
            var serverHdr    = resp.headers.get('server') || '';
            var isCloudflare = serverHdr.toLowerCase().indexOf('cloudflare') !== -1 ? '1' : '0';

            return window.pcmPost({
                action: 'pcm_report_batcache_header',
                nonce: ddBatcacheNonce,
                x_nananana: xNananana,
                is_cloudflare: isCloudflare
            });
        })
        .then(function(res) {
            if (!res || !res.success || !res.data) return;
            var status = res.data.status || 'unknown';
            var label  = status.charAt(0).toUpperCase() + status.slice(1);
            var cls    = status === 'active' ? 'pcm-ci-status-ok' : (status === 'broken' ? 'pcm-ci-status-bad' : 'pcm-ci-status-warn');

            if (ddBatcacheMaxAge) label += ' (TTL ' + ddBatcacheMaxAge + 's)';

            var now = new Date();
            var timeStr = ('0' + now.getUTCHours()).slice(-2) + ':' + ('0' + now.getUTCMinutes()).slice(-2);
            label += '<br><span style="font-size:10px;color:#6b7280;">Measured from browser headers. Last checked ' + timeStr + ' UTC.</span>';

            var bcCard = cardsEl.querySelector('.pcm-cache-insight-card:first-child');
            if (bcCard) {
                var valueEl = bcCard.querySelector('.pcm-ci-value');
                if (valueEl) {
                    valueEl.className = 'pcm-ci-value ' + cls;
                    valueEl.innerHTML = label;
                }
            }
        })
        .catch(function() { /* probe failed — keep loopback value */ });
    }

    /* ── Render status cards from Cache Insights data ── */
    function renderCards(d) {
        var cards = [];

        // Batcache — initial render from loopback; browser probe overwrites below
        var bcStatus = d.batcache_status || 'unknown';
        var bcLabel = bcStatus.charAt(0).toUpperCase() + bcStatus.slice(1);
        var bcClass = bcStatus === 'active' ? 'pcm-ci-status-ok' : (bcStatus === 'broken' ? 'pcm-ci-status-bad' : 'pcm-ci-status-warn');
        if (d.batcache_max_age) bcLabel += ' (TTL ' + d.batcache_max_age + 's)';
        cards.push(renderCard('Batcache', escapeHtml(bcLabel), bcClass));

        // Object Cache
        var ocType = d.object_cache_type || 'unknown';
        var ocClass = (ocType === 'Default (none)' || ocType === 'unknown') ? 'pcm-ci-status-warn' : 'pcm-ci-status-ok';
        cards.push(renderCard('Object Cache', escapeHtml(ocType), ocClass));

        // Hit Ratio (prefer OCI snapshot value, fall back to insights value)
        var hr = d._hit_ratio;
        if (typeof hr === 'number') {
            var hrClass = hr >= 80 ? 'pcm-ci-status-ok' : (hr >= 50 ? 'pcm-ci-status-warn' : 'pcm-ci-status-bad');
            cards.push(renderCard('Hit Ratio', hr + '%', hrClass));
        } else if (typeof d.object_cache_hit_ratio === 'number') {
            var hrClass2 = d.object_cache_hit_ratio >= 80 ? 'pcm-ci-status-ok' : (d.object_cache_hit_ratio >= 50 ? 'pcm-ci-status-warn' : 'pcm-ci-status-bad');
            cards.push(renderCard('Hit Ratio', d.object_cache_hit_ratio + '%', hrClass2));
        }

        // PHP OPcache — dashicons instead of emoji
        var opcacheOk = d.opcache_enabled;
        cards.push(renderCard(
            'PHP OPcache',
            statusIcon(opcacheOk) + (opcacheOk ? ' Enabled' : ' Disabled'),
            opcacheOk ? 'pcm-ci-status-ok' : 'pcm-ci-status-bad'
        ));

        cardsEl.innerHTML = cards.join('');
    }

    /* ── Render 7-day hit ratio trend chart ── */
    function renderTrend(points) {
        if (!Array.isArray(points) || !points.length) {
            trendEl.innerHTML = '<em>No trend data yet.</em>';
            return;
        }
        var rows = points.slice(-20);
        var labels = rows.map(function(p){ return (p.taken_at || '').slice(5, 10); });
        var hitValues = rows.map(function(p){ var v = Number(p.hit_ratio); return Number.isFinite(v) ? v : null; });

        trendEl.innerHTML =
            '<div class="pcm-trend-charts">' +
            '<div class="pcm-trend-chart"><h5>Hit Ratio % <span>7-day trend &middot; threshold 70%</span></h5>' +
            lineChartSvg(hitValues, labels, 70, { label: 'Object cache hit ratio trend', minMax: 100, color: '#03fcc2' }) +
            '</div></div>';
    }

    /* ── Data loading ── */
    var retryCount = 0, maxRetries = 2;

    function loadInsights() {
        return window.pcmPost({ action: 'pcm_cache_insights', nonce: window.pcmGetCacheabilityNonce() }, { timeout: 10000 });
    }

    function loadSnapshot(refresh) {
        return window.pcmPost({ action: 'pcm_object_cache_snapshot', nonce: window.pcmGetCacheabilityNonce(), refresh: refresh ? '1' : '0' }, { timeout: 15000 });
    }

    function loadTrends() {
        return window.pcmPost({ action: 'pcm_object_cache_trends', nonce: window.pcmGetCacheabilityNonce(), range: '7d' }, { timeout: 15000 });
    }

    function loadAll(refresh) {
        window.pcmRenderSkeletonRows(cardsEl, 4, ['48%', '48%', '48%', '48%']);
        window.pcmRenderSkeletonRows(trendEl, 3, ['100%', '96%', '98%']);

        return Promise.all([loadInsights(), loadSnapshot(refresh), loadTrends()])
            .then(function(results) {
                var insightsRes = results[0];
                var snapshotRes = results[1];
                var trendsRes   = results[2];

                // Build card data — merge insights + snapshot hit ratio
                var cardData = (insightsRes && insightsRes.success && insightsRes.data) ? insightsRes.data : {};
                if (snapshotRes && snapshotRes.success && snapshotRes.data) {
                    var snap = snapshotRes.data.snapshot;
                    if (snap && snap.hit_ratio != null) {
                        cardData._hit_ratio = Number(snap.hit_ratio);
                    }
                }
                renderCards(cardData);
                probeBatcacheFromBrowser();

                // Trend chart
                var trendPoints = (trendsRes && trendsRes.success && trendsRes.data) ? trendsRes.data.points : [];
                renderTrend(trendPoints);

                retryCount = 0;
            });
    }

    function loadWithRetry(refresh) {
        return loadAll(refresh).catch(function(error) {
            if (error && (error.isTimeout || error.message === 'timeout') && retryCount < maxRetries) {
                retryCount++;
                if (statusEl) statusEl.textContent = 'Retrying\u2026 (attempt ' + (retryCount + 1) + '/' + (maxRetries + 1) + ')';
                var delay = Math.pow(2, retryCount) * 1000;
                return new Promise(function(resolve){ setTimeout(resolve, delay); })
                    .then(function(){ return loadWithRetry(false); });
            }
            throw error;
        });
    }

    refreshBtn.addEventListener('click', function(){
        refreshBtn.disabled = true;
        refreshBtn.style.opacity = '0.6';
        if (statusEl) statusEl.textContent = 'Refreshing\u2026';
        retryCount = 0;
        loadWithRetry(true)
            .catch(function(error){ showError(cardsEl, error); })
            .finally(function(){ refreshBtn.disabled = false; refreshBtn.style.opacity = ''; if (statusEl) statusEl.textContent = ''; });
    });

    section.addEventListener('click', function(event){
        if (!event.target.closest('[data-action="pcm-retry"]')) return;
        retryCount = 0;
        loadWithRetry(false).catch(function(error){ showError(cardsEl, error); });
    });

    loadWithRetry(false).catch(function(error){
        showError(cardsEl, error);
    });
})();
});

(function(){
        var nav = document.getElementById('pcm-deep-dive-nav');
        if (!nav || !window.IntersectionObserver) return;
        var links = Array.prototype.slice.call(nav.querySelectorAll('a'));
        var map = {};
        links.forEach(function(link){
            var id = (link.getAttribute('href') || '').replace('#', '');
            var node = document.getElementById(id);
            if (node) map[id] = link;
        });
        var io = new IntersectionObserver(function(entries){
            entries.forEach(function(entry){
                var id = entry.target.getAttribute('id');
                if (!map[id]) return;
                if (entry.isIntersecting) {
                    links.forEach(function(a){ a.classList.remove('is-active'); });
                    map[id].classList.add('is-active');
                }
            });
        }, { rootMargin: '-25% 0px -60% 0px', threshold: 0.01 });
        Object.keys(map).forEach(function(id){ io.observe(document.getElementById(id)); });
    })();

