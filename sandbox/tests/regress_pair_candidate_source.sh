#!/usr/bin/env bash
# Regression — DUO-3377: pair.sh's exact-source gate for live evidence.
#
# DUO-3277 made every pair's agent/manifests bind mounts resolve to the
# CANONICAL checkout through git's own common-dir, so a persistent pair can
# outlive the per-issue worktree that created it. The unintended consequence
# this suite pins down: a live regression or conformance sweep launched from
# an issue WORKTREE mounts the canonical checkout's bytes, not the candidate
# branch's, with nothing comparing the two — observed live during DUO-3316
# (worktree at 3ae1ea5, pair mounted canonical b69fdf; the stale-code
# warnings that produced read as a candidate regression for a day).
#
# Offline by construction: the fixture is a REAL pair of scratch git
# checkouts (a canonical repo at one commit plus a linked worktree at
# another, which is exactly the trap's shape) and a fake `docker` on PATH.
# Real git is deliberately NOT faked here — canonical_root(), the HEAD
# resolution, and the dirty-mount query are the mechanism under test, so they
# must run against genuine repositories. No Docker, no MariaDB, no
# WordPress, and nothing outside this suite's own temporary directory.
#
# What this proves, case by case:
#   - unset gate: the stale canonical source is mounted silently and the run
#     succeeds (the pre-DUO-3377 behavior, now at least PRINTED);
#   - gate set to the candidate commit: refusal BEFORE the budget lock, the
#     shared DB, the pair databases, the site-repo roots, and any container;
#   - gate set to the source's own commit: proceeds (a source assertion, not
#     a ban on worktrees);
#   - uncommitted agent/manifests bytes: refusal, same fail-closed point;
#   - `reset` (a sweep's first mutation) is gated ahead of DROP/CREATE;
#   - `start` verifies the source BAKED into existing containers, not what
#     canonical_root() resolves today, reading BOTH of them and refusing when
#     they disagree;
#   - DUO-3277's own dead-mount refusal (and its recovery text) still fires
#     for a vanished baked source, gate or no gate;
#   - an ungated run from a copy of pair.sh outside any checkout still works,
#     because the gate reports when it is off and never adds a failure mode;
#   - teardown (stop/destroy) stays ungated, so cleanup can never be blocked.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
# Physical path: git resolves the canonical checkout through its own
# common-dir, which is always symlink-resolved, while pair.sh's `pwd` is not.
# On macOS ${TMPDIR} is itself a symlink (/var -> /private/var), so a logical
# fixture root would compare a resolved path against an unresolved one.
TMP="$(cd "$(mktemp -d "${TMPDIR:-/tmp}/duo-pair-candidate-source.XXXXXX")" && pwd -P)"
trap 'rm -rf "$TMP"' EXIT
ORIGINAL_PATH="$PATH"
# DUO-3396: pair.sh's budget refusal now consults the host certification
# rendezvous (read-only) to see whether the candidate is the pair a HELD
# certification lock reserved. Point it at a path under this suite's own
# scratch that is never created, so these cases decide against a fixture
# instead of against whatever bundle happens to be running on this host.
export CERT_BUNDLE_LOCK_DIR="$TMP/no-certbundle-rendezvous"

say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*" >&2; exit 1; }

assert_file_contains() {
  local file="$1" needle="$2" message="$3"
  grep -F -- "$needle" "$file" >/dev/null || fail "$message (missing: $needle)"
}

assert_file_lacks() {
  local file="$1" needle="$2" message="$3"
  [ -f "$file" ] || return 0
  grep -F -- "$needle" "$file" >/dev/null && fail "$message (unexpected: $needle)"
  return 0
}

line_for() {
  local file="$1" needle="$2"
  grep -nF -- "$needle" "$file" | head -n 1 | cut -d: -f1 || true
}

assert_before() {
  local file="$1" first="$2" second="$3" first_line second_line
  first_line="$(line_for "$file" "$first")"
  second_line="$(line_for "$file" "$second")"
  [ -n "$first_line" ] || fail "missing first ordered line: $first"
  [ -n "$second_line" ] || fail "missing second ordered line: $second"
  [ "$first_line" -lt "$second_line" ] || fail "expected '$first' before '$second'"
}

copy_pair_launcher() { # copy_pair_launcher <sandbox-bin-dir>
  local bin_dir="$1"
  mkdir -p "$bin_dir/../lib"
  cp "$ROOT/sandbox/bin/pair.sh" "$bin_dir/pair.sh"
  cp "$ROOT/sandbox/lib/pair_identity.sh" "$bin_dir/../lib/pair_identity.sh"
  cp "$ROOT/sandbox/lib/pair_budget_lock.sh" "$bin_dir/../lib/pair_budget_lock.sh"
  cp "$ROOT/sandbox/lib/pair_db.sh" "$bin_dir/../lib/pair_db.sh"
  cp "$ROOT/sandbox/lib/pair_compose.sh" "$bin_dir/../lib/pair_compose.sh"
  cp "$ROOT/sandbox/lib/pair_readiness.sh" "$bin_dir/../lib/pair_readiness.sh"
  cp "$ROOT/sandbox/lib/pair_bootstrap.sh" "$bin_dir/../lib/pair_bootstrap.sh"
  cp "$ROOT/sandbox/lib/pair_siterepo.sh" "$bin_dir/../lib/pair_siterepo.sh"
}

