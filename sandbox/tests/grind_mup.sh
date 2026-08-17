#!/usr/bin/env bash
# Minimum-Usable-Platform end-to-end grind (round-3 MUP §6.1).
#
# The thirteen steps of the proposal's acceptance table, run once, in order,
# against ONE dedicated `sandbox/bin/pair.sh` pair — side 1 the author, side 2
# both the rehearsal preview and the release target. It is the only test in
# this estate that drives the whole customer loop the round exists to ship:
#
#   assess -> contract -> rehearse -> capture/merge -> release + verify -> recover
#
# Step 11 is the point of the grind, and the reason it is a gate rather than a
# demo: it is thesis test #3 (live-writer recovery) and #10 (recovery claims
# are literal) executed against a real target. A runtime row written AFTER the
# checkpoint either survives or does not, and whichever it does must be what
# the printed `maximum_loss_boundary` said, word for word. The claim is
# literal or this test fails.
#
# ## Shape
#
# Deliberately the proven shape of `sandbox/conformance/run.sh` and
# `sandbox/tests/grind_code_half.sh`, not a new one: `pair.sh reset` + `up`
# with `--http --artifacts`, pinned plugin/theme artifacts through
# `sandbox/bin/fetch-artifact.sh`, a bare origin plus two clones under
# `sandbox/siterepo/`, and an exit trap that destroys exactly this pair and
# removes exactly its two site-repo roots. Nothing here touches another
# agent's pair, and the pair NAME is parameterized for the same reason
# run.sh's is: two writers on one pair produce false failures.
#
#   MUP_PAIR   (default mup)    pair.sh pair name; grammar [a-z][a-z0-9]*
#   MUP_PORT1  (default 9400)   published host port for side 1
#   MUP_PORT2  (default 9401)   published host port for side 2
#   MUP_KEEP=1                  leave the pair and the site repos in place
#   MUP_WOO_VERSION             pinned WooCommerce version (default 11.0.0)
#   MUP_THEME_SLUG/_VERSION     pinned storefront theme (see the preflight)
#   MUP_BOOTSTRAP=init|manual   how step 2 creates the site repository
#   MUP_STEP11=required|record-gap   see "The step-11 transport gate" below
#   MUP_JOURNEY_SHOP_URL        the shop journey's path (default /shop/)
#   DUO_EXPECTED_SOURCE_SHA     bind this run to an exact source commit
#   DUO_WORDPRESS_ORG_OFFLINE   0|1, forwarded to pair.sh/fetch-artifact
#
# ## Offline modes
#
#   bash sandbox/tests/grind_mup.sh --self-check
#       Run every pure bash/jq helper in this file against the recorded
#       documents in sandbox/tests/fixtures/mup/ — the PASS fixtures built by
#       the shipped builders (AssessReport, AuthorizationPlan, RecoveryClaim,
#       JourneyOracle, CheckpointCatalog) and one hand-mutated FAIL fixture
#       per helper. No docker, no pair, no network. A helper that cannot fail
#       proves nothing about the run that trusts it, so every helper has both.
#
#   bash sandbox/tests/grind_mup.sh --dry-run
#       Run --self-check, then walk all thirteen steps printing the exact
#       argv of every external command with its arguments already resolved,
#       executing none of them. This is the plan, not a replay: the step
#       bodies below are the single source of both, because every external
#       call goes through `run`/`capture`, which print instead of executing
#       when DRY_RUN=1.
#
# ## The step-11 transport gate
#
# `duo recover` drives the adopted rollback-authority runtime, and
# `RecoverCommand::authorityTransport()` accepts an SSH transport and nothing
# else — every other transport gets the typed refusal
# `recovery_authority_unavailable`. `sandbox/bin/pair.sh` publishes no sshd, so
# on this pair `duo recover mup2 --list` refuses by construction. That is a
# real gap between MUP §6.1's grind and MUP §2.5's verb, not a defect in this
# script, and it is not papered over:
#
#   MUP_STEP11=required   (default) step 11 runs exactly as §6.1 writes it and
#                         FAILS, naming the gap, if `duo recover --list` cannot
#                         list the checkpoint the release just took.
#   MUP_STEP11=record-gap steps 1-10 and 12-13 still produce evidence; step 11
#                         asserts the refusal is the typed, named one and that
#                         the frozen plan's claim is literal, then prints
#                         THESIS GATE NOT EXECUTED on stdout and stderr and in
#                         the final summary. It is not a pass of step 11 and
#                         says so three times.
#
# Bash + docker + jq + php. Never `make`; never another agent's pair.
set -euo pipefail
cd "$(dirname "$0")/.."   # -> sandbox/

# fd 3 is the plan/report stream. It stays attached to the script's real
# stdout so `--dry-run` can narrate from inside a `$(...)` capture without the
# narration becoming the captured value.
exec 3>&1

SANDBOX="$(pwd -P)"
REPO_ROOT="$(cd .. && pwd -P)"
DUO="$REPO_ROOT/cli/duo"
FIXTURES="$SANDBOX/tests/fixtures/mup"

MODE=run
for arg in "$@"; do
  case "$arg" in
    --self-check) MODE=self-check ;;
    --dry-run) MODE=dry-run ;;
    *) printf 'usage: grind_mup.sh [--self-check|--dry-run]\n' >&2; exit 2 ;;
  esac
done
DRY_RUN=0
[ "$MODE" = dry-run ] && DRY_RUN=1

PAIR="${MUP_PAIR:-mup}"
PORT1="${MUP_PORT1:-9400}"
PORT2="${MUP_PORT2:-9401}"
WOO_VERSION="${MUP_WOO_VERSION:-11.0.0}"
THEME_SLUG="${MUP_THEME_SLUG:-storefront}"
THEME_VERSION="${MUP_THEME_VERSION:-}"
BOOTSTRAP="${MUP_BOOTSTRAP:-init}"
STEP11="${MUP_STEP11:-required}"
WORDPRESS_OFFLINE="${DUO_WORDPRESS_ORG_OFFLINE:-0}"

FAILURES=0
STEP11_EXECUTED=0
PAIR_UP=0
SCRATCH=""

