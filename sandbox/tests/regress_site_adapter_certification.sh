#!/usr/bin/env bash
# Offline regression for signed, plugin-blind site-adapter certification.
set -euo pipefail
cd "$(dirname "$0")/../.."

php -l agent/src/AdapterCertification.php >/dev/null
php -l agent/src/ManifestDispositions.php >/dev/null
php -l agent/src/CapabilityRegistry.php >/dev/null
php -l scripts/adapter-certification.php >/dev/null
php -l sandbox/tests/regress_site_adapter_certification.php >/dev/null
php sandbox/tests/regress_site_adapter_certification.php
