#!/usr/bin/env bash
# DUO-3344 slice 4 — exact-source live public-CLI scoped apply regression.
#
# This is intentionally a live-only companion to the compiler/session/wire
# suites.  It owns one disposable pair and drives the normal host `cli/duo`
# interface through DockerTransport.  The fixture plugin owns its provider
# capability; the engine sees only the generic manifest/provider contract.
#
# Required caller inputs are deliberately explicit so this cannot consume a
# shared pair accidentally:
#
#   make regress-scoped-apply-live \
#     SCOPED_APPLY_LIVE_PAIR=codexmacb3344 \
#     SCOPED_APPLY_LIVE_PORT1=<free-even-port> \
#     SCOPED_APPLY_LIVE_PORT2=<next-odd-port> \
#     DUO_EXPECTED_SOURCE_SHA=$(git rev-parse HEAD)
#
# It proves, in one clean pair:
#   * host scope -> scoped target plan is target-bound and read-only;
#   * a selected tombstone refuses before a scoped session/mutation without
#     --with-deletes, then applies and verifies through the public CLI;
#   * provider/native actions are real changed-surface effects with public
#     hash-only receipts, while an unrelated target project stays untouched;
#   * scoped work never advances generic applied_revision/debt;
#   * terminal replay returns byte-stable receipt evidence, stale terminal and
#     stale source authority refuse without a follow-on mutation, and a
#     tampered local contract is rejected by the host before target work.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

PAIR="${SCOPED_APPLY_LIVE_PAIR:-}"
PORT1="${SCOPED_APPLY_LIVE_PORT1:-}"
PORT2="${SCOPED_APPLY_LIVE_PORT2:-}"
EXPECTED_SOURCE_SHA="${DUO_EXPECTED_SOURCE_SHA:-}"
PLUGIN_DIR=duo-agency-cpt
PLUGIN_FILE="code/wp-content/plugins/${PLUGIN_DIR}/${PLUGIN_DIR}.php"
SITE1="$ROOT/sandbox/siterepo/${PAIR}1"
SITE2="$ROOT/sandbox/siterepo/${PAIR}2"
ORIGIN="$ROOT/sandbox/siterepo/origin-${PAIR}.git"
DRIVER_COMPOSE="$ROOT/sandbox/tests/fixtures/duo3344-scoped-live-driver.yml"
DUO="$ROOT/cli/duo"
TMP="$(mktemp -d "${TMPDIR:-/tmp}/duo3344-scoped-live.XXXXXX")"
ENVS="$TMP/envs.json"
PAIR_OWNED=0
PAIR_ATTEMPTED=0

say() { printf '\n== %s ==\n' "$*"; }
pass() { printf 'ok: %s\n' "$*"; }
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }

source_wp() { docker compose -f "$DRIVER_COMPOSE" run --rm -T source wp "$@"; }
target_wp() { docker compose -f "$DRIVER_COMPOSE" run --rm -T target wp "$@"; }

# pair.sh list deliberately emits bare pair names.  Do not accept Docker's
# project prefix or a name prefix: either would make cleanup unsafe.
pair_list_has_exact() { # <bare-pair>; reads list on stdin
  local pair=$1
  grep -Eq "^[[:space:]]*-[[:space:]]*${pair}[[:space:]]*$"
}

assert_pair_list_parser() {
  local sample near
  sample="== live sandbox pairs =="$'\n'"  - ${PAIR}"$'\n'"== stopped pairs =="$'\n'"  - anotherpair"
  near="  - duo-${PAIR}"$'\n'"  - ${PAIR}0"
  pair_list_has_exact "$PAIR" <<<"$sample" || fail "exact pair-list parser missed its owned name"
  if pair_list_has_exact "$PAIR" <<<"$near"; then
    fail "pair-list parser accepted a project/name prefix lookalike"
  fi
}

cleanup() {
  local status=$?
  local list
  trap - EXIT INT TERM
  set +e
  if [ "$PAIR_OWNED" -eq 1 ]; then
    if [ "$PAIR_ATTEMPTED" -eq 1 ]; then
      bash "$ROOT/sandbox/bin/pair.sh" destroy "$PAIR" >/dev/null 2>&1 || status=1
    fi
    rm -rf -- "$SITE1" "$SITE2" "$ORIGIN" || status=1
    if [ -e "$SITE1" ] || [ -e "$SITE2" ] || [ -e "$ORIGIN" ]; then
      printf 'FAIL: cleanup left owned site/origin resource(s): %s %s %s\n' "$SITE1" "$SITE2" "$ORIGIN" >&2
      status=1
    fi
  fi
  rm -rf -- "$TMP" || status=1
  if [ -e "$TMP" ]; then
    printf 'FAIL: cleanup left its mktemp allocation: %s\n' "$TMP" >&2
    status=1
  fi
  list="$(bash "$ROOT/sandbox/bin/pair.sh" list 2>&1)" || status=1
  if pair_list_has_exact "$PAIR" <<<"$list"; then
    printf 'FAIL: exact cleanup left pair %s in pair.sh list:\n%s\n' "$PAIR" "$list" >&2
    status=1
  fi
  exit "$status"
}
trap cleanup EXIT
trap 'exit 130' INT TERM

