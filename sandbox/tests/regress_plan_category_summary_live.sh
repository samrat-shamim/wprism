#!/usr/bin/env bash
# Live regression — DUO-3345 category-summary menu evidence.
#
# The offline projection suite proves the closed schema and the pure count
# arithmetic.  It cannot prove that the count comes from Capture's one MVCC
# observation of a real WordPress menu: canonical capture deliberately sees
# only published managed items, menu reconciliation removes only managed
# items across statuses (including an explicit repository choice after a
# concurrent target change), adoption associates an unmanaged term by
# slug without minting its items, and a menu tombstone deletes every assigned
# nav_menu_item regardless of status or UUID ownership.  Those distinctions
# are exactly what this product-path fixture exercises.
#
# It requires an explicitly named disposable pair and an exact candidate SHA.
# `pair.sh` checks the same SHA before reset/up mutates a database; this
# wrapper additionally refuses a SHA that is not this checkout's HEAD, so the
# invocation cannot accidentally describe one candidate while launching a
# different checkout's harness.  Run it from a clean standalone candidate
# clone, never a linked issue worktree:
#
#   make regress-plan-category-summary-live \
#     PLAN_CATEGORY_SUMMARY_PAIR=codexsma3345 \
#     PLAN_CATEGORY_SUMMARY_PORT1=9060 \
#     PLAN_CATEGORY_SUMMARY_PORT2=9061 \
#     DUO_EXPECTED_SOURCE_SHA="$(git rev-parse HEAD)"
#
# The fixture uses only the shipped core manifest and generic WordPress menu
# APIs.  It carries no plugin-specific engine branch or test-only provider.
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$REPO_ROOT/sandbox"

say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*" >&2; exit 1; }

command -v jq >/dev/null || fail "jq required"
command -v git >/dev/null || fail "git required"

[ -n "${PLAN_CATEGORY_SUMMARY_PAIR:-}" ] \
  || fail "PLAN_CATEGORY_SUMMARY_PAIR is required; choose an owned, unique disposable pair name"
[ -n "${PLAN_CATEGORY_SUMMARY_PORT1:-}" ] \
  || fail "PLAN_CATEGORY_SUMMARY_PORT1 is required; choose an owned even port at or above 8900"
[ -n "${PLAN_CATEGORY_SUMMARY_PORT2:-}" ] \
  || fail "PLAN_CATEGORY_SUMMARY_PORT2 is required; it must be PLAN_CATEGORY_SUMMARY_PORT1 + 1"
[ -n "${DUO_EXPECTED_SOURCE_SHA:-}" ] \
  || fail "DUO_EXPECTED_SOURCE_SHA is required; set it to git rev-parse HEAD in the standalone candidate clone"

PAIR="$PLAN_CATEGORY_SUMMARY_PAIR"
PORT1="$PLAN_CATEGORY_SUMMARY_PORT1"
PORT2="$PLAN_CATEGORY_SUMMARY_PORT2"

[[ "$PAIR" =~ ^[a-z][a-z0-9]*$ ]] \
  || fail "PAIR '$PAIR' invalid; pair.sh names use lowercase letters/digits and start with a letter"
[[ "$PORT1" =~ ^[0-9]+$ && "$PORT2" =~ ^[0-9]+$ ]] \
  || fail "ports must be decimal integers"
