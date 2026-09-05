#!/usr/bin/env bash
# Candidate-bound exact ACF/Polylang/Rank Math/WooCommerce product evidence.
# The source and target active-plugin orders deliberately reverse every plugin
# except Polylang, whose native pre-update filter forces itself first. This is
# pairwise-complete precedence evidence, not an install-order approximation.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../../../.." && pwd -P)"
cd "$ROOT/sandbox"

say() { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*" >&2; exit 1; }
. conformance/asserts.sh

assert_rmcombo_warning_free_capture() { # <what> <complete JSON capture stream>
  local last
  assert_wprism_json_required_environment "$1" "$2"
  ! grep -Eq '(^|[[:space:]])Warning:' <<<"$2" \
    || fail "$1 emitted a warning; the positive capture evidence is not clean"
  last=$(awk 'NF { line=$0 } END { print line }' <<<"$2")
  jq -e '.warnings == []' <<<"$last" >/dev/null \
    || fail "$1 returned a nonempty or malformed warning inventory"
}

assert_rmcombo_default_apply_ready() { # <what> <complete JSON apply stream>
  assert_wprism_apply_ready "$1" "$2"
  ! grep -Fq 'option default_product_cat' <<<"$2" \
    || fail "$1 did not settle the portable Woo default without an option warning"
}

capture_rmcombo_native_json() { # <what> <command> [args...]
  local answer
  capture_wprism_json_success answer "$1" "${@:2}"
  printf '%s\n' "$answer"
}

assert_rmcombo_host_native_json() { # shared capture callback; bindings live only within its synchronous caller
  jq -Rse --arg pair "$rmcombo_host_pair" --arg service "$rmcombo_host_service" '
    split("\n") | map(select(length > 0))
    | map(select(test("^ ?Container wprism-" + $pair + "-" + $service + "-run-[a-f0-9]+ (Creating|Created) *$") | not))
    | length == 1 and (.[0] | fromjson | type == "object")
  ' <<<"$2" >/dev/null 2>&1 || fail "$1 did not return one exact native JSON object at the bound site"
}

assert_rmcombo_host_rank_math_state() { # <wp1|wp2> <pair> <active|inactive> <present|absent>
  [ "$#" -eq 4 ] || fail 'Rank Math host premise requires explicit site, pair, status and table presence'
  local side="$1" rmcombo_host_pair="$2" status="$3" presence="$4" plugin table rmcombo_host_service
  case "$side" in wp1) rmcombo_host_service=cli1 ;; wp2) rmcombo_host_service=cli2 ;; *) fail 'Rank Math host premise has an unknown site' ;; esac
  [[ "$rmcombo_host_pair" =~ ^[a-z][a-z0-9]{2,23}$ ]] || fail 'Rank Math host premise has an invalid pair'
  case "$status:$presence" in active:present|inactive:absent) ;; *) fail 'Rank Math host premise has an unknown lifecycle/schema state' ;; esac
  # 65d92 stopped at nonexistent `plugin is-inactive`. Inactivity and absence
  # require positive observations, not the failure status or empty output of
  # an unsupported command / failed SHOW read. Keep both complete transports.
  capture_wprism_json_checked plugin 'Rank Math host plugin status' assert_rmcombo_host_native_json \
    "$side" plugin get seo-by-rank-math --fields=name,status,version --format=json
  jq -e --arg status "$status" \
    '. == {name:"seo-by-rank-math",status:$status,version:"1.0.277.2"}' \
    <<<"$plugin" >/dev/null 2>&1 || fail 'Rank Math host plugin is not the exact expected release and status'
  capture_wprism_json_checked table 'Rank Math host derived-table presence' assert_rmcombo_host_native_json "$side" eval '
global $wpdb;
$table = $wpdb->prefix . "rank_math_redirections_cache";
$suppressed = $wpdb->suppress_errors(true);
$wpdb->last_error = "";
try {
    $found = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $wpdb->esc_like($table)));
    if ((string) $wpdb->last_error !== "" || ($found !== null && $found !== $table)) {
        throw new RuntimeException("Rank Math host table-presence read is incomplete");
    }
    echo json_encode(["table" => $table, "present" => $found !== null], JSON_THROW_ON_ERROR);
} finally {
    $wpdb->suppress_errors($suppressed);
}
'
  jq -e --arg presence "$presence" \
    '. == {table:"wp_rank_math_redirections_cache",present:($presence == "present")}' \
    <<<"$table" >/dev/null 2>&1 || fail 'Rank Math host derived table is not in the exact expected state'
}

assert_rmcombo_one_json() { # shared capture callback; host commands have no Compose prelude
  jq -se 'length == 1 and (.[0] | type == "object")' <<<"$2" >/dev/null 2>&1 \
    || fail "$1 did not return exactly one JSON object"
}

assert_rmcombo_recovery_web_state() { # <true|false>; the leased pair owns this exact target
  local running="$1" observed
  case "$running" in true|false) ;; *) fail 'unknown recovery web-process state' ;; esac
  capture_wprism_json_checked observed 'Rank Math recovery web-process state' assert_rmcombo_one_json \
    docker container inspect "wprism-${PAIR}-wp2-1" --format '{{json .}}'
  jq -e --arg project "wprism-$PAIR" --argjson running "$running" '
    .Name == ("/" + $project + "-wp2-1") and
    .Config.Labels["com.docker.compose.project"] == $project and
    .Config.Labels["com.docker.compose.service"] == "wp2" and
    .State.Running == $running and .State.Paused == false and .State.Restarting == false
  ' <<<"$observed" >/dev/null 2>&1 || fail 'the owned recovery target is not in the exact web-process state'
}

rmcombo_recovery_control() {
  local observed rmcombo_host_pair="$PAIR" rmcombo_host_service=cli2
  capture_wprism_json_checked observed 'Rank Math recovery control observation' assert_rmcombo_host_native_json wp2 eval '
require_once "/siterepo/.wprism/control/recovery-runtime/CanonicalJson.php";
require_once "/siterepo/.wprism/control/recovery-runtime/AtomicStore.php";
require_once "/siterepo/.wprism/control/recovery-runtime/ProtocolLock.php";
require_once "/siterepo/.wprism/control/recovery-runtime/ProviderSettlementIntent.php";
global $wpdb;
$presence = [];
foreach (\WPrism\Ledger::OWN_TABLES as $table) {
    $presence[] = \WPrism\DatabaseTablePresence::base_table_exists($wpdb->prefix . $table);
}
if (in_array(true, $presence, true) && in_array(false, $presence, true)) {
    throw new RuntimeException("combined recovery ledger inventory is partial");
}
$installed = !in_array(false, $presence, true);
echo json_encode([
    "ledger_present" => $installed,
    "provider" => \WPrism\Recovery\ProviderSettlementIntent::recoveryStatus("/siterepo/.wprism/control"),
    "schema_clear" => !$installed || \WPrism\Ledger::kv_get("schema_settlement_in_progress") === null,
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
'
  printf '%s\n' "$observed"
}

for command in docker git jq mktemp php; do
  command -v "$command" >/dev/null 2>&1 || fail "$command required"
done

PAIR="${RANK_MATH_COMBO_PAIR:-}"
PORT1_RAW="${RANK_MATH_COMBO_PORT1:-}"
PORT2_RAW="${RANK_MATH_COMBO_PORT2:-}"
EXPECTED_SHA="${RANK_MATH_COMBO_EXPECTED_SOURCE_SHA:-}"
HEAD="$(git -C "$ROOT" --no-optional-locks rev-parse --verify 'HEAD^{commit}')" \
  || fail 'Rank Math combination evidence has no resolvable Git HEAD'
[[ "$PAIR" =~ ^[a-z][a-z0-9]{2,23}$ ]] \
  || fail 'RANK_MATH_COMBO_PAIR is required and must be a unique lowercase 3..24 character pair name'
case "$PAIR" in
  db|sandbox) fail "Rank Math combination pair '$PAIR' is reserved by the shared sandbox" ;;
esac
[[ "$PORT1_RAW" =~ ^[0-9]+$ && "$PORT2_RAW" =~ ^[0-9]+$ ]] \
  || fail 'RANK_MATH_COMBO_PORT1 and RANK_MATH_COMBO_PORT2 are required decimal ports'
PORT1=$((10#$PORT1_RAW))
PORT2=$((10#$PORT2_RAW))
(( PORT1 >= 8900 && PORT1 <= 65534 && PORT1 % 2 == 0 && PORT2 == PORT1 + 1 )) \
  || fail 'RANK_MATH_COMBO_PORT1 must be even and >=8900; RANK_MATH_COMBO_PORT2 must be its successor'
[[ "$EXPECTED_SHA" =~ ^[0-9a-f]{40}$ ]] \
  || fail 'RANK_MATH_COMBO_EXPECTED_SOURCE_SHA must be the exact lowercase 40-character candidate SHA'
[ "$EXPECTED_SHA" = "$HEAD" ] || fail "candidate SHA $EXPECTED_SHA does not equal checkout HEAD $HEAD"
SOURCE_STATUS="$(git -C "$ROOT" --no-optional-locks status --porcelain=v1 --untracked-files=all)" \
  || fail 'could not inspect Rank Math combination source cleanliness'
[ -z "$SOURCE_STATUS" ] || fail 'Rank Math combination evidence requires a clean candidate checkout'
docker info >/dev/null 2>&1 || fail 'Docker daemon is unavailable'

export WPRISM_SOURCE_ROOT="$ROOT" WPRISM_EXPECTED_SOURCE_SHA="$EXPECTED_SHA"
export WPRISM_PAIR="$PAIR" WPRISM_PORT1="$PORT1" WPRISM_PORT2="$PORT2"
export WPRISM_CODEBIND_PLUGIN='' WPRISM_DB_ENGINE='mariadb' WPRISM_DB_HOST='wprism-shared-db'
. tests/lib/pair_live_ownership.sh
pair_live_ownership_prepare "$PAIR" "$PORT1" "$PORT2" \
  'Rank Math combination' 'wprism-rmcombo'
COMPOSE=(docker compose -p "wprism-$PAIR" -f pair.yml -f pair.artifacts.yml)
PAIR_COMPOSE=("${COMPOSE[@]}")
. lib/host_orchestrator.sh
R1="siterepo/${PAIR}1"
R2="siterepo/${PAIR}2"
ORIGIN="siterepo/origin-$PAIR.git"
SCENARIO="$ROOT/integration-scenarios/rank-math-commerce-multilingual/scenario.json"
TMP_ROOT="$PAIR_LIVE_OWNERSHIP_TMP_ROOT"
WPRISM_HOST_REGISTRY=''
WPRISM_HOST_REGISTRY="$TMP_ROOT/host-envs.json"
for path in "$WPRISM_HOST_REGISTRY"; do
  [ ! -e "$path" ] && [ ! -L "$path" ] \
    || fail "chosen Rank Math combination scratch target already exists: $path"
done
wprism_host_registry_create "$WPRISM_HOST_REGISTRY" "$ROOT/sandbox/pair.yml" "$PAIR"
[ "$(pair_live_ownership_mode_of "$TMP_ROOT")" = 700 ] \
  || fail 'private Rank Math combination scratch mode is not 0700'
[ "$(pair_live_ownership_mode_of "$WPRISM_HOST_REGISTRY")" = 600 ] \
  || fail 'private Rank Math combination host registry mode is not 0600'

. "$ROOT/sandbox/tests/lib/private_command_capture.sh"

host_wprism_combo() { # <wp1|wp2> <verb> [args...]
  local side="$1"
  shift
  if [ "$1" != deploy ]; then
    wprism_host_call "$ROOT/cli/wprism" "$WPRISM_HOST_REGISTRY" "wprism-$PAIR" \
      "${PAIR}${side#wp}" "$@"
    return
  fi
  rmcombo_private_command "$side" deploy wprism_host_call "$ROOT/cli/wprism" "$WPRISM_HOST_REGISTRY" \
    "wprism-$PAIR" "${PAIR}${side#wp}" "$@"
}

rmcombo_private_command() { # <wp1|wp2> <deploy|apply> <command argv...>
  local side="$1" command="$2"
  shift 2
  case "$side:$command" in wp[12]:deploy|wp[12]:apply) ;; *) fail 'unknown combined private command binding' ;; esac
  local -a snapshot_argv=(rmcombo_command_private "$side" snapshot)
  local -a collect_argv=(rmcombo_command_private "$side" capture-file)
  local -a validate_argv=(rmcombo_command_private_accept "$side")
  mkdir -p "$ROOT/sandbox/tmp" || fail 'could not prepare combined command diagnostic parent'
  wprism_private_command_capture "$ROOT/sandbox/tmp/wprism-rmcombo-$command.$PAIR" \
    snapshot_argv collect_argv validate_argv -- "$@"
}

rmcombo_private_command_inventory() {
  printf '%s\n' '["lifecycle-status","schema-status","promotion-begin","checkpoint","provider-settlement-begin","lifecycle-retire","lifecycle-activate","schema-settle","lifecycle-settle","provider-settlement-complete","apply"]'
}

rmcombo_command_private() { # <wp1|wp2> <snapshot|capture-file> [owned baseline stdout path]
  local side="$1" mode="$2" service baseline=null
  case "$side" in wp1) service=cli1 ;; wp2) service=cli2 ;; *) fail 'unknown combined diagnostic site' ;; esac
  case "$mode:$#" in
    snapshot:2) ;;
    capture-file:3) baseline=$(cat "$3") || fail 'could not read combined private baseline'; mode=capture ;;
    *) fail 'unknown combined diagnostic operation' ;;
  esac
  "${COMPOSE[@]}" run --rm -T \
    --volume "$ROOT/sandbox/tests/lib/PrivateRefusalReceipt.php:/wprism-test/PrivateRefusalReceipt.php:ro" \
    --entrypoint php "$service" -r '
require "/wprism-test/PrivateRefusalReceipt.php";
$commands = json_decode($argv[3], true, 16, JSON_THROW_ON_ERROR);
$baseline = json_decode($argv[2], true, 16, JSON_THROW_ON_ERROR);
if ($argv[1] === "capture" && (!is_array($baseline) || array_keys($baseline) !== $commands)) {
    throw new RuntimeException("combined command diagnostic baseline is not its exact command inventory");
}
$result = [];
foreach ($commands as $command) {
    $result[$command] = $argv[1] === "snapshot"
        ? \WPrismTest\PrivateRefusalReceipt::diagnosticSnapshot("/siterepo/.wprism/refusals", $command)
        : \WPrismTest\PrivateRefusalReceipt::diagnosticNewRecords("/siterepo/.wprism/refusals", $baseline[$command], $command);
}
echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
' "$mode" "$baseline" "$(rmcombo_private_command_inventory)"
}

