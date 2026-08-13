#!/usr/bin/env bash
# pair.sh — lifecycle tool for the sandbox redesign (task #74): one
# parameterized pair template (sandbox/pair.yml) against one shared MariaDB
# (sandbox/db.yml), instead of sandbox/docker-compose.yml's ~10 hand-
# duplicated pair profiles each carrying their own dedicated MariaDB.
#
# Subcommands:
#   pair.sh up <name> <port1> <port2> [--journal] [--codebind <plugin-dir>] [--artifacts] [--wordpress-offline] [--http|--headless]
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
#   the same budget warning for pairs already up. The one exception is the
#   pair named by a HELD host certification lock, which is already budgeted
#   for the length of that bundle — see certbundle_reserved_pair() below.
#
# - `up`/`reset`/`start` print the agent/manifests bind-mount source they
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
#   WordPress in it — DUO-3412, see needs_install_marker() below.
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

[ -r "lib/pair_db.sh" ] || fail "pair db library is missing: lib/pair_db.sh (the launcher cannot safely bring up or query the shared database)"
# shellcheck source=../lib/pair_db.sh
source "lib/pair_db.sh"

[ -r "lib/pair_compose.sh" ] || fail "pair compose library is missing: lib/pair_compose.sh (the launcher cannot safely build compose invocations or query compose state)"
# shellcheck source=../lib/pair_compose.sh
source "lib/pair_compose.sh"


# DUO-3277: the repo's CANONICAL checkout -- where a persistent pair's
# bind-mounted agent/manifests sources must always live, regardless of
# which worktree's own copy of THIS SCRIPT actually ran `up`. Per-issue
# worktrees are always removed at close-gate; a pair whose agent/manifests
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
# pair whose agent/manifests source directory was deleted out from under
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
recovery: run \"pair.sh up $name <port1> <port2> [same flags you originally used]\" from ANY checkout of this repo (worktree or canonical, doesn't matter now) -- this recreates the container against the canonical checkout's own agent/manifests (docker compose detects the config drift and recreates automatically); this pair's own database and webroot volumes are untouched either way"
  fi
}

# DUO-3377: the container-side destination pair.yml mounts DUO_AGENT_SRC to.
# Matched exactly, never by prefix: the sibling duo-loader.php mount lives in
# the same directory, and pair.codebind.yml adds its own mounts one tree over
# (wp-content/plugins/, see refuse_codebind_reset).
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
recovery: run \"pair.sh up $name <port1> <port2> [same flags you originally used]\" to recreate BOTH containers against this checkout's canonical agent/manifests (compose detects the config drift and recreates automatically); this pair's own databases and webroot volumes are untouched either way"
      fi
      break   # one agent mount per container; the rest of its mounts are other trees
    done <<< "$mounts"
  done
  PAIR_BAKED_AGENT_SRC="$found"
  [ -n "$found" ]
}

# DUO-3377: the exact-source gate. DUO-3277 made every pair's agent/manifests
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
# run declares "the mounted agent/manifests bytes must be commit <sha>, with
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
# DUO_AGENT_SRC/DUO_MANIFESTS_SRC into sandbox/.env in pair_compose_configure().
#
# Deliberately NOT applied to stop/destroy/list: those are teardown and
# inspection, never evidence, and cleanup must never be blocked by a variable
# left exported in someone's shell.
assert_candidate_source() { # assert_candidate_source <subcommand> [baked-agent-dir]
  local subcommand="$1" baked="${2:-}"
  local canonical source_root actual expected dirt dirt_err origin=""

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
  # DUO-3277's canonical bind root stays exactly what pair_compose_configure() exports
  # and writes to sandbox/.env. A baked source only ever changes what this
  # gate VERIFIES, never what any later compose call mounts.
  [ -n "$canonical" ] && PAIR_CANONICAL_ROOT="$canonical"

  source_root="$canonical"
  if [ -n "$baked" ] && [ "$baked" != "$canonical/agent" ]; then
    source_root="$(dirname "$baked")"
    origin=" (baked into this pair's existing containers at create time; this checkout's canonical root is $canonical)"
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
    say "candidate source for '$subcommand' (DUO-3377): agent/manifests bind mounts"
    if [ -n "$source_root" ]; then
      echo "  mounted source: ${source_root}/{agent,manifests}${origin}"
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
    || fail "could not resolve this repo's canonical checkout via git (not a git repository?) -- the agent/manifests bind-mount source cannot be identified, so DUO_EXPECTED_SOURCE_SHA=$expected cannot be honored; refusing before any pair mutation"
  [ -n "$actual" ] \
    || fail "candidate-source gate is set (DUO_EXPECTED_SOURCE_SHA=$expected) but the mounted source has no resolvable HEAD: $source_root -- refusing before any pair mutation"
  if [ "${actual:0:${#expected}}" != "$expected" ]; then
    fail "candidate-source MISMATCH -- refusing before any pair mutation (no budget reservation, no database drop/create, no site-repo roots, no container create/start):
  expected (DUO_EXPECTED_SOURCE_SHA): $expected
  actual mounted source:              ${source_root}/{agent,manifests}${origin}
  actual mounted source HEAD:         $actual
  this pair.sh copy is running from:  $(pwd)
This is DUO-3277's canonical bind working as designed and DUO-3377's evidence hazard: agent/manifests always resolve to the CANONICAL checkout (git's own common-dir), so a live run launched from an issue worktree executes the canonical checkout's bytes, not your branch's.
remedy: produce this evidence from a standalone clone of the candidate at that exact commit --
  git clone --branch <branch> $canonical /path/to/duo-wp-live-<issue>
  cd /path/to/duo-wp-live-<issue> && DUO_EXPECTED_SOURCE_SHA=\$(git rev-parse HEAD) bash sandbox/bin/pair.sh $subcommand ...
or, if the canonical checkout genuinely IS the intended source, set DUO_EXPECTED_SOURCE_SHA=$actual (or leave it unset for a run that is not candidate-bound)"
  fi
  # The right commit says nothing about the two directories being PRESENT:
  # `git status -- <pathspec>` reports nothing at all for a path that does not
  # exist, so the clean-tree check just below would otherwise pass a checkout
  # with no agent/ straight through to an opaque compose mount error later.
  [ -d "$source_root/agent" ] \
    || fail "the expected commit matched but the agent bind-mount source is absent: $source_root/agent -- refusing before any pair mutation"
  [ -d "$source_root/manifests" ] \
    || fail "the expected commit matched but the manifests bind-mount source is absent: $source_root/manifests -- refusing before any pair mutation"
  # Dirtiness is scoped to the two directories that are actually MOUNTED, not
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
      --untracked-files=all -- agent manifests 2>"$dirt_err")"; then
    local why
    why="$(cat "$dirt_err" 2>/dev/null || true)"
    rm -f -- "$dirt_err"
    fail "could not check the mounted source for uncommitted agent/manifests changes ($source_root) -- refusing before any pair mutation: ${why:-git status failed without a diagnostic}"
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
    fail "candidate source is DIRTY -- refusing before any pair mutation. The agent/manifests bytes about to be mounted from $source_root do not correspond to $actual:
$(printf '  %s\n' "${dirt_lines[@]}")
remedy: commit or stash those changes, or produce this evidence from a clean standalone clone at the expected commit (git clone --branch <branch> $canonical /path/to/duo-wp-live-<issue>). Uncommitted mount bytes make the evidence unreproducible -- nothing records what they were"
  fi
  pass "mounted source is exactly $expected, clean — this run's evidence is bound to that commit" >&2
}

