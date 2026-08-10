#!/usr/bin/env bash
# DUO-3209: copied/invalid embedded identity blocks before state publication.
set -euo pipefail

# DUO-3409: concurrency-safe allocation of the host `duo explain` envs registry
# used at the DUO-3345 explain slice below (sourced like _retry_helper.sh).
source "$(dirname "${BASH_SOURCE[0]}")/_explain_registry.sh"

# DUO-3264: dynamic_options.theme_mods -- proof beyond the generic
# byte-diff already run above in run.sh (which only proves conf1's
# captured tokens equal conf2's captured tokens; it can't see whether the
# target's LIVE blob merged sub-keys into conf2's own pre-existing content
# correctly, or whether a previously-active theme's own row genuinely
# never entered state/ at all). Deliberately placed FIRST in this file,
# before any of the DUO-3209/3210 tests below delete the shared
# conformance-logo attachment (the "branch edit" block) -- that later
# deletion is what exercises Apply::apply_option_sub_keys()'s sub-key
# tombstone fix (also DUO-3264, found live via this exact interaction: a
# stale custom_logo/header_image/header_image_data left behind on conf2
# after conf1's own recapture correctly stopped reporting them), so this
# block intentionally runs against the fully-populated, pre-deletion state
# rather than duplicating that proof.
CONF2_MODS=$(wp_conf2 option get theme_mods_twentytwentyfive --format=json)
echo "$CONF2_MODS" | jq -e '.background_color == "3c8c3c"' >/dev/null \
  || fail "theme_mods_twentytwentyfive.background_color did not apply correctly on conf2: $CONF2_MODS"
echo "$CONF2_MODS" | jq -e '.custom_logo | type == "number"' >/dev/null \
  || fail "theme_mods_twentytwentyfive.custom_logo did not re-resolve to a local attachment id on conf2: $CONF2_MODS"
CONF2_LOGO_ID=$(echo "$CONF2_MODS" | jq -r '.custom_logo')
[ "$(wp_conf2 post get "$CONF2_LOGO_ID" --field=post_type 2>/dev/null)" = "attachment" ] \
  || fail "theme_mods_twentytwentyfive.custom_logo ($CONF2_LOGO_ID) does not point at a real attachment on conf2"
echo "$CONF2_MODS" | jq -e --arg port "$CONF2_PORT" '.header_image | contains("localhost:" + $port)' >/dev/null \
  || fail "theme_mods_twentytwentyfive.header_image was not rewritten to conf2's own domain: $CONF2_MODS"
echo "$CONF2_MODS" | jq -e '.header_image_data.attachment_id == .custom_logo' >/dev/null \
  || fail "theme_mods_twentytwentyfive.header_image_data.attachment_id did not re-resolve consistently with custom_logo: $CONF2_MODS"
CONF2_CSS_ID=$(echo "$CONF2_MODS" | jq -r '.custom_css_post_id')
[ "$(wp_conf2 post get "$CONF2_CSS_ID" --field=post_type 2>/dev/null)" = "custom_css" ] \
  || fail "theme_mods_twentytwentyfive.custom_css_post_id ($CONF2_CSS_ID) does not point at a real custom_css post on conf2"
[ "$(wp_conf2 post get "$CONF2_CSS_ID" --field=post_content 2>/dev/null)" = 'body { background: #3c8c3c; }' ] \
  || fail "custom_css post content did not round-trip to conf2"
pass "theme_mods_twentytwentyfive's declared authored sub-keys (background_color, custom_logo, header_image, header_image_data, custom_css_post_id) all apply correctly on conf2, ref-typed fields re-resolved to conf2's own local ids"

echo "$CONF2_MODS" | jq -e '.sidebars_widgets.data."sidebar-1" | length > 0' >/dev/null \
  || fail "conf2's own pre-existing sidebars_widgets did not survive the theme_mods sub-key merge untouched: $CONF2_MODS"
echo "$CONF2_MODS" | jq -e '.wp_classic_sidebars."sidebar-1".name == "Footer"' >/dev/null \
  || fail "conf2's own pre-existing wp_classic_sidebars did not survive the theme_mods sub-key merge untouched: $CONF2_MODS"
pass "conf2's own runtime-excluded sub-keys (sidebars_widgets, wp_classic_sidebars) survived the merge into the live blob untouched -- sub_keys apply is a merge, never a whole-value replace"

jq -e '.records | has("theme_mods_twentytwentyone") | not' "$CONF_REPO1/state/options/core.json" >/dev/null \
  || fail "theme_mods_twentytwentyone (a previously-active theme's own row) leaked into captured state -- residue exclusion failed"
pass "theme_mods_twentytwentyone (residue: a previously-active, now-inactive theme's own row) never entered captured state, exactly as declared"

PENDING2=$(wp_conf2 duo pending --repo=/siterepo --format=json)
[ "$PENDING2" = "[]" ] \
  || fail "wp duo pending on conf2 is no longer empty: $PENDING2"
pass "wp duo pending remains empty post-apply -- empty, auto-registered widget_<type> rows (every core type not covered by widgets{}) stay unscanned by design (contentless scaffolding, never captured before this issue, not captured now); DUO-3278's own declared block/nav_menu/text content applied cleanly"

# DUO-3264 <-> DUO-3278 cross-PR finding, full evolution (see manifests/
# core.json's own note at dynamic_options for the complete walk-back):
# DUO-3264 first shipped its OWN blocking net here (core.json
# option_namespaces for ^sidebars_widgets$/^widget_, ~18 per-name `runtime`
# classifications) believing gate_scan()'s widgets section was informational
# only. Reverted: SidebarState::capture()'s own load_widget_options() ALREADY
# has an unconditional, independent, EARLIER-firing guard for the identical
# condition (any widget_<type> row with real instances and an undeclared
# type refuses capture) -- proven live, this exact probe's own captured
# error was SidebarState's message, not the (also shipped, at the time)
# options-layer one, because SidebarState::capture() always runs before
# build_options() in build()'s own call order. The options-layer net was
# therefore provably unreachable dead weight for this family and is gone.
# What's tested below is what remains true: SidebarState's own guard is
# sufficient on its own, AND (a second, separate finding, also DUO-3264)
# its FIRST shipped message advertised a remedy that didn't work --
# "classify options:widget_<type>=runtime" did nothing, since the guard
# only ever consulted widgets{}, never options.* classification. Fixed at
# the source (SidebarState::load_widget_options() now also treats an
# explicit runtime/env options classification as first-class
# acknowledgment, same tier as a widgets{} entry) rather than dropping the
# remedy from the message -- both are asserted below, live, not assumed.
say "(DUO-3264 <-> DUO-3278) live probe: an unknown, non-core widget type gates loudly (SidebarState's own guard), names a remedy that actually works, then classifies clean"
wp_conf1 option update widget_regress_fake_type '{"2":{"title":"Regress Fake"}}' --format=json >/dev/null

