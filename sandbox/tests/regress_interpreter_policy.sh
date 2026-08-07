#!/usr/bin/env bash
# Regression — DUO-3262: optional term/user interpreter hooks and their
# static-policy fallbacks. Pure PHP fixtures; no Docker or WordPress.
set -euo pipefail
cd "$(dirname "$0")"

say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }

command -v php >/dev/null || fail "php required on PATH"

say "php -l syntax check"
php -l regress_interpreter_policy.php >/dev/null || fail "regress_interpreter_policy.php has a syntax error"
php -l ../../agent/src/Policy.php >/dev/null || fail "agent/src/Policy.php has a syntax error"
php -l ../../agent/src/Capture.php >/dev/null || fail "agent/src/Capture.php has a syntax error"
pass "no syntax errors"

say "running offline interpreter-policy harness"
php regress_interpreter_policy.php || fail "regress_interpreter_policy.php reported failing checks"

printf '\n\033[1;32m✔ REGRESS_INTERPRETER_POLICY PASSED\033[0m\n'