# Every mutation pair.sh can perform before it reaches a container, expressed
# as evidence in the fake Docker log or on disk. A refusal case asserts ALL of
# them, which is what "refuses before pair mutation" has to mean concretely.
assert_no_pair_mutation() { # assert_no_pair_mutation <label> <case-root> <pair>
  local label="$1" case_root="$2" pair="$3"
  local log="$case_root/docker.log"
  assert_file_lacks "$log" 'docker <compose> <ls>' \
    "$label queried live pairs (budget reservation) before refusing"
  assert_file_lacks "$log" '<-p> <duo-db>' \
    "$label reached the shared DB before refusing"
  assert_file_lacks "$log" "<-p> <duo-$pair>" \
    "$label reached this pair's Compose project before refusing"
  assert_file_lacks "$log" 'docker <exec> <-i>' \
    "$label ran admin SQL (database drop/create) before refusing"
  assert_file_lacks "$log" 'docker <ps>' \
    "$label enumerated containers before refusing"
  [ ! -e "$case_root/canonical/sandbox/siterepo" ] \
    || fail "$label created the shared budget-lock directory before refusing"
  [ ! -e "$case_root/worktree/sandbox/siterepo/${pair}1" ] \
    || fail "$label created side-1 site state before refusing"
  [ ! -e "$case_root/worktree/sandbox/siterepo/${pair}2" ] \
    || fail "$label created side-2 site state before refusing"
  [ ! -e "$case_root/worktree/sandbox/.env" ] \
    || fail "$label wrote the canonical mount registry before refusing"
}

write_fake_docker() {
  local fake_bin="$1"
  cat > "$fake_bin/docker" <<'FAKE_DOCKER'
#!/usr/bin/env bash
set -euo pipefail
log="${DUO_PAIR_TEST_LOG:?}"
{
  printf 'docker'
  for arg in "$@"; do printf ' <%s>' "$arg"; done
  printf ' env[DUO_AGENT_SRC]=%s env[DUO_MANIFESTS_SRC]=%s\n' \
    "${DUO_AGENT_SRC:-}" "${DUO_MANIFESTS_SRC:-}"
} >> "$log"

if [ "${1:-}" = inspect ]; then
  # Two argument shapes, exactly as pair.sh writes them: `-f <format> <name>`
  # for the shared DB's health, and `<name> [--format <format>]` for the
  # per-pair existence probe and mount table.
  if [ "${2:-}" = -f ]; then
    case "${3:-}" in
      "{{.State.Health.Status}}") printf 'healthy\n' ;;
      "{{.State.Status}} ({{.State.Health.Status}})") printf 'running (healthy)\n' ;;
    esac
    exit 0
  fi
  # Container existence is configured per case. mounted_agent_source() probes
  # existence first and only then asks for the mount table, so both answers
  # come from the same variable: no configured mounts means no container.
  # wp2 can be given its OWN mount table, which is how a case expresses two
  # web containers baked against different checkouts.
  mounts="${DUO_PAIR_TEST_MOUNTS:-}"
  case "${2:-}" in
    *-wp2-1) mounts="${DUO_PAIR_TEST_MOUNTS_WP2:-$mounts}" ;;
  esac
  [ -n "$mounts" ] || exit 1
  if [ "${3:-}" = --format ]; then
    case "${4:-}" in
      # pair.sh asks two different mount questions: source+destination pairs
      # (mounted_agent_source) and bare sources (check_dead_mounts). Answering
      # both from one tab-separated fixture keeps a source path from ever
      # being mistaken for a "src<TAB>dest" string by the dead-mount probe.
      *Destination*) printf '%s\n' "$mounts" ;;
      *) printf '%s\n' "$mounts" | cut -f1 ;;
    esac
  fi
elif [ "${1:-}" = info ]; then
  case "${3:-}" in
    "{{.NCPU}}") printf '%s\n' "${DUO_PAIR_TEST_CPU:-8}" ;;
    "{{.MemTotal}}") printf '%s\n' "${DUO_PAIR_TEST_MEM:-8589934592}" ;;
  esac
elif [ "${1:-}" = ps ]; then
  printf '%s\n' "${DUO_PAIR_TEST_CONTAINERS:-}"
elif [ "${1:-}" = compose ] && [ "${2:-}" = ls ]; then
  if [ -n "${DUO_PAIR_TEST_LIVE_FILE:-}" ] && [ -f "$DUO_PAIR_TEST_LIVE_FILE" ]; then
    cat "$DUO_PAIR_TEST_LIVE_FILE"
  else
    printf '[]\n'
  fi
