<?php
declare(strict_types=1);

/**
 * The capsule's classification decisions, asserted through the product
 * accessors rather than by re-reading the manifest it is testing.
 *
 * Download Manager writes package metadata through one uniform loop
 * (Packages.php:75-115 stores every $_POST['file'][<k>] as __wpdm_<k>) and
 * persists ANY posted settings key matching _wpdm_ (Settings.php:129-140).
 * Both surfaces are open by construction, so what this suite protects is the
 * boundary: which of those keys the engine will carry, which stay with the
 * target, and that everything else stays loudly unclassified.
 */

$root = dirname(__DIR__, 4);
require_once $root . '/sandbox/tests/lib/check.php';
require_once $root . '/tools/src/AdapterPackageValidator.php';
require_once $root . '/agent/src/Kernel/PlainData.php';
require_once $root . '/agent/src/Kernel/Secrets.php';

use WPrism\AdapterLibrary;
use WPrism\PlainData;
use WPrism\Policy;
use WPrism\Secrets;
use WPrism\Tooling\AdapterPackageValidator;

// Also defines WPRISM_SPEC_VERSION, which Policy::load() checks before it will
// accept core's own manifest.
AdapterPackageValidator::validate($root, 'download-manager');
$policy = Policy::load(null, ['core', 'download-manager'], adapterLibrary: AdapterLibrary::fromSourcePackage($root, 'download-manager'));

// ---- entity boundary -------------------------------------------------------

wprism_check_same(['wpdmpro'], $policy->declared_post_types(), 'download-manager.php:320 registers exactly one post type and the capsule claims exactly it');
wprism_check_same(['wpdmcategory', 'wpdmtag'], $policy->declared_taxonomies(), 'both registered taxonomies are declared over that type');
wprism_check_same('blocks', $policy->body_mode('wpdmpro'), 'wpdmpro supports the editor, so post_content takes the engine default rather than a declared codec');

// ---- carried authored intent ----------------------------------------------

$authored = [
    '__wpdm_access', '__wpdm_changelog', '__wpdm_files', '__wpdm_link_label',
    '__wpdm_page_template', '__wpdm_quota', '__wpdm_template', '__wpdm_terms_conditions',
    '__wpdm_version',
];
foreach ($authored as $key) {
    wprism_check_same('authored', $policy->post_meta_rule($key)['class'], "$key is portable package authoring");
}
wprism_check_same(
    ['class' => 'authored', 'ref' => 'post[]'],
    array_diff_key($policy->post_meta_rule('__wpdm_additional_previews'), ['note' => true]),
    'PackageController.php:2493-2495 iterates the stored value as attachment ids, so the rule carries them as post references'
);

// ---- withheld: derived, runtime, and target-local ---------------------------

foreach (['__wpdm_package_size', '__wpdm_package_size_b'] as $key) {
    wprism_check_same('derived', $policy->post_meta_rule($key)['class'], "$key is recomputed from a filesystem walk, never authored intent");
}
foreach (['__wpdm_download_count', '__wpdm_view_count', '__wpdm_password_usage', '__wpdm_legacy_id'] as $key) {
    wprism_check_same('runtime', $policy->post_meta_rule($key)['class'], "$key is a counter or import residue");
}

// Shortcodes.php:342/349 write these on the EMBEDDING post during render, so
// they can appear under any post type, not only wpdmpro.
foreach (['__wpdm_link_template', '__wpdm_items_per_page'] as $key) {
    wprism_check_same('runtime', $policy->post_meta_rule($key)['class'], "$key is written by front-end render on the embedding post");
}
wprism_check_same('authored', $policy->post_meta_rule('__wpdm_template')['class'], 'the authored link template is a different key from the render-written __wpdm_link_template');

