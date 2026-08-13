#!/usr/bin/env bash
# DUO-3306 reference certification: every shipped conformance manifest emits
# named machine evidence, followed by the executable multisite refusal boundary
# and exact-artifact version matrix. Every run, pass or fail, is reduced to
# result/diff JSON plus a full log and published by content digest.
set -euo pipefail
# BASH_SOURCE keeps the runner rooted at its own checkout before it sources
# the extracted lock boundary.
cd "$(dirname "${BASH_SOURCE[0]}")/.."   # -> sandbox/
REPO_ROOT=$(cd .. && pwd)

say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*" >&2; exit 1; }

PAIR="${CERT_BUNDLE_PAIR:-certbundle}"
PORT1="${CERT_BUNDLE_PORT1:-8880}"
PORT2="${CERT_BUNDLE_PORT2:-8881}"
OUT_ROOT="${CERT_BUNDLE_OUT:-$PWD/certification-bundles}"
[[ "$PAIR" =~ ^[a-z][a-z0-9]*$ ]] || fail "invalid CERT_BUNDLE_PAIR '$PAIR'"
case "${DUO_WORDPRESS_ORG_OFFLINE:-0}" in
  0) ;;
  1) export DUO_ARTIFACT_OFFLINE=1 ;;
  *) fail "DUO_WORDPRESS_ORG_OFFLINE must be 0 or 1" ;;
esac

# DUO-3427/DUO-3428: the two init legs -- the platform init contract and the
# public existing-site `duo init` golden path -- are back IN the certified set
# and run by DEFAULT. CERT_BUNDLE_INCLUDE_INIT_LEGS=0 is an emergency opt-OUT.
#
# #151 added both before either had ever passed end to end. They could not
# have: the golden path was evidence-coupled (its own proposals refused on the
# expired attestation every bundle-owing branch carries by construction, so it
# self-blocked on the evidence the bundle exists to mint), and behind that
# coupling sat eighteen independent defects. DUO-3421 fixed ten and descoped
# the legs rather than certify a set containing legs that had never passed.
# DUO-3427 fixed the remaining six -- a deletion authority that compared
# journaled ownership manifests by PHP key order and so refused every strict
# rollback, a proposal gate that swept bound capture-record temporaries by name
# where the authority resolves them by inode, a test-only ledger row that
# survived every killed init, a committed-config check that compared file bytes
# to a re-encoding the JSON journal cannot reproduce, a committed-code check
# that compared the repository payload's revision to the live source's, and a
# capture refusal that redacted the one instruction that was public -- and
# DUO-3428 restated `completed_within_fifteen_minutes` as the per-init claim it
# always named instead of a whole-suite stopwatch. The live suite is green end
# to end, so the rule that removed these legs is the rule that returns them: a
# certified set may only contain legs that have passed, and these now have.
#
# The opt-out survives because the condition that created it can recur: when a
# leg starts failing, an operator must be able to isolate it without editing
# this file mid-incident. Opting out is LOUD, produces no test fragment, no
# assertion and no exclusion for either leg, and says plainly that the result
# is not a complete reference bundle.
INCLUDE_INIT_LEGS=1
case "${CERT_BUNDLE_INCLUDE_INIT_LEGS:-}" in
  ''|1|true|yes) INCLUDE_INIT_LEGS=1 ;;
  0|false|no) INCLUDE_INIT_LEGS=0 ;;
  *) fail "CERT_BUNDLE_INCLUDE_INIT_LEGS must be 1/true/yes or 0/false/no (got '${CERT_BUNDLE_INCLUDE_INIT_LEGS}')" ;;
esac
command -v jq >/dev/null || fail "jq required"
command -v php >/dev/null || fail "php required"
command -v git >/dev/null || fail "git required"
# shellcheck source=../bin/fetch-artifact.sh
source bin/fetch-artifact.sh

# DUO-3355: the crash-safe host lock is a cohesive sourced boundary; this
# runner retains only orchestration and the acquire/release call sites.
# shellcheck source=../lib/certbundle_lock.sh
source lib/certbundle_lock.sh

