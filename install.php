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
 * https://exc-d.com
 */

// ---------------------------------------------------------------------------
// Constants
// ---------------------------------------------------------------------------

define('DIXLASE_MIN_PHP', '8.2.0');
define('DIXLASE_REPO', 'Dixlase/dixlase-core');
define('DIXLASE_PACKAGE', 'dixlase/dixlase-core');
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
        'non_interactive' => false,
        'assume_yes'      => false,
        'help'            => false,
    ];

    foreach ($argv as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            $options['help'] = true;
        } elseif ($arg === '--no-composer') {
            $options['no_composer'] = true;
        } elseif ($arg === '--non-interactive') {
            $options['non_interactive'] = true;
        } elseif ($arg === '--yes' || $arg === '-y') {
            $options['assume_yes'] = true;
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

function show_help(): void
{
    fwrite(STDOUT, <<<'HELP'

Usage:
  curl -sS https://install.dixlase.net | php
  curl -sS https://install.dixlase.net | php -- [options]
  php install.php [options]

Options:
  --dir=PATH          Installation directory (default: current directory)
  --version=X.X.X     Install a specific version (default: latest)
  --method=MODE       Delivery method: auto, composer, or zip (default: auto)
  --no-composer       Skip "composer install" in the zip fallback path
  --non-interactive   Disable prompts even when STDIN is a terminal
  -y, --yes           Auto-confirm every prompt
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
        fwrite(STDERR, '  Install them and try again. For example (Debian/Ubuntu):' . PHP_EOL);
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
 */
function fetch_latest_version(): string
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
        fatal('Could not fetch release information from GitHub. Check your network connection.');
    }

    $data = json_decode($json, true);

    if (! isset($data['tag_name'])) {
        fatal('Unexpected API response from GitHub.');
    }

    $version = ltrim($data['tag_name'], 'v');
    info("Latest version: {$version}");

    return $version;
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
 * Verify the SHA-256 checksum of a downloaded file.
 */
function verify_checksum(string $file, string $version): bool
{
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
        '/^\s*(Deprecation Notice|Deprecated):/',
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
        // SQLSTATE 2002 from the post-create-project `migrate --graceful`:
        // the install wizard sets DB credentials on first browser visit,
        // so the placeholder .env's DB_HOST=mysql is expected to fail here.
        '/SQLSTATE\[HY000\] \[2002\]/',
    ];

    foreach ($noise as $pattern) {
        if (preg_match($pattern, $line)) {
            return false;
        }
    }

    return true;
}

/**
 * Stream a Composer subprocess to STDOUT, filtering noise, and capture the
 * full output to a temporary log file so failures can be debugged.
 *
 * Returns the subprocess exit code. $logFile is set to the path of the log
 * for the caller to display on failure (it lives under sys_get_temp_dir()).
 */
function run_filtered_composer(string $command, ?string &$logFile = null): int
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

        if (should_show_composer_line($line)) {
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

function download_dixlase(string $dir, string $version): void
{
    step("Downloading Dixlase v{$version}");

    $zipName = "dixlase-v{$version}.zip";
    $url     = DIXLASE_RELEASE_URL . "/v{$version}/{$zipName}";
    $tmpZip  = sys_get_temp_dir() . "/{$zipName}";

    if (! download_file($url, $tmpZip)) {
        @unlink($tmpZip);
        fatal("Failed to download {$url}");
    }

    // Checksum verification
    if (! verify_checksum($tmpZip, $version)) {
        @unlink($tmpZip);
        fatal('Download verification failed. The file may have been tampered with.');
    }

    // Extract
    step('Extracting files');

    if (! class_exists('ZipArchive')) {
        // Fallback to unzip command
        $escaped = escapeshellarg($tmpZip);
        $escDir  = escapeshellarg($dir);
        exec("unzip -o {$escaped} -d {$escDir} 2>&1", $output, $code);

        if ($code !== 0) {
            @unlink($tmpZip);
            fatal('Failed to extract ZIP archive. Install the php-zip extension or the unzip command.');
        }
    } else {
        $zip = new ZipArchive();

        if ($zip->open($tmpZip) !== true) {
            @unlink($tmpZip);
            fatal('Failed to open ZIP archive.');
        }

        // Detect if the archive has a top-level directory
        $topDir = null;
        if ($zip->numFiles > 0) {
            $firstName = $zip->getNameIndex(0);
            if (str_contains($firstName, '/')) {
                $topDir = explode('/', $firstName)[0];
            }
        }

        $zip->extractTo(sys_get_temp_dir());
        $zip->close();

        // Move files from nested directory to target
        $extractedPath = $topDir
            ? sys_get_temp_dir() . '/' . $topDir
            : sys_get_temp_dir();

        if ($topDir && is_dir($extractedPath)) {
            // Ensure target directory exists
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            // Move all files
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($extractedPath, RecursiveDirectoryIterator::SKIP_DOTS),
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

                    rename($item->getPathname(), $target);
                }
            }

            // Clean up extracted directory
            remove_directory($extractedPath);
        }
    }

    @unlink($tmpZip);

    info('Files extracted to ' . $dir);
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
    $exitCode = run_filtered_composer($command, $logFile);

    if ($exitCode === 127) {
        error('Failed to launch Composer.');
        return false;
    }

    if ($exitCode !== 0) {
        error('composer create-project failed (exit code ' . $exitCode . ').');
        if ($logFile !== null) {
            warn('Full log saved to ' . $logFile);
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
    $exitCode = run_filtered_composer($command, $logFile);

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

    // Generate APP_KEY
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

function show_complete(string $dir): void
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
    fwrite(STDOUT, '  1. Point your web server document root to:' . PHP_EOL);
    fwrite(STDOUT, '     ' . cyan($dir . '/public') . PHP_EOL);
    fwrite(STDOUT, PHP_EOL);
    fwrite(STDOUT, '  2. Open your browser and visit your site URL.' . PHP_EOL);
    fwrite(STDOUT, '     The ' . bold('Installation Wizard') . ' will guide you through:' . PHP_EOL);
    fwrite(STDOUT, '     • Database configuration' . PHP_EOL);
    fwrite(STDOUT, '     • Admin account creation' . PHP_EOL);
    fwrite(STDOUT, '     • Mail server settings' . PHP_EOL);
    fwrite(STDOUT, PHP_EOL);
    fwrite(STDOUT, dim('  Documentation: https://docs.dixlase.com') . PHP_EOL);
    fwrite(STDOUT, dim('  Support:       https://github.com/' . DIXLASE_REPO . '/issues') . PHP_EOL);
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

    $method = $options['method'];

    if ($method === 'auto') {
        $method = $composerAvailable ? 'composer' : 'zip';
    }

    if ($method === 'composer' && ! $composerAvailable) {
        error('Composer is required for --method=composer but was not found.');
        fwrite(STDERR, PHP_EOL);
        fwrite(STDERR, '  Install Composer:' . PHP_EOL);
        fwrite(STDERR, '    curl -sS https://getcomposer.org/installer | php' . PHP_EOL);
        fwrite(STDERR, '    sudo mv composer.phar /usr/local/bin/composer' . PHP_EOL);
        fwrite(STDERR, PHP_EOL);
        fwrite(STDERR, '  Or re-run with ' . bold('--method=zip') . ' to use the ZIP fallback.' . PHP_EOL);
        return 1;
    }

    if ($method === 'zip' && ! $composerAvailable && ! $options['no_composer']) {
        warn('Composer not found — the ZIP path will skip "composer install".');
        warn('You will need to run it yourself before the site can boot.');
        $options['no_composer'] = true;
    }

    // --- Resolve installation directory ---

    if ($options['dir_specified']) {
        $dir = resolve_path((string) $options['dir']);
    } elseif ($interactive) {
        $answer = ask('Installation directory', getcwd());
        $dir    = resolve_path($answer);
    } else {
        $dir = resolve_path(getcwd());
    }

    // --- Confirm before proceeding (interactive only) ---

    if ($interactive && ! $options['assume_yes']) {
        fwrite(STDOUT, PHP_EOL);
        fwrite(STDOUT, '  Method:    ' . cyan($method) . PHP_EOL);
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

    if ($method === 'composer') {
        if (! composer_create_project($dir, $options['version'])) {
            warn('Falling back to the ZIP delivery path.');
            $method = 'zip';
        }
    }

    if ($method === 'zip') {
        $version = $options['version'] ?? fetch_latest_version();
        download_dixlase($dir, $version);

        if (! $options['no_composer']) {
            run_composer($dir);
        } else {
            step('Skipping Composer install');
            warn('Remember to run: composer install --no-dev --optimize-autoloader');
        }
    }

    // --- Common post-install steps ---

    setup_environment($dir);
    set_permissions($dir);
    create_storage_link($dir);

    show_complete($dir);

    return 0;
}

// Run
exit(main($argv));
