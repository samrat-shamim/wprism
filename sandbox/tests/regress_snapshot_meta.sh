#!/usr/bin/env bash
# Regression — DUO-3204 (Snapshot::reconcile_meta() attached-meta ownership)
# + a slice of DUO-3214 (secret guard wired into typed-snapshot capture).
#
# DUO-3204: Snapshot.php's reconcile_meta() delete loop deleted EVERY live
# attached-meta key absent from the canonical file, with no classification
# check at all — including manifest-declared RUNTIME keys (manifests/
# ninja-forms.json's nf3_form_meta/nf3_field_meta/nf3_action_meta declare
# editActive/drawerDisabled/_seq_num {"class":"runtime"} — the admin JS
# builder's own ui-state scratch flags). A raw $wpdb->delete() fires no
# WordPress hooks, so this was silent and canary-invisible: every ordinary
# re-apply of an already-managed form quietly wiped those keys. The fix
# (agent/src/Repository/Snapshot.php, meta_key_rule()/reconcile_meta()) gates the
# delete loop on class==='authored', mirroring Apply::finalize_post()'s
# postmeta delete (Apply.php:806-811) and reusing capture_meta_rows()'s
# exact classification lookup so the two paths can never disagree.
#
# DUO-3214 (this file's slice only): Snapshot.php had zero secret scanning
# on typed-snapshot capture even though an attached-meta value is exactly as
# capable of holding a stray API key as post_meta is. guard_secret() (new)
# wires Secrets::hard_match_deep() into both the row-column and attached-
# meta capture paths, aborting loudly and naming table+column-or-key,
# mirroring Capture.php's own guard_secret() posture (spec/repo-format.md's
# "Secret guard at capture": hard match aborts, allow_secret escapes).
#
# A CRITICAL nuance this script deliberately makes explicit rather than
# papering over: Apply::apply()'s $work array (Apply.php:414-419) is built
# from create+adopt+update+forced-conflict ONLY — an 'unchanged'-hash entity
# never enters phase 2, so Snapshot::finalize_row()/reconcile_meta() are
# never even CALLED for it (the exact "unchanged skips reprocessing, by
# design" behavior grind_r1b_shop.sh's own self-heal test already
# demonstrates for term relationships). Re-applying the SAME, unmodified
# revision therefore proves nothing about reconcile_meta() by itself — the
# runtime keys would "survive" whether or not this bug was ever fixed,
# simply because the buggy code path never runs. This script keeps that
# no-op re-apply as a cheap baseline (proof (3) below) but treats proof (4)
# — an actual AUTHORED change, which forces the row into the 'update'
# bucket — as the real regression test, and explicitly asserts the plan
# bucket is "update" (not "unchanged") so a future edit can't silently
# regress this script back into testing nothing.
#
# Self-contained: uses sandbox/bin/pair.sh (task #74's redesign — see
# docs/sandbox.md), its own scratch pair (created and destroyed by this
# script, never touching r1a/r1b/r1c/conf/a/b or any legacy docker-
# compose.yml pair). Ports 8870/8871 (not the docs' example 8850/8851 —
# already occupied by another concurrent pair.sh pair at authoring time;
# any free pair of ports works identically).
set -euo pipefail
cd "$(dirname "$0")/.."   # -> sandbox/

say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }

command -v jq >/dev/null || fail "jq required"

PAIR="${SNAPSHOT_META_PAIR:-w1a}"
PORT1="${SNAPSHOT_META_PORT1:-8870}"
PORT2="${SNAPSHOT_META_PORT2:-8871}"
export DUO_PAIR="$PAIR" DUO_PORT1="$PORT1" DUO_PORT2="$PORT2" DUO_CODEBIND_PLUGIN=""

wp1() { docker compose -p "duo-$PAIR" -f pair.yml run --rm -T cli1 wp "$@"; }
wp2() { docker compose -p "duo-$PAIR" -f pair.yml run --rm -T cli2 wp "$@"; }
GIT_1="git -C siterepo/${PAIR}1 -c user.name=duo-$PAIR -c user.email=$PAIR@example.test"

cleanup() {
  bash bin/pair.sh destroy "$PAIR" >/dev/null 2>&1 || true
}
trap cleanup EXIT

say "bring up scratch pair '$PAIR' ($PORT1/$PORT2)"
bash bin/pair.sh up "$PAIR" "$PORT1" "$PORT2"
pass "pair '$PAIR' ready"

