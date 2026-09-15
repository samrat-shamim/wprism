#!/usr/bin/env bash
# Conformance gate (DESIGN.md §6 / adversarial-review finding #20 — the
# manifest-treadmill answer): a generalized capture -> apply -> re-capture
# round-trip harness, run per manifest against a FRESH, disposable env pair.
# This canonical local gate knows nothing manifest-specific beyond what's
# declared by each package's tests/conformance/entry.json and optional
# hook files, each invoked at a fixed point in the flow below IF PRESENT —
# this file never inspects what any of them actually do. Adapter packages own
# the same hook names under adapter-packages/<name>/tests/conformance/; the legacy
# paths below remain the package fallback while the other adapters migrate:
#   conformance/seeds/<name>.sh        conf1 only, before capture: author
#                                       the manifest's representative content.
#   conformance/postdeploy/<name>.sh   conf2 only, strictly after target
#                                       lifecycle setup and strictly before
#                                       `wprism apply`: fixups that need conf2's
#                                       plugin genuinely ACTIVE. Normal and
#                                       agent-roundtrip entries reach this via
#                                       deploy; agent-apply-roundtrip uses the
#                                       disposable harness's native setup after
#                                       both deployment boundaries refuse. conf2
#                                       arrives with plugin FILES only
#                                       (install_env's role=target). Motivating case (task
#                                       issue #3223): a plugin's OWN activation
#                                       hook can mint default content
#                                       independently on each side with no
#                                       natural key for apply to converge on
#                                       (Snapshot.php's "mapped" identity
#                                       mode docblock) — see
#                                       seeds/ninja-forms.sh and
#                                       postdeploy/ninja-forms.sh for the
#                                       concrete case, split across the two
#                                       hooks because conf1 is active at
#                                       seed time and conf2 isn't.
#   conformance/postapply/<name>.sh    conf2 only, strictly after successful
#                                       apply and before the generic canonical
#                                       recapture/diff: assert and remove only
#                                       test-owned target-local witnesses that
#                                       must survive apply but are not canonical.
#   conformance/checks/<name>.sh       conf2 only, after apply: render-level
#                                       acceptance a byte-diff can't see.
#   conformance/capture-checks/<name>.sh
#                                     conf1 only, after deterministic capture:
#                                       plugin/API and canonical-shape checks
#                                       for a reviewed capture-plan profile.
#
# Entries default to `mode: roundtrip`. `mode: agent-roundtrip` qualifies
# experimental adapters through the documented lower-level agent verbs while
# proving the host promotion gate still refuses. `mode: agent-apply-roundtrip`
# is the honest sibling for a pin set with a host-owned provider phase: both
# aggregate host and direct target deployment refuse, the disposable harness
# establishes native lifecycle state, and the public Apply/consumer/recapture
# path remains exercised. A separately deployable participant keeps its own
# bounded claim; the aggregate scenario does not acquire deploy authority.
# Neither mode is certification.
# `mode: capture-plan` is the bounded
# evidence path for an experimental adapter that deliberately does not claim
# apply: it still boots an exact-artifact pair, seeds through plugin APIs,
# captures twice, lints, runs the real plan/capability paths, and invokes its
# source-side checks, then stops before deploy/apply. Treating an unsupported
# apply as a failed round-trip would pressure authors to overclaim merely to
# make the harness green.
#
# Env provider: sandbox/bin/pair.sh (task #74's sandbox redesign), not a
# per-manifest docker-compose profile. `pair.sh reset conf` + `pair.sh up
# conf ...` gives a genuinely fresh WordPress install on both sides every
# run — DROP/CREATE against the one shared MariaDB server instead of the
# old per-pair volume-rm-and-reinit cycle, and DB-level readiness instead of
# the `wp core version` check every other script in this sandbox still
# uses. See docs/sandbox.md for the full model. Everything from "init the
# site repo" onward is unchanged from before this migration — only env
# provisioning (this file's first ~60 lines) moved.
#
# Usage:
#   bash sandbox/conformance/run.sh <manifest-name>
#   bash sandbox/conformance/run.sh --scenario=<integration-scenario-name>
# Set CONF_EXPECTED_SOURCE_SHA=$(git rev-parse HEAD) to bind the sweep to an
# exact agent/manifests commit (issue #3377's gate — see below, before reset).
# Set CONF_RECORD_VECTOR=<file> to leave a replayable `wprism-conformance-vector/v1`
# document behind (WP-2.7, conformance/record-vector.sh — recorded only after
# the round-trip acceptance passes, replayed offline by
# sandbox/tests/offline/capture/regress_conformance_vector_replay.php).
#
# Concurrency: the pair NAME is parameterized so sweeps no longer serialize
# behind one host-wide 'conf' instance (a fleet-scale bottleneck — and two
# writers on one pair produce FALSE failures: reset's DROP/CREATE lands
# under the other run's feet, proven live during wave 1's yoast leg).
# Default CONF_PAIR=conf keeps single-user behavior byte-identical; an
# agent runs its own sweep with e.g.
#   CONF_PAIR=codexmaccf CONF1_PORT=8890 CONF2_PORT=8891 bash run.sh core
# A custom pair REQUIRES explicit ports (two sweeps on the default ports
# would collide at bind time, loudly but confusingly late). pair.sh's
# dynamic host budget applies to sweep pairs like any other.
set -euo pipefail
cd "$(dirname "$0")/.."   # -> sandbox/
SUBJECT="${1:-}"
SCENARIO=""
case "$SUBJECT" in
  --scenario=*)
    SCENARIO="${SUBJECT#--scenario=}"
    [[ "$SCENARIO" =~ ^[a-z][a-z0-9]*(-[a-z0-9]+)*$ ]] \
      || { echo "FAIL: integration scenario name is not canonical: '$SCENARIO'" >&2; exit 1; }
    MANIFEST="$SCENARIO"
    ;;
  *) MANIFEST="$SUBJECT" ;;
esac
[ -n "$MANIFEST" ] || { echo "usage: run.sh <manifest-name> | --scenario=<integration-scenario-name>" >&2; exit 1; }

CONF_PAIR="${CONF_PAIR:-conf}"
[[ "$CONF_PAIR" =~ ^[a-z][a-z0-9]*$ ]] \
  || { echo "FAIL: CONF_PAIR '$CONF_PAIR' invalid (pair.sh naming: lowercase letters/digits, letter first)" >&2; exit 1; }
if [ "$CONF_PAIR" != "conf" ] && { [ -z "${CONF1_PORT:-}" ] || [ -z "${CONF2_PORT:-}" ]; }; then
  echo "FAIL: custom CONF_PAIR '$CONF_PAIR' requires explicit CONF1_PORT and CONF2_PORT (the 8806/8807 defaults belong to the shared 'conf' instance)" >&2
  exit 1
