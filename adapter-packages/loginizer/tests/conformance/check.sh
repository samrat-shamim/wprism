#!/usr/bin/env bash
# Production-oriented Loginizer proof. The generic harness already proved
# clean apply and byte-identical recapture; this hook adds the layers a
# byte-diff cannot see: the plugin's own boot-time configuration and access
# decisions consuming the applied rows, hostile-target convergence across
# every owned family, lifecycle recovery through deactivation, uninstall
# residue, absent-code refusal and exact reinstall, a real three-way settings
# conflict with forced convergence and idempotent retry, authorized option
# deletion, and the closed-sub-key refusal that keeps a future tenth member
# from riding along.
set -euo pipefail

LOGINIZER_FIXTURES=/var/www/html/wp-content/mu-plugins/adapter-packages/loginizer/fixtures
LOGINIZER_SHA=7f51a7d54b258d6d5a0718b5d4978c64df2629f7b0220ecd2c5885a990283878
LOGINIZER_ARTIFACT="/artifacts-cache/plugin-loginizer-2.1.0-${LOGINIZER_SHA}.zip"

observe_loginizer() {
  local side="$1" out
  if [ "$side" = conf1 ]; then
    out=$(wp_conf1 eval-file "$LOGINIZER_FIXTURES/native-options-observe.php" --use-include)
  else
    out=$(wp_conf2 eval-file "$LOGINIZER_FIXTURES/native-options-observe.php" --use-include)
  fi
  require_observed_nonempty "Loginizer $side runtime observation" "$out"
  printf '%s\n' "$out" | awk 'NF { line=$0 } END { print line }'
}

converged_filter='
  .version == "2.1.0" and
  .rows.options.max_retries == 3 and .rows.options.lockout_time == 900 and .rows.options.max_lockouts == 5
  and .rows.options.lockouts_extend == 21600 and .rows.options.reset_retries == 43200
  and .rows.options.notify_email == 1 and .rows.options.notify_email_address == "admin@example.test"
  and .rows.options.trusted_ips == "on" and .rows.options.blocked_screen == "on"
  and (.rows.options | length) == 9
  and .rows.login_mail.enable == 1 and .rows.login_mail.disable_whitelist == 0
  and .rows.login_mail.html_mail == true
  and .rows.login_mail.subject == "[$sitename] Failed login attempts"
  and .rows.login_mail.roles == ["administrator"]
  and (.rows.login_mail | length) == 6
  and .body_carries_home == true
  and .rows.whitelist["1"].start == "10.0.0.5" and .rows.whitelist["1"].end == "10.0.0.9"
  and (.rows.whitelist | length) == 1
  and .rows.blacklist["1"].start == "192.168.7.7" and .rows.blacklist["1"].end == "192.168.7.7"
  and (.rows.blacklist | length) == 1
  and .rows.disable_brute == 0 and .rows.neighbor == "target-only-neighbor"
  and .effective.max_retries == 3 and .effective.lockout_time == 900 and .effective.max_lockouts == 5
  and .effective.lockouts_extend == 21600 and .effective.reset_retries == 43200
  and .effective.notify_email == 1 and .effective.notify_email_address == "admin@example.test"
  and .effective.trusted_ips == true and .effective.disable_brute == 0
  and .access.whitelisted_member.whitelisted == true and .access.whitelisted_member.blacklisted == false
  and .access.blacklisted_member.blacklisted == true and .access.blacklisted_member.whitelisted == false
  and .access.neutral.whitelisted == false and .access.neutral.blacklisted == false
  and .logs.exists == true and .logs.rows == 0
'

INITIAL=$(observe_loginizer conf2)
printf '%s\n' "$INITIAL" | jq -e "$converged_filter" >/dev/null \
  || fail "Loginizer target did not converge the hostile rows into the plugin's own configuration and access decisions: $INITIAL"
pass "Loginizer converges all nine opposed settings, the opposed notification template, the doubled IP lists and the disable toggle; the plugin's boot globals, whitelist/blacklist access decisions, home-URL template binding and empty runtime log table all consume the applied state, and the undeclared neighbour survives"

# ---------------------------------------------------------------- lifecycle
wp_conf2 plugin deactivate loginizer >/dev/null
DEACTIVATED_OPTIONS=$(wp_conf2 option get loginizer_options --format=json)
require_observed_nonempty "Loginizer settings after deactivation" "$DEACTIVATED_OPTIONS"
printf '%s\n' "$DEACTIVATED_OPTIONS" | jq -e '.max_retries == 3 and (. | length) == 9' >/dev/null \
  || fail "Loginizer deactivation removed or rewrote the authored settings: $DEACTIVATED_OPTIONS"
