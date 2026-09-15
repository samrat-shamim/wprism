#!/usr/bin/env bash
set -euo pipefail
SCENARIO_ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)
REPOSITORY_ROOT=$(cd "$SCENARIO_ROOT/../.." && pwd -P)
bash "$REPOSITORY_ROOT/adapter-packages/qi-blocks/tests/conformance/seed.sh"
bash "$REPOSITORY_ROOT/adapter-packages/visual-portfolio/tests/conformance/seed.sh"
pass 'Qi Blocks and Visual Portfolio authored their package-owned native source fixtures together'
