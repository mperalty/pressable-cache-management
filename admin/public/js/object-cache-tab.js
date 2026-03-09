/**
 * Pressable Cache Management - Object Cache tab
 *
 * Public API (on window):
 *   window.pcmRefreshBatcacheStatus() - Manual Batcache status refresh (used by PHP onclick)
 */
(function(window, document) {
    'use strict';

    var pcmBatcacheNonce = window.pcmObjectCacheData && window.pcmObjectCacheData.nonces ? window.pcmObjectCacheData.nonces.batcache : '';
        var pcmSiteUrl       = (window.pcmObjectCacheData && window.pcmObjectCacheData.siteUrl) || '/';

        // WHY BROWSER-SIDE FETCH:
        // wp_remote_get() is a server-side loopback. Pressable routes loopbacks
        // directly to PHP, bypassing the Batcache/CDN layer, so x-nananana is
        // never returned regardless of real cache state.
        // The browser is the only client that sees the actual CDN response headers.
        // We fetch the homepage from JS, read x-nananana directly, then POST the
        // result to PHP which stores it in the transient.

        // Apply AJAX response to the badge DOM
        function pcmApplyStatus(res) {
            if (!res || !res.success) return null;
            var badge = document.getElementById('pcm-bc-badge');
            var label = document.getElementById('pcm-bc-label');
            if (!badge || !label) return null;
            label.textContent = res.data.label;
            // Remove retry button and inline error if present from a previous error state
            var oldRetry = badge.querySelector('.pcm-bc-retry');
            if (oldRetry) oldRetry.remove();
            var oldError = badge.querySelector('.pcm-inline-error');
            if (oldError) oldError.remove();
            ['active','broken','cloudflare','checking','unknown'].forEach(function(cls) {
                badge.classList.remove(cls);
            });
            badge.classList.add(res.data.status === 'active' ? 'active' : 'broken');
            var announce = document.getElementById('pcm-bc-status-announce');
            if (announce) {
                announce.textContent = res.data.label;
            }
            return res.data.status;
        }

        // Transition badge to error/unknown state when probe fails
        // errorType: 'timeout' | 'server' | 'network' (default)
        function pcmApplyErrorState(errorType) {
            var badge = document.getElementById('pcm-bc-badge');
            var label = document.getElementById('pcm-bc-label');
            if (!badge || !label) return;

            var errorText;
            if (errorType === 'timeout') {
                errorText = 'The request took too long.';
            } else if (errorType === 'server') {
                errorText = 'Server error. Check PHP error logs.';
            } else {
                errorText = (window.pcmObjectCacheData && window.pcmObjectCacheData.strings && window.pcmObjectCacheData.strings.checkFailed)
                    ? window.pcmObjectCacheData.strings.checkFailed
                    : 'Check Failed';
            }

            label.textContent = errorText;
            ['active','broken','cloudflare','checking'].forEach(function(cls) {
                badge.classList.remove(cls);
            });
            badge.classList.add('unknown');

            // Show an inline error container with a clickable "Try again" link
            var errorContainer = badge.querySelector('.pcm-inline-error');
            if (!errorContainer) {
                errorContainer = document.createElement('div');
                errorContainer.className = 'pcm-inline-error';
                errorContainer.setAttribute('role', 'alert');
                errorContainer.setAttribute('aria-live', 'assertive');
                badge.appendChild(errorContainer);
            }
            var retryLink = '<a href="#" class="pcm-bc-retry-link">Try again</a>';
            errorContainer.innerHTML = errorText + ' ' + retryLink;
            var link = errorContainer.querySelector('.pcm-bc-retry-link');
            if (link) {
                link.addEventListener('click', function(e) {
                    e.preventDefault();
                    errorContainer.remove();
                    pcmRefreshBatcacheStatus();
                });
            }

            // Also keep the Retry button for consistency
            if (!badge.querySelector('.pcm-bc-retry')) {
                var retryBtn = document.createElement('button');
                retryBtn.type = 'button';
                retryBtn.className = 'pcm-bc-retry';
                retryBtn.textContent = 'Retry';
                retryBtn.title = 'Retry Batcache status check';
                retryBtn.addEventListener('click', function() {
                    var oldError = badge.querySelector('.pcm-inline-error');
                    if (oldError) oldError.remove();
                    pcmRefreshBatcacheStatus();
                });
                badge.appendChild(retryBtn);
            }
            var announce = document.getElementById('pcm-bc-status-announce');
            if (announce) {
                announce.textContent = errorText;
            }
        }

        var pcmProbeInProgress = false;

        function pcmSetProbeBusyState(isBusy) {
            var badge = document.getElementById('pcm-bc-badge');
            var btn = document.getElementById('pcm-bc-refresh');
            if (badge) {
                badge.classList.toggle('checking', !!isBusy);
            }
            if (!btn) return;
            btn.disabled = !!isBusy;
            btn.style.opacity = isBusy ? '0.3' : '0.6';
        }

        // Core: browser fetches homepage, reads header, reports to PHP.
        // cache:'reload' bypasses browser cache for a fresh CDN response.
        // Pragma: no-cache forces Pressable's Atomic Edge Cache to BYPASS (x-ac: BYPASS).
        var pcmProbeTimeoutId = null;
        var PCM_PROBE_TIMEOUT_MS = 15000; // 15-second safety net

        function pcmProbeAndReport(onDone) {
            if (pcmProbeInProgress) {
                if (typeof onDone === 'function') onDone('busy');
                return;
            }
            pcmProbeInProgress = true;
            pcmSetProbeBusyState(true);

            var probeCompleted = false;

            // Timeout fallback: if probe hasn't resolved in 15s, show error state
            pcmProbeTimeoutId = setTimeout(function() {
                if (!probeCompleted) {
                    probeCompleted = true;
                    pcmProbeInProgress = false;
                    pcmSetProbeBusyState(false);
                    pcmApplyErrorState('timeout');
                    if (typeof onDone === 'function') onDone(null);
                }
            }, PCM_PROBE_TIMEOUT_MS);

            fetch(pcmSiteUrl, {
                method: 'GET',
                cache: 'reload',
                credentials: 'omit',
                redirect: 'follow',
                headers: { 'Pragma': 'no-cache' },
            })
            .then(function(resp) {
                var xNananana    = resp.headers.get('x-nananana') || '';
                var serverHdr    = resp.headers.get('server') || '';
                var cacheControl = resp.headers.get('cache-control') || '';
                var age          = resp.headers.get('age') || '';
                var isCloudflare = serverHdr.toLowerCase().indexOf('cloudflare') !== -1 ? '1' : '0';

                // Parse max-age and display as human readable
                var ttlHuman = '—';
                var maxAgeMatch = cacheControl.match(/max-age=(\d+)/i);
                if (maxAgeMatch) {
                    ttlHuman = pcmSecondsToHuman(parseInt(maxAgeMatch[1]));
                }
                var ttlEl = document.getElementById('pcm-ttl-value');
                if (ttlEl && ttlHuman !== '—') ttlEl.textContent = ttlHuman;

                var body = 'action=pcm_report_batcache_header'
                         + '&nonce='         + encodeURIComponent(pcmBatcacheNonce)
                         + '&x_nananana='    + encodeURIComponent(xNananana)
                         + '&is_cloudflare=' + isCloudflare;
                return fetch(ajaxurl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body,
                });
            })
            .then(function(r) {
                if (!r.ok) {
                    var httpErr = new Error('http_' + r.status);
                    httpErr.status = r.status;
                    throw httpErr;
                }
                return r.json();
            })
            .then(function(res) {
                if (probeCompleted) return; // timeout already fired
                probeCompleted = true;
                clearTimeout(pcmProbeTimeoutId);
                var status = pcmApplyStatus(res);
                if (typeof onDone === 'function') onDone(status);
            })
            .catch(function(err) {
                // Immediately show error state on CORS / network / parse errors
                if (probeCompleted) return; // timeout already fired
                probeCompleted = true;
                clearTimeout(pcmProbeTimeoutId);
                var errType = 'network';
                if (err && err.status >= 500) {
                    errType = 'server';
                } else if (err && (err.name === 'AbortError' || err.isTimeout)) {
                    errType = 'timeout';
                }
                pcmApplyErrorState(errType);
                if (typeof onDone === 'function') onDone(null);
            })
            .finally(function(){
                pcmProbeInProgress = false;
                pcmSetProbeBusyState(false);
            });
        }

        function pcmSecondsToHuman(s) {
            s = parseInt(s);
            if (s <= 0) return '0 sec';
            if (s < 60) return s + ' sec';
            if (s < 3600) {
                var m = Math.floor(s / 60), sec = s % 60;
                return sec > 0 ? m + ' min ' + sec + ' sec' : m + ' min';
            }
            if (s < 86400) {
                var h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60);
                return m > 0 ? h + ' hr ' + m + ' min' : h + ' hr';
            }
            var d = Math.floor(s / 86400), h = Math.floor((s % 86400) / 3600);
            return h > 0 ? d + ' day' + (d !== 1 ? 's' : '') + ' ' + h + ' hr' : d + ' day' + (d !== 1 ? 's' : '');
        }

        // Manual refresh button
        function pcmRefreshBatcacheStatus() {
            var badge = document.getElementById('pcm-bc-badge');
            var label = document.getElementById('pcm-bc-label');
            if (pcmProbeInProgress) {
                label.textContent = (window.pcmObjectCacheData && window.pcmObjectCacheData.strings ? window.pcmObjectCacheData.strings.alreadyChecking : 'Already checking…');
                return;
            }
            // Clear error state before re-probing
            if (badge) {
                badge.classList.remove('unknown');
                var oldRetry = badge.querySelector('.pcm-bc-retry');
                if (oldRetry) oldRetry.remove();
                var oldError = badge.querySelector('.pcm-inline-error');
                if (oldError) oldError.remove();
            }
            label.textContent = (window.pcmObjectCacheData && window.pcmObjectCacheData.strings ? window.pcmObjectCacheData.strings.checking : 'Checking…');
            pcmProbeAndReport();
        }

        // Expose for PHP onclick="pcmRefreshBatcacheStatus()"
        window.pcmRefreshBatcacheStatus = pcmRefreshBatcacheStatus;

        // Auto-poll: re-probe every 60s while status is broken (up to 5 attempts max)
        var pcmPollTimer = null, pcmPollCount = 0, pcmPollMax = 5;

        function pcmStopRecoveryPoll() {
            if (pcmPollTimer) {
                clearInterval(pcmPollTimer);
                pcmPollTimer = null;
            }
            pcmPollCount = 0;
        }

        function pcmStartRecoveryPoll() {
            pcmStopRecoveryPoll();
            pcmPollTimer = setInterval(function() {
                pcmPollCount++;
                if (pcmPollCount > pcmPollMax) { pcmStopRecoveryPoll(); return; }
                pcmProbeAndReport(function(status) {
                    if (status === 'active') {
                        pcmStopRecoveryPoll();
                    }
                });
            }, 60000);
        }

        // --- Cleanup: clear interval on tab switch, visibility change, and page unload ---

        // 1. Tab navigation: stop polling when user clicks a different tab
        var tabNav = document.getElementById('pcm-main-tab-nav');
        if (tabNav) {
            tabNav.addEventListener('click', function(e) {
                var link = e.target.closest('.nav-tab');
                if (!link) return;
                // If the clicked tab is not the Object Cache tab, stop polling
                var href = link.getAttribute('href') || '';
                var isObjectCacheTab = href.indexOf('tab=object_cache') !== -1
                    || href.indexOf('tab=') === -1; // default tab is object cache
                // Also check if the link is already active (no-op)
                if (!isObjectCacheTab) {
                    pcmStopRecoveryPoll();
                }
            });
        }

        // 2. Visibility change: pause when page is hidden, resume when visible
        var pcmPollWasRunning = false;
        var pcmPollCountBeforePause = 0;
        document.addEventListener('visibilitychange', function() {
            if (document.hidden) {
                // Pause: remember if poll was running and current count, then stop it
                pcmPollWasRunning = !!pcmPollTimer;
                pcmPollCountBeforePause = pcmPollCount;
                if (pcmPollTimer) {
                    clearInterval(pcmPollTimer);
                    pcmPollTimer = null;
                }
            } else {
                // Resume: only restart if it was running before and we haven't exhausted attempts
                if (pcmPollWasRunning && pcmPollCountBeforePause < pcmPollMax) {
                    pcmPollCount = pcmPollCountBeforePause; // restore count
                    pcmPollTimer = setInterval(function() {
                        pcmPollCount++;
                        if (pcmPollCount > pcmPollMax) { pcmStopRecoveryPoll(); return; }
                        pcmProbeAndReport(function(status) {
                            if (status === 'active') {
                                pcmStopRecoveryPoll();
                            }
                        });
                    }, 60000);
                }
                pcmPollWasRunning = false;
            }
        });

        // 3. Page unload: final cleanup to prevent orphaned timers
        window.addEventListener('beforeunload', function() {
            pcmStopRecoveryPoll();
            if (pcmProbeTimeoutId) {
                clearTimeout(pcmProbeTimeoutId);
                pcmProbeTimeoutId = null;
            }
        });

        // Always fire one silent probe on page load to verify stored status.
        // If transient expired (unknown) → show Checking… then update to real result.
        // If stored active → silently confirms or corrects without waiting 24 hrs.
        // If stored broken → re-probes immediately, starts recovery poll if still broken.
        if (window.pcmObjectCacheData && window.pcmObjectCacheData.isUnknown && document.getElementById('pcm-bc-label')) {
            document.getElementById('pcm-bc-label').textContent = (window.pcmObjectCacheData.strings ? window.pcmObjectCacheData.strings.checking : 'Checking…');
        }
        pcmProbeAndReport(function(status) {
            if (status !== 'active') pcmStartRecoveryPoll();
        });
                // Tooltip show/hide
        (function() {
            var wrap = document.querySelector('.pcm-bc-tooltip-wrap');
            if (!wrap) return;
            var tip = wrap.querySelector('.pcm-bc-tooltip');
            wrap.addEventListener('mouseenter', function() { tip.style.display = 'block'; });
            wrap.addEventListener('mouseleave', function() { tip.style.display = 'none'; });
            var trigger = wrap.querySelector('.pcm-bc-tooltip-trigger');
            if (trigger) {
                trigger.addEventListener('focus', function() { tip.style.display = 'block'; });
                trigger.addEventListener('blur', function() { tip.style.display = 'none'; });
                trigger.addEventListener('keydown', function(e) {
                    if (e.key === 'Escape') { tip.style.display = 'none'; trigger.blur(); }
                });
            }
        })();

