#!/usr/bin/env bash
# Combined full or scoped Apply: exact update, preservation, native consumers and repeat.
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../../.." && pwd -P)"
cd "$ROOT/sandbox"
MODE="${IMPORTER_WOO_APPLY_MODE:-full}"
[[ "$MODE" = full || "$MODE" = scoped ]] || { printf 'invalid combined Apply mode\n' >&2; exit 1; }
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }
pass() { printf 'ok: %s\n' "$*"; }
PAIR="${IMPORTER_WOO_PAIR:?unique owned pair required}"
PORT1="${IMPORTER_WOO_PORT1:?even port required}" PORT2="${IMPORTER_WOO_PORT2:?successor port required}"
export WPRISM_SOURCE_ROOT="$ROOT" WPRISM_EXPECTED_SOURCE_SHA="${WPRISM_EXPECTED_SOURCE_SHA:?}"
[ "$(git rev-parse HEAD)" = "$WPRISM_EXPECTED_SOURCE_SHA" ] && [ -z "$(git status --porcelain)" ] || fail 'exact clean candidate required'
export WPRISM_PAIR="$PAIR" WPRISM_PORT1="$PORT1" WPRISM_PORT2="$PORT2" WPRISM_CODEBIND_PLUGIN=''
export WPRISM_ARTIFACT_LIBRARY_ROOT="$ROOT" CONF_PAIR="$PAIR" COMPOSE_PROJECT_NAME="wprism-$PAIR"
SCENARIO_ROOT="$ROOT/integration-scenarios/importer-woocommerce-customers"
. tests/lib/pair_live_ownership.sh
. tests/lib/private_command_capture.sh
. tests/lib/conformance_private_command.sh
. conformance/asserts.sh
. bin/fetch-artifact.sh
WPRISM_ARTIFACT_PARTICIPANTS=$(artifact_library_scenario_participants "$SCENARIO_ROOT/scenario.json")
export WPRISM_ARTIFACT_PARTICIPANTS
pair_live_ownership_prepare "$PAIR" "$PORT1" "$PORT2" 'Importer Woo full Apply' importer-woo-apply
PAIR_COMPOSE=(docker compose -p "wprism-$PAIR" -f pair.yml -f pair.artifacts.yml)
wp_side() { local side="$1"; shift; "${PAIR_COMPOSE[@]}" run --rm -T -v "$SCENARIO_ROOT/fixtures:/wprism-scenario:ro" "cli$side" wp "$@"; }
sink=$(umask 077; mktemp -d "$ROOT/sandbox/tmp/importer-woo-native-premise.XXXXXX")
printf 'Native premise evidence: %s\n' "$sink"
combo_capture() {
  local label="$1" result=0 suffix; shift
  [[ "$label" =~ ^[a-z][a-z0-9_-]*$ ]] || fail 'unsafe premise stage'
  for suffix in stdout stderr exit; do (umask 077; set -C; : > "$sink/$label.$suffix"); done
  wprism_private_capture_stage "$sink" "$label" "$@" || result=$?
  [ "$result" = 0 ] || fail "$label failed; inspect retained private streams"
  if [ "${1:-}" = candidate ]; then
    php "$ROOT/adapter-packages/users-customers-import-export-for-wp-woocommerce/fixtures/settings-evidence.php" admit-command "$sink/$label" "$PAIR" "$3" 0
  else
  php -r 'require $argv[1]."/sandbox/tests/lib/PrivateCommandOutput.php"; WPrismTest\PrivateCommandOutput::readBytes($argv[2], "/^ ?Container wprism-".$argv[3]."-cli[12]-run-[a-f0-9]{12} (Creating|Created) *$/D", WPrismTest\EvidenceSizeProfile::NATIVE_DATABASE);' "$ROOT" "$sink/$label" "$PAIR"
  fi
  pass "$label"
}
CAPSULE=/var/www/html/wp-content/mu-plugins/adapter-packages/users-customers-import-export-for-wp-woocommerce/fixtures
SCENARIO=/wprism-scenario
native() { local side="$1" fixture="$2"; shift 2; wp_side "$side" --require="$CAPSULE/admin-context.php" --user=admin eval-file "$fixture" "$@" --use-include; }
bash bin/pair.sh list
pair_live_ownership_acquire mariadb
pair_live_ownership_up --artifacts --headless
for side in 1 2; do
  combo_capture "cron$side" wp_side "$side" config set DISABLE_WP_CRON true --raw
  combo_capture "empty$side" wp_side "$side" site empty --yes
  for spec in users-customers-import-export-for-wp-woocommerce:2.7.5 woocommerce:11.0.1; do
    slug=${spec%:*}; version=${spec#*:}
    artifact=$(fetch_artifact "$slug" "$version" "cli$side")
    combo_capture "install$side-$slug" wp_side "$side" plugin install "$artifact" --activate
  done
  role=source; [ "$side" = 1 ] || role=target
  combo_capture "load-order-set$side" native "$side" "$SCENARIO/load-order-native.php" set "$role"
  combo_capture "load-order-before$side" native "$side" "$SCENARIO/load-order-native.php" observe "$role"
  combo_capture "hpos$side" establish_woocommerce_hpos wp_side "$side" --require="$CAPSULE/admin-context.php" --user=admin
  role=source; [ "$side" = 1 ] || role=target
  combo_capture "settings$side" native "$side" "$CAPSULE/settings-native.php" "setup-$role"
  combo_capture "exports$side" native "$side" "$CAPSULE/templates-native.php" "setup-$role"
  combo_capture "imports$side" native "$side" "$CAPSULE/import-templates-native.php" "setup-$role"
  combo_capture "customers$side" native "$side" "$SCENARIO/customers-native.php" "seed-$role"
  combo_capture "inputs$side" native "$side" "$SCENARIO/customers-native.php" "inputs-$role"
  combo_capture "save-export$side" native "$side" "$SCENARIO/templates-native.php" save export 'Selected users'
  combo_capture "save-import$side" native "$side" "$SCENARIO/templates-native.php" save import 'Reusable input mapping'
done
candidate() { local side="$1" verb="$2"; shift 2; conformance_private_command "cli$side" "$verb" wp_side "$side" wprism "$verb" "$@"; }
combo_capture catalog-attribute native 1 "$SCENARIO/catalog-native.php" attribute-source
combo_capture catalog-seed native 1 "$SCENARIO/catalog-native.php" seed-source
combo_capture configure native 1 "$SCENARIO/apply-native.php" configure
pair_live_ownership_repo_host
R1="$PAIR_LIVE_OWNERSHIP_SITE1" R2="$PAIR_LIVE_OWNERSHIP_SITE2"
cp site-repo.gitignore.template "$R1/.gitignore"
git -C "$R1" init -q -b evidence
git -C "$R2" init -q -b evidence
combo_capture binding1 establish_core_environment_bindings wp_side /siterepo admin@example.test "http://${PAIR}1.invalid" "http://${PAIR}1.invalid" 1
combo_capture baseline-capture candidate 1 capture --repo=/siterepo --format=json
git -C "$R1" add site.wprism.json .gitignore state media
git -C "$R1" -c user.name=wprism -c user.email=wprism@example.test commit -qm 'Combined native fixture baseline'
git -C "$R2" fetch -q "$R1" evidence
git -C "$R2" merge -q --ff-only FETCH_HEAD
combo_capture binding2 establish_core_environment_bindings wp_side /siterepo admin@example.test "http://${PAIR}2.invalid" "http://${PAIR}2.invalid" 2
combo_capture input-bind native 2 "$CAPSULE/import-templates-native.php" bind
combo_capture baseline-apply candidate 2 apply --repo=/siterepo --adopt-by-slug=posts,terms,tables --default-author=admin --format=json
combo_capture catalog-stock native 2 "$SCENARIO/catalog-native.php" stock-target
# Evidence recapture must not replace immutable transferred source bytes with
# target-derived timestamps: scope contracts bind the exact compiled artifact.
combo_capture baseline-recapture candidate 2 capture --repo=/siterepo --out=/siterepo/.tmp-importer-woo-baseline --format=json
pair_live_ownership_repo_host
combo_capture baseline-convergence php "$SCENARIO_ROOT/fixtures/repository-convergence.php" "$R1" "$R2" "$R2/.tmp-importer-woo-baseline"
combo_capture baseline-state php "$ROOT/adapter-packages/users-customers-import-export-for-wp-woocommerce/fixtures/settings-evidence.php" snapshot "$R1"
for kind in export import; do
  combo_capture "edit-$kind" native 1 "$CAPSULE/dirty-target-native.php" "$kind" source
done
combo_capture changed-capture candidate 1 capture --repo=/siterepo --format=json
pair_live_ownership_repo_host
git -C "$R1" add state
git -C "$R1" -c user.name=wprism -c user.email=wprism@example.test commit -qm 'Update both native template batch settings'
git -C "$R2" fetch -q "$R1" evidence
git -C "$R2" merge -q --ff-only FETCH_HEAD
combo_capture desired-state php "$ROOT/adapter-packages/users-customers-import-export-for-wp-woocommerce/fixtures/settings-evidence.php" snapshot "$R1"
combo_capture changed-plan candidate 2 plan --repo=/siterepo --format=json
. "$SCENARIO_ROOT/fixtures/observe.sh"
files_side() {
  local side="$1"
  "${PAIR_COMPOSE[@]}" run --rm -T -v "$ROOT:/wprism-evidence:ro" "cli$side" php -r '
require "/wprism-evidence/sandbox/tests/lib/FilesystemTreeEvidence.php";
$trees = [];
foreach (["webtoffee_export", "webtoffee_import"] as $name) $trees[$name] = WPrismTest\FilesystemTreeEvidence::capture("/var/www/html/wp-content", $name);
echo json_encode($trees, JSON_THROW_ON_ERROR), "\n";
'
}
repository_image() {
  php -r 'require $argv[1]."/sandbox/tests/lib/FilesystemTreeEvidence.php"; $trees=[]; foreach(["state","site.wprism.json","media"] as $name) $trees[$name]=WPrismTest\FilesystemTreeEvidence::capture($argv[2],$name); echo json_encode($trees,JSON_THROW_ON_ERROR),"\n";' "$ROOT" "$R2"
}
catalog_observe() { wp_side 2 --user=admin eval-file "$SCENARIO/catalog-native.php" observe --use-include; }
apply_image() {
  combo_capture "$1-catalog" catalog_observe
  combo_database_observe 2 "$1"
  combo_capture "$1-files" files_side 2
  pair_live_ownership_repo_host
  combo_capture "$1-state" repository_image
}
revision=$(git -C "$R2" rev-parse HEAD)
apply_image apply-before
if [ "$MODE" = scoped ]; then
  export_uuid=$(php -r '$ids=[]; foreach(glob($argv[1]."/state/tables/wt_iew_mapping_template/*.json") as $path) { $row=json_decode(file_get_contents($path),true,flags:JSON_THROW_ON_ERROR); if($row["columns"]["name"]==="Selected users" && $row["columns"]["template_type"]==="export" && $row["columns"]["item_type"]==="user") $ids[]=$row["uuid"]; } if(count($ids)!==1) exit(1); echo $ids[0];' "$R1")
  # Contract resolution belongs to the isolated host control plane; ordinary
  # plugin-loaded WP-CLI refuses it before compilation (Cli::scope).
  php -r '$config=["envs"=>["source"=>["transport"=>"docker","compose_file"=>$argv[2],"service"=>"cli1","repo_path"=>"/siterepo"]]]; file_put_contents($argv[1],json_encode($config,JSON_THROW_ON_ERROR));' "$sink/envs.json" "$ROOT/sandbox/pair.yml"
  combo_capture export-scope "$ROOT/cli/wprism" --envs-file="$sink/envs.json" scope source --roots="table:wt_iew_mapping_template:$export_uuid" --contract --format=json
  # Scope stdout is public canonical evidence; transport/private diagnostics stay
  # in the retained capture streams. Only that contract enters the target repo.
  cp "$sink/export-scope.stdout" "$R2/.tmp-importer-woo-export-scope.json"
  chmod 0644 "$R2/.tmp-importer-woo-export-scope.json"
  combo_capture scoped-plan candidate 2 plan --repo=/siterepo --scope-contract=/siterepo/.tmp-importer-woo-export-scope.json --format=json
fi
for phase in update repeat; do
  before=apply-before; after=apply-after
  if [ "$phase" = repeat ]; then before=apply-after; after=repeat-after; fi
  begun=$(date +%s)
  if [ "$MODE" = scoped ]; then
    combo_capture "$phase-apply" candidate 2 apply --repo=/siterepo --scope-contract=/siterepo/.tmp-importer-woo-export-scope.json --request-id=importer-woo-export-batch --format=json
  else
    combo_capture "$phase-apply" candidate 2 apply --repo=/siterepo --revision="$revision" --default-author=admin --format=json
  fi
  ended=$(date +%s)
  combo_capture "$phase-window" printf '{"before":%s,"after":%s}\n' "$begun" "$ended"
  apply_image "$after"
  php "$SCENARIO_ROOT/fixtures/apply-check.php" "$sink" "$PAIR" "$before" "$after" "$phase" "$revision" "$MODE"
done
if [ "$MODE" = scoped ]; then
  combo_capture remaining-plan candidate 2 plan --repo=/siterepo --format=json
  php "$SCENARIO_ROOT/fixtures/apply-check.php" "$sink" "$PAIR" apply-before repeat-after remaining "$revision" "$MODE"
  # Completing the protected pending import is a separate window; it permits
  # final native consumers and canonical recapture after scoped preservation.
  combo_capture remainder-apply candidate 2 apply --repo=/siterepo --revision="$revision" --default-author=admin --format=json
fi
for kind in export import; do
  name='Selected users'; fixture="$CAPSULE/templates-native.php"
  if [ "$kind" = import ]; then name='Reusable input mapping'; fixture="$CAPSULE/import-templates-native.php"; fi
  combo_capture "$kind-reopen" native 2 "$fixture" reopen "$name"
  combo_capture "$kind-consume" native 2 "$SCENARIO/templates-native.php" consume "$kind" "$name"
done
combo_capture catalog-final catalog_observe
php -r 'require $argv[1]."/fixtures/catalog-evidence.php"; require $argv[1]."/fixtures/database-evidence.php"; $read=static fn($label)=>json_decode(WPrismTest\PrivateCommandOutput::readObject($argv[2]."/".$label, ImporterWooDatabaseEvidence::transport($argv[3],2)),true,flags:JSON_THROW_ON_ERROR); ImporterWooCatalogEvidence::preserved($read("apply-before-catalog"),$read("catalog-final"));' "$SCENARIO_ROOT" "$sink" "$PAIR"
combo_capture customers-after native 2 "$SCENARIO/customers-native.php" observe
php "$SCENARIO_ROOT/fixtures/native-evidence.php" "$sink" "$PAIR"
combo_capture final-recapture candidate 2 capture --repo=/siterepo --out=/siterepo/.tmp-importer-woo-final --format=json
pair_live_ownership_repo_host
combo_capture final-convergence php "$SCENARIO_ROOT/fixtures/repository-convergence.php" "$R1" "$R2" "$R2/.tmp-importer-woo-final"
for side in 1 2; do
  role=source; [ "$side" = 1 ] || role=target
  combo_capture "load-order-after$side" native "$side" "$SCENARIO/load-order-native.php" observe "$role"
done
pair_live_ownership_complete "PASS: combined $MODE template Apply, native consumers, recapture and exact repeat preservation"
