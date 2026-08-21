#!/usr/bin/env bash
# Polylang render/API-level acceptance (the Polylang frontier exploration's
# proposed acceptance criteria (b)/(c)/(d), mirroring checks/fse.sh's and
# checks/elementor.sh's methodology): byte-identical canonical state between
# conf1 and conf2 is necessary but not sufficient — that exploration's own
# confirmed corruption round-tripped byte-identical to itself while Polylang's own
# lookup functions returned nothing/wrong values on the target. This check
# calls Polylang's OWN documented public API on conf2 (not Duo's state
# tree) and inspects the raw DB bytes for type fidelity, using conf2's own
# LOCAL ids throughout — seeds/polylang.sh's anti-coincidence fillers make
# conf1's and conf2's ids for the same uuid genuinely different, so these
# assertions cannot pass by lucky coincidence the way the original fx1/fx2
# exploration's did.
#
# Invoked by conformance/run.sh after a clean apply, from the sandbox/
# directory; conf1/conf2's ports are set by run.sh via CONF1_PORT/CONF2_PORT
# (defaults below match the legacy docker-compose.yml conf1/conf2 ports for
# any standalone invocation).
set -euo pipefail
CONF1_PORT="${CONF1_PORT:-8806}"
CONF2_PORT="${CONF2_PORT:-8807}"

POST_EN_ID=$(wp_conf2 post list --post_type=post --name=conformance-polylang-post-en --field=ID)
POST_FR_ID=$(wp_conf2 post list --post_type=post --name=conformance-polylang-post-fr --field=ID)
NEWS_ID=$(wp_conf2 eval "echo get_term_by('slug', 'conformance-polylang-news', 'category')->term_id;")
ACT_ID=$(wp_conf2 eval "echo get_term_by('slug', 'conformance-polylang-actualites', 'category')->term_id;")
require_fixture_ids POST_EN_ID POST_FR_ID NEWS_ID ACT_ID
echo "conf2 local ids: post_en=$POST_EN_ID post_fr=$POST_FR_ID news=$NEWS_ID actualites=$ACT_ID"

# Anti-coincidence sanity: the fillers live ONLY on conf1 (created, then
# hard-deleted, so they're invisible to capture and never replayed on
# conf2) — that's what makes conf1's ids for this content HIGHER than
# conf2's, which independently assigns its own ids starting from ITS OWN
# low baseline during apply. So the real signature of the technique working
# is conf1's post_en id being noticeably ABOVE conf2's, not conf2's id being
# high in isolation — check the actual conf1 id (echoed by the seed step
# above into the run log) against conf2's here.
CONF1_POST_EN_ID=$(wp_conf1 post list --post_type=post --name=conformance-polylang-post-en --field=ID)
require_fixture_ids CONF1_POST_EN_ID
echo "conf1 local id: post_en=$CONF1_POST_EN_ID (vs conf2's $POST_EN_ID)"
[ "$CONF1_POST_EN_ID" != "$POST_EN_ID" ] \
  || fail "post_en landed on the SAME id ($POST_EN_ID) on both conf1 and conf2 — anti-coincidence fillers had no effect; (b) below would pass even on the old, broken code"
echo "ok: post_en's conf1 id ($CONF1_POST_EN_ID) and conf2 id ($POST_EN_ID) genuinely differ — the fillers broke the lucky-coincidence conf1/conf2 would otherwise share"

# --- (b) pll_get_post_translations() on conf2, using conf2's OWN local ids ---
POST_TR=$(wp_conf2 eval "echo json_encode(pll_get_post_translations($POST_EN_ID));")
require_observed_nonempty "conf2 Polylang post translation map" "$POST_TR"
echo "pll_get_post_translations($POST_EN_ID) = $POST_TR"
jq -e --argjson en "$POST_EN_ID" --argjson fr "$POST_FR_ID" '.en == $en and .fr == $fr' <<<"$POST_TR" >/dev/null \
  || fail "pll_get_post_translations($POST_EN_ID) did not return {en:$POST_EN_ID, fr:$POST_FR_ID} — got $POST_TR"
echo "ok: pll_get_post_translations() returns the correct pair using conf2-local ids"