fi
# Seed scripts write .tmp-* helper files into the side-1 site repo (it is
# bind-mounted to /siterepo inside the containers, so wp eval-file can read
# them) — they take the directory from the exported CONF_REPO1, defaulting
# to the classic siterepo/conf1 when invoked standalone.
R1="siterepo/${CONF_PAIR}1"
R2="siterepo/${CONF_PAIR}2"
ORIGIN="siterepo/origin-${CONF_PAIR}.git"
export CONF_PAIR CONF_REPO1="$R1" CONF_REPO2="$R2"

say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }

# The premise/answer assertion helpers the seeds and postdeploy hooks call
# (require_fixture_ids/values/state, require_wprism_answered) live in the shared
# fragment: TWO harnesses source those hooks (this one and
# sandbox/tests/certify/certify_version_matrix.sh), and issue #3408 is what happens when
# a helper reaches only one of them. Full doctrine in the fragment itself.
. conformance/asserts.sh
. tests/lib/conformance_private_command.sh

# Package-first lookup makes the adapter directory the authority without
# breaking the still-global adapters. A duplicate is ambiguous ownership, so
# it refuses instead of silently choosing one copy.
PACKAGE_CONFORMANCE="../adapter-packages/$MANIFEST/tests/conformance"
CONFORMANCE_OWNER="${PACKAGE_CONFORMANCE%/tests/conformance}"
SCENARIO_RECORD=""
if [ -n "$SCENARIO" ]; then
  [ -z "${CONFORMANCE_ENTRY_FILE:-}" ] \
    || fail 'a named integration scenario owns its conformance entry; CONFORMANCE_ENTRY_FILE cannot replace it'
  REPOSITORY_ROOT=$(cd .. && pwd -P)
  SCENARIO_ROOT="$REPOSITORY_ROOT/integration-scenarios/$SCENARIO"
  [ -d "$SCENARIO_ROOT" ] && [ ! -L "$SCENARIO_ROOT" ] \
    || fail "integration scenario '$SCENARIO' is not an ordinary owned directory"
  SCENARIO_RECORD="$SCENARIO_ROOT/scenario.json"
  CONFORMANCE_ENTRY_FILE="$SCENARIO_ROOT/fixtures/conformance-entry.json"
  [ -f "$SCENARIO_RECORD" ] && [ ! -L "$SCENARIO_RECORD" ] \
    || fail "integration scenario '$SCENARIO' has no ordinary participant record"
  [ -f "$CONFORMANCE_ENTRY_FILE" ] && [ ! -L "$CONFORMANCE_ENTRY_FILE" ] \
    || fail "integration scenario '$SCENARIO' has no ordinary conformance entry"
  CONFORMANCE_OWNER="$SCENARIO_ROOT"
fi
conformance_hook() { # conformance_hook <package-basename> <legacy-path>
  if [ "${CONFORMANCE_HOOKS:-null}" != null ]; then
    jq -r --arg phase "${1%.sh}" '.[$phase] // empty' <<<"$CONFORMANCE_HOOKS"
    return
  fi
  local package_path="$PACKAGE_CONFORMANCE/$1" legacy_path="$2"
  if [ -e "$package_path" ] && [ -e "$legacy_path" ]; then
    fail "duplicate conformance hook for '$MANIFEST': $package_path and $legacy_path"
  fi
  if [ -f "$package_path" ]; then
    printf '%s\n' "$package_path"
  else
    printf '%s\n' "$legacy_path"
  fi
}

