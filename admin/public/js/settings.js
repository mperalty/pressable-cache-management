(function(){
        var toggle = document.getElementById('pcm-caching-suite-toggle');
        var nonceField = document.getElementById('pcm-caching-suite-toggle-nonce');
        if (!toggle || !nonceField || typeof window.pcmPost !== 'function') return;

        var pcmToastStack = [];

        function pcmRepositionToasts() {
            var bottom = 24;
            for (var i = pcmToastStack.length - 1; i >= 0; i--) {
                var t = pcmToastStack[i];
                if (t && t.parentNode) {
                    t.style.bottom = bottom + 'px';
                    bottom += t.offsetHeight + 10;
                }
            }
        }

        function pcmDismissToast(toast) {
            if (toast.dataset.pcmDismissing) return;
            toast.dataset.pcmDismissing = '1';
            toast.classList.add('is-dismissing');
            toast.classList.remove('is-visible');
            window.setTimeout(function(){
                if (toast.parentNode) toast.parentNode.removeChild(toast);
                var idx = pcmToastStack.indexOf(toast);
                if (idx !== -1) pcmToastStack.splice(idx, 1);
                pcmRepositionToasts();
            }, 300);
        }

        function pcmShowToast(message, type) {
            type = type || 'success';
            var duration = 5000;
            var isError = type === 'error';

            var toast = document.createElement('div');
            toast.className = 'pcm-toast' + (isError ? ' pcm-toast-error' : '');
            toast.setAttribute('role', isError ? 'alert' : 'status');
            toast.setAttribute('aria-live', isError ? 'assertive' : 'polite');

            var msgSpan = document.createElement('span');
            msgSpan.className = 'pcm-toast-message';
            msgSpan.textContent = message;
            toast.appendChild(msgSpan);

            if (isError) {
                var closeBtn = document.createElement('button');
                closeBtn.className = 'pcm-toast-close';
                closeBtn.setAttribute('aria-label', 'Dismiss');
                closeBtn.innerHTML = '&times;';
                closeBtn.addEventListener('click', function(e){
                    e.stopPropagation();
                    pcmDismissToast(toast);
                });
                toast.appendChild(closeBtn);
            }

            if (!isError) {
                var progress = document.createElement('div');
                progress.className = 'pcm-toast-progress';
                progress.style.animationDuration = duration + 'ms';
                toast.appendChild(progress);
            }

            toast.addEventListener('click', function(){
                pcmDismissToast(toast);
            });

            pcmToastStack.push(toast);
            document.body.appendChild(toast);
            pcmRepositionToasts();

            requestAnimationFrame(function(){
                toast.classList.add('is-visible');
            });

            if (!isError) {
                var timerId = window.setTimeout(function(){
                    pcmDismissToast(toast);
                }, duration);

                var remaining = duration;
                var started = Date.now();

                toast.addEventListener('mouseenter', function(){
                    window.clearTimeout(timerId);
                    remaining -= (Date.now() - started);
                    toast.classList.add('is-paused');
                });

                toast.addEventListener('mouseleave', function(){
                    started = Date.now();
                    toast.classList.remove('is-paused');
                    timerId = window.setTimeout(function(){
                        pcmDismissToast(toast);
                    }, remaining);
                });
            }
        }

        function pcmSyncCachingSuiteUi(enabled) {
            var badge = document.getElementById('pcm-caching-suite-status-badge');
            var inlineStatus = document.getElementById('pcm-caching-suite-inline-status');
            if (badge) {
                badge.textContent = enabled ? 'Active' : 'Inactive';
                badge.classList.toggle('is-active', !!enabled);
                badge.classList.toggle('is-inactive', !enabled);
            }
            if (inlineStatus) {
                inlineStatus.innerHTML = '<strong>Status:</strong> ' + (enabled ? 'Enabled' : 'Disabled');
            }

            var tabsNav = document.getElementById('pcm-main-tab-nav');
            var deepDiveTab = document.getElementById('pcm-deep-dive-tab');

            if (enabled) {
                if (deepDiveTab) {
                    deepDiveTab.style.display = '';
                } else if (tabsNav) {
                    deepDiveTab = document.createElement('a');
                    deepDiveTab.id = 'pcm-deep-dive-tab';
                    deepDiveTab.className = 'nav-tab';
                    deepDiveTab.href = 'admin.php?page=pressable_cache_management&tab=deep_dive_tab';
                    deepDiveTab.textContent = 'Deep Dive';
                    var settingsTab = tabsNav.querySelector('a[href*="tab=settings_tab"]');
                    if (settingsTab) {
                        tabsNav.insertBefore(deepDiveTab, settingsTab);
                    } else {
                        tabsNav.appendChild(deepDiveTab);
                    }
                }
            } else if (deepDiveTab) {
                deepDiveTab.style.display = 'none';
            }
        }

        toggle.addEventListener('change', function(){
            var enabled = toggle.checked;
            var sw = toggle.closest('.switch');
            // Use pointer-events instead of disabled so the checkbox value is
            // still included if the surrounding form is submitted mid-flight.
            if (sw) { sw.style.pointerEvents = 'none'; sw.style.opacity = '0.6'; }

            window.pcmPost({
                action: 'pcm_toggle_caching_suite_features',
                nonce: nonceField.value,
                enabled: enabled ? '1' : '0'
            }).then(function(res){
                if (!res || !res.success) {
                    throw new Error((res && res.data && res.data.message) ? res.data.message : 'toggle_failed');
                }
                pcmSyncCachingSuiteUi(!!res.data.enabled);
                pcmShowToast(res.data.label || (enabled ? 'Caching Suite enabled' : 'Caching Suite disabled'));
            }).catch(function(error){
                toggle.checked = !enabled;
                pcmShowToast(window.pcmHandleError('Save Caching Suite Setting', error), 'error');
            }).finally(function(){
                if (sw) { sw.style.pointerEvents = ''; sw.style.opacity = ''; }
            });
        });
    })();

