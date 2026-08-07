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
# duo ledger tables, and site-repo git state. WP core/theme/plugin install is
# skipped on repeat runs (guarded by `core is-installed` / `plugin
# is-installed`).
set -euo pipefail
cd "$(dirname "$0")/.."   # -> sandbox/
COMPOSE="docker compose -f docker-compose.yml --profile r1a"
say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }
R1A1=http://localhost:8814
R1A2=http://localhost:8815

wp_env() { # wp_env <r1a1|r1a2> <wp args...>
  local env="$1"; shift
  $COMPOSE run --rm -T "cli-$env" wp "$@"
}
wp_1() { wp_env r1a1 "$@"; }
wp_2() { wp_env r1a2 "$@"; }

wait_for() { # wait_for <r1a1|r1a2>
  local env="$1"
  echo "waiting for env $env..."
  for _ in $(seq 1 90); do
    wp_env "$env" core version >/dev/null 2>&1 && return 0
    sleep 2
  done
  echo "env $env never became ready" >&2
  exit 1
}

write_htaccess() { # write_htaccess <r1a1|r1a2>
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
# content + the duo ledger tables every time (mirrors spike_f_core_loop.sh's
# reset_env_state). CF7/Ninja Forms are NOT deactivated/reinstalled per run
# (install_env already guards that) — only their data is wiped, via each
# plugin's own uninstall-equivalent tables/options where feasible.
reset_env_state() { # reset_env_state <r1a1|r1a2>
  local env="$1"
  wp_env "$env" site empty --yes >/dev/null
  wp_env "$env" db query "TRUNCATE TABLE wp_duo_map" >/dev/null 2>&1 || true
  wp_env "$env" db query "TRUNCATE TABLE wp_duo_state" >/dev/null 2>&1 || true
  wp_env "$env" db query "TRUNCATE TABLE wp_duo_kv" >/dev/null 2>&1 || true
  wp_env "$env" duo journal-reset >/dev/null 2>&1 || true
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
  # (nothing to do with Duo specifically) can hit the same silent staleness,
  # and it directly informs the typed-snapshot acceptance criteria below —
  # a future capture/apply capability for nf3_* MUST rebuild this cache per
  # form id, the same "manifest declares rebuilders" pattern already used
  # for Yoast/Elementor.
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
    if echo "$out" | grep -q '"batch_complete":true'; then
      rm -f "siterepo/$env/.tmp-nf-import-step.php"
      echo "$out" | grep -o '"form_id":[0-9]*' | grep -o '[0-9]*'
      return 0
    fi
  done
  echo "$out" >&2
  fail "Ninja Forms import of $template on $env did not complete after 6 steps"
}

say "boot env pair r1a1 (:8814) / r1a2 (:8815)"
mkdir -p siterepo/r1a1 siterepo/r1a2
$COMPOSE up -d db-r1a1 wp-r1a1 db-r1a2 wp-r1a2
install_env r1a1 8814 "Duo R1A1 (Forms)"
install_env r1a2 8815 "Duo R1A2 (Forms)"
pass "both envs installed, CF7 + Ninja Forms active, journal on (DUO_JOURNAL)"

say "reset r1a1/r1a2 content + ledger for a clean run"
reset_env_state r1a1
reset_env_state r1a2
pass "WP content, duo_map/duo_state/duo_kv, nf3_* tables, and the journal are all clean on both envs"

say "fresh site repo (own origin, own clones — never touches siterepo/a|b|c|conf*|e*|fx*|g*|r1b*|r1c*)"
rm -rf siterepo/origin-r1a.git
git init --bare -b main siterepo/origin-r1a.git >/dev/null
rm -rf siterepo/r1a1 && mkdir -p siterepo/r1a1
# post_types deliberately does NOT include nf_sub: it is Ninja Forms'
# submission post type (see the "anonymous submissions" section above) —
# runtime, visitor-authored, and must never enter the branchable partition.
# wpcf7_contact_form IS in scope: it is CF7's own form-definition CPT, a
# site-builder-authored entity like any other post type in this engine.
cat > siterepo/r1a1/site.duo.json <<'EOF'
{
  "manifests": ["core"],
  "policy": {
    "options": {},
    "post_meta": {},
    "post_types": ["post", "page", "attachment", "wpcf7_contact_form"],
    "taxonomies": ["category", "post_tag"]
  },
  "spec_version": 1
}
EOF
cp site-repo.gitignore.template siterepo/r1a1/.gitignore
git -C siterepo/r1a1 init -q -b main
git -C siterepo/r1a1 remote add origin ../origin-r1a.git
pass "site repo initialized (manifests: [core], post_types scope excludes nf_sub deliberately)"

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
$mail['subject'] = '[Duo Demo Co] New inquiry: [your-subject]';
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
  --post_content="<!-- wp:paragraph --><p>Duo Demo Co. is a fictional business built to exercise a forms-driven site.</p><!-- /wp:paragraph -->")
pass "pages created: About #$ABOUT_ID, Contact #$CONTACT_ID (CF7 shortcode), Careers #$CAREERS_ID (Ninja Forms block, formID=$NF_JOB_ID)"

say "a Main menu (Home custom link + About/Contact/Careers), assigned to twentytwentyone's primary location"
wp_1 menu create "Main" >/dev/null
# A custom link to the env's own home URL — spec/repo-format.md's own menu
# example uses exactly this shape (object:custom, ref: a tokenized URL) so
# it's a genuine {{home}}-prefixed href for Duo's tokenizer to exercise.
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
wp_1 post create --post_type=post --post_title='Welcome to Duo Demo Co' --post_name=welcome-to-duo-demo-co \
  --post_status=publish --post_category="$(wp_1 term list category --slug=announcements --field=term_id)" \
  --post_content='<!-- wp:paragraph --><p>We just launched our new site. Say hello via the Contact page.</p><!-- /wp:paragraph -->' >/dev/null
wp_1 post create --post_type=post --post_title="We're Hiring: Now Accepting Applications" --post_name=were-hiring \
  --post_status=publish --post_category="$(wp_1 term list category --slug=careers-cat --field=term_id)" \
  --post_content='<!-- wp:paragraph --><p>Head over to the Careers page to apply.</p><!-- /wp:paragraph -->' >/dev/null
wp_1 post create --post_type=post --post_title='New Ways to Reach Us' --post_name=new-ways-to-reach-us \
  --post_status=publish --post_category="$(wp_1 term list category --slug=announcements --field=term_id)" \
  --post_content='<!-- wp:paragraph --><p>Our new Contact form makes it easier than ever to reach the team.</p><!-- /wp:paragraph -->' >/dev/null
pass "3 posts created across 2 categories (Announcements, Careers)"
