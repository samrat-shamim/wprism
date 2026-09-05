#!/usr/bin/env bash
# Live public-path regression for issue #3336. Owns and always destroys one pair.
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "$0")/../../.." && pwd)"
cd "$REPO_ROOT"

PAIR="${WPRISM_INIT_PAIR:-codexmaca3336}"
PORT1="${WPRISM_INIT_PORT1:-9300}"
PORT2="${WPRISM_INIT_PORT2:-9301}"
if [[ ! "$PAIR" =~ ^[a-z][a-z0-9]*$ ]]; then
  printf 'FAIL: invalid WPRISM_INIT_PAIR %q\n' "$PAIR" >&2
  exit 2
fi
if [[ ! "$PORT1" =~ ^[0-9]+$ ]] || [[ ! "$PORT2" =~ ^[0-9]+$ ]]; then
  printf 'FAIL: WPRISM init ports must be decimal integers\n' >&2
  exit 2
fi
PORT1=$((10#$PORT1))
PORT2=$((10#$PORT2))
if (( PORT1 < 8900 || PORT1 > 65534 || PORT1 % 2 != 0 || PORT2 != PORT1 + 1 )); then
  printf 'FAIL: WPRISM init ports must be an even port >=8900 plus its adjacent successor\n' >&2
  exit 2
fi

SOURCE_SHA="$(git rev-parse --verify 'HEAD^{commit}')" || {
  printf 'FAIL: init evidence source has no resolvable Git HEAD\n' >&2
  exit 2
}
if [[ ! -d "$REPO_ROOT/.git" ]]; then
  printf 'FAIL: init evidence must run from a standalone clone, not a linked worktree\n' >&2
  exit 2
fi
if [[ -n "${WPRISM_EXPECTED_SOURCE_SHA:-}" && "$WPRISM_EXPECTED_SOURCE_SHA" != "$SOURCE_SHA" ]]; then
  printf 'FAIL: WPRISM_EXPECTED_SOURCE_SHA %s does not equal init evidence HEAD %s\n' \
    "$WPRISM_EXPECTED_SOURCE_SHA" "$SOURCE_SHA" >&2
  exit 2
fi
if [[ -n "$(git status --porcelain --untracked-files=all)" ]]; then
  printf 'FAIL: init evidence checkout is dirty; use a clean standalone clone at %s\n' "$SOURCE_SHA" >&2
  exit 2
fi
export WPRISM_EXPECTED_SOURCE_SHA="$SOURCE_SHA"
HOST_REPO="$REPO_ROOT/sandbox/siterepo/${PAIR}1"
HOST_REPO2="$REPO_ROOT/sandbox/siterepo/${PAIR}2"
ENVS_FILE="$REPO_ROOT/sandbox/siterepo/${PAIR}-envs.json"
COMPOSE_FILE="$REPO_ROOT/sandbox/pair.yml"
ARTIFACTS_COMPOSE_FILE="$REPO_ROOT/sandbox/pair.artifacts.yml"
WORDPRESS_OFFLINE_COMPOSE_FILE="$REPO_ROOT/sandbox/pair.wordpress-offline.yml"
# issue #3421: the hermetic certification fixture this suite mounts instead of the
# live library (see its manufacture below) and the confirmation logs that are
# this suite's primary evidence when a paused confirmation misbehaves. Both are
# derived from the validated $PAIR, so the EXIT trap's removals stay bounded to
# paths this run owns.
HERMETIC_ROOT="/tmp/${PAIR}-init-manifests"
# issue #3499: the host-side release mirror the split leg classifies against, and
# its content-addressed cache. Both are derived from the validated $PAIR for the
# same reason HERMETIC_ROOT is -- the EXIT trap's removals stay bounded to paths
# this run owns.
CODE_MIRROR="/tmp/${PAIR}-code-artifacts"
INIT_LOGS=(
  "/tmp/${PAIR}-init-concurrent-1.log"
  "/tmp/${PAIR}-init-concurrent-2.log"
  "/tmp/${PAIR}-init-root-symlink.log"
  "/tmp/${PAIR}-init-root-directory.log"
)
# issue #3428: informational only. The certified clock is per-init and lives in
# time_golden_init() below; this stopwatch measures the HARNESS.
SUITE_STARTED_AT=$SECONDS

export WPRISM_PAIR="$PAIR"

say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }
. sandbox/lib/pair_db.sh
pair_db_select_engine

# cleanup [preserve_evidence]
#
# issue #3421: a failed run's confirmation logs ARE the diagnosis. The paused
# confirmations below write their whole answer — refusal envelope included — to
# /tmp/${PAIR}-init-*.log and nowhere else, so deleting them unconditionally
# left the one interesting failure in this suite (a confirmation that refuses
# before it can take the init lease) with no surviving evidence at all; the
# defect this argument closes cost four instrumentation rounds to see. On
# failure the logs and the hermetic fixture are kept and their paths printed;
# on success everything is removed exactly as before. The pair itself is
# destroyed either way — an owned pair is never leaked for evidence.
cleanup() {
  local preserve="${1:-0}" destroy_status=0 remaining="" evidence=""
  if [ "$preserve" = 1 ]; then
    printf 'preserved init evidence for %s (this run failed; nothing below was deleted):\n' "$PAIR" >&2
    for evidence in "${INIT_LOGS[@]}" "$HERMETIC_ROOT" "$CODE_MIRROR"; do
      if [ -e "$evidence" ]; then
        printf '  %s\n' "$evidence" >&2
      fi
    done
  fi
  if bash sandbox/bin/pair.sh destroy "$PAIR" >/dev/null 2>&1; then
    destroy_status=0
  else
    destroy_status=$?
  fi
  if (( destroy_status != 0 )); then
    printf 'FAIL: pair destroy failed for %s; preserving its repository artifacts\n' "$PAIR" >&2
    return "$destroy_status"
  fi
  if ! remaining=$(docker ps -a \
      --filter "label=com.docker.compose.project=wprism-${PAIR}" --format '{{.ID}}'); then
    printf 'FAIL: could not prove pair %s stopped; preserving its repository artifacts\n' "$PAIR" >&2
    return 1
  fi
  if [[ -n "$remaining" ]]; then
    printf 'FAIL: pair %s still has containers; preserving its repository artifacts\n' "$PAIR" >&2
    return 1
  fi
  rm -f "$ENVS_FILE"
  if [ "$preserve" != 1 ]; then
    rm -f "${INIT_LOGS[@]}" "/tmp/${PAIR}-committed-site.wprism.json"
    rm -rf "$HERMETIC_ROOT" "$CODE_MIRROR"
  fi
  rm -rf "$HOST_REPO" \
    "$REPO_ROOT/sandbox/siterepo/${PAIR}2" \
    "$REPO_ROOT/sandbox/siterepo/origin-${PAIR}.git"
}
cleanup_on_exit() {
  local status=$?
  if [ "$status" -ne 0 ]; then
    cleanup 1
  else
    cleanup
  fi
}
trap cleanup_on_exit EXIT

# The evidence pair is deliberately reusable. Start from the same verified
# clean-room boundary that the EXIT trap establishes so a prior interrupted
# or completed run cannot leak repository state into the read-only checks.
cleanup

assert_exit() {
  local expected="$1" description="$2"; shift 2
  set +e
  OUT=$("$@" 2>&1)
  CODE=$?
  set -e
  printf '%s\n' "$OUT"
  [ "$CODE" -eq "$expected" ] || fail "$description: expected exit $expected, got $CODE"
  pass "$description (exit $CODE)"
}

library_digest() { # library_digest <dir>
  find "$1" -type f -print0 | LC_ALL=C sort -z | xargs -0 sha256sum | sha256sum | awk '{print $1}'
}

# issue #3428: the certified `completed_within_fifteen_minutes` clock.
#
# The claim is issue #3336's, and issue #3336's title is per-init: an existing site
# initializes inside fifteen minutes. It was implemented as a whole-SUITE
# stopwatch -- `$SECONDS` captured at script start, compared to 900 once on the
# last line -- and then exported as a certified assertion. Those are not the
# same measurement and
# they do not even have the same subject: this suite installs WooCommerce,
# plants a 5000-row risk fixture, and drives roughly twenty injected-failure
# confirmations with about eighteen full code stagings between them, so the
# number it certified was the harness's own cost. At full green the suite runs
# ~60 minutes while one init measures a few hundred seconds, so a suite that
# proved the product fast would have failed the claim that says it is -- the
# certified assertion could only ever have been false, or vacuous behind an
# earlier failure.
#
# So the budget is applied where the claim lives: each golden-path init is
# timed on its OWN wall, proposal through confirmation, which is exactly the
# span an operator waits through, and every timed case must be inside the
# budget. Any case may be added here; the certified assertion is the whole set,
# and a set that lost its last member would certify nothing, so the count is
# pinned below too. The suite total is still measured and printed, because it
# is real operational data worth tracking (issue #3425) -- it is simply not what
# this claim certifies, and it is reported as an informational line that no
# assertion rests on.
INIT_BUDGET_SECONDS=900
INIT_TIMED_CASES_EXPECTED=1
INIT_TIMINGS=()

# time_golden_init <expected-exit> <label> <command...>
#
# assert_exit's contract is preserved verbatim -- exit status checked, $OUT
# left in place for the caller's greps -- with the per-init wall recorded and
# budgeted around it.
time_golden_init() {
  local expected="$1" label="$2"; shift 2
  local started=$SECONDS elapsed
  assert_exit "$expected" "$label" "$@"
  elapsed=$((SECONDS - started))
  INIT_TIMINGS+=("$elapsed"$'\t'"$label")
  [ "$elapsed" -le "$INIT_BUDGET_SECONDS" ] \
    || fail "$label took ${elapsed}s, over the per-init fifteen-minute budget (${INIT_BUDGET_SECONDS}s)"
  pass "$label completed in ${elapsed}s (per-init budget ${INIT_BUDGET_SECONDS}s)"
}

# assert_init_plan <wp1|wp2> <container-repo> <label>  ->  $INIT_PLAN_DIGEST
#
# Named assert_*, not require_*: issue #3408 reserves the `require_<name>() {`
# grammar to sandbox/conformance/asserts.sh, the single owner of the hook
# premise helpers, and regress_conformance_asserts.sh fails any second
# definition home. Only the name is reserved -- the "fixture manufacture
# failed:" message prefix below IS the cross-suite convention, and this keeps
# it.
#
# issue #3421, issue #3381's premise-before-behavior family. Nearly every case below
# manufactures its fixture the same way: take a fresh `wprism init --format=json`
# proposal, then feed its digest to a confirmation that is expected to fail in
# some exact injected way, then assert on what the repository retained. That
# manufacture was never asserted. A `docker compose run` under multi-agent host
# load can answer EMPTY (or noise-polluted) with exit status 0 — nothing for
# `set -e` to catch — and an empty digest makes the confirmation refuse BEFORE
# its first mutation, which every one of those cases then reports as the ENGINE
# losing a journal, a lock, or a payload. Observed live: "state-reservation
# failure lost its unmanifested root or sealed journal" on a run whose identical
# sequence passed standalone twice.
#
# Assert it once, here, with the grep-able "fixture manufacture failed:" prefix
# that marks an infrastructure signal rather than an accusation against WPrism. A
# digest is only usable if the proposal is a JSON object, is READY (a confirmed
# proposal must be), and carries the exact 64-hex identity the protocol binds.
# Sets a global instead of echoing: `fail` inside a command substitution would
# only kill the subshell and hand the caller an empty digest — the very failure
# this helper exists to make impossible.
INIT_PLAN_DIGEST=""
assert_init_plan() {
  local runner="$1" repo="$2" label="$3" plan ready digest rc=0
  INIT_PLAN_DIGEST=""
  # issue #3519: the validated proposal itself, for the one case that asserts on
  # its CONTENT rather than confirming its digest. Publishing it here is what
  # keeps that case inside the checked shape issue #3421 requires -- a proposal
  # must reach the suite through this helper, which proves manufacture (ready
  # + 64-hex digest) on a stdout-only capture, never through a bare
  # `X=$(wp1 wprism init ...)`.
  INIT_PLAN_JSON=""
  set +e
  plan=$("$runner" wprism init --repo="$repo" --format=json)
  rc=$?
  set -e
  [ "$rc" -eq 0 ] \
    || fail "fixture manufacture failed: $label proposal exited $rc for $repo: $plan"
  ready=$(jq -r 'if type == "object" then (.ready | tostring) else "not-an-object" end' <<<"$plan" 2>/dev/null) \
    || fail "fixture manufacture failed: $label proposal was not JSON for $repo: $plan"
  [ "$ready" = "true" ] \
    || fail "fixture manufacture failed: $label proposal is not ready (ready=$ready) for $repo: $plan"
  digest=$(jq -r '.digest // ""' <<<"$plan")
  [[ "$digest" =~ ^[a-f0-9]{64}$ ]] \
    || fail "fixture manufacture failed: $label proposal carried no 64-hex digest for $repo: $plan"
  INIT_PLAN_DIGEST="$digest"
  INIT_PLAN_JSON="$plan"
}

# issue #3421: every `wprism init` proposal below is capability-gated — init refuses
# to advertise confirmation while any selected adapter reports an unsupported
# row — so this pair must mount a manifest library whose reviewed claims are
# the shipped ones.
#
# It used to mount a RE-SEALED library, because the generated attestation bound
# the exact bytes of every certification-bound input and was expired by
# construction on any branch owing a reference bundle: `evidence_not_current`
# then rode on every certified claim, the paused swap-link/swap-directory
# confirmations refused instantly with the redacted not-ready envelope, never
# took the init lease, and the TOCTOU cases failed with a lease timeout that
# named none of that. The suite blocked on the evidence it existed to mint.
#
# That attestation is gone. The reviewed dispositions are the whole authored
# claim source and no branch state can expire them, so the mounted library is a
# straight hermetic COPY (sandbox/tests/offline/adapter/certification_fixture.php, shared with
# regress_adapter_sources.php). What is kept from that episode is the mount
# discipline: this pair still never mounts the primary checkout's own package
# or platform directory, so nothing a case does can reach the shipped bytes.
#
# Manufactured and asserted BEFORE the first Docker mutation, and never on the
# shipped library: a fixture whose manufacture silently failed would report the
# ENGINE as broken (issue #3381's premise-before-behavior family).
say "manufacture the hermetic source adapter library this pair will mount"
SHIPPED_PACKAGES_BEFORE=$(library_digest "$REPO_ROOT/adapter-packages")
SHIPPED_PLATFORM_BEFORE=$(library_digest "$REPO_ROOT/platform")
rm -rf "$HERMETIC_ROOT"
HERMETIC_SOURCE=$(php sandbox/tests/offline/adapter/certification_fixture.php --source-tree "$HERMETIC_ROOT") \
  || fail "fixture manufacture failed: could not build a hermetic source adapter library under $HERMETIC_ROOT"
[ "$HERMETIC_SOURCE" = "$HERMETIC_ROOT" ] \
  || fail "fixture manufacture failed: hermetic source landed at $HERMETIC_SOURCE, not at this run's owned scratch"
jq -e -s '[.[] | select(.status == "certified") | .evidence.tests | length] | all(. > 0)' \
  "$HERMETIC_SOURCE"/adapter-packages/*/package/disposition.json >/dev/null \
  || fail "fixture manufacture failed: a certified disposition cites no evidence"
jq -e '.format == "wprism-platform-boundary/v1"' \
  "$HERMETIC_SOURCE/platform/adapter-library/capabilities/platform.json" >/dev/null \
  || fail "fixture manufacture failed: the hermetic library has no platform boundary"
diff -r "$REPO_ROOT/adapter-packages" "$HERMETIC_SOURCE/adapter-packages" >/dev/null \
  || fail "fixture manufacture failed: the hermetic adapter packages are not shipped bytes"
diff -r "$REPO_ROOT/platform" "$HERMETIC_SOURCE/platform" >/dev/null \
  || fail "fixture manufacture failed: the hermetic library is not the shipped library byte for byte"
[ "$SHIPPED_PACKAGES_BEFORE" = "$(library_digest "$REPO_ROOT/adapter-packages")" ] \
  && [ "$SHIPPED_PLATFORM_BEFORE" = "$(library_digest "$REPO_ROOT/platform")" ] \
  || fail "fixture manufacture failed: building the fixture modified the shipped source adapter library"
pass "hermetic source adapter library built at $HERMETIC_SOURCE (shipped bytes unchanged)"

# pair.sh deliberately binds durable pairs to the primary checkout, and
# pair_compose_configure() re-resolves all three source mounts from the canonical
# root inside its own process for exactly that reason — so the long-lived wp1/
# wp2 web containers it creates below mount the canonical agent and library no
# matter what this suite exports, and nothing here tries to change that. This
# pair is disposable evidence for the current issue worktree, so every CLI
# invocation the suite actually drives is an ephemeral `run --rm` container;
# it resolves this checkout's agent plus the hermetic package and platform
# siblings from the environment. Exported before bring-up so the mount source
# is fixed and asserted before the first container exists.
export WPRISM_AGENT_SRC="$REPO_ROOT/agent"
export WPRISM_ADAPTER_PACKAGES_SRC="$HERMETIC_SOURCE/adapter-packages"
export WPRISM_PLATFORM_SRC="$HERMETIC_SOURCE/platform"

say "boot disposable authenticated Docker target on owned ports $PORT1/$PORT2"
unset WPRISM_CLI_IMAGE || true
WORDPRESS_OFFLINE="${WPRISM_WORDPRESS_ORG_OFFLINE:-0}"
case "$WORDPRESS_OFFLINE" in
  0|1) ;;
  *) fail "WPRISM_WORDPRESS_ORG_OFFLINE must be 0 or 1" ;;
esac
PAIR_UP_FLAGS=(--headless --artifacts)
COMPOSE=(docker compose -p "wprism-$PAIR" -f "$COMPOSE_FILE" -f "$ARTIFACTS_COMPOSE_FILE")
if [ "$WORDPRESS_OFFLINE" = 1 ]; then
  PAIR_UP_FLAGS+=(--wordpress-offline)
  COMPOSE+=(-f "$WORDPRESS_OFFLINE_COMPOSE_FILE")
fi
export WPRISM_ARTIFACT_OFFLINE="$WORDPRESS_OFFLINE"
bash sandbox/bin/pair.sh up "$PAIR" "$PORT1" "$PORT2" "${PAIR_UP_FLAGS[@]}"
wp1() { "${COMPOSE[@]}" run --rm -T cli1 wp "$@"; }
wp2() { "${COMPOSE[@]}" run --rm -T cli2 wp "$@"; }
git1() { "${COMPOSE[@]}" run --rm -T --entrypoint git cli1 -C /siterepo "$@"; }
PAIR_COMPOSE=("${COMPOSE[@]}")
# shellcheck source=../../bin/fetch-artifact.sh
. "$REPO_ROOT/sandbox/bin/fetch-artifact.sh"
repo_host() { bash sandbox/bin/pair.sh repo-host "$PAIR" "$1" >/dev/null; }
# wait_for_init_lease <repo> <confirmation-log>
#
# issue #3421: a confirmation that never takes the lease has ALREADY answered, in
# its own log, and that answer is the entire diagnosis — a not-ready proposal
# refuses before the paused phase is ever reached, which reads here as nothing
# but a timeout. Paste the log into the failure instead of making the next
# reader re-instrument the suite to see it (the issue #3413 evidence discipline).
wait_for_init_lease() {
  local repo="$1" log="$2" name holder
  name="wprism-init:$(php -r 'echo substr(hash("sha256", "wp_" . chr(0) . $argv[1]), 0, 48);' "$repo")"
  for _ in $(seq 1 100); do
    holder=$(docker exec -e MYSQL_PWD=root "$DB_CONTAINER" \
      "$DB_CLIENT" -uroot -N -B --raw -e "SELECT COALESCE(IS_USED_LOCK('$name'), 0)" 2>/dev/null || true)
    if [[ "$holder" =~ ^[1-9][0-9]*$ ]]; then
      return 0
    fi
    sleep 0.1
  done
  fail "confirmation never acquired the init lease for $repo; its own answer, from $log:
$(cat -- "$log" 2>&1)
(a not-ready proposal refuses before the paused phase — check the capability
rows above and the hermetic fixture at $HERMETIC_ROOT)"
}

say "install the exact certified WooCommerce boundary and representative authored entities"
WOO_ARTIFACT=$(fetch_artifact woocommerce 11.0.0 cli1 plugin) \
  || fail "WooCommerce 11.0.0 pinned artifact was unavailable"
wp1 plugin install "$WOO_ARTIFACT" --activate --force >/dev/null
[ "$(wp1 plugin get woocommerce --field=version | tr -d '\r')" = "11.0.0" ] \
  || fail "WooCommerce 11.0.0 was not installed"
ATTR_ID=$(wp1 wc product_attribute create --name='WPrism Init Material' --slug=wprisminit --type=select --order_by=menu_order --has_archives=false --user=admin --porcelain)
wp1 wc product_attribute_term create "$ATTR_ID" --name=Cotton --slug=cotton --user=admin >/dev/null
PRODUCT_ID=$(wp1 wc product create --name='WPrism Init Shirt' --type=variable \
  --attributes="[{\"id\":$ATTR_ID,\"variation\":true,\"visible\":true,\"options\":[\"Cotton\"]}]" \
  --status=publish --user=admin --porcelain)
wp1 wc product_variation create "$PRODUCT_ID" \
  --attributes="[{\"id\":$ATTR_ID,\"option\":\"Cotton\"}]" \
  --regular_price=24.00 --sku=WPRISM-INIT-COTTON --user=admin >/dev/null
pass "WooCommerce product, variation, and pa_wprisminit taxonomy exist"

mkdir -p "$(dirname "$ENVS_FILE")"
cat > "$ENVS_FILE" <<EOF
{
  "envs": {
    "${PAIR}1": {
      "transport": "docker",
      "compose_file": "$COMPOSE_FILE",
      "service": "cli1",
      "repo_path": "/siterepo"
    },
    "${PAIR}2": {
      "transport": "docker",
      "compose_file": "$COMPOSE_FILE",
      "service": "cli2",
      "repo_path": "/siterepo"
    },
    "${PAIR}root": {
      "transport": "docker",
      "compose_file": "$COMPOSE_FILE",
      "service": "cli1",
      "repo_path": "/siterepo/linked-root"
    },
    "${PAIR}dangling": {
      "transport": "docker",
      "compose_file": "$COMPOSE_FILE",
      "service": "cli1",
      "repo_path": "/siterepo/dangling-root"
    },
    "${PAIR}missing": {
      "transport": "docker",
      "compose_file": "$COMPOSE_FILE",
      "service": "cli1",
      "repo_path": "/siterepo/missing-root"
    },
    "${PAIR}ancestor": {
      "transport": "docker",
      "compose_file": "$COMPOSE_FILE",
      "service": "cli1",
      "repo_path": "/siterepo/ancestor-link/child"
    }
  }
}
EOF
WPRISM=("$REPO_ROOT/cli/wprism" "--envs-file=$ENVS_FILE")

say "missing target Git is an explicit pre-confirmation blocker"
assert_exit 2 "Git-unavailable target blocks init" "${WPRISM[@]}" init "${PAIR}1" --yes
grep -q 'git_unavailable' <<<"$OUT" || fail "missing Git blocker did not expose a stable reason code"
[ ! -e "$HOST_REPO/site.wprism.json" ] && [ ! -d "$HOST_REPO/state" ] \
  || fail "Git-unavailable proposal mutated the repository"
[ "$(wp1 db query "SHOW TABLES LIKE 'wp_wprism_%'" --skip-column-names | wc -l | tr -d ' ')" = "0" ] \
  || fail "Git-unavailable proposal created ledger tables"
pass "Git readiness is proven before confirmation"

say "build the evidence-only target image with Git installed"
WPRISM_CLI_IMAGE="wprism-init-cli-php83-git:${PAIR}"
docker build -q -f sandbox/init-cli.Dockerfile -t "$WPRISM_CLI_IMAGE" sandbox >/dev/null
export WPRISM_CLI_IMAGE
[ "$(wp1 eval 'echo trim((string) shell_exec("git --version"));')" != "" ] \
  || fail "Git-enabled evidence target did not expose Git to the agent"
pass "Git-enabled target fixture is ready"

say "repository-root links refuse before any child path can escape"
rm -rf "$HOST_REPO/missing-root"
assert_exit 2 "missing repository root blocks init" "${WPRISM[@]}" init "${PAIR}missing" --yes
grep -q 'repository_root_missing' <<<"$OUT" || fail "missing repository root omitted its bootstrap reason code"
[ ! -e "$HOST_REPO/missing-root" ] || fail "missing-root proposal materialized its repository directory"

wp1 eval '
$dir = ABSPATH . "wprism-init-external-root";
wp_mkdir_p($dir);
wp_mkdir_p($dir . "/adapters");
file_put_contents($dir . "/sentinel", "external root sentinel\n");
file_put_contents($dir . "/site.wprism.json", "{wprism-init-root-poison");
file_put_contents($dir . "/adapters/poison.json", "{wprism-init-adapter-poison");
' >/dev/null
EXTERNAL_ROOT_SHA=$(wp1 eval 'echo hash_file("sha256", ABSPATH . "wprism-init-external-root/sentinel");')
ln -s /var/www/html/wprism-init-external-root "$HOST_REPO/linked-root"
assert_exit 2 "symlinked repository root blocks init" "${WPRISM[@]}" init "${PAIR}root" --yes
grep -q 'unsafe_repository_root' <<<"$OUT" || fail "repository root link omitted its ownership reason code"
! grep -q 'wprism-init-root-poison\|wprism-init-adapter-poison' <<<"$OUT" \
  || fail "repository root refusal traversed an external config or adapter child"
[ "$EXTERNAL_ROOT_SHA" = "$(wp1 eval 'echo hash_file("sha256", ABSPATH . "wprism-init-external-root/sentinel");')" ] \
  || fail "repository root refusal changed the external sentinel"
rm -f "$HOST_REPO/linked-root"
wp1 eval '
$dir = ABSPATH . "wprism-init-external-root";
unlink($dir . "/sentinel"); unlink($dir . "/site.wprism.json");
unlink($dir . "/adapters/poison.json"); rmdir($dir . "/adapters"); rmdir($dir);
' >/dev/null

wp1 eval '@rmdir(ABSPATH . "wprism-init-missing-root");' >/dev/null
ln -s /var/www/html/wprism-init-missing-root "$HOST_REPO/dangling-root"
assert_exit 2 "dangling repository root blocks init" "${WPRISM[@]}" init "${PAIR}dangling" --yes
grep -q 'unsafe_repository_root' <<<"$OUT" || fail "dangling repository root omitted its ownership reason code"
[ -L "$HOST_REPO/dangling-root" ] && [ ! -e "$HOST_REPO/dangling-root" ] \
  || fail "dangling repository root refusal materialized its external target"
rm -f "$HOST_REPO/dangling-root"

wp1 eval '
$dir = ABSPATH . "wprism-init-ancestor-external/child";
wp_mkdir_p($dir . "/adapters");
file_put_contents($dir . "/sentinel", "ancestor sentinel\n");
file_put_contents($dir . "/site.wprism.json", "{wprism-init-ancestor-poison");
file_put_contents($dir . "/adapters/poison.json", "{wprism-init-ancestor-adapter-poison");
' >/dev/null
ANCESTOR_SENTINEL_SHA=$(wp1 eval 'echo hash_file("sha256", ABSPATH . "wprism-init-ancestor-external/child/sentinel");')
ln -s /var/www/html/wprism-init-ancestor-external "$HOST_REPO/ancestor-link"
assert_exit 2 "symlinked repository ancestor blocks init" "${WPRISM[@]}" init "${PAIR}ancestor" --yes
grep -q 'unsafe_repository_root' <<<"$OUT" || fail "repository ancestor link omitted its ownership reason code"
! grep -q 'wprism-init-ancestor-poison\|wprism-init-ancestor-adapter-poison' <<<"$OUT" \
  || fail "repository ancestor refusal traversed external child content"
[ ! -e "$HOST_REPO/ancestor-link/child/state.capture.lock" ] \
  && [ ! -e "$HOST_REPO/ancestor-link/child/.git" ] \
  && [ ! -e "$HOST_REPO/ancestor-link/child/code" ] \
  || fail "repository ancestor refusal mutated the external child"
[ "$ANCESTOR_SENTINEL_SHA" = "$(wp1 eval 'echo hash_file("sha256", ABSPATH . "wprism-init-ancestor-external/child/sentinel");')" ] \
  || fail "repository ancestor refusal changed the external sentinel"
rm -f "$HOST_REPO/ancestor-link"
wp1 eval '
$dir = ABSPATH . "wprism-init-ancestor-external/child";
unlink($dir . "/sentinel"); unlink($dir . "/site.wprism.json");
unlink($dir . "/adapters/poison.json"); rmdir($dir . "/adapters");
rmdir($dir); rmdir(dirname($dir));
' >/dev/null
pass "missing, terminal-link, dangling-link, and ancestor-link roots remain outside init ownership"

say "post-proposal repository replacement refuses before the first repository write"
mkdir -p "$HOST_REPO/swap-link"
assert_init_plan wp1 /siterepo/swap-link "post-proposal symlink swap"
SWAP_LINK_DIGEST="$INIT_PLAN_DIGEST"
wp1 eval '
$dir = ABSPATH . "wprism-init-swap-external";
wp_mkdir_p($dir . "/adapters");
file_put_contents($dir . "/sentinel", "swap sentinel\n");
file_put_contents($dir . "/site.wprism.json", "{wprism-init-swap-poison");
file_put_contents($dir . "/adapters/poison.json", "{wprism-init-swap-adapter-poison");
' >/dev/null
SWAP_SENTINEL_SHA=$(wp1 eval 'echo hash_file("sha256", ABSPATH . "wprism-init-swap-external/sentinel");')
set +e
"${COMPOSE[@]}" run --rm -T \
  -e WPRISM_TEST_MODE=1 -e WPRISM_TEST_INIT_PAUSE_MS=10000 \
  cli1 wp wprism init --repo=/siterepo/swap-link --confirm="$SWAP_LINK_DIGEST" --format=json \
  >"/tmp/${PAIR}-init-root-symlink.log" 2>&1 &
SWAP_LINK_PID=$!
set -e
wait_for_init_lease /siterepo/swap-link "/tmp/${PAIR}-init-root-symlink.log"
mv "$HOST_REPO/swap-link" "$HOST_REPO/swap-link-reviewed"
ln -s /var/www/html/wprism-init-swap-external "$HOST_REPO/swap-link"
set +e
wait "$SWAP_LINK_PID"; SWAP_LINK_CODE=$?
set -e
[ "$SWAP_LINK_CODE" -ne 0 ] || fail "post-proposal symlink replacement unexpectedly initialized"
! grep -q 'wprism-init-swap-poison\|wprism-init-swap-adapter-poison' "/tmp/${PAIR}-init-root-symlink.log" \
  || fail "post-proposal symlink replacement traversed external child content"
# issue #3516 added `.wprism` to this list. The four names above are the init
# transaction's own artifacts, and the swapped-in link never received one --
# but the redacted refusal's private evidence recorder DID follow this link and
# create `.wprism/refusals` in the external target, outside the repository
# entirely. The sibling swap-directory case caught the same hole only because
# it asserts emptiness rather than four names.
[ ! -e "$HOST_REPO/swap-link/state.capture.lock" ] \
  && [ ! -e "$HOST_REPO/swap-link/.git" ] \
  && [ ! -e "$HOST_REPO/swap-link/code" ] \
  && [ ! -e "$HOST_REPO/swap-link/state" ] \
  && [ ! -e "$HOST_REPO/swap-link/.wprism" ] \
  || fail "post-proposal symlink replacement received a repository write:
$(find "$HOST_REPO/swap-link/" -mindepth 1 -maxdepth 1 2>/dev/null | head -20)"
[ "$SWAP_SENTINEL_SHA" = "$(wp1 eval 'echo hash_file("sha256", ABSPATH . "wprism-init-swap-external/sentinel");')" ] \
  || fail "post-proposal symlink replacement changed the external sentinel"
rm -f "$HOST_REPO/swap-link"
rmdir "$HOST_REPO/swap-link-reviewed"
wp1 eval '
$dir = ABSPATH . "wprism-init-swap-external";
unlink($dir . "/sentinel"); unlink($dir . "/site.wprism.json");
unlink($dir . "/adapters/poison.json"); rmdir($dir . "/adapters"); rmdir($dir);
' >/dev/null

mkdir -p "$HOST_REPO/swap-directory" "$HOST_REPO/swap-directory-replacement"
assert_init_plan wp1 /siterepo/swap-directory "post-proposal directory swap"
SWAP_DIR_DIGEST="$INIT_PLAN_DIGEST"
set +e
"${COMPOSE[@]}" run --rm -T \
  -e WPRISM_TEST_MODE=1 -e WPRISM_TEST_INIT_PAUSE_MS=10000 \
  cli1 wp wprism init --repo=/siterepo/swap-directory --confirm="$SWAP_DIR_DIGEST" --format=json \
  >"/tmp/${PAIR}-init-root-directory.log" 2>&1 &
SWAP_DIR_PID=$!
set -e
wait_for_init_lease /siterepo/swap-directory "/tmp/${PAIR}-init-root-directory.log"
mv "$HOST_REPO/swap-directory" "$HOST_REPO/swap-directory-reviewed"
mv "$HOST_REPO/swap-directory-replacement" "$HOST_REPO/swap-directory"
set +e
wait "$SWAP_DIR_PID"; SWAP_DIR_CODE=$?
set -e
[ "$SWAP_DIR_CODE" -ne 0 ] || fail "post-proposal ordinary-directory replacement unexpectedly initialized"
# The cleanup below removes both directories, so a failure that only says
# "received a write" leaves the next reader with nothing to diagnose (it did,
# once). Name the entries and the refusal in the failure itself.
SWAP_DIR_WROTE=$(find "$HOST_REPO/swap-directory" -mindepth 1 -maxdepth 1 2>/dev/null | head -20)
SWAP_REVIEWED_WROTE=$(find "$HOST_REPO/swap-directory-reviewed" -mindepth 1 -maxdepth 1 2>/dev/null | head -20)
[ -z "$SWAP_DIR_WROTE" ] \
  || fail "replacement ordinary directory received a repository write before digest refusal:
$SWAP_DIR_WROTE
the refused init's own answer was:
$(cat "/tmp/${PAIR}-init-root-directory.log" 2>&1)"
[ -z "$SWAP_REVIEWED_WROTE" ] \
  || fail "reviewed ordinary directory retained a failed-init write:
$SWAP_REVIEWED_WROTE"
rmdir "$HOST_REPO/swap-directory" "$HOST_REPO/swap-directory-reviewed"
[ "$(wp1 db query "SHOW TABLES LIKE 'wp_wprism_%'" --skip-column-names | wc -l | tr -d ' ')" = "0" ] \
  || fail "root replacement tests created ledger tables"
pass "symlink and ordinary-directory replacement both refuse before repository or ledger mutation"

say "a partial first Git initialization is fully compensated"
assert_init_plan wp1 /siterepo "post-Git-create injected failure"
GIT_FAIL_DIGEST="$INIT_PLAN_DIGEST"
set +e
OUT=$("${COMPOSE[@]}" run --rm -T \
  -e WPRISM_TEST_MODE=1 -e WPRISM_TEST_INIT_FAIL_AFTER_GIT_CREATE=1 \
  cli1 wp wprism init --repo=/siterepo --confirm="$GIT_FAIL_DIGEST" --format=json 2>&1)
CODE=$?
set -e
printf '%s\n' "$OUT"
[ "$CODE" -ne 0 ] || fail "post-Git-create injected failure unexpectedly succeeded"
grep -q 'wprism-command-refusal/v1' <<<"$OUT" || fail "post-Git-create failure omitted its stable JSON envelope"
grep -q 'init_failed' <<<"$OUT" || fail "post-Git-create failure omitted its stable reason code"
grep -q 'details_redacted' <<<"$OUT" || fail "post-Git-create failure omitted its redaction witness"
! grep -q 'injected init failure after Git metadata creation' <<<"$OUT" \
  || fail "post-Git-create failure leaked private fault detail"
[ -z "$(find "$HOST_REPO" -mindepth 1 -maxdepth 1 -print -quit)" ] \
  || fail "post-Git-create failure left repository artifacts"
[ "$(wp1 db query "SHOW TABLES LIKE 'wp_wprism_%'" --skip-column-names | wc -l | tr -d ' ')" = "0" ] \
  || fail "post-Git-create failure created ledger tables"
pass "failed first Git initialization leaves the repository retryable"

say "repository-owned config and code roots never traverse external links"
wp1 eval '
$seed = [
    "manifests" => ["core"],
    "policy" => [
        "options" => [], "post_meta" => [], "term_meta" => [],
        "post_types" => ["post", "page", "attachment"],
        "taxonomies" => ["category", "post_tag"],
    ],
    "spec_version" => WPRISM_SPEC_VERSION,
];
file_put_contents(ABSPATH . "wprism-init-external-site.json", \WPrism\Canon::encode($seed));
' >/dev/null
EXTERNAL_SITE_SHA=$(wp1 eval 'echo hash_file("sha256", ABSPATH . "wprism-init-external-site.json");')
ln -s /var/www/html/wprism-init-external-site.json "$HOST_REPO/site.wprism.json"
assert_exit 2 "symlinked valid adoption seed blocks init" "${WPRISM[@]}" init "${PAIR}1" --yes
grep -q 'unsafe_site_config' <<<"$OUT" || fail "site config symlink omitted its ownership reason code"
[ "$EXTERNAL_SITE_SHA" = "$(wp1 eval 'echo hash_file("sha256", ABSPATH . "wprism-init-external-site.json");')" ] \
  || fail "site config refusal changed the external adoption-seed sentinel"
[ -L "$HOST_REPO/site.wprism.json" ] && [ ! -d "$HOST_REPO/state" ] \
  || fail "site config symlink refusal mutated the repository"
rm -f "$HOST_REPO/site.wprism.json"
wp1 eval 'unlink(ABSPATH . "wprism-init-external-site.json");' >/dev/null

wp1 eval '@unlink(ABSPATH . "wprism-init-missing-site.json");' >/dev/null
ln -s /var/www/html/wprism-init-missing-site.json "$HOST_REPO/site.wprism.json"
assert_exit 2 "dangling site config symlink blocks init" "${WPRISM[@]}" init "${PAIR}1" --yes
grep -q 'unsafe_site_config' <<<"$OUT" || fail "dangling site config omitted its ownership reason code"
[ -L "$HOST_REPO/site.wprism.json" ] && [ ! -d "$HOST_REPO/state" ] \
  || fail "dangling site config refusal mutated the repository"
rm -f "$HOST_REPO/site.wprism.json"

wp1 eval '
$dir = ABSPATH . "wprism-init-external-code";
wp_mkdir_p($dir);
file_put_contents($dir . "/sentinel", "external code sentinel\n");
' >/dev/null
EXTERNAL_CODE_SHA=$(wp1 eval 'echo hash_file("sha256", ABSPATH . "wprism-init-external-code/sentinel");')
ln -s /var/www/html/wprism-init-external-code "$HOST_REPO/code"
assert_exit 2 "symlinked code root blocks init" "${WPRISM[@]}" init "${PAIR}1" --yes
grep -q 'unsafe_code_root' <<<"$OUT" || fail "code root symlink omitted its ownership reason code"
[ "$EXTERNAL_CODE_SHA" = "$(wp1 eval 'echo hash_file("sha256", ABSPATH . "wprism-init-external-code/sentinel");')" ] \
  || fail "code root refusal changed the external sentinel"
[ -L "$HOST_REPO/code" ] && [ ! -e "$HOST_REPO/site.wprism.json" ] && [ ! -d "$HOST_REPO/state" ] \
  || fail "code root symlink refusal mutated the repository"
rm -f "$HOST_REPO/code"
wp1 eval 'unlink(ABSPATH . "wprism-init-external-code/sentinel"); rmdir(ABSPATH . "wprism-init-external-code");' >/dev/null
pass "site config and code publication remain inside ordinary repository-owned roots"

say "an unenumerable repository root is never mistaken for an empty one"
chmod 0333 "$HOST_REPO"
set +e
OUT=$("${WPRISM[@]}" init "${PAIR}1" --yes 2>&1)
CODE=$?
set -e
chmod 0777 "$HOST_REPO"
printf '%s\n' "$OUT"
[ "$CODE" -eq 2 ] || fail "unreadable repository root: expected exit 2, got $CODE"
grep -q 'unreadable_repository_root' <<<"$OUT" || fail "unreadable root omitted its ownership reason code"
[ ! -e "$HOST_REPO/site.wprism.json" ] && [ ! -d "$HOST_REPO/state" ] \
  || fail "unreadable repository refusal mutated the repository"
pass "repository ownership requires a complete directory enumeration"

say "site-owned adapter provenance is visible and remains uncertified"
mkdir -p "$HOST_REPO/adapters"
wp1 eval '
$dir = WP_PLUGIN_DIR . "/wprism-init-site";
wp_mkdir_p($dir);
file_put_contents($dir . "/wprism-init-site.php", "<?php\n/* Plugin Name: WPrism Init Site Adapter\nVersion: 1.0.0 */\n");
' >/dev/null
wp1 plugin activate wprism-init-site >/dev/null
# issue #3421: [1.0.0, 2.0.0), not [1.0.0, 1.0.0). Policy's assert_min_max_range()
# has required min STRICTLY less than max since issue #3222 ("wildcards, empty,
# and unbounded forms are not certifiable"), so the original min == max fixture
# made Policy::load() throw before the site adapter could be reported at all:
# init answered with the redacted unclassified envelope (exit 1) instead of the
# uncertified-source blocker this case exists to assert (exit 2). Nothing ever
# saw it, because the evidence-coupled lease timeout above aborted every run
# before this line. Verified live: with the range repaired the proposal reports
# adapter_source_uncertified for wprism-init-site. (It used to report
# evidence_not_current beside it on an unsealed library; that code is gone with
# the generated attestation, so the source blocker now stands alone.)
cat > "$HOST_REPO/adapters/wprism-init-site.json" <<'JSON'
{"name":"wprism-init-site","option_autoload":"preserve","options":{"wprism_init_site_option":{"class":"authored"}},"plugin":"wprism-init-site/wprism-init-site.php","spec_version":2,"version_range":{"min":"1.0.0","max":"2.0.0"}}
JSON
SITE_ADAPTER_BEFORE=$(sha256sum "$HOST_REPO/adapters/wprism-init-site.json" | awk '{print $1}')
assert_exit 2 "uncertified site adapter blocks init" "${WPRISM[@]}" init "${PAIR}1" --yes
grep -q 'adapter_source_uncertified' <<<"$OUT" || fail "site adapter omitted its source-specific reason code"
grep -q 'adapters/wprism-init-site.json' <<<"$OUT" || fail "site adapter refusal omitted repository-relative provenance"
! grep -q 'active_plugin_without_adapter' <<<"$OUT" || fail "site adapter was falsely reported as absent"
[ "$SITE_ADAPTER_BEFORE" = "$(sha256sum "$HOST_REPO/adapters/wprism-init-site.json" | awk '{print $1}')" ] \
  || fail "read-only proposal changed the site adapter source"
[ ! -e "$HOST_REPO/site.wprism.json" ] && [ ! -d "$HOST_REPO/state" ] \
  || fail "uncertified site adapter proposal mutated the repository"
[ "$(wp1 db query "SHOW TABLES LIKE 'wp_wprism_%'" --skip-column-names | wc -l | tr -d ' ')" = "0" ] \
  || fail "uncertified site adapter proposal created ledger tables"
wp1 plugin deactivate wprism-init-site >/dev/null
wp1 plugin delete wprism-init-site >/dev/null
rm -rf "$HOST_REPO/adapters"
pass "site adapter source remains distinct from a missing shipped adapter"

say "the adapters allowlist never launders a foreign file or symlink"
# issue #3421: these two go through the PUBLIC HOST CLI, whose contract for a
# refusal is the rendered envelope (render_command_refusal_human), not the
# machine JSON — `wprism init` is a human surface, and cmd_status()/fetch_pending()
# established that shape in issue #3399. The stable reason code, the remediation,
# and the redaction witness must all survive the transport; the raw
# wprism-command-refusal/v1 document is asserted where it belongs, on the direct
# --format=json invocation above. Until issue #3421 the host preferred stderr,
# which for a docker transport is never empty (compose writes "Container ...
# Creating" there on every run), so all three were replaced by that noise.
printf 'foreign repository payload\n' > "$HOST_REPO/adapters"
assert_exit 1 "regular-file adapter boundary blocks init" "${WPRISM[@]}" init "${PAIR}1" --yes
grep -qF '[init_failed]' <<<"$OUT" || fail "regular-file adapter refusal omitted its stable rendered envelope and reason code"
grep -q 'details: redacted from machine output' <<<"$OUT" || fail "regular-file adapter refusal omitted its redaction witness"
grep -q 'correct the named init blocker' <<<"$OUT" || fail "regular-file adapter refusal omitted its remediation"
! grep -q 'Container wprism-' <<<"$OUT" || fail "regular-file adapter refusal surfaced transport noise instead of the target's answer"
! grep -q 'exists but is not a real directory' <<<"$OUT" || fail "regular-file adapter refusal leaked private ownership detail"
[ ! -e "$HOST_REPO/site.wprism.json" ] && [ ! -d "$HOST_REPO/state" ] \
  || fail "regular-file adapter boundary mutated the repository"
rm -f "$HOST_REPO/adapters"
ln -s /tmp/wprism-init-missing-adapters "$HOST_REPO/adapters"
assert_exit 1 "dangling adapter symlink blocks init" "${WPRISM[@]}" init "${PAIR}1" --yes
grep -qF '[init_failed]' <<<"$OUT" || fail "adapter symlink refusal omitted its stable rendered envelope and reason code"
grep -q 'details: redacted from machine output' <<<"$OUT" || fail "adapter symlink refusal omitted its redaction witness"
grep -q 'correct the named init blocker' <<<"$OUT" || fail "adapter symlink refusal omitted its remediation"
! grep -q 'Container wprism-' <<<"$OUT" || fail "adapter symlink refusal surfaced transport noise instead of the target's answer"
! grep -q 'exists but is not a real directory' <<<"$OUT" || fail "adapter symlink refusal leaked private ownership detail"
[ ! -e "$HOST_REPO/site.wprism.json" ] && [ ! -d "$HOST_REPO/state" ] \
  || fail "adapter symlink boundary mutated the repository"
rm -f "$HOST_REPO/adapters"
pass "only a real repository-owned adapters directory is allowlisted"

say "unknown active plugin is an explicit blocker and the proposal is read-only"
wp1 eval '
$dir = WP_PLUGIN_DIR . "/wprism-init-unknown";
wp_mkdir_p($dir);
file_put_contents($dir . "/wprism-init-unknown.php", "<?php\n/* Plugin Name: WPrism Init Unknown */\n");
' >/dev/null
wp1 plugin activate wprism-init-unknown >/dev/null
assert_exit 2 "unsupported active plugin blocks init" "${WPRISM[@]}" init "${PAIR}1" --yes
grep -q 'active_plugin_without_adapter' <<<"$OUT" || fail "blocker did not expose a stable reason code"
grep -q 'no configuration, state, identity, or ledger mutation was made' <<<"$OUT" \
  || fail "blocked output did not state its no-write contract"
[ ! -e "$HOST_REPO/site.wprism.json" ] && [ ! -d "$HOST_REPO/state" ] \
  || fail "blocked proposal mutated the repository"
[ "$(wp1 db query "SHOW TABLES LIKE 'wp_wprism_%'" --skip-column-names | wc -l | tr -d ' ')" = "0" ] \
  || fail "blocked proposal created ledger tables"
wp1 plugin deactivate wprism-init-unknown >/dev/null
wp1 plugin delete wprism-init-unknown >/dev/null
pass "unsupported extension stayed outside configuration and state"

say "large risk discovery is deterministic and serialized objects never execute"
wp1 eval '
$dir = WP_PLUGIN_DIR . "/wprism-init-risk-canary";
wp_mkdir_p($dir);
$php = <<<'"'"'PHP'"'"'
<?php
/* Plugin Name: WPrism Init Risk Canary */
class WPrism_Init_Risk_Canary {
    public string $email = "sensitive-person@example.test";
    public function __wakeup(): void { update_option("wprism_init_wakeup_ran", "yes"); }
}
PHP;
file_put_contents($dir . "/wprism-init-risk-canary.php", $php);
' >/dev/null
wp1 plugin activate wprism-init-risk-canary >/dev/null
wp1 eval '
global $wpdb;
$object = new WPrism_Init_Risk_Canary();
add_user_meta(1, "wprism_init_email_surface", serialize($object));
for ($start = 0; $start < 5001; $start += 250) {
    $optionRows = [];
    $metaRows = [];
    for ($i = $start; $i < min(5001, $start + 250); $i++) {
        $optionRows[] = $wpdb->prepare("(%s,%s,%s)", "wprism_init_risk_$i", "plain-$i", "no");
        $metaRows[] = $wpdb->prepare("(%d,%s,%s)", 1, "wprism_init_risk_$i", "plain-$i");
    }
    $wpdb->query("INSERT INTO {$wpdb->options} (option_name,option_value,autoload) VALUES " . implode(",", $optionRows));
    $wpdb->query("INSERT INTO {$wpdb->usermeta} (user_id,meta_key,meta_value) VALUES " . implode(",", $metaRows));
}
add_option("wprism_init_risk_oversized", str_repeat("O", 70000), "", "no");
add_user_meta(1, "wprism_init_risk_oversized", str_repeat("M", 70000));
' >/dev/null
RISK_A=$(wp1 wprism init --repo=/siterepo --format=json)
RISK_B=$(wp1 wprism init --repo=/siterepo --format=json)
[ "$(jq -r .digest <<<"$RISK_A")" = "$(jq -r .digest <<<"$RISK_B")" ] \
  || fail "unchanged >5000-row risk surfaces produced different proposal digests"
jq -e '.state.risk_surfaces.truncated == true and (.state.risk_surfaces.user_meta["email address"] // 0) >= 1' \
  <<<"$RISK_A" >/dev/null || fail "bounded deterministic risk report omitted redacted PII surface"
jq -e '.state.risk_surfaces.oversized.options >= 1 and .state.risk_surfaces.oversized.user_meta >= 1' \
  <<<"$RISK_A" >/dev/null || fail "oversized risk omissions were reported as a complete scan"
! grep -q 'sensitive-person@example.test' <<<"$RISK_A" \
  || fail "risk report exposed a raw PII value"
if wp1 option get wprism_init_wakeup_ran >/dev/null 2>&1; then
  fail "read-only proposal executed a serialized-object wakeup hook"
fi
[ "$(wp1 db query "SHOW TABLES LIKE 'wp_wprism_%'" --skip-column-names | wc -l | tr -d ' ')" = "0" ] \
  || fail "risk proposal created ledger tables"
wp1 eval '
global $wpdb;
delete_user_meta(1, "wprism_init_email_surface");
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\''wprism\\_init\\_risk\\_%'\''");
$wpdb->query("DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE '\''wprism\\_init\\_risk\\_%'\''");
' >/dev/null
wp1 plugin deactivate wprism-init-risk-canary >/dev/null
wp1 plugin delete wprism-init-risk-canary >/dev/null
pass "risk digest and no-object-execution boundary are deterministic"

say "high-confidence credentials in captured code block with redacted output"
wp1 eval '
$token = "sk_" . "live_" . str_repeat("A", 24);
$payload = str_repeat("x", 32763) . "\n" . $token;
file_put_contents(WP_PLUGIN_DIR . "/woocommerce/wprism-init-secret.php", $payload);
' >/dev/null
assert_exit 2 "credential-bearing active code blocks init" "${WPRISM[@]}" init "${PAIR}1" --yes
grep -q 'credential_bearing_code_file' <<<"$OUT" || fail "code credential blocker omitted its reason code"
grep -q 'stripe key' <<<"$OUT" || fail "code credential blocker omitted its redacted label"
! grep -q 'sk_live_' <<<"$OUT" || fail "code credential blocker exposed the credential value"
[ ! -e "$HOST_REPO/site.wprism.json" ] && [ ! -d "$HOST_REPO/state" ] \
  || fail "credential-bearing proposal mutated the repository"
wp1 eval 'unlink(WP_PLUGIN_DIR . "/woocommerce/wprism-init-secret.php");' >/dev/null
pass "captured code secret guard is value-redacted and fail-closed"

say "long JWT credentials cannot cross beyond the former short overlap"
wp1 eval '
$jwt = "eyJ" . str_repeat("A", 700) . ".eyJ" . str_repeat("B", 24) . ".signature";
$payload = str_repeat("x", 32067) . "\n" . $jwt;
file_put_contents(WP_PLUGIN_DIR . "/woocommerce/wprism-init-jwt.php", $payload);
' >/dev/null
# issue #3519: this case asserted exit 2 until 2026-08-21. It has not blocked
# since #476 (2026-08-19), which deliberately reclassified a COMPLETE JWT in
# shipped code from blocking to advisory: shipped code carries public tokens
# (Yoast's OIDC software statement, id-token fixtures) far more often than live
# credentials, and the hard block refused `wprism init` on every site running that
# plugin (T7 grind A4). The rationale and the line it draws live on
# InitCodeInventory::ADVISORY_SECRET_LABELS, and InitCodeBaseline's staged-code
# gate already agrees with it through blockingSecretLabel().
#
# The SUBJECT of the case is unchanged and is why it still exists: a JWT that
# straddles the 32KB streaming read must still be FOUND. Detection is now
# visible as the advisory rather than as a refusal, so that is what is asserted
# -- named, redacted, and explicitly not the blocking reason code.
# Deliberately the READ-ONLY proposal, not a confirmation. Detection is a
# proposal-time property, so confirming proves nothing extra -- and a completing
# init here would mint durable `_wprism_uuid` identities into wp_postmeta, which is
# SITE state that no reset in this file clears (repo_host/find -delete and the
# DROP TABLE idiom clear the repository and the ledger, and nothing clears the
# site). Those identities then survive into the kill-phase loop below, whose
# assertions require a pristine site, and it fails there at
# "recovery left minted identities" -- an unrelated case, broken from here.
# WPrism minting identities on a successful init and keeping them is correct and
# intended; the mistake was making a DETECTION case complete an init at all.
# Through assert_init_plan, like every other proposal in this file: it captures
# stdout only (a docker transport writes "Container ... Creating" to STDERR on
# every invocation, which would make the JSON unparseable) and proves the
# proposal was manufactured -- ready, with a 64-hex digest -- before anything
# reads it. issue #3421's pin requires that shape; regress_init_contract.php
# enforces it offline over this file.
assert_init_plan wp1 /siterepo "cross-chunk JWT"
jq -e '[.advisories[] | select(.code == "jwt_in_code_file")] | length == 1' <<<"$INIT_PLAN_JSON" >/dev/null \
  || fail "cross-chunk JWT proposal did not carry exactly one jwt_in_code_file advisory: $INIT_PLAN_JSON"
jq -e '[.unsupported[] | select(.code == "credential_bearing_code_file")] | length == 0' <<<"$INIT_PLAN_JSON" >/dev/null \
  || fail "cross-chunk JWT was reported as a blocking credential after #476 made it advisory"
! grep -q 'eyJAAAA' <<<"$INIT_PLAN_JSON" || fail "cross-chunk JWT advisory exposed the credential value"
[ ! -e "$HOST_REPO/site.wprism.json" ] && [ ! -d "$HOST_REPO/state" ] \
  || fail "cross-chunk JWT proposal mutated the repository"
wp1 eval 'unlink(WP_PLUGIN_DIR . "/woocommerce/wprism-init-jwt.php");' >/dev/null
pass "bounded JWT matcher covers streaming chunk boundaries and states the finding without blocking"

say "foreign state, media, capture receipts, and non-pristine ledger ownership refuse before writes"
mkdir -p "$HOST_REPO/state" "$HOST_REPO/media"
assert_exit 2 "foreign state/media block init" "${WPRISM[@]}" init "${PAIR}1" --yes
grep -q 'existing_state_payload' <<<"$OUT" || fail "stale state blocker was missing"
grep -q 'existing_media_payload' <<<"$OUT" || fail "stale media blocker was missing"
rmdir "$HOST_REPO/state" "$HOST_REPO/media"
wp1 eval '
$receipt = [
    "format" => "wprism-capture-receipt/v1",
    "intent_id" => str_repeat("a", 32),
    "phase" => "committed",
    "candidate_sha256" => str_repeat("b", 64),
    "previous_sha256" => str_repeat("c", 64),
    "committed_at" => "2026-08-10T00:00:00+00:00",
];
$receipt["record_sha256"] = hash("sha256", \WPrism\Canon::encode($receipt));
\WPrism\Canon::write_file("/siterepo/state.capture-receipt", \WPrism\Canon::encode($receipt));
' >/dev/null
RECEIPT_BEFORE=$(sha256sum "$HOST_REPO/state.capture-receipt" | awk '{print $1}')
assert_exit 2 "durable orphan capture receipt blocks init" "${WPRISM[@]}" init "${PAIR}1" --yes
grep -q 'existing_capture_receipt' <<<"$OUT" || fail "capture receipt blocker was missing"
[ "$RECEIPT_BEFORE" = "$(sha256sum "$HOST_REPO/state.capture-receipt" | awk '{print $1}')" ] \
  || fail "capture receipt refusal rewrote durable audit evidence"
[ ! -e "$HOST_REPO/site.wprism.json" ] && [ ! -d "$HOST_REPO/code" ] \
  && [ ! -d "$HOST_REPO/state" ] && [ ! -d "$HOST_REPO/media" ] \
  || fail "capture receipt refusal created canonical repository payloads"
[ "$(wp1 db query "SHOW TABLES LIKE 'wp_wprism_%'" --skip-column-names | wc -l | tr -d ' ')" = "0" ] \
  || fail "capture receipt refusal created ledger tables"
rm -f "$HOST_REPO/state.capture-receipt"
# issue #3497: a site booted with WPRISM_JOURNAL on reaches init holding observation
# rows and nothing else — Journal::flush() calls Ledger::ensure(), so all four
# tables exist with zero identity rows. That is not an abandoned baseline, and
# the freshness probe no longer reads it as one. Proposal-only (no --confirm),
# so this stays read-only and leaves the following stale-ledger case untouched.
wp1 eval '
\WPrism\Ledger::ensure();
global $wpdb;
$wpdb->query($wpdb->prepare(
    "INSERT INTO {$wpdb->prefix}wprism_journal (t, op, tbl, item, surface, actor, caps, hook, proposal)"
    . " VALUES (%s, %s, %s, %s, %s, %d, %s, %s, %s)",
    "2026-08-21 00:00:00", "UPDATE", "options", "wprism_init_journal_only", "admin", 0, "", "", "review"
));
' >/dev/null
JOURNAL_ONLY_PROPOSAL=$(wp1 wprism init --repo=/siterepo --format=json)
if grep -q 'existing_wprism_ledger' <<<"$JOURNAL_ONLY_PROPOSAL"; then
  fail "journal-only observations were reported as an existing WPrism ledger"
fi
grep -q 'retained_journal_observations' <<<"$JOURNAL_ONLY_PROPOSAL" \
  || fail "journal-only observations produced no retention advisory"
[ "$(wp1 db query 'SELECT COUNT(*) FROM wp_wprism_journal' --skip-column-names | tr -d '[:space:]')" = "1" ] \
  || fail "the init proposal mutated the provenance journal it reported on"
wp1 db query 'TRUNCATE TABLE wp_wprism_journal' >/dev/null
wp1 eval '\WPrism\Ledger::ensure(); \WPrism\Ledger::kv_set("wprism_init_stale", "1");' >/dev/null
assert_exit 2 "non-pristine ledger blocks init" "${WPRISM[@]}" init "${PAIR}1" --yes
grep -q 'existing_wprism_ledger' <<<"$OUT" || fail "stale ledger blocker was missing"
[ "$(wp1 db query 'SELECT COUNT(*) FROM wp_wprism_kv' --skip-column-names | tr -d '[:space:]')" = "1" ] \
  || fail "ledger refusal mutated the pre-existing row set"
wp1 db query 'DROP TABLE IF EXISTS wp_wprism_journal,wp_wprism_kv,wp_wprism_map,wp_wprism_state' >/dev/null
pass "init never adopts or overwrites foreign canonical ownership or audit evidence"

say "orphan and malformed init transition slots refuse before repository writes"
printf '{}\n' >"$HOST_REPO/.wprism-init-attempt.next"
ORPHAN_NEXT_BEFORE=$(sha256sum "$HOST_REPO/.wprism-init-attempt.next" | awk '{print $1}')
assert_exit 1 "orphan init next-record blocks proposal before writes" "${WPRISM[@]}" init "${PAIR}1" --yes
[ "$ORPHAN_NEXT_BEFORE" = "$(sha256sum "$HOST_REPO/.wprism-init-attempt.next" | awk '{print $1}')" ] \
  || fail "orphan init next-record refusal rewrote the foreign slot"
[ ! -e "$HOST_REPO/.wprism-init-attempt" ] \
  && [ ! -e "$HOST_REPO/state.capture.lock" ] \
  && [ ! -e "$HOST_REPO/site.wprism.json" ] \
  && [ ! -d "$HOST_REPO/code" ] \
  && [ ! -d "$HOST_REPO/state" ] \
  || fail "orphan init next-record caused a repository write before refusal"
rm -f "$HOST_REPO/.wprism-init-attempt.next"
pass "orphan init next-record is preserved and pre-write refused"

say "first-lock acquisition refusal cannot strand an unjournaled lock"
assert_init_plan wp1 /siterepo "first-lock acquisition failure"
LOCK_FAILURE_DIGEST="$INIT_PLAN_DIGEST"
set +e
OUT=$("${COMPOSE[@]}" run --rm -T \
  -e WPRISM_TEST_MODE=1 \
  -e WPRISM_TEST_INIT_FAIL_PHASE=lock-acquire-after-create \
  cli1 wp wprism init --repo=/siterepo --confirm="$LOCK_FAILURE_DIGEST" --format=json 2>&1)
CODE=$?
set -e
[ "$CODE" -ne 0 ] || fail "injected first-lock acquisition failure unexpectedly initialized"
[ -z "$(find "$HOST_REPO" -mindepth 1 -maxdepth 1 -print -quit)" ] \
  || fail "first-lock acquisition failure stranded a lock, journal, or repository payload"
pass "failed first-lock acquisition compensates its exact new inode before journal cleanup"

say "Git initialization failure never loses its sealed recovery authority"
assert_init_plan wp1 /siterepo "unmanifested Git initialization"
PARTIAL_GIT_DIGEST="$INIT_PLAN_DIGEST"
set +e
OUT=$("${COMPOSE[@]}" run --rm -T \
  -e WPRISM_TEST_MODE=1 \
  -e WPRISM_TEST_INIT_FAIL_PHASE=git-initialized-before-identity \
  cli1 wp wprism init --repo=/siterepo --confirm="$PARTIAL_GIT_DIGEST" --format=json 2>&1)
CODE=$?
set -e
[ "$CODE" -ne 0 ] || fail "unmanifested Git initialization unexpectedly succeeded"
[ -d "$HOST_REPO/.git" ] \
  && [ -f "$HOST_REPO/.wprism-init-attempt" ] \
  && [ -f "$HOST_REPO/state.capture.lock" ] \
  || fail "Git initialization failure left an unjournaled or unlocked metadata root"
assert_exit 2 "unmanifested Git recovery is non-confirmable" "${WPRISM[@]}" init "${PAIR}1" --yes
grep -q 'interrupted_init_manual_recovery' <<<"$OUT" \
  || fail "Git recovery proposal advertised confirmation without deletion authority"
grep -q 'incomplete Git metadata without a complete ownership manifest' <<<"$OUT" \
  || fail "Git recovery refusal did not name its manual ownership boundary"
[ -d "$HOST_REPO/.git" ] && [ -f "$HOST_REPO/.wprism-init-attempt" ] \
  || fail "Git recovery refusal discarded its incomplete root or sealed journal"
repo_host 1
find "$HOST_REPO" -mindepth 1 -delete
pass "planned-to-mutated Git gaps retain a sealed journal until explicit cleanup"

say "unmanifested state reservation is preserved with its sealed journal"
assert_init_plan wp1 /siterepo "unmanifested state reservation"
UNBOUND_STATE_DIGEST="$INIT_PLAN_DIGEST"
set +e
OUT=$("${COMPOSE[@]}" run --rm -T \
  -e WPRISM_TEST_MODE=1 \
  -e WPRISM_TEST_INIT_FAIL_PHASE=state-reserved-before-identity \
  cli1 wp wprism init --repo=/siterepo --confirm="$UNBOUND_STATE_DIGEST" --format=json 2>&1)
CODE=$?
set -e
[ "$CODE" -ne 0 ] || fail "unmanifested state reservation unexpectedly initialized"
[ -d "$HOST_REPO/state" ] && [ -f "$HOST_REPO/.wprism-init-attempt" ] \
  || fail "state-reservation failure lost its unmanifested root or sealed journal"
repo_host 1
printf 'must survive recovery refusal\n' >"$HOST_REPO/state/manual-sentinel"
assert_exit 2 "unmanifested state recovery is non-confirmable" "${WPRISM[@]}" init "${PAIR}1" --yes
grep -q 'interrupted_init_manual_recovery' <<<"$OUT" \
  || fail "state recovery proposal advertised confirmation without deletion authority"
grep -q 'incomplete state reservation without a complete ownership manifest' <<<"$OUT" \
  || fail "state recovery refusal did not name its missing ownership manifest"
grep -q 'must survive recovery refusal' "$HOST_REPO/state/manual-sentinel" \
  || fail "state recovery refusal deleted the unmanifested sentinel"
repo_host 1
find "$HOST_REPO" -mindepth 1 -delete
pass "state planned-to-mutated gap cannot manufacture deletion authority"

say "code source change after the bound copy leaves no unjournaled stage"
assert_init_plan wp1 /siterepo "changed code source"
COPY_CHANGE_DIGEST="$INIT_PLAN_DIGEST"
set +e
OUT=$("${COMPOSE[@]}" run --rm -T \
  -e WPRISM_TEST_MODE=1 \
  -e WPRISM_TEST_INIT_FAIL_PHASE=code-copy-after-file \
  cli1 wp wprism init --repo=/siterepo --confirm="$COPY_CHANGE_DIGEST" --format=json 2>&1)
CODE=$?
set -e
printf '%s\n' "$OUT"
[ "$CODE" -ne 0 ] || fail "changed code source injection unexpectedly initialized the repository"
grep -q 'copy source digest changed' <<<"$OUT" \
  || fail "changed code source refusal did not identify its digest boundary"
[ -z "$(find "$HOST_REPO" -mindepth 1 -maxdepth 1 -print -quit)" ] \
  || fail "changed code source left a staging tree, journal, lock, or canonical payload"
[ "$(wp1 db query "SHOW TABLES LIKE 'wp_wprism_%'" --skip-column-names | wc -l | tr -d ' ')" = "0" ] \
  || fail "changed code source created ledger tables before capture"
pass "post-create code-copy refusal removes its owned partial file and complete stage"

say "prepublication SIGKILL recovery preserves a clean existing Git worktree"
git1 init --initial-branch=main >/dev/null
cp sandbox/site-repo.gitignore.template "$HOST_REPO/.gitignore"
git1 config user.name 'WPrism Init Regression'
git1 config user.email 'wprism-init@example.invalid'
git1 add .gitignore
git1 commit -m 'test: pre-existing init worktree' >/dev/null
for INIT_KILL_PHASE in lock-created attempt-transition-pre-rename; do
  assert_init_plan wp1 /siterepo "$INIT_KILL_PHASE SIGKILL"
  INIT_KILL_DIGEST="$INIT_PLAN_DIGEST"
  set +e
  OUT=$("${COMPOSE[@]}" run --rm -T \
    -e WPRISM_TEST_MODE=1 \
    -e WPRISM_TEST_INIT_KILL_PHASE="$INIT_KILL_PHASE" \
    cli1 wp wprism init --repo=/siterepo --confirm="$INIT_KILL_DIGEST" --format=json 2>&1)
  CODE=$?
  set -e
  printf '%s\n' "$OUT"
  [ "$CODE" -ne 0 ] || fail "$INIT_KILL_PHASE SIGKILL unexpectedly succeeded"
  [ -f "$HOST_REPO/.wprism-init-attempt" ] \
    || fail "$INIT_KILL_PHASE did not retain its sealed recovery journal"
  git1 check-ignore -q .wprism-init-attempt \
    || fail "$INIT_KILL_PHASE journal was visible to Git"
  if [[ "$INIT_KILL_PHASE" = attempt-transition-pre-rename* ]]; then
    [ -f "$HOST_REPO/.wprism-init-attempt.next" ] \
      || fail "journal transition kill did not retain its sealed next slot"
    git1 check-ignore -q .wprism-init-attempt.next \
      || fail "journal next slot was visible to Git"
  fi
  if [ "$INIT_KILL_PHASE" = 'lock-created' ]; then
    printf '{}\n' >"$HOST_REPO/.wprism-init-attempt.next"
    CANONICAL_ATTEMPT_BEFORE=$(sha256sum "$HOST_REPO/.wprism-init-attempt" | awk '{print $1}')
    assert_exit 1 "malformed next-record blocks recovery before cleanup" "${WPRISM[@]}" init "${PAIR}1" --yes
    [ "$CANONICAL_ATTEMPT_BEFORE" = "$(sha256sum "$HOST_REPO/.wprism-init-attempt" | awk '{print $1}')" ] \
      && [ -f "$HOST_REPO/state.capture.lock" ] \
      || fail "malformed next-record refusal changed canonical recovery evidence"
    rm -f "$HOST_REPO/.wprism-init-attempt.next"
  fi
  [ -z "$(git1 status --porcelain --untracked-files=all)" ] \
    || fail "$INIT_KILL_PHASE exposed recovery artifacts in Git status"
  assert_exit 1 "$INIT_KILL_PHASE fresh-process recovery" "${WPRISM[@]}" init "${PAIR}1" --yes
  grep -q 'safely rolled back' <<<"$OUT" \
    || fail "$INIT_KILL_PHASE recovery did not report its cleanup-only outcome"
  [ ! -e "$HOST_REPO/.wprism-init-attempt" ] \
    && [ ! -e "$HOST_REPO/.wprism-init-attempt.next" ] \
    && [ ! -e "$HOST_REPO/state.capture.lock" ] \
    || fail "$INIT_KILL_PHASE recovery retained an owned journal or lock"
  [ -z "$(git1 status --porcelain --untracked-files=all)" ] \
    || fail "$INIT_KILL_PHASE recovery changed the pre-existing Git worktree"
done
repo_host 1
rm -rf "$HOST_REPO/.git"
rm -f "$HOST_REPO/.gitignore"
pass "prepublication crash recovery is fresh-process, sealed, and Git-invisible"

say "partial code staging is retained without manufacturing deletion authority"
assert_init_plan wp1 /siterepo "partial code staging"
PARTIAL_CODE_DIGEST="$INIT_PLAN_DIGEST"
set +e
OUT=$("${COMPOSE[@]}" run --rm -T \
  -e WPRISM_TEST_MODE=1 \
  -e WPRISM_TEST_INIT_KILL_PHASE=attempt-transition-pre-rename-code-staging \
  cli1 wp wprism init --repo=/siterepo --confirm="$PARTIAL_CODE_DIGEST" --format=json 2>&1)
CODE=$?
set -e
[ "$CODE" -ne 0 ] || fail "code-staging journal-transition SIGKILL unexpectedly succeeded"
find "$HOST_REPO" -maxdepth 1 -type d -name '.wprism-init-code-*' -print -quit | grep -q . \
  || fail "code-staging crash did not retain its partial owned root"
assert_exit 2 "partial code-stage recovery is non-confirmable" "${WPRISM[@]}" init "${PAIR}1" --yes
grep -q 'interrupted_init_manual_recovery' <<<"$OUT" \
  || fail "partial code-stage proposal advertised confirmation without deletion authority"
grep -q 'partial code staging tree without a complete descriptor' <<<"$OUT" \
  || fail "partial code-stage recovery did not name its manual deletion boundary"
[ -f "$HOST_REPO/.wprism-init-attempt" ] && [ -f "$HOST_REPO/state.capture.lock" ] \
  || fail "partial code-stage recovery discarded its sealed journal or canonical lock"
repo_host 1
find "$HOST_REPO" -mindepth 1 -delete
pass "partial code-stage evidence is retained fail-closed for explicit manual cleanup"

say "partial initial state staging is retained without a completed manifest"
assert_init_plan wp1 /siterepo "partial initial-state staging"
PARTIAL_STATE_DIGEST="$INIT_PLAN_DIGEST"
set +e
OUT=$("${COMPOSE[@]}" run --rm -T \
  -e WPRISM_TEST_MODE=1 \
  -e WPRISM_TEST_PUBLISH_KILL_PHASE=initial-staging-partial \
  cli1 wp wprism init --repo=/siterepo --confirm="$PARTIAL_STATE_DIGEST" --format=json 2>&1)
CODE=$?
set -e
[ "$CODE" -ne 0 ] || fail "partial initial-state staging SIGKILL unexpectedly succeeded"
[ -d "$HOST_REPO/state.capture-staging" ] \
  || fail "initial-state staging crash did not retain its partial payload"
assert_exit 2 "partial state-stage recovery is non-confirmable" "${WPRISM[@]}" init "${PAIR}1" --yes
grep -q 'interrupted_init_manual_recovery' <<<"$OUT" \
  || fail "partial state-stage proposal advertised confirmation without deletion authority"
grep -q 'partial state staging tree without a complete deletion manifest' <<<"$OUT" \
  || fail "partial state-stage recovery did not name its manual deletion boundary"
[ -d "$HOST_REPO/state.capture-staging" ] \
  && [ -f "$HOST_REPO/.wprism-init-attempt" ] \
  && [ -f "$HOST_REPO/state.capture.lock" ] \
  || fail "partial state-stage recovery discarded unproven payload or recovery authority"
repo_host 1
find "$HOST_REPO" -mindepth 1 -delete
wp1 db query 'DROP TABLE IF EXISTS wp_wprism_journal,wp_wprism_kv,wp_wprism_map,wp_wprism_state' >/dev/null
pass "partial state payload is retained fail-closed until explicit manual cleanup"

say "record-temp SIGKILL is a non-confirmable recovery shape"
assert_init_plan wp1 /siterepo "record-temp SIGKILL"
TEMP_DIGEST="$INIT_PLAN_DIGEST"
set +e
OUT=$("${COMPOSE[@]}" run --rm -T \
  -e WPRISM_TEST_MODE=1 \
  -e WPRISM_TEST_PUBLISH_KILL_PHASE=record-create-temp \
  cli1 wp wprism init --repo=/siterepo --confirm="$TEMP_DIGEST" --format=json 2>&1)
CODE=$?
set -e
[ "$CODE" -ne 0 ] || fail "record-temp SIGKILL unexpectedly succeeded"
TEMP_PATH=$(find "$REPO_ROOT/sandbox/siterepo/${PAIR}1" -maxdepth 1 -type f \
  -name 'state.capture-intent.tmp.*' -print -quit)
[ -n "$TEMP_PATH" ] && [ -f "$HOST_REPO/.wprism-init-attempt" ] \
  || fail "record-temp SIGKILL did not retain its temp and sealed journal"
TEMP_HASH=$(sha256sum "$TEMP_PATH" | awk '{print $1}')
assert_exit 2 "record-temp recovery is non-confirmable" "${WPRISM[@]}" init "${PAIR}1" --yes
grep -q 'interrupted_init_manual_recovery' <<<"$OUT" \
  || fail "record-temp recovery proposal advertised confirmation"
[ "$TEMP_HASH" = "$(sha256sum "$TEMP_PATH" | awk '{print $1}')" ] \
  || fail "record-temp refusal changed the unbound temp artifact"
repo_host 1
find "$HOST_REPO" -mindepth 1 -delete
wp1 db query 'DROP TABLE IF EXISTS wp_wprism_journal,wp_wprism_kv,wp_wprism_map,wp_wprism_state' >/dev/null
pass "record-temp recovery retains the exact unbound artifact and journal"

say "Init-owned temp SIGKILL is a non-confirmable recovery shape"
assert_init_plan wp1 /siterepo "Init-owned temp SIGKILL"
INIT_TEMP_DIGEST="$INIT_PLAN_DIGEST"
set +e
OUT=$("${COMPOSE[@]}" run --rm -T \
  -e WPRISM_TEST_MODE=1 \
  -e WPRISM_TEST_INIT_KILL_PHASE=owned-file-temp \
  cli1 wp wprism init --repo=/siterepo --confirm="$INIT_TEMP_DIGEST" --format=json 2>&1)
CODE=$?
set -e
[ "$CODE" -ne 0 ] || fail "Init-owned temp SIGKILL unexpectedly succeeded"
INIT_TEMP_PATH=$(find "$REPO_ROOT/sandbox/siterepo/${PAIR}1" -maxdepth 1 -type f \
  -name '.*.wprism-init-*' -print -quit)
[ -n "$INIT_TEMP_PATH" ] && [ -f "$HOST_REPO/.wprism-init-attempt" ] \
  || fail "Init-owned temp SIGKILL did not retain its temp and sealed journal"
INIT_TEMP_HASH=$(sha256sum "$INIT_TEMP_PATH" | awk '{print $1}')
assert_exit 2 "Init-owned temp recovery is non-confirmable" "${WPRISM[@]}" init "${PAIR}1" --yes
grep -q 'interrupted_init_manual_recovery' <<<"$OUT" \
  || fail "Init-owned temp recovery proposal advertised confirmation"
[ "$INIT_TEMP_HASH" = "$(sha256sum "$INIT_TEMP_PATH" | awk '{print $1}')" ] \
  || fail "Init-owned temp refusal changed the unbound temp artifact"
repo_host 1
find "$HOST_REPO" -mindepth 1 -delete
wp1 db query 'DROP TABLE IF EXISTS wp_wprism_journal,wp_wprism_kv,wp_wprism_map,wp_wprism_state' >/dev/null
pass "Init-owned temp recovery retains the exact unbound artifact and journal"

say "capture SIGKILL recovery rolls back repo, media, identity, and ledger rows"
wp1 eval '
$upload = wp_upload_dir();
wp_mkdir_p($upload["path"]);
$path = $upload["path"] . "/wprism-init-atomic.txt";
file_put_contents($path, "wprism init atomic media\n");
$id = wp_insert_attachment([
    "post_title" => "WPrism Init Atomic Media", "post_status" => "inherit",
    "post_mime_type" => "text/plain",
], $path);
update_attached_file($id, $path);
' >/dev/null

assert_init_plan wp1 /siterepo "post-next-link publication failure"
FAIL_NEXT_DIGEST="$INIT_PLAN_DIGEST"
set +e
OUT=$("${COMPOSE[@]}" run --rm -T \
  -e WPRISM_TEST_MODE=1 \
  -e WPRISM_TEST_PUBLISH_FAIL_PHASE=record-create-next \
  cli1 wp wprism init --repo=/siterepo --confirm="$FAIL_NEXT_DIGEST" --format=json 2>&1)
CODE=$?
set -e
[ "$CODE" -ne 0 ] || fail "post-next-link publication failure unexpectedly succeeded"
[ -f "$HOST_REPO/state.capture-intent.next" ] \
  && [ -d "$HOST_REPO/state.capture-staging" ] \
  && [ -f "$HOST_REPO/.wprism-init-attempt" ] \
  || fail "post-next-link failure discarded its sealed transition or manifest-bound payload"
assert_exit 1 "post-next-link normal-error recovery" "${WPRISM[@]}" init "${PAIR}1" --yes
grep -q 'safely rolled back' <<<"$OUT" \
  || fail "post-next-link failure did not recover through the sealed initial manifest path"
# Self-diagnosing, like the swap cases: the EXIT cleanup removes $HOST_REPO, so
# "did not restore byte-empty" on its own leaves the next reader nothing to act
# on -- which cost an evidence run to learn.
POST_NEXT_LEFTOVER=$(find "$HOST_REPO" -mindepth 1 -maxdepth 1 2>/dev/null | head -20)
[ -z "$POST_NEXT_LEFTOVER" ] \
  || fail "post-next-link normal-error recovery did not restore byte-empty repository ownership:
$POST_NEXT_LEFTOVER"
wp1 db query 'DROP TABLE IF EXISTS wp_wprism_journal,wp_wprism_kv,wp_wprism_map,wp_wprism_state' >/dev/null
pass "normal failure after the fresh intent next-link recovers without legacy cleanup"

assert_init_plan wp1 /siterepo "post-swap unmanifested directory"
POST_SWAP_DIGEST="$INIT_PLAN_DIGEST"
set +e
OUT=$("${COMPOSE[@]}" run --rm -T \
  -e WPRISM_TEST_MODE=1 \
  -e WPRISM_TEST_INIT_FAIL_PHASE=post-swap-unmanifested-empty \
  cli1 wp wprism init --repo=/siterepo --confirm="$POST_SWAP_DIGEST" --format=json 2>&1)
CODE=$?
set -e
[ "$CODE" -ne 0 ] || fail "post-swap unmanifested directory was accepted as a successful baseline"
[ -d "$HOST_REPO/state/unmanifested-empty-directory" ] \
  && [ -f "$HOST_REPO/.wprism-init-attempt" ] \
  && [ -f "$HOST_REPO/state.capture-intent" ] \
  || fail "post-swap manifest refusal did not retain its complete recovery evidence"
assert_exit 2 "post-swap exact-manifest recovery refusal" "${WPRISM[@]}" init "${PAIR}1" --yes
[ -f "$HOST_REPO/.wprism-init-attempt" ] \
  || fail "post-swap exact-manifest proposal did not retain its sealed journal"
[ -d "$HOST_REPO/state/unmanifested-empty-directory" ] \
  && [ -f "$HOST_REPO/.wprism-init-attempt" ] \
  || fail "post-swap recovery deleted the unmanifested directory or cleared its journal"
repo_host 1
find "$HOST_REPO" -mindepth 1 -delete
wp1 db query 'DROP TABLE IF EXISTS wp_wprism_journal,wp_wprism_kv,wp_wprism_map,wp_wprism_state' >/dev/null
pass "live initial success requires the exact candidate manifest through receipt cleanup"

say "strict initial recovery preserves stable payload additions absent from sealed manifests"
for STRICT_PHASE in record-create-next after-backup-rename after-state-rename; do
  assert_init_plan wp1 /siterepo "$STRICT_PHASE strict recovery"
  STRICT_DIGEST="$INIT_PLAN_DIGEST"
  set +e
  OUT=$("${COMPOSE[@]}" run --rm -T \
    -e WPRISM_TEST_MODE=1 \
    -e WPRISM_TEST_PUBLISH_KILL_PHASE="$STRICT_PHASE" \
    cli1 wp wprism init --repo=/siterepo --confirm="$STRICT_DIGEST" --format=json 2>&1)
  CODE=$?
  set -e
  [ "$CODE" -ne 0 ] || fail "$STRICT_PHASE strict-recovery fixture unexpectedly succeeded"
  case "$STRICT_PHASE" in
    record-create-next) STRICT_ROOT="$HOST_REPO/state.capture-staging" ;;
    after-backup-rename) STRICT_ROOT="$HOST_REPO/state.capture-backup" ;;
    after-state-rename) STRICT_ROOT="$HOST_REPO/state" ;;
  esac
  repo_host 1
  mkdir "$STRICT_ROOT/unmanifested-empty-directory"
  assert_exit 2 "$STRICT_PHASE exact-manifest recovery refusal" "${WPRISM[@]}" init "${PAIR}1" --yes
  grep -q 'interrupted_init_manual_recovery' <<<"$(wp1 wprism init --repo=/siterepo --format=json)" \
    || fail "$STRICT_PHASE proposal did not expose manual recovery"
  [ -d "$STRICT_ROOT/unmanifested-empty-directory" ] \
    && [ -f "$HOST_REPO/.wprism-init-attempt" ] \
    || fail "$STRICT_PHASE recovery deleted an unmanifested directory or its sealed journal"
  repo_host 1
  find "$HOST_REPO" -mindepth 1 -delete
  wp1 db query 'DROP TABLE IF EXISTS wp_wprism_journal,wp_wprism_kv,wp_wprism_map,wp_wprism_state' >/dev/null
