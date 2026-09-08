<?php
declare(strict_types=1);

/**
 * The capsule's classification decisions, asserted through the product
 * accessors rather than by re-reading the manifest it is testing.
 *
 * Hustle is the first capsule here whose authored content lives entirely in
 * custom tables: Hustle_Db::get_tables() (inc/class-hustle-db.php:225-267)
 * creates hustle_modules for the module row and hustle_modules_meta for its
 * content/design/settings, beside three runtime tables that hold visitor
 * submissions and counters. What this suite protects is that boundary.
 */

$root = dirname(__DIR__, 4);
require_once $root . '/sandbox/tests/lib/check.php';
require_once $root . '/tools/src/AdapterPackageValidator.php';
require_once $root . '/agent/src/Adapter/IdentityNamespaces.php';

use WPrism\AdapterLibrary;
use WPrism\IdentityNamespaces;
use WPrism\Policy;
use WPrism\Tooling\AdapterPackageValidator;

AdapterPackageValidator::validate($root, 'wordpress-popup');
$policy = Policy::load(null, ['core', 'wordpress-popup'], adapterLibrary: AdapterLibrary::fromSourcePackage($root, 'wordpress-popup'));

// ---- the authored table pair ------------------------------------------------

$modules = $policy->table_rule('hustle_modules');
wprism_check_same('authored_snapshot', $modules['class'], 'the module row is the authored entity, since no post type carries it');
wprism_check_same('module_id', $modules['pk'], 'its primary key is the auto-increment the shortcode also addresses');
wprism_check_same('hustle_module', $modules['id_kind'], 'and it mints its own identity keyspace');

$meta = $policy->table_rule('hustle_modules_meta');
wprism_check_same('authored_snapshot_meta', $meta['class'], 'module settings are attached meta, not columns');
wprism_check_same(['table' => 'hustle_modules', 'column' => 'module_id'], $meta['attached_to'], 'attached to the module row by its own foreign key');
wprism_check_same('meta_key', $meta['key_column'], 'keyed by the plugin schema key column');
wprism_check_same('meta_value', $meta['value_column'], 'valued by its longtext column');

// A new id_kind is a permanent floor entry, not a value that may simply appear
// in a manifest (IdentityNamespaces' own header states the rule).
wprism_check(IdentityNamespaces::is_grandfathered_id_kind('hustle_module'), 'the new id_kind is recorded in the reviewed permanent floor');

// ---- carried authored intent ------------------------------------------------

foreach (['content', 'design', 'display', 'settings', 'visibility', 'emails', 'edit_roles', 'shortcode_id'] as $key) {
    wprism_check_same('authored', $meta['keys'][$key]['class'], "module meta '$key' is portable authoring");
}
wprism_check_same('authored', $modules['columns']['module_name']['class'], 'the module name is authored');
wprism_check_same('authored', $modules['columns']['module_type']['class'], 'so is its type');
wprism_check_same('authored', $modules['columns']['module_mode']['class'], 'and its mode');

// ---- withheld: submissions, counters, credentials, environment --------------

foreach (['hustle_entries', 'hustle_entries_meta', 'hustle_tracking'] as $table) {
    wprism_check_same('runtime', $policy->table_rule($table)['class'], "$table holds visitor data or counters and stays outside the portable row set");
}
wprism_check_same('env', $meta['keys']['integrations_settings']['class'], 'per-module provider credentials never become authored configuration');
wprism_check_same('env', $modules['columns']['blog_id']['class'], 'the multisite blog id is environment-local');
foreach (['track_types', 'hustle_shares', 'hustle_timestamp', 'hustle_dismissed_notifications', 'hustle_unsubscribe_nonces', 'visibility_backup_40x'] as $key) {
    wprism_check_same('runtime', $meta['keys'][$key]['class'], "module meta '$key' is runtime bookkeeping");
}
wprism_check_same('runtime', $meta['default_class'], 'an undeclared module meta key defaults to runtime rather than being carried by accident');

// ---- options ----------------------------------------------------------------

wprism_check_same('authored', $policy->option_rule('hustle_settings')['class'], 'the global settings blob is portable');
wprism_check_same(true, $policy->option_rule('hustle_settings')['plain_data'], 'and is declared plain data, not an opaque scalar');
wprism_check_same('authored', $policy->option_rule('hustle_custom_palettes')['class'], 'operator-authored palettes travel with it');
wprism_check_same('env', $policy->option_rule('hustle_unsubscribe_page')['class'], 'the stored absolute permalink stays target-local');
wprism_check_same('runtime', $policy->option_rule('hustle_database_version')['class'], 'the schema bookkeeping version is never portable intent');
foreach (['hustle_global_email_settings', 'hustle_provider_aweber_settings', 'hustle_opt-in-constant_contact-token'] as $name) {
    wprism_check_same('env', $policy->option_rule($name)['class'], "option '$name' is target-local credential material");
}
// The provider family is open: every integration adds its own option.
wprism_check_same('env', $policy->option_rule('hustle_provider_mailchimp_settings')['class'], 'an undeclared provider option still matches the credential pattern');
wprism_check_same(null, $policy->option_rule('hustle_provider_settings'), 'but the pattern requires a provider slug rather than matching the bare prefix');
wprism_check_same(null, $policy->option_rule('newsletter_customfields'), 'an unprefixed name this plugin writes but does not own is deliberately unclaimed');

// ---- embeds ------------------------------------------------------------------

$shortcodes = $policy->shortcode_attr_rules();
foreach (['wd_hustle', 'wd_hustle_cc', 'wd_hustle_ss'] as $tag) {
    wprism_check_same([['kind' => 'hustle_module', 'path' => 'id']], $shortcodes[$tag], "[$tag id=...] resolves a module reference in the custom-table keyspace");
}
wprism_check(!isset($shortcodes['wd_hustle_unsubscribe']), 'the unsubscribe shortcode takes no module id and is not claimed');

// The widget setting that CANNOT be declared: widget_setting_refs admits only
// term and post, so a hustle_module id has no expressible reference form. It is
// carried verbatim and marked non-reference rather than mis-declared as a post.
$widget = $policy->widget_types()['hustle_module_widget'];
wprism_check_same('authored', $widget['settings']['module_id']['class'], 'the widget module id is carried');
wprism_check_same(true, $widget['settings']['module_id']['lint_ok'], 'but declared a non-reference, because no custom-table widget ref exists');
wprism_check(!isset($widget['settings']['module_id']['ref']), 'and is never mis-declared as a post or term reference');

wprism_check_summary('regress_wordpress_popup_module_boundary');
