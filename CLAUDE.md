# Tallyst CMS — Claude Code Project Guide

## WHY
Tallyst is a deliberately simple, modular CMS for a single developer who wants to
sell their own services and digital products (apps) directly. It exists because
off-the-shelf options (WordPress + paid form/payment plugins) are too heavy, carry
recurring license costs, and miss a few specific features — most importantly the
ability to turn any content page into a sellable product via an inline form tag.

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
- Install deps: `php8.5 /usr/local/bin/composer install`
- Add a package: `php8.5 /usr/local/bin/composer require <pkg>`
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
- The front-end loads the `app` entrypoint (`assets/app.js` → Stimulus + `app.css`).
- The admin loads a SEPARATE `admin` entrypoint (`assets/admin.js` → Stimulus only, no
  front CSS) via `DashboardController::configureAssets()`, so front styles never
  override the EasyAdmin theme/dark mode. Register module Stimulus controllers in
  `assets/stimulus_bootstrap.js` (shared by both entrypoints).

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
- **`[image id=N size=medium align=left alt="..."]`** shortcode embeds a Media in
  content, mirroring `[form id=N]`. Missing/deleted id → nothing (a comment), never an
  error. `size` is whitelisted to defined Liip filters (medium default), `align` to a
  fixed CSS class, `alt` is escaped — no arbitrary attribute reaches the `<img>`.
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
   - **Conditional-required:** a field hidden by its display conditions is NOT required (nor
     validated, nor stored). The SERVER is authoritative — `SubmissionValidator` runs
     `ConditionEvaluator::visibleKeys()` (a CASCADING fixed point: a field whose condition
     depends on an already-hidden field is hidden too) over the submitted values and skips
     hidden fields before checking `required`. The client (`formbuilder--conditions`) mirrors
     this by removing `required` from hidden inputs. Locked by `SubmissionValidatorTest`
     (incl. the chained case) + the functional `FormSubmitConditionalRequiredTest`.
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
   units — cents, never float) + `currency`. A priced submission creates an `Order`
   and starts payment; a free form behaves as before.
5. **Payments use a strategy interface.** `PaymentProcessorInterface` + a registry
   (Stripe done in pass 2a; PayPal is just another impl). Order lifecycle is a
   Symfony **state_machine** workflow (`order`): `pending → paid → fulfilled →
   refunded`. Critical rules:
   - The **verified webhook is the SOLE source of truth for `paid`** — never the
     submit flow or the thank-you redirect. Verify the signature (reject 400), require
     the provider's paid status, be idempotent, and ack unknown sessions with 200.
   - The webhook marks `paid` fast, then dispatches **async fulfillment** (Messenger).
     Fulfillment (e-mails, then `paid→fulfilled`) is retriable and MUST NOT roll back
     `paid` if it fails. Production needs a running `messenger:consume async` worker.
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
- **Shortcode⇄node is an extension point (IoC), NOT hardcoded:** Core defines
  `EditorShortcodeConverterInterface` (auto-tagged `app.editor_shortcode_converter`, like
  `ShortcodeInterface`); `EditorContentConverter` aggregates every tagged converter and
  `TiptapType` depends only on that Core aggregator. Each module supplies its own:
  - Media → `ImageShortcodeHtmlConverter`: `[image id=N size align alt]` ⇄
    `<img data-tallyst-image data-id=N …>` (forward resolves the Liip URL via
    `MediaImageHelper`; null-safe — deleted Media → empty src, id kept).
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
  Core ships `CoreSettingsProvider` (sections: **General**, **Lokalizacija**, **Email**);
  modules MAY add sections later via the same interface (mechanism only — none built yet). A
  `SettingDefinition` (key, `SettingType`, label, help, default, choices, `encrypted`) is the
  single description used to BUILD the form AND cast values.
