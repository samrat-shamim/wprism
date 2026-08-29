seed_acf_content() { # seed_acf_content <cli-fn>
  local cli="$1"
  cat > "siterepo/${PAIR}1/.tmp-seed-acf.php" <<'PHPEOF'
<?php
if (!function_exists('acf_update_field_group')) {
    fwrite(STDERR, "ACF functions not available\n");
    exit(1);
}
acf_update_field_group([
    'key' => 'group_wprism_demo', 'title' => 'WPrism Demo', 'fields' => [],
    'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'post']]],
    'menu_order' => 0, 'position' => 'normal', 'style' => 'default',
    'label_placement' => 'top', 'instruction_placement' => 'label', 'active' => true,
]);
$group_posts = get_posts(['post_type' => 'acf-field-group', 'name' => 'group_wprism_demo', 'posts_per_page' => 1, 'fields' => 'ids', 'post_status' => 'any']);
$group_id = $group_posts ? (int) $group_posts[0] : 0;
if (!$group_id) { fwrite(STDERR, "field group not created\n"); exit(1); }
acf_update_field([
    'key' => 'field_wprism_related', 'label' => 'Related', 'name' => 'wprism_related',
    'type' => 'relationship', 'parent' => $group_id, 'post_type' => ['post'], 'return_format' => 'id',
]);
$target = wp_insert_post([
    'post_type' => 'post', 'post_status' => 'publish',
    'post_title' => 'Version Matrix Related Target', 'post_name' => 'vmatrix-related-target',
    'post_content' => "<!-- wp:paragraph -->\n<p>Relationship target.</p>\n<!-- /wp:paragraph -->",
], true);
if (is_wp_error($target)) { fwrite(STDERR, "target post insert failed\n"); exit(1); }
$content_id = wp_insert_post([
    'post_type' => 'post', 'post_status' => 'publish',
    'post_title' => 'Version Matrix ACF Content', 'post_name' => 'vmatrix-acf-content',
    'post_content' => "<!-- wp:paragraph -->\n<p>Carries an ACF field.</p>\n<!-- /wp:paragraph -->",
], true);
if (is_wp_error($content_id)) { fwrite(STDERR, "content post insert failed\n"); exit(1); }
update_field('wprism_related', [$target], $content_id);
echo json_encode(['group' => $group_id, 'target' => $target, 'content' => $content_id]) . "\n";
PHPEOF
  local seed_out
  seed_out=$("$cli" eval-file /siterepo/.tmp-seed-acf.php)
  rm -f "siterepo/${PAIR}1/.tmp-seed-acf.php"
  echo "acf seed: $seed_out"
}

VMATRIX_PLUGIN_SLUG=advanced-custom-fields

