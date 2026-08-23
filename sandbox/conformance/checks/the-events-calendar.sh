#!/usr/bin/env bash
# Exact-artifact TEC acceptance beyond byte recapture: native repositories,
# hostile huge identities, mixed-option sovereignty, schema refusals,
# unsupported deletion, conflict authority, rollback/retry, concurrency, and
# deactivate/uninstall/reinstall lifecycle behavior.
set -euo pipefail
CONF1_PORT="${CONF1_PORT:-8806}"
CONF2_PORT="${CONF2_PORT:-8807}"

observe_tec() { # <conf1|conf2>
  local side="$1" repo file out
  case "$side" in
    conf1) repo="${CONF_REPO1:-siterepo/conf1}" ;;
    conf2) repo="${CONF_REPO2:-siterepo/conf2}" ;;
    *) fail "invalid TEC observation side: $side" ;;
  esac
  file="$repo/.tmp-tec-observe.php"
  read -r -d '' OBSERVE_PHP <<'PHPEOF' || true
<?php
$one = static function (string $type, string $title): WP_Post {
    $posts = get_posts([
        'post_type' => $type,
        'post_status' => 'any',
        'posts_per_page' => -1,
        'title' => $title,
    ]);
    if (count($posts) !== 1) {
        throw new RuntimeException("expected one $type '$title', got " . count($posts));
    }
    return $posts[0];
};
$event = $one('tribe_events', 'Duo Production Readiness Event 東京');
$all_day = $one('tribe_events', 'Duo All Day Boundary Event');
$delete_probe = $one('tribe_events', 'Duo Unsupported Delete Probe');
$venue = $one('tribe_venue', 'Duo Readiness Hall 東京');
$organizer = $one('tribe_organizer', 'Duo Readiness Team 東京');
$category = get_term_by('slug', 'duo-readiness-category', 'tribe_events_cat');
if (!$category instanceof WP_Term) {
    throw new RuntimeException('TEC event category is missing');
}
global $wpdb;
$occurrence = $wpdb->get_row($wpdb->prepare(
    "SELECT start_date,end_date,duration,event_id,post_id FROM {$wpdb->prefix}tec_occurrences WHERE post_id=%d",
    $event->ID
), ARRAY_A);
$event_row = $wpdb->get_row($wpdb->prepare(
    "SELECT start_date,end_date,timezone,duration,event_id,post_id FROM {$wpdb->prefix}tec_events WHERE post_id=%d",
    $event->ID
), ARRAY_A);
$all_day_occurrence = $wpdb->get_row($wpdb->prepare(
    "SELECT start_date,end_date,post_id FROM {$wpdb->prefix}tec_occurrences WHERE post_id=%d",
    $all_day->ID
), ARRAY_A);
$repository_event = tribe_events()->where('id', (int) $event->ID)->first();
$cache = $wpdb->get_var($wpdb->prepare(
    "SELECT value FROM {$wpdb->prefix}tec_kv_cache WHERE cache_key=%s",
    'duo-readiness-target-only'
));
$option = (array) get_option('tribe_events_calendar_options', []);
$category_meta = [];
foreach (['primary', 'secondary', 'text', 'priority', 'hidden'] as $suffix) {
    $category_meta[$suffix] = get_term_meta($category->term_id, 'tec-events-cat-colors-' . $suffix, true);
}
$dropdown_rows = tribe(
    \TEC\Events\Category_Colors\Repositories\Category_Color_Dropdown_Provider::class
)->get_dropdown_categories();
$category_dropdown = array_values(array_filter(
    $dropdown_rows,
    static fn(array $row): bool => ($row['slug'] ?? '') === $category->slug
));
if (count($category_dropdown) !== 1) {
    throw new RuntimeException('TEC native Category Colors dropdown did not return the fixture category');
}
echo wp_json_encode([
    'all_day' => [
        'all_day' => get_post_meta($all_day->ID, '_EventAllDay', true),
        'end' => get_post_meta($all_day->ID, '_EventEndDate', true),
        'id' => (int) $all_day->ID,
        'occurrence' => $all_day_occurrence,
        'organizer' => get_post_meta($all_day->ID, '_EventOrganizerID', true),
        'start' => get_post_meta($all_day->ID, '_EventStartDate', true),
        'venue' => get_post_meta($all_day->ID, '_EventVenueID', true),
    ],
    'cache' => $cache,
    'category' => [
        'description' => $category->description,
        'dropdown' => $category_dropdown[0],
        'id' => (int) $category->term_id,
        'meta' => $category_meta,
    ],
    'category_css' => get_option('tec_events_category_color_css', null),
    'delete_probe' => (int) $delete_probe->ID,
    'event' => [
        'category_ids' => array_map('intval', wp_get_post_terms($event->ID, 'tribe_events_cat', ['fields' => 'ids'])),
        'content' => $event->post_content,
        'cost' => get_post_meta($event->ID, '_EventCost', true),
        'currency_code' => get_post_meta($event->ID, '_EventCurrencyCode', true),
        'currency_position' => get_post_meta($event->ID, '_EventCurrencyPosition', true),
        'currency_symbol' => get_post_meta($event->ID, '_EventCurrencySymbol', true),
        'end' => get_post_meta($event->ID, '_EventEndDate', true),
        'featured' => get_post_meta($event->ID, '_tribe_featured', true),
        'id' => (int) $event->ID,
        'occurrence' => $occurrence,
        'organizer' => (int) get_post_meta($event->ID, '_EventOrganizerID', true),
        'permalink' => get_permalink($event),
        'phone' => get_post_meta($event->ID, '_EventPhone', true),
        'repository_id' => $repository_event ? (int) $repository_event->ID : 0,
        'row' => $event_row,
        'start' => get_post_meta($event->ID, '_EventStartDate', true),
        'tags' => wp_get_post_terms($event->ID, 'post_tag', ['fields' => 'names']),
        'timezone' => get_post_meta($event->ID, '_EventTimezone', true),
        'url' => get_post_meta($event->ID, '_EventURL', true),
        'venue' => (int) get_post_meta($event->ID, '_EventVenueID', true),
    ],
    'home' => home_url('/'),
    'organizer' => [
        'email' => get_post_meta($organizer->ID, '_OrganizerEmail', true),
        'id' => (int) $organizer->ID,
        'phone' => get_post_meta($organizer->ID, '_OrganizerPhone', true),
        'website' => get_post_meta($organizer->ID, '_OrganizerWebsite', true),
    ],
    'options' => [
        'after' => $option['tribeEventsAfterHTML'] ?? null,
        'before' => $option['tribeEventsBeforeHTML'] ?? null,
        'category_frontend' => $option['category-color-enable-frontend'] ?? null,
        'category_show_hidden' => $option['category-color-show-hidden-categories'] ?? null,
        'currency_code' => $option['defaultCurrencyCode'] ?? null,
        'default_organizer' => (int) ($option['eventsDefaultOrganizerID'] ?? 0),
        'default_venue' => (int) ($option['eventsDefaultVenueID'] ?? 0),
        'eb_secret' => $option['eb_security_key'] ?? null,
        'events_slug' => $option['eventsSlug'] ?? null,
        'maps_key' => $option['google_maps_js_api_key'] ?? null,
        'seo_behavior' => $option['tec_seo_out_of_range_behavior'] ?? null,
        'single_slug' => $option['singleEventSlug'] ?? null,
        'source_only' => $option['duo_source_only_secret'] ?? null,
        'target_only' => $option['duo_target_only_runtime'] ?? null,
        'timezone_mode' => $option['tribe_events_timezone_mode'] ?? null,
        'views' => $option['tribeEnableViews'] ?? null,
    ],
    'venue' => [
        'address' => get_post_meta($venue->ID, '_VenueAddress', true),
        'city' => get_post_meta($venue->ID, '_VenueCity', true),
        'country' => get_post_meta($venue->ID, '_VenueCountry', true),
        'id' => (int) $venue->ID,
        'phone' => get_post_meta($venue->ID, '_VenuePhone', true),
        'province' => get_post_meta($venue->ID, '_VenueProvince', true),
        'state_province' => get_post_meta($venue->ID, '_VenueStateProvince', true),
        'website' => get_post_meta($venue->ID, '_VenueURL', true),
    ],
    'version' => defined('Tribe__Events__Main::VERSION') ? Tribe__Events__Main::VERSION : null,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
PHPEOF
  printf '%s' "$OBSERVE_PHP" > "$file"
  if [ "$side" = conf1 ]; then
    out=$(wp_conf1 eval-file /siterepo/.tmp-tec-observe.php)
  else
    out=$(wp_conf2 eval-file /siterepo/.tmp-tec-observe.php)
  fi
  rm -f "$file"
  require_observed_nonempty "$side The Events Calendar native observation" "$out"
  printf '%s\n' "$out" | awk 'NF { line=$0 } END { print line }'
}

commit_tec_source() { # <message>
  wp_conf1 duo capture --repo=/siterepo >/dev/null
  git -C "$CONF_REPO1" add -A
  git -C "$CONF_REPO1" -c user.name=duo -c user.email=duo@example.test commit -qm "$1"
  git -C "$CONF_REPO1" push -q origin main
  git -C "$CONF_REPO2" pull -q origin main
}

tec_target_hash() {
  wp_conf2 eval '
    global $wpdb;
    $p=get_posts(["post_type"=>"tribe_events","post_status"=>"any","posts_per_page"=>1,"title"=>"Duo Production Readiness Event 東京"])[0]??null;
    if(!$p) throw new RuntimeException("TEC hash event missing");
    $term=get_term_by("slug","duo-readiness-category","tribe_events_cat");
    $option=(array)get_option("tribe_events_calendar_options",[]);
    $selected=[]; foreach(["eventsSlug","singleEventSlug","tribeEnableViews","viewOption","eventsDefaultVenueID","eventsDefaultOrganizerID","google_maps_js_api_key","eb_security_key","duo_target_only_runtime"] as $k){$selected[$k]=$option[$k]??null;}
    $rows=[
      "post"=>$wpdb->get_row($wpdb->prepare("SELECT ID,post_title,post_name,post_status,post_content FROM {$wpdb->posts} WHERE ID=%d",$p->ID),ARRAY_A),
      "meta"=>$wpdb->get_results($wpdb->prepare("SELECT meta_key,meta_value FROM {$wpdb->postmeta} WHERE post_id=%d ORDER BY meta_key,meta_id",$p->ID),ARRAY_A),
      "event"=>$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}tec_events WHERE post_id=%d",$p->ID),ARRAY_A),
      "occurrence"=>$wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}tec_occurrences WHERE post_id=%d ORDER BY occurrence_id",$p->ID),ARRAY_A),
      "term"=>$term ? [$term->term_id,$term->name,$term->slug,$term->description,get_term_meta($term->term_id)] : null,
      "category_css"=>get_option("tec_events_category_color_css",null),
      "option"=>$selected,
    ];
    echo hash("sha256",wp_json_encode($rows,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
  '
}

SOURCE_IDS_FILE="${CONF_REPO1:-siterepo/conf1}/.tmp-tec-source-ids.json"
TARGET_IDS_FILE="${CONF_REPO2:-siterepo/conf2}/.tmp-tec-target-ids.json"
[ -f "$SOURCE_IDS_FILE" ] || fail "TEC source identity premise is missing: $SOURCE_IDS_FILE"
[ -f "$TARGET_IDS_FILE" ] || fail "TEC target identity premise is missing: $TARGET_IDS_FILE"
SOURCE_IDS=$(cat "$SOURCE_IDS_FILE")
TARGET_IDS=$(cat "$TARGET_IDS_FILE")
SOURCE=$(observe_tec conf1)
TARGET=$(observe_tec conf2)
TEC_EXPECTED_VERSION="${TEC_EXPECTED_VERSION:-6.17.3}"

printf '%s\n' "$TARGET" | jq -e \
  --arg version "$TEC_EXPECTED_VERSION" \
  --argjson source "$SOURCE_IDS" \
  --argjson dirty "$TARGET_IDS" '
  .home as $home |
  .version == $version and
  .event.id == $dirty.dirty_event and .venue.id == $dirty.venue and
  .organizer.id == $dirty.organizer and .category.id == $dirty.category and
  .event.id != $source.event and .venue.id != $source.venue and
  .organizer.id != $source.organizer and .category.id != $source.category and
  .event.id >= 7000000000 and .category.id >= 7100000000 and
  .event.repository_id == .event.id and .event.venue == .venue.id and
  .event.organizer == .organizer.id and .event.category_ids == [.category.id] and
  .event.start == "2026-09-05 22:30:00" and .event.end == "2026-09-06 01:45:00" and
  .event.timezone == "Asia/Kathmandu" and .event.cost == "125.50" and
  .event.currency_code == "NPR" and .event.currency_position == "postfix" and
  .event.currency_symbol == "रु" and .event.featured == "1" and
  .event.phone == "+977-555-0199" and
  .event.occurrence.start_date == .event.start and .event.occurrence.end_date == .event.end and
  .event.row.start_date == .event.start and .event.row.end_date == .event.end and
  .event.row.timezone == .event.timezone and
  (.event.content | length > 25000) and (.event.content | contains("বাংলা")) and
  (.event.content | contains($home)) and (.event.url | startswith($home)) and
  .venue.city == "Kathmandu" and .venue.country == "Nepal" and
  (.venue.address | contains("ভবন ৭")) and (.venue.website | startswith($home)) and
  .organizer.email == "events@example.test" and (.organizer.website | startswith($home)) and
  .category.description == "Portable category description — বাংলা — مرحبا" and
  .category.meta == {primary:"#123abc",secondary:"#fedcba",text:"#ffffff",priority:"17",hidden:"0"} and
  .category.dropdown.primary == "#123abc" and .category.dropdown.slug == "duo-readiness-category" and
  (.category_css | type == "string") and
  (.category_css | contains(".tribe_events_cat-duo-readiness-category{")) and
  (.category_css | contains("--tec-color-category-primary:#123abc")) and
  (.category_css | contains("--tec-color-category-secondary:#fedcba")) and
  (.category_css | contains("--tec-color-category-text:#ffffff")) and
  .all_day.all_day == "yes" and .all_day.venue == "" and .all_day.organizer == "" and
  .all_day.occurrence.start_date == .all_day.start and .all_day.occurrence.end_date == .all_day.end and
  .options.events_slug == "calendar-readiness" and .options.single_slug == "readiness-event" and
  .options.views == ["list","month"] and .options.currency_code == "NPR" and
  .options.default_venue == .venue.id and .options.default_organizer == .organizer.id and
  .options.category_frontend == true and .options.seo_behavior == "soft_noindex" and
  .options.category_show_hidden == false and
  .options.timezone_mode == "event" and
  .options.maps_key == "target-maps-key-preserved" and
  .options.eb_secret == "target-event-aggregator-secret-preserved" and
  .options.target_only == "target-option-preserved" and .options.source_only == null and
  .cache == "target-runtime-preserved" and
  (.event.permalink | contains("/readiness-event/"))
' >/dev/null || fail "TEC native graph/settings/derived state did not converge: $TARGET"
pass "TEC adopted huge native identities, rewrote refs/URLs/defaults, repaired projections, and preserved target-owned state"

PERMALINK=$(jq -er '.event.permalink' <<<"$TARGET")
FRONT=$(curl -fsSL "$PERMALINK") || fail "TEC target event permalink did not return 200: $PERMALINK"
require_observed_nonempty "TEC target event response" "$FRONT"
[ "${#FRONT}" -ge 20000 ] || fail "TEC target event response was suspiciously short (${#FRONT} bytes)"
grep -qF 'Duo Production Readiness Event 東京' <<<"$FRONT" || fail "TEC target event response lost the title"
grep -qF 'Portable long event body' <<<"$FRONT" || fail "TEC target event response lost the long body"
! grep -qF '.tribe_events_cat-duo-readiness-category{' <<<"$FRONT" \
  || fail "TEC singular event unexpectedly enqueued archive-only Category Colors CSS"
ARCHIVE=$(curl -fsSL "http://localhost:${CONF2_PORT}/calendar-readiness/") \
  || fail "TEC authored archive slug did not resolve after rewrite repair"
grep -qF 'Readiness before 東京' <<<"$ARCHIVE" || fail "TEC archive lost authored before HTML"
grep -qF 'Readiness after বাংলা' <<<"$ARCHIVE" || fail "TEC archive lost authored after HTML"
grep -qF '.tribe_events_cat-duo-readiness-category{' <<<"$ARCHIVE" \
  || fail "TEC archive did not enqueue the native Category Colors selector"
grep -qF '#123abc' <<<"$ARCHIVE" || fail "TEC archive did not carry the authored primary category color"
pass "single/archive frontends follow TEC's singular exclusion and archive-only native Category Colors behavior"

if [ "${TEC_BOUNDARY_ONLY:-0}" = 1 ]; then
  pass "TEC exact-boundary native round trip is clean"
  return 0 2>/dev/null || exit 0
fi

# Category metadata lands before the native provider action. Reject the
# provider's option write after those authored rows move, then prove the one
# apply transaction restores both layers and a retry repairs CSS + plugin cache.
wp_conf1 eval '
  $term=get_term_by("slug","duo-readiness-category","tribe_events_cat");
  if(!$term instanceof WP_Term) throw new RuntimeException("TEC source category disappeared");
  tribe(\TEC\Events\Category_Colors\Event_Category_Meta::class)
    ->set_term((int)$term->term_id)
    ->set("tec-events-cat-colors-primary","#654321")
    ->save();
  tribe(\TEC\Events\Category_Colors\CSS\Controller::class)->generate_css();
  $css=get_option("tec_events_category_color_css","");
  if(!is_string($css)||!str_contains($css,"#654321")) throw new RuntimeException("TEC source CSS update failed");
' >/dev/null
commit_tec_source 'conformance: native TEC Category Colors intent'
COLOR_FAULT_BEFORE=$(tec_target_hash)
wp_conf2 db query 'ALTER TABLE wp_options DROP CONSTRAINT IF EXISTS duo_tec_fail_category_css' >/dev/null
wp_conf2 db query '
  ALTER TABLE wp_options ADD CONSTRAINT duo_tec_fail_category_css
  CHECK (option_name <> "tec_events_category_color_css" OR option_value NOT LIKE "%#654321%")
' >/dev/null
COLOR_FAULT_RC=0
COLOR_FAULT_OUT=$(wp_conf2 duo apply --repo=/siterepo --default-author=admin 2>&1) || COLOR_FAULT_RC=$?
require_duo_answered "TEC injected Category Colors provider failure" human "$COLOR_FAULT_OUT"
[ "$COLOR_FAULT_RC" -ne 0 ] && grep -Eq 'Category Colors|recovery_required|missing native projection' <<<"$COLOR_FAULT_OUT" \
  || fail "TEC injected Category Colors option failure did not surface through the provider: $COLOR_FAULT_OUT"
[ "$(tec_target_hash)" = "$COLOR_FAULT_BEFORE" ] \
  || fail "TEC failed Category Colors provider action left partial term-meta/CSS writes"
[ "$(wp_conf2 eval 'echo null === \Duo\Ledger::kv_get("apply_in_progress") ? "clear" : "retained";')" = retained ] \
  || fail "TEC failed Category Colors provider action did not retain retry authority"
wp_conf2 db query 'ALTER TABLE wp_options DROP CONSTRAINT duo_tec_fail_category_css' >/dev/null
COLOR_RETRY=$(wp_conf2 duo apply --repo=/siterepo --default-author=admin --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered "TEC Category Colors retry" json "$COLOR_RETRY"
jq -e '.canary == "clean" and .verification.result == "pass" and .applied >= 1' <<<"$COLOR_RETRY" >/dev/null \
  || fail "TEC Category Colors retry did not converge: $COLOR_RETRY"
COLOR_RECOVERED=$(observe_tec conf2)
printf '%s\n' "$COLOR_RECOVERED" | jq -e '
  .category.meta.primary == "#654321" and .category.dropdown.primary == "#654321" and
  (.category_css | contains("--tec-color-category-primary:#654321")) and
  (.category_css | contains("--tec-color-category-secondary:#fedcba"))
' >/dev/null || fail "TEC Category Colors retry did not repair native CSS/dropdown projections: $COLOR_RECOVERED"
pass "native Category Colors option failure rolls back authored metadata and retries CSS/cache repair cleanly"

# Capture-time schema/secret probes restore exact live bytes and require every
# refusal to leave the committed repository untouched.
SCHEMA_BACKUP="${CONF_REPO1:-siterepo/conf1}/.tmp-tec-schema-backup.json"
wp_conf1 eval '
  $p=get_posts(["post_type"=>"tribe_events","post_status"=>"any","posts_per_page"=>1,"title"=>"Duo Production Readiness Event 東京"])[0];
  file_put_contents("/siterepo/.tmp-tec-schema-backup.json",wp_json_encode([
    "content"=>$p->post_content,
    "start"=>get_post_meta($p->ID,"_EventStartDate",true),
    "cost"=>get_post_meta($p->ID,"_EventCost",true),
  ],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
' >/dev/null

FAKE_SECRET='AKIAABCDEFGHIJKLMNOP'
wp_conf1 eval '
  $p=get_posts(["post_type"=>"tribe_events","post_status"=>"any","posts_per_page"=>1,"title"=>"Duo Production Readiness Event 東京"])[0];
  wp_update_post(["ID"=>$p->ID,"post_content"=>"AKIAABCDEFGHIJKLMNOP"]);
' >/dev/null
BEFORE_STATUS=$(git -C "$CONF_REPO1" status --porcelain --untracked-files=all -- state)
SECRET_RC=0
SECRET_OUT=$(wp_conf1 duo capture --repo=/siterepo 2>&1) || SECRET_RC=$?
[ "$SECRET_RC" -ne 0 ] && grep -q 'secret guard tripped' <<<"$SECRET_OUT" \
  && ! grep -Fq "$FAKE_SECRET" <<<"$SECRET_OUT" \
  || fail "TEC credential-shaped content did not refuse and redact: $SECRET_OUT"
[ "$(git -C "$CONF_REPO1" status --porcelain --untracked-files=all -- state)" = "$BEFORE_STATUS" ] \
  || fail "TEC secret refusal partially published state"
wp_conf1 eval '
  $b=json_decode(file_get_contents("/siterepo/.tmp-tec-schema-backup.json"),true,512,JSON_THROW_ON_ERROR);
  $p=get_posts(["post_type"=>"tribe_events","post_status"=>"any","posts_per_page"=>1,"title"=>"Duo Production Readiness Event 東京"])[0];
  wp_update_post(["ID"=>$p->ID,"post_content"=>$b["content"]]);
' >/dev/null

wp_conf1 eval '
  $p=get_posts(["post_type"=>"tribe_events","post_status"=>"any","posts_per_page"=>1,"title"=>"Duo Production Readiness Event 東京"])[0];
  update_post_meta($p->ID,"_EventStartDate","2026-02-30 01:02:03");
' >/dev/null
DATE_RC=0
DATE_OUT=$(wp_conf1 duo capture --repo=/siterepo 2>&1) || DATE_RC=$?
[ "$DATE_RC" -ne 0 ] && grep -q 'exact real Y-m-d H:i:s date' <<<"$DATE_OUT" \
  || fail "TEC impossible date did not refuse through the shipped interpreter: $DATE_OUT"
wp_conf1 eval '
  $b=json_decode(file_get_contents("/siterepo/.tmp-tec-schema-backup.json"),true,512,JSON_THROW_ON_ERROR);
  $p=get_posts(["post_type"=>"tribe_events","post_status"=>"any","posts_per_page"=>1,"title"=>"Duo Production Readiness Event 東京"])[0];
  update_post_meta($p->ID,"_EventStartDate",$b["start"]);
' >/dev/null

wp_conf1 eval '
  $p=get_posts(["post_type"=>"tribe_events","post_status"=>"any","posts_per_page"=>1,"title"=>"Duo Production Readiness Event 東京"])[0];
  update_post_meta($p->ID,"_EventRecurrence",["rules"=>[["type"=>"Every Week"]]]);
' >/dev/null
RECURRENCE_RC=0
RECURRENCE_OUT=$(wp_conf1 duo capture --repo=/siterepo 2>&1) || RECURRENCE_RC=$?
[ "$RECURRENCE_RC" -ne 0 ] && grep -Eqi 'recurrence|unclassified' <<<"$RECURRENCE_OUT" \
  || fail "TEC Pro recurrence state did not refuse in the free adapter: $RECURRENCE_OUT"
wp_conf1 eval '
  $p=get_posts(["post_type"=>"tribe_events","post_status"=>"any","posts_per_page"=>1,"title"=>"Duo Production Readiness Event 東京"])[0];
  delete_post_meta($p->ID,"_EventRecurrence");
' >/dev/null

wp_conf1 eval '
  $p=get_posts(["post_type"=>"tribe_events","post_status"=>"any","posts_per_page"=>1,"title"=>"Duo Production Readiness Event 東京"])[0];
  update_post_meta($p->ID,"_EventCost",["future"=>"schema"]);
' >/dev/null
COST_RC=0
COST_OUT=$(wp_conf1 duo capture --repo=/siterepo 2>&1) || COST_RC=$?
[ "$COST_RC" -ne 0 ] && grep -q 'one scalar string' <<<"$COST_OUT" \
  || fail "TEC structured cost did not refuse before native consumption: $COST_OUT"
wp_conf1 eval '
  $b=json_decode(file_get_contents("/siterepo/.tmp-tec-schema-backup.json"),true,512,JSON_THROW_ON_ERROR);
  $p=get_posts(["post_type"=>"tribe_events","post_status"=>"any","posts_per_page"=>1,"title"=>"Duo Production Readiness Event 東京"])[0];
  update_post_meta($p->ID,"_EventCost",$b["cost"]);
' >/dev/null
rm -f "$SCHEMA_BACKUP"
pass "secret, impossible date, paid recurrence, and structured scalar probes refuse atomically"

# The adapter grants no semantic event deletion. Remove only wp_posts so the
# exact row can be restored after capture proves no tombstone was published.
DELETE_BACKUP="${CONF_REPO1:-siterepo/conf1}/.tmp-tec-delete-row.json"
DELETE_ID=$(wp_conf1 eval '
  global $wpdb;
  $p=get_posts(["post_type"=>"tribe_events","post_status"=>"any","posts_per_page"=>1,"title"=>"Duo Unsupported Delete Probe"])[0];
  $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->posts} WHERE ID=%d",$p->ID),ARRAY_A);
  file_put_contents("/siterepo/.tmp-tec-delete-row.json",wp_json_encode($row));
  if(1!==$wpdb->delete($wpdb->posts,["ID"=>$p->ID])) throw new RuntimeException($wpdb->last_error);
  clean_post_cache($p->ID); echo $p->ID;
')
require_fixture_ids DELETE_ID
DELETE_STATUS=$(git -C "$CONF_REPO1" status --porcelain --untracked-files=all -- state)
DELETE_RC=0
DELETE_OUT=$(wp_conf1 duo capture --repo=/siterepo --format=json) || DELETE_RC=$?
require_duo_answered "TEC unsupported event deletion capture" json "$DELETE_OUT"
[ "$DELETE_RC" -ne 0 ] && jq -e '
  .format == "duo-command-refusal/v1" and .reason_code == "unsupported_deletion" and
  any(.diagnostics[]?; .code == "unsupported_deletion" and .surface == "post:tribe_events")
' <<<"$DELETE_OUT" >/dev/null \
  || fail "TEC event deletion did not refuse at exact selector: $DELETE_OUT"
[ "$(git -C "$CONF_REPO1" status --porcelain --untracked-files=all -- state)" = "$DELETE_STATUS" ] \
  || fail "TEC deletion refusal partially published a tombstone"
wp_conf1 eval '
  global $wpdb; $row=json_decode(file_get_contents("/siterepo/.tmp-tec-delete-row.json"),true,512,JSON_THROW_ON_ERROR);
  if(false===$wpdb->insert($wpdb->posts,$row)) throw new RuntimeException($wpdb->last_error);
  clean_post_cache((int)$row["ID"]);
' >/dev/null
rm -f "$DELETE_BACKUP"
pass "unsupported TEC event deletion refuses at post:tribe_events with no partial tombstone"

# Competing source/target native repository edits must surface a conflict,
# remain atomic unforced, and converge only under explicit repository authority.
wp_conf1 eval '
  $p=get_posts(["post_type"=>"tribe_events","post_status"=>"any","posts_per_page"=>1,"title"=>"Duo Production Readiness Event 東京"])[0];
  $result=tribe_events()->where("id",$p->ID)->set_args([
    "description"=>"Repository competing body 東京 🚀 " . home_url("/repository-authority/"),
    "start_date"=>"2026-09-07 10:00:00","end_date"=>"2026-09-07 12:30:00","timezone"=>"Asia/Kathmandu"
  ])->save();
  if(empty($result[$p->ID])) throw new RuntimeException("TEC source repository update failed");
' >/dev/null
commit_tec_source 'conformance: competing TEC event intent'
wp_conf2 eval '
  $p=get_posts(["post_type"=>"tribe_events","post_status"=>"any","posts_per_page"=>1,"title"=>"Duo Production Readiness Event 東京"])[0];
  $result=tribe_events()->where("id",$p->ID)->set_args([
    "description"=>"Target competing body","start_date"=>"2032-01-01 05:00:00","end_date"=>"2032-01-01 06:00:00","timezone"=>"UTC"
  ])->save();
  if(empty($result[$p->ID])) throw new RuntimeException("TEC target repository update failed");
' >/dev/null
CONFLICT_BEFORE=$(tec_target_hash)
CONFLICT_PLAN=$(wp_conf2 duo plan --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered "TEC competing event plan" json "$CONFLICT_PLAN"
jq -e '(.conflict | length) > 0' <<<"$CONFLICT_PLAN" >/dev/null \
  || fail "TEC competing event did not produce a typed conflict: $CONFLICT_PLAN"
CONFLICT_RC=0
CONFLICT_OUT=$(wp_conf2 duo apply --repo=/siterepo --default-author=admin 2>&1) || CONFLICT_RC=$?
require_duo_answered "TEC unforced competing event apply" human "$CONFLICT_OUT"
[ "$CONFLICT_RC" -ne 0 ] && grep -qi 'conflict' <<<"$CONFLICT_OUT" \
  || fail "TEC competing event did not refuse: $CONFLICT_OUT"
[ "$(tec_target_hash)" = "$CONFLICT_BEFORE" ] || fail "TEC unforced conflict partially mutated target state"
FORCED=$(wp_conf2 duo apply --repo=/siterepo --force-theirs --default-author=admin --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered "TEC forced competing event apply" json "$FORCED"
jq -e '.canary == "clean" and .verification.result == "pass" and .plan.conflict >= 1' <<<"$FORCED" >/dev/null \
  || fail "TEC forced repository intent did not converge: $FORCED"
CONVERGED=$(observe_tec conf2)
printf '%s\n' "$CONVERGED" | jq -e '
  .home as $home |
  .event.start == "2026-09-07 10:00:00" and .event.end == "2026-09-07 12:30:00" and
  .event.timezone == "Asia/Kathmandu" and (.event.content | contains($home)) and
  .event.occurrence.start_date == .event.start and .event.occurrence.end_date == .event.end and
  .options.maps_key == "target-maps-key-preserved" and .cache == "target-runtime-preserved"
' >/dev/null || fail "TEC forced conflict did not repair native derived state or preserve runtime state: $CONVERGED"
pass "native event conflicts refuse atomically; explicit authority converges and regenerates occurrences"

# A late postmeta constraint failure lands after the post body write. The whole
# transaction, derived rows, and retry marker must survive as one unit.
wp_conf1 eval '
  $p=get_posts(["post_type"=>"tribe_events","post_status"=>"any","posts_per_page"=>1,"title"=>"Duo Production Readiness Event 東京"])[0];
  $result=tribe_events()->where("id",$p->ID)->set_args([
    "description"=>"TEC transaction body 東京 🚀 " . home_url("/transaction/"),
    "start_date"=>"2026-09-08 13:15:00","end_date"=>"2026-09-08 16:45:00","timezone"=>"Asia/Kathmandu"
  ])->save();
  if(empty($result[$p->ID])) throw new RuntimeException("TEC transaction source update failed");
' >/dev/null
commit_tec_source 'conformance: TEC transactional recovery intent'
FAULT_BEFORE=$(tec_target_hash)
wp_conf2 db query 'ALTER TABLE wp_postmeta DROP CONSTRAINT IF EXISTS duo_tec_fail_end' >/dev/null
wp_conf2 db query '
  ALTER TABLE wp_postmeta ADD CONSTRAINT duo_tec_fail_end
  CHECK (meta_key <> "_EventEndDate" OR meta_value <> "2026-09-08 16:45:00")
' >/dev/null
FAULT_RC=0
FAULT_OUT=$(wp_conf2 duo apply --repo=/siterepo --default-author=admin 2>&1) || FAULT_RC=$?
require_duo_answered "TEC injected transaction failure" human "$FAULT_OUT"
[ "$FAULT_RC" -ne 0 ] && grep -q 'duo_tec_fail_end' <<<"$FAULT_OUT" \
  || fail "TEC injected late database failure did not surface exactly: $FAULT_OUT"
[ "$(tec_target_hash)" = "$FAULT_BEFORE" ] || fail "TEC failed transaction left partial post/meta/derived writes"
[ "$(wp_conf2 eval 'echo null === \Duo\Ledger::kv_get("apply_in_progress") ? "clear" : "retained";')" = retained ] \
  || fail "TEC failed transaction did not retain retry authority"
wp_conf2 db query 'ALTER TABLE wp_postmeta DROP CONSTRAINT duo_tec_fail_end' >/dev/null
RETRY=$(wp_conf2 duo apply --repo=/siterepo --default-author=admin --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered "TEC retry after injected failure" json "$RETRY"
jq -e '.canary == "clean" and .verification.result == "pass" and .applied >= 1' <<<"$RETRY" >/dev/null \
  || fail "TEC retry did not consume durable intent: $RETRY"
RETRIED=$(observe_tec conf2)
printf '%s\n' "$RETRIED" | jq -e '
  .event.start == "2026-09-08 13:15:00" and .event.end == "2026-09-08 16:45:00" and
  .event.occurrence.start_date == .event.start and .event.occurrence.end_date == .event.end and
  (.event.content | contains("TEC transaction body 東京 🚀"))
' >/dev/null || fail "TEC retry did not converge native event/occurrence state: $RETRIED"
pass "late TEC metadata failure rolls back post/meta/projections, retains authority, and retries cleanly"

# Two real apply processes race one new event intent. At least one must win;
# any loser must name serialization rather than an unrelated failure.
wp_conf1 eval '
  $p=get_posts(["post_type"=>"tribe_events","post_status"=>"any","posts_per_page"=>1,"title"=>"Duo Production Readiness Event 東京"])[0];
  $result=tribe_events()->where("id",$p->ID)->set("description","Concurrent TEC intent 東京 🚀")->save();
  if(empty($result[$p->ID])) throw new RuntimeException("TEC concurrent source update failed");
' >/dev/null
commit_tec_source 'conformance: concurrent TEC apply intent'
CONCURRENT_A="${CONF_REPO2:-siterepo/conf2}/.tmp-tec-concurrent-a.log"
CONCURRENT_B="${CONF_REPO2:-siterepo/conf2}/.tmp-tec-concurrent-b.log"
set +e
wp_conf2 duo apply --repo=/siterepo --default-author=admin >"$CONCURRENT_A" 2>&1 & PID_A=$!
wp_conf2 duo apply --repo=/siterepo --default-author=admin >"$CONCURRENT_B" 2>&1 & PID_B=$!
wait "$PID_A"; RC_A=$?
wait "$PID_B"; RC_B=$?
set -e
if [ "$RC_A" -ne 0 ] && [ "$RC_B" -ne 0 ]; then
  fail "both competing TEC applies failed: A=$(cat "$CONCURRENT_A") B=$(cat "$CONCURRENT_B")"
fi
for result in A B; do
  eval "rc=\$RC_$result"; eval "log=\$CONCURRENT_$result"
  if [ "$rc" -eq 0 ]; then
    grep -q 'canary clean' "$log" || fail "successful competing TEC apply lacked a clean canary: $(cat "$log")"
  else
    grep -Eqi 'lock|another apply|in progress|promotion' "$log" \
      || fail "competing TEC apply failed outside the named lock: $(cat "$log")"
  fi
done
rm -f "$CONCURRENT_A" "$CONCURRENT_B"
CONCURRENT=$(observe_tec conf2)
printf '%s\n' "$CONCURRENT" | jq -e '
  .event.content == "Concurrent TEC intent 東京 🚀" and
  .event.occurrence.start_date == .event.start and .event.occurrence.end_date == .event.end
' >/dev/null || fail "competing TEC applies lost intent or occurrence repair: $CONCURRENT"
CONCURRENT_PLAN=$(wp_conf2 duo plan --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered "TEC plan after competing applies" json "$CONCURRENT_PLAN"
jq -e '([.create,.update,.drift,.conflict,.collision,.delete,.delete_conflict] | map(length) | add) == 0' <<<"$CONCURRENT_PLAN" >/dev/null \
  || fail "TEC competing applies left retained work: $CONCURRENT_PLAN"
pass "competing TEC applies serialize and leave one exact idempotent native result"

# TEC's uninstall.php is intentionally empty: code removal retains authored
# rows/options/tables. Missing code must refuse, then the exact cached artifact
# reinstalls and deploy reactivates without overwriting target-owned siblings.
wp_conf2 option update duo_tec_neighbor 'target-neighbor-preserved' >/dev/null
wp_conf2 plugin deactivate the-events-calendar >/dev/null
wp_conf2 plugin is-active the-events-calendar >/dev/null 2>&1 && fail "TEC deactivation premise did not land"
REACTIVATE=$(wp_conf2 duo deploy --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered "TEC deploy after deactivation" json "$REACTIVATE"
wp_conf2 plugin is-active the-events-calendar >/dev/null || fail "Duo deploy did not reactivate exact TEC code"
ROWS_BEFORE_UNINSTALL=$(wp_conf2 post list --post_type=tribe_events --format=count)
wp_conf2 plugin deactivate the-events-calendar >/dev/null
wp_conf2 plugin uninstall the-events-calendar >/dev/null
wp_conf2 plugin is-installed the-events-calendar >/dev/null 2>&1 && fail "TEC uninstall left plugin code installed"
[ "$(wp_conf2 db query 'SELECT COUNT(*) FROM wp_posts WHERE post_type="tribe_events"' --skip-column-names)" = "$ROWS_BEFORE_UNINSTALL" ] \
  || fail "TEC empty native uninstall unexpectedly deleted authored event rows"
[ "$(wp_conf2 option get duo_tec_neighbor)" = 'target-neighbor-preserved' ] \
  || fail "TEC uninstall mutated an unrelated target option"
MISSING_RC=0
MISSING_OUT=$(wp_conf2 duo deploy --repo=/siterepo 2>&1) || MISSING_RC=$?
require_duo_answered "TEC deploy with code absent" human "$MISSING_OUT"
[ "$MISSING_RC" -ne 0 ] && grep -Eq 'code_mismatch|missing_in_code|is not installed' <<<"$MISSING_OUT" \
  || fail "missing TEC code did not refuse at compatibility: $MISSING_OUT"
TEC_SHA=2db436c929797bfc5311be942158c474716e61c2f289f7d05c3a08d29b2ad687
TEC_ARTIFACT="/artifacts-cache/plugin-the-events-calendar-6.17.3-${TEC_SHA}.zip"
[ "$(wp_conf2 eval "echo hash_file('sha256', '$TEC_ARTIFACT');")" = "$TEC_SHA" ] \
  || fail "cached TEC reinstall artifact digest moved"
wp_conf2 plugin install "$TEC_ARTIFACT" --force >/dev/null
[ "$(wp_conf2 plugin get the-events-calendar --field=version)" = '6.17.3' ] \
  || fail "TEC exact reinstall reported wrong version"
REINSTALL_DEPLOY=$(wp_conf2 duo deploy --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered "TEC deploy after exact reinstall" json "$REINSTALL_DEPLOY"
REINSTALL_APPLY=$(wp_conf2 duo apply --repo=/siterepo --default-author=admin --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered "TEC apply after residue-preserving reinstall" json "$REINSTALL_APPLY"
jq -e '.canary == "clean" and .verification.result == "pass"' <<<"$REINSTALL_APPLY" >/dev/null \
  || fail "TEC exact reinstall did not retain/converge canonical state: $REINSTALL_APPLY"
RECOVERED=$(observe_tec conf2)
printf '%s\n' "$RECOVERED" | jq -e '
  .version == "6.17.3" and .event.content == "Concurrent TEC intent 東京 🚀" and
  .event.repository_id == .event.id and
  .event.occurrence.start_date == .event.start and .event.occurrence.end_date == .event.end and
  .category.meta.primary == "#654321" and .category.dropdown.primary == "#654321" and
  (.category_css | contains("--tec-color-category-primary:#654321")) and
  .options.maps_key == "target-maps-key-preserved" and .cache == "target-runtime-preserved"
' >/dev/null || fail "TEC native state did not survive exact reinstall: $RECOVERED"
[ "$(wp_conf2 option get duo_tec_neighbor)" = 'target-neighbor-preserved' ] \
  || fail "TEC recovery mutated the unrelated target option"
FINAL_PLAN=$(wp_conf2 duo plan --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered "TEC final recovery plan" json "$FINAL_PLAN"
jq -e '([.create,.update,.drift,.conflict,.collision,.delete,.delete_conflict] | map(length) | add) == 0' <<<"$FINAL_PLAN" >/dev/null \
  || fail "TEC recovery was not idempotent: $FINAL_PLAN"
wp_conf2 duo capture --repo=/siterepo --out=/siterepo/.tmp-tec-final >/dev/null
diff -r "$CONF_REPO1/state" "$CONF_REPO2/.tmp-tec-final" || fail "TEC final recovered state was not byte-identical"
rm -rf "$CONF_REPO2/.tmp-tec-final"
rm -f "$SOURCE_IDS_FILE" "$TARGET_IDS_FILE"
pass "deactivate/reactivate, residue-preserving uninstall, absent-code refusal, exact reinstall, native readback, and final recapture are clean"
