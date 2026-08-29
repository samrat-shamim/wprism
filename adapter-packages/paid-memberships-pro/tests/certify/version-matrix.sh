seed_pmpro_content() {
  wp_conf1() { wp1 "$@"; }
  local CONF_REPO1="siterepo/${PAIR}1"
  local COMPOSE="$PAIR_COMPOSE_STRING"
  local package_tests
  package_tests="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
  . "$(dirname "${BASH_SOURCE[0]}")/../conformance/seed.sh"
  unset -f wp_conf1
}

check_pmpro_content() {
  wp_conf1() { wp1 "$@"; }
  wp_conf2() { wp2 "$@"; }
  local COMPOSE="$PAIR_COMPOSE_STRING"
  local CONF_REPO1="siterepo/${PAIR}1"
  local CONF_REPO2="siterepo/${PAIR}2"
  local CONF1_PORT="$PORT1"
  local CONF2_PORT="$PORT2"
  local PMPRO_EXPECTED_VERSION="${PMPRO_CHECK_VERSION:-$PMPRO_VERSION}"
  local PMPRO_BOUNDARY_ONLY=1
  local PMPRO_SKIP_FRONTEND=1
  local package_tests
  package_tests="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
  . "$(dirname "${BASH_SOURCE[0]}")/../conformance/check.sh"
}

postdeploy_pmpro_content() {
  wp_conf2() { wp2 "$@"; }
  local CONF_REPO2="siterepo/${PAIR}2"
  local COMPOSE="$PAIR_COMPOSE_STRING"
  local package_tests
  package_tests="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
  . "$(dirname "${BASH_SOURCE[0]}")/../conformance/postdeploy.sh"
  unset -f wp_conf2
}

VMATRIX_PLUGIN_SLUG=paid-memberships-pro

version_matrix_reset_after_delete() {
  local cli="$1"
  # Paid Memberships Pro deliberately retains authored/runtime tables and
  # options on ordinary deletion. Boundary cases must run the selected tag's
  # installer against an empty PMPro schema rather than inherit the prior tag.
  "$cli" eval '
    global $wpdb;
    $like = $wpdb->prefix . "pmpro\\_%";
    foreach ($wpdb->get_col($wpdb->prepare("SHOW TABLES LIKE %s", $like)) as $table) {
      $safe = str_replace("`", "``", $table);
      $wpdb->query("DROP TABLE IF EXISTS `{$safe}`");
    }
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\''pmpro_%'\'' OR option_name LIKE '\''_transient_pmpro_%'\'' OR option_name LIKE '\''_site_transient_pmpro_%'\''");
  ' >/dev/null
}