done
pass "staging, retained reservation, and published candidate cleanup all require exact manifests"

say "strict recovery proposal refuses a partially removed manifest-bound tree"
assert_init_plan wp1 /siterepo "partial manifest-bound tree"
PARTIAL_DIGEST="$INIT_PLAN_DIGEST"
set +e
OUT=$("${COMPOSE[@]}" run --rm -T \
  -e WPRISM_TEST_MODE=1 \
  -e WPRISM_TEST_PUBLISH_KILL_PHASE=after-state-rename \
  cli1 wp wprism init --repo=/siterepo --confirm="$PARTIAL_DIGEST" --format=json 2>&1)
CODE=$?
set -e
[ "$CODE" -ne 0 ] || fail "partial-tree recovery fixture unexpectedly succeeded"
repo_host 1
PARTIAL_FILE=$(find "$HOST_REPO/state" -mindepth 1 -type f -print -quit)
if [ -n "$PARTIAL_FILE" ]; then
  rm -f "$PARTIAL_FILE"
else
  mkdir "$HOST_REPO/state/partial-removal"
fi
assert_exit 2 "partial manifest-bound tree is non-confirmable" "${WPRISM[@]}" init "${PAIR}1" --yes
grep -q 'interrupted_init_manual_recovery' <<<"$(wp1 wprism init --repo=/siterepo --format=json)" \
  || fail "partial manifest-bound tree proposal did not expose manual recovery"
