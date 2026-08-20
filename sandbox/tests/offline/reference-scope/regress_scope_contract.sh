#!/usr/bin/env bash
# Regression — DUO-3344 immutable scope-contract evidence. Pure compiler /
# policy / closure work over a scratch repository; no Docker, WordPress, or
# target APIs. The PHP harness booby-traps target option/upload calls itself.
set -euo pipefail
cd "$(dirname "$0")"

say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }

command -v php >/dev/null || fail "php required on PATH"

say "php -l syntax check (contract, shared surface projector, host/agent boundary, harness)"
for file in regress_scope_contract.php ../../../../agent/src/Repository/CanonicalSurfaces.php ../../../../agent/src/Policy/ScopeContract.php \
  ../../../../agent/src/Scope/ScopedStateOverlay.php \
  ../../../../agent/src/Policy/ScopeClosure.php ../../../../agent/src/Repository/RepositoryCompiler.php ../../../../agent/src/Policy/Policy.php \
  ../../../../agent/src/Apply/Apply.php ../../../../agent/src/Command/Cli.php ../../../../cli/duo; do
  php -l "$file" >/dev/null || fail "$file has a syntax error"
done
pass "no syntax errors"

say "running offline immutable scope-contract evidence harness"
php regress_scope_contract.php "$(cd ../../../.. && pwd)" || fail "regress_scope_contract.php reported failures"

printf '\n\033[1;32m✔ REGRESS_SCOPE_CONTRACT PASSED\033[0m\n'
