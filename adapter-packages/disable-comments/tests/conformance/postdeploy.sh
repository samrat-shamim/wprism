#!/usr/bin/env bash
# Target-only runtime and unknown-sibling rows must survive the authored
# endpoint sub-key merge. These values never enter canonical state.
set -euo pipefail

wp_conf2 option update disable_comments_blocked_stats_rest 37 --autoload=yes >/dev/null
wp_conf2 option update disable_comments_review_trigger 123 --autoload=yes >/dev/null
wp_conf2 user meta update 1 disable_comments_review_dismissed target-only-review >/dev/null
wp_conf2 eval '
  $options = (array) get_option("disable_comments_options", []);
  $options["disable_comments_target_only_probe"] = "preserve-me";
  update_option("disable_comments_options", $options);
' >/dev/null
