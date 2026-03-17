/**
 * Pressable Cache Management - Flush cache for individual page column
 * Uses shared pcmShowModal from pcm-utils.js
 *
 * Public API: none (all behaviour is event-driven)
 */
(function(window, document) {
    'use strict';

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
                            window.pcmShowModal('Batcache flushed successfully \u2705', triggerEl);
                        } else {
                            window.pcmShowModal("Failed to flush cache for '" + postTitle + "'. The server returned an unexpected response.", triggerEl);
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
                        window.pcmShowModal(msg, triggerEl);
                    }
                });
                return false;
            });
        });
    }

    // Use addEventListener instead of window.onload chaining to avoid globals
    document.addEventListener('DOMContentLoaded', flush_object_cache_column_button_action);

})(window, document);
