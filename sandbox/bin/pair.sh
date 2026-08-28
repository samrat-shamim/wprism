#!/usr/bin/env bash
# pair.sh — lifecycle tool for the sandbox redesign (task #74): one
# parameterized pair template (sandbox/pair.yml) against one shared MariaDB
# (sandbox/db.yml), instead of sandbox/docker-compose.yml's ~10 hand-
# duplicated pair profiles each carrying their own dedicated MariaDB.
#
# Subcommands:
#   pair.sh up <name> <port1> <port2> [--journal] [--codebind <plugin-dir>] [--artifacts] [--wordpress-offline] [--git-cli] [--http|--headless]
#   pair.sh reset <name>
#   pair.sh repo-host <name> [1|2|both]
#   pair.sh destroy <name>
#   pair.sh list
#
# Run with `bash sandbox/bin/pair.sh ...` (this repo's shell is zsh; every
# script here is bash and is always invoked that way — see Makefile).
#
# Design notes worth knowing before reading further:
#
# - No per-pair MariaDB. Every pair's two databases (wp_<name>1/wp_<name>2)
#   live on the ONE server sandbox/db.yml brings up (its own compose
#   project, duo-db). A clean-room reset is DROP DATABASE + CREATE DATABASE
#   against an already-warm server — no InnoDB re-init from an empty
#   datadir, which is what made the old per-pair-volume reset slow.
#
# - Readiness is checked at the DB level, deliberately NOT via
#   `wp core version` (the pattern sandbox/setup.sh, sandbox/conformance/
#   run.sh, and the spike scripts all use today). `wp core version` reads a
#   static PHP file — it never touches the database — so it reports "ready"
#   before the database is actually reachable. This file waits on the
#   shared server's own healthcheck (--connect --innodb_initialized) and
#   then, per pair, on `wp db query "SELECT 1"` actually succeeding through
#   that pair's own cli container — the same dependency chain a real
#   `core install` is about to exercise.
#
# - Every pair is its own compose project (`duo-<name>`, via `-p`), so any
#   number of pairs can come and go independently. They all attach to one
#   external network (duo-shared, owned/created by sandbox/db.yml) to reach
#   the shared db by its container name (duo-shared-db) — `depends_on`
#   can't cross compose-project boundaries, which is exactly why this
#   script's own readiness waits exist instead.
#
# - `up` performs the host-budget check before creating any pair database or
#   site-repo state. A new pair over the dynamic CPU/RAM budget is refused;
#   `DUO_PAIR_BUDGET_OVERRIDE=1` is the explicit escape hatch. `list` surfaces
#   the same budget warning for pairs already up.
#
# - `up`/`reset`/`start` print the agent/adapter-packages/platform bind-mount source they
#   will actually use (path + HEAD) before doing anything, and refuse if
#   `DUO_EXPECTED_SOURCE_SHA` is set and that source is not exactly that
#   commit, clean — DUO-3377's exact-source gate, see
#   assert_candidate_source() below.
#
# - `reset` DROPs both databases, so it also RECORDS that fact per side
#   (siterepo/.<name>{1,2}.needs-install) and `up` consumes the record:
#   after this script has emptied a database it never re-derives "is this
#   side installed?" from a single `wp core is-installed` probe against that
#   same database. `up` then refuses loudly if a side it just bootstrapped is
#   still not installed, instead of handing a caller a "ready" pair with no
#   WordPress in it — DUO-3412, see
#   pair_bootstrap_needs_install_marker() in lib/pair_bootstrap.sh.
set -euo pipefail
cd "$(dirname "$0")/.."   # sandbox/bin/pair.sh -> sandbox/