elif [ "${1:-}" = compose ]; then
  project="" has_start=0 previous=""
  for arg in "$@"; do
    [ "$previous" = -p ] && project="$arg"
    [ "$arg" = start ] && has_start=1
    previous="$arg"
  done
  # `start` waits for Compose visibility; publish the pair once it has run.
  if [ "$has_start" = 1 ] && [ -n "${DUO_PAIR_TEST_LIVE_FILE:-}" ]; then
    printf '[{"ConfigFiles":"/fake/pair.yml","Name":"%s"}]\n' "$project" > "$DUO_PAIR_TEST_LIVE_FILE"
  fi
fi
FAKE_DOCKER
  chmod +x "$fake_bin/docker"
}

git_scratch() { # git_scratch <repo> <args...> — host-config-independent git
  local repo="$1"; shift
  git -C "$repo" \
    -c user.name=duo -c user.email=duo@example.test \
    -c commit.gpgsign=false -c gc.auto=0 "$@"
}

# The trap's exact shape: ONE canonical checkout (primary worktree, "stale"
# commit) plus ONE linked worktree of the same repository at a different
# commit (the "candidate"). pair.sh copied into both, since the whole point is
# that running the worktree's own copy still mounts the canonical checkout.
# Sets CASE_ROOT/CANONICAL/WORKTREE/SHA_CANONICAL/SHA_CANDIDATE/FAKE_BIN/LOG.
build_fixture() { # build_fixture <label>
  local label="$1"
  CASE_ROOT="$TMP/$label"
  CANONICAL="$CASE_ROOT/canonical"
  WORKTREE="$CASE_ROOT/worktree"
  FAKE_BIN="$CASE_ROOT/fake-bin"
  LOG="$CASE_ROOT/docker.log"
  OUTPUT="$CASE_ROOT/output.log"
  mkdir -p "$CANONICAL/sandbox/bin" "$CANONICAL/agent" "$CANONICAL/manifests" "$FAKE_BIN"
  write_fake_docker "$FAKE_BIN"

  copy_pair_launcher "$CANONICAL/sandbox/bin"
  chmod +x "$CANONICAL/sandbox/bin/pair.sh"
  printf 'canonical (stale) agent bytes\n' > "$CANONICAL/agent/duo.php"
  printf '{"canonical":true}\n' > "$CANONICAL/manifests/demo.json"
  git -C "$CANONICAL" init -q -b main .
  git_scratch "$CANONICAL" add -A
  git_scratch "$CANONICAL" commit -qm "canonical checkout state"
  SHA_CANONICAL="$(git -C "$CANONICAL" rev-parse HEAD)"

  git_scratch "$CANONICAL" checkout -q -b candidate
  printf 'candidate agent bytes under test\n' > "$CANONICAL/agent/duo.php"
  git_scratch "$CANONICAL" add -A
  git_scratch "$CANONICAL" commit -qm "candidate fix under test"
  SHA_CANDIDATE="$(git -C "$CANONICAL" rev-parse HEAD)"
  git_scratch "$CANONICAL" checkout -q main
  git_scratch "$CANONICAL" worktree add -q "$WORKTREE" candidate

  [ "$SHA_CANONICAL" != "$SHA_CANDIDATE" ] \
    || fail "$label fixture is degenerate: canonical and candidate resolved to the same commit"
  [ -f "$WORKTREE/sandbox/bin/pair.sh" ] \
    || fail "$label fixture worktree has no pair.sh copy"
  [ -f "$WORKTREE/.git" ] \
    || fail "$label fixture worktree is not a LINKED worktree (its .git must be a file)"
}

# One pair.sh invocation from the WORKTREE's own copy — the launch shape the
# whole issue is about. DUO_EXPECTED_SOURCE_SHA is passed per call so an unset
# gate cannot leak in from a previous case.
run_pair() { # run_pair <expected-sha-or-empty> <subcommand> [args...]
  local expected="$1"; shift
  ( cd "$WORKTREE" \
    && env PATH="$FAKE_BIN:$ORIGINAL_PATH" \
       DUO_PAIR_TEST_LOG="$LOG" \
       DUO_PAIR_TEST_MOUNTS="${MOUNTS:-}" \
       DUO_PAIR_TEST_MOUNTS_WP2="${MOUNTS_WP2:-}" \
       DUO_PAIR_TEST_CONTAINERS="${CONTAINERS:-}" \
       DUO_PAIR_TEST_LIVE_FILE="${LIVE_FILE:-}" \
       DUO_PAIR_TEST_CPU=8 DUO_PAIR_TEST_MEM=8589934592 \
       DUO_EXPECTED_SOURCE_SHA="$expected" \
       bash "$WORKTREE/sandbox/bin/pair.sh" "$@" ) >"$OUTPUT" 2>&1
}

