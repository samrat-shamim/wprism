#!/usr/bin/env bash
# Certify one adapter package against the exact artifact boundaries owned by
# that package. This driver owns only shared pair lifecycle, repository, and
# assertion helpers. The selected package capsule owns its plugin slug, reset
# hooks, admitted cases, negative controls, and any candidate-source preflight.
# One invocation selects and sources exactly one capsule; sibling package code
# is neither evaluated nor able to influence the selected certification run.
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
# premise/answer assertion helpers (issue #3381/issue #3391); they live in one
# fragment precisely so this second harness cannot strand them (issue #3408 —
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
  "../adapter-packages/$VMATRIX_MANIFEST/package/disposition.json" >/dev/null \
  || fail "manifest '$VMATRIX_MANIFEST' does not declare exact-artifact-version-matrix evidence"
VMATRIX_CAPSULE="../adapter-packages/$VMATRIX_MANIFEST/tests/certify/version-matrix.sh"
[ -f "$VMATRIX_CAPSULE" ] && [ ! -L "$VMATRIX_CAPSULE" ] \
  || fail "manifest '$VMATRIX_MANIFEST' has no package-owned certification workflow"
# shellcheck source=/dev/null
. "$VMATRIX_CAPSULE"
declare -F version_matrix_workflow >/dev/null \
  || fail "manifest '$VMATRIX_MANIFEST' certification capsule does not define version_matrix_workflow"
[[ "${VMATRIX_PLUGIN_SLUG:-}" =~ ^[a-z][a-z0-9-]*$ ]] \
  || fail "manifest '$VMATRIX_MANIFEST' certification capsule does not define one canonical VMATRIX_PLUGIN_SLUG"
# Normalize the public matrix variable before the capsule preflight. Candidate-
# bound capsules validate and export their source root there; invoking them
# first would make the documented VMATRIX_EXPECTED_SOURCE_SHA interface fail
# before the shared driver had translated it.
if [ -n "${VMATRIX_EXPECTED_SOURCE_SHA:-}" ]; then
  export WPRISM_EXPECTED_SOURCE_SHA="$VMATRIX_EXPECTED_SOURCE_SHA"
fi
if declare -F version_matrix_preflight >/dev/null; then
  version_matrix_preflight
fi
VMATRIX_CASES=0
WORDPRESS_OFFLINE="${WPRISM_WORDPRESS_ORG_OFFLINE:-0}"
case "$WORDPRESS_OFFLINE" in
  0|1) ;;
  *) fail "WPRISM_WORDPRESS_ORG_OFFLINE must be 0 or 1" ;;
esac
export WPRISM_PAIR="$PAIR"
# A boundary result is evidence only for the agent and adapter-library source bytes that the
# pair mounts. Export before the first pair.sh call: `up` can allocate the
# databases and start containers, so setting it later would certify a stale
# canonical checkout rather than this candidate.
# Boundary observations use fresh direct Compose processes. Keep the selected
# mounts in this shell; shared .env can legitimately move when another pair is
# cleaned up and therefore cannot carry this matrix's candidate identity.
. lib/pair_identity.sh
pair_identity_export_source_mounts \
  || fail 'version matrix could not pin its selected source mounts in the caller environment'
PAIR_COMPOSE=(docker compose -p "wprism-$PAIR" -f pair.yml -f pair.artifacts.yml)
PAIR_UP_FLAGS=(--artifacts)
if [ "$WORDPRESS_OFFLINE" = 1 ]; then
  PAIR_COMPOSE+=(-f pair.wordpress-offline.yml)
  PAIR_UP_FLAGS+=(--wordpress-offline)
