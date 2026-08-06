#!/usr/bin/env bash
# Regression — DUO-3234: Policy.php's regen_dependency()/regenerators()/
# validate_regen_dependencies() wiring for derived tables with a hard
# per-entity query-availability dependency (task #124, TEC's tec_occurrences
# shape). Pure PHP, no docker: uses a fake fixture manifest + fake
# regenerator via DUO_MANIFESTS_DIR, never the real TEC classes (which need
# a live WordPress+plugin bootstrap this file deliberately doesn't have —
# see sandbox/tests/regress_tec_regen.sh for that live proof, including the
# hard-fail + marker-retry mechanics Apply::regen_dependencies() implements,
# which this offline file does not attempt to cover — see its own docblock
# for why that half follows this codebase's existing live-test convention
# for anything touching Apply.php).
set -euo pipefail
cd "$(dirname "$0")"   # -> sandbox/tests/
say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }

command -v php >/dev/null || fail "php required on PATH"

say "php -l syntax check"
php -l regress_regen_dependency_policy.php >/dev/null || fail "regress_regen_dependency_policy.php has a syntax error"
pass "no syntax errors"

say "running the offline harness (Policy.php: regen_dependency lookup, load-time shape validation, regenerators() manifest-shipped-PHP loading)"
php regress_regen_dependency_policy.php || fail "regress_regen_dependency_policy.php reported failing checks (see output above)"

printf '\n\033[1;32m✔ REGRESS_REGEN_DEPENDENCY_POLICY PASSED\033[0m\n'
