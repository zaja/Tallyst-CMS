# Tallyst CMS — Claude Code Project Guide

## Vision & product (WHY)
Tallyst is a deliberately simple, self-hosted CMS for **solo developers** who want to
present AND sell their own app or services **in-house** — without paying recurring fees to
external "sell-your-stuff" SaaS (Gumroad, Lemon Squeezy, paid WordPress form/payment plugins).
The bet: that niche is underserved. Off-the-shelf options are either heavy and bloated
(WordPress + plugins) or hosted services that take a cut and own the customer relationship.
Tallyst is the clean, opinionated, everything-yours alternative.

It started as a personal CMS; the goal is a well-made basic CMS plus a lean e-commerce layer,
polished enough to offer to that community.

**Signature feature:** an admin builds a payment-enabled form and inserts it into any page via
`[form id=N]` — that page becomes a sellable product. (Architecture in WHAT, below.)

## Guiding principle — simple IS the product

For the target user, **simplicity is the value proposition, not a limitation.** Tallyst must NOT
try to out-feature WordPress; it wins by being clean, lean, opinionated. The constant filter for
every proposed feature:

> "Does a solo developer selling their app/service need this for v1?"

If no → defer or drop. The auth layer went deep BECAUSE Tallyst is multi-user and handles
payments — depth is justified there. Do NOT repeat depth-for-its-own-sake elsewhere; keep CMS
features lean. The danger is endless "polish until done"; the cure is the closed v1 scope below.

## v1 scope (a CLOSED list — resist creep)

**In v1:**
- Core CMS engine + production-grade auth — **DONE** (Roadmap Phase 0).
- CMS-complete polish: a neutral, well-designed **default theme**; **demo content** (~15 pages +
  ~15 posts in a 2-level menu, to see the front-end whole incl. mobile); site **footer** config;
  per-page **hero** section; **email templates**.
  - Email templates are SCOPED: customer-facing mail (order confirmation, free-form notification)
    gets *light* admin editability — editable subject + body with a few variables (`{name}`,
    `{total}`…) + sensible defaults. System mail (reset, 2FA) stays as good Twig templates in code.
    **NOT** a full admin email-template editor (variables/preview/per-type IDE) — that's over-build.
- E-commerce finish on the **manual-fulfilment** model (see Delivery): PayPal alongside Stripe,
  refund, order-flow polish.
- A standalone **installer** (WordPress-like) + the deployment readiness panel.

**Explicitly NOT in v1 (later versions / never):**
- Automated digital delivery — download-file handoff, licence-key generation, access grants on
  `fulfilled`. **The admin fulfils manually in v1** (see Delivery). Deferred to a later version.
- Full email-template editor, multilingual/i18n, comments, dynamic/custom RBAC roles,
  required-2FA-for-admins, trusted devices, SMS/e-mail 2FA, WebAuthn, full self-profile, custom
  fields/widgets — none unless the target-user filter later demands one.

## Delivery model — DECIDED (resolves the long-open fork)

**v1 = manual fulfilment.** A purchase flows: payment (Stripe/PayPal) → order recorded →
confirmation e-mail → **the admin delivers manually** (sends the file, grants access, issues the
licence, performs the service). This is enough for BOTH services and digital products at v1, and
it means the e-commerce CORE is essentially already built (payment + order + confirmation work).

**Post-v1 = automated delivery.** On `fulfilled`: download links / licence keys / access grants,
with the verified webhook staying the sole source of truth for `paid`. Explicitly deferred — NOT
built now.

## Roadmap / phases

- **Phase 0 — Core CMS + auth — DONE & parked.** Content engine, modules, Media, Tiptap editor,
  FormBuilder + Stripe, Settings/SMTP, and production-grade auth (roles, user CRUD, lockout, reset,
  2FA, throttling, self-service password). Per-area implementation detail is documented below.
- **Phase 1 — CMS-complete polish — DONE.** Theme + demo + page layout/hero + footer config +
  branding-in-Postavke + favicon + blog archives/pagination + post author/byline + email templates
  (engine PASS 1 + Tiptap-lite editor PASS 2) — all complete (see the sections below).
- **Phase 2 — E-commerce finish (manual-fulfilment model) — DONE.** Manual-fulfilment order lifecycle +
  admin actions, refund (admin + provider webhook), Stripe + PayPal config in Postavke, PayPal processor
  + provider choice + per-product limit, price variants, and inclusive tax + CSV export. **NOT** automated
  delivery (post-v1).
- **Phase 3 — Standalone installer + deployment readiness — DONE → v1.0.0.** WordPress-like install:
  **Pass 1 (interactive `app:install` wizard) DONE; Pass 2 (readiness panel + worker heartbeat) DONE**
  (incl. `APP_ENV=prod` default) — both **smoke-tested on a clean prod CloudPanel install**; **Pass 3
  (Packagist packaging: `tallyst/cms` composer metadata + MIT LICENSE + `post-create-project-cmd`
  asset hook) DONE** → **v1.0.0** (cut by a manual `git tag`, the user's action). Deferred (in the
  readiness panel, post-1.0): worker-activation snippet generator + encryption-key rotation command.
  Next: README / install guide.
- **Post-v1 / future.** Automated digital delivery (downloads/licences); **Import / content packs**
  (a content importer with format adapters — see the backlog); and any deferred item that later
  passes the target-user filter.

## Versioning (semver — ACTIVE as of v1.0.0)
Tallyst follows **semver (MAJOR.MINOR.PATCH)** from the first stable release.
- **PATCH** = bugfix (backward-compatible). **MINOR** = new feature (backward-compatible).
  **MAJOR** = breaking change (a core-API change that may break existing installs/addons).
- Every release → a git tag (`vX.Y.Z`) → **Packagist auto-sync** (git↔Packagist linked).
- **Semver is the API CONTRACT to the addon ecosystem:** MINOR/PATCH must NOT break addons (core
  API stays stable); MAJOR signals "addons: re-check compatibility". Addons declare the compatible
  core version (`"tallyst/cms": "^1.0"`).
- **Timing:** the rule is **ACTIVE as of v1.0.0** (end of Phase 3 — installer + readiness done,
  distribution-ready). Packaging (Pass 3) is committed; **v1.0.0 is cut by a manual `git tag` (the
  user runs it — irreversible + triggers Packagist sync), not by Claude.** From v1.0.0 on, every
  release is tagged.

## WHAT
A Symfony 8 application built on three pillars:
- **Core** — pages, posts, post categories, menus, settings, themes.
- **Modules** — optional, self-contained features. Each module is a lean Symfony bundle.
- **Themes** — front-end presentation. One folder = one theme.

Signature feature: an admin builds a payment-enabled form, inserts it anywhere in
page content via a replacement tag like `[form id=5]`, and that page becomes a
product. Payments go through Stripe and PayPal.

## Tech stack
- PHP **8.5** (selected per-site in CloudPanel)
- Symfony **8.1** (full web app; the `webapp` pack is installed)
- Doctrine ORM + Migrations (MySQL/MariaDB provisioned by CloudPanel)
- Twig templating
- AssetMapper (no build step) + Stimulus for front-end JS
- Hosting: CloudPanel on Ubuntu; app lives at `/home/tallystorg/htdocs/tallyst.org`

## ENVIRONMENT GOTCHA — always use the versioned PHP binary
The default `php` on this server may NOT be 8.5. Always call the explicit binary:
- `php8.5 /usr/local/bin/composer ...`
- `php8.5 bin/console ...`
Plain `php` / `composer` can resolve the wrong PHP version and break installs.

## Common commands
- Install (interactive wizard): `php8.5 bin/console app:install` — guided first-run install
  (DB creds + connection validation, admin user, `.env.local`, migrations, baseline seed, asset
  fallback); idempotent + already-installed guard. See "Standalone installer" in the backlog.
  Baseline content (default theme, home, 2-item menu) is now seeded by `BaselineSeeder` via the
  hidden `app:install:finalize` step (run in a fresh-kernel subprocess).
- Seed DEMO content: `php8.5 bin/console app:demo:seed` (additive) / `--fresh` (full reset).
  Separate from install — ~16 pages + 15 posts in a 2-level menu, demo forms (free + priced
  page-as-product), and GD-generated neutral demo images. See "Default theme & demo content".
- Install deps: `php8.5 /usr/local/bin/composer install`
- Add a package: `php8.5 /usr/local/bin/composer require <pkg>`
- **⚠️ EDITING composer.json METADATA (name/description/license) — Flex strips it.** After a MANUAL
  edit to `composer.json` metadata, a plugin-enabled composer write-op (`composer update` /
  `run-script` / `require`) can SELECTIVELY strip `name`/`description` and revert `license` to
  `"proprietary"` (and delete an untracked `LICENSE`) — Symfony Flex's JsonManipulator rewriting on
  composer events. `composer validate` is read-only (safe). Rule: after editing composer.json
  metadata, **do NOT run a plugin-composer-write-op before committing**, OR run it with
  `--no-plugins` (e.g. `composer update --lock --no-install --no-plugins` to refresh the lock hash).
  ALWAYS re-check `composer.json` (and `LICENSE`) right before commit/tag — **Packagist reads the
  TAGGED composer.json from GitHub**, so a stripped tag ships wrong metadata. (Bit the v1.0.0 prep.)
- Clear cache: `php8.5 bin/console cache:clear`
- Create migration: `php8.5 bin/console make:migration`
- Run migrations: `php8.5 bin/console doctrine:migrations:migrate`
- Sanity check: `php8.5 bin/console about`
- List routes: `php8.5 bin/console debug:router`
- Run PHP tests: `php8.5 bin/phpunit` (PHPUnit is set up).
- Run a module's JS tests: `node modules/<Name>/tests/js/<x>.test.mjs` (Node 20 via nvm).
- Compile assets: `php8.5 bin/console asset-map:compile` (REQUIRED after JS/CSS changes
  — see "Front-end assets" below).
- Static analysis / cs-fixer — not yet set up.

## Front-end assets (AssetMapper) — must be compiled
nginx serves `/assets/*` as static files, so compiled assets MUST exist on disk:
after ANY change to JS or CSS (incl. module assets), run
`php8.5 bin/console asset-map:compile`. Symptom of stale/missing assets: Stimulus
controllers silently don't boot (e.g. admin buttons do nothing). `public/assets/` is
a per-environment build artifact and is git-ignored — never commit it.
- **⚠️ DEV serves assets LIVE; PROD serves the compiled `public/assets/` — which MUST be regenerated
  on every deploy.** A controller that works in dev can be ABSENT in prod if `public/assets/` is stale
  or its compile failed (`git pull` alone is NOT enough — run `importmap:install` + `asset-map:compile`
  on deploy, then hard-refresh). Learned on the clean-prod smoke: the `formbuilder--webhook-check` button
  was dead in prod purely because the prod `asset-map:compile` had silently failed (see the prod-compile
  config/ bug below) so the controller never entered the served build — the JS itself was correct. When
  an admin button is dead ONLY in prod, suspect the asset BUILD (stale/failed compile), not the JS.
- The front-end loads the `app` entrypoint (`assets/app.js` → Stimulus + `app.css`).
- The admin loads a SEPARATE `admin` entrypoint (`assets/admin.js` → Stimulus only, no
  front CSS) via `DashboardController::configureAssets()`, so front styles never
  override the EasyAdmin theme/dark mode. Register module Stimulus controllers in
  `assets/stimulus_bootstrap.js` (shared by both entrypoints).
