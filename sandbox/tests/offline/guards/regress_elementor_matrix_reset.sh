#!/usr/bin/env bash
# Regression — exact version boundaries reuse persistent webroot volumes, so
# the reset must remove uploads as well as database content. Without
# `--uploads`, WooCommerce's 11.0.0 placeholder derivatives survived into the
# standalone 11.0.1 leg and correctly tripped the unowned-file collision gate.
# The reset must then recreate the ordinary uploads root that apply requires.
# DUO-3366 also requires Elementor's active-kit option to be removed before
# `site empty` deletes its target post. Woo's active Review Order endpoint must
# likewise be disabled before that deletion or init:4 recreates its page during
# the next reset command. This guard pins all three reset contracts.
set -euo pipefail
cd "$(dirname "$0")/../../.."   # -> sandbox/

pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }

F=tests/certify/certify_version_matrix.sh
[ -f "$F" ] || fail "$F is missing"

python3 - "$F" <<'PY'
from pathlib import Path
import json
import sys

path = Path(sys.argv[1])
text = path.read_text(encoding="utf-8")
start = text.index("reset_env() {")
end = text.index("\n}\n\nrun_elementor_command", start)
reset = text[start:end]

delete = '"$cli" option delete elementor_active_kit >/dev/null 2>&1 || true'
empty = '"$cli" site empty --yes --uploads >/dev/null'
uploads_restore = 'version-matrix reset could not restore uploads root'
if delete not in reset:
    raise SystemExit("reset_env no longer deletes Elementor's active-kit option")
if empty not in reset:
    raise SystemExit("reset_env no longer clears persistent uploads at the site-empty boundary")
if uploads_restore not in reset or 'wp_get_upload_dir()' not in reset or 'wp_mkdir_p($root)' not in reset:
    raise SystemExit("reset_env no longer recreates and verifies the WordPress uploads root")
if reset.index(empty) >= reset.index(uploads_restore):
    raise SystemExit("reset_env verifies the uploads root before site empty can remove it")
if reset.index(delete) >= reset.index(empty):
    raise SystemExit("Elementor active-kit cleanup happens after site empty; the null-post warning can return")
woo_review_options = (
    'woocommerce_feature_customer_review_request_enabled',
    'woocommerce_review_order_page_id',
    'woocommerce_review_order_flush_rewrite_pending',
)
for option in woo_review_options:
    if option not in reset:
        raise SystemExit(f"reset_env no longer clears WooCommerce Review Order option {option}")
    if reset.index(option) >= reset.index(empty):
        raise SystemExit(f"WooCommerce Review Order option {option} is cleared after site empty; init can recreate its host page")
if 'version-matrix reset retained WooCommerce Review Order option' not in reset:
    raise SystemExit("reset_env no longer verifies WooCommerce Review Order option deletion")
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

entry = json.loads(
    Path("../adapter-packages/elementor/tests/conformance/entry.json").read_text(encoding="utf-8")
)["entry"]
if "elementor_library_type" not in entry.get("taxonomies", []):
    raise SystemExit("Elementor package fixture omits its native library taxonomy")
PY

pass "Elementor fixtures pin native taxonomy scope, machine-readable receipts, reset order, and boundary stderr"
echo "REGRESS_ELEMENTOR_MATRIX_RESET PASSED"
