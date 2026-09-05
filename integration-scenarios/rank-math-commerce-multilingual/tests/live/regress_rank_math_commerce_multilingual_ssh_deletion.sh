#!/usr/bin/env bash

set -euo pipefail

# Scenario-owned extension for sandbox/tests/live/regress_ssh_adopt.sh. A Woo
# product deletion would require each active plugin adapter to declare and
# source-audit post:product reverse references. This narrower proof stays on
# the declared core post:post boundary: the deleted post carries real ACF,
# Polylang, and Rank Math state while a real Woo product remains active and
# retains its authored fields, language, native lookup row, and identity.

wprism_ssh_adopt_extension() {
  local adapter actual_version expected_version post_id product_id post_uuid
  local plan_json before_json after_json status_json
  local converged_plan diagnostic_file promote_code seed_json plan_code converged_code
  local success_stdout="$DIAG_DIR/rank-math-commerce-multilingual-delete-success.stdout"
  local success_stderr="$DIAG_DIR/rank-math-commerce-multilingual-delete-success.stderr"
  local plan_stdout="$DIAG_DIR/rank-math-commerce-multilingual-delete-plan.stdout"
  local plan_stderr="$DIAG_DIR/rank-math-commerce-multilingual-delete-plan.stderr"
  local converged_stdout="$DIAG_DIR/rank-math-commerce-multilingual-delete-converged.stdout"
  local converged_stderr="$DIAG_DIR/rank-math-commerce-multilingual-delete-converged.stderr"

  for diagnostic_file in "$success_stdout" "$success_stderr" "$plan_stdout" "$plan_stderr" \
      "$converged_stdout" "$converged_stderr"; do
    ( umask 077; : >"$diagnostic_file" )
    chmod 0600 "$diagnostic_file"
  done

  say "enroll full recovery for the four-plugin signed deletion"
  wprism_ssh_enroll_full_recovery rm-combo
  pass "candidate-bound upload/effect/code-release providers are enrolled for the combined deletion"

  say "install the exact ACF, Polylang, Rank Math, and WooCommerce boundaries"
  WPRISM_ARTIFACT_PARTICIPANTS="$(artifact_library_scenario_participants \
    "$ROOT/integration-scenarios/rank-math-commerce-multilingual/scenario.json")" \
    || fail 'the combined deletion could not resolve its participant-owned artifact library'
  export WPRISM_ARTIFACT_PARTICIPANTS
  while IFS='|' read -r adapter expected_version; do
    [ -n "$adapter" ] || continue
    wprism_ssh_install_certified_plugin "$adapter" "$expected_version"
    actual_version="$(ssh_fixture "cd /var/www/html && wp plugin get '$adapter' --field=version")"
    [ "$actual_version" = "$expected_version" ] \
      || fail "$adapter is $actual_version, expected exact $expected_version"
  done <<'PLUGINS'
advanced-custom-fields|6.8.7
polylang|3.8.6
seo-by-rank-math|1.0.277.2
woocommerce|11.0.1
PLUGINS

  ssh_fixture 'cd /var/www/html && wp option update active_plugins '\''["polylang/polylang.php","advanced-custom-fields/acf.php","seo-by-rank-math/rank-math.php","woocommerce/woocommerce.php"]'\'' --format=json >/dev/null'
  jq -e '. == [
    "polylang/polylang.php",
    "advanced-custom-fields/acf.php",
    "seo-by-rank-math/rank-math.php",
    "woocommerce/woocommerce.php"
  ]' <<<"$(ssh_fixture 'cd /var/www/html && wp option get active_plugins --format=json')" >/dev/null \
    || fail "the SSH deletion did not retain the exact four-plugin active order"

cat >"$TMP/rank-math-commerce-multilingual-config.php" <<'PHP'
<?php

if (!class_exists('ACF') || !function_exists('PLL') || !class_exists('RankMath\\Helper')
    || !class_exists('WC_Install')) {
    throw new RuntimeException('the exact four-plugin runtime is incomplete');
}
if (!defined('WOOCOMMERCE_BIS_ALPHA_ENABLED')) {
    define('WOOCOMMERCE_BIS_ALPHA_ENABLED', true);
}
WC_Install::maybe_enable_hpos();
WC_Install::create_tables();
WC_Install::create_terms();
if (!\Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()) {
    throw new RuntimeException('WooCommerce HPOS did not become active');
}

