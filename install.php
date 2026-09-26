#!/usr/bin/env php
<?php

/**
 * Dixlase - Quick Install Script
 *
 * Pipe-friendly installer. Two delivery paths:
 *   - composer create-project (preferred when Composer is on PATH)
 *   - GitHub Releases ZIP fallback (when Composer is missing)
 *
 * Usage:
 *   curl -sS https://install.dixlase.net | php
 *   curl -sS https://install.dixlase.net | php -- --dir=/var/www/dixlase
 *   curl -sS https://install.dixlase.net | php -- --version=1.0.0
 *   curl -sS https://install.dixlase.net | php -- --method=zip
 *   php install.php                                  # interactive
 *   php install.php --non-interactive --yes          # CI mode
 *   curl -sS https://install.dixlase.net | GITHUB_TOKEN=... php   # private repo
 *
 * SPDX-License-Identifier: MIT
 * Copyright (c) 2026 exc-D inc.
 * Licensed under the MIT License. See LICENSE for the full text.
 * https://exc-d.com
 */

// ---------------------------------------------------------------------------
// Constants
// ---------------------------------------------------------------------------

define('DIXLASE_MIN_PHP', '8.3.0');
// Node floor for the Vite asset build, used when core's package.json declares
// no engines.node. Vite 8 supports 20.19+ within 20.x and 22.12+ from there on
// (21.x and 22.0-22.11 are out); core builds on Node 24.
define('DIXLASE_NODE_REQUIREMENT', '^20.19 || >=22.12');
define('DIXLASE_REPO', 'Dixlase/dixlase-core');
define('DIXLASE_PACKAGE', 'dixlase/dixlase-core');
define('DIXLASE_INSTALLER_REPO', 'Dixlase/dixlase-oneliner-installer');
// URLs are overridable via env vars so tests, mirrors, and air-gapped installs
// can point the script at a local server without touching the source.
define('DIXLASE_API_LATEST', getenv('DIXLASE_API_LATEST_URL') ?: 'https://api.github.com/repos/' . DIXLASE_REPO . '/releases/latest');
define('DIXLASE_RELEASE_URL', getenv('DIXLASE_RELEASE_URL_BASE') ?: 'https://github.com/' . DIXLASE_REPO . '/releases/download');
define('DIXLASE_CHECKSUM_FILE', 'checksums.sha256');

define('DIXLASE_REQUIRED_EXTENSIONS', [
    'openssl',
    'pdo',
    'mbstring',
    'tokenizer',
    'xml',
    'ctype',
    'json',
    'bcmath',
    'curl',
    'fileinfo',
    'gd',
]);

// ---------------------------------------------------------------------------
// Standard stream fallbacks
// ---------------------------------------------------------------------------

/**
 * Make sure STDIN / STDOUT / STDERR exist before anything writes to them.
 *
 * PHP only defines these constants when the CLI SAPI runs a script file. With
 * the pipe form this installer is built around (`curl -sS … | php`), PHP 8.2
 * and older leave them undefined, so the first fwrite() died with "Undefined
 * constant STDOUT" inside banner() — on exactly the versions that should be
 * getting the "requires PHP 8.3" message instead. PHP 8.3+ defines them even
 * for piped input, so these fallbacks are inert there.
 */
foreach ([['STDIN', 'php://stdin', 'rb'], ['STDOUT', 'php://stdout', 'wb'], ['STDERR', 'php://stderr', 'wb']] as [$name, $uri, $mode]) {
    if (defined($name)) {
        continue;
    }

    $handle = @fopen($uri, $mode);

    if ($handle !== false) {
        define($name, $handle);
    }
}
unset($name, $uri, $mode, $handle);

// ---------------------------------------------------------------------------
// Terminal helpers
// ---------------------------------------------------------------------------

/**
 * Determine whether the terminal supports colour output.
 */
function supports_color(): bool
{
    if (getenv('NO_COLOR') !== false) {
        return false;
    }

    if (getenv('FORCE_COLOR') !== false) {
        return true;
    }

    if (! defined('STDOUT')) {
        return false;
    }

    if (PHP_OS_FAMILY === 'Windows') {
        return (getenv('ANSICON') !== false)
            || (getenv('ConEmuANSI') === 'ON')
            || str_contains((string) getenv('TERM'), 'xterm');
    }

    return function_exists('posix_isatty') && @posix_isatty(STDOUT);
}

/** @var bool */
$colorEnabled = supports_color();

function style(string $text, string $code): string
{
    global $colorEnabled;

    if (! $colorEnabled) {
        return $text;
    }

    return "\033[{$code}m{$text}\033[0m";
}

function bold(string $t): string    { return style($t, '1'); }
function green(string $t): string   { return style($t, '0;32'); }
function yellow(string $t): string  { return style($t, '0;33'); }
function red(string $t): string     { return style($t, '0;31'); }
function cyan(string $t): string    { return style($t, '0;36'); }
function dim(string $t): string     { return style($t, '2'); }

function info(string $msg): void    { fwrite(STDOUT, green('  ✓ ') . $msg . PHP_EOL); }
function warn(string $msg): void    { fwrite(STDERR, yellow('  ⚠ ') . $msg . PHP_EOL); }
function error(string $msg): void   { fwrite(STDERR, red('  ✗ ') . $msg . PHP_EOL); }
function step(string $msg): void    { fwrite(STDOUT, PHP_EOL . bold(cyan('▸ ')) . bold($msg) . PHP_EOL); }

/**
 * Print an error and exit with a non-zero status.
 *
 * @return never
 */
function fatal(string $msg): void
{
    error($msg);
    exit(1);
}

// ---------------------------------------------------------------------------
// Interactive prompts
// ---------------------------------------------------------------------------

/**
 * Detect whether STDIN is attached to a terminal (so prompts make sense).
 */
function stdin_is_tty(): bool
{
    if (! defined('STDIN')) {
        return false;
    }

    if (function_exists('stream_isatty')) {
        return @stream_isatty(STDIN);
    }

    if (function_exists('posix_isatty')) {
        return @posix_isatty(STDIN);
    }

    return false;
}

/**
 * Read a line from STDIN, returning the default if empty.
 */
function ask(string $question, string $default = ''): string
{
    $hint   = $default !== '' ? ' [' . dim($default) . ']' : '';
    $prompt = '  ' . cyan('? ') . $question . $hint . ': ';

    fwrite(STDOUT, $prompt);

    $line = fgets(STDIN);

    if ($line === false) {
        return $default;
    }

    $line = trim($line);

    return $line === '' ? $default : $line;
}

/**
 * Yes/no prompt. $default is the value used on empty input or when not interactive.
 */
function confirm(string $question, bool $default = true, bool $assumeYes = false): bool
{
    if ($assumeYes) {
        return true;
    }

    $hint = $default ? 'Y/n' : 'y/N';
    fwrite(STDOUT, '  ' . cyan('? ') . $question . ' [' . dim($hint) . ']: ');

    $line = fgets(STDIN);

    if ($line === false) {
        return $default;
    }

    $line = strtolower(trim($line));

    if ($line === '') {
        return $default;
    }

    return $line === 'y' || $line === 'yes';
}

// ---------------------------------------------------------------------------
// Banner
// ---------------------------------------------------------------------------

function banner(): void
{
    $lines = [
        '',
        bold(cyan('  ╔══════════════════════════════════════════╗')),
        bold(cyan('  ║')) . bold('            Dixlase Installer             ') . bold(cyan('║')),
        bold(cyan('  ╚══════════════════════════════════════════╝')),
        '',
    ];

    fwrite(STDOUT, implode(PHP_EOL, $lines) . PHP_EOL);
}

// ---------------------------------------------------------------------------
// Argument parsing
// ---------------------------------------------------------------------------

