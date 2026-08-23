#!/usr/bin/env bash
# Exact CF7 6.1.7 source fixture. Forms are authored through the plugin API;
# the only direct metadata transition reproduces CF7's still-readable pre-3.3
# property names so both admitted storage generations travel the product path.
set -euo pipefail

SEED_FILE="${CONF_REPO1:-siterepo/conf1}/.tmp-cf7-seed.php"
cat > "$SEED_FILE" <<'PHPEOF'
<?php
if (!class_exists('WPCF7_ContactForm')) {
    throw new RuntimeException('WPCF7_ContactForm not loaded');
}
$admin = get_user_by('login', 'admin');
if (!$admin) {
    throw new RuntimeException('admin user missing');
}
wp_set_current_user((int) $admin->ID);

function duo_cf7_create(string $title, string $profile): WPCF7_ContactForm {
    $form = WPCF7_ContactForm::get_template(['title' => $title]);
    $home = home_url('/');
    $mail = $form->prop('mail');
    $mail['recipient'] = "$profile-recipient@example.test";
    $mail['sender'] = "Duo CF7 <$profile-sender@example.test>";
    $mail['subject'] = "[$profile 東京 🚀] [your-subject]";
    $mail['body'] = str_repeat("$profile mail 東京 🚀 | $home\n", 700)
        . "Name: [your-name]\nEmail: [your-email]\nMessage: [your-message]";
    $mail['additional_headers'] = "Reply-To: [your-email]\nX-Duo-Profile: $profile";
    $mail['attachments'] = '';
    $mail['use_html'] = false;
    $mail['exclude_blank'] = false;

    $mail2 = $form->prop('mail_2');
    $mail2['active'] = true;
    $mail2['recipient'] = '[your-email]';
    $mail2['sender'] = "Duo CF7 <$profile-sender@example.test>";
    $mail2['subject'] = "[$profile autoresponse 東京 🚀]";
    $mail2['body'] = "Second mail $profile 東京 🚀\n$home";
    $mail2['additional_headers'] = '';
    $mail2['attachments'] = '';
    $mail2['use_html'] = false;
    $mail2['exclude_blank'] = false;

    $messages = $form->prop('messages');
    $messages['validation_error'] = "$profile validation 東京 🚀 — $home";
    $messages['mail_sent_ok'] = "$profile sent 東京 🚀";

    $form->set_properties([
        'form' => "<label>Name 東京 🚀 [text* your-name]</label>\n"
            . "<label>Email [email* your-email]</label>\n"
            . "<label>Subject [text your-subject]</label>\n"
            . "<label>Message [textarea your-message]</label>\n"
            . "[duo-unknown raw=\"$profile-literal\"]\n[submit \"Send 東京 🚀\"]",
        'mail' => $mail,
        'mail_2' => $mail2,
        'messages' => $messages,
        // Prevent the conformance submission from touching external mail.
        'additional_settings' => "demo_mode: on\nacceptance_as_validation: on",
    ]);
    $id = $form->save();
    if (!$id) {
        throw new RuntimeException("CF7 save failed for $title");
    }
    $saved = WPCF7_ContactForm::get_instance((int) $id);
    if (!$saved) {
        throw new RuntimeException("CF7 reload failed for $title");
    }
    return $saved;
}

$main = duo_cf7_create('Conformance Contact Form', 'main');
$legacy = duo_cf7_create('Conformance Legacy Storage', 'legacy');
$deleteProbe = duo_cf7_create('Conformance Delete Probe', 'delete');

$mainOldId = 3199001;
$legacyOldId = 3199002;
update_post_meta($main->id(), '_old_cf7_unit_id', (string) $mainOldId);
update_post_meta($legacy->id(), '_old_cf7_unit_id', (string) $legacyOldId);

// CF7 6.x constructor code still checks each unprefixed pre-3.3 property if
// its current underscored spelling is absent. Move all five as one generation.
foreach (['form', 'mail', 'mail_2', 'messages', 'additional_settings'] as $property) {
    $value = get_post_meta($legacy->id(), '_' . $property, true);
    delete_post_meta($legacy->id(), '_' . $property);
    update_post_meta($legacy->id(), $property, $value);
}
$legacy = WPCF7_ContactForm::get_instance($legacy->id());
if (!$legacy || !str_contains((string) $legacy->prop('form'), 'legacy-literal')) {
    throw new RuntimeException('CF7 did not read the admitted legacy property generation');
}
$main = WPCF7_ContactForm::get_instance($main->id());

