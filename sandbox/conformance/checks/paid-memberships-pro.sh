#!/usr/bin/env bash
# Exact-artifact PMPro production acceptance: native API/frontend behavior,
# divergent mapped and natural identities, target sovereignty, fail-closed
# boundaries, deletion, conflict, failure/retry, concurrency, and lifecycle.
set -euo pipefail
CONF1_PORT="${CONF1_PORT:-8806}"
CONF2_PORT="${CONF2_PORT:-8807}"
CONF_REPO1="${CONF_REPO1:-siterepo/conf1}"
CONF_REPO2="${CONF_REPO2:-siterepo/conf2}"
PMPRO_EXPECTED_VERSION="${PMPRO_EXPECTED_VERSION:-3.8.3}"

read -r -d '' OBSERVE_PHP <<'PHPEOF' || true
<?php
global $wpdb;
$builderRows = $wpdb->get_results($wpdb->prepare(
    "SELECT * FROM {$wpdb->pmpro_membership_levels} WHERE name=%s ORDER BY id",
    'Builder 東京 🚀'
));
$builder = null;
$hostileBuilder = null;
foreach ($builderRows as $row) {
    if ((float) $row->initial_payment === 19.95) {
        $builder = $row;
    } elseif ((float) $row->initial_payment === 888.0) {
        $hostileBuilder = $row;
    }
}
$agency = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->pmpro_membership_levels} WHERE name=%s", 'Agency Plan'));
$deleteProbe = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->pmpro_membership_levels} WHERE name=%s", 'Unsafe Delete Probe'));
$page = get_page_by_path('pmpro-restricted', OBJECT, 'page');
$categoryPost = get_page_by_path('pmpro-category-restricted', OBJECT, 'post');
$category = get_term_by('slug', 'pmpro-members-category', 'category');
if (!$builder || !$agency || !$deleteProbe || !$page || !$categoryPost || !$category) {
    throw new RuntimeException('PMPro canonical authored fixture is incomplete');
}
$builderApi = new PMPro_Membership_Level((int) $builder->id);
$discountApi = new PMPro_Discount_Code('DUO-PORTABLE-25');
if (!$discountApi || empty($discountApi->id)) {
    throw new RuntimeException('PMPro discount API could not resolve the portable code');
}
$groups = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->pmpro_groups} WHERE name=%s ORDER BY id", 'Portable Offers 東京'));
$portableGroup = null;
$hostileGroup = null;
foreach ($groups as $group) {
    if ((int) $group->displayorder === 7) {
        $portableGroup = $group;
    } elseif ((int) $group->displayorder === 91) {
        $hostileGroup = $group;
    }
}
if (!$portableGroup) {
    throw new RuntimeException('PMPro group API fixture is incomplete');
}
[$pageAccess, $pageLevels] = pmpro_has_membership_access((int) $page->ID, 0, true);
[$categoryAccess, $categoryLevels] = pmpro_has_membership_access((int) $categoryPost->ID, 0, true);
$levelIds = static fn(array $values): array => array_values(array_map('intval', $values));
$discountLevels = [];
foreach ((array) $discountApi->levels as $levelId => $pricing) {
    $discountLevels[(string) $levelId] = $pricing;
}
ksort($discountLevels, SORT_NUMERIC);
$systemPages = [];
foreach (['account','billing','cancel','checkout','confirmation','invoice','levels','login','member_profile_edit'] as $name) {
    $id = (int) get_option("pmpro_{$name}_page_id");
    $systemPages[$name] = ['id' => $id, 'exists' => $id > 0 && get_post($id) instanceof WP_Post];
}
echo wp_json_encode([
    'version' => defined('PMPRO_VERSION') ? PMPRO_VERSION : null,
    'ids' => [
        'agency' => (int) $agency->id,
        'builder' => (int) $builder->id,
        'category' => (int) $category->term_id,
        'category_post' => (int) $categoryPost->ID,
        'delete_probe' => (int) $deleteProbe->id,
        'discount' => (int) $discountApi->id,
        'group' => (int) $portableGroup->id,
        'restricted_page' => (int) $page->ID,
    ],
    'hostile' => [
        'group_id' => $hostileGroup ? (int) $hostileGroup->id : 0,
        'level_id' => $hostileBuilder ? (int) $hostileBuilder->id : 0,
        'level_description' => $hostileBuilder ? (string) $hostileBuilder->description : '',
    ],
    'level' => [
        'categories' => array_values(array_map('intval', (array) $builderApi->categories)),
        'confirmation' => (string) $builderApi->confirmation,
        'description_bytes' => strlen((string) $builderApi->description),
        'group_id' => (int) pmpro_get_group_id_for_level((int) $builder->id),
        'initial_payment' => (string) $builderApi->initial_payment,
        'meta' => [
            'confirmation_in_email' => get_pmpro_membership_level_meta((int) $builder->id, 'confirmation_in_email', true),
            'enable_avatars' => get_pmpro_membership_level_meta((int) $builder->id, 'enable_avatars', true),
            'membership_account_message' => get_pmpro_membership_level_meta((int) $builder->id, 'membership_account_message', true),
            'stripe_product_id' => get_pmpro_membership_level_meta((int) $builder->id, 'stripe_product_id', true),
        ],
        'name' => (string) $builderApi->name,
    ],
    'group' => [
        'allow_multiple' => (int) pmpro_get_level_group((int) $portableGroup->id)->allow_multiple_selections,
        'level_ids' => $levelIds(pmpro_get_level_ids_for_group((int) $portableGroup->id)),
        'name' => (string) pmpro_get_level_group((int) $portableGroup->id)->name,
    ],
    'discount' => [
        'code' => (string) $discountApi->code,
        'expires' => (string) $discountApi->expires,
        'levels' => $discountLevels,
        'one_use_per_user' => (int) $wpdb->get_var($wpdb->prepare("SELECT one_use_per_user FROM {$wpdb->pmpro_discount_codes} WHERE id=%d", $discountApi->id)),
        'starts' => (string) $discountApi->starts,
        'uses' => (int) $discountApi->uses,
    ],
    'access' => [
        'category' => $categoryAccess ? true : false,
        'category_levels' => $levelIds((array) $categoryLevels),
        'page' => $pageAccess ? true : false,
        'page_levels' => $levelIds((array) $pageLevels),
    ],
    'edges' => [
        'category' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->pmpro_memberships_categories} WHERE membership_id=%d AND category_id=%d", $builder->id, $category->term_id)),
        'discount_levels' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->pmpro_discount_codes_levels} WHERE code_id=%d", $discountApi->id)),
        'group_levels' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->pmpro_membership_levels_groups} WHERE `group`=%d", $portableGroup->id)),
        'pages' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->pmpro_memberships_pages} WHERE page_id=%d", $page->ID)),
    ],
    'options' => [
        'address' => get_option('pmpro_business_address'),
        'colors' => get_option('pmpro_colors'),
        'currency' => get_option('pmpro_currency'),
        'email_body_bytes' => strlen((string) get_option('pmpro_email_checkout_paid_body')),
        'email_subject' => get_option('pmpro_email_checkout_paid_subject'),
        'hideadslevels' => get_option('pmpro_hideadslevels'),
        'level_order' => get_option('pmpro_level_order'),
    ],
    'environment' => [
        'email_to' => get_option('pmpro_email_checkout_paid_to'),
        'gateway' => get_option('pmpro_gateway'),
        'gateway_environment' => get_option('pmpro_gateway_environment'),
        'license' => get_option('pmpro_license_key'),
        'secret' => get_option('pmpro_stripe_secretkey'),
        'ssl' => get_option('pmpro_use_ssl'),
        'turnstile' => get_option('pmpro_cloudflare_turnstile_secret_key'),
    ],
    'runtime' => [
        'neighbor' => get_option('duo_target_pmpro_neighbor'),
        'source_members' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->users} WHERE user_login='pmpro_source_member'"),
        'source_orders' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->pmpro_membership_orders} WHERE code='SOURCE-RUNTIME-ORDER'"),
        'target_members' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->users} u JOIN {$wpdb->pmpro_memberships_users} mu ON mu.user_id=u.ID WHERE u.user_login='pmpro_target_member' AND mu.status='active'"),
        'target_orders' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->pmpro_membership_orders} WHERE code='TARGET-RUNTIME-ORDER'"),
        'updates' => get_option('pmpro_updates'),
    ],
    'system_pages' => $systemPages,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
