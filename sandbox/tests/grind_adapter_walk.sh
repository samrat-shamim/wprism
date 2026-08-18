#!/usr/bin/env bash
# Operator-authored adapters — the four-scenario walk (round-3 T6 §4).
#
# Contract: docs/proposals/round-3-adapter-walk.md. That document is binding on
# both sides: the walk asserts the words, the product emits them. Where the
# contract left a spelling open this script chose one and recorded it in "words
# this walk asserts" below, so the product builders can match a table rather
# than read a script.
#
# Four scenarios, each on a FRESH `pair.sh reset` of the same dedicated pair,
# each driving the whole customer loop the round ships:
#
#   S1  a published plugin with no adapter, kept deliberately unmanaged
#   S2  the same plugin, with an adapter the OPERATOR authored and certified
#   S3  an in-house plugin that bundles its own adapter, promoted and certified
#   S4  a SHIPPED adapter overridden by a site copy the operator certified
#
# The walk is the exercise and its stops are the work list: every step names
# the public command it is about, asserts by reason code or by exact string,
# writes its evidence, and FAILS naming the reason code when the product
# refuses somewhere the walk did not expect one.
#
# ## Shape
#
# Deliberately `sandbox/tests/grind_mup.sh`'s shape, not a new one: the same
# say/pass/fail helpers, the same `run`/`run_in` wrappers that print instead of
# executing under `--dry-run`, the same `--self-check` discipline (every pure
# helper against a recorded PASS document AND a hand-mutated FAIL document),
# the same one-dedicated-pair model with the pair NAME and both ports
# parameterized, the same Git-enabled cli image built from
# `sandbox/init-cli.Dockerfile` and handed to pair.sh through DUO_CLI_IMAGE,
# the same machine-local registry whose SOURCE and `preview` entries both carry
# the reference provider block, the same `DUO_EXPECTED_SOURCE_SHA` candidate
# gate, and the same exit trap that destroys exactly this pair and verifies the
# destruction.
#
#   WALK_PAIR       (default awalk)   pair.sh pair name; grammar [a-z][a-z0-9]*
#   WALK_PORT1      (default 9500)    published host port for side 1
#   WALK_PORT2      (default 9501)    published host port for side 2
#   WALK_SCENARIOS  (default S1,S2,S3,S4)  comma list, run in the order given
#   WALK_KEEP=1                       leave the pair and the site repos in place
#   WALK_THEME_SLUG/_VERSION (twentytwentyone / 2.8)  the pinned theme
#   WALK_WOO_VERSION      (default 11.0.0)   the pinned WooCommerce artifact
#   WALK_WPFORMS_VERSION  (default 2.0.0.4)  the pinned subject plugin
#   DUO_EXPECTED_SOURCE_SHA           bind this run to an exact source commit
#   DUO_WORDPRESS_ORG_OFFLINE         0|1, forwarded to pair.sh/fetch-artifact
#
# WooCommerce is installed in every scenario, not only S4: the site under test
# should look like a real shop that also runs the subject plugin, one
# `install_side` path serves all four, and S1's "the rest of the site is still
# normally managed while this plugin is not" assertion needs a normally-managed
# adapter present to be worth making.
#
# ## Offline modes
#
#   bash sandbox/tests/grind_adapter_walk.sh --self-check
#       Every pure bash/jq helper against sandbox/tests/fixtures/adapter-walk/.
#       No docker, no pair, no network. A helper that cannot fail proves
#       nothing about the run that trusts it, so every helper has both.
#
#   bash sandbox/tests/grind_adapter_walk.sh --dry-run
#       --self-check, then walk every selected scenario printing the exact
#       argv of every external command with its arguments already resolved,
#       executing none of them and creating nothing.
#
# ## Words this walk asserts
#
# Every literal below is asserted somewhere in this file. The `source` column
# is the contract section that fixes it; `WALK` means the contract named the
# thing but not the spelling, and this walk picked one.
#
#   literal                                             where                       source
#   --allow-unmanaged-plugins                           duo init flag               §3.4
#   UNMANAGED PLUGIN <slug>/<file>.php [<code>]         init human, advisories      §3.4
#   active_plugin_without_adapter                       init refusal reason code    §1
#   adapter_source_uncertified                          init refusal reason code    §1 §3.4
#   certify it with duo adapter certify                 that blocker's remediation  §3.4
#   incomplete_policy_scope                             capture refusal code        §1
#   scope:post_type:wpforms=runtime                     classify decision           §4
#   plugin:wpforms-lite                                 assess surface id           §3.6
#   plugin                                              that surface's `kind`       WALK
#   install adapter                                     gap action, plugin + tables §3.6
#   certify adapter                                     gap action, uncertified (S4) §3.6
#   option-prefix:wpforms                               assess unknown sample       §4 (product's family token, no trailing _)
#   table:<logical_name>                                assess surface id           §3.7
#   logical_name                                        coverage undeclared row key §3.7
#   N undeclared table(s)                               assess human unknown block  §3.6
#   nothing — supported                                 never on an unclassified row §4
#   runtime / preserve local                            contract decision, plugin   §3.6
#   Unsupported                                         that decision's readiness   §3.6
#   site_signed                                         catalog row certification   §3.2
#   trust_root                                          catalog row + projection key §3.2
#   principal                                           catalog row + projection key §3.2
#   site                                                that trust_root's value     WALK
#   Site-certified                                      assess certification word   §2 §3.6
#   certified by <principal> (site trust root); contract attestation unsigned
#                                                       assess human, once          §3.6
#   shadowed_by_site                                    not_installed[].reason_code WALK (§3.3 names the word)
#   duo adapter keygen --out=<file> [--key-id=<id>]     host verb                   §3.5
#   key-id: <id>                                        keygen stdout, first field  WALK
#   secret_key_inside_repository                        keygen refusal reason code  WALK (§3.1 names the refusal)
#   draft_output_exists                                 adapter-draft --out refusal WALK (§3.5 names the refusal)
#   duo adapter certify <repo> --name= --secret-key-file= --reason= --pin
#                                                       host verb                   §3.5
#   duo adapter pin <repo> --name= --source=site        host verb                   §3.5
#   duo adapter-draft <repo> --name= --seed= --out=     host verb                   §3.5
#   adapters/authorities.json                           site trust root path        §3.1
#   adapters/certifications/<name>.json                 certificate path            §3.1
#   duo-adapter-authorities/v1                          authorities file format     §3.1
#   {"name","source":"site","digest"}                   the pin object              §3.1 §3.3
#
# Two spellings this walk deliberately does NOT assert, because the contract
# names the effect rather than the string: the heading the init advisories
# print under (§3.4 says "an `advisories` heading"; the walk asserts only the
# `UNMANAGED PLUGIN` row itself), and the exact prose of `duo adapter certify`'s
# printed pin object (the walk reads the pin out of `site.duo.json`, which §3.1
# does fix).
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
FIXTURES="$SANDBOX/tests/fixtures/adapter-walk"

MODE=run
for arg in "$@"; do
  case "$arg" in
    --self-check) MODE=self-check ;;
    --dry-run) MODE=dry-run ;;
    *) printf 'usage: grind_adapter_walk.sh [--self-check|--dry-run]\n' >&2; exit 2 ;;
  esac
done
DRY_RUN=0
[ "$MODE" = dry-run ] && DRY_RUN=1

PAIR="${WALK_PAIR:-awalk}"
PORT1="${WALK_PORT1:-9500}"
PORT2="${WALK_PORT2:-9501}"
SCENARIOS="${WALK_SCENARIOS:-S1,S2,S3,S4}"
WOO_VERSION="${WALK_WOO_VERSION:-11.0.0}"
WPFORMS_VERSION="${WALK_WPFORMS_VERSION:-2.0.0.4}"
# grind_mup's documented fallback theme is this walk's DEFAULT: Storefront is
# not pinned in this tree and `sandbox/bin/fetch-artifact.sh` refuses an
# unpinned artifact rather than installing from the catalog, so defaulting to
# something the lock does not carry would make every first run fail at
# preflight for a reason that has nothing to do with adapters.
THEME_SLUG="${WALK_THEME_SLUG:-twentytwentyone}"
THEME_VERSION="${WALK_THEME_VERSION:-2.8}"
WORDPRESS_OFFLINE="${DUO_WORDPRESS_ORG_OFFLINE:-0}"

# The subject plugin's identity, in the three spellings the walk needs. All
# three are facts about WPForms Lite 2.0.0.4, pinned as an `exercise-fixture`
# in sandbox/conformance/artifacts.lock.json.
WPFORMS_SLUG=wpforms-lite
WPFORMS_BASENAME='wpforms-lite/wpforms.php'
WPFORMS_CPT=wpforms
WPFORMS_OPTION_PREFIX=wpforms_
# The `option-prefix:` token assess prints is Coverage::guess_prefix()'s
# grouping token — the first one or two `_`-joined words WITHOUT the trailing
# underscore (`wpforms`, `wpforms_transient`, `wpforms_version`), so the
# operator reads a family name, not a glob. Run 7 asserted `wpforms_` and the
# product printed `wpforms`; the product's spelling is the operator's.
WPFORMS_OPTION_FAMILY=wpforms
# One WPForms table the walk writes to itself after the checkpoint. Chosen
# because it is the least load-bearing of the plugin's own tables (a task
# scheduler's meta), so writing a row into it cannot change what any other
# assertion observes.
WPFORMS_TABLE=wpforms_tasks_meta

ACME_SLUG=acme-catalog
ACME_BASENAME='acme-catalog/acme-catalog.php'
ACME_CPT=acme_item
ACME_TAXONOMY=acme_kind

# The organization key id the walk certifies under. It is an operator-chosen
# label, not a secret, and it is the value the projection's `principal` must
# read back (§3.2).
WALK_KEY_ID="${WALK_KEY_ID:-acme-ops-2026}"

FAILURES=0
PAIR_UP=0
SCRATCH=""
SCENARIOS_RUN=()

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

# walk_json_tail <file> — the trailing canonical JSON document.
#
# `duo release --plan-only --format=json` prints the rendered authorization
# page and THEN the document (ReleaseCommand::run()), so a bare `jq .` over
# the whole stream fails. `\Duo\Canon::encode()` is pretty-printed, which puts
# the document's opening brace alone on a line at column 0 and puts every
# nested object's brace after a `"key": `, so the LAST bare `{` line is the
# start of the last document and nothing else can be.
walk_json_tail() {
  local file="$1" start
  start="$(grep -n '^{$' -- "$file" | tail -1 | cut -d: -f1 || true)"
  [ -n "$start" ] || { printf 'no canonical JSON document found in %s\n' "$file" >&2; return 1; }
  tail -n "+$start" -- "$file"
}

# walk_agent_json <file> — the LAST single-line JSON object in a stream.
#
# The agent's own `--format=json` commands print one compact object per line
# (`WP_CLI::line(json_encode(...))`), and the docker transport can prepend
# container noise, so the compact readers need the opposite rule from
# walk_json_tail's: the last line that starts with `{` and parses.
walk_agent_json() {
  local file="$1" line
  # Fed on stdin, not as an argv path: awk has no `--` and would read a
  # leading-dash filename as an option.
  line="$(awk '/^\{/ { last = $0 } END { if (last != "") print last }' < "$file")"
  [ -n "$line" ] || { printf 'no compact JSON object found in %s\n' "$file" >&2; return 1; }
  printf '%s\n' "$line" | jq -e . >/dev/null 2>&1 \
    || { printf 'the trailing line of %s is not valid JSON\n' "$file" >&2; return 1; }
  printf '%s\n' "$line"
}

# walk_assert_no_internal_ids <file> <label> — MUP §5.2 as a mechanical gate,
# unchanged from grind_mup because the rule is unchanged.
#
# A human view may print an internal identifier only when a documented command
# consumes it. Nothing consumes an operation id, a session id, a lease owner
# or an artifact hash from `duo assess`, so none of the three shapes those
# take may appear: a 36-character UUID, a bare 64-hex digest, or a bare 32-hex
# digest. `sha256:`-prefixed digests are matched by the 64-hex rule too, which
# is deliberate — assess names `--format=json` on every line that hides one.
walk_assert_no_internal_ids() {
  local file="$1" label="$2" hit
  hit="$(grep -nEo '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}' -- "$file" | head -3 || true)"
  [ -z "$hit" ] || { printf '%s leaks a UUID into the human view: %s\n' "$label" "$hit" >&2; return 1; }
  hit="$(grep -nEo '(^|[^0-9a-fA-F])[0-9a-fA-F]{32,}([^0-9a-fA-F]|$)' -- "$file" | head -3 || true)"
  [ -z "$hit" ] || { printf '%s leaks a hex identifier into the human view: %s\n' "$label" "$hit" >&2; return 1; }
  return 0
}

