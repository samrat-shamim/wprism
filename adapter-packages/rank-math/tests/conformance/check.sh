#!/usr/bin/env bash
# Rank Math acceptance beyond canonical byte equality: native model/frontend,
# references, schema and derived state, failure/retry, conflicts, lifecycle,
# deletion/refusal boundaries, duplicate identities, and concurrent apply.
set -euo pipefail

CONF2_PORT="${CONF2_PORT:-8807}"
RANK_MATH_EXPECTED_VERSION="${RANK_MATH_EXPECTED_VERSION:-1.0.277.2}"

. "$(dirname "${BASH_SOURCE[0]}")/../../fixtures/native-observer.sh"

# One exact, value-redacted database oracle for checkpoint recovery. It covers
# plugin activation, every Rank Math option, authored and derived rows (including
# counters), table structure, post/term metadata, and the unrelated neighbor.
# Hashes keep the 100 KiB plain-data fixture out of diagnostics without turning
# equality into a sampled assertion.
rank_math_recovery_state() { # <conf1|conf2>
  local side="$1" repo file out
  case "$side" in
    conf1) repo="${CONF_REPO1:-siterepo/conf1}" ;;
    conf2) repo="${CONF_REPO2:-siterepo/conf2}" ;;
    *) fail "invalid Rank Math recovery-observation side: $side" ;;
  esac
  file="$repo/.tmp-rank-math-recovery-state.php"
  cat > "$file" <<'PHPEOF'
