seed_ninja_forms_content() {
  # Reuse the standalone conformance seed verbatim. It imports Ninja Forms'
  # own bundled Job Application template through the plugin's real admin
  # import process, then adds one native large/serialized disposable field
  # and one disposable action: 1 form, 24 fields, 4 actions plus a real block.
  wp_conf1() { wp1 "$@"; }
  local CONF_REPO1="siterepo/${PAIR}1"
  local COMPOSE="$PAIR_COMPOSE_STRING"
  . "$(dirname "${BASH_SOURCE[0]}")/../conformance/seed.sh"
  unset -f wp_conf1
}

postdeploy_ninja_forms_content() {
  # Deployment activates the plugin and Ninja Forms creates its own sample
  # form on the target. Reuse the standalone hook that removes only that
  # environment-local activation side effect before apply.
  wp_conf2() { wp2 "$@"; }
  local CONF_REPO2="siterepo/${PAIR}2"
  local COMPOSE="$PAIR_COMPOSE_STRING"
  . "$(dirname "${BASH_SOURCE[0]}")/../conformance/postdeploy.sh"
  unset -f wp_conf2
}

check_ninja_forms_boundary_content() { # [expected-title]
  local expected_title="${1:-Job Application}" front form_id api_out cache_out
  front=$(curl -fsSL "http://localhost:${PORT2}/conformance-careers/") \
    || fail "side 2 conformance-careers page did not return 200"
  require_observed_nonempty "side 2 Ninja Forms careers page" "$front"
  [ "${#front}" -ge 1000 ] \
    || fail "side 2 conformance-careers response was suspiciously short (${#front} bytes)"
  if grep -qiE 'fatal error|uncaught' <<<"$front"; then
    fail "side 2 rendered careers page contains a PHP fatal error marker"
  fi
  grep -qE 'Job Application|nf-form-' <<<"$front" \
    || fail "side 2 rendered careers page has no Ninja Forms markup"
  grep -q 'First Name' <<<"$front" \
    || fail "side 2 rendered form is missing its own field content"

  form_id=$(wp2 db query "SELECT id FROM wp_nf3_forms ORDER BY id" --skip-column-names | tr -d '[:space:]')
  require_fixture_ids form_id
  api_out=$(wp2 eval "
\$form = Ninja_Forms()->form($form_id)->get();
echo \$form->get_setting('title') . '|' . count(Ninja_Forms()->form($form_id)->get_fields()) . '|' . count(Ninja_Forms()->form($form_id)->get_actions());
")
  require_observed_nonempty "side 2 Ninja Forms model API" "$api_out"
  [ "$api_out" = "$expected_title|24|4" ] \
    || fail "side 2 Ninja Forms model API mismatch (got: $api_out)"
  cache_out=$(wp2 eval '
    global $wpdb;
    $forms=array_values(array_map("intval",$wpdb->get_col("SELECT id FROM {$wpdb->prefix}nf3_forms ORDER BY id")));
    $caches=array_values(array_map("intval",$wpdb->get_col("SELECT id FROM {$wpdb->prefix}nf3_upgrades ORDER BY id")));
    $invalid=0;
    foreach ($forms as $id) {
      $raw=$wpdb->get_var($wpdb->prepare("SELECT cache FROM {$wpdb->prefix}nf3_upgrades WHERE id=%d",$id));
      $cache=is_string($raw)?@unserialize($raw,["allowed_classes"=>false,"max_depth"=>64]):false;
      $fields=array_values(array_map("intval",$wpdb->get_col($wpdb->prepare("SELECT id FROM {$wpdb->prefix}nf3_fields WHERE parent_id=%d ORDER BY id",$id))));
      $actions=array_values(array_map("intval",$wpdb->get_col($wpdb->prepare("SELECT id FROM {$wpdb->prefix}nf3_actions WHERE parent_id=%d ORDER BY id",$id))));
      $cachedFields=is_array($cache)?array_map(static fn($row)=>(int)($row["id"]??0),(array)($cache["fields"]??[])):[];
      $cachedActions=is_array($cache)?array_map(static fn($row)=>(int)($row["id"]??0),(array)($cache["actions"]??[])):[];
      sort($cachedFields,SORT_NUMERIC); sort($cachedActions,SORT_NUMERIC);
      if (!is_array($cache)||(int)($cache["id"]??0)!==$id||$cachedFields!==$fields||$cachedActions!==$actions) $invalid++;
    }
    $legacy=0;
    foreach ((array)$wpdb->get_col("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE \"nf_form_%\"") as $name) {
      if (is_string($name)&&preg_match("/^nf_form_[1-9][0-9]*$/D",$name)===1) $legacy++;
    }
    echo implode("|",[count($forms),$forms===$caches?1:0,$invalid,$legacy]);
  ')
  require_observed_nonempty "side 2 Ninja Forms cache projection" "$cache_out"
  [ "$cache_out" = "1|1|0|0" ] \
    || fail "side 2 Ninja Forms cache projection was not closed (got: $cache_out)"
  pass "side 2 renders the real Job Application; native API resolves 24 fields/4 actions; provider cache is exact and legacy-free"
}
