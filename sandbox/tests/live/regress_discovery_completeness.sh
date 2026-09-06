#!/usr/bin/env bash
# issue #3205: discovery completeness is based on enumerated live state, not on
# whether the provenance journal happened to observe a write.
set -euo pipefail
cd "$(dirname "$0")/../.."

export WPRISM_PAIR=codexmac3205 WPRISM_PORT1=8900 WPRISM_PORT2=8901
COMPOSE="docker compose -p wprism-codexmac3205 -f pair.yml"
REPO=/siterepo/.tmp-discovery-completeness
HOST_REPO="siterepo/${WPRISM_PAIR}1/.tmp-discovery-completeness"
wp1() { $COMPOSE run --rm -T cli1 wp "$@"; }
pass() { printf 'ok: %s\n' "$*"; }
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }
. lib/pair_db.sh
pair_db_select_engine

command -v jq >/dev/null || fail "jq required"
bash bin/pair.sh reset "$WPRISM_PAIR"
bash bin/pair.sh up "$WPRISM_PAIR" "$WPRISM_PORT1" "$WPRISM_PORT2" --headless

rm -rf "$HOST_REPO"
mkdir -p "$HOST_REPO/adapters"

cat > "$HOST_REPO/adapters/discovery-fixture.json" <<'JSON'
{
  "name": "discovery-fixture",
  "spec_version": 2,
  "option_autoload": "preserve",
  "option_namespaces": [{"match": "^wprism_discovery_"}],
  "option_patterns": [
    {"match": "^wprism_discovery_dynamic_[0-9]+$", "class": "authored"},
    {"match": "^wprism_discovery_runtime_", "class": "runtime"}
  ],
  "term_meta": {
    "wprism_discovery_authored_term": {"class": "authored"}
  },
  "user_meta": {
    "wprism_discovery_authored_user": {"class": "authored"}
  },
  "tables": {
    "wprism_discovery_rows": {
      "class": "authored_snapshot",
      "pk": "id",
      "id_kind": "wprism_disc_row",
      "slug_column": "name",
      "identity": {"mode": "natural_key", "column": "name"},
      "columns": {"name": {"class": "authored"}},
      "refs": []
    },
    "wprism_discovery_meta": {
      "class": "authored_snapshot_meta",
      "attached_to": {"table": "wprism_discovery_rows", "column": "parent_id"},
      "key_column": "meta_key",
      "value_column": "meta_value",
      "default_class": "authored",
      "keyspace": {
        "version_range": {"min": "1.0.0", "max": "2.0.0"},
        "keys": ["known_setting"],
        "patterns": []
      }
    }
  }
}
JSON

cat > "$HOST_REPO/site.wprism.json" <<'JSON'
{
  "manifests": ["core", "discovery-fixture"],
  "policy": {
    "options": {
      "page_for_posts": {"class": "env", "required": false},
      "page_on_front": {"class": "env", "required": false},
      "sticky_posts": {"class": "env", "required": false},
      "wp_page_for_privacy_policy": {"class": "env", "required": false}
    },
    "post_meta": {},
    "term_meta": {},
    "post_types": [],
    "taxonomies": ["category"],
    "scope": {
      "post_type": {
        "post": {"class": "runtime"},
        "page": {"class": "runtime"},
        "attachment": {"class": "runtime"}
      },
      "taxonomy": {"post_tag": {"class": "runtime"}}
    }
  },
  "spec_version": 2
}
JSON

cleanup() {
  rm -rf "$HOST_REPO"
}
trap cleanup EXIT

wp1 option update wprism_journal_disabled 1 >/dev/null
wp1 option update wprism_discovery_dynamic_17 'captured-before-observation' >/dev/null
wp1 option update wprism_discovery_unknown 'pre-agent-value' >/dev/null
TERM_ID=$(wp1 term create category 'Discovery Fixture' --slug=wprism-discovery-fixture --porcelain)
wp1 term meta update "$TERM_ID" wprism_discovery_unknown_term 'unknown-term-value' >/dev/null
wp1 term meta update "$TERM_ID" wprism_discovery_authored_term 'authored-but-unrepresentable' >/dev/null
wp1 db query "
  CREATE TABLE wp_wprism_discovery_rows (
    id bigint unsigned NOT NULL AUTO_INCREMENT,
    name varchar(191) NOT NULL,
    PRIMARY KEY (id), UNIQUE KEY name (name)
  ) ENGINE=InnoDB;
  CREATE TABLE wp_wprism_discovery_meta (
    id bigint unsigned NOT NULL AUTO_INCREMENT,
    parent_id bigint unsigned NOT NULL,
    meta_key varchar(191) NOT NULL,
    meta_value longtext NOT NULL,
    PRIMARY KEY (id)
  ) ENGINE=InnoDB;
  INSERT INTO wp_wprism_discovery_rows (name) VALUES ('fixture-row');
  INSERT INTO wp_wprism_discovery_meta (parent_id, meta_key, meta_value)
    VALUES (1, 'known_setting', 'known'), (1, 'upgrade_added_setting', 'new-shape');
" >/dev/null
wp1 db query 'DROP TABLE IF EXISTS wp_wprism_journal' >/dev/null
pass "seeded option, term-meta, and EAV state with journaling disabled"

