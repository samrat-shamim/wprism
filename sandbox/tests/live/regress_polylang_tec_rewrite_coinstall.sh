#!/usr/bin/env bash
# Candidate-bound Polylang 3.8.6 + TEC 6.17.2 co-install evidence. It proves
# provider projection, the separate native fresh-process rewrite action, the
# closed callback topology, failure-before-effect/retry, and clean no-op.
set -euo pipefail
cd "$(dirname "$0")/../.."
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*" >&2; exit 1; }
. conformance/asserts.sh
say() { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }

PAIR="${POLYLANG_TEC_REWRITE_PAIR:-plltec}"
PORT1="${POLYLANG_TEC_REWRITE_PORT1:-9022}"
PORT2="${POLYLANG_TEC_REWRITE_PORT2:-9023}"
EXPECTED_SHA="${POLYLANG_TEC_REWRITE_EXPECTED_SOURCE_SHA:-${DUO_EXPECTED_SOURCE_SHA:-}}"
ROOT="$(cd .. && pwd -P)"; HEAD="$(git -C "$ROOT" rev-parse HEAD)"
[[ "$PAIR" =~ ^[a-z][a-z0-9]*$ ]] || fail "invalid Polylang+TEC pair '$PAIR'"
[[ "$PORT1" =~ ^[0-9]+$ && "$PORT2" =~ ^[0-9]+$ && "$PORT1" != "$PORT2" ]] || fail 'invalid Polylang+TEC ports'
[[ "$EXPECTED_SHA" =~ ^[0-9a-f]{40}$ ]] || fail 'Polylang+TEC evidence requires a candidate SHA'
[ "$EXPECTED_SHA" = "$HEAD" ] || fail "candidate SHA $EXPECTED_SHA does not equal checkout HEAD $HEAD"
[ -z "$(git -C "$ROOT" status --porcelain=v1 --untracked-files=all)" ] || fail 'Polylang+TEC evidence requires a clean candidate checkout'
command -v jq >/dev/null || fail 'jq required'

export DUO_SOURCE_ROOT="$ROOT" DUO_EXPECTED_SOURCE_SHA="$EXPECTED_SHA" DUO_PAIR="$PAIR"
PAIR_COMPOSE=(docker compose -p "duo-$PAIR" -f pair.yml -f pair.artifacts.yml)
wp1() { "${PAIR_COMPOSE[@]}" run --rm -T cli1 wp "$@"; }
wp2() { "${PAIR_COMPOSE[@]}" run --rm -T cli2 wp "$@"; }
R1="siterepo/${PAIR}1"; R2="siterepo/${PAIR}2"; ORIGIN="siterepo/origin-$PAIR.git"
. bin/fetch-artifact.sh
validate_artifact_lock conformance/artifacts.lock.json || fail 'artifact lock validation failed'
jq -e '.plugins.polylang["3.8.6"] and .plugins["the-events-calendar"]["6.17.2"]' conformance/artifacts.lock.json >/dev/null || fail 'exact co-install pins are missing'
GREEN=0; cleanup() { [ "$GREEN" = 1 ] && bash bin/pair.sh destroy "$PAIR" >/dev/null 2>&1 || true; }; trap cleanup EXIT
install_exact() { local side="$1" slug="$2" version="$3" artifact; artifact=$(fetch_artifact "$slug" "$version" "cli$side"); "wp$side" plugin install "$artifact" --force --activate >/dev/null; [ "$("wp$side" plugin get "$slug" --field=version)" = "$version" ] || fail "side $side: $slug exact version mismatch"; }

say "fresh exact Polylang 3.8.6 + TEC 6.17.2 pair $PAIR"
bash bin/pair.sh reset "$PAIR"; bash bin/pair.sh up "$PAIR" "$PORT1" "$PORT2" --artifacts --headless
for side in 1 2; do install_exact "$side" polylang 3.8.6; install_exact "$side" the-events-calendar 6.17.2; done
export CONF_REPO1="$R1" CONF_REPO2="$R2" CONF1_PORT="$PORT1" CONF2_PORT="$PORT2" COMPOSE="${PAIR_COMPOSE[*]}"
wp_conf1() { wp1 "$@"; }
. conformance/seeds/polylang.sh
. conformance/seeds/the-events-calendar.sh
unset -f wp_conf1

