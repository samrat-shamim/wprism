seed_cf7_content() { # seed_cf7_content <cli-fn>
  local cli="$1"
  cat > "siterepo/${PAIR}1/.tmp-seed-cf7.php" <<'PHPEOF'
<?php
if (!class_exists('WPCF7_ContactForm')) {
    fwrite(STDERR, "WPCF7_ContactForm not loaded\n");
    exit(1);
}
wp_set_current_user(get_user_by('login', 'admin')->ID);
$form = WPCF7_ContactForm::get_template(['title' => 'Version Matrix Contact Form']);
$mail = $form->prop('mail');
$mail['recipient'] = 'vmatrix@example.test';
$mail['subject'] = '[Version Matrix] [your-subject]';
$form->set_properties(['mail' => $mail]);
$form_id = $form->save();
if (!$form_id) { fwrite(STDERR, "CF7 save() failed\n"); exit(1); }
$form = WPCF7_ContactForm::get_instance($form_id);
$shortcode = $form->shortcode();
$old_id = 3199001;
if ((string) $old_id === (string) $form_id) { fwrite(STDERR, "legacy alternate equals source post id\n"); exit(1); }
update_post_meta($form_id, '_old_cf7_unit_id', $old_id);
$legacy_shortcode = '[contact-form ' . $old_id . ' "Version Matrix Contact Form"]';
$page_id = wp_insert_post([
    'post_type' => 'page', 'post_status' => 'publish',
    'post_title' => 'Version Matrix Contact', 'post_name' => 'vmatrix-contact',
    'post_content' => "<!-- wp:paragraph -->\n<p>Contact Form 7 boundary fixture.</p>\n<!-- /wp:paragraph -->\n<!-- wp:shortcode -->\n{$shortcode}\n<!-- /wp:shortcode -->",
], true);
if (is_wp_error($page_id)) { fwrite(STDERR, "page insert failed\n"); exit(1); }
$legacy_page_id = wp_insert_post([
    'post_type' => 'page', 'post_status' => 'publish',
    'post_title' => 'Version Matrix Contact Legacy', 'post_name' => 'vmatrix-contact-legacy',
    'post_content' => "<!-- wp:paragraph -->\n<p>Legacy Contact Form 7 boundary fixture.</p>\n<!-- /wp:paragraph -->\n<!-- wp:shortcode -->\n{$legacy_shortcode}\n<!-- /wp:shortcode -->",
], true);
if (is_wp_error($legacy_page_id)) { fwrite(STDERR, "legacy page insert failed\n"); exit(1); }
echo json_encode([
    'form' => $form_id, 'page' => $page_id, 'shortcode' => $shortcode,
    'old_id' => $old_id, 'legacy_page' => $legacy_page_id, 'legacy_shortcode' => $legacy_shortcode,
]) . "\n";
PHPEOF
  local seed_out
  seed_out=$("$cli" eval-file /siterepo/.tmp-seed-cf7.php)
  rm -f "siterepo/${PAIR}1/.tmp-seed-cf7.php"
  CF7_SEED_OUT="$seed_out"
  echo "cf7 seed: $seed_out"
}

VMATRIX_PLUGIN_SLUG=contact-form-7

version_matrix_reset_after_delete() {
  local cli="$1"
  # The initial form is created only when this option is absent.
  "$cli" db query "DELETE FROM wp_options WHERE option_name = 'wpcf7';" >/dev/null
}