[ -f "$HOST_REPO/.wprism-init-attempt" ] \
  || fail "partial manifest-bound tree refusal cleared the sealed journal"
[ -d "$HOST_REPO/state" ] \
  || fail "partial manifest-bound tree refusal removed the retained state root"
repo_host 1
find "$HOST_REPO" -mindepth 1 -delete
wp1 db query 'DROP TABLE IF EXISTS wp_wprism_journal,wp_wprism_kv,wp_wprism_map,wp_wprism_state' >/dev/null
pass "strict recovery proposal refuses partial manifest-bound trees before mutation"

for PUBLISH_KILL_PHASE in record-create-next intent-written after-state-rename; do
  assert_init_plan wp1 /siterepo "$PUBLISH_KILL_PHASE SIGKILL"
  KILL_DIGEST="$INIT_PLAN_DIGEST"
  set +e
  OUT=$("${COMPOSE[@]}" run --rm -T \
    -e WPRISM_TEST_MODE=1 \
    -e WPRISM_TEST_PUBLISH_KILL_PHASE="$PUBLISH_KILL_PHASE" \
    cli1 wp wprism init --repo=/siterepo --confirm="$KILL_DIGEST" --format=json 2>&1)
  CODE=$?
  set -e
  printf '%s\n' "$OUT"
  [ "$CODE" -ne 0 ] || fail "$PUBLISH_KILL_PHASE SIGKILL unexpectedly succeeded"
  [ -f "$HOST_REPO/.wprism-init-attempt" ] \
    || fail "$PUBLISH_KILL_PHASE did not retain its sealed init journal"
  assert_exit 1 "$PUBLISH_KILL_PHASE fresh-process recovery" "${WPRISM[@]}" init "${PAIR}1" --yes
  grep -q 'safely rolled back' <<<"$OUT" \
    || fail "$PUBLISH_KILL_PHASE recovery did not report deterministic pre-COMMIT rollback"
  [ -z "$(find "$HOST_REPO" -mindepth 1 -maxdepth 1 -print -quit)" ] \
    || fail "$PUBLISH_KILL_PHASE recovery did not restore byte-empty repository ownership"
  ROW_TOTAL=$(wp1 db query '
  SELECT
    (SELECT COUNT(*) FROM wp_wprism_journal) +
    (SELECT COUNT(*) FROM wp_wprism_kv) +
    (SELECT COUNT(*) FROM wp_wprism_map) +
    (SELECT COUNT(*) FROM wp_wprism_state)
  ' --skip-column-names | tr -d '[:space:]')
  [ "$ROW_TOTAL" = "0" ] || fail "$PUBLISH_KILL_PHASE recovery left WPrism ledger rows"
  [ "$(wp1 db query "SELECT COUNT(*) FROM wp_postmeta WHERE meta_key = '_wprism_uuid'" --skip-column-names | tr -d '[:space:]')" = "0" ] \
    || fail "$PUBLISH_KILL_PHASE recovery left minted identities"
  wp1 db query 'DROP TABLE IF EXISTS wp_wprism_journal,wp_wprism_kv,wp_wprism_map,wp_wprism_state' >/dev/null
done
pass "intent and post-rename SIGKILL recovery leave no ghost initial baseline"

say "operator cancellation leaves the reviewed proposal completely uncommitted"
set +e
OUT=$(printf 'n\n' | "${WPRISM[@]}" init "${PAIR}1" 2>&1)
CODE=$?
set -e
printf '%s\n' "$OUT"
[ "$CODE" -eq 1 ] || fail "cancelled init expected exit 1, got $CODE"
grep -q 'Initialization cancelled' <<<"$OUT" || fail "cancelled init did not say it was cancelled"
[ ! -e "$HOST_REPO/site.wprism.json" ] && [ ! -d "$HOST_REPO/state" ] \
  || fail "cancelled proposal mutated the repository"
[ "$(wp1 db query "SHOW TABLES LIKE 'wp_wprism_%'" --skip-column-names | wc -l | tr -d ' ')" = "0" ] \
  || fail "cancelled proposal created ledger tables"
pass "confirmation boundary is real"

say "two concurrent confirmations produce exactly one complete winner"
assert_init_plan wp2 /siterepo "concurrent confirmation"
CONCURRENT_DIGEST="$INIT_PLAN_DIGEST"
# issue #3428: measured and printed, never asserted. This IS a complete init, but
# it is a contention case -- two contenders, one deliberate 5000 ms publication
# pause, and the winner is whichever one the lease picks -- so its wall is not
# the golden-path span `completed_within_fifteen_minutes` certifies. It is
# recorded because it is the suite's only other full initialization and the
# comparison is worth having (issue #3425).
CONCURRENT_STARTED_AT=$SECONDS
set +e
"${COMPOSE[@]}" run --rm -T \
  -e WPRISM_TEST_MODE=1 -e WPRISM_TEST_INIT_PUBLICATION_PAUSE_MS=5000 \
  cli2 wp wprism init --repo=/siterepo --confirm="$CONCURRENT_DIGEST" --format=json \
  >"/tmp/${PAIR}-init-concurrent-1.log" 2>&1 &
PID1=$!
"${COMPOSE[@]}" run --rm -T \
  -e WPRISM_TEST_MODE=1 -e WPRISM_TEST_INIT_PUBLICATION_PAUSE_MS=5000 \
  cli2 wp wprism init --repo=/siterepo --confirm="$CONCURRENT_DIGEST" --format=json \
  >"/tmp/${PAIR}-init-concurrent-2.log" 2>&1 &
PID2=$!
for _ in $(seq 1 100); do
  [ -f "$HOST_REPO2/site.wprism.json" ] && break
  sleep 0.1
done
[ -f "$HOST_REPO2/site.wprism.json" ] || fail "concurrent winner never reached publication-lock phase"
# issue #3427: this is the MACHINE surface, so it is asserted on the machine
# contract. `another capture is already publishing` is the OPERATOR-message
# wording, which --format=json deliberately does not carry — the reviewed
# public fields are the reason code and its own sentence — so grepping the
# human phrase here asked the JSON envelope for something it never promised.
# The operator wording keeps its two proper homes: regress_capture_concurrency
# asserts it on stderr, and regress_capture_publish asserts it on the thrown
# message. Compose stderr is dropped for the same reason issue #3421 stopped
# preferring it: `docker compose run` writes progress there on every call, so
# merging it here would leave nothing parseable.
set +e
CAPTURE_OUT=$(wp2 wprism capture --repo=/siterepo --format=json 2>/dev/null)
CAPTURE_CODE=$?
set -e
[ "$CAPTURE_CODE" -ne 0 ] || fail "ordinary capture entered while init held its publication lock"
jq -e '.format == "wprism-command-refusal/v1" and .reason_code == "capture_lock_held"' \
  <<<"$CAPTURE_OUT" >/dev/null 2>&1 \
  || fail "ordinary capture refusal did not name the held publication lock: $CAPTURE_OUT"
grep -q 'another publisher holds the destination lock' <<<"$CAPTURE_OUT" \
  || fail "ordinary capture refusal omitted its reviewed public sentence: $CAPTURE_OUT"
set +e
wait "$PID1"; CODE1=$?
wait "$PID2"; CODE2=$?
set -e
if { [ "$CODE1" -eq 0 ] && [ "$CODE2" -eq 0 ]; } \
  || { [ "$CODE1" -ne 0 ] && [ "$CODE2" -ne 0 ]; }; then
  printf '%s\n' "first=$CODE1 second=$CODE2"
  sed -n '1,120p' "/tmp/${PAIR}-init-concurrent-1.log"
  sed -n '1,120p' "/tmp/${PAIR}-init-concurrent-2.log"
  fail "concurrent init expected exactly one successful confirmation"
fi
CONCURRENT_ELAPSED=$((SECONDS - CONCURRENT_STARTED_AT))
printf 'informational: concurrent-confirmation group (winner + loser + injected 5000ms pause) took %ss; not a certified per-init measurement\n' \
  "$CONCURRENT_ELAPSED"
[ -f "$HOST_REPO2/site.wprism.json" ] && [ -d "$HOST_REPO2/state" ] \
  && [ -d "$HOST_REPO2/code/wp-content" ] && [ -d "$HOST_REPO2/.git" ] \
  || fail "concurrent winner did not leave one complete baseline tuple"
[ "$(wp2 db query "SELECT COUNT(*) FROM wp_wprism_kv WHERE k IN ('code_revision','code_descriptor')" --skip-column-names | tr -d '[:space:]')" = "2" ] \
  || fail "concurrent winner did not publish exactly one completed lifecycle pair"
assert_exit 0 "concurrent winner status" "${WPRISM[@]}" status "${PAIR}2"
pass "init advisory lease prevents loser cleanup from touching the winner"

say "the host holds the release archives the golden path will lock against"
# Git never carries third-party code, so there is no `--code=full` any more and
# the golden path CLASSIFIES: every active component either locks against a
# release the host can verify or is declared first-party, and anything else
# blocks the proposal. Classification runs on THIS host and never on the
# target -- a target that reached a package registry would falsify
# code_release_provider's shipped probe attestation. The mirror below is the
# same pinned WooCommerce 11.0.0 archive `fetch_artifact` already
# digest-verified into the pair's artifact cache, republished where the host
# can read it, so this leg is hermetic and runs unchanged under
# WPRISM_WORDPRESS_ORG_OFFLINE=1. WPRISM_CODE_ARTIFACT_BASE moves only WHERE bytes are
# fetched from: the url the lock records is still the canonical
# downloads.wordpress.org one, because a wp-org-release's identity is its
# canonical url plus its archive digest, never the host that served it. The
# archive_sha256 assertion below is what proves that -- it must equal
# the WooCommerce capsule's own artifact pin for this release.
rm -rf "$CODE_MIRROR"
mkdir -p "$CODE_MIRROR/plugin" "$CODE_MIRROR/cache" "$CODE_MIRROR/cache-empty"
"${COMPOSE[@]}" run --rm -T -u root --entrypoint cat cli1 "$WOO_ARTIFACT" \
  >"$CODE_MIRROR/plugin/woocommerce.11.0.0.zip"
WOO_PINNED_SHA256=$(artifact_library_jq -r '.plugins.woocommerce."11.0.0".sha256')
[[ "$WOO_PINNED_SHA256" =~ ^[0-9a-f]{64}$ ]] \
  || fail "the artifact lock has no usable WooCommerce 11.0.0 digest pin"
[ "$(sha256sum "$CODE_MIRROR/plugin/woocommerce.11.0.0.zip" | awk '{print $1}')" = "$WOO_PINNED_SHA256" ] \
  || fail "the host release mirror is not the pinned WooCommerce 11.0.0 archive"
# The active theme ships inside the WordPress image, not from a release the
# mirror holds, which makes it exactly the component class the no-third-party-
# bytes invariant is about: a component with no wp.org release on this host.
# The operator's path for that is `wprism code-import`: take the archive they
# hold -- here, the theme's own installed bytes, packed on the host -- import it
# into the host's code-artifact cache, and the classifier locks against its
# digest. The repository records the digests only; it never carries the zip.
"${COMPOSE[@]}" run --rm -T -u root --entrypoint tar cli1 \
  -C /var/www/html/wp-content/themes -cf - twentytwentyone >"$CODE_MIRROR/twentytwentyone.tar"
php -r '
$dir = $argv[1];
(new PharData($dir . "/twentytwentyone.tar"))->extractTo($dir . "/theme-src", null, true);
$zip = new ZipArchive();
$zip->open($dir . "/twentytwentyone.zip", ZipArchive::CREATE | ZipArchive::OVERWRITE);
$root = $dir . "/theme-src/twentytwentyone";
$zip->addEmptyDir("twentytwentyone");
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $item) {
    $relative = "twentytwentyone/" . substr($item->getPathname(), strlen($root) + 1);
    $item->isDir() ? $zip->addEmptyDir($relative) : $zip->addFile($item->getPathname(), $relative);
}
$zip->close();
' "$CODE_MIRROR"
THEME_ARCHIVE_SHA256=$(sha256sum "$CODE_MIRROR/twentytwentyone.zip" | awk '{print $1}')
assert_exit 0 "wprism code-import of the active theme archive" \
  "${WPRISM[@]}" code-import "$CODE_MIRROR/twentytwentyone.zip" --root=themes --cache-dir="$CODE_MIRROR/cache"
