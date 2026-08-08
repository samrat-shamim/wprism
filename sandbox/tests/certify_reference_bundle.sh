#!/usr/bin/env bash
# DUO-3223/DUO-3224 reference certification: core and FSE conformance, the executable
# multisite refusal boundary, and the exact-artifact matrix (which includes
# Ninja Forms' real typed-table graph). Every run, pass or fail, is reduced to
# machine result/diff JSON plus full logs and published by content digest.
# Own one disposable pair at a time; callers parameterize name/ports on a
# shared host. Generated bundles live under an ignored output directory.
set -euo pipefail
cd "$(dirname "$0")/.."   # -> sandbox/
REPO_ROOT=$(cd .. && pwd)

say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*" >&2; exit 1; }

PAIR="${CERT_BUNDLE_PAIR:-certbundle}"
PORT1="${CERT_BUNDLE_PORT1:-8880}"
PORT2="${CERT_BUNDLE_PORT2:-8881}"
OUT_ROOT="${CERT_BUNDLE_OUT:-$PWD/certification-bundles}"
[[ "$PAIR" =~ ^[a-z][a-z0-9]*$ ]] || fail "invalid CERT_BUNDLE_PAIR '$PAIR'"
command -v jq >/dev/null || fail "jq required"
command -v php >/dev/null || fail "php required"
command -v git >/dev/null || fail "git required"

# pair.sh intentionally resolves agent/manifests bind mounts through Git's
# common directory so a long-lived pair never depends on an ephemeral linked
# worktree.  Certification has the opposite requirement: every exercised byte
# must come from the exact clean HEAD whose hashes enter the evidence bundle.
# Refuse before allocating a work root or starting Docker when those roots
# would differ, or when uncommitted/stale mount bytes would make the run
# irreproducible.
assert_exact_certification_checkout() {
  local checkout_root git_dir common_dir common_root env_file
  local expected_agent expected_manifests mounted_agent mounted_manifests
  checkout_root="$(cd "$REPO_ROOT" && pwd -P)"
  git_dir="$(git -C "$REPO_ROOT" rev-parse --path-format=absolute --git-dir 2>/dev/null)" \
    || fail "refusing certification: cannot resolve git-dir for checkout $checkout_root"
  common_dir="$(git -C "$REPO_ROOT" rev-parse --path-format=absolute --git-common-dir 2>/dev/null)" \
    || fail "refusing certification: cannot resolve git-common-dir for checkout $checkout_root"
  common_root="$(dirname "$common_dir")"
  if [ ! -d "$git_dir" ] || [ "$git_dir" != "$common_dir" ] \
      || [ "$common_root" != "$checkout_root" ] || [ -f "$REPO_ROOT/.git" ]; then
    fail "refusing certification from a linked worktree or stale canonical mount; use a clean primary or standalone exact-HEAD clone"
  fi
  if [ -n "$(git -C "$REPO_ROOT" status --porcelain=v1 --untracked-files=all)" ]; then
    fail "refusing certification from a dirty checkout; use a clean primary or standalone exact-HEAD clone"
  fi
  expected_agent="$checkout_root/agent"
  expected_manifests="$checkout_root/manifests"
  env_file="$checkout_root/sandbox/.env"
  if [ -e "$env_file" ]; then
    mounted_agent="$(sed -n 's/^DUO_AGENT_SRC=//p' "$env_file" | head -1)"
    mounted_manifests="$(sed -n 's/^DUO_MANIFESTS_SRC=//p' "$env_file" | head -1)"
    if [ "$mounted_agent" != "$expected_agent" ] || [ "$mounted_manifests" != "$expected_manifests" ]; then
      fail "refusing certification with stale canonical mount registry $env_file; remove it or refresh pair.sh from the clean checkout"
    fi
  fi
  [ -d "$expected_agent" ] || fail "refusing certification: canonical agent mount source is absent: $expected_agent"
  [ -d "$expected_manifests" ] || fail "refusing certification: canonical manifest mount source is absent: $expected_manifests"
}
assert_exact_certification_checkout

WORK_ROOT=$(mktemp -d /tmp/duo-certbundle.XXXXXX)
cleanup_work() {
  case "$WORK_ROOT" in
    /tmp/duo-certbundle.*) rm -rf -- "$WORK_ROOT" ;;
    *) printf 'refusing unsafe work cleanup path: %s\n' "$WORK_ROOT" >&2 ;;
  esac
}
trap cleanup_work EXIT

CORE_LOG="$WORK_ROOT/core-conformance.log"
FSE_LOG="$WORK_ROOT/fse-conformance.log"
MULTISITE_LOG="$WORK_ROOT/multisite-refusal.log"
MATRIX_LOG="$WORK_ROOT/version-matrix.log"
ENV_FILE="$WORK_ROOT/environment.json"

