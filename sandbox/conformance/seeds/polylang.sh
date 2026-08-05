#!/usr/bin/env bash
# Polylang manifest conformance seed: docs/frontier/polylang.md's exact
# fixture (two languages, one translated category pair, one translated post
# pair) plus the report's own proposed acceptance-criterion (d) hardening —
# "extend the fixture with an extra unrelated post/attachment inserted
# between languages and content, specifically to break the lucky-coincidence
# case this exploration didn't stress." Without that, conf1 and conf2 go
# through IDENTICAL install_env() steps (same plugin, same activation
# hooks), so their auto-increment counters start in lockstep and the
# translated pair would likely land on the SAME numeric ids on both sides —
# exactly the coincidence that let the ORIGINAL fx1/fx2 bug hide (stale
# source ids silently still "worked" on the target by luck). The filler
# posts/terms below are created then immediately hard-deleted: they consume
# real auto-increment slots on conf1 ONLY (a deleted row is invisible to
# Capture's scope_posts()/scope_terms(), so conf2 never recreates them and
# never "catches up" past the same slots) — genuinely decoupling conf1's and
# conf2's ids for the SAME uuid, so checks/polylang.sh's assertions exercise
# the real ledger-based rewrite rather than passing by accident.
#
# Invoked by conformance/run.sh with wp_conf1/wp_conf2/$COMPOSE already
# exported; runs from the sandbox/ directory.
set -euo pipefail

echo "seeding two languages"
wp_conf1 eval '
$model = PLL()->model;
$model->languages->add(["locale" => "en_US", "slug" => "en", "name" => "English"]);
$model->languages->add(["locale" => "fr_FR", "slug" => "fr", "name" => "French"]);
echo "languages added\n";
'

echo "anti-coincidence fillers: consume auto-increment ids on conf1 that conf2 will never replay"
for i in 1 2 3 4 5; do
  FILLER_POST=$(wp_conf1 post create --post_type=post --post_title="Polylang anti-coincidence filler $i" \
    --post_status=publish --porcelain)
  wp_conf1 post delete "$FILLER_POST" --force >/dev/null
done
for i in 1 2 3; do
  FILLER_TERM=$(wp_conf1 term create category "Polylang anti-coincidence filler cat $i" --porcelain)
  wp_conf1 term delete category "$FILLER_TERM" >/dev/null
done
echo "5 filler posts + 3 filler categories created and hard-deleted on conf1 only"

echo "translated category pair (term-object relationships: term_language + term_translations)"
TERM_OUT=$(wp_conf1 eval '
$news = wp_insert_term("Conformance Polylang News", "category", ["slug" => "conformance-polylang-news"]);
$act = wp_insert_term("Conformance Polylang Actualites", "category", ["slug" => "conformance-polylang-actualites"]);
if (is_wp_error($news) || is_wp_error($act)) { fwrite(STDERR, "term creation failed\n"); exit(1); }
$news_id = (int) $news["term_id"];
$act_id = (int) $act["term_id"];
pll_set_term_language($news_id, "en");
pll_set_term_language($act_id, "fr");
pll_save_term_translations(["en" => $news_id, "fr" => $act_id]);
echo "$news_id $act_id\n";
')
read -r NEWS_TERM_ID ACT_TERM_ID <<< "$TERM_OUT"
echo "conf1 categories: news=$NEWS_TERM_ID actualites=$ACT_TERM_ID"

echo "translated post pair (post-object relationships: language + post_translations, already-working machinery)"
POST_EN=$(wp_conf1 post create --post_type=post --post_title='Conformance Polylang Post EN' \
  --post_name=conformance-polylang-post-en --post_status=publish \
  --post_content='<!-- wp:paragraph --><p>Conformance content (English).</p><!-- /wp:paragraph -->' --porcelain)
POST_FR=$(wp_conf1 post create --post_type=post --post_title='Conformance Polylang Post FR' \
  --post_name=conformance-polylang-post-fr --post_status=publish \
  --post_content='<!-- wp:paragraph --><p>Contenu de conformite (Francais).</p><!-- /wp:paragraph -->' --porcelain)

# language BEFORE category: Polylang swaps a term for its same-language
# translation on wp_set_object_terms() otherwise (regress_collision.sh's
# same finding).
wp_conf1 eval "
pll_set_post_language($POST_EN, 'en');
pll_set_post_language($POST_FR, 'fr');
pll_save_post_translations(['en' => $POST_EN, 'fr' => $POST_FR]);
wp_set_object_terms($POST_EN, [$NEWS_TERM_ID], 'category', false);
wp_set_object_terms($POST_FR, [$ACT_TERM_ID], 'category', false);
echo 'tagged';
"
CATS_EN=$(wp_conf1 eval "echo implode(',', wp_list_pluck(wp_get_post_terms($POST_EN, 'category'), 'slug'));")
CATS_FR=$(wp_conf1 eval "echo implode(',', wp_list_pluck(wp_get_post_terms($POST_FR, 'category'), 'slug'));")
[ "$CATS_EN" = "conformance-polylang-news" ] || { echo "post_en category mismatch: $CATS_EN" >&2; exit 1; }
[ "$CATS_FR" = "conformance-polylang-actualites" ] || { echo "post_fr category mismatch: $CATS_FR" >&2; exit 1; }

echo "polylang seed: news=$NEWS_TERM_ID actualites=$ACT_TERM_ID post_en=$POST_EN post_fr=$POST_FR"