# walk_assess_projection <json-file> <surface-id> <operation> — the six §1
# dimensions of one row, tab-separated, in the projection's column order.
walk_assess_projection() {
  local file="$1" id="$2" operation="$3"
  jq -er --arg id "$id" --arg op "$operation" '
    (.surfaces[] | select(.id == $id)) as $row
    | ($row.operations[$op] // error("surface \($id) has no \($op) projection")) as $p
    | [$row.state_class, $row.handling, $p.readiness,
       $p.certification_provenance, $p.effect_containment, $p.effect_recovery_semantics]
    | @tsv
  ' -- "$file"
}

# walk_assess_next_action <json-file> <surface-id> — one row's gap action.
walk_assess_next_action() {
  local file="$1" id="$2"
  jq -er --arg id "$id" '
    (.surfaces[] | select(.id == $id) | .next_action)
    // error("no surface \($id) in this report")
  ' -- "$file"
}

# walk_assess_certification <json-file> <surface-id> <operation> — the
# certification triple §3.2 requires a certified claim to expose:
# `<provenance>\t<trust_root>\t<principal>`. Read as three fields rather than
# one word because `Site-certified` with no principal names no authority, and
# a projection that says who signed it is the whole content of §2's ruling.
walk_assess_certification() {
  local file="$1" id="$2" operation="$3"
  jq -er --arg id "$id" --arg op "$operation" '
    (.surfaces[] | select(.id == $id)) as $row
    | ($row.operations[$op] // error("surface \($id) has no \($op) projection")) as $p
    | [$p.certification_provenance, ($p.trust_root // "null"), ($p.principal // "null")]
    | @tsv
  ' -- "$file"
}

# walk_assert_assess_document <json-file> — the facts about the DOCUMENT
# rather than about one row: it is the right format, and every unclassified
# surface carries a real gap action. `nothing — supported` is a member of the
# closed set and is exactly the answer an unclassified row may never give.
walk_assert_assess_document() {
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

# walk_assert_unknown_names <json-file> <name>... — every named entry is in
# the unknown block's sample.
#
# §4's S1 row requires the plugin's option family by name (`option-prefix:wpforms`). The sample is bounded
# (ContractProposal::MAX_NAMES_SAMPLE) while the counts beside it are exact,
# so this asks about names the walk knows are few, never about a total.
walk_assert_unknown_names() {
  local file="$1" name missing=""
  shift
  for name in "$@"; do
    jq -e --arg n "$name" '[.unknown.names_sample[]] | index($n) != null' -- "$file" >/dev/null \
      || missing+="${missing:+, }$name"
  done
  [ -z "$missing" ] \
    || { printf 'the assess unknown block never names: %s\n' "$missing" >&2; return 1; }
  return 0
}

# walk_assert_unknown_table_line <human-file> — §3.6: the `unknown:` block
# prints `N undeclared table(s)`.
#
# Bug 2 of §3.7 is that it does not, which is why this reads the human view
# rather than the document: the count exists in coverage either way, and the
# finding is that an operator reading the page never sees it.
walk_assert_unknown_table_line() {
  local file="$1"
  # The product's line carries a parenthetical the operator needs ("(no
  # installed adapter declares them)"); the count and the word are what this
  # asserts, so a trailing parenthetical is admitted, prose before it is not.
  grep -Eq '^ *[0-9]+ undeclared table\(s\)( \(.*\))?$' -- "$file" \
    || { printf 'the assess unknown block prints no "N undeclared table(s)" line: %s\n' "$file" >&2; return 1; }
  return 0
}

# walk_gap_count <human-file> <action> — the next-actions roll-up count for
# one closed-set action. Every action is printed with its count including the
# zeroes (GapActions::summarise()), so a missing line is a defect rather than
# a zero and this refuses instead of returning one.
walk_gap_count() {
  local file="$1" action="$2" line
  line="$(grep -E "^ +[0-9]+  $(printf '%s' "$action" | sed 's/[][\.*^$/]/\\&/g')\$" -- "$file" | head -1 || true)"
  [ -n "$line" ] \
    || { printf 'the next-actions roll-up has no line for "%s": %s\n' "$action" "$file" >&2; return 1; }
  printf '%s\n' "$line" | awk '{print $1}'
}

# walk_coverage_undeclared <coverage-json> — the logical names of every
# undeclared table, one per line.
#
# §3.7 bug 1: `Coverage::tables_report()` drops `logical_name` from the
# published rows, and `logical_name` is what assess builds its `table:<name>`
# identity from (cli/src/Assess/SurfaceCatalog.php:326), so without it the
# `table:` rows can never appear on a live site. Reading the key by name is
# what makes that a caught bug rather than an empty list.
walk_coverage_undeclared() {
  local file="$1"
  jq -er '
    if (.format // "") != "duo-coverage-report/v1"
    then error("not a duo-coverage-report/v1 document")
    else (.tables.undeclared // [])
      | map(.logical_name // error("an undeclared table row publishes no logical_name: " + (.table // "?")))
      | .[]
    end
  ' -- "$file"
}

# walk_catalog_row <catalog-json> <name> — one adapter's provenance and
# certification, tab-separated: `<source>\t<certification>\t<trust_root>\t<principal>`.
# §3.2 puts `trust_root` and `principal` on EVERY row, so a row that omits
# them reads `null` here rather than failing — the caller compares the whole
# tuple and says which field disagreed.
walk_catalog_row() {
  local file="$1" name="$2"
  jq -er --arg n "$name" '
    (.adapters[] | select(.name == $n))
    // error("no adapter \($n) in this catalog")
    | [.source, .certification, (.trust_root // "null"), (.principal // "null")] | @tsv
  ' -- "$file"
}

# walk_assert_shadowed_by_site <catalog-json> <name> — §3.3's override, read
# from the catalog.
#
# The shipped copy becomes an installed-but-not-loaded row whose winner is the
# site source. All three facts are asserted together because any two of them
# without the third describe a different situation: `shadowed_by_site` with a
# shipped winner is precedence running the ORDINARY way, and a site winner
# under today's `shadowed` code is the row a name-only pin already produces.
walk_assert_shadowed_by_site() {
  local file="$1" name="$2"
  jq -e --arg n "$name" '
    (.not_installed // []) | any(
      .name == $n and .reason_code == "shadowed_by_site"
      and .source == "shipped" and (.winner.source // "") == "site"
    )
  ' -- "$file" >/dev/null \
    || { printf 'the catalog does not report %s as shadowed_by_site with a site winner: %s\n' "$name" "$file" >&2; return 1; }
  return 0
}

# walk_assert_bundled_uncertified <survey-json> <name> — a plugin-bundled
# adapter is `uncertified` BY CONSTRUCTION and its remediation is the
# promotion path, never "get it signed" (AdapterSources::diagnostics() says so
# in its own comment, and certification binds `source: "site"` plus the exact
# `adapters/<name>.json` path inside the signed statement). A survey reporting
# a signed word for a bundled adapter is a defect, not good news.
walk_assert_bundled_uncertified() {
  local file="$1" name="$2"
  jq -e --arg n "$name" '
    (.adapters[] | select(.name == $n))
    | .source == "plugin" and .certification == "uncertified"
  ' -- "$file" >/dev/null \
    || { printf '%s is not reported as an uncertified plugin-source adapter: %s\n' "$name" "$file" >&2; return 1; }
  return 0
}

# walk_batch_decide <batch-json> <section> <key> <class> <out> — record one
# reviewed classification in an exported batch.
#
# The batch is digest-bound to the exact queue it was exported from
# (ClassificationBatch::validate()), so this edits a decision IN PLACE and
# never adds one: a decision naming a key the queue does not carry is the
# stale-batch case the binding exists to refuse, and this refuses it here
# rather than discovering it at apply time.
walk_batch_decide() {
  local file="$1" section="$2" key="$3" class="$4" out="$5"
  jq -e --arg s "$section" --arg k "$key" '
    [.decisions[] | select(.section == $s and .key == $k)] | length == 1
  ' -- "$file" >/dev/null \
    || { printf 'the exported batch carries no %s:%s decision to review\n' "$section" "$key" >&2; return 1; }
  jq --arg s "$section" --arg k "$key" --arg c "$class" '
    .decisions = [.decisions[] | if .section == $s and .key == $k then .class = $c else . end]
  ' -- "$file" > "$out"
}

# walk_batch_class <batch-json> <section> <key> — read one decision back.
walk_batch_class() {
  local file="$1" section="$2" key="$3"
  jq -er --arg s "$section" --arg k "$key" '
    (.decisions[] | select(.section == $s and .key == $k) | .class)
    // error("no decision for \($s):\($k)")
  ' -- "$file"
}

# walk_refusal_code <file> — the typed reason code of a refusal.
#
# The walk runs every step it expects to REFUSE with `--format=json`, so the
# common path is the versioned envelope
# (agent/src/Command/Cli.php::REFUSAL_FORMAT). Two fallbacks follow, in the
# order a stop is most likely to be readable: a bracketed `[code]` as init's
# and plan's renderers print blockers, then the literal operator sentence of
# the one gate whose human half the walk deliberately also reads. Anything
# else returns 1 rather than guessing, because the whole value of this reader
# is that an unexpected stop gets NAMED.
walk_refusal_code() {
  local file="$1" code
  code="$(grep -o '"reason_code":"[a-z][a-z0-9_]*"' -- "$file" | head -1 | sed 's/.*:"//; s/"$//' || true)"
  [ -n "$code" ] && { printf '%s\n' "$code"; return 0; }
  code="$(grep -o '"error":"[a-z][a-z0-9_]*"' -- "$file" | head -1 | sed 's/.*:"//; s/"$//' || true)"
  [ -n "$code" ] && { printf '%s\n' "$code"; return 0; }
  code="$(grep -oE '\[[a-z][a-z0-9_]{2,63}\]' -- "$file" | head -1 | tr -d '[]' || true)"
  [ -n "$code" ] && { printf '%s\n' "$code"; return 0; }
  if grep -Fq 'authored state exists outside policy scope' -- "$file"; then
    printf 'incomplete_policy_scope\n'; return 0
  fi
  printf 'no typed reason code found in %s\n' "$file" >&2
  return 1
}

# walk_assert_init_line <init-output> <heading> <extension> <code> — one line
# of `Init::render()`, matched on the three fields §3.4 fixes: the heading
# (which is two words — the severity and the KIND, e.g. `UNMANAGED PLUGIN`),
# the plugin basename, and the bracketed reason code.
walk_assert_init_line() {
  local file="$1" heading="$2" extension="$3" code="$4"
  grep -Fq "  $heading $extension [$code]" -- "$file" \
    || { printf 'init did not print "%s %s [%s]": %s\n' "$heading" "$extension" "$code" "$file" >&2; return 1; }
  return 0
}

# walk_assert_plan_document <json-file> — the plan validates as a
# `duo-authorization-plan/v1`, cites the contract it was authorized under,
# lists what recovery does NOT restore, and names the recovery profile AND the
# reason it was selected. "Names the profile" without "and why" is the failure
# this checks for: a profile with no stated reason is an assertion, not
# evidence.
walk_assert_plan_document() {
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

# walk_assert_verify_report <json-file> — convergence pass, every declared
# journey pass, at least two journeys declared, and the uncovered-surfaces
# list present (an empty list is a report; a missing key is a silence).
walk_assert_verify_report() {
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

# walk_claim_boundary <json-file> [<jq-path-to-claim>] — the claim's literal
# `maximum_loss_boundary` sentence, verbatim.
walk_claim_boundary() {
  local file="$1" path="${2:-.}"
  jq -er "$path"' | .maximum_loss_boundary' -- "$file"
}

# walk_boundary_expectation <boundary-sentence> — what that exact sentence
# says must happen to a row written after the checkpoint.
#
# `RecoveryClaim::lossBoundary()` emits exactly three sentences and no others.
# This function refuses anything else rather than guessing, because the point
# of the recovery step is that the printed claim is taken literally.
walk_boundary_expectation() {
  local boundary="$1"
  case "$boundary" in
    'writes committed after checkpoint '*) printf 'lost\n' ;;
    'writes committed after the checkpoint this release takes immediately before mutation'*) printf 'lost\n' ;;
    'everything this release writes: no checkpoint is taken, so nothing bounds the loss') printf 'unbounded\n' ;;
    *) printf 'the recovery claim printed a maximum_loss_boundary this walk cannot read literally: %s\n' \
         "$boundary" >&2; return 1 ;;
  esac
}

# walk_assert_claim_literal <json-file> [<jq-path>] — a claim that gives
# nothing up is the one thing no profile can honestly say (RecoveryClaim's own
# invariant). The walk reads the claim out of a frozen plan and out of the
# recovery outcome, so it re-checks the invariant at both readings.
walk_assert_claim_literal() {
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

# walk_assert_phase_order <file> <phase...> — the phases appear, in this
# order. Deploy-before-apply is the only reason the release step exists.
walk_assert_phase_order() {
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

# walk_checkpoint_id <catalog-json> — the receipt id `--restore=` consumes.
# A catalog with no row is a failure to NAME, not a row to skip.
walk_checkpoint_id() {
  local file="$1"
  jq -er '
    if (.rows | length) == 0
    then error("the checkpoint catalog lists no receipt: " + ((.disclosures // []) | join("; ")))
    else .rows[0].id end
  ' -- "$file"
}

# walk_projection_subset <assess-json> <surface-id>... — the release
# projection of exactly the named surfaces, canonically ordered, for the
# before/after comparison. Only the projected words are compared: a timestamp
# or a digest differing between two assessments of the same site is the clock
# moving, not the site moving.
walk_projection_subset() {
  local file="$1"; shift
  local ids
  ids="$(printf '%s\n' "$@" | jq -R . | jq -sc .)"
  jq -Sc --argjson ids "$ids" '
    [ .surfaces[]
      | select(.id as $i | $ids | index($i))
      | {id, state_class, handling,
         release: (.operations.release
                   | {readiness, certification_provenance, principal, trust_root,
                      effect_containment, effect_recovery_semantics, handling, state_class})}
    ] | sort_by(.id)
  ' -- "$file"
}

# walk_write_registry <envs-file> <compose-file> <one> <two> <php> <provider> <config>
# The machine-local overlay: the two pair sides, plus the `preview` entry that
# carries the privileged provider block. The SOURCE side carries the same
# block — `EnvironmentCommand` builds a provider client for `--from <env>` as
# well as for the target, and the reference provider's config names ${PAIR}1 as
# its `source_environment`. Only the release target `${PAIR}2` carries none:
# `duo release`/`verify`/`recover` need no provider, and a provider config is
# privileged host configuration nothing else should carry. Extracted so the run
# path and --self-check write the SAME bytes.
walk_write_registry() {
  jq -n --arg compose "$2" --arg one "$3" --arg two "$4" \
        --arg php "$5" --arg provider "$6" --arg config "$7" '
    {envs: {
      ($one): {
        transport: "docker", compose_file: $compose, service: "cli1", repo_path: "/siterepo",
        environment_provider: {command: [$php, $provider, $config], timeout_seconds: 60}
      },
      ($two): {transport: "docker", compose_file: $compose, service: "cli2", repo_path: "/siterepo"},
      preview: {
        transport: "docker", compose_file: $compose, service: "cli2", repo_path: "/siterepo",
        environment_provider: {command: [$php, $provider, $config], timeout_seconds: 60}
      }
    }}' > "$1"
}

# walk_validate_registry <envs-file> <source-env> — load it through the REAL
# orchestrator registry and construct the REAL provider client from both
# provider-bearing entries. `CommandEnvironmentProvider::fromEnvironment()` is
# EnvironmentLifecycle's own provider-config schema, so a malformed provider
# block refuses on this machine, offline, instead of at materialization time
# with a snapshot already taken.
walk_validate_registry() {
  php -r '
    require $argv[2] . "/cli/src/Environment/Registry.php";
    require $argv[2] . "/cli/src/Environment/EnvironmentLifecycle.php";
    $envs = \Duo\Orchestrator\Registry::load($argv[1], dirname($argv[1]));
    $source = $argv[3];
    foreach (["preview", $source] as $name) {
        if (!is_array($envs[$name] ?? null)) {
            fwrite(STDERR, "the registry has no $name environment\n");
            exit(1);
        }
    }
    \Duo\Orchestrator\CommandEnvironmentProvider::fromEnvironment("preview", $envs["preview"]);
    \Duo\Orchestrator\CommandEnvironmentProvider::fromEnvironment($source, $envs[$source]);
    echo "preview and source provider config accepted by EnvironmentLifecycle\n";
  ' "$1" "$REPO_ROOT" "$2"
}

# ---------------------------------------------------------------------------
# --self-check: every helper above, against the recorded fixtures.
# ---------------------------------------------------------------------------
self_check() {
  local expect got
  say "self-check: pure helpers against sandbox/tests/fixtures/adapter-walk/"
  [ -d "$FIXTURES" ] || fail "fixture directory is missing: $FIXTURES (run php $FIXTURES/make-fixtures.php)"

  # ---- json readers
  got="$(walk_json_tail "$FIXTURES/plan-only.stdout.txt" | jq -r '.format')" \
    || soft_fail "walk_json_tail could not extract the plan document"
  [ "$got" = 'duo-authorization-plan/v1' ] \
    && pass "walk_json_tail extracts the document a rendered plan page is followed by" \
    || soft_fail "walk_json_tail extracted '$got'"
  if walk_json_tail "$FIXTURES/assess-human.clean.txt" >/dev/null 2>&1; then
    soft_fail "walk_json_tail accepted output that carries no JSON document"
  else
    pass "walk_json_tail refuses output with no canonical document rather than returning nothing"
  fi
  got="$(walk_agent_json "$FIXTURES/refusal.incomplete-policy-scope.json" | jq -r '.reason_code')" \
    || soft_fail "walk_agent_json could not read a compact agent refusal"
  [ "$got" = incomplete_policy_scope ] \
    && pass "walk_agent_json reads the trailing compact object the agent prints" \
    || soft_fail "walk_agent_json read '$got'"
  if walk_agent_json "$FIXTURES/refusal.incomplete-policy-scope.txt" >/dev/null 2>&1; then
    soft_fail "walk_agent_json accepted a human refusal as a JSON document"
  else
    pass "walk_agent_json refuses a stream whose trailing line is not JSON"
  fi

  # ---- leak gate
  if walk_assert_no_internal_ids "$FIXTURES/assess-human.clean.txt" 'clean assess' 2>/dev/null; then
    pass "the leak gate passes a clean assess human view"
  else
    soft_fail "the leak gate rejected a clean assess human view"
  fi
  local leak
  for leak in leaks-uuid leaks-artifact-hash; do
    if walk_assert_no_internal_ids "$FIXTURES/assess-human.$leak.txt" 'leaking assess' 2>/dev/null; then
      soft_fail "the leak gate accepted $leak"
    else
      pass "the leak gate catches $leak"
    fi
  done

  # ---- assess document + rows
  if walk_assert_assess_document "$FIXTURES/assess-report.s1.json" 2>/dev/null; then
    pass "the assess-document check passes a well-formed report"
  else
    soft_fail "the assess-document check rejected a well-formed report"
  fi
  if walk_assert_assess_document "$FIXTURES/assess-report.fail-unclassified-no-next-action.json" 2>/dev/null; then
    soft_fail "the assess-document check accepted an unclassified row whose action is 'nothing — supported'"
  else
    pass "the assess-document check catches an unclassified row with no real gap action"
  fi
  expect=$'unclassified\tblock\tNot qualified\tUncertified\tunknown\tunknown'
  got="$(walk_assess_projection "$FIXTURES/assess-report.s1.json" "plugin:$WPFORMS_SLUG" release)"
  [ "$got" = "$expect" ] \
    && pass "the plugin: row projects §3.6's unclassified/block/Not qualified/Uncertified/unknown/unknown" \
    || soft_fail "the plugin: row projected '$got'"
  got="$(walk_assess_next_action "$FIXTURES/assess-report.s1.json" "plugin:$WPFORMS_SLUG")"
  [ "$got" = 'install adapter' ] \
    && pass "the plugin: row's next action is 'install adapter'" \
    || soft_fail "the plugin: row's next action read '$got'"
  got="$(walk_assess_next_action "$FIXTURES/assess-report.s1.json" "table:$WPFORMS_TABLE")"
  [ "$got" = 'install adapter' ] \
    && pass "an undeclared table with a probable owning plugin reads 'install adapter', never 'qualify in rehearsal'" \
    || soft_fail "the undeclared-table row's next action read '$got'"
  got="$(walk_assess_next_action "$FIXTURES/assess-report.certify-adapter.json" "post_type:$WPFORMS_CPT")"
  [ "$got" = 'certify adapter' ] \
    && pass "a Not-qualified row caused by adapter_source_uncertified reads §3.6's new word 'certify adapter'" \
    || soft_fail "the uncertified-adapter row's next action read '$got'"
  got="$(walk_assess_next_action "$FIXTURES/assess-report.fail-install-adapter-for-uncertified.json" "post_type:$WPFORMS_CPT")"
  [ "$got" != 'certify adapter' ] \
    && pass "the gap-action reader tells 'certify adapter' apart from 'install adapter' rather than accepting either" \
    || soft_fail "the gap-action reader read 'certify adapter' from the install-adapter fixture"

  # ---- certification triple
  expect=$'Site-certified\tsite\t'"$WALK_KEY_ID"
  got="$(walk_assess_certification "$FIXTURES/assess-report.site-certified.json" "post_type:$WPFORMS_CPT" release)"
  [ "$got" = "$expect" ] \
    && pass "a site-signed surface projects Site-certified with trust_root=site and the principal named" \
    || soft_fail "the certification triple read '$got', expected '$expect'"
  got="$(walk_assess_certification "$FIXTURES/assess-report.fail-platform-certified.json" "post_type:$WPFORMS_CPT" release | cut -f1)"
  [ "$got" != 'Site-certified' ] \
    && pass "the certification reader reports Platform-certified rather than smoothing it into Site-certified" \
    || soft_fail "the certification reader read Site-certified from the platform fixture"

  # ---- unknown block
  if walk_assert_unknown_names "$FIXTURES/assess-report.s1.json" \
      "option-prefix:$WPFORMS_OPTION_FAMILY" "table:$WPFORMS_TABLE" 2>/dev/null; then
    pass "the unknown block names the invisible option prefix and the undeclared table"
  else
    soft_fail "the unknown-names check rejected a report that names both"
  fi
  if walk_assert_unknown_names "$FIXTURES/assess-report.fail-no-option-prefix.json" \
      "option-prefix:$WPFORMS_OPTION_FAMILY" 2>/dev/null; then
    soft_fail "the unknown-names check accepted a report that never names the option prefix"
  else
    pass "the unknown-names check catches a missing option prefix"
  fi
  if walk_assert_unknown_table_line "$FIXTURES/assess-human.clean.txt" 2>/dev/null; then
    pass "the human unknown block prints 'N undeclared table(s)'"
  else
    soft_fail "the undeclared-table-line check rejected a view that carries the line"
  fi
  if walk_assert_unknown_table_line "$FIXTURES/assess-human.no-undeclared-table-line.txt" 2>/dev/null; then
    soft_fail "the undeclared-table-line check accepted today's view, which has no table line (§3.7 bug 2)"
  else
    pass "the undeclared-table-line check catches §3.7 bug 2"
  fi
  got="$(walk_gap_count "$FIXTURES/assess-human.clean.txt" 'install adapter')"
  [ "$got" -ge 1 ] \
    && pass "the next-actions roll-up counts $got 'install adapter' finding(s)" \
    || soft_fail "the roll-up reader counted '$got' install-adapter findings"
  if walk_gap_count "$FIXTURES/assess-human.clean.txt" 'certify adapter' >/dev/null 2>&1; then
    soft_fail "the roll-up reader invented a count for an action the fixture never prints"
  else
    pass "the roll-up reader refuses an action the roll-up does not print"
  fi

  # ---- coverage
  got="$(walk_coverage_undeclared "$FIXTURES/coverage.pass.json" | sort | tr '\n' ',')"
  [ "$got" = 'wpforms_logs,wpforms_payments,wpforms_tasks_meta,' ] \
    && pass "coverage publishes the logical name of every undeclared table" \
    || soft_fail "the coverage reader read '$got'"
  if walk_coverage_undeclared "$FIXTURES/coverage.fail-no-logical-name.json" >/dev/null 2>&1; then
    soft_fail "the coverage reader accepted today's rows, which carry no logical_name (§3.7 bug 1)"
  else
    pass "the coverage reader catches §3.7 bug 1 by name"
  fi

  # ---- adapter catalog
  expect=$'site\tsite_signed\tsite\t'"$WALK_KEY_ID"
  got="$(walk_catalog_row "$FIXTURES/adapter-catalog.site-signed.json" wpforms)"
  [ "$got" = "$expect" ] \
    && pass "a certified site adapter's catalog row reads site/site_signed/site/<principal>" \
    || soft_fail "the catalog row read '$got', expected '$expect'"
  got="$(walk_catalog_row "$FIXTURES/adapter-catalog.uncertified.json" wpforms | cut -f2)"
  [ "$got" = uncertified ] \
    && pass "the catalog reader reports an unsigned site adapter as uncertified" \
    || soft_fail "the catalog reader read '$got' for an unsigned site adapter"
  if walk_catalog_row "$FIXTURES/adapter-catalog.site-signed.json" nosuchadapter >/dev/null 2>&1; then
    soft_fail "the catalog reader invented a row for an adapter that is not installed"
  else
    pass "the catalog reader refuses a name the catalog does not carry"
  fi
  if walk_assert_shadowed_by_site "$FIXTURES/adapter-catalog.shadowed.json" woocommerce 2>/dev/null; then
    pass "the override check reads shadowed_by_site with a site winner"
  else
    soft_fail "the override check rejected a catalog that reports the override"
  fi
  if walk_assert_shadowed_by_site "$FIXTURES/adapter-catalog.site-signed.json" woocommerce 2>/dev/null; then
    soft_fail "the override check accepted a catalog with no shadow row at all"
  else
    pass "the override check catches a catalog that reports no override"
  fi

  # ---- adapter survey (the plugin source)
  if walk_assert_bundled_uncertified "$FIXTURES/adapter-survey.bundled.json" "$ACME_SLUG" 2>/dev/null; then
    pass "the survey check reads a bundled adapter as an uncertified plugin-source row"
  else
    soft_fail "the survey check rejected a well-formed bundled row"
  fi
  if walk_assert_bundled_uncertified "$FIXTURES/adapter-survey.fail-bundled-signed.json" "$ACME_SLUG" 2>/dev/null; then
    soft_fail "the survey check accepted a bundled adapter claiming a signed word"
  else
    pass "the survey check catches a bundled adapter claiming a signed word"
  fi
  grep -Fq "install this adapter as a repository package at adapters/$ACME_SLUG.json" \
    "$FIXTURES/adapter-survey.bundled.json" \
    && pass "the bundled row carries the promotion path as its remediation" \
    || soft_fail "the bundled row carries no promotion-path remediation"

  # ---- classification batch
  local tmp
  tmp="$(mktemp -d "${TMPDIR:-/tmp}/grind-adapter-walk-selfcheck.XXXXXX")"
  if walk_batch_decide "$FIXTURES/classification-batch.pending.json" \
      scope "post_type:$WPFORMS_CPT" runtime "$tmp/batch.json" 2>/dev/null; then
    got="$(walk_batch_class "$tmp/batch.json" scope "post_type:$WPFORMS_CPT")"
    [ "$got" = runtime ] \
      && pass "the batch editor records scope:post_type:$WPFORMS_CPT=runtime in place" \
      || soft_fail "the batch editor recorded '$got'"
  else
    soft_fail "the batch editor could not review a decision the queue carries"
  fi
  if walk_batch_decide "$FIXTURES/classification-batch.pending.json" \
      scope 'post_type:not_in_this_queue' runtime "$tmp/bad.json" 2>/dev/null; then
    soft_fail "the batch editor added a decision the bound queue never carried"
  else
    pass "the batch editor refuses a decision outside the bound queue"
  fi
  got="$(walk_batch_class "$FIXTURES/classification-batch.decided.json" scope "post_type:$WPFORMS_CPT")"
  [ "$got" = runtime ] \
    && pass "the batch reader reads a recorded decision back" \
    || soft_fail "the batch reader read '$got'"

  # ---- refusal reader
  got="$(walk_refusal_code "$FIXTURES/refusal.incomplete-policy-scope.json")"
  [ "$got" = incomplete_policy_scope ] \
    && pass "the refusal reader names a capture stop from the JSON envelope" \
    || soft_fail "the refusal reader read '$got' from the capture envelope"
  got="$(walk_refusal_code "$FIXTURES/refusal.adapter-source-uncertified.json")"
  [ "$got" = adapter_source_uncertified ] \
    && pass "the refusal reader names an init stop from the JSON envelope" \
    || soft_fail "the refusal reader read '$got' from the init envelope"
  got="$(walk_refusal_code "$FIXTURES/refusal.incomplete-policy-scope.txt")"
  [ "$got" = incomplete_policy_scope ] \
    && pass "the refusal reader names the same stop from its human operator message" \
    || soft_fail "the refusal reader read '$got' from the human message"
  got="$(walk_refusal_code "$FIXTURES/init-blocked.txt")"
  [ "$got" = active_plugin_without_adapter ] \
    && pass "the refusal reader names an init blocker from its bracketed code" \
    || soft_fail "the refusal reader read '$got' from the init blocker line"
  if walk_refusal_code "$FIXTURES/release-phases.ordered.txt" >/dev/null 2>&1; then
    soft_fail "the refusal reader invented a reason code for output that carries none"
  else
    pass "the refusal reader refuses output that carries no typed reason code"
  fi

  # ---- init lines
  if walk_assert_init_line "$FIXTURES/init-blocked.txt" 'UNSUPPORTED PLUGIN' "$WPFORMS_BASENAME" active_plugin_without_adapter 2>/dev/null; then
    pass "init's refusal names the unmanaged plugin with its typed code"
  else
    soft_fail "the init-line check rejected a blocked proposal that names the plugin"
  fi
  grep -Fq -- '--allow-unmanaged-plugins' "$FIXTURES/init-blocked.txt" \
    && pass "the blocked proposal's remediation names --allow-unmanaged-plugins (§3.4)" \
    || soft_fail "the blocked proposal's remediation does not name --allow-unmanaged-plugins"
  if walk_assert_init_line "$FIXTURES/init-allow-unmanaged.txt" 'UNMANAGED PLUGIN' "$WPFORMS_BASENAME" active_plugin_without_adapter 2>/dev/null; then
    pass "--allow-unmanaged-plugins prints UNMANAGED PLUGIN instead of UNSUPPORTED (§3.4)"
  else
    soft_fail "the init-line check rejected the --allow-unmanaged-plugins advisory form"
  fi
  if walk_assert_init_line "$FIXTURES/init-blocked.txt" 'UNMANAGED PLUGIN' "$WPFORMS_BASENAME" active_plugin_without_adapter 2>/dev/null; then
    soft_fail "the init-line check read UNMANAGED out of a view that says UNSUPPORTED"
  else
    pass "the init-line check tells the advisory form apart from the refusal form"
  fi
  grep -Fq 'certify it with duo adapter certify' "$FIXTURES/init-uncertified-adapter.txt" \
    && pass "an uncertified site adapter's init blocker names duo adapter certify (§3.4)" \
    || soft_fail "the uncertified-adapter blocker does not name duo adapter certify"

  # ---- authorization plan
  if walk_assert_plan_document "$FIXTURES/authorization-plan.pass.json" 2>/dev/null; then
    pass "the plan check passes a complete frozen plan"
  else
    soft_fail "the plan check rejected a complete frozen plan"
  fi
  local broken
  for broken in fail-no-contract-digest fail-no-recovery-reason; do
    if walk_assert_plan_document "$FIXTURES/authorization-plan.$broken.json" 2>/dev/null; then
      soft_fail "the plan check accepted $broken"
    else
      pass "the plan check catches $broken"
    fi
  done

  # ---- verify report
  if walk_assert_verify_report "$FIXTURES/verify-report.pass.json" 2>/dev/null; then
    pass "the verify check passes a passing report"
  else
    soft_fail "the verify check rejected a passing report"
  fi
  if walk_assert_verify_report "$FIXTURES/verify-report.fail-journey.json" 2>/dev/null; then
    soft_fail "the verify check accepted a report with a failing journey"
  else
    pass "the verify check catches a failing journey"
  fi

  # ---- recovery claim + boundary
  if walk_assert_claim_literal "$FIXTURES/recovery-claim.operator-directed.json" 2>/dev/null; then
    pass "the claim check passes an operator-directed claim"
  else
    soft_fail "the claim check rejected an operator-directed claim"
  fi
  if walk_assert_claim_literal "$FIXTURES/recovery-claim.fail-empty-does-not-restore.json" 2>/dev/null; then
    soft_fail "the claim check accepted a claim that gives nothing up"
  else
    pass "the claim check catches a claim that gives nothing up"
  fi
  if walk_assert_claim_literal "$FIXTURES/authorization-plan.pass.json" '.recovery_profile.claim' 2>/dev/null; then
    pass "the claim check reads the claim embedded verbatim in a frozen plan"
  else
    soft_fail "the claim check could not read the plan-embedded claim"
  fi
  got="$(walk_boundary_expectation "$(walk_claim_boundary "$FIXTURES/recovery-claim.operator-directed.json")")"
  [ "$got" = lost ] \
    && pass "a checkpoint-bounded claim means a post-checkpoint row is lost" \
    || soft_fail "the operator-directed boundary read as '$got'"
  got="$(walk_boundary_expectation "$(walk_claim_boundary "$FIXTURES/recovery-claim.none.json")")"
  [ "$got" = unbounded ] \
    && pass "the no-checkpoint claim reads as unbounded loss" \
    || soft_fail "the none-profile boundary read as '$got'"
  if walk_boundary_expectation 'rollback restores everything' >/dev/null 2>&1; then
    soft_fail "the boundary reader invented a meaning for a sentence RecoveryClaim never emits"
  else
    pass "the boundary reader refuses a sentence RecoveryClaim never emits"
  fi

  # ---- phase order
  if walk_assert_phase_order "$FIXTURES/release-phases.ordered.txt" \
      'promote phase: checkpoint' 'promote phase: code-stage' \
      'promote phase: lifecycle-retire' 'promote phase: lifecycle-activate' \
      'promote phase: apply' 2>/dev/null; then
    pass "the phase-order check accepts deploy-before-apply"
  else
    soft_fail "the phase-order check rejected deploy-before-apply"
  fi
  if walk_assert_phase_order "$FIXTURES/release-phases.apply-first.txt" \
      'promote phase: lifecycle-retire' 'promote phase: apply' 2>/dev/null; then
    soft_fail "the phase-order check accepted apply before the lifecycle"
  else
    pass "the phase-order check catches apply before the lifecycle"
  fi

  # ---- checkpoint catalog
  got="$(walk_checkpoint_id "$FIXTURES/checkpoint-catalog.json")"
  [ "$got" = 'scoped-20260817-091300-0001' ] \
    && pass "the receipt id --restore= consumes is read from the catalog" \
    || soft_fail "the catalog reader read '$got'"
  if walk_checkpoint_id "$FIXTURES/checkpoint-catalog.empty.json" >/dev/null 2>&1; then
    soft_fail "the catalog reader invented a receipt id for an empty catalog"
  else
    pass "an empty catalog is named as a failure, not skipped"
  fi

  # ---- projection subset
  got="$(walk_projection_subset "$FIXTURES/assess-report.s1.json" "plugin:$WPFORMS_SLUG" post_type:product)"
  expect="$(walk_projection_subset "$FIXTURES/assess-report.s1.json" post_type:product "plugin:$WPFORMS_SLUG")"
  [ "$got" = "$expect" ] \
    && pass "the projection subset is independent of the order the surfaces are named in" \
    || soft_fail "the projection subset is order-dependent"
  expect="$(walk_projection_subset "$FIXTURES/assess-report.site-certified.json" post_type:product)"
  got="$(walk_projection_subset "$FIXTURES/assess-report.s1.json" post_type:product)"
  [ "$got" != "$expect" ] \
    && pass "the projection subset sees a certification change between two assessments" \
    || soft_fail "the projection subset is blind to a certification change"

  # ---- registry shape + EnvironmentLifecycle's provider-config schema, offline
  walk_write_registry "$tmp/.duo-envs.json" "$SANDBOX/pair.yml" "${PAIR}1" "${PAIR}2" \
    "$(command -v php)" "$REPO_ROOT/tools/reference-env-provider.php" "$tmp/config.json"
  if walk_validate_registry "$tmp/.duo-envs.json" "${PAIR}1" >/dev/null 2>&1; then
    pass "the registry this walk writes is accepted by EnvironmentLifecycle's provider-config schema, source and preview"
  else
    soft_fail "the registry this walk writes is rejected by EnvironmentLifecycle: $(walk_validate_registry "$tmp/.duo-envs.json" "${PAIR}1" 2>&1)"
  fi
  if jq -e --arg two "${PAIR}2" '.envs[$two] | has("environment_provider") | not' "$tmp/.duo-envs.json" >/dev/null; then
    pass "the release target carries no provider block"
  else
    soft_fail "the release target carries a provider block it does not need"
  fi
  jq '.envs.preview.environment_provider.command[0] = "php"' "$tmp/.duo-envs.json" > "$tmp/relative.json"
  if walk_validate_registry "$tmp/relative.json" "${PAIR}1" >/dev/null 2>&1; then
    soft_fail "EnvironmentLifecycle accepted a relative provider executable"
  else
    pass "a relative provider executable is refused before any provider call"
  fi
  rm -rf -- "$tmp"

  if [ "$FAILURES" -ne 0 ]; then
    printf '\nGRIND_ADAPTER_WALK SELF-CHECK FAILED (%d)\n' "$FAILURES" >&2
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
    exit "$status"
  fi
  if [ "${WALK_KEEP:-0}" = 1 ]; then
    printf 'note: WALK_KEEP=1 — pair %s, %s and %s were left in place\n' \
      "$PAIR" "siterepo/${PAIR}1" "siterepo/${PAIR}2" >&2
    [ -n "$SCRATCH" ] && printf 'note: evidence kept at %s\n' "$SCRATCH" >&2
    exit "$status"
  fi
  if [ "$PAIR_UP" = 1 ]; then
    # Capture publishes as uid 33 inside the cli container, so its staging
    # descendants can be host-undeletable after a failed run. Normalize only
    # this disposable pair's bind mounts before removing them.
    "${COMPOSE[@]}" run --rm -T -u root cli1 sh -c 'chmod -R ugo+rwX /siterepo' >/dev/null 2>&1
    "${COMPOSE[@]}" run --rm -T -u root cli2 sh -c 'chmod -R ugo+rwX /siterepo' >/dev/null 2>&1
    if ! bash bin/pair.sh destroy "$PAIR" >/dev/null 2>&1; then
      printf 'FAIL: walk pair destroy failed for %s\n' "$PAIR" >&2
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
    printf 'FAIL: cleanup left a walk site repo behind under siterepo/\n' >&2
    status=1
  fi
  [ -n "$SCRATCH" ] && rm -rf -- "$SCRATCH"
  exit "$status"
}

preflight() {
  [[ "$PAIR" =~ ^[a-z][a-z0-9]*$ ]] \
    || fail "WALK_PAIR '$PAIR' is invalid (pair.sh grammar: lowercase letters/digits, letter first)"
  if [ "$PAIR" != awalk ] && { [ -z "${WALK_PORT1:-}" ] || [ -z "${WALK_PORT2:-}" ]; }; then
    fail "a custom WALK_PAIR '$PAIR' requires explicit WALK_PORT1 and WALK_PORT2 (9500/9501 belong to the shared 'awalk' instance)"
  fi
  [[ "$PORT1" =~ ^[0-9]+$ && "$PORT2" =~ ^[0-9]+$ && "$PORT1" != "$PORT2" ]] \
    || fail "WALK_PORT1/WALK_PORT2 must be two different numeric host ports (got '$PORT1'/'$PORT2')"
  case "$WORDPRESS_OFFLINE" in 0|1) ;; *) fail "DUO_WORDPRESS_ORG_OFFLINE must be 0 or 1" ;; esac
  [ -f "$DUO" ] || fail "host CLI missing: $DUO"
  [ -f "$REPO_ROOT/tools/reference-env-provider.php" ] \
    || fail "the reference environment provider is missing: $REPO_ROOT/tools/reference-env-provider.php"
  [ -f "$SANDBOX/fixtures/$ACME_SLUG/$ACME_SLUG.php" ] \
    || fail "the walk's own fixture plugin is missing: sandbox/fixtures/$ACME_SLUG/$ACME_SLUG.php"
  [ -f "$SANDBOX/fixtures/$ACME_SLUG/duo-adapter.json" ] \
    || fail "the fixture plugin bundles no duo-adapter.json; S3 has no bundled adapter source to survey"

  # Exact artifact installs are mandatory — fetch-artifact.sh refuses an
  # unpinned version rather than falling through to the wordpress.org catalog —
  # so every pin is resolved HERE, before any pair is created, and an
  # unresolvable one is a named refusal rather than a silent substitution.
  jq -e --arg slug "$THEME_SLUG" --arg v "$THEME_VERSION" '.themes[$slug][$v]' \
    conformance/artifacts.lock.json >/dev/null \
    || fail "conformance/artifacts.lock.json has no pin for theme $THEME_SLUG $THEME_VERSION.
Either add the pin:
  .themes.$THEME_SLUG.\"<version>\" = {url, sha256, role: \"exercise-fixture\"}
or run against a theme this estate already pins:
  WALK_THEME_SLUG=twentytwentyone WALK_THEME_VERSION=2.8 bash sandbox/tests/grind_adapter_walk.sh"
  jq -e --arg v "$WOO_VERSION" '.plugins.woocommerce[$v]' conformance/artifacts.lock.json >/dev/null \
    || fail "conformance/artifacts.lock.json has no pin for woocommerce $WOO_VERSION"
  jq -e --arg v "$WPFORMS_VERSION" '.plugins["'"$WPFORMS_SLUG"'"][$v]' conformance/artifacts.lock.json >/dev/null \
    || fail "conformance/artifacts.lock.json has no pin for $WPFORMS_SLUG $WPFORMS_VERSION — T6 §4 names WPForms Lite 2.0.0.4 as the subject"

  local scenario
  for scenario in ${SCENARIOS//,/ }; do
    case "$scenario" in
      S1|S2|S3|S4) ;;
      *) fail "WALK_SCENARIOS names '$scenario'; the contract defines S1, S2, S3 and S4" ;;
    esac
  done
  pass "pinned artifacts resolved: woocommerce $WOO_VERSION, $WPFORMS_SLUG $WPFORMS_VERSION, $THEME_SLUG $THEME_VERSION"
  pass "scenarios selected: $SCENARIOS"
}

# ---------------------------------------------------------------------------
# Modes that stop before docker.
# ---------------------------------------------------------------------------
require jq
require php
self_check
if [ "$MODE" = self-check ]; then
  printf '\nGRIND_ADAPTER_WALK SELF-CHECK PASSED\n' >&3
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

preflight

if dry; then
  SCRATCH="$SANDBOX/tmp/grind-adapter-walk-dry-run"
else
  # sandbox/tmp is the estate's gitignored scratch root and is not guaranteed
  # to exist in a fresh checkout (AGENTS.md: scratch goes here, never under
  # agent/, cli/ or sandbox/bin/, which the certification closure walks whole).
  mkdir -p "$SANDBOX/tmp"
  SCRATCH="$(mktemp -d "$SANDBOX/tmp/grind-adapter-walk.XXXXXX")"
fi
ENVS_FILE="$SCRATCH/.duo-envs.json"
PROVIDER_CONFIG="$SCRATCH/reference-env-provider.json"
PROVIDER_STATE="$SCRATCH/provider-state"
EVIDENCE="$SCRATCH/evidence"
# The organization's private key. §3.1: private keys never live in the
# repository, and `duo adapter keygen` refuses a path inside the site repo — so
# the walk's key lives in the run's own scratch, outside both site repos, and
# the walk asserts the refusal of the other placement.
KEYDIR="$SCRATCH/keys"
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
sh_side() { # sh_side <1|2> <shell-command>
  local side="$1"; shift
  run "${COMPOSE[@]}" run --rm -T "cli${side}" sh -c "$1"
}
git1() { run git -C "$HOST_R1" "$@"; }
git2() { run git -C "$HOST_R2" "$@"; }
commit1() { run git -C "$HOST_R1" -c user.name=duo -c user.email=duo@example.test commit -qm "$1"; }
commit2() { run git -C "$HOST_R2" -c user.name=duo -c user.email=duo@example.test commit -qm "$1"; }

# duo_ok <evidence-file> <cwd> <duo args...>
#
# Run a host `duo` command that is expected to SUCCEED. A non-zero exit is
# reported by its typed reason code, which is the whole discipline of §4: "a
# scenario that hits a product refusal the walk did not expect FAILS naming the
# reason code". A stop nobody named is a bug report nobody can file.
duo_ok() {
  local out="$1" dir="$2"; shift 2
  if dry; then
    plan "(cd $(printf '%q' "$dir") && $(quoted php "$DUO" "--envs-file=$ENVS_FILE" "$@")) > $out 2>&1"
    return 0
  fi
  if ( cd "$dir" && php "$DUO" "--envs-file=$ENVS_FILE" "$@" ) > "$out" 2>&1; then
    return 0
  fi
  local code
  code="$(walk_refusal_code "$out" 2>/dev/null || true)"
  fail "duo $1 refused unexpectedly [${code:-no typed reason code was printed}]; see $out"
}

# duo_refused <evidence-file> <expected-code> <cwd> <duo args...>
#
# Run a host `duo` command that MUST refuse, with exactly this reason code.
# Succeeding is as much a failure as refusing differently: an assertion that a
# gate fires is worthless if the gate silently stopped firing.
duo_refused() {
  local out="$1" expect="$2" dir="$3"; shift 3
  if dry; then
    plan "(cd $(printf '%q' "$dir") && $(quoted php "$DUO" "--envs-file=$ENVS_FILE" "$@")) > $out 2>&1   # MUST refuse $expect"
    return 0
  fi
  if ( cd "$dir" && php "$DUO" "--envs-file=$ENVS_FILE" "$@" ) > "$out" 2>&1; then
    fail "duo $1 was expected to refuse with $expect, but it succeeded; see $out"
  fi
  local code
  code="$(walk_refusal_code "$out" 2>/dev/null || true)"
  [ "$code" = "$expect" ] \
    || fail "duo $1 refused with [${code:-no typed reason code was printed}], not the expected [$expect]; see $out"
}

# wp_ok — the same discipline for the agent's own verbs, which the walk reaches
# directly when the fact it needs exists only on the target. The plugin adapter
# source is the case that forces this: a WordPress-free host process cannot
# read WP_PLUGIN_DIR, so `wp duo adapter-survey` is the only command that can
# answer S3's first question.
wp_ok() {
  local out="$1" side="$2"; shift 2
  if dry; then
    plan "$(quoted "${COMPOSE[@]}" run --rm -T "cli${side}" sh -c 'umask 000; exec wp "$@"' sh "$@") > $out 2>&1"
    return 0
  fi
  if "${COMPOSE[@]}" run --rm -T "cli${side}" sh -c 'umask 000; exec wp "$@"' sh "$@" > "$out" 2>&1; then
    return 0
  fi
  local code
  code="$(walk_refusal_code "$out" 2>/dev/null || true)"
  fail "wp $1 $2 refused unexpectedly on side $side [${code:-no typed reason code was printed}]; see $out"
}

# The candidate-source gate, honoured exactly as conformance/run.sh honours it:
# exported so pair.sh's own assert_candidate_source refuses BEFORE reset
# DROP/CREATEs a database or a container starts. Unset leaves the run
# byte-identical and explicitly not candidate-bound.
if [ -n "${DUO_EXPECTED_SOURCE_SHA:-}" ]; then
  export DUO_EXPECTED_SOURCE_SHA
  pass "candidate-source gate armed: DUO_EXPECTED_SOURCE_SHA=$DUO_EXPECTED_SOURCE_SHA"
else
  note "DUO_EXPECTED_SOURCE_SHA is unset — this run is NOT bound to a source commit"
fi

# The pair's cli containers need Git: `duo init` proves Git on the target
# before confirming, and `duo rehearse` reads the production side's HEAD commit
# inside its own environment (EnvironmentLifecycle::productionCommit()). The
# stock wordpress:cli image ships none, so the same evidence-only image
# `regress_duo_init.sh` and `grind_mup.sh` build is used here —
# `sandbox/init-cli.Dockerfile`, wordpress:cli-php8.3 plus git — and handed to
# pair.sh through DUO_CLI_IMAGE. Every later `duo` call inherits the variable,
# so the docker transport runs the same image.
DUO_CLI_IMAGE="${DUO_CLI_IMAGE:-duo-walk-cli-git:${PAIR}}"
export DUO_CLI_IMAGE

run mkdir -p "$PROVIDER_STATE" "$EVIDENCE" "$KEYDIR"

# ---------------------------------------------------------------------------
# Shared scenario scaffolding.
# ---------------------------------------------------------------------------

# install_side <1|2> <author|target> [with-subject]
#
# Side 1 is the author: activated and set up, because capture must see a fully
# set-up environment. Side 2 arrives with extension FILES only — `duo deploy`
# inside the release reconciles activation from canonical, which is the whole
# point of deploy-before-apply and is exactly conformance/run.sh's split.
#
# `with-subject` adds the pinned subject plugin (WPForms Lite at
# WALK_WPFORMS_VERSION). It is a flag rather than a slug list because the
# version pin belongs to the subject: a second slug installed at the subject's
# version would be a silent mismatch.
install_side() {
  local side="$1" role="$2" subject="${3:-}"
  local artifact
  wp_side "$side" option update blogname "Duo adapter walk ${PAIR}${side}"
  wp_side "$side" site empty --yes
  if dry; then
    plan "fetch_artifact woocommerce $WOO_VERSION cli$side plugin"
    artifact='<container-side-zip>'
  else
    artifact="$(fetch_artifact woocommerce "$WOO_VERSION" "cli$side" plugin)" \
      || fail "could not obtain the pinned woocommerce $WOO_VERSION artifact for side $side"
  fi
  wp_side "$side" plugin install "$artifact" --force
  [ "$role" = author ] && wp_side "$side" plugin activate woocommerce
  if [ "$subject" = with-subject ]; then
    if dry; then
      plan "fetch_artifact $WPFORMS_SLUG $WPFORMS_VERSION cli$side plugin"
    else
      artifact="$(fetch_artifact "$WPFORMS_SLUG" "$WPFORMS_VERSION" "cli$side" plugin)" \
        || fail "could not obtain the pinned $WPFORMS_SLUG $WPFORMS_VERSION artifact for side $side"
    fi
    wp_side "$side" plugin install "$artifact" --force
    [ "$role" = author ] && wp_side "$side" plugin activate "$WPFORMS_SLUG"
  fi
  if ! dry; then
    artifact="$(fetch_artifact "$THEME_SLUG" "$THEME_VERSION" "cli$side" theme)" \
      || fail "could not obtain the pinned $THEME_SLUG $THEME_VERSION artifact for side $side"
  fi
  wp_side "$side" theme install "$artifact" --force
  [ "$role" = author ] && wp_side "$side" theme activate "$THEME_SLUG"
  return 0
}

# install_acme <1|2> <author|target>
#
# The walk's own fixture plugin has no wordpress.org artifact, so it is copied
# into the live plugin directory through the pair's own /siterepo bind (cli1
# and wp1 share the webroot volume). It is deliberately installed LIVE rather
# than only into the repository's `code/` tree: `duo init` builds the code
# baseline FROM the live wp-content, so a plugin that is live before init is a
# plugin the code half carries afterwards — and the bundled adapter source only
# exists in WP_PLUGIN_DIR, which is the whole point of S3.
install_acme() {
  local side="$1" role="$2" repo
  repo="$([ "$side" = 1 ] && printf '%s' "$HOST_R1" || printf '%s' "$HOST_R2")"
  run mkdir -p "$repo/.walk-fixture"
  run cp -R "$SANDBOX/fixtures/$ACME_SLUG" "$repo/.walk-fixture/$ACME_SLUG"
  sh_side "$side" "rm -rf /var/www/html/wp-content/plugins/$ACME_SLUG \
    && cp -R /siterepo/.walk-fixture/$ACME_SLUG /var/www/html/wp-content/plugins/$ACME_SLUG"
  run rm -rf "$repo/.walk-fixture"
  [ "$role" = author ] && wp_side "$side" plugin activate "$ACME_SLUG"
  return 0
}

# scenario_pair <scenario> [with-subject]
#
# One fresh pair per scenario, exactly as §4 requires. `pair.sh reset` DROPs
# and CREATEs both databases, clears both site-repo roots IN PLACE (preserving
# the two bind-root inodes the containers hold open) and removes the origin, so
# the following `up` reinstalls both sides unconditionally.
scenario_pair() {
  local scenario="$1"; shift
  say "$scenario — fresh pair '$PAIR' on :$PORT1/:$PORT2"
  if ! dry; then
    validate_artifact_lock "$SANDBOX/conformance/artifacts.lock.json" \
      || fail "artifact lock is malformed; the walk refused before pair reset"
    docker build -q -f init-cli.Dockerfile -t "$DUO_CLI_IMAGE" . >/dev/null \
      || fail "could not build the Git-enabled cli image $DUO_CLI_IMAGE from sandbox/init-cli.Dockerfile"
  else
    plan "docker build -q -f init-cli.Dockerfile -t $DUO_CLI_IMAGE .   # wordpress:cli-php8.3 + git"
  fi
  PAIR_UP=1
  run bash bin/pair.sh reset "$PAIR"
  run bash bin/pair.sh up "$PAIR" "$PORT1" "$PORT2" "${PAIR_UP_FLAGS[@]}"
  install_side 1 author "$@"
  install_side 2 target "$@"
  # WPForms Lite creates its own tables on activation, and only side 1
  # activates it — so side 2 gets them when the release's lifecycle phase
  # activates the plugin there, which is the ordering the walk wants anyway.
  run git init --bare -b main "$ORIGIN"
  pass "$scenario — pair up; woocommerce $WOO_VERSION + $THEME_SLUG $THEME_VERSION on both sides"
}

# write_registry — the two pair sides plus the preview environment the provider
# drives. `preview` and `${PAIR}2` are the SAME physical side (side 2 is both
# the rehearsal preview and the release target); they are two registry entries
# because only one of them carries the privileged `environment_provider` block.
write_registry() {
  say "registry — .duo-envs.json for ${PAIR}1, ${PAIR}2 and preview"
  if dry; then
    plan "write $ENVS_FILE (docker cli1/cli2; preview and ${PAIR}1 carry environment_provider -> php $PROVIDER $PROVIDER_CONFIG)"
    plan "write $PROVIDER_CONFIG (duo-reference-env-provider-config/v1, pair $PAIR)"
    return 0
  fi
  walk_write_registry "$ENVS_FILE" "${COMPOSE_FILES[0]}" "${PAIR}1" "${PAIR}2" \
    "$PHP_BIN" "$PROVIDER" "$PROVIDER_CONFIG"
  walk_validate_registry "$ENVS_FILE" "${PAIR}1" \
    || fail "the .duo-envs.json this walk wrote is not loadable as a machine-local provider registry"
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
  printf '{"action":"capabilities","environment":"preview","format":"duo-branch-environment-provider-request/v1","input":[],"operation_id":"20260817-090000-0000000000000000abcdefab"}\n' \
    | php "$PROVIDER" --print-plan "$PROVIDER_CONFIG" > "$EVIDENCE/provider-plan.json" \
    || fail "the reference provider refused the config this walk wrote; see $EVIDENCE/provider-plan.json"
  jq -e '
    .format == "duo-reference-env-provider-plan/v1" and .executed == false
    and ([.capabilities_advertised[]] | index("repository.materialize"))
    and ([.capabilities_advertised[]] | index("environment.attach"))
    and ([.capabilities_advertised[]] | index("snapshot.set.restore"))
  ' "$EVIDENCE/provider-plan.json" >/dev/null \
    || fail "the provider config validates but does not advertise the capability set a rehearsal needs"
  pass "provider config validates against the provider's own schema"
}

# seed_shop <landing-slug> — the ordinary shop content every scenario carries,
# so the site under test is a real site rather than one plugin on an empty
# install. Sets PRODUCT_ID and LANDING_ID.
PRODUCT_ID=""
LANDING_ID=""
seed_shop() {
  local slug="$1"
  say "seed — a small catalog and a landing page on ${PAIR}1"
  if dry; then
    plan "wp1 wc product create --name='Duo walk mug' --regular_price=24.00 ... (x2)"
    plan "wp1 post create --post_type=page --post_name=$slug ..."
    PRODUCT_ID='<product-id>'; LANDING_ID='<landing-id>'
    return 0
  fi
  PRODUCT_ID="$(wp1 wc product create --name='Duo walk mug' --type=simple \
    --regular_price=24.00 --sku=DUO-WALK-MUG --status=publish --user=admin --porcelain | tr -d '\r')"
  wp1 wc product create --name='Duo walk pin' --type=simple \
    --regular_price=8.00 --sku=DUO-WALK-PIN --status=publish --user=admin --porcelain >/dev/null
  LANDING_ID="$(wp1 post create --post_type=page --post_status=publish \
    --post_title='Duo walk landing page' --post_name="$slug" \
    --post_content='<p>Duo walk landing page, before the release.</p>' --porcelain | tr -d '\r')"
  [ -n "$PRODUCT_ID" ] && [ -n "$LANDING_ID" ] \
    || fail "the shop seed did not produce a product and a page on ${PAIR}1"
  # The journey URL is the page's own permalink PATH, read from the target as
  # grind_mup does: WooCommerce turns pretty permalinks on, and WordPress then
  # answers `/?page_id=N` with a 301 to the pretty form — run 9's verify read
  # `expected HTTP 200, got 301` on a journey the walk had spelled by query.
  LANDING_PATH="/?page_id=$LANDING_ID"
  local url path
  url="$(wp1 post list --post_type=page --name="$slug" --field=url | tr -d '\r' | head -1)"
  # The site URL is whatever WordPress was installed with (pair.sh uses
  # http://localhost:<port>); the journey is probed on 127.0.0.1:<port>, so
  # only the PATH travels — strip the scheme and host, whatever they were.
  path="$(printf '%s' "$url" | sed -E 's#^https?://[^/]+##')"
  case "$path" in
    /*) LANDING_PATH="$path" ;;
    *) note "the landing page URL '$url' has no path; probing by query string instead" ;;
  esac
  return 0
}
LANDING_PATH=""

# seed_repository <scenario>
#
# The adoption-seed site repository, written by hand before any `duo init`.
#
# S2 and S3 need a site repository to EXIST before init runs, because
# `duo coverage <env>`, `duo adapter-draft <site-repo>`, `duo manifest-validate
# --site` and `duo adapter certify <site-repo>` all resolve a directory holding
# `site.duo.json`, and the contract's §4 orders every one of them BEFORE the
# init they then expect to succeed. The bytes below are exactly
# `InitPlanner::existing_config()`'s `adoption-seed` shape — the product's own
# named seam for a repository that predates init — so seeding it is not a way
# around init's `existing_configuration` blocker but the case that blocker
# explicitly does not fire on. `MUP_BOOTSTRAP=manual` in grind_mup.sh is the
# same idea for the same reason.
#
# No capture runs before init in either scenario: a ledger row would make the
# environment initialized in fact, which is a different blocker with a
# different meaning.
seed_repository() {
  local scenario="$1"
  say "$scenario — seed the site repository (the adoption-seed site.duo.json init recognises)"
  if dry; then
    plan "write $HOST_R1/site.duo.json (adoption seed: manifests [core], core policy scope, spec_version 2)"
    plan "cp $SANDBOX/site-repo.gitignore.template $HOST_R1/.gitignore"
    plan "git -C $HOST_R1 init -q -b main"
    return 0
  fi
  jq -n '{
    manifests: ["core"],
    policy: {options: {}, post_meta: {}, term_meta: {},
             post_types: ["post", "page", "attachment"],
             taxonomies: ["category", "post_tag"]},
    spec_version: 2
  }' > "$HOST_R1/site.duo.json"
  cp site-repo.gitignore.template "$HOST_R1/.gitignore"
  git init -q -b main "$HOST_R1"
  pass "$scenario — adoption-seed repository in place at $HOST_R1"
}

# baseline_commit <scenario> — publish the initialized repository to the origin
# and prove the managed scope is clean.
baseline_commit() {
  local scenario="$1"
  if dry || [ ! -d "$HOST_R1/.git" ]; then
    git1 init -q -b main
  fi
  git1 remote add origin "$ORIGIN"
  git1 add -A
  commit1 "grind_adapter_walk $scenario: baseline capture of ${PAIR}1"
  git1 push -qu origin main
  duo_ok "$EVIDENCE/$scenario/status-1.txt" "$HOST_R1" status "${PAIR}1"
  if ! dry; then
    grep -q ', 0 conflict, 0 collision,' "$EVIDENCE/$scenario/status-1.txt" \
      || fail "$scenario: duo status ${PAIR}1 is not clean after the baseline; see $EVIDENCE/$scenario/status-1.txt"
  fi
  pass "$scenario — baseline committed on main; duo status ${PAIR}1 is clean"
}

# assess_both <scenario> <env> <cwd> <label> — the JSON document and the human
# view, with the document checks and the §5.2 leak gate applied to each. Sets
# ASSESS_JSON to the document path.
ASSESS_JSON=""
assess_both() {
  local scenario="$1" env="$2" dir="$3" label="$4"
  local raw="$SCRATCH/$scenario-assess-$label.raw"
  ASSESS_JSON="$EVIDENCE/$scenario/assess-$label.json"
  local human="$EVIDENCE/$scenario/assess-$label.txt"
  duo_ok "$raw" "$dir" assess "$env" --format=json
  duo_ok "$human" "$dir" assess "$env"
  if dry; then return 0; fi
  walk_json_tail "$raw" > "$ASSESS_JSON"
  walk_assert_assess_document "$ASSESS_JSON" \
    || fail "$scenario: the assess document for $label is not usable"
  walk_assert_no_internal_ids "$human" "duo assess $env" \
    || fail "$scenario: MUP §5.2 — the human assess view printed an identifier no documented command consumes"
  return 0
}

# contract_cycle <scenario> <env> <cwd> <landing-id> <extra-journey-json|-> [<jq-review-filter>]
#
# propose -> review -> accept. The review is the real one §3.4 requires a human
# for: the generated `code-lifecycle-window` entry arrives `decided_by:
# unresolved` and `ApplicationContract::validate()` refuses to accept it that
# way (external_effect_unreviewed). The scenario supplies any extra surface
# review as a jq filter, which is how S1 records §3.6's operator decision for
# the unmanaged plugin.
CONTRACT_DIGEST=""
contract_cycle() {
  local scenario="$1" env="$2" dir="$3" landing="$4" extraJourney="$5" surfaceFilter="${6:-.}"
  local proposed="$dir/.duo/contract/proposed.json"
  local contract="$dir/.duo/contract/contract.json"
  say "$scenario — duo contract $env propose -> review -> accept"
  duo_ok "$EVIDENCE/$scenario/contract-propose.txt" "$dir" contract "$env" propose
  if dry; then
    plan "jq: review .duo/contract/proposed.json — code-lifecycle-window live/provider-state restorable/operator; journeys <landing permalink path> and /?post_type=product$([ "$extraJourney" = '-' ] || printf ' (+1 scenario journey)')"
    plan "jq: review every surface this scenario decides (§3.6)"
    CONTRACT_DIGEST='<contract-digest>'
  else
    [ -f "$proposed" ] || fail "$scenario: duo contract propose wrote no $proposed"
    local journeys
    journeys="$(jq -n --arg landing "${LANDING_PATH:-/?page_id=$landing}" '
      [
        {id: "landing-page", url: $landing, expect_status: 200,
         expect_contains: "Duo walk landing page", affected_surfaces: ["post_type:page"]},
        {id: "catalog-index", url: "/?post_type=product", expect_status: 200,
         expect_contains: "Duo walk mug", affected_surfaces: ["post_type:product"]}
      ]')"
    if [ "$extraJourney" != '-' ]; then
      journeys="$(jq -c --argjson extra "$extraJourney" '. + [$extra]' <<<"$journeys")"
    fi
    jq --argjson journeys "$journeys" \
       --arg reason "the only external effect this site's installed set has in the lifecycle window is WordPress' own activation/deactivation hooks; reviewed against woocommerce $WOO_VERSION, $WPFORMS_SLUG $WPFORMS_VERSION and $THEME_SLUG $THEME_VERSION for this walk, none of which run mail, payment or webhook code on activation" '
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
      | .contract.declarations.journeys = $journeys
    ' "$proposed" > "$proposed.reviewed" \
      || fail "$scenario: could not review the proposed contract"
    jq "$surfaceFilter" "$proposed.reviewed" > "$proposed.surfaces" \
      || fail "$scenario: the scenario's own surface review filter failed"
    mv "$proposed.surfaces" "$proposed"
    rm -f "$proposed.reviewed"
    jq -e '(.contract.declarations.external_effects | length) == 1
      and .contract.declarations.external_effects[0].decided_by == "operator"
      and (.contract.declarations.journeys | length) >= 2' "$proposed" >/dev/null \
      || fail "$scenario: the reviewed proposal does not carry the declared lifecycle window and its journeys"
  fi
  duo_ok "$EVIDENCE/$scenario/contract-accept.txt" "$dir" contract "$env" accept
  if ! dry; then
    [ -f "$contract" ] || fail "$scenario: accept wrote no $contract"
    jq -e '.attestation.state == "unsigned"' "$contract" >/dev/null \
      || fail "$scenario: this profile may only ever write attestation.state unsigned (§3.6)"
    duo_ok "$SCRATCH/$scenario-show-a.raw" "$dir" contract "$env" show --format=json
    duo_ok "$SCRATCH/$scenario-show-b.raw" "$dir" contract "$env" show --format=json
    local a b
    a="$(walk_json_tail "$SCRATCH/$scenario-show-a.raw" | jq -r '.contract.contract_digest // .contract_digest')"
    b="$(walk_json_tail "$SCRATCH/$scenario-show-b.raw" | jq -r '.contract.contract_digest // .contract_digest')"
    [ -n "$a" ] && [ "$a" = "$b" ] \
      || fail "$scenario: contract_digest is not stable across two duo contract show runs ('$a' vs '$b')"
    CONTRACT_DIGEST="$a"
  fi
  git1 add -A
  commit1 "grind_adapter_walk $scenario: accept the reviewed application contract"
  git1 push -q origin main
  pass "$scenario — contract accepted, digest stable, attestation unsigned ($CONTRACT_DIGEST)"
}

# release_cycle <scenario> <main-sha> — plan-only, release, verify. Sets
# PLAN_JSON, PLAN_BOUNDARY and RELEASE_OUT for the recovery step.
PLAN_JSON=""
PLAN_BOUNDARY=""
RELEASE_OUT=""
release_cycle() {
  local scenario="$1" sha="$2"
  PLAN_JSON="$EVIDENCE/$scenario/authorization-plan.json"
  RELEASE_OUT="$EVIDENCE/$scenario/release.txt"
  say "$scenario — duo release ${PAIR}2 --from=$sha --plan-only, then --yes"
  duo_ok "$SCRATCH/$scenario-plan-only.raw" "$HOST_R2" release "${PAIR}2" --from="$sha" --plan-only --format=json
  if dry; then
    PLAN_BOUNDARY='writes committed after checkpoint <ts>'
  else
    walk_json_tail "$SCRATCH/$scenario-plan-only.raw" > "$PLAN_JSON"
    walk_assert_plan_document "$PLAN_JSON" || fail "$scenario: the authorization plan is incomplete"
    walk_assert_claim_literal "$PLAN_JSON" '.recovery_profile.claim' \
      || fail "$scenario: the plan's embedded recovery claim is not literal"
    [ "$(jq -r '.contract_digest' "$PLAN_JSON")" = "$CONTRACT_DIGEST" ] \
      || fail "$scenario: the plan cites a contract digest that is not the accepted one"
    local updates
    updates="$(jq -r '.scope.entities.update + .scope.entities.create' "$PLAN_JSON")"
    [ "$updates" -gt 0 ] \
      || fail "$scenario: the frozen plan authorizes no entity change, so the release and recovery would prove nothing"
    [ ! -d "$HOST_R2/.duo/releases" ] || [ -z "$(ls -A "$HOST_R2/.duo/releases" 2>/dev/null)" ] \
      || fail "$scenario: --plan-only froze a plan to disk"
    PLAN_BOUNDARY="$(walk_claim_boundary "$PLAN_JSON" '.recovery_profile.claim')"
    pass "$scenario — plan authorizes $updates change(s); boundary, verbatim: $PLAN_BOUNDARY"
  fi

  duo_ok "$RELEASE_OUT" "$HOST_R2" release "${PAIR}2" --from="$sha" --yes
  if ! dry; then
    grep -Fq 'authorization frozen: ' "$RELEASE_OUT" \
      || fail "$scenario: the release printed no frozen-plan path, so nothing was durably bound before mutation"
    walk_assert_phase_order "$RELEASE_OUT" \
      'promote phase: promotion-begin' \
      'promote phase: checkpoint' \
      'promote phase: lifecycle-retire' \
      'promote phase: lifecycle-activate' \
      'promote phase: apply' \
      || fail "$scenario: deploy-before-apply ordering is not observable in the release receipts"
    [ -n "$(ls -A "$HOST_R2/.duo/releases" 2>/dev/null)" ] \
      || fail "$scenario: the release left no frozen plan in .duo/releases"
  fi
  pass "$scenario — released; the receipts show deploy before apply"

  say "$scenario — duo verify ${PAIR}2"
  duo_ok "$SCRATCH/$scenario-verify.raw" "$HOST_R2" verify "${PAIR}2" --format=json
  if ! dry; then
    walk_json_tail "$SCRATCH/$scenario-verify.raw" > "$EVIDENCE/$scenario/verify.json"
    walk_assert_verify_report "$EVIDENCE/$scenario/verify.json" \
      || fail "$scenario: the verify report is not a pass"
    note "uncovered surfaces: $(jq -r '.uncovered_surfaces | join(", ") | if . == "" then "(none)" else . end' "$EVIDENCE/$scenario/verify.json")"
  fi
  pass "$scenario — convergence pass, every declared journey passes"

  # The pre-recovery truth, recorded on the TARGET before anything is written
  # after the checkpoint. post_recovery_check compares against exactly this, on
  # exactly the surfaces the frozen plan named in scope — two assessments of
  # one environment, which is the only comparison that means anything.
  duo_ok "$SCRATCH/$scenario-assess-pre-recovery.raw" "$HOST_R2" assess "${PAIR}2" --format=json
  if ! dry; then
    walk_json_tail "$SCRATCH/$scenario-assess-pre-recovery.raw" > "$EVIDENCE/$scenario/assess-pre-recovery.json"
    mapfile -t SCOPE_SURFACES < <(jq -r '.scope.surfaces[]' "$PLAN_JSON")
    [ "${#SCOPE_SURFACES[@]}" -gt 0 ] \
      || fail "$scenario: the frozen plan names no surfaces in scope, so there is nothing to compare after recovery"
    PRE_RECOVERY_SUBSET="$(walk_projection_subset "$EVIDENCE/$scenario/assess-pre-recovery.json" "${SCOPE_SURFACES[@]}")"
  fi
}

# post_recovery_check <scenario> — the projection of every surface the frozen
# plan named in scope is byte-identical before the release and after recovery.
# Only the projected words are compared: a digest or timestamp differing
# between two assessments of an unchanged site is the clock moving, not the
# site moving.
SCOPE_SURFACES=()
PRE_RECOVERY_SUBSET=""
post_recovery_check() {
  local scenario="$1"
  assess_both "$scenario" "${PAIR}2" "$HOST_R2" post-recovery
  if dry; then return 0; fi
  local after
  after="$(walk_projection_subset "$ASSESS_JSON" "${SCOPE_SURFACES[@]}")"
  if [ "$PRE_RECOVERY_SUBSET" != "$after" ]; then
    printf 'before: %s\nafter:  %s\n' "$PRE_RECOVERY_SUBSET" "$after" >&2
    fail "$scenario: the post-recovery projection differs from the pre-release projection for the surfaces in scope"
  fi
  pass "$scenario — the projection for ${#SCOPE_SURFACES[@]} in-scope surface(s) is unchanged by the release and recovery"
}

# recover_cycle <scenario> — list the checkpoint the release just took, restore
# it, and hold the printed boundary to the letter. Sets RECEIPT_ID.
RECEIPT_ID=""
recover_cycle() {
  local scenario="$1"
  say "$scenario — duo recover ${PAIR}2 --list, then --restore --writers-excluded"
  duo_ok "$SCRATCH/$scenario-recover-list.raw" "$HOST_R2" recover "${PAIR}2" --list --format=json
  if dry; then
    RECEIPT_ID='<receipt-id>'
    plan "(cd $HOST_R2 && php $DUO --envs-file=$ENVS_FILE recover ${PAIR}2 --restore=<receipt-id> --writers-excluded)"
    return 0
  fi
  walk_json_tail "$SCRATCH/$scenario-recover-list.raw" > "$EVIDENCE/$scenario/checkpoint-catalog.json" 2>/dev/null \
    || cp "$SCRATCH/$scenario-recover-list.raw" "$EVIDENCE/$scenario/checkpoint-catalog.json"
  RECEIPT_ID="$(walk_checkpoint_id "$EVIDENCE/$scenario/checkpoint-catalog.json")" \
    || fail "$scenario: the checkpoint catalog names no receipt to restore"
  local retainedLine retainedId
  retainedLine="$(grep -F 'database checkpoint retained: ' "$RELEASE_OUT" | tail -1 || true)"
  if [ -n "$retainedLine" ]; then
    retainedId="$(basename "${retainedLine#*database checkpoint retained: }" .sql | tr -d '\r')"
    [ "$retainedId" = "$RECEIPT_ID" ] \
      || fail "$scenario: the catalog lists '$RECEIPT_ID' first, but the release retained '$retainedId'"
  fi
  duo_ok "$EVIDENCE/$scenario/recover.txt" "$HOST_R2" recover "${PAIR}2" --restore="$RECEIPT_ID" --writers-excluded

  # The claim is printed BEFORE acting, or the operator read it too late to
  # stop. `recovery profile:` is the claim's first line
  # (RecoveryClaim::humanLines()); the step regex is anchored to the exact
  # shape RecoverCommand prints its driven steps in, because the claim's own
  # does-not-restore prose contains the words "import" and "abort".
  local claimLine actionLine
  claimLine="$(grep -n -m1 -E '^recovery profile: ' "$EVIDENCE/$scenario/recover.txt" | cut -d: -f1 || true)"
  actionLine="$(grep -n -m1 -E '^  (abort|begin|import|final-abort|signed-rollback): ' "$EVIDENCE/$scenario/recover.txt" | cut -d: -f1 || true)"
  [ -n "$claimLine" ] || fail "$scenario: duo recover printed no recovery claim"
  if [ -n "$actionLine" ]; then
    [ "$claimLine" -lt "$actionLine" ] \
      || fail "$scenario: the recovery claim was printed after recovery started"
  fi
  grep -Fq "$PLAN_BOUNDARY" "$EVIDENCE/$scenario/recover.txt" \
    || fail "$scenario: the boundary printed at recovery is not the one the frozen plan printed.
plan:     $PLAN_BOUNDARY
recovery: $(grep -i 'maximum loss' "$EVIDENCE/$scenario/recover.txt" || echo '(not printed)')"
  local expectation
  expectation="$(walk_boundary_expectation "$PLAN_BOUNDARY")" \
    || fail "$scenario: the printed boundary is not one this walk can read literally"
  [ "$expectation" = lost ] \
    || fail "$scenario: the release selected a profile whose boundary bounds nothing; this walk never authorizes that"
  pass "$scenario — recovery restored checkpoint $RECEIPT_ID and printed the plan's own boundary"
}

# reap_cycle <scenario> — reap the preview twice; the second must be idempotent.
reap_cycle() {
  local scenario="$1"
  say "$scenario — duo rehearse preview --reap, then again"
  duo_ok "$EVIDENCE/$scenario/reap-1.txt" "$HOST_R1" rehearse preview --reap
  duo_ok "$EVIDENCE/$scenario/reap-2.txt" "$HOST_R1" rehearse preview --reap
  if ! dry; then
    grep -Eq 'destroyed|detached' "$EVIDENCE/$scenario/reap-1.txt" \
      || fail "$scenario: the first reap receipt says neither destroyed nor detached"
    grep -Eq 'destroyed|detached' "$EVIDENCE/$scenario/reap-2.txt" \
      || fail "$scenario: the repeated reap printed no receipt"
  fi
  pass "$scenario — reap is idempotent"
}

# rehearse_preview <scenario> — materialize side 2 from side 1's main.
rehearse_preview() {
  local scenario="$1"
  say "$scenario — duo rehearse preview --from ${PAIR}1 --branch main"
  local out="$EVIDENCE/$scenario/rehearse.txt"
  local banner='containment: unknown — not enforced in this profile; do not point this environment at live payment or mail credentials.'
  duo_ok "$out" "$HOST_R1" rehearse preview --from "${PAIR}1" --branch main
  if ! dry; then
    [ "$(head -n 1 "$out")" = "$banner" ] \
      || fail "$scenario: the containment banner is not the first line of the rehearsal report; see $out"
    [ "$(grep -cF "$banner" "$out")" = 1 ] \
      || fail "$scenario: the containment banner is printed more than once"
    grep -Fq 'what a release would touch' "$out" \
      || fail "$scenario: the rehearsal printed no 'what a release would touch' preview"
    [ -f "$HOST_R2/site.duo.json" ] \
      || fail "$scenario: the preview converged but $HOST_R2 carries no materialized site repository"
  fi
  pass "$scenario — preview converged; banner present and printed once"
}

# capture_twice <scenario> <env> — capture, then capture again to a scratch
# tree, and require zero bytes of difference.
capture_twice() {
  local scenario="$1" env="$2"
  duo_ok "$EVIDENCE/$scenario/capture-preview.txt" "$HOST_R2" capture "$env"
  duo_ok "$EVIDENCE/$scenario/capture-preview-2.txt" "$HOST_R2" capture "$env" --out=/siterepo/.tmp-state2
  if ! dry; then
    diff -r "$HOST_R2/state" "$HOST_R2/.tmp-state2" \
      || fail "$scenario: capture is not deterministic on the preview"
    rm -rf "$HOST_R2/.tmp-state2"
  fi
  pass "$scenario — capture twice on the preview is byte-identical"
}

# merge_preview <scenario> — commit the preview's capture and merge it to main
# in the origin. Sets MAIN_SHA.
MAIN_SHA=""
merge_preview() {
  local scenario="$1"
  git2 add -A
  commit2 "grind_adapter_walk $scenario: authored edit captured from the preview"
  git2 push -q origin HEAD:main
  if dry; then
    dry_set MAIN_SHA '<main-sha>'
    plan "git -C $ORIGIN rev-parse main"
  else
    MAIN_SHA="$(git -C "$ORIGIN" rev-parse main)"
    [ -n "$MAIN_SHA" ] || fail "$scenario: the origin has no main revision after the merge"
  fi
  pass "$scenario — origin main is $MAIN_SHA"
}

# revert_target <scenario> <landing-id> <pre-body>
#
# Copied from grind_mup's step 7b, and here for the same reason: side 2 is both
# the preview and the release target, so an edit authored on the preview is
# already live on the target the moment it is captured — and a release with
# nothing to apply cannot prove that recovery restored anything. The revert
# alone is not enough: the target's identity ledger recorded the EDITED value
# at capture, so reverting the live row underneath it reads as `drift` and a
# drifted target is refused (`release_target_not_clean`). So the revert is
# followed by ONE capture on the target — the ledger now records the
# pre-release value, which is the capture-first workflow the refusal names —
# and that capture's working tree is discarded back to main, which carries the
# edit.
revert_target() {
  local scenario="$1" landing="$2" body="$3"
  say "$scenario — return the target's live page body to its pre-release value"
  if dry; then
    plan "wp2 post update <landing> --post_content=$(printf '%q' "$body")"
    plan "(cd $HOST_R2 && php $DUO --envs-file=$ENVS_FILE capture ${PAIR}2)   # ledger records the pre-release value"
    plan "git -C $HOST_R2 checkout -- . && git -C $HOST_R2 clean -fd"
    return 0
  fi
  wp2 post update "$landing" --post_content="$body"
  [ "$(wp2 post get "$landing" --field=post_content | tr -d '\r')" = "$body" ] \
    || fail "$scenario: the live page-body revert did not take"
  duo_ok "$SCRATCH/$scenario-capture-revert.txt" "$HOST_R2" capture "${PAIR}2"
  git2 checkout -q -- .
  git2 clean -fdq
  [ -z "$(git -C "$HOST_R2" status --porcelain)" ] \
    || fail "$scenario: the target clone is not back at main after discarding the ledger-updating capture"
  pass "$scenario — the target's live page body is back at its pre-release value, and its ledger knows"
}

# preview_page_edit <scenario> <slug> <new-body> — the authored edit every
# scenario releases. Sets PREVIEW_PAGE_ID.
PREVIEW_PAGE_ID=""
preview_page_edit() {
  local scenario="$1" slug="$2" body="$3"
  if dry; then
    PREVIEW_PAGE_ID='<preview-page-id>'
    plan "wp2 post update <page> --post_content=$(printf '%q' "$body")"
    return 0
  fi
  PREVIEW_PAGE_ID="$(wp2 post list --post_type=page --name="$slug" --field=ID | tr -d '\r' | head -1)"
  [ -n "$PREVIEW_PAGE_ID" ] || fail "$scenario: the preview carries no '$slug' page to edit"
  wp2 post update "$PREVIEW_PAGE_ID" --post_content="$body"
  return 0
}

# keygen_and_certify <scenario> <name> — §3.5's two host verbs, plus the two
# §3.1 facts about where a private key may live and what a certificate binds.
keygen_and_certify() {
  local scenario="$1" name="$2"
  local key="$KEYDIR/$name.key"
  say "$scenario — duo adapter keygen, then duo adapter certify --pin"
  # §3.1: `duo adapter keygen` refuses a path inside the site repository. That
  # refusal is asserted before the real key is made, because a key the walk
  # accidentally committed would be a key in a git history forever.
  duo_refused "$EVIDENCE/$scenario/keygen-refused.txt" secret_key_inside_repository "$HOST_R1" \
    adapter keygen --out="$HOST_R1/$name.key" --key-id="$WALK_KEY_ID"
  duo_ok "$EVIDENCE/$scenario/keygen.txt" "$HOST_R1" \
    adapter keygen --out="$key" --key-id="$WALK_KEY_ID"
  if ! dry; then
    grep -Eq "^key-id:[[:space:]]+$WALK_KEY_ID\$" "$EVIDENCE/$scenario/keygen.txt" \
      || fail "$scenario: duo adapter keygen printed no 'key-id: $WALK_KEY_ID' line; see $EVIDENCE/$scenario/keygen.txt"
    [ -f "$key" ] || fail "$scenario: duo adapter keygen wrote no secret key at $key"
    [ "$(stat -f '%Lp' "$key" 2>/dev/null || stat -c '%a' "$key")" = 600 ] \
      || fail "$scenario: the secret key is not mode 0600 (§3.5)"
  fi
  duo_ok "$EVIDENCE/$scenario/certify.txt" "$HOST_R1" \
    adapter certify "$HOST_R1" --name="$name" --secret-key-file="$key" \
    --key-id="$WALK_KEY_ID" \
    --reason="round-3 T6 adapter walk $scenario: the operator authored this adapter against the installed plugin and reviewed every rule it declares" \
    --pin
  if ! dry; then
    [ -f "$HOST_R1/adapters/authorities.json" ] \
      || fail "$scenario: certify registered no site trust root at adapters/authorities.json (§3.1)"
    jq -e --arg id "$WALK_KEY_ID" --arg n "$name" '
      .format == "duo-adapter-authorities/v1"
      and (.keys[$id].scope == "site_adapter_certification")
      and (.keys[$id].status == "trusted")
      and ([.keys[$id].adapter_names[]] | index($n) != null)
    ' "$HOST_R1/adapters/authorities.json" >/dev/null \
      || fail "$scenario: adapters/authorities.json is not a duo-adapter-authorities/v1 record trusting $WALK_KEY_ID for $name (§3.1)"
    [ -f "$HOST_R1/adapters/certifications/$name.json" ] \
      || fail "$scenario: certify wrote no adapters/certifications/$name.json (§3.1)"
    jq -e --arg n "$name" '
      [.manifests[] | select(type == "object" and .name == $n and .source == "site" and (.digest | type == "string"))] | length == 1
    ' "$HOST_R1/site.duo.json" >/dev/null \
      || fail "$scenario: --pin did not write the exact {name,source:\"site\",digest} pin into site.duo.json (§3.1)"
    # §3.1's other half: the private key never enters the repository. Scoped to
    # the three paths certification writes — a recursive grep over the whole
    # repository would also walk the code half's tens of thousands of files for
    # no additional guarantee.
    [ ! -e "$HOST_R1/$name.key" ] || fail "$scenario: a secret key is sitting in the site repository"
    local secretHead
    secretHead="$(head -c 40 "$key")"
    [ -n "$secretHead" ] || fail "$scenario: the generated secret key file is empty"
    ! grep -RIqF -- "$secretHead" \
        "$HOST_R1/site.duo.json" "$HOST_R1/adapters" 2>/dev/null \
      || fail "$scenario: secret key bytes reached site.duo.json or adapters/ — §3.1 says private keys never live in the repository"
  fi
  pass "$scenario — $name certified under site key $WALK_KEY_ID and pinned {name,source:site,digest}"
}

# adapter_catalog <scenario> <label> — `duo adapter list --repo` in both
# formats. Sets CATALOG_JSON.
CATALOG_JSON=""
adapter_catalog() {
  local scenario="$1" label="$2"
  local raw="$SCRATCH/$scenario-adapter-list-$label.raw"
  CATALOG_JSON="$EVIDENCE/$scenario/adapter-list-$label.json"
  duo_ok "$raw" "$HOST_R1" adapter list --repo="$HOST_R1" --format=json
  duo_ok "$EVIDENCE/$scenario/adapter-list-$label.txt" "$HOST_R1" adapter list --repo="$HOST_R1"
  # `AdapterCatalog::encode()` is JSON_PRETTY_PRINT, so the document's opening
  # brace is alone at column 0 and the tail extractor finds it even if anything
  # ahead of it wrote a line. Reading the evidence file with a bare `jq .` would
  # make one stray warning look like a catalog defect.
  if ! dry; then
    walk_json_tail "$raw" > "$CATALOG_JSON"
  fi
  return 0
}

# ---------------------------------------------------------------------------
# S1 — a published plugin with no adapter, kept deliberately unmanaged.
#
# What it must prove (§4): init refuses by name and proceeds with the new flag;
# capture refuses `incomplete_policy_scope` and goes green after one reviewed
# classification; assess names the plugin, its tables and its option prefix and
# counts them; the contract decides the plugin runtime/preserve local; the loop
# runs to completion with the plugin excluded; and the plugin's own unmanaged
# rows — a custom-table row and an option written AFTER the checkpoint — are
# restored by the database checkpoint exactly as the printed boundary says.
# That last one is the point: the checkpoint boundary is literal for state Duo
# does not manage, or "excluded" would mean "unprotected".
# ---------------------------------------------------------------------------
scenario_s1() {
  local S=S1
  run mkdir -p "$EVIDENCE/$S"
  scenario_pair "$S" with-subject
  write_registry
  seed_shop duo-walk-landing

  say "$S — two forms on ${PAIR}1, and the plugin's own tables"
  if dry; then
    plan "wp1 post create --post_type=$WPFORMS_CPT --post_content='<form json>' (x2)"
  else
    wp1 post create --post_type="$WPFORMS_CPT" --post_status=publish \
      --post_title='Duo walk contact form' --post_name=duo-walk-contact \
      --post_content='{"id":"1","settings":{"form_title":"Duo walk contact form"},"fields":{"1":{"id":"1","type":"email","label":"Email"}}}' \
      --porcelain >/dev/null
    wp1 post create --post_type="$WPFORMS_CPT" --post_status=publish \
      --post_title='Duo walk feedback form' --post_name=duo-walk-feedback \
      --post_content='{"id":"2","settings":{"form_title":"Duo walk feedback form"},"fields":{"1":{"id":"1","type":"textarea","label":"Notes"}}}' \
      --porcelain >/dev/null
  fi

  say "$S — duo init ${PAIR}1 refuses an active plugin no adapter declares"
  duo_refused "$EVIDENCE/$S/init-refused.txt" active_plugin_without_adapter "$HOST_R1" init "${PAIR}1" --yes
  if ! dry; then
    walk_assert_init_line "$EVIDENCE/$S/init-refused.txt" 'UNSUPPORTED PLUGIN' "$WPFORMS_BASENAME" active_plugin_without_adapter \
      || fail "$S: the init refusal does not name $WPFORMS_BASENAME with its typed code"
    grep -Fq -- '--allow-unmanaged-plugins' "$EVIDENCE/$S/init-refused.txt" \
      || fail "$S: §3.4 — the refusal's remediation must now name --allow-unmanaged-plugins"
    grep -Fq 'duo adapter certify' "$EVIDENCE/$S/init-refused.txt" \
      || fail "$S: §3.4 — the refusal's remediation must now name duo adapter certify"
  fi
  pass "$S — init refused by name and named both remedies"

  say "$S — duo init ${PAIR}1 --allow-unmanaged-plugins --yes"
  duo_ok "$EVIDENCE/$S/init.txt" "$HOST_R1" init "${PAIR}1" --allow-unmanaged-plugins --yes
  if ! dry; then
    walk_assert_init_line "$EVIDENCE/$S/init.txt" 'UNMANAGED PLUGIN' "$WPFORMS_BASENAME" active_plugin_without_adapter \
      || fail "$S: §3.4 — --allow-unmanaged-plugins must print UNMANAGED PLUGIN $WPFORMS_BASENAME [active_plugin_without_adapter]"
    # The decision carries through to the plugin's registered types: init's
    # own confirmation runs the baseline capture, whose scope gate refuses any
    # plugin-registered type with rows that no rule names, so "leave the
    # plugin unmanaged" means "its types stay local" — the same
    # `scope:post_type:<name>=runtime` rule `duo classify` would write, printed
    # as an advisory rather than taken silently. The forms CPT must NOT have
    # been taken into authored scope.
    walk_assert_init_line "$EVIDENCE/$S/init.txt" 'UNMANAGED SCOPE' "post_type:$WPFORMS_CPT" unmanaged_scope_left_local \
      || fail "$S: init did not print UNMANAGED SCOPE post_type:$WPFORMS_CPT [unmanaged_scope_left_local] for the plugin's forms type"
    jq -e --arg t "$WPFORMS_CPT" '[.policy.post_types[]] | index($t) == null' "$HOST_R1/site.duo.json" >/dev/null \
      || fail "$S: init took the unmanaged plugin's post type into authored policy scope"
    jq -e --arg t "$WPFORMS_CPT" '.policy.scope.post_type[$t].class == "runtime"' "$HOST_R1/site.duo.json" >/dev/null \
      || fail "$S: site.duo.json does not record policy.scope.post_type.$WPFORMS_CPT.class = runtime after --allow-unmanaged-plugins"
  fi
  baseline_commit "$S"

  say "$S — duo capture ${PAIR}1 is green, and the review queue holds no scope gap"
  duo_ok "$EVIDENCE/$S/capture.txt" "$HOST_R1" capture "${PAIR}1"
  duo_ok "$EVIDENCE/$S/pending.txt" "$HOST_R1" pending "${PAIR}1" --format=json
  if ! dry; then
    grep -Fq "scope:post_type:$WPFORMS_CPT" "$EVIDENCE/$S/pending.txt" \
      && fail "$S: the review queue still carries scope:post_type:$WPFORMS_CPT after init decided it runtime"
    # The gate itself is proven live in the negative: a site.duo.json WITHOUT
    # that rule refuses capture by name. Take the rule away in a scratch copy
    # of the repository's policy and ask the target — nothing on the target
    # moves, and the refusal is the exact one an operator who removed the
    # rule by hand would read.
    local stripped="$SCRATCH/$S-site.duo.without-scope.json"
    jq --arg t "$WPFORMS_CPT" 'del(.policy.scope.post_type[$t]) | if (.policy.scope.post_type // {}) == {} then del(.policy.scope.post_type) else . end | if (.policy.scope // {}) == {} then del(.policy.scope) else . end' \
      "$HOST_R1/site.duo.json" > "$stripped"
    cp "$HOST_R1/site.duo.json" "$SCRATCH/$S-site.duo.keep.json"
    cp "$stripped" "$HOST_R1/site.duo.json"
    duo_refused "$EVIDENCE/$S/capture-refused.txt" incomplete_policy_scope "$HOST_R1" capture "${PAIR}1" --format=json
    grep -Fq "scope:post_type:$WPFORMS_CPT" "$EVIDENCE/$S/capture-refused.txt" \
      || fail "$S: the capture refusal does not name scope:post_type:$WPFORMS_CPT"
    cp "$SCRATCH/$S-site.duo.keep.json" "$HOST_R1/site.duo.json"
    duo_ok "$EVIDENCE/$S/capture-again.txt" "$HOST_R1" capture "${PAIR}1"
  fi
  pass "$S — capture is green with the reviewed rule and loud without it, naming the exact scope gap"

  say "$S — duo coverage ${PAIR}1 and duo assess ${PAIR}1"
  duo_ok "$SCRATCH/$S-coverage.raw" "$HOST_R1" coverage "${PAIR}1" --format=json
  if ! dry; then
    walk_agent_json "$SCRATCH/$S-coverage.raw" > "$EVIDENCE/$S/coverage.json"
    local tables
    tables="$(walk_coverage_undeclared "$EVIDENCE/$S/coverage.json" | grep -c "^$WPFORMS_OPTION_PREFIX" || true)"
    [ "$tables" -ge 1 ] \
      || fail "$S: §3.7 bug 1 — coverage publishes no logical_name for the plugin's undeclared tables, so assess can never mint a table: row"
    pass "$S — coverage publishes logical_name for $tables undeclared ${WPFORMS_OPTION_PREFIX}* table(s)"
  fi

  assess_both "$S" "${PAIR}1" "$HOST_R1" unmanaged
  if ! dry; then
    local expect got
    expect=$'unclassified\tblock\tNot qualified\tUncertified\tunknown\tunknown'
    got="$(walk_assess_projection "$ASSESS_JSON" "plugin:$WPFORMS_SLUG" release)" \
      || fail "$S: §3.6 — assess published no plugin:$WPFORMS_SLUG surface for the unmanaged active plugin"
    [ "$got" = "$expect" ] \
      || fail "$S: plugin:$WPFORMS_SLUG projected '$got', expected '$expect'"
    got="$(walk_assess_next_action "$ASSESS_JSON" "plugin:$WPFORMS_SLUG")"
    [ "$got" = 'install adapter' ] \
      || fail "$S: the plugin row's next action is '$got', not 'install adapter'"
    jq -e --arg p "table:$WPFORMS_OPTION_PREFIX" '[.surfaces[] | select(.id | startswith($p))] | length >= 1' \
      "$ASSESS_JSON" >/dev/null \
      || fail "$S: §3.7 bug 1 — assess published no table:${WPFORMS_OPTION_PREFIX}* rows"
    walk_assert_unknown_names "$ASSESS_JSON" "option-prefix:$WPFORMS_OPTION_FAMILY" \
      || fail "$S: the unknown block does not name option-prefix:$WPFORMS_OPTION_FAMILY"
    [ "$(jq -r '.unknown.invisible_names_count' "$ASSESS_JSON")" -gt 0 ] \
      || fail "$S: assess counted no invisible option names for an unmanaged plugin that writes dozens"
    walk_assert_unknown_table_line "$EVIDENCE/$S/assess-unmanaged.txt" \
      || fail "$S: §3.6/§3.7 bug 2 — the human unknown block prints no 'N undeclared table(s)' line"
    got="$(walk_gap_count "$EVIDENCE/$S/assess-unmanaged.txt" 'install adapter')"
    [ "$got" -ge 2 ] \
      || fail "$S: the next-actions roll-up counted $got 'install adapter' findings; the plugin and its tables are at least two"
    pass "$S — assess names the plugin, its tables and its option prefix, and counts them"
  fi

  say "$S — decide the unmanaged plugin in the contract: runtime / preserve local"
  # §3.6: the operator's decision for a `plugin:` surface is an ordinary one —
  # `decided_by: operator`, `state_class: runtime`, `handling: preserve local`
  # ("this plugin's state stays local; not branchable"), which projects
  # `Unsupported` and carries no next action. The plugin's own undeclared
  # tables get the same decision for the same reason: they are that plugin's
  # runtime state, and the site is choosing to keep it local.
  local surfaceFilter='
    .contract.declarations.surfaces = [
      .contract.declarations.surfaces[]
      | if (.id | startswith("plugin:")) or (.id | startswith("table:'"$WPFORMS_OPTION_PREFIX"'"))
        then . + {state_class: "runtime", handling: "preserve local",
                  decided_by: "operator", decided_at: "2026-08-17T09:03:44Z"}
             | del(.next_action)
        else . end
    ]'
  contract_cycle "$S" "${PAIR}1" "$HOST_R1" "$LANDING_ID" - "$surfaceFilter"
  if ! dry; then
    jq -e --arg id "plugin:$WPFORMS_SLUG" '
      (.declarations.surfaces[] | select(.id == $id))
      | .state_class == "runtime" and .handling == "preserve local"
        and .decided_by == "operator" and (has("next_action") | not)
    ' "$HOST_R1/.duo/contract/contract.json" >/dev/null \
      || fail "$S: §3.6 — the accepted contract does not carry the operator's runtime/preserve local decision for plugin:$WPFORMS_SLUG"
    # Every surface the walk did not deliberately decide must have been decided
    # by the platform. An `unresolved` leftover is a surface this walk did not
    # anticipate, and §4 says such a stop is the work list, not a shrug.
    local unresolved
    unresolved="$(jq -r '[.declarations.surfaces[] | select(.decided_by == "unresolved") | .id] | join(", ")' \
      "$HOST_R1/.duo/contract/contract.json")"
    [ -z "$unresolved" ] \
      || fail "$S: the accepted contract still carries unreviewed surface(s) this walk did not anticipate: $unresolved"
  fi

  rehearse_preview "$S"
  say "$S — author one page-body edit on the preview, then capture twice"
  preview_page_edit "$S" duo-walk-landing '<p>Duo walk landing page, released through duo release.</p>'
  capture_twice "$S" preview
  merge_preview "$S"
  revert_target "$S" "$PREVIEW_PAGE_ID" '<p>Duo walk landing page, before the release.</p>'
  release_cycle "$S" "$MAIN_SHA"

  say "$S — the unmanaged plugin's own rows, written AFTER the checkpoint"
  # This is S1's thesis. The plugin is excluded from every release gate, and
  # "excluded" must not read as "unprotected": its custom-table row and its
  # option are ordinary target state inside the database checkpoint's boundary,
  # so the printed sentence decides their fate exactly as it decides a managed
  # row's. A survivor here would mean the boundary is literal only for state
  # Duo manages, which is not what the sentence says.
  local marker="duo-walk-$S-post-checkpoint"
  if dry; then
    plan "wp2 db query \"INSERT INTO wp_$WPFORMS_TABLE ...\"   # a row the checkpoint predates"
    plan "wp2 option update ${WPFORMS_OPTION_PREFIX}duo_walk_marker $marker"
  else
    local prefix
    prefix="$(wp2 db prefix | tr -d '\r\n')"
    # wp_wpforms_tasks_meta (WPForms Lite 2.0.0.4): id, action, data, date —
    # read from the target's own SHOW CREATE TABLE on run 11; the marker rides
    # in `action`, which is what the read-back below matches on.
    wp2 db query "INSERT INTO \`${prefix}${WPFORMS_TABLE}\` (action, data, date) VALUES ('$marker', '[]', NOW())" \
      || fail "$S: could not write a post-checkpoint row into ${prefix}${WPFORMS_TABLE}"
    wp2 option update "${WPFORMS_OPTION_PREFIX}duo_walk_marker" "$marker"
    [ "$(wp2 option get "${WPFORMS_OPTION_PREFIX}duo_walk_marker" | tr -d '\r')" = "$marker" ] \
      || fail "$S: the post-checkpoint option was not written"
  fi

  recover_cycle "$S"
  if ! dry; then
    local prefix rows
    prefix="$(wp2 db prefix | tr -d '\r\n')"
    rows="$(wp2 db query "SELECT COUNT(*) FROM \`${prefix}${WPFORMS_TABLE}\` WHERE action = '$marker'" \
      --skip-column-names 2>/dev/null | tr -d '\r' | grep -E '^[0-9]+$' | tail -1 || echo 0)"
    [ "$rows" = 0 ] \
      || fail "$S: the claim said '$PLAN_BOUNDARY' but the post-checkpoint row in $WPFORMS_TABLE survived recovery.
The boundary is literal for unmanaged state too, or this test fails."
    if wp2 option get "${WPFORMS_OPTION_PREFIX}duo_walk_marker" >/dev/null 2>&1; then
      fail "$S: the post-checkpoint ${WPFORMS_OPTION_PREFIX}duo_walk_marker option survived recovery, against the printed boundary"
    fi
    local body
    body="$(wp2 post get "$PREVIEW_PAGE_ID" --field=post_content | tr -d '\r')"
    case "$body" in
      *'before the release'*) ;;
      *) fail "$S: the page body was not restored to its pre-release value" ;;
    esac
    pass "$S — the excluded plugin's post-checkpoint table row and option are both gone, and the page is restored"
  fi

  say "$S — duo assess ${PAIR}2 after recovery"
  post_recovery_check "$S"
  if ! dry; then
    # And the plugin is STILL unmanaged afterwards. A release and a recovery
    # that quietly changed what an excluded plugin projects would mean the
    # decision the contract recorded did not survive the loop it was made for.
    local expect got
    expect=$'runtime\tpreserve local\tUnsupported'
    got="$(walk_assess_projection "$ASSESS_JSON" "plugin:$WPFORMS_SLUG" release | cut -f1-3)"
    [ "$got" = "$expect" ] \
      || fail "$S: §3.6 — after the loop, plugin:$WPFORMS_SLUG projects '$got', expected the operator's decision '$expect'"
    pass "$S — the excluded plugin still projects runtime/preserve local/Unsupported after the whole loop"
  fi
  reap_cycle "$S"
  pass "$S PASSED — a published plugin stayed unmanaged, and the loop ran around it"
}

# ---------------------------------------------------------------------------
# S2 — the same plugin, with an adapter the operator authored and certified.
#
# What it must prove (§4): the authoring path is real end to end —
# coverage -> adapter-draft --seed -> a hand-finished manifest ->
# manifest-validate -> keygen -> certify --pin -> `site_signed` in the catalog
# and `Site-certified` in the projection — and that once it is certified the
# plugin's forms are ordinary managed state that captures, releases, verifies
# and recovers like any other.
# ---------------------------------------------------------------------------
scenario_s2() {
  local S=S2
  run mkdir -p "$EVIDENCE/$S"
  scenario_pair "$S" with-subject
  write_registry
  seed_shop duo-walk-landing

  say "$S — one form on ${PAIR}1 to author against"
  if dry; then
    plan "wp1 post create --post_type=$WPFORMS_CPT --post_content='<form json>'"
  else
    wp1 post create --post_type="$WPFORMS_CPT" --post_status=publish \
      --post_title='Duo walk contact form' --post_name=duo-walk-contact \
      --post_content='{"id":"1","settings":{"form_title":"Duo walk contact form"},"fields":{"1":{"id":"1","type":"email","label":"Email"}}}' \
      --porcelain >/dev/null
  fi

  # §4's S2 row orders coverage, adapter-draft, manifest-validate, keygen and
  # certify BEFORE the init it then expects to succeed — so the repository has
  # to exist first, and it exists as the adoption seed rather than as an init
  # this scenario would then have to run twice.
  seed_repository "$S"

  say "$S — duo coverage ${PAIR}1 --format=json (the draft's seed)"
  duo_ok "$SCRATCH/$S-coverage.raw" "$HOST_R1" coverage "${PAIR}1" --format=json
  local seed="$EVIDENCE/$S/coverage.json"
  if dry; then
    plan "walk_agent_json <coverage raw> > $seed"
  else
    walk_agent_json "$SCRATCH/$S-coverage.raw" > "$seed"
    # Coverage groups by family token (`wpforms`, and sub-families such as
    # `wpforms_transient`); at least the family itself must be reported.
    jq -e --arg f "$WPFORMS_OPTION_FAMILY" '
      [.options.invisible_groups[] | select(.prefix == $f or (.prefix | startswith($f + "_")))] | length >= 1
    ' "$seed" >/dev/null \
      || fail "$S: coverage reports no invisible option group for the $WPFORMS_OPTION_FAMILY family, so --seed has nothing to propose"
  fi

  say "$S — duo adapter-draft ${PAIR}1 --seed --out=adapters/$WPFORMS_CPT.json"
  local draft="$HOST_R1/adapters/$WPFORMS_CPT.json"
  run mkdir -p "$HOST_R1/adapters"
  duo_ok "$EVIDENCE/$S/adapter-draft.txt" "$HOST_R1" \
    adapter-draft "$HOST_R1" --name="$WPFORMS_CPT" --match="^_?$WPFORMS_OPTION_PREFIX" \
    --seed="$seed" --out="$draft"
  # §3.5: `--out` refuses to overwrite without `--force`. A draft that silently
  # replaced a hand-finished manifest would destroy the operator's own work.
  duo_refused "$EVIDENCE/$S/adapter-draft-overwrite.txt" draft_output_exists "$HOST_R1" \
    adapter-draft "$HOST_R1" --name="$WPFORMS_CPT" --seed="$seed" --out="$draft"
  if ! dry; then
    [ -s "$draft" ] || fail "$S: adapter-draft --out wrote no draft at $draft"
    # Seeded candidates are inert `_draft.proposals` (Policy::load() never
    # applies a proposal); the operator ratifies them by hand below. Scoped by
    # --match, the seed must propose the wpforms family and its tables, and
    # NOT the core-option prefixes coverage cannot attribute either.
    # Every clause parenthesised: jq's `|` binds looser than `and`, so an
    # unparenthesised first clause would feed its ARRAY into the next
    # clause's `._draft` (seen: "Cannot index array with string _draft").
    jq -e --arg p "$WPFORMS_OPTION_PREFIX" '
      ([(._draft.proposals.option_namespaces // [])[] | select(.candidate.match | test($p))] | length >= 1)
      and ([(._draft.proposals.tables // [])[] | select(.target | test("tables\\." + $p))] | length >= 1)
      and ([(._draft.proposals.option_namespaces // [])[] | select(.candidate.match | test("admin|blog|avatar"))] | length == 0)
    ' "$draft" >/dev/null \
      || fail "$S: §3.5 — --seed did not turn coverage's $WPFORMS_OPTION_PREFIX prefix and tables into scoped proposals (or proposed unrelated core prefixes)"
  fi
  pass "$S — the draft exists and carries the seed's option-prefix proposal"

  say "$S — finish the draft into a minimal honest manifest"
  # The draft is a proposal; a manifest is a claim. Every rule below is one the
  # walk can defend from the installed plugin: WPForms stores each form as a
  # `wpforms` post whose post_content is the form's own JSON, which is why the
  # post type declares `body: verbatim` (spec/repo-format.md:520 — verbatim
  # byte-preserves post_content for serialized-data bodies, where URL
  # substitution would corrupt the payload). `wpforms_settings` is the one
  # option in the namespace an operator edits; everything else under the prefix
  # is the plugin's own runtime bookkeeping. The tables are declared `runtime`,
  # which is the manifest grammar's own word for "declared, deliberately not
  # captured" (agent/src/Policy/ManifestGrammar.php TABLE_CLASSES) — the same
  # declaration manifests/woocommerce.json makes for Action Scheduler's tables.
  if dry; then
    plan "jq: finish $draft into spec_version 2 / name $WPFORMS_CPT / plugin $WPFORMS_BASENAME / version_range [$WPFORMS_VERSION, 2.1.0)"
  else
    jq -n --arg name "$WPFORMS_CPT" --arg plugin "$WPFORMS_BASENAME" \
          --arg min "$WPFORMS_VERSION" --arg cpt "$WPFORMS_CPT" \
          --arg prefix "^$WPFORMS_OPTION_PREFIX" '
      {
        name: $name,
        notes: {
          "authored (T6 S2, verified against the installed plugin)":
            "Each form is one `\($cpt)` post whose post_content is the form definition as JSON, so the post type is authored with body \"verbatim\": spec/repo-format.md byte-preserves post_content for serialized-data bodies, where URL substitution would corrupt the payload. \($name)_settings is the operator-edited settings blob; every other option under the namespace is bookkeeping the plugin owns and is runtime. The custom tables are declared runtime — the grammar word for declared-and-deliberately-not-captured, the same declaration manifests/woocommerce.json makes for Action Scheduler."
        },
        option_autoload: "preserve",
        option_namespaces: [{match: $prefix}],
        option_patterns: [{match: $prefix, class: "runtime"}],
        options: {("\($name)_settings"): {class: "authored"}},
        plugin: $plugin,
        post_types: {($cpt): {class: "authored", body: "verbatim"}},
        spec_version: 2,
        tables: {
          ("\($name)_analytics_forms"): {class: "runtime"},
          ("\($name)_analytics_snapshots"): {class: "runtime"},
          ("\($name)_logs"): {class: "runtime"},
          ("\($name)_payment_meta"): {class: "runtime"},
          ("\($name)_payments"): {class: "runtime"},
          ("\($name)_tasks_meta"): {class: "runtime"}
        },
        version_range: {min: $min, max: "2.1.0"}
      }' > "$draft.finished" \
      || fail "$S: could not finish the draft into a manifest"
    mv "$draft.finished" "$draft"
  fi

  say "$S — duo manifest-validate $HOST_R1/adapters --site=$HOST_R1"
  duo_ok "$EVIDENCE/$S/manifest-validate.txt" "$HOST_R1" \
    manifest-validate "$HOST_R1/adapters" --site="$HOST_R1"
  if ! dry; then
    grep -Fq "[ok] $WPFORMS_CPT" "$EVIDENCE/$S/manifest-validate.txt" \
      || fail "$S: manifest-validate did not accept the finished manifest; see $EVIDENCE/$S/manifest-validate.txt"
  fi
  pass "$S — the operator-authored manifest is grammatically valid against this site"

  # §1/§3.4: an installed but UNCERTIFIED site adapter still blocks init, and
  # §3.4 moves that blocker's remediation onto the new verb. Asserting it here
  # is what makes certification a step rather than a formality.
  say "$S — duo init ${PAIR}1 refuses while the adapter is uncertified"
  duo_refused "$EVIDENCE/$S/init-uncertified.txt" adapter_source_uncertified "$HOST_R1" init "${PAIR}1" --yes
  if ! dry; then
    grep -Fq 'duo adapter certify' "$EVIDENCE/$S/init-uncertified.txt" \
      || fail "$S: §3.4 — the uncertified-adapter blocker must name duo adapter certify"
  fi
  adapter_catalog "$S" uncertified
  if ! dry; then
    local row
    row="$(walk_catalog_row "$CATALOG_JSON" "$WPFORMS_CPT")"
    [ "$(printf '%s' "$row" | cut -f2)" = uncertified ] \
      || fail "$S: the catalog reports '$row' for the freshly installed adapter, expected an uncertified site row"
  fi
  pass "$S — an installed, unsigned site adapter is visibly uncertified and blocks init"

  keygen_and_certify "$S" "$WPFORMS_CPT"
  adapter_catalog "$S" certified
  if ! dry; then
    local expect got
    expect=$'site\tsite_signed\tsite\t'"$WALK_KEY_ID"
    got="$(walk_catalog_row "$CATALOG_JSON" "$WPFORMS_CPT")"
    [ "$got" = "$expect" ] \
      || fail "$S: §3.2 — the certified adapter's catalog row reads '$got', expected '$expect'"
    grep -Fq 'site_signed' "$EVIDENCE/$S/adapter-list-certified.txt" \
      || fail "$S: §3.2 — the human catalog does not print the word site_signed"
  fi
  pass "$S — the catalog reads site/site_signed/site/$WALK_KEY_ID"

  # This is §3.4's own remediation carried out: "certify it with duo adapter
  # certify …, then rerun duo init". Rerunning init necessarily means running
  # it on a repository whose non-seed content is exactly an adapter, its
  # certificate and its pin, so this step is where that becomes a fact rather
  # than a sentence. No --allow-unmanaged-plugins: the plugin has an owning
  # adapter now, and needing the flag here would mean certification bought
  # nothing.
  say "$S — duo init ${PAIR}1 --yes (no unmanaged flag: the plugin has a certified adapter now)"
  duo_ok "$EVIDENCE/$S/init.txt" "$HOST_R1" init "${PAIR}1" --yes
  if ! dry; then
    jq -e --arg t "$WPFORMS_CPT" '[.policy.post_types[]] | index($t) != null' "$HOST_R1/site.duo.json" >/dev/null \
      || fail "$S: init did not take the adapter's authored post type into policy scope"
  fi
  duo_ok "$EVIDENCE/$S/capture.txt" "$HOST_R1" capture "${PAIR}1"
  baseline_commit "$S"
  pass "$S — init and capture are green with the operator's own adapter governing the forms"

  assess_both "$S" "${PAIR}1" "$HOST_R1" site-certified
  if ! dry; then
    local expect got
    expect=$'authored\tmanage\tReady\tSite-certified\tprevented\tprovider-state restorable'
    got="$(walk_assess_projection "$ASSESS_JSON" "post_type:$WPFORMS_CPT" release)"
    [ "$got" = "$expect" ] \
      || fail "$S: §3.6 — post_type:$WPFORMS_CPT projected '$got', expected '$expect'"
    expect=$'Site-certified\tsite\t'"$WALK_KEY_ID"
    got="$(walk_assess_certification "$ASSESS_JSON" "post_type:$WPFORMS_CPT" release)"
    [ "$got" = "$expect" ] \
      || fail "$S: §3.2 — the projection's certification triple read '$got', expected '$expect'"
    # §3.6: the human view says once who signed it and that the contract
    # attestation is still unsigned. Both halves matter: the first names the
    # authority, the second refuses to let a site signature read as more than
    # it is.
    local principalLine="certified by $WALK_KEY_ID (site trust root); contract attestation unsigned"
    [ "$(grep -cF "$principalLine" "$EVIDENCE/$S/assess-site-certified.txt")" = 1 ] \
      || fail "$S: §3.6 — the human view must print exactly once: $principalLine"
    pass "$S — assess reads Site-certified, names the principal, and says the attestation is unsigned"
  fi

  contract_cycle "$S" "${PAIR}1" "$HOST_R1" "$LANDING_ID" - '.'
  rehearse_preview "$S"

  say "$S — author a NEW form on the preview, then capture twice"
  local formTitle='Duo walk quote request'
  if dry; then
    plan "wp2 post create --post_type=$WPFORMS_CPT --post_title='$formTitle' --post_content='<form json>'"
  else
    wp2 post create --post_type="$WPFORMS_CPT" --post_status=publish \
      --post_title="$formTitle" --post_name=duo-walk-quote \
      --post_content='{"id":"3","settings":{"form_title":"Duo walk quote request"},"fields":{"1":{"id":"1","type":"text","label":"Company"}}}' \
      --porcelain >/dev/null
  fi
  preview_page_edit "$S" duo-walk-landing '<p>Duo walk landing page, released through duo release.</p>'
  capture_twice "$S" preview
  merge_preview "$S"
  # The new form is on the preview and therefore already on the target; the
  # release has to have something to apply, so the target's copy goes away and
  # its ledger is brought back into agreement, exactly as the page body is.
  if dry; then
    plan "wp2 post delete <quote-form> --force   # so the release has the form to apply"
  else
    local previewFormId
    previewFormId="$(wp2 post list --post_type="$WPFORMS_CPT" --name=duo-walk-quote --field=ID | tr -d '\r' | head -1)"
    [ -n "$previewFormId" ] || fail "$S: the preview did not carry the authored form back"
    wp2 post delete "$previewFormId" --force
  fi
  revert_target "$S" "$PREVIEW_PAGE_ID" '<p>Duo walk landing page, before the release.</p>'
  release_cycle "$S" "$MAIN_SHA"
  if ! dry; then
    wp2 post list --post_type="$WPFORMS_CPT" --field=post_title | tr -d '\r' | grep -Fqx "$formTitle" \
      || fail "$S: the release did not put the authored form on the target"
    pass "$S — the operator-authored form arrived on the release target"
  fi
  recover_cycle "$S"
  post_recovery_check "$S"
  reap_cycle "$S"
  pass "$S PASSED — an operator authored, certified and released through their own adapter"
}

# ---------------------------------------------------------------------------
# S3 — an in-house plugin that bundles its own adapter.
#
# What it must prove (§4): the bundled source is discovered and reported
# `uncertified` with the PROMOTION path as its remediation (a bundled adapter
# cannot be certified in place, because certification binds `source: "site"`
# and the exact `adapters/<name>.json` path inside the signed statement); the
# promotion works; and after certification the custom plugin's CPT is ordinary
# managed state through the whole loop.
# ---------------------------------------------------------------------------
scenario_s3() {
  local S=S3
  run mkdir -p "$EVIDENCE/$S"
  scenario_pair "$S"
  write_registry
  install_acme 1 author
  install_acme 2 target
  seed_shop duo-walk-landing

  say "$S — one catalog item on ${PAIR}1"
  if dry; then
    plan "wp1 term create $ACME_TAXONOMY 'Duo walk kind' --slug=duo-walk-kind"
    plan "wp1 post create --post_type=$ACME_CPT --post_title='Duo walk item'"
  else
    wp1 term create "$ACME_TAXONOMY" 'Duo walk kind' --slug=duo-walk-kind --porcelain >/dev/null
    wp1 post create --post_type="$ACME_CPT" --post_status=publish \
      --post_title='Duo walk item' --post_name=duo-walk-item \
      --post_content='<p>The first catalog item.</p>' --porcelain >/dev/null
  fi

  # Same reason as S2: manifest-validate --site, adapter certify and adapter
  # list all resolve a directory holding site.duo.json, and §4 orders them
  # before the init that then has to succeed.
  seed_repository "$S"

  say "$S — wp duo adapter-survey on the target: the bundled source"
  # The host-side `duo adapter` commands are WordPress-free and cannot read
  # WP_PLUGIN_DIR, so the only place the plugin source is visible is the
  # target's own survey. That is not a workaround; it is the boundary the
  # catalog command documents on every run.
  wp_ok "$SCRATCH/$S-survey.raw" 1 duo adapter-survey --repo=/siterepo --format=json
  if ! dry; then
    walk_agent_json "$SCRATCH/$S-survey.raw" > "$EVIDENCE/$S/adapter-survey.json"
    walk_assert_bundled_uncertified "$EVIDENCE/$S/adapter-survey.json" "$ACME_SLUG" \
      || fail "$S: the survey does not report $ACME_SLUG as an uncertified plugin-source adapter"
    pass "$S — the bundled adapter is discovered from the active plugin and reads uncertified"
  fi

  say "$S — duo init ${PAIR}1 refuses: the bundled adapter is uncertified"
  duo_refused "$EVIDENCE/$S/init-uncertified.txt" adapter_source_uncertified "$HOST_R1" init "${PAIR}1" --yes
  if ! dry; then
    grep -Fq "install this adapter as a repository package at adapters/$ACME_SLUG.json" \
      "$EVIDENCE/$S/init-uncertified.txt" \
      || fail "$S: the bundled adapter's blocker does not carry the promotion path as its remediation"
    pass "$S — the blocker names the promotion path, which is the only thing that CAN be done"
  fi

  say "$S — promote the bundled adapter to adapters/$ACME_SLUG.json"
  run mkdir -p "$HOST_R1/adapters"
  run cp "$SANDBOX/fixtures/$ACME_SLUG/duo-adapter.json" "$HOST_R1/adapters/$ACME_SLUG.json"
  duo_ok "$EVIDENCE/$S/manifest-validate.txt" "$HOST_R1" \
    manifest-validate "$HOST_R1/adapters" --site="$HOST_R1"
  keygen_and_certify "$S" "$ACME_SLUG"
  adapter_catalog "$S" certified
  if ! dry; then
    local expect got
    expect=$'site\tsite_signed\tsite\t'"$WALK_KEY_ID"
    got="$(walk_catalog_row "$CATALOG_JSON" "$ACME_SLUG")"
    [ "$got" = "$expect" ] \
      || fail "$S: the promoted adapter's catalog row reads '$got', expected '$expect'"
  fi
  # The site copy now outranks the bundled one, and the bundled one reports as
  # installed-but-not-loaded with the site copy as its winner. Read from the
  # target, because only the target can see the plugin source at all.
  wp_ok "$SCRATCH/$S-survey-after.raw" 1 duo adapter-survey --repo=/siterepo --format=json
  if ! dry; then
    walk_agent_json "$SCRATCH/$S-survey-after.raw" > "$EVIDENCE/$S/adapter-survey-after.json"
    jq -e --arg n "$ACME_SLUG" '
      (.not_installed // []) | any(.name == $n and .source == "plugin" and (.winner.source // "") == "site")
    ' "$EVIDENCE/$S/adapter-survey-after.json" >/dev/null \
      || fail "$S: after promotion the bundled copy is not reported as not-loaded behind the site copy"
    jq -e --arg n "$ACME_SLUG" '
      (.adapters[] | select(.name == $n)) | .source == "site"
    ' "$EVIDENCE/$S/adapter-survey-after.json" >/dev/null \
      || fail "$S: the site copy does not answer to the name after promotion"
    pass "$S — the site copy wins by precedence; the plugin stayed active throughout"
  fi

  say "$S — duo init ${PAIR}1 --yes (the promoted adapter is certified)"
  duo_ok "$EVIDENCE/$S/init.txt" "$HOST_R1" init "${PAIR}1" --yes
  if ! dry; then
    jq -e --arg t "$ACME_CPT" '[.policy.post_types[]] | index($t) != null' "$HOST_R1/site.duo.json" >/dev/null \
      || fail "$S: init did not take $ACME_CPT into policy scope from the promoted adapter"
    jq -e --arg t "$ACME_TAXONOMY" '[.policy.taxonomies[]] | index($t) != null' "$HOST_R1/site.duo.json" >/dev/null \
      || fail "$S: init did not take $ACME_TAXONOMY into policy scope from the promoted adapter"
  fi
  duo_ok "$EVIDENCE/$S/capture.txt" "$HOST_R1" capture "${PAIR}1"
  baseline_commit "$S"

  assess_both "$S" "${PAIR}1" "$HOST_R1" site-certified
  if ! dry; then
    local expect got
    expect=$'authored\tmanage\tReady\tSite-certified\tprevented\tprovider-state restorable'
    got="$(walk_assess_projection "$ASSESS_JSON" "post_type:$ACME_CPT" release)"
    [ "$got" = "$expect" ] \
      || fail "$S: post_type:$ACME_CPT projected '$got', expected '$expect'"
    # The plugin's own undeclared table is still undeclared on purpose (the
    # bundled manifest says so in its notes), so it must still be visible as a
    # finding rather than silently absorbed by certification.
    jq -e '[.surfaces[] | select(.id == "table:acme_catalog_index")] | length == 1' "$ASSESS_JSON" >/dev/null \
      || fail "$S: the plugin's deliberately undeclared table no longer appears as a surface"
    pass "$S — the custom plugin's CPT is Site-certified and its undeclared table is still a named finding"
  fi

  # The acme archive is a real public surface, so S3 declares it as a third
  # journey: a release that writes a catalog item and cannot prove the archive
  # renders it has verified bytes rather than behaviour.
  local extraJourney
  extraJourney="$(jq -nc --arg id "$ACME_CPT" '
    {id: "acme-index", url: "/?post_type=\($id)", expect_status: 200,
     expect_contains: "Duo walk item", affected_surfaces: ["post_type:\($id)"]}')"
  # The undeclared table is decided the same way S1 decides the plugin's
  # tables: it is that plugin's own runtime index, kept local.
  local surfaceFilter='
    .contract.declarations.surfaces = [
      .contract.declarations.surfaces[]
      | if .id == "table:acme_catalog_index"
        then . + {state_class: "runtime", handling: "preserve local",
                  decided_by: "operator", decided_at: "2026-08-17T09:03:44Z"}
             | del(.next_action)
        else . end
    ]'
  contract_cycle "$S" "${PAIR}1" "$HOST_R1" "$LANDING_ID" "$extraJourney" "$surfaceFilter"
  rehearse_preview "$S"

  say "$S — author a catalog item on the preview, then capture twice"
  local itemTitle='Duo walk second item'
  if dry; then
    plan "wp2 post create --post_type=$ACME_CPT --post_title='$itemTitle'"
  else
    wp2 post create --post_type="$ACME_CPT" --post_status=publish \
      --post_title="$itemTitle" --post_name=duo-walk-item-2 \
      --post_content='<p>The second catalog item.</p>' --porcelain >/dev/null
  fi
  preview_page_edit "$S" duo-walk-landing '<p>Duo walk landing page, released through duo release.</p>'
  capture_twice "$S" preview
  merge_preview "$S"
  if dry; then
    plan "wp2 post delete <second item> --force   # so the release has the item to apply"
  else
    local previewItemId
    previewItemId="$(wp2 post list --post_type="$ACME_CPT" --name=duo-walk-item-2 --field=ID | tr -d '\r' | head -1)"
    [ -n "$previewItemId" ] || fail "$S: the preview did not carry the authored catalog item back"
    wp2 post delete "$previewItemId" --force
  fi
  revert_target "$S" "$PREVIEW_PAGE_ID" '<p>Duo walk landing page, before the release.</p>'
  release_cycle "$S" "$MAIN_SHA"
  if ! dry; then
    wp2 post list --post_type="$ACME_CPT" --field=post_title | tr -d '\r' | grep -Fqx "$itemTitle" \
      || fail "$S: the release did not put the authored catalog item on the target"
  fi
  recover_cycle "$S"
  post_recovery_check "$S"
  reap_cycle "$S"
  pass "$S PASSED — a plugin's own bundled adapter was promoted, certified and released through"
}

# ---------------------------------------------------------------------------
# S4 — override a shipped adapter with a site copy.
#
# What it must prove (§3.3/§4): an explicit {name, source:"site", digest} pin
# selects the site copy for a SHIPPED name — today that is a whole-source
# refusal — the shipped copy reports `shadowed_by_site`, the site copy carries
# the SITE's certification words on every surface it governs (a signed override
# is `Site-certified`, never `Platform-certified`, because the customer
# organization's approval is explicitly not a Duo endorsement), and the
# ordinary loop still converges.
# ---------------------------------------------------------------------------
scenario_s4() {
  local S=S4
  run mkdir -p "$EVIDENCE/$S"
  scenario_pair "$S"
  write_registry
  seed_shop duo-walk-landing

  say "$S — duo init ${PAIR}1 --yes on the ordinary shipped-adapter site"
  duo_ok "$EVIDENCE/$S/init.txt" "$HOST_R1" init "${PAIR}1" --yes
  baseline_commit "$S"
  assess_both "$S" "${PAIR}1" "$HOST_R1" platform-certified
  if ! dry; then
    local got
    got="$(walk_assess_certification "$ASSESS_JSON" post_type:product release | cut -f1)"
    [ "$got" = 'Platform-certified' ] \
      || fail "$S: before the override, post_type:product must read Platform-certified, not '$got'"
    pass "$S — the shipped adapter governs post_type:product and reads Platform-certified"
  fi

  say "$S — copy the shipped woocommerce manifest into adapters/ and add one authored option"
  # `woocommerce_store_address_2` is a real WooCommerce store-address setting
  # (the second address line) that manifests/woocommerce.json does not declare
  # — checked against the shipped manifest below, so the override is a genuine
  # widening rather than a restatement. One added rule is enough: §3.3 is about
  # which COPY answers to the name, and a copy that differed in nothing would
  # make "the site copy won" unobservable.
  local override="$HOST_R1/adapters/woocommerce.json"
  local newOption=woocommerce_store_address_2
  run mkdir -p "$HOST_R1/adapters"
  if dry; then
    plan "jq: cp manifests/woocommerce.json -> adapters/woocommerce.json + options.$newOption = authored"
  else
    jq -e --arg o "$newOption" '.options[$o] == null' "$REPO_ROOT/manifests/woocommerce.json" >/dev/null \
      || fail "$S: manifests/woocommerce.json already declares $newOption, so adding it proves nothing; pick another undeclared option"
    # `notes` is free-form in the grammar and the shipped copy carries it as
    # an object (keyed rationale), so the override adds a key rather than
    # assuming a list.
    jq --arg o "$newOption" '
      .options[$o] = {class: "authored", autoload: "preserve"}
      | .notes = ((if (.notes | type) == "object" then .notes else {} end)
          + {"round-3 T6 S4: site override": "This copy is the shipped manifest plus one authored option the shipped copy does not declare (\($o), the second store-address line WooCommerce writes), so which copy answered to the name is observable rather than asserted."})
    ' "$REPO_ROOT/manifests/woocommerce.json" > "$override" \
      || fail "$S: could not build the site override manifest"
  fi

  # Before the override is STATED, the site copy of a shipped name is a
  # shadow, and every loader-backed verb refuses it — including
  # manifest-validate --site. That is the documented stop, and its remediation
  # must name the override verb rather than only "rename or remove".
  say "$S — duo manifest-validate refuses the unstated override (shadows_shipped) and names the pin"
  duo_refused "$EVIDENCE/$S/manifest-validate-shadow.txt" shadows_shipped "$HOST_R1" \
    manifest-validate "$HOST_R1/adapters" --site="$HOST_R1"
  if ! dry; then
    grep -Fq 'duo adapter pin' "$EVIDENCE/$S/manifest-validate-shadow.txt" \
      || fail "$S: §3.3 — the shadow refusal does not name the override verb (duo adapter pin … --source=site)"
  fi

  say "$S — duo adapter pin ${PAIR}1 --name=woocommerce --source=site (the override)"
  duo_ok "$EVIDENCE/$S/adapter-pin.txt" "$HOST_R1" \
    adapter pin "$HOST_R1" --name=woocommerce --source=site
  if ! dry; then
    grep -Fq "override: site.duo.json now names the site copy of shipped adapter 'woocommerce'" \
      "$EVIDENCE/$S/adapter-pin.txt" \
      || fail "$S: §3.3 — adapter pin --source=site did not report bootstrapping the override statement"
    jq -e '
      ([.manifests[] | select(type == "object" and .name == "woocommerce" and .source == "site"
        and (.digest | type == "string"))] | length == 1)
      and ([.manifests[] | select(. == "woocommerce")] | length == 0)
    ' "$HOST_R1/site.duo.json" >/dev/null \
      || fail "$S: §3.3 — adapter pin did not replace the name-only pin with the explicit {name,source:\"site\",digest} override in site.duo.json"
  fi
  duo_ok "$EVIDENCE/$S/manifest-validate.txt" "$HOST_R1" \
    manifest-validate "$HOST_R1/adapters" --site="$HOST_R1"
  if ! dry; then
    grep -Fq '[ok] woocommerce' "$EVIDENCE/$S/manifest-validate.txt" \
      || fail "$S: manifest-validate did not accept the stated override; see $EVIDENCE/$S/manifest-validate.txt"
    pass "$S — the override validates once stated, with the shipped provider grant inherited"
  fi
  adapter_catalog "$S" override
  if ! dry; then
    walk_assert_shadowed_by_site "$CATALOG_JSON" woocommerce \
      || fail "$S: §3.3 — the catalog does not report the shipped woocommerce copy as shadowed_by_site"
    grep -Fq 'shadowed_by_site' "$EVIDENCE/$S/adapter-list-override.txt" \
      || fail "$S: §3.3 — the human catalog does not print shadowed_by_site"
    local got
    got="$(walk_catalog_row "$CATALOG_JSON" woocommerce | cut -f1)"
    [ "$got" = site ] \
      || fail "$S: the loaded woocommerce adapter's source is '$got', not the site copy the pin selected"
  fi
  pass "$S — the site copy answers to the shipped name and the shipped copy is shadowed_by_site"

  # §3.6's new gap action, asserted where it can be asserted LIVE: the site
  # copy governs these surfaces now and is not certified yet, so their
  # readiness is `Not qualified` caused by `adapter_source_uncertified` — and
  # the smallest safe next action is `certify adapter`, never `install adapter`
  # (the adapter exists; the operator just installed it). S4 is the only
  # scenario that can make this claim without a conditional: its repository was
  # initialized before the override, so the WooCommerce surfaces are already in
  # policy scope while the adapter governing them is uncertified.
  say "$S — duo assess ${PAIR}1 with the override pinned but not yet certified"
  assess_both "$S" "${PAIR}1" "$HOST_R1" override-uncertified
  if ! dry; then
    local expect got
    expect=$'Not qualified\tUncertified'
    got="$(walk_assess_projection "$ASSESS_JSON" post_type:product release | cut -f3-4)"
    [ "$got" = "$expect" ] \
      || fail "$S: an uncertified site override must project '$expect' for post_type:product, not '$got'"
    got="$(walk_assess_next_action "$ASSESS_JSON" post_type:product)"
    [ "$got" = 'certify adapter' ] \
      || fail "$S: §3.6 — the next action for a Not-qualified surface caused by adapter_source_uncertified is 'certify adapter', not '$got'.
The adapter exists; telling the operator to install one is telling them to redo what they just did."
    got="$(walk_gap_count "$EVIDENCE/$S/assess-override-uncertified.txt" 'certify adapter')"
    [ "$got" -ge 1 ] \
      || fail "$S: the next-actions roll-up counted $got 'certify adapter' findings"
    pass "$S — the uncertified override reads Not qualified / Uncertified, next action 'certify adapter'"
  fi

  keygen_and_certify "$S" woocommerce
  adapter_catalog "$S" certified
  if ! dry; then
    local expect got
    expect=$'site\tsite_signed\tsite\t'"$WALK_KEY_ID"
    got="$(walk_catalog_row "$CATALOG_JSON" woocommerce)"
    [ "$got" = "$expect" ] \
      || fail "$S: the certified override's catalog row reads '$got', expected '$expect'"
  fi

  # No second `duo init`: this repository is already init-owned, and §3.3's
  # override is a change to the PIN SET, which `duo capture` reads on its next
  # run. Re-initializing an initialized repository is a different operation
  # with its own blocker (`existing_configuration`), and asserting it here
  # would be asserting something the override does not need.
  duo_ok "$EVIDENCE/$S/capture.txt" "$HOST_R1" capture "${PAIR}1"
  git1 add -A
  commit1 "grind_adapter_walk $S: site override of the shipped woocommerce adapter, certified"
  git1 push -q origin main

  assess_both "$S" "${PAIR}1" "$HOST_R1" site-certified
  if ! dry; then
    local expect got
    expect=$'Site-certified\tsite\t'"$WALK_KEY_ID"
    got="$(walk_assess_certification "$ASSESS_JSON" post_type:product release)"
    [ "$got" = "$expect" ] \
      || fail "$S: §3.3 — an overridden surface must read '$expect', not '$got'.
A signed override is Site-certified, never Platform-certified: the customer organization's approval is explicitly not a Duo endorsement."
    pass "$S — every WooCommerce surface now reads Site-certified under $WALK_KEY_ID"
  fi

  contract_cycle "$S" "${PAIR}1" "$HOST_R1" "$LANDING_ID" - '.'
  rehearse_preview "$S"
  say "$S — author a page-body edit on the preview, then capture twice"
  preview_page_edit "$S" duo-walk-landing '<p>Duo walk landing page, released through duo release.</p>'
  capture_twice "$S" preview
  merge_preview "$S"
  revert_target "$S" "$PREVIEW_PAGE_ID" '<p>Duo walk landing page, before the release.</p>'
  release_cycle "$S" "$MAIN_SHA"
  recover_cycle "$S"
  post_recovery_check "$S"
  reap_cycle "$S"
  pass "$S PASSED — a shipped adapter was overridden, certified, and released through"
}

# ---------------------------------------------------------------------------
# The walk.
# ---------------------------------------------------------------------------
for scenario in ${SCENARIOS//,/ }; do
  case "$scenario" in
    S1) scenario_s1 ;;
    S2) scenario_s2 ;;
    S3) scenario_s3 ;;
    S4) scenario_s4 ;;
  esac
  SCENARIOS_RUN+=("$scenario")
done

if dry; then
  printf '\n\033[1;32mGRIND_ADAPTER_WALK DRY RUN COMPLETE — nothing above was executed\033[0m\n' >&3
  exit 0
fi
if [ "$FAILURES" -ne 0 ]; then
  printf '\nGRIND_ADAPTER_WALK FAILED (%d)\n' "$FAILURES" >&2
  exit 1
fi
printf '\n\033[1;32m✔ GRIND_ADAPTER_WALK PASSED (%s)\033[0m\n' "$(IFS=,; printf '%s' "${SCENARIOS_RUN[*]}")" >&3
printf 'evidence: %s\n' "$EVIDENCE" >&3
