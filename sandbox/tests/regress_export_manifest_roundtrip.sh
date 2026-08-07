#!/usr/bin/env bash
# Regression — DUO-3284: Policy::export_manifest()'s output must be
# loadable by Policy::load() — the round trip `wp duo policy-to-manifest`
# promises but which was never actually exercised end to end until this
# test, which is why export_manifest() silently produced manifests
# missing spec_version for as long as DUO-3247's mandatory-spec_version
# gate has existed. Pure PHP, no docker: uses fake fixture manifests via
# DUO_MANIFESTS_DIR and a fake site repo, never the real shipped
# manifests — this file proves the MECHANISM (export and load agree),
# independent of any real site's content.
set -euo pipefail
cd "$(dirname "$0")"   # -> sandbox/tests/
say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }

command -v php >/dev/null || fail "php required on PATH"

say "php -l syntax check"
php -l regress_export_manifest_roundtrip.php >/dev/null || fail "regress_export_manifest_roundtrip.php has a syntax error"
pass "no syntax errors"

say "running the offline harness (export_manifest() -> load() round trip, plus the negative control proving this test would have caught DUO-3284)"
php regress_export_manifest_roundtrip.php || fail "regress_export_manifest_roundtrip.php reported failing checks (see output above)"

printf '\n\033[1;32m✔ REGRESS_EXPORT_MANIFEST_ROUNDTRIP PASSED\033[0m\n'