function parse_args(array $argv): array
{
    $options = [
        'dir'             => null,    // resolved later (cwd or prompted)
        'dir_specified'   => false,
        'version'         => null,    // null = latest
        'method'          => 'auto',  // auto|composer|zip
        'no_composer'     => false,   // applies to zip path
        'no_build'        => false,   // skip npm ci + npm run build
        'non_interactive' => false,
        'assume_yes'      => false,
        'force_reinstall' => false,   // allow installing over an existing site
        'help'            => false,
    ];

    foreach ($argv as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            $options['help'] = true;
        } elseif ($arg === '--no-composer') {
            $options['no_composer'] = true;
        } elseif ($arg === '--no-build') {
            $options['no_build'] = true;
        } elseif ($arg === '--non-interactive') {
            $options['non_interactive'] = true;
        } elseif ($arg === '--yes' || $arg === '-y') {
            $options['assume_yes'] = true;
        } elseif ($arg === '--force-reinstall') {
            $options['force_reinstall'] = true;
        } elseif (str_starts_with($arg, '--dir=')) {
            $options['dir']           = substr($arg, 6);
            $options['dir_specified'] = true;
        } elseif (str_starts_with($arg, '--version=')) {
            $options['version'] = ltrim(substr($arg, 10), 'v');
        } elseif (str_starts_with($arg, '--method=')) {
            $value = strtolower(substr($arg, 9));
            if (! in_array($value, ['auto', 'composer', 'zip'], true)) {
                fatal("Invalid --method value: {$value} (expected auto, composer, or zip)");
            }
            $options['method'] = $value;
        }
    }

    return $options;
}

/**
 * Resolve a (possibly empty / relative) path to an absolute path with no trailing slash.
 */
function resolve_path(string $path): string
{
    if ($path === '') {
        $path = getcwd();
    }

    if ($path[0] !== '/' && (PHP_OS_FAMILY !== 'Windows' || ! preg_match('/^[A-Za-z]:/', $path))) {
        $path = getcwd() . '/' . $path;
    }

    return rtrim($path, '/');
}

/**
 * Pick the default installation directory when --dir is not given.
 *
 * Always carve a "<cwd>/dixlase" subdirectory. Predictable in every
 * shell context (HOME, an existing project folder, a fresh mkdir),
 * mirrors the convention used by scaffolders like "laravel new" and
 * "create-react-app", and never scatters the Laravel skeleton into
 * a directory the operator did not create for it. Callers who do
 * want an in-place install pass --dir=. explicitly.
 */
function default_install_dir(): string
{
    return rtrim(getcwd() ?: '.', '/') . '/dixlase';
}

/**
 * True when $dir holds no entries beyond a small allow-list of
 * harmless metadata files (macOS .DS_Store, Git's bookkeeping, an
 * empty-dir placeholder).
 */
function is_dir_effectively_empty(string $dir): bool
{
    $ignore  = ['.', '..', '.DS_Store', '.gitkeep', '.git', '.localized'];
    $entries = @scandir($dir);

    if ($entries === false) {
        return true;
    }

    foreach ($entries as $entry) {
        if (! in_array($entry, $ignore, true)) {
            return false;
        }
    }

    return true;
}

/**
 * List the files in $dir that show an application is already installed there.
 *
 * Installing over them would replace a live site's code and, before this
 * guard existed, its APP_KEY. An empty result means the directory is safe
 * to install into.
 *
 * @return list<string>
 */
function existing_install_markers(string $dir): array
{
    $markers = [];

    foreach (['.env', 'artisan', 'bootstrap/app.php', 'vendor'] as $path) {
        if (file_exists($dir . '/' . $path)) {
            $markers[] = $path;
        }
    }

    return $markers;
}

/**
 * Create a private work directory for downloads and extraction.
 *
 * The name is random and mkdir() fails if it already exists, so another
 * local user cannot pre-create it in a shared temp directory; mode 0700
 * keeps them from reading or swapping files inside it.
 */
function create_work_dir(): string
{
    $base = rtrim(sys_get_temp_dir(), '/');

    for ($i = 0; $i < 5; $i++) {
        $path = $base . '/dixlase-install-' . bin2hex(random_bytes(8));

        if (@mkdir($path, 0700)) {
            // mkdir() applies the umask; make sure the mode is exactly 0700.
            chmod($path, 0700);
            return $path;
        }
    }

    fatal("Cannot create a private work directory under {$base}");
}

function show_help(): void
{
    fwrite(STDOUT, <<<'HELP'

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

Environment:
  GITHUB_TOKEN        GitHub token for installing from a private Dixlase repo

HELP
    );
}

// ---------------------------------------------------------------------------
// Environment checks
// ---------------------------------------------------------------------------

function check_php_version(): void
{
    step('Checking PHP version');

    $current = PHP_VERSION;

    if (version_compare($current, DIXLASE_MIN_PHP, '<')) {
        fatal("PHP {$current} detected. Dixlase requires PHP " . DIXLASE_MIN_PHP . ' or higher.');
    }

    info("PHP {$current}");
}

function check_extensions(): void
{
    step('Checking PHP extensions');

    $missing = [];

    foreach (DIXLASE_REQUIRED_EXTENSIONS as $ext) {
        if (! extension_loaded($ext)) {
            $missing[] = $ext;
        }
    }

    if (count($missing) > 0) {
        error('Missing PHP extensions: ' . implode(', ', $missing));
        fwrite(STDERR, PHP_EOL);
        fwrite(STDERR, '  Install them and try again. For example (Debian/Ubuntu): ' . PHP_EOL);
        fwrite(STDERR, '    sudo apt-get install ' . implode(' ', array_map(
            fn ($e) => "php-{$e}",
            $missing
        )) . PHP_EOL);
        exit(1);
    }

    info('All required extensions are installed (' . count(DIXLASE_REQUIRED_EXTENSIONS) . ' checked)');
}

function check_composer(): bool
{
    step('Checking Composer');

    // Try `composer` in PATH
    $result = exec('composer --version 2>/dev/null', $output, $code);

    if ($code === 0 && $result !== '') {
        info(trim($result));
        return true;
    }

    // Try local composer.phar
    if (file_exists('composer.phar')) {
        info('Found local composer.phar');
        return true;
    }

    return false;
}

/**
 * Resolve the Composer binary path.
 */
function composer_bin(): string
{
    exec('command -v composer 2>/dev/null', $output, $code);

    if ($code === 0 && ! empty($output[0])) {
        return 'composer';
    }

    if (file_exists('composer.phar')) {
        return PHP_BINARY . ' composer.phar';
    }

    return 'composer';
}

// ---------------------------------------------------------------------------
// GitHub authentication
// ---------------------------------------------------------------------------

/**
 * Read the GitHub token from the environment (used for private-repo installs).
 * DIXLASE_GITHUB_TOKEN takes precedence over the conventional GITHUB_TOKEN.
 */
function github_token(): string
{
    return trim((string) (getenv('DIXLASE_GITHUB_TOKEN') ?: getenv('GITHUB_TOKEN') ?: ''));
}

/**
 * Decide whether the token may be sent to a given host. The token is attached
 * only to GitHub hosts, or to a host explicitly configured through the URL
 * override env vars, so it can never leak to an unrelated mirror.
 */
function token_allowed_for_host(string $host): bool
{
    if ($host === '') {
        return false;
    }

    if ($host === 'github.com'
        || $host === 'api.github.com'
        || str_ends_with($host, '.githubusercontent.com')) {
        return true;
    }

    foreach (['DIXLASE_API_LATEST_URL', 'DIXLASE_RELEASE_URL_BASE'] as $var) {
        $override = getenv($var);

        if ($override !== false && parse_url((string) $override, PHP_URL_HOST) === $host) {
            return true;
        }
    }

    return false;
}

/**
 * Build the HTTP request header block for a request to $url. An Authorization
 * header is appended when a token is set and the host is allowed to receive it.
 */
function http_request_headers(string $url): string
{
    $headers = "User-Agent: DixlaseInstaller/1.0\r\n";

    $token = github_token();
    $host  = (string) (parse_url($url, PHP_URL_HOST) ?: '');

    if ($token !== '' && token_allowed_for_host($host)) {
        $headers .= "Authorization: Bearer {$token}\r\n";
    }

    return $headers;
}

