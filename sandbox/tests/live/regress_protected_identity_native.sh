#!/usr/bin/env bash
# Shared UUID owner locking through ordinary Capture -> env-set. One owned
# core-manifest MariaDB lane, not concurrent gap-lock or identity-fork evidence.
set -euo pipefail
umask 022
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd -P)"
cd "$REPO_ROOT/sandbox"
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }
PAIR="${PROTECTED_IDENTITY_PAIR:?unique owned pair required}"
PORT1="${PROTECTED_IDENTITY_PORT1:?even port required}"
PORT2="${PROTECTED_IDENTITY_PORT2:?successor port required}"
EXPECTED_SHA="${WPRISM_EXPECTED_SOURCE_SHA:?exact candidate SHA required}"
[[ "$EXPECTED_SHA" =~ ^[a-f0-9]{40}$ ]] && [ "$(git rev-parse HEAD)" = "$EXPECTED_SHA" ] || fail 'wrong native evidence source'
[ -z "$(git status --porcelain=v1 --untracked-files=all)" ] || fail 'dirty native evidence source'
export WPRISM_SOURCE_ROOT="$REPO_ROOT" WPRISM_EXPECTED_SOURCE_SHA="$EXPECTED_SHA"
export WPRISM_PAIR="$PAIR" WPRISM_PORT1="$PORT1" WPRISM_PORT2="$PORT2" WPRISM_CODEBIND_PLUGIN=''
export WPRISM_ARTIFACT_LIBRARY_ROOT="$REPO_ROOT" CONF_PAIR="$PAIR"
. tests/lib/pair_live_ownership.sh
. tests/lib/private_command_capture.sh
. tests/lib/conformance_private_command.sh
pair_live_ownership_prepare "$PAIR" "$PORT1" "$PORT2" 'native protected identity evidence' 'wprism-protected-identity'
PAIR_COMPOSE=(docker compose -p "wprism-$PAIR" -f pair.yml)
fixture="$REPO_ROOT/sandbox/tests/fixtures/protected-identity-native.php"
sink=$(umask 077; mktemp -d "$REPO_ROOT/sandbox/tmp/protected-identity-native.XXXXXX")
printf 'Retained source-bound %s native streams: %s\n' "$EXPECTED_SHA" "$sink"
capture() {
    local stage="$1" suffix result=0
    shift
    [[ "$stage" =~ ^[a-z][a-z0-9-]{0,40}$ ]] || fail 'bounded evidence stage required'
    for suffix in stdout stderr exit; do (umask 077; set -C; : >"$sink/$stage.$suffix"); done
    wprism_private_capture_stage "$sink" "$stage" "$@" || result=$?
    [ "$result" -eq 0 ] || printf 'Retained nonzero stage %s (exit %s) under %s\n' "$stage" "$result" "$sink" >&2
    return "$result"
}
wp_source() { "${PAIR_COMPOSE[@]}" run --rm -T cli1 wp "$@"; }
observe() {
    "${PAIR_COMPOSE[@]}" run --rm -T -v "$fixture:/protected-identity-native.php:ro" cli1 \
        wp eval-file /protected-identity-native.php "$1" --use-include --user=admin
}
capture source php "$fixture" --source "$EXPECTED_SHA" "$PAIR"
bash bin/pair.sh list
pair_live_ownership_acquire mariadb
pair_live_ownership_up --headless
php "$fixture" --policy "$PAIR_LIVE_OWNERSHIP_SITE1"
cp site-repo.gitignore.template "$PAIR_LIVE_OWNERSHIP_SITE1/.gitignore"
git -C "$PAIR_LIVE_OWNERSHIP_SITE1" init -q -b main
git -C "$PAIR_LIVE_OWNERSHIP_SITE1" add site.wprism.json .gitignore
git -C "$PAIR_LIVE_OWNERSHIP_SITE1" -c user.name="wprism-$PAIR" -c user.email="$PAIR@example.test" commit -qm 'owned core policy'
capture seed observe seed </dev/null
capture capture wp_source wprism capture --repo=/siterepo --format=json </dev/null
capture before observe observe </dev/null
binding=$(php "$fixture" --binding "$sink/before" "$PAIR")
printf '%s\n' 'owned-positive-password' | capture positive wp_source wprism env-set --repo=/siterepo --name="$binding" --stdin --format=json
capture after-positive observe observe </dev/null
capture orphans observe orphans </dev/null
printf '%s\n' 'owned-orphan-password' | capture orphan-update wp_source wprism env-set --repo=/siterepo --name="$binding" --stdin --format=json
capture after-orphans observe observe </dev/null
capture duplicate observe duplicate </dev/null
refusal_status=0
printf '%s\n' 'must-not-publish-this-password' | capture refusal conformance_private_command cli1 env-set \
    wp_source wprism env-set --repo=/siterepo --name="$binding" --stdin --format=json || refusal_status=$?
[ "$refusal_status" -eq 1 ] || fail 'expected an exact exit-1 native refusal'
capture after-refusal observe observe </dev/null
php "$fixture" --admit "$sink" "$PAIR" "$EXPECTED_SHA"
pair_live_ownership_complete 'REGRESS_PROTECTED_IDENTITY_NATIVE PASSED'
