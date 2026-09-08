#!/usr/bin/env bash
# Production-oriented Speculative Loading proof. The generic harness already
# proved clean apply and byte-identical recapture; this hook adds the
# plugin-owned behaviour core actually consumes, hostile-target convergence,
# lifecycle recovery, a real three-way conflict, the absent-row default
# completion this adapter's interpreter is responsible for, and the
# closed_sub_keys refusal that keeps a future fourth key from riding along.
set -euo pipefail

observe_speculation_rules() {
  local side="$1" repo_var file out
  case "$side" in
    conf1) repo_var="${CONF_REPO1:-siterepo/conf1}" ;;
    conf2) repo_var="${CONF_REPO2:-siterepo/conf2}" ;;
    *) fail "invalid Speculative Loading observation side: $side" ;;
  esac
  read -r -d '' OBSERVE_PHP <<'PHPEOF' || true
<?php
wp_set_current_user(1);
global $wpdb;
$row = $wpdb->get_row(
    "SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = 'plsr_speculation_rules'",
    ARRAY_A
);
$stored = plsr_get_stored_setting_value();
echo wp_json_encode([
    'autoload' => is_array($row) ? $row['autoload'] : null,
    'config' => wp_get_speculation_rules_configuration(),
    'default' => plsr_get_setting_default(),
    'enabled' => plsr_is_speculative_loading_enabled(),
    'keys' => array_keys($stored),
    'neighbor' => get_option('plsr_speculation_rules_target_probe', null),
    'row_present' => is_array($row),
    'stored' => $stored,
]);
PHPEOF
  file="$repo_var/.tmp-speculation-rules-observe.php"
  printf '%s' "$OBSERVE_PHP" > "$file"
  if [ "$side" = conf1 ]; then
    out=$(wp_conf1 eval-file /siterepo/.tmp-speculation-rules-observe.php)
  else
    out=$(wp_conf2 eval-file /siterepo/.tmp-speculation-rules-observe.php)
  fi
  rm -f "$file"
  require_observed_nonempty "Speculative Loading $side runtime observation" "$out"
  printf '%s\n' "$out" | awk 'NF { line=$0 } END { print line }'
}

INITIAL=$(observe_speculation_rules conf2)
printf '%s\n' "$INITIAL" | jq -e '
  .stored.mode == "prefetch" and .stored.eagerness == "conservative" and
  .stored.authentication == "logged_out_and_admins" and
  .keys == ["mode", "eagerness", "authentication"] and
  .row_present == true and .autoload == "auto" and
  .enabled == true and
  .config.mode == "prefetch" and .config.eagerness == "conservative" and
  .neighbor == "target-only-neighbor"
' >/dev/null || fail "Speculative Loading target did not converge all three owned sub-keys into core's configuration: $INITIAL"
pass "Speculative Loading converges three opposed sub-keys, drives WordPress core's speculation configuration from the applied values, and preserves an undeclared neighbour"

# ---------------------------------------------------------------- lifecycle
wp_conf2 plugin deactivate speculation-rules >/dev/null
DEACTIVATED_ROW=$(wp_conf2 db query \
  "SELECT option_value FROM wp_options WHERE option_name = 'plsr_speculation_rules';" --skip-column-names)
require_observed_nonempty "Speculative Loading setting after deactivation" "$DEACTIVATED_ROW"
grep -q 'conservative' <<<"$DEACTIVATED_ROW" \
  || fail "Speculative Loading deactivation removed or rewrote the authored setting: $DEACTIVATED_ROW"
