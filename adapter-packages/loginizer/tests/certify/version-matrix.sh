seed_loginizer_content() {
  wp_conf1() { wp1 "$@"; }
  local CONF_REPO1="siterepo/${PAIR}1"
  local COMPOSE="$PAIR_COMPOSE_STRING"
  . "$(dirname "${BASH_SOURCE[0]}")/../conformance/seed.sh"
  unset -f wp_conf1
}

check_loginizer_boundary_content() {
  local out
  out=$(wp2 eval-file /var/www/html/wp-content/mu-plugins/adapter-packages/loginizer/fixtures/native-options-observe.php --use-include)
  require_observed_nonempty "Loginizer boundary target behavior" "$out"
  out=$(printf '%s\n' "$out" | awk 'NF { line=$0 } END { print line }')
  printf '%s\n' "$out" | jq -e '
    .version == "2.1.0" and
    .rows.options.max_retries == 3 and .rows.options.lockout_time == 900 and .rows.options.max_lockouts == 5
    and .rows.options.lockouts_extend == 21600 and .rows.options.reset_retries == 43200
    and .rows.options.notify_email == 1 and .rows.options.notify_email_address == "admin@example.test"
    and .rows.options.trusted_ips == "on" and .rows.options.blocked_screen == "on"
    and (.rows.options | length) == 9
    and .rows.login_mail.enable == 1 and (.rows.login_mail | length) == 6
    and .body_carries_home == true
    and .rows.whitelist["1"].start == "10.0.0.5" and (.rows.whitelist | length) == 1
    and .rows.blacklist["1"].start == "192.168.7.7" and (.rows.blacklist | length) == 1
    and .rows.disable_brute == 0
    and .effective.max_retries == 3 and .effective.lockout_time == 900
    and .effective.notify_email_address == "admin@example.test" and .effective.trusted_ips == true
    and .effective.disable_brute == 0
    and .access.whitelisted_member.whitelisted == true and .access.whitelisted_member.blacklisted == false
    and .access.blacklisted_member.blacklisted == true and .access.blacklisted_member.whitelisted == false
    and .access.neutral.whitelisted == false and .access.neutral.blacklisted == false
    and .logs.exists == true and .logs.rows == 0
  ' >/dev/null || fail "Loginizer boundary target did not consume the applied settings through the plugin's own configuration and access decisions: $out"
  pass "Loginizer exact boundary drives the plugin's boot globals, whitelist/blacklist access decisions, home-URL template binding and empty runtime log table from the applied rows"
}

VMATRIX_PLUGIN_SLUG=loginizer

version_matrix_reset_after_delete() {
  local cli="$1"
  # `wp plugin delete` removes code without running the uninstall hook, so
  # every option row the prior case's activation and native saves created
  # survives as residue; the next exact boundary must not inherit it.
  "$cli" db query "
    DELETE FROM wp_options
      WHERE option_name IN (
        'loginizer_options', 'loginizer_login_mail', 'loginizer_whitelist',
        'loginizer_blacklist', 'loginizer_disable_brute', 'loginizer_version',
        'loginizer_last_reset', 'loginizer_ins_time', 'loginizer_msg',
        'loginizer_2fa_whitelist', 'loginizer_target_probe'
      );
  " >/dev/null
}

