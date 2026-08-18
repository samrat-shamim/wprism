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
  # The adoption seed first: `duo doctor` checks that the repo path holds a
  # site.duo.json (a [FAIL] row without one — the honest answer, and not the
  # first look this situation is about), and `duo assess` reads that seed.
  seed_repository "$label"
  say "$label — duo doctor $env (read-only) and duo assess $env on the adoption seed"
  duo_ok "$EVIDENCE/$label/doctor.txt" "$HOST_R1" doctor "$env"
  if ! dry; then
    grep -q '^\[FAIL\]' "$EVIDENCE/$label/doctor.txt" && fail "$label: duo doctor reports a FAIL row on a fresh pair; see $EVIDENCE/$label/doctor.txt"
  fi
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
  capture_twice "$label" "${PAIR}2"
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
  if ! dry; then
    # The FSE profile is core's certified profile for exactly these types
    # (manifests/dispositions.json profiles.fse.scope). A block theme's
    # site-editor customisations live in non-public _builtin types the scope
    # gate never names, so init proposes the profile's scope itself and SAYS so
    # — the alternative was a customised footer left behind silently.
    grep -Fq '[fse_profile_scope_selected]' "$EVIDENCE/$S/init.txt" \
      || fail "$S: init did not print the FSE profile scope advisory for a block theme; see $EVIDENCE/$S/init.txt"
    jq -e '([.policy.post_types[]] | index("wp_template_part") != null) and ([.policy.taxonomies[]] | index("wp_theme") != null)' \
      "$HOST_R1/site.duo.json" >/dev/null \
      || fail "$S: init did not take the FSE profile's types (wp_template_part, wp_theme) into policy scope"
    pass "$S — init proposed the certified FSE profile scope for the block theme and printed it"
  fi
  assess_both "$S" "${PAIR}1" "$HOST_R1" adopted
  if ! dry; then
    jq -e '[.surfaces[] | select(.id == "post_type:wp_template_part")] | length == 1' "$ASSESS_JSON" >/dev/null \
      || fail "$S: the customised template part is not a surface the assessment names (post_type:wp_template_part)"
    local got
    got="$(walk_assess_projection "$ASSESS_JSON" post_type:wp_template_part release)"
    note "$S — post_type:wp_template_part release projection: $got"
    [ "$(printf '%s' "$got" | cut -f3-4)" = $'Ready\tPlatform-certified' ] \
      || fail "$S: post_type:wp_template_part read '$got', expected Ready / Platform-certified under the certified FSE profile"
    pass "$S — the block theme's customised template part is a Ready, Platform-certified surface"
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
  capture_twice "$S" "${PAIR}2"
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
  say "$S — the page edit on the preview; the catalog change on the SOURCE (a new product)"
  preview_page_edit "$S" duo-walk-landing '<p>Duo walk landing page, released through duo release.</p>'
  capture_twice "$S" "${PAIR}2"
  merge_preview "$S"
  # The new product is authored on the source and captured there, so it is
  # genuinely absent from the target until the release creates it. (Authoring
  # it on the preview and deleting it from the target — the walk's pattern for
  # its own adapters — is not available here: manifests/woocommerce.json keeps
  # product deletion fail-closed, so a capture that finds a product gone
  # refuses the deletion intent by design.)
  # The source clone first takes main (the preview's page edit), so its own
  # capture builds on it; the source's live page is edited to the same
  # released body so main carries the page edit AND the product, and the
  # release applies both to the reverted target.
  git1 fetch -q origin main
  git1 merge -q --ff-only FETCH_HEAD
  if ! dry; then
    wp1 post update "$LANDING_ID" --post_content='<p>Duo walk landing page, released through duo release.</p>' >/dev/null
    wp1 wc product create --name='Duo walk poster' --type=simple --regular_price=15.00 --sku=DUO-WALK-POSTER \
      --status=publish --user=admin --porcelain >/dev/null
  fi
  duo_ok "$EVIDENCE/$S/capture-source-product.txt" "$HOST_R1" capture "${PAIR}1"
  git1 add -A
  commit1 "grind_adoption $S: a new product authored on the source"
  git1 push -q origin main
  # `duo release --from=<sha>` resolves the ref in the TARGET clone; a
  # revision that arrived on origin from the source is fetched there first.
  git2 fetch -q origin main
  if ! dry; then
    MAIN_SHA="$(git -C "$ORIGIN" rev-parse main)"
    [ -n "$MAIN_SHA" ] || fail "$S: the origin has no main revision after the source capture"
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
  # The form through CF7's own API (as sandbox/conformance/seeds/contact-form-7.sh
  # does): CF7's shortcode embeds id="<hash7>", a portable identity, never the
  # numeric post id — a hand-written [contact-form-7 id="<ID>"] would break on
  # the target, where the form has another local id.
  cat > "$HOST_R1/.tmp-cf7-seed.php" <<'PHPEOF'
