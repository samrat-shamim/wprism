#!/usr/bin/env bash
# capture-plan terminates before target apply, so this hook is unreachable
# today. It refuses rather than passing: the dual shortcode-id resolution and
# the undeclarable widget module_id must have real round-trip evidence before
# any mode change can turn a source-only proof into target acceptance.
set -euo pipefail
printf '%s\n' 'Hustle has no target round-trip evidence; capture-plan is the only admitted conformance mode.' >&2
exit 1
