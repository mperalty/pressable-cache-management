/**
 * Redirects Tab — Redirect Assistant
 *
 * Standalone version of the Redirect Assistant for the dedicated Redirects tab.
 * Handles rule discovery, editing, dry-run simulation, export, and import.
 */
document.addEventListener('DOMContentLoaded', function() {
    if (typeof window.pcmGetCacheabilityNonce !== 'function' || !window.pcmGetCacheabilityNonce()) return;

    var out = document.getElementById('pcm-ra-output');
    var candidatesOut = document.getElementById('pcm-ra-candidates-output');
    var simOut = document.getElementById('pcm-ra-sim-output');
    var rulesBox = document.getElementById('pcm-ra-rules-json');
    var exportBox = document.getElementById('pcm-ra-export-content');
    var importBox = document.getElementById('pcm-ra-import-content');
    var rulesBody = document.getElementById('pcm-ra-rules-body');
    var ruleErrors = document.getElementById('pcm-ra-rule-errors');
    var toggleAdvancedBtn = document.getElementById('pcm-ra-toggle-advanced');
    var advancedVisible = false;
    var ruleState = [];

    if (!rulesBox || !rulesBody || !ruleErrors || !toggleAdvancedBtn) return;

    function requireSuccess(res, fallbackMessage) {
        if (!res || !res.success) {
            throw new Error(window.pcmPayloadErrorMessage
                ? window.pcmPayloadErrorMessage(res, fallbackMessage)
                : (res && res.data && res.data.message ? res.data.message : fallbackMessage));
        }
        return res;
    }

    function showStatus(el, msg, isError) {
        if (!el) return;
        var klass = isError ? 'pcm-ra-status-error' : 'pcm-ra-status-success';
        el.innerHTML = '<div class="' + klass + '">' + escapeHtml(msg) + '</div>';
    }

    var escapeHtml = window.pcmEscapeHtml;

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

        ruleState.forEach(function(rule) {
            var key = rule.source_pattern.trim().toLowerCase();
            if (!key) return;
            if (seen[key]) {
                duplicates[key] = true;
            }
            seen[key] = true;
        });

        ruleState.forEach(function(rule) {
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
        validationErrors.forEach(function(entry) { errorMap[entry.id] = entry.messages; });

        rulesBody.innerHTML = ruleState.map(function(rule) {
            var invalid = (errorMap[rule.id] || []).length > 0;
            var inputClass = invalid ? 'pcm-ra-input pcm-ra-input-invalid' : 'pcm-ra-input';
            return '<tr data-rule-id="' + escapeHtml(rule.id) + '">' +
                '<td class="pcm-ra-td"><input data-field="source_pattern" type="text" value="' + escapeHtml(rule.source_pattern) + '" class="' + inputClass + ' pcm-ra-input-source"></td>' +
                '<td class="pcm-ra-td"><input data-field="target_pattern" type="text" value="' + escapeHtml(rule.target_pattern) + '" class="pcm-ra-input pcm-ra-input-target"></td>' +
                '<td class="pcm-ra-td"><select data-field="match_type" class="pcm-ra-select pcm-ra-select-match">' +
                    '<option value="exact"' + (rule.match_type === 'exact' ? ' selected' : '') + '>exact</option>' +
                    '<option value="wildcard"' + (rule.match_type === 'wildcard' ? ' selected' : '') + '>wildcard</option>' +
                    '<option value="regex"' + (rule.match_type === 'regex' ? ' selected' : '') + '>regex</option>' +
                '</select></td>' +
                '<td class="pcm-ra-td"><select data-field="status_code" class="pcm-ra-select pcm-ra-select-code">' +
                    '<option value="301"' + (parseInt(rule.status_code, 10) === 301 ? ' selected' : '') + '>301</option>' +
                    '<option value="302"' + (parseInt(rule.status_code, 10) === 302 ? ' selected' : '') + '>302</option>' +
                    '<option value="307"' + (parseInt(rule.status_code, 10) === 307 ? ' selected' : '') + '>307</option>' +
                '</select></td>' +
                '<td class="pcm-ra-td"><input data-field="enabled" type="checkbox"' + (rule.enabled ? ' checked' : '') + '></td>' +
                '<td class="pcm-ra-td"><button type="button" class="button-link-delete" data-delete-rule="1">Delete</button></td>' +
            '</tr>';
        }).join('');

        if (!ruleState.length) {
            rulesBody.innerHTML = '<tr><td colspan="6" class="pcm-ra-td pcm-ra-empty">No rules yet. Click + Add Rule.</td></tr>';
        }

        ruleErrors.innerHTML = validationErrors.map(function(item) {
            return '&bull; ' + escapeHtml(item.messages.join(' '));
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
            if (!rule.enabled) continue;
            if (rule.match_type === 'exact' && path === rule.source_pattern) return rule;
            if (rule.match_type === 'wildcard' && path.indexOf(rule.source_pattern) === 0) return rule;
            if (rule.match_type === 'regex') {
                try {
                    if ((new RegExp(rule.source_pattern)).test(path)) return rule;
                } catch (e) {}
            }
        }
        return null;
    }

    // --- Discover Candidates ---
    function renderCandidates(candidates) {
        if (!candidatesOut) return;
        if (!candidates || !candidates.length) {
            candidatesOut.innerHTML = '<em>No candidates found.</em>';
            return;
        }

        var html = candidates.map(function(c, idx) {
            var source = escapeHtml(c.source_pattern || '');
            var target = escapeHtml(c.target_pattern || '');
            return '<div class="pcm-ra-candidate-item">' +
                '<span class="pcm-ra-candidate-source">' + source + '</span>' +
                '<span class="pcm-ra-candidate-arrow">&rarr;</span>' +
                '<span class="pcm-ra-candidate-target">' + target + '</span>' +
                '<button type="button" class="pcm-btn-text pcm-ra-candidate-add" data-index="' + idx + '">Add</button>' +
            '</div>';
        }).join('');

        candidatesOut.innerHTML = html;

        // Store candidates for "Add" button clicks
        candidatesOut._candidates = candidates;
    }

    if (candidatesOut) {
        candidatesOut.addEventListener('click', function(event) {
            var btn = event.target.closest('.pcm-ra-candidate-add');
            if (!btn) return;
            var idx = parseInt(btn.getAttribute('data-index'), 10);
            var candidates = candidatesOut._candidates;
            if (!candidates || !candidates[idx]) return;
            ruleState.push(normalizeRule(candidates[idx]));
            renderRules();
            btn.textContent = 'Added';
            btn.disabled = true;
            btn.style.opacity = '0.5';
        });
    }

    // --- Dry-Run Simulation ---
    function renderDryRunTable(res) {
        var target = simOut || out;
        var results = (res && res.data && Array.isArray(res.data.results)) ? res.data.results : [];
        if (!results.length) {
            target.innerHTML = '<em>No dry-run results.</em>';
            return;
        }

        var html = '<table class="pcm-table-full pcm-ra-sim-table">' +
            '<thead><tr>' +
            '<th class="pcm-ra-th">Input</th>' +
            '<th class="pcm-ra-th">Match</th>' +
            '<th class="pcm-ra-th">Result</th>' +
            '<th class="pcm-ra-th">Status</th>' +
            '</tr></thead><tbody>';

        results.forEach(function(item) {
            var matchedRule = findMatchedRule(item.input_url || '');
            var isOk = !!matchedRule;
            var rowClass = isOk ? 'pcm-ra-sim-ok' : 'pcm-ra-sim-miss';
            var dotClass = isOk ? 'pcm-ra-status-dot-ok' : 'pcm-ra-status-dot-miss';
            var statusLabel = isOk ? 'OK' : 'No match';

            html += '<tr class="' + rowClass + '">' +
                '<td class="pcm-ra-td">' + escapeHtml(item.input_url || '') + '</td>' +
                '<td class="pcm-ra-td">' + escapeHtml(matchedRule ? ('Rule: ' + matchedRule.source_pattern + ' \u2192 ' + matchedRule.target_pattern) : 'No match') + '</td>' +
                '<td class="pcm-ra-td">' + escapeHtml(item.result_url || (matchedRule ? matchedRule.target_pattern : '')) + '</td>' +
                '<td class="pcm-ra-td"><span class="pcm-ra-status-dot ' + dotClass + '"></span>' + statusLabel + '</td>' +
            '</tr>';
        });

        html += '</tbody></table>';
        target.innerHTML = html;
    }

    // --- Toggle Advanced JSON ---
    toggleAdvancedBtn.addEventListener('click', function() {
        advancedVisible = !advancedVisible;
        rulesBox.style.display = advancedVisible ? 'block' : 'none';
        toggleAdvancedBtn.textContent = advancedVisible ? 'Hide Advanced JSON' : 'Show Advanced JSON';
        if (!advancedVisible) {
            setRulesFromJson(rulesBox.value, true);
        }
    });

    // --- Add Rule ---
    document.getElementById('pcm-ra-add-rule').addEventListener('click', function() {
        ruleState.push(defaultRule());
        renderRules();
    });

    // --- Delete Rule ---
    rulesBody.addEventListener('click', function(event) {
        var row = event.target.closest('tr[data-rule-id]');
        if (!row || !event.target.closest('[data-delete-rule="1"]')) return;
        var id = row.getAttribute('data-rule-id');
        ruleState = ruleState.filter(function(rule) { return rule.id !== id; });
        renderRules();
    });

    // --- Inline Edit ---
    rulesBody.addEventListener('input', function(event) {
        var row = event.target.closest('tr[data-rule-id]');
        var field = event.target.getAttribute('data-field');
        if (!row || !field) return;
        var id = row.getAttribute('data-rule-id');
        var currentRule = ruleState.find(function(rule) { return rule.id === id; });
        if (!currentRule) return;
        currentRule[field] = field === 'enabled' ? !!event.target.checked : event.target.value;
        if (field === 'status_code') {
            currentRule[field] = parseInt(event.target.value, 10) || 301;
        }
        renderRules();
    });

    // --- Advanced JSON edit ---
    rulesBox.addEventListener('input', function() {
        if (advancedVisible) {
            setRulesFromJson(rulesBox.value, false);
        }
    });

    // --- Discover Candidates ---
    document.getElementById('pcm-ra-discover').addEventListener('click', function() {
        if (candidatesOut) candidatesOut.innerHTML = '<em>Discovering candidates...</em>';
        window.pcmPost({ action: 'pcm_redirect_assistant_discover_candidates', nonce: window.pcmGetCacheabilityNonce(), urls: document.getElementById('pcm-ra-urls').value })
            .then(function(res) {
                requireSuccess(res, 'Unable to load redirect discovery endpoint.');
                if (res && res.success && res.data && Array.isArray(res.data.candidates)) {
                    renderCandidates(res.data.candidates);
                } else {
                    if (candidatesOut) candidatesOut.innerHTML = '<em>No candidates returned.</em>';
                }
            })
            .catch(function(error) {
                showStatus(candidatesOut || out, error.message || 'Discovery failed.', true);
            });
    });

    // --- Load Saved Rules ---
    document.getElementById('pcm-ra-load-rules').addEventListener('click', function() {
        window.pcmPost({ action: 'pcm_redirect_assistant_list_rules', nonce: window.pcmGetCacheabilityNonce() })
            .then(function(res) {
                requireSuccess(res, 'Unable to load redirect rules endpoint.');
                if (res && res.success && res.data) {
                    rulesBox.value = JSON.stringify(res.data.rules || [], null, 2);
                    setRulesFromJson(rulesBox.value, true);
                }
                showStatus(out, 'Rules loaded successfully.', false);
            })
            .catch(function(error) {
                showStatus(out, error.message || 'Failed to load rules.', true);
            });
    });

    // --- Save Rules ---
    document.getElementById('pcm-ra-save').addEventListener('click', function() {
        if (validateRuleState().length) {
            ruleErrors.textContent = 'Fix validation errors before saving.';
            return;
        }
        syncJsonFromState();
        window.pcmPost({ action: 'pcm_redirect_assistant_save_rules', nonce: window.pcmGetCacheabilityNonce(), rules: rulesBox.value, confirm_wildcards: document.getElementById('pcm-ra-confirm-wildcards').checked ? '1' : '0' })
            .then(function(res) {
                requireSuccess(res, 'Unable to save redirect rules endpoint.');
                showStatus(out, 'Rules saved successfully.', false);
            })
            .catch(function(error) {
                showStatus(out, error.message || 'Failed to save rules.', true);
            });
    });

    // --- Dry-Run Simulation ---
    document.getElementById('pcm-ra-simulate').addEventListener('click', function() {
        syncJsonFromState();
        if (simOut) simOut.innerHTML = '<em>Running simulation...</em>';
        window.pcmPost({ action: 'pcm_redirect_assistant_simulate', nonce: window.pcmGetCacheabilityNonce(), urls: document.getElementById('pcm-ra-sim-urls').value, rules: rulesBox.value })
            .then(function(res) {
                requireSuccess(res, 'Unable to run redirect simulation endpoint.');
                renderDryRunTable(res);
            })
            .catch(function(error) {
                showStatus(simOut || out, error.message || 'Simulation failed.', true);
            });
    });

    // --- Build Export ---
    document.getElementById('pcm-ra-export').addEventListener('click', function() {
        syncJsonFromState();
        window.pcmPost({ action: 'pcm_redirect_assistant_export', nonce: window.pcmGetCacheabilityNonce(), confirm_wildcards: document.getElementById('pcm-ra-confirm-wildcards').checked ? '1' : '0' })
            .then(function(res) {
                requireSuccess(res, 'Unable to export redirect rules endpoint.');
                if (res && res.success && res.data && res.data.export && exportBox) {
                    var content = (res.data.export.content || '') + '\n\n/* JSON PAYLOAD FOR IMPORT */\n' + (res.data.meta_json || '');
                    exportBox.value = content;
                }
                showStatus(out, 'Export generated successfully.', false);
            })
            .catch(function(error) {
                showStatus(out, error.message || 'Export failed.', true);
            });
    });

    // --- Copy to Clipboard ---
    var copyBtn = document.getElementById('pcm-ra-copy');
    if (copyBtn) {
        copyBtn.addEventListener('click', function() {
            var txt = exportBox ? exportBox.value || '' : '';
            navigator.clipboard.writeText(txt)
                .then(function() { showStatus(out, 'Copied to clipboard.', false); })
                .catch(function() { showStatus(out, 'Failed to copy to clipboard.', true); });
        });
    }

    // --- Download ---
    var downloadBtn = document.getElementById('pcm-ra-download');
    if (downloadBtn) {
        downloadBtn.addEventListener('click', function() {
            var content = exportBox ? exportBox.value || '' : '';
            var idx = content.indexOf('/* JSON PAYLOAD FOR IMPORT */');
            if (idx > -1) {
                content = content.substring(0, idx).trim() + '\n';
            }
            var blob = new Blob([content], { type: 'text/x-php' });
            var a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            a.download = 'custom-redirects.php';
            document.body.appendChild(a);
            a.click();
            a.remove();
        });
    }

    // --- Import JSON Payload ---
    var importBtn = document.getElementById('pcm-ra-import');
    if (importBtn) {
        importBtn.addEventListener('click', function() {
            var raw = importBox ? importBox.value || '' : '';
            var marker = '/* JSON PAYLOAD FOR IMPORT */';
            var payload = raw.indexOf(marker) > -1 ? raw.substring(raw.indexOf(marker) + marker.length).trim() : raw.trim();
            window.pcmPost({ action: 'pcm_redirect_assistant_import', nonce: window.pcmGetCacheabilityNonce(), payload: payload })
                .then(function(res) {
                    requireSuccess(res, 'Unable to import redirect rules endpoint.');
                    showStatus(out, 'Import successful. Loading updated rules...', false);
                    if (res && res.success) {
                        return window.pcmPost({ action: 'pcm_redirect_assistant_list_rules', nonce: window.pcmGetCacheabilityNonce() });
                    }
                })
                .then(function(res) {
                    if (res) requireSuccess(res, 'Unable to refresh redirect rules endpoint.');
                    if (res && res.success && res.data) {
                        rulesBox.value = JSON.stringify(res.data.rules || [], null, 2);
                        setRulesFromJson(rulesBox.value, true);
                    }
                    showStatus(out, 'Rules imported and loaded successfully.', false);
                })
                .catch(function(error) {
                    showStatus(out, error.message || 'Import failed.', true);
                });
        });
    }

    // Initialize with default empty rule
    setRulesFromJson(rulesBox.value, true);
});
