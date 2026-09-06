#!/usr/bin/env bash
# Certify the cross-branch plugin-version-skew workflow (issue #3228, rebuilt at
# issue #3487).
#
# DESIGN.md §3.4 requires an integration branch to merge plugin code first,
# run that version's migrations, re-capture the migrated canonical shape,
# and only then merge state authored on the older plugin branch. This fixture
# makes that ordering executable. It deliberately gives one plugin-owned
# option two incompatible schemas:
#
#   v1: "blue"                       (scalar)
#   v2: {"label":"blue","schema":2} (object, after an explicit migration)
#
# A state-v1 branch changes blue -> green while a code-v2 branch adds the
# migration. The integration branch merges only code-v2, runs the migration,
# captures the v2 object, and then merges state-v1. Git must conflict on the
# one canonical options file; the resolution keeps v2's schema and v1's
# editorial value. Both environments then materialize the resolved revision
# and must re-capture byte-identically.
#
# WHY THIS FILE EXISTS TWICE OVER. The original (issue #3228) was built on the
# wprism-loop-demo demo manifest and its fixture plugin, and #478 deleted both;
# certify_merge.sh:56 recorded the scope as UNCOVERED with that reason, and
# this file is what retires that note. Every assertion of the deleted script
# is carried over — the ordering, the migration receipt, the reported (not
# hidden) v1->v2 code transition, the single scoped canonical conflict, the
# deliberate resolution, the byte-identical convergence and the clean lint.
#
# WHAT CHANGED IN THE REBUILD, and why each substitution is not a weakening:
#
#   - The plugin is wprism-agency-cpt, the sandbox's one retained fixture plugin
#     (sandbox/fixtures/wprism-agency-cpt/), shipped through the site repo's own
#     code/ tree exactly as wprism-loop-demo was. The fixture file on disk is
#     never edited: the pair's disposable site-repo COPY carries the version
#     bump and the migration command, so the committed fixture and
#     manifests/wprism-agency-cpt.json keep their bytes (AGENTS.md #2 — manifest
#     bytes are adapter identity, and six live suites pin this one).
#
#   - The migrated option is classified by this repository's OWN
#     site.wprism.json `policy.options`, not by a manifest. wprism-agency-cpt.json
#     declares only an `env` key and a `derived` projection, neither of which
#     can carry authored editorial state, and no shipped manifest may gain a
#     key for a test's convenience. Site policy is the supported route for
#     exactly this (spec/repo-format.md: "policy holds site-local
#     classification overrides ... it wins over manifests") and it is what a
#     real operator uses for a plugin whose shipped manifest does not
#     classify a key. The mechanic under test — capture/merge/apply of one
#     authored option whose VALUE SHAPE changes across plugin versions — is
#     identical either way; only who classified it moves.
#
#   - The version-skew signal is `code_drift` (Deploy::code_drift()), which
#     compares each active plugin's live header against the ledger baseline
#     the last capture/deploy recorded. It is manifest-independent by
#     construction (LifecyclePlanner.php:367 reads the ledger, not
#     version_ranges()), so dropping the deleted wprism-loop-demo-versioned pin
#     costs this scenario nothing. The version_range half of the same area is
#     covered on its own by sandbox/tests/live/regress_adapter_plugin_range.sh.
#
# Own pair (a3487sk 8986/8987 by default), headless, code-bound. Destroyed on
# a green run; a failed run leaves it up for inspection (docs/sandbox.md).
set -euo pipefail
cd "$(dirname "$0")/../.."   # -> sandbox/

say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }
. lib/pair_db.sh
pair_db_select_engine
. conformance/asserts.sh

command -v jq >/dev/null || fail "jq required"

PAIR="${MERGESKEW_PAIR:-a3487sk}"
PORT1="${MERGESKEW_PORT1:-8986}"
PORT2="${MERGESKEW_PORT2:-8987}"
PLUGIN_DIR=wprism-agency-cpt
PLUGIN_BASENAME="$PLUGIN_DIR/$PLUGIN_DIR.php"
PLUGIN_FILE="code/wp-content/plugins/$PLUGIN_DIR/$PLUGIN_DIR.php"
OPTION=wprism_agency_color
V1=1.0.0
V2=2.0.0
export WPRISM_PAIR="$PAIR" WPRISM_PORT1="$PORT1" WPRISM_PORT2="$PORT2" WPRISM_CODEBIND_PLUGIN="$PLUGIN_DIR"
COMPOSE=(docker compose -p "wprism-$PAIR" -f pair.yml -f pair.codebind.yml)
wp1() { "${COMPOSE[@]}" run --rm -T cli1 wp "$@"; }
wp2() { "${COMPOSE[@]}" run --rm -T cli2 wp "$@"; }
GIT1=(git -C "siterepo/${PAIR}1" -c user.name=wprism-v2 -c user.email=v2@example.test)
GIT2=(git -C "siterepo/${PAIR}2" -c user.name=wprism-v1 -c user.email=v1@example.test)