// ---------------------------------------------------------------------------
// Download helpers
// ---------------------------------------------------------------------------

/**
 * Fetch the latest release version from the GitHub API.
 *
 * Returns the version string without the leading "v", or null when no
 * release exists / the API call fails. The null path lets the caller fall
 * back to the composer create-project method under --method=auto.
 */
function fetch_latest_version(): ?string
{
    step('Fetching latest release information');

    $ctx = stream_context_create([
        'http' => [
            'header'  => http_request_headers(DIXLASE_API_LATEST),
            'timeout' => 30,
        ],
    ]);

    $json = @file_get_contents(DIXLASE_API_LATEST, false, $ctx);

    if ($json === false) {
        warn('Could not fetch release information from GitHub.');
        return null;
    }

    $data = json_decode($json, true);

    if (! is_array($data) || ! isset($data['tag_name'])) {
        warn('Unexpected API response from GitHub.');
        return null;
    }

    $version = ltrim((string) $data['tag_name'], 'v');
    info("Latest version: {$version}");

    return $version;
}

// ---------------------------------------------------------------------------
// GitHub Release API (private-repo-safe asset download)
// ---------------------------------------------------------------------------

/**
 * Whether ZIP downloads should be routed through the GitHub REST API rather
 * than the public browser_download_url. Requires a token AND the configured
 * release URL to point at github.com (so the bats mock with 127.0.0.1 keeps
 * using its static browser-style files).
 */
function use_github_api_for_releases(): bool
{
    if (github_token() === '') {
        return false;
    }

    return parse_url(DIXLASE_RELEASE_URL, PHP_URL_HOST) === 'github.com';
}

/**
 * Fetch a release object (including its assets array) by tag via the API.
 * Returns null on any HTTP / parse error so the caller can fall back.
 */
function fetch_release_by_tag(string $tag): ?array
{
    $url = 'https://api.github.com/repos/' . DIXLASE_REPO . "/releases/tags/{$tag}";

    $ctx = stream_context_create([
        'http' => [
            'header'  => http_request_headers($url),
            'timeout' => 30,
        ],
    ]);

    $json = @file_get_contents($url, false, $ctx);

    if ($json === false) {
        return null;
    }

    $data = json_decode($json, true);

    if (! is_array($data) || ! isset($data['assets'])) {
        return null;
    }

    return $data;
}

/**
 * Find an asset by exact filename in a release object.
 */
function find_release_asset(array $release, string $name): ?array
{
    foreach ($release['assets'] ?? [] as $asset) {
        if (isset($asset['name']) && $asset['name'] === $name) {
            return $asset;
        }
    }

    return null;
}

/**
 * Download a release asset via the GitHub REST API.
 *
 * The asset API URL (api.github.com/.../releases/assets/<id>) answers a 302
 * to a signed objects.githubusercontent.com URL. The signed URL must NOT
 * receive the Authorization header (S3 returns "InvalidArgument: only one
 * auth mechanism allowed"). curl's CURLOPT_UNRESTRICTED_AUTH=false strips
 * the Authorization on cross-host redirects, which is exactly what we need.
 *
 * Uses ext-curl (already in DIXLASE_REQUIRED_EXTENSIONS).
 */
function download_asset_via_api(string $apiUrl, string $dest): bool
{
    $token = github_token();

    if ($token === '') {
        return false;
    }

    $fh = @fopen($dest, 'wb');

    if ($fh === false) {
        return false;
    }

    $ch = curl_init();

    curl_setopt_array($ch, [
        CURLOPT_URL              => $apiUrl,
        CURLOPT_HTTPHEADER       => [
            'User-Agent: DixlaseInstaller/1.0',
            'Accept: application/octet-stream',
            'Authorization: Bearer ' . $token,
        ],
        CURLOPT_FOLLOWLOCATION   => true,
        CURLOPT_UNRESTRICTED_AUTH => false,
        CURLOPT_FILE             => $fh,
        CURLOPT_TIMEOUT          => 600,
        CURLOPT_FAILONERROR      => true,
        CURLOPT_NOPROGRESS       => false,
        CURLOPT_PROGRESSFUNCTION => function ($_, $total, $downloaded) {
            static $lastMb = 0.0;
            if ($downloaded <= 0) {
                return 0;
            }
            $mb = round($downloaded / 1048576, 1);
            if ($mb - $lastMb >= 0.5 || ($total > 0 && $downloaded >= $total)) {
                fwrite(STDOUT, "\r" . dim("    Downloaded: {$mb} MB"));
                $lastMb = $mb;
            }
            return 0;
        },
    ]);

    $ok    = curl_exec($ch);
    $errno = curl_errno($ch);
    $err   = $errno !== 0 ? curl_error($ch) : '';

    // curl_close() is a no-op on PHP 8.0+ and deprecated in 8.5 — handle via unset.
    unset($ch);
    fclose($fh);
    fwrite(STDOUT, PHP_EOL);

    if ($ok === false) {
        warn('curl error (' . $errno . '): ' . $err);
        @unlink($dest);
        return false;
    }

    return (@filesize($dest) ?: 0) > 0;
}

/**
 * Download a file with a progress indicator.
 *
 * @return bool True on success.
 */
function download_file(string $url, string $dest): bool
{
    $ctx = stream_context_create([
        'http' => [
            'header'  => http_request_headers($url),
            'timeout' => 300,
        ],
    ]);

    $source = @fopen($url, 'r', false, $ctx);

    if ($source === false) {
        return false;
    }

    $target = fopen($dest, 'w');

    if ($target === false) {
        fclose($source);
        return false;
    }

    $downloaded = 0;
    $lastPrint  = 0;

    while (! feof($source)) {
        $chunk = fread($source, 8192);

        if ($chunk === false) {
            break;
        }

        fwrite($target, $chunk);
        $downloaded += strlen($chunk);

        $mb = round($downloaded / 1048576, 1);
        if ($mb - $lastPrint >= 0.5 || feof($source)) {
            fwrite(STDOUT, "\r" . dim("    Downloaded: {$mb} MB"));
            $lastPrint = $mb;
        }
    }

    fwrite(STDOUT, PHP_EOL);
    fclose($source);
    fclose($target);

    return $downloaded > 0;
}

/**
 * Compare a file's SHA-256 to the entries in a checksums.sha256 payload.
 * Returns false ONLY on a real hash mismatch — missing-file-in-list and
 * malformed-payload cases are treated as "skip with warn" by the caller.
 */
function verify_checksum_data(string $file, string $checksumData): bool
{
    $basename   = basename($file);
    $actualHash = hash_file('sha256', $file);

    foreach (explode("\n", trim($checksumData)) as $line) {
        $parts = preg_split('/\s+/', trim($line), 2);

        if (count($parts) === 2 && $parts[1] === $basename) {
            if (hash_equals($parts[0], $actualHash)) {
                info('Checksum verified (SHA-256)');
                return true;
            }

            error('Checksum mismatch!');
            error("  Expected: {$parts[0]}");
            error("  Got:      {$actualHash}");
            return false;
        }
    }

    warn('File not listed in checksum file — skipping verification.');
    return true;
}

/**
 * Verify the SHA-256 checksum of a downloaded file.
 *
 * Two paths: when GitHub API mode is active (token set + release URL is
 * github.com), the checksums.sha256 asset is fetched via the API endpoint
 * (private-repo-safe). Otherwise the existing browser_download_url path is
 * used (works for public repos and the bats mock).
 *
 * A missing checksums.sha256 (typical for early releases) skips verification
 * with a warn — same behaviour as before.
 */
