#!/usr/bin/env bash
# Regression — DUO-3232: Policy.php's validate_env_options()/env_options()
# wiring for env-bound value provisioning (manifest-declared class:"env"
# options with a mandatory 'required' boolean). Pure PHP, no docker: uses
# fake fixture manifests via DUO_MANIFESTS_DIR, never the real shipped
# manifests. See sandbox/tests/regress_env_set.sh for the live proof this
# file deliberately does not attempt — Apply::set_env_option()'s actual
# wp_options write, site.duo.json policy-override precedence, `wp duo
# plan`'s env_missing rendering, and `wp duo env-set` end-to-end — matching
# this codebase's existing split between offline Policy-layer tests and
# live Apply-layer tests (see regress_regen_dependency_policy.sh's own
# docblock for the same split on DUO-3234).
set -euo pipefail
cd "$(dirname "$0")"   # -> sandbox/tests/
say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }

command -v php >/dev/null || fail "php required on PATH"

say "php -l syntax check"
php -l regress_env_options_policy.php >/dev/null || fail "regress_env_options_policy.php has a syntax error"
pass "no syntax errors"

say "running the offline harness (Policy.php: validate_env_options() load-time gate, env_options() enumeration/merge/ksort/autoload wiring)"
php regress_env_options_policy.php || fail "regress_env_options_policy.php reported failing checks (see output above)"

printf '\n\033[1;32m✔ REGRESS_ENV_OPTIONS_POLICY PASSED\033[0m\n'
