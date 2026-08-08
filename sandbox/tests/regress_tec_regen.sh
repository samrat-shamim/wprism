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
#   0. DUO-3301's R3-B render checker keeps its complete-response and
#      producer-safe aggregate assertion contract. This source-wiring
#      preflight prevents `echo "$LIST_HTML" | grep -q` from returning as
#      a soft false-negative under pipefail.
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
#   3. regen_pending:<uuid> (this file's own marker) is independently
#      load-bearing, not merely redundant with DUO-3206's unrelated
#      apply_in_progress marker (landed later, picked up via a rebase onto
#      main after this script was first written — see step (6e)'s comment
#      for the full interaction). Step (7) manufactures a scenario with a
#      hand-planted regen_pending:<uuid> marker and NO apply_in_progress at
#      all, proving regen_dependencies() still finds and resolves it purely
#      off this file's own kv_prefix() scan.
#   4. Design review's two REQUIRED follow-on conditions (both missed on
#      the first merge — see the issue's own comment history):
#        (a) PLAN VISIBILITY — `duo plan` (read-only) surfaces a live
#            regen_pending marker into plan.regen_pending and
#            plan.warnings (step 6e, and again in step 7's isolated
#            scenario where DUO-3206's own incomplete_apply signal is
#            deliberately absent — proving this file's own field, not
#            DUO-3206's, is what's doing the surfacing there).
#        (b) STATUS FAIL-CLOSED — `cli/duo status <env>` (cli/src/
#            PlanSummary.php, DUO-3221's decision-matrix) exits non-zero
#            while a regen_pending marker is outstanding and clean (exit
#            0) once it resolves — step (7c-status)/(7d-status), isolated
#            from every other ok=false condition so the proof is
#            unambiguous about which signal is doing the work.
#   5. ORPHAN SWEEP (design review's second, minor addition): a
#      regen_pending marker whose post type is no longer declared, or
#      whose uuid no longer resolves to a local post, gets actively
#      swept (Ledger::kv_delete()) with a loud warning naming what was
#      dropped and why — step (8), both shapes, live.
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

R3B_GRIND="tests/grind_r3b_events.sh"
grep -q '^assert_complete_html()' "$R3B_GRIND" || fail "R3-B grind lacks complete-HTML validation"
grep -q 'assert_complete_html "$LIST_HTML" "/events/list/ aggregate page"' "$R3B_GRIND" \
  || fail "R3-B aggregate view is not guarded by complete-HTML validation"
grep -q 'grep -qF "$t" <<<"$LIST_HTML" || fail' "$R3B_GRIND" \
  || fail "R3-B aggregate title assertion is not producer-safe and hard-failing"
R3B_CODE=$(grep -Ev '^[[:space:]]*#' "$R3B_GRIND")
if grep -Eq 'echo "\$[A-Za-z0-9_]*HTML"[[:space:]]*\|[[:space:]]*grep -q' <<<"$R3B_CODE"; then
  fail "R3-B grind still pipes buffered HTML through grep -q under pipefail"
fi
if grep -q 'LIST_MISSING\|aggregate view currently missing' "$R3B_GRIND"; then
  fail "R3-B aggregate assertion still has a soft-failure path"
fi

SYNTHETIC_HTML="<html><body>Fall Open House Community Meetup Annual Gala$(printf '%08000d' 0)</body></html>"
[ "${#SYNTHETIC_HTML}" -ge 4096 ] || fail "synthetic complete-response fixture is too short"
grep -qi '</html>' <<<"$SYNTHETIC_HTML" || fail "synthetic response lacks closing HTML"
for title in "Fall Open House" "Community Meetup" "Annual Gala"; do
  grep -qF "$title" <<<"$SYNTHETIC_HTML" || fail "producer-safe title check missed '$title'"
done
pass "DUO-3301 render-check contract: complete response, producer-safe title checks, hard aggregate failure"
# The owning issue can run its focused, docker-free contract independently
# from DUO-3234's older live regen scenarios below.
[ "${TEC_REGEN_PREFLIGHT_ONLY:-0}" = "1" ] && exit 0

