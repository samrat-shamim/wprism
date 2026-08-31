#!/usr/bin/env bash

set -euo pipefail
PACKAGE_ROOT="$(cd "$(dirname "$0")/../.." && pwd -P)"
export WPRISM_ARTIFACT_PACKAGE="${PACKAGE_ROOT##*/}"

# WooCommerce capsule extension for sandbox/tests/live/regress_ssh_adopt.sh.
# The shared suite owns the standalone SSH host, signed rollback authority,
# encrypted database checkpoint, and complete external writer exclusion. This
# extension adds one product tombstone and proves the public promote path rolls
# it back when that exclusion disappears at Delete's final pre-COMMIT check.

wprism_ssh_adopt_extension() {
  local woo_version="11.0.1"
  local woo_pin theme_owners product_id product_file product_base product_uuid
  local expected_hash expected_revision source_path scope_hash plan_json
  local lookup_before failed_code failed_product failed_lookup retry_code
  local status_json success_product success_lookup converged_plan
  local failure_stdout="$DIAG_DIR/woocommerce-delete-failure.stdout"
  local failure_stderr="$DIAG_DIR/woocommerce-delete-failure.stderr"
  local success_stdout="$DIAG_DIR/woocommerce-delete-success.stdout"
  local success_stderr="$DIAG_DIR/woocommerce-delete-success.stderr"
  local contract="$TMP/woocommerce-delete.scope.json"

  for diagnostic_file in "$failure_stdout" "$failure_stderr" "$success_stdout" "$success_stderr"; do
    ( umask 077; : >"$diagnostic_file" )
    chmod 0600 "$diagnostic_file"
  done

  say "install the exact WooCommerce deletion boundary on the adopted SSH target"
  ssh_fixture "cd /var/www/html && wp plugin install woocommerce --version=$woo_version --activate --quiet"
  [ "$(ssh_fixture 'cd /var/www/html && wp plugin get woocommerce --field=version')" = "$woo_version" ] \
    || fail "WooCommerce scoped-deletion extension left its exact plugin boundary"

  cat >"$TMP/woocommerce-theme-agreements.php" <<'PHP'
<?php
declare(strict_types=1);

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
echo wp_json_encode($owners, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
PHP
  scp -F "$TMP/ssh_config" "$TMP/woocommerce-theme-agreements.php" \
    wprism-adopt-fixture:/home/wprism/recovery-fixture/woocommerce-theme-agreements.php >/dev/null
  theme_owners="$(ssh_fixture 'cd /var/www/html && wp eval-file /home/wprism/recovery-fixture/woocommerce-theme-agreements.php')"
  ssh_fixture 'rm -f /home/wprism/recovery-fixture/woocommerce-theme-agreements.php'
  jq -e '
    length >= 1
    and all(.[];
      (.owner | test("^theme:[A-Za-z0-9._-]{1,128}$"))
      and .code_identity.format == "wprism-executable-tree/v1"
      and (.code_identity.root | test("^themes/[A-Za-z0-9._-]{1,128}$"))
      and (.code_identity.sha256 | test("^[a-f0-9]{64}$"))
      and (.rationale | length >= 16)
    )
  ' <<<"$theme_owners" >/dev/null \
    || fail "WooCommerce scoped-deletion extension did not bind exact active-theme code"

  woo_pin="$(ssh_fixture 'cd /var/www/html && wp wprism manifest-pin --repo=/home/wprism/site --name=woocommerce')"
  jq -e '
    .name == "woocommerce" and .source == "shipped"
    and (.digest | test("^[a-f0-9]{64}$"))
  ' <<<"$woo_pin" >/dev/null \
    || fail "WooCommerce scoped-deletion extension could not obtain the exact shipped adapter pin"
  scp -F "$TMP/ssh_config" wprism-adopt-fixture:/home/wprism/site/site.wprism.json \
    "$TMP/woocommerce-site.before.json" >/dev/null
  jq --argjson pin "$woo_pin" --argjson owners "$theme_owners" '
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
declare(strict_types=1);

$sku = 'WPRISM-SSH-DELETE-1101';
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
  product_id="$(ssh_fixture 'cd /var/www/html && wp eval-file /home/wprism/recovery-fixture/woocommerce-delete-product.php')"
  ssh_fixture 'rm -f /home/wprism/recovery-fixture/woocommerce-delete-product.php'
  [[ "$product_id" =~ ^[1-9][0-9]*$ ]] \
    || fail "WooCommerce scoped-deletion extension did not create its real product"

  "$WPRISM" --envs-file="$TMP/envs.json" capture target --format=json >"$TMP/woocommerce-delete-capture.json" \
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
    and [.delete[]? | select(.uuid == $uuid and .type == "post" and .deletion_type == "product" and ((.blocked // "") == ""))] | length == 1
  ' <<<"$plan_json" >/dev/null \
    || fail "WooCommerce scoped-deletion extension did not plan one clean product delete"
  lookup_before="$(ssh_fixture "cd /var/www/html && wp db query \"SELECT COUNT(*) FROM wp_wc_product_meta_lookup WHERE product_id=$product_id\" --skip-column-names" | tr -d '[:space:]')"
  [ "$lookup_before" -ge 1 ] \
    || fail "WooCommerce scoped-deletion product has no native lookup preimage"
  pass "WooCommerce exact adapter pin, theme identities, product, tombstone, and public scoped plan are bound"

  say "lose the external writer exclusion at Delete's final pre-COMMIT frontier"
  # The public scoped-promotion sequence performs one recovery claim verify,
  # then five target-agent witnesses: apply admission, delete-plan admission,
  # executable-owner binding, destructive-unit authorization, and the final
  # commit boundary. The sixth verify is therefore the exact last operation
  # before the authored database COMMIT.
  ssh_fixture 'printf "6\n" > /home/wprism/recovery-fixture/provider-state.json.fail-verify-after && chmod 600 /home/wprism/recovery-fixture/provider-state.json.fail-verify-after'
  if "$WPRISM" --envs-file="$TMP/envs.json" promote target --scope-contract="$contract" --with-deletes --format=json >"$failure_stdout" 2>"$failure_stderr"; then
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

  status_json="$(ssh_fixture 'php /home/wprism/site/.wprism/control/recovery-runtime/rollback-control.php authority-status --root=/home/wprism/site/.wprism/control')"
  jq -e --arg scope "$scope_hash" '
    .ok == true and .receipt_format == "wprism-scoped-promotion-receipt/v1"
    and .scope_hash == $scope and .allow_deletes == true
    and .state == "rolled_back" and .terminal == true
  ' <<<"$status_json" >/dev/null \
    || fail "WooCommerce provider-loss failure did not leave exact signed rolled-back deletion authority"
  failed_product="$(ssh_fixture 'cd /var/www/html && wp eval '\''echo (int) wc_get_product_id_by_sku("WPRISM-SSH-DELETE-1101");'\''')"
  failed_lookup="$(ssh_fixture "cd /var/www/html && wp db query \"SELECT COUNT(*) FROM wp_wc_product_meta_lookup WHERE product_id=$product_id\" --skip-column-names" | tr -d '[:space:]')"
  [ "$failed_product" = "$product_id" ] && [ "$failed_lookup" = "$lookup_before" ] \
    || fail "WooCommerce provider-loss rollback did not restore product and native lookup rows completely"
  [ -z "$(target_ledger_value promotion_lock)" ] \
    || fail "WooCommerce provider-loss rollback retained a promotion lock"
  jq -e '.state == "released"' <<<"$(ssh_fixture 'cat /home/wprism/recovery-fixture/provider-state.json')" >/dev/null \
    || fail "WooCommerce provider-loss rollback did not release the external exclusion"
  pass "final pre-COMMIT provider loss rolls back the product, lookup rows, and signed scoped generation"

  say "retry the same public WooCommerce tombstone after recovery"
  if "$WPRISM" --envs-file="$TMP/envs.json" promote target --scope-contract="$contract" --with-deletes --format=json >"$success_stdout" 2>"$success_stderr"; then
    retry_code=0
  else
    retry_code=$?
  fi
  [ "$retry_code" -eq 0 ] \
    || fail "WooCommerce scoped deletion did not succeed after the verified rollback"
  jq -e --arg scope "$scope_hash" '
    .format == "wprism-scoped-promotion-result/v1"
    and .scope_hash == $scope and .state == "committed"
    and .rollback.format == "wprism-scoped-promotion-receipt/v1"
    and .rollback.automatic_window_closed == true
    and .scoped_apply.format == "wprism-scoped-apply-result/v1"
    and .scoped_apply.verification.selected_deletions == 1
    and .scoped_apply.scoped_receipt.phase == "complete"
  ' "$success_stdout" >/dev/null \
    || fail "WooCommerce successful retry lacked its exact terminal deletion receipt"
  success_product="$(ssh_fixture 'cd /var/www/html && wp eval '\''echo (int) wc_get_product_id_by_sku("WPRISM-SSH-DELETE-1101");'\''')"
  success_lookup="$(ssh_fixture "cd /var/www/html && wp db query \"SELECT COUNT(*) FROM wp_wc_product_meta_lookup WHERE product_id=$product_id\" --skip-column-names" | tr -d '[:space:]')"
  [ "$success_product" = "0" ] && [ "$success_lookup" = "0" ] \
    || fail "WooCommerce successful retry retained product or native lookup rows"
  converged_plan="$("$WPRISM" --envs-file="$TMP/envs.json" plan target --scope-contract="$contract" --format=json)" \
    || fail "WooCommerce successful retry did not permit a converged scoped plan"
  jq -e '
    .format == "wprism-scoped-plan/v1"
    and .create == [] and .update == [] and .drift == [] and .conflict == []
    and .delete == [] and .delete_conflict == []
  ' <<<"$converged_plan" >/dev/null \
    || fail "WooCommerce successful retry did not converge the selected tombstone"
  [ -z "$(target_ledger_value promotion_lock)" ] && [ -z "$(target_ledger_value promotion_session)" ] \
    || fail "WooCommerce successful retry retained a target promotion session"
  jq -e '.state == "released"' <<<"$(ssh_fixture 'cat /home/wprism/recovery-fixture/provider-state.json')" >/dev/null \
    || fail "WooCommerce successful retry did not release the external exclusion"
  pass "public signed scoped promotion retries cleanly and deletes the exact WooCommerce product"
}