function verify_checksum(string $file, string $version, string $workDir): bool
{
    if (use_github_api_for_releases()) {
        $release = fetch_release_by_tag("v{$version}");

        if ($release === null) {
            warn('Could not fetch release info for checksum — skipping verification.');
            return true;
        }

        $asset = find_release_asset($release, DIXLASE_CHECKSUM_FILE);

        if ($asset === null) {
            warn('Checksum file not in release assets — skipping verification.');
            return true;
        }

        $tmpSum = $workDir . '/' . DIXLASE_CHECKSUM_FILE;

        if (! download_asset_via_api($asset['url'], $tmpSum)) {
            @unlink($tmpSum);
            warn('Failed to download checksum file — skipping verification.');
            return true;
        }

        $checksumData = @file_get_contents($tmpSum);
        @unlink($tmpSum);

        if ($checksumData === false) {
            warn('Could not read checksum file — skipping verification.');
            return true;
        }

        return verify_checksum_data($file, $checksumData);
    }

    $checksumUrl = DIXLASE_RELEASE_URL . "/v{$version}/" . DIXLASE_CHECKSUM_FILE;

    $ctx = stream_context_create([
        'http' => [
            'header'  => http_request_headers($checksumUrl),
            'timeout' => 30,
        ],
    ]);

    $checksumData = @file_get_contents($checksumUrl, false, $ctx);

    if ($checksumData === false) {
        warn('Checksum file not available — skipping verification.');
        return true;
    }

    return verify_checksum_data($file, $checksumData);
}

// ---------------------------------------------------------------------------
// Composer output filtering
// ---------------------------------------------------------------------------

/**
 * Decide whether a Composer subprocess line should be surfaced to the user.
 * Lines matching the noise list are dropped from STDOUT but still recorded in
 * the install log file, so failures can be diagnosed afterwards.
 *
 * The patterns capture three families of noise:
 *   - generic PHP / Composer chatter (deprecation notices, per-package
 *     install/download lines, progress bars, funding nag, abandoned-package
 *     reminders)
 *   - known upstream warnings in dixlase-core that are recovered automatically
 *     (case-collision unzip fallback, PSR-4 mismatch warning, deprecated PDO
 *     MYSQL constant)
 *   - composer.local.json sync notice (informational only)
 */
function should_show_composer_line(string $line): bool
{
    static $noise = [
        '/^\s*(Deprecation Notice|Deprecated): /',
        '/^\s*-\s+(Installing|Downloading)\s+/',
        '/\d+\/\d+\s+\[.+\]\s+\d+%/',
        '/Failed to extract dixlase\/dixlase-core/',
        '/write error \(disk full/',
        '/cannot set modif\.\/access times/',
        '/^\s+No such file or directory\s*$/',
        '/is probably truncated/',
        '/identical file names with different capitalization/',
        '/Unzip with unzip command failed, falling back to ZipArchive class/',
        '/Package .* is abandoned/',
        '/packages you are using are looking for funding/',
        '/Use the `composer fund` command/',
        '/does not comply with psr-4 autoloading standard/',
        '/composer\.local\.json synced/',
        '/Constant PDO::MYSQL_ATTR_SSL_CA is deprecated/',
        // SQLSTATE 2002 + `getaddrinfo for mysql failed` from the post-create
        // -project `migrate --graceful`: the install wizard sets DB credentials
        // on first browser visit, so the placeholder .env's DB_HOST=mysql is
        // expected to fail here. Laravel's --ansi renderer can hard-wrap the
        // long single-line message into several fgets() lines, so each likely
        // wrapped fragment gets its own pattern below.
        '/SQLSTATE\[HY000\]/',
        '/getaddrinfo for mysql failed/',
        '/Connection: mysql, Host: mysql/',
    ];

    foreach ($noise as $pattern) {
        if (preg_match($pattern, $line)) {
            return false;
        }
    }

    return true;
}

/**
 * Stream a subprocess to STDOUT, filtering noise via the given callable, and
 * capture the full unfiltered output to a temporary log file so failures can
 * be debugged afterwards.
 *
 * $shouldShow is the per-line filter (e.g. should_show_composer_line(...) or
 * should_show_npm_line(...)). Returns the subprocess exit code; $logFile is
 * set to the path of the log for the caller to display on failure (it lives
 * under sys_get_temp_dir() with mode 0600 thanks to tempnam()).
 */
function run_filtered_tool(string $command, callable $shouldShow, ?string &$logFile = null): int
{
    $logFile   = tempnam(sys_get_temp_dir(), 'dixlase-install-');
    $logHandle = $logFile !== false ? @fopen($logFile, 'w') : false;

    $proc = popen($command, 'r');

    if ($proc === false) {
        if ($logHandle !== false) {
            fclose($logHandle);
        }
        return 127;
    }

    while (! feof($proc)) {
        $line = fgets($proc);

        if ($line === false) {
            break;
        }

        if ($logHandle !== false) {
            fwrite($logHandle, $line);
        }

        if ($shouldShow($line)) {
            $trimmed = rtrim($line);

            if ($trimmed !== '') {
                fwrite(STDOUT, dim('    ' . $trimmed) . PHP_EOL);
            }
        }
    }

    if ($logHandle !== false) {
        fclose($logHandle);
    }

    return pclose($proc);
}

// ---------------------------------------------------------------------------
// Installation steps
// ---------------------------------------------------------------------------

/**
 * Download and extract a Dixlase release ZIP into $dir.
 *
 * Returns true on success. Returns false (instead of fatal()) when the ZIP
 * download or extraction fails, so the caller (main()) can fall back to the
 * composer create-project path when --method=auto is active.
 *
 * Chooses between two delivery paths transparently:
 *   - GitHub API asset endpoint (when token + github.com host — private-repo
 *     safe; uses curl with auth-strip on the S3 redirect)
 *   - public browser_download_url (otherwise — works for public repos and
 *     for the bats mock under 127.0.0.1)
 */
function download_dixlase(string $dir, string $version): bool
{
    $workDir = create_work_dir();

    try {
        return download_dixlase_into($dir, $version, $workDir);
    } finally {
        remove_directory($workDir);
    }
}

/**
 * Download, verify and extract the release inside $workDir, then move the
 * files into $dir. Everything under $workDir is removed by the caller.
 */
function download_dixlase_into(string $dir, string $version, string $workDir): bool
{
    step("Downloading Dixlase v{$version}");

    $zipName = "dixlase-v{$version}.zip";
    $tmpZip  = $workDir . "/{$zipName}";

    if (use_github_api_for_releases()) {
        $release = fetch_release_by_tag("v{$version}");

        if ($release === null) {
            error("Release v{$version} from the GitHub API.");
            return false;
        }

        $asset = find_release_asset($release, $zipName);

        if ($asset === null) {
            error("Release v{$version} does not have an asset named '{$zipName}' attached.");
            return false;
        }

        if (! download_asset_via_api($asset['url'], $tmpZip)) {
            error("Failed to download {$zipName} via the GitHub API.");
            return false;
        }
    } else {
        $url = DIXLASE_RELEASE_URL . "/v{$version}/{$zipName}";

        if (! download_file($url, $tmpZip)) {
            error("Failed to download {$url}");
            return false;
        }
    }

    if (! verify_checksum($tmpZip, $version, $workDir)) {
        error('Download verification failed. The file may have been tampered with.');
        return false;
    }

    step('Extracting files');

    $extractDir = $workDir . '/extract';

    if (! @mkdir($extractDir, 0700)) {
        error('Failed to create the extraction directory.');
        return false;
    }

    if (! class_exists('ZipArchive')) {
        // Fallback to unzip command
        $escaped = escapeshellarg($tmpZip);
        $escDir  = escapeshellarg($extractDir);
        exec("unzip -o -q {$escaped} -d {$escDir} 2>&1", $output, $code);

        if ($code !== 0) {
            error('Failed to extract ZIP archive. Install the php-zip extension or the unzip command.');
            return false;
        }
    } else {
        $zip = new ZipArchive();

        if ($zip->open($tmpZip) !== true) {
            error('Failed to open ZIP archive.');
            return false;
        }

        $extracted = $zip->extractTo($extractDir);
        $zip->close();

        if (! $extracted) {
            error('Could not extract the ZIP archive.');
            return false;
        }
    }

    // Release archives wrap everything in one top-level directory
    // (dixlase-vX.Y.Z/). Strip it when present; otherwise move the
    // archive root as is. Both extraction paths share this step.
    $sourceDir = $extractDir;
    $entries   = array_values(array_diff(scandir($extractDir) ?: [], ['.', '..', '__MACOSX']));

    if (count($entries) === 1 && is_dir($extractDir . '/' . $entries[0])) {
        $sourceDir = $extractDir . '/' . $entries[0];
    }

    if (! is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourceDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        $target = $dir . '/' . $iterator->getSubPathname();

        if ($item->isDir()) {
            if (! is_dir($target)) {
                mkdir($target, 0755, true);
            }
        } else {
            $targetDir = dirname($target);

            if (! is_dir($targetDir)) {
                mkdir($targetDir, 0755, true);
            }

            if (! rename($item->getPathname(), $target)) {
                error('Failed to move files into ' . $dir . ': ' . $iterator->getSubPathname());
                return false;
            }
        }
    }

    info('Files extracted to ' . $dir);
    return true;
}

