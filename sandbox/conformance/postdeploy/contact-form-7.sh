#!/usr/bin/env bash
# Force a real cross-environment post-id divergence before CF7 state applies.
# Both clean-room sides otherwise allocate CF7's activation-created default
# form and the seeded form in the same order, so their numeric ids happen to
# match and the render check cannot prove that the captured hash resolves to
# the target's own identity. A disposable target-only post advances MySQL's
# AUTO_INCREMENT without leaving any target-local row for reconciliation.
# Invoked by conformance/run.sh after deploy and before apply.
set -euo pipefail

FILLER_ID=$(wp_conf2 post create --post_type=post --post_status=draft \
  --post_title='CF7 conformance identity spacer' --porcelain)
require_fixture_ids FILLER_ID
wp_conf2 post delete "$FILLER_ID" --force >/dev/null
pass "advanced conf2's post id sequence with disposable post $FILLER_ID so CF7 identity portability is exercised"
