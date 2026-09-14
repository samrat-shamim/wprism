seed_disable_comments_content() {
  wp_conf1() { wp1 "$@"; }
  local CONF_REPO1="siterepo/${PAIR}1"
  . "$(dirname "${BASH_SOURCE[0]}")/../conformance/seed.sh"
  unset -f wp_conf1
}

check_disable_comments_boundary_content() {
  local post_id="$1" port="$2" label="$3" body status xml
  post_id=$($post_id post list --post_type=post --name=disable-comments-endpoint-fixture --field=ID)
  require_observed_nonempty "Disable Comments $label target post" "$post_id"

  body=$(mktemp "${TMPDIR:-/tmp}/wprism-vmatrix-disable-comments-rest.XXXXXX")
  status=$(curl --max-time 20 -sS -X POST \
    -H 'Content-Type: application/json' \
    --data "{\"post\":${post_id},\"author_name\":\"WPrism\",\"author_email\":\"probe@example.test\",\"content\":\"endpoint probe\"}" \
    -o "$body" -w '%{http_code}' "http://localhost:${port}/wp-json/wp/v2/comments")
  [ "$status" = "403" ] \
    || fail "Disable Comments $label REST request was not refused with 403: $(cat "$body")"
  jq -e '.code == "rest_comment_disabled" and .data.status == 403' "$body" >/dev/null \
    || fail "Disable Comments $label REST request returned the wrong refusal: $(cat "$body")"
  rm -f "$body"

  xml='<?xml version="1.0"?><methodCall><methodName>system.listMethods</methodName><params/></methodCall>'
  body=$(mktemp "${TMPDIR:-/tmp}/wprism-vmatrix-disable-comments-xmlrpc.XXXXXX")
  curl --max-time 20 -sS -H 'Content-Type: text/xml' --data "$xml" \
    -o "$body" -w '%{http_code}' "http://localhost:${port}/xmlrpc.php" >"$body.code"
  [ "$(cat "$body.code")" = "200" ] || fail "Disable Comments $label XML-RPC method list failed: $(cat "$body")"
  ! grep -Fq 'wp.newComment' "$body" || fail "Disable Comments $label XML-RPC still exposes wp.newComment"
  grep -Fq 'wp.getComments' "$body" || fail "Disable Comments $label XML-RPC lost wp.getComments"
  rm -f "$body" "$body.code"
  pass "Disable Comments $label REST and XML-RPC endpoint behavior is native and target-local"
}

VMATRIX_PLUGIN_SLUG=disable-comments

version_matrix_reset_after_delete() {
  local cli="$1"
  "$cli" db query "
    DELETE FROM wp_options
      WHERE option_name IN (
        'disable_comments_options',
        'disable_comment_version',
        'disable_comments_blocked_since',
        'disable_comments_blocked_stats_comment',
        'disable_comments_blocked_stats_rest',
        'disable_comments_blocked_stats_trackback',
        'disable_comments_review_trigger'
      );
    DELETE FROM wp_usermeta WHERE meta_key = 'disable_comments_review_dismissed';
  " >/dev/null
}

