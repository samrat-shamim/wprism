#!/usr/bin/env bash
# Paid Memberships Pro manifest conformance seed (DUO-3239 — promoting
# DUO-3235's own hand-run proof, sandbox/tests/regress_pmpro_composite_ref.sh,
# into the permanent CI-run registry). Authors PMPro's real system pages via
# its own pmpro_generatePages() setup function (the exact 9-name/title array
# regress_pmpro_composite_ref.sh already verified against this manifest's
# pinned 3.8.3 tag — this manifest's own nine pmpro_*_page_id options are
# keyed on these same names), one membership level via a direct $wpdb->insert
# (PMPro's own admin save handler, adminpages/levels/save-level.php, does
# exactly this — there is no separate public-API wrapper worth calling
# through), one levelmeta key via update_pmpro_membership_level_meta() (the
# id_column=meta_id sidecar-PK-override's proving value, task #126), and a
# real page restriction via pmpro_update_post_level_restrictions() (the
# composite_ref identity-mode's proving fact, task #125 — the code behind
# wp-admin's own "Require Membership" meta box). Mirrors the regression
# script's seed step exactly (same function calls, same shape) rather than
# inventing a second, possibly-drifted version of the same proof.
#
# Invoked by conformance/run.sh with wp_conf1/$COMPOSE already exported;
# runs from the sandbox/ directory, conf1 only, before capture.
set -euo pipefail

read -r -d '' SEED_PHP <<'PHPEOF' || true
<?php
global $wpdb;

$created = pmpro_generatePages([
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
echo "pmpro_generatePages(): " . count((array) $created) . " page(s) touched\n";

$level_id = $wpdb->insert($wpdb->pmpro_membership_levels, [
    'name' => 'Conformance Test Level',
    'description' => 'DUO-3239 conformance fixture',
    'confirmation' => 'Welcome to the conformance regression level.',
    'allow_signups' => 1,
    'initial_payment' => 9.99,
    'billing_amount' => 0,
    'cycle_number' => 0,
    'cycle_period' => 'Month',
    'billing_limit' => 0,
    'trial_amount' => 0,
    'trial_limit' => 0,
    'expiration_number' => 0,
    'expiration_period' => 'Year',
]) ? $wpdb->insert_id : 0;
if (!$level_id) {
    fwrite(STDERR, "failed to insert membership level\n");
    exit(1);
}
echo "level_id=$level_id\n";

// task #126's proving value: a real, distinctive authored string in
// pmpro_membership_levelmeta, whose real PK column is meta_id, not id.
update_pmpro_membership_level_meta($level_id, 'membership_account_message', 'DUO-3239 conformance levelmeta marker');

$page_id = wp_insert_post([
    'post_title' => 'Conformance Members Only',
    'post_name' => 'conformance-members-only',
    'post_status' => 'publish',
    'post_type' => 'page',
    'post_content' => 'Restricted content for the DUO-3239 conformance fixture.',
]);
if (!$page_id || is_wp_error($page_id)) {
    fwrite(STDERR, "failed to create the restricted page\n");
    exit(1);
}
echo "page_id=$page_id\n";

// task #125's proving fact: the REAL restriction mechanism, writing
// straight into pmpro_memberships_pages (membership_id, page_id —
// composite PK, no surrogate id column).
pmpro_update_post_level_restrictions($page_id, [$level_id]);
$restricted = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->pmpro_memberships_pages} WHERE membership_id = %d AND page_id = %d",
    $level_id, $page_id
));
if ($restricted !== 1) {
    fwrite(STDERR, "expected exactly 1 pmpro_memberships_pages row after restriction, got $restricted\n");
    exit(1);
}
echo "restriction row confirmed live: membership_id=$level_id page_id=$page_id\n";
PHPEOF
printf '%s' "$SEED_PHP" > "${CONF_REPO1:-siterepo/conf1}"/.tmp-pmpro-seed.php
PM_OUT=$(wp_conf1 eval-file /siterepo/.tmp-pmpro-seed.php)
require_observed_nonempty "conf1 PMPro seed output" "$PM_OUT"
printf '%s\n' "$PM_OUT"
rm -f "${CONF_REPO1:-siterepo/conf1}"/.tmp-pmpro-seed.php

echo "paid-memberships-pro seed: system pages generated, one level + levelmeta + one restricted page authored on conf1"
