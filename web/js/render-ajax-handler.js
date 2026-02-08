/**
 * AJAX Handler for Render Array AJAX Elements
 *
 * Automatically handles AJAX links and buttons created with the render array system.
 *
 * @author Evangelos Rokas
 * @version 1.0
 * @date February 2026
 */

(function() {
    'use strict';

    /**
     * Handle AJAX link clicks
     */
    document.addEventListener('click', function(e) {
        const link = e.target.closest('.ajax-link');
        if (!link) return;

        e.preventDefault();

        const url = link.getAttribute('data-ajax-url');
        const method = link.getAttribute('data-ajax-method') || 'GET';
        const target = link.getAttribute('data-ajax-target');
        const callback = link.getAttribute('data-ajax-callback');
        const confirm = link.getAttribute('data-ajax-confirm');

        // Confirmation dialog
        if (confirm && !window.confirm(confirm)) {
            return;
        }

        // Add loading state
        link.classList.add('ajax-loading');
        link.setAttribute('disabled', 'disabled');

        // Make AJAX request
        makeAjaxRequest(url, method, null, function(response, error) {
            // Remove loading state
            link.classList.remove('ajax-loading');
            link.removeAttribute('disabled');

            if (error) {
                console.error('AJAX Error:', error);
                if (typeof window.showAjaxError === 'function') {
                    window.showAjaxError(error);
                }
                return;
            }

            // Update target element if specified
            if (target) {
                const targetElement = document.querySelector(target);
                if (targetElement) {
                    targetElement.innerHTML = response;
                }
            }

            // Call callback function if specified
            if (callback && typeof window[callback] === 'function') {
                window[callback](response, link);
            }
        });
    });

    /**
     * Handle AJAX button clicks
     */
    document.addEventListener('click', function(e) {
        const button = e.target.closest('.ajax-button');
        if (!button) return;

        e.preventDefault();

        const url = button.getAttribute('data-ajax-url');
        const method = button.getAttribute('data-ajax-method') || 'POST';
        const target = button.getAttribute('data-ajax-target');
        const callback = button.getAttribute('data-ajax-callback');
        const confirm = button.getAttribute('data-ajax-confirm');
        const dataAttr = button.getAttribute('data-ajax-data');
        const data = dataAttr ? JSON.parse(dataAttr) : null;

        // Confirmation dialog
        if (confirm && !window.confirm(confirm)) {
            return;
        }

        // Add loading state
        button.classList.add('ajax-loading');
        button.setAttribute('disabled', 'disabled');
        const originalText = button.innerHTML;
        button.innerHTML = '<span class="spinner">⏳</span> ' + originalText;

        // Make AJAX request
        makeAjaxRequest(url, method, data, function(response, error) {
            // Remove loading state
            button.classList.remove('ajax-loading');
            button.removeAttribute('disabled');
            button.innerHTML = originalText;

            if (error) {
                console.error('AJAX Error:', error);
                if (typeof window.showAjaxError === 'function') {
                    window.showAjaxError(error);
                }
                return;
            }

            // Update target element if specified
            if (target) {
                const targetElement = document.querySelector(target);
                if (targetElement) {
                    targetElement.innerHTML = response;
                }
            }

            // Call callback function if specified
            if (callback && typeof window[callback] === 'function') {
                window[callback](response, button);
            }
        });
    });

    /**
     * Make AJAX request
     *
     * @param {string} url Request URL
     * @param {string} method HTTP method
     * @param {object|null} data Request data
     * @param {function} callback Callback function
     */
    function makeAjaxRequest(url, method, data, callback) {
        const xhr = new XMLHttpRequest();
        xhr.open(method, url, true);

        // Set headers
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

        if (method === 'POST' || method === 'PUT' || method === 'PATCH') {
            xhr.setRequestHeader('Content-Type', 'application/json');
        }

        xhr.onload = function() {
            if (xhr.status >= 200 && xhr.status < 300) {
                try {
                    // Try to parse as JSON
                    const response = JSON.parse(xhr.responseText);
                    callback(response, null);
                } catch (e) {
                    // Not JSON, return as text
                    callback(xhr.responseText, null);
                }
            } else {
                callback(null, 'HTTP Error: ' + xhr.status);
            }
        };

        xhr.onerror = function() {
            callback(null, 'Network Error');
        };

        // Send request
        if (data) {
            xhr.send(JSON.stringify(data));
        } else {
            xhr.send();
        }
    }

    /**
     * Public API
     */
    window.RenderAjaxHandler = {
        version: '1.0',

        /**
         * Manual AJAX request
         */
        request: function(url, method, data, callback) {
            makeAjaxRequest(url, method, data, callback);
        }
    };

    console.log('Render AJAX Handler initialized (v1.0)');
})();
