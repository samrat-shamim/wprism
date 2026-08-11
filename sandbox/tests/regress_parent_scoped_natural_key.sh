#!/usr/bin/env bash
# Live regression — DUO-3318: the parent-scoped multi-column natural key,
# end to end across two real environments with genuinely different local ids.
#
# The offline half (sandbox/tests/regress_vocabulary_ownership.sh) proves the
# declaration grammar and the derivation arithmetic. It structurally cannot
# prove the thing the mode exists for: that capture on one environment and
# apply+recapture on ANOTHER, whose auto-increment ids do not line up, produce
# the same UUIDs and byte-identical files. That needs two databases.
#
# The fixture is the existing sandbox/fixtures/duo-agency-cpt plugin, extended
# with two authored tables in the shape a parent-scoped key exists for:
# `duo_agency_rooms` (a site-unique room_code) and `duo_agency_room_slots`
# (a slot_code unique only WITHIN its room, plus its own surrogate slot_id and
# its own authored `capacity` payload). Two different rooms deliberately both
# have a slot called `morning`: that is the ordinary case, and the single
# reason a one-column natural key cannot express this table.
#
# The SHIPPED manifests/duo-agency-cpt.json stays byte-identical — this suite
# supplies the table declaration through a test-manifests overlay
# (DUO_MANIFESTS_DIR, the regress_fatal_mutations.sh / regress_tec_regen.sh
# pattern), and asserts that the shipped file is unchanged before it starts.
# The overlay lives under `.tmp-*`, which the site-repo gitignore template
# already excludes, so it never enters a commit and each side builds its own.
#
# Own pair, so this is regress-live-list material, never regress-offline-all.
set -euo pipefail
cd "$(dirname "$0")/.."   # -> sandbox/

say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }

command -v jq >/dev/null || fail "jq required"

PAIR="${PARENT_KEY_PAIR:-claudemacb3318}"
PORT1="${PARENT_KEY_PORT1:-8930}"
PORT2="${PARENT_KEY_PORT2:-8931}"
PLUGIN_DIR=duo-agency-cpt
PLUGIN_FILE="code/wp-content/plugins/$PLUGIN_DIR/$PLUGIN_DIR.php"
OVERLAY=.tmp-3318-manifests
export DUO_PAIR="$PAIR" DUO_PORT1="$PORT1" DUO_PORT2="$PORT2" DUO_CODEBIND_PLUGIN="$PLUGIN_DIR"
COMPOSE=(docker compose -p "duo-$PAIR" -f pair.yml -f pair.codebind.yml)

wp1()  { "${COMPOSE[@]}" run --rm -T cli1 wp "$@"; }
wp2()  { "${COMPOSE[@]}" run --rm -T cli2 wp "$@"; }
# The overlay-manifest invocations. Everything that loads policy for the two
# declared tables goes through these; plain wp1/wp2 stay on the shipped
# adapter bytes, which is what keeps the shipped manifest honest.
wp1m() { "${COMPOSE[@]}" run --rm -T -e "DUO_MANIFESTS_DIR=/siterepo/$OVERLAY" cli1 wp "$@"; }
wp2m() { "${COMPOSE[@]}" run --rm -T -e "DUO_MANIFESTS_DIR=/siterepo/$OVERLAY" cli2 wp "$@"; }
repo_host() { bash bin/pair.sh repo-host "$PAIR" "$1" >/dev/null; }
GIT1=(git -C "siterepo/${PAIR}1" -c user.name=duo-3318-a -c user.email=a1@example.test)
GIT2=(git -C "siterepo/${PAIR}2" -c user.name=duo-3318-b -c user.email=a2@example.test)

# Last NON-empty line: `wp db query --skip-column-names` emits a trailing blank
# line, so a bare `tail -1` reads the blank and a passing "0" false-fails.
q1() { wp1 db query "$1" --skip-column-names | tr -d '\r' | awk 'NF {last=$0} END {print last}'; }
q2() { wp2 db query "$1" --skip-column-names | tr -d '\r' | awk 'NF {last=$0} END {print last}'; }

