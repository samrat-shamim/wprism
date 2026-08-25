seed_elementor_content() {
  # Reuse the standalone conformance fixture verbatim: it creates real media,
  # saves a document through Elementor's own Document::save() pipeline, and
  # renders it once so Elementor's lazy derived keys are exercised before
  # capture. Keeping one fixture prevents the boundary matrix from drifting
  # into a weaker hand-written approximation.
  wp_conf1() { wp1 "$@"; }
  local CONF_REPO1="siterepo/${PAIR}1"
  local CONF1_PORT="$PORT1"
  local COMPOSE="$PAIR_COMPOSE_STRING"
  . conformance/seeds/elementor.sh
  unset -f wp_conf1
}

check_elementor_content() {
  wp_conf2() { wp2 "$@"; }
  local CONF_REPO1="siterepo/${PAIR}1"
  local CONF_REPO2="siterepo/${PAIR}2"
  local CONF1_PORT="$PORT1"
  local CONF2_PORT="$PORT2"
  local COMPOSE="$PAIR_COMPOSE_STRING"
  local ELEMENTOR_EXPECTED_VERSION="${ELEMENTOR_VERSION:-4.2.3}"
  local ELEMENTOR_BOUNDARY_ONLY=1
  . conformance/checks/elementor.sh
  unset -f wp_conf2
}

postdeploy_elementor_content() {
  wp_conf2() { wp2 "$@"; }
  local CONF_REPO1="siterepo/${PAIR}1"
  local CONF_REPO2="siterepo/${PAIR}2"
  local COMPOSE="$PAIR_COMPOSE_STRING"
  . conformance/postdeploy/elementor.sh
  unset -f wp_conf2
}
