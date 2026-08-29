#!/usr/bin/env bash
# Candidate-bound mixed Woo rewrite evidence: only the product-route provider,
# its exact active artifacts, hook topology, and hostile refusal/retry.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../../../.." && pwd -P)"
cd "$ROOT/sandbox"

say() { printf '\n== %s ==\n' "$*"; }
pass() { printf 'ok: %s\n' "$*"; }
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }
. conformance/asserts.sh

PAIR="${WOO_REWRITE_COINSTALL_PAIR:-woorewrite}"
PORT1="${WOO_REWRITE_COINSTALL_PORT1:-8978}"
PORT2="${WOO_REWRITE_COINSTALL_PORT2:-8979}"
EXPECTED_SHA="${WPRISM_EXPECTED_SOURCE_SHA:-}"
HEAD="$(git -C "$ROOT" rev-parse HEAD)"
[ -n "$EXPECTED_SHA" ] || fail 'WPRISM_EXPECTED_SOURCE_SHA is required'
[ "$EXPECTED_SHA" = "$HEAD" ] || fail "expected candidate $EXPECTED_SHA, checkout is $HEAD"
[[ "$PAIR" =~ ^[a-z][a-z0-9]*$ ]] || fail 'invalid pair name'
[[ "$PORT1" =~ ^[0-9]{4,5}$ && "$PORT2" =~ ^[0-9]{4,5}$ ]] || fail 'invalid pair ports'
command -v jq >/dev/null || fail 'jq is required'

export WPRISM_SOURCE_ROOT="$ROOT" WPRISM_EXPECTED_SOURCE_SHA="$EXPECTED_SHA" WPRISM_PAIR="$PAIR"
. lib/pair_identity.sh
pair_identity_export_source_mounts \
  || fail 'WooCommerce rewrite co-install could not pin its candidate mounts in the caller environment'
COMPOSE=(docker compose -p "wprism-$PAIR" -f pair.yml -f pair.artifacts.yml)
PAIR_COMPOSE=("${COMPOSE[@]}")
# Four exact production plugins exceed PHP's image-default 128 MiB while
# WordPress loads active plugins, before WP-CLI can raise its own ceiling.
# Keep the evidence process finite and below pair.yml's 768 MiB cgroup limit.
WP_CLI_MEMORY_LIMIT=512M
wp_side() { # side wp-arguments...
  local side="$1"; shift
  "${COMPOSE[@]}" run --rm -T --entrypoint php "cli$side" \
    -d "memory_limit=$WP_CLI_MEMORY_LIMIT" /usr/local/bin/wp "$@"
}
wp1() { wp_side 1 "$@"; }
wp2() { wp_side 2 "$@"; }
install_hostile_mu() { # basename; plugin bytes on stdin
  local name="$1" path
  [[ "$name" =~ ^wprism-woo-[a-z0-9-]+\.php$ ]] || fail "invalid hostile MU-plugin name: $name"
  path="/var/www/html/wp-content/mu-plugins/$name"
  # WordPress images may create mu-plugins as root:root 0755. Runtime fixture
  # ownership belongs to the disposable web container, so use its explicit
  # root boundary and atomically publish one world-readable hostile file.
  "${COMPOSE[@]}" exec -T --user root wp2 sh -c '
set -eu
mkdir -p "$(dirname "$1")"
tmp="$1.tmp.$$"
trap '\''rm -f "$tmp"'\'' EXIT HUP INT TERM
umask 022
cat >"$tmp"
chmod 0644 "$tmp"
mv "$tmp" "$1"
trap - EXIT HUP INT TERM
' sh "$path"
}
remove_hostile_mu() { # basename
  local name="$1" path
  [[ "$name" =~ ^wprism-woo-[a-z0-9-]+\.php$ ]] || fail "invalid hostile MU-plugin name: $name"
  path="/var/www/html/wp-content/mu-plugins/$name"
  "${COMPOSE[@]}" exec -T --user root wp2 sh -c 'test -f "$1" && rm "$1"' sh "$path"
}
R1="siterepo/$PAIR""1"
R2="siterepo/$PAIR""2"
ORIGIN="siterepo/origin-$PAIR.git"
TOPOLOGY="$ROOT/integration-scenarios/woocommerce-rewrite-coinstall/fixtures/woocommerce-rewrite-coinstall-topology.json"
SCENARIO="$ROOT/integration-scenarios/woocommerce-rewrite-coinstall/scenario.json"
. bin/fetch-artifact.sh
WPRISM_ARTIFACT_PARTICIPANTS="$(artifact_library_scenario_participants "$SCENARIO")" \
  || fail 'Woo rewrite co-install scenario metadata is malformed'
export WPRISM_ARTIFACT_PARTICIPANTS

GREEN=0
cleanup() {
  if [ "$GREEN" = 1 ]; then
    bash bin/pair.sh destroy "$PAIR" >/dev/null 2>&1 || true
    pass "destroyed $PAIR"
  else
    printf '(pair %s left up for inspection after failure)\n' "$PAIR" >&2
  fi
}
trap cleanup EXIT

install() { # side slug version
  local side="$1" slug="$2" version="$3" path actual
  path=$(fetch_artifact "$slug" "$version" "cli$side")
  "wp$side" plugin install "$path" --activate >/dev/null
  actual=$("wp$side" plugin get "$slug" --field=version)
  [ "$actual" = "$version" ] || fail "side $side: $slug is $actual, not exact $version"
}

witness() { # side
  local side="$1"
  "wp$side" eval '
global $wpdb;
$row=static function($name,$include_raw=false)use($wpdb){$r=$wpdb->get_row($wpdb->prepare("SELECT option_id,option_value,autoload FROM {$wpdb->options} WHERE option_name=%s LIMIT 1",$name),ARRAY_A);if(!is_array($r)){return null;}$raw=(string)$r["option_value"];$out=["id"=>(int)$r["option_id"],"sha256"=>hash("sha256",$raw),"autoload"=>(string)$r["autoload"]];if($include_raw){$out["raw"]=$raw;}return $out;};
$woo=$row("woocommerce_permalinks",true);
if(is_array($woo)){$raw=(string)$woo["raw"];unset($woo["raw"]);$value=maybe_unserialize($raw);$woo["value_base64"]=base64_encode($raw);$woo["keys"]=is_array($value)?array_keys($value):null;$woo["value"]=$value;}
echo wp_json_encode(["permalink"=>$row("permalink_structure"),"woo"=>$woo,"rules"=>$row("rewrite_rules"),"generate"=>$row("tribe_last_generate_rewrite_rules"),"save"=>$row("tribe_last_save_post"),"updated"=>$row("tribe_last_updated_option")]);
' | tail -1
}

