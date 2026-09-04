#!/usr/bin/env bash
# Regression — round-3 MUP §5.1, §5.2, §5.3 (the phase-A leak closures) as a
# gate, per §6.2's row: "no internal identifier in the human view of
# assess/release/verify/recover unless a documented command consumes it;
# every `wp wprism` command is either host-driven or named in
# docs/guides/internals.md".
#
# Five independent properties. Each one closes a leak the product spec names
# explicitly — "normal operation never depends on private identifiers,
# undocumented commands, or raw database surgery", and §776-777's fourth
# class, "unbounded-output leaks that prevent an agent from completing a first
# session cleanly" — and each one is checked against OUTPUT or SOURCE, never
# against a promise in a docblock.
#
#   (a) INTERNAL IDENTIFIERS (§5.2). The human view of `wprism assess`,
#       `wprism release --plan-only`, `wprism verify`, `wprism recover --list`,
#       `wprism recover --restore`, `wprism rehearse`, `wprism pending` and
#       `wprism contract show` may print an internal identifier only where a
#       documented command consumes it. The last two joined the set in
#       issue #3521: the rule was never about six particular verbs, it is about
#       every host-rendered view with an obtainable `--format=json` twin, and
#       leaving two of them out made the gate look narrower than the rule.
#
#       ONE host-rendered view has no twin and is named here rather than
#       silently skipped: the post-checkpoint promotion failure. It stops
#       before any machine document exists, so part (c) below scans it
#       directly for the two identifier classes it could plausibly carry (a
#       64-hex artifact hash and a bare lease owner) instead of diffing it
#       against a document that cannot be produced.
#
#       The allowlist
#       is closed and is exactly §5.2's: the `<bucket>:<uuid>` selector
#       `wprism explain` takes, the receipt ids `wprism recover --restore=<id>`
#       takes, and the `plan_digest` `wprism verify --plan=<digest>` takes.
#       Artifact hashes, lease owners, operation ids and session ids are
#       `--format=json` only.
#
#       The views are produced by running the REAL `php cli/wprism` verbs
#       against the fixture sites the T2/T3 suites already build — which is
#       the point of reusing them rather than writing a sixth: an audit that
#       rendered its own view would be auditing the audit. Each view is
#       compared against the SAME command's `--format=json` document, so the
#       machine document is the authority on what an identifier is and the
#       human view is the thing on trial.
#
#   (b) COMMAND SURFACE (§5.1). Every public `wp wprism` subcommand is either
#       driven by a host verb or named in `docs/guides/internals.md` with
#       §5.1's literal sentence. Neither is a value judgement about the
#       command; the point is that an operator can never find one that is
#       reachable, undocumented, and unexplained.
#
#   (c) RAW RECOVERY (§5.3), in the guides AND in the product. The
#       four-ordered-commands recipe — abort, re-begin, isolated import,
#       mandatory final abort — is gone from `docs/guides/**` except
#       `internals.md`, replaced by `wprism recover <env> --restore=<id>
#       --writers-excluded`. Naming the runtime in prose is still allowed and
#       is in fact required: §5.3 retires it as an ENTRY POINT, and saying so
#       is documentation. What is forbidden is a runnable recipe.
#
#       Until issue #3525 this part scanned prose only, which left the one
#       surface that mattered most unaudited: `cli/wprism`'s
#       `print_promotion_recovery()` printed the recipe itself, and it is the
#       source the pattern list below was written FROM. The second half now
#       renders a real post-checkpoint promotion failure with the real `php
#       cli/wprism` and scans its human view with the same patterns, then proves
#       the remedy it prints instead is a verb `wprism` actually publishes in its
#       own Usage block. A gate that forbids a recipe in the documentation
#       while the product emits it is a gate that measures the wrong file.
#
#   (d) UNBOUNDED OUTPUT (product spec:776-777). Every human view that
#       prints a list is bounded, the cut names the remedy in the output
#       itself — `N more (use --format=json)` — and the `--format=json` twin
#       stays COMPLETE, because a bound is only honest while the whole
#       document is one flag away. The bound is exercised with `--limit=1`
#       against the ordinary fixtures rather than with a fixture larger than
#       the 200-row ceiling: the property under test is "this view cuts and
#       says so", and `--limit=1` tests it on every view without asking five
#       fixture generators to grow a thousand rows each. The ceiling itself
#       is checked through the grammar: `--limit=0`, `--limit=201`,
#       `--limit=+5`, a bare `--limit` and a repeated one must each produce
#       that verb's OWN refusal bytes, which is also the proof that sharing
#       one parser (`HumanViewLimit`) moved no refusal envelope.
#
#       The PASSTHROUGH verbs — `capabilities`, `lint`, `plan`, `explain`,
#       `apply`, `coverage` — are deliberately out of scope for a HOST bound,
#       and this is the sentence that says so rather than leaving the surface
#       unexplained. `PassthroughCommand::run()` forwards the verb verbatim
#       and streams the target's own output (`streamWp(['wprism', $verb, …])`,
#       cli/src/Command/PassthroughCommand.php:34); bounding there would mean
#       the host parsing target stdout, which is the exact boundary that class
#       exists to hold. Their bound is the agent's: `coverage` and `scope` are
#       cut at `Coverage::LARGE_LISTING_THRESHOLD` through `Cli::scope_listing()`
#       (agent/src/Command/Cli.php:2570-2582), `plan` carries `PlanView`'s
#       `--limit`, and `wp wprism pending` joined the same helper in issue #3521.
#
#   (e) VERB SYMMETRY. Every verb `wprism`'s Usage block publishes is dispatched
#       by `main()`, and every verb `main()` dispatches is published. Part (b)
#       gates that disjunction for `wp wprism` commands; the host half had the
#       same failure mode and no gate. Measured when this landed, the two
#       sides already agree — so this LOCKS an invariant rather than fixing a
#       bug, and the self-test drives it in both directions so it stays one.
#
# `--self-test` proves all five gates actually fail on an injected
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
TMP="$(mktemp -d "${TMPDIR:-/tmp}/wprism-mup-leak-audit.XXXXXX")"
trap 'rm -rf "$TMP"' EXIT INT TERM

