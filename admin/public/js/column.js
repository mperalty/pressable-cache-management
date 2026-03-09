/**
 * Pressable Cache Management - Flush cache for individual page column
 * Branded modal popup replaces browser alert()
 *
 * Public API: none (all behaviour is event-driven)
 */
(function(window, document) {
    'use strict';

    /* ── Branded modal (injected once) ─────────────────────────────────────── */
    function pcmEnsureModal() {
        if (document.getElementById('pcm-col-modal-overlay')) return;

        var overlay = document.createElement('div');
        overlay.id  = 'pcm-col-modal-overlay';
        overlay.style.cssText =
            'display:none;position:fixed;inset:0;background:rgba(4,0,36,.45);'
            + 'z-index:999999;align-items:center;justify-content:center;';

        overlay.innerHTML =
            '<div id="pcm-col-modal-dialog" style="background:#fff;border-radius:12px;padding:28px 32px;max-width:420px;width:90%;'
            + 'box-shadow:0 8px 40px rgba(4,0,36,.18);font-family:sans-serif;position:relative;">'
            + '<div style="width:48px;height:4px;background:#03fcc2;border-radius:4px;margin-bottom:16px;"></div>'
            + '<p id="pcm-col-modal-msg" style="margin:0 0 22px;font-size:14px;color:#040024;line-height:1.6;"></p>'
            + '<button id="pcm-col-modal-ok" style="background:#dd3a03;color:#fff;border:none;border-radius:8px;'
            + 'padding:10px 28px;font-size:13.5px;font-weight:700;cursor:pointer;font-family:sans-serif;'
            + 'transition:background .2s;">OK</button>'
            + '</div>';

        document.body.appendChild(overlay);

        overlay.style.display = 'flex'; overlay.style.display = 'none'; // force style parse

        var dialog = document.getElementById('pcm-col-modal-dialog');

        function closeColumnModal() {
            overlay.style.display = 'none';
            window.pcmModalA11y.onClose(overlay);
        }

        document.getElementById('pcm-col-modal-ok').addEventListener('click', closeColumnModal);
        overlay.addEventListener('click', function(e) {
            if (e.target === overlay) closeColumnModal();
        });

        window.pcmModalA11y.setup({
            overlay: overlay,
            dialog: dialog,
            labelId: 'pcm-col-modal-msg',
            onClose: closeColumnModal
        });

        // hover on OK button
        var okBtn = document.getElementById('pcm-col-modal-ok');
        okBtn.addEventListener('mouseenter', function() { okBtn.style.background = '#b82f00'; });
        okBtn.addEventListener('mouseleave', function() { okBtn.style.background = '#dd3a03'; });
    }

    function pcmShowColumnModal(msg, triggerEl) {
        pcmEnsureModal();
        document.getElementById('pcm-col-modal-msg').textContent = msg;
        var overlay = document.getElementById('pcm-col-modal-overlay');
        var dialog  = document.getElementById('pcm-col-modal-dialog');
        overlay.style.display = 'flex';
        window.pcmModalA11y.onOpen(overlay, dialog, triggerEl);
    }

    function flush_object_cache_column_button_action() {
        jQuery(document).ready(function($) {
            $("a[id^='flush-object-cache-url']").on('click', function(e) {
                e.preventDefault();
                var triggerEl = e.currentTarget;

                // Prevent duplicate requests while AJAX is in-flight
                if ($(triggerEl).data('pcm-busy')) return false;
                $(triggerEl).data('pcm-busy', true);
                $(triggerEl).css({ cursor: 'wait', opacity: 0.6, pointerEvents: 'none' });

                var post_id = $(triggerEl).attr('data-id');
                var nonce   = $(triggerEl).attr('data-nonce');

                // Retrieve the post title from the row for contextual error messages
                var $row = $(triggerEl).closest('tr');
                var postTitle = $row.find('.row-title').text() || $row.find('.column-title a').first().text() || ('post #' + post_id);

                $.ajax({
                    type:     'GET',
                    url:      ajaxurl,
                    data:     { action: 'pcm_flush_object_cache_column', id: post_id, nonce: nonce },
                    dataType: 'json',
                    cache:    false,
                    timeout:  15000,
                    success: function(data) {
                        $('#flush-object-cache-url-' + post_id).css({ cursor: 'pointer', opacity: 1, pointerEvents: 'auto' }).data('pcm-busy', false);
                        if (typeof data.success !== 'undefined' && data.success === true) {
                            pcmShowColumnModal('Batcache flushed successfully \u2705', triggerEl);
                        } else {
                            pcmShowColumnModal("Failed to flush cache for '" + postTitle + "'. The server returned an unexpected response.", triggerEl);
                        }
                    },
                    error: function(jqXHR, textStatus) {
                        $('#flush-object-cache-url-' + post_id).css({ cursor: 'pointer', opacity: 1, pointerEvents: 'auto' }).data('pcm-busy', false);
                        var msg;
                        if (textStatus === 'timeout') {
                            msg = "Flush request for '" + postTitle + "' timed out. Please try again.";
                        } else if (jqXHR.status >= 500) {
                            msg = "Server error while flushing cache for '" + postTitle + "'. Check your PHP error logs.";
                        } else if (jqXHR.status === 403) {
                            msg = "Permission denied flushing cache for '" + postTitle + "'. Reload the page and try again.";
                        } else {
                            msg = "Could not connect. Check your site is accessible.";
                        }
                        pcmShowColumnModal(msg, triggerEl);
                    }
                });
                return false;
            });
        });
    }

    // Use addEventListener instead of window.onload chaining to avoid globals
    document.addEventListener('DOMContentLoaded', flush_object_cache_column_button_action);

})(window, document);
