seed_rank_math_content() {
  wp_conf1() { wp1 "$@"; }
  local CONF_REPO1="siterepo/${PAIR}1"
  local CONF1_PORT="$PORT1"
  . "$(dirname "${BASH_SOURCE[0]}")/../conformance/seed.sh"
  unset -f wp_conf1
}

postdeploy_rank_math_content() {
  wp_conf1() { wp1 "$@"; }
  wp_conf2() { wp2 "$@"; }
  local CONF_REPO1="siterepo/${PAIR}1"
  local CONF_REPO2="siterepo/${PAIR}2"
  . "$(dirname "${BASH_SOURCE[0]}")/../conformance/postdeploy.sh"
  unset -f wp_conf1 wp_conf2
}

check_rank_math_boundary_content() {
  wp_conf1() { wp1 "$@"; }
  wp_conf2() { wp2 "$@"; }
  local CONF_REPO1="siterepo/${PAIR}1"
  local CONF_REPO2="siterepo/${PAIR}2"
  local CONF1_PORT="$PORT1"
  local CONF2_PORT="$PORT2"
  local RANK_MATH_BOUNDARY_ONLY=1
  local RANK_MATH_EXPECTED_VERSION="$RANK_MATH_VERSION"
  local APPLY_JSON="$RANK_MATH_BOUNDARY_APPLY_JSON"
  . "$(dirname "${BASH_SOURCE[0]}")/../conformance/check.sh"
  unset -f wp_conf1 wp_conf2
}

rank_math_site_policy() { # <repository>
  local repository="$1"
  printf '%s\n' \
    '{' \
    '  "manifests": ["core", "rank-math"],' \
    '  "policy": {' \
    '    "options": {},' \
    '    "post_meta": {},' \
    '    "post_types": ["post", "page", "attachment"],' \
    '    "taxonomies": ["category", "post_tag"]' \
    '  },' \
    '  "spec_version": 3' \
    '}' > "$repository/site.wprism.json"
}

