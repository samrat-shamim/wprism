#!/usr/bin/env bash
# Regression — task #73: Capture::option_ref_tokens() must distinguish
# DANGLING (the target id doesn't exist as a row anywhere — deleted, or
# never existed) from UNSCOPED (the target row genuinely exists, but its
# post_type/taxonomy was never added to policy scope, so it was never
# minted a uuid). Before this task, BOTH cases warn-and-dropped the whole
# option silently (`duo capture` exited 0) — the exact
# elementor_active_kit-out-of-scope shape docs/grind/r1c-agency.md
# escalated. Only UNSCOPED is fixed here: it's a policy gap a human can
# actually close, so it now aborts capture by default (same posture as the
# unclassified-meta gate), with `--force-unresolved-refs` as the explicit
# best-effort escape hatch. DANGLING must keep warning-and-dropping exactly
# as before — spike_a_round_trip.sh's own fixture relies on that for
# wp_page_for_privacy_policy pointing at nothing (id 0, the "unset" case);
# this regression additionally proves the NON-zero, genuinely-dangling-id
# case (a stale id after the target was deleted) stays silent too.
#
# Self-contained: uses only manifests/core.json's own always-pinned
# wp_page_for_privacy_policy ({"class":"authored","ref":"post"}) and
# sticky_posts ({"class":"authored","ref":"post[]"}) rules — no plugin,
# no manifest change. Runs against the existing r1b1 environment (already
# up for Grind R1-B) but touches NEITHER its real site.duo.json/git
# history nor its ledger: every capture below targets a throwaway scratch
# repo directory inside the same bind mount (`--repo=/siterepo/.tmp-*`,
# `--out=.../state-out`, which Capture::run() documents as skipping ledger
# updates) with its own minimal site.duo.json (policy.post_types has no
# 'page' at all, so a real page can be deliberately out of scope without
# touching r1b1's actual shop scope). One throwaway page is created and
# deleted; wp_page_for_privacy_policy/sticky_posts are restored to their
# original values; nothing else on r1b1 is touched.
set -euo pipefail
cd "$(dirname "$0")/.."   # -> sandbox/
COMPOSE="docker compose -f docker-compose.yml --profile r1b"
wp1() { $COMPOSE run --rm -T cli-r1b1 wp "$@"; }
say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }

command -v jq >/dev/null || fail "jq required"

REPO=/siterepo/.tmp-r73-scope-test
OUT="$REPO/state-out"
HOST_REPO="siterepo/r1b1/.tmp-r73-scope-test"
HOST_OUT="$HOST_REPO/state-out"

say "setup: throwaway scratch repo (policy.post_types excludes 'page') + a real page + original option values saved"
ORIG_PRIVACY=$(wp1 option get wp_page_for_privacy_policy 2>/dev/null | tr -d '\r')
ORIG_STICKY=$(wp1 eval 'echo json_encode(get_option("sticky_posts", []));' 2>/dev/null | tail -1 | tr -d '\r')
echo "original wp_page_for_privacy_policy=$ORIG_PRIVACY sticky_posts=$ORIG_STICKY (restored at the end)"

PAGE_ID=$(wp1 post create --post_type=page --post_title='Duo R73 Scope Test Page' --post_status=publish --porcelain 2>/dev/null | tail -1 | tr -d '\r')
[ -n "$PAGE_ID" ] && [ "$PAGE_ID" -gt 0 ] 2>/dev/null || fail "failed to create the throwaway page (got: $PAGE_ID)"
echo "throwaway page id: $PAGE_ID"

cleanup() {
  wp1 post delete "$PAGE_ID" --force >/dev/null 2>&1 || true
  wp1 option update wp_page_for_privacy_policy "$ORIG_PRIVACY" >/dev/null 2>&1 || true
  wp1 eval "update_option('sticky_posts', json_decode('$ORIG_STICKY', true) ?: []);" >/dev/null 2>&1 || true
  # r1b1's own option/page state is ALWAYS restored above regardless of
  # KEEP_SCRATCH -- this only ever skips the scratch TREE deletion, so a
  # failed run's captured options/core.json survives for direct
  # inspection instead of a diagnosis having to be reconstructed from
  # warnings/log text alone (the exact gap that cost extra round trips
  # diagnosing (1b) below the first time this script was reconciled).
  if [ "${KEEP_SCRATCH:-0}" = "1" ]; then
    echo "KEEP_SCRATCH=1: leaving $HOST_REPO on disk for inspection (remove by hand when done)"
  else
    rm -rf "$HOST_REPO"
  fi
}
trap cleanup EXIT