update_option('rank_math_registration_skip', 1);
update_option('rank_math_is_configured', 1);
$stored = array_values(array_unique(array_map('strval', (array) get_option('rank_math_modules', []))));
if ($stored !== []) {
    RankMath\Helper::update_modules(array_fill_keys($stored, 'off'));
}
$modules = ['link-counter', 'redirections', 'rich-snippet'];
RankMath\Helper::update_modules(array_fill_keys($modules, 'on'));
if (array_values(RankMath\Helper::get_active_modules()) !== $modules) {
    throw new RuntimeException('Rank Math did not activate the exact combined module set');
}

$language = [
    'locale' => 'en_US',
    'slug' => 'en',
    'name' => 'English',
    'rtl' => 0,
    'term_group' => 0,
    'flag' => 'us',
];
$result = isset(PLL()->model->languages)
    ? PLL()->model->languages->add($language)
    : (new PLL_Admin_Model(PLL()->options))->add_language($language);
if (is_wp_error($result) && $result->has_errors()) {
    throw new RuntimeException($result->get_error_message());
}
PHP
  scp -F "$TMP/ssh_config" "$TMP/rank-math-commerce-multilingual-config.php" \
    wprism-adopt-fixture:/home/wprism/recovery-fixture/rank-math-commerce-multilingual-config.php >/dev/null
  ssh_fixture 'cd /var/www/html && wp eval-file /home/wprism/recovery-fixture/rank-math-commerce-multilingual-config.php' >/dev/null \
    || fail "the exact four-plugin native configuration failed"
  ssh_fixture 'rm -f /home/wprism/recovery-fixture/rank-math-commerce-multilingual-config.php'

  # Polylang persists its language model at shutdown. Author portable option
  # keys in a fresh request so that shutdown cannot overwrite them.
  ssh_fixture 'cd /var/www/html && wp eval '\''
$options = get_option("polylang");
if (!is_array($options)) {
    throw new RuntimeException("Polylang options are absent");
}
$options["default_lang"] = "en";
$options["browser"] = false;
$options["force_lang"] = 0;
$options["hide_default"] = false;
$options["media_support"] = 1;
$options["post_types"] = ["product"];
$options["redirect_lang"] = false;
$options["rewrite"] = true;
$options["taxonomies"] = ["product_cat"];
$options["sync"] = ["taxonomies", "post_meta", "post_date"];
update_option("polylang", $options);
'\''' >/dev/null

  jq -e '
    .active_modules == ["link-counter","redirections","rich-snippet"]
    and .languages == ["en"]
    and .rank_tables == {
      rank_math_internal_links:true,
      rank_math_internal_meta:true,
      rank_math_redirections:true,
      rank_math_redirections_cache:true
    }
    and .stock_notifications == true
  ' <<<"$(ssh_fixture 'cd /var/www/html && wp eval '\''
global $wpdb;
$tables = [];
foreach (["rank_math_internal_links", "rank_math_internal_meta", "rank_math_redirections", "rank_math_redirections_cache"] as $suffix) {
    $table = $wpdb->prefix . $suffix;
    $tables[$suffix] = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table;
}
$stock = $wpdb->prefix . "wc_stock_notifications";
$languages = pll_languages_list(["fields" => "slug"]);
sort($languages, SORT_STRING);
echo wp_json_encode([
    "active_modules" => array_values(RankMath\Helper::get_active_modules()),
    "languages" => $languages,
    "rank_tables" => $tables,
    "stock_notifications" => $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $stock)) === $stock,
]);
'\''')" >/dev/null \
    || fail "the four-plugin runtime did not expose its non-vacuous native topology"
  pass "exact plugin bytes, active order, Rank Math tables, Polylang language, and Woo topology are live"

  say "bind the exact combined plugin/theme code and adapter pins"
  wprism_ssh_stage_code_inventory \
    advanced-custom-fields polylang seo-by-rank-math woocommerce

  for adapter in acf polylang rank-math woocommerce; do
    ssh_fixture "cd /var/www/html && wp wprism manifest-pin --repo=/home/wprism/site --name='$adapter'" \
      >"$TMP/rm-combo-$adapter-pin.json" \
      || fail "the combined deletion could not obtain the shipped $adapter pin"
  done
  jq -s '.' \
    "$TMP/rm-combo-acf-pin.json" \
    "$TMP/rm-combo-polylang-pin.json" \
    "$TMP/rm-combo-rank-math-pin.json" \
    "$TMP/rm-combo-woocommerce-pin.json" \
    >"$TMP/rm-combo-pins.json"
  jq -e '
    map(.name) == ["acf","polylang","rank-math","woocommerce"]
    and all(.[]; .source == "shipped" and (.digest | test("^[a-f0-9]{64}$")))
  ' "$TMP/rm-combo-pins.json" >/dev/null \
    || fail "the combined adapter pins are incomplete or unbound"

  scp -F "$TMP/ssh_config" wprism-adopt-fixture:/home/wprism/site/site.wprism.json \
    "$TMP/rm-combo-site.before.json" >/dev/null
  jq --slurpfile pins "$TMP/rm-combo-pins.json" '
    .manifests = (
      [.manifests[]? | select((if type == "string" then . else .name end) as $name
        | (["acf","polylang","rank-math","woocommerce"] | index($name) | not))]
      + $pins[0]
    )
    | .code = {format:1,layout:"wp-content",source:"code/wp-content"}
    | .policy.post_types = (((.policy.post_types // []) + [
        "post","page","attachment","product","product_variation","shop_coupon",
        "acf-field-group","acf-field"
      ]) | unique)
    | .policy.taxonomies = (((.policy.taxonomies // []) + [
        "category","post_tag","product_brand","product_cat","product_shipping_class",
        "product_tag","product_type","product_visibility","language","term_language",
        "term_translations","post_translations"
      ]) | unique)
  ' "$TMP/rm-combo-site.before.json" >"$TMP/rm-combo-site.json"
  scp -F "$TMP/ssh_config" "$TMP/rm-combo-site.json" \
    wprism-adopt-fixture:/home/wprism/site/site.wprism.json >/dev/null

  cat >"$TMP/rank-math-commerce-multilingual-seed.php" <<'PHP'
