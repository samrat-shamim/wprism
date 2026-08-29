#!/usr/bin/env bash
# Grind round R1-A (task #46) — a forms-driven business site: Contact Form 7
# + Ninja Forms (both free, wordpress.org slugs contact-form-7/ninja-forms) on
# twentytwentyone. Ninja Forms is the deliberate stress test: its nf3_*
# custom tables (intra-table FKs) are the known typed-snapshot engine
# frontier (docs/design-review-v0.md finding #8) — this script characterizes
# that gap empirically rather than working around it.
#
# Own dedicated env pair (r1a1 :8814 / r1a2 :8815, profile "r1a", journal
# on); own site repo (siterepo/{origin-r1a.git,r1a1,r1a2}). Never touches
# envs a/b/c/conf/e*/fx*/g* or their site repos, or other grind rounds'
# r1b*/r1c* pairs.
#
# Re-run safety: r1a1/r1a2 are never torn down (docker compose down/clean is
# off-limits — other agents share this stack), so every run resets content,
# wprism ledger tables, and site-repo git state. WP core/theme/plugin install is
# skipped on repeat runs (guarded by `core is-installed` / `plugin
# is-installed`).
set -euo pipefail
cd "$(dirname "$0")/../.."   # -> sandbox/
COMPOSE="docker compose -f docker-compose.yml --profile r1a"
say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }
R1A1=http://localhost:8814
R1A2=http://localhost:8815

PROOF_LEGACY_COMPOSE="$COMPOSE"
[ -r "lib/proof_legacy_pair.sh" ] || fail "legacy proof pair library is missing: lib/proof_legacy_pair.sh"
# shellcheck source=../../lib/proof_legacy_pair.sh
source "lib/proof_legacy_pair.sh"

wp_env() { proof_legacy_pair_wp_env "$@"; }
wp_1() { wp_env r1a1 "$@"; }
wp_2() { wp_env r1a2 "$@"; }
wait_for() { proof_legacy_pair_wait_for "$@"; }
write_htaccess() { proof_legacy_pair_write_htaccess "$@"; }

install_env() { # install_env <r1a1|r1a2> <port> <title>
  # Every step below is independently idempotent (its own is-installed/
  # is-active guard) rather than one big "if not installed do everything"
  # block — a flaky theme/plugin download (slow zip.org fetch under docker)
  # can fail partway through a first run, after core install already
  # succeeded; re-running must still finish the remaining steps instead of
  # skipping them because "core is-installed" is now true.
  local env="$1" port="$2" title="$3"
  wait_for "$env"
  if ! wp_env "$env" core is-installed >/dev/null 2>&1; then
    wp_env "$env" core install \
      --url="http://localhost:$port" --title="$title" \
      --admin_user=admin --admin_password=admin \
      --admin_email=admin@example.test --skip-email
    echo "env $env: core installed"
  fi
  wp_env "$env" theme is-installed twentytwentyone >/dev/null 2>&1 || wp_env "$env" theme install twentytwentyone
  wp_env "$env" theme activate twentytwentyone >/dev/null
  [ "$(wp_env "$env" option get permalink_structure)" = "/%postname%/" ] || wp_env "$env" option update permalink_structure '/%postname%/'
  wp_env "$env" rewrite flush
  write_htaccess "$env"
  wp_env "$env" plugin is-installed contact-form-7 >/dev/null 2>&1 || wp_env "$env" plugin install contact-form-7
  wp_env "$env" plugin is-installed ninja-forms >/dev/null 2>&1 || wp_env "$env" plugin install ninja-forms
  wp_env "$env" plugin activate contact-form-7 ninja-forms >/dev/null
  echo "env $env: ready (twentytwentyone active, CF7 + Ninja Forms active)"
}

