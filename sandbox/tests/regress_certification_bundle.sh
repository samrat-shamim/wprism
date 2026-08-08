#!/usr/bin/env bash
# Offline DUO-3223 evidence-contract regression: valid, expired, corrupt,
# failed-checker, and human/machine/exit agreement. No Docker pair.
set -euo pipefail
cd "$(dirname "$0")"

php -l ../bin/certification-bundle.php >/dev/null
php -l regress_certification_bundle.php >/dev/null
php regress_certification_bundle.php
