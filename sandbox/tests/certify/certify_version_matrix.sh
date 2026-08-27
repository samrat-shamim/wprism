#!/usr/bin/env bash
# Certify version-boundary matrix (DUO-3223's own last remaining piece,
# unblocked by the owner ruling on artifact sourcing — issue comment
# 0ec1d2e3). No existing conformance/grind fixture installs a plugin at
# anything other than "whatever wp.org currently serves for this slug" —
# this is the first proof that a manifest's own declared version_range is
# backed by real evidence at ITS OWN edges, not just the one version every
# other fixture happens to exercise.
#
# First fifteen real plugins: ACF, Advanced Editor Tools, Classic Editor,
# Code Snippets, Contact Form 7, Elementor, Ninja Forms, Paid Memberships Pro, Polylang,
# Redirection, The Events Calendar,
# WooCommerce, WPS Hide Login, Yoast Duplicate Post, and Yoast SEO. ACF proved the artifact-sourcing
# mechanism itself; the others
# prove the matrix accepts genuinely different
# plugin content shapes rather than replaying one ACF fixture. This closes the
# last pinned-manifest boundary that DUO-3223 had explicitly scope-accounted.
#
# For EACH boundary version (ACF 6.0.0/6.8.7; Advanced Editor Tools 5.9.2;
# Classic Editor 1.7.0; Code Snippets 3.9.5/3.9.6; CF7 6.0/6.1.7; Elementor 4.0.0/4.2.3; Ninja Forms
# 3.4.34.2/3.14.11; PMPro 3.8.2/3.8.3 (with adjacent official-tag refusals);
# Polylang 3.8/3.8.7; Redirection 5.9.0 (with 5.8.1 below-range refusal);
# The Events Calendar 6.17.2/6.17.3;
# WooCommerce 11.0.0/11.0.1 (including a populated in-place upgrade); Yoast SEO
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
# inspection. VMATRIX_EXPECTED_SOURCE_SHA forwards the exact candidate gate to
# pair.sh; pair.sh also accepts DUO_SOURCE_ROOT for an issue worktree mount.
set -euo pipefail
cd "$(dirname "$0")/../.."   # -> sandbox/

say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }

assert_no_php_diagnostics() { # <label> <log>
  local label="$1" log="$2"
  if grep -Eq '(^|[[:space:]])(PHP )?(Warning|Notice|Deprecated): .* in .*[.]php on line [0-9]+' "$log"; then
    fail "$label emitted a PHP runtime diagnostic: $(grep -Em1 '(^|[[:space:]])(PHP )?(Warning|Notice|Deprecated): .* in .*[.]php on line [0-9]+' "$log")"
  fi
}

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
jq -e '.evidence.tests | index("exact-artifact-version-matrix") != null' \
  "../manifests/dispositions/$VMATRIX_MANIFEST.json" >/dev/null \
  || fail "manifest '$VMATRIX_MANIFEST' does not declare exact-artifact-version-matrix evidence"
# The WooCommerce and Redirection legs are production-readiness evidence over
# shipped manifest/provider bytes. pair.sh otherwise resolves a linked worktree to
# its canonical checkout, which can make a green boundary matrix about a
# different commit. Require the candidate SHA and mount this script's own
# physical checkout before reset can mutate either disposable database.
if [ "$VMATRIX_MANIFEST" = woocommerce ] || [ "$VMATRIX_MANIFEST" = redirection ]; then
  [ -n "${DUO_EXPECTED_SOURCE_SHA:-}" ] \
    || fail "$VMATRIX_MANIFEST version-matrix evidence requires DUO_EXPECTED_SOURCE_SHA"
  export DUO_SOURCE_ROOT="$(cd .. && pwd -P)"
fi
VMATRIX_CASES=0
WORDPRESS_OFFLINE="${DUO_WORDPRESS_ORG_OFFLINE:-0}"
case "$WORDPRESS_OFFLINE" in
  0|1) ;;
  *) fail "DUO_WORDPRESS_ORG_OFFLINE must be 0 or 1" ;;
esac
export DUO_PAIR="$PAIR"
# A boundary result is evidence only for the agent/manifests bytes that the
# pair mounts. Export before the first pair.sh call: `up` can allocate the
# databases and start containers, so setting it later would certify a stale
# canonical checkout rather than this candidate.
if [ -n "${VMATRIX_EXPECTED_SOURCE_SHA:-}" ]; then
  export DUO_EXPECTED_SOURCE_SHA="$VMATRIX_EXPECTED_SOURCE_SHA"
fi
# Boundary observations use fresh direct Compose processes. Keep the selected
# mounts in this shell; shared .env can legitimately move when another pair is
# cleaned up and therefore cannot carry this matrix's candidate identity.
. lib/pair_identity.sh
pair_identity_export_source_mounts \
  || fail 'version matrix could not pin its selected source mounts in the caller environment'
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