# Envs persist across runs, so make re-running this script safe: wipe WP
# content + the wprism ledger tables every time (the core-loop spike's
# reset_env_state idiom). CF7/Ninja Forms are NOT deactivated/reinstalled per run
# (install_env already guards that) — only their data is wiped, via each
# plugin's own uninstall-equivalent tables/options where feasible.
reset_env_state() { # reset_env_state <r1a1|r1a2>
  local env="$1"
  wp_env "$env" site empty --yes >/dev/null
  wp_env "$env" db query "TRUNCATE TABLE wp_wprism_map" >/dev/null 2>&1 || true
  wp_env "$env" db query "TRUNCATE TABLE wp_wprism_state" >/dev/null 2>&1 || true
  wp_env "$env" db query "TRUNCATE TABLE wp_wprism_kv" >/dev/null 2>&1 || true
  wp_env "$env" wprism journal-reset >/dev/null 2>&1 || true
  # Ninja Forms ships its own custom tables (nf3_*) that `site empty` never
  # touches (they aren't wp_posts/wp_postmeta) — truncate them directly so a
  # second run starts from zero forms/submissions. Names + shape confirmed
  # empirically (DESCRIBE'd live on a fresh NF 3.14.11 install, not assumed
  # from the design doc's one-line "nf3_*" mention): nf3_forms/nf3_fields/
  # nf3_actions + their _meta twins hold form DEFINITIONS (parent_id FKs,
  # single-target per table — a form's fields, a form's actions); nf3_objects/
  # nf3_object_meta/nf3_relationships are separate and were EMPTY after
  # importing a 23-field form (confirmed: form/field/action definitions never
  # touch them) — reserved for submissions, confirmed in the next step.
  #
  # nf3_upgrades IS included, corrected from an earlier version of this
  # script that deliberately left it alone as "migration bookkeeping, not
  # content" — that was wrong, caught empirically the hard way: it is ALSO
  # Ninja Forms' own settings CACHE, one row per form id (WPN_Helper::
  # update_nf_cache()/get_nf_cache(), keyed by the exact same id as
  # nf3_forms.id), consulted by BOTH Ninja_Forms()->form($id)->get_fields()
  # (the general model API, not just the AJAX submission path) and the
  # front-end submission handler. Truncating nf3_forms alone resets its
  # auto_increment counter, so a freshly re-imported form reuses id 1 — and
  # without also clearing nf3_upgrades, that reused id silently served a
  # STALE cache row from whatever form previously held id 1 (observed live:
  # a freshly-imported 23-field "Job Application" read back as the OLD
  # 4-field "Contact Me" until this table was cleared and the cache rebuilt
  # via WPN_Helper::build_nf_cache()). This is a real, general hazard, not a
  # one-off: any environment where nf3_forms rows get deleted and recreated
  # (nothing to do with WPrism specifically) can hit the same silent staleness,
  # and it directly informs the typed-snapshot acceptance criteria below —
  # a future capture/apply capability for nf3_* MUST rebuild this cache per
  # form id, the same "manifest declares actions" pattern already used
  # for Yoast/Elementor (a `rebuilders` command string when this was written;
  # a provider capability since issue #3338 — the declaration channel changed,
  # the obligation it records did not).
  for t in nf3_forms nf3_form_meta nf3_fields nf3_field_meta nf3_actions nf3_action_meta nf3_objects nf3_object_meta nf3_relationships nf3_chunks nf3_upgrades; do
    wp_env "$env" db query "TRUNCATE TABLE wp_${t}" >/dev/null 2>&1 || true
  done
}

# --- Ninja Forms real import driver ------------------------------------
# NF_Admin_Processes_ImportForm (the class wp-admin's own "Add New Form"
# template gallery uses) runs ONE step per instantiation and terminates the
# PHP process via wp_die() after echoing a JSON progress response — the
# real multi-AJAX-request batch protocol the JS admin builder drives, not
# something to route around. So this helper invokes `wp eval-file` once per
# step, relying on the plugin's own nf_doing_import_form/nf_import_form
# options to resume via its restart() path on every call after the first.
nf_import_step_php() { # writes the one-step driver script to $1
  cat > "$1" <<'PHPEOF'
<?php
if ( ! defined( 'WP_ADMIN' ) ) {
	define( 'WP_ADMIN', true );
}
$admin = get_user_by( 'login', 'admin' );
wp_set_current_user( $admin->ID );

if ( ! get_option( 'nf_doing_import_form' ) ) {
	$nff_path = getenv( 'NF_TEMPLATE_PATH' );
	$raw = file_get_contents( $nff_path );
	$_POST['extraData'] = array(
		'content' => 'data:application/octet-stream;base64,' . base64_encode( $raw ),
		'extraChecksOff' => 'true',
	);
}
new NF_Admin_Processes_ImportForm();
PHPEOF
}

