# Installing Tallyst

A complete, step-by-step guide to deploying Tallyst on your own server. For a 30-second overview see the [README](../README.md); this is the full version — prerequisites, installation, web-server setup, what to do after, and troubleshooting.

Tallyst installs in two steps: `composer create-project` (downloads it and compiles assets), then `app:install` (an interactive wizard for the database, admin account, and configuration).

---

## 1. Prerequisites

- **PHP 8.5 or newer** — both the CLI and the version your web server runs. Required extensions:
  - `pdo_mysql` — database access
  - `sodium` — encrypts the SMTP password stored in Settings. **`app:install` refuses to run without it.** Verify with `php8.5 -m | grep sodium`.
  - `ctype`, `iconv`, `intl`, `mbstring` — standard Symfony requirements (usually bundled with PHP).
- **MySQL or MariaDB** — create an **empty database and a user** with privileges on it *before* installing. The wizard connects to an existing database; it does not create one for you.
- **[Composer](https://getcomposer.org)**.
- For production e-mail (password reset, order confirmations): the ability to run a background process — shown in [Post-installation](#4-post-installation). On a typical Linux host this is a user-level systemd service (no root required).

---

## 2. Installation

### Step 1 — Create the project

```bash
composer create-project tallyst/cms my-site
```

This downloads Tallyst and runs a post-create hook that silently compiles the front-end assets (`importmap:install` → `asset-map:compile` → `app:theme:assets:install`).

> ⚠️ **Composer must run under PHP 8.5+.** Many hosts default to an older `php` (e.g. 8.4). If so, `create-project` fails immediately with a platform error like *"requires php >=8.5 … your php version (8.4.x) does not satisfy that requirement."* Run Composer through the 8.5 binary instead:
>
> ```bash
> php8.5 $(which composer) create-project tallyst/cms my-site
> ```
>
> This is the single most common first-time hurdle.

### Step 2 — Run the installer

```bash
cd my-site
php8.5 bin/console app:install
```

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

---

## 4. Post-installation

The installer's closing message lists these too. Work through them before going live.

### Background worker (required for e-mail)

E-mails — password reset, order confirmations, admin notifications — are sent **asynchronously** through a Symfony Messenger worker. Without a running worker they queue but never send. Run it as a **user-level systemd service** (no root needed, survives logout and reboot):

Create `~/.config/systemd/user/tallyst-messenger.service`:

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

## 5. The readiness panel

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

## 6. Troubleshooting

| Symptom | Cause & fix |
| --- | --- |
| `create-project` fails: *requires php >=8.5 … does not satisfy* | Your default `php` is older. Run Composer via the 8.5 binary: `php8.5 $(which composer) create-project tallyst/cms my-site`. |
| Payment succeeded but the order stays **"U obradi"** (processing) | The webhook returned **401** — basic-auth or an IP restriction on `/webhook/...`. Make the webhook routes publicly reachable; verify with the readiness panel's webhook test. |
| E-mails don't arrive | The messenger worker isn't running (`systemctl --user status tallyst-messenger`), or SMTP isn't configured / the From address is wrong (553). Check the readiness panel's Mail + Background items. |
| Admin buttons do nothing / no styling | Assets weren't compiled. Run `php8.5 bin/console importmap:install && php8.5 bin/console asset-map:compile && php8.5 bin/console app:theme:assets:install`, then hard-refresh. |
| Detailed Symfony error pages on a public site | `APP_ENV` isn't `prod`. Set `APP_ENV=prod` in `.env.local` and run `php8.5 bin/console cache:clear`. |
| `app:install` says *"već instaliran"* (already installed) | It found a configured database with data and refused to overwrite. Use a fresh empty database, or re-run with `--force` (data isn't deleted; configuration is updated). |

---

Still stuck? Open a [GitHub issue](https://github.com/zaja/Tallyst-CMS/issues) with your PHP version, web server, and the exact error.
