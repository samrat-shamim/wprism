#!/usr/bin/env bash
# Candidate-bound WooCommerce scope refusal.  Seed the real WooCommerce
# 11.0.1/HPOS graph through the native conformance seed, convert that same
# populated site to a network, then prove every public repository command
# refuses before it can publish or mutate the graph.
set -euo pipefail
PACKAGE_ROOT="$(cd "$(dirname "$0")/../.." && pwd -P)"
export WPRISM_ARTIFACT_PACKAGE="${PACKAGE_ROOT##*/}"
cd "$PACKAGE_ROOT/../../sandbox"

say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*" >&2; exit 1; }
. conformance/asserts.sh

command -v jq >/dev/null || fail 'jq required'
PAIR="${WOO_MULTISITE_PAIR:-wooms}"
PORT1="${WOO_MULTISITE_PORT1:-9030}"
PORT2="${WOO_MULTISITE_PORT2:-9031}"
EXPECTED_SHA="${WOO_MULTISITE_EXPECTED_SOURCE_SHA:-${WPRISM_EXPECTED_SOURCE_SHA:-}}"
ROOT="$(cd .. && pwd -P)"
HEAD="$(git -C "$ROOT" rev-parse HEAD)"
[[ "$PAIR" =~ ^[a-z][a-z0-9]*$ ]] || fail "invalid WOO_MULTISITE_PAIR '$PAIR'"
[[ "$PORT1" =~ ^[0-9]+$ && "$PORT2" =~ ^[0-9]+$ && "$PORT1" != "$PORT2" ]] \
  || fail 'WooCommerce multisite ports must be distinct decimal integers'
[ "$PORT1" -ge 1024 ] && [ "$PORT1" -le 65535 ] \
  && [ "$PORT2" -ge 1024 ] && [ "$PORT2" -le 65535 ] \
  || fail 'WooCommerce multisite ports must be within 1024..65535'
[[ "$EXPECTED_SHA" =~ ^[0-9a-f]{40}$ ]] \
  || fail 'WooCommerce multisite evidence requires a 40-character candidate SHA'
[ "$EXPECTED_SHA" = "$HEAD" ] \
  || fail "candidate SHA $EXPECTED_SHA does not equal checkout HEAD $HEAD"
[ -z "$(git -C "$ROOT" status --porcelain=v1 --untracked-files=all)" ] \
  || fail 'WooCommerce multisite evidence requires a clean candidate checkout'

WORDPRESS_OFFLINE="${WPRISM_WORDPRESS_ORG_OFFLINE:-0}"
case "$WORDPRESS_OFFLINE" in 0|1) ;; *) fail 'WPRISM_WORDPRESS_ORG_OFFLINE must be 0 or 1' ;; esac
export WPRISM_SOURCE_ROOT="$ROOT" WPRISM_EXPECTED_SOURCE_SHA="$EXPECTED_SHA" WPRISM_PAIR="$PAIR"
export WPRISM_ARTIFACT_OFFLINE="$WORDPRESS_OFFLINE"
. lib/pair_identity.sh
pair_identity_export_source_mounts \
  || fail 'WooCommerce multisite evidence could not pin its candidate mounts in the caller environment'
PAIR_COMPOSE=(docker compose -p "wprism-$PAIR" -f pair.yml -f pair.artifacts.yml)
UP_FLAGS=(--artifacts --headless)
if [ "$WORDPRESS_OFFLINE" = 1 ]; then
  PAIR_COMPOSE+=(-f pair.wordpress-offline.yml)
  UP_FLAGS+=(--wordpress-offline)
fi
wp1() { "${PAIR_COMPOSE[@]}" run --rm -T cli1 wp "$@"; }
wp_env() { local side="$1"; shift; "${PAIR_COMPOSE[@]}" run --rm -T "cli${side#conf}" wp "$@"; }
REPO="siterepo/${PAIR}1"
ORIGIN="siterepo/origin-${PAIR}.git"
CONF_REPO1="$REPO"
CONF1_PORT="$PORT1"
COMPOSE="${PAIR_COMPOSE[*]}"
export CONF_REPO1 CONF1_PORT COMPOSE
wp_conf1() { wp1 "$@"; }
. bin/fetch-artifact.sh