<?php
if (!class_exists('WPCF7_ContactForm')) { fwrite(STDERR, "WPCF7_ContactForm not loaded\n"); exit(1); }
wp_set_current_user(get_user_by('login', 'admin')->ID);
$cf = WPCF7_ContactForm::get_template(['title' => 'Duo adoption enquiry']);
$mail = $cf->prop('mail');
$mail['recipient'] = 'owner@example.test';
$mail['subject'] = '[Duo adoption] [your-subject]';
$cf->set_properties(['mail' => $mail, 'form' => '<label> Your name [text* your-name] </label> <label> Your email [email* your-email] </label> [submit "Send"]']);
$id = $cf->save();
if (!$id) { fwrite(STDERR, "CF7 save() failed\n"); exit(1); }
$cf = WPCF7_ContactForm::get_instance($id);
echo "cf7_id=" . $id . "\n";
echo "cf7_shortcode=" . $cf->shortcode() . "\n";
PHPEOF
  local cf7Out cf7Shortcode
  cf7Out="$(wp1 eval-file /siterepo/.tmp-cf7-seed.php | tr -d '\r')" || fail "the CF7 seed failed"
  rm -f "$HOST_R1/.tmp-cf7-seed.php"
  FORM_ID="$(printf '%s\n' "$cf7Out" | sed -n 's/^cf7_id=//p')"
  cf7Shortcode="$(printf '%s\n' "$cf7Out" | sed -n 's/^cf7_shortcode=//p')"
  [ -n "$FORM_ID" ] && [ -n "$cf7Shortcode" ] || fail "the CF7 seed produced no form/shortcode"
  local formPage
  formPage="$(wp1 post create --post_type=page --post_status=publish --post_title='Contact us' --post_name=contact-us \
    --post_content="<p>Write to us.</p>$cf7Shortcode" --porcelain | tr -d '\r')"
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
  capture_twice "$S" "${PAIR}2"
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
  capture_twice "$S" "${PAIR}2"
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
# ---------------------------------------------------------------------------
# A6 — builder site: Elementor + ACF + block theme. ACF field groups and
# Elementor pages/templates by decision; a design edit round-trips.
# manifests/elementor.json's own note: elementor_library is a scope the site
# must opt into — this situation walks that opt-in as an operator would.
# ---------------------------------------------------------------------------
seed_builder() {
  say "seed — an ACF field group with a value on the landing page, and an Elementor-built page"
  if dry; then
    plan "wp1 eval-file (acf_update_field_group + acf_update_field + update_field on the landing page)"
    plan "wp1 eval-file (Elementor Document::save with a heading widget) + one front-end render"
    BUILDER_PAGE_PATH='/duo-builder-page/'
    return 0
  fi
  cat > "$HOST_R1/.tmp-seed-acf.php" <<'PHPEOF'
<?php
if (!function_exists('acf_update_field_group')) { fwrite(STDERR, "ACF functions not available\n"); exit(1); }
acf_update_field_group([
    'key' => 'group_duo_adoption', 'title' => 'Duo Adoption', 'fields' => [],
    'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'page']]],
    'menu_order' => 0, 'position' => 'normal', 'style' => 'default',
    'label_placement' => 'top', 'instruction_placement' => 'label', 'active' => true,
]);
$g = get_posts(['post_type' => 'acf-field-group', 'name' => 'group_duo_adoption', 'posts_per_page' => 1, 'fields' => 'ids', 'post_status' => 'any']);
$gid = $g ? (int) $g[0] : 0;
if (!$gid) { fwrite(STDERR, "field group not created\n"); exit(1); }
acf_update_field(['key' => 'field_duo_tagline', 'label' => 'Tagline', 'name' => 'duo_tagline', 'type' => 'text', 'parent' => $gid]);
$landing = get_posts(['post_type' => 'page', 'name' => 'duo-walk-landing', 'posts_per_page' => 1, 'fields' => 'ids', 'post_status' => 'any']);
if (!$landing) { fwrite(STDERR, "no landing page\n"); exit(1); }
update_field('field_duo_tagline', 'Built with care, before the release.', (int) $landing[0]);
echo "acf seed: group=$gid landing=" . $landing[0] . "\n";
PHPEOF
  wp1 eval-file /siterepo/.tmp-seed-acf.php >/dev/null || fail "the ACF seed failed"
  rm -f "$HOST_R1/.tmp-seed-acf.php"
  local builderPage
  builderPage="$(wp1 post create --post_type=page --post_status=publish --post_title='Duo builder page' \
    --post_name=duo-builder-page --post_content='' --porcelain | tr -d '\r')"
  cat > "$HOST_R1/.tmp-seed-elementor.php" <<PHPEOF
<?php
error_reporting(E_ALL & ~E_DEPRECATED);
\$admins = get_users(['role' => 'administrator', 'number' => 1]);
if (\$admins) { wp_set_current_user(\$admins[0]->ID); }
\$page_id = $builderPage;
update_post_meta(\$page_id, '_elementor_edit_mode', 'builder');
update_post_meta(\$page_id, '_elementor_template_type', 'wp-page');
\$elements = [[
    'id' => 'adsec0001', 'elType' => 'section', 'settings' => [],
    'elements' => [[
        'id' => 'adcol0001', 'elType' => 'column', 'settings' => ['_column_size' => 100],
        'elements' => [[
            'id' => 'adhead001', 'elType' => 'widget', 'widgetType' => 'heading',
            'settings' => ['title' => 'Duo builder heading, before the release.'], 'elements' => [],
        ]],
    ]],
]];
\$doc = \Elementor\Plugin::\$instance->documents->get(\$page_id);
\$result = \$doc->save(['elements' => \$elements]);
if (\$result === false) { fwrite(STDERR, "Elementor Document::save() returned false\n"); exit(1); }
echo "elementor seed: page=\$page_id\n";
PHPEOF
  wp1 eval-file /siterepo/.tmp-seed-elementor.php >/dev/null || fail "the Elementor seed failed"
  rm -f "$HOST_R1/.tmp-seed-elementor.php"
  local url
  url="$(wp1 post list --post_type=page --name=duo-builder-page --field=url | tr -d '\r' | head -1)"
  BUILDER_PAGE_PATH="$(printf '%s' "$url" | sed -E 's#^https?://[^/]+##')"
  # Elementor materialises its CSS/cache meta on the first front-end render.
  curl -fs "http://127.0.0.1:${PORT1}${BUILDER_PAGE_PATH}" >/dev/null || fail "the builder page did not render on ${PAIR}1"
  return 0
}
BUILDER_PAGE_PATH=""