GREEN=0
cleanup() {
  if [ "$GREEN" = 1 ]; then
    bash bin/pair.sh destroy "$PAIR" >/dev/null 2>&1 || true
  else
    printf '\033[1;33m(pair %s left up for inspection after a failed run)\033[0m\n' "$PAIR" >&2
  fi
}
trap cleanup EXIT

say "the shipped adapter bytes must be untouched before this suite starts"
git -C .. diff --quiet -- manifests/duo-agency-cpt.json \
  || fail "manifests/duo-agency-cpt.json has uncommitted changes — this suite proves the parent-scoped key WITHOUT changing the shipped adapter"
pass "manifests/duo-agency-cpt.json is unmodified"

say "clean-room site repositories, with the fixture plugin authored before the code-bind containers are created"
# destroy-then-implicit-up rather than reset: pair.sh reset refuses a
# codebind-pinned pair by design, and a destroy of a nonexistent pair is a
# no-op, so the clean first run is unaffected.
bash bin/pair.sh destroy "$PAIR" >/dev/null 2>&1 || true
rm -rf "siterepo/${PAIR}1" "siterepo/${PAIR}2" "siterepo/origin-$PAIR.git"
git init --bare -b main "siterepo/origin-$PAIR.git" >/dev/null
mkdir -p "siterepo/${PAIR}1/code/wp-content/plugins/$PLUGIN_DIR"
cp "fixtures/$PLUGIN_DIR/$PLUGIN_DIR.php" "siterepo/${PAIR}1/$PLUGIN_FILE"
cat > "siterepo/${PAIR}1/site.duo.json" <<'EOF'
{
  "manifests": ["core", "duo-agency-cpt"],
  "policy": {
    "options": {},
    "post_meta": {},
    "post_types": ["post", "page", "attachment", "project"],
    "taxonomies": ["category"]
  },
  "spec_version": 2
}
EOF
cp site-repo.gitignore.template "siterepo/${PAIR}1/.gitignore"
git -C "siterepo/${PAIR}1" init -q -b main
git -C "siterepo/${PAIR}1" remote add origin "../origin-$PAIR.git"
"${GIT1[@]}" add -A
"${GIT1[@]}" commit -qm "init: duo-agency-cpt in code/ + site.duo.json"
git -C "siterepo/${PAIR}1" push -qu origin main
git clone -q "siterepo/origin-$PAIR.git" "siterepo/${PAIR}2"
bash bin/pair.sh up "$PAIR" "$PORT1" "$PORT2" --codebind "$PLUGIN_DIR" --headless
pass "pair $PAIR up (headless, code-bound to $PLUGIN_DIR)"

say "build the test-manifests overlay on BOTH sides (shipped bytes copied, table declaration added)"
for side in 1 2; do
  DIR="siterepo/${PAIR}${side}/$OVERLAY"
  mkdir -p "$DIR"
  cp ../manifests/core.json "$DIR/core.json"
  jq '.tables = {
        "duo_agency_rooms": {
          "class": "authored_snapshot",
          "id_kind": "agency_room",
          "pk": "room_id",
          "slug_column": "room_code",
          "columns": {
            "room_code": {"class": "authored"},
            "room_label": {"class": "authored"}
          },
          "refs": [],
          "identity": {"mode": "natural_key", "column": "room_code"}
        },
        "duo_agency_room_slots": {
          "class": "authored_snapshot",
          "id_kind": "agency_slot",
          "pk": "slot_id",
          "slug_column": "slot_code",
          "columns": {
            "slot_code": {"class": "authored"},
            "capacity": {"class": "authored", "lint_ok": true}
          },
          "refs": [{"column": "room_id", "kind": "agency_room"}],
          "identity": {"mode": "natural_key", "columns": ["room_id", "slot_code"]}
        }
      }' ../manifests/duo-agency-cpt.json > "$DIR/duo-agency-cpt.json.tmp"
  # Atomic publish + container-side settle barrier: the host's plain `>`
  # write races the container's bind-mount view on macOS (observed live —
  # one run's capture transiently loaded a declaration WITHOUT the identity
  # columns these exact bytes carry, then the identical command loaded clean
  # minutes later). mv is atomic on one filesystem; the barrier below proves
  # the CONTAINER sees the final parsed bytes before anything loads policy.
  mv "$DIR/duo-agency-cpt.json.tmp" "$DIR/duo-agency-cpt.json"
  jq -e '.tables.duo_agency_room_slots.identity.columns == ["room_id", "slot_code"]' "$DIR/duo-agency-cpt.json" >/dev/null \
    || fail "overlay manifest on side $side did not receive the parent-scoped identity declaration"
