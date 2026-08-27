seed_cf7_content() { # seed_cf7_content <cli-fn>
  local cli="$1"
  cat > "siterepo/${PAIR}1/.tmp-seed-cf7.php" <<'PHPEOF'
<?php
if (!class_exists('WPCF7_ContactForm')) {
    fwrite(STDERR, "WPCF7_ContactForm not loaded\n");
    exit(1);
}
wp_set_current_user(get_user_by('login', 'admin')->ID);
$form = WPCF7_ContactForm::get_template(['title' => 'Version Matrix Contact Form']);
$mail = $form->prop('mail');
$mail['recipient'] = 'vmatrix@example.test';
$mail['subject'] = '[Version Matrix] [your-subject]';
$form->set_properties(['mail' => $mail]);
$form_id = $form->save();
if (!$form_id) { fwrite(STDERR, "CF7 save() failed\n"); exit(1); }
$form = WPCF7_ContactForm::get_instance($form_id);
$shortcode = $form->shortcode();
$old_id = 3199001;
if ((string) $old_id === (string) $form_id) { fwrite(STDERR, "legacy alternate equals source post id\n"); exit(1); }
update_post_meta($form_id, '_old_cf7_unit_id', $old_id);
$legacy_shortcode = '[contact-form ' . $old_id . ' "Version Matrix Contact Form"]';
$page_id = wp_insert_post([
    'post_type' => 'page', 'post_status' => 'publish',
    'post_title' => 'Version Matrix Contact', 'post_name' => 'vmatrix-contact',
    'post_content' => "<!-- wp:paragraph -->\n<p>Contact Form 7 boundary fixture.</p>\n<!-- /wp:paragraph -->\n<!-- wp:shortcode -->\n{$shortcode}\n<!-- /wp:shortcode -->",
], true);
if (is_wp_error($page_id)) { fwrite(STDERR, "page insert failed\n"); exit(1); }
$legacy_page_id = wp_insert_post([
    'post_type' => 'page', 'post_status' => 'publish',
    'post_title' => 'Version Matrix Contact Legacy', 'post_name' => 'vmatrix-contact-legacy',
    'post_content' => "<!-- wp:paragraph -->\n<p>Legacy Contact Form 7 boundary fixture.</p>\n<!-- /wp:paragraph -->\n<!-- wp:shortcode -->\n{$legacy_shortcode}\n<!-- /wp:shortcode -->",
], true);
if (is_wp_error($legacy_page_id)) { fwrite(STDERR, "legacy page insert failed\n"); exit(1); }
echo json_encode([
    'form' => $form_id, 'page' => $page_id, 'shortcode' => $shortcode,
    'old_id' => $old_id, 'legacy_page' => $legacy_page_id, 'legacy_shortcode' => $legacy_shortcode,
]) . "\n";
PHPEOF
  local seed_out
  seed_out=$("$cli" eval-file /siterepo/.tmp-seed-cf7.php)
  rm -f "siterepo/${PAIR}1/.tmp-seed-cf7.php"
  CF7_SEED_OUT="$seed_out"
  echo "cf7 seed: $seed_out"
}