FAKE_PENDING=$(wp_conf1 duo pending --repo=/siterepo --format=json)
echo "$FAKE_PENDING" | jq -e 'any(.section == "widgets" and .key == "regress_fake_type")' >/dev/null \
  || fail "unknown widget type regress_fake_type did not surface in wp duo pending's own widgets section (DUO-3278's gate_scan() diagnostic): $FAKE_PENDING"

FAKE_RC=0
FAKE_CAPTURE_OUT=$(wp_conf1 duo capture --repo=/siterepo 2>&1) || FAKE_RC=$?
# DUO-3391: the `|| FAKE_RC=$?` that lets the three assertions below read
# $FAKE_CAPTURE_OUT is the same thing that keeps `set -e` from firing on a
# compose-layer death. Assert this invocation was answered at all before
# asserting anything about the answer (all three assertions read one capture).
require_duo_answered "conf1 duo capture (unknown widget type probe)" human "$FAKE_CAPTURE_OUT"
[ "$FAKE_RC" -ne 0 ] && echo "$FAKE_CAPTURE_OUT" | grep -q "widget option 'widget_regress_fake_type' contains instances but type 'regress_fake_type' is undeclared" \
  || fail "capture did not loudly refuse the unknown widget type via SidebarState's own guard: $FAKE_CAPTURE_OUT"
# team-lead's own requirement: this refusal must read as widgets-aware, not
# a generic "go classify it" -- both real remedies named inline.
echo "$FAKE_CAPTURE_OUT" | grep -q "add \"regress_fake_type\" to a pinned manifest's widgets{} grammar" \
  || fail "refusal did not name the first remedy (extend widgets{} grammar), or misidentified the type: $FAKE_CAPTURE_OUT"
echo "$FAKE_CAPTURE_OUT" | grep -q "declare it a deliberate exclusion (wp duo classify --set 'options:widget_regress_fake_type=runtime')" \
  || fail "refusal did not name the second remedy (deliberate exclusion): $FAKE_CAPTURE_OUT"

# The substantive gate: does the second remedy the message names ACTUALLY
# work? (Team-lead's own requirement, after the first shipped version of
# this message was proven to advertise a dead remedy.) Classify via site
# policy exactly as the message instructs, then confirm capture proceeds.
cp "$CONF_REPO1/site.duo.json" "$CONF_REPO1/.tmp-site-backup.json"
jq '.policy.options.widget_regress_fake_type = {"class": "runtime"}' "$CONF_REPO1/site.duo.json" > "$CONF_REPO1/.tmp-site-new.json"
mv "$CONF_REPO1/.tmp-site-new.json" "$CONF_REPO1/site.duo.json"
# DUO-3391: the only NON-refusal assertion in this family, and at risk for the
# identical reason — `|| fail` consumes the exit status, so a compose-layer
# death reaches this engine-accusing message instead of `set -e`. Captured
# (rather than discarded) purely so the answer can be asserted first; the
# accusation itself is unchanged and still keyed on the exit status alone.
REMEDY_RC=0
REMEDY_OUT=$(wp_conf1 duo capture --repo=/siterepo 2>&1) || REMEDY_RC=$?
require_duo_answered "conf1 duo capture after the classify-runtime remedy" human "$REMEDY_OUT"
if [ "$REMEDY_RC" -ne 0 ]; then
  # Capturing must not cost the operator the refusal text the uncaptured
  # shape left in the sweep log; the accusation itself is byte-unchanged.
  printf '%s\n' "$REMEDY_OUT" >&2
  fail "capture still refused widget_regress_fake_type after following the message's own stated remedy (site policy classified it runtime) -- the escape hatch does not function"
fi
mv "$CONF_REPO1/.tmp-site-backup.json" "$CONF_REPO1/site.duo.json"
wp_conf1 option delete widget_regress_fake_type >/dev/null
wp_conf1 duo capture --repo=/siterepo >/dev/null
pass "unknown widget type: SidebarState's own guard refuses naming BOTH remedies, the deliberate-exclusion remedy it names actually works (verified, not assumed -- the operator-path-dishonesty class this project refuses to ship), clean again once the fake type is fully removed"

# DUO-3278: the core fixture's three declared widget kinds round-trip through
# the sidebar wire format with ledger-only identity and target-local counters.
SIDEBAR_FILE="$CONF_REPO1/state/sidebars/sidebar-1.json"
[ -f "$SIDEBAR_FILE" ] || fail "canonical sidebar-1 file is missing"
jq -e '
  ([.widgets[].type] == ["block","text","nav_menu"])
  and (.widgets | length == 3)
  and ([.widgets[].settings | has("_duo_uuid")] | any | not)
  and (.widgets[0].settings.content | contains("{{post:"))
  and (.widgets[0].settings.content | contains("{{uploads}}"))
  and (.widgets[1].settings.text | contains("{{home}}"))
  and (.widgets[2].settings.nav_menu | startswith("{{term:"))
' "$SIDEBAR_FILE" >/dev/null || fail "canonical block/text/nav-menu widget wire format is wrong"

for TYPE in block text nav_menu; do
  UUID=$(jq -r --arg type "$TYPE" '.widgets[] | select(.type == $type) | .uuid' "$SIDEBAR_FILE")
  SOURCE_LOCAL=$(wp_conf1 eval "echo \\Duo\\Ledger::id_for('$UUID', 'widget_$TYPE');")
  TARGET_LOCAL=$(wp_conf2 eval "echo \\Duo\\Ledger::id_for('$UUID', 'widget_$TYPE');")
  [ -n "$SOURCE_LOCAL" ] && [ -n "$TARGET_LOCAL" ] \
    || fail "widget_$TYPE identity is absent from one environment's ledger"
  [ "$SOURCE_LOCAL" != "$TARGET_LOCAL" ] \
    || fail "widget_$TYPE copied source counter $SOURCE_LOCAL instead of allocating target-locally"
done
TARGET_KEYS=$(wp_conf2 eval '$sidebars=get_option("sidebars_widgets"); echo implode(",", $sidebars["sidebar-1"]);')
[[ "$TARGET_KEYS" != *-21* ]] || fail "colliding target widget defaults survived apply: $TARGET_KEYS"
wp_conf2 eval '
foreach (["block","text","nav_menu"] as $type) {
  $stored=get_option("widget_".$type);
  foreach ($stored as $settings) {
    if (is_array($settings) && array_key_exists("_duo_uuid", $settings)) {
      throw new RuntimeException("settings UUID leaked into widget_".$type);
    }
  }
}
' >/dev/null
pass "block/text/nav-menu widgets use portable refs, ledger-only identity, free target counters, and replace target defaults"