say "install + activate Ninja Forms on both sides"
wp1 plugin install ninja-forms --activate >/dev/null
wp2 plugin install ninja-forms --activate >/dev/null
pass "ninja-forms active on both sides"

# Ninja Forms auto-creates a "Contact Me" sample form on activation (both
# sides mint their OWN, independent row — nf3_forms is mapped-identity, no
# natural key exists for "the sample form", so the two can never be
# recognized as the same entity). Removing each side's own copy first keeps
# exactly one form in this whole test — mirrors sandbox/conformance/seeds/
# ninja-forms.sh's own REMOVE_CONTACT_ME_PHP cleanup idiom, generalized to
# run on both sides via the shared helper below.
say "remove each side's activation-created 'Contact Me' sample form"
read -r -d '' REMOVE_CONTACT_ME_PHP <<'PHPEOF' || true
<?php
global $wpdb;
$id = (int) $wpdb->get_var("SELECT id FROM {$wpdb->prefix}nf3_forms WHERE title = 'Contact Me'");
if ($id) {
    $fieldIds = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$wpdb->prefix}nf3_fields WHERE parent_id = %d", $id));
    $actionIds = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$wpdb->prefix}nf3_actions WHERE parent_id = %d", $id));
    foreach ($fieldIds as $fid) {
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nf3_field_meta WHERE parent_id = %d", $fid));
    }
    foreach ($actionIds as $aid) {
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nf3_action_meta WHERE parent_id = %d", $aid));
    }
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nf3_fields WHERE parent_id = %d", $id));
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nf3_actions WHERE parent_id = %d", $id));
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nf3_form_meta WHERE parent_id = %d", $id));
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nf3_upgrades WHERE id = %d", $id));
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nf3_forms WHERE id = %d", $id));
    echo "removed this environment's own activation-created 'Contact Me' form (id=$id)\n";
}
PHPEOF
for side in 1 2; do
  printf '%s' "$REMOVE_CONTACT_ME_PHP" > "siterepo/${PAIR}${side}/.tmp-remove-contact-me.php"
done
wp1 eval-file /siterepo/.tmp-remove-contact-me.php
wp2 eval-file /siterepo/.tmp-remove-contact-me.php
rm -f "siterepo/${PAIR}1/.tmp-remove-contact-me.php" "siterepo/${PAIR}2/.tmp-remove-contact-me.php"
pass "both sides start with zero Ninja Forms forms"

# Seed via direct SQL, not a template import: the bug under test lives in
# ATTACHED-META reconciliation (nf3_form_meta), which needs no nf3_fields/
# nf3_actions rows at all to exercise — a form with zero fields is still a
# perfectly valid, independently-capturable nf3_forms row (Snapshot.php
# imposes no cross-table completeness requirement). Schema DESCRIBE'd live
# against this fixture's own Ninja Forms 3.14.11 while authoring this script
# (matching manifests/ninja-forms.json's own "grounded empirically" evidence
# posture): nf3_forms' 14 columns and nf3_form_meta's id/parent_id/key/
# value/meta_key/meta_value 6 columns, exactly as declared.
say "(1) seed one Ninja Forms form on side 1 (nf3_forms + nf3_form_meta only)"
wp1 db query "INSERT INTO wp_nf3_forms (title, \`key\`, created_at, updated_at, views, subs, form_title, default_label_pos, show_title, clear_complete, hide_complete, logged_in, seq_num) VALUES ('Duo W1A Regress Form', NULL, NOW(), NOW(), 0, 0, 'Duo W1A Regress Form', 'above', 1, 1, 0, 0, 42)"
FORM_ID=$(wp1 db query "SELECT id FROM wp_nf3_forms WHERE title='Duo W1A Regress Form'" --skip-column-names 2>/dev/null | tr -d '\r')
[ -n "$FORM_ID" ] || fail "failed to seed the test form on side 1"
wp1 db query "INSERT INTO wp_nf3_form_meta (parent_id, \`key\`, value, meta_key, meta_value) VALUES ($FORM_ID, 'regress_marker', 'hello-world', 'regress_marker', 'hello-world')"
pass "seeded form id=$FORM_ID on side 1 with one authored meta key (regress_marker=hello-world)"

