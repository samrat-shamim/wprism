VMATRIX_PLUGIN_SLUG=change-wp-admin-login

version_matrix_preflight() {
  : "${WPRISM_EXPECTED_SOURCE_SHA:?AIO exact artifact evidence requires committed candidate source}"
  export WPRISM_SOURCE_ROOT
  WPRISM_SOURCE_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../../.." && pwd -P)"
}

version_matrix_workflow() {
  VMATRIX_CASES=$((VMATRIX_CASES + 1))
  export CONF_PAIR="$PAIR" CONF1_PORT="$PORT1" CONF2_PORT="$PORT2"
  export CONF_REPO1="siterepo/${PAIR}1" CONF_REPO2="siterepo/${PAIR}2"
  export WPRISM_ARTIFACT_LIBRARY_ROOT="$WPRISM_SOURCE_ROOT"
  wp_conf1() { wp1 "$@"; }
  wp_conf2() { wp2 "$@"; }
  reset_env wp1
  reset_env wp2
  reset_case_repositories
  AIO_ARTIFACT_1=$(fetch_artifact change-wp-admin-login 2.4.1 cli1)
  AIO_ARTIFACT_2=$(fetch_artifact change-wp-admin-login 2.4.1 cli2)
  wp1 plugin install "$AIO_ARTIFACT_1" --activate
  wp2 plugin install "$AIO_ARTIFACT_2"
  capture_wprism_json_success AIO_VERSION 'AIO exact artifact versions and lifecycle premise' wp2 eval 'require_once ABSPATH."wp-admin/includes/plugin.php"; $p="change-wp-admin-login/change-wp-admin-login.php"; $v=get_plugins()[$p]["Version"]??null; if($v!=="2.4.1"||is_plugin_active($p)) throw new RuntimeException("exact inactive AIO artifact required"); echo wp_json_encode(["version"=>$v,"active"=>false]);'
  printf '%s\n' '{"manifests":["core","change-wp-admin-login"],"policy":{"options":{},"post_meta":{},"post_types":["post","page","attachment"],"taxonomies":["category","post_tag"]},"spec_version":3}' > "$CONF_REPO1/site.wprism.json"
  cp site-repo.gitignore.template "$CONF_REPO1/.gitignore"
  "${GIT1[@]}" init -q -b main
  "${GIT1[@]}" remote add origin "../origin-$PAIR.git"
  . "$(dirname "${BASH_SOURCE[0]}")/../conformance/seed.sh"
  establish_core_environment_bindings wp1 /siterepo admin@example.test "http://localhost:$PORT1" "http://localhost:$PORT1"
  wp1 wprism capture --repo=/siterepo
  "${GIT1[@]}" add -A
  "${GIT1[@]}" commit -qm 'AIO exact 2.4.1 native boundary'
  "${GIT1[@]}" push -qu origin main
  clone_case_target
  capture_wprism_json_success AIO_DEPLOY 'AIO boundary native activation' wp2 wprism deploy --repo=/siterepo --format=json
  . "$(dirname "${BASH_SOURCE[0]}")/../conformance/postdeploy.sh"
  AIO_REV=$(git -C "$CONF_REPO2" rev-parse HEAD)
  capture_wprism_json_checked AIO_APPLY 'AIO exact boundary apply' assert_wprism_apply_ready wp2 wprism apply --repo=/siterepo --adopt-by-slug=terms,posts --default-author=admin --revision="$AIO_REV" --json
  printf '%s\n' "$AIO_APPLY" > "$VMATRIX_APPLY_LOG"
  assert_version_matrix_apply_ready
  . "$(dirname "${BASH_SOURCE[0]}")/../conformance/check.sh"
  # The declared half-open range admits one released patch. A second admitted
  # upgrade artifact does not exist in this contract; official 2.4.0 tests the
  # adjacent downgrade refusal with actual plugin code, without header edits.
  VMATRIX_CASES=$((VMATRIX_CASES + 1))
  wp2 plugin deactivate change-wp-admin-login
  wp2 plugin delete change-wp-admin-login
  AIO_OLD_ARTIFACT=$(fetch_artifact change-wp-admin-login 2.4.0 cli2)
  wp2 plugin install "$AIO_OLD_ARTIFACT"
  capture_wprism_json_success AIO_VERSION 'AIO official old artifact premise' wp2 eval 'require_once ABSPATH."wp-admin/includes/plugin.php"; $p="change-wp-admin-login/change-wp-admin-login.php"; $v=get_plugins()[$p]["Version"]??null; if($v!=="2.4.0"||is_plugin_active($p)) throw new RuntimeException("official inactive AIO 2.4.0 required"); echo wp_json_encode(["version"=>$v,"active"=>false]);'
  cp "$AIO_CAPSULE/fixtures/native/state-observe.php" "$CONF_REPO2/.tmp-aio-state-observe.php"
  capture_wprism_json_success AIO_BEFORE 'AIO old artifact state baseline' wp2 eval-file /siterepo/.tmp-aio-state-observe.php --use-include
  . "$(dirname "${BASH_SOURCE[0]}")/../../fixtures/native/private-command.sh"
  capture_wprism_json_refusal AIO_REFUSAL 'AIO exact old-version cause' aio_private_command cli2 deploy old-version wp2 wprism deploy --repo=/siterepo --format=json
  printf '%s\n' "$AIO_REFUSAL" > "$AIO_TARGET_EVIDENCE/old-version-refusal.json"
  capture_wprism_json_success AIO_AFTER 'AIO old artifact state preservation' wp2 eval-file /siterepo/.tmp-aio-state-observe.php --use-include
  [ "$AIO_BEFORE" = "$AIO_AFTER" ] || fail 'AIO official old-version refusal changed owned rows or ledger'
  if wp2 plugin is-active change-wp-admin-login; then fail 'AIO old code activated before refusal'; fi
  pass 'AIO official 2.4.1 native roundtrip and 2.4.0 exact-cause refusal pass'
}