say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*" >&3; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*" >&3; }
note() { printf '\033[1;33mnote: %s\033[0m\n' "$*" >&3; }
plan() { printf '    $ %s\n' "$*" >&3; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*" >&2; exit 1; }
soft_fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*" >&2; FAILURES=$((FAILURES + 1)); }

dry() { [ "$DRY_RUN" = 1 ]; }

# quoted <argv...> — the argv as a shell would have to be given it. `--dry-run`
# is only useful if what it prints can be pasted, so the plan carries the real
# word boundaries rather than a space-joined approximation.
quoted() { local out="" arg; for arg in "$@"; do out+="${out:+ }$(printf '%q' "$arg")"; done; printf '%s' "$out"; }

# run <argv...> — execute, or (under --dry-run) print the resolved argv.
run() {
  if dry; then plan "$(quoted "$@")"; return 0; fi
  "$@"
}

# run_in <dir> <argv...> — the same, in a subshell rooted at <dir>. `duo
# assess`/`contract`/`release` resolve the LOCAL site repository from the
# current working directory (AssessCommand::siteRepo()), so which directory
# they run in is part of the command, not an accident of the shell.
run_in() {
  local dir="$1"; shift
  if dry; then plan "(cd $(printf '%q' "$dir") && $(quoted "$@"))"; return 0; fi
  ( cd "$dir" && "$@" )
}

# dry_set <var> <placeholder> — give a captured variable a readable stand-in
# under --dry-run, so later steps print resolved arguments rather than blanks.
dry_set() {
  if dry; then printf -v "$1" '%s' "$2"; fi
}

require() { command -v "$1" >/dev/null 2>&1 || fail "required command is missing: $1"; }

# ---------------------------------------------------------------------------
# Pure helpers. Every one of these is exercised by --self-check against a
# recorded PASS document and a recorded FAIL document; none of them runs a
# command, reads the network, or knows about docker.
# ---------------------------------------------------------------------------

# mup_json_tail <file> — the trailing canonical JSON document.
#
# `duo release --plan-only --format=json` prints the rendered authorization
# page and THEN the document (ReleaseCommand::run()), so a bare `jq .` over
# the whole stream fails. `\Duo\Canon::encode()` is pretty-printed, which puts
# the document's opening brace alone on a line at column 0 and puts every
# nested object's brace after a `"key": `, so the LAST bare `{` line is the
# start of the last document and nothing else can be.
mup_json_tail() {
  local file="$1" start
  start="$(grep -n '^{$' -- "$file" | tail -1 | cut -d: -f1 || true)"
  [ -n "$start" ] || { printf 'no canonical JSON document found in %s\n' "$file" >&2; return 1; }
  tail -n "+$start" -- "$file"
}

# mup_assert_no_internal_ids <file> <label> — MUP §5.2 as a mechanical gate.
#
# A human view may print an internal identifier only when a documented command
# consumes it. Nothing consumes an operation id, a session id, a lease owner
# or an artifact hash from `duo assess`, so none of the three shapes those
# take may appear: a 36-character UUID, a bare 64-hex digest, or a bare 32-hex
# digest. `sha256:`-prefixed digests are matched by the 64-hex rule too, which
# is deliberate — assess names `--format=json` on every line that hides one.
mup_assert_no_internal_ids() {
  local file="$1" label="$2" hit
  hit="$(grep -nEo '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}' -- "$file" | head -3 || true)"
  [ -z "$hit" ] || { printf '%s leaks a UUID into the human view: %s\n' "$label" "$hit" >&2; return 1; }
  # 32 or MORE, so a 64-hex sha256 (with or without its `sha256:` prefix) is
  # caught by the same rule rather than sliding under a fixed-width one.
  hit="$(grep -nEo '(^|[^0-9a-fA-F])[0-9a-fA-F]{32,}([^0-9a-fA-F]|$)' -- "$file" | head -3 || true)"
  [ -z "$hit" ] || { printf '%s leaks a hex identifier into the human view: %s\n' "$label" "$hit" >&2; return 1; }
  return 0
}

# mup_assess_projection <json-file> <surface-id> <operation> — the six §1
# dimensions of one row, tab-separated, in MUP §2.1's column order.
mup_assess_projection() {
  local file="$1" id="$2" operation="$3"
  jq -er --arg id "$id" --arg op "$operation" '
    (.surfaces[] | select(.id == $id)) as $row
    | ($row.operations[$op] // error("surface \($id) has no \($op) projection")) as $p
    | [$row.state_class, $row.handling, $p.readiness,
       $p.certification_provenance, $p.effect_containment, $p.effect_recovery_semantics]
    | @tsv
  ' -- "$file"
}

# mup_assert_assess_document <json-file> — the §6.1 step 3 assertions that are
# facts about the DOCUMENT rather than about one row: it is the right format,
# and every unclassified surface carries a real gap action. `nothing —
# supported` is a member of the closed set and is exactly the answer an
# unclassified row may never give.
mup_assert_assess_document() {
  local file="$1"
  jq -e '.format == "duo-assess-report/v1"' -- "$file" >/dev/null \
    || { printf 'not a duo-assess-report/v1 document: %s\n' "$file" >&2; return 1; }
  local offenders
  offenders="$(jq -r '
    [.surfaces[] | select(.state_class == "unclassified")
     | select((.next_action // "") == "" or .next_action == "nothing — supported") | .id]
    | join(", ")
  ' -- "$file")"
  [ -z "$offenders" ] \
    || { printf 'unclassified surfaces carry no next action: %s\n' "$offenders" >&2; return 1; }
  return 0
}

# mup_assert_plan_document <json-file> — §6.1 step 8. The plan validates as a
# `duo-authorization-plan/v1`, cites the contract it was authorized under,
# lists what recovery does NOT restore, and names the recovery profile AND the
# reason it was selected. "Names the profile" without "and why" is the failure
# this checks for: a profile with no stated reason is an assertion, not
# evidence.
mup_assert_plan_document() {
  local file="$1"
  jq -e '
    .format == "duo-authorization-plan/v1"
    and (.plan_digest | test("^sha256:[0-9a-f]{64}$"))
    and (.contract_digest | type == "string") and (.contract_digest | test("^sha256:[0-9a-f]{64}$"))
    and (.recovery_profile.claim.does_not_restore | type == "array")
    and (.recovery_profile.claim.does_not_restore | length) > 0
    and (.recovery_profile.selected | type == "string") and (.recovery_profile.selected | length) > 0
    and (.recovery_profile.selected_because | type == "string")
    and (.recovery_profile.selected_because | length) > 0
    and (.effects.unknown_blocking | length) == 0
  ' -- "$file" >/dev/null \
    || { printf 'the authorization plan is not a complete duo-authorization-plan/v1: %s\n' "$file" >&2; return 1; }
  return 0
}

# mup_assert_verify_report <json-file> — §6.1 step 10. Convergence pass, every
# declared journey pass, at least two journeys declared, and the
# uncovered-surfaces list present (an empty list is a report; a missing key is
# a silence).
mup_assert_verify_report() {
  local file="$1"
  jq -e '
    .format == "duo-verify-report/v1"
    and .verdict == "pass"
    and .convergence.status == "pass"
    and (.journeys | length) >= 2
    and (.journeys | all(.status == "pass"))
    and (.uncovered_surfaces | type == "array")
  ' -- "$file" >/dev/null \
    || { printf 'the verify report is not a passing duo-verify-report/v1: %s\n' "$file" >&2; return 1; }
  return 0
}

# mup_claim_boundary <json-file> [<jq-path-to-claim>] — the claim's literal
# `maximum_loss_boundary` sentence, verbatim.
mup_claim_boundary() {
  local file="$1" path="${2:-.}"
  jq -er "$path"' | .maximum_loss_boundary' -- "$file"
}

# mup_boundary_expectation <boundary-sentence> — what that exact sentence says
# must happen to a row written after the checkpoint.
#
# `RecoveryClaim::lossBoundary()` emits exactly three sentences and no others.
# This function refuses anything else rather than guessing, because the whole
# point of step 11 is that the printed claim is taken literally.
#
#   lost      "writes committed after checkpoint <ts>"  -> the row must be gone
#   lost      "writes committed after the checkpoint this release takes ..."
#   unbounded "everything this release writes: no checkpoint is taken, ..."
mup_boundary_expectation() {
  local boundary="$1"
  case "$boundary" in
    'writes committed after checkpoint '*) printf 'lost\n' ;;
    'writes committed after the checkpoint this release takes immediately before mutation') printf 'lost\n' ;;
    'everything this release writes: no checkpoint is taken, so nothing bounds the loss') printf 'unbounded\n' ;;
    *) printf 'the recovery claim printed a maximum_loss_boundary this grind cannot read literally: %s\n' \
         "$boundary" >&2; return 1 ;;
  esac
}

# mup_assert_claim_literal <json-file> [<jq-path>] — a claim that gives nothing
# up is the one thing no profile can honestly say (RecoveryClaim's own
# invariant). The grind reads the claim out of a frozen plan and out of the
# recovery outcome, so it re-checks the invariant at both readings.
mup_assert_claim_literal() {
  local file="$1" path="${2:-.}"
  jq -e "$path"' as $c
    | $c.format == "duo-recovery-claim/v1"
      and ($c.does_not_restore | type == "array") and ($c.does_not_restore | length) > 0
      and ($c.maximum_loss_boundary | type == "string") and ($c.maximum_loss_boundary | length) > 0
      and ($c.restores | type == "array")
  ' -- "$file" >/dev/null \
    || { printf 'the recovery claim is not literal: %s\n' "$file" >&2; return 1; }
  return 0
}

# mup_assert_phase_order <file> <phase...> — the phases appear, in this order.
# Lifted from grind_code_half.sh's assert_phase_order, which is the idiom of
# record for reading `promote phase:` lines; deploy-before-apply is the only
# reason step 9 exists.
mup_assert_phase_order() {
  local file="$1" last=0 needle line
  shift
  for needle in "$@"; do
    line="$(grep -n -F -m1 -- "$needle" "$file" | cut -d: -f1 || true)"
    [ -n "$line" ] || { printf 'release output did not include phase %s\n' "$needle" >&2; return 1; }
    [ "$line" -gt "$last" ] || { printf 'release phase %s was out of order\n' "$needle" >&2; return 1; }
    last="$line"
  done
  return 0
}

# mup_checkpoint_id <catalog-json> — the receipt id `--restore=` consumes.
# `--list` prints exactly one row (the rollback authority holds one generation
# at a time and CheckpointCatalog says so in its own disclosure), so a catalog
# with none is a failure to name rather than a row to skip.
mup_checkpoint_id() {
  local file="$1"
  jq -er '
    if (.rows | length) == 0
    then error("the checkpoint catalog lists no receipt: " + ((.disclosures // []) | join("; ")))
    else .rows[0].id end
  ' -- "$file"
}

# mup_projection_subset <assess-json> <surface-id>... — the release projection
# of exactly the named surfaces, canonically ordered, for the §6.1 step 12
# before/after comparison. Only the projected words are compared: a timestamp
# or a digest differing between two assessments of the same site is the clock
# moving, not the site moving.
mup_projection_subset() {
  local file="$1"; shift
  local ids
  ids="$(printf '%s\n' "$@" | jq -R . | jq -sc .)"
  jq -Sc --argjson ids "$ids" '
    [ .surfaces[]
      | select(.id as $i | $ids | index($i))
      | {id, state_class, handling,
         release: (.operations.release
                   | {readiness, certification_provenance,
                      effect_containment, effect_recovery_semantics, handling, state_class})}
    ] | sort_by(.id)
  ' -- "$file"
}

# mup_write_registry <envs-file> <compose-file> <one> <two> <php> <provider> <config>
# The machine-local overlay MUP §6.1 needs: the two pair sides, plus the
# `preview` entry that carries the privileged provider block. Extracted so the
# run path and --self-check write the SAME bytes; a registry the grind can
# write but the orchestrator cannot load is a failure worth finding offline.
mup_write_registry() {
  jq -n --arg compose "$2" --arg one "$3" --arg two "$4" \
        --arg php "$5" --arg provider "$6" --arg config "$7" '
    {envs: {
      ($one): {transport: "docker", compose_file: $compose, service: "cli1", repo_path: "/siterepo"},
      ($two): {transport: "docker", compose_file: $compose, service: "cli2", repo_path: "/siterepo"},
      preview: {
        transport: "docker", compose_file: $compose, service: "cli2", repo_path: "/siterepo",
        environment_provider: {command: [$php, $provider, $config], timeout_seconds: 60}
      }
    }}' > "$1"
}

# mup_validate_registry <envs-file> — load it through the REAL orchestrator
# registry and construct the REAL provider client from the `preview` entry.
#
# `CommandEnvironmentProvider::fromEnvironment()` is EnvironmentLifecycle's own
# provider-config schema: closed key set {command, timeout_seconds}, a
# non-empty argv whose executable is absolute, a 1..60 second timeout, and the
# `_machine_local` provenance flag only `.duo-envs.json` can carry. Running it
# here means a malformed provider block refuses on this machine, offline,
# instead of at materialization time with a snapshot already taken.
mup_validate_registry() {
  php -r '
    require $argv[2] . "/cli/src/Environment/Registry.php";
    require $argv[2] . "/cli/src/Environment/EnvironmentLifecycle.php";
    $envs = \Duo\Orchestrator\Registry::load($argv[1], dirname($argv[1]));
    foreach (["preview"] as $name) {
        if (!is_array($envs[$name] ?? null)) {
            fwrite(STDERR, "the registry has no $name environment\n");
            exit(1);
        }
    }
    \Duo\Orchestrator\CommandEnvironmentProvider::fromEnvironment("preview", $envs["preview"]);
    echo "preview provider config accepted by EnvironmentLifecycle\n";
  ' "$1" "$REPO_ROOT"
}

# ---------------------------------------------------------------------------
# --self-check: every helper above, against the recorded fixtures.
# ---------------------------------------------------------------------------
self_check() {
  local expect got
  say "self-check: pure helpers against sandbox/tests/fixtures/mup/"
  [ -d "$FIXTURES" ] || fail "fixture directory is missing: $FIXTURES (run php $FIXTURES/make-fixtures.php)"

  # json tail
  got="$(mup_json_tail "$FIXTURES/plan-only.stdout.txt" | jq -r '.format')" \
    || soft_fail "mup_json_tail could not extract the plan document"
  [ "$got" = 'duo-authorization-plan/v1' ] \
    && pass "mup_json_tail extracts the document a rendered plan page is followed by" \
    || soft_fail "mup_json_tail extracted '$got'"
  if mup_json_tail "$FIXTURES/assess-human.clean.txt" >/dev/null 2>&1; then
    soft_fail "mup_json_tail accepted output that carries no JSON document"
  else
    pass "mup_json_tail refuses output with no canonical document rather than returning nothing"
  fi

  # leak gate
  if mup_assert_no_internal_ids "$FIXTURES/assess-human.clean.txt" 'clean assess' 2>/dev/null; then
    pass "the leak gate passes a clean assess human view"
  else
    soft_fail "the leak gate rejected a clean assess human view"
  fi
  for leak in leaks-uuid leaks-artifact-hash; do
    if mup_assert_no_internal_ids "$FIXTURES/assess-human.$leak.txt" 'leaking assess' 2>/dev/null; then
      soft_fail "the leak gate accepted $leak"
    else
      pass "the leak gate catches $leak"
    fi
  done

  # assess document + rows
  if mup_assert_assess_document "$FIXTURES/assess-report.pass.json" 2>/dev/null; then
    pass "the assess-document check passes a well-formed report"
  else
    soft_fail "the assess-document check rejected a well-formed report"
  fi
  if mup_assert_assess_document "$FIXTURES/assess-report.fail-unclassified-no-next-action.json" 2>/dev/null; then
    soft_fail "the assess-document check accepted an unclassified row with no gap action"
  else
    pass "the assess-document check catches an unclassified row with no gap action"
  fi
  expect=$'authored\tmanage\tReady\tPlatform-certified\tprevented\tprovider-state restorable'
  got="$(mup_assess_projection "$FIXTURES/assess-report.pass.json" post_type:product release)"
  [ "$got" = "$expect" ] \
    && pass "the products row reads authored/manage/Ready/Platform-certified/prevented" \
    || soft_fail "the products row read '$got'"
  expect=$'runtime\tpreserve local\tUnsupported\tPlatform-certified\tprevented\tnot applicable'
  got="$(mup_assess_projection "$FIXTURES/assess-report.pass.json" post_type:shop_order release)"
  [ "$got" = "$expect" ] \
    && pass "the orders row reads runtime/preserve local/Unsupported" \
    || soft_fail "the orders row read '$got'"
  got="$(mup_assess_projection "$FIXTURES/assess-report.fail-products-not-ready.json" post_type:product release | cut -f3)"
  [ "$got" != Ready ] \
    && pass "the row reader reports a readiness word that is not Ready rather than smoothing it" \
    || soft_fail "the row reader read Ready from the not-ready fixture"

  # authorization plan
  if mup_assert_plan_document "$FIXTURES/authorization-plan.pass.json" 2>/dev/null; then
    pass "the plan check passes a complete frozen plan"
  else
    soft_fail "the plan check rejected a complete frozen plan"
  fi
  for broken in fail-no-contract-digest fail-no-recovery-reason; do
    if mup_assert_plan_document "$FIXTURES/authorization-plan.$broken.json" 2>/dev/null; then
      soft_fail "the plan check accepted $broken"
    else
      pass "the plan check catches $broken"
    fi
  done

  # verify report
  if mup_assert_verify_report "$FIXTURES/verify-report.pass.json" 2>/dev/null; then
    pass "the verify check passes a passing report"
  else
    soft_fail "the verify check rejected a passing report"
  fi
  if mup_assert_verify_report "$FIXTURES/verify-report.fail-journey.json" 2>/dev/null; then
    soft_fail "the verify check accepted a report with a failing journey"
  else
    pass "the verify check catches a failing journey"
  fi

  # recovery claim + boundary
  if mup_assert_claim_literal "$FIXTURES/recovery-claim.operator-directed.json" 2>/dev/null; then
    pass "the claim check passes an operator-directed claim"
  else
    soft_fail "the claim check rejected an operator-directed claim"
  fi
  if mup_assert_claim_literal "$FIXTURES/recovery-claim.fail-empty-does-not-restore.json" 2>/dev/null; then
    soft_fail "the claim check accepted a claim that gives nothing up"
  else
    pass "the claim check catches a claim that gives nothing up"
  fi
  if mup_assert_claim_literal "$FIXTURES/authorization-plan.pass.json" '.recovery_profile.claim' 2>/dev/null; then
    pass "the claim check reads the claim embedded verbatim in a frozen plan"
  else
    soft_fail "the claim check could not read the plan-embedded claim"
  fi
  got="$(mup_boundary_expectation "$(mup_claim_boundary "$FIXTURES/recovery-claim.operator-directed.json")")"
  [ "$got" = lost ] \
    && pass "a checkpoint-bounded claim means a post-checkpoint row is lost" \
    || soft_fail "the operator-directed boundary read as '$got'"
  got="$(mup_boundary_expectation "$(mup_claim_boundary "$FIXTURES/recovery-claim.none.json")")"
  [ "$got" = unbounded ] \
    && pass "the no-checkpoint claim reads as unbounded loss" \
    || soft_fail "the none-profile boundary read as '$got'"
  got="$(mup_claim_boundary "$FIXTURES/authorization-plan.pass.json" '.recovery_profile.claim')"
  [ -n "$got" ] \
    && pass "the boundary sentence is readable from the frozen plan: $got" \
    || soft_fail "the frozen plan carries no boundary sentence"
  if mup_boundary_expectation 'rollback restores everything' >/dev/null 2>&1; then
    soft_fail "the boundary reader invented a meaning for a sentence RecoveryClaim never emits"
  else
    pass "the boundary reader refuses a sentence RecoveryClaim never emits"
  fi

  # phase order
  if mup_assert_phase_order "$FIXTURES/release-phases.ordered.txt" \
      'promote phase: checkpoint' 'promote phase: code-stage' \
      'promote phase: lifecycle-retire' 'promote phase: lifecycle-activate' \
      'promote phase: apply' 2>/dev/null; then
    pass "the phase-order check accepts deploy-before-apply"
  else
    soft_fail "the phase-order check rejected deploy-before-apply"
  fi
  if mup_assert_phase_order "$FIXTURES/release-phases.apply-first.txt" \
      'promote phase: lifecycle-retire' 'promote phase: apply' 2>/dev/null; then
    soft_fail "the phase-order check accepted apply before the lifecycle"
  else
    pass "the phase-order check catches apply before the lifecycle"
  fi

  # checkpoint catalog
  got="$(mup_checkpoint_id "$FIXTURES/checkpoint-catalog.json")"
  [ "$got" = 'scoped-20260817-091300-0001' ] \
    && pass "the receipt id --restore= consumes is read from the catalog" \
    || soft_fail "the catalog reader read '$got'"
  if mup_checkpoint_id "$FIXTURES/checkpoint-catalog.empty.json" >/dev/null 2>&1; then
    soft_fail "the catalog reader invented a receipt id for an empty catalog"
  else
    pass "an empty catalog is named as a failure, not skipped"
  fi

  # projection subset
  got="$(mup_projection_subset "$FIXTURES/assess-report.pass.json" post_type:product post_type:shop_order)"
  expect="$(mup_projection_subset "$FIXTURES/assess-report.pass.json" post_type:shop_order post_type:product)"
  [ "$got" = "$expect" ] \
    && pass "the projection subset is independent of the order the surfaces are named in" \
    || soft_fail "the projection subset is order-dependent"
  expect="$(mup_projection_subset "$FIXTURES/assess-report.fail-products-not-ready.json" post_type:product)"
  got="$(mup_projection_subset "$FIXTURES/assess-report.pass.json" post_type:product)"
  [ "$got" != "$expect" ] \
    && pass "the projection subset sees a readiness change between two assessments" \
    || soft_fail "the projection subset is blind to a readiness change"

  # registry shape + EnvironmentLifecycle's provider-config schema, offline
  local tmp
  tmp="$(mktemp -d "${TMPDIR:-/tmp}/grind-mup-selfcheck.XXXXXX")"
  mup_write_registry "$tmp/.duo-envs.json" "$SANDBOX/pair.yml" mup1 mup2 \
    "$(command -v php)" "$REPO_ROOT/tools/reference-env-provider.php" "$tmp/config.json"
  if mup_validate_registry "$tmp/.duo-envs.json" >/dev/null 2>&1; then
    pass "the registry this grind writes is accepted by EnvironmentLifecycle's provider-config schema"
  else
    soft_fail "the registry this grind writes is rejected by EnvironmentLifecycle: $(mup_validate_registry "$tmp/.duo-envs.json" 2>&1)"
  fi
  # A relative provider executable is the mistake this schema exists to catch.
  jq '.envs.preview.environment_provider.command[0] = "php"' "$tmp/.duo-envs.json" > "$tmp/relative.json"
  if mup_validate_registry "$tmp/relative.json" >/dev/null 2>&1; then
    soft_fail "EnvironmentLifecycle accepted a relative provider executable"
  else
    pass "a relative provider executable is refused before any provider call"
  fi
  rm -rf -- "$tmp"

  if [ "$FAILURES" -ne 0 ]; then
    printf '\nGRIND_MUP SELF-CHECK FAILED (%d)\n' "$FAILURES" >&2
    exit 1
  fi
  pass "self-check clean"
}

# ---------------------------------------------------------------------------
# Preflight, cleanup, and the pair.
# ---------------------------------------------------------------------------
cleanup() {
  local status=$?
  local containers volumes networks databases
  trap - EXIT INT TERM
  set +e
  if dry; then
    # A dry run creates no pair and no site repo, so it must remove neither.
    # PAIR_UP is set by the planned step-1 body like any other variable.
    exit "$status"
  fi
  if [ "${MUP_KEEP:-0}" = 1 ]; then
    printf 'note: MUP_KEEP=1 — pair %s, %s and %s were left in place\n' \
      "$PAIR" "siterepo/${PAIR}1" "siterepo/${PAIR}2" >&2
    exit "$status"
  fi
  if [ "$PAIR_UP" = 1 ]; then
    # Capture publishes as uid 33 inside the cli container, so its staging
    # descendants can be host-undeletable after a failed run. Normalize only
    # this disposable pair's bind mounts before removing them.
    "${COMPOSE[@]}" run --rm -T -u root cli1 sh -c 'chmod -R ugo+rwX /siterepo' >/dev/null 2>&1
    "${COMPOSE[@]}" run --rm -T -u root cli2 sh -c 'chmod -R ugo+rwX /siterepo' >/dev/null 2>&1
    if ! bash bin/pair.sh destroy "$PAIR" >/dev/null 2>&1; then
      printf 'FAIL: grind pair destroy failed for %s\n' "$PAIR" >&2
      status=1
    fi
    containers="$(docker ps -aq --filter "label=com.docker.compose.project=duo-$PAIR" 2>/dev/null)"
    volumes="$(docker volume ls -q --filter "label=com.docker.compose.project=duo-$PAIR" 2>/dev/null)"
    networks="$(docker network ls -q --filter "label=com.docker.compose.project=duo-$PAIR" 2>/dev/null)"
    if [ -n "$containers$volumes$networks" ]; then
      printf 'FAIL: cleanup left Docker resources for project duo-%s behind\n' "$PAIR" >&2
      status=1
    fi
    databases="$(docker exec -e MYSQL_PWD=root duo-shared-db mariadb -uroot -N -B --raw \
      -e "SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME IN ('wp_${PAIR}1','wp_${PAIR}2')" 2>/dev/null)"
    if [ -n "$databases" ]; then
      printf 'FAIL: cleanup left pair database(s) behind: %s\n' "$databases" >&2
      status=1
    fi
  fi
  rm -rf -- "siterepo/${PAIR}1" "siterepo/${PAIR}2" "siterepo/origin-${PAIR}.git"
  if [ -e "siterepo/${PAIR}1" ] || [ -e "siterepo/${PAIR}2" ]; then
    printf 'FAIL: cleanup left a grind site repo behind under siterepo/\n' >&2
    status=1
  fi
  [ -n "$SCRATCH" ] && rm -rf -- "$SCRATCH"
  exit "$status"
}

preflight() {
  [[ "$PAIR" =~ ^[a-z][a-z0-9]*$ ]] \
    || fail "MUP_PAIR '$PAIR' is invalid (pair.sh grammar: lowercase letters/digits, letter first)"
  if [ "$PAIR" != mup ] && { [ -z "${MUP_PORT1:-}" ] || [ -z "${MUP_PORT2:-}" ]; }; then
    fail "a custom MUP_PAIR '$PAIR' requires explicit MUP_PORT1 and MUP_PORT2 (9400/9401 belong to the shared 'mup' instance)"
  fi
  [[ "$PORT1" =~ ^[0-9]+$ && "$PORT2" =~ ^[0-9]+$ && "$PORT1" != "$PORT2" ]] \
    || fail "MUP_PORT1/MUP_PORT2 must be two different numeric host ports (got '$PORT1'/'$PORT2')"
  case "$WORDPRESS_OFFLINE" in 0|1) ;; *) fail "DUO_WORDPRESS_ORG_OFFLINE must be 0 or 1" ;; esac
  case "$BOOTSTRAP" in init|manual) ;; *) fail "MUP_BOOTSTRAP must be 'init' or 'manual'" ;; esac
  case "$STEP11" in required|record-gap) ;; *) fail "MUP_STEP11 must be 'required' or 'record-gap'" ;; esac
  [ -f "$DUO" ] || fail "host CLI missing: $DUO"
  [ -f "$REPO_ROOT/tools/reference-env-provider.php" ] \
    || fail "the reference environment provider is missing: $REPO_ROOT/tools/reference-env-provider.php"

  # MUP §6.1 names Storefront. Exact artifact installs are mandatory
  # (fetch-artifact.sh refuses an unpinned version rather than falling through
  # to the wordpress.org catalog), so an unpinned theme is a refusal with the
  # two real remedies named, not a silent substitution.
  if [ -z "$THEME_VERSION" ]; then
    THEME_VERSION="$(jq -r --arg slug "$THEME_SLUG" '
      (.themes[$slug] // {}) | keys | if length == 0 then "" else .[-1] end
    ' conformance/artifacts.lock.json)"
  fi
  if [ -z "$THEME_VERSION" ]; then
    fail "conformance/artifacts.lock.json carries no pin for theme '$THEME_SLUG'.
MUP §6.1 names Storefront, and sandbox/bin/fetch-artifact.sh refuses an unpinned
artifact rather than installing from the catalog, so this grind refuses here —
before any pair is created — instead of installing something it cannot name.
Either add the pin:
  .themes.$THEME_SLUG.\"<version>\" = {url, sha256, role: \"exercise-fixture\"}
or run against a theme this estate already pins:
  MUP_THEME_SLUG=twentytwentyone MUP_THEME_VERSION=2.8 bash sandbox/tests/grind_mup.sh"
  fi
  jq -e --arg slug "$THEME_SLUG" --arg v "$THEME_VERSION" '.themes[$slug][$v]' \
    conformance/artifacts.lock.json >/dev/null \
    || fail "conformance/artifacts.lock.json has no pin for theme $THEME_SLUG $THEME_VERSION"
  jq -e --arg v "$WOO_VERSION" '.plugins.woocommerce[$v]' conformance/artifacts.lock.json >/dev/null \
    || fail "conformance/artifacts.lock.json has no pin for woocommerce $WOO_VERSION"
  pass "pinned artifacts resolved: woocommerce $WOO_VERSION, $THEME_SLUG $THEME_VERSION"
}

