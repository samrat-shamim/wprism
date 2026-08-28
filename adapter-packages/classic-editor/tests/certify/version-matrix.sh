seed_classic_editor_content() {
  wp_conf1() { wp1 "$@"; }
  local CONF_REPO1="siterepo/${PAIR}1"
  local COMPOSE="$PAIR_COMPOSE_STRING"
  . ../adapter-packages/classic-editor/tests/conformance/seed.sh
  unset -f wp_conf1
}

check_classic_editor_boundary_content() {
  local out
  out=$(wp2 eval '
    wp_set_current_user(1);
    $plain = get_page_by_path("classic-editor-plain-fixture", OBJECT, "post");
    $blocks = get_page_by_path("classic-editor-block-fixture", OBJECT, "post");
    if (!$plain || !$blocks) { throw new RuntimeException("Classic Editor boundary posts are missing"); }
    $get_settings = new ReflectionMethod("Classic_Editor", "get_settings");
    echo wp_json_encode([
      "allow" => get_option("classic-editor-allow-users"),
      "block_post_uses_blocks" => (bool) use_block_editor_for_post($blocks),
      "plain_post_uses_blocks" => (bool) use_block_editor_for_post($plain),
      "post_type_uses_blocks" => (bool) use_block_editor_for_post_type("post"),
      "replace" => get_option("classic-editor-replace"),
      "settings" => $get_settings->invoke(null, "refresh", 1),
    ]);
  ')
  require_observed_nonempty "Classic Editor boundary target behavior" "$out"
  out=$(printf '%s\n' "$out" | awk 'NF { line=$0 } END { print line }')
  printf '%s\n' "$out" | jq -e '
    .replace == "classic" and .allow == "allow" and
    .settings.editor == "classic" and .settings["allow-users"] == true and
    .plain_post_uses_blocks == false and .block_post_uses_blocks == true and
    .post_type_uses_blocks == true
  ' >/dev/null || fail "Classic Editor boundary target did not consume the applied selection settings: $out"
  pass "Classic Editor exact boundary selects classic for plain content while preserving block-editor routing for block content"
}

VMATRIX_PLUGIN_SLUG=classic-editor

version_matrix_reset_after_delete() {
  local cli="$1"
  "$cli" db query "
    DELETE FROM wp_options
      WHERE option_name IN ('classic-editor-allow-users', 'classic-editor-replace');
  " >/dev/null
}

version_matrix_workflow() {
VMATRIX_CASES=$((VMATRIX_CASES + 1))
CLASSIC_VERSION=1.7.0
say "boundary: classic-editor $CLASSIC_VERSION (only admitted patch)"

reset_env wp1
reset_env wp2
reset_case_repositories

say "fetch + verify classic-editor $CLASSIC_VERSION (digest-checked artifact only)"
CLASSIC_ARTIFACT_1=$(fetch_artifact classic-editor "$CLASSIC_VERSION" cli1)
CLASSIC_ARTIFACT_2=$(fetch_artifact classic-editor "$CLASSIC_VERSION" cli2)
wp1 plugin install "$CLASSIC_ARTIFACT_1" --activate >/dev/null
CLASSIC_INSTALLED_1=$(wp1 plugin get classic-editor --field=version)
[ "$CLASSIC_INSTALLED_1" = "$CLASSIC_VERSION" ] \
  || fail "side 1 installed version mismatch: expected $CLASSIC_VERSION, got $CLASSIC_INSTALLED_1"
pass "side 1: classic-editor $CLASSIC_VERSION installed from verified artifact, active"

cat > "siterepo/${PAIR}1/site.duo.json" <<'EOF'
{
  "manifests": ["core", "classic-editor"],
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
"${GIT1[@]}" commit -qm "policy: Classic Editor $CLASSIC_VERSION exact-boundary certification"
"${GIT1[@]}" push -qu origin main

seed_classic_editor_content
wp1 duo capture --repo=/siterepo
wp1 duo lint --repo=/siterepo
"${GIT1[@]}" add -A
"${GIT1[@]}" commit -qm "capture: Classic Editor $CLASSIC_VERSION settings and routed posts"
"${GIT1[@]}" push -q origin main

clone_case_target
wp2 plugin install "$CLASSIC_ARTIFACT_2" >/dev/null
INSTALLED_2=$(wp2 plugin get classic-editor --field=version)
require_fixture_values INSTALLED_2
[ "$INSTALLED_2" = "$CLASSIC_VERSION" ] \
  || fail "side 2 installed version mismatch: expected $CLASSIC_VERSION, got $INSTALLED_2"
wp2 duo deploy --repo=/siterepo
REV=$(git -C "siterepo/${PAIR}2" rev-parse HEAD)
wp2 duo apply --repo=/siterepo --adopt-by-slug=terms,posts --default-author=admin --revision="$REV" | tee "$VMATRIX_APPLY_LOG"
grep -q 'canary clean' "$VMATRIX_APPLY_LOG" \
  || fail "apply canary not clean at classic-editor $CLASSIC_VERSION"
check_classic_editor_boundary_content

wp2 duo capture --repo=/siterepo --out=/siterepo/.tmp-final
CLASSIC_DIFF=$(diff -rq "siterepo/${PAIR}1/state" "siterepo/${PAIR}2/.tmp-final" || true)
rm -rf "siterepo/${PAIR}2/.tmp-final"
[ -z "$CLASSIC_DIFF" ] \
  || fail "byte-identity broken at classic-editor $CLASSIC_VERSION: $CLASSIC_DIFF"
pass "Classic Editor $CLASSIC_VERSION deploys, routes both editor modes natively, and recaptures byte-identically"

say "negative control: classic-editor 1.6.7 (adjacent official release below the exact 1.7.0 contract) must be REFUSED"
reset_env wp1
reset_case_repositories

CLASSIC_IN_RANGE=$(fetch_artifact classic-editor 1.7.0 cli1)
wp1 plugin install "$CLASSIC_IN_RANGE" --activate >/dev/null
NEGATIVE_INSTALLED=$(wp1 plugin get classic-editor --field=version)
require_fixture_values NEGATIVE_INSTALLED
[ "$NEGATIVE_INSTALLED" = "1.7.0" ] \
  || fail "negative control premise did not install exact classic-editor 1.7.0 bytes"
cat > "siterepo/${PAIR}1/site.duo.json" <<'EOF'
{
  "manifests": ["core", "classic-editor"],
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
"${GIT1[@]}" commit -qm "policy: Classic Editor negative-control pin"
"${GIT1[@]}" push -qu origin main
seed_classic_editor_content
wp1 duo capture --repo=/siterepo
"${GIT1[@]}" add -A
"${GIT1[@]}" commit -qm "capture: valid Classic Editor state for negative control"
"${GIT1[@]}" push -q origin main

wp1 plugin deactivate classic-editor >/dev/null
wp1 plugin delete classic-editor >/dev/null
CLASSIC_OUT_OF_RANGE=$(fetch_artifact classic-editor 1.6.7 cli1)
wp1 plugin install "$CLASSIC_OUT_OF_RANGE" >/dev/null
INSTALLED_OOR=$(wp1 plugin get classic-editor --field=version)
[ "$INSTALLED_OOR" = "1.6.7" ] \
  || fail "negative control: expected classic-editor 1.6.7 installed, got $INSTALLED_OOR"
CLASSIC_REFUSAL_BEFORE=$(wp1 eval 'echo hash("sha256", wp_json_encode([get_option("classic-editor-replace", null), get_option("classic-editor-allow-users", null)]));')
require_observed_nonempty "Classic Editor refusal state baseline" "$CLASSIC_REFUSAL_BEFORE"
set +e
DEPLOY_OUT=$(wp1 duo deploy --repo=/siterepo 2>&1)
DEPLOY_RC=$?
set -e
[ "$DEPLOY_RC" -ne 0 ] \
  || fail "expected deploy to refuse classic-editor 1.6.7, but it exited 0 (got: $DEPLOY_OUT)"
grep -Eq "outside_version_range|outside the '.*' manifest's declared version_range" <<<"$DEPLOY_OUT" \
  || fail "classic-editor 1.6.7 refused for the wrong reason (got: $DEPLOY_OUT)"
grep -q "classic-editor/classic-editor.php" <<<"$DEPLOY_OUT" \
  || fail "Classic Editor refusal did not name the exact basename (got: $DEPLOY_OUT)"
grep -q "1.6.7" <<<"$DEPLOY_OUT" \
  || fail "Classic Editor refusal did not name installed version 1.6.7 (got: $DEPLOY_OUT)"
if wp1 plugin is-active classic-editor >/dev/null 2>&1; then
  fail "outside-range classic-editor 1.6.7 was activated before refusal"
fi
CLASSIC_REFUSAL_AFTER=$(wp1 eval 'echo hash("sha256", wp_json_encode([get_option("classic-editor-replace", null), get_option("classic-editor-allow-users", null)]));')
[ "$CLASSIC_REFUSAL_AFTER" = "$CLASSIC_REFUSAL_BEFORE" ] \
  || fail "Classic Editor outside-range refusal mutated authored settings"
printf '%s\n' "$DEPLOY_OUT"
pass "official classic-editor 1.6.7 is loudly refused, remains inactive, and cannot mutate admitted settings"
}
