#!/usr/bin/env bash
# The hostile target owns one all-day event solely to prove that Duo's
# hook-bypassing mixed-option write neither executes TEC's unbounded cutoff
# migration nor deletes old events. It must survive apply byte-for-byte, then
# leave through the native post lifecycle before generic canonical recapture.
set -euo pipefail

tec_postapply_cutoff_sentinel() {
  local target_ids_file target_ids sentinel sentinel_json expected sentinel_id

  command -v wp_conf2 >/dev/null \
    || fail 'TEC post-apply hook requires the target WordPress command boundary'
  : "${CONF_REPO2:?TEC post-apply hook requires CONF_REPO2}"
  target_ids_file="$CONF_REPO2/.tmp-tec-target-ids.json"
  [ -f "$target_ids_file" ] \
    || fail "TEC target identity premise is missing: $target_ids_file"
  target_ids=$(cat "$target_ids_file")
  expected=$(jq -c '.cutoff_sentinel' <<<"$target_ids")
  sentinel_id=$(jq -er '.id' <<<"$expected")
  require_fixture_ids sentinel_id

  sentinel=$(wp_conf2 eval '
$id = '"$sentinel_id"';
$post = get_post($id);
if (!$post instanceof WP_Post || (int) $post->ID !== $id) {
    throw new RuntimeException("target-local cutoff sentinel identity changed");
}
$meta = [];
foreach (["_EventAllDay", "_EventStartDate", "_EventEndDate", "_EventDuration"] as $key) {
    $meta[$key] = get_post_meta($post->ID, $key, true);
}
echo wp_json_encode([
    "id" => (int) $post->ID,
    "post" => [
        "post_status" => $post->post_status,
        "post_title" => $post->post_title,
        "post_type" => $post->post_type,
    ],
    "meta" => $meta,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
') || fail 'TEC target-local cutoff sentinel could not be observed after apply'
  require_observed_nonempty 'TEC target-local cutoff sentinel after apply' "$sentinel"
  sentinel_json=$(printf '%s\n' "$sentinel" | awk 'NF { line=$0 } END { print line }')
  jq -e --argjson expected "$expected" '. == $expected' <<<"$sentinel_json" >/dev/null \
    || fail 'TEC hook-bypassing settings apply mutated or deleted the target-local all-day sentinel'
  pass 'TEC preserved the target-local multi-day-cutoff setting and all-day event bytes without invoking broad native callbacks'

  wp_conf2 eval '
global $wpdb;
$id = '"$sentinel_id"';
if (!wp_delete_post($id, true)) {
    throw new RuntimeException("target-local cutoff sentinel cleanup failed");
}
$counts = [
    (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID=%d", $id)),
    (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id=%d", $id)),
    (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->term_relationships} WHERE object_id=%d", $id)),
    (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}tec_events WHERE post_id=%d", $id)),
    (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}tec_occurrences WHERE post_id=%d", $id)),
];
if ($counts !== [0, 0, 0, 0, 0]) {
    throw new RuntimeException("target-local cutoff sentinel cleanup retained durable owner rows");
}
' >/dev/null || fail 'TEC target-local cutoff sentinel could not be removed after its preservation proof'
  pass 'TEC removed the test-owned cutoff sentinel before canonical recapture'
}

tec_postapply_cutoff_sentinel
unset -f tec_postapply_cutoff_sentinel
