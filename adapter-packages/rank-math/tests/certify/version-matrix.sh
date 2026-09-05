seed_rank_math_content() {
  wp_conf1() { wp1 "$@"; }
  local CONF_REPO1="siterepo/${PAIR}1"
  local CONF1_PORT="$PORT1"
  local RANK_MATH_EXPECTED_VERSION="$RANK_MATH_VERSION"
  . "$(dirname "${BASH_SOURCE[0]}")/../conformance/seed.sh"
  unset -f wp_conf1
}

postdeploy_rank_math_content() {
  wp_conf1() { wp1 "$@"; }
  wp_conf2() { wp2 "$@"; }
  local CONF_REPO1="siterepo/${PAIR}1"
  local CONF_REPO2="siterepo/${PAIR}2"
  . "$(dirname "${BASH_SOURCE[0]}")/../conformance/postdeploy.sh"
  unset -f wp_conf1 wp_conf2
}

check_rank_math_boundary_content() {
  wp_conf1() { wp1 "$@"; }
  wp_conf2() { wp2 "$@"; }
  local CONF_REPO1="siterepo/${PAIR}1"
  local CONF_REPO2="siterepo/${PAIR}2"
  local CONF1_PORT="$PORT1"
  local CONF2_PORT="$PORT2"
  local RANK_MATH_BOUNDARY_ONLY=1
  local RANK_MATH_EXPECTED_VERSION="$RANK_MATH_VERSION"
  local APPLY_JSON="$RANK_MATH_BOUNDARY_APPLY_JSON"
  . "$(dirname "${BASH_SOURCE[0]}")/../conformance/check.sh"
  unset -f wp_conf1 wp_conf2
}

rank_math_site_policy() { # <repository>
  local repository="$1"
  printf '%s\n' \
    '{' \
    '  "manifests": ["core", "rank-math"],' \
    '  "policy": {' \
    '    "options": {},' \
    '    "post_meta": {},' \
    '    "post_types": ["post", "page", "attachment"],' \
    '    "taxonomies": ["category", "post_tag"]' \
    '  },' \
    '  "spec_version": 3' \
    '}' > "$repository/site.wprism.json"
}

rank_math_native_state_hash() { # <wp1|wp2>
  local side="$1"
  "$side" eval '
global $wpdb;
// wpdb read failures look like absent tables or empty row sets; its next read
// clears last_error. A zero-write witness must checkpoint each read before
// another statement, and never turn a failed encoding into hash("").
$read = static function (callable $query, string $label) use ($wpdb) {
    $suppressed = $wpdb->suppress_errors(true);
    $wpdb->last_error = "";
    try {
        $result = $query();
        if ((string) $wpdb->last_error !== "") {
            throw new RuntimeException("incomplete read");
        }
        return $result;
    } catch (Throwable) {
        throw new RuntimeException("Rank Math native observation could not read " . $label);
    } finally {
        $wpdb->suppress_errors($suppressed);
    }
};
$readRows = static function (string $sql, string $label, array $columns = []) use ($wpdb, $read): array {
    $rows = $read(static fn() => $wpdb->get_results($sql, ARRAY_A), $label);
    if (!is_array($rows) || !array_is_list($rows)) {
        throw new RuntimeException("Rank Math native observation has an invalid row set for " . $label);
    }
    foreach ($rows as $row) {
        if (!is_array($row) || $row === [] || array_is_list($row)) {
            throw new RuntimeException("Rank Math native observation has an invalid row for " . $label);
        }
        $columns = $columns === [] ? array_keys($row) : $columns;
        if (array_keys($row) !== $columns) {
            throw new RuntimeException("Rank Math native observation has inconsistent columns for " . $label);
        }
        foreach ($row as $key => $value) {
            if (!is_string($key) || $key === "" || ($value !== null && !is_string($value))) {
                throw new RuntimeException("Rank Math native observation has an invalid column value for " . $label);
            }
        }
    }
    return $rows;
};
$tables = [];
foreach (["rank_math_internal_links", "rank_math_internal_meta", "rank_math_redirections", "rank_math_redirections_cache"] as $suffix) {
    $table = $wpdb->prefix . $suffix;
    $found = $read(static fn() => $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $wpdb->esc_like($table))),
        "table presence " . $suffix);
    if ($found !== null && (!is_string($found) || !hash_equals($table, $found))) {
        throw new RuntimeException("Rank Math native observation has an unexpected table-presence answer");
    }
    $tables[$suffix] = $found === null ? null : $readRows("SELECT * FROM `$table` ORDER BY 1", $suffix);
}
$active = $read(static fn() => get_option("active_plugins", []), "active plugins");
if (!is_array($active) || !array_is_list($active)
    || array_filter($active, static fn($plugin): bool => !is_string($plugin)) !== []) {
    throw new RuntimeException("Rank Math native observation has an invalid active-plugin inventory");
}
$payload = [
    "active" => $active,
    "options" => $readRows(
        "SELECT option_name,option_value,autoload FROM {$wpdb->options} " .
        "WHERE option_name LIKE '\''rank\\_math%'\'' ESCAPE '\''\\\\'\'' ORDER BY option_name",
        "options", ["option_name", "option_value", "autoload"]
    ),
    "postmeta" => $readRows(
        "SELECT post_id,meta_key,meta_value FROM {$wpdb->postmeta} " .
        "WHERE meta_key LIKE '\''rank\\_math%'\'' ESCAPE '\''\\\\'\'' ORDER BY post_id,meta_key,meta_id",
        "postmeta", ["post_id", "meta_key", "meta_value"]
    ),
    "tables" => $tables,
    "termmeta" => $readRows(
        "SELECT term_id,meta_key,meta_value FROM {$wpdb->termmeta} " .
        "WHERE meta_key LIKE '\''rank\\_math%'\'' ESCAPE '\''\\\\'\'' ORDER BY term_id,meta_key,meta_id",
        "termmeta", ["term_id", "meta_key", "meta_value"]
    ),
];
$json = wp_json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if (!is_string($json) || $json === "") {
    throw new RuntimeException("Rank Math native observation could not encode its complete payload");
}
echo hash("sha256", $json);
'
}

