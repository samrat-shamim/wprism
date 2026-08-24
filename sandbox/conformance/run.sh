#!/usr/bin/env bash
# Conformance gate (DESIGN.md §6 / adversarial-review finding #20 — the
# manifest-treadmill answer): a generalized capture -> apply -> re-capture
# round-trip harness, run per manifest against a FRESH, disposable env pair.
# This is what CI runs; it knows nothing manifest-specific beyond what's
# declared in conformance/manifests.json and three optional per-manifest
# hook files, each invoked at a fixed point in the flow below IF PRESENT —
# this file never inspects what any of them actually do:
#   conformance/seeds/<name>.sh        conf1 only, before capture: author
#                                       the manifest's representative content.
#   conformance/postdeploy/<name>.sh   conf2 only, strictly after `wp duo
#                                       deploy` and strictly before `duo
#                                       apply`: fixups that need conf2's
#                                       plugin genuinely ACTIVE, which only
#                                       becomes true once deploy runs (conf2
#                                       arrives at the seed step with plugin
#                                       FILES only — install_env's
#                                       role=target). Motivating case (task
#                                       DUO-3223): a plugin's OWN activation
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
# Entries default to `mode: roundtrip`. `mode: capture-plan` is the bounded
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
# Usage: bash sandbox/conformance/run.sh <manifest-name>
# Set CONF_EXPECTED_SOURCE_SHA=$(git rev-parse HEAD) to bind the sweep to an
# exact agent/manifests commit (DUO-3377's gate — see below, before reset).
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
MANIFEST="${1:-}"
REG=conformance/manifests.json
[ -n "$MANIFEST" ] || { echo "usage: run.sh <manifest-name> (see $REG for known names)" >&2; exit 1; }

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
# (require_fixture_ids/values/state, require_duo_answered) live in the shared
# fragment: TWO harnesses source those hooks (this one and
# sandbox/tests/certify/certify_version_matrix.sh), and DUO-3408 is what happens when
# a helper reaches only one of them. Full doctrine in the fragment itself.
. conformance/asserts.sh

# A scoped certificate may bind a single extracted fixture entry instead of
# the all-adapter fixture index.  The harness remains generic: it accepts the
# same entry shape and never learns a plugin name.  Keeping the fixture input
# singular is what lets an unrelated adapter-fixture edit stay out of one
# adapter's conservative evidence closure.
if [ -n "${CONFORMANCE_ENTRY_FILE:-}" ]; then
  [ -f "$CONFORMANCE_ENTRY_FILE" ] \
    || fail "CONFORMANCE_ENTRY_FILE is not a regular fixture entry: $CONFORMANCE_ENTRY_FILE"
  ENTRY=$(jq -ce --arg manifest "$MANIFEST" '
    if (keys | sort) == ["entry", "manifest"] and .manifest == $manifest and (.entry | type) == "object"
    then .entry else error("entry must name the requested manifest") end
  ' "$CONFORMANCE_ENTRY_FILE") \
    || fail "CONFORMANCE_ENTRY_FILE must contain one exact named fixture entry for '$MANIFEST'"
else
  ENTRY=$(jq -e --arg m "$MANIFEST" '.[$m]' "$REG") \
    || fail "unknown manifest '$MANIFEST' (see $REG)"
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
MODE=$(echo "$ENTRY" | jq -r '.mode // "roundtrip"')
case "$MODE" in
  roundtrip|capture-plan) ;;
  *) fail "unknown conformance mode '$MODE' for manifest '$MANIFEST' (expected roundtrip|capture-plan)" ;;
esac

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
  if docker ps --format '{{.Names}}' | grep -qE 'duo-sandbox-wp-conf[12]-1'; then
    CONF1_PORT=8840
    CONF2_PORT=8841
    echo "note: legacy conf1/conf2 containers are still running — using fallback ports $CONF1_PORT/$CONF2_PORT instead of 8806/8807" >&2
  else
    CONF1_PORT="${CONF1_PORT:-8806}"
    CONF2_PORT="${CONF2_PORT:-8807}"
  fi
