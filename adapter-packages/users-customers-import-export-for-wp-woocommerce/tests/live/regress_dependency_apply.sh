#!/usr/bin/env bash
# Exact native activation/reinstallation and pre-mutation dependency refusals.
set -euo pipefail
PACKAGE_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
export WPRISM_ARTIFACT_PACKAGE="${PACKAGE_ROOT##*/}"
ROOT="$(cd "$PACKAGE_ROOT/../.." && pwd -P)"
cd "$ROOT/sandbox"
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }
PAIR="${IMPORTER_DEPENDENCY_PAIR:?unique owned pair required}"
PORT1="${IMPORTER_DEPENDENCY_PORT1:?even port required}"
PORT2="${IMPORTER_DEPENDENCY_PORT2:?successor port required}"
EXPECTED_SHA="${WPRISM_EXPECTED_SOURCE_SHA:?exact candidate SHA required}"
[ "$(git rev-parse HEAD)" = "$EXPECTED_SHA" ] && [ -z "$(git status --porcelain=v1 --untracked-files=all)" ] || fail 'native dependency evidence requires the clean exact candidate'
zip="${IMPORTER_DEPENDENCY_ZIP:?locked Importer 2.7.5 archive required}"
prior_zip="${IMPORTER_DEPENDENCY_PRIOR_ZIP:?locked Importer 2.7.4 archive required}"
[[ "$zip" = /* && "$prior_zip" = /* ]] && [ -f "$zip" ] && [ -f "$prior_zip" ] || fail 'absolute native archive paths required'
[ "$(shasum -a 256 "$zip" | cut -d ' ' -f 1)" = 6b7bd053960bee782e900688dac0cfed9df2519a2a0cdf72f65cf47d4b77e4a2 ] || fail 'wrong supported artifact'
[ "$(shasum -a 256 "$prior_zip" | cut -d ' ' -f 1)" = 3437f56048dccb3492ed280662cfbb74dd8d8ae50018624432e7cd26fd4d1221 ] || fail 'wrong refusal artifact'
export WPRISM_SOURCE_ROOT="$ROOT" WPRISM_ARTIFACT_LIBRARY_ROOT="$ROOT" CONF_PAIR="$PAIR"
export WPRISM_PAIR="$PAIR" WPRISM_PORT1="$PORT1" WPRISM_PORT2="$PORT2" WPRISM_CODEBIND_PLUGIN=''
export WPRISM_WP_IMAGE='wordpress@sha256:65919a9ca10940feb10d9400fead0d639bf86241f47c91e2b9ea4703aa8452cf'
export WPRISM_CLI_IMAGE='wordpress@sha256:2b5e9d4d3e51909dca1aaa4732e9f5e5bf0377c2114dbd8ff39f060bff202586'
. tests/lib/pair_live_ownership.sh
. tests/lib/private_command_capture.sh
. tests/lib/conformance_private_command.sh
. conformance/asserts.sh
pair_live_ownership_prepare "$PAIR" "$PORT1" "$PORT2" 'Importer dependency and lifecycle' wprism-importer-dependency
COMPOSE=(docker compose -p "wprism-$PAIR" -f pair.yml)
PAIR_COMPOSE=("${COMPOSE[@]}")
wp_side() { local side="$1"; shift; "${COMPOSE[@]}" run --rm -T "cli$side" wp "$@"; }
candidate() { local side="$1" verb="$2"; shift 2; conformance_private_command "cli$side" "$verb" wp_side "$side" wprism "$verb" "$@"; }
mkdir -p "$ROOT/sandbox/tmp"
sink=$(umask 077; mktemp -d "$ROOT/sandbox/tmp/importer-dependency-native.XXXXXX")
printf 'Native dependency evidence for %s: %s\n' "$EXPECTED_SHA" "$sink"
capture() { local name="$1"; shift; capture_expected "$name" 0 "$@"; }
capture_expected() {
  local name="$1" expected="$2" result=0 suffix verb=''; shift 2
  if [ "${1:-}" = candidate ]; then verb="$3"; fi
  for suffix in stdout stderr exit; do (umask 077; set -C; : > "$sink/$name.$suffix"); done
  wprism_private_capture_stage "$sink" "$name" "$@" || result=$?
  [ "$result" -eq "$expected" ] || fail "$name exited $result (expected $expected); retained $sink/$name"
  php "$PACKAGE_ROOT/fixtures/settings-evidence.php" admit-command "$sink/$name" "$PAIR" "$verb" "$expected"
  printf 'ok: %s\n' "$name"
}
snapshot() {
  local name="$1"
  capture "$name-tables" wp_side 2 db query 'SHOW FULL TABLES' --batch --raw --skip-column-names --quiet
  capture "$name-database" wp_side 2 db export - --single-transaction --skip-lock-tables --skip-add-locks \
    --skip-dump-date --order-by-primary --hex-blob --complete-insert --skip-extended-insert --quiet
  capture "$name-state" php "$PACKAGE_ROOT/fixtures/dependency-evidence.php" snapshot "$PAIR_LIVE_OWNERSHIP_SITE2"
}
refusal() {
  local name="$1" verb="$2" result=0 suffix
  snapshot "$name-before"
  for suffix in stdout stderr exit; do (umask 077; set -C; : > "$sink/$name-refusal.$suffix"); done
  wprism_private_capture_stage "$sink" "$name-refusal" candidate 2 "$verb" --repo=/siterepo --format=json || result=$?
  snapshot "$name-after"
  [ "$result" -eq 1 ] || fail "$name-refusal exited $result (expected 1); retained pre/postimages in $sink"
  php "$PACKAGE_ROOT/fixtures/settings-evidence.php" admit-command "$sink/$name-refusal" "$PAIR" "$verb" 1
  printf 'ok: %s-refusal\n' "$name"
}
WPRISM_DB_ENGINE=mariadb bash bin/pair.sh list
pair_live_ownership_acquire mariadb
pair_live_ownership_up
fixture=/var/www/html/wp-content/mu-plugins/adapter-packages/users-customers-import-export-for-wp-woocommerce/fixtures
settings() { local side="$1"; shift; wp_side "$side" --require="$fixture/admin-context.php" eval-file "$fixture/settings-native.php" "$@" --use-include --user=admin; }
templates() { local side="$1"; shift; wp_side "$side" --require="$fixture/admin-context.php" eval-file "$fixture/templates-native.php" "$@" --use-include --user=admin; }
status() { wp_side 2 --require="$fixture/admin-context.php" eval-file "$fixture/dependency-native.php" --use-include --user=admin; }
install() { local side="$1" archive="$2"; shift 2; "${COMPOSE[@]}" run --rm -T -v "$archive:/importer.zip:ro" "cli$side" wp plugin install /importer.zip "$@"; }
for side in 1 2; do
  capture "cron$side" wp_side "$side" config set DISABLE_WP_CRON true --raw
  capture "empty$side" wp_side "$side" site empty --yes
  capture "install$side" install "$side" "$zip"
done
capture activate-source wp_side 1 plugin activate users-customers-import-export-for-wp-woocommerce
capture settings-source settings 1 setup-source
capture save-source settings 1 save-source
capture templates-source templates 1 setup-source
pair_live_ownership_repo_host
R1="$PAIR_LIVE_OWNERSHIP_SITE1" R2="$PAIR_LIVE_OWNERSHIP_SITE2"
cp site-repo.gitignore.template "$R1/.gitignore"
git -C "$R1" init -q -b evidence
git -C "$R2" init -q -b evidence
capture binding1 establish_core_environment_bindings wp_side /siterepo admin@example.test "http://localhost:$PORT1" "http://localhost:$PORT1" 1
capture baseline-capture candidate 1 capture --repo=/siterepo --format=json
pair_live_ownership_repo_host
git -C "$R1" add .gitignore site.wprism.json state
git -C "$R1" -c user.name=wprism -c user.email=wprism@example.test commit -qm 'Native Importer dependency boundary'
git -C "$R2" fetch -q "$R1" evidence
git -C "$R2" merge -q --ff-only FETCH_HEAD
capture binding2 establish_core_environment_bindings wp_side /siterepo admin@example.test "http://localhost:$PORT2" "http://localhost:$PORT2" 2
capture installed-inactive status
capture initial-deploy candidate 2 deploy --repo=/siterepo --format=json
capture initial-status status
capture templates-target templates 2 setup-target
capture initial-apply candidate 2 apply --repo=/siterepo --adopt-by-slug=posts,terms,tables --default-author=admin --format=json
capture jobs settings 2 jobs
capture retained-before settings 2 raw-observe
capture repeat-deploy candidate 2 deploy --repo=/siterepo --format=json
capture deactivate wp_side 2 plugin deactivate users-customers-import-export-for-wp-woocommerce
capture inactive-status status
capture deactivated-native settings 2 raw-observe
refusal inactive apply
capture reactivated-deploy candidate 2 deploy --repo=/siterepo --format=json
capture reactivated-status status
capture reactivated-native settings 2 raw-observe
capture uninstall wp_side 2 plugin uninstall users-customers-import-export-for-wp-woocommerce --deactivate
capture missing-status status
capture uninstalled-native settings 2 raw-observe
refusal missing deploy
capture install-prior install 2 "$prior_zip"
capture prior-status status
refusal prior deploy
capture delete-prior wp_side 2 plugin delete users-customers-import-export-for-wp-woocommerce
capture reinstall install 2 "$zip"
capture reinstalled-deploy candidate 2 deploy --repo=/siterepo --format=json
capture reinstalled-status status
capture reinstalled-apply candidate 2 apply --repo=/siterepo --format=json
capture reinstalled-native settings 2 raw-observe
capture final-capture candidate 2 capture --repo=/siterepo --format=json
pair_live_ownership_repo_host
diff -r "$R1/state" "$R2/state" || fail 'native lifecycle changed canonical intent'
php "$PACKAGE_ROOT/fixtures/dependency-evidence.php" "$sink" "$PAIR"
pair_live_ownership_complete 'REGRESS_IMPORTER_DEPENDENCY_APPLY PASSED'