rmcombo_command_private_accept() { # <wp1|wp2> <owned capture stem>
  local side="$1" stem="$2" stdout stderr rmcombo_host_pair="$PAIR" rmcombo_host_service
  case "$side" in wp1) rmcombo_host_service=cli1 ;; wp2) rmcombo_host_service=cli2 ;; *) fail 'unknown combined diagnostic site' ;; esac
  stdout=$(cat "$stem.stdout") && stderr=$(cat "$stem.stderr") \
    || fail 'could not read combined command private transport'
  assert_no_php_runtime_diagnostics 'combined command private transport' "$stdout"$'\n'"$stderr"
  assert_rmcombo_host_native_json 'combined command private transport' "$stdout"$'\n'"$stderr"
  # JSON shape alone accepts an unrelated {} and only discovers the missing
  # baseline after the protected command ran. Validate the exact declared
  # inventory and use the shared baseline grammar BEFORE granting that turn.
  php -r '
require $argv[1]."/sandbox/tests/lib/PrivateRefusalReceipt.php";
$commands = json_decode($argv[3], true, 16, JSON_THROW_ON_ERROR);
$rows = json_decode(file_get_contents($argv[2].".stdout"), true, 32, JSON_THROW_ON_ERROR);
if (!is_array($rows) || array_keys($rows) !== $commands) throw new RuntimeException("combined diagnostic command inventory is incomplete");
foreach ($commands as $command) {
    if (!is_string($rows[$command])) throw new RuntimeException("combined diagnostic command payload is not serialized JSON");
    if (basename($argv[2]) === "baseline") {
        \WPrismTest\PrivateRefusalReceipt::validateDiagnosticBaseline($rows[$command], $command);
        continue;
    }
    $receipt = json_decode($rows[$command], true, 32, JSON_THROW_ON_ERROR);
    if (basename($argv[2]) !== "private" || !is_array($receipt)
        || ($receipt["command"] ?? null) !== $command
        || ($receipt["format"] ?? null) !== "wprism-private-refusal-diagnostic/v1"
        || ($receipt["purpose"] ?? null) !== "diagnostic_only"
        || ($receipt["verified"] ?? null) !== false) {
        throw new RuntimeException("combined diagnostic result is not an unverified command-bound capture");
    }
}
' "$ROOT" "$stem" "$(rmcombo_private_command_inventory)"
}
WP_CLI_MEMORY_LIMIT=512M
wp_side() { # <side> <wp args...>
  local side="$1"; shift
  # b60de223 proved host settlement then lost direct Apply's private cause at
  # teardown. The same shared lifecycle now owns every native Apply attempt,
  # including expected refusals and retries, before any JSON caller can fail.
  if [ "${1-}:${2-}" = wprism:apply ]; then
    rmcombo_private_command "wp$side" apply "${COMPOSE[@]}" run --rm -T --entrypoint php "cli$side" \
      -d "memory_limit=$WP_CLI_MEMORY_LIMIT" /usr/local/bin/wp "$@"
    return
  fi
  "${COMPOSE[@]}" run --rm -T --entrypoint php "cli$side" \
    -d "memory_limit=$WP_CLI_MEMORY_LIMIT" /usr/local/bin/wp "$@"
}
wp1() { wp_side 1 "$@"; }
wp2() { wp_side 2 "$@"; }
. bin/fetch-artifact.sh
WPRISM_ARTIFACT_PARTICIPANTS="$(artifact_library_scenario_participants "$SCENARIO")" \
  || fail 'Rank Math combination participant record is malformed'
export WPRISM_ARTIFACT_PARTICIPANTS
validate_artifact_library || fail 'Rank Math combination artifact library validation failed'

install_exact() { # <side> <slug> <version>
  local side="$1" slug="$2" version="$3" artifact actual
  artifact=$(fetch_artifact "$slug" "$version" "cli$side")
  "wp$side" plugin install "$artifact" --activate --force >/dev/null
  actual=$("wp$side" plugin get "$slug" --field=version)
  [ "$actual" = "$version" ] || fail "side $side: $slug is $actual, expected exact $version"
}

configure_rank_math() { # <wp1|wp2> <source|target>
  local side="$1" role="$2"
  "$side" option update rank_math_registration_skip 1 >/dev/null
  "$side" option update rank_math_is_configured 1 >/dev/null
  "$side" eval '
$role = (string) getenv("WPRISM_RMCOMBO_ROLE");
$desired = $role === "source"
    ? ["link-counter", "redirections", "rich-snippet"]
    : ["redirections", "rich-snippet"];
$stored = array_values(array_unique(array_map("strval", (array) get_option("rank_math_modules", []))));
if ($stored !== []) {
    RankMath\Helper::update_modules(array_fill_keys($stored, "off"));
}
RankMath\Helper::update_modules(array_fill_keys($desired, "on"));
$actual = array_values(array_unique(array_map("strval", (array) get_option("rank_math_modules", []))));
if ($actual !== $desired) {
    throw new RuntimeException("Rank Math native module lifecycle did not persist the exact requested set");
}
' --exec="putenv('WPRISM_RMCOMBO_ROLE=$role');" >/dev/null
}

rank_math_readiness() { # <wp1|wp2> <source|target>
  local side="$1" role="$2"
  capture_rmcombo_native_json "Rank Math combination $role Rank Math readiness" "$side" eval '
global $wpdb;
$role = (string) getenv("WPRISM_RMCOMBO_ROLE");
$expectedModules = $role === "source"
    ? ["link-counter", "redirections", "rich-snippet"]
    : ["redirections", "rich-snippet"];
$requiredTables = $role === "source"
    ? ["rank_math_internal_links", "rank_math_internal_meta", "rank_math_redirections", "rank_math_redirections_cache"]
    : ["rank_math_redirections", "rank_math_redirections_cache"];
$modules = array_values(array_unique(array_map("strval", (array) get_option("rank_math_modules", []))));
$activeModules = array_values(RankMath\Helper::get_active_modules());
if ($modules !== $expectedModules) {
    throw new RuntimeException("Rank Math module state changed before the independent readiness readback");
}
if ($activeModules !== $expectedModules) {
    throw new RuntimeException("Rank Math manager did not accept the exact requested active module set");
}
$tables = [];
foreach ($requiredTables as $suffix) {
    $table = $wpdb->prefix . $suffix;
    $tables[$suffix] = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table;
}
echo wp_json_encode([
    "active_modules" => $activeModules,
    "modules" => $modules,
    "role" => $role,
    "tables" => $tables,
    "version" => defined("RANK_MATH_VERSION") ? RANK_MATH_VERSION : null,
], JSON_UNESCAPED_SLASHES);
' --exec="putenv('WPRISM_RMCOMBO_ROLE=$role');"
}

create_languages() { # <wp1|wp2>
  local side="$1"
  "$side" eval '
$languages = [
    ["locale"=>"en_US","slug"=>"en","name"=>"English","rtl"=>0,"term_group"=>0,"flag"=>"us"],
    ["locale"=>"de_DE","slug"=>"de","name"=>"Deutsch 東京","rtl"=>0,"term_group"=>1,"flag"=>"de"],
];
foreach ($languages as $args) {
    $result = isset(PLL()->model->languages)
        ? PLL()->model->languages->add($args)
        : (new PLL_Admin_Model(PLL()->options))->add_language($args);
    if (is_wp_error($result) && $result->has_errors()) {
        throw new RuntimeException($result->get_error_message());
    }
}
' >/dev/null
  # Polylang persists its model at shutdown. Configure translated Woo types
  # in a fresh request so that shutdown cannot overwrite the authored option.
  "$side" eval '
$options = get_option("polylang");
if (!is_array($options)) throw new RuntimeException("Polylang options are absent");
$options["default_lang"] = "en";
$options["browser"] = false;
$options["force_lang"] = 1;
$options["hide_default"] = false;
$options["media_support"] = 1;
$options["post_types"] = ["product"];
$options["redirect_lang"] = false;
$options["rewrite"] = true;
$options["taxonomies"] = ["product_cat"];
$options["sync"] = ["taxonomies", "post_meta", "post_date"];
update_option("polylang", $options);
' >/dev/null
}

active_plugin_order() { # <wp1|wp2>
  local side="$1" active
  active=$(capture_rmcombo_native_json 'Rank Math combination active-plugin order observation' \
    "$side" option get active_plugins --format=json)
  jq -c 'map(split("/")[0])' <<<"$active"
}