rank_math_module_state() { # <wp1|wp2>
  local side="$1"
  "$side" eval '
echo wp_json_encode([
    "active_modules" => array_values(RankMath\Helper::get_active_modules()),
    "modules" => array_values(array_unique(array_map("strval", (array) get_option("rank_math_modules", [])))),
    "version" => defined("RANK_MATH_VERSION") ? RANK_MATH_VERSION : null,
], JSON_UNESCAPED_SLASHES);
' | awk 'NF { line=$0 } END { print line }'
}

# The ordinary boundary check deliberately authors one scoped metadata change
# and drives real redirect traffic. Upgrade/downgrade are continuations of that
# state, so their oracle must be read-only and compare against the immediately
# preceding target rather than replaying the fresh-target fixture assumptions.
assert_rank_math_transition_content() { # <label> <version> <source> <target-before> <target-after> <apply-receipt>
  local label="$1" version="$2" source="$3" target_before="$4" target_after="$5" receipt="$6"
  require_observed_nonempty "$label source observation" "$source"
  require_observed_nonempty "$label target baseline" "$target_before"
  require_observed_nonempty "$label target observation" "$target_after"
  require_observed_nonempty "$label apply receipt" "$receipt"
  printf '%s\n' "$source" "$target_before" "$target_after" "$receipt" | jq -es \
    --arg version "$version" --arg port "$CONF2_PORT" '
    length == 4 and
    .[0] as $source | .[1] as $before | .[2] as $target | .[3] as $receipt |
    ($source | type) == "object" and ($before | type) == "object" and
    ($target | type) == "object" and ($receipt | type) == "object" and
    ["attachment","category","hub","post","secondary","tag"] as $identity_keys |
    ["link-counter","redirections","rich-snippet","image-seo"] as $modules |
    ($source.version == $version and $target.version == $version) and
    ($source.modules == $modules and $target.modules == $modules) and
    (($source.setup.configured | tostring) == "1" and
      ($source.setup.registration_skip | tostring) == "1" and
      ($target.setup.configured | tostring) == "1" and
      ($target.setup.registration_skip | tostring) == "1") and
    ($before.post.description == "Scoped Rank Math description 東京 🚀 with exact recovery." and
      $source.post.description == $before.post.description and
      $target.post.description == $source.post.description) and
    ([ $identity_keys[] as $key |
      ($source.ids[$key] | type) == "number" and ($target.ids[$key] | type) == "number" and
      $source.ids[$key] > 0 and $target.ids[$key] > 0 and
      $source.ids[$key] != $target.ids[$key] and $target.ids[$key] == $before.ids[$key]
    ] | all) and
    ($target.options.breadcrumbs_home_label == $source.options.breadcrumbs_home_label and
      $target.options.plain_large_bytes == $source.options.plain_large_bytes and
      $target.options.plain_nested == $source.options.plain_nested and
      $target.options.homepage_image_id == $target.ids.attachment and
      $target.options.logo_id == $target.ids.attachment and
      $target.options.open_graph_image_id == $target.ids.attachment and
      $target.options.local_seo_about_page == $target.ids.hub and
      $target.options.local_seo_contact_page == $target.ids.post) and
    ($target.post.title == $source.post.title and
      ($target.post.canonical | startswith("http://localhost:" + $port + "/rank-math-canonical/")) and
      $target.post.facebook_image_id == $target.ids.attachment and
      $target.post.primary_category == $target.ids.category and $target.post.processed == true) and
    ($target.term.description == $source.term.description and
      $target.term.facebook_image_id == $target.ids.attachment and
      $target.term.title == $source.term.title) and
    ($target.redirection_count == 1 and $target.redirection.id == $before.redirection.id and
      $target.redirection.sources_shape == $source.redirection.sources_shape and
      $target.redirection.header_code == $source.redirection.header_code and
      $target.redirection.status == $source.redirection.status and
      ($target.redirection.url_to | startswith("http://localhost:" + $port + "/rank-math-hub/")) and
      $target.redirection.hits == $before.redirection.hits and
      $target.redirection.created == $before.redirection.created and
      $target.redirection.updated == $before.redirection.updated and
      $target.redirection.last_accessed == $before.redirection.last_accessed and
      $target.redirection_cache_count == $before.redirection_cache_count) and
    ($target.links | length) == 2 and
    ([ $target.links[].type ] | sort) == ["external","internal"] and
    ([ $target.links[] | select(.type == "internal") ] | length) == 1 and
    ([ $target.links[] | select(.type == "internal") ][0].post_id | tonumber) == $target.ids.post and
    ([ $target.links[] | select(.type == "internal") ][0].target_post_id | tonumber) == $target.ids.hub and
    ([ $target.links[] | select(.type == "external") ] | length) == 1 and
    ([ $target.links[] | select(.type == "external") ][0].post_id | tonumber) == $target.ids.post and
    ([ $target.links[] | select(.type == "external") ][0].target_post_id | tonumber) == 0 and
    (($target.post_counts.internal_link_count | tonumber) == 1 and
      ($target.post_counts.external_link_count | tonumber) == 1 and
      ($target.post_counts.incoming_link_count | tonumber) == 0 and
      ($target.hub_counts.internal_link_count | tonumber) == 0 and
      ($target.hub_counts.external_link_count | tonumber) == 0 and
      ($target.hub_counts.incoming_link_count | tonumber) == 1) and
    ($target.schema == {
      rank_math_internal_links:{present:true,columns:["id","url","post_id","target_post_id","type"]},
      rank_math_internal_meta:{present:true,columns:["object_id","internal_link_count","external_link_count","incoming_link_count"]},
      rank_math_redirections:{present:true,columns:["id","sources","url_to","header_code","hits","status","created","updated","last_accessed"]},
      rank_math_redirections_cache:{present:true,columns:["id","from_url","redirection_id","object_id","object_type","is_redirected"]}
    } and $target.schema == $before.schema) and
    ($target.target_owned == $before.target_owned) and
    ($receipt.canary == "clean" and $receipt.verification.result == "pass" and
      ($receipt.actions | type) == "array" and
      ([ $receipt.actions[]?.source | select(startswith("provider:rank-math-state/")) ] | sort | unique) ==
        ["provider:rank-math-state/rebuild_all_link_state"] and
      any($receipt.actions[]?;
        .source == "provider:rank-math-state/rebuild_all_link_state" and .verified == true and
        .before.enabled == true and .after.enabled == true and
        ([.before.link_count,.before.meta_count,.before.marker_count,
          .after.link_count,.after.meta_count,.after.marker_count] |
          all(.[]; type == "number" and . >= 0)) and
        .after.link_count == 2 and .after.meta_count == 2 and .after.marker_count == 2 and
        ([.before.link_hash,.after.link_hash,.before.meta_hash,.after.meta_hash,
          .before.marker_hash,.after.marker_hash,.before.dependency_hash,.after.dependency_hash,
          .before.dependency_state_hash,.after.dependency_state_hash] |
          all(.[]; type == "string" and test("^[a-f0-9]{64}$"))) and
        .before.dependency_state_hash == .after.dependency_state_hash))
  ' >/dev/null || fail "$label did not preserve the evolved authored, native, derived, and target-runtime state"
}