say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
warn() { printf '\033[1;33m%s\033[0m\n' "$*" >&2; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*" >&2; exit 1; }

# Identity is separate from lifecycle: every subcommand needs the same
# namespace and canonical-checkout rules, while only a few mutate a pair.
# Keep the historical facade names below so callers and diagnostics remain
# byte-compatible as the implementation gains shared users.
[ -r "lib/pair_identity.sh" ] \
  || fail "pair identity library is missing: lib/pair_identity.sh (the launcher cannot safely resolve a pair name or canonical checkout)"
# shellcheck source=../lib/pair_identity.sh
source "lib/pair_identity.sh"

[ -r "lib/pair_budget_lock.sh" ] || fail "pair budget lock library is missing: lib/pair_budget_lock.sh (the launcher cannot safely reserve shared host capacity)"
# shellcheck source=../lib/pair_budget_lock.sh
source "lib/pair_budget_lock.sh"

[ -r "lib/pair_force_hatch.sh" ] || fail "pair force-hatch library is missing: lib/pair_force_hatch.sh (the launcher cannot truthfully record forced over-budget pair admission)"
# shellcheck source=../lib/pair_force_hatch.sh
source "lib/pair_force_hatch.sh"

[ -r "lib/pair_db.sh" ] || fail "pair db library is missing: lib/pair_db.sh (the launcher cannot safely bring up or query the shared database)"
# shellcheck source=../lib/pair_db.sh
source "lib/pair_db.sh"

[ -r "lib/pair_compose.sh" ] || fail "pair compose library is missing: lib/pair_compose.sh (the launcher cannot safely build compose invocations or query compose state)"
# shellcheck source=../lib/pair_compose.sh
source "lib/pair_compose.sh"

[ -r "lib/pair_lease.sh" ] || fail "pair lease library is missing: lib/pair_lease.sh (parallel evidence lanes cannot reserve names/ports safely)"
# shellcheck source=../lib/pair_lease.sh
source "lib/pair_lease.sh"

[ -r "lib/pair_readiness.sh" ] || fail "pair readiness library is missing: lib/pair_readiness.sh (the launcher cannot safely verify pair, mount, and database readiness)"
# shellcheck source=../lib/pair_readiness.sh
source "lib/pair_readiness.sh"

[ -r "lib/pair_bootstrap.sh" ] || fail "pair bootstrap library is missing: lib/pair_bootstrap.sh (the launcher cannot safely install or verify WordPress sides)"
# shellcheck source=../lib/pair_bootstrap.sh
source "lib/pair_bootstrap.sh"

[ -r "lib/pair_siterepo.sh" ] || fail "pair site-repository library is missing: lib/pair_siterepo.sh (the launcher cannot safely hand back or clear exact pair roots)"
# shellcheck source=../lib/pair_siterepo.sh
source "lib/pair_siterepo.sh"


# DUO-3277: the repo's CANONICAL checkout -- where a persistent pair's
# bind-mounted agent/adapter-packages/platform sources must always live, regardless of
# which worktree's own copy of THIS SCRIPT actually ran `up`. Per-issue
# worktrees are always removed at close-gate; a pair whose agent/adapter-packages/platform
# bind-mount source was resolved against a worktree (the historical bug --
# `../agent` in pair.yml, relative to wherever pair.sh's own `cd
# "$(dirname "$0")/.."` above landed) is left with a dead mount the moment
# that worktree goes, silently, until the next `pair.sh start` fails --
# potentially days later, by a different actor (observed live twice on the
# r3b pair; see this issue's own filing).
#
# Git's own common-dir is the right primitive, not a hardcoded directory
# name: for a LINKED worktree, `git rev-parse --git-common-dir` resolves
# to the PRIMARY worktree's own .git (a linked worktree's .git is a FILE
# pointing back to it, never a directory of its own); for the primary
# worktree itself, it's simply its own .git. The identical one-liner
# resolves correctly either way -- no special-casing "am I in a worktree"
# at all, and no assumption about what the canonical checkout is NAMED
# (this repo's own primary checkout is "duo-wp" in one clone on this host,
# "duo-wp-main" in another -- a hardcoded name would only ever match one
# of them, exactly the fragility this function exists to avoid).
canonical_root() {
  pair_identity_canonical_root
}

# DUO-3277: `start` (unlike `up`) never touches container config -- compose
# start just resumes whatever bind-mount sources were baked in when the
# container was CREATED, so a pair created before this fix shipped (or a
# pair whose agent/adapter-packages/platform source directory was deleted out from under
# it for any other reason) still hits a dead mount here even after the
# canonicalize fix above, until someone runs `up` again to force the
# recreate. Detect it here and say exactly what happened and how to
# recover instead of leaving it to docker's own opaque container-start
# failure (the issue's own acceptance criterion) -- only wp1/wp2 have
# persistent containers `start` ever touches; cli1/cli2 are always `run
# --rm` (see every wp_env()-style helper across this sandbox), so they
# never have a stopped container of their own to check or resume.
check_dead_mounts() { # check_dead_mounts <name>
  local name="$1" container dead=()
  for container in "duo-${name}-wp1-1" "duo-${name}-wp2-1"; do
    docker inspect "$container" >/dev/null 2>&1 || continue   # not created yet -- nothing to check
    local sources src
    sources=$(docker inspect "$container" \
      --format '{{range .Mounts}}{{if eq .Type "bind"}}{{.Source}}{{"\n"}}{{end}}{{end}}' 2>/dev/null || true)
    while IFS= read -r src; do
      [ -n "$src" ] && [ ! -e "$src" ] && dead+=("$container: $src")
    done <<< "$sources"
  done
  if [ "${#dead[@]}" -gt 0 ]; then
    fail "pair '$name' has a dead bind-mount source -- the checkout its containers were created against no longer exists on disk (DUO-3277's own worktree-bind-mount hazard: a pair started with 'up' before that fix shipped, or from a worktree since removed, still has the OLD source baked in):
$(printf '  %s\n' "${dead[@]}")
recovery: run \"pair.sh up $name <port1> <port2> [same flags you originally used]\" from ANY checkout of this repo (worktree or canonical, doesn't matter now) -- this recreates the container against the canonical checkout's own agent/adapter-packages/platform (docker compose detects the config drift and recreates automatically); this pair's own database and webroot volumes are untouched either way"
  fi
}

# DUO-3377: the container-side destination pair.yml mounts DUO_AGENT_SRC to.
# Matched exactly, never by prefix: the sibling duo-loader.php mount lives in
# the same directory, and pair.codebind.yml adds its own mounts one tree over
# (wp-content/plugins/, see pair_siterepo_refuse_codebind_reset).
AGENT_MOUNT_DEST=/var/www/html/wp-content/mu-plugins/duo

# DUO-3377: the agent bind source BAKED INTO an existing pair's containers,
# which is not necessarily what canonical_root() resolves today -- compose
# start reuses whatever a container was CREATED with (the same fact
# check_dead_mounts above exists for), so a pair created before DUO-3277
# shipped can still carry a different source path.
#
# BOTH web containers are read, not just the first one that answers -- the
# same pair of containers check_dead_mounts beside this walks. Compose creates
# wp1/wp2 from one pair.yml in a single transaction, so they cannot currently
# disagree; short-circuiting on wp1 anyway would leave this function quietly
# reporting half an answer the day something else can create them separately,
# which is precisely the class of silent half-truth this whole issue exists to
# remove. Disagreement is therefore a refusal, not a coin flip.
#
# The answer comes back in PAIR_BAKED_AGENT_SRC rather than on stdout: a
# refusal in here has to kill the RUN, and inside the `$(...)` this used to be
# called from, fail's exit would only have ended the subshell and been
# swallowed by the caller's `|| true`. Returns non-zero (with the variable
# empty) when this pair has no container yet, or when docker cannot answer --
# `up` is then the path that decides the source, and it resolves it
# canonically.
PAIR_BAKED_AGENT_SRC=""
mounted_agent_source() { # mounted_agent_source <name>
  local name="$1" container mounts source destination found="" seen=()
  PAIR_BAKED_AGENT_SRC=""
  for container in "duo-${name}-wp1-1" "duo-${name}-wp2-1"; do
    docker inspect "$container" >/dev/null 2>&1 || continue
    mounts=$(docker inspect "$container" \
      --format '{{range .Mounts}}{{if eq .Type "bind"}}{{.Source}}{{"\t"}}{{.Destination}}{{"\n"}}{{end}}{{end}}' \
      2>/dev/null) || continue
    while IFS=$'\t' read -r source destination; do
      [ "${destination:-}" = "$AGENT_MOUNT_DEST" ] || continue
      seen+=("$container: $source")
      if [ -z "$found" ]; then
        found="$source"
      elif [ "$found" != "$source" ]; then
        fail "pair '$name' has DISAGREEING agent bind-mount sources baked into its two web containers, so there is no single answer to 'which code does this pair run' -- refusing before any pair mutation:
$(printf '  %s\n' "${seen[@]}")
recovery: run \"pair.sh up $name <port1> <port2> [same flags you originally used]\" to recreate BOTH containers against this checkout's canonical agent/adapter-packages/platform (compose detects the config drift and recreates automatically); this pair's own databases and webroot volumes are untouched either way"
      fi
      break   # one agent mount per container; the rest of its mounts are other trees
    done <<< "$mounts"
  done
  PAIR_BAKED_AGENT_SRC="$found"
  [ -n "$found" ]
}

# DUO-3377: the exact-source gate. DUO-3277 made every pair's agent/adapter-packages/platform
# bind mounts resolve to the CANONICAL checkout (canonical_root() above) no
# matter which checkout ran this script -- exactly right for a pair that must
# outlive a per-issue worktree, and silently wrong for EVIDENCE: a live
# regression or conformance sweep launched from an issue worktree mounts the
# canonical checkout's bytes, not the candidate branch's, and the verdict it
# produces (green OR red) is about code that was never under test. Observed
# live during DUO-3316: worktree at 3ae1ea5, pair mounted canonical b69fdf,
# and the resulting stale-code warnings read as a candidate regression for a
# full day before the mount was suspected.
#
# Opt-in via DUO_EXPECTED_SOURCE_SHA, because the persistent-pair workflows
# DUO-3277 exists for are deliberately NOT candidate-bound: unset, every path
# below behaves exactly as it did before this gate (it only PRINTS what is
# being mounted, which every live evidence run wants recorded anyway). Set, a
# run declares "the mounted agent/adapter-packages/platform bytes must be commit <sha>, with
# no uncommitted changes", and any other answer refuses before the first
# mutation -- no budget lock, no shared db, no DROP/CREATE, no site-repo
# roots, no container create/start. A refusal costs seconds; a false verdict
# costs a day.
#
# An environment variable rather than a subcommand flag: `reset` DROP/CREATEs
# both databases and takes no flags at all, `start` starts containers and
# takes none either, and the callers that actually produce evidence
# (sandbox/conformance/run.sh, every regress_*.sh/grind_*.sh) invoke pair.sh
# as a subprocess. One exported variable reaches every subcommand from every
# caller with no argv plumbing anywhere -- the same reasoning that put
# DUO_AGENT_SRC/DUO_ADAPTER_PACKAGES_SRC/DUO_PLATFORM_SRC into sandbox/.env in pair_compose_configure().
#
# Deliberately NOT applied to stop/destroy/list: those are teardown and
# inspection, never evidence, and cleanup must never be blocked by a variable
# left exported in someone's shell.
assert_candidate_source() { # assert_candidate_source <subcommand> [baked-agent-dir]
  local subcommand="$1" baked="${2:-}"
  local canonical selected source_root actual expected dirt dirt_err origin=""

  # An unresolvable canonical root is NOT this function's failure to report
  # while the gate is off: `reset` never needed git at all (it only touches
  # databases and site-repo directories relative to its own cwd), and `up`/
  # `start` already fail closed on exactly this condition further down, in
  # pair_compose_configure()/reserve_pair_budget(), with their own diagnostics. Failing
  # here would add a brand-new failure mode to an ungated `reset` run from a
  # non-Git copy of this script -- caught by regress_pair_bootstrap_unit.sh's
  # reset_codebind_refusal case, which reset from a scratch directory and got
  # this refusal instead of its codebind one. With the gate ON it is fatal:
  # a run that demands an exact source cannot proceed without identifying it.
  canonical="$(canonical_root)" || canonical=""
  selected="$(pair_identity_source_root)" || selected=""
  # The canonical root remains the shared budget-lock identity. The selected
  # source controls only agent/adapter-packages/platform mounts and is normally canonical;
  # evidence lanes may explicitly select their clean linked worktree.
  [ -n "$canonical" ] && PAIR_CANONICAL_ROOT="$canonical"
  [ -n "$selected" ] && PAIR_SOURCE_ROOT="$selected"

  source_root="$selected"
  if [ -n "$baked" ] && [ "$baked" != "$selected/agent" ]; then
    source_root="$(dirname "$baked")"
    origin=" (baked into this pair's existing containers at create time; the selected source root is $selected)"
  fi
  if [ -n "$source_root" ]; then
    actual="$(git -C "$source_root" rev-parse --verify HEAD 2>/dev/null)" || actual=""
  else
    actual=""
  fi
  expected="${DUO_EXPECTED_SOURCE_SHA:-}"

  # On stderr, unlike every other say()/pass() in this file: which bytes
  # produced a piece of evidence is provenance, not progress chatter, and most
  # live suites run `pair.sh up ... >/dev/null` (regress_acf_term_options_
  # fields.sh, regress_adapter_theme_range.sh, regress_menu_item_meta_gate.sh,
  # ...). Printing this to stdout would make it invisible in exactly the runs
  # whose evidence most needs to name its source.
  {
    say "candidate source for '$subcommand' (DUO-3377): agent/adapter-packages/platform bind mounts"
    if [ -n "$source_root" ]; then
      echo "  mounted source: ${source_root}/{agent,adapter-packages,platform}${origin}"
    else
      echo "  mounted source: <unresolvable — git could not name this repo's canonical checkout>"
    fi
    echo "  source HEAD:    ${actual:-<none — that path is not a git checkout>}"
    echo "  invoked from:   $(pwd)"
  } >&2
  if [ -z "$expected" ]; then
    echo "  expected SHA:   (DUO_EXPECTED_SOURCE_SHA unset — this run is NOT candidate-bound)" >&2
    return 0
  fi

  expected="$(printf '%s' "$expected" | tr '[:upper:]' '[:lower:]')"
  echo "  expected SHA:   $expected (DUO_EXPECTED_SOURCE_SHA)" >&2
  [[ "$expected" =~ ^[0-9a-f]{7,40}$ ]] \
    || fail "DUO_EXPECTED_SOURCE_SHA must be a 7-40 character hex commit SHA (got '${DUO_EXPECTED_SOURCE_SHA}') -- take it from \`git rev-parse HEAD\` in the checkout whose bytes this evidence is about; refusing before any pair mutation rather than guessing what was meant"
  [ -n "$source_root" ] \
    || fail "could not resolve a safe source checkout via git -- DUO_SOURCE_ROOT must be the exact physical path of a worktree from this repository; DUO_EXPECTED_SOURCE_SHA=$expected cannot be honored"
  [ -n "$actual" ] \
    || fail "candidate-source gate is set (DUO_EXPECTED_SOURCE_SHA=$expected) but the mounted source has no resolvable HEAD: $source_root -- refusing before any pair mutation"
  if [ "${actual:0:${#expected}}" != "$expected" ]; then
    fail "candidate-source MISMATCH -- refusing before any pair mutation (no budget reservation, no database drop/create, no site-repo roots, no container create/start):
  expected (DUO_EXPECTED_SOURCE_SHA): $expected
  actual mounted source:              ${source_root}/{agent,adapter-packages,platform}${origin}
  actual mounted source HEAD:         $actual
  this pair.sh copy is running from:  $(pwd)
By default persistent pairs mount the canonical checkout. For evidence from a
linked worktree, explicitly select that exact physical worktree:
  DUO_SOURCE_ROOT=\$(pwd -P) DUO_EXPECTED_SOURCE_SHA=\$(git rev-parse HEAD) bash sandbox/bin/pair.sh $subcommand ...
Otherwise set DUO_EXPECTED_SOURCE_SHA=$actual only if the canonical checkout genuinely is the intended source."
  fi
  # The right commit says nothing about the two directories being PRESENT:
  # `git status -- <pathspec>` reports nothing at all for a path that does not
  # exist, so the clean-tree check just below would otherwise pass a checkout
  # with no agent/ straight through to an opaque compose mount error later.
  [ -d "$source_root/agent" ] \
    || fail "the expected commit matched but the agent bind-mount source is absent: $source_root/agent -- refusing before any pair mutation"
  [ -d "$source_root/adapter-packages" ] \
    || fail "the expected commit matched but the adapter-packages bind-mount source is absent: $source_root/adapter-packages -- refusing before any pair mutation"
  [ -d "$source_root/platform" ] \
    || fail "the expected commit matched but the platform bind-mount source is absent: $source_root/platform -- refusing before any pair mutation"
  # Dirtiness is scoped to the three directories that are actually MOUNTED, not
  # to the whole tree: this script itself writes sandbox/.env and
  # sandbox/siterepo/ into the checkout on every run, and a shared canonical
  # checkout routinely carries other agents' in-flight work -- a whole-tree
  # check would refuse over bytes no container ever sees. What the gate
  # promises is that the MOUNTED bytes are exactly this commit's, which is
  # precisely this query.
  #
  # Two details this query is fussy about, both found in review:
  # - stderr is captured SEPARATELY, never merged into the porcelain output.
  #   git can warn while still exiting 0 (an unreadable directory, for one),
  #   and a merged capture turns that warning text into a phantom "DIRTY"
  #   refusal quoting a message that names no file at all.
  # - --no-optional-locks, because this runs against the SHARED canonical
  #   checkout other agents are working in concurrently: a plain `git status`
  #   takes index.lock and writes the refreshed index back, which is both an
  #   unwanted write on someone else's checkout and a flaky-refusal risk if it
  #   loses that race.
  dirt_err="$(mktemp "${TMPDIR:-/tmp}/duo-pair-source-dirt.XXXXXX")" \
    || fail "could not create a temporary file to capture git's own diagnostics -- refusing before any pair mutation"
  if ! dirt="$(git -C "$source_root" --no-optional-locks status --porcelain=v1 \
      --untracked-files=all -- agent adapter-packages platform 2>"$dirt_err")"; then
    local why
    why="$(cat "$dirt_err" 2>/dev/null || true)"
    rm -f -- "$dirt_err"
    fail "could not check the mounted source for uncommitted agent/adapter-packages/platform changes ($source_root) -- refusing before any pair mutation: ${why:-git status failed without a diagnostic}"
  fi
  rm -f -- "$dirt_err"
  if [ -n "$dirt" ]; then
    # One array element per porcelain line, so every line gets the indent --
    # printf with a single multi-line argument indents only the first (the
    # same array/loop form check_dead_mounts uses for its own listing).
    local -a dirt_lines=()
    local dirt_line
    while IFS= read -r dirt_line; do
      [ -n "$dirt_line" ] && dirt_lines+=("$dirt_line")
    done <<< "$dirt"
    fail "candidate source is DIRTY -- refusing before any pair mutation. The agent/adapter-packages/platform bytes about to be mounted from $source_root do not correspond to $actual:
$(printf '  %s\n' "${dirt_lines[@]}")
remedy: commit or stash those changes, or produce this evidence from a clean standalone clone at the expected commit (git clone --branch <branch> $canonical /path/to/duo-wp-live-<issue>). Uncommitted mount bytes make the evidence unreproducible -- nothing records what they were"
  fi
  pass "mounted source is exactly $expected, clean — this run's evidence is bound to that commit" >&2
}

# Which shared database server this invocation talks to. DB_CONTAINER,
# DB_CLIENT, DB_COMPOSE and DB_LABEL come from DUO_DB_ENGINE via
# pair_db_select_engine() (lib/pair_db.sh); its `mariadb` default reproduces
# the exact values this block hard-coded before the MySQL evidence lane
# existed, so no default-path byte moves. Called HERE, at load, because fail()
# is defined at the top of this script and lib/pair_db.sh is already sourced
# above: an unknown engine therefore refuses before ANY subcommand runs and
# before a single docker call is made.
pair_db_select_engine
# The selected engine's server name travels with the WHOLE invocation, not just
# `up`: pair_compose_configure() REWRITES sandbox/.env on every call (stop,
# start and destroy each call it too, pair.sh:783/832/855), so exporting this
# inside cmd_up only would let a later `pair.sh stop <mysql-pair>` overwrite
# that file's DUO_DB_HOST with pair_compose.sh's duo-shared-db default -- and
# the next subprocess `docker compose -f pair.yml up` from conformance/run.sh
# or a regress_*.sh would then recreate wp1/wp2 against MariaDB while the
# operator recorded MySQL evidence. Exported at load, beside the selection it
# derives from, that window does not exist.
export DUO_DB_HOST="$DB_CONTAINER"
DB_ROOT_USER=root
DB_ROOT_PASS=root
APP_USER=wordpress
APP_PASS=wordpress

validate_name() { # validate_name <name>
  pair_identity_validate_name "$1"
}

# --- shared db: bring-up, readiness, admin SQL, per-pair lifecycle are in
# lib/pair_db.sh (pair_db_sql/pair_db_ensure_up/pair_db_ensure_app_user/
# pair_db_create/pair_db_drop) --------------------------------------------

# --- pair-level compose plumbing: invocation building and compose-state
# discovery are in lib/pair_compose.sh (pair_compose_configure/
# pair_compose_live_pairs/pair_compose_stopped_pairs) ------------------------

# DUO-3412: the "this side's database was dropped out from under it" record —
# the one piece of state that lets `up` know it must not trust the
# `is-installed` probe it is about to make.
#
# The hazard is specific to reset->up, which is exactly the sequence every
# conformance sweep opens with (sandbox/conformance/run.sh's first two pair.sh
# calls). `reset` DROP/CREATEs both databases while this pair's wp1/wp2
# containers and their webroot volumes KEEP RUNNING — it deliberately does not
# restart or reinstall anything, see cmd_reset's own summary — and `up` then
# asks that still-warm site `wp core is-installed` and SKIPS `wp core install`
# on TRUE. One probe answering TRUE against a database this script itself
# emptied seconds earlier (shared-MariaDB propagation lag, a probe that
# reached the wrong database, any cached answer) is enough for `up` to skip
# the reinstall and hand back a "ready" pair with no wp_options in it. The
# sweep then dies a long way from the cause, at its first seed's `wp_conf1`
# call, on wp-cli's bare "Error: The site you have requested is not
# installed" — observed live during DUO-3410's degraded-host era. That the
# probe actually raced is a HYPOTHESIS (that host was failing other ways
# too); that a harness must not re-derive a premise it just destroyed from a
# single probe is not — it is DUO-3381/DUO-3391's premise-before-behavior
# family applied to bootstrap instead of to a seed hook.
#
# So `reset` records what it did and `up` consumes the record: after a DROP
# there is nothing to probe FOR, because installing is definitionally correct.
#
# Location: sandbox/siterepo/ — this script's own dot-file state directory
# (the shared .pair-budget.lock and its helper dirs already live there),
# gitignored as a whole tree, and removed by `make clean`. Deliberately NOT
# inside siterepo/<name>{1,2}: those two are bind-mounted into the containers
# as /siterepo and ARE the site repository the agent captures and commits, so
# a stray dot-file there is content drift in someone's evidence — and
# cmd_reset's own pair_siterepo_clear_root() empties them, which would delete
# the marker in the same breath that wrote it.
# --- compose state/discovery is in lib/pair_compose.sh; bounded readiness
# waits are in lib/pair_readiness.sh (pair_readiness_wait_*()); WordPress
# installation and reset-to-bootstrap state are in lib/pair_bootstrap.sh ---

pair_budget() {
  # Dynamic host budget instead of a hardcoded pair count: **2 RUNNING pairs
  # per docker core**, computed from what the docker VM actually has right
  # now — a fixed number calibrated to one machine's load (the old "2",
  # set while an unrelated kind cluster ate half this host) goes stale the
  # moment the machine changes. Pairs are DB- and PHP-boot-bound, not
  # CPU-bound — a sweep spends its wall clock in MariaDB round-trips and
  # wp-cli boots — so two pairs comfortably share one core (owner throughput
  # ruling 2026-08-11; was 1 pair per core). Two reserves come off the top
  # before the per-core rule applies:
  #   - CPU: 2 cores for the shared MariaDB (its own cpus cap is 2.0) plus
  #     daemon/system churn.
  #   - RAM guard: on most machines memory binds before cores — an ACTIVELY
  #     verifying pair runs ~1GiB TYPICAL (wp1+wp2 resident well under their
  #     1GiB mem_limits; cli bursts are ephemeral), so also cap at
  #     (docker mem - 3GiB reserve for the db's 2GiB cap + overhead) / 1GiB
  #     per pair, and take the smaller of the two budgets. This sizes
  #     admission to typical active use rather than the wp1+wp2 cap sum the
  #     old /2GiB rule charged (same ruling); the per-container mem_limits
  #     in pair.yml stay the backstop for a runaway container (per
  #     container, not an aggregate host guarantee — admission is a soft
  #     control sized to typical concurrency).
  # Floor of 1: a tiny VM still gets one pair (nothing works otherwise).
  local cores mem_bytes mem_gib cpu_budget ram_budget budget
  cores="$(docker info -f '{{.NCPU}}' 2>/dev/null)" || return 1
  mem_bytes="$(docker info -f '{{.MemTotal}}' 2>/dev/null)" || return 1
  [[ "$cores" =~ ^[0-9]+$ ]] || return 1
  [[ "$mem_bytes" =~ ^[0-9]+$ ]] || return 1
  [ "$cores" -ge 1 ] || return 1
  [ "$mem_bytes" -ge 1 ] || return 1
  mem_gib=$(( mem_bytes / 1073741824 ))
  cpu_budget=$(( (cores - 2) * 2 ))
  ram_budget=$(( mem_gib - 3 ))
  budget=$(( cpu_budget < ram_budget ? cpu_budget : ram_budget ))
  [ "$budget" -lt 1 ] && budget=1
  printf '%s\n' "$budget"
}

reserve_pair_budget() { # reserve_pair_budget <candidate>; leaves lock held
  local candidate="$1" root live reserved budget live_count reserved_count candidate_admitted=0 total pair
  if ! root="$(canonical_root)"; then
    fail "could not resolve this repo's canonical checkout via git (not a git repository?) -- pair budget reservation cannot be shared safely"
  fi
  PAIR_CANONICAL_ROOT="$root"
  budget_lock_acquire "$root"

  if ! live="$(pair_compose_live_pairs)"; then
    budget_lock_release
    fail "could not enumerate live pair Compose projects; refusing without a verified budget"
  fi
  PAIR_BUDGET_LIVE_PAIRS="$live"
  reserved="$(pair_lease_reserved_pairs)"
  PAIR_BUDGET_RESERVED_PAIRS="$reserved"
  if ! budget="$(pair_budget)"; then
    budget_lock_release
    fail "could not query Docker host capacity; refusing without a verified budget"
  fi

  live_count="$(printf '%s\n' "$live" | awk 'NF {n++} END {print n+0}')"
  reserved_count=0
  while IFS= read -r pair; do
    [ -n "$pair" ] || continue
    if ! printf '%s\n' "$live" | grep -Fqx -- "$pair"; then
      reserved_count=$((reserved_count + 1))
    fi
  done <<<"$reserved"
  PAIR_BUDGET_LIMIT="$budget"
  PAIR_BUDGET_LIVE_COUNT="$live_count"
  PAIR_BUDGET_RESERVED_COUNT="$reserved_count"
  PAIR_BUDGET_AVAILABLE=$((budget - live_count - reserved_count))
  [ "$PAIR_BUDGET_AVAILABLE" -ge 0 ] || PAIR_BUDGET_AVAILABLE=0
  if [ -n "$candidate" ] && {
    printf '%s\n' "$live" | grep -Fqx -- "$candidate" \
      || printf '%s\n' "$reserved" | grep -Fqx -- "$candidate";
  }; then
    candidate_admitted=1
  fi
  total=$((live_count + reserved_count))
  [ -z "$candidate" ] || [ "$candidate_admitted" -eq 1 ] || total=$((total + 1))

  if [ "$total" -gt "$budget" ]; then
    warn ""
    warn "!! ${live_count} running + ${reserved_count} reserved pairs (budget for this host: ${budget} — 2 pairs per docker core, RAM-guarded; see pair_budget())"
    warn "!! pairs: $(printf '%s' "$live" | tr '\n' ' ')"
    warn "!! stop pairs you're not actively using (pair.sh stop <name>) or destroy finished ones"
    if [ -n "$candidate" ] && [ "$candidate_admitted" -eq 0 ]; then
      if [ "${DUO_PAIR_BUDGET_OVERRIDE:-0}" = "1" ]; then
        # Presence is permission, not evidence of use.  Record only here,
        # after both the in-budget and held-reservation paths have failed to
        # admit the candidate.  A ledger the operator configured and that then
        # cannot be written fails closed before pair mutation: a run that
        # forced its way past the budget has to stay distinguishable from one
        # that did not, and that is the ledger's whole remaining job now that
        # nothing projects it anywhere (lib/pair_force_hatch.sh header).
        if ! pair_force_hatch_record DUO_PAIR_BUDGET_OVERRIDE; then
          budget_lock_release
          fail "could not record actual DUO_PAIR_BUDGET_OVERRIDE use in the force-hatch ledger; refusing before pair mutation"
        fi
        warn "!! DUO_PAIR_BUDGET_OVERRIDE=1 set — bringing up '$candidate' ANYWAY, ${total}/${budget} over budget"
      else
        budget_lock_release
        fail "refusing to bring up new pair '$candidate' over budget (${total} > ${budget}); stop/destroy another pair first, or set DUO_PAIR_BUDGET_OVERRIDE=1 to proceed anyway"
      fi
    fi
  fi
}

# --- subcommands -------------------------------------------------------------

cmd_up() {
  local name="${1:?usage: pair.sh up <name> <port1> <port2> [--journal] [--codebind <dir>] [--artifacts] [--wordpress-offline] [--git-cli] [--http|--headless]}"
  local port1="${2:?up needs <port1>}"
  local port2="${3:?up needs <port2>}"
  shift 3
  local journal=0 codebind="" artifacts=0 wordpress_offline=0 git_cli=0 http_mode=1
  while [ $# -gt 0 ]; do
    case "$1" in
      --journal) journal=1; shift ;;
      --codebind) codebind="${2:?--codebind needs a plugin directory name}"; shift 2 ;;
      --artifacts) artifacts=1; shift ;;
      --wordpress-offline) wordpress_offline=1; shift ;;
      --git-cli) git_cli=1; shift ;;
      --http) http_mode=1; shift ;;
      --headless) http_mode=0; shift ;;
      *) fail "up: unknown flag '$1'" ;;
    esac
  done
  [ "$wordpress_offline" = 0 ] || [ "$artifacts" = 1 ] \
    || fail "up: --wordpress-offline requires --artifacts so bootstrap has a verified local theme source"
  if [ "$artifacts" = 1 ]; then
    # Ordinary pair commands retain pair.sh's self-contained load boundary;
    # only the explicit artifact-backed bootstrap needs the shared resolver.
    # shellcheck source=fetch-artifact.sh
    . bin/fetch-artifact.sh
  fi
  validate_name "$name"

  # DUO-3377: the exact-source gate runs FIRST -- ahead of the budget
  # reservation (which creates the shared lock directory under the canonical
  # checkout), the shared DB, this pair's schemas, its site-repo roots, and
  # every container operation. A refusal here has touched nothing at all.
  assert_candidate_source up

  # The typed artifact library is mutation authority for the explicit
  # artifact-backed bootstrap. Validate its complete closed shape before the
  # budget lock, shared database, pair roots, or Docker are touched; a later
  # fetch must not be the first place an unknown role/key is discovered.
  if [ "$artifacts" = 1 ]; then
    validate_artifact_library \
      || fail "up: artifact library is malformed; no pair resources were changed"
    validate_artifact_platform_library \
      || fail "up: platform artifact library is malformed; no pair resources were changed"
    PAIR_BOOTSTRAP_THEME_VERSION=$(artifact_library_platform_jq -r '
      .themes.twentytwentyone | if type == "object" and length == 1 then keys[0] else empty end
    ')
    [[ "$PAIR_BOOTSTRAP_THEME_VERSION" =~ ^[0-9A-Za-z][0-9A-Za-z._-]*$ ]] \
      || fail "up: the pinned bootstrap-theme registry entry is missing or ambiguous; no pair resources were changed"
    PAIR_BOOTSTRAP_THEME_ARCHIVE_ROOT=$(artifact_library_platform_jq -r \
      --arg version "$PAIR_BOOTSTRAP_THEME_VERSION" \
      '.themes.twentytwentyone[$version].archive_root // "twentytwentyone"')
    [[ "$PAIR_BOOTSTRAP_THEME_ARCHIVE_ROOT" =~ ^[a-z0-9][a-z0-9._-]*[a-z0-9]$ ]] \
      || fail "up: the pinned bootstrap-theme archive root is malformed; no pair resources were changed"
  else
    PAIR_BOOTSTRAP_THEME_VERSION=
    PAIR_BOOTSTRAP_THEME_ARCHIVE_ROOT=
  fi
  PAIR_BOOTSTRAP_ARTIFACTS="$artifacts"

  if [ "$git_cli" = 1 ]; then
    [ -n "${DUO_CLI_IMAGE:-}" ] \
      || fail "up: --git-cli requires an explicit DUO_CLI_IMAGE tag"
    # `duo release` reads the managed repository's Git revision inside the
    # CLI service. The stock wordpress:cli image has no Git; build the repo's
    # reviewed image before pair mutation and make the selected tag persist
    # through the ordinary pair.yml interpolation used by later host verbs.
    docker build -q -f init-cli.Dockerfile -t "$DUO_CLI_IMAGE" . >/dev/null \
      || fail "up: could not build the Git-enabled CLI image $DUO_CLI_IMAGE"
  fi

  # Reserve the host budget before touching the shared DB, creating pair
  # schemas, or creating bind roots.  The reservation lock remains held
  # through web/CLI creation so a concurrent `up` cannot observe the same
  # pre-creation live-pair list and over-commit the host.
  arm_budget_up_cleanup
  reserve_pair_budget "$name"
  pair_lease_assert_access "$name" "$port1" "$port2"

  # Resolve the canonical bind sources before any shared DB or pair-directory
  # mutation. A copied/non-Git launcher must fail closed without leaving
  # orphan schemas behind. pair_compose_configure only builds argv and writes the
  # canonical-source .env; it does not contact Docker or require the codebind
  # source directories to exist yet.
  local overlays=()
  [ "$http_mode" = 1 ] && overlays+=(pair.http.yml)
  [ "$journal" = 1 ] && overlays+=(pair.journal.yml)
  [ -n "$codebind" ] && overlays+=(pair.codebind.yml)
  [ "$artifacts" = 1 ] && overlays+=(pair.artifacts.yml)
  [ "$wordpress_offline" = 1 ] && overlays+=(pair.wordpress-offline.yml)
  export DUO_PAIR="$name" DUO_PORT1="$port1" DUO_PORT2="$port2" DUO_CODEBIND_PLUGIN="$codebind"
  export DUO_ARTIFACT_OFFLINE="$wordpress_offline"
  # DUO_DB_HOST is exported at load beside pair_db_select_engine (this file's
  # shared-db section) so every subcommand carries it, not just this one;
  # pair.yml renders WORDPRESS_DB_HOST from it and pair_compose_configure()
  # persists it to sandbox/.env for the many subprocess callers that make their
  # OWN compose calls after `pair.sh up`.
  # Stock macOS Bash 3.2 treats an empty "${array[@]}" as an unbound variable
  # under `set -u`; a plain pair legitimately has no overlays.
  if [ "${#overlays[@]}" -gt 0 ]; then
    pair_compose_configure "$name" "${overlays[@]}"
  else
    pair_compose_configure "$name"
  fi

  say "shared infra: $DB_LABEL + duo-shared network"
  pair_db_ensure_up
  pair_db_ensure_app_user
  pass "shared db up, healthy, wordpress user granted on wp\\_%"

  say "pair '$name': databases"
  pair_db_create "$name"
  pass "wp_${name}1, wp_${name}2 exist"

  say "pair '$name': site-repo directories"
  pair_siterepo_prepare_roots "$name"
  if [ -n "$codebind" ]; then
    # Bootstrap-order requirement inherited from spike G (see
    # pair.codebind.yml's header): the bind-mount SOURCE must exist,
    # host-owned, before any container that mounts it is created.
    mkdir -p "siterepo/${name}1/code/wp-content/plugins/${codebind}" \
             "siterepo/${name}2/code/wp-content/plugins/${codebind}"
  fi

  say "pair '$name': web containers up"
  # Persistent callers resolve agent/adapter-packages/platform against the canonical checkout;
  # exact evidence callers may explicitly select their linked worktree with
  # DUO_SOURCE_ROOT. If that source differs from whatever config an EXISTING pair
  # was created with (e.g. a pair `up`'d from a worktree before this fix,
  # or from a different worktree than last time), compose's own standard
  # config-drift detection recreates it here automatically, on volumes
  # that never move (the r3b recovery this issue's own filing already
  # documented empirically, now happening for the RIGHT reason instead of
  # by accident).
  if [ "$DUO_AGENT_SRC" != "$(pwd)/agent" ]; then
    echo "  (bind-mount source: $(dirname "$DUO_AGENT_SRC") -- this pair.sh copy is running from $(pwd))"
  fi
  # Keep the codebind contract's force-recreate scoped to the web services,
  # but never create CLI services until the web containers have established
  # pair.yml's nested MU bind mountpoints inside their named volumes.
  if [ -n "$codebind" ]; then
    "${PAIR_COMPOSE[@]}" up -d --force-recreate wp1 wp2
  else
    "${PAIR_COMPOSE[@]}" up -d wp1 wp2
  fi
  pair_readiness_wait_web_mountpoints "$name"
  "${PAIR_COMPOSE[@]}" up -d cli1 cli2
  # Once both web and CLI containers exist, the pair is visible to the next
  # strict compose-list query. Release before the potentially long WP
  # install/bootstrap phase; EXIT/signal cleanup still protects failures
  # before this point.
  disarm_budget_up_cleanup
  pass "web mountpoints established; CLI containers up"

  say "pair '$name': waiting for DB-level readiness (both sides)"
  pair_readiness_wait_db "$name" 1
  pair_readiness_wait_db "$name" 2
  pass "both sides reach their database"

  local url1 url2
  if [ "$http_mode" = 1 ]; then
    url1="http://localhost:${port1}"; url2="http://localhost:${port2}"
  else
    # RFC 2606 .invalid — deliberately unresolvable. Headless pairs publish
    # no host port, and nothing in this design needs siteurl/home to
    # actually resolve for wp-cli to work (most wp-cli commands never make
    # an HTTP round trip to themselves); using a real in-network hostname
    # here would be misleading anyway, since wp1/wp2/cli1/cli2 are the same
    # literal service names across every pair sharing the duo-shared
    # network, and Docker's embedded DNS does not scope those bare-name
    # aliases per compose project on a shared external network — the only
    # cross-pair-safe hostname in this whole design is duo-shared-db's
    # explicit container_name. See docs/sandbox.md.
    url1="http://${name}1.invalid"; url2="http://${name}2.invalid"
  fi

  say "pair '$name': generic WordPress bootstrap (idempotent)"
  pair_bootstrap_install_side "$name" 1 "$url1" "Duo ${name}1"
  pair_bootstrap_install_side "$name" 2 "$url2" "Duo ${name}2"

  say "pair '$name' ready"
  if [ "$http_mode" = 1 ]; then
    echo "  wp1: $url1 (published on host port $port1)"
    echo "  wp2: $url2 (published on host port $port2)"
  else
    echo "  wp1: $url1 (headless — no host port published)"
    echo "  wp2: $url2 (headless — no host port published)"
  fi
  echo
  local cli_recipe_env="DUO_PAIR=${name} DUO_PORT1=${port1} DUO_PORT2=${port2} DUO_CLI_IMAGE=${DUO_CLI_IMAGE:-wordpress:cli-php8.3}"
  [ -z "$codebind" ] || cli_recipe_env="${cli_recipe_env} DUO_CODEBIND_PLUGIN=${codebind}"
  echo "  wp-cli invocation pattern for this pair (run from sandbox/):"
  echo "    ${cli_recipe_env} docker compose -p duo-${name} -f pair.yml $( [ "$journal" = 1 ] && printf -- '-f pair.journal.yml ' )$( [ -n "$codebind" ] && printf -- '-f pair.codebind.yml ' )$( [ "$artifacts" = 1 ] && printf -- '-f pair.artifacts.yml ' )$( [ "$wordpress_offline" = 1 ] && printf -- '-f pair.wordpress-offline.yml ' )run --rm cli1 wp <command...>"
  echo "    ${cli_recipe_env} docker compose -p duo-${name} -f pair.yml $( [ "$journal" = 1 ] && printf -- '-f pair.journal.yml ' )$( [ -n "$codebind" ] && printf -- '-f pair.codebind.yml ' )$( [ "$artifacts" = 1 ] && printf -- '-f pair.artifacts.yml ' )$( [ "$wordpress_offline" = 1 ] && printf -- '-f pair.wordpress-offline.yml ' )run --rm cli2 wp <command...>"
  echo "  (the printed DUO_PAIR/port/image/codebind values are part of the recipe: compose project -p alone does not populate pair.yml's variable interpolation)"
}

