seed_the_events_calendar_content() {
  # The standalone fixture uses TEC's repositories, Category Colors API, and
  # settings API. Reusing it here keeps the exact-version proof on the same
  # native graph and difficult-value surface as the isolated conformance run.
  wp_conf1() { wp1 "$@"; }
  local CONF_REPO1="siterepo/${PAIR}1"
  local COMPOSE="$PAIR_COMPOSE_STRING"
  local package_tests
  package_tests="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
  . "$(dirname "${BASH_SOURCE[0]}")/../conformance/seed.sh"
  unset -f wp_conf1
}

postdeploy_the_events_calendar_content() {
  wp_conf2() { wp2 "$@"; }
  local CONF_REPO2="siterepo/${PAIR}2"
  local COMPOSE="$PAIR_COMPOSE_STRING"
  local package_tests
  package_tests="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
  . "$(dirname "${BASH_SOURCE[0]}")/../conformance/postdeploy.sh"
  unset -f wp_conf2
}

check_the_events_calendar_boundary_content() {
  wp_conf1() { wp1 "$@"; }
  wp_conf2() { wp2 "$@"; }
  wp_env() {
    local env="$1"; shift
    case "$env" in
      conf1) wp1 "$@" ;;
      conf2) wp2 "$@" ;;
      *) fail "unknown TEC check environment: $env" ;;
    esac
  }
  local CONF_REPO1="siterepo/${PAIR}1"
  local CONF_REPO2="siterepo/${PAIR}2"
  local CONF1_PORT="$PORT1"
  local CONF2_PORT="$PORT2"
  local COMPOSE="$PAIR_COMPOSE_STRING"
  local TEC_EXPECTED_VERSION="${TEC_VERSION:-6.17.3}"
  local TEC_PRESERVE_ID_FIXTURES=1
  local TEC_POST_UPGRADE_ONLY="${TEC_POST_UPGRADE_ONLY:-0}"
  local TEC_BOUNDARY_ONLY=0
  if [ "$TEC_EXPECTED_VERSION" != 6.17.2 ]; then
    TEC_BOUNDARY_ONLY=1
  fi
  local package_tests
  package_tests="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
  . "$(dirname "${BASH_SOURCE[0]}")/../conformance/check.sh"
  unset -f wp_conf1 wp_conf2 wp_env
}

postapply_the_events_calendar_content() {
  wp_conf2() { wp2 "$@"; }
  local CONF_REPO2="siterepo/${PAIR}2"
  local package_tests
  package_tests="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
  . "$(dirname "${BASH_SOURCE[0]}")/../conformance/postapply.sh"
  unset -f wp_conf2
}

VMATRIX_PLUGIN_SLUG=the-events-calendar

version_matrix_reset_after_delete() {
  local cli="$1"
  # TEC retains authored rows, its Custom Tables V1 projections, and almost
  # all setup/runtime options when code is deleted. Reset those disposable
  # matrix residues so each admitted release runs its own installer and no
  # 6.17.2 schema or cached migration marker can make 6.17.3 look healthy.
  "$cli" db query "
    DROP TABLE IF EXISTS wp_tec_events, wp_tec_occurrences, wp_tec_kv_cache;
    DELETE FROM wp_options
      WHERE option_name LIKE 'tribe_%'
         OR option_name LIKE 'tec_%'
         OR option_name LIKE 'stellarwp_%'
         OR option_name LIKE 'stellar_schema_version_%';
  " >/dev/null
  # The Events Calendar's Custom Tables v1 schema, its schema-version and
  # one-time-migration bookkeeping, and its kv cache all survive ordinary
  # plugin deletion (adapter-packages/the-events-calendar/package/manifest.json classifies exactly
  # these as env/runtime/derived). `site empty` deletes the tribe_events
  # posts but not their tec_occurrences rows, and the target-only cache row
  # conformance/postdeploy/the-events-calendar.sh plants
  # ('duo-readiness-target-only') would otherwise survive into the next
  # boundary iteration and satisfy that iteration's own runtime-preservation
  # assertion without this run having preserved anything.
  "$cli" eval '
    global $wpdb;
    foreach (["tec\\_%"] as $suffix) {
      $like = $wpdb->prefix . $suffix;
      foreach ($wpdb->get_col($wpdb->prepare("SHOW TABLES LIKE %s", $like)) as $table) {
        $safe = str_replace("`", "``", $table);
        $wpdb->query("DROP TABLE IF EXISTS `{$safe}`");
      }
    }
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '"'"'tec\_%'"'"' OR option_name LIKE '"'"'tribe\_%'"'"' OR option_name LIKE '"'"'stellar\_schema\_version\_%'"'"' OR option_name LIKE '"'"'stellarwp\_telemetry%'"'"' OR option_name LIKE '"'"'_transient\_tribe\_%'"'"' OR option_name LIKE '"'"'_site\_transient\_tribe\_%'"'"'");
  ' >/dev/null
}

