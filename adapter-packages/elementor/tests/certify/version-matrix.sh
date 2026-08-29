seed_elementor_content() {
  # Reuse the standalone conformance fixture verbatim: it creates real media,
  # saves a document through Elementor's own Document::save() pipeline, and
  # renders it once so Elementor's lazy derived keys are exercised before
  # capture. Keeping one fixture prevents the boundary matrix from drifting
  # into a weaker hand-written approximation.
  wp_conf1() { wp1 "$@"; }
  local CONF_REPO1="siterepo/${PAIR}1" package_tests
  local CONF1_PORT="$PORT1"
  local COMPOSE="$PAIR_COMPOSE_STRING"
  package_tests="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
  . "$(dirname "${BASH_SOURCE[0]}")/../conformance/seed.sh"
  unset -f wp_conf1
}

check_elementor_content() {
  wp_conf2() { wp2 "$@"; }
  local CONF_REPO1="siterepo/${PAIR}1" package_tests
  local CONF_REPO2="siterepo/${PAIR}2"
  local CONF1_PORT="$PORT1"
  local CONF2_PORT="$PORT2"
  local COMPOSE="$PAIR_COMPOSE_STRING"
  local ELEMENTOR_EXPECTED_VERSION="${ELEMENTOR_VERSION:-4.2.3}"
  local ELEMENTOR_BOUNDARY_ONLY=1
  package_tests="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
  . "$(dirname "${BASH_SOURCE[0]}")/../conformance/check.sh"
  unset -f wp_conf2
}

postdeploy_elementor_content() {
  wp_conf2() { wp2 "$@"; }
  local CONF_REPO1="siterepo/${PAIR}1" package_tests
  local CONF_REPO2="siterepo/${PAIR}2"
  local COMPOSE="$PAIR_COMPOSE_STRING"
  package_tests="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
  . "$(dirname "${BASH_SOURCE[0]}")/../conformance/postdeploy.sh"
  unset -f wp_conf2
}

VMATRIX_PLUGIN_SLUG=elementor

version_matrix_reset_before_empty() {
  local cli="$1"
  # Elementor stores the active kit as an option pointing at a post. `site
  # empty` removes that post, but the option can survive; deleting the
  # authored reference first keeps Elementor's own reset/shutdown hooks from
  # dereferencing a null post on the next exact-version install. This is
  # disposable matrix-fixture cleanup only, not a production-state policy.
  "$cli" option delete elementor_active_kit >/dev/null 2>&1 || true
}

run_elementor_command() {
  local command_log rc
  command_log=$(mktemp "${ELEMENTOR_STDERR_LOG}.command.XXXXXX")
  rc=0
  "$@" 2>"$command_log" || rc=$?
  cat "$command_log" >>"$ELEMENTOR_STDERR_LOG"
  cat "$command_log" >&2
  rm -f "$command_log"
  return "$rc"
}

