#!/usr/bin/env bash
set -euo pipefail
REPOSITORY_ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/../../../.." && pwd -P)
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }
: "${CONF_PAIR:?unique pair required}" "${CONF1_PORT:?even port required}" "${CONF2_PORT:?successor port required}"
: "${WPRISM_EXPECTED_SOURCE_SHA:?exact clean source commit required}"
[[ "$WPRISM_EXPECTED_SOURCE_SHA" =~ ^[a-f0-9]{40}$ ]] \
  && [ "$(git -C "$REPOSITORY_ROOT" rev-parse HEAD)" = "$WPRISM_EXPECTED_SOURCE_SHA" ] \
  && [ -z "$(git -C "$REPOSITORY_ROOT" status --porcelain=v1 --untracked-files=all)" ] \
  || fail 'Qi + Visual Portfolio combination requires its exact clean checkout'
export WPRISM_SOURCE_ROOT="$REPOSITORY_ROOT" CONF_EXPECTED_SOURCE_SHA="$WPRISM_EXPECTED_SOURCE_SHA"
cd "$REPOSITORY_ROOT/sandbox"
. tests/lib/pair_live_ownership.sh
pair_live_ownership_prepare "$CONF_PAIR" "$CONF1_PORT" "$CONF2_PORT" \
  'Qi + Visual Portfolio combination' qi-vp-combination
bash bin/pair.sh list
pair_live_ownership_acquire "${WPRISM_DB_ENGINE:-mariadb}"
bash conformance/run.sh --scenario=qi-blocks-visual-portfolio
pair_live_ownership_complete 'PASS: Qi + Visual Portfolio combination and verified owned-pair cleanup'
