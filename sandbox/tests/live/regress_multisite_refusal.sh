#!/usr/bin/env bash
# issue #3223 live scope-boundary proof: v1 is single-site. A real WordPress
# multisite conversion must make the ordinary `wp wprism capture` product path
# fail loudly before repository publication or authored-state mutation.
# Own disposable pair; green runs destroy it, failures leave it for inspection.
set -euo pipefail
cd "$(dirname "$0")/../.."   # -> sandbox/

say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*" >&2; exit 1; }
. lib/pair_db.sh
pair_db_select_engine

PAIR="${MULTISITE_PAIR:-msrefusal}"
PORT1="${MULTISITE_PORT1:-8882}"
PORT2="${MULTISITE_PORT2:-8883}"
[[ "$PAIR" =~ ^[a-z][a-z0-9]*$ ]] || fail "invalid MULTISITE_PAIR '$PAIR'"
WORDPRESS_OFFLINE="${WPRISM_WORDPRESS_ORG_OFFLINE:-0}"
case "$WORDPRESS_OFFLINE" in
  0|1) ;;
  *) fail "WPRISM_WORDPRESS_ORG_OFFLINE must be 0 or 1" ;;
esac
export WPRISM_PAIR="$PAIR" WPRISM_PORT1="$PORT1" WPRISM_PORT2="$PORT2"
# A multisite refusal is still evidence about the mounted agent/manifests
# candidate. Plumb the optional explicit SHA before reset, whose DROP/CREATE
# is the first pair mutation; WPRISM_SOURCE_ROOT remains pair.sh's worktree
# override when the candidate is not the canonical checkout.
if [ -n "${MULTISITE_EXPECTED_SOURCE_SHA:-}" ]; then
  export WPRISM_EXPECTED_SOURCE_SHA="$MULTISITE_EXPECTED_SOURCE_SHA"
fi
COMPOSE=(docker compose -p "wprism-$PAIR" -f pair.yml -f pair.artifacts.yml)
PAIR_UP_FLAGS=(--artifacts)
if [ "$WORDPRESS_OFFLINE" = 1 ]; then
  COMPOSE+=(-f pair.wordpress-offline.yml)
  PAIR_UP_FLAGS+=(--wordpress-offline)
fi
export WPRISM_ARTIFACT_OFFLINE="$WORDPRESS_OFFLINE"
# shellcheck source=../../bin/fetch-artifact.sh
. bin/fetch-artifact.sh
validate_artifact_library \
  || fail "artifact library is malformed; multisite refusal proof stopped before pair reset"
wp1() { "${COMPOSE[@]}" run --rm -T cli1 wp "$@"; }
REPO="siterepo/${PAIR}1"

say "pair-budget preflight"
bash bin/pair.sh list

say "fresh pair $PAIR"
bash bin/pair.sh reset "$PAIR"
bash bin/pair.sh up "$PAIR" "$PORT1" "$PORT2" "${PAIR_UP_FLAGS[@]}"

say "convert side 1 to a real WordPress multisite"
wp1 core multisite-convert --title='WPrism Multisite Refusal' >/dev/null
[ "$(wp1 eval 'echo is_multisite() ? "yes" : "no";')" = yes ] \
  || fail "WordPress did not report is_multisite() after multisite-convert"
pass "WordPress reports multisite through its own runtime API"

say "prepare an otherwise-valid core site repository and mutation canary"
jq -n '{manifests:["core"],policy:{options:{},post_meta:{},post_types:["post","page","attachment"],taxonomies:["category","post_tag"]},spec_version:2}' > "$REPO/site.wprism.json"
cp site-repo.gitignore.template "$REPO/.gitignore"
wp1 option update wprism_multisite_refusal_canary untouched >/dev/null
SITE_BEFORE=$(shasum -a 256 "$REPO/site.wprism.json" | awk '{print $1}')

say "ordinary product path must refuse loudly and publish nothing"
set +e
CAPTURE_OUT=$(wp1 wprism capture --repo=/siterepo 2>&1)
CAPTURE_RC=$?
set -e
printf '%s\n' "$CAPTURE_OUT"
[ "$CAPTURE_RC" -ne 0 ] || fail "multisite capture returned success"
grep -qF 'multisite is unsupported by the certified v1 contract' <<<"$CAPTURE_OUT" \
  || fail "refusal did not name the unsupported multisite contract"
grep -qF 'single-site only' <<<"$CAPTURE_OUT" \
  || fail "refusal did not name the supported single-site boundary"