FAILURES=0
pass() { printf 'ok: %s\n' "$*"; }
fail() { printf 'FAIL: %s\n' "$*" >&2; FAILURES=$((FAILURES + 1)); }
say()  { printf '\n== %s ==\n' "$*"; }

# The four ordered raw-recovery commands, in the exact spellings cli/wprism's
# print_promotion_recovery() USED to emit them (built from
# CodeDeploy::abortArgs / beginArgs / recoveryDbImportArgs; the function is at
# cli/wprism:3317-3394 and since issue #3525 emits none of them — the second half of
# part (c) is what keeps that true). Steps 1 and 4 are
# the same command, so three patterns cover four steps; the fourth pattern is
# an INVOCATION of the runtime itself, which is what §5.3 retires. A bare
# mention of the path is deliberately not matched — `php ` must precede it —
# because "driving `recovery/rollback-control.php` by hand is no longer an
# operator path" is exactly the sentence §5.3 wants the guides to carry.
RECOVERY_RECIPE_PATTERNS=(
  'wp wprism promotion-abort'
  'wp wprism promotion-begin'
  'wp db import'
  'php [^[:space:]]*rollback-control\.php'
)

# The same four steps as they look when the PRODUCT renders them rather than
# when prose writes them. `EnvironmentDriver::wpInstruction()` emits one
# shell-quoted argv per line — `'wp' '--path=…' '--exec=…' '--skip-plugins'
# '--skip-themes' 'wprism' 'promotion-abort' '--promotion-owner=…'` — with the
# whole control-plane bootstrap sitting between `wp` and `wprism`. Measured
# against the pre-issue #3525 `cli/wprism`, whose output printed all four steps, the
# prose patterns above found ZERO hits. A rendered gate that inherited them
# would be a gate that cannot fail, which is the one thing this suite exists
# not to be. Both sets are applied to a rendered view.
RECOVERY_RENDERED_PATTERNS=(
  "wprism'?[[:space:]]+'?promotion-abort"
  "wprism'?[[:space:]]+'?promotion-begin"
  "'?db'?[[:space:]]+'?import'?[[:space:]/]"
  'rollback-control\.php'
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
        printf '        cites the retired raw-recovery step /%s/ — MUP §5.3 replaces it with `wprism recover <env> --restore=<id> --writers-excluded`\n' "$pattern"
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

# scan_rendered_recovery <label> <human-view-file> -> 0 clean, 1 any recipe.
# The same RECOVERY_RECIPE_PATTERNS the guide scan uses, applied to what the
# PRODUCT prints. There is no internals.md exemption here: a terminal is not a
# reference page, and an operator reading a failure has nowhere else to be.
scan_rendered_recovery() {
  local label="$1" view="$2" status=0 pattern hits
  [ -s "$view" ] || { printf 'RECIPE: %s rendered no output at all\n' "$label"; return 1; }
  for pattern in "${RECOVERY_RECIPE_PATTERNS[@]}" "${RECOVERY_RENDERED_PATTERNS[@]}"; do
    hits="$(grep -nE -- "$pattern" "$view" || true)"
    [ -n "$hits" ] || continue
    while IFS= read -r hit; do
      [ -n "$hit" ] || continue
      printf 'RECIPE: %s:%s\n' "$label" "${hit%%:*}"
      printf '        the product printed the retired raw-recovery step /%s/ — MUP §5.3 replaces it with `wprism recover <env> --restore=<id> --writers-excluded`\n' "$pattern"
    done <<< "$hits"
    status=1
  done
  return "$status"
}

# ---------------------------------------------------------------- part (d)
# assert_bounded <label> <human-view-file> <total-rows> <rows-shown>
# 0 when the view cut correctly, 1 otherwise. The expected remainder is
# computed from the TRUE total and the bound that was asked for, so a view
# that prints the right number of rows under a WRONG tail still fails — the
# tail is the operator's only evidence that anything was withheld.
assert_bounded() {
  local label="$1" view="$2" total="$3" shown="$4" expected
  [ -s "$view" ] || { printf 'UNBOUNDED: %s rendered nothing\n' "$label"; return 1; }
  expected=$((total - shown))
  if [ "$expected" -le 0 ]; then
    printf 'UNBOUNDED: %s was driven with a case that cuts nothing (total=%s, shown=%s); the check would be vacuous\n' \
      "$label" "$total" "$shown"
    return 1
  fi
  if ! grep -Fq -- "$expected more (use --format=json)" "$view"; then
    printf 'UNBOUNDED: %s printed no `%d more (use --format=json)` tail for a %d-row listing bounded at %d\n' \
      "$label" "$expected" "$total" "$shown"
    return 1
  fi
  return 0
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
{"format":"wprism-fake/v1",
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
  # being printed, not a leak (`wprism recover --list`'s retained checkpoint id
  # is `promote-<lease owner>` by construction); the same value printed on
  # its own anywhere else on the page still is one. Both halves are checked.
  mkdir -p "$scratch/a2"
  cat > "$scratch/a2/doc.json" <<'JSON'
{"format":"wprism-fake/v1","rows":[{"id":"promote-20260817-091402-0123456789abcdef0123456789abcdef","owner":"20260817-091402-0123456789abcdef0123456789abcdef"}]}
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

  # --harness-root must mask the scratch PREFIX and nothing more. Both halves
  # are checked from one view, because a mask that swallowed the whole token
  # would pass the first and silently lose the second -- and losing it is how
  # a real leak would hide behind a path.
  mkdir -p "$scratch/root-mask"
  printf '{"format":"wprism-fake/v1"}\n' > "$scratch/root-mask/doc.json"
  cat > "$scratch/root-mask/human.txt" <<TXT
wrote $scratch/root-mask/3f2504e0-4f89-41d3-9a0c-0305e82c3301.json
TXT
  out="$(php "$FIX/identifier-scan.php" 'fake view' "$scratch/root-mask/human.txt" \
        "$scratch/root-mask/doc.json" --harness-root="$scratch/root-mask" 2>&1)"
  if [ "$?" -eq 0 ]; then
    fail 'self-test A: --harness-root masked a UUID FILENAME under the root, not just the prefix'
    printf '%s\n' "$out" >&2
  else
    pass 'self-test A: a product-minted UUID under the masked root is still reported'
  fi
  # And the prefix itself, which is the environment, must not be reported.
  mkdir -p "$scratch/3f2504e0-4f89-41d3-9a0c-0305e82c3301"
  printf '{"format":"wprism-fake/v1"}\n' > "$scratch/3f2504e0-4f89-41d3-9a0c-0305e82c3301/doc.json"
  printf 'wrote %s/plan.json\n' "$scratch/3f2504e0-4f89-41d3-9a0c-0305e82c3301" \
    > "$scratch/3f2504e0-4f89-41d3-9a0c-0305e82c3301/human.txt"
  if php "$FIX/identifier-scan.php" 'fake view' \
      "$scratch/3f2504e0-4f89-41d3-9a0c-0305e82c3301/human.txt" \
      "$scratch/3f2504e0-4f89-41d3-9a0c-0305e82c3301/doc.json" \
      --harness-root="$scratch/3f2504e0-4f89-41d3-9a0c-0305e82c3301" >/dev/null 2>&1; then
    pass 'self-test A: a UUID that is only part of the harness root is not reported'
  else
    fail 'self-test A: the audit reported its own TMPDIR back as a product leak'
  fi

  # The root as the SHELL passes it and the root as the PRODUCT prints it are
  # different strings on stock macOS: TMPDIR carries a trailing slash, so
  # mktemp yields `//`, and the views print realpath() with the /var symlink
  # resolved. A byte-exact mask matches neither, which is how the first
  # version of this passed on a normalised TMPDIR and left the audit failing
  # on a default one.
  mkdir -p "$scratch/norm"
  printf '{"format":"wprism-fake/v1"}\n' > "$scratch/norm/doc.json"
  printf 'wrote %s/plan.json\n' "$(cd "$scratch/norm" && pwd -P)" > "$scratch/norm/human.txt"
  if php "$FIX/identifier-scan.php" 'fake view' "$scratch/norm/human.txt" \
      "$scratch/norm/doc.json" --harness-root="$scratch//norm/" >/dev/null 2>&1; then
    pass 'self-test A: --harness-root masks the realpath the views print, not just the string it was given'
  else
    fail 'self-test A: a denormalised --harness-root failed to mask the path the product printed'
  fi

  # mktemp fills XXXXXX from [A-Za-z0-9], so a root can END in hex. An
  # unanchored replace then eats the head of a longer token that merely starts
  # with the root, and the remainder matches no shape -- a leak deleted from
  # the findings rather than reported.
  mkdir -p "$scratch/anchor"
  printf '{"format":"wprism-fake/v1"}\n' > "$scratch/anchor/doc.json"
  printf 'wrote /var/folders/qj/T/audit.a1a1b280f1f2b7d1cc179dba8d36aa88463eda6ac11cb8e60a4583e1bd16aa3c\n' \
    > "$scratch/anchor/human.txt"
  if php "$FIX/identifier-scan.php" 'fake view' "$scratch/anchor/human.txt" \
      "$scratch/anchor/doc.json" --harness-root=/var/folders/qj/T/audit.a1a1b2 >/dev/null 2>&1; then
    fail 'self-test A: a root that PREFIXES a real 64-hex token swallowed it'
  else
    pass 'self-test A: masking is anchored to a path boundary, so a prefix root hides no identifier'
  fi

  # (b) removing a row from internals.md must fail the disposition gate, and
  #     a row naming a command that does not exist must fail it too.
  echo 'self-test B: an internals.md missing a row must fail the disposition gate'
  grep -v '`wp wprism orphans`' "$GUIDES/internals.md" > "$scratch/internals-short.md"
  out="$(php "$FIX/command-dispositions.php" "$ROOT" --internals="$scratch/internals-short.md" 2>&1)"
  rc=$?
  if [ "$rc" -ne 0 ] && printf '%s\n' "$out" | grep -Fq 'wp wprism orphans'; then
    pass 'self-test B: a command dropped from the table is reported by name'
  else
    fail 'self-test B: dropping a documented internal did not fail the gate'
    printf '%s\n' "$out" >&2
  fi

  sed 's/`wp wprism orphans`/`wp wprism not-a-real-command`/' "$GUIDES/internals.md" > "$scratch/internals-stale.md"
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
# Internal wp wprism commands

The raw recovery steps `wp wprism promotion-abort`, `wp wprism promotion-begin` and
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

Recover with `wprism recover production --restore=<receipt-id> --writers-excluded`;
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
    pass 'self-test C: a corpus that publishes only wprism recover passes'
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

  # (c, rendered) the same proof for the product half. A synthetic view stands
  # in for a real failure for the reason the guide corpus is synthetic: this
  # case is about the SCANNER, and seeding it from the tree under audit would
  # make it pass or fail for reasons that have nothing to do with the checker.
  echo 'self-test C: an injected raw-recovery step in a RENDERED view must fail the output scan'
  cat > "$scratch/rendered-bad.human" <<'TXT'
wprism: promote: apply failed (exit 1); later phases were not run
wprism: promote: promotion lease cleanup confirmed
database checkpoint: /srv/site/.wprism/checkpoints/promote-owner.sql
  3. wp db import /srv/site/.wprism/checkpoints/promote-owner.sql
TXT
  out="$(scan_rendered_recovery 'fake failure view' "$scratch/rendered-bad.human" 2>&1)"
  rc=$?
  if [ "$rc" -ne 0 ] && printf '%s\n' "$out" | grep -Fq 'fake failure view:4'; then
    pass 'self-test C: a rendered wp db import is reported with the line it appeared on'
  else
    fail 'self-test C: an injected raw database import in a rendered view was accepted'
    printf '%s\n' "$out" >&2
  fi

  # The form the product ACTUALLY used before issue #3525: one shell-quoted argv
  # per numbered step, with the control-plane bootstrap between `wp` and the
  # subcommand. The prose patterns match none of these lines, which is why
  # RECOVERY_RENDERED_PATTERNS exists; each of the three steps is injected
  # separately so a missing pattern is named rather than masked by its
  # neighbours.
  local rendered_step
  for rendered_step in \
    "  1. 'wp' '--path=/srv/site' '--exec=\$wprismWpRoot = …' '--skip-plugins' 'wprism' 'promotion-abort' '--promotion-owner=o'" \
    "  2. 'wp' '--path=/srv/site' '--exec=\$wprismWpRoot = …' '--skip-plugins' 'wprism' 'promotion-begin' '--promotion-owner=o'" \
    "  3. 'wp' '--path=/srv/site' '--exec=\$wprismWpRoot = …' '--skip-plugins' 'db' 'import' '/srv/site/.wprism/checkpoints/promote-o.sql'"
  do
    printf 'database checkpoint: /srv/site/.wprism/checkpoints/promote-o.sql\n%s\n' "$rendered_step" \
      > "$scratch/rendered-argv.human"
    if scan_rendered_recovery 'fake failure view' "$scratch/rendered-argv.human" >/dev/null 2>&1; then
      fail "self-test C: the argv-form step was accepted: $rendered_step"
    else
      pass "self-test C: the argv-form step is reported (${rendered_step:2:4}…)"
    fi
  done
  cat > "$scratch/rendered-good.human" <<'TXT'
wprism: promote: apply failed (exit 1); later phases were not run
database checkpoint: /srv/site/.wprism/checkpoints/promote-owner.sql
wprism: promote: once that exclusion is in place, recover with: wprism recover production --restore=promote-owner --writers-excluded --operator-directed
TXT
  if scan_rendered_recovery 'fake failure view' "$scratch/rendered-good.human" >/dev/null 2>&1; then
    pass 'self-test C: a rendered view that publishes only wprism recover passes'
  else
    fail 'self-test C: a clean rendered view was rejected'
  fi
  : > "$scratch/rendered-empty.human"
  if scan_rendered_recovery 'fake failure view' "$scratch/rendered-empty.human" >/dev/null 2>&1; then
    fail 'self-test C: an empty rendered view was reported as clean'
  else
    pass 'self-test C: an empty rendered view is a failure, not a pass'
  fi

  # (d) a view that prints every row must fail the bound check, and the same
  #     view cut correctly must pass it. Synthetic for the same reason (c)'s
  #     corpus is: this case is about the CHECKER.
  echo 'self-test D: a human view that prints its whole listing unbounded must fail'
  printf 'row a\nrow b\nrow c\nrow d\n3 item(s)\n' > "$scratch/unbounded.human"
  if assert_bounded 'fake listing' "$scratch/unbounded.human" 4 1 >/dev/null 2>&1; then
    fail 'self-test D: an unbounded human view was accepted as bounded'
  else
    pass 'self-test D: a view with no cut line is reported'
  fi
  printf 'row a\n  3 more (use --format=json)\n\n4 item(s)\n' > "$scratch/bounded.human"
  if assert_bounded 'fake listing' "$scratch/bounded.human" 4 1 >/dev/null 2>&1; then
    pass 'self-test D: a view whose tail names the true remainder passes'
  else
    fail 'self-test D: a correctly cut view was rejected'
  fi
  # A tail that under-reports what was withheld is the failure mode a
  # presence-only check would miss entirely.
  printf 'row a\n  1 more (use --format=json)\n' > "$scratch/wrong-tail.human"
  if assert_bounded 'fake listing' "$scratch/wrong-tail.human" 4 1 >/dev/null 2>&1; then
    fail 'self-test D: a tail naming the wrong remainder was accepted'
  else
    pass 'self-test D: a tail that under-reports the remainder is reported'
  fi
  # And a tail that names no remedy is not a cut line at all.
  printf 'row a\n  3 more\n' > "$scratch/no-remedy.human"
  if assert_bounded 'fake listing' "$scratch/no-remedy.human" 4 1 >/dev/null 2>&1; then
    fail 'self-test D: a tail that names no --format=json remedy was accepted'
  else
    pass 'self-test D: a cut that does not name --format=json is reported'
  fi

  # (e) the symmetry gate must fail in BOTH directions. It passes today, so
  #     without this it is a `true` with paperwork.
  echo 'self-test E: the host-verb symmetry gate must fail in both directions'
  php "$ROOT/cli/wprism" > "$scratch/usage.txt" 2>&1
  grep -v '^  wprism pending ' "$scratch/usage.txt" > "$scratch/usage-missing.txt"
  out="$(php "$FIX/host-verb-symmetry.php" "$ROOT" --usage="$scratch/usage-missing.txt" 2>&1)"
  rc=$?
  if [ "$rc" -ne 0 ] && printf '%s\n' "$out" | grep -Fq 'wprism pending'; then
    pass 'self-test E: a dispatched verb missing from the Usage block is reported by name'
  else
    fail 'self-test E: dropping a verb from the Usage block did not fail the symmetry gate'
    printf '%s\n' "$out" >&2
  fi

  { cat "$scratch/usage.txt"; printf '  wprism not-a-real-verb <env>\n'; } > "$scratch/usage-extra.txt"
  out="$(php "$FIX/host-verb-symmetry.php" "$ROOT" --usage="$scratch/usage-extra.txt" 2>&1)"
  rc=$?
  if [ "$rc" -ne 0 ] && printf '%s\n' "$out" | grep -Fq 'not-a-real-verb'; then
    pass 'self-test E: a published verb nothing dispatches is reported by name'
  else
    fail 'self-test E: an invented Usage line was accepted'
    printf '%s\n' "$out" >&2
  fi

  # The dispatch half is read from SOURCE, so mutate the source and prove
  # that half is really being read rather than inferred from the Usage block.
  sed "s/\$verb === 'envs'/\$verb === 'not-dispatched-any-more'/" "$ROOT/cli/wprism" > "$scratch/wprism-source"
  out="$(php "$FIX/host-verb-symmetry.php" "$ROOT" --usage="$scratch/usage.txt" --source="$scratch/wprism-source" 2>&1)"
  rc=$?
  if [ "$rc" -ne 0 ] && printf '%s\n' "$out" | grep -Fq 'wprism envs'; then
    pass 'self-test E: removing a dispatch arm reports the verb the Usage block still publishes'
  else
    fail 'self-test E: a Usage line whose dispatch arm was deleted was accepted'
    printf '%s\n' "$out" >&2
  fi
}

# ------------------------------------------------------------------ syntax
say 'syntax'
for file in "$FIX/identifier-scan.php" "$FIX/command-dispositions.php" "$FIX/rehearse-view.php" \
            "$FIX/host-verb-symmetry.php"; do
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

# rel <name> <args...> — one `php cli/wprism` run against the release fixture.
# stdout and stderr are BOTH the human view: an operator reads the terminal,
# not one stream of it, and a refusal is exactly where a renderer is most
# tempted to interpolate an operation id.
rel() {
  local name="$1"; shift
  ( cd "$RSITE" \
    && WPRISM_FIXTURES="$TMP/rel/fixtures" WPRISM_SITE_REPO="$RSITE" WPRISM_CALLS="$TMP/calls.txt" \
       PATH="$TMP/rel/bin:$PATH" \
       php "$ROOT/cli/wprism" --envs-file="$TMP/rel/envs.json" "$@" ) \
    > "$TMP/$name.out" 2> "$TMP/$name.err"
  local status=$?
  cat "$TMP/$name.out" "$TMP/$name.err" > "$TMP/$name.human"
  return $status
}

# rec <name> <status-fixture> <args...> — the same, against the recover site.
rec() {
  local name="$1" statusFixture="$2"; shift 2
  ( cd "$TMP/rec/site" \
    && WPRISM_RECOVERY_RUNTIME_SOURCE="$ROOT/recovery/rollback-control.php" \
       WPRISM_WP_CALLS="$TMP/wp-calls.txt" \
       WPRISM_RECOVER_STATUS="$TMP/rec/status/$statusFixture.json" \
       PATH="$TMP/rec/bin:$PATH" \
       php "$ROOT/cli/wprism" --envs-file="$TMP/rec/envs.json" "$@" ) \
    > "$TMP/$name.out" 2> "$TMP/$name.err"
  local status=$?
  cat "$TMP/$name.out" "$TMP/$name.err" > "$TMP/$name.human"
  return $status
}

# json_only <raw> <out> — `wprism release` prints the human authorization plan
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
' "$RSITE/.wprism/contract/fixture/proposed.json"
rel 'accept' contract fixture accept || { fail 'contract accept failed'; cat "$TMP/accept.err" >&2; }

# `wprism contract <env> show` reads the two committed review artifacts off disk
# and contacts nothing, so it renders only after accept has written them.
rel 'contract-show'      contract fixture show
rel 'contract-show-one'  contract fixture show --limit=1
rel 'contract-show-json' contract fixture show --format=json

rel 'assess'      assess fixture
rel 'assess-json' assess fixture --format=json

# The review queue, at a size this suite chooses. `wprism pending` renders
# host-side (PendingCommand -> Pending::render()), so its human view is on
# trial here exactly like the other seven; the fixture's fake wp answers
# `wprism pending` from $WPRISM_PENDING (make-release-site.php).
PENDING_ROWS=4
php -r '
$rows = [];
for ($i = 0; $i < (int) $argv[2]; $i++) {
    $rows[] = [
        "section" => "options",
        "key" => "fixture_pending_$i",
        "proposal" => "runtime",
        "evidence" => ["entities" => 3],
    ];
}
file_put_contents($argv[1], json_encode($rows, JSON_UNESCAPED_SLASHES));
' "$TMP/pending-queue.json" "$PENDING_ROWS"
WPRISM_PENDING="$TMP/pending-queue.json" rel 'pending'      pending fixture
WPRISM_PENDING="$TMP/pending-queue.json" rel 'pending-one'  pending fixture --limit=1
WPRISM_PENDING="$TMP/pending-queue.json" rel 'pending-json' pending fixture --format=json
rel 'assess-one' assess fixture --limit=1
rel 'release'      release fixture --plan-only
rel 'release-json' release fixture --plan-only --format=json
WPRISM_PLAN=plan-converged rel 'verify'      verify fixture
WPRISM_PLAN=plan-converged rel 'verify-json' verify fixture --format=json
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

for view in assess release verify recover-list recover-restore pending contract-show; do
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
  # --harness-root names OUR scratch prefix so the shape scan does not report
  # this audit's own TMPDIR back to us; see identifier-scan.php §2.
  if php "$FIX/identifier-scan.php" "$label" "$TMP/$view.human" "$TMP/$view.json" \
      --harness-root="$TMP" "$@"; then
    return 0
  fi
  fail "$label leaks an internal identifier into its human view (MUP §5.2)"
  return 1
}

# `probable_owner` is allowlisted for assess and only assess. It is not an
# internal identifier at all: it holds an ACTIVE PLUGIN SLUG, which the human
# view already prints as the `plugin:<slug>` surface id an operator types back
# into `wprism contract accept` — so the value the scanner sees "leaked" is that
# same documented id, arriving through a second field. Coverage's own
# attribution for an undeclared table is the other value it can hold, and that
# is a plugin slug too. Leaving it out would make T6 §3.6's row unshippable
# for a reason §5.2 does not actually state.
scan 'wprism assess <env>'                  assess --allow-key=probable_owner
scan 'wprism release <env> --plan-only'     release --allow-key=plan_digest
scan 'wprism verify <env>'                  verify
scan 'wprism recover <env> --list'          recover-list    --allow-key=id
scan 'wprism recover <env> --restore=<id>'  recover-restore --allow-key=id
scan 'wprism rehearse <env> (disclosure and provider refusal)' rehearse-verb
scan 'wprism rehearse <env> (preview)'      rehearse-preview
# issue #3521 widened the set past §5.2's original six. `pending`'s rows are
# `section:key` pairs an operator types straight back into `wprism classify`, and
# `contract show` prints surface ids `wprism contract accept` consumes; neither
# is an internal identifier, so neither needs an allowlist entry — which is
# the point of running them through the same scanner rather than assuming it.
scan 'wprism pending <env>'                 pending
# `probable_owner` for the same reason it is allowlisted for assess above and
# for no other view: it holds an ACTIVE PLUGIN SLUG, and `contract show`
# prints that slug as the `plugin:<slug>` surface id an operator types into
# `wprism contract accept`. The "leak" the scanner sees is that documented id
# arriving through a second field. This view carries the projection assess
# generated, so it inherits assess's exemption and nothing else.
scan 'wprism contract <env> show'           contract-show --allow-key=probable_owner

# ------------------------------------------- the allowlist is exercised, not
# assumed. An audit that passed because every view printed nothing would be
# indistinguishable from one that passed because the rule holds.
say '(a) the allowlisted identifiers are present and consumable'

RECEIPT="$(php -r '
$d = json_decode((string) file_get_contents($argv[1]), true);
echo (string) ($d["rows"][0]["id"] ?? "");
' "$TMP/recover-list.json")"
if [ -n "$RECEIPT" ] && grep -Fq -- "$RECEIPT" "$TMP/recover-list.human"; then
  pass "wprism recover --list prints the receipt id --restore=<id> consumes ($RECEIPT)"
else
  fail 'wprism recover --list did not print the receipt id its own --restore consumes'
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
  pass "wprism release prints the plan digest wprism verify --plan=<digest> consumes, elided to 12 hex ($PLAN_ELISION)"
else
  fail 'wprism release did not print an elided plan digest'
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
  fail 'wprism release printed a full 64-hex digest in its human view; §5.2 allows the digest, §4.6 bounds it'
else
  pass 'the full 64-hex digest stays in --format=json and in .wprism/releases/<digest>.json'
fi

# The UUID class is allowlisted only because `wprism explain` documents a
# selector that takes one. Prove the documented consumer exists rather than
# taking §5.2's word for it.
if grep -Fq 'wprism explain <env> <bucket>:<entity-key>' "$ROOT/cli/wprism"; then
  pass 'wprism explain documents the <bucket>:<entity-key> selector that allowlists the UUID class'
else
  fail 'no documented command consumes a <bucket>:<uuid> selector, so the UUID allowlist has no basis'
fi

# ============================================================ part (b)
say '(b) every wp wprism command is host-driven or a documented internal'
if php "$FIX/command-dispositions.php" "$ROOT"; then
  pass 'every public wp wprism subcommand is driven by a host verb or named in docs/guides/internals.md'
else
  fail 'a public wp wprism subcommand is neither host-driven nor documented (MUP §5.1)'
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

if grep -Fq 'wprism recover' "$GUIDES/recovery.md" 2>/dev/null; then
  pass 'docs/guides/recovery.md publishes wprism recover as the replacement entry point'
else
  fail 'docs/guides/recovery.md does not publish wprism recover as the recovery entry point'
fi

# ---------------------------------------- part (c), the product's own output
say '(c) the product itself never prints the raw-recovery recipe'

# A REAL post-checkpoint promotion failure, rendered by the real `php cli/wprism`
# through the same rel() helper part (a) uses. `WPRISM_APPLY_EXIT=1` is enough:
# the release fixture's fake wp already answers `wprism apply`
# (make-release-site.php:299), and an apply failure is the one that happens
# AFTER the checkpoint, which is exactly when print_promotion_recovery() runs
# (cli/wprism:2502 -> promote_failed() -> the one chokepoint).
if WPRISM_APPLY_EXIT=1 rel 'promote-failed' promote fixture; then
  fail 'the seeded post-checkpoint promote failure exited 0; no recovery guidance was rendered'
fi
if grep -Fq 'database checkpoint: ' "$TMP/promote-failed.human"; then
  pass 'a post-checkpoint promote failure renders its recovery guidance'
else
  fail 'the seeded promote failure never reached the checkpoint; the fixture no longer drives this path'
  cat "$TMP/promote-failed.human" >&2
fi

if RENDERED_FINDINGS="$(scan_rendered_recovery 'wprism promote <env> (post-checkpoint failure)' \
      "$TMP/promote-failed.human" 2>&1)"; then
  pass 'the promote failure view publishes no abort/begin/import/abort recipe and no runtime invocation'
else
  fail 'the product still prints the retired raw-recovery recipe (MUP §5.3)'
  printf '%s\n' "$RENDERED_FINDINGS" >&2
fi

# What it prints INSTEAD has to be real. Take the remedy out of the rendered
# view, take its verb, and require that verb of `wprism`'s own Usage block —
# rendered by running `php cli/wprism` with no arguments, not by reading the
# heredoc, so a verb that is documented but unreachable cannot satisfy this.
REMEDY="$(sed -n 's/^wprism: promote: .*recover with: //p' "$TMP/promote-failed.human" | head -1)"
if [ -n "$REMEDY" ]; then
  pass "the promote failure names a remedy instead of a recipe ($REMEDY)"
else
  fail 'the promote failure view named no remedy at all'
fi
case "$REMEDY" in
  'wprism recover '*' --restore='*' --writers-excluded --operator-directed')
    pass 'the remedy is the documented wprism recover --restore/--writers-excluded/--operator-directed form' ;;
  *) fail "the remedy is not the documented wprism recover form: $REMEDY" ;;