cmd_reset() {
  local name="${1:?usage: pair.sh reset <name>}" lease_locked=0
  validate_name "$name"
  # DUO-3377: reset is a mutation (DROP/CREATE of both databases, plus the
  # site-repo clear below) and is what every conformance sweep runs FIRST, so
  # the gate has to sit ahead of pair_siterepo_refuse_codebind_reset's Docker
  # queries too, since the source question is answerable without them.
  assert_candidate_source reset
  if canonical_root >/dev/null 2>&1; then
    arm_budget_up_cleanup
    reserve_pair_budget ""
    pair_lease_assert_access "$name"
    lease_locked=1
  fi
  pair_siterepo_refuse_codebind_reset "$name"
  # Refuse before DROP/CREATE if uid-33 descendants cannot be returned to the
  # host process that clears them. The helper preserves both bind-root inodes.
  pair_siterepo_host "$name" both
  pair_db_ensure_up

  say "pair '$name': reset"
  pair_db_drop "$name"
  pair_db_create "$name"
  # DUO-3412: record the DROP for `up`, immediately after it and before
  # anything else here can fail. From this line on, both sides of this pair
  # are KNOWN uninstalled, and the next install_side must not re-derive that
  # from a probe it makes against the two databases these lines just emptied
  # (see pair_bootstrap_needs_install_marker() in lib/pair_bootstrap.sh).
  # Written after the CREATE, never before
  # the DROP: a marker left behind by a reset that failed to drop anything
  # would force `wp core install` onto a site that is still installed.
  pair_bootstrap_mark_sides_need_install "$name"
  pair_siterepo_clear_root "siterepo/${name}1"
  pair_siterepo_clear_root "siterepo/${name}2"
  rm -rf -- "siterepo/origin-${name}.git"
  pair_siterepo_prepare_roots "$name"
  [ "$lease_locked" -eq 0 ] || disarm_budget_up_cleanup
  pass "wp_${name}1/wp_${name}2 dropped + recreated empty; siterepo/${name}{1,2} cleared in place and origin-${name}.git removed"
  echo "  reset covers: both databases (DROP/CREATE) and the site-repo contents"
  echo "  (siterepo/${name}{1,2}, origin-${name}.git). The two ordinary site-repo"
  echo "  root inodes are preserved; reset refuses while a codebind mount exists"
  echo "  because its nested plugin inode is independently pinned. It does NOT touch the wp1/wp2"
  echo "  webroot volumes and does NOT restart containers or reinstall WordPress —"
  echo "  the next wp-cli call against this pair sees an empty, uninstalled site."
  echo "  Re-run 'pair.sh up ${name} <port1> <port2> ...' (or your script's own"
  echo "  install_env) before using it again."
  echo "  reset also recorded siterepo/.${name}{1,2}.needs-install: that 'up' will"
  echo "  reinstall both sides unconditionally rather than trust an is-installed"
  echo "  probe against the databases just dropped here (DUO-3412)."
}

