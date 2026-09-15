#!/usr/bin/env bash
set -euo pipefail
export IMPORTER_WOO_APPLY_MODE=scoped
exec bash "$(dirname "${BASH_SOURCE[0]}")/regress_importer_woocommerce_apply.sh" "$@"
