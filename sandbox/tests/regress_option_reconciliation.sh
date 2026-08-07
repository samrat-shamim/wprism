#!/usr/bin/env bash
# Regression — DUO-3211: authored options are explicit records, not an
# upsert-only flat map. Exercises the product capture/compiler/plan/apply
# path against real wp_options rows, including storage flags and recapture.
#
# DUO-3252: the pair NAME/PORTS are parameterized so this regression can run
# on its own pair instead of colliding with whoever else is using the
# hardcoded default — this exact footgun already reset a foreign pair live
# (an agent ran this script unread, as an ancillary check, and its
# unconditional `pair.sh reset` wiped that pair's database and host state).
# Default PAIR=codexmac3211/PORT1=8900/PORT2=8901 keeps existing single-
# user/CI behavior byte-identical; an agent runs its own copy with e.g.
#   PAIR=amergeor PORT1=8920 PORT2=8921 bash regress_option_reconciliation.sh
# A custom PAIR REQUIRES explicit PORT1/PORT2 (mirrors sandbox/conformance/
# run.sh's own CONF_PAIR mechanism, commit 3aab875, the precedent for this
# exact class of fix): defaulting a custom pair name onto the SAME hardcoded
# ports would just relocate the collision risk from the pair name to the
# port numbers instead of removing it.
set -euo pipefail
cd "$(dirname "$0")/.."

PAIR="${PAIR:-codexmac3211}"
[[ "$PAIR" =~ ^[a-z][a-z0-9]*$ ]] \
  || { echo "FAIL: PAIR '$PAIR' invalid (pair.sh naming: lowercase letters/digits, letter first)" >&2; exit 1; }
if [ "$PAIR" != "codexmac3211" ] && { [ -z "${PORT1:-}" ] || [ -z "${PORT2:-}" ]; }; then
  echo "FAIL: custom PAIR '$PAIR' requires explicit PORT1 and PORT2 (the 8900/8901 defaults belong to the original pair)" >&2
  exit 1
fi
PORT1="${PORT1:-8900}"
PORT2="${PORT2:-8901}"
export DUO_PAIR="$PAIR" DUO_PORT1="$PORT1" DUO_PORT2="$PORT2"
COMPOSE="docker compose -p duo-$PAIR -f pair.yml"
wp_env() { local side="$1"; shift; $COMPOSE run --rm -T -e DUO_MANIFESTS_DIR=/siterepo/test-manifests "cli$side" wp "$@"; }
wp1() { wp_env 1 "$@"; }
wp2() { wp_env 2 "$@"; }
say() { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }

bash bin/pair.sh reset "$PAIR"
bash bin/pair.sh up "$PAIR" "$PORT1" "$PORT2" --headless

R1="siterepo/${PAIR}1"
R2="siterepo/${PAIR}2"
ORIGIN="siterepo/origin-$PAIR.git"
rm -rf "$ORIGIN" "$R1/.git" "$R1/state" "$R1/site.duo.json" "$R1/test-manifests" "$R2"
mkdir -p "$R1/test-manifests"
cp ../manifests/core.json "$R1/test-manifests/core.json"
cat > "$R1/test-manifests/option-matrix.json" <<'JSON'
{
  "name": "option-matrix",
  "spec_version": 2,
  "option_autoload": "preserve",
  "option_namespaces": [{"match": "^duo_matrix_dynamic_"}],
  "option_patterns": [{"match": "^duo_matrix_dynamic_[0-9]+$", "class": "authored"}],
  "options": {
    "duo_matrix_null": {"class": "authored"},
    "duo_matrix_false": {"class": "authored"},
    "duo_matrix_empty": {"class": "authored"},
    "duo_matrix_yes": {"class": "authored"},
    "duo_matrix_no": {"class": "authored"},
    "duo_matrix_auto": {"class": "authored"},
    "duo_matrix_serialized": {"class": "authored"},
    "duo_matrix_delete": {"class": "authored"},
    "duo_matrix_conflict": {"class": "authored"}
  }
}
JSON
cat > "$R1/site.duo.json" <<'JSON'
{
  "manifests": ["core", "option-matrix"],
  "policy": {
    "options": {}, "post_meta": {}, "term_meta": {},
    "post_types": ["post", "page", "attachment"],
    "taxonomies": ["category", "post_tag"]
  },
  "spec_version": 2
}
JSON
cp site-repo.gitignore.template "$R1/.gitignore"