PAIR="${TEC_REGEN_PAIR:-asnaptec}"
PORT1="${TEC_REGEN_PORT1:-8936}"
PORT2="${TEC_REGEN_PORT2:-8937}"
export DUO_PAIR="$PAIR" DUO_PORT1="$PORT1" DUO_PORT2="$PORT2" DUO_CODEBIND_PLUGIN=""

wp1() { docker compose -p "duo-$PAIR" -f pair.yml run --rm -T cli1 wp "$@"; }
wp2() { docker compose -p "duo-$PAIR" -f pair.yml run --rm -T cli2 wp "$@"; }
GIT_1="git -C siterepo/${PAIR}1 -c user.name=duo-$PAIR -c user.email=$PAIR@example.test"

MANIFEST="../manifests/the-events-calendar.json"
MANIFEST_BACKUP=$(mktemp)
cp "$MANIFEST" "$MANIFEST_BACKUP"

# cli/duo status <env> needs a repo-root .duo-envs.json naming this pair's
# side-2 (cli2) docker service — same pattern cli_status_truth.sh (DUO-3221)
# already established. Written once, used by step (7)'s isolated
# regen_pending-alone status proof below. Gitignored (.gitignore:5), and
# this script's own worktree is never shared with another agent's, so
# there's no cross-session collision risk in writing it at repo root.
DUO_CLI="$(pwd)/../cli/duo"
ENVS_FILE="$(pwd)/../.duo-envs.json"
ENV_NAME="${PAIR}2"

cleanup() {
  cp "$MANIFEST_BACKUP" "$MANIFEST"
  rm -f "$MANIFEST_BACKUP" "$ENVS_FILE"
  bash bin/pair.sh destroy "$PAIR" >/dev/null 2>&1 || true
}
trap cleanup EXIT

say "bring up scratch pair '$PAIR' ($PORT1/$PORT2)"
bash bin/pair.sh up "$PAIR" "$PORT1" "$PORT2"
pass "pair '$PAIR' ready"

cat > "$ENVS_FILE" <<EOF
{
  "envs": {
    "${ENV_NAME}": {"transport": "docker", "compose_file": "sandbox/pair.yml", "service": "cli2", "repo_path": "/siterepo"}
  }
}
EOF
pass ".duo-envs.json written for cli/duo status <$ENV_NAME>"

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
      "default_category": {"class": "env", "required": false},
      "page_for_posts": {"class": "env", "required": false},
      "page_on_front": {"class": "env", "required": false},
      "sticky_posts": {"class": "env", "required": false},
      "wp_page_for_privacy_policy": {"class": "env", "required": false}
    },
    "post_meta": {},
    "post_types": ["post", "page", "attachment", "tribe_events"],
    "taxonomies": ["category", "post_tag"]
  },
  "spec_version": 2
}
EOF
cp site-repo.gitignore.template "siterepo/${PAIR}1/.gitignore"
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
grep -qi "duo_regress_nonexistent_column\|regen_dependency verification failed" <<<"$APPLY2" \
  || fail "failure message doesn't name the verification failure (got: $APPLY2)"
pass "duo apply hard-failed as required (exit $APPLY2_RC), naming the verification failure"

MARKER=$(wp2 db query "SELECT v FROM wp_duo_kv WHERE k = 'regen_pending:$CAPTURED_UUID'" --skip-column-names 2>/dev/null | tr -d '\r')
[ "$MARKER" = "tribe_events" ] || fail "expected a regen_pending:<uuid> marker recording post_type=tribe_events, got '$MARKER'"
pass "regen_pending marker recorded in duo_kv (post_type=$MARKER) — this is what makes the next apply retry"

say "(6d) restore the manifest to its correct, shipped state"
cp "$MANIFEST_BACKUP" "$MANIFEST"
jq -e '.post_types.tribe_events.regen_dependency.verify.column == "post_id"' "$MANIFEST" >/dev/null || fail "manifest restoration did not produce the expected verify.column"
pass "manifest restored"

