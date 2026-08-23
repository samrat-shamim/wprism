#!/usr/bin/env bash
# Certify version-boundary matrix (DUO-3223's own last remaining piece,
# unblocked by the owner ruling on artifact sourcing — issue comment
# 0ec1d2e3). No existing conformance/grind fixture installs a plugin at
# anything other than "whatever wp.org currently serves for this slug" —
# this is the first proof that a manifest's own declared version_range is
# backed by real evidence at ITS OWN edges, not just the one version every
# other fixture happens to exercise.
#
# First thirteen real plugins: ACF, Advanced Editor Tools, Classic Editor,
# Code Snippets, Contact Form 7, Elementor, Ninja Forms, Paid Memberships Pro, Polylang,
# WooCommerce, WPS Hide Login, Yoast Duplicate Post, and Yoast SEO. ACF proved the artifact-sourcing
# mechanism itself; the others
# prove the matrix accepts genuinely different
# plugin content shapes rather than replaying one ACF fixture. This closes the
# last pinned-manifest boundary that DUO-3223 had explicitly scope-accounted.
#
# For EACH boundary version (ACF 6.0.0/6.8.7; Advanced Editor Tools 5.9.2;
# Classic Editor 1.7.0; Code Snippets 3.9.5/3.9.6; CF7 6.0/6.1.7; Elementor 4.0.0/4.2.3; Ninja Forms
# 3.4.34.2/3.14.11; PMPro 3.8.2/3.8.3 (with adjacent official-tag refusals);
# Polylang 3.5/3.8.6;
# WooCommerce 11.0.0 (the only stable in-range 11.x release); Yoast SEO
# 28.0/28.3 — all real
# wp.org releases except PMPro's official upstream GitHub tags, never invented): fresh state, install ONLY from
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
cd "$(dirname "$0")/../.."   # -> sandbox/

say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }

# The conformance seeds/postdeploy hooks this harness sources call the shared
# premise/answer assertion helpers (DUO-3381/DUO-3391); they live in one
# fragment precisely so this second harness cannot strand them (DUO-3408 —
# leg 12 died `require_fixture_state: command not found` on pristine main).
. conformance/asserts.sh

command -v jq >/dev/null || fail "jq required"

PAIR="${VMATRIX_PAIR:-vmatrix}"
PORT1="${VMATRIX_PORT1:-8870}"
PORT2="${VMATRIX_PORT2:-8871}"
# One invocation is one subject. Repository-wide aggregation would recreate
# the coupling that subject-scoped certification removes.
VMATRIX_MANIFEST="${VMATRIX_MANIFEST:-}"
[[ "$VMATRIX_MANIFEST" =~ ^[a-z][a-z0-9-]*$ ]] \
  || fail "VMATRIX_MANIFEST must name one canonical manifest"
jq -e --arg name "$VMATRIX_MANIFEST" \
  '.manifests[$name].evidence.tests | index("exact-artifact-version-matrix") != null' \
  ../manifests/dispositions.json >/dev/null \
  || fail "manifest '$VMATRIX_MANIFEST' does not declare exact-artifact-version-matrix evidence"
VMATRIX_CASES=0
WORDPRESS_OFFLINE="${DUO_WORDPRESS_ORG_OFFLINE:-0}"
case "$WORDPRESS_OFFLINE" in
  0|1) ;;
  *) fail "DUO_WORDPRESS_ORG_OFFLINE must be 0 or 1" ;;
esac
export DUO_PAIR="$PAIR"
PAIR_COMPOSE=(docker compose -p "duo-$PAIR" -f pair.yml -f pair.artifacts.yml)
PAIR_UP_FLAGS=(--artifacts)
if [ "$WORDPRESS_OFFLINE" = 1 ]; then
  PAIR_COMPOSE+=(-f pair.wordpress-offline.yml)
  PAIR_UP_FLAGS+=(--wordpress-offline)
fi
export DUO_ARTIFACT_OFFLINE="$WORDPRESS_OFFLINE"
PAIR_COMPOSE_STRING="${PAIR_COMPOSE[*]}"
VMATRIX_APPLY_LOG=$(mktemp "${TMPDIR:-/tmp}/duo-vmatrix-apply.${PAIR}.XXXXXX")
trap 'rm -f -- "$VMATRIX_APPLY_LOG"' EXIT
wp1() { "${PAIR_COMPOSE[@]}" run --rm -T cli1 sh -c 'umask 000; exec wp "$@"' sh "$@"; }
wp2() { "${PAIR_COMPOSE[@]}" run --rm -T cli2 sh -c 'umask 000; exec wp "$@"' sh "$@"; }
GIT1=(git -C "siterepo/${PAIR}1" -c user.name=duo-vmatrix1 -c user.email=vmatrix1@example.test)

. bin/fetch-artifact.sh

normalize_version_matrix_archive_root() { # <service> <plugin|theme> <slug> <archive-root>
  local service="$1" kind="$2" slug="$3" archive_root="$4" base
  [ "$archive_root" != "$slug" ] || return 0
  case "$kind" in
    plugin) base=/var/www/html/wp-content/plugins ;;
    theme) base=/var/www/html/wp-content/themes ;;
    *) fail "invalid version-matrix extension kind for archive-root normalization" ;;
  esac
  "${PAIR_COMPOSE[@]}" run --rm -T "$service" sh /duo-harness/artifact-archive-root.sh \
    "$base" "$archive_root" "$slug" \
    || fail "could not normalize pinned $kind archive root $archive_root to $slug on $service"
}

# Every boundary replaces both host-side Git working trees while the pair's
# CLI processes run as uid 33.  The roots are live bind mounts, so removing a
# root lets Docker recreate it as root:0755 before the next CLI call.  Preserve
# those exact inodes, clear only their children, and keep this cross-user
# cooperation strictly inside the two disposable matrix repositories.
clear_case_repository() {
  local root="$1"
  case "$root" in
    "siterepo/${PAIR}1"|"siterepo/${PAIR}2") ;;
    *) fail "refusing unsafe matrix repository cleanup: $root" ;;
  esac
  mkdir -p "$root"
  find "$root" -mindepth 1 -maxdepth 1 -exec rm -rf -- {} +
  chmod 0777 "$root"
}

reset_case_repositories() {
  rm -rf "siterepo/origin-$PAIR.git"
  clear_case_repository "siterepo/${PAIR}1"
  clear_case_repository "siterepo/${PAIR}2"
  git init --bare -b main "siterepo/origin-$PAIR.git" >/dev/null
}

clone_case_target() {
  git clone -q "siterepo/origin-$PAIR.git" "siterepo/${PAIR}2"
  chmod 0777 "siterepo/${PAIR}2"
}

say "boot pair $PAIR (${PAIR}1 :$PORT1 / ${PAIR}2 :$PORT2), idempotent"
# Elementor's contract includes real frontend and generated-CSS checks, so
# this matrix must publish its already-reserved ports rather than run headless.
bash bin/pair.sh up "$PAIR" "$PORT1" "$PORT2" "${PAIR_UP_FLAGS[@]}"
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

seed_elementor_content() {
  # Reuse the standalone conformance fixture verbatim: it creates real media,
  # saves a document through Elementor's own Document::save() pipeline, and
  # renders it once so Elementor's lazy derived keys are exercised before
  # capture. Keeping one fixture prevents the boundary matrix from drifting
  # into a weaker hand-written approximation.
  wp_conf1() { wp1 "$@"; }
  local CONF_REPO1="siterepo/${PAIR}1"
  local CONF1_PORT="$PORT1"
  local COMPOSE="$PAIR_COMPOSE_STRING"
  . conformance/seeds/elementor.sh
  unset -f wp_conf1
}

check_elementor_content() {
  wp_conf2() { wp2 "$@"; }
  local CONF_REPO1="siterepo/${PAIR}1"
  local CONF_REPO2="siterepo/${PAIR}2"
  local CONF1_PORT="$PORT1"
  local CONF2_PORT="$PORT2"
  local COMPOSE="$PAIR_COMPOSE_STRING"
  local ELEMENTOR_EXPECTED_VERSION="${ELEMENTOR_VERSION:-4.2.3}"
  local ELEMENTOR_BOUNDARY_ONLY=1
  . conformance/checks/elementor.sh
  unset -f wp_conf2
}

postdeploy_elementor_content() {
  wp_conf2() { wp2 "$@"; }
  local CONF_REPO1="siterepo/${PAIR}1"
  local CONF_REPO2="siterepo/${PAIR}2"
  local COMPOSE="$PAIR_COMPOSE_STRING"
  . conformance/postdeploy/elementor.sh
  unset -f wp_conf2
}

seed_ninja_forms_content() {
  # Reuse the standalone conformance seed verbatim. It imports Ninja Forms'
  # own bundled Job Application template through the plugin's real admin
  # import process, yielding a typed-table graph of 1 form, 23 fields, and
  # 3 actions plus a page containing the real block.
  wp_conf1() { wp1 "$@"; }
  local CONF_REPO1="siterepo/${PAIR}1"
  local COMPOSE="$PAIR_COMPOSE_STRING"
  . conformance/seeds/ninja-forms.sh
  unset -f wp_conf1
}

postdeploy_ninja_forms_content() {
  # Deployment activates the plugin and Ninja Forms creates its own sample
  # form on the target. Reuse the standalone hook that removes only that
  # environment-local activation side effect before apply.
  wp_conf2() { wp2 "$@"; }
  local CONF_REPO2="siterepo/${PAIR}2"
  local COMPOSE="$PAIR_COMPOSE_STRING"
  . conformance/postdeploy/ninja-forms.sh
  unset -f wp_conf2
}

check_ninja_forms_boundary_content() {
  local front form_id api_out
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

  form_id=$(wp2 db query "SELECT id FROM wp_nf3_forms WHERE title='Job Application'" --skip-column-names | tr -d '[:space:]')
  require_fixture_ids form_id
  api_out=$(wp2 eval "
\$form = Ninja_Forms()->form($form_id)->get();
echo \$form->get_setting('title') . '|' . count(Ninja_Forms()->form($form_id)->get_fields()) . '|' . count(Ninja_Forms()->form($form_id)->get_actions());
")
  require_observed_nonempty "side 2 Ninja Forms model API" "$api_out"
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
  local COMPOSE="$PAIR_COMPOSE_STRING"
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

seed_woocommerce_content() {
  # Reuse the standalone WooCommerce fixture: products, coupon, media,
  # global attributes, shipping methods, tax, and a source-only HPOS order.
  wp_conf1() { wp1 "$@"; }
  wp_env() {
    local env="$1"; shift
    case "$env" in
      conf1) wp1 "$@" ;;
      conf2) wp2 "$@" ;;
      *) fail "unknown WooCommerce seed environment: $env" ;;
    esac
  }
  local CONF_REPO1="siterepo/${PAIR}1"
  local COMPOSE="$PAIR_COMPOSE_STRING"
  . conformance/seeds/woocommerce.sh
  unset -f wp_conf1 wp_env
}

postdeploy_woocommerce_content() {
  wp_conf2() { wp2 "$@"; }
  . conformance/postdeploy/woocommerce.sh
  unset -f wp_conf2
}

check_woocommerce_content() {
  local COMPOSE="$PAIR_COMPOSE_STRING"
  local CONF1_PORT="$PORT1"
  local CONF2_PORT="$PORT2"
  . conformance/checks/woocommerce.sh
}

seed_yoast_content() {
  # Reuse the standalone Yoast fixture verbatim: two categories, authored
  # post SEO metadata, term metadata, four media attachments, and the
  # wpseo_titles/wpseo_social sub-key references, all written through the
  # same public Yoast APIs exercised by real settings saves.
  wp_conf1() { wp1 "$@"; }
  wp_env() {
    local env="$1"; shift
    case "$env" in
      conf1) wp1 "$@" ;;
      conf2) wp2 "$@" ;;
      *) fail "unknown Yoast seed environment: $env" ;;
    esac
  }
  local CONF_REPO1="siterepo/${PAIR}1"
  local CONF1_PORT="$PORT1"
  local COMPOSE="$PAIR_COMPOSE_STRING"
  . conformance/seeds/yoast.sh
  unset -f wp_conf1 wp_env
}

check_yoast_content() {
  local COMPOSE="$PAIR_COMPOSE_STRING"
  local CONF_REPO1="siterepo/${PAIR}1"
  local CONF_REPO2="siterepo/${PAIR}2"
  local CONF1_PORT="$PORT1"
  local CONF2_PORT="$PORT2"
  local YOAST_EXPECTED_VERSION="$YOAST_VERSION"
  local YOAST_BOUNDARY_ONLY=1
  . conformance/checks/yoast.sh
}

postdeploy_yoast_content() {
  wp_conf2() { wp2 "$@"; }
  local CONF_REPO1="siterepo/${PAIR}1"
  local CONF_REPO2="siterepo/${PAIR}2"
  local COMPOSE="$PAIR_COMPOSE_STRING"
  . conformance/postdeploy/yoast.sh
  unset -f wp_conf2
}

seed_pmpro_content() {
  wp_conf1() { wp1 "$@"; }
  local CONF_REPO1="siterepo/${PAIR}1"
  local COMPOSE="$PAIR_COMPOSE_STRING"
  . conformance/seeds/paid-memberships-pro.sh
  unset -f wp_conf1
}

check_pmpro_content() {
  wp_conf1() { wp1 "$@"; }
  wp_conf2() { wp2 "$@"; }
  local COMPOSE="$PAIR_COMPOSE_STRING"
  local CONF_REPO1="siterepo/${PAIR}1"
  local CONF_REPO2="siterepo/${PAIR}2"
  local CONF1_PORT="$PORT1"
  local CONF2_PORT="$PORT2"
  local PMPRO_EXPECTED_VERSION="${PMPRO_CHECK_VERSION:-$PMPRO_VERSION}"
  local PMPRO_BOUNDARY_ONLY=1
  local PMPRO_SKIP_FRONTEND=1
  . conformance/checks/paid-memberships-pro.sh
}

postdeploy_pmpro_content() {
  wp_conf2() { wp2 "$@"; }
  local CONF_REPO2="siterepo/${PAIR}2"
  local COMPOSE="$PAIR_COMPOSE_STRING"
  . conformance/postdeploy/paid-memberships-pro.sh
  unset -f wp_conf2
}

