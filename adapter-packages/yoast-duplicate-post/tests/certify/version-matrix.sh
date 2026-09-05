seed_yoast_duplicate_post_content() {
  wp_conf1() { wp1 "$@"; }
  local CONF_REPO1="siterepo/${PAIR}1"
  local COMPOSE="$PAIR_COMPOSE_STRING"
  . "$(dirname "${BASH_SOURCE[0]}")/../conformance/seed.sh"
  unset -f wp_conf1
}

postdeploy_yoast_duplicate_post_content() {
  wp_conf2() { wp2 "$@"; }
  local CONF_REPO2="siterepo/${PAIR}2"
  local COMPOSE="$PAIR_COMPOSE_STRING"
  . "$(dirname "${BASH_SOURCE[0]}")/../conformance/postdeploy.sh"
  unset -f wp_conf2
}

check_yoast_duplicate_post_boundary_content() {
  local source_ids target_out target_json source_original source_copy
  source_ids=$(wp1 eval '
    $o=get_page_by_path("wprism-duplicate-original", OBJECT, "post");
    $c=get_page_by_path("wprism-duplicate-copy", OBJECT, "post");
    echo $o->ID . "|" . $c->ID;
  ')
  require_observed_nonempty "Yoast Duplicate Post boundary source IDs" "$source_ids"
  IFS='|' read -r source_original source_copy <<<"$source_ids"
  require_fixture_ids source_original source_copy
  target_out=$(wp2 eval '
    wp_set_current_user(1);
    $o=get_page_by_path("wprism-duplicate-original", OBJECT, "post");
    $c=get_page_by_path("wprism-duplicate-copy", OBJECT, "post");
    if (!$o || !$c) throw new RuntimeException("Duplicate Post boundary posts missing");
    $api=duplicate_post_get_original($c);
    $roles=[];
    foreach (["administrator","wprism_reviewer","editor","subscriber"] as $name) {
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
    (.copy_title | contains("WPrism Duplicate Original 東京 🚀")) and
    (.clone_link | contains("duplicate_post")) and
    .roles.administrator == true and .roles.wprism_reviewer == true and
    .roles.editor == false and .roles.subscriber == false and
    (.runtime_copy | tonumber) == .copy and .runtime_creation == "not-a-date-東京-🚀" and
    .version == "4.7"
  ' >/dev/null || fail "Yoast Duplicate Post exact boundary target did not consume applied state: $target_json"
  pass "Yoast Duplicate Post exact boundary rewrites large IDs, exposes native links, reconciles roles, and preserves runtime workflow state"
}

VMATRIX_PLUGIN_SLUG=duplicate-post

version_matrix_reset_after_delete() {
  local cli="$1"
  # Yoast Duplicate Post retains its settings, original-link meta, and role
  # capability on ordinary deletion. Matrix cases must begin at activation.
  "$cli" eval '
    global $wpdb;
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\''duplicate_post_%'\'' OR option_name = '\''yoast_duplicate_post_target_neighbor'\''");
    foreach (wp_roles()->roles as $name => $_row) {
      $role=get_role($name); if ($role && $role->has_cap("copy_posts")) $role->remove_cap("copy_posts");
    }
    foreach (["wprism_reviewer","wprism_source_only"] as $name) if (get_role($name)) remove_role($name);
  ' >/dev/null
}

version_matrix_workflow() {
VMATRIX_CASES=$((VMATRIX_CASES + 1))
YDP_VERSION=4.7
say "boundary: duplicate-post $YDP_VERSION (only admitted patch)"

reset_env wp1
reset_env wp2
reset_case_repositories

say "fetch + verify duplicate-post $YDP_VERSION (digest-checked artifact only)"
YDP_ARTIFACT_1=$(fetch_artifact duplicate-post "$YDP_VERSION" cli1)
YDP_ARTIFACT_2=$(fetch_artifact duplicate-post "$YDP_VERSION" cli2)
wp1 plugin install "$YDP_ARTIFACT_1" --activate >/dev/null
YDP_INSTALLED_1=$(wp1 plugin get duplicate-post --field=version)
[ "$YDP_INSTALLED_1" = "$YDP_VERSION" ] \
  || fail "side 1 installed version mismatch: expected $YDP_VERSION, got $YDP_INSTALLED_1"
pass "side 1: duplicate-post $YDP_VERSION installed from verified artifact, active"

cat > "siterepo/${PAIR}1/site.wprism.json" <<'EOF'
{
  "manifests": ["core", "yoast-duplicate-post"],
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
"${GIT1[@]}" commit -qm "policy: Yoast Duplicate Post $YDP_VERSION exact-boundary certification"
"${GIT1[@]}" push -qu origin main

seed_yoast_duplicate_post_content
wp1 wprism capture --repo=/siterepo
wp1 wprism lint --repo=/siterepo
"${GIT1[@]}" add -A
"${GIT1[@]}" commit -qm "capture: Yoast Duplicate Post $YDP_VERSION settings and clone state"
"${GIT1[@]}" push -q origin main

clone_case_target
wp2 plugin install "$YDP_ARTIFACT_2" >/dev/null
INSTALLED_2=$(wp2 plugin get duplicate-post --field=version)
require_fixture_values INSTALLED_2
[ "$INSTALLED_2" = "$YDP_VERSION" ] \
  || fail "side 2 installed version mismatch: expected $YDP_VERSION, got $INSTALLED_2"
wp2 wprism deploy --repo=/siterepo
postdeploy_yoast_duplicate_post_content
REV=$(git -C "siterepo/${PAIR}2" rev-parse HEAD)
wp2 wprism apply --repo=/siterepo --adopt-by-slug=terms,posts --default-author=admin --revision="$REV" 2>&1 | tee "$VMATRIX_APPLY_LOG"
assert_version_matrix_apply_ready
grep -q 'canary clean' "$VMATRIX_APPLY_LOG" \
  || fail "apply canary not clean at duplicate-post $YDP_VERSION"
grep -q 'provider capability fired: yoast-duplicate-post-role-capabilities@1.0.0 reconcile_role_capabilities' "$VMATRIX_APPLY_LOG" \
  || fail "Yoast Duplicate Post role provider did not fire at $YDP_VERSION"
check_yoast_duplicate_post_boundary_content

wp2 wprism capture --repo=/siterepo --out=/siterepo/.tmp-final
YDP_DIFF=$(diff -rq "siterepo/${PAIR}1/state" "siterepo/${PAIR}2/.tmp-final" || true)
rm -rf "siterepo/${PAIR}2/.tmp-final"
[ -z "$YDP_DIFF" ] \
  || fail "byte-identity broken at duplicate-post $YDP_VERSION: $YDP_DIFF"
pass "Yoast Duplicate Post $YDP_VERSION deploys, rewrites provenance, reconciles role state, behaves natively, and recaptures byte-identically"

say "negative control: duplicate-post 4.6 (adjacent official release below the exact 4.7 contract) must be REFUSED"
reset_env wp1
reset_case_repositories

YDP_IN_RANGE=$(fetch_artifact duplicate-post 4.7 cli1)
wp1 plugin install "$YDP_IN_RANGE" --activate >/dev/null
NEGATIVE_INSTALLED=$(wp1 plugin get duplicate-post --field=version)
require_fixture_values NEGATIVE_INSTALLED
[ "$NEGATIVE_INSTALLED" = 4.7 ] \
  || fail "negative control premise did not install exact duplicate-post 4.7 bytes"
cat > "siterepo/${PAIR}1/site.wprism.json" <<'EOF'
{
  "manifests": ["core", "yoast-duplicate-post"],
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
"${GIT1[@]}" commit -qm "policy: Yoast Duplicate Post negative-control pin"
"${GIT1[@]}" push -qu origin main
seed_yoast_duplicate_post_content
wp1 wprism capture --repo=/siterepo
"${GIT1[@]}" add -A
"${GIT1[@]}" commit -qm "capture: valid Yoast Duplicate Post state for negative control"
"${GIT1[@]}" push -q origin main

wp1 plugin deactivate duplicate-post >/dev/null
wp1 plugin delete duplicate-post >/dev/null
YDP_OUT_OF_RANGE=$(fetch_artifact duplicate-post 4.6 cli1)
wp1 plugin install "$YDP_OUT_OF_RANGE" >/dev/null
INSTALLED_OOR=$(wp1 plugin get duplicate-post --field=version)
[ "$INSTALLED_OOR" = 4.6 ] \
  || fail "negative control: expected duplicate-post 4.6 installed, got $INSTALLED_OOR"
YDP_REFUSAL_BEFORE=$(wp1 eval '
  $o=get_page_by_path("wprism-duplicate-original", OBJECT, "post");
  $c=get_page_by_path("wprism-duplicate-copy", OBJECT, "post");
  $roles=[]; foreach (["administrator","wprism_reviewer","editor","subscriber"] as $name) { $r=get_role($name); $roles[$name]=$r ? $r->has_cap("copy_posts") : null; }
  echo hash("sha256", wp_json_encode([get_option("duplicate_post_title_prefix",null),get_option("duplicate_post_roles",null),$o?$o->post_content:null,$c?get_post_meta($c->ID,"_dp_original",true):null,$roles]));
')
require_observed_nonempty "Yoast Duplicate Post refusal state baseline" "$YDP_REFUSAL_BEFORE"
set +e
DEPLOY_OUT=$(wp1 wprism deploy --repo=/siterepo 2>&1)
DEPLOY_RC=$?
set -e
[ "$DEPLOY_RC" -ne 0 ] \
  || fail "expected deploy to refuse duplicate-post 4.6, but it exited 0 (got: $DEPLOY_OUT)"
grep -Eq "outside_version_range|outside the '.*' manifest's declared version_range" <<<"$DEPLOY_OUT" \
  || fail "duplicate-post 4.6 refused for the wrong reason (got: $DEPLOY_OUT)"
grep -q 'duplicate-post/duplicate-post.php' <<<"$DEPLOY_OUT" \
  || fail "Yoast Duplicate Post refusal did not name the exact basename (got: $DEPLOY_OUT)"
grep -q '4.6' <<<"$DEPLOY_OUT" \
  || fail "Yoast Duplicate Post refusal did not name installed version 4.6 (got: $DEPLOY_OUT)"
if wp1 plugin is-active duplicate-post >/dev/null 2>&1; then
  fail "outside-range duplicate-post 4.6 was activated before refusal"
fi
YDP_REFUSAL_AFTER=$(wp1 eval '
  $o=get_page_by_path("wprism-duplicate-original", OBJECT, "post");
  $c=get_page_by_path("wprism-duplicate-copy", OBJECT, "post");
  $roles=[]; foreach (["administrator","wprism_reviewer","editor","subscriber"] as $name) { $r=get_role($name); $roles[$name]=$r ? $r->has_cap("copy_posts") : null; }
  echo hash("sha256", wp_json_encode([get_option("duplicate_post_title_prefix",null),get_option("duplicate_post_roles",null),$o?$o->post_content:null,$c?get_post_meta($c->ID,"_dp_original",true):null,$roles]));
')
[ "$YDP_REFUSAL_AFTER" = "$YDP_REFUSAL_BEFORE" ] \
  || fail "Yoast Duplicate Post outside-range refusal mutated settings/posts/references/roles"
printf '%s\n' "$DEPLOY_OUT"
pass "official duplicate-post 4.6 is loudly refused, remains inactive, and cannot mutate admitted state"
}