say "(6e) THE LOAD-BEARING PROOF: re-run apply with ZERO further content changes — the regen failure still gets retried and resolved automatically, not silently skipped (the exact false-green retry the design review flagged)"
PLAN3=$(wp2 duo plan --repo=/siterepo --format=json | tail -1)
echo "$PLAN3"
# NOTE on this assertion's history: this used to check the event shows as
# 'unchanged' in plan, to prove regen_pending:<uuid> alone (not plan's
# content-hash bucketing) is what forces the retry. DUO-3206 (landed after
# this test was first written, merged in via rebase) added its OWN
# whole-apply apply_in_progress marker — set before every apply's main
# transaction, cleared only after rebuild() AND a following ledger
# transaction both succeed. Since regen_dependencies() throwing prevents
# that ledger transaction from ever running, apply_in_progress now ALSO
# stays set on exactly this failure, and forces EVERY entity (not just
# this one) into the 'update' bucket with retry:true on the next plan —
# see Apply::build_plan()'s own incomplete_apply handling. Both markers
# are real and both get set by this failure; DUO-3206's coarser mechanism
# is simply what's now visible in plan's bucketing. Step (7) below proves
# regen_pending:<uuid> still does independent, load-bearing work with
# apply_in_progress absent entirely.
echo "$PLAN3" | jq -e --arg u "$CAPTURED_UUID" '.update | any(.uuid == $u and .retry == true)' >/dev/null \
  || fail "expected the event in plan's update bucket with retry:true (DUO-3206's apply_in_progress forcing a full retry after the regen failure) — plan: $PLAN3"
echo "$PLAN3" | jq -e '.incomplete_apply | length > 0' >/dev/null \
  || fail "expected plan.incomplete_apply to be non-empty (apply_in_progress marker still set from the failed run) — plan: $PLAN3"
pass "confirmed: plan shows the retry-forcing state (incomplete_apply + retry:true), driven by DUO-3206's apply_in_progress marker set by the same regen failure"

# DUO-3234 design review, addition 1: plan must ALSO surface the
# regen_pending marker itself here, in the natural "between a failed
# apply and its retry" moment — not just DUO-3206's coarser
# incomplete_apply signal. Step (7) below is the cleaner, isolated proof
# that regen_pending alone (no incomplete_apply at all) still surfaces and
# still flips `duo status`; this assertion instead proves both signals
# genuinely coexist at the point they'd actually occur together.
echo "$PLAN3" | jq -e --arg u "$CAPTURED_UUID" '.regen_pending | any(.uuid == $u and .post_type == "tribe_events")' >/dev/null \
  || fail "expected plan.regen_pending to name the event (uuid + post_type) here — plan: $PLAN3"
echo "$PLAN3" | jq -e --arg u "$CAPTURED_UUID" '.warnings | any(test($u) and test("regeneration"))' >/dev/null \
  || fail "expected plan.warnings to contain a regeneration-pending message naming the uuid — plan: $PLAN3"
pass "confirmed: plan.regen_pending and plan.warnings both name the event — an operator running plain 'duo plan' here sees the truth, not 'nothing to do'"

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

say "(7) ISOLATION PROOF: regen_pending:<uuid> resolves independently of DUO-3206's apply_in_progress — no failed apply, no forced full-tree retry, just this repo's own marker-consulting logic"
say "(7a) sanity: confirm this environment is fully clean before manufacturing the isolated scenario"
CLEAN_INCOMPLETE=$(wp2 db query "SELECT COUNT(*) FROM wp_duo_kv WHERE k = 'apply_in_progress'" --skip-column-names 2>/dev/null | tr -d '\r')
[ "$CLEAN_INCOMPLETE" = "0" ] || fail "expected apply_in_progress to be absent after (6e)'s successful retry (got count=$CLEAN_INCOMPLETE) — cannot isolate the marker's own behavior otherwise"
CLEAN_PLAN=$(wp2 duo plan --repo=/siterepo --format=json | tail -1)
echo "$CLEAN_PLAN" | jq -e --arg u "$CAPTURED_UUID" '.unchanged | any(.uuid == $u)' >/dev/null \
  || fail "expected the event back to plain 'unchanged' bucketing now that both markers are clear — plan: $CLEAN_PLAN"