esac
REMEDY_VERB="$(printf '%s\n' "$REMEDY" | awk '{print $2}')"
php "$ROOT/cli/wprism" > "$TMP/usage.txt" 2>&1
if [ -n "$REMEDY_VERB" ] && grep -qE "^  wprism $REMEDY_VERB( |\$)" "$TMP/usage.txt"; then
  pass "the remedy's verb is published in wprism's own Usage block (wprism $REMEDY_VERB)"
else
  fail "the remedy names 'wprism $REMEDY_VERB', which wprism's Usage block does not publish"
fi

# The `<id>` it hands the operator has to be the id `wprism recover --list`
# publishes, not a path or an invented token: RetainedCheckpoints builds both
# from one `<prefix><owner>` stem (RetainedCheckpoints.php:287, :342).
REMEDY_ID="$(printf '%s\n' "$REMEDY" | tr ' ' '\n' | sed -n 's/^--restore=//p')"
CHECKPOINT_PATH="$(sed -n 's/^database checkpoint: //p' "$TMP/promote-failed.human" | head -1)"
if [ -n "$REMEDY_ID" ] && [ "$CHECKPOINT_PATH" = "${CHECKPOINT_PATH%/*}/$REMEDY_ID.sql.enc" ]; then
  pass "the --restore=<id> the failure prints is the retained checkpoint's own stem ($REMEDY_ID)"
