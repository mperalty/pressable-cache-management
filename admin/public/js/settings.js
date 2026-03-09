(function(){
        var toggle = document.getElementById('pcm-caching-suite-toggle');
        var nonceField = document.getElementById('pcm-caching-suite-toggle-nonce');
        if (!toggle || !nonceField || typeof window.pcmPost !== 'function') return;

        function pcmShowToast(message) {
            var existing = document.getElementById('pcm-caching-suite-toast');
            if (existing) {
                existing.remove();
            }
            var toast = document.createElement('div');
            toast.id = 'pcm-caching-suite-toast';
            toast.className = 'pcm-toast';
            toast.textContent = message;
            document.body.appendChild(toast);
            requestAnimationFrame(function(){
                toast.classList.add('is-visible');
            });
            window.setTimeout(function(){
                toast.classList.remove('is-visible');
                window.setTimeout(function(){
                    if (toast && toast.parentNode) toast.parentNode.removeChild(toast);
                }, 250);
            }, 1900);
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
                if (!deepDiveTab && tabsNav) {
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
            } else if (deepDiveTab && deepDiveTab.parentNode) {
                deepDiveTab.parentNode.removeChild(deepDiveTab);
            }
        }

        toggle.addEventListener('change', function(){
            var enabled = toggle.checked;
            toggle.disabled = true;

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
                pcmShowToast(window.pcmHandleError('Save Caching Suite Setting', error));
            }).finally(function(){
                toggle.disabled = false;
            });
        });
    })();

