seed_advanced_editor_tools_content() {
  wp_conf1() { wp1 "$@"; }
  local CONF_REPO1="siterepo/${PAIR}1"
  local COMPOSE="$PAIR_COMPOSE_STRING"
  . ../adapter-packages/advanced-editor-tools/tests/conformance/seed.sh
  unset -f wp_conf1
}

check_advanced_editor_tools_boundary_content() {
  local out
  out=$(wp2 eval '
    wp_set_current_user(1);
    $settings = get_option("tadv_settings");
    $admin = get_option("tadv_admin_settings");
    echo wp_json_encode([
      "admin" => $admin,
      "buttons_1" => array_values(apply_filters("mce_buttons", ["formatselect"], "content")),
      "buttons_2" => array_values(apply_filters("mce_buttons_2", [], "content")),
      "buttons_3" => array_values(apply_filters("mce_buttons_3", [], "content")),
      "buttons_4" => array_values(apply_filters("mce_buttons_4", [], "content")),
      "classic_buttons" => array_values(apply_filters("mce_buttons", [], "classic-block")),
      "init" => apply_filters("tiny_mce_before_init", [], "content"),
      "settings" => $settings,
    ]);
  ')
  require_observed_nonempty "Advanced Editor Tools boundary target behavior" "$out"
  out=$(printf '%s\n' "$out" | awk 'NF { line=$0 } END { print line }')
  printf '%s\n' "$out" | jq -e '
    .buttons_1 == ["bold","italic","underline","strikethrough"] and
    .buttons_2 == ["bullist","numlist","blockquote","link","unlink"] and
    .buttons_3 == ["forecolor","backcolor","removeformat","charmap"] and
    .buttons_4 == ["code","fullscreen","searchreplace"] and
    .classic_buttons == ["bold","italic","link","undo","redo"] and
    .settings.toolbar_1 == "bold,italic,underline,strikethrough" and
    .admin.options == "no_autop,table_resize_bars" and
    .admin.disabled_editors == "rest_of_wpadmin" and
    .init.wpautop == false and .init.tadv_noautop == true
  ' >/dev/null || fail "Advanced Editor Tools boundary target did not consume the applied toolbar/admin settings: $out"
  pass "Advanced Editor Tools exact boundary drives all four toolbars, Classic block controls, and no-autop behavior on the target"
}
