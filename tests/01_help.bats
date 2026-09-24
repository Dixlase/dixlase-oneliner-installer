#!/usr/bin/env bats

load 'helpers/test_helpers'

@test "help: --help prints usage and key options" {
    run php "$INSTALL_PHP" --help
    [ "$status" -eq 0 ]
    [[ "$output" == *"Usage:"* ]]
    [[ "$output" == *"Options:"* ]]
    [[ "$output" == *"--method=MODE"* ]]
    [[ "$output" == *"--non-interactive"* ]]
    [[ "$output" == *"-y, --yes"* ]]
}

@test "help: short -h flag also prints usage" {
    run php "$INSTALL_PHP" -h
    [ "$status" -eq 0 ]
    [[ "$output" == *"Usage:"* ]]
}

@test "help: works when the script is piped in (curl | php form)" {
    # The pipe form is the documented entry point, and it is the case where
    # PHP <= 8.2 leaves STDIN/STDOUT/STDERR undefined. This suite only runs on
    # 8.3+, where they are always defined, so this guards the plumbing (arg
    # passing after `--`, banner on stdout) rather than the 8.2 fallback itself.
    run bash -c "php -- --help < '$INSTALL_PHP'"
    [ "$status" -eq 0 ]
    [[ "$output" == *"Usage:"* ]]
    [[ "$output" == *"Dixlase Installer"* ]]
}

@test "help: ja-converted install.php prints translated headings" {
    stage_project_in "$BATS_TEST_TMPDIR"
    (cd "$BATS_TEST_TMPDIR" && ./convert-comments.sh ja install.php) >/dev/null

    run php "$BATS_TEST_TMPDIR/install.php" --help
    [ "$status" -eq 0 ]
    [[ "$output" == *"使い方"* ]]
    [[ "$output" == *"オプション"* ]]
    [[ "$output" == *"配布方式"* ]]
}
