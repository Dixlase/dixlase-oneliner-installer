#!/usr/bin/env bats
# SPDX-License-Identifier: MIT
# Copyright (c) 2026 exc-D inc. Licensed under the MIT License. See LICENSE.

load 'helpers/test_helpers'

setup() {
    INSTALL_DIR="$BATS_TEST_TMPDIR/dixlase"
    REL_DIR="$BATS_TEST_TMPDIR/releases"
    mkdir -p "$REL_DIR"
}

teardown() {
    stop_mock_github
}

serve_release() {
    VERSION="$(build_fake_release "$REL_DIR")"
    start_mock_github "$REL_DIR" "$VERSION"
}

install_zip() {
    run run_installer_with_mock --method=zip --no-composer --no-build --non-interactive --yes \
        --version="$VERSION" --dir="$INSTALL_DIR" "$@"
}

@test "storage link: public/storage is a relative link that survives a move" {
    serve_release
    install_zip
    [ "$status" -eq 0 ]
    [[ "$output" == *"Storage symlink created"* ]]
    [ "$(readlink "$INSTALL_DIR/public/storage")" = "../storage/app/public" ]
    [ -d "$INSTALL_DIR/public/storage" ]
    [ ! -e "$INSTALL_DIR/.storage-link-by-artisan" ]

    mv "$INSTALL_DIR" "$BATS_TEST_TMPDIR/moved"
    [ -d "$BATS_TEST_TMPDIR/moved/public/storage" ]
}

@test "storage link: an existing link is left alone on --force-reinstall" {
    serve_release
    install_zip
    [ "$status" -eq 0 ]
    rm "$INSTALL_DIR/public/storage"
    ln -s "$INSTALL_DIR/storage/app/public" "$INSTALL_DIR/public/storage"

    install_zip --force-reinstall
    [ "$status" -eq 0 ]
    [[ "$output" == *"Storage symlink already exists"* ]]
    [ "$(readlink "$INSTALL_DIR/public/storage")" = "$INSTALL_DIR/storage/app/public" ]
}

@test "storage link: without storage/app/public it falls back to artisan" {
    # build_fake_release packs $FIXTURES_DIR/dixlase-fake: point it at a copy
    # without the link target.
    local fixtures="$BATS_TEST_TMPDIR/fixtures"
    mkdir -p "$fixtures"
    cp -R "$FIXTURES_DIR/dixlase-fake" "$fixtures/"
    rm -rf "$fixtures/dixlase-fake/storage/app"
    FIXTURES_DIR="$fixtures"
    serve_release
    install_zip
    [ "$status" -eq 0 ]
    [ -e "$INSTALL_DIR/.storage-link-by-artisan" ]
    [ ! -L "$INSTALL_DIR/public/storage" ]
}
