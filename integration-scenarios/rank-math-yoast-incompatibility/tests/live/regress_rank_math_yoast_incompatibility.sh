#!/usr/bin/env bash
# Candidate-bound Rank Math 1.0.277.2 / Yoast 28.3 incompatibility evidence.
# Both plugin and manifest orders reach the same policy-load refusal before a
# capture publication or host promotion can acquire mutation authority.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../../../.." && pwd -P)"
cd "$ROOT/sandbox"

say() { printf '\n== %s ==\n' "$*"; }
pass() { printf 'ok: %s\n' "$*"; }
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }
. conformance/asserts.sh

PAIR="${RANK_MATH_YOAST_PAIR:-rmyoast}"
PORT1="${RANK_MATH_YOAST_PORT1:-9050}"
PORT2="${RANK_MATH_YOAST_PORT2:-9051}"
EXPECTED_SHA="${RANK_MATH_YOAST_EXPECTED_SOURCE_SHA:-${WPRISM_EXPECTED_SOURCE_SHA:-}}"
HEAD="$(git -C "$ROOT" rev-parse HEAD)"
[[ "$PAIR" =~ ^[a-z][a-z0-9]*$ ]] || fail "invalid Rank Math/Yoast pair '$PAIR'"
[[ "$PORT1" =~ ^[0-9]{4,5}$ && "$PORT2" =~ ^[0-9]{4,5}$ && "$PORT1" != "$PORT2" ]] \
  || fail 'invalid Rank Math/Yoast ports'
[[ "$EXPECTED_SHA" =~ ^[0-9a-f]{40}$ ]] || fail 'Rank Math/Yoast evidence requires a candidate SHA'
[ "$EXPECTED_SHA" = "$HEAD" ] || fail "candidate SHA $EXPECTED_SHA does not equal checkout HEAD $HEAD"
[ -z "$(git -C "$ROOT" status --porcelain=v1 --untracked-files=all)" ] \
  || fail 'Rank Math/Yoast evidence requires a clean candidate checkout'
command -v jq >/dev/null || fail 'jq required'

export WPRISM_SOURCE_ROOT="$ROOT" WPRISM_EXPECTED_SOURCE_SHA="$EXPECTED_SHA" WPRISM_PAIR="$PAIR"
. lib/pair_identity.sh
pair_identity_export_source_mounts \
  || fail 'Rank Math/Yoast evidence could not pin candidate mounts in the caller environment'
COMPOSE=(docker compose -p "wprism-$PAIR" -f pair.yml -f pair.artifacts.yml)
PAIR_COMPOSE=("${COMPOSE[@]}")
. lib/host_orchestrator.sh
WPRISM_HOST_REGISTRY=$(mktemp "${TMPDIR:-/tmp}/wprism-rmyoast-host.${PAIR}.XXXXXX")
wprism_host_registry_create "$WPRISM_HOST_REGISTRY" "$(pwd)/pair.yml" "$PAIR"
host_deploy() { # <side>
  local side="$1"
  wprism_host_call "$ROOT/cli/wprism" "$WPRISM_HOST_REGISTRY" "wprism-$PAIR" \
    "${PAIR}${side}" deploy
}
WP_CLI_MEMORY_LIMIT=512M
wp_side() { # <side> <wp args...>
  local side="$1"
  shift
  "${COMPOSE[@]}" run --rm -T --entrypoint php "cli$side" \
    -d "memory_limit=$WP_CLI_MEMORY_LIMIT" /usr/local/bin/wp "$@"
}
wp1() { wp_side 1 "$@"; }
wp2() { wp_side 2 "$@"; }
private_evidence() { # <side> <snapshot|verify> <args...>
  local side="$1"
  shift
  "${COMPOSE[@]}" run --rm -T --entrypoint php "cli$side" \
    /siterepo/.tmp-rank-math-yoast-private-refusal.php "$@"
}
R1="siterepo/${PAIR}1"
R2="siterepo/${PAIR}2"
SCENARIO="$ROOT/integration-scenarios/rank-math-yoast-incompatibility/scenario.json"
. bin/fetch-artifact.sh
WPRISM_ARTIFACT_PARTICIPANTS="$(artifact_library_scenario_participants "$SCENARIO")" \
  || fail 'Rank Math/Yoast participant record is malformed'
export WPRISM_ARTIFACT_PARTICIPANTS
validate_artifact_library || fail 'Rank Math/Yoast artifact library validation failed'
artifact_library_jq -e '.plugins["seo-by-rank-math"]["1.0.277.2"] and .plugins["wordpress-seo"]["28.3"]' >/dev/null \
  || fail 'exact Rank Math 1.0.277.2 and Yoast 28.3 artifacts are absent'

EXPECTED_REFUSAL="wprism: manifest 'rank-math' for plugin 'seo-by-rank-math/rank-math.php' declares plugin 'wordpress-seo/wp-seo.php' incompatible, and pinned manifest(s) {'yoast'} claim that plugin — incompatible plugin adapters cannot share one policy; pin only one"
GREEN=0
cleanup() {
  rm -f -- "$WPRISM_HOST_REGISTRY"
  if [ "$GREEN" = 1 ]; then
    bash bin/pair.sh destroy "$PAIR" >/dev/null 2>&1 || true
  else
    printf '(pair %s left up for inspection after failure)\n' "$PAIR" >&2
  fi
}
trap cleanup EXIT

