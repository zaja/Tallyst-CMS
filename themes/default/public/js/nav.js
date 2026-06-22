/*
 * Tallyst default theme — navigation enhancement.
 *
 * Progressive enhancement only: with JS off the menu is a plain nested list and the
 * CSS :hover/:focus-within dropdown still works on desktop. This script adds the mobile
 * hamburger toggle and the click-to-open submenu accordion, keeping ARIA state in sync.
 *
 * Served statically with the theme (theme_asset('js/nav.js')); NOT an AssetMapper entry.
 */
(function () {
    'use strict';

    var MOBILE = '(max-width: 51.999rem)';

    document.addEventListener('DOMContentLoaded', function () {
        var header = document.querySelector('[data-nav]');
        if (!header) return;

        var toggle = header.querySelector('.nav-toggle');
        var nav = header.querySelector('.site-nav');

        // Hamburger: open/close the whole nav panel.
        if (toggle && nav) {
            toggle.addEventListener('click', function () {
                var open = header.classList.toggle('is-nav-open');
                toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
                toggle.setAttribute('aria-label', open ? 'Zatvori izbornik' : 'Otvori izbornik');
            });
        }

        // Submenu toggles: accordion on mobile, click-fallback for the dropdown on desktop.
        var toggles = header.querySelectorAll('.submenu-toggle');
        Array.prototype.forEach.call(toggles, function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                var li = btn.closest('.menu-item');
                if (!li) return;
                var open = li.classList.toggle('is-open');
                btn.setAttribute('aria-expanded', open ? 'true' : 'false');
            });
        });

        // Close the mobile panel on Escape and reset state when crossing to desktop.
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && header.classList.contains('is-nav-open')) {
                header.classList.remove('is-nav-open');
                if (toggle) {
                    toggle.setAttribute('aria-expanded', 'false');
                    toggle.setAttribute('aria-label', 'Otvori izbornik');
                    toggle.focus();
                }
            }
        });

        if (window.matchMedia) {
            window.matchMedia(MOBILE).addEventListener('change', function (mq) {
                if (!mq.matches) {
                    // back to desktop — clear mobile-only open states
                    header.classList.remove('is-nav-open');
                    if (toggle) {
                        toggle.setAttribute('aria-expanded', 'false');
                        toggle.setAttribute('aria-label', 'Otvori izbornik');
                    }
                    Array.prototype.forEach.call(toggles, function (btn) {
                        var li = btn.closest('.menu-item');
                        if (li) li.classList.remove('is-open');
                        btn.setAttribute('aria-expanded', 'false');
                    });
                }
            });
        }
    });
})();