[ ! -e "$REPO/state" ] || fail "multisite refusal published a state directory"
[ ! -e "$REPO/state.capture-staging" ] || fail "multisite refusal leaked a capture staging directory"
[ ! -e "$REPO/state.capture-backup" ] || fail "multisite refusal leaked a capture backup directory"
[ "$(shasum -a 256 "$REPO/site.wprism.json" | awk '{print $1}')" = "$SITE_BEFORE" ] \
  || fail "multisite refusal mutated site.wprism.json"
[ "$(wp1 option get wprism_multisite_refusal_canary)" = untouched ] \
  || fail "multisite refusal mutated authored WordPress state"
pass "multisite is an actionable non-zero refusal with zero repository/authored-state mutation"

# (a) The JSON half of the same refusal. Before the gate became a typed
# CommandRefusalException, --format=json collapsed it to `capture_failed` /
# "capture refused at an unclassified safety gate" with details_redacted:true
# (agent/src/Command/Cli.php:84-85,:98), so the word "multisite" never reached
# a machine caller at all.
say "the same refusal, in the machine channel"
set +e
CAPTURE_JSON=$(wp1 wprism capture --repo=/siterepo --format=json 2>/dev/null)
CAPTURE_JSON_RC=$?
set -e
[ "$CAPTURE_JSON_RC" -ne 0 ] || fail "multisite capture --format=json returned success"
printf '%s\n' "$CAPTURE_JSON" | jq -e '
  .format == "wprism-command-refusal/v1"
  and .ok == false
  and .command == "capture"
  and .reason_code == "multisite_unsupported"
  and .error == "multisite_unsupported"
  and (has("details_redacted") | not)
  and (.message | contains("multisite is unsupported by the certified v1 contract"))
  and (.message | contains("single-site only"))
  and (.remediation | contains("single-site"))
' >/dev/null || { printf '%s\n' "$CAPTURE_JSON"; fail "the JSON refusal is not a named, unredacted wprism-command-refusal/v1"; }
pass "wp wprism capture --format=json names multisite_unsupported and redacts nothing"

# (b) journal-reset is Policy-free: it called Ledger::ensure() (four CREATE
# TABLEs on the serving blog's prefix) and then TRUNCATEd, with no gate at any
# layer. The table check is the load-bearing half -- the refusal must happen
# BEFORE the DDL, and no rollback in the shipped tree removes a wprism table.
say "journal-reset refuses before it can create or truncate anything"
set +e
JOURNAL_OUT=$(wp1 wprism journal-reset 2>&1)
JOURNAL_RC=$?
set -e
printf '%s\n' "$JOURNAL_OUT"
[ "$JOURNAL_RC" -ne 0 ] || fail "multisite journal-reset returned success"
grep -qF 'multisite is unsupported by the certified v1 contract' <<<"$JOURNAL_OUT" \
  || fail "journal-reset did not print the byte-identical multisite sentence"
grep -qF 'single-site only' <<<"$JOURNAL_OUT" \
  || fail "journal-reset refusal did not name the supported single-site boundary"
WPRISM_TABLES=$(wp1 db query "SHOW TABLES LIKE '%wprism\\_%'" --skip-column-names 2>/dev/null || true)
[ -z "$(printf '%s' "$WPRISM_TABLES" | tr -d '[:space:]')" ] \
  || fail "journal-reset created wprism tables on a network: $WPRISM_TABLES"
pass "journal-reset refuses with the same sentence and creates no wprism_* table"

# (c) The promotion lease verbs, same property: the lease row lives in
# {prefix}wprism_kv, and Ledger::ensure() would have created it.
say "promotion-begin refuses before taking a lease"
set +e
PROMOTION_JSON=$(wp1 wprism promotion-begin \
  --promotion-owner=multisite-refusal-probe \
  --artifact-hash=$(printf 'ab%.0s' $(seq 1 32)) \
  --format=json 2>/dev/null)
PROMOTION_RC=$?
set -e
[ "$PROMOTION_RC" -ne 0 ] || fail "multisite promotion-begin returned success"
printf '%s\n' "$PROMOTION_JSON" | jq -e '
  .command == "promotion-begin"
  and .reason_code == "multisite_unsupported"
  and (has("details_redacted") | not)
