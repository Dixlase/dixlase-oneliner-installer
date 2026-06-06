#!/usr/bin/env bats

load 'helpers/test_helpers'

setup() {
    REL_DIR="$BATS_TEST_TMPDIR/releases"
    mkdir -p "$REL_DIR"
    VERSION="$(build_fake_release "$REL_DIR")"
    start_mock_github "$REL_DIR" "$VERSION"
    FAKE_HOME="$BATS_TEST_TMPDIR/home"
    mkdir -p "$FAKE_HOME"
}

teardown() {
    stop_mock_github
}

@test "default dir: piped run from HOME installs into <HOME>/dixlase, not HOME itself" {
    run env HOME="$FAKE_HOME" bash -c "cd \"$FAKE_HOME\" && \
        DIXLASE_API_LATEST_URL=\"$MOCK_BASE_URL/api/latest.json\" \
        DIXLASE_RELEASE_URL_BASE=\"$MOCK_BASE_URL/releases/download\" \
        php \"$INSTALL_PHP\" --method=zip --no-composer --non-interactive --yes --version=\"$VERSION\""
    [ "$status" -eq 0 ]
    [[ "$output" == *"Installation Complete!"* ]]
    [ -f "$FAKE_HOME/dixlase/artisan" ]
    [ -f "$FAKE_HOME/dixlase/.env" ]
    [ ! -f "$FAKE_HOME/artisan" ]
}

@test "default dir: piped run from an empty non-HOME cwd installs in place" {
    local CWD="$BATS_TEST_TMPDIR/site"
    mkdir -p "$CWD"
    run env HOME="$FAKE_HOME" bash -c "cd \"$CWD\" && \
        DIXLASE_API_LATEST_URL=\"$MOCK_BASE_URL/api/latest.json\" \
        DIXLASE_RELEASE_URL_BASE=\"$MOCK_BASE_URL/releases/download\" \
        php \"$INSTALL_PHP\" --method=zip --no-composer --non-interactive --yes --version=\"$VERSION\""
    [ "$status" -eq 0 ]
    [[ "$output" == *"Installation Complete!"* ]]
    [ -f "$CWD/artisan" ]
    [ ! -d "$CWD/dixlase" ]
}

@test "post-install: next-steps include 'cd <dir> && php artisan serve' and docs index URL" {
    local CWD="$BATS_TEST_TMPDIR/site"
    mkdir -p "$CWD"
    run env HOME="$FAKE_HOME" bash -c "cd \"$CWD\" && \
        DIXLASE_API_LATEST_URL=\"$MOCK_BASE_URL/api/latest.json\" \
        DIXLASE_RELEASE_URL_BASE=\"$MOCK_BASE_URL/releases/download\" \
        php \"$INSTALL_PHP\" --method=zip --no-composer --non-interactive --yes --version=\"$VERSION\""
    [ "$status" -eq 0 ]
    [[ "$output" == *"cd $CWD && php artisan serve"* ]]
    [[ "$output" == *"http://127.0.0.1:8000"* ]]
    [[ "$output" == *"https://github.com/Dixlase/dixlase-oneliner-installer/blob/main/docs/index.md"* ]]
}