run_unset_gate_documents_stale_source_case() {
  local label=unset_gate_stale_source pair=stalesrc
  build_fixture "$label"

  # Prior (pre-DUO-3377) behavior, byte for byte: the run succeeds against
  # the canonical checkout's agent/manifests even though it was launched from
  # a worktree sitting on a different commit. It is now at least legible —
  # the source path and HEAD are printed — but nothing refuses, which is the
  # contract for every persistent-pair workflow DUO-3277 protects.
  run_pair "" up "$pair" 9911 9912 --headless \
    || { cat "$OUTPUT" >&2; fail "$label refused an ungated run (unset must stay unchanged behavior)"; }

  assert_file_contains "$LOG" "env[DUO_AGENT_SRC]=$CANONICAL/agent" \
    "$label did not mount the canonical agent source"
  assert_file_lacks "$LOG" "env[DUO_AGENT_SRC]=$WORKTREE/agent" \
    "$label mounted the worktree's own agent source"
  assert_file_contains "$LOG" '<up> <-d> <wp1> <wp2>' \
    "$label did not create the pair's web containers"
  assert_file_contains "$OUTPUT" "mounted source: $CANONICAL/{agent,manifests}" \
    "$label did not print the mounted source path"
  assert_file_contains "$OUTPUT" "source HEAD:    $SHA_CANONICAL" \
    "$label did not print the mounted source HEAD"
  assert_file_contains "$OUTPUT" "invoked from:   $WORKTREE/sandbox" \
    "$label did not print where this pair.sh copy ran from"
  assert_file_contains "$OUTPUT" 'expected SHA:   (DUO_EXPECTED_SOURCE_SHA unset' \
    "$label did not state that the run is not candidate-bound"
  assert_file_contains "$OUTPUT" "pair '$pair' ready" \
    "$label did not complete the ungated bootstrap"
  [ -d "$WORKTREE/sandbox/siterepo/${pair}1" ] \
    || fail "$label did not create side-1 site state on the ungated path"
  pass "$label: ungated behavior is unchanged — canonical bytes mounted from a worktree launch, now printed"
}

run_mismatch_refusal_case() {
  local label=mismatch_refusal pair=mismatch
  build_fixture "$label"

  # The DUO-3316 shape: an agent in the worktree asks for ITS commit's
  # evidence. The mounted source is the canonical checkout's older commit, so
  # this must refuse rather than produce a verdict about untested bytes.
  if run_pair "$SHA_CANDIDATE" up "$pair" 9911 9912 --headless; then
    cat "$OUTPUT" >&2
    fail "$label produced a pair (and therefore evidence) from a stale canonical source"
  fi

  assert_file_contains "$OUTPUT" 'candidate-source MISMATCH' \
    "$label did not name the mismatch"
  assert_file_contains "$OUTPUT" "expected (DUO_EXPECTED_SOURCE_SHA): $SHA_CANDIDATE" \
    "$label did not print the expected candidate SHA"
  assert_file_contains "$OUTPUT" "actual mounted source HEAD:         $SHA_CANONICAL" \
    "$label did not print the actual mounted source HEAD"
  assert_file_contains "$OUTPUT" "actual mounted source:              $CANONICAL/{agent,manifests}" \
    "$label did not print the actual mounted source path"
  assert_file_contains "$OUTPUT" "git clone --branch <branch> $CANONICAL" \
    "$label did not name the standalone-clone remedy"
  assert_no_pair_mutation "$label" "$CASE_ROOT" "$pair"
  pass "$label: a stale canonical source cannot yield candidate evidence, green or red"
}

run_expected_match_case() {
  local label=expected_match pair=matchsrc short
  build_fixture "$label"
  short="${SHA_CANONICAL:0:7}"

  # The gate asserts the SOURCE, it does not ban worktrees: naming the commit
  # the mount actually carries proceeds normally. Abbreviated SHAs are
  # accepted the way git accepts them everywhere else, so an operator can
  # paste a short SHA out of a log.
  run_pair "$short" up "$pair" 9911 9912 --headless \
    || { cat "$OUTPUT" >&2; fail "$label refused a run whose mounted source IS the expected commit"; }

  assert_file_contains "$OUTPUT" "mounted source is exactly $short, clean" \
    "$label did not confirm the bound source"
  assert_file_contains "$OUTPUT" "pair '$pair' ready" \
    "$label did not complete the gated bootstrap"
  assert_file_contains "$LOG" "env[DUO_AGENT_SRC]=$CANONICAL/agent" \
    "$label changed which source DUO-3277 binds"
  pass "$label: an expected-source match proceeds, abbreviated SHA included"
}

