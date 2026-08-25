seed_polylang_content() {
  # Reuse the standalone fixture verbatim: two languages, translated post
  # and category pairs, plus deleted fillers that force source/target ids
  # apart so a stale-id implementation cannot pass by coincidence.
  wp_conf1() { wp1 "$@"; }
  local CONF_REPO1="siterepo/${PAIR}1"
  local COMPOSE="$PAIR_COMPOSE_STRING"
  . conformance/seeds/polylang.sh
  unset -f wp_conf1
}

check_polylang_content() {
  # The standalone check uses Polylang's public lookup APIs, raw serialized
  # relationship bytes, a target recapture, and a real frontend request.
  wp_conf1() { wp1 "$@"; }
  wp_conf2() { wp2 "$@"; }
  local CONF_REPO2="siterepo/${PAIR}2"
  local CONF1_PORT="$PORT1"
  local CONF2_PORT="$PORT2"
  . conformance/checks/polylang.sh
  unset -f wp_conf1 wp_conf2
}