# ---------------------------------------------------------------------------
# Modes that stop before docker.
# ---------------------------------------------------------------------------
require jq
require php
self_check
if [ "$MODE" = self-check ]; then
  printf '\nGRIND_MUP SELF-CHECK PASSED\n' >&3
  exit 0
fi
if ! dry; then
  require docker
  require git
  require curl
fi

R1="siterepo/${PAIR}1"
R2="siterepo/${PAIR}2"
ORIGIN="$SANDBOX/siterepo/origin-${PAIR}.git"
HOST_R1="$SANDBOX/$R1"
HOST_R2="$SANDBOX/$R2"
BASE1="http://localhost:${PORT1}"

preflight

if dry; then
  SCRATCH="$SANDBOX/tmp/grind-mup-dry-run"
else
  # sandbox/tmp is the estate's gitignored scratch root and is not guaranteed
  # to exist in a fresh checkout (AGENTS.md: scratch goes here, never under
  # agent/, cli/ or sandbox/bin/, which the certification closure walks whole).
  mkdir -p "$SANDBOX/tmp"
  SCRATCH="$(mktemp -d "$SANDBOX/tmp/grind-mup.XXXXXX")"
fi
ENVS_FILE="$SCRATCH/.duo-envs.json"
PROVIDER_CONFIG="$SCRATCH/reference-env-provider.json"
PROVIDER_STATE="$SCRATCH/provider-state"
EVIDENCE="$SCRATCH/evidence"
PHP_BIN="$(command -v php)"
PROVIDER="$REPO_ROOT/tools/reference-env-provider.php"

trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

export DUO_PAIR="$PAIR" DUO_PORT1="$PORT1" DUO_PORT2="$PORT2"
export DUO_ARTIFACT_OFFLINE="$WORDPRESS_OFFLINE"
export DUO_ARTIFACT_LOCKFILE="$SANDBOX/conformance/artifacts.lock.json"
COMPOSE_FILES=("$SANDBOX/pair.yml" "$SANDBOX/pair.http.yml" "$SANDBOX/pair.artifacts.yml")
PAIR_UP_FLAGS=(--http --artifacts)
if [ "$WORDPRESS_OFFLINE" = 1 ]; then
  COMPOSE_FILES+=("$SANDBOX/pair.wordpress-offline.yml")
  PAIR_UP_FLAGS+=(--wordpress-offline)
fi
COMPOSE=(docker compose -p "duo-$PAIR")
for file in "${COMPOSE_FILES[@]}"; do COMPOSE+=(-f "$file"); done
PAIR_COMPOSE=("${COMPOSE[@]}")
# shellcheck source=../bin/fetch-artifact.sh
. bin/fetch-artifact.sh

wp_side() { # wp_side <1|2> <wp args...>
  local side="$1"; shift
  run "${COMPOSE[@]}" run --rm -T "cli${side}" sh -c 'umask 000; exec wp "$@"' sh "$@"
}
wp1() { wp_side 1 "$@"; }
wp2() { wp_side 2 "$@"; }
duo_in() { # duo_in <cwd> <duo args...>
  local dir="$1"; shift
  run_in "$dir" php "$DUO" "--envs-file=$ENVS_FILE" "$@"
}
git1() { run git -C "$HOST_R1" "$@"; }
git2() { run git -C "$HOST_R2" "$@"; }

# The candidate-source gate, honoured exactly as conformance/run.sh honours
# it: exported so pair.sh's own assert_candidate_source refuses BEFORE reset
# DROP/CREATEs a database or a container starts. Unset leaves the run
# byte-identical and explicitly not candidate-bound.
if [ -n "${DUO_EXPECTED_SOURCE_SHA:-}" ]; then
  export DUO_EXPECTED_SOURCE_SHA
  pass "candidate-source gate armed: DUO_EXPECTED_SOURCE_SHA=$DUO_EXPECTED_SOURCE_SHA"
else
  note "DUO_EXPECTED_SOURCE_SHA is unset — this run is NOT bound to a source commit"
fi

run mkdir -p "$PROVIDER_STATE" "$EVIDENCE"

# ---------------------------------------------------------------------------
# Step 1 — pair up, WooCommerce + theme on both sides, a small catalog on
#          side 1.
# ---------------------------------------------------------------------------
say "step 1/13 — pair '$PAIR' up on :$PORT1/:$PORT2, pinned extensions, seeded catalog"
if ! dry; then validate_artifact_lock "$SANDBOX/conformance/artifacts.lock.json" \
  || fail "artifact lock is malformed; the grind refused before pair reset"; fi
PAIR_UP=1
run bash bin/pair.sh reset "$PAIR"
run bash bin/pair.sh up "$PAIR" "$PORT1" "$PORT2" "${PAIR_UP_FLAGS[@]}"

install_side() { # install_side <1|2> <author|target>
  local side="$1" role="$2" artifact
  wp_side "$side" option update blogname "Duo MUP ${PAIR}${side}"
  wp_side "$side" site empty --yes
  if dry; then
    plan "fetch_artifact woocommerce $WOO_VERSION cli$side plugin"
    plan "fetch_artifact $THEME_SLUG $THEME_VERSION cli$side theme"
    artifact='<container-side-zip>'
  else
    artifact="$(fetch_artifact woocommerce "$WOO_VERSION" "cli$side" plugin)" \
      || fail "could not obtain the pinned woocommerce $WOO_VERSION artifact for side $side"
  fi
  wp_side "$side" plugin install "$artifact" --force
  [ "$role" = author ] && wp_side "$side" plugin activate woocommerce
  if ! dry; then
    artifact="$(fetch_artifact "$THEME_SLUG" "$THEME_VERSION" "cli$side" theme)" \
      || fail "could not obtain the pinned $THEME_SLUG $THEME_VERSION artifact for side $side"
  fi
  wp_side "$side" theme install "$artifact" --force
  [ "$role" = author ] && wp_side "$side" theme activate "$THEME_SLUG"
  return 0
}
# Side 1 is the author: activated and set up, because capture must see a fully
# set-up environment. Side 2 arrives with extension FILES only — `duo deploy`
# inside the release reconciles activation from canonical, which is the whole
# point of deploy-before-apply and is exactly conformance/run.sh's split.
install_side 1 author
install_side 2 target

