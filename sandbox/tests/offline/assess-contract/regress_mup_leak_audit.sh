#!/usr/bin/env bash
# Regression — round-3 MUP §5.1, §5.2, §5.3 (the phase-A leak closures) as a
# gate, per §6.2's row: "no internal identifier in the human view of
# assess/release/verify/recover unless a documented command consumes it;
# every `wp duo` command is either host-driven or named in
# docs/guides/internals.md".
#
# Three independent properties. Each one closes a leak the product spec names
# explicitly — "normal operation never depends on private identifiers,
# undocumented commands, or raw database surgery" — and each one is checked
# against OUTPUT or SOURCE, never against a promise in a docblock.
#
#   (a) INTERNAL IDENTIFIERS (§5.2). The human view of `duo assess`,
#       `duo release --plan-only`, `duo verify`, `duo recover --list`,
#       `duo recover --restore` and `duo rehearse` may print an internal
#       identifier only where a documented command consumes it. The allowlist
#       is closed and is exactly §5.2's: the `<bucket>:<uuid>` selector
#       `duo explain` takes, the receipt ids `duo recover --restore=<id>`
#       takes, and the `plan_digest` `duo verify --plan=<digest>` takes.
#       Artifact hashes, lease owners, operation ids and session ids are
#       `--format=json` only.
#
#       The views are produced by running the REAL `php cli/duo` verbs
#       against the fixture sites the T2/T3 suites already build — which is
#       the point of reusing them rather than writing a sixth: an audit that
#       rendered its own view would be auditing the audit. Each view is
#       compared against the SAME command's `--format=json` document, so the
#       machine document is the authority on what an identifier is and the
#       human view is the thing on trial.
#
#   (b) COMMAND SURFACE (§5.1). Every public `wp duo` subcommand is either
#       driven by a host verb or named in `docs/guides/internals.md` with
#       §5.1's literal sentence. Neither is a value judgement about the
#       command; the point is that an operator can never find one that is
#       reachable, undocumented, and unexplained.
#
#   (c) RAW RECOVERY (§5.3). The four-ordered-commands recipe — abort,
#       re-begin, isolated import, mandatory final abort — is gone from
#       `docs/guides/**` except `internals.md`, replaced by
#       `duo recover <env> --restore=<id> --writers-excluded`. Naming the
#       runtime in prose is still allowed and is in fact required: §5.3
#       retires it as an ENTRY POINT, and saying so is documentation. What is
#       forbidden is a runnable recipe.
#
# `--self-test` proves all three gates actually fail on an injected
# violation. A leak audit whose only evidence is that it printed `ok` is not
# evidence, and this suite ships green over a tree that has already been
# closed, so the self-test is the only thing standing between it and a
# permanently vacuous pass. Same precedent as
# sandbox/tests/spike/check_guide_commands.sh.
#
# `set -uo pipefail` rather than `-e`, matching its three sibling suites
# (regress_assess_composition.sh, regress_release_next_action.sh,
# regress_recover_ordering.sh): this suite accumulates failures and reports
# every leak in one run, and `-e` would stop at the first one — which for a
# leak audit is the least useful possible behaviour.
#
# Offline: no docker, no WordPress, no network, no target, no pair.
set -uo pipefail

ROOT="$(cd "$(dirname "$0")/../../../.." && pwd)"
FIX="$ROOT/sandbox/tests/fixtures/mup-leak"
GUIDES="$ROOT/docs/guides"
TMP="$(mktemp -d "${TMPDIR:-/tmp}/duo-mup-leak-audit.XXXXXX")"
trap 'rm -rf "$TMP"' EXIT INT TERM

FAILURES=0
pass() { printf 'ok: %s\n' "$*"; }
fail() { printf 'FAIL: %s\n' "$*" >&2; FAILURES=$((FAILURES + 1)); }
say()  { printf '\n== %s ==\n' "$*"; }