run_dirty_source_refusal_case() {
  local label=dirty_source pair=dirtysrc
  build_fixture "$label"
  printf 'uncommitted edit\n' >> "$CANONICAL/agent/duo.php"
  printf '{"untracked":true}\n' > "$CANONICAL/manifests/scratch.json"

  # Right commit, wrong bytes: the mount would carry edits no commit records,
  # so the evidence could never be reproduced from the SHA it claims.
  if run_pair "$SHA_CANONICAL" up "$pair" 9911 9912 --headless; then
    cat "$OUTPUT" >&2
    fail "$label produced a pair from uncommitted mount bytes"
  fi
  assert_file_contains "$OUTPUT" 'candidate source is DIRTY' \
    "$label did not name the dirty mount source"
  assert_file_contains "$OUTPUT" 'agent/duo.php' \
    "$label did not list the modified mounted file"
  assert_file_contains "$OUTPUT" 'manifests/scratch.json' \
    "$label did not list the untracked mounted file"
  # Every porcelain line carries the two-space indent, not just the first:
  # printf with one multi-line argument silently indents only line one.
  grep -qE '^  [ M?]{1,2} .*agent/duo\.php' "$OUTPUT" \
    || fail "$label did not indent the modified-file line"
  grep -qE '^  [ M?]{1,2} .*manifests/scratch\.json' "$OUTPUT" \
    || fail "$label did not indent the second dirty line (multi-line listing lost its indent)"
  assert_no_pair_mutation "$label" "$CASE_ROOT" "$pair"
  pass "$label: uncommitted agent/manifests bytes refuse before any mutation"
}

run_dirt_scoped_to_mounts_case() {
  local label=dirt_scope pair=dirtscope
  build_fixture "$label"
  printf 'unrelated in-flight work\n' > "$CANONICAL/README.md"
  printf 'another agent edit\n' > "$CANONICAL/sandbox/scratch.txt"

  # A shared canonical checkout is nearly always dirty SOMEWHERE (this script
  # itself writes sandbox/.env and sandbox/siterepo/ into it). Only the bytes
  # that get mounted decide the verdict, or the gate would be unusable.
  run_pair "$SHA_CANONICAL" up "$pair" 9911 9912 --headless \
    || { cat "$OUTPUT" >&2; fail "$label refused over dirt outside the mounted agent/manifests trees"; }
  assert_file_contains "$OUTPUT" "mounted source is exactly $SHA_CANONICAL, clean" \
    "$label did not accept a checkout whose dirt is all outside the mounts"
  pass "$label: dirtiness is scoped to the mounted agent/manifests trees"
}

run_reset_gate_case() {
  local label=reset_gate pair=resetgate
  build_fixture "$label"
  mkdir -p "$WORKTREE/sandbox/siterepo/${pair}1"
  printf 'must survive a refused reset\n' > "$WORKTREE/sandbox/siterepo/${pair}1/marker.txt"

  # `reset` is the FIRST thing every conformance sweep runs and it DROPs both
  # databases; gating only `up` would let a sweep destroy a pair's state on
  # behalf of a candidate whose bytes were never mounted.
  if run_pair "$SHA_CANDIDATE" reset "$pair"; then
    cat "$OUTPUT" >&2
    fail "$label reset a pair on behalf of a source that was never mounted"
  fi
  assert_file_contains "$OUTPUT" 'candidate-source MISMATCH' \
    "$label did not refuse the reset with the source mismatch"
  assert_file_contains "$OUTPUT" "candidate source for 'reset'" \
    "$label did not report the gate for the reset subcommand"
  grep -q 'must survive a refused reset' "$WORKTREE/sandbox/siterepo/${pair}1/marker.txt" \
    || fail "$label cleared site-repo state before refusing"
  assert_file_lacks "$LOG" 'docker <exec> <-i>' \
    "$label ran DROP/CREATE before refusing"
  assert_file_lacks "$LOG" '<-p> <duo-db>' \
    "$label reached the shared DB before refusing the reset"
  assert_file_lacks "$LOG" 'docker <ps>' \
    "$label ran reset's codebind preflight before the source gate"
  pass "$label: reset refuses ahead of DROP/CREATE and the site-repo clear"
}

run_start_baked_source_case() {
  local label=start_baked_source pair=startbaked
  build_fixture "$label"
  # A pair created BEFORE DUO-3277 shipped: its containers carry a worktree
  # path baked in at create time, which compose start reuses verbatim. The
  # honest answer to "what will this mount" is that baked path, not what
  # canonical_root() resolves now — so the gate must verify the former.
  MOUNTS="$WORKTREE/agent"$'\t/var/www/html/wp-content/mu-plugins/duo'
  CONTAINERS="duo-${pair}-wp1-1"
  LIVE_FILE="$CASE_ROOT/live.json"

  if run_pair "$SHA_CANONICAL" start "$pair"; then
    cat "$OUTPUT" >&2
    fail "$label started containers whose baked mount source is not the expected commit"
  fi
  assert_file_contains "$OUTPUT" 'candidate-source MISMATCH' \
    "$label did not refuse a start against a differently-baked source"
  assert_file_contains "$OUTPUT" 'baked into this pair'"'"'s existing containers' \
    "$label did not report that the verified source came from the containers"
  assert_file_contains "$OUTPUT" "actual mounted source HEAD:         $SHA_CANDIDATE" \
    "$label verified canonical_root() instead of the baked source"
  assert_file_lacks "$LOG" '<start>' "$label started containers before refusing"
  assert_file_lacks "$LOG" 'docker <compose> <ls>' \
    "$label reserved budget before refusing the start"

  # Same baked source, now named correctly: start proceeds, and the printed
  # source is the baked path rather than the canonical one.
  run_pair "$SHA_CANDIDATE" start "$pair" \
    || { cat "$OUTPUT" >&2; fail "$label refused a start whose baked source IS the expected commit"; }
  assert_file_contains "$OUTPUT" "mounted source: $WORKTREE/{agent,manifests}" \
    "$label did not print the baked mount source on the accepted start"
  assert_file_contains "$LOG" "<-p> <duo-$pair> <-f> <pair.yml> <start>" \
    "$label did not resume the pair after the gate passed"
  MOUNTS=""; CONTAINERS=""; LIVE_FILE=""
  pass "$label: start verifies the source baked into its containers, both ways"
}

