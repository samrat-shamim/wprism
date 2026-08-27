#!/usr/bin/env bash
# Regression — DUO-3394: the polylang conformance seed + checks must route every
# failure through the exported `fail` helper (which emits the harness's
# `FAIL: …` line the sweep driver and log scrapers key on), never a bare
# `echo "FAIL: …" >&2; exit 1`. Found during DUO-3381's 26-file conformance
# audit. Offline static check — no docker, no pair. (Scoped to the two polylang
# files this issue owns; a family-wide sweep is separate.)
set -euo pipefail
cd "$(dirname "$0")/../../.."   # -> sandbox/

pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }

for f in conformance/seeds/polylang.sh conformance/checks/polylang.sh; do
  [ -f "$f" ] || fail "$f is missing"
  # No bare shell fail-path: a hand-rolled FAIL: line, or a `>&2; exit 1` shell
  # exit. (PHP `fwrite(STDERR, …); exit(1);` inside `wp eval` heredocs is PHP,
  # not a shell fail-path, and is intentionally out of scope — it matches
  # neither pattern.)
  if grep -nE 'echo "FAIL:' "$f" >/dev/null; then
    fail "$f still emits a bare 'echo \"FAIL: …\"' instead of the fail helper: $(grep -nE 'echo "FAIL:' "$f")"
  fi
  if grep -nE '>&2; exit 1' "$f" >/dev/null; then
    fail "$f still has a bare '>&2; exit 1' shell fail-path instead of the fail helper: $(grep -nE '>&2; exit 1' "$f")"
  fi
  # And it must actually route failures through the helper (a `fail "…"` call
  # preceded by start-of-line or whitespace — || fail, && fail, or a bare
  # indented fail in an if-block).
  grep -qE '(^|[[:space:]])fail "' "$f" \
    || fail "$f does not route any failure through the fail helper"
  pass "$f routes failures through the fail helper (no bare echo+exit fail-path)"
done

if grep -nF "Polylang 3.5 language creation failed" conformance/seeds/polylang.sh >/dev/null; then
  fail "conformance/seeds/polylang.sh still labels the unsupported pre-3.8 fallback as Polylang 3.5"
fi
pass "conformance/seeds/polylang.sh has no stale Polylang 3.5 diagnostic"

echo "REGRESS_POLYLANG_FAIL_HELPER PASSED"
