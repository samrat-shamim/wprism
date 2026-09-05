woocommerce_pii_redaction_witnesses() {
  # One grep-safe value witness for every finite allow_pii grant. Country is
  # physically VARCHAR(2), so QZ is the exact reserved two-byte sentinel; all
  # other grants use a longer grant-specific marker.
  cat <<'EOF'
option:pickup_location_pickup_locations	WPRA19-PICKUP-01
option:woocommerce_default_country	QZ:WPRA19-DEFAULT-02
option:woocommerce_email_from_name	WPRA19-EMAIL-FROM-03
option:woocommerce_email_reply_to_name	WPRA19-EMAIL-REPLY-04
option:woocommerce_pos_store_address	WPRA19-POS-ADDRESS-05
option:woocommerce_pos_store_email	wpra19-pos-email-06@agency.example
option:woocommerce_pos_store_phone	+999-WPRA19-PHONE-07
option:woocommerce_store_address	WPRA19-STORE-ADDRESS-08
option:woocommerce_store_address_2	WPRA19-STORE-ADDRESS2-09
option:woocommerce_store_city	WPRA19-STORE-CITY-10
option:woocommerce_store_postcode	WPRA19-POSTCODE-11
post_meta:customer_email	wpra19-coupon-email-12@agency.example
table:woocommerce_tax_rates.tax_rate_country	QZ
table:woocommerce_tax_rates.tax_rate_state	WPRA19TAXSTATE14
EOF
}

woocommerce_assert_pii_log_redacted() { # <label> <log>
  local label="$1" log="$2" grant witness observed=0
  while IFS=$'\t' read -r grant witness; do
    [ -n "$grant" ] && [ -n "$witness" ] \
      || fail "$label has a malformed WPRA-019 redaction witness row"
    observed=$((observed + 1))
    ! grep -Fq -- "$witness" "$log" \
      || fail "$label exposed the source value witness for $grant"
  done < <(woocommerce_pii_redaction_witnesses)
  [ "$observed" -eq 14 ] \
    || fail "$label did not inspect the closed 14-grant WPRA-019 redaction roster"
}