product_route() { # side
  local side="$1" route id path host response status body
  route=$("wp$side" eval '
$product=get_page_by_path("rewrite-coinstall-product",OBJECT,"product");
if(!$product){throw new RuntimeException("product route witness is absent");}
$url=get_permalink($product);$path=wp_parse_url($url,PHP_URL_PATH);
if(!is_string($path)||$path===""){throw new RuntimeException("product permalink has no path");}
$language=function_exists("pll_get_post_language")?pll_get_post_language((int)$product->ID,"slug"):null;
 $host=wp_parse_url(home_url("/"),PHP_URL_HOST);
if(!is_string($host)||$host===""){throw new RuntimeException("product route home host is absent");}
echo wp_json_encode(["id"=>(int)$product->ID,"language"=>$language,"path"=>$path,"host"=>$host]);
' | tail -1) || fail "could not inspect the product permalink route on side $side"
  id=$(jq -er '.id|numbers' <<<"$route") || fail "product route witness has no numeric product id: $route"
  path=$(jq -er '.path|strings' <<<"$route") || fail "product route witness has no path: $route"
  host=$(jq -er '.host|strings' <<<"$route") || fail "product route witness has no home host: $route"
  # The pair is headless, so its published host ports are deliberately absent.
  # Request the exact generated path inside the web container and retain the
  # vhost Host header; this exercises WordPress' real request parser rather
  # than the CLI URL-to-ID helper, which can observe stale rewrite state in a
  # bootstrap immediately after a Woo permalink mutation.
  response=$("${COMPOSE[@]}" exec -T "wp$side" curl -sS --max-time 20 \
    -H "Host: $host" -w '\n__WPRISM_HTTP_STATUS__%{http_code}' \
    "http://127.0.0.1$path") || fail "product route HTTP request failed on side $side: $path"
  status=$(printf '%s\n' "$response" | tail -1)
  status=${status#__WPRISM_HTTP_STATUS__}
  body=$(printf '%s\n' "$response" | sed '$d')
  [ "$status" = 200 ] || fail "product route did not return HTTP 200 on side $side: path=$path status=$status"
  case "$body" in
    *single-product*) ;;
    *) fail "product route response is not a single-product page on side $side: path=$path" ;;
  esac
  case "$body" in
    *"postid-$id"*) ;;
    *) fail "product route response does not identify product $id on side $side: path=$path" ;;
  esac
  echo "$route" | jq --argjson status "$status" --argjson postid "$id" \
    '. + {http_status:$status,single_product:true,postid:$postid}'
}

assert_live_source_file_hashes() { # side
  local side="$1" plugin relative expected root actual
  while IFS=$'\t' read -r plugin relative expected; do
    case "$plugin" in
      woocommerce|wordpress-seo|polylang|the-events-calendar) root="$plugin" ;;
      *) fail "topology fixture declares an unsupported source plugin: $plugin" ;;
    esac
    [[ "$relative" =~ ^[A-Za-z0-9._/-]+$ && "$relative" != *".."* ]] \
      || fail "topology fixture declares an unsafe source path: $relative"
    # Current `wp eval` accepts only its PHP-code positional argument. Pass the
    # already-validated path through WP-CLI's supported global execution hook;
    # a second positional produced "Too many positional arguments" before the
    # first of the 50 exact source hashes could be observed.
    actual=$("wp$side" eval '
$relative=(string)getenv("WPRISM_AUDITED_PLUGIN_FILE");$path=WP_PLUGIN_DIR."/".$relative;
if(!is_file($path)){throw new RuntimeException("audited plugin source file is absent: ".$relative);}
echo hash_file("sha256",$path);
' --exec="putenv('WPRISM_AUDITED_PLUGIN_FILE=$root/$relative');" | tail -1) || fail "could not hash audited $plugin source file: $relative"
    [ "$actual" = "$expected" ] \
      || fail "installed $plugin source hash differs from topology fixture for $relative: $actual"
  done < <(jq -r '.source_files[] | [.plugin,.path,.sha256] | @tsv' "$TOPOLOGY")
}

say "candidate/source preflight: $HEAD"
jq -e '.format=="wprism-woocommerce-rewrite-coinstall-topology/v1" and .artifacts.woocommerce.version=="11.0.1" and .artifacts["wordpress-seo"].version=="28.3" and .artifacts["wordpress-seo"].versions=={"28.0":"348ac1e90fc5a1e50b716757728e2d6300918b3c8a0795d84e264f23cbf3776f","28.2":"f464e509d5f642023dc0a47082b3cdfed6b1fd5d5e4bf6584d6d43e0b53e8e23","28.3":"381edc1603147bd76af81341f21c9155ff3e9f6ce29ed20886d889fb9d6744fb"} and ((.source_files|map(select(.plugin=="wordpress-seo" and .path=="wp-seo-main.php"))|.[0].versions)=={"28.0":"c1eabcbc2c5e8243d7ee9c0a787330355492701603e1869c49eb78a9b51d3a0b","28.2":"9fdfe9f87a5c11c4d45673d121c81db9117d138357d297d7d2d3a4be5b387117","28.3":"5ecb2632b7997782e7efda714ab11e4a1ca479a8f3277c8e3137600bcb575ff1"}) and .artifacts.polylang.version=="3.8.6" and .artifacts["the-events-calendar"].version=="6.17.2" and (.source_files|length==55) and (.static_callbacks|length==39) and (.dynamic_callback_containers|length==4) and (.marker_option_topology.updated_option|length==5) and (.woocommerce_normal_option_topology.updated_option|length==4) and (.woocommerce_normal_option_topology.pre_update_option|length==1) and (.woocommerce_normal_option_topology.added_option|length==2) and (.yoast_normal_option_topology.pre_update_option|length==6) and (.yoast_normal_option_topology.update_option|length==7) and (.yoast_normal_option_topology.add_option|length==6) and (.yoast_normal_option_topology.pre_update_option|all(.priority==9223372036854775807 and .accepted_args==3)) and (.yoast_normal_option_topology.update_option|all(.priority==10 and .accepted_args==1)) and (.yoast_normal_option_topology.add_option|all(.priority==10 and .accepted_args==1)) and .yoast_normal_option_topology.woocommerce_permalinks=={"hook":"update_option_woocommerce_permalinks","callback":"Yoast\\WP\\SEO\\Integrations\\Third_Party\\Woocommerce_Permalinks::reset_woocommerce_permalinks","priority":10,"accepted_args":2} and .yoast_normal_option_topology.option_cache_map=={"wpseo":"WPSEO_Option_Wpseo","wpseo_titles":"WPSEO_Option_Titles","wpseo_social":"WPSEO_Option_Social","wpseo_taxonomy_meta":"WPSEO_Taxonomy_Meta","wpseo_llmstxt":"WPSEO_Option_Llmstxt","wpseo_tracking_only":"WPSEO_Option_Tracking_Only"} and .yoast_normal_option_topology.sitemap.global=="wpseo_sitemaps" and .yoast_normal_option_topology.sitemap.class=="WPSEO_Sitemaps" and .yoast_normal_option_topology.sitemap.cache_property=="cache" and .yoast_normal_option_topology.sitemap.cache_class=="WPSEO_Sitemaps_Cache" and .yoast_normal_option_topology.sitemap.cache_callback=={"hook":"update_option","method":"clear_on_option_update","priority":10,"accepted_args":1} and .marker_option_topology.pre_option.optional_callback=="TEC\\Common\\Integrations\\Harbor\\PUE::filter_pre_get_option" and .marker_option_topology.wp_default_autoload_value.callback=="wp_filter_default_autoload_value_via_option_size" and (.dynamic_callback_containers|any(.=={"hook":"rewrite_rules_array","callback":"PLL_Sitemaps::rewrite_rules","priority":10,"accepted_args":1,"activation":"at least one Polylang language and the normal sitemap loader initializes the runtime-owned sitemap service"})) and (.dynamic_callback_containers|any(.hook=="pll_modify_rewrite_rule" and .accepted_args==4))' "$TOPOLOGY" >/dev/null || fail 'co-install topology fixture is not exact'
validate_artifact_library
artifact_library_jq -e --slurpfile topology "$TOPOLOGY" '. as $library | ($topology[0].artifacts | to_entries | all(. as $artifact | $library.plugins[$artifact.key][$artifact.value.version].sha256 == $artifact.value.sha256)) and ($topology[0].artifacts["wordpress-seo"].versions | to_entries | all(. as $artifact | $library.plugins["wordpress-seo"][$artifact.key].sha256 == $artifact.value))' >/dev/null || fail 'co-install artifact hashes differ from the artifact library'
pass 'candidate, artifact hashes, and audited topology are pinned'