DB_CONTAINER=duo-shared-db
DB_ROOT_USER=root
DB_ROOT_PASS=root
APP_USER=wordpress
APP_PASS=wordpress
DB_COMPOSE=(docker compose -p duo-db -f db.yml)

validate_name() { # validate_name <name>
  pair_identity_validate_name "$1"
}

# --- shared db: bring-up, readiness, admin SQL, per-pair lifecycle are in
# lib/pair_db.sh (pair_db_sql/pair_db_ensure_up/pair_db_ensure_app_user/
# pair_db_create/pair_db_drop) --------------------------------------------

# --- pair-level compose plumbing: invocation building and compose-state
# discovery are in lib/pair_compose.sh (pair_compose_configure/
# pair_compose_live_pairs/pair_compose_stopped_pairs) ------------------------

prepare_siterepo_roots() { # prepare_siterepo_roots <name>
  local name="$1"
  mkdir -p "siterepo/${name}1" "siterepo/${name}2"

  # These are disposable sandbox bind-mount roots, shared by two different
  # users: the host process creates/commits the repository, while wp-cli runs
  # as uid 33 and creates capture locks plus atomic staging directories at the
  # repository root. Linux CI preserves host ownership on bind mounts (unlike
  # some desktop Docker filesystems), so mkdir's ordinary 0755 would leave a
  # fresh checkout host-only and every capture would fail before it
  # could acquire state.capture.lock. Keep this deliberately scoped to the two
  # throwaway sandbox roots; it is not a production permission recommendation.
  chmod 0777 "siterepo/${name}1" "siterepo/${name}2"
}

# DUO-3420: return one pair-owned bind root to the host user after uid 33 has
# created capture/state trees in it. The path is never accepted from argv: it
# is derived only from an already validated pair name and a closed side value.
# A one-shot root container bind-mounts precisely that resolved directory at
# /siterepo, crossing the uid boundary without sudo or granting host cleanup
# authority over another pair or the canonical checkout. Direct `docker run`
# also keeps reset's established non-Git-copy behavior: no agent/manifests
# source resolution or pair service/volume creation is needed for a handback.
#
# The root inode is preserved. Missing roots are a no-op (destroy of a pair
# that never reached repository creation stays a no-op); a symlink or other
# non-directory refuses instead of following/replacing it. chown/chmod and the
# host-side ownership readback are all checked so reset/destroy fail before
# database/container mutation when the handback cannot be proved.
repo_host_stat_owner() { # repo_host_stat_owner <ordinary path>
  local path="$1" owner
  if owner="$(stat -c '%u:%g' "$path" 2>/dev/null)"; then
    : # GNU stat.
  elif owner="$(stat -f '%u:%g' "$path" 2>/dev/null)"; then
    : # BSD stat.
  else
    return 1
  fi
  printf '%s\n' "$owner"
}

repo_host_stat_inode() { # repo_host_stat_inode <ordinary path>
  local path="$1" inode
  if inode="$(stat -c '%i' "$path" 2>/dev/null)"; then
    : # GNU stat.
  elif inode="$(stat -f '%i' "$path" 2>/dev/null)"; then
    : # BSD stat.
  else
    return 1
  fi
  printf '%s\n' "$inode"
}

repo_host_revalidate_root() { # repo_host_revalidate_root <root> <expected-inode>
  local root="$1" expected_inode="$2" actual_inode
  if [ -L "$root" ] || [ ! -d "$root" ]; then
    fail "exact pair repository root changed from an ordinary directory during ownership handback: $root"
  fi
  actual_inode="$(repo_host_stat_inode "$root")" \
    || fail "could not read exact pair repository inode after ownership handback: $root"
  [ "$actual_inode" = "$expected_inode" ] \
    || fail "exact pair repository inode changed during ownership handback: $root"
}

repo_host_one() { # repo_host_one <name> <side (1|2)>
  local name="$1" side="$2" root root_abs host_uid host_gid owner owner_uid root_inode_before cli_image
  root="siterepo/${name}${side}"
  case "$side" in
    1|2) ;;
    *) fail "repository side '$side' invalid — expected 1 or 2" ;;
  esac
  if [ -L "$root" ] || { [ -e "$root" ] && [ ! -d "$root" ]; }; then
    fail "pair '$name' repository root is not an ordinary directory: $root — refusing ownership handback"
  fi
  [ -d "$root" ] || return 0

  host_uid="$(id -u)" || fail "could not resolve the host uid for repository handback"
  host_gid="$(id -g)" || fail "could not resolve the host gid for repository handback"
  [[ "$host_uid" =~ ^[0-9]+$ ]] && [[ "$host_gid" =~ ^[0-9]+$ ]] \
    || fail "host uid/gid must be decimal integers for repository handback (got ${host_uid}:${host_gid})"

  root_abs="$(cd "$(dirname "$root")" && pwd -P)/$(basename "$root")" \
    || fail "could not resolve exact pair repository path for ownership handback: $root"
  if [ -L "$root_abs" ] || [ ! -d "$root_abs" ]; then
    fail "pair '$name' repository root is not an ordinary physical directory: $root_abs — refusing ownership handback"
  fi
  root_inode_before="$(repo_host_stat_inode "$root_abs")" \
    || fail "could not read exact pair repository inode before ownership handback: $root"
  [[ "$root_inode_before" =~ ^[0-9]+$ ]] \
    || fail "exact pair repository inode is malformed before ownership handback: $root"

  cli_image="${DUO_CLI_IMAGE:-wordpress:cli-php8.3}"
  if ! docker run --rm -u root \
    --mount "type=bind,src=${root_abs},dst=/siterepo" \
    --entrypoint sh "$cli_image" -ceu '
