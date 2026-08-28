seed_polylang_content() {
  # Reuse the standalone three-language fixture verbatim: translated posts,
  # categories and media plus high source identities that rule out a stale-id
  # implementation passing by coincidence.
  wp_conf1() { wp1 "$@"; }
  local CONF_REPO1="siterepo/${PAIR}1"
  local COMPOSE="$PAIR_COMPOSE_STRING"
  local package_tests
  package_tests="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
  . "$package_tests/conformance/seed.sh"
  unset -f wp_conf1
}

check_polylang_content() {
  # The boundary matrix consumes the complete portable fixture/native
  # behavior but leaves the destructive hostile/lifecycle sequence to the
  # exact current-version conformance pair.
  wp_conf1() { wp1 "$@"; }
  wp_conf2() { wp2 "$@"; }
  local CONF_REPO1="siterepo/${PAIR}1"
  local CONF_REPO2="siterepo/${PAIR}2"
  local CONF1_PORT="$PORT1"
  local CONF2_PORT="$PORT2"
  local POLYLANG_BOUNDARY_ONLY=1
  local POLYLANG_EXPECTED_VERSION="$POLYLANG_VERSION"
  local APPLY_JSON="${POLYLANG_BOUNDARY_PROVIDER_RECEIPT:-}"
  local package_tests
  package_tests="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
  . "$package_tests/conformance/check.sh"
  unset -f wp_conf1 wp_conf2
}

VMATRIX_PLUGIN_SLUG=polylang

version_matrix_reset_after_delete() {
  local cli="$1"
  # Polylang's terms are removed by site empty while the plugin is active,
  # but authored/runtime options and language-cache transients deliberately
  # survive uninstall. Delete them so each boundary starts from activation.
  "$cli" db query "
    DELETE FROM wp_options
      WHERE option_name = 'polylang'
         OR option_name LIKE 'polylang_%'
         OR option_name LIKE 'widget_polylang%'
         OR option_name LIKE '%pll_languages_list%'
         OR option_name LIKE '%pll_activation_redirect%';
  " >/dev/null
}

