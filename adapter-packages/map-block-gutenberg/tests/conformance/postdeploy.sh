#!/usr/bin/env bash
set -euo pipefail
MAP_CAPSULE="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cp "$MAP_CAPSULE/fixtures/source-probe.php" "$CONF_REPO2/.tmp-map-probe.php"
printf '%s' 'map-fixture-target-key' | wp_conf2 wprism env-set --repo=/siterepo --name=gmw-map-block-key --stdin >/dev/null
pass 'target API key provisioned through the product env-set path'
