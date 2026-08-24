seed_code_snippets_content() {
  wp_conf1() { wp1 "$@"; }
  local CONF_REPO1="siterepo/${PAIR}1"
  local COMPOSE="$PAIR_COMPOSE_STRING"
  . conformance/seeds/code-snippets.sh
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
