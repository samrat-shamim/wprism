#!/usr/bin/env bash
# Live regression — DUO-3206. Exercises deterministic failures at every
# product mutation class and apply boundary, then proves that required
# rebuild failures leave applied_revision/base state unadvanced and retry.
set -euo pipefail
REPO_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$REPO_ROOT"

PAIR=codexmaca3206
PORT1=9210
PORT2=9211
SITEREPO="$REPO_ROOT/sandbox/siterepo/${PAIR}1"
COMPOSE=(docker compose -p "duo-$PAIR" -f sandbox/pair.yml)
export DUO_PAIR="$PAIR" DUO_PORT1="$PORT1" DUO_PORT2="$PORT2"

say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*" >&2; exit 1; }
wp1() { "${COMPOSE[@]}" run --rm -T cli1 wp "$@"; }
wp1_fail() {
  local contexts="$1"; shift
  "${COMPOSE[@]}" run --rm -T \
    -e DUO_TEST_MODE=1 -e DUO_TEST_FAIL_DB_CONTEXT="$contexts" \
    cli1 wp "$@"
}
wp1_test_manifests() {
  "${COMPOSE[@]}" run --rm -T \
    -e DUO_MANIFESTS_DIR=/siterepo/test-manifests \
    cli1 wp "$@"
}
ledger_value() {
  wp1 eval "echo \\Duo\\Ledger::kv_get('$1') ?? 'NULL';" 2>/dev/null | tr -d '\r' | tail -1
}
expect_failure() {
  local contexts="$1" expected="$2"; shift 2
  if OUT=$(wp1_fail "$contexts" "$@" 2>&1); then
    echo "$OUT"
    fail "expected failure for context '$contexts'"
  fi
  echo "$OUT"
  echo "$OUT" | grep -Fq "$expected" || fail "failure did not name '$expected'"
}
reset_baseline() {
  wp1 option update blogname "$ORIGINAL_BLOGNAME" >/dev/null
  wp1 duo capture --repo=/siterepo >/dev/null
  wp1 eval "\\Duo\\Ledger::kv_delete('apply_in_progress'); \\Duo\\Ledger::kv_set('applied_revision', 'baseline');" >/dev/null
}
edit_blogname() {
  jq --arg v "$1" '.records.blogname.value = $v' "$SITEREPO/state/options/core.json" > "$SITEREPO/state/options/core.json.tmp"
  mv "$SITEREPO/state/options/core.json.tmp" "$SITEREPO/state/options/core.json"
}

cleanup() {
  bash sandbox/bin/pair.sh destroy "$PAIR" >/dev/null 2>&1 || true
  rm -rf "sandbox/siterepo/${PAIR}1" "sandbox/siterepo/${PAIR}2"
}
trap cleanup EXIT

say "boot isolated headless pair $PAIR"
bash sandbox/bin/pair.sh up "$PAIR" "$PORT1" "$PORT2" --headless
mkdir -p "$SITEREPO"
cat > "$SITEREPO/site.duo.json" <<'EOF'
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
wp1 duo capture --repo=/siterepo >/dev/null
ORIGINAL_BLOGNAME=$(wp1 option get blogname | tr -d '\r')
wp1 eval "\\Duo\\Ledger::kv_set('applied_revision', 'baseline');" >/dev/null
pass "baseline captured"

say "transaction START failure is fatal before target mutation"
edit_blogname "DUO 3206 start failure"
expect_failure "apply transaction start" "apply transaction start" \
  duo apply --repo=/siterepo --revision=bad-start
[ "$(wp1 option get blogname | tr -d '\r')" = "$ORIGINAL_BLOGNAME" ] || fail "start failure mutated blogname"
[ "$(ledger_value applied_revision)" = baseline ] || fail "start failure advanced applied_revision"
reset_baseline
pass "transaction start failure left target and revision unchanged"

say "INSERT failure never records local id zero or identity"
SRC_POST=$(find "$SITEREPO/state/posts/post" -type f | head -1)
[ -n "$SRC_POST" ] || fail "captured tree has no post fixture"
NEW_UUID=01999999-3206-7000-8000-000000000001
NEW_POST="$SITEREPO/state/posts/post/${NEW_UUID}--duo-3206-insert.md"
php -r '
require $argv[1];
[$front, $body] = \Duo\Canon::parse_post_file(file_get_contents($argv[2]));
$front["uuid"] = $argv[4]; $front["slug"] = "duo-3206-insert"; $front["title"] = "DUO 3206 Insert";
file_put_contents($argv[3], \Duo\Canon::post_file($front, $body));
' "$REPO_ROOT/agent/src/Canon.php" "$SRC_POST" "$NEW_POST" "$NEW_UUID"
expect_failure "apply insert post" "apply insert post" \
  duo apply --repo=/siterepo --revision=bad-insert