' >/dev/null || { printf '%s\n' "$PROMOTION_JSON"; fail "promotion-begin did not refuse with multisite_unsupported"; }
KV_TABLES=$(wp1 db query "SHOW TABLES LIKE '%wprism\\_kv'" --skip-column-names 2>/dev/null || true)
[ -z "$(printf '%s' "$KV_TABLES" | tr -d '[:space:]')" ] \
  || fail "promotion-begin created a wprism_kv table on a network: $KV_TABLES"
pass "promotion-begin refuses with the same reason code and creates no wprism_kv"

# (d) The host-side gate on the most destructive verb there is: step 3 of
# `wprism recover` is a stock `wp db import`, which on a network replaces every
# blog plus wp_users/wp_blogs/wp_sitemeta. Refused before step 1, so the
# target's checkpoint and lease state is untouched.
say "wprism recover refuses on the host, before step 1"
RECOVER_ENV="${MULTISITE_RECOVER_ENV:-}"
RECOVER_ID="${MULTISITE_RECOVER_ID:-}"
if [ -n "$RECOVER_ENV" ] && [ -n "$RECOVER_ID" ]; then
  CHECKPOINTS_BEFORE=$(wp1 eval 'echo (int) is_dir(ABSPATH . "../.wprism/checkpoints");' 2>/dev/null || echo 0)
  set +e
  RECOVER_JSON=$(php ../cli/wprism recover "$RECOVER_ENV" \
    --restore="$RECOVER_ID" --writers-excluded --format=json 2>/dev/null)
  RECOVER_RC=$?
  set -e
  [ "$RECOVER_RC" -ne 0 ] || fail "wprism recover returned success against a network"
  printf '%s\n' "$RECOVER_JSON" | jq -e '
    .format == "wprism-command-refusal/v1"
    and .command == "recover"
    and .reason_code == "recover_topology_unsupported"
  ' >/dev/null || { printf '%s\n' "$RECOVER_JSON"; fail "wprism recover did not refuse with recover_topology_unsupported"; }
  CHECKPOINTS_AFTER=$(wp1 eval 'echo (int) is_dir(ABSPATH . "../.wprism/checkpoints");' 2>/dev/null || echo 0)
  [ "$CHECKPOINTS_BEFORE" = "$CHECKPOINTS_AFTER" ] \
    || fail "the refused recovery changed the target's checkpoint state"
  KV_AFTER=$(wp1 db query "SHOW TABLES LIKE '%wprism\\_kv'" --skip-column-names 2>/dev/null || true)
  [ -z "$(printf '%s' "$KV_AFTER" | tr -d '[:space:]')" ] \
    || fail "the refused recovery took a lease on a network"
  pass "wprism recover refuses with recover_topology_unsupported and drives zero steps"
else
  # This pair has no promotion checkpoint to name, and MANUFACTURING one would
  # mean running a full release against a network -- exactly the mutation this
  # suite exists to prove never happens. The offline half
  # (sandbox/tests/offline/assess-contract/regress_recover_ordering.sh) drives
  # both reason codes and asserts zero steps against a recorded wp call log.
  pass "wprism recover host gate: covered offline; set MULTISITE_RECOVER_ENV/_ID to exercise it here"
fi

# (e) The pre-swap adoption proof is NOT drivable from a pair: `wprism adopt`
# reaches DockerTransport's driver preflight first, which refuses with
# "driver 'docker' does not implement 'environment.bootstrap'; no emulation
# is permitted" (cli/src/Transport/DockerTransport.php is not an
# AdoptionTransport — measured on this estate 2026-08-24), so no docker
# environment can ever reach Adopt::install()'s topology probe, let alone the
# swap. The ordering claim — the probe precedes the tar, the upload and the
# install script, and a network leaves $swapped false with nothing to roll back
# — is owned offline by sandbox/tests/offline/cli/regress_adopt_command.php,
# which drives Adopt::install() through the product path with a transport that
# answers multisite and asserts no command after the probe ever ran. A live
# pre-swap proof needs an adoptable transport (ssh: regress_ssh_adopt.sh's
# estate; local: regress_local_bootstrap_live.sh's controller container), which
# is a separate, heavier estate than this one-pair refusal suite.
say "wprism adopt pre-swap topology probe"
pass "adopt pre-swap ordering: covered offline (regress_adopt_command.php); a pair target is not adoptable over the docker driver"

printf '\n\033[1;32m✔ REGRESS_MULTISITE_REFUSAL PASSED\033[0m\n'

say "cleanup: destroy own disposable pair"
bash bin/pair.sh destroy "$PAIR"
pass "$PAIR destroyed"