pass "environment confirmed clean: no apply_in_progress, event is plain 'unchanged' — a true baseline for the isolation proof"

say "(7b) manufacture drift by hand: delete the live tec_occurrences row, then manually plant ONLY a regen_pending:<uuid> marker (no failed apply, no apply_in_progress)"
wp2 db query "DELETE FROM wp_tec_occurrences WHERE post_id=$EVENT_ID_2" >/dev/null
ORPHAN_OCC=$(wp2 db query "SELECT COUNT(*) FROM wp_tec_occurrences WHERE post_id=$EVENT_ID_2" --skip-column-names 2>/dev/null | tr -d '\r')
[ "$ORPHAN_OCC" = "0" ] || fail "expected the manual tec_occurrences delete to have taken effect (got count=$ORPHAN_OCC)"
wp2 db query "INSERT INTO wp_duo_kv (k, v) VALUES ('regen_pending:$CAPTURED_UUID', 'tribe_events') ON DUPLICATE KEY UPDATE v = VALUES(v)" >/dev/null
MARKER_PLANTED=$(wp2 db query "SELECT v FROM wp_duo_kv WHERE k = 'regen_pending:$CAPTURED_UUID'" --skip-column-names 2>/dev/null | tr -d '\r')
[ "$MARKER_PLANTED" = "tribe_events" ] || fail "expected the manually-planted marker to read back, got '$MARKER_PLANTED'"
pass "tec_occurrences row deleted; regen_pending:<uuid> marker planted by hand; apply_in_progress deliberately left untouched (still absent)"

say "(7c) plan still shows plain BUCKET 'unchanged' (DUO-3206's apply_in_progress plays no role here) — but, per design review addition 1, plan.regen_pending must still name the marker explicitly"
ISO_PLAN=$(wp2 duo plan --repo=/siterepo --format=json | tail -1)
echo "$ISO_PLAN"
echo "$ISO_PLAN" | jq -e --arg u "$CAPTURED_UUID" '.unchanged | any(.uuid == $u)' >/dev/null \
  || fail "expected the event to still show as plain 'unchanged' (no incomplete_apply, no retry:true — apply_in_progress was never set this time) — plan: $ISO_PLAN"
echo "$ISO_PLAN" | jq -e '.incomplete_apply | length == 0' >/dev/null \
  || fail "expected plan.incomplete_apply to be EMPTY this time (no apply_in_progress marker exists) — plan: $ISO_PLAN"
pass "confirmed: plan shows plain 'unchanged', zero incomplete_apply — DUO-3206's mechanism plays no role in this scenario"

echo "$ISO_PLAN" | jq -e --arg u "$CAPTURED_UUID" '.regen_pending | any(.uuid == $u and .post_type == "tribe_events")' >/dev/null \
  || fail "expected plan.regen_pending to name the hand-planted marker even with incomplete_apply empty — plan: $ISO_PLAN"
pass "confirmed: plan.regen_pending surfaces the marker on its own, with zero DUO-3206 involvement (incomplete_apply is empty, regen_pending is not)"

say "(7c-status) THE ISOLATED duo-status PROOF: regen_pending alone — no conflict, no collision, no code_mismatch, no incomplete_apply — must still flip 'duo status' to not-safe-to-promote"
set +e
STATUS_OUT=$("$DUO_CLI" status "$ENV_NAME" 2>&1)
STATUS_RC=$?
set -e
echo "$STATUS_OUT"
[ "$STATUS_RC" -ne 0 ] || fail "expected 'duo status $ENV_NAME' to exit non-zero while a regen_pending marker is outstanding — it exited 0"
grep -qi "regen_pending\|REGEN_PENDING" <<<"$STATUS_OUT" \
  || fail "duo status output doesn't mention regen_pending (output: $STATUS_OUT)"
