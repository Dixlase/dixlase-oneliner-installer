#!/usr/bin/env bats
# SPDX-License-Identifier: MIT
# Copyright (c) 2026 exc-D inc. Licensed under the MIT License. See LICENSE.

# DIXLASE_INSTALL_METHOD tells the core's setup wizard how the site was
# installed. A .env this run creates ends up with "oneliner" on both delivery
# paths; a .env that predates the run is never touched.

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

# Point build_fake_release at a copy of the fixture whose .env.example carries
# the core's commented placeholder.
use_fixture_with_placeholder() {
    local fixtures="$BATS_TEST_TMPDIR/fixtures"
    mkdir -p "$fixtures"
    cp -R "$FIXTURES_DIR/dixlase-fake" "$fixtures/"
    printf '\n# How Dixlase was installed.\n# DIXLASE_INSTALL_METHOD=\n' \
        >> "$fixtures/dixlase-fake/.env.example"
    FIXTURES_DIR="$fixtures"
}

@test "install method: the ZIP path appends it when .env.example has no placeholder" {
    serve_release
    install_zip
    [ "$status" -eq 0 ]
    [[ "$output" == *"Install method recorded"* ]]
    [ "$(grep -c '^DIXLASE_INSTALL_METHOD=' "$INSTALL_DIR/.env")" -eq 1 ]
    grep -qx 'DIXLASE_INSTALL_METHOD=oneliner' "$INSTALL_DIR/.env"
}

@test "install method: the ZIP path fills the commented placeholder in place" {
    use_fixture_with_placeholder
    serve_release
    install_zip
    [ "$status" -eq 0 ]
    grep -qx 'DIXLASE_INSTALL_METHOD=oneliner' "$INSTALL_DIR/.env"
    # Set on the placeholder's line, under its explanation, not appended.
    grep -A1 -x '# How Dixlase was installed.' "$INSTALL_DIR/.env" | grep -qx 'DIXLASE_INSTALL_METHOD=oneliner'
    # A bare negated grep would not fail a bats test.
    run grep -q '^# *DIXLASE_INSTALL_METHOD=' "$INSTALL_DIR/.env"
    [ "$status" -ne 0 ]
}

@test "install method: a .env that predates the run is left alone on --force-reinstall" {
    serve_release
    install_zip
    [ "$status" -eq 0 ]
    printf 'APP_KEY=base64:keep\nDIXLASE_INSTALL_METHOD=composer\n' > "$INSTALL_DIR/.env"
    cp "$INSTALL_DIR/.env" "$BATS_TEST_TMPDIR/env.before"

    install_zip --force-reinstall
    [ "$status" -eq 0 ]
    [[ "$output" != *"Install method recorded"* ]]
    cmp "$BATS_TEST_TMPDIR/env.before" "$INSTALL_DIR/.env"
}

@test "install method: the Composer path overwrites the composer value create-project wrote" {
    # A fake composer that does what create-project does with the core's
    # post-create-project-cmd: lay out the project and mark it "composer".
    local fake_bin="$BATS_TEST_TMPDIR/bin"
    mkdir -p "$fake_bin"
    cat > "$fake_bin/composer" <<EOF
#!/bin/sh
if [ "\$1" = "--version" ]; then
    echo "Composer version 2.8.0 (fake)"
    exit 0
fi
dir="\$3"
mkdir -p "\$dir"
(cd "$FIXTURES_DIR/dixlase-fake" && tar cf - .) | (cd "\$dir" && tar xf -)
cp "\$dir/.env.example" "\$dir/.env"
printf 'DIXLASE_INSTALL_METHOD=composer\n' >> "\$dir/.env"
exit 0
EOF
    chmod +x "$fake_bin/composer"

    run env PATH="$fake_bin:$PATH" php "$INSTALL_PHP" --method=composer --no-build \
        --non-interactive --yes --dir="$INSTALL_DIR"
    [ "$status" -eq 0 ]
    [ "$(grep -c '^DIXLASE_INSTALL_METHOD=' "$INSTALL_DIR/.env")" -eq 1 ]
    grep -qx 'DIXLASE_INSTALL_METHOD=oneliner' "$INSTALL_DIR/.env"
}
