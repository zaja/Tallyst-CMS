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
  postPersist thumbnail warm fire identically on both paths.
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
    dispatches a `media-library:select` event `{id,name,thumbUrl}` — it never touches a
    hidden field, so the editor (Prolaz B) can reuse it. Open it by dispatching
    `media-library:open` on its element. Modal markup is the reusable partial
    `@Media/admin/_media_library_modal.html.twig` (today included per picker widget; lift
    to one shared instance when more consumers appear).
  - `filepond_factory.js` is the ONE FilePond setup (plugins + process endpoint + CSRF
    header + raster/≤5 MB client checks mirroring the server), shared by the library modal
    and the bulk page. FilePond is via importmap (`filepond` + image-preview +
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
- **Bulk upload** page: `media_bulk_upload` (`/admin/media/bulk-upload`, EA-shell) — a
  FilePond drop zone (`media--bulk-upload` controller) that creates one Media per dropped
  image via the same factory + endpoint. Linked from the Media admin menu.

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

## WYSIWYG editor
No editor ships natively with Symfony. Load a permissively-licensed editor
(**Trix** — MIT, or **Tiptap** — MIT) via AssetMapper + a Stimulus controller
bound to a textarea. AVOID CKEditor 5 / TinyMCE for the shipped product (GPL or
commercial dual license). A Tiptap custom button can insert the `[form id=N]`
tag for good UX.

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
