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
# directory; conf1/conf2's ports are set by run.sh via CONF1_PORT/CONF2_PORT
# (defaults below match the legacy docker-compose.yml conf1/conf2 ports for
# any standalone invocation).
set -euo pipefail
CONF1_PORT="${CONF1_PORT:-8806}"
CONF2_PORT="${CONF2_PORT:-8807}"

# DUO-3238: retry the whole front-page render as one unit. Two documented
# load-flake instances against exactly this assertion set motivated
# _retry_helper.sh (docs/grind/r3b-events-memberships.md; DUO-3228 task 0's
# About-permalink failure) — this is its first wiring. front_page_checks()
# bundles every assertion below against the SAME curled body (a stale or
# incomplete render is a property of the page as a whole, not any single
# assertion — see _retry_helper.sh's header) and records the specific
# failing message in FSE_FRONT_FAIL instead of calling fail() itself, so a
# genuine, persistent failure still reports the exact same message it
# always has — the helper changes WHEN this check gives up, not what it
# checks or how specifically it reports.
source "$(dirname "${BASH_SOURCE[0]}")/_retry_helper.sh"

FSE_FRONT_FAIL="conf2 front page did not return 200"
front_page_checks() { # front_page_checks <body> — sets FSE_FRONT_FAIL on any mismatch
  local body="$1"

  # The sharpest possible test of the report's exact corruption: conf1's own
  # origin must not leak into conf2's rendered output anywhere.
  if grep -q "localhost:${CONF1_PORT}" <<<"$body"; then
    FSE_FRONT_FAIL="conf2's rendered front page links back to conf1 (localhost:${CONF1_PORT}) — navigation-link id/url not rebound"
    return 1
  fi
  if ! grep -q 'href="http://localhost:'"$CONF2_PORT"'/duo-fse-about/"' <<<"$body"; then
    FSE_FRONT_FAIL="post-type navigation-link (kind_from -> post ref) did not resolve to conf2's own About permalink"
    return 1
  fi
  if ! grep -q 'href="http://localhost:'"$CONF2_PORT"'/category/conformance-fse-news/"' <<<"$body"; then
    FSE_FRONT_FAIL="taxonomy navigation-link (kind_from -> term ref) did not resolve to conf2's own category archive"
    return 1
  fi
  if ! grep -q 'href="https://duo-conformance-external.example.test/features"' <<<"$body"; then
    FSE_FRONT_FAIL="custom navigation-link's genuinely external URL was altered (should pass through unchanged)"
    return 1
  fi
  if ! grep -q 'src="http://localhost:'"$CONF2_PORT"'/wp-content/uploads/[0-9]\{4\}/[0-9]\{2\}/conf-fse-cta\.png"' <<<"$body"; then
    FSE_FRONT_FAIL="reusable block's image did not load from conf2's own uploads"
    return 1
  fi
  if ! grep -q 'href="http://localhost:'"$CONF2_PORT"'/duo-fse-contact/"' <<<"$body"; then
    FSE_FRONT_FAIL="customized footer template-part's Contact link did not resolve to conf2's own permalink"
    return 1
  fi
  return 0
}

retry_render_check "http://localhost:${CONF2_PORT}/" front_page_checks \
  || fail "$FSE_FRONT_FAIL"

pass "conf2 renders its own nav (post/term/custom links), reusable-block image, and footer link — none point at conf1"

# --- active-theme-mismatch guard (docs/frontier/fse.md's other open gap,
# task #32) --------------------------------------------------------------
# Runs strictly AFTER the green render check above, and restores conf2's
# theme before this script returns — leaves conf2 exactly as run.sh's own
# install_env() (setup=block-theme) put it, for anything that follows.
# No site-repo state changes here at all: the "home" wp_template and
# "footer" wp_template_part are still tagged wp_theme=twentytwentyfive
# from the seed; only conf2's OWN active theme option moves.
say "guard: switch conf2 to a different bundled theme, plan must name both themes"
wp_conf2 theme activate twentytwentyfour >/dev/null || fail "could not activate twentytwentyfour on conf2"

MISMATCH_PLAN=$(wp_conf2 duo plan --repo=/siterepo --format=json | tail -1)
MISMATCH_WARNINGS=$(echo "$MISMATCH_PLAN" | jq -r '.warnings[]?')
echo "$MISMATCH_WARNINGS" | grep -q 'active-theme mismatch' \
  || fail "plan did not warn about the active-theme mismatch after switching conf2 to twentytwentyfour"
echo "$MISMATCH_WARNINGS" | grep -q "active theme is 'twentytwentyfour'" \
  || fail "mismatch warning did not name conf2's own active theme (twentytwentyfour)"
echo "$MISMATCH_WARNINGS" | grep -q "tagged for theme 'twentytwentyfive'" \
  || fail "mismatch warning did not name the captured theme (twentytwentyfive)"
echo "$MISMATCH_WARNINGS" | grep -q 'posts/wp_template/.*home\.md' \
  || fail "mismatch warning did not name the affected home wp_template"
echo "$MISMATCH_WARNINGS" | grep -q 'posts/wp_template_part/.*footer\.md' \
  || fail "mismatch warning did not name the affected footer wp_template_part"
pass "plan loudly warns: conf2 active theme 'twentytwentyfour' vs. captured 'twentytwentyfive', naming both templates"

say "guard: restore conf2's active theme, plan must be warning-free again"
wp_conf2 theme activate twentytwentyfive >/dev/null || fail "could not restore twentytwentyfive on conf2"
CLEAN_PLAN=$(wp_conf2 duo plan --repo=/siterepo --format=json | tail -1)
CLEAN_WARNING_COUNT=$(echo "$CLEAN_PLAN" | jq '.warnings | length')
[ "$CLEAN_WARNING_COUNT" = "0" ] \
  || fail "plan still warned after restoring the matching theme: $(echo "$CLEAN_PLAN" | jq -c '.warnings')"
pass "plan is warning-free again once conf2's active theme matches the captured wp_theme term"