GREEN=0
cleanup() {
  if [ "$GREEN" = 1 ]; then
    bash bin/pair.sh destroy "$PAIR" >/dev/null 2>&1 || true
    rm -rf "siterepo/${PAIR}1" "siterepo/${PAIR}2" "siterepo/origin-$PAIR.git"
    printf '\033[1;32mok: pair %s destroyed and its site-repo trees removed\033[0m\n' "$PAIR"
  else
    printf '\033[1;33m(pair %s left up for inspection after a failed run)\033[0m\n' "$PAIR" >&2
  fi
}
trap cleanup EXIT

set_plugin_version() {
  local file="$1" version="$2"
  sed -i.bak -E "s/^( \* Version:).*/\\1 $version/" "$file"
  rm -f "$file.bak"
}

read_settings() {
  if [ "$1" = 1 ]; then
    wp1 option get "$OPTION" --format=json
  else
    wp2 option get "$OPTION" --format=json
  fi
}

live_plugin_version() { # live_plugin_version <1|2>
  local ev="wp$1"
  "$ev" eval "echo get_file_data(WP_PLUGIN_DIR . '/$PLUGIN_BASENAME', ['Version' => 'Version'])['Version'];" 2>&1 | tail -1
}

say "clean-room site repositories, with v1 plugin code present before the code-bind containers start"
# destroy-then-implicit-up rather than reset: pair.sh reset refuses a
# codebind-pinned pair by design (its own message prescribes this destroy),
# and a prior FAILED run deliberately leaves this pair up. A destroy of a
# nonexistent pair is a no-op, so a clean first run is unaffected.
bash bin/pair.sh destroy "$PAIR" >/dev/null 2>&1 || true
rm -rf "siterepo/${PAIR}1" "siterepo/${PAIR}2" "siterepo/origin-$PAIR.git"
git init --bare -b main "siterepo/origin-$PAIR.git" >/dev/null
mkdir -p "siterepo/${PAIR}1/code/wp-content/plugins/$PLUGIN_DIR"
cp "fixtures/$PLUGIN_DIR/$PLUGIN_DIR.php" "siterepo/${PAIR}1/$PLUGIN_FILE"
set_plugin_version "siterepo/${PAIR}1/$PLUGIN_FILE" "$V1"
# policy.options is what classifies this plugin's editorial key: the shipped
# manifest deliberately declares no authored option, and it may not gain one.
# `autoload: preserve` is not optional decoration — OptionGrammar::
# validate_option_storage() refuses an authored option that does not say how
# its wp_options row is stored ("insertion may never guess"), and the deleted
# wprism-loop-demo manifest carried the same declaration as a manifest-level
# `option_autoload`. Per-rule here, because site policy classifies exactly
# one name.
cat > "siterepo/${PAIR}1/site.wprism.json" <<EOF
{
  "manifests": ["core", "$PLUGIN_DIR"],
  "policy": {
    "options": {
      "$OPTION": {"class": "authored", "autoload": "preserve"}
    },
    "post_meta": {},
    "post_types": ["post", "page", "attachment", "project"],
    "taxonomies": ["category"]
  },
  "spec_version": 2
}
EOF
cp site-repo.gitignore.template "siterepo/${PAIR}1/.gitignore"
git -C "siterepo/${PAIR}1" init -q -b main
git -C "siterepo/${PAIR}1" remote add origin "../origin-$PAIR.git"
"${GIT1[@]}" add -A
"${GIT1[@]}" commit -qm "baseline code: $PLUGIN_DIR v$V1"
"${GIT1[@]}" push -qu origin main
git clone -q "siterepo/origin-$PAIR.git" "siterepo/${PAIR}2"

bash bin/pair.sh up "$PAIR" "$PORT1" "$PORT2" --codebind "$PLUGIN_DIR" --headless