say "step 1/13 — seed the catalog on ${PAIR}1"
PRODUCT_A_ID=""
PRODUCT_B_ID=""
LANDING_ID=""
if dry; then
  plan "wp wc product create --name='Duo Ceramic Mug' --regular_price=24.00 ... (x3)"
  plan "wp post create --post_type=page --post_title='Duo grind landing page' ..."
  PRODUCT_A_ID='<product-a-id>'; PRODUCT_B_ID='<product-b-id>'; LANDING_ID='<landing-id>'
else
  PRODUCT_A_ID="$(wp1 wc product create --name='Duo Ceramic Mug' --type=simple \
    --regular_price=24.00 --sku=DUO-MUG --status=publish --user=admin --porcelain | tr -d '\r')"
  PRODUCT_B_ID="$(wp1 wc product create --name='Duo Enamel Pin' --type=simple \
    --regular_price=8.00 --sku=DUO-PIN --status=publish --user=admin --porcelain | tr -d '\r')"
  wp1 wc product create --name='Duo Tote Bag' --type=simple \
    --regular_price=15.00 --sku=DUO-TOTE --status=publish --user=admin --porcelain >/dev/null
  LANDING_ID="$(wp1 post create --post_type=page --post_status=publish \
    --post_title='Duo grind landing page' --post_name=duo-grind-landing \
    --post_content='<p>Duo grind landing page, before the release.</p>' --porcelain | tr -d '\r')"
  [ -n "$PRODUCT_A_ID" ] && [ -n "$LANDING_ID" ] \
    || fail "the catalog seed did not produce a product and a page on ${PAIR}1"
fi
# WooCommerce mints its own Shop/Cart/Checkout pages on activation, so the
# shop page is a fact about the target rather than something the grind
# authors. Read its path rather than assuming it.
SHOP_PATH="${MUP_JOURNEY_SHOP_URL:-/shop/}"
LANDING_PATH="/?page_id=$LANDING_ID"
if ! dry; then
  LANDING_URL="$(wp1 post list --post_type=page --name=duo-grind-landing --field=url | tr -d '\r' | head -1)"
  case "$LANDING_URL" in
    "$BASE1"*) LANDING_PATH="${LANDING_URL#"$BASE1"}" ;;
    *) note "the landing page URL '$LANDING_URL' is not under $BASE1; probing by query string instead" ;;
  esac
fi
pass "pair up; woocommerce $WOO_VERSION + $THEME_SLUG $THEME_VERSION installed; 3 products and a page on ${PAIR}1"

# ---------------------------------------------------------------------------
# Registry: the two sides plus the preview environment the provider drives.
# ---------------------------------------------------------------------------
say "registry — .duo-envs.json for ${PAIR}1, ${PAIR}2 and preview"
# `preview` and `${PAIR}2` are the SAME physical side: MUP §6.1 makes side 2
# both the rehearsal preview and the release target, and the pair has two
# sides. They are two registry entries because only one of them carries the
# privileged `environment_provider` block — a provider config is machine-local
# authority (EnvironmentLifecycle refuses it anywhere but .duo-envs.json), and
# `duo release`/`duo verify`/`duo recover` need none of it.
if dry; then
  plan "write $ENVS_FILE (docker cli1/cli2; preview carries environment_provider -> php $PROVIDER $PROVIDER_CONFIG)"
  plan "write $PROVIDER_CONFIG (duo-reference-env-provider-config/v1, pair $PAIR)"
else
  mup_write_registry "$ENVS_FILE" "${COMPOSE_FILES[0]}" "${PAIR}1" "${PAIR}2" \
    "$PHP_BIN" "$PROVIDER" "$PROVIDER_CONFIG"
  mup_validate_registry "$ENVS_FILE" \
    || fail "the .duo-envs.json this grind wrote is not loadable as a machine-local provider registry"
  jq -n \
    --arg pair "$PAIR" --arg script "$SANDBOX/bin/pair.sh" --arg dir "$SANDBOX" \
    --arg origin "$ORIGIN" --arg state "$PROVIDER_STATE" --arg source "${PAIR}1" \
    --argjson port1 "$PORT1" --argjson port2 "$PORT2" \
    --arg repo1 "$HOST_R1" --arg repo2 "$HOST_R2" \
    --argjson files "$(printf '%s\n' "${COMPOSE_FILES[@]}" | jq -R . | jq -sc .)" '
    {
      format: "duo-reference-env-provider-config/v1",
      pair: $pair, pair_script: $script, compose_dir: $dir, compose_files: $files,
      controller_repo: $origin, db_container: "duo-shared-db", state_root: $state,
      source_environment: $source, destroy_scope: "side", withheld_capabilities: [],
      environments: {
        ($source): {role: "source", side: 1, port: $port1,
                    container: ("duo-" + $pair + "-wp1-1"), service: "cli1",
                    database: ("wp_" + $pair + "1"), repo: $repo1},
        preview:    {role: "target", side: 2, port: $port2,
                     container: ("duo-" + $pair + "-wp2-1"), service: "cli2",
                     database: ("wp_" + $pair + "2"), repo: $repo2}
      }
    }' > "$PROVIDER_CONFIG"
fi
# Validate the config through the provider's OWN validator and the real
# orchestrator envelope, offline, before the first live provider call. A
# malformed provider config discovered at materialization time has already
# cost a snapshot.
if ! dry; then
  printf '{"action":"capabilities","environment":"preview","format":"duo-branch-environment-provider-request/v1","input":[],"operation_id":"20260817-090000-0000000000000000abcdefab"}\n' \
    | php "$PROVIDER" --print-plan "$PROVIDER_CONFIG" > "$EVIDENCE/provider-plan.json" \
    || fail "the reference provider refused the config this grind wrote; see $EVIDENCE/provider-plan.json"
  jq -e '
    .format == "duo-reference-env-provider-plan/v1" and .executed == false
    and .provider.protocol == 1
    and ([.capabilities_advertised[]] | index("repository.materialize"))
    and ([.capabilities_advertised[]] | index("environment.attach"))
    and ([.capabilities_advertised[]] | index("environment.url.discover"))
    and ([.capabilities_advertised[]] | index("operation.receipts"))
    and ([.capabilities_advertised[]] | index("snapshot.set.restore"))
  ' "$EVIDENCE/provider-plan.json" >/dev/null \
    || fail "the provider config validates but does not advertise the capabilities MUP §2.2 requires"
fi
pass "provider config validates against the provider's own schema and advertises the §2.2 capability set"

# ---------------------------------------------------------------------------
# Step 2 — adopt / init side 1, baseline clean.
# ---------------------------------------------------------------------------
say "step 2/13 — bootstrap the site repository on ${PAIR}1, then duo status clean"
run git init --bare -b main "$ORIGIN"
# `duo adopt` is the control-plane transfer verb, and AdoptCommand refuses any
# transport that is not an AdoptionTransport. A pair side already carries the
# agent (pair.sh mounts it), so adoption has nothing to transfer here — assert
# the typed refusal rather than pretending the verb was skipped for taste.
ADOPT_OUT="$SCRATCH/adopt.txt"
if ! dry; then
  if duo_in "$HOST_R1" adopt "${PAIR}1" > "$ADOPT_OUT" 2>&1; then
    note "duo adopt ${PAIR}1 succeeded on this transport"
  else
    grep -Fq 'adopt requires a transport with explicit control-plane transfer authority' "$ADOPT_OUT" \
      || fail "duo adopt failed for a reason this grind does not recognize; see $ADOPT_OUT"
    pass "duo adopt refuses the pair's docker transport by name (the pair side already carries the agent)"
  fi
else
  plan "(cd $HOST_R1 && php $DUO --envs-file=$ENVS_FILE adopt ${PAIR}1)"
fi

if [ "$BOOTSTRAP" = init ]; then
  duo_in "$HOST_R1" init "${PAIR}1" --yes
else
  # The conformance shape: a hand-written registry plus one capture. Kept as
  # an explicit alternative because `duo init`'s proposal is evidence-gated
  # and a stale generated registry makes it refuse for a reason that has
  # nothing to do with this grind.
  if dry; then
    plan "write $HOST_R1/site.duo.json (manifests core+woocommerce, spec_version 2)"
  else
    jq -n '{
      manifests: ["core", "woocommerce"],
      policy: {options: {}, post_meta: {},
               post_types: ["post","page","attachment","product","product_variation","shop_coupon"],
               taxonomies: ["category","post_tag","product_cat","product_type"]},
      spec_version: 2
    }' > "$HOST_R1/site.duo.json"
    cp site-repo.gitignore.template "$HOST_R1/.gitignore"
    git init -q -b main "$HOST_R1"
  fi
  duo_in "$HOST_R1" capture "${PAIR}1"
fi
# `duo init` creates the worktree itself; the manual bootstrap already did.
# Either way the repository exists before the first commit, and the plan says
# so rather than hiding a conditional.
if dry || [ ! -d "$HOST_R1/.git" ]; then
  git1 init -q -b main
fi
git1 remote add origin "$ORIGIN"
git1 add -A
git1 -c user.name=duo -c user.email=duo@example.test commit -qm "grind_mup: baseline capture of ${PAIR}1"
git1 push -qu origin main

STATUS1="$SCRATCH/status-1.txt"
if ! dry; then
  duo_in "$HOST_R1" status "${PAIR}1" > "$STATUS1" 2>&1 \
    || fail "duo status ${PAIR}1 did not exit 0 after the baseline; see $STATUS1"
  grep -q ', 0 conflict, 0 collision,' "$STATUS1" \
    || fail "duo status ${PAIR}1 is not clean after the baseline; see $STATUS1"
else
  plan "(cd $HOST_R1 && php $DUO --envs-file=$ENVS_FILE status ${PAIR}1)"
fi
pass "step 2 — baseline committed on main; duo status ${PAIR}1 is clean"

