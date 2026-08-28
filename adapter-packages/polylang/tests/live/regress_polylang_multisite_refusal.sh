#!/usr/bin/env bash
# Candidate-bound exact Polylang 3.8.6 scope refusal.  The source graph is
# seeded through Polylang's own APIs, then converted in place to a real
# network.  Every adapter-facing command must refuse before repository,
# authored-state, or plugin bytes can change.
set -euo pipefail
PACKAGE_ROOT="$(cd "$(dirname "$0")/../.." && pwd -P)"
export DUO_ARTIFACT_PACKAGE="${PACKAGE_ROOT##*/}"
cd "$PACKAGE_ROOT/../../sandbox"

fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*" >&2; exit 1; }
. conformance/asserts.sh

say() { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }

PAIR="${POLYLANG_MULTISITE_PAIR:-pllms}"
PORT1="${POLYLANG_MULTISITE_PORT1:-9020}"
PORT2="${POLYLANG_MULTISITE_PORT2:-9021}"
EXPECTED_SHA="${POLYLANG_MULTISITE_EXPECTED_SOURCE_SHA:-${DUO_EXPECTED_SOURCE_SHA:-}}"
ROOT="$(cd .. && pwd -P)"
HEAD="$(git -C "$ROOT" rev-parse HEAD)"
[[ "$PAIR" =~ ^[a-z][a-z0-9]*$ ]] || fail "invalid POLYLANG_MULTISITE_PAIR '$PAIR'"
[[ "$PORT1" =~ ^[0-9]+$ && "$PORT2" =~ ^[0-9]+$ && "$PORT1" != "$PORT2" ]] \
  || fail 'Polylang multisite ports must be distinct decimal integers'
[[ "$EXPECTED_SHA" =~ ^[0-9a-f]{40}$ ]] || fail 'Polylang multisite evidence requires a 40-character candidate SHA'
[ "$EXPECTED_SHA" = "$HEAD" ] || fail "candidate SHA $EXPECTED_SHA does not equal checkout HEAD $HEAD"
[ -z "$(git -C "$ROOT" status --porcelain=v1 --untracked-files=all)" ] \
  || fail 'Polylang multisite evidence requires a clean candidate checkout'
command -v jq >/dev/null || fail 'jq required'

WORDPRESS_OFFLINE="${DUO_WORDPRESS_ORG_OFFLINE:-0}"
case "$WORDPRESS_OFFLINE" in 0|1) ;; *) fail 'DUO_WORDPRESS_ORG_OFFLINE must be 0 or 1' ;; esac
export DUO_SOURCE_ROOT="$ROOT" DUO_EXPECTED_SOURCE_SHA="$EXPECTED_SHA" DUO_PAIR="$PAIR"
PAIR_COMPOSE=(docker compose -p "duo-$PAIR" -f pair.yml -f pair.artifacts.yml)
UP_FLAGS=(--artifacts)
if [ "$WORDPRESS_OFFLINE" = 1 ]; then
  PAIR_COMPOSE+=(-f pair.wordpress-offline.yml)
  UP_FLAGS+=(--wordpress-offline)
fi
export DUO_ARTIFACT_OFFLINE="$WORDPRESS_OFFLINE"
wp1() { "${PAIR_COMPOSE[@]}" run --rm -T cli1 wp "$@"; }
REPO="siterepo/${PAIR}1"
. bin/fetch-artifact.sh
validate_artifact_library || fail 'artifact library validation failed'
artifact_library_jq -e '.plugins.polylang["3.8.6"].sha256 == "dd2a213d407c6d565eb5e246e68b434003f1112c059ee53ca070bf97102010aa"' \
  >/dev/null || fail 'exact Polylang 3.8.6 artifact pin drifted'

GREEN=0
cleanup() { [ "$GREEN" = 1 ] && bash bin/pair.sh destroy "$PAIR" >/dev/null 2>&1 || true; }
trap cleanup EXIT

say "fresh exact Polylang 3.8.6 pair $PAIR"
bash bin/pair.sh reset "$PAIR"
bash bin/pair.sh up "$PAIR" "$PORT1" "$PORT2" "${UP_FLAGS[@]}"
ARTIFACT=$(fetch_artifact polylang 3.8.6 cli1 plugin)
wp1 plugin install "$ARTIFACT" --force --activate >/dev/null
[ "$(wp1 plugin get polylang --field=version)" = 3.8.6 ] || fail 'exact Polylang 3.8.6 artifact did not activate'
PLUGIN_SHA=$(wp1 eval 'echo hash_file("sha256", WP_PLUGIN_DIR . "/polylang/polylang.php");' | tail -1)
require_observed_nonempty 'Polylang plugin source hash' "$PLUGIN_SHA"

