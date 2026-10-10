# Troubleshooting

Common failure modes when running `curl -sS https://install.dixlase.net | php`, what they actually mean, and how to fix them.

## "Could not fetch release information from GitHub"

```
▸ Fetching latest release information
  ✗ Could not fetch release information from GitHub. Check your network connection.
```

No token is needed for this request — the repository is public. Possible causes:

1. **No network access to `api.github.com`**, or a GitHub outage. Check that the host can reach GitHub over HTTPS.
2. **The anonymous GitHub API limit is used up.** Anonymous requests share 60 per hour per IP address, so a shared IP (CI, an office, shared hosting) can exhaust it. Wait an hour, or pass a token (see [Optional GitHub token](../README.md#optional-github-token)).
3. **A token is set but is invalid or expired.** GitHub rejects the request instead of treating it as anonymous. Fix the token, or drop it — it is optional.

With `--method=auto` (the default), the installer falls back to `composer create-project` when Composer is installed.

## "Could not find package dixlase/dixlase-core with stability stable"

The Composer path failed at resolution time:

```
In CreateProjectCommand.php line 424:
  Could not find package dixlase/dixlase-core with stability stable.
```

`dixlase/dixlase-core` is not on Packagist, so Composer can only find it through the GitHub repository the installer passes with `--repository`. Either:

- **You are running an old copy of the installer.** Installer 0.1.1 and earlier passed the repository only when a token was set, so an anonymous `--method=composer` always ended here. Fetch the current installer from `https://install.dixlase.net`, or use `--method=zip`.
- **`--version` names a release that has no tag** (for example a typo). Check the tags at <https://github.com/Dixlase/dixlase-core/releases>.

## "composer create-project failed" / "Could not authenticate against github.com"

Composer could not read the Dixlase Core repository. The installer lists the usual causes: no network access to `github.com`, a GitHub outage or rate limit, or a PHP requirement Composer reported. Without a token, the installer has Composer read the repository with `git` (so the anonymous API limit does not apply) — but only when `git` is installed. Without `git`, Composer falls back to the GitHub API, and "Could not authenticate against github.com" usually means its anonymous limit is used up. Install `git`, wait an hour, or pass a token. The simplest fix is `--method=zip`: the release ZIP ships pre-built and needs no resolution.

## "Project directory ... is not empty"

```
In CreateProjectCommand.php line 371:
  Project directory "/path/to/install" is not empty.
```

Composer refuses to overwrite a directory that already has files. This typically happens after a previous failed attempt left partial files behind. Wipe and retry:

```bash
cd ~
rm -rf /path/to/install
mkdir -p /path/to/install
cd /path/to/install
curl -sS https://install.dixlase.net | php
```

## "stream_context_create(): Argument #1 must be ... cannot access protected method"

```
[TypeError]
stream_context_create(): Argument #1 ($options) must be an array with valid
callbacks as values, cannot access protected method
Composer\Util\RemoteFilesystem::callbackGet()
```

A bug in Composer 2.7.x triggered on PHP 8.4 and newer. Fixed in Composer 2.8+. Run:

```bash
sudo composer self-update
composer --version    # should report 2.8.x or 2.9.x
```

Then clean and retry the installer.

## "The lock file is not up to date with the latest changes in composer.json"

```
Warning: The lock file is not up to date with the latest changes in composer.json.
  - Required package "composer/installers" is not present in the lock file.
  - Required package "dixlase/dixlase-onepage" is not present in the lock file.
```

The Dixlase Core repository pushed `composer.json` changes without regenerating `composer.lock`. This is an upstream bug — open an issue at <https://github.com/Dixlase/dixlase-core/issues>. As a workaround, install via the ZIP fallback explicitly:

```bash
curl -sS https://install.dixlase.net | php -- --method=zip
```

## "Failed to extract: ... ???something.blade.php"

```
Failed to extract dixlase/dixlase-core: (50) /usr/bin/unzip ...
.../???security-notifications.blade.php: write error (disk full?)
The archive may contain identical file names with different capitalization
```

A file in the Dixlase Core archive collides on case-insensitive filesystems (typical of macOS APFS). Composer automatically falls back to its built-in `ZipArchive` extractor, which doesn't have the issue — **so this warning is not fatal**, the install continues. The underlying duplicate filename should be fixed in dixlase-core (open an upstream issue).

## "php_network_getaddresses: getaddrinfo for mysql failed"

```
WARN  SQLSTATE[HY000] [2002] php_network_getaddresses: getaddrinfo for mysql failed
```

The `.env` still points to `DB_HOST=mysql` (the Docker default hostname). This is benign during install — `migrate --graceful` skips when the database is unreachable — but you need to fix it before the first browser visit. Edit `.env` with the actual DB host (e.g. `127.0.0.1` for a local DB, or the rental host's DB hostname).

## "Composer install failed (exit code 4)"

Composer 2 treats lock-file inconsistencies as fatal under `--no-interaction`. Same root cause as the "lock file not up to date" warning above. Fix by:

1. Asking the upstream to regenerate `composer.lock`, or
2. Using `--method=zip` to skip composer's lock-based install, or
3. Running the installer in a directory that already has a fresh checkout you maintain manually with `composer update`.

## Token leaked in shell history

If you accidentally pasted the token literally on the command line, **revoke it immediately** at <https://github.com/settings/tokens?type=beta> and create a new one. The leaked token is in:

- Your shell history (`~/.bash_history`, `~/.zsh_history`)
- Any logs you may have shared (chat transcripts, GitHub issues, paste sites)

In future, set the token in the environment first so it doesn't land in history:

```bash
read -rs GITHUB_TOKEN && export GITHUB_TOKEN
# (paste the token, hit Enter — input is hidden)
curl -sS https://install.dixlase.net | php
unset GITHUB_TOKEN
```

Or store it in your OS keychain and read it at runtime (macOS):

```bash
# Save once
security add-generic-password -s dixlase-installer -a "$USER" -w

# Run (env on the right side of the pipe so the token reaches php, not curl)
curl -sS https://install.dixlase.net | env GITHUB_TOKEN="$(security find-generic-password -s dixlase-installer -w)" php
```

> ⚠️ The naive form `GITHUB_TOKEN=$(security ...) curl ... | php` does **not** work — the temporary env assignment applies to `curl` only, not to `php` on the right of the pipe. Either use `env GITHUB_TOKEN=...` in front of `php` as above, or `export GITHUB_TOKEN=$(security ...)` before the pipeline and `unset GITHUB_TOKEN` after.

## Still stuck?

- Re-run with `--non-interactive --yes` removed if you want to confirm each step interactively.
- Run `php install.php --help` (after `curl -sS https://install.dixlase.net > install.php`) to review every flag.
- File an issue at <https://github.com/Dixlase/dixlase-oneliner-installer/issues> with the full output (redact any tokens).
