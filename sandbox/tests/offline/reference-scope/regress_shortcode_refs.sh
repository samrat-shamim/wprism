#!/usr/bin/env bash
# Regression — DUO-3259: shortcode-attribute reference codec.
#   (1) agent/src/Grammar/Shortcodes.php (new): structure-aware capture/apply
#       rewriting of declared shortcode_attrs refs (gallery's id/ids/
#       include/exclude), mirroring Blocks.php's block_attrs mechanism
#       and porting task #73's dangling-vs-unscoped triage a third time.
#   (2) agent/src/Grammar/Blocks.php's $rewriteString closure: now also threads
#       every innerContent chunk through Shortcodes::capture_rewrite_
#       text()/apply_rewrite_text() -- proven via the REAL Blocks::
#       capture_rewrite() entry point (S18), not just Shortcodes.php in
#       isolation.
#   (3) agent/src/Review/Lint.php's new scan_shortcodes(): the shortcode twins
#       of unregistered_block_attr / unrewritten_registered_ref.
#
# Pure PHP, no docker, no WordPress bootstrap: regress_shortcode_refs.php
# runs the real, unmodified agent/src/{Canon,Policy,Tokens,Ledger,Pending,
# Blocks,Shortcodes,Lint}.php against hand-built fixtures, with only
# WordPress's shortcode-parsing functions (../../support/wp-shortcode-stub.php
# -- a verbatim vendored copy of wp-includes/shortcodes.php pinned to the
# SAME core version ../../support/wp-block-parser-stub.php already vendors from,
# see its own docblock), WordPress's block-parser functions (needed only
# for the S18 integration check), and a minimal fake $wpdb standing in for
# a real database. Safe to run anywhere `php` is on PATH; touches no
# sandbox/siterepo state.
set -euo pipefail
cd "$(dirname "$0")"   # -> sandbox/tests/
say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }

command -v php >/dev/null || fail "php required on PATH"

say "php -l syntax check (harness + support stubs)"
php -l regress_shortcode_refs.php >/dev/null || fail "regress_shortcode_refs.php has a syntax error"
php -l ../../support/wp-shortcode-stub.php >/dev/null || fail "support/wp-shortcode-stub.php has a syntax error"
php -l ../../support/wp-block-parser-stub.php >/dev/null || fail "support/wp-block-parser-stub.php has a syntax error"
pass "no syntax errors"

say "running the offline harness (Shortcodes.php rewrite semantics + Blocks.php wiring + Lint.php shortcode findings)"
php regress_shortcode_refs.php || fail "regress_shortcode_refs.php reported failing checks (see output above)"

say "regression: regress_block_refs.php must still pass (Shortcodes.php is now a hard Blocks.php dependency)"
php -l regress_block_refs.php >/dev/null || fail "regress_block_refs.php has a syntax error"
php regress_block_refs.php || fail "regress_block_refs.php reported failing checks -- DUO-3259 regressed the existing block-refs mechanism"

printf '\n\033[1;32m✔ REGRESS_SHORTCODE_REFS PASSED\033[0m\n'
