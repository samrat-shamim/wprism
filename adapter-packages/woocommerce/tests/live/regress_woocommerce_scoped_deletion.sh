#!/usr/bin/env bash

set -euo pipefail
PACKAGE_ROOT="$(cd "$(dirname "$0")/../.." && pwd -P)"
export WPRISM_ARTIFACT_PACKAGE="${PACKAGE_ROOT##*/}"

# WooCommerce capsule extension for sandbox/tests/live/regress_ssh_adopt.sh.
# The shared suite owns the standalone SSH host, signed rollback authority,
# encrypted database checkpoint, and complete external writer exclusion. This
# extension adds one product tombstone and proves the checkpoint-only scoped
# profile stays closed while automatic full promotion rolls the deletion back
# when its exclusion disappears at Delete's final pre-COMMIT check.

wprism_ssh_adopt_extension() {
  local woo_version="${WPRISM_WOO_DELETE_VERSION:-}"
  case "$woo_version" in
    11.0.0|11.0.1) ;;
    *) fail "WPRISM_WOO_DELETE_VERSION must select exact WooCommerce 11.0.0 or 11.0.1" ;;
  esac
  local woo_suffix="${woo_version//./}"
  local woo_sku="WPRISM-SSH-DELETE-${woo_suffix}"
  local woo_pin executable_owners product_id product_file product_base product_uuid
  local expected_hash expected_revision source_path scope_hash plan_json full_plan_json scoped_code
  local lookup_before failed_code failed_product failed_lookup retry_code
  local status_json stock_topology success_product success_lookup converged_plan
  local next_generation retry_generation
  local failure_stdout="$DIAG_DIR/woocommerce-delete-failure.stdout"
  local failure_stderr="$DIAG_DIR/woocommerce-delete-failure.stderr"
  local success_stdout="$DIAG_DIR/woocommerce-delete-success.stdout"
  local success_stderr="$DIAG_DIR/woocommerce-delete-success.stderr"
  local contract="$TMP/woocommerce-delete.scope.json"

  for diagnostic_file in "$failure_stdout" "$failure_stderr" "$success_stdout" "$success_stderr"; do
    ( umask 077; : >"$diagnostic_file" )
    chmod 0600 "$diagnostic_file"
  done

  say "enroll the full upload/effect recovery providers required for deletion"
  ( umask 077; openssl rand 32 >"$TMP/woocommerce-upload.key" )
  chmod 0600 "$TMP/woocommerce-upload.key"
  scp -F "$TMP/ssh_config" \
    "$ROOT/sandbox/tests/fixtures/upload-provider.php" \
    "$ROOT/sandbox/tests/fixtures/effect-provider.php" \
    "$PACKAGE_ROOT/fixtures/plan-bound-code-release-provider.php" \
    "$TMP/woocommerce-upload.key" \
    wprism-adopt-fixture:/home/wprism/recovery-fixture/ >/dev/null
  ssh_fixture '
    chmod 700 /home/wprism/recovery-fixture/upload-provider.php /home/wprism/recovery-fixture/effect-provider.php /home/wprism/recovery-fixture/plan-bound-code-release-provider.php
    chmod 600 /home/wprism/recovery-fixture/woocommerce-upload.key
    mkdir -p /home/wprism/recovery-fixture/offload /home/wprism/recovery-fixture/media
    chmod 700 /home/wprism/recovery-fixture/offload /home/wprism/recovery-fixture/media
  '
  jq '
    .envs.target.rollback_recovery.upload_provider = [
      "/usr/local/bin/php",
      "/home/wprism/recovery-fixture/upload-provider.php",
      "/home/wprism/recovery-fixture/upload-provider-state",
      "/var/www/html/wp-content/uploads",
      "/home/wprism/recovery-fixture/offload",
      "/home/wprism/recovery-fixture/media",
      "/home/wprism/recovery-fixture/woocommerce-upload.key"
    ]
    | .envs.target.rollback_recovery.effect_provider = [
      "/usr/local/bin/php",
      "/home/wprism/recovery-fixture/effect-provider.php",
      "/home/wprism/recovery-fixture/effect-provider-state",
      "/var/www/html"
    ]
    | .envs.target.rollback_recovery.code_release_provider = [
      "/usr/local/bin/php",
      "/home/wprism/recovery-fixture/plan-bound-code-release-provider.php",
      "/home/wprism/recovery-fixture/woocommerce-code-release-state",
      "/home/wprism/code-releases",
      "/home/wprism/code-current"
    ]
  ' "$TMP/envs.json" >"$TMP/envs.full-recovery.json"
  mv "$TMP/envs.full-recovery.json" "$TMP/envs.json"
  "$WPRISM" --envs-file="$TMP/envs.json" adopt target >/dev/null \
    || fail "WooCommerce scoped-deletion extension could not enroll full recovery providers"
  pass "candidate-bound upload/effect/code-release providers are enrolled for full automatic recovery"

  say "install the exact WooCommerce deletion boundary on the adopted SSH target"
  ssh_fixture "cd /var/www/html && wp plugin install woocommerce --version=$woo_version --activate --quiet"
  [ "$(ssh_fixture 'cd /var/www/html && wp plugin get woocommerce --field=version')" = "$woo_version" ] \
    || fail "WooCommerce scoped-deletion extension left its exact plugin boundary"
  if [ "$woo_version" = "11.0.1" ]; then
    ssh_fixture 'cd /var/www/html && wp eval '\''
      if (!defined("WOOCOMMERCE_BIS_ALPHA_ENABLED")) {
          define("WOOCOMMERCE_BIS_ALPHA_ENABLED", true);
      }
      WC_Install::maybe_enable_hpos();
      WC_Install::create_tables();
      if (!\Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()) {
          throw new RuntimeException("WooCommerce deletion proof did not enable HPOS");
      }
    '\''' >/dev/null
  else
    ssh_fixture 'cd /var/www/html && wp eval '\''
      WC_Install::maybe_enable_hpos();
      WC_Install::create_tables();
      if (!\Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()) {
          throw new RuntimeException("WooCommerce deletion proof did not enable HPOS");
      }
    '\''' >/dev/null
  fi
  stock_topology="$(ssh_fixture "cd /var/www/html && wp db query \"SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND BINARY TABLE_NAME=BINARY 'wp_wc_stock_notifications'\" --skip-column-names" | tr -d '[:space:]')"
  case "$woo_version:$stock_topology" in
    11.0.0:0|11.0.1:1) ;;
    *) fail "WooCommerce $woo_version did not expose its reviewed stock-notification table topology" ;;
  esac
  pass "WooCommerce $woo_version exposes its exact reviewed stock-notification table topology ($stock_topology)"

  say "bind the exact WooCommerce and active-theme bytes to immutable code releases"
  ssh_fixture 'git -C /home/wprism/site status --porcelain=v1 --untracked-files=all | php -r '\''
    $site = false;
    $state = false;
    foreach (file("php://stdin", FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if ($line === " M site.wprism.json") {
            $site = true;
            continue;
        }
        if (preg_match("/^\\?\\? state\\/[A-Za-z0-9._\\/-]+$/D", $line) === 1) {
            $state = true;
            continue;
        }
        fwrite(STDERR, "unexpected shared repository status\n");
        exit(41);
    }
    exit($site && $state ? 0 : 42);
  '\''' || fail "WooCommerce code release setup found repository changes outside the shared captured site/state evidence"
  ssh_fixture '
    set -eu
    mkdir -p /home/wprism/site/code/wp-content/plugins /home/wprism/site/code/wp-content/themes
    cp -a /var/www/html/wp-content/plugins/woocommerce /home/wprism/site/code/wp-content/plugins/woocommerce
    stylesheet="$(cd /var/www/html && wp option get stylesheet)"
    template="$(cd /var/www/html && wp option get template)"
    for theme in "$stylesheet" "$template"; do
      case "$theme" in
        ""|*[!A-Za-z0-9._-]*) exit 41 ;;
      esac
      test -d "/var/www/html/wp-content/themes/$theme"
      if [ ! -e "/home/wprism/site/code/wp-content/themes/$theme" ]; then
        cp -a "/var/www/html/wp-content/themes/$theme" "/home/wprism/site/code/wp-content/themes/$theme"
      fi
    done
    php -r '\''
      $path = "/home/wprism/site/site.wprism.json";
      $site = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
      $site["code"] = ["format" => 1, "layout" => "wp-content", "source" => "code/wp-content"];
      file_put_contents($path, json_encode($site, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    '\''
    git -C /home/wprism/site add -- site.wprism.json state code
    git -C /home/wprism/site commit -m "Bind shared state and exact WooCommerce deletion code release" >/dev/null
    test -z "$(git -C /home/wprism/site status --porcelain)"
  ' || fail "WooCommerce deletion proof could not commit its exact code half"
  next_generation="$(ssh_fixture 'php /home/wprism/site/.wprism/control/recovery-runtime/rollback-control.php authority-status --root=/home/wprism/site/.wprism/control' | jq -r '.generation + 1')"
  [[ "$next_generation" =~ ^[1-9][0-9]*$ ]] \
    || fail "WooCommerce deletion proof could not derive its next signed generation"
  retry_generation=$((next_generation + 1))
  ssh_fixture "
    set -eu
    test ! -e /home/wprism/code-releases
    test ! -e /home/wprism/code-current
    mkdir -p /home/wprism/code-releases/release-prior
    mkdir -p /home/wprism/code-releases/release-desired-$next_generation
    mkdir -p /home/wprism/code-releases/release-desired-$retry_generation
    cp -a /home/wprism/site/code/wp-content /home/wprism/code-releases/release-prior/wp-content
    cp -a /home/wprism/site/code/wp-content /home/wprism/code-releases/release-desired-$next_generation/wp-content
    cp -a /home/wprism/site/code/wp-content /home/wprism/code-releases/release-desired-$retry_generation/wp-content
    printf '%s\\n' release-prior > /home/wprism/code-current
    chmod 600 /home/wprism/code-current
  " || fail "WooCommerce deletion proof could not stage immutable prior/failure/retry releases"
  pass "WooCommerce code inventory and immutable generations $next_generation/$retry_generation are exact and target-credential-free"

  cat >"$TMP/woocommerce-owner-agreements.php" <<'PHP'
<?php

$themes = array_values(array_unique([get_stylesheet(), get_template()]));
sort($themes, SORT_STRING);
$contentRoot = realpath(WP_CONTENT_DIR);
if (!is_string($contentRoot) || $contentRoot === '') {
    throw new RuntimeException('WooCommerce deletion agreement cannot resolve WP_CONTENT_DIR');
}
$owners = [];
foreach ($themes as $theme) {
    if (!is_string($theme) || preg_match('/^[A-Za-z0-9._-]{1,128}$/D', $theme) !== 1) {
        throw new RuntimeException('WooCommerce deletion agreement found a malformed theme owner');
    }
    $canonicalRoot = 'themes/' . $theme;
    $root = WP_CONTENT_DIR . '/' . $canonicalRoot;
    $resolved = realpath($root);
    if (!is_string($resolved) || $resolved !== $contentRoot . '/' . $canonicalRoot || is_link($root)) {
        throw new RuntimeException('WooCommerce deletion agreement found an unsafe theme root');
    }
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $entry) {
        if (!$entry instanceof SplFileInfo) {
            throw new RuntimeException('WooCommerce deletion agreement found an uninspectable theme entry');
        }
        $path = $entry->getPathname();
        $stat = lstat($path);
        $kind = is_array($stat) ? (((int) $stat['mode']) & 0170000) : 0;
        if ($kind === 0040000) {
            continue;
        }
        if ($kind !== 0100000 || $entry->isLink() || !is_readable($path)) {
            throw new RuntimeException('WooCommerce deletion agreement found a nonregular theme entry');
        }
        $relative = str_replace('\\', '/', substr($path, strlen($root) + 1));
        $files[] = ['path' => $relative, 'sha256' => hash_file('sha256', $path)];
    }
    usort($files, static fn(array $left, array $right): int => strcmp($left['path'], $right['path']));
    $payload = ['files' => $files, 'format' => 'wprism-executable-tree/v1', 'root' => $canonicalRoot];
    $owners[] = [
        'owner' => 'theme:' . $theme,
        'code_identity' => [
            'format' => 'wprism-executable-tree/v1',
            'root' => $canonicalRoot,
            'sha256' => hash('sha256', \WPrism\Canon::encode($payload)),
        ],
        'rationale' => 'Exact active theme code reviewed: it persists no Woo product reverse-reference identity.',
    ];
}
$plugin = 'woocommerce/woocommerce.php';
$canonicalRoot = 'plugins/woocommerce';
$root = WP_PLUGIN_DIR . '/woocommerce';
$resolved = realpath($root);
if (!is_string($resolved) || $resolved !== $contentRoot . '/' . $canonicalRoot
    || is_link($root) || !is_file(WP_PLUGIN_DIR . '/' . $plugin)) {
    throw new RuntimeException('WooCommerce deletion agreement found an unsafe plugin root');
}
$files = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);
foreach ($iterator as $entry) {
    if (!$entry instanceof SplFileInfo) {
        throw new RuntimeException('WooCommerce deletion agreement found an uninspectable plugin entry');
    }
    $path = $entry->getPathname();
    $stat = lstat($path);
    $kind = is_array($stat) ? (((int) $stat['mode']) & 0170000) : 0;
    if ($kind === 0040000) {
        continue;
    }
    if ($kind !== 0100000 || $entry->isLink() || !is_readable($path)) {
        throw new RuntimeException('WooCommerce deletion agreement found a nonregular plugin entry');
    }
    $relative = str_replace('\\', '/', substr($path, strlen($root) + 1));
    $files[] = ['path' => $relative, 'sha256' => hash_file('sha256', $path)];
}
usort($files, static fn(array $left, array $right): int => strcmp($left['path'], $right['path']));
$payload = ['files' => $files, 'format' => 'wprism-executable-tree/v1', 'root' => $canonicalRoot];
$owners[] = [
    'owner' => 'plugin:' . $plugin,
    'code_identity' => [
        'format' => 'wprism-executable-tree/v1',
        'root' => $canonicalRoot,
        'sha256' => hash('sha256', \WPrism\Canon::encode($payload)),
    ],
    'rationale' => 'Exact adapter-declared WooCommerce tree reviewed for this deletion boundary.',
];
echo wp_json_encode($owners, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
PHP
  scp -F "$TMP/ssh_config" "$TMP/woocommerce-owner-agreements.php" \
    wprism-adopt-fixture:/home/wprism/recovery-fixture/woocommerce-owner-agreements.php >/dev/null
  executable_owners="$(ssh_fixture 'cd /var/www/html && wp eval-file /home/wprism/recovery-fixture/woocommerce-owner-agreements.php')"
  ssh_fixture 'rm -f /home/wprism/recovery-fixture/woocommerce-owner-agreements.php'
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
  ' <<<"$executable_owners" >/dev/null \
    || fail "WooCommerce scoped-deletion extension did not bind exact active-owner code"

  woo_pin="$(ssh_fixture 'cd /var/www/html && wp wprism manifest-pin --repo=/home/wprism/site --name=woocommerce')"
  jq -e '
    .name == "woocommerce" and .source == "shipped"
    and (.digest | test("^[a-f0-9]{64}$"))
  ' <<<"$woo_pin" >/dev/null \
    || fail "WooCommerce scoped-deletion extension could not obtain the exact shipped adapter pin"
  scp -F "$TMP/ssh_config" wprism-adopt-fixture:/home/wprism/site/site.wprism.json \
    "$TMP/woocommerce-site.before.json" >/dev/null
  jq --argjson pin "$woo_pin" --argjson owners "$executable_owners" '
    .manifests = ([.manifests[]? | select((if type == "string" then . else .name end) != "woocommerce")] + [$pin])
    | .policy.post_types = (((.policy.post_types // []) + ["product", "product_variation"]) | unique)
    | .policy.taxonomies = (((.policy.taxonomies // []) + ["product_brand", "product_cat", "product_shipping_class", "product_tag", "product_type", "product_visibility"]) | unique)
    | .policy.deletion_owner_agreements = {
        format: "wprism-deletion-owner-agreements/v2",
        selectors: [
          {selector: "post:product", owners: $owners},
          {selector: "post:product_variation", owners: $owners}
        ]
      }
  ' "$TMP/woocommerce-site.before.json" >"$TMP/woocommerce-site.json"
  scp -F "$TMP/ssh_config" "$TMP/woocommerce-site.json" \
    wprism-adopt-fixture:/home/wprism/site/site.wprism.json >/dev/null

  cat >"$TMP/woocommerce-delete-product.php" <<'PHP'
<?php

$sku = getenv('WPRISM_DELETE_SKU');
if (!is_string($sku) || preg_match('/^WPRISM-SSH-DELETE-110(?:0|1)$/D', $sku) !== 1) {
    throw new RuntimeException('WooCommerce scoped-deletion SKU is missing or malformed');
}
if (wc_get_product_id_by_sku($sku)) {
    throw new RuntimeException('WooCommerce scoped-deletion product already exists');
}
$product = new WC_Product_Simple();
$product->set_name('WPrism SSH deletion proof');
$product->set_slug('wprism-ssh-deletion-proof');
$product->set_sku($sku);
$product->set_regular_price('17.00');
$product->set_manage_stock(true);
$product->set_stock_quantity(3);
$product->set_status('publish');
echo $product->save();
PHP
  scp -F "$TMP/ssh_config" "$TMP/woocommerce-delete-product.php" \
    wprism-adopt-fixture:/home/wprism/recovery-fixture/woocommerce-delete-product.php >/dev/null
  product_id="$(ssh_fixture "cd /var/www/html && WPRISM_DELETE_SKU='$woo_sku' wp eval-file /home/wprism/recovery-fixture/woocommerce-delete-product.php")"
  ssh_fixture 'rm -f /home/wprism/recovery-fixture/woocommerce-delete-product.php'
  [[ "$product_id" =~ ^[1-9][0-9]*$ ]] \
    || fail "WooCommerce scoped-deletion extension did not create its real product"

  "$WPRISM" --envs-file="$TMP/envs.json" capture target --target-branch="$TARGET_REPOSITORY_BRANCH" --format=json >"$TMP/woocommerce-delete-capture.json" \
    || fail "WooCommerce scoped-deletion extension could not capture the exact product"
  product_file="$(ssh_fixture 'find /home/wprism/site/state/posts/product -type f -name "*--wprism-ssh-deletion-proof.md" -print')"
  [ "$(wc -l <<<"$product_file" | tr -d ' ')" -eq 1 ] && [ -n "$product_file" ] \
    || fail "WooCommerce scoped-deletion extension did not capture one named product file"
  product_base="$(basename "$product_file")"
  product_uuid="${product_base:0:36}"
  [[ "$product_uuid" =~ ^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$ ]] \
    || fail "WooCommerce scoped-deletion extension captured a malformed product UUID"
  expected_hash="$(ssh_fixture "cd /var/www/html && wp db query \"SELECT content_hash FROM wp_wprism_state WHERE uuid='$product_uuid'\" --skip-column-names" | tr -d '[:space:]')"
  expected_revision="$(ssh_fixture 'cd /var/www/html && wp eval '\''echo \WPrism\RepositoryCompiler::compile("/home/wprism/site", \WPrism\Policy::load("/home/wprism/site"))->revision_hash();'\''')"
  [[ "$expected_hash" =~ ^[a-f0-9]{64}$ ]] && [[ "$expected_revision" =~ ^[a-f0-9]{64}$ ]] \
    || fail "WooCommerce scoped-deletion extension could not bind exact product preimage hashes"
  source_path="posts/product/$product_base"
  jq -n --arg expected_hash "$expected_hash" --arg expected_revision "$expected_revision" \
    --arg source_path "$source_path" --arg uuid "$product_uuid" \
    '{expected_hash:$expected_hash,expected_revision:$expected_revision,format:"wprism-deletion/v1",kind:"post",source_path:$source_path,type:"product",uuid:$uuid}' \
    >"$TMP/woocommerce-delete.json"
  ssh_fixture 'mkdir -p /home/wprism/site/state/deletions'
  ssh_fixture "mv '$product_file' '/home/wprism/recovery-fixture/$product_base.present'"
  scp -F "$TMP/ssh_config" "$TMP/woocommerce-delete.json" \
    "wprism-adopt-fixture:/home/wprism/site/state/deletions/$product_uuid.json" >/dev/null

  "$WPRISM" --envs-file="$TMP/envs.json" scope target --roots="tombstone:$product_uuid" --contract --format=json >"$contract" \
    || fail "WooCommerce scoped-deletion extension could not mint its exact tombstone contract"
  scope_hash="$(jq -r '.scope_hash' "$contract")"
  jq -e --arg uuid "$product_uuid" '
    .format == "wprism-scope-contract/v1"
    and .selectors == ["tombstone:" + $uuid]
    and (.tombstones | length == 1 and .[0].uuid == $uuid)
  ' "$contract" >/dev/null \
    || fail "WooCommerce scoped-deletion extension widened its selected tombstone"
  plan_json="$("$WPRISM" --envs-file="$TMP/envs.json" plan target --scope-contract="$contract" --format=json)" \
    || fail "WooCommerce scoped-deletion extension could not plan its public tombstone"
  jq -e --arg uuid "$product_uuid" '
    .format == "wprism-scoped-plan/v1"
    and ([.delete[]? | select(.uuid == $uuid and .type == "post" and .deletion_type == "product" and ((.blocked // "") == ""))] | length) == 1
  ' <<<"$plan_json" >/dev/null \
    || fail "WooCommerce scoped-deletion extension did not plan one clean product delete"
  if "$WPRISM" --envs-file="$TMP/envs.json" promote target --scope-contract="$contract" --with-deletes --format=json \
      >"$TMP/woocommerce-scoped-refusal.stdout" 2>"$TMP/woocommerce-scoped-refusal.stderr"; then
    scoped_code=0
  else
    scoped_code=$?
  fi
  [ "$scoped_code" -ne 0 ] \
    || fail "WooCommerce post tombstone escaped the checkpoint-only scoped profile"
  grep -Fq 'scoped_promotion_preflight_failed' \
    "$TMP/woocommerce-scoped-refusal.stdout" "$TMP/woocommerce-scoped-refusal.stderr" \
    || fail "WooCommerce checkpoint-only scoped refusal lost its typed preflight boundary"
  full_plan_json="$("$WPRISM" --envs-file="$TMP/envs.json" plan target --format=json)" \
    || fail "WooCommerce scoped-deletion extension could not plan its full automatic promotion"
  jq -e --arg uuid "$product_uuid" '
    ([.delete[]? | select(.uuid == $uuid and .type == "post" and .deletion_type == "product" and ((.blocked // "") == ""))] | length) == 1
  ' <<<"$full_plan_json" >/dev/null \
    || fail "WooCommerce full promotion did not retain one clean product delete"
  lookup_before="$(ssh_fixture "cd /var/www/html && wp db query \"SELECT COUNT(*) FROM wp_wc_product_meta_lookup WHERE product_id=$product_id\" --skip-column-names" | tr -d '[:space:]')"
  [ "$lookup_before" -ge 1 ] \
    || fail "WooCommerce scoped-deletion product has no native lookup preimage"
  pass "WooCommerce exact adapter pin, product tombstone, scoped refusal, and full automatic plan are bound"

  say "lose the external writer exclusion at Delete's final pre-COMMIT frontier"
  # The captured code revision is already live, so the full profile performs
  # five controller-side held-exclusion reads before Apply. Apply then proves
  # admission twice and re-verifies at plan, transaction, delete, and commit;
  # the eleventh verify is therefore the final operation before DB COMMIT.
  ssh_fixture 'printf "11\n" > /home/wprism/recovery-fixture/provider-state.json.fail-verify-after && chmod 600 /home/wprism/recovery-fixture/provider-state.json.fail-verify-after'
  if "$WPRISM" --envs-file="$TMP/envs.json" promote target --with-deletes >"$failure_stdout" 2>"$failure_stderr"; then
    failed_code=0
  else
    failed_code=$?
  fi
  [ "$failed_code" -ne 0 ] \
    || fail "WooCommerce scoped deletion committed after its external exclusion disappeared"
  grep -Fq 'delete commit boundary' "$failure_stdout" "$failure_stderr" \
    || fail "WooCommerce scoped deletion did not fail at the exact final pre-COMMIT exclusion frontier"
  grep -q 'rolled_back and exclusion released' "$failure_stdout" "$failure_stderr" \
    || fail "WooCommerce scoped deletion did not report complete checkpoint rollback"
  ssh_fixture 'test ! -e /home/wprism/recovery-fixture/provider-state.json.fail-verify-after' \
    || fail "WooCommerce scoped deletion did not consume its one-use provider-loss control"

  status_json="$(ssh_fixture 'php /home/wprism/site/.wprism/control/recovery-runtime/rollback-control.php active-evidence --root=/home/wprism/site/.wprism/control')"
  jq -e '
    .receipt.format == "wprism-rollback-receipt/v3"
    and .receipt.allow_deletes == true
    and .status.state == "rolled_back" and .status.terminal == true
  ' <<<"$status_json" >/dev/null \
    || fail "WooCommerce provider-loss failure did not leave exact signed rolled-back deletion authority"
  failed_product="$(ssh_fixture "cd /var/www/html && wp eval 'echo (int) wc_get_product_id_by_sku(\"$woo_sku\");'")"
  failed_lookup="$(ssh_fixture "cd /var/www/html && wp db query \"SELECT COUNT(*) FROM wp_wc_product_meta_lookup WHERE product_id=$product_id\" --skip-column-names" | tr -d '[:space:]')"
  [ "$failed_product" = "$product_id" ] && [ "$failed_lookup" = "$lookup_before" ] \
    || fail "WooCommerce provider-loss rollback did not restore product and native lookup rows completely"
  [ -z "$(target_ledger_value promotion_lock)" ] \
    || fail "WooCommerce provider-loss rollback retained a promotion lock"
  jq -e '.state == "released"' <<<"$(ssh_fixture 'cat /home/wprism/recovery-fixture/provider-state.json')" >/dev/null \
    || fail "WooCommerce provider-loss rollback did not release the external exclusion"
  pass "final pre-COMMIT provider loss rolls back the product, lookup rows, and signed full generation"

  say "retry the same public WooCommerce tombstone after recovery"
  if "$WPRISM" --envs-file="$TMP/envs.json" promote target --with-deletes >"$success_stdout" 2>"$success_stderr"; then
    retry_code=0
  else
    retry_code=$?
  fi
  [ "$retry_code" -eq 0 ] \
    || fail "WooCommerce scoped deletion did not succeed after the verified rollback"
  grep -Fq 'promote complete: verified committed receipt; traffic exclusion released' "$success_stdout" \
    || fail "WooCommerce successful retry lacked its verified committed full-recovery receipt"
  success_product="$(ssh_fixture "cd /var/www/html && wp eval 'echo (int) wc_get_product_id_by_sku(\"$woo_sku\");'")"
  success_lookup="$(ssh_fixture "cd /var/www/html && wp db query \"SELECT COUNT(*) FROM wp_wc_product_meta_lookup WHERE product_id=$product_id\" --skip-column-names" | tr -d '[:space:]')"
  [ "$success_product" = "0" ] && [ "$success_lookup" = "0" ] \
    || fail "WooCommerce successful retry retained product or native lookup rows"
  converged_plan="$("$WPRISM" --envs-file="$TMP/envs.json" plan target --format=json)" \
    || fail "WooCommerce successful retry did not permit a converged full plan"
  jq -e '
    .create == [] and .update == [] and .drift == [] and .conflict == []
    and .delete == [] and .delete_conflict == []
  ' <<<"$converged_plan" >/dev/null \
    || fail "WooCommerce successful retry did not converge the selected tombstone"
  [ -z "$(target_ledger_value promotion_lock)" ] \
    || fail "WooCommerce successful retry retained a target promotion lock"
  jq -e '.state == "released"' <<<"$(ssh_fixture 'cat /home/wprism/recovery-fixture/provider-state.json')" >/dev/null \
    || fail "WooCommerce successful retry did not release the external exclusion"
  pass "public automatic verified promotion retries cleanly and deletes the exact WooCommerce product"
}