certbundle_lock_acquire
validate_artifact_lock conformance/artifacts.lock.json \
  || fail "reference certification requires a closed typed artifact lock"

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

# Freeze the commit identity before the first child process or Docker/pair
# mutation. A clean checkout is only a point-in-time fact; without this SHA
# handoff, a stale/moved source mount could be exercised and the bundle could
# later label those results with a different HEAD. Respect any launcher-owned
# expectation, then give every existing gate the same exact commit.
SOURCE_SHA=$(git -C "$REPO_ROOT" rev-parse --verify 'HEAD^{commit}') \
  || fail "refusing certification: cannot resolve the exact source commit"
assert_expected_source_sha() { # assert_expected_source_sha <name> <value>
  local name="$1" value="$2"
  [ -z "$value" ] && return 0
  [[ "$value" =~ ^[0-9a-f]{40}$ ]] \
    || fail "refusing certification: $name must be one full lowercase Git commit SHA"
  [ "$value" = "$SOURCE_SHA" ] \
    || fail "refusing certification: $name names $value but the clean checkout is $SOURCE_SHA"
}
assert_expected_source_sha CERT_BUNDLE_EXPECTED_SOURCE_SHA "${CERT_BUNDLE_EXPECTED_SOURCE_SHA:-}"
assert_expected_source_sha DUO_EXPECTED_SOURCE_SHA "${DUO_EXPECTED_SOURCE_SHA:-}"
assert_expected_source_sha CONF_EXPECTED_SOURCE_SHA "${CONF_EXPECTED_SOURCE_SHA:-}"
export DUO_EXPECTED_SOURCE_SHA="$SOURCE_SHA"
export CONF_EXPECTED_SOURCE_SHA="$SOURCE_SHA"

assert_exact_source_unchanged() {
  local current
  current=$(git -C "$REPO_ROOT" rev-parse --verify 'HEAD^{commit}' 2>/dev/null) \
    || fail "refusing certification: source HEAD became unreadable during the run"
  [ "$current" = "$SOURCE_SHA" ] \
    || fail "refusing certification: source HEAD moved from $SOURCE_SHA to $current during the run"
  [ -z "$(git -C "$REPO_ROOT" status --porcelain=v1 --untracked-files=all)" ] \
    || fail "refusing certification: source checkout changed after the exact-SHA preflight"
}

WORK_ROOT=$(mktemp -d /tmp/duo-certbundle.XXXXXX)
cleanup_run() {
  case "$WORK_ROOT" in
    # DUO-3382: the removal is allowed to fail without taking the rest of the
    # trap down with it. Under `set -e` a bare `rm -rf` that hit a busy or
    # read-only path would abort the trap -- skipping the release below, and
    # exiting a GREEN bundle 1 over a cleanup failure that changed nothing.
    /tmp/duo-certbundle.*)
      rm -rf -- "$WORK_ROOT" \
        || printf 'WARNING: could not remove the work root %s; remove it by hand\n' "$WORK_ROOT" >&2
      ;;
    *) printf 'refusing unsafe work cleanup path: %s\n' "$WORK_ROOT" >&2 ;;
  esac
  # The lock is released LAST, and from the same trap that clears the work
  # root -- the next invocation must never win the lock while this run's work
  # root is still on disk.
  certbundle_lock_release
}
trap cleanup_run EXIT   # replaces the release-only trap armed by certbundle_lock_acquire

ENV_FILE="$WORK_ROOT/environment.json"
ARTIFACT_USAGE_LOG="$WORK_ROOT/artifact-cache-usage.ndjson"
: > "$ARTIFACT_USAGE_LOG"
export DUO_ARTIFACT_USAGE_LOG="$ARTIFACT_USAGE_LOG"
MULTISITE_LOG="$WORK_ROOT/multisite-refusal.log"
MATRIX_LOG="$WORK_ROOT/exact-artifact-version-matrix.log"
INIT_CONTRACT_LOG="$WORK_ROOT/init-contract.log"
INIT_GOLDEN_LOG="$WORK_ROOT/duo-init-golden-path.log"
CONFORMANCE_MANIFESTS=(
  core fse acf contact-form-7 elementor ninja-forms
  polylang woocommerce yoast paid-memberships-pro
)
TEST_FRAGMENTS=()

