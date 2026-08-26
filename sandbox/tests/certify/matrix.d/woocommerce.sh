seed_woocommerce_content() {
  # Reuse the standalone WooCommerce fixture: products, coupon, media,
  # global attributes, shipping methods, tax, and a source-only HPOS order.
  wp_conf1() { wp1 "$@"; }
  wp_env() {
    local env="$1"; shift
    case "$env" in
      conf1) wp1 "$@" ;;
      conf2) wp2 "$@" ;;
      *) fail "unknown WooCommerce seed environment: $env" ;;
    esac
  }
  local CONF_REPO1="siterepo/${PAIR}1"
  local COMPOSE="$PAIR_COMPOSE_STRING"
  . conformance/seeds/woocommerce.sh
  unset -f wp_conf1 wp_env
}

postdeploy_woocommerce_content() {
  wp_conf2() { wp2 "$@"; }
  . conformance/postdeploy/woocommerce.sh
  unset -f wp_conf2
  woocommerce_preapply_authority_assertion "$WOO_VERSION" 'exact boundary'
}

postapply_woocommerce_content() {
  wp_conf2() { wp2 "$@"; }
  local CONF_REPO2="siterepo/${PAIR}2"
  . conformance/postapply/woocommerce.sh
  unset -f wp_conf2
}

check_woocommerce_content() {
  local COMPOSE="$PAIR_COMPOSE_STRING"
  local CONF1_PORT="$PORT1"
  local CONF2_PORT="$PORT2"
  local CONF_REPO1="siterepo/${PAIR}1"
  local CONF_REPO2="siterepo/${PAIR}2"
  local WOOCOMMERCE_BOUNDARY_ONLY=1
  local WOOCOMMERCE_EXPECTED_VERSION="$WOO_VERSION"
  . conformance/checks/woocommerce.sh
}

woocommerce_boundary_observation() {
  local COMPOSE="$PAIR_COMPOSE_STRING"
  local CONF1_PORT="$PORT1"
  local CONF2_PORT="$PORT2"
  local CONF_REPO1="siterepo/${PAIR}1"
  local CONF_REPO2="siterepo/${PAIR}2"
  observe_woocommerce conf2
}

