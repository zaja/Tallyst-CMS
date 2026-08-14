# Changelog

All notable changes to Tallyst are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and Tallyst adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html)
from v1.0.0 on. Semver is the API contract to the add-on ecosystem: a `Fixed`
change is a PATCH, an `Added` or `Changed` change is a MINOR, and a breaking
core-API change is a MAJOR (flagged ⚠).

## [Unreleased]

## [1.12.1] — 2026-08-14

### Fixed

- **⚠ Sales made through Stripe or Dodo were not being recorded. Upgrade from 1.12.0 immediately.**
  On 1.12.0 only, a successful payment through **Stripe** or **Dodo** was taken by the payment
  provider but never recorded by your site: the order stayed on **"Processing"** for ever, the
  customer got **no confirmation e-mail**, no licence key was delivered, and the sale never appeared
  as paid. The money reached your account; nothing else happened. Retries by the payment provider
  could not fix it, and the affected orders do not repair themselves. **PayPal was not affected.**

  **How to tell whether this hit you:** look in **Sales → Orders** for orders still marked
  *Processing* that were placed while you were running 1.12.0, and check whether the payment actually
  went through in your Stripe or Dodo dashboard. Any that did are real sales your site did not
  record — you will need to deliver them by hand, and you can use **Resend confirmation** once the
  order is marked as paid.

  Upgrading fixes all future payments. It cannot retroactively record the ones that were lost, since
  the payment provider has already stopped retrying them.

## [1.12.0] — 2026-08-12

### Added

- **Member accounts.** Visitors can now have an account on your site. There are no passwords: they
  enter their e-mail address, receive a link, and press a button on the page it opens — that button
  is what signs them in, so a link fetched by a corporate mail filter before they see it is not
  wasted. Signing in lasts 90 days from the last visit, so somebody who comes back regularly is
  never sent to their inbox again, and signing out really ends it rather than just forgetting a
  cookie. An account is created only when somebody proves they hold the address, never silently on
  your behalf, and members are kept entirely separate from your staff accounts and cannot reach the
  back-office. What an account shows depends on what your site offers — **purchases are the first
  such thing**: orders placed under that address are attached the first time the member signs in,
  and someone who has bought nothing simply sees no purchase list at all. The thank-you page now
  shows the purchase and its state immediately, without waiting for any e-mail, and offers to send a
  sign-in link to the address already on the order. A new **Sales → Unassigned orders** screen lets
  you attach an order to an account by hand — the way back for a customer who mistyped their
  address, or who has lost access to their mailbox and would otherwise be locked out for good.

- **Protection against someone using your sign-in form to send mail in your name.** The sign-in form
  is the only place an unknown visitor can make your site send an e-mail, and before this it would
  send one for every address typed into it — a script could get tens of thousands of messages an hour
  out of your site, almost all to invented addresses. That is not a nuisance: your mail provider
  judges you by how many of your messages bounce, and a frozen sending account stops your **order
  confirmations** as well, so the shop keeps selling while buyers hear nothing. Your site now limits
  how fast one visitor can request links and how many it will send in total in an hour. An ordinary
  visitor is unaffected — a sign-in lasts 90 days, so almost nobody asks twice — and a whole office
  behind one connection can still sign in together. Requests that are turned away look exactly like
  ones that went through, so the form still cannot be used to find out which addresses your site
  knows. If it ever happens, **System & Tools → Readiness check** tells you: how many were refused
  and when, that you were protected rather than damaged, and it clears itself after a quiet week.

- **A cleanup command for expired sign-in records.** `php bin/console app:member:prune` removes
  sign-in links that have already expired and sign-ins nobody has used for 90 days. Nothing breaks if
  you never run it — the records simply accumulate — but the install guide now shows how to run it
  nightly. Add `--dry-run` to see what it would remove without removing anything.

- **The readiness check now tells you when your stored secrets can't be read.** Your payment keys
  (Stripe, PayPal, Dodo) and mail credentials are stored encrypted, and the key that reads them
  lives in `.env.local`. If that key is lost or changed — most often by restoring a site from a
  database backup without it — those secrets become unreadable and Tallyst treats them as *not
  configured*: the site keeps working and payments quietly stop. **System & Tools → Readiness
  check** now reports this as a problem and names each affected setting, instead of leaving you to
  discover it from a customer. The install guide gained a "Backing up your site" section
  explaining what must be backed up together, and why a database dump alone is not enough.

