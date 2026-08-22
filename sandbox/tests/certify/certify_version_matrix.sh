#!/usr/bin/env bash
# Certify version-boundary matrix (DUO-3223's own last remaining piece,
# unblocked by the owner ruling on artifact sourcing — issue comment
# 0ec1d2e3). No existing conformance/grind fixture installs a plugin at
# anything other than "whatever wp.org currently serves for this slug" —
# this is the first proof that a manifest's own declared version_range is
# backed by real evidence at ITS OWN edges, not just the one version every
# other fixture happens to exercise.
#
# First eight real plugins: ACF, Contact Form 7, Elementor, Ninja Forms,
# Paid Memberships Pro, Polylang, WooCommerce, and Yoast SEO. ACF proved the artifact-sourcing
# mechanism itself; the others prove the matrix accepts genuinely different
# plugin content shapes rather than replaying one ACF fixture. This closes the
# last pinned-manifest boundary that DUO-3223 had explicitly scope-accounted.
#
# For EACH boundary version (ACF 6.0.0/6.8.7; CF7 6.0.1/6.1.6; Elementor
# 4.0.0/4.2.2; Ninja Forms 3.4.34.2/3.14.11; PMPro 3.8.3 (with
# adjacent official-tag refusals); Polylang 3.5/3.8.6;
# WooCommerce 11.0.0 (the only stable in-range 11.x release); Yoast SEO
# 28.0/28.2 — all real
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
  local CONF1_PORT="$PORT1"
  local CONF2_PORT="$PORT2"
  . conformance/checks/yoast.sh
}

seed_pmpro_content() {
  wp_conf1() { wp1 "$@"; }
  local CONF_REPO1="siterepo/${PAIR}1"
  local COMPOSE="$PAIR_COMPOSE_STRING"
  . conformance/seeds/paid-memberships-pro.sh
  unset -f wp_conf1
}