# --- (c) pll_get_term_translations() on conf2, using conf2's OWN local ids ---
TERM_TR=$(wp_conf2 eval "echo json_encode(pll_get_term_translations($NEWS_ID));")
require_observed_nonempty "conf2 Polylang English term translation map" "$TERM_TR"
echo "pll_get_term_translations($NEWS_ID) = $TERM_TR"
jq -e --argjson en "$NEWS_ID" --argjson fr "$ACT_ID" '.en == $en and .fr == $fr' <<<"$TERM_TR" >/dev/null \
  || fail "pll_get_term_translations($NEWS_ID) did not return {en:$NEWS_ID, fr:$ACT_ID} — got $TERM_TR"
echo "ok: pll_get_term_translations() returns the correct pair using conf2-local ids (capability 1 + 2 proof)"

# Symmetric direction: Actualites -> News, and Polylang's own per-term
# language getter (proves the term_language relationship, not just
# term_translations).
TERM_TR_FR=$(wp_conf2 eval "echo json_encode(pll_get_term_translations($ACT_ID));")
require_observed_nonempty "conf2 Polylang French term translation map" "$TERM_TR_FR"
jq -e --argjson en "$NEWS_ID" --argjson fr "$ACT_ID" '.en == $en and .fr == $fr' <<<"$TERM_TR_FR" >/dev/null \
  || fail "pll_get_term_translations($ACT_ID) did not return the same pair — got $TERM_TR_FR"
NEWS_LANG=$(wp_conf2 eval "echo pll_get_term_language($NEWS_ID, 'slug');")
ACT_LANG=$(wp_conf2 eval "echo pll_get_term_language($ACT_ID, 'slug');")
require_observed_nonempty "conf2 Polylang English term language" "$NEWS_LANG"
require_observed_nonempty "conf2 Polylang French term language" "$ACT_LANG"
[ "$NEWS_LANG" = "en" ] || fail "News's term_language is '$NEWS_LANG', expected 'en'"
[ "$ACT_LANG" = "fr" ] || fail "Actualites's term_language is '$ACT_LANG', expected 'fr'"
echo "ok: term_language relationships (capability 1) correct in both directions"

# --- (d) zero fabricated post<->term-only-taxonomy relationships on conf2 ---
# Raw object_id counting is NOT the right tool for this: WordPress's schema
# cannot distinguish "object_id=1 IS post_en" from "object_id=1 IS some
# term" — and on THIS fixture, conf2's adopted "Uncategorized" (term_id=1)
# and its auto-created "Uncategorized-fr" (term_id=2) legitimately DO have
# their own term_language/term_translations relationships (correctly
# captured by capability 1 on conf1, correctly replayed by apply on conf2),
# coincidentally at the SAME numeric ids as post_en/post_fr. A naive
# `COUNT(*) WHERE object_id IN (1,2)` finds those legitimate rows and would
# misreport them as fabrication. The unambiguous test is Duo's OWN
# disambiguated representation: re-capture conf2 and confirm post_en/
# post_fr's `terms` field never contains a term-object taxonomy — exactly
# the assertion sandbox/tests/live/regress_collision.sh uses for its (engineered)
# collision, applied here to this fixture's (organic) one.
wp_conf2 duo capture --repo=/siterepo --out=/siterepo/.tmp-fabcheck >/dev/null
POST_EN_FILE=$(find "${CONF_REPO2:-siterepo/conf2}"/.tmp-fabcheck/posts -name '*conformance-polylang-post-en*')
POST_FR_FILE=$(find "${CONF_REPO2:-siterepo/conf2}"/.tmp-fabcheck/posts -name '*conformance-polylang-post-fr*')
for f in "$POST_EN_FILE" "$POST_FR_FILE"; do
  JSON=$(cat "$f")
  grep -q '"term_language"' <<<"$JSON" \
    && fail "$f has a fabricated term_language relationship"
  grep -q '"term_translations"' <<<"$JSON" \
    && fail "$f has a fabricated term_translations relationship"
done
rm -rf "${CONF_REPO2:-siterepo/conf2}"/.tmp-fabcheck
echo "ok: post_en/post_fr's captured relationships never include a term-object taxonomy (no fabrication, despite Uncategorized/Uncategorized-fr coincidentally sharing their numeric ids)"