version_matrix_workflow() {
VMATRIX_CASES=$((VMATRIX_CASES + 1))
for ELEMENTOR_VERSION in 4.0.0 4.2.3; do
  say "boundary: elementor $ELEMENTOR_VERSION"
  ELEMENTOR_STDERR_LOG=$(mktemp "${TMPDIR:-/tmp}/wprism-vmatrix-elementor.XXXXXX")

  run_elementor_command reset_env wp1
  run_elementor_command reset_env wp2
  reset_case_repositories

  say "fetch + verify elementor $ELEMENTOR_VERSION (never a bare slug install — always a digest-checked artifact)"
  ARTIFACT_1=$(run_elementor_command fetch_artifact elementor "$ELEMENTOR_VERSION" cli1)
  ARTIFACT_2=$(run_elementor_command fetch_artifact elementor "$ELEMENTOR_VERSION" cli2)
  pass "verified sha256-pinned artifact resolved for both sides: $ARTIFACT_1"

  run_elementor_command wp1 plugin install "$ARTIFACT_1" --activate >/dev/null
  INSTALLED_1=$(run_elementor_command wp1 plugin get elementor --field=version)
  [ "$INSTALLED_1" = "$ELEMENTOR_VERSION" ] || fail "side 1 installed version mismatch: expected $ELEMENTOR_VERSION, got $INSTALLED_1"
  pass "side 1: elementor $ELEMENTOR_VERSION installed from verified artifact, active"

  cat > "siterepo/${PAIR}1/site.wprism.json" <<EOF
{
  "manifests": ["core", "elementor"],
  "policy": {
    "options": {},
    "post_meta": {},
    "post_types": ["post", "page", "attachment", "elementor_library"],
    "taxonomies": ["category", "post_tag", "elementor_library_type"]
  },
  "spec_version": 2
}
EOF
  cp site-repo.gitignore.template "siterepo/${PAIR}1/.gitignore"
  "${GIT1[@]}" init -q -b main
  "${GIT1[@]}" remote add origin "../origin-$PAIR.git"
  "${GIT1[@]}" add -A
  "${GIT1[@]}" commit -qm "policy: elementor $ELEMENTOR_VERSION version-boundary certification"
  "${GIT1[@]}" push -qu origin main

  run_elementor_command seed_elementor_content

  run_elementor_command wp1 wprism capture --repo=/siterepo
  pass "captured on side 1 (elementor $ELEMENTOR_VERSION)"

  run_elementor_command wp1 wprism lint --repo=/siterepo
  pass "lint: 0 findings"

  "${GIT1[@]}" add -A
  "${GIT1[@]}" commit -qm "capture: elementor $ELEMENTOR_VERSION content"
  "${GIT1[@]}" push -q origin main

  clone_case_target
  run_elementor_command wp2 plugin install "$ARTIFACT_2" >/dev/null
  INSTALLED_2=$(run_elementor_command wp2 plugin get elementor --field=version)
  require_fixture_values INSTALLED_2
  [ "$INSTALLED_2" = "$ELEMENTOR_VERSION" ] || fail "side 2 installed version mismatch: expected $ELEMENTOR_VERSION, got $INSTALLED_2"

  run_elementor_command wp2 wprism deploy --repo=/siterepo
  run_elementor_command postdeploy_elementor_content
  REV=$(git -C "siterepo/${PAIR}2" rev-parse HEAD)
  run_elementor_command wp2 wprism apply --repo=/siterepo --adopt-by-slug=terms,posts --default-author=admin --revision="$REV" --format=json | tee "$VMATRIX_APPLY_LOG"
  require_wprism_answered "Elementor $ELEMENTOR_VERSION apply" json "$(cat "$VMATRIX_APPLY_LOG")"
  jq -e '.canary == "clean"' "$VMATRIX_APPLY_LOG" >/dev/null \
    || fail "apply canary not clean at elementor $ELEMENTOR_VERSION"
  pass "deploy + apply succeeded on side 2 (elementor $ELEMENTOR_VERSION, canary clean)"

  run_elementor_command check_elementor_content

  run_elementor_command wp2 wprism capture --repo=/siterepo --out="/siterepo/.tmp-final"
  DIFF_OUT=$(diff -rq "siterepo/${PAIR}1/state" "siterepo/${PAIR}2/.tmp-final" || true)
  rm -rf "siterepo/${PAIR}2/.tmp-final"
  [ -z "$DIFF_OUT" ] || fail "byte-identity broken at elementor $ELEMENTOR_VERSION: $DIFF_OUT"
  pass "byte-identical recapture at elementor $ELEMENTOR_VERSION — the manifest's own declared version_range boundary is proven, not just its currently-installed version"

  if [ "$ELEMENTOR_VERSION" = 4.0.0 ]; then
    say 'in-place upgrade: elementor 4.0.0 -> 4.2.3 on both existing environments'
    UPGRADE_ARTIFACT_1=$(run_elementor_command fetch_artifact elementor 4.2.3 cli1)
    UPGRADE_ARTIFACT_2=$(run_elementor_command fetch_artifact elementor 4.2.3 cli2)
    run_elementor_command wp1 plugin install "$UPGRADE_ARTIFACT_1" --force --activate >/dev/null
    [ "$(run_elementor_command wp1 plugin get elementor --field=version)" = 4.2.3 ] \
      || fail 'Elementor source in-place upgrade did not install exact 4.2.3'
    run_elementor_command wp1 wprism deploy --repo=/siterepo --force-code-drift >/dev/null
    UPGRADE_PAGE=$(run_elementor_command wp1 post list --post_type=page --name=wprism-conformance-elementor-page --field=ID)
    require_fixture_ids UPGRADE_PAGE
    run_elementor_command wp1 post update "$UPGRADE_PAGE" --post_title='Elementor 4.0.0 to 4.2.3 upgrade 東京 🚀' >/dev/null
    run_elementor_command wp1 wprism capture --repo=/siterepo
    run_elementor_command wp1 wprism lint --repo=/siterepo
    "${GIT1[@]}" add -A
    "${GIT1[@]}" commit -qm 'capture: elementor 4.0.0 to 4.2.3 in-place upgrade'
    "${GIT1[@]}" push -q origin main
    git -C "siterepo/${PAIR}2" pull -q origin main

    run_elementor_command wp2 plugin install "$UPGRADE_ARTIFACT_2" --force --activate >/dev/null
    [ "$(run_elementor_command wp2 plugin get elementor --field=version)" = 4.2.3 ] \
      || fail 'Elementor target in-place upgrade did not install exact 4.2.3'
    run_elementor_command wp2 wprism deploy --repo=/siterepo --force-code-drift
    REV=$(git -C "siterepo/${PAIR}2" rev-parse HEAD)
    run_elementor_command wp2 wprism apply --repo=/siterepo --default-author=admin --revision="$REV" --format=json | tee "$VMATRIX_APPLY_LOG"
    require_wprism_answered 'Elementor 4.0.0 to 4.2.3 upgrade apply' json "$(cat "$VMATRIX_APPLY_LOG")"
    jq -e '.canary == "clean" and .verification.result == "pass"' "$VMATRIX_APPLY_LOG" >/dev/null \
      || fail 'apply canary not clean after elementor 4.0.0 to 4.2.3 in-place upgrade'
    SAVED_ELEMENTOR_VERSION="$ELEMENTOR_VERSION"
    ELEMENTOR_VERSION=4.2.3
    run_elementor_command check_elementor_content
    ELEMENTOR_VERSION="$SAVED_ELEMENTOR_VERSION"

    run_elementor_command wp2 wprism capture --repo=/siterepo --out=/siterepo/.tmp-upgraded-final
    UPGRADE_DIFF=$(diff -rq "siterepo/${PAIR}1/state" "siterepo/${PAIR}2/.tmp-upgraded-final" || true)
    rm -rf "siterepo/${PAIR}2/.tmp-upgraded-final"
    [ -z "$UPGRADE_DIFF" ] || fail "Elementor 4.0.0 to 4.2.3 in-place upgrade lost byte identity: $UPGRADE_DIFF"
    pass 'elementor 4.0.0 -> 4.2.3 in-place upgrade preserves native rendering, regenerates CSS, and recaptures byte-identically'
  fi

  # The exact boundary must be warning-free. Keep stderr visible for normal
  # diagnostics, then reject the specific Elementor null-reference paths that
  # previously made the matrix green while human/exit status disagreed.
  ELEMENTOR_WARNING_MATCHES=$(grep -nE 'elementor/core/isolation/elementor-adapter\.php|elementor/core/base/document\.php|Elementor\\Core\\Isolation\\Elementor_Adapter' "$ELEMENTOR_STDERR_LOG" || true)
  if [ -n "$ELEMENTOR_WARNING_MATCHES" ]; then
    fail "unexpected Elementor PHP warning at exact $ELEMENTOR_VERSION boundary (captured stderr: $ELEMENTOR_STDERR_LOG):
$ELEMENTOR_WARNING_MATCHES"
  fi
  rm -f "$ELEMENTOR_STDERR_LOG"
done

say "negative control: elementor 3.35.9 (real wp.org release, genuinely below adapter-packages/elementor/package/manifest.json's own declared min 4.0.0) must be REFUSED, not silently accepted"
reset_env wp1
reset_case_repositories

OUT_OF_RANGE_ARTIFACT=$(fetch_artifact elementor 3.35.9 cli1)
wp1 plugin install "$OUT_OF_RANGE_ARTIFACT" --activate >/dev/null
INSTALLED_OOR=$(wp1 plugin get elementor --field=version)
[ "$INSTALLED_OOR" = "3.35.9" ] || fail "negative control: expected elementor 3.35.9 installed, got $INSTALLED_OOR"

cat > "siterepo/${PAIR}1/site.wprism.json" <<'EOF'
{
  "manifests": ["core", "elementor"],
  "policy": {
    "options": {},
    "post_meta": {},
    "post_types": ["post", "page", "attachment", "elementor_library"],
    "taxonomies": ["category", "post_tag", "elementor_library_type"]
  },
  "spec_version": 2
}
EOF
cp site-repo.gitignore.template "siterepo/${PAIR}1/.gitignore"
"${GIT1[@]}" init -q -b main
"${GIT1[@]}" remote add origin "../origin-$PAIR.git"
"${GIT1[@]}" add -A
"${GIT1[@]}" commit -qm "policy: elementor negative-control pin, out-of-range plugin installed"
"${GIT1[@]}" push -qu origin main

wp1 wprism capture --repo=/siterepo
"${GIT1[@]}" add -A
"${GIT1[@]}" commit -qm "capture: empty state, elementor 3.35.9 still installed"
"${GIT1[@]}" push -q origin main

set +e
DEPLOY_OUT=$(wp1 wprism deploy --repo=/siterepo 2>&1)
DEPLOY_RC=$?
set -e
[ "$DEPLOY_RC" -ne 0 ] || fail "expected deploy to refuse elementor 3.35.9 as outside_version_range, but it exited 0 (got: $DEPLOY_OUT)"
grep -Eq "outside_version_range|outside the '.*' manifest's declared version_range" <<<"$DEPLOY_OUT" \
  || fail "deploy refused, but not for the expected outside_version_range reason (got: $DEPLOY_OUT)"
grep -q "elementor/elementor.php" <<<"$DEPLOY_OUT" || fail "refusal did not name the plugin (got: $DEPLOY_OUT)"
grep -q "3.35.9" <<<"$DEPLOY_OUT" || fail "refusal did not name the actually-installed version (got: $DEPLOY_OUT)"
printf '%s\n' "$DEPLOY_OUT"
pass "confirmed: elementor 3.35.9 (real, installed, genuinely below the declared min) is loudly refused by Deploy::code_mismatch() — the version_range pin is honest, not just decorative"
}
