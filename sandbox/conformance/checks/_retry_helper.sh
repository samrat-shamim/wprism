#!/usr/bin/env bash
# Shared render-check retry helper. Converts the suspected-load-flake class
# already documented twice — docs/grind/r3b-events-memberships.md's
# aggregate-list-view timing under concurrent-pair contention, and DUO-3228
# task 0's own fse run (the About-permalink render assertion failed once
# under 3 concurrent pairs; the identical assertion, on the identical
# commit, had passed cleanly minutes earlier under less contention;
# team-lead's forensics closed it as a suspected load-flake, second
# instance of the class) — into MEASURED signal instead of a recurring
# mystery each time it happens: retry a render check a bounded number of
# times after a short delay, and log loudly and distinctly when the retry
# is what saved it. A pass-on-retry is itself evidence of flakiness, not
# something to swallow silently.
#
# Usage: source this file, then call it around a curl+assertion:
#
#   about_link_present() { grep -q 'href="http://localhost:8807/about/"' <<<"$1"; }
#   retry_render_check "http://localhost:8807/" about_link_present \
#     || fail "About link never resolved, even after retry"
#
# Retries the WHOLE curl-plus-check unit, never one grep in isolation: a
# stale or incomplete render is a property of the page as a whole (task 0's
# own failure was one assertion among several against the SAME curl body,
# and r3b's finding was about the underlying data lagging the request, not
# about any one string match), so re-fetching is the correct retry unit,
# not re-testing a body already in hand.
#
# Bounded on purpose, never open-ended: a check that still fails after
# every attempt is a real failure and must still fail loudly — retrying
# forever would silently convert a genuine regression into "still warming
# up," exactly the "green with warnings" posture DESIGN.md rejects.
#
# NOT wired into any checks/*.sh by DUO-3228, which added this file as
# shared infra per team-lead's request, since validating that change meant
# re-touching the conf pair — out of this fixture's own footprint. DUO-3238
# wired it into conformance/checks/fse.sh's front-page assertions (the file
# that actually flaked) — see that file for the first real usage.
# sandbox/tests/certify_merge.sh still has no HTTP/render checks to apply
# this to (headless by design, a data-layer certification like
# spike_b_merge.sh) — see that file's header.
retry_render_check() { # retry_render_check <url> <check_fn> [attempts=2] [delay_seconds=5]
  local url="$1" check_fn="$2" attempts="${3:-2}" delay="${4:-5}"
  local n=1 body
  while :; do
    body=$(curl -fs "$url" 2>/dev/null) || body=""
    if [ -n "$body" ] && "$check_fn" "$body"; then
      if [ "$n" -gt 1 ]; then
        printf '\033[1;33mnote: render check passed on retry %d/%d — load flake suspected (docs/grind/r3b-events-memberships.md, DUO-3228 task 0 precedent)\033[0m\n' "$n" "$attempts"
      fi
      return 0
    fi
    [ "$n" -ge "$attempts" ] && return 1
    n=$((n + 1))
    sleep "$delay"
  done
}

# Self-test: run this file DIRECTLY (bash sandbox/conformance/checks/
# _retry_helper.sh) to prove the retry semantics offline — no docker, no
# live pair, a fake "curl" standing in. Sourcing this file (the normal
# usage) does not run any of this.
if [ "${BASH_SOURCE[0]}" = "${0}" ]; then
  set -euo pipefail
  say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
  pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
  fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }

  # Fake curl: "succeeds" (echoes a body, exit 0) once the call count
  # reaches TARGET_ATTEMPT; every call before that "fails" (empty body,
  # exit 1) — a controllable stand-in for "the Nth attempt is the one
  # that finally sees the finished render." Counter lives in a FILE, not
  # a shell variable: retry_render_check invokes curl as
  # `body=$(curl ...)`, and command substitution always forks a subshell
  # — a variable increment inside curl() would be invisible to every
  # subsequent call (confirmed the hard way authoring this self-test: a
  # plain `pass_count=$((pass_count+1))` never advanced past 1, because
  # each fork started fresh from the parent's never-updated value). A
  # file is the one piece of state that actually survives the fork.
  COUNTER_FILE="$(mktemp)"
  TARGET_ATTEMPT=1
  reset_counter() { echo 0 > "$COUNTER_FILE"; }
  counter_value() { cat "$COUNTER_FILE"; }
  curl() {
    local count
    count=$(($(cat "$COUNTER_FILE") + 1))
    echo "$count" > "$COUNTER_FILE"
    if [ "$count" -lt "$TARGET_ATTEMPT" ]; then
      echo ""
      return 1
    fi
    echo "OK-BODY"
  }
  trap 'rm -f "$COUNTER_FILE"' EXIT

  always_true()  { [ "$1" = "OK-BODY" ]; }
  always_false() { return 1; }

  say "self-test 1: succeeds on the first attempt — no retry note"
  TARGET_ATTEMPT=1; reset_counter
  OUT=$(retry_render_check "http://fake/" always_true 3 0) || fail "expected success on the first attempt"
  echo "$OUT" | grep -q "passed on retry" && fail "unexpected retry note on a first-attempt pass"
  pass "first-attempt success, no retry note printed"

  say "self-test 2: fails once, recovers on retry 2/3 — must log the retry note naming the attempt"
  TARGET_ATTEMPT=2; reset_counter
  OUT=$(retry_render_check "http://fake/" always_true 3 0) || fail "expected eventual success"
  echo "$OUT" | grep -q "passed on retry 2/3" || fail "expected the retry note naming attempt 2/3, got: $OUT"
  pass "recovered on retry, logged loudly and distinctly"

  say "self-test 3: never passes — must fail after exactly <attempts>, never hang or retry forever"
  TARGET_ATTEMPT=999; reset_counter
  if retry_render_check "http://fake/" always_false 3 0 >/dev/null; then
    fail "expected failure when the check never passes"
  fi
  [ "$(counter_value)" -eq 3 ] || fail "expected exactly 3 curl attempts (bounded), got $(counter_value)"
  pass "bounded — failed loudly after exhausting attempts, made exactly 3 attempts, did not retry forever"

  printf '\n\033[1;32m✔ retry helper self-test passed (offline, no docker)\033[0m\n'
fi
