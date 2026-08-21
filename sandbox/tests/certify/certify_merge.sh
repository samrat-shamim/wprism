#!/usr/bin/env bash
# Certify merge (DUO-3228): permanentizes sandbox/tests/spike/spike_b_merge.sh's
# divergent-edit + genuine-conflict + resolve + both-environments-converge
# flow as a first-class, re-runnable, conformance-style clean-room
# certification. Entity-level git merge is the product's core bet
# (DESIGN.md §3.4 / adversarial-review-confirmed decision #2: "the merge
# path is exactly the market gap") and, before this fixture, had zero
# ongoing regression protection: spike_b_merge.sh proved it once per grind
# round, by hand, against the legacy docker-compose.yml stack, and nothing
# re-ran it afterward.
#
# Gate posture (commit 1efb6df, owner decision, 2026-08-06): the merge
# gate is LOCAL conformance evidence, not CI — .github/workflows/
# conformance.yml is currently disabled by owner decision. This script IS
# that local evidence: a Makefile target (`make certify-merge`), not a CI
# job. Placement is sandbox/tests/, not sandbox/conformance/: run.sh's
# loop is a single-manifest, single-promotion (conf1->conf2) shape driven
# by conformance/manifests.json+seeds/+checks/; this fixture needs TWO
# long-lived branches per environment and a second manifest bolted on
# partway through, which doesn't fit that per-manifest contract without
# distorting it. See the PR for the fuller placement argument.
#
# Two parts, one pair.sh pair (own dedicated pair, "mergecert" — never the
# legacy spike stack spike_b_merge.sh itself uses):
#   PART 1 — core entities only (page/post). Manifest-agnostic in spirit:
#     nothing here depends on any plugin. This is spike_b_merge.sh's own
#     About/Hello-Duo/Team scenario, clean-roomed onto pair.sh.
#   PART 2 — EXTENSION: a typed-snapshot TABLE entity conflict (task #75's
#     grammar, spec/repo-format.md's "Custom tables" section), reproducing
#     the shape grind round R3-B proved ad hoc (a
#     PMPro membership-price conflict on a table entity's field) on
#     woocommerce_attribute_taxonomies.attribute_label instead — the
#     cheaper typed-snapshot fixture available: 5 scalar columns, ZERO FK
#     refs, natural_key identity (manifests/woocommerce.json), needs only
#     one `wp wc product_attribute create` call — no product, variation,
#     or order required at all.
#
# Explicitly OUT OF SCOPE this round (protocol's "no silent partial
# ships" — see this issue's scope note for where each is re-homed):
#   - add/add same-slug -> semantic-compiler rejection: DUO-3208 (the
#     repository semantic compiler, agent/src/Repository/RepositoryCompiler.php)
#     merged to main DURING this fixture's development (PR #2,
#     436af42c), so the ORIGINAL blocker for this case is gone —
#     RepositoryCompiler::validate_natural_identities() now catches
#     exactly the motivating shape (two branches each add a same-slug
#     post with a different uuid; git merges the two new files clean,
#     no line-level overlap; the compiler's tree-wide identity pass
#     catches what git's line-based merge structurally cannot) and
#     would be cheap to build with core entities alone, no plugin
#     needed. Still deliberately NOT added here: the issue's own
#     Required-behavior bullet 2 names this case as an axis of DUO-3223's
#     adversarial certification matrix specifically, not an extension of
#     THIS fixture — that placement call predates and is independent of
#     DUO-3208's blocked status. Flagging as unblocked-and-ready for
#     whoever picks up DUO-3223 (see PR).
#   - the cross-branch plugin-version-skew workflow is a genuinely separate
#     scenario (two DIFFERENT plugin versions across branches) and has its
#     own fixture: sandbox/tests/certify/certify_version_skew_merge.sh
#     (`make certify-version-skew-merge`). It went UNCOVERED for one round
#     when #478 deleted the duo-loop-demo manifest its original fixture was
#     built on, and DUO-3487 rebuilt it on the retained duo-agency-cpt
#     fixture plugin. It stays a separate file, as it always was: folding it
#     in would distort this one.
#   - conflict-marker leakage into POST BODIES: DUO-3208 also shipped
#     RepositoryCompiler's CONFLICT_RE scan
#     ('/^(<{7}|={7}|>{7})(?: .*|)$/m'), which runs against every state
#     file's RAW BYTES before any JSON/front-matter parsing — so it
#     already covers post bodies (front-matter + raw body live in one
#     .md file) exactly as uniformly as table-entity JSON. There is no
#     longer a real gap here to flag; the NEGATIVE TEST below exercises
#     this mechanism directly (on the table-entity case, cheapest to
#     stage with what Part 2 already built) rather than a separate
#     post-body-specific probe, since it's the same regex over the same
#     raw-byte scan regardless of which file it's scanning.
#
# Negative-path coverage (issue's evidence bar: "one deliberately-broken-
# merge negative test failing loudly") is built into both parts' own
# assertions, not bolted on separately: each part asserts the merge
# ACTUALLY conflicts (git exit != 0) and is scoped to EXACTLY the right
# single entity file — either assertion fails loudly if conflict-detection
# ever silently regressed to an auto-merge. Part 2 additionally runs an
# explicit NEGATIVE TEST proving the PRODUCT PATH itself (`wp duo plan`,
# not just the advisory `wp duo lint`) refuses an entity left with
# unresolved conflict markers via RepositoryCompiler's own
# `conflict_marker` diagnostic — see that section below.
#
# Cleanup: the mergecert pair is destroyed only on a fully green run
# (last line of this script) — the "destroy-when-green" convention
# (docs/sandbox.md), implemented here simply by placing the destroy call
# last under `set -euo pipefail`: any earlier `fail` aborts before it's
# reached, so a failing run leaves the pair up for inspection, matching
# this session's own established practice of not tearing down evidence a
# human might want to look at.
set -euo pipefail
cd "$(dirname "$0")/../.."   # -> sandbox/

