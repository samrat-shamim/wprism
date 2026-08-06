#!/usr/bin/env bash
# Regression — DUO-3203: every repository-carried write must be authorized by
# the currently active policy before plan/apply/deploy performs any mutation.
set -euo pipefail
cd "$(dirname "$0")/.."   # -> sandbox/

export DUO_PAIR=conf DUO_PORT1=8806 DUO_PORT2=8807
COMPOSE="docker compose -p duo-conf -f pair.yml"
wp1() { $COMPOSE run --rm -T cli1 wp "$@"; }
say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }

command -v jq >/dev/null || fail "jq required"
pgrep -f 'conformance/run.sh' >/dev/null && fail "conformance sweep owns the conf pair; wait for it to finish"

say "fresh conf1 target (reuse the existing conf pair; no fourth pair)"
bash bin/pair.sh reset conf
bash bin/pair.sh up conf "$DUO_PORT1" "$DUO_PORT2" --headless

HOST_REPO=siterepo/conf1/.tmp-duo-3203
REPO=/siterepo/.tmp-duo-3203
rm -rf "$HOST_REPO"
mkdir -p "$HOST_REPO/state/options" "$HOST_REPO/state/posts/product" "$HOST_REPO/state/tables/nf3_forms"

jq -n '{
  manifests: ["core", "woocommerce", "ninja-forms"],
  policy: {
    options: {blogname: {class: "runtime"}},
    post_meta: {}, term_meta: {},
    post_types: ["post", "page", "attachment", "product"],
    taxonomies: ["category", "post_tag", "product_cat", "product_type"]
  },
  spec_version: 0
}' > "$HOST_REPO/site.duo.json"

# active_plugins is intentionally valid managed state. The other four keys
# exercise env/runtime plus an authored->runtime site-policy downgrade.
jq -n '{
  active_plugins: [],
  blogname: "stale authored value",
  cron: {},
  siteurl: "https://wrong-environment.example",
  stylesheet: "twentytwentyfive",
  template: "twentytwentyfive"
}' > "$HOST_REPO/state/options/core.json"

POST_UUID=11111111-1111-4111-8111-111111111111
POST_FRONT=$(jq -n --arg uuid "$POST_UUID" '{
  author: "user:admin", comment_status: "open",
  date: "2026-08-06 00:00:00", date_gmt: "2026-08-06 00:00:00",
  excerpt: "", menu_order: 0,
  meta: {_stock: "99", injected_unknown: "must block"},
  modified_gmt: "2026-08-06 00:00:00", parent: null,
  ping_status: "closed", slug: "unauthorized-product", status: "publish",
  terms: {}, title: "Unauthorized Product", type: "product", uuid: $uuid
}')
printf -- '---\n%s\n---\n\nbody\n' "$POST_FRONT" > "$HOST_REPO/state/posts/product/${POST_UUID}--unauthorized-product.md"

TABLE_UUID=22222222-2222-4222-8222-222222222222
jq -n --arg uuid "$TABLE_UUID" '{
  columns: {title: "Injected form", seq_num: 7},
  meta: {_seq_num: "7"},
  table: "nf3_forms",
  uuid: $uuid
}' > "$HOST_REPO/state/tables/nf3_forms/${TABLE_UUID}--injected-form.json"

run_json_failure() { # run_json_failure <plan|apply|deploy>
  local verb="$1" rc=0 out json
  out=$(wp1 duo "$verb" --repo="$REPO" --format=json 2>&1) || rc=$?
  printf '%s\n' "$out" >&2
  [ "$rc" -eq 1 ] || fail "duo $verb must exit 1 on unauthorized repository state (got $rc)"
  json=$(printf '%s\n' "$out" | tail -1)
  printf '%s\n' "$json" | jq -e '.ok == false and .error == "repository_authorization_failed" and (.diagnostics | type == "array")' >/dev/null \
    || fail "duo $verb did not emit the structured authorization failure payload"
  printf '%s\n' "$json"
}

say "fresh-target plan refuses before Ledger::ensure and returns stable structured findings"
wp1 db query 'DROP TABLE IF EXISTS wp_duo_journal, wp_duo_kv, wp_duo_state, wp_duo_map' >/dev/null
FRESH=$(run_json_failure plan)

for expected in \
  'option|blogname|runtime|site.duo.json' \
  'option|cron|runtime|core' \
  'option|siteurl|env|core' \
  'post_meta|_stock|runtime|woocommerce' \
  'post_meta|injected_unknown|unclassified|' \
  'table_column|seq_num|runtime|ninja-forms' \
  'attached_meta:nf3_form_meta|_seq_num|runtime|ninja-forms'
