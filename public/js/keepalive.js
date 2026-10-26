/**
 * samlSSO keepalive, see ADR 0028.
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

    /**
     * Plugin public assets and routes share the plugin web path:
     * .../samlsso/js/keepalive(.min).js -> .../samlsso/front/keepalive.
     * An unexpected script name disables the keepalive instead of pinging a wrong URL.
     */
    var url = script.src.replace(/\/js\/keepalive(\.min)?\.js(\?.*)?$/, '/front/keepalive');
    if (url === script.src) {
        return;
    }

    /**
     * Ping interval in milliseconds, read on first use so the order of the meta tag
     * and this script in the page does not matter. Falls back to 30 seconds, which
     * is safe for the smallest possible timeout of 1 minute.
     */
    var interval = null;
    function getInterval() {
        if (interval === null) {
            var meta = document.querySelector('meta[name="samlsso-keepalive-interval"]');
            var seconds = meta ? parseInt(meta.getAttribute('content'), 10) : NaN;
            interval = (seconds > 0 ? seconds : 30) * 1000;
        }
        return interval;
    }

    /** The page load itself already counted as activity on the server. */
    var lastPing = Date.now();
    var trailing = null;

    /**
     * Sends the keepalive request. No X-Requested-With header on purpose:
     * LoginFlow::doAuth() skips AJAX requests (ADR 0011) and would not count it as activity.
     */
    function ping() {
        lastPing = Date.now();
        trailing = null;
        fetch(url, { credentials: 'same-origin', cache: 'no-store' }).catch(function () {});
    }

    /**
     * Pings right away if the interval has passed, otherwise once at the end of the
     * interval, so input shortly after a ping is reported as well.
     */
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

    /** Registers the activity listeners on a document. */
    function listen(target) {
        events.forEach(function (name) {
            target.addEventListener(name, onActivity, { capture: true, passive: true });
        });
    }

    listen(document);

    /**
     * Rich text editors (TinyMCE) run in an iframe, their events do not reach this document.
     * GLPI loads TinyMCE on demand, possibly after this script, so retry until it is available.
     */
    function listenEditor(editor) {
        editor.on('keydown input mousedown', onActivity);
    }

    var hookTimer = null;

    /** Hooks existing and future TinyMCE editors, returns false while TinyMCE is not loaded. */
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