(function(){
        if (typeof window.pcmGetCacheabilityNonce !== 'function' || !window.pcmGetCacheabilityNonce()) return;
        var initialSettings = (window.pcmSettingsData && window.pcmSettingsData.privacySettings) || {};
        var retentionEl = document.getElementById('pcm-privacy-retention');
        var redactionEl = document.getElementById('pcm-privacy-redaction');
        var advancedEl = document.getElementById('pcm-privacy-advanced-scan');
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
        if (!retentionEl || !redactionEl || !advancedEl || !auditEnabledEl || !statusEl || !auditLogEl || !loadMoreEl) {
            return;
        }

        function renderSettings(s) {
            retentionEl.value = s.retention_days || 90;
            redactionEl.value = s.redaction_level || 'standard';
            advancedEl.checked = !!s.advanced_scan_opt_in;
            auditEnabledEl.checked = !!s.audit_log_enabled;
        }

        function escHtml(value) {
            return String(value == null ? '' : value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function renderAuditRows(rows) {
            if (!rows.length) {
                auditLogEl.classList.remove('pcm-skeleton');
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
            auditLogEl.classList.remove('pcm-skeleton');
            auditLogEl.innerHTML = html;
        }

        function loadAudit(reset){
            if (reset) {
                currentOffset = 0;
                allRows = [];
                auditLogEl.classList.add('pcm-skeleton');
                loadMoreEl.style.display = 'none';
            }

            return window.pcmPost({ action: 'pcm_audit_log_list', nonce: window.pcmGetCacheabilityNonce(), limit: pageSize, offset: currentOffset }).then(function(res){
                if (!res || !res.success || !res.data || !Array.isArray(res.data.rows)) throw new Error('audit_failed');
                allRows = allRows.concat(res.data.rows);
                currentOffset += res.data.rows.length;
                renderAuditRows(allRows);
                loadMoreEl.style.display = res.data.has_more ? 'inline-block' : 'none';
            }).catch(function(error){
                auditLogEl.classList.remove('pcm-skeleton');
                window.pcmHandleError('Load Audit Log', error, auditLogEl);
            });
        }

        document.getElementById('pcm-privacy-save').addEventListener('click', function(){
            statusEl.textContent = 'Saving…';
            window.pcmPost({
                action: 'pcm_privacy_settings_save',
                nonce: window.pcmGetCacheabilityNonce(),
                settings: JSON.stringify({
                    retention_days: retentionEl.value,
                    redaction_level: redactionEl.value,
                    advanced_scan_opt_in: advancedEl.checked,
                    audit_log_enabled: auditEnabledEl.checked,
                    export_restrictions: 'admin_only'
                })
            }).then(function(res){
                statusEl.textContent = (res && res.success) ? 'Saved.' : 'Save failed.';
                loadAudit(true);
            }).catch(function(error){
                window.pcmHandleError('Save Privacy Settings', error, statusEl);
            });
        });

        document.getElementById('pcm-audit-refresh').addEventListener('click', function(){ loadAudit(true); });
        loadMoreEl.addEventListener('click', function(){ loadAudit(false); });

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
                            purgeBtn.removeClass('ec-disabled-btn')
                                    .prop('disabled', false)
                                    .css({ opacity:1, cursor:'pointer', pointerEvents:'auto' });
                        }
                    } else {
                        var msg = (r.data && r.data.message) ? r.data.message : (window.pcmSettingsData && window.pcmSettingsData.strings ? window.pcmSettingsData.strings.failedRetrieveStatus : 'Failed to retrieve status.');
                        wrapper.html('<div class="pcm-inline-error">' + msg + '</div>');
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
        }

        if (typeof pcmConfirmResolver === 'function') {
            pcmConfirmResolver(result);
            pcmConfirmResolver = null;
        }
    }

    function pcmEnsureConfirmModal() {
        if (document.getElementById('pcm-settings-confirm-overlay')) return;

        var overlay = document.createElement('div');
        overlay.id = 'pcm-settings-confirm-overlay';
        overlay.style.cssText =
            'display:none;position:fixed;inset:0;background:rgba(4,0,36,.45);'
            + 'z-index:999999;align-items:center;justify-content:center;';

        overlay.innerHTML =
            '<div style="background:#fff;border-radius:12px;padding:28px 32px;max-width:500px;width:90%;'
            + 'box-shadow:0 8px 40px rgba(4,0,36,.18);font-family:sans-serif;position:relative;">'
            + '<div style="width:48px;height:4px;background:#03fcc2;border-radius:4px;margin-bottom:16px;"></div>'
            + '<p id="pcm-settings-confirm-msg" style="margin:0 0 22px;font-size:14px;color:#040024;line-height:1.6;"></p>'
            + '<div style="display:flex;justify-content:flex-end;gap:10px;">'
            + '<button id="pcm-settings-confirm-cancel" type="button" style="background:#fff;color:#040024;border:1px solid #cbd5e1;border-radius:8px;'
            + 'padding:10px 20px;font-size:13.5px;font-weight:700;cursor:pointer;font-family:sans-serif;">Cancel</button>'
            + '<button id="pcm-settings-confirm-ok" type="button" style="background:#dd3a03;color:#fff;border:none;border-radius:8px;'
            + 'padding:10px 24px;font-size:13.5px;font-weight:700;cursor:pointer;font-family:sans-serif;">Confirm</button>'
            + '</div>'
            + '</div>';

        document.body.appendChild(overlay);

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
    }

    function pcmShowConfirmModal(message, confirmLabel) {
        pcmEnsureConfirmModal();

        var overlay = document.getElementById('pcm-settings-confirm-overlay');
        var msg = document.getElementById('pcm-settings-confirm-msg');
        var okBtn = document.getElementById('pcm-settings-confirm-ok');

        msg.textContent = message;
        okBtn.textContent = confirmLabel || 'Confirm';

        return new Promise(function(resolve) {
            pcmConfirmResolver = resolve;
            overlay.style.display = 'flex';
        });
    }

    function pcmRenderFlushNotice(message, isError) {
        var wrap = document.getElementById('pcm-flush-feedback');
        if (!wrap) return;
        var klass = isError ? 'pcm-inline-error' : 'pcm-inline-success';
        wrap.innerHTML = '<div class="' + klass + '">' + message + '</div>';
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
                    var msg = (res && res.data && res.data.message) ? res.data.message : fallback;
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

    function pcmInterceptWithConfirmation(selector, message, confirmLabel, onConfirm) {
        document.addEventListener('click', function(e) {
            var target = e.target.closest(selector);
            if (!target || target.dataset.pcmConfirmBypass === '1') return;

            if (target.disabled) {
                return;
            }

            e.preventDefault();
            pcmShowConfirmModal(message, confirmLabel).then(function(confirmed) {
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
        'Are you sure you want to flush the entire site cache? This will temporarily slow your site while the cache rebuilds.',
        'Flush',
        pcmAjaxFlushObjectCache
    );
    pcmInterceptWithConfirmation(
        '#purge-edge-cache-button-input',
        'Are you sure you want to purge the edge cache for the entire site? This will temporarily slow your site while the cache rebuilds.',
        'Purge'
    );
    pcmInterceptWithConfirmation(
        '#edge_cache_settings_tab_options_enable',
        'Are you sure you want to enable Edge Cache? Visitors may briefly receive uncached responses while cache is warmed.',
        'Enable Edge Cache'
    );
    pcmInterceptWithConfirmation(
        '#edge_cache_settings_tab_options_disable',
        'Are you sure you want to disable Edge Cache? This can increase origin load and reduce performance for visitors.',
        'Disable Edge Cache'
    );
})();