install_exact() { # <side> <slug> <version>
  local side="$1" slug="$2" version="$3" artifact actual
  artifact=$(fetch_artifact "$slug" "$version" "cli$side")
  "wp$side" plugin install "$artifact" --activate --force >/dev/null
  actual=$("wp$side" plugin get "$slug" --field=version)
  [ "$actual" = "$version" ] || fail "side $side: $slug is $actual, expected exact $version"
}

repo_witness() { # <repo>
  local repo="$1" file
  while IFS= read -r file; do
    printf '%s  %s\n' "$(shasum -a 256 "$file" | awk '{print $1}')" "${file#"$repo"/}"
  done < <(find "$repo" -type f ! -path "$repo/.git/*" \
    ! -path "$repo/.wprism/refusals/*" -print | LC_ALL=C sort)
}

target_witness() { # <side>
  local side="$1"
  "wp$side" eval '
global $wpdb;
$table = $wpdb->prefix . "wprism_kv";
$present = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
$keys = [];
if (is_string($present) && hash_equals($table, $present)) {
    $keys = $wpdb->get_col("SELECT k FROM `{$table}` WHERE k IN (\"promotion_lock\",\"promotion_session\",\"apply_in_progress\",\"schema_settlement_in_progress\") ORDER BY k");
}
echo wp_json_encode([
    "active_plugins" => array_values((array) get_option("active_plugins", [])),
    "sentinel" => get_option("wprism_rank_math_yoast_sentinel"),
    "mutation_keys" => $keys,
]);
' | tail -1
}

write_repo() { # <repo> <manifest-order-json> <sentinel>
  local repo="$1" manifests="$2" sentinel="$3"
  jq -n --argjson manifests "$manifests" \
    '{manifests:$manifests,policy:{options:{},post_meta:{},post_types:["post","page","attachment"],taxonomies:["category","post_tag"]},spec_version:3}' \
    > "$repo/site.wprism.json"
  cp site-repo.gitignore.template "$repo/.gitignore"
  cp "$ROOT/integration-scenarios/rank-math-yoast-incompatibility/fixtures/private-refusal-evidence.php" \
    "$repo/.tmp-rank-math-yoast-private-refusal.php"
  printf '%s\n' "$sentinel" > "$repo/refusal-sentinel.txt"
  git init -q -b main "$repo"
  git -C "$repo" -c user.name=wprism-rmyoast -c user.email=rmyoast@example.test add -A
  git -C "$repo" -c user.name=wprism-rmyoast -c user.email=rmyoast@example.test \
    commit -qm 'policy: exact incompatible SEO adapter order'
}

assert_refusal() { # <side> <repo> <capture|deploy> <expected-runtime-json>
  local side="$1" repo="$2" operation="$3" expected_runtime="$4"
  local before_repo before_head output rc after_runtime private_baseline='' private_receipt=''
  before_repo=$(repo_witness "$repo")
  before_head=$(git -C "$repo" rev-parse HEAD)
  rc=0
  if [ "$operation" = capture ]; then
    output=$("wp$side" wprism capture --repo=/siterepo 2>&1) || rc=$?
  elif [ "$operation" = deploy ]; then
    private_baseline=$(private_evidence "$side" snapshot /siterepo/.wprism/refusals) \
      || fail 'deploy could not snapshot private compile evidence as the target CLI identity'
    require_observed_nonempty 'Rank Math/Yoast private compile refusal baseline' "$private_baseline"
    output=$(host_deploy "$side" 2>&1) || rc=$?
  else
    fail "unsupported refusal operation '$operation'"
  fi
  [ "$rc" -ne 0 ] || fail "$operation unexpectedly admitted incompatible SEO adapters: $output"
  require_wprism_answered "Rank Math/Yoast $operation refusal" human "$output"
  if [ "$operation" = capture ]; then
    grep -Fq "$EXPECTED_REFUSAL" <<<"$output" \
      && ! grep -Fq '"details_redacted":true' <<<"$output" \
      || fail "capture lost the public incompatibility refusal: $output"
  else
    grep -Fq '"format":"wprism-command-refusal/v1"' <<<"$output" \
      && grep -Fq '"command":"compile"' <<<"$output" \
      && grep -Fq '"reason_code":"compile_failed"' <<<"$output" \
      && grep -Fq '"details_redacted":true' <<<"$output" \
      && grep -Fq "the target's compile refusal was redacted" <<<"$output" \
      && grep -Fq '.wprism/refusals/' <<<"$output" \
      && ! grep -Fq "$EXPECTED_REFUSAL" <<<"$output" \
      || fail "deploy lost the public/private incompatibility boundary: $output"
    private_receipt=$(private_evidence "$side" verify \
      /siterepo/.wprism/refusals "$private_baseline") \
      || fail 'deploy could not verify private compile evidence as the target CLI identity'
    require_observed_nonempty 'Rank Math/Yoast private compile refusal receipt' "$private_receipt"
    [ "$private_receipt" = \
      '{"command":"compile","format":"wprism-rank-math-yoast-private-refusal-check/v1","new_records":1,"root_message_sha256":"e0e3db584904aa388ce5547e87b149223ed113f23751f0bc94b5f98e8acbb421","verified":true}' ] \
      || fail "deploy returned a malformed private incompatibility receipt: $private_receipt"
  fi
  [ "$(git -C "$repo" rev-parse HEAD)" = "$before_head" ] \
    || fail "$operation moved the repository HEAD before refusing"
  [ "$(repo_witness "$repo")" = "$before_repo" ] \
    || fail "$operation changed repository bytes before refusing"
  [ -z "$(git -C "$repo" status --porcelain=v1 --untracked-files=all)" ] \
    || fail "$operation left tracked or untracked repository state"
  [ ! -e "$repo/.wprism/control/provider-settlement-intent.json" ] \
    || fail "$operation published provider settlement debt before refusing"
  after_runtime=$(target_witness "$side")
  jq -en --argjson before "$expected_runtime" --argjson after "$after_runtime" \
    '$before == $after and ($after.mutation_keys | length) == 0' >/dev/null \
    || fail "$operation changed plugin/runtime state or retained mutation authority: $after_runtime"
  if [ "$operation" = capture ]; then
    pass 'capture refused before repository publication'
  else
    ! grep -Fq 'deploy phase: promotion-begin' <<<"$output" \
      || fail 'host deploy crossed promotion-begin before incompatibility refusal'
    ! grep -Fq 'deploy phase: provider-settlement-begin' <<<"$output" \
      || fail 'host deploy crossed provider settlement before incompatibility refusal'
    pass 'deploy refused before promotion-begin or provider settlement'
  fi
}