cmd_stop() {
  # Release-without-destroy: a stopped pair frees ALL of its RAM and CPU
  # (idle Apache+MariaDB churn is real — measured ~10-15MiB + fractional
  # CPU per container even at rest) while keeping containers, webroot
  # volumes, and this pair's databases exactly as they are. This is the
  # verb the LINEAR-LOOP resource-lifecycle rule wants while an agent is
  # polling/waiting/blocked rather than actively executing against the
  # pair (docs/agents/linear-loop.md).
  local name="${1:?usage: pair.sh stop <name>}" lease_locked=0
  validate_name "$name"
  if canonical_root >/dev/null 2>&1; then
    arm_budget_up_cleanup
    reserve_pair_budget ""
    pair_lease_assert_access "$name"
    lease_locked=1
  fi
  say "pair '$name': stop (free RAM/CPU; containers, volumes, databases all kept)"
  pair_compose_configure "$name"
  export DUO_PAIR="$name"
  "${PAIR_COMPOSE[@]}" stop
  [ "$lease_locked" -eq 0 ] || disarm_budget_up_cleanup
  pass "stopped — resume with: pair.sh start $name"
}

cmd_start() {
  # Resume a stopped pair. compose start reuses the EXISTING containers
  # (same ports, same overlay config they were created with), so none of
  # up's flags or port args are needed — and none can be changed here; a
  # config change means destroy + up.
  #
  # DUO-3412: `start` deliberately neither consumes nor clears a needs-install
  # marker, and that is a decision, not an omission. It installs nothing, so
  # there is nothing here to consume; and after reset + stop + start the pair
  # genuinely IS uninstalled, so clearing the marker here would silently
  # re-arm the exact race the marker closes — the eventual `up` (which reset's
  # own output tells you to run) must still install unconditionally. A marker
  # is therefore not "stale at start" in any sequence this script can produce;
  # it is simply not `start`'s business.
  local name="${1:?usage: pair.sh start <name>}" bound_ports
  local -a requested_ports=()
  validate_name "$name"
  # DUO-3377: `start` resumes containers with the bind-mount sources baked in
  # at CREATE time (see this file's own check_dead_mounts comment), so the
  # source this gate must verify is the BAKED one, not whatever
  # canonical_root() resolves today -- they differ for any pair created before
  # DUO-3277 shipped. check_dead_mounts below still owns the separate "that
  # source no longer exists at all" case. Called as a plain statement, never
  # inside `$(...)`: mounted_agent_source can itself refuse (disagreeing
  # containers), and that refusal has to end this run rather than a
  # command-substitution subshell that `|| true` would then swallow.
  mounted_agent_source "$name" || true
  assert_candidate_source start "$PAIR_BAKED_AGENT_SRC"
  arm_budget_up_cleanup
  reserve_pair_budget "$name"
  bound_ports="$(pair_compose_pair_bound_ports "$name")" \
    || fail "could not read pair '$name' persisted host ports before start"
  if [ -n "$bound_ports" ]; then
    mapfile -t requested_ports <<<"$bound_ports"
  fi
  pair_lease_assert_access "$name" "${requested_ports[@]}"
  # Keep the existing resume contract: the shared MariaDB must be healthy
  # before a stopped pair is started. The reservation is already held, so a
  # concurrent up/start cannot over-commit while this prerequisite runs.
  pair_db_ensure_up
  check_dead_mounts "$name"
  say "pair '$name': start (state exactly as it was at stop)"
  pair_compose_configure "$name"
  export DUO_PAIR="$name"
  "${PAIR_COMPOSE[@]}" start
  pair_readiness_wait_pair_visible "$name"
  disarm_budget_up_cleanup
  pass "running again — same ports/config as before the stop"
}