say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }
. conformance/asserts.sh

# Dedicated, descriptively-named pair (not tied to any one agent/session)
# so any future re-run picks the same identity. Ports chosen from the free
# range above every pair already in this sandbox as of 2026-08 (8801-8819,
# 8840/8841, 8850-8855, 8870/8871, 8888) — override via env if that ever
# changes. Headless: this is a data-layer certification exactly like
# spike_b_merge.sh (git + wp-cli only) — no render check needs an HTTP
# port, so publishing one would just be one more thing to collide over.
PORT1="${MERGECERT_PORT1:-8860}"
PORT2="${MERGECERT_PORT2:-8861}"
COMPOSE="docker compose -p duo-mergecert -f pair.yml"
export DUO_PAIR=mergecert
wp1() { $COMPOSE run --rm -T cli1 wp "$@"; }
wp2() { $COMPOSE run --rm -T cli2 wp "$@"; }
GIT_A="git -C siterepo/mergecert1 -c user.name=duo-a -c user.email=a@example.test"
GIT_B="git -C siterepo/mergecert2 -c user.name=duo-b -c user.email=b@example.test"

command -v jq >/dev/null || fail "jq required"

say "clean-room via pair.sh (own pair, isolated from every other stack — never the legacy spike_b_merge.sh compose file)"
bash bin/pair.sh reset mergecert
bash bin/pair.sh up mergecert "$PORT1" "$PORT2" --headless

say "install + activate WooCommerce on both sides (Part 2's typed-snapshot entity). Both sides symmetric (no files-only/deploy split): this fixture certifies MERGE, not deploy reconciliation, which every ordinary conformance/run.sh pass already exercises"
wp1 plugin install woocommerce --activate >/dev/null
wp2 plugin install woocommerce --activate >/dev/null

say "seed baseline content on mergecert1 (env A): About/Team/Hello Duo (spike_b_merge.sh's own fixture) + one product attribute (Part 2's typed-snapshot entity)"
ABOUT_ID=$(wp1 post create --post_type=page --post_title=About --post_name=about --post_status=publish \
  --post_content='<!-- wp:paragraph --><p>About Duo.</p><!-- /wp:paragraph -->' --porcelain)
TEAM_ID=$(wp1 post create --post_type=page --post_title=Team --post_name=team --post_status=publish \
  --post_content='<!-- wp:paragraph --><p>The Duo team.</p><!-- /wp:paragraph -->' --porcelain)
HELLO_ID=$(wp1 post create --post_type=post --post_title='Hello Duo' --post_name=hello-duo --post_status=publish \
  --post_content='<!-- wp:paragraph --><p>First post.</p><!-- /wp:paragraph -->' --porcelain)