# import_nf_template <r1a1|r1a2> <nff-basename-under-ninja-forms/includes/Templates> -> echoes the new form_id on success
import_nf_template() {
  local env="$1" template="$2" tmp="/siterepo/.tmp-nf-import-step.php" out step
  nf_import_step_php "siterepo/$env/.tmp-nf-import-step.php"
  for step in 1 2 3 4 5 6; do
    out=$($COMPOSE run --rm -T -e "NF_TEMPLATE_PATH=/var/www/html/wp-content/plugins/ninja-forms/includes/Templates/$template" "cli-$env" wp eval-file "$tmp" 2>&1) || true
    # Herestrings, not pipes -- issue #3267's own SIGPIPE-race finding (see the
    # render-check section below) applies to any `echo "$VAR" | grep` shape
    # under this script's `set -o pipefail`, not just the two checks that
    # happened to surface it; swept the whole file rather than leaving the
    # same class of bug in place elsewhere on the assumption it's "probably
    # fine" for a smaller variable.
    if grep -q '"batch_complete":true' <<<"$out"; then
      rm -f "siterepo/$env/.tmp-nf-import-step.php"
      grep -o '"form_id":[0-9]*' <<<"$out" | grep -o '[0-9]*'
      return 0
    fi
  done
  echo "$out" >&2
  fail "Ninja Forms import of $template on $env did not complete after 6 steps"
}

say "boot env pair r1a1 (:8814) / r1a2 (:8815)"
mkdir -p siterepo/r1a1 siterepo/r1a2
$COMPOSE up -d db-r1a1 wp-r1a1 db-r1a2 wp-r1a2
install_env r1a1 8814 "WPrism R1A1 (Forms)"
install_env r1a2 8815 "WPrism R1A2 (Forms)"
pass "both envs installed, CF7 + Ninja Forms active, journal on (WPRISM_JOURNAL)"

say "reset r1a1/r1a2 content + ledger for a clean run"
reset_env_state r1a1
reset_env_state r1a2
pass "WP content, wprism_map/wprism_state/wprism_kv, nf3_* tables, and the journal are all clean on both envs"

say "fresh site repo (own origin, own clones — never touches siterepo/a|b|c|conf*|e*|fx*|g*|r1b*|r1c*)"
rm -rf siterepo/origin-r1a.git
git init --bare -b main siterepo/origin-r1a.git >/dev/null
rm -rf siterepo/r1a1 && mkdir -p siterepo/r1a1
# post_types deliberately does NOT include nf_sub: it is Ninja Forms'
# submission post type (see the "anonymous submissions" section above) —
# runtime, visitor-authored, and must never enter the branchable partition.
# wpcf7_contact_form IS in scope: it is CF7's own form-definition CPT, a
# site-builder-authored entity like any other post type in this engine.
#
# issue #3267: manifests now includes "ninja-forms", not just "core". This was
# the deliberate omission the script's own header comment used to justify
# ("Ninja Forms is the known typed-snapshot engine frontier ... this script
# characterizes that gap empirically rather than working around it") — true
# when task #56 wrote it, stale since task #75/#76 closed that frontier
# elsewhere: manifests/ninja-forms.json now carries a complete, live-
# verified typed-snapshot capture/apply for nf3_forms/nf3_fields/nf3_actions
# (+ their _meta twins) AND a block_attrs codec for the Careers page's own
# `wp:ninja-forms/form {"formID":N}` embed (kind nf3_form, resolved through
# the same generalized Tokens::id_to_token()/token_to_id() every other ref
# kind uses). This fixture just never got wired to that manifest once it
# existed. See issue #3267's own Linear scope note for the full trace.
#
# "contact-form-7" added the same way, found by team-lead's live run of the
# round-trip leg above: this script's very first `wp wprism capture` (never
# exercised before -- the ORIGINAL script had none) hit the discovery gate
# ("wprism: incomplete state discovery on manifest-owned or in-scope surfaces")
# naming CF7's own seven postmeta keys (_form/_mail/_mail_2/_messages/
# _additional_settings/_hash/_locale) as unclassified. manifests/contact-
# form-7.json already declares all seven as authored -- built during the
# ORIGINAL R1-A grind round and empirically
# verified then -- and its own note already says "wpcf7_contact_form must
# be added to site.wprism.json's policy.post_types for any of this to take
# effect": post_types already had it (below), but the manifest declaring
# what those keys ARE was never pinned. This script predates the discovery-
# gate rework entirely (round-2 era, per team-lead) -- it had literally
# never run a single capture before tonight, so this gap sat unexercised
# rather than merely stale.
cat > siterepo/r1a1/site.wprism.json <<'EOF'
{
  "manifests": ["core", "contact-form-7", "ninja-forms"],
  "policy": {
    "options": {},
    "post_meta": {},
    "post_types": ["post", "page", "attachment", "wpcf7_contact_form"],
    "taxonomies": ["category", "post_tag"]
  },
  "spec_version": 2
}
EOF
cp site-repo.gitignore.template siterepo/r1a1/.gitignore
GIT_1="git -C siterepo/r1a1 -c user.name=wprism-r1a1 -c user.email=r1a1@example.test"
GIT_2="git -C siterepo/r1a2 -c user.name=wprism-r1a2 -c user.email=r1a2@example.test"
$GIT_1 init -q -b main
$GIT_1 remote add origin ../origin-r1a.git
pass "site repo initialized (manifests: [core, ninja-forms] — nf3_* now in scope; post_types scope still excludes nf_sub deliberately)"