else
  fail "the printed --restore=<id> ('$REMEDY_ID') is not the stem of '$CHECKPOINT_PATH'"
fi

# The one host-rendered view with no `--format=json` twin (named in the header
# above): it stops before any machine document exists, so part (a)'s
# document-diffing scanner has nothing to diff against. The two identifier
# classes it could plausibly carry are checked directly instead of the view
# being skipped. The lease owner is allowed ONLY as part of the checkpoint's
# `promote-<owner>` stem — that stem is the `<id>` `wprism recover --restore=<id>`
# consumes, which is exactly §5.2's second allowlist entry, and part (a)'s own
# self-test pins the same distinction for `wprism recover --list`.
if grep -qE '(^|[^0-9a-f])[0-9a-f]{64}([^0-9a-f]|$)' "$TMP/promote-failed.human"; then
  fail 'the promote failure view printed a 64-hex artifact hash; §5.2 keeps it to --format=json'
else
  pass 'the promote failure view prints no artifact hash'
fi
if grep -oE '[0-9]{8}-[0-9]{6}-[0-9a-f]{32}' "$TMP/promote-failed.human" | grep -q .; then
  if grep -oE '.{8}[0-9]{8}-[0-9]{6}-[0-9a-f]{32}' "$TMP/promote-failed.human" \
     | grep -vq 'promote-'; then
    fail 'the promote failure view printed a bare lease owner outside the allowlisted checkpoint id'
  else
    pass 'every lease owner in the promote failure view is part of the --restore=<id> that consumes it'
  fi