mkdir -p "$HOST_REPO"
cat > "$HOST_REPO/site.duo.json" <<'EOF'
{
  "spec_version": 1,
  "manifests": ["core"],
  "policy": {
    "options": {},
    "post_meta": {},
    "term_meta": {},
    "post_types": ["post", "attachment"],
    "taxonomies": ["category", "post_tag"],
    "scope": {
      "post_type": {
        "page": {"class": "runtime"},
        "product": {"class": "runtime"}
      },
      "taxonomy": {
        "pa_color": {"class": "runtime"},
        "pa_size": {"class": "runtime"},
        "product_cat": {"class": "runtime"}
      }
    }
  }
}
EOF
# scope_gaps() hardening (post-dates this fixture): ANY public post_type/
# taxonomy with real rows now must be classified one way or the other --
# in policy.post_types/taxonomies, or explicitly excluded via
# scope.post_type/taxonomy.<name>.class -- not just types a ref happens to
# point at. r1b1 is Grind R1-B's live WooCommerce shop (task #64+), so its
# product/pa_color/pa_size/product_cat rows now trip this gate exactly
# like 'page' does, BEFORE capture ever reaches the option-ref gate this
# script means to exercise -- an unrelated shop-surface gap masking the
# scope-gate test's own subject. 'page' ITSELF now needs its own explicit
# "runtime" entry too: omission from policy.post_types alone satisfied the
# OLD gate, but the new one treats undeclared-with-real-rows as its own
# violation regardless of whether anything references that type. "runtime"
# (not "authored") is deliberate for all five: Policy::post_types() only
# merges a scope-classified type into "in scope" when class is exactly
# authored (confirmed by reading it directly) -- so 'page' stays correctly
# OUT of policy.post_types() for the ref-classification check below, and
# a real page-referencing option ref still resolves as UNSCOPED, not
# accidentally scoped-in by this fix. product/pa_*/product_cat need no
# such care (nothing in this script ever references them), so the same
# "runtime" shape is used uniformly rather than reasoning through a
# second class per type.
pass "scratch repo ready at $HOST_REPO (policy.post_types has no 'page' — any real page is, by construction, UNSCOPED not unclassified; r1b1's own shop surface explicitly excluded so its unrelated real rows can't trip the scope gate first)"

say "(1) UNSCOPED: wp_page_for_privacy_policy -> a REAL page whose post_type isn't in policy scope"
wp1 option update wp_page_for_privacy_policy "$PAGE_ID" >/dev/null
set +e
OUT1=$(wp1 duo capture --repo="$REPO" --out="$OUT" 2>&1)
RC1=$?
set -e
echo "$OUT1"
[ "$RC1" -ne 0 ] || fail "expected duo capture to ABORT (unscoped ref-typed option) — got exit 0"
echo "$OUT1" | grep -q "wp_page_for_privacy_policy" || fail "abort message doesn't name the option (got: $OUT1)"
echo "$OUT1" | grep -q "$PAGE_ID" || fail "abort message doesn't name the raw unresolved id (got: $OUT1)"
echo "$OUT1" | grep -qi "page" || fail "abort message doesn't name the missing post type (got: $OUT1)"
echo "$OUT1" | grep -q "force-unresolved-refs" || fail "abort message doesn't mention the escape hatch (got: $OUT1)"
pass "capture aborted loudly, naming the option, the raw id, the missing post type, and the escape hatch"

say "(1b) same case, escape hatch: --force-unresolved-refs proceeds, drops it like a dangling ref"
OUT1B=$(wp1 duo capture --repo="$REPO" --out="$OUT" --force-unresolved-refs 2>&1)
echo "$OUT1B"
echo "$OUT1B" | grep -qi "success" || fail "expected --force-unresolved-refs to let capture succeed (got: $OUT1B)"
# NOT has()|not: wp_page_for_privacy_policy is policy-declared authored
# (manifests/core.json), so Capture::build_options()'s own $required pass
# (independent of any previous-document reconciliation -- confirmed by
# reading it directly) ALWAYS gives it a record, even when its ref drops.
# A scalar ref's option_ref_tokens() returns null on drop (unlike an
# array ref, which always returns an array, even empty -- see (3b) below,
# a REAL asymmetry, not a bug) -> capture_value()'s own included=false ->
# the main present-record loop skips it -> the $required fallback writes
# OptionState::absent(), exactly {"state":"absent"} (validate_record()
# forbids any other key on an absent record, so this equality check IS
# the "no raw id anywhere in the record" proof, not a separate check).
jq -e '.records.wp_page_for_privacy_policy == {"state":"absent"}' "$HOST_OUT/options/core.json" >/dev/null \
  || fail "wp_page_for_privacy_policy should be exactly {state:absent} (dropped, no raw id) in forced capture's output (got: $(jq -c '.records.wp_page_for_privacy_policy' "$HOST_OUT/options/core.json"))"
pass "forced capture succeeded; option correctly recorded as absent (never a raw env-local id in canonical state)"

say "(2) DANGLING (regression, must be UNCHANGED): wp_page_for_privacy_policy -> an id that exists NOWHERE"
wp1 option update wp_page_for_privacy_policy 999999999 >/dev/null
OUT2=$(wp1 duo capture --repo="$REPO" --out="$OUT" 2>&1)
echo "$OUT2"
echo "$OUT2" | grep -qi "success" || fail "expected a genuinely dangling ref to still warn-and-drop, not abort (got: $OUT2)"
echo "$OUT2" | grep -q "999999999" || fail "expected the ordinary dangling warning naming the id (got: $OUT2)"
# Same record shape as (1b) above, same reason: a dropped scalar ref
# always ends up {"state":"absent"} via the $required fallback, dangling
# or unscoped-forced makes no difference to THIS shape (only to which
# warning text fires, already checked above).
jq -e '.records.wp_page_for_privacy_policy == {"state":"absent"}' "$HOST_OUT/options/core.json" >/dev/null \
  || fail "wp_page_for_privacy_policy should be exactly {state:absent} (dangling, dropped) (got: $(jq -c '.records.wp_page_for_privacy_policy' "$HOST_OUT/options/core.json"))"
