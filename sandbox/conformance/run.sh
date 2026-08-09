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
#   conformance/checks/<name>.sh       conf2 only, after apply: render-level
#                                       acceptance a byte-diff can't see.
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
# Set CONFORMANCE_EVIDENCE_DIR to export conformance-<manifest>.{result,diff,
# fragment}.json for import by a certification-bundle assembler.
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

# DUO-3381: assert the premise before the behavior. A seed/postdeploy hook
# manufactures its fixture through `docker compose run` (wp_env, below), and
# under multi-agent host load that can hand back an EMPTY or noise-polluted
# --porcelain capture without a non-zero exit — `set -e` never fires, the
# fixture silently never lands, and the engine assertion that depends on it
# then fails for a reason that has nothing to do with the engine. Observed
# live 2026-08-09 (DUO-3380's first certification bundle, main 7938476):
# postdeploy/core.sh's ambiguous-adoption-key refusal legitimately did not
# fire and the sweep reported "duplicate full hierarchical adoption key was
# not rejected" — a false engine-regression scare plus a ~1h bundle restart;
# the identical sweep standalone passed. These three helpers are what a hook
# calls BETWEEN its manufacture and its engine assertion, so a fixture
# failure names ITSELF: every message they emit carries the grep-able
# "fixture manufacture failed:" prefix, which is an infrastructure signal,
# never an accusation against Duo. They only ever move a failure from the
# wrong domain into the right one — no engine assertion is weakened, and a
# hook whose fixture landed sees no behavior change at all.
require_fixture_ids() { # require_fixture_ids <VAR_NAME>... — each named var must hold a numeric id
  local name value
  for name in "$@"; do
    value="${!name-}"
    [[ "$value" =~ ^[0-9]+$ ]] \
      || fail "fixture manufacture failed: $name is not a numeric id (got: '${value:-<empty>}') — this hook's own fixture never landed, so nothing after it is testing the engine"
  done
}
require_fixture_values() { # require_fixture_values <VAR_NAME>... — each named var must be non-empty (ids that aren't numeric: uuids, slugs, hashes)
  local name
  for name in "$@"; do
    [ -n "${!name-}" ] \
      || fail "fixture manufacture failed: $name is empty — this hook's own fixture never landed, so nothing after it is testing the engine"
  done
}
require_fixture_state() { # require_fixture_state <what> <expected> <actual>
  [ "$2" = "$3" ] \
    || fail "fixture manufacture failed: $1 — expected '$2', got '${3:-<empty>}'"
}

# DUO-3391: the sibling failure domain, and the residual path DUO-3381
# deliberately did not cover. The three helpers above assert that a hook's own
# FIXTURE landed; this one asserts that the duo INVOCATION the hook then makes
# its assertion about actually happened. Every refusal assertion in this suite
# neutralizes that invocation's exit status on purpose — `|| RC=$?` so it can
# inspect the output, or `|| fail` so it can name the engine — which is
# exactly what disables `set -e` for it. So when `docker compose run` dies at
# the DOCKER layer (container creation refused, daemon saturated by parallel
# agents, image racing another pull), the hook still runs its assertion, over
# a capture that holds nothing but compose's own container-creation chatter:
# the refusal grep legitimately does not match and the sweep reports the
# ENGINE ("... was not rejected", with that chatter pasted in from $OUT) for a
# command that never reached the engine. That is what DUO-3380's archived $OUT
# pollution shows, at the same price DUO-3381 paid: a false engine-regression
# scare plus a ~1h certification-bundle restart.
#
# A hook calls this BETWEEN its duo invocation and its assertion about that
# invocation's output. Messages carry the grep-able "infrastructure failure:"
# prefix — a SIBLING of "fixture manufacture failed:" above, deliberately
# distinct because they name different domains (that one: this hook never
# built its premise; this one: this hook never got an answer). Neither is ever
# an accusation against Duo. No engine assertion is reworded or weakened, and
# an invocation that was answered sees no behavior change at all.
#
# The marker of "answered" is deliberately BROAD: wp-cli's own framing of any
# answer it gives (Success:/Error:/Warning:), duo's own `duo:` message prefix,
# or PHP's own fatal framing. A NARROW marker would be the dangerous one — it
# could demote a real but differently-worded engine failure into an
# infrastructure signal, i.e. weaken an engine assertion. Broad, the helper
# can only ever fire on the case it exists for: nothing came back from the
# containerized process at all.
require_duo_answered() { # require_duo_answered <what> <human|json> <captured output>
  local what="$1" mode="$2" out="$3" last
  case "$mode" in
    human)
      # 2>&1-merged human capture: wp-cli frames every answer it gives.
      grep -Eq '^(Success|Error|Warning): |(^|[[:space:]])duo:|^PHP [A-Z]|^Fatal error' <<<"$out" \
        || fail "infrastructure failure: $what was never answered — the capture carries no wp-cli Success:/Error:/Warning: line, no 'duo:' message, no PHP error, so this invocation died at the docker/compose layer and nothing after it is testing the engine: ${out:-<empty>}"
      ;;
    json)
      # --format=json capture: one JSON envelope on stdout, success summary
      # or duo-command-refusal/v1 alike, read exactly as the assertions do.
      last=$(awk 'NF { line=$0 } END { print line }' <<<"$out")
      jq -e 'type == "object"' >/dev/null 2>&1 <<<"$last" \
        || fail "infrastructure failure: $what was never answered — the capture's last non-empty line is not a JSON envelope, so this invocation died at the docker/compose layer and nothing after it is testing the engine: ${out:-<empty>}"
      ;;
    *)
      fail "require_duo_answered: unknown mode '$mode' (expected human|json)"
      ;;
  esac
}