# Fresh site repo. Core post types/taxonomies are deliberately classified
# runtime — this test is scoped to the typed-snapshot tables mechanism only,
# and core.json's
# default_category (ref:term) / wp_page_for_privacy_policy (ref:post) are
# both non-zero on a stock WP install (a real "Uncategorized" term, a real
# auto-created Privacy Policy page). Site-policy reclassification excludes
# those five ref-typed core options as env state, while explicit runtime scope
# rules account for the stock post/page/category rows under DUO-3229's loud
# whole-surface gate. Together they say "duo doesn't manage this" without
# relying on the pre-gate silent scope shrinkage this fixture once assumed.
say "site repo: policy scoped to ninja-forms tables only"
rm -rf "siterepo/origin-$PAIR.git" "siterepo/${PAIR}1/.git" "siterepo/${PAIR}2" "siterepo/${PAIR}1/state" "siterepo/${PAIR}1/site.duo.json"
git init --bare -b main "siterepo/origin-$PAIR.git" >/dev/null
cat > "siterepo/${PAIR}1/site.duo.json" <<'EOF'
{
  "manifests": ["core", "ninja-forms"],
  "policy": {
    "options": {
      "default_category": {"class": "env", "required": false},
      "page_for_posts": {"class": "env", "required": false},
      "page_on_front": {"class": "env", "required": false},
      "sticky_posts": {"class": "env", "required": false},
      "wp_page_for_privacy_policy": {"class": "env", "required": false}
    },
    "post_meta": {},
    "term_meta": {},
    "post_types": [],
    "taxonomies": [],
    "scope": {
      "post_type": {
        "page": {"class": "runtime"},
        "post": {"class": "runtime"}
      },
      "taxonomy": {
        "category": {"class": "runtime"}
      }
    }
  },
  "spec_version": 2
}
EOF
cp site-repo.gitignore.template "siterepo/${PAIR}1/.gitignore"
$GIT_1 init -q -b main
$GIT_1 remote add origin "../origin-$PAIR.git"
$GIT_1 add -A
$GIT_1 commit -qm "policy: ninja-forms tables only"
$GIT_1 push -qu origin main
pass "site repo initialized"

say "capture on side 1"
wp1 duo capture --repo=/siterepo
FORM_FILE=$(ls "siterepo/${PAIR}1/state/tables/nf3_forms/"*.json)
[ -n "$FORM_FILE" ] || fail "expected a captured nf3_forms row"
jq -e '.meta == {"regress_marker":"hello-world"}' "$FORM_FILE" >/dev/null \
  || fail "captured meta should be exactly {regress_marker: hello-world} (runtime keys must never appear) — got: $(jq -c .meta "$FORM_FILE")"
pass "captured cleanly: authored columns present, meta holds ONLY the one authored key (no runtime keys ever appear in canonical state)"

$GIT_1 add -A
$GIT_1 commit -qm "capture: Duo W1A Regress Form"
$GIT_1 push -q origin main

say "(1 continued) clone into side 2, apply"
git clone -q "siterepo/origin-$PAIR.git" "siterepo/${PAIR}2"
REV=$(git -C "siterepo/${PAIR}2" rev-parse HEAD)
APPLY1=$(wp2 duo apply --repo=/siterepo --default-author=admin --revision="$REV" --format=json | tail -1)
echo "$APPLY1"
echo "$APPLY1" | jq -e '.canary == "clean"' >/dev/null || fail "expected a clean canary on the first apply"
FORM_ID_2=$(wp2 db query "SELECT id FROM wp_nf3_forms WHERE title='Duo W1A Regress Form'" --skip-column-names 2>/dev/null | tr -d '\r')
[ -n "$FORM_ID_2" ] || fail "form did not land on side 2"
MARKER=$(wp2 db query "SELECT meta_value FROM wp_nf3_form_meta WHERE parent_id=$FORM_ID_2 AND meta_key='regress_marker'" --skip-column-names 2>/dev/null | tr -d '\r')
[ "$MARKER" = "hello-world" ] || fail "authored marker did not land on side 2 (got '$MARKER')"
pass "form applied to side 2 (id=$FORM_ID_2), authored marker present, canary clean"

say "(2) directly inject/modify RUNTIME keys on side 2 via SQL — editActive=1, drawerDisabled=1, a FAKE _seq_num (these can never appear in captured state, so they must be created live, not via any duo command)"
wp2 db query "INSERT INTO wp_nf3_form_meta (parent_id, \`key\`, value, meta_key, meta_value) VALUES
  ($FORM_ID_2,'editActive','1','editActive','1'),
  ($FORM_ID_2,'drawerDisabled','1','drawerDisabled','1'),
  ($FORM_ID_2,'_seq_num','fake-seq-999','_seq_num','fake-seq-999')"