PHPEOF

observe_pmpro() { # <conf1|conf2>
  local side="$1" service repo out
  case "$side" in
    conf1) service=cli1; repo="$CONF_REPO1" ;;
    conf2) service=cli2; repo="$CONF_REPO2" ;;
    *) fail "invalid PMPro observation side: $side" ;;
  esac
  printf '%s' "$OBSERVE_PHP" > "$repo/.tmp-pmpro-observe.php"
  out=$($COMPOSE run --rm -T "$service" wp eval-file /siterepo/.tmp-pmpro-observe.php)
  rm -f "$repo/.tmp-pmpro-observe.php"
  require_observed_nonempty "$side PMPro native observation" "$out"
  printf '%s\n' "$out" | awk 'NF { line=$0 } END { print line }'
}

pmpro_target_hash() {
  wp_conf2 eval '
    global $wpdb;
    $tables=[];
    foreach (["pmpro_membership_levels","pmpro_membership_levelmeta","pmpro_discount_codes","pmpro_discount_codes_levels","pmpro_groups","pmpro_membership_levels_groups","pmpro_memberships_categories","pmpro_memberships_pages"] as $suffix) {
      $table=$wpdb->prefix.$suffix;
      $tables[$suffix]=$wpdb->get_results("SELECT * FROM `$table` ORDER BY 1,2",ARRAY_A);
    }
    $options=[];
    foreach (["pmpro_currency","pmpro_business_address","pmpro_colors","pmpro_level_order","pmpro_hideadslevels","pmpro_email_checkout_paid_subject","pmpro_email_checkout_paid_body"] as $name) $options[$name]=get_option($name,null);
    echo hash("sha256",serialize([$tables,$options]));
  ' | tail -1
}

commit_pmpro_source() { # <message>
  wp_conf1 duo capture --repo=/siterepo >/dev/null
  git -C "$CONF_REPO1" add -A
  git -C "$CONF_REPO1" -c user.name=duo -c user.email=duo@example.test commit -qm "$1"
  git -C "$CONF_REPO1" push -q origin main
  git -C "$CONF_REPO2" pull -q origin main
}

# The target runtime user was created before canonical levels existed. Enroll
# it now through PMPro's real API, after the runner's first verified apply, and
# create a payment row that later authored operations must never copy/delete.
wp_conf2 eval '
  global $wpdb;
  $user=get_user_by("login","pmpro_target_member");
  $level=(int)$wpdb->get_var("SELECT id FROM {$wpdb->pmpro_membership_levels} WHERE name=\"Builder 東京 🚀\" AND initial_payment=19.95");
  if (!$user || !$level) throw new RuntimeException("target runtime enrollment fixture is incomplete");
  if (!pmpro_hasMembershipLevel($level,$user->ID) && !pmpro_changeMembershipLevel($level,$user->ID)) throw new RuntimeException("target runtime enrollment failed");
  if (!(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->pmpro_membership_orders} WHERE code=\"TARGET-RUNTIME-ORDER\"")) {
    $order=new MemberOrder(); $order->code="TARGET-RUNTIME-ORDER"; $order->user_id=$user->ID; $order->membership_id=$level;
    $order->status="success"; $order->gateway="check"; $order->gateway_environment="sandbox";
    $order->subtotal=7.25; $order->total=7.25; $order->timestamp="2026-02-03 04:05:06";
    if (!$order->saveOrder()) throw new RuntimeException("target runtime order failed");
  }
' >/dev/null

