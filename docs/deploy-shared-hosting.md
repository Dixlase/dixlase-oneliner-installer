# Shared / rental hosting install

> Whether the one-liner is usable depends entirely on what the rental plan exposes. This guide splits the answer into the two realistic cases.

## Prerequisite checklist

Before anything else, confirm with your hosting control panel:

- [ ] **SSH** is available (the one-liner is a shell command — without a shell, it can't run)
- [ ] **PHP 8.3 or later** is selectable for your account
- [ ] **PHP extensions**: `openssl pdo mbstring tokenizer xml ctype json bcmath curl fileinfo gd` are all enabled
- [ ] **Composer** is on the PATH (most premium shared plans bundle it; check with `composer --version` over SSH)
- [ ] **MySQL / MariaDB** database can be created from the panel
- [ ] **Outbound HTTPS** to `github.com`, `api.github.com`, `packagist.org`, and `install.dixlase.net` is not blocked
- [ ] **Document root** can be pointed at a subdirectory (you need to expose `public/`, not the project root)

Plans where every line above is "yes" → [Path A](#path-a-ssh--composer--php-82-rental-plan). Plans without SSH → [Path B](#path-b-sftp-only-rental-plan).

## Path A — SSH + Composer + PHP 8.3+ rental plan

Examples that typically fit: Sakura Rental Server (Standard+), X-Server, mixhost, ConoHa WING, KAGOYA. Always double-check current plan specs before signing up.

### 1. SSH into the account and run the one-liner

```bash
ssh user@your-rental.example.jp
cd ~/www                              # the panel-managed web root (varies by host)
mkdir dixlase && cd dixlase
curl -sS https://install.dixlase.net | GITHUB_TOKEN=github_pat_xxx php
```

If `php` on the default PATH is too old (the panel says PHP 8.3+ but the SSH default is older), most hosts publish a path like `/usr/local/php/8.3/bin/php`. Invoke that explicitly:

```bash
curl -sS https://install.dixlase.net | GITHUB_TOKEN=github_pat_xxx /usr/local/php/8.3/bin/php
```

### 2. Point the document root at `dixlase/public`

Done through the hosting control panel — not the installer. Most panels offer a "公開フォルダ" / "Web root" field per subdomain.

If you can't change the document root, place a single-line `.htaccess` at the panel's web root that redirects everything into Dixlase's `public/`:

```apache
DirectoryIndex disabled
RewriteEngine On
RewriteRule ^$ public/ [L]
RewriteRule ^((?!public/).*)$ public/$1 [L,NC]
```

### 3. Create a database from the panel and edit `.env`

Most rental panels generate the DB hostname, username, and database name for you. Plug them into `~/www/dixlase/.env` (DB_HOST, DB_DATABASE, DB_USERNAME, DB_PASSWORD).

### 4. Run migrations

```bash
cd ~/www/dixlase
php artisan migrate --force
```

### 5. Open the URL — the install wizard finishes the rest

## Path B — SFTP-only rental plan (no SSH)

Cheap shared plans (e.g. Lolipop! Light, very basic hosting tiers) don't let you run `php` from a shell. The one-liner doesn't apply here directly. The reliable workaround is **install locally, then upload**:

### 1. Install on your local machine first

Follow [deploy-local-dev.md](./deploy-local-dev.md). After the one-liner finishes you have a complete tree at e.g. `/path/to/dixlase-install/`.

### 2. Upload via SFTP / rsync

Push everything except the local-only artefacts:

```bash
rsync -avz \
    --exclude='.git' --exclude='node_modules' \
    --exclude='.env' --exclude='database/database.sqlite' \
    /path/to/dixlase-install/  user@host:/path/to/public_html/
```

### 3. Set up `.env` on the host

Create `.env` on the server (via SFTP or the panel's file manager) by copying `.env.example` and filling in the host's DB credentials.

Generate the app key locally and paste it in (you only need the resulting string, not a shell on the host):

```bash
# locally, against a throwaway clone
php artisan key:generate --show
# copy the printed base64:... value into APP_KEY=... in the uploaded .env
```

### 4. Run migrations

If the host offers **scheduled tasks / cron / "PHP CLI executor"** in the panel, use it to run:

```
php /path/to/public_html/artisan migrate --force
```

If not, a one-time bootstrap script (e.g. `setup.php`) under `public/` can be hit via the browser to run migrations, then deleted. Make sure it requires a one-shot token in the URL.

### 5. Point the panel's document root at the uploaded `public/` directory

## Common rental-hosting pitfalls

| Symptom | Likely cause |
| --- | --- |
| `composer create-project failed` with no clear message | The host's outbound firewall blocks `api.github.com` or `packagist.org`. Ask support to whitelist, or use the offline upload path. |
| `Class App\... does not comply with PSR-4` warnings during composer install | Pre-existing dixlase-core issue, not a host problem. Safe to ignore for now. |
| `php_network_getaddresses: getaddrinfo for mysql failed` | `.env` still points to `DB_HOST=mysql` (the Docker default). Replace it with the hostname your panel shows. |
| File uploads fail above a few MB | `upload_max_filesize` / `post_max_size` need to be raised, usually via panel or `php.ini` overrides. |
| 500 on every page | Check `storage/logs/laravel.log`. Almost always a missing PHP extension or an unwritable `storage/`. |

## Managed PaaS (Heroku, Render, Railway, Fly.io, Cloud Run)

Managed platforms don't expose a persistent shell, and the build step compiles the app from a git push or container image. The one-liner is the wrong tool there — use the platform's native deploy path:

1. Fork or clone `Dixlase/dixlase-core` into your own GitHub account (private is fine).
2. Add the platform's deploy descriptor (`Procfile`, `render.yaml`, `fly.toml`, `Dockerfile`, etc.).
3. Configure `COMPOSER_AUTH` / `GITHUB_TOKEN` as a build-time secret so the theme dependency resolves.
4. Push — the platform runs `composer install` for you.

You can copy ideas from the [DixlaseInstallerDocker](https://github.com/Dixlase/dixlase-installer-docker) Dockerfile as a starting point.
