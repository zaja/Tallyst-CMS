/*
 * Tallyst default theme — navigation enhancement.
 *
 * Progressive enhancement only: with JS off the menu is a plain nested list and the
 * CSS :hover/:focus-within dropdown still works on desktop. This script adds the mobile
 * hamburger toggle and the click-to-open submenu accordion, keeping ARIA state in sync.
 *
 * IMPORTANT — works WITH Turbo. The app runs @symfony/ux-turbo (turbo-core, eager), which
 * starts @hotwired/turbo and SWAPS <body> on navigation. So we must NOT cache element
 * references and must NOT gate binding on DOMContentLoaded (it fires once per full document
 * load, never after a Turbo body swap). Instead we delegate from `document`: a single
 * listener bound once on a node that survives every swap, resolving the clicked control at
 * click time. An idempotent guard stops a re-executed body script from double-binding.
 *
 * Served statically with the theme (theme_asset('js/nav.js')); NOT an AssetMapper entry.
 */
(function () {
    'use strict';

    if (window.__tallystNavInit) {
        return;
    }
    window.__tallystNavInit = true;

    var MOBILE = '(max-width: 51.999rem)';

    function header() {
        return document.querySelector('[data-nav]');
    }

    function setToggle(btn, open) {
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        btn.setAttribute('aria-label', open ? 'Zatvori izbornik' : 'Otvori izbornik');
    }

    // Delegated click: resolve the control at click time so it survives Turbo body swaps.
    document.addEventListener('click', function (e) {
        if (!e.target || !e.target.closest) {
            return;
        }

        var navToggle = e.target.closest('.nav-toggle');
        if (navToggle) {
            var h = navToggle.closest('[data-nav]') || header();
            if (h) {
                setToggle(navToggle, h.classList.toggle('is-nav-open'));
            }
            return;
        }

        var subToggle = e.target.closest('.submenu-toggle');
        if (subToggle) {
            e.preventDefault();
            var li = subToggle.closest('.menu-item');
            if (li) {
                subToggle.setAttribute('aria-expanded', li.classList.toggle('is-open') ? 'true' : 'false');
            }
        }
    });

    // Escape closes the open mobile panel.
    document.addEventListener('keydown', function (e) {
        if ('Escape' !== e.key) {
            return;
        }
        var h = header();
        if (h && h.classList.contains('is-nav-open')) {
            h.classList.remove('is-nav-open');
            var t = h.querySelector('.nav-toggle');
            if (t) {
                setToggle(t, false);
                t.focus();
            }
        }
    });

    // Crossing back to desktop clears mobile-only open state.
    if (window.matchMedia) {
        var mql = window.matchMedia(MOBILE);
        var onChange = function (mq) {
            if (mq.matches) {
                return;
            }
            var h = header();
            if (!h) {
                return;
            }
            h.classList.remove('is-nav-open');
            var t = h.querySelector('.nav-toggle');
            if (t) {
                setToggle(t, false);
            }
            Array.prototype.forEach.call(h.querySelectorAll('.menu-item.is-open'), function (li) {
                li.classList.remove('is-open');
            });
            Array.prototype.forEach.call(h.querySelectorAll('.submenu-toggle'), function (b) {
                b.setAttribute('aria-expanded', 'false');
            });
        };
        if (mql.addEventListener) {
            mql.addEventListener('change', onChange);
        } else if (mql.addListener) {
            mql.addListener(onChange);
        }
    }
})();
