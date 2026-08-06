#!/usr/bin/env bash
# Regression — DUO-3212: two reference-hygiene defects in the block-attrs
# path, fixed together (a stale capture and a silent gap would otherwise
# re-hide each other):
#   (1) agent/src/Lint.php's scan_blocks() exempted ANY registered
#       block_attrs path from the suspicious-ref check unconditionally,
#       regardless of whether the value actually got rewritten to a token
#       — so a declared ref whose rewrite silently failed was invisible to
#       `wp duo lint`. New finding class 'unrewritten_registered_ref'.
#   (2) agent/src/Blocks.php's walk() kept the raw env-local id
#       (`id_to_token(...) ?? (int) $v`) when a block ref failed to map,
#       instead of dropping it the way options/post_meta refs already do
#       (spec/repo-format.md "Dangling references") — the exact gap (1)'s
#       new finding class exists to catch when it already happened.
#
# Pure PHP, no docker, no WordPress bootstrap: regress_block_refs.php runs
# the real, unmodified agent/src/{Canon,Policy,Tokens,Ledger,Pending,Blocks,
# Lint}.php against hand-built fixtures, with only WordPress's block-parser
# functions (support/wp-block-parser-stub.php — a verbatim vendored copy of
# wp-includes/{blocks.php,class-wp-block-parser*.php}, see its own docblock)
# and a minimal fake $wpdb standing in for a real database. Safe to run
# anywhere `php` is on PATH; touches no sandbox/siterepo state.
set -euo pipefail
cd "$(dirname "$0")"   # -> sandbox/tests/
say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }

command -v php >/dev/null || fail "php required on PATH"

say "php -l syntax check (harness + support stub)"
php -l regress_block_refs.php >/dev/null || fail "regress_block_refs.php has a syntax error"
php -l support/wp-block-parser-stub.php >/dev/null || fail "support/wp-block-parser-stub.php has a syntax error"
pass "no syntax errors"

say "running the offline harness (Blocks.php dangling-ref drop + Lint.php unrewritten_registered_ref)"
php regress_block_refs.php || fail "regress_block_refs.php reported failing checks (see output above)"

printf '\n\033[1;32m✔ REGRESS_BLOCK_REFS PASSED\033[0m\n'