rank_math_private_evidence() { # <snapshot|verify> <profile> <directory> [baseline]
  "${PAIR_COMPOSE[@]}" run --rm -T \
    --volume "$PAIR_SOURCE_ROOT/sandbox/tests/lib/PrivateRefusalReceipt.php:/wprism-test/PrivateRefusalReceipt.php:ro" \
    --entrypoint php cli2 \
    /var/www/html/wp-content/mu-plugins/adapter-packages/rank-math/fixtures/private-refusal-evidence.php \
    /wprism-test/PrivateRefusalReceipt.php "$@"
}

assert_rank_math_baseline_only_deploy() { # <label> <captured-output>
  local label="$1" output="$2"
  require_wprism_answered "$label" human "$output"
  grep -q '^deploy phase: lifecycle-status$' <<<"$output" \
    && grep -q '^deploy phase: schema-status$' <<<"$output" \
    && grep -q '^deploy phase: code-baseline-accept$' <<<"$output" \
    && grep -q '^deploy complete: code-baseline-accept; no code descriptor$' <<<"$output" \
    || fail "$label did not select the exact baseline-only path: $output"
  [ "$(grep -c 'FORCED past code_drift' <<<"$output")" -eq 1 ] \
    || fail "$label did not report exactly one forced code-drift finding: $output"
  if grep -Eq '^deploy phase: (promotion-begin|checkpoint|lifecycle-retire|lifecycle-activate|schema-settle|lifecycle-settle)$' <<<"$output"; then
    fail "$label invented lifecycle, provider, or checkpoint work: $output"
  fi
}

assert_rank_math_zero_code_drift() { # <wp1|wp2> <label>
  local side="$1" label="$2" plan
  plan=$("$side" wprism plan --repo=/siterepo --format=json | tail -1)
  jq -e '.code_drift == []' <<<"$plan" >/dev/null \
    || fail "$label left code drift after an accepted baseline: $plan"
}

VMATRIX_PLUGIN_SLUG=seo-by-rank-math

version_matrix_preflight() {
  [ -n "${WPRISM_EXPECTED_SOURCE_SHA:-}" ] \
    || fail 'Rank Math version-matrix evidence requires WPRISM_EXPECTED_SOURCE_SHA'
  export WPRISM_SOURCE_ROOT
  WPRISM_SOURCE_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../../.." && pwd -P)"
}

version_matrix_reset_after_delete() {
  local cli="$1"
  # Rank Math retains authored options and all four adapter-owned tables on
  # ordinary deletion. A patch case must prove its own installer/lifecycle,
  # not inherit schema or module choices from the preceding release.
  "$cli" db query "
    DROP TABLE IF EXISTS
      wp_rank_math_internal_links,
      wp_rank_math_internal_meta,
      wp_rank_math_redirections,
      wp_rank_math_redirections_cache;
    DELETE FROM wp_options
      WHERE option_name LIKE 'rank\\_math%' ESCAPE '\\\\'
         OR option_name LIKE 'rank-math-%';
    DELETE FROM wp_postmeta WHERE meta_key LIKE 'rank\\_math%' ESCAPE '\\\\';
    DELETE FROM wp_termmeta WHERE meta_key LIKE 'rank\\_math%' ESCAPE '\\\\';
  " >/dev/null
}

