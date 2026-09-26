#!/usr/bin/env bats

load 'helpers/test_helpers'

setup() {
    INSTALL_DIR="$BATS_TEST_TMPDIR/dixlase"
    REL_DIR="$BATS_TEST_TMPDIR/releases"
    WORK_TMP="$BATS_TEST_TMPDIR/tmp"
    mkdir -p "$REL_DIR" "$WORK_TMP"
    VERSION="$(build_fake_release "$REL_DIR")"
    start_mock_github "$REL_DIR" "$VERSION"
}

teardown() {
    stop_mock_github
}

install_once() {
    TMPDIR="$WORK_TMP" run_installer_with_mock --method=zip --no-composer --non-interactive --yes \
        --version="$VERSION" --dir="$INSTALL_DIR" "$@"
}

app_key() {
    grep '^APP_KEY=' "$INSTALL_DIR/.env"
}

@test "reinstall: first install generates an APP_KEY" {
    run install_once
    [ "$status" -eq 0 ]
    [[ "$output" == *"Application key generated"* ]]
    [[ "$(app_key)" == APP_KEY=base64:* ]]
}

@test "reinstall: second run into the same --dir refuses and leaves the site untouched" {
    install_once >/dev/null 2>&1
    local key_before
    key_before="$(app_key)"
    : > "$INSTALL_DIR/local-change.txt"
    rm "$INSTALL_DIR/composer.json"

    run install_once
    [ "$status" -ne 0 ]
    [[ "$output" == *"An existing installation was found"* ]]
    [[ "$output" == *"dls:core:update"* ]]
    [[ "$output" == *"--force-reinstall"* ]]
    [ "$(app_key)" = "$key_before" ]
    # No files were moved in.
    [ ! -f "$INSTALL_DIR/composer.json" ]
}

@test "reinstall: --force-reinstall overwrites files but keeps the existing APP_KEY" {
    install_once >/dev/null 2>&1
    local key_before
    key_before="$(app_key)"
    rm "$INSTALL_DIR/composer.json"

    run install_once --force-reinstall
    [ "$status" -eq 0 ]
    [[ "$output" == *"Existing application key kept"* ]]
    [ "$(app_key)" = "$key_before" ]
    [ -f "$INSTALL_DIR/composer.json" ]
}

@test "reinstall: an existing .env with an empty APP_KEY still gets a key" {
    mkdir -p "$INSTALL_DIR"
    printf 'APP_NAME=Dixlase\nAPP_KEY=\n' > "$INSTALL_DIR/.env"

    run install_once --force-reinstall
    [ "$status" -eq 0 ]
    [[ "$output" == *"Application key generated"* ]]
    [[ "$(app_key)" == APP_KEY=base64:* ]]
}

@test "work dir: downloads and extraction leave nothing behind in the temp directory" {
    run install_once
    [ "$status" -eq 0 ]
    [ -z "$(ls -A "$WORK_TMP")" ]
}

@test "work dir: a pre-created predictable path in the temp directory is not used" {
    # The old installer extracted to <tmp>/dixlase-v<version>/; a planted
    # directory there must not end up in the install.
    mkdir -p "$WORK_TMP/dixlase-v$VERSION"
    : > "$WORK_TMP/dixlase-v$VERSION/planted.php"

    run install_once
    [ "$status" -eq 0 ]
    [ ! -f "$INSTALL_DIR/planted.php" ]
    [ -f "$WORK_TMP/dixlase-v$VERSION/planted.php" ]
}

@test "zip layout: an archive without a top-level directory installs into --dir" {
    local flat="$BATS_TEST_TMPDIR/flat"
    mkdir -p "$flat"
    (cd "$FIXTURES_DIR/dixlase-fake" && tar cf - .) | (cd "$flat" && tar xf -)
    local zip="$MOCK_SERVE_ROOT/releases/download/v$VERSION/dixlase-v$VERSION.zip"
    rm -f "$zip"
    (cd "$flat" && zip -rq "$zip" .)
    local hash
    hash=$(shasum -a 256 "$zip" | awk '{print $1}')
    printf '%s  dixlase-v%s.zip\n' "$hash" "$VERSION" \
        > "$MOCK_SERVE_ROOT/releases/download/v$VERSION/checksums.sha256"

    run install_once
    [ "$status" -eq 0 ]
    [ -f "$INSTALL_DIR/artisan" ]
    [ -f "$INSTALL_DIR/.env" ]
    [ -z "$(ls -A "$WORK_TMP")" ]
}