woocommerce_boundary_storage_hash() {
  # The projection remains callable while the extension is absent, so it
  # distinguishes retained state from a false-green missing-code refusal.
  wp2 eval '
    global $wpdb;
    $queries = [
      "catalog_posts" => "SELECT * FROM {$wpdb->posts} WHERE post_type IN (\"attachment\",\"product\",\"product_variation\",\"shop_coupon\") ORDER BY ID",
      "catalog_meta" => "SELECT pm.* FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID=pm.post_id WHERE p.post_type IN (\"attachment\",\"product\",\"product_variation\",\"shop_coupon\") ORDER BY pm.meta_id",
      "catalog_comments" => "SELECT c.* FROM {$wpdb->comments} c INNER JOIN {$wpdb->posts} p ON p.ID=c.comment_post_ID WHERE p.post_type IN (\"product\",\"product_variation\") ORDER BY c.comment_ID",
      "catalog_commentmeta" => "SELECT cm.* FROM {$wpdb->commentmeta} cm INNER JOIN {$wpdb->comments} c ON c.comment_ID=cm.comment_id INNER JOIN {$wpdb->posts} p ON p.ID=c.comment_post_ID WHERE p.post_type IN (\"product\",\"product_variation\") ORDER BY cm.meta_id",
      "catalog_terms" => "SELECT t.* FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id=t.term_id WHERE tt.taxonomy LIKE \"product\\_%\" OR tt.taxonomy LIKE \"pa\\_%\" ORDER BY t.term_id",
      "catalog_taxonomy" => "SELECT * FROM {$wpdb->term_taxonomy} WHERE taxonomy LIKE \"product\\_%\" OR taxonomy LIKE \"pa\\_%\" ORDER BY term_taxonomy_id",
      "catalog_relationships" => "SELECT tr.* FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=tr.term_taxonomy_id WHERE tt.taxonomy LIKE \"product\\_%\" OR tt.taxonomy LIKE \"pa\\_%\" ORDER BY tr.object_id,tr.term_taxonomy_id",
      "catalog_termmeta" => "SELECT tm.* FROM {$wpdb->termmeta} tm INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id=tm.term_id WHERE tt.taxonomy LIKE \"product\\_%\" OR tt.taxonomy LIKE \"pa\\_%\" ORDER BY tm.meta_id",
      "options" => "SELECT option_name,option_value,autoload FROM {$wpdb->options} WHERE option_name IN (\"duo_target_environment_neighbor\",\"pickup_location_pickup_locations\",\"woocommerce_calc_taxes\",\"woocommerce_maybe_regenerate_images_hash\",\"woocommerce_paypal_settings\",\"woocommerce_pickup_location_settings\",\"woocommerce_price_num_decimals\",\"woocommerce_thumbnail_cropping\",\"woocommerce_thumbnail_cropping_custom_height\",\"woocommerce_thumbnail_cropping_custom_width\",\"woocommerce_thumbnail_image_width\") ORDER BY option_name",
      "attributes" => "SELECT * FROM {$wpdb->prefix}woocommerce_attribute_taxonomies ORDER BY attribute_id",
      "zones" => "SELECT * FROM {$wpdb->prefix}woocommerce_shipping_zones ORDER BY zone_id",
      "zone_locations" => "SELECT * FROM {$wpdb->prefix}woocommerce_shipping_zone_locations ORDER BY location_id",
      "zone_methods" => "SELECT * FROM {$wpdb->prefix}woocommerce_shipping_zone_methods ORDER BY instance_id",
      "tax_classes" => "SELECT * FROM {$wpdb->prefix}wc_tax_rate_classes ORDER BY tax_rate_class_id",
      "tax_rates" => "SELECT * FROM {$wpdb->prefix}woocommerce_tax_rates ORDER BY tax_rate_id",
      "tax_locations" => "SELECT * FROM {$wpdb->prefix}woocommerce_tax_rate_locations ORDER BY location_id",
      "product_lookup" => "SELECT * FROM {$wpdb->prefix}wc_product_meta_lookup ORDER BY product_id",
      "attribute_lookup" => "SELECT * FROM {$wpdb->prefix}wc_product_attributes_lookup ORDER BY product_or_parent_id,product_id,taxonomy,term_id",
      "category_lookup" => "SELECT * FROM {$wpdb->prefix}wc_category_lookup ORDER BY category_tree_id,category_id",
      "download_directories" => "SELECT * FROM {$wpdb->prefix}wc_product_download_directories ORDER BY url_id",
      "target_orders" => "SELECT * FROM {$wpdb->prefix}wc_orders WHERE billing_email=\"target-runtime@example.test\" ORDER BY id",
      "target_order_addresses" => "SELECT a.* FROM {$wpdb->prefix}wc_order_addresses a INNER JOIN {$wpdb->prefix}wc_orders o ON o.id=a.order_id WHERE o.billing_email=\"target-runtime@example.test\" ORDER BY a.id",
      "target_order_operational" => "SELECT o.* FROM {$wpdb->prefix}wc_order_operational_data o INNER JOIN {$wpdb->prefix}wc_orders p ON p.id=o.order_id WHERE p.billing_email=\"target-runtime@example.test\" ORDER BY o.id",
      "target_order_meta" => "SELECT m.* FROM {$wpdb->prefix}wc_orders_meta m INNER JOIN {$wpdb->prefix}wc_orders o ON o.id=m.order_id WHERE o.billing_email=\"target-runtime@example.test\" ORDER BY m.id",
      "target_session" => "SELECT * FROM {$wpdb->prefix}woocommerce_sessions WHERE session_key=\"duo-target-runtime-session\" ORDER BY session_id",
      "target_action" => "SELECT a.* FROM {$wpdb->prefix}actionscheduler_actions a WHERE a.hook=\"duo_woo_target_runtime_probe\" ORDER BY a.action_id",
      "target_action_group" => "SELECT g.* FROM {$wpdb->prefix}actionscheduler_groups g INNER JOIN {$wpdb->prefix}actionscheduler_actions a ON a.group_id=g.group_id WHERE a.hook=\"duo_woo_target_runtime_probe\" ORDER BY g.group_id",
    ];
    $state = [];
    foreach ($queries as $key => $sql) {
      $wpdb->last_error = "";
      $rows = $wpdb->get_results($sql, ARRAY_A);
      if ($wpdb->last_error !== "") { throw new RuntimeException("WooCommerce lifecycle storage read failed for " . $key); }
      if (count($rows) > 50000) { throw new RuntimeException("WooCommerce lifecycle storage projection exceeded its fixture bound"); }
      $state[$key] = $rows;
    }
    $state["media_files"] = [];
    foreach (get_posts(["post_type"=>"attachment","post_status"=>"any","numberposts"=>50001,"fields"=>"ids","orderby"=>"ID","order"=>"ASC"]) as $attachment_id) {
      $file = get_attached_file((int) $attachment_id, true);
      $state["media_files"][] = ["id" => (int) $attachment_id, "present" => is_string($file) && is_file($file), "sha256" => is_string($file) && is_file($file) ? hash_file("sha256", $file) : null];
    }
    if (count($state["media_files"]) > 50000) { throw new RuntimeException("WooCommerce lifecycle media projection exceeded its fixture bound"); }
    echo hash("sha256", wp_json_encode($state, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
  '
}

woocommerce_downgrade_duo_storage_hash() {
  # A code-drift refusal is read-only for Duo-owned persistence.  Project every
  # prefixed ledger table and the option namespace instead of assuming the
  # current migration's table list; an added ledger table must therefore enter
  # this witness automatically.
  wp2 eval '
    global $wpdb;
    $like = $wpdb->esc_like($wpdb->prefix . "duo_") . "%";
    $tables = $wpdb->get_col($wpdb->prepare("SHOW TABLES LIKE %s", $like));
    if (!is_array($tables)) { throw new RuntimeException("Duo storage table discovery did not return an array"); }
    sort($tables, SORT_STRING);
    $state = ["tables" => [], "options" => []];
    foreach ($tables as $table) {
      if (!is_string($table) || preg_match("/^[A-Za-z0-9_]+$/D", $table) !== 1) {
        throw new RuntimeException("Duo storage table name is not a safe SQL identifier");
      }
      $wpdb->last_error = "";
      $columns = $wpdb->get_col("SHOW COLUMNS FROM `{$table}`", 0);
      if ($wpdb->last_error !== "" || !is_array($columns) || $columns === []) {
        throw new RuntimeException("Duo storage column discovery failed for " . $table);
      }
      foreach ($columns as $column) {
        if (!is_string($column) || preg_match("/^[A-Za-z0-9_]+$/D", $column) !== 1) {
          throw new RuntimeException("Duo storage column name is not a safe SQL identifier");
        }
      }
      $order = implode(",", array_map(static fn(string $column): string => "`{$column}`", $columns));
      $wpdb->last_error = "";
      $rows = $wpdb->get_results("SELECT * FROM `{$table}` ORDER BY {$order}", ARRAY_A);
      if ($wpdb->last_error !== "" || !is_array($rows)) {
        throw new RuntimeException("Duo storage read failed for " . $table);
      }
      if (count($rows) > 50000) { throw new RuntimeException("Duo storage projection exceeded its fixture bound"); }
      $state["tables"][$table] = $rows;
    }
    $wpdb->last_error = "";
    $state["options"] = $wpdb->get_results(
      "SELECT option_name,option_value,autoload FROM {$wpdb->options} WHERE option_name REGEXP \"^(duo_|_transient(_timeout)?_duo_)\" ORDER BY option_name",
      ARRAY_A
    );
    if ($wpdb->last_error !== "" || !is_array($state["options"])) {
      throw new RuntimeException("Duo option storage read failed");
    }
    if (count($state["options"]) > 50000) { throw new RuntimeException("Duo option projection exceeded its fixture bound"); }
    echo hash("sha256", wp_json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
  '
}

woocommerce_downgrade_runtime_hash() {
  # The artifact replacement itself is outside this assertion.  Once the exact
  # 11.0.0 archive is active, ordinary commands may inspect but must not alter
  # runtime activation or scheduled work while refusing the stale code witness.
  wp2 eval '
    $duo_cron = [];
    foreach (_get_cron_array() as $timestamp => $hooks) {
      foreach ($hooks as $hook => $events) {
        if (is_string($hook) && str_starts_with($hook, "duo_")) {
          $duo_cron[$timestamp][$hook] = $events;
        }
      }
    }
    $state = [
      "active_plugins" => get_option("active_plugins", []),
      "stylesheet" => get_stylesheet(),
      "template" => get_template(),
      "duo_cron" => $duo_cron,
    ];
    echo hash("sha256", wp_json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
  '
}

woocommerce_downgrade_repository_hash() {
  local repo="siterepo/${PAIR}2"
  {
    git -C "$repo" status --porcelain=v1 --untracked-files=all
    git -C "$repo" rev-parse HEAD
    git -C "$repo" for-each-ref --format='%(refname) %(objectname)' refs/heads refs/remotes
    git -C "$repo" diff --no-ext-diff --binary HEAD
  } | shasum -a 256 | awk '{print $1}'
}

woocommerce_downgrade_refusal_snapshot() {
  local storage native runtime repository
  storage=$(woocommerce_downgrade_duo_storage_hash)
  native=$(woocommerce_boundary_storage_hash)
  runtime=$(woocommerce_downgrade_runtime_hash)
  repository=$(woocommerce_downgrade_repository_hash)
  require_observed_nonempty 'WooCommerce downgrade Duo storage witness' "$storage"
  require_observed_nonempty 'WooCommerce downgrade native storage witness' "$native"
  require_observed_nonempty 'WooCommerce downgrade runtime witness' "$runtime"
  require_observed_nonempty 'WooCommerce downgrade repository witness' "$repository"
  printf '%s\n' "$storage:$native:$runtime:$repository"
}

woocommerce_preapply_authority_assertion() { # <exact-version> <matrix-phase>
  # The 85-field unclassified failure was transient enough that apply's own
  # summary did not identify the manifest library it had actually loaded.
  # Read the same target Policy immediately before mutation and make its
  # source paths, file bytes, and representative Woo authority decisions the
  # failure evidence; none of this path writes WordPress or the repository.
  local version="$1" phase="$2" evidence
  evidence=$(wp2 eval '
$policy = \Duo\Policy::load("/siterepo");
$sources = $policy->adapter_sources();
$loaded = [];
foreach ($policy->manifests as $manifest) {
    $name = (string) ($manifest["name"] ?? "");
    if ($name === "") {
        throw new RuntimeException("WooCommerce pre-apply authority loaded an unnamed manifest");
    }
    $file = $sources->file($name, \Duo\Policy::manifests_dir());
    $loaded[] = [
        "file" => $file,
        "path" => $sources->path($name),
        "sha256" => is_file($file) ? hash_file("sha256", $file) : null,
        "source" => $sources->source($name),
        "name" => $name,
    ];
}
usort($loaded, static fn(array $a, array $b): int => strcmp($a["name"], $b["name"]));
$evidence = [
    "format" => "duo-woocommerce-preapply-authority/v1",
    "loaded_manifests" => $loaded,
    "rules" => [
        "option:pickup_location_pickup_locations" => $policy->option_rule_details("pickup_location_pickup_locations"),
        "option:woocommerce_bacs_settings" => $policy->option_rule_details("woocommerce_bacs_settings"),
        "post_meta:_product_url" => $policy->post_meta_rule_details("_product_url"),
        "term_meta:display_type" => $policy->term_meta_rule_details("display_type"),
    ],
];
$woocommerce = null;
foreach ($policy->manifests as $manifest) {
    if (($manifest["name"] ?? null) === "woocommerce") {
        $woocommerce = $manifest;
        break;
    }
}
$valid = array_column($loaded, "name") === ["core", "woocommerce"]
    && ($woocommerce["version_range"] ?? null) === ["min" => "11.0.0", "max" => "11.0.2"]
    && ($evidence["rules"]["option:pickup_location_pickup_locations"] ?? null) === [
        "rule" => ["class" => "authored", "plain_data" => true, "autoload" => "preserve"],
        "source" => "woocommerce",
    ]
    && ($evidence["rules"]["option:woocommerce_bacs_settings"] ?? null) === [
        "rule" => [
            "class" => "env",
            "required" => false,
            "absent_autoload" => "yes",
            "closed_sub_keys" => true,
            "sub_keys" => [
                "enabled" => ["class" => "authored"],
                "title" => ["class" => "authored"],
                "description" => ["class" => "authored"],
                "instructions" => ["class" => "authored"],
                "account_details" => ["class" => "derived", "native_default_completion" => true],
                "account_name" => ["class" => "env"],
                "account_number" => ["class" => "env"],
                "bank_name" => ["class" => "env"],
                "sort_code" => ["class" => "env"],
                "iban" => ["class" => "env"],
                "bic" => ["class" => "env"],
            ],
            "autoload" => "preserve",
        ],
        "source" => "woocommerce",
    ]
    && ($evidence["rules"]["post_meta:_product_url"] ?? null) === [
        "rule" => ["class" => "authored"],
        "source" => "woocommerce",
    ]
    && ($evidence["rules"]["term_meta:display_type"] ?? null) === [
        "rule" => ["class" => "authored"],
        "source" => "woocommerce",
    ];
foreach ($loaded as $manifest) {
    $valid = $valid
        && $manifest["source"] === "shipped"
        && is_string($manifest["file"])
        && $manifest["file"] !== ""
        && is_string($manifest["path"])
        && $manifest["path"] !== ""
        && is_string($manifest["sha256"])
        && preg_match("/^[0-9a-f]{64}$/D", $manifest["sha256"]) === 1;
}
$json = wp_json_encode($evidence, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
if (!$valid) {
    throw new RuntimeException("WooCommerce pre-apply authority assertion failed: " . $json);
}
echo $json;
') || fail "WooCommerce $version $phase pre-apply authority assertion could not load the target policy"
  require_observed_nonempty "WooCommerce $version $phase pre-apply authority evidence" "$evidence"
  jq -se '
    length == 1
    and .[0].format == "duo-woocommerce-preapply-authority/v1"
    and (.[0].loaded_manifests | map(.name) == ["core", "woocommerce"])
    and (.[0].loaded_manifests | all(.source == "shipped" and (.file | type == "string" and length > 0) and (.path | type == "string" and length > 0) and (.sha256 | test("^[0-9a-f]{64}$"))))
    and .[0].rules["option:pickup_location_pickup_locations"] == {rule:{class:"authored",plain_data:true,autoload:"preserve"},source:"woocommerce"}
    and .[0].rules["option:woocommerce_bacs_settings"].source == "woocommerce"
    and .[0].rules["option:woocommerce_bacs_settings"].rule.class == "env"
    and .[0].rules["option:woocommerce_bacs_settings"].rule.closed_sub_keys == true
    and .[0].rules["option:woocommerce_bacs_settings"].rule.autoload == "preserve"
    and .[0].rules["post_meta:_product_url"] == {rule:{class:"authored"},source:"woocommerce"}
    and .[0].rules["term_meta:display_type"] == {rule:{class:"authored"},source:"woocommerce"}
  ' <<<"$evidence" >/dev/null \
    || fail "WooCommerce $version $phase pre-apply authority evidence was malformed: $evidence"
  pass "WooCommerce $version $phase pre-apply authority is exact: $evidence"
}

assert_woocommerce_downgrade_refusal_unchanged() { # <operation> <post-install-snapshot>
  local operation="$1" expected="$2" observed
  observed=$(woocommerce_downgrade_refusal_snapshot)
  [ "$observed" = "$expected" ] \
    || fail "WooCommerce 11.0.1 to 11.0.0 $operation refusal mutated Duo storage, runtime, or repository state"
}

check_woocommerce_in_range_downgrade() { # <exact-11.0.0-source-artifact> <exact-11.0.0-target-artifact>
  local source_artifact="$1" target_artifact="$2" expected_sha snapshot plan_out plan_json plan_rc
  local deploy_out deploy_rc apply_out apply_rc revision settled source_price target_price downgrade_diff
  local mutation_note='WooCommerce 11.0.1 to 11.0.0 downgrade 東京 🚀'

  say 'in-range downgrade: populated woocommerce 11.0.1 -> exact 11.0.0 refuses until explicit re-baseline'
  # Installation has its own activation/migration effects, so establish the
  # no-mutation baseline only after the exact verified archives replace 11.0.1.
  wp1 plugin install "$source_artifact" --force --activate >/dev/null
  wp2 plugin install "$target_artifact" --force --activate >/dev/null
  expected_sha=$(jq -r '.plugins.woocommerce["11.0.0"].sha256' conformance/artifacts.lock.json)
  [[ "$expected_sha" =~ ^[0-9a-f]{64}$ ]] || fail 'WooCommerce 11.0.0 downgrade artifact has no exact lock digest'
  [ "$(wp1 eval "echo hash_file('sha256','$source_artifact');")" = "$expected_sha" ] \
    && [ "$(wp2 eval "echo hash_file('sha256','$target_artifact');")" = "$expected_sha" ] \
    || fail 'WooCommerce in-range downgrade did not retain the exact 11.0.0 artifacts'
  [ "$(wp1 plugin get woocommerce --field=version)" = 11.0.0 ] \
    && [ "$(wp2 plugin get woocommerce --field=version)" = 11.0.0 ] \
    || fail 'WooCommerce in-range downgrade did not install exact 11.0.0 on both environments'
  snapshot=$(woocommerce_downgrade_refusal_snapshot)

  plan_rc=0
  plan_out=$(wp2 duo plan --repo=/siterepo --format=json 2>&1) || plan_rc=$?
  require_duo_answered 'WooCommerce 11.0.1 to 11.0.0 downgrade plan' json "$plan_out"
  [ "$plan_rc" -eq 0 ] || fail "WooCommerce in-range downgrade plan did not report its code drift: $plan_out"
  plan_json=$(awk 'NF { line=$0 } END { print line }' <<<"$plan_out")
  jq -e '
    (.code_drift | length) == 1 and
    .code_drift[0].plugin == "woocommerce/woocommerce.php" and
    .code_drift[0].installed_version == "11.0.0" and
    .code_drift[0].recorded_version == "11.0.1"
  ' <<<"$plan_json" >/dev/null \
    || fail "WooCommerce in-range downgrade plan did not identify the exact 11.0.1 to 11.0.0 code_drift: $plan_json"
  assert_woocommerce_downgrade_refusal_unchanged plan "$snapshot"

  deploy_rc=0
  deploy_out=$(wp2 duo deploy --repo=/siterepo 2>&1) || deploy_rc=$?
  require_duo_answered 'WooCommerce 11.0.1 to 11.0.0 downgrade deploy refusal' human "$deploy_out"
  [ "$deploy_rc" -ne 0 ] && grep -q 'code_drift' <<<"$deploy_out" \
    && grep -q '11.0.1' <<<"$deploy_out" && grep -q '11.0.0' <<<"$deploy_out" \
    || fail "WooCommerce in-range downgrade deploy did not refuse at the exact code-drift boundary: $deploy_out"
  assert_woocommerce_downgrade_refusal_unchanged deploy "$snapshot"

  revision=$(git -C "siterepo/${PAIR}2" rev-parse HEAD)
  apply_rc=0
  apply_out=$(wp2 duo apply --repo=/siterepo --adopt-by-slug=terms,posts --default-author=admin --revision="$revision" 2>&1) || apply_rc=$?
  require_duo_answered 'WooCommerce 11.0.1 to 11.0.0 downgrade apply refusal' human "$apply_out"
  [ "$apply_rc" -ne 0 ] && grep -q 'code_drift' <<<"$apply_out" \
    && grep -q '11.0.1' <<<"$apply_out" && grep -q '11.0.0' <<<"$apply_out" \
    || fail "WooCommerce in-range downgrade apply did not refuse at the exact code-drift boundary: $apply_out"
  assert_woocommerce_downgrade_refusal_unchanged apply "$snapshot"
  pass 'WooCommerce 11.0.1 -> 11.0.0 ordinary plan identifies code_drift; deploy/apply refuse without Duo storage, runtime, or repository mutation'

  local forced_source forced_target
  forced_source=$(wp1 duo deploy --repo=/siterepo --force-code-drift 2>&1)
  forced_target=$(wp2 duo deploy --repo=/siterepo --force-code-drift 2>&1)
  normalize_woocommerce_harness_placeholder_mode wp2
  grep -q 'FORCED past code_drift' <<<"$forced_source" \
    && grep -q 'FORCED past code_drift' <<<"$forced_target" \
    || fail "WooCommerce explicit downgrade re-baseline did not report both forced decisions: source=$forced_source target=$forced_target"
  settled=$(wp2 duo plan --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
  require_duo_answered 'WooCommerce 11.0.1 to 11.0.0 plan after explicit re-baseline' json "$settled"
  jq -e '([.create,.update,.drift,.conflict,.collision,.delete,.delete_conflict,.code_mismatch,.code_drift,.incomplete_apply,.incomplete_lifecycle,.regen_pending,.regen_context] | map(length) | add) == 0' <<<"$settled" >/dev/null \
    || fail "WooCommerce explicit 11.0.0 re-baseline invented work: $settled"

  source_price=$(wp1 eval '
    global $wpdb;
    $product = wc_get_product(wc_get_product_id_by_sku("CONF-PRECISION-UTF8"));
    if (!$product) { throw new RuntimeException("downgrade precision product missing"); }
    $product->set_regular_price("17.345678");
    $product->set_sale_price("");
    $product->set_purchase_note("WooCommerce 11.0.1 to 11.0.0 downgrade 東京 🚀");
    $product->save();
    $row = $wpdb->get_row($wpdb->prepare("SELECT CAST(min_price AS CHAR) AS min_price, CAST(max_price AS CHAR) AS max_price FROM {$wpdb->prefix}wc_product_meta_lookup WHERE product_id=%d", $product->get_id()), ARRAY_A);
    if (!is_array($row) || $row !== ["min_price" => "17.3457", "max_price" => "17.3457"]) {
      throw new RuntimeException("downgrade source lookup DECIMAL readback was not exact: " . wp_json_encode($row));
    }
    echo wp_json_encode(["price" => $product->get_regular_price("edit"), "note" => $product->get_purchase_note("edit"), "lookup" => $row], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  ')
  jq -e --arg note "$mutation_note" '.price == "17.345678" and .note == $note and .lookup == {min_price:"17.3457",max_price:"17.3457"}' <<<"$source_price" >/dev/null \
    || fail "WooCommerce exact 11.0.0 source mutation/readback was not precise: $source_price"
  wp1 duo capture --repo=/siterepo
  wp1 duo lint --repo=/siterepo
  "${GIT1[@]}" add -A
  "${GIT1[@]}" commit -qm 'capture: woocommerce 11.0.1 to 11.0.0 in-range downgrade'
  "${GIT1[@]}" push -q origin main

  git -C "siterepo/${PAIR}2" pull -q origin main
  revision=$(git -C "siterepo/${PAIR}2" rev-parse HEAD)
  woocommerce_preapply_authority_assertion 11.0.0 'in-range downgrade'
  wp2 duo apply --repo=/siterepo --adopt-by-slug=terms,posts --default-author=admin --revision="$revision" 2>&1 | tee "$VMATRIX_APPLY_LOG"
  grep -q 'canary clean' "$VMATRIX_APPLY_LOG" \
    || fail 'WooCommerce exact 11.0.0 apply canary was not clean after downgrade re-baseline'
  target_price=$(wp2 eval '
    global $wpdb;
    $product = wc_get_product(wc_get_product_id_by_sku("CONF-PRECISION-UTF8"));
    if (!$product) { throw new RuntimeException("downgrade precision product missing on target"); }
    $row = $wpdb->get_row($wpdb->prepare("SELECT CAST(min_price AS CHAR) AS min_price, CAST(max_price AS CHAR) AS max_price FROM {$wpdb->prefix}wc_product_meta_lookup WHERE product_id=%d", $product->get_id()), ARRAY_A);
    echo wp_json_encode(["price" => $product->get_regular_price("edit"), "note" => $product->get_purchase_note("edit"), "lookup" => $row], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  ')
  jq -e --arg note "$mutation_note" '.price == "17.345678" and .note == $note and .lookup == {min_price:"17.3457",max_price:"17.3457"}' <<<"$target_price" >/dev/null \
    || fail "WooCommerce exact 11.0.0 target mutation/readback was not precise: $target_price"
  settled=$(wp2 duo plan --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
  require_duo_answered 'WooCommerce exact 11.0.0 plan after downgrade mutation' json "$settled"
  jq -e '([.create,.update,.drift,.conflict,.collision,.delete,.delete_conflict,.code_mismatch,.code_drift,.incomplete_apply,.incomplete_lifecycle,.regen_pending,.regen_context] | map(length) | add) == 0' <<<"$settled" >/dev/null \
    || fail "WooCommerce exact 11.0.0 plan did not settle after downgrade mutation: $settled"
  wp2 duo capture --repo=/siterepo --out=/siterepo/.tmp-woo-downgrade-final >/dev/null
  downgrade_diff=$(diff -rq "siterepo/${PAIR}1/state" "siterepo/${PAIR}2/.tmp-woo-downgrade-final" || true)
  rm -rf "siterepo/${PAIR}2/.tmp-woo-downgrade-final"
  [ -z "$downgrade_diff" ] \
    || fail "WooCommerce 11.0.1 to 11.0.0 in-range downgrade lost byte identity: $downgrade_diff"
  pass 'WooCommerce 11.0.1 -> 11.0.0 explicit re-baseline applies exact DECIMAL catalog mutation, settles, and recaptures byte-identically'
}

check_woocommerce_boundary_lifecycle() { # <exact-version> <verified-artifact>
  local version="$1" artifact="$2" expected_sha before_native reactivated_native
  local before_uninstall absent_after_uninstall missing_before missing_after missing_rc missing_out
  local lifecycle_diff lifecycle_diff_rc unexpected_lifecycle_diff
  # Both official boundary artifacts carry byte-identical uninstall.php bytes
  # (sha256 e06e0c2086f695d39f5d9edead87cd4faeb0ea45184d77e7d8fe5588abfde48e):
  # native runtime hooks are cleared unconditionally, while catalog/options/
  # tables are removed only when the operator sets WC_REMOVE_ALL_DATA=true.
  expected_sha=$(jq -r --arg version "$version" '.plugins.woocommerce[$version].sha256' conformance/artifacts.lock.json)
  [[ "$expected_sha" =~ ^[0-9a-f]{64}$ ]] || fail "WooCommerce $version lifecycle artifact has no exact lock digest"
  [ "$(wp2 eval "echo hash_file('sha256','$artifact');")" = "$expected_sha" ] || fail "WooCommerce $version lifecycle artifact digest moved before reinstall proof"
  [ "$(wp2 eval 'echo defined("WC_REMOVE_ALL_DATA") && true === WC_REMOVE_ALL_DATA ? "yes" : "no";')" = no ] || fail "WooCommerce $version default-retention lifecycle premise unexpectedly enables destructive cleanup"
  before_native=$(woocommerce_boundary_observation)
  require_observed_nonempty "WooCommerce $version native state before deactivation" "$before_native"
  wp2 plugin deactivate woocommerce >/dev/null
  wp2 plugin is-active woocommerce >/dev/null 2>&1 && fail "WooCommerce $version deactivation premise did not land"
  wp2 duo deploy --repo=/siterepo >/dev/null
  wp2 plugin is-active woocommerce >/dev/null || fail "WooCommerce $version deploy did not reactivate the exact plugin"
  normalize_woocommerce_harness_placeholder_mode wp2
  reactivated_native=$(woocommerce_boundary_observation)
  [ "$reactivated_native" = "$before_native" ] || fail "WooCommerce $version deactivate/reactivate changed native authored or target-runtime state"
  wp2 plugin deactivate woocommerce >/dev/null
  before_uninstall=$(woocommerce_boundary_storage_hash)
  require_observed_nonempty "WooCommerce $version raw storage before default uninstall" "$before_uninstall"
  wp2 plugin uninstall woocommerce >/dev/null
  wp2 plugin is-installed woocommerce >/dev/null 2>&1 && fail "WooCommerce $version uninstall left plugin code installed"
  absent_after_uninstall=$(woocommerce_boundary_storage_hash)
  [ "$absent_after_uninstall" = "$before_uninstall" ] || fail "WooCommerce $version default uninstall changed retained authored or target-runtime storage"
  missing_before="$absent_after_uninstall"; missing_rc=0
  missing_out=$(wp2 duo deploy --repo=/siterepo 2>&1) || missing_rc=$?
  require_duo_answered "WooCommerce $version deploy with code absent" human "$missing_out"
  [ "$missing_rc" -ne 0 ] && grep -Eq 'code_mismatch|missing_in_code|is not installed' <<<"$missing_out" || fail "WooCommerce $version missing-code deploy did not refuse at compatibility: $missing_out"
  missing_after=$(woocommerce_boundary_storage_hash)
  [ "$missing_after" = "$missing_before" ] || fail "WooCommerce $version missing-code refusal partially changed retained storage"
  wp2 plugin install "$artifact" --force >/dev/null
  [ "$(wp2 plugin get woocommerce --field=version)" = "$version" ] || fail "WooCommerce exact reinstall reported the wrong version at $version"
  wp2 duo deploy --repo=/siterepo >/dev/null
  wp2 plugin is-active woocommerce >/dev/null || fail "WooCommerce $version exact reinstall was not active after deploy"
  normalize_woocommerce_harness_placeholder_mode wp2
  check_woocommerce_content
  wp2 duo capture --repo=/siterepo --out=/siterepo/.tmp-woo-lifecycle-final >/dev/null
  lifecycle_diff_rc=0
  lifecycle_diff=$(diff -r "siterepo/${PAIR}1/state" "siterepo/${PAIR}2/.tmp-woo-lifecycle-final" 2>&1) || lifecycle_diff_rc=$?
  [ "$lifecycle_diff_rc" -le 1 ] || fail "WooCommerce $version exact-reinstall recapture comparison errored: $lifecycle_diff"
  if [ -n "$lifecycle_diff" ]; then
    unexpected_lifecycle_diff=$(grep -Ev \
      -e '^diff -r .*/state/posts/(product|product_variation)/[^ ]+ .*/\.tmp-woo-lifecycle-final/posts/(product|product_variation)/[^ ]+$' \
      -e '^[0-9]+(,[0-9]+)?c[0-9]+(,[0-9]+)?$' \
      -e '^---$' \
      -e '^[<>]     "modified(_gmt)?": "[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}",$' \
      <<<"$lifecycle_diff" || true)
    [ -z "$unexpected_lifecycle_diff" ] \
      || fail "WooCommerce $version exact-reinstall recapture diverged outside declared derived product timestamps: $lifecycle_diff"
  fi
  rm -rf "siterepo/${PAIR}2/.tmp-woo-lifecycle-final"
  pass "WooCommerce $version deactivate/reactivate, retained-data uninstall, absent-code refusal, digest-bound exact reinstall, native readback, recapture modulo declared derived product timestamps, and retry are clean"
}

check_woocommerce_product_delete_refusal() { # <exact-version>
  local version="$1" repo="siterepo/${PAIR}2" product order lookup_before lookup_after
  local product_file product_uuid expected_hash expected_revision source_path backup
  local before_tree after_tree before_head after_head before_origin after_origin plan_rc apply_rc force_rc plan_out apply_out force_out retry
  product=$(wp2 eval '$id=(int) wc_get_product_id_by_sku("CONF-WIDGET-1"); echo $id;')
  require_fixture_ids product
  order=$(wp2 eval "
\$product = wc_get_product($product);
if (!\$product) { throw new RuntimeException('WooCommerce deletion fixture product is absent'); }
\$order = wc_create_order(); \$order->add_product(\$product, 1); \$order->calculate_totals(); \$order->set_status('processing'); \$order->save(); echo \$order->get_id();")
  require_fixture_ids order
  lookup_before=0
  for _ in $(seq 1 8); do
    wp2 action-scheduler run >/dev/null
    lookup_before=$(wp2 db query "SELECT COUNT(*) FROM wp_wc_order_product_lookup WHERE order_id=$order AND product_id=$product" --skip-column-names)
    require_observed_nonempty "WooCommerce $version deletion order lookup count" "$lookup_before"
    [ "$lookup_before" -ge 1 ] && break
    sleep 2
  done
  [ "$lookup_before" -ge 1 ] || fail "WooCommerce $version deletion fixture did not materialize its native order-product lookup"
  product_file=$(find "$repo/state/posts/product" -type f -name '*--conformance-widget.md' -print -quit)
  [ -n "$product_file" ] || fail "WooCommerce $version deletion fixture cannot locate the captured product state"
  product_uuid=$(basename "$product_file" | cut -d- -f1-5)
  [[ "$product_uuid" =~ ^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$ ]] || fail "WooCommerce $version deletion fixture has a malformed product UUID"
  expected_hash=$(shasum -a 256 "$product_file" | awk '{print $1}')
  expected_revision=$(wp2 eval 'echo \Duo\RepositoryCompiler::compile("/siterepo", \Duo\Policy::load("/siterepo"))->revision_hash();')
  require_observed_nonempty "WooCommerce $version deletion expected revision" "$expected_revision"
  source_path="posts/product/$(basename "$product_file")"; backup="$repo/.tmp-woocommerce-product-delete.md"
  mkdir -p "$repo/state/deletions"; mv "$product_file" "$backup"
  jq -n --arg expected_hash "$expected_hash" --arg expected_revision "$expected_revision" --arg source_path "$source_path" --arg uuid "$product_uuid" \
    '{expected_hash:$expected_hash,expected_revision:$expected_revision,format:"duo-deletion/v1",kind:"post",source_path:$source_path,type:"product",uuid:$uuid}' > "$repo/state/deletions/$product_uuid.json"
  before_tree=$(git -C "$repo" status --porcelain); before_head=$(git -C "$repo" rev-parse HEAD); before_origin=$(git -C "$repo" rev-parse refs/remotes/origin/main)
  set +e
  plan_rc=0; plan_out=$(wp2 duo plan --repo=/siterepo --format=json 2>&1) || plan_rc=$?
  require_duo_answered "WooCommerce $version deletion refusal plan" json "$plan_out"
  apply_rc=0; apply_out=$(wp2 duo apply --repo=/siterepo --with-deletes --default-author=admin 2>&1) || apply_rc=$?
  require_duo_answered "WooCommerce $version deletion refusal apply" human "$apply_out"
  force_rc=0; force_out=$(wp2 duo apply --repo=/siterepo --with-deletes --force-delete-referenced --default-author=admin 2>&1) || force_rc=$?
  require_duo_answered "WooCommerce $version forced deletion refusal apply" human "$force_out"
  set -e
  [ "$plan_rc" -ne 0 ] && [ "$apply_rc" -ne 0 ] && [ "$force_rc" -ne 0 ] || fail "WooCommerce $version accepted unsupported product deletion"
  for output in "$plan_out" "$apply_out" "$force_out"; do grep -Fq 'deletion intent for post:product is unsupported' <<<"$output" || fail "WooCommerce $version deletion refusal did not name the missing capability: $output"; done
  [ "$(wp2 eval '$id=(int) wc_get_product_id_by_sku("CONF-WIDGET-1"); echo $id;')" = "$product" ] || fail "WooCommerce $version refusal changed the target product identity"
  lookup_after=$(wp2 db query "SELECT COUNT(*) FROM wp_wc_order_product_lookup WHERE order_id=$order AND product_id=$product" --skip-column-names)
  require_observed_nonempty "WooCommerce $version retained order lookup count" "$lookup_after"
  [ "$lookup_after" = "$lookup_before" ] || fail "WooCommerce $version refusal changed the native order-product lookup"
  [ "$(wp2 wc shop_order get "$order" --field=status --user=admin)" = processing ] || fail "WooCommerce $version refusal changed the target HPOS order"
  after_tree=$(git -C "$repo" status --porcelain); [ "$after_tree" = "$before_tree" ] || fail "WooCommerce $version refusal mutated the authored deletion intent"
  after_head=$(git -C "$repo" rev-parse HEAD); after_origin=$(git -C "$repo" rev-parse refs/remotes/origin/main)
  [ "$after_head" = "$before_head" ] && [ "$after_origin" = "$before_origin" ] || fail "WooCommerce $version refusal moved the disposable repository revision"
  rm "$repo/state/deletions/$product_uuid.json"; rmdir "$repo/state/deletions"; mv "$backup" "$product_file"
  retry=$(wp2 duo plan --repo=/siterepo --format=json | tail -1)
  require_duo_answered "WooCommerce $version deletion retry plan" json "$retry"
  echo "$retry" | jq -e '(.delete | length) == 0 and (.delete_conflict | length) == 0' >/dev/null || fail "WooCommerce $version deletion retry did not settle after intent removal: $retry"
  [ -z "$(git -C "$repo" status --porcelain)" ] || fail "WooCommerce $version deletion fixture did not restore its disposable repository state"
  pass "WooCommerce $version product deletion refuses before product/order/repository mutation and retry settles"
}