version_matrix_workflow() {
VMATRIX_CASES=$((VMATRIX_CASES + 1))
LOGINIZER_VERSION=2.1.0
say "boundary: loginizer $LOGINIZER_VERSION (only admitted patch)"

reset_env wp1
reset_env wp2
reset_case_repositories

say "fetch + verify loginizer $LOGINIZER_VERSION (digest-checked artifact only)"
LOGINIZER_ARTIFACT_1=$(fetch_artifact loginizer "$LOGINIZER_VERSION" cli1)
LOGINIZER_ARTIFACT_2=$(fetch_artifact loginizer "$LOGINIZER_VERSION" cli2)
wp1 plugin install "$LOGINIZER_ARTIFACT_1" --activate >/dev/null
LOGINIZER_INSTALLED_1=$(wp1 plugin get loginizer --field=version)
[ "$LOGINIZER_INSTALLED_1" = "$LOGINIZER_VERSION" ] \
  || fail "side 1 installed version mismatch: expected $LOGINIZER_VERSION, got $LOGINIZER_INSTALLED_1"
pass "side 1: loginizer $LOGINIZER_VERSION installed from verified artifact, active"

cat > "siterepo/${PAIR}1/site.wprism.json" <<'EOF'
{
  "manifests": ["core", "loginizer"],
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
"${GIT1[@]}" commit -qm "policy: Loginizer $LOGINIZER_VERSION exact-boundary certification"
"${GIT1[@]}" push -qu origin main

seed_loginizer_content
wp1 wprism capture --repo=/siterepo
wp1 wprism lint --repo=/siterepo
"${GIT1[@]}" add -A
"${GIT1[@]}" commit -qm "capture: Loginizer $LOGINIZER_VERSION settings"
"${GIT1[@]}" push -q origin main

clone_case_target
wp2 plugin install "$LOGINIZER_ARTIFACT_2" >/dev/null
INSTALLED_2=$(wp2 plugin get loginizer --field=version)
require_fixture_values INSTALLED_2
[ "$INSTALLED_2" = "$LOGINIZER_VERSION" ] \
  || fail "side 2 installed version mismatch: expected $LOGINIZER_VERSION, got $INSTALLED_2"
wp2 wprism deploy --repo=/siterepo
REV=$(git -C "siterepo/${PAIR}2" rev-parse HEAD)
wp2 wprism apply --repo=/siterepo --adopt-by-slug=terms,posts --default-author=admin --revision="$REV" 2>&1 | tee "$VMATRIX_APPLY_LOG"
assert_version_matrix_apply_ready
grep -q 'canary clean' "$VMATRIX_APPLY_LOG" \
  || fail "apply canary not clean at loginizer $LOGINIZER_VERSION"
check_loginizer_boundary_content

wp2 wprism capture --repo=/siterepo --out=/siterepo/.tmp-final
LOGINIZER_DIFF=$(diff -rq "siterepo/${PAIR}1/state" "siterepo/${PAIR}2/.tmp-final" || true)
rm -rf "siterepo/${PAIR}2/.tmp-final"
[ -z "$LOGINIZER_DIFF" ] \
  || fail "byte-identity broken at loginizer $LOGINIZER_VERSION: $LOGINIZER_DIFF"
pass "Loginizer $LOGINIZER_VERSION deploys, drives the plugin's own configuration natively, and recaptures byte-identically"

say "negative control: loginizer 2.0.9 (adjacent official release below the exact 2.1.0 contract) must be REFUSED"
# 2.0.9's brute-force settings writer is BYTE-IDENTICAL to 2.1.0's (measured:
# diff of main/settings/brute-force.php is empty) and its activation seeds the
# same option set (init.php:41-47). The refusal therefore has to come from the
# declared version_range alone, with no incidental surface difference to lean
# on — which is what the assertions below hold it to.
reset_env wp1
reset_case_repositories

LOGINIZER_IN_RANGE=$(fetch_artifact loginizer 2.1.0 cli1)
wp1 plugin install "$LOGINIZER_IN_RANGE" --activate >/dev/null
NEGATIVE_INSTALLED=$(wp1 plugin get loginizer --field=version)
require_fixture_values NEGATIVE_INSTALLED
[ "$NEGATIVE_INSTALLED" = "2.1.0" ] \
  || fail "negative control premise did not install exact loginizer 2.1.0 bytes"
cat > "siterepo/${PAIR}1/site.wprism.json" <<'EOF'
{
  "manifests": ["core", "loginizer"],
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
"${GIT1[@]}" commit -qm "policy: Loginizer negative-control pin"
"${GIT1[@]}" push -qu origin main
seed_loginizer_content
wp1 wprism capture --repo=/siterepo
"${GIT1[@]}" add -A
"${GIT1[@]}" commit -qm "capture: valid Loginizer state for negative control"
"${GIT1[@]}" push -q origin main

wp1 plugin deactivate loginizer >/dev/null
wp1 plugin delete loginizer >/dev/null
LOGINIZER_OUT_OF_RANGE=$(fetch_artifact loginizer 2.0.9 cli1)
wp1 plugin install "$LOGINIZER_OUT_OF_RANGE" >/dev/null
INSTALLED_OOR=$(wp1 plugin get loginizer --field=version)
[ "$INSTALLED_OOR" = "2.0.9" ] \
  || fail "negative control: expected loginizer 2.0.9 installed, got $INSTALLED_OOR"
LOGINIZER_REFUSAL_BEFORE=$(wp1 db query \
  "SELECT CONCAT(option_name, '=', SHA2(option_value, 256)) FROM wp_options WHERE option_name IN ('loginizer_options','loginizer_login_mail','loginizer_whitelist','loginizer_blacklist','loginizer_disable_brute') ORDER BY option_name;" \
  --skip-column-names)
require_observed_nonempty "Loginizer refusal state baseline" "$LOGINIZER_REFUSAL_BEFORE"
set +e
DEPLOY_OUT=$(wp1 wprism deploy --repo=/siterepo 2>&1)
DEPLOY_RC=$?
set -e
[ "$DEPLOY_RC" -ne 0 ] \
  || fail "expected deploy to refuse loginizer 2.0.9, but it exited 0 (got: $DEPLOY_OUT)"
grep -Eq "outside_version_range|outside the '.*' manifest's declared version_range" <<<"$DEPLOY_OUT" \
  || fail "loginizer 2.0.9 refused for the wrong reason (got: $DEPLOY_OUT)"
grep -q "loginizer/loginizer.php" <<<"$DEPLOY_OUT" \
  || fail "Loginizer refusal did not name the exact basename (got: $DEPLOY_OUT)"
grep -q "2.0.9" <<<"$DEPLOY_OUT" \
  || fail "Loginizer refusal did not name installed version 2.0.9 (got: $DEPLOY_OUT)"
if wp1 plugin is-active loginizer >/dev/null 2>&1; then
  fail "outside-range loginizer 2.0.9 was activated before refusal"
fi
LOGINIZER_REFUSAL_AFTER=$(wp1 db query \
  "SELECT CONCAT(option_name, '=', SHA2(option_value, 256)) FROM wp_options WHERE option_name IN ('loginizer_options','loginizer_login_mail','loginizer_whitelist','loginizer_blacklist','loginizer_disable_brute') ORDER BY option_name;" \
  --skip-column-names)
[ "$LOGINIZER_REFUSAL_AFTER" = "$LOGINIZER_REFUSAL_BEFORE" ] \
  || fail "Loginizer outside-range refusal mutated the authored settings"
printf '%s\n' "$DEPLOY_OUT"
pass "official loginizer 2.0.9 is loudly refused on the declared range alone despite a byte-identical settings writer, remains inactive, and cannot mutate admitted settings"
}
