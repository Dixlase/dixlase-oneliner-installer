# Changelog

All notable changes to the Dixlase one-line installer (`install.php`, served at `install.dixlase.net`) are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the installer follows Semantic Versioning. Its version is independent of Dixlase core: `--version` selects the core version to install, not the installer's. The installer version is shown under the banner.

A release is cut when the script's behaviour changes, and `install.dixlase.net` is updated to serve the latest release at the same time.

## [Unreleased]

### Added

- The PHP check warns when the running PHP is missing the latest security fixes for its branch (8.3.36 / 8.4.27 / 8.5.12), and says that updating Dixlase never updates PHP. The install still continues. The docs gain "Keeping PHP up to date" (#23).

### Fixed

- `public/storage` is now the relative link `../storage/app/public`, so it keeps working when the site is moved or installed locally and uploaded (shared hosting, Path B). It was an absolute link from `artisan storage:link`, which then pointed at the local machine and every public-disk file answered 404. An existing `public/storage` is left alone, and `artisan storage:link` remains the fallback (#24).
- The permission step skips symbolic links under `storage/` and `bootstrap/cache/`. It followed them and changed the mode of their targets, which may lie outside the install directory (#25).

## [0.1.2] — 2026-10-10

### Fixed

- `--method=composer` works without a GitHub token: the installer always points Composer at the core's GitHub repository (it is not on Packagist), and without a token has Composer read it with `git` rather than the rate-limited GitHub API (#19).
- The Composer path no longer passes `--stability=dev`, which made a token-authenticated install without `--version` pick the unreleased `dev-main` instead of the latest tagged release (#19).
- When `composer create-project` fails, the hint lists the real causes and suggests `--method=zip`, instead of saying a token is needed for a private repository (#19).

### Changed

- The README and docs describe the GitHub token as optional: it only raises the GitHub API rate limit or reaches a private fork (#19).

### Upgrade notes

Nothing to do: every run downloads the script afresh. A GitHub token is no longer needed for any delivery path.

## [0.1.1] — 2026-10-03

### Added

- The banner shows the installer version (`Installer v0.1.1`) (#17).
- This changelog, and a test that keeps the version constant and the newest changelog entry in step (#17).

### Changed

- The README explains how to run a fixed installer version from its tag, and that `--version` selects the core version.

### Upgrade notes

Nothing to do: every run downloads the script afresh.

## [0.1.0] — 2026-10-01

Initial public release, published with Dixlase 0.1.0 (Beta 1).

### Added

- `curl -sS https://install.dixlase.net | php` installs Dixlase with `composer create-project` when Composer is available, and otherwise from the GitHub release ZIP, verified against the release's `checksums.sha256`.
- Interactive and non-interactive modes, `--dir`, `--version`, `--method`, `--no-composer`, `--no-build`, `--yes` and `--force-reinstall`.
- Environment checks: PHP 8.3 or later with the required extensions, and the Node range the asset build needs (the build is skipped with a notice when Node does not fit).
- Refuses to install over an existing site unless `--force-reinstall` is given (which keeps its `APP_KEY`), and works in a private temporary directory.
- The completion message recommends SQLite for a quick start, and shows how to run PHP's built-in server with four workers and when to restart it.
- English and Japanese messages through `./convert-comments.sh`.
- Released under the MIT License.
