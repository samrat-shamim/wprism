#!/usr/bin/env bash
# Exact-artifact Ninja Forms acceptance. The native model and frontend, mapped
# identities/references, cache provider v2, runtime submissions, hostile data,
# deletion, retry, concurrency and lifecycle must agree before readiness closes.
set -euo pipefail
CONF1_PORT="${CONF1_PORT:-8806}"
CONF2_PORT="${CONF2_PORT:-8807}"
NINJA_EXPECTED_VERSION="${NINJA_EXPECTED_VERSION:-3.14.11}"

observe_ninja_forms() { # <conf1|conf2>
  local side="$1" repo runner file out
  case "$side" in
    conf1) repo="${CONF_REPO1:-siterepo/conf1}"; runner=wp_conf1 ;;
    conf2) repo="${CONF_REPO2:-siterepo/conf2}"; runner=wp_conf2 ;;
    *) fail "invalid Ninja Forms observation side: $side" ;;
  esac
  file="$repo/.tmp-ninja-observe.php"
  cat > "$file" <<'PHPEOF'
<?php
global $wpdb;

$page = get_page_by_path('conformance-careers', OBJECT, 'page');
if (!$page instanceof WP_Post) {
    throw new RuntimeException('Ninja Forms careers page is absent');
}
$formId = 0;
$walk = static function (array $blocks) use (&$walk, &$formId): void {
    foreach ($blocks as $block) {
        if (($block['blockName'] ?? '') === 'ninja-forms/form') {
            $formId = (int) ($block['attrs']['formID'] ?? 0);
        }
        if (is_array($block['innerBlocks'] ?? null)) {
            $walk($block['innerBlocks']);
        }
    }
};
$walk(parse_blocks((string) $page->post_content));
if ($formId <= 0) {
    throw new RuntimeException('Ninja Forms block has no target-local form identity');
}
$form = Ninja_Forms()->form($formId)->get();
$fields = Ninja_Forms()->form($formId)->get_fields();
$actions = Ninja_Forms()->form($formId)->get_actions();
if (!is_object($form) || !is_array($fields) || !is_array($actions)) {
    throw new RuntimeException('Ninja Forms native model graph is unreadable');
}

$ids = static function (string $table, int $parent) use ($wpdb): array {
    $found = $wpdb->get_col($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}$table WHERE parent_id=%d ORDER BY id",
        $parent
    ));
    if (!is_array($found) || $wpdb->last_error !== '') {
        throw new RuntimeException("Ninja Forms $table inventory failed");
    }
    return array_values(array_map('intval', $found));
};
$formIds = array_values(array_map('intval', $wpdb->get_col(
    "SELECT id FROM {$wpdb->prefix}nf3_forms ORDER BY id"
)));
$fieldIds = $ids('nf3_fields', $formId);
$actionIds = $ids('nf3_actions', $formId);
$fieldKeys = array_values(array_map('strval', (array) $wpdb->get_col($wpdb->prepare(
    "SELECT `key` FROM {$wpdb->prefix}nf3_fields WHERE parent_id=%d ORDER BY id",
    $formId
))));
$formContent = $form->get_setting('formContentData');
$layoutKeys = is_array($formContent) ? array_values(array_map('strval', $formContent)) : [];
$sortedFieldKeys = $fieldKeys;
$sortedLayoutKeys = $layoutKeys;
sort($sortedFieldKeys, SORT_STRING);
sort($sortedLayoutKeys, SORT_STRING);
$layoutMatchesFields = $sortedLayoutKeys === $sortedFieldKeys;
$disposableField = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT id FROM {$wpdb->prefix}nf3_fields WHERE parent_id=%d AND `key`='wprism_disposable_child'",
    $formId
));
$disposableAction = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT id FROM {$wpdb->prefix}nf3_actions WHERE parent_id=%d AND `key`='wprism_disposable_action'",
    $formId
));
$largeBytes = $disposableField > 0 ? (int) $wpdb->get_var($wpdb->prepare(
    "SELECT LENGTH(meta_value) FROM {$wpdb->prefix}nf3_field_meta WHERE parent_id=%d AND meta_key='instructions'",
    $disposableField
)) : 0;
$serialized = $disposableField > 0 ? $wpdb->get_var($wpdb->prepare(
    "SELECT meta_value FROM {$wpdb->prefix}nf3_field_meta WHERE parent_id=%d AND meta_key='options'",
    $disposableField
)) : null;
$serializedValid = false;
$serializedUrl = null;
if (is_string($serialized)) {
    $decoded = @unserialize($serialized, ['allowed_classes' => false, 'max_depth' => 64]);
    $serializedValid = is_array($decoded)
        && ($decoded[0]['label'] ?? null) === '東京'
        && serialize($decoded) === $serialized;
    $serializedUrl = is_array($decoded) ? ($decoded[0]['value'] ?? null) : null;
}

$cacheRows = $wpdb->get_results(
    "SELECT id,cache,stage,maintenance FROM {$wpdb->prefix}nf3_upgrades ORDER BY id",
    ARRAY_A
);
if (!is_array($cacheRows) || $wpdb->last_error !== '') {
    throw new RuntimeException('Ninja Forms cache inventory failed');
}
$cacheIds = [];
$cacheInvalid = 0;
$cacheFingerprints = [];
foreach ($cacheRows as $row) {
    $id = (int) ($row['id'] ?? 0);
    $cacheIds[] = $id;
    $decoded = is_string($row['cache'] ?? null)
        ? @unserialize($row['cache'], ['allowed_classes' => false, 'max_depth' => 64])
        : false;
    $cachedFields = is_array($decoded)
        ? array_map(static fn(array $item): int => (int) ($item['id'] ?? 0), (array) ($decoded['fields'] ?? []))
        : [];
    $cachedActions = is_array($decoded)
        ? array_map(static fn(array $item): int => (int) ($item['id'] ?? 0), (array) ($decoded['actions'] ?? []))
        : [];
    sort($cachedFields, SORT_NUMERIC);
    sort($cachedActions, SORT_NUMERIC);
    $expectedFields = $ids('nf3_fields', $id);
    $expectedActions = $ids('nf3_actions', $id);
    if (!is_array($decoded)
        || (int) ($decoded['id'] ?? 0) !== $id
        || $cachedFields !== $expectedFields
        || $cachedActions !== $expectedActions
        || (int) ($row['maintenance'] ?? -1) !== 0) {
        $cacheInvalid++;
    }
    $cacheFingerprints[(string) $id] = hash('sha256', (string) ($row['cache'] ?? ''));
}
sort($cacheIds, SORT_NUMERIC);
sort($formIds, SORT_NUMERIC);
ksort($cacheFingerprints, SORT_STRING);

$legacyRows = $wpdb->get_col(
    "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'nf_form_%' ORDER BY option_name"
);
$legacy = [];
foreach ((array) $legacyRows as $name) {
    if (is_string($name) && preg_match('/^nf_form_[1-9][0-9]*$/D', $name) === 1) {
        $legacy[] = $name;
    }
}
$mirrorMismatch = 0;
foreach (['nf3_form_meta', 'nf3_field_meta', 'nf3_action_meta'] as $table) {
    $mirrorMismatch += (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->prefix}$table "
        . "WHERE NOT (BINARY `key` <=> BINARY meta_key) OR NOT (BINARY `value` <=> BINARY meta_value)"
    );
}

