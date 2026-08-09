#!/usr/bin/env bash
# Regression — DUO-3327: `duo manifest-validate`, the adapter author's offline
# grammar check, plus the machine-readable grammar document it emits.
#
# The whole point of the command is that a manifest is refusable with nothing
# installed, so this suite is the same shape: it runs the REAL host CLI as a
# subprocess against REAL manifest fixture files under a scratch directory, and
# reads the real exit codes and the real stdout/stderr. No docker, no sandbox
# pair, no WordPress bootstrap, no $wpdb stub, nothing mocked. Same idiom as
# sandbox/tests/regress_adapter_contract.sh (DUO-3222/DUO-3243) and
# sandbox/tests/regress_vocabulary_ownership.sh (DUO-3318), whose two-adapter
# fixtures this suite imports rather than copies
# (sandbox/tests/manifest_fixtures.php).
#
# Covered: every acceptance-1 category (invalid keys, shapes, ranges,
# exclusivity rules, action names, provider declarations) producing its precise
# path; the deferred live-target list appearing on passing runs, failing runs,
# and in the schema document; the emitted grammar matching the engine's own
# accessors field for field; every published vocabulary checked in BOTH
# directions against the runtime validator; exit codes; the command's own
# fail-closed IO paths; and every SHIPPED manifest validating through the
# command as the real-world smoke.
#
# What this does NOT cover, deliberately: everything the command itself reports
# as deferred — live table schema, taxonomy_patterns expansion, installed
# plugin/theme versions, provider negotiation, native-action execution,
# capability evaluation against a target, and lint's live id cross-reference.
# Those have their own live suites; this one proves the command SAYS it did not
# do them.
set -euo pipefail
cd "$(dirname "$0")"   # -> sandbox/tests/
say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }

command -v php >/dev/null || fail "php required on PATH"

say "php -l syntax check (harness, fixtures, and every file it exercises)"
php -l regress_manifest_validate.php >/dev/null || fail "regress_manifest_validate.php has a syntax error"
php -l manifest_fixtures.php >/dev/null || fail "manifest_fixtures.php has a syntax error"
php -l ../../cli/duo >/dev/null || fail "cli/duo has a syntax error"
php -l ../../cli/src/ManifestValidate.php >/dev/null || fail "cli/src/ManifestValidate.php has a syntax error"
php -l ../../agent/src/Policy.php >/dev/null || fail "agent/src/Policy.php has a syntax error"
php -l ../../agent/src/NativeActions.php >/dev/null || fail "agent/src/NativeActions.php has a syntax error"
pass "no syntax errors"

say "the command is WordPress-free by construction — assert it, don't assume it"
# ManifestValidate reaches the engine's pure validators only. A WordPress
# function call on this path would work on a target and fail here, which is
# precisely the class of bug an offline authoring aid must not have. Full-line
# comments are stripped first: this file's own docblock explains why it does
# NOT define is_multisite(), and prose naming a function is not a call to it
# (same false-positive shape regress_bundle_coverage.sh documents).
if grep -vE '^[[:space:]]*(\*|//|/\*)' ../../cli/src/ManifestValidate.php \
    | grep -nE '\b(wp_[a-z_]+|get_option|is_multisite|add_action|apply_filters)[[:space:]]*\('; then
  fail "cli/src/ManifestValidate.php reaches a WordPress function — this command must run with no WordPress present"
fi
pass "no WordPress function is reachable from the handler"

say "running the offline harness (precise paths, deferred list, schema derivation, exit codes, shipped manifests)"
php regress_manifest_validate.php || fail "regress_manifest_validate.php reported failing checks (see output above)"

printf '\n\033[1;32m✔ REGRESS_MANIFEST_VALIDATE PASSED\033[0m\n'
