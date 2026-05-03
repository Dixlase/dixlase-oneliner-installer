#!/usr/bin/env bats

load 'helpers/test_helpers'

@test "args: --method=bogus exits non-zero with helpful message" {
    run php "$INSTALL_PHP" --method=bogus --non-interactive --yes
    [ "$status" -ne 0 ]
    [[ "$output" == *"Invalid --method value: bogus"* ]]
    [[ "$output" == *"auto, composer, or zip"* ]]
}

@test "args: unknown flags are silently ignored (no fatal)" {
    # Unknown flags fall through parse_args; the run will fail later for other
    # reasons (no real release), but argument parsing itself must not error.
    run php "$INSTALL_PHP" --help --foo=bar --baz
    [ "$status" -eq 0 ]
    [[ "$output" == *"Usage:"* ]]
}