write_result() { # write_result <id> <rc> <reason> <assertions-json> <path>
  local id="$1" rc="$2" reason="$3" assertions="$4" path="$5" verdict=fail
  [ "$rc" -eq 0 ] && verdict=pass
  jq -n \
    --arg test "$id" --arg verdict "$verdict" --arg reason "$reason" \
    --argjson exit_code "$rc" --argjson assertions "$assertions" \
    '{schema_version:1,test:$test,verdict:$verdict,exit_code:$exit_code,reason:$reason,assertions:$assertions}' > "$path"
}

write_scoped_result() { # write_scoped_result <id> <rc> <reason> <assertions-json> <scope> <exclusions-json> <path>
  local id="$1" rc="$2" reason="$3" assertions="$4" scope="$5" exclusions="$6" path="$7" tmp
  write_result "$id" "$rc" "$reason" "$assertions" "$path"
  tmp="$path.tmp"
  jq --arg scope "$scope" --argjson exclusions "$exclusions" \
    '. + {scope:$scope,exclusions:$exclusions}' "$path" > "$tmp"
  mv "$tmp" "$path"
}

write_fragment() { # write_fragment <id> <manifest> <result> <diff> <fragment>
  jq -n --arg id "$1" --arg manifest "$2" --arg result "$3" --arg diff "$4" \
    '{id:$id,manifest:$manifest,result:$result,diff:$diff}' > "$5"
}

write_skipped() { # write_skipped <id> <manifest> <log> <result> <diff> <fragment>
  local id="$1" manifest="$2" log="$3" result="$4" diff="$5" fragment="$6"
  printf 'SKIPPED: blocked by an earlier failed reference-certification leg\n' > "$log"
  write_result "$id" 99 blocked_by_prior_failure '[]' "$result"
  jq -n --arg manifest "$manifest" \
    '{status:"unknown",manifest:$manifest,reason:"blocked_by_prior_failure"}' > "$diff"
  write_fragment "$id" "$manifest" "$result" "$diff" "$fragment"
}

append_fragment() { # append_fragment <fragment> <log>
  local fragment="$1" log="$2"
  TEST_FRAGMENTS+=("$(jq -c --arg log "$log" '. + {log:$log} | del(.manifest)' "$fragment")")
}

destroy_own_pair() {
  local rc
  set +e
  bash bin/pair.sh destroy "$PAIR"
  rc=$?
  set -e
  return "$rc"
}

say "source/static preflight"
php -l bin/certification-bundle.php >/dev/null
php -l tests/regress_init_contract.php >/dev/null
bash -n conformance/run.sh tests/regress_multisite_refusal.sh tests/certify_version_matrix.sh tests/regress_duo_init.sh
bash bin/pair.sh list
pass "bundle builder and all invoked harnesses parse; pair load inspected"