situation_a6() {
  local S=A6
  run mkdir -p "$EVIDENCE/$S"
  situation_pair "$S" "$BLOCK_THEME_SLUG@$BLOCK_THEME_VERSION" \
    "elementor@$ELEMENTOR_VERSION" "advanced-custom-fields@$ACF_VERSION"
  write_registry
  seed_pages duo-walk-landing
  seed_builder
  doctor_and_first_look "$S" "${PAIR}1"
  if ! dry; then
    local libRow
    libRow="$(jq -r '[.surfaces[] | select(.id | test("elementor_library"))] | map("\(.id)\t\(.state_class)\t\(.handling)\t\(.next_action)") | join(";")' "$ASSESS_JSON")"
    note "$S — elementor_library as the first look reads it: ${libRow:-(no row)}"
    jq -e '[.surfaces[] | select(.id | test("post_type:acf-field-group"))] | length == 1' "$ASSESS_JSON" >/dev/null \
      || fail "$S: no post_type:acf-field-group row on the first look"
    pass "$S — ACF field groups are a named, adapter-governed surface before init"
  fi
  # The opt-in, walked as an operator: init proposes the adapters' authored
  # types; whatever Elementor registers that holds rows and nothing declares
  # is either left local by init's own advisory or refused by the scope gate
  # with the classify decision named. Either way it is a NAMED stop, and the
  # operator decides elementor_library into scope with `duo classify`.
  say "$S — duo init ${PAIR}1 --yes"
  local initOut="$EVIDENCE/$S/init.txt"
  if dry; then
    plan "(cd $HOST_R1 && duo init ${PAIR}1 --yes) ; on incomplete_policy_scope: duo classify scope:post_type:elementor_library=authored, rerun"
  else
    if ( cd "$HOST_R1" && php "$DUO" "--envs-file=$ENVS_FILE" init "${PAIR}1" --yes ) > "$initOut" 2>&1; then
      pass "$S — init proceeded (elementor_library: $(grep -c elementor_library "$initOut") mention(s) in the init report)"
    else
      local code
      code="$(walk_refusal_code "$initOut" 2>/dev/null || true)"
      [ "$code" = incomplete_policy_scope ] \
        || fail "$S: duo init refused with [${code:-no typed reason code}], not the scope decision this situation expects; see $initOut"
      grep -Fq 'scope:post_type:elementor_library' "$initOut" \
        || fail "$S: the scope refusal does not name elementor_library"
      pass "$S — init stopped on a NAMED scope decision (elementor_library); deciding it"
      duo_ok "$EVIDENCE/$S/classify.txt" "$HOST_R1" classify "${PAIR}1" --accept-proposals \
        || true
      duo_ok "$initOut" "$HOST_R1" init "${PAIR}1" --yes
    fi
  fi
  duo_ok "$EVIDENCE/$S/capture.txt" "$HOST_R1" capture "${PAIR}1"
  baseline_commit "$S"
  assess_both "$S" "${PAIR}1" "$HOST_R1" adopted
  local builderJourney
  builderJourney="$(jq -nc --arg url "$BUILDER_PAGE_PATH" '{id: "builder-page", url: $url, expect_status: 200,
    expect_contains: "Duo builder heading", affected_surfaces: ["post_type:page"]}')"
  CONTRACT_JOURNEYS_JSON="$(journeys_json "$LANDING_PATH")"
  CONTRACT_LIFECYCLE_REASON="the only external effect this site's installed set has in the lifecycle window is WordPress' own activation/deactivation hooks; reviewed against elementor $ELEMENTOR_VERSION, advanced-custom-fields $ACF_VERSION and $BLOCK_THEME_SLUG $BLOCK_THEME_VERSION, none of which run mail, payment or webhook code on activation"
  contract_cycle "$S" "${PAIR}1" "$HOST_R1" "$LANDING_ID" "$builderJourney" '.'
  rehearse_preview "$S"
  say "$S — a design edit on the preview: the Elementor heading and the ACF tagline"
  if ! dry; then
    local previewBuilder previewLanding
    previewBuilder="$(wp2 post list --post_type=page --name=duo-builder-page --field=ID | tr -d '\r' | head -1)"
    previewLanding="$(wp2 post list --post_type=page --name=duo-walk-landing --field=ID | tr -d '\r' | head -1)"
    [ -n "$previewBuilder" ] && [ -n "$previewLanding" ] || fail "$S: the preview lacks the builder page or the landing page"
    wp2 eval "
\$admins = get_users(['role' => 'administrator', 'number' => 1]); if (\$admins) { wp_set_current_user(\$admins[0]->ID); }
\$doc = \Elementor\Plugin::\$instance->documents->get($previewBuilder);
\$data = \$doc->get_elements_data();
\$data[0]['elements'][0]['elements'][0]['settings']['title'] = 'Duo builder heading, released through duo release.';
if (\$doc->save(['elements' => \$data]) === false) { fwrite(STDERR, 'save failed'); exit(1); }
update_field('field_duo_tagline', 'Built with care, released through duo release.', $previewLanding);
echo 'edited';
" >/dev/null || fail "$S: the preview design edit failed"
  fi
  preview_page_edit "$S" duo-walk-landing '<p>Duo walk landing page, released through duo release.</p>'
  capture_twice "$S" "${PAIR}2"
  merge_preview "$S"
  if ! dry; then
    local targetBuilder targetLanding
    targetBuilder="$(wp2 post list --post_type=page --name=duo-builder-page --field=ID | tr -d '\r' | head -1)"
    targetLanding="$(wp2 post list --post_type=page --name=duo-walk-landing --field=ID | tr -d '\r' | head -1)"
    wp2 eval "
\$admins = get_users(['role' => 'administrator', 'number' => 1]); if (\$admins) { wp_set_current_user(\$admins[0]->ID); }
\$doc = \Elementor\Plugin::\$instance->documents->get($targetBuilder);
\$data = \$doc->get_elements_data();
\$data[0]['elements'][0]['elements'][0]['settings']['title'] = 'Duo builder heading, before the release.';
\$doc->save(['elements' => \$data]);
update_field('field_duo_tagline', 'Built with care, before the release.', $targetLanding);
echo 'reverted';
" >/dev/null || fail "$S: could not put the target's design back"
  fi
  revert_target "$S" "$PREVIEW_PAGE_ID" '<p>Duo walk landing page, before the release.</p>'
  release_cycle "$S" "$MAIN_SHA"
  if ! dry; then
    curl -fs "http://127.0.0.1:${PORT2}${BUILDER_PAGE_PATH}" | grep -Fq 'released through duo release' \
      || fail "$S: the Elementor heading edit did not render on the release target"
    wp2 eval "echo get_field('field_duo_tagline', $(wp2 post list --post_type=page --name=duo-walk-landing --field=ID | tr -d '\r' | head -1));" | tr -d '\r' | grep -Fq 'released through duo release' \
      || fail "$S: the ACF tagline edit did not arrive on the release target"
    pass "$S — the Elementor and ACF edits round-tripped through the release"
  fi
  recover_cycle "$S"
  post_recovery_check "$S"
  reap_cycle "$S"
  pass "$S PASSED — a builder site adopted Duo; design edits travelled through the loop"
}

