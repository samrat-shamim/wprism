seed_elementor_content() {
  # Reuse the standalone conformance fixture verbatim: it creates real media,
  # saves a document through Elementor's own Document::save() pipeline, and
  # renders it once so Elementor's lazy derived keys are exercised before
  # capture. Keeping one fixture prevents the boundary matrix from drifting
  # into a weaker hand-written approximation.
  wp_conf1() { wp1 "$@"; }
  local CONF_REPO1="siterepo/${PAIR}1" package_tests
  local CONF1_PORT="$PORT1"
  local COMPOSE="$PAIR_COMPOSE_STRING"
  package_tests="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
  . "$package_tests/conformance/seed.sh"
  unset -f wp_conf1
}

check_elementor_content() {
  wp_conf2() { wp2 "$@"; }
  local CONF_REPO1="siterepo/${PAIR}1" package_tests
  local CONF_REPO2="siterepo/${PAIR}2"
  local CONF1_PORT="$PORT1"
  local CONF2_PORT="$PORT2"
  local COMPOSE="$PAIR_COMPOSE_STRING"
  local ELEMENTOR_EXPECTED_VERSION="${ELEMENTOR_VERSION:-4.2.3}"
  local ELEMENTOR_BOUNDARY_ONLY=1
  package_tests="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
  . "$package_tests/conformance/check.sh"
  unset -f wp_conf2
}

postdeploy_elementor_content() {
  wp_conf2() { wp2 "$@"; }
  local CONF_REPO1="siterepo/${PAIR}1" package_tests
  local CONF_REPO2="siterepo/${PAIR}2"
  local COMPOSE="$PAIR_COMPOSE_STRING"
  package_tests="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
  . "$package_tests/conformance/postdeploy.sh"
  unset -f wp_conf2
}
