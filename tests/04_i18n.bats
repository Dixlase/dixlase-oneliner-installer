#!/usr/bin/env bats

load 'helpers/test_helpers'

@test "i18n: convert-comments.sh ja round-trip preserves install.php byte-for-byte" {
    stage_project_in "$BATS_TEST_TMPDIR"
    cp "$BATS_TEST_TMPDIR/install.php" "$BATS_TEST_TMPDIR/install.php.original"

    (cd "$BATS_TEST_TMPDIR" && ./convert-comments.sh ja install.php >/dev/null)
    # Make sure the JA conversion actually happened.
    grep -q '使い方' "$BATS_TEST_TMPDIR/install.php"

    (cd "$BATS_TEST_TMPDIR" && ./convert-comments.sh ja install.php --reverse >/dev/null)
    diff -q "$BATS_TEST_TMPDIR/install.php" "$BATS_TEST_TMPDIR/install.php.original"
}

@test "i18n: lang/en/install.php.tsv is identity (col1 == col2)" {
    awk -F'\t' 'NF >= 2 && $1 != $2 { print "non-identity at line " NR ": [" $1 "] vs [" $2 "]"; bad=1 } END { exit bad }' \
        "$PROJECT_ROOT/lang/en/install.php.tsv"
}

@test "banner: EN renders as a 42-column box" {
    run php "$INSTALL_PHP" --help
    [ "$status" -eq 0 ]
    local line inner width
    line="$(printf '%s\n' "$output" | grep 'Dixlase Installer' | head -1)"
    inner="${line#*║}"
    inner="${inner%║*}"
    width=$(printf '%s' "$inner" | php -r 'echo mb_strwidth(stream_get_contents(STDIN), "UTF-8");')
    [ "$width" -eq 42 ]
}

@test "banner: JA renders as a 42-column box" {
    stage_project_in "$BATS_TEST_TMPDIR"
    (cd "$BATS_TEST_TMPDIR" && ./convert-comments.sh ja install.php) >/dev/null

    run php "$BATS_TEST_TMPDIR/install.php" --help
    [ "$status" -eq 0 ]
    local line inner width
    line="$(printf '%s\n' "$output" | grep 'インストーラー' | head -1)"
    inner="${line#*║}"
    inner="${inner%║*}"
    width=$(printf '%s' "$inner" | php -r 'echo mb_strwidth(stream_get_contents(STDIN), "UTF-8");')
    [ "$width" -eq 42 ]
}

@test "i18n: php -l succeeds on the JA-converted install.php" {
    stage_project_in "$BATS_TEST_TMPDIR"
    (cd "$BATS_TEST_TMPDIR" && ./convert-comments.sh ja install.php) >/dev/null
    run php -l "$BATS_TEST_TMPDIR/install.php"
    [ "$status" -eq 0 ]
    [[ "$output" == *"No syntax errors"* ]]
}
