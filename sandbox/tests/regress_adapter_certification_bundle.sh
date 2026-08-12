#!/usr/bin/env bash
# Pure-PHP build/import/expiry coverage for one scoped adapter certificate.
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/../.." && pwd -P)
php -l "$ROOT/sandbox/bin/adapter-certification-bundle.php" >/dev/null
php -l "$ROOT/sandbox/tests/regress_adapter_certification_bundle.php" >/dev/null
bash -n "$ROOT/sandbox/tests/certify_version_matrix.sh"
php "$ROOT/sandbox/tests/regress_adapter_certification_bundle.php"
