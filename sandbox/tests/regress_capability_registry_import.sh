#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/../.."

fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }
pass() { printf 'ok: %s\n' "$*"; }

ROOT=$(mktemp -d "${TMPDIR:-/tmp}/duo-cap-import.XXXXXX")
trap 'rm -rf -- "$ROOT"' EXIT
SOURCE="$ROOT/source"
git clone --quiet --no-hardlinks . "$SOURCE"

# Exercise the exact working bytes even before the parent task commits them.
cp scripts/capability-registry.php "$SOURCE/scripts/capability-registry.php"
cp sandbox/bin/certification-bundle.php "$SOURCE/sandbox/bin/certification-bundle.php"
git -C "$SOURCE" add scripts/capability-registry.php sandbox/bin/certification-bundle.php
git -C "$SOURCE" -c user.name='Duo Regression' -c user.email='duo-regression@example.invalid' \
  commit --quiet --allow-empty -m 'exact import-boundary fixture'
SOURCE_SHA=$(git -C "$SOURCE" rev-parse --verify 'HEAD^{commit}')
[[ "$SOURCE_SHA" =~ ^[0-9a-f]{40}$ ]] || fail "fixture HEAD is not canonical"

INPUTS="$ROOT/inputs"
BUNDLES="$ROOT/bundles"
mkdir -p "$INPUTS" "$BUNDLES"
printf '{"wordpress":"fixture","php":"fixture","database":"fixture","multisite":false}\n' >"$INPUTS/environment.json"
printf '%s\n' '{"format":"duo-manifest-dispositions/v1","manifests":{"fixture":{"status":"experimental"}},"profiles":{}}' >"$INPUTS/ratification.json"
printf '%s\n' '{"schema_version":1,"test":"import-boundary","verdict":"pass","exit_code":0,"assertions":["source-bound"]}' >"$INPUTS/result.json"
printf '%s\n' '{"status":"clean","changed":[]}' >"$INPUTS/diff.json"
printf 'import boundary PASS\n' >"$INPUTS/test.log"
jq -n \
  --arg repo "$SOURCE" --arg revision "$SOURCE_SHA" --arg inputs "$INPUTS" \
  '{repo_root:$repo,created_at:"2026-08-10T00:00:00Z",git_revision:$revision,
    harness:{name:"capability-import-regression",version:1},force_hatches:[],
    environment:($inputs+"/environment.json"),ratification:($inputs+"/ratification.json"),
    bound_inputs:["agent/duo.php"],artifacts:[],tests:[{id:"import-boundary",
    result:($inputs+"/result.json"),diff:($inputs+"/diff.json"),log:($inputs+"/test.log")}]} ' \
  >"$INPUTS/spec.json"
BUILD=$(php "$SOURCE/sandbox/bin/certification-bundle.php" build "$INPUTS/spec.json" "$BUNDLES")
BUNDLE=$(jq -r '.bundle // empty' <<<"$BUILD")
[[ -n "$BUNDLE" && -f "$BUNDLE/bundle.json" ]] || fail "could not build import fixture bundle"

new_case() {
  local name=$1
  local path="$ROOT/$name"
  git clone --quiet --no-hardlinks "$SOURCE" "$path"
  printf '%s' "$path"
}

MATCH=$(new_case matching)
php "$MATCH/scripts/capability-registry.php" import-bundle "$BUNDLE" >/dev/null \
  || fail "clean matching HEAD refused a valid bundle"
jq -e --arg revision "$SOURCE_SHA" \
  '.status == "current" and .bundle.git_revision == $revision' \
  "$MATCH/manifests/capabilities/evidence.json" >/dev/null \
  || fail "matching import did not write source-bound current evidence"
pass "clean matching HEAD imports current evidence"

assert_refuses_unchanged() {
  local repo=$1 label=$2 bundle=${3:-$BUNDLE}
  local evidence="$repo/manifests/capabilities/evidence.json"
  local before after
  before=$(shasum -a 256 "$evidence" | awk '{print $1}')
  if php "$repo/scripts/capability-registry.php" import-bundle "$bundle" >/dev/null 2>&1; then
    fail "$label was accepted"
  fi
  after=$(shasum -a 256 "$evidence" | awk '{print $1}')
  [[ "$before" == "$after" ]] || fail "$label changed evidence before refusing"
  pass "$label refuses without changing evidence"
}

MISMATCH=$(new_case mismatch)
git -C "$MISMATCH" -c user.name='Duo Regression' -c user.email='duo-regression@example.invalid' \
  commit --quiet --allow-empty -m 'different clean head'
assert_refuses_unchanged "$MISMATCH" "canonical wrong-HEAD bundle"

DIRTY_TRACKED=$(new_case dirty-tracked)
printf '\ntracked drift\n' >>"$DIRTY_TRACKED/README.md"
assert_refuses_unchanged "$DIRTY_TRACKED" "dirty tracked importer"

