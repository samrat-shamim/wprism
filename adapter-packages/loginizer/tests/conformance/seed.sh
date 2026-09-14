#!/usr/bin/env bash
# Author the source fixture through Loginizer's own admin-page save handlers,
# not through bare update_option calls that would prove the manifest against
# the seed instead of against the plugin.
#
# brute-force.php's save blocks (max_retries..blocked_screen at :75-134, the
# login-notification template at :56-66, the IP-range writers at :270-330) all
# read $_POST behind check_admin_referer('loginizer-options') and
# current_user_can('manage_options'), reached in a real request through the
# add_menu_page callback loginizer_brute_force_settings() (main/admin.php:
# 739-742). The fixture reproduces that context exactly — administrator user,
# valid nonce, populated $_POST, and the operator's REMOTE_ADDR inside the
# whitelisted range the trusted-ips guard (brute-force.php:83-85) demands —
# and invokes the callback itself.
#
# Two invocations, in the order a real operator would click: the IP ranges
# first (trusted_ips cannot pass before the operator's address is
# whitelisted), then the settings and notification save.
set -euo pipefail

LOGINIZER_FIXTURES=/var/www/html/wp-content/mu-plugins/adapter-packages/loginizer/fixtures

capture_wprism_json_success LOGINIZER_RANGES 'Loginizer native IP-range writers' \
  wp_conf1 --require="$LOGINIZER_FIXTURES/admin-context.php" eval-file "$LOGINIZER_FIXTURES/native-options.php" save-ranges --use-include --user=admin
printf '%s' "$LOGINIZER_RANGES" | jq -e '
  .whitelist["1"].start == "10.0.0.5" and .whitelist["1"].end == "10.0.0.9"
  and .blacklist["1"].start == "192.168.7.7" and .blacklist["1"].end == "192.168.7.7"
' >/dev/null || fail "Loginizer native IP-range writers did not persist both ranges: $LOGINIZER_RANGES"

capture_wprism_json_success LOGINIZER_SAVE 'Loginizer native brute-force settings Save' \
  wp_conf1 --require="$LOGINIZER_FIXTURES/admin-context.php" eval-file "$LOGINIZER_FIXTURES/native-options.php" save-settings --use-include --user=admin
printf '%s' "$LOGINIZER_SAVE" | jq -e '
  .options.max_retries == 3 and .options.lockout_time == 900 and .options.max_lockouts == 5
  and .options.lockouts_extend == 21600 and .options.reset_retries == 43200
  and .options.notify_email == 1 and .options.notify_email_address == "admin@example.test"
  and .options.trusted_ips == "on" and .options.blocked_screen == "on"
  and (.options | length) == 9
  and .login_mail.enable == 1 and .login_mail.disable_whitelist == 0
  and .login_mail.html_mail == true
  and .login_mail.subject == "[$sitename] Failed login attempts"
  and .login_mail.roles == ["administrator"]
  and (.login_mail | length) == 6
' >/dev/null || fail "Loginizer native settings Save did not land all nine settings and six template members: $LOGINIZER_SAVE"

pass "Loginizer brute-force settings, notification template and IP ranges were authored through the plugin's own admin-page save handlers"