grep -q 'imported themes/twentytwentyone' <<<"$OUT" || fail "code-import did not name the imported theme"
grep -q "archive_sha256: $THEME_ARCHIVE_SHA256" <<<"$OUT" || fail "code-import did not print the archive digest the lock will record"
[ -f "$CODE_MIRROR/cache/imported/$THEME_ARCHIVE_SHA256.zip" ] \
  || fail "code-import did not store the archive under its digest in the host's code-artifact cache"
pass "the host holds a pinned wp.org release and an imported archive, and reached no network for either"

say "confirm the content-addressed proposal through the public host CLI"
# issue #3428: the certified per-init clock. One public host-CLI invocation that
# proposes and confirms, which is the whole operator-visible span the
# fifteen-minute claim is about. The classification round trip is part of
# that span now -- there is no unclassified shape an init could take -- and it
# resolves against the local mirror and the local import, so what the clock
# measures is still the operator's wait for a complete baseline, not a
# network.
export WPRISM_CODE_ARTIFACT_BASE="file://$CODE_MIRROR"
time_golden_init 0 "wprism init Woo golden path" "${WPRISM[@]}" init "${PAIR}1" --yes --cache-dir="$CODE_MIRROR/cache"
unset WPRISM_CODE_ARTIFACT_BASE
grep -q 'code: managed-baseline-proposed' <<<"$OUT" || fail "init did not propose a separate code baseline"
grep -q 'code classification: 2 locked, 0 first-party, 0 unsourced' <<<"$OUT" \
  || fail "the golden path did not classify both components as locked; the host's own classification said:
