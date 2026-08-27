seed_redirection_content() {
  wp_conf1() { wp1 "$@"; }
  local CONF_REPO1="siterepo/${PAIR}1"
  local CONF1_PORT="$PORT1"
  . conformance/seeds/redirection.sh
  unset -f wp_conf1
}

prepare_redirection_boundary_target() {
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

  headers=$(mktemp "${TMPDIR:-/tmp}/duo-vmatrix-redirection.XXXXXX")
  code=$(curl --max-time 20 -sS -D "$headers" -o /dev/null -w '%{http_code}' "http://localhost:${PORT2}/summer")
  location=$(awk 'BEGIN { IGNORECASE=1 } /^Location:/ { sub(/\r$/, ""); print substr($0, 11) }' "$headers" | tail -1)
  rm -f "$headers"
  [ "$code" = 302 ] && [ "$location" = "http://localhost:${PORT2}/summer-marketplace/" ] \
    || fail "Redirection 5.9.0 boundary route failed (status=$code location=${location:-<none>})"
  grep -q 'provider capability fired: redirection-state@1.0.0 rebuild_redirect_state' "$VMATRIX_APPLY_LOG" \
    || fail 'Redirection 5.9.0 boundary provider did not fire'
  pass 'Redirection 5.9.0 exact artifact preserves mapped refs, mixed framing, native readback, cache repair, and HTTP routing'
}
