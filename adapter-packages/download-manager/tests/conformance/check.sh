#!/usr/bin/env bash
# capture-plan mode terminates before target apply, so this target hook is
# never reached today. It refuses rather than passing: a future mode edit must
# not turn a source-only proof into target acceptance without the round-trip,
# deletion and lifecycle assertions the disposition still withholds.
set -euo pipefail
printf '%s\n' 'Download Manager has no target round-trip evidence; capture-plan is the only admitted conformance mode.' >&2
exit 1
