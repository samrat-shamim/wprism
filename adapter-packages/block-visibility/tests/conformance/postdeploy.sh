#!/usr/bin/env bash
# The target owns divergent values in declared settings keys. Apply must
# converge those managed values without treating target drift as source intent.
set -euo pipefail

wp_conf2 eval '
  $settings = (array) get_option("block_visibility_settings", []);
  $settings["plugin_settings"]["block_opacity"] = 99;
  $settings["disabled_blocks"] = ["core/paragraph"];
  update_option("block_visibility_settings", $settings);
' >/dev/null