# ---------------------------------------------------------------------------
# Step 3 — duo assess.
# ---------------------------------------------------------------------------
say "step 3/13 — duo assess ${PAIR}1 (JSON document, then the human leak gate)"
ASSESS1_JSON="$EVIDENCE/assess-${PAIR}1.json"
ASSESS1_HUMAN="$EVIDENCE/assess-${PAIR}1.txt"
if ! dry; then
  duo_in "$HOST_R1" assess "${PAIR}1" --format=json > "$SCRATCH/assess1.raw" \
    || fail "duo assess ${PAIR}1 --format=json refused; see $SCRATCH/assess1.raw"
  mup_json_tail "$SCRATCH/assess1.raw" > "$ASSESS1_JSON"
  mup_assert_assess_document "$ASSESS1_JSON" || fail "step 3: the assess document is not usable"
  EXPECT_PRODUCTS=$'authored\tmanage\tReady\tPlatform-certified\tprevented'
  GOT_PRODUCTS="$(mup_assess_projection "$ASSESS1_JSON" post_type:product release | cut -f1-5)"
  [ "$GOT_PRODUCTS" = "$EXPECT_PRODUCTS" ] \
    || fail "step 3: products projected '$GOT_PRODUCTS', expected '$EXPECT_PRODUCTS'"
  EXPECT_ORDERS=$'runtime\tpreserve local\tUnsupported'
  GOT_ORDERS="$(mup_assess_projection "$ASSESS1_JSON" post_type:shop_order release | cut -f1-3)"
  [ "$GOT_ORDERS" = "$EXPECT_ORDERS" ] \
    || fail "step 3: orders projected '$GOT_ORDERS', expected '$EXPECT_ORDERS'"

  duo_in "$HOST_R1" assess "${PAIR}1" > "$ASSESS1_HUMAN" 2>&1 \
    || fail "duo assess ${PAIR}1 (human) refused; see $ASSESS1_HUMAN"
  mup_assert_no_internal_ids "$ASSESS1_HUMAN" "duo assess ${PAIR}1" \
    || fail "step 3: MUP §5.2 — the human assess view printed an identifier no documented command consumes"
else
  plan "(cd $HOST_R1 && php $DUO --envs-file=$ENVS_FILE assess ${PAIR}1 --format=json)"
  plan "(cd $HOST_R1 && php $DUO --envs-file=$ENVS_FILE assess ${PAIR}1)"
fi
pass "step 3 — assess validates; products and orders project the §6.1 words; the human view leaks no internal id"

# ---------------------------------------------------------------------------
# Step 4 — propose, review, accept the application contract.
# ---------------------------------------------------------------------------
say "step 4/13 — duo contract ${PAIR}1 propose -> review -> accept"
PROPOSED="$HOST_R1/.duo/contract/proposed.json"
CONTRACT="$HOST_R1/.duo/contract/contract.json"
PROJECTION="$HOST_R1/.duo/contract/projection.json"
duo_in "$HOST_R1" contract "${PAIR}1" propose

# The review step MUP §3.4 requires a human for, performed here as the exact
# edit that review consists of: the generated code-lifecycle entry is
# `decided_by: unresolved` and ApplicationContract::validate() refuses to
# accept it that way (external_effect_unreviewed). §3.2's reviewed entry
# declares the window `live`, states what restores it, and names the operator
# as the deciding principal. The two journeys §2.4 verifies are declared in
# the same edit, because a contract with no journey makes verification
# byte-level only and step 10 asserts otherwise.
if ! dry; then
  [ -f "$PROPOSED" ] || fail "step 4: duo contract propose wrote no $PROPOSED"
  jq --arg reason "the only external effect this site's installed set has in the lifecycle window is WordPress' own activation/deactivation hooks; reviewed against woocommerce $WOO_VERSION and $THEME_SLUG $THEME_VERSION for this grind, which run no mail, payment or webhook code" \
     --arg shop "$SHOP_PATH" --arg landing "$LANDING_PATH" '
    .contract.declarations.external_effects =
      [ (.contract.declarations.external_effects[]
         | select(.id == "code-lifecycle-window")
         | .containment = "live"
         | .effect_recovery_semantics = "provider-state restorable"
         | .restored_by = "code release"
         | .reason = $reason
         | .decided_by = "operator"
         | .decided_at = "2026-08-17T09:02:11Z")
      ]
    | .contract.declarations.journeys = [
        {id: "shop-index", url: $shop, expect_status: 200,
         expect_contains: "Duo Ceramic Mug", affected_surfaces: ["post_type:product"]},
        {id: "landing-page", url: $landing, expect_status: 200,
         expect_contains: "Duo grind landing page", affected_surfaces: ["post_type:page"]}
      ]
  ' "$PROPOSED" > "$PROPOSED.reviewed" \
    || fail "step 4: could not review the proposed contract"
  jq -e '(.contract.declarations.external_effects | length) == 1
    and .contract.declarations.external_effects[0].decided_by == "operator"
    and .contract.declarations.external_effects[0].containment == "live"
    and (.contract.declarations.journeys | length) == 2' "$PROPOSED.reviewed" >/dev/null \
    || fail "step 4: the reviewed proposal does not carry the declared lifecycle window and two journeys"
  mv "$PROPOSED.reviewed" "$PROPOSED"
else
  plan "jq: review .duo/contract/proposed.json — declare code-lifecycle-window live/provider-state restorable/operator; declare journeys $SHOP_PATH and $LANDING_PATH"
fi

duo_in "$HOST_R1" contract "${PAIR}1" accept
DIGEST_A=""
DIGEST_B=""
if ! dry; then
  [ -f "$CONTRACT" ] || fail "step 4: accept wrote no $CONTRACT"
  [ -f "$PROJECTION" ] || fail "step 4: accept wrote no $PROJECTION"
  jq -e '.attestation.state == "unsigned"' "$CONTRACT" >/dev/null \
    || fail "step 4: this profile may only ever write attestation.state unsigned"
  duo_in "$HOST_R1" contract "${PAIR}1" show --format=json > "$SCRATCH/show-a.raw"
  duo_in "$HOST_R1" contract "${PAIR}1" show --format=json > "$SCRATCH/show-b.raw"
  DIGEST_A="$(mup_json_tail "$SCRATCH/show-a.raw" | jq -r '.contract.contract_digest // .contract_digest')"
  DIGEST_B="$(mup_json_tail "$SCRATCH/show-b.raw" | jq -r '.contract.contract_digest // .contract_digest')"
  [ -n "$DIGEST_A" ] && [ "$DIGEST_A" = "$DIGEST_B" ] \
    || fail "step 4: contract_digest is not stable across two duo contract show runs ('$DIGEST_A' vs '$DIGEST_B')"
else
  DIGEST_A='<contract-digest>'
  plan "(cd $HOST_R1 && php $DUO --envs-file=$ENVS_FILE contract ${PAIR}1 show --format=json)  # twice, same digest"
fi
git1 add -A
git1 -c user.name=duo -c user.email=duo@example.test commit -qm "grind_mup: accept the reviewed application contract"
git1 push -q origin main
pass "step 4 — contract.json + projection.json accepted, digest stable, attestation unsigned ($DIGEST_A)"

# ---------------------------------------------------------------------------
# Step 5 — rehearse the preview from side 1.
# ---------------------------------------------------------------------------
say "step 5/13 — duo rehearse preview --from ${PAIR}1 --branch main"
REHEARSE_OUT="$EVIDENCE/rehearse.txt"
BANNER='containment: unknown — not enforced in this profile; do not point this environment at live payment or mail credentials.'
if ! dry; then
  duo_in "$HOST_R1" rehearse preview --from "${PAIR}1" --branch main > "$REHEARSE_OUT" 2>&1 \
    || fail "duo rehearse preview refused or failed; see $REHEARSE_OUT"
  [ "$(head -n 1 "$REHEARSE_OUT")" = "$BANNER" ] \
    || fail "step 5: the containment banner is not the first line of the rehearsal report; see $REHEARSE_OUT"
  [ "$(grep -cF "$BANNER" "$REHEARSE_OUT")" = 1 ] \
    || fail "step 5: the containment banner is printed more than once"
  grep -Fq 'what a release would touch' "$REHEARSE_OUT" \
    || fail "step 5: the rehearsal printed no 'what a release would touch' preview"
  grep -Fq 'cannot authorize an Experimental or Uncertified capability' "$REHEARSE_OUT" \
    || fail "step 5: the rehearsal did not state what a preview cannot authorize"
  [ -f "$HOST_R2/site.duo.json" ] \
    || fail "step 5: the preview converged but $HOST_R2 carries no materialized site repository"
else
  plan "(cd $HOST_R1 && php $DUO --envs-file=$ENVS_FILE rehearse preview --from ${PAIR}1 --branch main)"
fi
pass "step 5 — preview converged; banner present and printed once; release preview present"

# ---------------------------------------------------------------------------
# Step 6 — author on the preview; capture twice, zero diff.
# ---------------------------------------------------------------------------
say "step 6/13 — edit one product price and one page body on the preview, then capture twice"
NEW_PRICE=31.50
NEW_BODY='<p>Duo grind landing page, released through duo release.</p>'
if ! dry; then
  PREVIEW_PRODUCT_ID="$(wp2 post list --post_type=product --name=duo-ceramic-mug --field=ID | tr -d '\r' | head -1)"
  [ -n "$PREVIEW_PRODUCT_ID" ] \
    || fail "step 6: the preview carries no duo-ceramic-mug product to edit"
  PREVIEW_PAGE_ID="$(wp2 post list --post_type=page --name=duo-grind-landing --field=ID | tr -d '\r' | head -1)"
  [ -n "$PREVIEW_PAGE_ID" ] \
    || fail "step 6: the preview carries no duo-grind-landing page to edit"
  wp2 post meta update "$PREVIEW_PRODUCT_ID" _regular_price "$NEW_PRICE"
  wp2 post meta update "$PREVIEW_PRODUCT_ID" _price "$NEW_PRICE"
  wp2 post update "$PREVIEW_PAGE_ID" --post_content="$NEW_BODY"
else
  PREVIEW_PRODUCT_ID='<preview-product-id>'; PREVIEW_PAGE_ID='<preview-page-id>'
  plan "wp2 post meta update <product> _regular_price $NEW_PRICE  # and _price"
  plan "wp2 post update <page> --post_content=..."
fi
duo_in "$HOST_R2" capture preview
duo_in "$HOST_R2" capture preview --out=/siterepo/.tmp-state2
if ! dry; then
  diff -r "$HOST_R2/state" "$HOST_R2/.tmp-state2" \
    || fail "step 6: capture is not deterministic on the preview"
  rm -rf "$HOST_R2/.tmp-state2"
fi
pass "step 6 — the preview's two authored edits captured; capture twice is byte-identical"

