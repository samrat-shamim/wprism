#!/usr/bin/env bash
set -euo pipefail
PACKAGE_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
export WPRISM_ARTIFACT_PACKAGE="${PACKAGE_ROOT##*/}"
ROOT="$(cd "$PACKAGE_ROOT/../.." && pwd -P)"
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }
: "${CONF_PAIR:?unique pair required}" "${CONF1_PORT:?even port required}" "${CONF2_PORT:?successor port required}"
: "${WPRISM_EXPECTED_SOURCE_SHA:?exact clean source commit required}"
[[ "$WPRISM_EXPECTED_SOURCE_SHA" =~ ^[a-f0-9]{40}$ ]] \
  && [ "$(git -C "$ROOT" rev-parse HEAD)" = "$WPRISM_EXPECTED_SOURCE_SHA" ] \
  && [ -z "$(git -C "$ROOT" status --porcelain=v1 --untracked-files=all)" ] || fail 'clean target requires its exact clean checkout'
export WPRISM_SOURCE_ROOT="$ROOT" CONF_EXPECTED_SOURCE_SHA="$WPRISM_EXPECTED_SOURCE_SHA"
export CONFORMANCE_ENTRY_FILE="$PACKAGE_ROOT/fixtures/clean-target-entry.json"
cd "$ROOT/sandbox"
php ../tools/conformance-hooks.php "$(jq -c .entry "$CONFORMANCE_ENTRY_FILE")" "$PACKAGE_ROOT" >/dev/null
. tests/lib/pair_live_ownership.sh
pair_live_ownership_prepare "$CONF_PAIR" "$CONF1_PORT" "$CONF2_PORT" 'Importer clean target' importer-clean-target
bash bin/pair.sh list
pair_live_ownership_acquire "${WPRISM_DB_ENGINE:-mariadb}"
bash conformance/run.sh "${PACKAGE_ROOT##*/}"
pair_live_ownership_complete 'PASS: Importer clean target and verified owned-pair cleanup'