say "fresh exact co-install pair $PAIR"
bash bin/pair.sh reset "$PAIR"
bash bin/pair.sh up "$PAIR" "$PORT1" "$PORT2" --artifacts --headless
for side in 1 2; do
  observed_limit=$("${COMPOSE[@]}" run --rm -T --entrypoint php "cli$side" \
    -d "memory_limit=$WP_CLI_MEMORY_LIMIT" -r 'echo ini_get("memory_limit");' | tail -1)
  [ "$observed_limit" = "$WP_CLI_MEMORY_LIMIT" ] \
    || fail "side $side pre-bootstrap PHP memory limit is $observed_limit, not $WP_CLI_MEMORY_LIMIT"
done
pass "both sides pin the finite $WP_CLI_MEMORY_LIMIT pre-bootstrap PHP limit for the four-plugin process"
for side in 1 2; do
  install "$side" woocommerce 11.0.1
  install "$side" wordpress-seo 28.3
  install "$side" polylang 3.8.6
  install "$side" the-events-calendar 6.17.2
  "wp$side" site empty --yes >/dev/null
  # `site empty` removes Woo's install-time taxonomy inventory. Restore it
  # through the exact plugin API so capture sees all nine product_visibility
  # terms and Woo CRUD below can write the one required product_type relation.
  "wp$side" eval 'WC_Install::create_terms();' >/dev/null
  establish_woocommerce_hpos "wp$side" >/dev/null \
    || fail "side $side could not establish HPOS through WooCommerce's native new-shop lifecycle"
done
assert_live_source_file_hashes 1
assert_live_source_file_hashes 2
pass 'both sides have the four exact active plugins and audited source-file hashes'

# pair.sh reset erased only this pair's origin and cleared these two bind roots
# in place. Keep those inodes for the running containers; Git accepts the
# empty target root rather than replacing it with a freshly-created directory.
bash bin/pair.sh repo-host "$PAIR" both >/dev/null
[ ! -e "$ORIGIN" ] || fail "pair reset did not remove the exact disposable origin: $ORIGIN"
git init --bare -b main "$ORIGIN" >/dev/null

say 'source: real directory-mode Polylang plus exact five-key Woo source row'
wp1 eval '
update_option("permalink_structure","/%postname%/");
update_option("woocommerce_permalinks",["product_base"=>"store/%product_cat%","category_base"=>"catalog","tag_base"=>"label","attribute_base"=>"feature","use_verbose_page_rules"=>true]);
$lang=PLL()->model->add_language(["locale"=>"en_US","name"=>"English","slug"=>"en","rtl"=>false,"term_group"=>0,"no_default_cat"=>true]);
if(is_wp_error($lang)||!$lang instanceof PLL_Language){throw new RuntimeException("could not create the Polylang language");}
$product=new WC_Product_Simple();
$product->set_name("Rewrite Co-install Product");
$product->set_slug("rewrite-coinstall-product");
$product->set_status("publish");
$product_id=$product->save();
if(!$product_id){throw new RuntimeException("could not create product through WooCommerce CRUD");}
pll_set_post_language((int)$product_id,"en");
' >/dev/null
# add_language() leaves Polylang's Options singleton dirty; its shutdown save
# overwrites a direct update in that request with the old hide_default=true.
# Cross the same fresh-request boundary as the certified Polylang seed before
# selecting directory mode with Woo products in Polylang's translated-post
# registry, then prove the bytes survived that process exit.
wp1 eval '
$polylang=get_option("polylang");
if(!is_array($polylang)){throw new RuntimeException("Polylang source option is not an array");}
$polylang["default_lang"]="en";
$polylang["browser"]=false;
$polylang["force_lang"]=1;
$polylang["hide_default"]=false;
$polylang["media_support"]=1;
$polylang["post_types"]=["product"];
$polylang["redirect_lang"]=false;
$polylang["rewrite"]=true;
$polylang["taxonomies"]=[];
$polylang["sync"]=["taxonomies","post_meta","post_date"];
update_option("polylang",$polylang);
' >/dev/null
POLYLANG_SOURCE_MODE=$(wp1 option get polylang --format=json)
jq -e '
  .default_lang=="en" and .browser==false and .force_lang==1 and
  .hide_default==false and .media_support==1 and .post_types==["product"] and
  .redirect_lang==false and .rewrite==true and .taxonomies==[] and
  .sync==["taxonomies","post_meta","post_date"]
' <<<"$POLYLANG_SOURCE_MODE" >/dev/null \
  || fail "Polylang directory-mode source option did not survive its authoring request: $POLYLANG_SOURCE_MODE"
wp1 rewrite flush --hard >/dev/null
cat > "$R1/site.wprism.json" <<'EOF'
{
  "manifests": ["core", "woocommerce", "yoast", "polylang", "the-events-calendar"],
  "policy": {
    "options": {},
    "post_meta": {},
    "post_types": ["post", "page", "attachment", "product", "product_variation", "shop_coupon", "tribe_events"],
    "taxonomies": ["category", "post_tag", "product_cat", "product_shipping_class", "product_tag", "product_type", "product_visibility", "language", "term_language", "term_translations", "post_translations"]
  },
  "spec_version": 2
}
EOF
cp site-repo.gitignore.template "$R1/.gitignore"
git -C "$R1" init -q -b main
git -C "$R1" remote add origin "../origin-$PAIR.git"
wp1 wprism capture --repo=/siterepo >/dev/null
bash bin/pair.sh repo-host "$PAIR" 1 >/dev/null
git -C "$R1" -c user.name=wprism-woo-rewrite -c user.email=woo-rewrite@example.test add -A
git -C "$R1" -c user.name=wprism-woo-rewrite -c user.email=woo-rewrite@example.test commit -qm 'capture: Woo rewrite co-install baseline'
git -C "$R1" push -qu origin main
[ -z "$(find "$R2" -mindepth 1 -maxdepth 1 -print -quit)" ] \
  || fail "pair reset did not leave the exact target repository root empty: $R2"
