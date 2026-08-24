#!/usr/bin/env bash
# Candidate-bound mixed Woo rewrite evidence: only the product-route provider,
# its exact active artifacts, hook topology, and hostile refusal/retry.
set -euo pipefail
cd "$(dirname "$0")/../.."

say() { printf '\n== %s ==\n' "$*"; }
pass() { printf 'ok: %s\n' "$*"; }
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }

PAIR="${WOO_REWRITE_COINSTALL_PAIR:-woorewrite}"
PORT1="${WOO_REWRITE_COINSTALL_PORT1:-8978}"
PORT2="${WOO_REWRITE_COINSTALL_PORT2:-8979}"
EXPECTED_SHA="${DUO_EXPECTED_SOURCE_SHA:-}"
ROOT="$(cd .. && pwd -P)"
HEAD="$(git -C "$ROOT" rev-parse HEAD)"
[ -n "$EXPECTED_SHA" ] || fail 'DUO_EXPECTED_SOURCE_SHA is required'
[ "$EXPECTED_SHA" = "$HEAD" ] || fail "expected candidate $EXPECTED_SHA, checkout is $HEAD"
[[ "$PAIR" =~ ^[a-z][a-z0-9]*$ ]] || fail 'invalid pair name'
[[ "$PORT1" =~ ^[0-9]{4,5}$ && "$PORT2" =~ ^[0-9]{4,5}$ ]] || fail 'invalid pair ports'
command -v jq >/dev/null || fail 'jq is required'

export DUO_SOURCE_ROOT="$ROOT" DUO_EXPECTED_SOURCE_SHA="$EXPECTED_SHA" DUO_PAIR="$PAIR"
COMPOSE=(docker compose -p "duo-$PAIR" -f pair.yml -f pair.artifacts.yml)
wp1() { "${COMPOSE[@]}" run --rm -T cli1 wp "$@"; }
wp2() { "${COMPOSE[@]}" run --rm -T cli2 wp "$@"; }
R1="siterepo/$PAIR""1"
R2="siterepo/$PAIR""2"
ORIGIN="siterepo/origin-$PAIR.git"
TOPOLOGY="tests/fixtures/woocommerce-rewrite-coinstall-topology.json"
. bin/fetch-artifact.sh

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
  local side="$1"
  "wp$side" eval '
$product=get_page_by_path("rewrite-coinstall-product",OBJECT,"product");
if(!$product){throw new RuntimeException("product route witness is absent");}
$url=get_permalink($product);$path=wp_parse_url($url,PHP_URL_PATH);
if(!is_string($path)||$path===""){throw new RuntimeException("product permalink has no path");}
$language=function_exists("pll_get_post_language")?pll_get_post_language((int)$product->ID,"slug"):null;
echo wp_json_encode(["id"=>(int)$product->ID,"language"=>$language,"path"=>$path,"resolved"=>(int)url_to_postid(home_url($path))]);
' | tail -1
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
    actual=$("wp$side" eval '
$relative=$args[0]??"";$path=WP_PLUGIN_DIR."/".$relative;
if(!is_file($path)){throw new RuntimeException("audited plugin source file is absent: ".$relative);}
echo hash_file("sha256",$path);
' -- "$root/$relative" | tail -1) || fail "could not hash audited $plugin source file: $relative"
    [ "$actual" = "$expected" ] \
      || fail "installed $plugin source hash differs from topology fixture for $relative: $actual"
  done < <(jq -r '.source_files[] | [.plugin,.path,.sha256] | @tsv' "$TOPOLOGY")
}

say "candidate/source preflight: $HEAD"
jq -e '.format=="duo-woocommerce-rewrite-coinstall-topology/v1" and .artifacts.woocommerce.version=="11.0.1" and .artifacts["wordpress-seo"].version=="28.3" and .artifacts.polylang.version=="3.8.6" and .artifacts["the-events-calendar"].version=="6.17.2" and (.source_files|length==16) and (.static_callbacks|length==18) and (.marker_option_topology.updated_option|length==5) and (.woocommerce_normal_option_topology.updated_option|length==4) and (.woocommerce_normal_option_topology.pre_update_option|length==1) and (.woocommerce_normal_option_topology.added_option|length==2) and .marker_option_topology.pre_option.optional_callback=="TEC\\Common\\Integrations\\Harbor\\PUE::filter_pre_get_option" and .marker_option_topology.wp_default_autoload_value.callback=="wp_filter_default_autoload_value_via_option_size" and (.dynamic_callback_containers|any(.hook=="pll_modify_rewrite_rule" and .accepted_args==4))' "$TOPOLOGY" >/dev/null || fail 'co-install topology fixture is not exact'
validate_artifact_lock conformance/artifacts.lock.json
jq -e --slurpfile lock conformance/artifacts.lock.json '(.artifacts | to_entries | all(. as $artifact | $lock[0].plugins[$artifact.key][$artifact.value.version].sha256 == $artifact.value.sha256))' "$TOPOLOGY" >/dev/null || fail 'co-install artifact hashes differ from the artifact lock'
pass 'candidate, artifact hashes, and audited topology are pinned'

