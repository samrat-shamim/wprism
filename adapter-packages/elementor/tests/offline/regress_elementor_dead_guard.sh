#!/usr/bin/env bash
# Regression — issue #3393: checks/elementor.sh reads conf2's own local id for the
# seeded page with `PAGE_ID=$(wp post list … --field=ID)`. `wp post list` returns
# EMPTY at exit 0 on no match, so a `|| fail` guard there is DEAD (never fires) —
# the no-match instead surfaces two lines down as the confusing `… post id ()`
# (empty interpolation). The read must be a standalone assignment guarded by
# require_fixture_ids (the issue #3381 helper), which names an empty/non-numeric id
# at the read site. Found in issue #3381's 26-file audit. Offline static check.
set -euo pipefail
REPO_ROOT="$(cd "$(dirname "$0")/../../../.." && pwd)"
cd "$REPO_ROOT"

pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }

F=adapter-packages/elementor/tests/conformance/check.sh
[ -f "$F" ] || fail "$F is missing"

# The seeded-page id read must be a STANDALONE assignment — a line ending in `)`,
# not `) \` continuing to a dead `|| fail` (the empty-at-exit-0 shape).
grep -qE 'PAGE_ID=\$\(wp_conf2 post list .*--field=ID\)$' "$F" \
  || fail "$F's seeded-page id read is not a standalone assignment — it still continues to a dead '|| fail' (wp post list exits 0 on no match, so it never fires)"
# And it must be guarded by require_fixture_ids at the read site.
grep -qE '^require_fixture_ids PAGE_ID$' "$F" \
  || fail "$F no longer guards the seeded-page id read with 'require_fixture_ids PAGE_ID' (issue #3393 dead-guard fix)"
pass "elementor.sh guards its seeded-page id read with require_fixture_ids, not a dead post-list '|| fail'"

echo "REGRESS_ELEMENTOR_DEAD_GUARD PASSED"