run_dead_baked_mount_case() {
  local label=dead_baked_mount pair=deadmount
  build_fixture "$label"
  # DUO-3277's own hazard, unchanged by this gate: the checkout a pair's
  # containers were created against is simply gone. The gate must report what
  # it can and then get out of the way — check_dead_mounts still owns this
  # refusal, and its recovery instructions must still be what an operator
  # sees.
  MOUNTS="$CASE_ROOT/removed-worktree/agent"$'\t/var/www/html/wp-content/mu-plugins/duo'
  LIVE_FILE="$CASE_ROOT/live.json"

  if run_pair "" start "$pair"; then
    cat "$OUTPUT" >&2
    fail "$label started a pair whose baked bind-mount source no longer exists"
  fi
  assert_file_contains "$OUTPUT" 'has a dead bind-mount source' \
    "$label lost DUO-3277's dead-mount refusal"
  assert_file_contains "$OUTPUT" "recovery: run \"pair.sh up $pair" \
    "$label lost DUO-3277's dead-mount recovery instructions"
  assert_file_contains "$OUTPUT" 'source HEAD:    <none' \
    "$label did not report honestly that the vanished source has no HEAD"
  assert_file_lacks "$LOG" '<start>' "$label resumed containers on a dead mount"

  # With the gate on, the same pair refuses EARLIER and for its own reason:
  # a source with no resolvable HEAD can never be the expected commit.
  if run_pair "$SHA_CANDIDATE" start "$pair"; then
    cat "$OUTPUT" >&2
    fail "$label accepted a vanished source under the candidate-source gate"
  fi
  assert_file_contains "$OUTPUT" 'the mounted source has no resolvable HEAD' \
    "$label did not refuse a vanished source at the gate"
  assert_file_lacks "$LOG" '<start>' "$label resumed containers under the gate"
  MOUNTS=""; LIVE_FILE=""
  pass "$label: DUO-3277's dead-mount protection is intact, and the gate refuses earlier still"
}

run_disagreeing_baked_sources_case() {
  local label=disagreeing_baked_sources pair=disagree
  build_fixture "$label"
  # wp1 and wp2 baked against DIFFERENT checkouts. Compose cannot produce this
  # from one pair.yml today, which is exactly why reading only wp1 would be a
  # trap rather than an optimization: there is no single answer to "which code
  # does this pair run", so the only honest outcome is a refusal naming both.
  MOUNTS="$CANONICAL/agent"$'\t/var/www/html/wp-content/mu-plugins/duo'
  MOUNTS_WP2="$WORKTREE/agent"$'\t/var/www/html/wp-content/mu-plugins/duo'
  CONTAINERS="duo-${pair}-wp1-1"
  LIVE_FILE="$CASE_ROOT/live.json"

  if run_pair "$SHA_CANONICAL" start "$pair"; then
    cat "$OUTPUT" >&2
    fail "$label started a pair whose two web containers mount different sources"
  fi
  assert_file_contains "$OUTPUT" 'DISAGREEING agent bind-mount sources' \
    "$label did not refuse containers baked against different checkouts"
  assert_file_contains "$OUTPUT" "duo-${pair}-wp1-1: $CANONICAL/agent" \
    "$label did not name the wp1 baked source"
  assert_file_contains "$OUTPUT" "duo-${pair}-wp2-1: $WORKTREE/agent" \
    "$label did not name the wp2 baked source"
  assert_file_lacks "$LOG" '<start>' "$label resumed containers before refusing"
  assert_file_lacks "$LOG" 'docker <compose> <ls>' \
    "$label reserved budget before refusing"

  # Deliberately ungated, and the ONE place this issue's changes refuse
  # without DUO_EXPECTED_SOURCE_SHA: a pair whose two containers mount
  # different code is broken whether or not the run is candidate-bound, which
  # is the same judgement check_dead_mounts already makes on `start` for the
  # structurally identical "baked mount is incoherent" case.
  if run_pair "" start "$pair"; then
    cat "$OUTPUT" >&2
    fail "$label started disagreeing containers once the gate was unset"
  fi
  assert_file_contains "$OUTPUT" 'DISAGREEING agent bind-mount sources' \
    "$label made an incoherent pair's refusal depend on the gate being set"
  assert_file_lacks "$LOG" '<start>' "$label resumed containers on the ungated path"
  MOUNTS=""; MOUNTS_WP2=""; CONTAINERS=""; LIVE_FILE=""
  pass "$label: both web containers are read, and disagreement refuses instead of picking one"
}

