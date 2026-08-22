#!/usr/bin/env bash
# Production-oriented Advanced Editor Tools proof. The generic harness already
# proves clean apply and byte-identical recapture; this adds all plugin-visible
# editor surfaces, namespace/runtime sovereignty, lifecycle recovery, option
# deletion, and a real competing-settings branch conflict.
set -euo pipefail

observe_advanced_editor_tools() {
  local side="$1" repo_var file out
  case "$side" in
    conf1) repo_var="${CONF_REPO1:-siterepo/conf1}" ;;
    conf2) repo_var="${CONF_REPO2:-siterepo/conf2}" ;;
    *) fail "invalid Advanced Editor Tools observation side: $side" ;;
  esac
  read -r -d '' OBSERVE_PHP <<'PHPEOF' || true
<?php
wp_set_current_user(1);
$settings = get_option('tadv_settings');
$admin = get_option('tadv_admin_settings');
$runtime = [];
foreach (['tadv_options','tadv_toolbars','tadv_plugins','tadv_btns1','tadv_btns2','tadv_btns3','tadv_btns4','tadv_allbtns'] as $name) {
    $runtime[$name] = get_option($name, null);
}
$init = apply_filters('tiny_mce_before_init', [], 'content');
echo wp_json_encode([
    'admin' => $admin,
    'buttons_1' => array_values(apply_filters('mce_buttons', ['formatselect'], 'content')),
    'buttons_2' => array_values(apply_filters('mce_buttons_2', [], 'content')),
    'buttons_3' => array_values(apply_filters('mce_buttons_3', [], 'content')),
    'buttons_4' => array_values(apply_filters('mce_buttons_4', [], 'content')),
    'classic_buttons' => array_values(apply_filters('mce_buttons', [], 'classic-block')),
    'init' => $init,
    'neighbor' => get_option('tadv_future_setting', null),
    'runtime' => $runtime,
    'settings' => $settings,
    'version' => get_option('tadv_version', null),
]);
PHPEOF
  file="$repo_var/.tmp-advanced-editor-tools-observe.php"
  printf '%s' "$OBSERVE_PHP" > "$file"
  if [ "$side" = conf1 ]; then
    out=$(wp_conf1 eval-file /siterepo/.tmp-advanced-editor-tools-observe.php)
  else
    out=$(wp_conf2 eval-file /siterepo/.tmp-advanced-editor-tools-observe.php)
  fi
  rm -f "$file"
  require_observed_nonempty "Advanced Editor Tools $side runtime observation" "$out"
  printf '%s\n' "$out" | awk 'NF { line=$0 } END { print line }'
}

save_advanced_editor_tools_profile() {
  local side="$1" profile="$2" repo_var file out
  case "$side" in
    conf1) repo_var="${CONF_REPO1:-siterepo/conf1}" ;;
    conf2) repo_var="${CONF_REPO2:-siterepo/conf2}" ;;
    *) fail "invalid Advanced Editor Tools save side: $side" ;;
  esac
  read -r -d '' SAVE_PHP <<PHPEOF || true
<?php
wp_set_current_user(1);
\$profile = '$profile';
\$profiles = [
    'repository' => [
        'settings' => [
            'toolbar_1' => 'bold,code', 'toolbar_2' => 'link',
            'toolbar_3' => '', 'toolbar_4' => '',
            'toolbar_classic_block' => 'bold,code',
            'options' => 'menubar', 'plugins' => '',
        ],
        'admin_settings' => ['options' => 'table_resize_bars', 'disabled_editors' => ''],
    ],
    'target' => [
        'settings' => [
            'toolbar_1' => 'italic,charmap', 'toolbar_2' => 'blockquote',
            'toolbar_3' => '', 'toolbar_4' => '',
            'toolbar_classic_block' => 'italic',
            'options' => '', 'plugins' => '',
        ],
        'admin_settings' => ['options' => 'no_autop', 'disabled_editors' => 'on_front_end'],
    ],
];
if (!isset(\$profiles[\$profile])) {
    throw new RuntimeException('unknown Advanced Editor Tools profile');
}
\$instance = null;
foreach ((\$GLOBALS['wp_filter']['mce_buttons']->callbacks ?? []) as \$callbacks) {
    foreach (\$callbacks as \$callback) {
        \$fn = \$callback['function'] ?? null;
        if (is_array(\$fn) && is_object(\$fn[0] ?? null) && (\$fn[1] ?? null) === 'mce_buttons_1') {
            \$instance = \$fn[0];
            break 2;
        }
    }
}
if (!\$instance) {
    throw new RuntimeException('Advanced Editor Tools callback instance was not registered');
}
\$save = new ReflectionMethod(\$instance, 'save_settings');
\$save->invoke(\$instance, \$profiles[\$profile]);
echo get_option('tadv_settings')['toolbar_1'];
PHPEOF
  file="$repo_var/.tmp-advanced-editor-tools-save.php"
  printf '%s' "$SAVE_PHP" > "$file"
  if [ "$side" = conf1 ]; then
    out=$(wp_conf1 eval-file /siterepo/.tmp-advanced-editor-tools-save.php)
  else
    out=$(wp_conf2 eval-file /siterepo/.tmp-advanced-editor-tools-save.php)
  fi
  rm -f "$file"
  require_observed_nonempty "Advanced Editor Tools $side $profile profile save" "$out"
}

