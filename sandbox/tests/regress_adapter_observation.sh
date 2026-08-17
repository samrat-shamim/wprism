#!/usr/bin/env bash
# DUO-3340: closed target observation + strict host transport regression.
# Offline only: fake wpdb/WP hook fixtures exercise the observer-owned
# SELECT/no-explicit-Duo-mutation boundary, redaction/schema/hash validation,
# one configured target call, and atomic create-only local evidence output.
set -euo pipefail
cd "$(dirname "$0")"

say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*" >&2; exit 1; }

say "syntax checks"
for file in \
  regress_adapter_observation.php \
  ../../agent/src/Adapter/AdapterObservation.php ../../agent/src/Review/Journal.php ../../agent/src/Review/Pending.php ../../agent/src/Capture/Capture.php ../../agent/src/Command/Cli.php \
  ../../cli/src/Adapter/AdapterObservation.php ../../cli/src/Transport/EnvironmentDriver.php ../../cli/duo; do
  php -l "$file" >/dev/null || fail "$file has a syntax error"
done
pass "observer, transport, and fixture sources parse"

say "closed observation regression"
php regress_adapter_observation.php || fail "regress_adapter_observation.php reported failures"

printf '\n\033[1;32m✔ REGRESS_ADAPTER_OBSERVATION PASSED\033[0m\n'