pass "runtime keys injected on side 2"

assert_runtime_keys_intact() { # assert_runtime_keys_intact <context label>
  local ctx="$1"
  local ea da sn
  ea=$(wp2 db query "SELECT meta_value FROM wp_nf3_form_meta WHERE parent_id=$FORM_ID_2 AND meta_key='editActive'" --skip-column-names 2>/dev/null | tr -d '\r')
  da=$(wp2 db query "SELECT meta_value FROM wp_nf3_form_meta WHERE parent_id=$FORM_ID_2 AND meta_key='drawerDisabled'" --skip-column-names 2>/dev/null | tr -d '\r')
  sn=$(wp2 db query "SELECT meta_value FROM wp_nf3_form_meta WHERE parent_id=$FORM_ID_2 AND meta_key='_seq_num'" --skip-column-names 2>/dev/null | tr -d '\r')
  [ "$ea" = "1" ] || fail "$ctx: editActive corrupted/deleted (got '$ea') — DUO-3204 regressed"
  [ "$da" = "1" ] || fail "$ctx: drawerDisabled corrupted/deleted (got '$da') — DUO-3204 regressed"
  [ "$sn" = "fake-seq-999" ] || fail "$ctx: _seq_num corrupted/deleted (got '$sn') — DUO-3204 regressed"
}

say "(3) re-apply the SAME revision — baseline only (see this file's header: an 'unchanged'-hash entity never reaches reconcile_meta() at all, so this proves no MORE than 'a no-op apply touches nothing', not the fix itself)"
APPLY2=$(wp2 duo apply --repo=/siterepo --default-author=admin --revision="$REV" --format=json | tail -1)
echo "$APPLY2"
echo "$APPLY2" | jq -e '.plan.unchanged >= 1 and .plan.create == 0 and .plan.update == 0 and .applied == 0' >/dev/null \
  || fail "expected this re-apply to be a true no-op with the form in the unchanged set (plan: $APPLY2) — if this now shows 'update', the hash-basis semantics changed and this test's own baseline assumption needs revisiting"
assert_runtime_keys_intact "after no-op re-apply"
pass "no-op re-apply touches nothing (plan confirms 'unchanged', not 'update') — runtime keys trivially intact, as expected"

say "(4) THE regression test: change ONE authored setting on side 1, recapture, reapply — this forces the row into the 'update' bucket, so reconcile_meta() genuinely runs"
wp1 db query "UPDATE wp_nf3_form_meta SET value='hello-world-v2', meta_value='hello-world-v2' WHERE parent_id=$FORM_ID AND meta_key='regress_marker'"
wp1 duo capture --repo=/siterepo >/dev/null
$GIT_1 add -A
$GIT_1 commit -qm "capture: bump regress_marker to v2"
$GIT_1 push -q origin main
git -C "siterepo/${PAIR}2" pull -q origin main
REV2=$(git -C "siterepo/${PAIR}2" rev-parse HEAD)
APPLY3=$(wp2 duo apply --repo=/siterepo --default-author=admin --revision="$REV2" --format=json | tail -1)
echo "$APPLY3"
echo "$APPLY3" | jq -e '.plan.update == 1' >/dev/null \
  || fail "expected this apply to land in the 'update' bucket (plan: $APPLY3) — otherwise reconcile_meta() never ran and this test proves nothing"
MARKER2=$(wp2 db query "SELECT meta_value FROM wp_nf3_form_meta WHERE parent_id=$FORM_ID_2 AND meta_key='regress_marker'" --skip-column-names 2>/dev/null | tr -d '\r')
[ "$MARKER2" = "hello-world-v2" ] || fail "authored change did not land (got '$MARKER2')"
assert_runtime_keys_intact "after a REAL reconcile_meta() pass (update bucket)"
pass "confirmed under a genuine reconcile_meta() invocation: authored change landed (regress_marker -> hello-world-v2) AND all three runtime keys survived byte-identical — DUO-3204 fixed"

