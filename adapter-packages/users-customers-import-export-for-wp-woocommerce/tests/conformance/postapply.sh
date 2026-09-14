#!/usr/bin/env bash
set -euo pipefail
. "$(dirname "${BASH_SOURCE[0]}")/../../fixtures/roundtrip.sh"
importer_roundtrip_capture after importer_roundtrip_observe 2
php "$IMPORTER_PACKAGE_ROOT/fixtures/roundtrip-evidence.php" applied "$IMPORTER_EVIDENCE" "$CONF_PAIR"
pass 'Importer full Apply preserves all local rows/files and materializes the complete authored forms'
