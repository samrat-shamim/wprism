#!/usr/bin/env bash
# Exact-artifact PMPro authoring fixture. Every authored table is populated
# through the plugin API used by its own admin screens (or the same $wpdb
# operation where PMPro exposes no wrapper); runtime and environment rows are
# deliberately present so the target-sovereignty checks have a real negative.
set -euo pipefail

read -r -d '' SEED_PHP <<'PHPEOF' || true
<?php
global $wpdb;

function wprism_pmpro_level(array $values): PMPro_Membership_Level {
    $level = new PMPro_Membership_Level();
    foreach ($values as $key => $value) {
        $level->{$key} = $value;
    }
    $level->save();
    if (empty($level->id)) {
        throw new RuntimeException('PMPro failed to create membership level ' . ($values['name'] ?? '<unnamed>'));
    }
    return $level;
}

$generated = pmpro_generatePages([
    'account' => 'Membership Account',
    'billing' => 'Membership Billing',
    'cancel' => 'Membership Cancel',
    'checkout' => 'Membership Checkout',
    'confirmation' => 'Membership Confirmation',
    'invoice' => 'Membership Orders',
    'levels' => 'Membership Levels',
    'login' => 'Log In',
    'member_profile_edit' => 'Your Profile',
]);

$term = wp_insert_term('PMPro Members 東京 🚀', 'category', ['slug' => 'pmpro-members-category']);
if (is_wp_error($term)) {
    throw new RuntimeException($term->get_error_message());
}
$categoryId = (int) $term['term_id'];

$long = str_repeat('Portable PMPro 東京 🚀 | commas, colons: and quotes "stay data". ', 320);
$builder = wprism_pmpro_level([
    'name' => 'Builder 東京 🚀', 'description' => $long, 'confirmation' => 'Builder confirmation 東京 🚀',
    'initial_payment' => 19.95, 'billing_amount' => 7.25, 'cycle_number' => 2, 'cycle_period' => 'Month',
    'billing_limit' => 12, 'trial_amount' => 1.25, 'trial_limit' => 2, 'allow_signups' => 1,
    'expiration_number' => 2, 'expiration_period' => 'Year', 'categories' => [$categoryId],
]);
$agency = wprism_pmpro_level([
    'name' => 'Agency Plan', 'description' => 'Agency recurring plan', 'confirmation' => 'Agency confirmation',
    'initial_payment' => 99.99, 'billing_amount' => 49.50, 'cycle_number' => 1, 'cycle_period' => 'Month',
    'billing_limit' => 0, 'trial_amount' => 0, 'trial_limit' => 0, 'allow_signups' => 1,
    'expiration_number' => 0, 'expiration_period' => '', 'categories' => [],
]);
$deleteProbe = wprism_pmpro_level([
    'name' => 'Unsafe Delete Probe', 'description' => 'Parent deletion must refuse', 'confirmation' => '',
    'initial_payment' => 0, 'billing_amount' => 0, 'cycle_number' => 0, 'cycle_period' => 'Month',
    'billing_limit' => 0, 'trial_amount' => 0, 'trial_limit' => 0, 'allow_signups' => 0,
    'expiration_number' => 0, 'expiration_period' => '', 'categories' => [],
]);

update_pmpro_membership_level_meta($builder->id, 'confirmation_in_email', '1');
update_pmpro_membership_level_meta($builder->id, 'enable_avatars', '1');
update_pmpro_membership_level_meta($builder->id, 'membership_account_message', $long);
// Remote Stripe product ids are exact admitted PMPro keys but environment
// values. Their source sentinel must never enter canonical state.
update_pmpro_membership_level_meta($builder->id, 'stripe_product_id', 'prod_SOURCE_SECRET_4f709');

$groupId = pmpro_create_level_group('Portable Offers 東京', true, 7);
$secondGroupId = pmpro_create_level_group('Secondary Offers', false, 11);
if (!$groupId || !$secondGroupId) {
    throw new RuntimeException('PMPro failed to create level groups');
}
pmpro_add_level_to_group($builder->id, $groupId);
pmpro_add_level_to_group($agency->id, $secondGroupId);

$discount = new PMPro_Discount_Code();
$discount->code = 'WPRISM-PORTABLE-25';
$discount->starts = '2025-01-02';
$discount->expires = '2035-12-30';
$discount->uses = 125;
$discount->levels = [
    $builder->id => ['initial_payment' => 3.75, 'billing_amount' => 5.50, 'cycle_number' => 2, 'cycle_period' => 'Month', 'billing_limit' => 10, 'trial_amount' => 0.50, 'trial_limit' => 1, 'expiration_number' => 18, 'expiration_period' => 'Month'],
    $agency->id => ['initial_payment' => 44.25, 'billing_amount' => 33.75, 'cycle_number' => 1, 'cycle_period' => 'Month', 'billing_limit' => 0, 'trial_amount' => 0, 'trial_limit' => 0, 'expiration_number' => 0, 'expiration_period' => ''],
];
$savedDiscount = $discount->save();
if (!$savedDiscount || empty($discount->id)) {
    throw new RuntimeException('PMPro failed to create the discount graph');
}
$wpdb->update($wpdb->pmpro_discount_codes, ['one_use_per_user' => 1], ['id' => $discount->id], ['%d'], ['%d']);