woocommerce_native_pii_fingerprints() { # <wp1|wp2>
  local side="$1"
  "$side" eval '
global $wpdb;
$coupon = new WC_Coupon("CONF-WELCOME10");
$taxId = (int) $wpdb->get_var(
    "SELECT tax_rate_id FROM {$wpdb->prefix}woocommerce_tax_rates " .
    "WHERE tax_rate_name=\"Conformance CA Sales Tax\""
);
$tax = $taxId > 0 && method_exists(WC_Tax::class, "_get_tax_rate")
    ? WC_Tax::_get_tax_rate($taxId)
    : null;
if (!$coupon->get_id() || !is_array($tax)) {
    throw new RuntimeException("WPRA-019 native observer premise is incomplete");
}
$readOption = static function (string $name): array {
    $absent = new stdClass();
    $value = get_option($name, $absent);
    return $value === $absent
        ? ["state" => "absent"]
        : ["state" => "present", "value" => $value];
};
$values = [
    "option:pickup_location_pickup_locations" => $readOption("pickup_location_pickup_locations"),
    "option:woocommerce_default_country" => $readOption("woocommerce_default_country"),
    "option:woocommerce_email_from_name" => $readOption("woocommerce_email_from_name"),
    "option:woocommerce_email_reply_to_name" => $readOption("woocommerce_email_reply_to_name"),
    "option:woocommerce_pos_store_address" => $readOption("woocommerce_pos_store_address"),
    "option:woocommerce_pos_store_email" => $readOption("woocommerce_pos_store_email"),
    "option:woocommerce_pos_store_phone" => $readOption("woocommerce_pos_store_phone"),
    "option:woocommerce_store_address" => $readOption("woocommerce_store_address"),
    "option:woocommerce_store_address_2" => $readOption("woocommerce_store_address_2"),
    "option:woocommerce_store_city" => $readOption("woocommerce_store_city"),
    "option:woocommerce_store_postcode" => $readOption("woocommerce_store_postcode"),
    "post_meta:customer_email" => array_values($coupon->get_email_restrictions("edit")),
    "table:woocommerce_tax_rates.tax_rate_country" => (string) ($tax["tax_rate_country"] ?? ""),
    "table:woocommerce_tax_rates.tax_rate_state" => (string) ($tax["tax_rate_state"] ?? ""),
];
$canonicalize = static function (mixed $value) use (&$canonicalize): mixed {
    if (!is_array($value)) { return $value; }
    if (array_is_list($value)) { return array_map($canonicalize, $value); }
    ksort($value, SORT_STRING);
    foreach ($value as $key => $entry) { $value[$key] = $canonicalize($entry); }
    return $value;
};
$out = [];
foreach ($values as $grant => $value) {
    if (str_starts_with($grant, "option:")) {
        if (!is_array($value) || !in_array($value["state"] ?? null, ["absent", "present"], true)) {
            throw new RuntimeException("WPRA-019 native option observer returned a malformed record");
        }
        if ($value["state"] === "absent") {
            if (count($value) !== 1) {
                throw new RuntimeException("WPRA-019 absent native option carried a value");
            }
            $out[$grant] = hash("sha256", "wprism-woo-option-record\0absent");
            continue;
        }
        if (count($value) !== 2 || !array_key_exists("value", $value)) {
            throw new RuntimeException("WPRA-019 present native option lacks a value");
        }
        $value = $value["value"];
    }
    $out[$grant] = hash("sha256", wp_json_encode(
        $canonicalize($value),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    ));
}
echo wp_json_encode($out, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
'
}

woocommerce_write_pii_profile() { # <wp1|wp2> <source|target>
  local side="$1" profile="$2"
  "$side" eval '
global $wpdb;
$profile = '"'"$profile"'"';
$profiles = [
    "baseline" => [
        "emails" => ["buyer@example.test", "*@agency.example.test"],
        "tax" => ["country" => "US", "state" => "CA"],
        "options" => [
            "pickup_location_pickup_locations" => [[
                "name" => "<strong>مخزن</strong> 東京",
                "address" => ["address_1" => "١٢ شارع الاختبار", "city" => "東京", "state" => "13", "postcode" => "100-0001", "country" => "JP"],
                "details" => "<em>بوابة ٢</em><br>南口",
                "enabled" => true,
            ]],
            "woocommerce_default_country" => "US:CA",
            "woocommerce_email_from_name" => null,
            "woocommerce_email_reply_to_name" => "",
            "woocommerce_pos_store_address" => "",
            "woocommerce_pos_store_email" => "admin@example.test",
            "woocommerce_pos_store_phone" => "",
            "woocommerce_store_address" => "",
            "woocommerce_store_address_2" => "",
            "woocommerce_store_city" => "",
            "woocommerce_store_postcode" => "",
        ],
    ],
    "source" => [
        "emails" => ["wpra19-coupon-email-12@agency.example", "*@wpra19-coupon-domain-12.example"],
        "tax" => ["country" => "QZ", "state" => "WPRA19TAXSTATE14"],
        "options" => [
            "pickup_location_pickup_locations" => [[
                "name" => "WPRA19-PICKUP-01",
                "address" => ["address_1" => "Pickup address", "city" => "Pickup city", "state" => "PS", "postcode" => "P01", "country" => "QZ"],
                "details" => "Pickup details",
                "enabled" => true,
            ]],
            "woocommerce_default_country" => "QZ:WPRA19-DEFAULT-02",
            "woocommerce_email_from_name" => "WPRA19-EMAIL-FROM-03",
            "woocommerce_email_reply_to_name" => "WPRA19-EMAIL-REPLY-04",
            "woocommerce_pos_store_address" => "WPRA19-POS-ADDRESS-05",
            "woocommerce_pos_store_email" => "wpra19-pos-email-06@agency.example",
            "woocommerce_pos_store_phone" => "+999-WPRA19-PHONE-07",
            "woocommerce_store_address" => "WPRA19-STORE-ADDRESS-08",
            "woocommerce_store_address_2" => "WPRA19-STORE-ADDRESS2-09",
            "woocommerce_store_city" => "WPRA19-STORE-CITY-10",
            "woocommerce_store_postcode" => "WPRA19-POSTCODE-11",
        ],
    ],
    "target" => [
        "emails" => ["wpra19-target@agency.example", "*@wpra19-target.example"],
        "tax" => ["country" => "CA", "state" => "ON"],
        "options" => [
            "pickup_location_pickup_locations" => [[
                "name" => "WPRA19 Target Pickup",
                "address" => ["address_1" => "29 Target Street", "city" => "Toronto Target", "state" => "ON", "postcode" => "M5V 2T6", "country" => "CA"],
                "details" => "WPRA19 target loading door",
                "enabled" => true,
            ]],
            "woocommerce_default_country" => "CA:ON",
            "woocommerce_email_from_name" => "WPRA19 Target Commerce",
            "woocommerce_email_reply_to_name" => "WPRA19 Target Support",
            "woocommerce_pos_store_address" => "WPRA19 Target POS Address",
            "woocommerce_pos_store_email" => "wpra19-target-pos@agency.example",
            "woocommerce_pos_store_phone" => "+1-416-555-0190",
            "woocommerce_store_address" => "29 Target Street",
            "woocommerce_store_address_2" => "Target Suite 19",
            "woocommerce_store_city" => "Toronto Target",
            "woocommerce_store_postcode" => "M5V 2T6",
        ],
    ],
];
$selected = $profiles[$profile] ?? null;
if (!is_array($selected)) { throw new RuntimeException("unknown WPRA-019 profile"); }
foreach ($selected["options"] as $name => $value) {
    if ($value === null) {
        delete_option($name);
        if (get_option($name, false) !== false) {
            throw new RuntimeException("WPRA-019 native option delete readback disagrees for " . $name);
        }
        continue;
    }
    update_option($name, $value);
    if (get_option($name) !== $value) {
        throw new RuntimeException("WPRA-019 native option readback disagrees for " . $name);
    }
}
$coupon = new WC_Coupon("CONF-WELCOME10");
if (!$coupon->get_id()) { throw new RuntimeException("WPRA-019 coupon premise is absent"); }
$coupon->set_email_restrictions($selected["emails"]);
$coupon->save();
if (array_values($coupon->get_email_restrictions("edit")) !== $selected["emails"]) {
    throw new RuntimeException("WPRA-019 native coupon readback disagrees");
}
$taxId = (int) $wpdb->get_var(
    "SELECT tax_rate_id FROM {$wpdb->prefix}woocommerce_tax_rates " .
    "WHERE tax_rate_name=\"Conformance CA Sales Tax\""
);
if ($taxId < 1 || !method_exists(WC_Tax::class, "_get_tax_rate")
    || !method_exists(WC_Tax::class, "_update_tax_rate")) {
    throw new RuntimeException("WPRA-019 native tax API premise is absent");
}
$tax = WC_Tax::_get_tax_rate($taxId);
if (!is_array($tax)) { throw new RuntimeException("WPRA-019 native tax read failed"); }
$tax["tax_rate_country"] = $selected["tax"]["country"];
$tax["tax_rate_state"] = $selected["tax"]["state"];
WC_Tax::_update_tax_rate($taxId, $tax);
$tax = WC_Tax::_get_tax_rate($taxId);
if (!is_array($tax)
    || ($tax["tax_rate_country"] ?? null) !== $selected["tax"]["country"]
    || ($tax["tax_rate_state"] ?? null) !== $selected["tax"]["state"]) {
    throw new RuntimeException("WPRA-019 native tax readback disagrees");
}
echo "ok";
' | grep -qx ok || fail "WooCommerce WPRA-019 $profile native write/read failed"
}

woocommerce_assert_pii_fingerprint_map() { # <label> <json>
  local label="$1" observed="$2" grants
  grants=$(php "$package_tests/../fixtures/woocommerce-pii-fingerprints.php" --grants)
  jq -en --argjson observed "$observed" --argjson grants "$grants" '
    ($observed | keys | sort) == ($grants | sort)
    and ($observed | all(.[]; type == "string" and test("^[a-f0-9]{64}$")))
  ' >/dev/null || fail "$label did not independently fingerprint the closed 14-grant WPRA-019 roster"
}

check_woocommerce_allow_pii_roundtrip() { # <exact-version> <exact-target-artifact>
  local version="$1" target_artifact="$2" source_repo="siterepo/${PAIR}1" target_repo="siterepo/${PAIR}2"
  local source_before source_native_before source_native source_captured target_before target_divergent
  local target_applied target_reinstalled target_recaptured source_restored target_restored
  local pii_revision pii_commit pii_capture_log pii_apply_log pii_diff package_tests
  package_tests="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"

  source_before=$(php "$package_tests/../fixtures/woocommerce-pii-fingerprints.php" "$source_repo/state")
  woocommerce_assert_pii_fingerprint_map "WooCommerce $version pre-mutation capture" "$source_before"
  source_native_before=$(woocommerce_native_pii_fingerprints wp1)
  [ "$source_native_before" = "$source_before" ] \
    || fail "WooCommerce $version WPRA-019 source preimage disagreed with its canonical capture"
  woocommerce_write_pii_profile wp1 source
  source_native=$(woocommerce_native_pii_fingerprints wp1)
  woocommerce_assert_pii_fingerprint_map "WooCommerce $version source native readback" "$source_native"
  pii_capture_log="$source_repo/.tmp-woo-pii-capture.log"
  wp1 wprism capture --repo=/siterepo >"$pii_capture_log" 2>&1
  source_captured=$(php "$package_tests/../fixtures/woocommerce-pii-fingerprints.php" "$source_repo/state")
  woocommerce_assert_pii_fingerprint_map "WooCommerce $version source captured values" "$source_captured"
  [ "$source_captured" = "$source_native" ] \
    || fail "WooCommerce $version captured WPRA-019 values did not equal exact native source readback"
  jq -en --argjson before "$source_before" --argjson after "$source_captured" '
    ($before | keys) == ($after | keys) and ($before | keys | all(.[]; . as $key | $before[$key] != $after[$key]))
  ' >/dev/null || fail "WooCommerce $version capture did not change every WPRA-019 value fingerprint"

  target_before=$(woocommerce_native_pii_fingerprints wp2)
  [ "$target_before" = "$source_before" ] \
    || fail "WooCommerce $version WPRA-019 target preimage disagreed with the canonical source"
  woocommerce_write_pii_profile wp2 target
  target_divergent=$(woocommerce_native_pii_fingerprints wp2)
  woocommerce_assert_pii_fingerprint_map "WooCommerce $version divergent target native readback" "$target_divergent"
  jq -en --argjson source "$source_native" --argjson target "$target_divergent" '
    ($source | keys) == ($target | keys) and ($source | keys | all(.[]; . as $key | $source[$key] != $target[$key]))
  ' >/dev/null || fail "WooCommerce $version WPRA-019 source/target profiles were not divergent for all grants"

  woocommerce_assert_pii_log_redacted "WooCommerce $version WPRA-019 capture output" "$pii_capture_log"
  rm "$pii_capture_log"
  "${GIT1[@]}" add -A
  "${GIT1[@]}" commit -qm "capture: woocommerce $version WPRA-019 finite PII matrix"
  pii_commit=$(git -C "$source_repo" rev-parse HEAD)
  "${GIT1[@]}" push -q origin main
  git -C "$target_repo" pull -q origin main
  pii_revision=$(git -C "$target_repo" rev-parse HEAD)
  pii_apply_log="$target_repo/.tmp-woo-pii-apply.log"
  wp2 wprism apply --repo=/siterepo --default-author=admin --revision="$pii_revision" --force-theirs >"$pii_apply_log" 2>&1
  assert_no_php_diagnostics "WooCommerce $version WPRA-019 apply" "$pii_apply_log"
  assert_wprism_required_environment "WooCommerce $version WPRA-019 apply" human "$(<"$pii_apply_log")"
  grep -q 'canary clean' "$pii_apply_log" || fail "WooCommerce $version WPRA-019 apply was not canary-clean"
  target_applied=$(woocommerce_native_pii_fingerprints wp2)
  [ "$target_applied" = "$source_native" ] \
    || fail "WooCommerce $version target native WPRA-019 readback did not equal the source fingerprint map"

  woocommerce_assert_pii_log_redacted "WooCommerce $version WPRA-019 apply output" "$pii_apply_log"
  rm "$pii_apply_log"

  wp2 plugin deactivate woocommerce >/dev/null
  wp2 plugin delete woocommerce >/dev/null
  wp2 plugin install "$target_artifact" --activate >/dev/null
  [ "$(wp2 plugin get woocommerce --field=version)" = "$version" ] \
    || fail "WooCommerce $version WPRA-019 exact-artifact reinstall changed the plugin boundary"
  target_reinstalled=$(woocommerce_native_pii_fingerprints wp2)
  [ "$target_reinstalled" = "$source_native" ] \
    || fail "WooCommerce $version WPRA-019 values changed across exact-artifact reinstall"

  wp2 wprism capture --repo=/siterepo --out=/siterepo/.tmp-woo-pii-final >/dev/null
  target_recaptured=$(php "$package_tests/../fixtures/woocommerce-pii-fingerprints.php" "$target_repo/.tmp-woo-pii-final")
  [ "$target_recaptured" = "$source_captured" ] \
    || fail "WooCommerce $version WPRA-019 recapture fingerprints changed after exact-artifact reinstall"
  pii_diff=$(diff -rq "$source_repo/state" "$target_repo/.tmp-woo-pii-final" || true)
  rm -rf "$target_repo/.tmp-woo-pii-final"
  [ -z "$pii_diff" ] || fail "WooCommerce $version WPRA-019 target recapture was not byte-identical"

  # The next lifecycle and version-boundary legs must start from the exact
  # portable fixture they certify. Revert the evidence commit, then restore
  # the finite native fixture through its owning Woo APIs and prove the
  # original closed 14-grant fingerprint map. Absence is not generic deletion
  # authority, so the baseline profile explicitly removes its one absent
  # authored option instead of asking Apply to infer that intent.
  "${GIT1[@]}" revert --no-edit "$pii_commit" >/dev/null
  "${GIT1[@]}" push -q origin main
  woocommerce_write_pii_profile wp1 baseline
  source_restored=$(woocommerce_native_pii_fingerprints wp1)
  [ "$source_restored" = "$source_before" ] \
    || fail "WooCommerce $version WPRA-019 source restore did not recover the exact preimage"
  git -C "$target_repo" pull -q origin main
  woocommerce_write_pii_profile wp2 baseline
  target_restored=$(woocommerce_native_pii_fingerprints wp2)
  [ "$target_restored" = "$source_before" ] \
    || fail "WooCommerce $version WPRA-019 target restore did not recover the exact preimage"
  pass "WooCommerce $version independently applies all 14 finite WPRA-019 grants, preserves them across exact-artifact reinstall, redacts output, and recaptures byte-identically"
  pass "WooCommerce $version restores the exact pre-WPRA-019 fixture before deletion and lifecycle evidence"
}

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
  local package_tests
  package_tests="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
  . "$(dirname "${BASH_SOURCE[0]}")/../conformance/seed.sh"
  unset -f wp_conf1 wp_env
}

postdeploy_woocommerce_content() {
  wp_conf2() { wp2 "$@"; }
  local package_tests
  package_tests="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
  . "$(dirname "${BASH_SOURCE[0]}")/../conformance/postdeploy.sh"
  unset -f wp_conf2
  woocommerce_preapply_authority_assertion "$WOO_VERSION" 'exact boundary'
}