ENTRY=$(jq -e --arg m "$MANIFEST" '.[$m]' "$REG") \
  || fail "unknown manifest '$MANIFEST' (see $REG)"
mapfile -t PLUGINS < <(echo "$ENTRY" | jq -r '.plugins[]?')
SETUP=$(echo "$ENTRY" | jq -r '.setup // ""')

# A caller that is assembling certification evidence can ask every manifest
# run to export the same named/importable bundle fragment. The full stdout /
# stderr log remains the caller's responsibility (the reference certifier
# captures it without hiding it from operators); this harness owns the
# machine verdict and clean-diff records because only it knows whether the
# complete deploy/apply/recapture path reached its final acceptance point.
EVIDENCE_DIR="${CONFORMANCE_EVIDENCE_DIR:-}"
EVIDENCE_COMPLETE=0
emit_conformance_evidence() {
  local original_rc=$? rc verdict reason status render result diff fragment tmp
  trap - EXIT
  [ -n "$EVIDENCE_DIR" ] || exit "$original_rc"

  set +e
  rc="$original_rc"
  verdict=fail
  reason=command_failed
  status=unknown
  if [ "$rc" -eq 0 ] && [ "$EVIDENCE_COMPLETE" -eq 1 ]; then
    verdict=pass
    reason=passed
    status=clean
  elif [ "$rc" -eq 0 ]; then
    rc=70
    reason=invalid_checker_output
  fi
  if [ -f "conformance/checks/$MANIFEST.sh" ]; then
    render=passed
  else
    render=not-declared
  fi

  mkdir -p -- "$EVIDENCE_DIR" || exit 70
  result="$EVIDENCE_DIR/conformance-$MANIFEST.result.json"
  diff="$EVIDENCE_DIR/conformance-$MANIFEST.diff.json"
  fragment="$EVIDENCE_DIR/conformance-$MANIFEST.fragment.json"
  tmp="${result}.tmp.$$"
  jq -n \
    --arg test "conformance-$MANIFEST" --arg verdict "$verdict" --arg reason "$reason" \
    --argjson exit_code "$rc" \
    '{schema_version:1,test:$test,verdict:$verdict,exit_code:$exit_code,reason:$reason,
      assertions:["lint_json_valid","capture_twice_identical","deploy_activation_state","apply_canary_clean","cross_environment_recapture_identical","render_api_checks"]}' \
    > "$tmp" && mv -- "$tmp" "$result" || exit 70
  tmp="${diff}.tmp.$$"
  jq -n --arg status "$status" --arg manifest "$MANIFEST" --arg render_check "$render" \
    '{status:$status,manifest:$manifest,diffs:["capture-twice","conf1-vs-conf2-recapture"],render_check:$render_check}' \
    > "$tmp" && mv -- "$tmp" "$diff" || exit 70
  tmp="${fragment}.tmp.$$"
  jq -n --arg id "conformance-$MANIFEST" --arg manifest "$MANIFEST" \
    --arg result "$result" --arg diff "$diff" \
    '{id:$id,manifest:$manifest,result:$result,diff:$diff}' \
    > "$tmp" && mv -- "$tmp" "$fragment" || exit 70
  exit "$rc"
}
trap emit_conformance_evidence EXIT

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
COMPOSE="docker compose -p duo-${CONF_PAIR} -f pair.yml -f pair.http.yml"
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
  require_duo_answered

