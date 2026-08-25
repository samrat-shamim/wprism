seed_yoast_duplicate_post_content() {
  wp_conf1() { wp1 "$@"; }
  local CONF_REPO1="siterepo/${PAIR}1"
  local COMPOSE="$PAIR_COMPOSE_STRING"
  . conformance/seeds/yoast-duplicate-post.sh
  unset -f wp_conf1
}

postdeploy_yoast_duplicate_post_content() {
  wp_conf2() { wp2 "$@"; }
  local CONF_REPO2="siterepo/${PAIR}2"
  local COMPOSE="$PAIR_COMPOSE_STRING"
  . conformance/postdeploy/yoast-duplicate-post.sh
  unset -f wp_conf2
}

check_yoast_duplicate_post_boundary_content() {
  local source_ids target_out target_json source_original source_copy
  source_ids=$(wp1 eval '
    $o=get_page_by_path("duo-duplicate-original", OBJECT, "post");
    $c=get_page_by_path("duo-duplicate-copy", OBJECT, "post");
    echo $o->ID . "|" . $c->ID;
  ')
  require_observed_nonempty "Yoast Duplicate Post boundary source IDs" "$source_ids"
  IFS='|' read -r source_original source_copy <<<"$source_ids"
  require_fixture_ids source_original source_copy
  target_out=$(wp2 eval '
    wp_set_current_user(1);
    $o=get_page_by_path("duo-duplicate-original", OBJECT, "post");
    $c=get_page_by_path("duo-duplicate-copy", OBJECT, "post");
    if (!$o || !$c) throw new RuntimeException("Duplicate Post boundary posts missing");
    $api=duplicate_post_get_original($c);
    $roles=[];
    foreach (["administrator","duo_reviewer","editor","subscriber"] as $name) {
      $role=get_role($name); $roles[$name]=$role ? $role->has_cap("copy_posts") : null;
    }
    echo wp_json_encode([
      "copy"=>(int)$c->ID,
      "copy_content_hash"=>hash("sha256", $c->post_content),
      "copy_menu_order"=>(int)$c->menu_order,
      "copy_original"=>(int)get_post_meta($c->ID,"_dp_original",true),
      "copy_original_api"=>$api ? (int)$api->ID : 0,
      "copy_status"=>$c->post_status,
      "copy_title"=>$c->post_title,
      "clone_link"=>duplicate_post_get_clone_post_link($o->ID,"display",false),
      "original"=>(int)$o->ID,
      "original_content_hash"=>hash("sha256", $o->post_content),
      "roles"=>$roles,
      "runtime_copy"=>get_post_meta($o->ID,"_dp_has_rewrite_republish_copy",true),
      "runtime_creation"=>get_post_meta($c->ID,"_dp_creation_date_gmt",true),
      "version"=>get_option("duplicate_post_version"),
    ], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
  ')
  require_observed_nonempty "Yoast Duplicate Post boundary target behavior" "$target_out"
  target_json=$(printf '%s\n' "$target_out" | awk 'NF { line=$0 } END { print line }')
  printf '%s\n' "$target_json" | jq -e --arg source_original "$source_original" --arg source_copy "$source_copy" '
    .original >= 9100001 and .copy > .original and
    (.original | tostring) != $source_original and (.copy | tostring) != $source_copy and
    .copy_original == .original and .copy_original_api == .original and
    .copy_status == "draft" and .copy_menu_order == 24 and
    .copy_content_hash == .original_content_hash and
    (.copy_title | contains("Duo Duplicate Original 東京 🚀")) and
    (.clone_link | contains("duplicate_post")) and
    .roles.administrator == true and .roles.duo_reviewer == true and
    .roles.editor == false and .roles.subscriber == false and
    (.runtime_copy | tonumber) == .copy and .runtime_creation == "not-a-date-東京-🚀" and
    .version == "4.7"
  ' >/dev/null || fail "Yoast Duplicate Post exact boundary target did not consume applied state: $target_json"
  pass "Yoast Duplicate Post exact boundary rewrites large IDs, exposes native links, reconciles roles, and preserves runtime workflow state"
}
