#!/usr/bin/env bash
# Regression — DUO-3260: URL-query reference codec (?p=/?page_id=/
# ?attachment_id=).
#   (1) agent/src/Grammar/Tokens.php's tokenize_text()/detokenize_text(): now
#       also rewrite/restore these three WordPress-core query-string
#       parameters, {{home}}-anchored (never touches an external URL's
#       own unrelated ?p=), mirroring Blocks.php's/Shortcodes.php's own
#       dangling-vs-unscoped triage a third time.
#   (2) agent/src/Review/Lint.php's new unrewritten_url_query_ref finding
#       (deliberately un-anchored -- a wide-net signal, not a rewrite;
#       see its own docblock for why that's not an inconsistency with
#       (1)'s own safety-anchored scope).
#
# Pure PHP, no docker, no WordPress bootstrap: regress_url_query_refs.php
# runs the real, unmodified agent/src/{Canon,Policy,Tokens,Ledger,Pending,
# Lint}.php against hand-built fixtures, with only a vendored block-parser
# stub (Lint::scan_tree()'s own unconditional parse_blocks() call, needed
# even though this mechanism itself is pure regex text handling) and a
# minimal fake $wpdb standing in for a real database. Safe to run anywhere
# `php` is on PATH; touches no sandbox/siterepo state.
set -euo pipefail
cd "$(dirname "$0")"   # -> sandbox/tests/
say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }

command -v php >/dev/null || fail "php required on PATH"

say "php -l syntax check (harness + support stub)"
php -l regress_url_query_refs.php >/dev/null || fail "regress_url_query_refs.php has a syntax error"
php -l ../../support/wp-block-parser-stub.php >/dev/null || fail "support/wp-block-parser-stub.php has a syntax error"
pass "no syntax errors"

say "running the offline harness (Tokens.php url-query-ref rewrite/restore + Lint.php's new finding)"
php regress_url_query_refs.php || fail "regress_url_query_refs.php reported failing checks (see output above)"

say "regression: regress_block_refs.php and regress_shortcode_refs.php must still pass"
php -l regress_block_refs.php >/dev/null || fail "regress_block_refs.php has a syntax error"
php regress_block_refs.php || fail "regress_block_refs.php reported failing checks -- DUO-3260 regressed the block-refs mechanism"
php -l regress_shortcode_refs.php >/dev/null || fail "regress_shortcode_refs.php has a syntax error"
php regress_shortcode_refs.php || fail "regress_shortcode_refs.php reported failing checks -- DUO-3260 regressed the shortcode-refs mechanism"

printf '\n\033[1;32m✔ REGRESS_URL_QUERY_REFS PASSED\033[0m\n'