uid="$1"; gid="$2"
chown -R "$uid:$gid" /siterepo
chmod -R ugo+rwX /siterepo
chmod 0777 /siterepo
' sh "$host_uid" "$host_gid"; then
    fail "could not return exact pair repository $root from container uid 33 to host ${host_uid}:${host_gid}"
  fi

  # Docker Desktop may return an exact host UID but a translated GID for this
  # bind root. Revalidate its closed path/inode before every host mutation;
  # a foreign UID is never normalized, and a final literal uid:gid readback is
  # still mandatory after the narrowly scoped host-side repair.
  repo_host_revalidate_root "$root_abs" "$root_inode_before"
  owner="$(repo_host_stat_owner "$root_abs")" || {
    fail "could not verify host ownership of exact pair repository $root after handback"
  }
  if ! [[ "$owner" =~ ^[0-9]+:[0-9]+$ ]]; then
    fail "exact pair repository ownership readback is malformed after handback: $owner"
  fi
  owner_uid="${owner%%:*}"
  if [ "$owner_uid" != "$host_uid" ]; then
    fail "exact pair repository $root has foreign uid after handback (got ${owner}; expected uid ${host_uid})"
  fi
  if [ "$owner" != "${host_uid}:${host_gid}" ]; then
    # -h prevents a root-path symlink race from following a target; no -R is
    # intentional. Docker already chowns the tree recursively, while this
    # host repair changes only the prevalidated exact bind-root directory.
    if ! chgrp -h "$host_gid" "$root_abs"; then
      repo_host_revalidate_root "$root_abs" "$root_inode_before"
      fail "could not normalize exact pair repository $root to host group ${host_gid} after handback"
    fi
    repo_host_revalidate_root "$root_abs" "$root_inode_before"
    owner="$(repo_host_stat_owner "$root_abs")" || {
      fail "could not verify host ownership of exact pair repository $root after host-side normalization"
    }
  fi
  [ "$owner" = "${host_uid}:${host_gid}" ] \
    || fail "exact pair repository $root still has owner $owner after handback (expected ${host_uid}:${host_gid})"
}

repo_host() { # repo_host <name> [1|2|both]
  local name="$1" selector="${2:-both}"
  validate_name "$name"
  case "$selector" in
    1|2) repo_host_one "$name" "$selector" ;;
    both)
      # Validate both exact roots before mutating either one, so a malformed
      # peer path cannot leave a half-transition behind.
      local root
      for root in "siterepo/${name}1" "siterepo/${name}2"; do
        if [ -L "$root" ] || { [ -e "$root" ] && [ ! -d "$root" ]; }; then
          fail "pair '$name' repository root is not an ordinary directory: $root — refusing ownership handback"
        fi
      done
      repo_host_one "$name" 1
      repo_host_one "$name" 2
      ;;
    *) fail "repository side '$selector' invalid — expected 1, 2, or both" ;;
  esac
}

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
# cmd_reset's own clear_siterepo_root() empties them, which would delete the
# marker in the same breath that wrote it.
needs_install_marker() { # needs_install_marker <name> <side (1|2)> -> marker path
  printf 'siterepo/.%s%s.needs-install\n' "$1" "$2"
}

mark_sides_need_install() { # mark_sides_need_install <name>
  local name="$1" side
  # siterepo/ normally already exists by now; mkdir keeps this callable from
  # anywhere in reset's ordering without depending on that.
  mkdir -p siterepo
  for side in 1 2; do
    : > "$(needs_install_marker "$name" "$side")"
  done
}

clear_needs_install_markers() { # clear_needs_install_markers <name>
  local name="$1" side
  for side in 1 2; do
    rm -f -- "$(needs_install_marker "$name" "$side")"
  done
}

# --- live_pairs()/stopped_pairs() (compose-state discovery) are in
# lib/pair_compose.sh as pair_compose_live_pairs()/pair_compose_stopped_pairs() ---

wait_pair_visible() { # wait_pair_visible <name>
  local name="$1" live
  for _ in $(seq 1 60); do
    if ! live="$(pair_compose_live_pairs)"; then
      fail "could not verify pair '$name' became live after compose start"
    fi
    if printf '%s\n' "$live" | grep -Fqx -- "$name"; then
      return 0
    fi
    sleep 1
  done
  fail "pair '$name' did not become visible in Compose after start"
}

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

