#!/usr/bin/env bash
# Regression — issue #3214(a): the authored post-meta and option call sites
# used to gate CaptureSafetyGates::guardSecret() behind `is_string($v)` — an authored
# value that decoded to an ARRAY (a plugin's serialized settings blob) got
# ZERO secret scanning in any downstream branch. guard_secret() now deep-
# scans via Secrets::clearance_match_deep() (widened from hard_match()) and
# both call sites call it unconditionally, matching the pattern
# Snapshot::guard_secret() and the option_name_refs inline scan already
# proved in the wave-1 security subset.
#
# Pure PHP, no docker, no WordPress bootstrap: regress_capture_secret_scan.php
# invokes CaptureSafetyGates::guardSecret() directly — the method itself has
# zero WordPress dependency. Proves the
# core logic change in isolation; full end-to-end call-site behavior is
# still proven separately via a live sandbox pair (see the PR body —
# CaptureCandidateBuilder is not offline-stubbable end-to-end the way agent/src/
# Publish.php was for issue #3213).
#
# The PHP harness above deliberately does NOT prove the call sites are
# actually wired unconditionally in the real source file — Reflection
# invokes guard_secret() directly, bypassing whatever gates its real
# callers. That gap is real, not hypothetical: a rebase onto issue #3211
# (agent/src/Capture/Capture.php's build_options() rewrite) silently reverted one
# of the two options-loop call sites back to an is_string()-gated shape via
# a no-conflict-marker auto-merge — git's 3-way merge considered that hunk
# already resolved because the surrounding lines changed on both sides of
# the rebase. This suite's own PHP harness did not and could not have
# caught it (it never reads Capture.php's call sites at all). The check
# below closes that specific gap: a plain grep-level scan of Capture.php,
# OptionsCapture.php, EntityMetaCapture.php, and UserMetaCapture.php,
# asserting each security callback's unconditional call sites (post/term
# meta, both authored-options loops, and user_meta -- issue #3268's later
# addition, extracted by issue #3349)
# has no is_string() gate in the two
# lines immediately before it, and that
# the one DELIBERATE exception (the sub_keys loop's hand-rolled is_string/
# hard_match_deep split, documented in its own comment) still has both
# halves of that split intact. It does not parse PHP or match brace
# structure — just fixed small line-windows around named call sites,
# located by their literal `$this->guard_secret(` invocation text (which
# excludes every comment that merely mentions the method by name). Verified
# against synthetic before trusting it here: catches the exact silent-
# reversion shape above, catches the same corruption at each of the other
# two unconditional sites, catches the sub_keys exception's own array-scan
# branch being deleted, and catches a call site being deleted outright (via
# the site-count assertion) — all four confirmed to fail loudly, and the
# known-good shape confirmed to pass, before this check was trusted for a
# real run.
#
# issue #3285: this suite's own count assertion (3 unconditional sites) went
# silently stale exactly the way this file's own docblock warns about --
# issue #3268 ("add authored user meta sidecars") legitimately added a fourth
# unconditional call site. issue #3349 later extracted those actual invocations
# into UserMetaCapture and EntityMetaCapture, leaving equally mandatory Capture
# callback bindings; this suite now pins both sides of each handoff instead of
# counting only one file. Before the extraction, the added user-meta call existed for months and
# nothing ever caught the assertion falling behind because this suite had
# no Makefile target reachable from any bundle or CI check. First real
# catch by issue #3285's own regress-offline-all, discovered by running the
# bundle for the first time, not by design -- corrected here (4, not 3;
# the new site is genuinely unconditional and correct, this was always a
# stale test assumption, never a Capture.php defect).
#
# Safe to run anywhere `php`/`bash`/`grep`/`sed` are on PATH; touches no
# sandbox/siterepo state.
set -euo pipefail
cd "$(dirname "$0")"   # -> sandbox/tests/
say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }

command -v php >/dev/null || fail "php required on PATH"

say "php -l syntax check"
php -l regress_capture_secret_scan.php >/dev/null || fail "regress_capture_secret_scan.php has a syntax error"
php -l ../../../../agent/src/Capture/CaptureCandidateBuilder.php >/dev/null || fail "agent/src/Capture/CaptureCandidateBuilder.php has a syntax error"
php -l ../../../../agent/src/Capture/CaptureSafetyGates.php >/dev/null || fail "agent/src/Capture/CaptureSafetyGates.php has a syntax error"
php -l ../../../../agent/src/Capture/OptionsCapture.php >/dev/null || fail "agent/src/Capture/OptionsCapture.php has a syntax error"
php -l ../../../../agent/src/Capture/UserMetaCapture.php >/dev/null || fail "agent/src/Capture/UserMetaCapture.php has a syntax error"
php -l ../../../../agent/src/Capture/EntityMetaCapture.php >/dev/null || fail "agent/src/Capture/EntityMetaCapture.php has a syntax error"
php -l ../../../../agent/src/Kernel/Secrets.php >/dev/null || fail "agent/src/Kernel/Secrets.php has a syntax error"
pass "no syntax errors"

