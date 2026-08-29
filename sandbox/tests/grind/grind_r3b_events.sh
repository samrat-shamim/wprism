#!/usr/bin/env bash
# Grind round R3-B (task #91) — an events + memberships site: The Events
# Calendar (free, wp.org) + Paid Memberships Pro (free, but no longer on
# wp.org — installed from the author's own GitHub release tag, see below)
# stress-testing the
# brand-new typed-snapshot custom-table grammar (agent/src/Repository/Snapshot.php,
# task #75) against schemas it was NOT designed around. Three distinct
# engine gaps were found this round: (1) TEC's tec_events/tec_occurrences
# are derived with a HARD per-entity query-availability dependency, not a
# soft cache — the manifest can mark them 'derived' but the grammar has no
# primitive for 'apply must regenerate this or the entity is unusable'
# (CLOSED by issue #3234: post_types.<type>.regen_dependency is exactly that
# primitive now, declared for tribe_events in manifests/the-events-
# calendar.json — see "issue #3234's regen_dependency contract, proven live"
# below, which now proves the automatic fix rather than the manual-step
# gap this round originally found); (2)
# PMPro's real content-restriction table, pmpro_memberships_pages, has a
# COMPOSITE primary key (no surrogate id column), a gap closed by issue #3235's
# identity.mode=composite_ref grammar; (3) pmpro_membership_levelmeta's own
# PK column is named meta_id, not id, also closed by issue #3235's id_column
# override. See the two post-apply proofs below: both authored facts now
# capture and converge without manual repair.
#
# Own dedicated sandbox/bin/pair.sh pair (defaults: r3b1 :8852 / r3b2
# :8853, journal on). Pair name and ports are injectable for isolated
# verification. Own site repo under sandbox/siterepo/.
#
# Re-run safety: r3b1/r3b2 are never torn down via `pair.sh destroy`/
# `docker compose down` (off-limits — other agents share the shared db and
# network). `pair.sh stop r3b` between runs IS fine and expected (frees
# RAM/CPU immediately; containers/volumes/databases all kept, so the
# TEC+PMPro install + seeded content survive at zero footprint — issue #3256/
# issue #3258's own hygiene note, generalizing the original "never torn down"
# convention: stopped-not-destroyed is what that convention actually
# requires now that host pair-budget discipline matters). Every run wipes
# WP content, the wprism ledger tables, the
# tec_*/pmpro_* custom tables, the PMPro system-page options, and the
# site-repo git state from scratch — mirroring grind_r1b_shop.sh's own
# reset_env_state() approach exactly (NOT sandbox/bin/pair.sh's own `reset`
# subcommand, which drops/recreates the databases and would force a full
# plugin reinstall — including re-fetching PMPro from GitHub — on every
# run). `pair.sh up` (idempotent) is still used for the one-time bring-up/
# WordPress-core-install path.
set -euo pipefail
cd "$(dirname "$0")/../../.."   # sandbox/tests/grind/grind_r3b_events.sh -> repo root
SANDBOX="$(pwd)/sandbox"
cd "$SANDBOX"

say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }

