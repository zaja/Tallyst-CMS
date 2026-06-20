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
- Tests / static analysis / lint — NOT YET SET UP. Once installed, replace this
  line, e.g.: `php8.5 bin/phpunit`, `php8.5 vendor/bin/phpstan analyse`,
  `php8.5 vendor/bin/php-cs-fixer fix`.

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
2. **Replacement tags go through the ShortcodeRegistry.** Core knows nothing about
   specific tags. Modules register their own (FormBuilder registers `form`).
   Content is rendered via a `render_content` Twig filter that runs the registry
   over the raw stored content.
3. **The form builder is DATA-DRIVEN — not the Symfony Form component.**
   - `FormDefinition` + `FormField` entities store the admin-built form AS DATA.
   - The end-user form is rendered and validated dynamically at runtime from that
     data. Do NOT model end-user forms with Symfony's compile-time Form component.
   - Use the Symfony Form component only for the ADMIN builder UI itself.
4. **Page-as-product.** A `FormDefinition` may carry `price` + `currency`. On a
   priced submission, create an `Order` and start payment.
5. **Payments use a strategy interface.** `PaymentProcessorInterface` with
   `StripeProcessor` and `PaypalProcessor`. Webhook controllers confirm payment.
   Order lifecycle is managed by Symfony's **Workflow** component:
   `pending → paid → fulfilled → refunded`. Fulfillment triggers on `paid`.
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
1. Copy the structure of `modules/FormBuilder/`.
2. Create `<Name>Bundle.php` and register it in `config/bundles.php`.
3. Implement `ModuleInterface` so it appears in the admin module registry.
4. Entities under `Entity/`; templates under `templates/` (=> `@<Name>` namespace).
5. Register routes and services via the bundle's `config/`.
6. If it adds content tags, register them with the ShortcodeRegistry.
7. Generate + run a migration for any new entities.

## Workflow expectations for Claude Code
- Prefer Explore → Plan → Implement → Commit. Propose a plan before large changes.
- Keep changes scoped. Run `cache:clear` after config or route changes.
- After adding/altering entities, generate and run a Doctrine migration.
- Never leave the public domain in dev/debug mode beyond active development.
- Never commit secrets. DB credentials and Stripe/PayPal keys live in `.env.local`
  (git-ignored) — never in `.env` or in code.
