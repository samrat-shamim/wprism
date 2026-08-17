#!/usr/bin/env bash
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/../.." && pwd -P)
php -l "$ROOT/agent/src/Adapter/ScopedCertificationBundle.php" >/dev/null
php -l "$ROOT/agent/src/Adapter/CapabilityRegistry.php" >/dev/null
php -l "$ROOT/sandbox/tests/regress_scoped_certification_bundle.php" >/dev/null
php "$ROOT/sandbox/tests/regress_scoped_certification_bundle.php"
