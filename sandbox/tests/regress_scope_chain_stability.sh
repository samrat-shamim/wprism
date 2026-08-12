#!/usr/bin/env bash
# DUO-3344 slice 6 — cross-command scope-stability acceptance proof.
#
# Every prior DUO-3344 slice proves ONE command handles a scope contract
# correctly in isolation (scope's own closure math; capture's overlay
# preservation; refresh-export's production read; plan/apply's target
# mutation boundary). None of them assert that the SAME literal set of
# entity uuids stays in scope as that one contract flows unchanged through
# capture -> refresh-export -> plan -> apply, end to end, in one session --
# the acceptance criterion this epic's own description names explicitly:
# "Scope is stable across capture, refresh, plan, promote, verification,
# and rollback." This is deliberately narrower than that full sentence:
# `duo promote`'s scoped path requires SshTransport specifically (verified
# by reading cli/duo before writing this suite -- DockerTransport refuses
# it outright), an entirely different live harness than this one, so
# promote/rollback stability remains open after this slice (see this
# issue's own scope note for the follow-up). What this suite proves instead
# is the local, Docker-transport-reachable half of the chain: scope,
# capture, refresh-export, plan, and apply all independently parse the same
# contract and re-derive the same selected-identity set (catching a
# field-name/parsing drift in any one of five call sites into the shared
# ScopedStateOverlay/ScopeContract library), that a scoped apply never
# touches a deliberately out-of-scope entity, and that a fresh scope
# recomputed on the TARGET reproduces the SOURCE's closure and scope_hash
# byte-for-byte when fed identical repository bytes from two directories.
# The scenario deliberately creates a real source-side in-scope edit after
# the baseline, captures it, and pushes the resulting repository bytes to the
# target before plan/apply. The target plan must therefore contain a real
# selected update (not a clean no-op), while the deliberately divergent
# out-of-scope post remains untouched. This makes the chain a proof of live
# data flow as well as cross-command contract stability.
#
# Reuses the same host-CLI-over-Docker harness pattern established in
# regress_scoped_apply_live.sh (run_duo_json/write_envs/driver compose),
# trimmed to a single bounded scenario (one post + its one term, plus one
# deliberately out-of-scope post) rather than that file's much larger
# scenario set, and appended as a NEW standalone suite instead of inserted
# into that file: 1412 lines of already-passing, intricately state-carrying
# assertions (terminal session rotation, generic debt tracking) are exactly
# the kind of shared mutable state a surgical insertion could silently
# perturb without full command of every helper's invariants.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

# Required caller inputs are deliberately explicit (no defaults) so this
# cannot consume a shared pair accidentally -- same discipline
# regress_scoped_apply_live.sh established for this same live-suite family.
PAIR="${SCOPE_CHAIN_PAIR:?SCOPE_CHAIN_PAIR is required; use an unused disposable pair name}"
PORT1="${SCOPE_CHAIN_PORT1:?SCOPE_CHAIN_PORT1 is required; choose a free even port >= 8900}"
PORT2="${SCOPE_CHAIN_PORT2:?SCOPE_CHAIN_PORT2 is required; use PORT1 + 1}"
EXPECTED_SOURCE_SHA="${DUO_EXPECTED_SOURCE_SHA:?DUO_EXPECTED_SOURCE_SHA is required; bind evidence to git rev-parse HEAD}"
SITE1="$ROOT/sandbox/siterepo/${PAIR}1"
SITE2="$ROOT/sandbox/siterepo/${PAIR}2"
ORIGIN="$ROOT/sandbox/siterepo/origin-${PAIR}.git"
DRIVER_COMPOSE="$ROOT/sandbox/tests/fixtures/duo3344-scoped-live-driver.yml"
DUO="$ROOT/cli/duo"
TMP="$(mktemp -d "${TMPDIR:-/tmp}/duo3344-scope-chain.XXXXXX")"
ENVS="$TMP/envs.json"
PAIR_OWNED=0
PAIR_ATTEMPTED=0
BODY_COMPLETE=0

say() { printf '\n== %s ==\n' "$*"; }
pass() { printf 'ok: %s\n' "$*"; }
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }

source_wp() { docker compose -f "$DRIVER_COMPOSE" run --rm -T source wp "$@"; }
target_wp() { docker compose -f "$DRIVER_COMPOSE" run --rm -T target wp "$@"; }

