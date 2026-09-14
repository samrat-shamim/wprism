#!/usr/bin/env bash
# Source-side proof of the capture half. The verify fixture asserts both
# halves at once — the native rows the plugin's own writers produced are still
# intact, and the published canonical document carries every authored family
# (the notification body through the home-URL token codec) while every runtime
# marker and every undeclared option stays out of state — and a repeated
# public Capture plus a repeated readback proves the whole picture is
# deterministic.
set -euo pipefail

LOGINIZER_FIXTURES=/var/www/html/wp-content/mu-plugins/adapter-packages/loginizer/fixtures

capture_wprism_json_success LOGINIZER_VERIFY1 'Loginizer native and canonical settings readback' \
  wp_conf1 --user=admin --require="$LOGINIZER_FIXTURES/admin-context.php" eval-file "$LOGINIZER_FIXTURES/native-options-verify-capture.php" --use-include
printf '%s' "$LOGINIZER_VERIFY1" | jq -e '
  .markers_present | all(. == true)
' >/dev/null || fail "a guaranteed runtime marker row (version, reset clock or install clock) disappeared before recapture; the premise is gone: $LOGINIZER_VERIFY1"

capture_wprism_json_success LOGINIZER_RECAPTURE 'Loginizer repeated public Capture' \
  wp_conf1 wprism capture --repo=/siterepo --format=json

capture_wprism_json_success LOGINIZER_VERIFY2 'Loginizer repeated native and canonical settings readback' \
  wp_conf1 --user=admin --require="$LOGINIZER_FIXTURES/admin-context.php" eval-file "$LOGINIZER_FIXTURES/native-options-verify-capture.php" --use-include

[ "$LOGINIZER_VERIFY1" = "$LOGINIZER_VERIFY2" ] \
  || fail "Loginizer repeated Capture moved the canonical options document or a native row: $LOGINIZER_VERIFY2"

pass "Loginizer capture and recapture keep the five authored families in canonical state (the mail body tokenized through the home-URL codec) and every runtime marker and undeclared option out"