say "running the offline harness (guard_secret() widened to deep-scan arrays)"
php regress_capture_secret_scan.php || fail "regress_capture_secret_scan.php reported failing checks (see output above)"

say "call-site wiring: CaptureCandidateBuilder binds every extracted security callback unconditionally"
CAPTURE_SRC=../../../../agent/src/Capture/CaptureCandidateBuilder.php
mapfile -t CALL_LINES < <(grep -n '\$this->safetyGates->guardSecret(' "$CAPTURE_SRC")
[ "${#CALL_LINES[@]}" -eq 3 ] || fail "expected exactly 3 CaptureCandidateBuilder guardSecret() callback bindings (user meta, entity meta, options), got ${#CALL_LINES[@]} — a handoff was added or removed"
for entry in "${CALL_LINES[@]}"; do
  lineno="${entry%%:*}"
  start=$((lineno - 2))
  [ "$start" -lt 1 ] && start=1
  window=$(sed -n "${start},${lineno}p" "$CAPTURE_SRC")
  grep -q 'is_string(' <<<"$window" \
    && fail "guardSecret() call at CaptureCandidateBuilder.php:$lineno appears gated by a nearby is_string() check -- this is the exact shape of the issue #3211-rebase silent reversion (see this script's header); widened deep scanning would silently stop applying to array-shaped values again:
$window"
done
pass "all three extracted-capturer bindings have no nearby is_string() gate"

say "call-site wiring: OptionsCapture guards every authored option shape through one callback"
OPTIONS_SRC=../../../../agent/src/Capture/OptionsCapture.php
mapfile -t OPTION_CALL_LINES < <(grep -n '(\$this->guardSecret)(' "$OPTIONS_SRC")
[ "${#OPTION_CALL_LINES[@]}" -eq 4 ] || fail "expected exact, pattern-name, and sub-key authored-option callbacks, got ${#OPTION_CALL_LINES[@]}"
for entry in "${OPTION_CALL_LINES[@]}"; do
  lineno="${entry%%:*}"
  start=$((lineno - 2))
  [ "$start" -lt 1 ] && start=1
  window=$(sed -n "${start},${lineno}p" "$OPTIONS_SRC")
  grep -q 'is_string(' <<<"$window" \
    && fail "option secret callback at OptionsCapture.php:$lineno appears gated by a nearby is_string() check:\n$window"
done
pass "all authored-option callbacks are unconditional"

USER_META_SRC=../../../../agent/src/Capture/UserMetaCapture.php
USER_SECRET_LINE=$(grep -n "(\$this->guardSecret)('user_meta'" "$USER_META_SRC" | cut -d: -f1)
[ -n "$USER_SECRET_LINE" ] || fail "UserMetaCapture lost its authored user-meta secret callback"
user_start=$((USER_SECRET_LINE - 2))
[ "$user_start" -lt 1 ] && user_start=1
user_window=$(sed -n "${user_start},${USER_SECRET_LINE}p" "$USER_META_SRC")
grep -q 'is_string(' <<<"$user_window" \
  && fail "UserMetaCapture's secret callback appears gated by a nearby is_string() check:\n$user_window"
grep -q '(\$this->guardPersonalData)' "$USER_META_SRC" \
  || fail "UserMetaCapture lost the personal-data gate paired with its secret gate"
pass "the extracted user-meta capturer keeps unconditional deep-secret and personal-data gates"

ENTITY_META_SRC=../../../../agent/src/Capture/EntityMetaCapture.php
ENTITY_SECRET_LINE=$(grep -n '(\$this->guardSecret)(' "$ENTITY_META_SRC" | cut -d: -f1)
[ -n "$ENTITY_SECRET_LINE" ] || fail "EntityMetaCapture lost its authored post/term-meta secret callback"
entity_start=$((ENTITY_SECRET_LINE - 2))
[ "$entity_start" -lt 1 ] && entity_start=1
entity_window=$(sed -n "${entity_start},${ENTITY_SECRET_LINE}p" "$ENTITY_META_SRC")
grep -q 'is_string(' <<<"$entity_window" \
  && fail "EntityMetaCapture's secret callback appears gated by a nearby is_string() check:\n$entity_window"
pass "the extracted post/term-meta capturer keeps its unconditional deep-secret gate"

printf '\n\033[1;32m✔ REGRESS_CAPTURE_SECRET_SCAN PASSED\033[0m\n'