// Absolute stored URLs have no scalar-meta rebinding path in this slice.
foreach (['__wpdm_icon', '__wpdm_preview'] as $key) {
    wprism_check_same('env', $policy->post_meta_rule($key)['class'], "$key stores an absolute URL and would otherwise point a target at the source site");
}
wprism_check_same('env', $policy->term_meta_rule('__wpdm_icon')['class'], 'the category icon URL is withheld on the same grounds');
wprism_check(
    $policy->post_meta_rule('__wpdm_icon')['note'] !== $policy->term_meta_rule('__wpdm_icon')['note'],
    'post_meta and term_meta are separate sections, so one key name carries two independently reviewed rules'
);
wprism_check_same('env', $policy->post_meta_rule('__wpdm_terms_page')['class'], 'wp_dropdown_pages() stores a -1 "none" sentinel that a scalar post reference cannot express here');

// ---- credentials -----------------------------------------------------------

$credentials = [
    '__wpdm_password' => 'post_meta',
    '__wpdm_media_pass' => 'post_meta',
    '__wpdm_masterkey' => 'post_meta',
];
foreach ($credentials as $key => $section) {
    wprism_check_same('env', $policy->post_meta_rule($key)['class'], "$section '$key' never becomes authored configuration");
}
foreach (['__wpdm_enc_key', '__wpdm_cron_key', '__wpdm_access_token', '_wpdm_recaptcha_secret_key', '_wpdm_twitter_api_secret', '_wpdm_google_client_secret'] as $name) {
    wprism_check_same('env', $policy->option_rule($name)['class'], "option '$name' is target-local credential material");
}

// The reviewed classification, not the automatic screen, is what holds this
// line. The screen catches a high-entropy __wpdm_password by its key role, but
// __wpdm_enc_key and __wpdm_masterkey carry no credential role at all: had
// they been declared authored, capture would have written the target's own
// encryption key into state/ without a single warning.
$highEntropy = 'Xk7Qz2Lp9Rt4Wm8Nb3Vc6Yh1Jd5Fg0S';
wprism_check_same('credential-shaped value', Secrets::clearance_match_deep('__wpdm_password', $highEntropy), 'the package password does carry a credential key role');
wprism_check_same(null, Secrets::clearance_match_deep('__wpdm_enc_key', $highEntropy), 'the encryption key does NOT trip the automatic screen');
wprism_check_same(null, Secrets::clearance_match_deep('__wpdm_masterkey', $highEntropy), 'the per-package master key does NOT trip the automatic screen either');

$authoredOptions = $policy->authored_options();
foreach (['__wpdm_enc_key', '__wpdm_cron_key', '__wpdm_masterkey', '_wpdm_file_browser_root', '__wpdm_blocked_ips'] as $name) {
    wprism_check(!isset($authoredOptions[$name]), "'$name' is excluded from the captured option set outright, not merely screened");
}

// ---- page references and the sentinel that makes them safe ------------------

// All four settings dropdowns set option_none_value to the empty string, which
// (int)-casts to the engine's durable-zero unset contract rather than to a
// negative sentinel. Every WPDM reader of these already applies that same
// zero, so the reference survives the round trip in both states.
foreach (['__wpdm_author_dashboard', '__wpdm_author_profile', '__wpdm_login_url', '__wpdm_register_url', '__wpdm_user_dashboard'] as $name) {
    $rule = $policy->option_rule($name);
    wprism_check_same('authored', $rule['class'], "option '$name' carries its page choice across environments");
    wprism_check_same('post', $rule['ref'], "and resolves it as a post reference");
}
// The one page reference that is NOT bound: its metabox control omits
// option_none_value, so WordPress stores -1 rather than an empty string.
wprism_check_same(null, $policy->post_meta_rule('__wpdm_terms_page')['ref'] ?? null, 'the -1-sentinel page reference is deliberately left unbound');

// ---- residue this release reads or prunes but never authors -----------------

foreach (['__wpdm_cat_desc', '__wpdm_cat_img', '__wpdm_cat_tb', '__wpdmcategory', '_wpdm_etpl'] as $name) {
    wprism_check_same('env', $policy->option_rule($name)['class'], "'$name' has no writer in this release and is not carried as intent");
}
wprism_check_same('authored', $policy->option_rule('__wpdm_mask_link')['class'], 'the paired hidden/checkbox setting always writes one of its two states');
foreach (['__wpdm_ui_download_button', '__wpdm_ui_download_button_sc'] as $name) {
    wprism_check_same(true, $policy->option_rule($name)['plain_data'], "'$name' stores a member map, not a scalar");
}

