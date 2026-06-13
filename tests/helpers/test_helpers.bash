#!/usr/bin/env bash
# SPDX-License-Identifier: LicenseRef-Proprietary
# Copyright (c) 2026 exc-D inc. All rights reserved. See LICENSE for terms.
# Common helpers for the bats test suite.

# Resolve project paths relative to this helper file (tests/helpers/test_helpers.bash).
PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
INSTALL_PHP="$PROJECT_ROOT/install.php"
FIXTURES_DIR="$PROJECT_ROOT/tests/fixtures"

# Find a free TCP port in [18765, 19000). Uses bash's /dev/tcp probe.
find_free_port() {
    local p
    for p in $(seq 18765 19000); do
        if ! (echo > "/dev/tcp/127.0.0.1/$p") 2>/dev/null; then
            echo "$p"
            return 0
        fi
    done
    echo "no free port in 18765..19000" >&2
    return 1
}

# Build a fake Dixlase release ZIP under $1 with version $2 (default 0.0.1-test).
# Also writes a checksums.sha256 entry next to the zip.
build_fake_release() {
    local outdir="$1"
    local version="${2:-0.0.1-test}"
    local pkgdir="$outdir/dixlase-v$version"

    rm -rf "$pkgdir"
    mkdir -p "$pkgdir"
    # Copy fixture content (including dotfiles).
    (cd "$FIXTURES_DIR/dixlase-fake" && tar cf - .) | (cd "$pkgdir" && tar xf -)

    (cd "$outdir" && zip -rq "dixlase-v$version.zip" "dixlase-v$version")
    rm -rf "$pkgdir"

    local hash
    hash=$(shasum -a 256 "$outdir/dixlase-v$version.zip" | awk '{print $1}')
    printf '%s  dixlase-v%s.zip\n' "$hash" "$version" > "$outdir/checksums.sha256"

    printf '%s' "$version"
}

# Lay out the served tree and start `php -S` on a free port.
# Arg 3 (optional): a token the mock will require on every request.
# Sets globals: MOCK_PID, MOCK_BASE_URL, MOCK_SERVE_ROOT, MOCK_VERSION, MOCK_TOKEN.
start_mock_github() {
    local releases_dir="$1"
    local version="$2"
    local expected_token="${3:-}"

    MOCK_SERVE_ROOT="$(mktemp -d)"
    mkdir -p "$MOCK_SERVE_ROOT/api" "$MOCK_SERVE_ROOT/releases/download/v$version"
    cp "$releases_dir/dixlase-v$version.zip" "$MOCK_SERVE_ROOT/releases/download/v$version/"
    cp "$releases_dir/checksums.sha256" "$MOCK_SERVE_ROOT/releases/download/v$version/"
    printf '{"tag_name":"v%s"}\n' "$version" > "$MOCK_SERVE_ROOT/api/latest.json"

    local port
    port="$(find_free_port)" || return 1
    MOCK_BASE_URL="http://127.0.0.1:$port"
    MOCK_VERSION="$version"
    MOCK_TOKEN="$expected_token"

    # The router enforces the token only when MOCK_EXPECTED_TOKEN is non-empty;
    # otherwise it is transparent, so the suite always runs through it.
    MOCK_EXPECTED_TOKEN="$expected_token" \
        php -S "127.0.0.1:$port" -t "$MOCK_SERVE_ROOT" \
        "$PROJECT_ROOT/tests/helpers/mock_router.php" >/dev/null 2>&1 &
    MOCK_PID=$!

    # Wait up to 3s for the server to come up (sending the token if required).
    local i auth=()
    [[ -n "$expected_token" ]] && auth=(-H "Authorization: Bearer $expected_token")
    for i in $(seq 1 30); do
        if curl -sf "${auth[@]}" -o /dev/null "$MOCK_BASE_URL/api/latest.json"; then
            return 0
        fi
        sleep 0.1
    done
    echo "mock server did not become ready on port $port" >&2
    return 1
}

stop_mock_github() {
    if [[ -n "${MOCK_PID:-}" ]]; then
        kill "$MOCK_PID" 2>/dev/null || true
        wait "$MOCK_PID" 2>/dev/null || true
        unset MOCK_PID
    fi
    if [[ -n "${MOCK_SERVE_ROOT:-}" && -d "$MOCK_SERVE_ROOT" ]]; then
        rm -rf "$MOCK_SERVE_ROOT"
        unset MOCK_SERVE_ROOT
    fi
}

# Run install.php with the mock URLs wired in via env vars.
run_installer_with_mock() {
    DIXLASE_API_LATEST_URL="$MOCK_BASE_URL/api/latest.json" \
        DIXLASE_RELEASE_URL_BASE="$MOCK_BASE_URL/releases/download" \
        php "$INSTALL_PHP" "$@"
}

# Same as run_installer_with_mock, but also exports a GitHub token. The token
# env var name is given as arg 1 (DIXLASE_GITHUB_TOKEN or GITHUB_TOKEN).
run_installer_with_mock_token() {
    local token_var="$1"
    local token_val="$2"
    shift 2
    env "${token_var}=${token_val}" \
        DIXLASE_API_LATEST_URL="$MOCK_BASE_URL/api/latest.json" \
        DIXLASE_RELEASE_URL_BASE="$MOCK_BASE_URL/releases/download" \
        php "$INSTALL_PHP" "$@"
}

# Copy the bits convert-comments.sh needs into a sandbox so we can convert
# install.php to a locale without touching the working tree.
stage_project_in() {
    local dest="$1"
    cp "$PROJECT_ROOT/install.php" "$dest/"
    cp "$PROJECT_ROOT/convert-comments.sh" "$dest/"
    cp -r "$PROJECT_ROOT/lang" "$dest/"
}
