#!/usr/bin/env bash
# Offline regression — DUO-3316's manifest-declared taxonomy relationship
# object keyspace. Exercises the independent fixture plugin plus Policy,
# Capture, Lint, RepositoryCompiler, and Apply without Docker or WordPress.
set -euo pipefail
cd "$(dirname "$0")"

say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }

command -v php >/dev/null || fail "php required on PATH"

say "php syntax checks"
php -l regress_taxonomy_object_keyspace.php >/dev/null || fail "test harness has a syntax error"
php -l ../fixtures/duo-taxonomy-keyspace/duo-taxonomy-keyspace.php >/dev/null || fail "fixture plugin has a syntax error"
pass "fixture and harness parse"

say "running manifest-keyspace regression"
php regress_taxonomy_object_keyspace.php || fail "regression harness reported failing checks (see output above)"

printf '\n\033[1;32m✔ REGRESS_TAXONOMY_OBJECT_KEYSPACE PASSED\033[0m\n'