<?php

$group = [
    'key' => 'group_rmcombo_ssh',
    'title' => 'SSH combined deletion fields',
    'fields' => [],
    'location' => [
        [['param' => 'post_type', 'operator' => '==', 'value' => 'post']],
        [['param' => 'post_type', 'operator' => '==', 'value' => 'product']],
    ],
    'active' => true,
];
acf_update_field_group($group);
$groups = get_posts([
    'post_type' => 'acf-field-group',
    'name' => $group['key'],
    'posts_per_page' => 1,
    'fields' => 'ids',
    'post_status' => 'any',
]);
if ($groups === []) {
    throw new RuntimeException('the combined ACF field group was not created');
}
acf_update_field([
    'key' => 'field_rmcombo_ssh_note',
    'label' => 'Combined note',
    'name' => 'rmcombo_ssh_note',
    'type' => 'text',
    'parent' => (int) $groups[0],
]);

$product = new WC_Product_Simple();
$product->set_name('SSH unaffected Woo product');
$product->set_slug('rmcombo-ssh-unaffected-product');
$product->set_status('publish');
$product->set_regular_price('43');
$productId = (int) $product->save();
if ($productId < 1) {
    throw new RuntimeException('the Woo preservation witness was not created');
}
pll_set_post_language($productId, 'en');
update_field('field_rmcombo_ssh_note', 'unaffected Woo ACF value', $productId);
update_post_meta($productId, 'rank_math_title', 'Unaffected Woo Rank Math title');
$product->set_description('<p>Unaffected Woo product <a href="https://example.test/product">external</a></p>');
$product->save();
RankMath\Links\Links::process_post_links($productId, get_post($productId));

$postId = wp_insert_post([
    'post_type' => 'post',
    'post_status' => 'publish',
    'post_name' => 'rmcombo-ssh-delete',
    'post_title' => 'SSH combined deletion post',
    'post_content' => '<p>Delete me <a href="' . esc_url(get_permalink($productId))
        . '">Woo peer</a> <a href="https://example.test/delete">external</a></p>',
], true);
if (is_wp_error($postId) || (int) $postId < 1) {
    throw new RuntimeException('the combined deletion post was not created');
}
$postId = (int) $postId;
pll_set_post_language($postId, 'en');
update_field('field_rmcombo_ssh_note', 'delete this ACF value', $postId);
update_post_meta($postId, 'rank_math_title', 'Delete this Rank Math title');
RankMath\Links\Links::process_post_links($postId, get_post($postId));