# A scoped certificate may bind a single extracted fixture entry instead of
# the all-adapter fixture index.  The harness remains generic: it accepts the
# same entry shape and never learns a plugin name.  Keeping the fixture input
# singular is what lets an unrelated adapter-fixture edit stay out of one
# adapter's conservative evidence closure.
PACKAGE_ENTRY="$PACKAGE_CONFORMANCE/entry.json"
PLATFORM_ENTRY="conformance/entries/$MANIFEST.json"
if [ -n "${CONFORMANCE_ENTRY_FILE:-}" ]; then
  [ -f "$CONFORMANCE_ENTRY_FILE" ] \
    || fail "CONFORMANCE_ENTRY_FILE is not a regular fixture entry: $CONFORMANCE_ENTRY_FILE"
  ENTRY=$(jq -ce --arg manifest "$MANIFEST" '
    if (keys | sort) == ["entry", "manifest"] and .manifest == $manifest and (.entry | type) == "object"
    then .entry else error("entry must name the requested manifest") end
  ' "$CONFORMANCE_ENTRY_FILE") \
    || fail "CONFORMANCE_ENTRY_FILE must contain one exact named fixture entry for '$MANIFEST'"
elif [ -f "$PACKAGE_ENTRY" ]; then
  ENTRY=$(jq -ce --arg manifest "$MANIFEST" '
    if (keys | sort) == ["entry", "manifest"] and .manifest == $manifest and (.entry | type) == "object"
    then .entry else error("entry must name the requested manifest") end
  ' "$PACKAGE_ENTRY") \
    || fail "package conformance entry must contain one exact named fixture entry for '$MANIFEST': $PACKAGE_ENTRY"
elif [ -f "$PLATFORM_ENTRY" ]; then
  ENTRY=$(jq -ce --arg manifest "$MANIFEST" '
    if (keys | sort) == ["entry", "manifest"] and .manifest == $manifest and (.entry | type) == "object"
    then .entry else error("entry must name the requested manifest") end
  ' "$PLATFORM_ENTRY") \
    || fail "platform conformance entry must contain one exact named fixture entry for '$MANIFEST': $PLATFORM_ENTRY"
else
  fail "unknown manifest '$MANIFEST': no package or platform conformance entry"
fi
CONFORMANCE_HOOKS=$(php ../tools/conformance-hooks.php "$ENTRY" "$CONFORMANCE_OWNER") \
  || fail "manifest '$MANIFEST' has invalid declared conformance hooks"
if [ -n "$SCENARIO" ] && [ "$CONFORMANCE_HOOKS" = null ]; then
  fail "integration scenario '$SCENARIO' must explicitly own all five conformance hook phases"
fi
# Package-owned conformance resolves only that capsule's artifact fragment.
# Core/FSE consume no adapter plugin artifacts, so their child pair and check
# hooks inherit explicit platform-only authority. Named integration scenarios
# set their declared participant context independently of this platform lane.
if [ -n "$SCENARIO" ]; then
  :
elif [ -f "../adapter-packages/$MANIFEST/evidence/artifacts.lock.json" ]; then
  export WPRISM_ARTIFACT_PACKAGE="$MANIFEST"
elif [ "$MANIFEST" = core ] || [ "$MANIFEST" = fse ]; then
  export WPRISM_ARTIFACT_PLATFORM_ONLY=1
fi
jq -e '
  (.plugins | type == "array") and
  (.plugins | all(type == "object" and (keys == ["slug", "version"]) and
    (.slug | test("^[a-z0-9][a-z0-9._-]*[a-z0-9]$")) and
    (.version | test("^[0-9A-Za-z][0-9A-Za-z._-]*$")))) and
  ((.themes // []) | type == "array") and
  ((.themes // []) | all(type == "object" and (keys == ["slug", "version"]) and
    (.slug | test("^[a-z0-9][a-z0-9._-]*[a-z0-9]$")) and
    (.version | test("^[0-9A-Za-z][0-9A-Za-z._-]*$"))))
' <<<"$ENTRY" >/dev/null || fail "manifest '$MANIFEST' has malformed pinned plugin/theme fixtures"
mapfile -t PLUGINS < <(echo "$ENTRY" | jq -c '.plugins[]')
mapfile -t THEMES < <(echo "$ENTRY" | jq -c '.themes[]?')
SETUP=$(echo "$ENTRY" | jq -r '.setup // ""')
ADOPT_BY_SLUG=$(conformance_adopt_by_slug "$ENTRY") \
  || fail "manifest '$MANIFEST' has malformed fixture adoption policy"
DISABLE_TARGET_CRON=$(conformance_disable_target_cron "$ENTRY") \
  || fail "manifest '$MANIFEST' has malformed target cron fixture policy"
MODE=$(echo "$ENTRY" | jq -r '.mode // "roundtrip"')
case "$MODE" in
  roundtrip|capture-plan|agent-roundtrip|agent-apply-roundtrip) ;;
  *) fail "unknown conformance mode '$MODE' for manifest '$MANIFEST' (expected roundtrip|capture-plan|agent-roundtrip|agent-apply-roundtrip)" ;;
esac
if [[ "$MODE" = agent-* ]] && [ -n "${CONF_RECORD_VECTOR:-}" ]; then
  fail 'experimental agent roundtrips cannot publish a certified conformance vector'
fi

# Prefer the legacy docker-compose.yml conf1/conf2 ports (8806/8807) so
# conformance/checks/*.sh and seeds/elementor.sh — which read CONF1_PORT/
# CONF2_PORT with those exact values as their DEFAULT, so they're unchanged
# in the common case — need no override. Fall back only if the legacy
# conf1/conf2 containers are actually still running (this migration runs
# once tasks #72/#73/#75 are complete, which says nothing about whether
# anyone has torn down their own containers since — see docs/sandbox.md's
# note that "leave it running" was the old norm this whole redesign
# responds to): a real port collision there would otherwise surface as an
# opaque `docker compose up` failure instead of this explicit, named cause.
if [ "$CONF_PAIR" = "conf" ]; then
  if docker ps --format '{{.Names}}' | grep -qE 'wprism-sandbox-wp-conf[12]-1'; then
    CONF1_PORT=8840
    CONF2_PORT=8841
    echo "note: legacy conf1/conf2 containers are still running — using fallback ports $CONF1_PORT/$CONF2_PORT instead of 8806/8807" >&2
  else
    CONF1_PORT="${CONF1_PORT:-8806}"
    CONF2_PORT="${CONF2_PORT:-8807}"
  fi
fi
WORDPRESS_OFFLINE="${WPRISM_WORDPRESS_ORG_OFFLINE:-0}"
case "$WORDPRESS_OFFLINE" in
  0|1) ;;
  *) fail "WPRISM_WORDPRESS_ORG_OFFLINE must be 0 or 1" ;;
esac
export WPRISM_ARTIFACT_OFFLINE="$WORDPRESS_OFFLINE"
COMPOSE="docker compose -p wprism-${CONF_PAIR} -f pair.yml -f pair.http.yml -f pair.artifacts.yml"
PAIR_COMPOSE=(docker compose -p "wprism-${CONF_PAIR}" -f pair.yml -f pair.http.yml -f pair.artifacts.yml)
PAIR_UP_FLAGS=(--http --artifacts)
if [ "$WORDPRESS_OFFLINE" = 1 ]; then
  COMPOSE="$COMPOSE -f pair.wordpress-offline.yml"
  PAIR_COMPOSE+=(-f pair.wordpress-offline.yml)
  PAIR_UP_FLAGS+=(--wordpress-offline)
fi
# shellcheck source=../bin/fetch-artifact.sh
. bin/fetch-artifact.sh
if [ -n "$SCENARIO" ]; then
  [ -z "${WPRISM_ARTIFACT_PACKAGE:-}" ] && [ "${WPRISM_ARTIFACT_PLATFORM_ONLY:-0}" = 0 ] \
    || fail "integration scenario '$SCENARIO' cannot inherit package-only or platform-only artifact authority"
  SCENARIO_PARTICIPANTS=$(artifact_library_scenario_participants "$SCENARIO_RECORD") \
    || fail "integration scenario '$SCENARIO' has malformed participant authority"
  ENTRY_PARTICIPANTS=$(jq -er '
    if (.pin | type) == "array" and .pin[0] == "core" and
      (.pin | length) >= 3 and (.pin == (.pin | unique))
    then .pin[1:] | join(",")
    else error("scenario pins must be core followed by at least two unique participants")
    end
  ' <<<"$ENTRY") || fail "integration scenario '$SCENARIO' has malformed conformance pins"
  [ "$ENTRY_PARTICIPANTS" = "$SCENARIO_PARTICIPANTS" ] \
    || fail "integration scenario '$SCENARIO' conformance pins disagree with its participant record"
  if [ -n "${WPRISM_ARTIFACT_PARTICIPANTS:-}" ] \
    && [ "$WPRISM_ARTIFACT_PARTICIPANTS" != "$SCENARIO_PARTICIPANTS" ]; then
    fail "integration scenario '$SCENARIO' artifact authority disagrees with its participant record"
  fi
  export WPRISM_ARTIFACT_PARTICIPANTS="$SCENARIO_PARTICIPANTS"
fi
validate_artifact_library \
  || fail "artifact library is malformed; conformance refused before pair reset"
# Package checks run as child shells. Pin the repository root explicitly so
# the exported artifact helpers never try to derive it from BASH_SOURCE after
# function export has detached them from bin/artifact-library.sh.
WPRISM_ARTIFACT_LIBRARY_ROOT="$(cd .. && pwd)"
export WPRISM_ARTIFACT_LIBRARY_ROOT
wp_env() { # wp_env <conf1|conf2> <wp args...>
  local env="$1"; shift
  local side="${env#conf}"   # conf1 -> 1, conf2 -> 2 (pair.sh's generic side numbering)
  # The disposable site repo is jointly managed by host-side Git and the
  # container's uid-33 wp-cli process. Give files created by conformance wp
  # commands a cooperative umask so the host can diff/remove capture output
  # on native Linux bind mounts. This wraps only the test harness; WPrism's
  # production process umask and permission policy remain untouched.
  $COMPOSE run --rm -T "cli${side}" sh -c 'umask 000; exec wp "$@"' sh "$@"
}
wp_conf1() { wp_env conf1 "$@"; }
wp_conf2() { wp_env conf2 "$@"; }
. lib/host_orchestrator.sh
WPRISM_HOST_CLI="$(cd .. && pwd)/cli/wprism"
WPRISM_HOST_REGISTRY=$(mktemp "${TMPDIR:-/tmp}/wprism-conformance-host.${CONF_PAIR}.XXXXXX")
CONFORMANCE_CRON_WINDOW_STARTED=0
conformance_cleanup() {
  local status="$1"
  trap - EXIT INT TERM
  rm -f -- "$WPRISM_HOST_REGISTRY" || { [ "$status" -ne 0 ] || status=1; }
  if [ "$CONFORMANCE_CRON_WINDOW_STARTED" = 1 ]; then
    wordpress_cron_window_exit "$status"
  fi
  exit "$status"
}
conformance_target_cron_transport() { wordpress_cron_window_compose_transport cli2 "$@"; }
conformance_target_cron_begin() {
  [ "$DISABLE_TARGET_CRON" = true ] || return 0
  . tests/lib/wordpress_cron_window.sh
  CONFORMANCE_CRON_WINDOW_STARTED=1
  trap 'exit 130' INT TERM
  wordpress_cron_window_begin wp_conf2 conformance_target_cron_transport \
    || fail 'conformance could not establish its owned target cron window'
}
conformance_target_cron_end() {
  [ "$CONFORMANCE_CRON_WINDOW_STARTED" = 1 ] || return 0
  wordpress_cron_window_release || fail 'conformance target cron window cleanup failed'
  CONFORMANCE_CRON_WINDOW_STARTED=0
}
trap 'conformance_cleanup "$?"' EXIT
wprism_host_registry_create "$WPRISM_HOST_REGISTRY" "$(pwd)/pair.yml" "$CONF_PAIR"
# pair.sh set these for ITS OWN compose invocations while bringing the pair
# up, but that was a separate process — its exports die with it. Every one
# of run.sh's own $COMPOSE calls below creates a fresh --rm container
# (never a persistent one), so pair.yml's ${WPRISM_PAIR}/${WPRISM_PORT1}/
# ${WPRISM_PORT2} interpolation (WORDPRESS_DB_NAME among them) needs these set
# in THIS shell too, every time — confirmed the hard way: without this,
# WORDPRESS_DB_NAME silently resolved to "wp_1" (WPRISM_PAIR defaulting to an
# empty string) instead of "wp_conf1", surfacing only as a generic "Error
# establishing a database connection" from wp-cli, not a missing-variable
# warning that would have pointed straight at the cause.
export WPRISM_PAIR="$CONF_PAIR" WPRISM_PORT1="$CONF1_PORT" WPRISM_PORT2="$CONF2_PORT"
export COMPOSE CONF1_PORT CONF2_PORT
export WPRISM_HOST_CLI WPRISM_HOST_REGISTRY
export -f wp_env wp_conf1 wp_conf2 host_wprism wprism_host_call say pass fail \
  require_fixture_ids require_fixture_values require_fixture_state \
  require_wprism_answered capture_wprism_json_success capture_wprism_json_checked capture_wprism_json_refusal require_observed_nonempty \
  capture_wprism_json_document \
  establish_core_environment_bindings \
  has_php_runtime_diagnostics assert_no_php_runtime_diagnostics \
  assert_wprism_required_environment assert_wprism_json_required_environment assert_wprism_apply_ready \
  establish_woocommerce_hpos normalize_woocommerce_harness_placeholder_mode \
  artifact_library_repo_root artifact_library_package_context artifact_library_participant_context \
  artifact_library_platform_context artifact_library_platform_emit artifact_library_emit \
  validate_artifact_library artifact_library_jq

# issue #3377: a sweep IS evidence, so it must be able to state which
# agent/manifests bytes produced it. CONF_EXPECTED_SOURCE_SHA=$(git rev-parse
# HEAD) binds this run to that exact commit: pair.sh's own gate then refuses
# below — before `reset` DROP/CREATEs either database and before any container
# starts — unless the source it is about to mount is that commit, clean (see
# pair.sh's assert_candidate_source for the canonical default and the explicit
# WPRISM_SOURCE_ROOT worktree override). Exported rather
# than passed as an argument for the same process-boundary reason WPRISM_PAIR/
# WPRISM_PORT1/WPRISM_PORT2 are exported above: pair.sh is a subprocess here, and
# `reset` accepts no flags at all. Unset leaves every sweep byte-identical.
if [ -n "${CONF_EXPECTED_SOURCE_SHA:-}" ]; then
  export WPRISM_EXPECTED_SOURCE_SHA="$CONF_EXPECTED_SOURCE_SHA"
fi
# Every wp_env call below is a fresh direct Compose process. Keep the selected
# mounts in this shell so another pair's teardown cannot rewrite shared .env
# to a different checkout between this candidate gate and apply.
. lib/pair_identity.sh
pair_identity_export_source_mounts \
  || fail 'conformance could not pin its selected source mounts in the caller environment'
case "$MODE" in
  capture-plan|agent-roundtrip|agent-apply-roundtrip)
    capture_wprism_json_success CAPTURE_PLAN_CLAIMS 'capture-plan shipped declaration projection' \
      php "$PAIR_SOURCE_ROOT/sandbox/tests/lib/capture_plan_claims.php" "$PAIR_SOURCE_ROOT" \
      "$(jq -c '.pin' <<<"$ENTRY")"
    ;;
esac
. lib/pair_db.sh
pair_db_select_engine

say "clean-room via pair.sh (DROP/CREATE beats volume rm + InnoDB re-init — conformance never trusts leftover state from a previous manifest's run)"
bash bin/pair.sh reset "$CONF_PAIR"

say "pair.sh up: boot conf1 (:$CONF1_PORT) / conf2 (:$CONF2_PORT), DB-level readiness, generic WordPress bootstrap"
bash bin/pair.sh up "$CONF_PAIR" "$CONF1_PORT" "$CONF2_PORT" "${PAIR_UP_FLAGS[@]}"
conformance_target_cron_begin

normalize_archive_root() { # normalize_archive_root <env> <plugin|theme> <slug> <archive-root>
  local env="$1" kind="$2" slug="$3" archive_root="$4" side="${1#conf}" base
  [ "$archive_root" != "$slug" ] || return 0
  case "$kind" in
    plugin) base=/var/www/html/wp-content/plugins ;;
    theme) base=/var/www/html/wp-content/themes ;;
    *) fail "invalid extension kind for archive-root normalization" ;;
  esac
  $COMPOSE run --rm -T "cli${side}" sh /wprism-harness/artifact-archive-root.sh \
    "$base" "$archive_root" "$slug" \
    || fail "could not normalize pinned $kind archive root $archive_root to $slug on $env"
}

