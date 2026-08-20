#!/usr/bin/env bash
# Regression — DUO-3362: grind_r1c_agency.sh must NOT regenerate the committed
# manifests/duo-agency-cpt.json wholesale from `policy-to-manifest` output. Since
# DUO-3338 that manifest carries hand-authored `providers`/`actions` (the shipped
# proof a custom plugin can advertise a provider via the `duo_providers` filter)
# plus rationale `notes`, none of which policy-to-manifest emits — a wholesale
# `> ../manifests/duo-agency-cpt.json` silently deleted them, and nothing failed
# until the live provider-contract regression next ran. The grind must instead
# export to a SCRATCH path and verify the committed file's classification
# sections match, preserving the hand-authored blocks. Offline static check.
set -euo pipefail
cd "$(dirname "$0")/.."   # -> sandbox/

pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }

G=tests/grind/grind_r1c_agency.sh
M=../manifests/duo-agency-cpt.json
[ -f "$G" ] || fail "$G is missing"
[ -f "$M" ] || fail "$M is missing"

# The committed manifest must actually carry the hand-authored blocks the
# exporter omits — otherwise there is nothing to protect.
jq -e '(.providers // [] | length) > 0 and (.actions // [] | length) > 0' "$M" >/dev/null \
  || fail "$M carries no providers/actions to protect — wrong fixture, or the blocks were already lost"

# The grind must NOT redirect policy-to-manifest output over the committed file
# (comment lines that merely describe the old bug are excluded).
if grep -nE '>[[:space:]]*\.\./manifests/duo-agency-cpt\.json' "$G" | grep -vE '^[0-9]+:[[:space:]]*#'; then
  fail "$G still overwrites the committed manifest wholesale (deletes hand-authored providers/actions)"
fi

# It must still exercise policy-to-manifest, write the export to a scratch path,
# and assert the committed manifest's providers/actions are preserved.
grep -qE 'policy-to-manifest' "$G" \
  || fail "$G no longer exercises policy-to-manifest at all"
grep -qE '\.tmp-agency-manifest-export\.json' "$G" \
  || fail "$G does not write the policy-to-manifest export to a scratch path"
grep -qE 'lost its hand-authored providers/actions' "$G" \
  || fail "$G does not assert the committed manifest's hand-authored providers/actions survive"

pass "grind_r1c exports policy-to-manifest to a scratch path and preserves the committed manifest's hand-authored providers/actions (no wholesale overwrite)"

echo "REGRESS_GRIND_R1C_MANIFEST_PRESERVE PASSED"
