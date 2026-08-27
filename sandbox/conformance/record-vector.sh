#!/usr/bin/env bash
# The LIVE half of WP-2.7: leave a replayable `duo-conformance-vector/v1`
# document behind after a sweep that already passed.
#
# This is a HOOK ON run.sh, deliberately not a second harness. Everything a
# vector holds exists only inside one sweep, only at one instant, and only
# because run.sh's own acceptance just succeeded:
#
#   * conf1's `state/` tree and conf2's recapture, which run.sh has just
#     proven `diff -r`-identical ("canonical state identical across
#     environments") — a recorder that booted its own pair would be recording
#     a round trip nobody checked;
#   * the LIVE rows behind that tree, which only exist while conf1 is up;
#   * a `duo-adapter-probe/v1` document read off that same running target, at
#     the exact pinned plugin version conformance/artifacts.lock.json installed.
#
# docs/agents/live-pair-budget.md allocates order 4 to exactly this: one
# recording per vector, then the offline replay
# (sandbox/tests/offline/capture/regress_conformance_vector_replay.php) forever.
#
# Usage (invoked by run.sh when CONF_RECORD_VECTOR is set; runnable by hand
# from sandbox/ inside a live sweep):
#   bash conformance/record-vector.sh <out.json> <manifest> <state-dir> <recapture-dir>
#
# SCOPE OF WHAT THIS WRITES: a vector carries row VALUES, unlike the probe,
# whose AdapterProbe boundary 2 forbids them. That is only acceptable because
# the rows are conformance/seeds/<name>.sh's own authored fixture content on a
# disposable pair that pair.sh DROP/CREATEd at the start of this run. Do not
# point this at a site whose content is not yours to commit.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
SOURCE_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd -P)"

OUT="${1:-}"
MANIFEST="${2:-}"
STATE_DIR="${3:-}"
RECAPTURE_DIR="${4:-}"
[ -n "$OUT" ] && [ -n "$MANIFEST" ] && [ -n "$STATE_DIR" ] && [ -n "$RECAPTURE_DIR" ] \
  || { echo "usage: record-vector.sh <out.json> <manifest> <state-dir> <recapture-dir>" >&2; exit 1; }
[[ "$MANIFEST" =~ ^[a-z0-9]([a-z0-9._-]*[a-z0-9])?$ ]] \
  || { echo "FAIL: manifest '$MANIFEST' is not a canonical adapter package name" >&2; exit 1; }
[ -d "$STATE_DIR" ] || { echo "FAIL: recorded state dir '$STATE_DIR' does not exist" >&2; exit 1; }
[ -d "$RECAPTURE_DIR" ] || { echo "FAIL: recorded recapture dir '$RECAPTURE_DIR' does not exist" >&2; exit 1; }
# The pair's side-1 repo is bind-mounted at /siterepo inside the containers;
# without it there is no way to hand an eval-file script to the target, which
# is the same premise every seed hook depends on.
[ -n "${CONF_REPO1:-}" ] || { echo "FAIL: record-vector.sh runs inside a live sweep; CONF_REPO1 is unset" >&2; exit 1; }
command -v wp_conf1 >/dev/null 2>&1 || declare -F wp_conf1 >/dev/null \
  || { echo "FAIL: wp_conf1 is not available; run this through conformance/run.sh" >&2; exit 1; }

MANIFEST_JSON="$SOURCE_ROOT/adapter-packages/$MANIFEST/package/manifest.json"
[ -f "$MANIFEST_JSON" ] \
  || { echo "FAIL: no source adapter package manifest at $MANIFEST_JSON" >&2; exit 1; }

# The probe and the row dump cover exactly the tables the manifest declares —
# not the schema, not the site. An adapter that declares no tables has nothing
# a typed-row vector could replay, so say so instead of writing an empty one.
TABLES=$(jq -r '(.tables // {}) | keys | join(",")' "$MANIFEST_JSON")
if [ -z "$TABLES" ]; then
  echo "FAIL: manifest '$MANIFEST' declares no tables; a conformance vector replays typed rows and has nothing to record here" >&2
  exit 1