DIRTY_UNTRACKED=$(new_case dirty-untracked)
printf 'untracked drift\n' >"$DIRTY_UNTRACKED/untracked-source.txt"
assert_refuses_unchanged "$DIRTY_UNTRACKED" "dirty untracked importer"

MALFORMED_DIR="$ROOT/malformed-bundle"
mkdir "$MALFORMED_DIR"
php -r '
  $bundle = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
  $bundle["git_revision"] = "bad";
  unset($bundle["bundle_digest"]);
  $normalize = function ($value) use (&$normalize) {
      if (!is_array($value)) return $value;
      if (array_is_list($value)) return array_map($normalize, $value);
      ksort($value, SORT_STRING);
      foreach ($value as $key => $item) $value[$key] = $normalize($item);
      return $value;
  };
  $canonical = json_encode($normalize($bundle), JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";
  $bundle["bundle_digest"] = hash("sha256", $canonical);
  file_put_contents($argv[2], json_encode($bundle, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n");
' "$BUNDLE/bundle.json" "$MALFORMED_DIR/bundle.json"
MALFORMED=$(new_case malformed)
assert_refuses_unchanged "$MALFORMED" "malformed revision bundle" "$MALFORMED_DIR/bundle.json"

CANDIDATE=$(new_case candidate)
php -r '
  $path=$argv[1]; $e=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
  $e["status"]="candidate"; $e["bundle"]["git_revision"]=str_repeat("0",40);
  file_put_contents($path,json_encode($e,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
' "$CANDIDATE/manifests/capabilities/evidence.json"
php "$CANDIDATE/scripts/capability-registry.php" generate >/dev/null \
  || fail "candidate generation incorrectly inherited the import-time HEAD gate"
jq -e '.evidence.status == "candidate"' "$CANDIDATE/manifests/capabilities/registry.json" >/dev/null \
  || fail "candidate generation did not stay non-current"
pass "candidate generation accepts an older canonical revision without granting current status"

# DUO-3406: the reference-bundle builder must record DUO_PAIR_BUDGET_OVERRIDE as
# a force hatch (not hardcode force_hatches:[]), and cap_import_bundle must
# refuse a reference bundle that carries a non-empty force_hatches list.
grep -qE 'force_hatches:\$force_hatches' sandbox/tests/certify_reference_bundle.sh \
  || fail "certify_reference_bundle.sh no longer records a computed force_hatches (hardcoded []?)"
HATCH_SET=$(DUO_PAIR_BUDGET_OVERRIDE=1 jq -cn '[ "DUO_PAIR_BUDGET_OVERRIDE" ] | map(select($ENV[.] // "" | . != "" and . != "0"))')
[ "$HATCH_SET" = '["DUO_PAIR_BUDGET_OVERRIDE"]' ] \
  || fail "the force-hatch computation did not record DUO_PAIR_BUDGET_OVERRIDE when set (got: $HATCH_SET)"
HATCH_UNSET=$(env -u DUO_PAIR_BUDGET_OVERRIDE jq -cn '[ "DUO_PAIR_BUDGET_OVERRIDE" ] | map(select($ENV[.] // "" | . != "" and . != "0"))')
[ "$HATCH_UNSET" = '[]' ] \
  || fail "the force-hatch computation recorded a hatch when the override was unset (got: $HATCH_UNSET)"
pass "reference-bundle builder records DUO_PAIR_BUDGET_OVERRIDE as a force hatch when set, [] when unset"

# Build a bundle that recorded the hatch (same clean revision + passing test as
# the accepted MATCH bundle, differing only in force_hatches) and prove import
# refuses it without touching evidence.
FORCED_OUT="$ROOT/bundles-forced"
mkdir -p "$FORCED_OUT"
FORCED_SPEC="$INPUTS/spec-forced.json"
jq '.force_hatches = ["DUO_PAIR_BUDGET_OVERRIDE"] | .harness.name = "capability-import-regression-forced"' \
  "$INPUTS/spec.json" > "$FORCED_SPEC"
FORCED_BUILD=$(php "$SOURCE/sandbox/bin/certification-bundle.php" build "$FORCED_SPEC" "$FORCED_OUT")
FORCED_BUNDLE=$(jq -r '.bundle // empty' <<<"$FORCED_BUILD")
[[ -n "$FORCED_BUNDLE" && -f "$FORCED_BUNDLE/bundle.json" ]] || fail "could not build the forced-hatch fixture bundle"
jq -e '.force_hatches == ["DUO_PAIR_BUDGET_OVERRIDE"]' "$FORCED_BUNDLE/bundle.json" >/dev/null \
  || fail "forced fixture bundle did not carry the recorded force hatch"
FORCED=$(new_case forced-hatch)
assert_refuses_unchanged "$FORCED" "reference bundle with a recorded force hatch" "$FORCED_BUNDLE/bundle.json"

printf 'REGRESS_CAPABILITY_REGISTRY_IMPORT PASSED\n'
