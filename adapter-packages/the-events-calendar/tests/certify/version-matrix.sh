seed_the_events_calendar_content() {
  # The standalone fixture uses TEC's repositories, Category Colors API, and
  # settings API. Reusing it here keeps the exact-version proof on the same
  # native graph and difficult-value surface as the isolated conformance run.
  wp_conf1() { wp1 "$@"; }
  local CONF_REPO1="siterepo/${PAIR}1"
  local COMPOSE="$PAIR_COMPOSE_STRING"
  local package_tests
  package_tests="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
  . "$package_tests/conformance/seed.sh"
  unset -f wp_conf1
}

postdeploy_the_events_calendar_content() {
  wp_conf2() { wp2 "$@"; }
  local CONF_REPO2="siterepo/${PAIR}2"
  local COMPOSE="$PAIR_COMPOSE_STRING"
  local package_tests
  package_tests="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
  . "$package_tests/conformance/postdeploy.sh"
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
  . "$package_tests/conformance/check.sh"
  unset -f wp_conf1 wp_conf2 wp_env
}

postapply_the_events_calendar_content() {
  wp_conf2() { wp2 "$@"; }
  local CONF_REPO2="siterepo/${PAIR}2"
  local package_tests
  package_tests="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
  . "$package_tests/conformance/postapply.sh"
  unset -f wp_conf2
}