postapply_woocommerce_content() {
  wp_conf2() { wp2 "$@"; }
  local CONF_REPO2="siterepo/${PAIR}2"
  local package_tests
  package_tests="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
  . "$(dirname "${BASH_SOURCE[0]}")/../conformance/postapply.sh"
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
  local APPLY_JSON="${WOOCOMMERCE_BOUNDARY_PROVIDER_RECEIPT:-}"
  local package_tests
  package_tests="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
  . "$(dirname "${BASH_SOURCE[0]}")/../conformance/check.sh"
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
      "options" => "SELECT option_name,option_value,autoload FROM {$wpdb->options} WHERE option_name IN (\"wprism_target_environment_neighbor\",\"pickup_location_pickup_locations\",\"woocommerce_calc_taxes\",\"woocommerce_maybe_regenerate_images_hash\",\"woocommerce_paypal_settings\",\"woocommerce_pickup_location_settings\",\"woocommerce_price_num_decimals\",\"woocommerce_thumbnail_cropping\",\"woocommerce_thumbnail_cropping_custom_height\",\"woocommerce_thumbnail_cropping_custom_width\",\"woocommerce_thumbnail_image_width\") ORDER BY option_name",
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
      "target_session" => "SELECT * FROM {$wpdb->prefix}woocommerce_sessions WHERE session_key=\"wprism-target-runtime-session\" ORDER BY session_id",
      "target_action" => "SELECT a.* FROM {$wpdb->prefix}actionscheduler_actions a WHERE a.hook=\"wprism_woo_target_runtime_probe\" ORDER BY a.action_id",
      "target_action_group" => "SELECT g.* FROM {$wpdb->prefix}actionscheduler_groups g INNER JOIN {$wpdb->prefix}actionscheduler_actions a ON a.group_id=g.group_id WHERE a.hook=\"wprism_woo_target_runtime_probe\" ORDER BY g.group_id",
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

woocommerce_downgrade_wprism_storage_hash() {
  # A code-drift refusal is read-only for WPrism-owned persistence.  Project every
  # prefixed ledger table and the option namespace instead of assuming the
  # current migration's table list; an added ledger table must therefore enter
  # this witness automatically.
  wp2 eval '
    global $wpdb;
    $like = $wpdb->esc_like($wpdb->prefix . "wprism_") . "%";
    $tables = $wpdb->get_col($wpdb->prepare("SHOW TABLES LIKE %s", $like));
    if (!is_array($tables)) { throw new RuntimeException("WPrism storage table discovery did not return an array"); }
    sort($tables, SORT_STRING);
    $state = ["tables" => [], "options" => []];
    foreach ($tables as $table) {
      if (!is_string($table) || preg_match("/^[A-Za-z0-9_]+$/D", $table) !== 1) {
        throw new RuntimeException("WPrism storage table name is not a safe SQL identifier");
      }
      $wpdb->last_error = "";
      $columns = $wpdb->get_col("SHOW COLUMNS FROM `{$table}`", 0);
      if ($wpdb->last_error !== "" || !is_array($columns) || $columns === []) {
        throw new RuntimeException("WPrism storage column discovery failed for " . $table);
      }
      foreach ($columns as $column) {
        if (!is_string($column) || preg_match("/^[A-Za-z0-9_]+$/D", $column) !== 1) {
          throw new RuntimeException("WPrism storage column name is not a safe SQL identifier");
        }
      }
      $order = implode(",", array_map(static fn(string $column): string => "`{$column}`", $columns));
      $wpdb->last_error = "";
      $rows = $wpdb->get_results("SELECT * FROM `{$table}` ORDER BY {$order}", ARRAY_A);
      if ($wpdb->last_error !== "" || !is_array($rows)) {
        throw new RuntimeException("WPrism storage read failed for " . $table);
      }
      if (count($rows) > 50000) { throw new RuntimeException("WPrism storage projection exceeded its fixture bound"); }
      $state["tables"][$table] = $rows;
    }
    $wpdb->last_error = "";
    $state["options"] = $wpdb->get_results(
      "SELECT option_name,option_value,autoload FROM {$wpdb->options} WHERE option_name REGEXP \"^(wprism_|_transient(_timeout)?_wprism_)\" ORDER BY option_name",
      ARRAY_A
    );
    if ($wpdb->last_error !== "" || !is_array($state["options"])) {
      throw new RuntimeException("WPrism option storage read failed");
    }
    if (count($state["options"]) > 50000) { throw new RuntimeException("WPrism option projection exceeded its fixture bound"); }
    echo hash("sha256", wp_json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
  '
}

woocommerce_downgrade_runtime_hash() {
  # The artifact replacement itself is outside this assertion.  Once the exact
  # 11.0.0 archive is active, ordinary commands may inspect but must not alter
  # runtime activation or scheduled work while refusing the stale code witness.
  wp2 eval '
    $wprism_cron = [];
    foreach (_get_cron_array() as $timestamp => $hooks) {
      foreach ($hooks as $hook => $events) {
        if (is_string($hook) && str_starts_with($hook, "wprism_")) {
          $wprism_cron[$timestamp][$hook] = $events;
        }
      }
    }
    $state = [
      "active_plugins" => get_option("active_plugins", []),
      "stylesheet" => get_stylesheet(),
      "template" => get_template(),
      "wprism_cron" => $wprism_cron,
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
  storage=$(woocommerce_downgrade_wprism_storage_hash)
  native=$(woocommerce_boundary_storage_hash)
  runtime=$(woocommerce_downgrade_runtime_hash)
  repository=$(woocommerce_downgrade_repository_hash)
  require_observed_nonempty 'WooCommerce downgrade WPrism storage witness' "$storage"
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
$policy = \WPrism\Policy::load("/siterepo");
$sources = $policy->adapter_sources();
$loaded = [];
foreach ($policy->manifests as $manifest) {
    $name = (string) ($manifest["name"] ?? "");
    if ($name === "") {
        throw new RuntimeException("WooCommerce pre-apply authority loaded an unnamed manifest");
    }
    $file = $sources->file($name, $policy->adapter_library());
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
    "format" => "wprism-woocommerce-preapply-authority/v1",
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
        "rule" => ["class" => "authored", "plain_data" => true, "allow_pii" => true, "autoload" => "preserve"],
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
    and .[0].format == "wprism-woocommerce-preapply-authority/v1"
    and (.[0].loaded_manifests | map(.name) == ["core", "woocommerce"])
    and (.[0].loaded_manifests | all(.source == "shipped" and (.file | type == "string" and length > 0) and (.path | type == "string" and length > 0) and (.sha256 | test("^[0-9a-f]{64}$"))))
    and .[0].rules["option:pickup_location_pickup_locations"] == {rule:{class:"authored",plain_data:true,allow_pii:true,autoload:"preserve"},source:"woocommerce"}
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
    || fail "WooCommerce 11.0.1 to 11.0.0 $operation refusal mutated WPrism storage, runtime, or repository state"
}

check_woocommerce_in_range_downgrade() { # <exact-11.0.0-source-artifact> <exact-11.0.0-target-artifact>
  local source_artifact="$1" target_artifact="$2" expected_sha snapshot plan_out plan_json plan_rc
  local deploy_out deploy_rc apply_out apply_rc revision settled source_price target_price
  local downgrade_compare_out downgrade_compare_rc
  local package_tests
  local mutation_note='WooCommerce 11.0.1 to 11.0.0 downgrade 東京 🚀'
  package_tests="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"

  say 'in-range downgrade: populated woocommerce 11.0.1 -> exact 11.0.0 refuses until explicit re-baseline'
  # Installation has its own activation/migration effects, so establish the
  # no-mutation baseline only after the exact verified archives replace 11.0.1.
  wp1 plugin install "$source_artifact" --force --activate >/dev/null
  wp2 plugin install "$target_artifact" --force --activate >/dev/null
  expected_sha=$(artifact_library_jq -r '.plugins.woocommerce["11.0.0"].sha256')
  [[ "$expected_sha" =~ ^[0-9a-f]{64}$ ]] || fail 'WooCommerce 11.0.0 downgrade artifact has no exact lock digest'
  [ "$(wp1 eval "echo hash_file('sha256','$source_artifact');")" = "$expected_sha" ] \
    && [ "$(wp2 eval "echo hash_file('sha256','$target_artifact');")" = "$expected_sha" ] \
    || fail 'WooCommerce in-range downgrade did not retain the exact 11.0.0 artifacts'
  [ "$(wp1 plugin get woocommerce --field=version)" = 11.0.0 ] \
    && [ "$(wp2 plugin get woocommerce --field=version)" = 11.0.0 ] \
    || fail 'WooCommerce in-range downgrade did not install exact 11.0.0 on both environments'
  snapshot=$(woocommerce_downgrade_refusal_snapshot)

  plan_rc=0
  plan_out=$(wp2 wprism plan --repo=/siterepo --format=json 2>&1) || plan_rc=$?
  require_wprism_answered 'WooCommerce 11.0.1 to 11.0.0 downgrade plan' json "$plan_out"
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
  deploy_out=$(wp2 wprism deploy --repo=/siterepo 2>&1) || deploy_rc=$?
  require_wprism_answered 'WooCommerce 11.0.1 to 11.0.0 downgrade deploy refusal' human "$deploy_out"
  [ "$deploy_rc" -ne 0 ] && grep -q 'code_drift' <<<"$deploy_out" \
    && grep -q '11.0.1' <<<"$deploy_out" && grep -q '11.0.0' <<<"$deploy_out" \
    || fail "WooCommerce in-range downgrade deploy did not refuse at the exact code-drift boundary: $deploy_out"
  assert_woocommerce_downgrade_refusal_unchanged deploy "$snapshot"

  revision=$(git -C "siterepo/${PAIR}2" rev-parse HEAD)
  apply_rc=0
  apply_out=$(wp2 wprism apply --repo=/siterepo --adopt-by-slug=terms,posts --default-author=admin --revision="$revision" 2>&1) || apply_rc=$?
  require_wprism_answered 'WooCommerce 11.0.1 to 11.0.0 downgrade apply refusal' human "$apply_out"
  [ "$apply_rc" -ne 0 ] && grep -q 'code_drift' <<<"$apply_out" \
    && grep -q '11.0.1' <<<"$apply_out" && grep -q '11.0.0' <<<"$apply_out" \
    || fail "WooCommerce in-range downgrade apply did not refuse at the exact code-drift boundary: $apply_out"
  assert_woocommerce_downgrade_refusal_unchanged apply "$snapshot"
  pass 'WooCommerce 11.0.1 -> 11.0.0 ordinary plan identifies code_drift; deploy/apply refuse without WPrism storage, runtime, or repository mutation'

  local forced_source forced_target
  forced_source=$(wp1 wprism deploy --repo=/siterepo --force-code-drift 2>&1)
  forced_target=$(wp2 wprism deploy --repo=/siterepo --force-code-drift 2>&1)
  normalize_woocommerce_harness_placeholder_mode wp2
  grep -q 'FORCED past code_drift' <<<"$forced_source" \
    && grep -q 'FORCED past code_drift' <<<"$forced_target" \
    || fail "WooCommerce explicit downgrade re-baseline did not report both forced decisions: source=$forced_source target=$forced_target"
  settled=$(wp2 wprism plan --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
  require_wprism_answered 'WooCommerce 11.0.1 to 11.0.0 plan after explicit re-baseline' json "$settled"
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
  wp1 wprism capture --repo=/siterepo
  wp1 wprism lint --repo=/siterepo
  "${GIT1[@]}" add -A
  "${GIT1[@]}" commit -qm 'capture: woocommerce 11.0.1 to 11.0.0 in-range downgrade'
  "${GIT1[@]}" push -q origin main

  git -C "siterepo/${PAIR}2" pull -q origin main
  revision=$(git -C "siterepo/${PAIR}2" rev-parse HEAD)
  woocommerce_preapply_authority_assertion 11.0.0 'in-range downgrade'
  wp2 wprism apply --repo=/siterepo --adopt-by-slug=terms,posts --default-author=admin --revision="$revision" 2>&1 | tee "$VMATRIX_APPLY_LOG"
  assert_version_matrix_apply_ready
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
  settled=$(wp2 wprism plan --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
  require_wprism_answered 'WooCommerce exact 11.0.0 plan after downgrade mutation' json "$settled"
  jq -e '([.create,.update,.drift,.conflict,.collision,.delete,.delete_conflict,.code_mismatch,.code_drift,.incomplete_apply,.incomplete_lifecycle,.regen_pending,.regen_context] | map(length) | add) == 0' <<<"$settled" >/dev/null \
    || fail "WooCommerce exact 11.0.0 plan did not settle after downgrade mutation: $settled"
  wp2 wprism capture --repo=/siterepo --out=/siterepo/.tmp-woo-downgrade-final >/dev/null
  downgrade_compare_rc=0
  downgrade_compare_out=$(php "$package_tests/../fixtures/woocommerce-downgrade-recapture.php" \
    "siterepo/${PAIR}1/state" \
    "siterepo/${PAIR}2/.tmp-woo-downgrade-final" 2>&1) || downgrade_compare_rc=$?
  rm -rf "siterepo/${PAIR}2/.tmp-woo-downgrade-final"
  [ "$downgrade_compare_rc" -eq 0 ] \
    || fail "WooCommerce 11.0.1 to 11.0.0 in-range downgrade recapture diverged outside declared derived product timestamps: $downgrade_compare_out"
  pass 'WooCommerce 11.0.1 -> 11.0.0 explicit re-baseline applies exact DECIMAL catalog mutation, settles, and recaptures modulo exact derived product timestamps'
}

check_woocommerce_boundary_lifecycle() { # <exact-version> <verified-artifact>
  local version="$1" artifact="$2" expected_sha before_native reactivated_native
  local before_uninstall absent_after_uninstall missing_before missing_after missing_rc missing_out
  local lifecycle_diff lifecycle_diff_rc unexpected_lifecycle_diff
  # Both official boundary artifacts carry byte-identical uninstall.php bytes
  # (sha256 e06e0c2086f695d39f5d9edead87cd4faeb0ea45184d77e7d8fe5588abfde48e):
  # native runtime hooks are cleared unconditionally, while catalog/options/
  # tables are removed only when the operator sets WC_REMOVE_ALL_DATA=true.
  expected_sha=$(artifact_library_jq -r --arg version "$version" '.plugins.woocommerce[$version].sha256')
  [[ "$expected_sha" =~ ^[0-9a-f]{64}$ ]] || fail "WooCommerce $version lifecycle artifact has no exact lock digest"
  [ "$(wp2 eval "echo hash_file('sha256','$artifact');")" = "$expected_sha" ] || fail "WooCommerce $version lifecycle artifact digest moved before reinstall proof"
  [ "$(wp2 eval 'echo defined("WC_REMOVE_ALL_DATA") && true === WC_REMOVE_ALL_DATA ? "yes" : "no";')" = no ] || fail "WooCommerce $version default-retention lifecycle premise unexpectedly enables destructive cleanup"
  before_native=$(woocommerce_boundary_observation)
  require_observed_nonempty "WooCommerce $version native state before deactivation" "$before_native"
  wp2 plugin deactivate woocommerce >/dev/null
  wp2 plugin is-active woocommerce >/dev/null 2>&1 && fail "WooCommerce $version deactivation premise did not land"
  wp2 wprism deploy --repo=/siterepo >/dev/null
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
  missing_out=$(wp2 wprism deploy --repo=/siterepo 2>&1) || missing_rc=$?
  require_wprism_answered "WooCommerce $version deploy with code absent" human "$missing_out"
  [ "$missing_rc" -ne 0 ] && grep -Eq 'code_mismatch|missing_in_code|is not installed' <<<"$missing_out" || fail "WooCommerce $version missing-code deploy did not refuse at compatibility: $missing_out"
  missing_after=$(woocommerce_boundary_storage_hash)
  [ "$missing_after" = "$missing_before" ] || fail "WooCommerce $version missing-code refusal partially changed retained storage"
  wp2 plugin install "$artifact" --force >/dev/null
  [ "$(wp2 plugin get woocommerce --field=version)" = "$version" ] || fail "WooCommerce exact reinstall reported the wrong version at $version"
  wp2 wprism deploy --repo=/siterepo >/dev/null
  wp2 plugin is-active woocommerce >/dev/null || fail "WooCommerce $version exact reinstall was not active after deploy"
  normalize_woocommerce_harness_placeholder_mode wp2
  check_woocommerce_content
  wp2 wprism capture --repo=/siterepo --out=/siterepo/.tmp-woo-lifecycle-final >/dev/null
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

check_woocommerce_product_deletion() { # <exact-version>
  local version="$1" repo="siterepo/${PAIR}2" source_repo="siterepo/${PAIR}1"
  local product order lookup_before lookup_after product_file product_uuid expected_hash expected_revision source_path backup
  local before_tree after_tree before_head after_head before_origin after_origin plan_rc apply_rc plan_out apply_out retry
  local disposable_sku disposable_slug disposable_source disposable_target disposable_file disposable_uuid
  local revision delete_file delete_plan delete_apply final_plan final_diff residue creation_apply

  # First prove the ordinary guard posture on a product referenced by a native
  # HPOS order.  The plan must describe the blocked deletion, and apply must
  # refuse without requiring an operator to risk the force override.
  product=$(wp2 eval '$id=(int) wc_get_product_id_by_sku("CONF-EXTERNAL-1"); echo $id;')
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
  product_file=$(find "$repo/state/posts/product" -type f -name '*--conformance-external-partner.md' -print -quit)
  [ -n "$product_file" ] || fail "WooCommerce $version deletion fixture cannot locate the captured product state"
  product_uuid=$(basename "$product_file" | cut -d- -f1-5)
  [[ "$product_uuid" =~ ^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$ ]] || fail "WooCommerce $version deletion fixture has a malformed product UUID"
  # External-product URLs are deliberately materialized for the target host,
  # so its last-synced base is not the source file's canonical byte hash. Bind
  # the target-side intent to the ledger base that plan compares with the live
  # target; otherwise a legitimate guard probe is only a stale-base conflict.
  expected_hash=$(wp2 db query "SELECT content_hash FROM wp_wprism_state WHERE uuid='$product_uuid'" --skip-column-names | tr -d '[:space:]')
  require_observed_nonempty "WooCommerce $version deletion expected target base" "$expected_hash"
  [[ "$expected_hash" =~ ^[0-9a-f]{64}$ ]] || fail "WooCommerce $version deletion expected target base is malformed"
  expected_revision=$(wp2 eval 'echo \WPrism\RepositoryCompiler::compile("/siterepo", \WPrism\Policy::load("/siterepo"))->revision_hash();')
  require_observed_nonempty "WooCommerce $version deletion expected revision" "$expected_revision"
  source_path="posts/product/$(basename "$product_file")"; backup="$repo/.tmp-woocommerce-product-delete.md"
  mkdir -p "$repo/state/deletions"; mv "$product_file" "$backup"
  jq -n --arg expected_hash "$expected_hash" --arg expected_revision "$expected_revision" --arg source_path "$source_path" --arg uuid "$product_uuid" \
    '{expected_hash:$expected_hash,expected_revision:$expected_revision,format:"wprism-deletion/v1",kind:"post",source_path:$source_path,type:"product",uuid:$uuid}' > "$repo/state/deletions/$product_uuid.json"
  before_tree=$(git -C "$repo" status --porcelain); before_head=$(git -C "$repo" rev-parse HEAD); before_origin=$(git -C "$repo" rev-parse refs/remotes/origin/main)
  set +e
  plan_rc=0; plan_out=$(wp2 wprism plan --repo=/siterepo --format=json 2>&1) || plan_rc=$?
  require_wprism_answered "WooCommerce $version referenced deletion plan" json "$plan_out"
  apply_rc=0; apply_out=$(wp2 wprism apply --repo=/siterepo --with-deletes --default-author=admin 2>&1) || apply_rc=$?
  require_wprism_answered "WooCommerce $version referenced deletion apply" human "$apply_out"
  set -e
  [ "$plan_rc" -eq 0 ] || fail "WooCommerce $version referenced product plan failed instead of reporting its guard: $plan_out"
  echo "$plan_out" | tail -1 | jq -e --arg uuid "$product_uuid" \
    '[.delete[]? | select(.uuid == $uuid and (.blocked // "") != "")] | length == 1' >/dev/null \
    || fail "WooCommerce $version referenced product plan omitted its blocking guard: $plan_out"
  [ "$apply_rc" -ne 0 ] && grep -Fq 'deletes blocked by referential guards' <<<"$apply_out" \
    || fail "WooCommerce $version referenced product apply did not refuse at its guard: $apply_out"
  [ "$(wp2 eval '$id=(int) wc_get_product_id_by_sku("CONF-EXTERNAL-1"); echo $id;')" = "$product" ] || fail "WooCommerce $version refusal changed the target product identity"
  lookup_after=$(wp2 db query "SELECT COUNT(*) FROM wp_wc_order_product_lookup WHERE order_id=$order AND product_id=$product" --skip-column-names)
  require_observed_nonempty "WooCommerce $version retained order lookup count" "$lookup_after"
  [ "$lookup_after" = "$lookup_before" ] || fail "WooCommerce $version refusal changed the native order-product lookup"
  [ "$(wp2 wc shop_order get "$order" --field=status --user=admin)" = processing ] || fail "WooCommerce $version refusal changed the target HPOS order"
  after_tree=$(git -C "$repo" status --porcelain); [ "$after_tree" = "$before_tree" ] || fail "WooCommerce $version refusal mutated the authored deletion intent"
  after_head=$(git -C "$repo" rev-parse HEAD); after_origin=$(git -C "$repo" rev-parse refs/remotes/origin/main)
  [ "$after_head" = "$before_head" ] && [ "$after_origin" = "$before_origin" ] || fail "WooCommerce $version refusal moved the disposable repository revision"
  rm "$repo/state/deletions/$product_uuid.json"; rmdir "$repo/state/deletions"; mv "$backup" "$product_file"
  retry=$(wp2 wprism plan --repo=/siterepo --format=json | tail -1)
  require_wprism_answered "WooCommerce $version deletion retry plan" json "$retry"
  echo "$retry" | jq -e '(.delete | length) == 0 and (.delete_conflict | length) == 0' >/dev/null || fail "WooCommerce $version deletion retry did not settle after intent removal: $retry"
  [ -z "$(git -C "$repo" status --porcelain)" ] || fail "WooCommerce $version deletion fixture did not restore its disposable repository state"
  pass "WooCommerce $version referenced product deletion is visibly guard-blocked before product/order/repository mutation"

  # Then prove raw local Apply cannot turn an otherwise clean product or
  # variation tombstone into a delete without the signed external exclusion.
  # The capsule's SSH extension owns the successful public promotion path.
  disposable_sku="WPRISM-DELETE-${version//./-}"
  disposable_slug=$(printf '%s' "$disposable_sku" | tr '[:upper:]' '[:lower:]')
  disposable_source=$(wp1 eval '
$sku = '"'"$disposable_sku"'"';
if (wc_get_product_id_by_sku($sku)) { throw new RuntimeException("disposable deletion SKU already exists"); }
$product = new WC_Product_Simple();
$product->set_name("WPrism disposable deletion proof");
$product->set_slug(strtolower($sku));
$product->set_sku($sku);
$product->set_regular_price("7.00");
$product->set_status("publish");
$product->save();
echo $product->get_id();')
  require_fixture_ids disposable_source
  wp1 wprism capture --repo=/siterepo >/dev/null
  disposable_file=$(find "$source_repo/state/posts/product" -type f -name "*--$disposable_slug.md" -print -quit)
  [ -n "$disposable_file" ] || fail "WooCommerce $version source capture omitted the disposable product"
  disposable_uuid=$(basename "$disposable_file" | cut -d- -f1-5)
  [[ "$disposable_uuid" =~ ^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$ ]] \
    || fail "WooCommerce $version disposable product UUID is malformed"
  "${GIT1[@]}" add -A
  "${GIT1[@]}" commit -qm "capture: woocommerce $version disposable deletion product"
  "${GIT1[@]}" push -q origin main
  git -C "$repo" pull -q origin main
  revision=$(git -C "$repo" rev-parse HEAD)
  capture_wprism_json_success creation_apply 'WooCommerce disposable product creation apply' \
    wp2 wprism apply --repo=/siterepo --default-author=admin --revision="$revision" --format=json
  assert_wprism_apply_ready 'WooCommerce disposable product creation apply' "$creation_apply"
  disposable_target=$(wp2 eval '$id=(int) wc_get_product_id_by_sku('"'"$disposable_sku"'"'); echo $id;')
  require_fixture_ids disposable_target

  disposable_target_file="$repo/state/posts/product/$(basename "$disposable_file")"
  delete_file="$repo/state/deletions/$disposable_uuid.json"
  disposable_backup="$repo/.tmp-woocommerce-local-product-delete.md"
  expected_hash=$(wp2 db query "SELECT content_hash FROM wp_wprism_state WHERE uuid='$disposable_uuid'" --skip-column-names | tr -d '[:space:]')
  expected_revision=$(wp2 eval 'echo \WPrism\RepositoryCompiler::compile("/siterepo", \WPrism\Policy::load("/siterepo"))->revision_hash();')
  [[ "$expected_hash" =~ ^[a-f0-9]{64}$ ]] && [[ "$expected_revision" =~ ^[a-f0-9]{64}$ ]] \
    || fail "WooCommerce $version local product refusal premise lacks exact hashes"
  mkdir -p "$repo/state/deletions"
  mv "$disposable_target_file" "$disposable_backup"
  jq -n --arg expected_hash "$expected_hash" --arg expected_revision "$expected_revision" \
    --arg source_path "posts/product/$(basename "$disposable_file")" --arg uuid "$disposable_uuid" \
    '{expected_hash:$expected_hash,expected_revision:$expected_revision,format:"wprism-deletion/v1",kind:"post",source_path:$source_path,type:"product",uuid:$uuid}' >"$delete_file"
  jq -e --arg uuid "$disposable_uuid" --arg source_path "posts/product/$(basename "$disposable_file")" \
    '.kind == "post" and .type == "product" and .uuid == $uuid and .source_path == $source_path' "$delete_file" >/dev/null \
    || fail "WooCommerce $version local product tombstone was malformed"
  delete_plan=$(wp2 wprism plan --repo=/siterepo --format=json | tail -1)
  require_wprism_answered "WooCommerce $version unreferenced deletion plan" json "$delete_plan"
  echo "$delete_plan" | jq -e '[.delete[]? | select(.type == "post" and .deletion_type == "product" and ((.blocked // "") == ""))] | length == 1' >/dev/null \
    || fail "WooCommerce $version unreferenced product was not one clean planned delete: $delete_plan"
  product_lookup_before=$(wp2 db query "
SELECT
  (SELECT COUNT(*) FROM wp_wc_product_meta_lookup WHERE product_id=$disposable_target) +
  (SELECT COUNT(*) FROM wp_wc_product_attributes_lookup WHERE product_id=$disposable_target OR product_or_parent_id=$disposable_target)
" --skip-column-names | tr -d '\r')
  before_tree=$(git -C "$repo" status --porcelain)
  set +e
  delete_rc=0
  delete_apply=$(wp2 wprism apply --repo=/siterepo --with-deletes --default-author=admin --format=json 2>&1) || delete_rc=$?
  set -e
  require_wprism_answered "WooCommerce $version unreferenced deletion apply" json "$delete_apply"
  [ "$delete_rc" -ne 0 ] \
    && echo "$delete_apply" | tail -1 | jq -e '.reason_code == "deletion_writer_exclusion_required"' >/dev/null \
    || fail "WooCommerce $version local product delete did not refuse for absent external exclusion"
  [ "$(wp2 eval 'echo (int) wc_get_product_id_by_sku('"'"$disposable_sku"'"');')" = "$disposable_target" ] \
    || fail "WooCommerce $version external-exclusion refusal changed the target product"
  product_lookup_after=$(wp2 db query "
SELECT
  (SELECT COUNT(*) FROM wp_wc_product_meta_lookup WHERE product_id=$disposable_target) +
  (SELECT COUNT(*) FROM wp_wc_product_attributes_lookup WHERE product_id=$disposable_target OR product_or_parent_id=$disposable_target)
" --skip-column-names | tr -d '\r')
  [ "$product_lookup_after" = "$product_lookup_before" ] \
    || fail "WooCommerce $version external-exclusion refusal changed product lookup rows"
  [ "$(git -C "$repo" status --porcelain)" = "$before_tree" ] \
    || fail "WooCommerce $version external-exclusion refusal mutated repository intent"
  rm "$delete_file"
  rmdir "$repo/state/deletions"
  mv "$disposable_backup" "$disposable_target_file"
  final_plan=$(wp2 wprism plan --repo=/siterepo --format=json | tail -1)
  require_wprism_answered "WooCommerce $version deletion settled plan" json "$final_plan"
  echo "$final_plan" | jq -e '(.delete | length) == 0 and (.delete_conflict | length) == 0' >/dev/null \
    || fail "WooCommerce $version product deletion did not settle: $final_plan"
  [ -z "$(git -C "$repo" status --porcelain)" ] \
    || fail "WooCommerce $version local product refusal fixture did not restore the target repository"
  pass "WooCommerce $version local product deletion refuses before mutation without signed external writer exclusion"

  # A variation is a distinct manifest selector and lookup identity, not
  # incidental coverage from deleting a variable parent. Drive its complete
  # lifecycle by SKU so both environments prove their independently mapped id.
  variation_parent_sku="WPRISM-VARIATION-PARENT-${version//./-}"
  variation_sku="WPRISM-DELETE-VARIATION-${version//./-}"
  variation_ids=$(wp1 eval '
$parentSku = '"'"$variation_parent_sku"'"';
$variationSku = '"'"$variation_sku"'"';
if (wc_get_product_id_by_sku($parentSku) || wc_get_product_id_by_sku($variationSku)) {
    throw new RuntimeException("disposable variation deletion SKU already exists");
}
$parent = new WC_Product_Variable();
$parent->set_name("WPrism disposable variation parent");
$parent->set_slug(strtolower($parentSku));
$parent->set_sku($parentSku);
$parent->set_status("publish");
$parentId = $parent->save();
$variation = new WC_Product_Variation();
$variation->set_parent_id($parentId);
$variation->set_sku($variationSku);
$variation->set_regular_price("8.25");
$variation->set_manage_stock(true);
$variation->set_stock_quantity(4);
$variation->set_status("publish");
$variationId = $variation->save();
echo $parentId . "|" . $variationId;')
  IFS='|' read -r variation_parent_source variation_source <<<"$variation_ids"
  require_fixture_ids variation_parent_source variation_source
  wp1 wprism capture --repo=/siterepo >/dev/null
  variation_file=$(grep -rlF -- "$variation_sku" "$source_repo/state/posts/product_variation" | head -n 1)
  [ -n "$variation_file" ] || fail "WooCommerce $version source capture omitted the named product_variation"
  variation_uuid=$(basename "$variation_file" | cut -d- -f1-5)
  [[ "$variation_uuid" =~ ^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$ ]] \
    || fail "WooCommerce $version named product_variation UUID is malformed"
  "${GIT1[@]}" add -A
  "${GIT1[@]}" commit -qm "capture: woocommerce $version named deletion variation"
  "${GIT1[@]}" push -q origin main
  git -C "$repo" pull -q origin main
  revision=$(git -C "$repo" rev-parse HEAD)
  capture_wprism_json_success creation_apply 'WooCommerce disposable variation creation apply' \
    wp2 wprism apply --repo=/siterepo --default-author=admin --revision="$revision" --format=json
  assert_wprism_apply_ready 'WooCommerce disposable variation creation apply' "$creation_apply"
  variation_parent_target=$(wp2 eval 'echo (int) wc_get_product_id_by_sku('"'"$variation_parent_sku"'"');')
  variation_target=$(wp2 eval 'echo (int) wc_get_product_id_by_sku('"'"$variation_sku"'"');')
  require_fixture_ids variation_parent_target variation_target
  variation_lookup_before=$(wp2 db query "
SELECT
  (SELECT COUNT(*) FROM wp_wc_product_meta_lookup WHERE product_id=$variation_target) +
  (SELECT COUNT(*) FROM wp_wc_product_attributes_lookup WHERE product_id=$variation_target)
" --skip-column-names | tr -d '\r')
  require_observed_nonempty "WooCommerce $version named product_variation lookup preimage" "$variation_lookup_before"
  [ "$variation_lookup_before" -ge 1 ] \
    || fail "WooCommerce $version named product_variation did not materialize a Woo lookup row"

  variation_target_file="$repo/state/posts/product_variation/$(basename "$variation_file")"
  variation_delete_file="$repo/state/deletions/$variation_uuid.json"
  variation_backup="$repo/.tmp-woocommerce-local-variation-delete.md"
  variation_expected_hash=$(wp2 db query "SELECT content_hash FROM wp_wprism_state WHERE uuid='$variation_uuid'" --skip-column-names | tr -d '[:space:]')
  variation_expected_revision=$(wp2 eval 'echo \WPrism\RepositoryCompiler::compile("/siterepo", \WPrism\Policy::load("/siterepo"))->revision_hash();')
  [[ "$variation_expected_hash" =~ ^[a-f0-9]{64}$ ]] && [[ "$variation_expected_revision" =~ ^[a-f0-9]{64}$ ]] \
    || fail "WooCommerce $version local variation refusal premise lacks exact hashes"
  mkdir -p "$repo/state/deletions"
  mv "$variation_target_file" "$variation_backup"
  jq -n --arg expected_hash "$variation_expected_hash" --arg expected_revision "$variation_expected_revision" \
    --arg source_path "posts/product_variation/$(basename "$variation_file")" --arg uuid "$variation_uuid" \
    '{expected_hash:$expected_hash,expected_revision:$expected_revision,format:"wprism-deletion/v1",kind:"post",source_path:$source_path,type:"product_variation",uuid:$uuid}' >"$variation_delete_file"
  jq -e --arg uuid "$variation_uuid" --arg source_path "posts/product_variation/$(basename "$variation_file")" '
    .format == "wprism-deletion/v1" and .kind == "post" and .type == "product_variation" and
    .uuid == $uuid and .source_path == $source_path
  ' "$variation_delete_file" >/dev/null \
    || fail "WooCommerce $version local product_variation tombstone was malformed"
  variation_plan=$(wp2 wprism plan --repo=/siterepo --format=json | tail -1)
  require_wprism_answered "WooCommerce $version named product_variation deletion plan" json "$variation_plan"
  echo "$variation_plan" | jq -e --arg uuid "$variation_uuid" '
    [.delete[]? | select(.uuid == $uuid and .type == "post" and .deletion_type == "product_variation" and ((.blocked // "") == ""))] | length == 1
  ' >/dev/null || fail "WooCommerce $version named product_variation was not one clean planned delete: $variation_plan"
  variation_before_tree=$(git -C "$repo" status --porcelain)
  set +e
  variation_apply_rc=0
  variation_apply=$(wp2 wprism apply --repo=/siterepo --with-deletes --default-author=admin --format=json 2>&1) || variation_apply_rc=$?
  set -e
  require_wprism_answered "WooCommerce $version named product_variation deletion apply" json "$variation_apply"
  [ "$variation_apply_rc" -ne 0 ] \
    && echo "$variation_apply" | tail -1 | jq -e '.reason_code == "deletion_writer_exclusion_required"' >/dev/null \
    || fail "WooCommerce $version local product_variation delete did not refuse for absent external exclusion"
  [ "$(wp2 eval 'echo (int) wc_get_product_id_by_sku('"'"$variation_sku"'"');')" = "$variation_target" ] \
    || fail "WooCommerce $version external-exclusion refusal changed the named variation"
  [ "$(wp2 eval 'echo (int) wc_get_product_id_by_sku('"'"$variation_parent_sku"'"');')" = "$variation_parent_target" ] \
    || fail "WooCommerce $version external-exclusion refusal changed the variation parent"
  variation_lookup_after=$(wp2 db query "
SELECT
  (SELECT COUNT(*) FROM wp_wc_product_meta_lookup WHERE product_id=$variation_target) +
  (SELECT COUNT(*) FROM wp_wc_product_attributes_lookup WHERE product_id=$variation_target)
" --skip-column-names | tr -d '\r')
  [ "$variation_lookup_after" = "$variation_lookup_before" ] \
    || fail "WooCommerce $version external-exclusion refusal changed variation lookup rows"
  [ "$(git -C "$repo" status --porcelain)" = "$variation_before_tree" ] \
    || fail "WooCommerce $version variation exclusion refusal mutated repository intent"
  rm "$variation_delete_file"
  rmdir "$repo/state/deletions"
  mv "$variation_backup" "$variation_target_file"
  variation_final_plan=$(wp2 wprism plan --repo=/siterepo --format=json | tail -1)
  require_wprism_answered "WooCommerce $version named product_variation settled plan" json "$variation_final_plan"
  echo "$variation_final_plan" | jq -e '(.delete | length) == 0 and (.delete_conflict | length) == 0' >/dev/null \
    || fail "WooCommerce $version product_variation deletion did not settle: $variation_final_plan"
  [ -z "$(git -C "$repo" status --porcelain)" ] \
    || fail "WooCommerce $version local variation refusal fixture did not restore the target repository"
  pass "WooCommerce $version local product_variation deletion refuses before mutation without signed external writer exclusion"
}

woocommerce_deletion_owner_agreements() {
  local active_themes observation owner_row stylesheet template theme
  local executable_owners='[]'
  stylesheet="$(wp1 option get stylesheet)" \
    || fail "WooCommerce $WOO_VERSION could not observe its active stylesheet"
  template="$(wp1 option get template)" \
    || fail "WooCommerce $WOO_VERSION could not observe its active template"
  active_themes="$(printf '%s\n' "$stylesheet" "$template" | LC_ALL=C sort -u)" \
    || fail "WooCommerce $WOO_VERSION could not order its active theme roster"
  [ -n "$active_themes" ] \
    || fail "WooCommerce $WOO_VERSION observed an empty active theme roster"
  while IFS= read -r theme; do
    [[ "$theme" =~ ^[A-Za-z0-9._-]{1,128}$ ]] && [ "$theme" != '.' ] && [ "$theme" != '..' ] \
      || fail "WooCommerce $WOO_VERSION observed a malformed active theme owner"
    observation="$(wp1 wprism executable-owner-observe "--owner=theme:$theme")" \
      || fail "WooCommerce $WOO_VERSION could not observe exact theme:$theme code"
    owner_row="$(jq -ce --arg owner "theme:$theme" --arg root "themes/$theme" \
      --arg rationale 'Exact active theme code reviewed: it persists no Woo product or variation reverse-reference identity.' '
        if keys == ["code_identity", "owner"]
          and .owner == $owner
          and .code_identity.format == "wprism-executable-tree/v1"
          and .code_identity.root == $root
          and (.code_identity.sha256 | test("^[a-f0-9]{64}$"))
        then . + {rationale: $rationale}
        else error("noncanonical executable owner observation")
        end
      ' <<<"$observation")" \
      || fail "WooCommerce $WOO_VERSION received malformed theme:$theme code identity"
    executable_owners="$(jq -ce --argjson row "$owner_row" '. + [$row]' <<<"$executable_owners")" \
      || fail "WooCommerce $WOO_VERSION could not assemble theme:$theme agreement"
  done <<<"$active_themes"

  observation="$(wp1 wprism executable-owner-observe '--owner=plugin:woocommerce/woocommerce.php')" \
    || fail "WooCommerce $WOO_VERSION could not observe exact WooCommerce code"
  owner_row="$(jq -ce --arg rationale \
    'Exact adapter-declared WooCommerce tree reviewed for this deletion boundary.' '
      if keys == ["code_identity", "owner"]
        and .owner == "plugin:woocommerce/woocommerce.php"
        and .code_identity.format == "wprism-executable-tree/v1"
        and .code_identity.root == "plugins/woocommerce"
        and (.code_identity.sha256 | test("^[a-f0-9]{64}$"))
      then . + {rationale: $rationale}
      else error("noncanonical executable owner observation")
      end
    ' <<<"$observation")" \
    || fail "WooCommerce $WOO_VERSION received malformed WooCommerce code identity"
  executable_owners="$(jq -ce --argjson row "$owner_row" '. + [$row]' <<<"$executable_owners")" \
    || fail "WooCommerce $WOO_VERSION could not assemble its WooCommerce agreement"
  printf '%s\n' "$executable_owners"
}

VMATRIX_PLUGIN_SLUG=woocommerce

version_matrix_preflight() {
  # These production-readiness legs must certify this physical checkout, not a
  # canonical sibling checkout selected by pair.sh for a linked worktree.
  [ -n "${WPRISM_EXPECTED_SOURCE_SHA:-}" ] \
    || fail "$VMATRIX_MANIFEST version-matrix evidence requires WPRISM_EXPECTED_SOURCE_SHA"
  export WPRISM_SOURCE_ROOT="$(cd .. && pwd -P)"
}

version_matrix_reset_before_empty() {
  local cli="$1"
  # WooCommerce's Review Order endpoint runs on init:4 while the prior exact
  # artifact is still active. If its feature stays enabled through `site
  # empty`, the very next reset command recreates the deleted host page at a
  # low id before postdeploy raises AUTO_INCREMENT above 2^31. Disable the
  # feature and clear its page/rewrite identities before deleting posts so a
  # consecutive exact boundary starts from the selected artifact's own
  # activation state rather than a page recreated by the preceding release.
  "$cli" eval '
    $names = [
      "woocommerce_feature_customer_review_request_enabled",
      "woocommerce_review_order_page_id",
      "woocommerce_review_order_flush_rewrite_pending",
    ];
    foreach ($names as $name) { delete_option($name); }
    foreach ($names as $name) {
      if (false !== get_option($name, false)) {
        throw new RuntimeException("version-matrix reset retained WooCommerce Review Order option " . $name);
      }
    }
  ' >/dev/null
}

version_matrix_reset_after_delete() {
  local cli="$1"
  # WooCommerce intentionally preserves its schema and setup/runtime options
  # on ordinary plugin deletion. A boundary case must exercise the selected
  # release's own installer, not inherit the preceding release's tables.
  "$cli" eval '
    global $wpdb;
    foreach (["wc\\_%", "woocommerce\\_%", "actionscheduler\\_%"] as $suffix) {
      $like = $wpdb->prefix . $suffix;
      foreach ($wpdb->get_col($wpdb->prepare("SHOW TABLES LIKE %s", $like)) as $table) {
        $safe = str_replace("`", "``", $table);
        $wpdb->query("DROP TABLE IF EXISTS `{$safe}`");
      }
    }
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\''woocommerce_%'\'' OR option_name LIKE '\''wc_%'\'' OR option_name LIKE '\''_transient_wc_%'\'' OR option_name LIKE '\''_site_transient_wc_%'\'' OR option_name LIKE '\''action_scheduler_%'\'' OR option_name IN ('\''schema-ActionScheduler_StoreSchema'\'', '\''schema-ActionScheduler_LoggerSchema'\'')");
  ' >/dev/null
}

version_matrix_workflow() {
# WooCommerce 11.0.0 is the declared minimum and 11.0.1 is the current exact
# release below the exclusive 11.0.2 bound. Certify both artifacts, then upgrade populated 11.0.0
# environments in place so a fresh 11.0.1 install is not mistaken for upgrade
# compatibility.
VMATRIX_CASES=$((VMATRIX_CASES + 1))
for WOO_VERSION in 11.0.0 11.0.1; do
  say "boundary: woocommerce $WOO_VERSION"

  reset_env wp1
  reset_env wp2
  reset_case_repositories

  say "fetch + verify woocommerce $WOO_VERSION (never a bare slug install — always a digest-checked artifact)"
  ARTIFACT_1=$(fetch_artifact woocommerce "$WOO_VERSION" cli1)
  ARTIFACT_2=$(fetch_artifact woocommerce "$WOO_VERSION" cli2)
  pass "verified sha256-pinned artifact resolved for both sides: $ARTIFACT_1"

  wp1 plugin install "$ARTIFACT_1" --activate >/dev/null
  INSTALLED_1=$(wp1 plugin get woocommerce --field=version)
  [ "$INSTALLED_1" = "$WOO_VERSION" ] || fail "side 1 installed version mismatch: expected $WOO_VERSION, got $INSTALLED_1"
  establish_woocommerce_hpos wp1 >/dev/null \
    || fail "side 1 could not establish HPOS through WooCommerce's native new-shop lifecycle"
  pass "side 1: woocommerce $WOO_VERSION installed from verified artifact, active, HPOS enabled"

  deletion_owner_agreements=$(woocommerce_deletion_owner_agreements)
  require_observed_nonempty "WooCommerce $WOO_VERSION active executable-owner code identities" "$deletion_owner_agreements"
  jq -e '
    (map(select(.owner == "plugin:woocommerce/woocommerce.php")) | length) == 1
    and (map(select(.owner | startswith("theme:"))) | length) >= 1
    and all(.[];
      (.owner | test("^(?:plugin:woocommerce/woocommerce\\.php|theme:[A-Za-z0-9._-]{1,128})$"))
      and .code_identity.format == "wprism-executable-tree/v1"
      and (.code_identity.root | test("^(?:plugins/woocommerce|themes/[A-Za-z0-9._-]{1,128})$"))
      and (.code_identity.sha256 | test("^[a-f0-9]{64}$"))
      and (.rationale | length >= 16)
    )
  ' <<<"$deletion_owner_agreements" >/dev/null \
    || fail "WooCommerce $WOO_VERSION active owner agreements were not exact v2 executable identities"

  cat > "siterepo/${PAIR}1/site.wprism.json" <<EOF
{
  "manifests": ["core", "woocommerce"],
  "policy": {
    "deletion_owner_agreements": {
      "format": "wprism-deletion-owner-agreements/v2",
      "selectors": [
        {"selector": "post:product", "owners": $deletion_owner_agreements},
        {"selector": "post:product_variation", "owners": $deletion_owner_agreements}
      ]
    },
    "options": {},
    "post_meta": {},
    "post_types": ["post", "page", "attachment", "product", "product_variation", "shop_coupon"],
    "taxonomies": ["category", "post_tag", "product_brand", "product_cat", "product_shipping_class", "product_tag", "product_type", "product_visibility"]
  },
  "spec_version": 2
}
EOF
  cp site-repo.gitignore.template "siterepo/${PAIR}1/.gitignore"
  "${GIT1[@]}" init -q -b main
  "${GIT1[@]}" remote add origin "../origin-$PAIR.git"
  "${GIT1[@]}" add -A
  "${GIT1[@]}" commit -qm "policy: woocommerce $WOO_VERSION version-boundary certification"
  "${GIT1[@]}" push -qu origin main

  seed_woocommerce_content
  wp1 wprism capture --repo=/siterepo
  pass "captured on side 1 (woocommerce $WOO_VERSION)"
  wp1 wprism lint --repo=/siterepo
  pass "lint: 0 findings"

  "${GIT1[@]}" add -A
  "${GIT1[@]}" commit -qm "capture: woocommerce $WOO_VERSION content"
  "${GIT1[@]}" push -q origin main

  clone_case_target
  wp2 plugin install "$ARTIFACT_2" >/dev/null
  INSTALLED_2=$(wp2 plugin get woocommerce --field=version)
  require_fixture_values INSTALLED_2
  [ "$INSTALLED_2" = "$WOO_VERSION" ] || fail "side 2 installed version mismatch: expected $WOO_VERSION, got $INSTALLED_2"

  wp2 wprism deploy --repo=/siterepo
  normalize_woocommerce_harness_placeholder_mode wp2
  postdeploy_woocommerce_content
  REV=$(git -C "siterepo/${PAIR}2" rev-parse HEAD)
  wp2 wprism apply --repo=/siterepo --adopt-by-slug=terms,posts --default-author=admin --revision="$REV" 2>&1 | tee "$VMATRIX_APPLY_LOG"
  assert_version_matrix_apply_ready
  grep -q 'canary clean' "$VMATRIX_APPLY_LOG" || fail "apply canary not clean at woocommerce $WOO_VERSION"
  WOOCOMMERCE_BOUNDARY_PROVIDER_RECEIPT=$(cat "$VMATRIX_APPLY_LOG")
  pass "deploy + apply succeeded on side 2 (woocommerce $WOO_VERSION, HPOS, canary clean)"

  postapply_woocommerce_content
  check_woocommerce_content

  wp2 wprism capture --repo=/siterepo --out="/siterepo/.tmp-final"
  DIFF_OUT=$(diff -rq "siterepo/${PAIR}1/state" "siterepo/${PAIR}2/.tmp-final" || true)
  rm -rf "siterepo/${PAIR}2/.tmp-final"
  [ -z "$DIFF_OUT" ] || fail "byte-identity broken at woocommerce $WOO_VERSION: $DIFF_OUT"
  pass "byte-identical recapture at woocommerce $WOO_VERSION — the exact in-range release is proven through the full product path"

  check_woocommerce_allow_pii_roundtrip "$WOO_VERSION" "$ARTIFACT_2"

  check_woocommerce_product_deletion "$WOO_VERSION"

  check_woocommerce_boundary_lifecycle "$WOO_VERSION" "$ARTIFACT_2"

  if [ "$WOO_VERSION" = 11.0.0 ]; then
    say 'in-place upgrade: populated woocommerce 11.0.0 -> exact 11.0.1 on both environments'
    UPGRADE_ARTIFACT_1=$(fetch_artifact woocommerce 11.0.1 cli1)
    UPGRADE_ARTIFACT_2=$(fetch_artifact woocommerce 11.0.1 cli2)
    wp1 plugin install "$UPGRADE_ARTIFACT_1" --force --activate >/dev/null
    [ "$(wp1 plugin get woocommerce --field=version)" = 11.0.1 ] \
      || fail 'WooCommerce source in-place upgrade did not install exact 11.0.1'
    wp1 wprism deploy --repo=/siterepo --force-code-drift >/dev/null
    wp1 eval '
      $product=wc_get_product(wc_get_product_id_by_sku("CONF-WIDGET-1"));
      if (!$product) { throw new RuntimeException("upgrade product missing"); }
      $product->set_purchase_note("WooCommerce 11.0.0 to 11.0.1 upgrade 東京 🚀");
      $product->save();
    ' >/dev/null
    wp1 wprism capture --repo=/siterepo
    wp1 wprism lint --repo=/siterepo
    "${GIT1[@]}" add -A
    "${GIT1[@]}" commit -qm 'capture: woocommerce 11.0.0 to 11.0.1 in-place upgrade'
    "${GIT1[@]}" push -q origin main

    wp2 plugin install "$UPGRADE_ARTIFACT_2" --force --activate >/dev/null
    [ "$(wp2 plugin get woocommerce --field=version)" = 11.0.1 ] \
      || fail 'WooCommerce target in-place upgrade did not install exact 11.0.1'
    git -C "siterepo/${PAIR}2" pull -q origin main
    wp2 wprism deploy --repo=/siterepo --force-code-drift >/dev/null
    normalize_woocommerce_harness_placeholder_mode wp2
    UPGRADE_REV=$(git -C "siterepo/${PAIR}2" rev-parse HEAD)
    woocommerce_preapply_authority_assertion 11.0.1 'in-place upgrade'
    wp2 wprism apply --repo=/siterepo --adopt-by-slug=terms,posts --default-author=admin --revision="$UPGRADE_REV" \
      2>&1 | tee "$VMATRIX_APPLY_LOG"
    assert_version_matrix_apply_ready
    grep -q 'canary clean' "$VMATRIX_APPLY_LOG" \
      || fail 'apply canary not clean after woocommerce 11.0.0 to 11.0.1 in-place upgrade'
    UPGRADE_PROVIDER_COUNT=$(grep -Ec 'provider capability fired:' "$VMATRIX_APPLY_LOG" || true)
    [ "$UPGRADE_PROVIDER_COUNT" -eq 1 ] \
      && grep -Eq 'provider capability fired: woocommerce-product-lookups@3\.1\.0 rebuild_product_lookups \([0-9]+(\.[0-9]+)?s, verified\)' "$VMATRIX_APPLY_LOG" \
      || fail 'WooCommerce 11.0.0 -> 11.0.1 product-note upgrade did not invoke exactly one verified product-lookup provider'
    SAVED_WOO_VERSION="$WOO_VERSION"
    WOO_VERSION=11.0.1
    check_woocommerce_content
    WOO_VERSION="$SAVED_WOO_VERSION"
    UPGRADE_NOTE=$(wp2 eval '
      $product=wc_get_product(wc_get_product_id_by_sku("CONF-WIDGET-1"));
      echo $product ? $product->get_purchase_note("edit") : "";
    ')
    [ "$UPGRADE_NOTE" = 'WooCommerce 11.0.0 to 11.0.1 upgrade 東京 🚀' ] \
      || fail "WooCommerce 11.0.1 did not preserve/apply the product authored during upgrade: $UPGRADE_NOTE"
    wp2 wprism capture --repo=/siterepo --out=/siterepo/.tmp-woo-upgrade-final
    UPGRADE_DIFF_RC=0
    UPGRADE_DIFF=$(diff -r "siterepo/${PAIR}1/state" "siterepo/${PAIR}2/.tmp-woo-upgrade-final" 2>&1) \
      || UPGRADE_DIFF_RC=$?
    [ "$UPGRADE_DIFF_RC" -le 1 ] \
      || fail "WooCommerce 11.0.0 to 11.0.1 in-place upgrade recapture comparison errored: $UPGRADE_DIFF"
    if [ -n "$UPGRADE_DIFF" ]; then
      UNEXPECTED_UPGRADE_DIFF=$(grep -Ev \
        -e '^diff -r .*/state/posts/(product|product_variation)/[^ ]+ .*/\.tmp-woo-upgrade-final/posts/(product|product_variation)/[^ ]+$' \
        -e '^[0-9]+(,[0-9]+)?c[0-9]+(,[0-9]+)?$' \
        -e '^---$' \
        -e '^[<>]     "modified(_gmt)?": "[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}",$' \
        <<<"$UPGRADE_DIFF" || true)
      [ -z "$UNEXPECTED_UPGRADE_DIFF" ] \
        || fail "WooCommerce 11.0.0 to 11.0.1 in-place upgrade recapture diverged outside declared derived product timestamps: $UPGRADE_DIFF"
    fi
    rm -rf "siterepo/${PAIR}2/.tmp-woo-upgrade-final"
    pass 'populated woocommerce 11.0.0 -> 11.0.1 upgrade preserves native catalog/API behavior, applies cleanly, and recaptures exactly modulo declared derived product timestamps'

    check_woocommerce_in_range_downgrade "$ARTIFACT_1" "$ARTIFACT_2"
  fi
done

say "negative control: woocommerce 10.9.4 (real wp.org release, closest stable below adapter-packages/woocommerce/package/manifest.json's min 11.0.0) must be REFUSED, not silently accepted"
reset_env wp1
reset_case_repositories

# Build a valid, representative WooCommerce state tree with the admitted
# 11.0.0 artifact, then swap only the installed code to 10.9.4. This keeps
# the negative control focused on Deploy::code_mismatch(), not installer
# or old-schema behavior outside the manifest's claim.
IN_RANGE_ARTIFACT=$(fetch_artifact woocommerce 11.0.0 cli1)
wp1 plugin install "$IN_RANGE_ARTIFACT" --activate >/dev/null
NEGATIVE_INSTALLED=$(wp1 plugin get woocommerce --field=version)
require_fixture_values NEGATIVE_INSTALLED
[ "$NEGATIVE_INSTALLED" = "11.0.0" ] \
  || fail "negative control premise did not install exact woocommerce 11.0.0 bytes"
establish_woocommerce_hpos wp1 >/dev/null \
  || fail "negative-control source could not establish HPOS through WooCommerce's native new-shop lifecycle"
cat > "siterepo/${PAIR}1/site.wprism.json" <<'EOF'
{
  "manifests": ["core", "woocommerce"],
  "policy": {
    "options": {},
    "post_meta": {},
    "post_types": ["post", "page", "attachment", "product", "product_variation", "shop_coupon"],
    "taxonomies": ["category", "post_tag", "product_brand", "product_cat", "product_shipping_class", "product_tag", "product_type", "product_visibility"]
  },
  "spec_version": 2
}
EOF
cp site-repo.gitignore.template "siterepo/${PAIR}1/.gitignore"
"${GIT1[@]}" init -q -b main
"${GIT1[@]}" remote add origin "../origin-$PAIR.git"
"${GIT1[@]}" add -A
"${GIT1[@]}" commit -qm "policy: woocommerce negative-control pin"
"${GIT1[@]}" push -qu origin main
seed_woocommerce_content
wp1 wprism capture --repo=/siterepo
"${GIT1[@]}" add -A
"${GIT1[@]}" commit -qm "capture: valid WooCommerce state for negative control"
"${GIT1[@]}" push -q origin main

wp1 plugin deactivate woocommerce >/dev/null
wp1 plugin delete woocommerce >/dev/null
OUT_OF_RANGE_ARTIFACT=$(fetch_artifact woocommerce 10.9.4 cli1)
wp1 plugin install "$OUT_OF_RANGE_ARTIFACT" >/dev/null
INSTALLED_OOR=$(wp1 plugin get woocommerce --field=version)
[ "$INSTALLED_OOR" = "10.9.4" ] || fail "negative control: expected woocommerce 10.9.4 installed, got $INSTALLED_OOR"

set +e
DEPLOY_OUT=$(wp1 wprism deploy --repo=/siterepo 2>&1)
DEPLOY_RC=$?
set -e
[ "$DEPLOY_RC" -ne 0 ] || fail "expected deploy to refuse woocommerce 10.9.4 as outside_version_range, but it exited 0 (got: $DEPLOY_OUT)"
grep -Eq "outside_version_range|outside the '.*' manifest's declared version_range" <<<"$DEPLOY_OUT" \
  || fail "deploy refused, but not for the expected outside_version_range reason (got: $DEPLOY_OUT)"
grep -q "woocommerce/woocommerce.php" <<<"$DEPLOY_OUT" || fail "refusal did not name the plugin (got: $DEPLOY_OUT)"
grep -q "10.9.4" <<<"$DEPLOY_OUT" || fail "refusal did not name the actually-installed version (got: $DEPLOY_OUT)"
printf '%s\n' "$DEPLOY_OUT"
pass "confirmed: woocommerce 10.9.4 (real, installed, closest stable below the declared min) is loudly refused by Deploy::code_mismatch() — the version_range pin is honest, not decorative"

say "negative control: woocommerce synthetic 11.0.2 (the exclusive upper endpoint) must be REFUSED before deploy mutates lifecycle state"
reset_env wp1
reset_case_repositories

# wp.org does not supply a published 11.0.2 archive. Start from the exact
# admitted 11.0.1 artifact and replace only its Version header in this
# disposable container. That is the narrowest executable upper-bound fixture:
# WordPress itself parses the synthetic endpoint, while every other source byte
# and the captured Woo state remain the reviewed 11.0.1 product path.
IN_RANGE_ARTIFACT=$(fetch_artifact woocommerce 11.0.1 cli1)
wp1 plugin install "$IN_RANGE_ARTIFACT" --activate >/dev/null
[ "$(wp1 plugin get woocommerce --field=version)" = "11.0.1" ] \
  || fail "upper-bound premise did not install exact WooCommerce 11.0.1 bytes"
establish_woocommerce_hpos wp1 >/dev/null \
  || fail "upper-bound source could not establish HPOS through WooCommerce's native new-shop lifecycle"
cat > "siterepo/${PAIR}1/site.wprism.json" <<'EOF'
{
  "manifests": ["core", "woocommerce"],
  "policy": {
    "options": {},
    "post_meta": {},
    "post_types": ["post", "page", "attachment", "product", "product_variation", "shop_coupon"],
    "taxonomies": ["category", "post_tag", "product_brand", "product_cat", "product_shipping_class", "product_tag", "product_type", "product_visibility"]
  },
  "spec_version": 2
}
EOF
cp site-repo.gitignore.template "siterepo/${PAIR}1/.gitignore"
"${GIT1[@]}" init -q -b main
"${GIT1[@]}" remote add origin "../origin-$PAIR.git"
"${GIT1[@]}" add -A
"${GIT1[@]}" commit -qm "policy: woocommerce exclusive-upper negative-control pin"
"${GIT1[@]}" push -qu origin main
seed_woocommerce_content
wp1 wprism capture --repo=/siterepo
"${GIT1[@]}" add -A
"${GIT1[@]}" commit -qm "capture: valid WooCommerce state for exclusive-upper negative control"
"${GIT1[@]}" push -q origin main

wp1 plugin deactivate woocommerce >/dev/null
wp1 eval '
$path = WP_PLUGIN_DIR . "/woocommerce/woocommerce.php";
$bytes = file_get_contents($path);
if (!is_string($bytes) || substr_count($bytes, " * Version: 11.0.1") !== 1) {
    throw new RuntimeException("exclusive-upper fixture did not find one 11.0.1 Version header");
}
$next = preg_replace("/^ \\* Version: 11\\.0\\.1$/m", " * Version: 11.0.2", $bytes, 1);
if (!is_string($next) || $next === $bytes || file_put_contents($path, $next) !== strlen($next)) {
    throw new RuntimeException("exclusive-upper fixture could not replace the inactive plugin Version header");
}
' >/dev/null
UPPER_INSTALLED=$(wp1 plugin get woocommerce --field=version)
[ "$UPPER_INSTALLED" = "11.0.2" ] \
  || fail "exclusive-upper fixture expected WordPress to parse WooCommerce 11.0.2, got $UPPER_INSTALLED"
PRE_REFUSAL_ACTIVE=$(wp1 option get active_plugins --format=json | tail -1)
PRE_REFUSAL_HEAD=$(git -C "siterepo/${PAIR}1" rev-parse HEAD)
PRE_REFUSAL_REPO=$(git -C "siterepo/${PAIR}1" status --porcelain)

set +e
DEPLOY_OUT=$(wp1 wprism deploy --repo=/siterepo 2>&1)
DEPLOY_RC=$?
set -e
[ "$DEPLOY_RC" -ne 0 ] || fail "expected deploy to refuse synthetic WooCommerce 11.0.2 as outside_version_range, but it exited 0 (got: $DEPLOY_OUT)"
grep -Eq "outside_version_range|outside the '.*' manifest's declared version_range" <<<"$DEPLOY_OUT" \
  || fail "exclusive-upper deploy refused, but not for outside_version_range (got: $DEPLOY_OUT)"
grep -q "woocommerce/woocommerce.php" <<<"$DEPLOY_OUT" \
  || fail "exclusive-upper refusal did not name the WooCommerce plugin (got: $DEPLOY_OUT)"
grep -q "11.0.2" <<<"$DEPLOY_OUT" \
  || fail "exclusive-upper refusal did not name WordPress's installed Version header (got: $DEPLOY_OUT)"
[ "$(wp1 option get active_plugins --format=json | tail -1)" = "$PRE_REFUSAL_ACTIVE" ] \
  || fail "exclusive-upper code mismatch changed active_plugins before refusing"
[ "$(git -C "siterepo/${PAIR}1" rev-parse HEAD)" = "$PRE_REFUSAL_HEAD" ] \
  || fail "exclusive-upper code mismatch changed the captured repository revision before refusing"
[ "$(git -C "siterepo/${PAIR}1" status --porcelain)" = "$PRE_REFUSAL_REPO" ] \
  || fail "exclusive-upper code mismatch changed the captured repository before refusing"
printf '%s\n' "$DEPLOY_OUT"
pass "confirmed: synthetic WooCommerce 11.0.2 is rejected at the exclusive upper bound before deploy changes lifecycle state or the captured repository"
}
