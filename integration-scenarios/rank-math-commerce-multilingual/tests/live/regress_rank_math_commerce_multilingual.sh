#!/usr/bin/env bash
# Candidate-bound exact ACF/Polylang/Rank Math/WooCommerce product evidence.
# The source and target active-plugin orders deliberately reverse every plugin
# except Polylang, whose native pre-update filter forces itself first. This is
# pairwise-complete precedence evidence, not an install-order approximation.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../../../.." && pwd -P)"
cd "$ROOT/sandbox"

say() { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*" >&2; exit 1; }
. conformance/asserts.sh

for command in docker git jq mktemp php; do
  command -v "$command" >/dev/null 2>&1 || fail "$command required"
done

PAIR="${RANK_MATH_COMBO_PAIR:-}"
PORT1_RAW="${RANK_MATH_COMBO_PORT1:-}"
PORT2_RAW="${RANK_MATH_COMBO_PORT2:-}"
EXPECTED_SHA="${RANK_MATH_COMBO_EXPECTED_SOURCE_SHA:-}"
HEAD="$(git -C "$ROOT" --no-optional-locks rev-parse --verify 'HEAD^{commit}')" \
  || fail 'Rank Math combination evidence has no resolvable Git HEAD'
[[ "$PAIR" =~ ^[a-z][a-z0-9]{2,23}$ ]] \
  || fail 'RANK_MATH_COMBO_PAIR is required and must be a unique lowercase 3..24 character pair name'
case "$PAIR" in
  db|sandbox) fail "Rank Math combination pair '$PAIR' is reserved by the shared sandbox" ;;
esac
[[ "$PORT1_RAW" =~ ^[0-9]+$ && "$PORT2_RAW" =~ ^[0-9]+$ ]] \
  || fail 'RANK_MATH_COMBO_PORT1 and RANK_MATH_COMBO_PORT2 are required decimal ports'
