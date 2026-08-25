#!/usr/bin/env bash
# Static contract for the two Polylang evidence sweeps.  Docker is intentionally
# absent: this pins the live command boundaries and prevents a future fixture
# from silently widening the exact artifact/topology claim.
set -euo pipefail
cd "$(dirname "$0")/../../../.."
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }
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
  'polylang_graph_fingerprint'; do
  grep -Fq "$needle" "$MS" || fail "multisite fixture lost required guard: $needle"
done
for needle in \
  'fetch_artifact' \
  'provider:polylang-nav-menus/synchronize_runtime' \
  'native:rewrite.flush' \
  'pll_modify_rewrite_rule' \
  'recovery_required' \
  'clean no-op recapture' \
  '(.actions|length)==0'; do
  grep -Fq "$needle" "$TEC" || fail "co-install fixture lost required guard: $needle"
done
grep -Fq 'manifests:["core","polylang","the-events-calendar"]' "$TEC" \
  || fail 'co-install fixture widened or dropped its manifest set'
grep -Fq 'manifests:["core","polylang"]' "$MS" \
  || fail 'multisite fixture does not use the dedicated Polylang manifest set'
printf 'PASS: Polylang exact multisite and bounded Polylang+TEC live fixtures are statically guarded\n'