run_non_git_copy_case() {
  local label=non_git_copy pair=nongitcopy
  local case_root="$TMP/$label" fake_bin="$TMP/$label/fake-bin"
  local log="$TMP/$label/docker.log" output="$TMP/$label/output.log"
  mkdir -p "$case_root/sandbox/bin" "$case_root/sandbox/siterepo/${pair}1" \
           "$case_root/sandbox/siterepo/${pair}2" "$fake_bin"
  copy_pair_launcher "$case_root/sandbox/bin"
  chmod +x "$case_root/sandbox/bin/pair.sh"
  write_fake_docker "$fake_bin"

  # `reset` never needed Git: it only touches databases and site-repo
  # directories relative to its own cwd. An ungated run from a copy of this
  # script outside any checkout therefore has to keep working exactly as it
  # did — the gate REPORTS when it is off, it never adds a failure mode. (This
  # regressed once during DUO-3377: an unconditional refusal here turned
  # regress_pair_bootstrap_unit.sh's reset_codebind_refusal case into a
  # source-resolution error instead of its codebind refusal.)
  ( cd "$case_root/sandbox" \
    && env PATH="$fake_bin:$ORIGINAL_PATH" DUO_PAIR_TEST_LOG="$log" \
       DUO_PAIR_TEST_MOUNTS='' DUO_PAIR_TEST_CONTAINERS='' DUO_PAIR_TEST_LIVE_FILE='' \
       DUO_PAIR_TEST_CPU=8 DUO_PAIR_TEST_MEM=8589934592 DUO_EXPECTED_SOURCE_SHA='' \
       bash "$case_root/sandbox/bin/pair.sh" reset "$pair" ) >"$output" 2>&1 \
    || { cat "$output" >&2; fail "$label: an ungated reset outside a Git checkout stopped working"; }
  assert_file_contains "$output" 'mounted source: <unresolvable' \
    "$label did not report the unresolvable source honestly"
  assert_file_contains "$output" 'dropped + recreated empty' \
    "$label did not complete the reset it was always able to complete"

  # With the gate ON the same run must refuse: a run that names an exact
  # commit cannot proceed against a source nothing can identify.
  ( cd "$case_root/sandbox" \
    && env PATH="$fake_bin:$ORIGINAL_PATH" DUO_PAIR_TEST_LOG="$log" \
       DUO_PAIR_TEST_MOUNTS='' DUO_PAIR_TEST_CONTAINERS='' DUO_PAIR_TEST_LIVE_FILE='' \
       DUO_PAIR_TEST_CPU=8 DUO_PAIR_TEST_MEM=8589934592 \
       DUO_EXPECTED_SOURCE_SHA=0123456789abcdef0123456789abcdef01234567 \
       bash "$case_root/sandbox/bin/pair.sh" reset "$pair" ) >"$output" 2>&1 \
    && { cat "$output" >&2; fail "$label: a gated reset proceeded against an unidentifiable source"; }
  assert_file_contains "$output" 'cannot be identified' \
    "$label did not refuse a gated run against an unidentifiable source"
  pass "$label: the ungated path adds no failure mode; the gated path still refuses closed"
}

run_malformed_expected_case() {
  local label=malformed_expected pair=badsha
  build_fixture "$label"

  # A branch name or `HEAD` is not a commit identity this gate can honor
  # without resolving it in some repository — and the repository it would be
  # resolved in is exactly what is in question. Refuse instead of guessing.
  if run_pair "candidate" up "$pair" 9911 9912 --headless; then
    cat "$OUTPUT" >&2
    fail "$label accepted a non-SHA candidate-source value"
  fi
  assert_file_contains "$OUTPUT" 'DUO_EXPECTED_SOURCE_SHA must be a 7-40 character hex commit SHA' \
    "$label did not explain the required value shape"
  assert_no_pair_mutation "$label" "$CASE_ROOT" "$pair"
  pass "$label: a non-SHA gate value refuses before any mutation"
}