<?php
global $wpdb;
$hash = static fn($value): string => hash(
    'sha256',
    wp_json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
);
$options = $wpdb->get_results(
    "SELECT option_name,option_value,autoload FROM {$wpdb->options} " .
        "WHERE option_name LIKE 'rank\\_math%' ESCAPE '\\\\' " .
        "OR option_name LIKE 'rank-math-%' OR option_name='wprism_rank_math_target_neighbor' " .
        'ORDER BY option_name',
    ARRAY_A
);
foreach ($options as &$option) {
    $option['option_value_sha256'] = hash('sha256', (string) $option['option_value']);
    unset($option['option_value']);
}
unset($option);
$meta = [];
foreach (['postmeta', 'termmeta'] as $kind) {
    $id = $kind === 'postmeta' ? 'post_id' : 'term_id';
    $table = $wpdb->$kind;
    $rows = $wpdb->get_results(
        "SELECT meta_id,$id,meta_key,meta_value FROM $table " .
            "WHERE meta_key LIKE 'rank\\_math%' ESCAPE '\\\\' ORDER BY meta_id",
        ARRAY_A
    );
    foreach ($rows as &$row) {
        $row['meta_value_sha256'] = hash('sha256', (string) $row['meta_value']);
        unset($row['meta_value']);
    }
    unset($row);
    $meta[$kind] = $rows;
}
$orders = [
    'rank_math_internal_links' => 'id',
    'rank_math_internal_meta' => 'object_id',
    'rank_math_redirections' => 'id',
    'rank_math_redirections_cache' => 'id',
];
$tables = [];
foreach ($orders as $suffix => $order) {
    $table = $wpdb->prefix . $suffix;
    $present = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    if (!$present) {
        $tables[$suffix] = null;
        continue;
    }
    $create = $wpdb->get_row("SHOW CREATE TABLE `$table`", ARRAY_N);
    $rows = $wpdb->get_results("SELECT * FROM `$table` ORDER BY `$order`", ARRAY_A);
    $tables[$suffix] = [
        'create_sha256' => hash('sha256', (string) ($create[1] ?? '')),
        'row_count' => count($rows),
        'rows_sha256' => $hash($rows),
    ];
}
echo wp_json_encode([
    'active_plugins' => array_values((array) get_option('active_plugins', [])),
    'meta' => $meta,
    'options' => $options,
    'tables' => $tables,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
PHPEOF
  if [ "$side" = conf1 ]; then
    out=$(wp_conf1 eval-file /siterepo/.tmp-rank-math-recovery-state.php)
  else
    out=$(wp_conf2 eval-file /siterepo/.tmp-rank-math-recovery-state.php)
  fi
  rm -f "$file"
  require_observed_nonempty "$side Rank Math recovery observation" "$out"
  printf '%s\n' "$out" | awk 'NF { line=$0 } END { print line }'
}

commit_rank_math_source() { # <message>
  local message="$1"
  wp_conf1 wprism capture --repo=/siterepo >/dev/null
  git -C "${CONF_REPO1:-siterepo/conf1}" add -A
  git -C "${CONF_REPO1:-siterepo/conf1}" -c user.name=wprism -c user.email=wprism@example.test commit -qm "$message"
  git -C "${CONF_REPO1:-siterepo/conf1}" push -q origin main
  git -C "${CONF_REPO2:-siterepo/conf2}" pull -q origin main
}

rank_math_request() { # <path>
  local path="$1"
  RANK_MATH_BODY=$(mktemp "${TMPDIR:-/tmp}/wprism-rank-math-body.XXXXXX")
  RANK_MATH_HEADERS=$(mktemp "${TMPDIR:-/tmp}/wprism-rank-math-headers.XXXXXX")
  RANK_MATH_CODE=$(curl --max-time 20 -sS -D "$RANK_MATH_HEADERS" -o "$RANK_MATH_BODY" \
    -w '%{http_code}' "http://localhost:${CONF2_PORT}${path}") \
    || fail "Rank Math request failed before response: $path"
  RANK_MATH_LOCATION=$(awk 'BEGIN { IGNORECASE=1 } /^Location:/ { sub(/\r$/, ""); print substr($0, 11) }' \
    "$RANK_MATH_HEADERS" | tail -1)
}

SOURCE=$(observe_rank_math conf1 "${CONF_REPO1:-siterepo/conf1}" wp_conf1)
TARGET=$(observe_rank_math conf2 "${CONF_REPO2:-siterepo/conf2}" wp_conf2)
require_observed_nonempty 'Rank Math source native observation' "$SOURCE"
require_observed_nonempty 'Rank Math target native observation' "$TARGET"
jq -e --arg port "$CONF2_PORT" --arg version "$RANK_MATH_EXPECTED_VERSION" '
  .version == $version and
  (.setup.configured | tostring) == "1" and (.setup.registration_skip | tostring) == "1" and
  (.modules | index("link-counter")) != null and (.modules | index("redirections")) != null and
  .options.breadcrumbs_home_label == "Origin 東京 🚀" and
  .options.plain_large_bytes > 100000 and
  .options.plain_nested == {enabled:true,threshold:0,nullable:null} and
  .options.homepage_image_id == .ids.attachment and .options.logo_id == .ids.attachment and
  .options.open_graph_image_id == .ids.attachment and
  .options.local_seo_about_page == .ids.hub and .options.local_seo_contact_page == .ids.post and
  .post.title == "Portable Rank Math Title 東京 🚀 %sep% %sitename%" and
  .post.description == "Portable Rank Math description 東京 🚀 with | = : delimiters." and
  .post.facebook_image_id == .ids.attachment and .post.primary_category == .ids.category and
  .post.processed == true and
  .term.facebook_image_id == .ids.attachment and
  .term.title == "Portable Category Title 東京 🚀 %sep% %sitename%" and
  .redirection_count == 1 and .redirection.header_code == "302" and
  .redirection.status == "active" and
  .redirection.sources_shape == [{ignore:"",pattern:"rank-math-old",comparison:"exact"}] and
  (.redirection.url_to | startswith("http://localhost:" + $port + "/rank-math-hub/")) and
  (.links | length) == 2 and ([.links[].type] | sort) == ["external","internal"] and
  ([.links[] | select(.type == "internal")][0].target_post_id | tonumber) == .ids.hub and
  (.post_counts.internal_link_count | tonumber) == 1 and
  (.post_counts.external_link_count | tonumber) == 1 and
  (.hub_counts.incoming_link_count | tonumber) == 1 and
  ([.schema[].present] | all) and
  .target_owned.instant_key == "target-indexnow-credential-must-survive" and
  .target_owned.indexnow_log == [{
    url:("http://localhost:" + $port + "/target-indexnow-history-must-survive/"),
    status:202,manual_submission:true,message:"target runtime submission history",time:1800000002
  }] and
  .target_owned.notifications == [{
    message:"Target runtime notification must survive",
    options:{id:"wprism-target-runtime",classes:"rank-math-notice",type:"success",screen:"any",capability:""}
  }] and
  .target_owned.neighbor == "target-neighbor-must-survive"
' <<<"$TARGET" >/dev/null || fail "Rank Math portable/native/derived/runtime state did not converge: $TARGET"
jq -e '
  .target_owned.notifications == [{
    message:"Source runtime notification must not transfer",
    options:{id:"wprism-source-runtime",classes:"rank-math-notice",type:"success",screen:"any",capability:""}
  }]
' <<<"$SOURCE" >/dev/null \
  || fail "Rank Math source persistent notification changed or transferred: $SOURCE"
if git -C "${CONF_REPO1:-siterepo/conf1}" grep -Fq \
  'Source runtime notification must not transfer' -- state; then
  fail 'Rank Math source runtime notification entered canonical state'
fi

for key in attachment category hub post secondary tag; do
  SOURCE_ID=$(jq -r --arg key "$key" '.ids[$key]' <<<"$SOURCE")
  TARGET_ID=$(jq -r --arg key "$key" '.ids[$key]' <<<"$TARGET")
  require_fixture_ids SOURCE_ID TARGET_ID
  [ "$SOURCE_ID" != "$TARGET_ID" ] || fail "Rank Math $key identity did not diverge ($SOURCE_ID)"
done
SOURCE_REDIR=$(jq -r '.redirection.id' <<<"$SOURCE")
TARGET_REDIR=$(jq -r '.redirection.id' <<<"$TARGET")
require_fixture_ids SOURCE_REDIR TARGET_REDIR
[ "$SOURCE_REDIR" != "$TARGET_REDIR" ] || fail "Rank Math natural-key redirection did not adopt the target identity ($SOURCE_REDIR)"
[ "$(jq -r '.redirection.hits' <<<"$TARGET")" = 37 ] \
  || fail 'Rank Math authored redirection convergence overwrote the target runtime hit counter'

PROVIDER_RECEIPT="${APPLY_JSON:-}"
# The full dependency hash includes request-local WordPress topology and may
# legitimately match or differ across the applying parent and repair child.
# The deterministic provider suite forces the differing case; this live path
# proves the durable dependency projection is stable while every seeded stale
# derived surface is replaced by the native repair.
jq -e '
  .canary == "clean" and .verification.result == "pass" and
  any(.actions[]?;
    .source == "provider:rank-math-state/rebuild_all_link_state" and .verified == true and
    .before.enabled == true and .after.enabled == true and
    .before.link_count == 1 and .after.link_count == 2 and
    .before.meta_count == 1 and .after.meta_count == 2 and
    .before.marker_count == 1 and .after.marker_count == 2 and
    .before.link_hash != .after.link_hash and
    .before.meta_hash != .after.meta_hash and
    .before.marker_hash != .after.marker_hash and
    ([.before.link_hash,.after.link_hash,.before.meta_hash,.after.meta_hash,
      .before.marker_hash,.after.marker_hash] | all(test("^[a-f0-9]{64}$"))) and
    (.before.dependency_hash | test("^[a-f0-9]{64}$")) and
    (.after.dependency_hash | test("^[a-f0-9]{64}$")) and
    (.after.dependency_state_hash | test("^[a-f0-9]{64}$")) and
    .before.dependency_state_hash == .after.dependency_state_hash) and
  ([.actions[]?.source | select(startswith("provider:rank-math-state/"))] | sort | unique) ==
    ["provider:rank-math-state/rebuild_all_link_state"]
' <<<"$PROVIDER_RECEIPT" >/dev/null \
  || fail "Rank Math initial apply omitted its one site-complete provider proof: ${PROVIDER_RECEIPT:-<missing>}"
grep -Fq 'rank-math-hub' <<<"$(jq -c '.actions' <<<"$PROVIDER_RECEIPT")" \
  && fail 'Rank Math provider receipt leaked an authored URL'
pass 'divergent identities converge through exact value-free provider receipts while source and target persistent notifications remain isolated'

# The real settings object contains a password field. Exercise the complete
# capture publication path with that field populated: the outer authored option
# must refuse, redact the value, and leave the committed repository byte-exact.
SECRET_REPO_BEFORE=$(git -C "${CONF_REPO1:-siterepo/conf1}" status --porcelain=v1 --untracked-files=all)
[ -z "$SECRET_REPO_BEFORE" ] || fail "Rank Math secret-refusal premise requires a clean source repository: $SECRET_REPO_BEFORE"
wp_conf1 eval '
$titles=(array)get_option("rank-math-options-titles",[]);
if (array_key_exists("facebook_secret",$titles)) throw new RuntimeException("Facebook secret premise is not empty");
$titles["facebook_secret"]="WprismFacebookSecretA19z7Q4m";
update_option("rank-math-options-titles",$titles);
' >/dev/null
SECRET_CAPTURE_RC=0
SECRET_CAPTURE_OUT=$(wp_conf1 wprism capture --repo=/siterepo 2>&1) || SECRET_CAPTURE_RC=$?
require_wprism_answered 'Rank Math populated Facebook secret capture' human "$SECRET_CAPTURE_OUT"
[ "$SECRET_CAPTURE_RC" -ne 0 ] \
  && grep -Fq "secret guard tripped — options 'rank-math-options-titles'" <<<"$SECRET_CAPTURE_OUT" \
  && ! grep -Fq 'WprismFacebookSecretA19z7Q4m' <<<"$SECRET_CAPTURE_OUT" \
  || fail "Rank Math populated Facebook secret was not refused and redacted: $SECRET_CAPTURE_OUT"
[ -z "$(git -C "${CONF_REPO1:-siterepo/conf1}" status --porcelain=v1 --untracked-files=all)" ] \
  || fail 'Rank Math secret refusal partially published repository state'
wp_conf1 eval '
$titles=(array)get_option("rank-math-options-titles",[]);
unset($titles["facebook_secret"]);
update_option("rank-math-options-titles",$titles);
' >/dev/null
wp_conf1 wprism capture --repo=/siterepo --out=/siterepo/.tmp-rank-math-secret-restored >/dev/null
diff -r "${CONF_REPO1:-siterepo/conf1}/state" \
  "${CONF_REPO1:-siterepo/conf1}/.tmp-rank-math-secret-restored" \
  || fail 'Rank Math source did not return to byte-identical canonical state after secret refusal'
rm -rf "${CONF_REPO1:-siterepo/conf1}/.tmp-rank-math-secret-restored"
pass 'populated Rank Math Facebook credentials refuse through capture without disclosure or partial publication'

rank_math_request '/rank-math-article/'
[ "$RANK_MATH_CODE" = 200 ] \
  && grep -Fq 'Portable Rank Math Title' "$RANK_MATH_BODY" \
  && grep -Fq 'Portable Rank Math description' "$RANK_MATH_BODY" \
  && grep -Fq "http://localhost:${CONF2_PORT}/rank-math-canonical/" "$RANK_MATH_BODY" \
  && grep -Fq "http://localhost:${CONF2_PORT}/wp-content/uploads/" "$RANK_MATH_BODY" \
  || fail "Rank Math frontend did not render target-bound title/description/canonical/social image (status=$RANK_MATH_CODE)"
rm -f "$RANK_MATH_BODY" "$RANK_MATH_HEADERS"

HITS_BEFORE=$(jq -r '.redirection.hits' <<<"$TARGET")
rank_math_request '/rank-math-old'
[[ "$RANK_MATH_CODE" =~ ^30[1278]$ ]] \
  && [[ "$RANK_MATH_LOCATION" == "http://localhost:${CONF2_PORT}/rank-math-hub/?from=redirect"* ]] \
  || fail "Rank Math native redirect failed (status=$RANK_MATH_CODE location=${RANK_MATH_LOCATION:-<none>})"
rm -f "$RANK_MATH_BODY" "$RANK_MATH_HEADERS"
AFTER_TRAFFIC=$(observe_rank_math conf2 "${CONF_REPO2:-siterepo/conf2}" wp_conf2)
[ "$(jq -r '.redirection.hits' <<<"$AFTER_TRAFFIC")" -gt "$HITS_BEFORE" ] \
  || fail 'Rank Math native redirect did not advance target-local traffic telemetry'
wp_conf2 wprism capture --repo=/siterepo --out=/siterepo/.tmp-rank-math-traffic >/dev/null
diff -r "${CONF_REPO1:-siterepo/conf1}/state" "${CONF_REPO2:-siterepo/conf2}/.tmp-rank-math-traffic" \
  || fail 'Rank Math redirect traffic/cache/link projections leaked into canonical state'
rm -rf "${CONF_REPO2:-siterepo/conf2}/.tmp-rank-math-traffic"
pass 'real frontend SEO and 302 routing consume target-local references while traffic, cache and links remain noncanonical'

capture_wprism_json_checked ZERO_PLAN 'Rank Math zero-change plan' assert_wprism_json_required_environment \
  wp_conf2 wprism plan --repo=/siterepo --format=json
require_wprism_answered 'Rank Math zero-change plan' json "$ZERO_PLAN"
jq -e '([.create,.update,.drift,.conflict,.collision,.delete,.delete_conflict] | map(length) | add) == 0' \
  <<<"$ZERO_PLAN" >/dev/null || fail "Rank Math zero-change plan retained work: $ZERO_PLAN"
capture_wprism_json_checked ZERO_APPLY 'Rank Math zero-change apply' assert_wprism_apply_ready \
  wp_conf2 wprism apply --repo=/siterepo --default-author=admin --format=json
require_wprism_answered 'Rank Math zero-change apply' json "$ZERO_APPLY"
jq -e '.canary == "clean" and (.actions | length) == 0' <<<"$ZERO_APPLY" >/dev/null \
  || fail "Rank Math zero-change apply reran effects: $ZERO_APPLY"
pass 'zero-change plan/apply is mutation-free and does not rerun the site-complete provider action'

# A post:* trigger participates in scoped apply only when its provider carries
# the operation-bound invoke/reconcile contract. Exercise the real public path:
# one selected post-meta change, hash-only provider evidence, exact target
# convergence, and terminal replay without repeating the native repair.
RANK_SCOPED_SOURCE_POST=$(jq -r '.ids.post' <<<"$SOURCE")
RANK_SCOPED_UUID=$(wp_conf1 db query \
  "SELECT uuid FROM wp_wprism_map WHERE id_kind='post' AND local_id=$RANK_SCOPED_SOURCE_POST" \
  --skip-column-names | tr -d '[:space:]')
[[ "$RANK_SCOPED_UUID" =~ ^[a-f0-9-]{36}$ ]] \
  || fail "Rank Math scoped premise lacks one post UUID: $RANK_SCOPED_UUID"
wp_conf1 post meta update "$RANK_SCOPED_SOURCE_POST" rank_math_description \
  'Scoped Rank Math description 東京 🚀 with exact recovery.' >/dev/null
commit_rank_math_source 'conformance: scoped Rank Math post metadata intent'
RANK_SCOPE_PATH="${CONF_REPO2:-siterepo/conf2}/.tmp-rank-math.scope.json"
RANK_SCOPE_JSON=$(host_wprism conf2 scope --roots="post:$RANK_SCOPED_UUID" \
  --contract --format=json | jq -ce .)
printf '%s\n' "$RANK_SCOPE_JSON" >"$RANK_SCOPE_PATH"
jq -e '
  .format == "wprism-scope-contract/v1" and
  ([.potential_actions[]? | select(
    .manifest == "rank-math" and .source == "provider:rank-math-state/rebuild_all_link_state" and
    .declaration.kind == "provider" and .declaration.provider == "rank-math-state" and
    .declaration.capability == "rebuild_all_link_state"
  )] | length) == 1 and
  any(.potential_providers[]?;
    .id == "rank-math-state" and
    (.potential_action_sources | index("provider:rank-math-state/rebuild_all_link_state")) != null)
' <<<"$RANK_SCOPE_JSON" >/dev/null \
  || fail "Rank Math scope contract did not bind its site-complete provider declaration: $RANK_SCOPE_JSON"
RANK_SCOPED_ACTION_INDEX=$(jq -er '
  .potential_actions[] | select(
    .manifest == "rank-math" and .source == "provider:rank-math-state/rebuild_all_link_state"
  ) | .index
' <<<"$RANK_SCOPE_JSON")
RANK_SCOPED_ACTION_HASH=$(php -r '
  require $argv[1];
  $contract = json_decode(file_get_contents($argv[2]), true, 512, JSON_THROW_ON_ERROR);
  $matches = [];
  foreach (($contract["potential_actions"] ?? []) as $row) {
      $declaration = $row["declaration"] ?? null;
      if (is_array($declaration)
          && ($declaration["manifest"] ?? null) === "rank-math"
          && ($declaration["provider"] ?? null) === "rank-math-state"
          && ($declaration["capability"] ?? null) === "rebuild_all_link_state") {
          $matches[] = $declaration;
      }
  }
  if (count($matches) !== 1) {
      exit(2);
  }
  echo hash("sha256", \WPrism\Canon::encode($matches[0]));
' "$PAIR_SOURCE_ROOT/agent/src/Kernel/Canon.php" "$RANK_SCOPE_PATH") \
  || fail 'Rank Math scope contract provider declaration could not be canonically hashed'
[[ "$RANK_SCOPED_ACTION_INDEX" =~ ^[0-9]+$ && "$RANK_SCOPED_ACTION_HASH" =~ ^[a-f0-9]{64}$ ]] \
  || fail 'Rank Math scope contract published a malformed provider action identity'
capture_wprism_json_success RANK_SCOPED_PLAN 'Rank Math scoped provider plan' wp_conf2 wprism plan --repo=/siterepo \
  --scope-contract=/siterepo/.tmp-rank-math.scope.json --format=json
require_wprism_answered 'Rank Math scoped provider plan' json "$RANK_SCOPED_PLAN"
jq -e --arg hash "$RANK_SCOPED_ACTION_HASH" --argjson index "$RANK_SCOPED_ACTION_INDEX" '
  .format == "wprism-scoped-plan/v1" and
  .selected_actions == [{declaration_hash:$hash,index:$index,manifest:"rank-math"}]
' <<<"$RANK_SCOPED_PLAN" >/dev/null \
  || fail "Rank Math scoped plan did not bind its site-complete provider: $RANK_SCOPED_PLAN"
capture_wprism_json_checked RANK_SCOPED_APPLY 'Rank Math scoped provider apply' assert_wprism_apply_ready \
  wp_conf2 wprism apply --repo=/siterepo \
  --scope-contract=/siterepo/.tmp-rank-math.scope.json \
  --default-author=admin --format=json
require_wprism_answered 'Rank Math scoped provider apply' json "$RANK_SCOPED_APPLY"
jq -e '
  .format == "wprism-scoped-apply-result/v1" and .canary == "clean" and
  .verification.result == "pass" and .scoped_receipt.phase == "complete" and
  (.actions | length) == 1 and all(.actions[];
    (keys | sort) == ["capability_digest","format","kind","operation_hash","receipt_hash","source_hash","status","verified"] and
    .format == "wprism-scoped-effect-receipt/v1" and .kind == "provider" and
    .status == "verified" and .verified == true and
    ([.capability_digest,.operation_hash,.receipt_hash,.source_hash] |
      all(.[]; test("^[a-f0-9]{64}$"))))
' <<<"$RANK_SCOPED_APPLY" >/dev/null \
  || fail "Rank Math scoped provider did not return one hash-only verified receipt: $RANK_SCOPED_APPLY"
RANK_SCOPED_TARGET_POST=$(wp_conf2 db query \
  "SELECT local_id FROM wp_wprism_map WHERE uuid='$RANK_SCOPED_UUID' AND id_kind='post'" \
  --skip-column-names | tr -d '[:space:]')
require_fixture_ids RANK_SCOPED_TARGET_POST
[ "$(wp_conf2 post meta get "$RANK_SCOPED_TARGET_POST" rank_math_description)" = \
  'Scoped Rank Math description 東京 🚀 with exact recovery.' ] \
  || fail 'Rank Math scoped apply did not converge its one selected authored field'
RANK_SCOPED_OBSERVED=$(observe_rank_math conf2 "${CONF_REPO2:-siterepo/conf2}" wp_conf2)
jq -e '
  (.links | length) == 2 and
  (.post_counts.internal_link_count | tonumber) == 1 and
  (.post_counts.external_link_count | tonumber) == 1 and
  (.hub_counts.incoming_link_count | tonumber) == 1
' <<<"$RANK_SCOPED_OBSERVED" >/dev/null \
  || fail "Rank Math scoped apply did not retain the exact native link projection: $RANK_SCOPED_OBSERVED"
capture_wprism_json_checked RANK_SCOPED_REPLAY 'Rank Math scoped terminal replay' assert_wprism_json_required_environment \
  wp_conf2 wprism apply --repo=/siterepo \
  --scope-contract=/siterepo/.tmp-rank-math.scope.json \
  --default-author=admin --format=json
require_wprism_answered 'Rank Math scoped terminal replay' json "$RANK_SCOPED_REPLAY"
jq -e '
  .format == "wprism-scoped-apply-result/v1" and .replayed == true and .canary == "clean" and
  .applied == 0 and (.actions | type) == "array" and (.actions | length) == 0 and
  has("verification") and .verification == null
' <<<"$RANK_SCOPED_REPLAY" >/dev/null \
  || fail "Rank Math scoped terminal replay repeated work: $RANK_SCOPED_REPLAY"
rm -f "$RANK_SCOPE_PATH"
pass 'scoped post apply negotiates, executes, verifies and terminally replays the site-complete Rank Math provider'

if [ "${RANK_MATH_BOUNDARY_ONLY:-0}" = 1 ]; then
  pass "Rank Math $RANK_MATH_EXPECTED_VERSION exact boundary passed native, provider, HTTP, runtime-isolation and no-op checks"
  return 0
fi

# Dynamic custom-schema metadata is intentionally outside the contract because
# its shortcode sibling refers to a physical postmeta row id. Capture must
# refuse atomically rather than carrying half a schema feature.
SOURCE_POST=$(jq -r '.ids.post' <<<"$SOURCE")
wp_conf1 post meta update "$SOURCE_POST" rank_math_schema_Article \
  '{"@type":"Article","headline":"unsupported custom schema"}' >/dev/null
SCHEMA_STATE_BEFORE=$(find "${CONF_REPO1:-siterepo/conf1}/state" -type f -exec shasum -a 256 {} + | shasum -a 256 | awk '{print $1}')
capture_wprism_json_refusal SCHEMA_OUT 'Rank Math unsupported custom schema capture' \
  wp_conf1 wprism capture --repo=/siterepo --format=json
grep -Fq 'rank_math_schema_Article' <<<"$SCHEMA_OUT" \
  || fail "Rank Math custom schema did not refuse at its exact key: $SCHEMA_OUT"
[ "$(find "${CONF_REPO1:-siterepo/conf1}/state" -type f -exec shasum -a 256 {} + | shasum -a 256 | awk '{print $1}')" = "$SCHEMA_STATE_BEFORE" ] \
  || fail 'Rank Math custom-schema refusal partially published canonical state'
wp_conf1 post meta delete "$SOURCE_POST" rank_math_schema_Article >/dev/null
wp_conf1 wprism capture --repo=/siterepo --out=/siterepo/.tmp-rank-math-schema-restored >/dev/null
diff -r "${CONF_REPO1:-siterepo/conf1}/state" "${CONF_REPO1:-siterepo/conf1}/.tmp-rank-math-schema-restored" \
  || fail 'Rank Math source did not restore after custom-schema refusal'
rm -rf "${CONF_REPO1:-siterepo/conf1}/.tmp-rank-math-schema-restored"
pass 'custom schema refuses atomically at the exact unsupported physical key'

# The reviewed natural identity is singular. A second target row with the same
# sources must refuse before row order can choose a behavioral winner.
DUPLICATE_ID=$(wp_conf2 eval '
global $wpdb;
$table=$wpdb->prefix."rank_math_redirections";
$row=$wpdb->get_row("SELECT * FROM `$table` ORDER BY id LIMIT 1",ARRAY_A);
unset($row["id"]);
$row["url_to"]=home_url("/duplicate-must-refuse/");
if ($wpdb->insert($table,$row) !== 1) throw new RuntimeException("duplicate fixture insert failed");
echo (int)$wpdb->insert_id;
')
require_fixture_ids DUPLICATE_ID
DUPLICATE_RC=0
DUPLICATE_OUT=$(wp_conf2 wprism plan --repo=/siterepo 2>&1) || DUPLICATE_RC=$?
require_wprism_answered 'Rank Math duplicate natural identity plan' human "$DUPLICATE_OUT"
[ "$DUPLICATE_RC" -ne 0 ] \
  && grep -Fq 'identity contradiction:' <<<"$DUPLICATE_OUT" \
  && grep -Fq "(rank_math_redirection) is already bound to local id $TARGET_REDIR; refusing to rebind it to $DUPLICATE_ID" <<<"$DUPLICATE_OUT" \
  || fail "Rank Math duplicate natural identity was guessed by row order: $DUPLICATE_OUT"
wp_conf2 db query "DELETE FROM wp_rank_math_redirections WHERE id=$DUPLICATE_ID" >/dev/null
pass 'duplicate redirection identity refuses without choosing a mutable-row winner'

# Unsupported row deletion is a capture-time capability boundary. Restore the
# row through the native writer and prove the prior repository stays exact.
SOURCE_DELETE_REDIR=$(wp_conf1 db query \
  "SELECT id FROM wp_rank_math_redirections ORDER BY id LIMIT 1" --skip-column-names | tr -d '[:space:]')
require_fixture_ids SOURCE_DELETE_REDIR
wp_conf1 eval "RankMath\\Redirections\\DB::delete([(int)$SOURCE_DELETE_REDIR]);" >/dev/null
capture_wprism_json_refusal DELETE_OUT 'Rank Math unsupported redirection deletion capture' \
  wp_conf1 wprism capture --repo=/siterepo --format=json
jq -e '
  .format == "wprism-command-refusal/v1" and .reason_code == "unsupported_deletion" and
  any(.diagnostics[]?; .code == "unsupported_deletion" and .surface == "table:rank_math_redirections")
' <<<"$DELETE_OUT" >/dev/null \
  || fail "Rank Math redirection deletion did not refuse at its exact selector: $DELETE_OUT"
wp_conf1 eval '
$redirection=RankMath\Redirections\Redirection::from([
  "sources"=>[["pattern"=>"rank-math-old","comparison"=>"exact","ignore"=>""]],
  "url_to"=>home_url("/rank-math-hub/?from=redirect"),"header_code"=>"302","status"=>"active"
]);
if (!$redirection->save()) throw new RuntimeException("redirection restoration failed");
' >/dev/null
wp_conf1 wprism capture --repo=/siterepo --out=/siterepo/.tmp-rank-math-delete-restored >/dev/null
diff -r "${CONF_REPO1:-siterepo/conf1}/state" "${CONF_REPO1:-siterepo/conf1}/.tmp-rank-math-delete-restored" \
  || fail 'Rank Math source did not restore after unsupported deletion refusal'
rm -rf "${CONF_REPO1:-siterepo/conf1}/.tmp-rank-math-delete-restored"
pass 'unsupported redirection deletion publishes no tombstone and restores byte-identically'

# Provider callback authority is injected only on the target. Direct authored
# state commits first; revision advance waits for the verified child receipt,
# and removing only the callback lets the retained intent retry.
wp_conf1 eval '
$post=get_page_by_path("rank-math-article",OBJECT,"post");
$post->post_content=str_replace("external proof","provider recovery proof",$post->post_content);
wp_update_post($post);
' >/dev/null
commit_rank_math_source 'conformance: Rank Math provider recovery intent'
$COMPOSE exec -T --user root wp2 sh -c \
  'printf "%s\n" "<?php" "add_filter(\"rank_math/links/content\", static fn(\$content) => \$content, 10, 2);" > /var/www/html/wp-content/mu-plugins/wprism-rank-math-provider-fault.php'
FAIL_REV_BEFORE=$(wp_conf2 db query "SELECT v FROM wp_wprism_kv WHERE k='applied_revision'" --skip-column-names | tr -d '[:space:]')
require_observed_nonempty 'Rank Math applied revision before provider fault' "$FAIL_REV_BEFORE"
FAILURE_RC=0
FAILURE_OUT=$(wp_conf2 wprism apply --repo=/siterepo --default-author=admin 2>&1) || FAILURE_RC=$?
require_wprism_answered 'Rank Math injected provider callback refusal' human "$FAILURE_OUT"
[ "$FAILURE_RC" -ne 0 ] && grep -Fq "provider 'rank-math-state' capability 'rebuild_all_link_state' failed" <<<"$FAILURE_OUT" \
  || fail "Rank Math unreviewed callback did not refuse in its provider: $FAILURE_OUT"
TARGET_POST=$(jq -r '.ids.post' <<<"$(observe_rank_math conf2 "${CONF_REPO2:-siterepo/conf2}" wp_conf2)")
grep -Fq 'provider recovery proof' <<<"$(wp_conf2 post get "$TARGET_POST" --field=post_content)" \
  || fail 'Rank Math provider refusal lost post-commit authored state needed for retry'
[ "$(wp_conf2 db query "SELECT v FROM wp_wprism_kv WHERE k='applied_revision'" --skip-column-names | tr -d '[:space:]')" = "$FAIL_REV_BEFORE" ] \
  || fail 'Rank Math provider refusal advanced applied_revision before verified link effects'
[ "$(wp_conf2 eval 'echo null === \WPrism\Ledger::kv_get("apply_in_progress") ? "clear" : "retained";')" = retained ] \
  || fail 'Rank Math provider refusal did not retain retry authority'
[ "$(wp_conf2 option get wprism_rank_math_target_neighbor)" = target-neighbor-must-survive ] \
  || fail 'Rank Math provider refusal crossed the target option boundary'
$COMPOSE exec -T --user root wp2 rm -f /var/www/html/wp-content/mu-plugins/wprism-rank-math-provider-fault.php
capture_wprism_json_checked RETRY 'Rank Math retry after callback repair' assert_wprism_apply_ready \
  wp_conf2 wprism apply --repo=/siterepo --default-author=admin --format=json
require_wprism_answered 'Rank Math retry after callback repair' json "$RETRY"
jq -e '
  .canary == "clean" and .verification.result == "pass" and .applied >= 1 and
  any(.actions[]?; .source == "provider:rank-math-state/rebuild_all_link_state" and
    .verified == true and .after.link_count >= 2)
' <<<"$RETRY" >/dev/null || fail "Rank Math provider retry did not consume retained intent: $RETRY"
pass 'unreviewed callback failure retains post-commit intent/retry authority and converges after repairing only the callback'

# Competing metadata edits are a generic typed conflict. Nothing, including
# derived link state or target credentials, moves until explicit authority.
wp_conf1 post meta update "$SOURCE_POST" rank_math_title 'Repository competing Rank Math title 東京 🚀' >/dev/null
commit_rank_math_source 'conformance: competing Rank Math metadata intent'
TARGET_POST=$(jq -r '.ids.post' <<<"$(observe_rank_math conf2 "${CONF_REPO2:-siterepo/conf2}" wp_conf2)")
wp_conf2 post meta update "$TARGET_POST" rank_math_title 'Target competing Rank Math title' >/dev/null
CONFLICT_BEFORE=$(observe_rank_math conf2 "${CONF_REPO2:-siterepo/conf2}" wp_conf2 | shasum -a 256 | awk '{print $1}')
capture_wprism_json_checked CONFLICT_PLAN 'Rank Math competing metadata plan' assert_wprism_json_required_environment \
  wp_conf2 wprism plan --repo=/siterepo --format=json
require_wprism_answered 'Rank Math competing metadata plan' json "$CONFLICT_PLAN"
jq -e '(.conflict | length) > 0' <<<"$CONFLICT_PLAN" >/dev/null \
  || fail "Rank Math competing metadata did not produce a typed conflict: $CONFLICT_PLAN"
CONFLICT_RC=0
CONFLICT_OUT=$(wp_conf2 wprism apply --repo=/siterepo --default-author=admin 2>&1) || CONFLICT_RC=$?
require_wprism_answered 'Rank Math unforced competing metadata apply' human "$CONFLICT_OUT"
[ "$CONFLICT_RC" -ne 0 ] && grep -qi conflict <<<"$CONFLICT_OUT" \
  || fail "Rank Math unforced conflict did not refuse: $CONFLICT_OUT"
[ "$(observe_rank_math conf2 "${CONF_REPO2:-siterepo/conf2}" wp_conf2 | shasum -a 256 | awk '{print $1}')" = "$CONFLICT_BEFORE" ] \
  || fail 'Rank Math unforced conflict partially mutated authored, derived or target-owned state'
capture_wprism_json_checked FORCED 'Rank Math forced metadata conflict' assert_wprism_apply_ready \
  wp_conf2 wprism apply --repo=/siterepo --force-theirs --default-author=admin --format=json
require_wprism_answered 'Rank Math forced metadata conflict' json "$FORCED"
jq -e '
  .canary == "clean" and .verification.result == "pass" and .plan.conflict > 0 and
  any(.actions[]?; .source == "provider:rank-math-state/rebuild_all_link_state" and .verified == true)
' <<<"$FORCED" >/dev/null || fail "Rank Math forced repository intent did not verify: $FORCED"
[ "$(wp_conf2 post meta get "$TARGET_POST" rank_math_title)" = 'Repository competing Rank Math title 東京 🚀' ] \
  || fail 'Rank Math forced conflict did not converge the native meta value'
pass 'competing SEO metadata refuses atomically and explicit repository authority preserves target boundaries'

# An absent authored table with durable identity/state is data loss, never a
# virgin schema opportunity. Keep a database-matched copy, remove the live
# table, and prove host preflight refuses before lease/checkpoint/provider work.
AUTHORED_ROWS_BEFORE=$(wp_conf2 db query \
  'SELECT COUNT(*) FROM wp_rank_math_redirections' --skip-column-names | tr -d '[:space:]')
require_observed_nonempty 'Rank Math authored table baseline' "$AUTHORED_ROWS_BEFORE"
wp_conf2 db query 'DROP TABLE IF EXISTS wp_rank_math_redirections_wprism_loss_backup' >/dev/null
wp_conf2 db query \
  'CREATE TABLE wp_rank_math_redirections_wprism_loss_backup LIKE wp_rank_math_redirections' >/dev/null
wp_conf2 db query \
  'INSERT INTO wp_rank_math_redirections_wprism_loss_backup SELECT * FROM wp_rank_math_redirections' >/dev/null
wp_conf2 db query 'DROP TABLE wp_rank_math_redirections' >/dev/null
AUTHORED_LOSS_PRIVATE_BASELINE=$($COMPOSE run --rm -T \
  --volume "$PAIR_SOURCE_ROOT/sandbox/tests/lib/PrivateRefusalReceipt.php:/wprism-test/PrivateRefusalReceipt.php:ro" \
  --entrypoint php cli2 \
  /var/www/html/wp-content/mu-plugins/adapter-packages/rank-math/fixtures/private-refusal-evidence.php \
  /wprism-test/PrivateRefusalReceipt.php snapshot schema-loss /siterepo/.wprism/refusals) \
  || fail 'Rank Math authored loss could not snapshot private refusal evidence as the target CLI identity'
require_observed_nonempty 'Rank Math authored schema private refusal baseline' "$AUTHORED_LOSS_PRIVATE_BASELINE"
AUTHORED_LOSS_RC=0
AUTHORED_LOSS_OUT=$(host_wprism conf2 deploy 2>&1) || AUTHORED_LOSS_RC=$?
require_wprism_answered 'Rank Math authored schema loss refusal' human "$AUTHORED_LOSS_OUT"
[ "$AUTHORED_LOSS_RC" -ne 0 ] \
  && grep -Fq '"format":"wprism-command-refusal/v1"' <<<"$AUTHORED_LOSS_OUT" \
  && grep -Fq '"command":"schema-status"' <<<"$AUTHORED_LOSS_OUT" \
  && grep -Fq '"reason_code":"schema_status_failed"' <<<"$AUTHORED_LOSS_OUT" \
  && grep -Fq '"details_redacted":true' <<<"$AUTHORED_LOSS_OUT" \
  && grep -Fq "the target's schema-status refusal was redacted" <<<"$AUTHORED_LOSS_OUT" \
  && grep -Fq '.wprism/refusals/' <<<"$AUTHORED_LOSS_OUT" \
  && ! grep -Eq 'durable (identity|canonical) history remains' <<<"$AUTHORED_LOSS_OUT" \
  || fail "Rank Math authored table loss was treated as empty schema: $AUTHORED_LOSS_OUT"
AUTHORED_LOSS_PRIVATE_RECEIPT=$($COMPOSE run --rm -T \
  --volume "$PAIR_SOURCE_ROOT/sandbox/tests/lib/PrivateRefusalReceipt.php:/wprism-test/PrivateRefusalReceipt.php:ro" \
  --entrypoint php cli2 \
  /var/www/html/wp-content/mu-plugins/adapter-packages/rank-math/fixtures/private-refusal-evidence.php \
  /wprism-test/PrivateRefusalReceipt.php verify schema-loss /siterepo/.wprism/refusals "$AUTHORED_LOSS_PRIVATE_BASELINE") \
  || fail 'Rank Math authored loss could not verify private refusal evidence as the target CLI identity'
require_observed_nonempty 'Rank Math authored schema private refusal receipt' "$AUTHORED_LOSS_PRIVATE_RECEIPT"
[ "$AUTHORED_LOSS_PRIVATE_RECEIPT" = \
  '{"command":"schema-status","format":"wprism-rank-math-private-refusal-check/v1","new_records":1,"root_message_sha256":"818de0fac4852aff4fb77b52978db22331055a6a041ebb963a97e4759e223389","verified":true}' ] \
  || fail "Rank Math authored loss returned a malformed private-evidence receipt: $AUTHORED_LOSS_PRIVATE_RECEIPT"
AUTHORED_LOSS_PHASES=$(sed -n 's/^deploy phase: //p' <<<"$AUTHORED_LOSS_OUT" | paste -sd ' ' -)
[ "$AUTHORED_LOSS_PHASES" = 'compile lifecycle-status schema-status' ] \
  || fail "Rank Math authored loss crossed the read-only host preflight: $AUTHORED_LOSS_OUT"
[ ! -e "${CONF_REPO2:-siterepo/conf2}/.wprism/control/provider-settlement-intent.json" ] \
  || fail 'Rank Math authored loss published provider authority before its preflight refusal'
[ "$(wp_conf2 eval 'echo null === \WPrism\Ledger::kv_get("schema_settlement_in_progress") ? "clear" : "retained";')" = clear ] \
  || fail 'Rank Math authored loss published schema mutation authority before its preflight refusal'
wp_conf2 db query \
  'RENAME TABLE wp_rank_math_redirections_wprism_loss_backup TO wp_rank_math_redirections' >/dev/null
[ "$(wp_conf2 db query 'SELECT COUNT(*) FROM wp_rank_math_redirections' --skip-column-names | tr -d '[:space:]')" = "$AUTHORED_ROWS_BEFORE" ] \
  || fail 'Rank Math database-matched authored table restore lost rows'
pass 'authored table loss refuses before checkpoint/provider work and accepts only the database-matched table restore'

# A missing derived cache with no canonical history is a legitimate schema
# preparation. Keep the authored redirection table intact and establish the
# module-disabled baseline through capture -> commit -> apply: rank_math_modules
# is authored state, so changing only the target would correctly become
# ordinary drift after recovery. The tracked baseline prevents activation from
# pre-creating the cache before the provider-only refusal after the checkpoint.
wp_conf1 eval '
$modules=array_values((array)get_option("rank_math_modules",[]));
if (!in_array("redirections",$modules,true)) {
    throw new RuntimeException("Rank Math source fixture lacks its authored redirections module");
}
$modules=array_values(array_filter($modules,static fn($m)=>$m!=="redirections"));
update_option("rank_math_modules",$modules);
' >/dev/null
commit_rank_math_source 'conformance: schema recovery module baseline'
capture_wprism_json_checked SCHEMA_BASELINE_APPLY 'Rank Math schema recovery baseline apply' assert_wprism_apply_ready \
  wp_conf2 wprism apply --repo=/siterepo --default-author=admin --format=json
jq -e '.canary == "clean" and .verification.result == "pass" and .plan.update >= 1' \
  <<<"$SCHEMA_BASELINE_APPLY" >/dev/null \
  || fail "Rank Math schema recovery baseline did not converge through apply: $SCHEMA_BASELINE_APPLY"
wp_conf2 plugin deactivate seo-by-rank-math >/dev/null
wp_conf2 db query 'DROP TABLE wp_rank_math_redirections_cache' >/dev/null
SCHEMA_RECOVERY_BEFORE=$(rank_math_recovery_state conf2)
$COMPOSE exec -T --user root wp2 sh -c \
  'printf "%s\n" "<?php" "add_filter(\"rank_math/admin/create_tables\", static fn(\$modules) => \$modules);" > /var/www/html/wp-content/mu-plugins/wprism-rank-math-schema-fault.php'
SCHEMA_FAILURE_RC=0
SCHEMA_FAILURE_OUT=$(host_wprism conf2 deploy 2>&1) || SCHEMA_FAILURE_RC=$?
require_wprism_answered 'Rank Math injected host schema provider refusal' human "$SCHEMA_FAILURE_OUT"
[ "$SCHEMA_FAILURE_RC" -ne 0 ] \
  && grep -Fq "provider 'rank-math-state' capability 'prepare_schema' failed" <<<"$SCHEMA_FAILURE_OUT" \
  || fail "Rank Math unreviewed schema callback did not refuse in its provider: $SCHEMA_FAILURE_OUT"
SCHEMA_FAILURE_PHASES=$(sed -n 's/^deploy phase: //p' <<<"$SCHEMA_FAILURE_OUT" | paste -sd ' ' -)
[ "$SCHEMA_FAILURE_PHASES" = 'compile lifecycle-status schema-status promotion-begin checkpoint provider-settlement-begin lifecycle-retire lifecycle-activate schema-settle' ] \
  || fail "Rank Math schema refusal crossed or skipped an ordered host phase: $SCHEMA_FAILURE_OUT"
[ "$(wp_conf2 eval 'echo null === \WPrism\Ledger::kv_get("schema_settlement_in_progress") ? "clear" : "retained";')" = retained ] \
  || fail 'Rank Math schema provider refusal did not retain exact recovery intent'
[ -f "${CONF_REPO2:-siterepo/conf2}/.wprism/control/provider-settlement-intent.json" ] \
  || fail 'Rank Math schema provider refusal did not retain database-external provider debt'
[ "$(wp_conf2 db query "SHOW TABLES LIKE 'wp_rank_math_redirections_cache'" --skip-column-names | tr -d '[:space:]')" = '' ] \
  || fail 'Rank Math schema provider refusal crossed its pre-DDL boundary'
[ "$(wp_conf2 db query 'SELECT COUNT(*) FROM wp_rank_math_redirections' --skip-column-names | tr -d '[:space:]')" = "$AUTHORED_ROWS_BEFORE" ] \
  || fail 'Rank Math schema provider refusal changed authored redirection rows'
SCHEMA_RECOVERY_ID=$(sed -n \
  's/.*wprism recover [^ ]* --restore=\([^ ]*\) --writers-excluded.*/\1/p' \
  <<<"$SCHEMA_FAILURE_OUT" | tail -1)
[[ "$SCHEMA_RECOVERY_ID" =~ ^deploy-[A-Za-z0-9._-]+$ ]] \
  || fail "Rank Math failed deploy did not publish one retained recovery id: $SCHEMA_FAILURE_OUT"
SCHEMA_RECOVERY_RC=0
SCHEMA_RECOVERY_OUT=$(host_wprism conf2 recover --restore="$SCHEMA_RECOVERY_ID" \
  --writers-excluded --operator-directed 2>&1) || SCHEMA_RECOVERY_RC=$?
[ "$SCHEMA_RECOVERY_RC" -eq 0 ] \
  && grep -q "^recover ${CONF_PAIR:-conf}2: recovered$" <<<"$SCHEMA_RECOVERY_OUT" \
  || fail "Rank Math failed schema phase did not restore through the recovery product: $SCHEMA_RECOVERY_OUT"
[ "$(wp_conf2 eval 'echo null === \WPrism\Ledger::kv_get("schema_settlement_in_progress") ? "clear" : "retained";')" = clear ] \
  || fail 'Rank Math checkpoint recovery did not clear the imported schema intent'
[ ! -e "${CONF_REPO2:-siterepo/conf2}/.wprism/control/provider-settlement-intent.json" ] \
  || fail 'Rank Math checkpoint recovery did not clear exact external provider debt'
SCHEMA_RECOVERY_AFTER=$(rank_math_recovery_state conf2)
jq -en --argjson before "$SCHEMA_RECOVERY_BEFORE" --argjson after "$SCHEMA_RECOVERY_AFTER" \
  '$after == $before' >/dev/null \
  || fail "Rank Math schema recovery did not restore the exact pre-checkpoint plugin database state: $SCHEMA_RECOVERY_AFTER"
$COMPOSE exec -T --user root wp2 rm -f /var/www/html/wp-content/mu-plugins/wprism-rank-math-schema-fault.php

# Retry from the same recovered state with valid schema authority but a real
# Rank Math link callback. Schema settlement must complete before lifecycle
# settlement refuses, and the durable external intent must say exactly which
# phases finished. Recovery then has to restore the same pre-checkpoint oracle
# a second time, including undoing activation and the newly created cache.
$COMPOSE exec -T --user root wp2 sh -c \
  'printf "%s\n" "<?php" "add_filter(\"rank_math/links/content\", static fn(\$content) => \$content, 10, 2);" > /var/www/html/wp-content/mu-plugins/wprism-rank-math-lifecycle-fault.php'
LIFECYCLE_FAILURE_RC=0
LIFECYCLE_FAILURE_OUT=$(host_wprism conf2 deploy 2>&1) || LIFECYCLE_FAILURE_RC=$?
require_wprism_answered 'Rank Math injected lifecycle-settlement refusal' human "$LIFECYCLE_FAILURE_OUT"
[ "$LIFECYCLE_FAILURE_RC" -ne 0 ] \
  && grep -Fq "provider 'rank-math-state' capability 'rebuild_all_link_state' failed" <<<"$LIFECYCLE_FAILURE_OUT" \
  || fail "Rank Math lifecycle provider fault did not refuse: $LIFECYCLE_FAILURE_OUT"
LIFECYCLE_FAILURE_PHASES=$(sed -n 's/^deploy phase: //p' <<<"$LIFECYCLE_FAILURE_OUT" | paste -sd ' ' -)
[ "$LIFECYCLE_FAILURE_PHASES" = 'compile lifecycle-status schema-status promotion-begin checkpoint provider-settlement-begin lifecycle-retire lifecycle-activate schema-settle lifecycle-settle' ] \
  || fail "Rank Math lifecycle refusal did not occur after ordered schema progress: $LIFECYCLE_FAILURE_OUT"
wp_conf2 plugin is-active seo-by-rank-math >/dev/null \
  || fail 'Rank Math lifecycle fault did not occur after plugin activation'
[ "$(wp_conf2 db query "SHOW TABLES LIKE 'wp_rank_math_redirections_cache'" --skip-column-names | tr -d '[:space:]')" = wp_rank_math_redirections_cache ] \
  || fail 'Rank Math lifecycle fault did not occur after schema settlement created the derived cache'
[ "$(wp_conf2 eval 'echo null === \WPrism\Ledger::kv_get("schema_settlement_in_progress") ? "clear" : "retained";')" = clear ] \
  || fail 'Rank Math completed schema settlement left database-local schema debt'
jq -e '
  .phases == ["lifecycle-retire","lifecycle-activate","schema-settle","lifecycle-settle"] and
  .completed_phases == ["lifecycle-retire","lifecycle-activate","schema-settle"]
' "${CONF_REPO2:-siterepo/conf2}/.wprism/control/provider-settlement-intent.json" >/dev/null \
  || fail 'Rank Math lifecycle fault did not retain the exact completed-phase prefix'
LIFECYCLE_RECOVERY_ID=$(sed -n \
  's/.*wprism recover [^ ]* --restore=\([^ ]*\) --writers-excluded.*/\1/p' \
  <<<"$LIFECYCLE_FAILURE_OUT" | tail -1)
[[ "$LIFECYCLE_RECOVERY_ID" =~ ^deploy-[A-Za-z0-9._-]+$ ]] \
  || fail "Rank Math lifecycle failure did not publish one retained recovery id: $LIFECYCLE_FAILURE_OUT"
LIFECYCLE_RECOVERY_RC=0
LIFECYCLE_RECOVERY_OUT=$(host_wprism conf2 recover --restore="$LIFECYCLE_RECOVERY_ID" \
  --writers-excluded --operator-directed 2>&1) || LIFECYCLE_RECOVERY_RC=$?
[ "$LIFECYCLE_RECOVERY_RC" -eq 0 ] \
  && grep -q "^recover ${CONF_PAIR:-conf}2: recovered$" <<<"$LIFECYCLE_RECOVERY_OUT" \
  || fail "Rank Math lifecycle-settlement failure did not recover: $LIFECYCLE_RECOVERY_OUT"
LIFECYCLE_RECOVERY_AFTER=$(rank_math_recovery_state conf2)
jq -en --argjson before "$SCHEMA_RECOVERY_BEFORE" --argjson after "$LIFECYCLE_RECOVERY_AFTER" \
  '$after == $before' >/dev/null \
  || fail "Rank Math lifecycle recovery did not restore activation/modules/schema/authored/derived/counter/neighbor state exactly: $LIFECYCLE_RECOVERY_AFTER"
wp_conf2 plugin is-active seo-by-rank-math >/dev/null 2>&1 \
  && fail 'Rank Math lifecycle recovery did not restore the inactive pre-checkpoint state'
[ "$(wp_conf2 db query "SHOW TABLES LIKE 'wp_rank_math_redirections_cache'" --skip-column-names | tr -d '[:space:]')" = '' ] \
  || fail 'Rank Math lifecycle recovery did not remove schema created after the checkpoint'
[ ! -e "${CONF_REPO2:-siterepo/conf2}/.wprism/control/provider-settlement-intent.json" ] \
  || fail 'Rank Math lifecycle recovery did not clear external provider debt'
RECOVERED_NOTIFICATION=$(wp_conf2 --skip-plugins=seo-by-rank-math eval '
echo wp_json_encode(get_option("rank_math_notifications", null));
')
require_observed_nonempty 'Rank Math recovered notification before repaired activation' "$RECOVERED_NOTIFICATION"
jq -e '
  . == [{
    message:"Target runtime notification must survive",
    options:{id:"wprism-target-runtime",classes:"rank-math-notice",type:"success",screen:"any",capability:""}
  }]
' <<<"$RECOVERED_NOTIFICATION" >/dev/null \
  || fail "Rank Math recovery did not restore the pre-activation target notification: $RECOVERED_NOTIFICATION"
$COMPOSE exec -T --user root wp2 rm -f /var/www/html/wp-content/mu-plugins/wprism-rank-math-lifecycle-fault.php

REDEPLOY_RC=0
REDEPLOY=$(host_wprism conf2 deploy 2>&1) || REDEPLOY_RC=$?
[ "$REDEPLOY_RC" -eq 0 ] && grep -q '^deploy complete:' <<<"$REDEPLOY" \
  || fail "Rank Math repaired host deploy failed: $REDEPLOY"
REDEPLOY_PHASES=$(sed -n 's/^deploy phase: //p' <<<"$REDEPLOY" | paste -sd ' ' -)
[ "$REDEPLOY_PHASES" = 'compile lifecycle-status schema-status promotion-begin checkpoint provider-settlement-begin lifecycle-retire lifecycle-activate schema-settle lifecycle-settle provider-settlement-complete' ] \
  || fail "Rank Math repaired deploy violated its checkpointed lifecycle/provider phase order: $REDEPLOY"
[ "$(wp_conf2 eval 'echo null === \WPrism\Ledger::kv_get("schema_settlement_in_progress") ? "clear" : "retained";')" = clear ] \
  || fail 'Rank Math repaired schema retry did not clear exact recovery intent'
wp_conf2 plugin is-active seo-by-rank-math >/dev/null \
  || fail 'Rank Math deploy did not reactivate exact plugin code'
ACTIVATED_NOTIFICATION=$(wp_conf2 --skip-plugins=seo-by-rank-math eval '
echo wp_json_encode(get_option("rank_math_notifications", null));
')
require_observed_nonempty 'Rank Math native activation notification outcome' "$ACTIVATED_NOTIFICATION"
jq -e 'type == "array" and length == 0' <<<"$ACTIVATED_NOTIFICATION" >/dev/null \
  || fail "Rank Math native activation did not advance its notification queue to empty: $ACTIVATED_NOTIFICATION"
LIFECYCLE=$(observe_rank_math conf2 "${CONF_REPO2:-siterepo/conf2}" wp_conf2)
jq -e '
  ([.schema[].present] | all) and (.links | length) == 2 and
  (.post_counts.internal_link_count | tonumber) == 1 and
  (.post_counts.external_link_count | tonumber) == 1 and
  (.hub_counts.incoming_link_count | tonumber) == 1 and .redirection_count == 1
' <<<"$LIFECYCLE" >/dev/null \
  || fail "Rank Math ordered lifecycle settlement changed authored rows or missed derived repair: $LIFECYCLE"
wp_conf1 eval '
$modules=array_values((array)get_option("rank_math_modules",[]));
if (in_array("redirections",$modules,true)) {
    throw new RuntimeException("Rank Math recovery baseline unexpectedly retained redirections");
}
$offset=array_search("rich-snippet",$modules,true);
if (!is_int($offset)) {
    throw new RuntimeException("Rank Math recovery baseline lacks the rich-snippet insertion anchor");
}
array_splice($modules,$offset,0,["redirections"]);
update_option("rank_math_modules",$modules);
' >/dev/null
commit_rank_math_source 'conformance: restore Rank Math module intent after recovery'
capture_wprism_json_checked RESTORE 'Rank Math canonical verification after lifecycle recovery' assert_wprism_apply_ready \
  wp_conf2 wprism apply --repo=/siterepo --default-author=admin --format=json
jq -e '.canary == "clean" and .verification.result == "pass" and .plan.update >= 1' <<<"$RESTORE" >/dev/null \
  || fail "Rank Math canonical module state did not restore after lifecycle recovery: $RESTORE"
[ "$(wp_conf2 option get wprism_rank_math_target_neighbor)" = target-neighbor-must-survive ] \
  || fail 'Rank Math lifecycle recovery crossed the target option boundary'
pass 'schema and lifecycle-provider failures retain exact ordered debt; product recovery restores the full pre-checkpoint state before repaired settlement'

# Two real apply processes contend for one final title. At least one succeeds;
# the other may only succeed after observing no work or refuse at the named lock.
wp_conf1 post meta update "$SOURCE_POST" rank_math_title 'Concurrent Rank Math intent 東京 🚀' >/dev/null
commit_rank_math_source 'conformance: concurrent Rank Math apply intent'
CONCURRENT_A="${CONF_REPO2:-siterepo/conf2}/.tmp-rank-math-concurrent-a.log"
CONCURRENT_B="${CONF_REPO2:-siterepo/conf2}/.tmp-rank-math-concurrent-b.log"
set +e
wp_conf2 wprism apply --repo=/siterepo --default-author=admin >"$CONCURRENT_A" 2>&1 & PID_A=$!
wp_conf2 wprism apply --repo=/siterepo --default-author=admin >"$CONCURRENT_B" 2>&1 & PID_B=$!
wait "$PID_A"; RC_A=$?
wait "$PID_B"; RC_B=$?
set -e
if [ "$RC_A" -ne 0 ] && [ "$RC_B" -ne 0 ]; then
  fail "both competing Rank Math applies failed: A=$(cat "$CONCURRENT_A") B=$(cat "$CONCURRENT_B")"
fi
for result in A B; do
  eval "rc=\$RC_$result"; eval "log=\$CONCURRENT_$result"
  if [ "$rc" -eq 0 ]; then
    assert_no_php_runtime_diagnostics 'successful competing Rank Math apply' "$(cat "$log")"
    assert_wprism_required_environment 'successful competing Rank Math apply' human "$(cat "$log")"
    grep -q 'canary clean' "$log" \
      || fail "successful competing Rank Math apply lacked a clean canary: $(cat "$log")"
  else
    grep -Eqi 'lock|another apply|in progress|promotion' "$log" \
      || fail "competing Rank Math apply failed outside the named lock: $(cat "$log")"
  fi
done
rm -f "$CONCURRENT_A" "$CONCURRENT_B"
TARGET_FINAL=$(observe_rank_math conf2 "${CONF_REPO2:-siterepo/conf2}" wp_conf2)
jq -e '
  .post.title == "Concurrent Rank Math intent 東京 🚀" and .post.processed == true and
  (.links | length) == 2 and .target_owned.instant_key == "target-indexnow-credential-must-survive" and
  .target_owned.indexnow_log[0].message == "target runtime submission history" and
  .target_owned.notifications == [] and
  .target_owned.neighbor == "target-neighbor-must-survive"
' <<<"$TARGET_FINAL" >/dev/null || fail "Rank Math concurrent apply did not converge exact native state: $TARGET_FINAL"

# Rank Math's native uninstall is deliberately non-destructive unless its own
# opt-in filter is supplied. Prove that real default path, the missing-code
# refusal, and an exact digest-bound reinstall through host lifecycle. A fresh
# runtime notification must survive retirement, uninstall and missing code;
# the next native late activation must reproduce the measured empty-queue
# outcome before state apply preserves that exact plugin-owned result.
LIFECYCLE_NOTIFICATION_SEED=$(wp_conf2 eval '
\RankMath\Helper::add_notification(
    "Target runtime notification must survive until native reactivation",
    ["id" => "wprism-target-lifecycle-runtime", "type" => "success", "screen" => "any", "capability" => ""]
);
echo "rank-math-target-lifecycle-notification-seeded";
')
require_observed_nonempty 'Rank Math pre-uninstall runtime notification seed' "$LIFECYCLE_NOTIFICATION_SEED"
[ "$(printf '%s\n' "$LIFECYCLE_NOTIFICATION_SEED" | awk 'NF { line=$0 } END { print line }')" = \
    'rank-math-target-lifecycle-notification-seeded' ] \
  || fail "Rank Math pre-uninstall notification seed did not complete: $LIFECYCLE_NOTIFICATION_SEED"
LIFECYCLE_NOTIFICATION=$(wp_conf2 --skip-plugins=seo-by-rank-math eval '
echo wp_json_encode(get_option("rank_math_notifications", null));
')
require_observed_nonempty 'Rank Math pre-uninstall runtime notification premise' "$LIFECYCLE_NOTIFICATION"
jq -e '
  . == [{
    message:"Target runtime notification must survive until native reactivation",
    options:{id:"wprism-target-lifecycle-runtime",classes:"rank-math-notice",type:"success",screen:"any",capability:""}
  }]
' <<<"$LIFECYCLE_NOTIFICATION" >/dev/null \
  || fail "Rank Math pre-uninstall runtime notification was not durable: $LIFECYCLE_NOTIFICATION"
wp_conf2 plugin deactivate seo-by-rank-math >/dev/null
RETIRED_NOTIFICATION=$(wp_conf2 --skip-plugins=seo-by-rank-math eval '
echo wp_json_encode(get_option("rank_math_notifications", null));
')
require_observed_nonempty 'Rank Math retired runtime notification' "$RETIRED_NOTIFICATION"
jq -e --argjson expected "$LIFECYCLE_NOTIFICATION" '. == $expected' \
  <<<"$RETIRED_NOTIFICATION" >/dev/null \
  || fail "Rank Math native retirement changed its target runtime notification: $RETIRED_NOTIFICATION"
UNINSTALL_DATA_BEFORE=$(rank_math_recovery_state conf2)
wp_conf2 plugin uninstall seo-by-rank-math >/dev/null
wp_conf2 plugin is-installed seo-by-rank-math >/dev/null 2>&1 \
  && fail 'Rank Math native uninstall left plugin code installed'
UNINSTALL_DATA_AFTER=$(rank_math_recovery_state conf2)
jq -en --argjson before "$UNINSTALL_DATA_BEFORE" --argjson after "$UNINSTALL_DATA_AFTER" \
  '$after == $before' >/dev/null \
  || fail "Rank Math default uninstall changed options, metadata, tables, counters, or neighbor state: $UNINSTALL_DATA_AFTER"
MISSING_CODE_RC=0
MISSING_CODE_PRIVATE_BASELINE=$($COMPOSE run --rm -T \
  --volume "$PAIR_SOURCE_ROOT/sandbox/tests/lib/PrivateRefusalReceipt.php:/wprism-test/PrivateRefusalReceipt.php:ro" \
  --entrypoint php cli2 \
  /var/www/html/wp-content/mu-plugins/adapter-packages/rank-math/fixtures/private-refusal-evidence.php \
  /wprism-test/PrivateRefusalReceipt.php snapshot missing-code /siterepo/.wprism/refusals) \
  || fail 'Rank Math missing-code refusal could not snapshot private evidence as the target CLI identity'
require_observed_nonempty 'Rank Math missing-code private refusal baseline' "$MISSING_CODE_PRIVATE_BASELINE"
MISSING_CODE_OUT=$(host_wprism conf2 deploy 2>&1) || MISSING_CODE_RC=$?
require_wprism_answered 'Rank Math deploy with code absent' human "$MISSING_CODE_OUT"
[ "$MISSING_CODE_RC" -ne 0 ] \
  && grep -Fq '"format":"wprism-command-refusal/v1"' <<<"$MISSING_CODE_OUT" \
  && grep -Fq '"command":"lifecycle-status"' <<<"$MISSING_CODE_OUT" \
  && grep -Fq '"reason_code":"lifecycle_status_failed"' <<<"$MISSING_CODE_OUT" \
  && grep -Fq '"details_redacted":true' <<<"$MISSING_CODE_OUT" \
  && grep -Fq "the target's lifecycle-status refusal was redacted" <<<"$MISSING_CODE_OUT" \
  && grep -Fq '.wprism/refusals/' <<<"$MISSING_CODE_OUT" \
  && ! grep -Eq 'code_mismatch|missing_in_code|is not installed|active_plugins' <<<"$MISSING_CODE_OUT" \
  || fail "missing Rank Math code did not refuse before lifecycle mutation: $MISSING_CODE_OUT"
MISSING_CODE_PRIVATE_RECEIPT=$($COMPOSE run --rm -T \
  --volume "$PAIR_SOURCE_ROOT/sandbox/tests/lib/PrivateRefusalReceipt.php:/wprism-test/PrivateRefusalReceipt.php:ro" \
  --entrypoint php cli2 \
  /var/www/html/wp-content/mu-plugins/adapter-packages/rank-math/fixtures/private-refusal-evidence.php \
  /wprism-test/PrivateRefusalReceipt.php verify missing-code /siterepo/.wprism/refusals "$MISSING_CODE_PRIVATE_BASELINE") \
  || fail 'Rank Math missing-code refusal could not verify private evidence as the target CLI identity'
require_observed_nonempty 'Rank Math missing-code private refusal receipt' "$MISSING_CODE_PRIVATE_RECEIPT"
[ "$MISSING_CODE_PRIVATE_RECEIPT" = \
  '{"command":"lifecycle-status","format":"wprism-rank-math-private-refusal-check/v1","new_records":1,"root_message_sha256":"9145307bebd452be68d85b17d6bcd43b48921710b4a3f4fd9ffd0010d7690d93","verified":true}' ] \
  || fail "Rank Math missing code returned a malformed private-evidence receipt: $MISSING_CODE_PRIVATE_RECEIPT"
MISSING_CODE_PHASES=$(sed -n 's/^deploy phase: //p' <<<"$MISSING_CODE_OUT" | paste -sd ' ' -)
[ "$MISSING_CODE_PHASES" = 'compile lifecycle-status' ] \
  || fail "Rank Math missing-code refusal crossed its read-only lifecycle preflight: $MISSING_CODE_OUT"
[ ! -e "${CONF_REPO2:-siterepo/conf2}/.wprism/control/provider-settlement-intent.json" ] \
  || fail 'Rank Math missing-code refusal published provider authority before lifecycle preflight'
MISSING_CODE_AFTER=$(rank_math_recovery_state conf2)
jq -en --argjson before "$UNINSTALL_DATA_BEFORE" --argjson after "$MISSING_CODE_AFTER" \
  '$after == $before' >/dev/null \
  || fail 'Rank Math missing-code refusal mutated retained plugin data'
RANK_MATH_REINSTALL_SHA=$(artifact_library_jq -er --arg version "$RANK_MATH_EXPECTED_VERSION" '
  .plugins["seo-by-rank-math"][$version].sha256
  | select(type == "string" and test("^[0-9a-f]{64}$"))
') || fail "artifact library lacks an exact Rank Math $RANK_MATH_EXPECTED_VERSION reinstall digest"
RANK_MATH_REINSTALL="/artifacts-cache/plugin-seo-by-rank-math-${RANK_MATH_EXPECTED_VERSION}-${RANK_MATH_REINSTALL_SHA}.zip"
[ "$(wp_conf2 eval "echo hash_file('sha256', '$RANK_MATH_REINSTALL');")" = \
  "$RANK_MATH_REINSTALL_SHA" ] \
  || fail 'cached Rank Math reinstall artifact digest moved'
wp_conf2 plugin install "$RANK_MATH_REINSTALL" --force >/dev/null
[ "$(wp_conf2 plugin get seo-by-rank-math --field=version)" = "$RANK_MATH_EXPECTED_VERSION" ] \
  || fail 'Rank Math exact reinstall reported the wrong version'
REINSTALL_PENDING_NOTIFICATION=$(wp_conf2 --skip-plugins=seo-by-rank-math eval '
echo wp_json_encode(get_option("rank_math_notifications", null));
')
require_observed_nonempty 'Rank Math reinstalled-inactive runtime notification' "$REINSTALL_PENDING_NOTIFICATION"
jq -e --argjson expected "$LIFECYCLE_NOTIFICATION" '. == $expected' \
  <<<"$REINSTALL_PENDING_NOTIFICATION" >/dev/null \
  || fail "Rank Math code reinstall changed target runtime state before activation: $REINSTALL_PENDING_NOTIFICATION"
REINSTALL_DEPLOY=$(host_wprism conf2 deploy 2>&1) \
  || fail "Rank Math exact-reinstall host deploy failed: $REINSTALL_DEPLOY"
REINSTALL_PHASES=$(sed -n 's/^deploy phase: //p' <<<"$REINSTALL_DEPLOY" | paste -sd ' ' -)
[ "$REINSTALL_PHASES" = 'compile lifecycle-status schema-status promotion-begin checkpoint provider-settlement-begin lifecycle-retire lifecycle-activate schema-settle lifecycle-settle provider-settlement-complete' ] \
  || fail "Rank Math exact reinstall did not traverse the full checkpointed lifecycle: $REINSTALL_DEPLOY"
wp_conf2 plugin is-active seo-by-rank-math >/dev/null \
  || fail 'Rank Math exact reinstall was not activated by host lifecycle'
REINSTALL_ACTIVATED_NOTIFICATION=$(wp_conf2 --skip-plugins=seo-by-rank-math eval '
echo wp_json_encode(get_option("rank_math_notifications", null));
')
require_observed_nonempty 'Rank Math reinstall activation notification outcome' "$REINSTALL_ACTIVATED_NOTIFICATION"
jq -e 'type == "array" and length == 0' <<<"$REINSTALL_ACTIVATED_NOTIFICATION" >/dev/null \
  || fail "Rank Math reinstall activation did not reproduce its empty notification outcome: $REINSTALL_ACTIVATED_NOTIFICATION"
capture_wprism_json_checked REINSTALL_APPLY 'Rank Math apply after exact reinstall' assert_wprism_apply_ready \
  wp_conf2 wprism apply --repo=/siterepo --default-author=admin --format=json
require_wprism_answered 'Rank Math apply after exact reinstall' json "$REINSTALL_APPLY"
jq -e '.canary == "clean" and .verification.result == "pass"' <<<"$REINSTALL_APPLY" >/dev/null \
  || fail "Rank Math exact reinstall did not verify canonical state: $REINSTALL_APPLY"
REINSTALLED=$(observe_rank_math conf2 "${CONF_REPO2:-siterepo/conf2}" wp_conf2)
jq -en --argjson before "$TARGET_FINAL" --argjson after "$REINSTALLED" '$after == $before' >/dev/null \
  || fail "Rank Math exact reinstall did not preserve the complete observed native state: $REINSTALLED"
pass 'deactivate/retire, native uninstall residue, missing-code refusal and digest-bound reinstall preserve exact Rank Math behavior'

capture_wprism_json_checked FINAL_PLAN 'Rank Math final zero plan' assert_wprism_json_required_environment \
  wp_conf2 wprism plan --repo=/siterepo --format=json
require_wprism_answered 'Rank Math final zero plan' json "$FINAL_PLAN"
jq -e '([.create,.update,.drift,.conflict,.collision,.delete,.delete_conflict] | map(length) | add) == 0' \
  <<<"$FINAL_PLAN" >/dev/null || fail "Rank Math final plan retained work: $FINAL_PLAN"
wp_conf2 wprism capture --repo=/siterepo --out=/siterepo/.tmp-rank-math-final >/dev/null
diff -r "${CONF_REPO1:-siterepo/conf1}/state" "${CONF_REPO2:-siterepo/conf2}/.tmp-rank-math-final" \
  || fail 'Rank Math final state did not recapture byte-identically after failure/conflict/lifecycle/concurrency exercises'
rm -rf "${CONF_REPO2:-siterepo/conf2}/.tmp-rank-math-final"
pass 'concurrent apply serializes at the promotion lock and the final native/canonical state is exact'
