#!/usr/bin/env bash
# Run a command and refuse a green result when PHP printed a warning,
# deprecation, fatal, or parse diagnostic. Shell-level retry warnings are
# allowed: they do not carry PHP's diagnostic framing or a PHP source location.
set -euo pipefail

if [ "$#" -eq 0 ]; then
    echo "usage: $0 COMMAND [ARG...]" >&2
    exit 2
fi

SCRATCH=$(mktemp -d /tmp/wprism-offline-diagnostics.XXXXXX)
cleanup() { rm -rf "$SCRATCH"; }
trap cleanup EXIT INT TERM

if "$@" >"$SCRATCH/output" 2>&1; then
    COMMAND_RC=0
else
    COMMAND_RC=$?
fi

cat "$SCRATCH/output"

# PHP CLI prints diagnostics twice when display_errors=1: once with the
# `PHP ` prefix to stderr and once without it to stdout. The source-location
# suffix distinguishes the latter from intentional shell messages such as
# `Warning: fetch_artifact: retrying ...`.
PHP_DIAGNOSTICS=$(grep -En \
    '^(PHP (Deprecated|Warning|Fatal error|Parse error):|((Deprecated|Warning|Fatal error|Parse error):.*( in | on line )))' \
    "$SCRATCH/output" || true)
if [ -n "$PHP_DIAGNOSTICS" ]; then
    echo "FAIL: offline command emitted PHP diagnostics:" >&2
    printf '%s\n' "$PHP_DIAGNOSTICS" >&2
    [ "$COMMAND_RC" -ne 0 ] && exit "$COMMAND_RC"
    exit 1
fi

exit "$COMMAND_RC"
