# Installing Tallyst

A complete, step-by-step guide to deploying Tallyst on your own server. For a 30-second overview see the [README](../README.md); this is the full version — prerequisites, installation, web-server setup, what to do after, and troubleshooting.

Tallyst installs in two steps: `git clone` (downloads it, keeping its git history so upgrades are clean), then `bin/tallyst-setup` (installs dependencies and launches the interactive `app:install` wizard for the database, admin account, and configuration). Both `bin/tallyst-setup` and `bin/tallyst-upgrade` are **server-agnostic** — they auto-detect your PHP 8.5+ binary and Composer, so nothing about your host is assumed.

---

## 1. Prerequisites

- **PHP 8.5 or newer** — both the CLI and the version your web server runs. Required extensions:
  - `pdo_mysql` — database access
  - `sodium` — encrypts the SMTP password stored in Settings. **`app:install` refuses to run without it.** Verify with `php8.5 -m | grep sodium`.
  - `ctype`, `iconv`, `intl`, `mbstring` — standard Symfony requirements (usually bundled with PHP).
- **MySQL or MariaDB** — create an **empty database and a user** with privileges on it *before* installing. The wizard connects to an existing database; it does not create one for you.
- **[Composer](https://getcomposer.org)** and **git** — Tallyst is installed and updated via `git clone` / `git checkout`.
- For production e-mail (password reset, order confirmations): the ability to run a background process — shown in [Post-installation](#4-post-installation). On a typical Linux host this is a user-level systemd service (no root required).

---

## 2. Installation

```bash
git clone https://github.com/zaja/Tallyst-CMS.git my-site
cd my-site
bin/tallyst-setup
```

- **`git clone`** downloads Tallyst *with* its git history — so every future upgrade is a clean `git checkout` (no re-download, no bridge).
- **`bin/tallyst-setup`** detects a PHP 8.5+ binary and Composer on your server (nothing is hardcoded — it works whether your PHP is `php8.5`, `php`, or elsewhere), installs the production dependencies, then launches **`app:install`** — the interactive wizard that validates your database connection, writes `.env.local`, runs migrations, compiles the front-end assets, and creates your admin account. Open `/admin` and log in.

> ℹ️ **The old `php8.5 $(which composer)` hurdle is gone** — `tallyst-setup` runs Composer through the PHP it detected, so Composer's platform check always sees PHP 8.5+. If the probe can't find your binaries, point it at them:
>
> ```bash
> PHP=/path/to/php8.5 COMPOSER=/path/to/composer bin/tallyst-setup
> ```

`app:install` is an interactive wizard. It first runs a pre-flight check (the `sodium` extension, a writable `var/` directory) and an **already-installed guard** — if it finds a configured database with data, it refuses to overwrite a live site (re-run with `--force` to reconfigure; data is not deleted).

It then asks for:

- **Database connection** — host (default `127.0.0.1`), port (default `3306`), database name, user, and password (hidden input). It **validates the connection immediately** and re-prompts on failure — it never continues with a broken connection. (The database must already exist and be empty.)
- **Admin account** — e-mail, and password (hidden, minimum 8 characters, entered twice).
- **Site name** (default `Tallyst`).
- **Public URL** (`DEFAULT_URI`) — your absolute public URL, e.g. `https://example.com`. It is used for e-mail links and the sitemap, so set the real `https` address.

Then it does the rest automatically: writes `.env.local` (database DSN, public URL, a generated `APP_SECRET`, the `sodium` encryption key, and **`APP_ENV=prod` by default**), runs the database migrations, seeds a default theme + home page + menu, creates your admin user, compiles assets, and clears the cache. When it finishes it prints your admin URL and the next steps.

When it's done, open `https://example.com/admin` and log in with the admin account you created.

---

## 3. Web server / document root

Point your web server's document root at **`my-site/public/`** — **not** the project root. Everything sensitive (`.env.local`, `src/`, `vendor/`, `config/`) lives *above* `public/` and must never be served over the web.

- **CloudPanel** — set the site's **Root Directory** to `.../my-site/public`.
- **nginx** — `root /path/to/my-site/public;` with the standard Symfony front-controller config (`try_files $uri /index.php$is_args$args;`). See the [Symfony nginx guide](https://symfony.com/doc/current/setup/web_server_configuration.html#nginx).
- **Apache** — set `DocumentRoot` to `.../public`; `composer require symfony/apache-pack` adds a suitable `.htaccess`.

> **Web fonts (optional, cosmetic).** The bundled fonts in `public/fonts/` load correctly out-of-the-box — browsers accept `.woff2` from `@font-face` regardless of `Content-Type`, so nothing is needed. If your nginx has an old `mime.types` that serves `.woff2` as `application/octet-stream` and you want the pedantically-correct header, add `font/woff2 woff2;` to nginx's `mime.types` (nginx 1.21.1+ already ships it). Purely cosmetic — fonts work either way.

---

## 4. Post-installation

The installer's closing message lists these too. Work through them before going live.

### Background worker (required for e-mail)

E-mails — password reset, order confirmations, admin notifications — are sent **asynchronously** through a Symfony Messenger worker. Without a running worker they queue but never send. How you keep it running depends on your host — pick the option that fits.

#### Option A — systemd (if your host has it)

The best choice on a VPS or a managed host with user systemd (no root needed, survives logout and reboot). Create `~/.config/systemd/user/tallyst-messenger.service`:

```ini
[Unit]
Description=Tallyst messenger worker
After=network.target

[Service]
ExecStart=/usr/bin/php8.5 /home/USER/htdocs/my-site/bin/console messenger:consume async --time-limit=3600
Restart=always
RestartSec=5

[Install]
WantedBy=default.target
```

Replace the `php8.5` path with the output of `which php8.5` and the project path with yours. Then enable it:

```bash
systemctl --user daemon-reload
systemctl --user enable --now tallyst-messenger
loginctl enable-linger $USER     # keep it running after logout and across reboots
```

Check it with `systemctl --user status tallyst-messenger`. After a deploy (a cache rebuild), restart it: `systemctl --user restart tallyst-messenger`.

#### Option B — cron (works almost everywhere)

If your host has no systemd (typical shared hosting), run the worker from cron. It starts a short-lived worker every minute; `--time-limit=60` makes each one exit before the next starts, so processing is effectively continuous. Add to your crontab (`crontab -e`):

```cron
* * * * * cd /home/USER/htdocs/my-site && /usr/bin/php8.5 bin/console messenger:consume async --time-limit=60 --limit=10 >/dev/null 2>&1
```

Replace the path and the `php8.5` binary with yours (`which php8.5`). No restart step is needed after a deploy — the next minute's run picks up the new code.

#### Option C — supervisor

If your host uses [Supervisor](http://supervisord.org/), point a program at `php8.5 bin/console messenger:consume async --time-limit=3600` with `autostart=true`, `autorestart=true`, and restart it (`supervisorctl restart tallyst-messenger`) after a deploy.

> Confirm the worker is actually running from the admin **readiness panel** (below) — it reports the worker heartbeat and the queue.

### Stripe / PayPal

Enter your keys in the admin under **Postavke → Stripe** and **Postavke → PayPal** (*Settings → Stripe / PayPal*). Each section shows the exact **webhook URL** to paste into the provider's dashboard and the required events:

- **Stripe** — secret key + webhook signing secret; subscribe the webhook to `checkout.session.completed` and `charge.refunded`.
- **PayPal** — client id + secret + webhook id + mode (sandbox/live); subscribe to `PAYMENT.CAPTURE.COMPLETED` and `PAYMENT.CAPTURE.REFUNDED`.

Test and live each need their **own** webhook endpoint (separate keys/secret).

### ⚠️ Webhooks must be publicly reachable (common trap)

The verified webhook is the **single source of truth** for marking an order paid. The webhook routes — **`/webhook/stripe`** and **`/webhook/paypal`** — must be reachable by Stripe/PayPal **without authentication**.

If they sit behind HTTP basic-auth (or an IP allowlist that excludes the provider), the provider's request gets a **401 before it reaches Tallyst**: the payment succeeds on the provider's side, but the order stays **"U obradi"** (processing) and no confirmation e-mails are sent.

- Make sure your web server applies **no** basic-auth / IP restriction to `/webhook/stripe` and `/webhook/paypal`.
- Verify from the admin: **Sustav → Provjera spremnosti** has a **"Provjeri webhook"** button that sends an unsigned test request to your own webhook URL and reports whether it's reachable (a `401` means it's blocked).

### SMTP (e-mail)

Configure SMTP in **Postavke → Email** (*Settings → Email*): host, port, username, password, encryption. Also set the **From address** (*"Email pošiljatelja"*) — it must be an address your SMTP account is allowed to send as, or real mail servers reject it (`553 Sender address rejected`). You can send a test message from that page. (If SMTP is left blank, Tallyst falls back to the `MAILER_DSN` environment variable.)

### Environment mode

The installer sets **`APP_ENV=prod`** by default (neutral error pages, optimized) — keep that for a public site. For local development, set `APP_ENV=dev` in `.env.local` and run `php8.5 bin/console cache:clear`.

---

## 5. Upgrading

Because you installed with `git clone`, upgrading is **one command**:

```bash
bin/tallyst-upgrade
```

With no version it moves to the **latest** release. To pin a specific version, pass its tag — find it under [GitHub Releases](https://github.com/zaja/Tallyst-CMS/releases) and replace it with your target:

```bash
bin/tallyst-upgrade v1.5.1
```

`tallyst-upgrade` is server-agnostic (it detects your PHP 8.5+ binary and Composer) and does everything in order, each step a separate process so nothing rewrites its own running code:

1. `git fetch` + `git checkout <tag>` — swaps the code atomically, and **reports a conflict if you edited core files** (so you find out instead of silently losing changes).
2. `composer install` — dependencies from the lock file (the only step that touches `vendor/`).
3. `app:upgrade:finalize` — DB backup (into `var/backups/`) → `cache:clear` (before migrate) → `doctrine:migrations:migrate` → asset rebuild → `cache:clear`. Idempotent — safe to re-run if a step fails.

### Before you upgrade — back up first

`app:upgrade:finalize` dumps the **database** automatically, but can't back up what lives outside it. Back these up yourself:

- **`.env.local`** — ⚠️ especially `SETTINGS_ENCRYPTION_KEY`. Losing it permanently kills your stored SMTP password (it can't be decrypted).
- **`public/media/`** — your uploads (git-ignored, never in the release).
- **Any custom themes** under `themes/<your-theme>/`.

Your data is safe across an upgrade: `.env.local`, `public/media/`, `public/themes/`, and compiled assets are all git-ignored, so the code swap never touches them.

### After every upgrade

- **Restart the messenger worker** (it keeps running the old code until you do) — the command prints the exact restart line at the end, e.g. `systemctl --user restart tallyst-messenger`.
- **Hard-refresh** the browser — stale compiled assets can linger in the browser cache.
- Open the **readiness panel** (below) to confirm everything is green.

### If an upgrade fails (rollback)

Migrations are **reversible** and the database is backed up automatically, so you can go back to the previous version:

```bash
bin/tallyst-upgrade v1.5.0    # the version you were on (GitHub Releases lists them)
```

If a migration had already run and left the schema changed, restore the database from the automatic pre-upgrade dump:

```bash
mysql -u <user> -p <database> < var/backups/tallyst-pre-upgrade-<timestamp>.sql
```

(Or roll the schema back with `bin/console doctrine:migrations:migrate <previous-version>` — every Tallyst migration has a `down()`.) Then restart the worker.

---

## 6. The readiness panel

After installing — and again before going live — open **Sustav → Provjera spremnosti** (*System → Readiness check*, at `/admin/readiness`). It auto-checks what matters and shows ✅ / ⚠ / ❌ per item with a fix hint:

- **Security** — `APP_SECRET`, encryption key, HTTPS
- **Configuration** — `APP_ENV` (is it `prod`?), `DEFAULT_URI`
- **Mail** — SMTP configured, From address, order-notification address
- **Assets** — compiled assets, published theme
- **Filesystem** — `var/` and the upload directory writable
- **Database** — no pending migrations
- **Background processes** — the worker heartbeat (is it actually running?), the message queue, and the on-demand webhook 401 test

It's honest by design: checks it can't verify with certainty (worker liveness, webhook reachability, real TLS) are marked **"provjeri ručno"** (check manually) with instructions — never a false green.

---

## 7. Troubleshooting

| Symptom | Cause & fix |
| --- | --- |
| `bin/tallyst-setup` / `bin/tallyst-upgrade` can't find PHP or Composer | Point them at your binaries: `PHP=/path/to/php8.5 COMPOSER=/path/to/composer bin/tallyst-setup`. |
| `bin/tallyst-upgrade` says *"not a git checkout"* | The directory has no `.git`. Tallyst is installed via `git clone` (Installation, above) — re-clone into a fresh directory and copy over your `.env.local`, `public/media/`, and custom themes. |
| Payment succeeded but the order stays **"U obradi"** (processing) | The webhook returned **401** — basic-auth or an IP restriction on `/webhook/...`. Make the webhook routes publicly reachable; verify with the readiness panel's webhook test. |
| E-mails don't arrive | The messenger worker isn't running (`systemctl --user status tallyst-messenger`), or SMTP isn't configured / the From address is wrong (553). Check the readiness panel's Mail + Background items. |
| Admin buttons do nothing / no styling | Assets weren't compiled. Run `php8.5 bin/console importmap:install && php8.5 bin/console asset-map:compile && php8.5 bin/console app:theme:assets:install`, then hard-refresh. |
| Detailed Symfony error pages on a public site | `APP_ENV` isn't `prod`. Set `APP_ENV=prod` in `.env.local` and run `php8.5 bin/console cache:clear`. |
| `app:install` says *"već instaliran"* (already installed) | It found a configured database with data and refused to overwrite. Use a fresh empty database, or re-run with `--force` (data isn't deleted; configuration is updated). |

---

Still stuck? Open a [GitHub issue](https://github.com/zaja/Tallyst-CMS/issues) with your PHP version, web server, and the exact error.