validate_artifact_library \
  || fail 'artifact library validation failed before WooCommerce multisite pair mutation'
artifact_library_jq -e '.plugins.woocommerce["11.0.1"].role == "certified-boundary" and (.plugins.woocommerce["11.0.1"].sha256 | test("^[0-9a-f]{64}$"))' \
  >/dev/null || fail 'exact WooCommerce 11.0.1 certified artifact library entry is missing'

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

woo_plugin_tree_hash() {
  wp1 eval '
    $root = WP_PLUGIN_DIR . "/woocommerce";
    if (!is_dir($root)) { throw new RuntimeException("WooCommerce plugin tree is absent"); }
    $rows = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
      if (!$file->isFile()) { continue; }
      $path = $file->getPathname();
      $rows[] = [
        "path" => substr($path, strlen($root) + 1),
        "bytes" => $file->getSize(),
        "sha256" => hash_file("sha256", $path),
      ];
    }
    usort($rows, static fn(array $a, array $b): int => $a["path"] <=> $b["path"]);
    if ($rows === []) { throw new RuntimeException("WooCommerce plugin tree is empty"); }
    echo hash("sha256", wp_json_encode($rows, JSON_UNESCAPED_SLASHES));
  ' | awk 'NF { line=$0 } END { print line }'
}

woo_plugin_identity() {
  [ "$(wp1 plugin get woocommerce --field=version)" = 11.0.1 ] \
    || fail 'WooCommerce plugin version is not exact 11.0.1'
  wp1 plugin is-active woocommerce >/dev/null \
    || fail 'WooCommerce is not active'
}

woo_identity() {
  woo_plugin_identity
  [ "$(wp1 eval 'echo get_option("woocommerce_custom_orders_table_enabled", "");' | tail -1)" = yes ] \
    || fail 'WooCommerce HPOS is not enabled'
}