/**
 * Recursively remove a directory.
 */
function remove_directory(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($items as $item) {
        if ($item->isDir()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }

    @rmdir($dir);
}

/**
 * Install Dixlase via "composer create-project". Streams Composer's output live.
 * Returns true on success; false lets the caller decide whether to fall back.
 */
function composer_create_project(string $dir, ?string $version): bool
{
    step('Installing Dixlase via composer create-project');

    $bin    = composer_bin();
    $escDir = escapeshellarg($dir);
    $pkg    = DIXLASE_PACKAGE;
    $spec   = $version !== null ? "{$pkg}:^{$version}" : $pkg;

    $command = "{$bin} create-project " . escapeshellarg($spec) . " {$escDir}"
        . ' --prefer-dist --no-interaction --no-progress --remove-vcs';

    $token = github_token();

    if ($token !== '') {
        // Private repo: resolve the package straight from its Git VCS rather
        // than Packagist, and hand Composer the token via COMPOSER_AUTH (passed
        // through the environment so it never appears in the process list).
        $repo = json_encode([
            'type' => 'vcs',
            'url'  => 'https://github.com/' . DIXLASE_REPO,
        ]);
        $command .= ' --repository=' . escapeshellarg((string) $repo);

        // Allow dev versions for private installs (untagged branches). With
        // prefer-stable still in effect on the project, tagged releases win
        // automatically once they exist, so this stays safe long-term.
        $command .= ' --stability=dev';

        putenv('COMPOSER_AUTH=' . json_encode([
            'github-oauth' => ['github.com' => $token],
        ]));
    }

    $command .= ' 2>&1';

    $logFile  = null;
    $exitCode = run_filtered_tool($command, should_show_composer_line(...), $logFile);

    if ($exitCode === 127) {
        error('Failed to launch Composer.');
        return false;
    }

    if ($exitCode !== 0) {
        error('composer create-project failed (exit code ' . $exitCode . ').');
        if ($logFile !== null) {
            warn('Full log saved to ' . $logFile);
        }
        if (github_token() === '') {
            warn('No GITHUB_TOKEN detected — needed if installing from a private repository.');
        }
        return false;
    }

    if ($logFile !== null) {
        @unlink($logFile);
    }

    info('Dixlase installed via Composer');
    return true;
}

function run_composer(string $dir): void
{
    step('Installing dependencies via Composer');

    $bin     = composer_bin();
    $escDir  = escapeshellarg($dir);
    $command = "{$bin} install --no-dev --optimize-autoloader --no-interaction --no-progress --working-dir={$escDir} 2>&1";

    $logFile  = null;
    $exitCode = run_filtered_tool($command, should_show_composer_line(...), $logFile);

    if ($exitCode === 127) {
        fatal('Failed to run Composer. Please run it manually: composer install --no-dev --optimize-autoloader');
    }

    if ($exitCode !== 0) {
        if ($logFile !== null) {
            warn('Full log saved to ' . $logFile);
        }
        fatal('Composer install failed (exit code ' . $exitCode . '). Check the log above for errors.');
    }

    if ($logFile !== null) {
        @unlink($logFile);
    }

    info('Dependencies installed');
}

// ---------------------------------------------------------------------------
// Frontend asset build (npm install / npm ci + npm run build via Vite)
// ---------------------------------------------------------------------------

/**
 * Decide whether an npm subprocess line should be surfaced to the user.
 * Mirrors should_show_composer_line(): the noise list drops deprecation
 * notices, funding nags, and audit reminders while keeping useful lines such
 * as `added N packages in Ts` and Vite's `✓ built in Ts`.
 */
function should_show_npm_line(string $line): bool
{
    static $noise = [
        // Drop deprecation chatter ONLY — preserve EBADENGINE, ETIMEDOUT,
        // EACCES, and other actionable `npm warn` codes so the user can
        // diagnose real failures from the visible output.
        '/^\s*npm\s+(warn|WARN)\s+deprecated/i',
        '/^\s*npm\s+notice/',                       // npm self-update notices etc.
        '/packages? are looking for funding/',
        '/run `npm fund` for details/',
        '/found \d+ vulnerabilit(y|ies)/',
        '/run `npm audit` for details/',
        '/^\s*transforming \(\d+\)/',               // vite progress dots
        '/^\s*\d+ modules transformed\.\s*$/',      // vite mid-build status
    ];

    foreach ($noise as $pattern) {
        if (preg_match($pattern, $line)) {
            return false;
        }
    }

    return true;
}

/**
 * Detect whether `npm` is callable from the current PATH.
 */
function npm_available(): bool
{
    exec('command -v npm 2>/dev/null', $output, $code);
    return $code === 0 && ! empty($output[0]);
}

/**
 * Load and validate package.json. Returns the parsed array when it looks like
 * a Dixlase frontend manifest (package name starts with "dixlase" OR vite is
 * a declared dependency), null otherwise.
 *
 * The Dixlase-shape check protects against the `--dir=.` case where the user
 * happens to have an unrelated Laravel project's package.json sitting in the
 * target directory — we must not clobber it with our `npm ci`.
 */
function read_dixlase_package_json(string $dir): ?array
{
    $path = $dir . '/package.json';

    if (! is_file($path)) {
        return null;
    }

    $raw = @file_get_contents($path);

    if ($raw === false) {
        return null;
    }

    $pkg = json_decode($raw, true);

    if (! is_array($pkg)) {
        return null;
    }

    $name    = $pkg['name'] ?? '';
    $hasVite = isset($pkg['devDependencies']['vite']) || isset($pkg['dependencies']['vite']);

    if (! $hasVite && ! str_starts_with((string) $name, 'dixlase')) {
        return null;
    }

    return $pkg;
}

/**
 * Evaluate a package.json-style Node range against a major/minor pair.
 *
 * Covers the comparator forms Dixlase and Vite actually publish — the
 * disjunction "^20.19.0 || >=22.12.0", plain ">=22.12", "22.x" — because a
 * major-only reading of that disjunction would admit 21.x and 22.0-22.11,
 * which Vite 8 refuses to build on. Returns null when no alternative parses,
 * so the caller can apply its own floor instead of treating an unreadable
 * constraint as "anything goes".
 */
function node_range_allows(string $range, int $major, int $minor): ?bool
{
    $parsed  = false;
    $allowed = false;

    foreach (explode('||', $range) as $alternative) {
        if (! preg_match('/^\s*(\^|~|>=|>)?\s*v?(\d+)(?:\.(\d+))?/', $alternative, $match)) {
            continue;
        }

        $needMajor = (int) $match[2];

        // Defensive bounds: ignore implausible majors from a hostile or
        // malformed manifest (e.g. >99 or <=0) rather than gate on garbage.
        if ($needMajor < 1 || $needMajor > 99) {
            continue;
        }

        $needMinor = isset($match[3]) && $match[3] !== '' ? (int) $match[3] : 0;
        $operator  = $match[1];

        $parsed = true;

        $allowed = $allowed || match ($operator) {
            '>=' => $major > $needMajor || ($major === $needMajor && $minor >= $needMinor),
            '>'  => $major > $needMajor || ($major === $needMajor && $minor > $needMinor),
            // ^ and ~ both pin the major and require at least the minor; a
            // bare "20" / "20.19" is treated the same way here.
            default => $major === $needMajor && $minor >= $needMinor,
        };
    }

    return $parsed ? $allowed : null;
}

/**
 * Verify the active Node.js version can run the frontend build.
 *
 * Honours package.json's engines.node when the project declares a range this
 * understands, and otherwise applies DIXLASE_NODE_REQUIREMENT (Vite 8's own
 * floor) rather than "no constraint". Returns true when node is missing —
 * the caller surfaces the better error.
 */
function node_version_satisfies(array $pkg): bool
{
    $required = $pkg['engines']['node'] ?? '';

    exec('node -p "process.versions.node" 2>/dev/null', $output, $code);

    if ($code !== 0 || empty($output[0])) {
        return true; // let npm/vite report it; we don't gate on missing node here
    }

    $actual      = explode('.', trim($output[0]));
    $actualMajor = (int) $actual[0];
    $actualMinor = (int) ($actual[1] ?? 0);

    if (is_string($required) && $required !== '') {
        $allowed = node_range_allows($required, $actualMajor, $actualMinor);

        if ($allowed !== null) {
            return $allowed;
        }
    }

    return (bool) node_range_allows(DIXLASE_NODE_REQUIREMENT, $actualMajor, $actualMinor);
}

/**
 * Build the frontend assets (Vite manifest + bundled JS/CSS).
 *
 * Returns one of:
 *   'built'   — assets are ready, no further user action required
 *   'skipped' — nothing to build for this project (no package.json / not a
 *               Vite project / --no-build flag set); silent skip is correct
 *   'failed'  — should have been built but the build did not complete; the
 *               caller's show_complete() will surface a recovery hint
 */
function build_assets(string $dir, bool $skip): string
{
    if ($skip) {
        return 'skipped';
    }

    // Pre-built release ZIPs ship public/assets/build/manifest.json directly.
    // If the manifest is already present, treat the build as done so Node /
    // npm aren't required at all on the install machine.
    if (is_file($dir . '/public/assets/build/manifest.json')) {
        return 'built';
    }

    $pkg = read_dixlase_package_json($dir);

    if ($pkg === null) {
        return 'skipped';
    }

    step('Building frontend assets');

    if (! npm_available()) {
        warn('npm not found — install Node.js 24 (or any >=20.19) and re-run the asset build manually.');
        warn('(If you use nvm/fnm/asdf/volta, source it first or run from an interactive shell.)');
        return 'failed';
    }

    if (! node_version_satisfies($pkg)) {
        $required = $pkg['engines']['node'] ?? DIXLASE_NODE_REQUIREMENT;
        warn('Node version is too old for the asset build (need ' . $required . ') — skipping build.');
        return 'failed';
    }

    // Soft disk-space precheck: a typical Dixlase asset build needs ~500 MB
    // (node_modules + npm cache + build output). Warn but do not abort.
    $free = @disk_free_space($dir);
    if ($free !== false && $free < 500 * 1024 * 1024) {
        $freeMb = (int) ($free / 1024 / 1024);
        warn('Only ' . $freeMb . ' MB free on the install volume — npm install may fail.');
    }

    // SECURITY: clear the GitHub token from the process environment before
    // invoking npm. composer_create_project() set COMPOSER_AUTH via putenv()
    // so a malicious npm postinstall script could otherwise read the token
    // out of process env. Also unset the plain token vars for defence in depth.
    putenv('COMPOSER_AUTH');
    putenv('GITHUB_TOKEN');
    putenv('DIXLASE_GITHUB_TOKEN');

    // Quieten npm and signal non-interactive mode without per-call flags.
    putenv('CI=true');
    putenv('NPM_CONFIG_FUND=false');
    putenv('NPM_CONFIG_AUDIT=false');
    putenv('NPM_CONFIG_UPDATE_NOTIFIER=false');

    $escDir = escapeshellarg($dir);

    // Prefer `npm ci` (lockfile-enforced, reproducible) on a clean install;
    // fall back to `npm install` if no lockfile, OR if node_modules already
    // exists (re-install over an active checkout would let `ci` destructively
    // rmdir a tree that another process may be reading — EBUSY on WSL etc.).
    $hasLock    = is_file($dir . '/package-lock.json');
    $hasModules = is_dir($dir . '/node_modules');
    $subcmd     = ($hasLock && ! $hasModules) ? 'ci' : 'install';

    // When the installer runs as root (typical on shared hosting / VPS provision),
    // route the npm cache inside the project so root doesn't end up owning ~/.npm.
    // Pre-create the cache directory with restrictive mode 0700 and refuse to
    // use any pre-existing entry that is a symlink or non-directory — defends
    // against an unprivileged user pre-seeding $dir/.npm-cache as a symlink to
    // /root/.ssh or /etc to capture root-owned writes.
    $cacheArg = '';
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        $cachePath = $dir . '/.npm-cache';

        if (is_link($cachePath) || (file_exists($cachePath) && ! is_dir($cachePath))) {
            warn('Refusing to use existing ' . $cachePath . ' as npm cache (not a directory).');
        } else {
            if (! is_dir($cachePath)) {
                @mkdir($cachePath, 0700, true);
            }
            $cacheArg = ' --cache=' . escapeshellarg($cachePath);
        }
    }

    $installCmd = "npm {$subcmd} --prefix={$escDir}{$cacheArg} 2>&1";

    $logFile  = null;
    $exitCode = run_filtered_tool($installCmd, should_show_npm_line(...), $logFile);

    if ($exitCode !== 0) {
        if ($logFile !== null) {
            warn('Full log saved to ' . $logFile);
        }
        warn('npm ' . $subcmd . ' failed (exit code ' . $exitCode . ').');
        return 'failed';
    }

    if ($logFile !== null) {
        @unlink($logFile);
    }

    $buildCmd = "npm run build --prefix={$escDir} 2>&1";

    $logFile  = null;
    $exitCode = run_filtered_tool($buildCmd, should_show_npm_line(...), $logFile);

    if ($exitCode !== 0) {
        if ($logFile !== null) {
            warn('Full log saved to ' . $logFile);
        }
        warn('npm run build failed (exit code ' . $exitCode . ').');
        return 'failed';
    }

    if ($logFile !== null) {
        @unlink($logFile);
    }

    info('Frontend assets built');
    return 'built';
}

