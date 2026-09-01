#!/usr/bin/env bash
# Manufacture a hostile but valid PMPro target after deploy activation and
# before first apply. The discount code is a unique natural-key row that apply
# must adopt and update; mapped-name collision is exercised later in the check
# so the runner's pre-check byte-identity gate remains meaningful.
set -euo pipefail

read -r -d '' TARGET_PHP <<'PHPEOF' || true
<?php
global $wpdb;

// Push every target-local identity away from the source's small ids.
$wpdb->query("ALTER TABLE {$wpdb->pmpro_membership_levels} AUTO_INCREMENT = 5001");
$wpdb->query("ALTER TABLE {$wpdb->pmpro_groups} AUTO_INCREMENT = 6001");
$wpdb->query("ALTER TABLE {$wpdb->pmpro_discount_codes} AUTO_INCREMENT = 7001");
$wpdb->query("ALTER TABLE {$wpdb->posts} AUTO_INCREMENT = 10001");
$wpdb->query("ALTER TABLE {$wpdb->terms} AUTO_INCREMENT = 11001");

$discount = new PMPro_Discount_Code();
$discount->code = 'WPRISM-PORTABLE-25';
$discount->starts = '2020-01-01';
$discount->expires = '2020-01-02';
$discount->uses = 1;
$discount->levels = false;
$discount->save();
if (empty($discount->id)) {
    throw new RuntimeException('hostile target discount code was not created');
}

foreach ([
    'pmpro_gateway' => 'check', 'pmpro_gateway_environment' => 'sandbox',
    'pmpro_stripe_secretkey' => 'sk_test_TARGET_SECRET_b84c', 'pmpro_stripe_publishablekey' => 'pk_test_TARGET_MARKER',
    'pmpro_cloudflare_turnstile_secret_key' => 'turnstile_TARGET_SECRET', 'pmpro_license_key' => 'license_TARGET_SECRET',
    'pmpro_email_checkout_paid_to' => 'target-recipient@example.test', 'pmpro_use_ssl' => '0',
] as $name => $value) {
    update_option($name, $value);
}
update_option('pmpro_updates', ['target-runtime' => 1999999001]);
update_option('wprism_target_pmpro_neighbor', 'target-neighbor-preserved');
update_option('pmpro_business_address', ['name' => 'Target Office', 'street' => '99 Target Road', 'city' => 'Target City', 'state' => 'NY', 'zip' => '10001', 'country' => 'US', 'phone' => '+1 212 555 0100']);
update_option('pmpro_from_email', 'memberships-target@example.test');
update_option('pmpro_from_name', 'Target Memberships');
update_option('pmpro_tax_state', 'NY');

$targetUser = wp_create_user('pmpro_target_member', 'target-member-password', 'target-member@example.test');
if (is_wp_error($targetUser)) {
    throw new RuntimeException('target runtime user was not created');
}

$target = ['discount' => (int) $discount->id, 'runtime_user' => (int) $targetUser];
file_put_contents('/siterepo/.tmp-pmpro-target.json', wp_json_encode($target, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo wp_json_encode($target, JSON_UNESCAPED_UNICODE) . "\n";
PHPEOF

printf '%s' "$TARGET_PHP" > "${CONF_REPO2:-siterepo/conf2}/.tmp-pmpro-target.php"
TARGET_OUT=$(wp_conf2 eval-file /siterepo/.tmp-pmpro-target.php)
rm -f "${CONF_REPO2:-siterepo/conf2}/.tmp-pmpro-target.php"
require_observed_nonempty "conf2 hostile PMPro target output" "$TARGET_OUT"
printf '%s\n' "$TARGET_OUT"