else
  fail 'the promote failure view printed no checkpoint id at all; the fixture no longer exercises this path'
fi

# ============================================================ part (d)
say '(d) every human view is bounded, names the remedy, and keeps its JSON twin whole'

# json_rows <json-file> <expression> — the TRUE row count, read out of the
# machine document. The bound is asserted against this rather than against a
# number written here, so a fixture that grows or shrinks cannot make the
# check vacuous without also making it fail.
json_rows() {
  php -r '
$d = json_decode((string) file_get_contents($argv[1]), true);
$path = $argv[2] === "" ? [] : explode(".", $argv[2]);
foreach ($path as $key) {
    $d = is_array($d) ? ($d[$key] ?? null) : null;
}
echo is_array($d) ? count($d) : 0;
' "$1" "$2"
}

# wprism pending — the view that had NO ceiling at all before issue #3521.
PENDING_TOTAL="$(json_rows "$TMP/pending.json" '')"
if [ "$PENDING_TOTAL" = "$PENDING_ROWS" ]; then
  pass "wprism pending --format=json publishes the complete queue ($PENDING_TOTAL row(s), unbounded)"
else
  fail "wprism pending --format=json published $PENDING_TOTAL row(s) for a queue of $PENDING_ROWS"
fi
if BOUND_FINDINGS="$(assert_bounded 'wprism pending <env> --limit=1' "$TMP/pending-one.human" "$PENDING_TOTAL" 1 2>&1)"; then
  pass 'wprism pending --limit=1 prints one row and a tail naming the true remainder'
