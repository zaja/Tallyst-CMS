# Changelog

All notable changes to Tallyst are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and Tallyst adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html)
from v1.0.0 on. Semver is the API contract to the add-on ecosystem: a `Fixed`
change is a PATCH, an `Added` or `Changed` change is a MINOR, and a breaking
core-API change is a MAJOR (flagged ⚠).

## [Unreleased]

## [1.6.2] — 2026-07-07

### Changed

- **The demo now sets up the top bar** (an announcement plus GitHub/YouTube/X social
  icons) and clears it again on removal — matching how it manages the footer, so a demo
  install always presents a clean front.

### Fixed

- **The installer and the other CLI commands now output English** (they were partly
  Croatian). The worker-setup instructions point to `docs/INSTALL.md` (not an internal
  file) and now cover systemd, cron, and supervisor rather than assuming systemd.
- **Fresh-install content, the maintenance message, the order thank-you message, and the
  demo team names are now English** (they were Croatian).

## [1.6.1] — 2026-07-06

### Fixed

- **A fresh install (`bin/tallyst-setup`) failed on `cache:clear`** because the production
  dependencies were installed while the environment still defaulted to `dev` — setup (and
  upgrade) now run in the production environment.

## [1.6.0] — 2026-07-06

### Changed

- **Install and update are each a single, server-agnostic command** — `bin/tallyst-setup`
  (after `git clone`) and `bin/tallyst-upgrade`. They auto-detect your PHP 8.5+ binary and
  Composer, so nothing about the host is assumed. Git clone is the only supported install
  method, which makes every upgrade a clean `git checkout` (no bridge, no re-download).

### Fixed

- **The upgrade docs no longer show a confusing `vX.Y.Z` version placeholder** — the upgrade
  command defaults to the latest release, so no version needs to be typed.

### Removed

- **The `composer create-project` install path** (and its now-dead post-create hook) —
  install with `git clone` instead.

## [1.5.1] — 2026-07-05

### Changed

- **The public front-end no longer loads admin/editor JavaScript** (Chart.js, the
  Tiptap editor, the FilePond uploader) — about 118 KiB smaller on public pages.

### Fixed

- **Demo import replaces the default home page with the demo landing, and removing the
  demo restores the default home** (previously the home page was skipped on import, and
  left without a proper page after removal).

## [1.5.0] — 2026-07-05

### Added

- **Modified date column on the Pages and Posts lists** (sortable), showing when each
  was last edited.
- **Admin logo and favicon** (Branding settings) to white-label the back-office, plus an
  option to hide the Demo content link in the sidebar.
- **Previous/next post navigation** at the bottom of blog posts (chronological, with a
  thumbnail and title).

### Changed

- **List row actions (Edit, Preview, Delete) are now compact icon buttons with
  tooltips**, consistent across all admin lists.
- **Settings tabs are now routed pages** (each tab has its own URL; saving stays on the
  current tab) and consolidated from 13 to 8 tabs (General now includes Blog, Localization
  and Maintenance; Branding includes Typography; a new Header & Footer tab). Tab content is
  full-width and sub-sections are anchor-linkable.
- **The Post editor now uses a two-column layout** with metadata (status, category, author,
  published date, featured image) in the sidebar, matching the Page editor.
- **Demo actions now all ask for confirmation before running**; the sidebar "System"
  section is renamed "System & Tools".

### Fixed

- **The Forms list Delete now renders as a button** (with a trash icon), consistent
  with the other content lists.
- **Small admin buttons had no horizontal padding** (Remove demo flag, media
  picker actions, form-builder controls, etc.) — their text touched the edges.
- **The Demo Delete and Remove-flag actions now render as proper buttons** (they
  previously looked like plain text).
- **The unsaved-changes warning no longer falsely triggers on forms with media
  pickers** (file inputs are excluded from change detection).
- **ConsoleStepRunner refuses to spawn subprocesses in the test environment**,
  preventing a functional test that drives the install/upgrade/demo flow from
  accidentally mutating the development database instead of the isolated test one.

## [1.4.0] — 2026-07-04

### Added

- **Icons in content.** A curated icon set (Font Awesome Free, inline SVG) with an
  `[icon]` shortcode and a WYSIWYG picker in the editor toolbar — icons sit inline
  in text and inherit its colour and size.
- **Content buttons.** Turn any link into a call-to-action button with a curated
  style (primary, secondary or ghost) straight from the link picker.
- **Vertical spacer block.** Insert blank vertical space between blocks in three
  curated sizes (small, medium, large).
- **Curated text color palette.** Color selected text from an eight-color palette
  (a swatch picker in the toolbar) — the theme owns the actual colors.
