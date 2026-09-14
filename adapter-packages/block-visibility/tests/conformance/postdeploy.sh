#!/usr/bin/env bash
# The target owns an undeclared sibling in the shared settings option. Apply
# must replace the three authored sub-keys without erasing this target-local
# value.
set -euo pipefail

wp_conf2 eval '
  $settings = (array) get_option("block_visibility_settings", []);
  $settings["block_visibility_target_only_probe"] = "preserve-me";
  update_option("block_visibility_settings", $settings);
' >/dev/null
