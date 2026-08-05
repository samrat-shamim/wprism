#!/usr/bin/env bash
# FSE render-level acceptance (docs/frontier/fse.md's core methodological
# finding): byte-identical canonical state is NOT sufficient proof for
# ref-shaped block attributes the tokenizer doesn't know to look for — a
# broken fixture (nav links pointing back at the source env) captures and
# re-captures identically to itself, because both sides encode the same
# wrong bytes. This check curls conf2's OWN front page after apply and
# greps the rendered HTML, not the canonical state, for conf2's own host.
#
# Buffer the body into a variable before grepping it: under `pipefail`,
# `curl | grep -q` dies with curl's EPIPE (rc 23) whenever grep matches
# before curl finishes writing — a load-dependent false failure (the match
# SUCCEEDED). See spike_a_round_trip.sh's identical comment.
#
# Invoked by conformance/run.sh after a clean apply, from the sandbox/
# directory; conf2 is always :8807 regardless of manifest.
set -euo pipefail

FRONT=$(curl -fs http://localhost:8807/) || fail "conf2 front page did not return 200"

# The sharpest possible test of the report's exact corruption: conf1's own
# origin must not leak into conf2's rendered output anywhere.
if grep -q 'localhost:8806' <<<"$FRONT"; then
    fail "conf2's rendered front page links back to conf1 (localhost:8806) — navigation-link id/url not rebound"
fi

grep -q 'href="http://localhost:8807/duo-fse-about/"' <<<"$FRONT" \
  || fail "post-type navigation-link (kind_from -> post ref) did not resolve to conf2's own About permalink"
grep -q 'href="http://localhost:8807/category/conformance-fse-news/"' <<<"$FRONT" \
  || fail "taxonomy navigation-link (kind_from -> term ref) did not resolve to conf2's own category archive"
grep -q 'href="https://duo-conformance-external.example.test/features"' <<<"$FRONT" \
  || fail "custom navigation-link's genuinely external URL was altered (should pass through unchanged)"
grep -q 'src="http://localhost:8807/wp-content/uploads/[0-9]\{4\}/[0-9]\{2\}/conf-fse-cta\.png"' <<<"$FRONT" \
  || fail "reusable block's image did not load from conf2's own uploads"
grep -q 'href="http://localhost:8807/duo-fse-contact/"' <<<"$FRONT" \
  || fail "customized footer template-part's Contact link did not resolve to conf2's own permalink"

pass "conf2 renders its own nav (post/term/custom links), reusable-block image, and footer link — none point at conf1"
