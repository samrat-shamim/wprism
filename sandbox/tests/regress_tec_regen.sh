#!/usr/bin/env bash
# Regression — DUO-3234: derived tables with a HARD per-entity
# query-availability dependency (task #124, TEC's tec_occurrences shape).
# Live, docker-based (matches every other Apply.php-touching regression in
# this repo — regress_snapshot_meta.sh/regress_shipping_zones.sh/
# regress_collision.sh — none of which get a FakeWpdb offline harness, for
# the same reason: Apply::regen_dependencies() is entangled with Canary,
# the RepositoryCompiler, and a real transaction, none of which are
# meaningfully fakeable without reproducing most of Apply.php itself).
# sandbox/tests/regress_regen_dependency_policy.sh is the offline
# complement (Policy.php's own regen_dependency()/regenerators() wiring,
# in isolation, no docker).
#
# Proves, against the REAL The Events Calendar 6.17.2 and the SHIPPED
# manifests/the-events-calendar.json (not a synthetic declaration):
#   1. The original R3-B-documented break is fixed: applying a captured
#      tribe_events post to a fresh target used to leave it genuinely
#      invisible to `wp post list` (TEC's own WP_Query filter requires a
#      matching tec_occurrences row) until a manual regeneration step.
#      This script proves that step now runs automatically.
#   2. The hard-fail + marker-retry mechanics team-lead's design review
#      required (DUO-3234's Linear thread, point 5): a genuine
#      regen_dependency verification failure hard-fails `duo apply`
#      (nonzero exit), AND — the load-bearing correctness property — a
#      LATER apply with no further content changes still retries and
#      resolves it, rather than silently reporting all-clear (the
#      false-green retry the design review specifically flagged).
#
# Self-contained: own scratch pair (created and destroyed by this script).
# Temporarily edits the SHIPPED manifests/the-events-calendar.json's
# verify.column to force a deterministic failure for step 2 above, then
# restores it — a trap guarantees restoration even on a failed run, so this
# script never leaves the working tree's manifest in a modified state.
set -euo pipefail
cd "$(dirname "$0")/.."   # -> sandbox/

say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }

command -v jq >/dev/null || fail "jq required"

PAIR=asnaptec
PORT1=8936
PORT2=8937
export DUO_PAIR="$PAIR" DUO_PORT1="$PORT1" DUO_PORT2="$PORT2" DUO_CODEBIND_PLUGIN=""

wp1() { docker compose -p "duo-$PAIR" -f pair.yml run --rm -T cli1 wp "$@"; }
wp2() { docker compose -p "duo-$PAIR" -f pair.yml run --rm -T cli2 wp "$@"; }
GIT_1="git -C siterepo/${PAIR}1 -c user.name=duo-$PAIR -c user.email=$PAIR@example.test"

MANIFEST="../manifests/the-events-calendar.json"
MANIFEST_BACKUP=$(mktemp)
cp "$MANIFEST" "$MANIFEST_BACKUP"

cleanup() {
  cp "$MANIFEST_BACKUP" "$MANIFEST"
  rm -f "$MANIFEST_BACKUP"
  bash bin/pair.sh destroy "$PAIR" >/dev/null 2>&1 || true
}
trap cleanup EXIT

say "bring up scratch pair '$PAIR' ($PORT1/$PORT2)"
bash bin/pair.sh up "$PAIR" "$PORT1" "$PORT2"
pass "pair '$PAIR' ready"

say "install + activate The Events Calendar 6.17.2 on both sides"
wp1 plugin install the-events-calendar --version=6.17.2 --activate >/dev/null
wp2 plugin install the-events-calendar --version=6.17.2 --activate >/dev/null
wp1 plugin is-active the-events-calendar >/dev/null || fail "TEC not active on side 1"
wp2 plugin is-active the-events-calendar >/dev/null || fail "TEC not active on side 2"
pass "the-events-calendar active on both sides"

