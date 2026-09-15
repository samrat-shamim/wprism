#!/usr/bin/env bash
set -euo pipefail
SCENARIO_ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)
REPOSITORY_ROOT=$(cd "$SCENARIO_ROOT/../.." && pwd -P)
bash "$REPOSITORY_ROOT/adapter-packages/qi-blocks/tests/conformance/capture-check.sh"
bash "$REPOSITORY_ROOT/adapter-packages/visual-portfolio/tests/conformance/capture-check.sh"
pass 'both package-owned native observers agree with their shared canonical source'
