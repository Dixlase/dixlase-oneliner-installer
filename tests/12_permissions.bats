#!/usr/bin/env bats
# SPDX-License-Identifier: MIT
# Copyright (c) 2026 exc-D inc. Licensed under the MIT License. See LICENSE.

load 'helpers/test_helpers'

setup() {
    INSTALL_DIR="$BATS_TEST_TMPDIR/dixlase"
    OUTSIDE="$BATS_TEST_TMPDIR/outside"
    REL_DIR="$BATS_TEST_TMPDIR/releases"
    mkdir -p "$REL_DIR" "$OUTSIDE/dir"
    VERSION="$(build_fake_release "$REL_DIR")"
    start_mock_github "$REL_DIR" "$VERSION"
}

teardown() {
    stop_mock_github
}

# Octal permission bits, the same on BSD and GNU (stat's flags differ).
mode_of() {
    php -r 'printf("%o", fileperms($argv[1]) & 0777);' "$1"
}

install_zip() {
    run run_installer_with_mock --method=zip --no-composer --no-build --non-interactive --yes \
        --version="$VERSION" --dir="$INSTALL_DIR" "$@"
}

@test "permissions: links under storage/ are skipped, so their targets keep their mode" {
    install_zip
    [ "$status" -eq 0 ]

    touch "$OUTSIDE/file"
    chmod 0600 "$OUTSIDE/file"
    chmod 0700 "$OUTSIDE/dir"
    ln -s "$OUTSIDE/file" "$INSTALL_DIR/storage/file-link"
    ln -s "$OUTSIDE/dir" "$INSTALL_DIR/storage/dir-link"
    touch "$INSTALL_DIR/storage/real-file"
    chmod 0600 "$INSTALL_DIR/storage/real-file"

    install_zip --force-reinstall
    [ "$status" -eq 0 ]
    [ "$(mode_of "$OUTSIDE/file")" = "600" ]
    [ "$(mode_of "$OUTSIDE/dir")" = "700" ]
    # Real entries are still set as before.
    [ "$(mode_of "$INSTALL_DIR/storage/real-file")" = "664" ]
}