else
  fail 'wprism pending --limit=1 did not cut, or its tail does not name what was withheld'
  printf '%s\n' "$BOUND_FINDINGS" >&2
fi
# The count line beside a cut table is the TRUE total. A truncated sample is
# honest; a truncated count is a lie about the site.
if grep -Fq -- "$PENDING_TOTAL item(s) in the review queue" "$TMP/pending-one.human"; then
  pass 'wprism pending keeps the true queue size in its count line under --limit=1'
else
  fail 'wprism pending reported a truncated count instead of the true queue size'
fi

# wprism assess — bounded before this change; the check is that delegating the
# grammar to HumanViewLimit did not move the rendering.
ASSESS_SURFACES="$(json_rows "$TMP/assess.json" 'surfaces')"
if BOUND_FINDINGS="$(assert_bounded 'wprism assess <env> --limit=1' "$TMP/assess-one.human" "$ASSESS_SURFACES" 1 2>&1)"; then
  pass "wprism assess --limit=1 cuts its $ASSESS_SURFACES-surface table and names the remainder"
else
  fail 'wprism assess --limit=1 did not cut its surface table'
  printf '%s\n' "$BOUND_FINDINGS" >&2
fi

# wprism contract show — bounded at a hardcoded 50 before this change; what it
# gained is the flag every other bounded view already published.
CONTRACT_SURFACES="$(json_rows "$TMP/contract-show.json" 'projection.surfaces')"
if BOUND_FINDINGS="$(assert_bounded 'wprism contract <env> show --limit=1' "$TMP/contract-show-one.human" "$CONTRACT_SURFACES" 1 2>&1)"; then
  pass "wprism contract show --limit=1 cuts its $CONTRACT_SURFACES-surface projection and names the remainder"