PENDING=$(wp1 wprism pending --repo="$REPO" --format=json 2>/dev/null | tail -1)
printf '%s\n' "$PENDING" | jq -e '
  any(.[]; .section == "options" and .key == "wprism_discovery_unknown"
    and .evidence.entities == 1
    and .evidence.owner_candidates == ["discovery-fixture"]
    and .evidence.value_shapes == ["string"]
    and (.evidence.reason | contains("namespace matched"))
    and (.evidence.journal == null))
  and any(.[]; .section == "term_meta" and .key == "wprism_discovery_unknown_term"
    and .evidence.taxonomies == ["category"]
    and (.evidence.reason | contains("unclassified")))
  and any(.[]; .section == "term_meta" and .key == "wprism_discovery_authored_term"
    and (.evidence.reason | contains("unsupported")))
  and any(.[]; .section == "table_meta" and .key == "wprism_discovery_meta:upgrade_added_setting"
    and .evidence.entities == 1
    and .evidence.owner_candidates == ["discovery-fixture"]
    and .evidence.value_shapes == ["string"]
    and (.evidence.reason | contains("[1.0.0, 2.0.0)")))
' >/dev/null || fail "pending lacks complete actionable discovery evidence: $PENDING"
pass "pending names surface, owner candidate, shapes, counts, and refusal reason without journal evidence"

set +e
BLOCKED=$(wp1 wprism capture --repo="$REPO" 2>&1)
RC=$?
set -e
[ "$RC" -ne 0 ] || fail "capture unexpectedly accepted incomplete discovery"
printf '%s\n' "$BLOCKED" | grep -q 'table_meta:wprism_discovery_meta:upgrade_added_setting' \
  || fail "capture did not name the plugin-upgrade EAV key: $BLOCKED"
pass "capture fails closed on an EAV key outside the version-pinned keyspace"

jq '.tables.wprism_discovery_meta.keyspace.keys += ["upgrade_added_setting"]' \
  "$HOST_REPO/adapters/discovery-fixture.json" > "$HOST_REPO/adapters/discovery-fixture.json.tmp"
mv "$HOST_REPO/adapters/discovery-fixture.json.tmp" "$HOST_REPO/adapters/discovery-fixture.json"

wp1 user meta update admin wprism_discovery_authored_user 'authored-and-represented' >/dev/null
PENDING_USER=$(wp1 wprism pending --repo="$REPO" --format=json 2>/dev/null | tail -1)
printf '%s\n' "$PENDING_USER" | jq -e '
  all(.[]; .section != "user_meta" or .key != "wprism_discovery_authored_user")
' >/dev/null || fail "representable authored user meta incorrectly remained pending: $PENDING_USER"
pass "representable authored user meta is absent from the discovery queue"

set +e
BLOCKED=$(wp1 wprism capture --repo="$REPO" 2>&1)
RC=$?
set -e
[ "$RC" -ne 0 ] || fail "capture unexpectedly accepted incomplete option/term discovery"
printf '%s\n' "$BLOCKED" | grep -q 'options:wprism_discovery_unknown' \
  || fail "capture did not name the pre-existing unknown option: $BLOCKED"
printf '%s\n' "$BLOCKED" | grep -q 'term_meta:wprism_discovery_authored_term' \
  || fail "capture did not explicitly block authored term meta: $BLOCKED"
if printf '%s\n' "$BLOCKED" | grep -q 'user_meta:wprism_discovery_authored_user'; then
  fail "representable authored user meta incorrectly joined the aggregate capture gate: $BLOCKED"
fi
pass "capture fails closed on pre-agent options and unrepresentable term meta only"

wp1 wprism classify --repo="$REPO" \
  --set='options:wprism_discovery_unknown=runtime;term_meta:wprism_discovery_unknown_term=runtime;term_meta:wprism_discovery_authored_term=runtime' >/dev/null

wp1 wprism capture --repo="$REPO" >/dev/null
jq -e '.records.wprism_discovery_dynamic_17.value == "captured-before-observation"
  and (.records | has("wprism_discovery_unknown") == false)' "$HOST_REPO/state/options/core.json" >/dev/null \
  || fail "dynamic option family was not enumerated/captured correctly"
pass "authored dynamic option families are captured by declarative enumeration, not exact names"

USER_META_FILE=$(find "$HOST_REPO/state/user-meta" -type f -name '*.json' -print -quit)
jq -e '.login == "admin" and .meta.wprism_discovery_authored_user == "authored-and-represented"' \
  "$USER_META_FILE" >/dev/null || fail "authored user meta did not use the login-keyed sidecar"
pass "authored user meta is represented by an exact-login sidecar"

PENDING_AFTER=$(wp1 wprism pending --repo="$REPO" --format=json 2>/dev/null | tail -1)
printf '%s\n' "$PENDING_AFTER" | jq -e '[.[] | select(
  (.key | contains("wprism_discovery")) or (.key | contains("upgrade_added_setting"))
)] | length == 0' >/dev/null || fail "resolved discovery items remained pending: $PENDING_AFTER"
pass "explicit classifications and keyspace expansion close the queue"