function setup_environment(string $dir): void
{
    step('Setting up environment');

    $envExample = $dir . '/.env.example';
    $envFile    = $dir . '/.env';

    if (! file_exists($envExample)) {
        fatal('.env.example not found. The download may be incomplete.');
    }

    // Leave an existing .env in place silently. The Dixlase install wizard
    // populates DB / mail values at first browser visit, so a re-install
    // doesn't need to warn about "preserved configuration".
    if (! file_exists($envFile)) {
        if (! copy($envExample, $envFile)) {
            fatal('Failed to copy .env.example to .env');
        }

        info('.env file created');
    }

    // Generate APP_KEY only when it is empty. Replacing an existing key
    // makes every encrypted value unreadable and breaks the audit log
    // seals, so a re-install must never rotate it.
    if (env_file_has_app_key($envFile)) {
        info('Existing application key kept');
        return;
    }

    $artisan = $dir . '/artisan';

    if (file_exists($artisan)) {
        $escDir = escapeshellarg($dir);
        exec(PHP_BINARY . " {$escDir}/artisan key:generate --force 2>&1", $output, $code);

        if ($code === 0) {
            info('Application key generated');
        } else {
            warn('Could not generate application key. Run: php artisan key:generate');
        }
    } else {
        warn('artisan not found — skipping key generation.');
    }
}

