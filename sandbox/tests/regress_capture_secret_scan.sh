#!/usr/bin/env bash
# Regression — DUO-3214(a): Capture::guard_secret()'s two call sites
# (build_post()'s authored post_meta loop, build_options()'s authored-
# options loop) used to gate the call behind `is_string($v)` — an authored
# value that decoded to an ARRAY (a plugin's serialized settings blob) got
# ZERO secret scanning in any downstream branch. guard_secret() now deep-
# scans via Secrets::hard_match_deep() (widened from hard_match()) and
# both call sites call it unconditionally, matching the pattern
# Snapshot::guard_secret() and the option_name_refs inline scan already
# proved in the wave-1 security subset.
#
# Pure PHP, no docker, no WordPress bootstrap: regress_capture_secret_scan.php
# uses Reflection to construct a Capture instance without running its
# (private) constructor and invoke the (private) guard_secret() method
# directly — the method itself has zero WordPress dependency. Proves the
# core logic change in isolation; full end-to-end call-site behavior is
# still proven separately via a live sandbox pair (see the PR body —
# Capture::build() is not offline-stubbable end-to-end the way agent/src/
# Publish.php was for DUO-3213).
#
# The PHP harness above deliberately does NOT prove the call sites are
# actually wired unconditionally in the real source file — Reflection
# invokes guard_secret() directly, bypassing whatever gates its real
# callers. That gap is real, not hypothetical: a rebase onto DUO-3211
# (agent/src/Capture.php's build_options() rewrite) silently reverted one
# of the two options-loop call sites back to an is_string()-gated shape via
# a no-conflict-marker auto-merge — git's 3-way merge considered that hunk
# already resolved because the surrounding lines changed on both sides of
# the rebase. This suite's own PHP harness did not and could not have
# caught it (it never reads Capture.php's call sites at all). The check
# below closes that specific gap: a plain grep-level scan of Capture.php and
# UserMetaCapture.php, asserting each security callback's unconditional call
# sites (post_meta, both authored-options loops, and user_meta -- DUO-3268's
# later addition, extracted by DUO-3349) has no is_string() gate in the two
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
# DUO-3285: this suite's own count assertion (3 unconditional sites) went
# silently stale exactly the way this file's own docblock warns about --
# DUO-3268 ("add authored user meta sidecars") legitimately added a fourth
# unconditional call site. DUO-3349 later extracted that actual invocation
# into UserMetaCapture, leaving an equally mandatory Capture callback binding;
# this suite now pins both sides of that handoff instead of counting only one
# file. Before the extraction, the added user-meta call existed for months and
# nothing ever caught the assertion falling behind because this suite had
# no Makefile target reachable from any bundle or CI check. First real
# catch by DUO-3285's own regress-offline-all, discovered by running the
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
php -l ../../agent/src/Capture.php >/dev/null || fail "agent/src/Capture.php has a syntax error"
php -l ../../agent/src/UserMetaCapture.php >/dev/null || fail "agent/src/UserMetaCapture.php has a syntax error"
php -l ../../agent/src/Secrets.php >/dev/null || fail "agent/src/Secrets.php has a syntax error"
pass "no syntax errors"

say "running the offline harness (guard_secret() widened to deep-scan arrays)"
php regress_capture_secret_scan.php || fail "regress_capture_secret_scan.php reported failing checks (see output above)"

say "call-site wiring: guard_secret()'s real call sites in Capture.php, not just the method itself"
CAPTURE_SRC=../../agent/src/Capture.php
mapfile -t CALL_LINES < <(grep -n '\$this->guard_secret(' "$CAPTURE_SRC")
UNCONDITIONAL=()
EXCEPTION=()
for entry in "${CALL_LINES[@]}"; do
  lineno="${entry%%:*}"
  content="${entry#*:}"
  if grep -q '\$subVal' <<<"$content"; then
    EXCEPTION+=("$lineno")
  else
    UNCONDITIONAL+=("$lineno")
  fi
done
[ "${#UNCONDITIONAL[@]}" -eq 4 ] || fail "expected exactly 4 unconditional guard_secret() call sites in Capture.php (the UserMetaCapture callback binding, post_meta/term_meta, and both authored-options loops), got ${#UNCONDITIONAL[@]}: ${UNCONDITIONAL[*]:-none} — a call site was added or removed; update this check deliberately if that's intended, don't just widen the count"
[ "${#EXCEPTION[@]}" -eq 1 ] || fail "expected exactly 1 deliberate sub_keys-shaped exception, got ${#EXCEPTION[@]}: ${EXCEPTION[*]:-none}"
for lineno in "${UNCONDITIONAL[@]}"; do
  start=$((lineno - 2))
  [ "$start" -lt 1 ] && start=1
  window=$(sed -n "${start},${lineno}p" "$CAPTURE_SRC")
  grep -q 'is_string(' <<<"$window" \
    && fail "guard_secret() call at Capture.php:$lineno appears gated by a nearby is_string() check -- this is the exact shape of the DUO-3211-rebase silent reversion (see this script's header); widened deep scanning would silently stop applying to array-shaped values again:
$window"
done
pass "the UserMetaCapture binding plus all 3 direct Capture guard_secret call sites (lines ${UNCONDITIONAL[*]}) have no nearby is_string() gate"
exc_line="${EXCEPTION[0]}"
prev_line=$(sed -n "$((exc_line - 1))p" "$CAPTURE_SRC")
grep -q 'is_string(\$subVal)' <<<"$prev_line" \
  || fail "expected the sub_keys exception at Capture.php:$exc_line to be immediately preceded by is_string(\$subVal) -- got: $prev_line"
after_window=$(sed -n "${exc_line},$((exc_line + 15))p" "$CAPTURE_SRC")
grep -q 'hard_match_deep(\$subVal)' <<<"$after_window" \
  || fail "expected Secrets::hard_match_deep(\$subVal) within 15 lines after the sub_keys exception at Capture.php:$exc_line -- its own array-scan branch may have been silently deleted, leaving sub_keys array-shaped secrets unscanned"
pass "the one deliberate sub_keys exception (line $exc_line) still has both halves of its is_string()/hard_match_deep() split intact"

USER_META_SRC=../../agent/src/UserMetaCapture.php
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

printf '\n\033[1;32m✔ REGRESS_CAPTURE_SECRET_SCAN PASSED\033[0m\n'
