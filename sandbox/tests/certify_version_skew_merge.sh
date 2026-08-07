#!/usr/bin/env bash
# Certify the cross-branch plugin-version-skew workflow (DUO-3228).
#
# DESIGN.md §3.4 requires an integration branch to merge plugin code first,
# run that version's migrations, re-capture the migrated canonical shape,
# and only then merge state authored on the older plugin branch. This fixture
# makes that ordering executable. It deliberately gives duo_loop_color two
# incompatible schemas:
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
set -euo pipefail
cd "$(dirname "$0")/.."   # -> sandbox/

say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }

command -v jq >/dev/null || fail "jq required"

PAIR=mergeskew
PORT1="${MERGESKEW_PORT1:-8864}"
PORT2="${MERGESKEW_PORT2:-8865}"
PLUGIN_DIR=duo-loop-demo
PLUGIN_FILE="code/wp-content/plugins/$PLUGIN_DIR/duo-loop-demo.php"
V1=1.0.0
V2=2.0.0
export DUO_PAIR="$PAIR" DUO_PORT1="$PORT1" DUO_PORT2="$PORT2" DUO_CODEBIND_PLUGIN="$PLUGIN_DIR"
COMPOSE=(docker compose -p "duo-$PAIR" -f pair.yml -f pair.codebind.yml)
wp1() { "${COMPOSE[@]}" run --rm -T cli1 wp "$@"; }
wp2() { "${COMPOSE[@]}" run --rm -T cli2 wp "$@"; }
GIT1=(git -C "siterepo/${PAIR}1" -c user.name=duo-v2 -c user.email=v2@example.test)
GIT2=(git -C "siterepo/${PAIR}2" -c user.name=duo-v1 -c user.email=v1@example.test)

set_plugin_version() {
  local file="$1" version="$2"
  sed -i.bak -E "s/^( \* Version:).*/\\1 $version/" "$file"
  rm -f "$file.bak"
}

read_settings() {
  if [ "$1" = 1 ]; then
    wp1 option get duo_loop_color --format=json
  else
    wp2 option get duo_loop_color --format=json
  fi
}

say "clean-room site repositories, with v1 plugin code present before the code-bind containers start"
bash bin/pair.sh reset "$PAIR"
git init --bare -b main "siterepo/origin-$PAIR.git" >/dev/null
mkdir -p "siterepo/${PAIR}1/code/wp-content/plugins/$PLUGIN_DIR"
cp "fixtures/$PLUGIN_DIR/duo-loop-demo.php" "siterepo/${PAIR}1/$PLUGIN_FILE"
set_plugin_version "siterepo/${PAIR}1/$PLUGIN_FILE" "$V1"
cat > "siterepo/${PAIR}1/site.duo.json" <<'EOF'
{
  "manifests": ["core", "duo-loop-demo"],
  "policy": {
    "options": {},
    "post_meta": {},
    "post_types": ["post", "page", "attachment"],
    "taxonomies": ["category"]
  },
  "spec_version": 2
}
EOF
cp site-repo.gitignore.template "siterepo/${PAIR}1/.gitignore"
git -C "siterepo/${PAIR}1" init -q -b main
git -C "siterepo/${PAIR}1" remote add origin "../origin-$PAIR.git"
"${GIT1[@]}" add -A
"${GIT1[@]}" commit -qm "baseline code: duo-loop-demo v$V1"
"${GIT1[@]}" push -qu origin main
git clone -q "siterepo/origin-$PAIR.git" "siterepo/${PAIR}2"

bash bin/pair.sh up "$PAIR" "$PORT1" "$PORT2" --codebind "$PLUGIN_DIR" --headless

say "baseline both environments on active v1 code and identical scalar authored state"
wp1 site empty --yes >/dev/null
wp2 site empty --yes >/dev/null
wp1 plugin activate "$PLUGIN_DIR" >/dev/null
wp2 plugin activate "$PLUGIN_DIR" >/dev/null
wp1 option update duo_loop_color blue >/dev/null
wp2 option update duo_loop_color blue >/dev/null
wp1 duo capture --repo=/siterepo >/dev/null
"${GIT1[@]}" add -A
"${GIT1[@]}" commit -qm "baseline state: v1 scalar color blue"
"${GIT1[@]}" push -q origin main
"${GIT2[@]}" pull -q origin main
wp2 duo deploy --repo=/siterepo --format=json >/dev/null
wp2 duo apply --repo=/siterepo --adopt-by-slug=terms --default-author=admin --format=json >/dev/null
[ "$(read_settings 1 | jq -r .)" = blue ] || fail "env1 baseline is not scalar blue"
[ "$(read_settings 2 | jq -r .)" = blue ] || fail "env2 baseline is not scalar blue"
pass "baseline: both environments run v$V1 and canonical duo_loop_color is the scalar 'blue'"