done
jq -e '.tables == null' ../manifests/duo-agency-cpt.json >/dev/null \
  || fail "the SHIPPED duo-agency-cpt manifest gained a tables section — it must stay byte-identical"
for side in 1 2; do
  W=wp${side}m
  for i in $(seq 1 20); do
    SEEN=$($W eval 'echo json_encode((\Duo\Policy::load("/siterepo")->declared_tables()["duo_agency_room_slots"]["identity"]["columns"] ?? []));' 2>/dev/null | tr -d '\r' | tail -1) || SEEN=""
    [ "$SEEN" = '["room_id","slot_code"]' ] && break
    [ "$i" = "20" ] && fail "side $side never saw the settled overlay through the bind mount (last: $SEEN)"
    sleep 1
  done
done
pass "overlay built on both sides and proven visible inside both containers; the shipped manifest declares no tables"

say "side 1 activates the fixture for real; side 2 stays inactive and receives it through deploy alone"
wp1 site empty --yes >/dev/null
wp2 site empty --yes >/dev/null
wp1 plugin activate "$PLUGIN_DIR" >/dev/null
wp1 plugin list --status=active --field=name | grep -qx "$PLUGIN_DIR" \
  || fail "$PLUGIN_DIR did not activate on side 1"
[ "$(q1 "SHOW TABLES LIKE 'wp_duo_agency_room_slots'")" = "wp_duo_agency_room_slots" ] \
  || fail "activation did not create wp_duo_agency_room_slots on side 1"
pass "side 1 active, both tables created by the plugin's own activation hook"

say "seed side 1, with its auto-increment deliberately offset so the two sides cannot accidentally agree"
# The whole proof is that identity survives DIFFERENT local ids. Burn a block
# of ids on side 1 so its real rows start well past 1, which side 2's own
# fresh inserts never will.
wp1 db query "INSERT INTO wp_duo_agency_rooms (room_code, room_label) VALUES ('burn-1','x'),('burn-2','x'),('burn-3','x'),('burn-4','x'),('burn-5','x')" >/dev/null
wp1 db query "DELETE FROM wp_duo_agency_rooms" >/dev/null
wp1 db query "INSERT INTO wp_duo_agency_rooms (room_code, room_label) VALUES ('studio-one','Studio One'),('studio-two','Studio Two')" >/dev/null
ROOM_ONE_1=$(q1 "SELECT room_id FROM wp_duo_agency_rooms WHERE room_code='studio-one'")
ROOM_TWO_1=$(q1 "SELECT room_id FROM wp_duo_agency_rooms WHERE room_code='studio-two'")
[ -n "$ROOM_ONE_1" ] && [ -n "$ROOM_TWO_1" ] || fail "rooms did not land on side 1"
wp1 db query "INSERT INTO wp_duo_agency_room_slots (room_id, slot_code, capacity) VALUES ($ROOM_ONE_1,'morning',8),($ROOM_ONE_1,'evening',12),($ROOM_TWO_1,'morning',4)" >/dev/null
pass "side 1 seeded: rooms $ROOM_ONE_1/$ROOM_TWO_1, three slots, two of them BOTH called 'morning' under different rooms"