- **One-column layout option.** The editor's Columns dropdown can now insert a
  single full-width column — handy for an image-left, text-right card.
- **Columns card styles.** The editor's Columns dropdown gained a curated Style
  group: white bordered cards or tinted cards in rotation, plus a per-column
  highlight for the featured card (e.g. the "Pro" price). Existing columns are
  untouched (Default).
- **Eyebrow option in the heading menu.** A small brand-coloured kicker (h6) above
  a title, straight from the editor's Heading dropdown.
- **Editor toolbar stays pinned while scrolling long content** (page and post
  content), so the formatting controls are always reachable.
- **Top bar.** An optional thin bar above the header: rich text with links on the
  left, social icons (GitHub, X, LinkedIn, YouTube) on the right — configured in
  Settings.
- **Configurable footer.** One to four footer columns, each showing a menu (with
  its name as the heading) or rich text.
- **Typography settings.** Pick a display and a body font from a curated set of
  self-hosted fonts (no CDN calls) in Settings → Typography.
- **Header search toggle.** The search field is collapsed to an icon; it expands
  inline on desktop and as a full-width bar under the header on mobile.

### Changed

- **Theme v2 redesign.** The default theme got a full visual pass: a warm orange
  accent, a card-based layout language with soft tints, pill buttons, a dark top
  bar and footer, larger radii, and Space Grotesk/Inter as the default typography.
  The hamburger toggle now swaps to a close icon while the menu is open, and the
  remaining front-end glyphs (submenu carets, pagination arrows, back links) use
  the same icon set.
- **Photo hero overlay is now a solid footer-colour panel** instead of a gradient
  — cleaner behind the text, with the image half left clear.

### Fixed

- **Demo install/delete/make-permanent from the admin failed under php-fpm**
  (wrong PHP binary resolution) — the buttons now run the site's CLI PHP.
- **Toolbar dropdowns could open outside the editor** (into the sidebar or the
  settings column) — they are now positioned dynamically so they always stay inside
  the editor, whatever the trigger's position or the window width. The icon picker
  grid also scrolls when the set is large.
- **Left-aligned images inside cards, and h6 eyebrows lost when editing.** A
  left-floated image in a card now keeps the text beside it, and small "eyebrow"
  headings (h6) survive editing instead of turning into plain paragraphs.
- **Top bar social icons were too small, and intro paragraphs under left-aligned
  headings looked shifted right** — the social icons are now larger and a lede
  paragraph only centers when its heading is centered.

## [1.3.0] — 2026-07-02

### Added

