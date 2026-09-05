seed_ninja_forms_content() {
  # Reuse the standalone conformance seed verbatim. It imports Ninja Forms'
  # own bundled Job Application template through the plugin's real admin
  # import process, then adds one native large/serialized disposable field
  # and one disposable action: 1 form, 24 fields, 4 actions plus a real block.
  wp_conf1() { wp1 "$@"; }
  local CONF_REPO1="siterepo/${PAIR}1"
  local COMPOSE="$PAIR_COMPOSE_STRING"
  . "$(dirname "${BASH_SOURCE[0]}")/../conformance/seed.sh"
  unset -f wp_conf1
}

postdeploy_ninja_forms_content() {
  # Deployment activates the plugin and Ninja Forms creates its own sample
  # form on the target. Reuse the standalone hook that removes only that
  # environment-local activation side effect before apply.
  wp_conf2() { wp2 "$@"; }
  local CONF_REPO2="siterepo/${PAIR}2"
  local COMPOSE="$PAIR_COMPOSE_STRING"
  . "$(dirname "${BASH_SOURCE[0]}")/../conformance/postdeploy.sh"
  unset -f wp_conf2
}

check_ninja_forms_boundary_content() { # [expected-title]
  local expected_title="${1:-Job Application}" front form_id api_out cache_out
  front=$(curl -fsSL "http://localhost:${PORT2}/conformance-careers/") \
    || fail "side 2 conformance-careers page did not return 200"
  require_observed_nonempty "side 2 Ninja Forms careers page" "$front"
  [ "${#front}" -ge 1000 ] \
    || fail "side 2 conformance-careers response was suspiciously short (${#front} bytes)"
  if grep -qiE 'fatal error|uncaught' <<<"$front"; then
    fail "side 2 rendered careers page contains a PHP fatal error marker"
  fi
  grep -qE 'Job Application|nf-form-' <<<"$front" \
    || fail "side 2 rendered careers page has no Ninja Forms markup"
  grep -q 'First Name' <<<"$front" \
    || fail "side 2 rendered form is missing its own field content"

  form_id=$(wp2 db query "SELECT id FROM wp_nf3_forms ORDER BY id" --skip-column-names | tr -d '[:space:]')
  require_fixture_ids form_id
  api_out=$(wp2 eval "
\$form = Ninja_Forms()->form($form_id)->get();
echo \$form->get_setting('title') . '|' . count(Ninja_Forms()->form($form_id)->get_fields()) . '|' . count(Ninja_Forms()->form($form_id)->get_actions());
")
  require_observed_nonempty "side 2 Ninja Forms model API" "$api_out"
  [ "$api_out" = "$expected_title|24|4" ] \
    || fail "side 2 Ninja Forms model API mismatch (got: $api_out)"
  cache_out=$(wp2 eval '
    global $wpdb;
    $forms=array_values(array_map("intval",$wpdb->get_col("SELECT id FROM {$wpdb->prefix}nf3_forms ORDER BY id")));
    $caches=array_values(array_map("intval",$wpdb->get_col("SELECT id FROM {$wpdb->prefix}nf3_upgrades ORDER BY id")));
    $invalid=0;
    foreach ($forms as $id) {
      $raw=$wpdb->get_var($wpdb->prepare("SELECT cache FROM {$wpdb->prefix}nf3_upgrades WHERE id=%d",$id));
      $cache=is_string($raw)?@unserialize($raw,["allowed_classes"=>false,"max_depth"=>64]):false;
      $fields=array_values(array_map("intval",$wpdb->get_col($wpdb->prepare("SELECT id FROM {$wpdb->prefix}nf3_fields WHERE parent_id=%d ORDER BY id",$id))));
      $actions=array_values(array_map("intval",$wpdb->get_col($wpdb->prepare("SELECT id FROM {$wpdb->prefix}nf3_actions WHERE parent_id=%d ORDER BY id",$id))));
      $cachedFields=is_array($cache)?array_map(static fn($row)=>(int)($row["id"]??0),(array)($cache["fields"]??[])):[];
      $cachedActions=is_array($cache)?array_map(static fn($row)=>(int)($row["id"]??0),(array)($cache["actions"]??[])):[];
      sort($cachedFields,SORT_NUMERIC); sort($cachedActions,SORT_NUMERIC);
      if (!is_array($cache)||(int)($cache["id"]??0)!==$id||$cachedFields!==$fields||$cachedActions!==$actions) $invalid++;
    }
    $legacy=0;
    foreach ((array)$wpdb->get_col("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE \"nf_form_%\"") as $name) {
      if (is_string($name)&&preg_match("/^nf_form_[1-9][0-9]*$/D",$name)===1) $legacy++;
    }
    echo implode("|",[count($forms),$forms===$caches?1:0,$invalid,$legacy]);
  ')
  require_observed_nonempty "side 2 Ninja Forms cache projection" "$cache_out"
  [ "$cache_out" = "1|1|0|0" ] \
    || fail "side 2 Ninja Forms cache projection was not closed (got: $cache_out)"
  pass "side 2 renders the real Job Application; native API resolves 24 fields/4 actions; provider cache is exact and legacy-free"
}

VMATRIX_PLUGIN_SLUG=ninja-forms

version_matrix_reset_after_delete() {
  local cli="$1"
  # Ninja Forms' custom tables and schema-version options survive plugin
  # deletion. Leaving them behind makes a later boundary inherit an earlier
  # release's schema instead of exercising a fresh install at that boundary.
  "$cli" db query "
    DROP TABLE IF EXISTS
      wp_nf3_action_meta, wp_nf3_actions, wp_nf3_chunks,
      wp_nf3_field_meta, wp_nf3_fields, wp_nf3_form_meta, wp_nf3_forms,
      wp_nf3_object_meta, wp_nf3_objects, wp_nf3_relationships, wp_nf3_upgrades;
    DELETE FROM wp_options
      WHERE option_name LIKE 'ninja_forms%'
         OR option_name LIKE 'nf_%'
         OR option_name LIKE 'ninja-forms-%';
  " >/dev/null
}

version_matrix_workflow() {
VMATRIX_CASES=$((VMATRIX_CASES + 1))
for NINJA_VERSION in 3.4.34.2 3.14.11; do
  say "boundary: ninja-forms $NINJA_VERSION"

  reset_env wp1
  reset_env wp2
  reset_case_repositories

  say "fetch + verify ninja-forms $NINJA_VERSION (never a bare slug install — always a digest-checked artifact)"
  ARTIFACT_1=$(fetch_artifact ninja-forms "$NINJA_VERSION" cli1)
  ARTIFACT_2=$(fetch_artifact ninja-forms "$NINJA_VERSION" cli2)
  pass "verified sha256-pinned artifact resolved for both sides: $ARTIFACT_1"

  wp1 plugin install "$ARTIFACT_1" --activate >/dev/null
  INSTALLED_1=$(wp1 plugin get ninja-forms --field=version)
  [ "$INSTALLED_1" = "$NINJA_VERSION" ] || fail "side 1 installed version mismatch: expected $NINJA_VERSION, got $INSTALLED_1"
  pass "side 1: ninja-forms $NINJA_VERSION installed from verified artifact, active"

  cat > "siterepo/${PAIR}1/site.wprism.json" <<EOF
{
  "manifests": ["core", "ninja-forms"],
  "policy": {
    "options": {},
    "post_meta": {},
    "post_types": ["post", "page", "attachment"],
    "taxonomies": ["category", "post_tag"]
  },
  "spec_version": 2
}
EOF
  cp site-repo.gitignore.template "siterepo/${PAIR}1/.gitignore"
  "${GIT1[@]}" init -q -b main
  "${GIT1[@]}" remote add origin "../origin-$PAIR.git"
  "${GIT1[@]}" add -A
  "${GIT1[@]}" commit -qm "policy: ninja-forms $NINJA_VERSION version-boundary certification"
  "${GIT1[@]}" push -qu origin main

  seed_ninja_forms_content
  wp1 wprism capture --repo=/siterepo
  pass "captured on side 1 (ninja-forms $NINJA_VERSION)"
  wp1 wprism lint --repo=/siterepo
  pass "lint: 0 findings"

  "${GIT1[@]}" add -A
  "${GIT1[@]}" commit -qm "capture: ninja-forms $NINJA_VERSION content"
  "${GIT1[@]}" push -q origin main

  clone_case_target
  wp2 plugin install "$ARTIFACT_2" >/dev/null
  INSTALLED_2=$(wp2 plugin get ninja-forms --field=version)
  require_fixture_values INSTALLED_2
  [ "$INSTALLED_2" = "$NINJA_VERSION" ] || fail "side 2 installed version mismatch: expected $NINJA_VERSION, got $INSTALLED_2"

  wp2 wprism deploy --repo=/siterepo
  postdeploy_ninja_forms_content
  REV=$(git -C "siterepo/${PAIR}2" rev-parse HEAD)
  wp2 wprism apply --repo=/siterepo --adopt-by-slug=terms,posts --default-author=admin --revision="$REV" | tee "$VMATRIX_APPLY_LOG"
  assert_version_matrix_apply_ready
  grep -q 'canary clean' "$VMATRIX_APPLY_LOG" || fail "apply canary not clean at ninja-forms $NINJA_VERSION"
  pass "deploy + apply succeeded on side 2 (ninja-forms $NINJA_VERSION, canary clean)"

  check_ninja_forms_boundary_content

  wp2 wprism capture --repo=/siterepo --out="/siterepo/.tmp-final"
  DIFF_OUT=$(diff -rq "siterepo/${PAIR}1/state" "siterepo/${PAIR}2/.tmp-final" || true)
  rm -rf "siterepo/${PAIR}2/.tmp-final"
  [ -z "$DIFF_OUT" ] || fail "byte-identity broken at ninja-forms $NINJA_VERSION: $DIFF_OUT"
  pass "byte-identical recapture at ninja-forms $NINJA_VERSION — the manifest's own declared version_range boundary is proven, not just its currently-installed version"

  if [ "$NINJA_VERSION" = 3.4.34.2 ]; then
    say 'in-place lifecycle: ninja-forms 3.4.34.2 authored graph -> exact 3.14.11 on both environments'
    UPGRADE_ARTIFACT_1=$(fetch_artifact ninja-forms 3.14.11 cli1)
    UPGRADE_ARTIFACT_2=$(fetch_artifact ninja-forms 3.14.11 cli2)
    wp1 plugin install "$UPGRADE_ARTIFACT_1" --force --activate >/dev/null
    wp2 plugin install "$UPGRADE_ARTIFACT_2" --force --activate >/dev/null
    [ "$(wp1 plugin get ninja-forms --field=version)" = 3.14.11 ] \
      && [ "$(wp2 plugin get ninja-forms --field=version)" = 3.14.11 ] \
      || fail 'Ninja Forms in-place upgrade did not install exact 3.14.11 on both environments'

    UPGRADE_DRIFT_RC=0
    UPGRADE_DRIFT_OUT=$(wp2 wprism deploy --repo=/siterepo 2>&1) || UPGRADE_DRIFT_RC=$?
    require_wprism_answered 'Ninja Forms out-of-band 3.4.34.2 to 3.14.11 upgrade refusal' human "$UPGRADE_DRIFT_OUT"
    [ "$UPGRADE_DRIFT_RC" -ne 0 ] \
      && grep -q 'code_drift' <<<"$UPGRADE_DRIFT_OUT" \
      && grep -q '3.4.34.2' <<<"$UPGRADE_DRIFT_OUT" \
      && grep -q '3.14.11' <<<"$UPGRADE_DRIFT_OUT" \
      || fail "Ninja Forms out-of-band upgrade did not refuse at the exact code witness: $UPGRADE_DRIFT_OUT"

    # Re-baseline the explicit code replacement, then publish one real native
    # form edit under the new release so apply must exercise mapping and the
    # fresh-process cache provider across the in-place lifecycle boundary.
    wp1 wprism deploy --repo=/siterepo --force-code-drift >/dev/null
    wp2 wprism deploy --repo=/siterepo --force-code-drift >/dev/null
    wp1 eval '
      global $wpdb;
      $id=(int)$wpdb->get_var("SELECT id FROM {$wpdb->prefix}nf3_forms WHERE title=\"Job Application\"");
      if ($id <= 0) throw new RuntimeException("Ninja Forms upgrade form is absent");
      $form=Ninja_Forms()->form($id)->get();
      $form->update_setting("title", "Job Application Upgrade 東京 🚀")->save();
      WPN_Helper::delete_nf_cache($id);
      WPN_Helper::build_nf_cache($id);
    ' >/dev/null
    wp1 wprism capture --repo=/siterepo
    wp1 wprism lint --repo=/siterepo
    "${GIT1[@]}" add -A
    "${GIT1[@]}" commit -qm 'capture: Ninja Forms 3.4.34.2 to 3.14.11 in-place upgrade'
    "${GIT1[@]}" push -q origin main
    git -C "siterepo/${PAIR}2" pull -q origin main
    UPGRADE_REV=$(git -C "siterepo/${PAIR}2" rev-parse HEAD)
    wp2 wprism apply --repo=/siterepo --adopt-by-slug=terms,posts --default-author=admin --revision="$UPGRADE_REV" \
      2>&1 | tee "$VMATRIX_APPLY_LOG"
    assert_version_matrix_apply_ready
    grep -q 'canary clean' "$VMATRIX_APPLY_LOG" \
      || fail 'Ninja Forms 3.4.34.2 -> 3.14.11 apply canary was not clean'
    grep -q 'provider capability fired: ninja-forms-form-cache@2.2.0 rebuild_form_caches' "$VMATRIX_APPLY_LOG" \
      || fail 'Ninja Forms cache provider v2 did not fire across the in-place upgrade'
    check_ninja_forms_boundary_content 'Job Application Upgrade 東京 🚀'

    wp2 wprism capture --repo=/siterepo --out=/siterepo/.tmp-ninja-upgrade-final
    UPGRADE_DIFF=$(diff -rq "siterepo/${PAIR}1/state" "siterepo/${PAIR}2/.tmp-ninja-upgrade-final" || true)
    rm -rf "siterepo/${PAIR}2/.tmp-ninja-upgrade-final"
    [ -z "$UPGRADE_DIFF" ] \
      || fail "Ninja Forms 3.4.34.2 -> 3.14.11 recapture was not byte-identical: $UPGRADE_DIFF"

    # A downgrade is not an adapter data operation. Replacing code behind the
    # recorded 3.14.11 witness must stay loud until an operator explicitly
    # re-baselines it; restore the reviewed current artifact before continuing.
    wp2 plugin install "$ARTIFACT_2" --force --activate >/dev/null
    [ "$(wp2 plugin get ninja-forms --field=version)" = 3.4.34.2 ] \
      || fail 'Ninja Forms downgrade probe did not install exact 3.4.34.2'
    DOWNGRADE_RC=0
    DOWNGRADE_OUT=$(wp2 wprism deploy --repo=/siterepo 2>&1) || DOWNGRADE_RC=$?
    require_wprism_answered 'Ninja Forms out-of-band 3.14.11 to 3.4.34.2 downgrade refusal' human "$DOWNGRADE_OUT"
    [ "$DOWNGRADE_RC" -ne 0 ] \
      && grep -q 'code_drift' <<<"$DOWNGRADE_OUT" \
      && grep -q '3.14.11' <<<"$DOWNGRADE_OUT" \
      && grep -q '3.4.34.2' <<<"$DOWNGRADE_OUT" \
      || fail "Ninja Forms out-of-band downgrade did not refuse at the exact code witness: $DOWNGRADE_OUT"
    wp2 plugin install "$UPGRADE_ARTIFACT_2" --force --activate >/dev/null
    [ "$(wp2 plugin get ninja-forms --field=version)" = 3.14.11 ] \
      || fail 'Ninja Forms downgrade recovery did not restore exact 3.14.11'
    wp2 wprism deploy --repo=/siterepo --force-code-drift >/dev/null
    DOWNGRADE_PLAN=$(wp2 wprism plan --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
    require_wprism_answered 'Ninja Forms plan after rejected downgrade recovery' json "$DOWNGRADE_PLAN"
    jq -e '([.create,.update,.drift,.conflict,.collision,.delete,.delete_conflict] | map(length) | add) == 0' <<<"$DOWNGRADE_PLAN" >/dev/null \
      || fail "Ninja Forms rejected downgrade recovery invented authored work: $DOWNGRADE_PLAN"
    check_ninja_forms_boundary_content 'Job Application Upgrade 東京 🚀'
    pass 'Ninja Forms populated 3.4.34.2 sites upgrade in place to 3.14.11; out-of-band downgrade refuses before explicit restoration; native graph/cache and byte identity survive'
  fi
done

say "negative control: ninja-forms 3.3.21.4 (real wp.org release, genuinely below adapter-packages/ninja-forms/package/manifest.json's corrected min 3.4.34.2) must be REFUSED, not silently accepted"
reset_env wp1
reset_case_repositories

# Build valid canonical state with the certified upper-bound artifact first.
# The below-range release fatals during activation on the repository's PHP
# runtime, so asking it to create canonical content would test an unrelated
# runtime incompatibility rather than the deploy-time version gate this
# negative control owns.
IN_RANGE_ARTIFACT=$(fetch_artifact ninja-forms 3.14.11 cli1)
wp1 plugin install "$IN_RANGE_ARTIFACT" --activate >/dev/null
NEGATIVE_INSTALLED=$(wp1 plugin get ninja-forms --field=version)
require_fixture_values NEGATIVE_INSTALLED
[ "$NEGATIVE_INSTALLED" = "3.14.11" ] \
  || fail "negative control premise did not install exact ninja-forms 3.14.11 bytes"
cat > "siterepo/${PAIR}1/site.wprism.json" <<'EOF'
{
  "manifests": ["core", "ninja-forms"],
  "policy": {
    "options": {},
    "post_meta": {},
    "post_types": ["post", "page", "attachment"],
    "taxonomies": ["category", "post_tag"]
  },
  "spec_version": 2
}
EOF
cp site-repo.gitignore.template "siterepo/${PAIR}1/.gitignore"
"${GIT1[@]}" init -q -b main
"${GIT1[@]}" remote add origin "../origin-$PAIR.git"
"${GIT1[@]}" add -A
"${GIT1[@]}" commit -qm "policy: ninja-forms negative-control pin"
"${GIT1[@]}" push -qu origin main
seed_ninja_forms_content
wp1 wprism capture --repo=/siterepo
"${GIT1[@]}" add -A
"${GIT1[@]}" commit -qm "capture: valid Ninja Forms state for negative control"
"${GIT1[@]}" push -q origin main

wp1 plugin deactivate ninja-forms >/dev/null
wp1 plugin delete ninja-forms >/dev/null
OUT_OF_RANGE_ARTIFACT=$(fetch_artifact ninja-forms 3.3.21.4 cli1)
wp1 plugin install "$OUT_OF_RANGE_ARTIFACT" >/dev/null
INSTALLED_OOR=$(wp1 plugin get ninja-forms --field=version)
[ "$INSTALLED_OOR" = "3.3.21.4" ] || fail "negative control: expected ninja-forms 3.3.21.4 installed, got $INSTALLED_OOR"

set +e
DEPLOY_OUT=$(wp1 wprism deploy --repo=/siterepo 2>&1)
DEPLOY_RC=$?
set -e
[ "$DEPLOY_RC" -ne 0 ] || fail "expected deploy to refuse ninja-forms 3.3.21.4 as outside_version_range, but it exited 0 (got: $DEPLOY_OUT)"
grep -Eq "outside_version_range|outside the '.*' manifest's declared version_range" <<<"$DEPLOY_OUT" \
  || fail "deploy refused, but not for the expected outside_version_range reason (got: $DEPLOY_OUT)"
grep -q "ninja-forms/ninja-forms.php" <<<"$DEPLOY_OUT" || fail "refusal did not name the plugin (got: $DEPLOY_OUT)"
grep -q "3.3.21.4" <<<"$DEPLOY_OUT" || fail "refusal did not name the actually-installed version (got: $DEPLOY_OUT)"
printf '%s\n' "$DEPLOY_OUT"
pass "confirmed: ninja-forms 3.3.21.4 (real, installed, genuinely below the corrected min) is loudly refused by Deploy::code_mismatch() — the version_range pin is honest, not just decorative"
}
