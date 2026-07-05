import { Controller } from '@hotwired/stimulus';

/*
 * Makes a marked EA sidebar section (the SYSTEM group) collapsible. Default collapsed; the
 * open/closed choice is remembered across reloads in localStorage (per browser — enough for a
 * sidebar preference, no server round-trip). When you're ON a page inside the section (an active
 * child), it opens regardless so you can see where you are, without overwriting the stored choice.
 *
 * The controller wraps the EA menu (main_menu_wrapper block); it finds the section by a marker CSS
 * class on its label (set via MenuItem::section()->setCssClass(...)), since EA section MenuItems
 * don't expose setHtmlAttributes. Other sections (Content/Sales/Navigation/Appearance/Modules) and
 * the version footer / maintenance banner are untouched. Turbo-safe: re-inits cleanly per connect.
 */
export default class extends Controller {
    static values = {
        sectionClass: String, // marker class on the section's label span
        storageKey: String,   // localStorage key for the collapsed state
        ariaLabel: String,    // translated aria-label for the toggle
    };

    connect() {
        const label = this.element.querySelector('.' + this.sectionClassValue);
        if (!label) {
            return;
        }
        this.header = label.closest('li.menu-header');
        if (!this.header) {
            return;
        }

        // Items in this section = following siblings up to the next section header.
        this.items = [];
        let el = this.header.nextElementSibling;
        while (el && !el.classList.contains('menu-header')) {
            this.items.push(el);
            el = el.nextElementSibling;
        }
        if (this.items.length === 0) {
            return;
        }

        // Turn the header into a toggle.
        this.contents = this.header.querySelector('.menu-header-contents') || this.header;
        this.contents.setAttribute('role', 'button');
        this.contents.setAttribute('tabindex', '0');
        if (this.ariaLabelValue) {
            this.contents.setAttribute('aria-label', this.ariaLabelValue);
        }
        this.contents.style.cursor = 'pointer';

        this.caret = document.createElement('span');
        this.caret.className = 'menu-collapse-caret';
        this.caret.setAttribute('aria-hidden', 'true');
        this.caret.textContent = '▾';
        this.contents.appendChild(this.caret);

        this.onToggle = (e) => { e.preventDefault(); this.toggle(); };
        this.onKey = (e) => {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); this.toggle(); }
        };
        this.contents.addEventListener('click', this.onToggle);
        this.contents.addEventListener('keydown', this.onKey);

        // Initial state: open if a child is active; else the stored choice; else collapsed (default).
        const hasActive = this.items.some(
            (li) => li.classList.contains('active') || li.querySelector('.active') !== null,
        );
        const stored = this.readState();
        const collapsed = hasActive ? false : (stored === null ? true : stored);
        this.apply(collapsed);
    }

    disconnect() {
        if (this.contents) {
            this.contents.removeEventListener('click', this.onToggle);
            this.contents.removeEventListener('keydown', this.onKey);
        }
        if (this.caret) {
            this.caret.remove();
        }
    }

    toggle() {
        // Currently expanded → collapse, and vice versa.
        const collapsed = this.contents.getAttribute('aria-expanded') === 'true';
        this.apply(collapsed);
        this.writeState(collapsed);
    }

    apply(collapsed) {
        this.items.forEach((li) => li.classList.toggle('menu-section-collapsed', collapsed));
        this.contents.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
    }

    readState() {
        try {
            const v = localStorage.getItem(this.storageKeyValue);
            return v === null ? null : v === 'true';
        } catch {
            return null;
        }
    }

    writeState(collapsed) {
        try {
            localStorage.setItem(this.storageKeyValue, collapsed ? 'true' : 'false');
        } catch {
            /* localStorage unavailable (private mode / blocked) — non-persistent, still works in-session */
        }
    }
}