write_result() { # write_result <id> <rc> <reason> <assertions-json> <path>
  local id="$1" rc="$2" reason="$3" assertions="$4" path="$5" verdict=fail
  [ "$rc" -eq 0 ] && verdict=pass
  jq -n \
    --arg test "$id" --arg verdict "$verdict" --arg reason "$reason" \
    --argjson exit_code "$rc" --argjson assertions "$assertions" \
    '{schema_version:1,test:$test,verdict:$verdict,exit_code:$exit_code,reason:$reason,assertions:$assertions}' > "$path"
}

write_skipped() { # write_skipped <id> <log> <result> <diff>
  local id="$1" log="$2" result="$3" diff="$4"
  printf 'SKIPPED: blocked by an earlier failed reference-certification leg\n' > "$log"
  write_result "$id" 99 blocked_by_prior_failure '[]' "$result"
  jq -n '{status:"unknown",reason:"blocked_by_prior_failure"}' > "$diff"
}

say "source/static preflight"
php -l bin/certification-bundle.php >/dev/null
bash -n tests/regress_multisite_refusal.sh tests/certify_version_matrix.sh
bash bin/pair.sh list
pass "bundle builder and all invoked harnesses parse; pair load inspected"

overall=0

say "reference leg 1/4: core conformance through the real deploy/apply path"
set +e
CONF_PAIR="$PAIR" CONF1_PORT="$PORT1" CONF2_PORT="$PORT2" \
  bash conformance/run.sh core > "$CORE_LOG" 2>&1
core_rc=$?
set -e
tail -30 "$CORE_LOG"
core_reason=passed
if [ "$core_rc" -eq 0 ] && ! grep -qF '✔ CONFORMANCE PASSED (core)' "$CORE_LOG"; then
  core_rc=70
  core_reason=invalid_checker_output
fi
if [ "$core_rc" -ne 0 ]; then
  overall=1
  [ "$core_reason" = passed ] && core_reason=command_failed
fi
write_result core-conformance "$core_rc" "$core_reason" \
  '["lint_json_valid","capture_twice_identical","deploy_activation_state","apply_canary_clean","cross_environment_recapture_identical","render_api_checks"]' \
  "$WORK_ROOT/core-conformance.result.json"
jq -n --arg status "$([ "$core_rc" -eq 0 ] && printf clean || printf unknown)" \
  '{status:$status,diffs:["capture-twice","conf1-vs-conf2-recapture"]}' > "$WORK_ROOT/core-conformance.diff.json"