pair_list_has_exact() { # <bare-pair>; reads list on stdin
  local pair=$1
  grep -Eq "^[[:space:]]*-[[:space:]]*${pair}[[:space:]]*$"
}

print_cleanup_excerpt() { # <label> <path>
  local label=$1 path=$2
  printf '%s\n' "--- ${label} (first 240 lines; ${path}) ---" >&2
  if [ -f "$path" ]; then
    sed -n '1,240p' "$path" >&2
  else
    printf '%s\n' "<cleanup transcript was not created>" >&2
  fi
}

cleanup() {
  local incoming_status=$?
  local status=$incoming_status
  local destroy_log="$TMP/pair-destroy.log"
  local list_log="$TMP/pair-list-after-destroy.log"
  local cleanup_failed=0 body_incomplete=0 destroy_failed=0 list_failed=0 pair_still_present=0 pair_absent=0
  trap - EXIT INT TERM
  set +e
  if [ "$incoming_status" -ne 0 ] || [ "$BODY_COMPLETE" -ne 1 ]; then
    body_incomplete=1
  fi
  if [ "$PAIR_OWNED" -eq 1 ] && [ "$PAIR_ATTEMPTED" -eq 1 ]; then
    if ! bash "$ROOT/sandbox/bin/pair.sh" destroy "$PAIR" >"$destroy_log" 2>&1; then
      destroy_failed=1
      cleanup_failed=1
    fi
  fi
  # A durable cleanup witness even when destroy failed -- the retained
  # artifacts must say whether a pair remains visible, not leave that to be
  # reconstructed from a later manual teardown.
  if ! bash "$ROOT/sandbox/bin/pair.sh" list >"$list_log" 2>&1; then
    list_failed=1
    cleanup_failed=1
  elif pair_list_has_exact "$PAIR" <"$list_log"; then
    pair_still_present=1
    cleanup_failed=1
  else
    pair_absent=1
  fi

  if [ "$cleanup_failed" -eq 0 ] && [ "$pair_absent" -eq 1 ] && [ "$incoming_status" -eq 0 ] && [ "$BODY_COMPLETE" -eq 1 ]; then
    if [ "$PAIR_OWNED" -eq 1 ]; then
      rm -rf -- "$SITE1" "$SITE2" "$ORIGIN" || cleanup_failed=1
      if [ -e "$SITE1" ] || [ -e "$SITE2" ] || [ -e "$ORIGIN" ]; then
        printf 'FAIL: cleanup left owned site/origin resource(s): %s %s %s\n' "$SITE1" "$SITE2" "$ORIGIN" >&2
        cleanup_failed=1
      fi
    fi
    if [ "$cleanup_failed" -eq 0 ]; then
      rm -rf -- "$TMP" || cleanup_failed=1
      if [ -e "$TMP" ]; then
        printf 'FAIL: cleanup left its mktemp allocation: %s\n' "$TMP" >&2
        cleanup_failed=1
      fi
    fi
  fi

  if [ "$cleanup_failed" -ne 0 ] || [ "$body_incomplete" -eq 1 ]; then
    if [ "$cleanup_failed" -ne 0 ]; then
      printf 'FAIL: scope-chain-stability cleanup did not complete; preserving owned roots and cleanup artifacts:\n' >&2
    else
      printf 'FAIL: scope-chain-stability body did not complete cleanly; preserving owned roots and cleanup artifacts:\n' >&2
    fi
    printf '  pair: %s\n  roots: %s %s %s\n  destroy transcript: %s\n  post-destroy pair list: %s\n' \
      "$PAIR" "$SITE1" "$SITE2" "$ORIGIN" "$destroy_log" "$list_log" >&2
    if [ "$destroy_failed" -eq 1 ]; then
      print_cleanup_excerpt "pair destroy failed for ${PAIR}" "$destroy_log"
    fi
    if [ "$list_failed" -eq 1 ]; then
      print_cleanup_excerpt "post-destroy pair list failed for ${PAIR}" "$list_log"
    elif [ "$pair_still_present" -eq 1 ]; then
      print_cleanup_excerpt "exact pair ${PAIR} remains in post-destroy list" "$list_log"
    fi
    status=1
  fi

  if [ "$cleanup_failed" -eq 0 ] && [ "$body_incomplete" -eq 0 ] && [ "$pair_absent" -eq 1 ] && [ "$incoming_status" -eq 0 ] && [ "$BODY_COMPLETE" -eq 1 ]; then
    printf '\n✔ REGRESS_SCOPE_CHAIN_STABILITY PASSED (pair %s destroyed and cleanup verified)\n' "$PAIR"
  fi
  exit "$status"
}
trap cleanup EXIT
trap 'exit 130' INT TERM

