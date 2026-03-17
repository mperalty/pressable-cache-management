/**
 * Pressable Cache Management - AJAX helpers & nonce management
 *
 * Public API (on window):
 *   window.pcmPost(bodyObj)                - POST to ajaxurl, returns Promise
 *   window.pcmHandleError(context, error, targetEl) - Render error UI
 *   window.pcmGetCacheabilityNonce()       - Return current cacheability nonce
 *   window.pcmRefreshCacheabilityNonce()   - Refresh nonce, returns Promise
 *   window.pcmStartCacheabilityNonceRefresh() - Start 30-min auto-refresh
 */
(function(window, document) {
    'use strict';

    window.pcmPost = window.pcmPost || function(bodyObj, opts) {
        opts = opts || {};
        var timeoutMs = opts.timeout || 15000;
        var controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
        var timeoutId = null;
        var params = new URLSearchParams();
        Object.keys(bodyObj || {}).forEach(function(key){
            var value = bodyObj[key];
            if (Array.isArray(value)) {
                value.forEach(function(item){ params.append(key + '[]', item); });
                return;
            }
            params.append(key, value);
        });
        if (controller) {
            timeoutId = window.setTimeout(function(){
                controller.abort();
            }, timeoutMs);
        }

        return fetch(ajaxurl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: params.toString(),
            signal: controller ? controller.signal : undefined
        }).then(function(response){
            if (timeoutId) window.clearTimeout(timeoutId);
            if (!response.ok) {
                return response.text().then(function(text) {
                    var error = new Error('http_' + response.status);
                    error.status = response.status;

                    if (text) {
                        try {
                            var payload = JSON.parse(text);
                            error.payload = payload;
                            if (payload && payload.data && payload.data.message) {
                                error.message = payload.data.message;
                            } else if (payload && payload.message) {
                                error.message = payload.message;
                            }
                        } catch (parseError) {
                            // Keep the default status-based message when the response is not JSON.
                        }
                    }

                    throw error;
                });
            }
            return response.json();
        }).catch(function(error){
            if (timeoutId) window.clearTimeout(timeoutId);
            if (error && (error.name === 'AbortError' || error.message === 'timeout')) {
                var timeoutError = new Error('timeout');
                timeoutError.isTimeout = true;
                throw timeoutError;
            }
            throw error;
        });
    };

    window.pcmHandleError = window.pcmHandleError || function(context, error, targetEl) {
        var esc = window.pcmEscapeHtml;

        var isNonceError = error && (error.status === 403 || /nonce|forbidden|permission|rest_forbidden/i.test(error.message || ''));

        // Attempt automatic nonce refresh for 403/nonce errors before showing the message
        if (isNonceError && typeof window.pcmRefreshCacheabilityNonce === 'function') {
            window.pcmRefreshCacheabilityNonce().then(function() {
                var refreshed = 'Your session expired and was automatically refreshed. Please try your action again.';
                if (targetEl) {
                    targetEl.innerHTML = '<div class="pcm-inline-error" role="alert" aria-live="assertive"><strong>' + esc(context) + ':</strong> ' + esc(refreshed) + '</div>';
                }
            }).catch(function() {
                var expired = 'Your session expired. Please <a href="" class="pcm-reload-link" href="#">reload the page</a> to continue.';
                if (targetEl) {
                    targetEl.innerHTML = '<div class="pcm-inline-error" role="alert" aria-live="assertive"><strong>' + esc(context) + ':</strong> ' + expired + '</div>';
                }
            });
            return 'Your session expired. Attempting to refresh…';
        }

        var message;
        if (error && (error.isTimeout || error.message === 'timeout')) {
            message = 'The request timed out. <a href="#" class="pcm-retry-link">Try again</a>';
        } else if (isNonceError) {
            message = 'Your session expired. Please <a href="" class="pcm-reload-link" href="#">reload the page</a> to continue.';
        } else if (error && error.status >= 500) {
            message = 'Server error (HTTP ' + error.status + '). Check your PHP error logs for details.';
        } else {
            message = 'Could not connect to your site. Check that your site is accessible.';
        }

        if (targetEl) {
            targetEl.innerHTML = '<div class="pcm-inline-error" role="alert" aria-live="assertive"><strong>' + esc(context) + ':</strong> ' + message + '</div>';
            // Bind retry link if present
            var retryLink = targetEl.querySelector('.pcm-retry-link');
            if (retryLink) {
                retryLink.addEventListener('click', function(e) {
                    e.preventDefault();
                    if (typeof targetEl._pcmRetryCallback === 'function') {
                        targetEl._pcmRetryCallback();
                    } else {
                        location.reload();
                    }
                });
            }
            // Bind reload links (replaces inline onclick="location.reload()")
            var reloadLinks = targetEl.querySelectorAll('.pcm-reload-link');
            for (var i = 0; i < reloadLinks.length; i++) {
                reloadLinks[i].addEventListener('click', function(e) {
                    e.preventDefault();
                    location.reload();
                });
            }
        }
        return message;
    };

    (function(){
        function setNonce(nextNonce, data) {
            if (!nextNonce) return;
            if (window.pcmSettingsData && window.pcmSettingsData.nonces) {
                window.pcmSettingsData.nonces.cacheabilityScan = nextNonce;
            }
            if (window.pcmDeepDiveData && window.pcmDeepDiveData.nonces) {
                window.pcmDeepDiveData.nonces.cacheabilityScan = nextNonce;
            }
        }

        window.pcmGetCacheabilityNonce = window.pcmGetCacheabilityNonce || function() {
            if (window.pcmDeepDiveData && window.pcmDeepDiveData.nonces && window.pcmDeepDiveData.nonces.cacheabilityScan) {
                return window.pcmDeepDiveData.nonces.cacheabilityScan;
            }
            if (window.pcmSettingsData && window.pcmSettingsData.nonces && window.pcmSettingsData.nonces.cacheabilityScan) {
                return window.pcmSettingsData.nonces.cacheabilityScan;
            }
            return '';
        };

        function refreshCacheabilityNonce() {
            var params = new URLSearchParams();
            params.append('action', 'pcm_refresh_cacheability_nonce');

            return fetch(ajaxurl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: params.toString()
            }).then(function(response){
                if (!response.ok) {
                    var error = new Error('http_' + response.status);
                    error.status = response.status;
                    throw error;
                }
                return response.json();
            }).then(function(payload){
                if (payload && payload.success && payload.data && payload.data.nonce) {
                    setNonce(payload.data.nonce, payload.data);
                    return payload.data.nonce;
                }
                throw new Error('nonce_refresh_failed');
            });
        }

        function startNonceRefresh() {
            var existingNonce = window.pcmGetCacheabilityNonce();
            if (!existingNonce) return;
            if (window.pcmCacheabilityNonceRefreshStarted) return;
            window.pcmCacheabilityNonceRefreshStarted = true;

            window.setInterval(function(){
                refreshCacheabilityNonce().catch(function(){
                    return null;
                });
            }, 30 * 60 * 1000);
        }

        window.pcmRefreshCacheabilityNonce = window.pcmRefreshCacheabilityNonce || refreshCacheabilityNonce;
        window.pcmStartCacheabilityNonceRefresh = window.pcmStartCacheabilityNonceRefresh || startNonceRefresh;

        startNonceRefresh();
    })();

})(window, document);