seed_advanced_editor_tools_content() {
  wp_conf1() { wp1 "$@"; }
  local CONF_REPO1="siterepo/${PAIR}1"
  local COMPOSE="$PAIR_COMPOSE_STRING"
  . conformance/seeds/advanced-editor-tools.sh
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

seed_classic_editor_content() {
  wp_conf1() { wp1 "$@"; }
  local CONF_REPO1="siterepo/${PAIR}1"
  local COMPOSE="$PAIR_COMPOSE_STRING"
  . conformance/seeds/classic-editor.sh
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

seed_code_snippets_content() {
  wp_conf1() { wp1 "$@"; }
  local CONF_REPO1="siterepo/${PAIR}1"
  local COMPOSE="$PAIR_COMPOSE_STRING"
  . conformance/seeds/code-snippets.sh
  unset -f wp_conf1
}

prepare_code_snippets_boundary_target() {
  wp2 eval '
    foreach (Code_Snippets\get_snippets() as $snippet) {
      if (!Code_Snippets\delete_snippet((int) $snippet->id)) {
        throw new RuntimeException("could not remove target activation sample");
      }
    }
    for ($i = 0; $i < 5; $i++) {
      $spacer = Code_Snippets\save_snippet(new Code_Snippets\Snippet([
        "name" => "Boundary target spacer $i", "desc" => "deleted",
        "code" => "<p>deleted</p>", "scope" => "content", "active" => false,
      ]));
      if (!$spacer || !Code_Snippets\delete_snippet((int) $spacer->id)) {
        throw new RuntimeException("could not advance target identity sequence");
      }
    }
    Code_Snippets\Settings\update_setting("general", "enable_flat_files", true);
    do_action("code_snippets/settings_updated", Code_Snippets\Settings\get_settings_values());
  ' >/dev/null
}

check_code_snippets_boundary_content() { # <label>
  local label="$1" out source_id target_id
  source_id=$(wp1 db query "SELECT id FROM wp_snippets WHERE name='Duo portable content 東京 🚀'" --skip-column-names | tr -d '[:space:]')
  target_id=$(wp2 db query "SELECT id FROM wp_snippets WHERE name='Duo portable content 東京 🚀'" --skip-column-names | tr -d '[:space:]')
  require_fixture_ids source_id target_id
  [ "$source_id" != "$target_id" ] \
    || fail "Code Snippets $label source/target local IDs accidentally matched"
  out=$(wp2 eval '
    global $wpdb;
    $table = Code_Snippets\code_snippets()->db->get_table_name(false);
    $snippets = Code_Snippets\get_snippets();
    $content = null;
    foreach ($snippets as $snippet) {
      if ($snippet->name === "Duo portable content 東京 🚀") { $content = $snippet; }
    }
    if (!$content) { throw new RuntimeException("portable content snippet missing"); }
    $page = get_page_by_path("code-snippets-reference-matrix", OBJECT, "page");
    $hash = Code_Snippets\Snippet_Files::get_hashed_table_name($table);
    $directory = Code_Snippets\Snippet_Files::get_base_dir($hash);
    $files = [];
    if (is_dir($directory)) {
      foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isFile()) { $files[] = substr($file->getPathname(), strlen($directory) + 1); }
      }
    }
    sort($files, SORT_STRING);
    echo wp_json_encode([
      "api_count" => count($snippets),
      "content_id" => (int) $content->id,
      "content_render" => do_shortcode("[code_snippet id=\"{$content->id}\"]"),
      "files" => $files,
      "flat" => Code_Snippets\Snippet_Files::is_active(),
      "page" => $page ? $page->post_content : "",
      "priority" => (int) $wpdb->get_var("SELECT priority FROM `$table` WHERE name=\"Duo runtime filter\""),
      "runtime" => apply_filters("duo_code_snippets_runtime", "base"),
      "sample_count" => (int) $wpdb->get_var("SELECT COUNT(*) FROM `$table` WHERE tags LIKE \"%sample%\""),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  ')
  require_observed_nonempty "Code Snippets $label target behavior" "$out"
  out=$(printf '%s\n' "$out" | awk 'NF { line=$0 } END { print line }')
  printf '%s\n' "$out" | jq -e --arg source "$source_id" --arg target "$target_id" '
    .api_count == 3 and .sample_count == 0 and .flat == true and
    .runtime == "base|repository-runtime" and .priority == 32767 and
    (.content_render | contains("duo-code-snippet-marker")) and
    (.content_render | contains("東京 🚀")) and
    ([.page | scan("code_snippet(?:_source)? (?:id|snippet_id)=\\\"" + $target + "\\\"")] | length) == 4 and
    (.page | contains("\\\"" + $source + "\\\"") | not) and
    (.files | length) == 4 and
    (.files | index("html/" + $target + ".php") != null) and
    (.files | index("html/index.php") != null) and
    (.files | index("php/index.php") != null)
  ' >/dev/null || fail "Code Snippets $label target did not converge API, execution, refs, and flat files: $out"
  pass "Code Snippets $label exact artifact drives mapped refs, PHP/HTML behavior, cache repair, and flat-file execution"
}

seed_wps_hide_login_content() {
  wp_conf1() { wp1 "$@"; }
  local CONF_REPO1="siterepo/${PAIR}1"
  local COMPOSE="$PAIR_COMPOSE_STRING"
  . conformance/seeds/wps-hide-login.sh
  unset -f wp_conf1
}

seed_yoast_duplicate_post_content() {
  wp_conf1() { wp1 "$@"; }
  local CONF_REPO1="siterepo/${PAIR}1"
  local COMPOSE="$PAIR_COMPOSE_STRING"
  . conformance/seeds/yoast-duplicate-post.sh
  unset -f wp_conf1
}

postdeploy_yoast_duplicate_post_content() {
  wp_conf2() { wp2 "$@"; }
  local CONF_REPO2="siterepo/${PAIR}2"
  local COMPOSE="$PAIR_COMPOSE_STRING"
  . conformance/postdeploy/yoast-duplicate-post.sh
  unset -f wp_conf2
}

check_yoast_duplicate_post_boundary_content() {
  local source_ids target_out target_json source_original source_copy
  source_ids=$(wp1 eval '
    $o=get_page_by_path("duo-duplicate-original", OBJECT, "post");
    $c=get_page_by_path("duo-duplicate-copy", OBJECT, "post");
    echo $o->ID . "|" . $c->ID;
  ')
  require_observed_nonempty "Yoast Duplicate Post boundary source IDs" "$source_ids"
  IFS='|' read -r source_original source_copy <<<"$source_ids"
  require_fixture_ids source_original source_copy
  target_out=$(wp2 eval '
    wp_set_current_user(1);
    $o=get_page_by_path("duo-duplicate-original", OBJECT, "post");
    $c=get_page_by_path("duo-duplicate-copy", OBJECT, "post");
    if (!$o || !$c) throw new RuntimeException("Duplicate Post boundary posts missing");
    $api=duplicate_post_get_original($c);
    $roles=[];
    foreach (["administrator","duo_reviewer","editor","subscriber"] as $name) {
      $role=get_role($name); $roles[$name]=$role ? $role->has_cap("copy_posts") : null;
    }
    echo wp_json_encode([
      "copy"=>(int)$c->ID,
      "copy_content_hash"=>hash("sha256", $c->post_content),
      "copy_menu_order"=>(int)$c->menu_order,
      "copy_original"=>(int)get_post_meta($c->ID,"_dp_original",true),
      "copy_original_api"=>$api ? (int)$api->ID : 0,
      "copy_status"=>$c->post_status,
      "copy_title"=>$c->post_title,
      "clone_link"=>duplicate_post_get_clone_post_link($o->ID,"display",false),
      "original"=>(int)$o->ID,
      "original_content_hash"=>hash("sha256", $o->post_content),
      "roles"=>$roles,
      "runtime_copy"=>get_post_meta($o->ID,"_dp_has_rewrite_republish_copy",true),
      "runtime_creation"=>get_post_meta($c->ID,"_dp_creation_date_gmt",true),
      "version"=>get_option("duplicate_post_version"),
    ], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
  ')
  require_observed_nonempty "Yoast Duplicate Post boundary target behavior" "$target_out"
  target_json=$(printf '%s\n' "$target_out" | awk 'NF { line=$0 } END { print line }')
  printf '%s\n' "$target_json" | jq -e --arg source_original "$source_original" --arg source_copy "$source_copy" '
    .original >= 9100001 and .copy > .original and
    (.original | tostring) != $source_original and (.copy | tostring) != $source_copy and
    .copy_original == .original and .copy_original_api == .original and
    .copy_status == "draft" and .copy_menu_order == 24 and
    .copy_content_hash == .original_content_hash and
    (.copy_title | contains("Duo Duplicate Original 東京 🚀")) and
    (.clone_link | contains("duplicate_post")) and
    .roles.administrator == true and .roles.duo_reviewer == true and
    .roles.editor == false and .roles.subscriber == false and
    (.runtime_copy | tonumber) == .copy and .runtime_creation == "not-a-date-東京-🚀" and
    .version == "4.7"
  ' >/dev/null || fail "Yoast Duplicate Post exact boundary target did not consume applied state: $target_json"
  pass "Yoast Duplicate Post exact boundary rewrites large IDs, exposes native links, reconciles roles, and preserves runtime workflow state"
}

check_wps_hide_login_boundary_content() { # <wp1|wp2> <port> <label>
  local cli="$1" port="$2" label="$3" out headers body code location
  out=$("$cli" eval '
    echo wp_json_encode([
      "login" => get_option("whl_page"),
      "login_url" => wp_login_url(),
      "lostpassword_url" => wp_lostpassword_url(),
      "redirect" => get_option("whl_redirect_admin"),
      "site_login_url" => site_url("wp-login.php"),
    ], JSON_UNESCAPED_SLASHES);
  ')
  require_observed_nonempty "WPS Hide Login $label boundary APIs" "$out"
  out=$(printf '%s\n' "$out" | awk 'NF { line=$0 } END { print line }')
  printf '%s\n' "$out" | jq -e '
    .login == "duo-login" and .redirect == "duo-missing" and
    (.login_url | endswith("/duo-login/")) and
    (.site_login_url | endswith("/duo-login/")) and
    (.lostpassword_url | contains("/duo-login/"))
  ' >/dev/null || fail "WPS Hide Login $label boundary APIs do not consume the exact settings: $out"

  headers=$(mktemp "${TMPDIR:-/tmp}/duo-vmatrix-wps-headers.XXXXXX")
  body=$(mktemp "${TMPDIR:-/tmp}/duo-vmatrix-wps-body.XXXXXX")
  code=$(curl --path-as-is --max-time 20 -sS -D "$headers" -o "$body" -w '%{http_code}' "http://localhost:${port}/duo-login/")
  [ "$code" = 200 ] && grep -Fq 'id="loginform"' "$body" \
    || fail "WPS Hide Login $label custom GET did not serve login (status=$code)"
  code=$(curl --path-as-is --max-time 20 -sS -D "$headers" -o "$body" -w '%{http_code}' -X POST \
    --data 'log=duo-no-such-user&pwd=wrong&wp-submit=Log+In&redirect_to=%2Fwp-admin%2F&testcookie=1' \
    "http://localhost:${port}/duo-login/")
  [ "$code" = 200 ] && grep -Fq 'login_error' "$body" \
    || fail "WPS Hide Login $label custom POST did not execute WordPress login handling (status=$code)"
  code=$(curl --path-as-is --max-time 20 -sS -D "$headers" -o "$body" -w '%{http_code}' "http://localhost:${port}/wp-login%2Ephp")
  [ "$code" = 404 ] && ! grep -Fq 'id="loginform"' "$body" \
    || fail "WPS Hide Login $label encoded old-login path bypassed hiding (status=$code)"
  code=$(curl --path-as-is --max-time 20 -sS -D "$headers" -o "$body" -w '%{http_code}' "http://localhost:${port}/wp-admin/")
  location=$(awk 'BEGIN { IGNORECASE=1 } /^Location:/ { sub(/\r$/, ""); print substr($0, 11) }' "$headers" | tail -1)
  [ "$code" = 302 ] && [ "$location" = "http://localhost:${port}/duo-missing/" ] \
    || fail "WPS Hide Login $label wp-admin redirect moved (status=$code location=${location:-<none>})"
  rm -f "$headers" "$body"
  pass "WPS Hide Login $label exact artifact drives APIs, custom GET/POST, encoded old-login refusal, and wp-admin redirect"
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
  # Elementor stores the active kit as an option pointing at a post. `site
  # empty` removes that post, but the option can survive; deleting the
  # authored reference first keeps Elementor's own reset/shutdown hooks from
  # dereferencing a null post on the next exact-version install. This is
  # disposable matrix-fixture cleanup only, not a production-state policy.
  "$cli" option delete elementor_active_kit >/dev/null 2>&1 || true
  "$cli" site empty --yes >/dev/null
  # site empty can leave default_category pointing at a term it deleted. A
  # later plugin installer may reuse that numeric id for another taxonomy
  # (WooCommerce product_visibility exposed this), turning harmless stale
  # residue into a real out-of-scope reference. Rebind the core option to the
  # actual category that site empty retained/recreated before installing the
  # next exact artifact. Polylang can make a translated category (for example
  # uncategorized-en) the target's default. `site empty` then correctly keeps
  # that term, so after rebinding delete every other category: at this reset
  # boundary any survivor is cross-case contamination, not fixture content.
  "$cli" eval '
    $category = get_term_by("slug", "uncategorized", "category");
    if (!$category) {
      $created = wp_insert_term("Uncategorized", "category", ["slug" => "uncategorized"]);
      if (is_wp_error($created)) { throw new RuntimeException($created->get_error_message()); }
      $category = get_term((int) $created["term_id"], "category");
    }
    if (!$category || is_wp_error($category)) { throw new RuntimeException("version-matrix reset could not restore default category"); }
    update_option("default_category", (int) $category->term_id);
    $survivors = get_terms(["taxonomy" => "category", "hide_empty" => false]);
    if (is_wp_error($survivors)) { throw new RuntimeException($survivors->get_error_message()); }
    foreach ($survivors as $term) {
      if ((int) $term->term_id === (int) $category->term_id) { continue; }
      $deleted = wp_delete_term((int) $term->term_id, "category");
      if (is_wp_error($deleted) || $deleted === false) {
        throw new RuntimeException("version-matrix reset could not remove residual category " . $term->slug);
      }
    }
  ' >/dev/null
  # `wp site empty` intentionally retains users. Reusing this dedicated pair
  # after conformance otherwise leaves pmpro_source_member/target_member in
  # place, so the 3.8.2 native seed short-circuits at wp_create_user() before
  # exercising its exact artifact. A version boundary owns no prior fixture
  # principals; delete every non-admin user through WordPress before plugin
  # teardown so user and plugin cleanup hooks see a coherent live runtime.
  "$cli" eval '
    require_once ABSPATH . "wp-admin/includes/user.php";
    $admin = get_user_by("login", "admin");
    if (!$admin) { throw new RuntimeException("version-matrix reset could not resolve admin"); }
    foreach (get_users(["fields" => "ids", "exclude" => [(int) $admin->ID]]) as $user_id) {
      if (!wp_delete_user((int) $user_id, (int) $admin->ID)) {
        throw new RuntimeException("version-matrix reset could not delete user " . (int) $user_id);
      }
    }
  ' >/dev/null
  local plugin
  for plugin in advanced-custom-fields classic-editor code-snippets contact-form-7 duplicate-post elementor ninja-forms paid-memberships-pro polylang tinymce-advanced woocommerce wordpress-seo wps-hide-login; do
    "$cli" plugin deactivate "$plugin" >/dev/null 2>&1 || true
    "$cli" plugin delete "$plugin" >/dev/null 2>&1 || true
  done
  # The editor and hidden-login adapters are option-only. Classic Editor's exact WP-CLI
  # activation/uninstall lifecycle retains its settings, while Advanced
  # Editor Tools can retain legacy rows when code is removed without its
  # uninstall hook. Clear the complete reviewed ownership sets so a later
  # exact boundary cannot inherit another case's authored or migration state.
  # CF7 creates its initial form only when `wpcf7` is absent; retaining that
  # option after `site empty` makes repeated source/target runs asymmetrical.
  "$cli" db query "
    DELETE FROM wp_options WHERE option_name IN (
      'classic-editor-allow-users', 'classic-editor-replace',
      'tadv_admin_settings', 'tadv_allbtns', 'tadv_btns1', 'tadv_btns2',
      'tadv_btns3', 'tadv_btns4', 'tadv_options', 'tadv_plugins',
      'tadv_settings', 'tadv_toolbars', 'tadv_version',
      'whl_page', 'whl_redirect', 'whl_redirect_admin',
      'wps-hide-login-target-rewrite-hash', 'wps-hide-login-target-runtime-probe',
      'wpcf7'
    );
  " >/dev/null
  # Yoast Duplicate Post retains its settings, original-link meta, and role
  # capability on ordinary deletion. Matrix cases must begin at activation.
  "$cli" eval '
    global $wpdb;
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\''duplicate_post_%'\'' OR option_name = '\''yoast_duplicate_post_target_neighbor'\''");
    foreach (wp_roles()->roles as $name => $_row) {
      $role=get_role($name); if ($role && $role->has_cap("copy_posts")) $role->remove_cap("copy_posts");
    }
    foreach (["duo_reviewer","duo_source_only"] as $name) if (get_role($name)) remove_role($name);
  ' >/dev/null
  # Code Snippets deliberately retains its table/settings/files unless its
  # complete-uninstall setting is enabled. A matrix boundary must instead
  # start from that release's own fresh activation schema and sample rows.
  "$cli" db query "
    DROP TABLE IF EXISTS wp_snippets;
    DELETE FROM wp_options WHERE option_name LIKE 'code_snippets%'
      OR option_name IN ('recently_activated_snippets','active_shared_network_snippets');
  " >/dev/null
  "$cli" eval '
    $directory = WP_CONTENT_DIR . "/code-snippets";
    if (is_dir($directory)) {
      require_once ABSPATH . "wp-admin/includes/file.php";
      global $wp_filesystem;
      WP_Filesystem();
      if (!$wp_filesystem || !$wp_filesystem->delete($directory, true)) {
        throw new RuntimeException("version-matrix reset could not remove Code Snippets flat files");
      }
    }
  ' >/dev/null
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
  # Paid Memberships Pro deliberately retains authored/runtime tables and
  # options on ordinary deletion. Boundary cases must run the selected tag's
  # installer against an empty PMPro schema rather than inherit the prior tag.
  "$cli" eval '
    global $wpdb;
    $like = $wpdb->prefix . "pmpro\\_%";
    foreach ($wpdb->get_col($wpdb->prepare("SHOW TABLES LIKE %s", $like)) as $table) {
      $safe = str_replace("`", "``", $table);
      $wpdb->query("DROP TABLE IF EXISTS `{$safe}`");
    }
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\''pmpro_%'\'' OR option_name LIKE '\''_transient_pmpro_%'\'' OR option_name LIKE '\''_site_transient_pmpro_%'\''");
  ' >/dev/null
  # WooCommerce intentionally preserves its schema and setup/runtime options
  # on ordinary plugin deletion. A boundary case must exercise the selected
  # release's own installer, not inherit the preceding release's tables.
  "$cli" eval '
    global $wpdb;
    foreach (["wc\\_%", "woocommerce\\_%", "actionscheduler\\_%"] as $suffix) {
      $like = $wpdb->prefix . $suffix;
      foreach ($wpdb->get_col($wpdb->prepare("SHOW TABLES LIKE %s", $like)) as $table) {
        $safe = str_replace("`", "``", $table);
        $wpdb->query("DROP TABLE IF EXISTS `{$safe}`");
      }
    }
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\''woocommerce_%'\'' OR option_name LIKE '\''wc_%'\'' OR option_name LIKE '\''_transient_wc_%'\'' OR option_name LIKE '\''_site_transient_wc_%'\'' OR option_name LIKE '\''action_scheduler_%'\'' OR option_name IN ('\''schema-ActionScheduler_StoreSchema'\'', '\''schema-ActionScheduler_LoggerSchema'\'')");
  ' >/dev/null
  # Yoast keeps its indexables/migration schema and wpseo option families on
  # ordinary plugin deletion. Remove both so each boundary executes that
  # release's own install/migration path and cannot inherit a newer schema.
  "$cli" eval '
    global $wpdb;
    $like = $wpdb->prefix . "yoast\\_%";
    foreach ($wpdb->get_col($wpdb->prepare("SHOW TABLES LIKE %s", $like)) as $table) {
      $safe = str_replace("`", "``", $table);
      $wpdb->query("DROP TABLE IF EXISTS `{$safe}`");
    }
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\''wpseo%'\'' OR option_name LIKE '\''yoast_%'\'' OR option_name LIKE '\''_transient_%yoast%'\'' OR option_name LIKE '\''_site_transient_%yoast%'\''");
  ' >/dev/null
  "$cli" db query "TRUNCATE TABLE wp_duo_map" >/dev/null 2>&1 || true
  "$cli" db query "TRUNCATE TABLE wp_duo_state" >/dev/null 2>&1 || true
  "$cli" db query "TRUNCATE TABLE wp_duo_kv" >/dev/null 2>&1 || true
  "$cli" db query "TRUNCATE TABLE wp_duo_journal" >/dev/null 2>&1 || true
}

run_elementor_command() {
  local command_log rc
  command_log=$(mktemp "${ELEMENTOR_STDERR_LOG}.command.XXXXXX")
  rc=0
  "$@" 2>"$command_log" || rc=$?
  cat "$command_log" >>"$ELEMENTOR_STDERR_LOG"
  cat "$command_log" >&2
  rm -f "$command_log"
  return "$rc"
}

# These patch-bounded editor manifests each admit exactly one real release.
# Repeating the same bytes under artificial min/max labels would add runtime,
# not evidence; certify the one admitted artifact once and pair it with an
# adjacent official-release refusal below.
if [ "$VMATRIX_MANIFEST" = advanced-editor-tools ]; then
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
fi

if [ "$VMATRIX_MANIFEST" = classic-editor ]; then
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
fi

if [ "$VMATRIX_MANIFEST" = code-snippets ]; then
VMATRIX_CASES=$((VMATRIX_CASES + 1))
for CODE_SNIPPETS_VERSION in 3.9.5 3.9.6; do
  say "boundary: code-snippets $CODE_SNIPPETS_VERSION"

  reset_env wp1
  reset_env wp2
  reset_case_repositories

  say "fetch + verify code-snippets $CODE_SNIPPETS_VERSION (digest-checked artifact only)"
  CS_ARTIFACT_1=$(fetch_artifact code-snippets "$CODE_SNIPPETS_VERSION" cli1)
  CS_ARTIFACT_2=$(fetch_artifact code-snippets "$CODE_SNIPPETS_VERSION" cli2)
  wp1 plugin install "$CS_ARTIFACT_1" --activate >/dev/null
  CS_INSTALLED_1=$(wp1 plugin get code-snippets --field=version)
  [ "$CS_INSTALLED_1" = "$CODE_SNIPPETS_VERSION" ] \
    || fail "side 1 installed version mismatch: expected $CODE_SNIPPETS_VERSION, got $CS_INSTALLED_1"
  pass "side 1: code-snippets $CODE_SNIPPETS_VERSION installed from verified artifact, active"

  cat > "siterepo/${PAIR}1/site.duo.json" <<'EOF'
{
  "manifests": ["core", "code-snippets"],
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
  "${GIT1[@]}" commit -qm "policy: Code Snippets $CODE_SNIPPETS_VERSION exact-boundary certification"
  "${GIT1[@]}" push -qu origin main

  seed_code_snippets_content
  wp1 duo capture --repo=/siterepo
  wp1 duo lint --repo=/siterepo
  "${GIT1[@]}" add -A
  "${GIT1[@]}" commit -qm "capture: Code Snippets $CODE_SNIPPETS_VERSION executable and reference state"
  "${GIT1[@]}" push -q origin main

  clone_case_target
  wp2 plugin install "$CS_ARTIFACT_2" >/dev/null
  INSTALLED_2=$(wp2 plugin get code-snippets --field=version)
  require_fixture_values INSTALLED_2
  [ "$INSTALLED_2" = "$CODE_SNIPPETS_VERSION" ] \
    || fail "side 2 installed version mismatch: expected $CODE_SNIPPETS_VERSION, got $INSTALLED_2"
  wp2 duo deploy --repo=/siterepo
  prepare_code_snippets_boundary_target
  REV=$(git -C "siterepo/${PAIR}2" rev-parse HEAD)
  wp2 duo apply --repo=/siterepo --adopt-by-slug=terms,posts --default-author=admin --revision="$REV" 2>&1 | tee "$VMATRIX_APPLY_LOG"
  grep -q 'canary clean' "$VMATRIX_APPLY_LOG" \
    || fail "apply canary not clean at code-snippets $CODE_SNIPPETS_VERSION"
  grep -q 'provider capability fired: code-snippets-state@1.0.0 rebuild_snippet_state' "$VMATRIX_APPLY_LOG" \
    || fail "Code Snippets provider did not fire at $CODE_SNIPPETS_VERSION"
  check_code_snippets_boundary_content "$CODE_SNIPPETS_VERSION"

  wp2 duo capture --repo=/siterepo --out=/siterepo/.tmp-final
  CS_DIFF=$(diff -rq "siterepo/${PAIR}1/state" "siterepo/${PAIR}2/.tmp-final" || true)
  rm -rf "siterepo/${PAIR}2/.tmp-final"
  [ -z "$CS_DIFF" ] \
    || fail "byte-identity broken at code-snippets $CODE_SNIPPETS_VERSION: $CS_DIFF"
  pass "Code Snippets $CODE_SNIPPETS_VERSION deploys, rebuilds derived state, executes natively, and recaptures byte-identically"

  if [ "$CODE_SNIPPETS_VERSION" = 3.9.5 ]; then
    # The relevant table/cache/flat-file source is byte-identical across the
    # admitted boundary. Upgrade both populated sides in place and prove that
    # no canonical or execution state moves when 3.9.6's unrelated REST/
    # multisite fixes replace 3.9.5.
    CS_UPGRADE_1=$(fetch_artifact code-snippets 3.9.6 cli1)
    CS_UPGRADE_2=$(fetch_artifact code-snippets 3.9.6 cli2)
    UPGRADE_BEFORE_1=$(wp1 eval 'global $wpdb; echo hash("sha256", wp_json_encode($wpdb->get_results("SELECT name,description,code,tags,scope,priority,active FROM {$wpdb->prefix}snippets ORDER BY id", ARRAY_A), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));')
    UPGRADE_BEFORE_2=$(wp2 eval 'global $wpdb; echo hash("sha256", wp_json_encode($wpdb->get_results("SELECT name,description,code,tags,scope,priority,active FROM {$wpdb->prefix}snippets ORDER BY id", ARRAY_A), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));')
    require_observed_nonempty "Code Snippets 3.9.5 source upgrade baseline" "$UPGRADE_BEFORE_1"
    require_observed_nonempty "Code Snippets 3.9.5 target upgrade baseline" "$UPGRADE_BEFORE_2"
    wp1 plugin install "$CS_UPGRADE_1" --force --activate >/dev/null
    wp2 plugin install "$CS_UPGRADE_2" --force --activate >/dev/null
    [ "$(wp1 plugin get code-snippets --field=version)" = 3.9.6 ] \
      && [ "$(wp2 plugin get code-snippets --field=version)" = 3.9.6 ] \
      || fail "Code Snippets supported in-place upgrade did not install 3.9.6 on both sides"
    UPGRADE_DEPLOY_RC=0
    UPGRADE_DEPLOY_OUT=$(wp2 duo deploy --repo=/siterepo 2>&1) || UPGRADE_DEPLOY_RC=$?
    require_duo_answered "Code Snippets out-of-band 3.9.5 to 3.9.6 upgrade refusal" human "$UPGRADE_DEPLOY_OUT"
    [ "$UPGRADE_DEPLOY_RC" -ne 0 ] \
      && grep -q 'deploy refused — code_drift' <<<"$UPGRADE_DEPLOY_OUT" \
      && grep -q 'recorded 3.9.5' <<<"$UPGRADE_DEPLOY_OUT" \
      && grep -q 'is 3.9.6 on this environment' <<<"$UPGRADE_DEPLOY_OUT" \
      || fail "Code Snippets out-of-band upgrade did not refuse at the exact code-drift boundary: $UPGRADE_DEPLOY_OUT"
    wp2 duo deploy --repo=/siterepo --force-code-drift >/dev/null
    UPGRADE_PLAN=$(wp2 duo plan --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
    require_duo_answered "Code Snippets 3.9.5 to 3.9.6 target plan" json "$UPGRADE_PLAN"
    jq -e '([.create,.update,.drift,.conflict,.collision,.delete,.delete_conflict] | map(length) | add) == 0' <<<"$UPGRADE_PLAN" >/dev/null \
      || fail "Code Snippets supported in-place upgrade invented authored work: $UPGRADE_PLAN"
    [ "$(wp1 eval 'global $wpdb; echo hash("sha256", wp_json_encode($wpdb->get_results("SELECT name,description,code,tags,scope,priority,active FROM {$wpdb->prefix}snippets ORDER BY id", ARRAY_A), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));')" = "$UPGRADE_BEFORE_1" ] \
      && [ "$(wp2 eval 'global $wpdb; echo hash("sha256", wp_json_encode($wpdb->get_results("SELECT name,description,code,tags,scope,priority,active FROM {$wpdb->prefix}snippets ORDER BY id", ARRAY_A), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));')" = "$UPGRADE_BEFORE_2" ] \
      || fail "Code Snippets supported in-place upgrade changed authored rows"
    check_code_snippets_boundary_content '3.9.5 -> 3.9.6 in-place upgrade'
    pass "Code Snippets populated 3.9.5 sites upgrade in place to 3.9.6 without authored, identity, reference, cache, or execution drift"
  fi
done
fi

if [ "$VMATRIX_MANIFEST" = wps-hide-login ]; then
VMATRIX_CASES=$((VMATRIX_CASES + 1))
WPS_VERSION=1.9.19
say "boundary: wps-hide-login $WPS_VERSION (only admitted patch)"

reset_env wp1
reset_env wp2
reset_case_repositories

say "fetch + verify wps-hide-login $WPS_VERSION (digest-checked artifact only)"
WPS_ARTIFACT_1=$(fetch_artifact wps-hide-login "$WPS_VERSION" cli1)
WPS_ARTIFACT_2=$(fetch_artifact wps-hide-login "$WPS_VERSION" cli2)
wp1 plugin install "$WPS_ARTIFACT_1" --activate >/dev/null
WPS_INSTALLED_1=$(wp1 plugin get wps-hide-login --field=version)
[ "$WPS_INSTALLED_1" = "$WPS_VERSION" ] \
  || fail "side 1 installed version mismatch: expected $WPS_VERSION, got $WPS_INSTALLED_1"
pass "side 1: wps-hide-login $WPS_VERSION installed from verified artifact, active"

cat > "siterepo/${PAIR}1/site.duo.json" <<'EOF'
{
  "manifests": ["core", "wps-hide-login"],
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
"${GIT1[@]}" commit -qm "policy: WPS Hide Login $WPS_VERSION exact-boundary certification"
"${GIT1[@]}" push -qu origin main

seed_wps_hide_login_content
check_wps_hide_login_boundary_content wp1 "$PORT1" source
# Real login requests can leave core's transient Customizer sentinel at -1;
# remove it so exact recapture has no "unmanaged post id -1" warning.
wp1 eval 'remove_theme_mod("custom_css_post_id");' >/dev/null
wp1 duo capture --repo=/siterepo
wp1 duo lint --repo=/siterepo
"${GIT1[@]}" add -A
"${GIT1[@]}" commit -qm "capture: WPS Hide Login $WPS_VERSION routes"
"${GIT1[@]}" push -q origin main

clone_case_target
wp2 plugin install "$WPS_ARTIFACT_2" >/dev/null
INSTALLED_2=$(wp2 plugin get wps-hide-login --field=version)
require_fixture_values INSTALLED_2
[ "$INSTALLED_2" = "$WPS_VERSION" ] \
  || fail "side 2 installed version mismatch: expected $WPS_VERSION, got $INSTALLED_2"
wp2 duo deploy --repo=/siterepo
REV=$(git -C "siterepo/${PAIR}2" rev-parse HEAD)
wp2 duo apply --repo=/siterepo --adopt-by-slug=terms,posts --default-author=admin --revision="$REV" | tee "$VMATRIX_APPLY_LOG"
grep -q 'canary clean' "$VMATRIX_APPLY_LOG" \
  || fail "apply canary not clean at wps-hide-login $WPS_VERSION"
check_wps_hide_login_boundary_content wp2 "$PORT2" target

wp2 eval 'remove_theme_mod("custom_css_post_id");' >/dev/null
wp2 duo capture --repo=/siterepo --out=/siterepo/.tmp-final
WPS_DIFF=$(diff -rq "siterepo/${PAIR}1/state" "siterepo/${PAIR}2/.tmp-final" || true)
rm -rf "siterepo/${PAIR}2/.tmp-final"
[ -z "$WPS_DIFF" ] \
  || fail "byte-identity broken at wps-hide-login $WPS_VERSION: $WPS_DIFF"
pass "WPS Hide Login $WPS_VERSION deploys, handles real source/target requests, and recaptures byte-identically"
fi

if [ "$VMATRIX_MANIFEST" = yoast-duplicate-post ]; then
VMATRIX_CASES=$((VMATRIX_CASES + 1))
YDP_VERSION=4.7
say "boundary: duplicate-post $YDP_VERSION (only admitted patch)"

reset_env wp1
reset_env wp2
reset_case_repositories

say "fetch + verify duplicate-post $YDP_VERSION (digest-checked artifact only)"
YDP_ARTIFACT_1=$(fetch_artifact duplicate-post "$YDP_VERSION" cli1)
YDP_ARTIFACT_2=$(fetch_artifact duplicate-post "$YDP_VERSION" cli2)
wp1 plugin install "$YDP_ARTIFACT_1" --activate >/dev/null
YDP_INSTALLED_1=$(wp1 plugin get duplicate-post --field=version)
[ "$YDP_INSTALLED_1" = "$YDP_VERSION" ] \
  || fail "side 1 installed version mismatch: expected $YDP_VERSION, got $YDP_INSTALLED_1"
pass "side 1: duplicate-post $YDP_VERSION installed from verified artifact, active"

cat > "siterepo/${PAIR}1/site.duo.json" <<'EOF'
{
  "manifests": ["core", "yoast-duplicate-post"],
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
"${GIT1[@]}" commit -qm "policy: Yoast Duplicate Post $YDP_VERSION exact-boundary certification"
"${GIT1[@]}" push -qu origin main

seed_yoast_duplicate_post_content
wp1 duo capture --repo=/siterepo
wp1 duo lint --repo=/siterepo
"${GIT1[@]}" add -A
"${GIT1[@]}" commit -qm "capture: Yoast Duplicate Post $YDP_VERSION settings and clone state"
"${GIT1[@]}" push -q origin main

clone_case_target
wp2 plugin install "$YDP_ARTIFACT_2" >/dev/null
INSTALLED_2=$(wp2 plugin get duplicate-post --field=version)
require_fixture_values INSTALLED_2
[ "$INSTALLED_2" = "$YDP_VERSION" ] \
  || fail "side 2 installed version mismatch: expected $YDP_VERSION, got $INSTALLED_2"
wp2 duo deploy --repo=/siterepo
postdeploy_yoast_duplicate_post_content
REV=$(git -C "siterepo/${PAIR}2" rev-parse HEAD)
wp2 duo apply --repo=/siterepo --adopt-by-slug=terms,posts --default-author=admin --revision="$REV" 2>&1 | tee "$VMATRIX_APPLY_LOG"
grep -q 'canary clean' "$VMATRIX_APPLY_LOG" \
  || fail "apply canary not clean at duplicate-post $YDP_VERSION"
grep -q 'provider capability fired: yoast-duplicate-post-role-capabilities@1.0.0 reconcile_role_capabilities' "$VMATRIX_APPLY_LOG" \
  || fail "Yoast Duplicate Post role provider did not fire at $YDP_VERSION"
check_yoast_duplicate_post_boundary_content

wp2 duo capture --repo=/siterepo --out=/siterepo/.tmp-final
YDP_DIFF=$(diff -rq "siterepo/${PAIR}1/state" "siterepo/${PAIR}2/.tmp-final" || true)
rm -rf "siterepo/${PAIR}2/.tmp-final"
[ -z "$YDP_DIFF" ] \
  || fail "byte-identity broken at duplicate-post $YDP_VERSION: $YDP_DIFF"
pass "Yoast Duplicate Post $YDP_VERSION deploys, rewrites provenance, reconciles role state, behaves natively, and recaptures byte-identically"
fi

if [ "$VMATRIX_MANIFEST" = acf ]; then
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

  clone_case_target
  wp2 plugin install "$ARTIFACT_2" >/dev/null
  INSTALLED_2=$(wp2 plugin get advanced-custom-fields --field=version)
  require_fixture_values INSTALLED_2
  [ "$INSTALLED_2" = "$ACF_VERSION" ] || fail "side 2 installed version mismatch: expected $ACF_VERSION, got $INSTALLED_2"

  wp2 duo deploy --repo=/siterepo
  REV=$(git -C "siterepo/${PAIR}2" rev-parse HEAD)
  # --adopt-by-slug=terms,posts: WordPress core's own defaults (the
  # "Uncategorized" category always, a "Hello World" post/"Sample Page" on
  # some installs) survive `site empty --yes` and collide by slug with the
  # captured state's own entities of the same name — the same known,
  # expected pattern every other grind/certify pair script in this repo
  # already handles the identical way (grind_r3b_events.sh, grind_r1b_shop.sh).
  wp2 duo apply --repo=/siterepo --adopt-by-slug=terms,posts --default-author=admin --revision="$REV" | tee "$VMATRIX_APPLY_LOG"
  grep -q 'canary clean' "$VMATRIX_APPLY_LOG" || fail "apply canary not clean at acf $ACF_VERSION"
  pass "deploy + apply succeeded on side 2 (acf $ACF_VERSION, canary clean)"

  wp2 duo capture --repo=/siterepo --out="/siterepo/.tmp-final"
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
    wp1 duo deploy --repo=/siterepo --force-code-drift >/dev/null
    wp2 duo deploy --repo=/siterepo --force-code-drift >/dev/null
    wp1 duo capture --repo=/siterepo
    wp1 duo lint --repo=/siterepo
    if ! git -C "siterepo/${PAIR}1" diff --quiet -- state; then
      "${GIT1[@]}" add -A
      "${GIT1[@]}" commit -qm "capture: ACF in-place 6.0.0 to 6.8.7 migration"
      "${GIT1[@]}" push -q origin main
      git -C "siterepo/${PAIR}2" pull -q origin main
    fi
    UPGRADE_REV=$(git -C "siterepo/${PAIR}2" rev-parse HEAD)
    wp2 duo apply --repo=/siterepo --adopt-by-slug=terms,posts --default-author=admin --revision="$UPGRADE_REV" \
      2>&1 | tee "$VMATRIX_APPLY_LOG"
    grep -q 'canary clean' "$VMATRIX_APPLY_LOG" \
      || fail "ACF in-place 6.0.0 -> 6.8.7 apply canary was not clean"

    UPGRADE_NATIVE=$(wp2 eval '
      $content=get_page_by_path("vmatrix-acf-content", OBJECT, "post");
      $related=$content ? get_field("duo_related", $content->ID) : [];
      $target=$related ? get_post((int)$related[0]) : null;
      echo $target ? $target->post_title : "";
    ')
    [ "$UPGRADE_NATIVE" = 'Version Matrix Related Target' ] \
      || fail "ACF 6.8.7 did not resolve the relationship authored under 6.0.0: $UPGRADE_NATIVE"
    wp2 duo capture --repo=/siterepo --out=/siterepo/.tmp-acf-upgrade-final
    UPGRADE_DIFF=$(diff -rq "siterepo/${PAIR}1/state" "siterepo/${PAIR}2/.tmp-acf-upgrade-final" || true)
    rm -rf "siterepo/${PAIR}2/.tmp-acf-upgrade-final"
    [ -z "$UPGRADE_DIFF" ] \
      || fail "ACF in-place 6.0.0 -> 6.8.7 recapture was not byte-identical: $UPGRADE_DIFF"
    pass "ACF state authored under exact 6.0.0 upgrades in place to exact 6.8.7, remains plugin-visible, applies cleanly, and recaptures byte-identically"
  fi
done
fi

if [ "$VMATRIX_MANIFEST" = ninja-forms ]; then
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

  clone_case_target
  wp2 plugin install "$ARTIFACT_2" >/dev/null
  INSTALLED_2=$(wp2 plugin get ninja-forms --field=version)
  require_fixture_values INSTALLED_2
  [ "$INSTALLED_2" = "$NINJA_VERSION" ] || fail "side 2 installed version mismatch: expected $NINJA_VERSION, got $INSTALLED_2"

  wp2 duo deploy --repo=/siterepo
  postdeploy_ninja_forms_content
  REV=$(git -C "siterepo/${PAIR}2" rev-parse HEAD)
  wp2 duo apply --repo=/siterepo --adopt-by-slug=terms,posts --default-author=admin --revision="$REV" | tee "$VMATRIX_APPLY_LOG"
  grep -q 'canary clean' "$VMATRIX_APPLY_LOG" || fail "apply canary not clean at ninja-forms $NINJA_VERSION"
  pass "deploy + apply succeeded on side 2 (ninja-forms $NINJA_VERSION, canary clean)"

  check_ninja_forms_boundary_content

  wp2 duo capture --repo=/siterepo --out="/siterepo/.tmp-final"
  DIFF_OUT=$(diff -rq "siterepo/${PAIR}1/state" "siterepo/${PAIR}2/.tmp-final" || true)
  rm -rf "siterepo/${PAIR}2/.tmp-final"
  [ -z "$DIFF_OUT" ] || fail "byte-identity broken at ninja-forms $NINJA_VERSION: $DIFF_OUT"
  pass "byte-identical recapture at ninja-forms $NINJA_VERSION — the manifest's own declared version_range boundary is proven, not just its currently-installed version"
done
fi

# PMPro is no longer distributed through wp.org. Both admitted official tags
# are digest-pinned boundaries; populated 3.8.2 sites additionally upgrade in
# place to 3.8.3. The adjacent official 3.8.1/3.8.4 tags refuse below.
if [ "$VMATRIX_MANIFEST" = paid-memberships-pro ]; then
VMATRIX_CASES=$((VMATRIX_CASES + 2))
for PMPRO_VERSION in 3.8.2 3.8.3; do
  say "boundary: paid-memberships-pro $PMPRO_VERSION"

  reset_env wp1
  reset_env wp2
  reset_case_repositories

  say "fetch + verify paid-memberships-pro $PMPRO_VERSION from the official upstream tag"
  ARTIFACT_1=$(fetch_artifact paid-memberships-pro "$PMPRO_VERSION" cli1)
  ARTIFACT_2=$(fetch_artifact paid-memberships-pro "$PMPRO_VERSION" cli2)
  pass "verified sha256-pinned upstream artifact resolved for both sides: $ARTIFACT_1"

  wp1 plugin install "$ARTIFACT_1" >/dev/null
  normalize_version_matrix_archive_root cli1 plugin paid-memberships-pro "paid-memberships-pro-$PMPRO_VERSION"
  wp1 plugin activate paid-memberships-pro >/dev/null
  INSTALLED_1=$(wp1 plugin get paid-memberships-pro --field=version)
  [ "$INSTALLED_1" = "$PMPRO_VERSION" ] || fail "side 1 installed version mismatch: expected $PMPRO_VERSION, got $INSTALLED_1"
  pass "side 1: paid-memberships-pro $PMPRO_VERSION installed from verified artifact, active"

  cat > "siterepo/${PAIR}1/site.duo.json" <<EOF
{
  "manifests": ["core", "paid-memberships-pro"],
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
  "${GIT1[@]}" commit -qm "policy: paid-memberships-pro $PMPRO_VERSION exact-boundary certification"
  "${GIT1[@]}" push -qu origin main

  seed_pmpro_content
  wp1 duo capture --repo=/siterepo
  pass "captured on side 1 (paid-memberships-pro $PMPRO_VERSION)"
  wp1 duo lint --repo=/siterepo
  pass "lint: 0 findings"

  "${GIT1[@]}" add -A
  "${GIT1[@]}" commit -qm "capture: paid-memberships-pro $PMPRO_VERSION content"
  "${GIT1[@]}" push -q origin main

  clone_case_target
  wp2 plugin install "$ARTIFACT_2" >/dev/null
  normalize_version_matrix_archive_root cli2 plugin paid-memberships-pro "paid-memberships-pro-$PMPRO_VERSION"
  INSTALLED_2=$(wp2 plugin get paid-memberships-pro --field=version)
  require_fixture_values INSTALLED_2
  [ "$INSTALLED_2" = "$PMPRO_VERSION" ] || fail "side 2 installed version mismatch: expected $PMPRO_VERSION, got $INSTALLED_2"
  wp2 plugin is-active paid-memberships-pro >/dev/null 2>&1 && fail "PMPro target premise must begin inactive"

  wp2 duo deploy --repo=/siterepo
  wp2 plugin is-active paid-memberships-pro >/dev/null || fail "deploy did not activate the admitted PMPro artifact"
  postdeploy_pmpro_content
  REV=$(git -C "siterepo/${PAIR}2" rev-parse HEAD)
  wp2 duo apply --repo=/siterepo --adopt-by-slug=terms,posts --default-author=admin --revision="$REV" 2>&1 | tee "$VMATRIX_APPLY_LOG"
  grep -q 'canary clean' "$VMATRIX_APPLY_LOG" || fail "apply canary not clean at paid-memberships-pro $PMPRO_VERSION"
  pass "deploy + apply succeeded on side 2 (paid-memberships-pro $PMPRO_VERSION, hostile target, canary clean)"

  check_pmpro_content

  wp2 duo capture --repo=/siterepo --out="/siterepo/.tmp-final"
  DIFF_OUT=$(diff -rq "siterepo/${PAIR}1/state" "siterepo/${PAIR}2/.tmp-final" || true)
  rm -rf "siterepo/${PAIR}2/.tmp-final"
  [ -z "$DIFF_OUT" ] || fail "byte-identity broken at paid-memberships-pro $PMPRO_VERSION: $DIFF_OUT"
  pass "byte-identical recapture at paid-memberships-pro $PMPRO_VERSION — all authored tables and native APIs are bound to exact upstream bytes"

  if [ "$PMPRO_VERSION" = 3.8.2 ]; then
    say 'in-place lifecycle: paid-memberships-pro 3.8.2 authored state -> exact 3.8.3 on both environments'
    UPGRADE_ARTIFACT_1=$(fetch_artifact paid-memberships-pro 3.8.3 cli1)
    UPGRADE_ARTIFACT_2=$(fetch_artifact paid-memberships-pro 3.8.3 cli2)
    wp1 plugin install "$UPGRADE_ARTIFACT_1" --force >/dev/null
    wp2 plugin install "$UPGRADE_ARTIFACT_2" --force >/dev/null
    normalize_version_matrix_archive_root cli1 plugin paid-memberships-pro paid-memberships-pro-3.8.3
    normalize_version_matrix_archive_root cli2 plugin paid-memberships-pro paid-memberships-pro-3.8.3
    [ "$(wp1 plugin get paid-memberships-pro --field=version)" = 3.8.3 ] \
      && [ "$(wp2 plugin get paid-memberships-pro --field=version)" = 3.8.3 ] \
      || fail 'PMPro in-place upgrade did not install exact 3.8.3 on both populated environments'
    wp1 duo deploy --repo=/siterepo --force-code-drift >/dev/null
    wp2 duo deploy --repo=/siterepo --force-code-drift >/dev/null
    wp1 eval '
      global $wpdb; $id=(int)$wpdb->get_var("SELECT id FROM {$wpdb->pmpro_membership_levels} WHERE name=\"Builder 東京 🚀\" AND initial_payment=19.95");
      update_pmpro_membership_level_meta($id,"membership_account_message","PMPro 3.8.2 to 3.8.3 upgrade 東京 🚀");
    ' >/dev/null
    wp1 duo capture --repo=/siterepo
    wp1 duo lint --repo=/siterepo
    "${GIT1[@]}" add -A
    "${GIT1[@]}" commit -qm 'capture: PMPro 3.8.2 to 3.8.3 in-place upgrade'
    "${GIT1[@]}" push -q origin main
    git -C "siterepo/${PAIR}2" pull -q origin main
    UPGRADE_REV=$(git -C "siterepo/${PAIR}2" rev-parse HEAD)
    wp2 duo apply --repo=/siterepo --default-author=admin --revision="$UPGRADE_REV" 2>&1 | tee "$VMATRIX_APPLY_LOG"
    grep -q 'canary clean' "$VMATRIX_APPLY_LOG" || fail 'PMPro 3.8.2 -> 3.8.3 upgrade apply canary was not clean'
    PMPRO_CHECK_VERSION=3.8.3 check_pmpro_content
    unset PMPRO_CHECK_VERSION
    wp2 duo capture --repo=/siterepo --out=/siterepo/.tmp-pmpro-upgrade-final
    UPGRADE_DIFF=$(diff -rq "siterepo/${PAIR}1/state" "siterepo/${PAIR}2/.tmp-pmpro-upgrade-final" || true)
    rm -rf "siterepo/${PAIR}2/.tmp-pmpro-upgrade-final"
    [ -z "$UPGRADE_DIFF" ] || fail "PMPro 3.8.2 -> 3.8.3 in-place upgrade lost byte identity: $UPGRADE_DIFF"
    pass 'PMPro 3.8.2 authored graph upgrades in place to 3.8.3 with native API, cache, identities, and byte identity intact'
  fi
done
fi

if [ "$VMATRIX_MANIFEST" = elementor ]; then
VMATRIX_CASES=$((VMATRIX_CASES + 1))
for ELEMENTOR_VERSION in 4.0.0 4.2.3; do
  say "boundary: elementor $ELEMENTOR_VERSION"
  ELEMENTOR_STDERR_LOG=$(mktemp "${TMPDIR:-/tmp}/duo-vmatrix-elementor.XXXXXX")

  run_elementor_command reset_env wp1
  run_elementor_command reset_env wp2
  reset_case_repositories

  say "fetch + verify elementor $ELEMENTOR_VERSION (never a bare slug install — always a digest-checked artifact)"
  ARTIFACT_1=$(run_elementor_command fetch_artifact elementor "$ELEMENTOR_VERSION" cli1)
  ARTIFACT_2=$(run_elementor_command fetch_artifact elementor "$ELEMENTOR_VERSION" cli2)
  pass "verified sha256-pinned artifact resolved for both sides: $ARTIFACT_1"

  run_elementor_command wp1 plugin install "$ARTIFACT_1" --activate >/dev/null
  INSTALLED_1=$(run_elementor_command wp1 plugin get elementor --field=version)
  [ "$INSTALLED_1" = "$ELEMENTOR_VERSION" ] || fail "side 1 installed version mismatch: expected $ELEMENTOR_VERSION, got $INSTALLED_1"
  pass "side 1: elementor $ELEMENTOR_VERSION installed from verified artifact, active"

  cat > "siterepo/${PAIR}1/site.duo.json" <<EOF
{
  "manifests": ["core", "elementor"],
  "policy": {
    "options": {},
    "post_meta": {},
    "post_types": ["post", "page", "attachment", "elementor_library"],
    "taxonomies": ["category", "post_tag", "elementor_library_type"]
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

  run_elementor_command seed_elementor_content

  run_elementor_command wp1 duo capture --repo=/siterepo
  pass "captured on side 1 (elementor $ELEMENTOR_VERSION)"

  run_elementor_command wp1 duo lint --repo=/siterepo
  pass "lint: 0 findings"

  "${GIT1[@]}" add -A
  "${GIT1[@]}" commit -qm "capture: elementor $ELEMENTOR_VERSION content"
  "${GIT1[@]}" push -q origin main

  clone_case_target
  run_elementor_command wp2 plugin install "$ARTIFACT_2" >/dev/null
  INSTALLED_2=$(run_elementor_command wp2 plugin get elementor --field=version)
  require_fixture_values INSTALLED_2
  [ "$INSTALLED_2" = "$ELEMENTOR_VERSION" ] || fail "side 2 installed version mismatch: expected $ELEMENTOR_VERSION, got $INSTALLED_2"

  run_elementor_command wp2 duo deploy --repo=/siterepo
  run_elementor_command postdeploy_elementor_content
  REV=$(git -C "siterepo/${PAIR}2" rev-parse HEAD)
  run_elementor_command wp2 duo apply --repo=/siterepo --adopt-by-slug=terms,posts --default-author=admin --revision="$REV" --format=json | tee "$VMATRIX_APPLY_LOG"
  require_duo_answered "Elementor $ELEMENTOR_VERSION apply" json "$(cat "$VMATRIX_APPLY_LOG")"
  jq -e '.canary == "clean"' "$VMATRIX_APPLY_LOG" >/dev/null \
    || fail "apply canary not clean at elementor $ELEMENTOR_VERSION"
  pass "deploy + apply succeeded on side 2 (elementor $ELEMENTOR_VERSION, canary clean)"

  run_elementor_command check_elementor_content

  run_elementor_command wp2 duo capture --repo=/siterepo --out="/siterepo/.tmp-final"
  DIFF_OUT=$(diff -rq "siterepo/${PAIR}1/state" "siterepo/${PAIR}2/.tmp-final" || true)
  rm -rf "siterepo/${PAIR}2/.tmp-final"
  [ -z "$DIFF_OUT" ] || fail "byte-identity broken at elementor $ELEMENTOR_VERSION: $DIFF_OUT"
  pass "byte-identical recapture at elementor $ELEMENTOR_VERSION — the manifest's own declared version_range boundary is proven, not just its currently-installed version"

  if [ "$ELEMENTOR_VERSION" = 4.0.0 ]; then
    say 'in-place upgrade: elementor 4.0.0 -> 4.2.3 on both existing environments'
    UPGRADE_ARTIFACT_1=$(run_elementor_command fetch_artifact elementor 4.2.3 cli1)
    UPGRADE_ARTIFACT_2=$(run_elementor_command fetch_artifact elementor 4.2.3 cli2)
    run_elementor_command wp1 plugin install "$UPGRADE_ARTIFACT_1" --force --activate >/dev/null
    [ "$(run_elementor_command wp1 plugin get elementor --field=version)" = 4.2.3 ] \
      || fail 'Elementor source in-place upgrade did not install exact 4.2.3'
    run_elementor_command wp1 duo deploy --repo=/siterepo --force-code-drift >/dev/null
    UPGRADE_PAGE=$(run_elementor_command wp1 post list --post_type=page --name=duo-conformance-elementor-page --field=ID)
    require_fixture_ids UPGRADE_PAGE
    run_elementor_command wp1 post update "$UPGRADE_PAGE" --post_title='Elementor 4.0.0 to 4.2.3 upgrade 東京 🚀' >/dev/null
    run_elementor_command wp1 duo capture --repo=/siterepo
    run_elementor_command wp1 duo lint --repo=/siterepo
    "${GIT1[@]}" add -A
    "${GIT1[@]}" commit -qm 'capture: elementor 4.0.0 to 4.2.3 in-place upgrade'
    "${GIT1[@]}" push -q origin main
    git -C "siterepo/${PAIR}2" pull -q origin main

    run_elementor_command wp2 plugin install "$UPGRADE_ARTIFACT_2" --force --activate >/dev/null
    [ "$(run_elementor_command wp2 plugin get elementor --field=version)" = 4.2.3 ] \
      || fail 'Elementor target in-place upgrade did not install exact 4.2.3'
    run_elementor_command wp2 duo deploy --repo=/siterepo --force-code-drift
    REV=$(git -C "siterepo/${PAIR}2" rev-parse HEAD)
    run_elementor_command wp2 duo apply --repo=/siterepo --default-author=admin --revision="$REV" --format=json | tee "$VMATRIX_APPLY_LOG"
    require_duo_answered 'Elementor 4.0.0 to 4.2.3 upgrade apply' json "$(cat "$VMATRIX_APPLY_LOG")"
    jq -e '.canary == "clean" and .verification.result == "pass"' "$VMATRIX_APPLY_LOG" >/dev/null \
      || fail 'apply canary not clean after elementor 4.0.0 to 4.2.3 in-place upgrade'
    SAVED_ELEMENTOR_VERSION="$ELEMENTOR_VERSION"
    ELEMENTOR_VERSION=4.2.3
    run_elementor_command check_elementor_content
    ELEMENTOR_VERSION="$SAVED_ELEMENTOR_VERSION"

    run_elementor_command wp2 duo capture --repo=/siterepo --out=/siterepo/.tmp-upgraded-final
    UPGRADE_DIFF=$(diff -rq "siterepo/${PAIR}1/state" "siterepo/${PAIR}2/.tmp-upgraded-final" || true)
    rm -rf "siterepo/${PAIR}2/.tmp-upgraded-final"
    [ -z "$UPGRADE_DIFF" ] || fail "Elementor 4.0.0 to 4.2.3 in-place upgrade lost byte identity: $UPGRADE_DIFF"
    pass 'elementor 4.0.0 -> 4.2.3 in-place upgrade preserves native rendering, regenerates CSS, and recaptures byte-identically'
  fi

  # The exact boundary must be warning-free. Keep stderr visible for normal
  # diagnostics, then reject the specific Elementor null-reference paths that
  # previously made the matrix green while human/exit status disagreed.
  ELEMENTOR_WARNING_MATCHES=$(grep -nE 'elementor/core/isolation/elementor-adapter\.php|elementor/core/base/document\.php|Elementor\\Core\\Isolation\\Elementor_Adapter' "$ELEMENTOR_STDERR_LOG" || true)
  if [ -n "$ELEMENTOR_WARNING_MATCHES" ]; then
    fail "unexpected Elementor PHP warning at exact $ELEMENTOR_VERSION boundary (captured stderr: $ELEMENTOR_STDERR_LOG):
$ELEMENTOR_WARNING_MATCHES"
  fi
  rm -f "$ELEMENTOR_STDERR_LOG"
done
fi

if [ "$VMATRIX_MANIFEST" = contact-form-7 ]; then
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
fi

if [ "$VMATRIX_MANIFEST" = polylang ]; then
VMATRIX_CASES=$((VMATRIX_CASES + 1))
for POLYLANG_VERSION in 3.5 3.8.6; do
  say "boundary: polylang $POLYLANG_VERSION"

  reset_env wp1
  reset_env wp2
  reset_case_repositories

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

  clone_case_target
  wp2 plugin install "$ARTIFACT_2" >/dev/null
  INSTALLED_2=$(wp2 plugin get polylang --field=version)
  require_fixture_values INSTALLED_2
  [ "$INSTALLED_2" = "$POLYLANG_VERSION" ] || fail "side 2 installed version mismatch: expected $POLYLANG_VERSION, got $INSTALLED_2"

  wp2 duo deploy --repo=/siterepo
  REV=$(git -C "siterepo/${PAIR}2" rev-parse HEAD)
  wp2 duo apply --repo=/siterepo --adopt-by-slug=terms,posts --default-author=admin --revision="$REV" | tee "$VMATRIX_APPLY_LOG"
  grep -q 'canary clean' "$VMATRIX_APPLY_LOG" || fail "apply canary not clean at polylang $POLYLANG_VERSION"
  pass "deploy + apply succeeded on side 2 (polylang $POLYLANG_VERSION, canary clean)"

  check_polylang_content

  wp2 duo capture --repo=/siterepo --out="/siterepo/.tmp-final"
  DIFF_OUT=$(diff -rq "siterepo/${PAIR}1/state" "siterepo/${PAIR}2/.tmp-final" || true)
  rm -rf "siterepo/${PAIR}2/.tmp-final"
  [ -z "$DIFF_OUT" ] || fail "byte-identity broken at polylang $POLYLANG_VERSION: $DIFF_OUT"
  pass "byte-identical recapture at polylang $POLYLANG_VERSION — the manifest's own declared version_range boundary is proven, not just its currently-installed version"
done

fi

# WooCommerce 11.0.0 is currently both the declared minimum and the newest
# stable release below 12.0.0. Certify it once: repeating the same artifact
# under two labels would add runtime without adding evidence.
if [ "$VMATRIX_MANIFEST" = woocommerce ]; then
VMATRIX_CASES=$((VMATRIX_CASES + 1))
for WOO_VERSION in 11.0.0; do
  say "boundary: woocommerce $WOO_VERSION (only stable in-range release; min == max-practical)"

  reset_env wp1
  reset_env wp2
  reset_case_repositories

  say "fetch + verify woocommerce $WOO_VERSION (never a bare slug install — always a digest-checked artifact)"
  ARTIFACT_1=$(fetch_artifact woocommerce "$WOO_VERSION" cli1)
  ARTIFACT_2=$(fetch_artifact woocommerce "$WOO_VERSION" cli2)
  pass "verified sha256-pinned artifact resolved for both sides: $ARTIFACT_1"

  wp1 plugin install "$ARTIFACT_1" --activate >/dev/null
  INSTALLED_1=$(wp1 plugin get woocommerce --field=version)
  [ "$INSTALLED_1" = "$WOO_VERSION" ] || fail "side 1 installed version mismatch: expected $WOO_VERSION, got $INSTALLED_1"
  wp1 wc hpos enable >/dev/null
  pass "side 1: woocommerce $WOO_VERSION installed from verified artifact, active, HPOS enabled"

  cat > "siterepo/${PAIR}1/site.duo.json" <<EOF
{
  "manifests": ["core", "woocommerce"],
  "policy": {
    "options": {},
    "post_meta": {},
    "post_types": ["post", "page", "attachment", "product", "product_variation", "shop_coupon"],
    "taxonomies": ["category", "post_tag", "product_cat", "product_type"]
  },
  "spec_version": 2
}
EOF
  cp site-repo.gitignore.template "siterepo/${PAIR}1/.gitignore"
  "${GIT1[@]}" init -q -b main
  "${GIT1[@]}" remote add origin "../origin-$PAIR.git"
  "${GIT1[@]}" add -A
  "${GIT1[@]}" commit -qm "policy: woocommerce $WOO_VERSION version-boundary certification"
  "${GIT1[@]}" push -qu origin main

  seed_woocommerce_content
  wp1 duo capture --repo=/siterepo
  pass "captured on side 1 (woocommerce $WOO_VERSION)"
  wp1 duo lint --repo=/siterepo
  pass "lint: 0 findings"

  "${GIT1[@]}" add -A
  "${GIT1[@]}" commit -qm "capture: woocommerce $WOO_VERSION content"
  "${GIT1[@]}" push -q origin main

  clone_case_target
  wp2 plugin install "$ARTIFACT_2" >/dev/null
  INSTALLED_2=$(wp2 plugin get woocommerce --field=version)
  require_fixture_values INSTALLED_2
  [ "$INSTALLED_2" = "$WOO_VERSION" ] || fail "side 2 installed version mismatch: expected $WOO_VERSION, got $INSTALLED_2"

  wp2 duo deploy --repo=/siterepo
  postdeploy_woocommerce_content
  REV=$(git -C "siterepo/${PAIR}2" rev-parse HEAD)
  wp2 duo apply --repo=/siterepo --adopt-by-slug=terms,posts --default-author=admin --revision="$REV" | tee "$VMATRIX_APPLY_LOG"
  grep -q 'canary clean' "$VMATRIX_APPLY_LOG" || fail "apply canary not clean at woocommerce $WOO_VERSION"
  pass "deploy + apply succeeded on side 2 (woocommerce $WOO_VERSION, HPOS, canary clean)"

  check_woocommerce_content

  wp2 duo capture --repo=/siterepo --out="/siterepo/.tmp-final"
  DIFF_OUT=$(diff -rq "siterepo/${PAIR}1/state" "siterepo/${PAIR}2/.tmp-final" || true)
  rm -rf "siterepo/${PAIR}2/.tmp-final"
  [ -z "$DIFF_OUT" ] || fail "byte-identity broken at woocommerce $WOO_VERSION: $DIFF_OUT"
  pass "byte-identical recapture at woocommerce $WOO_VERSION — the only currently available in-range boundary is proven without duplicate execution"
done
fi

# Yoast's published 28.x line has two real stable boundaries: 28.0 is the
# first release admitted by the manifest's exact 28.0 minimum, and 28.3 is
# the current release below 29.0.0. Exercise both exact
# artifacts; a current-slug install would prove neither boundary.
if [ "$VMATRIX_MANIFEST" = yoast ]; then
VMATRIX_CASES=$((VMATRIX_CASES + 1))
for YOAST_VERSION in 28.0 28.3; do
  say "boundary: wordpress-seo $YOAST_VERSION"

  reset_env wp1
  reset_env wp2
  reset_case_repositories

  say "fetch + verify wordpress-seo $YOAST_VERSION (never a bare slug install — always a digest-checked artifact)"
  ARTIFACT_1=$(fetch_artifact wordpress-seo "$YOAST_VERSION" cli1)
  ARTIFACT_2=$(fetch_artifact wordpress-seo "$YOAST_VERSION" cli2)
  pass "verified sha256-pinned artifact resolved for both sides: $ARTIFACT_1"

  wp1 plugin install "$ARTIFACT_1" --activate >/dev/null
  INSTALLED_1=$(wp1 plugin get wordpress-seo --field=version)
  [ "$INSTALLED_1" = "$YOAST_VERSION" ] || fail "side 1 installed version mismatch: expected $YOAST_VERSION, got $INSTALLED_1"
  pass "side 1: wordpress-seo $YOAST_VERSION installed from verified artifact, active"

  cat > "siterepo/${PAIR}1/site.duo.json" <<EOF
{
  "manifests": ["core", "yoast"],
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
  "${GIT1[@]}" commit -qm "policy: wordpress-seo $YOAST_VERSION version-boundary certification"
  "${GIT1[@]}" push -qu origin main

  seed_yoast_content
  wp1 duo capture --repo=/siterepo
  pass "captured on side 1 (wordpress-seo $YOAST_VERSION)"
  wp1 duo lint --repo=/siterepo
  pass "lint: 0 findings"

  "${GIT1[@]}" add -A
  "${GIT1[@]}" commit -qm "capture: wordpress-seo $YOAST_VERSION content"
  "${GIT1[@]}" push -q origin main

  clone_case_target
  wp2 plugin install "$ARTIFACT_2" >/dev/null
  INSTALLED_2=$(wp2 plugin get wordpress-seo --field=version)
  require_fixture_values INSTALLED_2
  [ "$INSTALLED_2" = "$YOAST_VERSION" ] || fail "side 2 installed version mismatch: expected $YOAST_VERSION, got $INSTALLED_2"

  wp2 duo deploy --repo=/siterepo
  postdeploy_yoast_content
  REV=$(git -C "siterepo/${PAIR}2" rev-parse HEAD)
  wp2 duo apply --repo=/siterepo --adopt-by-slug=terms,posts --default-author=admin --revision="$REV" 2>&1 | tee "$VMATRIX_APPLY_LOG"
  grep -q 'canary clean' "$VMATRIX_APPLY_LOG" || fail "apply canary not clean at wordpress-seo $YOAST_VERSION"
  pass "deploy + apply succeeded on side 2 (wordpress-seo $YOAST_VERSION, canary clean)"

  check_yoast_content

  wp2 duo capture --repo=/siterepo --out="/siterepo/.tmp-final"
  DIFF_OUT=$(diff -rq "siterepo/${PAIR}1/state" "siterepo/${PAIR}2/.tmp-final" || true)
  rm -rf "siterepo/${PAIR}2/.tmp-final"
  [ -z "$DIFF_OUT" ] || fail "byte-identity broken at wordpress-seo $YOAST_VERSION: $DIFF_OUT"
  pass "byte-identical recapture at wordpress-seo $YOAST_VERSION — the manifest's own declared version_range boundary is proven, not just its currently-installed version"

  if [ "$YOAST_VERSION" = 28.0 ]; then
    say 'in-place upgrade: wordpress-seo 28.0 -> 28.3 on both existing environments'
    UPGRADE_ARTIFACT_1=$(fetch_artifact wordpress-seo 28.3 cli1)
    UPGRADE_ARTIFACT_2=$(fetch_artifact wordpress-seo 28.3 cli2)
    wp1 plugin install "$UPGRADE_ARTIFACT_1" --force --activate >/dev/null
    [ "$(wp1 plugin get wordpress-seo --field=version)" = 28.3 ] \
      || fail 'Yoast source in-place upgrade did not install exact 28.3'
    wp1 duo deploy --repo=/siterepo --force-code-drift >/dev/null
    UPGRADE_POST=$(wp1 post list --post_type=post --name=conformance-yoast-post --field=ID)
    require_fixture_ids UPGRADE_POST
    wp1 post meta update "$UPGRADE_POST" _yoast_wpseo_twitter-title 'Yoast 28.0 to 28.3 upgrade 東京 🚀' >/dev/null
    wp1 duo capture --repo=/siterepo
    wp1 duo lint --repo=/siterepo
    "${GIT1[@]}" add -A
    "${GIT1[@]}" commit -qm 'capture: wordpress-seo 28.0 to 28.3 in-place upgrade'
    "${GIT1[@]}" push -q origin main
    git -C "siterepo/${PAIR}2" pull -q origin main

    wp2 plugin install "$UPGRADE_ARTIFACT_2" --force --activate >/dev/null
    [ "$(wp2 plugin get wordpress-seo --field=version)" = 28.3 ] \
      || fail 'Yoast target in-place upgrade did not install exact 28.3'
    wp2 duo deploy --repo=/siterepo --force-code-drift
    REV=$(git -C "siterepo/${PAIR}2" rev-parse HEAD)
    wp2 duo apply --repo=/siterepo --default-author=admin --revision="$REV" 2>&1 | tee "$VMATRIX_APPLY_LOG"
    grep -q 'canary clean' "$VMATRIX_APPLY_LOG" \
      || fail 'apply canary not clean after wordpress-seo 28.0 to 28.3 in-place upgrade'
    SAVED_YOAST_VERSION="$YOAST_VERSION"
    YOAST_VERSION=28.3
    check_yoast_content
    YOAST_VERSION="$SAVED_YOAST_VERSION"

    wp2 duo capture --repo=/siterepo --out=/siterepo/.tmp-upgraded-final
    UPGRADE_DIFF=$(diff -rq "siterepo/${PAIR}1/state" "siterepo/${PAIR}2/.tmp-upgraded-final" || true)
    rm -rf "siterepo/${PAIR}2/.tmp-upgraded-final"
    [ -z "$UPGRADE_DIFF" ] || fail "Yoast 28.0 to 28.3 in-place upgrade lost byte identity: $UPGRADE_DIFF"
    pass 'wordpress-seo 28.0 -> 28.3 in-place upgrade preserves native behavior, reindexes, and recaptures byte-identically'
  fi
done
fi

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
if [ "$VMATRIX_MANIFEST" = advanced-editor-tools ]; then
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
fi

if [ "$VMATRIX_MANIFEST" = classic-editor ]; then
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
fi

if [ "$VMATRIX_MANIFEST" = code-snippets ]; then
say "negative control: code-snippets 3.9.4 (adjacent official release below the 3.9.5 contract) must be REFUSED"
reset_env wp1
reset_case_repositories

CS_IN_RANGE=$(fetch_artifact code-snippets 3.9.5 cli1)
wp1 plugin install "$CS_IN_RANGE" --activate >/dev/null
NEGATIVE_INSTALLED=$(wp1 plugin get code-snippets --field=version)
require_fixture_values NEGATIVE_INSTALLED
[ "$NEGATIVE_INSTALLED" = 3.9.5 ] \
  || fail "negative control premise did not install exact code-snippets 3.9.5 bytes"
cat > "siterepo/${PAIR}1/site.duo.json" <<'EOF'
{
  "manifests": ["core", "code-snippets"],
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
"${GIT1[@]}" commit -qm "policy: Code Snippets negative-control pin"
"${GIT1[@]}" push -qu origin main
seed_code_snippets_content
wp1 duo capture --repo=/siterepo
"${GIT1[@]}" add -A
"${GIT1[@]}" commit -qm "capture: valid Code Snippets state for negative control"
"${GIT1[@]}" push -q origin main

wp1 plugin deactivate code-snippets >/dev/null
wp1 plugin delete code-snippets >/dev/null
CS_OUT_OF_RANGE=$(fetch_artifact code-snippets 3.9.4 cli1)
wp1 plugin install "$CS_OUT_OF_RANGE" >/dev/null
INSTALLED_OOR=$(wp1 plugin get code-snippets --field=version)
[ "$INSTALLED_OOR" = 3.9.4 ] \
  || fail "negative control: expected code-snippets 3.9.4 installed, got $INSTALLED_OOR"
CS_REFUSAL_BEFORE=$(wp1 eval '
  global $wpdb;
  $rows=$wpdb->get_results("SELECT * FROM {$wpdb->prefix}snippets ORDER BY id", ARRAY_A);
  $root=WP_CONTENT_DIR . "/code-snippets"; $tree=[];
  if (is_dir($root)) foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $f) if ($f->isFile()) $tree[substr($f->getPathname(), strlen($root)+1)]=hash_file("sha256", $f->getPathname());
  ksort($tree); echo hash("sha256", wp_json_encode([$rows,get_option("code_snippets_settings",null),get_option("code_snippets_version",null),$tree]));
')
require_observed_nonempty "Code Snippets refusal state baseline" "$CS_REFUSAL_BEFORE"
set +e
DEPLOY_OUT=$(wp1 duo deploy --repo=/siterepo 2>&1)
DEPLOY_RC=$?
set -e
[ "$DEPLOY_RC" -ne 0 ] \
  || fail "expected deploy to refuse code-snippets 3.9.4, but it exited 0 (got: $DEPLOY_OUT)"
grep -Eq "outside_version_range|outside the '.*' manifest's declared version_range" <<<"$DEPLOY_OUT" \
  || fail "code-snippets 3.9.4 refused for the wrong reason (got: $DEPLOY_OUT)"
grep -q 'code-snippets/code-snippets.php' <<<"$DEPLOY_OUT" \
  || fail "Code Snippets refusal did not name the exact basename (got: $DEPLOY_OUT)"
grep -q '3.9.4' <<<"$DEPLOY_OUT" \
  || fail "Code Snippets refusal did not name installed version 3.9.4 (got: $DEPLOY_OUT)"
if wp1 plugin is-active code-snippets >/dev/null 2>&1; then
  fail "outside-range code-snippets 3.9.4 was activated before refusal"
fi
CS_REFUSAL_AFTER=$(wp1 eval '
  global $wpdb;
  $rows=$wpdb->get_results("SELECT * FROM {$wpdb->prefix}snippets ORDER BY id", ARRAY_A);
  $root=WP_CONTENT_DIR . "/code-snippets"; $tree=[];
  if (is_dir($root)) foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $f) if ($f->isFile()) $tree[substr($f->getPathname(), strlen($root)+1)]=hash_file("sha256", $f->getPathname());
  ksort($tree); echo hash("sha256", wp_json_encode([$rows,get_option("code_snippets_settings",null),get_option("code_snippets_version",null),$tree]));
')
[ "$CS_REFUSAL_AFTER" = "$CS_REFUSAL_BEFORE" ] \
  || fail "Code Snippets outside-range refusal mutated table, settings, or flat-file state"
printf '%s\n' "$DEPLOY_OUT"
pass "official code-snippets 3.9.4 is loudly refused, remains inactive, and cannot mutate table/settings/flat files"
fi

if [ "$VMATRIX_MANIFEST" = wps-hide-login ]; then
say "negative control: wps-hide-login 1.9.18 (adjacent official release below the exact 1.9.19 contract) must be REFUSED"
reset_env wp1
reset_case_repositories

WPS_IN_RANGE=$(fetch_artifact wps-hide-login 1.9.19 cli1)
wp1 plugin install "$WPS_IN_RANGE" --activate >/dev/null
NEGATIVE_INSTALLED=$(wp1 plugin get wps-hide-login --field=version)
require_fixture_values NEGATIVE_INSTALLED
[ "$NEGATIVE_INSTALLED" = "1.9.19" ] \
  || fail "negative control premise did not install exact wps-hide-login 1.9.19 bytes"
cat > "siterepo/${PAIR}1/site.duo.json" <<'EOF'
{
  "manifests": ["core", "wps-hide-login"],
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
"${GIT1[@]}" commit -qm "policy: WPS Hide Login negative-control pin"
"${GIT1[@]}" push -qu origin main
seed_wps_hide_login_content
wp1 duo capture --repo=/siterepo
"${GIT1[@]}" add -A
"${GIT1[@]}" commit -qm "capture: valid WPS Hide Login state for negative control"
"${GIT1[@]}" push -q origin main

wp1 plugin deactivate wps-hide-login >/dev/null
wp1 plugin delete wps-hide-login >/dev/null
WPS_OUT_OF_RANGE=$(fetch_artifact wps-hide-login 1.9.18 cli1)
wp1 plugin install "$WPS_OUT_OF_RANGE" >/dev/null
INSTALLED_OOR=$(wp1 plugin get wps-hide-login --field=version)
[ "$INSTALLED_OOR" = "1.9.18" ] \
  || fail "negative control: expected wps-hide-login 1.9.18 installed, got $INSTALLED_OOR"
WPS_REFUSAL_BEFORE=$(wp1 eval 'echo hash("sha256", maybe_serialize([get_option("whl_page", null), get_option("whl_redirect_admin", null), get_option("rewrite_rules")]));')
require_observed_nonempty "WPS Hide Login refusal state baseline" "$WPS_REFUSAL_BEFORE"
set +e
DEPLOY_OUT=$(wp1 duo deploy --repo=/siterepo 2>&1)
DEPLOY_RC=$?
set -e
[ "$DEPLOY_RC" -ne 0 ] \
  || fail "expected deploy to refuse wps-hide-login 1.9.18, but it exited 0 (got: $DEPLOY_OUT)"
grep -Eq "outside_version_range|outside the '.*' manifest's declared version_range" <<<"$DEPLOY_OUT" \
  || fail "wps-hide-login 1.9.18 refused for the wrong reason (got: $DEPLOY_OUT)"
grep -q "wps-hide-login/wps-hide-login.php" <<<"$DEPLOY_OUT" \
  || fail "WPS Hide Login refusal did not name the exact basename (got: $DEPLOY_OUT)"
grep -q "1.9.18" <<<"$DEPLOY_OUT" \
  || fail "WPS Hide Login refusal did not name installed version 1.9.18 (got: $DEPLOY_OUT)"
if wp1 plugin is-active wps-hide-login >/dev/null 2>&1; then
  fail "outside-range wps-hide-login 1.9.18 was activated before refusal"
fi
WPS_REFUSAL_AFTER=$(wp1 eval 'echo hash("sha256", maybe_serialize([get_option("whl_page", null), get_option("whl_redirect_admin", null), get_option("rewrite_rules")]));')
[ "$WPS_REFUSAL_AFTER" = "$WPS_REFUSAL_BEFORE" ] \
  || fail "WPS Hide Login outside-range refusal mutated authored settings or rewrite bytes"
printf '%s\n' "$DEPLOY_OUT"
pass "official wps-hide-login 1.9.18 is loudly refused, remains inactive, and cannot mutate admitted settings or rewrite state"
fi

if [ "$VMATRIX_MANIFEST" = yoast-duplicate-post ]; then
say "negative control: duplicate-post 4.6 (adjacent official release below the exact 4.7 contract) must be REFUSED"
reset_env wp1
reset_case_repositories

YDP_IN_RANGE=$(fetch_artifact duplicate-post 4.7 cli1)
wp1 plugin install "$YDP_IN_RANGE" --activate >/dev/null
NEGATIVE_INSTALLED=$(wp1 plugin get duplicate-post --field=version)
require_fixture_values NEGATIVE_INSTALLED
[ "$NEGATIVE_INSTALLED" = 4.7 ] \
  || fail "negative control premise did not install exact duplicate-post 4.7 bytes"
cat > "siterepo/${PAIR}1/site.duo.json" <<'EOF'
{
  "manifests": ["core", "yoast-duplicate-post"],
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
"${GIT1[@]}" commit -qm "policy: Yoast Duplicate Post negative-control pin"
"${GIT1[@]}" push -qu origin main
seed_yoast_duplicate_post_content
wp1 duo capture --repo=/siterepo
"${GIT1[@]}" add -A
"${GIT1[@]}" commit -qm "capture: valid Yoast Duplicate Post state for negative control"
"${GIT1[@]}" push -q origin main

wp1 plugin deactivate duplicate-post >/dev/null
wp1 plugin delete duplicate-post >/dev/null
YDP_OUT_OF_RANGE=$(fetch_artifact duplicate-post 4.6 cli1)
wp1 plugin install "$YDP_OUT_OF_RANGE" >/dev/null
INSTALLED_OOR=$(wp1 plugin get duplicate-post --field=version)
[ "$INSTALLED_OOR" = 4.6 ] \
  || fail "negative control: expected duplicate-post 4.6 installed, got $INSTALLED_OOR"
YDP_REFUSAL_BEFORE=$(wp1 eval '
  $o=get_page_by_path("duo-duplicate-original", OBJECT, "post");
  $c=get_page_by_path("duo-duplicate-copy", OBJECT, "post");
  $roles=[]; foreach (["administrator","duo_reviewer","editor","subscriber"] as $name) { $r=get_role($name); $roles[$name]=$r ? $r->has_cap("copy_posts") : null; }
  echo hash("sha256", wp_json_encode([get_option("duplicate_post_title_prefix",null),get_option("duplicate_post_roles",null),$o?$o->post_content:null,$c?get_post_meta($c->ID,"_dp_original",true):null,$roles]));
')
require_observed_nonempty "Yoast Duplicate Post refusal state baseline" "$YDP_REFUSAL_BEFORE"
set +e
DEPLOY_OUT=$(wp1 duo deploy --repo=/siterepo 2>&1)
DEPLOY_RC=$?
set -e
[ "$DEPLOY_RC" -ne 0 ] \
  || fail "expected deploy to refuse duplicate-post 4.6, but it exited 0 (got: $DEPLOY_OUT)"
grep -Eq "outside_version_range|outside the '.*' manifest's declared version_range" <<<"$DEPLOY_OUT" \
  || fail "duplicate-post 4.6 refused for the wrong reason (got: $DEPLOY_OUT)"
grep -q 'duplicate-post/duplicate-post.php' <<<"$DEPLOY_OUT" \
  || fail "Yoast Duplicate Post refusal did not name the exact basename (got: $DEPLOY_OUT)"
grep -q '4.6' <<<"$DEPLOY_OUT" \
  || fail "Yoast Duplicate Post refusal did not name installed version 4.6 (got: $DEPLOY_OUT)"
if wp1 plugin is-active duplicate-post >/dev/null 2>&1; then
  fail "outside-range duplicate-post 4.6 was activated before refusal"
fi
YDP_REFUSAL_AFTER=$(wp1 eval '
  $o=get_page_by_path("duo-duplicate-original", OBJECT, "post");
  $c=get_page_by_path("duo-duplicate-copy", OBJECT, "post");
  $roles=[]; foreach (["administrator","duo_reviewer","editor","subscriber"] as $name) { $r=get_role($name); $roles[$name]=$r ? $r->has_cap("copy_posts") : null; }
  echo hash("sha256", wp_json_encode([get_option("duplicate_post_title_prefix",null),get_option("duplicate_post_roles",null),$o?$o->post_content:null,$c?get_post_meta($c->ID,"_dp_original",true):null,$roles]));
')
[ "$YDP_REFUSAL_AFTER" = "$YDP_REFUSAL_BEFORE" ] \
  || fail "Yoast Duplicate Post outside-range refusal mutated settings/posts/references/roles"
printf '%s\n' "$DEPLOY_OUT"
pass "official duplicate-post 4.6 is loudly refused, remains inactive, and cannot mutate admitted state"
fi

if [ "$VMATRIX_MANIFEST" = acf ]; then
say "negative control: acf 5.12.6 (real wp.org release, genuinely below manifests/acf.json's own declared min 6.0.0) must be REFUSED, not silently accepted"
reset_env wp1
reset_case_repositories

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
fi

if [ "$VMATRIX_MANIFEST" = contact-form-7 ]; then
say "negative control: contact-form-7 5.9.8 (real wp.org release, genuinely below manifests/contact-form-7.json's own declared min 6.0) must be REFUSED, not silently accepted"
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
fi

if [ "$VMATRIX_MANIFEST" = elementor ]; then
say "negative control: elementor 3.35.9 (real wp.org release, genuinely below manifests/elementor.json's own declared min 4.0.0) must be REFUSED, not silently accepted"
reset_env wp1
reset_case_repositories

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
    "taxonomies": ["category", "post_tag", "elementor_library_type"]
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
fi

if [ "$VMATRIX_MANIFEST" = ninja-forms ]; then
say "negative control: ninja-forms 3.3.21.4 (real wp.org release, genuinely below manifests/ninja-forms.json's corrected min 3.4.34.2) must be REFUSED, not silently accepted"
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
fi

if [ "$VMATRIX_MANIFEST" = paid-memberships-pro ]; then
say "negative controls: adjacent official PMPro tags 3.8.1 and 3.8.4 must both be refused by the audited 3.8.2/3.8.3 contract"
reset_env wp1
reset_case_repositories

# Capture valid canonical state with admitted bytes, then replace only the
# installed plugin. Both refusals therefore exercise Deploy::code_mismatch()
# against real PMPro table/reference content rather than an empty repository.
IN_RANGE_ARTIFACT=$(fetch_artifact paid-memberships-pro 3.8.3 cli1)
wp1 plugin install "$IN_RANGE_ARTIFACT" >/dev/null
normalize_version_matrix_archive_root cli1 plugin paid-memberships-pro paid-memberships-pro-3.8.3
wp1 plugin activate paid-memberships-pro >/dev/null
NEGATIVE_INSTALLED=$(wp1 plugin get paid-memberships-pro --field=version)
require_fixture_values NEGATIVE_INSTALLED
[ "$NEGATIVE_INSTALLED" = "3.8.3" ] \
  || fail "negative control premise did not install exact paid-memberships-pro 3.8.3 bytes"
cat > "siterepo/${PAIR}1/site.duo.json" <<'EOF'
{
  "manifests": ["core", "paid-memberships-pro"],
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
"${GIT1[@]}" commit -qm "policy: paid-memberships-pro negative-control pin"
"${GIT1[@]}" push -qu origin main
seed_pmpro_content
wp1 duo capture --repo=/siterepo
"${GIT1[@]}" add -A
"${GIT1[@]}" commit -qm "capture: valid PMPro state for adjacent-version refusals"
"${GIT1[@]}" push -q origin main

say "negative control: missing PMPro code refuses before lifecycle mutation"
wp1 plugin deactivate paid-memberships-pro >/dev/null 2>&1 || true
wp1 plugin delete paid-memberships-pro >/dev/null
set +e
DEPLOY_OUT=$(wp1 duo deploy --repo=/siterepo 2>&1)
DEPLOY_RC=$?
set -e
[ "$DEPLOY_RC" -ne 0 ] || fail "expected deploy to refuse missing PMPro code, but it exited 0 (got: $DEPLOY_OUT)"
grep -Eq "code_mismatch|missing_in_code|is not installed" <<<"$DEPLOY_OUT" \
  || fail "missing PMPro refusal did not name the compatibility gate (got: $DEPLOY_OUT)"
grep -q "paid-memberships-pro/paid-memberships-pro.php" <<<"$DEPLOY_OUT" \
  || fail "missing PMPro refusal did not name the exact expected basename (got: $DEPLOY_OUT)"
if wp1 plugin is-installed paid-memberships-pro >/dev/null 2>&1; then
  fail "missing-code refusal installed PMPro before returning"
fi
pass "confirmed: absent PMPro code is refused and remains absent"

say "negative control: the right exact bytes under the wrong basename refuse rather than being guessed"
WRONG_BASENAME_ARTIFACT=$(fetch_artifact paid-memberships-pro 3.8.3 cli1)
wp1 plugin install "$WRONG_BASENAME_ARTIFACT" >/dev/null
WRONG_BASENAME_VERSION=$(wp1 plugin get paid-memberships-pro-3.8.3 --field=version)
[ "$WRONG_BASENAME_VERSION" = "3.8.3" ] \
  || fail "wrong-basename premise did not install official 3.8.3 bytes under the archive root"
set +e
DEPLOY_OUT=$(wp1 duo deploy --repo=/siterepo 2>&1)
DEPLOY_RC=$?
set -e
[ "$DEPLOY_RC" -ne 0 ] || fail "expected deploy to refuse PMPro under the wrong basename, but it exited 0 (got: $DEPLOY_OUT)"
grep -Eq "code_mismatch|missing_in_code|is not installed" <<<"$DEPLOY_OUT" \
  || fail "wrong-basename PMPro refusal did not name the compatibility gate (got: $DEPLOY_OUT)"
grep -q "paid-memberships-pro/paid-memberships-pro.php" <<<"$DEPLOY_OUT" \
  || fail "wrong-basename PMPro refusal did not name the exact expected basename (got: $DEPLOY_OUT)"
wp1 plugin is-installed paid-memberships-pro-3.8.3 >/dev/null \
  || fail "wrong-basename refusal rewrote or removed the installed upstream directory"
if wp1 plugin is-active paid-memberships-pro-3.8.3 >/dev/null 2>&1; then
  fail "wrong-basename refusal activated unrecognized PMPro code"
fi
pass "confirmed: exact PMPro bytes under paid-memberships-pro-3.8.3 remain inactive and are never accepted as the canonical basename"
wp1 plugin delete paid-memberships-pro-3.8.3 >/dev/null

say "negative control: an unreadable exact main file refuses before activation"
UNREADABLE_ARTIFACT=$(fetch_artifact paid-memberships-pro 3.8.3 cli1)
wp1 plugin install "$UNREADABLE_ARTIFACT" >/dev/null
normalize_version_matrix_archive_root cli1 plugin paid-memberships-pro paid-memberships-pro-3.8.3
"${PAIR_COMPOSE[@]}" run --rm -T cli1 sh -c 'chmod 000 /var/www/html/wp-content/plugins/paid-memberships-pro/paid-memberships-pro.php'
set +e
DEPLOY_OUT=$(wp1 duo deploy --repo=/siterepo 2>&1)
DEPLOY_RC=$?
set -e
"${PAIR_COMPOSE[@]}" run --rm -T cli1 sh -c 'chmod 0644 /var/www/html/wp-content/plugins/paid-memberships-pro/paid-memberships-pro.php'
[ "$DEPLOY_RC" -ne 0 ] || fail "expected deploy to refuse unreadable PMPro code, but it exited 0 (got: $DEPLOY_OUT)"
grep -Eq "code_mismatch|outside_version_range|unknown version|is not installed" <<<"$DEPLOY_OUT" \
  || fail "unreadable PMPro refusal did not name the compatibility gate (got: $DEPLOY_OUT)"
if wp1 plugin is-active paid-memberships-pro >/dev/null 2>&1; then
  fail "unreadable-plugin refusal activated PMPro before returning"
fi
pass "confirmed: unreadable PMPro main-file metadata refuses and remains inactive"
wp1 plugin delete paid-memberships-pro >/dev/null

for OUT_OF_RANGE_VERSION in 3.8.1 3.8.4; do
  wp1 plugin deactivate paid-memberships-pro >/dev/null 2>&1 || true
  wp1 plugin delete paid-memberships-pro >/dev/null
  OUT_OF_RANGE_ARTIFACT=$(fetch_artifact paid-memberships-pro "$OUT_OF_RANGE_VERSION" cli1)
  wp1 plugin install "$OUT_OF_RANGE_ARTIFACT" >/dev/null
  normalize_version_matrix_archive_root cli1 plugin paid-memberships-pro "paid-memberships-pro-$OUT_OF_RANGE_VERSION"
  INSTALLED_OOR=$(wp1 plugin get paid-memberships-pro --field=version)
  [ "$INSTALLED_OOR" = "$OUT_OF_RANGE_VERSION" ] \
    || fail "negative control: expected paid-memberships-pro $OUT_OF_RANGE_VERSION installed, got $INSTALLED_OOR"

  set +e
  DEPLOY_OUT=$(wp1 duo deploy --repo=/siterepo 2>&1)
  DEPLOY_RC=$?
  set -e
  [ "$DEPLOY_RC" -ne 0 ] \
    || fail "expected deploy to refuse paid-memberships-pro $OUT_OF_RANGE_VERSION as outside_version_range, but it exited 0 (got: $DEPLOY_OUT)"
  grep -Eq "outside_version_range|outside the '.*' manifest's declared version_range" <<<"$DEPLOY_OUT" \
    || fail "deploy refused PMPro $OUT_OF_RANGE_VERSION, but not for outside_version_range (got: $DEPLOY_OUT)"
  grep -q "paid-memberships-pro/paid-memberships-pro.php" <<<"$DEPLOY_OUT" \
    || fail "PMPro refusal did not name the exact plugin basename (got: $DEPLOY_OUT)"
  grep -q "$OUT_OF_RANGE_VERSION" <<<"$DEPLOY_OUT" \
    || fail "PMPro refusal did not name installed version $OUT_OF_RANGE_VERSION (got: $DEPLOY_OUT)"
  if wp1 plugin is-active paid-memberships-pro >/dev/null 2>&1; then
    fail "outside-range PMPro $OUT_OF_RANGE_VERSION was activated before deploy refused"
  fi
  printf '%s\n' "$DEPLOY_OUT"
  pass "confirmed: official PMPro $OUT_OF_RANGE_VERSION is loudly refused and remains inactive outside exact range >=3.8.2 <3.8.4"
done
fi

if [ "$VMATRIX_MANIFEST" = polylang ]; then
say "negative control: polylang 3.4.5 (real wp.org release, genuinely below manifests/polylang.json's corrected min 3.5) must be REFUSED, not silently accepted"
reset_env wp1
reset_case_repositories

# Build valid canonical state at the certified upper boundary, then replace
# only the installed plugin bytes. The refusal therefore proves the version
# gate against a real Polylang state tree rather than an empty repository.
IN_RANGE_ARTIFACT=$(fetch_artifact polylang 3.8.6 cli1)
wp1 plugin install "$IN_RANGE_ARTIFACT" --activate >/dev/null
NEGATIVE_INSTALLED=$(wp1 plugin get polylang --field=version)
require_fixture_values NEGATIVE_INSTALLED
[ "$NEGATIVE_INSTALLED" = "3.8.6" ] \
  || fail "negative control premise did not install exact polylang 3.8.6 bytes"
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

fi

if [ "$VMATRIX_MANIFEST" = woocommerce ]; then
say "negative control: woocommerce 10.9.4 (real wp.org release, closest stable below manifests/woocommerce.json's min 11.0.0) must be REFUSED, not silently accepted"
reset_env wp1
reset_case_repositories

# Build a valid, representative WooCommerce state tree with the admitted
# 11.0.0 artifact, then swap only the installed code to 10.9.4. This keeps
# the negative control focused on Deploy::code_mismatch(), not installer
# or old-schema behavior outside the manifest's claim.
IN_RANGE_ARTIFACT=$(fetch_artifact woocommerce 11.0.0 cli1)
wp1 plugin install "$IN_RANGE_ARTIFACT" --activate >/dev/null
NEGATIVE_INSTALLED=$(wp1 plugin get woocommerce --field=version)
require_fixture_values NEGATIVE_INSTALLED
[ "$NEGATIVE_INSTALLED" = "11.0.0" ] \
  || fail "negative control premise did not install exact woocommerce 11.0.0 bytes"
wp1 wc hpos enable >/dev/null
cat > "siterepo/${PAIR}1/site.duo.json" <<'EOF'
{
  "manifests": ["core", "woocommerce"],
  "policy": {
    "options": {},
    "post_meta": {},
    "post_types": ["post", "page", "attachment", "product", "product_variation", "shop_coupon"],
    "taxonomies": ["category", "post_tag", "product_cat", "product_type"]
  },
  "spec_version": 2
}
EOF
cp site-repo.gitignore.template "siterepo/${PAIR}1/.gitignore"
"${GIT1[@]}" init -q -b main
"${GIT1[@]}" remote add origin "../origin-$PAIR.git"
"${GIT1[@]}" add -A
"${GIT1[@]}" commit -qm "policy: woocommerce negative-control pin"
"${GIT1[@]}" push -qu origin main
seed_woocommerce_content
wp1 duo capture --repo=/siterepo
"${GIT1[@]}" add -A
"${GIT1[@]}" commit -qm "capture: valid WooCommerce state for negative control"
"${GIT1[@]}" push -q origin main

wp1 plugin deactivate woocommerce >/dev/null
wp1 plugin delete woocommerce >/dev/null
OUT_OF_RANGE_ARTIFACT=$(fetch_artifact woocommerce 10.9.4 cli1)
wp1 plugin install "$OUT_OF_RANGE_ARTIFACT" >/dev/null
INSTALLED_OOR=$(wp1 plugin get woocommerce --field=version)
[ "$INSTALLED_OOR" = "10.9.4" ] || fail "negative control: expected woocommerce 10.9.4 installed, got $INSTALLED_OOR"

set +e
DEPLOY_OUT=$(wp1 duo deploy --repo=/siterepo 2>&1)
DEPLOY_RC=$?
set -e
[ "$DEPLOY_RC" -ne 0 ] || fail "expected deploy to refuse woocommerce 10.9.4 as outside_version_range, but it exited 0 (got: $DEPLOY_OUT)"
grep -Eq "outside_version_range|outside the '.*' manifest's declared version_range" <<<"$DEPLOY_OUT" \
  || fail "deploy refused, but not for the expected outside_version_range reason (got: $DEPLOY_OUT)"
grep -q "woocommerce/woocommerce.php" <<<"$DEPLOY_OUT" || fail "refusal did not name the plugin (got: $DEPLOY_OUT)"
grep -q "10.9.4" <<<"$DEPLOY_OUT" || fail "refusal did not name the actually-installed version (got: $DEPLOY_OUT)"
printf '%s\n' "$DEPLOY_OUT"
pass "confirmed: woocommerce 10.9.4 (real, installed, closest stable below the declared min) is loudly refused by Deploy::code_mismatch() — the version_range pin is honest, not decorative"
fi

if [ "$VMATRIX_MANIFEST" = yoast ]; then
say "negative control: wordpress-seo 27.9 (real wp.org release, closest stable below manifests/yoast.json's min 28.0) must be REFUSED, not silently accepted"
reset_env wp1
reset_case_repositories

# Capture a valid admitted 28.0 state, then replace only the installed code
# with 27.9. That isolates Deploy::code_mismatch() from unsupported old-code
# seed/schema behavior and proves the manifest boundary itself is enforced.
IN_RANGE_ARTIFACT=$(fetch_artifact wordpress-seo 28.0 cli1)
wp1 plugin install "$IN_RANGE_ARTIFACT" --activate >/dev/null
NEGATIVE_INSTALLED=$(wp1 plugin get wordpress-seo --field=version)
require_fixture_values NEGATIVE_INSTALLED
[ "$NEGATIVE_INSTALLED" = "28.0" ] \
  || fail "negative control premise did not install exact wordpress-seo 28.0 bytes"
cat > "siterepo/${PAIR}1/site.duo.json" <<'EOF'
{
  "manifests": ["core", "yoast"],
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
"${GIT1[@]}" commit -qm "policy: wordpress-seo negative-control pin"
"${GIT1[@]}" push -qu origin main
seed_yoast_content
wp1 duo capture --repo=/siterepo
"${GIT1[@]}" add -A
"${GIT1[@]}" commit -qm "capture: valid Yoast state for negative control"
"${GIT1[@]}" push -q origin main

wp1 plugin deactivate wordpress-seo >/dev/null
wp1 plugin delete wordpress-seo >/dev/null
OUT_OF_RANGE_ARTIFACT=$(fetch_artifact wordpress-seo 27.9 cli1)
wp1 plugin install "$OUT_OF_RANGE_ARTIFACT" >/dev/null
INSTALLED_OOR=$(wp1 plugin get wordpress-seo --field=version)
[ "$INSTALLED_OOR" = "27.9" ] || fail "negative control: expected wordpress-seo 27.9 installed, got $INSTALLED_OOR"

set +e
DEPLOY_OUT=$(wp1 duo deploy --repo=/siterepo 2>&1)
DEPLOY_RC=$?
set -e
[ "$DEPLOY_RC" -ne 0 ] || fail "expected deploy to refuse wordpress-seo 27.9 as outside_version_range, but it exited 0 (got: $DEPLOY_OUT)"
grep -Eq "outside_version_range|outside the '.*' manifest's declared version_range" <<<"$DEPLOY_OUT" \
  || fail "deploy refused, but not for the expected outside_version_range reason (got: $DEPLOY_OUT)"
grep -q "wordpress-seo/wp-seo.php" <<<"$DEPLOY_OUT" || fail "refusal did not name the plugin (got: $DEPLOY_OUT)"
grep -q "27.9" <<<"$DEPLOY_OUT" || fail "refusal did not name the actually-installed version (got: $DEPLOY_OUT)"
printf '%s\n' "$DEPLOY_OUT"
pass "confirmed: wordpress-seo 27.9 (real, installed, closest stable below the declared min) is loudly refused by Deploy::code_mismatch() — the version_range pin is honest, not decorative"
fi

[ "$VMATRIX_CASES" -gt 0 ] \
  || fail "no exact-artifact matrix fixture is implemented for '$VMATRIX_MANIFEST'"

say "cleanup"
bash bin/pair.sh destroy "$PAIR"
pass "destroyed $PAIR (every assertion above passed)"

printf '\n\033[1;32m✔ CERTIFY_VERSION_MATRIX PASSED\033[0m\n'
