/**
 * Pressable Cache Management - Modal accessibility helpers
 *
 * Provides focus trapping, Escape-key closing, and ARIA attribute setup
 * for branded confirmation/alert modals.
 */
window.pcmModalA11y = (function() {
    'use strict';

    var FOCUSABLE = 'a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])';

    /**
     * Set up accessibility attributes and behaviour on a modal.
     *
     * @param {Object} opts
     * @param {HTMLElement} opts.overlay   - The full-screen overlay element.
     * @param {HTMLElement} opts.dialog    - The inner dialog panel.
     * @param {string}      opts.labelId  - The id of the element that labels the dialog.
     * @param {Function}    opts.onClose  - Called when the user presses Escape.
     */
    function setup(opts) {
        var dialog  = opts.dialog;
        var overlay = opts.overlay;

        dialog.setAttribute('role', 'alertdialog');
        dialog.setAttribute('aria-modal', 'true');
        dialog.setAttribute('aria-labelledby', opts.labelId);

        // Make the dialog focusable so we can send focus to it as a fallback.
        if (!dialog.getAttribute('tabindex')) {
            dialog.setAttribute('tabindex', '-1');
        }

        overlay.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' || e.keyCode === 27) {
                e.stopPropagation();
                opts.onClose();
                return;
            }

            if (e.key === 'Tab' || e.keyCode === 9) {
                trapFocus(dialog, e);
            }
        });
    }

    /**
     * Move focus into the dialog when it is shown.
     * Focuses the first focusable element, or the dialog container itself.
     * Returns nothing; stores `triggerEl` on the overlay for later restoration.
     *
     * @param {HTMLElement} overlay    - The overlay element (used to store trigger ref).
     * @param {HTMLElement} dialog     - The inner dialog panel.
     * @param {HTMLElement} triggerEl  - The element that triggered the modal open.
     */
    function onOpen(overlay, dialog, triggerEl) {
        overlay._pcmTrigger = triggerEl || document.activeElement;

        var first = dialog.querySelectorAll(FOCUSABLE);
        if (first.length) {
            first[0].focus();
        } else {
            dialog.focus();
        }
    }

    /**
     * Restore focus to the element that opened the modal.
     *
     * @param {HTMLElement} overlay - The overlay element.
     */
    function onClose(overlay) {
        var trigger = overlay._pcmTrigger;
        overlay._pcmTrigger = null;

        if (trigger && typeof trigger.focus === 'function') {
            trigger.focus();
        }
    }

    /* ── internal ────────────────────────────────────────────────────────── */

    function trapFocus(dialog, e) {
        var focusable = dialog.querySelectorAll(FOCUSABLE);
        if (!focusable.length) return;

        var first = focusable[0];
        var last  = focusable[focusable.length - 1];

        if (e.shiftKey) {
            if (document.activeElement === first) {
                e.preventDefault();
                last.focus();
            }
        } else {
            if (document.activeElement === last) {
                e.preventDefault();
                first.focus();
            }
        }
    }

    return { setup: setup, onOpen: onOpen, onClose: onClose };
})();