version_matrix_workflow() {
VMATRIX_CASES=$((VMATRIX_CASES + 1))
for CF7_VERSION in 6.0 6.1.7; do
  say "boundary: contact-form-7 $CF7_VERSION"

  reset_env wp1
  reset_env wp2
  reset_case_repositories

  say "fetch + verify contact-form-7 $CF7_VERSION (never a bare slug install — always a digest-checked artifact)"
  ARTIFACT_1=$(fetch_artifact contact-form-7 "$CF7_VERSION" cli1)
  ARTIFACT_2=$(fetch_artifact contact-form-7 "$CF7_VERSION" cli2)
  pass "verified sha256-pinned artifact resolved for both sides: $ARTIFACT_1"

  wp1 plugin install "$ARTIFACT_1" --activate >/dev/null
  INSTALLED_1=$(wp1 plugin get contact-form-7 --field=version)
  [ "$INSTALLED_1" = "$CF7_VERSION" ] || fail "side 1 installed version mismatch: expected $CF7_VERSION, got $INSTALLED_1"
  pass "side 1: contact-form-7 $CF7_VERSION installed from verified artifact, active"

  cat > "siterepo/${PAIR}1/site.duo.json" <<EOF
{
  "manifests": ["core", "contact-form-7"],
  "policy": {
    "options": {},
    "post_meta": {},
    "post_types": ["post", "page", "attachment", "wpcf7_contact_form"],
    "taxonomies": ["category", "post_tag"]
  },
  "spec_version": 2
}
EOF
  cp site-repo.gitignore.template "siterepo/${PAIR}1/.gitignore"
  "${GIT1[@]}" init -q -b main
  "${GIT1[@]}" remote add origin "../origin-$PAIR.git"
  "${GIT1[@]}" add -A
  "${GIT1[@]}" commit -qm "policy: contact-form-7 $CF7_VERSION version-boundary certification"
  "${GIT1[@]}" push -qu origin main

  seed_cf7_content wp1

  CF7_OLD_ID=$(jq -r '.old_id' <<<"$CF7_SEED_OUT")
  CF7_FORM_ID=$(jq -r '.form' <<<"$CF7_SEED_OUT")
  CF7_MODERN_SHORTCODE=$(jq -r '.shortcode' <<<"$CF7_SEED_OUT")
  CF7_LEGACY_PAGE_ID=$(jq -r '.legacy_page' <<<"$CF7_SEED_OUT")
  CF7_SOURCE_HASH=$(wp1 post meta get "$CF7_FORM_ID" _hash)
  require_fixture_values CF7_MODERN_SHORTCODE CF7_SOURCE_HASH
  [[ "$CF7_OLD_ID" =~ ^[1-9][0-9]+$ && "$CF7_FORM_ID" =~ ^[0-9]+$ && "$CF7_LEGACY_PAGE_ID" =~ ^[0-9]+$ \
    && "$CF7_SOURCE_HASH" =~ ^([0-9a-f]{40}|[0-9a-f]{64})$ \
    && "$CF7_MODERN_SHORTCODE" =~ ^\[contact-form-7\ id=\"[0-9a-f]{7}\" ]] \
    || fail "CF7 $CF7_VERSION seed did not return native legacy and modern identities"
  [[ "$CF7_MODERN_SHORTCODE" == *"id=\"${CF7_SOURCE_HASH:0:7}\""* ]] \
    || fail "CF7 $CF7_VERSION shortcode does not use the persisted _hash prefix: $CF7_MODERN_SHORTCODE"

  wp1 duo capture --repo=/siterepo
  pass "captured on side 1 (contact-form-7 $CF7_VERSION)"

  if rg -n "\[contact-form[[:space:]]+$CF7_OLD_ID([[:space:]]|\])" "siterepo/${PAIR}1/state/posts" >/dev/null 2>&1; then
    fail "CF7 $CF7_VERSION capture retained raw legacy alternate $CF7_OLD_ID"
  fi
  rg -n '\[contact-form[[:space:]]+\{\{post:[0-9a-f-]{36}\}\}' "siterepo/${PAIR}1/state/posts" >/dev/null 2>&1 \
    || fail "CF7 $CF7_VERSION capture did not emit a canonical positional post token"
  if rg -n "\[contact-form-7[^]]*id=\"${CF7_SOURCE_HASH:0:7}\"" "siterepo/${PAIR}1/state/posts" >/dev/null 2>&1; then
    fail "CF7 $CF7_VERSION capture retained raw modern hash prefix ${CF7_SOURCE_HASH:0:7}"
  fi
  rg -n '\[contact-form-7[^]]*id="\{\{post:[0-9a-f-]{36}\}\}"' "siterepo/${PAIR}1/state/posts" >/dev/null 2>&1 \
    || fail "CF7 $CF7_VERSION capture did not emit a canonical named post token"
  pass "capture: contact-form-7 $CF7_VERSION canonicalized legacy decimal and modern hash-prefix identities"

  wp1 duo lint --repo=/siterepo
  pass "lint: 0 findings"

  "${GIT1[@]}" add -A
  "${GIT1[@]}" commit -qm "capture: contact-form-7 $CF7_VERSION content"
  "${GIT1[@]}" push -q origin main

  clone_case_target
  wp2 plugin install "$ARTIFACT_2" >/dev/null
  INSTALLED_2=$(wp2 plugin get contact-form-7 --field=version)
  require_fixture_values INSTALLED_2
  [ "$INSTALLED_2" = "$CF7_VERSION" ] || fail "side 2 installed version mismatch: expected $CF7_VERSION, got $INSTALLED_2"

  wp2 duo deploy --repo=/siterepo
  REV=$(git -C "siterepo/${PAIR}2" rev-parse HEAD)
  wp2 duo apply --repo=/siterepo --adopt-by-slug=terms,posts --default-author=admin --revision="$REV" | tee "$VMATRIX_APPLY_LOG"
  grep -q 'canary clean' "$VMATRIX_APPLY_LOG" || fail "apply canary not clean at contact-form-7 $CF7_VERSION"
  pass "deploy + apply succeeded on side 2 (contact-form-7 $CF7_VERSION, canary clean)"

  # CF7 derives its post_name from the title/version-specific save path; use
  # the deterministic authored title rather than assuming a slug that the
  # plugin is free to normalize differently across its supported versions.
  TARGET_FORM_ID=$(wp2 post list --post_type=wpcf7_contact_form --title='Version Matrix Contact Form' --format=ids)
  TARGET_MODERN_ID=$(wp2 post list --post_type=page --name=vmatrix-contact --format=ids)
  TARGET_LEGACY_ID=$(wp2 post list --post_type=page --name=vmatrix-contact-legacy --format=ids)
  # The source and target are isolated databases, so their independently
  # created forms may legitimately receive the same numeric post ID.  The
  # target title/meta/render assertions below prove target ownership; numeric
  # inequality across databases would reject a valid deterministic fixture.
  require_fixture_ids TARGET_FORM_ID TARGET_MODERN_ID TARGET_LEGACY_ID
  [ "$TARGET_LEGACY_ID" != "" ] || fail "CF7 $CF7_VERSION target legacy page is missing"
  TARGET_OLD_ID=$(wp2 post meta get "$TARGET_FORM_ID" _old_cf7_unit_id)
  TARGET_HASH=$(wp2 post meta get "$TARGET_FORM_ID" _hash)
  TARGET_MODERN_CONTENT=$(wp2 post get "$TARGET_MODERN_ID" --field=post_content)
  require_fixture_values TARGET_OLD_ID TARGET_HASH TARGET_MODERN_CONTENT
  [ "$TARGET_OLD_ID" = "$CF7_OLD_ID" ] || fail "CF7 $CF7_VERSION target lost _old_cf7_unit_id ($TARGET_OLD_ID vs $CF7_OLD_ID)"
  [ "$TARGET_HASH" = "$CF7_SOURCE_HASH" ] \
    || fail "CF7 $CF7_VERSION target did not receive the repository-authored full _hash"
  grep -Fq "[contact-form-7 id=\"${TARGET_HASH:0:7}\"" <<<"$TARGET_MODERN_CONTENT" \
    || fail "CF7 $CF7_VERSION target page did not receive the repository-authored public hash prefix"
  MODERN_FRONT=$(curl -fs "http://localhost:${PORT2}/vmatrix-contact/") \
    || fail "CF7 $CF7_VERSION target modern page did not render"
  LEGACY_FRONT=$(curl -fs "http://localhost:${PORT2}/vmatrix-contact-legacy/") \
    || fail "CF7 $CF7_VERSION target legacy page did not render"
  require_observed_nonempty "CF7 $CF7_VERSION target modern page" "$MODERN_FRONT"
  require_observed_nonempty "CF7 $CF7_VERSION target legacy page" "$LEGACY_FRONT"
  grep -q "_wpcf7\" value=\"$TARGET_FORM_ID\"" <<<"$MODERN_FRONT" \
    || fail "CF7 $CF7_VERSION target modern hash prefix did not resolve its own form id $TARGET_FORM_ID"
  grep -q "_wpcf7\" value=\"$TARGET_FORM_ID\"" <<<"$LEGACY_FRONT" \
    || fail "CF7 $CF7_VERSION target legacy page did not resolve its own form id $TARGET_FORM_ID"
  pass "target: contact-form-7 $CF7_VERSION modern and legacy shortcodes resolve to target form $TARGET_FORM_ID"

  wp2 duo capture --repo=/siterepo --out="/siterepo/.tmp-final"
  DIFF_OUT=$(diff -rq "siterepo/${PAIR}1/state" "siterepo/${PAIR}2/.tmp-final" || true)
  rm -rf "siterepo/${PAIR}2/.tmp-final"
  [ -z "$DIFF_OUT" ] || fail "byte-identity broken at contact-form-7 $CF7_VERSION: $DIFF_OUT"
  pass "byte-identical recapture at contact-form-7 $CF7_VERSION — the manifest's own declared version_range boundary is proven, not just its currently-installed version"

  if [ "$CF7_VERSION" = 6.0 ]; then
    say "in-place lifecycle: contact-form-7 6.0 authored state -> exact 6.1.7 on both environments"
    UPGRADE_ARTIFACT_1=$(fetch_artifact contact-form-7 6.1.7 cli1)
    UPGRADE_ARTIFACT_2=$(fetch_artifact contact-form-7 6.1.7 cli2)
    wp1 plugin install "$UPGRADE_ARTIFACT_1" --force >/dev/null
    wp2 plugin install "$UPGRADE_ARTIFACT_2" --force >/dev/null
    [ "$(wp1 plugin get contact-form-7 --field=version)" = 6.1.7 ] \
      && [ "$(wp2 plugin get contact-form-7 --field=version)" = 6.1.7 ] \
      || fail "CF7 in-place upgrade did not install exact 6.1.7 on both environments"

    # Exact code replacement changes the captured code witness. Re-baseline
    # that explicit drift, then publish only real native data migrations.
    wp1 duo deploy --repo=/siterepo --force-code-drift >/dev/null
    wp2 duo deploy --repo=/siterepo --force-code-drift >/dev/null
    wp1 duo capture --repo=/siterepo
    wp1 duo lint --repo=/siterepo
    if ! git -C "siterepo/${PAIR}1" diff --quiet -- state; then
      "${GIT1[@]}" add -A
      "${GIT1[@]}" commit -qm "capture: CF7 in-place 6.0 to 6.1.7 migration"
      "${GIT1[@]}" push -q origin main
      git -C "siterepo/${PAIR}2" pull -q origin main
    fi
    UPGRADE_REV=$(git -C "siterepo/${PAIR}2" rev-parse HEAD)
    wp2 duo apply --repo=/siterepo --adopt-by-slug=terms,posts --default-author=admin --revision="$UPGRADE_REV" \
      2>&1 | tee "$VMATRIX_APPLY_LOG"
    grep -q 'canary clean' "$VMATRIX_APPLY_LOG" \
      || fail "CF7 in-place 6.0 -> 6.1.7 apply canary was not clean"

    UPGRADE_NATIVE=$(wp2 eval '
      $forms=get_posts([
        "post_type"=>"wpcf7_contact_form", "post_status"=>"any",
        "title"=>"Version Matrix Contact Form", "posts_per_page"=>2,
      ]);
      $form=count($forms) === 1 ? $forms[0] : null;
      $instance=$form ? WPCF7_ContactForm::get_instance($form->ID) : null;
      $mail=$instance ? (array)$instance->prop("mail") : [];
      echo wp_json_encode([
        "version"=>defined("WPCF7_VERSION") ? WPCF7_VERSION : "",
        "id"=>$form ? (int)$form->ID : 0,
        "hash"=>$form ? (string)get_post_meta($form->ID,"_hash",true) : "",
        "shortcode"=>$instance ? (string)$instance->shortcode() : "",
        "recipient"=>(string)($mail["recipient"] ?? ""),
      ]);
    ')
    require_observed_nonempty "CF7 6.1.7 upgraded native form" "$UPGRADE_NATIVE"
    jq -e '
      .version == "6.1.7" and .id > 0 and .recipient == "vmatrix@example.test" and
      (.hash | test("^([0-9a-f]{40}|[0-9a-f]{64})$")) and
      (.shortcode | test("^\\[contact-form-7 id=\\\"[0-9a-f]{7}\\\""))
    ' <<<"$UPGRADE_NATIVE" >/dev/null \
      || fail "CF7 6.1.7 did not preserve the form authored under 6.0: $UPGRADE_NATIVE"
    UPGRADE_FRONT=$(curl -fs "http://localhost:${PORT2}/vmatrix-contact/") \
      || fail "CF7 6.1.7 did not render the modern page authored under 6.0"
    UPGRADE_FORM_ID=$(jq -r '.id' <<<"$UPGRADE_NATIVE")
    grep -q "_wpcf7\" value=\"$UPGRADE_FORM_ID\"" <<<"$UPGRADE_FRONT" \
      || fail "CF7 6.1.7 did not resolve the modern identity authored under 6.0"
    wp2 duo capture --repo=/siterepo --out=/siterepo/.tmp-cf7-upgrade-final
    UPGRADE_DIFF=$(diff -rq "siterepo/${PAIR}1/state" "siterepo/${PAIR}2/.tmp-cf7-upgrade-final" || true)
    rm -rf "siterepo/${PAIR}2/.tmp-cf7-upgrade-final"
    [ -z "$UPGRADE_DIFF" ] \
      || fail "CF7 in-place 6.0 -> 6.1.7 recapture was not byte-identical: $UPGRADE_DIFF"
    pass "CF7 state authored under exact 6.0 upgrades in place to exact 6.1.7, remains natively visible, applies cleanly, and recaptures byte-identically"
  fi
done

say "negative control: contact-form-7 5.9.8 (real wp.org release, genuinely below adapter-packages/contact-form-7/package/manifest.json's own declared min 6.0) must be REFUSED, not silently accepted"
reset_env wp1
reset_case_repositories

OUT_OF_RANGE_ARTIFACT=$(fetch_artifact contact-form-7 5.9.8 cli1)
wp1 plugin install "$OUT_OF_RANGE_ARTIFACT" --activate >/dev/null
INSTALLED_OOR=$(wp1 plugin get contact-form-7 --field=version)
[ "$INSTALLED_OOR" = "5.9.8" ] || fail "negative control: expected contact-form-7 5.9.8 installed, got $INSTALLED_OOR"

cat > "siterepo/${PAIR}1/site.duo.json" <<'EOF'
{
  "manifests": ["core", "contact-form-7"],
  "policy": {
    "options": {},
    "post_meta": {},
    "post_types": ["post", "page", "attachment", "wpcf7_contact_form"],
    "taxonomies": ["category", "post_tag"]
  },
  "spec_version": 2
}
EOF
cp site-repo.gitignore.template "siterepo/${PAIR}1/.gitignore"
"${GIT1[@]}" init -q -b main
"${GIT1[@]}" remote add origin "../origin-$PAIR.git"
"${GIT1[@]}" add -A
"${GIT1[@]}" commit -qm "policy: contact-form-7 negative-control pin, out-of-range plugin installed"
"${GIT1[@]}" push -qu origin main

wp1 duo capture --repo=/siterepo
"${GIT1[@]}" add -A
"${GIT1[@]}" commit -qm "capture: empty state, contact-form-7 5.9.8 still installed"
"${GIT1[@]}" push -q origin main

set +e
DEPLOY_OUT=$(wp1 duo deploy --repo=/siterepo 2>&1)
DEPLOY_RC=$?
set -e
[ "$DEPLOY_RC" -ne 0 ] || fail "expected deploy to refuse contact-form-7 5.9.8 as outside_version_range, but it exited 0 (got: $DEPLOY_OUT)"
grep -Eq "outside_version_range|outside the '.*' manifest's declared version_range" <<<"$DEPLOY_OUT" \
  || fail "deploy refused, but not for the expected outside_version_range reason (got: $DEPLOY_OUT)"
grep -q "contact-form-7/wp-contact-form-7.php" <<<"$DEPLOY_OUT" || fail "refusal did not name the plugin (got: $DEPLOY_OUT)"
grep -q "5.9.8" <<<"$DEPLOY_OUT" || fail "refusal did not name the actually-installed version (got: $DEPLOY_OUT)"
printf '%s\n' "$DEPLOY_OUT"
pass "confirmed: contact-form-7 5.9.8 (real, installed, genuinely below the declared min) is loudly refused by Deploy::code_mismatch() — the version_range pin is honest, not just decorative"
}
