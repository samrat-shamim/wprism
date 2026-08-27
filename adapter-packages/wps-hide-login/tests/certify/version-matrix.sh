seed_wps_hide_login_content() {
  wp_conf1() { wp1 "$@"; }
  local CONF_REPO1="siterepo/${PAIR}1"
  local COMPOSE="$PAIR_COMPOSE_STRING"
  . ../adapter-packages/wps-hide-login/tests/conformance/seed.sh
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
    .login == "duo-login" and .redirect == "duo-missing" and
    (.login_url | endswith("/duo-login/")) and
    (.site_login_url | endswith("/duo-login/")) and
    (.lostpassword_url | contains("/duo-login/"))
  ' >/dev/null || fail "WPS Hide Login $label boundary APIs do not consume the exact settings: $out"

  headers=$(mktemp "${TMPDIR:-/tmp}/duo-vmatrix-wps-headers.XXXXXX")
  body=$(mktemp "${TMPDIR:-/tmp}/duo-vmatrix-wps-body.XXXXXX")
  code=$(curl --path-as-is --max-time 20 -sS -D "$headers" -o "$body" -w '%{http_code}' "http://localhost:${port}/duo-login/")
  [ "$code" = 200 ] && grep -Fq 'id="loginform"' "$body" \
    || fail "WPS Hide Login $label custom GET did not serve login (status=$code)"
  code=$(curl --path-as-is --max-time 20 -sS -D "$headers" -o "$body" -w '%{http_code}' -X POST \
    --data 'log=duo-no-such-user&pwd=wrong&wp-submit=Log+In&redirect_to=%2Fwp-admin%2F&testcookie=1' \
    "http://localhost:${port}/duo-login/")
  [ "$code" = 200 ] && grep -Fq 'login_error' "$body" \
    || fail "WPS Hide Login $label custom POST did not execute WordPress login handling (status=$code)"
  code=$(curl --path-as-is --max-time 20 -sS -D "$headers" -o "$body" -w '%{http_code}' "http://localhost:${port}/wp-login%2Ephp")
  [ "$code" = 404 ] && ! grep -Fq 'id="loginform"' "$body" \
    || fail "WPS Hide Login $label encoded old-login path bypassed hiding (status=$code)"
  code=$(curl --path-as-is --max-time 20 -sS -D "$headers" -o "$body" -w '%{http_code}' "http://localhost:${port}/wp-admin/")
  location=$(awk 'BEGIN { IGNORECASE=1 } /^Location:/ { sub(/\r$/, ""); print substr($0, 11) }' "$headers" | tail -1)
  [ "$code" = 302 ] && [ "$location" = "http://localhost:${port}/duo-missing/" ] \
    || fail "WPS Hide Login $label wp-admin redirect moved (status=$code location=${location:-<none>})"
  rm -f "$headers" "$body"
  pass "WPS Hide Login $label exact artifact drives APIs, custom GET/POST, encoded old-login refusal, and wp-admin redirect"
}
