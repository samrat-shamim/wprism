#!/usr/bin/env bash
set -euo pipefail
. "$(dirname "${BASH_SOURCE[0]}")/../../fixtures/settings-capture-check.sh"
. "$(dirname "${BASH_SOURCE[0]}")/../../fixtures/roundtrip.sh"
importer_roundtrip_capture source-enrolled importer_roundtrip_observe 1