- **`SettingsManager`** is the only typed read/write path over `SettingRepository`: casts by
  type (bool→'1'/'0', int), applies schema defaults, and encrypts/decrypts `encrypted`
  settings. **Write-only secrets:** an empty incoming value for an encrypted setting is a
  NO-OP (keeps the stored value) — that's what lets the UI render the SMTP password field
  empty ("•••• nepromijenjeno") and only overwrite when the admin types a new one.
  `getForForm()` NEVER returns a secret.
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
  `dashboardControllerFqcn` route default, same pattern as BrandingController). Fields are
  built dynamically from the schema, grouped into Bootstrap tabs per section, saved through
  `SettingsManager`. It REPLACES the raw `SettingCrudController` in the menu (the CRUD class
  stays as a raw fallback, just unlinked). `site_name` + `site_tagline` live HERE (General)
  as their single editable home; **Branding owns only the logo** (its form dropped
  `site_name`). The front reads the same `site_name` key, so `render_branding()` is unchanged.
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
  nor remove **your own** admin role. Enforced in `updateEntity`/`deleteEntity` as
  redirect+flash (skip `parent::` → no flush) — never a 500, never the dangerous mutation.
- **`app:user:create --role`** defaults to ROLE_ADMIN (bootstrap admin) and rejects anything
  other than ROLE_ADMIN/ROLE_EDITOR.
- **Functional tests need a migrated test DB.** This server uses a separate `tallystcmstest`
  database (its own MySQL user); the connection lives in **`.env.test.local`** (git-ignored).
  The `test` env does NOT load `.env.local`, so `.env.test.local` must carry `DATABASE_URL`
  AND `SETTINGS_ENCRYPTION_KEY`. The test DB name is `<DATABASE_URL db><TEST_DB_SUFFIX>`
  (`TEST_DB_SUFFIX` default `_test`; set it empty when the URL already names the test DB, as
  here). Provision once: create the DB + user, then `APP_ENV=test php8.5 bin/console
  doctrine:migrations:migrate -n`. (Fallback for a box that can't create a separate DB: point
  `DATABASE_URL` at the main DB with `TEST_DB_SUFFIX=` empty — tests self-clean, but it's not
  isolated.)
- **Test-cache gotcha:** after changing PHP/config the cold `test` container recompile can throw
  in phpunit's kernel-boot path (a FormBuilder prototype-loader quirk), failing every functional
  (WebTestCase) test with a container error. `cache:warmup` compiles it cleanly, so **warm the
  test cache first**: `APP_ENV=test php8.5 bin/console cache:warmup && php8.5 bin/phpunit`.

## Backlog (queued — agreed, NOT yet built)
This is the SINGLE home for "what's next" — park ideas here, not scattered across chat.

- **Prolaz C — multi-column layout — DONE.** Custom Tiptap `columns`/`column` nodes (FIXED
  2/3 equal columns; not resizable). Built as a PURE HTML node (no shortcode/converter) —
  see the "Multi-column layout" bullet under the WYSIWYG editor section for the full design
  (direct-in-schema like the image node, nesting forbidden via the `columns` group +
  `TallystDocument`, CSS in two places + theme contract, round-trip tests). NOT done (later
  passes): resizable columns, dynamic add/remove a column, nested columns, per-column
  widths/backgrounds, >3 columns.
- **FormBuilder Pass 2b — PayPal + refund (NOT built).** PayPal is just another
  `PaymentProcessorInterface` impl alongside Stripe (pass 2a done) — add it and register it in
  the processor registry. Refund: the `order` state_machine already has the `refunded` state;
  wire a trigger (admin action → provider refund call → transition) into it. Keep rule 5 (the
  verified webhook stays the sole source of truth for `paid`).
- **Product delivery model — decision + build (NOT built).** Tallyst sells both services AND
  digital products (apps); today `paid→fulfilled` only sends e-mails. DECIDE the delivery model
  per product type before building (e.g. nothing/manual hand-off for services vs. a download /
  license key for apps). This is the "real product delivery" half of Pass 2b.
