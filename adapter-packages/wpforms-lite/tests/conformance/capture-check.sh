#!/usr/bin/env bash
set -euo pipefail
. "$(dirname "${BASH_SOURCE[0]}")/../../fixtures/capture-plan/run-native.sh"
CAPTURE_OBSERVATION=$(wpforms_capture_native observe)
require_observed_nonempty "WPForms native capture observation" "$CAPTURE_OBSERVATION"
printf '%s\n' "$CAPTURE_OBSERVATION"
pass "WPForms native consumers and complete canonical bodies agree; no target apply claim"
