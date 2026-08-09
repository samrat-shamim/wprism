#!/usr/bin/env bash
# Regression — DUO-3318: ownership rules for the engine's closed manifest
# vocabularies, the safe extension points around them, and the parent-scoped
# multi-column natural key those rules were written down for.
#
# The proof shape is a SECOND ADAPTER: a well-formed manifest 'b' that either
# reaches into manifest 'a''s entities (post-type behavior keys, option
# namespace, plugin claim, provider id) or tries to mint a vocabulary value the
# engine owns (table class, identity mode, ref kind, body/phase, effect
# kind/mode, widget codec). Every one is refused at manifest LOAD time, before
# any target contact, by a message that names the rejected token, prints the
# legal set, and states who owns extension.
#
# Pure PHP against the real engine files under a scratch DUO_MANIFESTS_DIR —
# no docker, no sandbox pair, no WordPress bootstrap, and deliberately no $wpdb
# stub either: that a table declaration is refusable with no database in the
# process IS the DUO-3318 grammar-split claim. Same idiom as
# sandbox/tests/regress_adapter_contract.sh (DUO-3222/DUO-3243).
#
# What this does NOT cover, because it genuinely needs a live target: the
# parent-scoped natural key through capture/apply against real environments
# with genuinely different local ids — see
# sandbox/tests/regress_parent_scoped_natural_key.sh, which owns that leg on
# its own pair.
set -euo pipefail
cd "$(dirname "$0")"   # -> sandbox/tests/
say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }

command -v php >/dev/null || fail "php required on PATH"

say "php -l syntax check (harness + every engine file it exercises)"
php -l regress_vocabulary_ownership.php >/dev/null || fail "regress_vocabulary_ownership.php has a syntax error"
php -l ../../agent/src/Policy.php >/dev/null || fail "agent/src/Policy.php has a syntax error"
php -l ../../agent/src/Snapshot.php >/dev/null || fail "agent/src/Snapshot.php has a syntax error"
php -l ../../agent/src/IdentityNotes.php >/dev/null || fail "agent/src/IdentityNotes.php has a syntax error"
php -l ../../agent/src/Apply.php >/dev/null || fail "agent/src/Apply.php has a syntax error"
php -l ../../agent/src/RepositoryCompiler.php >/dev/null || fail "agent/src/RepositoryCompiler.php has a syntax error"
pass "no syntax errors"

say "running the offline harness (ownership guards, closed vocabularies, extension paths, natural-key grammar and derivation)"
php regress_vocabulary_ownership.php || fail "regress_vocabulary_ownership.php reported failing checks (see output above)"

printf '\n\033[1;32m✔ REGRESS_VOCABULARY_OWNERSHIP PASSED\033[0m\n'