install_env() { # install_env <conf1|conf2> <author|target> — pair.sh's `up`
  # already fully installed WordPress (core install, theme, permalinks,
  # .htaccess) on a freshly reset (empty) database and waited for real
  # DB-level readiness; this only does what's specific to conformance: the
  # manifest-decorated title (cosmetic parity with the pre-migration
  # title), stripping the default seed content, then this manifest's own
  # plugins + (author-only) setup hook.
  #
  # role=author (conf1) is where canonical state gets AUTHORED: full
  # install + activate + setup hook, same as always — capture must see the
  # fully-set-up environment.
  #
  # role=target (conf2) is the actual promotion target `wp wprism deploy`
  # reconciles later (see the "deploy conf2" step below, after the clone):
  # plugin FILES only, no --activate, no setup hook. Pre-activating conf2
  # here too (the old behavior, before this restructure) made `wprism
  # apply`'s job artificially easy — activation state already matched
  # canonical before deploy ever ran, so conformance never actually
  # exercised deploy's reconciliation. This is bug #2's fix.
  local env="$1" role="$2" side="${1#conf}" spec slug version artifact archive_root installed_version
  wp_env "$env" option update blogname "WPrism ${env} (${MANIFEST})"
  wp_env "$env" site empty --yes
  if [ "${#PLUGINS[@]}" -gt 0 ]; then
    for spec in "${PLUGINS[@]}"; do
      slug=$(jq -r '.slug' <<<"$spec")
      version=$(jq -r '.version' <<<"$spec")
      archive_root=$(artifact_library_jq -r --arg slug "$slug" --arg version "$version" \
        '.plugins[$slug][$version].archive_root // $slug')
      [[ "$archive_root" =~ ^[a-z0-9][a-z0-9._-]*[a-z0-9]$ ]] \
        || fail "pinned plugin archive root is malformed for $slug $version"
      artifact=$(fetch_artifact "$slug" "$version" "cli$side" plugin) \
        || fail "could not obtain pinned plugin artifact $slug $version for $env"
      wp_env "$env" plugin install "$artifact" --force
      normalize_archive_root "$env" plugin "$slug" "$archive_root"
      [ "$role" != author ] || wp_env "$env" plugin activate "$slug"
      wp_env "$env" plugin is-installed "$slug" >/dev/null \
        || fail "pinned plugin artifact $slug $version installed under an unexpected basename on $env"
      installed_version=$(wp_env "$env" plugin get "$slug" --field=version) \
        || fail "pinned plugin artifact $slug $version has no readable installed version on $env"
      [ "$installed_version" = "$version" ] \
        || fail "pinned plugin artifact $slug reported version $installed_version on $env, expected $version"
    done
  fi
  if [ "${#THEMES[@]}" -gt 0 ]; then
    for spec in "${THEMES[@]}"; do
      slug=$(jq -r '.slug' <<<"$spec")
      version=$(jq -r '.version' <<<"$spec")
      archive_root=$(artifact_library_platform_jq -r --arg slug "$slug" --arg version "$version" \
        '.themes[$slug][$version].archive_root // $slug')
      [[ "$archive_root" =~ ^[a-z0-9][a-z0-9._-]*[a-z0-9]$ ]] \
        || fail "pinned theme archive root is malformed for $slug $version"
      artifact=$(fetch_artifact "$slug" "$version" "cli$side" theme) \
        || fail "could not obtain pinned theme artifact $slug $version for $env"
      wp_env "$env" theme install "$artifact" --force
      normalize_archive_root "$env" theme "$slug" "$archive_root"
      wp_env "$env" theme is-installed "$slug" >/dev/null \
        || fail "pinned theme artifact $slug $version installed under an unexpected basename on $env"
      installed_version=$(wp_env "$env" theme get "$slug" --field=version) \
        || fail "pinned theme artifact $slug $version has no readable installed version on $env"
      [ "$installed_version" = "$version" ] \
        || fail "pinned theme artifact $slug reported version $installed_version on $env, expected $version"
    done
  fi
  if [ "$role" = author ]; then
    case "$SETUP" in
      "") ;;
      hpos)
        establish_woocommerce_hpos wp_env "$env" \
          || fail "could not establish HPOS through WooCommerce's native new-shop lifecycle on $env"
        ;;
      block-theme) wp_env "$env" theme activate twentytwentyfive || fail "could not activate twentytwentyfive on $env" ;;
      *) fail "unknown setup hook '$SETUP' for manifest '$MANIFEST'" ;;
    esac
  fi
  echo "env $env installed, role=$role ($MANIFEST: ${#PLUGINS[@]} pinned plugins, ${#THEMES[@]} pinned themes${SETUP:+, setup=$SETUP})"
}