# --- byte-level type fidelity: the re-serialized description must carry ---
# --- REAL PHP ints (i:N;), never the ACF/Yoast digit-string convention ----
POST_GROUP_TT=$(wp_conf2 db query "
  SELECT tr.term_taxonomy_id FROM wp_term_relationships tr
  JOIN wp_term_taxonomy tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
  WHERE tr.object_id = $POST_EN_ID AND tt.taxonomy = 'post_translations'
" --skip-column-names)
require_observed_nonempty "conf2 Polylang post translation term-taxonomy id" "$POST_GROUP_TT"
TERM_GROUP_TT=$(wp_conf2 db query "
  SELECT tr.term_taxonomy_id FROM wp_term_relationships tr
  JOIN wp_term_taxonomy tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
  WHERE tr.object_id = $NEWS_ID AND tt.taxonomy = 'term_translations'
" --skip-column-names)
require_observed_nonempty "conf2 Polylang term translation term-taxonomy id" "$TERM_GROUP_TT"
POST_GROUP_DESC=$(wp_conf2 db query "SELECT description FROM wp_term_taxonomy WHERE term_taxonomy_id = $POST_GROUP_TT" --skip-column-names)
TERM_GROUP_DESC=$(wp_conf2 db query "SELECT description FROM wp_term_taxonomy WHERE term_taxonomy_id = $TERM_GROUP_TT" --skip-column-names)
require_observed_nonempty "conf2 Polylang post translation serialized description" "$POST_GROUP_DESC"
require_observed_nonempty "conf2 Polylang term translation serialized description" "$TERM_GROUP_DESC"
echo "post_translations group description: $POST_GROUP_DESC"
echo "term_translations group description: $TERM_GROUP_DESC"
for DESC in "$POST_GROUP_DESC" "$TERM_GROUP_DESC"; do
  grep -qE 's:[0-9]+:"[0-9]+"' <<<"$DESC" \
    && fail "description has a STRING-typed id (should be PHP int i:N;): $DESC"
  grep -qE 'i:[0-9]+;' <<<"$DESC" \
    || fail "description has no int-typed (i:N;) id at all — re-serialize may have dropped/corrupted it: $DESC"
done
# And the exact expected byte-for-byte content, built from conf2's own ids
# (PHP serialize()'s key order is insertion order; struct_apply() walks the
# canonical map in "$.*" order over a PHP array built from JSON-decoded
# keys, which — since Canon::decode() uses json_decode(..., true) preserving
# on-disk key order, and the on-disk order is Canon's own alphabetical sort
# — is deterministically "en" then "fr").
EXPECT_POST_DESC="a:2:{s:2:\"en\";i:${POST_EN_ID};s:2:\"fr\";i:${POST_FR_ID};}"
EXPECT_TERM_DESC="a:2:{s:2:\"en\";i:${NEWS_ID};s:2:\"fr\";i:${ACT_ID};}"
[ "$POST_GROUP_DESC" = "$EXPECT_POST_DESC" ] \
  || fail "post_translations description byte mismatch. got: $POST_GROUP_DESC want: $EXPECT_POST_DESC"
[ "$TERM_GROUP_DESC" = "$EXPECT_TERM_DESC" ] \
  || fail "term_translations description byte mismatch. got: $TERM_GROUP_DESC want: $EXPECT_TERM_DESC"
echo "ok: re-serialized descriptions are byte-exact PHP serialize() output with genuine int-typed ids"

# --- render/negative host-leak convention (checks/fse.sh's methodology, ---
# --- extended here to prove ordinary post round-trip wasn't disturbed) ----
FRONT=$(curl -fsSL "http://localhost:${CONF2_PORT}/conformance-polylang-post-en/") || fail "conf2 post_en page did not return 200"
require_observed_nonempty "conf2 Polylang rendered response" "$FRONT"
[ "${#FRONT}" -ge 1000 ] \
  || fail "conf2 post_en response was suspiciously short (${#FRONT} bytes)"
if grep -qiE 'fatal error|uncaught' <<<"$FRONT"; then
    fail "conf2's rendered post_en page contains a PHP fatal error marker"
fi
if grep -qE "localhost:${CONF1_PORT}" <<<"$FRONT"; then
    fail "conf2's rendered post_en page links back to conf1 (localhost:${CONF1_PORT})"
fi
grep -qE 'Conformance content \(English\)' <<<"$FRONT" \
  || fail "conf2's post_en page does not render its own content"
echo "ok: conf2 renders post_en's own content, no conf1 host-leak"

echo "polylang conformance checks passed"