$(grep -E '^ +(LOCKED|FIRST-PARTY|UNSOURCED) ' <<<"$OUT" || true)"
grep -q 'LOCKED plugins/woocommerce 11.0.0 ' <<<"$OUT" || fail "the pinned WooCommerce release did not lock"
grep -q 'LOCKED themes/twentytwentyone ' <<<"$OUT" || fail "the imported theme archive did not lock"
grep -q 'locks third-party components' <<<"$OUT" \
  || fail "the next-steps did not name the materialization a fresh clone needs"
grep -q 'active plugin: woocommerce/woocommerce.php 11.0.0' <<<"$OUT" || fail "init did not inventory the active plugin version"
grep -q 'Initialized canonical state baseline' <<<"$OUT" || fail "init did not name the state baseline"
grep -q 'Initialized separate code baseline' <<<"$OUT" || fail "init did not name the independent code baseline"
grep -q 'not a code-and-database rollback checkpoint' <<<"$OUT" || fail "init overstated rollback readiness"
grep -q 'Managed state scope is clean' <<<"$OUT" || fail "init did not state the bounded clean result"
grep -q 'Coverage outside the selected adapters remains advisory' <<<"$OUT" || fail "init claimed whole-site completeness"
grep -q 'active_theme_code_only' <<<"$OUT" || fail "init hid the active theme code-only state advisory"
grep -q 'repository_external_writer_exclusion' <<<"$OUT" || fail "init hid the non-WPrism repository-writer exclusion advisory"
# The guide deliberately requires the installed, machine-local CLI path.  A
# bare `wprism capture` spelling would advertise an executable the previous step
# never configured, and it diverges from Init::nextSteps()'s public contract.
# Keep this public-path assertion on the exact command form so a future prose
# edit cannot reintroduce a green unit test alongside a failing golden path.
for needle in branch '"$WPRISM_CLI" capture' '"$WPRISM_CLI" plan' '"$WPRISM_CLI" promote' rollback; do
  grep -q "$needle" <<<"$OUT" || fail "workflow guide omitted $needle"