INITIAL=$(observe_advanced_editor_tools conf2)
printf '%s\n' "$INITIAL" | jq -e '
  .buttons_1 == ["bold","italic","underline","strikethrough"] and
  .buttons_2 == ["bullist","numlist","blockquote","link","unlink"] and
  .buttons_3 == ["forecolor","backcolor","removeformat","charmap"] and
  .buttons_4 == ["code","fullscreen","searchreplace"] and
  .classic_buttons == ["bold","italic","link","undo","redo"] and
  .settings.toolbar_1 == "bold,italic,underline,strikethrough" and
  .admin.options == "no_autop,table_resize_bars" and
  .admin.disabled_editors == "rest_of_wpadmin" and
  .init.wpautop == false and .init.tadv_noautop == true and
  .neighbor == "target-only-neighbor" and
  ([.runtime[] | .probe] | sort) == ([
    "target-runtime-tadv_allbtns", "target-runtime-tadv_btns1",
    "target-runtime-tadv_btns2", "target-runtime-tadv_btns3",
    "target-runtime-tadv_btns4", "target-runtime-tadv_options",
    "target-runtime-tadv_plugins", "target-runtime-tadv_toolbars"
  ] | sort)
' >/dev/null || fail "Advanced Editor Tools target APIs or runtime boundaries do not match the applied fixture: $INITIAL"
pass "Advanced Editor Tools converges every editor surface while preserving all excluded runtime rows and the undeclared neighbor"

wp_conf2 plugin deactivate tinymce-advanced >/dev/null
[ "$(wp_conf2 option get tadv_settings --format=json | jq -r '.toolbar_1')" = "bold,italic,underline,strikethrough" ] \
  || fail "Advanced Editor Tools deactivation changed authored toolbar settings"