(function(){
        if (typeof window.pcmGetCacheabilityNonce !== 'function' || !window.pcmGetCacheabilityNonce()) return;
        var initialSettings = (window.pcmSettingsData && window.pcmSettingsData.privacySettings) || {};
        var retentionEl = document.getElementById('pcm-privacy-retention');
        var auditEnabledEl = document.getElementById('pcm-privacy-audit-enabled');
        var statusEl = document.getElementById('pcm-privacy-status');
        var auditLogEl = document.getElementById('pcm-audit-log');
        var loadMoreEl = document.getElementById('pcm-audit-load-more');

        var pageSize = 20;
        var currentOffset = 0;
        var allRows = [];

        // Privacy controls are only present on the Security & Privacy tab.
        // Without this guard, attempting to bind events from other tabs throws
        // and prevents the rest of settings.js (including Edge Cache controls)
        // from running.
        if (!retentionEl || !auditEnabledEl || !statusEl || !auditLogEl || !loadMoreEl) {
            return;
        }

        function renderSettings(s) {
            retentionEl.value = s.retention_days || 90;
            auditEnabledEl.checked = !!s.audit_log_enabled;
        }

        var escHtml = window.pcmEscapeHtml;

        function renderAuditRows(rows) {
            if (!rows.length) {
                auditLogEl.innerHTML = '<em>No audit entries yet.</em>';
                return;
            }

            var html = '<table class="widefat striped pcm-audit-table"><thead><tr><th>#</th><th>Action</th><th>User</th><th>Timestamp</th></tr></thead><tbody>';
            rows.forEach(function(row){
                html += '<tr>' +
                    '<td>' + escHtml(row.sequence_id || '?') + '</td>' +
                    '<td>' + escHtml(row.action || 'action') + '</td>' +
                    '<td>' + escHtml(row.actor_display || 'System') + '</td>' +
                    '<td>' + escHtml(row.created_at || 'n/a') + '</td>' +
                    '</tr>';
            });
            html += '</tbody></table>';
            auditLogEl.innerHTML = html;
        }

        function loadAudit(reset){
            if (reset) {
                currentOffset = 0;
                allRows = [];
                auditLogEl.innerHTML = '<em>Loading…</em>';
                loadMoreEl.style.display = 'none';
            }

            return window.pcmPost({ action: 'pcm_audit_log_list', nonce: window.pcmGetCacheabilityNonce(), limit: pageSize, offset: currentOffset }).then(function(res){
                if (!res || !res.success || !res.data || !Array.isArray(res.data.rows)) throw new Error('audit_failed');
                allRows = allRows.concat(res.data.rows);
                currentOffset += res.data.rows.length;
                renderAuditRows(allRows);
                loadMoreEl.style.display = res.data.has_more ? 'inline-block' : 'none';
            }).catch(function(error){
                window.pcmHandleError('Load Audit Log', error, auditLogEl);
            });
        }

        document.getElementById('pcm-privacy-save').addEventListener('click', function(){
            var saveBtn = document.getElementById('pcm-privacy-save');
            saveBtn.disabled = true;
            saveBtn.style.opacity = '0.6';
            statusEl.textContent = 'Saving…';
            window.pcmPost({
                action: 'pcm_privacy_settings_save',
                nonce: window.pcmGetCacheabilityNonce(),
                settings: JSON.stringify({
                    retention_days: retentionEl.value,
                    redaction_level: 'standard',
                    audit_log_enabled: auditEnabledEl.checked,
                    export_restrictions: 'admin_only'
                })
            }).then(function(res){
                statusEl.textContent = (res && res.success) ? 'Saved.' : 'Save failed.';
                loadAudit(true);
            }).catch(function(error){
                window.pcmHandleError('Save Privacy Settings', error, statusEl);
            }).finally(function(){
                saveBtn.disabled = false;
                saveBtn.style.opacity = '';
            });
        });

        document.getElementById('pcm-audit-refresh').addEventListener('click', function(){ loadAudit(true); });
        loadMoreEl.addEventListener('click', function(){ loadAudit(false); });

        var exportCsvBtn = document.getElementById('pcm-audit-export-csv');
        if (exportCsvBtn) {
            exportCsvBtn.addEventListener('click', function(){
                if (!allRows.length) return;
                var header = '#,Action,User,Timestamp';
                var csvRows = [header];
                allRows.forEach(function(row){
                    csvRows.push(
                        '"' + String(row.sequence_id || '').replace(/"/g, '""') + '",' +
                        '"' + String(row.action || '').replace(/"/g, '""') + '",' +
                        '"' + String(row.actor_display || 'System').replace(/"/g, '""') + '",' +
                        '"' + String(row.created_at || '').replace(/"/g, '""') + '"'
                    );
                });
                var blob = new Blob([csvRows.join('\r\n')], { type: 'text/csv;charset=utf-8;' });
                var url = URL.createObjectURL(blob);
                var a = document.createElement('a');
                a.href = url;
                a.download = 'pcm-audit-log-' + new Date().toISOString().slice(0,10) + '.csv';
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                URL.revokeObjectURL(url);
            });
        }

        renderSettings(initialSettings);
        loadAudit(true);
    })();

jQuery(document).ready(function($){
        var wrapper  = $('#edge-cache-control-wrapper');
        var purgeBtn = $('#purge-edge-cache-button-input');
        if (wrapper.length && !wrapper.data('ec-checked')) {
            wrapper.data('ec-checked', true);
            $.ajax({
                url: ajaxurl, type: 'POST',
                data: { action: 'pcm_check_edge_cache_status' },
                success: function(r) {
                    if (r.success && r.data.html_controls_enable_disable) {
                        wrapper.html(r.data.html_controls_enable_disable);
                        if (r.data.enabled) {
                            purgeBtn.removeClass('disabled-button-style ec-disabled-btn')
                                    .prop('disabled', false)
                                    .css({ opacity:1, cursor:'pointer', pointerEvents:'auto' });
                        }
                    } else {
                        var msg = (r.data && r.data.message) ? r.data.message : (window.pcmSettingsData && window.pcmSettingsData.strings ? window.pcmSettingsData.strings.failedRetrieveStatus : 'Failed to retrieve status.');
                        wrapper.html('<div class="pcm-inline-error" role="alert" aria-live="assertive">' + msg + '</div>');
                    }
                },
                error: function() {
                    window.pcmHandleError('Load Edge Cache Status', { message: 'network_error' }, wrapper[0]);
                }
            });
        }
    });

(function(){
    var pcmConfirmResolver = null;

    function pcmResolveConfirm(result) {
        var overlay = document.getElementById('pcm-settings-confirm-overlay');
        if (overlay) {
            overlay.style.display = 'none';
            window.pcmModalA11y.onClose(overlay);
        }

        // Persist "Don't ask again" preference when user confirms
        if (result && pcmActiveStorageKey) {
            var skipCheckbox = document.getElementById('pcm-settings-confirm-skip');
            if (skipCheckbox && skipCheckbox.checked) {
                try { localStorage.setItem(pcmActiveStorageKey, '1'); } catch (e) { /* storage unavailable */ }
            }
        }
        pcmActiveStorageKey = null;

        if (typeof pcmConfirmResolver === 'function') {
            pcmConfirmResolver(result);
            pcmConfirmResolver = null;
        }
    }

    function pcmEnsureConfirmModal() {
        if (document.getElementById('pcm-settings-confirm-overlay')) return;

        var overlay = document.createElement('div');
        overlay.id = 'pcm-settings-confirm-overlay';
        overlay.className = 'pcm-modal-overlay';

        overlay.innerHTML =
            '<div id="pcm-settings-confirm-dialog" class="pcm-modal-dialog pcm-modal-dialog-wide">'
            + '<div class="pcm-modal-accent"></div>'
            + '<p id="pcm-settings-confirm-msg" class="pcm-modal-message"></p>'
            + '<label id="pcm-settings-confirm-remember" class="pcm-modal-remember">'
            + '<input type="checkbox" id="pcm-settings-confirm-skip">Don\'t ask again</label>'
            + '<div class="pcm-modal-btn-row">'
            + '<button id="pcm-settings-confirm-cancel" type="button" class="pcm-modal-cancel">Cancel</button>'
            + '<button id="pcm-settings-confirm-ok" type="button" class="pcm-modal-ok">Confirm</button>'
            + '</div>'
            + '</div>';

        document.body.appendChild(overlay);

        var dialog = document.getElementById('pcm-settings-confirm-dialog');

        overlay.addEventListener('click', function(e) {
            if (e.target === overlay) {
                pcmResolveConfirm(false);
            }
        });

        document.getElementById('pcm-settings-confirm-cancel').addEventListener('click', function() {
            pcmResolveConfirm(false);
        });

        document.getElementById('pcm-settings-confirm-ok').addEventListener('click', function() {
            pcmResolveConfirm(true);
        });

        window.pcmModalA11y.setup({
            overlay: overlay,
            dialog: dialog,
            labelId: 'pcm-settings-confirm-msg',
            onClose: function() { pcmResolveConfirm(false); }
        });
    }

    var pcmActiveStorageKey = null;

    function pcmShowConfirmModal(message, confirmLabel, triggerEl, storageKey) {
        pcmEnsureConfirmModal();

        var overlay = document.getElementById('pcm-settings-confirm-overlay');
        var dialog  = document.getElementById('pcm-settings-confirm-dialog');
        var msg = document.getElementById('pcm-settings-confirm-msg');
        var okBtn = document.getElementById('pcm-settings-confirm-ok');
        var skipCheckbox = document.getElementById('pcm-settings-confirm-skip');
        var rememberLabel = document.getElementById('pcm-settings-confirm-remember');

        msg.textContent = message;
        okBtn.textContent = confirmLabel || 'Confirm';

        // Show "Don't ask again" checkbox only when a storageKey is provided
        pcmActiveStorageKey = storageKey || null;
        if (skipCheckbox) skipCheckbox.checked = false;
        if (rememberLabel) rememberLabel.style.display = storageKey ? 'block' : 'none';

        return new Promise(function(resolve) {
            pcmConfirmResolver = resolve;
            overlay.style.display = 'flex';
            window.pcmModalA11y.onOpen(overlay, dialog, triggerEl);
        });
    }

    function pcmRenderFlushNotice(message, isError) {
        var wrap = document.getElementById('pcm-flush-feedback');
        if (!wrap) return;
        var klass = isError ? 'pcm-inline-error' : 'pcm-inline-success';
        var ariaAttrs = isError ? ' role="alert" aria-live="assertive"' : '';
        wrap.innerHTML = '<div class="' + klass + '"' + ariaAttrs + '>' + message + '</div>';
    }

    function pcmToggleFlushLoading(btn, isLoading) {
        if (!btn) return;
        btn.disabled = !!isLoading;
        btn.classList.toggle('pcm-btn-loading', !!isLoading);
        btn.setAttribute('aria-busy', isLoading ? 'true' : 'false');
    }

    function pcmAjaxFlushObjectCache(btn) {
        if (!btn || !btn.form || typeof fetch !== 'function' || typeof ajaxurl === 'undefined') {
            if (btn && btn.form) btn.form.submit();
            return;
        }

        var nonceField = btn.form.querySelector('input[name="flush_object_cache_nonce"]');
        if (!nonceField || !nonceField.value) {
            btn.form.submit();
            return;
        }

        pcmToggleFlushLoading(btn, true);
        fetch(ajaxurl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: 'action=pcm_flush_object_cache&flush_object_cache_nonce=' + encodeURIComponent(nonceField.value)
        })
            .then(function(response){ return response.json(); })
            .then(function(res){
                if (!res || !res.success) {
                    var fallback = (window.pcmSettingsData && window.pcmSettingsData.strings && window.pcmSettingsData.strings.flushFailed) || 'Object cache flush failed. Please try again.';
                    var msg = (res && res.data && typeof res.data === 'object' && res.data.message)
                        ? res.data.message
                        : (res && res.data && typeof res.data === 'string') ? res.data : fallback;
                    throw new Error(msg);
                }

                var ts = document.getElementById('pcm-last-flushed-value');
                if (ts && res.data && res.data.timestamp) {
                    ts.textContent = res.data.timestamp;
                }

                pcmRenderFlushNotice((res.data && res.data.message) ? res.data.message : 'Object Cache Flushed Successfully.', false);
            })
            .catch(function(error){
                pcmRenderFlushNotice(error && error.message ? error.message : 'Object cache flush failed. Please try again.', true);
            })
            .finally(function(){
                pcmToggleFlushLoading(btn, false);
            });
    }

    function pcmInterceptWithConfirmation(selector, message, confirmLabel, onConfirm, storageKey) {
        document.addEventListener('click', function(e) {
            var target = e.target.closest(selector);
            if (!target || target.dataset.pcmConfirmBypass === '1') return;

            if (target.disabled) {
                return;
            }

            // Skip confirmation if user previously chose "Don't ask again"
            if (storageKey) {
                try {
                    if (localStorage.getItem(storageKey) === '1') {
                        if (typeof onConfirm === 'function') {
                            e.preventDefault();
                            onConfirm(target);
                        }
                        return;
                    }
                } catch (ex) { /* localStorage unavailable, always confirm */ }
            }

            e.preventDefault();
            pcmShowConfirmModal(message, confirmLabel, target, storageKey).then(function(confirmed) {
                if (!confirmed) return;

                if (typeof onConfirm === 'function') {
                    onConfirm(target);
                    return;
                }

                target.dataset.pcmConfirmBypass = '1';
                if (target.form) {
                    target.form.requestSubmit(target);
                } else {
                    target.click();
                }
                window.setTimeout(function(){
                    delete target.dataset.pcmConfirmBypass;
                }, 0);
            });
        });
    }

    pcmInterceptWithConfirmation(
        '#pcm-flush-btn',
        'This will flush the entire object cache. This will temporarily slow your site while the cache rebuilds. Continue?',
        'Flush',
        pcmAjaxFlushObjectCache,
        'pcm_skip_flush_confirm'
    );
    pcmInterceptWithConfirmation(
        '#purge-edge-cache-button-input',
        'This will temporarily slow your site while the cache rebuilds. Continue?',
        'Purge',
        null,
        'pcm_skip_edge_purge_confirm'
    );
    // ─── Edge Cache toggle via AJAX with propagation polling ─────────────────
    function pcmEdgeCacheToggle(triggerBtn) {
        var action = triggerBtn.getAttribute('data-pcm-ec-action') || (triggerBtn.id.indexOf('enable') !== -1 ? 'enable' : 'disable');
        var wrapper = document.getElementById('edge-cache-control-wrapper');
        var statusEl = document.getElementById('pcm-ec-propagation-status');
        var purgeBtn = document.getElementById('purge-edge-cache-button-input');
        var nonce = (window.pcmSettingsData && window.pcmSettingsData.nonces && window.pcmSettingsData.nonces.edgeCacheToggle) || '';

        // Disable the button and show propagating state
        triggerBtn.disabled = true;
        triggerBtn.style.opacity = '0.6';
        triggerBtn.style.cursor = 'wait';
        if (statusEl) {
            statusEl.innerHTML = '<span class="pcm-ec-propagating" style="color:#6b7280;font-style:italic;">Propagating...</span>';
        }

        window.pcmPost({
            action: 'pcm_toggle_edge_cache',
            nonce: nonce,
            desired_state: action
        }).then(function(res) {
            if (!res || !res.success) {
                throw new Error((res && res.data && res.data.message) ? res.data.message : 'Toggle request failed.');
            }
            // Start polling for propagation
            pcmPollEdgeCacheStatus(action, nonce, wrapper, statusEl, triggerBtn, purgeBtn, 0);
        }).catch(function(error) {
            triggerBtn.disabled = false;
            triggerBtn.style.opacity = '';
            triggerBtn.style.cursor = '';
            if (statusEl) {
                statusEl.innerHTML = '<span class="pcm-inline-error" role="alert" style="color:#dd3a03;">' +
                    (error && error.message ? error.message : 'Toggle request failed.') + '</span>';
            }
        });
    }

    function pcmPollEdgeCacheStatus(desiredState, nonce, wrapper, statusEl, triggerBtn, purgeBtn, attempt) {
        var maxAttempts = 5;
        var pollInterval = 2000; // 2 seconds

        window.setTimeout(function() {
            window.pcmPost({
                action: 'pcm_poll_edge_cache_status',
                nonce: nonce,
                desired_state: desiredState
            }).then(function(res) {
                if (!res || !res.success) {
                    throw new Error((res && res.data && res.data.message) ? res.data.message : 'Status check failed.');
                }

                if (res.data.propagated) {
                    // Propagation complete - update the UI
                    if (wrapper && res.data.html_controls_enable_disable) {
                        wrapper.innerHTML = res.data.html_controls_enable_disable;
                    }
                    if (purgeBtn) {
                        if (res.data.enabled) {
                            purgeBtn.disabled = false;
                            purgeBtn.classList.remove('disabled-button-style', 'ec-disabled-btn');
                            purgeBtn.style.opacity = '1';
                            purgeBtn.style.cursor = 'pointer';
                            purgeBtn.style.pointerEvents = 'auto';
                        } else {
                            purgeBtn.disabled = true;
                            purgeBtn.classList.add('disabled-button-style');
                            purgeBtn.style.opacity = '';
                            purgeBtn.style.cursor = '';
                            purgeBtn.style.pointerEvents = '';
                        }
                    }
                    var newStatusEl = document.getElementById('pcm-ec-propagation-status');
                    if (newStatusEl) {
                        newStatusEl.innerHTML = '<span style="color:#16a34a;font-weight:600;">' +
                            (desiredState === 'enable' ? 'Edge Cache enabled successfully.' : 'Edge Cache disabled successfully.') +
                            '</span>';
                        window.setTimeout(function() {
                            if (newStatusEl.parentNode) newStatusEl.innerHTML = '';
                        }, 5000);
                    }
                } else if (attempt + 1 < maxAttempts) {
                    // Not yet propagated - keep polling
                    if (statusEl) {
                        statusEl.innerHTML = '<span class="pcm-ec-propagating" style="color:#6b7280;font-style:italic;">Propagating... (attempt ' + (attempt + 2) + '/' + maxAttempts + ')</span>';
                    }
                    pcmPollEdgeCacheStatus(desiredState, nonce, wrapper, statusEl, triggerBtn, purgeBtn, attempt + 1);
                } else {
                    // Max attempts reached - show soft warning and update UI anyway
                    if (wrapper && res.data.html_controls_enable_disable) {
                        wrapper.innerHTML = res.data.html_controls_enable_disable;
                    }
                    var finalStatusEl = document.getElementById('pcm-ec-propagation-status');
                    if (finalStatusEl) {
                        finalStatusEl.innerHTML = '<span style="color:#b45309;font-style:italic;">Change submitted. Status may take a minute to update.</span>';
                    }
                }
            }).catch(function(error) {
                if (attempt + 1 < maxAttempts) {
                    // Network error during poll - retry
                    pcmPollEdgeCacheStatus(desiredState, nonce, wrapper, statusEl, triggerBtn, purgeBtn, attempt + 1);
                } else {
                    // Give up polling after max attempts on error
                    if (statusEl) {
                        statusEl.innerHTML = '<span style="color:#b45309;font-style:italic;">Change submitted. Status may take a minute to update.</span>';
                    }
                    // Re-enable button so user can retry
                    if (triggerBtn) {
                        triggerBtn.disabled = false;
                        triggerBtn.style.opacity = '';
                        triggerBtn.style.cursor = '';
                    }
                }
            });
        }, pollInterval);
    }

    pcmInterceptWithConfirmation(
        '#edge_cache_settings_tab_options_enable',
        'Are you sure you want to enable Edge Cache? Visitors may briefly receive uncached responses while cache is warmed.',
        'Enable Edge Cache',
        pcmEdgeCacheToggle
    );
    pcmInterceptWithConfirmation(
        '#edge_cache_settings_tab_options_disable',
        'Are you sure you want to disable Edge Cache? This can increase origin load and reduce performance for visitors.',
        'Disable Edge Cache',
        pcmEdgeCacheToggle
    );

    // Disable submit buttons on form submit
    // to prevent duplicate submissions during page navigation.
    document.querySelectorAll('form').forEach(function(form) {
        form.addEventListener('submit', function() {
            var submitBtns = form.querySelectorAll('button[type="submit"]');
            submitBtns.forEach(function(btn) {
                btn.disabled = true;
                btn.style.opacity = '0.6';
            });
        });
    });
})();
