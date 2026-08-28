seed_advanced_editor_tools_content() {
  wp_conf1() { wp1 "$@"; }
  local CONF_REPO1="siterepo/${PAIR}1"
  local COMPOSE="$PAIR_COMPOSE_STRING"
  . "$(dirname "${BASH_SOURCE[0]}")/../conformance/seed.sh"
  unset -f wp_conf1
}

check_advanced_editor_tools_boundary_content() {
  local out
  out=$(wp2 eval '
    wp_set_current_user(1);
    $settings = get_option("tadv_settings");
    $admin = get_option("tadv_admin_settings");
    echo wp_json_encode([
      "admin" => $admin,
      "buttons_1" => array_values(apply_filters("mce_buttons", ["formatselect"], "content")),
      "buttons_2" => array_values(apply_filters("mce_buttons_2", [], "content")),
      "buttons_3" => array_values(apply_filters("mce_buttons_3", [], "content")),
      "buttons_4" => array_values(apply_filters("mce_buttons_4", [], "content")),
      "classic_buttons" => array_values(apply_filters("mce_buttons", [], "classic-block")),
      "init" => apply_filters("tiny_mce_before_init", [], "content"),
      "settings" => $settings,
    ]);
  ')
  require_observed_nonempty "Advanced Editor Tools boundary target behavior" "$out"
  out=$(printf '%s\n' "$out" | awk 'NF { line=$0 } END { print line }')
  printf '%s\n' "$out" | jq -e '
    .buttons_1 == ["bold","italic","underline","strikethrough"] and
    .buttons_2 == ["bullist","numlist","blockquote","link","unlink"] and
    .buttons_3 == ["forecolor","backcolor","removeformat","charmap"] and
    .buttons_4 == ["code","fullscreen","searchreplace"] and
    .classic_buttons == ["bold","italic","link","undo","redo"] and
    .settings.toolbar_1 == "bold,italic,underline,strikethrough" and
    .admin.options == "no_autop,table_resize_bars" and
    .admin.disabled_editors == "rest_of_wpadmin" and
    .init.wpautop == false and .init.tadv_noautop == true
  ' >/dev/null || fail "Advanced Editor Tools boundary target did not consume the applied toolbar/admin settings: $out"
  pass "Advanced Editor Tools exact boundary drives all four toolbars, Classic block controls, and no-autop behavior on the target"
}

VMATRIX_PLUGIN_SLUG=tinymce-advanced

version_matrix_reset_after_delete() {
  local cli="$1"
  # Clear the reviewed option ownership set retained without the uninstall hook.
  "$cli" db query "
    DELETE FROM wp_options WHERE option_name IN (
      'tadv_admin_settings', 'tadv_allbtns', 'tadv_btns1', 'tadv_btns2',
      'tadv_btns3', 'tadv_btns4', 'tadv_options', 'tadv_plugins',
      'tadv_settings', 'tadv_toolbars', 'tadv_version'
    );
  " >/dev/null
}

version_matrix_workflow() {
VMATRIX_CASES=$((VMATRIX_CASES + 1))
AET_VERSION=5.9.2
say "boundary: tinymce-advanced $AET_VERSION (only admitted patch)"

reset_env wp1
reset_env wp2
reset_case_repositories

say "fetch + verify tinymce-advanced $AET_VERSION (digest-checked artifact only)"
AET_ARTIFACT_1=$(fetch_artifact tinymce-advanced "$AET_VERSION" cli1)
AET_ARTIFACT_2=$(fetch_artifact tinymce-advanced "$AET_VERSION" cli2)
wp1 plugin install "$AET_ARTIFACT_1" --activate >/dev/null
AET_INSTALLED_1=$(wp1 plugin get tinymce-advanced --field=version)
[ "$AET_INSTALLED_1" = "$AET_VERSION" ] \
  || fail "side 1 installed version mismatch: expected $AET_VERSION, got $AET_INSTALLED_1"
pass "side 1: tinymce-advanced $AET_VERSION installed from verified artifact, active"

cat > "siterepo/${PAIR}1/site.duo.json" <<'EOF'
{
  "manifests": ["core", "advanced-editor-tools"],
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
"${GIT1[@]}" commit -qm "policy: Advanced Editor Tools $AET_VERSION exact-boundary certification"
"${GIT1[@]}" push -qu origin main

seed_advanced_editor_tools_content
wp1 duo capture --repo=/siterepo
wp1 duo lint --repo=/siterepo
"${GIT1[@]}" add -A
"${GIT1[@]}" commit -qm "capture: Advanced Editor Tools $AET_VERSION settings"
"${GIT1[@]}" push -q origin main

clone_case_target
wp2 plugin install "$AET_ARTIFACT_2" >/dev/null
INSTALLED_2=$(wp2 plugin get tinymce-advanced --field=version)
require_fixture_values INSTALLED_2
[ "$INSTALLED_2" = "$AET_VERSION" ] \
  || fail "side 2 installed version mismatch: expected $AET_VERSION, got $INSTALLED_2"
wp2 duo deploy --repo=/siterepo
REV=$(git -C "siterepo/${PAIR}2" rev-parse HEAD)
wp2 duo apply --repo=/siterepo --adopt-by-slug=terms,posts --default-author=admin --revision="$REV" | tee "$VMATRIX_APPLY_LOG"
grep -q 'canary clean' "$VMATRIX_APPLY_LOG" \
  || fail "apply canary not clean at tinymce-advanced $AET_VERSION"
check_advanced_editor_tools_boundary_content

wp2 duo capture --repo=/siterepo --out=/siterepo/.tmp-final
AET_DIFF=$(diff -rq "siterepo/${PAIR}1/state" "siterepo/${PAIR}2/.tmp-final" || true)
rm -rf "siterepo/${PAIR}2/.tmp-final"
[ -z "$AET_DIFF" ] \
  || fail "byte-identity broken at tinymce-advanced $AET_VERSION: $AET_DIFF"
pass "Advanced Editor Tools $AET_VERSION deploys, drives native editor behavior, and recaptures byte-identically"

# Team-lead's own requirement: the loop above proves every IN-RANGE boundary
# certifies — it does not by itself prove the pin is honest, i.e. that an
# OUT-OF-range version is actually refused rather than silently accepted.
# Both properties together are what "the matrix proves the pins honest, not
# just the plugin functional" means. Deploy::code_mismatch()
# (agent/src/Promotion/Deploy.php) is the real enforcement: it reads the ACTUALLY-
# installed plugin version via WordPress's own get_plugins(), compares it
# against the manifest's declared version_range, and — triggered by both
# `wp duo deploy` and `wp duo apply` — throws an 'outside_version_range'
# finding naming the plugin, its installed version, and the declared range,
# unless --force-code-mismatch is passed. This only needs `duo deploy`
# (code-only reconciliation), not a full capture/apply round-trip — the
# refusal fires before any target mutation is attempted.
say "negative control: tinymce-advanced 5.9.0 (adjacent official release below the exact 5.9.2 contract) must be REFUSED"
reset_env wp1
reset_case_repositories

AET_IN_RANGE=$(fetch_artifact tinymce-advanced 5.9.2 cli1)
wp1 plugin install "$AET_IN_RANGE" --activate >/dev/null
NEGATIVE_INSTALLED=$(wp1 plugin get tinymce-advanced --field=version)
require_fixture_values NEGATIVE_INSTALLED
[ "$NEGATIVE_INSTALLED" = "5.9.2" ] \
  || fail "negative control premise did not install exact tinymce-advanced 5.9.2 bytes"
cat > "siterepo/${PAIR}1/site.duo.json" <<'EOF'
{
  "manifests": ["core", "advanced-editor-tools"],
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
"${GIT1[@]}" commit -qm "policy: Advanced Editor Tools negative-control pin"
"${GIT1[@]}" push -qu origin main
seed_advanced_editor_tools_content
wp1 duo capture --repo=/siterepo
"${GIT1[@]}" add -A
"${GIT1[@]}" commit -qm "capture: valid Advanced Editor Tools state for negative control"
"${GIT1[@]}" push -q origin main

wp1 plugin deactivate tinymce-advanced >/dev/null
wp1 plugin delete tinymce-advanced >/dev/null
AET_OUT_OF_RANGE=$(fetch_artifact tinymce-advanced 5.9.0 cli1)
wp1 plugin install "$AET_OUT_OF_RANGE" >/dev/null
INSTALLED_OOR=$(wp1 plugin get tinymce-advanced --field=version)
[ "$INSTALLED_OOR" = "5.9.0" ] \
  || fail "negative control: expected tinymce-advanced 5.9.0 installed, got $INSTALLED_OOR"
AET_REFUSAL_BEFORE=$(wp1 eval 'echo hash("sha256", wp_json_encode([get_option("tadv_settings", null), get_option("tadv_admin_settings", null)]));')
require_observed_nonempty "Advanced Editor Tools refusal state baseline" "$AET_REFUSAL_BEFORE"
set +e
DEPLOY_OUT=$(wp1 duo deploy --repo=/siterepo 2>&1)
DEPLOY_RC=$?
set -e
[ "$DEPLOY_RC" -ne 0 ] \
  || fail "expected deploy to refuse tinymce-advanced 5.9.0, but it exited 0 (got: $DEPLOY_OUT)"
grep -Eq "outside_version_range|outside the '.*' manifest's declared version_range" <<<"$DEPLOY_OUT" \
  || fail "tinymce-advanced 5.9.0 refused for the wrong reason (got: $DEPLOY_OUT)"
grep -q "tinymce-advanced/tinymce-advanced.php" <<<"$DEPLOY_OUT" \
  || fail "Advanced Editor Tools refusal did not name the exact basename (got: $DEPLOY_OUT)"
grep -q "5.9.0" <<<"$DEPLOY_OUT" \
  || fail "Advanced Editor Tools refusal did not name installed version 5.9.0 (got: $DEPLOY_OUT)"
if wp1 plugin is-active tinymce-advanced >/dev/null 2>&1; then
  fail "outside-range tinymce-advanced 5.9.0 was activated before refusal"
fi
AET_REFUSAL_AFTER=$(wp1 eval 'echo hash("sha256", wp_json_encode([get_option("tadv_settings", null), get_option("tadv_admin_settings", null)]));')
[ "$AET_REFUSAL_AFTER" = "$AET_REFUSAL_BEFORE" ] \
  || fail "Advanced Editor Tools outside-range refusal mutated authored settings"
printf '%s\n' "$DEPLOY_OUT"
pass "official tinymce-advanced 5.9.0 is loudly refused, remains inactive, and cannot mutate admitted settings"
}