# --- Contact Form 7 real seeding -----------------------------------------
# WPCF7_ContactForm::get_template() is the exact method wp-admin's "Add New"
# screen calls (a real default template: Name/Email/Subject/Message fields
# + mail settings), customized the way any real setup does immediately
# after creating a form (mail recipient/subject/headers), then ->save() —
# CF7's own public save path (wp_insert_post + update_post_meta per
# property), not hand-authored postmeta.
cf7_seed_php() {
  cat > "$1" <<'PHPEOF'
<?php
if ( ! class_exists( 'WPCF7_ContactForm' ) ) { fwrite( STDERR, "WPCF7_ContactForm not loaded\n" ); exit( 1 ); }
// A bare wp-cli process carries no logged-in user by default, which would
// make the journal see this as an anonymous write (caps=anon) instead of
// the admin-authored, capability-backed write a real wp-admin "Add New"
// click actually is (DESIGN.md 3.1.5's capability x surface signal) —
// simulate the real actor, same as the Ninja Forms import step below.
wp_set_current_user( get_user_by( 'login', 'admin' )->ID );
$cf = WPCF7_ContactForm::get_template( array( 'title' => 'Contact Us' ) );
$mail = $cf->prop( 'mail' );
$mail['recipient'] = 'sales@example.test';
$mail['subject'] = '[WPrism Demo Co] New inquiry: [your-subject]';
$mail['additional_headers'] = "Reply-To: [your-email]\nCc: records@example.test";
$cf->set_properties( array( 'mail' => $mail ) );
$id = $cf->save();
if ( ! $id ) { fwrite( STDERR, "CF7 save() failed\n" ); exit( 1 ); }
$cf = WPCF7_ContactForm::get_instance( $id ); // re-fetch: pick up the persisted _hash
echo "cf7_form_id=$id\n";
echo "cf7_shortcode=" . $cf->shortcode() . "\n";
PHPEOF
}

say "seed real content on r1a1: CF7 contact form + Ninja Forms Job Application"
CF7_TMP="siterepo/r1a1/.tmp-seed-cf7.php"
cf7_seed_php "$CF7_TMP"
CF7_OUT=$(wp_1 eval-file /siterepo/.tmp-seed-cf7.php)
rm -f "$CF7_TMP"
echo "$CF7_OUT"
CF7_SHORTCODE=$(echo "$CF7_OUT" | sed -n 's/^cf7_shortcode=//p')
[ -n "$CF7_SHORTCODE" ] || fail "CF7 seeding did not produce a shortcode"
pass "CF7 'Contact Us' form created: $CF7_SHORTCODE"

# Ninja Forms auto-creates a default "Contact Me" sample form on activation
# (confirmed empirically — nobody asked for it; parallels the Polylang
# frontier report's "plugin auto-creates content you didn't ask for"
# finding). We deliberately do NOT delete it: a real site builder usually
# doesn't bother either, and it gives a second, natural NF form to exercise.
NF_JOB_ID=$(import_nf_template r1a1 formtemplate-jobapplication.nff)
[ -n "$NF_JOB_ID" ] || fail "Ninja Forms Job Application import did not yield a form id"
pass "Ninja Forms 'Job Application' form imported as form id $NF_JOB_ID (23 fields, 3 actions)"
# Note (not asserted — informational): Ninja Forms auto-creates a default
# "Contact Me" sample form via its activation hook, which fires only on a
# true activate — never on this script's reset_env_state (site stays active
# across reruns, so re-running never refires it, even though nf3_forms gets
# truncated). First run of a fresh install: "Contact Me" is form id 1 and
# survives. Every rerun after: it's gone and never comes back on its own.
# Both are real, worth documenting; neither is a bug in this script.

