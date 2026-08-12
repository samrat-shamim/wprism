#!/usr/bin/env bash
# Regression — DUO-3366: the exact Elementor version-boundary matrix must
# remove Elementor's active-kit option before `site empty` deletes its target
# post. Without that ordering, the next exact 4.2.2 lifecycle dereferences a
# null post and emits a PHP warning while the matrix still reports success.
# The live matrix also scans the captured boundary stderr; this offline check
# pins the reset contract so a future cleanup edit cannot silently reintroduce
# the stale reference.
set -euo pipefail
cd "$(dirname "$0")/.."   # -> sandbox/

pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }

F=tests/certify_version_matrix.sh
[ -f "$F" ] || fail "$F is missing"

python3 - "$F" <<'PY'
from pathlib import Path
import sys

path = Path(sys.argv[1])
text = path.read_text(encoding="utf-8")
start = text.index("reset_env() {")
end = text.index("\n}\n\nrun_elementor_command", start)
reset = text[start:end]

delete = '"$cli" option delete elementor_active_kit >/dev/null 2>&1 || true'
empty = '"$cli" site empty --yes >/dev/null'
if delete not in reset:
    raise SystemExit("reset_env no longer deletes Elementor's active-kit option")
if empty not in reset:
    raise SystemExit("reset_env no longer contains the site-empty boundary")
if reset.index(delete) >= reset.index(empty):
    raise SystemExit("Elementor active-kit cleanup happens after site empty; the null-post warning can return")

required = (
    "ELEMENTOR_STDERR_LOG=$(mktemp",
    "run_elementor_command reset_env wp1",
    "run_elementor_command check_elementor_content",
    "ELEMENTOR_WARNING_MATCHES=$(grep -nE",
    "elementor/core/isolation/elementor-adapter",
    "elementor/core/base/document",
)
missing = [marker for marker in required if marker not in text]
if missing:
    raise SystemExit("matrix lost its Elementor warning regression contract: " + ", ".join(missing))

boundary_end = text.index("\nfor CF7_VERSION", text.index("for ELEMENTOR_VERSION"))
guard = text.index("ELEMENTOR_WARNING_MATCHES=", text.index("for ELEMENTOR_VERSION"))
recapture = text.index("byte-identical recapture", text.index("for ELEMENTOR_VERSION"))
if guard < recapture:
    raise SystemExit("Elementor warning guard runs before the full boundary recapture")
if guard >= boundary_end:
    raise SystemExit("Elementor warning guard escaped the Elementor boundary block")
PY

pass "Elementor matrix removes the stale active-kit reference before site empty and guards the exact boundary stderr"
echo "REGRESS_ELEMENTOR_MATRIX_RESET PASSED"