A=$(wp_conf1 post list --post_type=page --name=branch-a --field=ID | tr -d '[:space:]')
B=$(wp_conf1 post list --post_type=page --name=branch-b --field=ID | tr -d '[:space:]')
UA=$(wp_conf1 post meta get "$A" _duo_uuid | tr -d '[:space:]')
UB=$(wp_conf1 post meta get "$B" _duo_uuid | tr -d '[:space:]')
# DUO-3381: the duplicate-identity condition below is manufactured from
# these four READS, and `post list --field=ID` on no match — like a
# load-starved `docker compose run` — returns empty with exit 0, while
# `post meta update <id> _duo_uuid ""` then succeeds just as silently. The
# refusal being asserted afterwards would legitimately not fire, and its
# message would report the ENGINE for a corruption this check never managed
# to author. Asserted before the write, so a failure names the right domain.
require_fixture_ids A B
require_fixture_values UA UB

wp_conf1 post meta update "$B" _duo_uuid "$UA" >/dev/null
RC=0
OUT=$(wp_conf1 duo capture --repo=/siterepo 2>&1) || RC=$?
require_duo_answered "conf1 duo capture (duplicate _duo_uuid probe)" human "$OUT"
[ "$RC" -ne 0 ] && grep -q "duplicate _duo_uuid $UA.*post:$A, post:$B" <<<"$OUT" \
  || fail "copied page identity did not fail with both owners: $OUT"
[ -z "$(git -C "$CONF_REPO1" status --porcelain -- state)" ] \
  || fail "failed duplicate-identity capture changed the published state tree"

wp_conf1 post meta update "$B" _duo_uuid 'NOT-A-UUID' >/dev/null
RC=0
OUT=$(wp_conf1 duo capture --repo=/siterepo 2>&1) || RC=$?
require_duo_answered "conf1 duo capture (invalid _duo_uuid probe)" human "$OUT"
[ "$RC" -ne 0 ] && grep -q "invalid _duo_uuid 'NOT-A-UUID'.*post:$B" <<<"$OUT" \
  || fail "invalid embedded identity was not rejected: $OUT"
[ -z "$(git -C "$CONF_REPO1" status --porcelain -- state)" ] \
  || fail "failed invalid-identity capture changed the published state tree"

wp_conf1 post meta update "$B" _duo_uuid "$UB" >/dev/null
wp_conf1 duo capture --repo=/siterepo --out=/siterepo/.tmp-identity-recovered >/dev/null
diff -r "$CONF_REPO1/state" "$CONF_REPO1/.tmp-identity-recovered" \
  || fail "restoring the page's original UUID did not restore deterministic capture"

pass "copied and invalid _duo_uuid metadata block before atomic state publication; original identities recover deterministically"

# DUO-3345 (plan naming + three-way conflict slices): both sides edit the
# same named WordPress entity after their shared base. The public JSON must
# identify base/repository/target roles and safe choices without serializing
# raw entity values; the human renderer must make those roles actionable.
# The explicit override then stays report-not-hide and converges the target,
# leaving the pair synchronized for the deletion scenarios below.
wp_conf2 post update "$(wp_conf2 post list --post_type=page --name=branch-a --field=ID | tr -d '[:space:]')" \
  --post_title='Target Environment Intent For Conflict' >/dev/null
wp_conf1 post update "$A" --post_title='Branch Repository Intent For Conflict' >/dev/null
wp_conf1 duo capture --repo=/siterepo >/dev/null
git -C "$CONF_REPO1" add -A
git -C "$CONF_REPO1" -c user.name=duo -c user.email=duo@example.test commit -qm 'conformance: branch-vs-target conflict intent'
git -C "$CONF_REPO1" push -q origin main
git -C "$CONF_REPO2" pull -q origin main
TITLE_PLAN=$(wp_conf2 duo plan --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
jq -e --arg uuid "$UA" \
  '.conflict | any(
    .uuid == $uuid
    and .title == "Branch Repository Intent For Conflict"
    and .conflict_view.format == "duo-plan-conflict/v1"
    and .conflict_view.kind == "concurrent_change"
    and .conflict_view.reason_code == "repository_and_target_changed_since_base"
    and .conflict_view.base.role == "last_synced"
    and .conflict_view.base.source == "duo_state"
    and .conflict_view.base.state == "present"
    and (.conflict_view.base.content_hash | test("^[a-f0-9]{64}$"))
    and .conflict_view.repository.role == "repository_intent"
    and .conflict_view.repository.source == "compiled_repository"
    and .conflict_view.repository.intent == "update"
    and (.conflict_view.repository.content_hash | test("^[a-f0-9]{64}$"))
    and .conflict_view.repository.expected_base_hash == .conflict_view.base.content_hash
    and .conflict_view.repository.intent_receipt_hash == null
    and .conflict_view.target.role == "target_observation"
    and .conflict_view.target.source == "live_target_snapshot"
    and .conflict_view.target.intent == "preserve_target_change"
    and .conflict_view.target.state == "present"
    and (.conflict_view.target.content_hash | test("^[a-f0-9]{64}$"))
    and .conflict_view.repository.content_hash != .conflict_view.base.content_hash
    and .conflict_view.target.content_hash != .conflict_view.base.content_hash
    and .conflict_view.repository.content_hash != .conflict_view.target.content_hash
    and .conflict_view.recommended_choice == "reconcile_in_repository"
    and (.conflict_view.choices | any(.id == "reconcile_in_repository" and .destructive == false))
    and (.conflict_view.choices | any(.id == "apply_repository" and .destructive == true and .requires == ["--force-theirs"] and .effect == "replace_target_authored_state"))
  )' <<<"$TITLE_PLAN" >/dev/null \
  || fail "planned conflict row does not carry exact base/repository/target intent and safe choices: $TITLE_PLAN"
! grep -q 'Target Environment Intent For Conflict' <<<"$TITLE_PLAN" \
  || fail "plan JSON leaked the target's raw conflicting title instead of hash-only evidence: $TITLE_PLAN"
TITLE_HUMAN=$(wp_conf2 duo plan --repo=/siterepo)
grep -qE "^CONFLICT +.*'Branch Repository Intent For Conflict'" <<<"$TITLE_HUMAN" \
  || fail "human plan line does not show the WordPress title: $TITLE_HUMAN"
for NEEDLE in \
  'WHY repository_and_target_changed_since_base' \
  'BASE last-synced: present sha256:' \
  'REPOSITORY intent=update state=sha256:' \
  'TARGET observation: intent=preserve_target_change state=present sha256:' \
  'SAFE CHOICE reconcile_in_repository:' \
  'DESTRUCTIVE OVERRIDE apply_repository (--force-theirs): replace target authored state'; do
  grep -Fq "$NEEDLE" <<<"$TITLE_HUMAN" \
    || fail "human conflict view is missing '$NEEDLE': $TITLE_HUMAN"
done