# ---------------------------------------------------------------------------
# A7 — an unmanifested published plugin (WPForms Lite) on A4's shop: kept
# unmanaged first (T6 S1's decision, on a busier site), then adopted with a
# site adapter AFTER init — the path an operator takes when the adapter comes
# later than the adoption.
# ---------------------------------------------------------------------------
situation_a7() {
  local S=A7
  run mkdir -p "$EVIDENCE/$S"
  situation_pair "$S" "$CLASSIC_THEME_SLUG@$CLASSIC_THEME_VERSION" \
    "woocommerce@$WOO_VERSION" "wordpress-seo@$YOAST_VERSION" "contact-form-7@$CF7_VERSION" "$WPFORMS_SLUG@$WPFORMS_VERSION"
  write_registry
  seed_shop duo-walk-landing
  seed_seo_and_form
  if ! dry; then
    wp1 post create --post_type="$WPFORMS_CPT" --post_status=publish --post_title='Duo adoption contact form' --post_name=duo-adoption-contact \
      --post_content='{"id":"1","settings":{"form_title":"Duo adoption contact form"},"fields":{"1":{"id":"1","type":"email","label":"Email"}}}' --porcelain >/dev/null
  fi
  doctor_and_first_look "$S" "${PAIR}1"
  if ! dry; then
    jq -e --arg id "plugin:$WPFORMS_SLUG" '[.surfaces[] | select(.id == $id)] | length == 1' "$ASSESS_JSON" >/dev/null \
      || fail "$S: the first look does not name plugin:$WPFORMS_SLUG"
    pass "$S — the unmanifested plugin is a named row beside three certified adapters"
  fi
  say "$S — duo init ${PAIR}1 refuses (active_plugin_without_adapter), then proceeds with --allow-unmanaged-plugins"
  duo_refused "$EVIDENCE/$S/init-refused.txt" active_plugin_without_adapter "$HOST_R1" init "${PAIR}1" --yes
  init_capture_baseline "$S" "${PAIR}1" --allow-unmanaged-plugins
  if ! dry; then
    walk_assert_init_line "$EVIDENCE/$S/init.txt" 'UNMANAGED PLUGIN' "$WPFORMS_BASENAME" active_plugin_without_adapter \
      || fail "$S: init did not print the UNMANAGED PLUGIN advisory"
    walk_assert_init_line "$EVIDENCE/$S/init.txt" 'UNMANAGED SCOPE' "post_type:$WPFORMS_CPT" unmanaged_scope_left_local \
      || fail "$S: init did not leave the plugin's post type local as UNMANAGED SCOPE"
    pass "$S — the plugin and its type were left local by decision, and printed as such"
  fi
  assess_both "$S" "${PAIR}1" "$HOST_R1" unmanaged
  # Now the operator authors, certifies and pins an adapter AFTER init.
  say "$S — author the WPForms adapter after adoption: coverage → draft → finish → certify --pin"
  duo_ok "$SCRATCH/$S-coverage.raw" "$HOST_R1" coverage "${PAIR}1" --format=json
  local seed="$EVIDENCE/$S/coverage.json" draft="$HOST_R1/adapters/$WPFORMS_CPT.json"
  run mkdir -p "$HOST_R1/adapters"
  if ! dry; then
    walk_agent_json "$SCRATCH/$S-coverage.raw" > "$seed"
  fi
  duo_ok "$EVIDENCE/$S/adapter-draft.txt" "$HOST_R1" adapter-draft "$HOST_R1" --name="$WPFORMS_CPT" \
    --match="^_?$WPFORMS_OPTION_PREFIX" --seed="$seed" --out="$draft"
  finish_wpforms_draft "$S" "$draft"
  duo_ok "$EVIDENCE/$S/manifest-validate.txt" "$HOST_R1" manifest-validate "$HOST_R1/adapters" --site="$HOST_R1"
  keygen_and_certify "$S" "$WPFORMS_CPT"
  # The repository is init-owned: no second init. The pin set changed, and
  # the type left local is re-decided into scope with one classify decision;
  # capture then reads both.
  say "$S — re-decide the left-local type into scope, capture, assess"
  duo_ok "$EVIDENCE/$S/scope-export.txt" "$HOST_R1" classify "${PAIR}1" --export-batch="$SCRATCH/$S-batch.json" || true
  if ! dry; then
    if [ -s "$SCRATCH/$S-batch.json" ] && jq -e --arg k "post_type:$WPFORMS_CPT" '[.decisions[] | select(.key == $k)] | length == 1' "$SCRATCH/$S-batch.json" >/dev/null 2>&1; then
      walk_batch_decide "$SCRATCH/$S-batch.json" scope "post_type:$WPFORMS_CPT" authored "$SCRATCH/$S-batch.decided.json"
      duo_ok "$EVIDENCE/$S/classify.txt" "$HOST_R1" classify "${PAIR}1" --apply-batch="$SCRATCH/$S-batch.decided.json"
    else
      # No queue item: the adapter's authored post type entered scope with the
      # pin change (init-owned repositories re-read the pin set on capture),
      # or the type still sits in policy.scope as runtime — flip that rule.
      jq 'if .policy.scope.post_type["'"$WPFORMS_CPT"'"] then del(.policy.scope.post_type["'"$WPFORMS_CPT"'"]) else . end
          | .policy.post_types = ((.policy.post_types + ["'"$WPFORMS_CPT"'"]) | unique)' "$HOST_R1/site.duo.json" > "$HOST_R1/site.duo.json.next" \
        && mv "$HOST_R1/site.duo.json.next" "$HOST_R1/site.duo.json"
      note "$S — no classify queue item; the post type was moved into policy.post_types by hand (an operator's own site.duo.json edit)"
    fi
  fi
  duo_ok "$EVIDENCE/$S/capture-adopted.txt" "$HOST_R1" capture "${PAIR}1"
  git1 add -A
  commit1 "grind_adoption $S: WPForms adapter authored, certified and pinned after adoption"
  git1 push -q origin main
  assess_both "$S" "${PAIR}1" "$HOST_R1" site-certified
  if ! dry; then
    local expect got
    expect=$'authored\tmanage\tReady\tSite-certified\tprevented\tprovider-state restorable'
    got="$(walk_assess_projection "$ASSESS_JSON" "post_type:$WPFORMS_CPT" release)"
    [ "$got" = "$expect" ] || fail "$S: post_type:$WPFORMS_CPT projected '$got' after adoption, expected '$expect'"
    jq -e --arg id "plugin:$WPFORMS_SLUG" '[.surfaces[] | select(.id == $id)] | length == 0' "$ASSESS_JSON" >/dev/null \
      || fail "$S: plugin:$WPFORMS_SLUG still reads as a plugin without an adapter after certification"
    pass "$S — the plugin adopted after init reads Site-certified beside the three shipped adapters"
  fi
  CONTRACT_JOURNEYS_JSON="$(shop_journeys)"
  CONTRACT_LIFECYCLE_REASON="the only external effect this shop's installed set has in the lifecycle window is WordPress' own activation/deactivation hooks; reviewed against woocommerce $WOO_VERSION, wordpress-seo $YOAST_VERSION, contact-form-7 $CF7_VERSION, $WPFORMS_SLUG $WPFORMS_VERSION and $CLASSIC_THEME_SLUG $CLASSIC_THEME_VERSION, none of which run mail, payment or webhook code on activation"
  contract_cycle "$S" "${PAIR}1" "$HOST_R1" "$LANDING_ID" - '.'
  rehearse_preview "$S"
  say "$S — author a new form on the preview, then capture twice"
  if ! dry; then
    wp2 post create --post_type="$WPFORMS_CPT" --post_status=publish --post_title='Duo adoption quote form' --post_name=duo-adoption-quote \
      --post_content='{"id":"3","settings":{"form_title":"Duo adoption quote form"},"fields":{"1":{"id":"1","type":"text","label":"Company"}}}' --porcelain >/dev/null
  fi
  preview_page_edit "$S" duo-walk-landing '<p>Duo walk landing page, released through duo release.</p>'
  capture_twice "$S" "${PAIR}2"
  merge_preview "$S"
  if ! dry; then
    local previewFormId
    previewFormId="$(wp2 post list --post_type="$WPFORMS_CPT" --name=duo-adoption-quote --field=ID | tr -d '\r' | head -1)"
    [ -n "$previewFormId" ] || fail "$S: the preview did not carry the authored form back"
    wp2 post delete "$previewFormId" --force
  fi
  revert_target "$S" "$PREVIEW_PAGE_ID" '<p>Duo walk landing page, before the release.</p>'
  release_cycle "$S" "$MAIN_SHA"
  if ! dry; then
    wp2 post list --post_type="$WPFORMS_CPT" --field=post_title | tr -d '\r' | grep -Fqx 'Duo adoption quote form' \
      || fail "$S: the release did not put the authored form on the target"
  fi
  recover_cycle "$S"
  post_recovery_check "$S"
  reap_cycle "$S"
  pass "$S PASSED — a plugin kept unmanaged at adoption was adopted later with an operator's own adapter"
}

