#!/usr/bin/env bash
# The driver supplies owned transport and private stream capture. Recheck table
# and column inventories around the transaction-consistent dump so a DDL race
# cannot turn an omitted field into apparently unchanged customer evidence.
combo_database_observe() { # <1|2> <unique label>
  local side="$1" label="$2" table inventory
  [[ "$side" = 1 || "$side" = 2 ]] && [[ "$label" =~ ^[a-z][a-z0-9-]*$ ]] || fail 'unsafe combination observation'
  combo_capture "$label-tables" wp_side "$side" db query 'SHOW FULL TABLES' --batch --raw --skip-column-names --quiet
  inventory=$(php "$SCENARIO_ROOT/fixtures/database-evidence.php" tables "$sink" "$PAIR" "$side" "$label") \
    || fail 'combination table inventory was not admitted'
  while IFS= read -r table; do
    [[ "$table" =~ ^[A-Za-z0-9_]{1,64}$ ]] || fail 'unsafe observed table'
    combo_capture "$label-columns-$table" wp_side "$side" db query "SHOW FULL COLUMNS FROM \`$table\`" --batch --raw --skip-column-names --quiet
  done <<<"$inventory"
  combo_capture "$label-database" wp_side "$side" db export - --single-transaction --skip-lock-tables --skip-add-locks \
    --skip-dump-date --order-by-primary --hex-blob --complete-insert --skip-extended-insert --quiet
  combo_capture "$label-tables-after" wp_side "$side" db query 'SHOW FULL TABLES' --batch --raw --skip-column-names --quiet
  while IFS= read -r table; do
    combo_capture "$label-columns-after-$table" wp_side "$side" db query "SHOW FULL COLUMNS FROM \`$table\`" --batch --raw --skip-column-names --quiet
  done <<<"$inventory"
  combo_capture "$label-image" php "$SCENARIO_ROOT/fixtures/database-evidence.php" image "$sink" "$PAIR" "$side" "$label"
}
