#!/usr/bin/env bash
set -euo pipefail
PACKAGE_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
export WPRISM_ARTIFACT_PACKAGE="${PACKAGE_ROOT##*/}"
AIO_CAPSULE="$PACKAGE_ROOT"
# Keep BASH_SOURCE absolute before changing cwd so capsule-relative sourced
# hooks resolve identically under the package runner and a direct invocation.
[[ ${BASH_SOURCE[0]} = /* ]] || exec bash "$AIO_CAPSULE/tests/live/regress_native_roundtrip.sh" "$@"
cd "$AIO_CAPSULE/../../sandbox"
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }
pass() { printf 'ok: %s\n' "$*"; }
. tests/lib/pair_live_ownership.sh
. conformance/asserts.sh
export WPRISM_SOURCE_ROOT="$(cd .. && pwd -P)"
: "${WPRISM_EXPECTED_SOURCE_SHA:?Set the exact committed source SHA for native qualification}"
export CONF_PAIR="${AIO_PAIR:-aiort}" CONF1_PORT="${AIO_PORT1:-9160}" CONF2_PORT="${AIO_PORT2:-9161}"
export WPRISM_PAIR="$CONF_PAIR" WPRISM_PORT1="$CONF1_PORT" WPRISM_PORT2="$CONF2_PORT"
export WPRISM_CLI_IMAGE="${WPRISM_CLI_IMAGE:-wprism-aio-native-cli:latest}"
pair_live_ownership_prepare "$CONF_PAIR" "$CONF1_PORT" "$CONF2_PORT" 'AIO native target qualification' aio-target
pair_live_ownership_acquire mariadb
pair_live_ownership_up --http --artifacts --git-cli
COMPOSE=(docker compose -p "wprism-$CONF_PAIR" -f pair.yml -f pair.http.yml -f pair.artifacts.yml)
wp_conf1() { "${COMPOSE[@]}" run --rm -T cli1 wp "$@"; }
wp_conf2() { "${COMPOSE[@]}" run --rm -T cli2 wp "$@"; }
export CONF_REPO1="siterepo/${CONF_PAIR}1" CONF_REPO2="siterepo/${CONF_PAIR}2"
PAIR_COMPOSE=("${COMPOSE[@]}")
. bin/fetch-artifact.sh
for side in 1 2; do
  AIO_ARTIFACT=$(fetch_artifact change-wp-admin-login 2.4.1 "cli$side")
  "wp_conf$side" site empty --yes
  "wp_conf$side" plugin install "$AIO_ARTIFACT"
done
wp_conf1 plugin activate change-wp-admin-login
git init --bare -q -b main "siterepo/origin-${CONF_PAIR}.git"
printf '%s\n' '{"manifests":["core","change-wp-admin-login"],"policy":{"options":{},"post_meta":{},"post_types":["post","page","attachment"],"taxonomies":["category","post_tag"]},"spec_version":3}' > "$CONF_REPO1/site.wprism.json"
cp site-repo.gitignore.template "$CONF_REPO1/.gitignore"
git -C "$CONF_REPO1" init -q -b main
git -C "$CONF_REPO1" remote add origin "../origin-${CONF_PAIR}.git"
. "$(dirname "${BASH_SOURCE[0]}")/../conformance/seed.sh"
establish_core_environment_bindings wp_conf1 /siterepo admin@example.test "http://localhost:$CONF1_PORT" "http://localhost:$CONF1_PORT"
wp_conf1 wprism capture --repo=/siterepo
git -C "$CONF_REPO1" add -A
git -C "$CONF_REPO1" -c user.name=wprism -c user.email=wprism@example.test commit -qm 'AIO native source qualification'
git -C "$CONF_REPO1" push -qu origin main
# Native target qualification follows the same freshly captured source.
wp1() { "${COMPOSE[@]}" run --rm -T cli1 wp "$@"; }
wp2() { "${COMPOSE[@]}" run --rm -T cli2 wp "$@"; }
R1="siterepo/${CONF_PAIR}1"
R2="siterepo/${CONF_PAIR}2"
AIO_TARGET_EVIDENCE="tmp/plugin-adapters/change-wp-admin-login/target-${CONF_PAIR}"
mkdir -p "$AIO_TARGET_EVIDENCE"
git clone -q "siterepo/origin-${CONF_PAIR}.git" "$R2"
establish_core_environment_bindings wp2 /siterepo admin@example.test "http://localhost:$CONF2_PORT" "http://localhost:$CONF2_PORT"
wp2 db query 'ALTER TABLE wp_posts AUTO_INCREMENT=801'
wp2 wprism deploy --repo=/siterepo
wp2 option update aio_login_google_recaptcha_v2_secret_key aio-target-secret
REV=$(git -C "$R2" rev-parse HEAD)
capture_wprism_json_checked APPLY_JSON 'AIO target apply' assert_wprism_apply_ready wp2 wprism apply --repo=/siterepo --adopt-by-slug=terms,posts --default-author=admin --revision="$REV" --json
printf '%s\n' "$APPLY_JSON" > "$AIO_TARGET_EVIDENCE/apply.json"
cp ../adapter-packages/change-wp-admin-login/fixtures/native/verify-canonical.php "$R2/.tmp-aio-verify-canonical.php"
capture_wprism_json_success AIO_NATIVE 'AIO target complete canonical values' wp2 eval-file /siterepo/.tmp-aio-verify-canonical.php --use-include
printf '%s\n' "$AIO_NATIVE" > "$AIO_TARGET_EVIDENCE/canonical.json"
cp "$AIO_CAPSULE/fixtures/native/target-native.php" "$R2/.tmp-aio-target-native.php"
capture_wprism_json_success AIO_NATIVE 'AIO target native API behavior' wp2 eval-file /siterepo/.tmp-aio-target-native.php --use-include
printf '%s\n' "$AIO_NATIVE" > "$AIO_TARGET_EVIDENCE/native.json"
python3 "$AIO_CAPSULE/fixtures/native/target-http.py" "http://localhost:$CONF2_PORT" "http://localhost:$CONF1_PORT" "$AIO_TARGET_EVIDENCE/http" > "$AIO_TARGET_EVIDENCE/http.json"
capture_wprism_json_checked APPLY_JSON 'AIO repeated target apply' assert_wprism_apply_ready wp2 wprism apply --repo=/siterepo --default-author=admin --revision="$REV" --json
printf '%s\n' "$APPLY_JSON" > "$AIO_TARGET_EVIDENCE/repeated-apply.json"
wp2 wprism capture --repo=/siterepo --out=/siterepo/.tmp-aio-recapture
diff -r "$R1/state" "$R2/.tmp-aio-recapture" > "$AIO_TARGET_EVIDENCE/recapture.diff"
cp "$AIO_CAPSULE/fixtures/native/security-native.php" "$R2/.tmp-aio-security-native.php"
capture_wprism_json_success AIO_SECURITY 'AIO native password lockout' wp2 eval-file /siterepo/.tmp-aio-security-native.php --use-include
printf '%s\n' "$AIO_SECURITY" > "$AIO_TARGET_EVIDENCE/security.json"
pass 'AIO target native APIs, HTTP login, different IDs, preserved environment, repeat and complete recapture qualify'
# Continues the isolated native roundtrip after its first canonical recapture.
export WPRISM_ARTIFACT_LIBRARY_ROOT="$WPRISM_SOURCE_ROOT"
. "$(dirname "${BASH_SOURCE[0]}")/../../fixtures/native/private-command.sh"
for side in 1 2; do
  cp "$AIO_CAPSULE/fixtures/native/profile-update.php" "siterepo/${CONF_PAIR}${side}/.tmp-aio-profile-update.php"
  cp "$AIO_CAPSULE/fixtures/native/state-observe.php" "siterepo/${CONF_PAIR}${side}/.tmp-aio-state-observe.php"
done
aio_observe() {
  local side="$1" label="$2"
  capture_wprism_json_success AIO_OBSERVATION "AIO $label state witness" "wp$side" eval-file /siterepo/.tmp-aio-state-observe.php --use-include
  printf '%s\n' "$AIO_OBSERVATION" > "$AIO_TARGET_EVIDENCE/$label.json"
}
aio_profile() {
  local side="$1" mode="$2"
  printf '%s\n' "$mode" > "siterepo/${CONF_PAIR}${side}/.tmp-aio-profile"
  capture_wprism_json_success AIO_PROFILE "AIO native $mode profile" "wp$side" eval-file /siterepo/.tmp-aio-profile-update.php --use-include
  printf '%s\n' "$AIO_PROFILE" > "$AIO_TARGET_EVIDENCE/profile-$side-$mode.json"
}
aio_publish() {
  wp1 wprism capture --repo=/siterepo
  git -C "$R1" add -A
  git -C "$R1" -c user.name=wprism -c user.email=wprism@example.test commit -qm "$1"
  git -C "$R1" push -q origin main
  git -C "$R2" pull --ff-only -q origin main
  REV=$(git -C "$R2" rev-parse HEAD)
}
aio_recapture() {
  local label="$1"
  wp2 wprism capture --repo=/siterepo --out="/siterepo/.tmp-aio-$label"
  diff -r "$R1/state" "$R2/.tmp-aio-$label" > "$AIO_TARGET_EVIDENCE/$label.diff"
}
aio_profile 1 repository
aio_profile 2 target
aio_publish 'AIO explicit repository update competes with target settings'
capture_wprism_json_success AIO_PLAN 'AIO competing settings plan' wp2 wprism plan --repo=/siterepo --format=json
jq -e '(.conflict | any(.uuid=="options/core")) and (.code_mismatch|length)==0 and (.drift|length)==0' <<<"$AIO_PLAN" >/dev/null || fail 'AIO competing native writes did not produce the expected option conflict'
aio_observe 2 conflict-before
AIO_BEFORE="$AIO_OBSERVATION"
capture_wprism_json_refusal AIO_REFUSAL 'AIO unforced competing settings' aio_private_command cli2 apply conflict wp2 wprism apply --repo=/siterepo --default-author=admin --revision="$REV" --json
printf '%s\n' "$AIO_REFUSAL" > "$AIO_TARGET_EVIDENCE/conflict-refusal.json"
aio_observe 2 conflict-after
[ "$AIO_BEFORE" = "$AIO_OBSERVATION" ] || fail 'AIO unforced conflict mutated owned rows or ledger'
capture_wprism_json_checked APPLY_JSON 'AIO explicit conflict resolution' assert_wprism_apply_ready wp2 wprism apply --repo=/siterepo --force-theirs --default-author=admin --revision="$REV" --json
jq -e '.warnings | any(contains("FORCED conflict options/core"))' <<<"$APPLY_JSON" >/dev/null || fail 'AIO explicit conflict resolution omitted the override receipt'
printf '%s\n' "$APPLY_JSON" > "$AIO_TARGET_EVIDENCE/conflict-resolved.json"
capture_wprism_json_success AIO_BRANCH 'AIO reversed native reference branches' wp2 eval 'wp_set_current_user(1); $p=get_page_by_path("aio-login-destination"); $rules=get_option("aio_login_pro_login_redirection_rules"); if (!$p || $p->ID<801 || $rules[0]["logout_target_value"]!==(string)$p->ID) throw new RuntimeException("reversed page reference did not map"); $u=get_userdata(1); $login=apply_filters("login_redirect",admin_url(),admin_url(),$u); $logout=apply_filters("logout_redirect",wp_login_url(),"",$u); if($login!==home_url("/aio-logout-destination/")||$logout!==get_permalink($p)) throw new RuntimeException("reversed native redirects failed"); echo wp_json_encode(["login"=>$login,"logout"=>$logout,"rules"=>$rules,"css"=>get_option("aio_login_custom-css")]);'
printf '%s\n' "$AIO_BRANCH" > "$AIO_TARGET_EVIDENCE/reversed-native-branches.json"
aio_observe 2 resolved-state
AIO_RUNTIME_BEFORE=$(jq -c '.tables | with_entries(select(.key | startswith("aio_login_")))' <<<"$AIO_BEFORE")
AIO_RUNTIME_AFTER=$(jq -c '.tables | with_entries(select(.key | startswith("aio_login_")))' <<<"$AIO_OBSERVATION")
[ "$AIO_RUNTIME_BEFORE" = "$AIO_RUNTIME_AFTER" ] || fail 'AIO conflict resolution changed target runtime tables'
aio_recapture updated
# Native free writers cannot create this Pro row. Inject it as hostile storage;
# capture must preserve the previous complete publication and every ledger row.
wp1 eval '$v=get_option("aio_login_pro_login_redirection_rules"); file_put_contents("/siterepo/.tmp-aio-valid-rules",serialize($v)); $v[0]["condition_type"]="user"; $v[0]["condition_value"]="1,2"; update_option("aio_login_pro_login_redirection_rules",$v);'
aio_observe 1 malformed-before
AIO_BEFORE="$AIO_OBSERVATION"
cp -R "$R1/state" "$R1/.tmp-aio-before-refusal"
capture_wprism_json_refusal AIO_REFUSAL 'AIO Pro residue capture refusal' aio_private_command cli1 capture pro-residue wp1 wprism capture --repo=/siterepo --format=json
printf '%s\n' "$AIO_REFUSAL" > "$AIO_TARGET_EVIDENCE/malformed-refusal.json"
aio_observe 1 malformed-after
[ "$AIO_BEFORE" = "$AIO_OBSERVATION" ] || fail 'AIO malformed capture mutated native or ledger state'
diff -r "$R1/.tmp-aio-before-refusal" "$R1/state" > "$AIO_TARGET_EVIDENCE/malformed-publication.diff"
wp1 eval 'update_option("aio_login_pro_login_redirection_rules",unserialize(file_get_contents("/siterepo/.tmp-aio-valid-rules"),["allowed_classes"=>false]));'
wp1 wprism capture --repo=/siterepo --out=/siterepo/.tmp-aio-after-refusal
diff -r "$R1/state" "$R1/.tmp-aio-after-refusal" > "$AIO_TARGET_EVIDENCE/recovered-capture.diff"
# A cleared native option produces an explicit generic OptionState tombstone.
wp1 option delete aio_login_custom-css
aio_publish 'AIO explicit custom CSS option deletion'
aio_observe 2 delete-before
AIO_BEFORE="$AIO_OBSERVATION"
capture_wprism_json_refusal AIO_REFUSAL 'AIO option deletion without authority' aio_private_command cli2 apply delete wp2 wprism apply --repo=/siterepo --default-author=admin --revision="$REV" --json
printf '%s\n' "$AIO_REFUSAL" > "$AIO_TARGET_EVIDENCE/delete-refusal.json"
aio_observe 2 delete-after
[ "$AIO_BEFORE" = "$AIO_OBSERVATION" ] || fail 'AIO unauthorized deletion mutated native or ledger state'
capture_wprism_json_checked APPLY_JSON 'AIO authorized option deletion' assert_wprism_apply_ready wp2 wprism apply --repo=/siterepo --with-deletes --default-author=admin --revision="$REV" --json
printf '%s\n' "$APPLY_JSON" > "$AIO_TARGET_EVIDENCE/delete-applied.json"
capture_wprism_json_success AIO_ABSENCE 'AIO native absent CSS fallback' wp2 eval '$absent=new stdClass(); if(get_option("aio_login_custom-css",$absent)!==$absent) throw new RuntimeException("CSS option remains"); wp_set_current_user(1); $r=rest_do_request(new WP_REST_Request("GET","/aio-login/custom-css/get-settings")); if($r->get_status()!==200||$r->get_data()["custom_css"]!=="") throw new RuntimeException("native absent CSS fallback failed"); echo wp_json_encode(["css_option_absent"=>true,"native_fallback_empty"=>true]);'
printf '%s\n' "$AIO_ABSENCE" > "$AIO_TARGET_EVIDENCE/delete-native.json"
aio_recapture deleted
pass 'AIO reversed native refs, difficult text, conflict refusal/resolution, malformed capture atomicity, explicit option deletion and retries pass'
# Native lifecycle owns plugin cleanup; the engine owns compatibility and
# explicit reconciliation against the previously synchronized option base.
aio_observe 2 deactivate-before
AIO_BEFORE="$AIO_OBSERVATION"
wp2 plugin deactivate change-wp-admin-login
aio_observe 2 deactivate-after
[ "$AIO_BEFORE" = "$AIO_OBSERVATION" ] || fail 'AIO deactivation mutated owned settings or runtime'
capture_wprism_json_success AIO_DEPLOY 'AIO exact reactivation' wp2 wprism deploy --repo=/siterepo --format=json
printf '%s\n' "$AIO_DEPLOY" > "$AIO_TARGET_EVIDENCE/reactivate.json"
wp2 plugin is-active change-wp-admin-login
aio_observe 2 reactivate-after
[ "$(jq -c '{options_sha256,tables: (.tables | with_entries(select(.key | startswith("aio_login_"))))}' <<<"$AIO_BEFORE")" = "$(jq -c '{options_sha256,tables: (.tables | with_entries(select(.key | startswith("aio_login_"))))}' <<<"$AIO_OBSERVATION")" ] || fail 'AIO exact reactivation changed owned state'
wp2 plugin uninstall change-wp-admin-login --deactivate
if wp2 plugin is-installed change-wp-admin-login; then fail 'AIO uninstall left code'; fi
aio_observe 2 uninstall-after
jq -e '([.option_names[] | select(startswith("aio_login"))]|length)==0 and .tables.aio_login_login_attempts.exists==false and .tables.aio_login_login_lockouts.exists==false and .tables.aio_login_enumeration_logs.exists==true' <<<"$AIO_OBSERVATION" >/dev/null || fail 'AIO native uninstall residue differs from the reviewed native cleanup contract'
AIO_BEFORE="$AIO_OBSERVATION"
capture_wprism_json_refusal AIO_REFUSAL 'AIO missing code deploy' aio_private_command cli2 deploy missing-code wp2 wprism deploy --repo=/siterepo --format=json
printf '%s\n' "$AIO_REFUSAL" > "$AIO_TARGET_EVIDENCE/missing-code-refusal.json"
aio_observe 2 missing-code-after
[ "$AIO_BEFORE" = "$AIO_OBSERVATION" ] || fail 'AIO missing-code refusal mutated owned state'
AIO_OLD_ARTIFACT=$(fetch_artifact change-wp-admin-login 2.4.0 cli2)
wp2 plugin install "$AIO_OLD_ARTIFACT"
[ "$(wp2 plugin get change-wp-admin-login --field=version)" = 2.4.0 ] || fail 'AIO refusal fixture is not official 2.4.0'
aio_observe 2 old-version-before
AIO_BEFORE="$AIO_OBSERVATION"
capture_wprism_json_refusal AIO_REFUSAL 'AIO official out-of-range deploy' aio_private_command cli2 deploy old-version wp2 wprism deploy --repo=/siterepo --format=json
printf '%s\n' "$AIO_REFUSAL" > "$AIO_TARGET_EVIDENCE/old-version-refusal.json"
aio_observe 2 old-version-after
[ "$AIO_BEFORE" = "$AIO_OBSERVATION" ] || fail 'AIO old-version refusal mutated owned state'
if wp2 plugin is-active change-wp-admin-login; then fail 'AIO old-version refusal activated unsupported code'; fi
AIO_ARTIFACT=$(fetch_artifact change-wp-admin-login 2.4.1 cli2)
wp2 plugin install "$AIO_ARTIFACT" --force
capture_wprism_json_success AIO_DEPLOY 'AIO exact reinstall activation' wp2 wprism deploy --repo=/siterepo --format=json
printf '%s\n' "$AIO_DEPLOY" > "$AIO_TARGET_EVIDENCE/reinstall-deploy.json"
# Native uninstall deletes environment credentials as well. Explicit local
# reprovisioning is required and is distinct from preservation during apply.
wp2 option update aio_login_google_recaptcha_v2_secret_key aio-target-secret
aio_profile 1 reinstall
aio_publish 'AIO native explicit reinstall recovery intent'
capture_wprism_json_success AIO_PLAN 'AIO reinstall conflict plan' wp2 wprism plan --repo=/siterepo --format=json
jq -e '.conflict | any(.uuid=="options/core")' <<<"$AIO_PLAN" >/dev/null || fail 'AIO native uninstall plus repository update omitted typed conflict'
aio_observe 2 reinstall-conflict-before
AIO_BEFORE="$AIO_OBSERVATION"
capture_wprism_json_refusal AIO_REFUSAL 'AIO unforced reinstall conflict' aio_private_command cli2 apply conflict wp2 wprism apply --repo=/siterepo --default-author=admin --revision="$REV" --json
printf '%s\n' "$AIO_REFUSAL" > "$AIO_TARGET_EVIDENCE/reinstall-conflict-refusal.json"
aio_observe 2 reinstall-conflict-after
[ "$AIO_BEFORE" = "$AIO_OBSERVATION" ] || fail 'AIO reinstall conflict partially restored settings'
capture_wprism_json_checked APPLY_JSON 'AIO explicit reinstall reconciliation' assert_wprism_apply_ready wp2 wprism apply --repo=/siterepo --force-theirs --default-author=admin --revision="$REV" --json
jq -e '.warnings | any(contains("FORCED conflict options/core"))' <<<"$APPLY_JSON" >/dev/null || fail 'AIO explicit reinstall omitted override receipt'
printf '%s\n' "$APPLY_JSON" > "$AIO_TARGET_EVIDENCE/reinstall-apply.json"
capture_wprism_json_success AIO_NATIVE 'AIO native reinstall behavior' wp2 eval 'wp_set_current_user(1); $page=get_page_by_path("aio-login-destination"); $u=get_userdata(1); $r=rest_do_request(new WP_REST_Request("GET","/aio-login/custom-css/get-settings")); $css=$r->get_data()["custom_css"]; if($r->get_status()!==200||strpos($css,"5px")===false||!$page||$page->ID<801||apply_filters("login_redirect",admin_url(),admin_url(),$u)!==home_url("/aio-logout-destination/")||apply_filters("logout_redirect",wp_login_url(),"",$u)!==get_permalink($page)||get_option("aio_login_google_recaptcha_v2_secret_key")!=="aio-target-secret") throw new RuntimeException("native reinstall recovery did not restore behavior and local configuration"); echo wp_json_encode(["css"=>$css,"page_id"=>$page->ID,"redirects"=>true,"locally_reprovisioned_secret"=>true]);'
printf '%s\n' "$AIO_NATIVE" > "$AIO_TARGET_EVIDENCE/reinstall-native.json"
aio_recapture reinstalled
capture_wprism_json_checked APPLY_JSON 'AIO repeated reinstall apply' assert_wprism_apply_ready wp2 wprism apply --repo=/siterepo --default-author=admin --revision="$REV" --json
printf '%s\n' "$APPLY_JSON" > "$AIO_TARGET_EVIDENCE/reinstall-repeated-apply.json"
pass 'AIO deactivation, native uninstall residue, missing/official-old-code refusals, exact reinstall and explicit recovery pass'

pair_live_ownership_complete 'PASS: AIO native, hostile and lifecycle qualification complete; pair destroyed'
