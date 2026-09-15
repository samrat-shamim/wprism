seed_wpforms_lite_content() {
  wp_conf1() { wp1 "$@"; }
  local CONF_REPO1="siterepo/${PAIR}1"
  export CONF_PAIR="$PAIR" CONF1_PORT="$PORT1" CONF2_PORT="$PORT2"
  export WPRISM_ARTIFACT_LIBRARY_ROOT="${WPRISM_SOURCE_ROOT:?}"
  . "$(dirname "${BASH_SOURCE[0]}")/../conformance/seed.sh"
  unset -f wp_conf1
}

check_wpforms_lite_content() {
  wp_conf2() { wp2 "$@"; }
  local CONF_REPO2="siterepo/${PAIR}2"
  export CONF_PAIR="$PAIR" CONF1_PORT="$PORT1" CONF2_PORT="$PORT2"
  export WPRISM_ARTIFACT_LIBRARY_ROOT="${WPRISM_SOURCE_ROOT:?}"
  . "$(dirname "${BASH_SOURCE[0]}")/../conformance/check.sh"
  unset -f wp_conf2
}

VMATRIX_PLUGIN_SLUG=wpforms-lite

version_matrix_workflow() {
  VMATRIX_CASES=$((VMATRIX_CASES + 1))
  WPFORMS_VERSION=2.0.1.1
  say "boundary: wpforms-lite $WPFORMS_VERSION (certified bounded surface)"

  reset_env wp1
  reset_env wp2
  reset_case_repositories

  say "fetch + verify wpforms-lite $WPFORMS_VERSION (digest-checked artifact only)"
  WPFORMS_ARTIFACT_1=$(fetch_artifact wpforms-lite "$WPFORMS_VERSION" cli1)
  WPFORMS_ARTIFACT_2=$(fetch_artifact wpforms-lite "$WPFORMS_VERSION" cli2)
  wp1 plugin install "$WPFORMS_ARTIFACT_1" --activate >/dev/null
  [ "$(wp1 plugin get wpforms-lite --field=version)" = "$WPFORMS_VERSION" ] \
    || fail "side 1 installed version mismatch for WPForms Lite"

  cat > "siterepo/${PAIR}1/site.wprism.json" <<'EOF'
{
  "manifests": ["core", "wpforms-lite"],
  "policy": {
    "options": {},
    "post_meta": {},
    "post_types": ["post", "page", "attachment", "wpforms", "wpforms-template"],
    "taxonomies": ["category", "post_tag", "wpforms_form_tag"]
  },
  "spec_version": 2
}
EOF
  cp site-repo.gitignore.template "siterepo/${PAIR}1/.gitignore"
  "${GIT1[@]}" init -q -b main
  "${GIT1[@]}" remote add origin "../origin-$PAIR.git"
  "${GIT1[@]}" add -A
  "${GIT1[@]}" commit -qm "policy: WPForms Lite $WPFORMS_VERSION exact-boundary certification"
  "${GIT1[@]}" push -qu origin main

  seed_wpforms_lite_content
  wp1 wprism capture --repo=/siterepo
  wp1 wprism lint --repo=/siterepo
  "${GIT1[@]}" add -A
  "${GIT1[@]}" commit -qm "capture: WPForms Lite bounded authored state"
  "${GIT1[@]}" push -q origin main

  clone_case_target
  wp2 plugin install "$WPFORMS_ARTIFACT_2" >/dev/null
  [ "$(wp2 plugin get wpforms-lite --field=version)" = "$WPFORMS_VERSION" ] \
    || fail "side 2 installed version mismatch for WPForms Lite"
  wp2 wprism deploy --repo=/siterepo
  REV=$(git -C "siterepo/${PAIR}2" rev-parse HEAD)
  wp2 wprism apply --repo=/siterepo --adopt-by-slug=terms,posts --default-author=admin --revision="$REV" 2>&1 | tee "$VMATRIX_APPLY_LOG"
  assert_version_matrix_apply_ready
  grep -q 'canary clean' "$VMATRIX_APPLY_LOG" \
    || fail "apply canary not clean at WPForms Lite $WPFORMS_VERSION"
  check_wpforms_lite_content

  wp2 wprism capture --repo=/siterepo --out=/siterepo/.tmp-final
  WPFORMS_DIFF=$(diff -rq "siterepo/${PAIR}1/state" "siterepo/${PAIR}2/.tmp-final" || true)
  rm -rf "siterepo/${PAIR}2/.tmp-final"
  [ -z "$WPFORMS_DIFF" ] || fail "byte-identity broken at WPForms Lite $WPFORMS_VERSION: $WPFORMS_DIFF"
  pass "WPForms Lite $WPFORMS_VERSION deploys, applies its bounded state and recaptures byte-identically"

  say "negative control: wpforms-lite 2.0.0.4 must be refused"
  reset_env wp1
  reset_case_repositories
  WPFORMS_IN_RANGE=$(fetch_artifact wpforms-lite 2.0.1.1 cli1)
  wp1 plugin install "$WPFORMS_IN_RANGE" --activate >/dev/null
  cat > "siterepo/${PAIR}1/site.wprism.json" <<'EOF'
{
  "manifests": ["core", "wpforms-lite"],
  "policy": {"options": {}, "post_meta": {}, "post_types": ["post", "page", "attachment", "wpforms", "wpforms-template"], "taxonomies": ["category", "post_tag", "wpforms_form_tag"]},
  "spec_version": 2
}
EOF
  cp site-repo.gitignore.template "siterepo/${PAIR}1/.gitignore"
  "${GIT1[@]}" init -q -b main
  "${GIT1[@]}" remote add origin "../origin-$PAIR.git"
  "${GIT1[@]}" add -A
  "${GIT1[@]}" commit -qm "policy: WPForms Lite negative-control pin"
  "${GIT1[@]}" push -qu origin main
  seed_wpforms_lite_content
  wp1 wprism capture --repo=/siterepo
  "${GIT1[@]}" add -A
  "${GIT1[@]}" commit -qm "capture: WPForms Lite negative-control state"
  "${GIT1[@]}" push -q origin main

  wp1 plugin deactivate wpforms-lite >/dev/null
  wp1 plugin delete wpforms-lite >/dev/null
  WPFORMS_OUT_OF_RANGE=$(fetch_artifact wpforms-lite 2.0.0.4 cli1)
  wp1 plugin install "$WPFORMS_OUT_OF_RANGE" >/dev/null
  [ "$(wp1 plugin get wpforms-lite --field=version)" = "2.0.0.4" ] \
    || fail "negative control installed the wrong WPForms Lite version"
  set +e
  WPFORMS_REFUSAL=$(wp1 wprism deploy --repo=/siterepo 2>&1)
  WPFORMS_REFUSAL_RC=$?
  set -e
  [ "$WPFORMS_REFUSAL_RC" -ne 0 ] \
    || fail "WPForms Lite 2.0.0.4 deploy unexpectedly succeeded"
  grep -Eq "outside_version_range|outside the '.*' manifest's declared version_range" <<<"$WPFORMS_REFUSAL" \
    || fail "WPForms Lite 2.0.0.4 refused for the wrong reason: $WPFORMS_REFUSAL"
  grep -q 'wpforms-lite/wpforms.php' <<<"$WPFORMS_REFUSAL" \
    || fail "WPForms Lite refusal did not name the exact plugin basename"
  pass "WPForms Lite 2.0.0.4 is loudly refused before deploy mutation"
}
