<?php
declare(strict_types=1);

// Actual candidate invoke/SDK/transaction mechanism, with supplied native
// responses over the shared core store. Not Policy-loaded or real-plugin proof.
$root = dirname(__DIR__, 4);
require_once $root . '/sandbox/tests/lib/check.php';
require_once $root . '/sandbox/tests/lib/native_permalink_stubs.php';
require_once $root . '/sandbox/tests/lib/FakeWpdb.php';
require_once $root . '/agent/src/Adapter/ProviderSdk.php';
require_once dirname(__DIR__, 2) . '/fixtures/location-provider/wpforms-form-locations.php';
require_once dirname(__DIR__, 2) . '/fixtures/location-provider/locator-responses.php';

use WPrism\DatabaseQueryIsolation;
use WPrism\Db;
use WPrism\Providers;
use WPrism\Providers\WpformsFormLocations;
use WPrismTest\FakeWpdb;
use WPrismTest\WpStore;

final class LocationCandidateApp {
    public WPForms\Forms\Locator $locator;
    public function __construct() { $this->locator = new WPForms\Forms\Locator(); }
    public function obj(string $name): object {
        if ($name !== 'locator') throw new LogicException('unexpected service in candidate mechanism');
        return $this->locator;
    }
}
function wpforms(): LocationCandidateApp { return $GLOBALS['location_candidate_app']; }
function wp_unslash(mixed $value): mixed {
    if (is_array($value)) return array_map('wp_unslash', $value);
    return is_string($value) ? stripslashes($value) : $value;
}

function location_input_post(int $id, string $type, string $content): array {
    return ['ID' => $id, 'post_author' => 0, 'post_date' => '2026-09-08 01:02:03', 'post_date_gmt' => '2026-09-08 01:02:03',
        'post_content' => $content, 'post_title' => 'Fixture ' . $id, 'post_excerpt' => '', 'post_status' => 'publish',
        'comment_status' => 'closed', 'ping_status' => 'closed', 'post_password' => '', 'post_name' => 'fixture-' . $id,
        'to_ping' => '', 'pinged' => '', 'post_modified' => '2026-09-08 01:02:03', 'post_modified_gmt' => '2026-09-08 01:02:03',
        'post_content_filtered' => '', 'post_parent' => 0, 'guid' => '', 'menu_order' => 0, 'post_type' => $type,
        'post_mime_type' => '', 'comment_count' => '0'];
}

function location_input_fixture(array $settings = [], int $placements = 0, ?array $standalone = null): array {
    Db::forget_transaction_tracking();
    WpStore::reset();
    $GLOBALS['wp_filter'] = [];
    $GLOBALS['wp_object_cache'] = new WP_Object_Cache();
    $GLOBALS['native_option_external_cache'] = $GLOBALS['native_option_installing'] = false;
    $GLOBALS['location_candidate_app'] = new LocationCandidateApp();
    $locator = wpforms()->locator;
    $locator->standaloneLocations = $standalone === null ? [] : [1 => $standalone];
    $GLOBALS['wp_post_types'] = [];
    foreach (['post', 'page', 'wpforms'] as $name) {
        $type = new WP_Post_Type();
        $type->name = $name;
        $type->_builtin = $name !== 'wpforms';
        $GLOBALS['wp_post_types'][$name] = $type;
    }
    $GLOBALS['wp_post_statuses'] = ['publish' => (object) ['internal' => false, 'protected' => false, 'private' => false,
        'publicly_queryable' => true, '_builtin' => true, 'public' => true]];
    unset($GLOBALS['post'], $_SERVER['HTTPS'], $_SERVER['SERVER_PORT']);
    $posts = [location_input_post(1, 'wpforms', json_encode(['id' => 1, 'settings' => $settings], JSON_THROW_ON_ERROR))];
    for ($id = 2; $id <= $placements + 1; $id++) {
        $content = 'supplied-response-' . $id;
        $posts[] = location_input_post($id, 'page', $content);
        $locator->formsByContent[$content] = [1];
    }
    $db = FakeWpdb::install()->enableInformationSchema()->seedTable('posts', $posts)
        ->setColumns('posts', array_fill_keys(array_keys($posts[0]), 'text'))->setTableEngine('posts', 'InnoDB');
    $options = [];
    foreach (['home' => 'http://current.invalid/base', 'permalink_structure' => '/%postname%/',
        'show_on_front' => 'posts', 'page_on_front' => '0'] as $name => $value) {
        $options[] = ['option_id' => count($options) + 1, 'option_name' => $name, 'option_value' => $value, 'autoload' => 'on'];
    }
    $db->seedTable('options', $options)->setTableEngine('options', 'InnoDB');
    foreach (['postmeta' => ['meta_id', 'post_id', 'meta_key', 'meta_value'],
        'terms' => ['term_id', 'name', 'slug', 'term_group'],
        'term_taxonomy' => ['term_taxonomy_id', 'term_id', 'taxonomy', 'description', 'parent', 'count'],
        'term_relationships' => ['object_id', 'term_taxonomy_id', 'term_order'],
        'termmeta' => ['meta_id', 'term_id', 'meta_key', 'meta_value'],
        'users' => ['ID', 'user_login', 'user_pass', 'user_nicename', 'user_email', 'user_url', 'user_registered',
            'user_activation_key', 'user_status', 'display_name'],
        'usermeta' => ['umeta_id', 'user_id', 'meta_key', 'meta_value']] as $table => $columns) {
        $db->seedTable($table, [])->setColumns($table, array_fill_keys($columns, 'text'))->setTableEngine($table, 'InnoDB');
    }
    $GLOBALS['wp_rewrite'] = new WP_Rewrite();
    $runtime = new WpformsFormLocations(['source' => 'manifest', 'id' => 'wpforms-location-inputs', 'plugin' => 'wpforms-lite/wpforms.php',
        'version' => '1.0.0', 'capabilities' => ['rebuild_form_locations'], 'contracts' => ['rebuild_form_locations' => [
            'args' => [], 'idempotent' => true, 'scope' => 'site', 'timeout_seconds' => 120,
            'reads' => ['table:posts', 'table:options', 'table:terms', 'table:term_taxonomy', 'table:term_relationships',
                'table:termmeta', 'table:users', 'table:usermeta'], 'writes' => ['table:postmeta'],
        ]]]);
    // Fixture-only loader join; no manifest/disposition advertises this candidate.
    (new ReflectionMethod(Providers::class, 'bind_manifest_runtime_contracts'))->invoke(null, $runtime, $runtime->capabilities());
    return [$db, $runtime, $locator];
}

