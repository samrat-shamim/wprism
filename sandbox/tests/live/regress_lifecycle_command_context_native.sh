#!/usr/bin/env bash
# Native WordPress lifecycle callbacks must register before plugins are loaded.
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd -P)"
cd "$ROOT/sandbox"
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }
PAIR="${LIFECYCLE_CONTEXT_PAIR:?unique owned pair required}"
PORT1="${LIFECYCLE_CONTEXT_PORT1:?even port required}"
PORT2="${LIFECYCLE_CONTEXT_PORT2:?successor port required}"
[ "$(git rev-parse HEAD)" = "${WPRISM_EXPECTED_SOURCE_SHA:?clean candidate SHA required}" ] \
  && [ -z "$(git status --porcelain=v1 --untracked-files=all)" ] || fail 'clean exact candidate required'
export WPRISM_SOURCE_ROOT="$ROOT" WPRISM_ARTIFACT_LIBRARY_ROOT="$ROOT" WPRISM_PAIR="$PAIR"
export WPRISM_PORT1="$PORT1" WPRISM_PORT2="$PORT2" WPRISM_CODEBIND_PLUGIN=''
export WPRISM_WP_IMAGE='wordpress@sha256:65919a9ca10940feb10d9400fead0d639bf86241f47c91e2b9ea4703aa8452cf'
export WPRISM_CLI_IMAGE='wordpress@sha256:2b5e9d4d3e51909dca1aaa4732e9f5e5bf0377c2114dbd8ff39f060bff202586'
. tests/lib/pair_live_ownership.sh
. tests/lib/private_command_capture.sh
. conformance/asserts.sh
pair_live_ownership_prepare "$PAIR" "$PORT1" "$PORT2" 'Lifecycle command context' wprism-lifecycle-context
COMPOSE=(docker compose -p "wprism-$PAIR" -f pair.yml)
wp_side() { local side="$1"; shift; "${COMPOSE[@]}" run --rm -T "cli$side" wp "$@"; }
sink=$(umask 077; mktemp -d "$ROOT/sandbox/tmp/lifecycle-context-native.XXXXXX")
printf 'Lifecycle context evidence for %s: %s\n' "$WPRISM_EXPECTED_SOURCE_SHA" "$sink"
capture() {
  local name="$1" suffix result=0; shift
  for suffix in stdout stderr exit; do (umask 077; set -C; : > "$sink/$name.$suffix"); done
  wprism_private_capture_stage "$sink" "$name" "$@" || result=$?
  [ "$result" -eq 0 ] || fail "$name exited $result; retained $sink/$name"
  php tests/fixtures/lifecycle-context-evidence.php admit "$sink/$name" "$PAIR"
  printf 'ok: %s\n' "$name"
}
observe() { wp_side 2 eval 'echo wp_json_encode(["admin" => is_admin(), "hook_state" => get_option("_wp_session_lifecycle_context_probe")]);'; }
install() {
  "${COMPOSE[@]}" run --rm -T --entrypoint sh \
    -v "$ROOT/sandbox/tests/fixtures/lifecycle-context-plugin.php:/fixture.php:ro" "cli$1" \
    -c 'mkdir -p wp-content/plugins/lifecycle-context-probe && cp /fixture.php wp-content/plugins/lifecycle-context-probe/context.php && echo installed'
}
WPRISM_DB_ENGINE=mariadb bash bin/pair.sh list
pair_live_ownership_acquire mariadb
pair_live_ownership_up
for side in 1 2; do
  capture "cron$side" wp_side "$side" config set DISABLE_WP_CRON true --raw
  capture "install$side" install "$side"
done
pair_live_ownership_repo_host
R1="$PAIR_LIVE_OWNERSHIP_SITE1" R2="$PAIR_LIVE_OWNERSHIP_SITE2"
cp site-repo.gitignore.template "$R1/.gitignore"
git -C "$R1" init -q -b evidence
git -C "$R2" init -q -b evidence
capture binding1 establish_core_environment_bindings wp_side /siterepo admin@example.test "http://localhost:$PORT1" "http://localhost:$PORT1" 1
capture before observe
publish() {
  local name="$1" active="$2"
  capture "$name-source" wp_side 1 option update active_plugins "$active" --format=json
  capture "$name-capture" wp_side 1 wprism capture --repo=/siterepo --format=json
  pair_live_ownership_repo_host
  git -C "$R1" add .gitignore site.wprism.json state
  git -C "$R1" -c user.name=wprism -c user.email=wprism@example.test commit -qm "$name lifecycle fixture"
  git -C "$R2" fetch -q "$R1" evidence
  git -C "$R2" merge -q --ff-only FETCH_HEAD
  capture "$name-binding2" establish_core_environment_bindings wp_side /siterepo admin@example.test "http://localhost:$PORT2" "http://localhost:$PORT2" 2
}
publish activate '["lifecycle-context-probe/context.php"]'
capture activate-deploy wp_side 2 wprism deploy --repo=/siterepo --format=json
capture activate-observe observe
capture repeat-deploy wp_side 2 wprism deploy --repo=/siterepo --format=json
capture repeat-observe observe
publish retire '[]'
capture retire-deploy wp_side 2 wprism deploy --repo=/siterepo --format=json
capture retire-observe observe
publish reactivate '["lifecycle-context-probe/context.php"]'
capture reactivate-deploy wp_side 2 wprism deploy --repo=/siterepo --format=json
capture reactivate-observe observe
php tests/fixtures/lifecycle-context-evidence.php final "$sink" "$PAIR"
pair_live_ownership_complete 'REGRESS_LIFECYCLE_COMMAND_CONTEXT PASSED'