- **⚠️ Tree-shakeable packages — import the BARE specifier + register manually, NEVER a `/auto` or
  other subpath.** AssetMapper does NO Node export-map resolution — it maps only the bare specifier
  listed in `importmap.php` (e.g. `chart.js`), so a subpath import like `chart.js/auto` throws a
  runtime "module not found" and the Stimulus controller **silently never boots** (the feature just
  doesn't work; no PHP/Twig error — the only signal is in the BROWSER console). Correct pattern:
  `import { Chart, registerables } from 'chart.js'; Chart.register(...registerables);`. Diagnosis when a
  JS feature is dead despite green PHP: open the browser console for a module-resolve error and check
  whether the import uses a subpath that isn't a key in `importmap.php`.

## Themes (one folder = one theme)
Theme STATIC assets (`themes/<name>/public/`) are served by the web server, SEPARATE
from AssetMapper (don't push theme CSS through importmap). Pattern:
- **Publish:** `php8.5 bin/console app:theme:assets:install` symlinks (copy fallback)
  every `themes/<name>/public/` to `public/themes/<name>/`. Run it when adding/changing
  a theme and on deploy. `public/themes/` is a git-ignored build artifact.
- **Reference assets in templates** with `theme_asset('css/theme.css')` — resolves up
  the active theme's parent chain (child→parent→…, same order as templates), checks the
  SERVED location (`public/themes/<name>/…`) so it never points at an unpublished file,
  and cache-busts by mtime. The default theme keeps its CSS in `public/css/theme.css`
  and loads it via `theme_asset()` — no inline `<style>` in the layout. Copy that theme
  as the starting point for a new one.
- **Render menus** with `render_menu('main')` (Twig): pulls the `Menu`/`MenuItem`
  entities for that location, resolves each URL (internal `Page` → its route, else the
  raw URL), nests children, flags the active item (home matches `/` EXACTLY; others
  match exactly or as a path prefix), and renders the theme-overridable
  `menu.html.twig` + `menu_items.html.twig`. Never hardcode nav in a layout.
- **Theme management = auto-detect + activate (V1; NO browser upload, NO CMS delete).** Admin/dev drops a
  theme folder into `themes/` (FTP / copy `default` + rename); the CMS detects it. `ThemeScanner::scan()`
  lists every `themes/*/` with a `theme.yaml` (folders without one are ignored), reading label/author/parent
  and flagging `valid` (a `layout.html.twig` resolves — own or via the parent chain, so child themes count),
  `parentMissing`, `hasThumbnail`, `isDefault`, `active`. **`ThemesController`** (`/admin/themes`, replaces
  the old EA Theme CRUD) shows the cards + activates one (CSRF; create-if-missing `Theme` row + single
  active; only a valid theme activates). Active theme is still the DB `Theme.active` row.
- **`theme.png` thumbnail convention:** optional `themes/<name>/theme.png` (root, next to `theme.yaml`;
  ~880×660). It's OUTSIDE `public/`, so the admin streams it via `GET /admin/themes/{name}/thumbnail`
  (ROLE_ADMIN; name regex-validated → no path traversal; 404 → list shows a placeholder). `themes/default`
  ships one.
- **The site ALWAYS has a working theme:** `ThemeResolver::getActiveThemeName()` verifies the active theme
  is usable (folder + resolvable `layout.html.twig`) and **falls back to `default`** otherwise — an
  FTP-deleted/broken active theme can't break the front. Plus `ThemeDeletionGuard` blocks deleting `default`
  (never), the active, or the only theme (defense-in-depth — no delete UI in V1), and `default` is
  git-tracked. **Theme upload via browser is V2** (Twig sandbox + zip validation — backlog).

## Default theme & demo content (Phase 1, pass 1 — DONE)
The default theme (`themes/default/`) is a small **design system**, not bland defaults, and
demo content seeds the whole front-end so it can be judged as a *lens* (esp. mobile 2-level nav).
This pass is intentionally scoped: **footer config, per-page hero, dark mode are LATER passes.**

- **`public/css/theme.css` = tokens-first.** Semantic CSS custom properties on `:root` drive
  colour (`--ink/--muted/--bg/--surface/--line/--brand/--brand-strong/--brand-ink/--brand-tint`),
  a type scale, a 4px spacing scale (`--s-1..8`), radius/shadow, container (`--container`) +
  readable measure (`--measure`) + nav breakpoint (`--nav-bp`). Re-theme by editing `:root`.
  **System font stack only — no external web fonts.** Every editor output is styled (h1–h6, p,
  ul/ol, blockquote, code/pre, links, hr, tables, images), plus header/nav, page header, blog
  cards, post meta, featured image, `[image]` align classes, `.tallyst-columns`/`.tallyst-column`
  (theme contract), the `[form id=N]` embed surface, and the footer placeholder.
- **Responsive 2-level nav.** Desktop: inline row + dropdown on `:hover`/`:focus-within` (or
  `.is-open`). Mobile (≤ `--nav-bp`): hamburger (`.nav-toggle`) opens the panel; parents with
  children get a `.submenu-toggle` accordion. **Progressive enhancement:** it degrades to a plain
  nested list with no JS; `themes/default/public/js/nav.js` (a tiny vanilla script loaded via
  `theme_asset('js/nav.js')`, NOT AssetMapper) only adds the toggles + keeps ARIA in sync.
  `menu_items.html.twig` emits the toggle button + `has-children` class; `menu.html.twig` gives
  the nav `id="site-nav"` (the hamburger's `aria-controls`). Accessibility: skip-link,
  `aria-expanded`/`aria-controls`, `:focus-visible` rings.
  - **nav.js MUST be Turbo-safe — learned the hard way.** The app runs `@symfony/ux-turbo`
    (`turbo-core`, `enabled: true, fetch: eager` in `assets/controllers.json`), whose
    `turbo_controller.js` does `import "@hotwired/turbo"` → **Turbo auto-starts and SWAPS `<body>`
    on navigation.** A nav script that binds inside `DOMContentLoaded` and caches element refs
    works on first load but DIES after the first internal navigation (the event never re-fires on
    a body swap; cached refs point at detached nodes). The hamburger bug was exactly this. Fix
    pattern (current nav.js): **delegate from `document`** (one `click` listener bound once on a
    node that survives swaps, resolving the control via `e.target.closest('.nav-toggle'/'.submenu-toggle')`
    at click time), an idempotent guard (`window.__tallystNavInit`) against a re-execed body
    script, and never cache element references. Any future theme JS must follow this, not the
    DOMContentLoaded-and-cache pattern.
- **Templates** keep all existing helper calls untouched (`render_branding`, `render_menu`,
  `render_content`, `media_img`, `app_date`, null-safe `featuredImage`) — only markup/classes
  changed, so nothing regresses. Page/post bodies use a `.prose` measure; `page.html.twig`/
  `post.html.twig` have a simple **page header (NO hero — hero is a later pass)**.
  - **Page vs blog layout (pass B).** Split at the template level: **`page.html.twig` uses
    `.page-content` = FULL-WIDTH** — text AND images/`.tallyst-columns`/`.fb-form-wrap` all use
    the full shell (`--container`, ~68rem), same as header/footer (text is NOT capped to a
    reading measure; decided after seeing the render). The non-hero `.page-header` is full-width,
    left-aligned. **`post.html.twig` + blog index stay NARROW (`.prose`) — do not change.**
  - **Per-page hero (pass B, opt-in).** `Page` has optional `heroEnabled`/`heroImage`
    (Media FK, `SET NULL`, reuses `MediaPickerField`)/`heroTitle`/`heroText`/`heroCtaLabel`/
    `heroCtaUrl`, edited under a collapsible **"Hero" fieldset** in `PageCrudController`. Rendered
    in `page.html.twig` (sharp corners, no border-radius), **two modes**:
    - **Overlay** — when `heroEnabled` AND (`heroTitle` OR `heroText`): a background `<img>`
      (`media_img(..., 'hero', null, '')` → `alt=""`, decorative; the `<h1>` carries meaning) under
      a CSS `::after` **dark gradient scrim** so title/text/CTA stay legible over ANY image (incl.
      light photos); `<h1>` = `heroTitle ?: title`; CTA only when label AND url are both set. No
      separate `.page-header` (the overlay carries the title).
    - **Bare image** — when `heroEnabled` AND `heroImage` but NO `heroTitle`/`heroText`: show the
      image alone (`.page-hero-image`, no overlay/scrim) and render the page `<h1>` BELOW it in the
      normal `.page-header`. (So a hero image never forces the page title onto the image.)
    Null-safe (deleted Media → no `<img>`; no hero → plain `.page-header`). **Hero is Page-only
    (not Post).** Uses a dedicated **`hero` Liip filter (~1600px inset)** so full-width heroes aren't
    upscaled-`medium` blur — and that filter name MUST be added in **THREE** places or it silently
    falls back to `medium`/404s: `config/packages/liip_imagine.yaml`, `ThumbnailWarmer::FILTERS`,
    AND `MediaImageHelper::FILTERS` (the helper has its OWN allowlist that drives the URL). Page
    featured image also uses `hero`; post featured stays `medium`.
- **`app:demo:seed`** (`src/Command/DemoSeedCommand.php`) is the demo lens. Re-runnable:
  default is additive (create-if-missing by a FIXED slug set, skip existing, **always rebuilds
  the `main` menu** — the demo OWNS it); `--fresh` deletes the whole demo set first (by the fixed
  page/post/category/form slugs + `main` menu + media whose `originalName` starts with
  `tallyst-demo-`) then recreates — the supported full-reset path (the only way to reset `home`,
  which `app:install` also creates). Cleanup only touches those fixed handles, never real content.
  **It is a demo/dev-instance tool** — don't run it on a real populated site expecting the real
  menu to survive. Order: media → forms → categories → pages → posts → menu (forms before pages so
  real `[form id=N]` ids splice into content). Demo images are **GD-generated at seed** (neutral
  abstract gradient covers), written to a temp file and pushed through `MediaUploader::upload()`
  via an `UploadedFile(..., test: true)` — no binaries committed, fully reproducible. Run
  `app:media:thumbnails:warm` after if thumbs are missing. Page/post bodies are written FULL
  (multiple `<h2>`/`<h3>` sections + several paragraphs per page) so the theme is honestly
  exercised and pages don't read as empty — keep that bar when editing the `content*()` builders.
  The seed also sets a demo **hero** on `home` + `usluge` (so the overlay is visible after `--fresh`).

## Media & uploads (Media module)
- Uploads (VichUploaderBundle) go to `public/media/uploads/` with a sanitised, unique
  namer; Liip thumbnails to `public/media/cache/` (filters `thumb`, `medium`). Both live
  under `public/media/`, which is GIT-IGNORED — uploads are **runtime data on the
  server**, backed up separately (cloud storage is a later iteration).
- **Thumbnails are PRE-WARMED on upload** (a Doctrine listener calls `ThumbnailWarmer`)
  and referenced by a deterministic RELATIVE cached-file URL
  (`/media/cache/<filter>/media/uploads/<name>`). This is mandatory under nginx: the
  Liip on-demand "resolve" URL ends in an image extension, so the static-asset location
  returns 404 before PHP runs. After deploying this / changing `filter_sets` / clearing
  the cache, warm existing images with `php8.5 bin/console app:media:thumbnails:warm`.
- Raster only (`Assert\Image`: jpeg/png/webp/gif, ≤5 MB). **SVG is intentionally
  rejected** (XSS surface; Liip doesn't thumbnail it).
- Any entity used in an EA `AssociationField`/`EntityType` picker needs `__toString()`
  (Media → `title ?: originalName`).
- **Branding** reuses the `Setting` store (`site_name`, `logo_media_id`) — no table.
  `logo_media_id` is a LOOSE reference (not a FK), so the render helper MUST be
  null-safe: `render_branding()` shows the Liip-sized logo (alt = media alt or site
  name) when the Media still exists, else falls back to the site name. It renders the
  theme-overridable `branding.html.twig` (same theme-chain pattern as menus).
- **Featured image** on Page/Post/Category is a real `ManyToOne(Media)` FK with
  `onDelete: SET NULL` (a deleted Media nulls the column — never a 500). This is the
  bounded core→Media exception (rule 1). Set it with an EA `AssociationField`; render
  it in the (theme-overridable) page/post templates, null-safe (`{% if x.featuredImage %}`).
  Why FK here but a loose Setting for the logo: featured is a per-entity relation where
  SET NULL gives real integrity; the logo is a global Setting (string by nature).
  **Pages do NOT expose featuredImage in the edit form** (the per-page hero IS the Page's image —
  featured would duplicate it). The column stays on `Page` (dormant, nullable) and `page.html.twig`
  still renders it null-safe, so existing pages with a legacy featured image don't break. Posts +
  Categories keep the featured field (no hero there → it's their list/archive thumbnail).
- **`[image id=N size=medium align=left width=full alt="..."]`** shortcode embeds a Media in
  content, mirroring `[form id=N]`. Missing/deleted id → nothing (a comment), never an
  error. `size` is whitelisted to defined Liip filters (medium default), `align` to a
  fixed CSS class, `alt` is escaped — no arbitrary attribute reaches the `<img>`.
  **`width`** is per-image (only `full` is special, else normal): `full` renders the `.media-img-full`
  class (full container width) from the **larger `hero` source** so a 100%-wide image isn't an
  upscaled-blurry medium, and full takes precedence over `align`. Default (absent) = normal → existing
  images unchanged. Set it in the editor via the toolbar **"Slika: puna širina"** toggle
  (`media--tiptap#toggleImageWidth` → toggles the selected image node's `width`; `data-width` round-trips
  through `ImageShortcodeHtmlConverter`).
- **One place builds the `<img>`:** `MediaImageHelper` (`media_img()` Twig fn) — used
  by branding, featured images AND the shortcode. Change image markup/escaping there.
- **One place validates+persists an upload:** `MediaUploader::upload(UploadedFile)` is the
  programmatic upload path (FilePond endpoint + bulk). It and the EA Media create
  (VichImageType) BOTH validate the SAME `Assert\Image` on `Media::$imageFile` — that
  entity constraint is the single source of truth (fileinfo mime + ≤5 MB), so rules can't
  diverge; do NOT duplicate the mime list elsewhere. Vich's namer/metadata + the
  postPersist thumbnail warm fire identically on both paths. It also **auto-fills empty
  title/alt** (WordPress-style) via `MediaMetadataExtractor` — IPTC ObjectName/Caption →
  EXIF ImageDescription → filename (separators→spaces); only-empty (never overwrites the
  admin) and robust (degrades to filename if the file/EXIF is unreadable; EXIF guarded by
  `function_exists`). Applies to every upload path (index bulk + modal). Backfill existing
  rows with `php8.5 bin/console app:media:backfill-meta` (touches only empty fields).
- **Pixel dimensions** (`Media.width`/`height`, nullable int) are captured at upload from the SAME
  `getimagesize` the metadata extractor already runs (no second read) and shown as `getDimensionsLabel()`
  ("1920×1080 px") in the Media list + detail. Null-safe: non-image / unreadable → null → blank in the UI.
  The backfill above ALSO fills dimensions for pre-existing rows (`findMissingMeta` matches
  `width/height IS NULL`); idempotent, skips missing files.
- **Reusable media-library component** (Stimulus, EA-shell, admin entrypoint):
  - Endpoints (Media module, under `^/admin` → ROLE_ADMIN, NOT EA-shell pages so no
    `dashboardControllerFqcn` default): `GET media_library_index` (`/admin/media/library`,
    JSON grid: id/thumbUrl/name/alt, `?q=` name·alt·title, `?page=`, 24/pg + `hasMore`)
    and `POST media_upload` (`/admin/media/upload`, FilePond process, 1 file/req, returns
    new Media id as PLAIN TEXT). Upload is **CSRF-protected**: page renders
    `csrf_token('media_upload')` → JS sends it as the `X-CSRF-Token` header →
    `isCsrfTokenValid()` (403 on mismatch).
  - `media--library` Stimulus controller = modal (grid + debounced search + "load more" +
    FilePond upload zone that re-fetches the grid). **Decoupled:** a thumbnail click
    dispatches a `media-library:select` event `{id,name,thumbUrl,displayUrl}` (thumb for
    tiles, `displayUrl`=Liip medium for content-size previews like the editor) — it never
    touches a hidden field, so consumers reuse it (the featured picker AND the editor's
    image insert both do). Open it by dispatching
    `media-library:open` on its element. Modal markup is the reusable partial
    `@Media/admin/_media_library_modal.html.twig` (today included per picker widget; lift
    to one shared instance when more consumers appear).
  - `filepond_factory.js` is the ONE FilePond setup (plugins + process endpoint + CSRF
    header + raster/≤5 MB client checks mirroring the server), shared by the library modal
    and the Media index bulk panel. FilePond is via importmap (`filepond` + image-preview +
    file-validate-type/-size, and their CSS `*.min.css` entries `import`ed from the
    factory). Media module assets: `modules/Media/assets/: media` in asset_mapper.yaml,
    controllers registered in `stimulus_bootstrap.js`.
- **Featured picker = `MediaPickerField`** (EA field, reusable on Page/Post/Category):
  swaps ONLY the widget of the existing `featuredImage` FK (no mapping change). Its
  `MediaPickerType` extends `HiddenType` + a Media↔id `CallbackTransformer` (no full
  EntityType choice list). The `media_picker` form-theme block renders preview + "open
  library" button + the modal; `media--picker` controller keeps the hidden id + preview in
  sync with the library's `select` event. Register the theme per CRUD with
  `->addFormTheme('@Media/admin/form/media_picker_widget.html.twig')`.
- **Media index = the create path:** the EA Media index template is overridden
  (`@Media/admin/index.html.twig` extends `@EasyAdmin/crud/index.html.twig`, prepends a
  FilePond drag&drop panel via `media--bulk-upload`) and `Action::NEW` is **disabled** —
  so there's no single-file create form/route; drop many images at once → each created via
  `media_upload` (shared `MediaUploader`, auto-filled meta) → the list reloads. EA **edit**
  remains for alt/title tweaks and image replacement (replacing the file changes the image
  everywhere that Media id is referenced — featured FK, `[image id=N]`, `logo_media_id`).

## Directory layout
```
src/                  # CORE
  Entity/             # Page, Post, Category, Menu, MenuItem, Theme, Setting
  Content/            # ContentRenderer + ShortcodeRegistry (replacement tags)
  Theme/              # ThemeResolver + Twig theme loader
  Module/             # ModuleInterface + ModuleRegistry
  Controller/
modules/              # MODULES — each is a lean bundle, identical structure
  FormBuilder/
    FormBuilderBundle.php
    Entity/           # FormDefinition, FormField, FormSubmission, Order
    Controller/
    Payment/          # PaymentProcessorInterface + Stripe/PayPal processors
    config/
    templates/        # @FormBuilder namespace
themes/               # THEMES — one folder = one theme
  default/
    theme.yaml        # name, author, parent
    templates/        # page.html.twig, post.html.twig, layout.html.twig
    public/           # css, js, images
```

## Architecture rules (IMPORTANT — do not violate)
1. **A module is a lean Symfony bundle.** Do NOT build a custom module-loading
   framework. Lean on Symfony's bundle system, DI, routing and Twig namespaces.
   Every new module mirrors the `FormBuilder` folder structure exactly.
   **Dependency direction:** modules depend on Core (`App\`), never the reverse — with
   ONE deliberate, bounded exception: **Media**. Because Media is foundational/
   mandatory, Core entities MAY hold a real FK to `Tallyst\Media\Entity\Media`
   (e.g. featured images). This is allowed for Media ONLY; no other module may be
   referenced from Core. Keep the exception narrow so it stays a decision, not erosion.
2. **Replacement tags go through the ShortcodeRegistry.** Core knows nothing about
   specific tags. Modules register their own (FormBuilder registers `form`).
   Content is rendered via a `render_content` Twig filter that runs the registry
   over the raw stored content.
3. **The form builder is DATA-DRIVEN — not the Symfony Form component.**
   - `FormDefinition` + `FormField` entities store the admin-built form AS DATA.
   - The end-user form is rendered and validated dynamically at runtime from that
     data. Do NOT model end-user forms with Symfony's compile-time Form component.
   - Use the Symfony Form component only for the ADMIN builder UI itself.
   - **Builder UX:** each field row is **collapsed by default** (WP-Simple-Pay style) — the
     row head is a clickable summary (chevron + label + type badge) that toggles the config
     open; `.fb-grid`/`.fb-cond` hide via CSS (inputs stay in the DOM → submit + the ↑/↓
     reorder unaffected). The `formbuilder--builder` Stimulus controller keeps the summary in
     sync as you type (`refreshSummary`/`updateSummary`) and auto-expands a freshly ADDED
     field; a field with a validation error renders expanded so the error is visible. Pure
     presentation — no data-model/submit change. Builder CSS is inline in `edit.html.twig`.
   - **Field reorder = drag-drop (SortableJS, touch + mouse) AND ↑/↓ arrows.** `formbuilder--builder`
     `connect()` inits `Sortable` on the FIELDS items container (`handle: '.fb-handle'` grip,
     `ghostClass: 'fb-row-ghost'`); `onEnd` reuses the existing `renumber()` to rewrite the
     `[data-fb-position]` inputs — identical save path to the arrows (kept as an accessible / no-Sortable
     fallback). SortableJS via importmap (`importmap.php`; `assets/vendor/` fetched at deploy). Init never
     mutates inputs → no false dirty-form trigger; new rows are draggable automatically. Drag is FIELDS-only
     (variants/rules still arrows-only). `disconnect()` destroys the instance.
   - **Edit screen is GROUPED into cards** (Osnovno / Tip forme / Naplata "samo proizvod" /
     Obavijesti / Polja forme), one template for new + edit. A **UI-ONLY "Tip forme" toggle**
     (`formbuilder--formtype`, buttons — nothing submitted, no entity flag) shows/hides Naplata
     (paid) vs Obavijesti (free); initial state derived from `FormDefinition::isProduct()`. It only
     reveals/hides — hidden sections keep submitting their values, so the toggle never clears
     price/variants (the actual mode is still "has price or variants"). The inline CSS uses
     **Bootstrap/EA theme variables** (`--bs-tertiary-bg`/`--bs-border-color`/…) NOT hardcoded hex,
     so the collection rows follow dark mode. `render_rest:false` → every `FormDefinitionType` field
     must stay explicitly rendered (incl. `allowedPaymentMethods`, `variants`).
   - **Conditional-required:** a field hidden by its display conditions is NOT required (nor
     validated, nor stored). The SERVER is authoritative — `SubmissionValidator` runs
     `ConditionEvaluator::visibleKeys()` (a CASCADING fixed point: a field whose condition
     depends on an already-hidden field is hidden too) over the submitted values and skips
     hidden fields before checking `required`. The client (`formbuilder--conditions`) mirrors
     this by removing `required` from hidden inputs. Locked by `SubmissionValidatorTest`
     (incl. the chained case) + the functional `FormSubmitConditionalRequiredTest`.
   - **Rule EDITOR is contextual (admin, `formbuilder--rules`), client-only.** The stored model
     (`{field:key, operator:one-of-7, value:string}`) + the evaluator are UNTOUCHED; the controller
     HIDES the real `_rule.html.twig` inputs and renders proxies that write back: a **field dropdown**
     of OTHER fields read LIVE from the DOM (so they work before save), a **type-aware operator**
     (checkbox → "je/nije čekirano" = `equals`/`not_equals` value `"1"` — no new operators), and a
     **value dropdown** (radio/select options) or input (text/number). It also **auto-keys** fields
     from their label (the server only slugifies when key is empty, so a client key is authoritative;
     a key referenced by a rule or manually edited is frozen so a later rename can't break the rule),
     and a per-field **"Uvjetni prikaz"** toggle hides the rules block (hide-not-delete). Graceful
     degradation: no-JS → the real inputs still work.
   - **Email-on-submit (free forms):** per-form notification config on `FormDefinition`
     (`notifyEnabled` + `notifyRecipient` comma-list + optional `notifySubject`; an
     `Assert\Callback` validates the e-mails in the admin form). On a valid FREE submit,
     `SubmissionNotifier` sends a label:value summary e-mail with **From left empty** (so
     `DefaultFromListener` applies `mail_from_email` — never hardcode From, the 553 lesson),
     `To` = the recipient(s). It's **async** via `$mailer->send()` (routes to the Messenger
     worker like the order mails → **needs a running `messenger:consume async` worker** to
     actually deliver); the controller wraps it in try/catch so a notification hiccup never
     fails the submit. PRICED forms are skipped — they keep the order/fulfilment mails.
4. **Page-as-product.** A `FormDefinition` may carry `priceMinor` (integer MINOR
   units — cents, never float) + `currency`, OR a list of price `variants`
   ({label, priceMinor}) — or-or: variants, when present, replace the fixed price (the
   buyer picks one; the server resolves the chosen index via `variantAt()`, never trusting
   a client price). A priced submission creates an `Order` and starts payment; a free form
   behaves as before.
5. **Payments use a strategy interface.** `PaymentProcessorInterface` + a registry —
   **Stripe AND PayPal** are both impls; `order.provider` routes checkout/refund/webhook.
   The interface is `getName / isConfigured / getMode / createCheckout / finalizeReturn /
   parseSignedWebhook(payload, headers[]) / refund / getWebhookEvents` — kept agnostic so each
   provider's specifics stay inside it (Stripe local-HMAC verify + auto-capture; PayPal
   local-RSA-cert verify + OAuth-for-API + an explicit capture-on-return + sandbox/live host +
   explicit mode). **PayPal = direct REST via HttpClient** (official PHP SDK archived; server SDK
   too partial).
   - **PayPal webhook verification is LOCAL/offline (RSA cert), NOT the verify-webhook-signature
     API** — that API 403s NOT_AUTHORIZED because the app's client-credentials token has no webhooks
     scope (proven: real & bogus webhook_id → identical 403). Offline needs no token/scope: SSRF-guard
     `cert_url` (https + `*.paypal.com` only), fetch+cache the cert, `openssl_verify` over
     `transmissionId|transmissionTime|webhookId|crc32(RAW body)` (SHA256). Symmetric with Stripe's
     local HMAC; the OAuth token is still used for create/capture/refund, just not verification.
   - **`finalizeReturn(Order)`** runs on the buyer's return for EVERY provider (Stripe no-op;
     PayPal captures the approved order). It is idempotent and MUST NOT set `paid` — the webhook
     stays sole truth. A capture failure is graceful (thank-you try/catch → "processing" view, no 500).
   - **Thank-you page** (`/form/order/{id}/thank-you`, `thank_you.html.twig`, themed): shows a dynamic
     order block (#id, paid/processing, amount) PLUS an admin-editable message (`thank_you_message`
     RICH_TEXT, FormBuilder "Narudžbe" settings section, rendered `setting('thank_you_message')|render_content`
     above the block; default applies when unset). **Token-guarded against enumeration:** `Order.thankYouToken`
     (`bin2hex(random_bytes(16))`, set at checkout) is carried as **`?t=`** (NOT `?token=` — PayPal appends
     its own `token`/`PayerID`), `hash_equals`-checked → wrong/missing/old-null token = **404**. Both
     providers get it (one `successUrl`). Locked by `tests/FormBuilder/ThankYouTest`.
   - **`OrderPaymentSync`** holds the paid+refund transitions and their idempotency guards in ONE
     place; BOTH webhook controllers (`/webhook/stripe`, `/webhook/paypal`) call it after their own
     verification, so every provider goes through identical guards (can't drift/bypass).
   - **Provider choice + per-product limit:** `FormDefinition.allowedPaymentMethods` (JSON, empty =
     all); `PaymentProcessorRegistry::availableFor(allowed)` = configured ∩ allowed — the one source
     for the form render (radios / hidden / "unavailable") and the submit (validate the chosen method,
     set `order.provider`). Keys live in Postavke (Stripe + PayPal sections), settings ?: env.
   - **Dashboard deep-link:** `dashboardUrl(Order): ?string` (per processor) → an OrderCrud detail-only
     action "Otvori u dashboardu plaćanja" (new tab; hidden when no PI). Stripe = a stable per-payment
     link (`/test/payments/{pi}` vs `/payments/{pi}`); **PayPal has no reliable per-transaction
     deep-link**, so it opens the (mode-correct) activity page and the capture id in the detail is used
     for lookup (honest fallback). **Mode is the order's RECORDED `paymentMode`** (set from `getMode()`
     at checkout — a historical fact, like `taxRate`), with the current mode as a fallback for
     pre-recording orders, so an old test order never mislinks to live after going live.
   - **OrderCrud surfacing:** provider badge in the list (Stripe/PayPal); detail shows `paymentMode`,
     net/tax/country/VAT/IP. CSV export adds **"Mod"** (test/live) + **"Podaci kupca"** (the submission
     summary flattened to one line — the buyer's form fields for invoicing; `fputcsv` quotes commas).
   - **Filters + filter-aware export:** `configureFilters` adds status/provider/paymentMode (ChoiceFilter),
     `createdAt` (DateTimeFilter range), `variantLabel` (TextFilter). The "Izvezi narudžbe" export
     **RESPECTS the active filter/search** — it rebuilds the SAME query the list uses via
     `createIndexQueryBuilder($context->getSearch(), $context->getEntity(), $fields,
     FilterFactory::create(...))` (EA's own pattern), so list rows == exported rows; no filter → all. The
     global-action link inherits the active `filters[…]`/`query` because `AdminUrlGenerator` merges the
     current request query. Test (`OrderCsvExportTest`, functional) seeds orders + asserts filtered subset
     vs all. **Gotcha:** a StreamedResponse in a WebTestCase is read via
     `$client->getInternalResponse()->getContent()` (the test client already buffered it; the Symfony
     response is already streamed → empty).
   Order lifecycle is a Symfony **state_machine** workflow (`order`): `pending → paid → fulfilled →
   refunded`. Critical rules:
   - The **verified webhook is the SOLE source of truth for `paid`** — never the
     submit flow or the thank-you redirect. Verify the signature (reject 400), require
     the provider's paid status, be idempotent, and ack unknown sessions with 200.
   - The webhook marks `paid` fast, then dispatches an **async confirmation** (Messenger).
     `FulfillOrderHandler` sends the customer confirmation + admin notice (via `OrderMailer`) and is
     retriable; it MUST NOT roll back `paid`. Production needs a running `messenger:consume async` worker.
   - **MANUAL FULFILMENT (Option B):** the handler does NOT advance to `fulfilled` — the order stays
     **`paid` = "awaiting delivery"** (the admin's to-do). **`fulfilled` = the admin manually marked
     delivered** via the `OrderCrudController` "Označi isporučeno" action, which applies `fulfill`
     through the state machine (never a manual status set) and sends the `order_delivered` mail. The
     admin can also re-send the confirmation. `OrderMailer` (FormBuilder/Service) builds order-mail
     tags/recipients in ONE place so the auto and manual paths send identical mail. Order badges:
     paid = `warning` "Čeka isporuku", fulfilled = `success` "Isporučeno".
   - A front form that 303-redirects to an external payment page MUST set
     `data-turbo="false"` (Turbo can't follow a cross-origin redirect).
6. **Themes resolve at runtime.** The active theme's `templates/` dir is prepended
   to Twig's loader. Support a `parent` theme for template fallback (child-theme
   behaviour). Reference: Sylius ThemeBundle is good prior art.

## WYSIWYG editor (Tiptap — content fields)
The content editor is **Tiptap** (MIT; `@tiptap/*` v3 via importmap — core/starter-kit/
extension-image; StarterKit v3 already bundles Link, so it's configured, not re-added).
AVOID CKEditor 5 / TinyMCE (GPL / commercial). It lives in the **Media module**
(`TiptapField` EA field + `TiptapType` + `media--tiptap` Stimulus controller) because it
hard-depends on the media library for image insert (the bounded Core→Media exception).
Replaces the old Trix `TextEditorField` on Page/Post.
- **Form-bound, drop-in:** `TiptapType` extends `TextareaType`; the controller mounts
  Tiptap on a hidden textarea and writes `editor.getHTML()` back on every change (and once
  on connect, so an unedited save also re-normalises). Storage stays HTML + shortcodes —
  same `content` column, no schema change.
- **Schema = what Trix could author** (`tiptap_extensions.js`, the ONE schema shared by
  the controller AND the Node test): bold/italic/strike/code, headings, bullet+ordered
  lists, blockquote, code block, link (plain `<a href>` — target/rel not forced),
  hard breaks, history, + the custom image node. **Trix `<div>` soup normalises to
  semantic `<p>` on re-save** (content changes in the DB only when the page is re-saved).
  **ProseMirror SILENTLY DROPS anything outside the schema** (tables, iframes, inline
  styles) — the Node round-trip test asserts this so the loss is known, not a surprise.
- **Toolbar has Paragraph + Clear formatting (editor-wide).** Both the page/post/footer editor
  (`media--tiptap` + `tiptap_widget.html.twig`) and the email-lite editor (`email-editor`) expose a
  Paragraph button (`setParagraph`) and a Clear-formatting button
  (`unsetAllMarks().clearNodes()` — selection-only, so nodes elsewhere are untouched; clearing an
  explicitly-selected columns block flattens it, standard "clear" behaviour).
- **Paste sanitization = the schema itself; NO custom scrubber.** The same ProseMirror DOMParser
  runs on paste as on load, so pasted colours/fonts/`<span>`/inline-`style`/classes/tables are
  dropped to the schema for FREE (both editors), while bold/italic/link/lists/headings survive.
  Do NOT add a `transformPastedHTML` HTML-scrubber that strips `style`/`class`/`data-*` — it would
  break the [image]/[form]/columns `parseDOM` on the page/post path (the very shortcode integrity we
  protect). Existing shortcode nodes are untouched by a paste (it only inserts at the cursor). Clear
  formatting handles any residual marks. (A bespoke schema-aware scrubber is a possible later
  belt-and-suspenders, not worth the shortcode risk now.)
- **Shortcode⇄node is an extension point (IoC), NOT hardcoded:** Core defines
  `EditorShortcodeConverterInterface` (auto-tagged `app.editor_shortcode_converter`, like
  `ShortcodeInterface`); `EditorContentConverter` aggregates every tagged converter and
  `TiptapType` depends only on that Core aggregator. Each module supplies its own:
  - Media → `ImageShortcodeHtmlConverter`: `[image id=N size align width alt]` ⇄
    `<img data-tallyst-image data-id=N …>` (forward resolves the Liip URL via
    `MediaImageHelper`; null-safe — deleted Media → empty src, id kept; `data-width` round-trips).
  - FormBuilder → `FormShortcodeHtmlConverter`: `[form id=N]` ⇄
    `<div data-tallyst-form data-id=N data-label=…>` card (null-safe label "Forma #N").
  Each converter touches ONLY its disjoint pattern, so the chain is **order-independent**
  (locked by `EditorContentConverterTest`). Both share `ShortcodeAttributeParser`, and
  per-converter coupling tests prove their shortcode parses identically through the real
  front pipeline. The front (`ImageShortcode`/`FormShortcode` + `render_content`) is
  UNCHANGED.
- **Multi-column layout (Prolaz C) is a PURE HTML node — NO shortcode, NO converter.** The
  `columns`/`column` nodes (`tiptap_columns_node.js`) are CORE editor features (Media-owned,
  no other module) so they're added to the schema DIRECTLY in `buildExtensions` — always
  present, like the image node — NOT via the module-gated `registerEditorExtension` path.
  Content stores as `<div class="tallyst-columns" data-columns="N"><div class="tallyst-column">
  …blocks…</div>…</div>` straight in the `content` HTML; Tiptap's parseHTML/renderHTML carry
  it in/out and the EditorContentConverter chain never touches it (disjoint patterns).
  Because the converters run over the WHOLE HTML, `[image]`/`[form]` embeds nested inside a
  column still convert (locked by `EditorContentConverterTest::testColumnsWrapper…`). v1 =
  FIXED 2/3 equal columns: not resizable, no per-column widths, no nesting. Nesting is
  forbidden at the SCHEMA level — `columns` is in its own `columns` group (not `block`) and
  StarterKit's Document is replaced by `TallystDocument` (content `(block | columns)+`), so a
  `columns` can never land inside a `column` (a malformed inner one is lifted out, not
  dropped). **CSS lives in TWO places, visually matched** (the Node round-trip test covers
  the 2/3-col round-trip, nested-embeds, and nesting-lifted; the drop assertions stay green):
  - editor preview in `modules/Media/assets/styles/tiptap.css` (grid + dashed column outline);
  - front in the theme's `public/css/theme.css` — count-agnostic grid (`grid-auto-flow:column`
    + `grid-auto-columns:1fr`) that stacks on narrow viewports (`@media → grid-auto-flow:row`).
  **THEME CONTRACT:** a theme that wants columns MUST carry that `.tallyst-columns`/`.tallyst-column`
  CSS (the default theme does); without it the columns degrade to stacked blocks on the front.
- **The editor (Media) has ZERO references to other modules** — PHP (Core interface), JS,
  and templates. Other modules plug in app-level via `registerEditorExtension({ key, node,
  toolbar })` in `stimulus_bootstrap.js`:
  - the **node** is ALWAYS added to the schema (so existing embeds round-trip safely even
    when the module is toggled off — only authoring is gated);
  - the **toolbar button** is gated by the editor against `enabled_modules()` (Core Twig
    fn → space-separated enabled module names; `key` MUST equal the module's `getName()`,
    e.g. `form_builder`). Disabled module → no button, but content stays safe.
  FormBuilder ships `tiptap_form_node.js` (atom block) + `form_picker.js` (a client-built
  modal, no server template/mount — fetches `/admin/forms-list`, inserts a `formEmbed`
  node into the editor it's handed). Image insert is Media's own (`media-library:open`).
- **Scoping / no cross-talk:** each consumer (featured `media--picker`, editor image
  insert) listens for `media-library:select` on its OWN wrapper (never document/window)
  with its OWN library modal; the form picker is handed the editor directly (no event). So
  Post edit (featured picker + image insert + form insert) has no cross-talk.
- **Tests:** PHPUnit — `tests/Media/ImageShortcodeHtmlConverterTest.php`,
  `tests/FormBuilder/FormShortcodeHtmlConverterTest.php` (boundary + coupling),
  `tests/Content/EditorContentConverterTest.php` (order-independence). Node —
  `modules/Media/tests/js/tiptap_roundtrip.test.mjs` (real Tiptap schema incl. the form
  node via `@tiptap/html`: rich round-trip, drop detection, toolbar gating). JS test deps
  are dev-only (`package.json`, `node_modules` git-ignored); the app still ships
  build-free via importmap. Run JS tests: `npm run test:js`.

## Settings (typed layer over the Setting store) & email
A typed, grouped settings layer sits ON TOP of the untyped `Setting` key/value store — no
new table, no migration (encrypted values are just text in the existing `value` column).
- **Schema is IoC**, like the shortcode/module registries. `SettingsSectionProviderInterface`
  (`#[AutoconfigureTag('app.settings_section')]`) → `SettingsRegistry` aggregates sections.
  Core ships `CoreSettingsProvider` (sections: **General**, **Branding**, **Lokalizacija**,
  **Email**, **Footer**); modules add their own — **FormBuilder ships the "Stripe" + "PayPal"
  sections** (`StripeSettingsProvider`: `stripe_secret_key`/`stripe_webhook_secret` PASSWORD-encrypted
  + `checkout_locale`; `PayPalSettingsProvider`: `paypal_client_id` + `paypal_client_secret`
  PASSWORD-encrypted + `paypal_webhook_id` + explicit `paypal_mode`), so payment config lives in the
  module, not Core. A
  `SettingDefinition` (key, `SettingType`, label, help, default, choices, `encrypted`) is the
  single description used to BUILD the form AND cast values. `footer_menu`'s choices are built
  dynamically from `MenuRepository` (name → menu **location**, which `render_menu` consumes).
- **Field types beyond text/bool/int/choice/email/password:** `SettingType::MEDIA` (a Media
  reference stored as its id string — logo, favicon) and `SettingType::RICH_TEXT` (HTML +
  shortcodes — footer text). `SettingsController::formType()` maps MEDIA → `MediaIdPickerType`
  and RICH_TEXT → `TiptapType` (both Media-module form types — same Core-admin→Media precedent as
  `PageCrudController`; their form themes are added in `settings.html.twig`). Both are
  **string-backed**, so `SettingsManager` needs no special casting. `MediaIdPickerType` reuses the
  `media_picker` widget + `media--picker` controller + library modal but keeps the model as the id
  string (no Media↔id transformer; `buildView` resolves the preview). `TiptapType` brings the same
  shortcode⇄HTML round-trip as page/post content, so `footer_text` renders via `render_content`.
  The settings page is in the EA shell (admin Stimulus entrypoint), so both widgets boot.
- **`SettingsManager`** is the only typed read/write path over `SettingRepository`: casts by
  type (bool→'1'/'0', int), applies schema defaults, and encrypts/decrypts `encrypted`
  settings. **Write-only secrets:** an empty incoming value for an encrypted setting is a
  NO-OP (keeps the stored value) — that's what lets the UI render the SMTP password field
  empty ("•••• nepromijenjeno") and only overwrite when the admin types a new one.
  `getForForm()` NEVER returns a secret.
  - **GOTCHA — browser autofill clobbers write-only secrets (learned the hard way, twice).** The
    "empty = keep" guard only protects EMPTY submits. A browser password manager can AUTOFILL the
    SMTP-password field with a saved credential; that non-empty value sails through and overwrites
    the real secret on a normal Settings Save (symptom: silent `535` SMTP auth after saving an
    unrelated tab; the stored ciphertext length changes though nobody typed). Mitigation in place:
    the field carries **`autocomplete="new-password"`** (`SettingsController::formOptions`) so the
    browser leaves it alone. That depends on browser cooperation; the real fix (QUEUED) is to
    **isolate the SMTP password into its OWN submit** (a separate `<form>` like the test-mail form
    already on that page), so the bulk Save never carries the secret at all — browser-independent.
    NOTE: a "submitted == current decrypted → no-op" guard does NOT help (autofill writes a
    *different* value), so don't bother with that approach.
- **`SettingsEncryptor`** = libsodium `crypto_secretbox` (authenticated), key from
  `SETTINGS_ENCRYPTION_KEY` env (base64 of 32 bytes, injected pre-decoded via the `base64:`
  env processor; real key in `.env.local`, empty placeholder in `.env`). Stored form is
  `base64(nonce.cipher)` — fresh nonce per write, so the same plaintext never repeats. Key
  length is validated lazily, so an unconfigured env still boots. Only the SMTP password is
  encrypted today. **`app:install` generates a key into `.env.local` if one is missing** (via
  `EncryptionKeyProvisioner`) and NEVER overwrites an existing one — so a fresh deploy is
  self-provisioning (admin runs `app:install` as the server user, same as the rest of the seed).
- **Decrypt failure is graceful, never a 500.** If an encrypted setting can't be decrypted
  (key rotated/lost/corrupt), `SettingsManager::get()` returns the schema default (treats it as
  unset) and `isEncryptedValueReadable($key)` reports `false`. The mailer then treats DB SMTP as
  incomplete and falls back to env `MAILER_DSN`; the settings page shows a warning ("SMTP lozinku
  nije moguće dekriptirati, upiši ponovno"). It never sends unauthenticated or throws.
- **Friendly form** = `SettingsController` (`/admin/settings`, in the EA shell via the
  `dashboardControllerFqcn` route default). Fields are
  built dynamically from the schema, grouped into Bootstrap tabs per section, saved through
  `SettingsManager` (admin-only, class-level `ROLE_ADMIN`). It REPLACES the raw
  `SettingCrudController` in the menu. `site_name` + `site_tagline` live in **General**.
  **Branding** holds `logo_media_id` + `favicon_media_id` (the old standalone `/admin/branding`
  page + `BrandingController`/`BrandingType` were REMOVED; the logo editor moved here). The front
  reads the same `logo_media_id` key, so `render_branding()` is unchanged. **Footer** holds
  `footer_columns` (1/2), `footer_text` (rich), `footer_menu` (a menu location), `footer_copyright`
  (empty → auto `© {year} {site_name}`), `footer_show_powered_by` (bool). **Favicon** renders via
  `favicon_url()` (`MediaRuntime`) → a DETERMINISTIC cached URL through `MediaImageHelper` (NOT an
  on-demand Liip resolve — nginx pre-warm gotcha), using a new `favicon` Liip filter (~64px) added
  to ALL THREE allowlists (liip config + `ThumbnailWarmer::FILTERS` + `MediaImageHelper::FILTERS`).
  The theme footer is driven by these settings (`render_menu(location, {template:
  'footer_menu.html.twig'})` for the menu column — `render_menu` now takes an optional options arg).
- **Settings take effect** (not just save): `LocaleSubscriber` (kernel.request, priority 100)
  applies `app_locale` to the request + `app_timezone` to PHP's default tz; the
  `SettingsExtension` Twig `setting('key')` + `app_date(date, format?)` apply locale/timezone/
  date-format on the front (the theme's hardcoded `|date('d.m.Y.')` were swapped to `app_date`).
- **DB-driven mailer = ONE source of truth.** `SettingsMailerTransport` **decorates the
  mailer transport** (`mailer.transports`), which BOTH the synchronous Mailer AND the async
  Messenger `MessageHandler` resolve through — so sync and async mail (incl. FormBuilder
  order/payment confirmations) all use the admin-configured SMTP, with env `MAILER_DSN` as
  fallback when `smtp_host` is empty. The transport is built per-send via `EsmtpTransport`
  constructed PROGRAMMATICALLY (host/port/tls from `smtp_encryption`: ssl→implicit TLS/465,
  tls→STARTTLS/587, none→off; username; **password decrypted only in-memory**) — never via a
  DSN string, so the password can't leak into logs/profiler. A long-running Messenger worker
  caches the Setting row for its lifetime, so changing SMTP settings needs a worker restart.
  **OPS GOTCHA (dev): running `cache:clear` (or any deploy that rebuilds the cache) while the
  worker is running CRASHES it** — in dev the long-running process holds the old split container
  (`var/cache/dev/ContainerXXXX/*`), which `cache:clear` deletes, so it dies on its next message
  (and a reserved message stays stuck until the redeliver timeout). ALWAYS
  `systemctl --user restart tallyst-messenger` after `cache:clear`. (Goes away under `APP_ENV=prod`
  — one compiled container.)
  `DefaultFromListener` (MessageEvent) fills From/Reply-To from the email identity settings on
  any message that didn't set its own; the decorator passes the global dispatcher to the SMTP
  transport so this fires for DB-SMTP sends too. **Handlers MUST NOT hardcode a From** — a
  From the SMTP account isn't allowed to send as is rejected by real servers (`553 Sender
  address rejected: not owned`), so the message silently lands in the `failed` queue and never
  arrives. Leave From unset and let this listener apply the configured `mail_from_email` (which
  MUST be an address the SMTP account owns). This bit FormBuilder once: `FulfillOrderHandler`
  hardcoded `noreply@tallyst.org`, so paid orders fulfilled but no confirmation was ever
  delivered until the hardcode was removed. **"Pošalji test mail"** (CSRF-protected POST)
  takes an editable recipient (default = sender, else the logged-in admin) and its flash
  reports BOTH which transport carried it (`activeTransportLabel()` → "DB SMTP (host)" vs
  "env MAILER_DSN (fallback)") AND, on failure, the REAL transport exception message — so
  there's no guessing where a message went. **The test send is SYNCHRONOUS — it calls the
  transport directly, NOT `$mailer->send()`** — because the app routes `SendEmailMessage` to
  Messenger `async`, so a bus send would only ENQUEUE (needing a running worker, and SMTP
  errors would surface in the worker, not the flash). Bypassing the bus means the bus-only
  `DelayedEnvelope` that lets `DefaultFromListener` fill an empty From isn't used, so the test
  message sets From/Reply-To from settings EXPLICITLY (`buildTestEmail()`; From falls back to
  the recipient) — else `Envelope::create()` throws on an empty From and some SMTP servers
  reject a missing From. Scope: the test checks SMTP config only; it does NOT verify the
  worker is running for real (async) order mail — that's a separate concern (a future
  readiness/heartbeat panel). Real order/payment mail still (correctly) goes async via the
  worker; only the test button is synchronous.
- **Tests:** `tests/Settings/` — encryptor round-trip (ciphertext≠plaintext, wrong-key fails,
  bad-key-length throws), manager typed get/set + defaults + password write-only + secret
  never prefilled + undecryptable-secret-is-graceful, `isEncryptedValueReadable`, `app_date`
  tz/format, `LocaleSubscriber`, `EncryptionKeyProvisioner` (gen-if-missing / never-overwrite);
  `tests/Mailer/` — transport build / env-fallback / decrypt-fail-fallback + transport label.
  **GATE before working on encryption: `php8.5 -m | grep sodium`.**

## Email templates engine (editable mails) — PASS 1 DONE
All 4 customer/admin mails are admin-editable (subject + HTML body + enabled) via one engine.
**The rich editor is PASS 2 (queued)** — pass 1 ships a plain-textarea editor + tag reference.
- **Model = override-only.** `EmailTemplate` (`identifier` unique = type key, `subject`, `body`,
  `enabled`) holds ONLY the admin's override per type. Known types + DEFAULT subject/body + tag
  inventory live in CODE (the registry). LAZY: send/edit uses `DB override ?? registry default`,
  nothing is seeded.
- **Type registry is IoC** (`app.email_type`, like settings sections): `EmailTypeProviderInterface`
  → `EmailTypeRegistry`. Core (`CoreEmailTypeProvider`) ships `password_reset`; **FormBuilder owns
  its own mail types** (`order_confirmation`, `order_admin`, `order_delivered`, `order_refunded`, `form_notification`) so Core never
  touches `Order`. An `EmailType` carries key/label/tags(name=>desc)/requiredTags/canDisable/
  defaultSubject/defaultBody. The send SITE builds the `{tag}` VALUES from its context (the registry
  only declares the inventory).
- **Rendering = SAFE placeholder replacement, NOT Twig-eval of admin content (no SSTI).**
  `EmailRenderer`: only the type's ADVERTISED `{tags}` are replaced — a known tag with NO value →
  EMPTY (never a literal `{tag}`), an unknown `{x}` is left as typed. BODY tag values are
  `htmlspecialchars`-escaped (admin markup passes raw — admin trusted for markup); SUBJECT values are
  stripped of CR/LF (header-injection safety), not HTML-escaped. Only the base layout
  (`templates/emails/base.html.twig`) is real (trusted) Twig; the rendered body is injected `|raw`.
- **Absolute URLs (the reset-link lesson).** The base layout + reset link use ABSOLUTE URLs from the
  router CONTEXT (request host in web, `default_uri` in the worker) — mail is sent without a request.
  The logo is `base_url ~ branding_logo_url(...)`.
- **`EmailSender` is the ONE send path** (`send(type, tagValues, to, ?subjectOverride)`): skip if the
  template is disabled — EXCEPT a non-`canDisable` type (reset) always sends (a stale DB row can't
  break reset); render → `Email` with **From UNSET** (DefaultFromListener / 553) + html + text
  fallback → `$mailer->send()` (async via the worker). All 4 call sites are one-liners, so the
  From/async/absolute-URL discipline can't drift. (`subjectOverride` preserves a form's own
  `notifySubject`.)
- **Reset guard (3×):** `password_reset` has `requiredTags=['reset_url']`, `canDisable=false`; the
  admin editor rejects a save whose body lacks `{reset_url}` AND forces `enabled=true`; `EmailSender`
  treats non-`canDisable` as always-send.
- **Admin:** `EmailTemplateController` (`/admin/email`, class-level `ROLE_ADMIN`, EA shell) — list from
  the registry → per-type edit with the type's tags as reference. Linked under the dashboard **Sustav**
  section; in `AdminAccessTest::ADMIN_ONLY`.
- **Body editor (PASS 2 — DONE).** The body is a **Tiptap-LITE** editor (`email-editor` Stimulus
  controller, `assets/controllers/email_editor_controller.js`), **Option A: raw HTML in/out, NO
  EditorContentConverter and NONE of the page-content nodes** (columns/`[form]`/media image) — email
  has no shortcode concept. It is NOT `TiptapType` (whose PHP transformer runs the converter): the
  body stays a plain `TextareaType` and the controller mounts Tiptap on it (`@tiptap/starter-kit`,
  headings limited to h2/h3), syncing `getHTML()` → the textarea. Toolbar: bold/italic/link/lists/
  h2/h3/paragraph. **"Insert tag"** buttons (per type, from the registry) drop the **literal `{tag}`
  text** at the cursor — a bare placeholder, NOT a custom node, so the Pass-1 replacement engine is
  untouched. **Reset guard survives** the editor: validation runs on the SUBMITTED HTML
  (`bodyMissingRequiredTags`), and Tiptap leaves `{…}` literal (not HTML-special) — locked by
  `EmailRendererTest` asserting `<p>{reset_url}</p>` passes. Mandatory after JS edits:
  `asset-map:compile` + `app:theme:assets:install` (else the editor won't boot).
- **Tests:** `tests/Email/EmailRendererTest` (value HTML-escaping = security, empty-on-missing-tag,
  subject CRLF-strip, required-tag detection incl. the editor-output `<p>{reset_url}</p>` case);
  `SubmissionNotifierTest` retargeted to the engine.

## Admin dashboard (widgets)
The `/admin` landing (`DashboardController::index`) renders **IoC widgets** — same tagged-provider pattern as
settings/email/menu sections, so Core never queries module data. `DashboardWidgetInterface`
(`#[AutoconfigureTag('app.dashboard_widget')]`): `getPosition()`, `getRequiredRole(): ?string` (the
controller skips widgets the user's role lacks → **editors see no revenue**), `getTemplate()`, `getData()`.
Core ships `ContentDashboardWidget` (pos 20, role null — counts + recent posts); **FormBuilder ships
`OrdersDashboardWidget`** (pos 10, ROLE_ADMIN — revenue, so Core never touches Order).
- **Revenue rule:** counted from **paid + fulfilled** only; **refunded/pending EXCLUDED** (money returned /
  never captured). Aggregated **in the DB** (`OrderRepository::revenueTotals`/`countPaidSince`/`countByStatus`/
  `revenueByDay`/`recentOrders` — GROUP BY, never load-all-then-sum), **per currency** (chart shows the
  primary = most-revenue currency; cards show all). Locked by `OrderDashboardStatsTest`.
- **Chart** = Chart.js via importmap (`import { Chart, registerables } from 'chart.js'; Chart.register(...)`
  — NOT `chart.js/auto`, see the AssetMapper subpath gotcha), client-side period switch (7d/30d/12mj + date
  range): server sends ~13 months of DAILY revenue once; `formbuilder--dashboard-chart` re-aggregates
  (≤60-day window → per day, else per month). **No date-adapter** dep — category X axis with string labels.
  Dark-mode safe (reads `--bs-body-color`/`--bs-border-color`/`--bs-primary` at draw; EA uses `data-bs-theme`).
  Empty series → "Nema podataka", never throws. The "Čeka isporuku" card deep-links to the paid-filtered order list.

## SEO — sitemap.xml + robots.txt
Public, on-demand, no auth (`SitemapController`). **`/sitemap.xml`** lists published content only: home `/` +
`/blog`, published Pages (skip the **`home`** slug — it's emitted as `/`, never `/home`), published Posts
(`<lastmod>` = `publishedAt`; Pages/Categories have no timestamp so no lastmod), and categories with ≥1
published post (`CategoryRepository::findWithPublishedPosts` — empty archives = thin content, excluded).
**`/robots.txt`** → `Allow: /` + `Disallow: /admin` + `Sitemap:` line. URLs are **ABSOLUTE from
`%env(DEFAULT_URI)%`** (the canonical host, not the request host — `rtrim(baseUri) . generate(ABSOLUTE_PATH)`).
No route conflict with `page_show /{slug}` (the `.` in the filenames isn't in `[a-zA-Z0-9\-]+`). **Twig XML
gotcha:** in a `.xml.twig` the `<?xml … ?>` declaration must be **literal text on line 1** (emitting it via
`{{ '<?xml…' }}` produced NO output → malformed XML); `<loc>` values are explicitly `|e`-escaped (xml
templates aren't HTML-autoescaped). Locked by `tests/Functional/SitemapTest`.

## Frontend search (MySQL FULLTEXT)
Public search over **published** Pages/Posts/Categories — self-hosted, zero external deps, native MySQL
FULLTEXT. `SitemapController`-style public route **`/pretraga`** (`SearchController` → `SearchService` →
theme `search.html.twig`).
- **Indexes:** per table a FULLTEXT on the **title/name alone** + one on the **body** (`page` title|content;
  `post` title|(excerpt,content); `category` name|description) — two indexes so the score can **weight the
  title ×2** above body (`MATCH(cols)` needs an index on exactly those cols). Declared via
  `#[ORM\Index(flags:['fulltext'])]` (stays in `schema:validate` sync). **`innodb_ft_min_token_size`
  default 3** → tokens <3 chars aren't indexed (we don't touch server config; handled gracefully).
- **Queries:** native SQL per repo (`{Page,Post,Category}Repository::search…`), `MATCH … AGAINST(? IN
  BOOLEAN MODE)`, **parameterised positional `?`** (DBAL won't reuse a named param; `LIMIT` is an inlined
  trusted int). Pages/Posts filter `status='published'`; categories are all public.
- **`SearchService`:** tokenises the query to `[\p{L}\p{N}]+` (drops boolean operators `+ - * " ( )` →
  injection-safe), keeps tokens ≥3 chars, builds `token*` (prefix wildcard — Croatian without stemming:
  `licenc*`). No token ≥3 → state `short`. Merges all types, sorts by score DESC (mixed by relevance, type
  badge in the UI), caps 25. **Snippet:** strip shortcodes+tags → window around first hit → `htmlspecialchars`
  THEN inject `<mark>` (tokens are word-chars, so escaping is safe; template prints `|raw`).
- **Toggle:** General setting **`search_enabled`** (BOOL, **default OFF** — simple sites stay field-free;
  null-safe via `setting()`). The header field shows only when on; **the `/pretraga` route always works**
  (bookmarks/direct links don't break — only the UI field is gated). NOT on the maintenance exempt list
  (it's a visitor feature → goes behind maintenance with the rest of the site). Locked by
  `tests/Functional/SearchTest` (ranking, draft-exclusion, short/empty, XSS-escape).
- **Live dropdown (instant results):** `GET /pretraga/live` → JSON `{results:[{title,type,url,snippet}]}`,
  reuses `SearchService::search($q, 5)`. `SearchService` splits `excerpt()` (PLAIN windowed text) from
  `highlight()` (`<mark>` wrap): the page snippet is `highlight(escape(excerpt))` (HTML, `|raw`), the DTO
  also carries `snippetText` (plain), and the live endpoint sends a ~100-char plain shortening → the JS
  renders it via `textContent` (no `<mark>`, XSS-safe). Type badges use `--brand-strong` on `--brand-tint`
  (dark-on-pale; `--brand-ink` white-on-tint was unreadable). Gated on the same `search_enabled`
  (off → `{results:[]}`, no 404 — the field is hidden anyway). `search--live` Stimulus controller
  (registered in `stimulus_bootstrap`, boots on the front via `app.js`) on the header form: **debounce
  250ms**, **min 3 chars**, **race guard** (AbortController cancels in-flight + a monotonic `seq` drops
  stale responses), **XSS-safe render via `textContent`/`el.href` — never innerHTML**, close on
  Escape/click-outside (listener bound in `connect`, removed in `disconnect` → Turbo-safe), arrow+Enter
  keyboard nav, "Prikaži sve →" footer → `/pretraga`. Progressive enhancement: no JS → the form still
  submits to `/pretraga`. **Theme contract:** the header form renders `data-controller="search--live"` +
  input/dropdown targets (like nav.js). Locked by `tests/Functional/SearchLiveTest` (top-5 cap,
  draft-excluded, JSON shape, XSS, short, toggle-off).

## Maintenance mode
Admin toggles it in **Postavke → Održavanje** (`maintenance_enabled` BOOL default off + `maintenance_message`
RICH_TEXT). `MaintenanceSubscriber` (kernel.request, **priority 7** — just AFTER the firewall@8 so
`isGranted` sees the user) serves public visitors a **503 + `Retry-After`** standalone page
(`templates/maintenance.html.twig`, no theme/nav, message via `render_content`) — 503 not 200 so Google
doesn't deindex.
- **Anti-lockout (double net):** the `^/admin` prefix is exempt (admin + `/admin/login` + reset + 2fa all
  live there → the admin can always get in to switch it off), AND a logged-in **ROLE_ADMIN bypasses** it
  (live preview). **Always-public exempt** too: `^/webhook`, `/sitemap.xml`, `/robots.txt` (providers +
  crawlers can't authenticate). Search (`/pretraga`) is NOT exempt (visitor feature → goes down with the site).
- **Fail-open on the read:** the toggle is read in a try/catch — a settings/DB hiccup must never 503 the
  whole site; only an explicit ON triggers maintenance. Locked by `tests/Functional/MaintenanceTest`.
- **Admin reminder:** an EA layout override (`templates/bundles/EasyAdminBundle/layout.html.twig`, overrides
  the `flash_messages` block) shows a Bootstrap `alert-warning` "Maintenance mode aktivan" banner on EVERY
  admin page when on (link to Postavke → Održavanje). `setting('maintenance_enabled')` drives it.
- **Dev toolbar:** `/_` paths (`_wdt`/`_profiler`) are exempt (else the toolbar's AJAX got 503'd → "error
  loading the web debug toolbar"), and the subscriber `$profiler?->disable()`s on the maintenance response
  (no `X-Debug-Token` → WDT skips injection → clean page). `?Profiler` is injected `@?profiler` (null in prod).

## Roles & access (back-office)
Two roles: **ROLE_ADMIN** (everything) and **ROLE_EDITOR** (content only — Pages, Posts,
Categories, Media). `role_hierarchy: ROLE_ADMIN ⊇ ROLE_EDITOR`, so existing admins keep full
access automatically and are never demoted.

- **The `^/admin` firewall requires only ROLE_EDITOR** (the minimum to enter the back-office).
  Per-section access is enforced ON THE CONTROLLER with `#[IsGranted(...)]`, NOT by hiding the
  menu and NOT by path `access_control` (EA routes don't map cleanly to paths). Menu
  `->setPermission('ROLE_ADMIN')` is COSMETIC — an editor who types an admin URL directly must
  still get 403.
- **⚠️ FAIL-OPEN — every new `/admin` controller is editor-reachable by default.** Because the
  firewall only requires ROLE_EDITOR, a controller WITHOUT a guard is open to editors. So:
  **admin-only controllers MUST carry `#[IsGranted('ROLE_ADMIN')]`** (class-level for CRUD +
  custom controllers; method-level when the class also has editor-reachable actions, e.g.
  `DashboardController::modules()`/`toggleModule()` while the dashboard landing stays open).
  Content controllers carry an explicit `#[IsGranted('ROLE_EDITOR')]` too (self-documenting).
  This discipline is MACHINE-ENFORCED for CRUD controllers by
  `tests/Security/CrudControllerAccessAnnotationTest` (fails if any `*CrudController` lacks an
  explicit role guard), and the real 403s are verified by the functional
  `tests/Functional/AdminAccessTest` (whose `ADMIN_ONLY` list is the COMPLETE set of
  admin-guarded routes — keep it in sync).
- **Editor-content endpoints MUST stay ROLE_EDITOR** even though they live under `/admin`:
  `media_library_index` + `media_upload` (image insert + featured picker) and
  `form_builder_picker_list` (insert `[form id=N]`). Tightening these to ROLE_ADMIN breaks
  content editing for editors.
- **User CRUD** (`UserCrudController`, admin-only): roles are a friendly choice
  (Administrator/Urednik). The password field is **form-only + unmapped**, hashed via a
  `POST_SUBMIT` listener (the EA recipe) — blank on edit = unchanged, plaintext never persisted.
- **Lockout protection** (`AdminLockoutGuard`): you can't delete or demote the **last admin**,
  nor remove **your own** admin role. Enforced server-side in `updateEntity`/`deleteEntity` as
  redirect+flash (skip `parent::` → no flush) — never a 500, never the dangerous mutation. **UI level:**
  `UserCrudController::configureActions` hides the Delete action when `blockDelete` would block it
  (`displayIf`), mirroring the server rule (role-strip stays server-only — can't hide an in-form field).
- **Integrity guards (same class — don't let the admin break the app):** beyond the admin lockout,
  (1) **`ThemeDeletionGuard`** blocks deleting the **active** or the **only** theme
  (`ThemeCrudController::deleteEntity` flash+abort + `configureActions` hides Delete); (2) **core modules
  can't be disabled** — `ModuleInterface::isCore()` (FormBuilder + Media return true), `toggleModule`
  rejects a core toggle even on a forged request, the "Instalirani moduli" page shows a "Core" badge with
  no Isključi button, and `ModuleStateManager::isEnabled()` treats core as always-enabled (self-heals a
  legacy `'0'`). NOTE: disabling a module is SHALLOW — it only hides that module's admin menu items +
  editor toolbar button; routes/services/entities/shortcodes/webhooks come from the always-loaded bundle
  and keep working. So a disabled core module just made the admin LOOK broken — hence the ban.
- **Unsaved-changes guard** (`assets/admin_dirty_guard.js`, admin entrypoint): warns before leaving a
  dirty edit/new form. **Turbo-aware** (admin runs Turbo Drive) → both `beforeunload` (close/reload) AND
  `turbo:before-visit` (in-app nav). Dirty = serialize-and-compare against a snapshot taken AFTER
  controllers settle (NOT raw input events — Tiptap/form-builder mutate inputs on connect → false dirty).
  Covers EA crud forms via `body.ea-edit`/`ea-new` + custom forms tagged `data-dirty-guard`; submit /
  `turbo:submit-start` suppresses the warning on save.
- **`app:user:create --role`** defaults to ROLE_ADMIN (bootstrap admin) and rejects anything
  other than ROLE_ADMIN/ROLE_EDITOR.
- **Forgot-password** = SymfonyCasts ResetPasswordBundle. Flow lives under **`/admin/reset-password`**
  (next to `/admin/login`): request e-mail → `app_forgot_password_request`, tokenised link →
  `app_reset_password` (`/reset/{token}`), set new password (same `UserPasswordHasher`), token
  consumed. The routes are **PUBLIC_ACCESS** and that rule MUST come BEFORE `^/admin` in
  `security.yaml` (first-match) — the page is for someone who can't log in. The reset templates are
  STANDALONE pages styled like the login (`templates/reset_password/_layout.html.twig`), not the
  front layout; the login has a "Zaboravljena lozinka?" link. The reset e-mail goes through the
  hardened mailer with **From left empty** (the generated `->from()` was removed → `DefaultFromListener`
  applies `mail_from_email`; 553 lesson) and is **async** (`$mailer->send()` → `SendEmailMessage` →
  needs the `messenger:consume async` worker to deliver). Security defaults are KEPT, do NOT weaken:
  token single-use + **hashed in the DB** (`reset_password_request`) + 1h expiry, request throttling,
  and **anti-enumeration** (an unknown e-mail gets the SAME `check-email` redirect and sends nothing).
  `not_compromised_password` is disabled only `when@test` (no HIBP network call in tests). Locked by
  `tests/Functional/ResetPasswordTest.php` (request+queued-email, anti-enum, valid-token reset,
  invalid-token reject). **The reset link is an ABSOLUTE URL rendered in the WORKER (no HTTP
  request), so it uses `framework.router.default_uri` = `%env(DEFAULT_URI)%` — set `DEFAULT_URI`
  to the real public URL in `.env.local` (e.g. `https://tallyst.org`), else the link defaults to
  `http://localhost`.** (Web-context URLs are fine — they use the real request host.)
- **Two-factor (TOTP) = scheb/2fa-bundle** (`scheb/2fa-bundle` + `-totp` + `-backup-code`;
  `endroid/qr-code` for the QR — the `scheb/2fa-qr-code` sub-package has no Symfony-8 release).
  **Opt-in, self-service** ("Sigurnost" page `/admin/security`, `SecurityController`, ROLE_EDITOR+,
  visible to ALL logged-in users — no `setPermission` on the menu item). `User` implements scheb's
  TOTP `TwoFactorInterface` + `BackupCodeInterface`; columns `totp_secret` / `totp_enabled` /
  `backup_codes` (JSON, **hashed** — sha256 is fine ONLY because the codes are high-entropy: 10×
  32-symbol ≈ 50 bits; shorten them and you'd need a slow hasher instead).
  - **Confirm-before-activate (anti-lockout):** `isTotpAuthenticationEnabled() = totpEnabled &&
    secret`. Enrolment stores a secret with `totpEnabled=false` (INERT — existing users and a user
    mid-enrolment log in normally); it flips true ONLY after a submitted code validates. Each GET
    of the enable page regenerates a fresh secret (overwrites a stale pending one). Backup codes are
    shown **once** (plaintext in the session, then cleared). **Disable requires the current password**
    (a hijacked session can't strip 2FA).
  - **Firewall flow:** `two_factor` under the `main` firewall (`auth_form_path: 2fa_login`,
    `check_path: 2fa_login_check` → `/admin/2fa` + `/admin/2fa_check`). `access_control` grants
    `^/admin/2fa` to `IS_AUTHENTICATED_2FA_IN_PROGRESS` and it MUST come BEFORE `^/admin` (first-match).
    After the password, scheb wraps the token (it must be one of `scheb_two_factor.security_tokens`;
    form_login yields `UsernamePasswordToken`, which is listed) and the firewall lands on the saved
    target (/admin), which **bounces** to /admin/2fa — so functional tests FOLLOW redirects, and use
    the REAL form-login (NOT `loginUser()`, which bypasses the firewall → skips the challenge). The
    challenge form is `templates/security/2fa_form.html.twig` (standalone, login-styled). **Reset does
    NOT bypass 2FA** — a password change leaves the 2FA fields intact, so login still challenges.
  - **Lockout escape hatch:** `php8.5 bin/console app:user:2fa:disable <email>` clears
    secret + enabled + backup codes (server-side recovery if both the app and the codes are lost).
  - Locked by `tests/Security/UserTwoFactorTest`, `tests/Command/Disable2faCommandTest`,
    `tests/Functional/TwoFactorTest` (no-2FA straight-in, valid/invalid TOTP, backup-code consume,
    reset-still-challenges, enrol confirm). NOT done: required-2FA-for-admins, trusted devices,
    SMS/e-mail 2FA, WebAuthn, full self-profile.
- **Login throttling** (brute-force): `login_throttling: { max_attempts: 5, interval: '15 minutes' }`
  on the `main` firewall. Symfony registers TWO limiters — LOCAL (username+IP, 5/15min) AND GLOBAL
  (IP only, 5×max = 25/15min); a successful login resets them. Storage = `cache.rate_limiter`
  (filesystem). E-mail reset is the escape if locked out. Enabled in ALL envs (incl. test) — the
  throttle message shows on the login form (English, like "Invalid credentials"; no hr security-domain
  translations). Locked by `tests/Functional/LoginThrottlingTest` which CLEARS the `cache.rate_limiter`
  pool (setUp/tearDown) and uses a unique username so it's deterministic via the local limiter and
  doesn't pollute other functional logins (which succeed → reset anyway).
- **Self-service password change** lives on the "Sigurnost" page (`SecurityController::index`, GET|POST,
  `ChangeOwnPasswordType`): `currentPassword` re-auths via `#[SecurityAssert\UserPassword]` (a hijacked
  session can't change it without the current one); the new password reuses the reset form's rules
  (Length≥12 + PasswordStrength + NotCompromisedPassword, the last disabled `when@test`). **Gotcha
  handled:** changing your OWN password must mutate the SAME instance `getUser()` returns (the token's
  user) then flush — so the refreshed token keeps the new hash and the session SURVIVES; hashing onto a
  re-fetched User would log you out on the next request. Asserted by `tests/Functional/PasswordChangeTest`
  (still-authenticated-after-change + wrong-current + weak-new). **Auth thread is parked here.**
- **Functional tests need a migrated test DB.** This server uses a separate `tallystcmstest`
  database (its own MySQL user); the connection lives in **`.env.test.local`** (git-ignored).
  The `test` env does NOT load `.env.local`, so `.env.test.local` must carry `DATABASE_URL`
  AND `SETTINGS_ENCRYPTION_KEY`. The test DB name is `<DATABASE_URL db><TEST_DB_SUFFIX>`
  (`TEST_DB_SUFFIX` default `_test`; set it empty when the URL already names the test DB, as
  here). Provision once: create the DB + user, then `APP_ENV=test php8.5 bin/console
  doctrine:migrations:migrate -n`. (Fallback for a box that can't create a separate DB: point
  `DATABASE_URL` at the main DB with `TEST_DB_SUFFIX=` empty — tests self-clean, but it's not
  isolated.)
- **Test-cache: warmed automatically.** A cold `test` container recompile can throw in phpunit's
  kernel-boot path (a FormBuilder prototype-loader quirk), failing every functional (WebTestCase)
  test with a container error. `tests/bootstrap.php` now runs `cache:warmup --env=test` up-front
  (idempotent — recompiles only when sources changed, compiles cleanly), so plain `php8.5 bin/phpunit`
  just works — no manual warmup step. (If you ever bypass the bootstrap, warm it yourself first.)

## Backlog (queued — grouped by Roadmap phase)
The SINGLE home for "what's next" — park ideas here, not scattered across chat. Phases mirror the
Roadmap at the top of this doc; nothing here is built unless marked DONE.

### Phase 0 — done (reference)
- **Prolaz C — multi-column layout — DONE.** Custom Tiptap `columns`/`column` nodes (FIXED
  2/3 equal columns; not resizable). Built as a PURE HTML node (no shortcode/converter) —
  see the "Multi-column layout" bullet under the WYSIWYG editor section for the full design
  (direct-in-schema like the image node, nesting forbidden via the `columns` group +
  `TallystDocument`, CSS in two places + theme contract, round-trip tests). NOT done (later
  passes): resizable columns, dynamic add/remove a column, nested columns, per-column
  widths/backgrounds, >3 columns.

### Phase 1 — CMS-complete polish (DONE)
Order matters (see Roadmap): theme + demo content are the *lens*, then footer/hero, then email.
- **Neutral default theme + demo content FIRST — DONE (pass 1).** Tokens-first design-system
  default theme + `app:demo:seed` (~16 pages / 15 posts, 2-level menu, free + priced demo forms,
  GD-generated demo images). Full design in "Default theme & demo content".
- **Page layout + per-page hero — DONE (pass B).** Full-width pages (text capped to the readable
  measure) / narrow blog, and an opt-in per-page hero with overlay text + scrim. Design in
  "Default theme & demo content".
- **Footer config + branding-in-Postavke + favicon — DONE.** Branding (logo) moved into Postavke
  → Branding (standalone page removed) + favicon; configurable footer (columns/text/menu/
  copyright/powered-by) replacing the hardcoded one. Design in the "Settings" section.
- **Blog archives + pagination — DONE.** Category archives at **`/kategorija/{slug}`**
  (`CategoryController`, `category_show`; two-segment so the `/{slug}` catch-all can't swallow it).
  Pagination via the Doctrine ORM **`Paginator`** (`PostRepository::paginatePublished` — `status`
  filter + optional `category`, `ORDER BY publishedAt DESC, id DESC` stable tiebreaker, to-ONE
  join-fetch of category+featuredImage to avoid N+1, `fetchJoinCollection: false`). **`App\Blog\
  PostPaginator`** is the ONE shared orchestrator (both controllers call it): reads
  `blog_posts_per_page` (clamped [1,50], default 9), clamps `?page` (junk→1, out-of-range→last,
  never a 500), returns a `BlogPage` DTO. Shared theme partials `_posts_list.html.twig` (card grid)
  + `_pagination.html.twig` (windowed 1 … current±2 … last) reused by `posts.html.twig` (index) and
  `category.html.twig` (archive). Category links (cards + post page) now point to `category_show`.
- **Post author + byline + user name/nickname — DONE.** `Post.author` = nullable `ManyToOne(User)`,
  `onDelete: 'SET NULL'` (deleting a user nulls the FK, never breaks/deletes posts — locked by the
  FK mapping, not a test). `PostCrudController::createEntity()` pre-fills the author with the current
  user on the NEW form (editable; not called on edit, so an existing author is never clobbered); the
  author `AssociationField` dropdown label is `nickname ?: email`. **Byline** (`post.html.twig`) shows
  `Autor: {nickname}` ONLY when `author` AND `author.nickname` exist — never the email; cards have no
  byline. `User` gained `nickname` (display name) + `name` (**dormant** — reserved for e-commerce, no
  renderer). The demo seed creates a **dedicated demo author** (`demo-author@tallyst.local`, nickname
  "Tallyst tim", ROLE_EDITOR, unusable random password) so the byline shows without touching the real
  admin; removed by `--fresh`.
- **Email templates — DONE (PASS 1 engine + PASS 2 editor).** All 4 mails (order confirmation,
  order-admin, form notification, password reset) admin-editable via the engine (safe placeholder
  render, branded base layout, reset guard); the body uses a Tiptap-lite editor (raw HTML, no
  converter) with clickable "insert tag" from the registry. Full design in "Email templates engine".
  (2FA is TOTP — no mail. The demo Create/Delete admin panel is NOT Phase 1 — it's in "Import /
  content packs", Post-v1.)
- **Phase 1 (CMS-complete polish) is COMPLETE.** Next: Phase 2 — e-commerce finish.

### Phase 2 — E-commerce finish (manual-fulfilment model) — DONE
- **Order lifecycle (manual fulfilment) + admin actions — DONE (pass 1).** Option B: `fulfilled`
  now = admin manually delivered (no longer auto-on-mail); `FulfillOrderHandler` sends confirmation +
  admin notice via `OrderMailer` and leaves the order `paid`; `OrderCrudController` actions "Označi
  isporučeno" (paid→fulfilled via the state machine + `order_delivered` mail) and "Pošalji ponovno
  potvrdu"; badge semantics (paid=warning "Čeka isporuku", fulfilled=success "Isporučeno"). See rule 5.
  (No data migration — old `fulfilled` rows were dev/demo only; `--fresh` resets.)
- **Refund — DONE (pass 2).** Two paths, both via the `refund` workflow transition, full refunds only:
  (a) admin "Refundiraj" action → `PaymentProcessorInterface::refund(Order)` → `StripeProcessor`
  `refunds->create(payment_intent)` → apply `refund` + `order_refunded` mail; (b) Stripe-dashboard
  refund → `charge.refunded` webhook (full only, `amount_refunded >= amount`) → finds order by
  `providerPaymentIntentId` → apply `refund` + mail. **Idempotent / single mail:** the webhook no-ops
  an already-refunded order, and the admin action `em->refresh`es before apply (closes the reverse
  race), so a Tallyst-initiated refund (+ the `charge.refunded` it triggers) never double-applies or
  double-mails. `PaymentProcessorInterface::refund` is provider-agnostic (PayPal: not-supported until
  pass 4). Errors → flash, never 500.
- **Stripe config in Postavke — DONE (pass 3).** FormBuilder ships the "Stripe" settings section
  (`StripeSettingsProvider`): `stripe_secret_key` + `stripe_webhook_secret` (PASSWORD/encrypted) +
  `checkout_locale`. `StripeProcessor` reads keys **settings ?: env** (decrypted; env fallback so a
  fresh deploy works) for checkout/refund/webhook-verify; `getMode()` → test/live/unconfigured from
  the effective key prefix (admin **mode badge**). Checkout passes `locale` unless `auto`. Postavke →
  Stripe shows the **webhook URL** (`url('form_builder_webhook_stripe')`) + the required events
  (`StripeWebhookController::REQUIRED_WEBHOOK_EVENTS`, one source) + a setup guide, via a FormBuilder
  Twig extension + `@FormBuilder/admin/_stripe_info.html.twig` partial the Core settings template
  includes (loose Twig coupling, no Core→FormBuilder PHP dep). Env support retained.
- **PayPal + provider choice + per-product limit — DONE (pass 4).** PayPal is a second
  `PaymentProcessorInterface` impl (Orders v2, direct REST via HttpClient — official SDK archived).
  Interface evolved (agnostic): `isConfigured/getMode/finalizeReturn/parseSignedWebhook(payload,
  headers[])/getWebhookEvents`; PayPal's OAuth + capture-on-return + local-cert webhook verify + sandbox/live host +
  explicit `paypal_mode` stay inside `PayPalProcessor`. `finalizeReturn` (thank-you, every provider:
  Stripe no-op, PayPal capture) is idempotent + never sets paid (webhook stays sole truth) + graceful
  on failure. `OrderPaymentSync` (FormBuilder/Service) holds the paid+refund transitions + idempotency
  guards; both `/webhook/stripe` + `/webhook/paypal` call it (same guards, no drift). Buyer chooses
  the provider: `FormDefinition.allowedPaymentMethods` (migration) + `registry.availableFor()` =
  configured ∩ allowed, used by the form render (radios/hidden/unavailable) + the submit. PayPal keys
  in Postavke → PayPal. DTO reuses `providerSessionId` (PayPal order id) + `providerPaymentIntentId`
  (capture id). PayPal full-refund only (partial out of scope).
- **Price variants — DONE (pass 5).** Or-or, single dimension: `FormDefinition.variants` (JSON list of
  `{label, priceMinor}`, like `allowedPaymentMethods` — no entity). When non-empty they REPLACE the
  fixed `priceMinor`; empty = fixed price (unchanged). `isProduct()` = `hasVariants() || priceMinor>0`;
  `variantAt($i)` is the server-side gate (null on out-of-range → submit rejects with a flash, never
  trusts a client price — the client sends only the index). Chosen variant's `label` + resolved price
  land on the `Order` (`variantLabel` + `amountMinor`); shown in OrderCrud + a `{variant}` mail tag
  (advertised on the order types; default bodies unchanged so existing mails don't break). Builder:
  `VariantType` collection reusing the generic `formbuilder--builder` add/remove (no new JS). Migration
  added `fb_form.variants` + `fb_order.variant_label`. **Also fixed a pass-4 gap:** `allowedPaymentMethods`
  was in the form type but never rendered in `edit.html.twig` (`render_rest:false` dropped it) → the
  per-product payment limit was unsettable; now rendered.
- **Tax — DONE (pass 6, LAST). PRODUCTION DECISION: Tallyst is NOT a tax engine or Merchant-of-Record.**
  ONE configurable **inclusive** rate (toggle): the entered price already includes tax; net/tax are
  derived backwards (`TaxCalculator`: `net = round(gross/(1+rate/100))`, `tax = gross - net` → always
  sums) and the **charged amount is unchanged** (both processors untouched — tax never alters the gross
  sent to Stripe/PayPal). Settings: FormBuilder `TaxSettingsProvider` "Porez" (`tax_enabled`/`tax_rate`/
  `tax_name`) with an honest-boundary help text. Recording on the Order (nullable; all null when tax was
  off, so export distinguishes "no tax" from a real 0): `taxAmountMinor`/`netAmountMinor`/`taxRate`/
  `taxName`/`customerIp` (+ `customerCountry`/`customerVatId` **DORMANT** — see below). Checkout shows the
  inclusive note "Cijena uključuje {tax_name} ({rate}%)". Mail tags `tax_amount`/`net_amount`/`tax_rate`/
  `tax_name` (advertised; default bodies unchanged). **CSV export** (OrderCrud global action, ROLE_ADMIN,
  UTF-8 BOM) is the accountant deliverable. OUT: multi-rate/per-country/per-product, OSS, VIES, tax-API,
  exclusive tax, changing the charged amount.
  - **B2B / VAT capture = ordinary form fields, NOT imposed checkout fields.** The tax pass briefly added
    fixed "Država"/"VAT ID" inputs to every paid checkout; **removed** (premature imposition). An admin
    who needs B2B adds a "Kupujem kao tvrtka" checkbox with **conditional display** revealing OIB/Ime
    tvrtke fields → those land in the **"Podaci kupca"** CSV column (the submission summary). No predefined
    field types / structured mapping. The `Order.customerCountry`/`customerVatId` columns are **DORMANT**
    (nullable, nobody fills them; kept for a possible future MoR — not dropped). `customerIp` is still
    recorded.
- **Phase 2 (e-commerce finish) is COMPLETE.** Next: Phase 3 — standalone installer + readiness panel.
- **Subscriptions & recurring (future epic, post-v1).** Stripe runs the billing/retries + the
  **Customer Portal** for self-service cancel/update (we do NOT build that UI); OUR job is the
  subscription-lifecycle webhooks (created/updated/`invoice.paid`/`canceled`) + access/licence
  teardown on cancel/lapse. Depends on automated access-management (the deferred automated-delivery
  work), so it sits with Post-v1, not the manual-fulfilment v1.
- **Merchant-of-Record (global tax) — future epic, post-v1.** For sellers above the OSS threshold /
  selling globally, add Lemon Squeezy / Paddle as an extra `PaymentProcessorInterface` + a "billing
  mode" choice (direct vs MoR). The MoR is the legal seller → they handle global tax/VAT/compliance, so
  Tallyst's single-rate engine doesn't have to. Notes: this CHANGES what an Order means (Tallyst isn't
  the seller); LS has no programmatic product-create (use a placeholder product + custom_price); check
  Paddle's product-create. This is the proper answer to everything the pass-6 tax scope consciously left
  out (multi-rate/per-country/VIES/OSS).

### Phase 3 — Standalone installer + deployment readiness
- **Standalone installer — interactive `app:install` wizard — DONE (Pass 1).** Ghost-style
  guided first-run install for a CLEAN environment (fresh site, empty DB). Flow:
  pre-flight (sodium ext + `var/`/project writable) → **already-installed GUARD first** (refuses
  to overwrite a live site; `--force` + confirm bypasses; also refuses a non-empty target schema)
  → DB prompts with **live connection validation** (raw DBAL probe, re-prompt loop, never proceeds
  on a broken connection; auto-detects `serverVersion` for the DSN) → admin email/password (hidden,
  confirmed) + site name + `DEFAULT_URI` → write `.env.local` (idempotent upsert; `APP_SECRET`
  generate-if-missing/never-rotate; **`APP_ENV=prod` by DEFAULT, only-if-missing** — see below;
  encryption key via `EncryptionKeyProvisioner`; perms 0600) → migrations + seed + admin + asset
  fallback + `cache:clear` → Ghost-style final message (worker / Stripe-PayPal / prod+webhook; +
  a dev-mode instruction). Optional CI flags (`--db-* --admin-* --site-* --force --skip-assets`)
  + the `TALLYST_ADMIN_PASSWORD` env for unattended install.
  - **APP_ENV=prod by default (no prompt).** Most installs are production; a dev-mode Symfony
    default would expose detailed stack traces on a public site + run slower. The wizard writes
    `APP_ENV=prod` **only-if-missing** (like `APP_SECRET`), so a re-run/`--force` never clobbers a
    developer's deliberate `APP_ENV=dev`; dev mode is offered via an instruction in the final
    message (`APP_ENV=dev` + `cache:clear`), not a prompt.
  - **Asset-fallback failures are surfaced PROMINENTLY, not swallowed.** The asset steps
    (`importmap:install`/`asset-map:compile`/`app:theme:assets:install`) stay non-fatal (the rest of
    the install is valid), but any failure is collected and shown as a loud warning block in the final
    message with the exact recompile commands — never a buried mid-flow `[WARNING]` under a green
    success. Why: a silently-failed compile leaves the admin/front JS dead (Stimulus controllers don't
    boot) on an install that otherwise looks clean — exactly the prod-smoke trap that hid the
    webhook-button bug.
  - **⚠️ ARCHITECTURE — DB-mutating steps MUST run as fresh-kernel SUBPROCESSES (the crux).**
    `symfony/runtime` runs `Dotenv::bootEnv()` ONCE before the kernel (`usePutenv=false`), so the
    boot-time `DATABASE_URL` is frozen in `$_ENV`/`$_SERVER` and baked into the compiled container.
    After the wizard writes a NEW `DATABASE_URL` to `.env.local` mid-run, the in-process Doctrine
    connection is STALE — migrations/seed/admin-insert CANNOT run in-process against the new DB
    (mutating `$_ENV`/`putenv` fights the already-compiled container; works in dev, breaks under
    prod's single container). So each step is a `Process([PHP_BINARY,'bin/console',…])` that boots a
    FRESH kernel reading the new `.env.local`. **Symfony Process inherits `$_SERVER`+`$_ENV`, so the
    child would otherwise see the parent's stale `DATABASE_URL` (and Dotenv lets a present env var
    win over the file)** — the wizard passes a child env that sets the `.env`-managed keys
    (`DATABASE_URL`/`APP_SECRET`/`DEFAULT_URI`/`ORDER_ADMIN_EMAIL`/`SETTINGS_ENCRYPTION_KEY` **plus
    `APP_ENV`/`APP_DEBUG`**) to `false` (Process removes them) so the child re-reads them fresh from
    the file. `APP_ENV`/`APP_DEBUG` MUST be stripped too or the subprocesses keep the parent's (dev)
    mode — `bootEnv` even derives `APP_DEBUG` from `APP_ENV` and won't override an inherited value, so
    without the strip the migrate/finalize/asset/cache steps would run dev despite `.env.local=prod`.
    The DB connection
    test + the install guard use a RAW `DriverManager` connection built from the inputs (independent
    of the container). The admin password is forwarded via the **private child env**
    (`TALLYST_ADMIN_PASSWORD`), never argv (argv shows in `ps`/history).
  - **Pieces:** `src/Install/` — `DatabaseDsnBuilder` (parts→mysql DSN + `serverVersion` formatting,
    pure), `EnvLocalWriter` (idempotent key upsert, double-quote+escape, 0600; does NOT touch the
    encryption-key block), `InstallStateDetector` (envLocal predicate + DB probes), `DatabaseProber`
    (raw DBAL connect/ping/version — **must use `Doctrine\DBAL\Tools\DsnParser` mapping
    `mysql`/`mariadb`→`pdo_mysql`, NOT `DriverManager::getConnection(['url'=>$dsn])`**: DBAL 4 dropped
    `url`-key driver derivation, so the bare-url form throws "options driver or driverClass are
    mandatory" — surfaced only on a real install, not the isolated DsnBuilder unit test),
    `BaselineSeeder` (theme/home/post/menu — extracted from the old
    `InstallCommand`). `src/Command/InstallCommand` (the wizard), `src/Command/InstallFinalizeCommand`
    (hidden `app:install:finalize` — seeds + creates admin in the fresh-kernel subprocess; reads the
    password env, idempotent). Unit tests: `tests/Install/*` (DsnBuilder, EnvLocalWriter,
    StateDetector predicate) + `tests/Command/InstallFinalizeCommandTest` (validation). The
    interactive wizard itself is smoke-only (user runs it on a clean CloudPanel site/empty DB).
  - **NOT in Pass 1 (later passes):** the Composer `post-create-project-cmd` hook + Packagist
    packaging, web installer, SMTP in the wizard (deferred to Postavke → Email). (Readiness panel =
    Pass 2, DONE — see "Readiness panel" below.)
- **Stripe auto-webhook-setup + config diagnostics (NOT built).** Beyond the pass-3 copy-paste guide:
  Tallyst creates/updates the Stripe webhook endpoint via the API (so the admin doesn't hand-add it),
  and a "check Stripe config" diagnostic that reads the configured endpoint and warns if
  `charge.refunded` (or any `REQUIRED_WEBHOOK_EVENTS`) is missing.
- **EasyAdmin `#[AdminDashboard]` / pretty-URL migration (DEFERRED — its own pass, NOT a syntax cleanup).**
  EA 4.24 emits an INFO deprecation that `DashboardController` should carry
  `#[AdminDashboard(routePath: '/admin', routeName: 'admin')]` (mandatory in **EA 5.0**). **DO NOT treat
  this as a one-line fix:** applying the attribute switches EA into route-based **"pretty URLs"** — it
  generates ~100 per-action routes (`admin_order_index → /admin/order`, `admin_page_edit →
  /admin/page/{entityId}/edit`, …) and REPLACES the legacy `/admin?crudControllerFqcn=…&crudAction=…`
  query-param scheme. `/admin` itself stays `admin → /admin`, but anything that hardcodes a legacy admin
  URL breaks (it broke `OrderCsvExportTest`, which GETs `/admin?crudControllerFqcn=…&crudAction=exportCsv`).
  EA-generated links (`linkToCrud`/`linkToRoute`) and `AdminUrlGenerator` adapt automatically; the risk is
  hardcoded `?crudControllerFqcn=` URLs in tests/templates/redirects. **When done (with EA 5.0 or a
  deliberate migration), its own `/plan`:** re-apply the attribute, update tests to the new URL format,
  AUDIT every admin URL/redirect/link for hardcoded legacy query-param usage, and full CRUD smoke (every
  list/new/edit/detail/custom-action page, menu, export, dashboard deep-links). Tracked here because the
  2026-06-25 deprecation pass deliberately STOPPED at this (it shipped only the syntax-only fixes:
  `#[Target('orderStateMachine')]` + Vich `Annotation`→`Attribute`). The remaining INFO deprecations on a
  prod `cache:clear` are this EA one and Vich's OWN factory tagged-iterator (vendor code — awaits a Vich
  release); neither is ours to silence without a library migration.
- **Go-live checklist (a release GATE, not a feature).** Before the public domain goes live:
  worker running as a DAEMON (systemd/supervisor, not a manual shell — see the Readiness Panel
  below), `APP_ENV=prod` (never dev/debug on the public domain), LIVE Stripe keys + the webhook
  secret in **Postavke → Stripe (or `.env.local`)** — the badge should read **LIVE MOD** — with a
  **LIVE webhook endpoint (separate from test)** subscribed to `checkout.session.completed` +
  `charge.refunded` (the URL + event list are shown in Postavke → Stripe). If using **PayPal**:
  live `paypal_client_id`/`secret` + `paypal_mode=live` (badge LIVE) + a live webhook endpoint
  (`/webhook/paypal`, separate from sandbox) subscribed to `PAYMENT.CAPTURE.COMPLETED` +
  `PAYMENT.CAPTURE.REFUNDED`, and its `paypal_webhook_id` set (verification needs it). A real
  `MAILER_DSN`/SMTP
  configured AND a test mail delivered, and a real `SETTINGS_ENCRYPTION_KEY` provisioned
  (`app:install`). Email specifics
  (learned the hard way): the configured **`mail_from_email` MUST be an address the SMTP account
  is allowed to send as** (else real SMTP rejects with `553 ... not owned` and mail silently
  fails) — and verify with a real **paid test order** end-to-end, not just the test button
  (they share the transport but the order path is async via the worker). The order-admin
  notification recipient is the **`order_admin_email`** Setting (Postavke → Email), falling back to
  the **`ORDER_ADMIN_EMAIL`** env when empty — set ONE of them to a real, deliverable address (the
  `admin@tallyst.local` env default is a placeholder whose domain bounces, so the notification never
  arrives). `FulfillOrderHandler` reads the setting per message (messenger resets services between
  messages), so changing it needs no worker restart — unlike SMTP, cached for the worker's lifetime.
  Set **`DEFAULT_URI` to the real
  public URL** (e.g. `https://tallyst.org`) so worker-rendered absolute links (password-reset link,
  etc.) aren't `http://localhost`. The Deployment Readiness Panel below is the eventual in-admin
  surface for these checks.
- **⚠️ Webhook routes MUST bypass EVERY front-auth (RECURRING TRAP — hit more than once).**
  `/webhook/stripe` + `/webhook/paypal` are server-to-server and verified by signature (Stripe HMAC /
  PayPal offline cert-verify), so any nginx **Basic Auth / maintenance page / front auth** returns
  **401 BEFORE Symfony** → the order stays **"U obradi" (pending)**, fulfilment never fires, **no mails**
  (customer + admin), and `customerEmail` stays empty — all ONE cause. Go-live: `auth_basic off;` (or
  equivalent) for those two routes in the nginx/CloudPanel config; if you use an IP allowlist instead,
  keep the Stripe + PayPal webhook IP lists current (they go stale → 401 again).
  - **SYMPTOM TRIAD (golden rule):** payment **SUCCEEDED on Stripe/PayPal's side** BUT the order is
    **"U obradi"** in Tallyst → **check the webhook 401 FIRST** (nginx access log: `POST /webhook/... 401`;
    the request is ABSENT from `dev.log` because it never reaches PHP). Don't hunt in code — the cause is
    almost always front-auth / webhook config, not the app.
  - **Maintenance/basic-auth EXEMPT-ROUTE list (when that feature lands):** the same class of "must stay
    publicly reachable" routes — `/webhook/stripe`, `/webhook/paypal`, **`/sitemap.xml`, `/robots.txt`** —
    must bypass any future maintenance mode / basic-auth (crawlers + payment providers can't authenticate).

### Readiness panel (deployment readiness) — DONE (Pass 2)
Admin screen **Sustav → Provjera spremnosti** (`/admin/readiness`, `ReadinessController`, ROLE_ADMIN,
EA shell) that AUTO-diagnoses whether the install is configured + production-ready. Informational
ONLY — it never changes config (diagnoses + instructs).
- **Honesty is the core principle — FOUR statuses** (`App\Readiness\Status`): OK / WARNING / PROBLEM /
  **MANUAL ("🔍 provjeri ručno")**. MANUAL is the deliberate honest result for checks the app can't
  verify with certainty (worker liveness, webhook reachability, real TLS) — shown with instructions,
  NEVER faked green, so a green badge can be trusted. Don't add a check that claims green it can't prove.
- **Tagged-provider IoC** (`ReadinessCheckProviderInterface`, `#[AutoconfigureTag('app.readiness_check')]`
  → `ReadinessReport` aggregates + groups + counts; same idiom as settings/dashboard widgets, so modules
  can add checks). Core ships two: **`ConfigReadinessProvider`** (pure → unit-tested: APP_SECRET,
  SETTINGS_ENCRYPTION_KEY base64→32B, HTTPS via DEFAULT_URI scheme, APP_ENV [dev→WARNING "za produkciju
  prod", NOT fatal — local dev is legit], DEFAULT_URI absolute/not-localhost, mail [DB SMTP or MAILER_DSN;
  smtp-password decryptable; mail_from; order_admin_email not the placeholder]) and **`InfraReadinessProvider`**
  (smoke: assets manifest, published theme, var/+uploads writable, pending migrations via `DependencyFactory`,
  worker heartbeat + queue backlog/failed).
- **Worker = HEARTBEAT (now EXISTS, was the planned enabling part):** `App\Messenger\WorkerHeartbeat`
  (cache.app: key + freshness window) + `WorkerHeartbeatSubscriber` (throttled write on Messenger
  `WorkerRunningEvent`). Panel: fresh→OK, stale/missing→**MANUAL** (never a hard "dead" claim — a just-
  restarted worker hasn't beaten yet; the systemctl hint is shown). NO `shell_exec`/`ps` (brittle/security
  smell). Supplementary: `messenger_messages` backlog (queue_name='default' undelivered) + failed count.
- **Webhook 401 self-test (the recurring go-live trap):** FormBuilder-owned (it owns the webhook routes)
  on-demand button → `WebhookReachabilityProbe` POSTs an EMPTY/UNSIGNED body to its own webhook URL (built
  from DEFAULT_URI). The unsigned body fails signature verification INSIDE the controller → HTTP **400**
  (no order logic runs — SAFE). **401 = front basic-auth blocks the route** (payment succeeds but the order
  stays "U obradi"). Honest caveat surfaced: a 401 can be a FALSE alarm under an IP-allowlist (the call
  isn't from a Stripe/PayPal IP), and a self-call can fail on hairpin NAT → MANUAL. On-demand (button) so
  the HTTP test never slows the admin (`form_builder_admin_webhook_check`, JSON, CSRF; `formbuilder--webhook-check`
  Stimulus renders verdicts XSS-safe). Core panel includes the FB partial (loose Twig coupling, like `_payment_info`).
- **Still future (NOT in Pass 2):** the panel REPORTS worker status but does NOT generate the worker
  activation snippet (the original "CMS generates the systemd/`messenger:consume async` command with detected
  paths; admin runs it as the server user — CMS never installs system services = security boundary"; user-level
  unit in `~/.config/systemd/user/` + `loginctl enable-linger` works rootless, learned 2026-06-21). Also future:
  an encryption-key rotation command (rotate the one secret = SMTP password, write-only re-encrypts; only if
  encrypted settings multiply).

### Post-v1 / future (deferred — NOT v1)
- **Theme upload via browser (V2 — Shopify-model).** V1 is auto-detect + activate (folders dropped into
  `themes/` via FTP/git). V2 adds in-admin upload: a zip uploaded + validated (structure: `theme.yaml` +
  `layout.html.twig`; size/type) and, crucially, **Twig sandboxing** (untrusted theme templates must not
  run arbitrary PHP/Twig — `{{ … }}`/tags restricted to a safe allowlist). Its own security pass.
- **Replacement tags in the thank-you message (and maybe email templates) — deferred, design noted.**
  Today the thank-you message (Pass 11) is static editable text + a fixed dynamic block; let the admin
  embed tags in the copy. Two kinds: **order tags** (`{broj_narudzbe}`, `{iznos}`, `{valuta}` — reliable,
  structured on `Order`) and **field tags** (`{polje:naziv}` — pulled from the form SUBMISSION, for
  name/company). **Customer data comes from the FORM (submission), NOT Stripe/PayPal** — processors don't
  give a reliable name (Stripe doesn't collect it unless `name_collection` is configured; PayPal returns the
  PayPal-account name, not necessarily the wanted one); don't touch payment for the name, the form is the
  cleaner source. Reuse the `ShortcodeRegistry`; graceful when a field is missing (tag → empty/removed,
  never a crash — it depends on per-form field names). **Deferred because:** static text + the dynamic block
  cover the main need, and replacement tags add fragility (field-name coupling) for marginal v1 value.
  (The email-template engine already has its OWN advertised-tag mechanism; this is about extending the same
  idea to the thank-you copy — keep the two consistent if/when built.)
- **Automated digital delivery (deferred — model DECIDED).** The delivery model is settled: **v1 is
  manual fulfilment** (payment → order recorded → confirmation e-mail → the admin delivers manually:
  sends the file, grants access, issues the licence, performs the service — enough for BOTH services
  AND digital products, and the e-commerce CORE is already built). Post-v1 builds AUTOMATED delivery:
  on `paid→fulfilled`, download links / licence-key generation / access grants, with the verified
  webhook staying the SOLE source of truth for `paid`. (This was the open "decide the delivery
  model" fork — now DECIDED; only the automated build remains, deferred.)
- **Import / content packs (deferred — its own meaty phase, sits with/after the standalone installer).**
  Turn demo content from code into DATA, and give the CMS a real importer:
  - **Demo-as-data.** Move the demo out of `DemoSeedCommand` PHP into a self-contained DATA bundle
    in its own folder (e.g. `demo_import/`): real GPL/CC images + a native JSON content bundle the
    CMS imports. Content-as-data, not content-as-PHP.
  - **Importer with format adapters.** A core importer + pluggable adapters: a **native-bundle**
    adapter (demo + future content packs) and a **WordPress WXR** adapter. The importer owns
    parsing, mapping the foreign model (post types / taxonomies / media / users) onto Tallyst,
    re-hosting media, and URL rewriting.
  - **Demo dogfoods the importer** — the demo bundle is the importer's first consumer, so it tests
    itself on itself.
  - **WP import is an ONBOARDING feature** — it lowers the switching cost for people leaving
    WordPress/SaaS, directly serving the product thesis (everything in-house, no SaaS/plugin rent).
  - **"Demo-in-admin"** (a back-office Create/Delete demo panel) belongs HERE, alongside the
    importer/installer — NOT in Phase 1.
  - **Until the importer lands, `app:demo:seed` STAYS as-is** (don't leave the dev workflow without
    a demo); the data bundle only REPLACES it once the importer exists.
  - Right-size: the importer (especially WP-WXR) is substantial — its own phase, not a quick add-on.
- **Everything under "Explicitly NOT in v1"** at the top of this doc — full email-template editor,
  multilingual/i18n, comments, dynamic/custom RBAC roles, required-2FA-for-admins, trusted devices,
  SMS/e-mail 2FA, WebAuthn, full self-profile, custom fields/widgets — none unless the target-user
  filter later demands it. (Prolaz C's own later passes are noted in its DONE entry above.)
- **Full contribution setup (deferred — README has only a minimal "Feedback" line for now).** The
  README intentionally invites ONLY bug reports / feature ideas via GitHub issues — no "Contributing"
  call-to-build (themes/modules), no PR guide. Before that section lands, build the prerequisites:
  `CONTRIBUTING.md`, GitHub issue/PR templates, developer docs for authoring themes + modules, and a
  DEFINED community-vs-paid add-on model. Only once those exist does the README gain a full
  "Contributing" section inviting third-party theme/module development. (Don't promise an add-on
  marketplace/store until it exists.)

## Adding a new module (the pattern to follow)
Copy `modules/FormBuilder/` — it is the reference module. Its bundle class
(`AbstractBundle`) self-registers its Doctrine mapping and its `config/services.php`,
and overrides `getPath()` to `__DIR__` (the bundle lives in `modules/<Name>/`, not a
`src/` subdir, so the default heuristic mis-resolves it).
- **⚠️ The module's `config/services.php` MUST exclude `config/` in its `->load('Tallyst\\<Name>\\',
  __DIR__.'/../')` PSR-4 scan** (alongside `Entity/` and the `*Bundle.php`). The scan globs the module
  root for service classes; `config/services.php` is a DI config file, not a class, so the scan derives a
  bogus FQCN (`Tallyst\<Name>\config\services`) from it. **DEV tolerates this; the PROD container compile
  THROWS** "Expected to find class … in file …/config/services.php". This bit both FormBuilder and Media
  (latent — surfaced only once `app:install` started compiling the prod container). Any new module with a
  `config/` loader: exclude `__DIR__.'/../config/'`. Implement `ModuleInterface`
(metadata → shows in the registry; **`isCore()`** — core modules can't be disabled) and optionally
`AdminModuleInterface` (`getAdminMenuItems()` returns **section-keyed** items
`[AdminModuleInterface::SECTION_CONTENT => [MenuItem…], SECTION_SALES => […]]` — the dashboard places
each group into the matching Core section, e.g. Sadržaj/Prodaja, so Core never references module
controllers (dependency direction); build CRUD links with `MenuItem::linkTo(<CrudController>::class,
label, icon)` — `linkToCrud()` is deprecated). Content tags implement `ShortcodeInterface`; both
interfaces are auto-tagged via `#[AutoconfigureTag]`, so no `_instanceof`/services wiring is needed.

**The 5 app-side touch points (everything else lives in the module):**
1. **Autoload** — add the PSR-4 namespace to `composer.json` (`"Tallyst\\<Name>\\":
   "modules/<Name>/"`) and run `php8.5 /usr/local/bin/composer dump-autoload`.
2. **bundles.php** — register `Tallyst\<Name>\<Name>Bundle::class => ['all' => true]`.
3. **Routes** — add `config/routes/<name>.yaml` importing
   `'../../modules/<Name>/Controller/'` with `type: attribute`.
4. **AssetMapper** — add `modules/<Name>/assets/: <name>` to `paths` in
   `config/packages/asset_mapper.yaml` (only if the module ships JS/CSS).
5. **Stimulus** — `import` the module's controllers in `assets/stimulus_bootstrap.js`
   and `app.register('<name>--<controller>', ...)` (only if it ships controllers).

Not every module needs all five. A module is only EA CRUD (e.g. **Media**) needs just
1 + 2 (EA auto-routes CRUD controllers; the routes import in 3 is only for the module's
*custom* admin/front controllers); no assets → skip 4 + 5. **A module may also WRAP
third-party bundles** (Media wraps Vich + Liip): register those bundles in `bundles.php`
too and add their app-level `config/packages/*.yaml` (and any routes the recipe would
have added, e.g. Liip's) — the contrib recipes are not auto-applied here.

Then generate + run a Doctrine migration for any new entities, and `cache:clear`.

**Module admin pages MUST live inside the EasyAdmin shell** (sidebar + header) — a
standalone page traps the user with no nav and fragments the admin. The rule:
- The admin template **extends `@EasyAdmin/page/content.html.twig`** (put content in
  `{% block main %}`, title in `{% block content_title %}`); use Bootstrap 5 classes
  and `{% form_theme ... 'bootstrap_5_layout.html.twig' %}` for native styling. For
  status badges use EasyAdmin's classes (`badge badge-success`, `badge badge-secondary`,
  ...) — NOT Bootstrap's `bg-*` — so they match the CRUD badges and follow dark mode.
- The admin controller's `#[Route]` sets a default so EasyAdmin builds its
  AdminContext for the route (otherwise `ea` is null and the layout errors):
  `#[Route('/admin/<x>', defaults: ['dashboardControllerFqcn' => 'App\Controller\Admin\DashboardController'])]`.
  (Import-level `defaults:` in routes YAML does NOT propagate to attribute routes —
  it must be on the `#[Route]` itself. Keep the public/front routes WITHOUT it.)
- For the module's own Stimulus controllers to boot on admin pages, the app Dashboard
  loads the admin entrypoint into EasyAdmin's single importmap:
  `DashboardController::configureAssets(): Assets { return Assets::new()->addAssetMapperEntry('admin'); }`.
  (Use the `admin` entrypoint — Stimulus only — NOT `app`, or front CSS leaks over the
  EA theme. One importmap per page — never add a second `importmap(...)` in the template.)
App-level custom admin pages (e.g. the module registry) are simplest as actions on
the Dashboard controller itself.

**Any entity rendered in an EasyAdmin `AssociationField`** (or shown as a related
entity) needs a `__toString()` — EA stringifies it for the dropdown/label. Missing it
throws "Object of class X could not be converted to string". Core entities used this
way already have one (Menu, Page, MenuItem, Category, User).

**Shared client/server logic (e.g. conditional fields):** keep ONE definition as
data (JSON on the entity), evaluate it with a PHP class AND a mirrored pure JS module
(`assets/condition_evaluator.js`), and test BOTH against one fixture
(`tests/fixtures/condition_cases.json` → PHPUnit + a Node test) so they cannot drift.

## Workflow expectations for Claude Code
- Prefer Explore → Plan → Implement → Commit. Propose a plan before large changes.
- Keep changes scoped. Run `cache:clear` after config or route changes.
- After adding/altering entities, generate and run a Doctrine migration.
- Never leave the public domain in dev/debug mode beyond active development.
- Never commit secrets. DB credentials and Stripe/PayPal keys live in `.env.local`
  (git-ignored) — never in `.env` or in code.
- **Smoke gotcha — the built-in PHP server BYPASSES nginx.** `php -S … public/index.php` (used to
  smoke the front when nginx basic-auth is on) does NOT validate Liip cached-URL / nginx-static
  behaviour: the on-demand Liip resolve URL *works* on the built-in server but **404s under nginx**
  (it ends in an image extension → served as a static file before PHP runs). So for any image/
  thumbnail pass, smoke on the REAL nginx (in a browser, or temporarily disable basic-auth + live
  `curl`) — a green built-in-server check is not proof the image resolves in production.
