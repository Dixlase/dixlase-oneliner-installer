# Troubleshooting

Common failure modes when running `curl -sS https://install.dixlase.net | php`, what they actually mean, and how to fix them.

## "Could not fetch release information from GitHub"

```
▸ Fetching latest release information
  ✗ Could not fetch release information from GitHub. Check your network connection.
```

Three possible causes — the right fix depends on which:

1. **You are not sending a GitHub token, and the Dixlase Core repository is private.** Add `GITHUB_TOKEN=github_pat_xxx` in front of `php` and retry. See the [README's private-repositories section](../README.md#private-repositories) for how to create a token.
2. **A token is set but does not have access to `Dixlase/dixlase-core`.** Edit the token's "Repository access" list to include the repo and grant **Contents: Read-only**.
3. **There are no GitHub Releases yet on the repository.** The API returns 404 in that case. The composer path (the primary delivery path) does not need a release; if composer is installed locally the script will use it instead. To force the composer path explicitly: add `--method=composer` to the install command.

## "Could not find package dixlase/dixlase-core with stability stable"

The Composer path failed at resolution time:

```
In CreateProjectCommand.php line 424:
  Could not find package dixlase/dixlase-core with stability stable.
```

Either:

- **The Dixlase Core repository's `composer.json` doesn't have `"name": "dixlase/dixlase-core"`.** Once the upstream rename PR is merged this resolves on its own.
- **The repository has no tagged release**, so composer cannot find a stable version. The installer passes `--stability=dev` when a token is set, which sidesteps this. If you still see this error, your token is not being detected — re-check that `GITHUB_TOKEN=` is on the same line as `php`.

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
curl -sS https://install.dixlase.net | GITHUB_TOKEN=... php
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
curl -sS https://install.dixlase.net | GITHUB_TOKEN=... php -- --method=zip
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