fi
WORDPRESS_OFFLINE="${DUO_WORDPRESS_ORG_OFFLINE:-0}"
case "$WORDPRESS_OFFLINE" in
  0|1) ;;
  *) fail "DUO_WORDPRESS_ORG_OFFLINE must be 0 or 1" ;;
esac
export DUO_ARTIFACT_OFFLINE="$WORDPRESS_OFFLINE"
COMPOSE="docker compose -p duo-${CONF_PAIR} -f pair.yml -f pair.http.yml -f pair.artifacts.yml"
PAIR_COMPOSE=(docker compose -p "duo-${CONF_PAIR}" -f pair.yml -f pair.http.yml -f pair.artifacts.yml)
PAIR_UP_FLAGS=(--http --artifacts)
if [ "$WORDPRESS_OFFLINE" = 1 ]; then
  COMPOSE="$COMPOSE -f pair.wordpress-offline.yml"
  PAIR_COMPOSE+=(-f pair.wordpress-offline.yml)
  PAIR_UP_FLAGS+=(--wordpress-offline)
fi
# shellcheck source=../bin/fetch-artifact.sh
. bin/fetch-artifact.sh
validate_artifact_lock conformance/artifacts.lock.json \
  || fail "artifact lock is malformed; conformance refused before pair reset"
wp_env() { # wp_env <conf1|conf2> <wp args...>
  local env="$1"; shift
  local side="${env#conf}"   # conf1 -> 1, conf2 -> 2 (pair.sh's generic side numbering)
  # The disposable site repo is jointly managed by host-side Git and the
  # container's uid-33 wp-cli process. Give files created by conformance wp
  # commands a cooperative umask so the host can diff/remove capture output
  # on native Linux bind mounts. This wraps only the test harness; Duo's
  # production process umask and permission policy remain untouched.
  $COMPOSE run --rm -T "cli${side}" sh -c 'umask 000; exec wp "$@"' sh "$@"
}
wp_conf1() { wp_env conf1 "$@"; }
wp_conf2() { wp_env conf2 "$@"; }
# pair.sh set these for ITS OWN compose invocations while bringing the pair
# up, but that was a separate process — its exports die with it. Every one
# of run.sh's own $COMPOSE calls below creates a fresh --rm container
# (never a persistent one), so pair.yml's ${DUO_PAIR}/${DUO_PORT1}/
# ${DUO_PORT2} interpolation (WORDPRESS_DB_NAME among them) needs these set
# in THIS shell too, every time — confirmed the hard way: without this,
# WORDPRESS_DB_NAME silently resolved to "wp_1" (DUO_PAIR defaulting to an
# empty string) instead of "wp_conf1", surfacing only as a generic "Error
# establishing a database connection" from wp-cli, not a missing-variable
# warning that would have pointed straight at the cause.
export DUO_PAIR="$CONF_PAIR" DUO_PORT1="$CONF1_PORT" DUO_PORT2="$CONF2_PORT"
export COMPOSE CONF1_PORT CONF2_PORT
export -f wp_env wp_conf1 wp_conf2 say pass fail \
  require_fixture_ids require_fixture_values require_fixture_state \
  require_duo_answered capture_duo_json_success require_observed_nonempty

# DUO-3377: a sweep IS evidence, so it must be able to state which
# agent/manifests bytes produced it. CONF_EXPECTED_SOURCE_SHA=$(git rev-parse
# HEAD) binds this run to that exact commit: pair.sh's own gate then refuses
# below — before `reset` DROP/CREATEs either database and before any container
# starts — unless the source it is about to mount is that commit, clean (see
# pair.sh's assert_candidate_source for the canonical default and the explicit
# DUO_SOURCE_ROOT worktree override). Exported rather
# than passed as an argument for the same process-boundary reason DUO_PAIR/
# DUO_PORT1/DUO_PORT2 are exported above: pair.sh is a subprocess here, and
# `reset` accepts no flags at all. Unset leaves every sweep byte-identical.
if [ -n "${CONF_EXPECTED_SOURCE_SHA:-}" ]; then
  export DUO_EXPECTED_SOURCE_SHA="$CONF_EXPECTED_SOURCE_SHA"
fi