git clone -q "$ORIGIN" "$R2"
bash bin/pair.sh repo-host "$PAIR" 2 >/dev/null
REVISION=$(git -C "$R2" rev-parse HEAD)
INITIAL_RC=0
INITIAL_RAW=$(wp2 wprism apply --repo=/siterepo --adopt-by-slug=posts,terms --default-author=admin --revision="$REVISION" --format=json 2>&1) \
  || INITIAL_RC=$?
[ "$INITIAL_RC" -eq 0 ] \
  || fail "initial co-install apply failed (exit $INITIAL_RC): $INITIAL_RAW"
[ -n "$INITIAL_RAW" ] || fail 'initial co-install apply returned empty evidence'
INITIAL=$(printf '%s\n' "$INITIAL_RAW" | tail -1)
echo "$INITIAL" | jq -e '.canary=="clean" and (.actions|any(.kind=="provider" and .source=="provider:woocommerce-hierarchy-lookups/rebuild_product_permalink_routes" and .verified==true))' >/dev/null || fail "initial product-route receipt missing: $INITIAL"
pass 'normal apply invoked the verified product-route provider'

say 'inspect the actual co-install callback identities and effects'
HOOKS=$(wp2 eval '
$markers=["tribe_last_generate_rewrite_rules","tribe_last_updated_option","tribe_last_save_post"];
$want=["rewrite_rules_array","option_rewrite_rules","sanitize_option_rewrite_rules","generate_rewrite_rules","category_rewrite_rules","updated_option","pre_option","wp_default_autoload_value","pre_wp_load_alloptions","pre_cache_alloptions","alloptions","pre_update_option","update_option","update_option_woocommerce_permalinks","wp_autoload_values_to_autoload","wp_max_autoloaded_option_size","add_option","added_option","pll_rewrite_rules","pll_modify_rewrite_rule"];
foreach($markers as $name){foreach(["sanitize_option_","pre_option_","default_option_","option_","pre_update_option_","update_option_","add_option_"] as $prefix){$want[]=$prefix.$name;}}
$rows=[];
foreach($want as $hook){foreach(($GLOBALS["wp_filter"][$hook]->callbacks??[])as $priority=>$set){foreach($set as $entry){$f=$entry["function"]??null;if(is_string($f)){$name=$f;}elseif(is_array($f)&&isset($f[0],$f[1])){$name=(is_object($f[0])?get_class($f[0]):$f[0])."::".$f[1];}else{continue;}$rows[]=["hook"=>$hook,"priority"=>(int)$priority,"args"=>(int)($entry["accepted_args"]??0),"callback"=>$name];}}}echo wp_json_encode($rows);
' | tail -1)
echo "$HOOKS" | jq -e --slurpfile topology "$TOPOLOGY" '
  def canon: sort_by(.priority,.args,.callback);
  def static_rewrite_hook:
    .hook=="rewrite_rules_array" or .hook=="option_rewrite_rules" or .hook=="sanitize_option_rewrite_rules" or .hook=="generate_rewrite_rules" or .hook=="category_rewrite_rules";
  . as $actual |
  ([ $actual[] | select(static_rewrite_hook) |
      select(.callback!="PLL_Links_Directory::rewrite_rules" and .callback!="PLL_Sitemaps::rewrite_rules") ] | canon) ==
    ([ $topology[0].static_callbacks[] | select(static_rewrite_hook) |
      {hook,priority,args:.accepted_args,callback} ] | canon) and
  ([ $actual[] | select(.hook=="updated_option") | del(.hook) ] | canon) ==
    ([ $topology[0].woocommerce_normal_option_topology.updated_option[], $topology[0].marker_option_topology.updated_option[] | {priority, args:.accepted_args, callback} ] | canon) and
  ([ $actual[] | select(.hook=="pre_update_option") | del(.hook) ] | canon) ==
    ([ $topology[0].woocommerce_normal_option_topology.pre_update_option[], $topology[0].yoast_normal_option_topology.pre_update_option[] | {priority, args:.accepted_args, callback} ] | canon) and
  ([ $actual[] | select(.hook=="update_option") | del(.hook) ] | canon) ==
    ([ $topology[0].yoast_normal_option_topology.update_option[] | {priority, args:.accepted_args, callback} ] | canon) and
  ([ $actual[] | select(.hook=="add_option") | del(.hook) ] | canon) ==
    ([ $topology[0].yoast_normal_option_topology.add_option[] | {priority, args:.accepted_args, callback} ] | canon) and
  ([ $actual[] | select(.hook=="update_option_woocommerce_permalinks") ] | canon) == [
    ($topology[0].yoast_normal_option_topology.woocommerce_permalinks |
      {hook,priority,args:.accepted_args,callback})
  ] and
  ([ $actual[] | select(.hook=="added_option") | del(.hook) ] | canon) ==
    ([ $topology[0].woocommerce_normal_option_topology.added_option[] | {priority, args:.accepted_args, callback} ] | canon) and
  ([ $actual[] | select(.hook=="pre_option") | del(.hook) ] | canon) as $pre |
  ($pre==[] or $pre==[
    ($topology[0].marker_option_topology.pre_option | {priority, args:.accepted_args, callback:.optional_callback})
  ]) and
  ([ $actual[] | select(.hook=="wp_default_autoload_value") | del(.hook) ] | canon) == [
    ($topology[0].marker_option_topology.wp_default_autoload_value | {priority, args:.accepted_args, callback})
  ] and
  ([ $actual[] | select(.hook=="pll_rewrite_rules" or .hook=="pll_modify_rewrite_rule") ] | length) == 0 and
  all($actual[]; .hook=="rewrite_rules_array" or .hook=="option_rewrite_rules" or .hook=="sanitize_option_rewrite_rules" or .hook=="generate_rewrite_rules" or .hook=="category_rewrite_rules" or .hook=="updated_option" or .hook=="pre_update_option" or .hook=="update_option" or .hook=="update_option_woocommerce_permalinks" or .hook=="add_option" or .hook=="added_option" or .hook=="pre_option" or .hook=="wp_default_autoload_value" or .hook=="pll_rewrite_rules" or .hook=="pll_modify_rewrite_rule") and
  any($actual[]; .hook=="rewrite_rules_array" and .callback=="PLL_Links_Directory::rewrite_rules" and .priority==10 and .args==1) and
  any($actual[]; .hook=="rewrite_rules_array" and .callback=="PLL_Sitemaps::rewrite_rules" and .priority==10 and .args==1)
' >/dev/null || fail "live callback topology differs from audited pins: $HOOKS"
# The printable topology above deliberately uses class::method names, which
# cannot distinguish Woo/TEC's boot singleton from a same-class foreign
# object. The native rewrite transaction restores marker effects only after
# binding these exact services, so live evidence must exercise the same
# object-identity boundary rather than treating a matching label as safe.
CALLBACK_IDENTITIES=$(wp2 eval '
$records=static function(string $hook):array{$registered=$GLOBALS["wp_filter"][$hook]??null;if($registered===null){return [];}if(!($registered instanceof WP_Hook)||!is_array($registered->callbacks??null)){throw new RuntimeException("native rewrite callback registry is malformed");}$out=[];foreach($registered->callbacks as $priority=>$atPriority){if(!is_int($priority)||!is_array($atPriority)){throw new RuntimeException("native rewrite callback priority is malformed");}foreach($atPriority as $record){if(!is_array($record)||array_keys($record)!==["function","accepted_args"]||!is_int($record["accepted_args"]??null)){throw new RuntimeException("native rewrite callback record is malformed");}$out[]=["priority"=>$priority,"function"=>$record["function"],"args"=>$record["accepted_args"]];}}return $out;};
$exact=static function(string $hook,array $expected)use($records):void{$actual=$records($hook);foreach($actual as $record){$matched=null;foreach($expected as $index=>$want){if($record["priority"]===$want[1]&&$record["args"]===$want[2]&&$record["function"]===$want[0]){$matched=$index;break;}}if($matched===null){throw new RuntimeException("native rewrite callback identity differs from source services for ".$hook);}unset($expected[$matched]);}if($expected!==[]){throw new RuntimeException("native rewrite callback identity is incomplete for ".$hook);}};
if(!function_exists("wc_get_container")||!array_key_exists("wc_container",$GLOBALS)){throw new RuntimeException("WooCommerce container is unavailable");}
$container=$GLOBALS["wc_container"];
if(!is_object($container)||get_class($container)!=="Automattic\\WooCommerce\\Container"||wc_get_container()!==$container){throw new RuntimeException("WooCommerce container identity differs from normal boot");}
$containerProperty=new ReflectionProperty("Automattic\\WooCommerce\\Container","container");
$runtime=$containerProperty->getValue($container);
if(!is_object($runtime)||get_class($runtime)!=="Automattic\\WooCommerce\\Internal\\DependencyManagement\\RuntimeContainer"){throw new RuntimeException("WooCommerce runtime container identity differs from normal boot");}
$cacheProperty=new ReflectionProperty("Automattic\\WooCommerce\\Internal\\DependencyManagement\\RuntimeContainer","resolved_cache");
$resolvedCache=$cacheProperty->getValue($runtime);
if(!is_array($resolvedCache)){throw new RuntimeException("WooCommerce runtime resolved cache is unavailable");}
$services=[
  "Automattic\\WooCommerce\\Internal\\Features\\FeaturesController"=>$resolvedCache["Automattic\\WooCommerce\\Internal\\Features\\FeaturesController"]??null,
  "Automattic\\WooCommerce\\Internal\\DataStores\\Orders\\DataSynchronizer"=>$resolvedCache["Automattic\\WooCommerce\\Internal\\DataStores\\Orders\\DataSynchronizer"]??null,
  "Automattic\\WooCommerce\\Internal\\DataStores\\Orders\\CustomOrdersTableController"=>$resolvedCache["Automattic\\WooCommerce\\Internal\\DataStores\\Orders\\CustomOrdersTableController"]??null,
];
foreach($services as $class=>$service){if(!array_key_exists($class,$resolvedCache)||!is_object($service)||get_class($service)!==$class){throw new RuntimeException("WooCommerce option service identity differs from normal boot");}}
$features=$services["Automattic\\WooCommerce\\Internal\\Features\\FeaturesController"];
$synchronizer=$services["Automattic\\WooCommerce\\Internal\\DataStores\\Orders\\DataSynchronizer"];
$customOrders=$services["Automattic\\WooCommerce\\Internal\\DataStores\\Orders\\CustomOrdersTableController"];
$rewrite=$GLOBALS["wp_rewrite"]??null;
if(!is_object($rewrite)||!class_exists("Yoast_Dynamic_Rewrites")||!is_callable(["Yoast_Dynamic_Rewrites","instance"])){throw new RuntimeException("Yoast dynamic rewrite singleton is unavailable");}
// The Yoast resolver registers both rewrite callbacks when its slot is empty;
// observe that private slot first so this verifier cannot manufacture a pass.
$yoastSlot=new ReflectionProperty("Yoast_Dynamic_Rewrites","instance");
$registeredYoast=$yoastSlot->getValue();
if(!is_object($registeredYoast)){throw new RuntimeException("Yoast dynamic rewrite singleton was not registered by normal boot");}
$yoast=Yoast_Dynamic_Rewrites::instance();
if($yoast!==$registeredYoast||get_class($yoast)!=="Yoast_Dynamic_Rewrites"||!property_exists($yoast,"wp_rewrite")||$yoast->wp_rewrite!==$rewrite){throw new RuntimeException("Yoast dynamic rewrite singleton differs from the canonical WordPress rewrite runtime");}
$exact("option_rewrite_rules",[[[$yoast,"filter_rewrite_rules_option"],10,1]]);
$exact("sanitize_option_rewrite_rules",[[[$yoast,"sanitize_rewrite_rules_option"],10,1]]);
$wpseoRewrite=$GLOBALS["wpseo_rewrite"]??null;
if(!is_object($wpseoRewrite)||get_class($wpseoRewrite)!=="WPSEO_Rewrite"){throw new RuntimeException("Yoast category rewrite singleton differs from normal boot");}
$categoryYoast=array_values(array_filter($records("category_rewrite_rules"),static fn(array $record):bool=>$record["priority"]===10&&$record["args"]===1&&$record["function"]===[$wpseoRewrite,"category_rewrite_rules_wrapper"]));
if(count($categoryYoast)!==1){throw new RuntimeException("Yoast category rewrite callback differs from the exact normal rewrite singleton");}
if(!class_exists("WPSEO_Options")||!is_callable(["WPSEO_Options","get_option_instance"])){throw new RuntimeException("Yoast options manager is unavailable");}
$yoastValues=(new ReflectionProperty("WPSEO_Options","option_values"))->getValue();
if(!is_array($yoastValues)||!array_key_exists("stripcategorybase",$yoastValues)||$yoastValues["stripcategorybase"]!==false){throw new RuntimeException("Yoast category rewrite policy is not the exact primed pass-through state");}
$sitemaps=$GLOBALS["wpseo_sitemaps"]??null;
$sitemapsCache=is_object($sitemaps)?($sitemaps->cache??null):null;
if(!is_object($sitemaps)||get_class($sitemaps)!=="WPSEO_Sitemaps"||!is_object($sitemapsCache)||get_class($sitemapsCache)!=="WPSEO_Sitemaps_Cache"){throw new RuntimeException("Yoast sitemap global/cache identity differs from normal boot");}
$yoastOptions=["wpseo"=>"WPSEO_Option_Wpseo","wpseo_titles"=>"WPSEO_Option_Titles","wpseo_social"=>"WPSEO_Option_Social","wpseo_taxonomy_meta"=>"WPSEO_Taxonomy_Meta","wpseo_llmstxt"=>"WPSEO_Option_Llmstxt","wpseo_tracking_only"=>"WPSEO_Option_Tracking_Only"];
$yoastPre=[];$yoastGeneric=[];
foreach($yoastOptions as $optionName=>$class){
  // Resolve only the already-registered option service. `get_instance()` can
  // construct missing services, while this evidence must witness normal boot.
  $instance=WPSEO_Options::get_option_instance($optionName);
  if(!is_object($instance)||get_class($instance)!==$class){throw new RuntimeException("Yoast option singleton identity differs from normal boot for ".$optionName);}
  $yoastPre[]=[[$instance,"add_default_filters_if_not_changed"],PHP_INT_MAX,3];
  $yoastGeneric[]=[[$instance,"add_default_filters_if_same_option"],10,1];
}
if(!class_exists("WPSEO_Sitemaps_Cache")||!is_callable(["WPSEO_Sitemaps_Cache","clear_on_option_update"])){throw new RuntimeException("Yoast sitemap cache callback is unavailable");}
$yoastSitemap=[["WPSEO_Sitemaps_Cache","clear_on_option_update"],10,1];
if(!class_exists("Tribe__Cache_Listener")||!class_exists("Tribe__Settings_Manager")||!class_exists("Tribe__Events__Aggregator")||!function_exists("tribe")){throw new RuntimeException("The Events Calendar option services are unavailable");}
$listener=Tribe__Cache_Listener::instance();$manager=Tribe__Settings_Manager::instance();$aggregator=Tribe__Events__Aggregator::instance();$views=tribe("Tribe\\Events\\Views\\V2\\Hooks");
foreach([[$listener,"Tribe__Cache_Listener"],[$manager,"Tribe__Settings_Manager"],[$aggregator,"Tribe__Events__Aggregator"],[$views,"Tribe\\Events\\Views\\V2\\Hooks"]]as[$service,$class]){if(!is_object($service)||get_class($service)!==$class){throw new RuntimeException("The Events Calendar option service identity differs from normal boot");}}
if(!class_exists("Tribe__Events__Rewrite")||!is_callable(["Tribe__Events__Rewrite","instance"])){throw new RuntimeException("The Events Calendar rewrite singleton is unavailable");}
// The public inherited slot must likewise have been populated by TEC normal
// boot; the resolver below is only safe after that zero-construction witness.
$registeredTecRewrite=Tribe__Events__Rewrite::$instance;
if(!is_object($registeredTecRewrite)){throw new RuntimeException("The Events Calendar rewrite singleton was not registered by normal boot");}
$tecRewrite=Tribe__Events__Rewrite::instance();
if($tecRewrite!==$registeredTecRewrite||get_class($tecRewrite)!=="Tribe__Events__Rewrite"){throw new RuntimeException("The Events Calendar rewrite singleton differs from normal boot");}
$listenerGeneration=array_values(array_filter($records("generate_rewrite_rules"),static fn(array $record):bool=>$record["priority"]===10&&$record["args"]===1&&$record["function"]===[$listener,"generate_rewrite_rules"]));
if(count($listenerGeneration)!==1){throw new RuntimeException("The Events Calendar rewrite-generation callback differs from the cache-listener singleton");}
$tecGeneration=array_values(array_filter($records("generate_rewrite_rules"),static fn(array $record):bool=>$record["priority"]===10&&$record["args"]===1&&$record["function"]===[$tecRewrite,"filter_generate"]));
if(count($tecGeneration)!==1){throw new RuntimeException("The Events Calendar rewrite callback differs from the exact TEC event rewrite singleton");}
$tecRules=array_values(array_filter($records("rewrite_rules_array"),static fn(array $record):bool=>$record["priority"]===25&&$record["args"]===1&&$record["function"]===[$tecRewrite,"filter_rewrite_rules_array"]));
if(count($tecRules)!==1){throw new RuntimeException("The Events Calendar rewrite callback differs from the exact TEC event rewrite singleton");}
$exact("updated_option",[[[$manager,"update_options_cache"],10,3],[[$listener,"update_last_updated_option"],10,3],[[$listener,"update_last_save_post"],10,3],[[$aggregator,"action_purge_transients"],10,1],[[$views,"action_save_wplang"],10,3],[[$features,"process_updated_option"],999,3],[[$synchronizer,"process_updated_option"],999,3],[[$customOrders,"process_updated_option"],999,3],[[$customOrders,"process_updated_option_fts_index"],999,3]]);
$exact("pre_update_option",array_merge([[[$customOrders,"process_pre_update_option"],999,3]],$yoastPre));
$exact("update_option",array_merge($yoastGeneric,[$yoastSitemap]));
$exact("add_option",$yoastGeneric);
$exact("added_option",[[[$features,"process_added_option"],999,3],[[$synchronizer,"process_added_option"],999,2]]);
$pre=$records("pre_option");if($pre!==[]){if(!function_exists("tribe")){throw new RuntimeException("Harbor option callback has no container resolver");}$harbor=tribe("TEC\\Common\\Integrations\\Harbor\\PUE");if(!is_object($harbor)||get_class($harbor)!=="TEC\\Common\\Integrations\\Harbor\\PUE"){throw new RuntimeException("Harbor option service identity differs from normal boot");}$exact("pre_option",[[[$harbor,"filter_pre_get_option"],10,3]]);}
$exact("wp_default_autoload_value",[["wp_filter_default_autoload_value_via_option_size",5,4]]);
echo wp_json_encode(["callbacks"=>"exact-singletons"]);
' | tail -1) || fail 'could not bind live callback services to their source singletons'
echo "$CALLBACK_IDENTITIES" | jq -e '.callbacks=="exact-singletons"' >/dev/null \
  || fail "live callback singleton witness is malformed: $CALLBACK_IDENTITIES"
# Polylang derives its `{$type}_rewrite_rules` hooks at `wp_loaded`; checking
# only the static hook names above would let a same-class foreign callback or
# a missing dynamic type evade the artifact topology. Its two extension
# filters must be empty before its own type resolver is safe to call.
PLL_DYNAMIC=$(wp2 eval '
$records=static function($hook):array{$out=[];foreach(($GLOBALS["wp_filter"][$hook]->callbacks??[])as $priority=>$set){foreach($set as $entry){$out[]=["priority"=>(int)$priority,"args"=>(int)($entry["accepted_args"]??0),"function"=>$entry["function"]??null];}}return $out;};
$polylang=function_exists("PLL")?PLL():null;
$links=is_object($polylang)?($polylang->links_model??null):null;
$sitemaps=is_object($polylang)?($polylang->sitemaps??null):null;
if(!is_object($polylang)||get_class($polylang)!=="PLL_Admin"||!array_key_exists("polylang",$GLOBALS)||$GLOBALS["polylang"]!==$polylang){throw new RuntimeException("Polylang runtime identity differs from normal boot");}
if(!is_object($links)||get_class($links)!=="PLL_Links_Directory"){throw new RuntimeException("Polylang directory links model is unavailable");}
if(!is_object($sitemaps)||get_class($sitemaps)!=="PLL_Sitemaps"){throw new RuntimeException("Polylang sitemap service is unavailable");}
if($records("pll_rewrite_rules")!==[]||$records("pll_modify_rewrite_rule")!==[]){throw new RuntimeException("Polylang extension filter chain is not empty");}
$static=$records("rewrite_rules_array");
$staticMatches=array_values(array_filter($static,static fn(array $record):bool=>$record["priority"]===10&&$record["args"]===1&&$record["function"]===[$links,"rewrite_rules"]));
if(count($staticMatches)!==1){throw new RuntimeException("Polylang static rewrite callback is not the directory links model");}
$sitemapMatches=array_values(array_filter($static,static fn(array $record):bool=>$record["priority"]===10&&$record["args"]===1&&$record["function"]===[$sitemaps,"rewrite_rules"]));
if(count($sitemapMatches)!==1){throw new RuntimeException("Polylang sitemap rewrite callback is not the runtime-owned sitemap service");}
$types=$links->get_rewrite_rules_filters();
if(!is_array($types)||$types===[]||!array_is_list($types)||$types!==array_values(array_unique($types))){throw new RuntimeException("Polylang rewrite type inventory is malformed");}
$dynamic=[];
foreach($types as $type){if(!is_string($type)||preg_match("/^[a-z0-9_]{1,191}$/D",$type)!==1){throw new RuntimeException("Polylang rewrite type is malformed");}$hook=$type."_rewrite_rules";$current=$records($hook);$polylangMatches=array_values(array_filter($current,static fn(array $record):bool=>$record["priority"]===10&&$record["args"]===1&&$record["function"]===[$links,"rewrite_rules"]));$expectedCount=$type==="category"?2:1;if(count($current)!==$expectedCount||count($polylangMatches)!==1){throw new RuntimeException("Polylang dynamic rewrite callback differs from the directory links model");}$dynamic[]=$hook;}
if(!in_array("category",$types,true)){throw new RuntimeException("Polylang category rewrite type is absent from the normal roster");}
echo wp_json_encode(["runtime"=>get_class($polylang),"links_model"=>get_class($links),"sitemaps"=>get_class($sitemaps),"types"=>$types,"hooks"=>$dynamic]);
' | tail -1) || fail 'could not verify Polylang dynamic rewrite callback identity'
echo "$PLL_DYNAMIC" | jq -e '
  .runtime=="PLL_Admin" and .links_model=="PLL_Links_Directory" and .sitemaps=="PLL_Sitemaps" and (.types|type=="array" and length>0) and
  (.hooks==[.types[]+"_rewrite_rules"])
' >/dev/null || fail "Polylang dynamic rewrite topology is malformed: $PLL_DYNAMIC"
RULES=$(wp2 eval '
global $wpdb;$raw=$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s LIMIT 1","rewrite_rules"));$durable=is_string($raw)?maybe_unserialize($raw):null;$effective=get_option("rewrite_rules");$h=static fn($x)=>hash("sha256",is_array($x)?wp_json_encode($x):(string)$x);echo wp_json_encode(["durable"=>is_array($durable),"effective"=>is_array($effective),"durable_sha256"=>$h($durable),"effective_sha256"=>$h($effective)]);
' | tail -1)
echo "$RULES" | jq -e '.durable and .effective and .durable_sha256!=.effective_sha256' >/dev/null || fail "Yoast durable/effective projection did not differ: $RULES"
MARKERS=$(witness 2)
echo "$MARKERS" | jq -e '.rules!=null and .generate!=null and .save!=null and .updated!=null' >/dev/null || fail "TEC marker witness is incomplete: $MARKERS"
pass 'real callback identities, Yoast durable/effective rules, and TEC marker rows match the topology fixture'

say 'hostile clean_url refusal must restore the captured product route, then retry'
wp1 eval '
$value=get_option("woocommerce_permalinks");
if(!is_array($value)||array_keys($value)!==["product_base","category_base","tag_base","attribute_base","use_verbose_page_rules"]){throw new RuntimeException("five-key source witness changed");}
$value["product_base"]="catalogue/%product_cat%";update_option("woocommerce_permalinks",$value);
' >/dev/null
# A real Permalinks settings save flushes after Woo persists this option. The
# direct CLI mutation above deliberately bypasses that admin request, so cross
# the same native regeneration boundary before treating the source as valid.
wp1 rewrite flush --hard >/dev/null
wp1 wprism capture --repo=/siterepo >/dev/null
bash bin/pair.sh repo-host "$PAIR" 1 >/dev/null
git -C "$R1" -c user.name=wprism-woo-rewrite -c user.email=woo-rewrite@example.test add -A
git -C "$R1" -c user.name=wprism-woo-rewrite -c user.email=woo-rewrite@example.test commit -qm 'capture: Woo product route change for retry'
git -C "$R1" push -q origin main
git -C "$R2" pull -q origin main
REVISION=$(git -C "$R2" rev-parse HEAD)
SOURCE=$(witness 1)
echo "$SOURCE" | jq -e '
  .woo!=null and (.woo.id|type=="number") and (.woo.autoload|type=="string") and
  (.woo.value_base64|type=="string" and length>0) and
  .woo.keys==["product_base","category_base","tag_base","attribute_base","use_verbose_page_rules"] and
  (.woo.value|type=="object" and keys_unsorted==["product_base","category_base","tag_base","attribute_base","use_verbose_page_rules"])
' >/dev/null || fail "source Woo raw five-key witness is not exact: $SOURCE"
SOURCE_ROUTE=$(product_route 1)
echo "$SOURCE_ROUTE" | jq -e '.language=="en" and (.path|startswith("/en/catalogue/")) and (.path|endswith("/rewrite-coinstall-product/")) and .http_status==200 and .single_product==true and .postid==.id' >/dev/null || fail "source route does not exercise the directory-mode Woo grammar: $SOURCE_ROUTE"
BEFORE=$(witness 2)
echo "$BEFORE" | jq -e '.woo!=null and (.woo.id|type=="number")' >/dev/null || fail "target lacks a pre-existing Woo option identity: $BEFORE"
install_hostile_mu wprism-woo-rewrite-hostile.php <<'PHP'
<?php
add_filter("clean_url", static fn($url) => $url, 10, 3);
PHP
set +e
FAILED=$(wp2 wprism apply --repo=/siterepo --adopt-by-slug=posts,terms --default-author=admin --revision="$REVISION" 2>&1)
RC=$?
set -e
[ "$RC" -ne 0 ] || fail "hostile clean_url unexpectedly allowed apply: $FAILED"
grep -Fq 'extension callback' <<<"$FAILED" || fail "hostile refusal did not identify the closed hook: $FAILED"
AFTER_FAILED=$(witness 2)
[ "$AFTER_FAILED" = "$BEFORE" ] || fail "failed apply changed permalink/Woo/rewrite/TEC witnesses
before=$BEFORE
after=$AFTER_FAILED"
remove_hostile_mu wprism-woo-rewrite-hostile.php
RETRY=$(wp2 wprism apply --repo=/siterepo --adopt-by-slug=posts,terms --default-author=admin --revision="$REVISION" --format=json | tail -1) || fail 'retry failed'
echo "$RETRY" | jq -e '.canary=="clean" and (.actions|any(.kind=="provider" and .source=="provider:woocommerce-hierarchy-lookups/rebuild_product_permalink_routes" and .verified==true))' >/dev/null || fail "retry receipt missing: $RETRY"
AFTER_RETRY=$(witness 2)
echo "$AFTER_RETRY" | jq -e --argjson source "$SOURCE" --argjson before "$BEFORE" '
  .permalink!=null and .woo!=null and .rules!=null and .generate!=null and .save!=null and .updated!=null and
  .woo.id==$before.woo.id and .woo.value_base64==$source.woo.value_base64 and
  .woo.autoload==$source.woo.autoload and .woo.keys==$source.woo.keys and .woo.value==$source.woo.value
' >/dev/null || fail "retry did not preserve the target row identity while materializing the exact source Woo row: $AFTER_RETRY"
[ "$AFTER_RETRY" != "$BEFORE" ] || fail 'retry reported success without changing product-route evidence'
TARGET_ROUTE=$(product_route 2)
echo "$TARGET_ROUTE" | jq -e --argjson source "$SOURCE_ROUTE" '
  .language=="en" and .path==$source.path and .http_status==200 and .single_product==true and .postid==.id and
  (.path|startswith("/en/catalogue/")) and (.path|endswith("/rewrite-coinstall-product/"))
' >/dev/null || fail "retry did not generate and resolve the exact directory-mode product route: $TARGET_ROUTE"
pass 'closed sanitizer refusal restored raw witnesses; retry retained target row identity, copied the exact Woo row, and resolved the Polylang directory product route'

say 'a third-party Polylang dynamic rewrite callback must refuse without an unreceipted generation, then retry'
wp1 eval '
$value=get_option("woocommerce_permalinks");
if(!is_array($value)||array_keys($value)!==["product_base","category_base","tag_base","attribute_base","use_verbose_page_rules"]){throw new RuntimeException("five-key source witness changed before Polylang refusal");}
$value["product_base"]="atelier/%product_cat%";
update_option("woocommerce_permalinks",$value);
' >/dev/null
# Keep the second authored source coherent for the real HTTP witness too; an
# earlier successful request cannot make its old catalogue rules authoritative
# for the newly-authored atelier base.
wp1 rewrite flush --hard >/dev/null
wp1 wprism capture --repo=/siterepo >/dev/null
bash bin/pair.sh repo-host "$PAIR" 1 >/dev/null
git -C "$R1" -c user.name=wprism-woo-rewrite -c user.email=woo-rewrite@example.test add -A
git -C "$R1" -c user.name=wprism-woo-rewrite -c user.email=woo-rewrite@example.test commit -qm 'capture: Woo product route Polylang dynamic refusal'
git -C "$R1" push -q origin main
git -C "$R2" pull -q origin main
REVISION=$(git -C "$R2" rev-parse HEAD)
SOURCE_POLY=$(witness 1)
SOURCE_ROUTE_POLY=$(product_route 1)
echo "$SOURCE_ROUTE_POLY" | jq -e '.language=="en" and (.path|startswith("/en/atelier/")) and .http_status==200 and .single_product==true and .postid==.id' >/dev/null || fail "source route did not reach the next Polylang directory grammar: $SOURCE_ROUTE_POLY"
BEFORE_POLY=$(witness 2)
install_hostile_mu wprism-woo-polylang-dynamic-hostile.php <<'PHP'
<?php
add_filter("pll_modify_rewrite_rule", static function (bool $modify, array $rule, string $type, string|false $archive): bool {
    return $modify;
}, 10, 4);
PHP
set +e
FAILED_POLY=$(wp2 wprism apply --repo=/siterepo --adopt-by-slug=posts,terms --default-author=admin --revision="$REVISION" 2>&1)
RC=$?
set -e
[ "$RC" -ne 0 ] || fail "third-party Polylang dynamic callback unexpectedly allowed apply: $FAILED_POLY"
grep -Fq "apply refused before target mutation — native action 'rewrite.flush' runtime is unsupported" <<<"$FAILED_POLY" \
  && grep -Fq "unsupported open Polylang rewrite filter" <<<"$FAILED_POLY" \
  && ! grep -Fq "required manifest action 'provider:woocommerce-hierarchy-lookups/rebuild_product_permalink_routes' failed" <<<"$FAILED_POLY" \
  || fail "Polylang dynamic refusal did not stop at the pre-mutation rewrite preflight: $FAILED_POLY"
AFTER_POLY_FAILED=$(witness 2)
[ "$AFTER_POLY_FAILED" = "$BEFORE_POLY" ] || fail "third-party Polylang refusal changed permalink/Woo/rewrite/TEC witnesses
before=$BEFORE_POLY
after=$AFTER_POLY_FAILED"
remove_hostile_mu wprism-woo-polylang-dynamic-hostile.php
RETRY_POLY=$(wp2 wprism apply --repo=/siterepo --adopt-by-slug=posts,terms --default-author=admin --revision="$REVISION" --format=json | tail -1) || fail 'Polylang dynamic retry failed'
echo "$RETRY_POLY" | jq -e '.canary=="clean" and (.actions|any(.kind=="provider" and .source=="provider:woocommerce-hierarchy-lookups/rebuild_product_permalink_routes" and .verified==true))' >/dev/null || fail "Polylang dynamic retry receipt missing: $RETRY_POLY"
AFTER_POLY_RETRY=$(witness 2)
echo "$AFTER_POLY_RETRY" | jq -e --argjson source "$SOURCE_POLY" --argjson before "$BEFORE_POLY" '
  .woo!=null and .woo.id==$before.woo.id and .woo.value_base64==$source.woo.value_base64 and
  .woo.autoload==$source.woo.autoload and .woo.keys==$source.woo.keys and .woo.value==$source.woo.value
' >/dev/null || fail "Polylang dynamic retry did not preserve target row identity and copy the exact source Woo row: $AFTER_POLY_RETRY"
TARGET_ROUTE_POLY=$(product_route 2)
echo "$TARGET_ROUTE_POLY" | jq -e --argjson source "$SOURCE_ROUTE_POLY" '
  .language=="en" and .path==$source.path and .http_status==200 and .single_product==true and .postid==.id and (.path|startswith("/en/atelier/"))
' >/dev/null || fail "Polylang dynamic retry did not regenerate the exact directory product route: $TARGET_ROUTE_POLY"
pass 'third-party Polylang dynamic callback refused before generation; retry copied the exact raw Woo witness and resolved the regenerated directory route'

GREEN=1
printf '
WOO_REWRITE_COINSTALL PASSED
'
