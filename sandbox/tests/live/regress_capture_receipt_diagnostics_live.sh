#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../../.." && pwd -P)"
cd "$ROOT/sandbox"
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }
PAIR="${CAPTURE_RECEIPT_PAIR:?unique owned pair required}"
PORT1="${CAPTURE_RECEIPT_PORT1:?even port required}"
PORT2="${CAPTURE_RECEIPT_PORT2:?successor port required}"
[ "${WPRISM_EXPECTED_SOURCE_SHA:-}" = "$(git rev-parse HEAD)" ] \
  && [ -z "$(git status --porcelain=v1 --untracked-files=all)" ] || fail 'exact clean source commit required'
export WPRISM_SOURCE_ROOT="$ROOT" WPRISM_PAIR="$PAIR" WPRISM_PORT1="$PORT1" WPRISM_PORT2="$PORT2"
export WPRISM_CODEBIND_PLUGIN='' WPRISM_DB_ENGINE=mariadb
export WPRISM_WP_IMAGE='wordpress@sha256:65919a9ca10940feb10d9400fead0d639bf86241f47c91e2b9ea4703aa8452cf'
export WPRISM_CLI_IMAGE='wordpress@sha256:2b5e9d4d3e51909dca1aaa4732e9f5e5bf0377c2114dbd8ff39f060bff202586'
. tests/lib/pair_live_ownership.sh
pair_live_ownership_prepare "$PAIR" "$PORT1" "$PORT2" 'Capture receipt diagnostic evidence' wprism-capture-receipt
bash bin/pair.sh list
pair_live_ownership_acquire mariadb
pair_live_ownership_up --headless
export CONF_PAIR="$PAIR" WPRISM_ARTIFACT_LIBRARY_ROOT="$ROOT"
PAIR_COMPOSE=(docker compose -p "wprism-$PAIR" -f "$ROOT/sandbox/pair.yml")
wp1() { "${PAIR_COMPOSE[@]}" run --rm -T cli1 sh -c 'umask 000; exec wp "$@"' sh "$@"; }
. conformance/asserts.sh
. tests/lib/conformance_private_command.sh
. tests/lib/private_command_capture.sh
SITE="$PAIR_LIVE_OWNERSHIP_SITE1"
[ ! -e "$SITE/site.wprism.json" ] && [ ! -e "$SITE/state" ] || fail 'fresh source repository required'
printf '%s\n' '{"manifests":["core"],"policy":{"options":{},"post_meta":{},"post_types":["post","page"],"taxonomies":["category","post_tag"]},"spec_version":2}' > "$SITE/site.wprism.json"
cp site-repo.gitignore.template "$SITE/.gitignore"
git -C "$SITE" init -q -b main
establish_core_environment_bindings wp1 /siterepo admin@example.test "http://${PAIR}1.invalid" "http://${PAIR}1.invalid"
OBSERVATIONS="$ROOT/sandbox/tmp/capture-receipt-$PAIR"
(umask 077; mkdir "$OBSERVATIONS") || fail 'fresh private observation directory required'
for stage in invocation observed repeated; do
  for suffix in stdout stderr exit; do
    (umask 077; set -C; : > "$OBSERVATIONS/$stage.$suffix") || fail 'fresh private observation file required'
  done
done
probe_capture() {
  "${PAIR_COMPOSE[@]}" run --rm -T -v "$ROOT/sandbox/tests:/wprism-tests:ro" \
    -e WPRISM_RECEIPT_READBACK_PROBE=missing cli1 wp --require=/wprism-tests/live/fixtures/capture_receipt_readback.php \
    wprism capture --repo=/siterepo --format=json
}
status=0
wprism_private_capture_stage "$OBSERVATIONS" invocation conformance_private_command cli1 capture probe_capture || status=$?
[ "$status" = 1 ] || fail 'native injected missing read must refuse exactly once'
sinks=("$ROOT/sandbox/tmp/wprism-conformance-capture.$PAIR".*)
[ "${#sinks[@]}" = 1 ] && [ -d "${sinks[0]}" ] || fail 'one private capture sink required'
observe() {
  "${PAIR_COMPOSE[@]}" run --rm -T -v "$ROOT/sandbox/tests:/wprism-tests:ro" cli1 \
    wp eval-file /wprism-tests/live/fixtures/capture_receipt_readback.php observe --use-include
}
wprism_private_capture_stage "$OBSERVATIONS" observed observe || fail 'native post-refusal observation failed'
wprism_private_capture_stage "$OBSERVATIONS" repeated observe || fail 'native repeated observation failed'
# The shared collector must retain the exact graph before mandatory teardown;
# the verifier has no live site from which it could reconstruct a lost cause.
pair_live_ownership_finish_leg
php "$ROOT/sandbox/tests/live/fixtures/capture_receipt_readback.php" verify "${sinks[0]}" "$PAIR" "$OBSERVATIONS"
pair_live_ownership_complete 'CAPTURE_RECEIPT_DIAGNOSTICS_PASSED'