- **Editor toolbar v2.** The content editor's toolbar is reorganised and rounded
  out: Heading (Paragraph/H1–H4), List, Alignment, and Columns (2/3/4) are now
  tidy dropdowns, text alignment and an "insert line" rule were added, and all
  image formatting (alignment + a single Small/Medium/Large/Full size scale) lives
  under one "IMG format" dropdown. The toolbar buttons (and the email editor's) now
  use a consistent, modern icon set throughout. The link button opens a picker where
  you paste a URL or search and link to one of your published pages/posts, with an
  "open in new tab" option.
- **Hide page title (per page).** A new page option hides the standard title heading
  on the front so you can build your own heading in the content — for landing pages.
  The title is still used for the browser tab and search engines.
- **Hero overlay: text position and readability style (per page).** Over a full-bleed
  image, the hero text sits on the left or right half (desktop), with a readability
  style — photo (a dark shade behind the text), light image (dark text), or dark image
  (light text).
- **Two-column page editor.** The page edit screen now puts the content in a wide main
  column with the lightweight settings (status, position, hide title, template, meta) in
  a narrow column on the right, instead of one long scroll.

### Changed

- **Images are served as WebP** (smaller files, faster loading) regardless of the uploaded
  format — page, post and content images plus logos and thumbnails. Favicons keep their
  original format.

## [1.2.0] — 2026-06-27

### Added

- **Supported upgrade path.** A new `app:upgrade:finalize` command runs every
  deterministic upgrade step in order (automatic database backup → migrations →
  asset rebuild), with a `bin/tallyst-upgrade` one-command wrapper over the full
  git + Composer + finalize flow, and an "Upgrading" guide in the install docs
  (git path, a one-time bridge for `create-project` installs, and a manual
  fallback).
- **Admin list polish.** Row actions (Edit/Delete) now show inline instead of
  hidden in a "⋮" menu, New/Edit screens have a "Back to list" button, and Pages,
  Posts, and Categories have a "Preview" link that opens the live page in a new tab.
  The Menu items list can be filtered by parent menu. The sidebar "System" section
  is collapsible (collapsed by default, remembered across reloads).
- **Dashboard chart shows orders alongside revenue.** The revenue chart now plots the
  order count as a second line on its own axis, so you see both at a glance.
- **Readiness: worker-startup example.** When the background worker isn't confirmed
  running, the readiness panel now shows an example command to start it (with an honest
  "depends on your server" note).
- **Install, remove, or keep demo content from the admin.** A System → Demo content
  screen seeds a full demo (pages, posts, a menu, forms, and sample images) to preview
  the front-end. Every demo item is marked as demo, so removing it deletes exactly the
  demo set — including orders placed through demo forms — while sparing anything you
  created. You can also "make the demo permanent" to keep it as the starting point for
  your real site (after which the uninstaller leaves it alone). The screen leads with a
  clear "use on a clean site only" boundary.

### Fixed

- **English admin no longer shows a Croatian placeholder** on encrypted settings
  fields (Stripe/PayPal secrets). The "leave blank to keep" placeholder is now
  localized.
- **Dark-mode display fixes.** Several back-office boxes that stayed light in dark mode
  now render correctly — the readiness panel's header, section dividers, and intro box,
  the themes screen's intro box, and the remaining light alert boxes across the admin.

## [1.1.0] — 2026-06-25

### Added

- **User-interface internationalization — English (default) and Croatian.** The
  language is selected in **Settings → Localization** and applies to both the
  admin and the public site. Every UI string across the core, the bundled theme,
  and the modules is now externalized to translation catalogs; themes and modules
  carry their own translations, so add-ons can ship in any language. Email
  defaults are localized too and are sent in the site's configured language.
- **Installed Tallyst version in the admin sidebar.** The running version is shown
  discreetly at the bottom of the back-office sidebar (read from the package
  metadata) for quicker support and troubleshooting.
- **"Reset to default" for email templates.** An admin can now clear a customized
  email template and fall back to the built-in default, which renders in the
  active language.

### Changed

- **Unified admin form styling.** The custom admin screens — Settings, the form
  builder, Security, and email templates — now use the EasyAdmin form theme, so
  their fields render consistently with the rest of the back office.

### Fixed

- Module-contributed sidebar menu items (Forms, Orders, Media) now follow the
  selected language instead of always showing in Croatian.
- Several admin and theme strings that were missed by the initial translation
  pass now translate correctly — the maintenance-mode banner, the blog "no posts
  yet" message, the mobile submenu toggle label, and the live-search "Show all"
  link.

## [1.0.0] — 2026-06-25

Initial public release.

### Added

- **Core CMS** — pages, posts, post categories, menus, and a media library with
  a Tiptap WYSIWYG editor, image embeds, and multi-column layouts.
- **Form builder → payment.** An admin builds a payment-enabled form and inserts
  it into any page with `[form id=N]`, turning that page into a sellable product.
  Payments go through **Stripe and PayPal**.
- **Orders** with manual fulfilment — order lifecycle, refunds, price variants,
  inclusive tax, and a filter-aware CSV export for accounting.
- **Themes** — one folder per theme, auto-detected and activated from the admin,
  with child-theme inheritance and a tokens-first default design system.
- **Front-end full-text search** (self-hosted MySQL FULLTEXT) with an instant
  results dropdown.
- **Maintenance mode** — a 503 holding page for visitors while admins keep access.
- **Deployment readiness panel** — auto-diagnoses whether an install is configured
  and production-ready.
- **Editable email templates** — customer- and admin-facing mails with a safe
  placeholder engine and a branded layout.
- **Production-grade authentication** — roles, user management, TOTP two-factor,
  password reset, lockout, and login throttling.
- **Standalone installer** (`app:install`) — a guided, WordPress-like first-run
  setup.
- **Modular architecture** and distribution via **Packagist**, under the **MIT**
  license.

[1.6.2]: https://github.com/zaja/Tallyst-CMS/releases/tag/v1.6.2
[1.6.1]: https://github.com/zaja/Tallyst-CMS/releases/tag/v1.6.1
[1.6.0]: https://github.com/zaja/Tallyst-CMS/releases/tag/v1.6.0
[1.5.1]: https://github.com/zaja/Tallyst-CMS/releases/tag/v1.5.1
[1.5.0]: https://github.com/zaja/Tallyst-CMS/releases/tag/v1.5.0
[1.3.0]: https://github.com/zaja/Tallyst-CMS/releases/tag/v1.3.0
[1.2.0]: https://github.com/zaja/Tallyst-CMS/releases/tag/v1.2.0
[1.1.0]: https://github.com/zaja/Tallyst-CMS/releases/tag/v1.1.0
[1.0.0]: https://github.com/zaja/Tallyst-CMS/releases/tag/v1.0.0