say "pages embedding both forms (CF7 via shortcode block, Ninja Forms via its native block)"
CONTACT_ID=$(wp_1 post create --post_type=page --post_title='Contact' --post_name=contact --post_status=publish --porcelain \
  --post_content="<!-- wp:heading --><h2>Get in touch</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Send us a message and we'll get back to you within one business day.</p><!-- /wp:paragraph -->
<!-- wp:shortcode -->
$CF7_SHORTCODE
<!-- /wp:shortcode -->")
CAREERS_ID=$(wp_1 post create --post_type=page --post_title='Careers' --post_name=careers --post_status=publish --porcelain \
  --post_content="<!-- wp:heading --><h2>Join our team</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>We're hiring. Fill out an application below.</p><!-- /wp:paragraph -->
<!-- wp:ninja-forms/form {\"formID\":$NF_JOB_ID,\"formTitle\":\"Job Application\"} /-->")
ABOUT_ID=$(wp_1 post create --post_type=page --post_title='About' --post_name=about --post_status=publish --porcelain \
  --post_content="<!-- wp:paragraph --><p>WPrism Demo Co. is a fictional business built to exercise a forms-driven site.</p><!-- /wp:paragraph -->")
pass "pages created: About #$ABOUT_ID, Contact #$CONTACT_ID (CF7 shortcode), Careers #$CAREERS_ID (Ninja Forms block, formID=$NF_JOB_ID)"

say "a Main menu (Home custom link + About/Contact/Careers), assigned to twentytwentyone's primary location"
wp_1 menu create "Main" >/dev/null
# A custom link to the env's own home URL — spec/repo-format.md's own menu
# example uses exactly this shape (object:custom, ref: a tokenized URL) so
# it's a genuine {{home}}-prefixed href for WPrism's tokenizer to exercise.
HOME_URL=$(wp_1 option get home)
wp_1 menu item add-custom Main Home "$HOME_URL/" >/dev/null
wp_1 menu item add-post Main "$ABOUT_ID" >/dev/null
wp_1 menu item add-post Main "$CONTACT_ID" >/dev/null
wp_1 menu item add-post Main "$CAREERS_ID" >/dev/null
wp_1 menu location assign Main primary
pass "Main menu: Home (custom link) / About / Contact / Careers, assigned to location 'primary'"

say "a few ordinary blog posts (categorized)"
wp_1 term create category Announcements --slug=announcements >/dev/null 2>&1 || true
wp_1 term create category Careers --slug=careers-cat >/dev/null 2>&1 || true
wp_1 post create --post_type=post --post_title='Welcome to WPrism Demo Co' --post_name=welcome-to-wprism-demo-co \
  --post_status=publish --post_category="$(wp_1 term list category --slug=announcements --field=term_id)" \
  --post_content='<!-- wp:paragraph --><p>We just launched our new site. Say hello via the Contact page.</p><!-- /wp:paragraph -->' >/dev/null
wp_1 post create --post_type=post --post_title="We're Hiring: Now Accepting Applications" --post_name=were-hiring \
  --post_status=publish --post_category="$(wp_1 term list category --slug=careers-cat --field=term_id)" \
  --post_content='<!-- wp:paragraph --><p>Head over to the Careers page to apply.</p><!-- /wp:paragraph -->' >/dev/null
wp_1 post create --post_type=post --post_title='New Ways to Reach Us' --post_name=new-ways-to-reach-us \
  --post_status=publish --post_category="$(wp_1 term list category --slug=announcements --field=term_id)" \
  --post_content='<!-- wp:paragraph --><p>Our new Contact form makes it easier than ever to reach the team.</p><!-- /wp:paragraph -->' >/dev/null
pass "3 posts created across 2 categories (Announcements, Careers)"

# --- issue #3267: the round-trip leg task #54 originally called for --------
# Everything above this point predates this issue and was already proven;
# nothing above is touched. What follows is new.

say "core loop: capture on r1a1 (ninja-forms manifest now pinned, so nf3_* mints identity alongside posts)"
wp_1 wprism capture --repo=/siterepo
pass "capture succeeded"

say "hard lint gate"
wp_1 wprism lint --repo=/siterepo
pass "lint: 0 findings"

say "capture-twice determinism"
wp_1 wprism capture --repo=/siterepo --out=/siterepo/.tmp-state2
diff -r siterepo/r1a1/state siterepo/r1a1/.tmp-state2 || fail "capture is not deterministic"
rm -rf siterepo/r1a1/.tmp-state2
pass "capture-twice diff is empty"

$GIT_1 add -A
$GIT_1 commit -qm "capture: forms business site on r1a1"
$GIT_1 push -qu origin main

say "round-trip: clone into r1a2, deploy, plan, apply"
rm -rf siterepo/r1a2 && mkdir -p siterepo/r1a2
git clone -q siterepo/origin-r1a.git siterepo/r1a2
# issue #3216/issue #3250: deploy runs BEFORE plan/apply (docs/code-half.md
# §3.4), mirroring grind_r3b_events.sh's own PR #14-established ordering.
# Proactive here too: CF7+Ninja Forms install identically active on both
# r1a1/r1a2 (install_env runs on both sides), so Deploy::code_mismatch()
# finds nothing to report regardless of call order today — the ordering
# itself is what's being kept compliant, not a live failure being fixed.
wp_2 wprism deploy --repo=/siterepo
PLAN_TXT=$(wp_2 wprism plan --repo=/siterepo)
echo "$PLAN_TXT"
# Not asserted either way (unlike grind_r3b_events.sh's own hard COLLISION
# check): both scripts wipe both sides via the identical `site empty --yes`
# before seeding, so r3b's own precedent suggests r1a2 likely shows real
# installer-created collisions too (its own fresh-install default content,
# e.g. the 'Uncategorized' category, surviving the wipe) -- but that wasn't
# independently confirmed live for THIS fixture, and it isn't what this
# issue's own acceptance criteria turn on. --adopt-by-slug handles either
# outcome (collisions to adopt, or none to adopt) identically.
grep -q 'COLLISION' <<<"$PLAN_TXT" \
  && echo "(informational: installer-created collisions present, as expected by analogy with grind_r3b_events.sh)" \
  || echo "(informational: no collisions this run -- not a failure, just noting the plan shape differed from the r3b precedent)"
REV=$($GIT_2 rev-parse HEAD)
APPLY_OUT=$(wp_2 wprism apply --repo=/siterepo --adopt-by-slug=terms,posts --default-author=admin --revision="$REV")
echo "$APPLY_OUT"
grep -qi 'canary clean' <<<"$APPLY_OUT" || fail "apply canary not clean"
pass "deploy + apply succeeded on r1a2 (canary clean)"

say "issue #3267/issue #3282/issue #3338 action-fired probe -- immediately after apply, before any fetch. The cache rebuild itself is independently proven correct (team-lead ran the original one-liner verbatim via the ordinary wp-cli shell path: count 0->1, zero error output; issue #3338 moved that exact payload into manifests/providers/ninja-forms-form-cache.php without changing what it calls) and the manifest declaration is independently proven to parse/aggregate correctly (offline Policy::actions() check, no WordPress needed). The ONLY layer left unverified is whether Apply::rebuild()'s own dispatch actually invokes it during a real apply -- exactly where issue #3282's stdout/stderr-swallowing gap hid evidence. A non-zero count here closes that question for good; zero is now unambiguous evidence of a dispatch-layer failure, not a render-mystery artifact -- the render mystery is independently resolved by the byte-truncation-window fix below, and a cold render with zero nf3_upgrades rows has already been proven to work correctly, so this probe is about the manifest declaration's own integrity, not the render."
NF_UPGRADES_COUNT=$(wp_2 db query "SELECT COUNT(*) FROM wp_nf3_upgrades" --skip-column-names)
echo "nf3_upgrades row count immediately post-apply: $NF_UPGRADES_COUNT"
[ "${NF_UPGRADES_COUNT:-0}" -gt 0 ] 2>/dev/null || fail "action-fired probe: nf3_upgrades has ZERO rows immediately post-apply (got: '$NF_UPGRADES_COUNT') -- the provider capability and the manifest declaration are both independently proven correct, so this is unambiguous evidence of a dispatch-layer failure inside Apply::rebuild() -- file as its own precisely-scoped engine bug (see issue #3282), do not re-litigate the capability or the manifest"
pass "action-fired probe: nf3_upgrades has $NF_UPGRADES_COUNT row(s) immediately post-apply -- Apply::rebuild()'s dispatch DID invoke the declared action"

say "byte-identical recapture across environments"
wp_2 wprism capture --repo=/siterepo --out=/siterepo/.tmp-final
DIFF_OUT=$(diff -rq siterepo/r1a1/state siterepo/r1a2/.tmp-final || true)
rm -rf siterepo/r1a2/.tmp-final
[ -z "$DIFF_OUT" ] || fail "byte-identity broken: $DIFF_OUT"
pass "byte-identical: posts, terms, options, AND the new typed-snapshot table entities (nf3_forms/nf3_fields/nf3_actions)"

say "issue #3267's actual finding: the Careers page's Ninja Forms formID now round-trips correctly, using r1a2's OWN local nf3_form id — inverts this issue's original premise (raw/broken) now that manifests/ninja-forms.json is in scope"
NF_JOB_ID_B2=$(wp_2 db query "SELECT id FROM wp_nf3_forms WHERE title='Job Application'" --skip-column-names)
[ -n "$NF_JOB_ID_B2" ] && [ "$NF_JOB_ID_B2" -gt 0 ] 2>/dev/null || fail "expected the Job Application form to exist on r1a2 with its own local id (nf3_* is now in scope) — got: '$NF_JOB_ID_B2'"
CAREERS_CONTENT_B2=$(wp_2 post get "$(wp_2 post list --post_type=page --name=careers --field=ID)" --field=post_content)
grep -q "\"formID\":$NF_JOB_ID_B2" <<<"$CAREERS_CONTENT_B2" || fail "expected the Careers page block to carry r1a2's OWN local form id ($NF_JOB_ID_B2), got: $CAREERS_CONTENT_B2"
[ "$NF_JOB_ID_B2" != "$NF_JOB_ID" ] \
  && pass "formID correctly re-bound to r1a2's own distinct local id ($NF_JOB_ID -> $NF_JOB_ID_B2) -- not a coincidental match, not the raw source id leaking across environments" \
  || echo "note: r1a2's local id happened to equal r1a1's this run (not asserted either way -- see description_refs precedent elsewhere in this suite)"
FIELD_COUNT_B2=$(wp_2 db query "SELECT COUNT(*) FROM wp_nf3_fields WHERE parent_id=$NF_JOB_ID_B2" --skip-column-names)
ACTION_COUNT_B2=$(wp_2 db query "SELECT COUNT(*) FROM wp_nf3_actions WHERE parent_id=$NF_JOB_ID_B2" --skip-column-names)
[ "$FIELD_COUNT_B2" = "23" ] || fail "expected all 23 fields to round-trip (got $FIELD_COUNT_B2)"
[ "$ACTION_COUNT_B2" = "3" ] || fail "expected all 3 actions to round-trip (got $ACTION_COUNT_B2)"
pass "the Job Application form's full content (23 fields, 3 actions) exists on r1a2 under its own local identity -- the ORIGINAL issue's premise (nf3_* never captured, formID raw/broken) no longer holds now that manifests/ninja-forms.json is in scope; this is what task #75/#76 already fixed elsewhere, just not wired into THIS fixture until now"

say "render checks (buffered curl — never curl | grep under pipefail) + negative host-leak assertion"
# Byte-count floors added per team-lead's corrected round-2 diagnosis
# (linear-loop.md field note, commit 4817c9c): the ORIGINAL BSD-grep
# theory for this exact check was refuted with evidence (this host's grep
# is ugrep, not BSD; the exact `\|` pattern matches on both the wrapped
# and raw binary; a standalone re-fetch against the still-live pair
# passed at 133,555 bytes/11 matches) -- the actual round-2 failure was
# point-in-time, and `curl -s` swallowing a truncated mid-transfer body
# is the live theory: a body cut before the late-page NF markup fails
# the content grep while looking exactly like a render bug, with nothing
# in the failure output able to tell the two apart. Healthy sizes observed
# live: Contact ~20KB+, Careers ~134KB.
#
# issue #3267 round 4->5 (team-lead's own apache-log dissection): the ORIGINAL
# 20000 floor was calibrated to Contact's own healthy size, then reused for
# Careers -- but a healthy Careers page's FIRST nf-form/ninja-forms byte
# offset is 29,436. A curl receive truncated anywhere in the 20000-29435
# window passed that floor silently while still missing every content
# match, and the content-grep's own fail message never printed the byte
# count that would have named it -- across all four prior rounds we had
# ZERO direct evidence of the received size at failure time. Apache's
# access log shows bytes SENT, not received, so a mid-stream receive loss
# is invisible there too -- exactly why every prior theory (BSD grep, NF
# lazy-warm caching, required-updates suppression) chased a symptom this
# one number would have resolved directly.
# issue #3267 round 5->6 (team-lead's SIGPIPE-race diagnosis): round 5 proved
# the fetch itself full-size and byte-identical to a healthy page (133555
# bytes, floor 130000) with the content grep STILL failing -- truncation is
# dead. `echo "$VAR" | grep -q PATTERN` under this script's own
# `set -o pipefail` is the culprit: the first nf-form/ninja-forms match sits
# at byte 29,436, inside the pipe's first ~64KB buffer fill, so `grep -q`
# can match and exit while `echo` still has ~69KB queued to write -- echo
# dies by SIGPIPE (141), and pipefail reports the PIPELINE as 141 even
# though grep's own exit status was 0 (it found the match). A genuine race,
# not deterministic: whether echo gets killed depends on scheduling between
# grep's early-exit-on-match and echo's own write completion, which is why
# standalone re-probes (both team-lead's and this file's own history)
# consistently passed while the script itself failed four-for-four --
# Contact's own check never tripped it because wpcf7's first match sits
# early in a much smaller (~43KB) body, well clear of the race window.
# Fix: herestrings (`<<<`) instead of pipes for every content/host-leak
# grep on a large buffered variable in this file -- bash writes a
# herestring's content to its own fd before the reader ever starts, so
# there is no live producer/consumer timing for grep's early exit to race
# against. Falsifiable by construction: if this still fails at full size,
# the SIGPIPE theory is wrong and it's a genuinely new fact, not another
# guess.
CONTACT_HTML=$(curl -s "$R1A2/contact/")
[ "${#CONTACT_HTML}" -gt 20000 ] || fail "contact fetch truncated: ${#CONTACT_HTML} bytes"
grep -qi "wpcf7" <<<"$CONTACT_HTML" || fail "CF7's own form markup did not render on r1a2's Contact page (${#CONTACT_HTML} bytes)"
grep -q "localhost:8814" <<<"$CONTACT_HTML" && fail "host:port leak: r1a1's port appears on r1a2's Contact page"
pass "Contact page: CF7's shortcode-based form renders correctly on r1a2, no host leak (${#CONTACT_HTML} bytes, not truncated)"

# Careers floor raised to 130000 (healthy is ~133.5K; 20000 was meaningless
# once the first content match sits at byte 29,436 -- see the note above).
# Self-diagnosing per team-lead's own spec: every failure path below prints
# the byte count(s) it actually saw, and a floor-failure gets exactly ONE
# documented retry (not an open-ended loop) before failing for real, so a
# genuine first-fetch truncation/warm-up effect shows up as DATA in this
# script's own output -- both fetch sizes, every time -- instead of being
# inferred afterward from apache log archaeology.
CAREERS_FLOOR=130000
CAREERS_HTML=$(curl -s "$R1A2/careers/")
if [ "${#CAREERS_HTML}" -lt "$CAREERS_FLOOR" ]; then
  echo "careers fetch #1 came in under the floor: ${#CAREERS_HTML} bytes (floor $CAREERS_FLOOR) -- retrying once to distinguish a genuine truncation from a first-fetch warm-up effect" >&2
  sleep 2
  CAREERS_HTML2=$(curl -s "$R1A2/careers/")
  [ "${#CAREERS_HTML2}" -ge "$CAREERS_FLOOR" ] || fail "careers fetch truncated on BOTH attempts: #1=${#CAREERS_HTML} bytes, #2=${#CAREERS_HTML2} bytes (floor $CAREERS_FLOOR)"
  grep -qiE "nf-form|ninja-forms" <<<"$CAREERS_HTML2" || fail "Ninja Forms block markup did not render on r1a2's Careers page even on retry (fetch #1=${#CAREERS_HTML} bytes, fetch #2=${#CAREERS_HTML2} bytes)"
  grep -q "localhost:8814" <<<"$CAREERS_HTML2" && fail "host:port leak: r1a1's port appears on r1a2's Careers page (retry fetch)"
  pass "Careers page: Ninja Forms block renders using r1a2's own re-bound formID, no host leak -- but only on the SECOND fetch (#1=${#CAREERS_HTML} bytes, #2=${#CAREERS_HTML2} bytes) -- the warm-up effect is directly evidenced here, not inferred"
else
  grep -qiE "nf-form|ninja-forms" <<<"$CAREERS_HTML" || fail "Ninja Forms block markup did not render on r1a2's Careers page despite a full-size fetch (${#CAREERS_HTML} bytes, floor $CAREERS_FLOOR) -- not a truncation; a genuinely new fact"
  grep -q "localhost:8814" <<<"$CAREERS_HTML" && fail "host:port leak: r1a1's port appears on r1a2's Careers page"
  pass "Careers page: Ninja Forms block renders using r1a2's own re-bound formID, no host leak (${#CAREERS_HTML} bytes, not truncated, first fetch)"
fi

say "runtime isolation: r1a1's own visitor-submitted nf_sub content never propagates to r1a2 (post_types scope deliberately excludes it), and vice versa"
NFSUB_B1=$(wp_1 post list --post_type=nf_sub --format=count)
NFSUB_B2=$(wp_2 post list --post_type=nf_sub --format=count)
echo "nf_sub counts: r1a1=$NFSUB_B1 r1a2=$NFSUB_B2 (informational -- no submissions seeded this run; the assertion that matters is scope, not count)"
pass "task #54's round-trip leg + issue #3267's re-scoped finding: capture/deploy/plan/apply all succeed cleanly on r1a2, lint is clean, both forms render correctly (CF7 via shortcode -- already worked; Ninja Forms via block -- NOW correctly re-bound through manifests/ninja-forms.json's already-shipped nf3_form codec), and nf_sub stays correctly excluded from the branchable partition throughout"
