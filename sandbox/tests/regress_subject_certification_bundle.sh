#!/usr/bin/env bash
# Pure-PHP build/import/expiry coverage for independent subject certificates.
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/../.." && pwd -P)
php -l "$ROOT/sandbox/bin/subject-certification-bundle.php" >/dev/null
php -l "$ROOT/sandbox/tests/regress_subject_certification_bundle.php" >/dev/null
bash -n "$ROOT/sandbox/tests/certify_version_matrix.sh"
php "$ROOT/sandbox/tests/regress_subject_certification_bundle.php"
