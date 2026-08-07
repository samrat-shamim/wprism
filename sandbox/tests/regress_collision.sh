#!/usr/bin/env bash
# Regression — object-id collision in relationship handling (engine bug, not
# a plugin-support test): posts and terms are minted from independent
# auto-increment counters that share one numeric space. Capture's and
# Apply's term-relationship queries used to key on bare `object_id` with no
# check of *which kind of object* actually owns that id — so a term with
# term-to-term relationships (Polylang's term_language/term_translations,
# object_type=["term"]) could silently glue its own relationships onto a
# POST that happened to reuse its numeric id, both at capture (fabricated
# into the post's `terms` field) and at apply (fabricated into the target's
# DB, or — worse — a colliding term's genuine relationships deleted as
# "extra" during reconciliation). See docs/frontier/polylang.md's
# "Object-id collision cross-contaminates captured POST relationships".
#
# Why this isn't in `make spikes`: this is primarily an engine-invariant
# regression, not a plugin-conformance test — it needs Polylang purely
# because no core taxonomy is ever term-object, so no core-only fixture can
# force the collision into the open. (Task #20 later shipped manifests/
# polylang.json, now pinned below alongside "core" so this fixture also
# re-proves the collision fix holds with the description_refs rewrite
# active for the same taxonomies — see acceptance (d) — but sandbox/
# conformance/{seeds,checks}/polylang.sh is the actual plugin-conformance
# target for that manifest, run via `conformance/run.sh polylang`.) It also
# runs on its own self-booting env pair (fx, ports 8810/8811) rather than
# chaining onto the shared a/b envs the way spike_b..spike_f do, so it
# doesn't fit the spikes target's dependency chain. Keeping it separate
# keeps the routine spikes/CI path free of a third-party plugin download
# for a check that has nothing to do with Polylang support specifically.
#
# The fixture deliberately does NOT rely on WordPress's own first-post/
# first-term coincidence (both auto-increment counters reset to 1 after
# `wp site empty`, which is how the original frontier report stumbled onto
# its collision by luck): it discovers a real term's id after seeding, then
# inserts exactly enough filler posts to force a *different*, engineered
# post to land on that same numeric id (acceptance criterion (d) in the
# frontier report's proposed acceptance criteria).
set -euo pipefail
cd "$(dirname "$0")/.."   # -> sandbox/
COMPOSE="docker compose -f docker-compose.yml --profile fx"
wp_env() { local env="$1"; shift; $COMPOSE run --rm -T "cli-$env" wp "$@"; }
wp_fx1() { wp_env fx1 "$@"; }
wp_fx2() { wp_env fx2 "$@"; }
say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }

say "clean-room: removing any existing fx1/fx2 containers + volumes"
$COMPOSE rm -sf wp-fx1 cli-fx1 db-fx1 wp-fx2 cli-fx2 db-fx2 >/dev/null 2>&1 || true
docker volume rm -f duo-sandbox_dbfx1 duo-sandbox_wpfx1 duo-sandbox_dbfx2 duo-sandbox_wpfx2 >/dev/null 2>&1 || true
rm -rf siterepo/fx1 siterepo/fx2 siterepo/origin-fx.git
mkdir -p siterepo/fx1 siterepo/fx2

say "boot fx1 (:8810) / fx2 (:8811)"
$COMPOSE up -d db-fx1 wp-fx1 db-fx2 wp-fx2

wait_for() { # wait_for <fx1|fx2>
  local env="$1"
  echo "waiting for env $env..."
  for _ in $(seq 1 90); do
    wp_env "$env" core version >/dev/null 2>&1 && return 0
    sleep 2
  done
  fail "env $env never became ready"
}

write_htaccess() { # write_htaccess <fx1|fx2>
  $COMPOSE exec -T -u www-data "wp-$1" tee /var/www/html/.htaccess >/dev/null <<'EOF'
# BEGIN WordPress
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
RewriteBase /
RewriteRule ^index\.php$ - [L]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule . /index.php [L]
</IfModule>
# END WordPress
EOF
}

install_env() { # install_env <fx1|fx2> <port> <title>
  local env="$1" port="$2" title="$3"
  wait_for "$env"
  wp_env "$env" core install \
    --url="http://localhost:$port" --title="$title" \
    --admin_user=admin --admin_password=admin \
    --admin_email=admin@example.test --skip-email
  wp_env "$env" theme install twentytwentyone --activate
  wp_env "$env" option update permalink_structure '/%postname%/'
  wp_env "$env" rewrite flush
  write_htaccess "$env"
  wp_env "$env" site empty --yes
  wp_env "$env" plugin install polylang --activate
  echo "env $env installed (Polylang $(wp_env "$env" plugin get polylang --field=version))"
}
install_env fx1 8810 "Duo FX1"
install_env fx2 8811 "Duo FX2"
pass "both envs installed, Polylang active on both (fx2 needs it active too: Apply's object-type filter must be able to resolve the same taxonomies on the target)"

