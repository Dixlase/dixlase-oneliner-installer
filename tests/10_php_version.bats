#!/usr/bin/env bats
# SPDX-License-Identifier: MIT
# Copyright (c) 2026 exc-D inc. Licensed under the MIT License. See LICENSE.

# The security-release warning depends on the running PHP, which a test cannot
# change. Instead each test runs a copy of install.php whose table entry for
# the running branch is rewritten, and stops it right after the pre-flight
# checks by pointing --dir at an existing site.

load 'helpers/test_helpers'

setup() {
    SITE_DIR="$BATS_TEST_TMPDIR/site"
    mkdir -p "$SITE_DIR"
    touch "$SITE_DIR/artisan"
    BRANCH="$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')"
    CURRENT="$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION . "." . PHP_RELEASE_VERSION;')"
}

# Write a copy of install.php whose table maps branch $1 to version $2.
installer_with_table() {
    local copy="$BATS_TEST_TMPDIR/install.php"
    # PHP rather than sed: a newline in a sed replacement differs between BSD
    # and GNU sed.
    php -r '
        [, $src, $branch, $version] = $argv;
        echo preg_replace(
            "/(define\(\x27DIXLASE_PHP_SECURITY_RELEASES\x27, \[)[^\]]*\]/",
            "\$1\n    \x27{$branch}\x27 => \x27{$version}\x27,\n]",
            file_get_contents($src),
            1
        );
    ' "$INSTALL_PHP" "$1" "$2" > "$copy"
    echo "$copy"
}

run_checks() {
    run php "$1" --non-interactive --yes --dir="$SITE_DIR"
}

@test "php version: an older patch release than the table gets a warning" {
    copy="$(installer_with_table "$BRANCH" "$BRANCH.999")"
    run_checks "$copy"
    [ "$status" -eq 1 ]
    [[ "$output" == *"PHP $CURRENT is missing security fixes. Update PHP to $BRANCH.999 or later."* ]]
    [[ "$output" == *"docs/index.md#keeping-php-up-to-date"* ]]
    # The warning never stops the run: it goes on to the existing-site check.
    [[ "$output" == *"An existing installation was found"* ]]
}

@test "php version: the table's own version gets no warning" {
    copy="$(installer_with_table "$BRANCH" "$CURRENT")"
    run_checks "$copy"
    [[ "$output" == *"✓ PHP $CURRENT"* ]]
    [[ "$output" != *"missing security fixes"* ]]
}

@test "php version: a branch missing from the table gets no warning" {
    copy="$(installer_with_table "7.4" "7.4.999")"
    run_checks "$copy"
    [[ "$output" == *"✓ PHP $CURRENT"* ]]
    [[ "$output" != *"missing security fixes"* ]]
}

@test "php version: the table covers every branch CI runs" {
    for branch in 8.3 8.4 8.5; do
        grep -qE "'$branch' => '$branch\.[0-9]+'" "$INSTALL_PHP"
    done
}
