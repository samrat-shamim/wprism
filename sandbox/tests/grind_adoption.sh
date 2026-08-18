#!/usr/bin/env bash
# Progressive adoption — ten situations a simulated end user walks through
# (round-3 T7; plan: docs/proposals/round-3-adoption-situations.md).
#
# One driver, ten situations, each on a FRESH `pair.sh reset` of one dedicated
# pair. "Progressive" means each situation is the sequence the same operator
# would actually take: start with the smallest thing Duo can do for that site
# (`duo doctor`, `duo assess` read-only on the adoption seed), then add
# management one decision at a time — never a big-bang init. Every situation
# ends with the full loop where the site allows it (contract → rehearse → edit
# → capture → merge → release → verify → recover) or with the typed refusal
# that says why not, asserted by reason code.
#
#   A1  brochure site: classic theme, core only, pages + menu + widget
#   A2  block theme (FSE): templates, template parts, navigation
#   A3  shop: WooCommerce + classic theme, orders already present
#   A4  shop + SEO + forms: WooCommerce + Yoast + CF7, all certified
#   A5  multilingual shop: WooCommerce + Polylang
#   A6  builder site: Elementor + ACF + block theme
#   A7  unmanifested published plugin (WPForms Lite) on A4's shop, kept
#       unmanaged, then adopted with a site adapter
#   A8  custom in-house plugin (acme-catalog) + WooCommerce, adapter bundled,
#       promoted, certified, then a code release that changes plugin AND adapter
#   A9  version edges: WooCommerce below range → upgrade; Yoast old → new
#   A10 edge cases: adopt-over-init refusal, multisite refusal, secret-shaped
#       option, plugin deactivated after adoption, theme switch, override
#       installed then removed
#
# The substrate is sandbox/tests/lib/grind_lib.sh — the same helpers, words
# and loop building blocks as grind_adapter_walk.sh (round-3 T6), so a
# situation here and a scenario there mean the same thing by the same code.
#
#   ADOPT_PAIR       (default adopt)   pair.sh pair name; grammar [a-z][a-z0-9]*
#   ADOPT_PORT1/2    (default 9600/9601)
#   ADOPT_SITUATIONS (default A1,…,A10) comma list, run in the order given
#   ADOPT_KEEP=1     leave the pair and the site repos in place
#   DUO_EXPECTED_SOURCE_SHA           bind this run to an exact source commit
#   DUO_WORDPRESS_ORG_OFFLINE         0|1, forwarded to pair.sh/fetch-artifact
#
# Modes: --self-check (helpers against fixtures, no docker), --dry-run.
#
# Bash + docker + jq + php. Never `make`; never another agent's pair.
set -euo pipefail
cd "$(dirname "$0")/.."   # -> sandbox/
exec 3>&1

SANDBOX="$(pwd -P)"
REPO_ROOT="$(cd .. && pwd -P)"
DUO="$REPO_ROOT/cli/duo"
FIXTURES="$SANDBOX/tests/fixtures/adapter-walk"

MODE=run
for arg in "$@"; do
  case "$arg" in
    --self-check) MODE=self-check ;;
    --dry-run) MODE=dry-run ;;
    *) printf 'usage: grind_adoption.sh [--self-check|--dry-run]\n' >&2; exit 2 ;;
  esac
done
DRY_RUN=0
[ "$MODE" = dry-run ] && DRY_RUN=1

PAIR="${ADOPT_PAIR:-adopt}"
PORT1="${ADOPT_PORT1:-9600}"
PORT2="${ADOPT_PORT2:-9601}"
SITUATIONS="${ADOPT_SITUATIONS:-A1,A2,A3,A4,A5,A6,A7,A8,A9,A10}"
WORDPRESS_OFFLINE="${DUO_WORDPRESS_ORG_OFFLINE:-0}"

# Pinned subjects (sandbox/conformance/artifacts.lock.json). Every install is an
# exact artifact; fetch-artifact.sh refuses an unpinned version.
WOO_VERSION="${ADOPT_WOO_VERSION:-11.0.0}"
WOO_OLD_VERSION="${ADOPT_WOO_OLD_VERSION:-10.9.4}"
YOAST_VERSION="${ADOPT_YOAST_VERSION:-28.2}"
YOAST_OLD_VERSION="${ADOPT_YOAST_OLD_VERSION:-27.9}"
CF7_VERSION="${ADOPT_CF7_VERSION:-6.1.6}"
POLYLANG_VERSION="${ADOPT_POLYLANG_VERSION:-3.8.6}"
ELEMENTOR_VERSION="${ADOPT_ELEMENTOR_VERSION:-4.2.2}"
ACF_VERSION="${ADOPT_ACF_VERSION:-6.8.7}"
WPFORMS_VERSION="${ADOPT_WPFORMS_VERSION:-2.0.0.4}"
CLASSIC_THEME_SLUG=twentytwentyone; CLASSIC_THEME_VERSION=2.8
BLOCK_THEME_SLUG=twentytwentyfive;  BLOCK_THEME_VERSION=1.5
# The lib's own defaults (install_side reads these); situations override
# per-run through install_stack below.
THEME_SLUG="$CLASSIC_THEME_SLUG"; THEME_VERSION="$CLASSIC_THEME_VERSION"
WPFORMS_SLUG=wpforms-lite
WPFORMS_BASENAME='wpforms-lite/wpforms.php'
WPFORMS_CPT=wpforms
WPFORMS_OPTION_PREFIX=wpforms_
WPFORMS_OPTION_FAMILY=wpforms
WPFORMS_TABLE=wpforms_tasks_meta
ACME_SLUG=acme-catalog
ACME_BASENAME='acme-catalog/acme-catalog.php'
ACME_CPT=acme_item
ACME_TAXONOMY=acme_kind
WALK_KEY_ID="${ADOPT_KEY_ID:-acme-ops-2026}"

FAILURES=0
PAIR_UP=0
SCRATCH=""
SITUATIONS_RUN=()

# shellcheck source=lib/grind_lib.sh
. "$SANDBOX/tests/lib/grind_lib.sh"

# TODO(build): situations A1..A10 (see t7-design.md); each is `situation_aN`.

