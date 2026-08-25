#!/usr/bin/env bash
# Static contract for the two Polylang evidence sweeps.  Docker is intentionally
# absent: this pins the live command boundaries and prevents a future fixture
# from silently widening the exact artifact/topology claim.
# conformance/asserts.sh rejects callers without fail() at source time, so the
# ordering is part of each live harness's load contract, not a runtime path.
set -euo pipefail
cd "$(dirname "$0")/../../../.."
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }

LIVE_FILES=(sandbox/tests/live/regress_polylang_*.sh)
[ -e "${LIVE_FILES[0]}" ] || fail 'no Polylang live fixtures found'

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

# Keep the ordering check mutation-sensitive: if either adjacent declaration is
# reverted, this temp copy must be rejected without sourcing the live harness.
TMP=$(mktemp -d "${TMPDIR:-/tmp}/duo-polylang-live-guard.XXXXXX")
trap 'rm -rf "$TMP"' EXIT
for file in "${LIVE_FILES[@]}"; do
  mutated="$TMP/$(basename "$file")"
  awk '
    !done && $0 ~ /^fail\(\) \{ printf/ {
      fail_line=$0
      if (getline source_line > 0 && source_line == ". conformance/asserts.sh") {
        print source_line
        print fail_line
        done=1
        next
      }
      print fail_line
      if (source_line != "") print source_line
      next
    }
    { print }
  ' "$file" > "$mutated"
  if (fail() { return 1; }; assert_fail_before_asserts "$mutated") >/dev/null 2>&1; then
    fail "ordering mutation unexpectedly passed for $file"
  fi
done
printf 'PASS: fail-before-asserts ordering rejects a source-before-fail mutation for every Polylang live fixture\n'
MS=sandbox/tests/live/regress_polylang_multisite_refusal.sh
TEC=sandbox/tests/live/regress_polylang_tec_rewrite_coinstall.sh
[ -x "$MS" ] || fail "missing executable $MS"
[ -x "$TEC" ] || fail "missing executable $TEC"
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
  'exec -T --user root wp2' \
  "<<'PHPEOF'" \
  'recovery_required' \
  'clean no-op recapture' \
  '(.actions|length)==0'; do
  grep -Fq "$needle" "$TEC" || fail "co-install fixture lost required guard: $needle"
done
if grep -Fq 'file_put_contents($path,$bytes)' "$TEC"; then
  fail 'co-install fixture reintroduced unprivileged/interpolated hostile callback installation'
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
