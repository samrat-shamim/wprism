seed_yoast_content() {
  # Reuse the standalone Yoast fixture verbatim: two categories, authored
  # post SEO metadata, term metadata, four media attachments, and the
  # wpseo_titles/wpseo_social sub-key references, all written through the
  # same public Yoast APIs exercised by real settings saves.
  wp_conf1() { wp1 "$@"; }
  wp_env() {
    local env="$1"; shift
    case "$env" in
      conf1) wp1 "$@" ;;
      conf2) wp2 "$@" ;;
      *) fail "unknown Yoast seed environment: $env" ;;
    esac
  }
  local CONF_REPO1="siterepo/${PAIR}1"
  local CONF1_PORT="$PORT1"
  local COMPOSE="$PAIR_COMPOSE_STRING"
  . "$(dirname "${BASH_SOURCE[0]}")/../conformance/seed.sh"
  unset -f wp_conf1 wp_env
}

check_yoast_content() {
  local COMPOSE="$PAIR_COMPOSE_STRING"
  local CONF_REPO1="siterepo/${PAIR}1"
  local CONF_REPO2="siterepo/${PAIR}2"
  local CONF1_PORT="$PORT1"
  local CONF2_PORT="$PORT2"
  local YOAST_EXPECTED_VERSION="$YOAST_VERSION"
  local YOAST_BOUNDARY_ONLY=1
  . "$(dirname "${BASH_SOURCE[0]}")/../conformance/check.sh"
}

postdeploy_yoast_content() {
  wp_conf2() { wp2 "$@"; }
  local CONF_REPO1="siterepo/${PAIR}1"
  local CONF_REPO2="siterepo/${PAIR}2"
  local COMPOSE="$PAIR_COMPOSE_STRING"
  . "$(dirname "${BASH_SOURCE[0]}")/../conformance/postdeploy.sh"
  unset -f wp_conf2
}

VMATRIX_PLUGIN_SLUG=wordpress-seo

version_matrix_reset_after_delete() {
  local cli="$1"
  # Yoast keeps its indexables/migration schema and wpseo option families on
  # ordinary plugin deletion. Remove both so each boundary executes that
  # release's own install/migration path and cannot inherit a newer schema.
  "$cli" eval '
    global $wpdb;
    $like = $wpdb->prefix . "yoast\\_%";
    foreach ($wpdb->get_col($wpdb->prepare("SHOW TABLES LIKE %s", $like)) as $table) {
      $safe = str_replace("`", "``", $table);
      $wpdb->query("DROP TABLE IF EXISTS `{$safe}`");
    }
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\''wpseo%'\'' OR option_name LIKE '\''yoast_%'\'' OR option_name LIKE '\''_transient_%yoast%'\'' OR option_name LIKE '\''_site_transient_%yoast%'\''");
  ' >/dev/null
}