version_matrix_workflow() {
VMATRIX_CASES=$((VMATRIX_CASES + 1))
for POLYLANG_VERSION in 3.8 3.8.7; do
  say "boundary: polylang $POLYLANG_VERSION"

  reset_env wp1
  reset_env wp2
  reset_case_repositories

  say "fetch + verify polylang $POLYLANG_VERSION (never a bare slug install — always a digest-checked artifact)"
  ARTIFACT_1=$(fetch_artifact polylang "$POLYLANG_VERSION" cli1)
  ARTIFACT_2=$(fetch_artifact polylang "$POLYLANG_VERSION" cli2)
  pass "verified sha256-pinned artifact resolved for both sides: $ARTIFACT_1"

  wp1 plugin install "$ARTIFACT_1" --activate >/dev/null
  INSTALLED_1=$(wp1 plugin get polylang --field=version)
  [ "$INSTALLED_1" = "$POLYLANG_VERSION" ] || fail "side 1 installed version mismatch: expected $POLYLANG_VERSION, got $INSTALLED_1"
  pass "side 1: polylang $POLYLANG_VERSION installed from verified artifact, active"

  cat > "siterepo/${PAIR}1/site.duo.json" <<EOF
{
  "manifests": ["core", "polylang"],
  "policy": {
    "options": {},
    "post_meta": {},
    "post_types": ["post", "page", "wp_block", "attachment"],
    "taxonomies": ["category", "post_tag", "language", "term_language", "term_translations", "post_translations"]
  },
  "spec_version": 2
}
EOF
  cp site-repo.gitignore.template "siterepo/${PAIR}1/.gitignore"
  "${GIT1[@]}" init -q -b main
  "${GIT1[@]}" remote add origin "../origin-$PAIR.git"
  "${GIT1[@]}" add -A
  "${GIT1[@]}" commit -qm "policy: polylang $POLYLANG_VERSION version-boundary certification"
  "${GIT1[@]}" push -qu origin main

  seed_polylang_content
  wp1 duo capture --repo=/siterepo
  pass "captured on side 1 (polylang $POLYLANG_VERSION)"
  wp1 duo lint --repo=/siterepo
  pass "lint: 0 findings"

  "${GIT1[@]}" add -A
  "${GIT1[@]}" commit -qm "capture: polylang $POLYLANG_VERSION content"
  "${GIT1[@]}" push -q origin main

  clone_case_target
  wp2 plugin install "$ARTIFACT_2" >/dev/null
  INSTALLED_2=$(wp2 plugin get polylang --field=version)
  require_fixture_values INSTALLED_2
  [ "$INSTALLED_2" = "$POLYLANG_VERSION" ] || fail "side 2 installed version mismatch: expected $POLYLANG_VERSION, got $INSTALLED_2"

  wp2 duo deploy --repo=/siterepo
  REV=$(git -C "siterepo/${PAIR}2" rev-parse HEAD)
  wp2 duo apply --repo=/siterepo --adopt-by-slug=terms,posts --default-author=admin --revision="$REV" 2>&1 | tee "$VMATRIX_APPLY_LOG"
  grep -q 'canary clean' "$VMATRIX_APPLY_LOG" || fail "apply canary not clean at polylang $POLYLANG_VERSION"
  assert_no_php_diagnostics "Polylang $POLYLANG_VERSION clean-target apply" "$VMATRIX_APPLY_LOG"
  POLYLANG_BOUNDARY_PROVIDER_RECEIPT=$(cat "$VMATRIX_APPLY_LOG")
  pass "deploy + apply succeeded on side 2 (polylang $POLYLANG_VERSION, canary clean)"

  check_polylang_content

  wp2 duo capture --repo=/siterepo --out="/siterepo/.tmp-final"
  DIFF_OUT=$(diff -rq "siterepo/${PAIR}1/state" "siterepo/${PAIR}2/.tmp-final" || true)
  rm -rf "siterepo/${PAIR}2/.tmp-final"
  [ -z "$DIFF_OUT" ] || fail "byte-identity broken at polylang $POLYLANG_VERSION: $DIFF_OUT"
  pass "byte-identical recapture at polylang $POLYLANG_VERSION — the manifest's own declared version_range boundary is proven, not just its currently-installed version"

  if [ "$POLYLANG_VERSION" = 3.8 ]; then
    say 'in-place upgrade: polylang 3.8 -> 3.8.7 on both populated environments'
    UPGRADE_ARTIFACT_1=$(fetch_artifact polylang 3.8.7 cli1)
    UPGRADE_ARTIFACT_2=$(fetch_artifact polylang 3.8.7 cli2)
    wp1 plugin install "$UPGRADE_ARTIFACT_1" --force --activate >/dev/null
    [ "$(wp1 plugin get polylang --field=version)" = 3.8.7 ] \
      || fail 'Polylang source in-place upgrade did not install exact 3.8.7'
    wp1 duo deploy --repo=/siterepo --force-code-drift >/dev/null
    UPGRADE_POST=$(jq -r '.posts.fr' "siterepo/${PAIR}1/.tmp-polylang-source.json")
    require_fixture_ids UPGRADE_POST
    wp1 post update "$UPGRADE_POST" --post_title='Polylang 3.8 vers 3.8.7 française 東京 🚀' >/dev/null
    wp1 duo capture --repo=/siterepo
    wp1 duo lint --repo=/siterepo
    "${GIT1[@]}" add -A
    "${GIT1[@]}" commit -qm 'capture: polylang 3.8 to 3.8.7 in-place upgrade'
    "${GIT1[@]}" push -q origin main
    git -C "siterepo/${PAIR}2" pull -q origin main
    wp2 plugin install "$UPGRADE_ARTIFACT_2" --force --activate >/dev/null
    [ "$(wp2 plugin get polylang --field=version)" = 3.8.7 ] \
      || fail 'Polylang target in-place upgrade did not install exact 3.8.7'
    wp2 duo deploy --repo=/siterepo --force-code-drift >/dev/null
    UPGRADE_REV=$(git -C "siterepo/${PAIR}2" rev-parse HEAD)
    wp2 duo apply --repo=/siterepo --default-author=admin --revision="$UPGRADE_REV" --format=json \
      2>&1 | tee "$VMATRIX_APPLY_LOG"
    assert_no_php_diagnostics 'Polylang 3.8 to 3.8.7 upgrade apply' "$VMATRIX_APPLY_LOG"
    UPGRADE_APPLY_JSON=$(awk '/^[{]/ { receipt=$0 } END { print receipt }' "$VMATRIX_APPLY_LOG")
    require_duo_answered 'Polylang 3.8 to 3.8.7 upgrade apply' json "$UPGRADE_APPLY_JSON"
    jq -e '.canary == "clean" and .verification.result == "pass"' <<<"$UPGRADE_APPLY_JSON" >/dev/null \
      || fail 'Polylang 3.8 -> 3.8.7 apply canary was not clean'
    SAVED_POLYLANG_VERSION="$POLYLANG_VERSION"
    POLYLANG_VERSION=3.8.7
    check_polylang_content
    POLYLANG_VERSION="$SAVED_POLYLANG_VERSION"
    UPGRADED_TITLE=$(wp2 post get "$UPGRADE_POST" --field=post_title 2>/dev/null || true)
    [ "$UPGRADED_TITLE" != 'Polylang 3.8 vers 3.8.7 française 東京 🚀' ] \
      || fail 'Polylang upgrade assertion accidentally consumed the source-local post id on the target'
    UPGRADED_TITLE=$(wp2 post list --post_type=post --name=portable-polylang-story-fr --field=post_title)
    [ "$UPGRADED_TITLE" = 'Polylang 3.8 vers 3.8.7 française 東京 🚀' ] \
      || fail "Polylang 3.8.7 did not consume the translated post authored under 3.8: $UPGRADED_TITLE"
    wp2 duo capture --repo=/siterepo --out=/siterepo/.tmp-polylang-upgraded-final
    UPGRADE_DIFF=$(diff -rq "siterepo/${PAIR}1/state" "siterepo/${PAIR}2/.tmp-polylang-upgraded-final" || true)
    rm -rf "siterepo/${PAIR}2/.tmp-polylang-upgraded-final"
    [ -z "$UPGRADE_DIFF" ] \
      || fail "Polylang 3.8 -> 3.8.7 in-place upgrade lost byte identity: $UPGRADE_DIFF"
    pass 'polylang 3.8 -> 3.8.7 in-place upgrade preserves native multilingual behavior, target-local identity, ordering and byte-identical state'
  fi
done

say "negative control: polylang 3.7 (real wp.org release, immediately below adapter-packages/polylang/package/manifest.json's corrected min 3.8) must be REFUSED, not silently accepted"
reset_env wp1
reset_case_repositories

# Build valid canonical state at the certified upper boundary, then replace
# only the installed plugin bytes. The refusal therefore proves the version
# gate against a real Polylang state tree rather than an empty repository.
IN_RANGE_ARTIFACT=$(fetch_artifact polylang 3.8.7 cli1)
wp1 plugin install "$IN_RANGE_ARTIFACT" --activate >/dev/null
NEGATIVE_INSTALLED=$(wp1 plugin get polylang --field=version)
require_fixture_values NEGATIVE_INSTALLED
[ "$NEGATIVE_INSTALLED" = "3.8.7" ] \
  || fail "negative control premise did not install exact polylang 3.8.7 bytes"
cat > "siterepo/${PAIR}1/site.duo.json" <<'EOF'
{
  "manifests": ["core", "polylang"],
  "policy": {
    "options": {},
    "post_meta": {},
    "post_types": ["post", "page", "wp_block", "attachment"],
    "taxonomies": ["category", "post_tag", "language", "term_language", "term_translations", "post_translations"]
  },
  "spec_version": 2
}
EOF
cp site-repo.gitignore.template "siterepo/${PAIR}1/.gitignore"
"${GIT1[@]}" init -q -b main
"${GIT1[@]}" remote add origin "../origin-$PAIR.git"
"${GIT1[@]}" add -A
"${GIT1[@]}" commit -qm "policy: polylang negative-control pin"
"${GIT1[@]}" push -qu origin main
seed_polylang_content
wp1 duo capture --repo=/siterepo
"${GIT1[@]}" add -A
"${GIT1[@]}" commit -qm "capture: valid Polylang state for negative control"
"${GIT1[@]}" push -q origin main

wp1 plugin deactivate polylang >/dev/null
wp1 plugin delete polylang >/dev/null
OUT_OF_RANGE_ARTIFACT=$(fetch_artifact polylang 3.7 cli1)
wp1 plugin install "$OUT_OF_RANGE_ARTIFACT" >/dev/null
INSTALLED_OOR=$(wp1 plugin get polylang --field=version)
[ "$INSTALLED_OOR" = "3.7" ] || fail "negative control: expected polylang 3.7 installed, got $INSTALLED_OOR"

set +e
DEPLOY_OUT=$(wp1 duo deploy --repo=/siterepo 2>&1)
DEPLOY_RC=$?
set -e
[ "$DEPLOY_RC" -ne 0 ] || fail "expected deploy to refuse polylang 3.7 as outside_version_range, but it exited 0 (got: $DEPLOY_OUT)"
grep -Eq "outside_version_range|outside the '.*' manifest's declared version_range" <<<"$DEPLOY_OUT" \
  || fail "deploy refused, but not for the expected outside_version_range reason (got: $DEPLOY_OUT)"
grep -q "polylang/polylang.php" <<<"$DEPLOY_OUT" || fail "refusal did not name the plugin (got: $DEPLOY_OUT)"
grep -q "3.7" <<<"$DEPLOY_OUT" || fail "refusal did not name the actually-installed version (got: $DEPLOY_OUT)"
printf '%s\n' "$DEPLOY_OUT"
pass "confirmed: polylang 3.7 (real, installed, immediately below the corrected 3.8 min) is loudly refused by Deploy::code_mismatch() — the version_range pin is honest, not just decorative"

# The declared upper bound has no real 3.8.8 release to install. Mutating
# ONLY the already-digest-verified 3.8.7 header makes WordPress's real plugin
# header parser report 3.8.8 while preserving the same canonical repository.
# This is the exact boundary Deploy compares, rather than a guessed semantic
# version helper or a synthetic manifest.
wp1 plugin delete polylang >/dev/null
UPPER_BOUND_ARTIFACT=$(fetch_artifact polylang 3.8.7 cli1)
wp1 plugin install "$UPPER_BOUND_ARTIFACT" --activate >/dev/null
[ "$(wp1 plugin get polylang --field=version)" = "3.8.7" ] \
  || fail 'synthetic upper-bound premise did not install exact Polylang 3.8.7'
POLY_SYNTHETIC_HEAD_BEFORE=$(git -C "siterepo/${PAIR}1" rev-parse HEAD)
wp1 eval '
$file = WP_PLUGIN_DIR . "/polylang/polylang.php";
$source = @file_get_contents($file);
if (!is_string($source)) throw new RuntimeException("synthetic Polylang header source is unreadable");
$updated = preg_replace("/^ \\* Version:[^\\r\\n]*$/m", " * Version:           3.8.8", $source, 1, $count);
if (!is_string($updated) || $count !== 1) throw new RuntimeException("synthetic Polylang version header replacement was not exact");
if (file_put_contents($file, $updated) !== strlen($updated)) throw new RuntimeException("synthetic Polylang version header write failed");
' >/dev/null
SYNTHETIC_UPPER_VERSION=$(wp1 plugin get polylang --field=version)
[ "$SYNTHETIC_UPPER_VERSION" = '3.8.8' ] \
  || fail "synthetic upper-bound premise did not expose the real plugin header as 3.8.8 (got: $SYNTHETIC_UPPER_VERSION)"
set +e
DEPLOY_OUT=$(wp1 duo deploy --repo=/siterepo 2>&1)
DEPLOY_RC=$?
set -e
[ "$DEPLOY_RC" -ne 0 ] \
  || fail "expected deploy to refuse synthetic Polylang 3.8.8 as outside_version_range, but it exited 0 (got: $DEPLOY_OUT)"
grep -Eq "outside_version_range|outside the '.*' manifest's declared version_range" <<<"$DEPLOY_OUT" \
  || fail "synthetic Polylang 3.8.8 refusal was not outside_version_range (got: $DEPLOY_OUT)"
grep -q 'polylang/polylang.php' <<<"$DEPLOY_OUT" \
  || fail "synthetic Polylang 3.8.8 refusal did not name the plugin (got: $DEPLOY_OUT)"
grep -q '3.8.8' <<<"$DEPLOY_OUT" \
  || fail "synthetic Polylang 3.8.8 refusal did not name the installed header version (got: $DEPLOY_OUT)"
[ "$(git -C "siterepo/${PAIR}1" rev-parse HEAD)" = "$POLY_SYNTHETIC_HEAD_BEFORE" ] \
  || fail 'synthetic Polylang 3.8.8 refusal mutated the repository ref'
wp1 plugin install "$UPPER_BOUND_ARTIFACT" --force --activate >/dev/null
[ "$(wp1 plugin get polylang --field=version)" = '3.8.7' ] \
  || fail 'synthetic Polylang 3.8.8 probe did not restore exact 3.8.7 artifact bytes'
pass 'synthetic Polylang 3.8.8 header is loudly refused at the exclusive upper bound without a repository mutation'
}
