/**
 * samlSSO keepalive
 *
 * The inactivity timeout only sees full page requests. Typing a long ticket
 * answer causes no such requests, so users were logged out on submit and lost
 * their text. This script reports user input to the server, at most once per
 * interval. Without input no request is sent, so idle sessions still time out.
 *
 * Loaded by setup.php for authenticated users when an inactivity timeout is configured.
 * The interval (seconds) is provided by the meta tag "samlsso-keepalive-interval" and is
 * half the configured timeout, so a ping always arrives before the session times out.
 */
(function () {
    'use strict';

    var script = document.currentScript;
    if (!script || !script.src || !window.fetch) {
        return;
    }

    // Plugin web path is shared by public assets and routes: .../samlsso/js/keepalive(.min).js -> .../samlsso/front/keepalive
    var url = script.src.replace(/\/js\/keepalive(\.min)?\.js(\?.*)?$/, '/front/keepalive');
    if (url === script.src) {
        return;
    }

    // Read on first use so the order of the meta tag and this script in the page does not matter.
    var interval = null;
    function getInterval() {
        if (interval === null) {
            var meta = document.querySelector('meta[name="samlsso-keepalive-interval"]');
            var seconds = meta ? parseInt(meta.getAttribute('content'), 10) : NaN;
            interval = (seconds > 0 ? seconds : 30) * 1000;
        }
        return interval;
    }

    var lastPing = Date.now(); // The page load itself already counted as activity.
    var trailing = null;

    function ping() {
        lastPing = Date.now();
        trailing = null;
        // No X-Requested-With header on purpose: doAuth() skips AJAX requests and
        // would not count this request as activity.
        fetch(url, { credentials: 'same-origin', cache: 'no-store' }).catch(function () {});
    }

    // Ping right away if the interval has passed, otherwise once at the end of the
    // interval, so input shortly after a ping is reported as well.
    function onActivity() {
        var wait = getInterval() - (Date.now() - lastPing);
        if (wait <= 0) {
            if (trailing !== null) {
                clearTimeout(trailing);
            }
            ping();
        } else if (trailing === null) {
            trailing = setTimeout(ping, wait);
        }
    }

    var events = ['keydown', 'input', 'mousedown', 'touchstart'];

    function listen(target) {
        events.forEach(function (name) {
            target.addEventListener(name, onActivity, { capture: true, passive: true });
        });
    }

    listen(document);

    // Rich text editors (TinyMCE) run in an iframe, their events do not reach this document.
    // GLPI loads TinyMCE on demand, possibly after this script, so retry until it is available.
    function listenEditor(editor) {
        editor.on('keydown input mousedown', onActivity);
    }

    var hookTimer = null;
    function hookTinymce() {
        if (!window.tinymce || typeof window.tinymce.on !== 'function') {
            return false;
        }
        (window.tinymce.get() || []).forEach(listenEditor);
        window.tinymce.on('AddEditor', function (e) {
            listenEditor(e.editor);
        });
        if (hookTimer !== null) {
            clearInterval(hookTimer);
        }
        return true;
    }

    document.addEventListener('DOMContentLoaded', function () {
        if (!hookTinymce()) {
            hookTimer = setInterval(hookTinymce, 2000);
        }
    });
})();