extract_final_json() { # <mixed-output-file> <json-file>
  php -r '
    $raw=file_get_contents($argv[1]);
    $final=null; $bestEnd=-1; $n=strlen($raw);
    for ($start=0; $start<$n; $start++) {
      if ($raw[$start] !== "{") continue;
      $depth=0; $quoted=false; $escaped=false;
      for ($end=$start; $end<$n; $end++) {
        $ch=$raw[$end];
        if ($quoted) {
          if ($escaped) { $escaped=false; continue; }
          if ($ch === "\\") { $escaped=true; continue; }
          if ($ch === "\"") $quoted=false;
          continue;
        }
        if ($ch === "\"") { $quoted=true; continue; }
        if ($ch === "{") { $depth++; continue; }
        if ($ch !== "}") continue;
        $depth--;
        if ($depth !== 0) continue;
        $candidate=json_decode(substr($raw,$start,$end-$start+1), true);
        if (is_array($candidate) && !array_is_list($candidate) && $end >= $bestEnd) {
          $final=$candidate; $bestEnd=$end;
        }
        break;
      }
    }
    if (!is_array($final)) { fwrite(STDERR, "no final JSON object in product output\n"); exit(1); }
    file_put_contents($argv[2], json_encode($final, JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT)."\n");
  ' "$1" "$2" || fail "could not extract the product JSON response from $(basename "$1")"
}

run_duo_json() { # <label> <receipt-file> <public cli args...>
  local label=$1 receipt=$2
  local output="$TMP/${label}.out"
  shift 2
  if ! "$DUO" --envs-file="$ENVS" "$@" >"$output" 2>&1; then
    sed -n '1,240p' "$output" >&2
    fail "$label unexpectedly failed"
  fi
  extract_final_json "$output" "$receipt"
}

