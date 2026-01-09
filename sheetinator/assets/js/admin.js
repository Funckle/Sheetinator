/**
 * Sheetinator Admin JavaScript
 *
 * @package Sheetinator
 */

(function($) {
    'use strict';

    /**
     * Sheetinator Admin Module
     */
    var SheetinatorAdmin = {
        /**
         * Initialize
         */
        init: function() {
            this.bindEvents();
        },

        /**
         * Bind event handlers
         */
        bindEvents: function() {
            // Toggle password visibility for client secret
            this.initPasswordToggle();

            // Confirm before resync
            this.initResyncConfirm();

            // Copy redirect URI to clipboard
            this.initCopyRedirectUri();
        },

        /**
         * Initialize password toggle for client secret field
         */
        initPasswordToggle: function() {
            var $secretField = $('#client_secret');
            if ($secretField.length === 0) {
                return;
            }

            // Add toggle button
            var $wrapper = $('<div class="password-toggle-wrapper"></div>');
            var $toggle = $('<button type="button" class="button button-secondary password-toggle" style="margin-left: 5px;">Show</button>');

            $secretField.wrap($wrapper);
            $secretField.after($toggle);

            $toggle.on('click', function(e) {
                e.preventDefault();
                if ($secretField.attr('type') === 'password') {
                    $secretField.attr('type', 'text');
                    $toggle.text('Hide');
                } else {
                    $secretField.attr('type', 'password');
                    $toggle.text('Show');
                }
            });
        },

        /**
         * Initialize resync confirmation
         */
        initResyncConfirm: function() {
            // This is handled inline in the HTML, but we can enhance it here
            $('a[href*="action=resync"]').on('click', function(e) {
                if (!confirm(sheetinatorAdmin.strings.confirmResync)) {
                    e.preventDefault();
                    return false;
                }
            });
        },

        /**
         * Initialize copy redirect URI functionality
         */
        initCopyRedirectUri: function() {
            var $codeElements = $('.setup-instructions code');

            $codeElements.each(function() {
                var $code = $(this);
                var text = $code.text();

                // Only add copy button for URLs
                if (text.indexOf('http') !== 0) {
                    return;
                }

                $code.css('cursor', 'pointer');
                $code.attr('title', 'Click to copy');

                $code.on('click', function() {
                    SheetinatorAdmin.copyToClipboard(text);
                    SheetinatorAdmin.showToast('Copied to clipboard!');
                });
            });
        },

        /**
         * Copy text to clipboard
         *
         * @param {string} text Text to copy
         */
        copyToClipboard: function(text) {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text);
            } else {
                // Fallback for older browsers
                var $temp = $('<textarea>');
                $('body').append($temp);
                $temp.val(text).select();
                document.execCommand('copy');
                $temp.remove();
            }
        },

        /**
         * Show a toast notification
         *
         * @param {string} message Message to display
         */
        showToast: function(message) {
            var $toast = $('<div class="sheetinator-toast">' + message + '</div>');

            $toast.css({
                'position': 'fixed',
                'bottom': '20px',
                'right': '20px',
                'background': '#1d2327',
                'color': '#fff',
                'padding': '10px 20px',
                'border-radius': '4px',
                'z-index': '99999',
                'opacity': '0',
                'transition': 'opacity 0.3s'
            });

            $('body').append($toast);

            // Fade in
            setTimeout(function() {
                $toast.css('opacity', '1');
            }, 10);

            // Fade out and remove
            setTimeout(function() {
                $toast.css('opacity', '0');
                setTimeout(function() {
                    $toast.remove();
                }, 300);
            }, 2000);
        }
    };

    // Initialize when DOM is ready
    $(document).ready(function() {
        SheetinatorAdmin.init();
    });

})(jQuery);
