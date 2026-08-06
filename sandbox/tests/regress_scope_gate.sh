#!/usr/bin/env bash
# Regression — DUO-3229: registered public/entity-contract surfaces with
# live capturable rows may not disappear merely because the site scope list
# forgot them. The same discovery powers capture's hard gate and pending's
# review item; an explicit whole-surface class is the durable resolution.
set -euo pipefail
cd "$(dirname "$0")/.."   # -> sandbox/

export DUO_PAIR=codexmac3229 DUO_PORT1=8900 DUO_PORT2=8901
COMPOSE="docker compose -p duo-codexmac3229 -f pair.yml"
wp1() { $COMPOSE run --rm -T cli1 wp "$@"; }
pass() { printf 'ok: %s\n' "$*"; }
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }

command -v jq >/dev/null || fail "jq required"
bash bin/pair.sh reset codexmac3229
bash bin/pair.sh up codexmac3229 "$DUO_PORT1" "$DUO_PORT2" --headless

HOST_REPO=siterepo/codexmac32291/.tmp-duo-3229
REPO=/siterepo/.tmp-duo-3229
OUT="$REPO/state-out"
rm -rf "$HOST_REPO"
mkdir -p "$HOST_REPO"

jq -n '{
  manifests: ["core"],
  policy: {
    options: {}, post_meta: {}, term_meta: {},
    post_types: ["post", "page", "attachment"],
    taxonomies: ["category", "post_tag"]
  },
  spec_version: 0
}' > "$HOST_REPO/site.duo.json"

cp tests/fixtures/scope_gate_register.php "$HOST_REPO/register.php"

IDS=$(wp1 --require="$REPO/register.php" eval '
  $post = wp_insert_post(["post_type"=>"duo_book", "post_title"=>"Branchable", "post_status"=>"publish"]);
  $term = wp_insert_term("Reference", "duo_genre");
  if (is_wp_error($post) || is_wp_error($term)) { throw new RuntimeException("fixture seed failed"); }
  wp_set_object_terms($post, [(int) $term["term_id"]], "duo_genre");
  echo $post . ":" . $term["term_id"];
' 2>/dev/null | tail -1)
IFS=: read -r BOOK_ID TERM_ID <<<"$IDS"
[[ "$BOOK_ID" =~ ^[0-9]+$ && "$TERM_ID" =~ ^[0-9]+$ ]] || fail "fixture ids invalid: $IDS"

cleanup() {
  wp1 --require="$REPO/register.php" post delete "$BOOK_ID" --force >/dev/null 2>&1 || true
  wp1 --require="$REPO/register.php" term delete duo_genre "$TERM_ID" >/dev/null 2>&1 || true
  rm -rf "$HOST_REPO"
}
trap cleanup EXIT

mkdir -p "$HOST_REPO/state-out"
printf 'preserve-last-known-good\n' > "$HOST_REPO/state-out/sentinel"
RC=0
BLOCKED=$(wp1 --require="$REPO/register.php" duo capture --repo="$REPO" --out="$OUT" 2>&1) || RC=$?
[ "$RC" -eq 1 ] || fail "unscoped public surfaces must block capture (rc=$RC)"
printf '%s\n' "$BLOCKED" | grep -q "post_type 'duo_book' has 1 capturable entity" \
  || fail "post-type diagnostic lacks name/count: $BLOCKED"
printf '%s\n' "$BLOCKED" | grep -q "taxonomy 'duo_genre' has 1 capturable entity" \
  || fail "taxonomy diagnostic lacks name/count: $BLOCKED"
printf '%s\n' "$BLOCKED" | grep -q 'policy.post_types' || fail "post-type diagnostic lacks policy fix"
printf '%s\n' "$BLOCKED" | grep -q 'policy.taxonomies' || fail "taxonomy diagnostic lacks policy fix"
[ "$(cat "$HOST_REPO/state-out/sentinel")" = preserve-last-known-good ] \
  || fail "blocked capture replaced its output tree"
pass "capture blocks registered public post type/taxonomy omissions with stable names, counts, and policy fixes"

PENDING=$(wp1 --require="$REPO/register.php" duo pending --repo="$REPO" --format=json 2>/dev/null | tail -1)
printf '%s\n' "$PENDING" | jq -e '
  any(.[]; .section == "scope" and .key == "post_type:duo_book" and .evidence.entities == 1)
  and any(.[]; .section == "scope" and .key == "taxonomy:duo_genre" and .evidence.entities == 1)
' >/dev/null || fail "pending did not expose the exact scope-gate evidence: $PENDING"
pass "duo pending uses the identical scope discovery and exposes machine-readable evidence"

wp1 duo classify --repo="$REPO" --set='scope:post_type:duo_book=authored;scope:taxonomy:duo_genre=runtime' >/dev/null
jq -e '
  .policy.scope.post_type.duo_book.class == "authored"
  and .policy.scope.taxonomy.duo_genre.class == "runtime"
' "$HOST_REPO/site.duo.json" >/dev/null || fail "classify did not persist whole-surface decisions"

rm -rf "$HOST_REPO/state-out"
wp1 --require="$REPO/register.php" duo capture --repo="$REPO" --out="$OUT" --format=json >/dev/null
[ "$(find "$HOST_REPO/state-out/posts/duo_book" -type f | wc -l | tr -d ' ')" = 1 ] \
  || fail "authored whole-post-type rule did not enter capture scope"
[ ! -d "$HOST_REPO/state-out/terms/duo_genre" ] \
  || fail "runtime whole-taxonomy rule did not remain deliberately excluded"
PENDING_AFTER=$(wp1 --require="$REPO/register.php" duo pending --repo="$REPO" --format=json 2>/dev/null | tail -1)
printf '%s\n' "$PENDING_AFTER" | jq -e '[.[] | select(.section == "scope")] | length == 0' >/dev/null \
  || fail "resolved whole-surface decisions remained pending: $PENDING_AFTER"
pass "authored inclusion captures the CPT; audited runtime exclusion omits the taxonomy; pending is clean"

pass "DUO-3229 regression: no silent scope shrinkage and one durable classify path"