say "(1) seed one real event on side 1 via the real repository API (no venue/organizer — keeps this test's scope to tribe_events alone)"
read -r -d '' SEED_PHP <<'PHPEOF' || true
<?php
$event_id = tribe_events()->set_args([
    'title' => 'Regen Dependency Test Event',
    'status' => 'publish',
    'start_date' => '2026-09-05 17:00:00',
    'end_date' => '2026-09-05 20:00:00',
    'description' => 'Seeded for DUO-3234.',
])->create()->ID;
if (!$event_id) { fwrite(STDERR, "failed to create event\n"); exit(1); }
echo "event_id=$event_id\n";
global $wpdb;
$occ = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}tec_occurrences WHERE post_id = %d", $event_id
));
if ($occ !== 1) { fwrite(STDERR, "expected exactly 1 tec_occurrences row on side 1 immediately after create(), got $occ\n"); exit(1); }
echo "side 1 tec_occurrences row confirmed present at creation time (as TEC's own repository always does)\n";
PHPEOF
printf '%s' "$SEED_PHP" > "siterepo/${PAIR}1/.tmp-seed.php"
wp1 eval-file /siterepo/.tmp-seed.php
rm -f "siterepo/${PAIR}1/.tmp-seed.php"
EVENT_ID_1=$(wp1 post list --post_type=tribe_events --format=ids)
[ -n "$EVENT_ID_1" ] || fail "failed to read back the seeded event id on side 1"
pass "side 1 seeded: event_id=$EVENT_ID_1"

say "site repo: pin the SHIPPED the-events-calendar manifest (the actual deliverable)"
rm -rf "siterepo/origin-$PAIR.git" "siterepo/${PAIR}1/.git" "siterepo/${PAIR}2" "siterepo/${PAIR}1/state" "siterepo/${PAIR}1/site.duo.json"
git init --bare -b main "siterepo/origin-$PAIR.git" >/dev/null
cat > "siterepo/${PAIR}1/site.duo.json" <<'EOF'
{
  "manifests": ["core", "the-events-calendar"],
  "policy": {
    "options": {
      "default_category": {"class": "env"},
      "page_for_posts": {"class": "env"},
      "page_on_front": {"class": "env"},
      "sticky_posts": {"class": "env"},
      "wp_page_for_privacy_policy": {"class": "env"}
    },
    "post_meta": {},
    "post_types": ["post", "page", "attachment", "tribe_events"],
    "taxonomies": ["category", "post_tag"]
  },
  "spec_version": 0
}
EOF
printf '.tmp*\nstate.capture.lock\nstate.capture-staging/\nstate.capture-backup/\n' > "siterepo/${PAIR}1/.gitignore"
$GIT_1 init -q -b main
$GIT_1 remote add origin "../origin-$PAIR.git"
$GIT_1 add -A
$GIT_1 commit -qm "policy: core + the-events-calendar"
$GIT_1 push -qu origin main
pass "site repo initialized, pinning the shipped manifest"

say "(2) capture on side 1"
wp1 duo capture --repo=/siterepo
EVENT_FILE=$(ls "siterepo/${PAIR}1/state/posts/tribe_events/"*.json 2>/dev/null || ls "siterepo/${PAIR}1/state/posts/tribe_events/"*.md 2>/dev/null)
[ -n "$EVENT_FILE" ] || fail "expected a captured tribe_events post"
pass "captured cleanly: $EVENT_FILE"
# Portable front-matter extraction (no GNU-only `head -n -1`): print lines
# strictly BETWEEN the first and second `---` fence.
CAPTURED_UUID=$(awk '/^---$/{c++; next} c==1' "$EVENT_FILE" | jq -r '.uuid')
[ -n "$CAPTURED_UUID" ] && [ "$CAPTURED_UUID" != "null" ] || fail "failed to extract uuid from $EVENT_FILE's front matter"
$GIT_1 add -A
$GIT_1 commit -qm "capture: regen dependency test event"
$GIT_1 push -q origin main

