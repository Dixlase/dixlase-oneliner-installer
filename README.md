# Dixlase Oneliner Installer

[![CI](https://github.com/Dixlase/dixlase-oneliner-installer/actions/workflows/ci.yml/badge.svg)](https://github.com/Dixlase/dixlase-oneliner-installer/actions/workflows/ci.yml)

One-line installer for [Dixlase](https://github.com/Dixlase/dixlase-core). A single PHP script downloads and bootstraps Dixlase on a server with one shell command.

For Japanese, see [README.ja.md](./README.ja.md).

## Quick Start

```bash
curl -sS https://install.dixlase.net | php
```

That's it. The script picks the best delivery path for your environment and walks through the rest. When run interactively (`php install.php`), it asks for the install directory and confirms before proceeding.

**Database:** to try Dixlase or run a small site, choose **SQLite** in the install wizard's Database step — it needs no database server (only PHP's `pdo_sqlite` extension). For production, use MySQL / MariaDB (see [docs/deploy-vps.md](docs/deploy-vps.md)).

## Prerequisites

- **PHP 8.3+** with the standard extensions (`openssl`, `pdo`, `mbstring`, `tokenizer`, `xml`, `ctype`, `json`, `bcmath`, `curl`, `fileinfo`, `gd`)
- **Composer** (recommended — runs `composer install` when a ZIP lacks `vendor/`, and powers the `--method=composer` path)
- **Node.js 24** (or any `^20.19 || >=22.12`, the range Vite 8 supports) — only when the frontend assets have to be built; release ZIPs ship them pre-built
- **Network access** to GitHub (both delivery paths download from it)
- **No GitHub token** — every Dixlase repository is public; see [Optional GitHub token](#optional-github-token)

The installer pre-checks all of the above and prints a clear remediation hint if something is missing.

## How it works

There are two delivery paths. The default `--method=auto` tries the ZIP first and falls back to Composer:

1. **GitHub Releases ZIP** (default) — downloads `dixlase-v<version>.zip` from `github.com/Dixlase/dixlase-core/releases`, verifies the SHA-256 checksum when the release has one, and extracts it. The ZIP ships `vendor/` and the built assets, so nothing has to be resolved; `composer install` runs only if `vendor/` is missing.
2. **`composer create-project`** (fallback, or `--method=composer`) — needs Composer on `PATH`. `dixlase/dixlase-core` is not on Packagist, so the installer points Composer at the package's GitHub repository (`--repository` with a VCS entry) and installs the latest tagged release, or the one `--version` names. Without a token, Composer reads that repository with `git` instead of the GitHub API, so the anonymous API rate limit does not get in the way.

After either path, common post-install steps run: copy `.env.example` → `.env`, generate `APP_KEY` (only when it is empty), set permissions on `storage/` and `bootstrap/cache/`, and create the `storage:link`. Database / admin / mail configuration is then handled by the Dixlase install wizard on first browser visit.

## Options

```
Usage:
  curl -sS https://install.dixlase.net | php
  curl -sS https://install.dixlase.net | php -- [options]
  php install.php [options]

Options:
  --dir=PATH          Installation directory (default: <cwd>/dixlase).
                      Pass --dir=. to install into the current directory
                      in place instead of creating a subdirectory.
  --version=X.X.X     Install a specific version (default: latest)
  --method=MODE       Delivery method: auto, composer, or zip (default: auto)
  --no-composer       Skip "composer install" in the zip fallback path
  --no-build          Skip "npm ci && npm run build" (frontend asset build)
  --non-interactive   Disable prompts even when STDIN is a terminal
  -y, --yes           Auto-confirm every prompt
  --force-reinstall   Install over an existing site (keeps its APP_KEY)
  -h, --help          Show this help message
```

The installer is for new installs only. If the target directory already holds a site (`.env`, `artisan`, `bootstrap/app.php` or `vendor/`), it stops without changing anything; update an existing site with `php artisan dls:core:update`. `--force-reinstall` overwrites the files but keeps the existing `.env` and `APP_KEY`, so encrypted data stays readable.

Examples:

```bash
# Install latest into /var/www/dixlase
curl -sS https://install.dixlase.net | php -- --dir=/var/www/dixlase

# Pin a specific version
curl -sS https://install.dixlase.net | php -- --version=1.0.0

# Force the ZIP fallback even if Composer is available
curl -sS https://install.dixlase.net | php -- --method=zip

# Run locally with full prompts
php install.php

# Run locally with no prompts (CI-friendly)
php install.php --non-interactive --yes --dir=/srv/dixlase
```

## Installer version

The installer has its own version, shown under the banner (`Installer v0.1.2`) and listed in [CHANGELOG.md](./CHANGELOG.md). It is independent of Dixlase core: `--version` above selects the **core** version to install.

`install.dixlase.net` serves the latest installer release. To run a fixed installer version, fetch it from its tag:

```bash
curl -sS https://raw.githubusercontent.com/Dixlase/dixlase-oneliner-installer/v0.1.2/install.php | php
```

## Optional GitHub token

**You do not need a token.** Every Dixlase repository is public, and both delivery paths work anonymously.

A token helps in two cases:

- **GitHub API rate limit** — anonymous requests share a limit of 60 per hour per IP address. If you install repeatedly, or share an IP with others (CI, an office, shared hosting), a token raises the limit.
- **Your own private fork** — if you point the installer at a private copy of the repository.

```bash
curl -sS https://install.dixlase.net | GITHUB_TOKEN=github_pat_xxx php
```

- A **fine-grained personal access token** with **Contents: Read-only** on the repository is enough (for the rate limit alone, read-only access to public repositories is enough).
- The token is read from `DIXLASE_GITHUB_TOKEN` (preferred) or `GITHUB_TOKEN`. Provide the token via the environment, never as a CLI argument, so it stays out of the shell history and process list.
- It is sent **only** to GitHub hosts (and to any host you explicitly set via the URL override env vars below); it is never forwarded to a third-party mirror.
- The Composer path always resolves `dixlase/dixlase-core` from its GitHub repository; the token only authenticates those requests. `install.php` hands it to Composer via `COMPOSER_AUTH` and removes it from the environment once Composer has finished. You only ever set `GITHUB_TOKEN`.

## Localization

Comments and user-facing messages in `install.php` default to English. To switch them to Japanese (or back), use the bundled converter:

```bash
./convert-comments.sh ja                # All files: English -> Japanese
./convert-comments.sh ja install.php    # One file: English -> Japanese
./convert-comments.sh ja --reverse      # All files: Japanese -> English (revert)
```

Translation dictionaries live at `lang/<locale>/<source-path>.tsv` (tab-separated `<english-text>\t<locale-text>` pairs). To add a new locale or extend an existing one, drop a new TSV next to the existing files; see [CLAUDE.md](./CLAUDE.md) for the format.

## Documentation

Per-scenario deployment guides live under [`docs/`](./docs/). Pick the one that matches your target:

- [Local development install](./docs/deploy-local-dev.md) — PHP built-in server, Laravel Herd, ddev, etc.
- [Docker install](./docs/deploy-docker.md) — pointer to the dedicated Docker installer repo
- [VPS / cloud VM install](./docs/deploy-vps.md) — the recommended self-host path (Ubuntu example)
- [Shared / rental hosting install](./docs/deploy-shared-hosting.md) — incl. the workaround for SFTP-only plans and PaaS
- [Troubleshooting](./docs/troubleshooting.md) — common failures and their root cause

Japanese mirror under [`docs/ja/`](./docs/ja/).

## Repository layout

```
.
├── install.php             # Single-file PHP installer (the thing served at install.dixlase.net)
├── convert-comments.sh     # Switch script comments / messages between locales
├── lang/{en,ja}/           # Translation dictionaries (TSV)
├── docs/, docs/ja/         # Deployment guides + troubleshooting (en / ja)
├── tests/                  # bats integration suite + fixtures + helpers
├── .github/workflows/      # GitHub Actions CI
├── CLAUDE.md / CLAUDE.ja.md # Coding rules for contributors and AI tools
├── LICENSE                 # MIT
└── README.md / README.ja.md
```

## Tests

The [bats](https://github.com/bats-core/bats-core) suite under `tests/` covers help output, argument validation, the full ZIP delivery path (against a local mock GitHub server), the `convert-comments.sh` round-trip, and banner alignment in both locales.

```bash
brew install bats-core              # macOS
sudo apt-get install -y bats        # Debian / Ubuntu

bats tests/
```

CI runs the same suite on PHP 8.3 / 8.4 / 8.5 — see [.github/workflows/ci.yml](./.github/workflows/ci.yml).

### URL overrides

`install.php` reads two optional environment variables, so tests, internal mirrors, and air-gapped installs can redirect downloads without editing the script:

| Variable | Default | Purpose |
| --- | --- | --- |
| `DIXLASE_API_LATEST_URL` | `https://api.github.com/repos/Dixlase/dixlase-core/releases/latest` | Endpoint that returns `{"tag_name": "vX.Y.Z"}` |
| `DIXLASE_RELEASE_URL_BASE` | `https://github.com/Dixlase/dixlase-core/releases/download` | Base URL; the script appends `/v<version>/dixlase-v<version>.zip` and `/v<version>/checksums.sha256` |

## License

This installer (`install.php` and supporting scripts / dictionaries) is released under the **MIT License** — see [LICENSE](./LICENSE). Contributions do not require a CLA.

The Dixlase application itself is licensed separately under AGPL v3 — see [Dixlase Core](https://github.com/Dixlase/dixlase-core).
