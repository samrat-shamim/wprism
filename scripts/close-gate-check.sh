#!/usr/bin/env bash
# Distributed LINEAR-LOOP close gate (docs/agents/linear-loop.md), simplified
# from genesis-monorepo's dispatch-close-gate-check.sh: after `gh pr merge
# --squash` reports success, PROVE the merge before touching Linear. Exits
# non-zero on any failure; the final stdout line on success is the literal
# evidence string the close comment must quote.
#
# Checks, in order:
#   1. GitHub says the PR is MERGED and reports a 40-hex mergeCommit oid.
#      (The squash SHA MUST come from the API — the local branch HEAD is the
#      pre-squash commit and may differ from what landed on main.)
#   2. The merge commit has exactly one parent (a squash — this repo disables
#      merge-commit and rebase-merge strategies; a multi-parent commit here
#      means someone changed repo settings, which is itself a finding).
#   3. The squash SHA is an ancestor of freshly-fetched origin/main. The race
#      between fetch and merge-base is harmless: origin/main only moves
#      forward, so a concurrent merge cannot un-ancestor an ancestor.
#
# Usage: bash scripts/close-gate-check.sh <pr-number>
set -euo pipefail
PR="${1:-}"
[ -n "$PR" ] || { echo "usage: close-gate-check.sh <pr-number>" >&2; exit 1; }

JSON=$(gh pr view "$PR" --json state,mergeCommit) || { echo "FAIL: gh pr view $PR failed" >&2; exit 1; }
STATE=$(echo "$JSON" | jq -r '.state')
SHA=$(echo "$JSON" | jq -r '.mergeCommit.oid // empty')
[ "$STATE" = "MERGED" ] || { echo "FAIL: PR $PR state is '$STATE', not MERGED" >&2; exit 1; }
echo "$SHA" | grep -qE '^[0-9a-f]{40}$' || { echo "FAIL: PR $PR has no 40-hex mergeCommit oid (got: '$SHA')" >&2; exit 1; }

git fetch -q origin main

PARENTS=$(git cat-file -p "$SHA" | grep -c '^parent ' || true)
[ "$PARENTS" = "1" ] || { echo "FAIL: merge commit $SHA has $PARENTS parents — not a squash merge" >&2; exit 1; }

git merge-base --is-ancestor "$SHA" origin/main \
    || { echo "FAIL: $SHA is not an ancestor of origin/main — do NOT mark the issue Done (phantom-Done class); comment the discrepancy on the issue instead" >&2; exit 1; }

echo "squash: $SHA"
echo "merge-base --is-ancestor: ok"
