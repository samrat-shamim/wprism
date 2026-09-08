#!/usr/bin/env bash
# Author the one owned setting through Speculative Loading's own registered
# sanitizer, and prove in the same pass the two facts closed_sub_keys rests on:
# the sanitizer seals its key set and clamps out-of-enum values.
set -euo pipefail

read -r -d '' SEED_PHP <<'PHPEOF' || true
<?php
wp_set_current_user(1);

// update_option() runs sanitize_option(), which applies the
// sanitize_option_plsr_speculation_rules filter that settings.php's
// register_setting() attached to plsr_sanitize_setting(). Feeding an
// undeclared sibling plus an out-of-enum value therefore exercises the
// plugin's real closure, not a copy of it.
update_option('plsr_speculation_rules', [
    'mode' => 'prefetch',
    'eagerness' => "not-a-real-eagerness-\u{672A}\u{77E5}-\u{1F680}",
    'authentication' => 'logged_out_and_admins',
    'undeclared_sibling' => 'must-not-persist',
]);
$probe = get_option('plsr_speculation_rules');
if (array_keys($probe) !== ['mode', 'eagerness', 'authentication']) {
    throw new RuntimeException(
        'Speculative Loading sanitizer no longer seals its key set: ' . wp_json_encode(array_keys($probe))
    );
}
if ($probe['eagerness'] !== 'moderate') {
    throw new RuntimeException(
        'Speculative Loading did not clamp an out-of-enum eagerness to its default: ' . wp_json_encode($probe)
    );
}

// The authored source state. All three keys differ from
// plsr_get_setting_default() so convergence is provable on every one, and
// logged_out_and_admins keeps the plugin ENABLED for the admin user the
// conformance harness runs as (logged_out would report disabled for both a
// converged and an unconverged target, proving nothing).
$authored = ['mode' => 'prefetch', 'eagerness' => 'conservative', 'authentication' => 'logged_out_and_admins'];
update_option('plsr_speculation_rules', $authored);
$stored = plsr_get_stored_setting_value();
if ($stored !== $authored) {
    throw new RuntimeException('Speculative Loading did not persist the authored setting: ' . wp_json_encode($stored));
}
if ($stored === plsr_get_setting_default()) {
    throw new RuntimeException('the authored source setting is indistinguishable from the plugin default');
}
if (!plsr_is_speculative_loading_enabled()) {
    throw new RuntimeException('Speculative Loading reports disabled for the admin the harness runs as');
}

global $wpdb;
$row = $wpdb->get_row(
    "SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = 'plsr_speculation_rules'",
    ARRAY_A
);
if (!is_array($row)) {
    throw new RuntimeException('Speculative Loading authored setting did not reach wp_options');
}

echo wp_json_encode([
    'autoload' => $row['autoload'],
    'config' => wp_get_speculation_rules_configuration(),
    'enabled' => plsr_is_speculative_loading_enabled(),
    'keys' => array_keys($stored),
    'stored' => $stored,
]);
PHPEOF

SEED_FILE="${CONF_REPO1:-siterepo/conf1}/.tmp-speculation-rules-seed.php"
printf '%s' "$SEED_PHP" > "$SEED_FILE"
SEED_OUT=$(wp_conf1 eval-file /siterepo/.tmp-speculation-rules-seed.php)
require_observed_nonempty "Speculative Loading source seed" "$SEED_OUT"
SEED_JSON=$(printf '%s\n' "$SEED_OUT" | awk 'NF { line=$0 } END { print line }')
printf '%s\n' "$SEED_JSON" | jq -e '
  .stored.mode == "prefetch" and .stored.eagerness == "conservative" and
  .stored.authentication == "logged_out_and_admins" and
  .keys == ["mode", "eagerness", "authentication"] and
  .enabled == true and
  .config.mode == "prefetch" and .config.eagerness == "conservative"
' >/dev/null || fail "Speculative Loading source seed did not land: $SEED_JSON"
rm -f "$SEED_FILE"
pass "Speculative Loading setting authored through its own sanitizer, which sealed an undeclared sibling out and clamped an out-of-enum value, and the result reaches core's speculation configuration"