(function(){
        var wrap   = document.getElementById('pcm-chips-wrap');
        var input  = document.getElementById('pcm-exempt-input');
        var hidden = document.getElementById('pcm-exempt-hidden');
        if (!wrap || !input || !hidden) return;

        var pasteTimer = null;
        var dupeTimer  = null;

        function getVals(){ return hidden.value ? hidden.value.split(',').map(function(s){ return s.trim(); }).filter(Boolean) : []; }
        function syncHidden(v){ hidden.value = v.join(', '); }

        // Normalize: auto-prefix with / if missing
        function normalize(val){
            val = val.trim();
            if (!val) return '';
            if (val.charAt(0) !== '/') val = '/' + val;
            return val;
        }

        // Show brief inline "Already excluded" message
        function showDuplicateMsg(){
            var msg = document.getElementById('pcm-exempt-dupe-msg');
            if (!msg) return;
            msg.textContent = 'Already excluded';
            msg.style.display = 'inline';
            if (dupeTimer) clearTimeout(dupeTimer);
            dupeTimer = setTimeout(function(){ msg.style.display = 'none'; msg.textContent = ''; }, 1500);
        }

        // Update "Clear all" link visibility
        function updateClearAll(){
            var link = document.getElementById('pcm-chips-clear-all');
            if (!link) return;
            var count = wrap.querySelectorAll('.pcm-chip').length;
            link.style.display = count >= 2 ? 'inline' : 'none';
        }

        function addChip(val){
            val = normalize(val);
            if (!val) return; // reject empty/whitespace
            var vals = getVals();
            if (vals.indexOf(val) !== -1) { showDuplicateMsg(); return; }
            vals.push(val); syncHidden(vals); renderChip(val);
            updateClearAll();
        }

        function removeChipAnimated(chipEl, val){
            chipEl.classList.add('pcm-chip-removing');
            syncHidden(getVals().filter(function(v){ return v !== val; }));
            chipEl.addEventListener('animationend', function(){
                chipEl.remove();
                updateClearAll();
            });
            // Fallback in case animationend doesn't fire
            setTimeout(function(){ if (chipEl.parentNode) { chipEl.remove(); updateClearAll(); } }, 350);
        }

        function renderChip(val){
            var c = document.createElement('span');
            c.className = 'pcm-chip pcm-chip-added'; c.dataset.value = val;
            c.innerHTML = val + ' <button type="button" class="pcm-chip-remove" title="Remove">&#xD7;</button>';
            c.querySelector('.pcm-chip-remove').addEventListener('click', function(){ removeChipAnimated(c, val); });
            wrap.appendChild(c);
            // Remove the flash class after animation completes
            setTimeout(function(){ c.classList.remove('pcm-chip-added'); }, 500);
        }

        // Bind existing server-rendered chip remove buttons
        wrap.querySelectorAll('.pcm-chip-remove').forEach(function(btn){
            btn.addEventListener('click', function(){
                var c = btn.closest('.pcm-chip');
                removeChipAnimated(c, c.dataset.value);
            });
        });

        input.addEventListener('keydown', function(e){
            if (e.key === 'Enter' || e.key === ','){
                e.preventDefault();
                var r = input.value.replace(/,/g, '').trim();
                if (r) { addChip(r); input.value = ''; }
            }
        });

        input.addEventListener('blur', function(){
            var r = input.value.replace(/,/g, '').trim();
            if (r) { addChip(r); input.value = ''; }
        });

        // Paste: debounce by 100ms, process entries sequentially
        input.addEventListener('paste', function(e){
            e.preventDefault();
            var p = (e.clipboardData || window.clipboardData).getData('text');
            if (pasteTimer) clearTimeout(pasteTimer);
            pasteTimer = setTimeout(function(){
                var entries = p.split(',');
                var i = 0;
                (function processNext(){
                    if (i >= entries.length) return;
                    var t = entries[i].trim();
                    if (t) addChip(t);
                    i++;
                    processNext();
                })();
                input.value = '';
            }, 100);
        });

        // "Clear all" button handler
        var clearAllLink = document.getElementById('pcm-chips-clear-all');
        if (clearAllLink) {
            clearAllLink.addEventListener('click', function(e){
                e.preventDefault();
                var chips = wrap.querySelectorAll('.pcm-chip');
                chips.forEach(function(c){ c.classList.add('pcm-chip-removing'); });
                setTimeout(function(){
                    chips.forEach(function(c){ c.remove(); });
                    syncHidden([]);
                    updateClearAll();
                }, 250);
            });
        }

        // Initial visibility check
        updateClearAll();
    })();
})(window, document);