pass "duo status correctly reports not-safe-to-promote (exit $STATUS_RC), naming regen_pending — isolated from every other ok=false condition"

say "(7d) apply anyway (no content changed, nothing forces a normal retry) — regen_pending:<uuid> alone must still trigger regen_dependencies() and repair the row"
ISO_APPLY=$(wp2 duo apply --repo=/siterepo --default-author=admin --revision="$REV2" --adopt-by-slug=posts,terms,menus,tables --format=json | tail -1)
echo "$ISO_APPLY"
echo "$ISO_APPLY" | jq -e '.canary == "clean"' >/dev/null || fail "expected the isolated marker-driven apply to succeed cleanly (output: $ISO_APPLY)"
pass "apply succeeded with zero plan-visible work, purely on the strength of the planted regen_pending:<uuid> marker"

ISO_OCC=$(wp2 db query "SELECT COUNT(*) FROM wp_tec_occurrences WHERE post_id=$EVENT_ID_2" --skip-column-names 2>/dev/null | tr -d '\r')
[ "$ISO_OCC" = "1" ] || fail "expected the manually-deleted tec_occurrences row to be regenerated (got count=$ISO_OCC)"
ISO_MARKER_AFTER=$(wp2 db query "SELECT COUNT(*) FROM wp_duo_kv WHERE k = 'regen_pending:$CAPTURED_UUID'" --skip-column-names 2>/dev/null | tr -d '\r')
[ "$ISO_MARKER_AFTER" = "0" ] || fail "expected the manually-planted marker to be cleared after the isolated repair (still present)"
pass "ISOLATION PROOF confirmed: tec_occurrences row regenerated and marker cleared with NO apply_in_progress involvement whatsoever — regen_pending:<uuid> is independently load-bearing, not merely redundant with DUO-3206"

say "(7d-status) duo status returns to clean now that the marker resolved"
STATUS_CLEAN_RC=0
STATUS_CLEAN_OUT=$("$DUO_CLI" status "$ENV_NAME" 2>&1) || STATUS_CLEAN_RC=$?
echo "$STATUS_CLEAN_OUT"
[ "$STATUS_CLEAN_RC" -eq 0 ] || fail "expected 'duo status $ENV_NAME' to exit 0 now that regen_pending has cleared (exit $STATUS_CLEAN_RC)"
pass "duo status confirms clean (exit 0) — the before/after status proof is complete"

say "(8) ORPHAN-SWEEP PROOF (design review addition 2): a regen_pending marker that can never resolve again must not sit in duo_kv forever — both orphan shapes get swept, loudly, in the same pass that would have processed them"

say "(8a) orphan shape 1: manifest no longer declares a regen_dependency for the post type"
jq 'del(.post_types.tribe_events.regen_dependency)' "$MANIFEST" > "$MANIFEST.tmp" && mv "$MANIFEST.tmp" "$MANIFEST"
jq -e '.post_types.tribe_events.regen_dependency == null' "$MANIFEST" >/dev/null || fail "failed to strip regen_dependency from the manifest for the orphan-sweep test"
wp2 db query "INSERT INTO wp_duo_kv (k, v) VALUES ('regen_pending:$CAPTURED_UUID', 'tribe_events') ON DUPLICATE KEY UPDATE v = VALUES(v)" >/dev/null
ORPHAN1_PLANTED=$(wp2 db query "SELECT v FROM wp_duo_kv WHERE k = 'regen_pending:$CAPTURED_UUID'" --skip-column-names 2>/dev/null | tr -d '\r')
[ "$ORPHAN1_PLANTED" = "tribe_events" ] || fail "expected the orphan-1 marker to be planted, got '$ORPHAN1_PLANTED'"
pass "regen_dependency declaration removed from the manifest; regen_pending:<uuid> marker (re-)planted by hand"