# DUO-3396: the pair a HELD host certification lock names is already budgeted.
#
# sandbox/tests/certify_reference_bundle.sh holds the per-host certification
# lock (DUO-3382) for one whole ~50-minute run, but it destroys and recreates
# ONE pair -- the pair it recorded in that lock -- once per leg. Every recreate
# re-enters the reservation below, so the default 14-leg bundle asks the host
# for its own pair thirteen times (every leg but the offline init-contract one;
# DUO-3427/DUO-3428 returned legs 13-14 to the certified set), and a leg that
# lands after other agents have filled the
# budget in between is refused ("refusing to bring up new pair ... over
# budget"), which ends the bundle in an immutable FAIL verdict after the legs
# it had already earned (observed live: leg 6, ~25 minutes of green burned).
# DUO_PAIR_BUDGET_OVERRIDE=1 is not the answer for a certification: nothing in
# the bundle detects the override today, so its manifest would affirmatively
# claim force_hatches:[] for a run whose budget WAS forced -- silently wrong
# evidence, which is worse than recorded-as-forced (DUO-3406 tracks making the
# bundle detect and record it).
#
# The slot was committed when the bundle started and the lock is the thing that
# says it still is, so while that lock is held the recorded pair counts as
# already budgeted. Two properties carry it, and BOTH fail open toward the
# ordinary refusal -- no record, an unreadable record, a different name, a lock
# nobody holds, or no way to ask: the budget then applies exactly as it did
# before this existed.
#
#   Exact name, never a pattern. The recorded name is compared literally; no
#   prefix, no glob, no `case`. DUO-3382 holds the same discipline for the same
#   reason (docs/agents/linear-loop.md's field note): a pattern matches
#   whatever merely CONTAINS the string, including every other agent's pair.
#
#   Held right now, decided by the kernel. holder.json is DUO-3382's NAMING
#   record, not its lock: it is written after the lock is taken, and a crashed
#   run's record outlives it until the next acquirer sweeps it. Trusting its
#   existence would hand a dead bundle a permanent reservation on this host --
#   a phantom that nothing would ever clear. So the question "is it held" goes
#   to the flock(2) itself, which the kernel drops the instant its holder stops
#   existing: an acquire that SUCCEEDS is proof that nobody held it.
#
# This is the read side of that lock and nothing else. It never creates the
# rendezvous, never writes or removes the record, and never keeps the
# descriptor. CERT_BUNDLE_LOCK_DIR is honored so the offline regression can
# drive a private rendezvous exactly as the bundle's own suite does, and the
# default is the same fixed literal certify_reference_bundle.sh commits to --
# both sides must name one path or there is nothing to be exempt from.
CERT_BUNDLE_LOCK_DIR="${CERT_BUNDLE_LOCK_DIR:-/tmp/duo-certbundle.lock}"

certbundle_lock_held() { # certbundle_lock_held <lock-file>; 0 = somebody holds it
  # SHARED (flock -s / LOCK_SH), not exclusive, and dropped the moment it is
  # taken. Two of these probes can race -- separate checkouts reserve against
  # separate budget locks -- and an exclusive probe would read its own twin as
  # "a bundle holds this" and grant a reservation nobody owns. A shared probe
  # can only ever be excluded by the bundle's own exclusive hold, which is the
  # single fact being asked about.
  #
  # The descriptor is read-only: this side must not create or truncate anything
  # in a rendezvous it does not own (flock(2) is indifferent to the open mode;
  # `9>` would truncate the very file the bundle's helper is holding). Only
  # flock's documented could-not-acquire status (1) is remapped to the "held"
  # signal 3; a usage error, a missing fd, an unsupported filesystem, a failed
  # redirection, or an flock that cannot run at all lands on any OTHER status
  # -- and every other status is doubt, not a holder (the python backend below
  # has the same shape: only BlockingIOError is a holder).
  local lock_file="$1" rc=0
  if command -v flock >/dev/null 2>&1; then
    ( s=0; flock -s -n 9 || s=$?   # `|| s=` keeps errexit from eating the status
      [ "$s" -eq 0 ] && exit 0     # we took it -- nobody held it
      [ "$s" -eq 1 ] && exit 3     # flock(1)'s could-not-acquire -- a holder has it
      exit 4                       # anything else is a tool failure, not a holder
    ) 9<"$lock_file" 2>/dev/null || rc=$?
  elif command -v python3 >/dev/null 2>&1; then
    # The same fcntl.flock fallback pair.sh's own budget lock and the bundle
    # both document for macOS/BSD hosts with no util-linux flock(1). Both
    # backends are flock(2) on one file, so they interoperate.
    python3 -c '
import fcntl, sys
handle = open(sys.argv[1])
try:
    fcntl.flock(handle, fcntl.LOCK_SH | fcntl.LOCK_NB)
except BlockingIOError:
    raise SystemExit(3)
raise SystemExit(0)
' "$lock_file" 2>/dev/null || rc=$?
  else
    return 1
  fi
  # Explicit returns: `set -e` is only suspended for a function called in a
  # condition, and this one must be safe to call anywhere.
  case "$rc" in
    3) return 0 ;;   # could not take it -- a live holder has it
    *) return 1 ;;   # 0 = we just took it, so nobody did; anything else = doubt
  esac
}

certbundle_reserved_pair() { # certbundle_reserved_pair <candidate>; 0 = pre-budgeted
  local candidate="$1" lock_file="$CERT_BUNDLE_LOCK_DIR/lock" recorded
  local holder_file="$CERT_BUNDLE_LOCK_DIR/holder.json"
  [ -n "$candidate" ] || return 1
  [ -f "$holder_file" ] && [ -f "$lock_file" ] || return 1
  # jq, the same reader certify_reference_bundle.sh's own
  # certbundle_lock_read_holder() uses, and already required by pair_compose_live_pairs()
  # above. A record that is absent, malformed, or carries a non-string pair is
  # simply not a reservation.
  recorded="$(jq -r 'if (.pair | type) == "string" then .pair else empty end' \
    "$holder_file" 2>/dev/null)" || return 1
  [ "$recorded" = "$candidate" ] || return 1
  certbundle_lock_held "$lock_file" || return 1
  return 0
}