# Test-only lifecycle setup for agent-apply-roundtrip. A manifest with schema
# or lifecycle providers correctly cannot use standalone `wp wprism deploy`:
# that command has no authenticated checkpoint or retained host session. This
# disposable pair still needs the plugin active before its package-owned
# provider and Apply checks run, so establish exactly canonical plugin/theme
# state through WordPress's public native commands and verify it below. This is
# fixture setup, never evidence for the adapter's deploy operation.
fixture_reconcile_target_lifecycle() { # <newline active slugs> <stylesheet>
  local canonical_active="$1" canonical_stylesheet="$2" current slug
  current=$(wp_conf2 plugin list --status=active --field=name | LC_ALL=C sort -u)
  while IFS= read -r slug || [ -n "$slug" ]; do
    [ -n "$slug" ] || continue
    grep -Fxq "$slug" <<<"$canonical_active" || wp_conf2 plugin deactivate "$slug" </dev/null
  done <<<"$current"
  while IFS= read -r slug || [ -n "$slug" ]; do
    [ -n "$slug" ] || continue
    grep -Fxq "$slug" <<<"$current" || wp_conf2 plugin activate "$slug" </dev/null
  done <<<"$canonical_active"
  current=$(wp_conf2 option get stylesheet)
  [ "$current" = "$canonical_stylesheet" ] || wp_conf2 theme activate "$canonical_stylesheet"
}