say "fresh exact Rank Math/Yoast refusal pair at candidate $HEAD"
bash bin/pair.sh reset "$PAIR"
bash bin/pair.sh up "$PAIR" "$PORT1" "$PORT2" --artifacts --headless
bash bin/pair.sh repo-host "$PAIR" both >/dev/null

# Side 1 proves core,rank-math,yoast; side 2 proves core,yoast,rank-math.
install_exact 1 seo-by-rank-math 1.0.277.2
install_exact 1 wordpress-seo 28.3
install_exact 2 wordpress-seo 28.3
install_exact 2 seo-by-rank-math 1.0.277.2
# WordPress sorts active_plugins by basename during activation, so installation
# order is not load-order evidence. Persist both exact test-owned sequences only
# after both artifacts exist; the next requests below boot through those orders.
wp1 option update active_plugins \
  '["seo-by-rank-math/rank-math.php","wordpress-seo/wp-seo.php"]' --format=json >/dev/null
wp2 option update active_plugins \
  '["wordpress-seo/wp-seo.php","seo-by-rank-math/rank-math.php"]' --format=json >/dev/null
wp1 option update wprism_rank_math_yoast_sentinel rank-first >/dev/null
wp2 option update wprism_rank_math_yoast_sentinel yoast-first >/dev/null
write_repo "$R1" '["core","rank-math","yoast"]' rank-first
write_repo "$R2" '["core","yoast","rank-math"]' yoast-first
wprism_host_install_recovery_runtime "$ROOT" "$R1" \
  || fail 'Rank-first refusal fixture could not install its recovery runtime'
wprism_host_install_recovery_runtime "$ROOT" "$R2" \
  || fail 'Yoast-first refusal fixture could not install its recovery runtime'

ORDER1=$(wp1 option get active_plugins --format=json | jq -c .)
ORDER2=$(wp2 option get active_plugins --format=json | jq -c .)
[ "$ORDER1" = '["seo-by-rank-math/rank-math.php","wordpress-seo/wp-seo.php"]' ] \
  || fail "Rank-first active plugin order is not exact: $ORDER1"
[ "$ORDER2" = '["wordpress-seo/wp-seo.php","seo-by-rank-math/rank-math.php"]' ] \
  || fail "Yoast-first active plugin order is not exact: $ORDER2"
BASE1=$(target_witness 1)
BASE2=$(target_witness 2)
jq -e '.sentinel == "rank-first" and (.mutation_keys | length) == 0' <<<"$BASE1" >/dev/null \
  || fail "rank-first target premise is not exact: $BASE1"
jq -e '.sentinel == "yoast-first" and (.mutation_keys | length) == 0' <<<"$BASE2" >/dev/null \
  || fail "yoast-first target premise is not exact: $BASE2"
pass 'both exact plugin and manifest orders are installed without prior mutation debt'

for operation in capture deploy; do
  assert_refusal 1 "$R1" "$operation" "$BASE1"
  assert_refusal 2 "$R2" "$operation" "$BASE2"
done
pass 'no lease, apply session, or provider intent survived'

GREEN=1
printf '\nPASS: Rank Math/Yoast incompatibility is deterministic before mutation in both orders\n'
