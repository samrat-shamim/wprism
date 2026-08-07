#!/usr/bin/env bash
# DUO-3205: discovery completeness is based on enumerated live state, not on
# whether the provenance journal happened to observe a write.
set -euo pipefail
cd "$(dirname "$0")/.."

export DUO_PAIR=codexmac3205 DUO_PORT1=8900 DUO_PORT2=8901
COMPOSE="docker compose -p duo-codexmac3205 -f pair.yml"
REPO=/siterepo/.tmp-duo-3205
HOST_REPO="siterepo/${DUO_PAIR}1/.tmp-duo-3205"
wp1() { $COMPOSE run --rm -T cli1 env DUO_MANIFESTS_DIR="$REPO/manifests" wp "$@"; }
pass() { printf 'ok: %s\n' "$*"; }
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }

command -v jq >/dev/null || fail "jq required"
bash bin/pair.sh reset "$DUO_PAIR"
bash bin/pair.sh up "$DUO_PAIR" "$DUO_PORT1" "$DUO_PORT2" --headless

rm -rf "$HOST_REPO"
mkdir -p "$HOST_REPO/manifests"
cp ../manifests/core.json "$HOST_REPO/manifests/core.json"

cat > "$HOST_REPO/manifests/discovery-fixture.json" <<'JSON'
{
  "name": "discovery-fixture",
  "option_namespaces": [{"match": "^duo_discovery_"}],
  "option_patterns": [
    {"match": "^duo_discovery_dynamic_[0-9]+$", "class": "authored"},
    {"match": "^duo_discovery_runtime_", "class": "runtime"}
  ],
  "term_meta": {
    "duo_discovery_authored_term": {"class": "authored"}
  },
  "tables": {
    "duo_discovery_rows": {
      "class": "authored_snapshot",
      "pk": "id",
      "id_kind": "duo_disc_row",
      "slug_column": "name",
      "identity": {"mode": "natural_key", "column": "name"},
      "columns": {"name": {"class": "authored"}},
      "refs": []
    },
    "duo_discovery_meta": {
      "class": "authored_snapshot_meta",
      "attached_to": {"table": "duo_discovery_rows", "column": "parent_id"},
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

cat > "$HOST_REPO/site.duo.json" <<'JSON'
{
  "manifests": ["core", "discovery-fixture"],
  "policy": {
    "options": {
      "page_for_posts": {"class": "env"},
      "page_on_front": {"class": "env"},
      "sticky_posts": {"class": "env"},
      "wp_page_for_privacy_policy": {"class": "env"}
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
  "spec_version": 1
}
JSON

cleanup() {
  rm -rf "$HOST_REPO"
}
trap cleanup EXIT

wp1 option update duo_journal_disabled 1 >/dev/null
wp1 option update duo_discovery_dynamic_17 'captured-before-observation' >/dev/null
wp1 option update duo_discovery_unknown 'pre-agent-value' >/dev/null
TERM_ID=$(wp1 term create category 'Discovery Fixture' --slug=duo-discovery-fixture --porcelain)
wp1 term meta update "$TERM_ID" duo_discovery_unknown_term 'unknown-term-value' >/dev/null
wp1 term meta update "$TERM_ID" duo_discovery_authored_term 'authored-but-unrepresentable' >/dev/null
wp1 db query "
  CREATE TABLE wp_duo_discovery_rows (
    id bigint unsigned NOT NULL AUTO_INCREMENT,
    name varchar(191) NOT NULL,
    PRIMARY KEY (id), UNIQUE KEY name (name)
  ) ENGINE=InnoDB;
  CREATE TABLE wp_duo_discovery_meta (
    id bigint unsigned NOT NULL AUTO_INCREMENT,
    parent_id bigint unsigned NOT NULL,
    meta_key varchar(191) NOT NULL,
    meta_value longtext NOT NULL,
    PRIMARY KEY (id)
  ) ENGINE=InnoDB;
  INSERT INTO wp_duo_discovery_rows (name) VALUES ('fixture-row');
  INSERT INTO wp_duo_discovery_meta (parent_id, meta_key, meta_value)
    VALUES (1, 'known_setting', 'known'), (1, 'upgrade_added_setting', 'new-shape');
" >/dev/null
wp1 db query 'DROP TABLE IF EXISTS wp_duo_journal' >/dev/null
pass "seeded option, term-meta, and EAV state with journaling disabled"

PENDING=$(wp1 duo pending --repo="$REPO" --format=json 2>/dev/null | tail -1)
printf '%s\n' "$PENDING" | jq -e '
  any(.[]; .section == "options" and .key == "duo_discovery_unknown"
    and .evidence.entities == 1
    and .evidence.owner_candidates == ["discovery-fixture"]
    and .evidence.value_shapes == ["string"]
    and (.evidence.reason | contains("namespace matched"))
    and (.evidence.journal == null))
  and any(.[]; .section == "term_meta" and .key == "duo_discovery_unknown_term"
    and .evidence.taxonomies == ["category"]
    and (.evidence.reason | contains("unclassified")))
  and any(.[]; .section == "term_meta" and .key == "duo_discovery_authored_term"
    and (.evidence.reason | contains("unsupported")))
  and any(.[]; .section == "table_meta" and .key == "duo_discovery_meta:upgrade_added_setting"
    and .evidence.entities == 1
    and .evidence.owner_candidates == ["discovery-fixture"]
    and .evidence.value_shapes == ["string"]
    and (.evidence.reason | contains("[1.0.0, 2.0.0)")))
' >/dev/null || fail "pending lacks complete actionable discovery evidence: $PENDING"
pass "pending names surface, owner candidate, shapes, counts, and refusal reason without journal evidence"

set +e
BLOCKED=$(wp1 duo capture --repo="$REPO" 2>&1)
RC=$?
set -e
[ "$RC" -ne 0 ] || fail "capture unexpectedly accepted incomplete discovery"
printf '%s\n' "$BLOCKED" | grep -q 'table_meta:duo_discovery_meta:upgrade_added_setting' \
  || fail "capture did not name the plugin-upgrade EAV key: $BLOCKED"
pass "capture fails closed on an EAV key outside the version-pinned keyspace"

jq '.tables.duo_discovery_meta.keyspace.keys += ["upgrade_added_setting"]' \
  "$HOST_REPO/manifests/discovery-fixture.json" > "$HOST_REPO/manifests/discovery-fixture.json.tmp"
mv "$HOST_REPO/manifests/discovery-fixture.json.tmp" "$HOST_REPO/manifests/discovery-fixture.json"

set +e
BLOCKED=$(wp1 duo capture --repo="$REPO" 2>&1)
RC=$?
set -e
[ "$RC" -ne 0 ] || fail "capture unexpectedly accepted incomplete option/term discovery"
printf '%s\n' "$BLOCKED" | grep -q 'options:duo_discovery_unknown' \
  || fail "capture did not name the pre-existing unknown option: $BLOCKED"
printf '%s\n' "$BLOCKED" | grep -q 'term_meta:duo_discovery_authored_term' \
  || fail "capture did not explicitly block authored term meta: $BLOCKED"
pass "capture fails closed on pre-agent options and unrepresentable term meta"

wp1 duo classify --repo="$REPO" \
  --set='options:duo_discovery_unknown=runtime;term_meta:duo_discovery_unknown_term=runtime;term_meta:duo_discovery_authored_term=runtime' >/dev/null

wp1 duo capture --repo="$REPO" >/dev/null
jq -e '.duo_discovery_dynamic_17 == "captured-before-observation"
  and has("duo_discovery_unknown") == false' "$HOST_REPO/state/options/core.json" >/dev/null \
  || fail "dynamic option family was not enumerated/captured correctly"
pass "authored dynamic option families are captured by declarative enumeration, not exact names"

PENDING_AFTER=$(wp1 duo pending --repo="$REPO" --format=json 2>/dev/null | tail -1)
printf '%s\n' "$PENDING_AFTER" | jq -e '[.[] | select(
  (.key | contains("duo_discovery")) or (.key | contains("upgrade_added_setting"))
)] | length == 0' >/dev/null || fail "resolved discovery items remained pending: $PENDING_AFTER"
pass "explicit classifications and keyspace expansion close the queue"