echo wp_json_encode(['post' => $postId, 'product' => $productId], JSON_UNESCAPED_SLASHES);
PHP
  scp -F "$TMP/ssh_config" "$TMP/rank-math-commerce-multilingual-seed.php" \
    wprism-adopt-fixture:/home/wprism/recovery-fixture/rank-math-commerce-multilingual-seed.php >/dev/null
  seed_json="$(ssh_fixture 'cd /var/www/html && wp eval-file /home/wprism/recovery-fixture/rank-math-commerce-multilingual-seed.php')" \
    || fail "the combined native deletion graph could not be authored"
  ssh_fixture 'rm -f /home/wprism/recovery-fixture/rank-math-commerce-multilingual-seed.php'
  post_id="$(jq -r '.post' <<<"$seed_json")"
  product_id="$(jq -r '.product' <<<"$seed_json")"
  [[ "$post_id" =~ ^[1-9][0-9]*$ ]] && [[ "$product_id" =~ ^[1-9][0-9]*$ ]] \
    || fail "the combined native deletion graph returned malformed identities"

  "$WPRISM" --envs-file="$TMP/envs.json" capture target --target-branch="$TARGET_REPOSITORY_BRANCH" \
    --format=json >"$TMP/rm-combo-baseline-capture.json" \
    || fail "the combined deletion could not capture its code/state baseline"
  ssh_fixture '
    set -eu
    git -C /home/wprism/site add -A
    git -C /home/wprism/site commit -m "Bind exact four-plugin code and combined deletion baseline" >/dev/null
    test -z "$(git -C /home/wprism/site status --porcelain)"
  ' || fail "the combined deletion baseline was not committed cleanly"
  wprism_ssh_stage_generation_releases 1
  "$WPRISM" --envs-file="$TMP/envs.json" deploy target \
    >"$TMP/rm-combo-code-baseline.stdout" 2>"$TMP/rm-combo-code-baseline.stderr" \
    || fail "the combined deletion could not complete its code lifecycle baseline"
  grep -q '^deploy complete:' "$TMP/rm-combo-code-baseline.stdout" \
    || fail "the combined deletion did not report a completed code lifecycle baseline"
  assert_ssh_fixture_positive_diagnostics 'combined deletion code lifecycle baseline' \
    "$TMP/rm-combo-code-baseline.stdout" "$TMP/rm-combo-code-baseline.stderr"
  pass "the four-plugin state and immutable code inventory completed the public capture/deploy lifecycle"

  cat >"$TMP/rank-math-commerce-multilingual-observe.php" <<'PHP'
<?php