version_matrix_workflow() {
# PMPro is no longer distributed through wp.org. Both admitted official tags
# are digest-pinned boundaries; populated 3.8.2 sites additionally upgrade in
# place to 3.8.3. The adjacent official 3.8.1/3.8.4 tags refuse below.
VMATRIX_CASES=$((VMATRIX_CASES + 2))
for PMPRO_VERSION in 3.8.2 3.8.3; do
  say "boundary: paid-memberships-pro $PMPRO_VERSION"

  reset_env wp1
  reset_env wp2
  reset_case_repositories

  say "fetch + verify paid-memberships-pro $PMPRO_VERSION from the official upstream tag"
  ARTIFACT_1=$(fetch_artifact paid-memberships-pro "$PMPRO_VERSION" cli1)
  ARTIFACT_2=$(fetch_artifact paid-memberships-pro "$PMPRO_VERSION" cli2)
  pass "verified sha256-pinned upstream artifact resolved for both sides: $ARTIFACT_1"

  wp1 plugin install "$ARTIFACT_1" >/dev/null
  normalize_version_matrix_archive_root cli1 plugin paid-memberships-pro "paid-memberships-pro-$PMPRO_VERSION"
  wp1 plugin activate paid-memberships-pro >/dev/null
  INSTALLED_1=$(wp1 plugin get paid-memberships-pro --field=version)
  [ "$INSTALLED_1" = "$PMPRO_VERSION" ] || fail "side 1 installed version mismatch: expected $PMPRO_VERSION, got $INSTALLED_1"
  pass "side 1: paid-memberships-pro $PMPRO_VERSION installed from verified artifact, active"

  cat > "siterepo/${PAIR}1/site.wprism.json" <<EOF
{
  "manifests": ["core", "paid-memberships-pro"],
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
  "${GIT1[@]}" commit -qm "policy: paid-memberships-pro $PMPRO_VERSION exact-boundary certification"
  "${GIT1[@]}" push -qu origin main

  seed_pmpro_content
  wp1 wprism capture --repo=/siterepo
  pass "captured on side 1 (paid-memberships-pro $PMPRO_VERSION)"
  wp1 wprism lint --repo=/siterepo
  pass "lint: 0 findings"

  "${GIT1[@]}" add -A
  "${GIT1[@]}" commit -qm "capture: paid-memberships-pro $PMPRO_VERSION content"
  "${GIT1[@]}" push -q origin main

  clone_case_target
  wp2 plugin install "$ARTIFACT_2" >/dev/null
  normalize_version_matrix_archive_root cli2 plugin paid-memberships-pro "paid-memberships-pro-$PMPRO_VERSION"
  INSTALLED_2=$(wp2 plugin get paid-memberships-pro --field=version)
  require_fixture_values INSTALLED_2
  [ "$INSTALLED_2" = "$PMPRO_VERSION" ] || fail "side 2 installed version mismatch: expected $PMPRO_VERSION, got $INSTALLED_2"
  wp2 plugin is-active paid-memberships-pro >/dev/null 2>&1 && fail "PMPro target premise must begin inactive"

  wp2 wprism deploy --repo=/siterepo
  wp2 plugin is-active paid-memberships-pro >/dev/null || fail "deploy did not activate the admitted PMPro artifact"
  postdeploy_pmpro_content
  REV=$(git -C "siterepo/${PAIR}2" rev-parse HEAD)
  wp2 wprism apply --repo=/siterepo --adopt-by-slug=terms,posts --default-author=admin --revision="$REV" 2>&1 | tee "$VMATRIX_APPLY_LOG"
  grep -q 'canary clean' "$VMATRIX_APPLY_LOG" || fail "apply canary not clean at paid-memberships-pro $PMPRO_VERSION"
  pass "deploy + apply succeeded on side 2 (paid-memberships-pro $PMPRO_VERSION, hostile target, canary clean)"

  check_pmpro_content

  wp2 wprism capture --repo=/siterepo --out="/siterepo/.tmp-final"
  DIFF_OUT=$(diff -rq "siterepo/${PAIR}1/state" "siterepo/${PAIR}2/.tmp-final" || true)
  rm -rf "siterepo/${PAIR}2/.tmp-final"
  [ -z "$DIFF_OUT" ] || fail "byte-identity broken at paid-memberships-pro $PMPRO_VERSION: $DIFF_OUT"
  pass "byte-identical recapture at paid-memberships-pro $PMPRO_VERSION — all authored tables and native APIs are bound to exact upstream bytes"

  if [ "$PMPRO_VERSION" = 3.8.2 ]; then
    say 'in-place lifecycle: paid-memberships-pro 3.8.2 authored state -> exact 3.8.3 on both environments'
    UPGRADE_ARTIFACT_1=$(fetch_artifact paid-memberships-pro 3.8.3 cli1)
    UPGRADE_ARTIFACT_2=$(fetch_artifact paid-memberships-pro 3.8.3 cli2)
    wp1 plugin install "$UPGRADE_ARTIFACT_1" --force >/dev/null
    wp2 plugin install "$UPGRADE_ARTIFACT_2" --force >/dev/null
    normalize_version_matrix_archive_root cli1 plugin paid-memberships-pro paid-memberships-pro-3.8.3
    normalize_version_matrix_archive_root cli2 plugin paid-memberships-pro paid-memberships-pro-3.8.3
    [ "$(wp1 plugin get paid-memberships-pro --field=version)" = 3.8.3 ] \
      && [ "$(wp2 plugin get paid-memberships-pro --field=version)" = 3.8.3 ] \
      || fail 'PMPro in-place upgrade did not install exact 3.8.3 on both populated environments'
    wp1 wprism deploy --repo=/siterepo --force-code-drift >/dev/null
    wp2 wprism deploy --repo=/siterepo --force-code-drift >/dev/null
    wp1 eval '
      global $wpdb; $id=(int)$wpdb->get_var("SELECT id FROM {$wpdb->pmpro_membership_levels} WHERE name=\"Builder 東京 🚀\" AND initial_payment=19.95");
      update_pmpro_membership_level_meta($id,"membership_account_message","PMPro 3.8.2 to 3.8.3 upgrade 東京 🚀");
    ' >/dev/null
    wp1 wprism capture --repo=/siterepo
    wp1 wprism lint --repo=/siterepo
    "${GIT1[@]}" add -A
    "${GIT1[@]}" commit -qm 'capture: PMPro 3.8.2 to 3.8.3 in-place upgrade'
    "${GIT1[@]}" push -q origin main
    git -C "siterepo/${PAIR}2" pull -q origin main
    UPGRADE_REV=$(git -C "siterepo/${PAIR}2" rev-parse HEAD)
    wp2 wprism apply --repo=/siterepo --default-author=admin --revision="$UPGRADE_REV" 2>&1 | tee "$VMATRIX_APPLY_LOG"
    grep -q 'canary clean' "$VMATRIX_APPLY_LOG" || fail 'PMPro 3.8.2 -> 3.8.3 upgrade apply canary was not clean'
    PMPRO_CHECK_VERSION=3.8.3 check_pmpro_content
    unset PMPRO_CHECK_VERSION
    wp2 wprism capture --repo=/siterepo --out=/siterepo/.tmp-pmpro-upgrade-final
    UPGRADE_DIFF=$(diff -rq "siterepo/${PAIR}1/state" "siterepo/${PAIR}2/.tmp-pmpro-upgrade-final" || true)
    rm -rf "siterepo/${PAIR}2/.tmp-pmpro-upgrade-final"
    [ -z "$UPGRADE_DIFF" ] || fail "PMPro 3.8.2 -> 3.8.3 in-place upgrade lost byte identity: $UPGRADE_DIFF"
    pass 'PMPro 3.8.2 authored graph upgrades in place to 3.8.3 with native API, cache, identities, and byte identity intact'
  fi
done

say "negative controls: adjacent official PMPro tags 3.8.1 and 3.8.4 must both be refused by the audited 3.8.2/3.8.3 contract"
reset_env wp1
reset_case_repositories

# Capture valid canonical state with admitted bytes, then replace only the
# installed plugin. Both refusals therefore exercise Deploy::code_mismatch()
# against real PMPro table/reference content rather than an empty repository.
IN_RANGE_ARTIFACT=$(fetch_artifact paid-memberships-pro 3.8.3 cli1)
wp1 plugin install "$IN_RANGE_ARTIFACT" >/dev/null
normalize_version_matrix_archive_root cli1 plugin paid-memberships-pro paid-memberships-pro-3.8.3
wp1 plugin activate paid-memberships-pro >/dev/null
NEGATIVE_INSTALLED=$(wp1 plugin get paid-memberships-pro --field=version)
require_fixture_values NEGATIVE_INSTALLED
[ "$NEGATIVE_INSTALLED" = "3.8.3" ] \
  || fail "negative control premise did not install exact paid-memberships-pro 3.8.3 bytes"
cat > "siterepo/${PAIR}1/site.wprism.json" <<'EOF'
{
  "manifests": ["core", "paid-memberships-pro"],
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
"${GIT1[@]}" commit -qm "policy: paid-memberships-pro negative-control pin"
"${GIT1[@]}" push -qu origin main
seed_pmpro_content
wp1 wprism capture --repo=/siterepo
"${GIT1[@]}" add -A
"${GIT1[@]}" commit -qm "capture: valid PMPro state for adjacent-version refusals"
"${GIT1[@]}" push -q origin main

say "negative control: missing PMPro code refuses before lifecycle mutation"
wp1 plugin deactivate paid-memberships-pro >/dev/null 2>&1 || true
wp1 plugin delete paid-memberships-pro >/dev/null
set +e
DEPLOY_OUT=$(wp1 wprism deploy --repo=/siterepo 2>&1)
DEPLOY_RC=$?
set -e
[ "$DEPLOY_RC" -ne 0 ] || fail "expected deploy to refuse missing PMPro code, but it exited 0 (got: $DEPLOY_OUT)"
grep -Eq "code_mismatch|missing_in_code|is not installed" <<<"$DEPLOY_OUT" \
  || fail "missing PMPro refusal did not name the compatibility gate (got: $DEPLOY_OUT)"
grep -q "paid-memberships-pro/paid-memberships-pro.php" <<<"$DEPLOY_OUT" \
  || fail "missing PMPro refusal did not name the exact expected basename (got: $DEPLOY_OUT)"
if wp1 plugin is-installed paid-memberships-pro >/dev/null 2>&1; then
  fail "missing-code refusal installed PMPro before returning"
fi
pass "confirmed: absent PMPro code is refused and remains absent"

say "negative control: the right exact bytes under the wrong basename refuse rather than being guessed"
WRONG_BASENAME_ARTIFACT=$(fetch_artifact paid-memberships-pro 3.8.3 cli1)
wp1 plugin install "$WRONG_BASENAME_ARTIFACT" >/dev/null
WRONG_BASENAME_VERSION=$(wp1 plugin get paid-memberships-pro-3.8.3 --field=version)
[ "$WRONG_BASENAME_VERSION" = "3.8.3" ] \
  || fail "wrong-basename premise did not install official 3.8.3 bytes under the archive root"
set +e
DEPLOY_OUT=$(wp1 wprism deploy --repo=/siterepo 2>&1)
DEPLOY_RC=$?
set -e
[ "$DEPLOY_RC" -ne 0 ] || fail "expected deploy to refuse PMPro under the wrong basename, but it exited 0 (got: $DEPLOY_OUT)"
grep -Eq "code_mismatch|missing_in_code|is not installed" <<<"$DEPLOY_OUT" \
  || fail "wrong-basename PMPro refusal did not name the compatibility gate (got: $DEPLOY_OUT)"
grep -q "paid-memberships-pro/paid-memberships-pro.php" <<<"$DEPLOY_OUT" \
  || fail "wrong-basename PMPro refusal did not name the exact expected basename (got: $DEPLOY_OUT)"
wp1 plugin is-installed paid-memberships-pro-3.8.3 >/dev/null \
  || fail "wrong-basename refusal rewrote or removed the installed upstream directory"
if wp1 plugin is-active paid-memberships-pro-3.8.3 >/dev/null 2>&1; then
  fail "wrong-basename refusal activated unrecognized PMPro code"
fi
pass "confirmed: exact PMPro bytes under paid-memberships-pro-3.8.3 remain inactive and are never accepted as the canonical basename"
wp1 plugin delete paid-memberships-pro-3.8.3 >/dev/null

say "negative control: an unreadable exact main file refuses before activation"
UNREADABLE_ARTIFACT=$(fetch_artifact paid-memberships-pro 3.8.3 cli1)
wp1 plugin install "$UNREADABLE_ARTIFACT" >/dev/null
normalize_version_matrix_archive_root cli1 plugin paid-memberships-pro paid-memberships-pro-3.8.3
"${PAIR_COMPOSE[@]}" run --rm -T cli1 sh -c 'chmod 000 /var/www/html/wp-content/plugins/paid-memberships-pro/paid-memberships-pro.php'
set +e
DEPLOY_OUT=$(wp1 wprism deploy --repo=/siterepo 2>&1)
DEPLOY_RC=$?
set -e
"${PAIR_COMPOSE[@]}" run --rm -T cli1 sh -c 'chmod 0644 /var/www/html/wp-content/plugins/paid-memberships-pro/paid-memberships-pro.php'
[ "$DEPLOY_RC" -ne 0 ] || fail "expected deploy to refuse unreadable PMPro code, but it exited 0 (got: $DEPLOY_OUT)"
grep -Eq "code_mismatch|outside_version_range|unknown version|is not installed" <<<"$DEPLOY_OUT" \
  || fail "unreadable PMPro refusal did not name the compatibility gate (got: $DEPLOY_OUT)"
if wp1 plugin is-active paid-memberships-pro >/dev/null 2>&1; then
  fail "unreadable-plugin refusal activated PMPro before returning"
fi
pass "confirmed: unreadable PMPro main-file metadata refuses and remains inactive"
wp1 plugin delete paid-memberships-pro >/dev/null

for OUT_OF_RANGE_VERSION in 3.8.1 3.8.4; do
  wp1 plugin deactivate paid-memberships-pro >/dev/null 2>&1 || true
  wp1 plugin delete paid-memberships-pro >/dev/null
  OUT_OF_RANGE_ARTIFACT=$(fetch_artifact paid-memberships-pro "$OUT_OF_RANGE_VERSION" cli1)
  wp1 plugin install "$OUT_OF_RANGE_ARTIFACT" >/dev/null
  normalize_version_matrix_archive_root cli1 plugin paid-memberships-pro "paid-memberships-pro-$OUT_OF_RANGE_VERSION"
  INSTALLED_OOR=$(wp1 plugin get paid-memberships-pro --field=version)
  [ "$INSTALLED_OOR" = "$OUT_OF_RANGE_VERSION" ] \
    || fail "negative control: expected paid-memberships-pro $OUT_OF_RANGE_VERSION installed, got $INSTALLED_OOR"

  set +e
  DEPLOY_OUT=$(wp1 wprism deploy --repo=/siterepo 2>&1)
  DEPLOY_RC=$?
  set -e
  [ "$DEPLOY_RC" -ne 0 ] \
    || fail "expected deploy to refuse paid-memberships-pro $OUT_OF_RANGE_VERSION as outside_version_range, but it exited 0 (got: $DEPLOY_OUT)"
  grep -Eq "outside_version_range|outside the '.*' manifest's declared version_range" <<<"$DEPLOY_OUT" \
    || fail "deploy refused PMPro $OUT_OF_RANGE_VERSION, but not for outside_version_range (got: $DEPLOY_OUT)"
  grep -q "paid-memberships-pro/paid-memberships-pro.php" <<<"$DEPLOY_OUT" \
    || fail "PMPro refusal did not name the exact plugin basename (got: $DEPLOY_OUT)"
  grep -q "$OUT_OF_RANGE_VERSION" <<<"$DEPLOY_OUT" \
    || fail "PMPro refusal did not name installed version $OUT_OF_RANGE_VERSION (got: $DEPLOY_OUT)"
  if wp1 plugin is-active paid-memberships-pro >/dev/null 2>&1; then
    fail "outside-range PMPro $OUT_OF_RANGE_VERSION was activated before deploy refused"
  fi
  printf '%s\n' "$DEPLOY_OUT"
  pass "confirmed: official PMPro $OUT_OF_RANGE_VERSION is loudly refused and remains inactive outside exact range >=3.8.2 <3.8.4"
done
}
