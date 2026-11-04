#!/usr/bin/env bats

# The Composer path cannot be run end to end here: it resolves the core from
# Packagist, which the tests do not reach. Instead a fake `composer`
# on PATH records the arguments and the COMPOSER_AUTH it receives, then fails,
# so these tests check how install.php builds the create-project call.

load 'helpers/test_helpers'

setup() {
    INSTALL_DIR="$BATS_TEST_TMPDIR/dixlase"
    FAKE_BIN="$BATS_TEST_TMPDIR/bin"
    CALL_LOG="$BATS_TEST_TMPDIR/composer-call.log"
    TOKEN="ghp_testtoken1234567890"
    mkdir -p "$FAKE_BIN"

    cat > "$FAKE_BIN/composer" <<EOF
#!/bin/sh
if [ "\$1" = "--version" ]; then
    echo "Composer version 2.8.0 (fake)"
    exit 0
fi
printf 'ARG %s\n' "\$@" > "$CALL_LOG"
printf 'AUTH %s\n' "\${COMPOSER_AUTH:-}" >> "$CALL_LOG"
echo "fake composer: create-project failed"
exit 1
EOF
    chmod +x "$FAKE_BIN/composer"
}

# Fail when the recorded call matches the pattern (a bare negated grep would
# not fail a bats test).
refute_call() {
    if grep -q -- "$1" "$CALL_LOG"; then
        echo "unexpected match in composer call: $1" >&2
        return 1
    fi
}

# Run install.php on the Composer path with the fake composer first on PATH.
# Arg 1 is the PATH to use after the fake bin directory.
run_composer_path() {
    local rest_path="$1"
    shift
    env -u GITHUB_TOKEN -u DIXLASE_GITHUB_TOKEN -u COMPOSER_AUTH \
        PATH="$FAKE_BIN:$rest_path" "$@" \
        php "$INSTALL_PHP" --method=composer --non-interactive --yes --dir="$INSTALL_DIR"
}

@test "composer: an anonymous run resolves the package from Packagist" {
    run run_composer_path "$PATH"
    [ "$status" -ne 0 ]
    grep -q '^ARG create-project$' "$CALL_LOG"
    grep -qx 'ARG dixlase/dixlase-core' "$CALL_LOG"
    refute_call '--repository'
    refute_call 'no-api'
    refute_call '--stability'
    grep -qx 'AUTH ' "$CALL_LOG"
}

@test "composer: the run does not depend on git being installed" {
    # A PATH holding only php (and the fake composer) hides git.
    local php_only="$BATS_TEST_TMPDIR/php-only"
    mkdir -p "$php_only"
    ln -s "$(command -v php)" "$php_only/php"
    run run_composer_path "$php_only"
    [ "$status" -ne 0 ]
    grep -q '^ARG create-project$' "$CALL_LOG"
    refute_call '--repository'
}

@test "composer: a token goes through COMPOSER_AUTH, never the command line" {
    run run_composer_path "$PATH" GITHUB_TOKEN="$TOKEN"
    [ "$status" -ne 0 ]
    refute_call '--repository'
    refute_call '--stability'
    refute_call "^ARG .*$TOKEN"
    grep -q "^AUTH .*\"github.com\":\"$TOKEN\"" "$CALL_LOG"
}

@test "composer: the failure hint names real causes, not a private repository" {
    run run_composer_path "$PATH"
    [ "$status" -ne 0 ]
    [[ "$output" == *"composer create-project failed"* ]]
    [[ "$output" == *"retry with --method=zip"* ]]
    [[ "$output" != *"private repository"* ]]
    [[ "$output" != *"No GITHUB_TOKEN detected"* ]]
}
