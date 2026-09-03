// ErnsAuth SSO widget (Flow A, mandatory-username variant) for login.zetem.
// Explicit-only: never starts a challenge on page load, only on a real
// click, per CLIENT-INTEGRATION.md (ernsauth repo) -- an automatic start
// would put a login request into the approver's Pending Logins list for
// every prefetch/bookmark/crawler hit.
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var root = document.querySelector('[data-ernsauth-sso]');
        if (!root) return;

        var startUrl = root.getAttribute('data-start-url');
        var pollUrl = root.getAttribute('data-poll-url');
        var exchangeUrl = root.getAttribute('data-exchange-url');

        var usernameInput = root.querySelector('[data-ea-username]');
        var startBtn = root.querySelector('[data-ea-start]');
        var numberEl = root.querySelector('[data-ea-number]');
        var statusEl = root.querySelector('[data-ea-status]');
        var errorMessageEl = root.querySelector('[data-ea-error-message]');
        var restartBtns = root.querySelectorAll('[data-ea-restart]');

        var stepEls = {};
        root.querySelectorAll('[data-ea-step]').forEach(function (el) {
            stepEls[el.getAttribute('data-ea-step')] = el;
        });

        var pollTimer = null;

        function showStep(name) {
            Object.keys(stepEls).forEach(function (key) {
                stepEls[key].hidden = (key !== name);
            });
        }

        function stopPolling() {
            if (pollTimer) {
                clearTimeout(pollTimer);
                pollTimer = null;
            }
        }

        function csrfToken() {
            var meta = document.querySelector('meta[name="csrf-token"]');
            return meta ? meta.getAttribute('content') : '';
        }

        function showError(message) {
            stopPolling();
            errorMessageEl.textContent = message;
            showStep('error');
        }

        var ERROR_MESSAGES = {
            rate_limited: 'Too many attempts. Please wait a few minutes and try again.',
            disabled: 'ErnsAuth sign-in is not available right now.',
            upstream_unavailable: 'Could not reach ErnsAuth. Please try again shortly.',
            invalid_request: 'Please enter your username.',
            account_not_found: 'That username was not recognized.'
        };

        function messageFor(code) {
            return ERROR_MESSAGES[code] || 'Something went wrong. Please try again.';
        }

        function postJson(url, body) {
            return fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-CSRF-Token': csrfToken()
                },
                body: body
            }).then(function (res) {
                return res.json().then(function (data) {
                    return { ok: res.ok, data: data };
                });
            });
        }

        function getJson(url) {
            return fetch(url, { headers: { 'X-CSRF-Token': csrfToken() } })
                .then(function (res) { return res.json(); });
        }

        function startChallenge() {
            var username = (usernameInput.value || '').trim();
            if (!username) {
                showError(messageFor('invalid_request'));
                return;
            }

            startBtn.disabled = true;
            postJson(startUrl, 'username=' + encodeURIComponent(username))
                .then(function (result) {
                    startBtn.disabled = false;
                    if (!result.ok || result.data.error) {
                        showError(messageFor(result.data.error));
                        return;
                    }
                    numberEl.textContent = result.data.challenge_number;
                    statusEl.textContent = 'Waiting for approval...';
                    showStep('challenge');
                    schedulePoll();
                })
                .catch(function () {
                    startBtn.disabled = false;
                    showError(messageFor('upstream_unavailable'));
                });
        }

        function schedulePoll() {
            pollTimer = setTimeout(doPoll, 2000);
        }

        function doPoll() {
            getJson(pollUrl)
                .then(function (data) {
                    var status = data.status;
                    if (status === 'pending') {
                        schedulePoll();
                        return;
                    }
                    if (status === 'approved' && data.auth_code) {
                        statusEl.textContent = 'Approved -- signing you in...';
                        exchangeCode(data.auth_code);
                        return;
                    }
                    if (status === 'rejected') {
                        showError('The login request was declined.');
                        return;
                    }
                    if (status === 'expired') {
                        showError('The request expired. Please try again.');
                        return;
                    }
                    // not_found / error / anything unrecognized
                    showError(messageFor('upstream_unavailable'));
                })
                .catch(function () {
                    showError(messageFor('upstream_unavailable'));
                });
        }

        function exchangeCode(authCode) {
            postJson(exchangeUrl, 'auth_code=' + encodeURIComponent(authCode))
                .then(function (result) {
                    if (!result.ok || result.data.error) {
                        showError(messageFor(result.data.error));
                        return;
                    }
                    window.location.href = result.data.redirect || '/';
                })
                .catch(function () {
                    showError(messageFor('upstream_unavailable'));
                });
        }

        startBtn.addEventListener('click', startChallenge);
        restartBtns.forEach(function (btn) {
            btn.addEventListener('click', function () {
                stopPolling();
                showStep('username');
            });
        });
    });
})();