say "(3) clone into side 2 (fresh target), apply — THE core proof"
git clone -q "siterepo/origin-$PAIR.git" "siterepo/${PAIR}2"
REV=$(git -C "siterepo/${PAIR}2" rev-parse HEAD)
APPLY1=$(wp2 duo apply --repo=/siterepo --default-author=admin --revision="$REV" --adopt-by-slug=posts,terms,menus,tables --format=json | tail -1)
echo "$APPLY1"
echo "$APPLY1" | jq -e '.canary == "clean"' >/dev/null || fail "expected a clean canary on the first apply (output: $APPLY1)"
pass "applied to side 2, canary clean"

EVENT_ID_2=$(wp2 post list --post_type=tribe_events --format=ids)
[ -n "$EVENT_ID_2" ] || fail "THE ORIGINAL R3-B BREAK: wp post list --post_type=tribe_events finds NOTHING on side 2 — the event is invisible to WP_Query, exactly the bug DUO-3234 exists to fix"
pass "THE CORE FIX PROVEN: wp post list --post_type=tribe_events finds the applied event on side 2 (event_id=$EVENT_ID_2) — NO manual regeneration step, unlike every prior grind round"

OCC_2=$(wp2 db query "SELECT COUNT(*) FROM wp_tec_occurrences WHERE post_id=$EVENT_ID_2" --skip-column-names 2>/dev/null | tr -d '\r')
[ "$OCC_2" = "1" ] || fail "expected exactly 1 tec_occurrences row on side 2 (got $OCC_2) — regen_dependencies() should have created it automatically"
pass "tec_occurrences row confirmed present on side 2, created automatically by Apply::regen_dependencies()"

say "(4) byte-identical recapture on side 2"
wp2 duo capture --repo=/siterepo
EVENT_FILE_2=$(ls "siterepo/${PAIR}2/state/posts/tribe_events/"*.json 2>/dev/null || ls "siterepo/${PAIR}2/state/posts/tribe_events/"*.md 2>/dev/null)
diff -u "$EVENT_FILE" "$EVENT_FILE_2" >/dev/null || fail "tribe_events file diverged between side 1 and recaptured side 2"
pass "byte-identical recapture"

say "(5) wp duo lint — hard gate, zero findings expected"
LINT_RC=0
LINT_OUT=$(wp2 duo lint --repo=/siterepo 2>&1) || LINT_RC=$?
echo "$LINT_OUT"
[ "$LINT_RC" -eq 0 ] || fail "wp duo lint found findings (exit $LINT_RC)"
pass "lint: zero findings"

say "(6) THE DESIGN-REVIEW PROOF: a genuine verification failure hard-fails apply, and a LATER apply with no further content changes still retries and resolves it"

say "(6a) small, real content change on side 1 (forces this event back into the 'update' plan bucket)"
wp1 post update "$EVENT_ID_1" --post_content='Updated for the DUO-3234 hard-fail/retry proof.' >/dev/null
wp1 duo capture --repo=/siterepo >/dev/null
$GIT_1 add -A
$GIT_1 commit -qm "capture: content tweak to force the update bucket"
$GIT_1 push -q origin main

say "(6b) temporarily point the SHIPPED manifest's verify at a nonexistent column (deterministic, reversible failure — restored by this script's own EXIT trap even on a failed run)"
jq '.post_types.tribe_events.regen_dependency.verify.column = "duo_regress_nonexistent_column"' "$MANIFEST" > "$MANIFEST.tmp" && mv "$MANIFEST.tmp" "$MANIFEST"
jq -e '.post_types.tribe_events.regen_dependency.verify.column == "duo_regress_nonexistent_column"' "$MANIFEST" >/dev/null || fail "failed to perturb the manifest for the failure test"