say "init the site repo — Polylang's own taxonomies deliberately in scope"
# term_language/term_translations are the term-object taxonomies that expose
# the bug; language/post_translations are post-object taxonomies that must
# keep working normally (proves the fix doesn't over-filter).
git init --bare -b main siterepo/origin-fx.git >/dev/null
cat > siterepo/fx1/site.duo.json <<'EOF'
{
  "manifests": ["core", "polylang"],
  "policy": {
    "options": {},
    "post_meta": {},
    "post_types": ["post", "page", "attachment"],
    "taxonomies": ["category", "post_tag", "language", "term_language", "term_translations", "post_translations"]
  },
  "spec_version": 1
}
EOF
printf '.tmp*\n' > siterepo/fx1/.gitignore
git -C siterepo/fx1 init -q -b main
git -C siterepo/fx1 remote add origin ../origin-fx.git

say "seed: two languages, a translated category pair (News/Actualites)"
wp_fx1 eval '
$model = PLL()->model;
$model->languages->add(["locale" => "en_US", "slug" => "en", "name" => "English"]);
$model->languages->add(["locale" => "fr_FR", "slug" => "fr", "name" => "French"]);
echo "languages added\n";
'
TERM_OUT=$(wp_fx1 eval '
$news = wp_insert_term("News", "category", ["slug" => "news"]);
$act = wp_insert_term("Actualites", "category", ["slug" => "actualites"]);
if (is_wp_error($news) || is_wp_error($act)) { fwrite(STDERR, "term creation failed\n"); exit(1); }
$news_id = (int) $news["term_id"];
$act_id = (int) $act["term_id"];
pll_set_term_language($news_id, "en");
pll_set_term_language($act_id, "fr");
pll_save_term_translations(["en" => $news_id, "fr" => $act_id]);
echo "$news_id $act_id\n";
')
read -r NEWS_TERM_ID FR_TERM_ID <<< "$TERM_OUT"
pass "News/Actualites created and linked as term translations (news=$NEWS_TERM_ID actualites=$FR_TERM_ID)"

say "engineer the collision: force a NEW post onto News's own term_id (criterion (d): not the lucky first-row coincidence)"
CUR_MAX=$(wp_fx1 eval 'global $wpdb; echo (int) $wpdb->get_var("SELECT COALESCE(MAX(ID),0) FROM {$wpdb->posts}");')
FILLERS_NEEDED=$((NEWS_TERM_ID - CUR_MAX - 1))
[ "$FILLERS_NEEDED" -ge 1 ] || fail "fixture assumption broke: need >=1 filler post between languages and content to avoid a lucky id coincidence (news_term_id=$NEWS_TERM_ID cur_max_post_id=$CUR_MAX) — Polylang's internal term bookkeeping must have changed shape"
for i in $(seq 1 "$FILLERS_NEEDED"); do
  wp_fx1 post create --post_type=post --post_title="Filler $i" --post_name="filler-$i" --post_status=publish --porcelain >/dev/null
done
echo "inserted $FILLERS_NEEDED unrelated filler post(s) between language setup and the translated content"

POST_EN=$(wp_fx1 post create --post_type=post --post_title='Hello Duo' --post_name=post-en --post_status=publish \
  --post_content='<!-- wp:paragraph --><p>Hello from Duo (English).</p><!-- /wp:paragraph -->' --porcelain)
[ "$POST_EN" = "$NEWS_TERM_ID" ] || fail "fixture assumption broke: post_en id ($POST_EN) != News term_id ($NEWS_TERM_ID) — collision was not engineered"
POST_FR=$(wp_fx1 post create --post_type=post --post_title='Bonjour Duo' --post_name=post-fr --post_status=publish \
  --post_content='<!-- wp:paragraph --><p>Bonjour de Duo (Francais).</p><!-- /wp:paragraph -->' --porcelain)
pass "post_en (id=$POST_EN) now collides with News's own term_id ($NEWS_TERM_ID); post_fr=$POST_FR"

say "translate the posts and tag them (language BEFORE category: Polylang swaps a term for its same-language translation on assignment otherwise)"
wp_fx1 eval "
pll_set_post_language($POST_EN, 'en');
pll_set_post_language($POST_FR, 'fr');
pll_save_post_translations(['en' => $POST_EN, 'fr' => $POST_FR]);
wp_set_object_terms($POST_EN, [$NEWS_TERM_ID], 'category', false);
wp_set_object_terms($POST_FR, [$FR_TERM_ID], 'category', false);
echo 'tagged';
"
CATS_EN=$(wp_fx1 eval "echo implode(',', wp_list_pluck(wp_get_post_terms($POST_EN, 'category'), 'slug'));")
CATS_FR=$(wp_fx1 eval "echo implode(',', wp_list_pluck(wp_get_post_terms($POST_FR, 'category'), 'slug'));")
[ "$CATS_EN" = "news" ] || fail "post_en category is '$CATS_EN', expected 'news'"
[ "$CATS_FR" = "actualites" ] || fail "post_fr category is '$CATS_FR', expected 'actualites'"
pass "post_en=news, post_fr=actualites (correctly language-matched, not swapped)"

say "capture fx1 — must NOT fabricate News's term-to-term relationships onto post_en"
wp_fx1 duo capture --repo=/siterepo
POST_EN_FILE=$(find siterepo/fx1/state/posts -name '*post-en*')
[ -n "$POST_EN_FILE" ] || fail "post-en captured file not found"
POST_EN_JSON=$(cat "$POST_EN_FILE")
echo "$POST_EN_JSON"
grep -q '"term_language"' <<<"$POST_EN_JSON" \
  && fail "FABRICATION: post_en's terms field contains term_language — that's News-the-term's own membership (term_id=$NEWS_TERM_ID), not post_en's"
grep -q '"term_translations"' <<<"$POST_EN_JSON" \
  && fail "FABRICATION: post_en's terms field contains term_translations — that's News-the-term's own membership (term_id=$NEWS_TERM_ID), not post_en's"
grep -q '"category"' <<<"$POST_EN_JSON" || fail "post_en lost its legitimate category relationship"
grep -q '"language"' <<<"$POST_EN_JSON" || fail "post_en lost its legitimate language relationship"
grep -q '"post_translations"' <<<"$POST_EN_JSON" || fail "post_en lost its legitimate post_translations relationship"
pass "post_en's captured relationships are exactly {category, language, post_translations} — no term-only-taxonomy fabrication"

say "capture fx1 — News's OWN term file must capture ITS term_language + term_translations relationships (task #20 capability: term-object relationship capture, the actual fix for the report's 'pll_get_term_translations() returns empty' root cause)"
NEWS_FILE=$(find siterepo/fx1/state/terms/category -name '*news*')
[ -n "$NEWS_FILE" ] || fail "News's captured term file not found"
NEWS_JSON=$(cat "$NEWS_FILE")
echo "$NEWS_JSON"
grep -q '"relationships"' <<<"$NEWS_JSON" || fail "News's term file has no relationships field at all"
grep -q '"term_language"' <<<"$NEWS_JSON" || fail "News lost its OWN term_language relationship (should point at pll_en) — this belongs on News's OWN file, not post_en's, even though they share a numeric id"
grep -q '"term_translations"' <<<"$NEWS_JSON" || fail "News lost its OWN term_translations relationship (should point at its group with Actualites)"
pass "News's term file correctly captures its own term_language + term_translations relationships"

say "acceptance: capture is deterministic (capture twice, zero diff)"
wp_fx1 duo capture --repo=/siterepo --out=/siterepo/.tmp-state2 >/dev/null
diff -r siterepo/fx1/state siterepo/fx1/.tmp-state2 || fail "capture is not deterministic"
rm -rf siterepo/fx1/.tmp-state2
pass "capture-twice diff is empty"

say "commit fx1, clone to fx2, apply"
git -C siterepo/fx1 add -A
git -C siterepo/fx1 -c user.name=duo -c user.email=duo@example.test commit -qm "capture: Polylang object-id collision fixture"
git -C siterepo/fx1 push -q origin main
git clone -q siterepo/origin-fx.git siterepo/fx2
REV=$(git -C siterepo/fx2 rev-parse HEAD)
wp_fx2 duo apply --repo=/siterepo --adopt-by-slug=terms,posts --default-author=admin --revision="$REV"

say "acceptance (a): canonical(fx2) == canonical(fx1), byte for byte"
wp_fx2 duo capture --repo=/siterepo --out=/siterepo/.tmp-fx2state >/dev/null
diff -r siterepo/fx1/state siterepo/fx2/.tmp-fx2state || fail "round-trip mismatch between fx1 and fx2"
pass "canonical state identical across environments"

say "acceptance (b): no wp_term_relationships row on fx2 relates post_en's local id to a term-only taxonomy"
POST_EN_FX2=$(wp_fx2 post list --post_type=post --name=post-en --field=ID)
FAB_COUNT=$(wp_fx2 db query "
  SELECT COUNT(*) FROM wp_term_relationships tr
  JOIN wp_term_taxonomy tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
  WHERE tr.object_id = $POST_EN_FX2 AND tt.taxonomy IN ('term_language','term_translations')
" --skip-column-names)
[ "$FAB_COUNT" = "0" ] || fail "found $FAB_COUNT fabricated term-only-taxonomy row(s) on fx2's post_en (local id $POST_EN_FX2)"
pass "post_en's local id ($POST_EN_FX2) on fx2 has zero term_language/term_translations rows"

say "acceptance (c): re-captured fx2 post_en file has no term-taxonomy entries it never had"
FX2_POST_EN_FILE=$(find siterepo/fx2/.tmp-fx2state/posts -name '*post-en*')
FX2_POST_EN_JSON=$(cat "$FX2_POST_EN_FILE")
grep -q '"term_language"' <<<"$FX2_POST_EN_JSON" && fail "fx2's re-captured post_en has term_language"
grep -q '"term_translations"' <<<"$FX2_POST_EN_JSON" && fail "fx2's re-captured post_en has term_translations"
pass "fx2's re-captured post_en matches fx1's — no fabricated entries introduced by apply"
rm -rf siterepo/fx2/.tmp-fx2state

say "acceptance (d): pll_get_term_translations() on fx2 returns the correct pair using fx2's OWN local ids — the report's sharpest finding (this exact call used to return an EMPTY array on the target) — proven live via Polylang's own API, not just Duo's state tree"
NEWS_FX2=$(wp_fx2 eval "echo get_term_by('slug', 'news', 'category')->term_id;")
ACT_FX2=$(wp_fx2 eval "echo get_term_by('slug', 'actualites', 'category')->term_id;")
[ "$NEWS_FX2" != "$NEWS_TERM_ID" ] || echo "note: News's fx1 and fx2 local ids coincidentally match ($NEWS_FX2) — the assertion below still holds, it's just not exercising a genuine id divergence this time"
TERM_TR_FX2=$(wp_fx2 eval "echo json_encode(pll_get_term_translations((int) $NEWS_FX2));")
echo "fx2: news=$NEWS_FX2 actualites=$ACT_FX2 pll_get_term_translations(news)=$TERM_TR_FX2"
echo "$TERM_TR_FX2" | jq -e --argjson en "$NEWS_FX2" --argjson fr "$ACT_FX2" '.en == $en and .fr == $fr' >/dev/null \
  || fail "pll_get_term_translations($NEWS_FX2) on fx2 did not return {en:$NEWS_FX2, fr:$ACT_FX2} — got $TERM_TR_FX2"
pass "pll_get_term_translations() on fx2 returns the correct pair using fx2's own local ids"

say "posture check: a policy-scoped taxonomy that's NOT registered at runtime warns loudly (names the taxonomy) and skips, never aborts or silently trusts"
wp_fx1 plugin deactivate polylang >/dev/null
set +e
UNREG_OUT=$(wp_fx1 duo capture --repo=/siterepo --out=/siterepo/.tmp-unreg-state 2>&1)
UNREG_RC=$?
set -e
echo "$UNREG_OUT"
[ "$UNREG_RC" -eq 0 ] || fail "capture aborted with an unregistered scoped taxonomy (posture is warn-and-skip, not abort)"
for tax in language term_language term_translations post_translations; do
  grep -q "'$tax'" <<<"$UNREG_OUT" || fail "no warning names taxonomy '$tax' when it's unregistered"
done
UNREG_POST_EN=$(find siterepo/fx1/.tmp-unreg-state/posts -name '*post-en*')
UNREG_JSON=$(cat "$UNREG_POST_EN")
grep -q '"language"' <<<"$UNREG_JSON" && fail "post_en still has 'language' relationships with the taxonomy unregistered — should have been skipped, not guessed"
grep -q '"category"' <<<"$UNREG_JSON" || fail "post_en lost 'category' (a core taxonomy, unaffected by Polylang being inactive)"
UNREG_NEWS_FILE=$(find siterepo/fx1/.tmp-unreg-state/terms/category -name '*news*')
UNREG_NEWS_JSON=$(cat "$UNREG_NEWS_FILE")
grep -q '"term_language"' <<<"$UNREG_NEWS_JSON" && fail "News's term file still has 'term_language' with the taxonomy unregistered — should have been skipped, not guessed"
grep -q '"term_translations"' <<<"$UNREG_NEWS_JSON" && fail "News's term file still has 'term_translations' with the taxonomy unregistered — should have been skipped, not guessed"
grep -q '"relationships"' <<<"$UNREG_NEWS_JSON" || fail "News's term file lost its 'relationships' field entirely when the taxonomy is unregistered — should degrade to an empty {}, not disappear"
rm -rf siterepo/fx1/.tmp-unreg-state
wp_fx1 plugin activate polylang >/dev/null
pass "unregistered-taxonomy warning names all 4 Polylang taxonomies, capture still succeeds, and BOTH posts' and terms' relationships are cleanly skipped rather than guessed"

printf '\n\033[1;32m✔ REGRESS_COLLISION PASSED\033[0m\n'