done

# The split declaration is the only declaration: every classified site is
# format 2 naming the lock, and the lock is the sourcing record for both
# components -- a wp.org release under its pinned digest, the theme under the
# imported archive's digest, and nothing first-party.
jq -e '
  ([.manifests[].name] | sort) == ["core", "woocommerce"] and
  ([.manifests[] | select((.digest | type) != "string" or (.digest | length) != 64)] | length) == 0 and
  .code == {"format":2,"layout":"wp-content","lock":"code/wprism-code.lock.json","source":"code/wp-content"} and
  (.policy.post_types | index("product")) != null and
  (.policy.post_types | index("product_variation")) != null and
  (.policy.post_types | index("shop_coupon")) != null and
  (.policy.post_types | index("shop_order")) == null
' "$HOST_REPO/site.wprism.json" >/dev/null || fail "generated site.wprism.json violates adapter pins or authored/runtime scope"
jq -e --arg woo "$WOO_PINNED_SHA256" --arg theme "$THEME_ARCHIVE_SHA256" '
  .format == "wprism-code-lock/v2" and
  .first_party == [] and
  ([.components[] | select(.root == "plugins" and .component == "woocommerce")] | length) == 1 and
  ([.components[] | select(.root == "plugins" and .component == "woocommerce")][0]
    | .version == "11.0.0"
      and .origin.kind == "wp-org-release"
      and .origin.url == "https://downloads.wordpress.org/plugin/woocommerce.11.0.0.zip"
      and .origin.archive_sha256 == $woo
      and (.tree_sha256 | test("^[0-9a-f]{64}$"))) and
  ([.components[] | select(.root == "themes" and .component == "twentytwentyone")] | length) == 1 and
  ([.components[] | select(.root == "themes" and .component == "twentytwentyone")][0]
    | .origin == {"kind":"imported-archive","archive_sha256":$theme}
      and (.tree_sha256 | test("^[0-9a-f]{64}$")))