- **Go-live checklist (a release GATE, not a feature).** Before the public domain goes live:
  worker running as a DAEMON (systemd/supervisor, not a manual shell — see the Readiness Panel
  below), `APP_ENV=prod` (never dev/debug on the public domain), LIVE Stripe keys + the
  registered webhook secret in `.env.local`, a real `MAILER_DSN`/SMTP configured AND a test mail
  delivered, and a real `SETTINGS_ENCRYPTION_KEY` provisioned (`app:install`). Email specifics
  (learned the hard way): the configured **`mail_from_email` MUST be an address the SMTP account
  is allowed to send as** (else real SMTP rejects with `553 ... not owned` and mail silently
  fails) — and verify with a real **paid test order** end-to-end, not just the test button
  (they share the transport but the order path is async via the worker). Set **`ORDER_ADMIN_EMAIL`
  to a real, deliverable address** (the `admin@tallyst.local` default is a placeholder whose
  domain bounces, so the admin-notification copy never arrives). The Deployment Readiness Panel
  below is the eventual in-admin surface for these checks.

### Deployment Readiness Panel (installer faza — NE gradi se sad)
Admin "Sustav/Deployment" panel: operativni go-live status + generirani setup snippeti.
Dizajn-dogovor za installer fazu.

- Worker aktivacija: generira stvarnu komandu/config za messenger worker. ODLUKA:
  daemon (systemd/supervisor), NE cron (odabran trajni worker). Generira s detektiranim
  putanjama (php8.5, project dir, bin/console messenger:consume async). CMS GENERIRA,
  admin POKRENE kao server user — CMS nikad ne izvršava (web app ne smije instalirati
  system servise = sigurnosna granica). Napomena: daemon setup traži root. (PRAKSA, naučeno
  2026-06-21: user-level systemd unit u `~/.config/systemd/user/` + `loginctl enable-linger`
  radi BEZ roota i preživi logout/reboot — tako je i pokrenut trenutni worker; system-level
  `/etc/systemd/system` s `User=` i dalje traži root.)
- Worker status indikator: preko HEARTBEATA, NE detekcije procesa (bez shell_exec/ps —
  često ugašeno, krhko, security smell). Worker piše "last seen" timestamp na Messenger
  WorkerRunningEvent (throttlano, u cache/status store); panel čita → ✓ aktivan ako svjež,
  ✗ ako star. Dopuna: health red (broj pending + dob najstarije, iz Doctrine transport tablice).
- Vidljivost komande: de-emfazirana (collapsible) kad radi, istaknuta kad ne radi — NE
  skrivati potpuno (stari heartbeat ≠ sigurno mrtav; komanda je korisna referenca i kad radi).
- Enkripcijski ključ u istom panelu: status SETTINGS_ENCRYPTION_KEY (postavljen/fali →
  app:install); rotacija = jedan secret (SMTP lozinka), promijeni ključ + ponovno upiši
  lozinku (write-only re-enkriptira); rotate-key komanda tek ako se enkriptirani settingsi
  namnože; decrypt-fail graciozno (env fallback + upozorenje).
- Ostali readiness checkovi (future): APP_ENV=prod provjera, mailer konfiguriran + test.
- Preduvjet: heartbeat subscriber je mali enabling dio (može se dodati i ranije ako zatreba).

## Adding a new module (the pattern to follow)
Copy `modules/FormBuilder/` — it is the reference module. Its bundle class
(`AbstractBundle`) self-registers its Doctrine mapping and its `config/services.php`,
and overrides `getPath()` to `__DIR__` (the bundle lives in `modules/<Name>/`, not a
`src/` subdir, so the default heuristic mis-resolves it). Implement `ModuleInterface`
(metadata → shows in the registry) and optionally `AdminModuleInterface`
(`getAdminMenuItems()` → appears under the dashboard "Moduli" section; build CRUD
links with `MenuItem::linkTo(<CrudController>::class, label, icon)` — `linkToCrud()`
is deprecated). Content tags implement `ShortcodeInterface`; both interfaces are auto-tagged via
`#[AutoconfigureTag]`, so no `_instanceof`/services wiring is needed for them.

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
