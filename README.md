# Dixlase Oneliner Installer

One-line installer for [Dixlase](https://github.com/Dixlase/dixlase-core). A single PHP script downloads and bootstraps Dixlase on a server with one shell command.

For Japanese, see [README.ja.md](./README.ja.md).

## Quick Start

```bash
curl -sS https://install.dixlase.com | php
```

That's it. The script picks the best delivery path for your environment and walks through the rest. When run interactively (`php install.php`), it asks for the install directory and confirms before proceeding.

## Prerequisites

- **PHP 8.2+** with the standard extensions (`openssl`, `pdo`, `mbstring`, `tokenizer`, `xml`, `ctype`, `json`, `bcmath`, `curl`, `fileinfo`, `gd`)
- **Composer** (recommended — used by the primary delivery path)
- **Network access** to GitHub (for the ZIP fallback) or Packagist (for `composer create-project`)

The installer pre-checks all of the above and prints a clear remediation hint if something is missing.

## How it works

The installer chooses one of two delivery paths automatically:

1. **`composer create-project`** (preferred) — used when Composer is on `PATH`. Runs `composer create-project dixlase/dixlase-core <dir>` so dependencies are resolved from Packagist.
2. **GitHub Releases ZIP** (fallback) — downloads `dixlase-v<version>.zip` from `github.com/Dixlase/dixlase-core/releases`, verifies the SHA-256 checksum, extracts it, and runs `composer install` if Composer is available.

After either path, common post-install steps run: copy `.env.example` → `.env`, generate `APP_KEY`, set permissions on `storage/` and `bootstrap/cache/`, and create the `storage:link`. Database / admin / mail configuration is then handled by the Dixlase install wizard on first browser visit.

## Options

```
Usage:
  curl -sS https://install.dixlase.com | php
  curl -sS https://install.dixlase.com | php -- [options]
  php install.php [options]

Options:
  --dir=PATH          Installation directory (default: current directory)
  --version=X.X.X     Install a specific version (default: latest)
  --method=MODE       Delivery method: auto, composer, or zip (default: auto)
  --no-composer       Skip "composer install" in the zip fallback path
  --non-interactive   Disable prompts even when STDIN is a terminal
  -y, --yes           Auto-confirm every prompt
  -h, --help          Show this help message
```

Examples:

```bash
# Install latest into /var/www/dixlase
curl -sS https://install.dixlase.com | php -- --dir=/var/www/dixlase

# Pin a specific version
curl -sS https://install.dixlase.com | php -- --version=1.0.0

# Force the ZIP fallback even if Composer is available
curl -sS https://install.dixlase.com | php -- --method=zip

# Run locally with full prompts
php install.php

# Run locally with no prompts (CI-friendly)
php install.php --non-interactive --yes --dir=/srv/dixlase
```

## Localization

Comments and user-facing messages in `install.php` default to English. To switch them to Japanese (or back), use the bundled converter:

```bash
./convert-comments.sh ja                # All files: English -> Japanese
./convert-comments.sh ja install.php    # One file: English -> Japanese
./convert-comments.sh ja --reverse      # All files: Japanese -> English (revert)
```

Translation dictionaries live at `lang/<locale>/<source-path>.tsv` (tab-separated `<english-text>\t<locale-text>` pairs). To add a new locale or extend an existing one, drop a new TSV next to the existing files; see [CLAUDE.md](./CLAUDE.md) for the format.

## Repository layout

```
.
├── install.php             # Single-file PHP installer (the thing served at install.dixlase.com)
├── convert-comments.sh     # Switch script comments / messages between locales
├── lang/{en,ja}/           # Translation dictionaries (TSV)
├── CLAUDE.md / CLAUDE.ja.md # Coding rules for contributors and AI tools
├── LICENSE                 # MIT
└── README.md / README.ja.md
```

## License

This installer (`install.php` and supporting scripts / dictionaries) is released under the [MIT License](./LICENSE) so it can be freely forked, modified, and redistributed.

The Dixlase application itself is licensed separately under AGPL v3 — see [Dixlase Core](https://github.com/Dixlase/dixlase-core).
