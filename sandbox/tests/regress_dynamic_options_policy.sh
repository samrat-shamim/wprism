#!/usr/bin/env bash
# Regression — DUO-3264: Policy.php's dynamic_options primitive (fork A of
# the owner ruling, issue comment 9fd882a6) — dynamic_options()/
# resolve_dynamic_option()/dynamic_option_rule_for_name()/
# dynamic_option_rule_for_prefix()/is_dynamic_option_residue(), and
# validate_dynamic_options()'s loud load-time rejection of a bad
# declaration. Pure PHP, no docker: uses fake fixture manifests via
# DUO_MANIFESTS_DIR, never the real manifests/core.json. See
# docs/agents/DUO-3264 issue comments for the live proof against the real
# shipped manifests and two real WordPress themes (twentytwentyone,
# twentytwentyfive) on a live pair — full round trip: capture, correct
# residue exclusion, push/clone, deploy (theme switch), apply, correct
# sub-key merge into the live blob, correct ref re-resolution to a second
# environment's own local ids, byte-identical recapture — matching this
# codebase's existing split between offline Policy-layer tests and live
# Apply-layer proof (see regress_menu_field_reclassification_policy.sh's
# own docblock for the identical split on DUO-3272).
set -euo pipefail
cd "$(dirname "$0")"   # -> sandbox/tests/
say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }

command -v php >/dev/null || fail "php required on PATH"

say "php -l syntax check"
php -l regress_dynamic_options_policy.php >/dev/null || fail "regress_dynamic_options_policy.php has a syntax error"
pass "no syntax errors"

say "running the offline harness (Policy.php: dynamic_options() enumeration, resolve_dynamic_option() name-building, dynamic_option_rule_for_name() exact-match vs dynamic_option_rule_for_prefix() prefix-only match, is_dynamic_option_residue(), validate_dynamic_options() load-time guard)"
php regress_dynamic_options_policy.php || fail "regress_dynamic_options_policy.php reported failing checks (see output above)"

printf '\n\033[1;32m✔ REGRESS_DYNAMIC_OPTIONS_POLICY PASSED\033[0m\n'