MAP=$(wp1 eval "echo \\Duo\\Ledger::id_for('$NEW_UUID', \\Duo\\Ledger::KIND_POST) ?? 'NULL';" 2>/dev/null | tr -d '\r' | tail -1)
[ "$MAP" = NULL ] || fail "failed insert recorded identity $MAP"
[ "$(ledger_value applied_revision)" = baseline ] || fail "insert failure advanced applied_revision"
rm -f "$NEW_POST"
reset_baseline
pass "failed insert left no row identity or revision advance"

say "UPDATE failure rolls back and preserves base revision"
edit_blogname "DUO 3206 update failure"
expect_failure "apply update authored option" "apply update authored option" \
  duo apply --repo=/siterepo --revision=bad-update
[ "$(wp1 option get blogname | tr -d '\r')" = "$ORIGINAL_BLOGNAME" ] || fail "update failure did not roll back blogname"
[ "$(ledger_value applied_revision)" = baseline ] || fail "update failure advanced applied_revision"
reset_baseline
pass "failed update rolled back"

say "DELETE failure rolls back prior cleanup writes and keeps ledger state"
DELETE_POST=$(find "$SITEREPO/state/posts/post" -type f | head -1)
DELETE_UUID=$(php -r 'require $argv[1]; [$f] = \Duo\Canon::parse_post_file(file_get_contents($argv[2])); echo $f["uuid"];' "$REPO_ROOT/agent/src/Canon.php" "$DELETE_POST")
DELETE_ID=$(wp1 eval "echo \\Duo\\Ledger::id_for('$DELETE_UUID', \\Duo\\Ledger::KIND_POST);" 2>/dev/null | tr -d '\r' | tail -1)
DELETE_SAVED="$SITEREPO/.duo-3206-deleted-post.md"
cp "$DELETE_POST" "$DELETE_SAVED"
wp1 eval "
\$compiled = \\Duo\\RepositoryCompiler::compile('/siterepo', \\Duo\\Policy::load('/siterepo'));
\$entity = \$compiled->tree()['$DELETE_UUID'];
if (!is_dir('/siterepo/state/deletions') && !wp_mkdir_p('/siterepo/state/deletions')) {
    throw new \\RuntimeException('could not create deletion fixture directory');
}
\$written = file_put_contents('/siterepo/state/deletions/$DELETE_UUID.json', \\Duo\\Canon::encode([
    'expected_hash' => \$entity['hash'],
    'expected_revision' => \$compiled->revision_hash(),
    'format' => \\Duo\\Deletion::FORMAT,
    'kind' => 'post',
    'source_path' => \$entity['path'],
    'type' => \$entity['data']['type'],
    'uuid' => '$DELETE_UUID',
]));
if (\$written === false || !unlink('/siterepo/state/' . \$entity['path'])) {
    throw new \\RuntimeException('could not publish explicit deletion fixture');
}
" >/dev/null
expect_failure "apply delete post" "apply delete post" \
  duo apply --repo=/siterepo --with-deletes --force-delete-referenced --revision=bad-delete
[ "$(wp1 post get "$DELETE_ID" --field=ID 2>/dev/null | tr -d '\r')" = "$DELETE_ID" ] || fail "delete failure did not restore post"
STATE_HASH=$(wp1 eval "echo \\Duo\\Ledger::state_hash('$DELETE_UUID') ?? 'NULL';" 2>/dev/null | tr -d '\r' | tail -1)
[ "$STATE_HASH" != NULL ] || fail "delete failure removed base state"
rm -f "$SITEREPO/state/deletions/$DELETE_UUID.json"
mv "$DELETE_SAVED" "$DELETE_POST"
reset_baseline
pass "failed delete rolled back row/meta cleanup and kept base state"

say "COMMIT failure is fatal and rolls the transaction back"
edit_blogname "DUO 3206 commit failure"
expect_failure "apply transaction commit" "apply transaction commit" \
  duo apply --repo=/siterepo --revision=bad-commit
[ "$(wp1 option get blogname | tr -d '\r')" = "$ORIGINAL_BLOGNAME" ] || fail "commit failure did not roll back blogname"
[ "$(ledger_value applied_revision)" = baseline ] || fail "commit failure advanced applied_revision"
reset_baseline
pass "failed commit rolled back"

say "ROLLBACK failure is itself typed and non-zero"
edit_blogname "DUO 3206 rollback failure"
expect_failure "apply update authored option,apply transaction rollback" "apply transaction rollback" \
  duo apply --repo=/siterepo --revision=bad-rollback
[ "$(ledger_value applied_revision)" = baseline ] || fail "rollback failure advanced applied_revision"
[ "$(wp1 eval 'echo \Duo\PromotionLock::current() === null ? "none" : "held";' 2>/dev/null | tr -d '\r' | tail -1)" = none ] \
  || fail "rollback failure stranded the promotion lock"