version_matrix_workflow() {
VMATRIX_CASES=$((VMATRIX_CASES + 1))
for RANK_MATH_VERSION in 1.0.277 1.0.277.1 1.0.277.2; do
  say "boundary: seo-by-rank-math $RANK_MATH_VERSION"

  reset_env wp1
  reset_env wp2
  reset_case_repositories

  say "fetch + verify seo-by-rank-math $RANK_MATH_VERSION (digest-checked official artifact)"
  RANK_MATH_ARTIFACT_1=$(fetch_artifact seo-by-rank-math "$RANK_MATH_VERSION" cli1)
  RANK_MATH_ARTIFACT_2=$(fetch_artifact seo-by-rank-math "$RANK_MATH_VERSION" cli2)
  wp1 plugin install "$RANK_MATH_ARTIFACT_1" --activate >/dev/null
  INSTALLED_1=$(wp1 plugin get seo-by-rank-math --field=version)
  [ "$INSTALLED_1" = "$RANK_MATH_VERSION" ] \
    || fail "side 1 installed version mismatch: expected $RANK_MATH_VERSION, got $INSTALLED_1"

  rank_math_site_policy "siterepo/${PAIR}1"
  cp site-repo.gitignore.template "siterepo/${PAIR}1/.gitignore"
  "${GIT1[@]}" init -q -b main
  "${GIT1[@]}" remote add origin "../origin-$PAIR.git"
  "${GIT1[@]}" add -A
  "${GIT1[@]}" commit -qm "policy: Rank Math $RANK_MATH_VERSION exact-patch certification"
  "${GIT1[@]}" push -qu origin main

  seed_rank_math_content
  wp1 wprism capture --repo=/siterepo
  wp1 wprism lint --repo=/siterepo
  "${GIT1[@]}" add -A
  "${GIT1[@]}" commit -qm "capture: Rank Math $RANK_MATH_VERSION portable SEO and redirection state"
  "${GIT1[@]}" push -q origin main

  clone_case_target
  wp2 plugin install "$RANK_MATH_ARTIFACT_2" >/dev/null
  INSTALLED_2=$(wp2 plugin get seo-by-rank-math --field=version)
  require_fixture_values INSTALLED_2
  [ "$INSTALLED_2" = "$RANK_MATH_VERSION" ] \
    || fail "side 2 installed version mismatch: expected $RANK_MATH_VERSION, got $INSTALLED_2"

  PREDEPLOY_STATE=$(rank_math_native_state_hash wp2)
  require_observed_nonempty 'Rank Math virgin-target native baseline' "$PREDEPLOY_STATE"
  PREDEPLOY_PRIVATE_BASELINE=$(rank_math_private_evidence \
    snapshot virgin-schema /siterepo/.wprism/refusals) \
    || fail 'Rank Math virgin-target plan could not snapshot private evidence as the target CLI identity'
  require_observed_nonempty 'Rank Math virgin-target private refusal baseline' "$PREDEPLOY_PRIVATE_BASELINE"
  PREDEPLOY_RC=0
  PREDEPLOY_PLAN=$(wp2 wprism plan --repo=/siterepo --format=json 2>&1) || PREDEPLOY_RC=$?
  require_wprism_answered 'Rank Math virgin-target strict plan' json "$PREDEPLOY_PLAN"
  PREDEPLOY_PLAN_JSON=$(awk 'NF { line=$0 } END { print line }' <<<"$PREDEPLOY_PLAN")
  [ "$PREDEPLOY_RC" -ne 0 ] \
    && jq -e '
      . == {
        format:"wprism-command-refusal/v1",ok:false,command:"plan",
        error:"plan_failed",reason_code:"plan_failed",
        message:"plan refused at an unclassified safety gate",
        remediation:"inspect private operator evidence and target state, then correct the repository, policy, capability, or target-state blocker",
        details_redacted:true,
        diagnostics:[{
          code:"plan_failed",message:"plan refused at an unclassified safety gate",
          remediation:"inspect private operator evidence and target state, then correct the repository, policy, capability, or target-state blocker"
        }]
      }
    ' <<<"$PREDEPLOY_PLAN_JSON" >/dev/null \
    && ! grep -Fq "declared table 'rank_math_" <<<"$PREDEPLOY_PLAN" \
    || fail "Rank Math virgin-target plan did not return its exact redacted refusal: $PREDEPLOY_PLAN"
  PREDEPLOY_PRIVATE_RECEIPT=$(rank_math_private_evidence \
    verify virgin-schema /siterepo/.wprism/refusals "$PREDEPLOY_PRIVATE_BASELINE") \
    || fail 'Rank Math virgin-target plan could not verify private evidence as the target CLI identity'
  require_observed_nonempty 'Rank Math virgin-target private refusal receipt' "$PREDEPLOY_PRIVATE_RECEIPT"
  [ "$PREDEPLOY_PRIVATE_RECEIPT" = \
    '{"command":"plan","format":"wprism-rank-math-private-refusal-check/v1","new_records":1,"root_message_sha256":"4a8208927399b3863b0973d34410b2fd71bfc406d286d10dc14de0b24763ff76","verified":true}' ] \
    || fail "Rank Math virgin-target private evidence is malformed: $PREDEPLOY_PRIVATE_RECEIPT"
  [ "$(rank_math_native_state_hash wp2)" = "$PREDEPLOY_STATE" ] \
    || fail 'Rank Math virgin-target strict-plan refusal mutated plugin state'

  DEPLOY_RC=0
  DEPLOY_OUT=$(host_wprism conf2 deploy 2>&1) || DEPLOY_RC=$?
  [ "$DEPLOY_RC" -eq 0 ] && grep -q '^deploy complete:' <<<"$DEPLOY_OUT" \
    || fail "Rank Math host deploy failed to establish lifecycle/schema: $DEPLOY_OUT"
  grep -q '^deploy phase: schema-settle$' <<<"$DEPLOY_OUT" \
    && grep -q '^deploy phase: lifecycle-settle$' <<<"$DEPLOY_OUT" \
    || fail "Rank Math host deploy omitted an ordered provider phase: $DEPLOY_OUT"
  postdeploy_rank_math_content
  REV=$(git -C "siterepo/${PAIR}2" rev-parse HEAD)
  capture_wprism_json_checked RANK_MATH_BOUNDARY_APPLY_JSON 'Rank Math version-matrix boundary apply' assert_wprism_apply_ready \
    wp2 wprism apply --repo=/siterepo --adopt-by-slug=terms,posts \
      --default-author=admin --revision="$REV" --json
  printf '%s\n' "$RANK_MATH_BOUNDARY_APPLY_JSON" > "$VMATRIX_APPLY_LOG"
  assert_version_matrix_apply_ready
  check_rank_math_boundary_content

  wp2 wprism capture --repo=/siterepo --out=/siterepo/.tmp-rank-math-final
  RANK_MATH_DIFF=$(diff -rq \
    "siterepo/${PAIR}1/state" \
    "siterepo/${PAIR}2/.tmp-rank-math-final" || true)
  rm -rf "siterepo/${PAIR}2/.tmp-rank-math-final"
  [ -z "$RANK_MATH_DIFF" ] \
    || fail "Rank Math $RANK_MATH_VERSION recapture lost byte identity: $RANK_MATH_DIFF"
  pass "Rank Math $RANK_MATH_VERSION preserves native behavior and byte identity"

  if [ "$RANK_MATH_VERSION" = 1.0.277 ]; then
    say 'in-place upgrade: seo-by-rank-math 1.0.277 -> 1.0.277.2 on populated source and target'
    UPGRADE_TARGET_TRANSITION_BEFORE=$(observe_rank_math conf2)
    require_observed_nonempty 'Rank Math pre-upgrade target transition baseline' "$UPGRADE_TARGET_TRANSITION_BEFORE"
    UPGRADE_ARTIFACT_1=$(fetch_artifact seo-by-rank-math 1.0.277.2 cli1)
    UPGRADE_ARTIFACT_2=$(fetch_artifact seo-by-rank-math 1.0.277.2 cli2)
    wp1 plugin install "$UPGRADE_ARTIFACT_1" --force --activate >/dev/null
    [ "$(wp1 plugin get seo-by-rank-math --field=version)" = 1.0.277.2 ] \
      || fail 'Rank Math source upgrade did not install exact 1.0.277.2'
    UPGRADE_SOURCE_BASELINE_BEFORE=$(wp1 eval "echo \\WPrism\\Ledger::kv_get('code_versions');" | tail -1)
    UPGRADE_SOURCE_STATE_BEFORE=$(rank_math_native_state_hash wp1)
    UPGRADE_SOURCE_REFUSE_RC=0
    UPGRADE_SOURCE_REFUSE=$(host_wprism conf1 deploy 2>&1) || UPGRADE_SOURCE_REFUSE_RC=$?
    require_wprism_answered 'Rank Math upgraded source unforced host deploy' human "$UPGRADE_SOURCE_REFUSE"
    [ "$UPGRADE_SOURCE_REFUSE_RC" -ne 0 ] \
      && grep -q 'code_drift' <<<"$UPGRADE_SOURCE_REFUSE" \
      && grep -q '1.0.277' <<<"$UPGRADE_SOURCE_REFUSE" \
      && grep -q '1.0.277.2' <<<"$UPGRADE_SOURCE_REFUSE" \
      || fail "Rank Math upgraded source did not refuse at its exact version witness: $UPGRADE_SOURCE_REFUSE"
    [ "$UPGRADE_SOURCE_BASELINE_BEFORE" = "$(wp1 eval "echo \\WPrism\\Ledger::kv_get('code_versions');" | tail -1)" ] \
      && [ "$UPGRADE_SOURCE_STATE_BEFORE" = "$(rank_math_native_state_hash wp1)" ] \
      || fail 'Rank Math upgraded source refusal mutated its ledger or native plugin state'
    if grep -Eq '^deploy phase: (promotion-begin|checkpoint|code-baseline-accept|lifecycle-retire|lifecycle-activate|schema-settle|lifecycle-settle)$' <<<"$UPGRADE_SOURCE_REFUSE"; then
      fail "Rank Math upgraded source refusal crossed the read-only preflight: $UPGRADE_SOURCE_REFUSE"
    fi
    UPGRADE_SOURCE_DEPLOY=$(host_wprism conf1 deploy --force-code-drift 2>&1) \
      || fail "Rank Math upgraded source host deploy failed: $UPGRADE_SOURCE_DEPLOY"
    assert_rank_math_baseline_only_deploy 'Rank Math upgraded source forced host deploy' "$UPGRADE_SOURCE_DEPLOY"
    assert_rank_math_zero_code_drift wp1 'Rank Math upgraded source forced host deploy'
    UPGRADE_POST=$(jq -r '.post' "siterepo/${PAIR}1/.tmp-rank-math-source.json")
    require_fixture_ids UPGRADE_POST
    wp1 post update "$UPGRADE_POST" --post_title='Rank Math 1.0.277 to 1.0.277.2 東京 🚀' >/dev/null
    wp1 eval '
RankMath\Helper::update_modules(["image-seo" => "on"]);
' >/dev/null
    UPGRADE_SOURCE_MODULES=$(rank_math_module_state wp1)
    require_observed_nonempty 'Rank Math upgrade source native module readiness' "$UPGRADE_SOURCE_MODULES"
    jq -e '
      . == {
        active_modules:["link-counter","redirections","rich-snippet","image-seo"],
        modules:["link-counter","redirections","rich-snippet","image-seo"],
        version:"1.0.277.2"
      }
    ' <<<"$UPGRADE_SOURCE_MODULES" >/dev/null \
      || fail "Rank Math upgrade source modules are not registered and active: $UPGRADE_SOURCE_MODULES"
    UPGRADE_SOURCE_CAPTURE=$(wp1 wprism capture --repo=/siterepo 2>&1) \
      || fail "Rank Math upgraded source capture failed: $UPGRADE_SOURCE_CAPTURE"
    ! grep -q 'did NOT accept it as the new baseline' <<<"$UPGRADE_SOURCE_CAPTURE" \
      || fail "Rank Math upgraded source capture found drift after host acceptance: $UPGRADE_SOURCE_CAPTURE"
    wp1 wprism lint --repo=/siterepo
    "${GIT1[@]}" add -A
    "${GIT1[@]}" commit -qm 'capture: Rank Math 1.0.277 to 1.0.277.2 in-place upgrade'
    "${GIT1[@]}" push -q origin main

    git -C "siterepo/${PAIR}2" pull -q origin main
    wp2 plugin install "$UPGRADE_ARTIFACT_2" --force --activate >/dev/null
    [ "$(wp2 plugin get seo-by-rank-math --field=version)" = 1.0.277.2 ] \
      || fail 'Rank Math target upgrade did not install exact 1.0.277.2'
    UPGRADE_TARGET_BASELINE_BEFORE=$(wp2 eval "echo \\WPrism\\Ledger::kv_get('code_versions');" | tail -1)
    UPGRADE_TARGET_STATE_BEFORE=$(rank_math_native_state_hash wp2)
    UPGRADE_TARGET_REFUSE_RC=0
    UPGRADE_TARGET_REFUSE=$(host_wprism conf2 deploy 2>&1) || UPGRADE_TARGET_REFUSE_RC=$?
    require_wprism_answered 'Rank Math upgraded target unforced host deploy' human "$UPGRADE_TARGET_REFUSE"
    [ "$UPGRADE_TARGET_REFUSE_RC" -ne 0 ] \
      && grep -q 'code_drift' <<<"$UPGRADE_TARGET_REFUSE" \
      && grep -q '1.0.277' <<<"$UPGRADE_TARGET_REFUSE" \
      && grep -q '1.0.277.2' <<<"$UPGRADE_TARGET_REFUSE" \
      || fail "Rank Math upgraded target did not refuse at its exact version witness: $UPGRADE_TARGET_REFUSE"
    [ "$UPGRADE_TARGET_BASELINE_BEFORE" = "$(wp2 eval "echo \\WPrism\\Ledger::kv_get('code_versions');" | tail -1)" ] \
      && [ "$UPGRADE_TARGET_STATE_BEFORE" = "$(rank_math_native_state_hash wp2)" ] \
      || fail 'Rank Math upgraded target refusal mutated its ledger or native plugin state'
    if grep -Eq '^deploy phase: (promotion-begin|checkpoint|code-baseline-accept|lifecycle-retire|lifecycle-activate|schema-settle|lifecycle-settle)$' <<<"$UPGRADE_TARGET_REFUSE"; then
      fail "Rank Math upgraded target refusal crossed the read-only preflight: $UPGRADE_TARGET_REFUSE"
    fi
    UPGRADE_TARGET_DEPLOY=$(host_wprism conf2 deploy --force-code-drift 2>&1) \
      || fail "Rank Math upgraded target host deploy failed: $UPGRADE_TARGET_DEPLOY"
    assert_rank_math_baseline_only_deploy 'Rank Math upgraded target forced host deploy' "$UPGRADE_TARGET_DEPLOY"
    assert_rank_math_zero_code_drift wp2 'Rank Math upgraded target forced host deploy'
    UPGRADE_REV=$(git -C "siterepo/${PAIR}2" rev-parse HEAD)
    capture_wprism_json_checked RANK_MATH_BOUNDARY_APPLY_JSON 'Rank Math version-matrix upgrade apply' assert_wprism_apply_ready \
      wp2 wprism apply --repo=/siterepo --default-author=admin \
        --revision="$UPGRADE_REV" --json
    assert_wprism_apply_ready 'Rank Math version-matrix upgrade apply' "$RANK_MATH_BOUNDARY_APPLY_JSON"
    UPGRADE_TARGET_MODULES=$(rank_math_module_state wp2)
    require_observed_nonempty 'Rank Math upgrade target native module readiness' "$UPGRADE_TARGET_MODULES"
    jq -e '
      . == {
        active_modules:["link-counter","redirections","rich-snippet","image-seo"],
        modules:["link-counter","redirections","rich-snippet","image-seo"],
        version:"1.0.277.2"
      }
    ' <<<"$UPGRADE_TARGET_MODULES" >/dev/null \
      || fail "Rank Math upgrade target modules are not registered and active: $UPGRADE_TARGET_MODULES"
    RANK_MATH_VERSION=1.0.277.2
    UPGRADE_SOURCE_TRANSITION=$(observe_rank_math conf1)
    UPGRADE_TARGET_TRANSITION=$(observe_rank_math conf2)
    assert_rank_math_transition_content 'Rank Math 1.0.277 to 1.0.277.2 transition' \
      "$RANK_MATH_VERSION" "$UPGRADE_SOURCE_TRANSITION" "$UPGRADE_TARGET_TRANSITION_BEFORE" \
      "$UPGRADE_TARGET_TRANSITION" "$RANK_MATH_BOUNDARY_APPLY_JSON"
    RANK_MATH_VERSION=1.0.277

    UPGRADED_TITLE=$(wp2 post list --post_type=post --name=rank-math-article --field=post_title)
    [ "$UPGRADED_TITLE" = 'Rank Math 1.0.277 to 1.0.277.2 東京 🚀' ] \
      || fail "Rank Math upgrade did not consume state authored after upgrade: $UPGRADED_TITLE"
    wp2 wprism capture --repo=/siterepo --out=/siterepo/.tmp-rank-math-upgraded-final
    UPGRADE_DIFF=$(diff -rq \
      "siterepo/${PAIR}1/state" \
      "siterepo/${PAIR}2/.tmp-rank-math-upgraded-final" || true)
    rm -rf "siterepo/${PAIR}2/.tmp-rank-math-upgraded-final"
    [ -z "$UPGRADE_DIFF" ] \
      || fail "Rank Math 1.0.277 -> 1.0.277.2 upgrade lost byte identity: $UPGRADE_DIFF"
    pass 'Rank Math 1.0.277 -> 1.0.277.2 preserves native behavior, target identities, projections and byte identity'

    say 'in-range downgrade: seo-by-rank-math 1.0.277.2 -> 1.0.277.1 on populated source and target'
    DOWNGRADE_TARGET_TRANSITION_BEFORE=$(observe_rank_math conf2)
    require_observed_nonempty 'Rank Math pre-downgrade target transition baseline' "$DOWNGRADE_TARGET_TRANSITION_BEFORE"
    DOWNGRADE_ARTIFACT_1=$(fetch_artifact seo-by-rank-math 1.0.277.1 cli1)
    DOWNGRADE_ARTIFACT_2=$(fetch_artifact seo-by-rank-math 1.0.277.1 cli2)
    wp1 plugin install "$DOWNGRADE_ARTIFACT_1" --force --activate >/dev/null
    wp2 plugin install "$DOWNGRADE_ARTIFACT_2" --force --activate >/dev/null
    [ "$(wp1 plugin get seo-by-rank-math --field=version)" = 1.0.277.1 ] \
      && [ "$(wp2 plugin get seo-by-rank-math --field=version)" = 1.0.277.1 ] \
      || fail 'Rank Math in-range downgrade did not install exact 1.0.277.1 on both environments'

    DOWNGRADE_BEFORE=$(rank_math_native_state_hash wp2)
    require_observed_nonempty 'Rank Math downgrade target baseline' "$DOWNGRADE_BEFORE"
    DOWNGRADE_PLAN_RC=0
    DOWNGRADE_PLAN_OUT=$(wp2 wprism plan --repo=/siterepo --format=json 2>&1) || DOWNGRADE_PLAN_RC=$?
    require_wprism_answered 'Rank Math 1.0.277.2 to 1.0.277.1 downgrade plan' json "$DOWNGRADE_PLAN_OUT"
    [ "$DOWNGRADE_PLAN_RC" -eq 0 ] \
      || fail "Rank Math in-range downgrade plan did not report its code drift: $DOWNGRADE_PLAN_OUT"
    DOWNGRADE_PLAN=$(awk 'NF { line=$0 } END { print line }' <<<"$DOWNGRADE_PLAN_OUT")
    jq -e '
      (.code_drift | length) == 1 and
      .code_drift[0].plugin == "seo-by-rank-math/rank-math.php" and
      .code_drift[0].installed_version == "1.0.277.1" and
      .code_drift[0].recorded_version == "1.0.277.2"
    ' <<<"$DOWNGRADE_PLAN" >/dev/null \
      || fail "Rank Math in-range downgrade plan did not identify the exact version transition: $DOWNGRADE_PLAN"
    [ "$(rank_math_native_state_hash wp2)" = "$DOWNGRADE_BEFORE" ] \
      || fail 'Rank Math in-range downgrade plan mutated plugin state'

    DOWNGRADE_DEPLOY_RC=0
    DOWNGRADE_DEPLOY_OUT=$(host_wprism conf2 deploy 2>&1) || DOWNGRADE_DEPLOY_RC=$?
    require_wprism_answered 'Rank Math 1.0.277.2 to 1.0.277.1 downgrade deploy' human "$DOWNGRADE_DEPLOY_OUT"
    [ "$DOWNGRADE_DEPLOY_RC" -ne 0 ] \
      && grep -q 'code_drift' <<<"$DOWNGRADE_DEPLOY_OUT" \
      && grep -q '1.0.277.2' <<<"$DOWNGRADE_DEPLOY_OUT" \
      && grep -q '1.0.277.1' <<<"$DOWNGRADE_DEPLOY_OUT" \
      || fail "Rank Math in-range downgrade deploy did not refuse at the exact code witness: $DOWNGRADE_DEPLOY_OUT"
    DOWNGRADE_REV=$(git -C "siterepo/${PAIR}2" rev-parse HEAD)
    DOWNGRADE_APPLY_RC=0
    DOWNGRADE_APPLY_OUT=$(wp2 wprism apply --repo=/siterepo --default-author=admin \
      --revision="$DOWNGRADE_REV" 2>&1) || DOWNGRADE_APPLY_RC=$?
    require_wprism_answered 'Rank Math 1.0.277.2 to 1.0.277.1 downgrade apply' human "$DOWNGRADE_APPLY_OUT"
    [ "$DOWNGRADE_APPLY_RC" -ne 0 ] \
      && grep -q 'code_drift' <<<"$DOWNGRADE_APPLY_OUT" \
      && grep -q '1.0.277.2' <<<"$DOWNGRADE_APPLY_OUT" \
      && grep -q '1.0.277.1' <<<"$DOWNGRADE_APPLY_OUT" \
      || fail "Rank Math in-range downgrade apply did not refuse at the exact code witness: $DOWNGRADE_APPLY_OUT"
    [ "$(rank_math_native_state_hash wp2)" = "$DOWNGRADE_BEFORE" ] \
      || fail 'Rank Math in-range downgrade refusal mutated plugin state'

    DOWNGRADE_SOURCE_DEPLOY=$(host_wprism conf1 deploy --force-code-drift 2>&1) \
      || fail "Rank Math downgraded source re-baseline failed: $DOWNGRADE_SOURCE_DEPLOY"
    DOWNGRADE_TARGET_DEPLOY=$(host_wprism conf2 deploy --force-code-drift 2>&1) \
      || fail "Rank Math downgraded target re-baseline failed: $DOWNGRADE_TARGET_DEPLOY"
    assert_rank_math_baseline_only_deploy 'Rank Math downgraded source forced host deploy' "$DOWNGRADE_SOURCE_DEPLOY"
    assert_rank_math_baseline_only_deploy 'Rank Math downgraded target forced host deploy' "$DOWNGRADE_TARGET_DEPLOY"
    assert_rank_math_zero_code_drift wp1 'Rank Math downgraded source forced host deploy'
    assert_rank_math_zero_code_drift wp2 'Rank Math downgraded target forced host deploy'

    DOWNGRADE_POST=$(wp1 post list --post_type=post --name=rank-math-article --field=ID)
    require_fixture_ids DOWNGRADE_POST
    wp1 post update "$DOWNGRADE_POST" --post_title='Rank Math 1.0.277.2 to 1.0.277.1 東京 🚀' >/dev/null
    wp1 wprism capture --repo=/siterepo
    wp1 wprism lint --repo=/siterepo
    "${GIT1[@]}" add -A
    "${GIT1[@]}" commit -qm 'capture: Rank Math 1.0.277.2 to 1.0.277.1 in-range downgrade'
    "${GIT1[@]}" push -q origin main
    git -C "siterepo/${PAIR}2" pull -q origin main
    DOWNGRADE_REV=$(git -C "siterepo/${PAIR}2" rev-parse HEAD)
    capture_wprism_json_checked RANK_MATH_BOUNDARY_APPLY_JSON 'Rank Math version-matrix downgrade apply' assert_wprism_apply_ready \
      wp2 wprism apply --repo=/siterepo --default-author=admin \
        --revision="$DOWNGRADE_REV" --json
    assert_wprism_apply_ready 'Rank Math version-matrix downgrade apply' "$RANK_MATH_BOUNDARY_APPLY_JSON"
    RANK_MATH_VERSION=1.0.277.1
    DOWNGRADE_SOURCE_TRANSITION=$(observe_rank_math conf1)
    DOWNGRADE_TARGET_TRANSITION=$(observe_rank_math conf2)
    assert_rank_math_transition_content 'Rank Math 1.0.277.2 to 1.0.277.1 transition' \
      "$RANK_MATH_VERSION" "$DOWNGRADE_SOURCE_TRANSITION" "$DOWNGRADE_TARGET_TRANSITION_BEFORE" \
      "$DOWNGRADE_TARGET_TRANSITION" "$RANK_MATH_BOUNDARY_APPLY_JSON"
    RANK_MATH_VERSION=1.0.277

    DOWNGRADED_TITLE=$(wp2 post list --post_type=post --name=rank-math-article --field=post_title)
    [ "$DOWNGRADED_TITLE" = 'Rank Math 1.0.277.2 to 1.0.277.1 東京 🚀' ] \
      || fail "Rank Math downgrade did not consume state authored after explicit re-baseline: $DOWNGRADED_TITLE"
    wp2 wprism capture --repo=/siterepo --out=/siterepo/.tmp-rank-math-downgraded-final
    DOWNGRADE_DIFF=$(diff -rq \
      "siterepo/${PAIR}1/state" \
      "siterepo/${PAIR}2/.tmp-rank-math-downgraded-final" || true)
    rm -rf "siterepo/${PAIR}2/.tmp-rank-math-downgraded-final"
    [ -z "$DOWNGRADE_DIFF" ] \
      || fail "Rank Math 1.0.277.2 -> 1.0.277.1 downgrade lost byte identity: $DOWNGRADE_DIFF"
    pass 'Rank Math 1.0.277.2 -> 1.0.277.1 refuses until explicit re-baseline, then preserves native behavior and byte identity'
  fi
done

say 'negative control: official seo-by-rank-math 1.0.276 must refuse below the certified patch line'
reset_env wp1
reset_case_repositories

RANK_MATH_IN_RANGE=$(fetch_artifact seo-by-rank-math 1.0.277.2 cli1)
wp1 plugin install "$RANK_MATH_IN_RANGE" --activate >/dev/null
NEGATIVE_INSTALLED=$(wp1 plugin get seo-by-rank-math --field=version)
require_fixture_values NEGATIVE_INSTALLED
[ "$NEGATIVE_INSTALLED" = 1.0.277.2 ] \
  || fail 'Rank Math negative-control premise did not install exact 1.0.277.2'
rank_math_site_policy "siterepo/${PAIR}1"
cp site-repo.gitignore.template "siterepo/${PAIR}1/.gitignore"
"${GIT1[@]}" init -q -b main
"${GIT1[@]}" remote add origin "../origin-$PAIR.git"
"${GIT1[@]}" add -A
"${GIT1[@]}" commit -qm 'policy: Rank Math negative-control pin'
"${GIT1[@]}" push -qu origin main
RANK_MATH_VERSION=1.0.277.2
seed_rank_math_content
wp1 wprism capture --repo=/siterepo
"${GIT1[@]}" add -A
"${GIT1[@]}" commit -qm 'capture: valid Rank Math state for negative control'
"${GIT1[@]}" push -q origin main
wprism_host_install_recovery_runtime "$(cd .. && pwd -P)" "siterepo/${PAIR}1" \
  || fail 'Rank Math negative control could not install the source recovery runtime'

wp1 plugin deactivate seo-by-rank-math >/dev/null
wp1 plugin delete seo-by-rank-math >/dev/null
RANK_MATH_OUT_OF_RANGE=$(fetch_artifact seo-by-rank-math 1.0.276 cli1)
wp1 plugin install "$RANK_MATH_OUT_OF_RANGE" >/dev/null
INSTALLED_OOR=$(wp1 plugin get seo-by-rank-math --field=version)
[ "$INSTALLED_OOR" = 1.0.276 ] \
  || fail "Rank Math negative control expected 1.0.276, got $INSTALLED_OOR"
NEGATIVE_BEFORE=$(rank_math_native_state_hash wp1)
require_observed_nonempty 'Rank Math outside-range state baseline' "$NEGATIVE_BEFORE"
NEGATIVE_RC=0
NEGATIVE_OUT=$(host_wprism conf1 deploy 2>&1) || NEGATIVE_RC=$?
require_wprism_answered 'Rank Math outside-range host deploy' human "$NEGATIVE_OUT"
[ "$NEGATIVE_RC" -ne 0 ] \
  && grep -Eq 'outside_version_range|outside the .* declared version_range' <<<"$NEGATIVE_OUT" \
  && grep -q 'seo-by-rank-math/rank-math.php' <<<"$NEGATIVE_OUT" \
  && grep -q '1.0.276' <<<"$NEGATIVE_OUT" \
  || fail "Rank Math 1.0.276 refused for the wrong reason: $NEGATIVE_OUT"
wp1 plugin is-active seo-by-rank-math >/dev/null 2>&1 \
  && fail 'outside-range Rank Math 1.0.276 was activated before refusal'
NEGATIVE_AFTER=$(rank_math_native_state_hash wp1)
[ "$NEGATIVE_AFTER" = "$NEGATIVE_BEFORE" ] \
  || fail 'Rank Math outside-range refusal mutated plugin state'
pass 'official Rank Math 1.0.276 is loudly refused before activation or plugin-state mutation'
}
