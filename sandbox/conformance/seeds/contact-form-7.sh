#!/usr/bin/env bash
# Contact Form 7 manifest conformance seed (Grind R1-A, docs/grind/r1a-forms.md):
# a real form built via WPCF7_ContactForm::get_template()+save() — CF7's own
# admin-screen code path, not hand-authored postmeta — with customized mail
# settings, plus a page embedding it via the shortcode CF7 itself generates
# (a persisted _hash, NOT the numeric post id). Invoked by conformance/run.sh
# with wp_conf1/wp_conf2/$COMPOSE already exported.
set -euo pipefail

cat > siterepo/conf1/.tmp-cf7-seed.php <<'PHPEOF'
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
$cf = WPCF7_ContactForm::get_instance( $id );
echo "cf7_shortcode=" . $cf->shortcode() . "\n";
PHPEOF
CF7_OUT=$($COMPOSE run --rm -T cli-conf1 wp eval-file /siterepo/.tmp-cf7-seed.php)
rm -f siterepo/conf1/.tmp-cf7-seed.php
CF7_SHORTCODE=$(echo "$CF7_OUT" | sed -n 's/^cf7_shortcode=//p')
[ -n "$CF7_SHORTCODE" ] || fail "contact-form-7 conformance seed did not produce a shortcode"

PAGE_ID=$(wp_conf1 post create --post_type=page --post_title='Conformance Contact' --post_name=conformance-contact \
  --post_status=publish --porcelain \
  --post_content="<!-- wp:paragraph --><p>Conformance contact page.</p><!-- /wp:paragraph -->
<!-- wp:shortcode -->
$CF7_SHORTCODE
<!-- /wp:shortcode -->")

echo "contact-form-7 seed: page=$PAGE_ID shortcode=$CF7_SHORTCODE"
