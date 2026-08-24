seed_woocommerce_content() {
  # Reuse the standalone WooCommerce fixture: products, coupon, media,
  # global attributes, shipping methods, tax, and a source-only HPOS order.
  wp_conf1() { wp1 "$@"; }
  wp_env() {
    local env="$1"; shift
    case "$env" in
      conf1) wp1 "$@" ;;
      conf2) wp2 "$@" ;;
      *) fail "unknown WooCommerce seed environment: $env" ;;
    esac
  }
  local CONF_REPO1="siterepo/${PAIR}1"
  local COMPOSE="$PAIR_COMPOSE_STRING"
  . conformance/seeds/woocommerce.sh
  unset -f wp_conf1 wp_env
}

postdeploy_woocommerce_content() {
  wp_conf2() { wp2 "$@"; }
  . conformance/postdeploy/woocommerce.sh
  unset -f wp_conf2
}

check_woocommerce_content() {
  local COMPOSE="$PAIR_COMPOSE_STRING"
  local CONF1_PORT="$PORT1"
  local CONF2_PORT="$PORT2"
  . conformance/checks/woocommerce.sh
}