SIZE_ATTR_ID=$(wp1 wc product_attribute create --name='Merge Cert Size' --slug=mergecert-size \
  --type=select --order_by=menu_order --has_archives=false --porcelain --user=admin)
echo "seed: about=$ABOUT_ID team=$TEAM_ID hello=$HELLO_ID attr=$SIZE_ATTR_ID"

say "init site repo (core+woocommerce) and capture the baseline on mergecert1"
git init --bare -b main siterepo/origin-mergecert.git >/dev/null
cat > siterepo/mergecert1/site.duo.json <<'EOF'
{
  "manifests": ["core", "woocommerce"],
  "policy": {
    "options": {},
    "post_meta": {},
    "post_types": ["post", "page", "attachment"],
    "taxonomies": ["category", "post_tag", "product_cat", "product_type"]
  },
  "spec_version": 2
}
EOF
cp site-repo.gitignore.template siterepo/mergecert1/.gitignore
git -C siterepo/mergecert1 init -q -b main
git -C siterepo/mergecert1 remote add origin ../origin-mergecert.git
wp1 duo capture --repo=/siterepo
git -C siterepo/mergecert1 add -A
git -C siterepo/mergecert1 -c user.name=duo -c user.email=duo@example.test commit -qm "baseline: About/Team/Hello Duo + Merge Cert Size attribute"
git -C siterepo/mergecert1 push -qu origin main

say "clone repo for mergecert2 (env B) and converge to the baseline (adopt terms/posts: default category + WooCommerce's own auto-created pages exist independently on both sides)"
git clone -q siterepo/origin-mergecert.git siterepo/mergecert2
wp2 duo apply --repo=/siterepo --adopt-by-slug=terms,posts --default-author=admin --format=json | tail -1 | jq .

ABOUT_ID_B=$(wp2 post list --post_type=page --name=about --field=ID)
TEAM_ID_B=$(wp2 post list --post_type=page --name=team --field=ID)
HELLO_ID_B=$(wp2 post list --post_type=post --name=hello-duo --field=ID)
SIZE_ATTR_ID_B=$(wp2 db query --skip-column-names "SELECT attribute_id FROM wp_woocommerce_attribute_taxonomies WHERE attribute_name='mergecert-size'" | tr -d '\r')
require_fixture_ids ABOUT_ID_B TEAM_ID_B HELLO_ID_B SIZE_ATTR_ID_B
echo "env B local ids: about=$ABOUT_ID_B team=$TEAM_ID_B hello=$HELLO_ID_B attr=$SIZE_ATTR_ID_B (expected to differ from A's — identity lives in duo_map/the natural-key uuid, never these)"

say "sanity: baseline is byte-identical across environments before any divergence"
wp1 duo capture --repo=/siterepo --out=/siterepo/.tmp-baseline1 >/dev/null
wp2 duo capture --repo=/siterepo --out=/siterepo/.tmp-baseline2 >/dev/null
diff -r siterepo/mergecert1/.tmp-baseline1 siterepo/mergecert2/.tmp-baseline2 || fail "baseline not converged before divergent edits began"
rm -rf siterepo/mergecert1/.tmp-baseline1 siterepo/mergecert2/.tmp-baseline2
pass "baseline converged; both environments start divergence from the identical state"

# ============================================================================
# PART 1 — core entity merge (permanentized spike_b_merge.sh scenario)
# ============================================================================

say "PART 1 — core entity merge: branch edit-a, env A edits About"
$GIT_A checkout -qb edit-a main
wp1 post update "$ABOUT_ID" --post_title='About (A-edit)' >/dev/null
wp1 duo capture --repo=/siterepo >/dev/null
$GIT_A add -A && $GIT_A commit -qm "A: retitle About" && $GIT_A push -q origin edit-a

say "PART 1 — branch edit-b, env B edits Hello Duo AND About (conflicting)"
$GIT_B fetch -q origin && $GIT_B checkout -qb edit-b origin/main
wp2 post update "$HELLO_ID_B" --post_title='Hello Duo (B-edit)' >/dev/null
wp2 post update "$ABOUT_ID_B" --post_title='About (B-edit)' >/dev/null
wp2 duo capture --repo=/siterepo >/dev/null
$GIT_B add -A && $GIT_B commit -qm "B: retitle Hello Duo + About" && $GIT_B push -q origin edit-b