version_matrix_workflow() {
  VMATRIX_CASES=$((VMATRIX_CASES + 1))
  DISABLE_COMMENTS_VERSION=2.9.0
  say "boundary: disable-comments $DISABLE_COMMENTS_VERSION (only admitted patch)"

  reset_env wp1
  reset_env wp2
  reset_case_repositories

  say "fetch + verify disable-comments $DISABLE_COMMENTS_VERSION (digest-checked artifact only)"
  DISABLE_ARTIFACT_1=$(fetch_artifact disable-comments "$DISABLE_COMMENTS_VERSION" cli1)
  DISABLE_ARTIFACT_2=$(fetch_artifact disable-comments "$DISABLE_COMMENTS_VERSION" cli2)
  wp1 plugin install "$DISABLE_ARTIFACT_1" --activate >/dev/null
  DISABLE_INSTALLED_1=$(wp1 plugin get disable-comments --field=version)
  [ "$DISABLE_INSTALLED_1" = "$DISABLE_COMMENTS_VERSION" ] \
    || fail "side 1 installed version mismatch: expected $DISABLE_COMMENTS_VERSION, got $DISABLE_INSTALLED_1"
  pass "side 1: Disable Comments $DISABLE_COMMENTS_VERSION installed from verified artifact, active"

  cat > "siterepo/${PAIR}1/site.wprism.json" <<'EOF'
{
  "manifests": ["core", "disable-comments"],
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
  "${GIT1[@]}" commit -qm "policy: Disable Comments $DISABLE_COMMENTS_VERSION exact-boundary certification"
  "${GIT1[@]}" push -qu origin main

  seed_disable_comments_content
  wp1 wprism capture --repo=/siterepo
  wp1 wprism lint --repo=/siterepo
  "${GIT1[@]}" add -A
  "${GIT1[@]}" commit -qm "capture: Disable Comments endpoint settings"
  "${GIT1[@]}" push -q origin main

  clone_case_target
  wp2 plugin install "$DISABLE_ARTIFACT_2" >/dev/null
  INSTALLED_2=$(wp2 plugin get disable-comments --field=version)
  require_fixture_values INSTALLED_2
  [ "$INSTALLED_2" = "$DISABLE_COMMENTS_VERSION" ] \
    || fail "side 2 installed version mismatch: expected $DISABLE_COMMENTS_VERSION, got $INSTALLED_2"
  wp2 wprism deploy --repo=/siterepo
  REV=$(git -C "siterepo/${PAIR}2" rev-parse HEAD)
  wp2 wprism apply --repo=/siterepo --adopt-by-slug=terms,posts --default-author=admin --revision="$REV" 2>&1 | tee "$VMATRIX_APPLY_LOG"
  assert_version_matrix_apply_ready
  grep -q 'canary clean' "$VMATRIX_APPLY_LOG" \
    || fail "apply canary not clean at Disable Comments $DISABLE_COMMENTS_VERSION"
  check_disable_comments_boundary_content wp2 "$PORT2" target

  wp2 wprism capture --repo=/siterepo --out=/siterepo/.tmp-final
  DISABLE_DIFF=$(diff -rq "siterepo/${PAIR}1/state" "siterepo/${PAIR}2/.tmp-final" || true)
  rm -rf "siterepo/${PAIR}2/.tmp-final"
  [ -z "$DISABLE_DIFF" ] \
    || fail "byte-identity broken at Disable Comments $DISABLE_COMMENTS_VERSION: $DISABLE_DIFF"
  pass "Disable Comments $DISABLE_COMMENTS_VERSION deploys, blocks both native endpoints, and recaptures byte-identically"

  say "lifecycle: exact Disable Comments uninstall and reinstall"
  wp2 plugin deactivate disable-comments >/dev/null
  wp2 plugin uninstall disable-comments --deactivate >/dev/null
  wp2 plugin is-installed disable-comments >/dev/null 2>&1 \
    && fail "Disable Comments uninstall left plugin code installed"
  [ "$(wp2 option get disable_comments_options 2>/dev/null || true)" = "" ] \
    || fail "Disable Comments uninstall left the authored option row"
  wp2 plugin install "$DISABLE_ARTIFACT_2" --force >/dev/null
  wp2 wprism deploy --repo=/siterepo
  wp2 plugin is-active disable-comments >/dev/null \
    || fail "exact Disable Comments reinstall was not activated by deploy"
  set +e
  REINSTALL_DRIFT=$(wp2 wprism apply --repo=/siterepo --adopt-by-slug=terms,posts --default-author=admin --revision="$REV" 2>&1)
  REINSTALL_DRIFT_RC=$?
  set -e
  [ "$REINSTALL_DRIFT_RC" -ne 0 ] \
    || fail "Apply silently crossed the native uninstall drift boundary"
  grep -q 'ordinary target drift requires capture/reconciliation before apply' <<<"$REINSTALL_DRIFT" \
    || fail "native uninstall drift was refused for the wrong reason: $REINSTALL_DRIFT"
  grep -q 'options/core.json' <<<"$REINSTALL_DRIFT" \
    || fail "native uninstall drift did not identify the affected canonical surface: $REINSTALL_DRIFT"
  wp2 disable-comments settings --xmlrpc --rest-api >/dev/null
  wp2 wprism apply --repo=/siterepo --adopt-by-slug=terms,posts --default-author=admin --revision="$REV" >/dev/null
  check_disable_comments_boundary_content wp2 "$PORT2" reinstall
  pass "Disable Comments uninstall removes plugin-owned residue without touching core content, WPrism refuses the resulting target drift, and native reinstall recovery restores the endpoint behavior"

  say "negative control: disable-comments 2.8.0 must be refused"
  reset_env wp1
  reset_case_repositories
  DISABLE_IN_RANGE=$(fetch_artifact disable-comments 2.9.0 cli1)
  wp1 plugin install "$DISABLE_IN_RANGE" --activate >/dev/null
  cat > "siterepo/${PAIR}1/site.wprism.json" <<'EOF'
{
  "manifests": ["core", "disable-comments"],
  "policy": {"options": {}, "post_meta": {}, "post_types": ["post", "page", "attachment"], "taxonomies": ["category", "post_tag"]},
  "spec_version": 2
}
EOF
  cp site-repo.gitignore.template "siterepo/${PAIR}1/.gitignore"
  "${GIT1[@]}" init -q -b main
  "${GIT1[@]}" remote add origin "../origin-$PAIR.git"
  "${GIT1[@]}" add -A
  "${GIT1[@]}" commit -qm "policy: Disable Comments negative-control pin"
  "${GIT1[@]}" push -qu origin main
  seed_disable_comments_content
  wp1 wprism capture --repo=/siterepo
  "${GIT1[@]}" add -A
  "${GIT1[@]}" commit -qm "capture: Disable Comments valid negative-control state"
  "${GIT1[@]}" push -q origin main

  DISABLE_REFUSAL_BEFORE=$(wp1 eval 'echo hash("sha256", maybe_serialize(get_option("disable_comments_options", null)));')
  require_observed_nonempty "Disable Comments refusal state baseline" "$DISABLE_REFUSAL_BEFORE"
  wp1 plugin deactivate disable-comments >/dev/null
  wp1 plugin delete disable-comments >/dev/null
  DISABLE_OUT_OF_RANGE=$(fetch_artifact disable-comments 2.8.0 cli1)
  wp1 plugin install "$DISABLE_OUT_OF_RANGE" >/dev/null
  INSTALLED_OOR=$(wp1 plugin get disable-comments --field=version)
  [ "$INSTALLED_OOR" = "2.8.0" ] \
    || fail "negative control expected Disable Comments 2.8.0, got $INSTALLED_OOR"
  set +e
  DEPLOY_OUT=$(wp1 wprism deploy --repo=/siterepo 2>&1)
  DEPLOY_RC=$?
  set -e
  [ "$DEPLOY_RC" -ne 0 ] \
    || fail "expected Disable Comments 2.8.0 deploy to refuse, but it exited 0: $DEPLOY_OUT"
  grep -Eq "outside_version_range|outside the '.*' manifest's declared version_range" <<<"$DEPLOY_OUT" \
    || fail "Disable Comments 2.8.0 refused for the wrong reason: $DEPLOY_OUT"
  grep -q "disable-comments/disable-comments.php" <<<"$DEPLOY_OUT" \
    || fail "Disable Comments refusal did not name the exact basename: $DEPLOY_OUT"
  grep -q "2.8.0" <<<"$DEPLOY_OUT" \
    || fail "Disable Comments refusal did not name installed version 2.8.0: $DEPLOY_OUT"
  if wp1 plugin is-active disable-comments >/dev/null 2>&1; then
    fail "out-of-range Disable Comments 2.8.0 was activated before refusal"
  fi
  DISABLE_REFUSAL_AFTER=$(wp1 eval 'echo hash("sha256", maybe_serialize(get_option("disable_comments_options", null)));')
  [ "$DISABLE_REFUSAL_AFTER" = "$DISABLE_REFUSAL_BEFORE" ] \
    || fail "Disable Comments outside-range refusal mutated authored settings"
  printf '%s\n' "$DEPLOY_OUT"
  pass "official Disable Comments 2.8.0 is loudly refused, remains inactive, and cannot mutate admitted settings"
}
