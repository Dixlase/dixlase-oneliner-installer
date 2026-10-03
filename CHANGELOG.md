# Changelog

All notable changes to the Dixlase one-line installer (`install.php`, served at `install.dixlase.net`) are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the installer follows Semantic Versioning. Its version is independent of Dixlase core: `--version` selects the core version to install, not the installer's. The installer version is shown under the banner.

A release is cut when the script's behaviour changes, and `install.dixlase.net` is updated to serve the latest release at the same time.

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
