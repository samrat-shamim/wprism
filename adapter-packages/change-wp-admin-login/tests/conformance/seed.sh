#!/usr/bin/env bash
set -euo pipefail
AIO_CAPSULE=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)
AIO_EVIDENCE="tmp/plugin-adapters/change-wp-admin-login/conformance-${CONF_PAIR}"
mkdir -p "$AIO_EVIDENCE"
cp "$AIO_CAPSULE/fixtures/native/seed.php" "$CONF_REPO1/.tmp-aio-seed.php"
capture_wprism_json_success AIO_NATIVE 'AIO native REST writer inventory' wp_conf1 eval-file /siterepo/.tmp-aio-seed.php --use-include
printf '%s\n' "$AIO_NATIVE" > "$AIO_EVIDENCE/native-writers.json"
python3 "$AIO_CAPSULE/fixtures/native/customizer.py" "http://localhost:$CONF1_PORT" "$CONF_REPO1" "$AIO_EVIDENCE/http" > "$AIO_EVIDENCE/customizer.json"
cp "$AIO_CAPSULE/fixtures/native/observe.php" "$CONF_REPO1/.tmp-aio-observe.php"
capture_wprism_json_success AIO_NATIVE 'AIO fresh-process native readback' wp_conf1 eval-file /siterepo/.tmp-aio-observe.php --use-include
printf '%s\n' "$AIO_NATIVE" > "$AIO_EVIDENCE/native-readback.json"
pass 'AIO native REST, authenticated Customizer and Permalinks writers persist the observed inventory'