// Native runtime and optional-integration residue must not enter canonical
// state. Matching target-owned sentinels are installed after deploy.
update_post_meta($main->id(), '_config_validation', ['source-runtime' => true, 'timestamp' => 100]);
update_post_meta($main->id(), '_config_errors', ['source-obsolete-runtime']);
update_post_meta($main->id(), '_flamingo', ['channel' => 7001]);
update_post_meta($main->id(), '_constant_contact', ['list' => 'source-environment-list']);
update_post_meta($main->id(), '_sendinblue', ['list' => 'source-environment-list']);
$sourceOption = (array) get_option('wpcf7', []);
$sourceOption['duo_source_only'] = 'must-not-cross';
$sourceOption['recaptcha'] = ['source-site-key' => 'source-secret-key'];
update_option('wpcf7', $sourceOption);

$modern = $main->shortcode();
$legacyPositional = $main->shortcode(['use_old_format' => true]);
$legacyModern = $legacy->shortcode();
if (!preg_match('/^\[contact-form-7 id="[0-9a-f]{7}" title=/', $modern)) {
    throw new RuntimeException("unexpected modern shortcode: $modern");
}
if (!str_starts_with($legacyPositional, '[contact-form ' . $mainOldId . ' ')) {
    throw new RuntimeException("unexpected legacy positional shortcode: $legacyPositional");
}

$pages = [];
foreach ([
    'modern' => ['Conformance Contact', 'conformance-contact', $modern],
    'positional' => ['Conformance Contact Legacy', 'conformance-contact-legacy', $legacyPositional],
    'legacy_storage' => ['Conformance Legacy Storage Page', 'conformance-contact-storage', $legacyModern],
    'multiple' => ['Conformance Multiple Forms', 'conformance-contact-multiple', "$modern\n$legacyModern"],
] as $key => [$title, $slug, $shortcodes]) {
    $id = wp_insert_post([
        'post_type' => 'page',
        'post_status' => 'publish',
        'post_title' => $title,
        'post_name' => $slug,
        'post_content' => "<!-- wp:paragraph -->\n<p>$title 東京 🚀</p>\n<!-- /wp:paragraph -->\n"
            . "<!-- wp:shortcode -->\n$shortcodes\n<!-- /wp:shortcode -->",
    ], true);
    if (is_wp_error($id)) {
        throw new RuntimeException($id->get_error_message());
    }
    $pages[$key] = (int) $id;
}

echo wp_json_encode([
    'delete_probe' => $deleteProbe->id(),
    'legacy_form' => $legacy->id(),
    'legacy_hash' => get_post_meta($legacy->id(), '_hash', true),
    'legacy_old_id' => $legacyOldId,
    'main_form' => $main->id(),
    'main_hash' => get_post_meta($main->id(), '_hash', true),
    'main_old_id' => $mainOldId,
    'pages' => $pages,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
PHPEOF

CF7_OUT=$(wp_conf1 eval-file /siterepo/.tmp-cf7-seed.php)
rm -f "$SEED_FILE"
require_observed_nonempty "conf1 Contact Form 7 seed output" "$CF7_OUT"
CF7_JSON=$(printf '%s\n' "$CF7_OUT" | awk 'NF { line=$0 } END { print line }')
printf '%s\n' "$CF7_JSON" | jq -e '
  .main_form > 0 and .legacy_form > 0 and .delete_probe > 0 and
  (.main_hash | test("^[0-9a-f]{64}$")) and
  (.legacy_hash | test("^[0-9a-f]{64}$")) and
  .main_old_id == 3199001 and .legacy_old_id == 3199002 and
  (.pages | length) == 4 and all(.pages[]; . > 0)
' >/dev/null || fail "Contact Form 7 source fixture was incomplete: $CF7_JSON"
printf '%s\n' "$CF7_JSON" > "${CONF_REPO1:-siterepo/conf1}/.tmp-cf7-source.json"

PAGE_ID=$(jq -r '.pages.modern' <<<"$CF7_JSON")
LEGACY_PAGE_ID=$(jq -r '.pages.positional' <<<"$CF7_JSON")
LEGACY_STORAGE_PAGE_ID=$(jq -r '.pages.legacy_storage' <<<"$CF7_JSON")
MULTIPLE_PAGE_ID=$(jq -r '.pages.multiple' <<<"$CF7_JSON")
require_fixture_ids PAGE_ID LEGACY_PAGE_ID LEGACY_STORAGE_PAGE_ID MULTIPLE_PAGE_ID
pass "seeded native CF7 current/legacy forms, long UTF-8 mail, modern/positional identities, runtime residue, and deletion probe"