reserve_pair_budget() { # reserve_pair_budget <candidate>; leaves lock held
  local candidate="$1" root live budget live_count candidate_live=0 total
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
  if ! budget="$(pair_budget)"; then
    budget_lock_release
    fail "could not query Docker host capacity; refusing without a verified budget"
  fi

  live_count="$(printf '%s\n' "$live" | awk 'NF {n++} END {print n+0}')"
  if [ -n "$candidate" ] && printf '%s\n' "$live" | grep -Fqx -- "$candidate"; then
    candidate_live=1
  fi
  total="$live_count"
  [ "$candidate_live" -eq 1 ] || total=$((total + 1))

  if [ "$total" -gt "$budget" ]; then
    warn ""
    warn "!! ${live_count} running pairs (budget for this host: ${budget} — 2 pairs per docker core, RAM-guarded; see pair_budget())"
    warn "!! pairs: $(printf '%s' "$live" | tr '\n' ' ')"
    warn "!! stop pairs you're not actively using (pair.sh stop <name>) or destroy finished ones"
    if [ -n "$candidate" ] && [ "$candidate_live" -eq 0 ]; then
      # Asked only here, in the branch that would otherwise refuse: an
      # in-budget `up` never reads the rendezvous at all, and the exemption
      # can only ever turn a refusal into the bring-up the certification lock
      # already reserved. Ahead of the override, and not interchangeable with
      # it: a slot that was RESERVED must be reported as reserved even when an
      # operator also happens to have the hatch set, or the run's own log says
      # its budget was forced when it was not (see this section's header on
      # what the bundle does and does not record today).
      if certbundle_reserved_pair "$candidate"; then
        warn "!! '$candidate' is the pair recorded by the HELD host certification lock ($CERT_BUNDLE_LOCK_DIR) — already budgeted for that run (DUO-3396), bringing it up at ${total}/${budget}"
      elif [ "${DUO_PAIR_BUDGET_OVERRIDE:-0}" = "1" ]; then
        warn "!! DUO_PAIR_BUDGET_OVERRIDE=1 set — bringing up '$candidate' ANYWAY, ${total}/${budget} over budget"
      else
        budget_lock_release
        fail "refusing to bring up new pair '$candidate' over budget (${total} > ${budget}); stop/destroy another pair first, or set DUO_PAIR_BUDGET_OVERRIDE=1 to proceed anyway"
      fi
    fi
  fi
}

# --- generic WordPress bootstrap (idempotent) -------------------------------

write_htaccess() { # write_htaccess <side (1|2)>
  # wp-cli can't write .htaccess without extra config; apache needs it for
  # pretty permalinks, and the HTTP_AUTHORIZATION line is required for
  # basic-auth REST — identical to every other script in this sandbox.
  "${PAIR_COMPOSE[@]}" exec -T -u www-data "wp$1" tee /var/www/html/.htaccess >/dev/null <<'EOF'
# BEGIN WordPress
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
RewriteBase /
RewriteRule ^index\.php$ - [L]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule . /index.php [L]
</IfModule>
# END WordPress
EOF
}

wait_db_ready() { # wait_db_ready <name> <side (1|2)>
  # THE readiness fix task #74 called for: a check that actually requires
  # the database to be reachable, run through this pair's own cli
  # container (so it also proves the network path and WORDPRESS_DB_* creds
  # are right) — not `wp core version`, which is a static-file read that
  # would happily report "ready" even if the database were unreachable.
  # `wp db query "SELECT 1"` works against a database that exists but has
  # zero tables yet (our exact state right after CREATE DATABASE, before
  # `core install` has run), unlike `wp db check` (mysqlcheck), which
  # checks tables and would have nothing to check yet.
  local name="$1" side="$2"
  for _ in $(seq 1 90); do
    if "${PAIR_COMPOSE[@]}" run --rm -T "cli${side}" wp db query "SELECT 1" >/dev/null 2>&1; then
      return 0
    fi
    sleep 2
  done
  fail "env ${name}${side} never reached its database"
}

wait_web_mountpoints() { # wait_web_mountpoints <name>
  # pair.yml mounts the shared wp-content tree and then mounts the Duo MU
  # directory/file underneath that named volume. Starting cli1/cli2 in the
  # same compose transaction races Docker's volume initialization: a CLI
  # container can try to create the nested file mountpoint while the first
  # web container is still populating the volume. The web services own that
  # initialization; wait until both can see the nested mounts before asking
  # Compose to create the CLI services.
  local name="$1" side all_ready mount_check
  mount_check='test -d /var/www/html/wp-content/mu-plugins/duo && test -f /var/www/html/wp-content/mu-plugins/duo-loader.php'
  for _ in $(seq 1 60); do
    all_ready=1
    for side in 1 2; do
      if ! "${PAIR_COMPOSE[@]}" exec -T "wp$side" sh -c "$mount_check" >/dev/null 2>&1; then
        all_ready=0
      fi
    done
    if [ "$all_ready" = 1 ]; then
      return 0
    fi
    sleep 1
  done
  fail "env ${name} web containers never exposed the nested Duo MU mountpoints"
}

install_and_activate_theme() { # install_and_activate_theme <cli service> <theme slug>
  local cli="$1" theme="$2" attempt active installed_version
  if [ "${PAIR_BOOTSTRAP_ARTIFACTS:-0}" = 1 ]; then
    local artifact
    artifact=$(fetch_artifact "$theme" "$PAIR_BOOTSTRAP_THEME_VERSION" "$cli" theme) \
      || fail "theme '$theme' exact pinned artifact is unavailable"
    "${PAIR_COMPOSE[@]}" run --rm -T "$cli" wp theme install "$artifact" --activate --force \
      || fail "theme '$theme' exact pinned artifact could not be installed and activated"
    active=$("${PAIR_COMPOSE[@]}" run --rm -T "$cli" wp theme list --status=active --field=name) \
      || fail "theme '$theme' active-theme readback failed after pinned install"
    [ "$active" = "$theme" ] \
      || fail "theme '$theme' pinned install completed but active theme was '${active:-none}'"
    installed_version=$("${PAIR_COMPOSE[@]}" run --rm -T "$cli" wp theme get "$theme" --field=version) \
      || fail "theme '$theme' version readback failed after pinned install"
    [ "$installed_version" = "$PAIR_BOOTSTRAP_THEME_VERSION" ] \
      || fail "theme '$theme' pinned install reported version '$installed_version', expected '$PAIR_BOOTSTRAP_THEME_VERSION'"
    return 0
  fi
  # A fresh pair is an evidence boundary, but WordPress.org is not: a
  # transient theme-directory lookup must not turn an otherwise healthy
  # conformance leg red. Keep the retry narrow and bounded, preserve every
  # WP-CLI diagnostic, and independently prove the requested theme is active
  # before allowing bootstrap to continue. `theme activate` makes a partial
  # install retry-safe without forcing or overwriting theme bytes.
  for attempt in 1 2 3; do
    if "${PAIR_COMPOSE[@]}" run --rm -T "$cli" wp theme install "$theme" --activate; then
      :
    elif "${PAIR_COMPOSE[@]}" run --rm -T "$cli" wp theme activate "$theme"; then
      printf 'Warning: theme %s was already installed after install attempt %s; activated the existing exact slug\n' \
        "$theme" "$attempt" >&2
    else
      if [ "$attempt" = 3 ]; then
        fail "theme '$theme' could not be installed and activated after 3 attempts"
      fi
      printf 'Warning: theme %s install/activation attempt %s/3 failed; retrying the exact slug\n' \
        "$theme" "$attempt" >&2
      sleep "$attempt"
      continue
    fi

    if ! active=$("${PAIR_COMPOSE[@]}" run --rm -T "$cli" wp theme list --status=active --field=name); then
      active=""
    fi
    if [ "$active" = "$theme" ]; then
      return 0
    fi
    if [ "$attempt" = 3 ]; then
      fail "theme '$theme' install completed but active theme was '${active:-none}' after 3 attempts"
    fi
    printf "Warning: theme %s attempt %s/3 did not leave the exact slug active (got '%s'); retrying\n" \
      "$theme" "$attempt" "${active:-none}" >&2
    sleep "$attempt"
  done
}