# Per-plugin seed/check/postdeploy fixtures (WP-2.3 split of what was 33
# inline function blocks appended straight into this driver — see the
# git history of this file up to the split commit for the prior shape).
# Extracted one file per plugin under matrix.d/, or package-owned as
# adapter-packages/<slug>/tests/certify/version-matrix.sh. This driver needs
# every plugin's functions defined up front (one invocation still only ENTERS
# the one $VMATRIX_MANIFEST case below), so package hooks are discovered as a
# set while the legacy hooks stay explicit until their packages migrate.
for package_matrix in ../adapter-packages/*/tests/certify/version-matrix.sh; do
  [ -f "$package_matrix" ] || continue
  # shellcheck source=/dev/null
  . "$package_matrix"
done
. tests/certify/matrix.d/contact-form-7.sh
. tests/certify/matrix.d/elementor.sh
. tests/certify/matrix.d/ninja-forms.sh
. tests/certify/matrix.d/polylang.sh
. tests/certify/matrix.d/woocommerce.sh
. tests/certify/matrix.d/yoast.sh
. tests/certify/matrix.d/paid-memberships-pro.sh
. tests/certify/matrix.d/advanced-editor-tools.sh
. tests/certify/matrix.d/classic-editor.sh
. tests/certify/matrix.d/code-snippets.sh
. tests/certify/matrix.d/wps-hide-login.sh
. tests/certify/matrix.d/yoast-duplicate-post.sh
. tests/certify/matrix.d/the-events-calendar.sh
. tests/certify/matrix.d/redirection.sh

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
  # WooCommerce's Review Order endpoint runs on init:4 while the prior exact
  # artifact is still active. If its feature stays enabled through `site
  # empty`, the very next reset command recreates the deleted host page at a
  # low id before postdeploy raises AUTO_INCREMENT above 2^31. Disable the
  # feature and clear its page/rewrite identities before deleting posts so a
  # consecutive exact boundary starts from the selected artifact's own
  # activation state rather than a page recreated by the preceding release.
  "$cli" eval '
    $names = [
      "woocommerce_feature_customer_review_request_enabled",
      "woocommerce_review_order_page_id",
      "woocommerce_review_order_flush_rewrite_pending",
    ];
    foreach ($names as $name) { delete_option($name); }
    foreach ($names as $name) {
      if (false !== get_option($name, false)) {
        throw new RuntimeException("version-matrix reset retained WooCommerce Review Order option " . $name);
      }
    }
  ' >/dev/null
  # The pair webroot is a named volume and survives every loop iteration.
  # Clearing only DB rows left WooCommerce's package-owned placeholder
  # derivatives behind after the 11.0.0 leg; the fresh 11.0.1 attachment did
  # not own those exact paths and apply correctly refused with "generated
  # attachment derivative collides with an existing file not owned by prior
  # native metadata". All uploads are disposable boundary-fixture content, so
  # use WP-CLI's bounded native cleanup instead of a plugin filename glob.
  "$cli" site empty --yes --uploads >/dev/null
  # `site empty --uploads` can remove the uploads root itself. A later apply
  # treats that as an invalid production filesystem boundary rather than
  # silently inventing it, so reset must restore the ordinary WordPress
  # premise explicitly before any exact-version case begins.
  "$cli" eval '
    $upload = wp_get_upload_dir();
    $root = (string) ($upload["basedir"] ?? "");
    if ($root === "" || is_link($root)
        || (!is_dir($root) && !wp_mkdir_p($root))
        || !is_dir($root) || is_link($root)) {
      throw new RuntimeException("version-matrix reset could not restore uploads root");
    }
  ' >/dev/null
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
  for plugin in advanced-custom-fields classic-editor code-snippets contact-form-7 duplicate-post elementor ninja-forms paid-memberships-pro polylang redirection the-events-calendar tinymce-advanced woocommerce wordpress-seo wps-hide-login; do
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
  # Redirection deliberately retains its authored tables and settings on
  # ordinary plugin deletion. Each exact-artifact case must execute the
  # selected release's own installer against an empty plugin schema.
  "$cli" db query "
    DROP TABLE IF EXISTS wp_redirection_404, wp_redirection_groups, wp_redirection_items, wp_redirection_logs;
    DELETE FROM wp_options WHERE option_name LIKE 'redirection%';
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
  # TEC retains authored rows, its Custom Tables V1 projections, and almost
  # all setup/runtime options when code is deleted. Reset those disposable
  # matrix residues so each admitted release runs its own installer and no
  # 6.17.2 schema or cached migration marker can make 6.17.3 look healthy.
  "$cli" db query "
    DROP TABLE IF EXISTS wp_tec_events, wp_tec_occurrences, wp_tec_kv_cache;
    DELETE FROM wp_options
      WHERE option_name LIKE 'tribe_%'
         OR option_name LIKE 'tec_%'
         OR option_name LIKE 'stellarwp_%'
         OR option_name LIKE 'stellar_schema_version_%';
  " >/dev/null
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
  # The Events Calendar's Custom Tables v1 schema, its schema-version and
  # one-time-migration bookkeeping, and its kv cache all survive ordinary
  # plugin deletion (manifests/the-events-calendar.json classifies exactly
  # these as env/runtime/derived). `site empty` deletes the tribe_events
  # posts but not their tec_occurrences rows, and the target-only cache row
  # conformance/postdeploy/the-events-calendar.sh plants
  # ('duo-readiness-target-only') would otherwise survive into the next
  # boundary iteration and satisfy that iteration's own runtime-preservation
  # assertion without this run having preserved anything.
  "$cli" eval '
    global $wpdb;
    foreach (["tec\\_%"] as $suffix) {
      $like = $wpdb->prefix . $suffix;
      foreach ($wpdb->get_col($wpdb->prepare("SHOW TABLES LIKE %s", $like)) as $table) {
        $safe = str_replace("`", "``", $table);
        $wpdb->query("DROP TABLE IF EXISTS `{$safe}`");
      }
    }
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '"'"'tec\_%'"'"' OR option_name LIKE '"'"'tribe\_%'"'"' OR option_name LIKE '"'"'stellar\_schema\_version\_%'"'"' OR option_name LIKE '"'"'stellarwp\_telemetry%'"'"' OR option_name LIKE '"'"'_transient\_tribe\_%'"'"' OR option_name LIKE '"'"'_site\_transient\_tribe\_%'"'"'");
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
if [ "$VMATRIX_MANIFEST" = redirection ]; then
VMATRIX_CASES=$((VMATRIX_CASES + 1))
REDIRECTION_VERSION=5.9.0
say "boundary: redirection $REDIRECTION_VERSION (only admitted patch)"

reset_env wp1
reset_env wp2
reset_case_repositories

say "fetch + verify redirection $REDIRECTION_VERSION (digest-checked artifact only)"
REDIRECTION_ARTIFACT_1=$(fetch_artifact redirection "$REDIRECTION_VERSION" cli1)
REDIRECTION_ARTIFACT_2=$(fetch_artifact redirection "$REDIRECTION_VERSION" cli2)
wp1 plugin install "$REDIRECTION_ARTIFACT_1" --activate >/dev/null
[ "$(wp1 plugin get redirection --field=version)" = "$REDIRECTION_VERSION" ] \
  || fail "side 1 did not install exact redirection $REDIRECTION_VERSION"

printf '%s\n' '{' \
  '  "manifests": ["core", "redirection"],' \
  '  "policy": {' \
  '    "options": {},' \
  '    "post_meta": {},' \
  '    "post_types": ["post", "page", "attachment"],' \
  '    "taxonomies": ["category", "post_tag"]' \
  '  },' \
  '  "spec_version": 3' \
  '}' > "siterepo/${PAIR}1/site.duo.json"
cp site-repo.gitignore.template "siterepo/${PAIR}1/.gitignore"
"${GIT1[@]}" init -q -b main
"${GIT1[@]}" remote add origin "../origin-$PAIR.git"
"${GIT1[@]}" add -A
"${GIT1[@]}" commit -qm "policy: Redirection $REDIRECTION_VERSION exact-boundary certification"
"${GIT1[@]}" push -qu origin main

seed_redirection_content
wp1 duo capture --repo=/siterepo
wp1 duo lint --repo=/siterepo
"${GIT1[@]}" add -A
"${GIT1[@]}" commit -qm "capture: Redirection $REDIRECTION_VERSION mixed rule graph"
"${GIT1[@]}" push -q origin main

clone_case_target
wp2 plugin install "$REDIRECTION_ARTIFACT_2" >/dev/null
[ "$(wp2 plugin get redirection --field=version)" = "$REDIRECTION_VERSION" ] \
  || fail "side 2 did not install exact redirection $REDIRECTION_VERSION"
wp2 duo deploy --repo=/siterepo
prepare_redirection_boundary_target
REV=$(git -C "siterepo/${PAIR}2" rev-parse HEAD)
wp2 duo apply --repo=/siterepo --adopt-by-slug=terms,posts --default-author=admin --revision="$REV" 2>&1 | tee "$VMATRIX_APPLY_LOG"
grep -q 'canary clean' "$VMATRIX_APPLY_LOG" \
  || fail "apply canary not clean at redirection $REDIRECTION_VERSION"
check_redirection_boundary_content

wp2 duo capture --repo=/siterepo --out=/siterepo/.tmp-final
REDIRECTION_DIFF=$(diff -rq "siterepo/${PAIR}1/state" "siterepo/${PAIR}2/.tmp-final" || true)
rm -rf "siterepo/${PAIR}2/.tmp-final"
[ -z "$REDIRECTION_DIFF" ] \
  || fail "byte-identity broken at redirection $REDIRECTION_VERSION: $REDIRECTION_DIFF"
pass "Redirection $REDIRECTION_VERSION deploys, behaves natively, and recaptures byte-identically"

say 'negative control: official redirection 5.8.1 must refuse below the exact contract'
NEGATIVE_BEFORE=$(wp1 eval 'global $wpdb; echo hash("sha256",wp_json_encode([$wpdb->get_results("SELECT * FROM {$wpdb->prefix}redirection_groups ORDER BY id",ARRAY_A),$wpdb->get_results("SELECT * FROM {$wpdb->prefix}redirection_items ORDER BY id",ARRAY_A),get_option("redirection_options")],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));')
wp1 plugin deactivate redirection >/dev/null
wp1 plugin delete redirection >/dev/null
REDIRECTION_OLD=$(fetch_artifact redirection 5.8.1 cli1)
wp1 plugin install "$REDIRECTION_OLD" >/dev/null
[ "$(wp1 plugin get redirection --field=version)" = 5.8.1 ] \
  || fail 'Redirection negative control did not install exact 5.8.1'
NEGATIVE_RC=0
NEGATIVE_OUT=$(wp1 duo deploy --repo=/siterepo 2>&1) || NEGATIVE_RC=$?
require_duo_answered 'Redirection 5.8.1 outside-range deploy' human "$NEGATIVE_OUT"
[ "$NEGATIVE_RC" -ne 0 ] && grep -Eq 'outside_version_range|outside the .* declared version_range' <<<"$NEGATIVE_OUT" \
  && grep -q '5.8.1' <<<"$NEGATIVE_OUT" \
  || fail "Redirection 5.8.1 refused for the wrong reason: $NEGATIVE_OUT"
wp1 plugin is-active redirection >/dev/null 2>&1 \
  && fail 'outside-range Redirection 5.8.1 was activated before refusal'
NEGATIVE_AFTER=$(wp1 eval 'global $wpdb; echo hash("sha256",wp_json_encode([$wpdb->get_results("SELECT * FROM {$wpdb->prefix}redirection_groups ORDER BY id",ARRAY_A),$wpdb->get_results("SELECT * FROM {$wpdb->prefix}redirection_items ORDER BY id",ARRAY_A),get_option("redirection_options")],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));')
[ "$NEGATIVE_AFTER" = "$NEGATIVE_BEFORE" ] \
  || fail 'Redirection outside-range refusal mutated retained plugin state'
pass 'official Redirection 5.8.1 is loudly refused before activation or state mutation'
fi

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
    UPGRADE_DRIFT_OUT=$(wp2 duo deploy --repo=/siterepo 2>&1) || UPGRADE_DRIFT_RC=$?
    require_duo_answered 'Ninja Forms out-of-band 3.4.34.2 to 3.14.11 upgrade refusal' human "$UPGRADE_DRIFT_OUT"
    [ "$UPGRADE_DRIFT_RC" -ne 0 ] \
      && grep -q 'code_drift' <<<"$UPGRADE_DRIFT_OUT" \
      && grep -q '3.4.34.2' <<<"$UPGRADE_DRIFT_OUT" \
      && grep -q '3.14.11' <<<"$UPGRADE_DRIFT_OUT" \
      || fail "Ninja Forms out-of-band upgrade did not refuse at the exact code witness: $UPGRADE_DRIFT_OUT"

    # Re-baseline the explicit code replacement, then publish one real native
    # form edit under the new release so apply must exercise mapping and the
    # fresh-process cache provider across the in-place lifecycle boundary.
    wp1 duo deploy --repo=/siterepo --force-code-drift >/dev/null
    wp2 duo deploy --repo=/siterepo --force-code-drift >/dev/null
    wp1 eval '
      global $wpdb;
      $id=(int)$wpdb->get_var("SELECT id FROM {$wpdb->prefix}nf3_forms WHERE title=\"Job Application\"");
      if ($id <= 0) throw new RuntimeException("Ninja Forms upgrade form is absent");
      $form=Ninja_Forms()->form($id)->get();
      $form->update_setting("title", "Job Application Upgrade 東京 🚀")->save();
      WPN_Helper::delete_nf_cache($id);
      WPN_Helper::build_nf_cache($id);
    ' >/dev/null
    wp1 duo capture --repo=/siterepo
    wp1 duo lint --repo=/siterepo
    "${GIT1[@]}" add -A
    "${GIT1[@]}" commit -qm 'capture: Ninja Forms 3.4.34.2 to 3.14.11 in-place upgrade'
    "${GIT1[@]}" push -q origin main
    git -C "siterepo/${PAIR}2" pull -q origin main
    UPGRADE_REV=$(git -C "siterepo/${PAIR}2" rev-parse HEAD)
    wp2 duo apply --repo=/siterepo --adopt-by-slug=terms,posts --default-author=admin --revision="$UPGRADE_REV" \
      2>&1 | tee "$VMATRIX_APPLY_LOG"
    grep -q 'canary clean' "$VMATRIX_APPLY_LOG" \
      || fail 'Ninja Forms 3.4.34.2 -> 3.14.11 apply canary was not clean'
    grep -q 'provider capability fired: ninja-forms-form-cache@2.2.0 rebuild_form_caches' "$VMATRIX_APPLY_LOG" \
      || fail 'Ninja Forms cache provider v2 did not fire across the in-place upgrade'
    check_ninja_forms_boundary_content 'Job Application Upgrade 東京 🚀'

    wp2 duo capture --repo=/siterepo --out=/siterepo/.tmp-ninja-upgrade-final
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
    DOWNGRADE_OUT=$(wp2 duo deploy --repo=/siterepo 2>&1) || DOWNGRADE_RC=$?
    require_duo_answered 'Ninja Forms out-of-band 3.14.11 to 3.4.34.2 downgrade refusal' human "$DOWNGRADE_OUT"
    [ "$DOWNGRADE_RC" -ne 0 ] \
      && grep -q 'code_drift' <<<"$DOWNGRADE_OUT" \
      && grep -q '3.14.11' <<<"$DOWNGRADE_OUT" \
      && grep -q '3.4.34.2' <<<"$DOWNGRADE_OUT" \
      || fail "Ninja Forms out-of-band downgrade did not refuse at the exact code witness: $DOWNGRADE_OUT"
    wp2 plugin install "$UPGRADE_ARTIFACT_2" --force --activate >/dev/null
    [ "$(wp2 plugin get ninja-forms --field=version)" = 3.14.11 ] \
      || fail 'Ninja Forms downgrade recovery did not restore exact 3.14.11'
    wp2 duo deploy --repo=/siterepo --force-code-drift >/dev/null
    DOWNGRADE_PLAN=$(wp2 duo plan --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
    require_duo_answered 'Ninja Forms plan after rejected downgrade recovery' json "$DOWNGRADE_PLAN"
    jq -e '([.create,.update,.drift,.conflict,.collision,.delete,.delete_conflict] | map(length) | add) == 0' <<<"$DOWNGRADE_PLAN" >/dev/null \
      || fail "Ninja Forms rejected downgrade recovery invented authored work: $DOWNGRADE_PLAN"
    check_ninja_forms_boundary_content 'Job Application Upgrade 東京 🚀'
    pass 'Ninja Forms populated 3.4.34.2 sites upgrade in place to 3.14.11; out-of-band downgrade refuses before explicit restoration; native graph/cache and byte identity survive'
  fi
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

# One candidate-bound pass executes every real-world standalone scenario on
# 6.17.2 and the native boundary on 6.17.3; standalone conformance owns the
# full 6.17.3 run. Keeping the seed, target, and check helpers singular is part
# of the evidence contract: a
# later duplicate definition can silently replace the product-path check,
# while a duplicate loop spends the pair budget without adding a boundary.
if [ "$VMATRIX_MANIFEST" = the-events-calendar ]; then
VMATRIX_CASES=$((VMATRIX_CASES + 1))
for TEC_VERSION in 6.17.2 6.17.3; do
  say "boundary: the-events-calendar $TEC_VERSION"

  reset_env wp1
  reset_env wp2
  reset_case_repositories

  say "fetch + verify the-events-calendar $TEC_VERSION (digest-checked artifact only)"
  TEC_ARTIFACT_1=$(fetch_artifact the-events-calendar "$TEC_VERSION" cli1)
  TEC_ARTIFACT_2=$(fetch_artifact the-events-calendar "$TEC_VERSION" cli2)
  wp1 plugin install "$TEC_ARTIFACT_1" --activate >/dev/null
  TEC_INSTALLED_1=$(wp1 plugin get the-events-calendar --field=version)
  [ "$TEC_INSTALLED_1" = "$TEC_VERSION" ] \
    || fail "side 1 installed version mismatch: expected $TEC_VERSION, got $TEC_INSTALLED_1"
  pass "side 1: the-events-calendar $TEC_VERSION installed from verified artifact, active"

  cat > "siterepo/${PAIR}1/site.duo.json" <<'EOF'
{
  "manifests": ["core", "the-events-calendar"],
  "policy": {
    "options": {},
    "post_meta": {},
    "post_types": ["post", "page", "attachment", "tribe_events", "tribe_venue", "tribe_organizer"],
    "taxonomies": ["category", "post_tag", "tribe_events_cat"]
  },
  "spec_version": 2
}
EOF
  cp site-repo.gitignore.template "siterepo/${PAIR}1/.gitignore"
  "${GIT1[@]}" init -q -b main
  "${GIT1[@]}" remote add origin "../origin-$PAIR.git"
  "${GIT1[@]}" add -A
  "${GIT1[@]}" commit -qm "policy: The Events Calendar $TEC_VERSION exact-boundary certification"
  "${GIT1[@]}" push -qu origin main

  seed_the_events_calendar_content
  wp1 duo capture --repo=/siterepo
  wp1 duo lint --repo=/siterepo
  "${GIT1[@]}" add -A
  "${GIT1[@]}" commit -qm "capture: The Events Calendar $TEC_VERSION native graph"
  "${GIT1[@]}" push -q origin main

  clone_case_target
  wp2 plugin install "$TEC_ARTIFACT_2" >/dev/null
  TEC_INSTALLED_2=$(wp2 plugin get the-events-calendar --field=version)
  require_fixture_values TEC_INSTALLED_2
  [ "$TEC_INSTALLED_2" = "$TEC_VERSION" ] \
    || fail "side 2 installed version mismatch: expected $TEC_VERSION, got $TEC_INSTALLED_2"
  wp2 plugin is-active the-events-calendar >/dev/null 2>&1 \
    && fail "TEC $TEC_VERSION target premise must begin inactive"

  wp2 duo deploy --repo=/siterepo
  wp2 plugin is-active the-events-calendar >/dev/null \
    || fail "deploy did not activate the admitted TEC $TEC_VERSION artifact"
  postdeploy_the_events_calendar_content
  REV=$(git -C "siterepo/${PAIR}2" rev-parse HEAD)
  wp2 duo apply --repo=/siterepo --adopt-by-slug=terms,posts --default-author=admin --revision="$REV" 2>&1 | tee "$VMATRIX_APPLY_LOG"
  grep -q 'canary clean' "$VMATRIX_APPLY_LOG" \
    || fail "apply canary not clean at the-events-calendar $TEC_VERSION"
  postapply_the_events_calendar_content
  check_the_events_calendar_boundary_content

  wp2 duo capture --repo=/siterepo --out=/siterepo/.tmp-final
  TEC_DIFF=$(diff -rq "siterepo/${PAIR}1/state" "siterepo/${PAIR}2/.tmp-final" || true)
  rm -rf "siterepo/${PAIR}2/.tmp-final"
  [ -z "$TEC_DIFF" ] \
    || fail "byte-identity broken at the-events-calendar $TEC_VERSION: $TEC_DIFF"
  pass "The Events Calendar $TEC_VERSION deploys, adopts hostile identities, repairs projections, renders natively, and recaptures byte-identically"

  if [ "$TEC_VERSION" = 6.17.2 ]; then
    # Both admitted artifacts carry byte-identical Custom Tables V1 code, but
    # the populated in-place path still owns installer/migration and code-
    # baseline behavior that two fresh installs cannot prove.
    TEC_UPGRADE_1=$(fetch_artifact the-events-calendar 6.17.3 cli1)
    TEC_UPGRADE_2=$(fetch_artifact the-events-calendar 6.17.3 cli2)
    wp1 plugin install "$TEC_UPGRADE_1" --force --activate >/dev/null
    wp2 plugin install "$TEC_UPGRADE_2" --force --activate >/dev/null
    [ "$(wp1 plugin get the-events-calendar --field=version)" = 6.17.3 ] \
      && [ "$(wp2 plugin get the-events-calendar --field=version)" = 6.17.3 ] \
      || fail "TEC supported in-place upgrade did not install 6.17.3 on both populated sides"

    TEC_UPGRADE_DEPLOY_RC=0
    TEC_UPGRADE_DEPLOY_OUT=$(wp2 duo deploy --repo=/siterepo 2>&1) || TEC_UPGRADE_DEPLOY_RC=$?
    require_duo_answered "TEC out-of-band 6.17.2 to 6.17.3 upgrade refusal" human "$TEC_UPGRADE_DEPLOY_OUT"
    [ "$TEC_UPGRADE_DEPLOY_RC" -ne 0 ] \
      && grep -q 'deploy refused — code_drift' <<<"$TEC_UPGRADE_DEPLOY_OUT" \
      && grep -q 'recorded 6.17.2' <<<"$TEC_UPGRADE_DEPLOY_OUT" \
      && grep -q 'is 6.17.3 on this environment' <<<"$TEC_UPGRADE_DEPLOY_OUT" \
      || fail "TEC out-of-band upgrade did not refuse at the exact code-drift boundary: $TEC_UPGRADE_DEPLOY_OUT"
    wp2 duo deploy --repo=/siterepo --force-code-drift >/dev/null
    TEC_UPGRADE_PLAN=$(wp2 duo plan --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
    require_duo_answered "TEC 6.17.2 to 6.17.3 target plan" json "$TEC_UPGRADE_PLAN"
    jq -e '([.create,.update,.drift,.conflict,.collision,.delete,.delete_conflict] | map(length) | add) == 0' <<<"$TEC_UPGRADE_PLAN" >/dev/null \
      || fail "TEC supported in-place upgrade invented authored work: $TEC_UPGRADE_PLAN"

    TEC_POST_UPGRADE_ONLY=1 TEC_VERSION=6.17.3 check_the_events_calendar_boundary_content
    wp1 duo capture --repo=/siterepo --out=/siterepo/.tmp-tec-upgrade-source
    wp2 duo capture --repo=/siterepo --out=/siterepo/.tmp-tec-upgrade-target
    TEC_UPGRADE_SOURCE_DIFF=$(diff -rq "siterepo/${PAIR}1/state" "siterepo/${PAIR}1/.tmp-tec-upgrade-source" || true)
    TEC_UPGRADE_TARGET_DIFF=$(diff -rq "siterepo/${PAIR}1/state" "siterepo/${PAIR}2/.tmp-tec-upgrade-target" || true)
    rm -rf "siterepo/${PAIR}1/.tmp-tec-upgrade-source" "siterepo/${PAIR}2/.tmp-tec-upgrade-target"
    [ -z "$TEC_UPGRADE_SOURCE_DIFF" ] && [ -z "$TEC_UPGRADE_TARGET_DIFF" ] \
      || fail "TEC populated in-place upgrade changed canonical state: source=$TEC_UPGRADE_SOURCE_DIFF target=$TEC_UPGRADE_TARGET_DIFF"
    pass "TEC populated 6.17.2 sites upgrade in place to 6.17.3 with exact native behavior, no authored drift, and explicit code-baseline authority"
    TEC_VERSION=6.17.2
  fi
  rm -f "siterepo/${PAIR}1/.tmp-tec-source-ids.json" "siterepo/${PAIR}2/.tmp-tec-target-ids.json"
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

if [ "$VMATRIX_MANIFEST" = the-events-calendar ]; then
say "negative control: official The Events Calendar 6.17.1 is below the reviewed 6.17.2 floor and must refuse before activation"
reset_env wp1
reset_case_repositories

# Capture a native graph under admitted 6.17.3 bytes, then replace only the
# installed code. The refusal therefore exercises the compatibility boundary
# against representative TEC references/settings rather than an empty repo.
TEC_IN_RANGE_ARTIFACT=$(fetch_artifact the-events-calendar 6.17.3 cli1)
wp1 plugin install "$TEC_IN_RANGE_ARTIFACT" --activate >/dev/null
[ "$(wp1 plugin get the-events-calendar --field=version)" = 6.17.3 ] \
  || fail "TEC negative-control premise did not install exact 6.17.3 bytes"
cat > "siterepo/${PAIR}1/site.duo.json" <<'EOF'
{
  "manifests": ["core", "the-events-calendar"],
  "policy": {
    "options": {},
    "post_meta": {},
    "post_types": ["post", "page", "attachment", "tribe_events", "tribe_venue", "tribe_organizer"],
    "taxonomies": ["category", "post_tag", "tribe_events_cat"]
  },
  "spec_version": 2
}
EOF
cp site-repo.gitignore.template "siterepo/${PAIR}1/.gitignore"
"${GIT1[@]}" init -q -b main
"${GIT1[@]}" remote add origin "../origin-$PAIR.git"
"${GIT1[@]}" add -A
"${GIT1[@]}" commit -qm "policy: The Events Calendar adjacent-version refusal"
"${GIT1[@]}" push -qu origin main
seed_the_events_calendar_content
wp1 duo capture --repo=/siterepo
"${GIT1[@]}" add -A
"${GIT1[@]}" commit -qm "capture: valid TEC graph for adjacent-version refusal"
"${GIT1[@]}" push -q origin main

wp1 plugin deactivate the-events-calendar >/dev/null
wp1 plugin delete the-events-calendar >/dev/null
TEC_OUT_OF_RANGE_ARTIFACT=$(fetch_artifact the-events-calendar 6.17.1 cli1)
wp1 plugin install "$TEC_OUT_OF_RANGE_ARTIFACT" >/dev/null
TEC_INSTALLED_OOR=$(wp1 plugin get the-events-calendar --field=version)
[ "$TEC_INSTALLED_OOR" = 6.17.1 ] \
  || fail "negative control: expected the-events-calendar 6.17.1 installed, got $TEC_INSTALLED_OOR"

TEC_REFUSAL_RC=0
TEC_REFUSAL_OUT=$(wp1 duo deploy --repo=/siterepo 2>&1) || TEC_REFUSAL_RC=$?
[ "$TEC_REFUSAL_RC" -ne 0 ] \
  || fail "expected deploy to refuse the-events-calendar 6.17.1, but it exited 0: $TEC_REFUSAL_OUT"
grep -Eq "outside_version_range|outside the '.*' manifest's declared version_range" <<<"$TEC_REFUSAL_OUT" \
  || fail "TEC 6.17.1 refused outside the version gate: $TEC_REFUSAL_OUT"
grep -q 'the-events-calendar/the-events-calendar.php' <<<"$TEC_REFUSAL_OUT" \
  || fail "TEC adjacent-version refusal did not name the exact plugin basename: $TEC_REFUSAL_OUT"
grep -q '6.17.1' <<<"$TEC_REFUSAL_OUT" \
  || fail "TEC adjacent-version refusal did not name installed version 6.17.1: $TEC_REFUSAL_OUT"
wp1 plugin is-active the-events-calendar >/dev/null 2>&1 \
  && fail "outside-range TEC 6.17.1 was activated before deploy refused"
printf '%s\n' "$TEC_REFUSAL_OUT"
pass "official TEC 6.17.1 is loudly refused and remains inactive outside >=6.17.2 <6.17.4"
fi

if [ "$VMATRIX_MANIFEST" = polylang ]; then
VMATRIX_CASES=$((VMATRIX_CASES + 1))
for POLYLANG_VERSION in 3.8 3.8.7; do
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
    "post_types": ["post", "page", "wp_block", "attachment"],
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
  wp2 duo apply --repo=/siterepo --adopt-by-slug=terms,posts --default-author=admin --revision="$REV" 2>&1 | tee "$VMATRIX_APPLY_LOG"
  grep -q 'canary clean' "$VMATRIX_APPLY_LOG" || fail "apply canary not clean at polylang $POLYLANG_VERSION"
  assert_no_php_diagnostics "Polylang $POLYLANG_VERSION clean-target apply" "$VMATRIX_APPLY_LOG"
  POLYLANG_BOUNDARY_PROVIDER_RECEIPT=$(cat "$VMATRIX_APPLY_LOG")
  pass "deploy + apply succeeded on side 2 (polylang $POLYLANG_VERSION, canary clean)"

  check_polylang_content

  wp2 duo capture --repo=/siterepo --out="/siterepo/.tmp-final"
  DIFF_OUT=$(diff -rq "siterepo/${PAIR}1/state" "siterepo/${PAIR}2/.tmp-final" || true)
  rm -rf "siterepo/${PAIR}2/.tmp-final"
  [ -z "$DIFF_OUT" ] || fail "byte-identity broken at polylang $POLYLANG_VERSION: $DIFF_OUT"
  pass "byte-identical recapture at polylang $POLYLANG_VERSION — the manifest's own declared version_range boundary is proven, not just its currently-installed version"

  if [ "$POLYLANG_VERSION" = 3.8 ]; then
    say 'in-place upgrade: polylang 3.8 -> 3.8.7 on both populated environments'
    UPGRADE_ARTIFACT_1=$(fetch_artifact polylang 3.8.7 cli1)
    UPGRADE_ARTIFACT_2=$(fetch_artifact polylang 3.8.7 cli2)
    wp1 plugin install "$UPGRADE_ARTIFACT_1" --force --activate >/dev/null
    [ "$(wp1 plugin get polylang --field=version)" = 3.8.7 ] \
      || fail 'Polylang source in-place upgrade did not install exact 3.8.7'
    wp1 duo deploy --repo=/siterepo --force-code-drift >/dev/null
    UPGRADE_POST=$(jq -r '.posts.fr' "siterepo/${PAIR}1/.tmp-polylang-source.json")
    require_fixture_ids UPGRADE_POST
    wp1 post update "$UPGRADE_POST" --post_title='Polylang 3.8 vers 3.8.7 française 東京 🚀' >/dev/null
    wp1 duo capture --repo=/siterepo
    wp1 duo lint --repo=/siterepo
    "${GIT1[@]}" add -A
    "${GIT1[@]}" commit -qm 'capture: polylang 3.8 to 3.8.7 in-place upgrade'
    "${GIT1[@]}" push -q origin main
    git -C "siterepo/${PAIR}2" pull -q origin main
    wp2 plugin install "$UPGRADE_ARTIFACT_2" --force --activate >/dev/null
    [ "$(wp2 plugin get polylang --field=version)" = 3.8.7 ] \
      || fail 'Polylang target in-place upgrade did not install exact 3.8.7'
    wp2 duo deploy --repo=/siterepo --force-code-drift >/dev/null
    UPGRADE_REV=$(git -C "siterepo/${PAIR}2" rev-parse HEAD)
    wp2 duo apply --repo=/siterepo --default-author=admin --revision="$UPGRADE_REV" --format=json \
      2>&1 | tee "$VMATRIX_APPLY_LOG"
    assert_no_php_diagnostics 'Polylang 3.8 to 3.8.7 upgrade apply' "$VMATRIX_APPLY_LOG"
    UPGRADE_APPLY_JSON=$(awk '/^[{]/ { receipt=$0 } END { print receipt }' "$VMATRIX_APPLY_LOG")
    require_duo_answered 'Polylang 3.8 to 3.8.7 upgrade apply' json "$UPGRADE_APPLY_JSON"
    jq -e '.canary == "clean" and .verification.result == "pass"' <<<"$UPGRADE_APPLY_JSON" >/dev/null \
      || fail 'Polylang 3.8 -> 3.8.7 apply canary was not clean'
    SAVED_POLYLANG_VERSION="$POLYLANG_VERSION"
    POLYLANG_VERSION=3.8.7
    check_polylang_content
    POLYLANG_VERSION="$SAVED_POLYLANG_VERSION"
    UPGRADED_TITLE=$(wp2 post get "$UPGRADE_POST" --field=post_title 2>/dev/null || true)
    [ "$UPGRADED_TITLE" != 'Polylang 3.8 vers 3.8.7 française 東京 🚀' ] \
      || fail 'Polylang upgrade assertion accidentally consumed the source-local post id on the target'
    UPGRADED_TITLE=$(wp2 post list --post_type=post --name=portable-polylang-story-fr --field=post_title)
    [ "$UPGRADED_TITLE" = 'Polylang 3.8 vers 3.8.7 française 東京 🚀' ] \
      || fail "Polylang 3.8.7 did not consume the translated post authored under 3.8: $UPGRADED_TITLE"
    wp2 duo capture --repo=/siterepo --out=/siterepo/.tmp-polylang-upgraded-final
    UPGRADE_DIFF=$(diff -rq "siterepo/${PAIR}1/state" "siterepo/${PAIR}2/.tmp-polylang-upgraded-final" || true)
    rm -rf "siterepo/${PAIR}2/.tmp-polylang-upgraded-final"
    [ -z "$UPGRADE_DIFF" ] \
      || fail "Polylang 3.8 -> 3.8.7 in-place upgrade lost byte identity: $UPGRADE_DIFF"
    pass 'polylang 3.8 -> 3.8.7 in-place upgrade preserves native multilingual behavior, target-local identity, ordering and byte-identical state'
  fi
done

fi

# WooCommerce 11.0.0 is the declared minimum and 11.0.1 is the current exact
# release below the exclusive 11.0.2 bound. Certify both artifacts, then upgrade populated 11.0.0
# environments in place so a fresh 11.0.1 install is not mistaken for upgrade
# compatibility.
if [ "$VMATRIX_MANIFEST" = woocommerce ]; then
VMATRIX_CASES=$((VMATRIX_CASES + 1))
for WOO_VERSION in 11.0.0 11.0.1; do
  say "boundary: woocommerce $WOO_VERSION"

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
  establish_woocommerce_hpos wp1 >/dev/null \
    || fail "side 1 could not establish HPOS through WooCommerce's native new-shop lifecycle"
  pass "side 1: woocommerce $WOO_VERSION installed from verified artifact, active, HPOS enabled"

  cat > "siterepo/${PAIR}1/site.duo.json" <<EOF
{
  "manifests": ["core", "woocommerce"],
  "policy": {
    "options": {},
    "post_meta": {},
    "post_types": ["post", "page", "attachment", "product", "product_variation", "shop_coupon"],
    "taxonomies": ["category", "post_tag", "product_brand", "product_cat", "product_shipping_class", "product_tag", "product_type", "product_visibility"]
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
  normalize_woocommerce_harness_placeholder_mode wp2
  postdeploy_woocommerce_content
  REV=$(git -C "siterepo/${PAIR}2" rev-parse HEAD)
  wp2 duo apply --repo=/siterepo --adopt-by-slug=terms,posts --default-author=admin --revision="$REV" 2>&1 | tee "$VMATRIX_APPLY_LOG"
  grep -q 'canary clean' "$VMATRIX_APPLY_LOG" || fail "apply canary not clean at woocommerce $WOO_VERSION"
  WOOCOMMERCE_BOUNDARY_PROVIDER_RECEIPT=$(cat "$VMATRIX_APPLY_LOG")
  pass "deploy + apply succeeded on side 2 (woocommerce $WOO_VERSION, HPOS, canary clean)"

  postapply_woocommerce_content
  check_woocommerce_content

  wp2 duo capture --repo=/siterepo --out="/siterepo/.tmp-final"
  DIFF_OUT=$(diff -rq "siterepo/${PAIR}1/state" "siterepo/${PAIR}2/.tmp-final" || true)
  rm -rf "siterepo/${PAIR}2/.tmp-final"
  [ -z "$DIFF_OUT" ] || fail "byte-identity broken at woocommerce $WOO_VERSION: $DIFF_OUT"
  pass "byte-identical recapture at woocommerce $WOO_VERSION — the exact in-range release is proven through the full product path"

  check_woocommerce_product_delete_refusal "$WOO_VERSION"

  check_woocommerce_boundary_lifecycle "$WOO_VERSION" "$ARTIFACT_2"

  if [ "$WOO_VERSION" = 11.0.0 ]; then
    say 'in-place upgrade: populated woocommerce 11.0.0 -> exact 11.0.1 on both environments'
    UPGRADE_ARTIFACT_1=$(fetch_artifact woocommerce 11.0.1 cli1)
    UPGRADE_ARTIFACT_2=$(fetch_artifact woocommerce 11.0.1 cli2)
    wp1 plugin install "$UPGRADE_ARTIFACT_1" --force --activate >/dev/null
    [ "$(wp1 plugin get woocommerce --field=version)" = 11.0.1 ] \
      || fail 'WooCommerce source in-place upgrade did not install exact 11.0.1'
    wp1 duo deploy --repo=/siterepo --force-code-drift >/dev/null
    wp1 eval '
      $product=wc_get_product(wc_get_product_id_by_sku("CONF-WIDGET-1"));
      if (!$product) { throw new RuntimeException("upgrade product missing"); }
      $product->set_purchase_note("WooCommerce 11.0.0 to 11.0.1 upgrade 東京 🚀");
      $product->save();
    ' >/dev/null
    wp1 duo capture --repo=/siterepo
    wp1 duo lint --repo=/siterepo
    "${GIT1[@]}" add -A
    "${GIT1[@]}" commit -qm 'capture: woocommerce 11.0.0 to 11.0.1 in-place upgrade'
    "${GIT1[@]}" push -q origin main

    wp2 plugin install "$UPGRADE_ARTIFACT_2" --force --activate >/dev/null
    [ "$(wp2 plugin get woocommerce --field=version)" = 11.0.1 ] \
      || fail 'WooCommerce target in-place upgrade did not install exact 11.0.1'
    git -C "siterepo/${PAIR}2" pull -q origin main
    wp2 duo deploy --repo=/siterepo --force-code-drift >/dev/null
    normalize_woocommerce_harness_placeholder_mode wp2
    UPGRADE_REV=$(git -C "siterepo/${PAIR}2" rev-parse HEAD)
    woocommerce_preapply_authority_assertion 11.0.1 'in-place upgrade'
    wp2 duo apply --repo=/siterepo --adopt-by-slug=terms,posts --default-author=admin --revision="$UPGRADE_REV" \
      2>&1 | tee "$VMATRIX_APPLY_LOG"
    grep -q 'canary clean' "$VMATRIX_APPLY_LOG" \
      || fail 'apply canary not clean after woocommerce 11.0.0 to 11.0.1 in-place upgrade'
    UPGRADE_PROVIDER_COUNT=$(grep -Ec 'provider capability fired:' "$VMATRIX_APPLY_LOG" || true)
    [ "$UPGRADE_PROVIDER_COUNT" -eq 1 ] \
      && grep -Eq 'provider capability fired: woocommerce-product-lookups@3\.0\.0 rebuild_product_lookups \([0-9]+(\.[0-9]+)?s, verified\)' "$VMATRIX_APPLY_LOG" \
      || fail 'WooCommerce 11.0.0 -> 11.0.1 product-note upgrade did not invoke exactly one verified product-lookup provider'
    SAVED_WOO_VERSION="$WOO_VERSION"
    WOO_VERSION=11.0.1
    check_woocommerce_content
    WOO_VERSION="$SAVED_WOO_VERSION"
    UPGRADE_NOTE=$(wp2 eval '
      $product=wc_get_product(wc_get_product_id_by_sku("CONF-WIDGET-1"));
      echo $product ? $product->get_purchase_note("edit") : "";
    ')
    [ "$UPGRADE_NOTE" = 'WooCommerce 11.0.0 to 11.0.1 upgrade 東京 🚀' ] \
      || fail "WooCommerce 11.0.1 did not preserve/apply the product authored during upgrade: $UPGRADE_NOTE"
    wp2 duo capture --repo=/siterepo --out=/siterepo/.tmp-woo-upgrade-final
    UPGRADE_DIFF_RC=0
    UPGRADE_DIFF=$(diff -r "siterepo/${PAIR}1/state" "siterepo/${PAIR}2/.tmp-woo-upgrade-final" 2>&1) \
      || UPGRADE_DIFF_RC=$?
    [ "$UPGRADE_DIFF_RC" -le 1 ] \
      || fail "WooCommerce 11.0.0 to 11.0.1 in-place upgrade recapture comparison errored: $UPGRADE_DIFF"
    if [ -n "$UPGRADE_DIFF" ]; then
      UNEXPECTED_UPGRADE_DIFF=$(grep -Ev \
        -e '^diff -r .*/state/posts/(product|product_variation)/[^ ]+ .*/\.tmp-woo-upgrade-final/posts/(product|product_variation)/[^ ]+$' \
        -e '^[0-9]+(,[0-9]+)?c[0-9]+(,[0-9]+)?$' \
        -e '^---$' \
        -e '^[<>]     "modified(_gmt)?": "[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}",$' \
        <<<"$UPGRADE_DIFF" || true)
      [ -z "$UNEXPECTED_UPGRADE_DIFF" ] \
        || fail "WooCommerce 11.0.0 to 11.0.1 in-place upgrade recapture diverged outside declared derived product timestamps: $UPGRADE_DIFF"
    fi
    rm -rf "siterepo/${PAIR}2/.tmp-woo-upgrade-final"
    pass 'populated woocommerce 11.0.0 -> 11.0.1 upgrade preserves native catalog/API behavior, applies cleanly, and recaptures exactly modulo declared derived product timestamps'

    check_woocommerce_in_range_downgrade "$ARTIFACT_1" "$ARTIFACT_2"
  fi
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
say "negative control: polylang 3.7 (real wp.org release, immediately below manifests/polylang.json's corrected min 3.8) must be REFUSED, not silently accepted"
reset_env wp1
reset_case_repositories

