#!/usr/bin/env bash
# Ninja Forms manifest conformance seed (task #75, docs/grind/r1a-forms.md):
# imports the plugin's own bundled "Job Application" template (23 fields, 3
# actions) through its REAL admin import batch process
# (NF_Admin_Processes_ImportForm — the exact class wp-admin's "Add New Form"
# template gallery drives), not hand-authored table rows. Mirrors
# sandbox/tests/grind_r1a_forms.sh's import_nf_template() helper, adapted to
# this harness's conf1-only, single-invocation convention (run.sh already
# exports $COMPOSE/wp_conf1/fail/pass to this script's process).
set -euo pipefail

# Ninja Forms auto-creates a default "Contact Me" sample form on activation —
# confirmed live, also noted in docs/grind/r1a-forms.md ("nobody asked for
# it"). install_env() activates the plugin independently on BOTH conf1 and
# conf2, so BOTH environments mint their OWN "Contact Me" row before this
# seed ever runs — two genuinely independent rows with no shared origin.
# nf3_forms is a MAPPED-identity table (no natural key exists for "the
# sample form" — see manifests/ninja-forms.json), so typed-snapshot has no
# way to recognize two independently-created rows as "the same" form:
# capturing conf1's and applying to conf2 correctly creates a SECOND,
# distinct "Contact Me" row on conf2 rather than silently guessing the two
# coincide — confirmed by deliberately running this suite without this
# cleanup first, which reproduced exactly that (conf2 ending up with two
# "Contact Me" rows and conf1 with one, a genuine round-trip mismatch, not a
# capture/apply bug). Removing each side's own copy before either capture
# establishes the same clean, deterministic baseline sandbox/tests/
# grind_r1a_forms.sh's reset_env_state() already established for the r1a
# pair (which truncates nf3_forms outright); this harness's conf pair has no
# such reset hook of its own, so it's done here, scoped to just this one row
# and its cascade, rather than truncating tables shared with other manifests
# this same conf pair rotates through.
read -r -d '' REMOVE_CONTACT_ME_PHP <<'PHPEOF' || true
<?php
global $wpdb;
$id = (int) $wpdb->get_var("SELECT id FROM {$wpdb->prefix}nf3_forms WHERE title = 'Contact Me'");
if ($id) {
    $fieldIds = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$wpdb->prefix}nf3_fields WHERE parent_id = %d", $id));
    $actionIds = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$wpdb->prefix}nf3_actions WHERE parent_id = %d", $id));
    foreach ($fieldIds as $fid) {
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nf3_field_meta WHERE parent_id = %d", $fid));
    }
    foreach ($actionIds as $aid) {
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nf3_action_meta WHERE parent_id = %d", $aid));
    }
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nf3_fields WHERE parent_id = %d", $id));
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nf3_actions WHERE parent_id = %d", $id));
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nf3_form_meta WHERE parent_id = %d", $id));
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nf3_upgrades WHERE id = %d", $id));
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nf3_forms WHERE id = %d", $id));
    echo "removed this environment's own activation-created 'Contact Me' form (id=$id)\n";
}
PHPEOF
# Each env's cli container mounts ITS OWN host siterepo/<env> dir as /siterepo
# (cli2 never sees siterepo/conf1) — the temp file is written into both,
# not shared, even though its content is identical.
for env in conf1 conf2; do
    printf '%s' "$REMOVE_CONTACT_ME_PHP" > "siterepo/$env/.tmp-nf-remove-contact-me.php"
    wp_env "$env" eval-file /siterepo/.tmp-nf-remove-contact-me.php
    rm -f "siterepo/$env/.tmp-nf-remove-contact-me.php"
done

cat > siterepo/conf1/.tmp-nf-import-step.php <<'PHPEOF'
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

NF_FORM_ID=""
for step in 1 2 3 4 5 6; do
    OUT=$($COMPOSE run --rm -T \
        -e "NF_TEMPLATE_PATH=/var/www/html/wp-content/plugins/ninja-forms/includes/Templates/formtemplate-jobapplication.nff" \
        cli1 wp eval-file /siterepo/.tmp-nf-import-step.php 2>&1) || true
    if grep -q '"batch_complete":true' <<<"$OUT"; then
        NF_FORM_ID=$(grep -o '"form_id":[0-9]*' <<<"$OUT" | grep -o '[0-9]*')
        break
    fi
done
rm -f siterepo/conf1/.tmp-nf-import-step.php
[ -n "$NF_FORM_ID" ] || fail "ninja-forms conformance seed: Job Application import did not complete after 6 steps"

PAGE_ID=$(wp_conf1 post create --post_type=page --post_title='Conformance Careers' --post_name=conformance-careers \
  --post_status=publish --porcelain \
  --post_content="<!-- wp:paragraph --><p>Conformance careers page.</p><!-- /wp:paragraph -->
<!-- wp:ninja-forms/form {\"formID\":$NF_FORM_ID,\"formTitle\":\"Job Application\"} /-->")

echo "ninja-forms seed: page=$PAGE_ID form_id=$NF_FORM_ID (23 fields, 3 actions)"