install_env conf1 author
install_env conf2 target
if [ "$MODE" = agent-apply-roundtrip ]; then
  pass 'conf1 fully authored; conf2 has plugin files only for deployment-refusal and disposable lifecycle evidence'
else
  pass "conf1 fully authored (activated + setup); conf2 has plugin files only — deploy (below) reconciles the rest"
fi

say "init the site repo (own origin, own clones — pins: $(echo "$ENTRY" | jq -c '.pin'))"
git init --bare -b main "$ORIGIN" >/dev/null
echo "$ENTRY" | jq '{
  manifests: .pin,
  policy: {options: {}, post_meta: {}, post_types: .post_types, taxonomies: .taxonomies},
  spec_version: 2
}' > "$R1"/site.wprism.json
cp site-repo.gitignore.template "$R1"/.gitignore
git -C "$R1" init -q -b main
git -C "$R1" remote add origin "../origin-${CONF_PAIR}.git"

SEED=$(conformance_hook seed.sh "conformance/seeds/$MANIFEST.sh")
say "seed representative authored content on conf1 ($SEED)"
[ -f "$SEED" ] || fail "no seed script for '$MANIFEST' (expected $SEED)"
bash "$SEED"
establish_core_environment_bindings wp_conf1 /siterepo admin@example.test \
  "http://localhost:$CONF1_PORT" "http://localhost:$CONF1_PORT"

say "capture conf1 into the site repo"
conformance_private_command cli1 capture wp_conf1 wprism capture --repo=/siterepo
git -C "$R1" add -A
git -C "$R1" -c user.name=wprism -c user.email=wprism@example.test commit -qm "capture: seeded $MANIFEST content on conf1"
git -C "$R1" push -qu origin main

# --- suspicious-ref lint gate ------------------------------------------------
# Generalized suspicious-ref linter (agent/src/Review/Lint.php / `wp wprism lint`):
# flags ref-shaped values that reached canonical state without a declared
# rewrite path — exactly the blind spot the byte-diff acceptance checks
# below cannot see (the FSE, Polylang and Elementor frontier explorations
# each hit it). HARD GATE:
# all in-tree manifests run clean against it; a finding here means either a
# manifest gap or a genuinely dangling/unrewritten ref — both are failures.
say "lint conf1's captured state (hard gate)"
# `wp wprism lint --format=json` exits 1 when it HAS findings (Cli.php's
# lint() prints the findings array, then WP_CLI::halt(1)) — that's the
# ordinary, expected non-zero outcome for the "found suspicious refs" case
# this whole gate exists to catch, which is why a bare `|| true` used to
# sit here. But a CRASH (Policy::load()/Lint::scan_tree() throwing —
# fatal, a DB/connection error, a malformed manifest) reaches
# WP_CLI::error(), which ALSO exits 1 — with no JSON ever printed to
# stdout. Exit code alone can't tell "1 because findings" apart from "1
# because crash", and the old `|| true` + `jq ... || echo 0` swallowed
# BOTH into "0 findings" — a crash silently became a pass. Fix: capture
# the exit code and stdout separately, and gate on stdout actually being a
# JSON array — a crash can't fake that (every code path that prints
# anything to stdout at all prints the findings array, nothing else).
LINT_RC=0
LINT_OUT=$(wp_conf1 wprism lint --repo=/siterepo --format=json) || LINT_RC=$?
LINT_JSON=$(printf '%s\n' "$LINT_OUT" | tail -1)
if ! printf '%s\n' "$LINT_JSON" | jq -e 'type == "array"' >/dev/null 2>&1; then
  printf '%s\n' "$LINT_OUT"
  fail "wp wprism lint crashed or produced malformed output (exit $LINT_RC, manifest: $MANIFEST) — expected a JSON array as the last line of output; raw output above"
fi
LINT_N=$(printf '%s\n' "$LINT_JSON" | jq 'length')
if [ "$LINT_RC" != "0" ] && [ "$LINT_N" = "0" ]; then
  printf '%s\n' "$LINT_OUT"
  fail "wp wprism lint exited $LINT_RC but its own output claims 0 findings (manifest: $MANIFEST) — inconsistent, treating as a crash rather than trusting it"
fi
if [ "$LINT_N" != "0" ]; then
  echo "$LINT_JSON" | jq .
  fail "wp wprism lint found $LINT_N suspicious ref(s) in captured state (manifest: $MANIFEST)"
fi
echo "lint: clean, 0 findings"
# --- end lint gate -----------------------------------------------------------

say "acceptance: capture is deterministic (capture twice, zero diff)"
conformance_private_command cli1 capture wp_conf1 wprism capture --repo=/siterepo --out=/siterepo/.tmp-state2 >/dev/null
diff -r "$R1"/state "$R1"/.tmp-state2 || fail "capture is not deterministic"
rm -rf "$R1"/.tmp-state2
pass "capture-twice diff is empty"

# A capture-plan profile makes claims only about the source-side capture,
# compilation, plan, and recapture paths. Its hook verifies plugin APIs and
# the canonical bytes those operations produced before this harness decides
# whether the target-facing round-trip is in scope.
CAPTURE_CHECK=$(conformance_hook capture-check.sh "conformance/capture-checks/$MANIFEST.sh")
if [ -f "$CAPTURE_CHECK" ]; then
  say "manifest-specific capture acceptance ($CAPTURE_CHECK)"
  bash "$CAPTURE_CHECK"
fi

if [ "$MODE" = "capture-plan" ]; then
  say "capture-plan acceptance: reviewed operations are reachable without claiming apply"
  run_wprism_capture_plan "$CAPTURE_PLAN_CLAIMS" wp_conf1 /siterepo
  pass "capture, compile, plan, and recapture paths are exercised; deploy/apply remain explicitly outside this profile"
  conformance_target_cron_end
  printf '\n\033[1;32m✔ CONFORMANCE PASSED (%s; capture-plan)\033[0m\n' "$MANIFEST"
  exit 0
fi
if [[ "$MODE" = agent-* ]]; then
  run_wprism_capture_plan "$CAPTURE_PLAN_CLAIMS" wp_conf1 /siterepo
fi

say "clone the repo for conf2"
git clone -q "$ORIGIN" "$R2"
REV=$(git -C "$R2" rev-parse HEAD)
establish_core_environment_bindings wp_conf2 /siterepo admin@example.test \
  "http://localhost:$CONF2_PORT" "http://localhost:$CONF2_PORT"
