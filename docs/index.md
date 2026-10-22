# Dixlase Oneliner Installer Documentation

The one-liner `curl -sS https://install.dixlase.net | php` works on any environment that exposes a shell, PHP 8.3+, and outbound HTTPS. This section walks through the realistic deployment scenarios.

For Japanese, see [ja/index.md](./ja/index.md).

## Pick the scenario that matches your target

| Scenario | Guide | When to use |
| --- | --- | --- |
| Local development on your laptop | [deploy-local-dev.md](./deploy-local-dev.md) | First-time evaluation, contributor setup, "just want to see the wizard" |
| Docker on your machine | [deploy-docker.md](./deploy-docker.md) | Production-shaped local stack (Nginx + PHP-FPM + MariaDB + Redis + Mailpit) |
| VPS / cloud VM | [deploy-vps.md](./deploy-vps.md) | The recommended path for self-hosting Dixlase |
| Shared / rental hosting | [deploy-shared-hosting.md](./deploy-shared-hosting.md) | When the host gives you SSH + PHP 8.3+ (and the workaround for SFTP-only plans) |
| Troubleshooting | [troubleshooting.md](./troubleshooting.md) | Common failures, their root cause, and the exact fix |

## Prerequisites recap

The installer always needs:

- **PHP 8.3+** with the standard extensions (`openssl`, `pdo`, `mbstring`, `tokenizer`, `xml`, `ctype`, `json`, `bcmath`, `curl`, `fileinfo`, `gd`)
- **Composer** (recommended — used when a ZIP lacks `vendor/` and by the `--method=composer` path)
- **Node.js 24** (or any `^20.19 || >=22.12`) — only when the frontend assets have to be built; pass `--no-build` to skip the build entirely
- **Outbound HTTPS** to `github.com`, `api.github.com`, `packagist.org`, and `install.dixlase.net`
- A shell where you can run `curl ... | php` (so plain SFTP-only hosts can't use it directly — see [deploy-shared-hosting.md](./deploy-shared-hosting.md) for the workaround)

No GitHub token is needed — every Dixlase repository is public. A token only raises the GitHub API rate limit (60 anonymous requests per hour per IP); see the main [README.md](../README.md#optional-github-token) for when it helps, and the [delivery path details](../README.md#how-it-works).

## Keeping PHP up to date

Updating Dixlase never updates PHP. PHP comes from the OS, a version manager or the host's panel, so a PHP security release reaches the site only when you update PHP itself. The installer warns when the PHP it runs on is missing the latest security fixes for its branch.

| Deployment | How to update PHP |
| --- | --- |
| VPS / cloud VM | Upgrade the PHP packages (e.g. `sudo apt update && sudo apt upgrade 'php8.3*'`), then `sudo systemctl reload php8.3-fpm` so PHP-FPM runs the new binary |
| Local, PHP built-in server | Update PHP with your package manager or version manager (Homebrew, Herd, …), then stop the server (Ctrl+C) and start it again |
| Shared / rental hosting | Pick the newest patch release of PHP 8.3+ in the hosting panel, or ask the host when it applies security releases |
| Docker installer | `./update.sh --rebuild` rebuilds the PHP image — see the [Docker installer](https://github.com/Dixlase/dixlase-docker-installer) README |

Check the result with `php -v` (CLI) and, for PHP-FPM, a page that reports `PHP_VERSION` — the two can differ when several PHP versions are installed.