cmd_destroy() {
  local name="${1:?usage: pair.sh destroy <name>}" lease_locked=0
  validate_name "$name"
  if canonical_root >/dev/null 2>&1; then
    arm_budget_up_cleanup
    reserve_pair_budget ""
    pair_lease_assert_access "$name"
    lease_locked=1
  fi

  say "pair '$name': destroy"
  # Cleanup callers remove the pair roots after destroy. Return uid-33 capture
  # descendants first, while the exact cli mounts still exist and before any
  # container/volume/database mutation. Missing roots remain a no-op.
  pair_siterepo_host "$name" both
  pair_compose_configure "$name"
  export DUO_PAIR="$name"
  "${PAIR_COMPOSE[@]}" down -v --remove-orphans
  pair_db_ensure_up
  pair_db_drop "$name"
  # DUO-3412: the needs-install markers are this pair's state, and destroy is
  # where this pair's state goes — leaving them would make the next `up` on a
  # recycled pair name act on a record about a pair that no longer exists.
  # Destroy does NOT write markers of its own, even though it drops the same
  # two databases: `down -v` took the containers AND the webroot volumes with
  # them, so the next `up` builds a brand-new site over a brand-new database
  # and probes it from a container younger than the drop. The asymmetry that
  # makes reset hazardous — live containers and surviving volumes spanning the
  # DROP — simply cannot arise here.
  pair_bootstrap_clear_needs_install_markers "$name"
  [ "$lease_locked" -eq 0 ] || disarm_budget_up_cleanup
  pass "containers + webroot volumes removed; wp_${name}1/wp_${name}2 dropped"
  echo "  siterepo/${name}{1,2} left on disk untouched — remove by hand if you want it gone too."
}

