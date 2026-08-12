#!/usr/bin/env bash
# Regression — DUO-3449: the dispatch protocol must freeze the reviewed
# candidate before certification. A previous ordering told agents to rebase
# immediately before launching the bundle; unrelated main movement could then
# invalidate a reviewed candidate or a running attestation. This is a pure
# source-contract check: it executes no docker, pair, git mutation, or Linear
# call, and fails if the protocol loses either the freeze sequence or any of
# the existing exact-source/close-gate safeguards.
set -euo pipefail
cd "$(dirname "$0")/../.."

say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*" >&2; exit 1; }

DOC=docs/agents/linear-loop.md
[ -f "$DOC" ] || fail "missing $DOC"

check_contract() {
    python3 - "$1" <<'PY'
from pathlib import Path
import re
import sys

path = Path(sys.argv[1])
text = path.read_text(encoding="utf-8")

required = [
    "**Ordering: freeze the reviewed candidate before bundle certification.**",
    "rebase once → final independent review of exact",
    "record/freeze C+B",
    "bundle at C",
    "deterministic import/generate",
    "Record candidate `C`, base `B`, and the bundle bound-input closure",
    "do not rebase merely because",
    "If no\n   candidate freeze `C+B` is recorded",
    "resume and review/bundle the exact frozen `C` instead",
    "explicitly break the",
    "preserve the immutable old bundle as historical evidence",
    "silently relabel old evidence current",
    "Merge the frozen PR if",
]
for needle in required:
    if needle not in text:
        raise SystemExit(f"missing required freeze rule: {needle}")

order = [
    "rebase once → final independent review of exact",
    "record/freeze C+B",
    "bundle at C",
    "deterministic import/generate",
    "merge promptly",
]
positions = [text.index(needle) for needle in order]
if positions != sorted(positions):
    raise SystemExit(f"freeze sequence is out of order: {positions}")

close_gate = text.index("## Close gate (strict order)")
freeze = text.index("**Ordering: freeze the reviewed candidate before bundle certification.**")
if freeze >= close_gate:
    raise SystemExit("freeze rule must precede the close-gate procedure")

# This exact stale instruction caused the defect and must not return. Keep the
# check literal so a future wording change has to update this regression and
# consciously re-prove the new sequencing.
for forbidden in (
    "Re-fetch and rebase onto `origin/main` immediately before launching",
    "rebase onto fresh `origin/main` → bundle",
    "post-bundle rebase",
):
    if forbidden in text:
        raise SystemExit(f"forbidden post-review rebase instruction remains: {forbidden}")

for safeguard in (
    "DUO_EXPECTED_SOURCE_SHA",
    "bundle import validation",
    "force-hatch refusal",
    "close-gate-check.sh",
    "merge-base --is-ancestor: ok",
):
    if safeguard not in text:
        raise SystemExit(f"existing safeguard disappeared while adding freeze rule: {safeguard}")

# Rebase language after the close-gate heading would be a post-bundle escape
# hatch, not the permitted pre-freeze preparation.
if re.search(r"(?s)## Close gate \(strict order\).*?\brebase\b", text):
    raise SystemExit("close-gate section contains a post-freeze rebase instruction")
if re.search(r"(?s)Re-entering an interrupted issue.*?rebase it onto", text):
    raise SystemExit("interrupted-issue re-entry still unconditionally rebases a frozen candidate")
PY
}

say "protocol source contract: freeze sequence and break rule"
check_contract "$DOC"
pass "reviewed C over B is frozen before bundle, with explicit break/repeat semantics"

say "protocol source contract: mutate the doc and prove the guard turns red"
TMP=$(mktemp -d "${TMPDIR:-/tmp}/duo-3449.XXXXXX")
trap 'rm -rf -- "$TMP"' EXIT
cp -- "$DOC" "$TMP/linear-loop.md"
printf '\nRe-fetch and rebase onto `origin/main` immediately before launching.\n' >> "$TMP/linear-loop.md"
if check_contract "$TMP/linear-loop.md" 2>"$TMP/mutation.err"; then
    fail "synthetic stale rebase instruction was not rejected"
fi
grep -qF -- "forbidden post-review rebase instruction remains: Re-fetch and rebase onto \`origin/main\` immediately before launching" "$TMP/mutation.err" \
    || { cat "$TMP/mutation.err" >&2; fail "synthetic stale rebase mutation failed for an unexpected reason"; }
pass "synthetic stale post-review rebase instruction is rejected"

printf '\n\033[1;32m✔ REGRESS_LINEAR_LOOP_FREEZE PASSED\033[0m\n'
