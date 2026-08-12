#!/usr/bin/env bash
# Contact Form 7 manifest conformance seed (Grind R1-A, docs/grind/r1a-forms.md):
# a real form built via WPCF7_ContactForm::get_template()+save() — CF7's own
# admin-screen code path, not hand-authored postmeta — with customized mail
# settings, plus pages embedding both the modern hash shortcode and the
# legacy positional shortcode (resolved through _old_cf7_unit_id). Invoked by
# conformance/run.sh
# with wp_conf1/wp_conf2/$COMPOSE already exported.
set -euo pipefail

cat > "${CONF_REPO1:-siterepo/conf1}"/.tmp-cf7-seed.php <<'PHPEOF'
<?php
if ( ! class_exists( 'WPCF7_ContactForm' ) ) { fwrite( STDERR, "WPCF7_ContactForm not loaded\n" ); exit( 1 ); }
wp_set_current_user( get_user_by( 'login', 'admin' )->ID );
$cf = WPCF7_ContactForm::get_template( array( 'title' => 'Conformance Contact Form' ) );
$mail = $cf->prop( 'mail' );
$mail['recipient'] = 'conformance@example.test';
$mail['subject'] = '[Conformance] [your-subject]';
$cf->set_properties( array( 'mail' => $mail ) );
$id = $cf->save();
if ( ! $id ) { fwrite( STDERR, "CF7 save() failed\n" ); exit( 1 ); }
// CF7's pre-6.x import path retained this alternate identity and its legacy
// formatter still emits it in [contact-form <old_id> "..."] today. Keep a
// deterministic, high value that cannot be mistaken for wp_posts.ID.
$old_id = 3199001;
if ( (string) $id === (string) $old_id ) { fwrite( STDERR, "legacy alternate equals source post id\n" ); exit( 1 ); }
update_post_meta( $id, '_old_cf7_unit_id', $old_id );
$cf = WPCF7_ContactForm::get_instance( $id );
echo "cf7_shortcode=" . $cf->shortcode() . "\n";
echo "cf7_legacy_shortcode=" . $cf->shortcode( array( 'use_old_format' => true ) ) . "\n";
echo "cf7_old_id=" . $old_id . "\n";
PHPEOF
CF7_OUT=$($COMPOSE run --rm -T cli1 wp eval-file /siterepo/.tmp-cf7-seed.php)
rm -f "${CONF_REPO1:-siterepo/conf1}"/.tmp-cf7-seed.php
require_observed_nonempty "conf1 Contact Form 7 seed output" "$CF7_OUT"
CF7_SHORTCODE=$(echo "$CF7_OUT" | sed -n 's/^cf7_shortcode=//p')
[ -n "$CF7_SHORTCODE" ] || fail "contact-form-7 conformance seed did not produce a shortcode"
CF7_LEGACY_SHORTCODE=$(echo "$CF7_OUT" | sed -n 's/^cf7_legacy_shortcode=//p')
[ -n "$CF7_LEGACY_SHORTCODE" ] || fail "contact-form-7 conformance seed did not produce a legacy shortcode"
CF7_OLD_ID=$(echo "$CF7_OUT" | sed -n 's/^cf7_old_id=//p')
[[ "$CF7_OLD_ID" =~ ^[1-9][0-9]+$ ]] || fail "contact-form-7 seed produced an invalid old unit id"
[[ "$CF7_LEGACY_SHORTCODE" =~ ^\[contact-form[[:space:]]+$CF7_OLD_ID([[:space:]]|\]) ]] \
  || fail "contact-form-7 did not emit the required legacy positional shortcode for old id $CF7_OLD_ID (got '$CF7_LEGACY_SHORTCODE')"
if [[ "$CF7_LEGACY_SHORTCODE" == *'[contact-form-7 '* ]]; then
  fail "contact-form-7 legacy seed unexpectedly returned the modern hash shortcode"
fi

PAGE_ID=$(wp_conf1 post create --post_type=page --post_title='Conformance Contact' --post_name=conformance-contact \
  --post_status=publish --porcelain \
  --post_content="<!-- wp:paragraph --><p>Conformance contact page.</p><!-- /wp:paragraph -->
<!-- wp:shortcode -->
$CF7_SHORTCODE
<!-- /wp:shortcode -->")
require_fixture_ids PAGE_ID

echo "contact-form-7 seed: page=$PAGE_ID shortcode=$CF7_SHORTCODE"

LEGACY_PAGE_ID=$(wp_conf1 post create --post_type=page --post_title='Conformance Contact Legacy' --post_name=conformance-contact-legacy \
  --post_status=publish --porcelain \
  --post_content="<!-- wp:paragraph --><p>Legacy contact page.</p><!-- /wp:paragraph -->
<!-- wp:shortcode -->
$CF7_LEGACY_SHORTCODE
<!-- /wp:shortcode -->")
require_fixture_ids LEGACY_PAGE_ID

echo "contact-form-7 legacy seed: page=$LEGACY_PAGE_ID shortcode=$CF7_LEGACY_SHORTCODE old_id=$CF7_OLD_ID"
