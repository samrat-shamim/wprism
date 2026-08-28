#!/usr/bin/env bash
# Regression — exact version boundaries reuse persistent webroot volumes, so
# the reset must remove uploads as well as database content. Without
# `--uploads`, WooCommerce's 11.0.0 placeholder derivatives survived into the
# standalone 11.0.1 leg and correctly tripped the unowned-file collision gate.
# The reset must then recreate the ordinary uploads root that apply requires.
# Adapter-owned pre-empty hooks remove Elementor's active-kit reference and
# disable Woo's active Review Order endpoint before shared `site empty` runs.
# This guard pins package ownership and the shared hook ordering together.
set -euo pipefail
cd "$(dirname "$0")/../../.."   # -> sandbox/

pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }

F=tests/certify/certify_version_matrix.sh
ELEMENTOR=../adapter-packages/elementor/tests/certify/version-matrix.sh
WOO=../adapter-packages/woocommerce/tests/certify/version-matrix.sh
[ -f "$F" ] && [ -f "$ELEMENTOR" ] && [ -f "$WOO" ] \
  || fail 'shared matrix or package-owned reset capsule is missing'

python3 - "$F" "$ELEMENTOR" "$WOO" <<'PY'
from pathlib import Path
import json
import sys

path = Path(sys.argv[1])
text = path.read_text(encoding="utf-8")
elementor = Path(sys.argv[2]).read_text(encoding="utf-8")
woo = Path(sys.argv[3]).read_text(encoding="utf-8")
start = text.index("reset_env() {")
end = text.index("\n}\n", start)
reset = text[start:end]

delete = '"$cli" option delete elementor_active_kit >/dev/null 2>&1 || true'
empty = '"$cli" site empty --yes --uploads >/dev/null'
uploads_restore = 'version-matrix reset could not restore uploads root'
hook = 'version_matrix_reset_before_empty "$cli"'
if hook not in reset or reset.index(hook) >= reset.index(empty):
    raise SystemExit("shared reset no longer invokes the selected pre-empty hook before site empty")
if empty not in reset:
    raise SystemExit("reset_env no longer clears persistent uploads at the site-empty boundary")
if uploads_restore not in reset or 'wp_get_upload_dir()' not in reset or 'wp_mkdir_p($root)' not in reset:
    raise SystemExit("reset_env no longer recreates and verifies the WordPress uploads root")
if reset.index(empty) >= reset.index(uploads_restore):
    raise SystemExit("reset_env verifies the uploads root before site empty can remove it")
if 'version_matrix_reset_before_empty() {' not in elementor or delete not in elementor:
    raise SystemExit("Elementor capsule no longer owns its active-kit pre-empty cleanup")
woo_review_options = (
    'woocommerce_feature_customer_review_request_enabled',
    'woocommerce_review_order_page_id',
    'woocommerce_review_order_flush_rewrite_pending',
)
for option in woo_review_options:
    if option not in woo:
        raise SystemExit(f"WooCommerce capsule no longer clears Review Order option {option}")
if 'version_matrix_reset_before_empty() {' not in woo:
    raise SystemExit("WooCommerce capsule no longer owns its pre-empty cleanup")
if 'version-matrix reset retained WooCommerce Review Order option' not in woo:
    raise SystemExit("WooCommerce capsule no longer verifies Review Order option deletion")
if 'woocommerce-placeholder' in reset or 'conf-woo-category' in reset:
    raise SystemExit("reset_env substituted a Woo filename cleanup for complete upload-volume isolation")

required = (
    "ELEMENTOR_STDERR_LOG=$(mktemp",
    "local command_log rc",
    '"$@" 2>"$command_log" || rc=$?',
    'cat "$command_log" >>"$ELEMENTOR_STDERR_LOG"',
    'cat "$command_log" >&2',
    'return "$rc"',
    "run_elementor_command reset_env wp1",
    "run_elementor_command check_elementor_content",
    '--revision="$REV" --format=json | tee "$VMATRIX_APPLY_LOG"',
    'require_duo_answered "Elementor $ELEMENTOR_VERSION apply" json',
    "jq -e '.canary == \"clean\"' \"$VMATRIX_APPLY_LOG\"",
    "in-place upgrade: elementor 4.0.0 -> 4.2.3",
    "Elementor 4.0.0 to 4.2.3 upgrade apply",
    "elementor 4.0.0 -> 4.2.3 in-place upgrade preserves native rendering",
    "ELEMENTOR_WARNING_MATCHES=$(grep -nE",
    "elementor/core/isolation/elementor-adapter",
    "elementor/core/base/document",
)
missing = [marker for marker in required if marker not in elementor]
if missing:
    raise SystemExit("matrix lost its Elementor warning regression contract: " + ", ".join(missing))

boundary_end = elementor.rindex("\n}")
guard = elementor.index("ELEMENTOR_WARNING_MATCHES=", elementor.index("for ELEMENTOR_VERSION"))
recapture = elementor.index("byte-identical recapture", elementor.index("for ELEMENTOR_VERSION"))
if guard < recapture:
    raise SystemExit("Elementor warning guard runs before the full boundary recapture")
if guard >= boundary_end:
    raise SystemExit("Elementor warning guard escaped the Elementor boundary block")

entry = json.loads(
    Path("../adapter-packages/elementor/tests/conformance/entry.json").read_text(encoding="utf-8")
)["entry"]
if "elementor_library_type" not in entry.get("taxonomies", []):
    raise SystemExit("Elementor package fixture omits its native library taxonomy")
PY

pass "Elementor fixtures pin native taxonomy scope, machine-readable receipts, reset order, and boundary stderr"
echo "REGRESS_ELEMENTOR_MATRIX_RESET PASSED"
