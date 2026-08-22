#!/usr/bin/env bash
# Production-oriented Classic Editor proof. The generic harness already
# proved clean apply and byte-identical recapture; this hook adds plugin-owned
# behavior, hostile-target namespace safety, lifecycle recovery, explicit
# option deletion, and a real three-way settings conflict.
set -euo pipefail

observe_classic_editor() {
  local side="$1" repo_var file out
  case "$side" in
    conf1) repo_var="${CONF_REPO1:-siterepo/conf1}" ;;
    conf2) repo_var="${CONF_REPO2:-siterepo/conf2}" ;;
    *) fail "invalid Classic Editor observation side: $side" ;;
  esac
  read -r -d '' OBSERVE_PHP <<'PHPEOF' || true
<?php
wp_set_current_user(1);
$plain = get_page_by_path('classic-editor-plain-fixture', OBJECT, 'post');
$blocks = get_page_by_path('classic-editor-block-fixture', OBJECT, 'post');
if (!$plain || !$blocks) {
    throw new RuntimeException('Classic Editor post fixtures are missing');
}
$get_settings = new ReflectionMethod('Classic_Editor', 'get_settings');
$settings = $get_settings->invoke(null, 'refresh', 1);
echo wp_json_encode([
    'allow' => get_option('classic-editor-allow-users'),
    'block_post_uses_blocks' => (bool) use_block_editor_for_post($blocks),
    'invalid_allow' => Classic_Editor::validate_option_allow_users("invalid-未知-\u{1F680}"),
    'invalid_editor' => Classic_Editor::validate_option_editor("invalid-未知-\u{1F680}"),
    'neighbor' => get_option('classic-editor-target-runtime-probe', null),
    'plain_post_uses_blocks' => (bool) use_block_editor_for_post($plain),
    'post_type_uses_blocks' => (bool) use_block_editor_for_post_type('post'),
    'replace' => get_option('classic-editor-replace'),
    'settings' => $settings,
]);
PHPEOF
  file="$repo_var/.tmp-classic-editor-observe.php"
  printf '%s' "$OBSERVE_PHP" > "$file"
  if [ "$side" = conf1 ]; then
    out=$(wp_conf1 eval-file /siterepo/.tmp-classic-editor-observe.php)
  else
    out=$(wp_conf2 eval-file /siterepo/.tmp-classic-editor-observe.php)
  fi
  rm -f "$file"
  require_observed_nonempty "Classic Editor $side runtime observation" "$out"
  printf '%s\n' "$out" | awk 'NF { line=$0 } END { print line }'
}

INITIAL=$(observe_classic_editor conf2)
printf '%s\n' "$INITIAL" | jq -e '
  .replace == "classic" and .allow == "allow" and
  .settings.editor == "classic" and .settings["allow-users"] == true and
  .plain_post_uses_blocks == false and .block_post_uses_blocks == true and
  .post_type_uses_blocks == true and
  .invalid_editor == "classic" and .invalid_allow == "disallow" and
  .neighbor == "target-only-neighbor"
' >/dev/null || fail "Classic Editor target APIs do not consume the applied settings safely: $INITIAL"
pass "Classic Editor converges opposite settings, selects editors through its own runtime, normalizes hostile UTF-8 values, and preserves an undeclared neighbor"

wp_conf2 plugin deactivate classic-editor >/dev/null
[ "$(wp_conf2 option get classic-editor-replace)" = "classic" ] \
  || fail "Classic Editor deactivation removed or rewrote the authored default-editor setting"
[ "$(wp_conf2 option get classic-editor-allow-users)" = "allow" ] \
  || fail "Classic Editor deactivation removed or rewrote the authored user-choice setting"
