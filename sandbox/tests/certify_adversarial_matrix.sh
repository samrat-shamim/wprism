#!/usr/bin/env bash
# Certify adversarial matrix (DUO-3223): houses adversarial certification
# cases that don't belong to any single capability's own certification
# fixture (contrast sandbox/tests/certify_merge.sh, which certifies the
# merge capability itself). Two parts, one pair (own dedicated "certmatrix"
# pair, never mergecert or any other agent's):
#
#   PART 1 — add/add same natural-key slug across two independently-
#     diverged branches -> RepositoryCompiler::validate_natural_identities()
#     refuses before any target contact.
#   PART 2 — restored target: an environment whose database (and therefore
#     its identity ledger) was restored from an older backup fails closed
#     on capture rather than silently re-minting identities, and
#     identity-export/identity-import (agent/src/IdentityBackup.php) turns
#     that refusal into a clean, byte-identical continuation.
#
# Provenance, PART 1: DUO-3228's Required-behavior bullet 2 named this case as an
# axis of DUO-3223's adversarial certification matrix specifically (not an
# extension of certify_merge.sh — that placement call predates and is
# independent of DUO-3208's landing status). DUO-3208 (the repository
# semantic compiler) merged to main during DUO-3228's own development,
# which is what makes this case cheap now: RepositoryCompiler.php already
# implements exactly the check this needs
# (validate_natural_identities(), agent/src/RepositoryCompiler.php). This
# script only certifies the existing mechanism against the real product
# path, on core entities alone — no plugin required, matching DUO-3228's
# own sizing note.
#
# Why this is a DIFFERENT case from certify_merge.sh's own conflict
# scenarios: those are two branches EDITING THE SAME EXISTING entity
# (same uuid, same file) — git sees the line-level overlap and produces a
# genuine <<<<<<< conflict. This case is two branches each ADDING A NEW,
# INDEPENDENT entity (different uuid, therefore a different filename) that
# happen to collide on the natural-key tuple WordPress itself would use to
# address them (post_type + slug + parent) — git's line-based merge has no
# way to see this collision at all: two new files, zero line overlap, a
# clean auto-merge. RepositoryCompiler's tree-wide identity pass is the
# only thing that can catch it, which is exactly what this certifies.
#
# TWO real, isolated WordPress environments (own dedicated pair, never
# mergecert or any other agent's pair) — not one environment abused as
# both branches: each side mints its own uuid and owns its own duo_map
# ledger, so the collision is exactly what two genuinely independent
# authors on two genuinely independent installs would produce, and (the
# concrete bug an earlier draft of this script hit) reusing one
# environment sequentially means the SECOND capture would see the FIRST
# branch's page as a live row still present in wp_posts, contaminating
# what's supposed to be an isolated parallel-universe branch. Headless: no
# HTTP render check needed for a repository-level refusal. destroy-when-
# green (docs/sandbox.md convention, mirrored from certify_merge.sh): the
# pair is destroyed only after every assertion below passes, so a failing
# run leaves it up for inspection.
set -euo pipefail
cd "$(dirname "$0")/.."   # -> sandbox/

say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }

command -v jq >/dev/null || fail "jq required"

PORT1="${CERTMATRIX_PORT1:-8862}"
PORT2="${CERTMATRIX_PORT2:-8863}"
COMPOSE="docker compose -p duo-certmatrix -f pair.yml"
export DUO_PAIR=certmatrix
wp1() { $COMPOSE run --rm -T cli1 wp "$@"; }
wp2() { $COMPOSE run --rm -T cli2 wp "$@"; }
GIT_A="git -C siterepo/certmatrix1 -c user.name=duo-a -c user.email=a@example.test"
GIT_B="git -C siterepo/certmatrix2 -c user.name=duo-b -c user.email=b@example.test"

say "clean-room via pair.sh (own pair, isolated — headless, core entities only)"
bash bin/pair.sh reset certmatrix
bash bin/pair.sh up certmatrix "$PORT1" "$PORT2" --headless

say "strip WordPress's own default seed content on both sides (Hello World/Sample Page) — irrelevant to this scenario, only in the way of a clean baseline"
wp1 site empty --yes >/dev/null
wp2 site empty --yes >/dev/null