# DUO-3377: a sweep IS evidence, so it must be able to state which
# agent/manifests bytes produced it. CONF_EXPECTED_SOURCE_SHA=$(git rev-parse
# HEAD) binds this run to that exact commit: pair.sh's own gate then refuses
# below — before `reset` DROP/CREATEs either database and before any container
# starts — unless the source it is about to mount is that commit, clean (see
# pair.sh's assert_candidate_source for why the mounted source is NOT this
# checkout when run.sh is launched from a linked worktree). Exported rather
# than passed as an argument for the same process-boundary reason DUO_PAIR/
# DUO_PORT1/DUO_PORT2 are exported above: pair.sh is a subprocess here, and
# `reset` accepts no flags at all. Unset leaves every sweep byte-identical.
if [ -n "${CONF_EXPECTED_SOURCE_SHA:-}" ]; then
  export DUO_EXPECTED_SOURCE_SHA="$CONF_EXPECTED_SOURCE_SHA"
fi

say "clean-room via pair.sh (DROP/CREATE beats volume rm + InnoDB re-init — conformance never trusts leftover state from a previous manifest's run)"
bash bin/pair.sh reset "$CONF_PAIR"

say "pair.sh up: boot conf1 (:$CONF1_PORT) / conf2 (:$CONF2_PORT), DB-level readiness, generic WordPress bootstrap"
bash bin/pair.sh up "$CONF_PAIR" "$CONF1_PORT" "$CONF2_PORT" --http

