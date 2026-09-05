#!/usr/bin/env bash
# Candidate-bound TEC scope refusal: seed the exact free-plugin product graph
# while single-site, convert that same database to a real network, then prove
# every adapter-facing command refuses before it can mutate the populated graph.
set -euo pipefail
PACKAGE_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
export WPRISM_ARTIFACT_PACKAGE="${PACKAGE_ROOT##*/}"
cd "$PACKAGE_ROOT/../../sandbox"

say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*" >&2; exit 1; }
. lib/pair_db.sh
pair_db_select_engine
# shellcheck source=../../conformance/asserts.sh
. conformance/asserts.sh

command -v jq >/dev/null || fail "jq required"
PAIR="${TEC_MULTISITE_PAIR:-tecms}"
PORT1="${TEC_MULTISITE_PORT1:-9010}"
PORT2="${TEC_MULTISITE_PORT2:-9011}"
[[ "$PAIR" =~ ^[a-z][a-z0-9]*$ ]] || fail "invalid TEC_MULTISITE_PAIR '$PAIR'"
[[ "$PORT1" =~ ^[0-9]+$ && "$PORT2" =~ ^[0-9]+$ ]] \
  || fail "TEC multisite ports must be decimal integers"
[ "$PORT1" -ge 1024 ] && [ "$PORT1" -le 65535 ] \
  && [ "$PORT2" -ge 1024 ] && [ "$PORT2" -le 65535 ] && [ "$PORT1" != "$PORT2" ] \
  || fail "TEC multisite ports must be distinct and within 1024..65535"

REPO_ROOT="$(cd .. && pwd -P)"
SOURCE_SHA="$(git -C "$REPO_ROOT" rev-parse HEAD)"
EXPECTED_SHA="${WPRISM_EXPECTED_SOURCE_SHA:-}"
[[ "$EXPECTED_SHA" =~ ^[0-9a-f]{40}$ ]] \
  || fail "WPRISM_EXPECTED_SOURCE_SHA must bind the exact lowercase 40-character candidate SHA"
[ "$EXPECTED_SHA" = "$SOURCE_SHA" ] \
  || fail "WPRISM_EXPECTED_SOURCE_SHA=$EXPECTED_SHA does not equal this checkout HEAD=$SOURCE_SHA"
[ -z "$(git -C "$REPO_ROOT" status --porcelain=v1 --untracked-files=all)" ] \
  || fail "TEC multisite evidence requires a clean candidate checkout"

WORDPRESS_OFFLINE="${WPRISM_WORDPRESS_ORG_OFFLINE:-0}"
case "$WORDPRESS_OFFLINE" in
  0|1) ;;
  *) fail "WPRISM_WORDPRESS_ORG_OFFLINE must be 0 or 1" ;;
esac
export WPRISM_PAIR="$PAIR" WPRISM_PORT1="$PORT1" WPRISM_PORT2="$PORT2"
export WPRISM_EXPECTED_SOURCE_SHA="$SOURCE_SHA" WPRISM_ARTIFACT_OFFLINE="$WORDPRESS_OFFLINE"
PAIR_COMPOSE=(docker compose -p "wprism-$PAIR" -f pair.yml -f pair.artifacts.yml)
PAIR_UP_FLAGS=(--artifacts)
if [ "$WORDPRESS_OFFLINE" = 1 ]; then
  PAIR_COMPOSE+=(-f pair.wordpress-offline.yml)
  PAIR_UP_FLAGS+=(--wordpress-offline)
fi
# shellcheck source=../../bin/fetch-artifact.sh
. bin/fetch-artifact.sh
validate_artifact_library \
  || fail "artifact library is malformed before TEC multisite pair mutation"

wp1() { "${PAIR_COMPOSE[@]}" run --rm -T cli1 wp "$@"; }
wp_conf1() { wp1 "$@"; }
CONF_REPO1="siterepo/${PAIR}1"
export CONF_REPO1

cleanup() {
  bash bin/pair.sh destroy "$PAIR" >/dev/null 2>&1 || true
}
trap cleanup EXIT

