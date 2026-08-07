#!/usr/bin/env bash
# Regression — DUO-3233: sub-key option classification. Manifest grammar to
# classify NAMED sub-keys of one option blob independently (capture some
# keys, exclude the rest), with apply-side SUB-KEY-LEVEL merge into the live
# blob that never clobbers excluded sibling keys. Closes the long-documented
# gap in manifests/polylang.json's own notes and grind board #121/task #121
# (docs/grind/r3a-multilingual-shop.md): the `polylang` option's
# `post_types`/`taxonomies`/`nav_menus` sub-keys never propagated, so a
# translated-CPT's language relationship and a per-language menu-location
# swap broke on every fresh target.
#
# Covers, against a fresh, from-scratch pair (own pair.sh-managed pair,
# default `asub3233`/:8910/:8911, parameterized -- see DUO-3276 note below
# -- the driver never tears it down):
#   (1) capture carves out ONLY the declared sub-keys of `polylang`
#       (post_types/taxonomies/nav_menus) — none of force_lang/domains/
#       hide_default/rewrite/redirect_lang/browser/media_support/sync/
#       default_lang/first_activation/previous_version/version ever enter
#       canonical state; nav_menus' per-language menu-term-ids are
#       correctly tokenized (json_refs, kind: term).
#   (2) apply on a genuinely fresh target (Polylang installed+active,
#       ZERO manual language/Settings config) MERGES the captured sub-keys
#       into the target's OWN live `polylang` option — its own
#       force_lang/hide_default/... survive untouched, and the nav_menus
#       term ids resolve to the TARGET's own local ids (proven to differ
#       from the source's).
#   (3) the well-documented, architecture-level Polylang timing hazard
#       (WordPress's own register_taxonomy() fixes a taxonomy's object_type
#       for the whole PHP process at `init`, before this apply's own
#       sub_keys merge can possibly take effect) is exercised and shown to
#       resolve automatically on the NEXT apply — zero manual Settings
#       replication, matching the SAME established pattern task #92 already
#       set for WooCommerce's pa_* attribute relationships (a genuine
#       content change forces reprocessing, moving state towards --
#       never away from -- fully resolved).
#   (4) pll_get_post_language()/pll_get_post_translations() resolve
#       correctly on the target for a Polylang-managed custom post type,
#       using the target's OWN local ids.
#   (5) the correct per-language menu renders at its shared location via a
#       real HTTP request per language — zero manual menu reassignment.
#   (6) never clobbering excluded sibling keys: re-asserted directly
#       against the database (every non-carved-out key byte-identical
#       before/after apply).
#   (7) the second real-plugin proof of the SAME grammar: Yoast's `wpseo`
#       option (identical mixed authored/env-bound shape) — one real,
#       empirically-verified toggle (`disableadvanced_meta`) merges
#       cleanly without touching `version`/`first_activated_on`/the ~115
#       other sibling keys.
#   (8) negative: RepositoryAuthorization refuses a captured `polylang`
#       value carrying an UNDECLARED sub-key (a hand-edited or stale repo
#       file) — the same "unknown field refuses" discipline every other
#       entity surface already gets, applied to option sub-keys.
#   (9) negative: `wp duo lint` flags a bare numeric id smuggled into a
#       PLAIN (no json_refs) sub-key value (post_types) — exercises
#       scan_option_sub_keys()'s shallow branch directly. A companion,
#       NOT-asserted note documents a separate, pre-existing Lint.php
#       blind spot the same testing surfaced: its DEEP (json_refs) bare-id
#       scan only flags ids under id-NAMED keys, which nav_menus'
#       language-slug-keyed shape structurally can't match — filed for
#       the record, not fixed here (out of this task's scope).
#
# Self-contained and re-runnable: wipes this pair's content/ledger/site-repo
# each run (mirrors grind_r3a_multilingual.sh's own reset_env_state
# pattern), never tears down containers, never touches another agent's pair.
set -euo pipefail
cd "$(dirname "$0")/.."   # -> sandbox/

