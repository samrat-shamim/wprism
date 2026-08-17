#!/usr/bin/env bash
# Offline half of DUO-3206's regression coverage. The prior implementation
# had no checked mutation abstraction, treated wpdb false like success, and
# read insert_id zero into the ledger; this harness therefore fails against
# the prior defect before it can make any database changes.
set -euo pipefail
cd "$(dirname "$0")"

php -l ../../agent/src/Kernel/Db.php >/dev/null
php -l regress_fatal_mutations.php >/dev/null
php regress_fatal_mutations.php

printf '\033[1;32m✔ REGRESS_FATAL_MUTATIONS_UNIT PASSED\033[0m\n'
