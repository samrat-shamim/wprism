#!/usr/bin/env bash
set -euo pipefail
AIO_CAPSULE=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)
AIO_EVIDENCE="tmp/plugin-adapters/change-wp-admin-login/conformance-${CONF_PAIR}"
mkdir -p "$AIO_EVIDENCE"
cp "$AIO_CAPSULE/fixtures/native/design.png" "$CONF_REPO1/.tmp-aio-design.png"
cp "$AIO_CAPSULE/fixtures/native/seed.php" "$CONF_REPO1/.tmp-aio-seed.php"
capture_wprism_json_success AIO_NATIVE 'AIO native REST writer inventory' wp_conf1 eval-file /siterepo/.tmp-aio-seed.php --use-include
printf '%s\n' "$AIO_NATIVE" > "$AIO_EVIDENCE/native-writers.json"
python3 "$AIO_CAPSULE/fixtures/native/customizer.py" "http://localhost:$CONF1_PORT" "$CONF_REPO1" "$AIO_EVIDENCE/http" > "$AIO_EVIDENCE/customizer.json"
cp "$AIO_CAPSULE/fixtures/native/observe.php" "$CONF_REPO1/.tmp-aio-observe.php"
capture_wprism_json_success AIO_NATIVE 'AIO fresh-process native readback' wp_conf1 eval-file /siterepo/.tmp-aio-observe.php --use-include
printf '%s\n' "$AIO_NATIVE" > "$AIO_EVIDENCE/native-readback.json"
cp "$AIO_CAPSULE/fixtures/native/local-widgets.php" "$CONF_REPO1/.tmp-aio-local-widgets.php"
capture_wprism_json_success AIO_WIDGETS_BEFORE 'Customizer widget scope premise' wp_conf1 eval-file /siterepo/.tmp-aio-local-widgets.php --use-include
printf '%s\n' "$AIO_WIDGETS_BEFORE" > "$AIO_EVIDENCE/local-widgets-before.json"
capture_wprism_json_success AIO_WIDGET_SCOPE 'Explicit local widget scope' wp_conf1 wprism classify --repo=/siterepo --set='options:widget_categories=runtime;options:widget_rss=runtime' --format=json
printf '%s\n' "$AIO_WIDGET_SCOPE" > "$AIO_EVIDENCE/local-widget-scope.json"
capture_wprism_json_success AIO_WIDGETS_AFTER 'Local widget native preservation' wp_conf1 eval-file /siterepo/.tmp-aio-local-widgets.php --use-include
[ "$AIO_WIDGETS_BEFORE" = "$AIO_WIDGETS_AFTER" ] || fail 'Widget scope classification changed native state'
pass 'AIO native REST, authenticated Customizer and Permalinks writers persist the observed inventory'