fi

WORK=$(mktemp -d)
trap 'rm -rf "$WORK"; rm -f "${CONF_REPO1}/.tmp-record-vector.php" "${CONF_REPO1}/.tmp-record-vector-tables.json"' EXIT

echo "record-vector: probing $TABLES on conf1"
wp_conf1 duo adapter-probe --tables="$TABLES" --format=json | awk 'NF { line=$0 } END { print line }' > "$WORK/probe.json"
jq -e '.format == "duo-adapter-probe/v1" and .authority == false' "$WORK/probe.json" >/dev/null \
  || { echo "FAIL: adapter-probe did not answer with a duo-adapter-probe/v1 document" >&2; exit 1; }

# The row dump runs on the TARGET because that is the only place the rows are.
# It reads through $wpdb with no plugin API involved: a vector records what the
# database held when capture read it, which is the state the offline replay
# seeds FakeWpdb with. duo_map is restricted to the id_kinds this manifest
# declares so one adapter's vector never carries another's identity.
jq -c '{tables: ((.tables // {}) | keys), id_kinds: [(.tables // {})[] | .id_kind // empty]}' \
  "$MANIFEST_JSON" > "${CONF_REPO1}/.tmp-record-vector-tables.json"

cat > "${CONF_REPO1}/.tmp-record-vector.php" <<'PHPEOF'
<?php
global $wpdb;
$spec = json_decode((string) file_get_contents('/siterepo/.tmp-record-vector-tables.json'), true);
$rows = [];
foreach ($spec['tables'] as $table) {
    $prefixed = $wpdb->prefix . $table;
    $wpdb->last_error = '';
    $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $prefixed));
    if ($found !== $prefixed) {
        // present:false in the probe is a real answer; an absent table records
        // as an absent key, never as an empty one that could look captured.
        continue;
    }
    $result = $wpdb->get_results("SELECT * FROM `$prefixed`", ARRAY_A);
    if ((string) $wpdb->last_error !== '') {
        throw new RuntimeException("duo: conformance vector could not read $prefixed: {$wpdb->last_error}");
    }
    $rows[$table] = $result ?: [];
}
$kinds = array_values(array_unique(array_map('strval', $spec['id_kinds'])));
$map = [];
if ($kinds !== []) {
    $in = implode(',', array_fill(0, count($kinds), '%s'));
    $wpdb->last_error = '';
    $map = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT uuid, entity_type, id_kind, local_id FROM {$wpdb->prefix}duo_map "
            . "WHERE id_kind IN ($in) ORDER BY id_kind ASC, local_id ASC",
            $kinds
        ),
        ARRAY_A
    );
    if ((string) $wpdb->last_error !== '') {
        throw new RuntimeException("duo: conformance vector could not read duo_map: {$wpdb->last_error}");
    }
}
echo wp_json_encode([
    'agent_version' => defined('DUO_AGENT_VERSION') ? DUO_AGENT_VERSION : 'unknown',
    'ledger' => ['duo_map' => $map ?: []],
    'rows' => $rows,
    'spec_version' => defined('DUO_SPEC_VERSION') ? DUO_SPEC_VERSION : 0,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
PHPEOF

echo "record-vector: dumping declared rows and duo_map from conf1"
wp_conf1 eval-file /siterepo/.tmp-record-vector.php | awk 'NF { line=$0 } END { print line }' > "$WORK/rows.json"
jq -e 'has("rows") and has("ledger")' "$WORK/rows.json" >/dev/null \
  || { echo "FAIL: the row dump did not answer with rows and ledger" >&2; exit 1; }

php "$SCRIPT_DIR/record-vector.php" \
  "$OUT" "$MANIFEST" "$STATE_DIR" "$RECAPTURE_DIR" "$WORK/probe.json" "$WORK/rows.json" "$SOURCE_ROOT"
