#!/usr/bin/env bash
# Source-side proof for the one operation the disposition claims: capture.
# The fixture's verify-capture phase asserts both halves at once — the native
# rows the plugin's own writers produced are still intact, and the canonical
# document carries every authored family while every runtime marker and every
# undeclared option stays out of state.
set -euo pipefail

LOGINIZER_FIXTURES=/var/www/html/wp-content/mu-plugins/adapter-packages/loginizer/fixtures

capture_wprism_json_success LOGINIZER_VERIFY1 'Loginizer native and canonical settings readback' \
  wp_conf1 --require="$LOGINIZER_FIXTURES/admin-context.php" eval-file "$LOGINIZER_FIXTURES/native-options.php" verify-capture --use-include --user=admin
printf '%s' "$LOGINIZER_VERIFY1" | jq -e '
  .markers_present | all(. == true)
' >/dev/null || fail "a runtime marker row disappeared before recapture; the premise is gone: $LOGINIZER_VERIFY1"

capture_wprism_json_success LOGINIZER_RECAPTURE 'Loginizer repeated public Capture' \
  wp_conf1 wprism capture --repo=/siterepo --format=json

capture_wprism_json_success LOGINIZER_VERIFY2 'Loginizer repeated native and canonical settings readback' \
  wp_conf1 --require="$LOGINIZER_FIXTURES/admin-context.php" eval-file "$LOGINIZER_FIXTURES/native-options.php" verify-capture --use-include --user=admin

[ "$LOGINIZER_VERIFY1" = "$LOGINIZER_VERIFY2" ] \
  || fail "Loginizer repeated Capture moved the canonical options document or a native row: $LOGINIZER_VERIFY2"

pass "Loginizer capture and recapture keep the four authored families in canonical state and every runtime marker and undeclared option out"