say "clean-room via pair.sh (DROP/CREATE beats volume rm + InnoDB re-init — conformance never trusts leftover state from a previous manifest's run)"
bash bin/pair.sh reset "$CONF_PAIR"

say "pair.sh up: boot conf1 (:$CONF1_PORT) / conf2 (:$CONF2_PORT), DB-level readiness, generic WordPress bootstrap"
bash bin/pair.sh up "$CONF_PAIR" "$CONF1_PORT" "$CONF2_PORT" "${PAIR_UP_FLAGS[@]}"

normalize_archive_root() { # normalize_archive_root <env> <plugin|theme> <slug> <archive-root>
  local env="$1" kind="$2" slug="$3" archive_root="$4" side="${1#conf}" base
  [ "$archive_root" != "$slug" ] || return 0
  case "$kind" in
    plugin) base=/var/www/html/wp-content/plugins ;;
    theme) base=/var/www/html/wp-content/themes ;;
    *) fail "invalid extension kind for archive-root normalization" ;;
  esac
  $COMPOSE run --rm -T "cli${side}" sh /duo-harness/artifact-archive-root.sh \
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
  # role=target (conf2) is the actual promotion target `wp duo deploy`
  # reconciles later (see the "deploy conf2" step below, after the clone):
  # plugin FILES only, no --activate, no setup hook. Pre-activating conf2
  # here too (the old behavior, before this restructure) made `duo
  # apply`'s job artificially easy — activation state already matched
  # canonical before deploy ever ran, so conformance never actually
  # exercised deploy's reconciliation. This is bug #2's fix.
  local env="$1" role="$2" side="${1#conf}" spec slug version artifact archive_root installed_version
  wp_env "$env" option update blogname "Duo ${env} (${MANIFEST})"
  wp_env "$env" site empty --yes
  if [ "${#PLUGINS[@]}" -gt 0 ]; then
    for spec in "${PLUGINS[@]}"; do
      slug=$(jq -r '.slug' <<<"$spec")
      version=$(jq -r '.version' <<<"$spec")
      archive_root=$(jq -r --arg slug "$slug" --arg version "$version" \
        '.plugins[$slug][$version].archive_root // $slug' conformance/artifacts.lock.json)
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
      archive_root=$(jq -r --arg slug "$slug" --arg version "$version" \
        '.themes[$slug][$version].archive_root // $slug' conformance/artifacts.lock.json)
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
      hpos) wp_env "$env" wc hpos enable || fail "could not enable HPOS on $env" ;;
      block-theme) wp_env "$env" theme activate twentytwentyfive || fail "could not activate twentytwentyfive on $env" ;;
      *) fail "unknown setup hook '$SETUP' for manifest '$MANIFEST'" ;;
    esac
  fi
  echo "env $env installed, role=$role ($MANIFEST: ${#PLUGINS[@]} pinned plugins, ${#THEMES[@]} pinned themes${SETUP:+, setup=$SETUP})"
}
install_env conf1 author
install_env conf2 target
pass "conf1 fully authored (activated + setup); conf2 has plugin files only — deploy (below) reconciles the rest"

say "init the site repo (own origin, own clones — pins: $(echo "$ENTRY" | jq -c '.pin'))"
git init --bare -b main "$ORIGIN" >/dev/null
echo "$ENTRY" | jq '{
  manifests: .pin,
  policy: {options: {}, post_meta: {}, post_types: .post_types, taxonomies: .taxonomies},
  spec_version: 2
}' > "$R1"/site.duo.json
cp site-repo.gitignore.template "$R1"/.gitignore
git -C "$R1" init -q -b main
git -C "$R1" remote add origin "../origin-${CONF_PAIR}.git"

say "seed representative authored content on conf1 (conformance/seeds/$MANIFEST.sh)"
SEED="conformance/seeds/$MANIFEST.sh"
[ -f "$SEED" ] || fail "no seed script for '$MANIFEST' (expected $SEED)"
bash "$SEED"

say "capture conf1 into the site repo"
wp_conf1 duo capture --repo=/siterepo
git -C "$R1" add -A
git -C "$R1" -c user.name=duo -c user.email=duo@example.test commit -qm "capture: seeded $MANIFEST content on conf1"
git -C "$R1" push -qu origin main