DEPLOY_AFTER_DEACTIVATE=$(wp_conf2 duo deploy --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered "Classic Editor deploy after deactivation" json "$DEPLOY_AFTER_DEACTIVATE"
wp_conf2 plugin is-active classic-editor >/dev/null \
  || fail "Duo deploy did not reactivate exact Classic Editor code"
REACTIVATED=$(observe_classic_editor conf2)
printf '%s\n' "$REACTIVATED" | jq -e '
  .replace == "classic" and .allow == "allow" and
  .plain_post_uses_blocks == false and .block_post_uses_blocks == true
' >/dev/null || fail "Classic Editor settings or behavior changed across deactivate/reactivate: $REACTIVATED"
pass "Classic Editor deactivation preserves authored state and deploy reactivation restores plugin behavior"

# Classic Editor registers its uninstall callback from init_actions(), which
# runs on plugins_loaded. WordPress activation includes an inactive plugin
# after that action has already fired, so the exact 1.7.0 artifact leaves the
# two options as uninstall residue. Make that residue hostile before uninstall:
# missing-code refusal must preserve it, then exact reinstall/apply must repair
# it rather than assuming uninstall or activation supplied clean defaults.
wp_conf2 eval "update_option('classic-editor-replace', Classic_Editor::validate_option_editor('block')); update_option('classic-editor-allow-users', Classic_Editor::validate_option_allow_users('disallow'));" >/dev/null
wp_conf2 plugin uninstall classic-editor --deactivate >/dev/null
if wp_conf2 plugin is-installed classic-editor >/dev/null 2>&1; then
  fail "Classic Editor uninstall left plugin code installed"
fi
UNINSTALL_ALLOW=$(wp_conf2 option get classic-editor-allow-users)
UNINSTALL_REPLACE=$(wp_conf2 option get classic-editor-replace)
require_observed_nonempty "Classic Editor user-choice residue after uninstall" "$UNINSTALL_ALLOW"
require_observed_nonempty "Classic Editor default-editor residue after uninstall" "$UNINSTALL_REPLACE"
[ "$UNINSTALL_ALLOW" = "disallow" ] && [ "$UNINSTALL_REPLACE" = "block" ] \
  || fail "Classic Editor exact uninstall residue changed unexpectedly: allow=$UNINSTALL_ALLOW replace=$UNINSTALL_REPLACE"
NEIGHBOR_AFTER_UNINSTALL=$(wp_conf2 option get classic-editor-target-runtime-probe)
require_observed_nonempty "Classic Editor target-only neighbor after uninstall" "$NEIGHBOR_AFTER_UNINSTALL"
[ "$NEIGHBOR_AFTER_UNINSTALL" = "target-only-neighbor" ] \
  || fail "Classic Editor uninstall or Duo lifecycle handling mutated the undeclared neighbor"

MISSING_RC=0
MISSING_OUT=$(wp_conf2 duo deploy --repo=/siterepo 2>&1) || MISSING_RC=$?
require_duo_answered "Classic Editor deploy with code absent" human "$MISSING_OUT"
[ "$MISSING_RC" -ne 0 ] && grep -Eq 'code_mismatch|missing_in_code|is not installed' <<<"$MISSING_OUT" \
  || fail "missing Classic Editor code did not refuse at the compatibility boundary: $MISSING_OUT"
if wp_conf2 plugin is-installed classic-editor >/dev/null 2>&1; then
  fail "missing-code refusal installed Classic Editor as a fallback"
fi
[ "$(wp_conf2 option get classic-editor-target-runtime-probe)" = "target-only-neighbor" ] \
  || fail "missing-code refusal partially mutated the target"
[ "$(wp_conf2 option get classic-editor-replace)" = "block" ] \
  && [ "$(wp_conf2 option get classic-editor-allow-users)" = "disallow" ] \
  || fail "missing-code refusal changed Classic Editor's hostile uninstall residue"

CLASSIC_SHA=7cc7799b0b7d820fbb7528dbff8027c736e52513992416cb37279f44d3d9dd77
CLASSIC_ARTIFACT="/artifacts-cache/plugin-classic-editor-1.7.0-${CLASSIC_SHA}.zip"
OBSERVED_SHA=$(wp_conf2 eval "echo hash_file('sha256', '$CLASSIC_ARTIFACT');")
require_observed_nonempty "Classic Editor cached artifact digest" "$OBSERVED_SHA"
[ "$OBSERVED_SHA" = "$CLASSIC_SHA" ] \
  || fail "Classic Editor reinstall artifact digest moved (expected=$CLASSIC_SHA actual=$OBSERVED_SHA)"
wp_conf2 plugin install "$CLASSIC_ARTIFACT" --force >/dev/null
[ "$(wp_conf2 plugin get classic-editor --field=version)" = "1.7.0" ] \
  || fail "Classic Editor exact reinstall reported the wrong version"
REINSTALL_DEPLOY=$(wp_conf2 duo deploy --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered "Classic Editor deploy after exact reinstall" json "$REINSTALL_DEPLOY"
REINSTALL_APPLY=$(wp_conf2 duo apply --repo=/siterepo --default-author=admin --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered "Classic Editor apply after exact reinstall" json "$REINSTALL_APPLY"
[ "$(jq -r '.canary' <<<"$REINSTALL_APPLY")" = "clean" ] \
  || fail "Classic Editor reinstall recovery did not keep the apply canary clean: $REINSTALL_APPLY"
RECOVERED=$(observe_classic_editor conf2)
printf '%s\n' "$RECOVERED" | jq -e '
  .replace == "classic" and .allow == "allow" and
  .plain_post_uses_blocks == false and .block_post_uses_blocks == true and
  .neighbor == "target-only-neighbor"
' >/dev/null || fail "Classic Editor exact reinstall/apply did not recover source behavior: $RECOVERED"
pass "Classic Editor hostile uninstall residue, absent-code refusal, exact reinstall, deploy, and apply retry recover without partial neighbor mutation"

wp_conf1 eval "update_option('classic-editor-replace', Classic_Editor::validate_option_editor('block')); update_option('classic-editor-allow-users', Classic_Editor::validate_option_allow_users('disallow'));" >/dev/null
wp_conf1 duo capture --repo=/siterepo >/dev/null
git -C "$CONF_REPO1" add -A
git -C "$CONF_REPO1" -c user.name=duo -c user.email=duo@example.test commit -qm 'conformance: Classic Editor repository branch intent'
git -C "$CONF_REPO1" push -q origin main
wp_conf2 eval "update_option('classic-editor-replace', Classic_Editor::validate_option_editor('classic')); update_option('classic-editor-allow-users', Classic_Editor::validate_option_allow_users('disallow'));" >/dev/null
git -C "$CONF_REPO2" pull -q origin main

CONFLICT_PLAN=$(wp_conf2 duo plan --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered "Classic Editor competing-settings plan" json "$CONFLICT_PLAN"
jq -e '.conflict | any(.uuid == "options/core" and .type == "options")' <<<"$CONFLICT_PLAN" >/dev/null \
  || fail "competing Classic Editor settings did not produce a typed options conflict: $CONFLICT_PLAN"
CONFLICT_BEFORE=$(wp_conf2 eval "echo get_option('classic-editor-replace') . '|' . get_option('classic-editor-allow-users');")
require_observed_nonempty "Classic Editor target conflict baseline" "$CONFLICT_BEFORE"
CONFLICT_RC=0
CONFLICT_OUT=$(wp_conf2 duo apply --repo=/siterepo --default-author=admin 2>&1) || CONFLICT_RC=$?
require_duo_answered "Classic Editor unforced competing-settings apply" human "$CONFLICT_OUT"
[ "$CONFLICT_RC" -ne 0 ] && grep -qi 'conflicts (env and repo both changed' <<<"$CONFLICT_OUT" \
  || fail "Classic Editor competing settings did not refuse before mutation: $CONFLICT_OUT"
CONFLICT_AFTER=$(wp_conf2 eval "echo get_option('classic-editor-replace') . '|' . get_option('classic-editor-allow-users');")
require_observed_nonempty "Classic Editor target after unforced conflict" "$CONFLICT_AFTER"
[ "$CONFLICT_AFTER" = "$CONFLICT_BEFORE" ] \
  || fail "unforced Classic Editor conflict partially mutated the target (before=$CONFLICT_BEFORE after=$CONFLICT_AFTER)"
FORCED=$(wp_conf2 duo apply --repo=/siterepo --force-theirs --default-author=admin --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered "Classic Editor forced competing-settings apply" json "$FORCED"
jq -e '.warnings | any(contains("FORCED conflict options/core"))' <<<"$FORCED" >/dev/null \
  || fail "forced Classic Editor conflict did not report its destructive override: $FORCED"
[ "$(wp_conf2 eval "echo get_option('classic-editor-replace') . '|' . get_option('classic-editor-allow-users');")" = "block|disallow" ] \
  || fail "forced Classic Editor conflict did not converge to repository intent"

ZERO_PLAN=$(wp_conf2 duo plan --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered "Classic Editor zero-change plan" json "$ZERO_PLAN"
jq -e '
  (.create | length) == 0 and (.update | length) == 0 and
  (.conflict | length) == 0 and (.drift | length) == 0
' <<<"$ZERO_PLAN" >/dev/null || fail "Classic Editor retry was not a zero-change plan: $ZERO_PLAN"
ZERO_APPLY=$(wp_conf2 duo apply --repo=/siterepo --default-author=admin --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered "Classic Editor zero-change apply" json "$ZERO_APPLY"
[ "$(jq -r '.canary' <<<"$ZERO_APPLY")" = "clean" ] \
  || fail "Classic Editor zero-change retry dirtied the apply canary: $ZERO_APPLY"
pass "Classic Editor competing branch settings refuse without mutation, forced intent converges, and retry is idempotent"

wp_conf1 eval "delete_option('classic-editor-allow-users');" >/dev/null
wp_conf1 duo capture --repo=/siterepo >/dev/null
git -C "$CONF_REPO1" add -A
git -C "$CONF_REPO1" -c user.name=duo -c user.email=duo@example.test commit -qm 'conformance: delete Classic Editor user-choice setting'
git -C "$CONF_REPO1" push -q origin main
git -C "$CONF_REPO2" pull -q origin main

DELETE_RC=0
DELETE_OUT=$(wp_conf2 duo apply --repo=/siterepo --default-author=admin 2>&1) || DELETE_RC=$?
require_duo_answered "Classic Editor option deletion without authorization" human "$DELETE_OUT"
[ "$DELETE_RC" -ne 0 ] && grep -q 'authored option deletion intent requires --with-deletes' <<<"$DELETE_OUT" \
  || fail "Classic Editor option deletion did not require explicit authorization: $DELETE_OUT"
[ "$(wp_conf2 option get classic-editor-allow-users)" = "disallow" ] \
  || fail "unauthorized Classic Editor option deletion partially mutated the target"
DELETE_APPLY=$(wp_conf2 duo apply --repo=/siterepo --with-deletes --default-author=admin --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered "Classic Editor authorized option deletion" json "$DELETE_APPLY"
if wp_conf2 option get classic-editor-allow-users >/dev/null 2>&1; then
  fail "authorized Classic Editor option deletion left the row present"
fi
[ "$(wp_conf2 option get classic-editor-replace)" = "block" ] \
  && [ "$(wp_conf2 option get classic-editor-target-runtime-probe)" = "target-only-neighbor" ] \
  || fail "authorized Classic Editor deletion mutated a sibling owned option or undeclared neighbor"
wp_conf2 duo capture --repo=/siterepo --out=/siterepo/.tmp-classic-editor-delete-state >/dev/null
diff -r "$CONF_REPO1/state" "$CONF_REPO2/.tmp-classic-editor-delete-state" \
  || fail "Classic Editor authorized deletion did not recapture byte-identically"
rm -rf "$CONF_REPO2/.tmp-classic-editor-delete-state"
pass "Classic Editor option deletion refuses without --with-deletes, deletes exactly one row when authorized, and recaptures identically"
