seed_classic_editor_content() {
  wp_conf1() { wp1 "$@"; }
  local CONF_REPO1="siterepo/${PAIR}1"
  local COMPOSE="$PAIR_COMPOSE_STRING"
  . conformance/seeds/classic-editor.sh
  unset -f wp_conf1
}

check_classic_editor_boundary_content() {
  local out
  out=$(wp2 eval '
    wp_set_current_user(1);
    $plain = get_page_by_path("classic-editor-plain-fixture", OBJECT, "post");
    $blocks = get_page_by_path("classic-editor-block-fixture", OBJECT, "post");
    if (!$plain || !$blocks) { throw new RuntimeException("Classic Editor boundary posts are missing"); }
    $get_settings = new ReflectionMethod("Classic_Editor", "get_settings");
    echo wp_json_encode([
      "allow" => get_option("classic-editor-allow-users"),
      "block_post_uses_blocks" => (bool) use_block_editor_for_post($blocks),
      "plain_post_uses_blocks" => (bool) use_block_editor_for_post($plain),
      "post_type_uses_blocks" => (bool) use_block_editor_for_post_type("post"),
      "replace" => get_option("classic-editor-replace"),
      "settings" => $get_settings->invoke(null, "refresh", 1),
    ]);
  ')
  require_observed_nonempty "Classic Editor boundary target behavior" "$out"
  out=$(printf '%s\n' "$out" | awk 'NF { line=$0 } END { print line }')
  printf '%s\n' "$out" | jq -e '
    .replace == "classic" and .allow == "allow" and
    .settings.editor == "classic" and .settings["allow-users"] == true and
    .plain_post_uses_blocks == false and .block_post_uses_blocks == true and
    .post_type_uses_blocks == true
  ' >/dev/null || fail "Classic Editor boundary target did not consume the applied selection settings: $out"
  pass "Classic Editor exact boundary selects classic for plain content while preserving block-editor routing for block content"
}