say "(5) delete an authored key from the CANONICAL FILE directly (not by recapturing) — authored ownership must still delete it on apply"
jq 'del(.meta.regress_marker)' "$FORM_FILE" > "$FORM_FILE.tmp" && mv "$FORM_FILE.tmp" "$FORM_FILE"
$GIT_1 add -A
$GIT_1 commit -qm "policy: drop regress_marker from canonical state"
$GIT_1 push -q origin main
git -C "siterepo/${PAIR}2" pull -q origin main
REV3=$(git -C "siterepo/${PAIR}2" rev-parse HEAD)
APPLY4=$(wp2 duo apply --repo=/siterepo --default-author=admin --revision="$REV3" --format=json | tail -1)
echo "$APPLY4"
echo "$APPLY4" | jq -e '.plan.update == 1' >/dev/null || fail "expected this apply to land in the 'update' bucket (plan: $APPLY4)"
MARKER3=$(wp2 db query "SELECT COUNT(*) FROM wp_nf3_form_meta WHERE parent_id=$FORM_ID_2 AND meta_key='regress_marker'" --skip-column-names 2>/dev/null | tr -d '\r')
[ "$MARKER3" = "0" ] || fail "regress_marker should have been deleted (authored key absent from canonical) — got count=$MARKER3"
assert_runtime_keys_intact "after deleting an unrelated authored key"
pass "authored-key deletion still works (regress_marker removed) — ownership semantics correctly preserved alongside the runtime-key fix"

say "(6) secret guard: a hard-matching secret in an authored EAV value must ABORT capture, naming table+key (DUO-3214 slice)"
wp1 db query "INSERT INTO wp_nf3_form_meta (parent_id, \`key\`, value, meta_key, meta_value) VALUES ($FORM_ID,'regress_secret','sk_live_ABCDEFGHIJKLMNOPQRSTUV','regress_secret','sk_live_ABCDEFGHIJKLMNOPQRSTUV')"
set +e
SECOUT=$(wp1 duo capture --repo=/siterepo 2>&1)
SECRC=$?
set -e
echo "$SECOUT"
[ "$SECRC" -ne 0 ] || fail "expected duo capture to ABORT on a hard-matched secret in an authored EAV value"
grep -q "nf3_form_meta" <<<"$SECOUT" || fail "abort message doesn't name the table (got: $SECOUT)"
grep -q "regress_secret" <<<"$SECOUT" || fail "abort message doesn't name the key (got: $SECOUT)"
grep -qi "stripe key" <<<"$SECOUT" || fail "abort message doesn't name the matched pattern (got: $SECOUT)"
pass "capture aborted loudly, naming the table and the key"

say "(6b) same key, allow_secret:true via a site-policy table override — capture must proceed"
cat > "siterepo/${PAIR}1/site.duo.json" <<EOF
{
  "manifests": ["core", "ninja-forms"],
  "policy": {
    "options": {
      "default_category": {"class": "env", "required": false},
      "page_for_posts": {"class": "env", "required": false},
      "page_on_front": {"class": "env", "required": false},
      "sticky_posts": {"class": "env", "required": false},
      "wp_page_for_privacy_policy": {"class": "env", "required": false}
    },
    "post_meta": {},
    "term_meta": {},
    "post_types": [],
    "taxonomies": [],
    "scope": {
      "post_type": {
        "page": {"class": "runtime"},
        "post": {"class": "runtime"}
      },
      "taxonomy": {
        "category": {"class": "runtime"}
      }
    },
    "tables": {
      "nf3_form_meta": {
        "attached_to": {"column": "parent_id", "table": "nf3_forms"},
        "class": "authored_snapshot_meta",
        "default_class": "authored",
        "key_column": "meta_key",
        "keys": {
          "_seq_num": {"class": "runtime"},
          "drawerDisabled": {"class": "runtime"},
          "editActive": {"class": "runtime"},
          "regress_secret": {"class": "authored", "allow_secret": true}
        },
        "legacy_key_column": "key",
        "legacy_value_column": "value",
        "value_column": "meta_value"
      }
    }
  },
  "spec_version": 2
}
EOF
wp1 duo capture --repo=/siterepo
jq -e '.meta.regress_secret == "sk_live_ABCDEFGHIJKLMNOPQRSTUV"' "$FORM_FILE" >/dev/null \
  || fail "expected regress_secret to be captured once allow_secret:true is declared"
pass "allow_secret:true escape hatch works — capture proceeded and captured the (deliberately, audited) allowed value"

say "all proofs green"
pass "DUO-3204 (runtime-key ownership) and this file's DUO-3214 slice (typed-snapshot secret guard) both verified end-to-end"
