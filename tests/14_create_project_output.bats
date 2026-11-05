#!/usr/bin/env bats
# SPDX-License-Identifier: MIT
# Copyright (c) 2026 exc-D inc. Licensed under the MIT License. See LICENSE.

# The core's post-create message (scripts/post-create-message.php) is for
# someone running create-project by hand. On the Composer path the installer
# hides it and prints its own completion message instead.

load 'helpers/test_helpers'

setup() {
    INSTALL_DIR="$BATS_TEST_TMPDIR/dixlase"
    FAKE_BIN="$BATS_TEST_TMPDIR/bin"
    mkdir -p "$FAKE_BIN"

    # A fake composer that lays out the project and prints what the core's
    # post-create-project-cmd prints, in the same order.
    cat > "$FAKE_BIN/composer" <<EOF
#!/bin/sh
if [ "\$1" = "--version" ]; then
    echo "Composer version 2.8.0 (fake)"
    exit 0
fi
dir="\$3"
mkdir -p "\$dir"
(cd "$FIXTURES_DIR/dixlase-fake" && tar cf - .) | (cd "\$dir" && tar xf -)
cp "\$dir/.env.example" "\$dir/.env"
echo "Installing dixlase/dixlase-core (v9.9.9)"
echo "> @php artisan key:generate --ansi"
echo "   INFO  Application key set successfully."
echo "> @php scripts/mark-install-method.php composer"
echo "> @php scripts/post-create-message.php"
echo "  ╔══════════════════════════════════════════╗"
echo "  ║      Installation Complete!              ║"
echo "  ╚══════════════════════════════════════════╝"
echo "  Next steps:"
echo "  1. Build the front-end assets:"
echo "     cd \$dir && npm install && npm run build"
echo "  2. Expose the bundled theme's assets:"
echo "     php artisan dls:theme:symlink create DixlaseOnePage"
exit 0
EOF
    chmod +x "$FAKE_BIN/composer"
}

# "|| false" makes a failed [[ ]] fail the test on bash 3.2 (macOS) too,
# where errexit ignores it.

@test "create-project output: the core's post-create message is hidden" {
    run env PATH="$FAKE_BIN:$PATH" php "$INSTALL_PHP" --method=composer --no-build \
        --non-interactive --yes --dir="$INSTALL_DIR"
    [ "$status" -eq 0 ]

    # The core's earlier post-create lines still show.
    [[ "$output" == *"Installing dixlase/dixlase-core (v9.9.9)"* ]] || false
    [[ "$output" == *"Application key set successfully."* ]] || false

    # Its message and next steps do not.
    [[ "$output" != *"post-create-message.php"* ]] || false
    [[ "$output" != *"Build the front-end assets"* ]] || false
    [[ "$output" != *"dls:theme:symlink"* ]] || false

    # Only the installer's own completion box is left.
    [ "$(grep -c 'Installation Complete!' <<< "$output")" -eq 1 ]
    [[ "$output" == *"Dixlase installed via Composer"* ]] || false
}