// ---- counts and toggles are declared non-references --------------------------

// The lint's bare_id class fires when an authored number happens to resolve to
// a real entity on THAT environment, so an undeclared toggle is a per-site
// coincidence rather than a stable verdict. The live conformance fixture proved
// it: terms_lock=1 and the four pagestyle counts each collided with a real post
// id and failed the sweep's hard lint gate. These keys are counts, limits and
// 0/1 flags by the plugin's own reader (PackageController::metaData types quota
// and download_limit_per_user 'int'), so the non-reference semantics are
// declared once here instead of depending on how many posts a site has.
foreach ([
    '__wpdm_captcha_lock', '__wpdm_download_limit_per_user', '__wpdm_individual_file_download',
    '__wpdm_password_lock', '__wpdm_private', '__wpdm_quota', '__wpdm_terms_lock',
] as $key) {
    wprism_check_same(true, $policy->post_meta_rule($key)['lint_ok'] ?? false, "post meta '$key' is a declared non-reference number");
}
wprism_check_same(true, $policy->term_meta_rule('__wpdm_pagestyle')['lint_ok'] ?? false, 'the per-term layout map is a declared non-reference');
wprism_check_same(true, $policy->option_rule('__wpdm_cpage')['lint_ok'] ?? false, 'and so is the global layout map it overrides, which carries the identical member shape');

// The exemption is scoped: a key that really does resolve an entity must never
// carry it, or the lint would stop protecting the references that matter.
foreach (['__wpdm_additional_previews', '__wpdm_terms_page'] as $key) {
    wprism_check_same(false, $policy->post_meta_rule($key)['lint_ok'] ?? false, "'$key' resolves entities and keeps full lint scrutiny");
}

// ---- the two open namespaces -----------------------------------------------

// wpdm-core.php:347 mints one option per notified plugin/version pair.
wprism_check_same('runtime', $policy->option_rule('__wpdm_' . md5('Download Manager3.3.68'))['class'], 'update-notice markers match the md5 pattern');
wprism_check_same(null, $policy->option_rule('__wpdm_1a2b3c4d5e6f70819293a4b5c6d7e8'), 'a 31-hex name is not an md5 marker and stays unclassified');
// Templates.php:84 writes one option per email template id.
wprism_check_same('authored', $policy->option_rule('__wpdm_etpl_default')['class'], 'email templates match the etpl pattern');
wprism_check_same(null, $policy->option_rule('__wpdm_etpl_'), 'the bare prefix is not a template id');
// User.php:177 appends a sanitized shortcode id to the key.
wprism_check_same('runtime', $policy->post_meta_rule('__wpdm_users_params')['class'], 'the unsuffixed render cache matches');
wprism_check_same('runtime', $policy->post_meta_rule('__wpdm_users_paramssc-1_a')['class'], 'so does a suffixed one, over the exact [^a-zA-Z0-9_-] filter User.php:187 applies');

// An exact rule must beat every pattern, or a reviewed decision could be
// silently overridden by a family match.
wprism_check_same('env', $policy->option_rule('__wpdm_enc_key')['class'], 'an exact rule outranks the option patterns');

// ---- everything else stays loud --------------------------------------------

foreach (['__wpdm_category', '__wpdm_favs', '__wpdm_pro_only_field'] as $key) {
    wprism_check_same(null, $policy->post_meta_rule($key), "undeclared post meta '$key' is unclassified, so capture aborts rather than guessing");
}
wprism_check_same(null, $policy->term_meta_rule('__wpdm_unreviewed'), 'undeclared term meta is unclassified too');
wprism_check_same(null, $policy->option_rule('__wpdm_addon_setting'), 'an add-on option outside both patterns is simply not captured');
wprism_check_same(null, $policy->user_meta_rule('__wpdm_public_profile'), 'no user metadata is claimed by this capsule');

// ---- runtime tables --------------------------------------------------------