say "fresh exact co-install pair $PAIR"
bash bin/pair.sh reset "$PAIR"
bash bin/pair.sh up "$PAIR" "$PORT1" "$PORT2" --artifacts --headless
for side in 1 2; do
  install "$side" woocommerce 11.0.1
  install "$side" wordpress-seo 28.3
  install "$side" polylang 3.8.6
  install "$side" the-events-calendar 6.17.2
  "wp$side" site empty --yes >/dev/null
  "wp$side" wc hpos enable >/dev/null
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
update_option("woocommerce_permalinks",["product_base"=>"store/%product_cat%","category_base"=>"catalog","attribute_base"=>"feature","tag_base"=>"label","use_verbose_page_rules"=>true]);
$lang=PLL()->model->add_language(["locale"=>"en_US","name"=>"English","slug"=>"en","rtl"=>false,"term_group"=>0,"no_default_cat"=>true]);
if(is_wp_error($lang)||!$lang instanceof PLL_Language){throw new RuntimeException("could not create the Polylang language");}
$product=wp_insert_post(["post_type"=>"product","post_status"=>"publish","post_title"=>"Rewrite Co-install Product","post_name"=>"rewrite-coinstall-product"],true);
if(is_wp_error($product)||!$product){throw new RuntimeException("could not create product");}
pll_set_post_language((int)$product,"en");
' >/dev/null
cat > "$R1/site.duo.json" <<'EOF'
{
  "manifests": ["core", "woocommerce", "yoast", "polylang", "the-events-calendar"],
  "policy": {
    "options": {},
    "post_meta": {},
    "post_types": ["post", "page", "attachment", "product", "product_variation", "shop_coupon", "tribe_events"],
    "taxonomies": ["category", "post_tag", "product_cat", "product_shipping_class", "product_tag", "product_type", "language", "term_language", "term_translations", "post_translations"]
  },
  "spec_version": 2
}
EOF
cp site-repo.gitignore.template "$R1/.gitignore"
git -C "$R1" init -q -b main
git -C "$R1" remote add origin "../origin-$PAIR.git"
wp1 duo capture --repo=/siterepo >/dev/null
bash bin/pair.sh repo-host "$PAIR" 1 >/dev/null
git -C "$R1" -c user.name=duo-woo-rewrite -c user.email=woo-rewrite@example.test add -A
git -C "$R1" -c user.name=duo-woo-rewrite -c user.email=woo-rewrite@example.test commit -qm 'capture: Woo rewrite co-install baseline'
git -C "$R1" push -qu origin main
[ -z "$(find "$R2" -mindepth 1 -maxdepth 1 -print -quit)" ] \
  || fail "pair reset did not leave the exact target repository root empty: $R2"
git clone -q "$ORIGIN" "$R2"
bash bin/pair.sh repo-host "$PAIR" 2 >/dev/null
REVISION=$(git -C "$R2" rev-parse HEAD)
INITIAL=$(wp2 duo apply --repo=/siterepo --adopt-by-slug=posts,terms --default-author=admin --revision="$REVISION" --format=json | tail -1) || fail 'initial co-install apply failed'
echo "$INITIAL" | jq -e '.canary=="clean" and (.actions|any(.kind=="provider" and .source=="provider:woocommerce-hierarchy-lookups/rebuild_product_permalink_routes" and .verified==true))' >/dev/null || fail "initial product-route receipt missing: $INITIAL"
pass 'normal apply invoked the verified product-route provider'

