# Local development install

> "I just want to click around the install wizard on my laptop." This guide gets you there in under a minute on macOS, Windows, or Linux.

## The 30-second path: PHP built-in server + SQLite

After `curl ... | php` has finished, you have a complete Laravel app in the install directory. The fastest way to actually open it in a browser is the PHP development server pointed at SQLite (zero extra services to install).

```bash
cd /path/to/dixlase-install   # the directory you installed into

# Switch the freshly-created .env to SQLite (the installer already touched the
# SQLite file at database/database.sqlite via composer's post-create-project script)
sed -i.bak \
    -e 's/^DB_CONNECTION=.*/DB_CONNECTION=sqlite/' \
    -e 's/^DB_HOST=.*/# DB_HOST=mysql/' \
    -e 's|^DB_DATABASE=.*|DB_DATABASE=database/database.sqlite|' \
    .env && rm -f .env.bak

php artisan migrate --graceful
php artisan serve
# → http://127.0.0.1:8000
```

Windows users without `sed`: open `.env` in an editor and make the same three changes by hand.

The built-in server is single-threaded and meant for development only — fine for clicking through the install wizard, not for production traffic.

## Native Mac / Windows stacks (Laravel-friendly)

For ongoing day-to-day development you probably want PHP-FPM behind a real web server with a real database. Each tool below handles PHP version switching, an SSL-able `*.test` hostname, and a bundled MySQL/Postgres.

### Laravel Herd — macOS & Windows

The current de-facto choice for Laravel developers. <https://herd.laravel.com/>

```bash
# 1. Install Herd from the website (GUI installer)
# 2. Tell Herd to serve your install directory
herd park ~/Sites              # or any parent directory
ln -s /path/to/dixlase-install ~/Sites/dixlase

# 3. Visit
open http://dixlase.test
```

Use the Herd GUI to flip the PHP version to 8.3+ and (optionally) enable HTTPS for `*.test`.

### Laravel Valet — macOS only

Lighter than Herd, command-line driven.

```bash
composer global require laravel/valet
valet install
cd /path/to/dixlase-install
valet link dixlase
# → http://dixlase.test
```

### ddev — Docker wrapper, cross-platform

Best when you want production-shape services (MySQL, Redis) on Mac, Windows, or Linux, without writing Docker Compose yourself.

```bash
cd /path/to/dixlase-install
ddev config --project-type=laravel --docroot=public --create-docroot
ddev start
ddev launch
# → https://dixlase-install.ddev.site
```

### Laragon — Windows only

Bundled Apache/Nginx + MySQL + PHP with auto-vhosts. Drop the install directory under `C:\laragon\www\dixlase` and Laragon serves it at `http://dixlase.test`.

### MAMP / XAMPP / WAMP

Older but still works. Point the document root at `/path/to/dixlase-install/public` in the GUI, set up a MySQL DB, then visit `http://localhost:<port>/`. Configure `.env` to match.

## Pick a database

If you only need to evaluate Dixlase, **SQLite** is enough and requires zero setup. For anything beyond that, prefer **MySQL 8 / MariaDB 10.6+** which Dixlase targets in production.

| Engine | When |
| --- | --- |
| SQLite | Smoke tests, "just open the wizard" |
| MySQL / MariaDB | Realistic local dev, mirrors production |
| Postgres | Works (Laravel supports it) but Dixlase is primarily tested on MySQL — use only if you have a strong reason |

For MySQL / MariaDB, set in `.env`:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=dixlase
DB_USERNAME=dixlase
DB_PASSWORD=secret
```

Then create the database, grant the user, and run `php artisan migrate`.

## Mail in development

Dixlase will eventually want to send mail (admin invitations, etc.). For local development, use a catcher:

- **Mailpit** (recommended) — `brew install mailpit` / Windows binary. Set `MAIL_MAILER=smtp`, `MAIL_HOST=127.0.0.1`, `MAIL_PORT=1025`. Web UI at <http://localhost:8025>.
- **Mailhog** — same idea, older.
- **Log driver** — `MAIL_MAILER=log` writes mail to `storage/logs/laravel.log` (no UI but zero dependencies).

## Recommended path by goal

| Goal | Choose |
| --- | --- |
| Try the wizard for the first time | PHP built-in server + SQLite |
| Day-to-day Laravel dev on Mac/Win | Laravel Herd |
| Production-shaped local stack | [Docker installer](./deploy-docker.md) or ddev |
| Pinned-version reproducibility in CI | ddev or Docker installer |
