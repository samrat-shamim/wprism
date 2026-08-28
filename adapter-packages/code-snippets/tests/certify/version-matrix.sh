seed_code_snippets_content() {
  wp_conf1() { wp1 "$@"; }
  local CONF_REPO1="siterepo/${PAIR}1" package_tests
  local COMPOSE="$PAIR_COMPOSE_STRING"
  package_tests="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
  . "$package_tests/conformance/seed.sh"
  unset -f wp_conf1
}

prepare_code_snippets_boundary_target() {
  wp2 eval '
    foreach (Code_Snippets\get_snippets() as $snippet) {
      if (!Code_Snippets\delete_snippet((int) $snippet->id)) {
        throw new RuntimeException("could not remove target activation sample");
      }
    }
    for ($i = 0; $i < 5; $i++) {
      $spacer = Code_Snippets\save_snippet(new Code_Snippets\Snippet([
        "name" => "Boundary target spacer $i", "desc" => "deleted",
        "code" => "<p>deleted</p>", "scope" => "content", "active" => false,
      ]));
      if (!$spacer || !Code_Snippets\delete_snippet((int) $spacer->id)) {
        throw new RuntimeException("could not advance target identity sequence");
      }
    }
    Code_Snippets\Settings\update_setting("general", "enable_flat_files", true);
    do_action("code_snippets/settings_updated", Code_Snippets\Settings\get_settings_values());
  ' >/dev/null
}