overall=0
leg=0
total_legs=$((${#CONFORMANCE_MANIFESTS[@]} + 2 + INCLUDE_INIT_LEGS * 2))
for manifest in "${CONFORMANCE_MANIFESTS[@]}"; do
  leg=$((leg + 1))
  id="conformance-$manifest"
  log="$WORK_ROOT/$id.log"
  result="$WORK_ROOT/$id.result.json"
  diff="$WORK_ROOT/$id.diff.json"
  fragment="$WORK_ROOT/$id.fragment.json"

  if [ "$overall" -eq 0 ]; then
    say "reference leg $leg/$total_legs: $manifest conformance through the real deploy/apply path"
    set +e
    CONF_PAIR="$PAIR" CONF1_PORT="$PORT1" CONF2_PORT="$PORT2" \
      CONFORMANCE_EVIDENCE_DIR="$WORK_ROOT" \
      bash conformance/run.sh "$manifest" > "$log" 2>&1
    rc=$?
    set -e

    reason=passed
    if [ "$rc" -eq 0 ] && ! grep -qF "✔ CONFORMANCE PASSED ($manifest)" "$log"; then
      rc=70
      reason=invalid_checker_output
    fi
    if [ "$rc" -eq 0 ] && ! jq -e \
      --arg id "$id" --arg manifest "$manifest" --arg result "$result" --arg diff "$diff" \
      '.id == $id and .manifest == $manifest and .result == $result and .diff == $diff' \
      "$fragment" >/dev/null 2>&1; then
      rc=70
      reason=invalid_checker_output
    fi
    if [ "$rc" -eq 0 ] && ! jq -e --arg id "$id" \
      '.test == $id and .verdict == "pass" and .exit_code == 0' "$result" >/dev/null 2>&1; then
      rc=70
      reason=invalid_checker_output
    fi
    if [ "$rc" -eq 0 ] && ! jq -e --arg manifest "$manifest" \
      '.status == "clean" and .manifest == $manifest' "$diff" >/dev/null 2>&1; then
      rc=70
      reason=invalid_checker_output
    fi

    # Capture the exact environment while core's successful target still
    # exists. All other conformance pairs can be destroyed immediately.
    if [ "$manifest" = core ] && [ "$rc" -eq 0 ]; then
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
        rc=71
        reason=environment_collection_failed
      fi
    fi

    if [ "$rc" -ne 0 ]; then
      overall=1
      [ "$reason" = passed ] && reason=command_failed
      write_result "$id" "$rc" "$reason" '[]' "$result"
      jq -n --arg manifest "$manifest" '{status:"unknown",manifest:$manifest}' > "$diff"
      write_fragment "$id" "$manifest" "$result" "$diff" "$fragment"
      if [ "$manifest" = core ] && [ ! -f "$ENV_FILE" ]; then
        jq -n --arg host_php "$(php -r 'echo PHP_VERSION;')" \
          '{collection:"failed",host_php:$host_php}' > "$ENV_FILE"
      fi
    fi

    tail -30 "$log"
    if ! destroy_own_pair; then
      overall=1
      printf 'FAIL: own pair %s could not be destroyed after %s\n' "$PAIR" "$manifest" >&2
    elif [ "$rc" -eq 0 ]; then
      pass "$manifest conformance passed; its named fragment was imported and pair destroyed"
    fi
  else
    write_skipped "$id" "$manifest" "$log" "$result" "$diff" "$fragment"
  fi
  append_fragment "$fragment" "$log"
done

[ -f "$ENV_FILE" ] || jq -n --arg host_php "$(php -r 'echo PHP_VERSION;')" \
  '{collection:"failed",host_php:$host_php}' > "$ENV_FILE"

leg=$((leg + 1))
if [ "$overall" -eq 0 ]; then
  say "reference leg $leg/$total_legs: real WordPress multisite must refuse with zero mutation"
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
    '{status:$status,checked:["site.duo.json","state","capture-staging","capture-backup","wordpress-option-canary"]}' \
    > "$WORK_ROOT/multisite-refusal.diff.json"
  destroy_own_pair || overall=1
else
  write_skipped multisite-refusal multisite "$MULTISITE_LOG" \
    "$WORK_ROOT/multisite-refusal.result.json" "$WORK_ROOT/multisite-refusal.diff.json" \
    "$WORK_ROOT/multisite-refusal.fragment.json"
fi
write_fragment multisite-refusal multisite "$WORK_ROOT/multisite-refusal.result.json" \
  "$WORK_ROOT/multisite-refusal.diff.json" "$WORK_ROOT/multisite-refusal.fragment.json"
append_fragment "$WORK_ROOT/multisite-refusal.fragment.json" "$MULTISITE_LOG"

leg=$((leg + 1))
if [ "$overall" -eq 0 ]; then
  say "reference leg $leg/$total_legs: exact-artifact version matrix (including typed tables and refusal fixtures)"
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
    '{status:$status,diffs:["all-in-range-boundary-recaptures"],negative_controls:"all-below-range-releases-refused"}' \
    > "$WORK_ROOT/exact-artifact-version-matrix.diff.json"
  destroy_own_pair || overall=1
else
  write_skipped exact-artifact-version-matrix version-matrix "$MATRIX_LOG" \
    "$WORK_ROOT/exact-artifact-version-matrix.result.json" "$WORK_ROOT/exact-artifact-version-matrix.diff.json" \
    "$WORK_ROOT/exact-artifact-version-matrix.fragment.json"
fi
write_fragment exact-artifact-version-matrix version-matrix \
  "$WORK_ROOT/exact-artifact-version-matrix.result.json" "$WORK_ROOT/exact-artifact-version-matrix.diff.json" \
  "$WORK_ROOT/exact-artifact-version-matrix.fragment.json"
append_fragment "$WORK_ROOT/exact-artifact-version-matrix.fragment.json" "$MATRIX_LOG"

if [ "$INCLUDE_INIT_LEGS" = 1 ]; then
  # The default (see CERT_BUNDLE_INCLUDE_INIT_LEGS at the top of this file).
  leg=$((leg + 1))
  init_contract_assertions='["authenticated_target_proposal","digest_bound_confirmation","separate_code_and_state_declarations","redacted_risk_rendering","fail_closed_transport","generic_authored_only_scope","initial_baseline_lifecycle"]'
  init_contract_exclusions='["live_wordpress_runtime","plugin_semantic_conformance","agent_installation_or_adoption"]'
  if [ "$overall" -eq 0 ]; then
    say "reference leg $leg/$total_legs: existing-site init platform contract"
    set +e
    php tests/regress_init_contract.php > "$INIT_CONTRACT_LOG" 2>&1
    init_contract_rc=$?
    set -e
    tail -30 "$INIT_CONTRACT_LOG"
    init_contract_reason=passed
    if [ "$init_contract_rc" -eq 0 ] && ! grep -qF 'REGRESS_INIT_CONTRACT PASSED' "$INIT_CONTRACT_LOG"; then
      init_contract_rc=70
      init_contract_reason=invalid_checker_output
    fi
    if [ "$init_contract_rc" -ne 0 ]; then
      overall=1
      [ "$init_contract_reason" = passed ] && init_contract_reason=command_failed
      init_contract_result_assertions='[]'
      init_contract_status=unknown
    else
      init_contract_result_assertions="$init_contract_assertions"
      init_contract_status=clean
    fi
    write_scoped_result init-contract "$init_contract_rc" "$init_contract_reason" \
      "$init_contract_result_assertions" platform-init-contract "$init_contract_exclusions" \
      "$WORK_ROOT/init-contract.result.json"
    jq -n --arg status "$init_contract_status" --arg scope platform-init-contract \
      --argjson assertions "$init_contract_result_assertions" --argjson exclusions "$init_contract_exclusions" \
      '{status:$status,scope:$scope,assertions:$assertions,exclusions:$exclusions}' \
      > "$WORK_ROOT/init-contract.diff.json"
  else
    printf 'SKIPPED: blocked by an earlier failed reference-certification leg\n' > "$INIT_CONTRACT_LOG"
    write_scoped_result init-contract 99 blocked_by_prior_failure '[]' \
      platform-init-contract "$init_contract_exclusions" "$WORK_ROOT/init-contract.result.json"
    jq -n --arg scope platform-init-contract --argjson exclusions "$init_contract_exclusions" \
      '{status:"unknown",scope:$scope,reason:"blocked_by_prior_failure",assertions:[],exclusions:$exclusions}' \
      > "$WORK_ROOT/init-contract.diff.json"
  fi
  write_fragment init-contract platform-init-contract \
    "$WORK_ROOT/init-contract.result.json" "$WORK_ROOT/init-contract.diff.json" \
    "$WORK_ROOT/init-contract.fragment.json"
  append_fragment "$WORK_ROOT/init-contract.fragment.json" "$INIT_CONTRACT_LOG"

  leg=$((leg + 1))
  init_golden_assertions='["no_write_blockers_and_cancel","public_digest_confirmation","separate_code_and_state_baselines","selected_authored_product_and_taxonomy_scope","runtime_order_exclusion","clean_public_status","completed_within_fifteen_minutes"]'
  init_golden_exclusions='["woocommerce_semantic_conformance","full_site_coverage","code_and_database_rollback","agent_installation_or_adoption"]'
  if [ "$overall" -eq 0 ]; then
    say "reference leg $leg/$total_legs: public existing-site duo init golden path"
    set +e
    DUO_INIT_PAIR="$PAIR" DUO_INIT_PORT1="$PORT1" DUO_INIT_PORT2="$PORT2" \
      bash tests/regress_duo_init.sh > "$INIT_GOLDEN_LOG" 2>&1
    init_golden_rc=$?
    set -e
    tail -40 "$INIT_GOLDEN_LOG"
    init_golden_reason=passed
    if [ "$init_golden_rc" -eq 0 ] && ! grep -qF '✔ REGRESS_DUO_INIT PASSED' "$INIT_GOLDEN_LOG"; then
      init_golden_rc=70
      init_golden_reason=invalid_checker_output
    fi
    if ! destroy_own_pair; then
      init_golden_rc=72
      init_golden_reason=cleanup_failed
    fi
    if [ "$init_golden_rc" -ne 0 ]; then
      overall=1
      [ "$init_golden_reason" = passed ] && init_golden_reason=command_failed
      init_golden_result_assertions='[]'
      init_golden_status=unknown
    else
      init_golden_result_assertions="$init_golden_assertions"
      init_golden_status=clean
    fi
    write_scoped_result duo-init-golden-path "$init_golden_rc" "$init_golden_reason" \
      "$init_golden_result_assertions" existing-site-init-workflow "$init_golden_exclusions" \
      "$WORK_ROOT/duo-init-golden-path.result.json"
    jq -n --arg status "$init_golden_status" --arg scope existing-site-init-workflow \
      --argjson assertions "$init_golden_result_assertions" --argjson exclusions "$init_golden_exclusions" \
      '{status:$status,scope:$scope,assertions:$assertions,exclusions:$exclusions,
        fixture:{plugin:"woocommerce",version:"11.0.0",version_checked_only:true,
          semantic_conformance:"not_certified_by_this_test"}}' \
      > "$WORK_ROOT/duo-init-golden-path.diff.json"
  else
    printf 'SKIPPED: blocked by an earlier failed reference-certification leg\n' > "$INIT_GOLDEN_LOG"
    write_scoped_result duo-init-golden-path 99 blocked_by_prior_failure '[]' \
      existing-site-init-workflow "$init_golden_exclusions" \
      "$WORK_ROOT/duo-init-golden-path.result.json"
    jq -n --arg scope existing-site-init-workflow --argjson exclusions "$init_golden_exclusions" \
      '{status:"unknown",scope:$scope,reason:"blocked_by_prior_failure",assertions:[],exclusions:$exclusions,
        fixture:{plugin:"woocommerce",version:"11.0.0",version_checked_only:true,
          semantic_conformance:"not_certified_by_this_test"}}' \
      > "$WORK_ROOT/duo-init-golden-path.diff.json"
  fi
  write_fragment duo-init-golden-path existing-site-init-workflow \
    "$WORK_ROOT/duo-init-golden-path.result.json" "$WORK_ROOT/duo-init-golden-path.diff.json" \
    "$WORK_ROOT/duo-init-golden-path.fragment.json"
  append_fragment "$WORK_ROOT/duo-init-golden-path.fragment.json" "$INIT_GOLDEN_LOG"
else
  say "reference legs: init platform contract + public duo init golden path are OPTED OUT"
  printf '\033[1;33mSKIPPED (not certified, not claimed) by CERT_BUNDLE_INCLUDE_INIT_LEGS=%s.
Both init legs are IN the certified set by default -- DUO-3427 repaired the init
interrupted-recovery subsystem and DUO-3428 restated the per-init clock, and the
live suite is green end to end. This run opted out, so the bundle below carries
no init test fragment, assertion, or exclusion and is NOT a complete reference
bundle. Unset CERT_BUNDLE_INCLUDE_INIT_LEGS to certify them.\033[0m\n' \
    "${CERT_BUNDLE_INCLUDE_INIT_LEGS}"
fi

assert_exact_source_unchanged

# DUO-3431: the immutable environment record names the exact cache entries
# actually requested by every successful bundle leg, not merely every pin
# that happens to exist in the lock. Each helper observation is checked back
# against the bound typed lock before it is reduced to deterministic evidence.
[ -s "$ARTIFACT_USAGE_LOG" ] \
  || fail "reference certification recorded no pinned artifact-cache usage"
jq -s -e --slurpfile lock conformance/artifacts.lock.json '
  length > 0 and all(.[];
    type == "object" and
    keys == ["kind","path","sha256","slug","source","version"] and
    (.kind == "plugin" or .kind == "theme") and
    (.source == "cache-hit" or .source == "network-fetch") and
    (.sha256 | test("^[0-9a-f]{64}$")) and
    .path == ("/artifacts-cache/" + .kind + "-" + .slug + "-" + .version + "-" + .sha256 + ".zip") and
    $lock[0][(.kind + "s")][.slug][.version].sha256 == .sha256)
' "$ARTIFACT_USAGE_LOG" >/dev/null \
  || fail "artifact-cache usage log is malformed or disagrees with the typed artifact lock"
if [ "${DUO_WORDPRESS_ORG_OFFLINE:-0}" = 1 ]; then
  jq -s -e 'all(.[]; .source == "cache-hit")' "$ARTIFACT_USAGE_LOG" >/dev/null \
    || fail "WordPress.org-offline proof observed a network artifact fetch"
fi
ARTIFACT_CACHE_ENTRIES=$(jq -s '
  sort_by(.kind,.slug,.version,.sha256,.path,.source)
  | group_by([.kind,.slug,.version,.sha256,.path])
  | map({
      kind:.[0].kind,slug:.[0].slug,version:.[0].version,sha256:.[0].sha256,
      path:.[0].path,sources:([.[].source] | unique),uses:length
    })
' "$ARTIFACT_USAGE_LOG")
ENV_TMP="$ENV_FILE.artifact-cache.tmp"
jq --argjson entries "$ARTIFACT_CACHE_ENTRIES" \
  --argjson wordpress_org_blocked "$([ "${DUO_WORDPRESS_ORG_OFFLINE:-0}" = 1 ] && printf true || printf false)" '
  . + {artifact_cache:{
    format:"duo-artifact-cache-usage/v1",
    authority:false,
    wordpress_org_blocked:$wordpress_org_blocked,
    entries:$entries
  }}
' "$ENV_FILE" > "$ENV_TMP"
mv "$ENV_TMP" "$ENV_FILE"

say "materialize the content-addressed machine-readable bundle"
# DUO-3361: the enumeration below MUST stay `LC_ALL=C sort -u`.
#
# bound_inputs is a JSON ARRAY, and the bundle's canonical encoding
# (certification-bundle.php::cert_canonical) sorts object KEYS but preserves
# list ORDER by construction -- so this sort's output order is load-bearing
# input to bundle_digest, and from there to the checked-in attestation in
# manifests/capabilities/evidence.json. Collation is a property of the
# OPERATOR'S LOCALE, not of the tree: `sort` under en_US.UTF-8 orders
# DESIGN.md and Makefile among the lowercase paths and puts cli/duo before
# cli/README.md, where the C locale's byte order does neither. Two sweeps of a
# byte-identical tree in two locales therefore produced two different digests
# and an evidence refresh whose diff was almost entirely reordering (PR #141:
# ~5 meaningful lines inflated to 89). Content and hashes were always right --
# only the order was ambient. Pinning the locale makes the enumeration a
# function of the tree alone.
BOUND_INPUTS=$({ git -C "$REPO_ROOT" ls-files \
  agent cli manifests sandbox/bin sandbox/conformance \
  sandbox/lib/certbundle_lock.sh \
  sandbox/tests/certify_reference_bundle.sh \
  sandbox/tests/certify_version_matrix.sh \
  sandbox/tests/regress_multisite_refusal.sh \
  sandbox/tests/regress_init_contract.php \
  sandbox/tests/regress_duo_init.sh \
  sandbox/pair.yml sandbox/pair.artifacts.yml sandbox/pair.wordpress-offline.yml \
  sandbox/db.yml sandbox/init-cli.Dockerfile \
  scripts/capability-registry.php docs/compatibility-baseline.json \
  DESIGN.md spec/repo-format.md Makefile .github/workflows/conformance.yml; \
  printf '%s\n' manifests/dispositions.json; } \
  | grep -v '^manifests/capabilities/' | LC_ALL=C sort -u | jq -R . | jq -s .)
ARTIFACTS=$(jq '[.plugins | to_entries[] as $slug | $slug.value | to_entries[]
  | select(.value.role == "certified-boundary" or .value.role == "refusal-fixture")
  | {name:$slug.key,version:.key,url:.value.url,sha256:.value.sha256,role:.value.role}]' \
  conformance/artifacts.lock.json)
TESTS=$(printf '%s\n' "${TEST_FRAGMENTS[@]}" | jq -s .)
CREATED_AT=$(date -u '+%Y-%m-%dT%H:%M:%SZ')
GIT_REVISION="$SOURCE_SHA"
# DUO-3406: record any force/override env the sweeps honor (currently only
# DUO_PAIR_BUDGET_OVERRIDE) as a force hatch, so a bundle built under an override
# never affirmatively claims force_hatches:[] — silently-wrong evidence, worse
# than recorded-as-forced. cap_import_bundle refuses a reference bundle with a
# non-empty list, so a forced build fails loudly at import instead of forging a
# clean certification. To add a sibling override, extend the name list below.
FORCE_HATCHES=$(jq -n '[ "DUO_PAIR_BUDGET_OVERRIDE" ] | map(select($ENV[.] // "" | . != "" and . != "0"))')
jq -n \
  --arg repo_root "$REPO_ROOT" --arg created_at "$CREATED_AT" --arg git_revision "$GIT_REVISION" \
  --arg environment "$ENV_FILE" --argjson bound_inputs "$BOUND_INPUTS" --argjson artifacts "$ARTIFACTS" \
  --arg ratification "$REPO_ROOT/manifests/dispositions.json" --argjson tests "$TESTS" \
  --argjson force_hatches "$FORCE_HATCHES" \
  '{
    repo_root:$repo_root,created_at:$created_at,git_revision:$git_revision,
    harness:{name:"duo-reference-certification",version:4},force_hatches:$force_hatches,
    environment:$environment,ratification:$ratification,bound_inputs:$bound_inputs,artifacts:$artifacts,
    tests:$tests
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

assert_exact_source_unchanged

if [ "$overall" -ne 0 ] || [ "$build_rc" -ne 0 ] || [ "$verify_rc" -ne 0 ]; then
  fail "reference certification failed; immutable evidence remains at $BUNDLE"
fi

LIVE_CONTAINERS=$(docker ps --format '{{.Names}}')
if grep -qE "^duo-${PAIR}-" <<<"$LIVE_CONTAINERS"; then
  fail "own pair '$PAIR' is still running after a green reference certification"
fi

pass "reference bundle is valid, content-addressed, input-bound, and immediately re-verified"
printf '\n\033[1;32m✔ CERTIFY_REFERENCE_BUNDLE PASSED (%s)\033[0m\n' "$(basename "$BUNDLE")"
