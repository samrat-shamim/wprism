#!/usr/bin/env bash
# Regression — DUO-3249: Policy.php's core-yields-to-plugin manifest
# reclassification precedence (rule_details(), authored_options()'s
# reclassification-away reconciliation, active_reclassifications()'s
# plan-visible reporting). Pure PHP, no docker: uses fake fixture manifests
# via DUO_MANIFESTS_DIR (one literally named "core"), never the real
# manifests/core.json or manifests/polylang.json. See
# sandbox/tests/regress_polylang_default_category.sh for the live proof
# against the real shipped manifests and a real Polylang install —
# default_category actually converging correctly across two languages,
# matching this codebase's existing split between offline Policy-layer
# tests and live Apply-layer tests (see regress_env_options_policy.sh's
# own docblock for the identical split on DUO-3232).
set -euo pipefail
cd "$(dirname "$0")"   # -> sandbox/tests/
say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }

command -v php >/dev/null || fail "php required on PATH"

say "php -l syntax check"
php -l regress_manifest_reclassification_policy.php >/dev/null || fail "regress_manifest_reclassification_policy.php has a syntax error"
pass "no syntax errors"

say "running the offline harness (Policy.php: rule_details() core-yields-to-plugin precedence, authored_options() reconciliation, active_reclassifications() reporting)"
php regress_manifest_reclassification_policy.php || fail "regress_manifest_reclassification_policy.php reported failing checks (see output above)"

printf '\n\033[1;32m✔ REGRESS_MANIFEST_RECLASSIFICATION_POLICY PASSED\033[0m\n'
