#!/usr/bin/env bash
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/../.." && pwd -P)
php -l "$ROOT/agent/src/ScopedCertificationBundle.php" >/dev/null
php -l "$ROOT/agent/src/CapabilityRegistry.php" >/dev/null
php -l "$ROOT/sandbox/tests/regress_scoped_certification_bundle.php" >/dev/null
php "$ROOT/sandbox/tests/regress_scoped_certification_bundle.php"