# DUO-3345 slice 4: the host-level public explain path re-observes this exact
# conflict under a strict SELECT-only boundary. The selector printed by human
# plan is hash-safe; the explanation is a separate value-free schema and may
# not inherit plan's ledger maintenance or provider/action authority.
EXPLAIN_ENTITY_HASH=$(printf '%s' "$UA" | shasum -a 256 | awk '{print $1}')
EXPLAIN_SELECTOR="conflict:sha256:$EXPLAIN_ENTITY_HASH"
grep -Fq "EXPLAIN wp duo explain $EXPLAIN_SELECTOR --repo=<repo>" <<<"$TITLE_HUMAN" \
  || fail "human plan did not print the copyable hash-safe explain selector: $TITLE_HUMAN"
# DUO-3409: a per-run private directory (portable across GNU/BSD mktemp — see
# _explain_registry.sh), with the trap installed BEFORE the first write so an
# interrupt cannot leave the temp namespace occupied for a later sweep.
EXPLAIN_REGISTRY_DIR=$(alloc_explain_registry_dir)
trap 'rm -rf -- "${EXPLAIN_REGISTRY_DIR:-}"' EXIT
EXPLAIN_REGISTRY="$EXPLAIN_REGISTRY_DIR/envs.json"
jq -n --arg compose "$PWD/pair.yml" '{envs:{target:{transport:"docker",compose_file:$compose,service:"cli2",repo_path:"/siterepo"}}}' \
  >"$EXPLAIN_REGISTRY"
# A real attachment is in scope. Register an offload adapter hook that would
# abort if strict explain contacted it; local media is already present, so the
# observation must bypass provider-owned code entirely. The persistent web
# volume is root-owned, so install the fixture through the pair's exact owned
# web container, then prove WordPress actually registered it before relying on
# the negative invocation assertion.
$COMPOSE exec -T --user root wp2 sh -c \
  'printf "%s\n" "<?php" "add_filter(\"duo_attachment_capture_source\", static function () { throw new RuntimeException(\"DUO_EXPLAIN_OFFLOAD_HOOK_WAS_INVOKED\"); });" > /var/www/html/wp-content/mu-plugins/duo-explain-offload-guard.php'
[ "$(wp_conf2 eval 'echo has_filter("duo_attachment_capture_source") ? "registered" : "missing";')" = 'registered' ] \
  || fail "the throwing attachment-offload premise hook was not registered"
EXPLAIN_DB_BEFORE=$(wp_conf2 db export - --skip-comments --single-transaction 2>/dev/null | shasum -a 256 | awk '{print $1}')
EXPLAIN_REPO_BEFORE=$(git -C "$CONF_REPO2" status --porcelain --untracked-files=all)
EXPLAIN_RC=0
EXPLAIN_JSON=$(php ../cli/duo --envs-file="$EXPLAIN_REGISTRY" explain target "$EXPLAIN_SELECTOR" --format=json 2>/dev/null) \
  || EXPLAIN_RC=$?
EXPLAIN_HUMAN=''
if [ "$EXPLAIN_RC" -eq 0 ]; then
  EXPLAIN_HUMAN=$(php ../cli/duo --envs-file="$EXPLAIN_REGISTRY" explain target "$EXPLAIN_SELECTOR" 2>/dev/null) \
    || EXPLAIN_RC=$?
fi
$COMPOSE exec -T --user root wp2 rm -f -- /var/www/html/wp-content/mu-plugins/duo-explain-offload-guard.php
rm -rf -- "$EXPLAIN_REGISTRY_DIR"
trap - EXIT
# The json invocation is safe to gate: the host CLI's refusal envelope goes to
# STDOUT (cli/duo's wants_agent_refusal_json path), so a genuine refusal still
# reaches the accusation below while a compose-layer death (empty stdout)
# names infrastructure. The HUMAN invocation above is deliberately ungated —
# its healthy framing is `EXPLAIN CONFLICT …`, not wp-cli's, and its refusals
# land on the dropped stderr (DUO-3413 owns capturing that).
require_duo_answered "host duo explain (json envelope)" json "$EXPLAIN_JSON"
[ "$EXPLAIN_RC" -eq 0 ] || fail "public host duo explain refused a valid current selector"
jq -e --arg selector "$EXPLAIN_SELECTOR" --arg entity_hash "$EXPLAIN_ENTITY_HASH" '
  .format == "duo-explain/v1"
  and .ok == true
  and .selector.bucket == "conflict"
  and .selector.entity_identity_sha256 == $entity_hash
  and .selector.copyable == $selector
  and (.basis.artifact_sha256 | test("^[a-f0-9]{64}$"))
  and (.basis.revision_sha256 | test("^[a-f0-9]{64}$"))
  and .action.bucket == "conflict"
  and .action.reason_code == "repository_and_target_changed_since_base"
  and .source.kind == "canonical_entity"
  and .source.path == "posts/page/<identity>.md"
  and .source.content_binding == "compiled_artifact"
  and (.rules | length) > 0
  and (.references.status == "none_declared" or .references.status == "declared")
  and .execution.mutation.apply_eligibility == "blocked"
  and .execution.rebuild_surfaces == []
  and .execution.actions == []
  and .execution.provider_negotiation == "not_performed"
  and .execution.action_invocation == "not_performed"
  and .verification[0].verifier == "canonical-recapture/v1"
  and .verification[0].when == "not_scheduled"
  and .redaction.canonical_values == "omitted"' <<<"$EXPLAIN_JSON" >/dev/null \
  || fail "public explain did not return the bounded source/rule/reference/action/verification contract: $EXPLAIN_JSON"
for FORBIDDEN in \
  "$UA" \
  'Branch Repository Intent For Conflict' \
  'Target Environment Intent For Conflict' \
  '/siterepo'; do
  ! grep -Fq "$FORBIDDEN" <<<"$EXPLAIN_JSON$EXPLAIN_HUMAN" \
    || fail "public explain leaked raw entity/value/path evidence"
done
for NEEDLE in \
  "EXPLAIN CONFLICT posts/page/<identity>.md" \
  "selector: $EXPLAIN_SELECTOR" \
  'intent: preserve_target_state (blocked)' \
  'structured actions: none selected by this row' \
  'values: omitted'; do
  grep -Fq "$NEEDLE" <<<"$EXPLAIN_HUMAN" \
    || fail "human explain is missing '$NEEDLE': $EXPLAIN_HUMAN"
done
EXPLAIN_DB_AFTER=$(wp_conf2 db export - --skip-comments --single-transaction 2>/dev/null | shasum -a 256 | awk '{print $1}')
[ "$EXPLAIN_DB_AFTER" = "$EXPLAIN_DB_BEFORE" ] \
  || fail "strict explain changed the target database"
[ "$(git -C "$CONF_REPO2" status --porcelain --untracked-files=all)" = "$EXPLAIN_REPO_BEFORE" ] \
  || fail "strict explain changed the target repository"
pass "public duo explain traces one current row through a deterministic value-free contract with zero database/repository/provider/action mutation"