else
  fail 'wprism contract show --limit=1 did not cut its projection listing'
  printf '%s\n' "$BOUND_FINDINGS" >&2
fi
if [ "$CONTRACT_SURFACES" -gt 1 ]; then
  pass "wprism contract show --format=json publishes the complete projection ($CONTRACT_SURFACES surface(s))"
else
  fail 'wprism contract show --format=json published no complete projection to compare against'
fi

# ------------------------------------------- the shared grammar, per verb.
# Every out-of-range spelling must produce THAT VERB'S OWN refusal bytes.
# `HumanViewLimit::parse()` takes a caller-supplied refusal factory precisely
# so this stays true, and this is where "no envelope moved" is checked rather
# than asserted in a docblock.
say '(d) the --limit grammar is closed, and each verb keeps its own refusal'

# check_limit_refusal <label> <expected-substring> <verb-args...>
check_limit_refusal() {
  local label="$1" expected="$2"; shift 2
  local bad
  for bad in '--limit=0' '--limit=201' '--limit=+5' '--limit=1e2' '--limit=050' '--limit'; do
    if rel "limit-$$" "$@" "$bad"; then
      fail "$label accepted an out-of-range bound ($bad)"
      continue
    fi
    grep -Fq -- "$expected" "$TMP/limit-$$.human" \
      || fail "$label refused $bad in words that are not its own: expected /$expected/"
  done
  # A repeated flag is refused too: two bounds is an ambiguity, and resolving
  # it silently would make one of them invisible.
  if rel "limit-$$" "$@" '--limit=1' '--limit=2'; then
    fail "$label accepted a repeated --limit"
  else
    grep -Fq -- "$expected" "$TMP/limit-$$.human" \
      || fail "$label refused a repeated --limit in words that are not its own"
  fi
  pass "$label refuses 0, 201, +5, 1e2, 050, a bare --limit and a repeated one, in its own words"
}

