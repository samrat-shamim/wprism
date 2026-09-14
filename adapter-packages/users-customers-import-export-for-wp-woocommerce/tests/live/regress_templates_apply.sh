#!/usr/bin/env bash
# Native template Save/reopen and CSV consumption after full and scoped public Apply.
set -euo pipefail
PACKAGE_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
export WPRISM_ARTIFACT_PACKAGE="${PACKAGE_ROOT##*/}"
# Capsule source dependencies are BASH_SOURCE-relative. Normalize entry before
# changing cwd so both root-relative and absolute invocations retain that ABI.
if [[ "${BASH_SOURCE[0]}" != /* ]]; then
  exec bash "$PACKAGE_ROOT/tests/live/regress_templates_apply.sh" "$@"
fi
REPO_ROOT="$(cd "$PACKAGE_ROOT/../.." && pwd -P)"
cd "$REPO_ROOT/sandbox"
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }
pass() { printf 'ok: %s\n' "$*"; }
PAIR="${IMPORTER_TEMPLATES_PAIR:?unique owned pair required}"
PORT1="${IMPORTER_TEMPLATES_PORT1:?even port required}"
PORT2="${IMPORTER_TEMPLATES_PORT2:?successor port required}"
EXPECTED_SHA="${WPRISM_EXPECTED_SOURCE_SHA:?exact candidate SHA required}"
[ "$(git rev-parse HEAD)" = "$EXPECTED_SHA" ] && [ -z "$(git status --porcelain=v1 --untracked-files=all)" ] || fail 'native evidence requires the clean exact candidate'
export WPRISM_SOURCE_ROOT="$REPO_ROOT" WPRISM_EXPECTED_SOURCE_SHA="$EXPECTED_SHA"
export WPRISM_PAIR="$PAIR" WPRISM_PORT1="$PORT1" WPRISM_PORT2="$PORT2" WPRISM_CODEBIND_PLUGIN=''
export WPRISM_ARTIFACT_LIBRARY_ROOT="$REPO_ROOT" CONF_PAIR="$PAIR"
export COMPOSE_PROJECT_NAME="wprism-$PAIR"
. tests/lib/pair_live_ownership.sh
. tests/lib/private_command_capture.sh
. tests/lib/conformance_private_command.sh
. conformance/asserts.sh
pair_live_ownership_prepare "$PAIR" "$PORT1" "$PORT2" 'Importer native template Apply' wprism-importer-templates
COMPOSE=(docker compose -p "wprism-$PAIR" -f pair.yml)
PAIR_COMPOSE=("${COMPOSE[@]}")
wp_side() { local side="$1"; shift; "${COMPOSE[@]}" run --rm -T "cli$side" wp "$@"; }
candidate() { local side="$1" verb="$2"; shift 2; conformance_private_command "cli$side" "$verb" wp_side "$side" wprism "$verb" "$@"; }
host_apply() { conformance_private_command cli2 apply "$REPO_ROOT/cli/wprism" --envs-file="$sink/envs.json" apply target "$@"; }
mkdir -p "$REPO_ROOT/sandbox/tmp"
sink=$(umask 077; mktemp -d "$REPO_ROOT/sandbox/tmp/importer-templates-native.XXXXXX")
printf 'Retained native template streams for %s: %s\n' "$EXPECTED_SHA" "$sink"
capture() { local name="$1"; shift; capture_expected "$name" 0 "$@"; }
capture_expected() {
  local name="$1" expected="$2" result=0 suffix verb=''; shift 2
  if [ "${1:-}" = candidate ]; then verb="$3"; fi
  if [ "${1:-}" = host_apply ]; then verb=apply; fi
  for suffix in stdout stderr exit; do (umask 077; set -C; : > "$sink/$name.$suffix"); done
  wprism_private_capture_stage "$sink" "$name" "$@" || result=$?
  [ "$result" -eq "$expected" ] || fail "$name exited $result (expected $expected); retained $sink/$name"
  assert_no_php_runtime_diagnostics "$name stdout" "$(cat "$sink/$name.stdout")"
  php "$PACKAGE_ROOT/fixtures/settings-evidence.php" admit-command "$sink/$name" "$PAIR" "$verb" "$expected"
  printf 'ok: %s\n' "$name"
}
zip="${IMPORTER_TEMPLATES_ZIP:?local locked Importer 2.7.5 zip required}"
[[ "$zip" = /* ]] && [ -f "$zip" ] || fail 'absolute native artifact path required'
[ "$(shasum -a 256 "$zip" | cut -d ' ' -f 1)" = 6b7bd053960bee782e900688dac0cfed9df2519a2a0cdf72f65cf47d4b77e4a2 ] || fail 'wrong locked Importer artifact'
WPRISM_DB_ENGINE=mariadb bash bin/pair.sh list
pair_live_ownership_acquire mariadb
pair_live_ownership_up
fixture_root=/var/www/html/wp-content/mu-plugins/adapter-packages/users-customers-import-export-for-wp-woocommerce/fixtures
fixture="$fixture_root/settings-native.php"
native_side() { local side="$1"; shift; wp_side "$side" --require="$fixture_root/admin-context.php" eval-file "$fixture" "$@" --use-include --user=admin; }
wp_conf1() { wp_side 1 "$@"; }
for side in 1 2; do
  capture "cron$side" wp_side "$side" config set DISABLE_WP_CRON true --raw
  capture "empty$side" wp_side "$side" site empty --yes
  capture "install$side" "${COMPOSE[@]}" run --rm -T -v "$zip:/importer.zip:ro" "cli$side" wp plugin install /importer.zip --activate
  role=source; [ "$side" = 1 ] || role=target
  capture "setup$side" native_side "$side" "setup-$role"
done
. "$(dirname "${BASH_SOURCE[0]}")/../conformance/seed.sh"
pair_live_ownership_repo_host
R1="$PAIR_LIVE_OWNERSHIP_SITE1" R2="$PAIR_LIVE_OWNERSHIP_SITE2"
cp site-repo.gitignore.template "$R1/.gitignore"
git -C "$R1" init -q -b evidence
git -C "$R2" init -q -b evidence
capture binding1 establish_core_environment_bindings wp_side /siterepo admin@example.test "http://localhost:$PORT1" "http://localhost:$PORT1" 1
capture baseline-capture candidate 1 capture --repo=/siterepo --format=json
. "$(dirname "${BASH_SOURCE[0]}")/../conformance/capture-check.sh"
git -C "$R1" add site.wprism.json .gitignore state
git -C "$R1" -c user.name=wprism -c user.email=wprism@example.test commit -qm 'Importer native settings baseline'
git -C "$R2" fetch -q "$R1" evidence
git -C "$R2" merge -q --ff-only FETCH_HEAD
capture binding2 establish_core_environment_bindings wp_side /siterepo admin@example.test "http://localhost:$PORT2" "http://localhost:$PORT2" 2
capture baseline-apply candidate 2 apply --repo=/siterepo --adopt-by-slug=posts,terms --default-author=admin --format=json
template_side() { local side="$1"; shift; wp_side "$side" --require="$fixture_root/admin-context.php" eval-file "$fixture_root/templates-native.php" "$@" --use-include --user=admin; }
for side in 1 2; do
  role=source; [ "$side" = 1 ] || role=target
  capture "templates-setup$side" template_side "$side" "setup-$role"
done
capture templates-source template_side 1 observe
capture templates-capture candidate 1 capture --repo=/siterepo --format=json
pair_live_ownership_repo_host
git -C "$R1" add state
git -C "$R1" -c user.name=wprism -c user.email=wprism@example.test commit -qm 'Native saved export templates'
git -C "$R2" fetch -q "$R1" evidence
git -C "$R2" merge -q --ff-only FETCH_HEAD
capture templates-before template_side 2 observe
capture templates-plan candidate 2 plan --repo=/siterepo --format=json
capture templates-apply candidate 2 apply --repo=/siterepo --adopt-by-slug=tables --format=json
capture templates-after template_side 2 observe
for name in 'Selected users' 'Selected users copy'; do
  stem=original; [ "$name" = 'Selected users' ] || stem=copy
  capture "$stem-reopen" template_side 2 reopen "$name"
  capture "$stem-export" template_side 2 export "$name"
done
capture templates-recapture candidate 2 capture --repo=/siterepo --format=json
pair_live_ownership_repo_host
diff -r "$R1/state" "$R2/state" || fail 'full template recapture changed canonical state'
capture templates-resave template_side 2 resave 'Selected users'
capture resave-recapture candidate 2 capture --repo=/siterepo --format=json
pair_live_ownership_repo_host
diff -r "$R1/state" "$R2/state" || fail 'native resave changed canonical template intent'
capture source-rename template_side 1 rename 'Selected users'
capture renamed-capture candidate 1 capture --repo=/siterepo --format=json
capture renamed-source template_side 1 observe
pair_live_ownership_repo_host
git -C "$R1" add state
git -C "$R1" -c user.name=wprism -c user.email=wprism@example.test commit -qm 'Native template rename and changed header'
git -C "$R2" fetch -q "$R1" evidence
git -C "$R2" merge -q --ff-only FETCH_HEAD
php -r '$c=["envs"=>[]]; foreach(["source"=>"cli1","target"=>"cli2"] as $name=>$service) $c["envs"][$name]=["transport"=>"docker","compose_file"=>$argv[2],"service"=>$service,"repo_path"=>"/siterepo"]; file_put_contents($argv[1],json_encode($c,JSON_THROW_ON_ERROR));' "$sink/envs.json" "$REPO_ROOT/sandbox/pair.yml"
uuid=$(php -r '$ids=[]; foreach(glob($argv[1]."/state/tables/wt_iew_mapping_template/*.json") as $path) { $f=json_decode(file_get_contents($path),true,flags:JSON_THROW_ON_ERROR); if($f["columns"]["name"]==="Renamed selection") $ids[]=$f["uuid"]; } if(count($ids)!==1) exit(1); echo $ids[0];' "$R1")
capture renamed-scope "$REPO_ROOT/cli/wprism" --envs-file="$sink/envs.json" scope source --roots="table:wt_iew_mapping_template:$uuid" --contract --format=json
capture renamed-before template_side 2 observe
capture renamed-plan "$REPO_ROOT/cli/wprism" --envs-file="$sink/envs.json" plan target --scope-contract="$sink/renamed-scope.stdout" --format=json
capture renamed-apply host_apply --scope-contract="$sink/renamed-scope.stdout" --request-id=importer-template-rename --format=json
capture renamed-after template_side 2 observe
capture renamed-repeat host_apply --scope-contract="$sink/renamed-scope.stdout" --request-id=importer-template-rename --format=json
capture renamed-stable template_side 2 observe
capture renamed-reopen template_side 2 reopen 'Renamed selection'
capture renamed-export template_side 2 export 'Renamed selection'
capture renamed-recapture candidate 2 capture --repo=/siterepo --format=json
pair_live_ownership_repo_host
diff -r "$R1/state" "$R2/state" || fail 'scoped rename recapture changed canonical state'

import_side() { local side="$1"; shift; wp_side "$side" --require="$fixture_root/admin-context.php" eval-file "$fixture_root/import-templates-native.php" "$@" --use-include --user=admin; }
for side in 1 2; do
  role=source; [ "$side" = 1 ] || role=target
  capture "imports-setup$side" import_side "$side" "setup-$role"
done
capture imports-source template_side 1 observe
capture imports-capture candidate 1 capture --repo=/siterepo --format=json
pair_live_ownership_repo_host
git -C "$R1" add state
git -C "$R1" -c user.name=wprism -c user.email=wprism@example.test commit -qm 'Native saved import templates'
git -C "$R2" fetch -q "$R1" evidence
git -C "$R2" merge -q --ff-only FETCH_HEAD
capture imports-before template_side 2 observe
capture imports-missing candidate 2 plan --repo=/siterepo --format=json
capture imports-bind import_side 2 bind
capture imports-provisioned template_side 2 observe
capture imports-plan candidate 2 plan --repo=/siterepo --format=json
capture imports-apply candidate 2 apply --repo=/siterepo --adopt-by-slug=tables --format=json
capture imports-after template_side 2 observe
for name in 'Reusable input mapping' 'Reusable input copy' 'Draft input mapping'; do
  stem=original; [ "$name" != 'Reusable input copy' ] || stem=copy; [ "$name" != 'Draft input mapping' ] || stem=draft
  capture "import-$stem-reopen" import_side 2 reopen "$name"
done
capture imports-recapture candidate 2 capture --repo=/siterepo --format=json
pair_live_ownership_repo_host
diff -r "$R1/state" "$R2/state" || fail 'full import recapture changed canonical state'
capture imports-resave import_side 2 resave 'Reusable input mapping'
capture imports-resave-capture candidate 2 capture --repo=/siterepo --format=json
pair_live_ownership_repo_host
diff -r "$R1/state" "$R2/state" || fail 'native import resave changed canonical intent'
capture import-original-consume import_side 2 consume 'Reusable input mapping'
capture import-copy-consume import_side 2 consume 'Reusable input copy'
capture imports-rotate import_side 2 rotate
pair_live_ownership_repo_host
import_uuid=$(php -r '$ids=[]; foreach(glob($argv[1]."/state/tables/wt_iew_mapping_template/*.json") as $path) { $f=json_decode(file_get_contents($path),true,flags:JSON_THROW_ON_ERROR); if($f["columns"]["template_type"]==="import" && $f["columns"]["name"]==="Reusable input mapping") $ids[]=$f["uuid"]; } if(count($ids)!==1) exit(1); echo $ids[0];' "$R1")
capture imports-scope "$REPO_ROOT/cli/wprism" --envs-file="$sink/envs.json" scope source --roots="table:wt_iew_mapping_template:$import_uuid" --contract --format=json
capture rotation-before template_side 2 observe
capture rotation-plan "$REPO_ROOT/cli/wprism" --envs-file="$sink/envs.json" plan target --scope-contract="$sink/imports-scope.stdout" --format=json
capture rotation-apply host_apply --scope-contract="$sink/imports-scope.stdout" --request-id=importer-input-rotation --format=json
capture rotation-after template_side 2 observe
capture rotation-repeat host_apply --scope-contract="$sink/imports-scope.stdout" --request-id=importer-input-rotation --format=json
capture rotation-stable template_side 2 observe
capture rotation-consume import_side 2 consume 'Reusable input mapping'
capture rotation-recapture candidate 2 capture --repo=/siterepo --format=json
pair_live_ownership_repo_host
diff -r "$R1/state" "$R2/state" || fail 'native import jobs or scoped rotation changed canonical intent'
php "$PACKAGE_ROOT/fixtures/templates-evidence.php" "$sink" "$PAIR"
pair_live_ownership_complete 'REGRESS_IMPORTER_TEMPLATES_APPLY PASSED'
