#!/usr/bin/env bash
# Regression — DUO-3344: a scope is resolved from explicit roots by closing
# over DECLARED edges, and every inclusion names the edge that caused it.
#
# The closure engine and the compiler's own reference validator now share one
# enumeration (agent/src/Repository/ReferenceGraph.php) rather than walking the tree
# twice. That is the property this suite really guards: two independent
# walkers would not fail loudly when an adapter declares a reference shape
# only one of them knows about — the validator would quietly stop guarding an
# edge, or a resolved scope would quietly ship without one of its
# dependencies. So the suite pins both consumers against the same fixture.
#
# It also pins the two defects that single enumeration retired, both of which
# were live at the parent commit:
#   1. the inline walk reused the tree's loop variable as its terms loop
#      variable, so a post carrying ANY term assignment filed its post_parent
#      edge under the last TERM's uuid — a genuine parent cycle compiled
#      clean the moment its posts were categorized;
#   2. "terms.$tax[$i]" interpolates as a string OFFSET into $tax, so every
#      term-assignment locator reported "terms.c" instead of
#      "terms.category[0]".
#
# Everything here is pure PHP on scratch fixture repositories under
# sys_get_temp_dir() with a scratch DUO_MANIFESTS_DIR — no $wpdb, no
# WordPress bootstrap, no docker, no sandbox pair, and never sandbox/siterepo
# state. That is not a convenience: RepositoryCompiler's own docblock is
# explicit that the typed IR exists "before Tokens, Ledger, Capture, or a
# target query can be constructed", and scope resolution reads only that IR,
# so a scope is a property of a repository revision rather than of any
# environment. The harness booby-traps get_option()/wp_upload_dir() to prove
# it. Same idiom as sandbox/tests/regress_adapter_contract.sh (DUO-3222).
#
# What this does NOT cover, because it needs a live WordPress: `wp duo scope`
# reaching a real target through wp-cli. That renderer is exercised live by
# the standalone pair demonstration recorded on the issue; nothing about the
# resolution itself changes when a target is present, because it never reads
# one.
set -euo pipefail
cd "$(dirname "$0")"   # -> sandbox/tests/
say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }

command -v php >/dev/null || fail "php required on PATH"

say "php -l syntax check (harness + every engine file it exercises)"
php -l regress_scope_closure.php >/dev/null || fail "regress_scope_closure.php has a syntax error"
php -l ../../agent/src/Repository/ReferenceGraph.php >/dev/null || fail "agent/src/Repository/ReferenceGraph.php has a syntax error"
php -l ../../agent/src/Policy/ScopeClosure.php >/dev/null || fail "agent/src/Policy/ScopeClosure.php has a syntax error"
php -l ../../agent/src/Repository/RepositoryCompiler.php >/dev/null || fail "agent/src/Repository/RepositoryCompiler.php has a syntax error"
php -l ../../agent/src/Policy/Policy.php >/dev/null || fail "agent/src/Policy/Policy.php has a syntax error"
php -l ../../agent/src/Command/Cli.php >/dev/null || fail "agent/src/Command/Cli.php has a syntax error"
pass "no syntax errors"

say "running the offline harness (closure, provenance, root refusals, all-roots scope, retired defects)"
php regress_scope_closure.php "$(cd ../.. && pwd)" || fail "regress_scope_closure.php reported failing checks (see output above)"

printf '\n\033[1;32m✔ REGRESS_SCOPE_CLOSURE PASSED\033[0m\n'