woo_storage_fingerprint() {
  wp1 eval '
    global $wpdb;
    $queries = [
      "posts" => "SELECT * FROM {$wpdb->posts} WHERE post_type IN (\"product\",\"product_variation\",\"shop_coupon\",\"attachment\") ORDER BY ID",
      "postmeta" => "SELECT pm.* FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID=pm.post_id WHERE p.post_type IN (\"product\",\"product_variation\",\"shop_coupon\",\"attachment\") ORDER BY pm.meta_id",
      "terms" => "SELECT * FROM {$wpdb->terms} WHERE term_id IN (SELECT term_id FROM {$wpdb->term_taxonomy} WHERE taxonomy LIKE \"product_%\" OR taxonomy LIKE \"pa_%\") ORDER BY term_id",
      "term_taxonomy" => "SELECT * FROM {$wpdb->term_taxonomy} WHERE taxonomy LIKE \"product_%\" OR taxonomy LIKE \"pa_%\" ORDER BY term_taxonomy_id",
      "termmeta" => "SELECT tm.* FROM {$wpdb->termmeta} tm INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id=tm.term_id WHERE tt.taxonomy LIKE \"product_%\" OR tt.taxonomy LIKE \"pa_%\" ORDER BY tm.meta_id",
      "term_relationships" => "SELECT tr.* FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=tr.term_taxonomy_id WHERE tt.taxonomy LIKE \"product_%\" OR tt.taxonomy LIKE \"pa_%\" ORDER BY tr.object_id,tr.term_taxonomy_id",
      "woo_options" => "SELECT option_name,option_value,autoload FROM {$wpdb->options} WHERE option_name LIKE \"woocommerce\\_%\" OR option_name=\"wprism_woocommerce_multisite_canary\" ORDER BY option_name",
      "hpos_orders" => "SELECT * FROM {$wpdb->prefix}wc_orders ORDER BY 1",
      "hpos_addresses" => "SELECT * FROM {$wpdb->prefix}wc_order_addresses ORDER BY 1",
      "hpos_operational" => "SELECT * FROM {$wpdb->prefix}wc_order_operational_data ORDER BY 1",
      "hpos_meta" => "SELECT * FROM {$wpdb->prefix}wc_orders_meta ORDER BY 1",
      "sessions" => "SELECT * FROM {$wpdb->prefix}woocommerce_sessions WHERE session_key LIKE \"wprism-source-runtime-session\" ORDER BY 1",
      "actions" => "SELECT * FROM {$wpdb->prefix}actionscheduler_actions WHERE hook=\"wprism_woo_source_runtime_probe\" ORDER BY 1",
    ];
    $state = [];
    foreach ($queries as $name => $sql) {
      $wpdb->last_error = "";
      $rows = $wpdb->get_results($sql, ARRAY_A);
      if (!is_array($rows) || $wpdb->last_error !== "") {
        throw new RuntimeException("WooCommerce multisite fingerprint read failed: " . $name);
      }
      if (count($rows) > 50000) { throw new RuntimeException("WooCommerce multisite fingerprint exceeded its fixture bound: " . $name); }
      $state[$name] = ["count" => count($rows), "sha256" => hash("sha256", serialize($rows))];
    }
    $wprismTables = $wpdb->get_col($wpdb->prepare("SHOW TABLES LIKE %s", $wpdb->esc_like($wpdb->prefix . "wprism_") . "%"));
    if (!is_array($wprismTables)) { throw new RuntimeException("WooCommerce multisite WPrism table inventory failed"); }
    sort($wprismTables, SORT_STRING);
    $state["wprism_tables"] = [];
    foreach ($wprismTables as $table) {
      if (!is_string($table) || !preg_match("/^" . preg_quote($wpdb->prefix, "/") . "wprism_[a-z0-9_]+$/", $table)) {
        throw new RuntimeException("WooCommerce multisite WPrism table inventory returned an unsafe name");
      }
      $wpdb->last_error = "";
      $schema = $wpdb->get_row("SHOW CREATE TABLE `{$table}`", ARRAY_N);
      $rows = $wpdb->get_results("SELECT * FROM `{$table}` ORDER BY 1", ARRAY_A);
      if (!is_array($schema) || !is_array($rows) || $wpdb->last_error !== "") {
        throw new RuntimeException("WooCommerce multisite WPrism storage read failed: " . $table);
      }
      if (count($rows) > 50000) { throw new RuntimeException("WooCommerce multisite WPrism storage exceeded its fixture bound: " . $table); }
      $state["wprism_tables"][$table] = [
        "count" => count($rows),
        "schema_sha256" => hash("sha256", serialize($schema)),
        "rows_sha256" => hash("sha256", serialize($rows)),
      ];
    }
    $wpdb->last_error = "";
    $wprismOptions = $wpdb->get_results(
      "SELECT option_name,option_value,autoload FROM {$wpdb->options} WHERE option_name REGEXP \"^(wprism_|_transient(_timeout)?_wprism_)\" ORDER BY option_name",
      ARRAY_A
    );
    if (!is_array($wprismOptions) || $wpdb->last_error !== "") { throw new RuntimeException("WooCommerce multisite WPrism option read failed"); }
    if (count($wprismOptions) > 50000) { throw new RuntimeException("WooCommerce multisite WPrism option projection exceeded its fixture bound"); }
    $state["wprism_options"] = ["count" => count($wprismOptions), "sha256" => hash("sha256", serialize($wprismOptions))];
    $uploads = wp_upload_dir();
    $uploadRoot = (string) ($uploads["basedir"] ?? "");
    $files = [];
    if ($uploadRoot !== "" && is_dir($uploadRoot)) {
      $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($uploadRoot, FilesystemIterator::SKIP_DOTS));
      foreach ($iterator as $file) {
        if (!$file->isFile()) { continue; }
        $files[] = [
          "path" => substr($file->getPathname(), strlen($uploadRoot) + 1),
          "bytes" => $file->getSize(),
          "sha256" => hash_file("sha256", $file->getPathname()),
        ];
        if (count($files) > 50000) { throw new RuntimeException("WooCommerce multisite uploads fingerprint exceeded its fixture bound"); }
      }
    }
    usort($files, static fn(array $a, array $b): int => $a["path"] <=> $b["path"]);
    $state["uploads"] = ["count" => count($files), "sha256" => hash("sha256", serialize($files))];
    if ($state["posts"]["count"] < 10 || $state["terms"]["count"] < 8
        || $state["hpos_orders"]["count"] < 1 || $state["sessions"]["count"] < 1
        || $state["actions"]["count"] < 1 || $state["uploads"]["count"] < 1) {
      throw new RuntimeException("WooCommerce multisite fingerprint lacks the populated HPOS fixture");
    }
    echo hash("sha256", wp_json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
  ' | awk 'NF { line=$0 } END { print line }'
}

