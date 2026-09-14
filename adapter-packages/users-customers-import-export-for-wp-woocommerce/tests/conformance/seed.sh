#!/usr/bin/env bash
set -euo pipefail
. "$(dirname "${BASH_SOURCE[0]}")/../../fixtures/settings-seed.sh"
. "$(dirname "${BASH_SOURCE[0]}")/../../fixtures/roundtrip.sh"
importer_roundtrip_begin
importer_roundtrip_capture source-export importer_roundtrip_native 1 templates-native setup-source
importer_roundtrip_capture source-import importer_roundtrip_native 1 import-templates-native setup-source
importer_roundtrip_capture source importer_roundtrip_observe 1