check_limit_refusal 'wprism assess <env>' \
  'assess accepts at most one canonical --limit=<1..200>' assess fixture
check_limit_refusal 'wprism release <env> --plan-only' \
  '--limit must be given once as --limit=N with N between 1 and 200' release fixture --plan-only
check_limit_refusal 'wprism contract <env> show' \
  '--limit must be given once as --limit=N with N between 1 and 200' contract fixture show
check_limit_refusal 'wprism pending <env>' \
  'wprism: pending: --limit must be given once as --limit=N with N between 1 and 200' pending fixture

# ============================================================ part (e)
say '(e) every published wprism verb is dispatched, and every dispatched verb is published'
if SYMMETRY_FINDINGS="$(php "$FIX/host-verb-symmetry.php" "$ROOT" 2>&1 >/dev/null)"; then
  pass "wprism's Usage block and main()'s three dispatch sources name exactly the same verbs"
else
  fail "wprism publishes a verb it does not dispatch, or dispatches one it does not publish (MUP §5.1)"
  printf '%s\n' "$SYMMETRY_FINDINGS" >&2
fi

# ------------------------------------------------------------------- verdict
printf '\n'
if [ "$FAILURES" -eq 0 ]; then
  echo '✔ REGRESS_MUP_LEAK_AUDIT PASSED'
  exit 0
fi
echo "✘ REGRESS_MUP_LEAK_AUDIT FAILED ($FAILURES)" >&2
exit 1