DEPLOY_AFTER_DEACTIVATE=$(wp_conf2 wprism deploy --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_wprism_answered "Speculative Loading deploy after deactivation" json "$DEPLOY_AFTER_DEACTIVATE"
wp_conf2 plugin is-active speculation-rules >/dev/null \
  || fail "WPrism deploy did not reactivate exact Speculative Loading code"
REACTIVATED=$(observe_speculation_rules conf2)
printf '%s\n' "$REACTIVATED" | jq -e '
  .stored.mode == "prefetch" and .stored.eagerness == "conservative" and
  .stored.authentication == "logged_out_and_admins" and
  .config.mode == "prefetch" and .config.eagerness == "conservative"
' >/dev/null || fail "Speculative Loading setting or behaviour changed across deactivate/reactivate: $REACTIVATED"
pass "Speculative Loading deactivation preserves the authored setting and deploy reactivation restores the configuration core consumes"

# ------------------------------------------------- three-way settings conflict
wp_conf1 eval "update_option('plsr_speculation_rules', ['mode'=>'prerender','eagerness'=>'eager','authentication'=>'any']);" >/dev/null
wp_conf1 wprism capture --repo=/siterepo >/dev/null
git -C "$CONF_REPO1" add -A
git -C "$CONF_REPO1" -c user.name=wprism -c user.email=wprism@example.test commit -qm 'conformance: Speculative Loading repository branch intent'
git -C "$CONF_REPO1" push -q origin main
wp_conf2 eval "update_option('plsr_speculation_rules', ['mode'=>'prefetch','eagerness'=>'moderate','authentication'=>'logged_out']);" >/dev/null
git -C "$CONF_REPO2" pull -q origin main

CONFLICT_PLAN=$(wp_conf2 wprism plan --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_wprism_answered "Speculative Loading competing-settings plan" json "$CONFLICT_PLAN"
jq -e '.conflict | any(.type == "options")' <<<"$CONFLICT_PLAN" >/dev/null \
  || fail "competing Speculative Loading settings did not produce a typed options conflict: $CONFLICT_PLAN"
CONFLICT_BEFORE=$(wp_conf2 eval "echo wp_json_encode(plsr_get_stored_setting_value());")
require_observed_nonempty "Speculative Loading target conflict baseline" "$CONFLICT_BEFORE"
CONFLICT_RC=0
CONFLICT_OUT=$(wp_conf2 wprism apply --repo=/siterepo --default-author=admin 2>&1) || CONFLICT_RC=$?
require_wprism_answered "Speculative Loading unforced competing-settings apply" human "$CONFLICT_OUT"
[ "$CONFLICT_RC" -ne 0 ] && grep -qi 'conflicts (env and repo both changed' <<<"$CONFLICT_OUT" \
  || fail "Speculative Loading competing settings did not refuse before mutation: $CONFLICT_OUT"
CONFLICT_AFTER=$(wp_conf2 eval "echo wp_json_encode(plsr_get_stored_setting_value());")
[ "$CONFLICT_AFTER" = "$CONFLICT_BEFORE" ] \
  || fail "unforced Speculative Loading conflict partially mutated the target (before=$CONFLICT_BEFORE after=$CONFLICT_AFTER)"
FORCED=$(wp_conf2 wprism apply --repo=/siterepo --force-theirs --default-author=admin --format=json | awk 'NF { line=$0 } END { print line }')
require_wprism_answered "Speculative Loading forced competing-settings apply" json "$FORCED"
jq -e '.warnings | any(contains("FORCED conflict"))' <<<"$FORCED" >/dev/null \
  || fail "forced Speculative Loading conflict did not report its destructive override: $FORCED"
FORCED_STATE=$(observe_speculation_rules conf2)
printf '%s\n' "$FORCED_STATE" | jq -e '
  .stored.mode == "prerender" and .stored.eagerness == "eager" and
  .stored.authentication == "any" and
  .config.mode == "prerender" and .config.eagerness == "eager" and
  .neighbor == "target-only-neighbor"
' >/dev/null || fail "forced Speculative Loading conflict did not converge to repository intent: $FORCED_STATE"

ZERO_PLAN=$(wp_conf2 wprism plan --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_wprism_answered "Speculative Loading zero-change plan" json "$ZERO_PLAN"
jq -e '
  (.create | length) == 0 and (.update | length) == 0 and
  (.conflict | length) == 0 and (.drift | length) == 0
' <<<"$ZERO_PLAN" >/dev/null || fail "Speculative Loading retry was not a zero-change plan: $ZERO_PLAN"
ZERO_APPLY=$(wp_conf2 wprism apply --repo=/siterepo --default-author=admin --format=json | awk 'NF { line=$0 } END { print line }')
require_wprism_answered "Speculative Loading zero-change apply" json "$ZERO_APPLY"
[ "$(jq -r '.canary' <<<"$ZERO_APPLY")" = "clean" ] \
  || fail "Speculative Loading zero-change retry dirtied the apply canary: $ZERO_APPLY"
pass "Speculative Loading competing branch settings refuse without mutation, forced intent converges through the plugin's runtime, and retry is idempotent"

# -------------------------------------------- absent source row default completion
# The plugin has no "setting removed" state: register_setting()'s default and
# plsr_sanitize_setting()'s non-array fallback both resolve an absent row to
# prerender/moderate/logged_out. The capsule's interpreter completes that
# absence from plsr_get_setting_default() itself, so a source with NO row and a
# target WITH a defaulted row must capture to the same document.
wp_conf1 eval "delete_option('plsr_speculation_rules');" >/dev/null
SOURCE_ABSENT=$(wp_conf1 db query \
  "SELECT COUNT(*) FROM wp_options WHERE option_name = 'plsr_speculation_rules';" --skip-column-names)
[ "$SOURCE_ABSENT" = "0" ] \
  || fail "Speculative Loading source still holds an option row after delete_option (count=$SOURCE_ABSENT)"
wp_conf1 wprism capture --repo=/siterepo >/dev/null
wp_conf1 wprism lint --repo=/siterepo >/dev/null
git -C "$CONF_REPO1" add -A
git -C "$CONF_REPO1" -c user.name=wprism -c user.email=wprism@example.test commit -qm 'conformance: Speculative Loading absent source row'
git -C "$CONF_REPO1" push -q origin main
git -C "$CONF_REPO2" pull -q origin main

ABSENT_APPLY=$(wp_conf2 wprism apply --repo=/siterepo --default-author=admin --format=json | awk 'NF { line=$0 } END { print line }')
require_wprism_answered "Speculative Loading absent-source apply" json "$ABSENT_APPLY"
[ "$(jq -r '.canary' <<<"$ABSENT_APPLY")" = "clean" ] \
  || fail "Speculative Loading absent-source apply did not keep the canary clean: $ABSENT_APPLY"
DEFAULTED=$(observe_speculation_rules conf2)
printf '%s\n' "$DEFAULTED" | jq -e '
  .stored == .default and
  .stored.mode == "prerender" and .stored.eagerness == "moderate" and
  .stored.authentication == "logged_out" and
  .neighbor == "target-only-neighbor"
' >/dev/null || fail "an absent source row did not converge the target onto the plugin's own registered defaults: $DEFAULTED"
wp_conf2 wprism capture --repo=/siterepo --out=/siterepo/.tmp-speculation-rules-absent >/dev/null
diff -r "$CONF_REPO1/state" "$CONF_REPO2/.tmp-speculation-rules-absent" \
  || fail "Speculative Loading absent-source completion did not recapture byte-identically"
rm -rf "$CONF_REPO2/.tmp-speculation-rules-absent"
pass "a source with no option row and a target with a defaulted row capture to the same document: absence is completed from the plugin's own plsr_get_setting_default(), converging behaviour without inventing a setting"

# ------------------------------------------------ closed_sub_keys refusal
# Write past the sanitizer the way a future release or a third-party writer
# would, and prove capture aborts loudly instead of carrying an unclassified
# key. update_option() cannot stage this: the plugin's own sanitizer strips it.
wp_conf2 eval '
global $wpdb;
$wpdb->update(
    $wpdb->options,
    ["option_value" => serialize([
        "mode" => "prerender",
        "eagerness" => "moderate",
        "authentication" => "logged_out",
        "undeclared_future_key" => "unclassified",
    ])],
    ["option_name" => "plsr_speculation_rules"]
);
' >/dev/null
UNDECLARED_RC=0
UNDECLARED_OUT=$(wp_conf2 wprism capture --repo=/siterepo --out=/siterepo/.tmp-speculation-rules-undeclared 2>&1) || UNDECLARED_RC=$?
rm -rf "$CONF_REPO2/.tmp-speculation-rules-undeclared"
require_wprism_answered "Speculative Loading undeclared sibling capture" human "$UNDECLARED_OUT"
[ "$UNDECLARED_RC" -ne 0 ] \
  || fail "an undeclared sibling key inside plsr_speculation_rules was captured without refusal: $UNDECLARED_OUT"
grep -q "undeclared sibling key" <<<"$UNDECLARED_OUT" \
  || fail "undeclared Speculative Loading sibling refused for the wrong reason: $UNDECLARED_OUT"
grep -q "plsr_speculation_rules" <<<"$UNDECLARED_OUT" \
  || fail "undeclared Speculative Loading sibling refusal did not name the option: $UNDECLARED_OUT"

# Restoring the sealed key set must make capture answer again, proving the
# refusal was the undeclared key and not a latent broken state.
wp_conf2 eval "update_option('plsr_speculation_rules', ['mode'=>'prerender','eagerness'=>'moderate','authentication'=>'logged_out']);" >/dev/null
wp_conf2 wprism capture --repo=/siterepo --out=/siterepo/.tmp-speculation-rules-restored >/dev/null
diff -r "$CONF_REPO1/state" "$CONF_REPO2/.tmp-speculation-rules-restored" \
  || fail "Speculative Loading capture did not recover after the undeclared sibling was removed"
rm -rf "$CONF_REPO2/.tmp-speculation-rules-restored"
pass "an undeclared fourth key inside the closed option aborts capture loudly naming the option, and capture recovers byte-identically once the plugin's own sealed key set is restored"