' "$HOST_REPO/code/wprism-code.lock.json" >/dev/null \
  || fail "the published code lock did not declare the pinned WooCommerce release and the imported theme:
$(cat "$HOST_REPO/code/wprism-code.lock.json" 2>&1)"
find "$HOST_REPO/state/posts/product" -type f -name '*.md' -print -quit | grep -q . \
  || fail "authored Woo product was not captured"
find "$HOST_REPO/state/terms/pa_wprisminit" -type f -name '*.json' -print -quit | grep -q . \
  || fail "manifest taxonomy_patterns did not expand the authored attribute taxonomy"
[ ! -d "$HOST_REPO/state/posts/shop_order" ] || fail "runtime Woo orders entered canonical state"
[ -f "$HOST_REPO/code/wp-content/plugins/woocommerce/woocommerce.php" ] \
  || fail "active WooCommerce code was not captured into the separate payload"
[ -f "$HOST_REPO/code/wp-content/themes/twentytwentyone/style.css" ] \
  || fail "active theme code was not captured into the separate payload"
[ ! -e "$HOST_REPO/code/wp-content/mu-plugins/wprism-loader.php" ] \
  || fail "WPrism's control-plane loader leaked into the managed code payload"
[ "$(wp1 db query "SHOW TABLES LIKE 'wp_wprism_%'" --skip-column-names | wc -l | tr -d ' ')" -ge 3 ] \
  || fail "confirmed capture did not establish the environment ledger"
# The number the first-party leg below has to reproduce exactly. Classification
# moves no byte under code/wp-content, so a site whose theme is locked and the
# same site whose theme is declared first-party compile to the identical
# code_revision -- the declaration is about Git, never about the payload.
GOLDEN_CODE_REVISION=$(wp1 db query "SELECT v FROM wp_wprism_kv WHERE k = 'code_revision'" \
  --skip-column-names | tr -d '[:space:]')
[[ "$GOLDEN_CODE_REVISION" =~ ^[0-9a-f]{64}$ ]] \
  || fail "the golden init published no completed code revision"
pass "state/media identity exists independently from executable code"

say "the advertised Git baseline, commit, and branch path is executable"
git1 rev-parse --show-toplevel | grep -qx /siterepo \
  || fail "confirmed init did not create a target-owned Git worktree"
git1 config user.name 'WPrism Init Regression'
git1 config user.email 'wprism-init@example.invalid'
git1 add .gitignore site.wprism.json code state media
git1 commit -m 'wprism: initial code and state baselines' >/dev/null
git1 switch -c wprism-init-regression >/dev/null
[ "$(git1 branch --show-current | tr -d '\r')" = "wprism-init-regression" ] \
  || fail "Git-ready baseline could not create the first branch"
[ -z "$(git1 status --porcelain)" ] || fail "initial Git baseline left unstaged canonical files"
# Every locked component, whichever ones this run locked: present on disk,
# ignored by a root-anchored line, and carried by nobody in Git. Driven from
# the published lock rather than a hard-coded slug list.
while read -r LOCKED_COMPONENT; do
  [ -n "$LOCKED_COMPONENT" ] || continue
  [ -d "$HOST_REPO/code/wp-content/$LOCKED_COMPONENT" ] \
    || fail "locked component $LOCKED_COMPONENT is not on disk; the lock declares bytes, it never deletes them"
  grep -qx "/code/wp-content/$LOCKED_COMPONENT/" "$HOST_REPO/.gitignore" \
    || fail "locked component $LOCKED_COMPONENT has no root-anchored ignore line in the repository-root .gitignore"
  LOCKED_TRACKED=$(git1 ls-files -- "code/wp-content/$LOCKED_COMPONENT" | head -3)
  [ -z "$LOCKED_TRACKED" ] \
    || fail "Git tracked locked component $LOCKED_COMPONENT: $LOCKED_TRACKED"
done < <(jq -r '.components[] | "\(.root)/\(.component)"' "$HOST_REPO/code/wprism-code.lock.json")
[ -z "$(git1 ls-files -- code/wp-content/plugins code/wp-content/themes)" ] \
  || fail "Git carries third-party component bytes: $(git1 ls-files -- code/wp-content/plugins code/wp-content/themes | head -3)"
pass "first commit and branch work without hand-authored repository setup, and Git carries no third-party code"

say "ordinary public status remains the truth source for the managed scope"
assert_exit 0 "wprism status after init" "${WPRISM[@]}" status "${PAIR}1"
grep -q '0 conflict' <<<"$OUT" || fail "clean status did not report zero conflicts"
grep -q '0 drift' <<<"$OUT" || fail "clean status did not report zero drift"

say "an unsourced component blocks init, and a first-party declaration is the other way out"
# The invariant, live: with a host cache that holds the WooCommerce mirror but
# NOT the imported theme, the theme has no wp.org release and no imported
# archive, so it is UNSOURCED -- and the proposal is BLOCKED on it, naming both
# remedies. There is no `--code=full` to vendor it anyway; the flag is refused
# by name. Declaring the theme the site's own code (`--first-party`) is the
# other way out, and the declaration changes what Git carries and nothing
# else: the payload compiles to the identical code_revision the golden path
# produced.
repo_host 1
find "$HOST_REPO" -mindepth 1 -delete
wp1 db query 'DROP TABLE IF EXISTS wp_wprism_journal,wp_wprism_kv,wp_wprism_map,wp_wprism_state' >/dev/null
assert_exit 1 "--code=full is refused by name" "${WPRISM[@]}" init "${PAIR}1" --yes --code=full
grep -q 'init no longer takes --code' <<<"$OUT" || fail "the removed --code flag was not refused with its reason"
[ ! -e "$HOST_REPO/site.wprism.json" ] || fail "the refused --code flag mutated the repository"