# ---------------------------------------------------------------------------
# The stack a situation installs. `install_stack <side> <role> <theme@ver>
# [<plugin@ver>...]` — every artifact exact (fetch_artifact), the author side
# activates, the target side only installs (the release's lifecycle phase
# activates there, which is the ordering every situation wants).
# ---------------------------------------------------------------------------
install_stack() {
  local side="$1" role="$2" theme="$3"; shift 3
  local artifact spec slug version
  wp_side "$side" option update blogname "Duo adoption ${PAIR}${side}"
  wp_side "$side" site empty --yes
  for spec in "$@"; do
    slug="${spec%@*}"; version="${spec#*@}"
    if dry; then
      plan "fetch_artifact $slug $version cli$side plugin"; artifact='<container-side-zip>'
    else
      artifact="$(fetch_artifact "$slug" "$version" "cli$side" plugin)" \
        || fail "could not obtain the pinned $slug $version artifact for side $side"
    fi
    wp_side "$side" plugin install "$artifact" --force
    [ "$role" = author ] && wp_side "$side" plugin activate "$slug"
  done
  slug="${theme%@*}"; version="${theme#*@}"
  if dry; then
    artifact='<container-side-zip>'
  else
    artifact="$(fetch_artifact "$slug" "$version" "cli$side" theme)" \
      || fail "could not obtain the pinned $slug $version artifact for side $side"
  fi
  wp_side "$side" theme install "$artifact" --force
  [ "$role" = author ] && wp_side "$side" theme activate "$slug"
  return 0
}

# situation_pair <label> <theme@ver> [<plugin@ver>...] — one fresh pair per
# situation, exactly as the walk's scenario_pair, with the situation's stack.
situation_pair() {
  local label="$1"; shift
  say "$label — fresh pair '$PAIR' on :$PORT1/:$PORT2"
  if ! dry; then
    validate_artifact_lock "$SANDBOX/conformance/artifacts.lock.json" \
      || fail "artifact lock is malformed; the grind refused before pair reset"
    docker build -q -f init-cli.Dockerfile -t "$DUO_CLI_IMAGE" . >/dev/null \
      || fail "could not build the Git-enabled cli image $DUO_CLI_IMAGE from sandbox/init-cli.Dockerfile"
  else
    plan "docker build -q -f init-cli.Dockerfile -t $DUO_CLI_IMAGE .   # wordpress:cli-php8.3 + git"
  fi
  PAIR_UP=1
  run bash bin/pair.sh reset "$PAIR"
  run bash bin/pair.sh up "$PAIR" "$PORT1" "$PORT2" "${PAIR_UP_FLAGS[@]}"
  install_stack 1 author "$@"
  install_stack 2 target "$@"
  run git init --bare -b main "$ORIGIN"
  pass "$label — pair up; $* on both sides"
}