PORT1_NUM=$((10#$PORT1))
PORT2_NUM=$((10#$PORT2))
[ "$PORT1_NUM" -ge 8900 ] && [ "$PORT1_NUM" -le 65534 ] \
  || fail "PLAN_CATEGORY_SUMMARY_PORT1 must be an even port from 8900 through 65534 (got $PORT1)"
[ $((PORT1_NUM % 2)) -eq 0 ] \
  || fail "PLAN_CATEGORY_SUMMARY_PORT1 must be even (got $PORT1)"
[ "$PORT2_NUM" -eq $((PORT1_NUM + 1)) ] \
  || fail "PLAN_CATEGORY_SUMMARY_PORT2 must equal PLAN_CATEGORY_SUMMARY_PORT1 + 1 (got $PORT1/$PORT2)"

[[ "$DUO_EXPECTED_SOURCE_SHA" =~ ^[0-9A-Fa-f]{7,40}$ ]] \
  || fail "DUO_EXPECTED_SOURCE_SHA must be a 7-40 character commit SHA"
EXPECTED_SHA="$(git -C "$REPO_ROOT" rev-parse --verify "${DUO_EXPECTED_SOURCE_SHA}^{commit}" 2>/dev/null)" \
  || fail "DUO_EXPECTED_SOURCE_SHA does not resolve in this checkout: $DUO_EXPECTED_SOURCE_SHA"
HEAD_SHA="$(git -C "$REPO_ROOT" rev-parse HEAD)"
[ "$EXPECTED_SHA" = "$HEAD_SHA" ] \
  || fail "DUO_EXPECTED_SOURCE_SHA resolves to $EXPECTED_SHA, but this harness checkout is $HEAD_SHA; run from the exact candidate clone"

export DUO_PAIR="$PAIR" DUO_PORT1="$PORT1" DUO_PORT2="$PORT2" DUO_EXPECTED_SOURCE_SHA="$EXPECTED_SHA"
COMPOSE=(docker compose -p "duo-$PAIR" -f pair.yml)
SITE1="siterepo/${PAIR}1"
SITE2="siterepo/${PAIR}2"
ORIGIN="siterepo/origin-${PAIR}.git"

wp1() { "${COMPOSE[@]}" run --rm -T cli1 wp "$@"; }
wp2() { "${COMPOSE[@]}" run --rm -T cli2 wp "$@"; }
wp_side() {
  local side="$1"
  shift
  case "$side" in
    1) wp1 "$@" ;;
    2) wp2 "$@" ;;
    *) fail "internal fixture error: unknown side '$side'" ;;
  esac
}

assert_positive_id() {
  local label="$1" value="$2"
  [[ "$value" =~ ^[1-9][0-9]*$ ]] \
    || fail "fixture manufacture failed: $label did not return a positive WordPress id (got '${value:-empty}')"
}

assert_uuid() {
  local label="$1" value="$2"
  [[ "$value" =~ ^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$ ]] \
    || fail "fixture manufacture failed: $label did not return a canonical UUID (got '${value:-empty}')"
}

item_shape() { # item_shape <side> <post-id> -> status|uuid, or missing
  local side="$1" id="$2" out
  assert_positive_id "item_shape id" "$id"
  out="$(wp_side "$side" eval "
global \$wpdb;
\$post = \$wpdb->get_row(\$wpdb->prepare(
    \"SELECT post_status FROM {\$wpdb->posts} WHERE ID = %d LIMIT 1\", $id
));
if (!\$post) {
    echo 'missing';
} else {
    \$uuid = \$wpdb->get_var(\$wpdb->prepare(
        \"SELECT meta_value FROM {\$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_duo_uuid' ORDER BY meta_id ASC LIMIT 1\", $id
    ));
    echo \$post->post_status . '|' . (\$uuid === null ? '' : \$uuid);
}
")" || fail "fixture manufacture failed: could not read menu item $id on side $side: $out"
  printf '%s\n' "${out//$'\r'/}"
}

menu_item_count() { # menu_item_count <side> <menu-term-id>
  local side="$1" term_id="$2" out
  assert_positive_id "menu_item_count term id" "$term_id"
  out="$(wp_side "$side" eval "
global \$wpdb;
\$tt = (int) \$wpdb->get_var(\$wpdb->prepare(
    \"SELECT term_taxonomy_id FROM {\$wpdb->term_taxonomy} WHERE term_id = %d AND taxonomy = 'nav_menu' LIMIT 1\", $term_id
));
echo (int) \$wpdb->get_var(\$wpdb->prepare(
    \"SELECT COUNT(*) FROM {\$wpdb->posts} p JOIN {\$wpdb->term_relationships} tr ON tr.object_id = p.ID WHERE tr.term_taxonomy_id = %d AND p.post_type = 'nav_menu_item'\", \$tt
));
")" || fail "fixture manufacture failed: could not count menu items for term $term_id on side $side: $out"
  printf '%s\n' "${out//$'\r'/}"
}

menu_term_uuid() { # menu_term_uuid <side> <menu-term-id>
  local side="$1" term_id="$2" out
  assert_positive_id "menu_term_uuid term id" "$term_id"
  out="$(wp_side "$side" eval "echo (string) get_term_meta($term_id, '_duo_uuid', true);")" \
    || fail "fixture manufacture failed: could not read menu identity for term $term_id on side $side: $out"
  printf '%s\n' "${out//$'\r'/}"
}

menu_id_by_slug() { # menu_id_by_slug <side> <known-safe-slug>
  local side="$1" slug="$2" out
  [[ "$slug" =~ ^[a-z0-9-]+$ ]] || fail "internal fixture error: unsafe static menu slug '$slug'"
  out="$(wp_side "$side" eval "
global \$wpdb;
echo (int) \$wpdb->get_var(\$wpdb->prepare(
    \"SELECT t.term_id FROM {\$wpdb->terms} t JOIN {\$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id WHERE t.slug = %s AND tt.taxonomy = 'nav_menu' LIMIT 1\", '$slug'
));
")" || fail "fixture manufacture failed: could not resolve menu '$slug' on side $side: $out"
  printf '%s\n' "${out//$'\r'/}"
}

menu_item_id_by_uuid() { # menu_item_id_by_uuid <side> <uuid>
  local side="$1" uuid="$2" out
  assert_uuid "menu-item lookup UUID" "$uuid"
  out="$(wp_side "$side" eval "
global \$wpdb;
echo (int) \$wpdb->get_var(\$wpdb->prepare(
    \"SELECT p.ID FROM {\$wpdb->posts} p JOIN {\$wpdb->postmeta} pm ON pm.post_id = p.ID WHERE p.post_type = 'nav_menu_item' AND pm.meta_key = '_duo_uuid' AND pm.meta_value = %s ORDER BY p.ID ASC LIMIT 1\", '$uuid'
));
")" || fail "fixture manufacture failed: could not resolve menu item UUID $uuid on side $side: $out"
  printf '%s\n' "${out//$'\r'/}"
}

assert_shape() { # assert_shape <label> <side> <id> <status> <uuid-or-empty>
  local label="$1" side="$2" id="$3" status="$4" uuid="$5" actual
  actual="$(item_shape "$side" "$id")"
  [ "$actual" = "$status|$uuid" ] \
    || fail "fixture manufacture failed: $label expected $status|$uuid, got $actual"
}

assert_missing() { # assert_missing <label> <side> <id>
  local label="$1" side="$2" id="$3" actual
  actual="$(item_shape "$side" "$id")"
  [ "$actual" = "missing" ] \
    || fail "$label was not deleted; expected missing, got $actual"
}

assert_count() { # assert_count <label> <side> <menu-id> <expected>
  local label="$1" side="$2" menu_id="$3" expected="$4" actual
  actual="$(menu_item_count "$side" "$menu_id")"
  [ "$actual" = "$expected" ] \
    || fail "fixture manufacture failed: $label expected $expected assigned nav_menu_item rows, got $actual"
}

menu_file_by_slug() { # menu_file_by_slug <repo-root> <slug>
  local repo="$1" slug="$2" file
  local -a matches=()
  [ -d "$repo/state/menus" ] || fail "fixture manufacture failed: $repo has no captured state/menus directory"
  for file in "$repo"/state/menus/*.json; do
    [ -e "$file" ] || continue
    if jq -e --arg slug "$slug" '.slug == $slug' "$file" >/dev/null; then
      matches+=("$file")
    fi
  done
  [ "${#matches[@]}" -eq 1 ] \
    || fail "fixture manufacture failed: expected one captured menu for slug '$slug', found ${#matches[@]}"
  printf '%s\n' "${matches[0]}"
}

tombstone_file_by_uuid() { # tombstone_file_by_uuid <repo-root> <uuid>
  local repo="$1" uuid="$2" file
  local -a matches=()
  [ -d "$repo/state/deletions" ] || fail "fixture manufacture failed: $repo has no deletion directory"
  for file in "$repo"/state/deletions/*.json; do
    [ -e "$file" ] || continue
    if jq -e --arg uuid "$uuid" '.uuid == $uuid' "$file" >/dev/null; then
      matches+=("$file")
    fi
  done
  [ "${#matches[@]}" -eq 1 ] \
    || fail "fixture manufacture failed: expected one tombstone for $uuid, found ${#matches[@]}"
  printf '%s\n' "${matches[0]}"
}

menu_item_uuid_from_file() { # menu_item_uuid_from_file <file> <title>
  local file="$1" title="$2" uuid
  uuid="$(jq -r --arg title "$title" '[.items[] | select(.title == $title) | .uuid] | if length == 1 then .[0] else empty end' "$file")"
  assert_uuid "captured menu item '$title'" "$uuid"
  printf '%s\n' "$uuid"
}

duo_json() { # duo_json <label> <duo-subcommand-and-arguments...>
  local label="$1" out rc=0 json
  shift
  out="$(wp2 duo "$@" --format=json 2>&1)" || rc=$?
  [ "$rc" -eq 0 ] || fail "$label did not return a successful Duo JSON response (exit $rc): $out"
  json="$(tail -n 1 <<<"$out")"
  jq -e . <<<"$json" >/dev/null \
    || fail "$label did not emit a final JSON document: $out"
  printf '%s\n' "$json"
}

duo_human() { # duo_human <label> <duo-subcommand-and-arguments...>
  local label="$1" out rc=0
  shift
  out="$(wp2 duo "$@" 2>&1)" || rc=$?
  [ "$rc" -eq 0 ] || fail "$label did not return a successful Duo human response (exit $rc): $out"
  printf '%s\n' "$out"
}

assert_summary_candidates() { # assert_summary_candidates <label> <json> <expected>
  local label="$1" json="$2" expected="$3"
  jq -e --argjson expected "$expected" '
    .category_summary.format == "duo-plan-category-summary/v1"
    and ([.category_summary.categories[]
          | select(.id == "deletions")
          | .metrics.nested_menu_item_delete_candidates] == [$expected])
  ' <<<"$json" >/dev/null \
    || fail "$label did not report exactly $expected nested menu deletion candidates: $json"
}

assert_plan_menu_bucket() { # assert_plan_menu_bucket <label> <json> <bucket> <menu-uuid>
  local label="$1" json="$2" bucket="$3" uuid="$4"
  jq -e --arg bucket "$bucket" --arg uuid "$uuid" '
    ([.[$bucket][] | select(.uuid == $uuid and .type == "menu")] | length) == 1
  ' <<<"$json" >/dev/null \
    || fail "$label did not carry menu $uuid in .$bucket exactly once: $json"
}

publish_source_update() { # publish_source_update <commit-message>
  local message="$1"
  git -C "$SITE1" add -A
  git -C "$SITE1" commit -qm "$message"
  git -C "$SITE1" push -q origin main
  git -C "$SITE2" pull -q --ff-only origin main \
    || fail "target repository could not fast-forward to source revision '$message'"
}

cleanup() {
  # Exact pair/root ownership is established above from required inputs.  Do
  # not leave containers, schemas, or disposable site repositories behind on
  # either success or failure; pair.sh destroy intentionally ignores the
  # candidate gate so cleanup is never blocked by a changed checkout.
  "${COMPOSE[@]}" run --rm -T --user root cli1 sh -c 'chmod -R a+rwX /siterepo' >/dev/null 2>&1 || true
  "${COMPOSE[@]}" run --rm -T --user root cli2 sh -c 'chmod -R a+rwX /siterepo' >/dev/null 2>&1 || true
  bash bin/pair.sh destroy "$PAIR" >/dev/null 2>&1 || true
  rm -rf -- "$SITE1" "$SITE2" "$ORIGIN"
}
trap cleanup EXIT

RECON_NAME='DUO 3345 Reconcile'
RECON_SLUG='duo-3345-reconcile'
RECON_TITLE_V1='DUO 3345 Reconcile Desired v1'
RECON_TITLE_V2='DUO 3345 Reconcile Desired v2'
RECON_URL='https://example.invalid/duo-3345/reconcile'
ADOPT_NAME='DUO 3345 Adopt'
ADOPT_SLUG='duo-3345-adopt'
ADOPT_TITLE='DUO 3345 Adopt Desired'
ADOPT_URL='https://example.invalid/duo-3345/adopt'

# Each manual UUID is deliberately a valid v4-shaped durable identity.  The
# source-captured UUIDs remain data-derived; these fixtures only establish
# target-only ownership/status shapes before plan observes them.
SOURCE_DRAFT_UUID='11111111-1111-4111-8111-111111111111'
ADOPT_STALE_PUBLISHED_UUID='22222222-2222-4222-8222-222222222222'
ADOPT_STALE_DRAFT_UUID='33333333-3333-4333-8333-333333333333'
NORMAL_STALE_PUBLISHED_UUID='44444444-4444-4444-8444-444444444444'
NORMAL_STALE_DRAFT_UUID='55555555-5555-4555-8555-555555555555'
TOMBSTONE_DRAFT_UUID='66666666-6666-4666-8666-666666666666'

say "candidate-bound clean room ($PAIR, $PORT1/$PORT2, source $EXPECTED_SHA)"
# Both calls inherit DUO_EXPECTED_SOURCE_SHA.  pair.sh verifies the mounted
# agent/manifests source before reset can DROP/CREATE anything and again before
# up creates the pair.
bash bin/pair.sh reset "$PAIR"
bash bin/pair.sh up "$PAIR" "$PORT1" "$PORT2" --headless
pass "own pair is up and candidate-bound"

say "source and target start from real empty WordPress sites"
wp1 site empty --yes >/dev/null
wp2 site empty --yes >/dev/null
git init --bare -q -b main "$ORIGIN"
cat > "$SITE1/site.duo.json" <<'JSON'
{
  "manifests": ["core"],
  "policy": {
    "options": {},
    "post_meta": {},
    "term_meta": {},
    "post_types": ["post", "page", "attachment"],
    "taxonomies": ["category", "post_tag"]
  },
  "spec_version": 2
}
JSON
cp site-repo.gitignore.template "$SITE1/.gitignore"
git -C "$SITE1" init -q -b main
git -C "$SITE1" remote add origin "../origin-${PAIR}.git"
git -C "$SITE1" config user.name duo-category-summary
git -C "$SITE1" config user.email duo-category-summary@example.test
pass "two isolated WordPress databases and a core-only source repository are ready"

say "source capture: published canonical menu item, plus a durable draft item that must stay out of canonical state"
SRC_RECON_TERM_ID="$(wp1 menu create "$RECON_NAME" --porcelain)"
assert_positive_id "source reconcile menu" "$SRC_RECON_TERM_ID"
SRC_RECON_DESIRED_ID="$(wp1 menu item add-custom "$SRC_RECON_TERM_ID" "$RECON_TITLE_V1" "$RECON_URL" --porcelain)"
assert_positive_id "source reconcile published item" "$SRC_RECON_DESIRED_ID"
SRC_RECON_DRAFT_ID="$(wp1 menu item add-custom "$SRC_RECON_TERM_ID" 'DUO 3345 source durable draft' 'https://example.invalid/duo-3345/source-draft' --porcelain)"
assert_positive_id "source reconcile draft item" "$SRC_RECON_DRAFT_ID"
wp1 post meta add "$SRC_RECON_DRAFT_ID" _duo_uuid "$SOURCE_DRAFT_UUID" >/dev/null
wp1 post update "$SRC_RECON_DRAFT_ID" --post_status=draft >/dev/null

SRC_ADOPT_TERM_ID="$(wp1 menu create "$ADOPT_NAME" --porcelain)"
assert_positive_id "source adopt menu" "$SRC_ADOPT_TERM_ID"
SRC_ADOPT_DESIRED_ID="$(wp1 menu item add-custom "$SRC_ADOPT_TERM_ID" "$ADOPT_TITLE" "$ADOPT_URL" --porcelain)"
assert_positive_id "source adopt published item" "$SRC_ADOPT_DESIRED_ID"

wp1 duo capture --repo=/siterepo >/dev/null
RECON_FILE="$(menu_file_by_slug "$SITE1" "$RECON_SLUG")"
ADOPT_FILE="$(menu_file_by_slug "$SITE1" "$ADOPT_SLUG")"
RECON_MENU_UUID="$(jq -r '.uuid' "$RECON_FILE")"
ADOPT_MENU_UUID="$(jq -r '.uuid' "$ADOPT_FILE")"
RECON_DESIRED_UUID="$(menu_item_uuid_from_file "$RECON_FILE" "$RECON_TITLE_V1")"
ADOPT_DESIRED_UUID="$(menu_item_uuid_from_file "$ADOPT_FILE" "$ADOPT_TITLE")"
assert_uuid "captured reconcile menu" "$RECON_MENU_UUID"
assert_uuid "captured adopt menu" "$ADOPT_MENU_UUID"
assert_shape "source durable draft premise" 1 "$SRC_RECON_DRAFT_ID" draft "$SOURCE_DRAFT_UUID"
jq -e --arg title "$RECON_TITLE_V1" --arg draft_uuid "$SOURCE_DRAFT_UUID" '
  (.items | length) == 1
  and .items[0].title == $title
  and ([.items[].uuid | select(. == $draft_uuid)] | length) == 0
' "$RECON_FILE" >/dev/null \
  || fail "published-vs-draft canonical capture regressed: durable draft item leaked into $RECON_FILE"
pass "canonical menu state contains only the published item; the durable draft is a real target observation, not source state"

git -C "$SITE1" add -A
git -C "$SITE1" commit -qm 'capture: DUO-3345 category-summary menu fixture'
git -C "$SITE1" push -qu origin main
git clone -q "$ORIGIN" "$SITE2"

say "adoption premise: an unmanaged same-slug target menu has desired, stale-published, stale-draft, and UUID-less-draft items"
TARGET_ADOPT_TERM_ID="$(wp2 menu create "$ADOPT_NAME" --porcelain)"
assert_positive_id "target unmanaged adopt menu" "$TARGET_ADOPT_TERM_ID"
TARGET_ADOPT_KEEP_ID="$(wp2 menu item add-custom "$TARGET_ADOPT_TERM_ID" "$ADOPT_TITLE" "$ADOPT_URL" --porcelain)"
assert_positive_id "target adoption desired item" "$TARGET_ADOPT_KEEP_ID"
wp2 post meta add "$TARGET_ADOPT_KEEP_ID" _duo_uuid "$ADOPT_DESIRED_UUID" >/dev/null
TARGET_ADOPT_STALE_PUBLISHED_ID="$(wp2 menu item add-custom "$TARGET_ADOPT_TERM_ID" 'DUO 3345 adopt stale published' 'https://example.invalid/duo-3345/adopt-stale-published' --porcelain)"
assert_positive_id "target adoption stale published item" "$TARGET_ADOPT_STALE_PUBLISHED_ID"
wp2 post meta add "$TARGET_ADOPT_STALE_PUBLISHED_ID" _duo_uuid "$ADOPT_STALE_PUBLISHED_UUID" >/dev/null
TARGET_ADOPT_STALE_DRAFT_ID="$(wp2 menu item add-custom "$TARGET_ADOPT_TERM_ID" 'DUO 3345 adopt stale draft' 'https://example.invalid/duo-3345/adopt-stale-draft' --porcelain)"
assert_positive_id "target adoption stale draft item" "$TARGET_ADOPT_STALE_DRAFT_ID"
wp2 post meta add "$TARGET_ADOPT_STALE_DRAFT_ID" _duo_uuid "$ADOPT_STALE_DRAFT_UUID" >/dev/null
wp2 post update "$TARGET_ADOPT_STALE_DRAFT_ID" --post_status=draft >/dev/null
TARGET_ADOPT_UNMANAGED_DRAFT_ID="$(wp2 menu item add-custom "$TARGET_ADOPT_TERM_ID" 'DUO 3345 adopt unmanaged draft' 'https://example.invalid/duo-3345/adopt-unmanaged-draft' --porcelain)"
assert_positive_id "target adoption unmanaged draft item" "$TARGET_ADOPT_UNMANAGED_DRAFT_ID"
wp2 post update "$TARGET_ADOPT_UNMANAGED_DRAFT_ID" --post_status=draft >/dev/null

[ -z "$(menu_term_uuid 2 "$TARGET_ADOPT_TERM_ID")" ] \
  || fail "fixture manufacture failed: target adoption menu unexpectedly already has a Duo term UUID"
assert_count "unmanaged adoption menu premise" 2 "$TARGET_ADOPT_TERM_ID" 4
assert_shape "adoption desired managed item premise" 2 "$TARGET_ADOPT_KEEP_ID" publish "$ADOPT_DESIRED_UUID"
assert_shape "adoption stale published item premise" 2 "$TARGET_ADOPT_STALE_PUBLISHED_ID" publish "$ADOPT_STALE_PUBLISHED_UUID"
assert_shape "adoption stale draft item premise" 2 "$TARGET_ADOPT_STALE_DRAFT_ID" draft "$ADOPT_STALE_DRAFT_UUID"
assert_shape "adoption UUID-less draft premise" 2 "$TARGET_ADOPT_UNMANAGED_DRAFT_ID" draft ''
pass "fixture premise is exact before plan: unmanaged term, two stale managed items across statuses, one UUID-less draft survivor"

ADOPTION_PLAN="$(duo_json 'adoption plan' plan --repo=/siterepo --adopt-by-slug=terms,menus)"
assert_plan_menu_bucket "adoption plan" "$ADOPTION_PLAN" adopt "$ADOPT_MENU_UUID"
assert_summary_candidates "adoption plan" "$ADOPTION_PLAN" 2
ADOPTION_HUMAN="$(duo_human 'adoption human plan' plan --repo=/siterepo --adopt-by-slug=terms,menus)"
grep -Fq 'SUMMARY [duo-plan-category-summary/v1]' <<<"$ADOPTION_HUMAN" \
  || fail "agent human plan omitted the category-summary header: $ADOPTION_HUMAN"
grep -Fq 'nested_menu_item_delete_candidates=2' <<<"$ADOPTION_HUMAN" \
  || fail "agent human plan did not render the exact adoption candidate count: $ADOPTION_HUMAN"
pass "adoption reports exactly two managed candidates; the summary is present in both JSON and human plan output"

duo_json 'initial apply with menu adoption' apply --repo=/siterepo --adopt-by-slug=terms,menus --default-author=admin >/dev/null
[ "$(menu_term_uuid 2 "$TARGET_ADOPT_TERM_ID")" = "$ADOPT_MENU_UUID" ] \
  || fail "adoption did not assign the source menu UUID to the same-slug target term"
assert_count "post-adoption menu" 2 "$TARGET_ADOPT_TERM_ID" 2
assert_shape "adopted desired item" 2 "$TARGET_ADOPT_KEEP_ID" publish "$ADOPT_DESIRED_UUID"
assert_missing "adoption stale published managed item" 2 "$TARGET_ADOPT_STALE_PUBLISHED_ID"
assert_missing "adoption stale draft managed item" 2 "$TARGET_ADOPT_STALE_DRAFT_ID"
assert_shape "adoption UUID-less draft survivor" 2 "$TARGET_ADOPT_UNMANAGED_DRAFT_ID" draft ''
pass "adoption reconciles both managed statuses and leaves the UUID-less draft untouched; post-apply verification succeeded"

say "concurrent managed reconciliation: change desired source state, then add target-only managed published/draft and UUID-less draft items"
TARGET_RECON_TERM_ID="$(menu_id_by_slug 2 "$RECON_SLUG")"
assert_positive_id "target reconcile menu created by baseline apply" "$TARGET_RECON_TERM_ID"
TARGET_RECON_DESIRED_ID="$(menu_item_id_by_uuid 2 "$RECON_DESIRED_UUID")"
assert_positive_id "target reconcile desired item" "$TARGET_RECON_DESIRED_ID"
TARGET_NORMAL_STALE_PUBLISHED_ID="$(wp2 menu item add-custom "$TARGET_RECON_TERM_ID" 'DUO 3345 normal stale published' 'https://example.invalid/duo-3345/normal-stale-published' --porcelain)"
assert_positive_id "target normal stale published item" "$TARGET_NORMAL_STALE_PUBLISHED_ID"
wp2 post meta add "$TARGET_NORMAL_STALE_PUBLISHED_ID" _duo_uuid "$NORMAL_STALE_PUBLISHED_UUID" >/dev/null
TARGET_NORMAL_STALE_DRAFT_ID="$(wp2 menu item add-custom "$TARGET_RECON_TERM_ID" 'DUO 3345 normal stale draft' 'https://example.invalid/duo-3345/normal-stale-draft' --porcelain)"
assert_positive_id "target normal stale draft item" "$TARGET_NORMAL_STALE_DRAFT_ID"
wp2 post meta add "$TARGET_NORMAL_STALE_DRAFT_ID" _duo_uuid "$NORMAL_STALE_DRAFT_UUID" >/dev/null
wp2 post update "$TARGET_NORMAL_STALE_DRAFT_ID" --post_status=draft >/dev/null
TARGET_NORMAL_UNMANAGED_DRAFT_ID="$(wp2 menu item add-custom "$TARGET_RECON_TERM_ID" 'DUO 3345 normal unmanaged draft' 'https://example.invalid/duo-3345/normal-unmanaged-draft' --porcelain)"
assert_positive_id "target normal unmanaged draft item" "$TARGET_NORMAL_UNMANAGED_DRAFT_ID"
wp2 post update "$TARGET_NORMAL_UNMANAGED_DRAFT_ID" --post_status=draft >/dev/null
assert_count "concurrent reconciliation premise" 2 "$TARGET_RECON_TERM_ID" 4
assert_shape "normal desired item premise" 2 "$TARGET_RECON_DESIRED_ID" publish "$RECON_DESIRED_UUID"
assert_shape "normal stale published item premise" 2 "$TARGET_NORMAL_STALE_PUBLISHED_ID" publish "$NORMAL_STALE_PUBLISHED_UUID"
assert_shape "normal stale draft item premise" 2 "$TARGET_NORMAL_STALE_DRAFT_ID" draft "$NORMAL_STALE_DRAFT_UUID"
assert_shape "normal UUID-less draft premise" 2 "$TARGET_NORMAL_UNMANAGED_DRAFT_ID" draft ''

wp1 post update "$SRC_RECON_DESIRED_ID" --post_title="$RECON_TITLE_V2" >/dev/null
wp1 duo capture --repo=/siterepo >/dev/null
publish_source_update 'capture: change DUO-3345 reconcile menu desired title'

NORMAL_PLAN="$(duo_json 'concurrent reconciliation plan' plan --repo=/siterepo --adopt-by-slug=terms,menus)"
assert_plan_menu_bucket "concurrent reconciliation plan" "$NORMAL_PLAN" conflict "$RECON_MENU_UUID"
assert_summary_candidates "concurrent reconciliation plan" "$NORMAL_PLAN" 2
pass "the three-way conflict still reports exactly the two target-owned candidates across published and draft statuses"

duo_json 'explicit repository-choice reconciliation apply' apply --repo=/siterepo --adopt-by-slug=terms,menus --default-author=admin --force-theirs >/dev/null
assert_count "post-normal-reconciliation menu" 2 "$TARGET_RECON_TERM_ID" 2
assert_shape "normal desired item after apply" 2 "$TARGET_RECON_DESIRED_ID" publish "$RECON_DESIRED_UUID"
[ "$(wp2 post get "$TARGET_RECON_DESIRED_ID" --field=post_title)" = "$RECON_TITLE_V2" ] \
  || fail "ordinary reconciliation did not apply the source title change"
assert_missing "normal stale published managed item" 2 "$TARGET_NORMAL_STALE_PUBLISHED_ID"
assert_missing "normal stale draft managed item" 2 "$TARGET_NORMAL_STALE_DRAFT_ID"
assert_shape "normal UUID-less draft survivor" 2 "$TARGET_NORMAL_UNMANAGED_DRAFT_ID" draft ''
pass "the explicit repository choice reconciles only managed items across statuses and preserves the UUID-less draft"

say "menu tombstone: preserve a canonical matching published item, add one durable draft and retain the UUID-less draft (three all-status/ownership rows)"
TARGET_TOMBSTONE_DRAFT_ID="$(wp2 menu item add-custom "$TARGET_ADOPT_TERM_ID" 'DUO 3345 tombstone durable draft' 'https://example.invalid/duo-3345/tombstone-draft' --porcelain)"
assert_positive_id "target tombstone durable draft item" "$TARGET_TOMBSTONE_DRAFT_ID"
wp2 post meta add "$TARGET_TOMBSTONE_DRAFT_ID" _duo_uuid "$TOMBSTONE_DRAFT_UUID" >/dev/null
wp2 post update "$TARGET_TOMBSTONE_DRAFT_ID" --post_status=draft >/dev/null
assert_count "menu tombstone premise" 2 "$TARGET_ADOPT_TERM_ID" 3
assert_shape "tombstone matching published managed item premise" 2 "$TARGET_ADOPT_KEEP_ID" publish "$ADOPT_DESIRED_UUID"
assert_shape "tombstone durable draft item premise" 2 "$TARGET_TOMBSTONE_DRAFT_ID" draft "$TOMBSTONE_DRAFT_UUID"
assert_shape "tombstone UUID-less draft item premise" 2 "$TARGET_ADOPT_UNMANAGED_DRAFT_ID" draft ''
pass "tombstone premise is exact: published managed, draft managed, and draft UUID-less items are all assigned to the menu"

DELETE_RESULT="$(wp1 eval "echo wp_delete_nav_menu($SRC_ADOPT_TERM_ID) ? 'deleted' : 'failed';")"
[ "$DELETE_RESULT" = 'deleted' ] \
  || fail "fixture manufacture failed: source WordPress API did not delete the adoption menu (got '$DELETE_RESULT')"
wp1 duo capture --repo=/siterepo >/dev/null
ADOPT_TOMBSTONE_FILE="$(tombstone_file_by_uuid "$SITE1" "$ADOPT_MENU_UUID")"
jq -e '.format == "duo-deletion/v1" and .kind == "menu" and .type == "nav_menu"' "$ADOPT_TOMBSTONE_FILE" >/dev/null \
  || fail "source menu disappearance did not publish the expected menu tombstone: $ADOPT_TOMBSTONE_FILE"
publish_source_update 'capture: delete DUO-3345 adoption menu'

TOMBSTONE_PLAN="$(duo_json 'menu tombstone plan' plan --repo=/siterepo --adopt-by-slug=terms,menus)"
assert_plan_menu_bucket "menu tombstone plan" "$TOMBSTONE_PLAN" delete "$ADOPT_MENU_UUID"
assert_summary_candidates "menu tombstone plan" "$TOMBSTONE_PLAN" 3
pass "menu tombstone counts all three assigned items, not just published or ledger-owned rows"

duo_json 'menu tombstone apply' apply --repo=/siterepo --with-deletes --adopt-by-slug=terms,menus --default-author=admin >/dev/null
[ "$(menu_id_by_slug 2 "$ADOPT_SLUG")" = '0' ] \
  || fail "menu tombstone apply left the target nav_menu term present"
assert_missing "tombstone matching published managed item" 2 "$TARGET_ADOPT_KEEP_ID"
assert_missing "tombstone durable draft managed item" 2 "$TARGET_TOMBSTONE_DRAFT_ID"
assert_missing "tombstone UUID-less draft item" 2 "$TARGET_ADOPT_UNMANAGED_DRAFT_ID"
pass "menu tombstone deleted every assigned status/ownership shape from the real target database"

TOMBSTONE_RETRY="$(duo_json 'menu tombstone retry plan' plan --repo=/siterepo --adopt-by-slug=terms,menus)"
assert_plan_menu_bucket "menu tombstone retry plan" "$TOMBSTONE_RETRY" deleted "$ADOPT_MENU_UUID"
assert_summary_candidates "menu tombstone retry plan" "$TOMBSTONE_RETRY" 0
pass "tombstone retry is settled and reports zero nested menu candidates"

printf '\n\033[1;32m✔ REGRESS_PLAN_CATEGORY_SUMMARY_LIVE PASSED\033[0m\n'