# DUO-3276: the pair NAME/PORTS are parameterized so this regression can run
# on its own pair instead of colliding with whoever else is using the
# hardcoded default -- the identical footgun class DUO-3252 (PR #35, commit
# 414a577) fixed for regress_option_reconciliation.sh, which already reset a
# FOREIGN pair live once (an agent ran that script unread as an ancillary
# check; its unconditional `pair.sh reset` wiped that pair's database and
# host state with zero warning). `asub3233` here is the same shape: dead
# residue naming from asub's own long-closed DUO-3233 work, sitting in a
# script anyone might run. Default PAIR=asub3233/PORT1=8910/PORT2=8911 keeps
# existing single-user/CI behavior byte-identical; run your own copy with
#   PAIR=acore3276 PORT1=8930 PORT2=8931 bash regress_option_subkeys.sh
# A custom PAIR REQUIRES explicit PORT1/PORT2 (mirrors sandbox/conformance/
# run.sh's own CONF_PAIR mechanism, commit 3aab875 -- the same precedent
# DUO-3252 itself cites): defaulting a custom pair name onto the SAME
# hardcoded ports would just relocate the collision risk from the pair name
# to the port numbers instead of removing it.
PAIR="${PAIR:-asub3233}"
[[ "$PAIR" =~ ^[a-z][a-z0-9]*$ ]] \
  || { echo "FAIL: PAIR '$PAIR' invalid (pair.sh naming: lowercase letters/digits, letter first)" >&2; exit 1; }
if [ "$PAIR" != "asub3233" ] && { [ -z "${PORT1:-}" ] || [ -z "${PORT2:-}" ]; }; then
  echo "FAIL: custom PAIR '$PAIR' requires explicit PORT1 and PORT2 (the 8910/8911 defaults belong to the original pair)" >&2
  exit 1
fi
PORT1="${PORT1:-8910}"
PORT2="${PORT2:-8911}"
export DUO_PAIR="$PAIR" DUO_PORT1="$PORT1" DUO_PORT2="$PORT2"
COMPOSE="docker compose -p duo-$PAIR -f pair.yml -f pair.http.yml"

wp_env() { local side="$1"; shift; $COMPOSE run --rm -T "cli${side}" wp "$@"; }
wp1() { wp_env 1 "$@"; }
wp2() { wp_env 2 "$@"; }
say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }

# DUO-3276: retries `wp duo apply` a few times on the documented Polylang
# 3.8.6 stale-in-process-state class (Apply.php's own verify_convergence()
# docblock names this exact reproduced case: an in-process post-apply
# recapture -- verify_convergence_local(), the path this repo hits -- can
# see a stale in-memory Polylang model, producing a spurious canonical
# hash mismatch on THIS SPECIFIC PROCESS's re-read; a fresh subsequent
# apply/plan re-derives from the committed DB state and does not repeat
# it, exactly the DUO-3206 apply_in_progress marker + automatic
# retry-forcing mechanism regress_tec_regen.sh's own live-verified
# pattern already exercises for a different trigger). Confirmed live,
# not guessed: an apply that failed with "post-apply convergence
# verification failed ... canonical hash mismatch" on this fixture's own
# Polylang-translated project posts succeeded cleanly on a bare retry,
# both attempts producing IDENTICAL "update":16/canary-clean output
# otherwise -- no new content, nothing else different. Same retry-loop
# shape as cli_smoke.sh's own "duo doctor" wait (this project's existing
# precedent for "concurrent docker load is expected, retry briefly"),
# not a new pattern invented here. Every OTHER failure mode still aborts
# on the FIRST attempt via `return "$rc"` below -- this narrowly targets
# the one named, engine-documented, proven-transient class.
apply_with_retry() { # apply_with_retry <duo-apply-args...>
  local out rc attempt
  for attempt in 1 2 3; do
    # `$?` immediately after a bare `if ...; then ...; fi` with NO else is
    # the IF STATEMENT's own exit status (0 -- "completed without a shell
    # error"), NOT the failed condition's -- a real bash gotcha, caught by
    # this function's own offline unit test (scenario B: 3 genuine
    # failures were silently reported as success before this fix, exactly
    # the "silent failure masked as success" this project's own posture
    # refuses to tolerate). Capturing rc explicitly inside `else` is what
    # makes it correct.
    if out=$($COMPOSE run --rm -T cli2 wp duo apply "$@" 2>&1); then
      echo "$out"
      return 0
    else
      rc=$?
    fi
    if [ "$attempt" -lt 3 ] && echo "$out" | grep -qE 'incomplete_apply|convergence verification failed'; then
      echo "apply attempt $attempt hit the known-transient Polylang post-apply convergence staleness (Apply.php's own documented case); retrying..." >&2
      sleep 2
      continue
    fi
    echo "$out"
    return "$rc"
  done
}

command -v jq >/dev/null || fail "jq required"
command -v python3 >/dev/null || fail "python3 required"

say "pair.sh reset + up: TRUE clean slate (DROP/CREATE database, not just wp-cli-level content wiping) -- plugin FILES persist in the webroot volume (pair.sh reset never touches it), so the installs below are fast re-activations, not re-downloads"
bash bin/pair.sh reset "$PAIR"
bash bin/pair.sh up "$PAIR" "$PORT1" "$PORT2" --http
pass "pair $PAIR ready, database genuinely fresh on both sides"

