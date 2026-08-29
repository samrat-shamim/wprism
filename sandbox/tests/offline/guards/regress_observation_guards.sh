#!/usr/bin/env bash
# Regression — issue #3401: the core sweep's NON-wprism wp-cli OBSERVATION reads must
# name infrastructure, not the engine, when a load-starved `docker compose run`
# returns EMPTY at exit 0 (the issue #3381 signature). Two shapes in
# conformance/checks/core.sh consumed such an empty read into an engine
# accusation:
#   1. compare family  `[ "$(wp_conf2 … get/list …)" = "$BEFORE" ] || fail "… mutated …"`
#      — an empty substitution makes `"" != "$BEFORE"` read as an engine mutation.
#   2. `|| fail` observation `wp_conf2 comment get … >/dev/null || fail "… removed …"`
#      — a compose death trips the guard with an engine-accusing message.
# The fix captures each read into a var, premise-asserts it carried bytes with
# require_observed_nonempty (issue #3413's "infrastructure failure:" domain, never
# an accusation against WPrism), and only THEN compares. A healthy (non-empty,
# correct) read reaches the exact same outcome; a real mutation (a different
# non-empty value) still reaches the engine accusation. For the two comment-get
# existence checks the guard is gated on exit code, so a genuine (non-zero)
# removal still reaches the accusation while only the exit-0 empty names infra.
#
# Proves: (a) require_observed_nonempty raises the infrastructure domain on an
# empty observation and passes a non-empty one; (b) each guarded call site in
# core.sh now captures+guards before comparing, and the old unguarded inline
# substitution is gone (reverting any one guard fails this suite).
# Offline: sources the shared fragment, no docker, no pair.
set -euo pipefail
cd "$(dirname "$0")/../../.."   # -> sandbox/

pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }

FRAG=conformance/asserts.sh
CORE=conformance/checks/core.sh
[ -f "$FRAG" ] || fail "shared fragment $FRAG is missing"
[ -f "$CORE" ] || fail "$CORE is missing"

# --- 1. defined in the shared fragment + exported for the hook subprocesses ---
grep -qE '^require_observed_nonempty\(\) \{' "$FRAG" \
  || fail "require_observed_nonempty is not defined in $FRAG (both harnesses must see it)"
grep -qE 'require_observed_nonempty' conformance/run.sh \
  || fail "run.sh does not export require_observed_nonempty for the hook subprocesses (core.sh runs as one)"
pass "require_observed_nonempty is defined in the shared fragment and exported by run.sh"

# --- 2. behavior: empty -> infrastructure failure; non-empty -> silent pass ---
# asserts.sh refuses to source without a caller-defined fail() (its own guard),
# so each probe defines one, sources the fragment, and calls the helper in an
# isolated bash -c whose exit the parent captures.
probe() { # probe <value> ; prints combined stdout+stderr then "rc=<code>"
  local out rc
  out=$(bash -c '
    fail() { printf "FAIL: %s\n" "$*"; exit 1; }
    . conformance/asserts.sh
    require_observed_nonempty "conf2 target observation (probe)" "$1" && printf "OK_NONEMPTY\n"
  ' _ "$1" 2>&1) && rc=0 || rc=$?
  printf '%s\nrc=%s\n' "$out" "$rc"
}

EMPTY=$(probe "")
grep -q 'infrastructure failure:' <<<"$EMPTY" \
  || fail "empty observation did not raise the infrastructure-failure domain: $EMPTY"
grep -qE 'rc=[1-9]' <<<"$EMPTY" \
  || fail "empty observation did not stop the hook (expected non-zero exit): $EMPTY"
grep -q 'OK_NONEMPTY' <<<"$EMPTY" \
  && fail "empty observation wrongly passed the premise: $EMPTY"
pass "require_observed_nonempty on an empty observation raises the infrastructure domain and stops the hook (not an engine accusation)"

NONEMPTY=$(probe "Branch Repository Intent For Conflict")
grep -q 'OK_NONEMPTY' <<<"$NONEMPTY" \
  || fail "require_observed_nonempty rejected a non-empty observation: $NONEMPTY"
grep -qE 'rc=0' <<<"$NONEMPTY" \
  || fail "require_observed_nonempty on a non-empty observation did not exit 0: $NONEMPTY"
pass "require_observed_nonempty passes a non-empty observation silently"

# --- 3. static call-site guards on core.sh (pin the fix in place) ------------
# Each guarded observation names itself with a distinct <what> string. If any
# guard is reverted (the inline `[ "$(wp_conf2 …)" = "$BEFORE" ]` restored),
# BOTH its positive pin here and the matching negative pin below fail.
GUARD_WHATS=(
  'require_observed_nonempty "conf2 theme_mods_twentytwentyfive (target observation)"'
  'require_observed_nonempty "conf1 widget_$TYPE identity ledger"'
  'require_observed_nonempty "conf2 widget_$TYPE identity ledger"'
  'require_observed_nonempty "conf2 sidebar-1 widget keys (target observation)"'
  'require_observed_nonempty "conf2 branch-a post_title (unforced-conflict target baseline)"'
  'require_observed_nonempty "conf2 wp_wprism_state content_hash (unforced-conflict base baseline)"'
  'require_observed_nonempty "conf2 branch-a post_title (unforced-conflict target)"'
  'require_observed_nonempty "conf2 wp_wprism_state content_hash (unforced-conflict base)"'
  'require_observed_nonempty "conf2 branch-a post_title (forced-conflict convergence)"'
  'require_observed_nonempty "conf2 post get post_content (guard-blocked deletion target baseline)"'
  'require_observed_nonempty "conf2 wp_wprism_state content_hash (guard-blocked deletion base baseline)"'
  'require_observed_nonempty "conf2 comment get (preserved runtime comment)"'
  'require_observed_nonempty "conf2 post get post_content (guard-blocked deletion target)"'
  'require_observed_nonempty "conf2 wp_wprism_state content_hash (guard-blocked deletion base)"'
  'require_observed_nonempty "conf2 comment get (guard-blocked deletion runtime reference)"'
  'require_observed_nonempty "conf2 post get post_content (force-theirs-only deletion-conflict target baseline)"'
  'require_observed_nonempty "conf2 wp_wprism_state content_hash (force-theirs-only deletion-conflict base baseline)"'
  'require_observed_nonempty "conf2 post get post_content (force-theirs-only deletion-conflict target)"'
  'require_observed_nonempty "conf2 wp_wprism_state content_hash (force-theirs-only deletion-conflict base)"'
)
for what in "${GUARD_WHATS[@]}"; do
  grep -Fq "$what" "$CORE" \
    || fail "$CORE no longer premise-guards an observation read: missing [$what]"
done
pass "all ${#GUARD_WHATS[@]} issue #3401/issue #3426 observation reads are premise-guarded with require_observed_nonempty"

grep -Fq "__wprism_missing__" "$CORE" \
  || fail "$CORE no longer distinguishes a genuine missing Ledger mapping from an empty infrastructure observation"
grep -Fq '[ "$SOURCE_LOCAL" != '\''__wprism_missing__'\'' ]' "$CORE" \
  || fail "$CORE no longer routes a genuine missing Ledger mapping to the engine assertion"
