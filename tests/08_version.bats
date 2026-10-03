#!/usr/bin/env bats
# SPDX-License-Identifier: MIT
# Copyright (c) 2026 exc-D inc. Licensed under the MIT License. See LICENSE.

load 'helpers/test_helpers'

installer_version() {
    grep -oE "DIXLASE_INSTALLER_VERSION', '[0-9]+\.[0-9]+\.[0-9]+'" "$INSTALL_PHP" | grep -oE '[0-9]+\.[0-9]+\.[0-9]+'
}

@test "version: the banner shows the installer version" {
    version="$(installer_version)"
    [ -n "$version" ]
    run php "$INSTALL_PHP" --help
    [ "$status" -eq 0 ]
    [[ "$output" == *"Installer v$version"* ]]
}

@test "version: the constant matches the newest CHANGELOG entry (en and ja)" {
    version="$(installer_version)"
    for f in CHANGELOG.md CHANGELOG.ja.md; do
        newest="$(grep -m1 -oE '^## \[[0-9]+\.[0-9]+\.[0-9]+\]' "$PROJECT_ROOT/$f" | tr -d '#[] ')"
        [ "$newest" = "$version" ]
    done
}