# ---------------------------------------------------------------------------
# Step 7 — commit and merge to main in the origin.
# ---------------------------------------------------------------------------
say "step 7/13 — commit the preview's capture and merge it to main in the origin"
git2 add -A
git2 -c user.name=duo -c user.email=duo@example.test commit -qm "grind_mup: authored price and page-body edit from the preview"
git2 push -q origin HEAD:main
MAIN_SHA=""
if ! dry; then
  MAIN_SHA="$(git -C "$ORIGIN" rev-parse main)"
  [ -n "$MAIN_SHA" ] || fail "step 7: the origin has no main revision after the merge"
else
  dry_set MAIN_SHA '<main-sha>'
  plan "git -C $ORIGIN rev-parse main"
fi
pass "step 7 — origin main is $MAIN_SHA"

# ---------------------------------------------------------------------------
# Step 7b — return the release target's LIVE values to their pre-release state.
#
# Not in §6.1's table, and here on purpose. §6.1 makes side 2 both the preview
# and the release target, so the edit authored at step 6 is already live on the
# target the moment it is captured — and a release with nothing to apply cannot
# prove that step 11 restored anything. Reverting exactly the two live values
# the release is about to write puts the target back in the state a release
# target is actually in: the repository carries the change, the site does not
# yet. Nothing else on the target is touched, and the revert is asserted
# afterwards so it cannot silently no-op.
# ---------------------------------------------------------------------------
say "step 7b/13 — revert the two live values on ${PAIR}2 so the release has something to apply"
PRE_PRICE=24.00
PRE_BODY='<p>Duo grind landing page, before the release.</p>'
if ! dry; then
  wp2 post meta update "$PREVIEW_PRODUCT_ID" _regular_price "$PRE_PRICE"
  wp2 post meta update "$PREVIEW_PRODUCT_ID" _price "$PRE_PRICE"
  wp2 post update "$PREVIEW_PAGE_ID" --post_content="$PRE_BODY"
  [ "$(wp2 post meta get "$PREVIEW_PRODUCT_ID" _regular_price | tr -d '\r')" = "$PRE_PRICE" ] \
    || fail "step 7b: the live price revert did not take"
else
  plan "wp2 post meta update <product> _regular_price $PRE_PRICE  # and _price, and the page body"
fi
pass "step 7b — the target's live price and page body are back at their pre-release values"

# ---------------------------------------------------------------------------
# Step 8 — the frozen authorization plan, --plan-only.
# ---------------------------------------------------------------------------
say "step 8/13 — duo release ${PAIR}2 --from=$MAIN_SHA --plan-only --format=json"
PLAN_JSON="$EVIDENCE/authorization-plan.json"
if ! dry; then
  duo_in "$HOST_R2" release "${PAIR}2" --from="$MAIN_SHA" --plan-only --format=json \
    > "$SCRATCH/plan-only.raw" 2>"$SCRATCH/plan-only.err" \
    || fail "duo release --plan-only refused; see $SCRATCH/plan-only.raw and $SCRATCH/plan-only.err"
  mup_json_tail "$SCRATCH/plan-only.raw" > "$PLAN_JSON"
  mup_assert_plan_document "$PLAN_JSON" || fail "step 8: the authorization plan is incomplete"
  mup_assert_claim_literal "$PLAN_JSON" '.recovery_profile.claim' \
    || fail "step 8: the plan's embedded recovery claim is not literal"
  [ "$(jq -r '.contract_digest' "$PLAN_JSON")" = "$DIGEST_A" ] \
    || fail "step 8: the plan cites a contract digest that is not the accepted one"
  PLAN_DIGEST="$(jq -r '.plan_digest' "$PLAN_JSON")"
  RECOVERY_PROFILE="$(jq -r '.recovery_profile.selected' "$PLAN_JSON")"
  PLAN_BOUNDARY="$(mup_claim_boundary "$PLAN_JSON" '.recovery_profile.claim')"
  UPDATES="$(jq -r '.scope.entities.update + .scope.entities.create' "$PLAN_JSON")"
  [ "$UPDATES" -gt 0 ] \
    || fail "step 8: the frozen plan authorizes no entity change, so steps 9-12 would prove nothing"
  # --plan-only mutates nothing at all, including the site repository.
  [ ! -d "$HOST_R2/.duo/releases" ] || [ -z "$(ls -A "$HOST_R2/.duo/releases" 2>/dev/null)" ] \
    || fail "step 8: --plan-only froze a plan to disk"
else
  PLAN_DIGEST='<plan-digest>'; RECOVERY_PROFILE='operator-directed'
  PLAN_BOUNDARY='writes committed after checkpoint <ts>'; UPDATES='<n>'
  plan "(cd $HOST_R2 && php $DUO --envs-file=$ENVS_FILE release ${PAIR}2 --from=$MAIN_SHA --plan-only --format=json)"
fi
pass "step 8 — plan $PLAN_DIGEST cites the contract, authorizes $UPDATES change(s), names profile '$RECOVERY_PROFILE'"
pass "step 8 — maximum loss boundary, verbatim: $PLAN_BOUNDARY"

# ---------------------------------------------------------------------------
# Step 9 — release, deploy before apply.
# ---------------------------------------------------------------------------
say "step 9/13 — duo release ${PAIR}2 --from=$MAIN_SHA --yes"
RELEASE_OUT="$EVIDENCE/release.txt"
if ! dry; then
  duo_in "$HOST_R2" release "${PAIR}2" --from="$MAIN_SHA" --yes > "$RELEASE_OUT" 2>&1 \
    || { cat "$RELEASE_OUT" >&2; fail "duo release ${PAIR}2 did not exit 0"; }
  grep -Fq "authorization frozen: " "$RELEASE_OUT" \
    || fail "step 9: the release printed no frozen-plan path, so nothing was durably bound before mutation"
  # Deploy before apply, read from promote's own receipts. `code-stage` and
  # `code-finalize` only appear when the artifact declares code, so they are
  # asserted in order relative to each other only if they are present at all.
  mup_assert_phase_order "$RELEASE_OUT" \
    'promote phase: promotion-begin' \
    'promote phase: checkpoint' \
    'promote phase: lifecycle-retire' \
    'promote phase: lifecycle-activate' \
    'promote phase: apply' \
    || fail "step 9: deploy-before-apply ordering is not observable in the release receipts"
  if grep -Fq 'promote phase: code-stage' "$RELEASE_OUT"; then
    mup_assert_phase_order "$RELEASE_OUT" \
      'promote phase: code-stage' 'promote phase: lifecycle-retire' \
      'promote phase: code-finalize' 'promote phase: apply' \
      || fail "step 9: the code phases did not bracket the lifecycle before apply"
  fi
  [ -n "$(ls -A "$HOST_R2/.duo/releases" 2>/dev/null)" ] \
    || fail "step 9: the release left no frozen plan in .duo/releases"
else
  plan "(cd $HOST_R2 && php $DUO --envs-file=$ENVS_FILE release ${PAIR}2 --from=$MAIN_SHA --yes)"
fi
pass "step 9 — released; the receipts show deploy before apply"

# ---------------------------------------------------------------------------
# Step 10 — verify.
# ---------------------------------------------------------------------------
say "step 10/13 — duo verify ${PAIR}2"
VERIFY_JSON="$EVIDENCE/verify.json"
if ! dry; then
  duo_in "$HOST_R2" verify "${PAIR}2" --format=json > "$SCRATCH/verify.raw" 2>&1 \
    || { cat "$SCRATCH/verify.raw" >&2; fail "duo verify ${PAIR}2 did not pass"; }
  mup_json_tail "$SCRATCH/verify.raw" > "$VERIFY_JSON"
  mup_assert_verify_report "$VERIFY_JSON" || fail "step 10: the verify report is not a pass"
  jq -e '.uncovered_surfaces | type == "array"' "$VERIFY_JSON" >/dev/null \
    || fail "step 10: uncovered_surfaces was not reported"
  note "uncovered surfaces: $(jq -r '.uncovered_surfaces | join(", ") | if . == "" then "(none)" else . end' "$VERIFY_JSON")"
else
  plan "(cd $HOST_R2 && php $DUO --envs-file=$ENVS_FILE verify ${PAIR}2 --format=json)"
fi
pass "step 10 — convergence pass, both declared journeys pass, uncovered surfaces reported"

# Pre-recovery truth: what the release actually wrote, read from the target.
POST_RELEASE_PRICE=""
POST_RELEASE_BODY=""
if ! dry; then
  POST_RELEASE_PRICE="$(wp2 post meta get "$PREVIEW_PRODUCT_ID" _regular_price | tr -d '\r')"
  POST_RELEASE_BODY="$(wp2 post get "$PREVIEW_PAGE_ID" --field=post_content | tr -d '\r')"
  [ "$POST_RELEASE_PRICE" = "$NEW_PRICE" ] \
    || fail "step 10: the release did not write the authored price (target says '$POST_RELEASE_PRICE')"
  case "$POST_RELEASE_BODY" in
    *"released through duo release"*) ;;
    *) fail "step 10: the release did not write the authored page body" ;;
  esac
fi
PRE_RELEASE_ASSESS="$EVIDENCE/assess-${PAIR}2-pre-recovery.json"
if ! dry; then
  duo_in "$HOST_R2" assess "${PAIR}2" --format=json > "$SCRATCH/assess2-pre.raw" \
    || fail "step 10: duo assess ${PAIR}2 refused before recovery"
  mup_json_tail "$SCRATCH/assess2-pre.raw" > "$PRE_RELEASE_ASSESS"
else
  plan "(cd $HOST_R2 && php $DUO --envs-file=$ENVS_FILE assess ${PAIR}2 --format=json)  # pre-recovery projection"
fi

# ---------------------------------------------------------------------------
# Step 11 — the thesis gate.
# ---------------------------------------------------------------------------
say "step 11/13 — a runtime row written after the checkpoint, then duo recover"
ORDER_ID=""
if ! dry; then
  # `post_type:shop_order` is what the pinned WooCommerce manifest classes
  # `runtime`, which is why this row is the right one: the manifest, not this
  # script, decides that it is runtime, and MUP §1.2 says a runtime surface is
  # preserved locally and never copied. It is written AFTER the release's
  # checkpoint, so the printed boundary is the only thing that decides its fate.
  ORDER_ID="$(wp2 post create --post_type=shop_order --post_status=publish \
    --post_title='duo-grind-post-checkpoint-order' --porcelain | tr -d '\r')"
  [ -n "$ORDER_ID" ] || fail "step 11: could not write a post-checkpoint runtime row on ${PAIR}2"
  [ "$(wp2 post list --post_type=shop_order --post_status=publish --field=ID \
      | tr -d '\r' | grep -cx "$ORDER_ID" || true)" = 1 ] \
    || fail "step 11: the post-checkpoint runtime row is not present before recovery"