say "state-v1 branch: env2 authors green while still running v1"
"${GIT2[@]}" checkout -qb state-v1 main
wp2 option update duo_loop_color green >/dev/null
wp2 duo capture --repo=/siterepo >/dev/null
jq -e '.records.duo_loop_color.value == "green"' "siterepo/${PAIR}2/state/options/core.json" >/dev/null \
  || fail "state-v1 capture did not preserve the v1 scalar schema"
"${GIT2[@]}" add -A
"${GIT2[@]}" commit -qm "state-v1: editorial color blue to green"
"${GIT2[@]}" push -qu origin state-v1
pass "state-v1 carries only the older-schema editorial change"

say "code-v2 branch: add a v2-only explicit migration, without capturing state"
"${GIT1[@]}" checkout -qb code-v2 main
set_plugin_version "siterepo/${PAIR}1/$PLUGIN_FILE" "$V2"
cat >> "siterepo/${PAIR}1/$PLUGIN_FILE" <<'PHP'

// DUO-3228 version-skew fixture: v2 migrates the authored color from the
// v1 scalar shape into an explicitly versioned object. Kept as an explicit
// command so the certification can prove migration ordering.
if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('duo-loop migrate', function () {
        $current = get_option('duo_loop_color', null);
        if (is_array($current) && (int) ($current['schema'] ?? 0) === 2) {
            WP_CLI::line(wp_json_encode(['migrated' => false, 'settings' => $current]));
            return;
        }
        if (!is_string($current)) {
            WP_CLI::error('duo_loop_color is not a v1 scalar; refusing lossy migration');
        }
        $next = ['label' => $current, 'schema' => 2];
        update_option('duo_loop_color', $next);
        WP_CLI::line(wp_json_encode(['migrated' => true, 'settings' => $next]));
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
LIVE_V1=$(wp1 eval 'echo get_file_data(WP_PLUGIN_DIR . "/duo-loop-demo/duo-loop-demo.php", ["Version" => "Version"])["Version"];' 2>&1 | tail -1)
[ "$LIVE_V1" = "$V2" ] || fail "env1 did not see merged v2 code (got '$LIVE_V1')"
[ "$(read_settings 1 | jq -r .)" = blue ] || fail "state changed before the explicit v2 migration"
pass "code merged independently; database is still visibly in the v1 scalar shape"

say "integration ordering step 2: run the merged code's migration, then reconcile/re-baseline code"
MIG1=$(wp1 duo-loop migrate 2>&1 | tail -1)
echo "$MIG1" | jq -e '.migrated == true and .settings == {"label":"blue","schema":2}' >/dev/null \
  || fail "env1 v2 migration did not produce the expected object: $MIG1"
DEPLOY1=$(wp1 duo deploy --repo=/siterepo --force-code-drift --format=json | tail -1)
echo "$DEPLOY1" | jq -e --arg old "$V1" --arg new "$V2" \
  '.code_drift | any(.plugin == "duo-loop-demo/duo-loop-demo.php" and .recorded_version == $old and .installed_version == $new)' >/dev/null \
  || fail "deploy did not report the accepted v1-to-v2 code transition: $DEPLOY1"
pass "migration ran under v2; deploy reported (not hid) the accepted code-version transition"

say "integration ordering step 3: re-capture the migrated v2 canonical shape BEFORE state merge"
wp1 duo capture --repo=/siterepo >/dev/null
jq -e '.records.duo_loop_color.value == {"label":"blue","schema":2}' "siterepo/${PAIR}1/state/options/core.json" >/dev/null \
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
jq --indent 4 '.records.duo_loop_color.value.label = "green"' "siterepo/${PAIR}1/state/options/core.json" > "siterepo/${PAIR}1/.tmp-options.json"
mv "siterepo/${PAIR}1/.tmp-options.json" "siterepo/${PAIR}1/state/options/core.json"
jq -e '.records.duo_loop_color.value == {"label":"green","schema":2}' "siterepo/${PAIR}1/state/options/core.json" >/dev/null \
  || fail "resolution lost either v2 schema or v1 editorial value"
"${GIT1[@]}" add state/options/core.json
"${GIT1[@]}" commit -qm "merge state-v1 after migration (resolve green in v2 schema)"
"${GIT1[@]}" push -q origin main
pass "integration revision contains v2 code plus green expressed in v2 state"

say "materialize the resolved integration revision on env1"
REV=$("${GIT1[@]}" rev-parse HEAD)
APPLY1=$(wp1 duo apply --repo=/siterepo --adopt-by-slug=terms --default-author=admin --revision="$REV" --format=json | tail -1)
echo "$APPLY1" | jq -e '.canary == "clean"' >/dev/null || fail "env1 apply canary was not clean: $APPLY1"
read_settings 1 | jq -e '. == {"label":"green","schema":2}' >/dev/null \
  || fail "env1 did not materialize the resolved v2 state"
pass "env1 runs v2 with the state-v1 editorial change preserved"

say "env2 follows the same code-first boundary: checkout merged code, migrate v1 green, then apply"
"${GIT2[@]}" fetch -q origin
"${GIT2[@]}" checkout -q main
"${GIT2[@]}" merge -q --ff-only origin/main
LIVE_V2=$(wp2 eval 'echo get_file_data(WP_PLUGIN_DIR . "/duo-loop-demo/duo-loop-demo.php", ["Version" => "Version"])["Version"];' 2>&1 | tail -1)
[ "$LIVE_V2" = "$V2" ] || fail "env2 did not see merged v2 code (got '$LIVE_V2')"
MIG2=$(wp2 duo-loop migrate 2>&1 | tail -1)
echo "$MIG2" | jq -e '.migrated == true and .settings == {"label":"green","schema":2}' >/dev/null \
  || fail "env2 migration did not carry its v1 green value into v2: $MIG2"
DEPLOY2=$(wp2 duo deploy --repo=/siterepo --force-code-drift --format=json | tail -1)
echo "$DEPLOY2" | jq -e --arg old "$V1" --arg new "$V2" \
  '.code_drift | any(.plugin == "duo-loop-demo/duo-loop-demo.php" and .recorded_version == $old and .installed_version == $new)' >/dev/null \
  || fail "env2 deploy did not report the accepted v1-to-v2 transition: $DEPLOY2"
wp2 duo capture --repo=/siterepo >/dev/null
[ -z "$("${GIT2[@]}" status --porcelain)" ] \
  || fail "env2's required post-migration re-capture differs from the resolved integration revision: $("${GIT2[@]}" status --short)"
pass "env2 post-migration re-capture matches the resolved integration revision byte-for-byte"
APPLY2=$(wp2 duo apply --repo=/siterepo --adopt-by-slug=terms --default-author=admin --revision="$REV" --format=json | tail -1)
echo "$APPLY2" | jq -e '.canary == "clean"' >/dev/null || fail "env2 apply canary was not clean: $APPLY2"
read_settings 2 | jq -e '. == {"label":"green","schema":2}' >/dev/null \
  || fail "env2 did not materialize the resolved v2 state"
pass "env2 migrated before state apply and reached the same resolved value"

say "final convergence and lint: canonical(env1) == canonical(env2)"
wp1 duo capture --repo=/siterepo --out=/siterepo/.tmp-skew1 >/dev/null
wp2 duo capture --repo=/siterepo --out=/siterepo/.tmp-skew2 >/dev/null
diff -r "siterepo/${PAIR}1/.tmp-skew1" "siterepo/${PAIR}2/.tmp-skew2" \
  || fail "post-version-skew environments did not re-capture byte-identically"
LINT1=$(wp1 duo lint --repo=/siterepo --format=json | tail -1)
LINT2=$(wp2 duo lint --repo=/siterepo --format=json | tail -1)
echo "$LINT1" | jq -e 'length == 0' >/dev/null || fail "env1 lint findings: $LINT1"
echo "$LINT2" | jq -e 'length == 0' >/dev/null || fail "env2 lint findings: $LINT2"
rm -rf "siterepo/${PAIR}1/.tmp-skew1" "siterepo/${PAIR}2/.tmp-skew2"
pass "both environments are byte-identical and lint-clean"

printf '\n\033[1;32m✔ VERSION-SKEW MERGE CERTIFICATION PASSED\033[0m\n'

say "cleanup: destroy the mergeskew pair (green run; failures leave it inspectable)"
bash bin/pair.sh destroy "$PAIR"
pass "mergeskew pair destroyed"