version_matrix_workflow() {
# Yoast's published 28.x line has two real stable boundaries: 28.0 is the
# first release admitted by the manifest's exact 28.0 minimum, and 28.3 is
# the current release below 29.0.0. Exercise both exact
# artifacts; a current-slug install would prove neither boundary.
VMATRIX_CASES=$((VMATRIX_CASES + 1))
for YOAST_VERSION in 28.0 28.3; do
  say "boundary: wordpress-seo $YOAST_VERSION"

  reset_env wp1
  reset_env wp2
  reset_case_repositories

  say "fetch + verify wordpress-seo $YOAST_VERSION (never a bare slug install — always a digest-checked artifact)"
  ARTIFACT_1=$(fetch_artifact wordpress-seo "$YOAST_VERSION" cli1)
  ARTIFACT_2=$(fetch_artifact wordpress-seo "$YOAST_VERSION" cli2)
  pass "verified sha256-pinned artifact resolved for both sides: $ARTIFACT_1"

  wp1 plugin install "$ARTIFACT_1" --activate >/dev/null
  INSTALLED_1=$(wp1 plugin get wordpress-seo --field=version)
  [ "$INSTALLED_1" = "$YOAST_VERSION" ] || fail "side 1 installed version mismatch: expected $YOAST_VERSION, got $INSTALLED_1"
  pass "side 1: wordpress-seo $YOAST_VERSION installed from verified artifact, active"

  cat > "siterepo/${PAIR}1/site.duo.json" <<EOF
{
  "manifests": ["core", "yoast"],
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
  "${GIT1[@]}" commit -qm "policy: wordpress-seo $YOAST_VERSION version-boundary certification"
  "${GIT1[@]}" push -qu origin main

  seed_yoast_content
  wp1 duo capture --repo=/siterepo
  pass "captured on side 1 (wordpress-seo $YOAST_VERSION)"
  wp1 duo lint --repo=/siterepo
  pass "lint: 0 findings"

  "${GIT1[@]}" add -A
  "${GIT1[@]}" commit -qm "capture: wordpress-seo $YOAST_VERSION content"
  "${GIT1[@]}" push -q origin main

  clone_case_target
  wp2 plugin install "$ARTIFACT_2" >/dev/null
  INSTALLED_2=$(wp2 plugin get wordpress-seo --field=version)
  require_fixture_values INSTALLED_2
  [ "$INSTALLED_2" = "$YOAST_VERSION" ] || fail "side 2 installed version mismatch: expected $YOAST_VERSION, got $INSTALLED_2"

  wp2 duo deploy --repo=/siterepo
  postdeploy_yoast_content
  REV=$(git -C "siterepo/${PAIR}2" rev-parse HEAD)
  wp2 duo apply --repo=/siterepo --adopt-by-slug=terms,posts --default-author=admin --revision="$REV" 2>&1 | tee "$VMATRIX_APPLY_LOG"
  grep -q 'canary clean' "$VMATRIX_APPLY_LOG" || fail "apply canary not clean at wordpress-seo $YOAST_VERSION"
  pass "deploy + apply succeeded on side 2 (wordpress-seo $YOAST_VERSION, canary clean)"

  check_yoast_content

  wp2 duo capture --repo=/siterepo --out="/siterepo/.tmp-final"
  DIFF_OUT=$(diff -rq "siterepo/${PAIR}1/state" "siterepo/${PAIR}2/.tmp-final" || true)
  rm -rf "siterepo/${PAIR}2/.tmp-final"
  [ -z "$DIFF_OUT" ] || fail "byte-identity broken at wordpress-seo $YOAST_VERSION: $DIFF_OUT"
  pass "byte-identical recapture at wordpress-seo $YOAST_VERSION — the manifest's own declared version_range boundary is proven, not just its currently-installed version"

  if [ "$YOAST_VERSION" = 28.0 ]; then
    say 'in-place upgrade: wordpress-seo 28.0 -> 28.3 on both existing environments'
    UPGRADE_ARTIFACT_1=$(fetch_artifact wordpress-seo 28.3 cli1)
    UPGRADE_ARTIFACT_2=$(fetch_artifact wordpress-seo 28.3 cli2)
    wp1 plugin install "$UPGRADE_ARTIFACT_1" --force --activate >/dev/null
    [ "$(wp1 plugin get wordpress-seo --field=version)" = 28.3 ] \
      || fail 'Yoast source in-place upgrade did not install exact 28.3'
    wp1 duo deploy --repo=/siterepo --force-code-drift >/dev/null
    UPGRADE_POST=$(wp1 post list --post_type=post --name=conformance-yoast-post --field=ID)
    require_fixture_ids UPGRADE_POST
    wp1 post meta update "$UPGRADE_POST" _yoast_wpseo_twitter-title 'Yoast 28.0 to 28.3 upgrade 東京 🚀' >/dev/null
    wp1 duo capture --repo=/siterepo
    wp1 duo lint --repo=/siterepo
    "${GIT1[@]}" add -A
    "${GIT1[@]}" commit -qm 'capture: wordpress-seo 28.0 to 28.3 in-place upgrade'
    "${GIT1[@]}" push -q origin main
    git -C "siterepo/${PAIR}2" pull -q origin main

    wp2 plugin install "$UPGRADE_ARTIFACT_2" --force --activate >/dev/null
    [ "$(wp2 plugin get wordpress-seo --field=version)" = 28.3 ] \
      || fail 'Yoast target in-place upgrade did not install exact 28.3'
    wp2 duo deploy --repo=/siterepo --force-code-drift
    REV=$(git -C "siterepo/${PAIR}2" rev-parse HEAD)
    wp2 duo apply --repo=/siterepo --default-author=admin --revision="$REV" 2>&1 | tee "$VMATRIX_APPLY_LOG"
    grep -q 'canary clean' "$VMATRIX_APPLY_LOG" \
      || fail 'apply canary not clean after wordpress-seo 28.0 to 28.3 in-place upgrade'
    SAVED_YOAST_VERSION="$YOAST_VERSION"
    YOAST_VERSION=28.3
    check_yoast_content
    YOAST_VERSION="$SAVED_YOAST_VERSION"

    wp2 duo capture --repo=/siterepo --out=/siterepo/.tmp-upgraded-final
    UPGRADE_DIFF=$(diff -rq "siterepo/${PAIR}1/state" "siterepo/${PAIR}2/.tmp-upgraded-final" || true)
    rm -rf "siterepo/${PAIR}2/.tmp-upgraded-final"
    [ -z "$UPGRADE_DIFF" ] || fail "Yoast 28.0 to 28.3 in-place upgrade lost byte identity: $UPGRADE_DIFF"
    pass 'wordpress-seo 28.0 -> 28.3 in-place upgrade preserves native behavior, reindexes, and recaptures byte-identically'
  fi
done

say "negative control: wordpress-seo 27.9 (real wp.org release, closest stable below adapter-packages/yoast/package/manifest.json's min 28.0) must be REFUSED, not silently accepted"
reset_env wp1
reset_case_repositories

# Capture a valid admitted 28.0 state, then replace only the installed code
# with 27.9. That isolates Deploy::code_mismatch() from unsupported old-code
# seed/schema behavior and proves the manifest boundary itself is enforced.
IN_RANGE_ARTIFACT=$(fetch_artifact wordpress-seo 28.0 cli1)
wp1 plugin install "$IN_RANGE_ARTIFACT" --activate >/dev/null
NEGATIVE_INSTALLED=$(wp1 plugin get wordpress-seo --field=version)
require_fixture_values NEGATIVE_INSTALLED
[ "$NEGATIVE_INSTALLED" = "28.0" ] \
  || fail "negative control premise did not install exact wordpress-seo 28.0 bytes"
cat > "siterepo/${PAIR}1/site.duo.json" <<'EOF'
{
  "manifests": ["core", "yoast"],
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
"${GIT1[@]}" commit -qm "policy: wordpress-seo negative-control pin"
"${GIT1[@]}" push -qu origin main
seed_yoast_content
wp1 duo capture --repo=/siterepo
"${GIT1[@]}" add -A
"${GIT1[@]}" commit -qm "capture: valid Yoast state for negative control"
"${GIT1[@]}" push -q origin main

wp1 plugin deactivate wordpress-seo >/dev/null
wp1 plugin delete wordpress-seo >/dev/null
OUT_OF_RANGE_ARTIFACT=$(fetch_artifact wordpress-seo 27.9 cli1)
wp1 plugin install "$OUT_OF_RANGE_ARTIFACT" >/dev/null
INSTALLED_OOR=$(wp1 plugin get wordpress-seo --field=version)
[ "$INSTALLED_OOR" = "27.9" ] || fail "negative control: expected wordpress-seo 27.9 installed, got $INSTALLED_OOR"

set +e
DEPLOY_OUT=$(wp1 duo deploy --repo=/siterepo 2>&1)
DEPLOY_RC=$?
set -e
[ "$DEPLOY_RC" -ne 0 ] || fail "expected deploy to refuse wordpress-seo 27.9 as outside_version_range, but it exited 0 (got: $DEPLOY_OUT)"
grep -Eq "outside_version_range|outside the '.*' manifest's declared version_range" <<<"$DEPLOY_OUT" \
  || fail "deploy refused, but not for the expected outside_version_range reason (got: $DEPLOY_OUT)"
grep -q "wordpress-seo/wp-seo.php" <<<"$DEPLOY_OUT" || fail "refusal did not name the plugin (got: $DEPLOY_OUT)"
grep -q "27.9" <<<"$DEPLOY_OUT" || fail "refusal did not name the actually-installed version (got: $DEPLOY_OUT)"
printf '%s\n' "$DEPLOY_OUT"
pass "confirmed: wordpress-seo 27.9 (real, installed, closest stable below the declared min) is loudly refused by Deploy::code_mismatch() — the version_range pin is honest, not decorative"
}