say "baseline both environments on active v1 code and identical scalar authored state"
wp1 site empty --yes >/dev/null
wp2 site empty --yes >/dev/null
wp1 plugin activate "$PLUGIN_DIR" >/dev/null
wp2 plugin activate "$PLUGIN_DIR" >/dev/null
wp1 option update "$OPTION" blue >/dev/null
wp2 option update "$OPTION" blue >/dev/null
wp1 wprism capture --repo=/siterepo >/dev/null
"${GIT1[@]}" add -A
"${GIT1[@]}" commit -qm "baseline state: v1 scalar color blue"
"${GIT1[@]}" push -q origin main
"${GIT2[@]}" pull -q origin main
wp2 wprism deploy --repo=/siterepo --format=json >/dev/null
wp2 wprism apply --repo=/siterepo --adopt-by-slug=terms --default-author=admin --format=json >/dev/null
BASELINE_SETTINGS_1=$(read_settings 1)
require_observed_nonempty "env1 baseline settings" "$BASELINE_SETTINGS_1"
[ "$(jq -r . <<<"$BASELINE_SETTINGS_1")" = blue ] || fail "env1 baseline is not scalar blue"
BASELINE_SETTINGS_2=$(read_settings 2)
require_observed_nonempty "env2 baseline settings" "$BASELINE_SETTINGS_2"
[ "$(jq -r . <<<"$BASELINE_SETTINGS_2")" = blue ] || fail "env2 baseline is not scalar blue"
pass "baseline: both environments run v$V1 and canonical $OPTION is the scalar 'blue'"

say "state-v1 branch: env2 authors green while still running v1"
"${GIT2[@]}" checkout -qb state-v1 main
wp2 option update "$OPTION" green >/dev/null
wp2 wprism capture --repo=/siterepo >/dev/null
jq -e --arg k "$OPTION" '.records[$k].value == "green"' "siterepo/${PAIR}2/state/options/core.json" >/dev/null \
  || fail "state-v1 capture did not preserve the v1 scalar schema"
"${GIT2[@]}" add -A
"${GIT2[@]}" commit -qm "state-v1: editorial color blue to green"
"${GIT2[@]}" push -qu origin state-v1
pass "state-v1 carries only the older-schema editorial change"

say "code-v2 branch: add a v2-only explicit migration, without capturing state"
"${GIT1[@]}" checkout -qb code-v2 main
set_plugin_version "siterepo/${PAIR}1/$PLUGIN_FILE" "$V2"
cat >> "siterepo/${PAIR}1/$PLUGIN_FILE" <<PHP

// issue #3228/issue #3487 version-skew fixture: v2 migrates the authored color
// from the v1 scalar shape into an explicitly versioned object. Kept as an
// explicit command so the certification can prove migration ordering.
if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('wprism-agency migrate', function () {
        \$current = get_option('$OPTION', null);
        if (is_array(\$current) && (int) (\$current['schema'] ?? 0) === 2) {
            WP_CLI::line(wp_json_encode(['migrated' => false, 'settings' => \$current]));
            return;
        }
        if (!is_string(\$current)) {
            WP_CLI::error('$OPTION is not a v1 scalar; refusing lossy migration');
        }
        \$next = ['label' => \$current, 'schema' => 2];
        update_option('$OPTION', \$next);
        WP_CLI::line(wp_json_encode(['migrated' => true, 'settings' => \$next]));
    });
}
PHP
php -l "siterepo/${PAIR}1/$PLUGIN_FILE" >/dev/null
"${GIT1[@]}" add "$PLUGIN_FILE"
"${GIT1[@]}" commit -qm "code-v2: add scalar-to-object settings migration"
"${GIT1[@]}" push -qu origin code-v2
[ -z "$("${GIT1[@]}" diff --name-only main...code-v2 -- state)" ] \
  || fail "code-v2 unexpectedly contains canonical state changes"
pass "code-v2 is code-only; state-v1 is state-only"

