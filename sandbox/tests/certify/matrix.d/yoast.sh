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
  . conformance/seeds/yoast.sh
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
  . conformance/checks/yoast.sh
}

postdeploy_yoast_content() {
  wp_conf2() { wp2 "$@"; }
  local CONF_REPO1="siterepo/${PAIR}1"
  local CONF_REPO2="siterepo/${PAIR}2"
  local COMPOSE="$PAIR_COMPOSE_STRING"
  . conformance/postdeploy/yoast.sh
  unset -f wp_conf2
}