say "seed source rows: null/false/empty/serialized, autoload yes/no/auto, and a dynamic family"
wp1 eval '
global $wpdb;
$rows = [
 ["duo_matrix_null", serialize(null), "yes"],
 ["duo_matrix_false", serialize(false), "yes"],
 ["duo_matrix_empty", "", "yes"],
 ["duo_matrix_yes", "yes-value", "yes"],
 ["duo_matrix_no", "no-value", "no"],
 ["duo_matrix_auto", "auto-value", "auto"],
 ["duo_matrix_serialized", maybe_serialize(["enabled"=>false,"empty"=>"","nil"=>null]), "no"],
 ["duo_matrix_delete", "delete-base", "no"],
 ["duo_matrix_conflict", "base", "yes"],
 ["duo_matrix_dynamic_17", maybe_serialize(["label"=>"dynamic","enabled"=>false]), "no"]
];
foreach ($rows as [$name,$value,$autoload]) {
 $wpdb->replace($wpdb->options,["option_name"=>$name,"option_value"=>$value,"autoload"=>$autoload]);
}
' >/dev/null

git init --bare -b main "$ORIGIN" >/dev/null
git -C "$R1" init -q -b main
git -C "$R1" remote add origin "../origin-$PAIR.git"
wp1 duo capture --repo=/siterepo >/dev/null
jq -e '
  .format == "duo-options/v1"
  and .records.duo_matrix_null.value == null
  and .records.duo_matrix_false.value == false
  and .records.duo_matrix_empty.value == ""
  and .records.duo_matrix_yes.autoload == "yes"
  and .records.duo_matrix_no.autoload == "no"
  and .records.duo_matrix_auto.autoload == "auto"
  and .records.duo_matrix_serialized.value == {enabled:false,empty:"",nil:null}
  and .records.duo_matrix_dynamic_17.value == {label:"dynamic",enabled:false}
  and .records.page_on_front.state == "absent"' "$R1/state/options/core.json" >/dev/null \
  || fail "captured option record matrix is incomplete or lossy"
pass "capture preserves all value shapes, dynamic identity, explicit absence, and exact autoload"

git -C "$R1" -c user.name=duo-option-test -c user.email=option@example.test add -A
git -C "$R1" -c user.name=duo-option-test -c user.email=option@example.test commit -qm "capture option matrix"
git -C "$R1" push -qu origin main
git clone -q "$ORIGIN" "$R2"
REV=$(git -C "$R2" rev-parse HEAD)

