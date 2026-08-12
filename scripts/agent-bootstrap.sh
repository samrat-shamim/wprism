#!/usr/bin/env bash
# One idempotent, fail-loud command that brings a fresh clone on a fresh host
# to a verifiable state for LINEAR-LOOP work (docs/agents/linear-loop.md).
# Safe to re-run any time; re-run it whenever a verification step reports a
# missing tool ("a missing tool is a setup step, not a blocker").
#
# What it does NOT do: create sandbox pairs (that is per-issue work via
# sandbox/bin/pair.sh), touch Linear, or configure git identity.
set -euo pipefail
cd "$(dirname "$0")/.."

ok()   { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*" >&2; exit 1; }

# --- host prerequisites (verified, not installed — install per your OS) -----
command -v git  >/dev/null || fail "git not found"
command -v jq   >/dev/null || fail "jq not found (used by every sandbox script)"
command -v php  >/dev/null || fail "php CLI not found (used for php -l gates and offline test harnesses)"
command -v curl >/dev/null || fail "curl not found (render checks)"
command -v docker >/dev/null || fail "docker not found"
docker info >/dev/null 2>&1 || fail "docker daemon not running/reachable"
docker compose version >/dev/null 2>&1 || fail "docker compose v2 plugin not available"
ok "host prerequisites present (git, jq, php, curl, docker + compose v2)"

# gh is needed only for the close gate (PR merge verification).
if command -v gh >/dev/null && gh auth status >/dev/null 2>&1; then
    ok "gh authenticated (close gate available)"
else
    printf '\033[1;33mwarn: gh missing or unauthenticated — required before any PR/close-gate step, not for local verification\033[0m\n'
fi

# --- images (pre-pull so first pair.sh up is not a cold multi-minute pull) --
for img in mariadb:11 "${DUO_WP_IMAGE:-wordpress:7.0.3-php8.3-apache}" wordpress:cli-php8.3; do
    docker pull -q "$img" >/dev/null && ok "image present: $img"
done

# --- sanity: the sandbox tooling itself parses --------------------------------
bash -n sandbox/bin/pair.sh && ok "pair.sh parses"
DUO_PAIR=x DUO_PORT1=1 DUO_PORT2=2 docker compose -f sandbox/pair.yml config >/dev/null \
    && ok "pair.yml valid"

# --- current sandbox load (agents must respect the dynamic pair budget) ------
echo "--- current pairs on this host (pair.sh warns when over the host budget; stop idle pairs):"
bash sandbox/bin/pair.sh list || true

echo
ok "bootstrap complete — read docs/agents/linear-loop.md before claiming"