run_teardown_ungated_case() {
  local label=teardown_ungated pair=teardown
  build_fixture "$label"

  # Teardown is never evidence, and a gate variable left exported in an
  # agent's shell must never be able to strand a pair on the host.
  run_pair 0000000000000000000000000000000000000000 stop "$pair" \
    || { cat "$OUTPUT" >&2; fail "$label blocked 'stop' behind the candidate-source gate"; }
  assert_file_lacks "$OUTPUT" 'candidate source for' \
    "$label ran the source gate for a teardown subcommand"
  assert_file_contains "$LOG" "<-p> <duo-$pair> <-f> <pair.yml> <stop>" \
    "$label did not stop the pair"

  run_pair 0000000000000000000000000000000000000000 destroy "$pair" \
    || { cat "$OUTPUT" >&2; fail "$label blocked 'destroy' behind the candidate-source gate"; }
  assert_file_contains "$LOG" "<-p> <duo-$pair> <-f> <pair.yml> <down> <-v> <--remove-orphans>" \
    "$label did not destroy the pair"
  pass "$label: stop/destroy stay ungated so cleanup can never be blocked"
}

run_conformance_passthrough_case() {
  local label=conformance_passthrough run_sh="$ROOT/sandbox/conformance/run.sh"

  # Source-text check, not a live sweep: run.sh's own gate contribution is
  # exactly "map CONF_EXPECTED_SOURCE_SHA onto pair.sh's variable, before the
  # first pair.sh call". Both halves matter — an export placed after `pair.sh
  # reset` would gate nothing that reset already destroyed.
  assert_file_contains "$run_sh" 'export DUO_EXPECTED_SOURCE_SHA="$CONF_EXPECTED_SOURCE_SHA"' \
    "$label: run.sh does not plumb CONF_EXPECTED_SOURCE_SHA through to pair.sh"
  assert_before "$run_sh" \
    'export DUO_EXPECTED_SOURCE_SHA="$CONF_EXPECTED_SOURCE_SHA"' \
    'bash bin/pair.sh reset "$CONF_PAIR"'
  assert_before "$run_sh" \
    'export DUO_EXPECTED_SOURCE_SHA="$CONF_EXPECTED_SOURCE_SHA"' \
    'bash bin/pair.sh up "$CONF_PAIR"'
  pass "$label: conformance binds its sweep before the first pair mutation"
}

say "bash syntax checks"
bash -n "$ROOT/sandbox/bin/pair.sh" "$ROOT/sandbox/lib/pair_identity.sh" "$ROOT/sandbox/lib/pair_budget_lock.sh" "$ROOT/sandbox/lib/pair_db.sh" "$ROOT/sandbox/lib/pair_compose.sh" "$ROOT/sandbox/lib/pair_readiness.sh" "$ROOT/sandbox/lib/pair_bootstrap.sh" "$ROOT/sandbox/lib/pair_siterepo.sh" "$ROOT/sandbox/conformance/run.sh" \
  "$ROOT/sandbox/tests/regress_pair_candidate_source.sh"
command -v git >/dev/null 2>&1 || fail "git is required for the linked-worktree fixture"
assert_file_contains "$ROOT/sandbox/bin/pair.sh" 'source "lib/pair_identity.sh"' \
  'pair launcher no longer loads its pair-identity library'
assert_file_contains "$ROOT/sandbox/lib/pair_identity.sh" 'pair_identity_canonical_root()' \
  'pair-identity library no longer owns canonical checkout resolution'
assert_file_contains "$ROOT/sandbox/lib/pair_identity.sh" 'pair_identity_validate_name()' \
  'pair-identity library no longer owns safe pair namespace validation'
assert_file_contains "$ROOT/sandbox/bin/pair.sh" '  pair_identity_canonical_root' \
  'canonical_root compatibility facade no longer delegates to pair identity'
assert_file_contains "$ROOT/sandbox/bin/pair.sh" '  pair_identity_validate_name "$1"' \
  'validate_name compatibility facade no longer delegates to pair identity'
pass "pair launcher, identity library, conformance runner, and this regression parse cleanly"

say "unset gate: stale canonical source is mounted and printed (prior behavior)"
run_unset_gate_documents_stale_source_case

say "expected candidate SHA vs stale canonical mount: refusal before any mutation"
run_mismatch_refusal_case

say "expected SHA equal to the mounted source: proceeds"
run_expected_match_case

say "uncommitted mounted bytes: refusal before any mutation"
run_dirty_source_refusal_case

say "unrelated dirt outside agent/manifests: still proceeds"
run_dirt_scoped_to_mounts_case

say "reset is gated ahead of DROP/CREATE"
run_reset_gate_case

say "start verifies the source baked into existing containers"
run_start_baked_source_case

say "DUO-3277's dead-mount protection survives the gate"
run_dead_baked_mount_case

say "web containers baked against different sources refuse instead of picking one"
run_disagreeing_baked_sources_case

say "an ungated run from a non-Git copy keeps working; a gated one refuses"
run_non_git_copy_case

say "malformed DUO_EXPECTED_SOURCE_SHA refuses closed"
run_malformed_expected_case

say "teardown subcommands are deliberately ungated"
run_teardown_ungated_case

say "conformance run.sh plumbs CONF_EXPECTED_SOURCE_SHA before its first pair.sh call"
run_conformance_passthrough_case

printf '\n\033[1;32m✔ REGRESS_PAIR_CANDIDATE_SOURCE PASSED\033[0m\n'