CONF_REPO1="$REPO"
CONF1_PORT="$PORT1"
COMPOSE="${PAIR_COMPOSE[*]}"
export CONF_REPO1 CONF1_PORT COMPOSE
wp_conf1() { wp1 "$@"; }
. "$(dirname "${BASH_SOURCE[0]}")/../conformance/seed.sh"
unset -f wp_conf1
jq -n '{manifests:["core","polylang"],policy:{options:{},post_meta:{},post_types:["post","page","attachment","wp_block","nav_menu_item"],taxonomies:["category","post_tag","language","term_language","post_translations","term_translations","nav_menu"]},spec_version:2}' > "$REPO/site.duo.json"
cp site-repo.gitignore.template "$REPO/.gitignore"
wp1 option update duo_polylang_multisite_canary untouched >/dev/null
SITE_BEFORE=$(shasum -a 256 "$REPO/site.duo.json" | awk '{print $1}')

say 'convert the populated exact fixture to a real WordPress multisite'
wp1 core multisite-convert --title='Duo Polylang 3.8.6 Multisite Refusal' >/dev/null
[ "$(wp1 eval 'echo is_multisite() ? "yes" : "no";')" = yes ] || fail 'WordPress did not report multisite'
[ "$(wp1 plugin get polylang --field=version)" = 3.8.6 ] || fail 'multisite conversion changed Polylang version'
PLUGIN_AFTER=$(wp1 eval 'echo hash_file("sha256", WP_PLUGIN_DIR . "/polylang/polylang.php");' | tail -1)
[ "$PLUGIN_AFTER" = "$PLUGIN_SHA" ] || fail 'multisite conversion changed Polylang plugin bytes'

polylang_graph_fingerprint() { wp1 eval '
global $wpdb;
$rows=[];
foreach (["posts","terms","term_taxonomy","term_relationships","postmeta","options"] as $table) {
  $name=$wpdb->$table;
  // WordPress renews this one process-local cron lease during a long WP-CLI
  // command. It is runtime coordination, not authored/plugin/Duo state; keep
  // every other option row inside the zero-effect fingerprint.
  $sql=$table === "options"
    ? $wpdb->prepare("SELECT * FROM $name WHERE option_name <> %s ORDER BY 1", "_transient_doing_cron")
    : "SELECT * FROM $name ORDER BY 1";
  $data=$wpdb->get_results($sql, ARRAY_A);
  if (!is_array($data) || $wpdb->last_error !== "") throw new RuntimeException("Polylang multisite fingerprint read failed: $table");
  $rows[$table]=["count"=>count($data),"sha256"=>hash("sha256",serialize($data))];
}
if ($rows["posts"]["count"] < 10 || $rows["terms"]["count"] < 10) throw new RuntimeException("Polylang fixture is not populated");
echo hash("sha256", wp_json_encode($rows));
' | tail -1; }
FINGERPRINT=$(polylang_graph_fingerprint)
require_observed_nonempty 'Polylang multisite populated graph' "$FINGERPRINT"
[ "$(polylang_graph_fingerprint)" = "$FINGERPRINT" ] || fail 'Polylang multisite fixture is not stable across ordinary network boots'

for command in capture plan deploy apply; do
  say "multisite $command must refuse before effect"
  set +e
  OUT=$(wp1 duo "$command" --repo=/siterepo --format=json 2>/dev/null)
  RC=$?
  set -e
  [ "$RC" -ne 0 ] || fail "Polylang multisite $command returned success"
  printf '%s\n' "$OUT" | awk 'NF { line=$0 } END { print line }' | jq -e --arg command "$command" '
    .format == "duo-command-refusal/v1" and .ok == false and .command == $command and
    .reason_code == "multisite_unsupported" and .error == "multisite_unsupported" and
    (has("details_redacted") | not) and (.message | contains("multisite is unsupported")) and
    (.remediation | contains("single-site"))
  ' >/dev/null || fail "Polylang multisite $command did not return typed refusal"
  [ "$(wp1 eval 'echo hash_file("sha256", WP_PLUGIN_DIR . "/polylang/polylang.php");' | tail -1)" = "$PLUGIN_SHA" ] || fail "Polylang multisite $command changed plugin bytes"
  [ "$(polylang_graph_fingerprint)" = "$FINGERPRINT" ] || fail "Polylang multisite $command mutated the populated graph"
  [ "$(shasum -a 256 "$REPO/site.duo.json" | awk '{print $1}')" = "$SITE_BEFORE" ] || fail "Polylang multisite $command mutated site.duo.json"
  [ ! -e "$REPO/state" ] && [ ! -e "$REPO/state.capture-staging" ] && [ ! -e "$REPO/state.capture-backup" ] || fail "Polylang multisite $command published repository state"
  [ "$(wp1 option get duo_polylang_multisite_canary)" = untouched ] || fail "Polylang multisite $command mutated authored state"
  pass "Polylang multisite $command refused with zero repository/plugin effect"
done

GREEN=1
printf '\n\033[1;32m✔ REGRESS_POLYLANG_MULTISITE_REFUSAL PASSED\033[0m\n'
