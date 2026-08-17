#!/usr/bin/env bash
# Regression — DUO-3263: real Acf interpreter term_meta_rule()/option_rule()
# classification logic, plus one end-to-end pass through the real
# manifests/acf.json + Policy dispatch/ownership wiring. Pure PHP fixtures;
# no Docker or WordPress (same idiom as regress_interpreter_policy.sh).
set -euo pipefail
cd "$(dirname "$0")"

say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }

command -v php >/dev/null || fail "php required on PATH"

say "php -l syntax check"
php -l regress_acf_meta_interpreter.php >/dev/null || fail "regress_acf_meta_interpreter.php has a syntax error"
php -l ../../agent/src/Policy/Policy.php >/dev/null || fail "agent/src/Policy/Policy.php has a syntax error"
php -l ../../agent/src/Capture/Capture.php >/dev/null || fail "agent/src/Capture/Capture.php has a syntax error"
php -l ../../agent/src/Repository/RepositoryAuthorization.php >/dev/null || fail "agent/src/Repository/RepositoryAuthorization.php has a syntax error"
php -l ../../agent/src/Repository/RepositoryCompiler.php >/dev/null || fail "agent/src/Repository/RepositoryCompiler.php has a syntax error"
php -l ../../manifests/interpreters/acf.php >/dev/null || fail "manifests/interpreters/acf.php has a syntax error"
pass "no syntax errors"

say "running offline ACF interpreter harness"
php regress_acf_meta_interpreter.php || fail "regress_acf_meta_interpreter.php reported failing checks"

printf '\n\033[1;32m✔ REGRESS_ACF_META_INTERPRETER PASSED\033[0m\n'
