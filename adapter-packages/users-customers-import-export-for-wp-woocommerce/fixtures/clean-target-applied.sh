#!/usr/bin/env bash
set -euo pipefail
. "$(dirname "${BASH_SOURCE[0]}")/roundtrip.sh"
importer_roundtrip_capture after importer_roundtrip_observe 2
php "$IMPORTER_PACKAGE_ROOT/fixtures/clean-target-evidence.php" created "$IMPORTER_EVIDENCE" "$CONF_PAIR"