tec_storage_fingerprint() {
  wp1 eval '
    global $wpdb;
    $queries = [
      "posts" => "SELECT * FROM {$wpdb->posts} WHERE post_type IN (\"tribe_events\",\"tribe_venue\",\"tribe_organizer\") ORDER BY ID",
      "postmeta" => "SELECT pm.* FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID=pm.post_id WHERE p.post_type IN (\"tribe_events\",\"tribe_venue\",\"tribe_organizer\") ORDER BY pm.meta_id",
      "terms" => "SELECT t.* FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id=t.term_id WHERE tt.taxonomy=\"tribe_events_cat\" ORDER BY t.term_id",
      "term_taxonomy" => "SELECT * FROM {$wpdb->term_taxonomy} WHERE taxonomy=\"tribe_events_cat\" ORDER BY term_taxonomy_id",
      "termmeta" => "SELECT tm.* FROM {$wpdb->termmeta} tm INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id=tm.term_id WHERE tt.taxonomy=\"tribe_events_cat\" ORDER BY tm.meta_id",
      "term_relationships" => "SELECT tr.* FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=tr.term_taxonomy_id WHERE tt.taxonomy=\"tribe_events_cat\" ORDER BY tr.object_id,tr.term_taxonomy_id",
      "tec_events" => "SELECT * FROM {$wpdb->prefix}tec_events ORDER BY event_id",
      "tec_occurrences" => "SELECT * FROM {$wpdb->prefix}tec_occurrences ORDER BY occurrence_id",
      "options" => $wpdb->prepare(
        "SELECT option_id,option_name,option_value,autoload FROM {$wpdb->options} WHERE option_name IN (%s,%s,%s,%s,%s,%s,%s,%s) ORDER BY option_id",
        "tribe_events_calendar_options",
        "tribe_customizer",
        "tribe_events_pro_customizer",
        "tec_events_category_color_css",
        "widget_tribe-widget-events-list",
        "sidebars_widgets",
        "wp_user_roles",
        "wprism_tec_multisite_canary"
      ),
    ];
    $fingerprint = [];
    foreach ($queries as $name => $sql) {
      $wpdb->last_error = "";
      $rows = $wpdb->get_results($sql, ARRAY_A);
      if (!is_array($rows) || $wpdb->last_error !== "") {
        throw new RuntimeException("TEC multisite fingerprint read failed for $name");
      }
      $fingerprint[$name] = [
        "count" => count($rows),
        "sha256" => hash("sha256", serialize($rows)),
      ];
    }
    if ($fingerprint["posts"]["count"] < 10
        || $fingerprint["tec_events"]["count"] < 3
        || $fingerprint["tec_occurrences"]["count"] < 3
        || $fingerprint["options"]["count"] !== 8) {
      throw new RuntimeException("TEC multisite fingerprint lacks the populated native fixture");
    }
    echo hash("sha256", wp_json_encode($fingerprint, JSON_UNESCAPED_SLASHES));
  ' | awk 'NF { line=$0 } END { print line }'
}

seed_adjacent_adapter_surfaces() {
  # The shared exact-artifact seed uses native repositories, registered meta,
  # settings APIs, status controllers, Custom Tables v1, and Category Colors.
  # shellcheck source=../conformance/seed.sh
  . "$(dirname "${BASH_SOURCE[0]}")/../conformance/seed.sh"
  rm -f "$CONF_REPO1/.tmp-tec-source-ids.json"
  wp1 eval '
    $canonical = [
      "month_view" => ["grid_lines_color" => "#123abc"],
      "tec_events_bar" => [
        "events_bar_text_color" => "#fedcba",
        "view_selector_background_color" => "#101010",
        "view_selector_background_color_choice" => "custom",
      ],
    ];
    $legacy = ["single_event" => ["post_title_color" => "#445566"]];
    update_option("tribe_customizer", $canonical, true);
    update_option("tribe_events_pro_customizer", $legacy, false);
    update_option("widget_tribe-widget-events-list", [
      2 => [
        "title" => "TEC network refusal widget 東京",
        "limit" => 7,
        "no_upcoming_events" => true,
        "featured_events_only" => false,
        "jsonld_enable" => true,
        "tribe_is_list_widget" => true,
      ],
      "_multiwidget" => 1,
    ], true);
    $sidebars = wp_get_sidebars_widgets();
    $sidebars["wp_inactive_widgets"] = array_values(array_unique(array_merge(
      (array) ($sidebars["wp_inactive_widgets"] ?? []),
      ["tribe-widget-events-list-2"]
    )));
    wp_set_sidebars_widgets($sidebars);
    update_option(
      "wprism_tec_multisite_canary",
      "untouched-" . $canonical["month_view"]["grid_lines_color"],
      false
    );
    if (get_option("tribe_customizer") !== $canonical
        || get_option("tribe_events_pro_customizer") !== $legacy
        || !in_array("tribe-widget-events-list-2", wp_get_sidebars_widgets()["wp_inactive_widgets"] ?? [], true)) {
      throw new RuntimeException("TEC adjacent multisite fixture did not persist exact Customizer/widget state");
    }
  ' >/dev/null
}

