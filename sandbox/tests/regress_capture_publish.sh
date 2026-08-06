#!/usr/bin/env bash
# Regression — DUO-3213: atomic capture publication.
#
# agent/src/Publish.php (new) owns the filesystem half of this issue's fix:
# a per-destination capture lock (flock, non-blocking), a staging directory
# the candidate tree is built and validated in BEFORE it ever touches the
# published dir, an atomic two-step rename swap (POSIX rename() can't
# atomically replace a non-empty directory in one step), and deterministic
# recovery of whatever a crash mid-swap leaves behind. It was written with
# ZERO WordPress/$wpdb dependency specifically so all of this is provable
# offline, on real files, including a REAL SIGKILL of a real child process
# mid-publish (P6) -- no docker, no WordPress bootstrap needed for any of
# it. Capture.php's own DB-side half (consistent-snapshot transaction,
# InnoDB engine check, deadlock retry) needs a live MySQL and is covered
# separately by the sandbox pair evidence in the DUO-3213 PR body.
#
# Pure PHP + real subprocess kill signals. Safe to run anywhere `php` is on
# PATH with pcntl-capable proc_open (standard on Linux/macOS); touches only
# scratch directories under sys_get_temp_dir(), never sandbox/siterepo state.
set -euo pipefail
cd "$(dirname "$0")"   # -> sandbox/tests/
say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }

command -v php >/dev/null || fail "php required on PATH"

say "php -l syntax check (harness + support driver + the code under test)"
php -l regress_capture_publish.php >/dev/null || fail "regress_capture_publish.php has a syntax error"
php -l support/capture_publish_kill_driver.php >/dev/null || fail "support/capture_publish_kill_driver.php has a syntax error"
php -l ../../agent/src/Publish.php >/dev/null || fail "agent/src/Publish.php has a syntax error"
php -l ../../agent/src/TransientDbException.php >/dev/null || fail "agent/src/TransientDbException.php has a syntax error"
php -l ../../agent/src/Capture.php >/dev/null || fail "agent/src/Capture.php has a syntax error"
pass "no syntax errors"

say "running the offline harness (lock, recover, staged write, atomic swap, real SIGKILL, retry-error matching)"
php regress_capture_publish.php || fail "regress_capture_publish.php reported failing checks (see output above)"

printf '\n\033[1;32m✔ REGRESS_CAPTURE_PUBLISH PASSED\033[0m\n'