check_code_snippets_boundary_content() { # <label>
  local label="$1" out source_id target_id
  source_id=$(wp1 db query "SELECT id FROM wp_snippets WHERE name='Duo portable content 東京 🚀'" --skip-column-names | tr -d '[:space:]')
  target_id=$(wp2 db query "SELECT id FROM wp_snippets WHERE name='Duo portable content 東京 🚀'" --skip-column-names | tr -d '[:space:]')
  require_fixture_ids source_id target_id
  [ "$source_id" != "$target_id" ] \
    || fail "Code Snippets $label source/target local IDs accidentally matched"
  out=$(wp2 eval '
    global $wpdb;
    $table = Code_Snippets\code_snippets()->db->get_table_name(false);
    $snippets = Code_Snippets\get_snippets();
    $content = null;
    foreach ($snippets as $snippet) {
      if ($snippet->name === "Duo portable content 東京 🚀") { $content = $snippet; }
    }
    if (!$content) { throw new RuntimeException("portable content snippet missing"); }
    $page = get_page_by_path("code-snippets-reference-matrix", OBJECT, "page");
    $hash = Code_Snippets\Snippet_Files::get_hashed_table_name($table);
    $directory = Code_Snippets\Snippet_Files::get_base_dir($hash);
    $files = [];
    if (is_dir($directory)) {
      foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isFile()) { $files[] = substr($file->getPathname(), strlen($directory) + 1); }
      }
    }
    sort($files, SORT_STRING);
    echo wp_json_encode([
      "api_count" => count($snippets),
      "content_id" => (int) $content->id,
      "content_render" => do_shortcode("[code_snippet id=\"{$content->id}\"]"),
      "files" => $files,
      "flat" => Code_Snippets\Snippet_Files::is_active(),
      "page" => $page ? $page->post_content : "",
      "priority" => (int) $wpdb->get_var("SELECT priority FROM `$table` WHERE name=\"Duo runtime filter\""),
      "runtime" => apply_filters("duo_code_snippets_runtime", "base"),
      "sample_count" => (int) $wpdb->get_var("SELECT COUNT(*) FROM `$table` WHERE tags LIKE \"%sample%\""),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  ')
  require_observed_nonempty "Code Snippets $label target behavior" "$out"
  out=$(printf '%s\n' "$out" | awk 'NF { line=$0 } END { print line }')
  printf '%s\n' "$out" | jq -e --arg source "$source_id" --arg target "$target_id" '
    .api_count == 3 and .sample_count == 0 and .flat == true and
    .runtime == "base|repository-runtime" and .priority == 32767 and
    (.content_render | contains("duo-code-snippet-marker")) and
    (.content_render | contains("東京 🚀")) and
    ([.page | scan("code_snippet(?:_source)? (?:id|snippet_id)=\\\"" + $target + "\\\"")] | length) == 4 and
    (.page | contains("\\\"" + $source + "\\\"") | not) and
    (.files | length) == 4 and
    (.files | index("html/" + $target + ".php") != null) and
    (.files | index("html/index.php") != null) and
    (.files | index("php/index.php") != null)
  ' >/dev/null || fail "Code Snippets $label target did not converge API, execution, refs, and flat files: $out"
  pass "Code Snippets $label exact artifact drives mapped refs, PHP/HTML behavior, cache repair, and flat-file execution"
}

VMATRIX_PLUGIN_SLUG=code-snippets

version_matrix_reset_after_delete() {
  local cli="$1"
  # Code Snippets deliberately retains its table/settings/files unless its
  # complete-uninstall setting is enabled. A matrix boundary must instead
  # start from that release's own fresh activation schema and sample rows.
  "$cli" db query "
    DROP TABLE IF EXISTS wp_snippets;
    DELETE FROM wp_options WHERE option_name LIKE 'code_snippets%'
      OR option_name IN ('recently_activated_snippets','active_shared_network_snippets');
  " >/dev/null
  "$cli" eval '
    $directory = WP_CONTENT_DIR . "/code-snippets";
    if (is_dir($directory)) {
      require_once ABSPATH . "wp-admin/includes/file.php";
      global $wp_filesystem;
      WP_Filesystem();
      if (!$wp_filesystem || !$wp_filesystem->delete($directory, true)) {
        throw new RuntimeException("version-matrix reset could not remove Code Snippets flat files");
      }
    }
  ' >/dev/null
}

version_matrix_workflow() {
VMATRIX_CASES=$((VMATRIX_CASES + 1))
for CODE_SNIPPETS_VERSION in 3.9.5 3.9.6; do
  say "boundary: code-snippets $CODE_SNIPPETS_VERSION"

  reset_env wp1
  reset_env wp2
  reset_case_repositories

  say "fetch + verify code-snippets $CODE_SNIPPETS_VERSION (digest-checked artifact only)"
  CS_ARTIFACT_1=$(fetch_artifact code-snippets "$CODE_SNIPPETS_VERSION" cli1)
  CS_ARTIFACT_2=$(fetch_artifact code-snippets "$CODE_SNIPPETS_VERSION" cli2)
  wp1 plugin install "$CS_ARTIFACT_1" --activate >/dev/null
  CS_INSTALLED_1=$(wp1 plugin get code-snippets --field=version)
  [ "$CS_INSTALLED_1" = "$CODE_SNIPPETS_VERSION" ] \
    || fail "side 1 installed version mismatch: expected $CODE_SNIPPETS_VERSION, got $CS_INSTALLED_1"
  pass "side 1: code-snippets $CODE_SNIPPETS_VERSION installed from verified artifact, active"

  cat > "siterepo/${PAIR}1/site.duo.json" <<'EOF'
{
  "manifests": ["core", "code-snippets"],
  "policy": {
    "options": {},
    "post_meta": {},
    "post_types": ["post", "page", "attachment"],
    "taxonomies": ["category", "post_tag"]
  },
  "spec_version": 2
}
EOF
  cp site-repo.gitignore.template "siterepo/${PAIR}1/.gitignore"
  "${GIT1[@]}" init -q -b main
  "${GIT1[@]}" remote add origin "../origin-$PAIR.git"
  "${GIT1[@]}" add -A
  "${GIT1[@]}" commit -qm "policy: Code Snippets $CODE_SNIPPETS_VERSION exact-boundary certification"
  "${GIT1[@]}" push -qu origin main

  seed_code_snippets_content
  wp1 duo capture --repo=/siterepo
  wp1 duo lint --repo=/siterepo
  "${GIT1[@]}" add -A
  "${GIT1[@]}" commit -qm "capture: Code Snippets $CODE_SNIPPETS_VERSION executable and reference state"
  "${GIT1[@]}" push -q origin main

  clone_case_target
  wp2 plugin install "$CS_ARTIFACT_2" >/dev/null
  INSTALLED_2=$(wp2 plugin get code-snippets --field=version)
  require_fixture_values INSTALLED_2
  [ "$INSTALLED_2" = "$CODE_SNIPPETS_VERSION" ] \
    || fail "side 2 installed version mismatch: expected $CODE_SNIPPETS_VERSION, got $INSTALLED_2"
  wp2 duo deploy --repo=/siterepo
  prepare_code_snippets_boundary_target
  REV=$(git -C "siterepo/${PAIR}2" rev-parse HEAD)
  wp2 duo apply --repo=/siterepo --adopt-by-slug=terms,posts --default-author=admin --revision="$REV" 2>&1 | tee "$VMATRIX_APPLY_LOG"
  grep -q 'canary clean' "$VMATRIX_APPLY_LOG" \
    || fail "apply canary not clean at code-snippets $CODE_SNIPPETS_VERSION"
  grep -q 'provider capability fired: code-snippets-state@1.0.0 rebuild_snippet_state' "$VMATRIX_APPLY_LOG" \
    || fail "Code Snippets provider did not fire at $CODE_SNIPPETS_VERSION"
  check_code_snippets_boundary_content "$CODE_SNIPPETS_VERSION"

  wp2 duo capture --repo=/siterepo --out=/siterepo/.tmp-final
  CS_DIFF=$(diff -rq "siterepo/${PAIR}1/state" "siterepo/${PAIR}2/.tmp-final" || true)
  rm -rf "siterepo/${PAIR}2/.tmp-final"
  [ -z "$CS_DIFF" ] \
    || fail "byte-identity broken at code-snippets $CODE_SNIPPETS_VERSION: $CS_DIFF"
  pass "Code Snippets $CODE_SNIPPETS_VERSION deploys, rebuilds derived state, executes natively, and recaptures byte-identically"

  if [ "$CODE_SNIPPETS_VERSION" = 3.9.5 ]; then
    # The relevant table/cache/flat-file source is byte-identical across the
    # admitted boundary. Upgrade both populated sides in place and prove that
    # no canonical or execution state moves when 3.9.6's unrelated REST/
    # multisite fixes replace 3.9.5.
    CS_UPGRADE_1=$(fetch_artifact code-snippets 3.9.6 cli1)
    CS_UPGRADE_2=$(fetch_artifact code-snippets 3.9.6 cli2)
    UPGRADE_BEFORE_1=$(wp1 eval 'global $wpdb; echo hash("sha256", wp_json_encode($wpdb->get_results("SELECT name,description,code,tags,scope,priority,active FROM {$wpdb->prefix}snippets ORDER BY id", ARRAY_A), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));')
    UPGRADE_BEFORE_2=$(wp2 eval 'global $wpdb; echo hash("sha256", wp_json_encode($wpdb->get_results("SELECT name,description,code,tags,scope,priority,active FROM {$wpdb->prefix}snippets ORDER BY id", ARRAY_A), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));')
    require_observed_nonempty "Code Snippets 3.9.5 source upgrade baseline" "$UPGRADE_BEFORE_1"
    require_observed_nonempty "Code Snippets 3.9.5 target upgrade baseline" "$UPGRADE_BEFORE_2"
    wp1 plugin install "$CS_UPGRADE_1" --force --activate >/dev/null
    wp2 plugin install "$CS_UPGRADE_2" --force --activate >/dev/null
    [ "$(wp1 plugin get code-snippets --field=version)" = 3.9.6 ] \
      && [ "$(wp2 plugin get code-snippets --field=version)" = 3.9.6 ] \
      || fail "Code Snippets supported in-place upgrade did not install 3.9.6 on both sides"
    UPGRADE_DEPLOY_RC=0
    UPGRADE_DEPLOY_OUT=$(wp2 duo deploy --repo=/siterepo 2>&1) || UPGRADE_DEPLOY_RC=$?
    require_duo_answered "Code Snippets out-of-band 3.9.5 to 3.9.6 upgrade refusal" human "$UPGRADE_DEPLOY_OUT"
    [ "$UPGRADE_DEPLOY_RC" -ne 0 ] \
      && grep -q 'deploy refused — code_drift' <<<"$UPGRADE_DEPLOY_OUT" \
      && grep -q 'recorded 3.9.5' <<<"$UPGRADE_DEPLOY_OUT" \
      && grep -q 'is 3.9.6 on this environment' <<<"$UPGRADE_DEPLOY_OUT" \
      || fail "Code Snippets out-of-band upgrade did not refuse at the exact code-drift boundary: $UPGRADE_DEPLOY_OUT"
    wp2 duo deploy --repo=/siterepo --force-code-drift >/dev/null
    UPGRADE_PLAN=$(wp2 duo plan --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
    require_duo_answered "Code Snippets 3.9.5 to 3.9.6 target plan" json "$UPGRADE_PLAN"
    jq -e '([.create,.update,.drift,.conflict,.collision,.delete,.delete_conflict] | map(length) | add) == 0' <<<"$UPGRADE_PLAN" >/dev/null \
      || fail "Code Snippets supported in-place upgrade invented authored work: $UPGRADE_PLAN"
    [ "$(wp1 eval 'global $wpdb; echo hash("sha256", wp_json_encode($wpdb->get_results("SELECT name,description,code,tags,scope,priority,active FROM {$wpdb->prefix}snippets ORDER BY id", ARRAY_A), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));')" = "$UPGRADE_BEFORE_1" ] \
      && [ "$(wp2 eval 'global $wpdb; echo hash("sha256", wp_json_encode($wpdb->get_results("SELECT name,description,code,tags,scope,priority,active FROM {$wpdb->prefix}snippets ORDER BY id", ARRAY_A), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));')" = "$UPGRADE_BEFORE_2" ] \
      || fail "Code Snippets supported in-place upgrade changed authored rows"
    check_code_snippets_boundary_content '3.9.5 -> 3.9.6 in-place upgrade'
    pass "Code Snippets populated 3.9.5 sites upgrade in place to 3.9.6 without authored, identity, reference, cache, or execution drift"
  fi
done

say "negative control: code-snippets 3.9.4 (adjacent official release below the 3.9.5 contract) must be REFUSED"
reset_env wp1
reset_case_repositories

CS_IN_RANGE=$(fetch_artifact code-snippets 3.9.5 cli1)
wp1 plugin install "$CS_IN_RANGE" --activate >/dev/null
NEGATIVE_INSTALLED=$(wp1 plugin get code-snippets --field=version)
require_fixture_values NEGATIVE_INSTALLED
[ "$NEGATIVE_INSTALLED" = 3.9.5 ] \
  || fail "negative control premise did not install exact code-snippets 3.9.5 bytes"
cat > "siterepo/${PAIR}1/site.duo.json" <<'EOF'
{
  "manifests": ["core", "code-snippets"],
  "policy": {
    "options": {},
    "post_meta": {},
    "post_types": ["post", "page", "attachment"],
    "taxonomies": ["category", "post_tag"]
  },
  "spec_version": 2
}
EOF
cp site-repo.gitignore.template "siterepo/${PAIR}1/.gitignore"
"${GIT1[@]}" init -q -b main
"${GIT1[@]}" remote add origin "../origin-$PAIR.git"
"${GIT1[@]}" add -A
"${GIT1[@]}" commit -qm "policy: Code Snippets negative-control pin"
"${GIT1[@]}" push -qu origin main
seed_code_snippets_content
wp1 duo capture --repo=/siterepo
"${GIT1[@]}" add -A
"${GIT1[@]}" commit -qm "capture: valid Code Snippets state for negative control"
"${GIT1[@]}" push -q origin main

wp1 plugin deactivate code-snippets >/dev/null
wp1 plugin delete code-snippets >/dev/null
CS_OUT_OF_RANGE=$(fetch_artifact code-snippets 3.9.4 cli1)
wp1 plugin install "$CS_OUT_OF_RANGE" >/dev/null
INSTALLED_OOR=$(wp1 plugin get code-snippets --field=version)
[ "$INSTALLED_OOR" = 3.9.4 ] \
  || fail "negative control: expected code-snippets 3.9.4 installed, got $INSTALLED_OOR"
CS_REFUSAL_BEFORE=$(wp1 eval '
  global $wpdb;
  $rows=$wpdb->get_results("SELECT * FROM {$wpdb->prefix}snippets ORDER BY id", ARRAY_A);
  $root=WP_CONTENT_DIR . "/code-snippets"; $tree=[];
  if (is_dir($root)) foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $f) if ($f->isFile()) $tree[substr($f->getPathname(), strlen($root)+1)]=hash_file("sha256", $f->getPathname());
  ksort($tree); echo hash("sha256", wp_json_encode([$rows,get_option("code_snippets_settings",null),get_option("code_snippets_version",null),$tree]));
')
require_observed_nonempty "Code Snippets refusal state baseline" "$CS_REFUSAL_BEFORE"
set +e
DEPLOY_OUT=$(wp1 duo deploy --repo=/siterepo 2>&1)
DEPLOY_RC=$?
set -e
[ "$DEPLOY_RC" -ne 0 ] \
  || fail "expected deploy to refuse code-snippets 3.9.4, but it exited 0 (got: $DEPLOY_OUT)"
grep -Eq "outside_version_range|outside the '.*' manifest's declared version_range" <<<"$DEPLOY_OUT" \
  || fail "code-snippets 3.9.4 refused for the wrong reason (got: $DEPLOY_OUT)"
grep -q 'code-snippets/code-snippets.php' <<<"$DEPLOY_OUT" \
  || fail "Code Snippets refusal did not name the exact basename (got: $DEPLOY_OUT)"
grep -q '3.9.4' <<<"$DEPLOY_OUT" \
  || fail "Code Snippets refusal did not name installed version 3.9.4 (got: $DEPLOY_OUT)"
if wp1 plugin is-active code-snippets >/dev/null 2>&1; then
  fail "outside-range code-snippets 3.9.4 was activated before refusal"
fi
CS_REFUSAL_AFTER=$(wp1 eval '
  global $wpdb;
  $rows=$wpdb->get_results("SELECT * FROM {$wpdb->prefix}snippets ORDER BY id", ARRAY_A);
  $root=WP_CONTENT_DIR . "/code-snippets"; $tree=[];
  if (is_dir($root)) foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $f) if ($f->isFile()) $tree[substr($f->getPathname(), strlen($root)+1)]=hash_file("sha256", $f->getPathname());
  ksort($tree); echo hash("sha256", wp_json_encode([$rows,get_option("code_snippets_settings",null),get_option("code_snippets_version",null),$tree]));
')
[ "$CS_REFUSAL_AFTER" = "$CS_REFUSAL_BEFORE" ] \
  || fail "Code Snippets outside-range refusal mutated table, settings, or flat-file state"
printf '%s\n' "$DEPLOY_OUT"
pass "official code-snippets 3.9.4 is loudly refused, remains inactive, and cannot mutate table/settings/flat files"
}