say "(1) capture on side 1 — the parent-scoped key derives, and the two 'morning' slots are different identities"
wp1m duo capture --repo=/siterepo
repo_host 1
SLOT_DIR="siterepo/${PAIR}1/state/tables/duo_agency_room_slots"
ROOM_DIR="siterepo/${PAIR}1/state/tables/duo_agency_rooms"
[ "$(ls "$ROOM_DIR"/*.json | wc -l | tr -d ' ')" = "2" ] || fail "expected 2 captured room files"
[ "$(ls "$SLOT_DIR"/*.json | wc -l | tr -d ' ')" = "3" ] || fail "expected 3 captured slot files"
MORNING_UUIDS=$(jq -r 'select(.columns.slot_code == "morning") | .uuid' "$SLOT_DIR"/*.json | sort)
[ "$(echo "$MORNING_UUIDS" | wc -l | tr -d ' ')" = "2" ] || fail "expected exactly two captured 'morning' slots"
[ "$(echo "$MORNING_UUIDS" | sort -u | wc -l | tr -d ' ')" = "2" ] \
  || fail "the two 'morning' slots derived the SAME uuid — the parent component is not participating in identity"
jq -e '.columns.room_id | test("^\\{\\{agency_room:[0-9a-f-]{36}\\}\\}$")' "$SLOT_DIR"/*.json >/dev/null \
  || fail "a slot's room_id was not captured as a portable ref token"
# Structural check, not substring: a bare auto-increment id like "6" would
# substring-match UUID bytes ("6abfed58-...") in every file and false-fail a
# perfect capture (caught live on this script's first run). What must never
# appear is the raw id AS the room_id VALUE.
jq -e --arg id "$ROOM_ONE_1" 'select(.columns.room_id == $id)' "$SLOT_DIR"/*.json | grep -q . \
  && fail "a raw environment-local room id reached canonical state"
pass "identity is scoped by the parent, and no local id reached the repository"

say "(2) publish side 1, then deploy + apply on side 2"
"${GIT1[@]}" add -A
"${GIT1[@]}" commit -qm "capture: rooms and parent-scoped slots"
git -C "siterepo/${PAIR}1" push -q origin main
git -C "siterepo/${PAIR}2" pull -q --ff-only origin main
# wp2m, not wp2: deploy COMPILES the repository, and the state tree carries
# table entities only the overlay declares — the un-overlaid policy refuses
# with invalid_reference_kind (verified live; the refusal itself is correct
# fail-closed behavior, which step (4) asserts on purpose).
DEPLOY_JSON=$(wp2m duo deploy --repo=/siterepo --format=json | tail -1)
echo "$DEPLOY_JSON" | jq -e --arg p "$PLUGIN_DIR/$PLUGIN_DIR.php" '.activated | any(. == $p)' >/dev/null \
  || fail "deploy did not activate $PLUGIN_DIR on side 2 (got: $DEPLOY_JSON)"
[ "$(q2 "SHOW TABLES LIKE 'wp_duo_agency_room_slots'")" = "wp_duo_agency_room_slots" ] \
  || fail "side 2's tables were not created by the deployed plugin's activation hook"
REV=$(git -C "siterepo/${PAIR}2" rev-parse HEAD)
# --adopt-by-slug=terms: side 2's fresh install pre-seeds its own
# 'uncategorized' term with no uuid mapping; first apply adopts it by slug
# (the standard fresh-pair pattern every live suite uses) instead of
# refusing on the slug collision.
APPLY_JSON=$(wp2m duo apply --repo=/siterepo --default-author=admin --adopt-by-slug=terms --revision="$REV" --format=json | tail -1)
echo "$APPLY_JSON" | jq -e '.canary == "clean"' >/dev/null || fail "apply canary was not clean: $APPLY_JSON"
pass "side 2 deployed and applied"

say "(3) THE proof: side 2's own local ids differ, and its independent recapture is byte-identical"
ROOM_ONE_2=$(q2 "SELECT room_id FROM wp_duo_agency_rooms WHERE room_code='studio-one'")
ROOM_TWO_2=$(q2 "SELECT room_id FROM wp_duo_agency_rooms WHERE room_code='studio-two'")
echo "side 1 rooms: $ROOM_ONE_1/$ROOM_TWO_1   |   side 2 rooms: $ROOM_ONE_2/$ROOM_TWO_2"
[ "$ROOM_ONE_1" != "$ROOM_ONE_2" ] \
  || fail "the two sides ended up with the SAME local room id — the auto-increment offset above did not take, so this run cannot prove portability"
wp2m duo capture --repo=/siterepo --out=/siterepo/.tmp-side2state >/dev/null
repo_host 2
diff -r "siterepo/${PAIR}1/state/tables" "siterepo/${PAIR}2/.tmp-side2state/tables" \
  || fail "side 2's independent recapture diverged from side 1 — same authored facts, different bytes or filenames"
pass "identical UUIDs, identical bytes, identical filenames — derived independently on each side from ITS OWN local ids"

say "(3b) the DERIVATION itself, on side 2: drop the slot identities and make it re-derive from side 2's own local ids"
# Step (3) is necessary but not sufficient on its own: every slot uuid it
# compared came out of a duo_map row apply had just written, so
# live_natural_key_components() never actually ran against side 2's genuinely
# different room ids — identify_row() short-circuits on Ledger::uuid_for()
# before it reaches the natural_key branch at all. Deleting exactly the
# agency_slot mappings (the rooms stay mapped, which is the point) forces that
# branch: each slot's room_id is resolved THROUGH the ledger to the room's
# UUID, and the tuple is re-derived from scratch. Byte-identical uuids after
# that can only mean the ref component contributed the room's UUID — side 2's
# own room local ids differ from side 1's, so a derivation that used them
# would produce three different uuids here and nowhere else.
SLOT_MAPS=$(q2 "SELECT COUNT(*) FROM wp_duo_map WHERE id_kind='agency_slot'")
[ "$SLOT_MAPS" = "3" ] || fail "expected 3 agency_slot ledger rows on side 2 before dropping them (got: $SLOT_MAPS)"
wp2 db query "DELETE FROM wp_duo_map WHERE id_kind='agency_slot'" >/dev/null
[ "$(q2 "SELECT COUNT(*) FROM wp_duo_map WHERE id_kind='agency_slot'")" = "0" ] \
  || fail "the agency_slot ledger rows were not actually removed"
[ "$(q2 "SELECT COUNT(*) FROM wp_duo_map WHERE id_kind='agency_room'")" = "2" ] \
  || fail "the agency_room mappings must survive — they are what the ref component resolves through"
wp2m duo capture --repo=/siterepo --out=/siterepo/.tmp-side2rederived >/dev/null
SIDE1_SLOT_UUIDS=$(jq -r '.uuid' "$SLOT_DIR"/*.json | sort)
REDERIVED_SLOT_UUIDS=$(jq -r '.uuid' "siterepo/${PAIR}2/.tmp-side2rederived/tables/duo_agency_room_slots"/*.json | sort)
[ "$SIDE1_SLOT_UUIDS" = "$REDERIVED_SLOT_UUIDS" ] \
  || fail "re-derived slot uuids differ from side 1's — the ref component is NOT the room's uuid (side 1: $SIDE1_SLOT_UUIDS | side 2 re-derived: $REDERIVED_SLOT_UUIDS)"
diff -r "siterepo/${PAIR}1/state/tables" "siterepo/${PAIR}2/.tmp-side2rederived/tables" \
  || fail "the re-derived capture diverged from side 1 in bytes or filenames"
pass "every slot uuid re-derived from scratch against side 2's OWN local ids is byte-identical to side 1's"

say "(4) a slot_code rename is an ordinary update, not delete+create (ledger continuity)"
EVENING_FILE=$(grep -l '"slot_code": "evening"' "$SLOT_DIR"/*.json)
EVENING_UUID=$(jq -r '.uuid' "$EVENING_FILE")
SLOT_ID_2=$(q2 "SELECT slot_id FROM wp_duo_agency_room_slots WHERE slot_code='evening'")
wp2 db query "UPDATE wp_duo_agency_room_slots SET slot_code='twilight' WHERE slot_id=$SLOT_ID_2" >/dev/null
wp2m duo capture --repo=/siterepo
repo_host 2
RENAMED_FILE=$(grep -l '"slot_code": "twilight"' "siterepo/${PAIR}2/state/tables/duo_agency_room_slots"/*.json)
[ -n "$RENAMED_FILE" ] || fail "the renamed slot was not captured"
[ "$(jq -r '.uuid' "$RENAMED_FILE")" = "$EVENING_UUID" ] \
  || fail "the renamed slot got a NEW uuid — the ledger did not provide rename continuity for a parent-scoped key"
"${GIT2[@]}" add -A
"${GIT2[@]}" commit -qm "rename: evening -> twilight"
git -C "siterepo/${PAIR}2" push -q origin main
git -C "siterepo/${PAIR}1" pull -q --ff-only origin main
PLAN=$(wp1m duo plan --repo=/siterepo --format=json | tail -1)
echo "$PLAN" | jq -e '(.update | any(.type == "duo_agency_room_slots"))' >/dev/null \
  || fail "the rename did not plan as an UPDATE on side 1 (plan: $PLAN)"
echo "$PLAN" | jq -e '((.create | length) == 0) and ((.delete | length) == 0)' >/dev/null \
  || fail "the rename planned as a create and/or delete instead of an update (plan: $PLAN)"
pass "a renamed component keeps its identity through the ledger, exactly like a single-column natural key"

say "(5) a second capture is byte-identical (determinism), and lint is clean"
wp2m duo capture --repo=/siterepo --out=/siterepo/.tmp-side2again >/dev/null
repo_host 2
diff -r "siterepo/${PAIR}2/state/tables" "siterepo/${PAIR}2/.tmp-side2again/tables" \
  || fail "a second capture of unchanged state produced different bytes"
LINT_RC=0
LINT_OUT=$(wp2m duo lint --repo=/siterepo 2>&1) || LINT_RC=$?
echo "$LINT_OUT"
[ "$LINT_RC" -eq 0 ] || fail "wp duo lint reported findings (exit $LINT_RC) — see output above"
pass "capture is deterministic and lint is clean"

say "(6) the WRONG declaration — the child key without its parent component — fails loudly"
# Not a style preference: with slot_code alone, both 'morning' rows derive one
# identity. This is the case the multi-column form exists for, so the engine
# must refuse rather than silently collapse two authored rows into one.
BAD_DIR="siterepo/${PAIR}1/.tmp-3318-bad"
mkdir -p "$BAD_DIR"
cp ../manifests/core.json "$BAD_DIR/core.json"
jq '.tables.duo_agency_room_slots.identity = {"mode": "natural_key", "column": "slot_code"}' \
  "siterepo/${PAIR}1/$OVERLAY/duo-agency-cpt.json" > "$BAD_DIR/duo-agency-cpt.json"
if OUT=$("${COMPOSE[@]}" run --rm -T -e "DUO_MANIFESTS_DIR=/siterepo/.tmp-3318-bad" cli1 \
    wp duo capture --repo=/siterepo --out=/siterepo/.tmp-bad-capture 2>&1); then
  echo "$OUT"
  fail "a single-column key over a parent-scoped table captured cleanly — two distinct authored slots silently collapsed into one identity"
fi
echo "$OUT" | tail -3
pass "the under-specified declaration is refused loudly instead of collapsing two rows into one identity"

GREEN=1
printf '\n\033[1;32m✔ REGRESS_PARENT_SCOPED_NATURAL_KEY PASSED\033[0m\n'
