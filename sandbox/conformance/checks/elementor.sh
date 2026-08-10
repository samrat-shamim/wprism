#!/usr/bin/env bash
# Elementor render-level acceptance (docs/frontier/elementor.md's core
# finding, mirroring checks/fse.sh's methodology): byte-identical canonical
# state is NOT sufficient proof for _elementor_data's ids/URLs — the report's
# own confirmed corruption round-tripped byte-identical to itself while
# rendering the SOURCE environment's host on the TARGET. This check curls
# conf2's seeded elementor page after apply and greps the rendered HTML
# (and Elementor's regenerated per-page CSS, which is where a section
# background-image URL actually lives — never in an HTML attribute) for
# conf2's own host, never conf1's.
#
# Buffer the body into a variable before grepping it: under `pipefail`,
# `curl | grep -q` dies with curl's EPIPE (rc 23) whenever grep matches
# before curl finishes writing — a load-dependent false failure (the match
# SUCCEEDED). See spike_a_round_trip.sh's identical comment (also
# checks/fse.sh's).
#
# Invoked by conformance/run.sh after a clean apply, from the sandbox/
# directory; conf1/conf2's ports are set by run.sh via CONF1_PORT/CONF2_PORT
# (defaults below match the legacy docker-compose.yml conf1/conf2 ports for
# any standalone invocation).
set -euo pipefail
CONF1_PORT="${CONF1_PORT:-8806}"
CONF2_PORT="${CONF2_PORT:-8807}"

FRONT=$(curl -fs "http://localhost:${CONF2_PORT}/duo-conformance-elementor-page/") \
  || fail "conf2 elementor page did not return 200"

# The sharpest possible test of the report's exact corruption: conf1's own
# origin must not leak into conf2's rendered output anywhere.
if grep -q "localhost:${CONF1_PORT}" <<<"$FRONT"; then
    fail "conf2's rendered elementor page links back to conf1 (localhost:${CONF1_PORT}) — _elementor_data ids/urls not rebound"
fi

# widget "image" control (json_refs $..image.id + the auto-tokenized url sibling)
grep -q 'src="http://localhost:'"$CONF2_PORT"'/wp-content/uploads/[0-9]\{4\}/[0-9]\{2\}/duo-conf-elementor-hero[^"]*"' <<<"$FRONT" \
  || fail "image widget did not render from conf2's own uploads (json_refs \$..image.id)"

# gallery widget "wp_gallery" control (json_refs $..wp_gallery.id, an ARRAY — array-transparency)
grep -q 'src="http://localhost:'"$CONF2_PORT"'/wp-content/uploads/[0-9]\{4\}/[0-9]\{2\}/duo-conf-elementor-gallery-a[^"]*"' <<<"$FRONT" \
  || fail "gallery image A did not render from conf2's own uploads (json_refs \$..wp_gallery.id)"
grep -q 'src="http://localhost:'"$CONF2_PORT"'/wp-content/uploads/[0-9]\{4\}/[0-9]\{2\}/duo-conf-elementor-gallery-b[^"]*"' <<<"$FRONT" \
  || fail "gallery image B did not render from conf2's own uploads (json_refs \$..wp_gallery.id)"

# button widget's plain-URL internal link (the report's original verified
# shape: NO id at all — only the generic string-leaf tokenize pass rebinds this)
grep -q 'href="http://localhost:'"$CONF2_PORT"'/duo-elementor-target/"' <<<"$FRONT" \
  || fail "button widget's internal link did not resolve to conf2's own target page permalink"

# section background-image (json_refs $..background_image.id): lives in
# Elementor's regenerated per-page CSS, never in an HTML attribute — the
# elementor-css provider capability (manifests/elementor.json's
# regenerate_css action, which runs the plugin's own "elementor flush-css
# --regenerate") must have run during apply's rebuild pass for this file to
# exist at all.
# The page also loads the KIT's own global CSS (a second "elementor-post-
# <id>-css" link, e.g. "post-1.css" for the adopted default kit) — grepping
# the FIRST css link found is wrong (caught empirically: it grabbed the
# kit's CSS, which never has this section's background-image rule, on a
# run where the kit's env-adopted post id sorted before the page's own).
# Ask conf2 directly which local id the seeded page itself resolved to.
# DUO-3393: `wp post list` returns EMPTY at exit 0 on no match, so the old
# `|| fail` here never fired — the failure surfaced two lines down as the
# confusing `... post id ()` (empty interpolation). require_fixture_ids (the
# DUO-3381 helper) names the no-match at the read site as a fixture failure.
PAGE_ID=$(wp_conf2 post list --post_type=page --name=duo-conformance-elementor-page --field=ID)
require_fixture_ids PAGE_ID
POST_CSS_URL="http://localhost:${CONF2_PORT}/wp-content/uploads/elementor/css/post-${PAGE_ID}.css"
grep -q "elementor-post-${PAGE_ID}-css" <<<"$FRONT" \
  || fail "conf2's rendered page has no elementor CSS link for its own post id ($PAGE_ID)"
CSS=$(curl -fs "$POST_CSS_URL") || fail "could not fetch conf2's regenerated elementor CSS ($POST_CSS_URL)"
if grep -q "localhost:${CONF1_PORT}" <<<"$CSS"; then
    fail "conf2's regenerated elementor CSS still references conf1 (localhost:${CONF1_PORT}) — the elementor-css provider did not pick up the rebound background_image"
fi
grep -q 'background-image:url("http://localhost:'"$CONF2_PORT"'/wp-content/uploads/[0-9]\{4\}/[0-9]\{2\}/duo-conf-elementor-bg[^"]*")' <<<"$CSS" \
  || fail "section background-image did not regenerate pointing at conf2's own uploads (json_refs \$..background_image.id + the elementor-css provider capability)"

pass "conf2 renders its own image/gallery/background-image (json_refs, incl. the array case) and internal link (plain-URL tokenize) — none point at conf1"