repo_git_fingerprint() {
  {
    git -C "$REPO" status --porcelain=v1 --untracked-files=all
    git -C "$REPO" rev-parse HEAD
    git -C "$REPO" symbolic-ref --short HEAD
    git -C "$REPO" for-each-ref --format='%(refname) %(objectname)' refs/heads refs/remotes
    git -C "$REPO" diff --no-ext-diff --binary HEAD
  } | shasum -a 256 | awk '{print $1}'
}

assert_no_publication() {
  [ ! -e "$REPO/state" ] && [ ! -e "$REPO/state.capture-staging" ] \
    && [ ! -e "$REPO/state.capture-backup" ] \
    || fail 'WooCommerce multisite refusal published repository state'
}

assert_command_refuses() { # <capture|plan|deploy|apply> <storage> <tree> <git> <site>
  local command="$1" storage="$2" tree="$3" git_state="$4" site_sha="$5" output rc=0 answer
  set +e
  if [ "$command" = apply ]; then
    output=$(wp1 wprism apply --repo=/siterepo --revision="$REVISION" --default-author=admin --format=json 2>/dev/null)
  else
    output=$(wp1 wprism "$command" --repo=/siterepo --format=json 2>/dev/null)
  fi
  rc=$?
  set -e
  [ "$rc" -ne 0 ] || fail "WooCommerce multisite $command returned success"
  answer=$(printf '%s\n' "$output" | awk 'NF { line=$0 } END { print line }')
  printf '%s\n' "$answer" | jq -e --arg command "$command" '
    .format == "wprism-command-refusal/v1" and .ok == false and .command == $command and
    .reason_code == "multisite_unsupported" and .error == "multisite_unsupported" and
    (has("details_redacted") | not) and (.message | contains("multisite is unsupported")) and
    (.remediation | contains("single-site"))
  ' >/dev/null || fail "WooCommerce multisite $command did not return the exact typed refusal: $answer"
  woo_identity
  [ "$(woo_plugin_tree_hash)" = "$tree" ] || fail "WooCommerce multisite $command changed the plugin tree"
  [ "$(woo_storage_fingerprint)" = "$storage" ] || fail "WooCommerce multisite $command mutated authored or runtime state"
  [ "$(shasum -a 256 "$REPO/site.wprism.json" | awk '{print $1}')" = "$site_sha" ] \
    || fail "WooCommerce multisite $command mutated site.wprism.json"
  [ "$(repo_git_fingerprint)" = "$git_state" ] || fail "WooCommerce multisite $command changed repository state/ref"
  [ "$(wp1 option get wprism_woocommerce_multisite_canary)" = untouched ] \
    || fail "WooCommerce multisite $command mutated the canary"
  assert_no_publication
  pass "WooCommerce multisite $command refused before plugin, state, Git, or repository mutation"
}

