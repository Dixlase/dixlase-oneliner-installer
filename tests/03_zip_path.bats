#!/usr/bin/env bats

load 'helpers/test_helpers'

setup() {
    INSTALL_DIR="$BATS_TEST_TMPDIR/dixlase"
    REL_DIR="$BATS_TEST_TMPDIR/releases"
    mkdir -p "$REL_DIR"
    VERSION="$(build_fake_release "$REL_DIR")"
    start_mock_github "$REL_DIR" "$VERSION"
}

teardown() {
    stop_mock_github
}

@test "zip path: pinned --version downloads, verifies, extracts, and runs post-install" {
    run run_installer_with_mock --method=zip --no-composer --non-interactive --yes \
        --version="$VERSION" --dir="$INSTALL_DIR"
    [ "$status" -eq 0 ]
    [[ "$output" == *"Checksum verified (SHA-256)"* ]]
    [[ "$output" == *"Files extracted to"* ]]
    [[ "$output" == *"Installation Complete!"* ]]
    [ -f "$INSTALL_DIR/.env" ]
    [ -f "$INSTALL_DIR/.env.example" ]
    [ -f "$INSTALL_DIR/artisan" ]
    [ -d "$INSTALL_DIR/storage" ]
    [ -d "$INSTALL_DIR/bootstrap/cache" ]
}

@test "zip path: latest version is fetched from API when --version omitted" {
    run run_installer_with_mock --method=zip --no-composer --non-interactive --yes \
        --dir="$INSTALL_DIR"
    [ "$status" -eq 0 ]
    [[ "$output" == *"Latest version: $VERSION"* ]]
    [ -f "$INSTALL_DIR/.env" ]
}

@test "zip path: tampered archive triggers checksum failure and abort" {
    # Corrupt the served zip after build_fake_release has computed the genuine checksum.
    printf 'garbage' >> "$MOCK_SERVE_ROOT/releases/download/v$VERSION/dixlase-v$VERSION.zip"

    run run_installer_with_mock --method=zip --no-composer --non-interactive --yes \
        --version="$VERSION" --dir="$INSTALL_DIR"
    [ "$status" -ne 0 ]
    [[ "$output" == *"Checksum mismatch"* ]] || [[ "$output" == *"verification failed"* ]]
    # Refuse to leave a half-installed tree behind.
    [ ! -f "$INSTALL_DIR/.env" ]
}
