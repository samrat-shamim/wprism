#!/usr/bin/env bash
# Integration — issue #3208: WP-CLI compile/plan/apply artifact routing and the
# before-Ledger target-mutation boundary.
set -euo pipefail
cd "$(dirname "$0")/../.."   # -> sandbox/

export WPRISM_PAIR=conf WPRISM_PORT1=8806 WPRISM_PORT2=8807
COMPOSE="docker compose -p wprism-conf -f pair.yml"
wp1() { $COMPOSE run --rm -T cli1 wp "$@"; }
wp2() { $COMPOSE run --rm -T cli2 wp "$@"; }
pass() { printf 'ok: %s\n' "$*"; }
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }

command -v jq >/dev/null || fail "jq required"
pgrep -f '^bash sandbox/conformance/run\.sh( |$)' >/dev/null \
  && fail "conformance sweep owns the conf pair; wait for it to finish"

bash bin/pair.sh reset conf
bash bin/pair.sh up conf "$WPRISM_PORT1" "$WPRISM_PORT2" --headless

HOST1=siterepo/conf1/.tmp-repository-compiler
HOST2=siterepo/conf2/.tmp-repository-compiler
REPO=/siterepo/.tmp-repository-compiler
ARTIFACT="$REPO/artifact.json"
rm -rf "$HOST1" "$HOST2"
mkdir -p "$HOST1"
# pair.yml intentionally runs wp-cli as www-data (33:33), while the fixture
# is prepared by the host user. Make this disposable repo writable by both;
# otherwise capture cannot create its lock/state files on a normal 022 umask.
chmod 0777 "$HOST1"
jq -n '{
  manifests: ["core"],
  policy: {
    options: {}, post_meta: {}, term_meta: {},
    post_types: ["post", "page", "attachment"],
    taxonomies: ["category", "post_tag"]
  },
  spec_version: 2
}' > "$HOST1/site.wprism.json"

wp1 wprism capture --repo="$REPO" --format=json >/dev/null
wp1 wprism compile --repo="$REPO" --out="$ARTIFACT" --format=json >/dev/null
# Captured files are owned by the container's www-data uid. This disposable
# fixture is subsequently copied, mutated, and removed by the host user, so
# have the owning uid grant that access explicitly.
$COMPOSE run --rm -T cli1 sh -c \
  'chmod -R a+rwX /siterepo/.tmp-repository-compiler/state && chmod a+rw /siterepo/.tmp-repository-compiler/artifact.json /siterepo/.tmp-repository-compiler/state.capture.lock' \
  >/dev/null
ARTIFACT_HASH=$(jq -r '.artifact_hash' "$HOST1/artifact.json")
[[ "$ARTIFACT_HASH" =~ ^[0-9a-f]{64}$ ]] || fail "compile did not emit a content-addressed artifact"
pass "wp wprism compile emitted a self-verifying artifact"

cp -R "$HOST1" "$HOST2"
# cp creates this disposable second checkout as the host user. The next
# assertion deliberately mutates it after compilation to prove the frozen
# artifact is independent of later checkout edits.
PAGE=$(rg --files "$HOST2/state/posts/page" | head -1)
[ -n "$PAGE" ] || fail "captured fixture has no page entity"
printf '\n<<<<<<< mutation-after-compile\n' >> "$PAGE"

RAW_RC=0
RAW=$(wp2 wprism plan --repo="$REPO" --format=json 2>&1) || RAW_RC=$?
[ "$RAW_RC" -eq 1 ] || fail "raw plan must reject the post-compile mutation (rc=$RAW_RC): $RAW"
RAW_JSON=$(printf '%s\n' "$RAW" | tail -1)
printf '%s\n' "$RAW_JSON" | jq -e '
  .ok == false and .error == "repository_compilation_failed"
  and any(.diagnostics[]; .code == "conflict_marker")
' >/dev/null || fail "raw plan did not emit structured compiler diagnostics"
LEDGER_COUNT=$(wp2 eval 'global $wpdb; echo count($wpdb->get_col("SHOW TABLES LIKE \"{$wpdb->prefix}wprism_%\""));' 2>/dev/null | tail -1)
[ "$LEDGER_COUNT" = 0 ] || fail "failed compilation created ledger tables on the fresh target"
pass "raw mutable tree is refused before fresh-target Ledger::ensure"

PLAN=$(wp2 wprism plan --repo="$REPO" --compiled="$ARTIFACT" --adopt-by-slug=posts,terms,menus --format=json 2>/dev/null | tail -1)
printf '%s\n' "$PLAN" | jq -e '(.adopt | length) > 0' >/dev/null \
  || fail "plan did not consume the frozen artifact"
APPLY=$(wp2 wprism apply --repo="$REPO" --compiled="$ARTIFACT" --adopt-by-slug=posts,terms,menus --format=json 2>/dev/null | tail -1)
printf '%s\n' "$APPLY" | jq -e --arg hash "$ARTIFACT_HASH" '.artifact.hash == $hash and .canary == "clean"' >/dev/null \
  || fail "apply did not report the exact compiled artifact"
pass "plan and apply consume the same artifact without reopening mutated state files"

wp2 eval '\WPrism\Ledger::kv_set("compiler_sentinel", "keep");' >/dev/null
BEFORE_BLOG=$(wp2 option get blogname 2>/dev/null | tail -1)
BEFORE_POSTS=$(wp2 post list --post_type=post,page --format=count 2>/dev/null | tail -1)
RAW2_RC=0
RAW2=$(wp2 wprism apply --repo="$REPO" --format=json 2>&1) || RAW2_RC=$?
[ "$RAW2_RC" -eq 1 ] || fail "raw apply must reject the changed tree (rc=$RAW2_RC): $RAW2"
printf '%s\n' "$RAW2" | tail -1 | jq -e '.error == "repository_compilation_failed"' >/dev/null \
  || fail "raw apply did not return the compiler failure payload"
[ "$(wp2 option get blogname 2>/dev/null | tail -1)" = "$BEFORE_BLOG" ] || fail "failed compile changed options"
[ "$(wp2 post list --post_type=post,page --format=count 2>/dev/null | tail -1)" = "$BEFORE_POSTS" ] || fail "failed compile changed posts"
[ "$(wp2 eval 'echo \WPrism\Ledger::kv_get("compiler_sentinel");' 2>/dev/null | tail -1)" = keep ] || fail "failed compile changed ledger state"
pass "mapped-target compiler failure leaves database and ledger byte-for-byte observable state untouched"

rm -rf "$HOST1" "$HOST2"
pass "issue #3208 integration: compile command + frozen artifact plan/apply + zero-mutation failure boundary"