/**
 * True when the .env file at $envFile has a non-empty APP_KEY value.
 */
function env_file_has_app_key(string $envFile): bool
{
    $contents = @file_get_contents($envFile);

    if ($contents === false || ! preg_match('/^APP_KEY[ \t]*=(.*)$/m', $contents, $m)) {
        return false;
    }

    return trim(trim($m[1]), '"\'') !== '';
}

function set_permissions(string $dir): void
{
    step('Setting permissions');

    $dirs = [
        $dir . '/storage',
        $dir . '/bootstrap/cache',
    ];

    foreach ($dirs as $path) {
        if (! is_dir($path)) {
            @mkdir($path, 0775, true);
        }

        chmod_recursive($path, 0755, 0664);
    }

    // Make storage and bootstrap/cache group-writable
    foreach ($dirs as $path) {
        @chmod($path, 0775);
    }

    info('Permissions set for storage/ and bootstrap/cache/');
}

/**
 * Recursively set permissions on files and directories.
 */
function chmod_recursive(string $path, int $dirMode, int $fileMode): void
{
    if (! is_dir($path)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        if ($item->isDir()) {
            @chmod($item->getPathname(), $dirMode);
        } else {
            @chmod($item->getPathname(), $fileMode);
        }
    }
}

function create_storage_link(string $dir): void
{
    $artisan = $dir . '/artisan';

    if (! file_exists($artisan)) {
        return;
    }

    $escDir = escapeshellarg($dir);
    exec(PHP_BINARY . " {$escDir}/artisan storage:link 2>&1", $output, $code);

    if ($code === 0) {
        info('Storage symlink created');
    } else {
        warn('Could not create storage symlink. Run: php artisan storage:link');
    }
}

// ---------------------------------------------------------------------------
// Completion message
// ---------------------------------------------------------------------------

function show_complete(string $dir, string $assetsStatus = 'built'): void
{
    fwrite(STDOUT, PHP_EOL);
    fwrite(STDOUT, bold(green('  ╔══════════════════════════════════════════╗')) . PHP_EOL);
    fwrite(STDOUT, bold(green('  ║')) . bold('      Installation Complete!              ') . bold(green('║')) . PHP_EOL);
    fwrite(STDOUT, bold(green('  ╚══════════════════════════════════════════╝')) . PHP_EOL);
    fwrite(STDOUT, PHP_EOL);
    fwrite(STDOUT, '  Dixlase has been installed to:' . PHP_EOL);
    fwrite(STDOUT, '  ' . cyan($dir) . PHP_EOL);
    fwrite(STDOUT, PHP_EOL);
    fwrite(STDOUT, bold('  Next steps:') . PHP_EOL);
    fwrite(STDOUT, PHP_EOL);

    // When the asset build did not complete, prepend a "build the frontend"
    // step so the user knows to run npm before opening the URL — otherwise
    // Vite's manifest is missing and the wizard 500s before it can render.
    // Differentiate 'failed' (we tried and broke; surface the failure clearly)
    // from 'skipped' (intentional via --no-build or no Vite project detected).
    $step = 1;
    if ($assetsStatus === 'failed') {
        fwrite(STDOUT, '  ' . $step . '. ' . yellow('Frontend asset build did not complete. Re-run:') . PHP_EOL);
        fwrite(STDOUT, '     ' . cyan('cd ' . $dir . ' && npm install && npm run build') . PHP_EOL);
        fwrite(STDOUT, PHP_EOL);
        $step++;
    } elseif ($assetsStatus === 'skipped') {
        fwrite(STDOUT, '  ' . $step . '. Build the frontend assets:' . PHP_EOL);
        fwrite(STDOUT, '     ' . cyan('cd ' . $dir . ' && npm install && npm run build') . PHP_EOL);
        fwrite(STDOUT, PHP_EOL);
        $step++;
    }

    fwrite(STDOUT, '  ' . $step . '. Start the local server:' . PHP_EOL);
    // PHP's built-in server started directly, with the same router script
    // `php artisan serve` uses. artisan serve cannot run this wizard: its
    // .env watcher restarts the server mid-request (the final step writes
    // .env three times around `migrate`, so it dies there), and with
    // --no-reload it injects the boot-time .env as real environment
    // variables, which then win over every later .env write.
    // variables_order=EGPCS puts the real environment (PATH included) into
    // $_ENV; without it, Symfony Process drops PATH once Laravel has loaded
    // .env into $_SERVER, and the core's `composer dump-autoload` subprocess
    // fails with "env: php: No such file or directory".
    // The router uses the current directory as the web root, hence public/.
    // PHP_CLI_SERVER_WORKERS lets the server answer several requests at
    // once; with the default single worker, one slow request (the admin's
    // plugin list fetches every thumbnail from GitHub server-side) stalls
    // every other page. Windows has neither the variable nor the
    // `VAR=value command` syntax, so it keeps the single worker.
    $workers = PHP_OS_FAMILY === 'Windows' ? '' : 'PHP_CLI_SERVER_WORKERS=4 ';
    fwrite(STDOUT, '     ' . cyan('cd ' . $dir . '/public && ' . $workers . 'php -d variables_order=EGPCS -d max_execution_time=300 -S 127.0.0.1:8000 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php') . PHP_EOL);
    fwrite(STDOUT, PHP_EOL);
    fwrite(STDOUT, '     Then open ' . cyan('http://127.0.0.1:8000') . ' in your browser.' . PHP_EOL);
    fwrite(STDOUT, '     The ' . bold('Installation Wizard') . ' guides you through database, admin, and mail setup.' . PHP_EOL);
    fwrite(STDOUT, PHP_EOL);
    // Recommend SQLite for the one-liner's typical use (a trial or a small
    // site on the built-in server): it needs no database server, and the
    // wizard sets it up in one step. pdo_sqlite is not in the required
    // extension list, so say how to get it when it is missing.
    if (extension_loaded('pdo_sqlite')) {
        fwrite(STDOUT, '     ' . green('Database:') . ' choose ' . bold('SQLite') . ' in the Database step. It needs no database server,' . PHP_EOL);
        fwrite(STDOUT, '     which suits a trial or a small site. For production, use MySQL / MariaDB.' . PHP_EOL);
    } else {
        fwrite(STDOUT, '     ' . yellow('Database:') . ' SQLite is the easiest choice, but the pdo_sqlite extension is not loaded.' . PHP_EOL);
        fwrite(STDOUT, '     Install it (e.g. php8.3-sqlite3) and restart, or use MySQL / MariaDB.' . PHP_EOL);
    }
    fwrite(STDOUT, PHP_EOL);
    fwrite(STDOUT, '     ' . dim('Use this instead of php artisan serve: the wizard rewrites .env as it goes,') . PHP_EOL);
    fwrite(STDOUT, '     ' . dim('and artisan serve either restarts mid-request (the final step dies) or, with') . PHP_EOL);
    fwrite(STDOUT, '     ' . dim('--no-reload, keeps the old values. Start it from public/ as shown.') . PHP_EOL);
    fwrite(STDOUT, PHP_EOL);
    $step++;
    fwrite(STDOUT, '  ' . $step . '. For other deployment options (Docker, VPS, shared hosting), see:' . PHP_EOL);
    fwrite(STDOUT, '     ' . cyan('https://github.com/' . DIXLASE_INSTALLER_REPO . '/blob/main/docs/index.md') . PHP_EOL);
    fwrite(STDOUT, PHP_EOL);
    // No documentation site is published yet for the first release, so the
    // only link here is one that actually resolves.
    fwrite(STDOUT, dim('  Support: https://github.com/' . DIXLASE_REPO . '/issues') . PHP_EOL);
    fwrite(STDOUT, PHP_EOL);
}

// ---------------------------------------------------------------------------
// Main
// ---------------------------------------------------------------------------