# Collect the exact runtime while the successful core pair still exists.
export DUO_PAIR="$PAIR" DUO_PORT1="$PORT1" DUO_PORT2="$PORT2"
COMPOSE=(docker compose -p "duo-$PAIR" -f pair.yml)
set +e
ENV_OUT=$("${COMPOSE[@]}" run --rm -T cli2 wp eval '
global $wpdb;
echo wp_json_encode([
  "wordpress" => get_bloginfo("version"),
  "php" => PHP_VERSION,
  "database_client" => $wpdb->db_version(),
  "database_server" => $wpdb->get_var("SELECT VERSION()"),
  "multisite" => is_multisite(),
  "active_plugins" => array_values((array) get_option("active_plugins", [])),
  "theme" => ["template" => get_option("template"), "stylesheet" => get_option("stylesheet")],
]);
' 2>"$WORK_ROOT/environment.stderr")
env_rc=$?
set -e
if [ "$env_rc" -eq 0 ] && jq -e 'type == "object"' <<<"$ENV_OUT" >/dev/null 2>&1; then
  printf '%s\n' "$ENV_OUT" | jq . > "$ENV_FILE"
else
  overall=1
  core_rc=71
  write_result core-conformance "$core_rc" environment_collection_failed '[]' "$WORK_ROOT/core-conformance.result.json"
  jq -n --arg host_php "$(php -r 'echo PHP_VERSION;')" \
    --arg diagnostic "$(tr '\n' ' ' < "$WORK_ROOT/environment.stderr")" \
    '{collection:"failed",host_php:$host_php,diagnostic:$diagnostic}' > "$ENV_FILE"
fi

if [ "$core_rc" -eq 0 ]; then
  bash bin/pair.sh destroy "$PAIR"
  pass "core conformance passed and its pair was destroyed"
fi

if [ "$overall" -eq 0 ]; then
  say "reference leg 2/4: FSE conformance through the real deploy/apply path"
  set +e
  CONF_PAIR="$PAIR" CONF1_PORT="$PORT1" CONF2_PORT="$PORT2" \
    bash conformance/run.sh fse > "$FSE_LOG" 2>&1
  fse_rc=$?
  set -e
  tail -30 "$FSE_LOG"
  fse_reason=passed
  if [ "$fse_rc" -eq 0 ] && ! grep -qF '✔ CONFORMANCE PASSED (fse)' "$FSE_LOG"; then
    fse_rc=70
    fse_reason=invalid_checker_output
  fi
  if [ "$fse_rc" -ne 0 ]; then
    overall=1
    [ "$fse_reason" = passed ] && fse_reason=command_failed
  fi
  write_result fse-conformance "$fse_rc" "$fse_reason" \
    '["block_theme_fixture","templates","template_parts","navigation","reusable_blocks","theme_taxonomies","byte_identical_recapture"]' \
    "$WORK_ROOT/fse-conformance.result.json"
  jq -n --arg status "$([ "$fse_rc" -eq 0 ] && printf clean || printf unknown)" \
    '{status:$status,diffs:["capture-twice","conf1-vs-conf2-recapture"]}' > "$WORK_ROOT/fse-conformance.diff.json"
  if [ "$fse_rc" -eq 0 ]; then
    bash bin/pair.sh destroy "$PAIR"
    pass "FSE conformance passed and its pair was destroyed"
  fi
else
  write_skipped fse-conformance "$FSE_LOG" "$WORK_ROOT/fse-conformance.result.json" "$WORK_ROOT/fse-conformance.diff.json"
fi

if [ "$overall" -eq 0 ]; then
  say "reference leg 3/4: real WordPress multisite must refuse with zero mutation"
  set +e
  MULTISITE_PAIR="$PAIR" MULTISITE_PORT1="$PORT1" MULTISITE_PORT2="$PORT2" \
    bash tests/regress_multisite_refusal.sh > "$MULTISITE_LOG" 2>&1
  multisite_rc=$?
  set -e
  tail -30 "$MULTISITE_LOG"
  multisite_reason=passed
  if [ "$multisite_rc" -eq 0 ] && ! grep -qF '✔ REGRESS_MULTISITE_REFUSAL PASSED' "$MULTISITE_LOG"; then
    multisite_rc=70
    multisite_reason=invalid_checker_output
  fi
  if [ "$multisite_rc" -ne 0 ]; then
    overall=1
    [ "$multisite_reason" = passed ] && multisite_reason=command_failed
  fi
  write_result multisite-refusal "$multisite_rc" "$multisite_reason" \
    '["wordpress_runtime_reports_multisite","capture_nonzero","actionable_single_site_boundary","no_repository_publication","authored_canary_unchanged"]' \
    "$WORK_ROOT/multisite-refusal.result.json"
  jq -n --arg status "$([ "$multisite_rc" -eq 0 ] && printf no_mutation || printf unknown)" \
    '{status:$status,checked:["site.duo.json","state","capture-staging","capture-backup","wordpress-option-canary"]}' > "$WORK_ROOT/multisite-refusal.diff.json"
else
  write_skipped multisite-refusal "$MULTISITE_LOG" "$WORK_ROOT/multisite-refusal.result.json" "$WORK_ROOT/multisite-refusal.diff.json"
fi

if [ "$overall" -eq 0 ]; then
  say "reference leg 4/4: exact-artifact version matrix (includes Ninja Forms typed tables)"
  bash bin/pair.sh list
  set +e
  VMATRIX_PAIR="$PAIR" VMATRIX_PORT1="$PORT1" VMATRIX_PORT2="$PORT2" \
    bash tests/certify_version_matrix.sh > "$MATRIX_LOG" 2>&1
  matrix_rc=$?
  set -e
  tail -40 "$MATRIX_LOG"
  matrix_reason=passed
  if [ "$matrix_rc" -eq 0 ] && ! grep -qF '✔ CERTIFY_VERSION_MATRIX PASSED' "$MATRIX_LOG"; then
    matrix_rc=70
    matrix_reason=invalid_checker_output
  fi
  if [ "$matrix_rc" -ne 0 ]; then
    overall=1
    [ "$matrix_reason" = passed ] && matrix_reason=command_failed
  fi
  write_result exact-artifact-version-matrix "$matrix_rc" "$matrix_reason" \
    '["digest_verified_artifacts","declared_min_boundaries","max_practical_boundaries","typed_table_ninja_forms","byte_identical_recapture","below_range_loud_refusal"]' \
    "$WORK_ROOT/exact-artifact-version-matrix.result.json"
  jq -n --arg status "$([ "$matrix_rc" -eq 0 ] && printf clean || printf unknown)" \
    '{status:$status,diffs:["all-in-range-boundary-recaptures"],negative_controls:"all-below-range-releases-refused"}' > "$WORK_ROOT/exact-artifact-version-matrix.diff.json"
else
  write_skipped exact-artifact-version-matrix "$MATRIX_LOG" "$WORK_ROOT/exact-artifact-version-matrix.result.json" "$WORK_ROOT/exact-artifact-version-matrix.diff.json"
fi

say "materialize the content-addressed machine-readable bundle"
BOUND_INPUTS=$({ git -C "$REPO_ROOT" ls-files \
  agent cli manifests sandbox/bin sandbox/conformance \
  sandbox/tests/certify_reference_bundle.sh \
  sandbox/tests/certify_version_matrix.sh \
  sandbox/tests/regress_multisite_refusal.sh \
  scripts/capability-registry.php docs/compatibility-baseline.json \
  DESIGN.md spec/repo-format.md Makefile .github/workflows/conformance.yml; \
  printf '%s\n' manifests/dispositions.json; } \
  | grep -v '^manifests/capabilities/' | sort -u | jq -R . | jq -s .)
ARTIFACTS=$(jq '[to_entries[] as $slug | $slug.value | to_entries[] | {name:$slug.key,version:.key,url:.value.url,sha256:.value.sha256}]' conformance/artifacts.lock.json)
CREATED_AT=$(date -u '+%Y-%m-%dT%H:%M:%SZ')
GIT_REVISION=$(git -C "$REPO_ROOT" rev-parse HEAD)
jq -n \
  --arg repo_root "$REPO_ROOT" --arg created_at "$CREATED_AT" --arg git_revision "$GIT_REVISION" \
  --arg environment "$ENV_FILE" --argjson bound_inputs "$BOUND_INPUTS" --argjson artifacts "$ARTIFACTS" \
  --arg ratification "$REPO_ROOT/manifests/dispositions.json" \
  --arg core_result "$WORK_ROOT/core-conformance.result.json" --arg core_diff "$WORK_ROOT/core-conformance.diff.json" --arg core_log "$CORE_LOG" \
  --arg fse_result "$WORK_ROOT/fse-conformance.result.json" --arg fse_diff "$WORK_ROOT/fse-conformance.diff.json" --arg fse_log "$FSE_LOG" \
  --arg ms_result "$WORK_ROOT/multisite-refusal.result.json" --arg ms_diff "$WORK_ROOT/multisite-refusal.diff.json" --arg ms_log "$MULTISITE_LOG" \
  --arg matrix_result "$WORK_ROOT/exact-artifact-version-matrix.result.json" --arg matrix_diff "$WORK_ROOT/exact-artifact-version-matrix.diff.json" --arg matrix_log "$MATRIX_LOG" \
  '{
    repo_root:$repo_root,created_at:$created_at,git_revision:$git_revision,
    harness:{name:"duo-reference-certification",version:2},force_hatches:[],
    environment:$environment,ratification:$ratification,bound_inputs:$bound_inputs,artifacts:$artifacts,
    tests:[
      {id:"core-conformance",result:$core_result,diff:$core_diff,log:$core_log},
      {id:"fse-conformance",result:$fse_result,diff:$fse_diff,log:$fse_log},
      {id:"multisite-refusal",result:$ms_result,diff:$ms_diff,log:$ms_log},
      {id:"exact-artifact-version-matrix",result:$matrix_result,diff:$matrix_diff,log:$matrix_log}
    ]
  }' > "$WORK_ROOT/spec.json"

