#!/usr/bin/env bash
# Static contract for the two Polylang evidence sweeps.  Docker is intentionally
# absent: this pins the live command boundaries and prevents a future fixture
# from silently widening the exact artifact/topology claim.
# conformance/asserts.sh rejects callers without fail() at source time, so the
# ordering is part of each live harness's load contract, not a runtime path.
set -euo pipefail
cd "$(dirname "$0")/../../../.."
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }

LIVE_FILES=(
  adapter-packages/polylang/tests/live/regress_polylang_multisite_refusal.sh
  integration-scenarios/polylang-tec-rewrite-coinstall/tests/live/regress_polylang_tec_rewrite_coinstall.sh
)

assert_fail_before_asserts() {
  local file="$1" fail_line source_line
  fail_line=$(grep -nE '^fail\(\) \{' "$file" | head -1 | cut -d: -f1)
  source_line=$(grep -nE '^\. conformance/asserts\.sh$' "$file" | head -1 | cut -d: -f1)
  [ -n "$fail_line" ] || fail "$file does not define fail()"
  [ -n "$source_line" ] || fail "$file does not source conformance/asserts.sh"
  [ "$fail_line" -lt "$source_line" ] \
    || fail "$file sources conformance/asserts.sh before defining fail()"
}

for file in "${LIVE_FILES[@]}"; do
  [ -x "$file" ] || fail "missing executable $file"
  assert_fail_before_asserts "$file"
done
printf 'PASS: every Polylang live fixture defines fail() before sourcing shared asserts\n'

# Move the exact declaration past the asserts import even when another shared
# harness import separates them. An adjacency-dependent mutation became a
# no-op once caller-local database selection was added between those lines.
TMP=$(mktemp -d "${TMPDIR:-/tmp}/wprism-polylang-live-guard.XXXXXX")
trap 'rm -rf "$TMP"' EXIT
for file in "${LIVE_FILES[@]}"; do
  mutated="$TMP/$(basename "$file")"
  awk '
    !done && $0 ~ /^fail\(\) \{ printf/ {
      fail_line=$0
      next
    }
    !done && fail_line != "" && $0 == ". conformance/asserts.sh" {
      print
      print fail_line
      done=1
      next
    }
    { print }
    END { if (!done) exit 1 }
  ' "$file" > "$mutated" || fail "could not construct an ordering mutation for $file"
  if (fail() { return 1; }; assert_fail_before_asserts "$mutated") >/dev/null 2>&1; then
    fail "ordering mutation unexpectedly passed for $file"
  fi
done
printf 'PASS: fail-before-asserts ordering rejects a source-before-fail mutation for every Polylang live fixture\n'
MS=adapter-packages/polylang/tests/live/regress_polylang_multisite_refusal.sh
TEC=integration-scenarios/polylang-tec-rewrite-coinstall/tests/live/regress_polylang_tec_rewrite_coinstall.sh
CONF=adapter-packages/polylang/tests/conformance/check.sh
[ -x "$MS" ] || fail "missing executable $MS"
[ -x "$TEC" ] || fail "missing executable $TEC"
[ -f "$CONF" ] || fail "missing conformance check $CONF"
for needle in \
  'fetch_artifact polylang 3.8.6' \
  'core multisite-convert' \
  'multisite_unsupported' \
  'state.capture-staging' \
  'Polylang plugin bytes' \
  '_transient_doing_cron' \
  'polylang_graph_fingerprint'; do
  grep -Fq "$needle" "$MS" || fail "multisite fixture lost required guard: $needle"
done
if grep -Eq "NOT LIKE|_transient_%" "$MS"; then
  fail 'multisite fixture widened its exact cron-lease exclusion to a transient wildcard'
fi
for needle in \
  'fetch_artifact' \
  'provider:polylang-nav-menus/synchronize_runtime' \
  'native:rewrite.flush' \
  'pll_modify_rewrite_rule' \
  'uncategorized-fr' \
  'Polylang Principal Français' \
  'theme_mods_twentytwentyone' \
  'capture emitted a warning' \
  'exec -T --user root wp2' \
  "<<'PHPEOF'" \
  'unsupported open Polylang rewrite filter' \
  'apply_in_progress' \
  'clean no-op recapture' \
  '(.actions|length)==0'; do
  grep -Fq "$needle" "$TEC" || fail "co-install fixture lost required guard: $needle"
done
if grep -Fq 'file_put_contents($path,$bytes)' "$TEC"; then
  fail 'co-install fixture reintroduced unprivileged/interpolated hostile callback installation'
fi
for needle in 'add_term_meta(' 'update_term_meta(' "[['Hello', 'Bonjour']]" 'exact empty string-catalog sentinel entered canonical authored state'; do
  grep -Fq "$needle" "$CONF" || fail "Polylang string-catalog fixture lost native-value setup: $needle"
done
for needle in \
  'get_term_by("slug","uncategorized-fr","category")' \
  'wp_get_nav_menu_object("Polylang Principal Français")' \
  'Polylang French projection source graph is incoherent' \
  'injected Polylang native-catalog verification child failure' \
  'wprism identity-export --repo=/siterepo --out=/siterepo/.tmp-polylang-remove-all-identity.json' \
  'widget identity history is missing' \
  'identity sidecar witness mismatch' \
  'db export /siterepo/.tmp-polylang-remove-all.sql --add-drop-table' \
  'db import /siterepo/.tmp-polylang-remove-all.sql' \
  'RETRY_RC=0' \
  'Polylang provider retry after exact repair failed'; do
  grep -Fq "$needle" "$CONF" || fail "Polylang provider-retry fixture lost coherent source or surfaced failure guard: $needle"
done
if grep -Eq 'wp_conf1 term meta (add|update).*_pll_strings_translations.*a:[0-9]+:' "$CONF"; then
  fail 'Polylang string-catalog fixture passes serialized-looking text through maybe_serialize'
fi
grep -Fq 'manifests:["core","polylang","the-events-calendar"]' "$TEC" \
  || fail 'co-install fixture widened or dropped its manifest set'
grep -Fq 'taxonomies:["category","post_tag","language","term_language","post_translations","term_translations","tribe_events_cat"]' "$TEC" \
  || fail 'co-install fixture no longer keeps core-owned nav_menu out of generic term scope'
if grep -Fq '"term_translations","nav_menu"' "$TEC"; then
  fail 'co-install fixture reintroduced the term/menu identity contradiction'
fi
grep -Fq 'post_types:["post","page","attachment","wp_block","tribe_events","tribe_venue","tribe_organizer"]' "$TEC" \
  || fail 'co-install fixture no longer keeps core-owned nav_menu_item out of generic post scope'
if grep -Fq '"wp_block","nav_menu_item"' "$TEC"; then
  fail 'co-install fixture reintroduced the post/menu-item identity contradiction'
fi
grep -Fq 'manifests:["core","polylang"]' "$MS" \
  || fail 'multisite fixture does not use the dedicated Polylang manifest set'
printf 'PASS: Polylang exact multisite and bounded Polylang+TEC live fixtures are statically guarded\n'
