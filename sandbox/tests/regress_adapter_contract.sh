#!/usr/bin/env bash
# Regression — DUO-3222/DUO-3243: version-pinned adapter compatibility
# contract plus optional content-addressed site manifest pins (Policy::load()
# validators, Policy::theme_ranges(), RepositoryCompiler's existing
# per-manifest digest/resolved_adapters(), and the real manifest-pin CLI
# handler).
#
# Every one of these is pure PHP — no $wpdb, no WordPress bootstrap, by
# design (RepositoryCompiler's own docblock: the tree becomes a validated
# IR "before Tokens, Ledger, Capture, or a target query can be
# constructed") — so this whole regression runs offline, on real manifest
# fixture files under a scratch DUO_MANIFESTS_DIR, using the REAL,
# unmodified agent/src/{Canon,Policy,RepositoryCompiler,Deploy}.php. No
# docker, no sandbox pair. Same idiom as
# sandbox/tests/regress_capture_publish.sh (DUO-3213).
#
# What this does NOT cover, because it genuinely needs a live WordPress:
# Deploy::code_mismatch()'s live version read. Its THEME leg is proven by
# sandbox/tests/regress_adapter_theme_range.sh (same function, live pair).
# Its PLUGIN leg has no live proof right now: the only one was built on the
# duo-loop-demo-versioned demo manifest and was deleted with it, so a
# manifest-declared plugin version_range is exercised offline (in_range()
# via Reflection, below) but not against a real installed plugin.
set -euo pipefail
cd "$(dirname "$0")"   # -> sandbox/tests/
say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }

command -v php >/dev/null || fail "php required on PATH"

say "php -l syntax check (harness + every engine file it exercises)"
php -l regress_adapter_contract.php >/dev/null || fail "regress_adapter_contract.php has a syntax error"
php -l ../../agent/src/Policy/Policy.php >/dev/null || fail "agent/src/Policy/Policy.php has a syntax error"
php -l ../../agent/src/Repository/RepositoryCompiler.php >/dev/null || fail "agent/src/Repository/RepositoryCompiler.php has a syntax error"
php -l ../../agent/src/Promotion/Deploy.php >/dev/null || fail "agent/src/Promotion/Deploy.php has a syntax error"
php -l ../../agent/src/Command/Cli.php >/dev/null || fail "agent/src/Command/Cli.php has a syntax error"
pass "no syntax errors"

say "running the offline harness (validators, site pins, CLI emission, digest determinism, in_range edges)"
php regress_adapter_contract.php || fail "regress_adapter_contract.php reported failing checks (see output above)"

printf '\n\033[1;32m✔ REGRESS_ADAPTER_CONTRACT PASSED\033[0m\n'