# The failed process closed its transaction before independently releasing the
# lease. Re-capture establishes the next case from a fresh process.
reset_baseline
pass "rollback boundary failure stayed fatal and released its lease"

say "term recount failure occurs after commit but before convergence metadata"
BASE_HASH=$(wp1 eval "echo \\Duo\\Ledger::state_hash('options/core') ?? 'NULL';" 2>/dev/null | tr -d '\r' | tail -1)
edit_blogname "DUO 3206 recount failure"
expect_failure "rebuild term counts" "rebuild term counts" \
  duo apply --repo=/siterepo --revision=bad-recount
[ "$(wp1 option get blogname | tr -d '\r')" = "DUO 3206 recount failure" ] || fail "recount test did not reach post-commit phase"
[ "$(ledger_value applied_revision)" = baseline ] || fail "recount failure advanced applied_revision"
[ "$(wp1 eval "echo \\Duo\\Ledger::state_hash('options/core') ?? 'NULL';" 2>/dev/null | tr -d '\r' | tail -1)" = "$BASE_HASH" ] || fail "recount failure advanced base hash"
[ "$(ledger_value apply_in_progress)" = 1 ] || fail "recount failure did not retain retry marker"
PENDING_PLAN=$(wp1 duo plan --repo=/siterepo --json 2>/dev/null | tail -1)
echo "$PENDING_PLAN" | jq -e '.warnings[] | contains("previous apply did not complete")' >/dev/null \
  || fail "status plan did not surface the incomplete apply marker as a correctness warning"
echo "$PENDING_PLAN" | jq -e '.incomplete_apply | length == 1' >/dev/null \
  || fail "plan did not expose the structured incomplete-apply condition"
STATUS_ENVS="$SITEREPO/duo-3206-envs.json"
jq -n --arg compose "$REPO_ROOT/sandbox/pair.yml" '{
  envs: {probe: {transport: "docker", compose_file: $compose, service: "cli1", repo_path: "/siterepo"}}
}' > "$STATUS_ENVS"
if STATUS_OUT=$("$REPO_ROOT/cli/duo" --envs-file="$STATUS_ENVS" status probe 2>&1); then
  echo "$STATUS_OUT"
  fail "duo status returned zero for an incomplete apply"
fi
echo "$STATUS_OUT" | grep -Fq 'INCOMPLETE_APPLY' \
  || fail "duo status did not name the incomplete apply condition"
wp1 duo apply --repo=/siterepo --revision=recount-recovered >/dev/null
[ "$(ledger_value applied_revision)" = recount-recovered ] || fail "recount retry did not advance revision"
[ "$(ledger_value apply_in_progress)" = NULL ] || fail "recount retry did not clear marker"
reset_baseline
pass "required recount failure stayed unapplied and retried successfully"

say "object-cache rebuild failure is fatal before ledger advancement"
edit_blogname "DUO 3206 cache rebuild failure"
expect_failure "rebuild object cache" "rebuild object cache" \
  duo apply --repo=/siterepo --revision=bad-cache
[ "$(ledger_value applied_revision)" = baseline ] || fail "cache rebuild failure advanced applied_revision"
[ "$(ledger_value apply_in_progress)" = 1 ] || fail "cache rebuild failure did not retain retry marker"
wp1 duo apply --repo=/siterepo --revision=cache-recovered >/dev/null
[ "$(ledger_value applied_revision)" = cache-recovered ] || fail "cache rebuild retry did not advance revision"
[ "$(ledger_value apply_in_progress)" = NULL ] || fail "cache rebuild retry did not clear marker"
reset_baseline
pass "required object-cache failure stayed unapplied and retried successfully"

say "ledger COMMIT failure atomically preserves every base hash and revision"
BASE_HASH=$(wp1 eval "echo \\Duo\\Ledger::state_hash('options/core') ?? 'NULL';" 2>/dev/null | tr -d '\r' | tail -1)
edit_blogname "DUO 3206 ledger commit failure"
expect_failure "ledger transaction commit" "ledger transaction commit" \
  duo apply --repo=/siterepo --revision=bad-ledger-commit
[ "$(wp1 option get blogname | tr -d '\r')" = "DUO 3206 ledger commit failure" ] || fail "ledger commit test did not reach committed authored state"
[ "$(ledger_value applied_revision)" = baseline ] || fail "ledger commit failure advanced applied_revision"
[ "$(wp1 eval "echo \\Duo\\Ledger::state_hash('options/core') ?? 'NULL';" 2>/dev/null | tr -d '\r' | tail -1)" = "$BASE_HASH" ] || fail "ledger commit failure partially advanced base hash"
[ "$(ledger_value apply_in_progress)" = 1 ] || fail "ledger commit failure did not retain retry marker"
wp1 duo apply --repo=/siterepo --revision=ledger-recovered >/dev/null
[ "$(ledger_value applied_revision)" = ledger-recovered ] || fail "ledger commit retry did not advance revision"
[ "$(ledger_value apply_in_progress)" = NULL ] || fail "ledger commit retry did not clear marker"
reset_baseline
pass "ledger metadata transition rolled back atomically and retried"