say "fresh target: update one row's autoload, create another, and round-trip structured/null-like values"
wp2 eval 'global $wpdb; $wpdb->replace($wpdb->options,["option_name"=>"duo_matrix_no","option_value"=>"stale","autoload"=>"yes"]);' >/dev/null
wp2 duo apply --repo=/siterepo --force-theirs --adopt-by-slug=posts,terms,menus --default-author=admin --revision="$REV" >/dev/null
MATRIX=$(wp2 eval '
global $wpdb; $names=["duo_matrix_null","duo_matrix_false","duo_matrix_empty","duo_matrix_yes","duo_matrix_no","duo_matrix_auto","duo_matrix_serialized","duo_matrix_dynamic_17"];
$out=[]; foreach($names as $name){$r=$wpdb->get_row($wpdb->prepare("SELECT option_value,autoload FROM {$wpdb->options} WHERE option_name=%s",$name),ARRAY_A);$out[$name]=["value"=>maybe_unserialize($r["option_value"]),"autoload"=>$r["autoload"]];} echo wp_json_encode($out);' | tail -1)
echo "$MATRIX" | jq -e '
  .duo_matrix_null.value == null and .duo_matrix_false.value == false and .duo_matrix_empty.value == ""
  and .duo_matrix_no.autoload == "no" and .duo_matrix_auto.autoload == "auto"
  and .duo_matrix_serialized.value == {enabled:false,empty:"",nil:null}
  and .duo_matrix_dynamic_17.autoload == "no"' >/dev/null \
  || fail "target database did not preserve the canonical value/autoload matrix: $MATRIX"
pass "database-level create/update assertions preserve exact values and autoload"

say "three-way conflict: repo and target both edit the same post-base option entity"
jq '.records.duo_matrix_conflict.value = "repo-edit"' "$R2/state/options/core.json" > "$R2/state/options/core.json.tmp"
mv "$R2/state/options/core.json.tmp" "$R2/state/options/core.json"
wp2 option update duo_matrix_conflict target-edit >/dev/null
PLAN=$(wp2 duo plan --repo=/siterepo --format=json 2>/dev/null | tail -1)
echo "$PLAN" | jq -e '[.conflict[] | select(.uuid == "options/core")] | length == 1' >/dev/null \
  || fail "target-local post-base option edit did not produce conflict: $PLAN"
git -C "$R2" checkout -- state/options/core.json
wp2 option update duo_matrix_conflict base >/dev/null
pass "target-local edit is a blocking three-way conflict"

say "capture source-row disappearance as a durable tombstone"
wp1 option delete duo_matrix_delete >/dev/null
wp1 duo capture --repo=/siterepo >/dev/null
jq -e '.records.duo_matrix_delete.state == "deleted" and (.records.duo_matrix_delete.expected_hash | test("^[0-9a-f]{64}$"))' \
  "$R1/state/options/core.json" >/dev/null || fail "source disappearance did not become an option tombstone"
git -C "$R1" -c user.name=duo-option-test -c user.email=option@example.test add state/options/core.json
git -C "$R1" -c user.name=duo-option-test -c user.email=option@example.test commit -qm "delete authored option"
git -C "$R1" push -q origin main
git -C "$R2" pull -q --ff-only

set +e
NO_DELETE=$(wp2 duo apply --repo=/siterepo 2>&1)
NO_DELETE_RC=$?
set -e
[ "$NO_DELETE_RC" -ne 0 ] || fail "option tombstone applied without --with-deletes"
echo "$NO_DELETE" | grep -q -- '--with-deletes' || fail "delete refusal did not name the required safety flag"
[ "$(wp2 option get duo_matrix_delete)" = "delete-base" ] || fail "refused delete mutated the target row"
wp2 duo apply --repo=/siterepo --with-deletes >/dev/null
DELETE_COUNT=$(wp2 eval 'global $wpdb; echo (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name=\"duo_matrix_delete\"");' | tail -1)
[ "$DELETE_COUNT" = "0" ] || fail "explicit option tombstone did not delete the target row"
pass "delete is explicit, flag-gated, database-real, and target row is absent"

say "recapture and retry converge; deletion intent remains byte-identical"
rm -rf "$R2/.tmp-option-recapture"
wp2 duo capture --repo=/siterepo --out=/siterepo/.tmp-option-recapture >/dev/null
diff "$R2/state/options/core.json" "$R2/.tmp-option-recapture/options/core.json" >/dev/null \
  || fail "target recapture did not preserve the tombstone exactly"
DELETE_COUNT=$(wp2 eval 'global $wpdb; echo (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name=\"duo_matrix_delete\"");' | tail -1)
[ "$DELETE_COUNT" = "0" ] || fail "read-only recapture recreated the deleted target row"
RETRY=$(wp2 duo apply --repo=/siterepo --with-deletes --format=json 2>/dev/null | tail -1)
echo "$RETRY" | jq -e '.plan.unchanged >= 1 and .plan.update == 0 and .plan.conflict == 0' >/dev/null \
  || fail "delete retry was not idempotently unchanged: $RETRY"
pass "tombstone recapture diff is empty and retry is idempotent"

say "recreation after deletion and accidental record removal both fail closed"
wp2 eval 'global $wpdb; $wpdb->replace($wpdb->options,["option_name"=>"duo_matrix_delete","option_value"=>"delete-base","autoload"=>"no"]);' >/dev/null
RECREATE=$(wp2 duo plan --repo=/siterepo --format=json 2>/dev/null | tail -1)
echo "$RECREATE" | jq -e '[.conflict[] | select(.uuid == "options/core")] | length == 1' >/dev/null \
  || fail "post-delete recreation was not a conflict: $RECREATE"

BAD="$R2/.tmp-missing-record"
rm -rf "$BAD"
mkdir -p "$BAD/state/options"
cp "$R2/site.duo.json" "$BAD/site.duo.json"
cp "$R2/state/options/core.json" "$BAD/state/options/core.json"
jq 'del(.records.duo_matrix_empty)' "$BAD/state/options/core.json" > "$BAD/state/options/core.json.tmp"
mv "$BAD/state/options/core.json.tmp" "$BAD/state/options/core.json"
set +e
MISSING=$(wp2 duo compile --repo=/siterepo/.tmp-missing-record --out=/tmp/duo-missing.json --format=json 2>&1)
set -e
echo "$MISSING" | tail -1 | jq -e '.ok == false and ([.diagnostics[] | select(.locator == "records.duo_matrix_empty")] | length == 1)' >/dev/null \
  || fail "compiler accepted or failed to identify removal of an exact authored option record: $MISSING"
pass "recreation conflicts and missing records are rejected as non-deletion"

printf '\n\033[1;32m✔ REGRESS_OPTION_RECONCILIATION PASSED\033[0m\n'