PORT1=$((10#$PORT1_RAW))
PORT2=$((10#$PORT2_RAW))
(( PORT1 >= 8900 && PORT1 <= 65534 && PORT1 % 2 == 0 && PORT2 == PORT1 + 1 )) \
  || fail 'RANK_MATH_COMBO_PORT1 must be even and >=8900; RANK_MATH_COMBO_PORT2 must be its successor'
[[ "$EXPECTED_SHA" =~ ^[0-9a-f]{40}$ ]] \
  || fail 'RANK_MATH_COMBO_EXPECTED_SOURCE_SHA must be the exact lowercase 40-character candidate SHA'
[ "$EXPECTED_SHA" = "$HEAD" ] || fail "candidate SHA $EXPECTED_SHA does not equal checkout HEAD $HEAD"
SOURCE_STATUS="$(git -C "$ROOT" --no-optional-locks status --porcelain=v1 --untracked-files=all)" \
  || fail 'could not inspect Rank Math combination source cleanliness'
[ -z "$SOURCE_STATUS" ] || fail 'Rank Math combination evidence requires a clean candidate checkout'
docker info >/dev/null 2>&1 || fail 'Docker daemon is unavailable'

export WPRISM_SOURCE_ROOT="$ROOT" WPRISM_EXPECTED_SOURCE_SHA="$EXPECTED_SHA"
export WPRISM_PAIR="$PAIR" WPRISM_PORT1="$PORT1" WPRISM_PORT2="$PORT2"
export WPRISM_CODEBIND_PLUGIN='' WPRISM_DB_ENGINE='mariadb' WPRISM_DB_HOST='wprism-shared-db'
. tests/lib/pair_live_ownership.sh
pair_live_ownership_prepare "$PAIR" "$PORT1" "$PORT2" \
  'Rank Math combination' 'wprism-rmcombo'
COMPOSE=(docker compose -p "wprism-$PAIR" -f pair.yml -f pair.artifacts.yml)
PAIR_COMPOSE=("${COMPOSE[@]}")
. lib/host_orchestrator.sh
R1="siterepo/${PAIR}1"
R2="siterepo/${PAIR}2"
ORIGIN="siterepo/origin-$PAIR.git"
SCENARIO="$ROOT/integration-scenarios/rank-math-commerce-multilingual/scenario.json"
TMP_ROOT="$PAIR_LIVE_OWNERSHIP_TMP_ROOT"
WPRISM_HOST_REGISTRY=''
WPRISM_HOST_REGISTRY="$TMP_ROOT/host-envs.json"
for path in "$WPRISM_HOST_REGISTRY"; do
  [ ! -e "$path" ] && [ ! -L "$path" ] \
    || fail "chosen Rank Math combination scratch target already exists: $path"
done
wprism_host_registry_create "$WPRISM_HOST_REGISTRY" "$ROOT/sandbox/pair.yml" "$PAIR"
[ "$(pair_live_ownership_mode_of "$TMP_ROOT")" = 700 ] \
  || fail 'private Rank Math combination scratch mode is not 0700'
[ "$(pair_live_ownership_mode_of "$WPRISM_HOST_REGISTRY")" = 600 ] \
  || fail 'private Rank Math combination host registry mode is not 0600'

host_wprism_combo() { # <wp1|wp2> <verb> [args...]
  local side="$1"
  shift
  wprism_host_call "$ROOT/cli/wprism" "$WPRISM_HOST_REGISTRY" "wprism-$PAIR" \
    "${PAIR}${side#wp}" "$@"
}
WP_CLI_MEMORY_LIMIT=512M
wp_side() { # <side> <wp args...>
  local side="$1"; shift
  "${COMPOSE[@]}" run --rm -T --entrypoint php "cli$side" \
    -d "memory_limit=$WP_CLI_MEMORY_LIMIT" /usr/local/bin/wp "$@"
}
wp1() { wp_side 1 "$@"; }
wp2() { wp_side 2 "$@"; }
. bin/fetch-artifact.sh
WPRISM_ARTIFACT_PARTICIPANTS="$(artifact_library_scenario_participants "$SCENARIO")" \
  || fail 'Rank Math combination participant record is malformed'
export WPRISM_ARTIFACT_PARTICIPANTS
validate_artifact_library || fail 'Rank Math combination artifact library validation failed'

install_exact() { # <side> <slug> <version>
  local side="$1" slug="$2" version="$3" artifact actual
  artifact=$(fetch_artifact "$slug" "$version" "cli$side")
  "wp$side" plugin install "$artifact" --activate --force >/dev/null
  actual=$("wp$side" plugin get "$slug" --field=version)
  [ "$actual" = "$version" ] || fail "side $side: $slug is $actual, expected exact $version"
}

configure_rank_math() { # <wp1|wp2> <source|target>
  local side="$1" role="$2"
  "$side" option update rank_math_registration_skip 1 >/dev/null
  "$side" option update rank_math_is_configured 1 >/dev/null
  "$side" eval '
$role = (string) getenv("WPRISM_RMCOMBO_ROLE");
$desired = $role === "source"
    ? ["link-counter", "redirections", "rich-snippet"]
    : ["redirections", "rich-snippet"];
$stored = array_values(array_unique(array_map("strval", (array) get_option("rank_math_modules", []))));
if ($stored !== []) {
    RankMath\Helper::update_modules(array_fill_keys($stored, "off"));
}
RankMath\Helper::update_modules(array_fill_keys($desired, "on"));
$actual = array_values(array_unique(array_map("strval", (array) get_option("rank_math_modules", []))));
if ($actual !== $desired) {
    throw new RuntimeException("Rank Math native module lifecycle did not persist the exact requested set");
}
' --exec="putenv('WPRISM_RMCOMBO_ROLE=$role');" >/dev/null
}

rank_math_readiness() { # <wp1|wp2> <source|target>
  local side="$1" role="$2"
  "$side" eval '
global $wpdb;
$role = (string) getenv("WPRISM_RMCOMBO_ROLE");
$expectedModules = $role === "source"
    ? ["link-counter", "redirections", "rich-snippet"]
    : ["redirections", "rich-snippet"];
$requiredTables = $role === "source"
    ? ["rank_math_internal_links", "rank_math_internal_meta", "rank_math_redirections", "rank_math_redirections_cache"]
    : ["rank_math_redirections", "rank_math_redirections_cache"];
$modules = array_values(array_unique(array_map("strval", (array) get_option("rank_math_modules", []))));
$activeModules = array_values(RankMath\Helper::get_active_modules());
if ($modules !== $expectedModules) {
    throw new RuntimeException("Rank Math module state changed before the independent readiness readback");
}
if ($activeModules !== $expectedModules) {
    throw new RuntimeException("Rank Math manager did not accept the exact requested active module set");
}
$tables = [];
foreach ($requiredTables as $suffix) {
    $table = $wpdb->prefix . $suffix;
    $tables[$suffix] = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table;
}
echo wp_json_encode([
    "active_modules" => $activeModules,
    "modules" => $modules,
    "role" => $role,
    "tables" => $tables,
    "version" => defined("RANK_MATH_VERSION") ? RANK_MATH_VERSION : null,
], JSON_UNESCAPED_SLASHES);
' --exec="putenv('WPRISM_RMCOMBO_ROLE=$role');" | awk 'NF { line=$0 } END { print line }'
}

create_languages() { # <wp1|wp2>
  local side="$1"
  "$side" eval '
$languages = [
    ["locale"=>"en_US","slug"=>"en","name"=>"English","rtl"=>0,"term_group"=>0,"flag"=>"us"],
    ["locale"=>"de_DE","slug"=>"de","name"=>"Deutsch 東京","rtl"=>0,"term_group"=>1,"flag"=>"de"],
];
foreach ($languages as $args) {
    $result = isset(PLL()->model->languages)
        ? PLL()->model->languages->add($args)
        : (new PLL_Admin_Model(PLL()->options))->add_language($args);
    if (is_wp_error($result) && $result->has_errors()) {
        throw new RuntimeException($result->get_error_message());
    }
}
' >/dev/null
  # Polylang persists its model at shutdown. Configure translated Woo types
  # in a fresh request so that shutdown cannot overwrite the authored option.
  "$side" eval '
$options = get_option("polylang");
if (!is_array($options)) throw new RuntimeException("Polylang options are absent");
$options["default_lang"] = "en";
$options["browser"] = false;
$options["force_lang"] = 1;
$options["hide_default"] = false;
$options["media_support"] = 1;
$options["post_types"] = ["product"];
$options["redirect_lang"] = false;
$options["rewrite"] = true;
$options["taxonomies"] = ["product_cat"];
$options["sync"] = ["taxonomies", "post_meta", "post_date"];
update_option("polylang", $options);
' >/dev/null
}

active_plugin_order() { # <wp1|wp2>
  local side="$1"
  "$side" option get active_plugins --format=json \
    | jq -c 'map(split("/")[0])'
}

native_state() { # <wp1|wp2>
  local side="$1"
  "$side" eval '
global $wpdb;
// This program crosses the outer Bash single-quoted wp-eval boundary. The
// 900b3e52 live run lost three inline SQL quote pairs there; prepare every
// data literal, and reject last_error before an empty set or zero can pose as
// a valid post-apply observation.
$readRows = static function (string $sql) use ($wpdb): array {
    $rows = $wpdb->get_results($sql, ARRAY_A);
    if ($wpdb->last_error !== "" || !is_array($rows)) {
        throw new RuntimeException("combined native database row-set observation failed");
    }
    return $rows;
};
$readRow = static function (string $sql) use ($wpdb): ?array {
    $row = $wpdb->get_row($sql, ARRAY_A);
    if ($wpdb->last_error !== "" || ($row !== null && !is_array($row))) {
        throw new RuntimeException("combined native database row observation failed");
    }
    return $row;
};
$readValue = static function (string $sql) use ($wpdb) {
    $value = $wpdb->get_var($sql);
    if ($wpdb->last_error !== "") {
        throw new RuntimeException("combined native database scalar observation failed");
    }
    return $value;
};
$en = get_page_by_path("rmcombo-product-en", OBJECT, "product");
$de = get_page_by_path("rmcombo-product-de", OBJECT, "product");
$neighbor = get_page_by_path("rmcombo-target-neighbor", OBJECT, "product");
$book = get_page_by_path("rmcombo-book", OBJECT, "rmcombo_book");
$catEn = get_term_by("slug", "rmcombo-catalog-en", "product_cat");
$catDe = get_term_by("slug", "rmcombo-catalog-de", "product_cat");
if (!$en instanceof WP_Post || !$de instanceof WP_Post
    || !$catEn instanceof WP_Term || !$catDe instanceof WP_Term) {
    throw new RuntimeException("combined native product graph is incomplete");
}
$products = ["en" => wc_get_product($en), "de" => wc_get_product($de)];
$rows = [];
foreach ($products as $language => $product) {
    if (!$product instanceof WC_Product) throw new RuntimeException("Woo product API read failed");
    $post = $language === "en" ? $en : $de;
    $lookup = $readRow($wpdb->prepare(
        "SELECT min_price,max_price,stock_status FROM {$wpdb->wc_product_meta_lookup} WHERE product_id=%d",
        $post->ID
    ));
    $counts = $readRow($wpdb->prepare(
        "SELECT internal_link_count,external_link_count,incoming_link_count " .
        "FROM {$wpdb->prefix}rank_math_internal_meta WHERE object_id=%d",
        $post->ID
    ));
    $linkRows = $readRows($wpdb->prepare(
        "SELECT url,target_post_id,type FROM {$wpdb->prefix}rank_math_internal_links " .
        "WHERE post_id=%d ORDER BY type,url,target_post_id",
        $post->ID
    ));
    $rows[$language] = [
        "acf" => get_field("rmcombo_badge", $post->ID),
        "canonical" => get_post_meta($post->ID, "rank_math_canonical_url", true),
        "content" => $post->post_content,
        "description" => get_post_meta($post->ID, "rank_math_description", true),
        "id" => (int) $post->ID,
        "language" => pll_get_post_language($post->ID, "slug"),
        "links" => $linkRows,
        "lookup" => $lookup,
        "price" => $product->get_regular_price("edit"),
        "primary" => (int) get_post_meta($post->ID, "rank_math_primary_product_cat", true),
        "processed" => (bool) get_post_meta($post->ID, "rank_math_internal_links_processed", true),
        "rank_counts" => $counts,
        "title" => get_post_meta($post->ID, "rank_math_title", true),
        "url" => get_permalink($post),
    ];
}
$redirection = null;
foreach ($readRows("SELECT * FROM {$wpdb->prefix}rank_math_redirections ORDER BY id") as $row) {
    $sources = maybe_unserialize($row["sources"] ?? "");
    if (is_array($sources) && ($sources[0]["pattern"] ?? null) === "rmcombo-old") {
        $redirection = [
            "header_code"=>(int)$row["header_code"],"hits"=>(int)$row["hits"],
            "id"=>(int)$row["id"],"sources"=>$sources,"status"=>$row["status"],"url_to"=>$row["url_to"],
        ];
    }
}
$bookLinks = [];
$bookCounts = null;
if ($book instanceof WP_Post) {
    $bookLinks = $readRows($wpdb->prepare(
        "SELECT url,target_post_id,type FROM {$wpdb->prefix}rank_math_internal_links " .
        "WHERE post_id=%d ORDER BY type,url,target_post_id",
        $book->ID
    ));
    $bookCounts = $readRow($wpdb->prepare(
        "SELECT internal_link_count,external_link_count,incoming_link_count " .
        "FROM {$wpdb->prefix}rank_math_internal_meta WHERE object_id=%d",
        $book->ID
    ));
}
if (!function_exists("as_get_scheduled_actions")) {
    throw new RuntimeException("Action Scheduler API is unavailable");
}
$scheduler = $readRows($wpdb->prepare(
    "SELECT a.action_id,a.hook,a.status,g.slug AS group_slug " .
    "FROM {$wpdb->prefix}actionscheduler_actions a " .
    "LEFT JOIN {$wpdb->prefix}actionscheduler_groups g ON g.group_id=a.group_id " .
    "WHERE a.hook IN (%s,%s) ORDER BY a.action_id",
    "rmcombo_source_runtime",
    "rmcombo_target_runtime"
));
$redirectionCache = $readRows($wpdb->prepare(
    "SELECT from_url,redirection_id,object_id,object_type,is_redirected " .
    "FROM {$wpdb->prefix}rank_math_redirections_cache WHERE from_url=%s ORDER BY id",
    "rmcombo-old"
));
$retiredTargetCounts = $neighbor instanceof WP_Post ? $readRow($wpdb->prepare(
    "SELECT internal_link_count,external_link_count,incoming_link_count " .
    "FROM {$wpdb->prefix}rank_math_internal_meta WHERE object_id=%d",
    $neighbor->ID
)) : null;
$staleLinkSentinels = (int) $readValue($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}rank_math_internal_links " .
    "WHERE url IN (%s,%s)",
    "/target-stale",
    "/target-stale-book"
));
$neighborState = null;
if ($neighbor instanceof WP_Post) {
    $neighborProduct = wc_get_product($neighbor);
    if (!$neighborProduct instanceof WC_Product) throw new RuntimeException("target-only neighbor API read failed");
    $neighborState = [
        "acf"=>get_field("rmcombo_badge",$neighbor->ID),"id"=>(int)$neighbor->ID,
        "price"=>$neighborProduct->get_regular_price("edit"),
        "title"=>get_post_meta($neighbor->ID,"rank_math_title",true),
    ];
}
echo wp_json_encode([
    "book" => $book instanceof WP_Post ? [
        "content"=>$book->post_content,"id"=>(int)$book->ID,"links"=>$bookLinks,
        "processed"=>(bool)get_post_meta($book->ID,"rank_math_internal_links_processed",true),
        "rank_counts"=>$bookCounts,"title"=>get_post_meta($book->ID,"rank_math_title",true),
    ] : null,
    "categories" => ["en"=>(int)$catEn->term_id,"de"=>(int)$catDe->term_id],
    "category_languages" => [
        "en"=>pll_get_term_language($catEn->term_id, "slug"),
        "de"=>pll_get_term_language($catDe->term_id, "slug"),
    ],
    "modules" => array_values((array) get_option("rank_math_modules", [])),
    "neighbor" => $neighborState,
    "products" => $rows,
    "redirection" => $redirection,
    "redirection_cache" => $redirectionCache,
    "retired_target_counts" => $retiredTargetCounts,
    "scheduler" => $scheduler,
    "stale_link_sentinels" => $staleLinkSentinels,
    "term_translations" => array_map("intval", pll_get_term_translations($catEn->term_id)),
    "translations" => array_map("intval", pll_get_post_translations($en->ID)),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
' | awk 'NF { line=$0 } END { print line }'
}

product_response() { # <slug>
  local slug="$1" route host path response status body
  route=$(wp2 eval '
$slug = (string) getenv("WPRISM_RMCOMBO_SLUG");
$post = get_page_by_path($slug, OBJECT, "product");
if (!$post instanceof WP_Post) throw new RuntimeException("product route is absent");
echo wp_json_encode(["host"=>wp_parse_url(home_url("/"),PHP_URL_HOST),"path"=>wp_parse_url(get_permalink($post),PHP_URL_PATH)]);
' --exec="putenv('WPRISM_RMCOMBO_SLUG=$slug');" | awk 'NF { line=$0 } END { print line }')
  host=$(jq -er '.host|strings' <<<"$route")
  path=$(jq -er '.path|strings' <<<"$route")
  response=$("${COMPOSE[@]}" exec -T wp2 curl -sS --max-time 20 -H "Host: $host" \
    -w '\n__WPRISM_STATUS__%{http_code}' "http://127.0.0.1$path") \
    || fail "combined product request failed: $path"
  status=$(printf '%s\n' "$response" | tail -1)
  status=${status#__WPRISM_STATUS__}
  body=$(printf '%s\n' "$response" | sed '$d')
  [ "$status" = 200 ] || fail "combined product route returned $status: $path"
  printf '%s\n' "$body"
}

head_projection() { # HTML on stdin -> one exact JSON projection
  php -r '
$html = stream_get_contents(STDIN);
$document = new DOMDocument();
libxml_use_internal_errors(true);
if (!$document->loadHTML($html, LIBXML_NOWARNING | LIBXML_NOERROR)) exit(2);
$xpath = new DOMXPath($document);
$one = static function (string $query, string $attribute = "") use ($xpath): ?string {
    $nodes = $xpath->query($query);
    if (!$nodes || $nodes->length !== 1) return null;
    $node = $nodes->item(0);
    return $attribute === "" ? trim($node->textContent) : $node->attributes?->getNamedItem($attribute)?->nodeValue;
};
$alternates = [];
foreach ($xpath->query("//head/link[@rel=\"alternate\"]") ?: [] as $node) {
    $language = $node->attributes?->getNamedItem("hreflang")?->nodeValue;
    $href = $node->attributes?->getNamedItem("href")?->nodeValue;
    if (is_string($language) && $language !== "" && is_string($href) && $href !== "") $alternates[$language] = $href;
}
ksort($alternates, SORT_STRING);
echo json_encode([
    "alternates"=>$alternates,
    "canonical"=>$one("//head/link[@rel=\"canonical\"]", "href"),
    "description"=>$one("//head/meta[@name=\"description\"]", "content"),
    "og_description"=>$one("//head/meta[@property=\"og:description\"]", "content"),
    "og_locale"=>$one("//head/meta[@property=\"og:locale\"]", "content"),
    "og_title"=>$one("//head/meta[@property=\"og:title\"]", "content"),
    "og_url"=>$one("//head/meta[@property=\"og:url\"]", "content"),
    "title"=>$one("//head/title"),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
'
}

redirection_response() { # populates REDIRECT_STATUS and REDIRECT_LOCATION
  local route host response
  route=$(wp2 eval 'echo wp_json_encode(["host"=>wp_parse_url(home_url("/"),PHP_URL_HOST)]);' \
    | awk 'NF { line=$0 } END { print line }')
  host=$(jq -er '.host|strings' <<<"$route")
  response=$("${COMPOSE[@]}" exec -T wp2 curl -sS --max-time 20 -o /dev/null -D - \
    -H "Host: $host" -w '__WPRISM_STATUS__%{http_code}\n' 'http://127.0.0.1/rmcombo-old') \
    || fail 'Rank Math combination redirect request failed before response'
  REDIRECT_STATUS=$(awk -F'__WPRISM_STATUS__' '/__WPRISM_STATUS__/ { sub(/\r$/, "", $2); print $2 }' <<<"$response" | tail -1)
  REDIRECT_LOCATION=$(awk 'BEGIN { IGNORECASE=1 } /^Location:/ { sub(/\r$/, ""); print substr($0, 11) }' \
    <<<"$response" | tail -1)
}

install_hostile_provider() {
  "${COMPOSE[@]}" exec -T --user root wp2 sh -c '
set -eu
target=/var/www/html/wp-content/mu-plugins/wprism-rmcombo-provider-fault.php
mkdir -p "$(dirname "$target")"
tmp="$target.tmp.$$"
trap '\''rm -f "$tmp"'\'' EXIT HUP INT TERM
umask 022
cat >"$tmp"
chmod 0644 "$tmp"
mv "$tmp" "$target"
trap - EXIT HUP INT TERM
' <<'PHPEOF'
<?php
add_filter('rank_math/excluded_post_types', static fn($types) => $types);
PHPEOF
}

remove_hostile_provider() {
  "${COMPOSE[@]}" exec -T --user root wp2 \
    rm -f /var/www/html/wp-content/mu-plugins/wprism-rmcombo-provider-fault.php
}

install_custom_post_type() { # <1|2>
  local side="$1"
  "${COMPOSE[@]}" exec -T --user root "wp$side" sh -c '
set -eu
target=/var/www/html/wp-content/mu-plugins/wprism-rmcombo-cpt.php
mkdir -p "$(dirname "$target")"
tmp="$target.tmp.$$"
trap '\''rm -f "$tmp"'\'' EXIT HUP INT TERM
umask 022
cat >"$tmp"
chmod 0644 "$tmp"
mv "$tmp" "$target"
trap - EXIT HUP INT TERM
' <<'PHPEOF'
<?php
add_action('init', static function (): void {
    register_post_type('rmcombo_book', [
        'label' => 'RM Combo Books',
        'public' => true,
        'rewrite' => ['slug' => 'rmcombo-books'],
        'show_in_rest' => true,
        'supports' => ['title', 'editor'],
    ]);
});
PHPEOF
}

install_stack() { # <side> <forward|reverse>
  local side="$1" order="$2"
  if [ "$order" = forward ]; then
    install_exact "$side" advanced-custom-fields 6.8.7
    install_exact "$side" polylang 3.8.6
    install_exact "$side" seo-by-rank-math 1.0.277.2
    install_exact "$side" woocommerce 11.0.1
  elif [ "$order" = reverse ]; then
    install_exact "$side" woocommerce 11.0.1
    install_exact "$side" seo-by-rank-math 1.0.277.2
    install_exact "$side" polylang 3.8.6
    install_exact "$side" advanced-custom-fields 6.8.7
  else
    fail "unknown plugin-load order '$order'"
  fi
}

persist_active_plugin_order() { # <side> <forward|reverse>
  local side="$1" order="$2" plugins
  if [ "$order" = forward ]; then
    plugins='["polylang/polylang.php","advanced-custom-fields/acf.php","seo-by-rank-math/rank-math.php","woocommerce/woocommerce.php"]'
  elif [ "$order" = reverse ]; then
    plugins='["polylang/polylang.php","woocommerce/woocommerce.php","seo-by-rank-math/rank-math.php","advanced-custom-fields/acf.php"]'
  else
    fail "unknown plugin-load order '$order'"
  fi
  "wp$side" option update active_plugins "$plugins" --format=json >/dev/null
}

run_leg() { # <source order> <target order>
local source_order="$1" target_order="$2" expected_source expected_target
say "fresh exact four-plugin pair: source=$source_order target=$target_order"
bash bin/pair.sh repo-host "$PAIR" both >/dev/null
for side in 1 2; do
  "wp$side" site empty --yes >/dev/null
done
wp1 db query 'ALTER TABLE wp_posts AUTO_INCREMENT=3100001; ALTER TABLE wp_terms AUTO_INCREMENT=3200001; ALTER TABLE wp_term_taxonomy AUTO_INCREMENT=3300001;' >/dev/null
wp2 db query 'ALTER TABLE wp_posts AUTO_INCREMENT=9100001; ALTER TABLE wp_terms AUTO_INCREMENT=9200001; ALTER TABLE wp_term_taxonomy AUTO_INCREMENT=9300001;' >/dev/null

install_stack 1 "$source_order"
install_stack 2 "$target_order"
# Activation does not preserve install order, and Polylang intentionally moves
# itself first. Author the two pairwise-opposed orders after every artifact is
# active; independent readbacks prove the subsequent request boots each one.
persist_active_plugin_order 1 "$source_order"
persist_active_plugin_order 2 "$target_order"
install_custom_post_type 1
install_custom_post_type 2

SOURCE_ORDER=$(active_plugin_order wp1)
TARGET_ORDER=$(active_plugin_order wp2)
if [ "$source_order" = forward ]; then
  expected_source='["polylang","advanced-custom-fields","seo-by-rank-math","woocommerce"]'
else
  expected_source='["polylang","woocommerce","seo-by-rank-math","advanced-custom-fields"]'
fi
if [ "$target_order" = forward ]; then
  expected_target='["polylang","advanced-custom-fields","seo-by-rank-math","woocommerce"]'
else
  expected_target='["polylang","woocommerce","seo-by-rank-math","advanced-custom-fields"]'
fi
jq -en --argjson source "$SOURCE_ORDER" --argjson target "$TARGET_ORDER" \
  --argjson expected_source "$expected_source" --argjson expected_target "$expected_target" '
  $source == $expected_source and $target == $expected_target
' >/dev/null || fail "source/target active-plugin orders are not exact: $SOURCE_ORDER / $TARGET_ORDER"
pass "exact active-plugin orders established: source=$source_order target=$target_order"

for side in wp1 wp2; do
  "$side" eval 'WC_Install::create_terms();' >/dev/null
  create_languages "$side"
done
configure_rank_math wp1 source
configure_rank_math wp2 target
SOURCE_RANK_MATH_READY=$(rank_math_readiness wp1 source)
TARGET_RANK_MATH_READY=$(rank_math_readiness wp2 target)
require_observed_nonempty 'source Rank Math native module readiness' "$SOURCE_RANK_MATH_READY"
require_observed_nonempty 'target Rank Math native module readiness' "$TARGET_RANK_MATH_READY"
jq -en --argjson source "$SOURCE_RANK_MATH_READY" --argjson target "$TARGET_RANK_MATH_READY" '
  $source == {
    active_modules:["link-counter","redirections","rich-snippet"],
    modules:["link-counter","redirections","rich-snippet"],role:"source",
    tables:{rank_math_internal_links:true,rank_math_internal_meta:true,
      rank_math_redirections:true,rank_math_redirections_cache:true},
    version:"1.0.277.2"
  } and
  $target == {
    active_modules:["redirections","rich-snippet"],
    modules:["redirections","rich-snippet"],role:"target",
    tables:{rank_math_redirections:true,rank_math_redirections_cache:true},
    version:"1.0.277.2"
  }
' >/dev/null || fail "Rank Math native module readiness is incomplete: $SOURCE_RANK_MATH_READY / $TARGET_RANK_MATH_READY"
pass 'Rank Math native module lifecycle persisted exact roles and installed every required table'

say 'author native multilingual products, ACF values, Rank Math SEO/link state and Woo lookup state'
SOURCE_SEED=$(wp1 eval '
global $wpdb;
$createTerm = static function (string $name, string $slug): int {
    $result = wp_insert_term($name, "product_cat", ["slug"=>$slug]);
    if (is_wp_error($result)) throw new RuntimeException($result->get_error_message());
    return (int) $result["term_id"];
};
$categories = [
    "en"=>$createTerm("Portable Catalog English 東京", "rmcombo-catalog-en"),
    "de"=>$createTerm("Tragbarer Katalog Deutsch", "rmcombo-catalog-de"),
];
foreach ($categories as $language=>$id) pll_set_term_language($id, $language);
pll_save_term_translations($categories);

$group = [
    "key"=>"group_rmcombo_product", "title"=>"Portable Product Fields 東京", "fields"=>[],
    "location"=>[[["param"=>"post_type","operator"=>"==","value"=>"product"]]], "active"=>true,
];
acf_update_field_group($group);
$groupPosts = get_posts(["post_type"=>"acf-field-group","name"=>$group["key"],"posts_per_page"=>1,"fields"=>"ids","post_status"=>"any"]);
if (!$groupPosts) throw new RuntimeException("ACF product group was not created");
acf_update_field([
    "key"=>"field_rmcombo_badge", "label"=>"Portable Badge", "name"=>"rmcombo_badge",
    "type"=>"text", "parent"=>(int)$groupPosts[0],
]);

$products = [];
foreach (["en","de"] as $language) {
    $product = new WC_Product_Simple();
    $product->set_name($language === "en" ? "Portable Commerce English 東京 🚀" : "Tragbarer Handel Deutsch 東京 🚀");
    $product->set_slug("rmcombo-product-$language");
    $product->set_status("publish");
    $product->set_regular_price($language === "en" ? "29" : "31");
    $id = $product->save();
    if (!$id) throw new RuntimeException("Woo product creation failed");
    $products[$language] = (int) $id;
    pll_set_post_language((int)$id, $language);
    wp_set_object_terms((int)$id, [$categories[$language]], "product_cat");
    update_field("field_rmcombo_badge", $language === "en" ? "English badge 東京" : "Deutsches Abzeichen 東京", (int)$id);
}
pll_save_post_translations($products);
foreach (["en","de"] as $language) {
    $other = $language === "en" ? "de" : "en";
    $product = wc_get_product($products[$language]);
    $product->set_description("<p>Portable $language product 東京 🚀 <a href=\"" . esc_url(get_permalink($products[$other])) . "\">translated peer</a> <a href=\"https://external.example.test/rmcombo\">external</a></p>");
    $product->save();
    update_post_meta($products[$language], "rank_math_title", $language === "en" ? "Portable Rank Math Commerce EN 東京 🚀" : "Tragbarer Rank Math Handel DE 東京 🚀");
    update_post_meta($products[$language], "rank_math_description", $language === "en" ? "Portable English commerce SEO 東京." : "Tragbare deutsche Commerce-SEO 東京.");
    update_post_meta($products[$language], "rank_math_canonical_url", get_permalink($products[$language]));
    update_post_meta($products[$language], "rank_math_primary_product_cat", (string)$categories[$language]);
    RankMath\Links\Links::process_post_links($products[$language], get_post($products[$language]));
}
$book = wp_insert_post([
    "post_type"=>"rmcombo_book","post_status"=>"publish","post_name"=>"rmcombo-book",
    "post_title"=>"Portable custom Rank Math book 東京",
    "post_content"=>"<p>Custom CPT <a href=\"" . esc_url(get_permalink($products["en"])) . "\">product</a> " .
        "<a href=\"https://external.example.test/rmcombo-book\">external</a></p>",
], true);
if (is_wp_error($book) || (int)$book < 1) throw new RuntimeException("custom CPT creation failed");
update_post_meta((int)$book,"rank_math_title","Portable custom CPT SEO 東京");
RankMath\Links\Links::process_post_links((int)$book,get_post((int)$book));
$redirection = RankMath\Redirections\Redirection::from([
    "sources"=>[["pattern"=>"rmcombo-old","comparison"=>"exact","ignore"=>""]],
    "url_to"=>get_permalink($products["en"]), "header_code"=>"302", "status"=>"active",
]);
$redirectionId = $redirection->save();
if (!is_int($redirectionId) || $redirectionId < 1) throw new RuntimeException("Rank Math redirection creation failed");
if (function_exists("as_schedule_single_action")) as_schedule_single_action(time()+3600, "rmcombo_source_runtime");
echo wp_json_encode(["book"=>(int)$book,"categories"=>$categories,"group"=>(int)$groupPosts[0],"products"=>$products,"redirection"=>$redirectionId]);
' | awk 'NF { line=$0 } END { print line }')
require_observed_nonempty 'Rank Math combination source seed' "$SOURCE_SEED"
SOURCE_NATIVE=$(native_state wp1)
jq -e '
  .scheduler == [{action_id: .scheduler[0].action_id, hook:"rmcombo_source_runtime", status:"pending", group_slug:""}] and
  (.scheduler[0].action_id | tonumber) > 0
' <<<"$SOURCE_NATIVE" >/dev/null \
  || fail "source Action Scheduler witness is absent or ambiguous before capture: $SOURCE_NATIVE"
pass 'source-only Action Scheduler state exists natively before capture'

TARGET_SEED=$(wp2 eval '
global $wpdb;
$createTerm = static function (string $name, string $slug): int {
    $result = wp_insert_term($name, "product_cat", ["slug"=>$slug]);
    if (is_wp_error($result)) throw new RuntimeException($result->get_error_message());
    return (int) $result["term_id"];
};
$categories = [
    "en"=>$createTerm("Target Catalog EN", "rmcombo-catalog-en"),
    "de"=>$createTerm("Target Catalog DE", "rmcombo-catalog-de"),
];
foreach ($categories as $language=>$id) pll_set_term_language($id, $language);
pll_save_term_translations($categories);
$group=["key"=>"group_rmcombo_product","title"=>"Target Product Fields","fields"=>[],"location"=>[[["param"=>"post_type","operator"=>"==","value"=>"product"]]],"active"=>true];
acf_update_field_group($group);
$groupPosts=get_posts(["post_type"=>"acf-field-group","name"=>$group["key"],"posts_per_page"=>1,"fields"=>"ids","post_status"=>"any"]);
acf_update_field(["key"=>"field_rmcombo_badge","label"=>"Target Badge","name"=>"rmcombo_badge","type"=>"text","parent"=>(int)$groupPosts[0]]);
$products=[];
foreach (["en","de"] as $language) {
    $product=new WC_Product_Simple();
    $product->set_name("Target stale $language product");
    $product->set_slug("rmcombo-product-$language");
    $product->set_status("publish");
    $product->set_regular_price($language === "en" ? "81" : "82");
    $id=$product->save();
    $products[$language]=(int)$id;
    pll_set_post_language((int)$id,$language);
    wp_set_object_terms((int)$id,[$categories[$language]],"product_cat");
    update_field("field_rmcombo_badge","target stale badge $language",(int)$id);
    update_post_meta((int)$id,"rank_math_title","target stale SEO $language");
    update_post_meta((int)$id,"rank_math_primary_product_cat",(string)$categories[$language]);
}
pll_save_post_translations($products);
$book=wp_insert_post([
    "post_type"=>"rmcombo_book","post_status"=>"publish","post_name"=>"rmcombo-book",
    "post_title"=>"Target stale custom book","post_content"=>"<p>target stale custom content</p>",
],true);
if(is_wp_error($book)||(int)$book<1) throw new RuntimeException("target custom CPT creation failed");
update_post_meta((int)$book,"rank_math_title","target stale custom SEO");
$neighbor=new WC_Product_Simple();
$neighbor->set_name("Target-only scheduler neighbor");
$neighbor->set_slug("rmcombo-target-neighbor");
$neighbor->set_status("publish");
$neighbor->set_regular_price("97");
$neighborId=$neighbor->save();
update_field("field_rmcombo_badge","target-only badge",(int)$neighborId);
update_post_meta((int)$neighborId,"rank_math_title","target-only SEO");
$sources=maybe_serialize([["ignore"=>"","pattern"=>"rmcombo-old","comparison"=>"exact"]]);
$wpdb->insert($wpdb->prefix."rank_math_redirections",[
    "sources"=>$sources,"url_to"=>home_url("/target-stale/"),"header_code"=>301,
    "hits"=>41,"status"=>"inactive","created"=>"2020-01-01 00:00:00","updated"=>"2020-01-02 00:00:00","last_accessed"=>"2020-01-03 00:00:00",
]);
$redirectionId=(int)$wpdb->insert_id;
$wpdb->insert($wpdb->prefix."rank_math_redirections_cache",[
    "from_url"=>"rmcombo-old","redirection_id"=>$redirectionId,"object_id"=>999999999,
    "object_type"=>"post","is_redirected"=>0,
]);
$wpdb->query("DELETE FROM {$wpdb->prefix}rank_math_internal_links");
$wpdb->query("DELETE FROM {$wpdb->prefix}rank_math_internal_meta");
foreach ($products as $id) {
    $wpdb->insert($wpdb->prefix."rank_math_internal_links",["url"=>"/target-stale","post_id"=>$id,"target_post_id"=>$neighborId,"type"=>"internal"]);
    $wpdb->insert($wpdb->prefix."rank_math_internal_meta",["object_id"=>$id,"internal_link_count"=>999,"external_link_count"=>999,"incoming_link_count"=>999]);
}
$wpdb->insert($wpdb->prefix."rank_math_internal_links",["url"=>"/target-stale-book","post_id"=>(int)$book,"target_post_id"=>$neighborId,"type"=>"internal"]);
$wpdb->insert($wpdb->prefix."rank_math_internal_meta",["object_id"=>(int)$book,"internal_link_count"=>999,"external_link_count"=>999,"incoming_link_count"=>999]);
$wpdb->insert($wpdb->prefix."rank_math_internal_meta",["object_id"=>$neighborId,"internal_link_count"=>0,"external_link_count"=>0,"incoming_link_count"=>999]);
if (function_exists("as_schedule_single_action")) as_schedule_single_action(time()+7200,"rmcombo_target_runtime");
echo wp_json_encode(["book"=>(int)$book,"categories"=>$categories,"group"=>(int)$groupPosts[0],"neighbor"=>(int)$neighborId,"products"=>$products,"redirection"=>$redirectionId]);
' | awk 'NF { line=$0 } END { print line }')
require_observed_nonempty 'Rank Math combination hostile target' "$TARGET_SEED"
HOSTILE_NATIVE=$(native_state wp2)
jq -e '
  .neighbor == {acf:"target-only badge",id:.neighbor.id,price:"97",title:"target-only SEO"} and
  .neighbor.id > 0 and
  .scheduler == [{action_id: .scheduler[0].action_id, hook:"rmcombo_target_runtime", status:"pending", group_slug:""}] and
  (.scheduler[0].action_id | tonumber) > 0 and
  .stale_link_sentinels == 3 and
  (.redirection_cache | length) == 1 and
  (.retired_target_counts.incoming_link_count | tonumber) == 999 and
  .redirection.header_code == 301 and .redirection.hits == 41 and .redirection.status == "inactive"
' <<<"$HOSTILE_NATIVE" >/dev/null \
  || fail "hostile target runtime/native premise is not exact: $HOSTILE_NATIVE"
pass 'target-only product and scheduler witnesses plus stale Rank Math projections are non-vacuous'

cat > "$R1/site.wprism.json" <<'EOF'
{
  "manifests": ["core", "acf", "polylang", "rank-math", "woocommerce"],
  "policy": {
    "options": {},
    "post_meta": {},
    "post_types": ["post", "page", "attachment", "product", "product_variation", "shop_coupon", "acf-field-group", "acf-field", "rmcombo_book"],
    "taxonomies": ["category", "post_tag", "product_brand", "product_cat", "product_shipping_class", "product_tag", "product_type", "product_visibility", "language", "term_language", "term_translations", "post_translations"]
  },
  "spec_version": 3
}
EOF
cp site-repo.gitignore.template "$R1/.gitignore"
git init -q -b main "$R1"
git -C "$R1" -c user.name=wprism-rmcombo -c user.email=rmcombo@example.test add -A
git -C "$R1" -c user.name=wprism-rmcombo -c user.email=rmcombo@example.test commit -qm 'policy: exact Rank Math commerce multilingual scenario'
git init --bare -b main "$ORIGIN" >/dev/null
git -C "$R1" remote add origin "../origin-$PAIR.git"
git -C "$R1" push -qu origin main
establish_core_environment_bindings wp1 /siterepo admin@example.test \
  "http://${PAIR}1.invalid" "http://${PAIR}1.invalid"
wp1 wprism capture --repo=/siterepo >/dev/null
wp1 wprism lint --repo=/siterepo >/dev/null
git -C "$R1" add -A
git -C "$R1" -c user.name=wprism-rmcombo -c user.email=rmcombo@example.test commit -qm 'capture: multilingual commerce SEO graph'
git -C "$R1" push -q origin main
git clone -q "$ORIGIN" "$R2"
chmod 0777 "$R2"
establish_core_environment_bindings wp2 /siterepo admin@example.test \
  "http://${PAIR}2.invalid" "http://${PAIR}2.invalid"
wprism_host_install_recovery_runtime "$ROOT" "$R1" \
  || fail 'Rank Math combination could not install the source recovery runtime'
wprism_host_install_recovery_runtime "$ROOT" "$R2" \
  || fail 'Rank Math combination could not install the target recovery runtime'

say 'deploy/apply combined product path against reverse-order hostile target'
wp2 db query 'ALTER TABLE wp_rank_math_internal_links ADD wprism_hostile_schema varchar(12) NULL' >/dev/null
DIRTY_DEPLOY_RC=0
DIRTY_DEPLOY=$(host_wprism_combo wp2 deploy 2>&1) || DIRTY_DEPLOY_RC=$?
[ "$DIRTY_DEPLOY_RC" -ne 0 ] \
  && grep -Fq 'Rank Math schema disagrees with the audited column/index contract' <<<"$DIRTY_DEPLOY" \
  || fail "independently extended Rank Math schema did not refuse host readiness: $DIRTY_DEPLOY"
DIRTY_NATIVE=$(native_state wp2)
jq -en --argjson before "$HOSTILE_NATIVE" --argjson after "$DIRTY_NATIVE" '$before == $after' >/dev/null \
  || fail "schema refusal crossed the target content/runtime boundary: $DIRTY_NATIVE"
wp2 db query 'ALTER TABLE wp_rank_math_internal_links DROP COLUMN wprism_hostile_schema' >/dev/null
# An inactive plugin plus one absent derived table is a legitimate host-level
# drift, not content drift. It forces the no-code deploy path through both
# lifecycle and schema settlement while the hostile cross-plugin graph remains
# available as an exact isolation oracle after activation recreates the table.
wp2 plugin deactivate seo-by-rank-math >/dev/null
wp2 db query 'DROP TABLE wp_rank_math_redirections_cache' >/dev/null
wp2 plugin is-inactive seo-by-rank-math >/dev/null \
  || fail 'Rank Math combination lifecycle drift premise is not inactive'
[ "$(wp2 db query "SHOW TABLES LIKE 'wp_rank_math_redirections_cache'" --skip-column-names | tr -d '[:space:]')" = '' ] \
  || fail 'Rank Math combination schema drift premise retained the derived cache table'
CLEAN_DEPLOY=$(host_wprism_combo wp2 deploy 2>&1) \
  || fail "clean Rank Math combination host deploy failed: $CLEAN_DEPLOY"
CLEAN_DEPLOY_PHASES=$(sed -n 's/^deploy phase: //p' <<<"$CLEAN_DEPLOY" | paste -sd ' ' -)
[ "$CLEAN_DEPLOY_PHASES" = 'compile lifecycle-status schema-status promotion-begin checkpoint provider-settlement-begin lifecycle-retire lifecycle-activate schema-settle lifecycle-settle provider-settlement-complete' ] \
  || fail "compatible host deploy skipped or reordered checkpointed lifecycle/schema settlement: $CLEAN_DEPLOY"
wp2 plugin is-active seo-by-rank-math >/dev/null \
  || fail 'compatible host deploy did not reactivate Rank Math'
[ "$(wp2 db query "SHOW TABLES LIKE 'wp_rank_math_redirections_cache'" --skip-column-names | tr -d '[:space:]')" = 'wp_rank_math_redirections_cache' ] \
  || fail 'compatible host deploy did not restore the missing Rank Math schema'
[ ! -e "$R2/.wprism/control/provider-settlement-intent.json" ] \
  || fail 'successful compatible host deploy retained provider settlement debt'
HOST_SETTLED_ORDER=$(active_plugin_order wp2)
jq -en --argjson actual "$HOST_SETTLED_ORDER" --argjson expected "$expected_source" \
  '$actual == $expected' >/dev/null \
  || fail "host lifecycle did not settle canonical source plugin order: $HOST_SETTLED_ORDER"
HOST_SETTLED_NATIVE=$(native_state wp2)
jq -en --argjson before "$HOSTILE_NATIVE" --argjson after "$HOST_SETTLED_NATIVE" '
  ($after | .redirection_cache = []) == ($before | .redirection_cache = []) and
  ($after.redirection_cache | length) == 0
' >/dev/null || fail "compatible host settlement crossed an unrelated plugin/content/runtime boundary: $HOST_SETTLED_NATIVE"
pass 'host deploy refuses hostile schema, then checkpoint-settles legitimate lifecycle/schema drift without crossing combination boundaries'
REVISION=$(git -C "$R2" rev-parse HEAD)
capture_wprism_json_success INITIAL 'Rank Math commerce/multilingual initial apply' \
  wp2 wprism apply --repo=/siterepo --adopt-by-slug=terms,posts \
  --default-author=admin --revision="$REVISION" --format=json
assert_wprism_apply_ready 'Rank Math commerce/multilingual initial apply' "$INITIAL"
jq -e '
  .canary == "clean" and .verification.result == "pass" and
  any(.actions[]?; .source == "provider:rank-math-state/rebuild_all_link_state" and .verified == true) and
  any(.actions[]?; .source == "provider:woocommerce-product-lookups/rebuild_product_lookups" and .verified == true) and
  any(.actions[]?; .source == "provider:polylang-nav-menus/synchronize_runtime" and .verified == true)
' <<<"$INITIAL" >/dev/null || fail "combined provider receipt is incomplete: $INITIAL"
jq -e '
  ([.actions[]?.source | select(startswith("provider:rank-math-state/"))] | sort | unique) ==
  ["provider:rank-math-state/rebuild_all_link_state"]
' <<<"$INITIAL" >/dev/null || fail "initial apply selected an unexpected Rank Math action set: $INITIAL"

TARGET=$(native_state wp2)
require_observed_nonempty 'Rank Math combination converged target' "$TARGET"
jq -en --argjson source "$SOURCE_SEED" --argjson hostile "$TARGET_SEED" \
  --argjson hostile_native "$HOSTILE_NATIVE" --argjson target "$TARGET" '
  ($target.products.en.id == $hostile.products.en) and
  ($target.products.de.id == $hostile.products.de) and
  ($target.products.en.id != $source.products.en) and
  ($target.products.de.id != $source.products.de) and
  ($target.book.id == $hostile.book) and ($target.book.id != $source.book) and
  ($target.categories.en == $hostile.categories.en) and
  ($target.categories.de == $hostile.categories.de) and
  ($target.products.en.language == "en") and ($target.products.de.language == "de") and
  ($target.category_languages.en == "en") and ($target.category_languages.de == "de") and
  ($target.translations.en == $target.products.en.id) and
  ($target.translations.de == $target.products.de.id) and
  ($target.term_translations.en == $target.categories.en) and
  ($target.term_translations.de == $target.categories.de) and
  ($target.products.en.primary == $target.categories.en) and
  ($target.products.de.primary == $target.categories.de) and
  ($target.products.en.acf == "English badge 東京") and
  ($target.products.de.acf == "Deutsches Abzeichen 東京") and
  ($target.products.en.price == "29") and ($target.products.de.price == "31") and
  (($target.products.en.lookup.min_price | tonumber) == 29) and
  (($target.products.de.lookup.min_price | tonumber) == 31) and
  ($target.products.en.links | length) == 2 and ($target.products.de.links | length) == 2 and
  ([ $target.products.en.links[].type ] | sort) == ["external","internal"] and
  ([ $target.products.de.links[].type ] | sort) == ["external","internal"] and
  ([ $target.products.en.links[] | select(.type == "internal") ][0].target_post_id | tonumber) == $target.products.de.id and
  ([ $target.products.de.links[] | select(.type == "internal") ][0].target_post_id | tonumber) == $target.products.en.id and
  ([ $target.products.en.links[] | select(.type == "external") ][0].target_post_id | tonumber) == 0 and
  ([ $target.products.de.links[] | select(.type == "external") ][0].target_post_id | tonumber) == 0 and
  ($target.products.en.rank_counts | map_values(tonumber)) == {external_link_count:1,incoming_link_count:1,internal_link_count:1} and
  ($target.products.de.rank_counts | map_values(tonumber)) == {external_link_count:1,incoming_link_count:1,internal_link_count:1} and
  ($target.products.en.processed == true) and ($target.products.de.processed == true) and
  ($target.book.processed == true) and
  ($target.book.title == "Portable custom CPT SEO 東京") and
  ($target.book.links | length) == 2 and
  ([ $target.book.links[].type ] | sort) == ["external","internal"] and
  ([ $target.book.links[] | select(.type == "internal") ][0].target_post_id | tonumber) == $target.products.en.id and
  ($target.book.rank_counts | map_values(tonumber)) == {external_link_count:1,incoming_link_count:0,internal_link_count:1} and
  ($target.products.en.title == "Portable Rank Math Commerce EN 東京 🚀") and
  ($target.products.de.title == "Tragbarer Rank Math Handel DE 東京 🚀") and
  ($target.products.en.description == "Portable English commerce SEO 東京.") and
  ($target.products.de.description == "Tragbare deutsche Commerce-SEO 東京.") and
  ($target.products.en.canonical == $target.products.en.url) and
  ($target.products.de.canonical == $target.products.de.url) and
  (($target.modules | index("link-counter")) != null) and
  ($target.redirection.id == $hostile.redirection) and
  ($target.redirection.sources == [{ignore:"",pattern:"rmcombo-old",comparison:"exact"}]) and
  ($target.redirection.url_to == $target.products.en.url) and
  ($target.redirection.header_code == 302) and
  ($target.redirection.hits == 41) and ($target.redirection.status == "active") and
  ($target.redirection_cache | length) == 0 and
  ($target.retired_target_counts.incoming_link_count | tonumber) == 0 and
  ($target.neighbor == $hostile_native.neighbor) and
  ($target.scheduler == $hostile_native.scheduler) and
  ($target.scheduler | all(.hook == "rmcombo_target_runtime" and .status == "pending" and .group_slug == "")) and
  ($target.stale_link_sentinels == 0)
' >/dev/null || fail "combined native state did not converge across local identities: $TARGET"
pass 'Woo, Polylang, ACF, Rank Math and a site-defined CPT converge while target runtime survives'

EN_BODY=$(product_response rmcombo-product-en)
DE_BODY=$(product_response rmcombo-product-de)
EN_HEAD=$(head_projection <<<"$EN_BODY")
DE_HEAD=$(head_projection <<<"$DE_BODY")
jq -en --argjson en "$EN_HEAD" --argjson de "$DE_HEAD" --argjson state "$TARGET" '
  $en.title == "Portable Rank Math Commerce EN 東京 🚀" and
  $en.description == "Portable English commerce SEO 東京." and
  $en.canonical == $state.products.en.url and $en.og_url == $state.products.en.url and
  $en.og_title == $en.title and $en.og_description == $en.description and $en.og_locale == "en_US" and
  $de.title == "Tragbarer Rank Math Handel DE 東京 🚀" and
  $de.description == "Tragbare deutsche Commerce-SEO 東京." and
  $de.canonical == $state.products.de.url and $de.og_url == $state.products.de.url and
  $de.og_title == $de.title and $de.og_description == $de.description and $de.og_locale == "de_DE" and
  $en.alternates["en-US"] == $state.products.en.url and
  $en.alternates["de-DE"] == $state.products.de.url and
  $de.alternates["en-US"] == $state.products.en.url and
  $de.alternates["de-DE"] == $state.products.de.url
' >/dev/null || fail "exact canonical/OG/reciprocal hreflang head state diverged: en=$EN_HEAD de=$DE_HEAD"
pass 'exact reciprocal hreflang, canonical and Open Graph state renders on both products'

redirection_response
[ "$REDIRECT_STATUS" = 302 ] && [ "$REDIRECT_LOCATION" = "$(jq -r '.products.en.url' <<<"$TARGET")" ] \
  || fail "native Rank Math redirection diverged: status=$REDIRECT_STATUS location=${REDIRECT_LOCATION:-<none>}"
TARGET_RUNTIME=$(native_state wp2)
jq -en --argjson before "$TARGET" --argjson after "$TARGET_RUNTIME" '
  $after.redirection.hits == ($before.redirection.hits + 1) and
  $after.redirection.header_code == 302 and $after.redirection.status == "active" and
  $after.redirection.url_to == $before.products.en.url and
  $after.neighbor == $before.neighbor and $after.scheduler == $before.scheduler
' >/dev/null || fail "native redirect telemetry or target runtime isolation diverged: $TARGET_RUNTIME"
pass 'native 302 routing consumes the target-bound URL and advances only target-local traffic telemetry'

say 'hostile ACF ownership overlap refuses under both manifest orders before publication'
POLICY_BYTES=$(<"$R1/site.wprism.json")
STATE_HASH_BEFORE=$(find "$R1/state" -type f -exec shasum -a 256 {} + | shasum -a 256 | awk '{print $1}')
wp1 eval '
$group=get_posts(["post_type"=>"acf-field-group","name"=>"group_rmcombo_product","posts_per_page"=>1,"fields"=>"ids","post_status"=>"any"]);
$product=get_page_by_path("rmcombo-product-en",OBJECT,"product");
if(!$group||!$product) throw new RuntimeException("ACF collision premise is absent");
acf_update_field(["key"=>"field_rmcombo_rank_title","label"=>"Hostile Rank Title","name"=>"rank_math_title","type"=>"post_object","post_type"=>["product"],"return_format"=>"id","parent"=>(int)$group[0]]);
update_field("field_rmcombo_rank_title",(int)$product->ID,(int)$product->ID);
' >/dev/null
for order in forward reverse; do
  if [ "$order" = forward ]; then
    manifests='["core","acf","polylang","rank-math","woocommerce"]'
  else
    manifests='["core","woocommerce","rank-math","polylang","acf"]'
  fi
  jq --argjson manifests "$manifests" '.manifests=$manifests' <<<"$POLICY_BYTES" > "$R1/site.wprism.json"
  COLLISION_RC=0
  COLLISION_OUT=$(wp1 wprism capture --repo=/siterepo 2>&1) || COLLISION_RC=$?
  [ "$COLLISION_RC" -ne 0 ] \
    && grep -Fq "post_meta 'rank_math_title' has multiple classification owners" <<<"$COLLISION_OUT" \
    || fail "ACF/Rank Math collision did not refuse under $order order: $COLLISION_OUT"
  [ "$(find "$R1/state" -type f -exec shasum -a 256 {} + | shasum -a 256 | awk '{print $1}')" = "$STATE_HASH_BEFORE" ] \
    || fail "ACF/Rank Math $order-order refusal partially published state"
done
printf '%s\n' "$POLICY_BYTES" > "$R1/site.wprism.json"
wp1 eval '
$field=acf_get_field("field_rmcombo_rank_title");
if(is_array($field)) acf_delete_field($field);
$product=get_page_by_path("rmcombo-product-en",OBJECT,"product");
delete_post_meta($product->ID,"_rank_math_title");
update_post_meta($product->ID,"rank_math_title","Portable Rank Math Commerce EN 東京 🚀");
' >/dev/null
wp1 wprism capture --repo=/siterepo --out=/siterepo/.tmp-rmcombo-collision-restored >/dev/null
diff -r "$R1/state" "$R1/.tmp-rmcombo-collision-restored" \
  || fail 'source did not return to its canonical state after removing the hostile ACF field'
rm -rf "$R1/.tmp-rmcombo-collision-restored"
pass 'hostile ACF ownership collision refuses identically under both pin orders'

say 'combined provider failure retains retry authority and converges after repair'
SOURCE_EN=$(jq -r '.products.en' <<<"$SOURCE_SEED")
require_fixture_ids SOURCE_EN
wp1 eval '
$post=get_post((int)getenv("WPRISM_RMCOMBO_SOURCE_EN"));
if(!$post instanceof WP_Post) throw new RuntimeException("retry source product is absent");
$post->post_content .= "<p>provider failure retained combined retry authority <a href=\"https://retry.example.test/\">retry external</a></p>";
wp_update_post($post);
' --exec="putenv('WPRISM_RMCOMBO_SOURCE_EN=$SOURCE_EN');" >/dev/null
wp1 wprism capture --repo=/siterepo >/dev/null
git -C "$R1" add -A
git -C "$R1" -c user.name=wprism-rmcombo -c user.email=rmcombo@example.test commit -qm 'capture: combined provider retry intent'
git -C "$R1" push -q origin main
git -C "$R2" pull -q origin main
FAIL_REV_BEFORE=$(wp2 db query "SELECT v FROM wp_wprism_kv WHERE k='applied_revision'" --skip-column-names | tr -d '[:space:]')
require_observed_nonempty 'Rank Math combination applied revision before fault' "$FAIL_REV_BEFORE"
install_hostile_provider
FAILURE_RC=0
FAILURE_OUT=$(wp2 wprism apply --repo=/siterepo --default-author=admin 2>&1) || FAILURE_RC=$?
[ "$FAILURE_RC" -ne 0 ] \
  && grep -Fq "provider 'rank-math-state' capability 'rebuild_all_link_state' failed" <<<"$FAILURE_OUT" \
  || fail "combined Rank Math provider fault did not refuse: $FAILURE_OUT"
[ "$(wp2 db query "SELECT v FROM wp_wprism_kv WHERE k='applied_revision'" --skip-column-names | tr -d '[:space:]')" = "$FAIL_REV_BEFORE" ] \
  || fail 'combined provider failure advanced applied_revision'
[ "$(wp2 eval 'echo null===\WPrism\Ledger::kv_get("apply_in_progress")?"clear":"retained";' | tail -1)" = retained ] \
  || fail 'combined provider failure did not retain retry authority'
FAILURE_NATIVE=$(native_state wp2)
jq -en --argjson baseline "$TARGET_RUNTIME" --argjson failed "$FAILURE_NATIVE" '
  ($failed | .products.en.content = $baseline.products.en.content) == $baseline and
  ($failed.products.en.content | contains("provider failure retained combined retry authority")) and
  ([ $failed.products.en.links[] | select(.url | contains("retry.example.test")) ] | length) == 0
' >/dev/null || fail "combined provider failure crossed a runtime boundary or fabricated derived success: $FAILURE_NATIVE"
remove_hostile_provider
capture_wprism_json_success RETRY 'Rank Math combination provider retry' \
  wp2 wprism apply --repo=/siterepo --default-author=admin --format=json
assert_wprism_apply_ready 'Rank Math combination provider retry' "$RETRY"
jq -e '
  .canary == "clean" and .verification.result == "pass" and
  any(.actions[]?; .source == "provider:rank-math-state/rebuild_all_link_state" and .verified == true) and
  any(.actions[]?; .source == "provider:woocommerce-product-lookups/rebuild_product_lookups" and .verified == true)
' <<<"$RETRY" >/dev/null || fail "combined retry receipt is incomplete: $RETRY"
jq -e '
  ([.actions[]?.source | select(startswith("provider:rank-math-state/"))] | sort | unique) ==
  ["provider:rank-math-state/rebuild_all_link_state"]
' <<<"$RETRY" >/dev/null || fail "retry selected an unexpected Rank Math action set: $RETRY"
RETRY_NATIVE=$(native_state wp2)
jq -en --argjson baseline "$TARGET_RUNTIME" --argjson retried "$RETRY_NATIVE" '
  ($retried
    | .products.en.content = $baseline.products.en.content
    | .products.en.links = $baseline.products.en.links
    | .products.en.rank_counts = $baseline.products.en.rank_counts) == $baseline and
  ($retried.products.en.content | contains("provider failure retained combined retry authority")) and
  ($retried.products.en.links | length) == 3 and
  ([ $retried.products.en.links[].type ] | sort) == ["external","external","internal"] and
  ([ $retried.products.en.links[] | select(.url | contains("retry.example.test")) ] | length) == 1 and
  ($retried.products.en.rank_counts | map_values(tonumber)) == {external_link_count:2,incoming_link_count:1,internal_link_count:1} and
  ($retried.products.de.rank_counts | map_values(tonumber)) == {external_link_count:1,incoming_link_count:1,internal_link_count:1}
' >/dev/null || fail "combined retry did not converge the exact new link projection in isolation: $RETRY_NATIVE"
pass 'provider failure and retry preserve every Woo, Polylang, ACF, taxonomy, module, CPT and target-runtime witness outside the intended Rank Math projection'

say 'combined recapture and repeated apply are exact no-ops'
REVISION=$(git -C "$R2" rev-parse HEAD)
capture_wprism_json_success NOOP 'Rank Math combination no-op apply' \
  wp2 wprism apply --repo=/siterepo --default-author=admin --revision="$REVISION" --format=json
assert_wprism_apply_ready 'Rank Math combination no-op apply' "$NOOP"
jq -e '.canary == "clean" and (.actions | length) == 0' <<<"$NOOP" >/dev/null \
  || fail "combined no-op reran effects: $NOOP"
wp2 wprism capture --repo=/siterepo --out=/siterepo/.tmp-rmcombo-final >/dev/null
FINAL_DIFF=$(diff -rq "$R1/state" "$R2/.tmp-rmcombo-final" || true)
rm -rf "$R2/.tmp-rmcombo-final"
[ -z "$FINAL_DIFF" ] || fail "combined target recapture differs: $FINAL_DIFF"
TARGET_FINAL=$(native_state wp2)
jq -en --argjson retried "$RETRY_NATIVE" --argjson final "$TARGET_FINAL" '
  $final == $retried and $final.products.en.processed == true and $final.products.de.processed == true
' >/dev/null || fail "combined final native/runtime state was not an exact no-op: $TARGET_FINAL"
product_response rmcombo-product-en >/dev/null
product_response rmcombo-product-de >/dev/null
pass "full source=$source_order target=$target_order path recaptures byte-identically and repeats with zero actions"

say 'direct deletion remains refusal-only across the combined adapter boundary'
SOURCE_BOOK=$(jq -r '.book' <<<"$SOURCE_SEED")
TARGET_BOOK=$(jq -r '.book.id' <<<"$TARGET_FINAL")
require_fixture_ids SOURCE_BOOK TARGET_BOOK
wp1 post delete "$SOURCE_BOOK" --force >/dev/null
wp1 wprism capture --repo=/siterepo >/dev/null
git -C "$R1" add -A
git -C "$R1" -c user.name=wprism-rmcombo -c user.email=rmcombo@example.test \
  commit -qm 'capture: delete custom CPT with derived Rank Math links'
git -C "$R1" push -q origin main
git -C "$R2" pull -q origin main
DELETE_WITHHELD_RC=0
DELETE_WITHHELD=$(wp2 wprism apply --repo=/siterepo --default-author=admin 2>&1) || DELETE_WITHHELD_RC=$?
[ "$DELETE_WITHHELD_RC" -ne 0 ] && grep -q -- '--with-deletes' <<<"$DELETE_WITHHELD" \
  || fail "combined custom-CPT deletion did not require explicit delete authority: $DELETE_WITHHELD"
wp2 post get "$TARGET_BOOK" --field=ID >/dev/null \
  || fail 'withheld Rank Math deletion removed the target post'
DELETE_REVISION=$(git -C "$R2" rev-parse HEAD)
DELETE_DIRECT_RC=0
DELETE_DIRECT=$(wp2 wprism apply --repo=/siterepo --with-deletes --default-author=admin \
  --revision="$DELETE_REVISION" --format=json 2>&1) || DELETE_DIRECT_RC=$?
require_wprism_answered 'Rank Math combination direct custom-CPT deletion refusal' json "$DELETE_DIRECT"
[ "$DELETE_DIRECT_RC" -ne 0 ] \
  && tail -1 <<<"$DELETE_DIRECT" | jq -e '.reason_code == "deletion_writer_exclusion_required"' >/dev/null \
  || fail "combined direct custom-CPT deletion crossed without signed external exclusion: $DELETE_DIRECT"
DELETE_REFUSAL_NATIVE=$(native_state wp2)
jq -en --argjson before "$TARGET_FINAL" --argjson after "$DELETE_REFUSAL_NATIVE" '$after == $before' >/dev/null \
  || fail "combined direct deletion refusal changed native or target-runtime state: $DELETE_REFUSAL_NATIVE"
wp2 post get "$TARGET_BOOK" --field=ID >/dev/null \
  || fail 'external-exclusion refusal removed the target custom post'
pass 'both direct deletion forms refuse before combined portable, derived, or target-runtime mutation; signed promotion is exercised by the SSH scenario extension'
}

# The generic lease is the sole fresh-namespace authority: under the shared
# lock it proves Compose/name/ports/roots/install markers plus both persistent
# schemas absent, then binds that complete fact to this exact process. Arm
# cleanup only after publication and hold the token through both legs.
pair_live_ownership_acquire mariadb
say "bring up caller-allocated Rank Math combination pair at candidate $HEAD"
pair_live_ownership_up --artifacts --headless
run_leg forward reverse

# The reverse-order leg reuses only the pair this process already created.
# Reset stays here, between completed legs, and is never an ownership shortcut.
[ "$PAIR_LIVE_OWNERSHIP_LEASE_ACTIVE" -eq 1 ] \
  || fail 'Rank Math combination lost pair ownership before its second leg'
pair_live_ownership_reset
pair_live_ownership_up --artifacts --headless
run_leg reverse forward

pair_live_ownership_complete '✔ REGRESS_RANK_MATH_COMMERCE_MULTILINGUAL PASSED'