# --- suspicious-ref lint gate ------------------------------------------------
# Generalized suspicious-ref linter (agent/src/Review/Lint.php / `wp duo lint`):
# flags ref-shaped values that reached canonical state without a declared
# rewrite path — exactly the blind spot the byte-diff acceptance checks
# below cannot see (the FSE, Polylang and Elementor frontier explorations
# each hit it). HARD GATE:
# all in-tree manifests run clean against it; a finding here means either a
# manifest gap or a genuinely dangling/unrewritten ref — both are failures.
say "lint conf1's captured state (hard gate)"
# `wp duo lint --format=json` exits 1 when it HAS findings (Cli.php's
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
LINT_OUT=$(wp_conf1 duo lint --repo=/siterepo --format=json) || LINT_RC=$?
LINT_JSON=$(printf '%s\n' "$LINT_OUT" | tail -1)
if ! printf '%s\n' "$LINT_JSON" | jq -e 'type == "array"' >/dev/null 2>&1; then
  printf '%s\n' "$LINT_OUT"
  fail "wp duo lint crashed or produced malformed output (exit $LINT_RC, manifest: $MANIFEST) — expected a JSON array as the last line of output; raw output above"
fi
LINT_N=$(printf '%s\n' "$LINT_JSON" | jq 'length')
if [ "$LINT_RC" != "0" ] && [ "$LINT_N" = "0" ]; then
  printf '%s\n' "$LINT_OUT"
  fail "wp duo lint exited $LINT_RC but its own output claims 0 findings (manifest: $MANIFEST) — inconsistent, treating as a crash rather than trusting it"
fi
if [ "$LINT_N" != "0" ]; then
  echo "$LINT_JSON" | jq .
  fail "wp duo lint found $LINT_N suspicious ref(s) in captured state (manifest: $MANIFEST)"
fi
echo "lint: clean, 0 findings"
# --- end lint gate -----------------------------------------------------------

say "acceptance: capture is deterministic (capture twice, zero diff)"
wp_conf1 duo capture --repo=/siterepo --out=/siterepo/.tmp-state2 >/dev/null
diff -r "$R1"/state "$R1"/.tmp-state2 || fail "capture is not deterministic"
rm -rf "$R1"/.tmp-state2
pass "capture-twice diff is empty"

# A capture-plan profile makes claims only about the source-side capture,
# compilation, plan, and recapture paths. Its hook verifies plugin APIs and
# the canonical bytes those operations produced before this harness decides
# whether the target-facing round-trip is in scope.
CAPTURE_CHECK="conformance/capture-checks/$MANIFEST.sh"
if [ -f "$CAPTURE_CHECK" ]; then
  say "manifest-specific capture acceptance (conformance/capture-checks/$MANIFEST.sh)"
  bash "$CAPTURE_CHECK"
fi

