seed_the_events_calendar_content() {
  # Reuse the standalone conformance seed verbatim: it authors the venue,
  # organizer and event through TEC's OWN repositories
  # (tribe_venues()/tribe_organizers()/tribe_events()), which is the only
  # path that populates the tec_events/tec_occurrences custom tables the
  # manifest classifies derived. A hand-written wp_insert_post fixture would
  # leave those tables empty and quietly weaken the boundary proof.
  wp_conf1() { wp1 "$@"; }
  local CONF_REPO1="siterepo/${PAIR}1"
  local COMPOSE="$PAIR_COMPOSE_STRING"
  . conformance/seeds/the-events-calendar.sh
  unset -f wp_conf1
}

postdeploy_the_events_calendar_content() {
  wp_conf2() { wp2 "$@"; }
  local CONF_REPO2="siterepo/${PAIR}2"
  local COMPOSE="$PAIR_COMPOSE_STRING"
  . conformance/postdeploy/the-events-calendar.sh
  unset -f wp_conf2
}

check_the_events_calendar_boundary_content() { # <label> <expected-plugin-version>
  # Deliberately NOT a grep of the apply log: TEC's convergence runs through
  # `regen_dependency` (manifests/the-events-calendar.json post_types.
  # tribe_events), not through a provider, so RebuildActionDispatcher.php:353's
  # "provider capability fired: ..." line is never emitted for this adapter.
  # The evidence is the same one sandbox/tests/live/regress_tec_regen.sh:230-234
  # uses: a matching tec_occurrences row AND plain-WP_Query visibility, which
  # is the hard dependency the manifest note documents (without an occurrence
  # row the post is invisible to WP_Query itself, admin list included).
  local label="$1" expected_version="$2" source_ids target_ids dirty_id out json
  source_ids=$(cat "siterepo/${PAIR}1/.tmp-tec-source-ids.json")
  target_ids=$(cat "siterepo/${PAIR}2/.tmp-tec-target-ids.json")
  require_fixture_values source_ids target_ids
  dirty_id=$(printf '%s\n' "$target_ids" | jq -er '.dirty_event')
  require_fixture_ids dirty_id
  out=$(wp2 eval '
    $events = get_posts([
      "post_type" => "tribe_events",
      "post_status" => "any",
      "posts_per_page" => -1,
      "name" => "duo-production-readiness-event",
    ]);
    if (count($events) !== 1) {
      throw new RuntimeException("expected exactly one adopted target event, got " . count($events));
    }
    $event = $events[0];
    $venue = get_post((int) get_post_meta($event->ID, "_EventVenueID", true));
    $organizer = get_post((int) get_post_meta($event->ID, "_EventOrganizerID", true));
    global $wpdb;
    $occurrence = $wpdb->get_row($wpdb->prepare(
      "SELECT start_date, end_date FROM {$wpdb->prefix}tec_occurrences WHERE post_id = %d",
      (int) $event->ID
    ), ARRAY_A);
    $events_rows = (int) $wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(*) FROM {$wpdb->prefix}tec_events WHERE post_id = %d",
      (int) $event->ID
    ));
    $repository_event = tribe_events()->where("id", (int) $event->ID)->first();
    $query = new WP_Query([
      "post_type" => "tribe_events",
      "post_status" => "any",
      "posts_per_page" => -1,
      "fields" => "ids",
    ]);
    $cache = $wpdb->get_var($wpdb->prepare(
      "SELECT value FROM {$wpdb->prefix}tec_kv_cache WHERE cache_key = %s",
      "duo-readiness-target-only"
    ));
    echo wp_json_encode([
      "cache" => $cache,
      "content" => $event->post_content,
      "end_meta" => get_post_meta($event->ID, "_EventEndDate", true),
      "event" => (int) $event->ID,
      "events_rows" => $events_rows,
      "occurrence" => $occurrence,
      "organizer" => $organizer ? (int) $organizer->ID : 0,
      "organizer_email" => $organizer ? get_post_meta($organizer->ID, "_OrganizerEmail", true) : "",
      "plugin_version" => Tribe__Events__Main::VERSION,
      "repository_event" => $repository_event ? (int) $repository_event->ID : 0,
      "start_meta" => get_post_meta($event->ID, "_EventStartDate", true),
      "venue" => $venue ? (int) $venue->ID : 0,
      "venue_city" => $venue ? get_post_meta($venue->ID, "_VenueCity", true) : "",
      "wp_query_ids" => array_map("intval", $query->posts),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  ')
  require_observed_nonempty "The Events Calendar $label target behavior" "$out"
  json=$(printf '%s\n' "$out" | awk 'NF { line=$0 } END { print line }')
  printf '%s\n' "$json" | jq -e \
    --argjson source "$source_ids" \
    --argjson dirty "$dirty_id" \
    --arg version "$expected_version" '
      .event == $dirty and
      .event != $source.event and .venue != $source.venue and .organizer != $source.organizer and
      .repository_event == .event and
      .wp_query_ids == [.event] and
      .events_rows == 1 and
      .plugin_version == $version and
      .start_meta == "2026-09-05 17:00:00" and .end_meta == "2026-09-05 20:00:00" and
      .occurrence.start_date == .start_meta and .occurrence.end_date == .end_meta and
      .venue_city == "Dhaka" and .organizer_email == "events@example.test" and
      (.content | contains("বাংলা café")) and
      .cache == "target-runtime-preserved"
    ' >/dev/null \
    || fail "The Events Calendar $label target did not converge its occurrence, query-visibility, reference and runtime boundary: $json"
  pass "The Events Calendar $label adopted divergent identities, repaired the stale occurrence, stayed WP_Query-visible, and preserved target-only runtime cache"
}
