seed_wps_hide_login_content() {
  wp_conf1() { wp1 "$@"; }
  local CONF_REPO1="siterepo/${PAIR}1"
  local COMPOSE="$PAIR_COMPOSE_STRING"
  . "$(dirname "${BASH_SOURCE[0]}")/../conformance/seed.sh"
  unset -f wp_conf1
}

check_wps_hide_login_boundary_content() { # <wp1|wp2> <port> <label>
  local cli="$1" port="$2" label="$3" out headers body code location
  out=$("$cli" eval '
    echo wp_json_encode([
      "login" => get_option("whl_page"),
      "login_url" => wp_login_url(),
      "lostpassword_url" => wp_lostpassword_url(),
      "redirect" => get_option("whl_redirect_admin"),
      "site_login_url" => site_url("wp-login.php"),
    ], JSON_UNESCAPED_SLASHES);
  ')
  require_observed_nonempty "WPS Hide Login $label boundary APIs" "$out"
  out=$(printf '%s\n' "$out" | awk 'NF { line=$0 } END { print line }')
  printf '%s\n' "$out" | jq -e '
    .login == "wprism-login" and .redirect == "wprism-missing" and
    (.login_url | endswith("/wprism-login/")) and
    (.site_login_url | endswith("/wprism-login/")) and
    (.lostpassword_url | contains("/wprism-login/"))
  ' >/dev/null || fail "WPS Hide Login $label boundary APIs do not consume the exact settings: $out"

  headers=$(mktemp "${TMPDIR:-/tmp}/wprism-vmatrix-wps-headers.XXXXXX")
  body=$(mktemp "${TMPDIR:-/tmp}/wprism-vmatrix-wps-body.XXXXXX")
  code=$(curl --path-as-is --max-time 20 -sS -D "$headers" -o "$body" -w '%{http_code}' "http://localhost:${port}/wprism-login/")
  [ "$code" = 200 ] && grep -Fq 'id="loginform"' "$body" \
    || fail "WPS Hide Login $label custom GET did not serve login (status=$code)"
  code=$(curl --path-as-is --max-time 20 -sS -D "$headers" -o "$body" -w '%{http_code}' -X POST \
    --data 'log=wprism-no-such-user&pwd=wrong&wp-submit=Log+In&redirect_to=%2Fwp-admin%2F&testcookie=1' \
    "http://localhost:${port}/wprism-login/")
  [ "$code" = 200 ] && grep -Fq 'login_error' "$body" \
    || fail "WPS Hide Login $label custom POST did not execute WordPress login handling (status=$code)"
  code=$(curl --path-as-is --max-time 20 -sS -D "$headers" -o "$body" -w '%{http_code}' "http://localhost:${port}/wp-login%2Ephp")
  [ "$code" = 404 ] && ! grep -Fq 'id="loginform"' "$body" \
    || fail "WPS Hide Login $label encoded old-login path bypassed hiding (status=$code)"
  code=$(curl --path-as-is --max-time 20 -sS -D "$headers" -o "$body" -w '%{http_code}' "http://localhost:${port}/wp-admin/")
  location=$(awk 'BEGIN { IGNORECASE=1 } /^Location:/ { sub(/\r$/, ""); print substr($0, 11) }' "$headers" | tail -1)
  [ "$code" = 302 ] && [ "$location" = "http://localhost:${port}/wprism-missing/" ] \
    || fail "WPS Hide Login $label wp-admin redirect moved (status=$code location=${location:-<none>})"
  rm -f "$headers" "$body"
  pass "WPS Hide Login $label exact artifact drives APIs, custom GET/POST, encoded old-login refusal, and wp-admin redirect"
}

VMATRIX_PLUGIN_SLUG=wps-hide-login

version_matrix_reset_after_delete() {
  local cli="$1"
  "$cli" db query "
    DELETE FROM wp_options WHERE option_name IN (
      'whl_page', 'whl_redirect', 'whl_redirect_admin',
      'wps-hide-login-target-rewrite-hash', 'wps-hide-login-target-runtime-probe'
    );
  " >/dev/null
}

version_matrix_workflow() {
VMATRIX_CASES=$((VMATRIX_CASES + 1))
WPS_VERSION=1.9.19
say "boundary: wps-hide-login $WPS_VERSION (only admitted patch)"

reset_env wp1
reset_env wp2
reset_case_repositories

say "fetch + verify wps-hide-login $WPS_VERSION (digest-checked artifact only)"
WPS_ARTIFACT_1=$(fetch_artifact wps-hide-login "$WPS_VERSION" cli1)
WPS_ARTIFACT_2=$(fetch_artifact wps-hide-login "$WPS_VERSION" cli2)
wp1 plugin install "$WPS_ARTIFACT_1" --activate >/dev/null
WPS_INSTALLED_1=$(wp1 plugin get wps-hide-login --field=version)
[ "$WPS_INSTALLED_1" = "$WPS_VERSION" ] \
  || fail "side 1 installed version mismatch: expected $WPS_VERSION, got $WPS_INSTALLED_1"
pass "side 1: wps-hide-login $WPS_VERSION installed from verified artifact, active"

cat > "siterepo/${PAIR}1/site.wprism.json" <<'EOF'
{
  "manifests": ["core", "wps-hide-login"],
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
"${GIT1[@]}" commit -qm "policy: WPS Hide Login $WPS_VERSION exact-boundary certification"
"${GIT1[@]}" push -qu origin main

seed_wps_hide_login_content
check_wps_hide_login_boundary_content wp1 "$PORT1" source
# Real login requests can leave core's transient Customizer sentinel at -1;
# remove it so exact recapture has no "unmanaged post id -1" warning.
wp1 eval 'remove_theme_mod("custom_css_post_id");' >/dev/null
wp1 wprism capture --repo=/siterepo
wp1 wprism lint --repo=/siterepo
"${GIT1[@]}" add -A
"${GIT1[@]}" commit -qm "capture: WPS Hide Login $WPS_VERSION routes"
"${GIT1[@]}" push -q origin main

clone_case_target
wp2 plugin install "$WPS_ARTIFACT_2" >/dev/null
INSTALLED_2=$(wp2 plugin get wps-hide-login --field=version)
require_fixture_values INSTALLED_2
[ "$INSTALLED_2" = "$WPS_VERSION" ] \
  || fail "side 2 installed version mismatch: expected $WPS_VERSION, got $INSTALLED_2"
wp2 wprism deploy --repo=/siterepo
REV=$(git -C "siterepo/${PAIR}2" rev-parse HEAD)
wp2 wprism apply --repo=/siterepo --adopt-by-slug=terms,posts --default-author=admin --revision="$REV" | tee "$VMATRIX_APPLY_LOG"
grep -q 'canary clean' "$VMATRIX_APPLY_LOG" \
  || fail "apply canary not clean at wps-hide-login $WPS_VERSION"
check_wps_hide_login_boundary_content wp2 "$PORT2" target

wp2 eval 'remove_theme_mod("custom_css_post_id");' >/dev/null
wp2 wprism capture --repo=/siterepo --out=/siterepo/.tmp-final
WPS_DIFF=$(diff -rq "siterepo/${PAIR}1/state" "siterepo/${PAIR}2/.tmp-final" || true)
rm -rf "siterepo/${PAIR}2/.tmp-final"
[ -z "$WPS_DIFF" ] \
  || fail "byte-identity broken at wps-hide-login $WPS_VERSION: $WPS_DIFF"
pass "WPS Hide Login $WPS_VERSION deploys, handles real source/target requests, and recaptures byte-identically"

say "negative control: wps-hide-login 1.9.18 (adjacent official release below the exact 1.9.19 contract) must be REFUSED"
reset_env wp1
reset_case_repositories

WPS_IN_RANGE=$(fetch_artifact wps-hide-login 1.9.19 cli1)
wp1 plugin install "$WPS_IN_RANGE" --activate >/dev/null
NEGATIVE_INSTALLED=$(wp1 plugin get wps-hide-login --field=version)
require_fixture_values NEGATIVE_INSTALLED
[ "$NEGATIVE_INSTALLED" = "1.9.19" ] \
  || fail "negative control premise did not install exact wps-hide-login 1.9.19 bytes"
cat > "siterepo/${PAIR}1/site.wprism.json" <<'EOF'
{
  "manifests": ["core", "wps-hide-login"],
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
"${GIT1[@]}" commit -qm "policy: WPS Hide Login negative-control pin"
"${GIT1[@]}" push -qu origin main
seed_wps_hide_login_content
wp1 wprism capture --repo=/siterepo
"${GIT1[@]}" add -A
"${GIT1[@]}" commit -qm "capture: valid WPS Hide Login state for negative control"
"${GIT1[@]}" push -q origin main

wp1 plugin deactivate wps-hide-login >/dev/null
wp1 plugin delete wps-hide-login >/dev/null
WPS_OUT_OF_RANGE=$(fetch_artifact wps-hide-login 1.9.18 cli1)
wp1 plugin install "$WPS_OUT_OF_RANGE" >/dev/null
INSTALLED_OOR=$(wp1 plugin get wps-hide-login --field=version)
[ "$INSTALLED_OOR" = "1.9.18" ] \
  || fail "negative control: expected wps-hide-login 1.9.18 installed, got $INSTALLED_OOR"
WPS_REFUSAL_BEFORE=$(wp1 eval 'echo hash("sha256", maybe_serialize([get_option("whl_page", null), get_option("whl_redirect_admin", null), get_option("rewrite_rules")]));')
require_observed_nonempty "WPS Hide Login refusal state baseline" "$WPS_REFUSAL_BEFORE"
set +e
DEPLOY_OUT=$(wp1 wprism deploy --repo=/siterepo 2>&1)
DEPLOY_RC=$?
set -e
[ "$DEPLOY_RC" -ne 0 ] \
  || fail "expected deploy to refuse wps-hide-login 1.9.18, but it exited 0 (got: $DEPLOY_OUT)"
grep -Eq "outside_version_range|outside the '.*' manifest's declared version_range" <<<"$DEPLOY_OUT" \
  || fail "wps-hide-login 1.9.18 refused for the wrong reason (got: $DEPLOY_OUT)"
grep -q "wps-hide-login/wps-hide-login.php" <<<"$DEPLOY_OUT" \
  || fail "WPS Hide Login refusal did not name the exact basename (got: $DEPLOY_OUT)"
grep -q "1.9.18" <<<"$DEPLOY_OUT" \
  || fail "WPS Hide Login refusal did not name installed version 1.9.18 (got: $DEPLOY_OUT)"
if wp1 plugin is-active wps-hide-login >/dev/null 2>&1; then
  fail "outside-range wps-hide-login 1.9.18 was activated before refusal"
fi
WPS_REFUSAL_AFTER=$(wp1 eval 'echo hash("sha256", maybe_serialize([get_option("whl_page", null), get_option("whl_redirect_admin", null), get_option("rewrite_rules")]));')
[ "$WPS_REFUSAL_AFTER" = "$WPS_REFUSAL_BEFORE" ] \
  || fail "WPS Hide Login outside-range refusal mutated authored settings or rewrite bytes"
printf '%s\n' "$DEPLOY_OUT"
pass "official wps-hide-login 1.9.18 is loudly refused, remains inactive, and cannot mutate admitted settings or rewrite state"
}