write_site_policy() {
  jq -n '{
    manifests:["core","the-events-calendar"],
    policy:{
      options:{
        default_category:{class:"env",required:false},
        page_for_posts:{class:"env",required:false},
        page_on_front:{class:"env",required:false},
        sticky_posts:{class:"env",required:false},
        wp_page_for_privacy_policy:{class:"env",required:false}
      },
      post_meta:{},
      post_types:["post","page","attachment","tribe_events","tribe_venue","tribe_organizer"],
      taxonomies:["category","post_tag","tribe_events_cat"]
    },
    spec_version:2
  }' > "$CONF_REPO1/site.wprism.json"
  cp site-repo.gitignore.template "$CONF_REPO1/.gitignore"
}

assert_command_refuses() { # <capture|plan|deploy|apply> <baseline-fingerprint> <site-policy-sha>
  local command="$1" baseline="$2" site_sha="$3" output rc=0 answer
  set +e
  output=$(wp1 wprism "$command" --repo=/siterepo --format=json 2>/dev/null)
  rc=$?
  set -e
  [ "$rc" -ne 0 ] || fail "TEC multisite $command returned success"
  answer=$(printf '%s\n' "$output" | awk 'NF { line=$0 } END { print line }')
  printf '%s\n' "$answer" | jq -e --arg command "$command" '
    .format == "wprism-command-refusal/v1" and
    .ok == false and
    .command == $command and
    .reason_code == "multisite_unsupported" and
    .error == "multisite_unsupported" and
    (has("details_redacted") | not) and
    (.message | contains("multisite is unsupported by the certified v1 contract")) and
    (.remediation | contains("single-site"))
  ' >/dev/null || fail "TEC multisite $command did not return the exact typed refusal: $answer"
  [ "$(tec_storage_fingerprint)" = "$baseline" ] \
    || fail "TEC multisite $command mutated the populated adapter graph"
  [ "$(shasum -a 256 "$CONF_REPO1/site.wprism.json" | awk '{print $1}')" = "$site_sha" ] \
    || fail "TEC multisite $command mutated site.wprism.json"
  [ ! -e "$CONF_REPO1/state" ] \
    && [ ! -e "$CONF_REPO1/state.capture-staging" ] \
    && [ ! -e "$CONF_REPO1/state.capture-backup" ] \
    || fail "TEC multisite $command published repository state before refusing"
  wp1 plugin is-active the-events-calendar >/dev/null \
    || fail "TEC multisite $command changed plugin activation state"
  pass "TEC multisite $command refuses before adapter/repository mutation"
}

for version in 6.17.2 6.17.3; do
  say "fresh exact TEC $version multisite pair"
  cleanup
  bash bin/pair.sh reset "$PAIR"
  bash bin/pair.sh up "$PAIR" "$PORT1" "$PORT2" "${PAIR_UP_FLAGS[@]}"
  artifact=$(fetch_artifact the-events-calendar "$version" cli1 plugin)
  wp1 plugin install "$artifact" --force --activate >/dev/null
  [ "$(wp1 plugin get the-events-calendar --field=version)" = "$version" ] \
    || fail "exact TEC $version artifact did not activate"

  seed_adjacent_adapter_surfaces
  write_site_policy
  wp1 core multisite-convert --title="WPrism TEC $version Multisite Refusal" >/dev/null
  [ "$(wp1 eval 'echo is_multisite() ? "yes" : "no";')" = yes ] \
    || fail "WordPress did not report multisite for TEC $version"
  [ "$(wp1 plugin get the-events-calendar --field=version)" = "$version" ] \
    || fail "multisite conversion changed the exact TEC $version artifact"

  # Settle normal plugin bootstrap, then require two identical physical reads
  # before attributing any later difference to a WPrism command.
  wp1 eval 'echo Tribe__Events__Main::VERSION;' >/dev/null
  before=$(tec_storage_fingerprint)
  require_observed_nonempty "TEC $version multisite baseline" "$before"
  [ "$(tec_storage_fingerprint)" = "$before" ] \
    || fail "TEC $version fixture is not stable across ordinary network boots"
  site_before=$(shasum -a 256 "$CONF_REPO1/site.wprism.json" | awk '{print $1}')

  for command in capture plan deploy apply; do
    assert_command_refuses "$command" "$before" "$site_before"
  done
  pass "exact TEC $version populated graph survives every pre-policy multisite refusal"
done

printf '\n\033[1;32m✔ REGRESS_THE_EVENTS_CALENDAR_MULTISITE_REFUSAL PASSED\033[0m\n'
cleanup
trap - EXIT