# seed_pages <landing-slug> — a brochure: three pages, a menu with them, and a
# text widget. Sets LANDING_ID and LANDING_PATH.
seed_pages() {
  local slug="$1"
  say "seed — pages, a menu and a widget on ${PAIR}1"
  if dry; then
    plan "wp1 post create --post_type=page ... (x3); wp1 menu create; wp1 widget add"
    LANDING_ID='<landing-id>'; LANDING_PATH="/$slug/"
    return 0
  fi
  LANDING_ID="$(wp1 post create --post_type=page --post_status=publish \
    --post_title='Duo walk landing page' --post_name="$slug" \
    --post_content='<p>Duo walk landing page, before the release.</p>' --porcelain | tr -d '\r')"
  local about contact
  about="$(wp1 post create --post_type=page --post_status=publish --post_title='About us' --post_name=about \
    --post_content='<p>About the studio.</p>' --porcelain | tr -d '\r')"
  contact="$(wp1 post create --post_type=page --post_status=publish --post_title='Contact' --post_name=contact \
    --post_content='<p>Write to us.</p>' --porcelain | tr -d '\r')"
  wp1 menu create 'Primary' >/dev/null
  wp1 menu item add-post primary "$LANDING_ID" >/dev/null
  wp1 menu item add-post primary "$about" >/dev/null
  wp1 menu item add-post primary "$contact" >/dev/null
  wp1 menu location assign primary primary >/dev/null 2>&1 || true
  wp1 widget add text sidebar-1 1 --title='Hours' --text='Open weekdays' >/dev/null 2>&1 || true
  [ -n "$LANDING_ID" ] || fail "the brochure seed did not produce a landing page on ${PAIR}1"
  local url path
  url="$(wp1 post list --post_type=page --name="$slug" --field=url | tr -d '\r' | head -1)"
  path="$(printf '%s' "$url" | sed -E 's#^https?://[^/]+##')"
  case "$path" in /*) LANDING_PATH="$path" ;; *) LANDING_PATH="/?page_id=$LANDING_ID" ;; esac
  return 0
}

# journeys_json <landing-path> [<extra-json>...] — the landing journey plus any
# situation-specific ones, for contract_cycle (CONTRACT_JOURNEYS_JSON).
journeys_json() {
  local landing="$1"; shift
  local out
  out="$(jq -n --arg landing "$landing" '[{id: "landing-page", url: $landing, expect_status: 200,
    expect_contains: "Duo walk landing page", affected_surfaces: ["post_type:page"]}]')"
  local extra
  for extra in "$@"; do out="$(jq -c --argjson e "$extra" '. + [$e]' <<<"$out")"; done
  printf '%s' "$out"
}

# doctor_and_first_look <label> <env> — the smallest thing Duo can do for a
# site: `duo doctor` names the transport and the agent, `duo assess` on the
# adoption seed reads the site without writing anything.
doctor_and_first_look() {
  local label="$1" env="$2"
  say "$label — duo doctor $env (read-only) and duo assess $env on the adoption seed"
  duo_ok "$EVIDENCE/$label/doctor.txt" "$HOST_R1" doctor "$env"
  seed_repository "$label"
  assess_both "$label" "$env" "$HOST_R1" first-look
  if ! dry; then
    [ -z "$(ls -A "$HOST_R1/state" 2>/dev/null)" ] \
      || fail "$label: a read-only first look wrote state/ into the seed repository"
    pass "$label — doctor is green and the first assessment wrote nothing"
  fi
}

# init_capture_baseline <label> <env> [init flags...] — the smallest honest
# adoption step after the first look: init, capture, commit the baseline.
init_capture_baseline() {
  local label="$1" env="$2"; shift 2
  say "$label — duo init $env --yes $*"
  duo_ok "$EVIDENCE/$label/init.txt" "$HOST_R1" init "$env" --yes "$@"
  duo_ok "$EVIDENCE/$label/capture.txt" "$HOST_R1" capture "$env"
  baseline_commit "$label"
}

# loop_to_recovery <label> — contract → rehearse → page edit on the preview →
# capture twice → merge → revert target → release → verify → recover → reap.
# CONTRACT_JOURNEYS_JSON / CONTRACT_LIFECYCLE_REASON must be set by the caller;
# EXTRA_JOURNEY / SURFACE_FILTER default to none / identity.
loop_to_recovery() {
  local label="$1" extraJourney="${2:--}" surfaceFilter="${3:-.}"
  contract_cycle "$label" "${PAIR}1" "$HOST_R1" "$LANDING_ID" "$extraJourney" "$surfaceFilter"
  rehearse_preview "$label"
  say "$label — author a page-body edit on the preview, then capture twice"
  preview_page_edit "$label" duo-walk-landing '<p>Duo walk landing page, released through duo release.</p>'
  capture_twice "$label" preview
  merge_preview "$label"
  revert_target "$label" "$PREVIEW_PAGE_ID" '<p>Duo walk landing page, before the release.</p>'
  release_cycle "$label" "$MAIN_SHA"
  recover_cycle "$label"
  post_recovery_check "$label"
  reap_cycle "$label"
}

# ---------------------------------------------------------------------------
# A1 — brochure site: classic theme, core only.
# ---------------------------------------------------------------------------
situation_a1() {
  local S=A1
  run mkdir -p "$EVIDENCE/$S"
  situation_pair "$S" "$CLASSIC_THEME_SLUG@$CLASSIC_THEME_VERSION"
  write_registry
  seed_pages duo-walk-landing
  doctor_and_first_look "$S" "${PAIR}1"
  if ! dry; then
    local expect got
    expect=$'authored\tmanage\tReady\tPlatform-certified\tprevented\tprovider-state restorable'
    got="$(walk_assess_projection "$ASSESS_JSON" post_type:page release)"
    [ "$got" = "$expect" ] || fail "$S: before init, post_type:page projected '$got', expected '$expect'"
    jq -e '[.surfaces[] | select(.id | startswith("plugin:"))] | length == 0' "$ASSESS_JSON" >/dev/null \
      || fail "$S: a core-only site reports a plugin: surface"
    [ "$(walk_gap_count "$EVIDENCE/$S/assess-first-look.txt" 'install adapter')" = 0 ] \
      || fail "$S: a core-only site asks for an adapter"
    pass "$S — the first look reads a plain, fully certified core site"
  fi
  init_capture_baseline "$S" "${PAIR}1"
  assess_both "$S" "${PAIR}1" "$HOST_R1" adopted
  if ! dry; then
    [ "$(walk_assess_projection "$ASSESS_JSON" post_type:page release | cut -f3-4)" = $'Ready\tPlatform-certified' ] \
      || fail "$S: after init, post_type:page is not Ready / Platform-certified"
    jq -e '.surfaces[] | select(.id == "menu:nav_menu" or (.id | startswith("menu")))' "$ASSESS_JSON" >/dev/null 2>&1 \
      || note "$S: no menu surface row in the assessment (menus travel with core; not asserted further)"
  fi
  CONTRACT_JOURNEYS_JSON="$(journeys_json "$LANDING_PATH")"
  CONTRACT_LIFECYCLE_REASON="a core-only site: the only external effect in the lifecycle window is WordPress' own theme switch, reviewed against $CLASSIC_THEME_SLUG $CLASSIC_THEME_VERSION"
  loop_to_recovery "$S"
  pass "$S PASSED — a brochure site adopted Duo with the smallest honest loop"
}

# ---------------------------------------------------------------------------
# A2 — block theme (FSE): a customised template part travels.
# ---------------------------------------------------------------------------
situation_a2() {
  local S=A2
  run mkdir -p "$EVIDENCE/$S"
  situation_pair "$S" "$BLOCK_THEME_SLUG@$BLOCK_THEME_VERSION"
  write_registry
  seed_pages duo-walk-landing
  # A customised footer template part: the site editor's own storage shape
  # (wp_template_part post, wp_theme + wp_template_part_area terms), exactly as
  # sandbox/conformance/seeds/fse.sh writes it.
  say "$S — customise the footer template part on ${PAIR}1"
  local footer
  if dry; then
    plan "wp1 post create --post_type=wp_template_part --post_name=footer ... + wp_theme/wp_template_part_area terms"
    footer='<footer-id>'
  else
    footer="$(wp1 post create --post_type=wp_template_part --post_title='Footer' --post_name=footer \
      --post_status=publish --post_content='<!-- wp:paragraph --><p>Duo adoption footer, before the release.</p><!-- /wp:paragraph -->' --porcelain | tr -d '\r')"
    wp1 post term add "$footer" wp_theme "$BLOCK_THEME_SLUG" --by=slug
    wp1 post term add "$footer" wp_template_part_area footer --by=slug
  fi
  doctor_and_first_look "$S" "${PAIR}1"
  init_capture_baseline "$S" "${PAIR}1"
  assess_both "$S" "${PAIR}1" "$HOST_R1" adopted
  if ! dry; then
    # The FSE profile is core's certified profile for exactly these types
    # (manifests/dispositions.json profiles.fse.scope). The point of this
    # situation is what an operator SEES about them after init: in scope and
    # certified, or a named gap with a next action — never silence.
    jq -e '[.surfaces[] | select(.id | test("post_type:wp_template_part"))] | length >= 1' "$ASSESS_JSON" >/dev/null \
      || fail "$S: the customised template part is not a surface the assessment names (post_type:wp_template_part)"
    local got
    got="$(walk_assess_projection "$ASSESS_JSON" post_type:wp_template_part release)"
    note "$S — post_type:wp_template_part release projection: $got"
    pass "$S — the block theme's customised template part is visible to the assessment"
  fi
  CONTRACT_JOURNEYS_JSON="$(journeys_json "$LANDING_PATH")"
  CONTRACT_LIFECYCLE_REASON="a block-theme site with no plugins: the only external effect in the lifecycle window is WordPress' own theme switch, reviewed against $BLOCK_THEME_SLUG $BLOCK_THEME_VERSION"
  contract_cycle "$S" "${PAIR}1" "$HOST_R1" "$LANDING_ID" - '.'
  rehearse_preview "$S"
  say "$S — edit the footer template part on the preview, then capture twice"
  if ! dry; then
    local previewFooter
    previewFooter="$(wp2 post list --post_type=wp_template_part --name=footer --field=ID | tr -d '\r' | head -1)"
    [ -n "$previewFooter" ] || fail "$S: the preview carries no footer template part"
    wp2 post update "$previewFooter" --post_content='<!-- wp:paragraph --><p>Duo adoption footer, released through duo release.</p><!-- /wp:paragraph -->'
  fi
  preview_page_edit "$S" duo-walk-landing '<p>Duo walk landing page, released through duo release.</p>'
  capture_twice "$S" preview
  merge_preview "$S"
  if ! dry; then
    local targetFooter
    targetFooter="$(wp2 post list --post_type=wp_template_part --name=footer --field=ID | tr -d '\r' | head -1)"
    wp2 post update "$targetFooter" --post_content='<!-- wp:paragraph --><p>Duo adoption footer, before the release.</p><!-- /wp:paragraph -->'
  fi
  revert_target "$S" "$PREVIEW_PAGE_ID" '<p>Duo walk landing page, before the release.</p>'
  release_cycle "$S" "$MAIN_SHA"
  if ! dry; then
    wp2 post list --post_type=wp_template_part --name=footer --field=post_content | tr -d '\r' | grep -Fq 'released through duo release' \
      || fail "$S: the footer template part edit did not arrive on the release target"
    pass "$S — the template part edit round-tripped through the release"
  fi
  recover_cycle "$S"
  post_recovery_check "$S"
  reap_cycle "$S"
  pass "$S PASSED — a block theme's site-editor customisations travel through the loop"
}

# shop_journeys — the landing page plus the catalog index, as the walk's
# contract_cycle declares them (WooCommerce answers /?post_type=product 200).
shop_journeys() {
  journeys_json "$LANDING_PATH" "$(jq -nc '{id: "catalog-index", url: "/?post_type=product", expect_status: 200,
    expect_contains: "Duo walk mug", affected_surfaces: ["post_type:product"]}')"
}

# create_order <side> <product-id> <email> — one WooCommerce order through the
# plugin's own API (wc_create_order), as sandbox/conformance/seeds/woocommerce.sh
# does; prints the order id.
create_order() {
  local side="$1" pid="$2" email="$3"
  wp_side "$side" eval "
\$order = wc_create_order();
\$order->set_billing_email('$email');
\$order->add_product(wc_get_product($pid), 1);
\$order->calculate_totals();
\$order->save();
echo \$order->get_id();
" | tr -d '\r' | tail -1
}

# ---------------------------------------------------------------------------
# A3 — a shop that already has orders: the operational-state boundary is
# literal. Orders are runtime/preserve local; an order that lands on the
# target AFTER the release's checkpoint survives the release and is lost only
# on an explicit recover — exactly what the printed boundary says.
# ---------------------------------------------------------------------------
situation_a3() {
  local S=A3
  run mkdir -p "$EVIDENCE/$S"
  situation_pair "$S" "$CLASSIC_THEME_SLUG@$CLASSIC_THEME_VERSION" "woocommerce@$WOO_VERSION"
  write_registry
  seed_shop duo-walk-landing
  say "$S — two live orders on ${PAIR}1 before Duo is even installed"
  if dry; then
    plan "wp1 eval wc_create_order() (x2)"
  else
    create_order 1 "$PRODUCT_ID" 'first-customer@example.test' >/dev/null
    create_order 1 "$PRODUCT_ID" 'second-customer@example.test' >/dev/null
  fi
  doctor_and_first_look "$S" "${PAIR}1"
  if ! dry; then
    local got
    got="$(walk_assess_projection "$ASSESS_JSON" post_type:shop_order release 2>/dev/null || jq -r '[.surfaces[] | select(.id | test("order"))] | map(.id) | join(",")' "$ASSESS_JSON")"
    note "$S — orders as the first look reads them: $got"
    # HPOS (WooCommerce 11): orders live in wc_orders; either way the class is
    # runtime and the handling preserve local — live operational state is
    # never copied.
    jq -e '[.surfaces[] | select((.id | test("order")) and .state_class == "runtime" and .handling == "preserve local")] | length >= 1' "$ASSESS_JSON" >/dev/null \
      || fail "$S: no order surface reads runtime / preserve local before init"
    pass "$S — orders read runtime / preserve local before anything is initialized"
  fi
  init_capture_baseline "$S" "${PAIR}1"
  assess_both "$S" "${PAIR}1" "$HOST_R1" adopted
  CONTRACT_JOURNEYS_JSON="$(shop_journeys)"
  CONTRACT_LIFECYCLE_REASON="the only external effect this shop's installed set has in the lifecycle window is WordPress' own activation/deactivation hooks; reviewed against woocommerce $WOO_VERSION and $CLASSIC_THEME_SLUG $CLASSIC_THEME_VERSION, neither of which runs mail, payment or webhook code on activation"
  contract_cycle "$S" "${PAIR}1" "$HOST_R1" "$LANDING_ID" - '.'
  rehearse_preview "$S"
  say "$S — a catalog change on the preview: a new product, plus the page edit"
  if ! dry; then
    wp2 wc product create --name='Duo walk poster' --type=simple --regular_price=15.00 --sku=DUO-WALK-POSTER \
      --status=publish --user=admin --porcelain >/dev/null
  fi
  preview_page_edit "$S" duo-walk-landing '<p>Duo walk landing page, released through duo release.</p>'
  capture_twice "$S" preview
  merge_preview "$S"
  if ! dry; then
    local previewPoster
    previewPoster="$(wp2 post list --post_type=product --name=duo-walk-poster --field=ID | tr -d '\r' | head -1)"
    [ -n "$previewPoster" ] || fail "$S: the preview did not carry the authored product back"
    wp2 post delete "$previewPoster" --force
  fi
  revert_target "$S" "$PREVIEW_PAGE_ID" '<p>Duo walk landing page, before the release.</p>'
  # The order that arrives on the TARGET after the checkpoint: created between
  # the checkpoint the release takes and the recovery — release_cycle takes
  # the checkpoint inside `duo release --yes`, so the order is placed right
  # after the release returns (still after that checkpoint) and before recover.
  release_cycle "$S" "$MAIN_SHA"
  local lateOrder=""
  if ! dry; then
    lateOrder="$(create_order 2 "$(wp2 post list --post_type=product --name=duo-walk-mug --field=ID | tr -d '\r' | head -1)" 'late-customer@example.test')"
    [ -n "$lateOrder" ] || fail "$S: could not place the post-checkpoint order on the target"
    wp2 wc shop_order get "$lateOrder" --user=admin --field=id >/dev/null \
      || fail "$S: the post-checkpoint order is not readable on the target"
    wp2 post list --post_type=product --field=post_title | tr -d '\r' | grep -Fqx 'Duo walk poster' \
      || fail "$S: the release did not put the authored product on the target"
    pass "$S — the release landed the catalog change; the target keeps taking orders (order $lateOrder placed after the checkpoint)"
  fi
  recover_cycle "$S"
  if ! dry; then
    # The boundary, literally: the post-checkpoint order is gone after an
    # explicit recover — the printed claim says writes committed after the
    # checkpoint are lost on recovery, and the operator chose recovery.
    if wp2 wc shop_order get "$lateOrder" --user=admin --field=id >/dev/null 2>&1; then
      fail "$S: order $lateOrder placed after the checkpoint survived the recovery, contradicting the printed boundary"
    fi
    pass "$S — the boundary is literal: the post-checkpoint order was lost only on the explicit recover"
  fi
  post_recovery_check "$S"
  reap_cycle "$S"
  pass "$S PASSED — a shop with live orders adopted Duo; the operational-state boundary held exactly as printed"
}

# ---------------------------------------------------------------------------
# A4 — shop + SEO + forms: WooCommerce + Yoast + Contact Form 7, all with
# shipped, certified adapters. Multi-adapter composition through one contract;
# a release that touches all three surfaces; journeys for the shop and a page
# carrying a form.
# ---------------------------------------------------------------------------
seed_seo_and_form() {
  # Yoast: per-post SEO meta on the landing page (the adapter's own surface).
  # CF7: one form (wpcf7_contact_form) and a page that embeds it.
  say "seed — Yoast meta on the landing page, one CF7 form and a page that embeds it"
  if dry; then
    plan "wp1 post meta update <landing> _yoast_wpseo_title/_yoast_wpseo_metadesc ...; wp1 post create --post_type=wpcf7_contact_form ...; wp1 post create --post_type=page --post_name=contact-us"
    FORM_ID='<form-id>'; FORM_PAGE_PATH='/contact-us/'
    return 0
  fi
  wp1 post meta update "$LANDING_ID" _yoast_wpseo_title 'Duo walk landing %%sep%% %%sitename%%' >/dev/null
  wp1 post meta update "$LANDING_ID" _yoast_wpseo_metadesc 'The landing page of the Duo adoption shop.' >/dev/null
  FORM_ID="$(wp1 post create --post_type=wpcf7_contact_form --post_status=publish --post_title='Duo adoption enquiry' \
    --post_name=duo-adoption-enquiry --porcelain | tr -d '\r')"
  wp1 post meta update "$FORM_ID" _form '<label> Your name [text* your-name] </label> <label> Your email [email* your-email] </label> [submit "Send"]' >/dev/null
  wp1 post meta update "$FORM_ID" _mail "$(printf '%s' '{"active":true,"subject":"Duo adoption enquiry","sender":"[your-name] <wordpress@example.test>","recipient":"owner@example.test","body":"From: [your-name] <[your-email]>","additional_headers":"Reply-To: [your-email]","attachments":"","use_html":false,"exclude_blank":false}')" --format=json >/dev/null
  local formPage
  formPage="$(wp1 post create --post_type=page --post_status=publish --post_title='Contact us' --post_name=contact-us \
    --post_content="<p>Write to us.</p>[contact-form-7 id=\"$FORM_ID\" title=\"Duo adoption enquiry\"]" --porcelain | tr -d '\r')"
  local url
  url="$(wp1 post list --post_type=page --name=contact-us --field=url | tr -d '\r' | head -1)"
  FORM_PAGE_PATH="$(printf '%s' "$url" | sed -E 's#^https?://[^/]+##')"
  [ -n "$FORM_ID" ] && [ -n "$formPage" ] || fail "the SEO/form seed did not produce a form and a page"
  return 0
}
FORM_ID=""; FORM_PAGE_PATH=""

situation_a4() {
  local S=A4
  run mkdir -p "$EVIDENCE/$S"
  situation_pair "$S" "$CLASSIC_THEME_SLUG@$CLASSIC_THEME_VERSION" \
    "woocommerce@$WOO_VERSION" "wordpress-seo@$YOAST_VERSION" "contact-form-7@$CF7_VERSION"
  write_registry
  seed_shop duo-walk-landing
  seed_seo_and_form
  doctor_and_first_look "$S" "${PAIR}1"
  if ! dry; then
    # Three certified adapters, three certified surfaces, one assessment.
    jq -e '[.surfaces[] | select(.id | startswith("plugin:"))] | length == 0' "$ASSESS_JSON" >/dev/null \
      || fail "$S: an active plugin has no owning adapter on the first look (plugin: row present); every plugin here ships a certified adapter"
    local id got
    for id in post_type:product post_type:wpcf7_contact_form; do
      got="$(walk_assess_projection "$ASSESS_JSON" "$id" release | cut -f3-4)"
      [ "$got" = $'Ready\tPlatform-certified' ] || fail "$S: $id read '$got' before init, expected Ready / Platform-certified"
    done
    pass "$S — three shipped adapters, every governed surface Ready / Platform-certified before init"
  fi
  init_capture_baseline "$S" "${PAIR}1"
  assess_both "$S" "${PAIR}1" "$HOST_R1" adopted
  local formJourney
  formJourney="$(jq -nc --arg url "$FORM_PAGE_PATH" '{id: "contact-page", url: $url, expect_status: 200,
    expect_contains: "wpcf7-form", affected_surfaces: ["post_type:page", "post_type:wpcf7_contact_form"]}')"
  CONTRACT_JOURNEYS_JSON="$(shop_journeys)"
  CONTRACT_LIFECYCLE_REASON="the only external effect this shop's installed set has in the lifecycle window is WordPress' own activation/deactivation hooks; reviewed against woocommerce $WOO_VERSION, wordpress-seo $YOAST_VERSION, contact-form-7 $CF7_VERSION and $CLASSIC_THEME_SLUG $CLASSIC_THEME_VERSION, none of which run mail, payment or webhook code on activation"
  contract_cycle "$S" "${PAIR}1" "$HOST_R1" "$LANDING_ID" "$formJourney" '.'
  rehearse_preview "$S"
  say "$S — one edit per adapter on the preview: product price, Yoast title, form label"
  if ! dry; then
    local previewProduct previewForm
    previewProduct="$(wp2 post list --post_type=product --name=duo-walk-mug --field=ID | tr -d '\r' | head -1)"
    previewForm="$(wp2 post list --post_type=wpcf7_contact_form --name=duo-adoption-enquiry --field=ID | tr -d '\r' | head -1)"
    [ -n "$previewProduct" ] && [ -n "$previewForm" ] || fail "$S: the preview lacks the product or the form"
    wp2 wc product update "$previewProduct" --regular_price=26.00 --user=admin >/dev/null
    wp2 post meta update "$(wp2 post list --post_type=page --name=duo-walk-landing --field=ID | tr -d '\r' | head -1)" _yoast_wpseo_title 'Duo walk landing, released %%sep%% %%sitename%%' >/dev/null
    wp2 post meta update "$previewForm" _form '<label> Your name [text* your-name] </label> <label> Your email [email* your-email] </label> <label> Message [textarea your-message] </label> [submit "Send"]' >/dev/null
  fi
  preview_page_edit "$S" duo-walk-landing '<p>Duo walk landing page, released through duo release.</p>'
  capture_twice "$S" preview
  merge_preview "$S"
  # Put the target back so the release has all four changes to apply.
  if ! dry; then
    local targetProduct targetForm targetLanding
    targetProduct="$(wp2 post list --post_type=product --name=duo-walk-mug --field=ID | tr -d '\r' | head -1)"
    targetForm="$(wp2 post list --post_type=wpcf7_contact_form --name=duo-adoption-enquiry --field=ID | tr -d '\r' | head -1)"
    targetLanding="$(wp2 post list --post_type=page --name=duo-walk-landing --field=ID | tr -d '\r' | head -1)"
    wp2 wc product update "$targetProduct" --regular_price=24.00 --user=admin >/dev/null
    wp2 post meta update "$targetLanding" _yoast_wpseo_title 'Duo walk landing %%sep%% %%sitename%%' >/dev/null
    wp2 post meta update "$targetForm" _form '<label> Your name [text* your-name] </label> <label> Your email [email* your-email] </label> [submit "Send"]' >/dev/null
  fi
  revert_target "$S" "$PREVIEW_PAGE_ID" '<p>Duo walk landing page, before the release.</p>'
  release_cycle "$S" "$MAIN_SHA"
  if ! dry; then
    local price title form
    price="$(wp2 wc product get "$(wp2 post list --post_type=product --name=duo-walk-mug --field=ID | tr -d '\r' | head -1)" --user=admin --field=regular_price | tr -d '\r')"
    [ "$price" = "26.00" ] || [ "$price" = "26" ] || fail "$S: the product price edit did not arrive on the target (got '$price')"
    title="$(wp2 post meta get "$(wp2 post list --post_type=page --name=duo-walk-landing --field=ID | tr -d '\r' | head -1)" _yoast_wpseo_title | tr -d '\r')"
    [[ "$title" == *released* ]] || fail "$S: the Yoast title edit did not arrive on the target (got '$title')"
    form="$(wp2 post meta get "$(wp2 post list --post_type=wpcf7_contact_form --name=duo-adoption-enquiry --field=ID | tr -d '\r' | head -1)" _form | tr -d '\r')"
    [[ "$form" == *your-message* ]] || fail "$S: the CF7 form edit did not arrive on the target"
    pass "$S — one release carried a WooCommerce, a Yoast and a CF7 change together"
  fi
  recover_cycle "$S"
  post_recovery_check "$S"
  reap_cycle "$S"
  pass "$S PASSED — three certified adapters composed through one contract and one release"
}

# ---------------------------------------------------------------------------
# A5 — multilingual shop: WooCommerce + Polylang. Languages and translations
# are captured; a translated product edit is released; Polylang's non-public
# `polylang_mo` post type is named honestly — declared or left local by
# decision, never silently.
# ---------------------------------------------------------------------------
seed_languages() {
  say "seed — two languages (Polylang's own model), a translated product pair"
  if dry; then
    plan "wp1 eval PLL()->model->languages->add(en_US, fr_FR); wp1 wc product create (fr) + pll_save_post_translations"
    PRODUCT_FR_ID='<product-fr-id>'
    return 0
  fi
  wp1 eval '
$languages = [
    ["locale" => "en_US", "slug" => "en", "name" => "English", "rtl" => 0, "term_group" => 0, "flag" => "us"],
    ["locale" => "fr_FR", "slug" => "fr", "name" => "French", "rtl" => 0, "term_group" => 1, "flag" => "fr"],
];
$model = PLL()->model;
foreach ($languages as $args) {
    $result = isset($model->languages) ? $model->languages->add($args) : (new PLL_Admin_Model(PLL()->options))->add_language($args);
    if (is_wp_error($result)) { fwrite(STDERR, $result->get_error_message() . "\n"); exit(1); }
}
echo "languages added\n";
' >/dev/null
  PRODUCT_FR_ID="$(wp1 wc product create --name='Tasse Duo' --type=simple --regular_price=24.00 --sku=DUO-WALK-MUG-FR \
    --status=publish --user=admin --porcelain | tr -d '\r')"
  wp1 eval "
pll_set_post_language($PRODUCT_ID, 'en');
pll_set_post_language($PRODUCT_FR_ID, 'fr');
pll_save_post_translations(['en' => $PRODUCT_ID, 'fr' => $PRODUCT_FR_ID]);
pll_set_post_language($LANDING_ID, 'en');
echo 'translations saved';
" >/dev/null
  [ -n "$PRODUCT_FR_ID" ] || fail "the language seed did not produce a French product"
  return 0
}
PRODUCT_FR_ID=""

situation_a5() {
  local S=A5
  run mkdir -p "$EVIDENCE/$S"
  situation_pair "$S" "$CLASSIC_THEME_SLUG@$CLASSIC_THEME_VERSION" \
    "woocommerce@$WOO_VERSION" "polylang@$POLYLANG_VERSION"
  write_registry
  seed_shop duo-walk-landing
  seed_languages
  doctor_and_first_look "$S" "${PAIR}1"
  if ! dry; then
    # polylang_mo (Polylang's string-translation storage) is a non-public post
    # type the plugin registers. The point of this situation is what the
    # operator reads about it: a named row with a decision or a next action.
    local moRow
    moRow="$(jq -r '[.surfaces[] | select(.id | test("polylang_mo"))] | map("\(.id)\t\(.state_class)\t\(.handling)\t\(.next_action)") | join(";")' "$ASSESS_JSON")"
    note "$S — polylang_mo as the first look reads it: ${moRow:-(no row)}"
    jq -e '[.surfaces[] | select(.id | test("post_type:product"))] | length == 1' "$ASSESS_JSON" >/dev/null \
      || fail "$S: no post_type:product row"
    pass "$S — languages, translations and the shop are all in the first look"
  fi
  init_capture_baseline "$S" "${PAIR}1"
  assess_both "$S" "${PAIR}1" "$HOST_R1" adopted
  if ! dry; then
    # After init the decision about polylang_mo is written down somewhere: in
    # scope (adapter declares it), left local as runtime (advisory), or a
    # classify queue item — asserted as "named", the exact word recorded.
    local moAfter
    moAfter="$(jq -r '[.surfaces[] | select(.id | test("polylang_mo"))] | map("\(.id)\t\(.state_class)\t\(.handling)\t\(.next_action)") | join(";")' "$ASSESS_JSON")"
    note "$S — polylang_mo after init: ${moAfter:-(no row)}"
    if grep -q 'polylang_mo' "$EVIDENCE/$S/init.txt"; then
      pass "$S — init named polylang_mo (see init.txt)"
    else
      jq -e '[.surfaces[] | select(.id | test("polylang_mo"))] | length >= 1' "$ASSESS_JSON" >/dev/null \
        || fail "$S: polylang_mo is neither named by init nor a surface of the assessment — it was left silent"
      pass "$S — polylang_mo is a named surface after init"
    fi
  fi
  CONTRACT_JOURNEYS_JSON="$(shop_journeys)"
  CONTRACT_LIFECYCLE_REASON="the only external effect this shop's installed set has in the lifecycle window is WordPress' own activation/deactivation hooks; reviewed against woocommerce $WOO_VERSION, polylang $POLYLANG_VERSION and $CLASSIC_THEME_SLUG $CLASSIC_THEME_VERSION, none of which run mail, payment or webhook code on activation"
  contract_cycle "$S" "${PAIR}1" "$HOST_R1" "$LANDING_ID" - '.'
  rehearse_preview "$S"
  say "$S — edit the FRENCH product on the preview, then capture twice"
  if ! dry; then
    local previewFr
    previewFr="$(wp2 post list --post_type=product --name=tasse-duo --field=ID | tr -d '\r' | head -1)"
    [ -n "$previewFr" ] || fail "$S: the preview lacks the French product"
    wp2 wc product update "$previewFr" --description='<p>Tasse Duo, publiée par duo release.</p>' --user=admin >/dev/null
  fi
  preview_page_edit "$S" duo-walk-landing '<p>Duo walk landing page, released through duo release.</p>'
  capture_twice "$S" preview
  merge_preview "$S"
  if ! dry; then
    local targetFr
    targetFr="$(wp2 post list --post_type=product --name=tasse-duo --field=ID | tr -d '\r' | head -1)"
    wp2 wc product update "$targetFr" --description='' --user=admin >/dev/null
  fi
  revert_target "$S" "$PREVIEW_PAGE_ID" '<p>Duo walk landing page, before the release.</p>'
  release_cycle "$S" "$MAIN_SHA"
  if ! dry; then
    wp2 post list --post_type=product --name=tasse-duo --field=post_content | tr -d '\r' | grep -Fq 'duo release' \
      || fail "$S: the French product edit did not arrive on the target"
    wp2 eval "echo pll_get_post_language($(wp2 post list --post_type=product --name=tasse-duo --field=ID | tr -d '\r' | head -1));" | tr -d '\r' | grep -qx fr \
      || fail "$S: the French product lost its language on the target"
    pass "$S — the translated product edit arrived with its language intact"
  fi
  recover_cycle "$S"
  post_recovery_check "$S"
  reap_cycle "$S"
  pass "$S PASSED — a multilingual shop adopted Duo; translations travelled and the non-public type was named"
}

# ---------------------------------------------------------------------------
# A6–A10 — round 2 (built after round 1 is green).
# ---------------------------------------------------------------------------
situation_a6()  { fail "A6 is not built yet (round 2: Elementor + ACF + block theme)"; }
situation_a7()  { fail "A7 is not built yet (round 2: WPForms on A4's shop, S1 then S2)"; }
situation_a8()  { fail "A8 is not built yet (round 2: acme-catalog + a plugin/adapter code release)"; }
situation_a9()  { fail "A9 is not built yet (round 2: version edges)"; }
situation_a10() { fail "A10 is not built yet (round 2: edge cases)"; }

# ---------------------------------------------------------------------------
# --self-check: the shared helpers against the walk's recorded fixtures (the
# lib's own self_check), plus this grind's pure helpers.
# ---------------------------------------------------------------------------
adoption_self_check() {
  say "self-check — this grind's pure helpers"
  local j
  j="$(LANDING_PATH=/landing/ journeys_json /landing/ "$(jq -nc '{id: "x", url: "/x/", expect_status: 200, expect_contains: "x", affected_surfaces: []}')")"
  [ "$(jq -r 'length' <<<"$j")" = 2 ] && [ "$(jq -r '.[0].id' <<<"$j")" = landing-page ] \
    && pass "journeys_json composes the landing journey with extras" \
    || soft_fail "journeys_json produced: $j"
  local sj
  sj="$(LANDING_PATH=/landing/ shop_journeys)"
  [ "$(jq -r 'map(.id) | join(",")' <<<"$sj")" = "landing-page,catalog-index" ] \
    && pass "shop_journeys is the walk's two default journeys" \
    || soft_fail "shop_journeys produced: $sj"
  if [ "$FAILURES" -ne 0 ]; then
    printf '\nGRIND_ADOPTION SELF-CHECK FAILED (%d)\n' "$FAILURES" >&2
    exit 1
  fi
}

preflight() {
  [[ "$PAIR" =~ ^[a-z][a-z0-9]*$ ]] \
    || fail "ADOPT_PAIR '$PAIR' is invalid (pair.sh grammar: lowercase letters/digits, letter first)"
  if [ "$PAIR" != adopt ] && { [ -z "${ADOPT_PORT1:-}" ] || [ -z "${ADOPT_PORT2:-}" ]; }; then
    fail "a custom ADOPT_PAIR '$PAIR' requires explicit ADOPT_PORT1 and ADOPT_PORT2 (9600/9601 belong to the shared 'adopt' instance)"
  fi
  [[ "$PORT1" =~ ^[0-9]+$ && "$PORT2" =~ ^[0-9]+$ && "$PORT1" != "$PORT2" ]] \
    || fail "ADOPT_PORT1/ADOPT_PORT2 must be two different numeric host ports (got '$PORT1'/'$PORT2')"
  case "$WORDPRESS_OFFLINE" in 0|1) ;; *) fail "DUO_WORDPRESS_ORG_OFFLINE must be 0 or 1" ;; esac
  [ -f "$DUO" ] || fail "host CLI missing: $DUO"
  [ -f "$REPO_ROOT/tools/reference-env-provider.php" ] \
    || fail "the reference environment provider is missing: $REPO_ROOT/tools/reference-env-provider.php"
  local pin
  for pin in "themes:$CLASSIC_THEME_SLUG:$CLASSIC_THEME_VERSION" "themes:$BLOCK_THEME_SLUG:$BLOCK_THEME_VERSION" \
      "plugins:woocommerce:$WOO_VERSION" "plugins:woocommerce:$WOO_OLD_VERSION" \
      "plugins:wordpress-seo:$YOAST_VERSION" "plugins:wordpress-seo:$YOAST_OLD_VERSION" \
      "plugins:contact-form-7:$CF7_VERSION" "plugins:polylang:$POLYLANG_VERSION" \
      "plugins:elementor:$ELEMENTOR_VERSION" "plugins:advanced-custom-fields:$ACF_VERSION" \
      "plugins:$WPFORMS_SLUG:$WPFORMS_VERSION"; do
    local kind="${pin%%:*}" rest="${pin#*:}" slug version
    slug="${rest%%:*}"; version="${rest#*:}"
    jq -e --arg k "$kind" --arg s "$slug" --arg v "$version" '.[$k][$s][$v]' conformance/artifacts.lock.json >/dev/null \
      || fail "conformance/artifacts.lock.json has no pin for $kind $slug $version"
  done
  local situation
  for situation in ${SITUATIONS//,/ }; do
    case "$situation" in
      A1|A2|A3|A4|A5|A6|A7|A8|A9|A10) ;;
      *) fail "ADOPT_SITUATIONS names '$situation'; the plan defines A1–A10" ;;
    esac
  done
  pass "pinned artifacts resolved for every situation"
  pass "situations selected: $SITUATIONS"
}

# ---------------------------------------------------------------------------
# Modes that stop before docker.
# ---------------------------------------------------------------------------
require jq
require php
self_check
adoption_self_check
if [ "$MODE" = self-check ]; then
  printf '\nGRIND_ADOPTION SELF-CHECK PASSED\n' >&3
  exit 0
fi
if ! dry; then
  require docker
  require git
  require curl
fi

R1="siterepo/${PAIR}1"
R2="siterepo/${PAIR}2"
ORIGIN="$SANDBOX/siterepo/origin-${PAIR}.git"
HOST_R1="$SANDBOX/$R1"
HOST_R2="$SANDBOX/$R2"

preflight

if dry; then
  SCRATCH="$SANDBOX/tmp/grind-adoption-dry-run"
else
  mkdir -p "$SANDBOX/tmp"
  SCRATCH="$(mktemp -d "$SANDBOX/tmp/grind-adoption.XXXXXX")"
fi
ENVS_FILE="$SCRATCH/.duo-envs.json"
PROVIDER_CONFIG="$SCRATCH/reference-env-provider.json"
PROVIDER_STATE="$SCRATCH/provider-state"
EVIDENCE="$SCRATCH/evidence"
KEYDIR="$SCRATCH/keys"
PHP_BIN="$(command -v php)"
PROVIDER="$REPO_ROOT/tools/reference-env-provider.php"

trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

export DUO_PAIR="$PAIR" DUO_PORT1="$PORT1" DUO_PORT2="$PORT2"
export DUO_ARTIFACT_OFFLINE="$WORDPRESS_OFFLINE"
export DUO_ARTIFACT_LOCKFILE="$SANDBOX/conformance/artifacts.lock.json"
COMPOSE_FILES=("$SANDBOX/pair.yml" "$SANDBOX/pair.http.yml" "$SANDBOX/pair.artifacts.yml")
PAIR_UP_FLAGS=(--http --artifacts)
if [ "$WORDPRESS_OFFLINE" = 1 ]; then
  COMPOSE_FILES+=("$SANDBOX/pair.wordpress-offline.yml")
  PAIR_UP_FLAGS+=(--wordpress-offline)
fi
COMPOSE=(docker compose -p "duo-$PAIR")
for file in "${COMPOSE_FILES[@]}"; do COMPOSE+=(-f "$file"); done
PAIR_COMPOSE=("${COMPOSE[@]}")
# shellcheck source=../bin/fetch-artifact.sh
. bin/fetch-artifact.sh
DUO_CLI_IMAGE="${DUO_CLI_IMAGE:-duo-walk-cli-git:$PAIR}"
export DUO_CLI_IMAGE
WALK_KEEP="${ADOPT_KEEP:-0}"

run mkdir -p "$PROVIDER_STATE" "$EVIDENCE" "$KEYDIR"

for situation in ${SITUATIONS//,/ }; do
  case "$situation" in
    A1) situation_a1 ;;  A2) situation_a2 ;;  A3) situation_a3 ;;  A4) situation_a4 ;;  A5) situation_a5 ;;
    A6) situation_a6 ;;  A7) situation_a7 ;;  A8) situation_a8 ;;  A9) situation_a9 ;;  A10) situation_a10 ;;
  esac
  SITUATIONS_RUN+=("$situation")
done

if dry; then
  printf '\n\033[1;32mGRIND_ADOPTION DRY RUN COMPLETE — nothing above was executed\033[0m\n' >&3
  exit 0
fi
if [ "$FAILURES" -ne 0 ]; then
  printf '\nGRIND_ADOPTION FAILED (%d)\n' "$FAILURES" >&2
  exit 1
fi
printf '\n\033[1;32m✔ GRIND_ADOPTION PASSED (%s)\033[0m\n' "$(IFS=,; printf '%s' "${SITUATIONS_RUN[*]}")" >&3
printf 'evidence: %s\n' "$EVIDENCE" >&3