extract_final_json() { # <mixed-output-file> <json-file>
  php -r '
    $raw=file_get_contents($argv[1]);
    $final=null; $bestEnd=-1; $n=strlen($raw);
    // Scope --contract deliberately prints canonical pretty JSON, while the
    // normal command/refusal path is one line.  Locate complete objects with
    // a tiny string-aware brace scanner instead of assuming either shape.
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

run_duo_refusal_json() { # <label> <receipt-file> <public cli args...>
  local label=$1 receipt=$2
  local output="$TMP/${label}.out"
  shift 2
  if "$DUO" --envs-file="$ENVS" "$@" >"$output" 2>&1; then
    sed -n '1,240p' "$output" >&2
    fail "$label unexpectedly succeeded"
  fi
  extract_final_json "$output" "$receipt"
  jq -e '.format == "duo-command-refusal/v1" and .ok == false' "$receipt" >/dev/null \
    || fail "$label did not produce a typed public refusal"
}

write_site_policy() { # <path>
  php -r '
    $policy=[
      "manifests"=>["core","duo-agency-cpt"],
      "policy"=>[
        "options"=>(object)[], "post_meta"=>(object)[],
        "post_types"=>["post","page","attachment","project"],
        "taxonomies"=>["category"],
      ],
      "spec_version"=>2,
    ];
    $bytes=json_encode($policy, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
    if (file_put_contents($argv[1], $bytes, LOCK_EX) !== strlen($bytes)) exit(1);
  ' "$1" || fail "could not write isolated site policy"
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

assert_driver_registry() {
  local listing report env
  if ! listing="$("$DUO" --envs-file="$ENVS" envs 2>&1)"; then
    printf '%s\n' "$listing" >&2
    fail "explicit public-CLI environment registry could not be loaded"
  fi
  grep -Eq '^source[[:space:]]+docker .* service=source repo_path=/siterepo$' <<<"$listing" \
    || fail "explicit environment registry did not resolve the source Docker driver"
  grep -Eq '^target[[:space:]]+docker .* service=target repo_path=/siterepo$' <<<"$listing" \
    || fail "explicit environment registry did not resolve the target Docker driver"
  for env in source target; do
    report="$TMP/driver-${env}.json"
    if ! "$DUO" --envs-file="$ENVS" driver-capabilities "$env" --operation=capture --format=json >"$report" 2>&1; then
      sed -n '1,160p' "$report" >&2
      fail "$env driver capability preflight failed"
    fi
    jq -e '.format == "duo-environment-driver-capabilities/v1" and .operation == "capture" and .ready == true' \
      "$report" >/dev/null || fail "$env driver did not attest the capture requirements"
  done
}

source_uuid() { source_wp post meta get "$1" _duo_uuid | tr -d '\r\n'; }
target_project_id() {
  target_wp post list --post_type=project --post_status=any --meta_key=_duo_uuid --meta_value="$1" --format=ids \
    | tr -d '[:space:]'
}
target_title() { target_wp post get "$1" --field=post_title | tr -d '\r\n'; }
target_kv() {
  target_wp eval "echo \\Duo\\Ledger::kv_get('$1') ?? '__DUO_NULL__';" \
    | tr -d '\r\n'
}

# The digest covers exactly the selected authored row(s), durable mapping/state,
# generated provider/native surfaces, and generic apply marker vocabulary.
# The promotion lock is included too: it must be absent once each command
# returns, even where the product briefly acquires it internally.
target_boundary_digest() {
  local file="$TMP/boundary-$RANDOM.txt"
  target_wp db query "
    SELECT 'post', ID, post_type, post_status, post_title FROM wp_posts WHERE post_type='project' ORDER BY ID;
    SELECT 'option', option_name, option_value FROM wp_options WHERE option_name IN ('duo_agency_project_index','_transient_duo_agency_project_cache','_transient_timeout_duo_agency_project_cache') ORDER BY option_name;
    SELECT 'map', uuid, entity_type, id_kind, local_id FROM wp_duo_map ORDER BY uuid, id_kind;
    SELECT 'state', uuid, entity_type, content_hash FROM wp_duo_state ORDER BY uuid;
    SELECT 'kv', k, v FROM wp_duo_kv WHERE k IN ('applied_revision','apply_in_progress','promotion_lock','scoped_apply_session') OR k LIKE 'regen_pending:%' ORDER BY k;
  " --skip-column-names >"$file"
  shasum -a 256 "$file" | awk '{print $1}'
}

generic_debt_snapshot() {
  local file="$TMP/debt-$RANDOM.txt"
  target_wp db query "SELECT k, v FROM wp_duo_kv WHERE k = 'apply_in_progress' OR k LIKE 'regen_pending:%' ORDER BY k" --skip-column-names >"$file"
  shasum -a 256 "$file" | awk '{print $1}'
}

assert_generic_scoped_boundary() { # <where>
  local where=$1
  [ "$(target_kv applied_revision)" = "$APPLIED_REVISION_BEFORE" ] \
    || fail "$where advanced generic applied_revision during scoped work"
  [ "$(target_kv apply_in_progress)" = '__DUO_NULL__' ] \
    || fail "$where authored generic apply_in_progress debt"
  [ "$(target_kv promotion_lock)" = '__DUO_NULL__' ] \
    || fail "$where left a promotion lease behind"
  [ "$(generic_debt_snapshot)" = "$GENERIC_DEBT_BEFORE" ] \
    || fail "$where changed generic recovery-debt vocabulary"
}

assert_outside_preserved() {
  local where=$1 outside_id
  outside_id="$(target_project_id "$OUTSIDE_UUID")"
  [ -n "$outside_id" ] || fail "$where removed the out-of-scope target project"
  [ "$(target_title "$outside_id")" = "$OUTSIDE_TARGET_TITLE" ] \
    || fail "$where overwrote the out-of-scope target title"
}

assert_stale_protected_preserved() {
  local where=$1 stale_id
  stale_id="$(target_project_id "$STALE_PROTECTED_UUID")"
  [ -n "$stale_id" ] || fail "$where removed the distinct protected stale-terminal project"
  [ "$(target_title "$stale_id")" = "$STALE_PROTECTED_TARGET_TITLE" ] \
    || fail "$where overwrote the distinct protected stale-terminal project"
}

extract_receipt_bytes() { # <summary-json> <output>
  php -r '
    $r=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);
    if (!is_array($r["scoped_receipt"] ?? null)) exit(1);
    echo json_encode($r["scoped_receipt"], JSON_UNESCAPED_SLASHES), "\n";
  ' "$1" >"$2" || fail "could not extract terminal receipt bytes"
}

assert_hash_only_actions() { # <apply-summary>
  jq -e '
    (.actions | type == "array" and length == 2)
    and ([.actions[].kind] | sort == ["native","provider"])
    and all(.actions[];
      (keys | sort) == ["capability_digest","format","kind","operation_hash","receipt_hash","source_hash","status","verified"]
      and .format == "duo-scoped-effect-receipt/v1"
      and .status == "verified" and .verified == true
      and ([.capability_digest,.operation_hash,.receipt_hash,.source_hash] | all(.[]; test("^[a-f0-9]{64}$")))
    )
  ' "$1" >/dev/null || fail "changed-surface action receipts were not the exact public hash-only provider/native shape"
}

assert_scoped_plan() { # <plan> <contract> <expected selector>
  local plan=$1 contract=$2 selector=$3 scope_hash
  scope_hash="$(jq -r '.scope_hash' "$contract")"
  jq -e --arg scope_hash "$scope_hash" --arg selector "$selector" '
    .format == "duo-scoped-plan/v1"
    and .scope.format == "duo-scope-contract/v1"
    and .scope.scope_hash == $scope_hash
    and (.target | keys | sort == ["ledger_map_root","protected_ledger_map_root","protected_out_of_scope_root","selected_before_root","selected_ledger_map_root","target_observation_hash"])
    and (.target | all(.[]; test("^[a-f0-9]{64}$")))
    and (.selected_surfaces | index("post:project") != null)
    and (.selected_actions | length == 2)
    and all(.selected_actions[]; .manifest == "duo-agency-cpt" and (.declaration_hash | test("^[a-f0-9]{64}$")))
  ' "$plan" >/dev/null || fail "scoped plan lacked target-bound roots/selected action evidence for $selector"
}

if ! [[ "$PAIR" =~ ^[a-z][a-z0-9]{2,31}$ ]]; then
  fail "SCOPED_APPLY_LIVE_PAIR must be a safe lowercase disposable pair name"
fi
if ! [[ "$PORT1" =~ ^[0-9]+$ && "$PORT2" =~ ^[0-9]+$ ]]; then
  fail "SCOPED_APPLY_LIVE_PORT1/2 must be decimal ports"
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
export DUO_EXPECTED_SOURCE_SHA="$EXPECTED_SOURCE_SHA"
export DUO3344_PAIR="$PAIR"
export DUO3344_AGENT_SRC="$ROOT/agent"
export DUO3344_MANIFESTS_SRC="$ROOT/manifests"
export DUO3344_SITE1="$SITE1"
export DUO3344_SITE2="$SITE2"
export DUO3344_PLUGIN_DIR="$PLUGIN_DIR"
command -v jq >/dev/null || fail "jq is required"
command -v shasum >/dev/null || fail "shasum is required"
command -v lsof >/dev/null || fail "lsof is required for the no-collision port preflight"

say "static/exact-source preflight before allocating pair resources"
bash -n "$0" || fail "live harness shell syntax failed"
bash -n "$ROOT/sandbox/bin/pair.sh" || fail "pair lifecycle shell syntax failed"
git diff --check || fail "working tree has whitespace errors"
[ "$(git rev-parse HEAD)" = "$DUO_EXPECTED_SOURCE_SHA" ] \
  || fail "current source HEAD does not equal DUO_EXPECTED_SOURCE_SHA"
[ -z "$(git status --porcelain)" ] || fail "exact-source live harness requires a clean standalone clone"
assert_pair_list_parser
docker compose -f "$DRIVER_COMPOSE" config >/dev/null || fail "public CLI driver compose config is invalid"
if lsof -nP -iTCP:"$PORT1" -sTCP:LISTEN >/dev/null 2>&1 || lsof -nP -iTCP:"$PORT2" -sTCP:LISTEN >/dev/null 2>&1; then
  fail "requested live ports are already listening; refuse before pair allocation"
fi
pass "static syntax, clean exact source, driver topology, and port shape are safe"

# The explicit overlay is an operator-selected trust input.  Resolve it and
# negotiate both built-in drivers before pair ownership so a malformed or
# stale host registry can never consume a Docker/database namespace.
write_envs
assert_driver_registry
pass "explicit source/target registry resolves trusted capture-capable Docker drivers"

# List before any pair mutation.  pair.sh itself enforces the shared four-pair
# budget; these local namespace checks prevent us from deleting somebody
# else's failed run on the way out.
PAIR_LIST="$(bash "$ROOT/sandbox/bin/pair.sh" list 2>&1)" || fail "could not inspect pair budget/state"
if pair_list_has_exact "$PAIR" <<<"$PAIR_LIST"; then
  fail "pair '$PAIR' already exists; refusing to take ownership"
fi
if [ -e "$SITE1" ] || [ -e "$SITE2" ] || [ -e "$ORIGIN" ]; then
  fail "isolated site-repository namespace for '$PAIR' already exists; refusing destructive reset"
fi

say "create isolated repositories and codebind source before pair creation"
PAIR_OWNED=1
mkdir -p "$SITE1/code/wp-content/plugins/$PLUGIN_DIR"
cp "$ROOT/sandbox/fixtures/$PLUGIN_DIR/$PLUGIN_DIR.php" "$SITE1/$PLUGIN_FILE"
write_site_policy "$SITE1/site.duo.json"
cp "$ROOT/sandbox/site-repo.gitignore.template" "$SITE1/.gitignore"
git init --bare -b main "$ORIGIN" >/dev/null
git -C "$SITE1" init -q -b main
git -C "$SITE1" remote add origin "../origin-${PAIR}.git"
git -C "$SITE1" -c user.name=duo3344-source -c user.email=duo3344-source@example.test add -A
git -C "$SITE1" -c user.name=duo3344-source -c user.email=duo3344-source@example.test commit -qm 'init: DUO-3344 scoped live fixture'
git -C "$SITE1" push -qu origin main
git clone -q "$ORIGIN" "$SITE2"

say "bring up exactly the authorized headless codebound pair"
PAIR_ATTEMPTED=1
bash "$ROOT/sandbox/bin/pair.sh" up "$PAIR" "$PORT1" "$PORT2" --codebind "$PLUGIN_DIR" --headless
source_wp core is-installed >/dev/null || fail "source public-driver endpoint is unavailable"
target_wp core is-installed >/dev/null || fail "target public-driver endpoint is unavailable"
source_wp plugin activate "$PLUGIN_DIR" >/dev/null
target_wp plugin activate "$PLUGIN_DIR" >/dev/null
source_wp plugin list --status=active --field=name | grep -qx "$PLUGIN_DIR" \
  || fail "source did not activate the codebound plugin"
target_wp plugin list --status=active --field=name | grep -qx "$PLUGIN_DIR" \
  || fail "target did not activate the codebound plugin"
assert_driver_registry
pass "pair is exact-source, plugin codebound, and reachable only through the public CLI driver"

say "seed baseline project state and establish ordinary full-sync metadata"
UPDATE_TITLE_BEFORE='DUO-3344 update before'
UPDATE_TITLE_AFTER='DUO-3344 update after'
UPDATE_TITLE_STALE='DUO-3344 update stale source'
DELETE_TITLE='DUO-3344 delete me'
OUTSIDE_SOURCE_TITLE='DUO-3344 outside source'
OUTSIDE_TARGET_TITLE='DUO-3344 outside target survives'
STALE_PROTECTED_SOURCE_TITLE='DUO-3344 stale protected source'
STALE_PROTECTED_TARGET_TITLE='DUO-3344 stale protected target edit'
UPDATE_SOURCE_ID="$(source_wp post create --post_type=project --post_status=publish --post_title="$UPDATE_TITLE_BEFORE" --porcelain)"
DELETE_SOURCE_ID="$(source_wp post create --post_type=project --post_status=publish --post_title="$DELETE_TITLE" --porcelain)"
OUTSIDE_SOURCE_ID="$(source_wp post create --post_type=project --post_status=publish --post_title="$OUTSIDE_SOURCE_TITLE" --porcelain)"
STALE_PROTECTED_SOURCE_ID="$(source_wp post create --post_type=project --post_status=publish --post_title="$STALE_PROTECTED_SOURCE_TITLE" --porcelain)"
run_duo_json baseline-capture "$TMP/baseline-capture.json" capture source --format=json
UPDATE_UUID="$(source_uuid "$UPDATE_SOURCE_ID")"
DELETE_UUID="$(source_uuid "$DELETE_SOURCE_ID")"
OUTSIDE_UUID="$(source_uuid "$OUTSIDE_SOURCE_ID")"
STALE_PROTECTED_UUID="$(source_uuid "$STALE_PROTECTED_SOURCE_ID")"
for uuid in "$UPDATE_UUID" "$DELETE_UUID" "$OUTSIDE_UUID" "$STALE_PROTECTED_UUID"; do
  [[ "$uuid" =~ ^[a-f0-9-]{36}$ ]] || fail "capture did not mint a durable project UUID"
done
git -C "$SITE1" -c user.name=duo3344-source -c user.email=duo3344-source@example.test add -A
git -C "$SITE1" -c user.name=duo3344-source -c user.email=duo3344-source@example.test commit -qm 'capture: scoped baseline projects'
git -C "$SITE1" push -q origin main
git -C "$SITE2" pull -q --ff-only origin main
run_duo_json baseline-apply "$TMP/baseline-apply.json" apply target --adopt-by-slug=posts --default-author=admin --format=json
jq -e '.canary == "clean"' "$TMP/baseline-apply.json" >/dev/null || fail "ordinary baseline apply did not converge"
APPLIED_REVISION_BEFORE="$(target_kv applied_revision)"
[[ "$APPLIED_REVISION_BEFORE" =~ ^[a-f0-9]{64}$ ]] || fail "ordinary baseline did not establish applied_revision"
GENERIC_DEBT_BEFORE="$(generic_debt_snapshot)"
[ "$(target_kv apply_in_progress)" = '__DUO_NULL__' ] || fail "ordinary baseline left generic apply debt"
pass "baseline is converged; generic revision/debt witnesses are captured for scoped-boundary checks"

say "publish one update plus one tombstone, then preserve a target-only out-of-scope edit"
source_wp post update "$UPDATE_SOURCE_ID" --post_title="$UPDATE_TITLE_AFTER" >/dev/null
source_wp post delete "$DELETE_SOURCE_ID" --force >/dev/null
run_duo_json changed-capture "$TMP/changed-capture.json" capture source --format=json
git -C "$SITE1" -c user.name=duo3344-source -c user.email=duo3344-source@example.test add -A
git -C "$SITE1" -c user.name=duo3344-source -c user.email=duo3344-source@example.test commit -qm 'capture: scoped update and tombstone'
git -C "$SITE1" push -q origin main
git -C "$SITE2" pull -q --ff-only origin main
OUTSIDE_TARGET_ID="$(target_project_id "$OUTSIDE_UUID")"
[ -n "$OUTSIDE_TARGET_ID" ] || fail "baseline target lacks the intended out-of-scope project"
target_wp post update "$OUTSIDE_TARGET_ID" --post_title="$OUTSIDE_TARGET_TITLE" >/dev/null
target_wp transient set duo_agency_project_cache stale-before-scoped-delete 600 >/dev/null
[ "$(target_wp transient get duo_agency_project_cache | tr -d '\r\n')" = stale-before-scoped-delete ] \
  || fail "could not establish a native-action changed surface"
pass "source has independent update/tombstone intent; target-only project edit and stale cache are now protected evidence"

say "scope/plan the selected tombstone through the public host CLI"
TOMB_CONTRACT="$TMP/tombstone.scope.json"
run_duo_json tombstone-scope "$TOMB_CONTRACT" scope source "--roots=tombstone:${DELETE_UUID}" --contract --format=json
jq -e --arg uuid "$DELETE_UUID" '
  .format == "duo-scope-contract/v1"
  and .selectors == ["tombstone:" + $uuid]
  and (.tombstones | length == 1 and .[0].uuid == $uuid)
  and ([.potential_actions[].source] | sort == ["native:transient.delete","provider:duo-agency-index/rebuild_project_index"])
  and (.potential_providers | length == 1 and .[0].id == "duo-agency-index")
' "$TOMB_CONTRACT" >/dev/null || fail "tombstone contract lacked exact scoped provider/native eligibility"
run_duo_json tombstone-plan "$TMP/tombstone-plan.json" plan target "--scope-contract=$TOMB_CONTRACT" --format=json
assert_scoped_plan "$TMP/tombstone-plan.json" "$TOMB_CONTRACT" "tombstone:${DELETE_UUID}"
[ "$(target_kv scoped_apply_session)" = '__DUO_NULL__' ] \
  || fail "read-only scoped plan created a scoped session"
assert_generic_scoped_boundary "read-only scoped plan"
pass "public scoped plan reports the immutable contract plus fresh target-bound roots without creating session/debt"

say "prove --with-deletes is an early scoped refusal with no session or authored mutation"
EARLY_BOUNDARY_BEFORE="$(target_boundary_digest)"
run_duo_refusal_json tombstone-without-deletes "$TMP/tombstone-without-deletes.json" apply target "--scope-contract=$TOMB_CONTRACT" --format=json
jq -e '.command == "apply" and .reason_code == "apply_refused" and (.message | contains("--with-deletes"))' \
  "$TMP/tombstone-without-deletes.json" >/dev/null \
  || fail "missing --with-deletes did not surface the public scoped delete refusal"
[ "$(target_kv scoped_apply_session)" = '__DUO_NULL__' ] \
  || fail "missing --with-deletes created a scoped session"
[ "$(target_boundary_digest)" = "$EARLY_BOUNDARY_BEFORE" ] \
  || fail "missing --with-deletes changed selected/protected/ledger/action state"
assert_generic_scoped_boundary "early delete refusal"
assert_outside_preserved "early delete refusal"
DELETE_TARGET_ID="$(target_project_id "$DELETE_UUID")"
[ -n "$DELETE_TARGET_ID" ] || fail "missing --with-deletes removed the selected target project"
pass "tombstone refusal happened before session creation, authored mutation, provider/native action, or generic debt"

say "apply selected tombstone with explicit deletion authority and verify changed surfaces"
run_duo_json tombstone-apply "$TMP/tombstone-apply.json" apply target "--scope-contract=$TOMB_CONTRACT" --with-deletes --format=json
jq -e '
  .format == "duo-scoped-apply-result/v1"
  and .canary == "clean"
  and .verification.format == "duo-scoped-convergence/v1"
  and .verification.result == "pass"
  and .verification.selected_deletions == 1
  and (.verification.protected_out_of_scope_root | test("^[a-f0-9]{64}$"))
  and (.verification.protected_ledger_map_root | test("^[a-f0-9]{64}$"))
  and .scoped_receipt.phase == "complete"
  and ([.scoped_receipt.authority_hash,.scoped_receipt.selected_ledger_map_hash,.scoped_receipt.protected_ledger_map_hash,.scoped_receipt.terminal_hash] | all(.[]; test("^[a-f0-9]{64}$")))
' "$TMP/tombstone-apply.json" >/dev/null || fail "scoped tombstone apply lacked terminal target-bound convergence evidence"
assert_hash_only_actions "$TMP/tombstone-apply.json"
[ -z "$(target_project_id "$DELETE_UUID")" ] || fail "scoped tombstone apply left selected target project live"
CACHE_ROWS="$(target_wp db query "SELECT COUNT(*) FROM wp_options WHERE option_name IN ('_transient_duo_agency_project_cache','_transient_timeout_duo_agency_project_cache')" --skip-column-names | awk 'NF {last=$0} END {print last}')"
[ "$CACHE_ROWS" = 0 ] || fail "native transient.delete did not clear the stale cache"
TARGET_PROJECT_IDS="$(target_wp post list --post_type=project --post_status=publish --orderby=ID --order=ASC --format=ids | tr -d '\r\n')"
TARGET_PROJECT_IDS_JSON="$(jq -cn '$ARGS.positional | map(tonumber)' --args $TARGET_PROJECT_IDS)"
target_wp option get duo_agency_project_index --format=json >"$TMP/index-after-tombstone.json"
jq -e --argjson ids "$TARGET_PROJECT_IDS_JSON" '.ids == $ids and (.titles | length == ($ids | length))' "$TMP/index-after-tombstone.json" >/dev/null \
  || fail "plugin-owned provider did not rebuild the target-local project index"
assert_outside_preserved "scoped tombstone apply"
assert_generic_scoped_boundary "scoped tombstone apply"
pass "real plugin provider and native action changed their target surfaces; public receipts exposed hashes only"

say "replay the terminal tombstone authority and require byte-stable receipt evidence"
run_duo_json tombstone-replay "$TMP/tombstone-replay.json" apply target "--scope-contract=$TOMB_CONTRACT" --with-deletes --format=json
jq -e '.format == "duo-scoped-apply-result/v1" and .replayed == true and .applied == 0 and (.actions | length == 0) and .verification == null' \
  "$TMP/tombstone-replay.json" >/dev/null || fail "terminal replay did not take the no-mutation replay path"
extract_receipt_bytes "$TMP/tombstone-apply.json" "$TMP/tombstone-terminal-first.bytes"
extract_receipt_bytes "$TMP/tombstone-replay.json" "$TMP/tombstone-terminal-replay.bytes"
cmp -s "$TMP/tombstone-terminal-first.bytes" "$TMP/tombstone-terminal-replay.bytes" \
  || fail "terminal replay changed durable terminal receipt bytes"
assert_outside_preserved "terminal replay"
assert_generic_scoped_boundary "terminal replay"
pass "terminal replay returned exactly the original durable receipt and no fresh action work"

say "make the completed tombstone authority stale and require replay refusal without engine mutation"
STALE_PROTECTED_TARGET_ID="$(target_project_id "$STALE_PROTECTED_UUID")"
[ -n "$STALE_PROTECTED_TARGET_ID" ] || fail "baseline target lacks distinct stale-terminal protected project"
target_wp post update "$STALE_PROTECTED_TARGET_ID" --post_title="$STALE_PROTECTED_TARGET_TITLE" >/dev/null
STALE_TERMINAL_BOUNDARY_BEFORE="$(target_boundary_digest)"
run_duo_refusal_json stale-terminal "$TMP/stale-terminal.json" apply target "--scope-contract=$TOMB_CONTRACT" --with-deletes --format=json
jq -e '.command == "apply" and .reason_code == "apply_refused" and (.message | contains("no mutation or replay attempted"))' \
  "$TMP/stale-terminal.json" >/dev/null || fail "stale terminal authority did not refuse its replay boundary"
[ "$(target_boundary_digest)" = "$STALE_TERMINAL_BOUNDARY_BEFORE" ] \
  || fail "stale terminal refusal mutated target evidence"
assert_outside_preserved "stale terminal refusal"
assert_stale_protected_preserved "stale terminal refusal"
assert_generic_scoped_boundary "stale terminal refusal"
pass "completed authority refuses stale protected-target replay before follow-on engine mutation"

say "scope/plan/apply the independent selected update while preserving the outside target edit"
UPDATE_CONTRACT="$TMP/update.scope.json"
run_duo_json update-scope "$UPDATE_CONTRACT" scope source "--roots=post:${UPDATE_UUID}" --contract --format=json
jq -e --arg uuid "$UPDATE_UUID" '
  .format == "duo-scope-contract/v1"
  and .selectors == ["post:" + $uuid]
  and (.live.roots | length == 1 and .[0].entity == $uuid)
  and (.tombstones | length == 0)
' "$UPDATE_CONTRACT" >/dev/null || fail "update contract did not bind exactly the selected live project"
run_duo_json update-plan "$TMP/update-plan.json" plan target "--scope-contract=$UPDATE_CONTRACT" --format=json
assert_scoped_plan "$TMP/update-plan.json" "$UPDATE_CONTRACT" "post:${UPDATE_UUID}"
run_duo_json update-apply "$TMP/update-apply.json" apply target "--scope-contract=$UPDATE_CONTRACT" --format=json
jq -e '
  .format == "duo-scoped-apply-result/v1"
  and .canary == "clean"
  and .verification.format == "duo-scoped-convergence/v1"
  and .verification.result == "pass"
  and .verification.selected_live == 1
  and .scoped_receipt.phase == "complete"
' "$TMP/update-apply.json" >/dev/null || fail "scoped update did not return target-bound convergence evidence"
assert_hash_only_actions "$TMP/update-apply.json"
UPDATE_TARGET_ID="$(target_project_id "$UPDATE_UUID")"
[ "$(target_title "$UPDATE_TARGET_ID")" = "$UPDATE_TITLE_AFTER" ] || fail "scoped update did not apply selected source title"
assert_outside_preserved "scoped update apply"
assert_stale_protected_preserved "scoped update apply"
assert_generic_scoped_boundary "scoped update apply"
pass "new scoped authority updates only its selected project; generic revision/debt and outside target state remain preserved"

say "tamper the host-local contract and prove public host refusal before target mutation"
TAMPERED_CONTRACT="$TMP/tampered-update.scope.json"
php -r '
  $c=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);
  $c["scope_hash"]=str_repeat("0",64);
  file_put_contents($argv[2],json_encode($c,JSON_UNESCAPED_SLASHES)."\n");
' "$UPDATE_CONTRACT" "$TAMPERED_CONTRACT" || fail "could not create controlled tampered contract"
TAMPERED_BOUNDARY_BEFORE="$(target_boundary_digest)"
run_duo_refusal_json tampered-contract "$TMP/tampered-contract.json" plan target "--scope-contract=$TAMPERED_CONTRACT" --format=json
jq -e '.command == "plan" and .reason_code == "scope_contract_invalid"' "$TMP/tampered-contract.json" >/dev/null \
  || fail "tampered local scope contract was not refused at the host boundary"
[ "$(target_boundary_digest)" = "$TAMPERED_BOUNDARY_BEFORE" ] \
  || fail "tampered host contract caused a target mutation"
assert_outside_preserved "tampered contract refusal"
assert_stale_protected_preserved "tampered contract refusal"
assert_generic_scoped_boundary "tampered contract refusal"
pass "tampered local evidence is rejected by public CLI before target work or mutation"

say "advance source again and require stale compact authority refusal without mutation"
source_wp post update "$UPDATE_SOURCE_ID" --post_title="$UPDATE_TITLE_STALE" >/dev/null
run_duo_json stale-source-capture "$TMP/stale-source-capture.json" capture source --format=json
git -C "$SITE1" -c user.name=duo3344-source -c user.email=duo3344-source@example.test add -A
git -C "$SITE1" -c user.name=duo3344-source -c user.email=duo3344-source@example.test commit -qm 'capture: stale scoped authority source advance'
git -C "$SITE1" push -q origin main
git -C "$SITE2" pull -q --ff-only origin main
STALE_SOURCE_BOUNDARY_BEFORE="$(target_boundary_digest)"
run_duo_refusal_json stale-source-contract "$TMP/stale-source-contract.json" apply target "--scope-contract=$UPDATE_CONTRACT" --format=json
jq -e '.command == "apply" and .reason_code == "apply_refused"' "$TMP/stale-source-contract.json" >/dev/null \
  || fail "stale source-bound compact authority did not refuse"
[ "$(target_boundary_digest)" = "$STALE_SOURCE_BOUNDARY_BEFORE" ] \
  || fail "stale source authority refusal changed target state"
assert_outside_preserved "stale source authority refusal"
assert_stale_protected_preserved "stale source authority refusal"
assert_generic_scoped_boundary "stale source authority refusal"
pass "stale source artifact cannot reuse old scoped authority and leaves target untouched"

printf '\n✔ REGRESS_SCOPED_APPLY_LIVE PASSED (pair %s cleaned exactly on exit)\n' "$PAIR"