say 'inspect the actual co-install callback identities and effects'
HOOKS=$(wp2 eval '
$markers=["tribe_last_generate_rewrite_rules","tribe_last_updated_option","tribe_last_save_post"];
$want=["rewrite_rules_array","option_rewrite_rules","sanitize_option_rewrite_rules","generate_rewrite_rules","updated_option","pre_option","wp_default_autoload_value","pre_wp_load_alloptions","pre_cache_alloptions","alloptions","pre_update_option","update_option","wp_autoload_values_to_autoload","wp_max_autoloaded_option_size","add_option","added_option","pll_rewrite_rules","pll_modify_rewrite_rule"];
foreach($markers as $name){foreach(["sanitize_option_","pre_option_","default_option_","option_","pre_update_option_","update_option_","add_option_"] as $prefix){$want[]=$prefix.$name;}}
$rows=[];
foreach($want as $hook){foreach(($GLOBALS["wp_filter"][$hook]->callbacks??[])as $priority=>$set){foreach($set as $entry){$f=$entry["function"]??null;if(is_string($f)){$name=$f;}elseif(is_array($f)&&isset($f[0],$f[1])){$name=(is_object($f[0])?get_class($f[0]):$f[0])."::".$f[1];}else{continue;}$rows[]=["hook"=>$hook,"priority"=>(int)$priority,"args"=>(int)($entry["accepted_args"]??0),"callback"=>$name];}}}echo wp_json_encode($rows);
' | tail -1)
echo "$HOOKS" | jq -e --slurpfile topology "$TOPOLOGY" '
  def canon: sort_by(.priority,.args,.callback);
  def static_rewrite_hook:
    .hook=="rewrite_rules_array" or .hook=="option_rewrite_rules" or .hook=="sanitize_option_rewrite_rules" or .hook=="generate_rewrite_rules";
  . as $actual |
  ([ $actual[] | select(static_rewrite_hook) ] | canon) ==
    ([ $topology[0].static_callbacks[] | select(static_rewrite_hook) |
      {hook,priority,args:.accepted_args,callback} ] | canon) and
  ([ $actual[] | select(.hook=="updated_option") | del(.hook) ] | canon) ==
    ([ $topology[0].woocommerce_normal_option_topology.updated_option[], $topology[0].marker_option_topology.updated_option[] | {priority, args:.accepted_args, callback} ] | canon) and
  ([ $actual[] | select(.hook=="pre_update_option") | del(.hook) ] | canon) ==
    ([ $topology[0].woocommerce_normal_option_topology.pre_update_option[] | {priority, args:.accepted_args, callback} ] | canon) and
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
  all($actual[]; .hook=="rewrite_rules_array" or .hook=="option_rewrite_rules" or .hook=="sanitize_option_rewrite_rules" or .hook=="generate_rewrite_rules" or .hook=="updated_option" or .hook=="pre_update_option" or .hook=="added_option" or .hook=="pre_option" or .hook=="wp_default_autoload_value" or .hook=="pll_rewrite_rules" or .hook=="pll_modify_rewrite_rule") and
  any($actual[]; .hook=="rewrite_rules_array" and .callback=="PLL_Links_Directory::rewrite_rules" and .priority==10 and .args==1)
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
$features=$container->get("Automattic\\WooCommerce\\Internal\\Features\\FeaturesController");
$synchronizer=$container->get("Automattic\\WooCommerce\\Internal\\DataStores\\Orders\\DataSynchronizer");
$customOrders=$container->get("Automattic\\WooCommerce\\Internal\\DataStores\\Orders\\CustomOrdersTableController");
foreach([[$features,"Automattic\\WooCommerce\\Internal\\Features\\FeaturesController"],[$synchronizer,"Automattic\\WooCommerce\\Internal\\DataStores\\Orders\\DataSynchronizer"],[$customOrders,"Automattic\\WooCommerce\\Internal\\DataStores\\Orders\\CustomOrdersTableController"]]as[$service,$class]){if(!is_object($service)||get_class($service)!==$class){throw new RuntimeException("WooCommerce option service identity differs from normal boot");}}
if(!class_exists("Tribe__Cache_Listener")||!class_exists("Tribe__Settings_Manager")||!class_exists("Tribe__Events__Aggregator")||!function_exists("tribe")){throw new RuntimeException("The Events Calendar option services are unavailable");}
$listener=Tribe__Cache_Listener::instance();$manager=Tribe__Settings_Manager::instance();$aggregator=Tribe__Events__Aggregator::instance();$views=tribe("Tribe\\Events\\Views\\V2\\Hooks");
foreach([[$listener,"Tribe__Cache_Listener"],[$manager,"Tribe__Settings_Manager"],[$aggregator,"Tribe__Events__Aggregator"],[$views,"Tribe\\Events\\Views\\V2\\Hooks"]]as[$service,$class]){if(!is_object($service)||get_class($service)!==$class){throw new RuntimeException("The Events Calendar option service identity differs from normal boot");}}
$exact("updated_option",[[$manager,"update_options_cache",10,3],[$listener,"update_last_updated_option",10,3],[$listener,"update_last_save_post",10,3],[$aggregator,"action_purge_transients",10,1],[$views,"action_save_wplang",10,3],[$features,"process_updated_option",999,3],[$synchronizer,"process_updated_option",999,3],[$customOrders,"process_updated_option",999,3],[$customOrders,"process_updated_option_fts_index",999,3]]);
$exact("pre_update_option",[[$customOrders,"process_pre_update_option",999,3]]);
$exact("added_option",[[$features,"process_added_option",999,3],[$synchronizer,"process_added_option",999,2]]);
$pre=$records("pre_option");if($pre!==[]){if(!function_exists("tribe")){throw new RuntimeException("Harbor option callback has no container resolver");}$harbor=tribe("TEC\\Common\\Integrations\\Harbor\\PUE");if(!is_object($harbor)||get_class($harbor)!=="TEC\\Common\\Integrations\\Harbor\\PUE"){throw new RuntimeException("Harbor option service identity differs from normal boot");}$exact("pre_option",[[$harbor,"filter_pre_get_option",10,3]]);}
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
if(!is_object($links)||get_class($links)!=="PLL_Links_Directory"){throw new RuntimeException("Polylang directory links model is unavailable");}
if($records("pll_rewrite_rules")!==[]||$records("pll_modify_rewrite_rule")!==[]){throw new RuntimeException("Polylang extension filter chain is not empty");}
$static=$records("rewrite_rules_array");
$staticMatches=array_values(array_filter($static,static fn(array $record):bool=>$record["priority"]===10&&$record["args"]===1&&$record["function"]===[$links,"rewrite_rules"]));
if(count($staticMatches)!==1){throw new RuntimeException("Polylang static rewrite callback is not the directory links model");}
$types=$links->get_rewrite_rules_filters();
if(!is_array($types)||$types===[]||!array_is_list($types)||$types!==array_values(array_unique($types))){throw new RuntimeException("Polylang rewrite type inventory is malformed");}
$dynamic=[];
foreach($types as $type){if(!is_string($type)||preg_match("/^[a-z0-9_]{1,191}$/D",$type)!==1){throw new RuntimeException("Polylang rewrite type is malformed");}$hook=$type."_rewrite_rules";$current=$records($hook);if(count($current)!==1||$current[0]["priority"]!==10||$current[0]["args"]!==1||$current[0]["function"]!==[$links,"rewrite_rules"]){throw new RuntimeException("Polylang dynamic rewrite callback differs from the directory links model");}$dynamic[]=$hook;}
echo wp_json_encode(["links_model"=>get_class($links),"types"=>$types,"hooks"=>$dynamic]);
' | tail -1) || fail 'could not verify Polylang dynamic rewrite callback identity'
echo "$PLL_DYNAMIC" | jq -e '
  .links_model=="PLL_Links_Directory" and (.types|type=="array" and length>0) and
  (.hooks==[.types[]+"_rewrite_rules"])
' >/dev/null || fail "Polylang dynamic rewrite topology is malformed: $PLL_DYNAMIC"
RULES=$(wp2 eval '
global $wpdb;$raw=$wpdb->get_var("SELECT option_value FROM {$wpdb->options} WHERE option_name="rewrite_rules" LIMIT 1");$durable=is_string($raw)?maybe_unserialize($raw):null;$effective=get_option("rewrite_rules");$h=static fn($x)=>hash("sha256",is_array($x)?wp_json_encode($x):(string)$x);echo wp_json_encode(["durable"=>is_array($durable),"effective"=>is_array($effective),"durable_sha256"=>$h($durable),"effective_sha256"=>$h($effective)]);
' | tail -1)
echo "$RULES" | jq -e '.durable and .effective and .durable_sha256!=.effective_sha256' >/dev/null || fail "Yoast durable/effective projection did not differ: $RULES"
MARKERS=$(witness 2)
echo "$MARKERS" | jq -e '.rules!=null and .generate!=null and .save!=null and .updated!=null' >/dev/null || fail "TEC marker witness is incomplete: $MARKERS"
pass 'real callback identities, Yoast durable/effective rules, and TEC marker rows match the topology fixture'

say 'hostile clean_url refusal must restore the captured product route, then retry'
wp1 eval '
$value=get_option("woocommerce_permalinks");
if(!is_array($value)||array_keys($value)!==["product_base","category_base","attribute_base","tag_base","use_verbose_page_rules"]){throw new RuntimeException("five-key source witness changed");}
$value["product_base"]="catalogue/%product_cat%";update_option("woocommerce_permalinks",$value);
' >/dev/null
wp1 duo capture --repo=/siterepo >/dev/null
bash bin/pair.sh repo-host "$PAIR" 1 >/dev/null
git -C "$R1" -c user.name=duo-woo-rewrite -c user.email=woo-rewrite@example.test add -A
git -C "$R1" -c user.name=duo-woo-rewrite -c user.email=woo-rewrite@example.test commit -qm 'capture: Woo product route change for retry'
git -C "$R1" push -q origin main
git -C "$R2" pull -q origin main
REVISION=$(git -C "$R2" rev-parse HEAD)
SOURCE=$(witness 1)
echo "$SOURCE" | jq -e '
  .woo!=null and (.woo.id|type=="number") and (.woo.autoload|type=="string") and
  (.woo.value_base64|type=="string" and length>0) and
  .woo.keys==["product_base","category_base","attribute_base","tag_base","use_verbose_page_rules"] and
  (.woo.value|type=="object" and keys_unsorted==["product_base","category_base","attribute_base","tag_base","use_verbose_page_rules"])
' >/dev/null || fail "source Woo raw five-key witness is not exact: $SOURCE"
SOURCE_ROUTE=$(product_route 1)
echo "$SOURCE_ROUTE" | jq -e '.language=="en" and (.path|startswith("/en/catalogue/")) and (.path|endswith("/rewrite-coinstall-product/")) and .resolved==.id' >/dev/null || fail "source route does not exercise the directory-mode Woo grammar: $SOURCE_ROUTE"
BEFORE=$(witness 2)
echo "$BEFORE" | jq -e '.woo!=null and (.woo.id|type=="number")' >/dev/null || fail "target lacks a pre-existing Woo option identity: $BEFORE"
wp2 eval '
$dir=WPMU_PLUGIN_DIR;if(!is_dir($dir)&&!wp_mkdir_p($dir)){throw new RuntimeException("no MU directory");}$path=$dir."/duo-woo-rewrite-hostile.php";$bytes="<?php
add_filter("clean_url",static fn($url)=>$url,10,3);
";if(file_put_contents($path,$bytes)!==strlen($bytes)){throw new RuntimeException("could not install hostile callback");}
' >/dev/null
set +e
FAILED=$(wp2 duo apply --repo=/siterepo --adopt-by-slug=posts,terms --default-author=admin --revision="$REVISION" 2>&1)
RC=$?
set -e
[ "$RC" -ne 0 ] || fail "hostile clean_url unexpectedly allowed apply: $FAILED"
grep -Fq 'extension callback' <<<"$FAILED" || fail "hostile refusal did not identify the closed hook: $FAILED"
AFTER_FAILED=$(witness 2)
[ "$AFTER_FAILED" = "$BEFORE" ] || fail "failed apply changed permalink/Woo/rewrite/TEC witnesses
before=$BEFORE
after=$AFTER_FAILED"
wp2 eval '
$path=WPMU_PLUGIN_DIR."/duo-woo-rewrite-hostile.php";if(!is_file($path)||!unlink($path)){throw new RuntimeException("could not remove hostile callback");}
' >/dev/null
RETRY=$(wp2 duo apply --repo=/siterepo --adopt-by-slug=posts,terms --default-author=admin --revision="$REVISION" --format=json | tail -1) || fail 'retry failed'
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
  .language=="en" and .path==$source.path and .resolved==.id and
  (.path|startswith("/en/catalogue/")) and (.path|endswith("/rewrite-coinstall-product/"))
' >/dev/null || fail "retry did not generate and resolve the exact directory-mode product route: $TARGET_ROUTE"
pass 'closed sanitizer refusal restored raw witnesses; retry retained target row identity, copied the exact Woo row, and resolved the Polylang directory product route'

say 'a third-party Polylang dynamic rewrite callback must refuse without an unreceipted generation, then retry'
wp1 eval '
$value=get_option("woocommerce_permalinks");
if(!is_array($value)||array_keys($value)!==["product_base","category_base","attribute_base","tag_base","use_verbose_page_rules"]){throw new RuntimeException("five-key source witness changed before Polylang refusal");}
$value["product_base"]="atelier/%product_cat%";
update_option("woocommerce_permalinks",$value);
' >/dev/null
wp1 duo capture --repo=/siterepo >/dev/null
bash bin/pair.sh repo-host "$PAIR" 1 >/dev/null
git -C "$R1" -c user.name=duo-woo-rewrite -c user.email=woo-rewrite@example.test add -A
git -C "$R1" -c user.name=duo-woo-rewrite -c user.email=woo-rewrite@example.test commit -qm 'capture: Woo product route Polylang dynamic refusal'
git -C "$R1" push -q origin main
git -C "$R2" pull -q origin main
REVISION=$(git -C "$R2" rev-parse HEAD)
SOURCE_POLY=$(witness 1)
SOURCE_ROUTE_POLY=$(product_route 1)
echo "$SOURCE_ROUTE_POLY" | jq -e '.language=="en" and (.path|startswith("/en/atelier/")) and .resolved==.id' >/dev/null || fail "source route did not reach the next Polylang directory grammar: $SOURCE_ROUTE_POLY"
BEFORE_POLY=$(witness 2)
wp2 eval '
$dir=WPMU_PLUGIN_DIR;if(!is_dir($dir)&&!wp_mkdir_p($dir)){throw new RuntimeException("no MU directory");}
$path=$dir."/duo-woo-polylang-dynamic-hostile.php";
$bytes="<?php\nadd_filter(\"pll_modify_rewrite_rule\", static function(bool \$modify, array \$rule, string \$type, string|false \$archive): bool { return \$modify; }, 10, 4);\n";
if(file_put_contents($path,$bytes)!==strlen($bytes)){throw new RuntimeException("could not install hostile Polylang callback");}
' >/dev/null
set +e
FAILED_POLY=$(wp2 duo apply --repo=/siterepo --adopt-by-slug=posts,terms --default-author=admin --revision="$REVISION" 2>&1)
RC=$?
set -e
[ "$RC" -ne 0 ] || fail "third-party Polylang dynamic callback unexpectedly allowed apply: $FAILED_POLY"
grep -Fq 'recovery_required' <<<"$FAILED_POLY" || fail "Polylang dynamic refusal was not a bounded recovery failure: $FAILED_POLY"
AFTER_POLY_FAILED=$(witness 2)
[ "$AFTER_POLY_FAILED" = "$BEFORE_POLY" ] || fail "third-party Polylang refusal changed permalink/Woo/rewrite/TEC witnesses
before=$BEFORE_POLY
after=$AFTER_POLY_FAILED"
wp2 eval '
$path=WPMU_PLUGIN_DIR."/duo-woo-polylang-dynamic-hostile.php";
if(!is_file($path)||!unlink($path)){throw new RuntimeException("could not remove hostile Polylang callback");}
' >/dev/null
RETRY_POLY=$(wp2 duo apply --repo=/siterepo --adopt-by-slug=posts,terms --default-author=admin --revision="$REVISION" --format=json | tail -1) || fail 'Polylang dynamic retry failed'
echo "$RETRY_POLY" | jq -e '.canary=="clean" and (.actions|any(.kind=="provider" and .source=="provider:woocommerce-hierarchy-lookups/rebuild_product_permalink_routes" and .verified==true))' >/dev/null || fail "Polylang dynamic retry receipt missing: $RETRY_POLY"
AFTER_POLY_RETRY=$(witness 2)
echo "$AFTER_POLY_RETRY" | jq -e --argjson source "$SOURCE_POLY" --argjson before "$BEFORE_POLY" '
  .woo!=null and .woo.id==$before.woo.id and .woo.value_base64==$source.woo.value_base64 and
  .woo.autoload==$source.woo.autoload and .woo.keys==$source.woo.keys and .woo.value==$source.woo.value
' >/dev/null || fail "Polylang dynamic retry did not preserve target row identity and copy the exact source Woo row: $AFTER_POLY_RETRY"
TARGET_ROUTE_POLY=$(product_route 2)
echo "$TARGET_ROUTE_POLY" | jq -e --argjson source "$SOURCE_ROUTE_POLY" '
  .language=="en" and .path==$source.path and .resolved==.id and (.path|startswith("/en/atelier/"))
' >/dev/null || fail "Polylang dynamic retry did not regenerate the exact directory product route: $TARGET_ROUTE_POLY"
pass 'third-party Polylang dynamic callback refused before generation; retry copied the exact raw Woo witness and resolved the regenerated directory route'

GREEN=1
printf '
WOO_REWRITE_COINSTALL PASSED
'