check_pmpro_content() {
  local COMPOSE="$PAIR_COMPOSE_STRING"
  . conformance/checks/paid-memberships-pro.sh
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
  local plugin
  for plugin in advanced-custom-fields contact-form-7 elementor ninja-forms paid-memberships-pro polylang woocommerce wordpress-seo; do
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

# PMPro is no longer distributed through wp.org. Its supported interval is
# intentionally one exact upstream GitHub tag: 3.8.3 <= version < 3.8.4.
# Certifying that tag once is the complete positive boundary; the two adjacent
# real tags are exercised as separate refusal controls below.
if [ "$VMATRIX_MANIFEST" = paid-memberships-pro ]; then
VMATRIX_CASES=$((VMATRIX_CASES + 1))
PMPRO_VERSION=3.8.3
say "boundary: paid-memberships-pro $PMPRO_VERSION (single admitted upstream tag)"

reset_env wp1
reset_env wp2
reset_case_repositories

say "fetch + verify paid-memberships-pro $PMPRO_VERSION from the official upstream tag"
ARTIFACT_1=$(fetch_artifact paid-memberships-pro "$PMPRO_VERSION" cli1)
ARTIFACT_2=$(fetch_artifact paid-memberships-pro "$PMPRO_VERSION" cli2)
pass "verified sha256-pinned upstream artifact resolved for both sides: $ARTIFACT_1"

wp1 plugin install "$ARTIFACT_1" >/dev/null
normalize_version_matrix_archive_root cli1 plugin paid-memberships-pro paid-memberships-pro-3.8.3
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
# Target starts with the exact plugin present but inactive. Deploy must both
# accept its basename/version and perform the declared activation lifecycle.
wp2 plugin install "$ARTIFACT_2" >/dev/null
normalize_version_matrix_archive_root cli2 plugin paid-memberships-pro paid-memberships-pro-3.8.3
INSTALLED_2=$(wp2 plugin get paid-memberships-pro --field=version)
require_fixture_values INSTALLED_2
[ "$INSTALLED_2" = "$PMPRO_VERSION" ] || fail "side 2 installed version mismatch: expected $PMPRO_VERSION, got $INSTALLED_2"
wp2 plugin is-inactive paid-memberships-pro >/dev/null || fail "PMPro target premise must begin inactive"

wp2 duo deploy --repo=/siterepo
wp2 plugin is-active paid-memberships-pro >/dev/null || fail "deploy did not activate the admitted PMPro artifact"
REV=$(git -C "siterepo/${PAIR}2" rev-parse HEAD)
wp2 duo apply --repo=/siterepo --adopt-by-slug=terms,posts --default-author=admin --revision="$REV" | tee "$VMATRIX_APPLY_LOG"
grep -q 'canary clean' "$VMATRIX_APPLY_LOG" || fail "apply canary not clean at paid-memberships-pro $PMPRO_VERSION"
pass "deploy + apply succeeded on side 2 (paid-memberships-pro $PMPRO_VERSION, inactive-to-active lifecycle, canary clean)"

check_pmpro_content

wp2 duo capture --repo=/siterepo --out="/siterepo/.tmp-final"
DIFF_OUT=$(diff -rq "siterepo/${PAIR}1/state" "siterepo/${PAIR}2/.tmp-final" || true)
rm -rf "siterepo/${PAIR}2/.tmp-final"
[ -z "$DIFF_OUT" ] || fail "byte-identity broken at paid-memberships-pro $PMPRO_VERSION: $DIFF_OUT"
pass "byte-identical recapture at paid-memberships-pro $PMPRO_VERSION — exact upstream artifact, lifecycle, table references, and plugin API are bound together"
fi

if [ "$VMATRIX_MANIFEST" = elementor ]; then
VMATRIX_CASES=$((VMATRIX_CASES + 1))
for ELEMENTOR_VERSION in 4.0.0 4.2.2; do
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
  REV=$(git -C "siterepo/${PAIR}2" rev-parse HEAD)
  run_elementor_command wp2 duo apply --repo=/siterepo --adopt-by-slug=terms,posts --default-author=admin --revision="$REV" | tee "$VMATRIX_APPLY_LOG"
  grep -q 'canary clean' "$VMATRIX_APPLY_LOG" || fail "apply canary not clean at elementor $ELEMENTOR_VERSION"
  pass "deploy + apply succeeded on side 2 (elementor $ELEMENTOR_VERSION, canary clean)"

  run_elementor_command check_elementor_content

  run_elementor_command wp2 duo capture --repo=/siterepo --out="/siterepo/.tmp-final"
  DIFF_OUT=$(diff -rq "siterepo/${PAIR}1/state" "siterepo/${PAIR}2/.tmp-final" || true)
  rm -rf "siterepo/${PAIR}2/.tmp-final"
  [ -z "$DIFF_OUT" ] || fail "byte-identity broken at elementor $ELEMENTOR_VERSION: $DIFF_OUT"
  pass "byte-identical recapture at elementor $ELEMENTOR_VERSION — the manifest's own declared version_range boundary is proven, not just its currently-installed version"

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
for CF7_VERSION in 6.0.1 6.1.6; do
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
  CF7_LEGACY_PAGE_ID=$(jq -r '.legacy_page' <<<"$CF7_SEED_OUT")
  [[ "$CF7_OLD_ID" =~ ^[1-9][0-9]+$ && "$CF7_FORM_ID" =~ ^[0-9]+$ && "$CF7_LEGACY_PAGE_ID" =~ ^[0-9]+$ ]] \
    || fail "CF7 $CF7_VERSION seed did not return typed form/legacy ids"

  wp1 duo capture --repo=/siterepo
  pass "captured on side 1 (contact-form-7 $CF7_VERSION)"

  if rg -n "\[contact-form[[:space:]]+$CF7_OLD_ID([[:space:]]|\])" "siterepo/${PAIR}1/state/posts" >/dev/null 2>&1; then
    fail "CF7 $CF7_VERSION capture retained raw legacy alternate $CF7_OLD_ID"
  fi
  rg -n '\[contact-form[[:space:]]+\{\{post:[0-9a-f-]{36}\}\}' "siterepo/${PAIR}1/state/posts" >/dev/null 2>&1 \
    || fail "CF7 $CF7_VERSION capture did not emit a canonical positional post token"
  pass "capture: contact-form-7 $CF7_VERSION canonicalized legacy positional shortcode $CF7_OLD_ID"

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
  TARGET_LEGACY_ID=$(wp2 post list --post_type=page --name=vmatrix-contact-legacy --format=ids)
  # The source and target are isolated databases, so their independently
  # created forms may legitimately receive the same numeric post ID.  The
  # target title/meta/render assertions below prove target ownership; numeric
  # inequality across databases would reject a valid deterministic fixture.
  require_fixture_ids TARGET_FORM_ID TARGET_LEGACY_ID
  [ "$TARGET_LEGACY_ID" != "" ] || fail "CF7 $CF7_VERSION target legacy page is missing"
  TARGET_OLD_ID=$(wp2 post meta get "$TARGET_FORM_ID" _old_cf7_unit_id)
  require_fixture_values TARGET_OLD_ID
  [ "$TARGET_OLD_ID" = "$CF7_OLD_ID" ] || fail "CF7 $CF7_VERSION target lost _old_cf7_unit_id ($TARGET_OLD_ID vs $CF7_OLD_ID)"
  LEGACY_FRONT=$(curl -fs "http://localhost:${PORT2}/vmatrix-contact-legacy/") \
    || fail "CF7 $CF7_VERSION target legacy page did not render"
  require_observed_nonempty "CF7 $CF7_VERSION target legacy page" "$LEGACY_FRONT"
  grep -q "_wpcf7\" value=\"$TARGET_FORM_ID\"" <<<"$LEGACY_FRONT" \
    || fail "CF7 $CF7_VERSION target legacy page did not resolve its own form id $TARGET_FORM_ID"
  pass "target: contact-form-7 $CF7_VERSION legacy positional shortcode resolves to target form $TARGET_FORM_ID"

  wp2 duo capture --repo=/siterepo --out="/siterepo/.tmp-final"
  DIFF_OUT=$(diff -rq "siterepo/${PAIR}1/state" "siterepo/${PAIR}2/.tmp-final" || true)
  rm -rf "siterepo/${PAIR}2/.tmp-final"
  [ -z "$DIFF_OUT" ] || fail "byte-identity broken at contact-form-7 $CF7_VERSION: $DIFF_OUT"
  pass "byte-identical recapture at contact-form-7 $CF7_VERSION — the manifest's own declared version_range boundary is proven, not just its currently-installed version"
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
# first release admitted by the manifest's exact 28.0 minimum, and 28.2 is
# the newest release below 29.0.0. Exercise both exact
# artifacts; a current-slug install would prove neither boundary.
if [ "$VMATRIX_MANIFEST" = yoast ]; then
VMATRIX_CASES=$((VMATRIX_CASES + 1))
for YOAST_VERSION in 28.0 28.2; do
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
  REV=$(git -C "siterepo/${PAIR}2" rev-parse HEAD)
  wp2 duo apply --repo=/siterepo --adopt-by-slug=terms,posts --default-author=admin --revision="$REV" | tee "$VMATRIX_APPLY_LOG"
  grep -q 'canary clean' "$VMATRIX_APPLY_LOG" || fail "apply canary not clean at wordpress-seo $YOAST_VERSION"
  pass "deploy + apply succeeded on side 2 (wordpress-seo $YOAST_VERSION, canary clean)"

  check_yoast_content

  wp2 duo capture --repo=/siterepo --out="/siterepo/.tmp-final"
  DIFF_OUT=$(diff -rq "siterepo/${PAIR}1/state" "siterepo/${PAIR}2/.tmp-final" || true)
  rm -rf "siterepo/${PAIR}2/.tmp-final"
  [ -z "$DIFF_OUT" ] || fail "byte-identity broken at wordpress-seo $YOAST_VERSION: $DIFF_OUT"
  pass "byte-identical recapture at wordpress-seo $YOAST_VERSION — the manifest's own declared version_range boundary is proven, not just its currently-installed version"
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
say "negative control: contact-form-7 5.9.8 (real wp.org release, genuinely below manifests/contact-form-7.json's own declared min 6.0.0) must be REFUSED, not silently accepted"
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
say "negative controls: adjacent official PMPro tags 3.8.2 and 3.8.4 must both be refused by the exact 3.8.3 contract"
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

for OUT_OF_RANGE_VERSION in 3.8.2 3.8.4; do
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
  printf '%s\n' "$DEPLOY_OUT"
  pass "confirmed: official PMPro $OUT_OF_RANGE_VERSION is loudly refused outside exact range >=3.8.3 <3.8.4"
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