if [ "$MODE" = "capture-plan" ]; then
  say "capture-plan acceptance: reviewed operations are reachable without claiming apply"
  CAPABILITY_JSON=$(wp_conf1 duo capabilities --repo=/siterepo --operation=capture --format=json | awk 'NF { line=$0 } END { print line }')
  require_duo_answered "conf1 duo capabilities --operation=capture" json "$CAPABILITY_JSON"
  printf '%s\n' "$CAPABILITY_JSON" | jq -e '
    (.manifests | type == "array" and length > 0) and
    all(.manifests[]; (.operations | index("capture")) != null)
  ' >/dev/null || fail "capture capability report does not expose capture for every pinned adapter"

  PLAN_JSON=$(wp_conf1 duo plan --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
  require_duo_answered "conf1 duo plan after deterministic capture" json "$PLAN_JSON"
  printf '%s\n' "$PLAN_JSON" | jq -e '
    type == "object" and
    (.create | type == "array") and
    (.update | type == "array") and
    (.conflict | type == "array")
  ' >/dev/null || fail "capture-plan profile did not reach the real structured plan result"
  pass "capture, compile, plan, and recapture paths are exercised; deploy/apply remain explicitly outside this profile"
  printf '\n\033[1;32m✔ CONFORMANCE PASSED (%s; capture-plan)\033[0m\n' "$MANIFEST"
  exit 0
fi

say "clone the repo for conf2"
git clone -q "$ORIGIN" "$R2"
REV=$(git -C "$R2" rev-parse HEAD)

# --- deploy conf2 from canonical --------------------------------------------
# The real promotion path this harness used to skip entirely (spec/
# repo-format.md's "Code-half facts & deploy"): conf2 arrived above with
# plugin FILES only, no activation, no setup hook (install_env's
# role=target) — this is the step that actually reconciles activation/
# theme state from canonical, via real activate_plugin()/switch_theme()
# calls (Deploy.php), deliberately outside `duo apply`'s hook-free canary.
# Must run after conf2's clone (it reads canonical from THIS environment's
# /siterepo, pair.yml mounts "$R2" there — not conf1's) and
# before `duo apply` (spec ordering: deploy code -> reconcile activation ->
# migrations fire as an activation side effect -> THEN apply state).
say "deploy conf2 from canonical (wp duo deploy) — the real promotion path"
DEPLOY_RC=0
DEPLOY_OUT=$(wp_conf2 duo deploy --repo=/siterepo --format=json) || DEPLOY_RC=$?
if [ "$DEPLOY_RC" != "0" ]; then
  echo "$DEPLOY_OUT"
  fail "wp duo deploy failed on conf2 (exit $DEPLOY_RC, manifest: $MANIFEST) — conf2's plugin-files-only install was likely insufficient (missing plugin/theme code), or deploy hit a genuine code_mismatch; see output above"
fi
echo "$DEPLOY_OUT" | jq .
pass "deploy succeeded on conf2"

say "acceptance: conf2's activation/theme state matches canonical, from deploy alone"
CANON_ACTIVE=$(jq -r '.records.active_plugins.value[]? | split("/")[0]' "$R1"/state/options/core.json | sort -u)
CONF2_ACTIVE=$(wp_conf2 plugin list --status=active --field=name | sort -u)
if [ "$CANON_ACTIVE" != "$CONF2_ACTIVE" ]; then
  echo "canonical active plugins (from conf1's capture): $CANON_ACTIVE"
  echo "conf2 active plugins (post-deploy):               $CONF2_ACTIVE"
  fail "conf2's active-plugin set does not match canonical after deploy (manifest: $MANIFEST)"
fi
CANON_TEMPLATE=$(jq -r '.records.template.value // empty' "$R1"/state/options/core.json)
CANON_STYLESHEET=$(jq -r '.records.stylesheet.value // empty' "$R1"/state/options/core.json)
CONF2_TEMPLATE=$(wp_conf2 option get template)
CONF2_STYLESHEET=$(wp_conf2 option get stylesheet)
[ "$CANON_TEMPLATE" = "$CONF2_TEMPLATE" ] \
  || fail "conf2 template ('$CONF2_TEMPLATE') does not match canonical ('$CANON_TEMPLATE') after deploy (manifest: $MANIFEST)"
[ "$CANON_STYLESHEET" = "$CONF2_STYLESHEET" ] \
  || fail "conf2 stylesheet ('$CONF2_STYLESHEET') does not match canonical ('$CANON_STYLESHEET') after deploy (manifest: $MANIFEST)"
pass "conf2 active plugins (${CANON_ACTIVE:-none}) and theme (template=$CONF2_TEMPLATE, stylesheet=$CONF2_STYLESHEET) match canonical — deploy alone did this"
# --- end deploy --------------------------------------------------------------

# Optional per-manifest post-deploy hook (conformance/postdeploy/<name>.sh,
# see this file's header comment for the full timing contract): conf2-only
# fixups that need the plugin genuinely ACTIVE, which only just became true.
# Runs strictly here — after deploy, before apply — never folded into the
# seed (conf2 isn't active yet at seed time) and never left to apply (apply
# is content reconciliation, not a place to special-case one manifest's
# activation-hook side effects).
POSTDEPLOY="conformance/postdeploy/$MANIFEST.sh"
if [ -f "$POSTDEPLOY" ]; then
  say "post-deploy conf2 fixup (conformance/postdeploy/$MANIFEST.sh)"
  bash "$POSTDEPLOY"
  pass "post-deploy fixup applied"
fi

# Setup hooks that need the plugin ACTIVE on conf2 run here, after deploy —
# never in install_env (role=target skipped this on purpose; see there).
case "$SETUP" in
  "") ;;
  hpos)
    # WooCommerce is active on conf2 now (deploy, just above). HPOS is a
    # feature FLAG, not activation state, so deploy (activation/theme only)
    # never touches it — enable it explicitly, the same call conf1's
    # install_env made pre-capture. Must happen before `duo apply` below:
    # apply is about to write order data, and it needs to land in whichever
    # storage backend HPOS selects — same reason conf1 needed it enabled
    # before its seed authored any orders.
    wp_conf2 wc hpos enable || fail "could not enable HPOS on conf2 (post-deploy)"
    ;;
  block-theme)
    # Nothing left to do — deploy's switch_theme() call above already put
    # twentytwentyfive live on conf2 (asserted above). conf1's install_env
    # still activates it pre-capture (role=author) so canonical carries it;
    # conf2 gets it FROM deploy, which is the point of this restructure.
    ;;
  *) fail "unknown setup hook '$SETUP' for manifest '$MANIFEST'" ;;
