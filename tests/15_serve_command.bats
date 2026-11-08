#!/usr/bin/env bats
# SPDX-License-Identifier: MIT
# Copyright (c) 2026 exc-D inc. Licensed under the MIT License. See LICENSE.

# The completion message prints the core's short serve command when the
# installed core ships it, and the long command for an older core.

load 'helpers/test_helpers'

setup() {
    INSTALL_DIR="$BATS_TEST_TMPDIR/dixlase"
    FAKE_BIN="$BATS_TEST_TMPDIR/bin"
    mkdir -p "$FAKE_BIN"

    # A fake composer that lays out the project, with scripts/serve.php only
    # when FAKE_SERVE_SCRIPT is set.
    cat > "$FAKE_BIN/composer" <<EOT
#!/bin/sh
if [ "\$1" = "--version" ]; then
    echo "Composer version 2.8.0 (fake)"
    exit 0
fi
dir="\$3"
mkdir -p "\$dir"
(cd "$FIXTURES_DIR/dixlase-fake" && tar cf - .) | (cd "\$dir" && tar xf -)
if [ -n "\$FAKE_SERVE_SCRIPT" ]; then
    mkdir -p "\$dir/scripts"
    echo '<?php' > "\$dir/scripts/serve.php"
fi
exit 0
EOT
    chmod +x "$FAKE_BIN/composer"
}

# "|| false" makes a failed [[ ]] fail the test on bash 3.2 (macOS) too,
# where errexit ignores it.

@test "serve command: the short form when the core ships scripts/serve.php" {
    run env FAKE_SERVE_SCRIPT=1 PATH="$FAKE_BIN:$PATH" php "$INSTALL_PHP" --method=composer --no-build \
        --non-interactive --yes --dir="$INSTALL_DIR"
    [ "$status" -eq 0 ]

    [[ "$output" == *"cd $INSTALL_DIR && php scripts/serve.php"* ]] || false
    [[ "$output" != *"-S 127.0.0.1:8000"* ]] || false
    [[ "$output" != *"Start it from public/"* ]] || false
}

@test "serve command: the long form for a core without scripts/serve.php" {
    run env PATH="$FAKE_BIN:$PATH" php "$INSTALL_PHP" --method=composer --no-build \
        --non-interactive --yes --dir="$INSTALL_DIR"
    [ "$status" -eq 0 ]

    [[ "$output" == *"cd $INSTALL_DIR/public && "* ]] || false
    [[ "$output" == *"-S 127.0.0.1:8000"* ]] || false
    [[ "$output" == *"Start it from public/ as shown."* ]] || false
    [[ "$output" != *"php scripts/serve.php"* ]] || false
}