version_matrix_workflow() {
# One candidate-bound pass executes every real-world standalone scenario on
# 6.17.2 and the native boundary on 6.17.3; standalone conformance owns the
# full 6.17.3 run. Keeping the seed, target, and check helpers singular is part
# of the evidence contract: a
# later duplicate definition can silently replace the product-path check,
# while a duplicate loop spends the pair budget without adding a boundary.
VMATRIX_CASES=$((VMATRIX_CASES + 1))
for TEC_VERSION in 6.17.2 6.17.3; do
  say "boundary: the-events-calendar $TEC_VERSION"

  reset_env wp1
  reset_env wp2
  reset_case_repositories

  say "fetch + verify the-events-calendar $TEC_VERSION (digest-checked artifact only)"
  TEC_ARTIFACT_1=$(fetch_artifact the-events-calendar "$TEC_VERSION" cli1)
  TEC_ARTIFACT_2=$(fetch_artifact the-events-calendar "$TEC_VERSION" cli2)
  wp1 plugin install "$TEC_ARTIFACT_1" --activate >/dev/null
  TEC_INSTALLED_1=$(wp1 plugin get the-events-calendar --field=version)
  [ "$TEC_INSTALLED_1" = "$TEC_VERSION" ] \
    || fail "side 1 installed version mismatch: expected $TEC_VERSION, got $TEC_INSTALLED_1"
  pass "side 1: the-events-calendar $TEC_VERSION installed from verified artifact, active"

  cat > "siterepo/${PAIR}1/site.duo.json" <<'EOF'
{
  "manifests": ["core", "the-events-calendar"],
  "policy": {
    "options": {},
    "post_meta": {},
    "post_types": ["post", "page", "attachment", "tribe_events", "tribe_venue", "tribe_organizer"],
    "taxonomies": ["category", "post_tag", "tribe_events_cat"]
  },
  "spec_version": 2
}
EOF
  cp site-repo.gitignore.template "siterepo/${PAIR}1/.gitignore"
  "${GIT1[@]}" init -q -b main
  "${GIT1[@]}" remote add origin "../origin-$PAIR.git"
  "${GIT1[@]}" add -A
  "${GIT1[@]}" commit -qm "policy: The Events Calendar $TEC_VERSION exact-boundary certification"
  "${GIT1[@]}" push -qu origin main

  seed_the_events_calendar_content
  wp1 duo capture --repo=/siterepo
  wp1 duo lint --repo=/siterepo
  "${GIT1[@]}" add -A
  "${GIT1[@]}" commit -qm "capture: The Events Calendar $TEC_VERSION native graph"
  "${GIT1[@]}" push -q origin main

  clone_case_target
  wp2 plugin install "$TEC_ARTIFACT_2" >/dev/null
  TEC_INSTALLED_2=$(wp2 plugin get the-events-calendar --field=version)
  require_fixture_values TEC_INSTALLED_2
  [ "$TEC_INSTALLED_2" = "$TEC_VERSION" ] \
    || fail "side 2 installed version mismatch: expected $TEC_VERSION, got $TEC_INSTALLED_2"
  wp2 plugin is-active the-events-calendar >/dev/null 2>&1 \
    && fail "TEC $TEC_VERSION target premise must begin inactive"

  wp2 duo deploy --repo=/siterepo
  wp2 plugin is-active the-events-calendar >/dev/null \
    || fail "deploy did not activate the admitted TEC $TEC_VERSION artifact"
  postdeploy_the_events_calendar_content
  REV=$(git -C "siterepo/${PAIR}2" rev-parse HEAD)
  wp2 duo apply --repo=/siterepo --adopt-by-slug=terms,posts --default-author=admin --revision="$REV" 2>&1 | tee "$VMATRIX_APPLY_LOG"
  grep -q 'canary clean' "$VMATRIX_APPLY_LOG" \
    || fail "apply canary not clean at the-events-calendar $TEC_VERSION"
  postapply_the_events_calendar_content
  check_the_events_calendar_boundary_content

  wp2 duo capture --repo=/siterepo --out=/siterepo/.tmp-final
  TEC_DIFF=$(diff -rq "siterepo/${PAIR}1/state" "siterepo/${PAIR}2/.tmp-final" || true)
  rm -rf "siterepo/${PAIR}2/.tmp-final"
  [ -z "$TEC_DIFF" ] \
    || fail "byte-identity broken at the-events-calendar $TEC_VERSION: $TEC_DIFF"
  pass "The Events Calendar $TEC_VERSION deploys, adopts hostile identities, repairs projections, renders natively, and recaptures byte-identically"

  if [ "$TEC_VERSION" = 6.17.2 ]; then
    # Both admitted artifacts carry byte-identical Custom Tables V1 code, but
    # the populated in-place path still owns installer/migration and code-
    # baseline behavior that two fresh installs cannot prove.
    TEC_UPGRADE_1=$(fetch_artifact the-events-calendar 6.17.3 cli1)
    TEC_UPGRADE_2=$(fetch_artifact the-events-calendar 6.17.3 cli2)
    wp1 plugin install "$TEC_UPGRADE_1" --force --activate >/dev/null
    wp2 plugin install "$TEC_UPGRADE_2" --force --activate >/dev/null
    [ "$(wp1 plugin get the-events-calendar --field=version)" = 6.17.3 ] \
      && [ "$(wp2 plugin get the-events-calendar --field=version)" = 6.17.3 ] \
      || fail "TEC supported in-place upgrade did not install 6.17.3 on both populated sides"

    TEC_UPGRADE_DEPLOY_RC=0
    TEC_UPGRADE_DEPLOY_OUT=$(wp2 duo deploy --repo=/siterepo 2>&1) || TEC_UPGRADE_DEPLOY_RC=$?
    require_duo_answered "TEC out-of-band 6.17.2 to 6.17.3 upgrade refusal" human "$TEC_UPGRADE_DEPLOY_OUT"
    [ "$TEC_UPGRADE_DEPLOY_RC" -ne 0 ] \
      && grep -q 'deploy refused — code_drift' <<<"$TEC_UPGRADE_DEPLOY_OUT" \
      && grep -q 'recorded 6.17.2' <<<"$TEC_UPGRADE_DEPLOY_OUT" \
      && grep -q 'is 6.17.3 on this environment' <<<"$TEC_UPGRADE_DEPLOY_OUT" \
      || fail "TEC out-of-band upgrade did not refuse at the exact code-drift boundary: $TEC_UPGRADE_DEPLOY_OUT"
    wp2 duo deploy --repo=/siterepo --force-code-drift >/dev/null
    TEC_UPGRADE_PLAN=$(wp2 duo plan --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
    require_duo_answered "TEC 6.17.2 to 6.17.3 target plan" json "$TEC_UPGRADE_PLAN"
    jq -e '([.create,.update,.drift,.conflict,.collision,.delete,.delete_conflict] | map(length) | add) == 0' <<<"$TEC_UPGRADE_PLAN" >/dev/null \
      || fail "TEC supported in-place upgrade invented authored work: $TEC_UPGRADE_PLAN"

    TEC_POST_UPGRADE_ONLY=1 TEC_VERSION=6.17.3 check_the_events_calendar_boundary_content
    wp1 duo capture --repo=/siterepo --out=/siterepo/.tmp-tec-upgrade-source
    wp2 duo capture --repo=/siterepo --out=/siterepo/.tmp-tec-upgrade-target
    TEC_UPGRADE_SOURCE_DIFF=$(diff -rq "siterepo/${PAIR}1/state" "siterepo/${PAIR}1/.tmp-tec-upgrade-source" || true)
    TEC_UPGRADE_TARGET_DIFF=$(diff -rq "siterepo/${PAIR}1/state" "siterepo/${PAIR}2/.tmp-tec-upgrade-target" || true)
    rm -rf "siterepo/${PAIR}1/.tmp-tec-upgrade-source" "siterepo/${PAIR}2/.tmp-tec-upgrade-target"
    [ -z "$TEC_UPGRADE_SOURCE_DIFF" ] && [ -z "$TEC_UPGRADE_TARGET_DIFF" ] \
      || fail "TEC populated in-place upgrade changed canonical state: source=$TEC_UPGRADE_SOURCE_DIFF target=$TEC_UPGRADE_TARGET_DIFF"
    pass "TEC populated 6.17.2 sites upgrade in place to 6.17.3 with exact native behavior, no authored drift, and explicit code-baseline authority"
    TEC_VERSION=6.17.2
  fi
  rm -f "siterepo/${PAIR}1/.tmp-tec-source-ids.json" "siterepo/${PAIR}2/.tmp-tec-target-ids.json"
done

say "negative control: official The Events Calendar 6.17.1 is below the reviewed 6.17.2 floor and must refuse before activation"
reset_env wp1
reset_case_repositories

# Capture a native graph under admitted 6.17.3 bytes, then replace only the
# installed code. The refusal therefore exercises the compatibility boundary
# against representative TEC references/settings rather than an empty repo.
TEC_IN_RANGE_ARTIFACT=$(fetch_artifact the-events-calendar 6.17.3 cli1)
wp1 plugin install "$TEC_IN_RANGE_ARTIFACT" --activate >/dev/null
[ "$(wp1 plugin get the-events-calendar --field=version)" = 6.17.3 ] \
  || fail "TEC negative-control premise did not install exact 6.17.3 bytes"
cat > "siterepo/${PAIR}1/site.duo.json" <<'EOF'
{
  "manifests": ["core", "the-events-calendar"],
  "policy": {
    "options": {},
    "post_meta": {},
    "post_types": ["post", "page", "attachment", "tribe_events", "tribe_venue", "tribe_organizer"],
    "taxonomies": ["category", "post_tag", "tribe_events_cat"]
  },
  "spec_version": 2
}
EOF
cp site-repo.gitignore.template "siterepo/${PAIR}1/.gitignore"
"${GIT1[@]}" init -q -b main
"${GIT1[@]}" remote add origin "../origin-$PAIR.git"
"${GIT1[@]}" add -A
"${GIT1[@]}" commit -qm "policy: The Events Calendar adjacent-version refusal"
"${GIT1[@]}" push -qu origin main
seed_the_events_calendar_content
wp1 duo capture --repo=/siterepo
"${GIT1[@]}" add -A
"${GIT1[@]}" commit -qm "capture: valid TEC graph for adjacent-version refusal"
"${GIT1[@]}" push -q origin main

wp1 plugin deactivate the-events-calendar >/dev/null
wp1 plugin delete the-events-calendar >/dev/null
TEC_OUT_OF_RANGE_ARTIFACT=$(fetch_artifact the-events-calendar 6.17.1 cli1)
wp1 plugin install "$TEC_OUT_OF_RANGE_ARTIFACT" >/dev/null
TEC_INSTALLED_OOR=$(wp1 plugin get the-events-calendar --field=version)
[ "$TEC_INSTALLED_OOR" = 6.17.1 ] \
  || fail "negative control: expected the-events-calendar 6.17.1 installed, got $TEC_INSTALLED_OOR"

TEC_REFUSAL_RC=0
TEC_REFUSAL_OUT=$(wp1 duo deploy --repo=/siterepo 2>&1) || TEC_REFUSAL_RC=$?
[ "$TEC_REFUSAL_RC" -ne 0 ] \
  || fail "expected deploy to refuse the-events-calendar 6.17.1, but it exited 0: $TEC_REFUSAL_OUT"
grep -Eq "outside_version_range|outside the '.*' manifest's declared version_range" <<<"$TEC_REFUSAL_OUT" \
  || fail "TEC 6.17.1 refused outside the version gate: $TEC_REFUSAL_OUT"
grep -q 'the-events-calendar/the-events-calendar.php' <<<"$TEC_REFUSAL_OUT" \
  || fail "TEC adjacent-version refusal did not name the exact plugin basename: $TEC_REFUSAL_OUT"
grep -q '6.17.1' <<<"$TEC_REFUSAL_OUT" \
  || fail "TEC adjacent-version refusal did not name installed version 6.17.1: $TEC_REFUSAL_OUT"
wp1 plugin is-active the-events-calendar >/dev/null 2>&1 \
  && fail "outside-range TEC 6.17.1 was activated before deploy refused"
printf '%s\n' "$TEC_REFUSAL_OUT"
pass "official TEC 6.17.1 is loudly refused and remains inactive outside >=6.17.2 <6.17.4"
}