# The four ordered raw-recovery commands, in the exact spellings cli/duo's
# print_promotion_recovery() emits them (cli/duo:2990-2993, built from
# CodeDeploy::abortArgs / beginArgs / recoveryDbImportArgs). Steps 1 and 4 are
# the same command, so three patterns cover four steps; the fourth pattern is
# an INVOCATION of the runtime itself, which is what §5.3 retires. A bare
# mention of the path is deliberately not matched — `php ` must precede it —
# because "driving `recovery/rollback-control.php` by hand is no longer an
# operator path" is exactly the sentence §5.3 wants the guides to carry.
RECOVERY_RECIPE_PATTERNS=(
  'wp duo promotion-abort'
  'wp duo promotion-begin'
  'wp db import'
  'php [^[:space:]]*rollback-control\.php'
)

# ---------------------------------------------------------------- part (c)
# scan_guides_recovery <dir> -> 0 clean, 1 any recipe citation. Prints one
# line per finding. internals.md is the one page allowed to name them.
scan_guides_recovery() {
  local dir="$1" status=0 file base pattern hits
  local files=0
  for file in "$dir"/*.md; do
    [ -e "$file" ] || continue
    base="$(basename "$file")"
    files=$((files + 1))
    [ "$base" = 'internals.md' ] && continue
    for pattern in "${RECOVERY_RECIPE_PATTERNS[@]}"; do
      hits="$(grep -nE -- "$pattern" "$file" || true)"
      [ -n "$hits" ] || continue
      while IFS= read -r hit; do
        [ -n "$hit" ] || continue
        printf 'RECIPE: %s:%s\n' "$base" "${hit%%:*}"
        printf '        cites the retired raw-recovery step /%s/ — MUP §5.3 replaces it with `duo recover <env> --restore=<id> --writers-excluded`\n' "$pattern"
      done <<< "$hits"
      status=1
    done
  done
  if [ "$files" -eq 0 ]; then
    printf 'RECIPE: no guide files found in %s\n' "$dir"
    return 1
  fi
  return "$status"
}

# ------------------------------------------------------------------ self-test
self_test() {
  local rc out
  local scratch="$TMP/self"
  mkdir -p "$scratch"

  # (a) the identifier scan must catch each shape and the friendly-named
  #     lease owner, and must NOT catch what the allowlist covers. A leak
  #     audit that has never been shown to fail is a `true` with paperwork.
  echo 'self-test A: a leaking human view must fail the identifier scan'
  mkdir -p "$scratch/a"
  cat > "$scratch/a/doc.json" <<'JSON'
{"format":"duo-fake/v1",
 "artifact_hash":"a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1",
 "owner":"a-friendly-owner-name",
 "code_revision_from":"ecad25e20897df04d4e37d639b1a9c92ceb88cfe",
 "plan_digest":"sha256:b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2"}
JSON
  cat > "$scratch/a/human.txt" <<'TXT'
entity 3f2504e0-4f89-41d3-9a0c-0305e82c3301 was updated
artifact a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1
owner a-friendly-owner-name
session 20260817-091402-0123456789abcdef0123456789abcdef
releasing code revision ecad25e20897df04d4e37d639b1a9c92ceb88cfe
plan sha256:b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2
TXT
  out="$(php "$FIX/identifier-scan.php" 'fake view' "$scratch/a/human.txt" "$scratch/a/doc.json" \
        --allow-key=plan_digest 2>&1)"
  rc=$?
  if [ "$rc" -eq 0 ]; then
    fail 'self-test A: the identifier scan accepted a leaking human view'
    printf '%s\n' "$out" >&2
  else
    local missing=0 needle
    for needle in 'UUID' '64-hex digest' '32-hex token' 'operation id' 'owner = a-friendly-owner-name'; do
      printf '%s\n' "$out" | grep -Fq "$needle" || { fail "self-test A: no finding named '$needle'"; missing=1; }
    done
    # The allowlisted plan digest and the 40-hex git revision are the two
    # things this view prints that are NOT leaks. Reporting either would make
    # the audit unusable, so their absence from the findings is a check.
    if printf '%s\n' "$out" | grep -Fq 'b2b2b2b2b2b2'; then
      fail 'self-test A: the allowlisted plan_digest was reported as a leak'
      missing=1
    fi
    if printf '%s\n' "$out" | grep -Fq 'ecad25e20897'; then
      fail 'self-test A: a 40-hex git revision was reported as an internal identifier'
      missing=1
    fi
    [ "$missing" -eq 0 ] \
      && pass 'self-test A: every shape and the friendly-named owner are reported, the git revision and the allowlisted digest are not'
  fi

  # An internal value that appears ONLY inside an allowlisted id is that id
  # being printed, not a leak (`duo recover --list`'s retained checkpoint id
  # is `promote-<lease owner>` by construction); the same value printed on
  # its own anywhere else on the page still is one. Both halves are checked.
  mkdir -p "$scratch/a2"
  cat > "$scratch/a2/doc.json" <<'JSON'
{"format":"duo-fake/v1","rows":[{"id":"promote-20260817-091402-0123456789abcdef0123456789abcdef","owner":"20260817-091402-0123456789abcdef0123456789abcdef"}]}
JSON
  printf 'checkpoints: 1\n  promote-20260817-091402-0123456789abcdef0123456789abcdef  retained  600s old\n' > "$scratch/a2/human.txt"
  if php "$FIX/identifier-scan.php" 'fake view' "$scratch/a2/human.txt" "$scratch/a2/doc.json" --allow-key=id >/dev/null 2>&1; then
    pass 'self-test A: an owner printed only inside the allowlisted id it is part of is not a leak'
  else
    fail 'self-test A: an owner embedded in the allowlisted id was reported as a leak'
  fi
  printf 'lease owner 20260817-091402-0123456789abcdef0123456789abcdef\n' >> "$scratch/a2/human.txt"
  if php "$FIX/identifier-scan.php" 'fake view' "$scratch/a2/human.txt" "$scratch/a2/doc.json" --allow-key=id >/dev/null 2>&1; then
    fail 'self-test A: the same owner printed on its own was accepted'
  else
    pass 'self-test A: the same owner printed on its own, outside the id, is still reported'
  fi

  # An allowlist entry the document cannot satisfy must fail too: allowing a
  # key that is not there would silently permit whatever replaced it.
  out="$(php "$FIX/identifier-scan.php" 'fake view' "$scratch/a/human.txt" "$scratch/a/doc.json" \
        --allow-key=not_a_field 2>&1)"
  if printf '%s\n' "$out" | grep -Fq "allowlisted key 'not_a_field' exists"; then
    pass 'self-test A: an allowlisted key the document does not carry is reported'
  else
    fail 'self-test A: an unreachable allowlist entry was accepted silently'
    printf '%s\n' "$out" >&2
  fi

  # (b) removing a row from internals.md must fail the disposition gate, and
  #     a row naming a command that does not exist must fail it too.
  echo 'self-test B: an internals.md missing a row must fail the disposition gate'
  grep -v '`wp duo orphans`' "$GUIDES/internals.md" > "$scratch/internals-short.md"
  out="$(php "$FIX/command-dispositions.php" "$ROOT" --internals="$scratch/internals-short.md" 2>&1)"
  rc=$?
  if [ "$rc" -ne 0 ] && printf '%s\n' "$out" | grep -Fq 'wp duo orphans'; then
    pass 'self-test B: a command dropped from the table is reported by name'
  else
    fail 'self-test B: dropping a documented internal did not fail the gate'
    printf '%s\n' "$out" >&2
  fi

  sed 's/`wp duo orphans`/`wp duo not-a-real-command`/' "$GUIDES/internals.md" > "$scratch/internals-stale.md"
  out="$(php "$FIX/command-dispositions.php" "$ROOT" --internals="$scratch/internals-stale.md" 2>&1)"
  rc=$?
  if [ "$rc" -ne 0 ] && printf '%s\n' "$out" | grep -Fq 'not-a-real-command'; then
    pass 'self-test B: a table row for a command that does not exist is reported too'
  else
    fail 'self-test B: a stale internals.md row was accepted'
    printf '%s\n' "$out" >&2
  fi

  # (c) an injected recipe citation must fail the guide scan, and the same
  #     citation inside internals.md must not. Built from a synthetic corpus
  #     rather than a copy of the real guides on purpose: this case is about
  #     the SCANNER, and seeding it from a tree that is itself under audit
  #     would make the self-test pass or fail for reasons that have nothing
  #     to do with the checker.
  echo 'self-test C: an injected raw-recovery recipe must fail the guide scan'
  mkdir -p "$scratch/guides-bad" "$scratch/guides-good"
  local corpus
  for corpus in "$scratch/guides-bad" "$scratch/guides-good"; do
    cat > "$corpus/internals.md" <<'MD'
# Internal wp duo commands

The raw recovery steps `wp duo promotion-abort`, `wp duo promotion-begin` and
`wp db import <checkpoint>` are named here, which is this page's whole job.
MD
  done
  cat > "$scratch/guides-bad/daily-workflow.md" <<'MD'
# Daily workflow

Then, in this order: abort the pair, re-begin it, run the fatal-safe isolated
`wp db import <checkpoint>`, and abort once more.
MD
  cat > "$scratch/guides-good/daily-workflow.md" <<'MD'
# Daily workflow

Recover with `duo recover production --restore=<receipt-id> --writers-excluded`;
the raw actions it drives are named in internals.md.
MD
  out="$(scan_guides_recovery "$scratch/guides-bad" 2>&1)"
  rc=$?
  if [ "$rc" -ne 0 ] && printf '%s\n' "$out" | grep -Fq 'daily-workflow.md:'; then
    pass 'self-test C: a recipe citation outside internals.md is reported with its file and line'
  else
    fail 'self-test C: an injected raw-recovery recipe was accepted'
    printf '%s\n' "$out" >&2
  fi
  if printf '%s\n' "$out" | grep -Fq 'internals.md:'; then
    fail 'self-test C: internals.md was reported, but that page documents the raw steps'
  else
    pass 'self-test C: internals.md is exempt even in a corpus that fails'
  fi
  out="$(scan_guides_recovery "$scratch/guides-good" 2>&1)"
  rc=$?
  if [ "$rc" -eq 0 ]; then
    pass 'self-test C: a corpus that publishes only duo recover passes'
  else
    fail 'self-test C: a clean corpus was rejected'
    printf '%s\n' "$out" >&2
  fi

  # And the scanner must refuse an empty corpus rather than call it clean.
  mkdir -p "$scratch/guides-empty"
  if scan_guides_recovery "$scratch/guides-empty" >/dev/null 2>&1; then
    fail 'self-test C: an empty guide directory was reported as clean'
  else
    pass 'self-test C: an empty guide directory is a failure, not a pass'
  fi
}

# ------------------------------------------------------------------ syntax
say 'syntax'
for file in "$FIX/identifier-scan.php" "$FIX/command-dispositions.php" "$FIX/rehearse-view.php"; do
  php -l "$file" >/dev/null || fail "php -l $file"
done
bash -n "$0" || fail "bash -n $0"
pass 'PHP and shell syntax'

if [ "${1:-}" = '--self-test' ]; then
  say 'self-test'
  self_test
  printf '\n'
  if [ "$FAILURES" -eq 0 ]; then
    echo '✔ REGRESS_MUP_LEAK_AUDIT SELF-TEST PASSED'
    exit 0
  fi
  echo "✘ REGRESS_MUP_LEAK_AUDIT SELF-TEST FAILED ($FAILURES)" >&2
  exit 1
elif [ "$#" -gt 0 ]; then
  echo "usage: $0 [--self-test]" >&2
  exit 2
fi

# ============================================================ part (a)
say '(a) internal identifiers in the human views'

# One fixture site serves assess, release, verify and the rehearsal
# disclosure: make-release-site.php extends the assess fixture rather than
# duplicating it, so all four verbs answer about the same site.
php "$ROOT/sandbox/tests/fixtures/release/make-release-site.php" "$TMP/rel" >/dev/null \
  || { echo 'FAIL: could not build the release fixture site' >&2; exit 1; }
php "$ROOT/sandbox/tests/fixtures/release/make-recover-site.php" "$TMP/rec" >/dev/null \
  || { echo 'FAIL: could not build the recover fixture site' >&2; exit 1; }

RSITE="$TMP/rel/repo"

# rel <name> <args...> — one `php cli/duo` run against the release fixture.
# stdout and stderr are BOTH the human view: an operator reads the terminal,
# not one stream of it, and a refusal is exactly where a renderer is most
# tempted to interpolate an operation id.
rel() {
  local name="$1"; shift
  ( cd "$RSITE" \
    && DUO_FIXTURES="$TMP/rel/fixtures" DUO_SITE_REPO="$RSITE" DUO_CALLS="$TMP/calls.txt" \
       PATH="$TMP/rel/bin:$PATH" \
       php "$ROOT/cli/duo" --envs-file="$TMP/rel/envs.json" "$@" ) \
    > "$TMP/$name.out" 2> "$TMP/$name.err"
  local status=$?
  cat "$TMP/$name.out" "$TMP/$name.err" > "$TMP/$name.human"
  return $status
}

# rec <name> <status-fixture> <args...> — the same, against the recover site.
rec() {
  local name="$1" statusFixture="$2"; shift 2
  ( cd "$TMP/rec/site" \
    && DUO_RECOVERY_RUNTIME_SOURCE="$ROOT/recovery/rollback-control.php" \
       DUO_WP_CALLS="$TMP/wp-calls.txt" \
       DUO_RECOVER_STATUS="$TMP/rec/status/$statusFixture.json" \
       PATH="$TMP/rec/bin:$PATH" \
       php "$ROOT/cli/duo" --envs-file="$TMP/rec/envs.json" "$@" ) \
    > "$TMP/$name.out" 2> "$TMP/$name.err"
  local status=$?
  cat "$TMP/$name.out" "$TMP/$name.err" > "$TMP/$name.human"
  return $status
}

# json_only <raw> <out> — `duo release` prints the human authorization plan
# BEFORE the machine document even under --format=json, because §2.3 requires
# the plan to be presented before anything else happens. Take the document
# from the first line that opens it.
json_only() {
  awk 'f || /^\{$/ { f = 1; print }' "$1" > "$2"
  [ -s "$2" ] || { fail "no JSON document in $1"; return 1; }
  return 0
}

# The reviewed contract, through the real propose -> review -> accept path.
# `decided_by: "unresolved"` is refused by ApplicationContract::validate(), so
# editing the proposal IS the human review step; this is the same edit
# regress_release_ref_binding.sh performs.
rel 'propose' contract fixture propose || { fail 'contract propose failed'; cat "$TMP/propose.err" >&2; }
php -r '
$path = $argv[1];
$proposal = json_decode((string) file_get_contents($path), true);
$contract = $proposal["contract"];
foreach ($contract["declarations"]["external_effects"] as $index => $effect) {
    if (($effect["decided_by"] ?? null) !== "unresolved") { continue; }
    $contract["declarations"]["external_effects"][$index]["decided_by"] = "operator";
    $contract["declarations"]["external_effects"][$index]["decided_at"] = "2026-08-17T09:02:11Z";
    $contract["declarations"]["external_effects"][$index]["reason"] =
        "reviewed 2026-08-17: nothing this site activates sends mail, calls a payment API, or fires a webhook";
}
foreach ($contract["declarations"]["surfaces"] as $index => $surface) {
    if (($surface["decided_by"] ?? null) !== "unresolved") { continue; }
    $contract["declarations"]["surfaces"][$index]["decided_by"] = "operator";
    // T6 SS3.6: an unmanaged plugin gets the ORDINARY decision an operator
    // makes for one -- runtime / preserve local, which projects Unsupported,
    // prints a meaning line and no next action, and sits outside every
    // release gate. Without it the row is unclassified/block and release
    // correctly refuses, which is the product working and not a fixture this
    // suite is about.
    if (strpos((string) ($surface["id"] ?? ""), "plugin:") === 0) {
        $contract["declarations"]["surfaces"][$index]["state_class"] = "runtime";
        $contract["declarations"]["surfaces"][$index]["handling"] = "preserve local";
    }
}
$proposal["contract"] = $contract;
file_put_contents($path, json_encode($proposal, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
' "$RSITE/.duo/contract/fixture/proposed.json"
rel 'accept' contract fixture accept || { fail 'contract accept failed'; cat "$TMP/accept.err" >&2; }

rel 'assess'      assess fixture
rel 'assess-json' assess fixture --format=json
rel 'release'      release fixture --plan-only
rel 'release-json' release fixture --plan-only --format=json
DUO_PLAN=plan-converged rel 'verify'      verify fixture
DUO_PLAN=plan-converged rel 'verify-json' verify fixture --format=json
rel 'rehearse-verb' rehearse fixture --reap

rec 'recover-list'      code recover fixture --list
rec 'recover-list-json' code recover fixture --list --format=json
rec 'recover-restore'      database-only recover fixture \
  --restore=receipt-recover-fixture --writers-excluded --operator-directed
rec 'recover-restore-json' database-only recover fixture \
  --restore=receipt-recover-fixture --writers-excluded --operator-directed --format=json

# The rehearsal preview: rendered rather than driven, for the reason stated in
# fixtures/mup-leak/rehearse-view.php. It regenerates the T3 rehearsal fixture
# through that suite's own generator rather than copying its two documents.
php "$FIX/rehearse-view.php" "$TMP/rehearse" > /dev/null \
  || fail 'could not render the rehearsal preview view'

for view in assess release verify recover-list recover-restore; do
  cp "$TMP/$view-json.out" "$TMP/$view.json" \
    || fail "the --format=json run for $view produced nothing"
done
json_only "$TMP/release-json.out" "$TMP/release.json"

# The rehearsal REFUSAL publishes no machine document of its own — it stops at
# the provider boundary before a preview exists — so the preview's document
# stands in as its identifier corpus. That makes the value scan vacuous for
# this one view and the SHAPE scan the whole check, which is the right shape
# for it: the question about a disclosure and a refusal is whether the prose
# carries a token it invented, not whether it echoed a field.
cp "$TMP/rehearse/rehearse.json" "$TMP/rehearse-verb.json"
cp "$TMP/rehearse/rehearse-human.txt" "$TMP/rehearse-preview.human"
cp "$TMP/rehearse/rehearse.json" "$TMP/rehearse-preview.json"

# scan <label> <view> [--allow-key=<leaf>]... — one view through the scanner.
# The allowlist is closed and is exactly §5.2's: nothing here may grow without
# a documented command that consumes what it admits.
scan() {
  local label="$1" view="$2"; shift 2
  if php "$FIX/identifier-scan.php" "$label" "$TMP/$view.human" "$TMP/$view.json" "$@"; then
    return 0
  fi
  fail "$label leaks an internal identifier into its human view (MUP §5.2)"
  return 1
}

# `probable_owner` is allowlisted for assess and only assess. It is not an
# internal identifier at all: it holds an ACTIVE PLUGIN SLUG, which the human
# view already prints as the `plugin:<slug>` surface id an operator types back
# into `duo contract accept` — so the value the scanner sees "leaked" is that
# same documented id, arriving through a second field. Coverage's own
# attribution for an undeclared table is the other value it can hold, and that
# is a plugin slug too. Leaving it out would make T6 §3.6's row unshippable
# for a reason §5.2 does not actually state.
scan 'duo assess <env>'                  assess --allow-key=probable_owner
scan 'duo release <env> --plan-only'     release --allow-key=plan_digest
scan 'duo verify <env>'                  verify
scan 'duo recover <env> --list'          recover-list    --allow-key=id
scan 'duo recover <env> --restore=<id>'  recover-restore --allow-key=id
scan 'duo rehearse <env> (disclosure and provider refusal)' rehearse-verb
scan 'duo rehearse <env> (preview)'      rehearse-preview

# ------------------------------------------- the allowlist is exercised, not
# assumed. An audit that passed because every view printed nothing would be
# indistinguishable from one that passed because the rule holds.
say '(a) the allowlisted identifiers are present and consumable'

RECEIPT="$(php -r '
$d = json_decode((string) file_get_contents($argv[1]), true);
echo (string) ($d["rows"][0]["id"] ?? "");
' "$TMP/recover-list.json")"
if [ -n "$RECEIPT" ] && grep -Fq -- "$RECEIPT" "$TMP/recover-list.human"; then
  pass "duo recover --list prints the receipt id --restore=<id> consumes ($RECEIPT)"
else
  fail 'duo recover --list did not print the receipt id its own --restore consumes'
fi
if grep -Fq -- "--restore=$RECEIPT" "$TMP/recover-restore.human" \
   || grep -Fq 'recover fixture: recovered' "$TMP/recover-restore.human"; then
  pass 'that receipt id is the value --restore actually takes'
else
  fail 'the receipt id printed by --list was not accepted by --restore'
fi

# The digest is read out of the human view itself rather than compared with
# the JSON run's: `--plan-only` freezes nothing, so the two runs are two
# authorizations and their digests are allowed to differ. What is being
# checked here is the RENDERING rule, and that is a property of one run.
PLAN_ELISION="$(grep -oE 'sha256:[0-9a-f]{12}…' "$TMP/release.human" | head -1)"
if [ -n "$PLAN_ELISION" ]; then
  pass "duo release prints the plan digest duo verify --plan=<digest> consumes, elided to 12 hex ($PLAN_ELISION)"
else
  fail 'duo release did not print an elided plan digest'
fi
PLAN_DIGEST="$(php -r '
$d = json_decode((string) file_get_contents($argv[1]), true);
$v = (string) ($d["plan_digest"] ?? "");
echo str_starts_with($v, "sha256:") ? substr($v, 7) : $v;
' "$TMP/release.json")"
if [ "${#PLAN_DIGEST}" = 64 ]; then
  pass 'the machine document carries the full 64-hex digest --plan=<digest> requires'
else
  fail "the machine document did not publish a 64-hex plan digest (got '${PLAN_DIGEST}')"
fi
if grep -qE '(^|[^0-9a-f])[0-9a-f]{64}([^0-9a-f]|$)' "$TMP/release.human"; then
  fail 'duo release printed a full 64-hex digest in its human view; §5.2 allows the digest, §4.6 bounds it'
else
  pass 'the full 64-hex digest stays in --format=json and in .duo/releases/<digest>.json'
fi

# The UUID class is allowlisted only because `duo explain` documents a
# selector that takes one. Prove the documented consumer exists rather than
# taking §5.2's word for it.
if grep -Fq 'duo explain <env> <bucket>:<entity-key>' "$ROOT/cli/duo"; then
  pass 'duo explain documents the <bucket>:<entity-key> selector that allowlists the UUID class'
else
  fail 'no documented command consumes a <bucket>:<uuid> selector, so the UUID allowlist has no basis'
fi

# ============================================================ part (b)
say '(b) every wp duo command is host-driven or a documented internal'
if php "$FIX/command-dispositions.php" "$ROOT"; then
  pass 'every public wp duo subcommand is driven by a host verb or named in docs/guides/internals.md'
else
  fail 'a public wp duo subcommand is neither host-driven nor documented (MUP §5.1)'
fi

# internals.md is one table, not several: §5.1 says "a single
# docs/guides/internals.md table", and two tables is how half of them stop
# being maintained.
TABLE_HEADERS="$(grep -c '^|---' "$GUIDES/internals.md")"
if [ "$TABLE_HEADERS" = 1 ]; then
  pass 'docs/guides/internals.md carries exactly one table'
else
  fail "docs/guides/internals.md carries $TABLE_HEADERS tables; §5.1 says one"
fi

# ============================================================ part (c)
say '(c) the raw-recovery recipe is gone from the guides'
if RECIPE_FINDINGS="$(scan_guides_recovery "$GUIDES" 2>&1)"; then
  pass 'no guide outside internals.md publishes an abort/begin/import/abort recipe'
else
  fail 'a guide still publishes the retired raw-recovery recipe (MUP §5.3)'
  printf '%s\n' "$RECIPE_FINDINGS" >&2
fi

if grep -Fq 'duo recover' "$GUIDES/recovery.md" 2>/dev/null; then
  pass 'docs/guides/recovery.md publishes duo recover as the replacement entry point'
else
  fail 'docs/guides/recovery.md does not publish duo recover as the recovery entry point'
fi

# ------------------------------------------------------------------- verdict
printf '\n'
if [ "$FAILURES" -eq 0 ]; then
  echo '✔ REGRESS_MUP_LEAK_AUDIT PASSED'
  exit 0
fi
echo "✘ REGRESS_MUP_LEAK_AUDIT FAILED ($FAILURES)" >&2
exit 1
