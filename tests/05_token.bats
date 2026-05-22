#!/usr/bin/env bats

# These tests run against a mock GitHub that rejects every request without a
# matching token, so they exercise both the API call and the download under
# authentication. --version is intentionally omitted so the API path runs too.

load 'helpers/test_helpers'

setup() {
    INSTALL_DIR="$BATS_TEST_TMPDIR/dixlase"
    REL_DIR="$BATS_TEST_TMPDIR/releases"
    mkdir -p "$REL_DIR"
    VERSION="$(build_fake_release "$REL_DIR")"
    TOKEN="ghp_testtoken1234567890"
    start_mock_github "$REL_DIR" "$VERSION" "$TOKEN"
}

teardown() {
    stop_mock_github
}

@test "token: DIXLASE_GITHUB_TOKEN authenticates the API call and the download" {
    run run_installer_with_mock_token DIXLASE_GITHUB_TOKEN "$TOKEN" \
        --method=zip --no-composer --non-interactive --yes --dir="$INSTALL_DIR"
    [ "$status" -eq 0 ]
    [[ "$output" == *"Token detected"* ]]
    [[ "$output" == *"Latest version: $VERSION"* ]]
    [[ "$output" == *"Installation Complete!"* ]]
    [ -f "$INSTALL_DIR/.env" ]
}

@test "token: the conventional GITHUB_TOKEN env var is also honoured" {
    run run_installer_with_mock_token GITHUB_TOKEN "$TOKEN" \
        --method=zip --no-composer --non-interactive --yes --dir="$INSTALL_DIR"
    [ "$status" -eq 0 ]
    [[ "$output" == *"Installation Complete!"* ]]
}

@test "token: missing token against a token-gated server fails clearly" {
    run run_installer_with_mock --method=zip --no-composer --non-interactive --yes \
        --dir="$INSTALL_DIR"
    [ "$status" -ne 0 ]
    [[ "$output" == *"Could not fetch release information"* ]]
    [ ! -f "$INSTALL_DIR/.env" ]
}

@test "token: a wrong token is rejected by the server" {
    run run_installer_with_mock_token DIXLASE_GITHUB_TOKEN "ghp_wrongtoken" \
        --method=zip --no-composer --non-interactive --yes --dir="$INSTALL_DIR"
    [ "$status" -ne 0 ]
    [[ "$output" == *"Could not fetch release information"* ]]
}
