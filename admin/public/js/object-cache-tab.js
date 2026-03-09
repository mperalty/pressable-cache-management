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
            ['active','broken','cloudflare'].forEach(function(cls) {
                badge.classList.remove(cls);
            });
            badge.classList.add(res.data.status === 'active' ? 'active' : 'broken');
            return res.data.status;
        }

        var pcmProbeInProgress = false;

        // Core: browser fetches homepage, reads header, reports to PHP.
        // cache:'reload' bypasses browser cache for a fresh CDN response.
        // Pragma: no-cache forces Pressable's Atomic Edge Cache to BYPASS (x-ac: BYPASS).
        function pcmProbeAndReport(onDone) {
            if (pcmProbeInProgress) {
                if (typeof onDone === 'function') onDone('busy');
                return;
            }
            pcmProbeInProgress = true;
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
            .then(function(r) { return r.json(); })
            .then(function(res) {
                var status = pcmApplyStatus(res);
                if (typeof onDone === 'function') onDone(status);
            })
            .catch(function() {
                if (typeof onDone === 'function') onDone(null);
            })
            .finally(function(){
                pcmProbeInProgress = false;
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
            var btn   = document.getElementById('pcm-bc-refresh');
            var label = document.getElementById('pcm-bc-label');
            if (pcmProbeInProgress) {
                label.textContent = (window.pcmObjectCacheData && window.pcmObjectCacheData.strings ? window.pcmObjectCacheData.strings.alreadyChecking : 'Already checking…');
                return;
            }
            btn.style.opacity = '0.3';
            btn.disabled = true;
            label.textContent = (window.pcmObjectCacheData && window.pcmObjectCacheData.strings ? window.pcmObjectCacheData.strings.checking : 'Checking…');
            pcmProbeAndReport(function() {
                btn.style.opacity = '0.6';
                btn.disabled = false;
            });
        }

        // Auto-poll: re-probe every 60s while status is broken (up to 5 attempts max)
        var pcmPollTimer = null, pcmPollCount = 0, pcmPollMax = 5;
        function pcmStartRecoveryPoll() {
            clearInterval(pcmPollTimer);
            pcmPollCount = 0;
            pcmPollTimer = setInterval(function() {
                pcmPollCount++;
                if (pcmPollCount > pcmPollMax) { clearInterval(pcmPollTimer); return; }
                pcmProbeAndReport(function(status) {
                    if (status === 'active') {
                        clearInterval(pcmPollTimer);
                    }
                });
            }, 60000);
        }

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
        })();

(function(){
        var wrap   = document.getElementById('pcm-chips-wrap');
        var input  = document.getElementById('pcm-exempt-input');
        var hidden = document.getElementById('pcm-exempt-hidden');
        if (!wrap || !input || !hidden) return;

        function getVals(){ return hidden.value ? hidden.value.split(',').map(s=>s.trim()).filter(Boolean) : []; }
        function syncHidden(v){ hidden.value = v.join(', '); }

        function addChip(val){
            val = val.trim(); if (!val) return;
            var vals = getVals();
            if (vals.indexOf(val) !== -1) return;
            vals.push(val); syncHidden(vals); renderChip(val);
        }
        function removeChip(val){ syncHidden(getVals().filter(v=>v!==val)); }

        function renderChip(val){
            var c = document.createElement('span');
            c.className = 'pcm-chip'; c.dataset.value = val;
            c.innerHTML = val + ' <button type="button" class="pcm-chip-remove" title="Remove">&#xD7;</button>';
            c.querySelector('.pcm-chip-remove').addEventListener('click',function(){ removeChip(val); c.remove(); });
            wrap.appendChild(c);
        }

        wrap.querySelectorAll('.pcm-chip-remove').forEach(function(btn){
            btn.addEventListener('click',function(){ var c=btn.closest('.pcm-chip'); removeChip(c.dataset.value); c.remove(); });
        });

        input.addEventListener('keydown',function(e){
            if (e.key==='Enter'||e.key===','){
                e.preventDefault(); var r=input.value.replace(/,/g,'').trim(); if(r){addChip(r);input.value='';}
            }
        });
        input.addEventListener('blur',function(){ var r=input.value.replace(/,/g,'').trim(); if(r){addChip(r);input.value='';} });
        input.addEventListener('paste',function(e){
            e.preventDefault();
            var p=(e.clipboardData||window.clipboardData).getData('text');
            p.split(',').forEach(function(v){ var t=v.trim(); if(t) addChip(t); });
            input.value='';
        });
    })();