# finish_wpforms_draft <label> <draft-path> — the walk's S2 manifest, from the draft.
finish_wpforms_draft() {
  local label="$1" draft="$2"
  if dry; then
    plan "jq: finish $draft into spec_version 2 / name $WPFORMS_CPT / plugin $WPFORMS_BASENAME / version_range [$WPFORMS_VERSION, 2.1.0) + deletions"
    return 0
  fi
  jq -n --arg name "$WPFORMS_CPT" --arg plugin "$WPFORMS_BASENAME" --arg min "$WPFORMS_VERSION" --arg cpt "$WPFORMS_CPT" \
        --arg prefix "^$WPFORMS_OPTION_PREFIX" '
    { name: $name,
      notes: {"authored (T7 A7)": "Each form is one wpforms post whose post_content is the form definition as JSON (body verbatim); wpforms_settings is the operator-edited settings blob; every other option under the namespace is bookkeeping the plugin owns (runtime); the custom tables are declared runtime; post:wpforms is deletable with the required post cascade set and no guards."},
      option_autoload: "preserve",
      option_namespaces: [{match: $prefix}],
      option_patterns: [{match: $prefix, class: "runtime"}],
      options: {("\($name)_settings"): {class: "authored"}},
      plugin: $plugin,
      post_types: {($cpt): {class: "authored", body: "verbatim"}},
      deletions: {("post:\($cpt)"): {cascades: ["postmeta", "post_revisions", "term_relationships"], guards: []}},
      spec_version: 2,
      tables: { ("\($name)_analytics_forms"): {class: "runtime"}, ("\($name)_analytics_snapshots"): {class: "runtime"},
                ("\($name)_logs"): {class: "runtime"}, ("\($name)_payment_meta"): {class: "runtime"},
                ("\($name)_payments"): {class: "runtime"}, ("\($name)_tasks_meta"): {class: "runtime"} },
      version_range: {min: $min, max: "2.1.0"} }' > "$draft.finished" \
    || fail "$label: could not finish the draft into a manifest"
  mv "$draft.finished" "$draft"
}
# ---------------------------------------------------------------------------
# A8 — an in-house plugin (acme-catalog) with a bundled adapter: promoted,
# certified (T6 S3), then the plugin AND its adapter change in one code
# release — a new option in 1.1.0, declared in both copies of the adapter,
# re-certified, released with the state that names it.
# ---------------------------------------------------------------------------
situation_a8() {
  local S=A8
  run mkdir -p "$EVIDENCE/$S"
  situation_pair "$S" "$CLASSIC_THEME_SLUG@$CLASSIC_THEME_VERSION" "woocommerce@$WOO_VERSION"
  write_registry
  install_acme 1 author
  install_acme 2 target
  seed_shop duo-walk-landing
  if ! dry; then
    wp1 post create --post_type="$ACME_CPT" --post_status=publish --post_title='Duo walk item' --post_name=duo-walk-item \
      --post_content='<p>The first catalog item.</p>' --porcelain >/dev/null
  fi
  seed_repository "$S"
  say "$S — promote the bundled adapter to adapters/$ACME_SLUG.json and certify it"
  run mkdir -p "$HOST_R1/adapters"
  run cp "$SANDBOX/fixtures/$ACME_SLUG/duo-adapter.json" "$HOST_R1/adapters/$ACME_SLUG.json"
  duo_ok "$EVIDENCE/$S/manifest-validate.txt" "$HOST_R1" manifest-validate "$HOST_R1/adapters" --site="$HOST_R1"
  keygen_and_certify "$S" "$ACME_SLUG"
  init_capture_baseline "$S" "${PAIR}1"
  assess_both "$S" "${PAIR}1" "$HOST_R1" adopted
  if ! dry; then
    [ "$(walk_assess_projection "$ASSESS_JSON" "post_type:$ACME_CPT" release | cut -f3-4)" = $'Ready\tSite-certified' ] \
      || fail "$S: post_type:$ACME_CPT is not Ready / Site-certified after adoption"
  fi
  local extraJourney surfaceFilter
  extraJourney="$(jq -nc --arg id "$ACME_CPT" '{id: "acme-index", url: "/?post_type=\($id)", expect_status: 200,
     expect_contains: "Duo walk item", affected_surfaces: ["post_type:\($id)"]}')"
  surfaceFilter='.contract.declarations.surfaces = [ .contract.declarations.surfaces[] | if .id == "table:acme_catalog_index" then . + {state_class: "runtime", handling: "preserve local", decided_by: "operator", decided_at: "2026-08-18T09:00:00Z"} | del(.next_action) else . end ]'
  CONTRACT_JOURNEYS_JSON="$(shop_journeys)"
  CONTRACT_LIFECYCLE_REASON="the only external effect this shop's installed set has in the lifecycle window is WordPress' own activation/deactivation hooks; reviewed against woocommerce $WOO_VERSION, $ACME_SLUG 1.x and $CLASSIC_THEME_SLUG $CLASSIC_THEME_VERSION, none of which run mail, payment or webhook code on activation"
  contract_cycle "$S" "${PAIR}1" "$HOST_R1" "$LANDING_ID" "$extraJourney" "$surfaceFilter"

  # The code release: 1.1.0 adds an authored option; the bundled adapter and
  # the promoted site adapter both declare it; the site adapter is
  # re-certified (its digest moved). All of it in the repository's code half
  # and adapters/, committed to main; the release deploys the code, activates
  # it in a fresh process, and applies the state that names the new option.
  say "$S — acme-catalog 1.1.0: a new authored option, declared in both adapter copies, re-certified"
  local codePlugin="$HOST_R1/code/wp-content/plugins/$ACME_SLUG"
  if dry; then
    plan "sed 1.0.0 -> 1.1.0 in $codePlugin/$ACME_SLUG.php; add acme_catalog_banner; jq options.acme_catalog_banner authored into $codePlugin/duo-adapter.json and adapters/$ACME_SLUG.json"
  else
    [ -f "$codePlugin/$ACME_SLUG.php" ] || fail "$S: the code half carries no $codePlugin/$ACME_SLUG.php to change"
    sed -i.bak -e 's/Version: 1\.0\.0/Version: 1.1.0/' -e "s/define('ACME_CATALOG_VERSION', '1.0.0');/define('ACME_CATALOG_VERSION', '1.1.0');/" "$codePlugin/$ACME_SLUG.php"
    rm -f "$codePlugin/$ACME_SLUG.php.bak"
    grep -q "1.1.0" "$codePlugin/$ACME_SLUG.php" || fail "$S: the version bump did not land in the code half"
    for f in "$codePlugin/duo-adapter.json" "$HOST_R1/adapters/$ACME_SLUG.json"; do
      jq '.options.acme_catalog_banner = {class: "authored"}
          | .notes = (.notes + {"1.1.0 (T7 A8)": "acme_catalog_banner is the operator-edited banner text 1.1.0 introduces; authored, no ref."})' "$f" > "$f.next" \
        && mv "$f.next" "$f"
    done
    # The live author side runs the same 1.1.0 (the operator updated it there
    # first, as they would), and sets the new option.
    sh_side 1 "sed -i -e 's/Version: 1\\.0\\.0/Version: 1.1.0/' -e \"s/define('ACME_CATALOG_VERSION', '1.0.0');/define('ACME_CATALOG_VERSION', '1.1.0');/\" /var/www/html/wp-content/plugins/$ACME_SLUG/$ACME_SLUG.php \
      && cp /siterepo/adapters/$ACME_SLUG.json /var/www/html/wp-content/plugins/$ACME_SLUG/duo-adapter.json"
    wp1 option update acme_catalog_banner 'Now with banners (1.1.0)' >/dev/null
  fi
  duo_ok "$EVIDENCE/$S/manifest-validate-1.1.txt" "$HOST_R1" manifest-validate "$HOST_R1/adapters" --site="$HOST_R1"
  duo_ok "$EVIDENCE/$S/certify-1.1.txt" "$HOST_R1" adapter certify "$HOST_R1" --name="$ACME_SLUG" \
    --secret-key-file="$KEYDIR/$ACME_SLUG.key" --key-id="$WALK_KEY_ID" --reason="round-3 T7 A8: 1.1.0 adds acme_catalog_banner" --pin
  duo_ok "$EVIDENCE/$S/capture-1.1.txt" "$HOST_R1" capture "${PAIR}1"
  git1 add -A
  commit1 "grind_adoption $S: acme-catalog 1.1.0 — code, both adapter copies, re-certified, and the state that names the new option"
  git1 push -q origin main
  MAIN_SHA="$(git -C "$HOST_R1" rev-parse HEAD 2>/dev/null || printf '%s' "$MAIN_SHA")"
  rehearse_preview "$S"
  if ! dry; then
    wp2 plugin get "$ACME_SLUG" --field=version | tr -d '\r' | grep -qx '1.1.0' \
      || fail "$S: the rehearsal preview does not run acme-catalog 1.1.0"
    wp2 option get acme_catalog_banner | tr -d '\r' | grep -Fq 'Now with banners' \
      || fail "$S: the rehearsal preview does not carry the new option"
    pass "$S — the preview runs 1.1.0 with the new option, from the same revision"
  fi
  preview_page_edit "$S" duo-walk-landing '<p>Duo walk landing page, released through duo release.</p>'
  capture_twice "$S" "${PAIR}2"
  merge_preview "$S"
  revert_target "$S" "$PREVIEW_PAGE_ID" '<p>Duo walk landing page, before the release.</p>'
  # The target still runs 1.0.0 with no banner option: put it back to that
  # state so the release has the code AND the option to deliver.
  if ! dry; then
    sh_side 2 "sed -i -e 's/Version: 1\\.1\\.0/Version: 1.0.0/' -e \"s/define('ACME_CATALOG_VERSION', '1.1.0');/define('ACME_CATALOG_VERSION', '1.0.0');/\" /var/www/html/wp-content/plugins/$ACME_SLUG/$ACME_SLUG.php" || true
    wp2 option delete acme_catalog_banner >/dev/null 2>&1 || true
  fi
  release_cycle "$S" "$MAIN_SHA"
  if ! dry; then
    wp2 plugin get "$ACME_SLUG" --field=version | tr -d '\r' | grep -qx '1.1.0' \
      || fail "$S: the release did not deploy acme-catalog 1.1.0 to the target"
    wp2 option get acme_catalog_banner | tr -d '\r' | grep -Fq 'Now with banners' \
      || fail "$S: the release did not apply the new authored option"
    pass "$S — one release carried the plugin's new code and the state its new adapter declares"
  fi
  recover_cycle "$S"
  post_recovery_check "$S"
  reap_cycle "$S"
  pass "$S PASSED — a custom plugin and its adapter evolved together through the loop"
}