cmd_list() {
  say "live sandbox pairs (duo-* compose projects, excluding duo-db)"
  local pairs
  # The reservation performs exactly one live query while holding the shared
  # lock. Reuse that result instead of first enumerating outside the gate.
  arm_budget_up_cleanup
  reserve_pair_budget ""
  pairs="$PAIR_BUDGET_LIVE_PAIRS"
  disarm_budget_up_cleanup
  if [ -z "$pairs" ]; then
    echo "  (none)"
  else
    printf '%s\n' "$pairs" | sed 's/^/  - /'
  fi

  say "stopped pairs (kept, zero footprint — resume with: pair.sh start <name>)"
  local stopped
  stopped=$(pair_compose_stopped_pairs)
  if [ -z "$stopped" ]; then
    echo "  (none)"
  else
    printf '%s\n' "$stopped" | sed 's/^/  - /'
  fi

  say "shared db (engine: ${DUO_DB_ENGINE:-mariadb})"
  if docker inspect "$DB_CONTAINER" >/dev/null 2>&1; then
    echo "  ${DB_CONTAINER}: $(docker inspect -f '{{.State.Status}} ({{.State.Health.Status}})' "$DB_CONTAINER")"
  else
    echo "  ${DB_CONTAINER}: not running"
  fi
}