assert_complete_html() {
  local body="$1" label="$2" bytes
  bytes=${#body}
  [ "$bytes" -ge 4096 ] || fail "$label returned an implausibly short HTML response ($bytes bytes)"
  grep -qi '</html>' <<<"$body" || fail "$label returned incomplete HTML (no closing </html>; $bytes bytes)"
}

PAIR="${R3B_PAIR:-r3b}"
PORT1="${R3B_PORT1:-8852}"
PORT2="${R3B_PORT2:-8853}"
[[ "$PAIR" =~ ^[a-z0-9][a-z0-9_-]*$ ]] || fail "invalid pair name '$PAIR'"
export WPRISM_PAIR="$PAIR"
PAIR_COMPOSE=(docker compose -p "wprism-$PAIR" -f pair.yml -f pair.journal.yml)
R3B1="http://localhost:$PORT1"
R3B2="http://localhost:$PORT2"
HOST1="siterepo/${PAIR}1"
HOST2="siterepo/${PAIR}2"
ORIGIN="siterepo/origin-${PAIR}.git"

wp1() { "${PAIR_COMPOSE[@]}" run --rm -T cli1 wp "$@"; }
wp2() { "${PAIR_COMPOSE[@]}" run --rm -T cli2 wp "$@"; }
GIT_1="git -C $HOST1 -c user.name=wprism-$PAIR-1 -c user.email=$PAIR-1@example.test"
GIT_2="git -C $HOST2 -c user.name=wprism-$PAIR-2 -c user.email=$PAIR-2@example.test"

say "boot pair $PAIR (${PAIR}1 :$PORT1 / ${PAIR}2 :$PORT2), idempotent"
bash bin/pair.sh up "$PAIR" "$PORT1" "$PORT2" --http --journal
pass "pair up (shared db, own containers, WordPress core installed on both sides if not already)"

install_plugins() { # install_plugins <cli-fn>
  local cli="$1"
  "$cli" plugin is-active the-events-calendar >/dev/null 2>&1 || "$cli" plugin install the-events-calendar --activate
  "$cli" plugin is-active the-events-calendar >/dev/null 2>&1 || "$cli" plugin activate the-events-calendar
  # Paid Memberships Pro was permanently removed from wp.org 2024-10-17
  # (author's own request) — `wp plugin install paid-memberships-pro`
  # fails with "closed". Still genuinely free/GPLv2; installed from the
  # official GitHub release tag. wp-cli auto-detects the GitHub source and
  # renames the extracted folder to the clean 'paid-memberships-pro' slug.
  "$cli" plugin is-active paid-memberships-pro >/dev/null 2>&1 || \
    "$cli" plugin install https://github.com/strangerstudios/paid-memberships-pro/archive/refs/tags/3.8.3.zip --activate
  "$cli" plugin is-active paid-memberships-pro >/dev/null 2>&1 || "$cli" plugin activate paid-memberships-pro
}
say "install + activate The Events Calendar + Paid Memberships Pro (both sides, idempotent)"
install_plugins wp1
install_plugins wp2
pass "TEC + PMPro active on both sides"

reset_env_state() { # reset_env_state <cli-fn>
  local cli="$1"
  "$cli" site empty --yes >/dev/null
  # wp_delete_post() can leave the active theme's custom_css_post_id at
  # WordPress's -1 sentinel when site-empty removes an old custom_css post.
  # That is reset residue, not authored fixture state; if retained, the
  # ref-typed theme-mod key correctly warns about an unmanaged post id on a
  # later capture. Keep both sides' clean slate genuinely warning-free.
  "$cli" theme mod remove custom_css_post_id >/dev/null 2>&1 || true
  "$cli" db query "TRUNCATE TABLE wp_tec_events" >/dev/null 2>&1 || true
  "$cli" db query "TRUNCATE TABLE wp_tec_occurrences" >/dev/null 2>&1 || true
  "$cli" db query "TRUNCATE TABLE wp_tec_kv_cache" >/dev/null 2>&1 || true
  "$cli" db query "TRUNCATE TABLE wp_pmpro_membership_levels" >/dev/null 2>&1 || true
  "$cli" db query "TRUNCATE TABLE wp_pmpro_membership_levelmeta" >/dev/null 2>&1 || true
  "$cli" db query "TRUNCATE TABLE wp_pmpro_memberships_pages" >/dev/null 2>&1 || true
  "$cli" db query "TRUNCATE TABLE wp_pmpro_memberships_categories" >/dev/null 2>&1 || true
  "$cli" db query "TRUNCATE TABLE wp_pmpro_memberships_users" >/dev/null 2>&1 || true
  # PMPro's own pmpro_generatePages() skips recreating a page whose
  # pmpro_<name>_page_id option still holds a (now site-emptied, deleted)
  # id — confirmed empirically while authoring this script. Must clear
  # these explicitly on every reset or the seed step below silently fails
  # to recreate the system pages on a second run.
  for p in account billing cancel checkout confirmation invoice levels login member_profile_edit; do
    "$cli" option delete "pmpro_${p}_page_id" >/dev/null 2>&1 || true
  done
  # site empty --yes deliberately does NOT touch users (its own --help says
  # so explicitly) — the seed step's wp_insert_user('dana.rivera', ...)
  # fails with "username already exists" on any second run otherwise
  # (confirmed empirically: silently degrades pmpro_changeMembershipLevel()
  # into being called with a WP_Error in place of a user id, no PHP
  # fatal, no script failure — just a quietly-missing membership row).
  "$cli" user delete dana.rivera --yes --reassign=1 >/dev/null 2>&1 || true
  "$cli" db query "TRUNCATE TABLE wp_wprism_map" >/dev/null 2>&1 || true
  "$cli" db query "TRUNCATE TABLE wp_wprism_state" >/dev/null 2>&1 || true
  "$cli" db query "TRUNCATE TABLE wp_wprism_kv" >/dev/null 2>&1 || true
  "$cli" db query "TRUNCATE TABLE wp_wprism_journal" >/dev/null 2>&1 || true
}
say "reset content/ledger/custom-tables on both sides (plugins stay installed+active)"
reset_env_state wp1
reset_env_state wp2
pass "both envs content-clean; TEC + PMPro remain active"

say "fresh site repo (own origin, own clones)"
rm -rf "$ORIGIN" "$HOST1/.git" "$HOST1/state" "$HOST1/site.wprism.json"
find "$HOST2" -mindepth 1 -maxdepth 1 -exec rm -rf -- {} +
git init --bare -b main "$ORIGIN" >/dev/null
mkdir -p "$HOST1" "$HOST2"
cat > "$HOST1/site.wprism.json" <<'EOF'
{
  "manifests": ["core", "the-events-calendar", "paid-memberships-pro"],
  "policy": {
    "options": {},
    "post_meta": {},
    "post_types": ["post", "page", "attachment", "tribe_events", "tribe_venue", "tribe_organizer"],
    "taxonomies": ["category", "post_tag"]
  },
  "spec_version": 2
}
EOF
cp site-repo.gitignore.template "$HOST1/.gitignore"
$GIT_1 init -q -b main
$GIT_1 remote add origin "../origin-${PAIR}.git"
$GIT_1 add -A
$GIT_1 commit -qm "policy: events + memberships site, both new manifests pinned from the start"
$GIT_1 push -qu origin main
pass "site repo initialized, both graduated manifests pinned (the interactive classify-loop discovery that produced them happened once, recorded in the report; a fresh site never has to repeat it)"

say "seed real content on ${PAIR}1: venue+organizer, 3 events, PMPro system pages, 2 membership levels, one restricted page, a real member signup"
cat > "$HOST1/.tmp-seed.php" <<'PHPEOF'
<?php
$venue_id = tribe_venues()->set_args([
    'venue' => 'Riverside Commons Workshop Hall', 'address' => '100 River Street',
    'city' => 'Springfield', 'state' => 'IL', 'zip' => '62701', 'country' => 'USA', 'phone' => '555-0100',
])->create()->ID;
$organizer_id = tribe_organizers()->set_args([
    'organizer' => 'Riverside Commons Events Team', 'email' => 'events@riversidecommons.test', 'phone' => '555-0101',
])->create()->ID;

$event1_id = tribe_events()->set_args([
    'title' => 'Fall Open House', 'status' => 'publish',
    'start_date' => '2026-09-05 17:00:00', 'end_date' => '2026-09-05 20:00:00',
    'venue' => $venue_id, 'organizer' => $organizer_id,
])->create()->ID;
$event2_id = tribe_events()->set_args([
    'title' => 'Community Meetup', 'status' => 'publish',
    'start_date' => '2026-09-19 18:30:00', 'end_date' => '2026-09-19 20:00:00',
])->create()->ID;
$event3_id = tribe_events()->set_args([
    'title' => 'Annual Gala', 'status' => 'publish',
    'start_date' => '2026-10-10 18:00:00', 'end_date' => '2026-10-10 22:00:00',
    'venue' => $venue_id,
])->create()->ID;

$created = pmpro_generatePages([
    'account' => 'Membership Account', 'billing' => 'Membership Billing', 'cancel' => 'Membership Cancel',
    'checkout' => 'Membership Checkout', 'confirmation' => 'Membership Confirmation', 'invoice' => 'Membership Orders',
    'levels' => 'Membership Levels', 'login' => 'Log In', 'member_profile_edit' => 'Your Profile',
]);

$studio_page_id = wp_insert_post([
    'post_title' => 'Studio Members Only', 'post_status' => 'publish', 'post_type' => 'page',
    'post_content' => "<!-- wp:paragraph -->\n<p>Studio tool access, storage lockers, and the members-only workshop calendar. This page is visible only to Studio Access members.</p>\n<!-- /wp:paragraph -->",
]);

global $wpdb;
$event2_url = get_permalink($event2_id);
$wpdb->insert($wpdb->pmpro_membership_levels, [
    'name' => 'Community', 'description' => 'Community membership: newsletter, member pricing on workshops, and voting rights at the annual meeting.',
    'confirmation' => 'Welcome to Riverside Commons! Your Community membership is now active. Come say hello at our next <a href="' . esc_url($event2_url) . '">Community Meetup</a> — we would love to meet you.',
    'initial_payment' => 9.99, 'billing_amount' => 9.99, 'cycle_number' => 1, 'cycle_period' => 'Month',
    'billing_limit' => 0, 'trial_amount' => 0, 'trial_limit' => 0, 'allow_signups' => 1,
    'expiration_number' => 0, 'expiration_period' => '',
], ['%s','%s','%s','%f','%f','%d','%s','%d','%f','%d','%d','%d','%s']);
$level1_id = (int) $wpdb->insert_id;
update_pmpro_membership_level_meta($level1_id, 'confirmation_in_email', 1);
update_pmpro_membership_level_meta($level1_id, 'membership_account_message', 'Thanks for being a Community member!');

$wpdb->insert($wpdb->pmpro_membership_levels, [
    'name' => 'Studio Access', 'description' => 'Full studio access: 24/7 tool use, personal storage locker, and priority workshop registration.',
    'confirmation' => 'Your Studio Access membership is confirmed. Visit the <a href="' . esc_url(get_permalink($studio_page_id)) . '">Studio Members Only</a> page for access hours and locker assignment.',
    'initial_payment' => 199.00, 'billing_amount' => 0, 'cycle_number' => 0, 'cycle_period' => '',
    'billing_limit' => 0, 'trial_amount' => 0, 'trial_limit' => 0, 'allow_signups' => 1,
    'expiration_number' => 1, 'expiration_period' => 'Year',
], ['%s','%s','%s','%f','%f','%d','%s','%d','%f','%d','%d','%d','%s']);
$level2_id = (int) $wpdb->insert_id;
update_pmpro_membership_level_meta($level2_id, 'confirmation_in_email', 1);
update_pmpro_membership_level_meta($level2_id, 'membership_account_message', 'Your locker assignment will be emailed within 2 business days.');

pmpro_update_post_level_restrictions($studio_page_id, [$level2_id]);

$user_id = wp_insert_user([
    'user_login' => 'dana.rivera', 'user_pass' => 'not-a-real-password-123', 'user_email' => 'dana.rivera@example.test',
    'first_name' => 'Dana', 'last_name' => 'Rivera', 'role' => 'subscriber',
]);
if (is_wp_error($user_id)) {
    fwrite(STDERR, "SEED_FAILED creating dana.rivera: " . $user_id->get_error_message() . "\n");
    exit(1);
}
pmpro_changeMembershipLevel($level2_id, $user_id);

echo "SEED_COMPLETE " . json_encode([
    'venue_id' => $venue_id, 'organizer_id' => $organizer_id,
    'event1_id' => $event1_id, 'event2_id' => $event2_id, 'event3_id' => $event3_id,
    'studio_page_id' => $studio_page_id, 'level1_id' => $level1_id, 'level2_id' => $level2_id, 'user_id' => $user_id,
]) . "\n";
PHPEOF
SEED_OUT=$(wp1 eval-file /siterepo/.tmp-seed.php)
echo "$SEED_OUT"
grep -q SEED_COMPLETE <<<"$SEED_OUT" || fail "seed script did not complete"
pass "r3b1 seeded: venue, organizer, 3 events (one venue+organizer, one bare, one venue-only), PMPro's 9 system pages, 2 membership levels with real pricing + confirmation text containing an internal link, Studio Members Only page restricted to Studio Access, a real member signup (dana.rivera)"

say "core loop: capture (both graduated manifests already pinned — capture should succeed immediately)"
wp1 wprism capture --repo=/siterepo
pass "capture succeeded with zero unclassified-meta gate firing — manifests/the-events-calendar.json + manifests/paid-memberships-pro.json fully cover this site's real content"

say "deliberately exercise task #73's unscoped-ref gate: retype the checkout page's own row out of scope, un-mint its identity"
# issue #3256: this used to remove "page" from policy.post_types wholesale,
# which also un-scopes the OTHER ~9 real page-type entities already
# captured on disk (PMPro's system pages + Studio Members Only) — and
# Capture::run() compiles+authorizes the EXISTING repo/state tree against
# the CURRENT policy before build() ever runs ("refuses to build new state
# on top of an already-invalid repository revision"), so that broader
# RepositoryAuthorization gate now fires on all 10 pages before task #73's
# own narrower per-option gate is ever reached. Capture::ref_target_type()
# keys off the LIVE wp_posts.post_type column for the referenced id, not
# whole-type policy scope — so retyping ONLY the checkout page's own row
# triggers the intended narrow gate without touching policy.post_types at
# all, leaving the other 9 pages validly in scope throughout.
#
# A second collateral dependency, found live (not guessed): PMPro's own
# pmpro_generatePages() nests "Membership Confirmation" under "Membership
# Checkout" via post_parent — a structural relationship independent of any
# option, resolved for EVERY captured post before task #73's own gate ever
# runs. Retyping checkout alone left confirmation's parent unresolvable,
# tripping a different, more fundamental "unmanaged parent post" error
# first. Temporarily re-parenting any such child to top-level (and
# restoring it afterward) neutralizes this without assuming confirmation
# is the only child — any post PMPro (or a future seed change) nests under
# checkout gets the same treatment.
#
# Trap, not just a happy-path restore (team-lead's own requirement): r3b
# persists by this file's own "never torn down" convention — every other
# agent's run reuses whatever this environment is left in. A fail()
# between the retype above and the restore below calls `exit 1` directly
# (bypassing the rest of this script, restore included), which would leave
# the checkout page permanently mistyped and orphan-parented for every
# future run on this shared pair, not just this one. The EXIT trap fires
# on ANY exit path — fail()'s explicit exit, an unexpected command failure
# under set -e, or a signal — restoring both the type and every child's
# parent before the shell actually terminates. Disarmed (not left to
# double-fire) once the normal path reaches its own explicit restore below.
CHECKOUT_ID=$(wp1 option get pmpro_checkout_page_id)
CHECKOUT_CHILDREN=$(wp1 db query "SELECT ID FROM wp_posts WHERE post_parent=$CHECKOUT_ID" --skip-column-names)
restore_checkout() {
  wp1 db query "UPDATE wp_posts SET post_type='page' WHERE ID=$CHECKOUT_ID" >/dev/null 2>&1 || true
  for cid in $CHECKOUT_CHILDREN; do
    wp1 db query "UPDATE wp_posts SET post_parent=$CHECKOUT_ID WHERE ID=$cid" >/dev/null 2>&1 || true
  done
}
trap restore_checkout EXIT
wp1 db query "DELETE FROM wp_wprism_map WHERE id_kind='post' AND local_id=$CHECKOUT_ID"
wp1 db query "UPDATE wp_posts SET post_parent=0 WHERE post_parent=$CHECKOUT_ID"
wp1 db query "UPDATE wp_posts SET post_type='wprism_test_unscoped' WHERE ID=$CHECKOUT_ID"
set +e
GATE_OUT=$(wp1 wprism capture --repo=/siterepo 2>&1)
GATE_RC=$?
set -e
[ "$GATE_RC" -ne 0 ] || fail "expected the unscoped-ref gate to abort capture"
grep -q "unresolvable ref-typed option(s) point at real, out-of-scope entities" <<<"$GATE_OUT" || fail "wrong error (got: $GATE_OUT)"
grep -q "pmpro_checkout_page_id" <<<"$GATE_OUT" || fail "gate did not name the option"
pass "loud-and-blocking gate fired correctly, naming the option, the raw id, and the real target type"
trap - EXIT
restore_checkout
wp1 wprism capture --repo=/siterepo
pass "scope fixed, recapture succeeds cleanly"

say "hard lint gate"
wp1 wprism lint --repo=/siterepo
pass "lint: 0 findings"

say "capture-twice determinism"
wp1 wprism capture --repo=/siterepo --out=/siterepo/.tmp-state2
diff -r "$HOST1/state" "$HOST1/.tmp-state2" || fail "capture is not deterministic"
rm -rf "$HOST1/.tmp-state2"
pass "capture-twice diff is empty"

$GIT_1 add -A
$GIT_1 commit -qm "capture: events + memberships site on r3b1"
$GIT_1 push -q origin main

say "round-trip: clone into ${PAIR}2, deploy, plan, apply (adopt installer collisions)"
git clone -q "$ORIGIN" "$HOST2"
# issue #3216/issue #3250: deploy runs BEFORE plan/apply, matching the documented
# deploy-before-apply contract (docs/code-half.md §3.4) and the
# exact ordering grind_r1b_shop.sh's own PR #14 fix established for this
# same class of scenario. This reorder is proactive, not reactive to a live
# failure here: TEC+PMPro install identically active on both r3b1/r3b2
# (install_plugins runs on both sides), so Deploy::code_mismatch() finds
# nothing to report regardless of call order today — but the ordering was
# objectively non-compliant, and a silent landmine for the day this
# scenario grows a theme-divergence or staggered-activation step the way
# grind_r1b_shop.sh/grind_r3a_multilingual.sh already have.
wp2 wprism deploy --repo=/siterepo
PLAN_TXT=$(wp2 wprism plan --repo=/siterepo)
grep -q 'COLLISION' <<<"$PLAN_TXT" || fail "expected installer-created page/post/term collisions in the plan"
REV=$(git -C "$HOST2" rev-parse HEAD)
wp2 wprism apply --repo=/siterepo --adopt-by-slug=terms,posts --default-author=admin --revision="$REV" \
  | tee /tmp/r3b_apply1.txt
grep -q 'canary clean' /tmp/r3b_apply1.txt || fail "apply canary not clean"
pass "deploy + apply succeeded on r3b2 (canary clean)"

say "byte-identical recapture across environments"
wp2 wprism capture --repo=/siterepo --out=/siterepo/.tmp-final
DIFF_OUT=$(diff -rq "$HOST1/state" "$HOST2/.tmp-final" || true)
rm -rf "$HOST2/.tmp-final"
[ -z "$DIFF_OUT" ] || fail "byte-identity broken: $DIFF_OUT"
pass "byte-identical: posts, terms, options, AND typed-snapshot table entities (pmpro_membership_levels, pmpro_membership_levelmeta, pmpro_memberships_pages)"

say "issue #3234's regen_dependency contract, proven live: TEC's derived custom tables are now regenerated automatically as part of apply — the manual-step gap this round originally found is closed"
# issue #3258: this step used to assert the GAP itself (zero tec_occurrences,
# invisible events, then a manual per-event regeneration loop reproducing
# TEC's own Single_Event_Migration_Strategy machinery by hand). issue #3234
# landed manifests/the-events-calendar.json's post_types.tribe_events.
# regen_dependency (regenerator="the-events-calendar", verify={table:
# tec_occurrences, column: post_id}) — see that manifest's own note: "the
# manual step is now automatic." Apply::regen_dependencies() runs
# synchronously inside apply(), calls the regenerator for every applied
# tribe_events post, and hard-fails the WHOLE apply (not a warning) if
# verification still finds no row afterward. The round-trip apply above
# already proves this succeeded — a regen_dependencies() failure would
# have thrown before "canary clean" ever printed — this step makes that
# proof explicit and checks the regen_pending ledger directly (issue #3234's
# own designed observability surface), rather than only inferring success
# from apply's own exit code.
TEC_ROWS=$(wp2 db query "SELECT COUNT(*) FROM wp_tec_occurrences" --skip-column-names)
TEC_B1_NOW=$(wp1 db query "SELECT COUNT(*) FROM wp_tec_occurrences" --skip-column-names)
[ "$TEC_ROWS" != "0" ] && [ "$TEC_ROWS" = "$TEC_B1_NOW" ] \
  || fail "expected tec_occurrences to be nonzero and consistent with the source side's count after a fresh apply (source r3b1=$TEC_B1_NOW, target r3b2=$TEC_ROWS) — the regen_dependency contract is not behaving as declared"
VISIBLE_COUNT=$(wp2 post list --post_type=tribe_events --post_status=any --format=count)
[ "$VISIBLE_COUNT" = "3" ] || fail "expected all 3 applied events immediately visible to WP_Query with no manual step (got $VISIBLE_COUNT)"
PLAN_AFTER=$(wp2 wprism plan --repo=/siterepo)
grep -q ', 0 regen_pending' <<<"$PLAN_AFTER" || fail "expected zero regen_pending markers outstanding after a clean apply (plan said: $(grep -o '[0-9]* regen_pending' <<<"$PLAN_AFTER"))"
wp2 rewrite flush >/dev/null
pass "confirmed fixed: issue #3234's regen_dependency contract transparently regenerated wp_tec_occurrences as part of apply itself — 3/3 events visible immediately, tec_occurrences consistent with source ($TEC_ROWS rows), zero regen_pending markers outstanding, no manual step required. This WAS the round's central finding; issue #3234 is why it no longer holds."

say "issue #3235's composite_ref contract, proven live: PMPro's page-restriction join row propagated and resolved to r3b2's own entity ids"
RESTRICT_ROWS_B1=$(wp1 db query "SELECT COUNT(*) FROM wp_pmpro_memberships_pages" --skip-column-names)
RESTRICT_ROWS_B2=$(wp2 db query "SELECT COUNT(*) FROM wp_pmpro_memberships_pages" --skip-column-names)
[ "$RESTRICT_ROWS_B1" = "1" ] || fail "expected exactly one authored restriction row on r3b1 (got $RESTRICT_ROWS_B1)"
[ "$RESTRICT_ROWS_B2" = "1" ] || fail "expected exactly one captured restriction row on r3b2 immediately after apply (got $RESTRICT_ROWS_B2)"
STUDIO_ID_B2=$(wp2 post list --post_type=page --name=studio-members-only --field=ID)
LEVEL2_ID_B2=$(wp2 db query "SELECT id FROM wp_pmpro_membership_levels WHERE name='Studio Access'" --skip-column-names)
RESTRICT_MATCH_B2=$(wp2 db query "SELECT COUNT(*) FROM wp_pmpro_memberships_pages WHERE membership_id=$LEVEL2_ID_B2 AND page_id=$STUDIO_ID_B2" --skip-column-names)
[ "$RESTRICT_MATCH_B2" = "1" ] || fail "captured restriction did not resolve to r3b2's own Studio Access level ($LEVEL2_ID_B2) and Studio Members Only page ($STUDIO_ID_B2)"
pass "confirmed fixed: the composite_ref row exists immediately after apply and points at r3b2's own Studio Access level + Studio Members Only page; no PMPro repair call was needed"

say "complete-response render checks + negative host-leak assertion"
# issue #3301 reproduced the former aggregate "delay" on an isolated pair:
# the checker reported a missing title, while an immediate complete
# 79,953-byte response contained all three titles. The cause was the
# producer-side SIGPIPE race in `echo "$LIST_HTML" | grep -q` under
# pipefail, not TEC/cache/query readiness. Buffer the response, prove it is
# complete, then inspect it through here-strings so every page is a hard,
# truthful assertion with no retry budget to hide a defect.
EVENT_HTML=$(curl -fsSL --max-time 30 "$R3B2/event/fall-open-house/")
assert_complete_html "$EVENT_HTML" "Fall Open House single-event page"
grep -qF "Fall Open House" <<<"$EVENT_HTML" || fail "event title did not render on r3b2"
grep -qiF "Riverside Commons Workshop Hall" <<<"$EVENT_HTML" || fail "venue name did not render on the event page"
EVENT2_HTML=$(curl -fsSL --max-time 30 "$R3B2/event/community-meetup/")
assert_complete_html "$EVENT2_HTML" "Community Meetup single-event page"
grep -qF "Community Meetup" <<<"$EVENT2_HTML" || fail "Community Meetup's own single-event page did not render on r3b2"
EVENT3_HTML=$(curl -fsSL --max-time 30 "$R3B2/event/annual-gala/")
assert_complete_html "$EVENT3_HTML" "Annual Gala single-event page"
grep -qF "Annual Gala" <<<"$EVENT3_HTML" || fail "Annual Gala's own single-event page did not render on r3b2"
pass "all 3 complete single-event responses render their own title correctly"
LIST_HTML=$(curl -fsSL --max-time 30 "$R3B2/events/list/")
assert_complete_html "$LIST_HTML" "/events/list/ aggregate page"
for t in "Fall Open House" "Community Meetup" "Annual Gala"; do
  grep -qF "$t" <<<"$LIST_HTML" || fail "/events/list/ aggregate view missing '$t' from a complete ${#LIST_HTML}-byte response"
done
pass "/events/list/ first complete response shows all 3 events"
STUDIO_HTML=$(curl -fsSL --max-time 30 "$R3B2/studio-members-only/")
assert_complete_html "$STUDIO_HTML" "Studio Members Only page"
LEAK_COUNT=$(printf '%s%s%s%s' "$EVENT_HTML" "$EVENT2_HTML" "$EVENT3_HTML" "$STUDIO_HTML" | grep -c "localhost:$PORT1" || true)
[ "$LEAK_COUNT" = "0" ] || fail "found $LEAK_COUNT leaked side-1 host string(s) in r3b2's rendered pages"
grep -qiF "tool access, storage lockers" <<<"$STUDIO_HTML" && fail "captured restriction did not block the authored content from an anonymous visitor"
CONFIRMATION_B2=$(wp2 db query "SELECT confirmation FROM wp_pmpro_membership_levels WHERE name='Community'" --skip-column-names)
grep -q "localhost:$PORT2" <<<"$CONFIRMATION_B2" || fail "confirmation text internal link did not detokenize to ${PAIR}2's own host"
grep -q "localhost:$PORT1" <<<"$CONFIRMATION_B2" && fail "confirmation text leaked ${PAIR}1's host"
pass "TEC event pages render correctly on ${PAIR}2; zero side-1 host leaks anywhere; the captured restriction blocks anonymous access with no manual repair; the membership confirmation text's internal link correctly re-bound to ${PAIR}2's own host ($R3B2/...), not ${PAIR}1's"

# A front-end request with no Customizer CSS can materialize WordPress's
# custom_css_post_id=-1 sentinel. It is runtime lookup residue, not authored
# fixture state; clear it before the later capture for the same reason the
# reset path above does.
wp2 theme mod remove custom_css_post_id >/dev/null 2>&1 || true

say "runtime isolation: r3b1's real member signup never propagates to r3b2, and vice versa nothing here touches r3b1"
MEMBERS_B2=$(wp2 db query "SELECT COUNT(*) FROM wp_pmpro_memberships_users" --skip-column-names)
[ "$MEMBERS_B2" = "0" ] || fail "expected zero pmpro_memberships_users rows on r3b2 (got $MEMBERS_B2) — r3b1's signup must stay local"
USERS_B2=$(wp2 user list --field=user_login)
grep -q dana.rivera <<<"$USERS_B2" && fail "r3b1's member user leaked onto r3b2" || true
MEMBERS_B1=$(wp1 db query "SELECT COUNT(*) FROM wp_pmpro_memberships_users" --skip-column-names)
[ "$MEMBERS_B1" = "1" ] || fail "expected r3b1's own signup to remain intact (got $MEMBERS_B1)"
TEC_B1=$(wp1 db query "SELECT COUNT(*) FROM wp_tec_occurrences" --skip-column-names)
[ "$TEC_B1" = "3" ] || fail "expected r3b1's own tec_occurrences (never touched by r3b2's own apply-time regen_dependency work) to remain intact (got $TEC_B1)"
pass "confirmed both directions: r3b2 has zero of r3b1's member/runtime data; r3b1's own runtime state (member signup, tec_occurrences) is untouched by any of r3b2's independent work"

say "divergent-edit merge: conflicting Community-level price edits on both environments"
$GIT_1 checkout -qb price-r3b1 main
LEVEL1_ID_B1=$(wp1 db query "SELECT id FROM wp_pmpro_membership_levels WHERE name='Community'" --skip-column-names)
wp1 db query "UPDATE wp_pmpro_membership_levels SET billing_amount=12.99 WHERE id=$LEVEL1_ID_B1"
wp1 wprism capture --repo=/siterepo >/dev/null
$GIT_1 add -A && $GIT_1 commit -qm "price: Community membership -> 12.99" && $GIT_1 push -qu origin price-r3b1

$GIT_2 fetch -q origin
$GIT_2 checkout -qb price-r3b2 origin/main
LEVEL1_ID_B2=$(wp2 db query "SELECT id FROM wp_pmpro_membership_levels WHERE name='Community'" --skip-column-names)
wp2 db query "UPDATE wp_pmpro_membership_levels SET billing_amount=8.99 WHERE id=$LEVEL1_ID_B2"
wp2 wprism capture --repo=/siterepo >/dev/null
$GIT_2 add -A && $GIT_2 commit -qm "price: Community membership -> 8.99" && $GIT_2 push -qu origin price-r3b2

$GIT_1 checkout -q main
$GIT_1 merge -q price-r3b1
set +e
$GIT_1 fetch -q origin price-r3b2
$GIT_1 merge origin/price-r3b2 >/tmp/r3b_merge.txt 2>&1
MERGE_RC=$?
set -e
[ "$MERGE_RC" -ne 0 ] || fail "expected a merge conflict on the Community level's billing_amount"
LEVELFILE=$(ls "$HOST1"/state/tables/pmpro_membership_levels/*community.json)
grep -q '<<<<<<<' "$LEVELFILE" || fail "no conflict markers found on the typed-snapshot table entity file"
pass "conflict surfaced as a plain git conflict on the table entity's billing_amount field — the SAME entity-per-file discipline posts/terms already get, now proven for the new typed-snapshot table machinery"

python3 - "$LEVELFILE" <<'PYEOF'
import re, sys
p = sys.argv[1]
s = open(p).read()
s = re.sub(r'<<<<<<< HEAD\n( *)"billing_amount": "12\.99000000",\n=======\n *"billing_amount": "8\.99000000",\n>>>>>>> origin/price-r3b2\n', r'\1"billing_amount": "10.99000000",\n', s)
open(p, 'w').write(s)
PYEOF
grep -qc '<<<<<<<' "$LEVELFILE" && fail "conflict markers remain after resolution" || true
$GIT_1 add -A
$GIT_1 commit -qm "merge price-r3b2 into main (editorial resolution: settled on 10.99)"
$GIT_1 push -q origin main
pass "conflict resolved editorially (split the difference: 10.99), committed, pushed"

say "apply the merged price to both environments; confirm convergence"
REV1=$(git -C "$HOST1" rev-parse HEAD)
wp1 wprism apply --repo=/siterepo --default-author=admin --revision="$REV1" >/dev/null
[ "$(wp1 db query "SELECT billing_amount FROM wp_pmpro_membership_levels WHERE id=$LEVEL1_ID_B1" --skip-column-names)" = "10.99000000" ] || fail "r3b1 did not converge"

git -C "$HOST2" checkout -q main
git -C "$HOST2" pull -q origin main
REV2=$(git -C "$HOST2" rev-parse HEAD)
wp2 wprism apply --repo=/siterepo --default-author=admin --force-theirs --revision="$REV2" >/dev/null
[ "$(wp2 db query "SELECT billing_amount FROM wp_pmpro_membership_levels WHERE id=$LEVEL1_ID_B2" --skip-column-names)" = "10.99000000" ] || fail "r3b2 did not converge"
pass "both environments converged on the editorially-merged price (\$10.99)"

printf '\n\033[1;32m✔ GRIND R3-B PASSED\033[0m\n'