ORPHAN1_APPLY=$(wp2 duo apply --repo=/siterepo --default-author=admin --revision="$REV2" --adopt-by-slug=posts,terms,menus,tables --format=json | tail -1)
echo "$ORPHAN1_APPLY"
echo "$ORPHAN1_APPLY" | jq -e '.canary == "clean"' >/dev/null || fail "expected apply to succeed cleanly while sweeping an orphaned marker (output: $ORPHAN1_APPLY)"
echo "$ORPHAN1_APPLY" | jq -e --arg u "$CAPTURED_UUID" '.warnings | any(test($u) and test("dropped") and test("no longer declares"))' >/dev/null \
  || fail "expected apply's warnings to name the dropped marker and the 'no longer declares' reason — output: $ORPHAN1_APPLY"
ORPHAN1_AFTER=$(wp2 db query "SELECT COUNT(*) FROM wp_duo_kv WHERE k = 'regen_pending:$CAPTURED_UUID'" --skip-column-names 2>/dev/null | tr -d '\r')
[ "$ORPHAN1_AFTER" = "0" ] || fail "expected the orphaned marker to be swept (deleted) — still present"
pass "orphan shape 1 swept: apply succeeded, warned by name naming why, marker gone from duo_kv"

say "(8a-restore) restore the manifest's regen_dependency declaration"
cp "$MANIFEST_BACKUP" "$MANIFEST"
jq -e '.post_types.tribe_events.regen_dependency.verify.column == "post_id"' "$MANIFEST" >/dev/null || fail "manifest restoration after orphan-1 test did not produce the expected shape"
pass "manifest restored"

say "(8b) orphan shape 2: the marker's uuid no longer resolves to a local post id (a made-up uuid, never applied)"
FAKE_UUID="00000000-0000-7000-8000-000000000000"
wp2 db query "INSERT INTO wp_duo_kv (k, v) VALUES ('regen_pending:$FAKE_UUID', 'tribe_events') ON DUPLICATE KEY UPDATE v = VALUES(v)" >/dev/null
ORPHAN2_PLANTED=$(wp2 db query "SELECT v FROM wp_duo_kv WHERE k = 'regen_pending:$FAKE_UUID'" --skip-column-names 2>/dev/null | tr -d '\r')
[ "$ORPHAN2_PLANTED" = "tribe_events" ] || fail "expected the orphan-2 marker to be planted, got '$ORPHAN2_PLANTED'"
pass "regen_pending marker planted for a uuid that was never applied ($FAKE_UUID)"

ORPHAN2_APPLY=$(wp2 duo apply --repo=/siterepo --default-author=admin --revision="$REV2" --adopt-by-slug=posts,terms,menus,tables --format=json | tail -1)
echo "$ORPHAN2_APPLY"
echo "$ORPHAN2_APPLY" | jq -e '.canary == "clean"' >/dev/null || fail "expected apply to succeed cleanly while sweeping the second orphaned marker (output: $ORPHAN2_APPLY)"
echo "$ORPHAN2_APPLY" | jq -e --arg u "$FAKE_UUID" '.warnings | any(test($u) and test("dropped") and test("no longer resolves"))' >/dev/null \
  || fail "expected apply's warnings to name the dropped marker and the 'no longer resolves' reason — output: $ORPHAN2_APPLY"
ORPHAN2_AFTER=$(wp2 db query "SELECT COUNT(*) FROM wp_duo_kv WHERE k = 'regen_pending:$FAKE_UUID'" --skip-column-names 2>/dev/null | tr -d '\r')
[ "$ORPHAN2_AFTER" = "0" ] || fail "expected the second orphaned marker to be swept (deleted) — still present"
pass "orphan shape 2 swept: apply succeeded, warned by name naming why, marker gone from duo_kv"

say "all proofs green"
pass "DUO-3234 fully verified live: the original R3-B break is fixed automatically, the design-review-required hard-fail + marker-retry mechanics are proven under a genuine verification failure, plan/status surface a pending marker truthfully, and both orphan-marker shapes get swept loudly rather than lingering forever"