foreach ([
    'ahm_asset_links', 'ahm_assets', 'ahm_cron_jobs', 'ahm_download_stats',
    'ahm_emails', 'ahm_sessions', 'ahm_social_conns', 'ahm_user_download_counts',
] as $table) {
    wprism_check_same('runtime', $policy->table_rule($table)['class'], "$table stays outside the portable row set");
}
wprism_check_same(null, $policy->table_rule('ahm_orders'), 'ActivityReport.php:429 reads a Pro-only table this capsule does not own');

// ---- embeds ----------------------------------------------------------------

$shortcodes = $policy->shortcode_attr_rules();
foreach (['wpdm_package', 'wpdm_direct_link', 'wpdm_changelog'] as $tag) {
    wprism_check_same([['kind' => 'post', 'path' => 'id']], $shortcodes[$tag], "[$tag id=...] resolves a package post reference");
}
foreach (['wpdm_packages', 'wpdm_all_packages', 'wpdm_category', 'wpdm_user_dashboard'] as $tag) {
    wprism_check(!isset($shortcodes[$tag]), "[$tag] carries no declared id attribute and is not claimed");
}

// Widget id_bases are derived, never declared: every WPDM widget calls
// parent::__construct(false, ...), so WP_Widget lowercases the class name.
$widgets = $policy->widget_types();
foreach (['catpackages', 'listpackages', 'wpdm_newdownloads', 'wpdm_search', 'wpdm_topdownloads'] as $type) {
    wprism_check(isset($widgets[$type]), "widget '$type' is keyed by its derived id_base");
}
foreach (['wpdm_categories', 'wpdm_tags', 'packageinfo'] as $type) {
    wprism_check(!isset($widgets[$type]), "widget '$type' stores an unexercised shape and is deliberately unclaimed");
}
wprism_check_same('term', $widgets['catpackages']['settings']['scat']['ref'], 'the category selector is a wpdmcategory term reference');
wprism_check_same('post', $widgets['wpdm_search']['settings']['result_page']['ref'], 'the search result page is a page reference from wp_dropdown_pages');

// ---- declared plain-data shapes decode as plain data ------------------------

// The exact eighteen-colour default Installer.php:180-182 writes.
$uiColors = 'a:18:{s:7:"primary";s:7:"#4a8eff";s:13:"primary_hover";s:7:"#5998ff";s:14:"primary_active";s:7:"#3281ff";'
    . 's:9:"secondary";s:7:"#6c757d";s:15:"secondary_hover";s:7:"#6c757d";s:16:"secondary_active";s:7:"#6c757d";'
    . 's:4:"info";s:7:"#2CA8FF";s:10:"info_hover";s:7:"#2CA8FF";s:11:"info_active";s:7:"#2CA8FF";'
    . 's:7:"success";s:7:"#018e11";s:13:"success_hover";s:7:"#0aad01";s:14:"success_active";s:7:"#0c8c01";'
    . 's:7:"warning";s:7:"#FFB236";s:13:"warning_hover";s:7:"#FFB236";s:14:"warning_active";s:7:"#FFB236";'
    . 's:6:"danger";s:7:"#ff5062";s:12:"danger_hover";s:7:"#ff5062";s:13:"danger_active";s:7:"#ff5062";}';
$decoded = PlainData::decode($uiColors, 'option __wpdm_ui_colors');
PlainData::assert($decoded, 'option __wpdm_ui_colors');
wprism_check_same(18, count($decoded), 'the seeded colour map is exactly the eighteen scalars the settings form posts');
wprism_check_same('#4a8eff', $decoded['primary'], 'and decodes to the shipped default');
wprism_check_same(true, $policy->option_rule('__wpdm_ui_colors')['plain_data'], 'so the rule declares it plain data rather than an opaque blob');

// __wpdm_files is a flat list of names; __wpdm_access a flat list of roles.
$files = PlainData::decode(serialize(['handbook.pdf', 'https://cdn.example.test/mirror/kit.zip']), 'post_meta __wpdm_files');
PlainData::assert($files, 'post_meta __wpdm_files');
wprism_check_same(['handbook.pdf', 'https://cdn.example.test/mirror/kit.zip'], $files, 'savePackage stores upload-relative names and absolute URLs side by side');

wprism_check_summary('regress_download_manager_classification_boundary');
