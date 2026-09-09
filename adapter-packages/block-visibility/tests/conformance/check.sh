#!/usr/bin/env bash
# capture-plan mode terminates before target apply, so this target hook is
# never reached today. It refuses rather than passing: this capsule withholds
# the plugin's primary surface, and a future mode edit must not turn a
# source-only proof into target acceptance without the round-trip evidence the
# disposition still says does not exist.
set -euo pipefail
printf '%s\n' 'Block Visibility has no target round-trip evidence; capture-plan is the only admitted conformance mode.' >&2
exit 1
