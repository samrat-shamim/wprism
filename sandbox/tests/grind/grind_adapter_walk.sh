#!/usr/bin/env bash
# Operator-authored adapters — the four-scenario walk (round-3 T6 §4).
#
# Contract: docs/grind/adapter-walk.md, with the certification bundle's wire
# format in docs/adapter-walk-bundle.md. Those documents are binding on
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
# Deliberately `sandbox/tests/grind/grind_mup.sh`'s shape, not a new one: the same
# say/pass/fail helpers, the same `run`/`run_in` wrappers that print instead of
# executing under `--dry-run`, the same `--self-check` discipline (every pure
# helper against a recorded PASS document AND a hand-mutated FAIL document),
# the same one-dedicated-pair model with the pair NAME and both ports
# parameterized, the same Git-enabled cli image built from
# `sandbox/init-cli.Dockerfile` and handed to pair.sh through DUO_CLI_IMAGE,
# the same machine-local registry whose SOURCE and TARGET entries both carry
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
#   bash sandbox/tests/grind/grind_adapter_walk.sh --self-check
#       Every pure bash/jq helper against sandbox/tests/fixtures/adapter-walk/.
#       No docker, no pair, no network. A helper that cannot fail proves
#       nothing about the run that trusts it, so every helper has both.
#
#   bash sandbox/tests/grind/grind_adapter_walk.sh --dry-run
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
#   trust_root                                          catalog row key             §3.2
#   principal                                           catalog row key             §3.2
#   certification_trust_root / certification_principal  projection keys (assess)    §3.2 (product spelling, beside certification_provenance)
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
cd "$(dirname "$0")/../.."   # -> sandbox/

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

# The helpers this walk shares with grind_adoption.sh live in one place; the
# self-check below exercises every pure one against the recorded fixtures.
# shellcheck source=../lib/grind_lib.sh
. "$SANDBOX/tests/lib/grind_lib.sh"



# ---------------------------------------------------------------------------
# Preflight, cleanup, and the pair.
# ---------------------------------------------------------------------------

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
  WALK_THEME_SLUG=twentytwentyone WALK_THEME_VERSION=2.8 bash sandbox/tests/grind/grind_adapter_walk.sh"
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
# shellcheck source=../../bin/fetch-artifact.sh
. bin/fetch-artifact.sh

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
  capture_twice "$S" "${PAIR}2"
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
  # `deletions` declares the form post type deletable (the required post
  # cascade set, no guards: a Lite form is referenced by nothing Duo manages);
  # without it the target-side capture after `wp post delete` of the authored
  # form refuses "deletion intent for post:wpforms is unsupported" (run 21).
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
        deletions: {("post:\($cpt)"): {cascades: ["postmeta", "post_revisions", "term_relationships"], guards: []}},
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
  capture_twice "$S" "${PAIR}2"
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
  capture_twice "$S" "${PAIR}2"
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
  # This synthetic option is deliberately inside Woo's discovery namespace
  # but outside every shipped exact/pattern rule. A site copy must differ in
  # observable policy bytes to prove which copy answers to the name; using a
  # real merchant field here previously froze a production omission into the
  # grind fixture instead of testing only override precedence.
  local override="$HOST_R1/adapters/woocommerce.json"
  local newOption=woocommerce_duo_site_override_probe
  run mkdir -p "$HOST_R1/adapters"
  if dry; then
    plan "jq: cp manifests/woocommerce.json -> adapters/woocommerce.json + options.$newOption = authored"
  else
    jq -e --arg o "$newOption" '
      .options[$o] == null
      and ([(.option_patterns // [])[] | . as $p | select($o | test($p.match))] | length == 0)
    ' "$REPO_ROOT/manifests/woocommerce.json" >/dev/null \
      || fail "$S: manifests/woocommerce.json already declares or pattern-covers $newOption, so adding it proves nothing; pick another undeclared option"
    # `notes` is free-form in the grammar and the shipped copy carries it as
    # an object (keyed rationale), so the override adds a key rather than
    # assuming a list.
    jq --arg o "$newOption" '
      .options[$o] = {class: "authored", autoload: "preserve"}
      | .notes = ((if (.notes | type) == "object" then .notes else {} end)
          + {"round-3 T6 S4: site override": "This copy is the shipped manifest plus one synthetic authored option the shipped copy neither declares nor pattern-covers (\($o)), so which copy answered to the name is observable rather than asserted."})
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
  capture_twice "$S" "${PAIR}2"
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
