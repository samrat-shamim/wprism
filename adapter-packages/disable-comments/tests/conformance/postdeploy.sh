#!/usr/bin/env bash
# Target-only runtime and unknown-sibling rows must survive the authored
# endpoint sub-key merge. These values never enter canonical state.
set -euo pipefail

disable_comments_seed_runtime() {
  local cli="$1"
  if ! "$cli" option get disable_comment_version >/dev/null 2>&1; then
    "$cli" option add disable_comment_version 2.9.0 --autoload=yes >/dev/null
  fi
  "$cli" option update disable_comments_blocked_since '2026-09-14 09:00:00' --autoload=yes >/dev/null
  "$cli" option update disable_comments_blocked_stats_comment 11 --autoload=yes >/dev/null
  "$cli" option update disable_comments_blocked_stats_rest 37 --autoload=yes >/dev/null
  "$cli" option update disable_comments_blocked_stats_trackback 19 --autoload=yes >/dev/null
  "$cli" option update disable_comments_review_trigger 123 --autoload=yes >/dev/null
  "$cli" user meta update 1 disable_comments_review_dismissed target-only-review >/dev/null
}

disable_comments_assert_runtime() {
  local cli="$1"
  [ "$($cli option get disable_comment_version)" = '2.9.0' ] \
    || fail 'Apply did not preserve disable_comment_version'
  [ "$($cli option get disable_comments_blocked_since)" = '2026-09-14 09:00:00' ] \
    || fail 'Apply did not preserve disable_comments_blocked_since'
  [ "$($cli option get disable_comments_blocked_stats_comment)" = '11' ] \
    || fail 'Apply did not preserve disable_comments_blocked_stats_comment'
  [ "$($cli option get disable_comments_blocked_stats_rest)" = '37' ] \
    || fail 'Apply did not preserve disable_comments_blocked_stats_rest'
  [ "$($cli option get disable_comments_blocked_stats_trackback)" = '19' ] \
    || fail 'Apply did not preserve disable_comments_blocked_stats_trackback'
  [ "$($cli option get disable_comments_review_trigger)" = '123' ] \
    || fail 'Apply did not preserve disable_comments_review_trigger'
  [ "$($cli user meta get 1 disable_comments_review_dismissed)" = 'target-only-review' ] \
    || fail 'Apply did not preserve disable_comments_review_dismissed'
}

disable_comments_assert_uninstall_runtime() {
  local cli="$1" option value
  # Native 2.9.0 uninstall retains only its version marker; the other declared
  # runtime rows and review-dismissal metadata are removed.
  [ "$($cli option get disable_comment_version)" = '2.9.0' ] \
    || fail 'uninstall changed the native disable_comment_version residue'
  for option in \
    disable_comments_blocked_since \
    disable_comments_blocked_stats_comment \
    disable_comments_blocked_stats_rest \
    disable_comments_blocked_stats_trackback \
    disable_comments_review_trigger; do
    value=$($cli option get "$option" 2>/dev/null || true)
    [ -z "$value" ] || fail "uninstall left runtime option $option: $value"
  done
  value=$($cli user meta get 1 disable_comments_review_dismissed 2>/dev/null || true)
  [ -z "$value" ] || fail "uninstall left runtime user meta: $value"
}

disable_comments_seed_runtime wp_conf2
wp_conf2 eval '
  $options = (array) get_option("disable_comments_options", []);
  $options["disable_comments_target_only_probe"] = "preserve-me";
  update_option("disable_comments_options", $options);
' >/dev/null
