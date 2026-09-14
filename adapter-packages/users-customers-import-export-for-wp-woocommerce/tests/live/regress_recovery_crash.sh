#!/usr/bin/env bash
set -euo pipefail
# Same database/canonical oracle and owned pair; actual SIGKILL omits all PHP cleanup.
export IMPORTER_RECOVERY_FAULT_MODE=kill
exec bash "$(dirname "${BASH_SOURCE[0]}")/regress_recovery.sh"
