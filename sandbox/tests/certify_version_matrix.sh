#!/usr/bin/env bash
# Certify version-boundary matrix (DUO-3223's own last remaining piece,
# unblocked by the owner ruling on artifact sourcing — issue comment
# 0ec1d2e3). No existing conformance/grind fixture installs a plugin at
# anything other than "whatever wp.org currently serves for this slug" —
# this is the first proof that a manifest's own declared version_range is
# backed by real evidence at ITS OWN edges, not just the one version every
# other fixture happens to exercise.
#
# First five real plugins: ACF, Contact Form 7, Elementor, Ninja Forms, and
# Polylang. ACF proved the artifact-sourcing mechanism itself; the others prove the matrix
# accepts genuinely different plugin content shapes rather than replaying one
# ACF fixture. The other two pinned manifests remain separately
# scope-accounted on DUO-3223.
#
# For EACH boundary version (ACF 6.0.0/6.8.7; CF7 6.0.1/6.1.6; Elementor
# 4.0.0/4.2.2; Ninja Forms 3.4.34.2/3.14.11; Polylang 3.5/3.8.6 — all real
# wp.org releases, confirmed against the plugin-info API, never invented): fresh state, install ONLY from
# a digest-verified artifact (never a bare slug install that silently pulls
# current), seed real plugin content through that plugin's own API, capture,
# round-trip deploy/apply, and byte-identical recapture. A failure at either
# boundary is exactly what this issue's own non-negotiable ("the harness
# installs exact artifacts; it never pulls latest") exists to catch before a
# manifest's claimed range is trusted.
#
# Own dedicated pair (vmatrix1 :8870 / vmatrix2 :8871 by default; agents set
# VMATRIX_PAIR and explicit ports), destroyed only after every assertion below
# passes (docs/sandbox.md's own convention) — a failing run leaves it up for
# inspection.
set -euo pipefail
cd "$(dirname "$0")/.."   # -> sandbox/

say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }

command -v jq >/dev/null || fail "jq required"

PAIR="${VMATRIX_PAIR:-vmatrix}"
PORT1="${VMATRIX_PORT1:-8870}"
PORT2="${VMATRIX_PORT2:-8871}"
export DUO_PAIR="$PAIR"
PAIR_COMPOSE=(docker compose -p "duo-$PAIR" -f pair.yml -f pair.artifacts.yml)
wp1() { "${PAIR_COMPOSE[@]}" run --rm -T cli1 wp "$@"; }
wp2() { "${PAIR_COMPOSE[@]}" run --rm -T cli2 wp "$@"; }
GIT1=(git -C "siterepo/${PAIR}1" -c user.name=duo-vmatrix1 -c user.email=vmatrix1@example.test)

. bin/fetch-artifact.sh

say "boot pair $PAIR (${PAIR}1 :$PORT1 / ${PAIR}2 :$PORT2), idempotent"
# Elementor's contract includes real frontend and generated-CSS checks, so
# this matrix must publish its already-reserved ports rather than run headless.
bash bin/pair.sh up "$PAIR" "$PORT1" "$PORT2"
pass "pair up"