establish_woocommerce_default_category() { # <wp1|wp2> <source|target>
  local side="$1" role="$2"
  capture_rmcombo_native_json "Rank Math combination $role Woo default fixture" "$side" eval '
global $wpdb;
$role = (string) getenv("WPRISM_RMCOMBO_ROLE");
if (!in_array($role, ["source", "target"], true)) {
    throw new RuntimeException("unknown Woo default-category fixture role");
}
$positiveId = static function ($value, string $where): int {
    if (is_int($value) && $value > 0) return $value;
    if (is_string($value) && preg_match("/^[1-9][0-9]*$/D", $value) === 1) {
        $id = (int) $value;
        if ($id > 0 && (string) $id === $value) return $id;
    }
    throw new RuntimeException("$where is not an exact positive integer");
};
$installerDefault = $positiveId(get_option("default_product_cat", null), "Woo installer default");
$name = $role === "source"
    ? "Portable authored default category 東京"
    : "Target stale matching default category";
$created = wp_insert_term($name, "product_cat", ["slug"=>"rmcombo-default-product-category"]);
if (is_wp_error($created)) throw new RuntimeException($created->get_error_message());
$termId = $positiveId($created["term_id"] ?? null, "created default term id");
$termTaxonomyId = $positiveId($created["term_taxonomy_id"] ?? null, "created default term-taxonomy id");
$wpdb->last_error = "";
$physical = $wpdb->get_row($wpdb->prepare(
    "SELECT t.term_id,tt.term_taxonomy_id,tt.term_id AS taxonomy_term_id,tt.taxonomy " .
    "FROM {$wpdb->terms} t JOIN {$wpdb->term_taxonomy} tt ON tt.term_id=t.term_id " .
    "WHERE t.term_id=%d AND tt.term_taxonomy_id=%d",
    $termId,
    $termTaxonomyId
), ARRAY_A);
if ($wpdb->last_error !== "" || !is_array($physical)
    || array_keys($physical) !== ["term_id", "term_taxonomy_id", "taxonomy_term_id", "taxonomy"]
    || (int) $physical["term_id"] !== $termId
    || (int) $physical["term_taxonomy_id"] !== $termTaxonomyId
    || (int) $physical["taxonomy_term_id"] !== $termId
    || $physical["taxonomy"] !== "product_cat"
    || $termId !== $termTaxonomyId) {
    throw new RuntimeException("authored Woo default does not occupy one coherent product_cat coordinate");
}
if ($role === "source") {
    if ($installerDefault === $termId || !update_option("default_product_cat", $termId)) {
        throw new RuntimeException("source Woo default was not explicitly changed from the installer choice");
    }
} elseif ((int) get_option("default_product_cat", 0) !== $installerDefault) {
    throw new RuntimeException("target Woo installer default changed while creating its matching term");
}
$stored = $positiveId(get_option("default_product_cat", null), "stored Woo default");
if (($role === "source" && $stored !== $termId)
    || ($role === "target" && $stored !== $installerDefault)) {
    throw new RuntimeException("Woo default-category fixture did not retain its exact role");
}
echo wp_json_encode([
    "installer_default"=>$installerDefault,
    "option"=>$stored,
    "role"=>$role,
    "slug"=>"rmcombo-default-product-category",
    "taxonomy"=>$physical["taxonomy"],
    "taxonomy_term_id"=>(int)$physical["taxonomy_term_id"],
    "term_id"=>$termId,
    "term_taxonomy_id"=>$termTaxonomyId,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
' --exec="putenv('WPRISM_RMCOMBO_ROLE=$role');"
}

default_product_category_state() { # <wp1|wp2>
  local side="$1"
  capture_rmcombo_native_json 'Rank Math combination Woo default-category native observation' "$side" eval '
global $wpdb;
$positiveId = static function ($value): int {
    if (is_int($value) && $value > 0) return $value;
    if (is_string($value) && preg_match("/^[1-9][0-9]*$/D", $value) === 1) {
        $id = (int) $value;
        if ($id > 0 && (string) $id === $value) return $id;
    }
    throw new RuntimeException("default_product_cat is not an exact positive integer");
};
$readRow = static function (string $sql, string $where) use ($wpdb): ?array {
    $wpdb->last_error = "";
    $row = $wpdb->get_row($sql, ARRAY_A);
    if ($wpdb->last_error !== "" || ($row !== null && !is_array($row))) {
        throw new RuntimeException("Woo default-category $where observation failed");
    }
    return $row;
};
$id = $positiveId(get_option("default_product_cat", null));
$term = $readRow($wpdb->prepare(
    "SELECT term_id,slug FROM {$wpdb->terms} WHERE term_id=%d",
    $id
), "term");
$taxonomy = $readRow($wpdb->prepare(
    "SELECT term_taxonomy_id,term_id,taxonomy FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id=%d",
    $id
), "term-taxonomy");
if ($term !== null
    && (array_keys($term) !== ["term_id", "slug"]
        || $positiveId($term["term_id"] ?? null) !== $id
        || !is_string($term["slug"] ?? null) || $term["slug"] === "")) {
    throw new RuntimeException("Woo default-category term observation returned a malformed row");
}
if ($taxonomy !== null
    && (array_keys($taxonomy) !== ["term_taxonomy_id", "term_id", "taxonomy"]
        || $positiveId($taxonomy["term_taxonomy_id"] ?? null) !== $id
        || $positiveId($taxonomy["term_id"] ?? null) < 1
        || !is_string($taxonomy["taxonomy"] ?? null) || $taxonomy["taxonomy"] === "")) {
    throw new RuntimeException("Woo default-category term-taxonomy observation returned a malformed row");
}
echo wp_json_encode([
    "option"=>$id,
    "term"=>$term === null ? null : ["term_id"=>(int)$term["term_id"],"slug"=>$term["slug"]],
    "term_taxonomy"=>$taxonomy === null ? null : [
        "term_taxonomy_id"=>(int)$taxonomy["term_taxonomy_id"],
        "term_id"=>(int)$taxonomy["term_id"],
        "taxonomy"=>$taxonomy["taxonomy"],
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
'
}

default_product_category_identity() { # <wp1|wp2> <canonical uuid>
  local side="$1" uuid="$2"
  [[ "$uuid" =~ ^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$ ]] \
    || fail 'default product-category canonical identity is malformed'
  capture_rmcombo_native_json 'Rank Math combination Woo default-category identity observation' "$side" eval '
global $wpdb;
$uuid = (string) getenv("WPRISM_RMCOMBO_UUID");
$wpdb->last_error = "";
$rows = $wpdb->get_results($wpdb->prepare(
    "SELECT uuid,entity_type,id_kind,local_id FROM {$wpdb->prefix}wprism_map " .
    "WHERE uuid=%s AND id_kind IN (%s,%s) ORDER BY id_kind",
    $uuid,
    "term",
    "term_taxonomy"
), ARRAY_A);
if ($wpdb->last_error !== "" || !is_array($rows) || !array_is_list($rows)) {
    throw new RuntimeException("Woo default-category identity observation failed");
}
foreach ($rows as &$row) $row["local_id"] = (int) $row["local_id"];
unset($row);
echo wp_json_encode($rows, JSON_UNESCAPED_SLASHES);
' --exec="putenv('WPRISM_RMCOMBO_UUID=$uuid');"
}

identity_map_digest() { # <wp1|wp2>
  local side="$1"
  capture_rmcombo_native_json 'Rank Math combination identity-map digest observation' "$side" eval '
global $wpdb;
$wpdb->last_error = "";
$rows = $wpdb->get_results(
    "SELECT uuid,entity_type,id_kind,local_id FROM {$wpdb->prefix}wprism_map " .
    "ORDER BY uuid,entity_type,id_kind,local_id",
    ARRAY_A
);
if ($wpdb->last_error !== "" || !is_array($rows) || !array_is_list($rows)) {
    throw new RuntimeException("identity-map digest observation failed");
}
$bytes = wp_json_encode($rows, JSON_UNESCAPED_SLASHES);
if (!is_string($bytes)) throw new RuntimeException("identity-map digest encoding failed");
echo wp_json_encode(["count"=>count($rows),"sha256"=>hash("sha256",$bytes)], JSON_UNESCAPED_SLASHES);
'
}

canonical_capture_digest() { # <repository root>
  php -r '
$repository = $argv[1];
$rows = [];
foreach (["media", "state"] as $ownedRoot) {
    $root = $repository . "/" . $ownedRoot;
    if (!is_dir($root) || is_link($root)) {
        if (file_exists($root) || is_link($root)) throw new RuntimeException("canonical capture root is unsafe");
        continue;
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isLink() || !$file->isFile()) throw new RuntimeException("canonical capture tree is unsafe");
        $path = $file->getPathname();
        $relative = $ownedRoot . "/" . substr($path, strlen($root) + 1);
        $digest = hash_file("sha256", $path);
        if (!is_string($digest)) throw new RuntimeException("canonical capture digest read failed");
        $rows[$relative] = $digest;
    }
}
ksort($rows, SORT_STRING);
echo hash("sha256", json_encode($rows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
' "$1"
}

seed_rmcombo_stale_links() {
  local receipt rmcombo_host_pair="$PAIR" rmcombo_host_service=cli2
  capture_wprism_json_checked receipt 'Rank Math combination stale-link seed' assert_rmcombo_host_native_json wp2 eval '
global $wpdb;
$posts = [];
foreach (["rmcombo-product-en"=>"product", "rmcombo-product-de"=>"product", "rmcombo-book"=>"rmcombo_book", "rmcombo-target-neighbor"=>"product"] as $slug=>$type) {
    $post = get_page_by_path($slug, OBJECT, $type);
    if (!$post instanceof WP_Post || $post->ID < 1) throw new RuntimeException("stale-link seed post is unavailable");
    $posts[$slug] = (int) $post->ID;
}
if (count(array_unique($posts)) !== 4) throw new RuntimeException("stale-link seed identities overlap");
$checked = static function ($result) use ($wpdb): void {
    if ($result === false || $wpdb->last_error !== "") throw new RuntimeException("stale-link seed write failed");
};
$checked($wpdb->query("DELETE FROM {$wpdb->prefix}rank_math_internal_links"));
$checked($wpdb->query("DELETE FROM {$wpdb->prefix}rank_math_internal_meta"));
$neighbor = $posts["rmcombo-target-neighbor"];
foreach ($posts as $slug=>$id) {
    if ($id === $neighbor) continue;
    $checked($wpdb->insert($wpdb->prefix."rank_math_internal_links", [
        "url"=>$slug === "rmcombo-book" ? "/target-stale-book" : "/target-stale",
        "post_id"=>$id,"target_post_id"=>$neighbor,"type"=>"internal",
    ]));
    $checked($wpdb->insert($wpdb->prefix."rank_math_internal_meta", [
        "object_id"=>$id,"internal_link_count"=>999,"external_link_count"=>999,"incoming_link_count"=>999,
    ]));
    update_post_meta($id, "rank_math_internal_links_processed", "1");
    if (get_post_meta($id, "rank_math_internal_links_processed", true) !== "1") {
        throw new RuntimeException("stale-link seed marker readback failed");
    }
}
$checked($wpdb->insert($wpdb->prefix."rank_math_internal_meta", [
    "object_id"=>$neighbor,"internal_link_count"=>0,"external_link_count"=>0,"incoming_link_count"=>999,
]));
$counts = [];
foreach (["links"=>"rank_math_internal_links", "counts"=>"rank_math_internal_meta"] as $key=>$suffix) {
    $count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}{$suffix}");
    if ($wpdb->last_error !== "" || !is_numeric($count)) throw new RuntimeException("stale-link seed count readback failed");
    $counts[$key] = (int) $count;
}
echo wp_json_encode($counts);
'
  jq -e '. == {links:3,counts:4}' <<<"$receipt" >/dev/null \
    || fail 'Rank Math combination stale-link seed did not retain every witness'
}

native_state() { # <wp1|wp2>
  local side="$1"
  capture_rmcombo_native_json 'Rank Math combination native-state observation' "$side" eval '
global $wpdb;
// This program crosses the outer Bash single-quoted wp-eval boundary. The
// 900b3e52 live run lost three inline SQL quote pairs there; prepare every
// data literal, and reject last_error before an empty set or zero can pose as
// a valid post-apply observation.
$readRows = static function (string $sql) use ($wpdb): array {
    $rows = $wpdb->get_results($sql, ARRAY_A);
    if ($wpdb->last_error !== "" || !is_array($rows)) {
        throw new RuntimeException("combined native database row-set observation failed");
    }
    return $rows;
};
$readRow = static function (string $sql) use ($wpdb): ?array {
    $row = $wpdb->get_row($sql, ARRAY_A);
    if ($wpdb->last_error !== "" || ($row !== null && !is_array($row))) {
        throw new RuntimeException("combined native database row observation failed");
    }
    return $row;
};
$readValue = static function (string $sql) use ($wpdb) {
    $value = $wpdb->get_var($sql);
    if ($wpdb->last_error !== "") {
        throw new RuntimeException("combined native database scalar observation failed");
    }
    return $value;
};
$en = get_page_by_path("rmcombo-product-en", OBJECT, "product");
$de = get_page_by_path("rmcombo-product-de", OBJECT, "product");
$neighbor = get_page_by_path("rmcombo-target-neighbor", OBJECT, "product");
$book = get_page_by_path("rmcombo-book", OBJECT, "rmcombo_book");
$catEn = get_term_by("slug", "rmcombo-catalog-en", "product_cat");
$catDe = get_term_by("slug", "rmcombo-catalog-de", "product_cat");
if (!$en instanceof WP_Post || !$de instanceof WP_Post
    || !$catEn instanceof WP_Term || !$catDe instanceof WP_Term) {
    throw new RuntimeException("combined native product graph is incomplete");
}
$products = ["en" => wc_get_product($en), "de" => wc_get_product($de)];
$rows = [];
foreach ($products as $language => $product) {
    if (!$product instanceof WC_Product) throw new RuntimeException("Woo product API read failed");
    $post = $language === "en" ? $en : $de;
    $lookup = $readRow($wpdb->prepare(
        "SELECT min_price,max_price,stock_status FROM {$wpdb->wc_product_meta_lookup} WHERE product_id=%d",
        $post->ID
    ));
    $counts = $readRow($wpdb->prepare(
        "SELECT internal_link_count,external_link_count,incoming_link_count " .
        "FROM {$wpdb->prefix}rank_math_internal_meta WHERE object_id=%d",
        $post->ID
    ));
    $linkRows = $readRows($wpdb->prepare(
        "SELECT url,target_post_id,type FROM {$wpdb->prefix}rank_math_internal_links " .
        "WHERE post_id=%d ORDER BY type,url,target_post_id",
        $post->ID
    ));
    $rows[$language] = [
        "acf" => get_field("rmcombo_badge", $post->ID),
        "canonical" => get_post_meta($post->ID, "rank_math_canonical_url", true),
        "content" => $post->post_content,
        "description" => get_post_meta($post->ID, "rank_math_description", true),
        "id" => (int) $post->ID,
        "language" => pll_get_post_language($post->ID, "slug"),
        "links" => $linkRows,
        "lookup" => $lookup,
        "price" => $product->get_regular_price("edit"),
        "primary" => (int) get_post_meta($post->ID, "rank_math_primary_product_cat", true),
        "processed" => (bool) get_post_meta($post->ID, "rank_math_internal_links_processed", true),
        "rank_counts" => $counts,
        "title" => get_post_meta($post->ID, "rank_math_title", true),
        "url" => get_permalink($post),
    ];
}
$redirection = null;
foreach ($readRows("SELECT * FROM {$wpdb->prefix}rank_math_redirections ORDER BY id") as $row) {
    $sources = maybe_unserialize($row["sources"] ?? "");
    if (is_array($sources) && ($sources[0]["pattern"] ?? null) === "rmcombo-old") {
        $redirection = [
            "header_code"=>(int)$row["header_code"],"hits"=>(int)$row["hits"],
            "id"=>(int)$row["id"],"sources"=>$sources,"status"=>$row["status"],"url_to"=>$row["url_to"],
        ];
    }
}
$bookLinks = [];
$bookCounts = null;
if ($book instanceof WP_Post) {
    $bookLinks = $readRows($wpdb->prepare(
        "SELECT url,target_post_id,type FROM {$wpdb->prefix}rank_math_internal_links " .
        "WHERE post_id=%d ORDER BY type,url,target_post_id",
        $book->ID
    ));
    $bookCounts = $readRow($wpdb->prepare(
        "SELECT internal_link_count,external_link_count,incoming_link_count " .
        "FROM {$wpdb->prefix}rank_math_internal_meta WHERE object_id=%d",
        $book->ID
    ));
}
if (!function_exists("as_get_scheduled_actions")) {
    throw new RuntimeException("Action Scheduler API is unavailable");
}
$scheduler = $readRows($wpdb->prepare(
    "SELECT a.action_id,a.hook,a.status,g.slug AS group_slug " .
    "FROM {$wpdb->prefix}actionscheduler_actions a " .
    "LEFT JOIN {$wpdb->prefix}actionscheduler_groups g ON g.group_id=a.group_id " .
    "WHERE a.hook IN (%s,%s) ORDER BY a.action_id",
    "rmcombo_source_runtime",
    "rmcombo_target_runtime"
));
$redirectionCache = $readRows($wpdb->prepare(
    "SELECT from_url,redirection_id,object_id,object_type,is_redirected " .
    "FROM {$wpdb->prefix}rank_math_redirections_cache WHERE from_url=%s ORDER BY id",
    "rmcombo-old"
));
$retiredTargetCounts = $neighbor instanceof WP_Post ? $readRow($wpdb->prepare(
    "SELECT internal_link_count,external_link_count,incoming_link_count " .
    "FROM {$wpdb->prefix}rank_math_internal_meta WHERE object_id=%d",
    $neighbor->ID
)) : null;
$staleLinkSentinels = (int) $readValue($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}rank_math_internal_links " .
    "WHERE url IN (%s,%s)",
    "/target-stale",
    "/target-stale-book"
));
$neighborState = null;
if ($neighbor instanceof WP_Post) {
    $neighborProduct = wc_get_product($neighbor);
    if (!$neighborProduct instanceof WC_Product) throw new RuntimeException("target-only neighbor API read failed");
    $neighborState = [
        "acf"=>get_field("rmcombo_badge",$neighbor->ID),"id"=>(int)$neighbor->ID,
        "price"=>$neighborProduct->get_regular_price("edit"),
        "title"=>get_post_meta($neighbor->ID,"rank_math_title",true),
    ];
}
echo wp_json_encode([
    "book" => $book instanceof WP_Post ? [
        "content"=>$book->post_content,"id"=>(int)$book->ID,"links"=>$bookLinks,
        "processed"=>(bool)get_post_meta($book->ID,"rank_math_internal_links_processed",true),
        "rank_counts"=>$bookCounts,"title"=>get_post_meta($book->ID,"rank_math_title",true),
    ] : null,
    "categories" => ["en"=>(int)$catEn->term_id,"de"=>(int)$catDe->term_id],
    "category_languages" => [
        "en"=>pll_get_term_language($catEn->term_id, "slug"),
        "de"=>pll_get_term_language($catDe->term_id, "slug"),
    ],
    "modules" => array_values((array) get_option("rank_math_modules", [])),
    "neighbor" => $neighborState,
    "products" => $rows,
    "redirection" => $redirection,
    "redirection_cache" => $redirectionCache,
    "retired_target_counts" => $retiredTargetCounts,
    "scheduler" => $scheduler,
    "stale_link_sentinels" => $staleLinkSentinels,
    "term_translations" => array_map("intval", pll_get_term_translations($catEn->term_id)),
    "translations" => array_map("intval", pll_get_post_translations($en->ID)),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
'
}

product_response() { # <slug>
  local slug="$1" route host path response status body
  route=$(capture_rmcombo_native_json 'Rank Math combination product-route observation' wp2 eval '
$slug = (string) getenv("WPRISM_RMCOMBO_SLUG");
$post = get_page_by_path($slug, OBJECT, "product");
if (!$post instanceof WP_Post) throw new RuntimeException("product route is absent");
echo wp_json_encode(["host"=>wp_parse_url(home_url("/"),PHP_URL_HOST),"path"=>wp_parse_url(get_permalink($post),PHP_URL_PATH)]);
' --exec="putenv('WPRISM_RMCOMBO_SLUG=$slug');")
  host=$(jq -er '.host|strings' <<<"$route")
  path=$(jq -er '.path|strings' <<<"$route")
  response=$("${COMPOSE[@]}" exec -T wp2 curl -sS --max-time 20 -H "Host: $host" \
    -w '\n__WPRISM_STATUS__%{http_code}' "http://127.0.0.1$path") \
    || fail "combined product request failed: $path"
  status=$(printf '%s\n' "$response" | tail -1)
  status=${status#__WPRISM_STATUS__}
  body=$(printf '%s\n' "$response" | sed '$d')
  [ "$status" = 200 ] || fail "combined product route returned $status: $path"
  printf '%s\n' "$body"
}

head_projection() { # HTML on stdin -> one exact JSON projection
  php -r '
$html = stream_get_contents(STDIN);
$document = new DOMDocument();
libxml_use_internal_errors(true);
if (!$document->loadHTML($html, LIBXML_NOWARNING | LIBXML_NOERROR)) exit(2);
$xpath = new DOMXPath($document);
$one = static function (string $query, string $attribute = "") use ($xpath): ?string {
    $nodes = $xpath->query($query);
    if (!$nodes || $nodes->length !== 1) return null;
    $node = $nodes->item(0);
    return $attribute === "" ? trim($node->textContent) : $node->attributes?->getNamedItem($attribute)?->nodeValue;
};
$alternates = [];
foreach ($xpath->query("//head/link[@rel=\"alternate\"]") ?: [] as $node) {
    $language = $node->attributes?->getNamedItem("hreflang")?->nodeValue;
    $href = $node->attributes?->getNamedItem("href")?->nodeValue;
    if (is_string($language) && $language !== "" && is_string($href) && $href !== "") $alternates[$language] = $href;
}
ksort($alternates, SORT_STRING);
echo json_encode([
    "alternates"=>$alternates,
    "canonical"=>$one("//head/link[@rel=\"canonical\"]", "href"),
    "description"=>$one("//head/meta[@name=\"description\"]", "content"),
    "og_description"=>$one("//head/meta[@property=\"og:description\"]", "content"),
    "og_locale"=>$one("//head/meta[@property=\"og:locale\"]", "content"),
    "og_title"=>$one("//head/meta[@property=\"og:title\"]", "content"),
    "og_url"=>$one("//head/meta[@property=\"og:url\"]", "content"),
    "title"=>$one("//head/title"),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
'
}

redirection_response() { # populates REDIRECT_STATUS and REDIRECT_LOCATION
  local route host response
  route=$(capture_rmcombo_native_json 'Rank Math combination redirection-route observation' \
    wp2 eval 'echo wp_json_encode(["host"=>wp_parse_url(home_url("/"),PHP_URL_HOST)]);')
  host=$(jq -er '.host|strings' <<<"$route")
  response=$("${COMPOSE[@]}" exec -T wp2 curl -sS --max-time 20 -o /dev/null -D - \
    -H "Host: $host" -w '__WPRISM_STATUS__%{http_code}\n' 'http://127.0.0.1/rmcombo-old') \
    || fail 'Rank Math combination redirect request failed before response'
  REDIRECT_STATUS=$(awk -F'__WPRISM_STATUS__' '/__WPRISM_STATUS__/ { sub(/\r$/, "", $2); print $2 }' <<<"$response" | tail -1)
  REDIRECT_LOCATION=$(awk 'BEGIN { IGNORECASE=1 } /^Location:/ { sub(/\r$/, ""); print substr($0, 11) }' \
    <<<"$response" | tail -1)
}

install_hostile_provider() {
  "${COMPOSE[@]}" exec -T --user root wp2 sh -c '
set -eu
target=/var/www/html/wp-content/mu-plugins/wprism-rmcombo-provider-fault.php
mkdir -p "$(dirname "$target")"
tmp="$target.tmp.$$"
trap '\''rm -f "$tmp"'\'' EXIT HUP INT TERM
umask 022
cat >"$tmp"
chmod 0644 "$tmp"
mv "$tmp" "$target"
trap - EXIT HUP INT TERM
' <<'PHPEOF'
<?php
add_filter('rank_math/excluded_post_types', static fn($types) => $types);
PHPEOF
}

remove_hostile_provider() {
  "${COMPOSE[@]}" exec -T --user root wp2 \
    rm -f /var/www/html/wp-content/mu-plugins/wprism-rmcombo-provider-fault.php
}

install_custom_post_type() { # <1|2>
  local side="$1"
  "${COMPOSE[@]}" exec -T --user root "wp$side" sh -c '
set -eu
target=/var/www/html/wp-content/mu-plugins/wprism-rmcombo-cpt.php
mkdir -p "$(dirname "$target")"
tmp="$target.tmp.$$"
trap '\''rm -f "$tmp"'\'' EXIT HUP INT TERM
umask 022
cat >"$tmp"
chmod 0644 "$tmp"
mv "$tmp" "$target"
trap - EXIT HUP INT TERM
' <<'PHPEOF'
<?php
add_action('init', static function (): void {
    register_post_type('rmcombo_book', [
        'label' => 'RM Combo Books',
        'public' => true,
        'rewrite' => ['slug' => 'rmcombo-books'],
        'show_in_rest' => true,
        'supports' => ['title', 'editor'],
    ]);
});
PHPEOF
}

install_stack() { # <side> <forward|reverse>
  local side="$1" order="$2"
  if [ "$order" = forward ]; then
    install_exact "$side" advanced-custom-fields 6.8.7
    install_exact "$side" polylang 3.8.6
    install_exact "$side" seo-by-rank-math 1.0.277.2
    install_exact "$side" woocommerce 11.0.1
  elif [ "$order" = reverse ]; then
    install_exact "$side" woocommerce 11.0.1
    install_exact "$side" seo-by-rank-math 1.0.277.2
    install_exact "$side" polylang 3.8.6
    install_exact "$side" advanced-custom-fields 6.8.7
  else
    fail "unknown plugin-load order '$order'"
  fi
}

persist_active_plugin_order() { # <side> <forward|reverse>
  local side="$1" order="$2" plugins
  if [ "$order" = forward ]; then
    plugins='["polylang/polylang.php","advanced-custom-fields/acf.php","seo-by-rank-math/rank-math.php","woocommerce/woocommerce.php"]'
  elif [ "$order" = reverse ]; then
    plugins='["polylang/polylang.php","woocommerce/woocommerce.php","seo-by-rank-math/rank-math.php","advanced-custom-fields/acf.php"]'
  else
    fail "unknown plugin-load order '$order'"
  fi
  "wp$side" option update active_plugins "$plugins" --format=json >/dev/null
}

run_leg() { # <source order> <target order>
local source_order="$1" target_order="$2" expected_source expected_target
say "fresh exact four-plugin pair: source=$source_order target=$target_order"
bash bin/pair.sh repo-host "$PAIR" both >/dev/null
for side in 1 2; do
  "wp$side" site empty --yes >/dev/null
done
# Woo's installer writes default_product_cat through a term-taxonomy id while
# its admin/default-term readers use the same scalar as a term id. Keep only
# that activation/default fixture on a coherent coordinate in each host; the
# source and target bases still differ, so no local id can cross environments.
wp1 db query 'ALTER TABLE wp_posts AUTO_INCREMENT=3100001; ALTER TABLE wp_terms AUTO_INCREMENT=3200001; ALTER TABLE wp_term_taxonomy AUTO_INCREMENT=3200001;' >/dev/null
wp2 db query 'ALTER TABLE wp_posts AUTO_INCREMENT=9100001; ALTER TABLE wp_terms AUTO_INCREMENT=9200001; ALTER TABLE wp_term_taxonomy AUTO_INCREMENT=9200001;' >/dev/null

install_stack 1 "$source_order"
install_stack 2 "$target_order"
# Activation does not preserve install order, and Polylang intentionally moves
# itself first. Author the two pairwise-opposed orders after every artifact is
# active; independent readbacks prove the subsequent request boots each one.
persist_active_plugin_order 1 "$source_order"
persist_active_plugin_order 2 "$target_order"
install_custom_post_type 1
install_custom_post_type 2

SOURCE_ORDER=$(active_plugin_order wp1)
TARGET_ORDER=$(active_plugin_order wp2)
if [ "$source_order" = forward ]; then
  expected_source='["polylang","advanced-custom-fields","seo-by-rank-math","woocommerce"]'
else
  expected_source='["polylang","woocommerce","seo-by-rank-math","advanced-custom-fields"]'
fi
if [ "$target_order" = forward ]; then
  expected_target='["polylang","advanced-custom-fields","seo-by-rank-math","woocommerce"]'
else
  expected_target='["polylang","woocommerce","seo-by-rank-math","advanced-custom-fields"]'
fi
jq -en --argjson source "$SOURCE_ORDER" --argjson target "$TARGET_ORDER" \
  --argjson expected_source "$expected_source" --argjson expected_target "$expected_target" '
  $source == $expected_source and $target == $expected_target
' >/dev/null || fail "source/target active-plugin orders are not exact: $SOURCE_ORDER / $TARGET_ORDER"
pass "exact active-plugin orders established: source=$source_order target=$target_order"

for side in wp1 wp2; do
  "$side" eval 'WC_Install::create_terms();' >/dev/null
done
SOURCE_DEFAULT_FIXTURE=$(establish_woocommerce_default_category wp1 source)
TARGET_DEFAULT_FIXTURE=$(establish_woocommerce_default_category wp2 target)
require_observed_nonempty 'source authored Woo default-category fixture' "$SOURCE_DEFAULT_FIXTURE"
require_observed_nonempty 'target matching Woo default-category fixture' "$TARGET_DEFAULT_FIXTURE"
jq -en --argjson source "$SOURCE_DEFAULT_FIXTURE" --argjson target "$TARGET_DEFAULT_FIXTURE" '
  ($source | keys) == ["installer_default","option","role","slug","taxonomy","taxonomy_term_id","term_id","term_taxonomy_id"] and
  ($target | keys) == ["installer_default","option","role","slug","taxonomy","taxonomy_term_id","term_id","term_taxonomy_id"] and
  $source.role == "source" and $target.role == "target" and
  $source.slug == "rmcombo-default-product-category" and $target.slug == $source.slug and
  $source.taxonomy == "product_cat" and $target.taxonomy == $source.taxonomy and
  $source.term_id == $source.term_taxonomy_id and $source.taxonomy_term_id == $source.term_id and
  $target.term_id == $target.term_taxonomy_id and $target.taxonomy_term_id == $target.term_id and
  $source.term_id != $target.term_id and
  $source.option == $source.term_id and $source.option != $source.installer_default and
  $target.option == $target.installer_default and $target.option != $target.term_id
' >/dev/null || fail "Woo default-category fixture is not an explicitly changed, cross-host portable coordinate: $SOURCE_DEFAULT_FIXTURE / $TARGET_DEFAULT_FIXTURE"
# Keep every subsequent product/language category in the original adversarial
# domain. Only the TT sequence moves: term ids remain at their host-local base.
wp1 db query 'ALTER TABLE wp_term_taxonomy AUTO_INCREMENT=3300001' >/dev/null
wp2 db query 'ALTER TABLE wp_term_taxonomy AUTO_INCREMENT=9300001' >/dev/null
for side in wp1 wp2; do
  create_languages "$side"
done
configure_rank_math wp1 source
configure_rank_math wp2 target
SOURCE_RANK_MATH_READY=$(rank_math_readiness wp1 source)
TARGET_RANK_MATH_READY=$(rank_math_readiness wp2 target)
require_observed_nonempty 'source Rank Math native module readiness' "$SOURCE_RANK_MATH_READY"
require_observed_nonempty 'target Rank Math native module readiness' "$TARGET_RANK_MATH_READY"
jq -en --argjson source "$SOURCE_RANK_MATH_READY" --argjson target "$TARGET_RANK_MATH_READY" '
  $source == {
    active_modules:["link-counter","redirections","rich-snippet"],
    modules:["link-counter","redirections","rich-snippet"],role:"source",
    tables:{rank_math_internal_links:true,rank_math_internal_meta:true,
      rank_math_redirections:true,rank_math_redirections_cache:true},
    version:"1.0.277.2"
  } and
  $target == {
    active_modules:["redirections","rich-snippet"],
    modules:["redirections","rich-snippet"],role:"target",
    tables:{rank_math_redirections:true,rank_math_redirections_cache:true},
    version:"1.0.277.2"
  }
' >/dev/null || fail "Rank Math native module readiness is incomplete: $SOURCE_RANK_MATH_READY / $TARGET_RANK_MATH_READY"
pass 'Rank Math native module lifecycle persisted exact roles and installed every required table'

say 'author native multilingual products, ACF values, Rank Math SEO/link state and Woo lookup state'
SOURCE_SEED=$(capture_rmcombo_native_json 'Rank Math combination source native seed' wp1 eval '
global $wpdb;
$createTerm = static function (string $name, string $slug): int {
    $result = wp_insert_term($name, "product_cat", ["slug"=>$slug]);
    if (is_wp_error($result)) throw new RuntimeException($result->get_error_message());
    return (int) $result["term_id"];
};
$categories = [
    "en"=>$createTerm("Portable Catalog English 東京", "rmcombo-catalog-en"),
    "de"=>$createTerm("Tragbarer Katalog Deutsch", "rmcombo-catalog-de"),
];
$categoryTts = [];
foreach ($categories as $language=>$id) {
    $term = get_term($id, "product_cat");
    if (!$term instanceof WP_Term) throw new RuntimeException("source product category readback failed");
    $categoryTts[$language] = (int) $term->term_taxonomy_id;
}
foreach ($categories as $language=>$id) pll_set_term_language($id, $language);
pll_save_term_translations($categories);

$group = [
    "key"=>"group_rmcombo_product", "title"=>"Portable Product Fields 東京", "fields"=>[],
    "location"=>[[["param"=>"post_type","operator"=>"==","value"=>"product"]]], "active"=>true,
];
acf_update_field_group($group);
$groupPosts = get_posts(["post_type"=>"acf-field-group","name"=>$group["key"],"posts_per_page"=>1,"fields"=>"ids","post_status"=>"any"]);
if (!$groupPosts) throw new RuntimeException("ACF product group was not created");
acf_update_field([
    "key"=>"field_rmcombo_badge", "label"=>"Portable Badge", "name"=>"rmcombo_badge",
    "type"=>"text", "parent"=>(int)$groupPosts[0],
]);

$products = [];
foreach (["en","de"] as $language) {
    $product = new WC_Product_Simple();
    $product->set_name($language === "en" ? "Portable Commerce English 東京 🚀" : "Tragbarer Handel Deutsch 東京 🚀");
    $product->set_slug("rmcombo-product-$language");
    $product->set_status("publish");
    $product->set_regular_price($language === "en" ? "29" : "31");
    $id = $product->save();
    if (!$id) throw new RuntimeException("Woo product creation failed");
    $products[$language] = (int) $id;
    pll_set_post_language((int)$id, $language);
    wp_set_object_terms((int)$id, [$categories[$language]], "product_cat");
    update_field("field_rmcombo_badge", $language === "en" ? "English badge 東京" : "Deutsches Abzeichen 東京", (int)$id);
}
pll_save_post_translations($products);
foreach (["en","de"] as $language) {
    $other = $language === "en" ? "de" : "en";
    $product = wc_get_product($products[$language]);
    $product->set_description("<p>Portable $language product 東京 🚀 <a href=\"" . esc_url(get_permalink($products[$other])) . "\">translated peer</a> <a href=\"https://external.example.test/rmcombo\">external</a></p>");
    $product->save();
    update_post_meta($products[$language], "rank_math_title", $language === "en" ? "Portable Rank Math Commerce EN 東京 🚀" : "Tragbarer Rank Math Handel DE 東京 🚀");
    update_post_meta($products[$language], "rank_math_description", $language === "en" ? "Portable English commerce SEO 東京." : "Tragbare deutsche Commerce-SEO 東京.");
    update_post_meta($products[$language], "rank_math_canonical_url", get_permalink($products[$language]));
    update_post_meta($products[$language], "rank_math_primary_product_cat", (string)$categories[$language]);
    RankMath\Links\Links::process_post_links($products[$language], get_post($products[$language]));
}
$book = wp_insert_post([
    "post_type"=>"rmcombo_book","post_status"=>"publish","post_name"=>"rmcombo-book",
    "post_title"=>"Portable custom Rank Math book 東京",
    "post_content"=>"<p>Custom CPT <a href=\"" . esc_url(get_permalink($products["en"])) . "\">product</a> " .
        "<a href=\"https://external.example.test/rmcombo-book\">external</a></p>",
], true);
if (is_wp_error($book) || (int)$book < 1) throw new RuntimeException("custom CPT creation failed");
update_post_meta((int)$book,"rank_math_title","Portable custom CPT SEO 東京");
RankMath\Links\Links::process_post_links((int)$book,get_post((int)$book));
$redirection = RankMath\Redirections\Redirection::from([
    "sources"=>[["pattern"=>"rmcombo-old","comparison"=>"exact","ignore"=>""]],
    "url_to"=>get_permalink($products["en"]), "header_code"=>"302", "status"=>"active",
]);
$redirectionId = $redirection->save();
if (!is_int($redirectionId) || $redirectionId < 1) throw new RuntimeException("Rank Math redirection creation failed");
if (function_exists("as_schedule_single_action")) as_schedule_single_action(time()+3600, "rmcombo_source_runtime");
echo wp_json_encode(["book"=>(int)$book,"categories"=>$categories,"category_tts"=>$categoryTts,"group"=>(int)$groupPosts[0],"products"=>$products,"redirection"=>$redirectionId]);
')
require_observed_nonempty 'Rank Math combination source seed' "$SOURCE_SEED"
jq -e '
  .categories.en > 0 and .categories.de > 0 and
  .category_tts.en > 0 and .category_tts.de > 0 and
  .categories.en != .category_tts.en and .categories.de != .category_tts.de
' <<<"$SOURCE_SEED" >/dev/null \
  || fail "source custom product categories did not retain term/TT divergence: $SOURCE_SEED"
SOURCE_NATIVE=$(native_state wp1)
jq -e '
  .scheduler == [{action_id: .scheduler[0].action_id, hook:"rmcombo_source_runtime", status:"pending", group_slug:""}] and
  (.scheduler[0].action_id | tonumber) > 0
' <<<"$SOURCE_NATIVE" >/dev/null \
  || fail "source Action Scheduler witness is absent or ambiguous before capture: $SOURCE_NATIVE"
pass 'source-only Action Scheduler state exists natively before capture'

TARGET_SEED=$(capture_rmcombo_native_json 'Rank Math combination target native seed' wp2 eval '
global $wpdb;
$createTerm = static function (string $name, string $slug): int {
    $result = wp_insert_term($name, "product_cat", ["slug"=>$slug]);
    if (is_wp_error($result)) throw new RuntimeException($result->get_error_message());
    return (int) $result["term_id"];
};
$categories = [
    "en"=>$createTerm("Target Catalog EN", "rmcombo-catalog-en"),
    "de"=>$createTerm("Target Catalog DE", "rmcombo-catalog-de"),
];
$categoryTts = [];
foreach ($categories as $language=>$id) {
    $term = get_term($id, "product_cat");
    if (!$term instanceof WP_Term) throw new RuntimeException("target product category readback failed");
    $categoryTts[$language] = (int) $term->term_taxonomy_id;
}
foreach ($categories as $language=>$id) pll_set_term_language($id, $language);
pll_save_term_translations($categories);
$group=["key"=>"group_rmcombo_product","title"=>"Target Product Fields","fields"=>[],"location"=>[[["param"=>"post_type","operator"=>"==","value"=>"product"]]],"active"=>true];
acf_update_field_group($group);
$groupPosts=get_posts(["post_type"=>"acf-field-group","name"=>$group["key"],"posts_per_page"=>1,"fields"=>"ids","post_status"=>"any"]);
acf_update_field(["key"=>"field_rmcombo_badge","label"=>"Target Badge","name"=>"rmcombo_badge","type"=>"text","parent"=>(int)$groupPosts[0]]);
$products=[];
foreach (["en","de"] as $language) {
    $product=new WC_Product_Simple();
    $product->set_name("Target stale $language product");
    $product->set_slug("rmcombo-product-$language");
    $product->set_status("publish");
    $product->set_regular_price($language === "en" ? "81" : "82");
    $id=$product->save();
    $products[$language]=(int)$id;
    pll_set_post_language((int)$id,$language);
    wp_set_object_terms((int)$id,[$categories[$language]],"product_cat");
    update_field("field_rmcombo_badge","target stale badge $language",(int)$id);
    update_post_meta((int)$id,"rank_math_title","target stale SEO $language");
    update_post_meta((int)$id,"rank_math_primary_product_cat",(string)$categories[$language]);
}
pll_save_post_translations($products);
$book=wp_insert_post([
    "post_type"=>"rmcombo_book","post_status"=>"publish","post_name"=>"rmcombo-book",
    "post_title"=>"Target stale custom book","post_content"=>"<p>target stale custom content</p>",
],true);
if(is_wp_error($book)||(int)$book<1) throw new RuntimeException("target custom CPT creation failed");
update_post_meta((int)$book,"rank_math_title","target stale custom SEO");
$neighbor=new WC_Product_Simple();
$neighbor->set_name("Target-only scheduler neighbor");
$neighbor->set_slug("rmcombo-target-neighbor");
$neighbor->set_status("publish");
$neighbor->set_regular_price("97");
$neighborId=$neighbor->save();
update_field("field_rmcombo_badge","target-only badge",(int)$neighborId);
update_post_meta((int)$neighborId,"rank_math_title","target-only SEO");
$sources=maybe_serialize([["ignore"=>"","pattern"=>"rmcombo-old","comparison"=>"exact"]]);
$wpdb->insert($wpdb->prefix."rank_math_redirections",[
    "sources"=>$sources,"url_to"=>home_url("/target-stale/"),"header_code"=>301,
    "hits"=>41,"status"=>"inactive","created"=>"2020-01-01 00:00:00","updated"=>"2020-01-02 00:00:00","last_accessed"=>"2020-01-03 00:00:00",
]);
$redirectionId=(int)$wpdb->insert_id;
$wpdb->insert($wpdb->prefix."rank_math_redirections_cache",[
    "from_url"=>"rmcombo-old","redirection_id"=>$redirectionId,"object_id"=>999999999,
    "object_type"=>"post","is_redirected"=>0,
]);
if (function_exists("as_schedule_single_action")) as_schedule_single_action(time()+7200,"rmcombo_target_runtime");
echo wp_json_encode(["book"=>(int)$book,"categories"=>$categories,"category_tts"=>$categoryTts,"group"=>(int)$groupPosts[0],"neighbor"=>(int)$neighborId,"products"=>$products,"redirection"=>$redirectionId]);
')
require_observed_nonempty 'Rank Math combination hostile target' "$TARGET_SEED"
jq -en --argjson source "$SOURCE_SEED" --argjson target "$TARGET_SEED" '
  $target.categories.en > 0 and $target.categories.de > 0 and
  $target.category_tts.en > 0 and $target.category_tts.de > 0 and
  $target.categories.en != $target.category_tts.en and
  $target.categories.de != $target.category_tts.de and
  $target.categories.en != $source.categories.en and
  $target.categories.de != $source.categories.de and
  $target.category_tts.en != $source.category_tts.en and
  $target.category_tts.de != $source.category_tts.de
' >/dev/null || fail "custom product categories lost their within-host and cross-host divergence: $SOURCE_SEED / $TARGET_SEED"
seed_rmcombo_stale_links
HOSTILE_NATIVE=$(native_state wp2)
jq -e '
  .modules == ["redirections","rich-snippet"] and
  all(.products[], .book;
    .processed == true and (.links | length) == 1 and
    (.rank_counts | map_values(tonumber)) == {internal_link_count:999,external_link_count:999,incoming_link_count:999}) and
  .neighbor == {acf:"target-only badge",id:.neighbor.id,price:"97",title:"target-only SEO"} and
  .neighbor.id > 0 and
  .scheduler == [{action_id: .scheduler[0].action_id, hook:"rmcombo_target_runtime", status:"pending", group_slug:""}] and
  (.scheduler[0].action_id | tonumber) > 0 and
  .stale_link_sentinels == 3 and
  (.redirection_cache | length) == 1 and
  (.retired_target_counts.incoming_link_count | tonumber) == 999 and
  .redirection.header_code == 301 and .redirection.hits == 41 and .redirection.status == "inactive"
' <<<"$HOSTILE_NATIVE" >/dev/null \
  || fail "hostile target runtime/native premise is not exact: $HOSTILE_NATIVE"
pass 'target-only product and scheduler witnesses plus stale Rank Math projections are non-vacuous'

cat > "$R1/site.wprism.json" <<'EOF'
{
  "manifests": ["core", "acf", "polylang", "rank-math", "woocommerce"],
  "policy": {
    "options": {},
    "post_meta": {},
    "post_types": ["post", "page", "attachment", "product", "product_variation", "shop_coupon", "acf-field-group", "acf-field", "rmcombo_book"],
    "taxonomies": ["category", "post_tag", "product_brand", "product_cat", "product_shipping_class", "product_tag", "product_type", "product_visibility", "language", "term_language", "term_translations", "post_translations"]
  },
  "spec_version": 3
}
EOF
cp site-repo.gitignore.template "$R1/.gitignore"
git init -q -b main "$R1"
git -C "$R1" -c user.name=wprism-rmcombo -c user.email=rmcombo@example.test add -A
git -C "$R1" -c user.name=wprism-rmcombo -c user.email=rmcombo@example.test commit -qm 'policy: exact Rank Math commerce multilingual scenario'
git init --bare -b main "$ORIGIN" >/dev/null
git -C "$R1" remote add origin "../origin-$PAIR.git"
git -C "$R1" push -qu origin main
establish_core_environment_bindings wp1 /siterepo admin@example.test \
  "http://${PAIR}1.invalid" "http://${PAIR}1.invalid"
capture_wprism_json_checked SOURCE_CAPTURE 'Rank Math combination source capture' assert_rmcombo_warning_free_capture \
  wp1 wprism capture --repo=/siterepo --format=json
jq -e '.warnings == []' <<<"$SOURCE_CAPTURE" >/dev/null \
  || fail "source capture was not warning-free after authoring a coherent Woo default: $SOURCE_CAPTURE"
wp1 wprism lint --repo=/siterepo >/dev/null

shopt -s nullglob
SOURCE_DEFAULT_TERM_FILES=("$R1"/state/terms/product_cat/*--rmcombo-default-product-category.json)
shopt -u nullglob
[ "${#SOURCE_DEFAULT_TERM_FILES[@]}" -eq 1 ] \
  || fail 'source capture did not publish exactly one authored Woo default-category entity'
SOURCE_DEFAULT_UUID=$(jq -er '
  select(.taxonomy == "product_cat" and .slug == "rmcombo-default-product-category")
  | .uuid | select(type == "string")
' "${SOURCE_DEFAULT_TERM_FILES[0]}") \
  || fail 'source Woo default category did not carry one canonical identity'
SOURCE_DEFAULT_TOKEN="{{term:$SOURCE_DEFAULT_UUID}}"
jq -e --arg token "$SOURCE_DEFAULT_TOKEN" '
  .records.default_product_cat.state == "present" and
  .records.default_product_cat.value == $token and
  (.records.default_product_cat.autoload | type) == "string"
' "$R1/state/options/core.json" >/dev/null \
  || fail 'source capture did not bind default_product_cat to the exact portable category UUID'
SOURCE_DEFAULT_NATIVE=$(default_product_category_state wp1)
SOURCE_DEFAULT_IDENTITY=$(default_product_category_identity wp1 "$SOURCE_DEFAULT_UUID")
jq -en --argjson fixture "$SOURCE_DEFAULT_FIXTURE" --argjson native "$SOURCE_DEFAULT_NATIVE" \
  --argjson identity "$SOURCE_DEFAULT_IDENTITY" --arg uuid "$SOURCE_DEFAULT_UUID" '
  $native == {
    option:$fixture.term_id,
    term:{term_id:$fixture.term_id,slug:"rmcombo-default-product-category"},
    term_taxonomy:{term_taxonomy_id:$fixture.term_id,term_id:$fixture.term_id,taxonomy:"product_cat"}
  } and
  ($identity | length) == 2 and
  ($identity | map(.id_kind)) == ["term","term_taxonomy"] and
  all($identity[]; .uuid == $uuid and .entity_type == "term" and .local_id == $fixture.term_id)
' >/dev/null || fail "source default UUID, native option and coherent physical tuple disagree: $SOURCE_DEFAULT_NATIVE / $SOURCE_DEFAULT_IDENTITY"

say 'incoherent Woo default refuses public capture without publication or native/identity drift'
SOURCE_DIVERGENT_DEFAULT=$(jq -er '.categories.en' <<<"$SOURCE_SEED")
SOURCE_DIVERGENT_TT=$(jq -er '.category_tts.en' <<<"$SOURCE_SEED")
SOURCE_AUTHORED_DEFAULT=$(jq -er '.term_id' <<<"$SOURCE_DEFAULT_FIXTURE")
require_fixture_ids SOURCE_DIVERGENT_DEFAULT SOURCE_DIVERGENT_TT SOURCE_AUTHORED_DEFAULT
[ "$SOURCE_DIVERGENT_DEFAULT" != "$SOURCE_DIVERGENT_TT" ] \
  || fail 'negative Woo default fixture does not span divergent term and TT coordinates'
wp1 eval '
$expected = (int) getenv("WPRISM_RMCOMBO_EXPECTED_DEFAULT");
$divergent = (int) getenv("WPRISM_RMCOMBO_DIVERGENT_DEFAULT");
if ((int) get_option("default_product_cat", 0) !== $expected || $expected === $divergent
    || !update_option("default_product_cat", $divergent)
    || (int) get_option("default_product_cat", 0) !== $divergent) {
    throw new RuntimeException("divergent Woo default negative fixture did not persist from the owned preimage");
}
' --exec="putenv('WPRISM_RMCOMBO_EXPECTED_DEFAULT=$SOURCE_AUTHORED_DEFAULT'); putenv('WPRISM_RMCOMBO_DIVERGENT_DEFAULT=$SOURCE_DIVERGENT_DEFAULT');" >/dev/null
DEFAULT_REFUSAL_NATIVE_BEFORE=$(native_state wp1)
DEFAULT_REFUSAL_OPTION_BEFORE=$(default_product_category_state wp1)
DEFAULT_REFUSAL_IDENTITY_BEFORE=$(identity_map_digest wp1)
DEFAULT_REFUSAL_STATE_BEFORE=$(canonical_capture_digest "$R1")
jq -en --argjson observed "$DEFAULT_REFUSAL_OPTION_BEFORE" --argjson source "$SOURCE_SEED" '
  $observed.option == $source.categories.en and
  $observed.term == {term_id:$source.categories.en,slug:"rmcombo-catalog-en"} and
  ($observed.term_taxonomy == null or
    $observed.term_taxonomy.term_taxonomy_id != $source.category_tts.en or
    $observed.term_taxonomy.term_id != $source.categories.en or
    $observed.term_taxonomy.taxonomy != "product_cat")
' >/dev/null || fail "negative Woo default did not expose the intended divergent native coordinate: $DEFAULT_REFUSAL_OPTION_BEFORE"
DEFAULT_REFUSAL_RC=0
DEFAULT_REFUSAL_STREAM=$(wp1 wprism capture --repo=/siterepo --format=json 2>&1) || DEFAULT_REFUSAL_RC=$?
require_wprism_answered 'incoherent Woo default capture refusal' json "$DEFAULT_REFUSAL_STREAM"
assert_no_php_runtime_diagnostics 'incoherent Woo default capture refusal' "$DEFAULT_REFUSAL_STREAM"
[ "$DEFAULT_REFUSAL_RC" -ne 0 ] \
  || fail "incoherent Woo default unexpectedly published: $DEFAULT_REFUSAL_STREAM"
! grep -Fq 'Warning: option default_product_cat' <<<"$DEFAULT_REFUSAL_STREAM" \
  || fail 'incoherent Woo default was downgraded to an exclusion warning'
DEFAULT_REFUSAL_JSON=$(awk 'NF { line=$0 } END { print line }' <<<"$DEFAULT_REFUSAL_STREAM")
jq -e '
  .format == "wprism-command-refusal/v1" and
  .reason_code == "reference_intersection_failed"
' <<<"$DEFAULT_REFUSAL_JSON" >/dev/null \
  || fail "incoherent Woo default did not return the exact public reference-intersection refusal: $DEFAULT_REFUSAL_STREAM"
DEFAULT_REFUSAL_NATIVE_AFTER=$(native_state wp1)
DEFAULT_REFUSAL_OPTION_AFTER=$(default_product_category_state wp1)
DEFAULT_REFUSAL_IDENTITY_AFTER=$(identity_map_digest wp1)
DEFAULT_REFUSAL_STATE_AFTER=$(canonical_capture_digest "$R1")
jq -en --argjson before "$DEFAULT_REFUSAL_NATIVE_BEFORE" --argjson after "$DEFAULT_REFUSAL_NATIVE_AFTER" \
  '$before == $after' >/dev/null \
  || fail 'reference-intersection refusal changed source plugin-native state'
jq -en --argjson before "$DEFAULT_REFUSAL_OPTION_BEFORE" --argjson after "$DEFAULT_REFUSAL_OPTION_AFTER" \
  '$before == $after' >/dev/null \
  || fail 'reference-intersection refusal changed the deliberately divergent native default'
[ "$DEFAULT_REFUSAL_IDENTITY_AFTER" = "$DEFAULT_REFUSAL_IDENTITY_BEFORE" ] \
  || fail 'reference-intersection refusal changed the source identity map'
[ "$DEFAULT_REFUSAL_STATE_AFTER" = "$DEFAULT_REFUSAL_STATE_BEFORE" ] \
  || fail 'reference-intersection refusal partially published canonical state'
wp1 eval '
$divergent = (int) getenv("WPRISM_RMCOMBO_DIVERGENT_DEFAULT");
$restore = (int) getenv("WPRISM_RMCOMBO_RESTORE_DEFAULT");
if ((int) get_option("default_product_cat", 0) !== $divergent || $divergent === $restore
    || !update_option("default_product_cat", $restore)
    || (int) get_option("default_product_cat", 0) !== $restore) {
    throw new RuntimeException("owned Woo default negative fixture did not restore exactly");
}
' --exec="putenv('WPRISM_RMCOMBO_DIVERGENT_DEFAULT=$SOURCE_DIVERGENT_DEFAULT'); putenv('WPRISM_RMCOMBO_RESTORE_DEFAULT=$SOURCE_AUTHORED_DEFAULT');" >/dev/null
SOURCE_DEFAULT_RESTORED=$(default_product_category_state wp1)
[ "$SOURCE_DEFAULT_RESTORED" = "$SOURCE_DEFAULT_NATIVE" ] \
  || fail "source Woo default did not return to its exact authored preimage: $SOURCE_DEFAULT_RESTORED"
[ "$(identity_map_digest wp1)" = "$DEFAULT_REFUSAL_IDENTITY_BEFORE" ] \
  || fail 'restoring the owned default changed an identity binding'
capture_wprism_json_checked RESTORED_DEFAULT_CAPTURE 'Rank Math combination restored-default capture' assert_rmcombo_warning_free_capture \
  wp1 wprism capture --repo=/siterepo --out=/siterepo/.tmp-rmcombo-default-restored --format=json
jq -e '.warnings == []' <<<"$RESTORED_DEFAULT_CAPTURE" >/dev/null \
  || fail "restored Woo default did not recapture warning-free: $RESTORED_DEFAULT_CAPTURE"
diff -r "$R1/state" "$R1/.tmp-rmcombo-default-restored" \
  || fail 'restored Woo default did not recapture to the exact prior canonical tree'
rm -rf "$R1/.tmp-rmcombo-default-restored"
[ "$(identity_map_digest wp1)" = "$DEFAULT_REFUSAL_IDENTITY_BEFORE" ] \
  || fail 'warning-free restored recapture changed an identity binding'
pass 'one divergent custom category refuses exactly; the explicitly authored coherent default remains portable and warning-free'

git -C "$R1" add -A
git -C "$R1" -c user.name=wprism-rmcombo -c user.email=rmcombo@example.test commit -qm 'capture: multilingual commerce SEO graph'
git -C "$R1" push -q origin main
git clone -q "$ORIGIN" "$R2"
chmod 0777 "$R2"
establish_core_environment_bindings wp2 /siterepo admin@example.test \
  "http://${PAIR}2.invalid" "http://${PAIR}2.invalid"
wprism_host_install_recovery_runtime "$ROOT" "$R1" \
  || fail 'Rank Math combination could not install the source recovery runtime'
wprism_host_install_recovery_runtime "$ROOT" "$R2" \
  || fail 'Rank Math combination could not install the target recovery runtime'

say 'deploy/apply combined product path against reverse-order hostile target'
# The headless, uniquely leased pair has one CLI writer. Stop the target web
# process as well: an unresolvable URL alone is not external writer exclusion
# for a whole-database restore (RecoverCommand::WRITERS_EXCLUDED_FLAG).
assert_rmcombo_recovery_web_state true
RECOVERY_WEB_STOP=$("${COMPOSE[@]}" stop wp2 2>&1) || fail 'could not stop the owned recovery target web process'
assert_no_php_runtime_diagnostics 'Rank Math recovery web-process stop' "$RECOVERY_WEB_STOP"
assert_rmcombo_recovery_web_state false
RECOVERY_NATIVE_BEFORE=$(native_state wp2)
RECOVERY_ORDER_BEFORE=$(active_plugin_order wp2)
RECOVERY_DEFAULT_BEFORE=$(default_product_category_state wp2)
RECOVERY_CONTROL_BEFORE=$(rmcombo_recovery_control)
jq -e '.ledger_present == false and .schema_clear == true and .provider.active == false' <<<"$RECOVERY_CONTROL_BEFORE" >/dev/null \
  || fail 'the combined recovery preimage is not a virgin ledger without settlement debt'
wp2 db query 'ALTER TABLE wp_rank_math_internal_links ADD wprism_hostile_schema varchar(12) NULL' >/dev/null
# Providers::invoke deliberately keeps the schema cause out of public errors.
# Inventory immediately before this invocation, then inspect its one new v2
# record as the target CLI uid without bootstrapping WordPress (0700/0600).
DIRTY_REFUSAL_BASELINE=$("${COMPOSE[@]}" run --rm -T \
  --volume "$ROOT/sandbox/tests/lib/PrivateRefusalReceipt.php:/wprism-test/PrivateRefusalReceipt.php:ro" \
  --entrypoint php cli2 \
  /var/www/html/wp-content/mu-plugins/adapter-packages/rank-math/fixtures/private-refusal-evidence.php \
  /wprism-test/PrivateRefusalReceipt.php snapshot schema-mismatch /siterepo/.wprism/refusals) \
  || fail 'Rank Math schema mismatch private-evidence baseline failed'
require_observed_nonempty 'Rank Math schema mismatch private-evidence baseline' "$DIRTY_REFUSAL_BASELINE"
DIRTY_DEPLOY_RC=0
DIRTY_DEPLOY=$(host_wprism_combo wp2 deploy 2>&1) || DIRTY_DEPLOY_RC=$?
[ "$DIRTY_DEPLOY_RC" -ne 0 ] || fail 'independently extended Rank Math schema did not refuse host readiness'
require_wprism_answered 'Rank Math schema mismatch deploy refusal' human "$DIRTY_DEPLOY"
assert_no_php_runtime_diagnostics 'Rank Math schema mismatch deploy refusal' "$DIRTY_DEPLOY"
grep -Fxq "Error: wprism: provider 'rank-math-state' capability 'inspect_schema' failed" <<<"$DIRTY_DEPLOY" \
  || fail 'Rank Math schema mismatch did not retain the public provider refusal'
! grep -Fq 'Rank Math schema disagrees with the audited column/index contract' <<<"$DIRTY_DEPLOY" \
  || fail 'Rank Math schema mismatch disclosed its private cause publicly'
grep -Fxq 'wprism: deploy: schema settlement failed (exit 1); later phases were not run' <<<"$DIRTY_DEPLOY" \
  || fail 'Rank Math schema mismatch did not stop deployment at schema settlement'
grep -Fxq 'wprism: deploy: promotion lease cleanup confirmed' <<<"$DIRTY_DEPLOY" \
  || fail 'Rank Math schema mismatch did not confirm promotion lease cleanup'
DIRTY_DEPLOY_PHASES=$(sed -n 's/^deploy phase: //p' <<<"$DIRTY_DEPLOY" | paste -sd ' ' -)
[ "$DIRTY_DEPLOY_PHASES" = 'compile lifecycle-status schema-status promotion-begin checkpoint provider-settlement-begin lifecycle-retire lifecycle-activate schema-settle' ] \
  || fail 'Rank Math schema mismatch skipped or crossed its checkpointed terminal phase'
DIRTY_REFUSAL_RECEIPT=$("${COMPOSE[@]}" run --rm -T \
  --volume "$ROOT/sandbox/tests/lib/PrivateRefusalReceipt.php:/wprism-test/PrivateRefusalReceipt.php:ro" \
  --entrypoint php cli2 \
  /var/www/html/wp-content/mu-plugins/adapter-packages/rank-math/fixtures/private-refusal-evidence.php \
  /wprism-test/PrivateRefusalReceipt.php verify schema-mismatch /siterepo/.wprism/refusals "$DIRTY_REFUSAL_BASELINE") \
  || fail 'Rank Math schema mismatch did not retain one exact complete private provider cause'
[ "$DIRTY_REFUSAL_RECEIPT" = '{"command":"schema-settle","format":"wprism-rank-math-private-refusal-check/v1","new_records":1,"root_message_sha256":"800d345a391e42ef89df45c65a2f3c5ac1c43527b55b20c7652bff88cde8e412","private_cause_message_sha256":"30ffabce9b0cc6d426729de1ac7be8202d2fcf0502b695b6200c23086eb95378","verified":true}' ] \
  || fail 'Rank Math schema mismatch private verification receipt is not exact'
DIRTY_NATIVE=$(native_state wp2)
jq -en --argjson before "$HOSTILE_NATIVE" --argjson after "$DIRTY_NATIVE" '$before == $after' >/dev/null \
  || fail 'schema refusal crossed the target content/runtime boundary'

# 0700be15 correctly retained provider debt, then retried deploy without
# recovery. Consume the one target-bound hint, never the newest catalog row;
# the host owns checkpoint identity, ordered import and final lease cleanup.
RECOVERY_ID=$(sed -n "s/^wprism: deploy: once that exclusion is in place, recover with: wprism recover ${PAIR}2 --restore=\(deploy-[A-Za-z0-9._-]*\) --writers-excluded --operator-directed$/\1/p" <<<"$DIRTY_DEPLOY")
[[ "$RECOVERY_ID" =~ ^deploy-[A-Za-z0-9][A-Za-z0-9._-]*$ ]] \
  || fail 'the failed combined deploy did not name exactly one target-bound retained checkpoint'
RECOVERY_CONTROL_DIRTY=$(rmcombo_recovery_control)
jq -e --arg id "$RECOVERY_ID" '
  .ledger_present == true and .schema_clear == true and .provider.active == true and
  .provider.owner == ($id | ltrimstr("deploy-")) and
  .provider.checkpoint.path == ("/siterepo/.wprism/checkpoints/" + $id + ".sql.enc")
' <<<"$RECOVERY_CONTROL_DIRTY" >/dev/null \
  || fail 'schema inspection refusal did not retain its exact external provider checkpoint before DDL'
# The first promotion installs the ledger before taking its checkpoint.
# 0f89b5c2 queried a nonexistent map before that boundary. Prove real absence
# above (the engine accepts only numeric 1146), then an installed EMPTY map
# here: lifecycle/schema settlement has no authority to mint content identity.
RECOVERY_MAP_BEFORE=$(identity_map_digest wp2)
jq -e '. == {count:0,sha256:"4f53cda18c2baa0c0354bb5f9a3ecbe5ed12ab4d8e11ba873c2f11161202b945"}' \
  <<<"$RECOVERY_MAP_BEFORE" >/dev/null || fail 'first host settlement unexpectedly minted target content identity'
assert_rmcombo_recovery_web_state false
# Host recovery emits pretty canonical JSON, unlike the agent compact-line
# transport. Validate the whole document without selecting its final line.
RECOVERY_RC=0
RECOVERY_OUT=$(host_wprism_combo wp2 recover --restore="$RECOVERY_ID" \
  --writers-excluded --operator-directed --format=json 2>&1) || RECOVERY_RC=$?
[ "$RECOVERY_RC" -eq 0 ] || fail 'combined host checkpoint recovery failed'
assert_no_php_runtime_diagnostics 'Rank Math combined checkpoint recovery' "$RECOVERY_OUT"
assert_rmcombo_one_json 'Rank Math combined checkpoint recovery' "$RECOVERY_OUT"
jq -e --arg id "$RECOVERY_ID" --arg environment "${PAIR}2" \
  --argjson debt "$RECOVERY_CONTROL_DIRTY" '
  .format == "wprism-recovery-outcome/v1" and .recovered == true and
  .environment == $environment and .checkpoint.id == $id and
  .checkpoint.owner == $debt.provider.owner and
  .checkpoint.artifact_hash == $debt.provider.artifact_hash and
  (.steps | map(.step)) == ["abort","begin","import","final-abort"] and
  all(.steps[]; .ok == true)
' <<<"$RECOVERY_OUT" >/dev/null || fail 'combined recovery did not complete the exact retained checkpoint and every ordered step'
assert_rmcombo_recovery_web_state false
RECOVERY_CONTROL_AFTER=$(rmcombo_recovery_control)
jq -en --argjson before "$RECOVERY_CONTROL_BEFORE" --argjson after "$RECOVERY_CONTROL_AFTER" \
  '$after == ($before | .ledger_present = true)' >/dev/null \
  || fail 'checkpoint recovery did not retain its initialized ledger and restore clear schema/provider control'
[ ! -e "$R2/.wprism/control/provider-settlement-intent.json" ] \
  && [ ! -L "$R2/.wprism/control/provider-settlement-intent.json" ] \
  && [ ! -e "$R2/.wprism/control/checkpoint-recovery-intent.json" ] \
  && [ ! -L "$R2/.wprism/control/checkpoint-recovery-intent.json" ] \
  || fail 'checkpoint recovery retained external settlement or recovery debt'
RECOVERY_NATIVE_AFTER=$(native_state wp2)
RECOVERY_ORDER_AFTER=$(active_plugin_order wp2)
RECOVERY_DEFAULT_AFTER=$(default_product_category_state wp2)
RECOVERY_MAP_AFTER=$(identity_map_digest wp2)
[ "$RECOVERY_NATIVE_AFTER" = "$RECOVERY_NATIVE_BEFORE" ] \
  && [ "$RECOVERY_ORDER_AFTER" = "$RECOVERY_ORDER_BEFORE" ] \
  && [ "$RECOVERY_DEFAULT_AFTER" = "$RECOVERY_DEFAULT_BEFORE" ] \
  && [ "$RECOVERY_MAP_AFTER" = "$RECOVERY_MAP_BEFORE" ] \
  || fail 'checkpoint recovery changed the combined native graph, active-plugin order, Woo default or identity map'
wp2 db query 'ALTER TABLE wp_rank_math_internal_links DROP COLUMN wprism_hostile_schema' >/dev/null
RECOVERY_WEB_START=$("${COMPOSE[@]}" start wp2 2>&1) || fail 'could not restart the recovered target web process'
assert_no_php_runtime_diagnostics 'Rank Math recovery web-process start' "$RECOVERY_WEB_START"
assert_rmcombo_recovery_web_state true
# An inactive plugin plus one absent derived table is a legitimate host-level
# drift, not content drift. It forces the no-code deploy path through both
# lifecycle and schema settlement while the hostile cross-plugin graph remains
# available as an exact isolation oracle after activation recreates the table.
# Manifest lifecycle_settle also rebuilds links: with the target module off,
# its declared postimage clears links/counts/markers, not authored state.
wp2 plugin deactivate seo-by-rank-math >/dev/null
wp2 db query 'DROP TABLE wp_rank_math_redirections_cache' >/dev/null
assert_rmcombo_host_rank_math_state wp2 "$PAIR" inactive absent
CLEAN_DEPLOY=$(host_wprism_combo wp2 deploy 2>&1) \
  || fail "clean Rank Math combination host deploy failed; private captures: $ROOT/sandbox/tmp/wprism-rmcombo-deploy.$PAIR.*"
assert_no_php_runtime_diagnostics 'clean Rank Math combination host deploy' "$CLEAN_DEPLOY"
CLEAN_DEPLOY_PHASES=$(sed -n 's/^deploy phase: //p' <<<"$CLEAN_DEPLOY" | paste -sd ' ' -)
[ "$CLEAN_DEPLOY_PHASES" = 'compile lifecycle-status schema-status promotion-begin checkpoint provider-settlement-begin lifecycle-retire lifecycle-activate schema-settle lifecycle-settle provider-settlement-complete' ] \
  || fail "compatible host deploy skipped or reordered checkpointed lifecycle/schema settlement: $CLEAN_DEPLOY"
assert_rmcombo_host_rank_math_state wp2 "$PAIR" active present
[ ! -e "$R2/.wprism/control/provider-settlement-intent.json" ] \
  || fail 'successful compatible host deploy retained provider settlement debt'
HOST_SETTLED_ORDER=$(active_plugin_order wp2)
jq -en --argjson actual "$HOST_SETTLED_ORDER" --argjson expected "$expected_source" \
  '$actual == $expected' >/dev/null \
  || fail "host lifecycle did not settle canonical source plugin order: $HOST_SETTLED_ORDER"
HOST_SETTLED_NATIVE=$(native_state wp2)
jq -en --argjson before "$HOSTILE_NATIVE" --argjson after "$HOST_SETTLED_NATIVE" '
  $before.modules == ["redirections","rich-snippet"] and
  $after == ($before | .redirection_cache = [] |
    .products.en.links = [] | .products.de.links = [] | .book.links = [] |
    .products.en.rank_counts = null | .products.de.rank_counts = null | .book.rank_counts = null |
    .products.en.processed = false | .products.de.processed = false | .book.processed = false |
    .retired_target_counts = null | .stale_link_sentinels = 0)
' >/dev/null || fail 'compatible host settlement did not retain the exact declared derived-state and unrelated-state postimage'
HOST_SETTLED_DEFAULT=$(default_product_category_state wp2)
jq -en --argjson fixture "$TARGET_DEFAULT_FIXTURE" --argjson observed "$HOST_SETTLED_DEFAULT" '
  $observed.option == $fixture.installer_default and
  $observed.option != $fixture.term_id and
  $observed.term.term_id == $observed.option and
  $observed.term_taxonomy == {
    term_taxonomy_id:$observed.option,
    term_id:$observed.option,
    taxonomy:"product_cat"
  }
' >/dev/null || fail "host settlement changed or invalidated the target installer default before apply: $HOST_SETTLED_DEFAULT"
pass 'host deploy refuses hostile schema, then checkpoint-settles legitimate lifecycle/schema drift without crossing combination boundaries'
# Host settlement just removed every stale projection. Reintroduce the same
# checked witnesses before Apply so its enabled-module repair is not credited
# for cleanup already performed by lifecycle_settle (6fbf7298 live evidence).
seed_rmcombo_stale_links
APPLY_HOSTILE_NATIVE=$(native_state wp2)
jq -en --argjson before "$HOSTILE_NATIVE" --argjson after "$APPLY_HOSTILE_NATIVE" \
  '$after == ($before | .redirection_cache = [])' >/dev/null \
  || fail 'combined Apply did not start with exact non-vacuous stale links and preserved unrelated state'
REVISION=$(git -C "$R2" rev-parse HEAD)
capture_wprism_json_checked INITIAL 'Rank Math commerce/multilingual initial apply' assert_rmcombo_default_apply_ready \
  wp2 wprism apply --repo=/siterepo --adopt-by-slug=terms,posts \
  --default-author=admin --revision="$REVISION" --format=json
jq -e '
  .canary == "clean" and .verification.result == "pass" and
  all(.warnings[]?; contains("default_product_cat") | not) and
  any(.actions[]?; .source == "provider:rank-math-state/rebuild_all_link_state" and .verified == true) and
  any(.actions[]?; .source == "provider:woocommerce-product-lookups/rebuild_product_lookups" and .verified == true) and
  any(.actions[]?; .source == "provider:polylang-nav-menus/synchronize_runtime" and .verified == true)
' <<<"$INITIAL" >/dev/null || fail "combined provider receipt is incomplete: $INITIAL"
jq -e '
  ([.actions[]?.source | select(startswith("provider:rank-math-state/"))] | sort | unique) ==
  ["provider:rank-math-state/rebuild_all_link_state"]
' <<<"$INITIAL" >/dev/null || fail "initial apply selected an unexpected Rank Math action set: $INITIAL"

TARGET=$(native_state wp2)
require_observed_nonempty 'Rank Math combination converged target' "$TARGET"
TARGET_DEFAULT_NATIVE=$(default_product_category_state wp2)
TARGET_DEFAULT_IDENTITY=$(default_product_category_identity wp2 "$SOURCE_DEFAULT_UUID")
jq -en --argjson source_fixture "$SOURCE_DEFAULT_FIXTURE" \
  --argjson target_fixture "$TARGET_DEFAULT_FIXTURE" \
  --argjson native "$TARGET_DEFAULT_NATIVE" --argjson identity "$TARGET_DEFAULT_IDENTITY" \
  --arg uuid "$SOURCE_DEFAULT_UUID" '
  $source_fixture.term_id != $target_fixture.term_id and
  $target_fixture.option == $target_fixture.installer_default and
  $target_fixture.installer_default != $target_fixture.term_id and
  $native == {
    option:$target_fixture.term_id,
    term:{term_id:$target_fixture.term_id,slug:"rmcombo-default-product-category"},
    term_taxonomy:{term_taxonomy_id:$target_fixture.term_id,term_id:$target_fixture.term_id,taxonomy:"product_cat"}
  } and
  ($identity | length) == 2 and
  ($identity | map(.id_kind)) == ["term","term_taxonomy"] and
  all($identity[]; .uuid == $uuid and .entity_type == "term" and .local_id == $target_fixture.term_id)
' >/dev/null || fail "portable Woo default UUID did not resolve to the target matching native coordinate: $TARGET_DEFAULT_NATIVE / $TARGET_DEFAULT_IDENTITY"
jq -en --argjson source "$SOURCE_SEED" --argjson hostile "$TARGET_SEED" \
  --argjson hostile_native "$HOSTILE_NATIVE" --argjson target "$TARGET" '
  ($target.products.en.id == $hostile.products.en) and
  ($target.products.de.id == $hostile.products.de) and
  ($target.products.en.id != $source.products.en) and
  ($target.products.de.id != $source.products.de) and
  ($target.book.id == $hostile.book) and ($target.book.id != $source.book) and
  ($target.categories.en == $hostile.categories.en) and
  ($target.categories.de == $hostile.categories.de) and
  ($source.categories.en != $source.category_tts.en) and
  ($source.categories.de != $source.category_tts.de) and
  ($hostile.categories.en != $hostile.category_tts.en) and
  ($hostile.categories.de != $hostile.category_tts.de) and
  ($source.categories.en != $hostile.categories.en) and
  ($source.category_tts.en != $hostile.category_tts.en) and
  ($target.products.en.language == "en") and ($target.products.de.language == "de") and
  ($target.category_languages.en == "en") and ($target.category_languages.de == "de") and
  ($target.translations.en == $target.products.en.id) and
  ($target.translations.de == $target.products.de.id) and
  ($target.term_translations.en == $target.categories.en) and
  ($target.term_translations.de == $target.categories.de) and
  ($target.products.en.primary == $target.categories.en) and
  ($target.products.de.primary == $target.categories.de) and
  ($target.products.en.acf == "English badge 東京") and
  ($target.products.de.acf == "Deutsches Abzeichen 東京") and
  ($target.products.en.price == "29") and ($target.products.de.price == "31") and
  (($target.products.en.lookup.min_price | tonumber) == 29) and
  (($target.products.de.lookup.min_price | tonumber) == 31) and
  ($target.products.en.links | length) == 2 and ($target.products.de.links | length) == 2 and
  ([ $target.products.en.links[].type ] | sort) == ["external","internal"] and
  ([ $target.products.de.links[].type ] | sort) == ["external","internal"] and
  ([ $target.products.en.links[] | select(.type == "internal") ][0].target_post_id | tonumber) == $target.products.de.id and
  ([ $target.products.de.links[] | select(.type == "internal") ][0].target_post_id | tonumber) == $target.products.en.id and
  ([ $target.products.en.links[] | select(.type == "external") ][0].target_post_id | tonumber) == 0 and
  ([ $target.products.de.links[] | select(.type == "external") ][0].target_post_id | tonumber) == 0 and
  ($target.products.en.rank_counts | map_values(tonumber)) == {external_link_count:1,incoming_link_count:1,internal_link_count:1} and
  ($target.products.de.rank_counts | map_values(tonumber)) == {external_link_count:1,incoming_link_count:1,internal_link_count:1} and
  ($target.products.en.processed == true) and ($target.products.de.processed == true) and
  ($target.book.processed == true) and
  ($target.book.title == "Portable custom CPT SEO 東京") and
  ($target.book.links | length) == 2 and
  ([ $target.book.links[].type ] | sort) == ["external","internal"] and
  ([ $target.book.links[] | select(.type == "internal") ][0].target_post_id | tonumber) == $target.products.en.id and
  ($target.book.rank_counts | map_values(tonumber)) == {external_link_count:1,incoming_link_count:0,internal_link_count:1} and
  ($target.products.en.title == "Portable Rank Math Commerce EN 東京 🚀") and
  ($target.products.de.title == "Tragbarer Rank Math Handel DE 東京 🚀") and
  ($target.products.en.description == "Portable English commerce SEO 東京.") and
  ($target.products.de.description == "Tragbare deutsche Commerce-SEO 東京.") and
  ($target.products.en.canonical == $target.products.en.url) and
  ($target.products.de.canonical == $target.products.de.url) and
  (($target.modules | index("link-counter")) != null) and
  ($target.redirection.id == $hostile.redirection) and
  ($target.redirection.sources == [{ignore:"",pattern:"rmcombo-old",comparison:"exact"}]) and
  ($target.redirection.url_to == $target.products.en.url) and
  ($target.redirection.header_code == 302) and
  ($target.redirection.hits == 41) and ($target.redirection.status == "active") and
  ($target.redirection_cache | length) == 0 and
  ($target.retired_target_counts.incoming_link_count | tonumber) == 0 and
  ($target.neighbor == $hostile_native.neighbor) and
  ($target.scheduler == $hostile_native.scheduler) and
  ($target.scheduler | all(.hook == "rmcombo_target_runtime" and .status == "pending" and .group_slug == "")) and
  ($target.stale_link_sentinels == 0)
' >/dev/null || fail "combined native state did not converge across local identities: $TARGET"
pass 'Woo, Polylang, ACF, Rank Math and a site-defined CPT converge while target runtime survives'

EN_BODY=$(product_response rmcombo-product-en)
DE_BODY=$(product_response rmcombo-product-de)
EN_HEAD=$(head_projection <<<"$EN_BODY")
DE_HEAD=$(head_projection <<<"$DE_BODY")
jq -en --argjson en "$EN_HEAD" --argjson de "$DE_HEAD" --argjson state "$TARGET" '
  $en.title == "Portable Rank Math Commerce EN 東京 🚀" and
  $en.description == "Portable English commerce SEO 東京." and
  $en.canonical == $state.products.en.url and $en.og_url == $state.products.en.url and
  $en.og_title == $en.title and $en.og_description == $en.description and $en.og_locale == "en_US" and
  $de.title == "Tragbarer Rank Math Handel DE 東京 🚀" and
  $de.description == "Tragbare deutsche Commerce-SEO 東京." and
  $de.canonical == $state.products.de.url and $de.og_url == $state.products.de.url and
  $de.og_title == $de.title and $de.og_description == $de.description and $de.og_locale == "de_DE" and
  $en.alternates["en-US"] == $state.products.en.url and
  $en.alternates["de-DE"] == $state.products.de.url and
  $de.alternates["en-US"] == $state.products.en.url and
  $de.alternates["de-DE"] == $state.products.de.url
' >/dev/null || fail "exact canonical/OG/reciprocal hreflang head state diverged: en=$EN_HEAD de=$DE_HEAD"
pass 'exact reciprocal hreflang, canonical and Open Graph state renders on both products'

redirection_response
[ "$REDIRECT_STATUS" = 302 ] && [ "$REDIRECT_LOCATION" = "$(jq -r '.products.en.url' <<<"$TARGET")" ] \
  || fail "native Rank Math redirection diverged: status=$REDIRECT_STATUS location=${REDIRECT_LOCATION:-<none>}"
TARGET_RUNTIME=$(native_state wp2)
jq -en --argjson before "$TARGET" --argjson after "$TARGET_RUNTIME" '
  $after.redirection.hits == ($before.redirection.hits + 1) and
  $after.redirection.header_code == 302 and $after.redirection.status == "active" and
  $after.redirection.url_to == $before.products.en.url and
  $after.neighbor == $before.neighbor and $after.scheduler == $before.scheduler
' >/dev/null || fail "native redirect telemetry or target runtime isolation diverged: $TARGET_RUNTIME"
pass 'native 302 routing consumes the target-bound URL and advances only target-local traffic telemetry'

say 'hostile ACF ownership overlap refuses under both manifest orders before publication'
POLICY_BYTES=$(<"$R1/site.wprism.json")
STATE_HASH_BEFORE=$(find "$R1/state" -type f -exec shasum -a 256 {} + | shasum -a 256 | awk '{print $1}')
wp1 eval '
$group=get_posts(["post_type"=>"acf-field-group","name"=>"group_rmcombo_product","posts_per_page"=>1,"fields"=>"ids","post_status"=>"any"]);
$product=get_page_by_path("rmcombo-product-en",OBJECT,"product");
if(!$group||!$product) throw new RuntimeException("ACF collision premise is absent");
acf_update_field(["key"=>"field_rmcombo_rank_title","label"=>"Hostile Rank Title","name"=>"rank_math_title","type"=>"post_object","post_type"=>["product"],"return_format"=>"id","parent"=>(int)$group[0]]);
update_field("field_rmcombo_rank_title",(int)$product->ID,(int)$product->ID);
' >/dev/null
for order in forward reverse; do
  if [ "$order" = forward ]; then
    manifests='["core","acf","polylang","rank-math","woocommerce"]'
  else
    manifests='["core","woocommerce","rank-math","polylang","acf"]'
  fi
  jq --argjson manifests "$manifests" '.manifests=$manifests' <<<"$POLICY_BYTES" > "$R1/site.wprism.json"
  COLLISION_RC=0
  COLLISION_OUT=$(wp1 wprism capture --repo=/siterepo 2>&1) || COLLISION_RC=$?
  [ "$COLLISION_RC" -ne 0 ] \
    && grep -Fq "post_meta 'rank_math_title' has multiple classification owners" <<<"$COLLISION_OUT" \
    || fail "ACF/Rank Math collision did not refuse under $order order: $COLLISION_OUT"
  [ "$(find "$R1/state" -type f -exec shasum -a 256 {} + | shasum -a 256 | awk '{print $1}')" = "$STATE_HASH_BEFORE" ] \
    || fail "ACF/Rank Math $order-order refusal partially published state"
done
printf '%s\n' "$POLICY_BYTES" > "$R1/site.wprism.json"
wp1 eval '
$field=acf_get_field("field_rmcombo_rank_title");
if(is_array($field)) acf_delete_field($field);
$product=get_page_by_path("rmcombo-product-en",OBJECT,"product");
delete_post_meta($product->ID,"_rank_math_title");
update_post_meta($product->ID,"rank_math_title","Portable Rank Math Commerce EN 東京 🚀");
' >/dev/null
capture_wprism_json_checked COLLISION_RESTORED_CAPTURE \
  'Rank Math combination collision-restored capture' assert_rmcombo_warning_free_capture \
  wp1 wprism capture --repo=/siterepo --out=/siterepo/.tmp-rmcombo-collision-restored --format=json
diff -r "$R1/state" "$R1/.tmp-rmcombo-collision-restored" \
  || fail 'source did not return to its canonical state after removing the hostile ACF field'
rm -rf "$R1/.tmp-rmcombo-collision-restored"
pass 'hostile ACF ownership collision refuses identically under both pin orders'

say 'combined provider failure retains retry authority and converges after repair'
SOURCE_EN=$(jq -r '.products.en' <<<"$SOURCE_SEED")
require_fixture_ids SOURCE_EN
wp1 eval '
$post=get_post((int)getenv("WPRISM_RMCOMBO_SOURCE_EN"));
if(!$post instanceof WP_Post) throw new RuntimeException("retry source product is absent");
$post->post_content .= "<p>provider failure retained combined retry authority <a href=\"https://retry.example.test/\">retry external</a></p>";
wp_update_post($post);
' --exec="putenv('WPRISM_RMCOMBO_SOURCE_EN=$SOURCE_EN');" >/dev/null
capture_wprism_json_checked RETRY_SOURCE_CAPTURE \
  'Rank Math combination retry source capture' assert_rmcombo_warning_free_capture \
  wp1 wprism capture --repo=/siterepo --format=json
git -C "$R1" add -A
git -C "$R1" -c user.name=wprism-rmcombo -c user.email=rmcombo@example.test commit -qm 'capture: combined provider retry intent'
git -C "$R1" push -q origin main
git -C "$R2" pull -q origin main
FAIL_REV_BEFORE=$(wp2 db query "SELECT v FROM wp_wprism_kv WHERE k='applied_revision'" --skip-column-names | tr -d '[:space:]')
require_observed_nonempty 'Rank Math combination applied revision before fault' "$FAIL_REV_BEFORE"
install_hostile_provider
FAILURE_RC=0
FAILURE_OUT=$(wp2 wprism apply --repo=/siterepo --default-author=admin 2>&1) || FAILURE_RC=$?
[ "$FAILURE_RC" -ne 0 ] \
  && grep -Fq "provider 'rank-math-state' capability 'rebuild_all_link_state' failed" <<<"$FAILURE_OUT" \
  || fail "combined Rank Math provider fault did not refuse: $FAILURE_OUT"
[ "$(wp2 db query "SELECT v FROM wp_wprism_kv WHERE k='applied_revision'" --skip-column-names | tr -d '[:space:]')" = "$FAIL_REV_BEFORE" ] \
  || fail 'combined provider failure advanced applied_revision'
[ "$(wp2 eval 'echo null===\WPrism\Ledger::kv_get("apply_in_progress")?"clear":"retained";' | tail -1)" = retained ] \
  || fail 'combined provider failure did not retain retry authority'
FAILURE_NATIVE=$(native_state wp2)
jq -en --argjson baseline "$TARGET_RUNTIME" --argjson failed "$FAILURE_NATIVE" '
  ($failed | .products.en.content = $baseline.products.en.content) == $baseline and
  ($failed.products.en.content | contains("provider failure retained combined retry authority")) and
  ([ $failed.products.en.links[] | select(.url | contains("retry.example.test")) ] | length) == 0
' >/dev/null || fail "combined provider failure crossed a runtime boundary or fabricated derived success: $FAILURE_NATIVE"
remove_hostile_provider
capture_wprism_json_checked RETRY 'Rank Math combination provider retry' assert_rmcombo_default_apply_ready \
  wp2 wprism apply --repo=/siterepo --default-author=admin --format=json
jq -e '
  .canary == "clean" and .verification.result == "pass" and
  any(.actions[]?; .source == "provider:rank-math-state/rebuild_all_link_state" and .verified == true) and
  any(.actions[]?; .source == "provider:woocommerce-product-lookups/rebuild_product_lookups" and .verified == true)
' <<<"$RETRY" >/dev/null || fail "combined retry receipt is incomplete: $RETRY"
jq -e '
  ([.actions[]?.source | select(startswith("provider:rank-math-state/"))] | sort | unique) ==
  ["provider:rank-math-state/rebuild_all_link_state"]
' <<<"$RETRY" >/dev/null || fail "retry selected an unexpected Rank Math action set: $RETRY"
RETRY_NATIVE=$(native_state wp2)
jq -en --argjson baseline "$TARGET_RUNTIME" --argjson retried "$RETRY_NATIVE" '
  ($retried
    | .products.en.content = $baseline.products.en.content
    | .products.en.links = $baseline.products.en.links
    | .products.en.rank_counts = $baseline.products.en.rank_counts) == $baseline and
  ($retried.products.en.content | contains("provider failure retained combined retry authority")) and
  ($retried.products.en.links | length) == 3 and
  ([ $retried.products.en.links[].type ] | sort) == ["external","external","internal"] and
  ([ $retried.products.en.links[] | select(.url | contains("retry.example.test")) ] | length) == 1 and
  ($retried.products.en.rank_counts | map_values(tonumber)) == {external_link_count:2,incoming_link_count:1,internal_link_count:1} and
  ($retried.products.de.rank_counts | map_values(tonumber)) == {external_link_count:1,incoming_link_count:1,internal_link_count:1}
' >/dev/null || fail "combined retry did not converge the exact new link projection in isolation: $RETRY_NATIVE"
pass 'provider failure and retry preserve every Woo, Polylang, ACF, taxonomy, module, CPT and target-runtime witness outside the intended Rank Math projection'

say 'combined recapture and repeated apply are exact no-ops'
REVISION=$(git -C "$R2" rev-parse HEAD)
capture_wprism_json_checked NOOP 'Rank Math combination no-op apply' assert_rmcombo_default_apply_ready \
  wp2 wprism apply --repo=/siterepo --default-author=admin --revision="$REVISION" --format=json
jq -e '.canary == "clean" and (.actions | length) == 0' <<<"$NOOP" >/dev/null \
  || fail "combined no-op reran effects: $NOOP"
capture_wprism_json_checked TARGET_RECAPTURE 'Rank Math combination target recapture' \
  assert_rmcombo_warning_free_capture \
  wp2 wprism capture --repo=/siterepo --out=/siterepo/.tmp-rmcombo-final --format=json
FINAL_DIFF=$(diff -rq "$R1/state" "$R2/.tmp-rmcombo-final" || true)
rm -rf "$R2/.tmp-rmcombo-final"
[ -z "$FINAL_DIFF" ] || fail "combined target recapture differs: $FINAL_DIFF"
TARGET_FINAL=$(native_state wp2)
jq -en --argjson retried "$RETRY_NATIVE" --argjson final "$TARGET_FINAL" '
  $final == $retried and $final.products.en.processed == true and $final.products.de.processed == true
' >/dev/null || fail "combined final native/runtime state was not an exact no-op: $TARGET_FINAL"
[ "$(default_product_category_state wp2)" = "$TARGET_DEFAULT_NATIVE" ] \
  || fail 'combined retry/no-op path changed the applied portable Woo default category'
product_response rmcombo-product-en >/dev/null
product_response rmcombo-product-de >/dev/null
pass "full source=$source_order target=$target_order path recaptures byte-identically and repeats with zero actions"

say 'direct deletion remains refusal-only across the combined adapter boundary'
SOURCE_BOOK=$(jq -r '.book' <<<"$SOURCE_SEED")
TARGET_BOOK=$(jq -r '.book.id' <<<"$TARGET_FINAL")
require_fixture_ids SOURCE_BOOK TARGET_BOOK
wp1 post delete "$SOURCE_BOOK" --force >/dev/null
capture_wprism_json_checked DELETE_SOURCE_CAPTURE \
  'Rank Math combination deletion source capture' assert_rmcombo_warning_free_capture \
  wp1 wprism capture --repo=/siterepo --format=json
git -C "$R1" add -A
git -C "$R1" -c user.name=wprism-rmcombo -c user.email=rmcombo@example.test \
  commit -qm 'capture: delete custom CPT with derived Rank Math links'
git -C "$R1" push -q origin main
git -C "$R2" pull -q origin main
DELETE_WITHHELD_RC=0
DELETE_WITHHELD=$(wp2 wprism apply --repo=/siterepo --default-author=admin 2>&1) || DELETE_WITHHELD_RC=$?
[ "$DELETE_WITHHELD_RC" -ne 0 ] && grep -q -- '--with-deletes' <<<"$DELETE_WITHHELD" \
  || fail "combined custom-CPT deletion did not require explicit delete authority: $DELETE_WITHHELD"
wp2 post get "$TARGET_BOOK" --field=ID >/dev/null \
  || fail 'withheld Rank Math deletion removed the target post'
DELETE_REVISION=$(git -C "$R2" rev-parse HEAD)
DELETE_DIRECT_RC=0
DELETE_DIRECT=$(wp2 wprism apply --repo=/siterepo --with-deletes --default-author=admin \
  --revision="$DELETE_REVISION" --format=json 2>&1) || DELETE_DIRECT_RC=$?
require_wprism_answered 'Rank Math combination direct custom-CPT deletion refusal' json "$DELETE_DIRECT"
[ "$DELETE_DIRECT_RC" -ne 0 ] \
  && tail -1 <<<"$DELETE_DIRECT" | jq -e '.reason_code == "deletion_writer_exclusion_required"' >/dev/null \
  || fail "combined direct custom-CPT deletion crossed without signed external exclusion: $DELETE_DIRECT"
DELETE_REFUSAL_NATIVE=$(native_state wp2)
jq -en --argjson before "$TARGET_FINAL" --argjson after "$DELETE_REFUSAL_NATIVE" '$after == $before' >/dev/null \
  || fail "combined direct deletion refusal changed native or target-runtime state: $DELETE_REFUSAL_NATIVE"
wp2 post get "$TARGET_BOOK" --field=ID >/dev/null \
  || fail 'external-exclusion refusal removed the target custom post'
pass 'both direct deletion forms refuse before combined portable, derived, or target-runtime mutation; signed promotion is exercised by the SSH scenario extension'
}

# The generic lease is the sole fresh-namespace authority: under the shared
# lock it proves Compose/name/ports/roots/install markers plus both persistent
# schemas absent, then binds that complete fact to this exact process. Arm
# cleanup only after publication and hold the token through both legs.
pair_live_ownership_acquire mariadb
say "bring up caller-allocated Rank Math combination pair at candidate $HEAD"
pair_live_ownership_up --artifacts --headless
run_leg forward reverse

# The reverse-order leg reuses only the pair this process already created.
# Reset stays here, between completed legs, and is never an ownership shortcut.
[ "$PAIR_LIVE_OWNERSHIP_LEASE_ACTIVE" -eq 1 ] \
  || fail 'Rank Math combination lost pair ownership before its second leg'
pair_live_ownership_reset
pair_live_ownership_up --artifacts --headless
run_leg reverse forward

pair_live_ownership_complete '✔ REGRESS_RANK_MATH_COMMERCE_MULTILINGUAL PASSED'