DEPLOY_AFTER_DEACTIVATE=$(wp_conf2 wprism deploy --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_wprism_answered "Loginizer deploy after deactivation" json "$DEPLOY_AFTER_DEACTIVATE"
wp_conf2 plugin is-active loginizer >/dev/null \
  || fail "WPrism deploy did not reactivate exact Loginizer code"
REACTIVATED=$(observe_loginizer conf2)
printf '%s\n' "$REACTIVATED" | jq -e "$converged_filter" >/dev/null \
  || fail "Loginizer settings or behaviour changed across deactivate/reactivate: $REACTIVATED"
pass "Loginizer deactivation preserves the authored state and deploy reactivation restores the plugin's own configuration and access decisions"

# ------------------------------------------------ uninstall residue + recovery
# Loginizer 2.1.0's uninstall callback (init.php:977-1005) drops loginizer_logs
# and deletes most option rows — but NOT loginizer_login_mail or
# loginizer_disable_brute, which survive as source-equal residue. Missing-code
# refusal must preserve those bytes; exact reinstall + deploy + apply must
# converge everything again, including re-creating the dropped runtime table.
wp_conf2 plugin uninstall loginizer --deactivate >/dev/null
if wp_conf2 plugin is-installed loginizer >/dev/null 2>&1; then
  fail "Loginizer uninstall left plugin code installed"
fi
if wp_conf2 option get loginizer_options >/dev/null 2>&1; then
  fail "Loginizer uninstall left the settings row the plugin's own uninstaller deletes"
fi
if wp_conf2 option get loginizer_whitelist >/dev/null 2>&1; then
  fail "Loginizer uninstall left the whitelist row the plugin's own uninstaller deletes"
fi
UNINSTALL_MAIL=$(wp_conf2 option get loginizer_login_mail --format=json)
require_observed_nonempty "Loginizer notification template residue after uninstall" "$UNINSTALL_MAIL"
printf '%s\n' "$UNINSTALL_MAIL" | jq -e '.enable == 1 and .subject == "[$sitename] Failed login attempts"' >/dev/null \
  || fail "Loginizer notification-template residue changed unexpectedly: $UNINSTALL_MAIL"
UNINSTALL_TOGGLE=$(wp_conf2 option get loginizer_disable_brute --format=json)
require_observed_nonempty "Loginizer toggle residue after uninstall" "$UNINSTALL_TOGGLE"
[ "$UNINSTALL_TOGGLE" = "0" ] \
  || fail "Loginizer disable-toggle residue changed unexpectedly: $UNINSTALL_TOGGLE"
NEIGHBOR_AFTER_UNINSTALL=$(wp_conf2 option get loginizer_target_probe)
require_observed_nonempty "Loginizer target-only neighbour after uninstall" "$NEIGHBOR_AFTER_UNINSTALL"
[ "$NEIGHBOR_AFTER_UNINSTALL" = "target-only-neighbor" ] \
  || fail "Loginizer uninstall or WPrism lifecycle handling mutated the undeclared neighbour"

MISSING_RC=0
MISSING_OUT=$(wp_conf2 wprism deploy --repo=/siterepo 2>&1) || MISSING_RC=$?
require_wprism_answered "Loginizer deploy with code absent" human "$MISSING_OUT"
[ "$MISSING_RC" -ne 0 ] && grep -Eq 'code_mismatch|missing_in_code|is not installed' <<<"$MISSING_OUT" \
  || fail "missing Loginizer code did not refuse at the compatibility boundary: $MISSING_OUT"
if wp_conf2 plugin is-installed loginizer >/dev/null 2>&1; then
  fail "missing-code refusal installed Loginizer as a fallback"
fi
[ "$(wp_conf2 option get loginizer_target_probe)" = "target-only-neighbor" ] \
  || fail "missing-code refusal partially mutated the target"
[ "$(wp_conf2 option get loginizer_disable_brute --format=json)" = "0" ] \
  || fail "missing-code refusal changed Loginizer's source-equal uninstall residue"

OBSERVED_SHA=$(wp_conf2 eval "echo hash_file('sha256', '$LOGINIZER_ARTIFACT');")
require_observed_nonempty "Loginizer cached artifact digest" "$OBSERVED_SHA"
[ "$OBSERVED_SHA" = "$LOGINIZER_SHA" ] \
  || fail "Loginizer reinstall artifact digest moved (expected=$LOGINIZER_SHA actual=$OBSERVED_SHA)"
wp_conf2 plugin install "$LOGINIZER_ARTIFACT" --force >/dev/null
[ "$(wp_conf2 plugin get loginizer --field=version)" = "2.1.0" ] \
  || fail "Loginizer exact reinstall reported the wrong version"
REINSTALL_DEPLOY=$(wp_conf2 wprism deploy --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_wprism_answered "Loginizer deploy after exact reinstall" json "$REINSTALL_DEPLOY"
REINSTALL_APPLY=$(wp_conf2 wprism apply --repo=/siterepo --default-author=admin --format=json | awk 'NF { line=$0 } END { print line }')
require_wprism_answered "Loginizer apply after exact reinstall" json "$REINSTALL_APPLY"
[ "$(jq -r '.canary' <<<"$REINSTALL_APPLY")" = "clean" ] \
  || fail "Loginizer reinstall recovery did not keep the apply canary clean: $REINSTALL_APPLY"
RECOVERED=$(observe_loginizer conf2)
printf '%s\n' "$RECOVERED" | jq -e "$converged_filter" >/dev/null \
  || fail "Loginizer exact reinstall/apply did not recover the source-converged state: $RECOVERED"
wp_conf2 wprism capture --repo=/siterepo --out=/siterepo/.tmp-loginizer-recovered >/dev/null
diff -r "${CONF_REPO1:-siterepo/conf1}/state" "${CONF_REPO2:-siterepo/conf2}/.tmp-loginizer-recovered" \
  || fail "Loginizer reinstall recovery did not recapture byte-identically"
rm -rf "${CONF_REPO2:-siterepo/conf2}/.tmp-loginizer-recovered"
pass "Loginizer uninstall residue, absent-code refusal, exact digest-verified reinstall, deploy and apply converge every owned row again and recapture byte-identically"

# ------------------------------------------------- three-way settings conflict
wp_conf1 eval "update_option('loginizer_options', ['max_retries'=>7,'lockout_time'=>600,'max_lockouts'=>11,'lockouts_extend'=>3600,'reset_retries'=>7200,'notify_email'=>0,'notify_email_address'=>'branch@example.test','trusted_ips'=>'off','blocked_screen'=>'off']);" >/dev/null
wp_conf1 wprism capture --repo=/siterepo >/dev/null
git -C "${CONF_REPO1:-siterepo/conf1}" add -A
git -C "${CONF_REPO1:-siterepo/conf1}" -c user.name=wprism -c user.email=wprism@example.test commit -qm 'conformance: Loginizer repository branch intent'
git -C "${CONF_REPO1:-siterepo/conf1}" push -q origin main
wp_conf2 eval "update_option('loginizer_options', ['max_retries'=>4,'lockout_time'=>120,'max_lockouts'=>9,'lockouts_extend'=>5400,'reset_retries'=>9000,'notify_email'=>1,'notify_email_address'=>'target@example.test','trusted_ips'=>'on','blocked_screen'=>'on']);" >/dev/null
git -C "${CONF_REPO2:-siterepo/conf2}" pull -q origin main

CONFLICT_PLAN=$(wp_conf2 wprism plan --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_wprism_answered "Loginizer competing-settings plan" json "$CONFLICT_PLAN"
jq -e '.conflict | any(.uuid == "options/core" and .type == "options")' <<<"$CONFLICT_PLAN" >/dev/null \
  || fail "competing Loginizer settings did not produce a typed options conflict: $CONFLICT_PLAN"
CONFLICT_BEFORE=$(wp_conf2 eval "echo wp_json_encode(get_option('loginizer_options'));")
require_observed_nonempty "Loginizer target conflict baseline" "$CONFLICT_BEFORE"
CONFLICT_RC=0
CONFLICT_OUT=$(wp_conf2 wprism apply --repo=/siterepo --default-author=admin 2>&1) || CONFLICT_RC=$?
require_wprism_answered "Loginizer unforced competing-settings apply" human "$CONFLICT_OUT"
[ "$CONFLICT_RC" -ne 0 ] && grep -qi 'conflicts (env and repo both changed' <<<"$CONFLICT_OUT" \
  || fail "Loginizer competing settings did not refuse before mutation: $CONFLICT_OUT"
CONFLICT_AFTER=$(wp_conf2 eval "echo wp_json_encode(get_option('loginizer_options'));")
require_observed_nonempty "Loginizer target after unforced conflict" "$CONFLICT_AFTER"
[ "$CONFLICT_AFTER" = "$CONFLICT_BEFORE" ] \
  || fail "unforced Loginizer conflict partially mutated the target (before=$CONFLICT_BEFORE after=$CONFLICT_AFTER)"
FORCED=$(wp_conf2 wprism apply --repo=/siterepo --force-theirs --default-author=admin --format=json | awk 'NF { line=$0 } END { print line }')
require_wprism_answered "Loginizer forced competing-settings apply" json "$FORCED"
jq -e '.warnings | any(contains("FORCED conflict options/core"))' <<<"$FORCED" >/dev/null \
  || fail "forced Loginizer conflict did not report its destructive override: $FORCED"
FORCED_STATE=$(observe_loginizer conf2)
printf '%s\n' "$FORCED_STATE" | jq -e '
  .rows.options.max_retries == 7 and .rows.options.lockout_time == 600
  and .rows.options.max_lockouts == 11 and .rows.options.lockouts_extend == 3600
  and .rows.options.reset_retries == 7200 and .rows.options.notify_email == 0
  and .rows.options.notify_email_address == "branch@example.test"
  and .rows.options.trusted_ips == "off" and .rows.options.blocked_screen == "off"
  and .effective.max_retries == 7 and .effective.lockout_time == 600
  and .effective.notify_email == 0 and .effective.trusted_ips == false
  and .rows.whitelist["1"].start == "10.0.0.5"
  and .rows.neighbor == "target-only-neighbor"
' >/dev/null || fail "forced Loginizer conflict did not converge to repository intent: $FORCED_STATE"

ZERO_PLAN=$(wp_conf2 wprism plan --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_wprism_answered "Loginizer zero-change plan" json "$ZERO_PLAN"
jq -e '
  (.create | length) == 0 and (.update | length) == 0 and
  (.conflict | length) == 0 and (.drift | length) == 0
' <<<"$ZERO_PLAN" >/dev/null || fail "Loginizer retry was not a zero-change plan: $ZERO_PLAN"
ZERO_APPLY=$(wp_conf2 wprism apply --repo=/siterepo --default-author=admin --format=json | awk 'NF { line=$0 } END { print line }')
require_wprism_answered "Loginizer zero-change apply" json "$ZERO_APPLY"
[ "$(jq -r '.canary' <<<"$ZERO_APPLY")" = "clean" ] \
  || fail "Loginizer zero-change retry dirtied the apply canary: $ZERO_APPLY"
pass "Loginizer competing branch settings refuse without mutation, forced intent converges through the plugin's runtime, and retry is idempotent"

# -------------------------------------------------- authorized option deletion
# loginizer_whitelist is a whole-option authored row, so a source that removes
# it expresses destructive intent: unforced apply must refuse, --with-deletes
# must remove exactly that row, and the plugin must stay coherent with the
# empty list its own reader resolves for an absent row (init.php:286's
# get_option default []). The retained-whitelist probes flip to refused.
wp_conf1 eval "delete_option('loginizer_whitelist');" >/dev/null
wp_conf1 wprism capture --repo=/siterepo >/dev/null
git -C "${CONF_REPO1:-siterepo/conf1}" add -A
git -C "${CONF_REPO1:-siterepo/conf1}" -c user.name=wprism -c user.email=wprism@example.test commit -qm 'conformance: delete Loginizer whitelist policy'
git -C "${CONF_REPO1:-siterepo/conf1}" push -q origin main
git -C "${CONF_REPO2:-siterepo/conf2}" pull -q origin main

DELETE_RC=0
DELETE_OUT=$(wp_conf2 wprism apply --repo=/siterepo --default-author=admin 2>&1) || DELETE_RC=$?
require_wprism_answered "Loginizer option deletion without authorization" human "$DELETE_OUT"
[ "$DELETE_RC" -ne 0 ] && grep -q 'authored option deletion intent requires --with-deletes' <<<"$DELETE_OUT" \
  || fail "Loginizer option deletion did not require explicit authorization: $DELETE_OUT"
KEPT_WHITELIST=$(wp_conf2 eval "echo wp_json_encode(get_option('loginizer_whitelist'));")
require_observed_nonempty "Loginizer whitelist after refused deletion" "$KEPT_WHITELIST"
printf '%s\n' "$KEPT_WHITELIST" | jq -e '(. | length) == 1 and .["1"].start == "10.0.0.5"' >/dev/null \
  || fail "unauthorized Loginizer deletion partially mutated the whitelist: $KEPT_WHITELIST"
DELETE_APPLY=$(wp_conf2 wprism apply --repo=/siterepo --with-deletes --default-author=admin --format=json | awk 'NF { line=$0 } END { print line }')
require_wprism_answered "Loginizer authorized option deletion" json "$DELETE_APPLY"
if wp_conf2 option get loginizer_whitelist >/dev/null 2>&1; then
  fail "authorized Loginizer deletion left the whitelist row present"
fi
[ "$(wp_conf2 eval "echo get_option('loginizer_options')['max_retries'];")" = "7" ] \
  && [ "$(wp_conf2 option get loginizer_target_probe)" = "target-only-neighbor" ] \
  || fail "authorized Loginizer deletion mutated a sibling owned option or the undeclared neighbour"
DELETED_STATE=$(observe_loginizer conf2)
printf '%s\n' "$DELETED_STATE" | jq -e '
  .rows.whitelist == null and
  .access.whitelisted_member.whitelisted == false and
  .access.whitelisted_member.blacklisted == false and
  .access.blacklisted_member.blacklisted == true and
  .rows.options.max_retries == 7 and .rows.neighbor == "target-only-neighbor"
' >/dev/null || fail "the plugin did not stay coherent with an absent whitelist row: $DELETED_STATE"
wp_conf2 wprism capture --repo=/siterepo --out=/siterepo/.tmp-loginizer-delete-state >/dev/null
diff -r "${CONF_REPO1:-siterepo/conf1}/state" "${CONF_REPO2:-siterepo/conf2}/.tmp-loginizer-delete-state" \
  || fail "Loginizer authorized deletion did not recapture byte-identically"
rm -rf "${CONF_REPO2:-siterepo/conf2}/.tmp-loginizer-delete-state"
pass "Loginizer option deletion refuses without --with-deletes, deletes exactly the whitelist row when authorized, keeps the plugin's own access decisions coherent with the absent row, and recaptures identically"

# ------------------------------------------------ closed_sub_keys refusal
# Write past the plugin's own writer the way a future release or a third-party
# writer would, and prove capture aborts loudly instead of carrying an
# unclassified member. Restoring the nine-member row must make capture answer
# again, proving the refusal was the undeclared member and not broken state.
wp_conf2 eval '
global $wpdb;
$wpdb->update(
    $wpdb->options,
    ["option_value" => serialize([
        "max_retries" => 7,
        "lockout_time" => 600,
        "max_lockouts" => 11,
        "lockouts_extend" => 3600,
        "reset_retries" => 7200,
        "notify_email" => 0,
        "notify_email_address" => "branch@example.test",
        "trusted_ips" => "off",
        "blocked_screen" => "off",
        "undeclared_future_key" => "unclassified",
    ])],
    ["option_name" => "loginizer_options"]
);
' >/dev/null
UNDECLARED_RC=0
UNDECLARED_OUT=$(wp_conf2 wprism capture --repo=/siterepo --out=/siterepo/.tmp-loginizer-undeclared 2>&1) || UNDECLARED_RC=$?
rm -rf "${CONF_REPO2:-siterepo/conf2}/.tmp-loginizer-undeclared"
require_wprism_answered "Loginizer undeclared sibling capture" human "$UNDECLARED_OUT"
[ "$UNDECLARED_RC" -ne 0 ] \
  || fail "an undeclared sibling key inside loginizer_options was captured without refusal: $UNDECLARED_OUT"
grep -q "undeclared sibling key" <<<"$UNDECLARED_OUT" \
  || fail "undeclared Loginizer sibling refused for the wrong reason: $UNDECLARED_OUT"
grep -q "loginizer_options" <<<"$UNDECLARED_OUT" \
  || fail "undeclared Loginizer sibling refusal did not name the option: $UNDECLARED_OUT"

wp_conf2 eval "update_option('loginizer_options', ['max_retries'=>7,'lockout_time'=>600,'max_lockouts'=>11,'lockouts_extend'=>3600,'reset_retries'=>7200,'notify_email'=>0,'notify_email_address'=>'branch@example.test','trusted_ips'=>'off','blocked_screen'=>'off']);" >/dev/null
wp_conf2 wprism capture --repo=/siterepo --out=/siterepo/.tmp-loginizer-restored >/dev/null
diff -r "${CONF_REPO1:-siterepo/conf1}/state" "${CONF_REPO2:-siterepo/conf2}/.tmp-loginizer-restored" \
  || fail "Loginizer capture did not recover after the undeclared sibling was removed"
rm -rf "${CONF_REPO2:-siterepo/conf2}/.tmp-loginizer-restored"
pass "an undeclared tenth member inside the closed settings option aborts capture loudly naming the option, and capture recovers byte-identically once the plugin's own nine-member shape is restored"