# Build valid canonical state at the certified upper boundary, then replace
# only the installed plugin bytes. The refusal therefore proves the version
# gate against a real Polylang state tree rather than an empty repository.
IN_RANGE_ARTIFACT=$(fetch_artifact polylang 3.8.7 cli1)
wp1 plugin install "$IN_RANGE_ARTIFACT" --activate >/dev/null
NEGATIVE_INSTALLED=$(wp1 plugin get polylang --field=version)
require_fixture_values NEGATIVE_INSTALLED
[ "$NEGATIVE_INSTALLED" = "3.8.7" ] \
  || fail "negative control premise did not install exact polylang 3.8.7 bytes"
cat > "siterepo/${PAIR}1/site.duo.json" <<'EOF'
{
  "manifests": ["core", "polylang"],
  "policy": {
    "options": {},
    "post_meta": {},
    "post_types": ["post", "page", "wp_block", "attachment"],
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
OUT_OF_RANGE_ARTIFACT=$(fetch_artifact polylang 3.7 cli1)
wp1 plugin install "$OUT_OF_RANGE_ARTIFACT" >/dev/null
INSTALLED_OOR=$(wp1 plugin get polylang --field=version)
[ "$INSTALLED_OOR" = "3.7" ] || fail "negative control: expected polylang 3.7 installed, got $INSTALLED_OOR"

set +e
DEPLOY_OUT=$(wp1 duo deploy --repo=/siterepo 2>&1)
DEPLOY_RC=$?
set -e
[ "$DEPLOY_RC" -ne 0 ] || fail "expected deploy to refuse polylang 3.7 as outside_version_range, but it exited 0 (got: $DEPLOY_OUT)"
grep -Eq "outside_version_range|outside the '.*' manifest's declared version_range" <<<"$DEPLOY_OUT" \
  || fail "deploy refused, but not for the expected outside_version_range reason (got: $DEPLOY_OUT)"
grep -q "polylang/polylang.php" <<<"$DEPLOY_OUT" || fail "refusal did not name the plugin (got: $DEPLOY_OUT)"
grep -q "3.7" <<<"$DEPLOY_OUT" || fail "refusal did not name the actually-installed version (got: $DEPLOY_OUT)"
printf '%s\n' "$DEPLOY_OUT"
pass "confirmed: polylang 3.7 (real, installed, immediately below the corrected 3.8 min) is loudly refused by Deploy::code_mismatch() — the version_range pin is honest, not just decorative"

# The declared upper bound has no real 3.8.8 release to install. Mutating
# ONLY the already-digest-verified 3.8.7 header makes WordPress's real plugin
# header parser report 3.8.8 while preserving the same canonical repository.
# This is the exact boundary Deploy compares, rather than a guessed semantic
# version helper or a synthetic manifest.
wp1 plugin delete polylang >/dev/null
UPPER_BOUND_ARTIFACT=$(fetch_artifact polylang 3.8.7 cli1)
wp1 plugin install "$UPPER_BOUND_ARTIFACT" --activate >/dev/null
[ "$(wp1 plugin get polylang --field=version)" = "3.8.7" ] \
  || fail 'synthetic upper-bound premise did not install exact Polylang 3.8.7'
POLY_SYNTHETIC_HEAD_BEFORE=$(git -C "siterepo/${PAIR}1" rev-parse HEAD)
wp1 eval '
$file = WP_PLUGIN_DIR . "/polylang/polylang.php";
$source = @file_get_contents($file);
if (!is_string($source)) throw new RuntimeException("synthetic Polylang header source is unreadable");
$updated = preg_replace("/^ \\* Version:[^\\r\\n]*$/m", " * Version:           3.8.8", $source, 1, $count);
if (!is_string($updated) || $count !== 1) throw new RuntimeException("synthetic Polylang version header replacement was not exact");
if (file_put_contents($file, $updated) !== strlen($updated)) throw new RuntimeException("synthetic Polylang version header write failed");
' >/dev/null
SYNTHETIC_UPPER_VERSION=$(wp1 plugin get polylang --field=version)
[ "$SYNTHETIC_UPPER_VERSION" = '3.8.8' ] \
  || fail "synthetic upper-bound premise did not expose the real plugin header as 3.8.8 (got: $SYNTHETIC_UPPER_VERSION)"
set +e
DEPLOY_OUT=$(wp1 duo deploy --repo=/siterepo 2>&1)
DEPLOY_RC=$?
set -e
[ "$DEPLOY_RC" -ne 0 ] \
  || fail "expected deploy to refuse synthetic Polylang 3.8.8 as outside_version_range, but it exited 0 (got: $DEPLOY_OUT)"
grep -Eq "outside_version_range|outside the '.*' manifest's declared version_range" <<<"$DEPLOY_OUT" \
  || fail "synthetic Polylang 3.8.8 refusal was not outside_version_range (got: $DEPLOY_OUT)"
grep -q 'polylang/polylang.php' <<<"$DEPLOY_OUT" \
  || fail "synthetic Polylang 3.8.8 refusal did not name the plugin (got: $DEPLOY_OUT)"
grep -q '3.8.8' <<<"$DEPLOY_OUT" \
  || fail "synthetic Polylang 3.8.8 refusal did not name the installed header version (got: $DEPLOY_OUT)"
[ "$(git -C "siterepo/${PAIR}1" rev-parse HEAD)" = "$POLY_SYNTHETIC_HEAD_BEFORE" ] \
  || fail 'synthetic Polylang 3.8.8 refusal mutated the repository ref'
wp1 plugin install "$UPPER_BOUND_ARTIFACT" --force --activate >/dev/null
[ "$(wp1 plugin get polylang --field=version)" = '3.8.7' ] \
  || fail 'synthetic Polylang 3.8.8 probe did not restore exact 3.8.7 artifact bytes'
pass 'synthetic Polylang 3.8.8 header is loudly refused at the exclusive upper bound without a repository mutation'

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
establish_woocommerce_hpos wp1 >/dev/null \
  || fail "negative-control source could not establish HPOS through WooCommerce's native new-shop lifecycle"
cat > "siterepo/${PAIR}1/site.duo.json" <<'EOF'
{
  "manifests": ["core", "woocommerce"],
  "policy": {
    "options": {},
    "post_meta": {},
    "post_types": ["post", "page", "attachment", "product", "product_variation", "shop_coupon"],
    "taxonomies": ["category", "post_tag", "product_brand", "product_cat", "product_shipping_class", "product_tag", "product_type", "product_visibility"]
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

say "negative control: woocommerce synthetic 11.0.2 (the exclusive upper endpoint) must be REFUSED before deploy mutates lifecycle state"
reset_env wp1
reset_case_repositories

# wp.org does not supply a published 11.0.2 archive. Start from the exact
# admitted 11.0.1 artifact and replace only its Version header in this
# disposable container. That is the narrowest executable upper-bound fixture:
# WordPress itself parses the synthetic endpoint, while every other source byte
# and the captured Woo state remain the reviewed 11.0.1 product path.
IN_RANGE_ARTIFACT=$(fetch_artifact woocommerce 11.0.1 cli1)
wp1 plugin install "$IN_RANGE_ARTIFACT" --activate >/dev/null
[ "$(wp1 plugin get woocommerce --field=version)" = "11.0.1" ] \
  || fail "upper-bound premise did not install exact WooCommerce 11.0.1 bytes"
establish_woocommerce_hpos wp1 >/dev/null \
  || fail "upper-bound source could not establish HPOS through WooCommerce's native new-shop lifecycle"
cat > "siterepo/${PAIR}1/site.duo.json" <<'EOF'
{
  "manifests": ["core", "woocommerce"],
  "policy": {
    "options": {},
    "post_meta": {},
    "post_types": ["post", "page", "attachment", "product", "product_variation", "shop_coupon"],
    "taxonomies": ["category", "post_tag", "product_brand", "product_cat", "product_shipping_class", "product_tag", "product_type", "product_visibility"]
  },
  "spec_version": 2
}
EOF
cp site-repo.gitignore.template "siterepo/${PAIR}1/.gitignore"
"${GIT1[@]}" init -q -b main
"${GIT1[@]}" remote add origin "../origin-$PAIR.git"
"${GIT1[@]}" add -A
"${GIT1[@]}" commit -qm "policy: woocommerce exclusive-upper negative-control pin"
"${GIT1[@]}" push -qu origin main
seed_woocommerce_content
wp1 duo capture --repo=/siterepo
"${GIT1[@]}" add -A
"${GIT1[@]}" commit -qm "capture: valid WooCommerce state for exclusive-upper negative control"
"${GIT1[@]}" push -q origin main

wp1 plugin deactivate woocommerce >/dev/null
wp1 eval '
$path = WP_PLUGIN_DIR . "/woocommerce/woocommerce.php";
$bytes = file_get_contents($path);
if (!is_string($bytes) || substr_count($bytes, " * Version: 11.0.1") !== 1) {
    throw new RuntimeException("exclusive-upper fixture did not find one 11.0.1 Version header");
}
$next = preg_replace("/^ \\* Version: 11\\.0\\.1$/m", " * Version: 11.0.2", $bytes, 1);
if (!is_string($next) || $next === $bytes || file_put_contents($path, $next) !== strlen($next)) {
    throw new RuntimeException("exclusive-upper fixture could not replace the inactive plugin Version header");
}
' >/dev/null
UPPER_INSTALLED=$(wp1 plugin get woocommerce --field=version)
[ "$UPPER_INSTALLED" = "11.0.2" ] \
  || fail "exclusive-upper fixture expected WordPress to parse WooCommerce 11.0.2, got $UPPER_INSTALLED"
PRE_REFUSAL_ACTIVE=$(wp1 option get active_plugins --format=json | tail -1)
PRE_REFUSAL_HEAD=$(git -C "siterepo/${PAIR}1" rev-parse HEAD)
PRE_REFUSAL_REPO=$(git -C "siterepo/${PAIR}1" status --porcelain)

set +e
DEPLOY_OUT=$(wp1 duo deploy --repo=/siterepo 2>&1)
DEPLOY_RC=$?
set -e
[ "$DEPLOY_RC" -ne 0 ] || fail "expected deploy to refuse synthetic WooCommerce 11.0.2 as outside_version_range, but it exited 0 (got: $DEPLOY_OUT)"
grep -Eq "outside_version_range|outside the '.*' manifest's declared version_range" <<<"$DEPLOY_OUT" \
  || fail "exclusive-upper deploy refused, but not for outside_version_range (got: $DEPLOY_OUT)"
grep -q "woocommerce/woocommerce.php" <<<"$DEPLOY_OUT" \
  || fail "exclusive-upper refusal did not name the WooCommerce plugin (got: $DEPLOY_OUT)"
grep -q "11.0.2" <<<"$DEPLOY_OUT" \
  || fail "exclusive-upper refusal did not name WordPress's installed Version header (got: $DEPLOY_OUT)"
[ "$(wp1 option get active_plugins --format=json | tail -1)" = "$PRE_REFUSAL_ACTIVE" ] \
  || fail "exclusive-upper code mismatch changed active_plugins before refusing"
[ "$(git -C "siterepo/${PAIR}1" rev-parse HEAD)" = "$PRE_REFUSAL_HEAD" ] \
  || fail "exclusive-upper code mismatch changed the captured repository revision before refusing"
[ "$(git -C "siterepo/${PAIR}1" status --porcelain)" = "$PRE_REFUSAL_REPO" ] \
  || fail "exclusive-upper code mismatch changed the captured repository before refusing"
printf '%s\n' "$DEPLOY_OUT"
pass "confirmed: synthetic WooCommerce 11.0.2 is rejected at the exclusive upper bound before deploy changes lifecycle state or the captured repository"
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