do
  IFS='|' read -r surface field class source <<<"$expected"
  printf '%s\n' "$FRESH" | jq -e --arg s "$surface" --arg f "$field" --arg c "$class" --arg d "$source" '
    any(.diagnostics[]; .surface == $s and .field == $f and .classification == $c
      and (($d == "" and .declared_by == null) or .declared_by == $d))
  ' >/dev/null || fail "missing diagnostic $expected"
done
printf '%s\n' "$FRESH" | jq -e 'all(.diagnostics[]; .path and .uuid and .field and .classification and .code)' >/dev/null \
  || fail "every finding must carry code/path/uuid/field/classification"
printf '%s\n' "$FRESH" | jq -e 'any(.diagnostics[]; .field == "active_plugins") | not' >/dev/null \
  || fail "managed active_plugins must stay authorized for Deploy's dedicated lifecycle route"

LEDGER_COUNT=$(wp1 eval 'global $wpdb; echo count($wpdb->get_col("SHOW TABLES LIKE \"{$wpdb->prefix}duo_%\""));' 2>/dev/null | tail -1)
[ "$LEDGER_COUNT" = 0 ] || fail "fresh-target refusal created ledger tables before authorization (found $LEDGER_COUNT)"
pass "fresh plan: exact policy/source diagnostics, exit 1, and zero ledger creation"

say "mapped-target diagnostics are byte-identical and existing ledger state is untouched"
wp1 eval '\Duo\Ledger::ensure(); \Duo\Ledger::kv_set("authz_sentinel", "keep");' >/dev/null
MAPPED=$(run_json_failure plan)
printf '%s\n' "$FRESH" | jq -S '.diagnostics' > /tmp/duo-3203-fresh.json
printf '%s\n' "$MAPPED" | jq -S '.diagnostics' > /tmp/duo-3203-mapped.json
diff -u /tmp/duo-3203-fresh.json /tmp/duo-3203-mapped.json \
  || fail "fresh and mapped equivalent targets produced different authorization diagnostics"
[ "$(wp1 eval 'echo \Duo\Ledger::kv_get("authz_sentinel");' 2>/dev/null | tail -1)" = keep ] \
  || fail "mapped-target refusal mutated existing ledger state"
pass "fresh/mapped preflight result is identical; mapped ledger sentinel survived"

say "apply and deploy share the gate; no DB, lifecycle, or filesystem work escapes"
BEFORE_BLOGNAME=$(wp1 option get blogname 2>/dev/null | tail -1)
BEFORE_PLUGINS=$(wp1 option get active_plugins --format=json 2>/dev/null | tail -1)
APPLY_JSON=$(run_json_failure apply)
DEPLOY_JSON=$(run_json_failure deploy)
diff -u <(printf '%s\n' "$FRESH" | jq -S '.diagnostics') <(printf '%s\n' "$APPLY_JSON" | jq -S '.diagnostics') \
  || fail "apply and plan produced different authorization diagnostics"
diff -u <(printf '%s\n' "$FRESH" | jq -S '.diagnostics') <(printf '%s\n' "$DEPLOY_JSON" | jq -S '.diagnostics') \
  || fail "deploy and plan produced different authorization diagnostics"
AFTER_BLOGNAME=$(wp1 option get blogname 2>/dev/null | tail -1)
AFTER_PLUGINS=$(wp1 option get active_plugins --format=json 2>/dev/null | tail -1)
[ "$AFTER_BLOGNAME" = "$BEFORE_BLOGNAME" ] || fail "apply refusal changed blogname"
[ "$AFTER_PLUGINS" = "$BEFORE_PLUGINS" ] || fail "deploy refusal changed plugin activation state"
[ "$(wp1 post list --post_type=product --name=unauthorized-product --format=count 2>/dev/null | tail -1)" = 0 ] \
  || fail "apply refusal created the injected product"
[ "$(wp1 eval 'echo \Duo\Ledger::kv_get("authz_sentinel");' 2>/dev/null | tail -1)" = keep ] \
  || fail "apply/deploy refusal changed the ledger sentinel"
pass "apply/deploy both refuse with structured evidence and zero observable mutation"

say "human output and exit status agree"
HUMAN_RC=0
HUMAN=$(wp1 duo apply --repo="$REPO" 2>&1) || HUMAN_RC=$?
printf '%s\n' "$HUMAN"
[ "$HUMAN_RC" -eq 1 ] || fail "human apply output must exit 1"
printf '%s\n' "$HUMAN" | grep -q 'repository authorization failed' || fail "human output did not name the authorization gate"
printf '%s\n' "$HUMAN" | grep -q 'no target mutation attempted' || fail "human output did not state the truthful mutation boundary"
pass "human output, machine output, and exit status agree"

rm -f /tmp/duo-3203-fresh.json /tmp/duo-3203-mapped.json
rm -rf "$HOST_REPO"
pass "DUO-3203 regression: options/meta/table/attached-meta authorization + policy downgrade + fresh/mapped identity + zero-mutation gate"