git init -q -b main "$R1"; git -C "$R1" remote add origin "../origin-$PAIR.git"
# core's menu surface owns nav_menu terms and nav_menu_item posts plus their
# identities. Listing either again in generic term/post scope would ask one
# durable UUID to carry two entity types, an intentionally refused policy.
jq -n '{manifests:["core","polylang","the-events-calendar"],policy:{options:{},post_meta:{},post_types:["post","page","attachment","wp_block","tribe_events","tribe_venue","tribe_organizer"],taxonomies:["category","post_tag","language","term_language","post_translations","term_translations","tribe_events_cat"]},spec_version:2}' > "$R1/site.duo.json"
cp site-repo.gitignore.template "$R1/.gitignore"; wp1 duo capture --repo=/siterepo >/dev/null
git init --bare -b main "$ORIGIN" >/dev/null
git -C "$R1" add -A; git -C "$R1" -c user.name=duo-polylang-tec -c user.email=polylang-tec@example.test commit -qm 'capture: Polylang TEC rewrite baseline'; git -C "$R1" push -qu origin main; git clone -q "$ORIGIN" "$R2"; REVISION=$(git -C "$R2" rev-parse HEAD)

say 'exact pre-provider Polylang rewrite phase'
PRE_PROVIDER=$(wp2 eval '$runtime=PLL();$callbacks=[];foreach(($GLOBALS["wp_filter"]["rewrite_rules_array"]->callbacks??[]) as $priority=>$set){foreach($set as $entry){$f=$entry["function"]??null;if(is_array($f)&&is_object($f[0]??null)){$callbacks[]=get_class($f[0])."::".($f[1]??"");}}}echo wp_json_encode(["runtime"=>get_class($runtime),"links"=>is_object($runtime->links_model??null)?get_class($runtime->links_model):null,"sitemaps_property"=>property_exists($runtime,"sitemaps"),"callbacks"=>$callbacks]);' | tail -1)
echo "$PRE_PROVIDER" | jq -e '.runtime=="PLL_Admin" and .links=="PLL_Links_Directory" and .sitemaps_property==false and (.callbacks|map(select(startswith("PLL_")))|length)==0' >/dev/null || fail "unexpected pre-provider Polylang topology: $PRE_PROVIDER"
pass 'clean target has the exact directory-link model and fully inert deferred rewrite topology'

say 'provider projection precedes native fresh-process rewrite action'
INITIAL=$(wp2 duo apply --repo=/siterepo --adopt-by-slug=posts,terms --default-author=admin --revision="$REVISION" --format=json | tail -1) || fail 'initial co-install apply failed'
echo "$INITIAL" | jq -e '.canary=="clean" and ([.actions[].source] | index("provider:polylang-nav-menus/synchronize_runtime")) < ([.actions[].source] | index("native:rewrite.flush")) and any(.actions[]; .source=="provider:polylang-nav-menus/synchronize_runtime" and .verified==true) and any(.actions[]; .source=="native:rewrite.flush" and .verified==true and .after.rules_present==true)' >/dev/null || fail "provider/native receipt is incomplete: $INITIAL"
pass 'Polylang provider projection precedes the verified native rewrite action'

say 'closed Polylang + TEC rewrite callback topology'
HOOKS=$(wp2 eval '$wanted=["rewrite_rules_array","generate_rewrite_rules","pll_rewrite_rules","pll_modify_rewrite_rule"];$rows=[];foreach($wanted as $hook){foreach(($GLOBALS["wp_filter"][$hook]->callbacks??[]) as $priority=>$set){foreach($set as $entry){$f=$entry["function"]??null;if(is_array($f)&&isset($f[0],$f[1])){$name=(is_object($f[0])?get_class($f[0]):$f[0])."::".$f[1];}elseif(is_string($f)){$name=$f;}else{continue;}$rows[]=["hook"=>$hook,"priority"=>(int)$priority,"args"=>(int)($entry["accepted_args"]??0),"callback"=>$name];}}}echo wp_json_encode($rows);' | tail -1)
echo "$HOOKS" | jq -e '([.[]|select(.hook=="pll_rewrite_rules" or .hook=="pll_modify_rewrite_rule")]|length)==0 and any(.[];.hook=="rewrite_rules_array" and .callback=="PLL_Links_Directory::rewrite_rules" and .priority==10 and .args==1) and any(.[];.hook=="rewrite_rules_array" and .callback=="PLL_Sitemaps::rewrite_rules" and .priority==10 and .args==1) and any(.[];.hook=="rewrite_rules_array" and (.callback|contains("Tribe__Events__Rewrite::filter_rewrite_rules_array"))) and all(.[];.hook=="rewrite_rules_array" or .hook=="generate_rewrite_rules" or .hook=="pll_rewrite_rules" or .hook=="pll_modify_rewrite_rule")' >/dev/null || fail "Polylang + TEC callback topology widened: $HOOKS"
pass 'dynamic Polylang filters are empty; only source-pinned Polylang/TEC callbacks are present'

