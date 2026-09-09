#!/usr/bin/env bash
# Source conformance cannot silently become target qualification after a mode edit.
set -euo pipefail
printf '%s\n' 'Qi target render, lifecycle, recovery and host-promotion conformance remain unqualified; this entry exercises source capture-plan only.' >&2
exit 1
