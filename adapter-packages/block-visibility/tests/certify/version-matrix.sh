seed_block_visibility_content() {
  wp_conf1() { wp1 "$@"; }
  local CONF_REPO1="siterepo/${PAIR}1"
  . "$(dirname "${BASH_SOURCE[0]}")/../conformance/seed.sh"
  unset -f wp_conf1
}

seed_block_visibility_target_state() {
  wp_conf2() { wp2 "$@"; }
  . "$(dirname "${BASH_SOURCE[0]}")/../conformance/postdeploy.sh"
  unset -f wp_conf2
}

check_block_visibility_boundary_content() {
  local cli="$1" label="$2" settings sibling preset meta
  settings=$($cli option get block_visibility_settings --format=json)
  printf '%s\n' "$settings" | jq -e '
    .plugin_settings.block_opacity == 45 and
    .plugin_settings.enable_contextual_indicators == false and
    .visibility_controls.cookie.enable == false and
    .disabled_blocks == ["core/separator", "core/spacer"]
  ' >/dev/null \
    || fail "Block Visibility $label settings did not converge: $settings"
  sibling=$($cli eval '
    $settings = (array) get_option("block_visibility_settings", []);
    echo $settings["block_visibility_target_only_probe"] ?? "";
  ')
  [ "$sibling" = "preserve-me" ] \
    || fail "Block Visibility $label erased its target-only settings sibling: $sibling"
  preset=$($cli post list --post_type=visibility_preset --name=logged-in-only --field=ID)
  require_observed_nonempty "Block Visibility $label preset identity" "$preset"
  meta=$($cli eval "echo wp_json_encode(get_post_meta((int) $preset));")
  printf '%s\n' "$meta" | jq -e '
    .enable == ["1"] and .hide_block == ["0"] and .layout == ["columns"] and
    .control_sets[0].controls.userRole.restrictedRoles == ["administrator", "editor"]
  ' >/dev/null \
    || fail "Block Visibility $label preset did not converge: $meta"
  pass "Block Visibility $label settings and entity-free preset converge through native storage"
}

VMATRIX_PLUGIN_SLUG=block-visibility

version_matrix_reset_after_delete() {
  local cli="$1"
  "$cli" eval '
    delete_option("block_visibility_settings");
    foreach (get_posts(["post_type" => "visibility_preset", "numberposts" => -1, "fields" => "ids"]) as $id) {
        wp_delete_post((int) $id, true);
    }
  ' >/dev/null
}

version_matrix_workflow() {
  VMATRIX_CASES=$((VMATRIX_CASES + 1))
  BLOCK_VISIBILITY_VERSION=3.7.1
  say "boundary: Block Visibility $BLOCK_VISIBILITY_VERSION (bounded settings and preset surface)"

  reset_env wp1
  reset_env wp2
  reset_case_repositories

  say "fetch + verify Block Visibility $BLOCK_VISIBILITY_VERSION (digest-checked artifact only)"
  BLOCK_ARTIFACT_1=$(fetch_artifact block-visibility "$BLOCK_VISIBILITY_VERSION" cli1)
  BLOCK_ARTIFACT_2=$(fetch_artifact block-visibility "$BLOCK_VISIBILITY_VERSION" cli2)
  wp1 plugin install "$BLOCK_ARTIFACT_1" --activate >/dev/null
  INSTALLED_1=$(wp1 plugin get block-visibility --field=version)
  [ "$INSTALLED_1" = "$BLOCK_VISIBILITY_VERSION" ] \
    || fail "side 1 installed version mismatch: expected $BLOCK_VISIBILITY_VERSION, got $INSTALLED_1"
  pass "side 1: Block Visibility $BLOCK_VISIBILITY_VERSION installed from verified artifact, active"

  cat > "siterepo/${PAIR}1/site.wprism.json" <<'EOF'
{
  "manifests": ["core", "block-visibility"],
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
  "${GIT1[@]}" commit -qm "policy: Block Visibility $BLOCK_VISIBILITY_VERSION bounded certification"
  "${GIT1[@]}" push -qu origin main

  seed_block_visibility_content
  wp1 wprism capture --repo=/siterepo
  wp1 wprism lint --repo=/siterepo
  "${GIT1[@]}" add -A
  "${GIT1[@]}" commit -qm "capture: Block Visibility settings and preset"
  "${GIT1[@]}" push -q origin main

  clone_case_target
  wp2 plugin install "$BLOCK_ARTIFACT_2" >/dev/null
  INSTALLED_2=$(wp2 plugin get block-visibility --field=version)
  require_fixture_values INSTALLED_2
  [ "$INSTALLED_2" = "$BLOCK_VISIBILITY_VERSION" ] \
    || fail "side 2 installed version mismatch: expected $BLOCK_VISIBILITY_VERSION, got $INSTALLED_2"
  wp2 wprism deploy --repo=/siterepo
  seed_block_visibility_target_state
  REV=$(git -C "siterepo/${PAIR}2" rev-parse HEAD)
  wp2 wprism apply --repo=/siterepo --adopt-by-slug=terms,posts --default-author=admin --revision="$REV" 2>&1 | tee "$VMATRIX_APPLY_LOG"
  assert_version_matrix_apply_ready
  grep -q 'canary clean' "$VMATRIX_APPLY_LOG" \
    || fail "apply canary not clean at Block Visibility $BLOCK_VISIBILITY_VERSION"
  check_block_visibility_boundary_content wp2 initial

  wp2 wprism capture --repo=/siterepo --out=/siterepo/.tmp-final
  BLOCK_DIFF=$(diff -rq "siterepo/${PAIR}1/state" "siterepo/${PAIR}2/.tmp-final" || true)
  rm -rf "siterepo/${PAIR}2/.tmp-final"
  [ -z "$BLOCK_DIFF" ] \
    || fail "byte-identity broken at Block Visibility $BLOCK_VISIBILITY_VERSION: $BLOCK_DIFF"
  pass "Block Visibility $BLOCK_VISIBILITY_VERSION deploys, applies the bounded surface, and recaptures byte-identically"

  say "lifecycle: exact Block Visibility uninstall and reinstall"
  wp2 plugin deactivate block-visibility >/dev/null
  wp2 plugin uninstall block-visibility --deactivate >/dev/null
  wp2 plugin is-installed block-visibility >/dev/null 2>&1 \
    && fail "Block Visibility uninstall left plugin code installed"
  [ "$(wp2 option get block_visibility_settings 2>/dev/null || true)" = "" ] \
    || fail "Block Visibility uninstall left the settings option"
  [ -z "$(wp2 post list --post_type=visibility_preset --field=ID)" ] \
    || fail "Block Visibility uninstall left visibility_preset entities"
  wp2 plugin install "$BLOCK_ARTIFACT_2" --force >/dev/null
  wp2 wprism deploy --repo=/siterepo
  wp2 plugin is-active block-visibility >/dev/null \
    || fail "Block Visibility reinstall was not activated by deploy"
  pass "Block Visibility uninstall removes plugin-owned settings and presets, and exact reinstall restores activation"

  say "negative control: Block Visibility 3.7.0 must be refused"
  reset_env wp1
  reset_case_repositories
  BLOCK_IN_RANGE=$(fetch_artifact block-visibility 3.7.1 cli1)
  wp1 plugin install "$BLOCK_IN_RANGE" --activate >/dev/null
  cat > "siterepo/${PAIR}1/site.wprism.json" <<'EOF'
{
  "manifests": ["core", "block-visibility"],
  "policy": {"options": {}, "post_meta": {}, "post_types": ["post", "page", "attachment"], "taxonomies": ["category", "post_tag"]},
  "spec_version": 2
}
EOF
  cp site-repo.gitignore.template "siterepo/${PAIR}1/.gitignore"
  "${GIT1[@]}" init -q -b main
  "${GIT1[@]}" remote add origin "../origin-$PAIR.git"
  "${GIT1[@]}" add -A
  "${GIT1[@]}" commit -qm "policy: Block Visibility negative-control pin"
  "${GIT1[@]}" push -qu origin main
  seed_block_visibility_content
  wp1 wprism capture --repo=/siterepo
  "${GIT1[@]}" add -A
  "${GIT1[@]}" commit -qm "capture: Block Visibility valid negative-control state"
  "${GIT1[@]}" push -q origin main

  REFUSAL_BEFORE=$(wp1 db query "SELECT IFNULL(SHA2(option_value, 256), 'absent') FROM wp_options WHERE option_name = 'block_visibility_settings';" --skip-column-names)
  require_observed_nonempty "Block Visibility refusal state baseline" "$REFUSAL_BEFORE"
  wp1 plugin deactivate block-visibility >/dev/null
  wp1 plugin delete block-visibility >/dev/null
  BLOCK_OUT_OF_RANGE=$(fetch_artifact block-visibility 3.7.0 cli1)
  wp1 plugin install "$BLOCK_OUT_OF_RANGE" >/dev/null
  [ "$(wp1 plugin get block-visibility --field=version)" = "3.7.0" ] \
    || fail "negative control expected Block Visibility 3.7.0"
  set +e
  DEPLOY_OUT=$(wp1 wprism deploy --repo=/siterepo 2>&1)
  DEPLOY_RC=$?
  set -e
  [ "$DEPLOY_RC" -ne 0 ] \
    || fail "expected Block Visibility 3.7.0 deploy to refuse"
  grep -Eq "outside_version_range|outside the '.*' manifest's declared version_range" <<<"$DEPLOY_OUT" \
    || fail "Block Visibility 3.7.0 refused for the wrong reason: $DEPLOY_OUT"
  grep -q "block-visibility/block-visibility.php" <<<"$DEPLOY_OUT" \
    || fail "Block Visibility refusal did not name the exact basename"
  grep -q "3.7.0" <<<"$DEPLOY_OUT" \
    || fail "Block Visibility refusal did not name installed version 3.7.0"
  if wp1 plugin is-active block-visibility >/dev/null 2>&1; then
    fail "out-of-range Block Visibility 3.7.0 was activated before refusal"
  fi
  REFUSAL_AFTER=$(wp1 db query "SELECT IFNULL(SHA2(option_value, 256), 'absent') FROM wp_options WHERE option_name = 'block_visibility_settings';" --skip-column-names)
  [ "$REFUSAL_AFTER" = "$REFUSAL_BEFORE" ] \
    || fail "Block Visibility outside-range refusal mutated authored settings"
  pass "official Block Visibility 3.7.0 is loudly refused, remains inactive, and cannot mutate admitted settings"
}
