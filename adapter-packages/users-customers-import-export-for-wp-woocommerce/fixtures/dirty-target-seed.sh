#!/usr/bin/env bash
set -euo pipefail
. "$(dirname "${BASH_SOURCE[0]}")/../tests/conformance/seed.sh"
for kind in import export; do
  importer_roundtrip_capture "historical-$kind" importer_roundtrip_native 1 dirty-target-native "$kind" historical
done