# ---------------------------------------------------------------------------
# A9 — version edges: WooCommerce below the adapter's range at adoption,
# upgraded mid-way; Yoast old → new. The words an operator meets are the
# honest ones (a typed refusal or a requalification word), never a silent
# pass, and the loop completes once the versions are inside the windows.
# ---------------------------------------------------------------------------
situation_a9() {
  local S=A9
  run mkdir -p "$EVIDENCE/$S"
  situation_pair "$S" "$CLASSIC_THEME_SLUG@$CLASSIC_THEME_VERSION" \
    "woocommerce@$WOO_OLD_VERSION" "wordpress-seo@$YOAST_OLD_VERSION"
  write_registry
  seed_shop duo-walk-landing
  say "$S — duo doctor, then duo assess on a shop whose plugins are OUTSIDE the adapters' version windows"
  duo_ok "$EVIDENCE/$S/doctor.txt" "$HOST_R1" doctor "${PAIR}1"
  seed_repository "$S"
  local firstLook="$SCRATCH/$S-assess-first-look.raw"
  if dry; then
    plan "(cd $HOST_R1 && duo assess ${PAIR}1 --format=json)  # either a typed refusal naming the version window, or a report whose product/yoast rows read Requalification required / Unsupported"
  else
    if ( cd "$HOST_R1" && php "$DUO" "--envs-file=$ENVS_FILE" assess "${PAIR}1" --format=json ) > "$firstLook" 2>&1; then
      walk_json_tail "$firstLook" > "$EVIDENCE/$S/assess-first-look.json"
      local wooRow
      wooRow="$(walk_assess_projection "$EVIDENCE/$S/assess-first-look.json" post_type:product release | cut -f3)"
      note "$S — post_type:product readiness with woocommerce $WOO_OLD_VERSION: $wooRow"
      [ "$wooRow" != Ready ] \
        || fail "$S: WooCommerce $WOO_OLD_VERSION is below manifests/woocommerce.json's window and post_type:product still reads Ready"
      pass "$S — the out-of-window plugin's surfaces do not read Ready ($wooRow)"
    else
      local code
      code="$(walk_refusal_code "$firstLook" 2>/dev/null || true)"
      grep -Eq "version|range|window" "$firstLook" \
        || fail "$S: assess refused with [${code:-no code}] and did not name the version window; see $firstLook"
      pass "$S — assess refused by name on the version window [${code:-untyped}]"
    fi
  fi
  say "$S — duo init ${PAIR}1 --yes with the plugins outside their windows"
  local initOut="$SCRATCH/$S-init-old.raw"
  if dry; then
    plan "(cd $HOST_R1 && duo init ${PAIR}1 --yes)   # expected: a typed refusal naming the version window"
  else
    if ( cd "$HOST_R1" && php "$DUO" "--envs-file=$ENVS_FILE" init "${PAIR}1" --yes ) > "$initOut" 2>&1; then
      fail "$S: init proceeded with woocommerce $WOO_OLD_VERSION and wordpress-seo $YOAST_OLD_VERSION outside their adapters' windows; see $initOut"
    fi
    grep -Eiq "version|range|window" "$initOut" \
      || fail "$S: init refused without naming the version window; see $initOut"
    cp "$initOut" "$EVIDENCE/$S/init-out-of-window.txt"
    pass "$S — init refused by name: the installed versions are outside the certified windows"
  fi
  say "$S — upgrade both plugins into their windows on both sides, then adopt"
  local artifact side
  for side in 1 2; do
    if ! dry; then
      artifact="$(fetch_artifact woocommerce "$WOO_VERSION" "cli$side" plugin)" || fail "no woocommerce $WOO_VERSION artifact"
      wp_side "$side" plugin install "$artifact" --force
      artifact="$(fetch_artifact wordpress-seo "$YOAST_VERSION" "cli$side" plugin)" || fail "no wordpress-seo $YOAST_VERSION artifact"
      wp_side "$side" plugin install "$artifact" --force
    else
      plan "wp$side plugin install woocommerce@$WOO_VERSION wordpress-seo@$YOAST_VERSION --force"
    fi
  done
  if ! dry; then
    wp1 plugin get woocommerce --field=version | tr -d '\r' | grep -qx "$WOO_VERSION" || fail "$S: woocommerce did not upgrade on ${PAIR}1"
    wp1 plugin get wordpress-seo --field=version | tr -d '\r' | grep -qx "$YOAST_VERSION" || fail "$S: wordpress-seo did not upgrade on ${PAIR}1"
    # WooCommerce runs its own updater on the next request; wake it.
    curl -fs "http://127.0.0.1:${PORT1}/" >/dev/null || true
    wp1 wc update >/dev/null 2>&1 || true
  fi
  init_capture_baseline "$S" "${PAIR}1"
  assess_both "$S" "${PAIR}1" "$HOST_R1" adopted
  if ! dry; then
    [ "$(walk_assess_projection "$ASSESS_JSON" post_type:product release | cut -f3-4)" = $'Ready\tPlatform-certified' ] \
      || fail "$S: after the upgrade post_type:product is not Ready / Platform-certified"
    pass "$S — inside the windows, the same shop reads Ready / Platform-certified"
  fi
  CONTRACT_JOURNEYS_JSON="$(shop_journeys)"
  CONTRACT_LIFECYCLE_REASON="the only external effect this shop's installed set has in the lifecycle window is WordPress' own activation/deactivation hooks; reviewed against woocommerce $WOO_VERSION, wordpress-seo $YOAST_VERSION and $CLASSIC_THEME_SLUG $CLASSIC_THEME_VERSION, none of which run mail, payment or webhook code on activation"
  loop_to_recovery "$S"
  pass "$S PASSED — version edges were refused by name, and the loop completed once the versions were inside their windows"
}

