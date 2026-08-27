seed_pmpro_content() {
  wp_conf1() { wp1 "$@"; }
  local CONF_REPO1="siterepo/${PAIR}1"
  local COMPOSE="$PAIR_COMPOSE_STRING"
  . conformance/seeds/paid-memberships-pro.sh
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
  . conformance/checks/paid-memberships-pro.sh
}

postdeploy_pmpro_content() {
  wp_conf2() { wp2 "$@"; }
  local CONF_REPO2="siterepo/${PAIR}2"
  local COMPOSE="$PAIR_COMPOSE_STRING"
  . conformance/postdeploy/paid-memberships-pro.sh
  unset -f wp_conf2
}
