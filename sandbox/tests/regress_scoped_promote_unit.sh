#!/usr/bin/env bash
# Offline host-path regression for DUO-3344 scoped promotion. The companion
# fixture routes the public SSH driver through a temporary fake ssh/wp target
# while retaining the real signed rollback-control implementation.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
FIXTURE="$ROOT/sandbox/tests/fixtures/duo3344-scoped-promote-unit.php"

printf '== syntax ==\n'
for file in "$FIXTURE" "$ROOT/cli/duo" "$ROOT/cli/src/Recovery/ScopedRollbackProfile.php" \
  "$ROOT/cli/src/Recovery/RollbackAuthority.php" "$ROOT/recovery/rollback-control.php"; do
  php -l "$file" >/dev/null
done
printf 'ok: PHP syntax\n'

printf '== public SSH scoped-promotion regression ==\n'
php "$FIXTURE" "$ROOT"

printf '✔ REGRESS_SCOPED_PROMOTE_UNIT PASSED\n'