## [1.11.0] — 2026-08-07

### Added

- **Your orders now remember what they sold.** Each order keeps its own copy of the product name and
  the details the buyer typed into the form, recorded at the moment of purchase. Renaming a product no
  longer rewrites your past sales: an order placed last month still shows — and its e-mails still say —
  the name it was actually sold under. This applies to every order e-mail, including the refund notice,
  which can go out long after the sale.
- **An order now survives having its form deleted.** Previously, deleting a form also deleted every
  order ever placed through it. Now the sale stays: the product name, the amount, and the buyer's
  details are all still there, and only the link back to the form is gone (shown as "—"). Deleting a
  form that has orders is still blocked, as before — this is the safety net underneath that, not a
  replacement for it. Note that messages from contact forms still go with their form, as they always
  have.

### Changed

- **The orders list now has a "Product" column instead of "Form".** It shows what was sold. The order's
  detail page shows both: the product, and underneath it the form the order came from.
- **⚠ The orders CSV export now has 17 columns instead of 16.** A new "Product" column has been added in
  third place, right after ID and Date, so the first few columns tell you which order, when, what, and
  for how much without scrolling. **If you feed this file into accounting software or a spreadsheet
  that expects columns in fixed positions, check that setup after upgrading** — everything from column
  four onwards has shifted one place to the right. Column headings themselves are unchanged.

## [1.10.0] — 2026-08-05

### Added

- **The forms list now shows how many orders each form has.** You can see which forms have
  actually made sales before you touch anything.

### Changed

- **Deleting a form with messages now tells you how many will be lost.** Messages sent through
  a contact form live only inside that form, so the confirmation now names the exact number and
  warns that they go with it. A form with no orders and no messages is deleted exactly as before.

### Fixed

- **A form that has orders can no longer be deleted.** Until now, deleting a form also quietly
  deleted every order ever placed through it — the sales record for that product, gone with one
  click and no warning. Tallyst now refuses, tells you how many orders the form has, and suggests
  setting the form to Draft instead, which takes it off your site while keeping its history.
- **Demo images no longer appear broken after you delete and re-import the demo content.** The
  pictures were being saved correctly, but the small preview versions Tallyst generates for them
  were removed again moments later, so pages and the Media library showed empty picture frames.
  Your uploaded originals were never at risk — only those generated previews were missing, and
  they are now created and kept as expected. This affected anyone who re-imported the demo
  content on version 1.8.0 or later. If you already have pictures showing as broken, you can
  bring them back at any time with `php bin/console app:media:thumbnails:warm`.

## [1.9.0] — 2026-08-05

### Added

- **Choose how your e-mail is sent.** Under Settings → Email you can now pick a mail
  provider — your own SMTP server, or Resend, Mailgun, Postmark, or Brevo — and just paste
  in an API key instead of wrestling with SMTP hosts and ports. Only the fields for the
  provider you pick are shown; switching providers keeps everything you've already entered.
  The "Send a test mail" button tells you exactly which provider the mail went through.

## [1.8.0] — 2026-07-23

### Added

- **Crop images in the Media library.** When you upload an image, you can now frame it before it's saved — pick a ratio (16:9, square, or free), drag the crop box, and confirm. You can also upload without cropping, and when adding several images at once you'll be asked about each one in turn.
- **Crop images you've already uploaded.** Open any image from the Media library and use *Crop* to reframe it. You can either save the result as a new image (the original stays untouched) or replace the existing one — replacing warns you first, since the new version will appear everywhere that image is used.
- **New maintenance command `app:media:cache:clean`** removes leftover thumbnail files that no longer belong to any image. It only reports what it would delete unless you pass `--force`, and refuses to run if the numbers look wrong.

### Fixed

- **Old thumbnails are now cleaned up automatically.** Replacing or deleting an image used to leave its generated thumbnails behind on disk forever. On sites with a lot of media this quietly added up; it no longer does.

## [1.7.2] — 2026-07-22

### Changed

