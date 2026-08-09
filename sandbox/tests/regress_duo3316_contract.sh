#!/usr/bin/env bash
# Offline DUO-3316 contract regression: independent object_keyspace and
# description structured-reference declarations, two attached sidecars, the
# invalid-manifest refusal matrix, normal/frozen policy loads, and compiler
# raw-id versus canonical-token gates.  No Docker, WordPress, or target query.
set -euo pipefail
cd "$(dirname "$0")"

say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }

command -v php >/dev/null || fail "php required on PATH"

say "php -l syntax check"
php -l regress_duo3316_contract.php >/dev/null || fail "regress_duo3316_contract.php has a syntax error"
pass "no syntax errors"

say "running the offline DUO-3316 contract harness"
php regress_duo3316_contract.php || fail "regress_duo3316_contract.php reported failing checks (see output above)"

printf '\n\033[1;32m✔ REGRESS_DUO3316_CONTRACT PASSED\033[0m\n'