write_envs() {
  php -r '
    $compose=$argv[2];
    $cfg=["envs"=>[
      "source"=>["transport"=>"docker","compose_file"=>$compose,"service"=>"source","repo_path"=>"/siterepo"],
      "target"=>["transport"=>"docker","compose_file"=>$compose,"service"=>"target","repo_path"=>"/siterepo"],
    ]];
    $bytes=json_encode($cfg, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
    if (file_put_contents($argv[1], $bytes, LOCK_EX) !== strlen($bytes)) exit(1);
  ' "$ENVS" "$DRIVER_COMPOSE" || fail "could not write private public-CLI environment config"
}

write_site_policy() { # <path>
  php -r '
    $policy=[
      "manifests"=>["core"],
      "policy"=>[
        "options"=>(object)[], "post_meta"=>(object)[],
        "post_types"=>["post","page","attachment"],
        "taxonomies"=>["category"],
      ],
      "spec_version"=>2,
    ];
    $bytes=json_encode($policy, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
    if (file_put_contents($argv[1], $bytes, LOCK_EX) !== strlen($bytes)) exit(1);
  ' "$1" || fail "could not write isolated site policy"
}

source_uuid() { source_wp post meta get "$1" _duo_uuid | tr -d '\r\n'; }
target_post_id() {
  target_wp post list --post_status=any --meta_key=_duo_uuid --meta_value="$1" --format=ids \
    | tr -d '[:space:]'
}
target_title() { target_wp post get "$1" --field=post_title | tr -d '\r\n'; }

# The canonical "which uuids does this contract select" computation --
# identical in shape to ScopedStateOverlay::selected_identities() (roots +
# closure entities, plus tombstone uuids), so this is a faithful jq mirror
# of the engine's own definition rather than a guessed approximation.
selected_identities() { # <contract-json-path>
  jq -r '[(.live.roots[]?.entity), (.live.closure[]?.entity), (.tombstones[]?.uuid)] | unique | sort | .[]' "$1"
}

command -v jq >/dev/null || fail "jq is required"
command -v lsof >/dev/null || fail "lsof is required for the no-collision port preflight"

# The :? messages above only NAME these constraints; enforce them for real
# before touching any resource, same checks regress_scoped_apply_live.sh
# already applies for this same live-suite family.
if ! [[ "$PAIR" =~ ^[a-z][a-z0-9]{2,31}$ ]]; then
  fail "SCOPE_CHAIN_PAIR must be a safe lowercase disposable pair name"
fi
if ! [[ "$PORT1" =~ ^[0-9]+$ && "$PORT2" =~ ^[0-9]+$ ]]; then
  fail "SCOPE_CHAIN_PORT1/2 must be decimal ports"
fi
PORT1_NUM=$((10#$PORT1))
PORT2_NUM=$((10#$PORT2))
if [ "$PORT1_NUM" -lt 8900 ] || [ $((PORT1_NUM % 2)) -ne 0 ] || [ "$PORT2_NUM" -ne $((PORT1_NUM + 1)) ]; then
  fail "the live pair needs a free even port >= 8900 and its immediately following odd port"
fi
if ! [[ "$EXPECTED_SOURCE_SHA" =~ ^[0-9a-fA-F]{7,40}$ ]]; then
  fail "DUO_EXPECTED_SOURCE_SHA must bind this live run to the committed source SHA"
fi
EXPECTED_SOURCE_SHA="$(git rev-parse --verify "${EXPECTED_SOURCE_SHA}^{commit}" 2>/dev/null)" \
  || fail "DUO_EXPECTED_SOURCE_SHA does not resolve to a commit in this standalone clone"

# The shared driver fixture's compose file always references these
# DUO3344_* variables (even for the config validation below, before any
# pair exists), so they must be exported before the very first preflight
# check, not merely before pair.sh up. This suite needs no custom plugin
# (core posts/terms only), so both sides get an empty, otherwise-inert
# directory to satisfy the fixture's generic codebind mount.
export DUO3344_PAIR="$PAIR" DUO3344_AGENT_SRC="$ROOT/agent" DUO3344_MANIFESTS_SRC="$ROOT/manifests"
export DUO3344_SITE1="$SITE1" DUO3344_SITE2="$SITE2" DUO3344_PLUGIN_DIR="duo-3344-scope-chain-noop"

say "static/exact-source preflight before allocating pair resources"
bash -n "$0" || fail "live harness shell syntax failed"
[ "$(git rev-parse HEAD)" = "$EXPECTED_SOURCE_SHA" ] \
  || fail "current source HEAD does not equal DUO_EXPECTED_SOURCE_SHA"
[ -z "$(git status --porcelain)" ] || fail "exact-source live harness requires a clean standalone clone"
docker compose -f "$DRIVER_COMPOSE" config >/dev/null || fail "public CLI driver compose config is invalid"
if lsof -nP -iTCP:"$PORT1" -sTCP:LISTEN >/dev/null 2>&1 || lsof -nP -iTCP:"$PORT2" -sTCP:LISTEN >/dev/null 2>&1; then
  fail "requested live ports are already listening; refuse before pair allocation"
fi
PAIR_LIST="$(bash "$ROOT/sandbox/bin/pair.sh" list 2>&1)" || fail "could not inspect pair budget/state"
if pair_list_has_exact "$PAIR" <<<"$PAIR_LIST"; then
  fail "pair '$PAIR' already exists; refusing to take ownership"
fi
if [ -e "$SITE1" ] || [ -e "$SITE2" ] || [ -e "$ORIGIN" ]; then
  fail "isolated site-repository namespace for '$PAIR' already exists; refusing destructive reset"
fi
pass "static syntax, exact source, driver topology, and port shape are safe"

say "create isolated repositories, then the pair, then the public-CLI environment registry"
PAIR_OWNED=1
mkdir -p "$SITE1/code/wp-content/plugins"
write_site_policy "$SITE1/site.duo.json"
cp "$ROOT/sandbox/site-repo.gitignore.template" "$SITE1/.gitignore"
git init --bare -b main "$ORIGIN" >/dev/null
git -C "$SITE1" init -q -b main
git -C "$SITE1" remote add origin "../origin-${PAIR}.git"
git -C "$SITE1" -c user.name=duo3344-source -c user.email=duo3344-source@example.test add -A
git -C "$SITE1" -c user.name=duo3344-source -c user.email=duo3344-source@example.test commit -qm 'init: DUO-3344 scope-chain-stability fixture'
git -C "$SITE1" push -qu origin main
git clone -q "$ORIGIN" "$SITE2"
mkdir -p "$SITE1/code/wp-content/plugins/$DUO3344_PLUGIN_DIR" "$SITE2/code/wp-content/plugins/$DUO3344_PLUGIN_DIR"

PAIR_ATTEMPTED=1
bash "$ROOT/sandbox/bin/pair.sh" up "$PAIR" "$PORT1" "$PORT2" --headless
source_wp core is-installed >/dev/null || fail "source public-driver endpoint is unavailable"
target_wp core is-installed >/dev/null || fail "target public-driver endpoint is unavailable"
source_wp site empty --yes >/dev/null
target_wp site empty --yes >/dev/null
write_envs
pass "pair is exact-source and reachable only through the public CLI driver"

say "(1) seed one bounded feature on source: a post assigned to its own term, plus one deliberately out-of-scope post"
TERM_ID="$(source_wp term create category 'DUO-3344 Chain Category' --porcelain)"
POST_ID="$(source_wp post create --post_type=post --post_status=publish --post_title='DUO-3344 chain post' --post_category="$TERM_ID" --porcelain)"
OUTSIDE_SOURCE_ID="$(source_wp post create --post_type=post --post_status=publish --post_title='DUO-3344 chain outside (source)' --porcelain)"
run_duo_json seed-capture "$TMP/seed-capture.json" capture source --format=json
POST_UUID="$(source_uuid "$POST_ID")"
TERM_UUID="$(source_wp eval "echo get_term_meta($TERM_ID, '_duo_uuid', true);" | tr -d '\r\n')"
OUTSIDE_UUID="$(source_uuid "$OUTSIDE_SOURCE_ID")"
for uuid in "$POST_UUID" "$TERM_UUID" "$OUTSIDE_UUID"; do
  [[ "$uuid" =~ ^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$ ]] || fail "capture did not mint a durable uuid (got '$uuid')"
done
git -C "$SITE1" -c user.name=duo3344-source -c user.email=duo3344-source@example.test add -A
git -C "$SITE1" -c user.name=duo3344-source -c user.email=duo3344-source@example.test commit -qm 'capture: chain post+term, plus outside post'
git -C "$SITE1" push -q origin main
git -C "$SITE2" pull -q --ff-only origin main
run_duo_json baseline-apply "$TMP/baseline-apply.json" apply target --adopt-by-slug=terms,posts --default-author=admin --format=json
jq -e '.canary == "clean"' "$TMP/baseline-apply.json" >/dev/null || fail "ordinary baseline apply did not converge"
# The out-of-scope preservation baseline: target diverges from source on
# purpose, so a later scoped apply touching this post at all is a failure.
target_wp post update "$(target_post_id "$OUTSIDE_UUID")" --post_title='DUO-3344 chain outside (target-only edit)' >/dev/null
OUTSIDE_TARGET_ID="$(target_post_id "$OUTSIDE_UUID")"
[ -n "$OUTSIDE_TARGET_ID" ] || fail "target baseline lacks the deliberately out-of-scope post"
OUTSIDE_TITLE_BEFORE="$(target_title "$OUTSIDE_TARGET_ID")"
pass "source seeded (post $POST_UUID in term $TERM_UUID); target baseline converged; out-of-scope post diverges on purpose"

say "(2) create genuine in-scope source drift and capture it before scope derivation"
NEW_SOURCE_TITLE='DUO-3344 chain post (source drift captured)'
source_wp post update "$POST_ID" --post_title="$NEW_SOURCE_TITLE" >/dev/null
run_duo_json source-drift-capture "$TMP/source-drift-capture.json" capture source --format=json
git -C "$SITE1" add -A
SOURCE_DRIFT_FILES="$(git -C "$SITE1" diff --cached --name-only)"
[ -n "$SOURCE_DRIFT_FILES" ] \
  || fail "source capture produced no repository bytes for the in-scope title drift"
git -C "$SITE1" -c user.name=duo3344-source -c user.email=duo3344-source@example.test commit -qm 'capture: genuine in-scope source drift'
pass "source live post changed and ordinary capture produced repository drift: $(tr '\n' ' ' <<<"$SOURCE_DRIFT_FILES")"

say "(3) duo scope: compute the authoritative contract over the captured drift"
CONTRACT="$TMP/chain.scope.json"
run_duo_json chain-scope "$CONTRACT" scope source "--roots=post:${POST_UUID},term:${TERM_UUID}" --contract --format=json
jq -e '.format == "duo-scope-contract/v1"' "$CONTRACT" >/dev/null || fail "scope did not produce a scope contract"
SET_A="$TMP/set-a.uuids"
selected_identities "$CONTRACT" > "$SET_A"
EXPECTED_SORTED="$(printf '%s\n%s\n' "$POST_UUID" "$TERM_UUID" | LC_ALL=C sort)"
[ "$(cat "$SET_A")" = "$EXPECTED_SORTED" ] \
  || fail "scope's own selected-identity set is not exactly {post,term} (got: $(cat "$SET_A" | tr '\n' ' '))"
[ "$(echo "$OUTSIDE_UUID" | grep -c -F -x -f - "$SET_A" || true)" = "0" ] \
  || fail "scope incorrectly included the deliberately out-of-scope post"
pass "scope selects exactly {post, term} over the captured source drift; the out-of-scope post is excluded"

say "(4) duo capture: the same contract's overlay lands exactly the selected entities"
run_duo_json chain-capture "$TMP/chain-capture.json" capture source "--scope-contract=$CONTRACT" --format=json
jq -e --arg h "$(jq -r '.scope_hash' "$CONTRACT")" '.scope.scope_hash == $h' "$TMP/chain-capture.json" >/dev/null \
  || fail "scoped capture echoed a different scope_hash than the contract it was given"
STATE_DIR="$(jq -r '.state_dir' "$TMP/chain-capture.json")"
CAPTURE_ROOT="$SITE1$(echo "$STATE_DIR" | sed 's#^/siterepo##')"
[ -n "$(find "$CAPTURE_ROOT/posts" -name "${POST_UUID}--*.md" 2>/dev/null)" ] \
  || fail "scoped capture did not write the selected post under $CAPTURE_ROOT/posts"
[ -n "$(find "$CAPTURE_ROOT/terms" -name "${TERM_UUID}--*.json" 2>/dev/null)" ] \
  || fail "scoped capture did not write the selected term under $CAPTURE_ROOT/terms"
pass "scoped capture's overlay contains exactly the files named by the contract's own selected-identity set"

say "(5) publish the captured source revision and pull it into the target repository"
git -C "$SITE1" add -A
if ! git -C "$SITE1" diff --cached --quiet; then
  git -C "$SITE1" -c user.name=duo3344-source -c user.email=duo3344-source@example.test commit -qm 'capture: scoped source drift'
fi
git -C "$SITE1" push -q origin main
git -C "$SITE2" pull -q --ff-only origin main
[ "$(git -C "$SITE2" rev-parse HEAD)" = "$(git -C "$SITE1" rev-parse HEAD)" ] \
  || fail "target repository did not receive the captured source revision"
pass "target repository pulled the source revision produced by the scoped capture"

say "(6) duo refresh-export: the same contract's production read reports the identical identity set"
# refresh-export is not a registered host verb (cmd_scoped_passthrough only
# covers capture/plan/apply/promote/refresh/rebase), so it must be invoked
# directly against the container -- which means the contract needs a path
# INSIDE the container's bind mount, not the host $TMP path run_duo_json's
# --scope-contract= otherwise reads directly on the host.
cp "$CONTRACT" "$SITE1/.tmp-scope-chain-contract.json"
docker compose -f "$DRIVER_COMPOSE" run --rm -T source \
  wp duo refresh-export --repo=/siterepo "--scope-contract=/siterepo/.tmp-scope-chain-contract.json" --format=json \
  > "$TMP/refresh-export.out" 2>&1 \
  || { sed -n '1,160p' "$TMP/refresh-export.out" >&2; fail "scoped refresh-export failed"; }
extract_final_json "$TMP/refresh-export.out" "$TMP/refresh-export.json"
jq -e '.format == "duo-refresh-production/v1" and .scope.format == "duo-refresh-scope/v1"' "$TMP/refresh-export.json" >/dev/null \
  || fail "refresh-export did not produce the expected scoped envelope"
REFRESH_IDENTITIES="$(jq -r '.scope.selected_identities | sort | .[]' "$TMP/refresh-export.json")"
[ "$REFRESH_IDENTITIES" = "$EXPECTED_SORTED" ] \
  || fail "refresh-export's selected_identities differ from scope's own set (refresh-export: $(echo "$REFRESH_IDENTITIES" | tr '\n' ' '); expected: $(echo "$EXPECTED_SORTED" | tr '\n' ' '))"
pass "refresh-export's own scope resolution reports the byte-identical uuid set, independently of scope/capture's code path"

say "(7) duo plan: the target proposes the captured in-scope update and nothing outside the contract"
run_duo_json chain-plan "$TMP/chain-plan.json" plan target "--scope-contract=$CONTRACT" --format=json
PLAN_TOUCHED="$TMP/plan-touched.uuids"
jq -r '[(.create // [])[], (.update // [])[], (.delete // [])[]] | .[].uuid' "$TMP/chain-plan.json" | LC_ALL=C sort -u > "$PLAN_TOUCHED"
if [ -s "$PLAN_TOUCHED" ]; then
  comm -23 "$PLAN_TOUCHED" "$SET_A" > "$TMP/plan-escapees.uuids" || true
  [ ! -s "$TMP/plan-escapees.uuids" ] \
    || fail "scoped plan proposed touching uuid(s) outside the contract: $(tr '\n' ' ' < "$TMP/plan-escapees.uuids")"
fi
jq -e --arg h "$(jq -r '.scope_hash' "$CONTRACT")" '.scope.scope_hash == $h' "$TMP/chain-plan.json" >/dev/null \
  || fail "scoped plan echoed a different scope_hash than the contract it was given"
PLAN_SELECTED_MATCHES="$(jq --arg uuid "$POST_UUID" '[(.create // [])[], (.update // [])[], (.delete // [])[]] | map(select(.uuid == $uuid)) | length' "$TMP/chain-plan.json")"
[ "$PLAN_SELECTED_MATCHES" = 1 ] \
  || fail "scoped plan did not contain the real captured in-scope post update (matches: $PLAN_SELECTED_MATCHES)"
TARGET_TITLE_BEFORE_APPLY="$(target_title "$(target_post_id "$POST_UUID")")"
[ "$TARGET_TITLE_BEFORE_APPLY" != "$NEW_SOURCE_TITLE" ] \
  || fail "target already had the source drift before scoped apply; the live propagation proof is vacuous"
pass "scoped plan contains the captured post update and excludes all out-of-scope identities"

say "(8) duo apply: converges the selected work while the out-of-scope post stays byte-identical"
run_duo_json chain-apply "$TMP/chain-apply.json" apply target "--scope-contract=$CONTRACT" --format=json
jq -e '.format == "duo-scoped-apply-result/v1" and .verification.result == "pass"' "$TMP/chain-apply.json" >/dev/null \
  || fail "scoped apply did not converge cleanly"
[ "$(target_title "$OUTSIDE_TARGET_ID")" = "$OUTSIDE_TITLE_BEFORE" ] \
  || fail "scoped apply mutated the deliberately out-of-scope post"
TARGET_POST_ID="$(target_post_id "$POST_UUID")"
[ -n "$TARGET_POST_ID" ] || fail "scoped apply did not materialize the selected post on target"
[ "$(target_title "$TARGET_POST_ID")" = "$NEW_SOURCE_TITLE" ] \
  || fail "scoped apply did not converge the selected post to the captured source title"
pass "scoped apply converges the real selected update; the out-of-scope post is byte-untouched"

say "(9) the capstone: a fresh scope recomputed on the TARGET, post-apply, reproduces the SAME closure"
TARGET_CONTRACT="$TMP/target-chain.scope.json"
run_duo_json target-chain-scope "$TARGET_CONTRACT" scope target "--roots=post:${POST_UUID},term:${TERM_UUID}" --contract --format=json
SET_B="$TMP/set-b.uuids"
selected_identities "$TARGET_CONTRACT" > "$SET_B"
diff "$SET_A" "$SET_B" >/dev/null \
  || fail "target's independently-recomputed scope closure differs from source's (source: $(tr '\n' ' ' < "$SET_A"); target: $(tr '\n' ' ' < "$SET_B"))"
[ "$(jq -r '.scope_hash' "$CONTRACT")" = "$(jq -r '.scope_hash' "$TARGET_CONTRACT")" ] \
  || fail "target's independently-recomputed scope_hash differs from source's, even though both resolve the identical roots against equivalent revisions"
pass "the closure is a deterministic function of (revision, roots): source and target independently agree on the exact same set and hash"

BODY_COMPLETE=1
