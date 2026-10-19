// App-wide behaviour. No inline scripts anywhere (strict CSP), so pages opt in via data- attributes.
(function () {
    'use strict';

    var $ = function (sel, root) { return (root || document).querySelector(sel); };
    var $$ = function (sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); };

    // ---------------------------------------------------------------- theme toggle
    $$('[data-theme-toggle]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var next = document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
            document.documentElement.setAttribute('data-bs-theme', next);
            try { localStorage.setItem('theme', next); } catch (e) { /* ignore */ }
            document.dispatchEvent(new CustomEvent('themechange', { detail: next }));
        });
    });

    // ---------------------------------------------------------------- widths from data (CSP blocks style="")
    $$('[data-width]').forEach(function (el) {
        var w = parseFloat(el.getAttribute('data-width'));
        if (!isNaN(w)) { el.style.width = Math.max(0, Math.min(100, w)) + '%'; }
    });

    // ---------------------------------------------------------------- forms
    // Prevent double submits and show a spinner.
    $$('form[data-once]').forEach(function (form) {
        form.addEventListener('submit', function (ev) {
            if (form.dataset.submitted) { ev.preventDefault(); return; }
            form.dataset.submitted = '1';
            var btn = $('button[type=submit]', form);
            if (btn) {
                btn.disabled = true;
                var sp = $('.spinner-border', btn);
                if (sp) { sp.classList.remove('d-none'); }
            }
        });
    });

    $$('[data-autosubmit]').forEach(function (el) {
        el.addEventListener('change', function () { if (el.form) { el.form.submit(); } });
    });

    $$('[data-toggle-password]').forEach(function (btn) {
        var input = $(btn.getAttribute('data-toggle-password'));
        if (!input) { return; }
        btn.addEventListener('click', function () {
            var show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
            var i = $('i', btn);
            if (i) { i.className = show ? 'bi bi-eye-slash' : 'bi bi-eye'; }
        });
    });

    var caps = $('[data-caps-warning]');
    if (caps) {
        $$('input[type=password], input[type=text]#password').forEach(function (input) {
            ['keydown', 'keyup'].forEach(function (evt) {
                input.addEventListener(evt, function (e) {
                    if (e.getModifierState) { caps.classList.toggle('d-none', !e.getModifierState('CapsLock')); }
                });
            });
        });
    }

    // Password strength (length-led: length matters far more than symbol rules).
    $$('[data-pw-meter]').forEach(function (meter) {
        var input = $(meter.getAttribute('data-pw-meter'));
        var min = parseInt(meter.getAttribute('data-min'), 10) || 12;
        var text = $('.pw-text', meter);
        if (!input) { return; }
        input.addEventListener('input', function () {
            var v = input.value, score = 0, label = '';
            if (v.length) {
                var classes = [/[a-z]/, /[A-Z]/, /\d/, /[^A-Za-z0-9]/].filter(function (r) { return r.test(v); }).length;
                var uniq = new Set(v).size;
                if (v.length < min) { score = 1; label = (min - v.length) + ' more character' + (min - v.length === 1 ? '' : 's') + ' needed'; }
                else if (uniq < 6) { score = 1; label = 'Too repetitive'; }
                else if (v.length >= 20 || (v.length >= 16 && classes >= 3)) { score = 4; label = 'Strong'; }
                else if (v.length >= 14 || classes >= 3) { score = 3; label = 'Good'; }
                else { score = 2; label = 'Fair — longer is stronger'; }
            }
            meter.setAttribute('data-score', String(score));
            if (text) { text.textContent = label; }
        });
    });

    // ---------------------------------------------------------------- IST clock in the top bar
    var clock = $('[data-ist-clock]');
    if (clock && window.Intl) {
        var fmt = new Intl.DateTimeFormat('en-GB', { timeZone: 'Asia/Kolkata', hour: '2-digit', minute: '2-digit', hour12: false });
        var tick = function () { clock.textContent = fmt.format(new Date()); };
        tick();
        setInterval(tick, 15000);
    }

    // ---------------------------------------------------------------- session timeout warning
    var body = document.body;
    var left = parseInt(body.getAttribute('data-session-seconds') || '', 10);
    var modalEl = $('#sessionModal');
    if (!isNaN(left) && left > 0 && modalEl && window.bootstrap) {
        var WARN = 300;
        var deadline = Date.now() + left * 1000;
        var modal = new bootstrap.Modal(modalEl);
        var countdown = $('[data-session-countdown]', modalEl);
        var shown = false;
        var loginUrl = body.getAttribute('data-login-url');

        setInterval(function () {
            var secs = Math.round((deadline - Date.now()) / 1000);
            if (secs <= 0) { window.location.href = loginUrl; return; }
            if (secs <= WARN) {
                if (!shown) { modal.show(); shown = true; }
                countdown.textContent = Math.floor(secs / 60) + ':' + String(secs % 60).padStart(2, '0');
            }
        }, 1000);

        $('[data-session-extend]', modalEl).addEventListener('click', function () {
            var token = $('meta[name=csrf-token]');
            fetch(body.getAttribute('data-session-extend-url'), {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'X-CSRF-Token': token ? token.content : '', 'Accept': 'application/json' }
            }).then(function (r) {
                var type = r.headers.get('Content-Type') || '';
                if (!r.ok || type.indexOf('application/json') === -1) { throw new Error('expired'); }
                return r.json();
            }).then(function (d) {
                deadline = Date.now() + d.seconds_left * 1000;
                shown = false;
                modal.hide();
            }).catch(function () { window.location.href = loginUrl; });
        });
    }
})();