wprism_host_install_recovery_runtime "$PAIR_SOURCE_ROOT" "$R1" \
  || fail 'could not install the adoption-equivalent recovery runtime on conf1'
wprism_host_install_recovery_runtime "$PAIR_SOURCE_ROOT" "$R2" \
  || fail 'could not install the adoption-equivalent recovery runtime on conf2'
pass 'both dev-bound environments carry the exact durable recovery runtime an adopted target has'

CANON_ACTIVE=$(jq -r '.records.active_plugins.value[]? | split("/")[0]' "$R1"/state/options/core.json | sort -u)
CANON_TEMPLATE=$(jq -r '.records.template.value // empty' "$R1"/state/options/core.json)
CANON_STYLESHEET=$(jq -r '.records.stylesheet.value // empty' "$R1"/state/options/core.json)

# --- deploy conf2 from canonical --------------------------------------------
# The real promotion path this harness used to skip entirely (spec/
# repo-format.md's "Code-half facts & deploy"): conf2 arrived above with
# plugin FILES only, no activation, no setup hook (install_env's
# role=target) — this is the step that actually reconciles activation/
# theme state from canonical, via real activate_plugin()/switch_theme()
# calls (Deploy.php), deliberately outside `wprism apply`'s hook-free canary.
# Must run after conf2's clone (it reads canonical from THIS environment's
# /siterepo, pair.yml mounts "$R2" there — not conf1's) and
# before `wprism apply` (spec ordering: deploy code -> reconcile activation ->
# migrations fire as an activation side effect -> THEN apply state).
say "deploy conf2 from canonical (host wprism deploy) — the real promotion path"
DEPLOY_RC=0
DEPLOY_OUT=$(host_wprism conf2 deploy 2>&1) || DEPLOY_RC=$?
if [ "$MODE" = agent-roundtrip ]; then
  assert_agent_roundtrip_refusal "$CAPTURE_PLAN_CLAIMS" "$DEPLOY_RC" "$DEPLOY_OUT"
  printf '%s\n' "$DEPLOY_OUT"
  capture_wprism_json_success AGENT_DEPLOY_JSON 'experimental agent deployment' \
    wp_conf2 wprism deploy --repo=/siterepo --format=json
  jq -e '.lifecycle_phase == "all" and .code_mismatch == [] and .code_drift == [] and .warnings == []' \
    <<<"$AGENT_DEPLOY_JSON" >/dev/null || fail 'experimental agent deployment did not settle cleanly'
  pass 'agent lifecycle exercised; host production deployment remains refused'
elif [ "$MODE" = agent-apply-roundtrip ]; then
  assert_agent_apply_roundtrip_refusal "$CAPTURE_PLAN_CLAIMS" "$DEPLOY_RC" "$DEPLOY_OUT"
  printf '%s\n' "$DEPLOY_OUT"
  AGENT_DEPLOY_RC=0
  AGENT_DEPLOY_OUT=$(wp_conf2 wprism deploy --repo=/siterepo 2>&1) || AGENT_DEPLOY_RC=$?
  [ "$AGENT_DEPLOY_RC" -eq 1 ] \
    || fail 'agent-apply-roundtrip direct target deploy must refuse before lifecycle mutation'
  assert_no_php_runtime_diagnostics 'agent-apply-roundtrip direct deploy refusal' "$AGENT_DEPLOY_OUT"
  grep -Fq 'wprism: direct target deploy cannot run host-owned provider settlement' <<<"$AGENT_DEPLOY_OUT" \
    || fail 'agent-apply-roundtrip direct deploy did not preserve the host-owned provider boundary'
  if grep -qE '(^|[[:space:]])(activated:|deactivated:|theme switched:)' <<<"$AGENT_DEPLOY_OUT"; then
    fail 'agent-apply-roundtrip direct deploy reported lifecycle mutation before its provider-boundary refusal'
  fi
  printf '%s\n' "$AGENT_DEPLOY_OUT"
  fixture_reconcile_target_lifecycle "$CANON_ACTIVE" "$CANON_STYLESHEET"
  pass 'host and direct deploy boundaries refused; disposable native lifecycle setup completed for Apply evidence'
elif [ "$DEPLOY_RC" != "0" ]; then
  echo "$DEPLOY_OUT"
  fail "host wprism deploy failed on conf2 (exit $DEPLOY_RC, manifest: $MANIFEST) — conf2's plugin-files-only install was likely insufficient (missing plugin/theme code), or deploy hit a genuine code_mismatch; see output above"
else
  grep -q '^deploy complete:' <<<"$DEPLOY_OUT" \
  || fail "host wprism deploy returned success without its terminal product result: $DEPLOY_OUT"
  printf '%s\n' "$DEPLOY_OUT"
  pass "deploy succeeded on conf2"
fi
if [ "$MANIFEST" = woocommerce ]; then
  normalize_woocommerce_harness_placeholder_mode wp_conf2
  pass 'target WooCommerce placeholder is exact and safe after the cooperative test umask'
fi

if [ "$MODE" = agent-apply-roundtrip ]; then
  say "acceptance: conf2's fixture-established activation/theme state matches canonical"
else
  say "acceptance: conf2's activation/theme state matches canonical, from deploy alone"
fi
CONF2_ACTIVE=$(wp_conf2 plugin list --status=active --field=name | sort -u)
if [ "$CANON_ACTIVE" != "$CONF2_ACTIVE" ]; then
  echo "canonical active plugins (from conf1's capture): $CANON_ACTIVE"
  echo "conf2 active plugins (post-lifecycle):            $CONF2_ACTIVE"
  fail "conf2's active-plugin set does not match canonical after target lifecycle setup (manifest: $MANIFEST)"
fi
CONF2_TEMPLATE=$(wp_conf2 option get template)
CONF2_STYLESHEET=$(wp_conf2 option get stylesheet)
[ "$CANON_TEMPLATE" = "$CONF2_TEMPLATE" ] \
  || fail "conf2 template ('$CONF2_TEMPLATE') does not match canonical ('$CANON_TEMPLATE') after target lifecycle setup (manifest: $MANIFEST)"
[ "$CANON_STYLESHEET" = "$CONF2_STYLESHEET" ] \
  || fail "conf2 stylesheet ('$CONF2_STYLESHEET') does not match canonical ('$CANON_STYLESHEET') after target lifecycle setup (manifest: $MANIFEST)"
