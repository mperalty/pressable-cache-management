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

    // Handle summary-card clicks that jump to a section
    var summaryGrid = document.querySelector('.pcm-summary-grid');
    if (summaryGrid) {
        summaryGrid.addEventListener('click', function(e) {
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

        function escapeHtml(input) {
            return String(input || '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

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
                detail = 'The request timed out. The server may be busy — please try again.';
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
        if (!runBtn || !runStatus || !scoreWrap || !findingsWrap || !sensitivityWrap || !diagnosisWrap || !playbookWrap || !section) return;

        function showError(targetEl, retryAction, error, fallbackMessage) {
            window.pcmRenderDeepDiveDependencyError(targetEl, 'Cacheability Advisor', retryAction, error, fallbackMessage);
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
                    routesHtml += '<li><span style="word-break:break-word;">' + escapeHtml(routeUrl) + '</span></li>';
                });
                routesHtml += '</ul>';
                return routesHtml;
            }());
            html += '</div>';
            scoreWrap.innerHTML = html;
        }

        function renderCollapsibleSection(id, label, contentHtml) {
            return '<div class="pcm-collapsible-panel">'
                + '<button type="button" class="pcm-btn-text" style="padding:0;height:auto;line-height:1.4;font-size:12px;color:#4b5563;" data-action="toggle-panel" data-target="' + escapeHtml(id) + '" aria-expanded="false">Show ' + escapeHtml(label) + '</button>'
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
            var entries = [
                { label: 'Total', key: 'total_time', value: formatTimingValue(timing.total_time || timing.total || (timing.total_ms ? Number(timing.total_ms) / 1000 : null)) },
                { label: 'DNS', key: 'namelookup_time', value: formatTimingValue(timing.namelookup_time || timing.dns || (timing.namelookup_ms ? Number(timing.namelookup_ms) / 1000 : null)) },
                { label: 'Connect', key: 'connect_time', value: formatTimingValue(timing.connect_time || timing.connect || (timing.connect_ms ? Number(timing.connect_ms) / 1000 : null)) },
                { label: 'TTFB', key: 'starttransfer_time', value: formatTimingValue(timing.starttransfer_time || timing.ttfb || (timing.starttransfer_ms ? Number(timing.starttransfer_ms) / 1000 : null)) }
            ];
            var max = entries.reduce(function(acc, item){ return item.value !== null ? Math.max(acc, item.value) : acc; }, 0);
            if (max <= 0) {
                return '<em>Timing unavailable.</em>';
            }
            return entries.map(function(item){
                var percent = item.value === null ? 0 : Math.round((item.value / max) * 100);
                var valText = item.value === null ? 'n/a' : item.value.toFixed(3) + 's';
                return '<div class="pcm-timing-row"><span>' + escapeHtml(item.label) + '</span><div class="pcm-timing-track"><span style="width:' + percent + '%;"></span></div><strong>' + escapeHtml(valText) + '</strong></div>';
            }).join('');
        }

        function severityBadge(severity) {
            var normalized = String(severity || 'info').toLowerCase();
            var map = { critical: 'is-critical', warning: 'is-warning', info: 'is-info' };
            var cls = map[normalized] || 'is-info';
            return '<span class="pcm-severity-badge ' + cls + '">' + escapeHtml(normalized) + '</span>';
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
                '<div class="pcm-diagnosis-section"><strong>Why bypassed (Batcache)</strong><div>' + chips(trace.batcache_bypass_reasons || []) + '</div></div>',
                '<div class="pcm-diagnosis-section"><strong>Poisoning cookies</strong><div>' + chips(topCookieSignals.map(function(p){ return { reason: p.key, evidence: p.evidence }; })) + '</div></div>',
                '<div class="pcm-diagnosis-section"><strong>Poisoning headers</strong><div>' + chips(topHeaderSignals.map(function(p){ return { reason: p.key, evidence: p.evidence }; })) + '</div></div>',
                '<div class="pcm-diagnosis-section"><strong>Route risk badges</strong><div>' + chips(trace.route_risk_labels || []) + '</div></div>',
                '<div class="pcm-diagnosis-section"><strong>Timing</strong><div class="pcm-timing-grid">' + renderTimingGrid(probeTiming) + '</div></div>'
            ].join('');
        }

        function loadRouteDiagnosis(runId, url) {
            if (!runId || !url) return Promise.resolve();
            window.pcmRenderSkeletonRows(diagnosisWrap, 8, ['40%', '90%', '82%', '88%', '76%', '92%', '85%', '67%']);
            return window.pcmPost({ action: 'pcm_cacheability_route_diagnosis', nonce: window.pcmGetCacheabilityNonce(), run_id: String(runId), url: url })
                .then(function(payload){
                    if (!payload || !payload.success || !payload.data) {
                        throw new Error(window.pcmPayloadErrorMessage(payload, 'Unable to load diagnosis endpoint.'));
                    }
                    renderDiagnosis(payload.data);
                })
                .catch(function(error){
                    showError(diagnosisWrap, 'reload-section', error);
                });
        }

        function escapeHtml(input) {
            return String(input || '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

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
                        html += '<div><span style="font-size:12px;word-break:break-word;">' + escapeHtml(url) + '</span></div>';
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
            var sensitivityRequest = window.pcmPost({ action: 'pcm_route_memcache_sensitivity', nonce: window.pcmGetCacheabilityNonce(), run_id: String(runId) });

            return Promise.allSettled([resultsRequest, findingsRequest, sensitivityRequest]).then(function(settled){
                var resultsPayload = settled[0] && settled[0].status === 'fulfilled' ? settled[0].value : null;
                var findingsPayload = settled[1] && settled[1].status === 'fulfilled' ? settled[1].value : null;
                var sensitivityPayload = settled[2] && settled[2].status === 'fulfilled' ? settled[2].value : null;

                if (!resultsPayload || !resultsPayload.success) {
                    throw new Error(window.pcmPayloadErrorMessage(resultsPayload, 'Unable to load cacheability results endpoint.'));
                }

                renderScores(resultsPayload && resultsPayload.success ? resultsPayload.data.results : []);

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

                var firstResult = (resultsPayload && resultsPayload.success && resultsPayload.data && Array.isArray(resultsPayload.data.results)) ? resultsPayload.data.results[0] : null;
                if (firstResult && firstResult.url) {
                    return loadRouteDiagnosis(runId, firstResult.url);
                }
            });
        }

        function loadLatestRun() {
            return window.pcmPost({ action: 'pcm_cacheability_scan_status', nonce: window.pcmGetCacheabilityNonce() }).then(function(payload){
                if (!payload || !payload.success || !payload.data || !payload.data.run || !payload.data.run.id) {
                    runStatus.textContent = 'No scan runs found yet.';
                    renderScores([]);
                    renderFindings([]);
                    return;
                }

                var run = payload.data.run;
                currentRunId = Number(run.id || 0);
                runStatus.textContent = 'Latest run #' + run.id + ' — ' + (run.status || 'unknown');
                return loadRunDetails(run.id);
            });
        }

        runBtn.addEventListener('click', function(){
            runBtn.disabled = true;
            runBtn.style.opacity = '0.6';
            runStatus.textContent = 'Running scan…';
            window.pcmPost({ action: 'pcm_cacheability_scan_start', nonce: window.pcmGetCacheabilityNonce() })
                .then(function(payload){
                    if (!payload || !payload.success || !payload.data || !payload.data.run_id) {
                        throw new Error('Unable to start run');
                    }
                    runStatus.textContent = 'Scan completed for run #' + payload.data.run_id + '.';
                    return loadRunDetails(payload.data.run_id);
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

window.pcmOnSectionReady('pcm-feature-object-cache-intelligence', function(){
(function(){
        if (typeof window.pcmGetCacheabilityNonce !== 'function' || !window.pcmGetCacheabilityNonce()) return;
        var refreshBtn = document.getElementById('pcm-oci-refresh-btn');
        var summaryEl = document.getElementById('pcm-oci-summary');
        var latestEl = document.getElementById('pcm-oci-latest');
        var trendEl = document.getElementById('pcm-oci-trends');
        var section = document.getElementById('pcm-feature-object-cache-intelligence');
        if (!refreshBtn || !summaryEl || !latestEl || !trendEl || !section) return;

        function showError(targetEl, error, fallbackMessage) {
            window.pcmRenderDeepDiveDependencyError(targetEl, 'Object Cache Intelligence', 'reload-section', error, fallbackMessage);
        }

        function renderLatest(snapshot) {
            if (!snapshot || !snapshot.taken_at) {
                latestEl.innerHTML = '<em>No snapshot data yet.</em>';
                summaryEl.textContent = 'No diagnostics snapshot available.';
                return;
            }

            summaryEl.textContent = 'Health: ' + (snapshot.health || 'unknown') + ' | Provider: ' + (snapshot.provider || 'n/a');
            var evictionsText = (snapshot.evictions == null ? 'n/a' : snapshot.evictions);
            var memoryText = (snapshot.memory_pressure == null ? 'n/a' : snapshot.memory_pressure + '%');
            var memoryNote = '';
            if (snapshot.memory_pressure === 0 && (!snapshot.bytes_limit || Number(snapshot.bytes_limit) <= 0)) {
                memoryNote = ' <span style="color:#6b7280;">(provider did not report memory limit bytes)</span>';
            }
            var evictionNote = snapshot.evictions == null
                ? ' <span style="color:#6b7280;">(provider did not report eviction counters)</span>'
                : '';
            var meta = snapshot.meta || {};
            var usedGb = (meta.used_gb == null ? 'n/a' : Number(meta.used_gb).toFixed(2) + ' GB');
            var limitGb = (meta.limit_gb == null ? 'n/a' : Number(meta.limit_gb).toFixed(2) + ' GB');
            var itemsNow = (meta.curr_items == null ? 'n/a' : Number(meta.curr_items));
            var itemsTotal = (meta.total_items == null ? 'n/a' : Number(meta.total_items));
            var connsNow = (meta.curr_connections == null ? 'n/a' : Number(meta.curr_connections));
            var uptime = (meta.uptime_human == null ? 'n/a' : String(meta.uptime_human));

            latestEl.innerHTML = [
                '<ul style="margin:0;padding-left:18px;">',
                '<li><strong>Status</strong>: ' + (snapshot.status || 'unknown') + '</li>',
                '<li><strong>Hit Ratio</strong>: ' + (snapshot.hit_ratio == null ? 'n/a' : snapshot.hit_ratio + '%') + '</li>',
                '<li><strong>Evictions</strong>: ' + evictionsText + evictionNote + '</li>',
                '<li><strong>Memory Pressure</strong>: ' + memoryText + memoryNote + '</li>',
                '<li><strong>Memory Used / Limit</strong>: ' + usedGb + ' / ' + limitGb + '</li>',
                '<li><strong>Current / Total Items</strong>: ' + itemsNow + ' / ' + itemsTotal + '</li>',
                '<li><strong>Current Connections</strong>: ' + connsNow + '</li>',
                '<li><strong>Node Uptime</strong>: ' + uptime + '</li>',
                '<li><strong>Captured</strong>: ' + snapshot.taken_at + '</li>',
                '</ul>'
            ].join('');
        }

        function toPoints(points, key) {
            return points.map(function(point){
                var value = Number(point[key]);
                return Number.isFinite(value) ? value : null;
            });
        }

        function lineChartSvg(values, labels, threshold, opts) {
            opts = opts || {};
            var width = 520;
            var height = 140;
            var pad = { top: 12, right: 12, bottom: 20, left: 28 };
            var valid = values.filter(function(value){ return value != null; });
            var maxVal = valid.length ? Math.max.apply(null, valid.concat([threshold || 0])) : 100;
            maxVal = Math.max(maxVal, opts.minMax || 100);
            var innerW = width - pad.left - pad.right;
            var innerH = height - pad.top - pad.bottom;
            var safeLength = Math.max(values.length - 1, 1);
            function xAt(index) { return pad.left + ((innerW * index) / safeLength); }
            function yAt(value) { return pad.top + innerH - ((Math.max(value, 0) / maxVal) * innerH); }

            var linePath = '';
            var areaPath = '';
            var started = false;
            values.forEach(function(value, index){
                if (value == null) {
                    if (started) {
                        areaPath += ' L ' + xAt(index - 1).toFixed(2) + ' ' + (pad.top + innerH).toFixed(2) + ' Z';
                        started = false;
                    }
                    return;
                }
                var x = xAt(index).toFixed(2);
                var y = yAt(value).toFixed(2);
                if (!started) {
                    linePath += (linePath ? ' M ' : 'M ') + x + ' ' + y;
                    areaPath += (areaPath ? ' M ' : 'M ') + x + ' ' + (pad.top + innerH).toFixed(2) + ' L ' + x + ' ' + y;
                    started = true;
                } else {
                    linePath += ' L ' + x + ' ' + y;
                    areaPath += ' L ' + x + ' ' + y;
                }
                if (index === values.length - 1) {
                    areaPath += ' L ' + x + ' ' + (pad.top + innerH).toFixed(2) + ' Z';
                }
            });

            var thresholdY = threshold != null ? yAt(threshold).toFixed(2) : null;
            var xTicks = labels.map(function(label, index){
                if (index === 0 || index === labels.length - 1 || index === Math.floor(labels.length / 2)) {
                    return '<text x="' + xAt(index).toFixed(2) + '" y="' + (height - 4) + '" text-anchor="middle" fill="#64748b" font-size="10">' + label + '</text>';
                }
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

        function renderTrends(points) {
            if (!Array.isArray(points) || !points.length) {
                trendEl.innerHTML = '<em>No trend points yet.</em>';
                return;
            }

            var rows = points.slice(-20);
            var labels = rows.map(function(point){ return (point.taken_at || '').slice(5, 10); });
            var hitValues = toPoints(rows, 'hit_ratio');
            var evictionValues = toPoints(rows, 'evictions');
            var memoryValues = toPoints(rows, 'memory_pressure');

            var html = '<div class="pcm-trend-charts">'
                + '<div class="pcm-trend-chart"><h5>Hit Ratio % <span>threshold 70%</span></h5>' + lineChartSvg(hitValues, labels, 70, { label: 'Object cache hit ratio trend', minMax: 100, color: '#03fcc2' }) + '</div>'
                + '<div class="pcm-trend-chart"><h5>Evictions <span>watch for spikes</span></h5>' + lineChartSvg(evictionValues, labels, null, { label: 'Object cache evictions trend', minMax: 10, color: '#dd3a03' }) + '</div>'
                + '<div class="pcm-trend-chart"><h5>Memory Pressure % <span>threshold 90%</span></h5>' + lineChartSvg(memoryValues, labels, 90, { label: 'Object cache memory pressure trend', minMax: 100, color: '#dd3a03' }) + '</div>'
                + '</div>';

            html += '<details class="pcm-trend-details"><summary>Details table</summary>';
            html += '<table class="widefat striped" style="max-width:100%;"><thead><tr><th>Date</th><th>Hit %</th><th>Evictions</th><th>Mem %</th></tr></thead><tbody>';
            rows.forEach(function(point){
                html += '<tr>'
                    + '<td>' + (point.taken_at || '') + '</td>'
                    + '<td>' + (point.hit_ratio == null ? 'n/a' : point.hit_ratio) + '</td>'
                    + '<td>' + (point.evictions == null ? 'n/a' : point.evictions) + '</td>'
                    + '<td>' + (point.memory_pressure == null ? 'n/a' : point.memory_pressure) + '</td>'
                    + '</tr>';
            });
            html += '</tbody></table></details>';
            trendEl.innerHTML = html;
        }

        function loadSnapshot(refresh) {
            window.pcmRenderSkeletonRows(latestEl, 5, ['84%', '91%', '78%', '72%', '86%']);
            return window.pcmPost({ action: 'pcm_object_cache_snapshot', nonce: window.pcmGetCacheabilityNonce(), refresh: refresh ? '1' : '0' })
                .then(function(payload){
                    if (!payload || !payload.success) {
                        throw new Error(window.pcmPayloadErrorMessage(payload, 'Unable to load object cache snapshot endpoint.'));
                    }
                    renderLatest(payload && payload.success ? payload.data.snapshot : null);
                });
        }

        function loadTrends() {
            window.pcmRenderSkeletonRows(trendEl, 4, ['100%', '96%', '98%', '92%']);
            return window.pcmPost({ action: 'pcm_object_cache_trends', nonce: window.pcmGetCacheabilityNonce(), range: '7d' })
                .then(function(payload){
                    if (!payload || !payload.success) {
                        throw new Error(window.pcmPayloadErrorMessage(payload, 'Unable to load object cache trends endpoint.'));
                    }
                    renderTrends(payload && payload.success ? payload.data.points : []);
                });
        }

        refreshBtn.addEventListener('click', function(){
            refreshBtn.disabled = true;
            refreshBtn.style.opacity = '0.6';
            summaryEl.textContent = 'Refreshing…';
            Promise.all([loadSnapshot(true), loadTrends()])
                .catch(function(error){ showError(latestEl, error); })
                .finally(function(){ refreshBtn.disabled = false; refreshBtn.style.opacity = ''; });
        });

        section.addEventListener('click', function(event){
            if (!event.target.closest('[data-action="pcm-retry"]')) return;
            Promise.all([loadSnapshot(false), loadTrends()]).catch(function(error){ showError(latestEl, error); });
        });

        Promise.all([loadSnapshot(false), loadTrends()]).catch(function(error){
            showError(latestEl, error);
        });
    })();
});

window.pcmOnSectionReady('pcm-feature-opcache-awareness', function(){
(function(){
        if (typeof window.pcmGetCacheabilityNonce !== 'function' || !window.pcmGetCacheabilityNonce()) return;
        var refreshBtn = document.getElementById('pcm-opcache-refresh-btn');
        var summaryEl = document.getElementById('pcm-opcache-summary');
        var latestEl = document.getElementById('pcm-opcache-latest');
        var trendEl = document.getElementById('pcm-opcache-trends');
        var section = document.getElementById('pcm-feature-opcache-awareness');
        if (!refreshBtn || !summaryEl || !latestEl || !trendEl || !section) return;

        function showError(targetEl, error, fallbackMessage) {
            window.pcmRenderDeepDiveDependencyError(targetEl, 'OPcache Awareness', 'reload-section', error, fallbackMessage);
        }

        function renderLatest(snapshot) {
            if (!snapshot || !snapshot.taken_at) {
                latestEl.innerHTML = '<em>No OPcache snapshot data yet.</em>';
                summaryEl.textContent = 'No OPcache diagnostics available.';
                return false;
            }

            if (!snapshot.enabled) {
                summaryEl.textContent = 'Health: ' + (snapshot.health || 'unknown') + ' | Enabled: no';
                latestEl.innerHTML = '<em>OPcache is disabled on this runtime. Snapshot details and trend history are hidden until OPcache is enabled.</em>';
                trendEl.innerHTML = '<em>OPcache is disabled, so trend history is unavailable.</em>';
                return false;
            }

            var mem = snapshot.memory || {};
            var stats = snapshot.statistics || {};

            summaryEl.textContent = 'Health: ' + (snapshot.health || 'unknown') + ' | Enabled: yes';
            latestEl.innerHTML = [
                '<ul style="margin:0;padding-left:18px;">',
                '<li><strong>Health</strong>: ' + (snapshot.health || 'unknown') + '</li>',
                '<li><strong>Hit Rate</strong>: ' + (stats.opcache_hit_rate == null ? 'n/a' : stats.opcache_hit_rate + '%') + '</li>',
                '<li><strong>Memory Pressure</strong>: ' + ((Number(mem.used_memory || 0) + Number(mem.wasted_memory || 0) + Number(mem.free_memory || 0)) > 0 ? Math.round(((Number(mem.used_memory || 0) + Number(mem.wasted_memory || 0)) / (Number(mem.used_memory || 0) + Number(mem.wasted_memory || 0) + Number(mem.free_memory || 0))) * 10000) / 100 : 0) + '%</li>',
                '<li><strong>Restart Total</strong>: ' + (stats.restart_total == null ? 'n/a' : stats.restart_total) + '</li>',
                '<li><strong>Captured</strong>: ' + snapshot.taken_at + '</li>',
                '</ul>'
            ].join('');
            return true;
        }

        function toPoints(points, key) {
            return points.map(function(point){
                var value = Number(point[key]);
                return Number.isFinite(value) ? value : null;
            });
        }

        function lineChartSvg(values, labels, threshold, opts) {
            opts = opts || {};
            var width = 520;
            var height = 140;
            var pad = { top: 12, right: 12, bottom: 20, left: 28 };
            var valid = values.filter(function(value){ return value != null; });
            var maxVal = valid.length ? Math.max.apply(null, valid.concat([threshold || 0])) : 100;
            maxVal = Math.max(maxVal, opts.minMax || 100);
            var innerW = width - pad.left - pad.right;
            var innerH = height - pad.top - pad.bottom;
            var safeLength = Math.max(values.length - 1, 1);
            function xAt(index) { return pad.left + ((innerW * index) / safeLength); }
            function yAt(value) { return pad.top + innerH - ((Math.max(value, 0) / maxVal) * innerH); }

            var linePath = '';
            var areaPath = '';
            var started = false;
            values.forEach(function(value, index){
                if (value == null) {
                    if (started) {
                        areaPath += ' L ' + xAt(index - 1).toFixed(2) + ' ' + (pad.top + innerH).toFixed(2) + ' Z';
                        started = false;
                    }
                    return;
                }
                var x = xAt(index).toFixed(2);
                var y = yAt(value).toFixed(2);
                if (!started) {
                    linePath += (linePath ? ' M ' : 'M ') + x + ' ' + y;
                    areaPath += (areaPath ? ' M ' : 'M ') + x + ' ' + (pad.top + innerH).toFixed(2) + ' L ' + x + ' ' + y;
                    started = true;
                } else {
                    linePath += ' L ' + x + ' ' + y;
                    areaPath += ' L ' + x + ' ' + y;
                }
                if (index === values.length - 1) {
                    areaPath += ' L ' + x + ' ' + (pad.top + innerH).toFixed(2) + ' Z';
                }
            });

            var thresholdY = threshold != null ? yAt(threshold).toFixed(2) : null;
            var xTicks = labels.map(function(label, index){
                if (index === 0 || index === labels.length - 1 || index === Math.floor(labels.length / 2)) {
                    return '<text x="' + xAt(index).toFixed(2) + '" y="' + (height - 4) + '" text-anchor="middle" fill="#64748b" font-size="10">' + label + '</text>';
                }
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

        function renderTrends(points) {
            if (!Array.isArray(points) || !points.length) {
                trendEl.innerHTML = '<em>No OPcache trend points yet.</em>';
                return;
            }

            var rows = points.slice(-20);
            var labels = rows.map(function(point){ return (point.taken_at || '').slice(5, 10); });
            var hitValues = toPoints(rows, 'hit_rate');
            var restartValues = toPoints(rows, 'restart_total');
            var memoryValues = toPoints(rows, 'memory_pressure');

            var html = '<div class="pcm-trend-charts">'
                + '<div class="pcm-trend-chart"><h5>Hit Rate % <span>threshold 70%</span></h5>' + lineChartSvg(hitValues, labels, 70, { label: 'OPcache hit rate trend', minMax: 100, color: '#03fcc2' }) + '</div>'
                + '<div class="pcm-trend-chart"><h5>Restarts <span>watch for spikes</span></h5>' + lineChartSvg(restartValues, labels, null, { label: 'OPcache restart trend', minMax: 5, color: '#dd3a03' }) + '</div>'
                + '<div class="pcm-trend-chart"><h5>Memory Pressure % <span>threshold 90%</span></h5>' + lineChartSvg(memoryValues, labels, 90, { label: 'OPcache memory pressure trend', minMax: 100, color: '#dd3a03' }) + '</div>'
                + '</div>';

            html += '<details class="pcm-trend-details"><summary>Details table</summary>';
            html += '<table class="widefat striped" style="max-width:100%;"><thead><tr><th>Date</th><th>Mem %</th><th>Restarts</th><th>Hit %</th><th>Health</th></tr></thead><tbody>';
            rows.forEach(function(point){
                html += '<tr>'
                    + '<td>' + (point.taken_at || '') + '</td>'
                    + '<td>' + (point.memory_pressure == null ? 'n/a' : point.memory_pressure) + '</td>'
                    + '<td>' + (point.restart_total == null ? 'n/a' : point.restart_total) + '</td>'
                    + '<td>' + (point.hit_rate == null ? 'n/a' : point.hit_rate) + '</td>'
                    + '<td>' + (point.health || 'unknown') + '</td>'
                    + '</tr>';
            });
            html += '</tbody></table></details>';
            trendEl.innerHTML = html;
        }

        function loadSnapshot(refresh) {
            window.pcmRenderSkeletonRows(latestEl, 5, ['90%', '84%', '92%', '88%', '79%']);
            return window.pcmPost({ action: 'pcm_opcache_snapshot', nonce: window.pcmGetCacheabilityNonce(), refresh: refresh ? '1' : '0' })
                .then(function(payload){
                    if (!payload || !payload.success) {
                        throw new Error(window.pcmPayloadErrorMessage(payload, 'Unable to load OPcache snapshot endpoint.'));
                    }
                    return renderLatest(payload && payload.success ? payload.data.snapshot : null);
                });
        }

        function loadTrends() {
            window.pcmRenderSkeletonRows(trendEl, 4, ['100%', '94%', '99%', '90%']);
            return window.pcmPost({ action: 'pcm_opcache_trends', nonce: window.pcmGetCacheabilityNonce(), range: '7d' })
                .then(function(payload){
                    if (!payload || !payload.success) {
                        throw new Error(window.pcmPayloadErrorMessage(payload, 'Unable to load OPcache trends endpoint.'));
                    }
                    renderTrends(payload && payload.success ? payload.data.points : []);
                });
        }

        refreshBtn.addEventListener('click', function(){
            refreshBtn.disabled = true;
            refreshBtn.style.opacity = '0.6';
            summaryEl.textContent = 'Refreshing OPcache…';
            loadSnapshot(true)
                .then(function(enabled){
                    if (enabled) {
                        return loadTrends();
                    }
                    return null;
                })
                .catch(function(error){ showError(latestEl, error); })
                .finally(function(){ refreshBtn.disabled = false; refreshBtn.style.opacity = ''; });
        });

        section.addEventListener('click', function(event){
            if (!event.target.closest('[data-action="pcm-retry"]')) return;
            loadSnapshot(false)
                .then(function(enabled){ return enabled ? loadTrends() : null; })
                .catch(function(error){ showError(latestEl, error); });
        });

        loadSnapshot(false)
            .then(function(enabled){
                if (enabled) {
                    return loadTrends();
                }
                return null;
            })
            .catch(function(error){
                showError(latestEl, error);
            });
    })();
});

window.pcmOnSectionReady('pcm-feature-redirect-assistant', function(){
(function(){
        if (typeof window.pcmGetCacheabilityNonce !== 'function' || !window.pcmGetCacheabilityNonce()) return;
        var out = document.getElementById('pcm-ra-output');
        var rulesBox = document.getElementById('pcm-ra-rules-json');
        var exportBox = document.getElementById('pcm-ra-export-content');
        var rulesBody = document.getElementById('pcm-ra-rules-body');
        var ruleErrors = document.getElementById('pcm-ra-rule-errors');
        var toggleAdvancedBtn = document.getElementById('pcm-ra-toggle-advanced');
        var section = document.getElementById('pcm-feature-redirect-assistant');
        var advancedVisible = false;
        var ruleState = [];
        if (!out || !rulesBox || !exportBox || !rulesBody || !ruleErrors || !toggleAdvancedBtn || !section) return;

        function requireSuccess(res, fallbackMessage) {
            if (!res || !res.success) {
                throw new Error(window.pcmPayloadErrorMessage(res, fallbackMessage));
            }
            return res;
        }

        function showError(error, fallbackMessage) {
            window.pcmRenderDeepDiveDependencyError(out, 'Redirect Assistant', 'reload-section', error, fallbackMessage);
        }

        function escapeHtml(value) {
            var str = String(value == null ? '' : value);
            return str.replace(/[&<>"']/g, function(char){
                return {
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#039;'
                }[char] || char;
            });
        }

        function defaultRule() {
            return {
                id: 'ui_' + Date.now() + '_' + Math.random().toString(36).slice(2, 7),
                source_pattern: '',
                target_pattern: '',
                match_type: 'exact',
                status_code: 301,
                enabled: true
            };
        }

        function normalizeRule(rule) {
            var matchType = rule && typeof rule.match_type === 'string' ? rule.match_type : 'exact';
            if (matchType === 'prefix') {
                matchType = 'wildcard';
            }
            if (['exact', 'wildcard', 'regex'].indexOf(matchType) === -1) {
                matchType = 'exact';
            }
            var statusCode = parseInt(rule && rule.status_code, 10);
            if ([301, 302, 307].indexOf(statusCode) === -1) {
                statusCode = 301;
            }
            return {
                id: rule && rule.id ? String(rule.id) : 'ui_' + Date.now() + '_' + Math.random().toString(36).slice(2, 7),
                source_pattern: (rule && rule.source_pattern ? String(rule.source_pattern) : '').trim(),
                target_pattern: rule && rule.target_pattern ? String(rule.target_pattern) : '',
                match_type: matchType,
                status_code: statusCode,
                enabled: !(rule && rule.enabled === false)
            };
        }

        function convertRuleForJson(rule) {
            return {
                id: rule.id,
                enabled: !!rule.enabled,
                match_type: rule.match_type === 'wildcard' ? 'prefix' : rule.match_type,
                source_pattern: rule.source_pattern,
                target_pattern: rule.target_pattern,
                status_code: parseInt(rule.status_code, 10) || 301
            };
        }

        function parseRulesFromJson(jsonRaw) {
            var parsed;
            try {
                parsed = JSON.parse(jsonRaw || '[]');
            } catch (e) {
                return { rules: [], parseError: 'Invalid JSON in advanced editor.' };
            }
            if (!Array.isArray(parsed)) {
                return { rules: [], parseError: 'Rules JSON must be an array.' };
            }
            return { rules: parsed.map(normalizeRule), parseError: '' };
        }

        function validateRuleState() {
            var errors = [];
            var seen = {};
            var duplicates = {};

            ruleState.forEach(function(rule){
                var key = rule.source_pattern.trim().toLowerCase();
                if (!key) {
                    return;
                }
                if (seen[key]) {
                    duplicates[key] = true;
                }
                seen[key] = true;
            });

            ruleState.forEach(function(rule){
                var issues = [];
                var key = rule.source_pattern.trim().toLowerCase();

                if (!rule.source_pattern.trim()) {
                    issues.push('Source is required.');
                }
                if (key && duplicates[key]) {
                    issues.push('Duplicate source pattern.');
                }
                if (rule.match_type === 'regex' && rule.source_pattern.trim()) {
                    try {
                        new RegExp(rule.source_pattern);
                    } catch (e) {
                        issues.push('Invalid regex pattern.');
                    }
                }

                if (issues.length) {
                    errors.push({ id: rule.id, messages: issues });
                }
            });

            return errors;
        }

        function syncJsonFromState() {
            rulesBox.value = JSON.stringify(ruleState.map(convertRuleForJson), null, 2);
        }

        function renderRules() {
            var errorMap = {};
            var validationErrors = validateRuleState();
            validationErrors.forEach(function(entry){ errorMap[entry.id] = entry.messages; });

            rulesBody.innerHTML = ruleState.map(function(rule){
                var invalid = (errorMap[rule.id] || []).length > 0;
                var invalidStyle = invalid ? 'border:1px solid #dc2626;background:#fef2f2;' : 'border:1px solid #cbd5e1;';
                return '<tr data-rule-id="' + escapeHtml(rule.id) + '">' +
                    '<td style="padding:6px;vertical-align:top;"><input data-field="source_pattern" type="text" value="' + escapeHtml(rule.source_pattern) + '" style="width:160px;' + invalidStyle + '"></td>' +
                    '<td style="padding:6px;vertical-align:top;"><input data-field="target_pattern" type="text" value="' + escapeHtml(rule.target_pattern) + '" style="width:180px;border:1px solid #cbd5e1;"></td>' +
                    '<td style="padding:6px;vertical-align:top;"><select data-field="match_type" style="width:95px;border:1px solid #cbd5e1;">' +
                        '<option value="exact"' + (rule.match_type === 'exact' ? ' selected' : '') + '>exact</option>' +
                        '<option value="wildcard"' + (rule.match_type === 'wildcard' ? ' selected' : '') + '>wildcard</option>' +
                        '<option value="regex"' + (rule.match_type === 'regex' ? ' selected' : '') + '>regex</option>' +
                    '</select></td>' +
                    '<td style="padding:6px;vertical-align:top;"><select data-field="status_code" style="width:80px;border:1px solid #cbd5e1;">' +
                        '<option value="301"' + (parseInt(rule.status_code, 10) === 301 ? ' selected' : '') + '>301</option>' +
                        '<option value="302"' + (parseInt(rule.status_code, 10) === 302 ? ' selected' : '') + '>302</option>' +
                        '<option value="307"' + (parseInt(rule.status_code, 10) === 307 ? ' selected' : '') + '>307</option>' +
                    '</select></td>' +
                    '<td style="padding:6px;vertical-align:top;"><input data-field="enabled" type="checkbox"' + (rule.enabled ? ' checked' : '') + '></td>' +
                    '<td style="padding:6px;vertical-align:top;"><button type="button" class="button-link-delete" data-delete-rule="1">Delete</button></td>' +
                '</tr>';
            }).join('');

            if (!ruleState.length) {
                rulesBody.innerHTML = '<tr><td colspan="6" style="padding:8px;color:#64748b;">No rules yet. Click Add Rule.</td></tr>';
            }

            ruleErrors.innerHTML = validationErrors.map(function(item){
                return '• ' + escapeHtml(item.messages.join(' '));
            }).join('<br>');

            syncJsonFromState();
        }

        function setRulesFromJson(raw, fallbackDefault) {
            var parsed = parseRulesFromJson(raw);
            if (parsed.parseError) {
                ruleErrors.textContent = parsed.parseError;
                if (fallbackDefault && !ruleState.length) {
                    ruleState = [defaultRule()];
                    renderRules();
                }
                return;
            }
            ruleState = parsed.rules.length ? parsed.rules : (fallbackDefault ? [defaultRule()] : []);
            renderRules();
        }

        function getPathname(url) {
            try {
                return new URL(url, window.location.origin).pathname || '/';
            } catch (e) {
                return String(url || '/');
            }
        }

        function findMatchedRule(inputUrl) {
            var path = getPathname(inputUrl);
            for (var i = 0; i < ruleState.length; i++) {
                var rule = ruleState[i];
                if (!rule.enabled) {
                    continue;
                }
                if (rule.match_type === 'exact' && path === rule.source_pattern) {
                    return rule;
                }
                if (rule.match_type === 'wildcard' && path.indexOf(rule.source_pattern) === 0) {
                    return rule;
                }
                if (rule.match_type === 'regex') {
                    try {
                        if ((new RegExp(rule.source_pattern)).test(path)) {
                            return rule;
                        }
                    } catch (e) {}
                }
            }
            return null;
        }

        function renderDryRunTable(res) {
            var results = (res && res.data && Array.isArray(res.data.results)) ? res.data.results : [];
            if (!results.length) {
                out.innerHTML = '<em>No dry-run results.</em>';
                return;
            }

            var html = '<table style="width:100%;border-collapse:collapse;font-size:12px;">' +
                '<thead><tr>' +
                '<th style="text-align:left;padding:6px;border-bottom:1px solid #e2e8f0;">Request URL</th>' +
                '<th style="text-align:left;padding:6px;border-bottom:1px solid #e2e8f0;">Matched Rule</th>' +
                '<th style="text-align:left;padding:6px;border-bottom:1px solid #e2e8f0;">Redirect Target</th>' +
                '<th style="text-align:left;padding:6px;border-bottom:1px solid #e2e8f0;">Status Code</th>' +
                '</tr></thead><tbody>';

            results.forEach(function(item){
                var matchedRule = findMatchedRule(item.input_url || '');
                html += '<tr>' +
                    '<td style="padding:6px;border-bottom:1px solid #f1f5f9;">' + escapeHtml(item.input_url || '') + '</td>' +
                    '<td style="padding:6px;border-bottom:1px solid #f1f5f9;">' + escapeHtml(matchedRule ? matchedRule.source_pattern : 'No match') + '</td>' +
                    '<td style="padding:6px;border-bottom:1px solid #f1f5f9;">' + escapeHtml(item.result_url || (matchedRule ? matchedRule.target_pattern : '')) + '</td>' +
                    '<td style="padding:6px;border-bottom:1px solid #f1f5f9;">' + escapeHtml(matchedRule ? String(matchedRule.status_code || 301) : '-') + '</td>' +
                '</tr>';
            });
            html += '</tbody></table>';
            out.innerHTML = html;
        }

        function render(obj) {
            out.textContent = JSON.stringify(obj || {}, null, 2);
        }

        toggleAdvancedBtn.addEventListener('click', function(){
            advancedVisible = !advancedVisible;
            rulesBox.style.display = advancedVisible ? 'block' : 'none';
            toggleAdvancedBtn.textContent = advancedVisible ? 'Hide Advanced JSON' : 'Show Advanced JSON';
            if (!advancedVisible) {
                setRulesFromJson(rulesBox.value, true);
            }
        });

        document.getElementById('pcm-ra-add-rule').addEventListener('click', function(){
            ruleState.push(defaultRule());
            renderRules();
        });

        rulesBody.addEventListener('click', function(event){
            var row = event.target.closest('tr[data-rule-id]');
            if (!row || !event.target.closest('[data-delete-rule="1"]')) {
                return;
            }
            var id = row.getAttribute('data-rule-id');
            ruleState = ruleState.filter(function(rule){ return rule.id !== id; });
            renderRules();
        });

        rulesBody.addEventListener('input', function(event){
            var row = event.target.closest('tr[data-rule-id]');
            var field = event.target.getAttribute('data-field');
            if (!row || !field) {
                return;
            }
            var id = row.getAttribute('data-rule-id');
            var currentRule = ruleState.find(function(rule){ return rule.id === id; });
            if (!currentRule) {
                return;
            }
            currentRule[field] = field === 'enabled' ? !!event.target.checked : event.target.value;
            if (field === 'status_code') {
                currentRule[field] = parseInt(event.target.value, 10) || 301;
            }
            renderRules();
        });

        rulesBox.addEventListener('input', function(){
            if (advancedVisible) {
                setRulesFromJson(rulesBox.value, false);
            }
        });

        document.getElementById('pcm-ra-discover').addEventListener('click', function(){
            window.pcmPost({ action: 'pcm_redirect_assistant_discover_candidates', nonce: window.pcmGetCacheabilityNonce(), urls: document.getElementById('pcm-ra-urls').value })
                .then(function(res){
                    requireSuccess(res, 'Unable to load redirect discovery endpoint.');
                    if (res && res.success && res.data && Array.isArray(res.data.candidates)) {
                        rulesBox.value = JSON.stringify(res.data.candidates, null, 2);
                        setRulesFromJson(rulesBox.value, true);
                    }
                    render(res);
                })
                .catch(function(error){ showError(error); });
        });

        document.getElementById('pcm-ra-load-rules').addEventListener('click', function(){
            window.pcmPost({ action: 'pcm_redirect_assistant_list_rules', nonce: window.pcmGetCacheabilityNonce() })
                .then(function(res){
                    requireSuccess(res, 'Unable to load redirect rules endpoint.');
                    if (res && res.success && res.data) {
                        rulesBox.value = JSON.stringify(res.data.rules || [], null, 2);
                        setRulesFromJson(rulesBox.value, true);
                    }
                    render(res);
                })
                .catch(function(error){ showError(error); });
        });

        document.getElementById('pcm-ra-save').addEventListener('click', function(){
            if (validateRuleState().length) {
                ruleErrors.textContent = 'Fix validation errors before saving.';
                return;
            }
            syncJsonFromState();
            window.pcmPost({ action: 'pcm_redirect_assistant_save_rules', nonce: window.pcmGetCacheabilityNonce(), rules: rulesBox.value, confirm_wildcards: document.getElementById('pcm-ra-confirm-wildcards').checked ? '1' : '0' })
                .then(function(res){ render(requireSuccess(res, 'Unable to save redirect rules endpoint.')); })
                .catch(function(error){ showError(error); });
        });

        document.getElementById('pcm-ra-simulate').addEventListener('click', function(){
            syncJsonFromState();
            window.pcmPost({ action: 'pcm_redirect_assistant_simulate', nonce: window.pcmGetCacheabilityNonce(), urls: document.getElementById('pcm-ra-sim-urls').value, rules: rulesBox.value })
                .then(function(res){
                    requireSuccess(res, 'Unable to run redirect simulation endpoint.');
                    renderDryRunTable(res);
                })
                .catch(function(error){ showError(error); });
        });

        document.getElementById('pcm-ra-export').addEventListener('click', function(){
            syncJsonFromState();
            window.pcmPost({ action: 'pcm_redirect_assistant_export', nonce: window.pcmGetCacheabilityNonce(), confirm_wildcards: document.getElementById('pcm-ra-confirm-wildcards').checked ? '1' : '0' })
                .then(function(res){
                    requireSuccess(res, 'Unable to export redirect rules endpoint.');
                    if (res && res.success && res.data && res.data.export) {
                        var content = (res.data.export.content || "") + "\n\n/* JSON PAYLOAD FOR IMPORT */\n" + (res.data.meta_json || "");
                        exportBox.value = content;
                    }
                    render(res);
                })
                .catch(function(error){ showError(error); });
        });

        document.getElementById('pcm-ra-copy').addEventListener('click', function(){
            var txt = exportBox.value || '';
            navigator.clipboard.writeText(txt).then(function(){ render({ copied: true }); }).catch(function(){ render({ copied: false }); });
        });

        document.getElementById('pcm-ra-download').addEventListener('click', function(){
            var content = exportBox.value || '';
            var idx = content.indexOf('/* JSON PAYLOAD FOR IMPORT */');
            if (idx > -1) {
                content = content.substring(0, idx).trim() + "\n";
            }
            var blob = new Blob([content], {type: 'text/x-php'});
            var a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            a.download = 'custom-redirects.php';
            document.body.appendChild(a);
            a.click();
            a.remove();
        });

        document.getElementById('pcm-ra-import').addEventListener('click', function(){
            var raw = exportBox.value || '';
            var marker = '/* JSON PAYLOAD FOR IMPORT */';
            var payload = raw.indexOf(marker) > -1 ? raw.substring(raw.indexOf(marker) + marker.length).trim() : raw.trim();
            window.pcmPost({ action: 'pcm_redirect_assistant_import', nonce: window.pcmGetCacheabilityNonce(), payload: payload })
                .then(function(res){
                    requireSuccess(res, 'Unable to import redirect rules endpoint.');
                    render(res);
                    if (res && res.success) {
                        return window.pcmPost({ action: 'pcm_redirect_assistant_list_rules', nonce: window.pcmGetCacheabilityNonce() });
                    }
                })
                .then(function(res){
                    if (res) requireSuccess(res, 'Unable to refresh redirect rules endpoint.');
                    if (res && res.success && res.data) {
                        rulesBox.value = JSON.stringify(res.data.rules || [], null, 2);
                        setRulesFromJson(rulesBox.value, true);
                    }
                })
                .catch(function(error){ showError(error); });
        });

        section.addEventListener('click', function(event){
            if (!event.target.closest('[data-action="pcm-retry"]')) return;
            document.getElementById('pcm-ra-load-rules').click();
        });

        setRulesFromJson(rulesBox.value, true);
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

(function(){
        if (typeof window.pcmGetCacheabilityNonce !== 'function' || !window.pcmGetCacheabilityNonce()) return;
        var out = document.getElementById('pcm-report-output');
        var rangeEl = document.getElementById('pcm-report-range');
        var section = document.getElementById('pcm-feature-observability-reporting');
        if (!out || !rangeEl || !section) return;

        function requireSuccess(res, fallbackMessage) {
            if (!res || !res.success) {
                throw new Error(window.pcmPayloadErrorMessage(res, fallbackMessage));
            }
            return res;
        }

        function showError(error, fallbackMessage) {
            window.pcmRenderDeepDiveDependencyError(out, 'Observability Reporting', 'reload-section', error, fallbackMessage);
        }


        function metricName(key){
            var map = {
                cacheability_score: 'Cacheability Score',
                cache_buster_incidence: 'Cache Buster Incidence',
                purge_frequency_by_scope: 'Purge Frequency (By Scope)',
                object_cache_hit_ratio: 'Object Cache Hit Ratio',
                object_cache_evictions: 'Object Cache Evictions',
                opcache_memory_pressure: 'OPcache Memory Pressure',
                opcache_restarts: 'OPcache Restarts',
                batcache_hits: 'Batcache Hits',
                high_memcache_sensitivity_routes_24h: 'High Memcache Sensitivity Routes (24h)',
                high_memcache_sensitivity_routes_7d: 'High Memcache Sensitivity Routes (7d)'
            };
            if (map[key]) {
                return map[key];
            }

            return (key || '').replace(/_/g, ' ').replace(/\b\w/g, function(char){ return char.toUpperCase(); });
        }

        function formatValue(value){
            var num = Number(value);
            if (!Number.isFinite(num)) {
                return '—';
            }

            return num.toLocaleString(undefined, { maximumFractionDigits: 2 });
        }

        function buildSummaryRows(rows){
            var byMetric = {};

            rows.forEach(function(row){
                var metric = row && row.metric_key ? row.metric_key : '';
                if (!metric) {
                    return;
                }

                if (!byMetric[metric]) {
                    byMetric[metric] = [];
                }

                byMetric[metric].push({
                    value: Number(row.value),
                    bucketStart: row.bucket_start || ''
                });
            });

            return Object.keys(byMetric).map(function(metric){
                var entries = byMetric[metric].filter(function(entry){ return Number.isFinite(entry.value); });

                entries.sort(function(a, b){
                    return new Date(a.bucketStart).getTime() - new Date(b.bucketStart).getTime();
                });

                var first = entries.length ? entries[0].value : NaN;
                var latest = entries.length ? entries[entries.length - 1].value : NaN;
                var delta = Number.isFinite(first) && Number.isFinite(latest) ? latest - first : NaN;

                return {
                    metric: metric,
                    currentValue: latest,
                    delta: delta
                };
            }).sort(function(a, b){
                return metricName(a.metric).localeCompare(metricName(b.metric));
            });
        }

        function render(obj){
            var rows = (((obj||{}).data||{}).rows)||[];
            if (!rows.length) { out.innerHTML = '<em>No trend rows available.</em>'; return; }
            var summaryRows = buildSummaryRows(rows);

            if (!summaryRows.length) {
                out.innerHTML = '<em>No trend rows available.</em>';
                return;
            }

            var html = '<table class="widefat striped"><thead><tr><th>Metric</th><th>Current Value</th><th>7-day Trend</th></tr></thead><tbody>';
            summaryRows.forEach(function(row){
                var hasDelta = Number.isFinite(row.delta);
                var arrowClass = hasDelta ? (row.delta >= 0 ? 'dashicons-arrow-up-alt' : 'dashicons-arrow-down-alt') : 'dashicons-arrow-right-alt';
                var arrow = '<span class="dashicons ' + arrowClass + ' pcm-trend-arrow" aria-hidden="true"></span>';
                var deltaText = hasDelta ? Math.abs(row.delta).toLocaleString(undefined, { maximumFractionDigits: 2 }) : 'n/a';
                html += '<tr><td>' + metricName(row.metric) + '</td><td>' + formatValue(row.currentValue) + '</td><td>' + arrow + ' ' + deltaText + '</td></tr>';
            });
            html += '</tbody></table>';
            out.innerHTML = html;
        }

        function downloadText(filename, text, mime){
            var blob = new Blob([text], { type: mime || 'text/plain' });
            var a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            a.download = filename;
            document.body.appendChild(a);
            a.click();
            a.remove();
        }

        document.getElementById('pcm-report-load').addEventListener('click', function(){
            window.pcmRenderSkeletonRows(out, 4, ['100%', '95%', '100%', '88%']);
            window.pcmPost({
                action: 'pcm_reporting_trends',
                nonce: window.pcmGetCacheabilityNonce(),
                range: rangeEl.value,
                metric_keys: ['cacheability_score','cache_buster_incidence','object_cache_hit_ratio','object_cache_evictions','opcache_memory_pressure','opcache_restarts','purge_frequency_by_scope','high_memcache_sensitivity_routes_24h','high_memcache_sensitivity_routes_7d']
            }).then(function(res){ render(requireSuccess(res, 'Unable to load reporting trends endpoint.')); }).catch(function(error){ showError(error); });
        });

        function doExport(format){
            window.pcmPost({
                action: 'pcm_reporting_export',
                nonce: window.pcmGetCacheabilityNonce(),
                format: format,
                range: rangeEl.value,
                metric_keys: ['cacheability_score','cache_buster_incidence','object_cache_hit_ratio','object_cache_evictions','opcache_memory_pressure','opcache_restarts','purge_frequency_by_scope','high_memcache_sensitivity_routes_24h','high_memcache_sensitivity_routes_7d']
            }).then(function(res){
                requireSuccess(res, 'Unable to export reporting endpoint.');
                if (res && res.success && res.data && res.data.content) {
                    var ext = format === 'csv' ? 'csv' : 'json';
                    var mime = format === 'csv' ? 'text/csv' : 'application/json';
                    var fname = 'pcm-report-' + rangeEl.value + '.' + ext;
                    downloadText(fname, res.data.content, mime);
                    out.innerHTML = '<div class="pcm-inline-success" style="font-size:13px;"><span class="dashicons dashicons-yes-alt" aria-hidden="true" style="color:#00a32a;font-size:16px;vertical-align:middle;margin-right:2px;"></span> Downloaded ' + fname + '</div>';
                }
            }).catch(function(error){ showError(error); });
        }

        document.getElementById('pcm-report-export-json').addEventListener('click', function(){ doExport('json'); });
        document.getElementById('pcm-report-export-csv').addEventListener('click', function(){ doExport('csv'); });

        section.addEventListener('click', function(event){
            if (!event.target.closest('[data-action="pcm-retry"]')) return;
            document.getElementById('pcm-report-load').click();
        });

        document.getElementById('pcm-report-load').click();
    })();