pass "Ledger null mappings retain the engine accusation while compose-empty observations remain infrastructure failures"

# Negative pins — the exact unguarded inline shapes the fix removed must not
# reappear. These are scoped to the guarded sites only (the rollback-alpha/beta
# existence compares at the end of core.sh and the `[ -z "$(…)" ]` deletion
# checks are deliberately NOT guarded, so they must stay matchable by name).
FORBIDDEN_INLINE=(
  '[ "$(wp_conf2 post list --post_type=page --name=branch-a --field=post_title)" ='
  '[ "$(wp_conf2 db query "SELECT content_hash'
  '[ "$(wp_conf2 comment get "$COMMENT2" --field=comment_ID)" ='
  '[ "$(wp_conf2 post get "$HELLO2" --field=post_content)" ='
  'wp_conf2 comment get "$HELLO_COMMENT" --field=comment_ID >/dev/null'
)
for inline in "${FORBIDDEN_INLINE[@]}"; do
  grep -Fq "$inline" "$CORE" \
    && fail "$CORE still consumes an unguarded observation read into an engine accusation: [$inline]"
done
pass "no guarded site's unguarded inline substitution/|| fail shape remains in $CORE"

# The two comment-get existence checks keep their engine accusation reachable
# for a GENUINE (non-zero) removal: the guard is gated on the captured exit
# code, so only an exit-0 empty (the compose-death signature) names infra.
grep -Fq '[ "$COMMENT2_REF_RC" -ne 0 ] || require_observed_nonempty' "$CORE" \
  || fail "$CORE's preserved-comment check no longer gates the infra guard on exit code (a real cascade must still accuse)"
grep -Fq '[ "$HELLO_COMMENT_REF_RC" -ne 0 ] || require_observed_nonempty' "$CORE" \
  || fail "$CORE's runtime-reference check no longer gates the infra guard on exit code (a real removal must still accuse)"
# The two post-get content compares are the OTHER exit-code-gated shape: post get
# exits non-zero on a genuine deletion of $HELLO2, so the guard must stay gated on
# the exit code — the plain form would demote a real engine deletion to infra.
grep -Fq '[ "$BLOCKED_CONTENT_AFTER_RC" -ne 0 ] || require_observed_nonempty' "$CORE" \
  || fail "$CORE's guard-blocked deletion-target check no longer gates the infra guard on exit code (a real deletion must still accuse)"
grep -Fq '[ "$LOCAL_FORCE_ONLY_CONTENT_AFTER_RC" -ne 0 ] || require_observed_nonempty' "$CORE" \
  || fail "$CORE's force-theirs-only deletion-target check no longer gates the infra guard on exit code (a real deletion must still accuse)"
pass "all four post get/comment get existence checks name only the exit-0 empty as infrastructure and keep the engine accusation for a genuine removal"

echo "REGRESS_OBSERVATION_GUARDS PASSED"
