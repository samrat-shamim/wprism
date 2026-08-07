#!/usr/bin/env bash
# Regression — DUO-3272: Policy.php's menu_fields classification surface
# (menu_field_class()/menu_field_rule_details(), reusing rule_details()'s
# DUO-3249 core-yields-to-plugin precedence under a new section;
# active_menu_field_reclassifications()'s plan-visible reporting;
# validate_menu_field_classes()'s loud load-time rejection of a bad
# declaration). Pure PHP, no docker: uses fake fixture manifests via
# DUO_MANIFESTS_DIR, never the real manifests/core.json or
# manifests/polylang.json. See sandbox/tests/grind_r3a_multilingual.sh for
# the live proof against the real shipped manifests and a real Polylang
# install — menu-location capture actually staying deterministic across a
# simulated default-language flip, matching this codebase's existing split
# between offline Policy-layer tests and live Apply-layer tests (see
# regress_manifest_reclassification_policy.sh's own docblock for the
# identical split on DUO-3249).
set -euo pipefail
cd "$(dirname "$0")"   # -> sandbox/tests/
say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }

command -v php >/dev/null || fail "php required on PATH"

say "php -l syntax check"
php -l regress_menu_field_reclassification_policy.php >/dev/null || fail "regress_menu_field_reclassification_policy.php has a syntax error"
pass "no syntax errors"

say "running the offline harness (Policy.php: menu_field_class()/menu_field_rule_details() core-yields-to-plugin precedence, active_menu_field_reclassifications() reporting, validate_menu_field_classes() load-time guard)"
php regress_menu_field_reclassification_policy.php || fail "regress_menu_field_reclassification_policy.php reported failing checks (see output above)"

printf '\n\033[1;32m✔ REGRESS_MENU_FIELD_RECLASSIFICATION_POLICY PASSED\033[0m\n'