# DUO-3242: derive a guard-safe SLUG from a manifest's plugins[] entry.
# `wp plugin is-active`/`is-installed` (install_env's own guard, below)
# both expect a slug (the wp-content/plugins/ directory name) — given a
# raw URL (paid-memberships-pro's own manifest entry, the first of this
# shape — DUO-3239), both always report "not found" regardless of actual
# state, so the guard never short-circuits for a URL-sourced plugin, and a
# second `install` call on files already present from an earlier
# manifest's run on this SAME (reset-but-not-destroyed) pair hard-fails:
# confirmed live — "Warning: Destination folder already exists" / "Plugin
# installation failed." / "Error: No plugins installed." (exit 1), NOT the
# graceful "Plugin already installed." warning a same-slug re-install
# produces (also confirmed live, side by side — the two are genuinely
# different wp-cli code paths, not a memory error). wp-cli's own
# GitHub-archive install path (every URL this project currently ships — a
# .../<repo>/archive/refs/{tags,heads}/<ref>.zip download) unpacks to
# `<repo>-<ref>/` then renames to the bare `<repo>` directory (confirmed
# live: "Renamed Github-based project from 'paid-memberships-pro-3.8.3' to
# 'paid-memberships-pro'") — so the repo name, one path segment before
# "archive", IS the eventual slug.
#
# Returns empty for any URL shape that doesn't match — not a guess dressed
# up as an answer. install_env()'s own fallback for that case (below)
# stays CORRECT either way, at the cost of an unconditional --force
# re-fetch instead of a cheap guard check: confirmed live that --force
# succeeds identically whether the destination already exists (updates in
# place) or doesn't (installs fresh) — unlike a bare re-attempted
# `install`, which is only safe for a KNOWN-matching slug.
plugin_slug() { # plugin_slug <plugin-identifier> -> slug, or empty if unknown
  local id="$1"
  case "$id" in
    http://*|https://*)
      if [[ "$id" =~ /([^/]+)/archive/ ]]; then
        printf '%s' "${BASH_REMATCH[1]}"
      fi
      ;;
    *)
      printf '%s' "$id"   # already a plain wp.org slug (or slug/file.php) — unchanged
      ;;
  esac
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
  local env="$1" role="$2"
  wp_env "$env" option update blogname "Duo ${env} (${MANIFEST})"
  wp_env "$env" site empty --yes
  if [ "${#PLUGINS[@]}" -gt 0 ]; then
    for plugin in "${PLUGINS[@]}"; do
      slug=$(plugin_slug "$plugin")
      # DUO-3242: a URL-sourced install call needs --force regardless of
      # whether plugin_slug() above could derive a slug. is-active/
      # is-installed (the guard just below) correctly gate WHETHER to call
      # `install` at all once a slug is known — but activation/installation
      # state is a DIFFERENT question from whether the URL's own download
      # destination already exists on disk from an earlier manifest's run
      # on this pair. Confirmed live: even when the guard correctly finds
      # "not active" (a fresh database has no activation record — see the
      # role=author comment below) and the derived slug is exactly right,
      # a plain, non-forced `install` on an existing destination still
      # hard-fails for a URL. A plain slug never has this problem (`wp
      # plugin install <slug>` gracefully warns "already installed" and
      # proceeds), so --force is scoped to URL-shaped entries only —
      # unnecessary weight on the common, already-working case.
      case "$plugin" in
        http://*|https://*) force=--force ;;
        *) force= ;;
      esac
      if [ "$role" = author ]; then
        # is-active, not is-installed: pair.sh's reset deliberately leaves the
        # webroot volume alone (only the database is DROP/CREATE'd — that's
        # the whole reset-speed win), so a plugin's FILES can persist from an
        # earlier manifest's run on this same pair while the freshly-reset
        # database has no record of it being active. is-installed (files on
        # disk) would short-circuit past `install --activate` entirely in
        # that case — confirmed the hard way: `install --activate` DOES
        # activate an already-present-but-inactive plugin fine when actually
        # invoked (it's not a no-op), the bug was this guard never calling it.
        if [ -z "$slug" ] || ! wp_env "$env" plugin is-active "$slug" >/dev/null 2>&1; then
          wp_env "$env" plugin install "$plugin" --activate $force
        fi
      else
        # role=target: files only, deliberately never --activate — `wp duo
        # deploy` (below, once conf2 has its clone) is what activates this
        # FOR REAL, from canonical. Same cross-manifest-run persistence
        # caveat as the author branch above, just guarding on the thing
        # this branch actually needs (files on disk), not activation state.
        if [ -z "$slug" ] || ! wp_env "$env" plugin is-installed "$slug" >/dev/null 2>&1; then
          wp_env "$env" plugin install "$plugin" $force
        fi
      fi
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
  echo "env $env installed, role=$role ($MANIFEST: ${PLUGINS[*]:-no plugins}${SETUP:+, setup=$SETUP})"
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
# Generalized suspicious-ref linter (agent/src/Lint.php / `wp duo lint`):
# flags ref-shaped values that reached canonical state without a declared
# rewrite path — exactly the blind spot the byte-diff acceptance checks
# below cannot see (docs/frontier/{fse,polylang,elementor}.md). HARD GATE:
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
APPLY_JSON=$(wp_conf2 duo apply --repo=/siterepo --adopt-by-slug=terms,posts --default-author=admin --revision="$REV" --json | tail -1)
echo "$APPLY_JSON" | jq .
[ "$(echo "$APPLY_JSON" | jq -r '.canary')" = "clean" ] || fail "side-effect canary was not clean during apply"
pass "apply succeeded, side-effect canary clean"

say "acceptance: canonical(conf2) == canonical(conf1), byte for byte"
wp_conf2 duo capture --repo=/siterepo --out=/siterepo/.tmp-conf2state >/dev/null
diff -r "$R1"/state "$R2"/.tmp-conf2state || fail "round-trip mismatch between conf1 and conf2 for manifest '$MANIFEST'"
rm -rf "$R2"/.tmp-conf2state
pass "canonical state identical across environments"

# Manifest-specific render-level acceptance (conformance/checks/<name>.sh,
# optional): byte-identical canonical state is necessary but not sufficient
# once a ref-shaped value is invisible to the tokenizer — source and target
# would then simply encode the same wrong bytes (docs/frontier/fse.md's
# core methodological finding). Checks curl the live conf2 site and grep
# rendered output, not state/, so they catch what a byte-diff cannot.
CHECK="conformance/checks/$MANIFEST.sh"
if [ -f "$CHECK" ]; then
  say "manifest-specific render acceptance (conformance/checks/$MANIFEST.sh)"
  bash "$CHECK"
fi

EVIDENCE_COMPLETE=1
printf '\n\033[1;32m✔ CONFORMANCE PASSED (%s)\033[0m\n' "$MANIFEST"
