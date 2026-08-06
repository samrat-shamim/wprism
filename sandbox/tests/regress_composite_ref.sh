#!/usr/bin/env bash
# Regression — DUO-3235: identity.mode=composite_ref, the typed-snapshot
# grammar's new representation for a PURE JOIN table with no surrogate
# primary key (task #125; proving fixture PMPro's pmpro_memberships_pages —
# see manifests/paid-memberships-pro.json and agent/src/Snapshot.php's own
# docblock, "Identity: three modes").
#
# Pure PHP, no docker, no WordPress bootstrap: regress_composite_ref.php
# runs the real, unmodified agent/src/{Canon,Policy,Uuid,Secrets,Ledger,
# Tokens,Snapshot}.php against hand-built fixtures, with a minimal fake
# $wpdb standing in for a real database (see the PHP file's own docblock for
# exactly what it fakes and why). Safe to run anywhere `php` is on PATH;
# touches no sandbox/siterepo state. Complements, not replaces,
# sandbox/tests/regress_pmpro_composite_ref.sh (a live, docker-based round-
# trip against a real PMPro install and a real second environment) — this
# file cannot reach Apply::build_plan() by design (Snapshot.php's own
# "Engine boundary" — it depends only on Policy/Ledger/Tokens/Canon/Uuid,
# never Apply/Capture), so the "no update bucket" plan-level claim is the
# live script's job, not this one's.
set -euo pipefail
cd "$(dirname "$0")"   # -> sandbox/tests/
say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }

command -v php >/dev/null || fail "php required on PATH"

say "php -l syntax check"
php -l regress_composite_ref.php >/dev/null || fail "regress_composite_ref.php has a syntax error"
pass "no syntax errors"

say "running the offline harness (Snapshot.php composite_ref: schema assertion, uuid-over-referenced-tuple derivation, structural throws, phase1-noop/phase2-upsert, delete, packed-id budget)"
php regress_composite_ref.php || fail "regress_composite_ref.php reported failing checks (see output above)"

printf '\n\033[1;32m✔ REGRESS_COMPOSITE_REF PASSED\033[0m\n'