# ---------------------------------------------------------------------------
# A10 — edge cases on one site, each a documented stop or a documented pass:
# an already-adopted repository, a hard-matched secret in an option, a plugin
# deactivated after adoption, a theme switch after adoption, and an override
# installed then removed. (Multisite and adopt-over-SSH-less are pair-model
# refusals exercised by their own suites; not repeated here.)
# ---------------------------------------------------------------------------
situation_a10() {
  local S=A10
  run mkdir -p "$EVIDENCE/$S"
  situation_pair "$S" "$CLASSIC_THEME_SLUG@$CLASSIC_THEME_VERSION" "woocommerce@$WOO_VERSION" "contact-form-7@$CF7_VERSION"
  write_registry
  seed_shop duo-walk-landing
  if ! dry; then
    # Both themes installed so the switch later is a real one.
    local artifact
    artifact="$(fetch_artifact "$BLOCK_THEME_SLUG" "$BLOCK_THEME_VERSION" cli1 theme)" || fail "no $BLOCK_THEME_SLUG artifact"
    wp1 theme install "$artifact" --force
    artifact="$(fetch_artifact "$BLOCK_THEME_SLUG" "$BLOCK_THEME_VERSION" cli2 theme)" || fail "no $BLOCK_THEME_SLUG artifact"
    wp2 theme install "$artifact" --force
    # A secret-shaped option value: what init and capture do with it must be
    # a stated redaction, never a copy.
    wp1 option update duo_walk_api_key 'sk_live_4eC39HqLyjWDarjtT1zdp7dc' >/dev/null
  fi
  doctor_and_first_look "$S" "${PAIR}1"
  init_capture_baseline "$S" "${PAIR}1"
  if ! dry; then
    grep -Eq "redacted risk surfaces: [1-9][0-9]* secret-shaped option value" "$EVIDENCE/$S/init.txt" \
      || fail "$S: init did not count the secret-shaped option among its redacted risk surfaces"
    ! grep -rq 'sk_live_4eC39HqLyjWDarjtT1zdp7dc' "$HOST_R1/state" "$HOST_R1/site.duo.json" 2>/dev/null \
      || fail "$S: the secret-shaped value reached the repository"
    pass "$S — the secret-shaped option was counted and never copied"
  fi
  # 1. Adopting again over an init-owned repository is a typed stop.
  say "$S — duo init again over the init-owned repository"
  duo_refused "$EVIDENCE/$S/init-again.txt" existing_configuration "$HOST_R1" init "${PAIR}1" --yes
  pass "$S — a second init refuses existing_configuration"
  # 2. A plugin deactivated after adoption: the next capture and assess say so.
  say "$S — deactivate contact-form-7 after adoption, capture, assess"
  if ! dry; then wp1 plugin deactivate contact-form-7 >/dev/null; fi
  local capOut="$SCRATCH/$S-capture-deactivated.raw"
  if dry; then
    plan "(cd $HOST_R1 && duo capture ${PAIR}1)   # either proceeds with the plugin's surfaces reported inactive, or refuses by name"
  else
    if ( cd "$HOST_R1" && php "$DUO" "--envs-file=$ENVS_FILE" capture "${PAIR}1" ) > "$capOut" 2>&1; then
      note "$S — capture proceeded with contact-form-7 deactivated"
    else
      local code
      code="$(walk_refusal_code "$capOut" 2>/dev/null || true)"
      note "$S — capture refused [${code:-untyped}] with contact-form-7 deactivated (see $capOut)"
      [ -n "$code" ] || fail "$S: capture refused without a typed reason code after a plugin deactivation; see $capOut"
    fi
    cp "$capOut" "$EVIDENCE/$S/capture-deactivated.txt"
    wp1 plugin activate contact-form-7 >/dev/null
  fi
  assess_both "$S" "${PAIR}1" "$HOST_R1" reactivated
  # 3. A theme switch after adoption.
  say "$S — switch the theme after adoption ($CLASSIC_THEME_SLUG → $BLOCK_THEME_SLUG), capture, assess"
  if ! dry; then wp1 theme activate "$BLOCK_THEME_SLUG" >/dev/null; fi
  duo_ok "$EVIDENCE/$S/capture-theme-switch.txt" "$HOST_R1" capture "${PAIR}1"
  assess_both "$S" "${PAIR}1" "$HOST_R1" theme-switched
  if ! dry; then
    grep -Fq "$BLOCK_THEME_SLUG" "$EVIDENCE/$S/assess-theme-switched.txt" \
      || note "$S: the assessment does not name the active theme in its human view"
    wp1 theme activate "$CLASSIC_THEME_SLUG" >/dev/null
    duo_ok "$EVIDENCE/$S/capture-theme-back.txt" "$HOST_R1" capture "${PAIR}1"
  fi
  # 4. An override installed, then removed: shadowed_by_site, then back to
  # the shipped adapter, with the pin set restored — nothing lingering.
  say "$S — install a site override of woocommerce, then remove it"
  local override="$HOST_R1/adapters/woocommerce.json"
  run mkdir -p "$HOST_R1/adapters"
  if ! dry; then
    jq '.options.woocommerce_demo_store_notice = {class: "authored", autoload: "preserve"}' "$REPO_ROOT/manifests/woocommerce.json" > "$override"
  fi
  duo_ok "$EVIDENCE/$S/adapter-pin.txt" "$HOST_R1" adapter pin "$HOST_R1" --name=woocommerce --source=site
  adapter_catalog "$S" override
  if ! dry; then
    walk_assert_shadowed_by_site "$CATALOG_JSON" woocommerce || fail "$S: the override is not reported as shadowing"
    rm -f "$override"
    jq '.manifests = [.manifests[] | if (type == "object" and .name == "woocommerce" and .source == "site") then "woocommerce" else . end]' \
      "$HOST_R1/site.duo.json" > "$HOST_R1/site.duo.json.next" && mv "$HOST_R1/site.duo.json.next" "$HOST_R1/site.duo.json"
  fi
  duo_ok "$EVIDENCE/$S/manifest-pin-back.txt" "$HOST_R1" adapter pin "$HOST_R1" --name=woocommerce
  adapter_catalog "$S" restored
  if ! dry; then
    [ "$(walk_catalog_row "$CATALOG_JSON" woocommerce | cut -f1)" = shipped ] \
      || fail "$S: after removing the override, woocommerce does not answer from the shipped library"
    jq -e '(.not_installed // []) | map(select(.reason_code == "shadowed_by_site")) | length == 0' "$CATALOG_JSON" >/dev/null \
      || fail "$S: a shadowed_by_site row lingers after the override was removed"
    pass "$S — the override came and went; the shipped adapter answers again and nothing lingers"
  fi
  duo_ok "$EVIDENCE/$S/capture-final.txt" "$HOST_R1" capture "${PAIR}1"
  git1 add -A
  commit1 "grind_adoption $S: edge cases walked; repository back on the shipped adapter set"
  git1 push -q origin main
  CONTRACT_JOURNEYS_JSON="$(shop_journeys)"
  CONTRACT_LIFECYCLE_REASON="the only external effect this shop's installed set has in the lifecycle window is WordPress' own activation/deactivation hooks; reviewed against woocommerce $WOO_VERSION, contact-form-7 $CF7_VERSION and $CLASSIC_THEME_SLUG $CLASSIC_THEME_VERSION, none of which run mail, payment or webhook code on activation"
  loop_to_recovery "$S"
  pass "$S PASSED — every edge case stopped or proceeded by its documented rule, and the loop still completed"
}

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