set +e
BUILD_OUT=$(php bin/certification-bundle.php build "$WORK_ROOT/spec.json" "$OUT_ROOT")
build_rc=$?
set -e
printf '%s\n' "$BUILD_OUT" | jq .
BUNDLE=$(jq -r '.bundle // empty' <<<"$BUILD_OUT")
[ -n "$BUNDLE" ] || fail "bundle builder produced no bundle path"

set +e
VERIFY_OUT=$(php bin/certification-bundle.php verify "$BUNDLE" "$REPO_ROOT")
verify_rc=$?
set -e
printf '%s\n' "$VERIFY_OUT" | jq .

if [ "$overall" -ne 0 ] || [ "$build_rc" -ne 0 ] || [ "$verify_rc" -ne 0 ]; then
  fail "reference certification failed; immutable evidence remains at $BUNDLE"
fi

LIVE_CONTAINERS=$(docker ps --format '{{.Names}}')
if grep -qE "^duo-${PAIR}-" <<<"$LIVE_CONTAINERS"; then
  fail "own pair '$PAIR' is still running after a green reference certification"
fi

pass "reference bundle is valid, content-addressed, input-bound, and immediately re-verified"
printf '\n\033[1;32m✔ CERTIFY_REFERENCE_BUNDLE PASSED (%s)\033[0m\n' "$(basename "$BUNDLE")"