git -C "siterepo/${PAIR}2" pull -q origin main
REV2=$(git -C "siterepo/${PAIR}2" rev-parse HEAD)
say "(6c) apply — expect a HARD FAILURE (nonzero exit), not a warning, not a silent skip"
set +e
APPLY2=$(wp2 duo apply --repo=/siterepo --default-author=admin --revision="$REV2" --adopt-by-slug=posts,terms,menus,tables --format=json 2>&1)
APPLY2_RC=$?
set -e
echo "$APPLY2"
[ "$APPLY2_RC" -ne 0 ] || fail "expected duo apply to hard-fail on a genuine regen_dependency verification failure — it exited 0"
echo "$APPLY2" | grep -qi "duo_regress_nonexistent_column\|regen_dependency verification failed" \
  || fail "failure message doesn't name the verification failure (got: $APPLY2)"
pass "duo apply hard-failed as required (exit $APPLY2_RC), naming the verification failure"

MARKER=$(wp2 db query "SELECT v FROM wp_duo_kv WHERE k = 'regen_pending:$CAPTURED_UUID'" --skip-column-names 2>/dev/null | tr -d '\r')
[ "$MARKER" = "tribe_events" ] || fail "expected a regen_pending:<uuid> marker recording post_type=tribe_events, got '$MARKER'"
pass "regen_pending marker recorded in duo_kv (post_type=$MARKER) — this is what makes the next apply retry"

say "(6d) restore the manifest to its correct, shipped state"
cp "$MANIFEST_BACKUP" "$MANIFEST"
jq -e '.post_types.tribe_events.regen_dependency.verify.column == "post_id"' "$MANIFEST" >/dev/null || fail "manifest restoration did not produce the expected verify.column"
pass "manifest restored"

say "(6e) THE LOAD-BEARING PROOF: re-run apply with ZERO further content changes — this event is 'unchanged' by plan's own content-hash bucketing, and would be silently skipped without the marker mechanism (the exact false-green retry the design review flagged)"
PLAN3=$(wp2 duo plan --repo=/siterepo --format=json | tail -1)
echo "$PLAN3"
echo "$PLAN3" | jq -e --arg u "$CAPTURED_UUID" '.unchanged | any(.uuid == $u)' >/dev/null \
  || fail "expected the event to show as 'unchanged' in plan (proving the marker, not plan bucketing, is what triggers the retry) — plan: $PLAN3"
pass "confirmed: plan itself shows this entity as unchanged (exactly why the marker mechanism is necessary, not merely convenient)"

APPLY3=$(wp2 duo apply --repo=/siterepo --default-author=admin --revision="$REV2" --adopt-by-slug=posts,terms,menus,tables --format=json | tail -1)
echo "$APPLY3"
echo "$APPLY3" | jq -e '.canary == "clean"' >/dev/null || fail "expected the retry apply to succeed cleanly (output: $APPLY3)"
pass "retry apply succeeded — the previously-failed verification now resolves automatically, with no new content change and no manual intervention"

MARKER_AFTER=$(wp2 db query "SELECT COUNT(*) FROM wp_duo_kv WHERE k = 'regen_pending:$CAPTURED_UUID'" --skip-column-names 2>/dev/null | tr -d '\r')
[ "$MARKER_AFTER" = "0" ] || fail "expected the regen_pending marker to be cleared after a successful retry (still present)"
pass "marker cleared — the ledger no longer carries a stale retry target"

FINAL_OCC=$(wp2 db query "SELECT COUNT(*) FROM wp_tec_occurrences WHERE post_id=$EVENT_ID_2" --skip-column-names 2>/dev/null | tr -d '\r')
[ "$FINAL_OCC" = "1" ] || fail "expected exactly 1 tec_occurrences row after the retry (got $FINAL_OCC)"
FINAL_VISIBLE=$(wp2 post list --post_type=tribe_events --format=ids)
[ -n "$FINAL_VISIBLE" ] || fail "event should still be visible to wp post list after the whole failure/retry sequence"
pass "final state confirmed correct: tec_occurrences present, event visible to WP_Query"

say "all proofs green"
pass "DUO-3234 fully verified live: the original R3-B break is fixed automatically, and the design-review-required hard-fail + marker-retry mechanics both proven under a genuine verification failure, not just a no-op"