$restrictedPageId = wp_insert_post([
    'post_title' => 'PMPro Restricted 東京 🚀', 'post_name' => 'pmpro-restricted', 'post_status' => 'publish',
    'post_type' => 'page', 'post_content' => 'PRIVATE-PMPRO-CONTENT-東京-🚀',
]);
$categoryPostId = wp_insert_post([
    'post_title' => 'PMPro Category Restricted', 'post_name' => 'pmpro-category-restricted', 'post_status' => 'publish',
    'post_type' => 'post', 'post_content' => 'CATEGORY-PRIVATE-PMPRO-CONTENT', 'post_category' => [$categoryId],
]);
if (!$restrictedPageId || !$categoryPostId || is_wp_error($restrictedPageId) || is_wp_error($categoryPostId)) {
    throw new RuntimeException('PMPro fixture posts could not be created');
}
pmpro_update_post_level_restrictions($restrictedPageId, [$builder->id, $agency->id]);

update_option('pmpro_currency', 'JPY');
update_option('pmpro_business_address', ['name' => 'WPrism 東京 Office', 'street' => "1-2-3 Portable\nSuite | 4", 'city' => '東京', 'state' => 'Tokyo', 'zip' => '100-0001', 'country' => 'JP', 'phone' => '+81-03-0000-0000']);
update_option('pmpro_colors', ['base' => '#112233', 'accent' => '#aabbcc', 'contrast' => '#fefefe']);
update_option('pmpro_level_order', implode(',', [$agency->id, $builder->id, $deleteProbe->id]));
update_option('pmpro_hideadslevels', implode(',', [$builder->id, $agency->id]));
update_option('pmpro_nonmembertext', 'Members only 東京 🚀');
update_option('pmpro_notloggedintext', 'Sign in to see this portable content.');
update_option('pmpro_email_checkout_paid_subject', 'Portable checkout 東京 🚀');
update_option('pmpro_email_checkout_paid_body', $long);

foreach ([
    'pmpro_gateway' => 'stripe', 'pmpro_gateway_environment' => 'live',
    'pmpro_stripe_secretkey' => 'sk_live_SOURCE_SECRET_91a8', 'pmpro_stripe_publishablekey' => 'pk_live_SOURCE_MARKER',
    'pmpro_cloudflare_turnstile_secret_key' => 'turnstile_SOURCE_SECRET', 'pmpro_license_key' => 'license_SOURCE_SECRET',
    'pmpro_email_checkout_paid_to' => 'source-recipient@example.test', 'pmpro_use_ssl' => '1',
] as $name => $value) {
    update_option($name, $value);
}
update_option('pmpro_updates', ['source-runtime' => 1700000001]);

$sourceUser = wp_create_user('pmpro_source_member', 'source-member-password', 'source-member@example.test');
if (is_wp_error($sourceUser) || !pmpro_changeMembershipLevel($builder->id, (int) $sourceUser)) {
    throw new RuntimeException('PMPro failed to create source runtime membership');
}
$sourceOrder = new MemberOrder();
$sourceOrder->code = 'SOURCE-RUNTIME-ORDER';
$sourceOrder->user_id = (int) $sourceUser;
$sourceOrder->membership_id = (int) $builder->id;
$sourceOrder->status = 'success';
$sourceOrder->gateway = 'check';
$sourceOrder->gateway_environment = 'sandbox';
$sourceOrder->subtotal = 19.95;
$sourceOrder->total = 19.95;
$sourceOrder->timestamp = '2025-01-03 04:05:06';
if (!$sourceOrder->saveOrder()) {
    throw new RuntimeException('PMPro failed to create source runtime order');
}

$source = [
    'agency' => (int) $agency->id, 'builder' => (int) $builder->id, 'category' => $categoryId,
    'category_post' => (int) $categoryPostId, 'delete_probe' => (int) $deleteProbe->id,
    'discount' => (int) $discount->id, 'group' => (int) $groupId, 'restricted_page' => (int) $restrictedPageId,
    'runtime_user' => (int) $sourceUser, 'second_group' => (int) $secondGroupId,
];
file_put_contents('/siterepo/.tmp-pmpro-source.json', wp_json_encode($source, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo wp_json_encode(['generated_pages' => count((array) $generated), 'source' => $source], JSON_UNESCAPED_UNICODE) . "\n";
PHPEOF

printf '%s' "$SEED_PHP" > "${CONF_REPO1:-siterepo/conf1}/.tmp-pmpro-seed.php"
PM_OUT=$(wp_conf1 eval-file /siterepo/.tmp-pmpro-seed.php)
rm -f "${CONF_REPO1:-siterepo/conf1}/.tmp-pmpro-seed.php"
require_observed_nonempty "conf1 PMPro seed output" "$PM_OUT"
printf '%s\n' "$PM_OUT"
echo "paid-memberships-pro seed: full authored table graph, structured options, env sentinels, and source-only runtime state created through PMPro 3.8 APIs"
