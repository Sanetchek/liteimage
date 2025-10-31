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

    /**
     * AJAX helpers for clearing thumbnails
     */
    function initAjaxClearButtons() {
        if (typeof LiteImageAdmin === 'undefined') {
            return;
        }

        var btnLite = document.getElementById('liteimage-btn-clear-lite');
        var btnWp = document.getElementById('liteimage-btn-clear-wp');
        var noticeLite = document.getElementById('liteimage-clear-lite-notice');
        var noticeWp = document.getElementById('liteimage-clear-wp-notice');

        function setNotice(el, type, text) {
            if (!el) return;
            el.innerHTML = '<div class="notice ' + (type === 'error' ? 'notice-error' : 'notice-success') + '"><p>' + text + '</p></div>';
        }

        function clearNotice(el) {
            if (el) el.innerHTML = '';
        }

        function disable(btn, disabled) {
            if (btn) btn.disabled = !!disabled;
        }

        function post(action, nonce) {
            var data = new URLSearchParams();
            data.set('action', action);
            data.set('nonce', nonce);
            return fetch(LiteImageAdmin.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: data.toString()
            }).then(function(res) { return res.json(); });
        }

        if (btnLite) {
            btnLite.addEventListener('click', function(e) {
                e.preventDefault();
                clearNotice(noticeLite);
                disable(btnLite, true);
                setNotice(noticeLite, 'info', LiteImageAdmin.i18n.inProgress);
                post('liteimage_clear_thumbnails', LiteImageAdmin.nonceClearLite)
                    .then(function(json) {
                        if (json && json.success) {
                            setNotice(noticeLite, 'success', LiteImageAdmin.i18n.done + ': ' + (json.data && json.data.deleted ? json.data.deleted : 0));
                        } else {
                            setNotice(noticeLite, 'error', (json && json.data && json.data.message) ? json.data.message : LiteImageAdmin.i18n.error);
                        }
                    })
                    .catch(function() {
                        setNotice(noticeLite, 'error', LiteImageAdmin.i18n.error);
                    })
                    .finally(function() {
                        disable(btnLite, false);
                    });
            });
        }

        if (btnWp) {
            btnWp.addEventListener('click', function(e) {
                e.preventDefault();
                clearNotice(noticeWp);
                disable(btnWp, true);
                setNotice(noticeWp, 'info', LiteImageAdmin.i18n.inProgress);
                post('liteimage_clear_wp_thumbnails', LiteImageAdmin.nonceClearWp)
                    .then(function(json) {
                        if (json && json.success) {
                            setNotice(noticeWp, 'success', LiteImageAdmin.i18n.done + ': ' + (json.data && json.data.deleted ? json.data.deleted : 0));
                        } else {
                            setNotice(noticeWp, 'error', (json && json.data && json.data.message) ? json.data.message : LiteImageAdmin.i18n.error);
                        }
                    })
                    .catch(function() {
                        setNotice(noticeWp, 'error', LiteImageAdmin.i18n.error);
                    })
                    .finally(function() {
                        disable(btnWp, false);
                    });
            });
        }
    }

    function init() {
        initBTCAddressCopy();
        initAjaxClearButtons();
    }

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();