$submissions = get_posts([
    'post_type' => 'nf_sub',
    'post_status' => 'any',
    'title' => 'Target Runtime Submission',
    'numberposts' => 1,
]);
$submission = $submissions[0] ?? null;
$submissionId = $submission instanceof WP_Post ? (int) $submission->ID : 0;
echo wp_json_encode([
    'version' => Ninja_Forms::VERSION,
    'form' => [
        'id' => $formId,
        'title' => (string) $form->get_setting('title'),
        'native_fields' => count($fields),
        'native_actions' => count($actions),
        'field_ids' => $fieldIds,
        'action_ids' => $actionIds,
        'disposable_field' => $disposableField,
        'disposable_action' => $disposableAction,
        'large_bytes' => $largeBytes,
        'serialized_valid' => $serializedValid,
        'serialized_url' => $serializedUrl,
        'layout_fields' => count($layoutKeys),
        'layout_has_disposable' => in_array('wprism_disposable_child', $layoutKeys, true),
        'layout_matches_fields' => $layoutMatchesFields,
    ],
    'cache' => [
        'form_ids' => $formIds,
        'cache_ids' => $cacheIds,
        'invalid' => $cacheInvalid,
        'missing' => array_values(array_diff($formIds, $cacheIds)),
        'orphan' => array_values(array_diff($cacheIds, $formIds)),
        'legacy' => $legacy,
        'fingerprint' => hash('sha256', serialize($cacheFingerprints)),
    ],
    'meta_mirror_mismatch' => $mirrorMismatch,
    'runtime' => [
        'neighbor' => get_option('ninja_forms_target_undeclared_neighbor', null),
        'submission_id' => $submissionId,
        'submission_form' => $submissionId > 0 ? get_post_meta($submissionId, '_form_id', true) : null,
        'submission_marker' => $submissionId > 0 ? get_post_meta($submissionId, '_field_999', true) : null,
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
PHPEOF
  out=$($runner eval-file /siterepo/.tmp-ninja-observe.php)
  rm -f "$file"
  require_observed_nonempty "$side Ninja Forms native observation" "$out"
  printf '%s\n' "$out"
}

ninja_target_hash() {
  observe_ninja_forms conf2 | shasum -a 256 | awk '{print $1}'
}

SOURCE=$(observe_ninja_forms conf1)
TARGET=$(observe_ninja_forms conf2)
require_observed_nonempty "conf2 Ninja Forms API observation" "$TARGET"
jq -e --arg version "$NINJA_EXPECTED_VERSION" --arg target_home "http://localhost:${CONF2_PORT}" '
  .version == $version and .form.title == "Job Application" and
  .form.native_fields == 24 and .form.native_actions == 4 and
  .form.disposable_field > 0 and .form.disposable_action > 0 and
  .form.large_bytes > 100000 and .form.serialized_valid == true and
  .form.serialized_url == ($target_home + "/conformance-careers/?from=ninja") and
  .form.layout_fields == 24 and .form.layout_has_disposable == true and
  .form.layout_matches_fields == true and
  .cache.form_ids == [.form.id] and .cache.cache_ids == [.form.id] and
  .cache.invalid == 0 and .cache.missing == [] and .cache.orphan == [] and .cache.legacy == [] and
  .meta_mirror_mismatch == 0 and
  .runtime.neighbor == "target-neighbor-survives" and
  .runtime.submission_id > 0 and .runtime.submission_form == "424242" and
  .runtime.submission_marker == "target-submission-survives"
' <<<"$TARGET" >/dev/null || fail "Ninja Forms authored/runtime/native state did not converge: $TARGET"

SOURCE_FORM_ID=$(jq -r '.form.id' <<<"$SOURCE")
CONF2_FORM_ID=$(jq -r '.form.id' <<<"$TARGET")
require_fixture_ids SOURCE_FORM_ID
require_fixture_ids CONF2_FORM_ID
[ "$SOURCE_FORM_ID" != "$CONF2_FORM_ID" ] && [ "$CONF2_FORM_ID" -ge 700000 ] \
  || fail "Ninja Forms source/target form identities did not diverge: source=$SOURCE_FORM_ID target=$CONF2_FORM_ID"
[ "$(jq -r '.form.field_ids | min' <<<"$TARGET")" -ge 720000 ] \
  || fail "Ninja Forms target field identities did not use the hostile high-id range: $TARGET"
[ "$(jq -r '.form.action_ids | min' <<<"$TARGET")" -ge 740000 ] \
  || fail "Ninja Forms target action identities did not use the hostile high-id range: $TARGET"

PROVIDER_RECEIPT="${APPLY_JSON:-}"
grep -Fq 'ninja-forms-form-cache@2.2.0' <<<"$PROVIDER_RECEIPT" \
  || fail "initial apply receipt did not identify Ninja Forms cache provider v2: ${PROVIDER_RECEIPT:-<missing>}"
jq -e '
  any(.actions[]?;
    .source == "provider:ninja-forms-form-cache/rebuild_form_caches" and .verified == true and
    .after.forms == 1 and .after.fields == 24 and .after.actions == 4 and
    .after.cache_rows == 1 and .after.missing_form_caches == 0 and
    .after.orphan_form_caches == 0 and .after.invalid_form_caches == 0 and
    .after.maintenance_form_caches == 0 and .after.legacy_form_caches == 0)
' <<<"$PROVIDER_RECEIPT" >/dev/null \
  || fail "Ninja Forms provider JSON receipt omitted its closed graph/cache projection: $PROVIDER_RECEIPT"

FRONT=$(curl -fsSL "http://localhost:${CONF2_PORT}/conformance-careers/") \
  || fail "conf2 conformance-careers page did not return 200"
require_observed_nonempty "conf2 Ninja Forms rendered response" "$FRONT"
[ "${#FRONT}" -ge 1000 ] || fail "conf2 Ninja Forms response was suspiciously short (${#FRONT} bytes)"
grep -qiE 'fatal error|uncaught' <<<"$FRONT" && fail "conf2 Ninja Forms response contains a fatal marker"
grep -q 'First Name' <<<"$FRONT" \
  && grep -Fq 'WPrism Disposable Child \u2014 \u754c' <<<"$FRONT" \
  && grep -Fq '"key":"wprism_disposable_child","type":"listselect"' <<<"$FRONT" \
  || fail "conf2 frontend did not publish core and extended Ninja Forms field models"
grep -Fq "http://localhost:${CONF1_PORT}" <<<"$FRONT" \
  && fail "conf2 Ninja Forms frontend leaked the source host"
pass "Ninja Forms native model/frontend publishes 24 fields, 4 actions, large UTF-8/serialized data and divergent target identities"
pass "provider v2 removed table/legacy orphan caches and preserved target-only submission/runtime state"

ZERO_PLAN=$(wp_conf2 wprism plan --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_wprism_answered 'Ninja Forms zero-change plan' json "$ZERO_PLAN"
jq -e '([.create,.update,.drift,.conflict,.collision,.delete,.delete_conflict] | map(length) | add) == 0' <<<"$ZERO_PLAN" >/dev/null \
  || fail "Ninja Forms retry retained work: $ZERO_PLAN"
ZERO_APPLY=$(wp_conf2 wprism apply --repo=/siterepo --default-author=admin --format=json | awk 'NF { line=$0 } END { print line }')
require_wprism_answered 'Ninja Forms zero-change apply' json "$ZERO_APPLY"
jq -e '.canary == "clean" and (.actions | length) == 0' <<<"$ZERO_APPLY" >/dev/null \
  || fail "Ninja Forms no-op apply was not clean and trigger-bounded: $ZERO_APPLY"
pass "Ninja Forms zero-change plan/apply is mutation-free and does not rerun the provider"

if [ "${NINJA_BOUNDARY_ONLY:-0}" = 1 ]; then
  pass "Ninja Forms $NINJA_EXPECTED_VERSION exact boundary consumed the full portable fixture"
  return 0 2>/dev/null || exit 0
fi

# issue #3209: mapped custom-table identity is environment-bound promotion
# metadata. Prove a database restore without it blocks before duplication,
# then prove the exact, hash-verified sidecar restores mappings, 3-way state,
# and applied-revision association. The row witness also has to reject a
# stale backup and an already-conflicting ledger without partial writes.
SIDE=/siterepo/.tmp-identity-ledger.json
wp_conf2 wprism identity-export --repo=/siterepo --out="$SIDE" >/dev/null
EXPECTED_MAPS=$(jq '.maps | length' "$CONF_REPO2/.tmp-identity-ledger.json")
EXPECTED_STATES=$(jq '.states | length' "$CONF_REPO2/.tmp-identity-ledger.json")
EXPECTED_REV=$(jq -r '.applied_revision' "$CONF_REPO2/.tmp-identity-ledger.json")
[ "$EXPECTED_MAPS" -gt 0 ] && [ "$EXPECTED_STATES" -gt 0 ] \
  || fail "identity sidecar omitted mappings or sync state"

wp_conf2 db query 'TRUNCATE TABLE wp_wprism_map; TRUNCATE TABLE wp_wprism_state; DELETE FROM wp_wprism_kv;' >/dev/null
PLAN_RC=0
PLAN_OUT=$(wp_conf2 wprism plan --repo=/siterepo 2>&1) || PLAN_RC=$?
# issue #3391: `|| PLAN_RC=$?` is what lets the two assertions below read
# $PLAN_OUT, and it is also what stops `set -e` from firing when this `docker
# compose run` dies at the docker layer with nothing but container-creation
# chatter in $PLAN_OUT — the exit-status assertion then passes VACUOUSLY and
# the grep accuses the engine over that chatter. Every identity probe in this
# file has that shape: assert the invocation was answered before asserting
# what the answer was.
require_wprism_answered "conf2 wprism plan (missing mapped identity probe)" human "$PLAN_OUT"
[ "$PLAN_RC" -ne 0 ] || fail "restored populated Ninja Forms DB planned successfully without identity metadata"
grep -q "mapped identity missing.*nf3_forms" <<<"$PLAN_OUT" \
  || fail "missing mapped identity failed for the wrong reason: $PLAN_OUT"

# plan repaired the post/term subset from embedded metadata before reaching
# nf3_forms; import must accept that verified subset, while still rejecting
# any row that disagrees with the sidecar.
wp_conf2 wprism identity-import --repo=/siterepo --in="$SIDE" >/dev/null
MAPS_NOW=$(wp_conf2 db query 'SELECT COUNT(*) FROM wp_wprism_map' --skip-column-names | tr -d '[:space:]')
STATES_NOW=$(wp_conf2 db query 'SELECT COUNT(*) FROM wp_wprism_state' --skip-column-names | tr -d '[:space:]')
REV_NOW=$(wp_conf2 db query "SELECT v FROM wp_wprism_kv WHERE k='applied_revision'" --skip-column-names | tr -d '[:space:]')
require_observed_nonempty "conf2 identity-map count after verified import" "$MAPS_NOW"
require_observed_nonempty "conf2 identity-state count after verified import" "$STATES_NOW"
require_observed_nonempty "conf2 applied revision after verified import" "$REV_NOW"
[ "$MAPS_NOW" = "$EXPECTED_MAPS" ] || fail "identity import restored $MAPS_NOW/$EXPECTED_MAPS mappings"
[ "$STATES_NOW" = "$EXPECTED_STATES" ] || fail "identity import restored $STATES_NOW/$EXPECTED_STATES sync states"
[ "$REV_NOW" = "$EXPECTED_REV" ] || fail "identity import lost applied revision ($REV_NOW != $EXPECTED_REV)"
RESTORED_PLAN=$(wp_conf2 wprism plan --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_wprism_answered "conf2 wprism plan after verified identity restore" json "$RESTORED_PLAN"
[ "$(jq '[.create,.update,.drift,.conflict,.collision] | map(length) | add' <<<"$RESTORED_PLAN")" = 0 ] \
  || fail "verified identity restore did not return target to a clean plan: $RESTORED_PLAN"

wp_conf2 db query "UPDATE wp_nf3_forms SET title='Stale Restore' WHERE id=$CONF2_FORM_ID; TRUNCATE TABLE wp_wprism_map; TRUNCATE TABLE wp_wprism_state; DELETE FROM wp_wprism_kv;" >/dev/null
STALE_RC=0
STALE_OUT=$(wp_conf2 wprism identity-import --repo=/siterepo --in="$SIDE" 2>&1) || STALE_RC=$?
require_wprism_answered "conf2 wprism identity-import (stale database/sidecar pairing probe)" human "$STALE_OUT"
[ "$STALE_RC" -ne 0 ] && grep -q 'witness mismatch' <<<"$STALE_OUT" \
  || fail "stale database/sidecar pairing was not rejected: $STALE_OUT"
STALE_MAP_COUNT=$(wp_conf2 db query 'SELECT COUNT(*) FROM wp_wprism_map' --skip-column-names | tr -d '[:space:]')
require_observed_nonempty "conf2 identity-map count after stale sidecar rejection" "$STALE_MAP_COUNT"
[ "$STALE_MAP_COUNT" = 0 ] \
  || fail "stale sidecar import partially mutated wprism_map"
wp_conf2 db query "UPDATE wp_nf3_forms SET title='Job Application' WHERE id=$CONF2_FORM_ID" >/dev/null
wp_conf2 wprism identity-import --repo=/siterepo --in="$SIDE" >/dev/null

wp_conf2 db query "UPDATE wp_wprism_map SET uuid='00000000-0000-4000-8000-000000000999' WHERE id_kind='nf3_form' AND local_id=$CONF2_FORM_ID" >/dev/null
CONFLICT_RC=0
CONFLICT_OUT=$(wp_conf2 wprism identity-import --repo=/siterepo --in="$SIDE" 2>&1) || CONFLICT_RC=$?
require_wprism_answered "conf2 wprism identity-import (conflicting live ledger probe)" human "$CONFLICT_OUT"
[ "$CONFLICT_RC" -ne 0 ] && grep -q 'current identity ledger conflicts' <<<"$CONFLICT_OUT" \
  || fail "conflicting live ledger was not rejected: $CONFLICT_OUT"
CONFLICT_UUID=$(wp_conf2 db query "SELECT uuid FROM wp_wprism_map WHERE id_kind='nf3_form' AND local_id=$CONF2_FORM_ID" --skip-column-names | tr -d '[:space:]')
require_observed_nonempty "conf2 live identity mapping after conflict rejection" "$CONFLICT_UUID"
[ "$CONFLICT_UUID" = '00000000-0000-4000-8000-000000000999' ] \
  || fail "conflicting sidecar import partially rebound the live mapping"
wp_conf2 db query 'TRUNCATE TABLE wp_wprism_map; TRUNCATE TABLE wp_wprism_state; DELETE FROM wp_wprism_kv;' >/dev/null
wp_conf2 wprism identity-import --repo=/siterepo --in="$SIDE" >/dev/null

jq '.applied_revision = "tampered"' "$CONF_REPO2/.tmp-identity-ledger.json" > "$CONF_REPO2/.tmp-identity-tampered.json"
TAMPER_RC=0
TAMPER_OUT=$(wp_conf2 wprism identity-import --repo=/siterepo --in=/siterepo/.tmp-identity-tampered.json 2>&1) || TAMPER_RC=$?
require_wprism_answered "conf2 wprism identity-import (tampered sidecar probe)" human "$TAMPER_OUT"
[ "$TAMPER_RC" -ne 0 ] && grep -q 'integrity hash does not verify' <<<"$TAMPER_OUT" \
  || fail "tampered identity sidecar was not rejected: $TAMPER_OUT"

# Source-side ledger loss is equally dangerous once canonical mapped UUIDs
# exist: capture must not mint replacements for them.
wp_conf1 wprism identity-export --repo=/siterepo --out="$SIDE" >/dev/null
wp_conf1 db query "DELETE FROM wp_wprism_map WHERE id_kind IN ('nf3_form','nf3_field','nf3_action')" >/dev/null
CAPTURE_RC=0
CAPTURE_OUT=$(wp_conf1 wprism capture --repo=/siterepo --out=/siterepo/.tmp-lost-ledger-state 2>&1) || CAPTURE_RC=$?
require_wprism_answered "conf1 wprism capture (lost mapped identity history probe)" human "$CAPTURE_OUT"
[ "$CAPTURE_RC" -ne 0 ] && grep -q 'mapped identity history is missing' <<<"$CAPTURE_OUT" \
  || fail "source capture minted replacements after mapped identity loss: $CAPTURE_OUT"
wp_conf1 wprism identity-import --repo=/siterepo --in="$SIDE" >/dev/null
wp_conf1 wprism capture --repo=/siterepo --out=/siterepo/.tmp-restored-state >/dev/null

pass "mapped identity loss blocks; verified sidecar restores map/state/revision; stale, conflicting, and tampered restores fail atomically"

commit_ninja_source() { # <message>
  wp_conf1 wprism capture --repo=/siterepo >/dev/null
  git -C "$CONF_REPO1" add -A
  git -C "$CONF_REPO1" -c user.name=wprism -c user.email=wprism@example.test commit -qm "$1"
  git -C "$CONF_REPO1" push -q origin main
  git -C "$CONF_REPO2" pull -q origin main
}

ninja_set_form_title() { # <conf1|conf2> <form-id> <title>
  local side="$1" form_id="$2" title="$3" repo service file
  case "$side" in
    conf1) repo="$CONF_REPO1"; service=cli1 ;;
    conf2) repo="$CONF_REPO2"; service=cli2 ;;
    *) fail "invalid Ninja Forms title-update side: $side" ;;
  esac
  file="$repo/.tmp-ninja-set-title.php"
  cat > "$file" <<'PHPEOF'
<?php
$id = (int) getenv('NF_FORM_ID');
$title = (string) getenv('NF_FORM_TITLE');
if ($id <= 0 || $title === '') {
    throw new RuntimeException('Ninja Forms native title update input is invalid');
}
$form = Ninja_Forms()->form($id)->get();
$form->update_setting('title', $title)->save();
WPN_Helper::delete_nf_cache($id);
WPN_Helper::build_nf_cache($id);
echo $id;
PHPEOF
  $COMPOSE run --rm -T -e "NF_FORM_ID=$form_id" -e "NF_FORM_TITLE=$title" \
    "$service" wp eval-file /siterepo/.tmp-ninja-set-title.php >/dev/null
  rm -f "$file"
}

# The open metadata keyspace is safe only while values remain bounded/plain
# serialization and the row type stays in the source-reviewed core roster.
# Each live capture refusal must leave canonical state byte-identical.
SOURCE_DISPOSABLE_FIELD=$(jq -r '.form.disposable_field' <<<"$SOURCE")
require_fixture_ids SOURCE_DISPOSABLE_FIELD
SOURCE_META_BACKUP=$(wp_conf1 db query \
  "SELECT HEX(meta_value) FROM wp_nf3_field_meta WHERE parent_id=$SOURCE_DISPOSABLE_FIELD AND meta_key='options'" \
  --skip-column-names | tr -d '[:space:]')
require_observed_nonempty 'Ninja Forms serialized setting backup' "$SOURCE_META_BACKUP"
CAPTURE_BASELINE=$(git -C "$CONF_REPO1" status --porcelain --untracked-files=all -- state)
[ -z "$CAPTURE_BASELINE" ] || fail "Ninja Forms source state was dirty before hostile capture probes: $CAPTURE_BASELINE"

MALFORMED_HEX=$(php -r 'echo bin2hex("a:1:{i:0;s:7:\"truncated\";");')
wp_conf1 db query \
  "UPDATE wp_nf3_field_meta SET value=UNHEX('$MALFORMED_HEX'),meta_value=UNHEX('$MALFORMED_HEX') WHERE parent_id=$SOURCE_DISPOSABLE_FIELD AND meta_key='options'" >/dev/null
MALFORMED_RC=0
MALFORMED_OUT=$(wp_conf1 wprism capture --repo=/siterepo 2>&1) || MALFORMED_RC=$?
require_wprism_answered 'Ninja Forms malformed serialized capture' human "$MALFORMED_OUT"
[ "$MALFORMED_RC" -ne 0 ] && grep -Eqi 'serialized setting|malformed|adapter_schema_content_mismatch' <<<"$MALFORMED_OUT" \
  || fail "Ninja Forms malformed serialized setting did not refuse: $MALFORMED_OUT"
[ "$(git -C "$CONF_REPO1" status --porcelain --untracked-files=all -- state)" = "$CAPTURE_BASELINE" ] \
  || fail 'Ninja Forms malformed serialized refusal partially published canonical state'

wp_conf1 db query \
  "UPDATE wp_nf3_field_meta SET value=UNHEX('$SOURCE_META_BACKUP'),meta_value=UNHEX('$SOURCE_META_BACKUP') WHERE parent_id=$SOURCE_DISPOSABLE_FIELD AND meta_key='options'" >/dev/null
FAKE_SECRET='sk_live_1234567890ABCDEFGHIJ'
SECRET_HEX=$(php -r 'echo bin2hex("sk_live_1234567890ABCDEFGHIJ");')
wp_conf1 db query \
  "UPDATE wp_nf3_field_meta SET value=UNHEX('$SECRET_HEX'),meta_value=UNHEX('$SECRET_HEX') WHERE parent_id=$SOURCE_DISPOSABLE_FIELD AND meta_key='options'" >/dev/null
SECRET_RC=0
SECRET_OUT=$(wp_conf1 wprism capture --repo=/siterepo 2>&1) || SECRET_RC=$?
require_wprism_answered 'Ninja Forms credential-shaped setting capture' human "$SECRET_OUT"
[ "$SECRET_RC" -ne 0 ] && grep -q 'secret guard tripped' <<<"$SECRET_OUT" \
  && ! grep -Fq "$FAKE_SECRET" <<<"$SECRET_OUT" \
  || fail "Ninja Forms credential-shaped setting did not refuse and redact: $SECRET_OUT"
[ "$(git -C "$CONF_REPO1" status --porcelain --untracked-files=all -- state)" = "$CAPTURE_BASELINE" ] \
  || fail 'Ninja Forms secret refusal partially published canonical state'

wp_conf1 db query \
  "UPDATE wp_nf3_field_meta SET value=UNHEX('$SOURCE_META_BACKUP'),meta_value=UNHEX('$SOURCE_META_BACKUP') WHERE parent_id=$SOURCE_DISPOSABLE_FIELD AND meta_key='options'; UPDATE wp_nf3_fields SET type='file_upload' WHERE id=$SOURCE_DISPOSABLE_FIELD" >/dev/null
ADDON_RC=0
ADDON_OUT=$(wp_conf1 wprism capture --repo=/siterepo 2>&1) || ADDON_RC=$?
require_wprism_answered 'Ninja Forms optional add-on type capture' human "$ADDON_OUT"
[ "$ADDON_RC" -ne 0 ] && grep -Eqi 'optional add-on|outside this adapter|file_upload' <<<"$ADDON_OUT" \
  || fail "Ninja Forms optional add-on field type did not refuse: $ADDON_OUT"
[ "$(git -C "$CONF_REPO1" status --porcelain --untracked-files=all -- state)" = "$CAPTURE_BASELINE" ] \
  || fail 'Ninja Forms add-on refusal partially published canonical state'
wp_conf1 db query "UPDATE wp_nf3_fields SET type='listselect' WHERE id=$SOURCE_DISPOSABLE_FIELD" >/dev/null
wp_conf1 wprism capture --repo=/siterepo --out=/siterepo/.tmp-ninja-hostile-restored >/dev/null
diff -r "$CONF_REPO1/state" "$CONF_REPO1/.tmp-ninja-hostile-restored" \
  || fail 'Ninja Forms source did not restore byte-identically after malformed/secret/add-on probes'
rm -rf "$CONF_REPO1/.tmp-ninja-hostile-restored"
pass 'malformed serialization, secrets and optional add-on types refuse atomically and redact values'

# A target-only same-title form has no mapped identity. Read-only planning may
# not mint or guess a binding from its mutable title; native cleanup must then
# return the managed graph to an exact clean plan.
cat > "$CONF_REPO2/.tmp-ninja-dirty-form.php" <<'PHPEOF'
<?php
$form = Ninja_Forms()->form()->get();
$form->update_setting('title', 'Job Application')->update_setting('key', 'dirty_same_title')->save();
echo (int) $form->get_id();
PHPEOF
DIRTY_FORM_ID=$(wp_conf2 eval-file /siterepo/.tmp-ninja-dirty-form.php)
rm -f "$CONF_REPO2/.tmp-ninja-dirty-form.php"
require_fixture_ids DIRTY_FORM_ID
DIRTY_RC=0
DIRTY_OUT=$(wp_conf2 wprism plan --repo=/siterepo 2>&1) || DIRTY_RC=$?
require_wprism_answered 'Ninja Forms unmanaged same-title target plan' human "$DIRTY_OUT"
[ "$DIRTY_RC" -ne 0 ] && grep -q "mapped identity missing for populated table 'nf3_forms'" <<<"$DIRTY_OUT" \
  || fail "Ninja Forms unmanaged same-title row was guessed/adopted: $DIRTY_OUT"
cat > "$CONF_REPO2/.tmp-ninja-delete-dirty.php" <<'PHPEOF'
<?php
$id = (int) getenv('NF_FORM_ID');
Ninja_Forms()->form($id)->get()->delete();
WPN_Helper::delete_nf_cache($id);
PHPEOF
$COMPOSE run --rm -T -e "NF_FORM_ID=$DIRTY_FORM_ID" cli2 \
  wp eval-file /siterepo/.tmp-ninja-delete-dirty.php >/dev/null
rm -f "$CONF_REPO2/.tmp-ninja-delete-dirty.php"
DIRTY_CLEAN=$(wp_conf2 wprism plan --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_wprism_answered 'Ninja Forms plan after unmanaged row cleanup' json "$DIRTY_CLEAN"
jq -e '([.create,.update,.drift,.conflict,.collision,.delete,.delete_conflict] | map(length) | add) == 0' <<<"$DIRTY_CLEAN" >/dev/null \
  || fail "Ninja Forms dirty-target cleanup did not restore exact state: $DIRTY_CLEAN"
pass 'unmanaged same-title target rows refuse mapped identity guessing without disturbing managed/runtime state'

# Both branches edit the same native form model. Unforced apply is a pure
# conflict refusal; explicit repository authority converges the authored row
# and provider cache while preserving target-owned submission/option state.
ninja_set_form_title conf1 "$SOURCE_FORM_ID" 'Repository competing Ninja title 東京 🚀'
commit_ninja_source 'conformance: competing Ninja Forms model intent'
ninja_set_form_title conf2 "$CONF2_FORM_ID" 'Target competing Ninja title'
CONFLICT_BEFORE=$(ninja_target_hash)
CONFLICT_PLAN=$(wp_conf2 wprism plan --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_wprism_answered 'Ninja Forms competing model plan' json "$CONFLICT_PLAN"
jq -e '(.conflict | length) > 0' <<<"$CONFLICT_PLAN" >/dev/null \
  || fail "Ninja Forms competing model did not produce a typed conflict: $CONFLICT_PLAN"
CONFLICT_RC=0
CONFLICT_OUT=$(wp_conf2 wprism apply --repo=/siterepo --default-author=admin 2>&1) || CONFLICT_RC=$?
require_wprism_answered 'Ninja Forms unforced competing model apply' human "$CONFLICT_OUT"
[ "$CONFLICT_RC" -ne 0 ] && grep -qi 'conflict' <<<"$CONFLICT_OUT" \
  || fail "Ninja Forms competing model did not refuse: $CONFLICT_OUT"
[ "$(ninja_target_hash)" = "$CONFLICT_BEFORE" ] \
  || fail 'Ninja Forms unforced conflict partially mutated target state/cache/runtime'
FORCED=$(wp_conf2 wprism apply --repo=/siterepo --force-theirs --default-author=admin --format=json | awk 'NF { line=$0 } END { print line }')
require_wprism_answered 'Ninja Forms forced competing model apply' json "$FORCED"
jq -e '
  .canary == "clean" and .verification.result == "pass" and .plan.conflict > 0 and
  any(.actions[]?; .source == "provider:ninja-forms-form-cache/rebuild_form_caches" and
    .verified == true and .after.invalid_form_caches == 0)
' <<<"$FORCED" >/dev/null || fail "Ninja Forms forced repository intent did not converge cleanly: $FORCED"
CONVERGED=$(observe_ninja_forms conf2)
jq -e '
  .form.title == "Repository competing Ninja title 東京 🚀" and
  .runtime.neighbor == "target-neighbor-survives" and
  .runtime.submission_marker == "target-submission-survives" and
  .cache.invalid == 0 and .cache.missing == [] and .cache.orphan == [] and .cache.legacy == []
' <<<"$CONVERGED" >/dev/null || fail "Ninja Forms forced conflict crossed a runtime/cache boundary: $CONVERGED"
pass 'dirty native-model conflicts refuse atomically; explicit force preserves target runtime boundaries'

# Inject a real post-commit provider failure. An orphan legacy cache avoids the
# authored form row's generic pre-provider invalidation, then forces the fresh
# child through delete_option(); a trigger rejects that delete after the
# transaction commits. Applied revision stays behind with retry authority;
# repairing only the trigger must converge.
ninja_set_form_title conf1 "$SOURCE_FORM_ID" 'Provider recovery Ninja title 東京 🚀'
commit_ninja_source 'conformance: Ninja Forms provider-fault recovery intent'
wp_conf2 eval "update_option('nf_form_999999', ['stale'=>'legacy target cache'], false);" >/dev/null
wp_conf2 db query \
  "DROP TRIGGER IF EXISTS wp_wprism_nf_legacy_fail; CREATE TRIGGER wp_wprism_nf_legacy_fail BEFORE DELETE ON wp_options FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='wprism injected Ninja legacy cache delete failure'" >/dev/null
FAILURE_REV_BEFORE=$(wp_conf2 db query "SELECT v FROM wp_wprism_kv WHERE k='applied_revision'" --skip-column-names | tr -d '[:space:]')
require_observed_nonempty 'Ninja Forms applied revision before provider fault' "$FAILURE_REV_BEFORE"
FAILURE_RC=0
FAILURE_OUT=$(wp_conf2 wprism apply --repo=/siterepo --default-author=admin 2>&1) || FAILURE_RC=$?
require_wprism_answered 'Ninja Forms injected provider failure' human "$FAILURE_OUT"
[ "$FAILURE_RC" -ne 0 ] && grep -q "provider 'ninja-forms-form-cache' capability 'rebuild_form_caches' failed" <<<"$FAILURE_OUT" \
  || fail "Ninja Forms injected child failure did not refuse in the provider: $FAILURE_OUT"
FAILURE_TITLE=$(wp_conf2 db query "SELECT title FROM wp_nf3_forms WHERE id=$CONF2_FORM_ID" --skip-column-names | tr -d '\r')
[ "$FAILURE_TITLE" = 'Provider recovery Ninja title 東京 🚀' ] \
  || fail 'Ninja Forms provider failure did not retain post-commit authored state for retry'
[ "$(wp_conf2 option get ninja_forms_target_undeclared_neighbor)" = target-neighbor-survives ] \
  || fail 'Ninja Forms provider failure crossed the target-owned option boundary'
[ "$(wp_conf2 db query "SELECT v FROM wp_wprism_kv WHERE k='applied_revision'" --skip-column-names | tr -d '[:space:]')" = "$FAILURE_REV_BEFORE" ] \
  || fail 'Ninja Forms provider failure advanced applied_revision before verified effects'
[ "$(wp_conf2 eval 'echo null === \WPrism\Ledger::kv_get("apply_in_progress") ? "clear" : "retained";')" = retained ] \
  || fail 'Ninja Forms provider failure did not retain retry authority'
wp_conf2 db query 'DROP TRIGGER wp_wprism_nf_legacy_fail' >/dev/null
RETRY=$(wp_conf2 wprism apply --repo=/siterepo --default-author=admin --format=json | awk 'NF { line=$0 } END { print line }')
require_wprism_answered 'Ninja Forms retry after provider repair' json "$RETRY"
jq -e '
  .canary == "clean" and .verification.result == "pass" and .applied >= 1 and
  any(.actions[]?; .source == "provider:ninja-forms-form-cache/rebuild_form_caches" and
    .verified == true and .after.legacy_form_caches == 0 and
    .after.invalid_form_caches == 0 and .after.missing_form_caches == 0 and .after.orphan_form_caches == 0)
' <<<"$RETRY" >/dev/null || fail "Ninja Forms provider retry did not consume retained intent: $RETRY"
RECOVERED=$(observe_ninja_forms conf2)
jq -e '
  .form.title == "Provider recovery Ninja title 東京 🚀" and .cache.invalid == 0 and
  .cache.missing == [] and .cache.orphan == [] and .cache.legacy == [] and
  .runtime.submission_marker == "target-submission-survives"
' <<<"$RECOVERED" >/dev/null || fail "Ninja Forms provider retry left partial state: $RECOVERED"
pass 'fresh-process failure retains exact post-commit intent and retries cleanly after repairing only the injected fault'

# Both advertised child selectors must produce real tombstones. Without
# authority they are withheld; with authority each row and attached meta are
# removed, the parent/submission remain, and provider cache membership closes.
SOURCE_DISPOSABLE_ACTION=$(jq -r '.form.disposable_action' <<<"$SOURCE")
TARGET_BEFORE_DELETE=$(observe_ninja_forms conf2)
TARGET_DISPOSABLE_FIELD=$(jq -r '.form.disposable_field' <<<"$TARGET_BEFORE_DELETE")
TARGET_DISPOSABLE_ACTION=$(jq -r '.form.disposable_action' <<<"$TARGET_BEFORE_DELETE")
require_fixture_ids SOURCE_DISPOSABLE_FIELD SOURCE_DISPOSABLE_ACTION TARGET_DISPOSABLE_FIELD TARGET_DISPOSABLE_ACTION
cat > "$CONF_REPO1/.tmp-ninja-delete-children.php" <<'PHPEOF'
<?php
$formId = (int) getenv('NF_FORM_ID');
$fieldId = (int) getenv('NF_FIELD_ID');
$actionId = (int) getenv('NF_ACTION_ID');
Ninja_Forms()->form($formId)->field($fieldId)->get()->delete();
Ninja_Forms()->form($formId)->action($actionId)->get()->delete();
$form = Ninja_Forms()->form($formId)->get();
$formContent = $form->get_setting('formContentData');
if (!is_array($formContent)) {
    throw new RuntimeException('Ninja Forms source child deletion found an invalid native layout');
}
$formContent = array_values(array_filter(
    $formContent,
    static function ($key): bool {
        return (string) $key !== 'wprism_disposable_child';
    }
));
$form->update_setting('formContentData', $formContent)->save();
WPN_Helper::delete_nf_cache($formId);
$cache = WPN_Helper::build_nf_cache($formId);
if (count((array) ($cache['fields'] ?? [])) !== 23
    || count((array) ($cache['actions'] ?? [])) !== 3) {
    throw new RuntimeException('Ninja Forms source child deletion cache did not converge');
}
PHPEOF
$COMPOSE run --rm -T -e "NF_FORM_ID=$SOURCE_FORM_ID" \
  -e "NF_FIELD_ID=$SOURCE_DISPOSABLE_FIELD" -e "NF_ACTION_ID=$SOURCE_DISPOSABLE_ACTION" \
  cli1 wp eval-file /siterepo/.tmp-ninja-delete-children.php >/dev/null
rm -f "$CONF_REPO1/.tmp-ninja-delete-children.php"
commit_ninja_source 'conformance: Ninja Forms field/action deletion intents'
WITHHELD=$(wp_conf2 wprism apply --repo=/siterepo --default-author=admin 2>&1)
require_wprism_answered 'Ninja Forms child deletions withheld without authority' human "$WITHHELD"
grep -q 'planned deletions NOT applied (2)' <<<"$WITHHELD" && grep -q -- '--with-deletes' <<<"$WITHHELD" \
  && grep -q 'canary clean' <<<"$WITHHELD" \
  || fail "Ninja Forms child deletions were not explicitly withheld: $WITHHELD"
[ "$(wp_conf2 db query "SELECT COUNT(*) FROM wp_nf3_fields WHERE id=$TARGET_DISPOSABLE_FIELD" --skip-column-names | tr -d '[:space:]')" = 1 ] \
  && [ "$(wp_conf2 db query "SELECT COUNT(*) FROM wp_nf3_actions WHERE id=$TARGET_DISPOSABLE_ACTION" --skip-column-names | tr -d '[:space:]')" = 1 ] \
  || fail 'Ninja Forms child deletion ran without explicit authority'
DELETED=$(wp_conf2 wprism apply --repo=/siterepo --with-deletes --default-author=admin --format=json | awk 'NF { line=$0 } END { print line }')
require_wprism_answered 'Ninja Forms authorized child deletion apply' json "$DELETED"
jq -e '
  .canary == "clean" and .verification.result == "pass" and (.plan.delete + .plan.deleted) >= 2 and
  any(.actions[]?; .source == "provider:ninja-forms-form-cache/rebuild_form_caches" and
    .verified == true and .after.fields == 23 and .after.actions == 3 and
    .after.invalid_form_caches == 0 and .after.missing_form_caches == 0 and .after.orphan_form_caches == 0)
' <<<"$DELETED" >/dev/null || fail "Ninja Forms authorized child deletions did not verify cache cleanup: $DELETED"
[ "$(wp_conf2 db query "SELECT COUNT(*) FROM wp_nf3_field_meta WHERE parent_id=$TARGET_DISPOSABLE_FIELD" --skip-column-names | tr -d '[:space:]')" = 0 ] \
  && [ "$(wp_conf2 db query "SELECT COUNT(*) FROM wp_nf3_action_meta WHERE parent_id=$TARGET_DISPOSABLE_ACTION" --skip-column-names | tr -d '[:space:]')" = 0 ] \
  || fail 'Ninja Forms authorized child deletion left attached metadata'
DELETE_OBSERVED=$(observe_ninja_forms conf2)
jq -e '
  .form.native_fields == 23 and .form.native_actions == 3 and
  .form.disposable_field == 0 and .form.disposable_action == 0 and
  .form.layout_fields == 23 and .form.layout_has_disposable == false and
  .form.layout_matches_fields == true and
  .runtime.submission_marker == "target-submission-survives" and
  .cache.invalid == 0 and .cache.missing == [] and .cache.orphan == []
' <<<"$DELETE_OBSERVED" >/dev/null || fail "Ninja Forms child deletion left invalid native/cache/runtime state: $DELETE_OBSERVED"
pass 'authorized field/action deletion removes attached meta and derived cache membership while parent/submission survive'

# Two real apply processes race one native title. At least one succeeds; the
# other may observe no work or refuse only at the named promotion lock.
ninja_set_form_title conf1 "$SOURCE_FORM_ID" 'Concurrent Ninja Forms intent 東京 🚀'
commit_ninja_source 'conformance: concurrent Ninja Forms apply intent'
CONCURRENT_A="$CONF_REPO2/.tmp-ninja-concurrent-a.log"
CONCURRENT_B="$CONF_REPO2/.tmp-ninja-concurrent-b.log"
set +e
wp_conf2 wprism apply --repo=/siterepo --default-author=admin >"$CONCURRENT_A" 2>&1 & PID_A=$!
wp_conf2 wprism apply --repo=/siterepo --default-author=admin >"$CONCURRENT_B" 2>&1 & PID_B=$!
wait "$PID_A"; RC_A=$?
wait "$PID_B"; RC_B=$?
set -e
if [ "$RC_A" -ne 0 ] && [ "$RC_B" -ne 0 ]; then
  fail "both competing Ninja Forms applies failed: A=$(cat "$CONCURRENT_A") B=$(cat "$CONCURRENT_B")"
fi
for result in A B; do
  eval "rc=\$RC_$result"; eval "log=\$CONCURRENT_$result"
  if [ "$rc" -eq 0 ]; then
    grep -q 'canary clean' "$log" || fail "successful competing Ninja Forms apply lacked a clean canary: $(cat "$log")"
  else
    grep -Eqi 'lock|another apply|in progress|promotion' "$log" \
      || fail "competing Ninja Forms apply failed outside the named lock: $(cat "$log")"
  fi
done
rm -f "$CONCURRENT_A" "$CONCURRENT_B"
CONCURRENT=$(observe_ninja_forms conf2)
jq -e '
  .form.title == "Concurrent Ninja Forms intent 東京 🚀" and
  .form.native_fields == 23 and .form.native_actions == 3 and
  .cache.invalid == 0 and .runtime.submission_marker == "target-submission-survives"
' <<<"$CONCURRENT" >/dev/null || fail "competing Ninja Forms applies lost repository/runtime intent: $CONCURRENT"
CONCURRENT_PLAN=$(wp_conf2 wprism plan --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_wprism_answered 'Ninja Forms plan after competing applies' json "$CONCURRENT_PLAN"
jq -e '([.create,.update,.drift,.conflict,.collision,.delete,.delete_conflict] | map(length) | add) == 0' <<<"$CONCURRENT_PLAN" >/dev/null \
  || fail "Ninja Forms competing applies left retained work: $CONCURRENT_PLAN"
pass 'competing Ninja Forms applies serialize and leave one exact idempotent result'

# Deactivation is repaired by deploy. Ninja Forms' exact native uninstall is
# intentionally non-destructive, so repository tables and target submissions
# must survive absent code, digest-bound reinstall, render, and the next real
# trigger-bearing authored revision.
wp_conf2 plugin deactivate ninja-forms >/dev/null
wp_conf2 plugin is-active ninja-forms >/dev/null 2>&1 && fail 'Ninja Forms deactivation premise did not land'
REACTIVATE=$(wp_conf2 wprism deploy --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_wprism_answered 'Ninja Forms deploy after deactivation' json "$REACTIVATE"
wp_conf2 plugin is-active ninja-forms >/dev/null || fail 'WPrism deploy did not reactivate exact Ninja Forms code'
GRAPH_SQL='SELECT SHA2(CONCAT(
  (SELECT GROUP_CONCAT(CONCAT_WS("|",id,title,form_title) ORDER BY id SEPARATOR "") FROM wp_nf3_forms),
  (SELECT GROUP_CONCAT(CONCAT_WS("|",id,parent_id,type,`key`) ORDER BY id SEPARATOR "") FROM wp_nf3_fields),
  (SELECT GROUP_CONCAT(CONCAT_WS("|",id,parent_id,type,`key`) ORDER BY id SEPARATOR "") FROM wp_nf3_actions)
),256)'
GRAPH_HASH_BEFORE=$(wp_conf2 db query "$GRAPH_SQL" --skip-column-names | tr -d '[:space:]')
require_observed_nonempty 'Ninja Forms authored graph hash before uninstall' "$GRAPH_HASH_BEFORE"
wp_conf2 plugin deactivate ninja-forms >/dev/null
wp_conf2 plugin uninstall ninja-forms >/dev/null
wp_conf2 plugin is-installed ninja-forms >/dev/null 2>&1 && fail 'Ninja Forms uninstall left plugin code installed'
GRAPH_HASH_AFTER=$(wp_conf2 db query "$GRAPH_SQL" --skip-column-names | tr -d '[:space:]')
[ "$GRAPH_HASH_AFTER" = "$GRAPH_HASH_BEFORE" ] \
  || fail 'Ninja Forms native uninstall removed repository-owned form graph data'
SUBMISSION_AFTER_UNINSTALL=$(wp_conf2 db query \
  "SELECT COUNT(*) FROM wp_posts WHERE post_type='nf_sub' AND post_title='Target Runtime Submission'" \
  --skip-column-names | tr -d '[:space:]')
[ "$SUBMISSION_AFTER_UNINSTALL" = 1 ] \
  || fail 'Ninja Forms native uninstall removed the target runtime submission'
MISSING_RC=0
MISSING_OUT=$(wp_conf2 wprism deploy --repo=/siterepo 2>&1) || MISSING_RC=$?
require_wprism_answered 'Ninja Forms deploy with code absent' human "$MISSING_OUT"
[ "$MISSING_RC" -ne 0 ] && grep -Eq 'code_mismatch|missing_in_code|is not installed' <<<"$MISSING_OUT" \
  || fail "missing Ninja Forms code did not refuse at compatibility: $MISSING_OUT"
NINJA_SHA=82dfb05166efeeeed9301c1e62f7838232f751d3cdc1acfb02daed028de33c73
NINJA_ARTIFACT="/artifacts-cache/plugin-ninja-forms-3.14.11-${NINJA_SHA}.zip"
[ "$(wp_conf2 eval "echo hash_file('sha256','$NINJA_ARTIFACT');")" = "$NINJA_SHA" ] \
  || fail 'cached Ninja Forms reinstall artifact digest moved'
wp_conf2 plugin install "$NINJA_ARTIFACT" --force >/dev/null
[ "$(wp_conf2 plugin get ninja-forms --field=version)" = 3.14.11 ] \
  || fail 'Ninja Forms exact reinstall reported wrong version'
REINSTALL_DEPLOY=$(wp_conf2 wprism deploy --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_wprism_answered 'Ninja Forms deploy after exact reinstall' json "$REINSTALL_DEPLOY"
REINSTALL_PLAN=$(wp_conf2 wprism plan --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_wprism_answered 'Ninja Forms plan after exact reinstall' json "$REINSTALL_PLAN"
jq -e '([.create,.update,.drift,.conflict,.collision,.delete,.delete_conflict] | map(length) | add) == 0' <<<"$REINSTALL_PLAN" >/dev/null \
  || fail "Ninja Forms uninstall unexpectedly removed repository-owned authored state: $REINSTALL_PLAN"
REINSTALL_FRONT=$(curl -fsSL "http://localhost:${CONF2_PORT}/conformance-careers/") \
  || fail 'Ninja Forms exact reinstall did not render retained form data'
grep -q 'First Name' <<<"$REINSTALL_FRONT" \
  || fail 'Ninja Forms exact reinstall native render lost retained fields'

ninja_set_form_title conf1 "$SOURCE_FORM_ID" 'Post-reinstall Ninja recovery 東京 🚀'
commit_ninja_source 'conformance: Ninja Forms post-reinstall recovery intent'
REINSTALL_APPLY=$(wp_conf2 wprism apply --repo=/siterepo --default-author=admin --format=json | awk 'NF { line=$0 } END { print line }')
require_wprism_answered 'Ninja Forms authored apply after exact reinstall' json "$REINSTALL_APPLY"
jq -e '
  .canary == "clean" and .verification.result == "pass" and .applied >= 1 and
  any(.actions[]?; .source == "provider:ninja-forms-form-cache/rebuild_form_caches" and
    .verified == true and .after.fields == 23 and .after.actions == 3 and
    .after.invalid_form_caches == 0 and .after.legacy_form_caches == 0)
' <<<"$REINSTALL_APPLY" >/dev/null || fail "Ninja Forms next authored revision did not recover derived state: $REINSTALL_APPLY"
FINAL=$(observe_ninja_forms conf2)
jq -e '
  .version == "3.14.11" and .form.title == "Post-reinstall Ninja recovery 東京 🚀" and
  .form.native_fields == 23 and .form.native_actions == 3 and
  .cache.invalid == 0 and .cache.missing == [] and .cache.orphan == [] and .cache.legacy == [] and
  .runtime.neighbor == "target-neighbor-survives" and .runtime.submission_marker == "target-submission-survives"
' <<<"$FINAL" >/dev/null || fail "Ninja Forms state did not recover after exact reinstall: $FINAL"
FINAL_PLAN=$(wp_conf2 wprism plan --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_wprism_answered 'Ninja Forms final recovery plan' json "$FINAL_PLAN"
jq -e '([.create,.update,.drift,.conflict,.collision,.delete,.delete_conflict] | map(length) | add) == 0' <<<"$FINAL_PLAN" >/dev/null \
  || fail "Ninja Forms recovery was not idempotent: $FINAL_PLAN"
wp_conf2 wprism capture --repo=/siterepo --out=/siterepo/.tmp-ninja-final >/dev/null
diff -r "$CONF_REPO1/state" "$CONF_REPO2/.tmp-ninja-final" \
  || fail 'Ninja Forms final recovered state was not byte-identical'
rm -rf "$CONF_REPO2/.tmp-ninja-final"
pass 'deactivate/reactivate, native uninstall residue, absent-code refusal, exact reinstall, render and final retry are clean'

# issue #3328: deleting the mapped parent is deliberately unsupported on the
# unmodified Ninja Forms schema. nf3_actions.parent_id and
# nf3_fields.parent_id are not indexed, so InnoDB cannot provide the
# next-key/gap-lock boundary required to close concurrent child insertion.
# The child selectors remain supported, but a whole-graph disappearance must
# refuse atomically at table:nf3_forms and publish no partial child tombstones.
CONF1_FORM_ID="$SOURCE_FORM_ID"
PAGE_ID=$(wp_conf1 post list --post_type=page --name=conformance-careers --field=ID | tr -d '[:space:]')
require_fixture_ids CONF1_FORM_ID PAGE_ID
wp_conf1 post update "$PAGE_ID" --post_content='<!-- wp:paragraph --><p>Applications are closed.</p><!-- /wp:paragraph -->' >/dev/null
wp_conf1 db query "
  DELETE FROM wp_nf3_field_meta WHERE parent_id IN (SELECT id FROM wp_nf3_fields WHERE parent_id=$CONF1_FORM_ID);
  DELETE FROM wp_nf3_action_meta WHERE parent_id IN (SELECT id FROM wp_nf3_actions WHERE parent_id=$CONF1_FORM_ID);
  DELETE FROM wp_nf3_fields WHERE parent_id=$CONF1_FORM_ID;
  DELETE FROM wp_nf3_actions WHERE parent_id=$CONF1_FORM_ID;
  DELETE FROM wp_nf3_form_meta WHERE parent_id=$CONF1_FORM_ID;
  DELETE FROM wp_nf3_upgrades WHERE id=$CONF1_FORM_ID;
  DELETE FROM wp_nf3_forms WHERE id=$CONF1_FORM_ID;
" >/dev/null
STATE_STATUS_BEFORE=$(git -C "$CONF_REPO1" status --porcelain --untracked-files=all -- state)
[ -z "$STATE_STATUS_BEFORE" ] || fail "source canonical state was dirty before parent-deletion refusal: $STATE_STATUS_BEFORE"
DELETION_INVENTORY_BEFORE=$(find "$CONF_REPO1/state/deletions" -type f -name '*.json' \
  -exec shasum -a 256 {} \; | LC_ALL=C sort)
CAPTURE_DELETE_RC=0
# The command's versioned record is stdout. Compose writes container lifecycle
# progress to stderr even for a healthy `run --rm`; folding both streams would
# make jq judge Docker's prose as if it were part of WPrism's machine contract.
CAPTURE_DELETE_OUT=$(wp_conf1 wprism capture --repo=/siterepo --format=json) || CAPTURE_DELETE_RC=$?
require_wprism_answered "parent-deletion capture" json "$CAPTURE_DELETE_OUT"
[ "$CAPTURE_DELETE_RC" -ne 0 ] || fail "capture accepted unsupported table:nf3_forms deletion"
jq -se '
  length == 1
  and .[0].format == "wprism-command-refusal/v1"
  and .[0].ok == false
  and .[0].command == "capture"
  and .[0].reason_code == "unsupported_deletion"
  and any(.[0].diagnostics[]?;
    .code == "unsupported_deletion"
    and .surface == "table:nf3_forms")
' <<<"$CAPTURE_DELETE_OUT" >/dev/null \
  || fail "parent-deletion refusal did not expose the exact table:nf3_forms capability gap: $CAPTURE_DELETE_OUT"
STATE_STATUS_AFTER=$(git -C "$CONF_REPO1" status --porcelain --untracked-files=all -- state)
[ "$STATE_STATUS_AFTER" = "$STATE_STATUS_BEFORE" ] \
  || fail "failed parent-deletion capture changed canonical state: $STATE_STATUS_AFTER"
DELETION_INVENTORY_AFTER=$(find "$CONF_REPO1/state/deletions" -type f -name '*.json' \
  -exec shasum -a 256 {} \; | LC_ALL=C sort)
[ "$DELETION_INVENTORY_AFTER" = "$DELETION_INVENTORY_BEFORE" ] \
  || fail "failed parent-deletion capture changed the committed child tombstone inventory"
pass "unmodified Ninja Forms loudly refuses table:nf3_forms deletion during capture and publishes no partial child tombstones"