say "integration ordering step 1: merge code-v2 into main FIRST"
"${GIT1[@]}" checkout -q main
"${GIT1[@]}" merge -q --no-ff code-v2 -m "merge code-v2 before state-v1"
LIVE_V1=$(live_plugin_version 1)
require_observed_nonempty "env1 live v2 plugin version" "$LIVE_V1"
[ "$LIVE_V1" = "$V2" ] || fail "env1 did not see merged v2 code (got '$LIVE_V1')"
PRE_MIGRATION_SETTINGS_1=$(read_settings 1)
require_observed_nonempty "env1 settings before explicit migration" "$PRE_MIGRATION_SETTINGS_1"
[ "$(jq -r . <<<"$PRE_MIGRATION_SETTINGS_1")" = blue ] || fail "state changed before the explicit v2 migration"
pass "code merged independently; database is still visibly in the v1 scalar shape"

say "integration ordering step 2: run the merged code's migration, then reconcile/re-baseline code"
MIG1=$(wp1 wprism-agency migrate 2>&1 | tail -1)
require_wprism_answered "env1 v2 migration" json "$MIG1"
echo "$MIG1" | jq -e '.migrated == true and .settings == {"label":"blue","schema":2}' >/dev/null \
  || fail "env1 v2 migration did not produce the expected object: $MIG1"
DEPLOY1=$(wp1 wprism deploy --repo=/siterepo --force-code-drift --format=json | tail -1)
require_wprism_answered "env1 v2 deploy" json "$DEPLOY1"
echo "$DEPLOY1" | jq -e --arg p "$PLUGIN_BASENAME" --arg old "$V1" --arg new "$V2" \
  '.code_drift | any(.plugin == $p and .recorded_version == $old and .installed_version == $new)' >/dev/null \
  || fail "deploy did not report the accepted v1-to-v2 code transition: $DEPLOY1"
pass "migration ran under v2; deploy reported (not hid) the accepted code-version transition"

say "integration ordering step 3: re-capture the migrated v2 canonical shape BEFORE state merge"
wp1 wprism capture --repo=/siterepo >/dev/null
jq -e --arg k "$OPTION" '.records[$k].value == {"label":"blue","schema":2}' \
  "siterepo/${PAIR}1/state/options/core.json" >/dev/null \
  || fail "post-migration capture is not the v2 object shape"
"${GIT1[@]}" add -A
"${GIT1[@]}" commit -qm "capture: v2-migrated canonical shape"
pass "repository now records the migration's v2 shape before older state is introduced"

say "integration ordering step 4: merge state-v1 — conflict must be scoped to canonical options"
"${GIT1[@]}" fetch -q origin state-v1
set +e
"${GIT1[@]}" merge origin/state-v1 >/dev/null 2>&1
MERGE_RC=$?
set -e
[ "$MERGE_RC" -ne 0 ] || fail "expected old scalar state vs migrated object state to conflict, but git merged silently"
CONFLICTS=$("${GIT1[@]}" status --porcelain | grep '^UU' || true)
[ "$CONFLICTS" = "UU state/options/core.json" ] \
  || fail "expected exactly one conflict on state/options/core.json, got: $CONFLICTS"
grep -q '^<<<<<<<' "siterepo/${PAIR}1/state/options/core.json" \
  || fail "options conflict lacks git markers"
pass "the version-skew semantic boundary surfaces as one reviewable canonical-file conflict"

say "resolve deliberately: keep v2 schema, carry forward v1 branch's editorial value"
"${GIT1[@]}" checkout --ours -- state/options/core.json
jq --indent 4 --arg k "$OPTION" '.records[$k].value.label = "green"' \
  "siterepo/${PAIR}1/state/options/core.json" > "siterepo/${PAIR}1/.tmp-options.json"
mv "siterepo/${PAIR}1/.tmp-options.json" "siterepo/${PAIR}1/state/options/core.json"
jq -e --arg k "$OPTION" '.records[$k].value == {"label":"green","schema":2}' \
  "siterepo/${PAIR}1/state/options/core.json" >/dev/null \
  || fail "resolution lost either v2 schema or v1 editorial value"
"${GIT1[@]}" add state/options/core.json
"${GIT1[@]}" commit -qm "merge state-v1 after migration (resolve green in v2 schema)"
"${GIT1[@]}" push -q origin main
pass "integration revision contains v2 code plus green expressed in v2 state"

