#!/usr/bin/env bash
set -euo pipefail
PACKAGE_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
export WPRISM_ARTIFACT_PACKAGE="${PACKAGE_ROOT##*/}"
# Same database/canonical oracle and owned pair; actual SIGKILL omits all PHP cleanup.
export IMPORTER_RECOVERY_FAULT_MODE=kill
exec bash "$(dirname "${BASH_SOURCE[0]}")/regress_recovery.sh"