global $wpdb;
$postId = (int) getenv('WPRISM_RMCOMBO_POST');
$productId = (int) getenv('WPRISM_RMCOMBO_PRODUCT');
$product = wc_get_product($productId);
$languages = pll_languages_list(['fields' => 'slug']);
sort($languages, SORT_STRING);
$rankIncoming = $wpdb->get_var($wpdb->prepare(
    "SELECT incoming_link_count FROM {$wpdb->prefix}rank_math_internal_meta WHERE object_id=%d",
    $productId
));
echo wp_json_encode([
    'deleted' => [
        'acf_meta' => (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key IN (%s,%s)",
            $postId,
            '_rmcombo_ssh_note',
            'rmcombo_ssh_note'
        )),
        'language' => get_post($postId) instanceof WP_Post ? pll_get_post_language($postId, 'slug') : null,
        'post' => get_post($postId) instanceof WP_Post ? 1 : 0,
        'postmeta' => (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id=%d",
            $postId
        )),
        'rank_links' => (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}rank_math_internal_links WHERE post_id=%d OR target_post_id=%d",
            $postId,
            $postId
        )),
        'rank_meta' => (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}rank_math_internal_meta WHERE object_id=%d",
            $postId
        )),
        'rank_target_links' => (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}rank_math_internal_links WHERE post_id=%d AND target_post_id=%d",
            $postId,
            $productId
        )),
        'relationships' => (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->term_relationships} WHERE object_id=%d",
            $postId
        )),
    ],
    'field_definitions' => count(get_posts([
        'post_type' => 'acf-field',
        'name' => 'field_rmcombo_ssh_note',
        'posts_per_page' => -1,
        'fields' => 'ids',
        'post_status' => 'any',
    ])),
    'languages' => $languages,
    'product' => [
        'acf' => get_field('rmcombo_ssh_note', $productId),
        'id' => $productId,
        'language' => pll_get_post_language($productId, 'slug'),
        'lookup' => (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->wc_product_meta_lookup} WHERE product_id=%d",
            $productId
        )),
        'post' => get_post($productId) instanceof WP_Post ? 1 : 0,
        'price' => $product instanceof WC_Product ? $product->get_regular_price('edit') : null,
        'rank_incoming' => $rankIncoming === null ? null : (int) $rankIncoming,
        'rank_title' => get_post_meta($productId, 'rank_math_title', true),
        'title' => get_the_title($productId),
    ],
    'shared_option' => get_option('scoped-apply_scoped_option'),
], JSON_UNESCAPED_SLASHES);
PHP
  scp -F "$TMP/ssh_config" "$TMP/rank-math-commerce-multilingual-observe.php" \
    wprism-adopt-fixture:/home/wprism/recovery-fixture/rank-math-commerce-multilingual-observe.php >/dev/null
  before_json="$(ssh_fixture "cd /var/www/html && WPRISM_RMCOMBO_POST='$post_id' WPRISM_RMCOMBO_PRODUCT='$product_id' wp eval-file /home/wprism/recovery-fixture/rank-math-commerce-multilingual-observe.php")" \
    || fail "the combined deletion preimage could not be observed"
  jq -e '
    .deleted.post == 1
    and .deleted.acf_meta == 2
    and .deleted.language == "en"
    and .deleted.postmeta > 2
    and .deleted.rank_links > 0
    and .deleted.rank_meta == 1
    and .deleted.rank_target_links == 1
    and .deleted.relationships > 0
    and .field_definitions == 1
    and .languages == ["en"]
    and .product == {
      acf:"unaffected Woo ACF value",
      id:.product.id,
      language:"en",
      lookup:1,
      post:1,
      price:"43",
      rank_incoming:1,
      rank_title:"Unaffected Woo Rank Math title",
      title:"SSH unaffected Woo product"
    }
    and .product.id > 0
    and .shared_option == "desired-success"
  ' <<<"$before_json" >/dev/null \
    || fail "the ACF/Polylang/Rank Math deletion preimage or Woo preservation witness is vacuous: $before_json"

  post_uuid="$(wprism_ssh_publish_post_tombstone post rmcombo-ssh-delete)" \
    || fail "the combined deletion could not publish its engine-derived core-post tombstone"

  # A PHP prelude may contain private operator values even at exit zero.
  # Keep both streams and parser diagnostics private before selecting JSON;
  # neither an invalid envelope nor a semantic refusal may echo its payload.
  plan_code=0
  "$WPRISM" --envs-file="$TMP/envs.json" plan target --format=json \
    >"$plan_stdout" 2>"$plan_stderr" || plan_code=$?
  [ "$plan_code" -eq 0 ] \
    || fail "the combined deletion could not plan its signed full promotion"
  assert_ssh_fixture_positive_diagnostics 'combined deletion plan' "$plan_stdout" "$plan_stderr"
  plan_json="$(jq -ce -s 'select(length == 1 and (.[0] | type == "object")) | .[0]' \
    "$plan_stdout" 2>>"$plan_stderr")" \
    || fail "the combined deletion plan did not return one JSON object; inspect its private capture"
  assert_wprism_required_environment 'combined deletion plan' json "$plan_json" 2>>"$plan_stderr"
  jq -e --arg uuid "$post_uuid" '
    ([.delete[]? | select(
      .uuid == $uuid and .type == "post" and .deletion_type == "post"
      and ((.blocked // "") == "")
    )] | length) == 1
    and any(.selected_actions[]?; .manifest == "rank-math")
    and any(.effects_inventory[]?; .source == "provider:rank-math-state/rebuild_all_link_state")
    and ([.effects_inventory[]? | select(.source == "provider:woocommerce-product-lookups/cleanup_product_deletions")] | length) == 0
    and .code_mismatch == []
    and .provider_problems == []
    and (.env_missing | type == "array" and all(.[]; type == "object" and .required == false))
  ' <<<"$plan_json" >/dev/null 2>>"$plan_stderr" \
    || fail "the combined plan did not isolate one supported core deletion and its Rank Math repair; inspect its private capture"
  pass "one core post deletion selects Rank Math repair while refusing to imply Woo product-delete authority"

  say "commit the supported combined deletion through signed full promotion"
  if "$WPRISM" --envs-file="$TMP/envs.json" promote target --with-deletes \
      >"$success_stdout" 2>"$success_stderr"; then
    promote_code=0
  else
    promote_code=$?
  fi
  [ "$promote_code" -eq 0 ] \
    || fail "the signed four-plugin core deletion did not complete"
  assert_ssh_fixture_positive_diagnostics 'signed four-plugin core deletion' \
    "$success_stdout" "$success_stderr"
  grep -Fq 'promote complete: verified committed receipt; traffic exclusion released' "$success_stdout" \
    || fail "the combined deletion lacked its verified committed full-recovery receipt"
  status_json="$(ssh_fixture 'php /home/wprism/site/.wprism/control/recovery-runtime/rollback-control.php active-evidence --root=/home/wprism/site/.wprism/control')"
  jq -e '
    .receipt.format == "wprism-rollback-receipt/v3"
    and .receipt.allow_deletes == true
    and .status.state == "committed"
    and .status.terminal == true
  ' <<<"$status_json" >/dev/null \
    || fail "the combined deletion did not retain exact signed committed authority"

  after_json="$(ssh_fixture "cd /var/www/html && WPRISM_RMCOMBO_POST='$post_id' WPRISM_RMCOMBO_PRODUCT='$product_id' wp eval-file /home/wprism/recovery-fixture/rank-math-commerce-multilingual-observe.php")" \
    || fail "the combined deletion postimage could not be observed"
  ssh_fixture 'rm -f /home/wprism/recovery-fixture/rank-math-commerce-multilingual-observe.php'
  jq -en --argjson before "$before_json" --argjson after "$after_json" '
    $after.deleted == {
      acf_meta:0,
      language:null,
      post:0,
      postmeta:0,
      rank_links:0,
      rank_meta:0,
      rank_target_links:0,
      relationships:0
    }
    and $after.field_definitions == $before.field_definitions
    and $after.languages == $before.languages
    and ($after.product | del(.rank_incoming)) == ($before.product | del(.rank_incoming))
    and $after.product.rank_incoming == 0
    and $after.product.post == 1
    and $after.product.lookup == 1
    and $after.shared_option == $before.shared_option
  ' >/dev/null \
    || fail "the signed deletion retained plugin-owned rows or crossed the Woo/ACF/Polylang boundary: $after_json"

  converged_code=0
  "$WPRISM" --envs-file="$TMP/envs.json" plan target --format=json \
    >"$converged_stdout" 2>"$converged_stderr" || converged_code=$?
  [ "$converged_code" -eq 0 ] \
    || fail "the combined deletion did not permit a converged follow-up plan"
  assert_ssh_fixture_positive_diagnostics 'combined deletion follow-up plan' "$converged_stdout" "$converged_stderr"
  converged_plan="$(jq -ce -s 'select(length == 1 and (.[0] | type == "object")) | .[0]' \
    "$converged_stdout" 2>>"$converged_stderr")" \
    || fail "the combined deletion follow-up plan did not return one JSON object; inspect its private capture"
  assert_wprism_required_environment 'combined deletion follow-up plan' json "$converged_plan" 2>>"$converged_stderr"
  jq -e '
    .create == [] and .update == [] and .adopt == []
    and .drift == [] and .conflict == []
    and .delete == [] and .delete_conflict == []
    and .code_mismatch == [] and .selected_actions == []
    and .provider_problems == []
    and (.env_missing | type == "array" and all(.[]; type == "object" and .required == false))
  ' <<<"$converged_plan" >/dev/null 2>>"$converged_stderr" \
    || fail "the combined signed deletion did not reach a no-action fixed point; inspect its private capture"
  [ -z "$(target_ledger_value promotion_lock)" ] \
    || fail "the combined signed deletion retained a promotion lock"
  jq -e '.state == "released"' <<<"$(ssh_fixture 'cat /home/wprism/recovery-fixture/provider-state.json')" >/dev/null \
    || fail "the combined signed deletion did not release external writer exclusion"
  pass "signed promotion deletes ACF/Polylang/Rank Math state, preserves the live Woo product/lookup, and converges"
}

if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
  SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd -P)"
  ROOT="$(cd "$SCRIPT_DIR/../../../.." && pwd -P)"
  : "${ADOPT_FIXTURE:?ADOPT_FIXTURE is required for the Rank Math combination SSH deletion gate}"
  : "${ADOPT_SSH_PORT:?ADOPT_SSH_PORT is required for the Rank Math combination SSH deletion gate}"
  : "${WPRISM_EXPECTED_SOURCE_SHA:?WPRISM_EXPECTED_SOURCE_SHA is required for the Rank Math combination SSH deletion gate}"
  export WPRISM_SSH_ADOPT_EXTENSION="$SCRIPT_DIR/$(basename "$0")"
  export WPRISM_SSH_SUITE_LABEL='rank-math-commerce-multilingual-ssh'
  export WPRISM_SSH_FINAL_LABEL='REGRESS_RANK_MATH_COMMERCE_MULTILINGUAL_SSH_DELETION'
  exec bash "$ROOT/sandbox/tests/live/regress_ssh_adopt.sh"
fi