version_matrix_workflow() {
VMATRIX_CASES=$((VMATRIX_CASES + 1))
for ACF_VERSION in 6.0.0 6.8.7; do
  say "boundary: acf $ACF_VERSION"

  reset_env wp1
  reset_env wp2
  reset_case_repositories

  say "fetch + verify acf $ACF_VERSION (never a bare slug install — always a digest-checked artifact)"
  ARTIFACT_1=$(fetch_artifact advanced-custom-fields "$ACF_VERSION" cli1)
  ARTIFACT_2=$(fetch_artifact advanced-custom-fields "$ACF_VERSION" cli2)
  pass "verified sha256-pinned artifact resolved for both sides: $ARTIFACT_1"

  wp1 plugin install "$ARTIFACT_1" --activate >/dev/null
  INSTALLED_1=$(wp1 plugin get advanced-custom-fields --field=version)
  [ "$INSTALLED_1" = "$ACF_VERSION" ] || fail "side 1 installed version mismatch: expected $ACF_VERSION, got $INSTALLED_1"
  pass "side 1: acf $ACF_VERSION installed from verified artifact, active"

  cat > "siterepo/${PAIR}1/site.wprism.json" <<EOF
{
  "manifests": ["core", "acf"],
  "policy": {
    "options": {},
    "post_meta": {},
    "post_types": ["post", "page", "attachment", "acf-field-group", "acf-field"],
    "taxonomies": ["category", "post_tag"]
  },
  "spec_version": 2
}
EOF
  cp site-repo.gitignore.template "siterepo/${PAIR}1/.gitignore"
  "${GIT1[@]}" init -q -b main
  "${GIT1[@]}" remote add origin "../origin-$PAIR.git"
  "${GIT1[@]}" add -A
  "${GIT1[@]}" commit -qm "policy: acf $ACF_VERSION version-boundary certification"
  "${GIT1[@]}" push -qu origin main

  seed_acf_content wp1

  wp1 wprism capture --repo=/siterepo
  pass "captured on side 1 (acf $ACF_VERSION)"

  wp1 wprism lint --repo=/siterepo
  pass "lint: 0 findings"

  "${GIT1[@]}" add -A
  "${GIT1[@]}" commit -qm "capture: acf $ACF_VERSION content"
  "${GIT1[@]}" push -q origin main

  clone_case_target
  wp2 plugin install "$ARTIFACT_2" >/dev/null
  INSTALLED_2=$(wp2 plugin get advanced-custom-fields --field=version)
  require_fixture_values INSTALLED_2
  [ "$INSTALLED_2" = "$ACF_VERSION" ] || fail "side 2 installed version mismatch: expected $ACF_VERSION, got $INSTALLED_2"

  wp2 wprism deploy --repo=/siterepo
  REV=$(git -C "siterepo/${PAIR}2" rev-parse HEAD)
  # --adopt-by-slug=terms,posts: WordPress core's own defaults (the
  # "Uncategorized" category always, a "Hello World" post/"Sample Page" on
  # some installs) survive `site empty --yes` and collide by slug with the
  # captured state's own entities of the same name — the same known,
  # expected pattern every other grind/certify pair script in this repo
  # already handles the identical way (grind_r3b_events.sh, grind_r1b_shop.sh).
  wp2 wprism apply --repo=/siterepo --adopt-by-slug=terms,posts --default-author=admin --revision="$REV" | tee "$VMATRIX_APPLY_LOG"
  grep -q 'canary clean' "$VMATRIX_APPLY_LOG" || fail "apply canary not clean at acf $ACF_VERSION"
  pass "deploy + apply succeeded on side 2 (acf $ACF_VERSION, canary clean)"

  wp2 wprism capture --repo=/siterepo --out="/siterepo/.tmp-final"
  DIFF_OUT=$(diff -rq "siterepo/${PAIR}1/state" "siterepo/${PAIR}2/.tmp-final" || true)
  rm -rf "siterepo/${PAIR}2/.tmp-final"
  [ -z "$DIFF_OUT" ] || fail "byte-identity broken at acf $ACF_VERSION: $DIFF_OUT"
  pass "byte-identical recapture at acf $ACF_VERSION — the manifest's own declared version_range boundary is proven, not just its currently-installed version"

  if [ "$ACF_VERSION" = 6.0.0 ]; then
    say "in-place lifecycle: acf 6.0.0 authored state -> exact 6.8.7 on both environments"
    UPGRADE_ARTIFACT_1=$(fetch_artifact advanced-custom-fields 6.8.7 cli1)
    UPGRADE_ARTIFACT_2=$(fetch_artifact advanced-custom-fields 6.8.7 cli2)
    wp1 plugin install "$UPGRADE_ARTIFACT_1" --force >/dev/null
    wp2 plugin install "$UPGRADE_ARTIFACT_2" --force >/dev/null
    [ "$(wp1 plugin get advanced-custom-fields --field=version)" = 6.8.7 ] \
      && [ "$(wp2 plugin get advanced-custom-fields --field=version)" = 6.8.7 ] \
      || fail "ACF in-place upgrade did not install exact 6.8.7 on both environments"

    # Deploy reasserts the repository's active-code intent after the exact
    # replacement, then source recapture publishes any real plugin migration
    # of authored bytes instead of assuming the two releases store them alike.
    wp1 wprism deploy --repo=/siterepo --force-code-drift >/dev/null
    wp2 wprism deploy --repo=/siterepo --force-code-drift >/dev/null
    wp1 wprism capture --repo=/siterepo
    wp1 wprism lint --repo=/siterepo
    if ! git -C "siterepo/${PAIR}1" diff --quiet -- state; then
      "${GIT1[@]}" add -A
      "${GIT1[@]}" commit -qm "capture: ACF in-place 6.0.0 to 6.8.7 migration"
      "${GIT1[@]}" push -q origin main
      git -C "siterepo/${PAIR}2" pull -q origin main
    fi
    UPGRADE_REV=$(git -C "siterepo/${PAIR}2" rev-parse HEAD)
    wp2 wprism apply --repo=/siterepo --adopt-by-slug=terms,posts --default-author=admin --revision="$UPGRADE_REV" \
      2>&1 | tee "$VMATRIX_APPLY_LOG"
    grep -q 'canary clean' "$VMATRIX_APPLY_LOG" \
      || fail "ACF in-place 6.0.0 -> 6.8.7 apply canary was not clean"

    UPGRADE_NATIVE=$(wp2 eval '
      $content=get_page_by_path("vmatrix-acf-content", OBJECT, "post");
      $related=$content ? get_field("wprism_related", $content->ID) : [];
      $target=$related ? get_post((int)$related[0]) : null;
      echo $target ? $target->post_title : "";
    ')
    [ "$UPGRADE_NATIVE" = 'Version Matrix Related Target' ] \
      || fail "ACF 6.8.7 did not resolve the relationship authored under 6.0.0: $UPGRADE_NATIVE"
    wp2 wprism capture --repo=/siterepo --out=/siterepo/.tmp-acf-upgrade-final
    UPGRADE_DIFF=$(diff -rq "siterepo/${PAIR}1/state" "siterepo/${PAIR}2/.tmp-acf-upgrade-final" || true)
    rm -rf "siterepo/${PAIR}2/.tmp-acf-upgrade-final"
    [ -z "$UPGRADE_DIFF" ] \
      || fail "ACF in-place 6.0.0 -> 6.8.7 recapture was not byte-identical: $UPGRADE_DIFF"
    pass "ACF state authored under exact 6.0.0 upgrades in place to exact 6.8.7, remains plugin-visible, applies cleanly, and recaptures byte-identically"
  fi
done

say "negative control: acf 5.12.6 (real wp.org release, genuinely below adapter-packages/acf/package/manifest.json's own declared min 6.0.0) must be REFUSED, not silently accepted"
reset_env wp1
reset_case_repositories

OUT_OF_RANGE_ARTIFACT=$(fetch_artifact advanced-custom-fields 5.12.6 cli1)
wp1 plugin install "$OUT_OF_RANGE_ARTIFACT" --activate >/dev/null
INSTALLED_OOR=$(wp1 plugin get advanced-custom-fields --field=version)
[ "$INSTALLED_OOR" = "5.12.6" ] || fail "negative control: expected acf 5.12.6 installed, got $INSTALLED_OOR"

cat > "siterepo/${PAIR}1/site.wprism.json" <<'EOF'
{
  "manifests": ["core", "acf"],
  "policy": {
    "options": {},
    "post_meta": {},
    "post_types": ["post", "page", "attachment", "acf-field-group", "acf-field"],
    "taxonomies": ["category", "post_tag"]
  },
  "spec_version": 2
}
EOF
cp site-repo.gitignore.template "siterepo/${PAIR}1/.gitignore"
"${GIT1[@]}" init -q -b main
"${GIT1[@]}" remote add origin "../origin-$PAIR.git"
"${GIT1[@]}" add -A
"${GIT1[@]}" commit -qm "policy: acf negative-control pin, out-of-range plugin installed"
"${GIT1[@]}" push -qu origin main

# `wprism deploy` compiles the repository before it ever reaches code_mismatch()
# and refuses loudly if state/ doesn't exist yet ([state_directory_missing])
# — found live on this section's own first attempt. A real capture (harmless
# with the out-of-range plugin installed: capture itself never checks
# version_range, only deploy/apply do — confirmed by direct read of
# Deploy::code_mismatch()'s own call sites) produces a valid state/ tree
# cheaply, with zero ACF-specific content since nothing has been seeded.
wp1 wprism capture --repo=/siterepo
"${GIT1[@]}" add -A
"${GIT1[@]}" commit -qm "capture: empty state, acf 5.12.6 still installed"
"${GIT1[@]}" push -q origin main

set +e
DEPLOY_OUT=$(wp1 wprism deploy --repo=/siterepo 2>&1)
DEPLOY_RC=$?
set -e
[ "$DEPLOY_RC" -ne 0 ] || fail "expected deploy to refuse acf 5.12.6 as outside_version_range, but it exited 0 (got: $DEPLOY_OUT)"
grep -Eq "outside_version_range|outside the '.*' manifest's declared version_range" <<<"$DEPLOY_OUT" \
  || fail "deploy refused, but not for the expected outside_version_range reason (got: $DEPLOY_OUT)"
grep -q "advanced-custom-fields/acf.php" <<<"$DEPLOY_OUT" || fail "refusal did not name the plugin (got: $DEPLOY_OUT)"
grep -q "5.12.6" <<<"$DEPLOY_OUT" || fail "refusal did not name the actually-installed version (got: $DEPLOY_OUT)"
printf '%s\n' "$DEPLOY_OUT"
pass "confirmed: acf 5.12.6 (real, installed, genuinely below the declared min) is loudly refused by Deploy::code_mismatch() — the version_range pin is honest, not just decorative"
}