function location_input_reject(FakeWpdb $db, WpformsFormLocations $runtime, string $label, string $message): void {
    $tables = ['posts', 'postmeta', 'options', 'terms', 'term_taxonomy', 'term_relationships', 'termmeta', 'users', 'usermeta'];
    $before = array_map($db->rows(...), $tables);
    $db->resetLog();
    wprism_check_throws(static fn() => $runtime->invoke('rebuild_form_locations', []), RuntimeException::class, $label, $message);
    wprism_check_same($before, array_map($db->rows(...), $tables), $label . ': every complete physical table survives');
    wprism_check_same([], array_values(array_filter($db->queries(), static fn(string $sql): bool => preg_match('/^(INSERT|UPDATE|DELETE|REPLACE)\b/i', $sql) === 1)),
        $label . ': no DML precedes complete native admission');
    wprism_check(!DatabaseQueryIsolation::is_active(), $label . ': transaction owner settles isolation');
}

function location_input_standalone(string $suffix, string $title = 'Standalone fixture', string $type = 'form_pages'): array {
    return ['type' => $type, 'title' => $title, 'form_id' => 1, 'id' => 1, 'status' => 'publish', 'url' => $suffix];
}

foreach (['form_pages', 'conversational_forms'] as $type) {
    foreach (['safe-slug', '東京', '%E6%9D%B1%E4%BA%AC', '%41%7a%30%2d%5f%7e'] as $slug) {
        $location = location_input_standalone('/' . $slug . '/', type: $type);
        [$db, $runtime, $locator] = location_input_fixture([$type . '_enable' => true, $type . '_page_slug' => $slug], standalone: $location);
        $result = $runtime->invoke('rebuild_form_locations', []);
        wprism_check($result['verified'], 'actual candidate/SDK accepts renderer-safe standalone output: ' . $type . '/' . $slug);
        wprism_check_same(serialize([$location]), $db->rows('postmeta')[0]['meta_value'], 'exact native standalone bytes are stored');
        wprism_check_same(2, $locator->standaloneBuilds, 'same-process second pass reruns the supplied native builder');
        wprism_check_same(1, count(array_filter($db->queries(), static fn(string $sql): bool => str_starts_with($sql, 'INSERT INTO'))),
            'fixed point keeps its physical meta identity without a second DML');
    }
}
foreach ([['form_pages_enable' => true, 'form_pages_title' => null, 'form_pages_page_slug' => null],
    ['form_pages_enable' => true, 'conversational_forms_enable' => true,
        'conversational_forms_title' => [], 'conversational_forms_page_slug' => []]] as $settings) {
    $location = location_input_standalone('//', title: '');
    [$db, $runtime] = location_input_fixture($settings, standalone: $location);
    wprism_check($runtime->invoke('rebuild_form_locations', [])['verified'], 'native null defaults and first-enabled precedence are preserved');
    wprism_check_same(serialize([$location]), $db->rows('postmeta')[0]['meta_value'], 'unconsumed standalone settings do not invent refusal authority');
}
foreach (['a+b', 'a%3Fb', 'a%23tail', 'a%22b', 'a%27b', 'a%252Fb', '../outside', '%2e%2e/outside', 'a%2Fb', 'a%5Cb', 'a%00b'] as $slug) {
    [$db, $runtime, $locator] = location_input_fixture(['form_pages_enable' => true, 'form_pages_page_slug' => $slug],
        standalone: location_input_standalone('/' . $slug . '/'));
    location_input_reject($db, $runtime, 'standalone renderer/path hazard: ' . $slug, 'frontier');
}
foreach (['form_pages', 'conversational_forms'] as $type) {
    foreach (['title', 'page_slug'] as $field) {
        foreach ([[], false, 7, str_repeat('x', 262145)] as $value) {
            [$db, $runtime, $locator] = location_input_fixture([$type . '_enable' => true, $type . '_' . $field => $value],
                standalone: location_input_standalone('/safe/', type: $type));
            location_input_reject($db, $runtime, 'malformed standalone source is refused before native allocation: ' . $type . '/' . $field, 'native location text');
            wprism_check_same(0, $locator->standaloneBuilds, 'malformed standalone fields never reach the public builder');
        }
    }
}
[$db, $runtime, $locator] = location_input_fixture(placements: 128);
wprism_check($runtime->invoke('rebuild_form_locations', [])['verified'], 'exact capsule placement budget is admitted through the candidate');
wprism_check_same(256, $locator->postScans, '128 accepted placements are scanned once per reconstruction pass');
[$db, $runtime, $locator] = location_input_fixture(placements: 130);
location_input_reject($db, $runtime, 'placement overflow stops native scanner work', 'placement population');
wprism_check_same(129, $locator->postScans, 'the overflow refusal precedes scanning the next placement');

wprism_check_summary('wpforms_location_native_inputs');