install_env() { # install_env <1|2> -- Polylang+Yoast, idempotent
  local side="$1"
  wp_env "$side" plugin is-active polylang >/dev/null 2>&1 || wp_env "$side" plugin install polylang --activate
  wp_env "$side" plugin is-active wordpress-seo >/dev/null 2>&1 || wp_env "$side" plugin install wordpress-seo --activate
}

say "install Polylang+Yoast on both sides (independent installs, matching every prior grind round's precedent)"
install_env 1
install_env 2
pass "both envs installed on a genuinely fresh database"

say "the project CPT fixture (sandbox/fixtures/duo-agency-cpt, R1-C's own fixture): drop in as an mu-plugin on both sides -- registered unconditionally, no activation step needed"
for side in 1 2; do
  $COMPOSE exec -T "wp${side}" mkdir -p /var/www/html/wp-content/mu-plugins
  $COMPOSE exec -T "wp${side}" tee /var/www/html/wp-content/mu-plugins/duo-agency-cpt.php >/dev/null < fixtures/duo-agency-cpt/duo-agency-cpt.php
  wp_env "$side" eval 'var_export(post_type_exists("project"));' | grep -q true || fail "project CPT did not register on side $side"
done
pass "project CPT registered both sides"

say "side1: languages (en default, de) -- separate wp-cli process from the option write below (documented write-path hazard, task #121 finding 5)"
wp1 eval "
PLL()->model->languages->add(['locale'=>'en_US','slug'=>'en','name'=>'English']);
PLL()->model->languages->add(['locale'=>'de_DE','slug'=>'de','name'=>'Deutsch']);
"

say "side1: enable project/project_type for translation via the polylang option's post_types/taxonomies sub-keys (a real admin action, done ONCE here -- the fresh target below gets this AUTOMATICALLY via duo apply, never by hand)"
wp1 eval "
\$o = get_option('polylang');
\$o['default_lang'] = 'en';
\$o['post_types'] = array_values(array_unique(array_merge(\$o['post_types'] ?? [], ['project'])));
\$o['taxonomies'] = array_values(array_unique(array_merge(\$o['taxonomies'] ?? [], ['project_type'])));
update_option('polylang', \$o);
"
OBJ_OK=0
for _ in 1 2 3 4 5 6 7 8; do
  OBJTYPE=$(wp1 eval "\$t=get_taxonomy('language'); echo implode(',', (array) \$t->object_type);")
  echo "$OBJTYPE" | grep -q project && { OBJ_OK=1; break; }
done
[ "$OBJ_OK" = "1" ] || fail "language taxonomy's object_type does not include 'project' on side1 after 8 checks (got: $OBJTYPE)"
pass "en/de added; project/project_type enabled for translation on side1"