- **Admin lists are cleaner and more consistent.** Row action icons (edit, preview, delete) now share one flat icon style across every list, sit on a single line, and are spaced more tightly.
- **Posts list is more useful at a glance.** Each post now shows its featured-image thumbnail, a "Published" date column (replacing "Modified"), and the author's display name instead of their e-mail. Long titles and slugs are shortened with an ellipsis — hover to see the full text. The edit screen now shows when a post was last modified.
- **Forms list shows each form's type** (message, physical, digital, or Merchant-of-Record) in a new column.
- **On/off controls look consistent.** Ordinary settings now render as plain checkboxes; the toggle switch is reserved for a true master switch (e.g. "Apply tax"). The page editor's "Hero enabled" and "Hide page title" options were switched to checkboxes to match.
- **The page editor's Hero section starts collapsed** when a page has no hero set, and expands automatically when it does.

## [1.7.1] — 2026-07-21

### Fixed

- **`bin/tallyst-upgrade` could crash mid-upgrade and leave the site on new code with a
  broken container and unmigrated database.** A stale compiled cache from before the
  upgrade was being reused against the just-updated code; the upgrade now clears it
  before installing dependencies, so this can't happen again. If you hit this during an
  upgrade, recover with: `rm -rf var/cache/prod/*`, then `bin/console cache:clear`, then
  `bin/console app:upgrade:finalize`.

## [1.7.0] — 2026-07-15

### Added

- **DodoPayments as a third payment provider — a Merchant-of-Record.** Configure it in
  **Settings → Dodo Payments** (API key, webhook secret, and an explicit test/live mode) and
  link a Dodo product to each paid form — picked from a dropdown of your Dodo catalogue
  (loaded live in the active mode) or typed in by hand when the catalogue can't be reached.
  A purchase then flows the same as Stripe/PayPal: checkout → thank-you → the verified
  `/webhook/dodo` marks the order paid (awaiting delivery), with manual fulfilment unchanged.
  As the Merchant-of-Record, **Dodo is the legal seller and handles sales tax / VAT itself**,
  so Tallyst's own inclusive tax is never applied to a Dodo order (no double tax). Webhooks
  are verified with the Standard Webhooks signature (HMAC-SHA256 + a replay window), and the
  order is matched back by its id carried in the checkout metadata. Refunds are issued from
  the order screen like the other providers.
  _Note: the refund path is wired but has NOT been run live yet — a completed refund is a final
  live-mode check (a sandbox merchant wallet has no funds to return). See the **Notes** below._