CONFLICT_TARGET_BEFORE=$(wp_conf2 post list --post_type=page --name=branch-a --field=post_title)
CONFLICT_BASE_BEFORE=$(wp_conf2 db query "SELECT content_hash FROM wp_duo_state WHERE uuid = '$UA'" --skip-column-names | tr -d '[:space:]')
CONFLICT_RC=0
CONFLICT_OUT=$(wp_conf2 duo apply --repo=/siterepo --default-author=admin 2>&1) || CONFLICT_RC=$?
require_duo_answered "conf2 duo apply (unforced three-way conflict probe)" human "$CONFLICT_OUT"
[ "$CONFLICT_RC" -ne 0 ] && grep -qi 'conflicts (env and repo both changed' <<<"$CONFLICT_OUT" \
  || fail "unforced three-way conflict did not refuse before mutation: $CONFLICT_OUT"
[ "$(wp_conf2 post list --post_type=page --name=branch-a --field=post_title)" = "$CONFLICT_TARGET_BEFORE" ] \
  || fail "unforced conflict mutated the target title"
[ "$(wp_conf2 db query "SELECT content_hash FROM wp_duo_state WHERE uuid = '$UA'" --skip-column-names | tr -d '[:space:]')" = "$CONFLICT_BASE_BEFORE" ] \
  || fail "unforced conflict advanced the target's last-synced base"
FORCED_CONFLICT=$(wp_conf2 duo apply --repo=/siterepo --force-theirs --default-author=admin --format=json | awk 'NF { line=$0 } END { print line }')
jq -e --arg uuid "$UA" '.warnings | any(contains("FORCED conflict " + $uuid))' <<<"$FORCED_CONFLICT" >/dev/null \
  || fail "--force-theirs did not report the overridden conflict in machine output: $FORCED_CONFLICT"
[ "$(wp_conf2 post list --post_type=page --name=branch-a --field=post_title)" = 'Branch Repository Intent For Conflict' ] \
  || fail "forced repository intent did not converge on target"
pass "plan conflicts speak WordPress names, expose hash-only base/repository/target intent, recommend reconciliation, and report destructive override (DUO-3345)"

