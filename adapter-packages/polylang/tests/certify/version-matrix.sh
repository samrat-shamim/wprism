seed_polylang_content() {
  # Reuse the standalone three-language fixture verbatim: translated posts,
  # categories and media plus high source identities that rule out a stale-id
  # implementation passing by coincidence.
  wp_conf1() { wp1 "$@"; }
  local CONF_REPO1="siterepo/${PAIR}1"
  local COMPOSE="$PAIR_COMPOSE_STRING"
  local package_tests
  package_tests="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
  . "$package_tests/conformance/seed.sh"
  unset -f wp_conf1
}

check_polylang_content() {
  # The boundary matrix consumes the complete portable fixture/native
  # behavior but leaves the destructive hostile/lifecycle sequence to the
  # exact current-version conformance pair.
  wp_conf1() { wp1 "$@"; }
  wp_conf2() { wp2 "$@"; }
  local CONF_REPO1="siterepo/${PAIR}1"
  local CONF_REPO2="siterepo/${PAIR}2"
  local CONF1_PORT="$PORT1"
  local CONF2_PORT="$PORT2"
  local POLYLANG_BOUNDARY_ONLY=1
  local POLYLANG_EXPECTED_VERSION="$POLYLANG_VERSION"
  local APPLY_JSON="${POLYLANG_BOUNDARY_PROVIDER_RECEIPT:-}"
  local package_tests
  package_tests="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
  . "$package_tests/conformance/check.sh"
  unset -f wp_conf1 wp_conf2
}
