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
# core logic change in isolation; the call-site wiring is proven separately
# via a live sandbox pair (see the PR body — Capture::build() is not
# offline-stubbable end-to-end the way agent/src/Publish.php was for
# DUO-3213). Safe to run anywhere `php` is on PATH; touches no sandbox/
# siterepo state.
set -euo pipefail
cd "$(dirname "$0")"   # -> sandbox/tests/
say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }

command -v php >/dev/null || fail "php required on PATH"

say "php -l syntax check"
php -l regress_capture_secret_scan.php >/dev/null || fail "regress_capture_secret_scan.php has a syntax error"
php -l ../../agent/src/Capture.php >/dev/null || fail "agent/src/Capture.php has a syntax error"
php -l ../../agent/src/Secrets.php >/dev/null || fail "agent/src/Secrets.php has a syntax error"
pass "no syntax errors"

say "running the offline harness (guard_secret() widened to deep-scan arrays)"
php regress_capture_secret_scan.php || fail "regress_capture_secret_scan.php reported failing checks (see output above)"

printf '\n\033[1;32m✔ REGRESS_CAPTURE_SECRET_SCAN PASSED\033[0m\n'
