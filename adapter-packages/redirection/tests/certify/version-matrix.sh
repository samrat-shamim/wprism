seed_redirection_content() {
  wp_conf1() { wp1 "$@"; }
  local CONF_REPO1="siterepo/${PAIR}1"
  local CONF1_PORT="$PORT1"
  . "$(dirname "${BASH_SOURCE[0]}")/../conformance/seed.sh"
  unset -f wp_conf1
}

prepare_redirection_boundary_target() {
  # Activation does not run Redirection's onboarding installer. The exact
  # boundary target must enter the same native ready state as conformance
  # before this helper can reset tables or exercise provider readback.
  local database
  wp2 redirection database install >/dev/null
  database=$(wp2 eval '
    global $wpdb;
    $tables=[];
    foreach (["redirection_items","redirection_groups","redirection_logs","redirection_404"] as $suffix) {
      $name=$wpdb->prefix.$suffix;
      $tables[$suffix]=$wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s",$name))===$name;
    }
    echo wp_json_encode([
      "database"=>(string)(Red_Options::get()["database"] ?? ""),
      "groups"=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}redirection_groups"),
      "tables"=>$tables,
    ]);
  ')
  require_observed_nonempty 'Redirection 5.9.0 boundary target database readiness' "$database"
  database=$(printf '%s\n' "$database" | awk 'NF { line=$0 } END { print line }')
  jq -e '.database != "" and .groups >= 2 and (.tables | all(. == true))' <<<"$database" >/dev/null \
    || fail "Redirection 5.9.0 boundary target native database install did not converge: $database"
  wp2 eval '
    global $wpdb;
    foreach (["redirection_items","redirection_groups","redirection_logs","redirection_404"] as $suffix) {
      $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}{$suffix}");
    }
    $wpdb->query("ALTER TABLE {$wpdb->prefix}redirection_groups AUTO_INCREMENT=101");
    $wpdb->query("ALTER TABLE {$wpdb->prefix}redirection_items AUTO_INCREMENT=201");
    Red_Options::save(["cache_key" => true]);
  ' >/dev/null
}