DEPLOY_AFTER_DEACTIVATE=$(wp_conf2 duo deploy --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered "Advanced Editor Tools deploy after deactivation" json "$DEPLOY_AFTER_DEACTIVATE"
wp_conf2 plugin is-active tinymce-advanced >/dev/null \
  || fail "Duo deploy did not reactivate exact Advanced Editor Tools code"
REACTIVATED=$(observe_advanced_editor_tools conf2)
printf '%s\n' "$REACTIVATED" | jq -e '
  .buttons_1 == ["bold","italic","underline","strikethrough"] and
  .admin.options == "no_autop,table_resize_bars" and
  .neighbor == "target-only-neighbor" and
  ([.runtime[] | .probe] | length) == 8
' >/dev/null || fail "Advanced Editor Tools settings/runtime behavior changed across deactivate/reactivate: $REACTIVATED"
pass "Advanced Editor Tools deactivation preserves authored/runtime state and deploy reactivation restores behavior"

wp_conf2 plugin uninstall tinymce-advanced --deactivate >/dev/null
if wp_conf2 plugin is-installed tinymce-advanced >/dev/null 2>&1; then
  fail "Advanced Editor Tools uninstall left plugin code installed"
fi
OPTION_ROWS_AFTER_UNINSTALL=$(wp_conf2 db query "SELECT COUNT(*) FROM wp_options WHERE option_name IN ('tadv_settings','tadv_admin_settings','tadv_version','tadv_options','tadv_toolbars','tadv_plugins','tadv_btns1','tadv_btns2','tadv_btns3','tadv_btns4','tadv_allbtns')" --skip-column-names | tr -d '[:space:]')
require_observed_nonempty "Advanced Editor Tools option count after uninstall" "$OPTION_ROWS_AFTER_UNINSTALL"
[ "$OPTION_ROWS_AFTER_UNINSTALL" = "0" ] \
  || fail "Advanced Editor Tools uninstall did not run its complete owned/runtime cleanup"
[ "$(wp_conf2 option get tadv_future_setting)" = "target-only-neighbor" ] \
  || fail "Advanced Editor Tools uninstall mutated the undeclared neighbor"

MISSING_RC=0
MISSING_OUT=$(wp_conf2 duo deploy --repo=/siterepo 2>&1) || MISSING_RC=$?
require_duo_answered "Advanced Editor Tools deploy with code absent" human "$MISSING_OUT"
[ "$MISSING_RC" -ne 0 ] && grep -Eq 'code_mismatch|missing_in_code|is not installed' <<<"$MISSING_OUT" \
  || fail "missing Advanced Editor Tools code did not refuse at the compatibility boundary: $MISSING_OUT"
if wp_conf2 plugin is-installed tinymce-advanced >/dev/null 2>&1; then
  fail "missing-code refusal installed Advanced Editor Tools as a fallback"
fi
[ "$(wp_conf2 option get tadv_future_setting)" = "target-only-neighbor" ] \
  || fail "Advanced Editor Tools missing-code refusal partially mutated the target"

AET_SHA=ec4c6635dc9d0f9c27d0256d7b83b3655b5904c936748604c698d256fbfd69c7
AET_ARTIFACT="/artifacts-cache/plugin-tinymce-advanced-5.9.2-${AET_SHA}.zip"
OBSERVED_SHA=$(wp_conf2 eval "echo hash_file('sha256', '$AET_ARTIFACT');")
require_observed_nonempty "Advanced Editor Tools cached artifact digest" "$OBSERVED_SHA"
[ "$OBSERVED_SHA" = "$AET_SHA" ] \
  || fail "Advanced Editor Tools reinstall artifact digest moved (expected=$AET_SHA actual=$OBSERVED_SHA)"
wp_conf2 plugin install "$AET_ARTIFACT" --force >/dev/null
[ "$(wp_conf2 plugin get tinymce-advanced --field=version)" = "5.9.2" ] \
  || fail "Advanced Editor Tools exact reinstall reported the wrong version"
REINSTALL_DEPLOY=$(wp_conf2 duo deploy --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered "Advanced Editor Tools deploy after exact reinstall" json "$REINSTALL_DEPLOY"
REINSTALL_APPLY=$(wp_conf2 duo apply --repo=/siterepo --default-author=admin --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered "Advanced Editor Tools apply after exact reinstall" json "$REINSTALL_APPLY"
[ "$(jq -r '.canary' <<<"$REINSTALL_APPLY")" = "clean" ] \
  || fail "Advanced Editor Tools reinstall recovery dirtied the apply canary: $REINSTALL_APPLY"
RECOVERED=$(observe_advanced_editor_tools conf2)
printf '%s\n' "$RECOVERED" | jq -e '
  .buttons_1 == ["bold","italic","underline","strikethrough"] and
  .admin.options == "no_autop,table_resize_bars" and
  .neighbor == "target-only-neighbor"
' >/dev/null || fail "Advanced Editor Tools exact reinstall/apply did not recover source behavior: $RECOVERED"
pass "Advanced Editor Tools uninstall cleanup, absent-code refusal, exact reinstall, deploy, and apply retry recover without partial neighbor mutation"

save_advanced_editor_tools_profile conf1 repository
wp_conf1 duo capture --repo=/siterepo >/dev/null
git -C "$CONF_REPO1" add -A
git -C "$CONF_REPO1" -c user.name=duo -c user.email=duo@example.test commit -qm 'conformance: Advanced Editor Tools repository branch intent'
git -C "$CONF_REPO1" push -q origin main
save_advanced_editor_tools_profile conf2 target
git -C "$CONF_REPO2" pull -q origin main

CONFLICT_PLAN=$(wp_conf2 duo plan --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered "Advanced Editor Tools competing-settings plan" json "$CONFLICT_PLAN"
jq -e '.conflict | any(.uuid == "options/core" and .type == "options")' <<<"$CONFLICT_PLAN" >/dev/null \
  || fail "competing Advanced Editor Tools settings did not produce a typed options conflict: $CONFLICT_PLAN"
CONFLICT_BEFORE=$(wp_conf2 option get tadv_settings --format=json | jq -r '.toolbar_1')
require_observed_nonempty "Advanced Editor Tools target conflict baseline" "$CONFLICT_BEFORE"
CONFLICT_RC=0
CONFLICT_OUT=$(wp_conf2 duo apply --repo=/siterepo --default-author=admin 2>&1) || CONFLICT_RC=$?
require_duo_answered "Advanced Editor Tools unforced competing-settings apply" human "$CONFLICT_OUT"
[ "$CONFLICT_RC" -ne 0 ] && grep -qi 'conflicts (env and repo both changed' <<<"$CONFLICT_OUT" \
  || fail "Advanced Editor Tools competing settings did not refuse before mutation: $CONFLICT_OUT"
CONFLICT_AFTER=$(wp_conf2 option get tadv_settings --format=json | jq -r '.toolbar_1')
require_observed_nonempty "Advanced Editor Tools target after unforced conflict" "$CONFLICT_AFTER"
[ "$CONFLICT_AFTER" = "$CONFLICT_BEFORE" ] \
  || fail "unforced Advanced Editor Tools conflict partially mutated the target (before=$CONFLICT_BEFORE after=$CONFLICT_AFTER)"
FORCED=$(wp_conf2 duo apply --repo=/siterepo --force-theirs --default-author=admin --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered "Advanced Editor Tools forced competing-settings apply" json "$FORCED"
jq -e '.warnings | any(contains("FORCED conflict options/core"))' <<<"$FORCED" >/dev/null \
  || fail "forced Advanced Editor Tools conflict did not report its destructive override: $FORCED"
[ "$(wp_conf2 option get tadv_settings --format=json | jq -r '.toolbar_1')" = "bold,code" ] \
  || fail "forced Advanced Editor Tools conflict did not converge to repository intent"

ZERO_PLAN=$(wp_conf2 duo plan --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered "Advanced Editor Tools zero-change plan" json "$ZERO_PLAN"
jq -e '
  (.create | length) == 0 and (.update | length) == 0 and
  (.conflict | length) == 0 and (.drift | length) == 0
' <<<"$ZERO_PLAN" >/dev/null || fail "Advanced Editor Tools retry was not a zero-change plan: $ZERO_PLAN"
ZERO_APPLY=$(wp_conf2 duo apply --repo=/siterepo --default-author=admin --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered "Advanced Editor Tools zero-change apply" json "$ZERO_APPLY"
[ "$(jq -r '.canary' <<<"$ZERO_APPLY")" = "clean" ] \
  || fail "Advanced Editor Tools zero-change retry dirtied the apply canary: $ZERO_APPLY"
pass "Advanced Editor Tools competing branch settings refuse without mutation, forced intent converges, and retry is idempotent"

wp_conf1 eval "delete_option('tadv_admin_settings');" >/dev/null
wp_conf1 duo capture --repo=/siterepo >/dev/null
git -C "$CONF_REPO1" add -A
git -C "$CONF_REPO1" -c user.name=duo -c user.email=duo@example.test commit -qm 'conformance: delete Advanced Editor Tools admin settings'
git -C "$CONF_REPO1" push -q origin main
git -C "$CONF_REPO2" pull -q origin main

DELETE_RC=0
DELETE_OUT=$(wp_conf2 duo apply --repo=/siterepo --default-author=admin 2>&1) || DELETE_RC=$?
require_duo_answered "Advanced Editor Tools option deletion without authorization" human "$DELETE_OUT"
[ "$DELETE_RC" -ne 0 ] && grep -q 'authored option deletion intent requires --with-deletes' <<<"$DELETE_OUT" \
  || fail "Advanced Editor Tools option deletion did not require explicit authorization: $DELETE_OUT"
wp_conf2 option get tadv_admin_settings >/dev/null \
  || fail "unauthorized Advanced Editor Tools option deletion partially mutated the target"
DELETE_APPLY=$(wp_conf2 duo apply --repo=/siterepo --with-deletes --default-author=admin --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered "Advanced Editor Tools authorized option deletion" json "$DELETE_APPLY"
if wp_conf2 option get tadv_admin_settings >/dev/null 2>&1; then
  fail "authorized Advanced Editor Tools option deletion left the row present"
fi
[ "$(wp_conf2 option get tadv_settings --format=json | jq -r '.toolbar_1')" = "bold,code" ] \
  && [ "$(wp_conf2 option get tadv_future_setting)" = "target-only-neighbor" ] \
  || fail "authorized Advanced Editor Tools deletion mutated a sibling authored option or undeclared neighbor"
wp_conf2 duo capture --repo=/siterepo --out=/siterepo/.tmp-advanced-editor-tools-delete-state >/dev/null
diff -r "$CONF_REPO1/state" "$CONF_REPO2/.tmp-advanced-editor-tools-delete-state" \
  || fail "Advanced Editor Tools authorized deletion did not recapture byte-identically"
rm -rf "$CONF_REPO2/.tmp-advanced-editor-tools-delete-state"
pass "Advanced Editor Tools option deletion refuses without --with-deletes, deletes exactly one row when authorized, and recaptures identically"