install_side() { # install_side <name> <side (1|2)> <url> <title>
  local name="$1" side="$2" url="$3" title="$4" cli="cli$2" marker forced=0
  marker="$(needs_install_marker "$name" "$side")"

  # DUO-3412: the marker beats the probe, and when it is present the probe is
  # not made at all — `reset` dropped this side's database, so `wp core
  # install` is the definitionally correct action and a TRUE from
  # `is-installed` here could only be wrong. With no marker this is
  # byte-for-byte the old idempotent behavior: one probe, skip on TRUE, so a
  # plain `up` (or an `up` after stop/start) on a healthy pair still never
  # reinstalls.
  if [ -e "$marker" ]; then
    forced=1
    echo "  side $side: $marker present — reset emptied wp_${name}${side}, so installing unconditionally (is-installed is not consulted across our own DROP)"
  elif "${PAIR_COMPOSE[@]}" run --rm -T "$cli" wp core is-installed >/dev/null 2>&1; then
    # The premise for THIS path is the probe that just established it. Asking
    # the identical question again at the bottom of the function would cost
    # another container per side per `up` and could not return new
    # information, so the skip path returns here.
    echo "  side $side already installed"
    return 0
  fi

  "${PAIR_COMPOSE[@]}" run --rm -T "$cli" wp core install \
    --url="$url" --title="$title" \
    --admin_user=admin --admin_password=admin \
    --admin_email=admin@example.test --skip-email
  # Cleared HERE, on the success of the install itself, rather than at the end
  # of the function: everything below is idempotent post-install configuration
  # that the no-marker path skips wholesale on a re-run anyway. If the theme
  # fetch (say) fails after core installed, keeping the marker would turn the
  # obvious recovery — re-run `up` — into a guaranteed "Error: WordPress is
  # already installed" refusal, i.e. a second, worse failure mode invented by
  # the fix for the first one. Narrow window by construction: the marker is
  # true exactly from reset's DROP until this line.
  if [ "$forced" = 1 ]; then
    rm -f -- "$marker"
  fi
  install_and_activate_theme "$cli" twentytwentyone
  "${PAIR_COMPOSE[@]}" run --rm -T "$cli" wp option update permalink_structure '/%postname%/'
  "${PAIR_COMPOSE[@]}" run --rm -T "$cli" wp rewrite flush
  write_htaccess "$side"
  echo "  side $side installed ($url)"

  # DUO-3412, DUO-3381/DUO-3391's premise-before-behavior family: assert the
  # premise the entire sweep is about to depend on, in the domain that owns
  # it. Every path that reaches this line just ran `core install`, and wp-cli
  # has more than one way to leave a database with no WordPress in it without
  # ever returning non-zero. Unasserted, the next thing to notice would be the
  # first seed's `wp_conf1` call, one manifest and several minutes later,
  # saying only "Error: The site you have requested is not installed" — a
  # message that names neither the pair, nor the side, nor the bootstrap.
  # Fail-closed and deliberately unretried: a `core install` that reports
  # success over a database that is still empty is not a flake to ride out,
  # and install_and_activate_theme above already owns the only genuinely
  # transient dependency in this function.
  "${PAIR_COMPOSE[@]}" run --rm -T "$cli" wp core is-installed >/dev/null 2>&1 \
    || fail "pair bootstrap premise failed: side $side (wp_${name}${side}) is not installed after install_side — the sweep would die at its first seed call with wp-cli's bare 'Error: The site you have requested is not installed'"
}

# --- subcommands -------------------------------------------------------------