rank_math_native_state_hash() { # <wp1|wp2>
  local side="$1"
  "$side" eval '
global $wpdb;
$tables = [];
foreach (["rank_math_internal_links", "rank_math_internal_meta", "rank_math_redirections", "rank_math_redirections_cache"] as $suffix) {
    $table = $wpdb->prefix . $suffix;
    $tables[$suffix] = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table
        ? $wpdb->get_results("SELECT * FROM `$table` ORDER BY 1", ARRAY_A)
        : null;
}
$payload = [
    "active" => array_values((array) get_option("active_plugins", [])),
    "options" => $wpdb->get_results(
        "SELECT option_name,option_value,autoload FROM {$wpdb->options} " .
        "WHERE option_name LIKE '\''rank\\_math%'\'' ESCAPE '\''\\\\'\'' ORDER BY option_name",
        ARRAY_A
    ),
    "postmeta" => $wpdb->get_results(
        "SELECT post_id,meta_key,meta_value FROM {$wpdb->postmeta} " .
        "WHERE meta_key LIKE '\''rank\\_math%'\'' ESCAPE '\''\\\\'\'' ORDER BY post_id,meta_key,meta_id",
        ARRAY_A
    ),
    "tables" => $tables,
    "termmeta" => $wpdb->get_results(
        "SELECT term_id,meta_key,meta_value FROM {$wpdb->termmeta} " .
        "WHERE meta_key LIKE '\''rank\\_math%'\'' ESCAPE '\''\\\\'\'' ORDER BY term_id,meta_key,meta_id",
        ARRAY_A
    ),
];
echo hash("sha256", wp_json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
'
}

VMATRIX_PLUGIN_SLUG=seo-by-rank-math

version_matrix_preflight() {
  [ -n "${WPRISM_EXPECTED_SOURCE_SHA:-}" ] \
    || fail 'Rank Math version-matrix evidence requires WPRISM_EXPECTED_SOURCE_SHA'
  export WPRISM_SOURCE_ROOT
  WPRISM_SOURCE_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../../.." && pwd -P)"
}

version_matrix_reset_after_delete() {
  local cli="$1"
  # Rank Math retains authored options and all four adapter-owned tables on
  # ordinary deletion. A patch case must prove its own installer/lifecycle,
  # not inherit schema or module choices from the preceding release.
  "$cli" db query "
    DROP TABLE IF EXISTS
      wp_rank_math_internal_links,
      wp_rank_math_internal_meta,
      wp_rank_math_redirections,
      wp_rank_math_redirections_cache;
    DELETE FROM wp_options
      WHERE option_name LIKE 'rank\\_math%' ESCAPE '\\\\'
         OR option_name LIKE 'rank-math-%';
    DELETE FROM wp_postmeta WHERE meta_key LIKE 'rank\\_math%' ESCAPE '\\\\';
    DELETE FROM wp_termmeta WHERE meta_key LIKE 'rank\\_math%' ESCAPE '\\\\';
  " >/dev/null
}

version_matrix_workflow() {
VMATRIX_CASES=$((VMATRIX_CASES + 1))
for RANK_MATH_VERSION in 1.0.277 1.0.277.1 1.0.277.2; do
  say "boundary: seo-by-rank-math $RANK_MATH_VERSION"

  reset_env wp1
  reset_env wp2
  reset_case_repositories

  say "fetch + verify seo-by-rank-math $RANK_MATH_VERSION (digest-checked official artifact)"
  RANK_MATH_ARTIFACT_1=$(fetch_artifact seo-by-rank-math "$RANK_MATH_VERSION" cli1)
  RANK_MATH_ARTIFACT_2=$(fetch_artifact seo-by-rank-math "$RANK_MATH_VERSION" cli2)
  wp1 plugin install "$RANK_MATH_ARTIFACT_1" --activate >/dev/null
  INSTALLED_1=$(wp1 plugin get seo-by-rank-math --field=version)
  [ "$INSTALLED_1" = "$RANK_MATH_VERSION" ] \
    || fail "side 1 installed version mismatch: expected $RANK_MATH_VERSION, got $INSTALLED_1"

  rank_math_site_policy "siterepo/${PAIR}1"
  cp site-repo.gitignore.template "siterepo/${PAIR}1/.gitignore"
  "${GIT1[@]}" init -q -b main
  "${GIT1[@]}" remote add origin "../origin-$PAIR.git"
  "${GIT1[@]}" add -A
  "${GIT1[@]}" commit -qm "policy: Rank Math $RANK_MATH_VERSION exact-patch certification"
  "${GIT1[@]}" push -qu origin main

  seed_rank_math_content
  wp1 wprism capture --repo=/siterepo
  wp1 wprism lint --repo=/siterepo
  "${GIT1[@]}" add -A
  "${GIT1[@]}" commit -qm "capture: Rank Math $RANK_MATH_VERSION portable SEO and redirection state"
  "${GIT1[@]}" push -q origin main

  clone_case_target
  wp2 plugin install "$RANK_MATH_ARTIFACT_2" >/dev/null
  INSTALLED_2=$(wp2 plugin get seo-by-rank-math --field=version)
  require_fixture_values INSTALLED_2
  [ "$INSTALLED_2" = "$RANK_MATH_VERSION" ] \
    || fail "side 2 installed version mismatch: expected $RANK_MATH_VERSION, got $INSTALLED_2"

  PREDEPLOY_STATE=$(rank_math_native_state_hash wp2)
  require_observed_nonempty 'Rank Math virgin-target native baseline' "$PREDEPLOY_STATE"
  PREDEPLOY_RC=0
  PREDEPLOY_PLAN=$(wp2 wprism plan --repo=/siterepo --format=json 2>&1) || PREDEPLOY_RC=$?
  require_wprism_answered 'Rank Math virgin-target strict plan' json "$PREDEPLOY_PLAN"
  [ "$PREDEPLOY_RC" -ne 0 ] \
    && grep -Fq "declared table 'rank_math_" <<<"$PREDEPLOY_PLAN" \
    && grep -Fq 'does not exist on this environment' <<<"$PREDEPLOY_PLAN" \
    || fail "Rank Math virgin-target plan invented virtual schema instead of refusing: $PREDEPLOY_PLAN"
  [ "$(rank_math_native_state_hash wp2)" = "$PREDEPLOY_STATE" ] \
    || fail 'Rank Math virgin-target strict-plan refusal mutated plugin state'

  DEPLOY_RC=0
  DEPLOY_OUT=$(host_wprism_vmatrix wp2 deploy 2>&1) || DEPLOY_RC=$?
  [ "$DEPLOY_RC" -eq 0 ] && grep -q '^deploy complete:' <<<"$DEPLOY_OUT" \
    || fail "Rank Math host deploy failed to establish lifecycle/schema: $DEPLOY_OUT"
  grep -q '^deploy phase: schema-settle$' <<<"$DEPLOY_OUT" \
    && grep -q '^deploy phase: lifecycle-settle$' <<<"$DEPLOY_OUT" \
    || fail "Rank Math host deploy omitted an ordered provider phase: $DEPLOY_OUT"
  postdeploy_rank_math_content
  REV=$(git -C "siterepo/${PAIR}2" rev-parse HEAD)
  capture_wprism_json_success RANK_MATH_BOUNDARY_APPLY_JSON 'Rank Math version-matrix boundary apply' \
    wp2 wprism apply --repo=/siterepo --adopt-by-slug=terms,posts \
      --default-author=admin --revision="$REV" --json
  printf '%s\n' "$RANK_MATH_BOUNDARY_APPLY_JSON" > "$VMATRIX_APPLY_LOG"
  jq -e '.canary == "clean" and .verification.result == "pass"' \
    <<<"$RANK_MATH_BOUNDARY_APPLY_JSON" >/dev/null \
    || fail "Rank Math $RANK_MATH_VERSION apply did not verify cleanly"
  check_rank_math_boundary_content

  wp2 wprism capture --repo=/siterepo --out=/siterepo/.tmp-rank-math-final
  RANK_MATH_DIFF=$(diff -rq \
    "siterepo/${PAIR}1/state" \
    "siterepo/${PAIR}2/.tmp-rank-math-final" || true)
  rm -rf "siterepo/${PAIR}2/.tmp-rank-math-final"
  [ -z "$RANK_MATH_DIFF" ] \
    || fail "Rank Math $RANK_MATH_VERSION recapture lost byte identity: $RANK_MATH_DIFF"
  pass "Rank Math $RANK_MATH_VERSION preserves native behavior and byte identity"

  if [ "$RANK_MATH_VERSION" = 1.0.277 ]; then
    say 'in-place upgrade: seo-by-rank-math 1.0.277 -> 1.0.277.2 on populated source and target'
    UPGRADE_ARTIFACT_1=$(fetch_artifact seo-by-rank-math 1.0.277.2 cli1)
    UPGRADE_ARTIFACT_2=$(fetch_artifact seo-by-rank-math 1.0.277.2 cli2)
    wp1 plugin install "$UPGRADE_ARTIFACT_1" --force --activate >/dev/null
    [ "$(wp1 plugin get seo-by-rank-math --field=version)" = 1.0.277.2 ] \
      || fail 'Rank Math source upgrade did not install exact 1.0.277.2'
    UPGRADE_SOURCE_DEPLOY=$(host_wprism_vmatrix wp1 deploy --force-code-drift 2>&1) \
      || fail "Rank Math upgraded source host deploy failed: $UPGRADE_SOURCE_DEPLOY"
    grep -q '^deploy complete:' <<<"$UPGRADE_SOURCE_DEPLOY" \
      || fail "Rank Math upgraded source host deploy returned no terminal result: $UPGRADE_SOURCE_DEPLOY"
    UPGRADE_POST=$(jq -r '.post' "siterepo/${PAIR}1/.tmp-rank-math-source.json")
    require_fixture_ids UPGRADE_POST
    wp1 post update "$UPGRADE_POST" --post_title='Rank Math 1.0.277 to 1.0.277.2 東京 🚀' >/dev/null
    wp1 eval '
$modules = array_values(array_unique(array_merge((array) get_option("rank_math_modules", []), ["image-seo"])));
sort($modules, SORT_STRING);
RankMath\Helper::update_modules($modules);
update_option("rank_math_modules", $modules);
' >/dev/null
    wp1 wprism capture --repo=/siterepo
    wp1 wprism lint --repo=/siterepo
    "${GIT1[@]}" add -A
    "${GIT1[@]}" commit -qm 'capture: Rank Math 1.0.277 to 1.0.277.2 in-place upgrade'
    "${GIT1[@]}" push -q origin main

    git -C "siterepo/${PAIR}2" pull -q origin main
    wp2 plugin install "$UPGRADE_ARTIFACT_2" --force --activate >/dev/null
    [ "$(wp2 plugin get seo-by-rank-math --field=version)" = 1.0.277.2 ] \
      || fail 'Rank Math target upgrade did not install exact 1.0.277.2'
    UPGRADE_TARGET_DEPLOY=$(host_wprism_vmatrix wp2 deploy --force-code-drift 2>&1) \
      || fail "Rank Math upgraded target host deploy failed: $UPGRADE_TARGET_DEPLOY"
    grep -q '^deploy complete:' <<<"$UPGRADE_TARGET_DEPLOY" \
      || fail "Rank Math upgraded target host deploy returned no terminal result: $UPGRADE_TARGET_DEPLOY"
    UPGRADE_REV=$(git -C "siterepo/${PAIR}2" rev-parse HEAD)
    capture_wprism_json_success RANK_MATH_BOUNDARY_APPLY_JSON 'Rank Math version-matrix upgrade apply' \
      wp2 wprism apply --repo=/siterepo --default-author=admin \
        --revision="$UPGRADE_REV" --json
    RANK_MATH_VERSION=1.0.277.2
    check_rank_math_boundary_content
    RANK_MATH_VERSION=1.0.277

    UPGRADED_TITLE=$(wp2 post list --post_type=post --name=rank-math-article --field=post_title)
    [ "$UPGRADED_TITLE" = 'Rank Math 1.0.277 to 1.0.277.2 東京 🚀' ] \
      || fail "Rank Math upgrade did not consume state authored after upgrade: $UPGRADED_TITLE"
    wp2 wprism capture --repo=/siterepo --out=/siterepo/.tmp-rank-math-upgraded-final
    UPGRADE_DIFF=$(diff -rq \
      "siterepo/${PAIR}1/state" \
      "siterepo/${PAIR}2/.tmp-rank-math-upgraded-final" || true)
    rm -rf "siterepo/${PAIR}2/.tmp-rank-math-upgraded-final"
    [ -z "$UPGRADE_DIFF" ] \
      || fail "Rank Math 1.0.277 -> 1.0.277.2 upgrade lost byte identity: $UPGRADE_DIFF"
    pass 'Rank Math 1.0.277 -> 1.0.277.2 preserves native behavior, target identities, projections and byte identity'

    say 'in-range downgrade: seo-by-rank-math 1.0.277.2 -> 1.0.277.1 on populated source and target'
    DOWNGRADE_ARTIFACT_1=$(fetch_artifact seo-by-rank-math 1.0.277.1 cli1)
    DOWNGRADE_ARTIFACT_2=$(fetch_artifact seo-by-rank-math 1.0.277.1 cli2)
    wp1 plugin install "$DOWNGRADE_ARTIFACT_1" --force --activate >/dev/null
    wp2 plugin install "$DOWNGRADE_ARTIFACT_2" --force --activate >/dev/null
    [ "$(wp1 plugin get seo-by-rank-math --field=version)" = 1.0.277.1 ] \
      && [ "$(wp2 plugin get seo-by-rank-math --field=version)" = 1.0.277.1 ] \
      || fail 'Rank Math in-range downgrade did not install exact 1.0.277.1 on both environments'

    DOWNGRADE_BEFORE=$(rank_math_native_state_hash wp2)
    require_observed_nonempty 'Rank Math downgrade target baseline' "$DOWNGRADE_BEFORE"
    DOWNGRADE_PLAN_RC=0
    DOWNGRADE_PLAN_OUT=$(wp2 wprism plan --repo=/siterepo --format=json 2>&1) || DOWNGRADE_PLAN_RC=$?
    require_wprism_answered 'Rank Math 1.0.277.2 to 1.0.277.1 downgrade plan' json "$DOWNGRADE_PLAN_OUT"
    [ "$DOWNGRADE_PLAN_RC" -eq 0 ] \
      || fail "Rank Math in-range downgrade plan did not report its code drift: $DOWNGRADE_PLAN_OUT"
    DOWNGRADE_PLAN=$(awk 'NF { line=$0 } END { print line }' <<<"$DOWNGRADE_PLAN_OUT")
    jq -e '
      (.code_drift | length) == 1 and
      .code_drift[0].plugin == "seo-by-rank-math/rank-math.php" and
      .code_drift[0].installed_version == "1.0.277.1" and
      .code_drift[0].recorded_version == "1.0.277.2"
    ' <<<"$DOWNGRADE_PLAN" >/dev/null \
      || fail "Rank Math in-range downgrade plan did not identify the exact version transition: $DOWNGRADE_PLAN"
    [ "$(rank_math_native_state_hash wp2)" = "$DOWNGRADE_BEFORE" ] \
      || fail 'Rank Math in-range downgrade plan mutated plugin state'

    DOWNGRADE_DEPLOY_RC=0
    DOWNGRADE_DEPLOY_OUT=$(host_wprism_vmatrix wp2 deploy 2>&1) || DOWNGRADE_DEPLOY_RC=$?
    require_wprism_answered 'Rank Math 1.0.277.2 to 1.0.277.1 downgrade deploy' human "$DOWNGRADE_DEPLOY_OUT"
    [ "$DOWNGRADE_DEPLOY_RC" -ne 0 ] \
      && grep -q 'code_drift' <<<"$DOWNGRADE_DEPLOY_OUT" \
      && grep -q '1.0.277.2' <<<"$DOWNGRADE_DEPLOY_OUT" \
      && grep -q '1.0.277.1' <<<"$DOWNGRADE_DEPLOY_OUT" \
      || fail "Rank Math in-range downgrade deploy did not refuse at the exact code witness: $DOWNGRADE_DEPLOY_OUT"
    DOWNGRADE_REV=$(git -C "siterepo/${PAIR}2" rev-parse HEAD)
    DOWNGRADE_APPLY_RC=0
    DOWNGRADE_APPLY_OUT=$(wp2 wprism apply --repo=/siterepo --default-author=admin \
      --revision="$DOWNGRADE_REV" 2>&1) || DOWNGRADE_APPLY_RC=$?
    require_wprism_answered 'Rank Math 1.0.277.2 to 1.0.277.1 downgrade apply' human "$DOWNGRADE_APPLY_OUT"
    [ "$DOWNGRADE_APPLY_RC" -ne 0 ] \
      && grep -q 'code_drift' <<<"$DOWNGRADE_APPLY_OUT" \
      && grep -q '1.0.277.2' <<<"$DOWNGRADE_APPLY_OUT" \
      && grep -q '1.0.277.1' <<<"$DOWNGRADE_APPLY_OUT" \
      || fail "Rank Math in-range downgrade apply did not refuse at the exact code witness: $DOWNGRADE_APPLY_OUT"
    [ "$(rank_math_native_state_hash wp2)" = "$DOWNGRADE_BEFORE" ] \
      || fail 'Rank Math in-range downgrade refusal mutated plugin state'

    DOWNGRADE_SOURCE_DEPLOY=$(host_wprism_vmatrix wp1 deploy --force-code-drift 2>&1) \
      || fail "Rank Math downgraded source re-baseline failed: $DOWNGRADE_SOURCE_DEPLOY"
    DOWNGRADE_TARGET_DEPLOY=$(host_wprism_vmatrix wp2 deploy --force-code-drift 2>&1) \
      || fail "Rank Math downgraded target re-baseline failed: $DOWNGRADE_TARGET_DEPLOY"
    grep -q 'FORCED past code_drift' <<<"$DOWNGRADE_SOURCE_DEPLOY" \
      && grep -q 'FORCED past code_drift' <<<"$DOWNGRADE_TARGET_DEPLOY" \
      || fail "Rank Math explicit downgrade re-baseline did not report both forced decisions: source=$DOWNGRADE_SOURCE_DEPLOY target=$DOWNGRADE_TARGET_DEPLOY"

    DOWNGRADE_POST=$(wp1 post list --post_type=post --name=rank-math-article --field=ID)
    require_fixture_ids DOWNGRADE_POST
    wp1 post update "$DOWNGRADE_POST" --post_title='Rank Math 1.0.277.2 to 1.0.277.1 東京 🚀' >/dev/null
    wp1 wprism capture --repo=/siterepo
    wp1 wprism lint --repo=/siterepo
    "${GIT1[@]}" add -A
    "${GIT1[@]}" commit -qm 'capture: Rank Math 1.0.277.2 to 1.0.277.1 in-range downgrade'
    "${GIT1[@]}" push -q origin main
    git -C "siterepo/${PAIR}2" pull -q origin main
    DOWNGRADE_REV=$(git -C "siterepo/${PAIR}2" rev-parse HEAD)
    capture_wprism_json_success RANK_MATH_BOUNDARY_APPLY_JSON 'Rank Math version-matrix downgrade apply' \
      wp2 wprism apply --repo=/siterepo --default-author=admin \
        --revision="$DOWNGRADE_REV" --json
    RANK_MATH_VERSION=1.0.277.1
    check_rank_math_boundary_content
    RANK_MATH_VERSION=1.0.277

    DOWNGRADED_TITLE=$(wp2 post list --post_type=post --name=rank-math-article --field=post_title)
    [ "$DOWNGRADED_TITLE" = 'Rank Math 1.0.277.2 to 1.0.277.1 東京 🚀' ] \
      || fail "Rank Math downgrade did not consume state authored after explicit re-baseline: $DOWNGRADED_TITLE"
    wp2 wprism capture --repo=/siterepo --out=/siterepo/.tmp-rank-math-downgraded-final
    DOWNGRADE_DIFF=$(diff -rq \
      "siterepo/${PAIR}1/state" \
      "siterepo/${PAIR}2/.tmp-rank-math-downgraded-final" || true)
    rm -rf "siterepo/${PAIR}2/.tmp-rank-math-downgraded-final"
    [ -z "$DOWNGRADE_DIFF" ] \
      || fail "Rank Math 1.0.277.2 -> 1.0.277.1 downgrade lost byte identity: $DOWNGRADE_DIFF"
    pass 'Rank Math 1.0.277.2 -> 1.0.277.1 refuses until explicit re-baseline, then preserves native behavior and byte identity'
  fi
done

say 'negative control: official seo-by-rank-math 1.0.276 must refuse below the certified patch line'
reset_env wp1
reset_case_repositories

RANK_MATH_IN_RANGE=$(fetch_artifact seo-by-rank-math 1.0.277.2 cli1)
wp1 plugin install "$RANK_MATH_IN_RANGE" --activate >/dev/null
NEGATIVE_INSTALLED=$(wp1 plugin get seo-by-rank-math --field=version)
require_fixture_values NEGATIVE_INSTALLED
[ "$NEGATIVE_INSTALLED" = 1.0.277.2 ] \
  || fail 'Rank Math negative-control premise did not install exact 1.0.277.2'
rank_math_site_policy "siterepo/${PAIR}1"
cp site-repo.gitignore.template "siterepo/${PAIR}1/.gitignore"
"${GIT1[@]}" init -q -b main
"${GIT1[@]}" remote add origin "../origin-$PAIR.git"
"${GIT1[@]}" add -A
"${GIT1[@]}" commit -qm 'policy: Rank Math negative-control pin'
"${GIT1[@]}" push -qu origin main
RANK_MATH_VERSION=1.0.277.2
seed_rank_math_content
wp1 wprism capture --repo=/siterepo
"${GIT1[@]}" add -A
"${GIT1[@]}" commit -qm 'capture: valid Rank Math state for negative control'
"${GIT1[@]}" push -q origin main
wprism_host_install_recovery_runtime "$(cd .. && pwd -P)" "siterepo/${PAIR}1" \
  || fail 'Rank Math negative control could not install the source recovery runtime'

wp1 plugin deactivate seo-by-rank-math >/dev/null
wp1 plugin delete seo-by-rank-math >/dev/null
RANK_MATH_OUT_OF_RANGE=$(fetch_artifact seo-by-rank-math 1.0.276 cli1)
wp1 plugin install "$RANK_MATH_OUT_OF_RANGE" >/dev/null
INSTALLED_OOR=$(wp1 plugin get seo-by-rank-math --field=version)
[ "$INSTALLED_OOR" = 1.0.276 ] \
  || fail "Rank Math negative control expected 1.0.276, got $INSTALLED_OOR"
NEGATIVE_BEFORE=$(rank_math_native_state_hash wp1)
require_observed_nonempty 'Rank Math outside-range state baseline' "$NEGATIVE_BEFORE"
NEGATIVE_RC=0
NEGATIVE_OUT=$(host_wprism_vmatrix wp1 deploy 2>&1) || NEGATIVE_RC=$?
require_wprism_answered 'Rank Math outside-range host deploy' human "$NEGATIVE_OUT"
[ "$NEGATIVE_RC" -ne 0 ] \
  && grep -Eq 'outside_version_range|outside the .* declared version_range' <<<"$NEGATIVE_OUT" \
  && grep -q 'seo-by-rank-math/rank-math.php' <<<"$NEGATIVE_OUT" \
  && grep -q '1.0.276' <<<"$NEGATIVE_OUT" \
  || fail "Rank Math 1.0.276 refused for the wrong reason: $NEGATIVE_OUT"
wp1 plugin is-active seo-by-rank-math >/dev/null 2>&1 \
  && fail 'outside-range Rank Math 1.0.276 was activated before refusal'
NEGATIVE_AFTER=$(rank_math_native_state_hash wp1)
[ "$NEGATIVE_AFTER" = "$NEGATIVE_BEFORE" ] \
  || fail 'Rank Math outside-range refusal mutated plugin state'
pass 'official Rank Math 1.0.276 is loudly refused before activation or plugin-state mutation'
}