check_redirection_boundary_content() {
  local source_group target_group out headers code location
  source_group=$(wp1 db query "SELECT id FROM wp_redirection_groups WHERE name='Summer campaign 東京 🚀'" --skip-column-names | tr -d '[:space:]')
  target_group=$(wp2 db query "SELECT id FROM wp_redirection_groups WHERE name='Summer campaign 東京 🚀'" --skip-column-names | tr -d '[:space:]')
  require_fixture_ids source_group target_group
  [ "$source_group" != "$target_group" ] \
    || fail "Redirection 5.9.0 boundary group ids accidentally matched"
  out=$(wp2 eval '
    global $wpdb;
    $rows=$wpdb->get_results("SELECT id,title,group_id,action_data FROM {$wpdb->prefix}redirection_items ORDER BY id",ARRAY_A);
    $shape=["null"=>0,"plain"=>0,"serialized"=>0]; $native=true;
    foreach ($rows as $row) {
      if ($row["action_data"] === null) $shape["null"]++;
      elseif (is_serialized($row["action_data"])) $shape["serialized"]++;
      else $shape["plain"]++;
      $item=Red_Item::get_by_id((int)$row["id"]);
      $native=$native && $item instanceof Red_Item && ($item->to_sql()["action_data"] ?? null) === $row["action_data"];
    }
    echo wp_json_encode([
      "cache_key"=>(int)Red_Options::get()["cache_key"],
      "count"=>count($rows),
      "group_ids"=>array_values(array_unique(array_map("intval",array_column($rows,"group_id")))),
      "ids"=>array_map("intval",array_column($rows,"id")),
      "native"=>$native,
      "shape"=>$shape,
    ],JSON_UNESCAPED_SLASHES);
  ')
  require_observed_nonempty 'Redirection 5.9.0 boundary native rows' "$out"
  out=$(printf '%s\n' "$out" | awk 'NF { line=$0 } END { print line }')
  jq -e --argjson group "$target_group" '
    .cache_key > 0 and .count == 4 and .native == true and
    .shape == {null:1,plain:2,serialized:1} and
    .group_ids == [$group] and (.ids | all(. >= 201))
  ' <<<"$out" >/dev/null || fail "Redirection 5.9.0 boundary rows did not converge: $out"

  headers=$(mktemp "${TMPDIR:-/tmp}/wprism-vmatrix-redirection.XXXXXX")
  code=$(curl --max-time 20 -sS -D "$headers" -o /dev/null -w '%{http_code}' "http://localhost:${PORT2}/summer")
  location=$(awk 'BEGIN { IGNORECASE=1 } /^Location:/ { sub(/\r$/, ""); print substr($0, 11) }' "$headers" | tail -1)
  rm -f "$headers"
  [ "$code" = 302 ] && [ "$location" = "http://localhost:${PORT2}/summer-marketplace/" ] \
    || fail "Redirection 5.9.0 boundary route failed (status=$code location=${location:-<none>})"
  grep -q 'provider capability fired: redirection-state@1.0.0 rebuild_redirect_state' "$VMATRIX_APPLY_LOG" \
    || fail 'Redirection 5.9.0 boundary provider did not fire'
  pass 'Redirection 5.9.0 exact artifact preserves mapped refs, mixed framing, native readback, cache repair, and HTTP routing'
}

VMATRIX_PLUGIN_SLUG=redirection

version_matrix_preflight() {
  # These production-readiness legs must certify this physical checkout, not a
  # canonical sibling checkout selected by pair.sh for a linked worktree.
  [ -n "${WPRISM_EXPECTED_SOURCE_SHA:-}" ] \
    || fail "$VMATRIX_MANIFEST version-matrix evidence requires WPRISM_EXPECTED_SOURCE_SHA"
  export WPRISM_SOURCE_ROOT="$(cd .. && pwd -P)"
}

version_matrix_reset_after_delete() {
  local cli="$1"
  # Redirection deliberately retains its authored tables and settings on
  # ordinary plugin deletion. Each exact-artifact case must execute the
  # selected release's own installer against an empty plugin schema.
  "$cli" db query "
    DROP TABLE IF EXISTS wp_redirection_404, wp_redirection_groups, wp_redirection_items, wp_redirection_logs;
    DELETE FROM wp_options WHERE option_name LIKE 'redirection%';
  " >/dev/null
}

version_matrix_workflow() {
# These patch-bounded editor manifests each admit exactly one real release.
# Repeating the same bytes under artificial min/max labels would add runtime,
# not evidence; certify the one admitted artifact once and pair it with an
# adjacent official-release refusal below.
VMATRIX_CASES=$((VMATRIX_CASES + 1))
REDIRECTION_VERSION=5.9.0
say "boundary: redirection $REDIRECTION_VERSION (only admitted patch)"

reset_env wp1
reset_env wp2
reset_case_repositories

say "fetch + verify redirection $REDIRECTION_VERSION (digest-checked artifact only)"
REDIRECTION_ARTIFACT_1=$(fetch_artifact redirection "$REDIRECTION_VERSION" cli1)
REDIRECTION_ARTIFACT_2=$(fetch_artifact redirection "$REDIRECTION_VERSION" cli2)
wp1 plugin install "$REDIRECTION_ARTIFACT_1" --activate >/dev/null
[ "$(wp1 plugin get redirection --field=version)" = "$REDIRECTION_VERSION" ] \
  || fail "side 1 did not install exact redirection $REDIRECTION_VERSION"

printf '%s\n' '{' \
  '  "manifests": ["core", "redirection"],' \
  '  "policy": {' \
  '    "options": {},' \
  '    "post_meta": {},' \
  '    "post_types": ["post", "page", "attachment"],' \
  '    "taxonomies": ["category", "post_tag"]' \
  '  },' \
  '  "spec_version": 3' \
  '}' > "siterepo/${PAIR}1/site.wprism.json"
cp site-repo.gitignore.template "siterepo/${PAIR}1/.gitignore"
"${GIT1[@]}" init -q -b main
"${GIT1[@]}" remote add origin "../origin-$PAIR.git"
"${GIT1[@]}" add -A
"${GIT1[@]}" commit -qm "policy: Redirection $REDIRECTION_VERSION exact-boundary certification"
"${GIT1[@]}" push -qu origin main

seed_redirection_content
wp1 wprism capture --repo=/siterepo
wp1 wprism lint --repo=/siterepo
"${GIT1[@]}" add -A
"${GIT1[@]}" commit -qm "capture: Redirection $REDIRECTION_VERSION mixed rule graph"
"${GIT1[@]}" push -q origin main

clone_case_target
wp2 plugin install "$REDIRECTION_ARTIFACT_2" >/dev/null
[ "$(wp2 plugin get redirection --field=version)" = "$REDIRECTION_VERSION" ] \
  || fail "side 2 did not install exact redirection $REDIRECTION_VERSION"
wp2 wprism deploy --repo=/siterepo
prepare_redirection_boundary_target
REV=$(git -C "siterepo/${PAIR}2" rev-parse HEAD)
wp2 wprism apply --repo=/siterepo --adopt-by-slug=terms,posts --default-author=admin --revision="$REV" 2>&1 | tee "$VMATRIX_APPLY_LOG"
assert_version_matrix_apply_ready
grep -q 'canary clean' "$VMATRIX_APPLY_LOG" \
  || fail "apply canary not clean at redirection $REDIRECTION_VERSION"
check_redirection_boundary_content

wp2 wprism capture --repo=/siterepo --out=/siterepo/.tmp-final
REDIRECTION_DIFF=$(diff -rq "siterepo/${PAIR}1/state" "siterepo/${PAIR}2/.tmp-final" || true)
rm -rf "siterepo/${PAIR}2/.tmp-final"
[ -z "$REDIRECTION_DIFF" ] \
  || fail "byte-identity broken at redirection $REDIRECTION_VERSION: $REDIRECTION_DIFF"
pass "Redirection $REDIRECTION_VERSION deploys, behaves natively, and recaptures byte-identically"

say 'negative control: official redirection 5.8.1 must refuse below the exact contract'
NEGATIVE_BEFORE=$(wp1 eval 'global $wpdb; echo hash("sha256",wp_json_encode([$wpdb->get_results("SELECT * FROM {$wpdb->prefix}redirection_groups ORDER BY id",ARRAY_A),$wpdb->get_results("SELECT * FROM {$wpdb->prefix}redirection_items ORDER BY id",ARRAY_A),get_option("redirection_options")],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));')
wp1 plugin deactivate redirection >/dev/null
wp1 plugin delete redirection >/dev/null
REDIRECTION_OLD=$(fetch_artifact redirection 5.8.1 cli1)
wp1 plugin install "$REDIRECTION_OLD" >/dev/null
[ "$(wp1 plugin get redirection --field=version)" = 5.8.1 ] \
  || fail 'Redirection negative control did not install exact 5.8.1'
NEGATIVE_RC=0
NEGATIVE_OUT=$(wp1 wprism deploy --repo=/siterepo 2>&1) || NEGATIVE_RC=$?
require_wprism_answered 'Redirection 5.8.1 outside-range deploy' human "$NEGATIVE_OUT"
[ "$NEGATIVE_RC" -ne 0 ] && grep -Eq 'outside_version_range|outside the .* declared version_range' <<<"$NEGATIVE_OUT" \
  && grep -q '5.8.1' <<<"$NEGATIVE_OUT" \
  || fail "Redirection 5.8.1 refused for the wrong reason: $NEGATIVE_OUT"
wp1 plugin is-active redirection >/dev/null 2>&1 \
  && fail 'outside-range Redirection 5.8.1 was activated before refusal'
NEGATIVE_AFTER=$(wp1 eval 'global $wpdb; echo hash("sha256",wp_json_encode([$wpdb->get_results("SELECT * FROM {$wpdb->prefix}redirection_groups ORDER BY id",ARRAY_A),$wpdb->get_results("SELECT * FROM {$wpdb->prefix}redirection_items ORDER BY id",ARRAY_A),get_option("redirection_options")],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));')
[ "$NEGATIVE_AFTER" = "$NEGATIVE_BEFORE" ] \
  || fail 'Redirection outside-range refusal mutated retained plugin state'
pass 'official Redirection 5.8.1 is loudly refused before activation or state mutation'
}
