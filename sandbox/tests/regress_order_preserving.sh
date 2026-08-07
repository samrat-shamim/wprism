#!/usr/bin/env bash
# Regression — DUO-3214(b) / task #123: Canon::normalize()'s alphabetical
# ksort() silently reorders order-sensitive serialized meta values.
# agent/src/OrderPreserved.php (new marker wrapper) + Canon::normalize()'s
# new branch fix this for any meta rule declaring "order_preserving": true
# (manifests/woocommerce.json's `_product_attributes` is the first user,
# closing the causation-proven WooCommerce variation-title word-reordering
# bug — see docs/grind/r3-round.md and this manifest's own note).
#
# Pure PHP, no docker, no WordPress bootstrap: exercises the real,
# unmodified agent/src/{Canon,OrderPreserved}.php directly — both have zero
# WordPress dependency. Proves the core serialization mechanism in
# isolation; Capture::build_post()'s call-site wiring and the real
# WooCommerce variation-title convergence are live sandbox-pair evidence
# instead (see the PR body). Safe to run anywhere `php` is on PATH;
# touches no sandbox/siterepo state.
set -euo pipefail
cd "$(dirname "$0")"   # -> sandbox/tests/
say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }

command -v php >/dev/null || fail "php required on PATH"

say "php -l syntax check"
php -l regress_order_preserving.php >/dev/null || fail "regress_order_preserving.php has a syntax error"
php -l ../../agent/src/Canon.php >/dev/null || fail "agent/src/Canon.php has a syntax error"
php -l ../../agent/src/OrderPreserved.php >/dev/null || fail "agent/src/OrderPreserved.php has a syntax error"
pass "no syntax errors"

say "running the offline harness (Canon::normalize()'s order-preserving branch)"
php regress_order_preserving.php || fail "regress_order_preserving.php reported failing checks (see output above)"

printf '\n\033[1;32m✔ REGRESS_ORDER_PRESERVING PASSED\033[0m\n'