esac

say "apply conf2 (content only — activation/theme were deploy's job, above)"
# adopt both terms (the default "Uncategorized" category every fresh install
# has) and posts (plugins like WooCommerce auto-create their own default
# pages — Shop/Cart/Checkout/... — on activation, independently on conf1 and
# conf2, so first apply always meets an unmanaged same-slug row for those).
# Core's dirty-target matrix and Polylang's per-language menu matrix each
# manufacture an exact nav-menu collision. Menu adoption is explicit only for
# those adapters so unrelated entries do not gain broader collision authority.
ADOPT_BY_SLUG=terms,posts
if [ "$MANIFEST" = core ] || [ "$MANIFEST" = polylang ]; then
  ADOPT_BY_SLUG=terms,posts,menus
fi
capture_duo_json_success \
  APPLY_JSON \
  "conf2 duo apply" \
  wp_conf2 duo apply --repo=/siterepo --adopt-by-slug="$ADOPT_BY_SLUG" \
  --default-author=admin --revision="$REV" --json
export APPLY_JSON
echo "$APPLY_JSON" | jq .
[ "$(echo "$APPLY_JSON" | jq -r '.canary')" = "clean" ] || fail "side-effect canary was not clean during apply"
pass "apply succeeded, side-effect canary clean"

# Optional per-manifest post-apply hook. This is deliberately before the
# generic recapture: a manifest may manufacture target-local state solely to
# prove apply preserved it, but that witness is not source-authored canonical
# state and must be checked and removed before byte identity is measured.
POSTAPPLY="conformance/postapply/$MANIFEST.sh"
if [ -f "$POSTAPPLY" ]; then
  say "post-apply target-local witness acceptance (conformance/postapply/$MANIFEST.sh)"
  bash "$POSTAPPLY"
  pass "post-apply target-local witnesses proved and removed"
fi

say "acceptance: canonical(conf2) == canonical(conf1), byte for byte"
wp_conf2 duo capture --repo=/siterepo --out=/siterepo/.tmp-conf2state >/dev/null
diff -r "$R1"/state "$R2"/.tmp-conf2state || fail "round-trip mismatch between conf1 and conf2 for manifest '$MANIFEST'"
rm -rf "$R2"/.tmp-conf2state
pass "canonical state identical across environments"

# Manifest-specific render-level acceptance (conformance/checks/<name>.sh,
# optional): byte-identical canonical state is necessary but not sufficient
# once a ref-shaped value is invisible to the tokenizer — source and target
# would then simply encode the same wrong bytes (the FSE exploration's
# core methodological finding). Checks curl the live conf2 site and grep
# rendered output, not state/, so they catch what a byte-diff cannot.
CHECK="conformance/checks/$MANIFEST.sh"
if [ -f "$CHECK" ]; then
  say "manifest-specific render acceptance (conformance/checks/$MANIFEST.sh)"
  bash "$CHECK"
fi

printf '\n\033[1;32m✔ CONFORMANCE PASSED (%s)\033[0m\n' "$MANIFEST"