if [ "$MODE" = agent-apply-roundtrip ]; then
  pass "conf2 active plugins (${CANON_ACTIVE:-none}) and theme (template=$CONF2_TEMPLATE, stylesheet=$CONF2_STYLESHEET) match canonical after disposable native lifecycle setup"
else
  pass "conf2 active plugins (${CANON_ACTIVE:-none}) and theme (template=$CONF2_TEMPLATE, stylesheet=$CONF2_STYLESHEET) match canonical — deploy alone did this"
fi
# --- end deploy --------------------------------------------------------------

# Optional per-manifest post-lifecycle hook (conformance/postdeploy/<name>.sh,
# see this file's header comment for the full timing contract): conf2-only
# fixups that need the plugin genuinely ACTIVE, which only just became true
# through deploy or agent-apply-roundtrip's explicit fixture lifecycle.
# Runs strictly here — before apply — never folded into the seed (conf2 isn't
# active yet at seed time) and never left to apply (apply is content
# reconciliation, not a place to special-case one manifest's activation-hook
# side effects).
POSTDEPLOY=$(conformance_hook postdeploy.sh "conformance/postdeploy/$MANIFEST.sh")
if [ -f "$POSTDEPLOY" ]; then
  say "post-lifecycle conf2 fixup ($POSTDEPLOY)"
  bash "$POSTDEPLOY"
  pass "post-deploy fixup applied"
fi

# Setup hooks that need the plugin ACTIVE on conf2 run here, after lifecycle setup —
# never in install_env (role=target skipped this on purpose; see there).
case "$SETUP" in
  "") ;;
  hpos)
    # WooCommerce is active on conf2 now (deploy, just above). HPOS is a
    # feature FLAG, not activation state, so deploy (activation/theme only)
    # never owns it. Re-run Woo's idempotent new-shop lifecycle and verify
    # both the selected store and physical table. Must happen before `wprism apply` below:
    # apply is about to write order data, and it needs to land in whichever
    # storage backend HPOS selects — same reason conf1 needed it enabled
    # before its seed authored any orders.
    establish_woocommerce_hpos wp_conf2 \
      || fail "could not verify HPOS through WooCommerce's native lifecycle on conf2 (post-deploy)"
    ;;
  block-theme)
    # Nothing left to do — deploy's switch_theme() call above already put
    # twentytwentyfive live on conf2 (asserted above). conf1's install_env
    # still activates it pre-capture (role=author) so canonical carries it;
    # conf2 gets it FROM deploy, which is the point of this restructure.
    ;;
  *) fail "unknown setup hook '$SETUP' for manifest '$MANIFEST'" ;;
esac

say "apply conf2 (content only — activation/theme lifecycle completed above)"
# The entry's validated fixture policy selects adoption. Plugin names do not
# grant collision authority; a dirty typed-table fixture opts in explicitly.
capture_wprism_json_checked \
  APPLY_JSON \
  "conf2 wprism apply" \
  assert_wprism_apply_ready \
  conformance_private_command cli2 apply \
  wp_conf2 wprism apply --repo=/siterepo --adopt-by-slug="$ADOPT_BY_SLUG" \
  --default-author=admin --revision="$REV" --json
export APPLY_JSON
echo "$APPLY_JSON" | jq .
assert_wprism_apply_ready 'conf2 wprism apply' "$APPLY_JSON"
pass "apply succeeded, side-effect canary clean"

# Optional per-manifest post-apply hook. This is deliberately before the
# generic recapture: a manifest may manufacture target-local state solely to
# prove apply preserved it, but that witness is not source-authored canonical
# state and must be checked and removed before byte identity is measured.
POSTAPPLY=$(conformance_hook postapply.sh "conformance/postapply/$MANIFEST.sh")
if [ -f "$POSTAPPLY" ]; then
  say "post-apply target-local witness acceptance ($POSTAPPLY)"
  bash "$POSTAPPLY"
  pass "post-apply target-local witnesses proved and removed"
fi

say "acceptance: canonical(conf2) == canonical(conf1), byte for byte"
conformance_private_command cli2 capture wp_conf2 wprism capture --repo=/siterepo --out=/siterepo/.tmp-conf2state >/dev/null
diff -r "$R1"/state "$R2"/.tmp-conf2state || fail "round-trip mismatch between conf1 and conf2 for manifest '$MANIFEST'"
pass "canonical state identical across environments"

# Optional vector recording (WP-2.7). A FLAG on this harness, never a second
# harness: the four things a `wprism-conformance-vector/v1` document holds — the
# live rows, conf1's wprism_map, a wprism-adapter-probe/v1 read off this same pinned
# target, and both canonical trees — exist only here, only now, and only
# because the acceptance diff above just passed. Placed BEFORE the recapture is
# removed for that last reason: a recorder that re-derived the recapture would
# be recording a round trip nobody checked. Unset leaves every sweep
# byte-identical. docs/agents/live-pair-budget.md allocation order 4 spends one
# pair per vector; the replay is free forever after
# (sandbox/tests/offline/capture/regress_conformance_vector_replay.php).
if [ -n "${CONF_RECORD_VECTOR:-}" ]; then
  say "record a replayable conformance vector -> $CONF_RECORD_VECTOR"
  bash conformance/record-vector.sh \
    "$CONF_RECORD_VECTOR" "$MANIFEST" "$R1/state" "$R2/.tmp-conf2state" \
    || fail "could not record a conformance vector for '$MANIFEST'"
  pass "conformance vector recorded"
fi
rm -rf "$R2"/.tmp-conf2state

# Manifest-specific render-level acceptance (conformance/checks/<name>.sh,
# optional): byte-identical canonical state is necessary but not sufficient
# once a ref-shaped value is invisible to the tokenizer — source and target
# would then simply encode the same wrong bytes (the FSE exploration's
# core methodological finding). Checks curl the live conf2 site and grep
# rendered output, not state/, so they catch what a byte-diff cannot.
CHECK=$(conformance_hook check.sh "conformance/checks/$MANIFEST.sh")
if [ -f "$CHECK" ]; then
  say "manifest-specific render acceptance ($CHECK)"
  bash "$CHECK"
fi

conformance_target_cron_end
if [ "$MODE" = agent-roundtrip ]; then
  printf '\n\033[1;32m✔ AGENT ROUNDTRIP PASSED (%s; production promotion withheld)\033[0m\n' "$MANIFEST"
elif [ "$MODE" = agent-apply-roundtrip ]; then
  printf '\n\033[1;32m✔ AGENT APPLY ROUNDTRIP PASSED (%s; deployment unclaimed)\033[0m\n' "$MANIFEST"
else
  printf '\n\033[1;32m✔ CONFORMANCE PASSED (%s)\033[0m\n' "$MANIFEST"
fi