fi
export WPRISM_ARTIFACT_OFFLINE="$WORDPRESS_OFFLINE"
PAIR_COMPOSE_STRING="${PAIR_COMPOSE[*]}"
VMATRIX_APPLY_LOG=$(mktemp "${TMPDIR:-/tmp}/wprism-vmatrix-apply.${PAIR}.XXXXXX")
. lib/host_orchestrator.sh
VMATRIX_HOST_REGISTRY=$(mktemp "${TMPDIR:-/tmp}/wprism-vmatrix-host.${PAIR}.XXXXXX")
trap 'rm -f -- "$VMATRIX_APPLY_LOG" "$VMATRIX_HOST_REGISTRY"' EXIT
wprism_host_registry_create "$VMATRIX_HOST_REGISTRY" "$(pwd)/pair.yml" "$PAIR"
VMATRIX_HOST_CLI="$(cd .. && pwd)/cli/wprism"
host_wprism_vmatrix() { # <wp1|wp2> <verb> [args...]
  local side="$1"
  shift
  wprism_host_call \
    "$VMATRIX_HOST_CLI" "$VMATRIX_HOST_REGISTRY" "wprism-$PAIR" \
    "${PAIR}${side#wp}" "$@"
}
wp1() { "${PAIR_COMPOSE[@]}" run --rm -T cli1 sh -c 'umask 000; exec wp "$@"' sh "$@"; }
wp2() { "${PAIR_COMPOSE[@]}" run --rm -T cli2 sh -c 'umask 000; exec wp "$@"' sh "$@"; }
GIT1=(git -C "siterepo/${PAIR}1" -c user.name=wprism-vmatrix1 -c user.email=vmatrix1@example.test)

. bin/fetch-artifact.sh

normalize_version_matrix_archive_root() { # <service> <plugin|theme> <slug> <archive-root>
  local service="$1" kind="$2" slug="$3" archive_root="$4" base
  [ "$archive_root" != "$slug" ] || return 0
  case "$kind" in
    plugin) base=/var/www/html/wp-content/plugins ;;
    theme) base=/var/www/html/wp-content/themes ;;
    *) fail "invalid version-matrix extension kind for archive-root normalization" ;;
  esac
  "${PAIR_COMPOSE[@]}" run --rm -T "$service" sh /wprism-harness/artifact-archive-root.sh \
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
  wprism_host_install_recovery_runtime "$PAIR_SOURCE_ROOT" "siterepo/${PAIR}1" \
    || fail 'version matrix could not install the source recovery runtime'
  wprism_host_install_recovery_runtime "$PAIR_SOURCE_ROOT" "siterepo/${PAIR}2" \
    || fail 'version matrix could not install the target recovery runtime'
}

say "boot pair $PAIR (${PAIR}1 :$PORT1 / ${PAIR}2 :$PORT2), idempotent"
# Package workflows may exercise HTTP surfaces, so publish the already-reserved
# ports rather than running the shared pair headless.
bash bin/pair.sh up "$PAIR" "$PORT1" "$PORT2" "${PAIR_UP_FLAGS[@]}"
pass "pair up"



reset_env() { # reset_env <cli-fn> — content + identity only, keeps WordPress
  # Keep WordPress installed between exact-boundary cases while removing the
  # prior case's authored content and identity. Package-specific lifecycle
  # cleanup is delegated to the one selected capsule.
  local cli="$1"
  if declare -F version_matrix_reset_before_empty >/dev/null; then
    version_matrix_reset_before_empty "$cli"
  fi
  # Uploads are disposable matrix content; use WordPress' bounded cleanup so
  # generated derivatives cannot leak from one exact boundary into the next.
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
  # Rebind the core default category before extension installers can reuse a
  # stale numeric id, then remove all other cross-case category residue.
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
  # A version boundary owns no prior fixture principals. Remove non-admin
  # users through WordPress so lifecycle hooks observe coherent state.
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
  "$cli" plugin deactivate "$VMATRIX_PLUGIN_SLUG" >/dev/null 2>&1 || true
  "$cli" plugin delete "$VMATRIX_PLUGIN_SLUG" >/dev/null 2>&1 || true
  if declare -F version_matrix_reset_after_delete >/dev/null; then
    version_matrix_reset_after_delete "$cli"
  fi
  "$cli" db query "TRUNCATE TABLE wp_wprism_map" >/dev/null 2>&1 || true
  "$cli" db query "TRUNCATE TABLE wp_wprism_state" >/dev/null 2>&1 || true
  "$cli" db query "TRUNCATE TABLE wp_wprism_kv" >/dev/null 2>&1 || true
  "$cli" db query "TRUNCATE TABLE wp_wprism_journal" >/dev/null 2>&1 || true
}


version_matrix_workflow

[ "$VMATRIX_CASES" -gt 0 ] \
  || fail "no exact-artifact matrix fixture is implemented for '$VMATRIX_MANIFEST'"

say "cleanup"
bash bin/pair.sh destroy "$PAIR"
pass "destroyed $PAIR (every assertion above passed)"

printf '\n\033[1;32m✔ CERTIFY_VERSION_MATRIX PASSED\033[0m\n'