say "fresh exact WooCommerce 11.0.1 HPOS pair $PAIR"
bash bin/pair.sh reset "$PAIR"
bash bin/pair.sh up "$PAIR" "$PORT1" "$PORT2" "${UP_FLAGS[@]}"
ARTIFACT=$(fetch_artifact woocommerce 11.0.1 cli1 plugin)
wp1 plugin install "$ARTIFACT" --force --activate >/dev/null
woo_plugin_identity
establish_woocommerce_hpos wp1 >/dev/null \
  || fail "could not establish HPOS through WooCommerce's native new-shop lifecycle"
woo_identity
PLUGIN_TREE=$(woo_plugin_tree_hash)
require_observed_nonempty 'WooCommerce 11.0.1 plugin tree fingerprint' "$PLUGIN_TREE"
pass 'exact WooCommerce 11.0.1 plugin tree is installed, active, and HPOS-enabled'

say 'seed the populated native WooCommerce graph'
. "$(dirname "${BASH_SOURCE[0]}")/../conformance/seed.sh"
woo_identity
wp1 option update wprism_woocommerce_multisite_canary untouched >/dev/null

say 'prepare a committed site policy without publishing captured state'
bash bin/pair.sh repo-host "$PAIR" both >/dev/null
[ ! -e "$ORIGIN" ] || fail "pair reset did not remove the exact disposable origin: $ORIGIN"
git init --bare -b main "$ORIGIN" >/dev/null
git init -q -b main "$REPO"
git -C "$REPO" remote add origin "../origin-${PAIR}.git"
jq -n '{manifests:["core","woocommerce"],policy:{options:{},post_meta:{},term_meta:{},user_meta:{},post_types:["post","page","attachment","product","product_variation","shop_coupon"],taxonomies:["category","post_tag","product_brand","product_cat","product_shipping_class","product_tag","product_type","product_visibility"]},spec_version:2}' > "$REPO/site.wprism.json"
cp site-repo.gitignore.template "$REPO/.gitignore"
git -C "$REPO" add site.wprism.json .gitignore
git -C "$REPO" -c user.name=wprism-woocommerce -c user.email=woocommerce@example.test commit -qm 'policy: core + woocommerce'
git -C "$REPO" push -qu origin main
REVISION=$(git -C "$REPO" rev-parse HEAD)
SITE_BEFORE=$(shasum -a 256 "$REPO/site.wprism.json" | awk '{print $1}')
GIT_BEFORE=$(repo_git_fingerprint)
assert_no_publication

say 'convert the populated exact fixture to a real WordPress multisite'
wp1 core multisite-convert --title='WPrism WooCommerce 11.0.1 Multisite Refusal' >/dev/null
[ "$(wp1 eval 'echo is_multisite() ? "yes" : "no";' | tail -1)" = yes ] \
  || fail 'WordPress did not report multisite after conversion'
woo_identity
PLUGIN_TREE_AFTER_CONVERSION=$(woo_plugin_tree_hash)
[ "$PLUGIN_TREE_AFTER_CONVERSION" = "$PLUGIN_TREE" ] \
  || fail 'multisite conversion changed the exact WooCommerce plugin tree'
FINGERPRINT=$(woo_storage_fingerprint)
require_observed_nonempty 'WooCommerce multisite populated HPOS fingerprint' "$FINGERPRINT"
[ "$(woo_storage_fingerprint)" = "$FINGERPRINT" ] \
  || fail 'WooCommerce populated fingerprint is not repeatable across ordinary network boots'
[ "$(repo_git_fingerprint)" = "$GIT_BEFORE" ] || fail 'multisite conversion changed site repository Git state/ref'
pass 'populated WooCommerce authored/runtime/HPOS fingerprint is repeatable and exact plugin identity survived network conversion'

for command in capture plan deploy apply; do
  assert_command_refuses "$command" "$FINGERPRINT" "$PLUGIN_TREE" "$GIT_BEFORE" "$SITE_BEFORE"
done

GREEN=1
printf '\n\033[1;32m✔ REGRESS_WOOCOMMERCE_MULTISITE_REFUSAL PASSED\033[0m\n'
cleanup
trap - EXIT
