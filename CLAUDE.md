# Dixlase Oneliner Installer — Coding Rules

For Japanese, see [CLAUDE.ja.md](./CLAUDE.ja.md).

Rules every contributor (human or AI) must follow when working in this repository.

## Project overview

- Pipe-friendly installer: `curl -sS https://install.dixlase.net | php`
- Single-file PHP script (`install.php`); no runtime dependencies beyond a PHP 8.3+ interpreter
- Two delivery paths:
  - `composer create-project dixlase/dixlase-core` (preferred when Composer is on PATH)
  - GitHub Releases ZIP fallback (when Composer is missing)
- Target: Dixlase — repo `Dixlase/dixlase-core`

## Commit message convention

Follow the unified commit message convention used across all Dixlase projects.

### Required rules

1. **No `Co-Authored-By:` line**
   - Forbid every `Co-Authored-By` line, including Claude / `noreply@anthropic.com`.

2. **Use a Conventional Commits prefix**
   - `feat:` — new feature
   - `fix:` — bug fix
   - `refactor:` — code change that does not alter behaviour
   - `docs:` — documentation-only change
   - `test:` — adding or updating tests
   - `chore:` — tooling, dependencies, other maintenance

3. **Bilingual format (English / Japanese)**
   - Subject: English title on line 1, Japanese title on line 2 (no Conventional Commits prefix on the Japanese line)
   - Body: English bullets first, then a `----` separator, then Japanese bullets

### Template

```
feat: add user profile page
ユーザープロフィールページを追加

- Add ProfileController with show/edit actions
- Create profile Blade views with avatar upload

----

- ProfileControllerにshow/editアクションを追加
- アバターアップロード付きプロフィールBladeビューを作成
```

## File editing policy

- `install.php` must run on PHP 8.3 with only stdlib extensions (the same set listed in `DIXLASE_REQUIRED_EXTENSIONS`).
- Helper shell scripts (e.g. `convert-comments.sh`) are POSIX-compliant `bash` so they run on both macOS and Linux.
- For commands that differ between BSD and GNU (e.g. `sed -i`), pick a form that works in both environments (e.g. `sed -i.bak ... && rm *.bak`).
- Hardcoded paths and machine-specific values are forbidden (this is a public installer).

## PHP style

- Always use braces for control structures, even on single-line bodies.
- Always declare explicit return types on methods and functions.
- Prefer PHPDoc blocks over inline comments for non-trivial helpers.
- Insert the proprietary license header (SPDX-style, `LicenseRef-Proprietary`) at the top of every PHP source file inside a PHPDoc block. The full license text lives in `LICENSE`.

## Language of in-source comments and strings

- **All comments and user-facing strings (`info()` / `warn()` / `error()` / `step()` / help text / banners / prompts) in source code must be written in English** (the default locale). Do not write Japanese comments or Japanese UI strings directly.
- Exceptions where Japanese is required:
  - `*.ja.md` documents such as `README.ja.md` / `CLAUDE.ja.md`
  - Translation dictionaries under `lang/ja/` (this directory is the canonical Japanese source)
  - Bilingual commit messages (see the convention above)
- When you find Japanese comments or Japanese UI strings while editing an existing file:
  1. Replace them with English
  2. Append the English-to-Japanese pair to `lang/ja/<source-path>.tsv`
  3. Append the identity (English-to-English) to `lang/en/<source-path>.tsv` (regenerable via `./convert-comments.sh`)

## Translation library (`lang/{en,ja}/`)

Each source file (currently `install.php`, future scripts) has a corresponding translation dictionary at `lang/<locale>/<source-path>.tsv`.

### Format

Tab-separated, one pair per line:

```
<english-text>	<locale-text>
```

- One source-side text and one target-locale text per line, separated by `\t`
- `lang/en/<file>.tsv` is identity (col1 == col2). `lang/ja/<file>.tsv` is the English-to-Japanese mapping.
- Empty lines and lines with too few columns are ignored.
- Comment lines (starting with `#`) are ordinary entries. Do not put meta comments inside dictionary files.

### Key construction

- **Inline comments** (`// Text` form): use `// Text` as the key, without leading indentation (sed's partial match preserves the line's indentation).
- **PHPDoc lines** (` * Text` form): use ` * Text` as the key, including the leading ` * `.
- **String literals** (`'…'` / `"…"`): use the text inside the quotes verbatim as the key, including surrounding whitespace, so display formatting is preserved.
- **Substitution risk**: prefer keys long enough to be unique. If a fragment like `'     The '` would false-match elsewhere on reverse, fold it together with surrounding code (e.g. `'     The ' . bold('Installation Wizard') . ' will guide you through:'`) into a single key.

### Bulk conversion script

`./convert-comments.sh` is a bash script that reads the dictionaries and rewrites source files:

```bash
./convert-comments.sh ja                # all files: en -> ja
./convert-comments.sh ja install.php    # single file: en -> ja
./convert-comments.sh ja --reverse      # all files: ja -> en (restore)
```

Always validate with a round-trip (`ja` then `ja --reverse`) and `php -l install.php` after editing the dictionaries.

## Response style

- Keep explanations short — focus on what matters, not the obvious.