cmd_capacity() {
  local pairs reserved budget live_count reserved_count available
  arm_budget_up_cleanup
  reserve_pair_budget ""
  pairs="$PAIR_BUDGET_LIVE_PAIRS"
  budget="$PAIR_BUDGET_LIMIT"
  live_count="$PAIR_BUDGET_LIVE_COUNT"
  reserved="$PAIR_BUDGET_RESERVED_PAIRS"
  reserved_count="$PAIR_BUDGET_RESERVED_COUNT"
  available="$PAIR_BUDGET_AVAILABLE"
  disarm_budget_up_cleanup
  jq -n --arg pairs "$pairs" --arg reserved "$reserved" --argjson budget "$budget" \
    --argjson live "$live_count" --argjson reserved_count "$reserved_count" --argjson available "$available" \
    '{schema_version:1,budget:$budget,live:$live,reserved:$reserved_count,available:$available,
      pairs:($pairs | split("\n") | map(select(length > 0))),
      reserved_pairs:($reserved | split("\n") | map(select(length > 0)))}'
}

cmd_lease_batch_acquire() {
  local token="${1:?lease-batch-acquire needs token}" owner_pid="${2:?lease-batch-acquire needs owner PID}"
  local owner_start="${3:?lease-batch-acquire needs owner start identity}"; shift 3
  local -a requests=("$@")
  arm_budget_up_cleanup
  reserve_pair_budget ""
  [ $(( ${#requests[@]} / 3 )) -le "$PAIR_BUDGET_AVAILABLE" ] \
    || fail "pair lease batch requests $((${#requests[@]} / 3)) slots but only $PAIR_BUDGET_AVAILABLE are available"
  pair_lease_acquire_batch "$token" "$owner_pid" "$owner_start" "${requests[@]}"
  disarm_budget_up_cleanup
}

cmd_lease_batch_release() {
  local token="${1:?lease-batch-release needs token}"
  [ "$#" -eq 1 ] || fail "lease-batch-release accepts exactly one token"
  arm_budget_up_cleanup
  reserve_pair_budget ""
  pair_lease_release_token "$token"
  disarm_budget_up_cleanup
}

usage() {
  cat <<'USAGE'
usage:
  pair.sh up <name> <port1> <port2> [--journal] [--codebind <plugin-dir>] [--artifacts] [--wordpress-offline] [--git-cli] [--http|--headless]
  pair.sh reset <name>
  pair.sh repo-host <name> [1|2|both]
  pair.sh stop <name>
  pair.sh start <name>
  pair.sh destroy <name>
  pair.sh list

  up       Bring up (or converge) a pair. Idempotent: ensures the shared
           MariaDB is up, creates this pair's two databases, brings up
           wp1/wp2, waits for their nested MU mountpoints, then brings up
           cli1/cli2 and waits for DB-level readiness on both sides,
           runs the generic WordPress bootstrap (core install, theme,
           permalinks, .htaccess) on each side if not already installed —
           unconditionally on a side reset marked needs-install, and
           refusing if a side is still not installed afterwards
           (DUO-3412) — then prints the wp-cli invocation pattern.
             --journal          turn on DUO_JOURNAL (pair.journal.yml)
             --codebind <dir>   bind wp-content/plugins/<dir> from this
                                 pair's own siterepo/<name>{1,2}/code/ tree
                                 (pair.codebind.yml; spike G's pattern)
             --artifacts        mount the shared digest-addressed artifact
                                 cache and bootstrap from its pinned theme
             --wordpress-offline  map WordPress.org catalog hosts to
                                 loopback; requires --artifacts and a warm cache
             --git-cli          build init-cli.Dockerfile into the explicit
                                DUO_CLI_IMAGE tag before creating pair resources
             --http             publish wp1/wp2 on <port1>/<port2> (default)
             --headless         don't publish any host port for this pair

  reset    DROP/CREATE this pair's two databases + clear ordinary site-repo
           contents in place, and record siterepo/.<name>{1,2}.needs-install
           so the next `up` reinstalls both sides unconditionally instead of
           probing the databases it just emptied (DUO-3412). Refuses if a
           live/stopped codebind mount is detected (destroy + up --codebind
           is the safe clean-room path). Does NOT touch webroot volumes,
           restart containers, or reinstall WordPress.

  repo-host
           Return one or both exact pair-owned siterepo roots from container
           uid 33 to the invoking host uid/gid, recursively, without replacing
           the bind-root inode. Used at host-Git/cleanup transitions; refuses
           symlinks, non-directories, invalid sides, or an unproved handback.

  stop     Free the pair's RAM/CPU without losing anything: containers
           stopped, webroot volumes and databases untouched. Use while
           polling/waiting/blocked instead of leaving the pair hot.

  start    Resume a stopped pair exactly as it was (same ports/config —
           compose start reuses the existing containers).

  destroy  compose -p down -v (containers + webroot volumes) + drop this
           pair's two databases. Site-repo directories are left on disk.

  list     Show live pairs, stopped pairs, and the shared db's status;
           warns if crowded.

  capacity Print the locked host pair budget, live count, available slots,
           and live pair names as machine-readable JSON.

Names: lowercase letters/digits only, starting with a letter (no
hyphens/underscores) — used bare as both a MySQL database-name fragment
and a docker compose project suffix.

Environment:
  DUO_EXPECTED_SOURCE_SHA=<7-40 hex>
           DUO-3377's exact-source gate. up/reset/start always PRINT the
           agent/adapter-packages/platform bind-mount source they will use (path + HEAD);
           with this set they additionally REFUSE — before any database
           drop/create, site-repo write, or container create/start —
           unless that source is exactly this commit with no uncommitted
           agent/adapter-packages/platform changes. Bind every live evidence run with it
           (`DUO_EXPECTED_SOURCE_SHA=$(git rev-parse HEAD)`). Without an
           explicit source override, mounts resolve to the canonical checkout.
           Unset = unchanged behavior.
           stop/destroy/list are deliberately ungated (teardown, not
           evidence). sandbox/conformance/run.sh passes it through as
           CONF_EXPECTED_SOURCE_SHA.
  DUO_SOURCE_ROOT=<absolute physical worktree path>
           Select this repository worktree as the agent/adapter-packages/platform mount source.
           The path must be the exact top-level physical path and share this
           repository's git common directory. Evidence runners set it together
           with DUO_EXPECTED_SOURCE_SHA; ordinary persistent pairs leave it unset.
  DUO_PAIR_BUDGET_OVERRIDE=1
           bring a pair up/start it even when the host budget is exceeded.
  DUO_DB_ENGINE=mariadb|mysql
           which shared database server every subcommand of this invocation
           uses. Default `mariadb` is unchanged behaviour: db.yml's
           duo-shared-db, the `mariadb` client, project duo-db. `mysql`
           selects the parallel evidence-lane server (db.mysql.yml's
           duo-shared-mysql, the `mysql` client, project duo-db-mysql) and
           exports DUO_DB_HOST so pair.yml and every subprocess compose call
           resolve it. Any other value is refused by name, at load, before any
           subcommand. Selecting `mysql` CLAIMS NOTHING: the shipped platform
           contract (platform/adapter-library/capabilities/platform.json) is still
           MariaDB-only, so `wp duo ...` on such a pair refuses
           platform_unsupported / platform_database_engine_unsupported. That
           refusal is the lane's first datum; widening the claim needs live
           evidence and its own commit.
USAGE
}

case "${1:-}" in
  up)      shift; cmd_up "$@" ;;
  reset)   shift; cmd_reset "$@" ;;
  repo-host) shift; pair_siterepo_host "$@" ;;
  stop)    shift; cmd_stop "$@" ;;
  start)   shift; cmd_start "$@" ;;
  destroy) shift; cmd_destroy "$@" ;;
  list)    shift; cmd_list "$@" ;;
  capacity) shift; cmd_capacity "$@" ;;
  lease-batch-acquire) shift; cmd_lease_batch_acquire "$@" ;;
  lease-batch-release) shift; cmd_lease_batch_release "$@" ;;
  -h|--help|"") usage ;;
  *) echo "unknown subcommand '$1'" >&2; usage >&2; exit 1 ;;
esac