say "PART 1 — env B makes an UNCAPTURED edit (drift)"
wp2 post update "$TEAM_ID_B" --post_title='Team (B-local-drift)' >/dev/null

say "PART 1 — merge both branches in git — NEGATIVE ASSERTION: this MUST conflict"
$GIT_A checkout -q main
$GIT_A merge -q edit-a >/dev/null
set +e
$GIT_A fetch -q origin edit-b
$GIT_A merge origin/edit-b >/dev/null 2>&1
MERGE_RC=$?
set -e
[ "$MERGE_RC" -ne 0 ] || fail "expected a merge conflict on About, merge succeeded — conflict-detection regressed"
CONFLICTS=$($GIT_A status --porcelain | grep '^UU' || true)
echo "$CONFLICTS"
grep -q -- '--about.md' <<<"$CONFLICTS" || fail "conflict is not on the About entity file"
[ "$(echo "$CONFLICTS" | wc -l | tr -d ' ')" = "1" ] || fail "expected exactly one conflicted entity"
grep -q '<<<<<<<' siterepo/mergecert1/state/posts/page/*--about.md || fail "no conflict markers in About file"
pass "conflict surfaced as a plain git conflict, scoped to the About entity; Hello Duo merged clean"

say "PART 1 — resolve the conflict (editorial decision: merged title)"
ABOUT_FILE=$(ls siterepo/mergecert1/state/posts/page/*--about.md)
git -C siterepo/mergecert1 checkout --theirs -- "state/posts/page/$(basename "$ABOUT_FILE")"
sed -i.bak 's/About (B-edit)/About (merged)/' "$ABOUT_FILE" && rm -f "$ABOUT_FILE.bak"
$GIT_A add -A && $GIT_A commit -qm "merge edit-b (About resolved: merged title)" && $GIT_A push -q origin main

say "PART 1 — apply merged main to env A"
$GIT_A pull -q origin main
wp1 duo apply --repo=/siterepo --default-author=admin >/dev/null
ABOUT_TITLE_A=$(wp1 post get "$ABOUT_ID" --field=post_title)
require_observed_nonempty "A About post title after merge" "$ABOUT_TITLE_A"
[ "$ABOUT_TITLE_A" = "About (merged)" ] || fail "A: About title not merged"
HELLO_TITLE_A=$(wp1 post get "$HELLO_ID" --field=post_title)
require_observed_nonempty "A Hello post title after merge" "$HELLO_TITLE_A"
[ "$HELLO_TITLE_A" = "Hello Duo (B-edit)" ] || fail "A: B's Hello edit did not arrive"
pass "env A converged to merged state"

say "PART 1 — apply merged main to env B — drift must surface and be preserved"
$GIT_B checkout -q main 2>/dev/null || $GIT_B checkout -qb main origin/main
$GIT_B pull -q origin main
PLAN=$(wp2 duo plan --repo=/siterepo --format=json | tail -1)
require_duo_answered "env B drift plan" json "$PLAN"
echo "$PLAN" | jq -e '.drift | length == 1' >/dev/null || fail "expected exactly one drift entity in B's plan"
echo "$PLAN" | jq -r '.drift[0].path' | grep -q -- '--team.md' || fail "drift is not the Team entity"
set +e
APPLY_B=$(wp2 duo apply --repo=/siterepo --default-author=admin 2>&1)
APPLY_B_RC=$?
set -e
require_duo_answered "env B apply with preserved local drift" human "$APPLY_B"
echo "$APPLY_B"
[ "$APPLY_B_RC" -ne 0 ] || fail "apply with preserved local drift unexpectedly passed the mandatory post-apply convergence gate"
grep -Fq 'post-apply convergence verification failed' <<<"$APPLY_B" \
  || fail "apply failure did not name the mandatory post-apply convergence gate"
grep -q -- '--team.md' <<<"$APPLY_B" \
  || fail "post-apply convergence failure did not identify the intentionally drifted Team entity"
ABOUT_TITLE_B=$(wp2 post get "$ABOUT_ID_B" --field=post_title)
require_observed_nonempty "B About post title after apply" "$ABOUT_TITLE_B"
[ "$ABOUT_TITLE_B" = "About (merged)" ] || fail "B: About title not merged"
TEAM_TITLE_B=$(wp2 post get "$TEAM_ID_B" --field=post_title)
require_observed_nonempty "B Team post title after apply" "$TEAM_TITLE_B"
[ "$TEAM_TITLE_B" = "Team (B-local-drift)" ] || fail "B: local drift was clobbered"
RETRY_PLAN=$(wp2 duo plan --repo=/siterepo --format=json | tail -1)
require_duo_answered "env B retry plan after preserved drift" json "$RETRY_PLAN"
jq -e '.incomplete_apply | length == 1' <<<"$RETRY_PLAN" >/dev/null \
  || fail "failed convergence verification did not retain the mandatory retry marker"
pass "B applied the non-drifted merge without clobbering Team, then failed closed before a false-green ledger advance"

say "PART 1 — capture-first: fold B's drift into the repo, propagate to A"
wp2 duo capture --repo=/siterepo >/dev/null
$GIT_B add -A && $GIT_B commit -qm "B: capture local Team edit" && $GIT_B push -q origin main
$GIT_A pull -q origin main
wp1 duo apply --repo=/siterepo --default-author=admin >/dev/null
TEAM_TITLE_A=$(wp1 post get "$TEAM_ID" --field=post_title)
require_observed_nonempty "A Team post title after recapture" "$TEAM_TITLE_A"
[ "$TEAM_TITLE_A" = "Team (B-local-drift)" ] || fail "A: Team drift did not propagate after capture"
RETRY_B=$(wp2 duo apply --repo=/siterepo --default-author=admin --format=json | tail -1)
require_duo_answered "B retry apply after capture" json "$RETRY_B"
jq -e '.verification.verifier == "canonical-recapture/v1" and .verification.result == "pass"' <<<"$RETRY_B" >/dev/null \
  || fail "B's capture-first retry did not pass fresh-process canonical verification"
CLEAN_PLAN=$(wp2 duo plan --repo=/siterepo --format=json | tail -1)
require_duo_answered "B clean plan after retry" json "$CLEAN_PLAN"
jq -e '.incomplete_apply | length == 0' <<<"$CLEAN_PLAN" >/dev/null \
  || fail "verified capture-first retry did not clear the incomplete-apply marker"
pass "PART 1 complete: conflict, fail-closed drift preservation, capture-first recovery, and verified propagation all proven"

# ============================================================================
# PART 2 — EXTENSION: typed-snapshot table entity merge
# ============================================================================

say "PART 2 — typed-snapshot table entity merge (woocommerce_attribute_taxonomies.attribute_label — reproduces grind round R3-B's membership-price conflict shape on the cheaper woo-attribute fixture)"

say "PART 2 — branch edit-attr-a: env A relabels the attribute"
$GIT_A checkout -qb edit-attr-a main
# --slug pinned explicitly on BOTH update calls below: confirmed live
# (first attempt at this fixture) that wc-cli's product_attribute update
# silently REGENERATES attribute_name (the natural_key identity column,
# manifests/woocommerce.json's slug_column) from --name when --slug is
# omitted — WooCommerce's own slugify-on-rename behavior. Without pinning
# it, "relabel the same attribute differently" is actually "delete the
# original row, add two DIFFERENT new natural-key rows" (a real add/add
# shape, confirmed via git status: "both deleted" + "added by us" +
# "added by them" on three distinctly-slugged files) — a different,
# legitimate scenario, but not the single-field modify/modify conflict
# this Part is testing. Pinning --slug keeps attribute_name (identity)
# fixed so only attribute_label (the one authored scalar this Part edits)
# diverges between branches.
wp1 wc product_attribute update "$SIZE_ATTR_ID" --name='Compact Size' --slug=mergecert-size --user=admin >/dev/null
wp1 duo capture --repo=/siterepo >/dev/null
$GIT_A add -A && $GIT_A commit -qm "A: relabel Merge Cert Size -> Compact Size" && $GIT_A push -q origin edit-attr-a

say "PART 2 — branch edit-attr-b: env B relabels the SAME attribute differently (conflicting)"
$GIT_B fetch -q origin && $GIT_B checkout -qb edit-attr-b origin/main
wp2 wc product_attribute update "$SIZE_ATTR_ID_B" --name='Small Size' --slug=mergecert-size --user=admin >/dev/null
wp2 duo capture --repo=/siterepo >/dev/null
$GIT_B add -A && $GIT_B commit -qm "B: relabel Merge Cert Size -> Small Size" && $GIT_B push -q origin edit-attr-b

say "PART 2 — merge both branches — NEGATIVE ASSERTION: this MUST conflict, scoped to the ONE table-entity file"
$GIT_A checkout -q main
$GIT_A merge -q edit-attr-a >/dev/null
set +e
$GIT_A fetch -q origin edit-attr-b
$GIT_A merge origin/edit-attr-b >/dev/null 2>&1
MERGE_RC2=$?
set -e
[ "$MERGE_RC2" -ne 0 ] || fail "expected a merge conflict on the attribute's label, merge succeeded — table-entity conflict-detection regressed"
CONFLICTS2=$($GIT_A status --porcelain | grep '^UU' || true)
echo "$CONFLICTS2"
grep -q 'state/tables/woocommerce_attribute_taxonomies/' <<<"$CONFLICTS2" || fail "conflict is not on the attribute table-entity file"
[ "$(echo "$CONFLICTS2" | wc -l | tr -d ' ')" = "1" ] || fail "expected exactly one conflicted entity"
ATTR_FILE=$(ls siterepo/mergecert1/state/tables/woocommerce_attribute_taxonomies/*--mergecert-size.json)
grep -q '<<<<<<<' "$ATTR_FILE" || fail "no conflict markers in the attribute table-entity file"
[ "$(grep -c '"attribute_label"' "$ATTR_FILE")" = "2" ] || fail "expected the conflict scoped to exactly the attribute_label line (both sides' versions present, everything else auto-merged)"
pass "conflict surfaced as a plain git conflict, surgically scoped to the single attribute_label line in the entity-per-file table JSON — the same clean, minimal-diff behavior posts/terms already get, now proven for state/tables/"

say "PART 2 — NEGATIVE TEST: unresolved conflict markers must not be silently ingested (product path, not just advisory lint)"
# Deliberately-broken-merge coverage (issue's evidence bar: "one
# deliberately-broken-merge negative test failing loudly"). The tree is
# CURRENTLY mid-conflict (previous step, never committed) — this probes
# the REAL apply path (`wp duo plan`, read-only) against that exact
# broken state before it gets resolved below. RepositoryCompiler.php
# (DUO-3208) scans every canonical state file's raw bytes for a git
# conflict marker line (CONFLICT_RE) before any JSON/front-matter parsing
# happens — the SAME scan covers post bodies and structured JSON entities
# uniformly, so proving it here (cheapest to stage: Part 2's tree is
# already mid-conflict) certifies the mechanism generally, not just for
# this one table.
set +e
COMPILE_CHECK=$(wp1 duo plan --repo=/siterepo --format=json 2>&1)
COMPILE_CHECK_RC=$?
set -e
require_duo_answered "env A unresolved-conflict plan" json "$COMPILE_CHECK"
echo "$COMPILE_CHECK"
[ "$COMPILE_CHECK_RC" -ne 0 ] || fail "expected wp duo plan to refuse (repository_compilation_failed) on a tree with live conflict markers, got exit 0 — conflict-marker detection regressed"
COMPILE_JSON=$(printf '%s\n' "$COMPILE_CHECK" | tail -1)
printf '%s\n' "$COMPILE_JSON" | jq -e '.error == "repository_compilation_failed"' >/dev/null 2>&1 \
  || fail "expected error=repository_compilation_failed in the refusal payload, got: $COMPILE_JSON"
printf '%s\n' "$COMPILE_JSON" | jq -e '.diagnostics[] | select(.code == "conflict_marker")' >/dev/null 2>&1 \
  || fail "expected a conflict_marker diagnostic in the refusal payload, got: $COMPILE_JSON"
printf '%s\n' "$COMPILE_JSON" | jq -r '.diagnostics[] | select(.code == "conflict_marker") | .path' | grep -q 'woocommerce_attribute_taxonomies' \
  || fail "conflict_marker diagnostic did not name the attribute table-entity file"
pass "wp duo plan refuses loudly on the product path — repository_compilation_failed, code=conflict_marker, naming the exact file — before any target contact is attempted"

say "PART 2 — resolve the conflict (editorial decision, same posture as r3b's split-the-difference)"
git -C siterepo/mergecert1 checkout --theirs -- "state/tables/woocommerce_attribute_taxonomies/$(basename "$ATTR_FILE")"
RESOLVED_LABEL='Compact/Small Size (merged)'
sed -i.bak 's/"attribute_label": "Small Size"/"attribute_label": "Compact\/Small Size (merged)"/' "$ATTR_FILE" && rm -f "$ATTR_FILE.bak"
jq -e . "$ATTR_FILE" >/dev/null || fail "resolved attribute file is not valid JSON"
$GIT_A add -A && $GIT_A commit -qm "merge edit-attr-b (label resolved: merged)" && $GIT_A push -q origin main

say "PART 2 — apply merged main to both environments"
$GIT_A pull -q origin main
wp1 duo apply --repo=/siterepo --default-author=admin >/dev/null
$GIT_B checkout -q main 2>/dev/null || $GIT_B checkout -qb main origin/main
$GIT_B pull -q origin main
wp2 duo apply --repo=/siterepo --default-author=admin >/dev/null

LABEL_A=$(wp1 db query --skip-column-names "SELECT attribute_label FROM wp_woocommerce_attribute_taxonomies WHERE attribute_name='mergecert-size'" | tr -d '\r')
require_observed_nonempty "A WooCommerce attribute label after conflict resolution" "$LABEL_A"
LABEL_B=$(wp2 db query --skip-column-names "SELECT attribute_label FROM wp_woocommerce_attribute_taxonomies WHERE attribute_name='mergecert-size'" | tr -d '\r')
require_observed_nonempty "B WooCommerce attribute label after conflict resolution" "$LABEL_B"
[ "$LABEL_A" = "$RESOLVED_LABEL" ] || fail "A: attribute label not merged (got: $LABEL_A)"
[ "$LABEL_B" = "$RESOLVED_LABEL" ] || fail "B: attribute label not merged (got: $LABEL_B)"
pass "both environments converged on the resolved attribute_label via WooCommerce's own table — typed-snapshot table entity merge proven end to end"

say "PART 2 — final convergence: canonical(A) == canonical(B)"
wp1 duo capture --repo=/siterepo --out=/siterepo/.tmp-final1 >/dev/null
wp2 duo capture --repo=/siterepo --out=/siterepo/.tmp-final2 >/dev/null
diff -r siterepo/mergecert1/.tmp-final1 siterepo/mergecert2/.tmp-final2 || fail "environments did not converge"
rm -rf siterepo/mergecert1/.tmp-final1 siterepo/mergecert2/.tmp-final2
pass "environments byte-identical — PART 2 complete"

say "final hard lint gate on both environments' fully-converged state"
LINT_RC_A=0; LINT_OUT_A=$(wp1 duo lint --repo=/siterepo --format=json) || LINT_RC_A=$?
require_duo_answered "env A final lint" json "$LINT_OUT_A"
LINT_JSON_A=$(printf '%s\n' "$LINT_OUT_A" | tail -1)
printf '%s\n' "$LINT_JSON_A" | jq -e 'type == "array"' >/dev/null 2>&1 || fail "duo lint crashed or produced malformed output on A (exit $LINT_RC_A): $LINT_OUT_A"
[ "$(printf '%s\n' "$LINT_JSON_A" | jq 'length')" = "0" ] || fail "lint found findings on A: $LINT_JSON_A"
LINT_RC_B=0; LINT_OUT_B=$(wp2 duo lint --repo=/siterepo --format=json) || LINT_RC_B=$?
require_duo_answered "env B final lint" json "$LINT_OUT_B"
LINT_JSON_B=$(printf '%s\n' "$LINT_OUT_B" | tail -1)
printf '%s\n' "$LINT_JSON_B" | jq -e 'type == "array"' >/dev/null 2>&1 || fail "duo lint crashed or produced malformed output on B (exit $LINT_RC_B): $LINT_OUT_B"
[ "$(printf '%s\n' "$LINT_JSON_B" | jq 'length')" = "0" ] || fail "lint found findings on B: $LINT_JSON_B"
pass "lint clean on both environments' final converged state"

printf '\n\033[1;32m✔ CERTIFY MERGE PASSED (core entities + typed-snapshot table entity)\033[0m\n'

say "cleanup: destroy the mergecert pair (green run — 'destroy-when-green' convention, docs/sandbox.md; unreached on any earlier failure, which is the point)"
bash bin/pair.sh destroy mergecert
pass "mergecert pair destroyed"