else
  ORDER_ID='<order-id>'
  plan "wp2 post create --post_type=shop_order --post_status=publish --porcelain"
fi

CATALOG_JSON="$EVIDENCE/checkpoint-catalog.json"
RECOVER_LIST="$EVIDENCE/recover-list.txt"
RECOVER_OUT="$EVIDENCE/recover.txt"
RECOVER_JSON="$EVIDENCE/recover-outcome.json"
LIST_RC=0
if dry; then
  plan "(cd $HOST_R2 && php $DUO --envs-file=$ENVS_FILE recover ${PAIR}2 --list --format=json)"
  plan "(cd $HOST_R2 && php $DUO --envs-file=$ENVS_FILE recover ${PAIR}2 --restore=<receipt-id> --writers-excluded)"
  plan "wp2 post meta get <product> _regular_price   # must be $PRE_PRICE again"
  plan "wp2 post list --post_type=shop_order         # fate must match: $PLAN_BOUNDARY"
else
  duo_in "$HOST_R2" recover "${PAIR}2" --list --format=json > "$SCRATCH/recover-list.raw" 2>"$RECOVER_LIST" \
    || LIST_RC=$?
fi

if [ "$LIST_RC" != 0 ] && ! dry; then
  # The one refusal this transport can legitimately produce, named exactly.
  if grep -Fq 'recovery_authority_unavailable' "$RECOVER_LIST" \
     || grep -Fq 'carries no rollback authority runtime' "$RECOVER_LIST"; then
    if [ "$STEP11" = record-gap ]; then
      note "duo recover refused: this transport carries no rollback authority runtime"
      mup_assert_claim_literal "$PLAN_JSON" '.recovery_profile.claim' \
        || fail "step 11: even in record-gap mode, the frozen plan's claim must be literal"
      printf '\n\033[1;31mTHESIS GATE NOT EXECUTED — step 11 ran in MUP_STEP11=record-gap mode\033[0m\n' >&3
      printf 'THESIS GATE NOT EXECUTED — step 11 ran in MUP_STEP11=record-gap mode\n' >&2
      STEP11_EXECUTED=0
    else
      fail "step 11: duo recover ${PAIR}2 --list refused with recovery_authority_unavailable.
RecoverCommand::authorityTransport() accepts an SSH transport and nothing else, and
sandbox/bin/pair.sh publishes no sshd, so MUP §2.5's verb cannot reach the checkpoint
MUP §6.1's own grind just took. That is the gap; it is not worked around here.
Run with MUP_STEP11=record-gap to collect the other twelve steps' evidence while it
is open, or point ${PAIR}2 at an SSH-adopted target."
    fi
  else
    cat "$RECOVER_LIST" >&2
    fail "step 11: duo recover --list failed for a reason this grind does not recognize"
  fi
elif ! dry; then
  mup_json_tail "$SCRATCH/recover-list.raw" > "$CATALOG_JSON" 2>/dev/null \
    || cp "$SCRATCH/recover-list.raw" "$CATALOG_JSON"
  RECEIPT_ID="$(mup_checkpoint_id "$CATALOG_JSON")" \
    || fail "step 11: the checkpoint catalog names no receipt to restore"
  pass "step 11 — the release's checkpoint is listed as receipt $RECEIPT_ID"

  duo_in "$HOST_R2" recover "${PAIR}2" --restore="$RECEIPT_ID" --writers-excluded \
    > "$RECOVER_OUT" 2>&1 \
    || { cat "$RECOVER_OUT" >&2; fail "step 11: duo recover --restore did not complete"; }

  # The claim is printed BEFORE acting. `restores:` precedes the first driven
  # step in the output, or the operator read it too late to stop.
  CLAIM_LINE="$(grep -n -m1 -E '^(restores|does not restore)' "$RECOVER_OUT" | cut -d: -f1 || true)"
  ACTION_LINE="$(grep -n -m1 -E 'abort|begin|import|signed-rollback' "$RECOVER_OUT" | cut -d: -f1 || true)"
  [ -n "$CLAIM_LINE" ] \
    || fail "step 11: duo recover printed no recovery claim; see $RECOVER_OUT"
  if [ -n "$ACTION_LINE" ]; then
    [ "$CLAIM_LINE" -lt "$ACTION_LINE" ] \
      || fail "step 11: the recovery claim was printed after recovery started; see $RECOVER_OUT"
  fi
  grep -Fq "$PLAN_BOUNDARY" "$RECOVER_OUT" \
    || fail "step 11: the boundary printed at recovery is not the one the frozen plan printed.
plan:     $PLAN_BOUNDARY
recovery: $(grep -i 'maximum loss' "$RECOVER_OUT" || echo '(not printed)')"
  pass "step 11 — the claim printed at recovery is the claim the plan printed, boundary included"

  # The literal test. The boundary sentence says what happens to a write
  # committed after the checkpoint; the target is then asked, and the two must
  # agree exactly.
  EXPECTATION="$(mup_boundary_expectation "$PLAN_BOUNDARY")" \
    || fail "step 11: the printed boundary is not one this grind can read literally"
  ORDER_PRESENT="$(wp2 post list --post_type=shop_order --post_status=publish --field=ID 2>/dev/null \
    | tr -d '\r' | grep -cx "$ORDER_ID" || true)"
  case "$EXPECTATION" in
    lost)
      [ "$ORDER_PRESENT" = 0 ] \
        || fail "step 11: the claim said '$PLAN_BOUNDARY' but the post-checkpoint runtime row $ORDER_ID survived recovery.
The claim is literal or this test fails."
      pass "step 11 — the post-checkpoint runtime row is gone, exactly as the boundary said" ;;
    unbounded)
      fail "step 11: the release selected a profile whose boundary bounds nothing; this grind never authorizes that" ;;
  esac

  RESTORED_PRICE="$(wp2 post meta get "$PREVIEW_PRODUCT_ID" _regular_price | tr -d '\r')"
  RESTORED_BODY="$(wp2 post get "$PREVIEW_PAGE_ID" --field=post_content | tr -d '\r')"
  [ "$RESTORED_PRICE" = "$PRE_PRICE" ] \
    || fail "step 11: the product price is '$RESTORED_PRICE', not the pre-release '$PRE_PRICE'"
  case "$RESTORED_BODY" in
    *"before the release"*) ;;
    *) fail "step 11: the page body was not restored to its pre-release value" ;;
  esac
  pass "step 11 — product and page are back at their pre-release values"
  STEP11_EXECUTED=1
fi

# ---------------------------------------------------------------------------
# Step 12 — the post-recovery projection.
# ---------------------------------------------------------------------------
say "step 12/13 — duo assess ${PAIR}2 after recovery"
POST_RECOVERY_ASSESS="$EVIDENCE/assess-${PAIR}2-post-recovery.json"
if ! dry; then
  duo_in "$HOST_R2" assess "${PAIR}2" --format=json > "$SCRATCH/assess2-post.raw" \
    || fail "step 12: duo assess ${PAIR}2 refused after recovery"
  mup_json_tail "$SCRATCH/assess2-post.raw" > "$POST_RECOVERY_ASSESS"
  mapfile -t SCOPE_SURFACES < <(jq -r '.scope.surfaces[]' "$PLAN_JSON")
  if [ "${#SCOPE_SURFACES[@]}" -eq 0 ]; then
    fail "step 12: the frozen plan names no surfaces in scope, so there is nothing to compare"
  fi
  BEFORE="$(mup_projection_subset "$PRE_RELEASE_ASSESS" "${SCOPE_SURFACES[@]}")"
  AFTER="$(mup_projection_subset "$POST_RECOVERY_ASSESS" "${SCOPE_SURFACES[@]}")"
  if [ "$BEFORE" != "$AFTER" ]; then
    printf 'before: %s\nafter:  %s\n' "$BEFORE" "$AFTER" >&2
    fail "step 12: the post-recovery projection differs from the pre-release projection for the surfaces in scope"
  fi
  pass "step 12 — the projection for ${#SCOPE_SURFACES[@]} in-scope surface(s) is unchanged by the release and recovery"
else
  plan "(cd $HOST_R2 && php $DUO --envs-file=$ENVS_FILE assess ${PAIR}2 --format=json)  # compare in-scope projection"
fi

# ---------------------------------------------------------------------------
# Step 13 — reap, twice.
# ---------------------------------------------------------------------------
say "step 13/13 — duo rehearse preview --reap, then again"
REAP1="$EVIDENCE/reap-1.txt"
REAP2="$EVIDENCE/reap-2.txt"
if ! dry; then
  duo_in "$HOST_R1" rehearse preview --reap > "$REAP1" 2>&1 \
    || { cat "$REAP1" >&2; fail "step 13: the first reap failed"; }
  grep -Eq 'destroyed|detached' "$REAP1" \
    || fail "step 13: the first reap receipt says neither destroyed nor detached; see $REAP1"
  duo_in "$HOST_R1" rehearse preview --reap > "$REAP2" 2>&1 \
    || { cat "$REAP2" >&2; fail "step 13: the repeated reap was not idempotent"; }
  grep -Eq 'destroyed|detached' "$REAP2" \
    || fail "step 13: the repeated reap printed no receipt; see $REAP2"
  pass "step 13 — reap receipt: $(grep -Eom1 'destroyed|detached' "$REAP1"); repeating it is idempotent"
else
  plan "(cd $HOST_R1 && php $DUO --envs-file=$ENVS_FILE rehearse preview --reap)  # twice"
fi

# ---------------------------------------------------------------------------
if dry; then
  printf '\n\033[1;32mGRIND_MUP DRY RUN COMPLETE — nothing above was executed\033[0m\n' >&3
  exit 0
fi
if [ "$FAILURES" -ne 0 ]; then
  printf '\nGRIND_MUP FAILED (%d)\n' "$FAILURES" >&2
  exit 1
fi
if [ "$STEP11_EXECUTED" != 1 ]; then
  printf '\n\033[1;33m✔ GRIND_MUP steps 1-10, 12-13 PASSED — STEP 11 (THESIS GATE) NOT EXECUTED\033[0m\n' >&3
  printf 'evidence: %s\n' "$EVIDENCE" >&3
  exit 0
fi
printf '\n\033[1;32m✔ GRIND_MUP PASSED (13/13, step 11 executed)\033[0m\n' >&3
printf 'evidence: %s\n' "$EVIDENCE" >&3