export WPRISM_CODE_ARTIFACT_BASE="file://$CODE_MIRROR"
assert_exit 2 "unsourced theme blocks init" \
  "${WPRISM[@]}" init "${PAIR}1" --yes --cache-dir="$CODE_MIRROR/cache-empty"
UNSOURCED_OUT="$OUT"
UNSOURCED_VIEW=$(grep -E '^ +(LOCKED|FIRST-PARTY|UNSOURCED) ' <<<"$UNSOURCED_OUT" || true)
grep -q 'code classification: 1 locked, 0 first-party, 1 unsourced' <<<"$UNSOURCED_OUT" \
  || fail "the blocked proposal did not render the classification; the host's own classification said:
$UNSOURCED_VIEW"
grep -q 'LOCKED plugins/woocommerce 11.0.0 ' <<<"$UNSOURCED_VIEW" \
  || fail "the pinned WooCommerce release did not lock from the mirror alone; the host's own classification said:
$UNSOURCED_VIEW"
grep -q 'UNSOURCED themes/twentytwentyone ' <<<"$UNSOURCED_VIEW" \
  || fail "the theme with no release and no import was not classified unsourced; the host's own classification said:
$UNSOURCED_VIEW"
grep -q 'UNSUPPORTED CODE themes/twentytwentyone \[code_component_unsourced\]' <<<"$UNSOURCED_OUT" \
  || fail "the unsourced theme did not block the proposal as code_component_unsourced"
grep -q 'wprism code-import <archive.zip>' <<<"$UNSOURCED_OUT" \
  || fail "the blocker did not name the import remedy"
grep -q 'wprism init --first-party=themes/twentytwentyone' <<<"$UNSOURCED_OUT" \
  || fail "the blocker did not name the first-party remedy"
grep -q 'result: blocked; no configuration, state, identity, or ledger mutation was made' <<<"$UNSOURCED_OUT" \
  || fail "the blocked proposal did not state that nothing was written"
[ ! -e "$HOST_REPO/site.wprism.json" ] && [ ! -d "$HOST_REPO/code" ] \
  || fail "a blocked classification mutated the repository"

assert_exit 0 "wprism init with the theme declared first-party" \
  "${WPRISM[@]}" init "${PAIR}1" --yes --first-party=themes/twentytwentyone --cache-dir="$CODE_MIRROR/cache-empty"
unset WPRISM_CODE_ARTIFACT_BASE
FIRST_PARTY_OUT="$OUT"
grep -q 'code classification: 1 locked, 1 first-party, 0 unsourced' <<<"$FIRST_PARTY_OUT" \
  || fail "the first-party proposal did not render the classification; the host's own classification said:
$(grep -E '^ +(LOCKED|FIRST-PARTY|UNSOURCED) ' <<<"$FIRST_PARTY_OUT" || true)"
grep -q 'FIRST-PARTY themes/twentytwentyone ' <<<"$FIRST_PARTY_OUT" \
  || fail "the declared theme was not classified first-party"

jq -e '.code == {"format":2,"layout":"wp-content","lock":"code/wprism-code.lock.json","source":"code/wp-content"}' \
  "$HOST_REPO/site.wprism.json" >/dev/null \
  || fail "the first-party baseline did not declare code format 2 naming the lock"
jq -e --arg sha "$WOO_PINNED_SHA256" '
  .format == "wprism-code-lock/v2" and
  .first_party == ["themes/twentytwentyone"] and
  ([.components[] | "\(.root)/\(.component)"]) == ["plugins/woocommerce"] and
  (.components[0] | .origin.kind == "wp-org-release" and .origin.archive_sha256 == $sha)
' "$HOST_REPO/code/wprism-code.lock.json" >/dev/null \
  || fail "the published code lock did not record WooCommerce locked and the theme first-party:
$(cat "$HOST_REPO/code/wprism-code.lock.json" 2>&1)"

git1 config user.name 'WPrism Init Regression'
git1 config user.email 'wprism-init@example.invalid'
git1 add .gitignore site.wprism.json code state media
git1 commit -m 'wprism: initial code and state baselines' >/dev/null
[ -z "$(git1 ls-files -- code/wp-content/plugins/woocommerce)" ] \
  || fail "Git tracked the locked WooCommerce component"
[ -n "$(git1 ls-files -- code/wp-content/themes/twentytwentyone)" ] \
  || fail "Git does not carry the theme the operator declared first-party"
grep -qx "/code/wp-content/plugins/woocommerce/" "$HOST_REPO/.gitignore" \
  || fail "the locked WooCommerce component has no root-anchored ignore line"
! grep -qx "/code/wp-content/themes/twentytwentyone/" "$HOST_REPO/.gitignore" \
  || fail "the first-party theme was ignored as if it were locked"
[ -f "$HOST_REPO/code/wp-content/plugins/woocommerce/woocommerce.php" ] \
  || fail "the locked WooCommerce bytes left the working tree"
[ -z "$(git1 status --porcelain)" ] \
  || fail "the first-party baseline left unstaged repository files"

# THE claim, live: the declaration is about Git, never about the payload, so
# the same bytes compile to the identical code_revision the golden path
# published with the theme locked.
FIRST_PARTY_CODE_REVISION=$(wp1 db query "SELECT v FROM wp_wprism_kv WHERE k = 'code_revision'" \
  --skip-column-names | tr -d '[:space:]')
[ "$FIRST_PARTY_CODE_REVISION" = "$GOLDEN_CODE_REVISION" ] \
  || fail "the first-party baseline compiled to code_revision $FIRST_PARTY_CODE_REVISION, not the golden run's $GOLDEN_CODE_REVISION; the classification must move no byte"
assert_exit 0 "wprism status after first-party init" "${WPRISM[@]}" status "${PAIR}1"
grep -q '0 conflict' <<<"$OUT" || fail "first-party baseline status did not report zero conflicts"
pass "an unsourced component blocks, --first-party declares, and Git carries exactly what the operator declared"

say "post-COMMIT SIGKILL finalizes the verified tuple instead of stranding its journal"
repo_host 2
find "$HOST_REPO2" -mindepth 1 -delete
wp2 db query 'DROP TABLE IF EXISTS wp_wprism_journal,wp_wprism_kv,wp_wprism_map,wp_wprism_state' >/dev/null
assert_init_plan wp2 /siterepo "pre-unlink journal SIGKILL"
COMMITTED_DIGEST="$INIT_PLAN_DIGEST"
set +e
OUT=$("${COMPOSE[@]}" run --rm -T \
  -e WPRISM_TEST_MODE=1 \
  -e WPRISM_TEST_INIT_KILL_PHASE=attempt-remove-pre-unlink \
  cli2 wp wprism init --repo=/siterepo --confirm="$COMMITTED_DIGEST" --format=json 2>&1)
CODE=$?
set -e
printf '%s\n' "$OUT"
[ "$CODE" -ne 0 ] || fail "pre-unlink journal SIGKILL unexpectedly succeeded"
[ -f "$HOST_REPO2/.wprism-init-attempt" ] \
  && [ -f "$HOST_REPO2/state.capture-receipt" ] \
  && [ -d "$HOST_REPO2/state" ] \
  || fail "pre-unlink journal kill did not retain the committed recovery tuple"
repo_host 2
cp "$HOST_REPO2/site.wprism.json" "/tmp/${PAIR}-committed-site.wprism.json"
printf '\n' >>"$HOST_REPO2/site.wprism.json"
assert_exit 1 "committed init tamper refuses finalization" "${WPRISM[@]}" init "${PAIR}2" --yes
[ -f "$HOST_REPO2/.wprism-init-attempt" ] \
  && [ -f "$HOST_REPO2/state.capture-receipt" ] \
  && [ -d "$HOST_REPO2/state" ] \
  || fail "committed finalization refusal discarded recovery evidence"
cp "/tmp/${PAIR}-committed-site.wprism.json" "$HOST_REPO2/site.wprism.json"
assert_exit 0 "committed init journal finalization" "${WPRISM[@]}" init "${PAIR}2" --yes
grep -q 'Verified the interrupted committed init' <<<"$OUT" \
  || fail "committed finalization did not report its verified recovery outcome"
[ ! -e "$HOST_REPO2/.wprism-init-attempt" ] \
  && [ ! -e "$HOST_REPO2/.wprism-init-attempt.next" ] \
  || fail "committed finalization left a stale init journal"
assert_exit 0 "status after committed journal finalization" "${WPRISM[@]}" status "${PAIR}2"
find "$HOST_REPO2" -maxdepth 1 -name '.*.wprism-init-compensate-*' -print -quit | grep -q . \
  && fail "committed finalization stranded a hidden journal claim"
pass "committed initialization survives a crash immediately before atomic journal removal"

say "a crash immediately after atomic journal removal leaves a complete usable baseline"
repo_host 2
find "$HOST_REPO2" -mindepth 1 -delete
wp2 db query 'DROP TABLE IF EXISTS wp_wprism_journal,wp_wprism_kv,wp_wprism_map,wp_wprism_state' >/dev/null
assert_init_plan wp2 /siterepo "post-unlink journal SIGKILL"
POST_UNLINK_DIGEST="$INIT_PLAN_DIGEST"
set +e
OUT=$("${COMPOSE[@]}" run --rm -T \
  -e WPRISM_TEST_MODE=1 \
  -e WPRISM_TEST_INIT_KILL_PHASE=attempt-remove-post-unlink \
  cli2 wp wprism init --repo=/siterepo --confirm="$POST_UNLINK_DIGEST" --format=json 2>&1)
CODE=$?
set -e
printf '%s\n' "$OUT"
[ "$CODE" -ne 0 ] || fail "post-unlink journal SIGKILL unexpectedly succeeded"
[ ! -e "$HOST_REPO2/.wprism-init-attempt" ] \
  && [ ! -e "$HOST_REPO2/.wprism-init-attempt.next" ] \
  || fail "post-unlink crash retained a stale init journal"
find "$HOST_REPO2" -maxdepth 1 -name '.*.wprism-init-compensate-*' -print -quit | grep -q . \
  && fail "post-unlink crash stranded a hidden journal claim"
[ -f "$HOST_REPO2/site.wprism.json" ] \
  && [ -d "$HOST_REPO2/code/wp-content" ] \
  && [ -d "$HOST_REPO2/state" ] \
  && [ -f "$HOST_REPO2/state.capture-receipt" ] \
  || fail "post-unlink crash lost part of the committed init tuple"
assert_exit 0 "status after post-unlink journal crash" "${WPRISM[@]}" status "${PAIR}2"
pass "atomic journal removal has no absent-canonical hidden-claim crash state"

say "retained post-commit cleanup cannot masquerade as successful init"
repo_host 2
find "$HOST_REPO2" -mindepth 1 -delete
wp2 db query 'DROP TABLE IF EXISTS wp_wprism_journal,wp_wprism_kv,wp_wprism_map,wp_wprism_state' >/dev/null
assert_init_plan wp2 /siterepo "retained post-commit cleanup"
RETAINED_DIGEST="$INIT_PLAN_DIGEST"
set +e
OUT=$("${COMPOSE[@]}" run --rm -T \
  -e WPRISM_TEST_MODE=1 \
  -e WPRISM_TEST_PUBLISH_FAIL_PHASE=post-commit-cleanup \
  cli2 wp wprism init --repo=/siterepo --confirm="$RETAINED_DIGEST" --format=json 2>&1)
CODE=$?
set -e
printf '%s\n' "$OUT"
[ "$CODE" -ne 0 ] || fail "retained post-commit cleanup was reported as successful init"
[ -f "$HOST_REPO2/state.capture-lock" ] && fail "unexpected misspelled capture-lock artifact"
[ -f "$HOST_REPO2/state.capture.lock" ] \
  && [ -f "$HOST_REPO2/state.capture-intent" ] \
  && [ -f "$HOST_REPO2/state.capture-receipt" ] \
  && [ -d "$HOST_REPO2/state" ] \
  || fail "retained cleanup did not preserve its complete recovery tuple and canonical lock"
RETAINED_RECEIPT_BEFORE=$(sha256sum "$HOST_REPO2/state.capture-receipt" | awk '{print $1}')
RETAINED_STATE_BEFORE=$(find "$HOST_REPO2/state" -type f -print0 | sort -z | xargs -0 sha256sum | sha256sum | awk '{print $1}')
ORIGINAL_DESCRIPTION=$(wp2 option get blogdescription)
wp2 option update blogdescription 'must not replace the retained init receipt' >/dev/null
assert_exit 1 "ordinary capture is blocked by retained init recovery" wp2 wprism capture --repo=/siterepo --format=json
grep -q 'sealed init recovery journal exists' <<<"$OUT" \
  || fail "ordinary capture refusal did not name the init recovery authority"
[ "$RETAINED_RECEIPT_BEFORE" = "$(sha256sum "$HOST_REPO2/state.capture-receipt" | awk '{print $1}')" ] \
  || fail "blocked ordinary capture replaced the initial receipt"
[ "$RETAINED_STATE_BEFORE" = "$(find "$HOST_REPO2/state" -type f -print0 | sort -z | xargs -0 sha256sum | sha256sum | awk '{print $1}')" ] \
  || fail "blocked ordinary capture replaced the initial state baseline"
wp2 option update blogdescription "$ORIGINAL_DESCRIPTION" >/dev/null
assert_exit 0 "verified finalization after retained cleanup" "${WPRISM[@]}" init "${PAIR}2" --yes
grep -q 'Verified the interrupted committed init' <<<"$OUT" \
  || fail "retained-cleanup finalization did not report its verified outcome"
[ ! -e "$HOST_REPO2/state.capture-intent" ] && [ ! -e "$HOST_REPO2/state.capture-backup" ] \
  || fail "verified init finalization did not finish retained publication cleanup"
[ ! -e "$HOST_REPO2/.wprism-init-attempt" ] \
  && [ ! -e "$HOST_REPO2/.wprism-init-attempt.next" ] \
  || fail "retained-cleanup finalization left a stale init journal"
assert_exit 0 "status after retained init cleanup recovery" "${WPRISM[@]}" status "${PAIR}2"
pass "retained init cleanup is receipt-bound and the canonical lock remains the recovery rendezvous"

say "certified per-init clock: completed_within_fifteen_minutes"
# issue #3428. Each entry was already budgeted at the moment it was measured, so
# reaching here means every one of them passed; this restates them together as
# the certified assertion's evidence and refuses a set that measured nothing --
# a claim whose subject silently disappeared is worse than a failing one.
[ "${#INIT_TIMINGS[@]}" -eq "$INIT_TIMED_CASES_EXPECTED" ] \
  || fail "the per-init clock timed ${#INIT_TIMINGS[@]} golden-path init(s), not the $INIT_TIMED_CASES_EXPECTED it certifies"
for TIMING in "${INIT_TIMINGS[@]}"; do
  printf '  %6ss  %s\n' "${TIMING%%$'\t'*}" "${TIMING#*$'\t'}"
done
pass "every golden-path init completed within ${INIT_BUDGET_SECONDS}s of its own proposal (per-init wall)"

SUITE_ELAPSED=$((SECONDS - SUITE_STARTED_AT))
printf '\ninformational: whole suite took %ss (harness cost -- WooCommerce install, 5000-row risk fixture, ~20 injected-failure confirmations; NOT the certified claim)\n' \
  "$SUITE_ELAPSED"

printf '\n\033[1;32m✔ REGRESS_WPRISM_INIT PASSED\033[0m\n'
