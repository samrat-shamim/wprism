#!/usr/bin/env bash
# DUO-3469: the offline corpus must not report green while PHP emits a
# warning/deprecation/fatal/parse diagnostic. The guard deliberately allows
# an ordinary shell retry warning, which is not a PHP diagnostic.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../../../.." && pwd)"
GUARD="$ROOT/sandbox/tests/offline_diagnostics_guard.sh"

fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }
pass() { printf 'ok: %s\n' "$*"; }

[ -f "$GUARD" ] || fail "offline diagnostics guard is missing"

if ! output=$(
    bash "$GUARD" bash -c \
        'printf "%s\\n" "Warning: fetch_artifact: retrying the pinned URL"; printf "%s\\n" "ok: no PHP warnings"'
); then
    fail "the guard rejected an intentional shell retry warning"
fi
grep -qF 'Warning: fetch_artifact: retrying the pinned URL' <<<"$output" \
    || fail "the guard did not preserve the shell retry warning in command output"

diagnostic_rc=0
diagnostic_output=$(
    bash "$GUARD" bash -c \
        'printf "%s\\n" "PHP Deprecated: Method ReflectionMethod::setAccessible() is deprecated since 8.5 in fixture.php on line 7"; printf "%s\\n" "Deprecated: Method ReflectionMethod::setAccessible() is deprecated since 8.5 in fixture.php on line 7"' 2>&1
) || diagnostic_rc=$?
[ "$diagnostic_rc" -eq 1 ] \
    || fail "the guard accepted PHP diagnostic output (exit $diagnostic_rc)"
grep -qF 'FAIL: offline command emitted PHP diagnostics:' <<<"$diagnostic_output" \
    || fail "the guard's refusal did not name PHP diagnostics"

startup_rc=0
bash "$GUARD" bash -c \
    'printf "%s\\n" "PHP Warning: unable to load a startup extension"' \
    >/dev/null 2>&1 || startup_rc=$?
[ "$startup_rc" -eq 1 ] \
    || fail "the guard accepted a PHP-prefixed startup warning (exit $startup_rc)"

status_rc=0
bash "$GUARD" bash -c 'exit 7' >/dev/null 2>&1 || status_rc=$?
[ "$status_rc" -eq 7 ] \
    || fail "the guard changed a non-diagnostic command status (got $status_rc)"

pass "offline guard rejects PHP diagnostics, allows shell retry warnings, and preserves command status"