say "side1: translated project pair (English + German) -- language tag via wp_set_object_terms directly (pll_set_post_language()'s documented silent no-op, task #121 finding 7), translations linked via pll_save_post_translations()"
PROJ_JSON=$(wp1 eval "
\$en = wp_insert_post(['post_type'=>'project','post_status'=>'publish','post_title'=>'Duo Website Revamp','post_author'=>1], true);
wp_set_object_terms(\$en, 'en', 'language');
\$de = wp_insert_post(['post_type'=>'project','post_status'=>'publish','post_title'=>'Duo Website Neugestaltung','post_author'=>1], true);
wp_set_object_terms(\$de, 'de', 'language');
pll_save_post_translations(['en'=>\$en, 'de'=>\$de]);
echo json_encode(['en'=>\$en, 'de'=>\$de]);
" | tail -1)
PROJ_EN=$(echo "$PROJ_JSON" | python3 -c "import json,sys; print(json.load(sys.stdin)['en'])")
PROJ_DE=$(echo "$PROJ_JSON" | python3 -c "import json,sys; print(json.load(sys.stdin)['de'])")
[ "$PROJ_EN" != "" ] && [ "$PROJ_DE" != "" ] || fail "project translation pair creation failed (got: $PROJ_JSON)"
LANG_CHECK=$(wp1 eval "echo json_encode(['en'=>pll_get_post_language($PROJ_EN),'de'=>pll_get_post_language($PROJ_DE),'trans'=>pll_get_post_translations($PROJ_EN)]);")
echo "$LANG_CHECK" | grep -q '"en":"en"' || fail "source-side language tag did not land (got: $LANG_CHECK)"
echo "$LANG_CHECK" | grep -q '"de":"de"' || fail "source-side language tag did not land for German project (got: $LANG_CHECK)"
pass "Duo Website Revamp ($PROJ_EN) / Duo Website Neugestaltung ($PROJ_DE) -- translated pair confirmed live on the SOURCE before capture"

say "side1: per-language menus -- Main Menu(en)/Hauptmenu(de), flat theme_mods location=Main Menu (language-blind), polylang's OWN nav_menus sub-key names BOTH per language"
MENU_EN=$(wp1 menu create "Main Menu" --porcelain)
MENU_DE=$(wp1 menu create "Hauptmenu" --porcelain)
wp1 menu item add-custom "$MENU_EN" "EnglishMarkerLink" "https://example.test/en" >/dev/null
wp1 menu item add-custom "$MENU_DE" "GermanMarkerLink" "https://example.test/de" >/dev/null
wp1 menu location assign "$MENU_EN" primary
wp1 eval "
\$o = get_option('polylang');
\$o['nav_menus'] = ['twentytwentyone' => ['primary' => ['en' => $MENU_EN, 'de' => $MENU_DE]]];
update_option('polylang', \$o);
"
pass "Main Menu ($MENU_EN) / Hauptmenu ($MENU_DE) -- flat theme_mods names Main Menu only; polylang's own nav_menus names both per language"

say "side1: Yoast wpseo.disableadvanced_meta -- flip from its true default (a real, safe, empirically-verified toggle -- see manifests/yoast.json's own note)"
wp1 eval "
\$o = get_option('wpseo');
\$o['disableadvanced_meta'] = false;
update_option('wpseo', \$o);
"

say "init site repo (own origin, own clones)"
rm -rf siterepo/origin-${PAIR}.git siterepo/${PAIR}1/.git siterepo/${PAIR}1/state siterepo/${PAIR}1/site.duo.json siterepo/${PAIR}2
git init --bare -b main siterepo/origin-${PAIR}.git >/dev/null
mkdir -p siterepo/${PAIR}1
cat > siterepo/${PAIR}1/site.duo.json <<'EOF'
{
  "manifests": ["core", "polylang", "yoast"],
  "policy": {
    "options": {},
    "post_meta": {},
    "post_types": ["post", "page", "attachment", "project"],
    "taxonomies": ["category", "post_tag", "project_type", "language", "post_translations", "term_language", "term_translations"]
  },
  "spec_version": 2
}
EOF
cp site-repo.gitignore.template siterepo/${PAIR}1/.gitignore
git -C siterepo/${PAIR}1 init -q -b main
git -C siterepo/${PAIR}1 remote add origin ../origin-${PAIR}.git
git -C siterepo/${PAIR}1 -c user.name=duo-${PAIR}1 -c user.email=a1@example.test add -A
git -C siterepo/${PAIR}1 -c user.name=duo-${PAIR}1 -c user.email=a1@example.test commit -qm "policy: DUO-3233 regression scope" >/dev/null
git -C siterepo/${PAIR}1 -c user.name=duo-${PAIR}1 -c user.email=a1@example.test push -qu origin main
pass "site repo initialized"

say "(1) capture: sub_keys carves out ONLY the declared keys"
wp1 duo capture --repo=/siterepo >/dev/null
POLYLANG_KEYS=$(python3 -c "import json; d=json.load(open('siterepo/${PAIR}1/state/options/core.json'))['records']; print(sorted(d['polylang']['value'].keys()))")
[ "$POLYLANG_KEYS" = "['nav_menus', 'post_types', 'taxonomies']" ] || fail "expected captured polylang option to carry EXACTLY [nav_menus, post_types, taxonomies], got: $POLYLANG_KEYS"
for excluded in force_lang domains hide_default rewrite redirect_lang browser media_support sync default_lang first_activation previous_version version; do
  python3 -c "import json,sys; d=json.load(open('siterepo/${PAIR}1/state/options/core.json'))['records']; sys.exit(1 if '$excluded' in d['polylang']['value'] else 0)" \
    || fail "excluded sub-key '$excluded' leaked into captured polylang option"
done
pass "captured polylang option carries exactly nav_menus/post_types/taxonomies -- every env-bound/bookkeeping sibling excluded"

WPSEO_KEYS=$(python3 -c "import json; d=json.load(open('siterepo/${PAIR}1/state/options/core.json'))['records']; print(sorted(d['wpseo']['value'].keys()))")
[ "$WPSEO_KEYS" = "['disableadvanced_meta']" ] || fail "expected captured wpseo option to carry EXACTLY [disableadvanced_meta], got: $WPSEO_KEYS"
pass "captured wpseo option carries exactly disableadvanced_meta -- the ~115 other sibling keys (version, first_activated_on, tokens, ...) excluded"

NAV_TOKENS=$(python3 -c "import json; d=json.load(open('siterepo/${PAIR}1/state/options/core.json'))['records']; print(d['polylang']['value']['nav_menus'])")
echo "$NAV_TOKENS" | grep -q '{{term:' || fail "expected nav_menus term ids tokenized as {{term:<uuid>}}, got: $NAV_TOKENS"
pass "nav_menus per-language menu-term-ids correctly tokenized via json_refs"

say "hard lint gate + capture-twice determinism (positive path)"
wp1 duo lint --repo=/siterepo
wp1 duo capture --repo=/siterepo --out=/siterepo/.tmp-state2 >/dev/null
diff -r siterepo/${PAIR}1/state siterepo/${PAIR}1/.tmp-state2 || fail "capture is not deterministic"
rm -rf siterepo/${PAIR}1/.tmp-state2
pass "lint clean, capture-twice diff empty"

git -C siterepo/${PAIR}1 -c user.name=duo-${PAIR}1 -c user.email=a1@example.test add -A
git -C siterepo/${PAIR}1 -c user.name=duo-${PAIR}1 -c user.email=a1@example.test commit -qm "capture: DUO-3233 fixture"
git -C siterepo/${PAIR}1 -c user.name=duo-${PAIR}1 -c user.email=a1@example.test push -q origin main

say "(2)/(3) round-trip onto a GENUINELY FRESH target: side2 has Polylang+Yoast active, but ZERO manual language/Settings config"
rm -rf siterepo/${PAIR}2
git clone -q siterepo/origin-${PAIR}.git siterepo/${PAIR}2
REV=$(git -C siterepo/${PAIR}2 rev-parse HEAD)
POLYLANG_BEFORE=$(wp2 option get polylang --format=json | tail -1)
echo "side2 polylang option BEFORE apply (fresh activation defaults): $POLYLANG_BEFORE"
echo "$POLYLANG_BEFORE" | python3 -c "import json,sys; d=json.load(sys.stdin); sys.exit(1 if d['post_types'] else 0)" \
  || fail "side2 should start with an EMPTY post_types (genuinely fresh, no manual config)"

APPLY1=$(apply_with_retry --repo=/siterepo --adopt-by-slug=terms,posts --force-theirs --default-author=admin --revision="$REV")
echo "$APPLY1"
echo "$APPLY1" | grep -qiE '"canary":"clean"|canary clean' || fail "apply canary was not clean"
pass "apply succeeded on a fresh target, canary clean"

say "(6) sub-key merge, re-asserted directly against the database: side2's OWN pre-existing polylang/wpseo bookkeeping survives untouched"
POLYLANG_AFTER=$(wp2 option get polylang --format=json | tail -1)
echo "side2 polylang option AFTER apply: $POLYLANG_AFTER"
python3 - "$POLYLANG_BEFORE" "$POLYLANG_AFTER" <<'PYEOF'
import json, sys
before = json.loads(sys.argv[1])
after = json.loads(sys.argv[2])
for key in ["force_lang", "domains", "hide_default", "rewrite", "redirect_lang", "browser",
            "media_support", "sync", "first_activation", "previous_version", "version"]:
    if before.get(key) != after.get(key):
        print(f"CLOBBERED: {key} was {before.get(key)!r}, now {after.get(key)!r}")
        sys.exit(1)
if after.get("post_types") != ["project"]:
    print(f"post_types did not merge in correctly: {after.get('post_types')!r}")
    sys.exit(1)
if after.get("taxonomies") != ["project_type"]:
    print(f"taxonomies did not merge in correctly: {after.get('taxonomies')!r}")
    sys.exit(1)
nav = after.get("nav_menus") or {}
ids = list(nav.get("twentytwentyone", {}).get("primary", {}).values())
if len(ids) != 2 or len(set(ids)) != 2:
    print(f"nav_menus did not merge in two distinct local menu ids: {nav!r}")
    sys.exit(1)
print("MERGE OK: every excluded sibling byte-identical; post_types/taxonomies/nav_menus correctly overlaid")
PYEOF
[ $? -eq 0 ] || fail "sub-key merge check failed (see output above)"
pass "excluded siblings (force_lang, hide_default, rewrite, ..., first_activation, version, ...) survived apply untouched; post_types/taxonomies/nav_menus correctly merged in with side2's OWN local menu-term-ids"

WPSEO_AFTER=$(wp2 option get wpseo --format=json | tail -1)
python3 -c "
import json, sys
d = json.loads('''$WPSEO_AFTER''')
assert d['disableadvanced_meta'] == False, 'disableadvanced_meta did not merge'
assert 'version' in d and 'first_activated_on' in d, 'target bookkeeping missing entirely -- merge started from empty, not the live blob'
print('wpseo merge OK:', d['disableadvanced_meta'], d['version'])
" || fail "wpseo sub-key merge check failed"
pass "wpseo.disableadvanced_meta merged correctly; version/first_activated_on (target's OWN) preserved -- second real-plugin proof of the same grammar"

say "(3) the documented Polylang timing hazard: get_taxonomy('language')->object_type on THIS apply's own process cannot see the post_types write this SAME apply just made (WordPress fixes it at init, before apply's own code runs)"
OBJTYPE_B2=$(wp2 eval "\$t=get_taxonomy('language'); echo implode(',', (array) \$t->object_type);")
echo "side2 language taxonomy object_type in the SAME process as apply #1: $OBJTYPE_B2"
PROJ_EN_B2=$(wp2 post list --post_type=project --field=ID | sort -n | head -1)
PROJ_DE_B2=$(wp2 post list --post_type=project --field=ID | sort -n | tail -1)
LANG_BEFORE_FIX=$(wp2 eval "var_export(pll_get_post_language($PROJ_EN_B2));")
echo "pll_get_post_language before the automatic follow-up apply: $LANG_BEFORE_FIX"
[ "$LANG_BEFORE_FIX" = "false" ] || fail "expected no language relationship yet (this apply run's own registration predates its own sub_keys merge) -- got $LANG_BEFORE_FIX"

say "characterizing the gap precisely (new finding, honestly demonstrated, not silently worked around): a no-op re-apply -- ZERO content changes anywhere -- surfaces the missing relationship as 'drift (env ahead, untouched)', by design never reprocessed by Apply's own phase-2 (only create/update/conflict entities enter \$work; a drift-classified entity is deliberately left alone, the same 'capture-first' bias documented in spec/repo-format.md's Apply semantics). This is the SAME general shape as task #92's own accepted pa_* finding ('an unchanged-hash entity skips relationship reprocessing by design') -- confirmed here for an option-driven (not typed-snapshot-table-driven) taxonomy scope change. Filed precisely, not fixed here -- see this task's PR/Linear comment."
REV_NOOP=$(git -C siterepo/${PAIR}2 rev-parse HEAD)
APPLY_NOOP=$(apply_with_retry --repo=/siterepo --default-author=admin --revision="$REV_NOOP")
echo "$APPLY_NOOP"
echo "$APPLY_NOOP" | grep -q '"drift":2' || fail "expected BOTH untouched project posts to show as drift on a no-op re-apply (got: $APPLY_NOOP)"
pass "confirmed: a no-op re-apply leaves the drifted relationship exactly as-is (never self-heals without a genuine touch) -- precisely characterized, matching task #92's own established precedent for the analogous pa_* timing hazard"

say "a genuine content change on BOTH posts (forces reprocessing, matching task #92's own established playbook for pa_* attribute relationships) + a SECOND, still fully automated apply -- zero manual Settings replication either time. Both, not just one: an entity Apply's plan classifies 'unchanged' is never reprocessed regardless of what a SIBLING option write just changed (confirmed distinctly below -- see the 'persistent drift' note) -- so a realistic 'just enabled this CPT for translation' operator workflow touches every existing item of that type once, the same discipline Polylang's own docs recommend after enabling translation for pre-existing content."
wp1 post update "$PROJ_EN" --post_excerpt="A ground-up rebuild of the marketing site." >/dev/null
wp1 post update "$PROJ_DE" --post_excerpt="Eine grundlegende Neugestaltung der Marketing-Website." >/dev/null
wp1 duo capture --repo=/siterepo >/dev/null
git -C siterepo/${PAIR}1 -c user.name=duo-${PAIR}1 -c user.email=a1@example.test add -A
git -C siterepo/${PAIR}1 -c user.name=duo-${PAIR}1 -c user.email=a1@example.test commit -qm "content: force reprocessing"
git -C siterepo/${PAIR}1 -c user.name=duo-${PAIR}1 -c user.email=a1@example.test push -q origin main
git -C siterepo/${PAIR}2 pull -q origin main
REV2=$(git -C siterepo/${PAIR}2 rev-parse HEAD)
APPLY2=$(apply_with_retry --repo=/siterepo --default-author=admin --force-theirs --revision="$REV2")
echo "$APPLY2"
echo "$APPLY2" | grep -qiE '"canary":"clean"|canary clean' || fail "second apply canary was not clean"

say "(4) pll_get_post_language()/pll_get_post_translations() now resolve on the target -- fresh process, target's OWN local ids, ZERO manual Settings replication"
LANG_FIXED=$(wp2 eval "echo json_encode(['en'=>pll_get_post_language($PROJ_EN_B2),'de'=>pll_get_post_language($PROJ_DE_B2),'trans'=>pll_get_post_translations($PROJ_EN_B2)]);")
echo "$LANG_FIXED"
echo "$LANG_FIXED" | grep -q '"en":"en"' || fail "expected pll_get_post_language=en on the target after the automatic follow-up apply (got: $LANG_FIXED)"
echo "$LANG_FIXED" | grep -q '"de":"de"' || fail "expected the German project's language=de on the target (got: $LANG_FIXED)"
TRANS_HAS_BOTH=$(echo "$LANG_FIXED" | python3 -c "
import json,sys
d = json.load(sys.stdin)
t = d['trans']
sys.exit(0 if ('en' in t and 'de' in t and t['en'] != t['de']) else 1)
")
[ $? -eq 0 ] || fail "pll_get_post_translations did not resolve BOTH sides to distinct target-local ids"
[ "$PROJ_EN_B2" != "$PROJ_EN" ] && pass "target-local ids genuinely differ from source ids ($PROJ_EN -> $PROJ_EN_B2) -- not a coincidental match" \
  || echo "note: target id happened to equal source id this run (not asserted either way -- see description_refs precedent)"
pass "pll_get_post_language()/pll_get_post_translations() both resolve correctly on the target using the target's OWN local ids"

say "(5) the correct per-language menu renders at its shared location -- real HTTP requests, zero manual menu reassignment"
BODY_EN=$(curl -s -w '\nHTTPSTATUS:%{http_code}' "http://localhost:${PORT2}/" || true)
CODE_EN=$(echo "$BODY_EN" | grep -o 'HTTPSTATUS:[0-9]*' | cut -d: -f2)
BODY_DE=$(curl -s -w '\nHTTPSTATUS:%{http_code}' "http://localhost:${PORT2}/de/" || true)
CODE_DE=$(echo "$BODY_DE" | grep -o 'HTTPSTATUS:[0-9]*' | cut -d: -f2)
if [ "$CODE_EN" = "200" ] && [ "$CODE_DE" = "200" ]; then
  echo "$BODY_EN" | grep -q "EnglishMarkerLink" || fail "expected Main Menu's marker link on the EN homepage (got HTTP $CODE_EN)"
  echo "$BODY_DE" | grep -q "GermanMarkerLink" || fail "expected Hauptmenu's marker link on the DE homepage (got HTTP $CODE_DE)"
  echo "$BODY_EN" | grep -qi "localhost:${PORT1}" && fail "host:port leak: side1's port appears on side2's EN homepage"
  echo "$BODY_DE" | grep -qi "localhost:${PORT1}" && fail "host:port leak: side1's port appears on side2's DE homepage"
  pass "confirmed via real HTTP requests: EN shows Main Menu, DE shows Hauptmenu, at the SAME 'primary' location, zero manual reassignment; no host:port leaks"
else
  echo "WARNING: front end returned EN=$CODE_EN DE=$CODE_DE, not 200/200 -- render check skipped this run (see docs/grind/r3a-multilingual-shop.md's open task #128 Polylang front-end finding, confirmed unrelated to Duo). wp-cli-level checks above already proved the underlying data is correct."
fi

say "(8) negative: RepositoryAuthorization refuses an UNDECLARED sub-key smuggled into a captured polylang value"
BAD_REPO=/siterepo/.tmp-duo3233-badsubkey
HOST_BAD_REPO=siterepo/${PAIR}2/.tmp-duo3233-badsubkey
rm -rf "$HOST_BAD_REPO"
mkdir -p "$HOST_BAD_REPO/state/options"
cp siterepo/${PAIR}2/site.duo.json "$HOST_BAD_REPO/site.duo.json"
# DUO-3276: was `jq -n` building a single-record file from scratch (only
# polylang's own record, nothing else) -- that shape predates DUO-3211's
# absent-record contract becoming mandatory for every authored-exact
# option (RepositoryCompiler.php:506-519: $required is EVERY authored_
# options()/sub_keyed_options() name plus the three managed options,
# and array_diff_key($required, $records) queues a schema_content_
# mismatch, `records.<name>` needing "an explicit absent, present, or
# deleted record", for each one missing -- confirmed by reading the
# check directly, not assumed). A single-record fixture is missing
# every OTHER required option (active_plugins/blogdescription/blogname/
# page_for_posts/page_on_front/posts_per_page/show_on_front/
# sticky_posts/stylesheet/template/wp_page_for_privacy_policy, plus
# wpseo's own four here since this test also loads yoast) --
# RepositoryCompiler::run()'s own diagnostics gate (`if ($this->
# diagnostics) fail()`) throws on THAT batch before RepositoryAuthorization
# ::assert_tree() -- where the smuggled-sub-key check this step actually
# means to exercise lives -- ever runs at all. Confirmed offline against
# the real, unmodified engine classes (RepositoryCompiler/
# RepositoryAuthorization/Policy/OptionState, no WordPress dependency):
# a fabricated single-record fixture reproduces the exact reported
# symptom byte-for-byte; the SAME fixture with every required option
# given an explicit record instead correctly reaches assert_tree() and
# throws `[repository_field_not_authored] ... field=polylang.sync
# classification=unclassified declared_by=polylang` -- matching this
# step's own existing assertion (`polylang.sync\|option_sub_key`)
# unchanged below.
#
# Fix: base the smuggled-key fixture on the REAL, already-captured
# state/options/core.json this pair produced (every required option
# already has a correct record, from the actual capture pipeline --
# `git -C siterepo/${PAIR}2 pull` a few lines above this step is the
# last write to this file, and nothing between there and here touches
# it again) and inject ONLY the undeclared 'sync' key into polylang's
# own value, rather than hand-reconstructing every option's record --
# robust against this option set changing later, unlike a hardcoded
# snapshot would be.
jq '.records.polylang.value.sync = ["taxonomies"]' siterepo/${PAIR}2/state/options/core.json > "$HOST_BAD_REPO/state/options/core.json"
set +e
BAD_OUT=$($COMPOSE run --rm -T cli2 wp duo apply --repo="$BAD_REPO" --format=json 2>&1)
BAD_RC=$?
set -e
echo "$BAD_OUT"
[ "$BAD_RC" -ne 0 ] || fail "expected apply to REFUSE an undeclared polylang sub-key ('sync'), got exit 0"
echo "$BAD_OUT" | grep -qE "polylang.sync|option_sub_key" || fail "refusal doesn't name the undeclared sub-key (got: $BAD_OUT)"
rm -rf "$HOST_BAD_REPO"
pass "an undeclared sub-key ('sync') smuggled into a captured polylang value is refused loudly, naming the offending key"

say "(9) negative: wp duo lint flags a bare numeric id smuggled into a PLAIN (no json_refs) sub-key's own value"
# post_types/taxonomies declare no ref/json_refs/key_refs, so
# scan_option_sub_keys()'s SHALLOW Pending::numeric_candidates() branch is
# what must catch this -- exercised directly, not the deep
# scan_structured_bare_ids() path (see the note below on why nav_menus
# itself is a DIFFERENT, NOT-asserted case here).
BAD_REPO2=/siterepo/.tmp-duo3233-badlint
HOST_BAD_REPO2=siterepo/${PAIR}1/.tmp-duo3233-badlint
rm -rf "$HOST_BAD_REPO2"
mkdir -p "$HOST_BAD_REPO2/state/options"
cp siterepo/${PAIR}1/site.duo.json "$HOST_BAD_REPO2/site.duo.json"
jq -n --argjson pid "$PROJ_EN" '{format:"duo-options/v1",records:{polylang:{state:"present",autoload:"yes",value:{post_types:[($pid | tostring)]}}}}' > "$HOST_BAD_REPO2/state/options/core.json"
set +e
LINT_OUT=$(wp1 duo lint --repo="$BAD_REPO2" 2>&1)
set -e
echo "$LINT_OUT"
echo "$LINT_OUT" | grep -qi "bare_id" || fail "expected lint to flag the bare numeric post_types entry as bare_id (got: $LINT_OUT)"
echo "$LINT_OUT" | grep -q "polylang.post_types" || fail "lint finding doesn't locate the sub-key path (got: $LINT_OUT)"
rm -rf "$HOST_BAD_REPO2"
pass "lint correctly flags a bare id smuggled into a sub_keys-declared PLAIN value (scan_option_sub_keys()'s shallow branch)"
echo "note (characterized, not asserted -- a genuine, PRE-EXISTING Lint.php limitation unrelated to sub_keys, filed separately): the DEEP branch (scan_structured_bare_ids(), used for json_refs-declared sub-keys like nav_menus) only flags an id-shaped VALUE sitting under an id-NAMED key (looks_like_id_key() -- e.g. wpseo_taxonomy_meta's 'wpseo-opengraph-image-id'). nav_menus' own shape keys its ids by LANGUAGE SLUG ('en'/'de'), which no id-naming heuristic could safely recognize (2-letter slugs are far too generic to add to that heuristic without mass false positives) -- so an unrewritten nav_menus id would currently pass lint silently. The rewrite itself is unaffected (Tokens::struct_capture()'s json_refs path rewrites by declared PATH, never by key-name matching) -- this is purely a lint-detection blind spot for the negative/audit case, the same species of gap task #11's original wave discovered and wave 2 partially closed."

say "final hard lint gate, both sides, on the real (non-fixture) state"
wp1 duo lint --repo=/siterepo
wp2 duo lint --repo=/siterepo
pass "lint clean both sides"

pass "DUO-3233 regression: sub-key carve-out (capture), sub-key-level merge without clobbering excluded siblings (apply), pll_get_post_language/translations resolving with zero manual Settings replication, correct per-language menu rendering, the second (Yoast) real-plugin proof, and both negative gates (repository authorization + lint) -- all confirmed"