# DUO-3210: absence alone is not authority; capture replaces the prior Home
# page with a versioned tombstone. A target-only comment blocks deletion,
# the explicit force path stays loud, comments are preserved, and the
# tombstone receipt makes retry a no-op.
HOME_FILE=$(find "$CONF_REPO1/state/posts/page" -name '*--home.md' -print -quit)
HOME_UUID=$(basename "$HOME_FILE" | sed -E 's/--home\.md$//')
HOME1=$(wp_conf1 post list --post_type=page --name=home --field=ID | tr -d '[:space:]')
HOME2=$(wp_conf2 post list --post_type=page --name=home --field=ID | tr -d '[:space:]')
require_fixture_ids HOME1 HOME2
COMMENT2=$(wp_conf2 comment create --comment_post_ID="$HOME2" --comment_content='runtime deletion guard' --comment_author='Runtime Visitor' --porcelain)
require_fixture_ids COMMENT2
wp_conf1 post delete "$HOME1" --force >/dev/null
DELETE_CAPTURE=$(wp_conf1 duo capture --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
[ "$(jq -r '.counts.deletion' <<<"$DELETE_CAPTURE")" -ge 1 ] \
  || fail "page deletion did not emit a tombstone: $DELETE_CAPTURE"
[ -f "$CONF_REPO1/state/deletions/$HOME_UUID.json" ] || fail "Home tombstone was not published"
git -C "$CONF_REPO1" add -A
git -C "$CONF_REPO1" -c user.name=duo -c user.email=duo@example.test commit -qm 'conformance: explicit page deletion'
git -C "$CONF_REPO1" push -q origin main
git -C "$CONF_REPO2" pull -q origin main

DELETE_PLAN=$(wp_conf2 duo plan --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
jq -e --arg uuid "$HOME_UUID" '.delete | any(.uuid == $uuid and (.blocked | contains("comments reference")))' \
  <<<"$DELETE_PLAN" >/dev/null || fail "target-only comment did not block the explicit page deletion: $DELETE_PLAN"
DELETE_RC=0
DELETE_OUT=$(wp_conf2 duo apply --repo=/siterepo --with-deletes --default-author=admin 2>&1) || DELETE_RC=$?
require_duo_answered "conf2 duo apply --with-deletes (referential guard probe)" human "$DELETE_OUT"
[ "$DELETE_RC" -ne 0 ] && grep -qi 'referential guard' <<<"$DELETE_OUT" \
  || fail "guarded page delete was not refused: $DELETE_OUT"
DELETE_OUT=$(wp_conf2 duo apply --repo=/siterepo --with-deletes --force-delete-referenced --default-author=admin --format=json | awk 'NF { line=$0 } END { print line }')
jq -e '.canary == "clean" and (.warnings | any(contains("FORCED delete")))' <<<"$DELETE_OUT" >/dev/null \
  || fail "forced page deletion was not loud and clean: $DELETE_OUT"
[ -z "$(wp_conf2 post list --post_type=page --name=home --field=ID)" ] || fail "Home page survived exact deletion"
[ "$(wp_conf2 comment get "$COMMENT2" --field=comment_ID)" = "$COMMENT2" ] || fail "runtime comment was cascaded or lost"
RETRY_PLAN=$(wp_conf2 duo plan --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
jq -e --arg uuid "$HOME_UUID" '(.delete | length) == 0 and (.delete_conflict | length) == 0 and (.deleted | any(.uuid == $uuid))' \
  <<<"$RETRY_PLAN" >/dev/null || fail "page tombstone retry did not settle as deleted: $RETRY_PLAN"
pass "explicit page tombstone guards and preserves comments, verifies exact deletion, and retries idempotently"

# Local edit: the tombstone expected base matches duo_state, but the live
# hash does not. Editing creates a derived revision child; the adapter
# explicitly cascades and verifies revisions while --force-theirs reports
# the overridden delete conflict.
HELLO_FILE=$(find "$CONF_REPO1/state/posts/post" -name '*--hello-conformance.md' -print -quit)
HELLO_UUID=$(basename "$HELLO_FILE" | sed -E 's/--hello-conformance\.md$//')
HELLO1=$(wp_conf1 post list --post_type=post --name=hello-conformance --field=ID | tr -d '[:space:]')
HELLO2=$(wp_conf2 post list --post_type=post --name=hello-conformance --field=ID | tr -d '[:space:]')
require_fixture_ids HELLO1 HELLO2
wp_conf2 post update "$HELLO2" --post_content='target-only deletion conflict' >/dev/null
HELLO_COMMENT=$(wp_conf2 comment create --comment_post_ID="$HELLO2" --comment_content='runtime conflict guard' --comment_author='Runtime Visitor' --porcelain)
require_fixture_ids HELLO_COMMENT
wp_conf1 post delete "$HELLO1" --force >/dev/null
wp_conf1 duo capture --repo=/siterepo >/dev/null
git -C "$CONF_REPO1" add -A
git -C "$CONF_REPO1" -c user.name=duo -c user.email=duo@example.test commit -qm 'conformance: delete against target local edit'
git -C "$CONF_REPO1" push -q origin main
git -C "$CONF_REPO2" pull -q origin main
BLOCKED_LOCAL_PLAN=$(wp_conf2 duo plan --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
jq -e --arg uuid "$HELLO_UUID" '.delete_conflict | any(
  .uuid == $uuid
  and (.blocked | contains("comments reference"))
  and (.conflict_view.choices | any(.id == "apply_repository") | not)
  and (.conflict_view.choices | any(.id == "reconcile_in_repository" and .destructive == false))
)' <<<"$BLOCKED_LOCAL_PLAN" >/dev/null \
  || fail "guard-blocked deletion conflict advertised a destructive repository choice: $BLOCKED_LOCAL_PLAN"
BLOCKED_LOCAL_HUMAN=$(wp_conf2 duo plan --repo=/siterepo)
! grep -Fq 'DESTRUCTIVE OVERRIDE apply_repository' <<<"$BLOCKED_LOCAL_HUMAN" \
  || fail "guard-blocked deletion conflict advertised a destructive override in human output: $BLOCKED_LOCAL_HUMAN"
BLOCKED_CONTENT_BEFORE=$(wp_conf2 post get "$HELLO2" --field=post_content)
BLOCKED_BASE_BEFORE=$(wp_conf2 db query "SELECT content_hash FROM wp_duo_state WHERE uuid = '$HELLO_UUID'" --skip-column-names | tr -d '[:space:]')
BLOCKED_FORCE_RC=0
BLOCKED_FORCE_OUT=$(wp_conf2 duo apply --repo=/siterepo --with-deletes --force-theirs --default-author=admin --format=json 2>/dev/null) \
  || BLOCKED_FORCE_RC=$?
# DUO-3391: json mode, and stderr is deliberately dropped — a compose-layer
# death therefore leaves $BLOCKED_FORCE_OUT EMPTY while satisfying the
# non-zero-exit assertion below vacuously, and the typed-evidence assertion
# after it then accuses the engine of losing its refusal envelope.
require_duo_answered "conf2 duo apply --with-deletes --force-theirs (json refusal envelope)" json "$BLOCKED_FORCE_OUT"
[ "$BLOCKED_FORCE_RC" -ne 0 ] \
  || fail "guard-blocked deletion conflict accepted incomplete force authorization: $BLOCKED_FORCE_OUT"
BLOCKED_FORCE_JSON=$(awk 'NF { line=$0 } END { print line }' <<<"$BLOCKED_FORCE_OUT")
BLOCKED_ENTITY_HASH=$(printf '%s' "$HELLO_UUID" | shasum -a 256 | awk '{print $1}')
jq -e --arg entity_hash "$BLOCKED_ENTITY_HASH" '.format == "duo-command-refusal/v1"
  and .error == "apply_conflict_override_incomplete"
  and (.forced_overrides | length) == 1
  and .forced_overrides[0].format == "duo-forced-plan-override/v1"
  and .forced_overrides[0].plan_bucket == "delete_conflict"
  and .forced_overrides[0].entity_identity_sha256 == $entity_hash
  and .forced_overrides[0].conflict_kind == "tombstone_conflict"
  and .forced_overrides[0].choice == "explicit_force_flags"
  and .forced_overrides[0].effect == "delete_target_authored_state"
  and .forced_overrides[0].required_flags == ["--with-deletes","--force-theirs","--force-delete-referenced"]
  and .forced_overrides[0].supplied_flags == ["--with-deletes","--force-theirs"]
  and (.forced_overrides[0] | has("guard_override") | not)
  and .forced_overrides[0].status == "incomplete"' <<<"$BLOCKED_FORCE_JSON" >/dev/null \
  || fail "guard-blocked deletion refusal did not preserve truthful bounded force evidence: $BLOCKED_FORCE_JSON"
! grep -Fq "$HELLO_UUID" <<<"$BLOCKED_FORCE_JSON" \
  || fail "guard-blocked deletion refusal leaked the raw entity identity: $BLOCKED_FORCE_JSON"
! grep -Fq 'runtime conflict guard' <<<"$BLOCKED_FORCE_JSON" \
  || fail "guard-blocked deletion refusal leaked raw guard detail: $BLOCKED_FORCE_JSON"
[ "$(wp_conf2 post get "$HELLO2" --field=post_content)" = "$BLOCKED_CONTENT_BEFORE" ] \
  || fail "guard-blocked forced deletion mutated the target post"
[ "$(wp_conf2 db query "SELECT content_hash FROM wp_duo_state WHERE uuid = '$HELLO_UUID'" --skip-column-names | tr -d '[:space:]')" = "$BLOCKED_BASE_BEFORE" ] \
  || fail "guard-blocked forced deletion advanced the last-synced base"
wp_conf2 comment get "$HELLO_COMMENT" --field=comment_ID >/dev/null \
  || fail "guard-blocked forced deletion removed its runtime reference"
pass "guard-blocked deletion conflict refuses incomplete force authorization with truthful typed evidence and zero target/ledger mutation"
wp_conf2 comment delete "$HELLO_COMMENT" --force >/dev/null
LOCAL_PLAN=$(wp_conf2 duo plan --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
jq -e --arg uuid "$HELLO_UUID" '.delete_conflict | any(
  .uuid == $uuid
  and (.reason | contains("changed locally"))
  and (has("blocked") | not)
  and .conflict_view.format == "duo-plan-conflict/v1"
  and .conflict_view.kind == "tombstone_conflict"
  and .conflict_view.reason_code == "target_changed_since_delete_base"
  and .conflict_view.base.role == "last_synced"
  and .conflict_view.repository.intent == "delete"
  and .conflict_view.repository.expected_base_hash == .conflict_view.base.content_hash
  and (.conflict_view.repository.intent_receipt_hash | test("^[a-f0-9]{64}$"))
  and .conflict_view.target.intent == "preserve_target_change"
  and .conflict_view.recommended_choice == "reconcile_in_repository"
  and (.conflict_view.choices | any(.id == "apply_repository" and .requires == ["--with-deletes","--force-theirs"] and .effect == "delete_target_authored_state" and .destructive == true))
)' \
  <<<"$LOCAL_PLAN" >/dev/null || fail "local edit did not become a deletion conflict: $LOCAL_PLAN"
LOCAL_HUMAN=$(wp_conf2 duo plan --repo=/siterepo)
for NEEDLE in \
  'WHY target_changed_since_delete_base' \
  'REPOSITORY intent=delete state=none expected-base=sha256:' \
  'DESTRUCTIVE OVERRIDE apply_repository (--with-deletes --force-theirs): delete target authored state'; do
  grep -Fq "$NEEDLE" <<<"$LOCAL_HUMAN" \
    || fail "human deletion-conflict view is missing '$NEEDLE': $LOCAL_HUMAN"
done
LOCAL_FORCE_ONLY_CONTENT=$(wp_conf2 post get "$HELLO2" --field=post_content)
LOCAL_FORCE_ONLY_BASE=$(wp_conf2 db query "SELECT content_hash FROM wp_duo_state WHERE uuid = '$HELLO_UUID'" --skip-column-names | tr -d '[:space:]')
LOCAL_FORCE_ONLY_RC=0
LOCAL_FORCE_ONLY_OUT=$(wp_conf2 duo apply --repo=/siterepo --force-theirs --default-author=admin --format=json 2>/dev/null) \
  || LOCAL_FORCE_ONLY_RC=$?
require_duo_answered "conf2 duo apply --force-theirs (json refusal envelope)" json "$LOCAL_FORCE_ONLY_OUT"
[ "$LOCAL_FORCE_ONLY_RC" -ne 0 ] \
  || fail "entity tombstone conflict accepted --force-theirs without --with-deletes: $LOCAL_FORCE_ONLY_OUT"
LOCAL_FORCE_ONLY_JSON=$(awk 'NF { line=$0 } END { print line }' <<<"$LOCAL_FORCE_ONLY_OUT")
jq -e --arg entity_hash "$BLOCKED_ENTITY_HASH" '.format == "duo-command-refusal/v1"
  and .error == "apply_conflict_override_incomplete"
  and (.forced_overrides | length) == 1
  and .forced_overrides[0].entity_identity_sha256 == $entity_hash
  and .forced_overrides[0].choice == "apply_repository"
  and .forced_overrides[0].effect == "delete_target_authored_state"
  and .forced_overrides[0].required_flags == ["--with-deletes","--force-theirs"]
  and .forced_overrides[0].supplied_flags == ["--force-theirs"]
  and .forced_overrides[0].status == "incomplete"' <<<"$LOCAL_FORCE_ONLY_JSON" >/dev/null \
  || fail "entity tombstone conflict did not report the missing --with-deletes authorization honestly: $LOCAL_FORCE_ONLY_JSON"
[ "$(wp_conf2 post get "$HELLO2" --field=post_content)" = "$LOCAL_FORCE_ONLY_CONTENT" ] \
  || fail "--force-theirs without --with-deletes mutated the deletion-conflict target"
[ "$(wp_conf2 db query "SELECT content_hash FROM wp_duo_state WHERE uuid = '$HELLO_UUID'" --skip-column-names | tr -d '[:space:]')" = "$LOCAL_FORCE_ONLY_BASE" ] \
  || fail "--force-theirs without --with-deletes advanced the deletion-conflict base"
pass "entity tombstone conflicts require both advertised flags and refuse incomplete authorization without mutation"
LOCAL_RC=0
LOCAL_OUT=$(wp_conf2 duo apply --repo=/siterepo --with-deletes --default-author=admin 2>&1) || LOCAL_RC=$?
require_duo_answered "conf2 duo apply --with-deletes (unforced delete conflict probe)" human "$LOCAL_OUT"
[ "$LOCAL_RC" -ne 0 ] && grep -qi 'deletion conflicts' <<<"$LOCAL_OUT" \
  || fail "unforced delete conflict was not refused: $LOCAL_OUT"
LOCAL_OUT=$(wp_conf2 duo apply --repo=/siterepo --with-deletes --force-theirs --default-author=admin --format=json | awk 'NF { line=$0 } END { print line }')
jq -e '.canary == "clean" and (.warnings | any(contains("FORCED deletion conflict")))' <<<"$LOCAL_OUT" >/dev/null \
  || fail "forced local-edit deletion did not report its override: $LOCAL_OUT"
[ -z "$(wp_conf2 post list --post_type=post --name=hello-conformance --field=ID)" ] || fail "locally edited post survived forced deletion"
pass "delete-vs-local-edit conflicts; force-theirs is loud and revision children cascade exactly"

# Branch edit: capture the changed entity into git without applying it to
# conf2, then delete it on conf1. The tombstone therefore expects the new
# branch hash while conf2's base is still the old hash.
ATT_FILE=$(find "$CONF_REPO1/state/posts/attachment" -name '*--conformance-logo.md' -print -quit)
ATT_UUID=$(basename "$ATT_FILE" | sed -E 's/--conformance-logo\.md$//')
ATT1=$(wp_conf1 post list --post_type=attachment --name=conformance-logo --field=ID | tr -d '[:space:]')
wp_conf1 post update "$ATT1" --post_title='Conformance Logo Branch Edit' >/dev/null
wp_conf1 duo capture --repo=/siterepo >/dev/null
git -C "$CONF_REPO1" add -A
git -C "$CONF_REPO1" -c user.name=duo -c user.email=duo@example.test commit -qm 'conformance: branch edits attachment'
git -C "$CONF_REPO1" push -q origin main
wp_conf1 post delete "$ATT1" --force >/dev/null
wp_conf1 duo capture --repo=/siterepo >/dev/null
git -C "$CONF_REPO1" add -A
git -C "$CONF_REPO1" -c user.name=duo -c user.email=duo@example.test commit -qm 'conformance: delete branch-edited attachment'
git -C "$CONF_REPO1" push -q origin main
git -C "$CONF_REPO2" pull -q origin main
BRANCH_PLAN=$(wp_conf2 duo plan --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
jq -e --arg uuid "$ATT_UUID" '.delete_conflict | any(.uuid == $uuid and (.reason | contains("expected hash")))' \
  <<<"$BRANCH_PLAN" >/dev/null || fail "delete-vs-branch-edit did not conflict on its expected base: $BRANCH_PLAN"
wp_conf2 duo apply --repo=/siterepo --with-deletes --force-theirs --default-author=admin --format=json >/dev/null
[ -z "$(wp_conf2 post list --post_type=attachment --name=conformance-logo --field=ID)" ] || fail "branch-conflicted attachment survived forced deletion"
pass "delete-vs-branch-edit conflicts on the tombstone expected base"

# Missing guard infrastructure is a refusal, never a skipped warning.
CHILD_FILE=$(find "$CONF_REPO1/state/posts/page" -name '*--shared-child.md' -print | sort | head -1)
CHILD_UUID=$(basename "$CHILD_FILE" | sed -E 's/--shared-child\.md$//')
CHILD1=$(wp_conf1 eval "echo \\Duo\\Ledger::id_for('$CHILD_UUID', \\Duo\\Ledger::KIND_POST);")
wp_conf1 post delete "$CHILD1" --force >/dev/null
wp_conf1 duo capture --repo=/siterepo >/dev/null
git -C "$CONF_REPO1" add -A
git -C "$CONF_REPO1" -c user.name=duo -c user.email=duo@example.test commit -qm 'conformance: missing deletion guard table'
git -C "$CONF_REPO1" push -q origin main
git -C "$CONF_REPO2" pull -q origin main
wp_conf2 db query 'RENAME TABLE wp_comments TO wp_comments_duo_hold' >/dev/null
MISSING_PLAN=$(wp_conf2 duo plan --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
jq -e --arg uuid "$CHILD_UUID" '.delete | any(.uuid == $uuid and (.blocked | contains("required guard table")))' \
  <<<"$MISSING_PLAN" >/dev/null || fail "missing guard table did not fail closed: $MISSING_PLAN"
wp_conf2 db query 'RENAME TABLE wp_comments_duo_hold TO wp_comments' >/dev/null
wp_conf2 duo apply --repo=/siterepo --with-deletes --default-author=admin --format=json >/dev/null
pass "missing reverse-reference guard infrastructure fails closed"

# Transaction rollback: two safe deletions, but an external FK refuses the
# lexically second UUID. The first row is deleted before the failure and
# must reappear after rollback; removing the test FK lets both complete.
ROLL_A1=$(wp_conf1 post create --post_type=page --post_title='Rollback Alpha' --post_name=rollback-alpha --post_status=publish --porcelain)
ROLL_B1=$(wp_conf1 post create --post_type=page --post_title='Rollback Beta' --post_name=rollback-beta --post_status=publish --porcelain)
wp_conf1 duo capture --repo=/siterepo >/dev/null
git -C "$CONF_REPO1" add -A
git -C "$CONF_REPO1" -c user.name=duo -c user.email=duo@example.test commit -qm 'conformance: seed transactional deletion pair'
git -C "$CONF_REPO1" push -q origin main
git -C "$CONF_REPO2" pull -q origin main
wp_conf2 duo apply --repo=/siterepo --with-deletes --default-author=admin --format=json >/dev/null
ROLL_A_FILE=$(find "$CONF_REPO1/state/posts/page" -name '*--rollback-alpha.md' -print -quit)
ROLL_B_FILE=$(find "$CONF_REPO1/state/posts/page" -name '*--rollback-beta.md' -print -quit)
ROLL_A_UUID=$(basename "$ROLL_A_FILE" | sed -E 's/--rollback-alpha\.md$//')
ROLL_B_UUID=$(basename "$ROLL_B_FILE" | sed -E 's/--rollback-beta\.md$//')
ROLL_A2=$(wp_conf2 post list --post_type=page --name=rollback-alpha --field=ID | tr -d '[:space:]')
ROLL_B2=$(wp_conf2 post list --post_type=page --name=rollback-beta --field=ID | tr -d '[:space:]')
require_fixture_ids ROLL_A1 ROLL_B1 ROLL_A2 ROLL_B2
wp_conf1 post delete "$ROLL_A1" "$ROLL_B1" --force >/dev/null
wp_conf1 duo capture --repo=/siterepo >/dev/null
git -C "$CONF_REPO1" add -A
git -C "$CONF_REPO1" -c user.name=duo -c user.email=duo@example.test commit -qm 'conformance: transactional deletion pair'
git -C "$CONF_REPO1" push -q origin main
git -C "$CONF_REPO2" pull -q origin main
if [[ "$ROLL_A_UUID" < "$ROLL_B_UUID" ]]; then BLOCK_ID=$ROLL_B2; else BLOCK_ID=$ROLL_A2; fi
wp_conf2 db query 'DROP TABLE IF EXISTS wp_duo_delete_block' >/dev/null
wp_conf2 db query 'CREATE TABLE wp_duo_delete_block (post_id bigint(20) unsigned NOT NULL PRIMARY KEY, CONSTRAINT duo_delete_block_fk FOREIGN KEY (post_id) REFERENCES wp_posts(ID)) ENGINE=InnoDB' >/dev/null
wp_conf2 db query "INSERT INTO wp_duo_delete_block (post_id) VALUES ($BLOCK_ID)" >/dev/null
ROLL_RC=0
ROLL_OUT=$(wp_conf2 duo apply --repo=/siterepo --with-deletes --default-author=admin 2>&1) || ROLL_RC=$?
# DUO-3391: this assertion is satisfied by ANY non-zero exit, so a
# compose-layer death passes it VACUOUSLY — and the rollback assertions that
# follow then also pass, because a delete that never ran leaves both pages
# exactly where the rollback proof expects to find them. Assert the answer
# exists so a dead invocation reports itself instead of reporting green.
require_duo_answered "conf2 duo apply --with-deletes (injected FK rollback probe)" human "$ROLL_OUT"
[ "$ROLL_RC" -ne 0 ] || fail "injected second-row deletion failure unexpectedly applied"
# The two post-conditions below read the TARGET, not $ROLL_OUT, so the broad
# answered-marker above cannot protect them. Pasting the apply capture makes
# a wp-cli-framed infrastructure error in the APPLY self-identify in the
# sweep log; a docker-layer death of the `wp post list` reads themselves
# remains diagnosable only by the pasted capture being healthy while the id
# comes back empty.
[ "$(wp_conf2 post list --post_type=page --name=rollback-alpha --field=ID | tr -d '[:space:]')" = "$ROLL_A2" ] \
  || fail "partial deletion failure did not roll back the first page: $ROLL_OUT"
[ "$(wp_conf2 post list --post_type=page --name=rollback-beta --field=ID | tr -d '[:space:]')" = "$ROLL_B2" ] \
  || fail "partial deletion failure lost the blocked page: $ROLL_OUT"
wp_conf2 db query 'DROP TABLE wp_duo_delete_block' >/dev/null
wp_conf2 duo apply --repo=/siterepo --with-deletes --default-author=admin --format=json >/dev/null
[ -z "$(wp_conf2 post list --post_type=page --name=rollback-alpha --field=ID)" ] \
  && [ -z "$(wp_conf2 post list --post_type=page --name=rollback-beta --field=ID)" ] \
  || fail "transactional deletion pair did not complete after removing injected failure"
pass "partial delete failure rolls the transaction back; retry completes exactly"

# Clear environment-bound history to simulate a fresh target. Tombstones
# remain `deleted`, never reinterpret absence through ledger history.
TOMBSTONES=$(find "$CONF_REPO1/state/deletions" -type f -name '*.json' | wc -l | tr -d '[:space:]')
wp_conf2 db query 'TRUNCATE TABLE wp_duo_map; TRUNCATE TABLE wp_duo_state' >/dev/null
FRESH_PLAN=$(wp_conf2 duo plan --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
jq -e --argjson count "$TOMBSTONES" '(.deleted | length) == $count and (.delete | length) == 0 and (.delete_conflict | length) == 0' \
  <<<"$FRESH_PLAN" >/dev/null || fail "fresh target interpreted repository deletion intent differently: $FRESH_PLAN"
pass "fresh and previously mapped targets make the same repository-level deletion decision"
