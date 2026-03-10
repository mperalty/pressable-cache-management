/**
 * Pressable Cache Management - Shared JS Utilities
 *
 * Consolidates duplicated utility functions from across the plugin:
 * - escapeHtml (was in deep-dive.js, redirects-tab.js, pcm-post.js, settings.js)
 * - showModal (was in column.js)
 *
 * Public API (on window):
 *   window.pcmEscapeHtml(value)              - Escape HTML special characters
 *   window.pcmShowModal(message, triggerEl)   - Show a simple alert modal
 */
(function(window, document) {
    'use strict';

    window.pcmEscapeHtml = window.pcmEscapeHtml || function(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    };

    window.pcmShowModal = window.pcmShowModal || function(msg, triggerEl) {
        var overlayId = 'pcm-shared-modal-overlay';
        var overlay = document.getElementById(overlayId);
        if (!overlay) {
            overlay = document.createElement('div');
            overlay.id = overlayId;
            overlay.className = 'pcm-modal-overlay';

            overlay.innerHTML =
                '<div id="pcm-shared-modal-dialog" class="pcm-modal-dialog">' +
                '<div class="pcm-modal-accent"></div>' +
                '<p id="pcm-shared-modal-msg" class="pcm-modal-message"></p>' +
                '<button id="pcm-shared-modal-ok" class="pcm-modal-ok" type="button">OK</button>' +
                '</div>';

            document.body.appendChild(overlay);

            var dialog = document.getElementById('pcm-shared-modal-dialog');

            function closeModal() {
                overlay.style.display = 'none';
                if (window.pcmModalA11y) window.pcmModalA11y.onClose(overlay);
            }

            document.getElementById('pcm-shared-modal-ok').addEventListener('click', closeModal);
            overlay.addEventListener('click', function(e) {
                if (e.target === overlay) closeModal();
            });

            if (window.pcmModalA11y) {
                window.pcmModalA11y.setup({
                    overlay: overlay,
                    dialog: dialog,
                    labelId: 'pcm-shared-modal-msg',
                    onClose: closeModal
                });
            }
        }

        document.getElementById('pcm-shared-modal-msg').textContent = msg;
        overlay.style.display = 'flex';
        if (window.pcmModalA11y) {
            window.pcmModalA11y.onOpen(overlay, document.getElementById('pcm-shared-modal-dialog'), triggerEl);
        }
    };

})(window, document);
