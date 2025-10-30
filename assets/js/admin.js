/**
 * LiteImage Admin JavaScript
 *
 * @package LiteImage
 * @since 3.2.0
 */

(function() {
    'use strict';

    /**
     * Copy Bitcoin address to clipboard
     */
    function initBTCAddressCopy() {
        const addressInput = document.getElementById('btc-address');
        const notice = document.getElementById('copy-notice');

        if (!addressInput || !notice) {
            return;
        }

        addressInput.addEventListener('click', function() {
            const address = this.value;

            // Modern Clipboard API
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(address)
                    .then(function() {
                        showCopyNotice(notice);
                    })
                    .catch(function(err) {
                        console.error('Failed to copy:', err);
                        // Fallback to old method
                        fallbackCopy(addressInput, notice);
                    });
            } else {
                // Fallback for older browsers
                fallbackCopy(addressInput, notice);
            }
        });
    }

    /**
     * Show copy success notice
     *
     * @param {HTMLElement} notice Notice element
     */
    function showCopyNotice(notice) {
        notice.style.display = 'inline';
        setTimeout(function() {
            notice.style.display = 'none';
        }, 2000);
    }

    /**
     * Fallback copy method for older browsers
     *
     * @param {HTMLInputElement} input Input element
     * @param {HTMLElement} notice Notice element
     */
    function fallbackCopy(input, notice) {
        input.select();
        try {
            document.execCommand('copy');
            showCopyNotice(notice);
        } catch(err) {
            console.error('Failed to copy:', err);
        }
    }

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initBTCAddressCopy);
    } else {
        initBTCAddressCopy();
    }
})();

