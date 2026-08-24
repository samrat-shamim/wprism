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
$want=["rewrite_rules_array","option_rewrite_rules","sanitize_option_rewrite_rules","generate_rewrite_rules","updated_option","pre_option","wp_default_autoload_value","pre_wp_load_alloptions","pre_cache_alloptions","alloptions","pre_update_option","update_option","wp_autoload_values_to_autoload","wp_max_autoloaded_option_size","add_option","added_option"];
foreach($markers as $name){foreach(["sanitize_option_","pre_option_","default_option_","option_","pre_update_option_","update_option_","add_option_"] as $prefix){$want[]=$prefix.$name;}}
$rows=[];
foreach($want as $hook){foreach(($GLOBALS["wp_filter"][$hook]->callbacks??[])as $priority=>$set){foreach($set as $entry){$f=$entry["function"]??null;if(is_string($f)){$name=$f;}elseif(is_array($f)&&isset($f[0],$f[1])){$name=(is_object($f[0])?get_class($f[0]):$f[0])."::".$f[1];}else{continue;}$rows[]=["hook"=>$hook,"priority"=>(int)$priority,"args"=>(int)($entry["accepted_args"]??0),"callback"=>$name];}}}echo wp_json_encode($rows);
' | tail -1)
echo "$HOOKS" | jq -e --slurpfile topology "$TOPOLOGY" '
  def canon: sort_by(.priority,.args,.callback);
  . as $actual |
  ($topology[0].static_callbacks | all(. as $want |
    any($actual[]; .hook==$want.hook and .callback==$want.callback and .priority==$want.priority and .args==$want.accepted_args)
  )) and
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
  all($actual[]; .hook=="rewrite_rules_array" or .hook=="option_rewrite_rules" or .hook=="sanitize_option_rewrite_rules" or .hook=="generate_rewrite_rules" or .hook=="updated_option" or .hook=="pre_update_option" or .hook=="added_option" or .hook=="pre_option" or .hook=="wp_default_autoload_value") and
  any($actual[]; .hook=="rewrite_rules_array" and .callback=="PLL_Links_Directory::rewrite_rules" and .priority==10 and .args==1)
' >/dev/null || fail "live callback topology differs from audited pins: $HOOKS"
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

GREEN=1
printf '
WOO_REWRITE_COINSTALL PASSED
'