say "materialize the resolved integration revision on env1"
REV=$("${GIT1[@]}" rev-parse HEAD)
APPLY1=$(wp1 wprism apply --repo=/siterepo --adopt-by-slug=terms --default-author=admin --revision="$REV" --format=json | tail -1)
require_wprism_answered "env1 v2 apply" json "$APPLY1"
echo "$APPLY1" | jq -e '.canary == "clean"' >/dev/null || fail "env1 apply canary was not clean: $APPLY1"
FINAL_SETTINGS_1=$(read_settings 1)
require_observed_nonempty "env1 settings after v2 apply" "$FINAL_SETTINGS_1"
jq -e '. == {"label":"green","schema":2}' <<<"$FINAL_SETTINGS_1" >/dev/null \
  || fail "env1 did not materialize the resolved v2 state"
pass "env1 runs v2 with the state-v1 editorial change preserved"

say "env2 follows the same code-first boundary: checkout merged code, migrate v1 green, then apply"
"${GIT2[@]}" fetch -q origin
"${GIT2[@]}" checkout -q main
"${GIT2[@]}" merge -q --ff-only origin/main
LIVE_V2=$(live_plugin_version 2)
require_observed_nonempty "env2 live v2 plugin version" "$LIVE_V2"
[ "$LIVE_V2" = "$V2" ] || fail "env2 did not see merged v2 code (got '$LIVE_V2')"
MIG2=$(wp2 wprism-agency migrate 2>&1 | tail -1)
require_wprism_answered "env2 v2 migration" json "$MIG2"
echo "$MIG2" | jq -e '.migrated == true and .settings == {"label":"green","schema":2}' >/dev/null \
  || fail "env2 migration did not carry its v1 green value into v2: $MIG2"
DEPLOY2=$(wp2 wprism deploy --repo=/siterepo --force-code-drift --format=json | tail -1)
require_wprism_answered "env2 v2 deploy" json "$DEPLOY2"
echo "$DEPLOY2" | jq -e --arg p "$PLUGIN_BASENAME" --arg old "$V1" --arg new "$V2" \
  '.code_drift | any(.plugin == $p and .recorded_version == $old and .installed_version == $new)' >/dev/null \
  || fail "env2 deploy did not report the accepted v1-to-v2 transition: $DEPLOY2"
wp2 wprism capture --repo=/siterepo >/dev/null
[ -z "$("${GIT2[@]}" status --porcelain)" ] \
  || fail "env2's required post-migration re-capture differs from the resolved integration revision: $("${GIT2[@]}" status --short)"
pass "env2 post-migration re-capture matches the resolved integration revision byte-for-byte"
APPLY2=$(wp2 wprism apply --repo=/siterepo --adopt-by-slug=terms --default-author=admin --revision="$REV" --format=json | tail -1)
require_wprism_answered "env2 v2 apply" json "$APPLY2"
echo "$APPLY2" | jq -e '.canary == "clean"' >/dev/null || fail "env2 apply canary was not clean: $APPLY2"
FINAL_SETTINGS_2=$(read_settings 2)
require_observed_nonempty "env2 settings after v2 apply" "$FINAL_SETTINGS_2"
jq -e '. == {"label":"green","schema":2}' <<<"$FINAL_SETTINGS_2" >/dev/null \
  || fail "env2 did not materialize the resolved v2 state"
pass "env2 migrated before state apply and reached the same resolved value"

say "final convergence and lint: canonical(env1) == canonical(env2)"
wp1 wprism capture --repo=/siterepo --out=/siterepo/.tmp-skew1 >/dev/null
wp2 wprism capture --repo=/siterepo --out=/siterepo/.tmp-skew2 >/dev/null
diff -r "siterepo/${PAIR}1/.tmp-skew1" "siterepo/${PAIR}2/.tmp-skew2" \
  || fail "post-version-skew environments did not re-capture byte-identically"
LINT1=$(wp1 wprism lint --repo=/siterepo --format=json | tail -1)
LINT2=$(wp2 wprism lint --repo=/siterepo --format=json | tail -1)
require_wprism_answered "env1 final lint" json "$LINT1"
require_wprism_answered "env2 final lint" json "$LINT2"
echo "$LINT1" | jq -e 'length == 0' >/dev/null || fail "env1 lint findings: $LINT1"
echo "$LINT2" | jq -e 'length == 0' >/dev/null || fail "env2 lint findings: $LINT2"
rm -rf "siterepo/${PAIR}1/.tmp-skew1" "siterepo/${PAIR}2/.tmp-skew2"
pass "both environments are byte-identical and lint-clean"

GREEN=1
printf '\n\033[1;32m✔ VERSION-SKEW MERGE CERTIFICATION PASSED\033[0m\n'