say 'failure-before-effect and retry on a hostile Polylang dynamic callback'
witness() { wp2 eval 'global $wpdb; $n=["polylang","theme_mods_twentytwentyone","rewrite_rules","default_category","tribe_last_generate_rewrite_rules","tribe_last_updated_option","tribe_last_save_post"]; $o=[]; foreach($n as $x){$o[$x]=$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s LIMIT 1",$x));} echo hash("sha256",serialize($o));' | tail -1; }
BEFORE=$(witness)
wp1 eval '
$category=get_term_by("slug","uncategorized-fr","category");
$menu=wp_get_nav_menu_object("Polylang Principal Français");
if (!$category instanceof WP_Term || !$menu instanceof WP_Term) throw new RuntimeException("Polylang French projection premise is missing");
$o=get_option("polylang"); $o["default_lang"]="fr"; update_option("polylang",$o);
update_option("default_category",(int)$category->term_id);
set_theme_mod("nav_menu_locations",["primary"=>(int)$menu->term_id]);
$theme_mods=get_option("theme_mods_".get_option("stylesheet"),[]);
$locations=is_array($theme_mods) ? ($theme_mods["nav_menu_locations"]??[]) : [];
if ((int)get_option("default_category")!==(int)$category->term_id || (int)($locations["primary"]??0)!==(int)$menu->term_id) throw new RuntimeException("Polylang French projection source graph is incoherent");
' >/dev/null
wp1 duo capture --repo=/siterepo >/dev/null; git -C "$R1" add -A; git -C "$R1" -c user.name=duo-polylang-tec -c user.email=polylang-tec@example.test commit -qm 'capture: Polylang projection retry'; git -C "$R1" push -qu origin main; git -C "$R2" pull -q origin main; REVISION=$(git -C "$R2" rev-parse HEAD)
HOSTILE_MU=/var/www/html/wp-content/mu-plugins/duo-polylang-tec-hostile.php
"${PAIR_COMPOSE[@]}" exec -T --user root wp2 sh -c 'umask 022; target=$1; tmp="${target}.tmp"; cat > "$tmp"; chmod 0644 "$tmp"; mv "$tmp" "$target"' sh "$HOSTILE_MU" <<'PHPEOF'
<?php
add_filter(
    'pll_modify_rewrite_rule',
    static fn(bool $modify, array $rule, string $type, string|false $archive): bool => $modify,
    10,
    4
);
PHPEOF
set +e; FAILED=$(wp2 duo apply --repo=/siterepo --adopt-by-slug=posts,terms --default-author=admin --revision="$REVISION" 2>&1); RC=$?; set -e
[ "$RC" -ne 0 ] || fail "hostile Polylang callback unexpectedly allowed apply: $FAILED"; grep -Fq 'unsupported open Polylang rewrite filter' <<<"$FAILED" || fail "hostile refusal lost its exact unsupported-filter reason: $FAILED"; [ "$(witness)" = "$BEFORE" ] || fail 'hostile refusal changed rewrite/TEC witnesses'; [ "$(wp2 eval 'echo null===\Duo\Ledger::kv_get("apply_in_progress")?"clear":"retained";' | tail -1)" = clear ] || fail 'hostile preflight refusal retained apply_in_progress'
"${PAIR_COMPOSE[@]}" exec -T --user root wp2 sh -c 'test -f "$1" && rm "$1"' sh "$HOSTILE_MU"
RETRY=$(wp2 duo apply --repo=/siterepo --adopt-by-slug=posts,terms --default-author=admin --revision="$REVISION" --format=json | tail -1) || fail 'Polylang+TEC retry failed'
echo "$RETRY" | jq -e '.canary=="clean" and any(.actions[];.source=="provider:polylang-nav-menus/synchronize_runtime" and .verified==true) and any(.actions[];.source=="native:rewrite.flush" and .verified==true)' >/dev/null || fail "retry receipt missing separate actions: $RETRY"
pass 'hostile callback refused before effect; removal permitted a bounded provider-then-native retry'

say 'clean no-op recapture'
wp2 duo capture --repo=/siterepo --out=/siterepo/.tmp-polylang-tec-recapture >/dev/null
# WordPress seeds an unreferenced Twenty Twenty-One sidebar on this exact target; canonical no-op below guards managed drift, while this loop proves every repository-owned byte recaptures unchanged.
while IFS= read -r -d '' SOURCE_FILE; do
  RELATIVE_FILE=${SOURCE_FILE#"$R2/state/"}
  cmp -s "$SOURCE_FILE" "$R2/.tmp-polylang-tec-recapture/$RELATIVE_FILE" || fail "repository-owned recapture differs at $RELATIVE_FILE"
done < <(find "$R2/state" -type f -print0)
NOOP=$(wp2 duo apply --repo=/siterepo --adopt-by-slug=posts,terms --default-author=admin --revision="$REVISION" --format=json | tail -1); echo "$NOOP" | jq -e '.canary=="clean" and (.actions|length)==0' >/dev/null || fail "clean recapture reran effects: $NOOP"; rm -rf "$R2/.tmp-polylang-tec-recapture"
pass 'post-retry recapture is clean and repeated apply is a verified no-op'
GREEN=1; printf '\n\033[1;32m✔ REGRESS_POLYLANG_TEC_REWRITE_COINSTALL PASSED\033[0m\n'
