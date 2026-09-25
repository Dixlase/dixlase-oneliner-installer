#!/usr/bin/env bats

load 'helpers/test_helpers'

setup() {
    REL_DIR="$BATS_TEST_TMPDIR/releases"
    mkdir -p "$REL_DIR"
    VERSION="$(build_fake_release "$REL_DIR")"
    start_mock_github "$REL_DIR" "$VERSION"
    FAKE_HOME="$BATS_TEST_TMPDIR/home"
    mkdir -p "$FAKE_HOME"
    : > "$FAKE_HOME/.zshrc"
}

teardown() {
    stop_mock_github
}

run_in() {
    local cwd="$1"
    shift
    env HOME="$FAKE_HOME" bash -c "cd \"$cwd\" && \
        DIXLASE_API_LATEST_URL=\"$MOCK_BASE_URL/api/latest.json\" \
        DIXLASE_RELEASE_URL_BASE=\"$MOCK_BASE_URL/releases/download\" \
        php \"$INSTALL_PHP\" --method=zip --no-composer --non-interactive --yes --version=\"$VERSION\" $*"
}

@test "default dir: empty cwd still installs into <cwd>/dixlase (unified rule)" {
    local CWD="$BATS_TEST_TMPDIR/site"
    mkdir -p "$CWD"
    run run_in "$CWD"
    [ "$status" -eq 0 ]
    [[ "$output" == *"Installation Complete!"* ]]
    [ -f "$CWD/dixlase/artisan" ]
    [ ! -f "$CWD/artisan" ]
}

@test "default dir: non-empty cwd installs into <cwd>/dixlase" {
    local CWD="$BATS_TEST_TMPDIR/site"
    mkdir -p "$CWD"
    : > "$CWD/note.txt"
    run run_in "$CWD"
    [ "$status" -eq 0 ]
    [ -f "$CWD/dixlase/artisan" ]
    [ -f "$CWD/note.txt" ]
}

@test "default dir: HOME installs into <HOME>/dixlase, not HOME itself" {
    run run_in "$FAKE_HOME"
    [ "$status" -eq 0 ]
    [ -f "$FAKE_HOME/dixlase/artisan" ]
    [ ! -f "$FAKE_HOME/artisan" ]
}

@test "default dir: pre-existing non-empty <cwd>/dixlase aborts with guidance" {
    local CWD="$BATS_TEST_TMPDIR/site"
    mkdir -p "$CWD/dixlase"
    : > "$CWD/dixlase/leftover.txt"
    run run_in "$CWD"
    [ "$status" -ne 0 ]
    [[ "$output" == *"already exists and is not empty"* ]]
    [[ "$output" == *"--dir=PATH"* ]]
    [[ "$output" == *"--dir=."* ]]
    [ ! -f "$CWD/dixlase/artisan" ]
    [ -f "$CWD/dixlase/leftover.txt" ]
}

@test "default dir: --dir=. forces in-place install even when cwd has files" {
    local CWD="$BATS_TEST_TMPDIR/site"
    mkdir -p "$CWD"
    : > "$CWD/note.txt"
    run run_in "$CWD" --dir=.
    [ "$status" -eq 0 ]
    [ -f "$CWD/artisan" ]
    [ -f "$CWD/note.txt" ]
    [ ! -d "$CWD/dixlase" ]
}

@test "post-install: next-steps include 'cd <cwd>/dixlase/public && php ... -S' and docs index URL" {
    local CWD="$BATS_TEST_TMPDIR/site"
    mkdir -p "$CWD"
    run run_in "$CWD"
    [ "$status" -eq 0 ]
    [[ "$output" == *"cd $CWD/dixlase/public && php -d variables_order=EGPCS -d max_execution_time=300 -S 127.0.0.1:8000"* ]]
    [[ "$output" == *"http://127.0.0.1:8000"* ]]
    [[ "$output" == *"https://github.com/Dixlase/dixlase-oneliner-installer/blob/main/docs/index.md"* ]]
}
