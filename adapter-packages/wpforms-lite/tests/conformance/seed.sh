#!/usr/bin/env bash
set -euo pipefail
. "$(dirname "${BASH_SOURCE[0]}")/../../fixtures/capture-plan/run-native.sh"
SEED_OBSERVATION=$(wpforms_capture_native seed)
require_observed_nonempty "WPForms native source seed" "$SEED_OBSERVATION"
printf '%s\n' "$SEED_OBSERVATION"
pass "WPForms forms, template, confirmations and embeds authored through native APIs"