- **Dodo orders capture the buyer and tax details Dodo reports.** After a Dodo purchase the
  order shows the buyer's name and phone, a link to the Dodo invoice, and — in a new **Merchant
  of Record** panel — the tax and settlement amounts Dodo calculated, collected and remits as the
  seller of record (Tallyst's own tax fields stay empty for these orders, by design). When the
  product grants a licence, its key is captured onto the order as well; the licence attaches
  reliably no matter which webhook (payment or licence) arrives first. Dodo orders also get their
  own badge and list filter. This is read-only visibility — Dodo still delivers the licence to the
  buyer; Tallyst only mirrors it.
- **A Dodo form now behaves as Dodo-only, everywhere.** Because Dodo is the seller of record, a form
  tied to a Dodo product can't also take Stripe/PayPal (their tax models differ). Link a Dodo product
  per form from a picker that also prefills the price and currency from the chosen product (a starting
  value you can still edit — Dodo charges the price set on the product). Ticking Dodo (or picking a Dodo
  product) now locks out Stripe/PayPal in the builder, saving a mixed setup is refused, and — the fix
  that matters on the storefront — the buy screen offers **only** Dodo (never a stray Stripe/PayPal
  button), while the Tallyst tax note is hidden on Dodo forms. This is decided in one place, so the
  builder, the storefront and checkout can't drift. Regular Stripe/PayPal forms are unchanged. Settings
  and the readiness "webhook check" now cover every payment provider automatically (Dodo included), so
  future providers need no wiring there.
- **Sell physical products — delivery methods, address & shipping countries.** A paid form can now
  sell shipped goods. Define named delivery methods with a price in **Settings → Shipping**, then
  choose per form which to offer; if there is more than one the buyer picks at checkout, where a
  delivery address is now collected. Delivery is added to the total and taxed at the product's rate,
  and a form can restrict which countries it ships to. Digital and message forms are unaffected.
- **Named tax rates.** Instead of a single global rate, define a catalog of named rates (e.g. VAT
  25 %, reduced 13 %) in **Settings → Tax**, with one marked as the default. Each product form picks
  its rate (or "no tax"); a new form starts on the default. Tax stays **inclusive** in the price — the
  charged amount never changes — and every order records the rate that applied, for your export.
- **Explicit form types + a create-form wizard.** A form now remembers *what it is* — a message form,
  a physical product, a digital product, or a Merchant-of-Record product — instead of guessing from the
  price. New forms start with a short wizard that sets this up, and the builder only shows the options
  that apply to the chosen type. Switching a digital form to or from Merchant-of-Record is allowed and
  never loses your price, shipping or tax settings; the other types are fixed once created. Existing
  forms were migrated to keep behaving exactly as before.
- **Merchant-of-Record is a remembered choice, with a safer product picker.** Which MoR provider a form
  uses is now stored explicitly (today: Dodo) rather than inferred. Pick the product from your Dodo
  catalogue — it prefills the name, description, price and currency — and Tallyst only ever offers
  fixed-price one-time products: subscriptions, usage-based and pay-what-you-want products are refused,
  both when picking from the list and when saving a hand-typed product id.
- **A Merchant-of-Record form can offer several options.** Instead of one product, a MoR form can list
  several sellable units (e.g. Personal / Team / Pro). The buyer sees the choices with a price each and
  is sent to the provider's checkout for the chosen one; the provider charges its own configured price
  (the shown price is a display value). A single-option form behaves exactly as before.
- **Import a whole collection at once.** Rather than adding MoR options one by one, import an entire Dodo
  product collection into a form in a single step — it fills the form name, description and the whole
  option list. A preview shows exactly what will change and lists any products it skipped (with the
  reason, e.g. a subscription), so nothing unsupported is imported by accident. It's a one-time prefill:
  nothing about the collection is stored or kept in sync.
- **Merchant-of-Record purchase e-mail + an honest price note.** For a MoR purchase the order-confirmation
  e-mail now includes the licence key and a link to the invoice. The licence is included even though the
  provider delivers it in a separate event that can arrive shortly after the payment — the e-mail waits a
  short grace window so it isn't sent without the key, and is still sent (with the invoice) if the product
  grants no licence. On the storefront a small, unobtrusive note tells the buyer how tax is handled —
  "included in the price", "added at checkout", or "included; may adjust to your region" — without ever
  promising an exact amount (the provider sets the final price).

### Notes

- **Merchant-of-Record (Dodo) is feature-complete and proven end-to-end in TEST mode.** Real test
  purchases, licence capture, the checkout money path, webhook verification and order correlation were
  all verified against the live Dodo API. Two things are, by their nature, confirmed only once a real
  production merchant exists: **the buyer-facing charge in live mode**, and **a fully completed refund**
  (the refund is issued from the order screen, but a sandbox merchant wallet has no funds to return).
  These are the expected final production checks, not open defects — everything else on the Dodo money
  path is proven.

## [1.6.3] — 2026-07-07

### Changed

- **Upgrade output is now clean** — framework deprecation notices go to
  `var/log/deprecation.log` instead of the console, and the Git detached-HEAD notice is
  suppressed. Real errors are unaffected.

### Fixed

- **A stray Croatian message in the install/upgrade step runner is now English.**

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

[1.6.3]: https://github.com/zaja/Tallyst-CMS/releases/tag/v1.6.3
[1.6.2]: https://github.com/zaja/Tallyst-CMS/releases/tag/v1.6.2
[1.6.1]: https://github.com/zaja/Tallyst-CMS/releases/tag/v1.6.1
[1.6.0]: https://github.com/zaja/Tallyst-CMS/releases/tag/v1.6.0
[1.5.1]: https://github.com/zaja/Tallyst-CMS/releases/tag/v1.5.1
[1.5.0]: https://github.com/zaja/Tallyst-CMS/releases/tag/v1.5.0
[1.3.0]: https://github.com/zaja/Tallyst-CMS/releases/tag/v1.3.0
[1.2.0]: https://github.com/zaja/Tallyst-CMS/releases/tag/v1.2.0
[1.1.0]: https://github.com/zaja/Tallyst-CMS/releases/tag/v1.1.0
[1.0.0]: https://github.com/zaja/Tallyst-CMS/releases/tag/v1.0.0
