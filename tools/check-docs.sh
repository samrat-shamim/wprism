#!/usr/bin/env bash
# Current docs share the same local gate after entry points are consolidated.
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"
bash tools/check-guide-commands.sh
python3 tools/check-doc-links.py