SOURCE=$(observe_pmpro conf1)
TARGET=$(observe_pmpro conf2)
SOURCE_IDS=$(cat "$CONF_REPO1/.tmp-pmpro-source.json")
TARGET_IDS=$(cat "$CONF_REPO2/.tmp-pmpro-target.json")

jq -e --arg version "$PMPRO_EXPECTED_VERSION" '
  .version == $version and .level.name == "Builder 東京 🚀" and .level.initial_payment == "19.95000000" and
  .level.description_bytes > 10000 and .level.meta.confirmation_in_email == "1" and .level.meta.enable_avatars == "1" and
  (.level.meta.membership_account_message | contains("東京 🚀")) and .level.meta.stripe_product_id == "" and
  .group.name == "Portable Offers 東京" and .group.allow_multiple == 1 and (.group.level_ids | length) == 1 and
  .discount.code == "DUO-PORTABLE-25" and .discount.starts == "2025-01-02" and .discount.expires == "2035-12-30" and
  .discount.uses == 125 and .discount.one_use_per_user == 1 and (.discount.levels | length) == 2 and
  .edges.category == 1 and .edges.discount_levels == 2 and .edges.group_levels == 1 and .edges.pages == 2 and
  .access.page == false and .access.category == false and (.access.page_levels | length) == 2 and (.access.category_levels | length) == 1 and
  .options.currency == "JPY" and .options.address.city == "東京" and .options.colors.accent == "#aabbcc" and
  .options.email_body_bytes > 10000 and .options.email_subject == "Portable checkout 東京 🚀" and
  .environment.gateway == "check" and .environment.gateway_environment == "sandbox" and
  .environment.secret == "sk_test_TARGET_SECRET_b84c" and .environment.turnstile == "turnstile_TARGET_SECRET" and
  .environment.license == "license_TARGET_SECRET" and .environment.email_to == "target-recipient@example.test" and .environment.ssl == "0" and
  .runtime.source_members == 0 and .runtime.source_orders == 0 and .runtime.target_members == 1 and .runtime.target_orders == 1 and
  .runtime.updates["target-runtime"] == 1999999001 and .runtime.neighbor == "target-neighbor-preserved" and
  ([.system_pages[] | .exists] | all)
' <<<"$TARGET" >/dev/null || fail "PMPro native authored/runtime state did not converge: $TARGET"

for key in agency builder category category_post delete_probe group restricted_page second_group; do
  SOURCE_ID=$(jq -r --arg key "$key" '.[$key]' <<<"$SOURCE_IDS")
  if [ "$key" = second_group ]; then
    TARGET_ID=$(wp_conf2 db query "SELECT id FROM wp_pmpro_groups WHERE name='Secondary Offers'" --skip-column-names | tr -d '[:space:]')
  else
    TARGET_ID=$(jq -r --arg key "$key" '.ids[$key]' <<<"$TARGET")
  fi
  require_fixture_ids SOURCE_ID TARGET_ID
  [ "$SOURCE_ID" != "$TARGET_ID" ] || fail "PMPro source/target $key identities did not diverge ($SOURCE_ID)"
done
[ "$(jq -r '.discount' <<<"$TARGET_IDS")" = "$(jq -r '.ids.discount' <<<"$TARGET")" ] \
  || fail "PMPro natural-key discount was duplicated instead of adopting the hostile target row"

grep -Rqs '{{pmpro_level:' "$CONF_REPO1/state" || fail 'canonical PMPro graph contains no portable level reference tokens'
grep -Rqs '{{pmpro_discount:' "$CONF_REPO1/state" || fail 'canonical PMPro graph contains no portable discount reference tokens'
grep -Rqs '{{pmpro_group:' "$CONF_REPO1/state" || fail 'canonical PMPro graph contains no portable group reference tokens'
if rg -q 'SOURCE_SECRET|source-recipient@example\.test|prod_SOURCE' "$CONF_REPO1/state"; then
  fail 'PMPro canonical state contains an environment/payment sentinel'
fi

PROVIDER_RECEIPT="${APPLY_JSON:-}"
if [ -z "$PROVIDER_RECEIPT" ] && [ -n "${VMATRIX_APPLY_LOG:-}" ] && [ -f "$VMATRIX_APPLY_LOG" ]; then
  PROVIDER_RECEIPT=$(cat "$VMATRIX_APPLY_LOG")
fi
grep -Fq 'paid-memberships-pro-cache' <<<"$PROVIDER_RECEIPT" \
  || fail "initial apply did not identify the PMPro cache provider: ${PROVIDER_RECEIPT:-<missing>}"
if jq -e 'any(.actions[]?; .source == "provider:paid-memberships-pro-cache/clear_level_meta_caches" and .verified == true and .after.database_hash == .after.api_hash and .after.meta_row_count >= 3)' <<<"$PROVIDER_RECEIPT" >/dev/null 2>&1; then
  :
elif ! grep -Eq 'canary clean|"canary"[[:space:]]*:[[:space:]]*"clean"' <<<"$PROVIDER_RECEIPT"; then
  fail "PMPro provider receipt was neither structured/verified nor a clean boundary log: $PROVIDER_RECEIPT"
fi
pass 'all PMPro table identities and references rebind at divergent ids; env/payment and runtime state remain target-owned; cache repair is verified'

if [ "${PMPRO_SKIP_FRONTEND:-0}" != 1 ]; then
  RESTRICTED=$(curl -fsSL "http://localhost:${CONF2_PORT}/pmpro-restricted/") || fail 'PMPro restricted frontend did not return 200'
  require_observed_nonempty 'PMPro restricted frontend response' "$RESTRICTED"
  grep -Fq 'PRIVATE-PMPRO-CONTENT' <<<"$RESTRICTED" && fail 'anonymous PMPro frontend leaked restricted content'
  grep -Eq 'Sign in to see|Members only|membership' <<<"$RESTRICTED" || fail 'PMPro restricted frontend rendered neither access message nor membership UI'
  LEVELS_URL=$(wp_conf2 eval 'echo get_permalink((int)get_option("pmpro_levels_page_id"));' | tail -1)
  LEVELS=$(curl -fsSL "$LEVELS_URL") || fail 'PMPro membership-levels frontend did not return 200'
  grep -Fq 'Builder 東京 🚀' <<<"$LEVELS" || fail 'PMPro frontend did not render the applied membership level through its shortcode'
  grep -qiE 'fatal error|uncaught' <<<"$LEVELS" && fail 'PMPro frontend contains a fatal marker'
  pass 'PMPro access control denies anonymous page/category access and native level UI renders large UTF-8 authored state'