seed_acf_content() { # seed_acf_content <cli-fn>
  local cli="$1"
  cat > "siterepo/${PAIR}1/.tmp-seed-acf.php" <<'PHPEOF'
<?php
if (!function_exists('acf_update_field_group')) {
    fwrite(STDERR, "ACF functions not available\n");
    exit(1);
}
acf_update_field_group([
    'key' => 'group_duo_demo', 'title' => 'Duo Demo', 'fields' => [],
    'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'post']]],
    'menu_order' => 0, 'position' => 'normal', 'style' => 'default',
    'label_placement' => 'top', 'instruction_placement' => 'label', 'active' => true,
]);
$group_posts = get_posts(['post_type' => 'acf-field-group', 'name' => 'group_duo_demo', 'posts_per_page' => 1, 'fields' => 'ids', 'post_status' => 'any']);
$group_id = $group_posts ? (int) $group_posts[0] : 0;
if (!$group_id) { fwrite(STDERR, "field group not created\n"); exit(1); }
acf_update_field([
    'key' => 'field_duo_related', 'label' => 'Related', 'name' => 'duo_related',
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
update_field('duo_related', [$target], $content_id);
echo json_encode(['group' => $group_id, 'target' => $target, 'content' => $content_id]) . "\n";
PHPEOF
  local seed_out
  seed_out=$("$cli" eval-file /siterepo/.tmp-seed-acf.php)
  rm -f "siterepo/${PAIR}1/.tmp-seed-acf.php"
  echo "acf seed: $seed_out"
}

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
$page_id = wp_insert_post([
    'post_type' => 'page', 'post_status' => 'publish',
    'post_title' => 'Version Matrix Contact', 'post_name' => 'vmatrix-contact',
    'post_content' => "<!-- wp:paragraph -->\n<p>Contact Form 7 boundary fixture.</p>\n<!-- /wp:paragraph -->\n<!-- wp:shortcode -->\n{$shortcode}\n<!-- /wp:shortcode -->",
], true);
if (is_wp_error($page_id)) { fwrite(STDERR, "page insert failed\n"); exit(1); }
echo json_encode(['form' => $form_id, 'page' => $page_id, 'shortcode' => $shortcode]) . "\n";
PHPEOF
  local seed_out
  seed_out=$("$cli" eval-file /siterepo/.tmp-seed-cf7.php)
  rm -f "siterepo/${PAIR}1/.tmp-seed-cf7.php"
  echo "cf7 seed: $seed_out"
}

seed_elementor_content() {
  # Reuse the standalone conformance fixture verbatim: it creates real media,
  # saves a document through Elementor's own Document::save() pipeline, and
  # renders it once so Elementor's lazy derived keys are exercised before
  # capture. Keeping one fixture prevents the boundary matrix from drifting
  # into a weaker hand-written approximation.
  wp_conf1() { wp1 "$@"; }
  local CONF_REPO1="siterepo/${PAIR}1"
  local CONF1_PORT="$PORT1"
  local COMPOSE="docker compose -p duo-$PAIR -f pair.yml -f pair.artifacts.yml"
  . conformance/seeds/elementor.sh
  unset -f wp_conf1
}

check_elementor_content() {
  wp_conf2() { wp2 "$@"; }
  local CONF1_PORT="$PORT1"
  local CONF2_PORT="$PORT2"
  . conformance/checks/elementor.sh
  unset -f wp_conf2
}

seed_ninja_forms_content() {
  # Reuse the standalone conformance seed verbatim. It imports Ninja Forms'
  # own bundled Job Application template through the plugin's real admin
  # import process, yielding a typed-table graph of 1 form, 23 fields, and
  # 3 actions plus a page containing the real block.
  wp_conf1() { wp1 "$@"; }
  local CONF_REPO1="siterepo/${PAIR}1"
  local COMPOSE="docker compose -p duo-$PAIR -f pair.yml -f pair.artifacts.yml"
  . conformance/seeds/ninja-forms.sh
  unset -f wp_conf1
}

postdeploy_ninja_forms_content() {
  # Deployment activates the plugin and Ninja Forms creates its own sample
  # form on the target. Reuse the standalone hook that removes only that
  # environment-local activation side effect before apply.
  wp_conf2() { wp2 "$@"; }
  local CONF_REPO2="siterepo/${PAIR}2"
  local COMPOSE="docker compose -p duo-$PAIR -f pair.yml -f pair.artifacts.yml"
  . conformance/postdeploy/ninja-forms.sh
  unset -f wp_conf2
}

check_ninja_forms_boundary_content() {
  local front form_id api_out
  front=$(curl -fsSL "http://localhost:${PORT2}/conformance-careers/") \
    || fail "side 2 conformance-careers page did not return 200"
  [ "${#front}" -ge 1000 ] \
    || fail "side 2 conformance-careers response was suspiciously short (${#front} bytes)"
  if grep -qiE 'fatal error|uncaught' <<<"$front"; then
    fail "side 2 rendered careers page contains a PHP fatal error marker"
  fi
  grep -qE 'Job Application|nf-form-' <<<"$front" \
    || fail "side 2 rendered careers page has no Ninja Forms markup"
  grep -q 'First Name' <<<"$front" \
    || fail "side 2 rendered form is missing its own field content"

  form_id=$(wp2 db query "SELECT id FROM wp_nf3_forms WHERE title='Job Application'" --skip-column-names | tr -d '[:space:]')
  [ -n "$form_id" ] || fail "side 2 has no Job Application row in nf3_forms"
  api_out=$(wp2 eval "
\$form = Ninja_Forms()->form($form_id)->get();
echo \$form->get_setting('title') . '|' . count(Ninja_Forms()->form($form_id)->get_fields()) . '|' . count(Ninja_Forms()->form($form_id)->get_actions());
")
  [ "$api_out" = "Job Application|23|3" ] \
    || fail "side 2 Ninja Forms model API mismatch (got: $api_out)"
  pass "side 2 renders the real Job Application and Ninja Forms' model API resolves 23 fields and 3 actions"
}

seed_polylang_content() {
  # Reuse the standalone fixture verbatim: two languages, translated post
  # and category pairs, plus deleted fillers that force source/target ids
  # apart so a stale-id implementation cannot pass by coincidence.
  wp_conf1() { wp1 "$@"; }
  local CONF_REPO1="siterepo/${PAIR}1"
  local COMPOSE="docker compose -p duo-$PAIR -f pair.yml -f pair.artifacts.yml"
  . conformance/seeds/polylang.sh
  unset -f wp_conf1
}

check_polylang_content() {
  # The standalone check uses Polylang's public lookup APIs, raw serialized
  # relationship bytes, a target recapture, and a real frontend request.
  wp_conf1() { wp1 "$@"; }
  wp_conf2() { wp2 "$@"; }
  local CONF_REPO2="siterepo/${PAIR}2"
  local CONF1_PORT="$PORT1"
  local CONF2_PORT="$PORT2"
  . conformance/checks/polylang.sh
  unset -f wp_conf1 wp_conf2
}

reset_env() { # reset_env <cli-fn> — content + identity only, keeps WordPress
  # core/theme installed and the site "installed" (unlike `pair.sh reset`,
  # which drops the database entirely and leaves the site UNINSTALLED until
  # `pair.sh up` runs again — the right tool between SCRIPT runs, the wrong
  # one for a same-run per-boundary-version loop iteration, confirmed the
  # hard way on this script's own first live attempt: "Error: The site you
  # have requested is not installed"). Matches grind_r1b_shop.sh/grind_r3b_
  # events.sh's own reset_env_state() convention, adapted for a single side.
  local cli="$1"
  "$cli" site empty --yes >/dev/null
  local plugin
  for plugin in advanced-custom-fields contact-form-7 elementor ninja-forms polylang; do
    "$cli" plugin deactivate "$plugin" >/dev/null 2>&1 || true
    "$cli" plugin delete "$plugin" >/dev/null 2>&1 || true
  done
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
  # Polylang's terms are removed by site empty while the plugin is active,
  # but authored/runtime options and language-cache transients deliberately
  # survive uninstall. Delete them so each boundary starts from activation.
  "$cli" db query "
    DELETE FROM wp_options
      WHERE option_name = 'polylang'
         OR option_name LIKE 'polylang_%'
         OR option_name LIKE 'widget_polylang%'
         OR option_name LIKE '%pll_languages_list%'
         OR option_name LIKE '%pll_activation_redirect%';
  " >/dev/null
  "$cli" db query "TRUNCATE TABLE wp_duo_map" >/dev/null 2>&1 || true
  "$cli" db query "TRUNCATE TABLE wp_duo_state" >/dev/null 2>&1 || true
  "$cli" db query "TRUNCATE TABLE wp_duo_kv" >/dev/null 2>&1 || true
  "$cli" db query "TRUNCATE TABLE wp_duo_journal" >/dev/null 2>&1 || true
}

for ACF_VERSION in 6.0.0 6.8.7; do
  say "boundary: acf $ACF_VERSION"

  reset_env wp1
  reset_env wp2
  rm -rf "siterepo/origin-$PAIR.git" "siterepo/${PAIR}1" "siterepo/${PAIR}2"
  git init --bare -b main "siterepo/origin-$PAIR.git" >/dev/null
  mkdir -p "siterepo/${PAIR}1"

  say "fetch + verify acf $ACF_VERSION (never a bare slug install — always a digest-checked artifact)"
  ARTIFACT_1=$(fetch_artifact advanced-custom-fields "$ACF_VERSION" cli1)
  ARTIFACT_2=$(fetch_artifact advanced-custom-fields "$ACF_VERSION" cli2)
  pass "verified sha256-pinned artifact resolved for both sides: $ARTIFACT_1"

  wp1 plugin install "$ARTIFACT_1" --activate >/dev/null
  INSTALLED_1=$(wp1 plugin get advanced-custom-fields --field=version)
  [ "$INSTALLED_1" = "$ACF_VERSION" ] || fail "side 1 installed version mismatch: expected $ACF_VERSION, got $INSTALLED_1"
  pass "side 1: acf $ACF_VERSION installed from verified artifact, active"

  cat > "siterepo/${PAIR}1/site.duo.json" <<EOF
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

  wp1 duo capture --repo=/siterepo
  pass "captured on side 1 (acf $ACF_VERSION)"

  wp1 duo lint --repo=/siterepo
  pass "lint: 0 findings"

  "${GIT1[@]}" add -A
  "${GIT1[@]}" commit -qm "capture: acf $ACF_VERSION content"
  "${GIT1[@]}" push -q origin main

  git clone -q "siterepo/origin-$PAIR.git" "siterepo/${PAIR}2"
  wp2 plugin install "$ARTIFACT_2" >/dev/null
  INSTALLED_2=$(wp2 plugin get advanced-custom-fields --field=version)
  [ "$INSTALLED_2" = "$ACF_VERSION" ] || fail "side 2 installed version mismatch: expected $ACF_VERSION, got $INSTALLED_2"

  wp2 duo deploy --repo=/siterepo
  REV=$(git -C "siterepo/${PAIR}2" rev-parse HEAD)
  # --adopt-by-slug=terms,posts: WordPress core's own defaults (the
  # "Uncategorized" category always, a "Hello World" post/"Sample Page" on
  # some installs) survive `site empty --yes` and collide by slug with the
  # captured state's own entities of the same name — the same known,
  # expected pattern every other grind/certify pair script in this repo
  # already handles the identical way (grind_r3b_events.sh, grind_r1b_shop.sh).
  wp2 duo apply --repo=/siterepo --adopt-by-slug=terms,posts --default-author=admin --revision="$REV" | tee /tmp/vmatrix_apply.txt
  grep -q 'canary clean' /tmp/vmatrix_apply.txt || fail "apply canary not clean at acf $ACF_VERSION"
  pass "deploy + apply succeeded on side 2 (acf $ACF_VERSION, canary clean)"

  wp2 duo capture --repo=/siterepo --out="/siterepo/.tmp-final"
  DIFF_OUT=$(diff -rq "siterepo/${PAIR}1/state" "siterepo/${PAIR}2/.tmp-final" || true)
  rm -rf "siterepo/${PAIR}2/.tmp-final"
  [ -z "$DIFF_OUT" ] || fail "byte-identity broken at acf $ACF_VERSION: $DIFF_OUT"
  pass "byte-identical recapture at acf $ACF_VERSION — the manifest's own declared version_range boundary is proven, not just its currently-installed version"
done

for NINJA_VERSION in 3.4.34.2 3.14.11; do
  say "boundary: ninja-forms $NINJA_VERSION"

  reset_env wp1
  reset_env wp2
  rm -rf "siterepo/origin-$PAIR.git" "siterepo/${PAIR}1" "siterepo/${PAIR}2"
  git init --bare -b main "siterepo/origin-$PAIR.git" >/dev/null
  mkdir -p "siterepo/${PAIR}1"

  say "fetch + verify ninja-forms $NINJA_VERSION (never a bare slug install — always a digest-checked artifact)"
  ARTIFACT_1=$(fetch_artifact ninja-forms "$NINJA_VERSION" cli1)
  ARTIFACT_2=$(fetch_artifact ninja-forms "$NINJA_VERSION" cli2)
  pass "verified sha256-pinned artifact resolved for both sides: $ARTIFACT_1"

  wp1 plugin install "$ARTIFACT_1" --activate >/dev/null
  INSTALLED_1=$(wp1 plugin get ninja-forms --field=version)
  [ "$INSTALLED_1" = "$NINJA_VERSION" ] || fail "side 1 installed version mismatch: expected $NINJA_VERSION, got $INSTALLED_1"
  pass "side 1: ninja-forms $NINJA_VERSION installed from verified artifact, active"

  cat > "siterepo/${PAIR}1/site.duo.json" <<EOF
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
  wp1 duo capture --repo=/siterepo
  pass "captured on side 1 (ninja-forms $NINJA_VERSION)"
  wp1 duo lint --repo=/siterepo
  pass "lint: 0 findings"

  "${GIT1[@]}" add -A
  "${GIT1[@]}" commit -qm "capture: ninja-forms $NINJA_VERSION content"
  "${GIT1[@]}" push -q origin main

  git clone -q "siterepo/origin-$PAIR.git" "siterepo/${PAIR}2"
  wp2 plugin install "$ARTIFACT_2" >/dev/null
  INSTALLED_2=$(wp2 plugin get ninja-forms --field=version)
  [ "$INSTALLED_2" = "$NINJA_VERSION" ] || fail "side 2 installed version mismatch: expected $NINJA_VERSION, got $INSTALLED_2"

  wp2 duo deploy --repo=/siterepo
  postdeploy_ninja_forms_content
  REV=$(git -C "siterepo/${PAIR}2" rev-parse HEAD)
  wp2 duo apply --repo=/siterepo --adopt-by-slug=terms,posts --default-author=admin --revision="$REV" | tee /tmp/vmatrix_apply.txt
  grep -q 'canary clean' /tmp/vmatrix_apply.txt || fail "apply canary not clean at ninja-forms $NINJA_VERSION"
  pass "deploy + apply succeeded on side 2 (ninja-forms $NINJA_VERSION, canary clean)"

  check_ninja_forms_boundary_content

  wp2 duo capture --repo=/siterepo --out="/siterepo/.tmp-final"
  DIFF_OUT=$(diff -rq "siterepo/${PAIR}1/state" "siterepo/${PAIR}2/.tmp-final" || true)
  rm -rf "siterepo/${PAIR}2/.tmp-final"
  [ -z "$DIFF_OUT" ] || fail "byte-identity broken at ninja-forms $NINJA_VERSION: $DIFF_OUT"
  pass "byte-identical recapture at ninja-forms $NINJA_VERSION — the manifest's own declared version_range boundary is proven, not just its currently-installed version"
done

for ELEMENTOR_VERSION in 4.0.0 4.2.2; do
  say "boundary: elementor $ELEMENTOR_VERSION"

  reset_env wp1
  reset_env wp2
  rm -rf "siterepo/origin-$PAIR.git" "siterepo/${PAIR}1" "siterepo/${PAIR}2"
  git init --bare -b main "siterepo/origin-$PAIR.git" >/dev/null
  mkdir -p "siterepo/${PAIR}1"

  say "fetch + verify elementor $ELEMENTOR_VERSION (never a bare slug install — always a digest-checked artifact)"
  ARTIFACT_1=$(fetch_artifact elementor "$ELEMENTOR_VERSION" cli1)
  ARTIFACT_2=$(fetch_artifact elementor "$ELEMENTOR_VERSION" cli2)
  pass "verified sha256-pinned artifact resolved for both sides: $ARTIFACT_1"

  wp1 plugin install "$ARTIFACT_1" --activate >/dev/null
  INSTALLED_1=$(wp1 plugin get elementor --field=version)
  [ "$INSTALLED_1" = "$ELEMENTOR_VERSION" ] || fail "side 1 installed version mismatch: expected $ELEMENTOR_VERSION, got $INSTALLED_1"
  pass "side 1: elementor $ELEMENTOR_VERSION installed from verified artifact, active"

  cat > "siterepo/${PAIR}1/site.duo.json" <<EOF
{
  "manifests": ["core", "elementor"],
  "policy": {
    "options": {},
    "post_meta": {},
    "post_types": ["post", "page", "attachment", "elementor_library"],
    "taxonomies": ["category", "post_tag"]
  },
  "spec_version": 2
}
EOF
  cp site-repo.gitignore.template "siterepo/${PAIR}1/.gitignore"
  "${GIT1[@]}" init -q -b main
  "${GIT1[@]}" remote add origin "../origin-$PAIR.git"
  "${GIT1[@]}" add -A
  "${GIT1[@]}" commit -qm "policy: elementor $ELEMENTOR_VERSION version-boundary certification"
  "${GIT1[@]}" push -qu origin main

  seed_elementor_content

  wp1 duo capture --repo=/siterepo
  pass "captured on side 1 (elementor $ELEMENTOR_VERSION)"

  wp1 duo lint --repo=/siterepo
  pass "lint: 0 findings"

  "${GIT1[@]}" add -A
  "${GIT1[@]}" commit -qm "capture: elementor $ELEMENTOR_VERSION content"
  "${GIT1[@]}" push -q origin main

  git clone -q "siterepo/origin-$PAIR.git" "siterepo/${PAIR}2"
  wp2 plugin install "$ARTIFACT_2" >/dev/null
  INSTALLED_2=$(wp2 plugin get elementor --field=version)
  [ "$INSTALLED_2" = "$ELEMENTOR_VERSION" ] || fail "side 2 installed version mismatch: expected $ELEMENTOR_VERSION, got $INSTALLED_2"

  wp2 duo deploy --repo=/siterepo
  REV=$(git -C "siterepo/${PAIR}2" rev-parse HEAD)
  wp2 duo apply --repo=/siterepo --adopt-by-slug=terms,posts --default-author=admin --revision="$REV" | tee /tmp/vmatrix_apply.txt
  grep -q 'canary clean' /tmp/vmatrix_apply.txt || fail "apply canary not clean at elementor $ELEMENTOR_VERSION"
  pass "deploy + apply succeeded on side 2 (elementor $ELEMENTOR_VERSION, canary clean)"

  check_elementor_content

  wp2 duo capture --repo=/siterepo --out="/siterepo/.tmp-final"
  DIFF_OUT=$(diff -rq "siterepo/${PAIR}1/state" "siterepo/${PAIR}2/.tmp-final" || true)
  rm -rf "siterepo/${PAIR}2/.tmp-final"
  [ -z "$DIFF_OUT" ] || fail "byte-identity broken at elementor $ELEMENTOR_VERSION: $DIFF_OUT"
  pass "byte-identical recapture at elementor $ELEMENTOR_VERSION — the manifest's own declared version_range boundary is proven, not just its currently-installed version"
done

for CF7_VERSION in 6.0.1 6.1.6; do
  say "boundary: contact-form-7 $CF7_VERSION"

  reset_env wp1
  reset_env wp2
  rm -rf "siterepo/origin-$PAIR.git" "siterepo/${PAIR}1" "siterepo/${PAIR}2"
  git init --bare -b main "siterepo/origin-$PAIR.git" >/dev/null
  mkdir -p "siterepo/${PAIR}1"

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

  wp1 duo capture --repo=/siterepo
  pass "captured on side 1 (contact-form-7 $CF7_VERSION)"

  wp1 duo lint --repo=/siterepo
  pass "lint: 0 findings"

  "${GIT1[@]}" add -A
  "${GIT1[@]}" commit -qm "capture: contact-form-7 $CF7_VERSION content"
  "${GIT1[@]}" push -q origin main

  git clone -q "siterepo/origin-$PAIR.git" "siterepo/${PAIR}2"
  wp2 plugin install "$ARTIFACT_2" >/dev/null
  INSTALLED_2=$(wp2 plugin get contact-form-7 --field=version)
  [ "$INSTALLED_2" = "$CF7_VERSION" ] || fail "side 2 installed version mismatch: expected $CF7_VERSION, got $INSTALLED_2"

  wp2 duo deploy --repo=/siterepo
  REV=$(git -C "siterepo/${PAIR}2" rev-parse HEAD)
  wp2 duo apply --repo=/siterepo --adopt-by-slug=terms,posts --default-author=admin --revision="$REV" | tee /tmp/vmatrix_apply.txt
  grep -q 'canary clean' /tmp/vmatrix_apply.txt || fail "apply canary not clean at contact-form-7 $CF7_VERSION"
  pass "deploy + apply succeeded on side 2 (contact-form-7 $CF7_VERSION, canary clean)"

  wp2 duo capture --repo=/siterepo --out="/siterepo/.tmp-final"
  DIFF_OUT=$(diff -rq "siterepo/${PAIR}1/state" "siterepo/${PAIR}2/.tmp-final" || true)
  rm -rf "siterepo/${PAIR}2/.tmp-final"
  [ -z "$DIFF_OUT" ] || fail "byte-identity broken at contact-form-7 $CF7_VERSION: $DIFF_OUT"
  pass "byte-identical recapture at contact-form-7 $CF7_VERSION — the manifest's own declared version_range boundary is proven, not just its currently-installed version"
done

for POLYLANG_VERSION in 3.5 3.8.6; do
  say "boundary: polylang $POLYLANG_VERSION"

  reset_env wp1
  reset_env wp2
  rm -rf "siterepo/origin-$PAIR.git" "siterepo/${PAIR}1" "siterepo/${PAIR}2"
  git init --bare -b main "siterepo/origin-$PAIR.git" >/dev/null
  mkdir -p "siterepo/${PAIR}1"

  say "fetch + verify polylang $POLYLANG_VERSION (never a bare slug install — always a digest-checked artifact)"
  ARTIFACT_1=$(fetch_artifact polylang "$POLYLANG_VERSION" cli1)
  ARTIFACT_2=$(fetch_artifact polylang "$POLYLANG_VERSION" cli2)
  pass "verified sha256-pinned artifact resolved for both sides: $ARTIFACT_1"

  wp1 plugin install "$ARTIFACT_1" --activate >/dev/null
  INSTALLED_1=$(wp1 plugin get polylang --field=version)
  [ "$INSTALLED_1" = "$POLYLANG_VERSION" ] || fail "side 1 installed version mismatch: expected $POLYLANG_VERSION, got $INSTALLED_1"
  pass "side 1: polylang $POLYLANG_VERSION installed from verified artifact, active"

  cat > "siterepo/${PAIR}1/site.duo.json" <<EOF
{
  "manifests": ["core", "polylang"],
  "policy": {
    "options": {},
    "post_meta": {},
    "post_types": ["post", "page", "attachment"],
    "taxonomies": ["category", "post_tag", "language", "term_language", "term_translations", "post_translations"]
  },
  "spec_version": 2
}
EOF
  cp site-repo.gitignore.template "siterepo/${PAIR}1/.gitignore"
  "${GIT1[@]}" init -q -b main
  "${GIT1[@]}" remote add origin "../origin-$PAIR.git"
  "${GIT1[@]}" add -A
  "${GIT1[@]}" commit -qm "policy: polylang $POLYLANG_VERSION version-boundary certification"
  "${GIT1[@]}" push -qu origin main

  seed_polylang_content
  wp1 duo capture --repo=/siterepo
  pass "captured on side 1 (polylang $POLYLANG_VERSION)"
  wp1 duo lint --repo=/siterepo
  pass "lint: 0 findings"

  "${GIT1[@]}" add -A
  "${GIT1[@]}" commit -qm "capture: polylang $POLYLANG_VERSION content"
  "${GIT1[@]}" push -q origin main

  git clone -q "siterepo/origin-$PAIR.git" "siterepo/${PAIR}2"
  wp2 plugin install "$ARTIFACT_2" >/dev/null
  INSTALLED_2=$(wp2 plugin get polylang --field=version)
  [ "$INSTALLED_2" = "$POLYLANG_VERSION" ] || fail "side 2 installed version mismatch: expected $POLYLANG_VERSION, got $INSTALLED_2"

  wp2 duo deploy --repo=/siterepo
  REV=$(git -C "siterepo/${PAIR}2" rev-parse HEAD)
  wp2 duo apply --repo=/siterepo --adopt-by-slug=terms,posts --default-author=admin --revision="$REV" | tee /tmp/vmatrix_apply.txt
  grep -q 'canary clean' /tmp/vmatrix_apply.txt || fail "apply canary not clean at polylang $POLYLANG_VERSION"
  pass "deploy + apply succeeded on side 2 (polylang $POLYLANG_VERSION, canary clean)"

  check_polylang_content

  wp2 duo capture --repo=/siterepo --out="/siterepo/.tmp-final"
  DIFF_OUT=$(diff -rq "siterepo/${PAIR}1/state" "siterepo/${PAIR}2/.tmp-final" || true)
  rm -rf "siterepo/${PAIR}2/.tmp-final"
  [ -z "$DIFF_OUT" ] || fail "byte-identity broken at polylang $POLYLANG_VERSION: $DIFF_OUT"
  pass "byte-identical recapture at polylang $POLYLANG_VERSION — the manifest's own declared version_range boundary is proven, not just its currently-installed version"
done

# Team-lead's own requirement: the loop above proves every IN-RANGE boundary
# certifies — it does not by itself prove the pin is honest, i.e. that an
# OUT-OF-range version is actually refused rather than silently accepted.
# Both properties together are what "the matrix proves the pins honest, not
# just the plugin functional" means. Deploy::code_mismatch()
# (agent/src/Deploy.php) is the real enforcement: it reads the ACTUALLY-
# installed plugin version via WordPress's own get_plugins(), compares it
# against the manifest's declared version_range, and — triggered by both
# `wp duo deploy` and `wp duo apply` — throws an 'outside_version_range'
# finding naming the plugin, its installed version, and the declared range,
# unless --force-code-mismatch is passed. This only needs `duo deploy`
# (code-only reconciliation), not a full capture/apply round-trip — the
# refusal fires before any target mutation is attempted.
say "negative control: acf 5.12.6 (real wp.org release, genuinely below manifests/acf.json's own declared min 6.0.0) must be REFUSED, not silently accepted"
reset_env wp1
rm -rf "siterepo/origin-$PAIR.git" "siterepo/${PAIR}1" "siterepo/${PAIR}2"
git init --bare -b main "siterepo/origin-$PAIR.git" >/dev/null
mkdir -p "siterepo/${PAIR}1"

OUT_OF_RANGE_ARTIFACT=$(fetch_artifact advanced-custom-fields 5.12.6 cli1)
wp1 plugin install "$OUT_OF_RANGE_ARTIFACT" --activate >/dev/null
INSTALLED_OOR=$(wp1 plugin get advanced-custom-fields --field=version)
[ "$INSTALLED_OOR" = "5.12.6" ] || fail "negative control: expected acf 5.12.6 installed, got $INSTALLED_OOR"

cat > "siterepo/${PAIR}1/site.duo.json" <<'EOF'
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

# `duo deploy` compiles the repository before it ever reaches code_mismatch()
# and refuses loudly if state/ doesn't exist yet ([state_directory_missing])
# — found live on this section's own first attempt. A real capture (harmless
# with the out-of-range plugin installed: capture itself never checks
# version_range, only deploy/apply do — confirmed by direct read of
# Deploy::code_mismatch()'s own call sites) produces a valid state/ tree
# cheaply, with zero ACF-specific content since nothing has been seeded.
wp1 duo capture --repo=/siterepo
"${GIT1[@]}" add -A
"${GIT1[@]}" commit -qm "capture: empty state, acf 5.12.6 still installed"
"${GIT1[@]}" push -q origin main

set +e
DEPLOY_OUT=$(wp1 duo deploy --repo=/siterepo 2>&1)
DEPLOY_RC=$?
set -e
[ "$DEPLOY_RC" -ne 0 ] || fail "expected deploy to refuse acf 5.12.6 as outside_version_range, but it exited 0 (got: $DEPLOY_OUT)"
grep -Eq "outside_version_range|outside the '.*' manifest's declared version_range" <<<"$DEPLOY_OUT" \
  || fail "deploy refused, but not for the expected outside_version_range reason (got: $DEPLOY_OUT)"
grep -q "advanced-custom-fields/acf.php" <<<"$DEPLOY_OUT" || fail "refusal did not name the plugin (got: $DEPLOY_OUT)"
grep -q "5.12.6" <<<"$DEPLOY_OUT" || fail "refusal did not name the actually-installed version (got: $DEPLOY_OUT)"
printf '%s\n' "$DEPLOY_OUT"
pass "confirmed: acf 5.12.6 (real, installed, genuinely below the declared min) is loudly refused by Deploy::code_mismatch() — the version_range pin is honest, not just decorative"

say "negative control: contact-form-7 5.9.8 (real wp.org release, genuinely below manifests/contact-form-7.json's own declared min 6.0.0) must be REFUSED, not silently accepted"
reset_env wp1
rm -rf "siterepo/origin-$PAIR.git" "siterepo/${PAIR}1" "siterepo/${PAIR}2"
git init --bare -b main "siterepo/origin-$PAIR.git" >/dev/null
mkdir -p "siterepo/${PAIR}1"

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

say "negative control: elementor 3.35.9 (real wp.org release, genuinely below manifests/elementor.json's own declared min 4.0.0) must be REFUSED, not silently accepted"
reset_env wp1
rm -rf "siterepo/origin-$PAIR.git" "siterepo/${PAIR}1" "siterepo/${PAIR}2"
git init --bare -b main "siterepo/origin-$PAIR.git" >/dev/null
mkdir -p "siterepo/${PAIR}1"

OUT_OF_RANGE_ARTIFACT=$(fetch_artifact elementor 3.35.9 cli1)
wp1 plugin install "$OUT_OF_RANGE_ARTIFACT" --activate >/dev/null
INSTALLED_OOR=$(wp1 plugin get elementor --field=version)
[ "$INSTALLED_OOR" = "3.35.9" ] || fail "negative control: expected elementor 3.35.9 installed, got $INSTALLED_OOR"

cat > "siterepo/${PAIR}1/site.duo.json" <<'EOF'
{
  "manifests": ["core", "elementor"],
  "policy": {
    "options": {},
    "post_meta": {},
    "post_types": ["post", "page", "attachment", "elementor_library"],
    "taxonomies": ["category", "post_tag"]
  },
  "spec_version": 2
}
EOF
cp site-repo.gitignore.template "siterepo/${PAIR}1/.gitignore"
"${GIT1[@]}" init -q -b main
"${GIT1[@]}" remote add origin "../origin-$PAIR.git"
"${GIT1[@]}" add -A
"${GIT1[@]}" commit -qm "policy: elementor negative-control pin, out-of-range plugin installed"
"${GIT1[@]}" push -qu origin main

wp1 duo capture --repo=/siterepo
"${GIT1[@]}" add -A
"${GIT1[@]}" commit -qm "capture: empty state, elementor 3.35.9 still installed"
"${GIT1[@]}" push -q origin main

set +e
DEPLOY_OUT=$(wp1 duo deploy --repo=/siterepo 2>&1)
DEPLOY_RC=$?
set -e
[ "$DEPLOY_RC" -ne 0 ] || fail "expected deploy to refuse elementor 3.35.9 as outside_version_range, but it exited 0 (got: $DEPLOY_OUT)"
grep -Eq "outside_version_range|outside the '.*' manifest's declared version_range" <<<"$DEPLOY_OUT" \
  || fail "deploy refused, but not for the expected outside_version_range reason (got: $DEPLOY_OUT)"
grep -q "elementor/elementor.php" <<<"$DEPLOY_OUT" || fail "refusal did not name the plugin (got: $DEPLOY_OUT)"
grep -q "3.35.9" <<<"$DEPLOY_OUT" || fail "refusal did not name the actually-installed version (got: $DEPLOY_OUT)"
printf '%s\n' "$DEPLOY_OUT"
pass "confirmed: elementor 3.35.9 (real, installed, genuinely below the declared min) is loudly refused by Deploy::code_mismatch() — the version_range pin is honest, not just decorative"

say "negative control: ninja-forms 3.3.21.4 (real wp.org release, genuinely below manifests/ninja-forms.json's corrected min 3.4.34.2) must be REFUSED, not silently accepted"
reset_env wp1
rm -rf "siterepo/origin-$PAIR.git" "siterepo/${PAIR}1" "siterepo/${PAIR}2"
git init --bare -b main "siterepo/origin-$PAIR.git" >/dev/null
mkdir -p "siterepo/${PAIR}1"

# Build valid canonical state with the certified upper-bound artifact first.
# The below-range release fatals during activation on the repository's PHP
# runtime, so asking it to create canonical content would test an unrelated
# runtime incompatibility rather than the deploy-time version gate this
# negative control owns.
IN_RANGE_ARTIFACT=$(fetch_artifact ninja-forms 3.14.11 cli1)
wp1 plugin install "$IN_RANGE_ARTIFACT" --activate >/dev/null
cat > "siterepo/${PAIR}1/site.duo.json" <<'EOF'
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
wp1 duo capture --repo=/siterepo
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
DEPLOY_OUT=$(wp1 duo deploy --repo=/siterepo 2>&1)
DEPLOY_RC=$?
set -e
[ "$DEPLOY_RC" -ne 0 ] || fail "expected deploy to refuse ninja-forms 3.3.21.4 as outside_version_range, but it exited 0 (got: $DEPLOY_OUT)"
grep -Eq "outside_version_range|outside the '.*' manifest's declared version_range" <<<"$DEPLOY_OUT" \
  || fail "deploy refused, but not for the expected outside_version_range reason (got: $DEPLOY_OUT)"
grep -q "ninja-forms/ninja-forms.php" <<<"$DEPLOY_OUT" || fail "refusal did not name the plugin (got: $DEPLOY_OUT)"
grep -q "3.3.21.4" <<<"$DEPLOY_OUT" || fail "refusal did not name the actually-installed version (got: $DEPLOY_OUT)"
printf '%s\n' "$DEPLOY_OUT"
pass "confirmed: ninja-forms 3.3.21.4 (real, installed, genuinely below the corrected min) is loudly refused by Deploy::code_mismatch() — the version_range pin is honest, not just decorative"

say "negative control: polylang 3.4.5 (real wp.org release, genuinely below manifests/polylang.json's corrected min 3.5) must be REFUSED, not silently accepted"
reset_env wp1
rm -rf "siterepo/origin-$PAIR.git" "siterepo/${PAIR}1" "siterepo/${PAIR}2"
git init --bare -b main "siterepo/origin-$PAIR.git" >/dev/null
mkdir -p "siterepo/${PAIR}1"

# Build valid canonical state at the certified upper boundary, then replace
# only the installed plugin bytes. The refusal therefore proves the version
# gate against a real Polylang state tree rather than an empty repository.
IN_RANGE_ARTIFACT=$(fetch_artifact polylang 3.8.6 cli1)
wp1 plugin install "$IN_RANGE_ARTIFACT" --activate >/dev/null
cat > "siterepo/${PAIR}1/site.duo.json" <<'EOF'
{
  "manifests": ["core", "polylang"],
  "policy": {
    "options": {},
    "post_meta": {},
    "post_types": ["post", "page", "attachment"],
    "taxonomies": ["category", "post_tag", "language", "term_language", "term_translations", "post_translations"]
  },
  "spec_version": 2
}
EOF
cp site-repo.gitignore.template "siterepo/${PAIR}1/.gitignore"
"${GIT1[@]}" init -q -b main
"${GIT1[@]}" remote add origin "../origin-$PAIR.git"
"${GIT1[@]}" add -A
"${GIT1[@]}" commit -qm "policy: polylang negative-control pin"
"${GIT1[@]}" push -qu origin main
seed_polylang_content
wp1 duo capture --repo=/siterepo
"${GIT1[@]}" add -A
"${GIT1[@]}" commit -qm "capture: valid Polylang state for negative control"
"${GIT1[@]}" push -q origin main

wp1 plugin deactivate polylang >/dev/null
wp1 plugin delete polylang >/dev/null
OUT_OF_RANGE_ARTIFACT=$(fetch_artifact polylang 3.4.5 cli1)
wp1 plugin install "$OUT_OF_RANGE_ARTIFACT" >/dev/null
INSTALLED_OOR=$(wp1 plugin get polylang --field=version)
[ "$INSTALLED_OOR" = "3.4.5" ] || fail "negative control: expected polylang 3.4.5 installed, got $INSTALLED_OOR"

set +e
DEPLOY_OUT=$(wp1 duo deploy --repo=/siterepo 2>&1)
DEPLOY_RC=$?
set -e
[ "$DEPLOY_RC" -ne 0 ] || fail "expected deploy to refuse polylang 3.4.5 as outside_version_range, but it exited 0 (got: $DEPLOY_OUT)"
grep -Eq "outside_version_range|outside the '.*' manifest's declared version_range" <<<"$DEPLOY_OUT" \
  || fail "deploy refused, but not for the expected outside_version_range reason (got: $DEPLOY_OUT)"
grep -q "polylang/polylang.php" <<<"$DEPLOY_OUT" || fail "refusal did not name the plugin (got: $DEPLOY_OUT)"
grep -q "3.4.5" <<<"$DEPLOY_OUT" || fail "refusal did not name the actually-installed version (got: $DEPLOY_OUT)"
printf '%s\n' "$DEPLOY_OUT"
pass "confirmed: polylang 3.4.5 (real, installed, genuinely below the corrected 3.5 min) is loudly refused by Deploy::code_mismatch() — the version_range pin is honest, not just decorative"

say "cleanup"
bash bin/pair.sh destroy "$PAIR"
pass "destroyed $PAIR (every assertion above passed)"

printf '\n\033[1;32m✔ CERTIFY_VERSION_MATRIX PASSED\033[0m\n'
