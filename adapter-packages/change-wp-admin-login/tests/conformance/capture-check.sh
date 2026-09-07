#!/usr/bin/env bash
set -euo pipefail
capture_wprism_json_success AIO_NATIVE 'AIO native capture readback' wp_conf1 eval-file /siterepo/.tmp-aio-observe.php --use-include
printf '%s\n' "$AIO_NATIVE" > "tmp/plugin-adapters/change-wp-admin-login/conformance-${CONF_PAIR}/captured-native-readback.json"
AIO_CAPSULE=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)
cp "$AIO_CAPSULE/fixtures/native/verify-canonical.php" "$CONF_REPO1/.tmp-aio-verify-canonical.php"
capture_wprism_json_success AIO_CANONICAL 'AIO complete canonical options' wp_conf1 eval-file /siterepo/.tmp-aio-verify-canonical.php --use-include
printf '%s\n' "$AIO_CANONICAL" > "tmp/plugin-adapters/change-wp-admin-login/conformance-${CONF_PAIR}/canonical-readback.json"
pass 'AIO native consumers still observe the authored configuration after capture'