say "manifest-declared rebuilder failure is fatal before ledger advancement"
mkdir -p "$SITEREPO/test-manifests"
cp "$REPO_ROOT/manifests/core.json" "$SITEREPO/test-manifests/core.json"
cat > "$SITEREPO/test-manifests/duo-3206-fatal-rebuilder.json" <<'EOF'
{
  "name": "duo-3206-fatal-rebuilder",
  "spec_version": 1,
  "rebuilders": [{"command": "duo-3206-command-that-does-not-exist"}]
}
EOF
jq '.manifests = ["core", "duo-3206-fatal-rebuilder"]' "$SITEREPO/site.duo.json" > "$SITEREPO/site.duo.json.tmp"
mv "$SITEREPO/site.duo.json.tmp" "$SITEREPO/site.duo.json"
edit_blogname "DUO 3206 manifest rebuilder failure"
if OUT=$(wp1_test_manifests duo apply --repo=/siterepo --revision=bad-manifest 2>&1); then
  echo "$OUT"
  fail "unknown required manifest rebuilder unexpectedly succeeded"
fi
echo "$OUT"
echo "$OUT" | grep -Fq "required manifest rebuilder" || fail "manifest failure was not named"
[ "$(ledger_value applied_revision)" = baseline ] || fail "manifest rebuilder failure advanced applied_revision"
[ "$(ledger_value apply_in_progress)" = 1 ] || fail "manifest failure did not retain retry marker"
jq '.manifests = ["core"]' "$SITEREPO/site.duo.json" > "$SITEREPO/site.duo.json.tmp"
mv "$SITEREPO/site.duo.json.tmp" "$SITEREPO/site.duo.json"
reset_baseline
pass "required manifest rebuilder failure stayed fatal and unapplied"

say "attachment metadata failure is retained and retried on the same canonical attachment"
printf '%s' 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=' \
  | base64 -d > "$SITEREPO/duo-3206-probe.png"
ATTACH_ID=$(wp1 media import /siterepo/duo-3206-probe.png --porcelain | tr -d '\r' | tail -1)
[ -n "$ATTACH_ID" ] || fail "could not import attachment fixture"
wp1 duo capture --repo=/siterepo >/dev/null
ATTACH_UUID=$(wp1 post meta get "$ATTACH_ID" _duo_uuid | tr -d '\r')
[ -n "$ATTACH_UUID" ] || fail "capture did not mint attachment uuid"
wp1 eval "\\Duo\\Ledger::kv_set('applied_revision', 'baseline');" >/dev/null
wp1 db query "DELETE FROM wp_postmeta WHERE post_id=$ATTACH_ID; DELETE FROM wp_posts WHERE ID=$ATTACH_ID; DELETE FROM wp_duo_map WHERE uuid='$ATTACH_UUID';" >/dev/null
expect_failure "rebuild attachment metadata" "rebuild attachment metadata" \
  duo apply --repo=/siterepo --revision=bad-attachment
[ "$(ledger_value applied_revision)" = baseline ] || fail "attachment rebuild failure advanced applied_revision"
[ "$(ledger_value apply_in_progress)" = 1 ] || fail "attachment failure did not retain retry marker"
NEW_ATTACH_ID=$(wp1 eval "echo \\Duo\\Ledger::id_for('$ATTACH_UUID', \\Duo\\Ledger::KIND_POST) ?? 'NULL';" 2>/dev/null | tr -d '\r' | tail -1)
[ "$NEW_ATTACH_ID" != NULL ] || fail "attachment test did not reach post-commit rebuild phase"
META_BEFORE=$(wp1 post meta get "$NEW_ATTACH_ID" _wp_attachment_metadata 2>/dev/null || true)
[ -z "$META_BEFORE" ] || fail "failed attachment rebuild unexpectedly wrote metadata"
wp1 duo apply --repo=/siterepo --revision=attachment-recovered >/dev/null
[ "$(ledger_value applied_revision)" = attachment-recovered ] || fail "attachment retry did not advance revision"
[ "$(ledger_value apply_in_progress)" = NULL ] || fail "attachment retry did not clear marker"
META_AFTER=$(wp1 post meta get "$NEW_ATTACH_ID" _wp_attachment_metadata 2>/dev/null || true)
[ -n "$META_AFTER" ] || fail "attachment retry did not regenerate metadata"
pass "attachment metadata failure remained retryable and recovered"

printf '\n\033[1;32m✔ REGRESS_FATAL_MUTATIONS PASSED\033[0m\n'
