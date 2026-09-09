#!/usr/bin/env bash
set -euo pipefail
AIO_CAPSULE=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)
AIO_TARGET_EVIDENCE="tmp/plugin-adapters/change-wp-admin-login/target-${CONF_PAIR}"
mkdir -p "$AIO_TARGET_EVIDENCE"
cp "$AIO_CAPSULE/fixtures/native/verify-canonical.php" "$CONF_REPO2/.tmp-aio-verify-canonical.php"
capture_wprism_json_success AIO_NATIVE 'AIO conformance complete canonical values' wp_conf2 eval-file /siterepo/.tmp-aio-verify-canonical.php --use-include
printf '%s\n' "$AIO_NATIVE" > "$AIO_TARGET_EVIDENCE/canonical.json"
cp "$AIO_CAPSULE/fixtures/native/target-native.php" "$CONF_REPO2/.tmp-aio-target-native.php"
capture_wprism_json_success AIO_NATIVE 'AIO conformance native API behavior' wp_conf2 eval-file /siterepo/.tmp-aio-target-native.php --use-include
printf '%s\n' "$AIO_NATIVE" > "$AIO_TARGET_EVIDENCE/native.json"
python3 "$AIO_CAPSULE/fixtures/native/target-http.py" "http://localhost:$CONF2_PORT" "http://localhost:$CONF1_PORT" "$AIO_TARGET_EVIDENCE/http" > "$AIO_TARGET_EVIDENCE/http.json"
cp "$AIO_CAPSULE/fixtures/native/security-native.php" "$CONF_REPO2/.tmp-aio-security-native.php"
capture_wprism_json_success AIO_SECURITY 'AIO conformance native password lockout' wp_conf2 eval-file /siterepo/.tmp-aio-security-native.php --use-include
printf '%s\n' "$AIO_SECURITY" > "$AIO_TARGET_EVIDENCE/security.json"
AIO_REV=$(git -C "$CONF_REPO2" rev-parse HEAD)
capture_wprism_json_checked AIO_REPEAT 'AIO conformance repeated apply' assert_wprism_apply_ready wp_conf2 wprism apply --repo=/siterepo --default-author=admin --revision="$AIO_REV" --json
printf '%s\n' "$AIO_REPEAT" > "$AIO_TARGET_EVIDENCE/repeated-apply.json"
wp_conf2 wprism capture --repo=/siterepo --out=/siterepo/.tmp-aio-checked-recapture
diff -r "$CONF_REPO1/state" "$CONF_REPO2/.tmp-aio-checked-recapture" > "$AIO_TARGET_EVIDENCE/recapture.diff"
pass 'AIO native APIs, HTTP login, lockout, divergent references, local secret, repeat and recapture qualify'