function main(array $argv): int
{
    // Remove script name from args
    array_shift($argv);

    // Remove the `--` separator that `php -- --opt` passes through
    $argv = array_values(array_filter($argv, fn ($a) => $a !== '--'));

    $options = parse_args($argv);

    banner();

    if ($options['help']) {
        show_help();
        return 0;
    }

    // --- Decide whether prompts are allowed ---

    $interactive = stdin_is_tty() && ! $options['non_interactive'];

    // --- Pre-flight checks ---

    check_php_version();
    check_extensions();

    $composerAvailable = check_composer();

    if (github_token() !== '') {
        step('GitHub authentication');
        info('Token detected — private repository access enabled');
    }

    // --- Choose delivery method ---
    //
    // auto = prefer ZIP (a release ZIP ships pre-built vendor/ and Vite assets,
    // so Node/Composer aren't required on the install machine). Fall through
    // to composer create-project only if the ZIP path fails AND composer is
    // present. Explicit --method=zip / --method=composer skips the fallback.

    $method = $options['method'];

    if ($method === 'composer' && ! $composerAvailable) {
        error('Composer is required for --method=composer but was not found.');
        fwrite(STDERR, PHP_EOL);
        fwrite(STDERR, '  Install Composer:' . PHP_EOL);
        fwrite(STDERR, '    curl -sS https://getcomposer.org/installer | php' . PHP_EOL);
        fwrite(STDERR, '    sudo mv composer.phar /usr/local/bin/composer' . PHP_EOL);
        fwrite(STDERR, PHP_EOL);
        fwrite(STDERR, '  Or re-run with ' . bold('--method=zip') . ' (the default) to use the pre-built release.' . PHP_EOL);
        return 1;
    }

    // --- Resolve installation directory ---

    if ($options['dir_specified']) {
        $dir = resolve_path((string) $options['dir']);
    } elseif ($interactive) {
        $answer = ask('Installation directory', default_install_dir());
        $dir    = resolve_path($answer);
    } else {
        $dir = resolve_path(default_install_dir());
    }

    // Refuse to install over an existing site, with or without --dir=.
    // Re-running the one-liner is not an update: it would overwrite the
    // live code in place. --force-reinstall is the explicit way through.
    $markers = is_dir($dir) ? existing_install_markers($dir) : [];

    if ($markers !== [] && ! $options['force_reinstall']) {
        error("An existing installation was found in: {$dir}");
        fwrite(STDERR, '    (found: ' . implode(', ', $markers) . ')' . PHP_EOL);
        fwrite(STDERR, PHP_EOL);
        fwrite(STDERR, '  The installer does not update an existing site. Choose one of:' . PHP_EOL);
        fwrite(STDERR, '    • To update Dixlase, run ' . bold('php artisan dls:core:update') . ' in that directory' . PHP_EOL);
        fwrite(STDERR, '    • Pass ' . bold('--dir=PATH') . ' to install into a different location' . PHP_EOL);
        fwrite(STDERR, '    • Pass ' . bold('--force-reinstall') . ' to overwrite the files (the existing .env and APP_KEY are kept)' . PHP_EOL);
        fwrite(STDERR, PHP_EOL);
        return 1;
    }

    // When the default <cwd>/dixlase target already holds files, refuse
    // upfront rather than letting composer / unzip emit a confusing
    // "directory not empty" error mid-install. Only fires for the auto
    // path — explicit --dir= lets the caller take responsibility.
    if (! $options['dir_specified'] && ! $options['force_reinstall'] && is_dir($dir) && ! is_dir_effectively_empty($dir)) {
        error("Target directory already exists and is not empty: {$dir}");
        fwrite(STDERR, PHP_EOL);
        fwrite(STDERR, '  Choose one of:' . PHP_EOL);
        fwrite(STDERR, '    • Remove the directory and re-run the installer' . PHP_EOL);
        fwrite(STDERR, '    • Pass ' . bold('--dir=PATH') . ' to install into a different location' . PHP_EOL);
        fwrite(STDERR, '    • Pass ' . bold('--dir=.') . ' to install into the current directory in place' . PHP_EOL);
        fwrite(STDERR, PHP_EOL);
        return 1;
    }

    // --- Confirm before proceeding (interactive only) ---

    if ($interactive && ! $options['assume_yes']) {
        $shownMethod = $method === 'auto' ? 'auto (prefers zip)' : $method;
        fwrite(STDOUT, PHP_EOL);
        fwrite(STDOUT, '  Method:    ' . cyan($shownMethod) . PHP_EOL);
        fwrite(STDOUT, '  Directory: ' . cyan($dir) . PHP_EOL);
        $version = $options['version'] !== null ? 'v' . $options['version'] : 'latest';
        fwrite(STDOUT, '  Version:   ' . cyan($version) . PHP_EOL);
        fwrite(STDOUT, PHP_EOL);

        if (! confirm('Proceed with installation?', true)) {
            warn('Cancelled by user.');
            return 1;
        }
    }

    // --- Ensure target directory ---

    if (! is_dir($dir)) {
        if (! @mkdir($dir, 0755, true)) {
            fatal("Cannot create directory: {$dir}");
        }
    }

    if (! is_writable($dir)) {
        fatal("Directory is not writable: {$dir}");
    }

    // --- Run the chosen delivery path ---
    //
    // Order: ZIP first when auto (or explicit zip), composer second. If zip
    // fails AND we're in auto mode AND composer is available, fall back to
    // composer create-project. Explicit method does NOT fall back so users
    // get the failure they asked for.

    $installed = false;

    if ($method === 'zip' || $method === 'auto') {
        $version = $options['version'] ?? fetch_latest_version();

        if ($version !== null && download_dixlase($dir, $version)) {
            $installed = true;

            // Only run composer install when the ZIP did NOT ship vendor/
            // (a pre-built release usually includes it). Skip when --no-composer
            // was set, or when composer isn't on PATH.
            if (! is_dir($dir . '/vendor')) {
                if ($options['no_composer']) {
                    step('Skipping Composer install');
                    warn('Remember to run: composer install --no-dev --optimize-autoloader');
                } elseif (! $composerAvailable) {
                    warn('vendor/ is not in the ZIP and Composer is not available.');
                    warn('Install Composer and run: composer install --no-dev --optimize-autoloader');
                } else {
                    run_composer($dir);
                }
            }
        } elseif ($method === 'auto' && $composerAvailable) {
            warn('ZIP delivery path failed — falling back to composer create-project.');
            $method = 'composer';
        } else {
            fatal('Failed to download the release ZIP.');
        }
    }

    if (! $installed && $method === 'composer') {
        if (composer_create_project($dir, $options['version'])) {
            $installed = true;
        } else {
            fatal('composer create-project failed.');
        }
    }

    if (! $installed) {
        fatal('Both delivery paths failed.');
    }

    // SECURITY: composer_create_project() set COMPOSER_AUTH (containing the
    // GitHub token) via putenv() for its own subprocess. Scrub it from the
    // process env now that composer has finished and *before* any other
    // subprocess (artisan key:generate via setup_environment, chmod via
    // set_permissions, npm via build_assets, artisan storage:link via
    // create_storage_link) inherits it. build_assets() repeats this defence
    // in depth in case a future caller invokes it without going through main.
    putenv('COMPOSER_AUTH');
    putenv('GITHUB_TOKEN');
    putenv('DIXLASE_GITHUB_TOKEN');

    // --- Common post-install steps ---
    //
    // Order matters: setup_environment runs first because Vite reads .env at
    // build time (VITE_*). build_assets must run BEFORE set_permissions so
    // chmod_recursive() does not walk the freshly-created node_modules tree
    // (slow + unnecessary).

    setup_environment($dir);
    $assetsStatus = build_assets($dir, $options['no_build']);
    set_permissions($dir);
    create_storage_link($dir);

    show_complete($dir, $assetsStatus);

    return 0;
}

// Run
exit(main($argv));