say "init site repo (core only), empty baseline capture on env A, converge env B to it"
git init --bare -b main siterepo/origin-certmatrix.git >/dev/null
cat > siterepo/certmatrix1/site.duo.json <<'EOF'
{
  "manifests": ["core"],
  "policy": {
    "options": {},
    "post_meta": {},
    "post_types": ["post", "page", "attachment"],
    "taxonomies": ["category", "post_tag"]
  },
  "spec_version": 1
}
EOF
printf '.tmp*\nstate.capture.lock\n' > siterepo/certmatrix1/.gitignore
git -C siterepo/certmatrix1 init -q -b main
git -C siterepo/certmatrix1 remote add origin ../origin-certmatrix.git
wp1 duo capture --repo=/siterepo
$GIT_A add -A && $GIT_A commit -qm "baseline: empty core capture" && $GIT_A push -qu origin main

git clone -q siterepo/origin-certmatrix.git siterepo/certmatrix2
wp2 duo apply --repo=/siterepo --adopt-by-slug=terms --default-author=admin --format=json | tail -1 | jq .
pass "baseline established on env A, env B converged to it"

# ============================================================================
# PART 1 — add/add same-slug across independently-diverged branches
# ============================================================================

say "env A (branch add-a): independently ADD a new page, slug 'shared-slug'"
$GIT_A checkout -qb add-a main
PAGE_A=$(wp1 post create --post_type=page --post_title='Shared Slug (A)' --post_name=shared-slug --post_status=publish \
  --post_content='<!-- wp:paragraph --><p>Added independently on branch A.</p><!-- /wp:paragraph -->' --porcelain)
