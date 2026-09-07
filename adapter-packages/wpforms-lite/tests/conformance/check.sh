#!/usr/bin/env bash
# capture-plan exits before this hook. A future mode edit must not turn a
# source-only proof into target acceptance without real round-trip assertions.
set -euo pipefail
printf '%s\n' 'WPForms preview has no target round-trip evidence; capture-plan is the only admitted conformance mode.' >&2
exit 1