pass "dangling reference still warns and drops silently, exit 0 — unaffected by this fix (spike A's id-0/deleted-target case stays honest)"

say "(3) UNSCOPED, array ref: sticky_posts -> [that same real, out-of-scope page]"
wp1 eval "update_option('sticky_posts', [$PAGE_ID]);" >/dev/null
wp1 option update wp_page_for_privacy_policy 0 >/dev/null
set +e
OUT3=$(wp1 duo capture --repo="$REPO" --out="$OUT" 2>&1)
RC3=$?
set -e
echo "$OUT3"
[ "$RC3" -ne 0 ] || fail "expected duo capture to ABORT (unscoped ref-typed ARRAY option) — got exit 0"
echo "$OUT3" | grep -q "sticky_posts" || fail "abort message doesn't name sticky_posts (got: $OUT3)"
pass "array-ref option gets the identical loud-and-blocking treatment as the scalar case (acceptance criterion 3)"

say "(3b) same array case, DANGLING element (regression, must be UNCHANGED)"
wp1 eval "update_option('sticky_posts', [888888888]);" >/dev/null
OUT3B=$(wp1 duo capture --repo="$REPO" --out="$OUT" 2>&1)
echo "$OUT3B"
echo "$OUT3B" | grep -qi "success" || fail "expected a dangling array element to still warn-and-drop (got: $OUT3B)"
# Sweep note (unlike (1b)/(2) above, this one does NOT need the {state:
# absent} fix): option_ref_tokens()'s array-ref branch (confirmed by
# reading it directly) ALWAYS returns an array, even when every element
# dropped -- an empty array is still `!== null`, so capture_value()'s
# included stays true and the option stays a PRESENT record with an
# empty value, never falling through to the $required absent() fallback
# a scalar ref's null return does. A real, deliberate scalar/array
# asymmetry, not a bug -- this assertion was already correct.
jq -e '.records.sticky_posts.value == []' "$HOST_OUT/options/core.json" >/dev/null \
  || fail "sticky_posts should be an empty array (dangling element dropped, not the whole key)"
pass "dangling array element still drops just that element and exits 0 — unaffected by this fix"

say "(4) NOT unscoped: a real, CORRECTLY-scoped target that this build simply hasn't minted a uuid for yet (Capture::snapshot()'s non-minting mode — plan/apply's drift check against a fresh target before its first capture). This is the exact false positive the core-manifest conformance sweep caught empirically (default_category on a never-captured env) — id_to_token() failing here is a MINTING fact, not a POLICY fact, and must not be read as unscoped."
cat > "$HOST_REPO/site.duo.json" <<'EOF'
{
  "spec_version": 1,
  "manifests": ["core"],
  "policy": {
    "options": {},
    "post_meta": {},
    "term_meta": {},
    "post_types": ["post", "page", "attachment"],
    "taxonomies": ["category", "post_tag"],
    "scope": {
      "post_type": {
        "product": {"class": "runtime"}
      },
      "taxonomy": {
        "pa_color": {"class": "runtime"},
        "pa_size": {"class": "runtime"},
        "product_cat": {"class": "runtime"}
      }
    }
  }
}
EOF
# Same r1b1 shop-surface gap as the first heredoc above -- 'page' is
# already IN policy.post_types here (this step's own subject is an
# in-scope-but-unminted PAGE, not an out-of-scope one), so it needs no
# separate scope entry, but product/pa_*/product_cat still do:
# Capture::snapshot() -> build(false, ...) hits the SAME unconditional
# scope_gaps() check (confirmed by reading both directly), so this
# heredoc is exposed to the identical widening.
wp1 option update wp_page_for_privacy_policy "$PAGE_ID" >/dev/null
# $PAGE_ID has never been in scope in any earlier step above, so it has never
# been minted a uuid — exactly the "in scope, not yet identified" case.
SNAP_OUT=$(wp1 eval "try { \Duo\Capture::snapshot('$REPO'); echo 'OK'; } catch (\Throwable \$e) { echo 'THROWN: ' . \$e->getMessage(); }" 2>&1 | tail -1)
echo "$SNAP_OUT"
echo "$SNAP_OUT" | grep -q '^OK' || fail "Capture::snapshot() (non-minting) incorrectly treated an in-scope-but-unminted page as UNSCOPED (got: $SNAP_OUT)"
pass "non-minting snapshot correctly leaves an in-scope, not-yet-minted entity alone — scope is decided by policy membership, never by minting state"

pass "task #73 regression: dangling-vs-unscoped distinction demonstrated in both directions (scalar + array), the escape hatch, and the minting-vs-scope false positive"
