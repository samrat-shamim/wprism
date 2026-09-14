#!/usr/bin/env bash
# Build a hostile target after deploy: every owned family disagrees with the
# source in its stored form, the IP lists carry extra target-local records, an
# undeclared neighbouring option must survive every reconciliation, and the
# brute-force feature is switched off. Direct update_option writes are the
# point here — this is target-local divergence manufacture (a hostile operator
# or a migrated-in database), so it must NOT go through the plugin's own
# sanitizing writer the way the source seed did.
set -euo pipefail

read -r -d '' HOSTILE_PHP <<'PHPEOF' || true
<?php
wp_set_current_user(1);

// Opposite nine members in stored (seconds/checkbox) form, the hostile UTF-8
# boundary carried in the address, plus a tenth blacklisted range and a
# hostile notification template that carries neither environment's home URL.
update_option('loginizer_options', [
    'max_retries' => 9,
    'lockout_time' => 333,
    'max_lockouts' => 30,
    'lockouts_extend' => 111,
    'reset_retries' => 222,
    'notify_email' => 1,
    'notify_email_address' => "hostile-\u{672A}\u{77E5}-\u{1F680}@example.test",
    'trusted_ips' => 'off',
    'blocked_screen' => 'off',
]);
update_option('loginizer_login_mail', [
    'enable' => 0,
    'disable_whitelist' => 1,
    'html_mail' => false,
    'subject' => "hostile-subject-\u{672A}\u{77E5}-\u{1F680}",
    'body' => '<p>hostile body, not this site: https://hostile.test/locked</p>',
    'roles' => ['editor', 'subscriber'],
]);
update_option('loginizer_whitelist', [
    1 => ['start' => '172.16.0.1', 'end' => '172.16.0.254', 'time' => 1726300099],
    2 => ['start' => '172.16.9.1', 'end' => '172.16.9.254', 'time' => 1726300098],
]);
update_option('loginizer_blacklist', [
    1 => ['start' => '203.0.113.1', 'end' => '203.0.113.9', 'time' => 1726300097],
    2 => ['start' => '203.0.113.200', 'end' => '203.0.113.250', 'time' => 1726300096],
]);
update_option('loginizer_disable_brute', 1);
update_option('loginizer_target_probe', 'target-only-neighbor');

echo wp_json_encode([
    'blacklist_entries' => count(get_option('loginizer_blacklist')),
    'neighbor' => get_option('loginizer_target_probe'),
    'whitelist_entries' => count(get_option('loginizer_whitelist')),
]);
PHPEOF

HOSTILE_FILE="${CONF_REPO2:-siterepo/conf2}/.tmp-loginizer-hostile.php"
printf '%s' "$HOSTILE_PHP" > "$HOSTILE_FILE"
HOSTILE_OUT=$(wp_conf2 --user=admin eval-file /siterepo/.tmp-loginizer-hostile.php)
require_observed_nonempty "Loginizer hostile target seed" "$HOSTILE_OUT"
HOSTILE_JSON=$(printf '%s\n' "$HOSTILE_OUT" | awk 'NF { line=$0 } END { print line }')
printf '%s\n' "$HOSTILE_JSON" | jq -e '
  .whitelist_entries == 2 and .blacklist_entries == 2 and
  .neighbor == "target-only-neighbor"
' >/dev/null || fail "Loginizer hostile target premise did not land: $HOSTILE_JSON"
rm -f "$HOSTILE_FILE"
pass "Loginizer target starts with opposite settings, opposite notification template, doubled IP lists, brute force disabled and undeclared neighbouring state"
