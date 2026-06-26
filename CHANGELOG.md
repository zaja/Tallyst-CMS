# Changelog

All notable changes to Tallyst are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and Tallyst adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html)
from v1.0.0 on. Semver is the API contract to the add-on ecosystem: a `Fixed`
change is a PATCH, an `Added` or `Changed` change is a MINOR, and a breaking
core-API change is a MAJOR (flagged ⚠).

## [Unreleased]

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

### Fixed

- **English admin no longer shows a Croatian placeholder** on encrypted settings
  fields (Stripe/PayPal secrets). The "leave blank to keep" placeholder is now
  localized.

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

[1.1.0]: https://github.com/zaja/Tallyst-CMS/releases/tag/v1.1.0
[1.0.0]: https://github.com/zaja/Tallyst-CMS/releases/tag/v1.0.0