cmd_up() {
  local name="${1:?usage: pair.sh up <name> <port1> <port2> [--journal] [--codebind <dir>] [--artifacts] [--wordpress-offline] [--http|--headless]}"
  local port1="${2:?up needs <port1>}"
  local port2="${3:?up needs <port2>}"
  shift 3
  local journal=0 codebind="" artifacts=0 wordpress_offline=0 http_mode=1
  while [ $# -gt 0 ]; do
    case "$1" in
      --journal) journal=1; shift ;;
      --codebind) codebind="${2:?--codebind needs a plugin directory name}"; shift 2 ;;
      --artifacts) artifacts=1; shift ;;
      --wordpress-offline) wordpress_offline=1; shift ;;
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

  # The typed artifact registry is mutation authority for the explicit
  # artifact-backed bootstrap. Validate its complete closed shape before the
  # budget lock, shared database, pair roots, or Docker are touched; a later
  # fetch must not be the first place an unknown role/key is discovered.
  if [ "$artifacts" = 1 ]; then
    validate_artifact_lock conformance/artifacts.lock.json \
      || fail "up: artifact lock is malformed; no pair resources were changed"
    PAIR_BOOTSTRAP_THEME_VERSION=$(jq -r '
      .themes.twentytwentyone | if type == "object" and length == 1 then keys[0] else empty end
    ' conformance/artifacts.lock.json)
    [[ "$PAIR_BOOTSTRAP_THEME_VERSION" =~ ^[0-9A-Za-z][0-9A-Za-z._-]*$ ]] \
      || fail "up: the pinned bootstrap-theme registry entry is missing or ambiguous; no pair resources were changed"
  else
    PAIR_BOOTSTRAP_THEME_VERSION=
  fi
  PAIR_BOOTSTRAP_ARTIFACTS="$artifacts"

  # Reserve the host budget before touching the shared DB, creating pair
  # schemas, or creating bind roots.  The reservation lock remains held
  # through web/CLI creation so a concurrent `up` cannot observe the same
  # pre-creation live-pair list and over-commit the host.
  arm_budget_up_cleanup
  reserve_pair_budget "$name"

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
  pair_compose_configure "$name" "${overlays[@]}"

  say "shared infra: MariaDB (duo-db) + duo-shared network"
  pair_db_ensure_up
  pair_db_ensure_app_user
  pass "shared db up, healthy, wordpress user granted on wp\\_%"

  say "pair '$name': databases"
  pair_db_create "$name"
  pass "wp_${name}1, wp_${name}2 exist"

  say "pair '$name': site-repo directories"
  prepare_siterepo_roots "$name"
  local force_recreate=()
  if [ -n "$codebind" ]; then
    # Bootstrap-order requirement inherited from spike G (see
    # pair.codebind.yml's header): the bind-mount SOURCE must exist,
    # host-owned, before any container that mounts it is created.
    mkdir -p "siterepo/${name}1/code/wp-content/plugins/${codebind}" \
             "siterepo/${name}2/code/wp-content/plugins/${codebind}"
    force_recreate=(--force-recreate)
  fi

  say "pair '$name': web containers up"
  # DUO-3277: agent/manifests bind-mount sources always resolve against
  # the canonical checkout (see pair_compose_configure()/canonical_root()), never
  # wherever this script itself was invoked from -- if that resolved
  # differently than whatever config an EXISTING container for this pair
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
  "${PAIR_COMPOSE[@]}" up -d "${force_recreate[@]}" wp1 wp2
  wait_web_mountpoints "$name"
  "${PAIR_COMPOSE[@]}" up -d cli1 cli2
  # Once both web and CLI containers exist, the pair is visible to the next
  # strict compose-list query. Release before the potentially long WP
  # install/bootstrap phase; EXIT/signal cleanup still protects failures
  # before this point.
  disarm_budget_up_cleanup
  pass "web mountpoints established; CLI containers up"

  say "pair '$name': waiting for DB-level readiness (both sides)"
  wait_db_ready "$name" 1
  wait_db_ready "$name" 2
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
  install_side "$name" 1 "$url1" "Duo ${name}1"
  install_side "$name" 2 "$url2" "Duo ${name}2"

  say "pair '$name' ready"
  if [ "$http_mode" = 1 ]; then
    echo "  wp1: $url1 (published on host port $port1)"
    echo "  wp2: $url2 (published on host port $port2)"
  else
    echo "  wp1: $url1 (headless — no host port published)"
    echo "  wp2: $url2 (headless — no host port published)"
  fi
  echo
  echo "  wp-cli invocation pattern for this pair (run from sandbox/):"
  echo "    docker compose -p duo-${name} -f pair.yml $( [ "$journal" = 1 ] && printf -- '-f pair.journal.yml ' )$( [ -n "$codebind" ] && printf -- '-f pair.codebind.yml ' )$( [ "$artifacts" = 1 ] && printf -- '-f pair.artifacts.yml ' )$( [ "$wordpress_offline" = 1 ] && printf -- '-f pair.wordpress-offline.yml ' )run --rm cli1 wp <command...>"
  echo "    docker compose -p duo-${name} -f pair.yml $( [ "$journal" = 1 ] && printf -- '-f pair.journal.yml ' )$( [ -n "$codebind" ] && printf -- '-f pair.codebind.yml ' )$( [ "$artifacts" = 1 ] && printf -- '-f pair.artifacts.yml ' )$( [ "$wordpress_offline" = 1 ] && printf -- '-f pair.wordpress-offline.yml ' )run --rm cli2 wp <command...>"
  echo "  (the journal/codebind -f flags only matter if the command you're running cares about DUO_JOURNAL or the bound plugin dir; DUO_PAIR=${name} must stay exported, or pass -p duo-${name} and set WORDPRESS_DB_NAME/etc. yourself)"
}

clear_siterepo_root() { # clear_siterepo_root <path>
  local root="$1"

  # Preserve the bind-root inode. Docker's default rprivate bind propagation
  # pins the directory that existed when a container was created; deleting
  # and recreating that directory makes a running ordinary web/site container
  # see stale content forever. Codebind pairs are refused separately because
  # their nested plugin inode would still be replaced. Remove only children
  # in place instead. A
  # pre-existing symlink/non-directory is not a valid disposable root and is
  # removed before the real directory is made.
  if [ -L "$root" ] || { [ -e "$root" ] && [ ! -d "$root" ]; }; then
    rm -rf -- "$root"
  fi
  mkdir -p -- "$root"
  chmod -R ugo+rwX "$root"
  find "$root" -mindepth 1 -maxdepth 1 -exec rm -rf -- {} +
  chmod 0777 "$root"
}