wp1 duo capture --repo=/siterepo >/dev/null
$GIT_A add -A && $GIT_A commit -qm "A: add page shared-slug" && $GIT_A push -q origin add-a
PAGE_A_FILE=$(ls siterepo/certmatrix1/state/posts/page/*--shared-slug.md)
PAGE_A_UUID=$(basename "$PAGE_A_FILE" | sed -E 's/--shared-slug\.md$//')
echo "env A: page=$PAGE_A uuid=$PAGE_A_UUID file=$(basename "$PAGE_A_FILE")"

say "env B (branch add-b, off origin/main — never sees add-a): independently ADD a DIFFERENT new page, ALSO slug 'shared-slug' — a genuinely different uuid, a genuinely separate environment, no collision on B's own install (it has never heard of A's page)"
$GIT_B fetch -q origin
$GIT_B checkout -qb add-b origin/main
PAGE_B=$(wp2 post create --post_type=page --post_title='Shared Slug (B)' --post_name=shared-slug --post_status=publish \
  --post_content='<!-- wp:paragraph --><p>Added independently on branch B.</p><!-- /wp:paragraph -->' --porcelain)
wp2 duo capture --repo=/siterepo >/dev/null
$GIT_B add -A && $GIT_B commit -qm "B: add page shared-slug" && $GIT_B push -q origin add-b
PAGE_B_FILE=$(ls siterepo/certmatrix2/state/posts/page/*--shared-slug.md)
PAGE_B_UUID=$(basename "$PAGE_B_FILE" | sed -E 's/--shared-slug\.md$//')
echo "env B: page=$PAGE_B uuid=$PAGE_B_UUID file=$(basename "$PAGE_B_FILE")"
[ "$PAGE_A_UUID" != "$PAGE_B_UUID" ] || fail "test setup bug: A and B minted the same uuid, this must be two DISTINCT entities"

say "merge add-a and add-b (on env A's repo) — POSITIVE ASSERTION: git itself sees NO conflict (two new files, zero line overlap — this is the whole point: git structurally cannot see this collision)"
$GIT_A checkout -q main
$GIT_A merge -q add-a >/dev/null
$GIT_A fetch -q origin add-b
$GIT_A merge origin/add-b >/dev/null \
  || fail "expected a clean git auto-merge (two distinct new files) — if git itself now conflicts here, this scenario's premise (colliding natural key, non-colliding file paths) no longer holds and this test needs redesigning, not silencing"
[ -f "siterepo/certmatrix1/state/posts/page/${PAGE_A_UUID}--shared-slug.md" ] || fail "A's shared-slug file missing after merge"
[ -f "siterepo/certmatrix1/state/posts/page/${PAGE_B_UUID}--shared-slug.md" ] || fail "B's shared-slug file missing after merge"
$GIT_A push -q origin main
pass "git auto-merged cleanly — both entity files present, no line-level conflict, no <<<<<<< markers anywhere"

say "THE ACTUAL CASE: wp duo plan against the merged tree — RepositoryCompiler must refuse before any target contact"
set +e
PLAN_OUT=$(wp1 duo plan --repo=/siterepo --format=json 2>&1)
PLAN_RC=$?
set -e
echo "$PLAN_OUT"
[ "$PLAN_RC" -ne 0 ] || fail "expected wp duo plan to refuse (repository_compilation_failed) on a tree with two same-slug pages, got exit 0 — duplicate_natural_identity detection regressed or never ran"
PLAN_JSON=$(printf '%s\n' "$PLAN_OUT" | tail -1)
printf '%s\n' "$PLAN_JSON" | jq -e '.error == "repository_compilation_failed"' >/dev/null 2>&1 \
  || fail "expected error=repository_compilation_failed in the refusal payload, got: $PLAN_JSON"
printf '%s\n' "$PLAN_JSON" | jq -e '.diagnostics[] | select(.code == "duplicate_natural_identity")' >/dev/null 2>&1 \
  || fail "expected a duplicate_natural_identity diagnostic in the refusal payload, got: $PLAN_JSON"
DIAG=$(printf '%s\n' "$PLAN_JSON" | jq -c '.diagnostics[] | select(.code == "duplicate_natural_identity")')
echo "$DIAG"
DIAG_PATH=$(printf '%s' "$DIAG" | jq -r '.path')
DIAG_RELATED=$(printf '%s' "$DIAG" | jq -r '.related_path')
DIAG_LOCATOR=$(printf '%s' "$DIAG" | jq -r '.locator')
[ "$DIAG_LOCATOR" = "slug" ] || fail "expected locator=slug, got: $DIAG_LOCATOR"
# Order is insertion/tree-traversal order, not asserted which of A/B lands
# in 'path' vs 'related_path' — only that the pair is EXACTLY {A,B}.
FOUND_PAIR=$(printf '%s\n%s\n' "$DIAG_PATH" "$DIAG_RELATED" | sort)
EXPECT_PAIR=$(printf 'posts/page/%s--shared-slug.md\nposts/page/%s--shared-slug.md\n' "$PAGE_A_UUID" "$PAGE_B_UUID" | sort)
[ "$FOUND_PAIR" = "$EXPECT_PAIR" ] || fail "diagnostic did not name exactly the two colliding entity files (got: $FOUND_PAIR, expected: $EXPECT_PAIR)"
pass "wp duo plan refuses loudly on the product path — repository_compilation_failed, code=duplicate_natural_identity, locator=slug, naming both colliding entity files exactly — before any target contact is attempted"

say "sanity: this is a repository-level refusal, not an environment-level one — the refused plan did not scramble either page's already-captured ledger mapping on its own owning environment"
LOCAL_A=$(wp1 db query --skip-column-names "SELECT local_id FROM wp_duo_map WHERE uuid='$PAGE_A_UUID'" 2>/dev/null | tr -d '\r')
LOCAL_B=$(wp2 db query --skip-column-names "SELECT local_id FROM wp_duo_map WHERE uuid='$PAGE_B_UUID'" 2>/dev/null | tr -d '\r')
[ "$LOCAL_A" = "$PAGE_A" ] || fail "A's uuid maps to local_id $LOCAL_A on its own environment after the refused plan, expected $PAGE_A"
[ "$LOCAL_B" = "$PAGE_B" ] || fail "B's uuid maps to local_id $LOCAL_B on its own environment after the refused plan, expected $PAGE_B"
pass "no partial/incorrect ledger state resulted from the refused plan — both uuids still map to their own, correct, already-captured local ids"

pass "PART 1 complete: add/add same-slug -> duplicate_natural_identity"

say "PART 1 -> PART 2 handoff: resolve the still-colliding merge (editorial decision: keep A's page, drop B's — B's page never existed on env A's own live WordPress anyway, it was only ever real on env B) so PART 2 starts from a genuinely valid repository, same posture as certify_merge.sh resolving its own conflicts between parts"
git -C siterepo/certmatrix1 rm -q "state/posts/page/${PAGE_B_UUID}--shared-slug.md"
$GIT_A commit -qm "resolve PART 1: keep A's shared-slug page, drop B's (editorial)" && $GIT_A push -q origin main
wp1 duo capture --repo=/siterepo >/dev/null || fail "capture still refuses after resolving the collision — resolution did not take"
pass "PART 1's collision resolved; env A's repo is valid again"

# ============================================================================
# PART 2 — restored target: identity-export/identity-import disaster recovery
# ============================================================================
#
# DUO-3223's own acceptance criteria name "fresh/mapped/restored target" as
# one axis of the certification matrix. "Fresh" and "mapped" are the two
# ordinary states every conformance/run.sh pass already exercises (a target
# with no prior ledger vs. one that's already converged). "Restored" is
# the third, adversarial one: an environment whose DATABASE was restored
# from an older backup, but whose wp_duo_map/wp_duo_state identity ledger
# — which lives IN that same database — reverted right along with it,
# while the live content rows it should be mapping may be NEWER (a capture
# taken after the backup, before the disaster). Snapshot.php's own
# assert_mapped_history_present() docblock names this exact shape ("That
# shape is indistinguishable from a database restore without its identity
# sidecar, so capture fails closed and asks for recovery") — this proves
# BOTH halves of that sentence: the fail-closed refusal (adversarial —
# proven first, not assumed), and the recovery path
# (identity-export/identity-import, agent/src/IdentityBackup.php) that
# turns the refusal into a clean, byte-identical continuation.
#
# MUST be a typed-snapshot TABLE entity (class: authored_snapshot,
# identity.mode: mapped — a WooCommerce shipping-zone location, the same
# fixture DUO-3246 used earlier this session), not an ordinary post: an
# earlier draft of this script tried this with a plain page and capture
# came back clean (exit 0) even after truncating duo_map/duo_state — a
# real, load-bearing distinction, not a test bug to route around.
# assert_mapped_history_present() (Snapshot.php) only iterates
# row_tables($policy); a post carries its own identity independently, in
# postmeta (_duo_uuid, spec/repo-format.md's "Identity" section) — capture
# just re-discovers it from the live row and re-populates duo_map, which
# is genuinely fine, not a bug. A table row has no such sidecar of its
# own: duo_map IS its only identity record, which is exactly why
# assert_mapped_history_present() exists at all and why it's scoped to
# row_tables() specifically.
#
# Reuses env A (certmatrix1/wp1) from PART 1 above — same pair, no new
# containers — since this is a single-environment disaster/recovery drill,
# not a two-environment convergence scenario. Installs WooCommerce here
# (PART 1 was deliberately core-only; this axis has no core-only
# equivalent by construction, see above).

say "PART 2 — install WooCommerce on env A, pin it into the repo alongside core"
wp1 plugin install woocommerce --activate >/dev/null
cat > siterepo/certmatrix1/site.duo.json <<'EOF'
{
  "manifests": ["core", "woocommerce"],
  "policy": {
    "options": {},
    "post_meta": {},
    "post_types": ["post", "page", "attachment", "product", "product_variation", "shop_coupon"],
    "taxonomies": ["category", "post_tag", "product_cat", "product_type"]
  },
  "spec_version": 1
}
EOF
$GIT_A checkout -q main
$GIT_A add -A && $GIT_A commit -qm "pin woocommerce alongside core (PART 2 fixture)" && $GIT_A push -q origin main

say "PART 2 — seed a shipping-zone location (typed-snapshot table row, identity.mode=mapped) and capture — this is the state the identity export below will be taken FROM"
ZONE_ID=$(wp1 wc shipping_zone create --name='Restore Drill Zone' --order=1 --user=admin --porcelain)
wp1 eval "\$z = new WC_Shipping_Zone($ZONE_ID); \$z->add_location('US', 'country'); \$z->save();" >/dev/null
wp1 duo capture --repo=/siterepo >/dev/null
$GIT_A add -A && $GIT_A commit -qm "A: seed shipping-zone location" && $GIT_A push -q origin main
LOC_FILE=$(ls siterepo/certmatrix1/state/tables/woocommerce_shipping_zone_locations/*.json)
# Table-entity filenames are <uuid>--<local_id>.json (mirroring posts'
# <uuid>--<slug>.md, but table rows have no slug) -- strip both the
# extension AND the --<local_id> tail, not just the extension.
LOC_UUID=$(basename "$LOC_FILE" | sed -E 's/--[0-9]+\.json$//')
LOC_LOCAL=$(wp1 db query --skip-column-names "SELECT location_id FROM wp_woocommerce_shipping_zone_locations WHERE zone_id=$ZONE_ID" 2>/dev/null | tr -d '\r')
echo "env A: zone=$ZONE_ID location uuid=$LOC_UUID local_id=$LOC_LOCAL"

say "PART 2 — export the identity ledger (wp duo identity-export) — the disaster-recovery sidecar this drill will restore from"
IDENTITY_BACKUP="siterepo/certmatrix1/.tmp-identity-backup.json"
wp1 duo identity-export --repo=/siterepo --out=/siterepo/.tmp-identity-backup.json
[ -s "$IDENTITY_BACKUP" ] || fail "identity-export produced no output file"
jq -e '.format == "duo-identity-ledger/v1" and (.maps | length) >= 1' "$IDENTITY_BACKUP" >/dev/null \
  || fail "identity-export artifact missing expected format/maps shape"
pass "identity ledger exported ($(jq '.maps | length' "$IDENTITY_BACKUP") mapping(s))"

say "PART 2 — simulate the disaster: the database was restored from an OLDER backup, reverting wp_duo_map/wp_duo_state -- but the live content rows (from the capture just above) remain, newer than that backup"
wp1 db query "TRUNCATE TABLE wp_duo_map"
wp1 db query "TRUNCATE TABLE wp_duo_state"
[ -z "$(wp1 db query --skip-column-names "SELECT uuid FROM wp_duo_map WHERE uuid='$LOC_UUID'" 2>/dev/null)" ] \
  || fail "test setup bug: LOC_UUID still present in duo_map after truncate"

say "PART 2 — THE ADVERSARIAL CASE, proven FIRST: capture must fail closed, not silently mint a replacement uuid for a table row whose ledger history just disappeared"
set +e
LOST_OUT=$(wp1 duo capture --repo=/siterepo 2>&1)
LOST_RC=$?
set -e
echo "$LOST_OUT"
[ "$LOST_RC" -ne 0 ] || fail "expected capture to refuse on a target with a live table row but no ledger history (a database-restore shape), got exit 0 — assert_mapped_history_present() regressed or never ran"
grep -qi 'mapped identity history is missing' <<<"$LOST_OUT" \
  || fail "refusal did not name the expected cause (mapped identity history missing): $LOST_OUT"
grep -q "$LOC_UUID" <<<"$LOST_OUT" \
  || fail "refusal did not name the specific entity uuid whose history is missing: $LOST_OUT"
[ -z "$(git -C siterepo/certmatrix1 status --porcelain -- state)" ] \
  || fail "the failed capture changed the published state tree — a refusal must never publish a partial/corrupted result"
pass "capture correctly fails closed on lost ledger history, naming the cause and the exact entity, publishing nothing — exactly Snapshot.php's own documented posture for this shape"

say "PART 2 — THE FIX: restore the identity ledger (wp duo identity-import) — this is what turns the refusal above into a clean continuation"
wp1 duo identity-import --repo=/siterepo --in=/siterepo/.tmp-identity-backup.json
RESTORED_LOCAL=$(wp1 db query --skip-column-names "SELECT local_id FROM wp_duo_map WHERE uuid='$LOC_UUID'" 2>/dev/null | tr -d '\r')
[ "$RESTORED_LOCAL" = "$LOC_LOCAL" ] \
  || fail "restored ledger does not map LOC_UUID back to its own real local_id (got: '$RESTORED_LOCAL', expected: $LOC_LOCAL)"
pass "identity ledger restored — the shipping-zone location's uuid maps back to its own real, live local_id"

say "PART 2 — recovery is genuine, not just silent: capture now succeeds cleanly AND is byte-identical to what a capture taken right before the disaster would have produced"
wp1 duo capture --repo=/siterepo --out=/siterepo/.tmp-post-restore >/dev/null \
  || fail "capture still fails after identity-import — restore did not actually fix the ledger"
diff -r siterepo/certmatrix1/state siterepo/certmatrix1/.tmp-post-restore \
  || fail "post-restore capture diverged from the pre-disaster state — identity-import restored a ledger, but not the RIGHT one"
rm -rf siterepo/certmatrix1/.tmp-post-restore siterepo/certmatrix1/.tmp-identity-backup.json
pass "post-restore capture is byte-identical to pre-disaster state — genuine identity continuity, not merely 'no error'"

pass "PART 2 complete: restored-target disaster recovery on a typed-snapshot table entity (fail closed on lost ledger history, then identity-export/identity-import to clean, byte-identical continuation)"

printf '\n\033[1;32m✔ CERTIFY ADVERSARIAL MATRIX PASSED (add/add same-slug; restored-target disaster recovery)\033[0m\n'

say "cleanup: destroy the certmatrix pair (green run — 'destroy-when-green' convention; unreached on any earlier failure)"
bash bin/pair.sh destroy certmatrix
pass "certmatrix pair destroyed"
