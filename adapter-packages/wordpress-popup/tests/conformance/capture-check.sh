#!/usr/bin/env bash
# Source-side proof for the three operations the disposition claims: capture,
# compile, recapture. Hustle keeps no post type, so the assertions here are
# about the custom-table snapshot and about which module-meta keys crossed the
# boundary — the half a byte-diff of post documents could never show.
set -euo pipefail

REPO="${CONF_REPO1:-siterepo/conf1}"

read -r -d '' OBSERVE_PHP <<'PHPEOF' || true
<?php
wp_set_current_user(1);
global $wpdb;
$moduleId = (int) $wpdb->get_var(
    "SELECT module_id FROM {$wpdb->prefix}hustle_modules WHERE module_name = 'WPrism Embed Fixture'"
);
if ( ! $moduleId ) {
    throw new RuntimeException('the seeded Hustle module is missing from hustle_modules');
}
$meta = $wpdb->get_results( $wpdb->prepare(
    "SELECT meta_key FROM {$wpdb->prefix}hustle_modules_meta WHERE module_id = %d", $moduleId
), ARRAY_A );
$keys = array_map( static fn( array $r ): string => $r['meta_key'], $meta );
sort( $keys );
echo wp_json_encode( array(
    'module'    => $moduleId,
    'meta_keys' => $keys,
    'entries'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}hustle_entries" ),
) );
PHPEOF

OBSERVE_FILE="$REPO/.tmp-hustle-observe.php"
printf '%s' "$OBSERVE_PHP" > "$OBSERVE_FILE"
OBSERVE_OUT=$(wp_conf1 eval-file /siterepo/.tmp-hustle-observe.php)
rm -f "$OBSERVE_FILE"
require_observed_nonempty "Hustle native capture observation" "$OBSERVE_OUT"
OBSERVED=$(printf '%s\n' "$OBSERVE_OUT" | awk 'NF { line=$0 } END { print line }')
printf '%s\n' "$OBSERVED" | jq -e '.module > 0 and (.meta_keys | index("content")) != null' >/dev/null \
  || fail "the native source no longer holds the seeded module and its content meta: $OBSERVED"

TABLES_DIR="$REPO/state/tables"
[ -d "$TABLES_DIR" ] || fail "capture published no table state directory"

MODULES_FILE=$(grep -rl 'WPrism Embed Fixture' "$TABLES_DIR" 2>/dev/null | head -1)
[ -n "$MODULES_FILE" ] || fail "the authored hustle_modules row is absent from captured table state"

# Declared authored module-meta keys must be present...
for expected in 'content' 'design' 'settings' 'shortcode_id'; do
  grep -q -- "\"$expected\"" "$MODULES_FILE" \
    || grep -rq -- "\"$expected\"" "$TABLES_DIR" \
    || fail "declared authored module meta key '$expected' is absent from captured table state"
done

# ...and the withheld families must not be. integrations_settings holds
# provider credentials; the three runtime tables hold visitor submissions and
# counters, which must never reach canonical state at all.
grep -rq -- '"integrations_settings"' "$TABLES_DIR" \
  && fail "the withheld integrations_settings credential blob reached captured table state"
for runtime in 'hustle_entries' 'hustle_entries_meta' 'hustle_tracking'; do
  if [ -e "$TABLES_DIR/$runtime" ] || [ -e "$TABLES_DIR/$runtime.json" ]; then
    fail "runtime table $runtime reached captured state"
  fi
done

EMBED_FILE=$(find "$REPO/state/posts/page" -name '*--wprism-hustle-embed.md' -type f 2>/dev/null | head -1)
[ -n "$EMBED_FILE" ] || fail "capture published no canonical document for the embedding page"
if ! grep -qE '\[wd_hustle id="\{\{hustle_module:[0-9a-f-]{36}\}\}"' "$EMBED_FILE"; then
  printf 'embed document body:\n' >&2
  sed -n '/wd_hustle/p' "$EMBED_FILE" >&2
  fail "the numeric [wd_hustle] module id was not tokenized into a hustle_module reference"
fi

printf '%s\n' "$OBSERVED"
pass "Hustle capture carries the module row, its authored meta keys and a tokenized numeric embed, and leaves credentials, submissions and tracking behind"