refuse_codebind_reset() { # refuse_codebind_reset <name>
  local name="$1" container mounts source destination existing

  # pair.codebind.yml adds a nested plugin bind source whose inode is pinned
  # independently of /siterepo/<name>. Clearing that nested directory in place
  # would still leave a running/stopped container attached to stale code, so
  # reset has an explicit fail-closed contract for codebind pairs. Destroy the
  # pair and bring it back with --codebind after the clean-room reset instead.
  if ! existing="$(docker ps -a --format '{{.Names}}' 2>/dev/null)"; then
    fail "could not enumerate pair containers before reset; refusing without a verified codebind check"
  fi
  for container in "duo-${name}-wp1-1" "duo-${name}-wp2-1" \
                   "duo-${name}-cli1-1" "duo-${name}-cli2-1"; do
    if ! printf '%s\n' "$existing" | grep -Fqx -- "$container"; then
      continue
    fi
    if ! mounts="$(docker inspect "$container" \
      --format '{{range .Mounts}}{{.Source}}{{"\t"}}{{.Destination}}{{"\n"}}{{end}}' \
      2>/dev/null)"; then
      fail "could not inspect existing pair container $container before reset; refusing without a verified codebind check"
    fi
    while IFS=$'\t' read -r source destination; do
      [ -n "${destination:-}" ] || continue
      case "$destination" in
        /var/www/html/wp-content/plugins/*)
          fail "pair '$name' has a codebind mount on $container ($source -> $destination); reset is refused because Docker pins that nested inode. Run 'pair.sh destroy $name' then 'pair.sh up $name <port1> <port2> --codebind <plugin-dir>'"
          ;;
      esac
    done <<< "$mounts"
  done
}

cmd_reset() {
  local name="${1:?usage: pair.sh reset <name>}"
  validate_name "$name"
  # DUO-3377: reset is a mutation (DROP/CREATE of both databases, plus the
  # site-repo clear below) and is what every conformance sweep runs FIRST, so
  # the gate has to sit ahead of it -- ahead of refuse_codebind_reset's docker
  # queries too, since the source question is answerable without them.
  assert_candidate_source reset
  refuse_codebind_reset "$name"
  # Refuse before DROP/CREATE if uid-33 descendants cannot be returned to the
  # host process that clears them. The helper preserves both bind-root inodes.
  repo_host "$name" both
  pair_db_ensure_up

  say "pair '$name': reset"
  pair_db_drop "$name"
  pair_db_create "$name"
  # DUO-3412: record the DROP for `up`, immediately after it and before
  # anything else here can fail. From this line on, both sides of this pair
  # are KNOWN uninstalled, and the next install_side must not re-derive that
  # from a probe it makes against the two databases these lines just emptied
  # (see needs_install_marker above). Written after the CREATE, never before
  # the DROP: a marker left behind by a reset that failed to drop anything
  # would force `wp core install` onto a site that is still installed.
  mark_sides_need_install "$name"
  clear_siterepo_root "siterepo/${name}1"
  clear_siterepo_root "siterepo/${name}2"
  rm -rf -- "siterepo/origin-${name}.git"
  prepare_siterepo_roots "$name"
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
  local name="${1:?usage: pair.sh stop <name>}"
  validate_name "$name"
  say "pair '$name': stop (free RAM/CPU; containers, volumes, databases all kept)"
  pair_compose_configure "$name"
  export DUO_PAIR="$name"
  "${PAIR_COMPOSE[@]}" stop
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
  local name="${1:?usage: pair.sh start <name>}"
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
  # Keep the existing resume contract: the shared MariaDB must be healthy
  # before a stopped pair is started. The reservation is already held, so a
  # concurrent up/start cannot over-commit while this prerequisite runs.
  pair_db_ensure_up
  check_dead_mounts "$name"
  say "pair '$name': start (state exactly as it was at stop)"
  pair_compose_configure "$name"
  export DUO_PAIR="$name"
  "${PAIR_COMPOSE[@]}" start
  wait_pair_visible "$name"
  disarm_budget_up_cleanup
  pass "running again — same ports/config as before the stop"
}

cmd_destroy() {
  local name="${1:?usage: pair.sh destroy <name>}"
  validate_name "$name"

  say "pair '$name': destroy"
  # Cleanup callers remove the pair roots after destroy. Return uid-33 capture
  # descendants first, while the exact cli mounts still exist and before any
  # container/volume/database mutation. Missing roots remain a no-op.
  repo_host "$name" both
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
  clear_needs_install_markers "$name"
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

  say "shared db"
  if docker inspect "$DB_CONTAINER" >/dev/null 2>&1; then
    echo "  ${DB_CONTAINER}: $(docker inspect -f '{{.State.Status}} ({{.State.Health.Status}})' "$DB_CONTAINER")"
  else
    echo "  ${DB_CONTAINER}: not running"
  fi
}

usage() {
  cat <<'USAGE'
usage:
  pair.sh up <name> <port1> <port2> [--journal] [--codebind <plugin-dir>] [--artifacts] [--wordpress-offline] [--http|--headless]
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

Names: lowercase letters/digits only, starting with a letter (no
hyphens/underscores) — used bare as both a MySQL database-name fragment
and a docker compose project suffix.

Environment:
  DUO_EXPECTED_SOURCE_SHA=<7-40 hex>
           DUO-3377's exact-source gate. up/reset/start always PRINT the
           agent/manifests bind-mount source they will use (path + HEAD);
           with this set they additionally REFUSE — before any database
           drop/create, site-repo write, or container create/start —
           unless that source is exactly this commit with no uncommitted
           agent/manifests changes. Bind every live evidence run with it
           (`DUO_EXPECTED_SOURCE_SHA=$(git rev-parse HEAD)`): mounts
           always resolve to the CANONICAL checkout (DUO-3277), so a run
           launched from an issue worktree otherwise silently exercises
           the canonical checkout's code. Unset = unchanged behavior.
           stop/destroy/list are deliberately ungated (teardown, not
           evidence). sandbox/conformance/run.sh passes it through as
           CONF_EXPECTED_SOURCE_SHA.
  DUO_PAIR_BUDGET_OVERRIDE=1
           bring a pair up/start it even when the host budget is exceeded.
  CERT_BUNDLE_LOCK_DIR=<dir>
           DUO-3382's host certification rendezvous, default
           /tmp/duo-certbundle.lock, READ ONLY here. While that lock is
           held, the exact pair name its holder.json records is already
           budgeted (DUO-3396: a bundle destroys and recreates that one
           pair per leg for ~50 minutes and must not lose the slot it
           reserved). Nothing else is exempt, and a released or crashed
           holder's record grants nothing — the flock, not the record,
           decides. Set it only in step with the bundle itself.
USAGE
}

case "${1:-}" in
  up)      shift; cmd_up "$@" ;;
  reset)   shift; cmd_reset "$@" ;;
  repo-host) shift; repo_host "$@" ;;
  stop)    shift; cmd_stop "$@" ;;
  start)   shift; cmd_start "$@" ;;
  destroy) shift; cmd_destroy "$@" ;;
  list)    shift; cmd_list "$@" ;;
  -h|--help|"") usage ;;
  *) echo "unknown subcommand '$1'" >&2; usage >&2; exit 1 ;;
esac