fi

ZERO_PLAN=$(wp_conf2 duo plan --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered 'PMPro zero-change plan' json "$ZERO_PLAN"
jq -e '([.create,.update,.drift,.conflict,.collision,.delete,.delete_conflict] | map(length) | add) == 0' <<<"$ZERO_PLAN" >/dev/null \
  || fail "PMPro initial apply is not idempotent: $ZERO_PLAN"

if [ "${PMPRO_BOUNDARY_ONLY:-0}" = 1 ]; then
  pass "PMPro $PMPRO_EXPECTED_VERSION boundary fixture passes native APIs, frontend, identities, provider receipt, sovereignty, and idempotence"
  return 0 2>/dev/null || exit 0
fi

# Closed schema/keyspaces and secret hygiene: unknown metadata, optional object
# graphs, credential-shaped authored values, and an unexpected live column each
# refuse without publishing any canonical bytes.
CAPTURE_BASELINE=$(git -C "$CONF_REPO1" status --porcelain --untracked-files=all -- state)
wp_conf1 eval '
  $id=(int)$GLOBALS["wpdb"]->get_var("SELECT id FROM {$GLOBALS["wpdb"]->pmpro_membership_levels} WHERE name=\"Builder 東京 🚀\" AND initial_payment=19.95");
  update_pmpro_membership_level_meta($id,"future_addon_remote_secret","AKIAABCDEFGHIJKLMNOP");
' >/dev/null
UNKNOWN_RC=0
UNKNOWN_OUT=$(wp_conf1 duo capture --repo=/siterepo 2>&1) || UNKNOWN_RC=$?
require_duo_answered 'PMPro unknown levelmeta capture' human "$UNKNOWN_OUT"
[ "$UNKNOWN_RC" -ne 0 ] && grep -Eqi 'keyspace|unclassified|future_addon' <<<"$UNKNOWN_OUT" \
  && ! grep -Fq 'AKIAABCDEFGHIJKLMNOP' <<<"$UNKNOWN_OUT" \
  || fail "PMPro unknown add-on metadata did not refuse and redact: $UNKNOWN_OUT"
wp_conf1 eval '
  global $wpdb; $id=(int)$wpdb->get_var("SELECT id FROM {$wpdb->pmpro_membership_levels} WHERE name=\"Builder 東京 🚀\" AND initial_payment=19.95");
  delete_pmpro_membership_level_meta($id,"future_addon_remote_secret");
  update_option("pmpro_user_fields_settings",[(object)["name"=>"checkout_secret","nested"=>(object)["enabled"=>true]]]);
' >/dev/null
OBJECT_RC=0
OBJECT_OUT=$(wp_conf1 duo capture --repo=/siterepo 2>&1) || OBJECT_RC=$?
require_duo_answered 'PMPro unsupported user-field object capture' human "$OBJECT_OUT"
[ "$OBJECT_RC" -ne 0 ] && grep -Eqi 'pmpro_user_fields_settings|unclassified|plain|object' <<<"$OBJECT_OUT" \
  || fail "PMPro object-backed optional user fields did not refuse: $OBJECT_OUT"
wp_conf1 eval 'delete_option("pmpro_user_fields_settings"); update_option("pmpro_nonmembertext","AKIAABCDEFGHIJKLMNOP");' >/dev/null
SECRET_RC=0
SECRET_OUT=$(wp_conf1 duo capture --repo=/siterepo 2>&1) || SECRET_RC=$?
require_duo_answered 'PMPro authored secret capture' human "$SECRET_OUT"
[ "$SECRET_RC" -ne 0 ] && grep -q 'secret guard tripped' <<<"$SECRET_OUT" && ! grep -Fq 'AKIAABCDEFGHIJKLMNOP' <<<"$SECRET_OUT" \
  || fail "PMPro credential-shaped authored value did not refuse and redact: $SECRET_OUT"
wp_conf1 option update pmpro_nonmembertext 'Members only 東京 🚀' >/dev/null
wp_conf1 db query 'ALTER TABLE wp_pmpro_groups ADD COLUMN duo_unreviewed_schema varchar(40) NULL' >/dev/null
SCHEMA_RC=0
SCHEMA_OUT=$(wp_conf1 duo capture --repo=/siterepo 2>&1) || SCHEMA_RC=$?
require_duo_answered 'PMPro table schema drift capture' human "$SCHEMA_OUT"
[ "$SCHEMA_RC" -ne 0 ] && grep -Eqi 'schema mismatch|unexpected column|duo_unreviewed_schema' <<<"$SCHEMA_OUT" \
  || fail "PMPro table schema drift did not refuse: $SCHEMA_OUT"
wp_conf1 db query 'ALTER TABLE wp_pmpro_groups DROP COLUMN duo_unreviewed_schema' >/dev/null
[ "$(git -C "$CONF_REPO1" status --porcelain --untracked-files=all -- state)" = "$CAPTURE_BASELINE" ] \
  || fail 'PMPro malformed/secret/schema refusals partially published canonical state'
wp_conf1 duo capture --repo=/siterepo --out=/siterepo/.tmp-pmpro-restored >/dev/null
diff -r "$CONF_REPO1/state" "$CONF_REPO1/.tmp-pmpro-restored" || fail 'PMPro source did not restore byte-identically after refusal probes'
rm -rf "$CONF_REPO1/.tmp-pmpro-restored"
pass 'optional/object/add-on surfaces, secrets, and schema drift refuse atomically while large plain arrays and UTF-8 remain portable'

# Parent disappearance is intentionally unsupported: runtime and add-on reverse
# references are open. Preserve the exact row, prove no tombstone, then restore.
DELETE_STATUS=$(git -C "$CONF_REPO1" status --porcelain --untracked-files=all -- state)
DELETE_ID=$(wp_conf1 eval '
  global $wpdb; $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->pmpro_membership_levels} WHERE name=%s","Unsafe Delete Probe"),ARRAY_A);
  file_put_contents("/siterepo/.tmp-pmpro-delete-row.json",wp_json_encode($row));
  if (1 !== $wpdb->delete($wpdb->pmpro_membership_levels,["id"=>(int)$row["id"]])) throw new RuntimeException($wpdb->last_error);
  echo (int)$row["id"];
')
require_fixture_ids DELETE_ID
DELETE_RC=0
DELETE_OUT=$(wp_conf1 duo capture --repo=/siterepo --format=json) || DELETE_RC=$?
require_duo_answered 'PMPro unsupported parent deletion capture' json "$DELETE_OUT"
[ "$DELETE_RC" -ne 0 ] && jq -e '
  .format == "duo-command-refusal/v1" and .reason_code == "unsupported_deletion" and
  any(.diagnostics[]?; .code == "unsupported_deletion" and .surface == "table:pmpro_membership_levels")
' <<<"$DELETE_OUT" >/dev/null || fail "PMPro parent deletion did not refuse exactly: $DELETE_OUT"
[ "$(git -C "$CONF_REPO1" status --porcelain --untracked-files=all -- state)" = "$DELETE_STATUS" ] \
  || fail 'PMPro unsupported parent deletion published canonical bytes'
wp_conf1 eval '
  global $wpdb; $row=json_decode(file_get_contents("/siterepo/.tmp-pmpro-delete-row.json"),true,512,JSON_THROW_ON_ERROR);
  if (false === $wpdb->insert($wpdb->pmpro_membership_levels,$row)) throw new RuntimeException($wpdb->last_error);
' >/dev/null
rm -f "$CONF_REPO1/.tmp-pmpro-delete-row.json"
pass 'membership-level parent deletion refuses at its exact selector and publishes no partial edge tombstones'

# Exercise every positively-authorized edge deletion in one native graph edit.
wp_conf1 eval '
  global $wpdb;
  $builder=(int)$wpdb->get_var("SELECT id FROM {$wpdb->pmpro_membership_levels} WHERE name=\"Builder 東京 🚀\" AND initial_payment=19.95");
  $agency=(int)$wpdb->get_var("SELECT id FROM {$wpdb->pmpro_membership_levels} WHERE name=\"Agency Plan\"");
  $page=(int)get_page_by_path("pmpro-restricted",OBJECT,"page")->ID;
  pmpro_update_post_level_restrictions($page,[$builder]);
  pmpro_updateMembershipCategories($builder,[]);
  $wpdb->delete($wpdb->pmpro_membership_levels_groups,["level"=>$agency]);
  $discount=new PMPro_Discount_Code("DUO-PORTABLE-25"); unset($discount->levels[$agency]);
  if (!$discount->save()) throw new RuntimeException("discount edge removal failed");
' >/dev/null
commit_pmpro_source 'conformance: delete all four PMPro authored edge species'
WITHHELD=$(wp_conf2 duo apply --repo=/siterepo --default-author=admin 2>&1)
require_duo_answered 'PMPro edge deletions withheld without authority' human "$WITHHELD"
grep -q 'planned deletions NOT applied (4)' <<<"$WITHHELD" && grep -q -- '--with-deletes' <<<"$WITHHELD" \
  || fail "PMPro four edge deletions were not explicitly withheld: $WITHHELD"
WITHHELD_OBS=$(observe_pmpro conf2)
jq -e '.edges.category == 1 and .edges.discount_levels == 2 and .edges.pages == 2' <<<"$WITHHELD_OBS" >/dev/null \
  || fail "PMPro edge deletion ran without authority: $WITHHELD_OBS"
AUTHORIZED=$(wp_conf2 duo apply --repo=/siterepo --with-deletes --default-author=admin --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered 'PMPro authorized edge deletion apply' json "$AUTHORIZED"
jq -e '.canary == "clean" and .verification.result == "pass" and (.plan.delete + .plan.deleted) >= 4' <<<"$AUTHORIZED" >/dev/null \
  || fail "PMPro authorized edge deletions did not converge: $AUTHORIZED"
EDGE_OBS=$(observe_pmpro conf2)
jq -e '.edges.category == 0 and .edges.discount_levels == 1 and .edges.pages == 1 and (.access.page_levels | length) == 1' <<<"$EDGE_OBS" >/dev/null \
  || fail "PMPro native APIs did not consume authorized edge deletions: $EDGE_OBS"
pass 'all four pure authored edge tombstones withhold without authority, then delete and verify through PMPro APIs'

# Duplicate level/group names are legal in PMPro and therefore cannot be
# adopted as natural identities. A target-only same-name graph must be a loud
# mapped-identity/collision boundary, never silently rebound to repository UUIDs.
DIRTY_IDS=$(wp_conf2 eval '
  global $wpdb;
  $level=new PMPro_Membership_Level();
  foreach (["name"=>"Builder 東京 🚀","description"=>"hostile same-name row","confirmation"=>"hostile","initial_payment"=>888,"billing_amount"=>0,"cycle_number"=>0,"cycle_period"=>"Month","billing_limit"=>0,"trial_amount"=>0,"trial_limit"=>0,"allow_signups"=>0,"expiration_number"=>0,"expiration_period"=>"","categories"=>[]] as $k=>$v) $level->{$k}=$v;
  $level->save(); $group=pmpro_create_level_group("Portable Offers 東京",false,91); pmpro_add_level_to_group($level->id,$group);
  $edge=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->pmpro_membership_levels_groups} WHERE level=%d AND `group`=%d",$level->id,$group));
  echo wp_json_encode(["level"=>(int)$level->id,"group"=>(int)$group,"edge"=>$edge]);
' | tail -1)
require_observed_nonempty 'PMPro hostile duplicate-name ids' "$DIRTY_IDS"
DIRTY_PLAN_RC=0
DIRTY_PLAN=$(wp_conf2 duo plan --repo=/siterepo --format=json 2>&1) || DIRTY_PLAN_RC=$?
DIRTY_PLAN_JSON=$(awk 'NF { line=$0 } END { print line }' <<<"$DIRTY_PLAN")
require_duo_answered 'PMPro hostile duplicate-name plan' json "$DIRTY_PLAN_JSON"
if [ "$DIRTY_PLAN_RC" -eq 0 ]; then
  jq -e '([.collision,.conflict,.drift] | map(length) | add) > 0' <<<"$DIRTY_PLAN_JSON" >/dev/null \
    || fail "PMPro target-only duplicate names produced a falsely clean plan: $DIRTY_PLAN"
else
  jq -e '
    .format == "duo-command-refusal/v1" and .reason_code == "plan_failed" and
    .details_redacted == true
  ' <<<"$DIRTY_PLAN_JSON" >/dev/null \
    || fail "PMPro duplicate-name machine refusal did not preserve the redacted public envelope: $DIRTY_PLAN"
  DIRTY_HUMAN_RC=0
  DIRTY_HUMAN=$(wp_conf2 duo plan --repo=/siterepo 2>&1) || DIRTY_HUMAN_RC=$?
  require_duo_answered 'PMPro hostile duplicate-name private diagnostic' human "$DIRTY_HUMAN"
  [ "$DIRTY_HUMAN_RC" -ne 0 ] && grep -Eqi 'mapped identity|collision|missing|unmanaged' <<<"$DIRTY_HUMAN" \
    || fail "PMPro duplicate-name refusal did not name its identity boundary privately: $DIRTY_HUMAN"
fi
DIRTY_LEVEL=$(jq -r '.level' <<<"$DIRTY_IDS")
DIRTY_GROUP=$(jq -r '.group' <<<"$DIRTY_IDS")
DIRTY_EDGE=$(jq -r '.edge' <<<"$DIRTY_IDS")
require_fixture_ids DIRTY_LEVEL DIRTY_GROUP DIRTY_EDGE
wp_conf2 db query "
  DELETE FROM wp_pmpro_membership_levels_groups WHERE id=$DIRTY_EDGE;
  DELETE FROM wp_pmpro_groups WHERE id=$DIRTY_GROUP;
  DELETE FROM wp_pmpro_membership_levels WHERE id=$DIRTY_LEVEL;
  DELETE FROM wp_duo_map WHERE (id_kind='pmpro_level' AND local_id=$DIRTY_LEVEL)
    OR (id_kind='pmpro_group' AND local_id=$DIRTY_GROUP)
    OR (id_kind='pmpro_level_group' AND local_id=$DIRTY_EDGE);
" >/dev/null
pass 'legal duplicate level/group names remain mapped and force a loud dirty-target identity boundary instead of unsafe adoption'

# Competing repository and target edits to the same mapped row must conflict;
# explicit repository authority converges without disturbing target runtime or
# environment values.
wp_conf1 eval '
  global $wpdb; $id=(int)$wpdb->get_var("SELECT id FROM {$wpdb->pmpro_membership_levels} WHERE name=\"Builder 東京 🚀\" AND initial_payment=19.95");
  $level=new PMPro_Membership_Level($id); $level->description="Repository competing PMPro description 東京 🚀"; $level->save();
' >/dev/null
commit_pmpro_source 'conformance: competing PMPro level intent'
wp_conf2 eval '
  global $wpdb; $id=(int)$wpdb->get_var("SELECT id FROM {$wpdb->pmpro_membership_levels} WHERE name=\"Builder 東京 🚀\" AND initial_payment=19.95");
  $level=new PMPro_Membership_Level($id); $level->description="Target competing PMPro description"; $level->save();
' >/dev/null
CONFLICT_BEFORE=$(pmpro_target_hash)
CONFLICT_PLAN=$(wp_conf2 duo plan --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered 'PMPro competing level plan' json "$CONFLICT_PLAN"
jq -e '(.conflict | length) > 0' <<<"$CONFLICT_PLAN" >/dev/null \
  || fail "PMPro competing mapped row did not produce a conflict: $CONFLICT_PLAN"
CONFLICT_RC=0
CONFLICT_OUT=$(wp_conf2 duo apply --repo=/siterepo --default-author=admin 2>&1) || CONFLICT_RC=$?
require_duo_answered 'PMPro unforced competing level apply' human "$CONFLICT_OUT"
[ "$CONFLICT_RC" -ne 0 ] && grep -qi conflict <<<"$CONFLICT_OUT" \
  || fail "PMPro competing row did not refuse: $CONFLICT_OUT"
[ "$(pmpro_target_hash)" = "$CONFLICT_BEFORE" ] || fail 'PMPro unforced conflict partially mutated target authored state'
FORCED=$(wp_conf2 duo apply --repo=/siterepo --force-theirs --default-author=admin --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered 'PMPro forced competing level apply' json "$FORCED"
jq -e '.canary == "clean" and .verification.result == "pass" and .plan.conflict > 0' <<<"$FORCED" >/dev/null \
  || fail "PMPro forced repository intent did not converge: $FORCED"
CONVERGED=$(observe_pmpro conf2)
jq -e '
  .level.description_bytes > 40 and .runtime.target_members == 1 and .runtime.target_orders == 1 and
  .environment.secret == "sk_test_TARGET_SECRET_b84c" and .runtime.neighbor == "target-neighbor-preserved"
' <<<"$CONVERGED" >/dev/null || fail "PMPro forced conflict crossed target-owned boundaries: $CONVERGED"
grep -Fq 'Repository competing PMPro description 東京 🚀' <<<"$(wp_conf2 db query "SELECT description FROM wp_pmpro_membership_levels WHERE name='Builder 東京 🚀' AND initial_payment=19.95" --skip-column-names)" \
  || fail 'PMPro forced conflict did not apply repository description'
pass 'dirty mapped-row conflict refuses atomically; explicit force converges while runtime/payment state stays target-owned'

# Inject a late table constraint after publishing a multi-surface intent. The
# failed transaction must preserve the exact authored hash, retain retry
# authority, and consume that intent after the schema fault is removed.
wp_conf1 eval '
  global $wpdb; $id=(int)$wpdb->get_var("SELECT id FROM {$wpdb->pmpro_membership_levels} WHERE name=\"Builder 東京 🚀\" AND initial_payment=19.95");
  update_option("pmpro_currency","EUR");
  update_pmpro_membership_level_meta($id,"membership_account_message","PMPro transaction recovery 東京 🚀");
' >/dev/null
commit_pmpro_source 'conformance: PMPro transactional recovery intent'
FAULT_BEFORE=$(pmpro_target_hash)
wp_conf2 db query 'ALTER TABLE wp_pmpro_membership_levelmeta DROP CONSTRAINT IF EXISTS duo_pmpro_fail_meta' >/dev/null
wp_conf2 db query '
  ALTER TABLE wp_pmpro_membership_levelmeta ADD CONSTRAINT duo_pmpro_fail_meta
  CHECK (meta_key <> "membership_account_message" OR meta_value NOT LIKE "%PMPro transaction recovery%")
' >/dev/null
FAULT_RC=0
FAULT_OUT=$(wp_conf2 duo apply --repo=/siterepo --default-author=admin 2>&1) || FAULT_RC=$?
require_duo_answered 'PMPro injected table failure' human "$FAULT_OUT"
[ "$FAULT_RC" -ne 0 ] && grep -q 'duo_pmpro_fail_meta' <<<"$FAULT_OUT" \
  || fail "PMPro injected late table failure did not surface exactly: $FAULT_OUT"
[ "$(pmpro_target_hash)" = "$FAULT_BEFORE" ] || fail 'PMPro failed transaction left partial table/option writes'
[ "$(wp_conf2 eval 'echo null === \Duo\Ledger::kv_get("apply_in_progress") ? "clear" : "retained";')" = retained ] \
  || fail 'PMPro failed transaction did not retain retry authority'
wp_conf2 db query 'ALTER TABLE wp_pmpro_membership_levelmeta DROP CONSTRAINT duo_pmpro_fail_meta' >/dev/null
RETRY=$(wp_conf2 duo apply --repo=/siterepo --default-author=admin --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered 'PMPro retry after injected failure' json "$RETRY"
jq -e '.canary == "clean" and .verification.result == "pass" and .applied >= 1' <<<"$RETRY" >/dev/null \
  || fail "PMPro retry did not consume durable intent: $RETRY"
RETRIED=$(observe_pmpro conf2)
jq -e '.options.currency == "EUR" and .level.meta.membership_account_message == "PMPro transaction recovery 東京 🚀"' <<<"$RETRIED" >/dev/null \
  || fail "PMPro retry did not converge through its native metadata API: $RETRIED"
pass 'late PMPro table failure rolls back the graph and options together, retains authority, and retries through verified cache readback'

# Race two real apply processes on one new intent. One may finish first while
# the other sees no work or the named promotion lock; final native state and
# plan must be exact.
wp_conf1 eval '
  global $wpdb; $id=(int)$wpdb->get_var("SELECT id FROM {$wpdb->pmpro_membership_levels} WHERE name=\"Builder 東京 🚀\" AND initial_payment=19.95");
  update_pmpro_membership_level_meta($id,"membership_account_message","Concurrent PMPro intent 東京 🚀");
' >/dev/null
commit_pmpro_source 'conformance: concurrent PMPro apply intent'
CONCURRENT_A="$CONF_REPO2/.tmp-pmpro-concurrent-a.log"
CONCURRENT_B="$CONF_REPO2/.tmp-pmpro-concurrent-b.log"
set +e
wp_conf2 duo apply --repo=/siterepo --default-author=admin >"$CONCURRENT_A" 2>&1 & PID_A=$!
wp_conf2 duo apply --repo=/siterepo --default-author=admin >"$CONCURRENT_B" 2>&1 & PID_B=$!
wait "$PID_A"; RC_A=$?
wait "$PID_B"; RC_B=$?
set -e
if [ "$RC_A" -ne 0 ] && [ "$RC_B" -ne 0 ]; then
  fail "both competing PMPro applies failed: A=$(cat "$CONCURRENT_A") B=$(cat "$CONCURRENT_B")"
fi
for result in A B; do
  eval "rc=\$RC_$result"; eval "log=\$CONCURRENT_$result"
  if [ "$rc" -eq 0 ]; then
    grep -q 'canary clean' "$log" || fail "successful competing PMPro apply lacked a clean canary: $(cat "$log")"
  else
    grep -Eqi 'lock|another apply|in progress|promotion' "$log" \
      || fail "competing PMPro apply failed outside the named lock: $(cat "$log")"
  fi
done
rm -f "$CONCURRENT_A" "$CONCURRENT_B"
CONCURRENT=$(observe_pmpro conf2)
jq -e '.level.meta.membership_account_message == "Concurrent PMPro intent 東京 🚀"' <<<"$CONCURRENT" >/dev/null \
  || fail "competing PMPro applies lost repository intent: $CONCURRENT"
CONCURRENT_PLAN=$(wp_conf2 duo plan --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered 'PMPro plan after competing applies' json "$CONCURRENT_PLAN"
jq -e '([.create,.update,.drift,.conflict,.collision,.delete,.delete_conflict] | map(length) | add) == 0' <<<"$CONCURRENT_PLAN" >/dev/null \
  || fail "PMPro competing applies left retained work: $CONCURRENT_PLAN"
pass 'competing PMPro applies serialize and leave one exact idempotent native result'

# Deactivation is repaired by deploy. PMPro's opt-in uninstall is genuinely
# destructive: tables, PMPro options, and generated pages disappear. Missing
# code must refuse; exact digest reinstall plus target env reprovisioning and
# explicit repository authority reconstructs only authored state.
wp_conf2 plugin deactivate paid-memberships-pro >/dev/null
wp_conf2 plugin is-active paid-memberships-pro >/dev/null 2>&1 && fail 'PMPro deactivation premise did not land'
REACTIVATE=$(wp_conf2 duo deploy --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered 'PMPro deploy after deactivation' json "$REACTIVATE"
wp_conf2 plugin is-active paid-memberships-pro >/dev/null || fail 'Duo deploy did not reactivate exact PMPro code'
wp_conf2 option update pmpro_uninstall 1 >/dev/null
wp_conf2 plugin deactivate paid-memberships-pro >/dev/null
wp_conf2 plugin uninstall paid-memberships-pro >/dev/null
wp_conf2 plugin is-installed paid-memberships-pro >/dev/null 2>&1 && fail 'PMPro destructive uninstall left plugin code installed'
[ -z "$(wp_conf2 db query "SHOW TABLES LIKE 'wp_pmpro_membership_levels'" --skip-column-names | tr -d '[:space:]')" ] \
  || fail 'PMPro destructive uninstall retained authored tables'
[ "$(wp_conf2 option get duo_target_pmpro_neighbor)" = target-neighbor-preserved ] \
  || fail 'PMPro destructive uninstall mutated an unrelated target option'
MISSING_RC=0
MISSING_OUT=$(wp_conf2 duo deploy --repo=/siterepo 2>&1) || MISSING_RC=$?
require_duo_answered 'PMPro deploy with code absent' human "$MISSING_OUT"
[ "$MISSING_RC" -ne 0 ] && grep -Eq 'code_mismatch|missing_in_code|is not installed' <<<"$MISSING_OUT" \
  || fail "missing PMPro code did not refuse at compatibility: $MISSING_OUT"
PMPRO_SHA=6c3acc683939e01f203037334c16dddd146aaa30e01e09c41c6503705c1db7bf
PMPRO_ARTIFACT="/artifacts-cache/plugin-paid-memberships-pro-3.8.3-${PMPRO_SHA}.zip"
[ "$(wp_conf2 eval "echo hash_file('sha256','$PMPRO_ARTIFACT');")" = "$PMPRO_SHA" ] \
  || fail 'cached PMPro reinstall artifact digest moved'
wp_conf2 plugin install "$PMPRO_ARTIFACT" --force >/dev/null
$COMPOSE run --rm -T cli2 sh /duo-harness/artifact-archive-root.sh \
  /var/www/html/wp-content/plugins paid-memberships-pro-3.8.3 paid-memberships-pro >/dev/null
[ "$(wp_conf2 plugin get paid-memberships-pro --field=version)" = 3.8.3 ] || fail 'PMPro exact reinstall reported wrong version'
REINSTALL_DEPLOY=$(wp_conf2 duo deploy --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered 'PMPro deploy after exact reinstall' json "$REINSTALL_DEPLOY"
foreach_pair='pmpro_gateway=check pmpro_gateway_environment=sandbox pmpro_stripe_secretkey=sk_test_TARGET_SECRET_b84c pmpro_stripe_publishablekey=pk_test_TARGET_MARKER pmpro_cloudflare_turnstile_secret_key=turnstile_TARGET_SECRET pmpro_license_key=license_TARGET_SECRET pmpro_email_checkout_paid_to=target-recipient@example.test pmpro_use_ssl=0'
for assignment in $foreach_pair; do
  name=${assignment%%=*}; value=${assignment#*=}; wp_conf2 option update "$name" "$value" >/dev/null
done
RECOVERY=$(wp_conf2 duo apply --repo=/siterepo --adopt-by-slug=terms,posts --force-theirs --default-author=admin --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered 'PMPro recovery apply after destructive uninstall' json "$RECOVERY"
jq -e '.canary == "clean" and .verification.result == "pass" and .applied > 0' <<<"$RECOVERY" >/dev/null \
  || fail "PMPro destructive-uninstall recovery did not converge: $RECOVERY"
RECOVERED=$(observe_pmpro conf2)
jq -e '
  .version == "3.8.3" and .level.name == "Builder 東京 🚀" and .level.meta.membership_account_message == "Concurrent PMPro intent 東京 🚀" and
  .options.currency == "EUR" and .environment.secret == "sk_test_TARGET_SECRET_b84c" and
  .runtime.source_members == 0 and .runtime.source_orders == 0 and .runtime.target_members == 0 and .runtime.target_orders == 0 and
  .runtime.neighbor == "target-neighbor-preserved"
' <<<"$RECOVERED" >/dev/null || fail "PMPro exact reinstall did not restore only authored state: $RECOVERED"
pass 'deactivate/deploy and destructive uninstall/absent-code/exact-reinstall recover authored state while deleted runtime remains deleted and env is operator-owned'

FINAL_PLAN=$(wp_conf2 duo plan --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered 'PMPro final zero-change plan' json "$FINAL_PLAN"
jq -e '([.create,.update,.drift,.conflict,.collision,.delete,.delete_conflict] | map(length) | add) == 0' <<<"$FINAL_PLAN" >/dev/null \
  || fail "PMPro lifecycle recovery is not idempotent: $FINAL_PLAN"
wp_conf2 duo capture --repo=/siterepo --out=/siterepo/.tmp-pmpro-final >/dev/null
diff -r "$CONF_REPO1/state" "$CONF_REPO2/.tmp-pmpro-final" || fail 'PMPro final recapture is not byte-identical to source state'
rm -rf "$CONF_REPO2/.tmp-pmpro-final"
pass 'PMPro final plan is empty and exact native-state recapture is byte-identical'
