<?php
declare(strict_types=1);

/** Exact TEC 6.17.2/6.17.3 schema, identity, and refusal boundary. */

if (!defined('DUO_SPEC_VERSION')) {
    define('DUO_SPEC_VERSION', 2);
}

require_once __DIR__ . '/../../lib/check.php';
require_once __DIR__ . '/../../../../agent/src/Kernel/Canon.php';
require_once __DIR__ . '/../../../../agent/src/Policy/Policy.php';
require_once __DIR__ . '/../../../../agent/src/Adapter/Providers.php';
require_once __DIR__ . '/../../../../manifests/interpreters/the-events-calendar.php';
require_once __DIR__ . '/../../../../manifests/providers/the-events-calendar-category-colors.php';
require_once __DIR__ . '/../../../../manifests/regenerators/the-events-calendar.php';

use Duo\Interpreters\TheEventsCalendar;
use Duo\Policy;
use Duo\Providers\TheEventsCalendarCategoryColors;
use Duo\Regenerators\TheEventsCalendar as TheEventsCalendarRegenerator;

const TEC_EVENT_UUID = '11111111-1111-4111-8111-111111111111';
const TEC_VENUE_UUID = '22222222-2222-4222-8222-222222222222';
const TEC_ORGANIZER_UUID = '33333333-3333-4333-8333-333333333333';
const TEC_CATEGORY_UUID = '44444444-4444-4444-8444-444444444444';

final class TecReadinessNativeColor {
    public function __construct(private string $value) {
        if (preg_match('/^#[0-9a-f]{6}$/iD', $value) !== 1) {
            throw new InvalidArgumentException('invalid native test color');
        }
    }

    public function get_hex_with_hash(): string {
        return strtolower($this->value);
    }
}

final class TecReadinessNativeMarker {}

final class TecReadinessCategoryColorController {
    public function generate_css(): void {
        ++$GLOBALS['tec_readiness_cache_busts'];
        $GLOBALS['tec_readiness_options']['tec_events_category_color_css'] =
            $GLOBALS['tec_readiness_generated_css'];
        $GLOBALS['tec_readiness_dropdown_rows'] = $GLOBALS['tec_readiness_generated_dropdown_rows'];
    }
}

final class TecReadinessCategoryColorDropdown {
    /** @return mixed */
    public function get_dropdown_categories(): mixed {
        return $GLOBALS['tec_readiness_dropdown_rows'];
    }
}

class_alias(TecReadinessNativeColor::class, 'Tribe__Utils__Color');
foreach ([
    'TEC\\Events\\Category_Colors\\CSS\\Controller',
    'TEC\\Events\\Category_Colors\\CSS\\Generator',
    'TEC\\Events\\Category_Colors\\Repositories\\Category_Color_Dropdown_Provider',
] as $tecReadinessNativeClass) {
    class_alias(TecReadinessNativeMarker::class, $tecReadinessNativeClass);
}

/** @return mixed */
function get_option(string $name, mixed $default = false): mixed {
    return $GLOBALS['tec_readiness_options'][$name] ?? $default;
}

/** @return mixed */
function get_term_meta(int $termId, string $key, bool $single = false): mixed {
    return $GLOBALS['tec_readiness_term_meta'][$termId][$key] ?? ($single ? '' : []);
}

/** @return list<object> */
function get_terms(array $args = []): array {
    return $GLOBALS['tec_readiness_terms'];
}

function is_wp_error(mixed $value): bool {
    return false;
}

function sanitize_html_class(string $class): string {
    return preg_replace('/[^A-Za-z0-9_-]/', '', $class) ?? '';
}

function sanitize_title(string $title): string {
    return strtolower($title);
}

function tribe(string $class): object {
    return $class === 'TEC\\Events\\Category_Colors\\Repositories\\Category_Color_Dropdown_Provider'
        ? $GLOBALS['tec_readiness_category_color_dropdown']
        : $GLOBALS['tec_readiness_category_color_controller'];
}

function tribe_get_option(string $name, mixed $default = false): mixed {
    return $GLOBALS['tec_readiness_tribe_options'][$name] ?? $default;
}

/** @return array<string,mixed> */
function tec_readiness_meta(): array {
    return [
        '_EventDuration' => '10800',
        '_EventEndDate' => '2026-09-05 20:00:00',
        '_EventEndDateUTC' => '2026-09-05 14:15:00',
        '_EventOrganizerID' => '{{post:' . TEC_ORGANIZER_UUID . '}}',
        '_EventOrigin' => 'events-calendar',
        '_EventShowMap' => '1',
        '_EventShowMapLink' => '1',
        '_EventStartDate' => '2026-09-05 17:00:00',
        '_EventStartDateUTC' => '2026-09-05 11:15:00',
        '_EventTimezone' => 'Asia/Kathmandu',
        '_EventTimezoneAbbr' => '+0545',
        '_EventVenueID' => '{{post:' . TEC_VENUE_UUID . '}}',
    ];
}

/** @return array<string,mixed> */
function tec_readiness_post(string $uuid, string $type, array $meta = [], string $slug = ''): array {
    return [
        'type' => 'post',
        'path' => "state/posts/$type/$uuid--" . ($slug !== '' ? $slug : $type) . '.md',
        'data' => [
            'type' => $type,
            'uuid' => $uuid,
            'slug' => $slug !== '' ? $slug : $type,
            'meta' => $meta,
        ],
        'body' => str_repeat('Long UTF-8 event boundary — বাংলা — こんにちは. ', 600),
    ];
}

/** @return array<string,mixed> */
function tec_readiness_term(array $meta): array {
    return [
        'type' => 'term',
        'path' => 'state/terms/tribe_events_cat/' . TEC_CATEGORY_UUID . '--readiness.json',
        'data' => [
            'taxonomy' => 'tribe_events_cat',
            'uuid' => TEC_CATEGORY_UUID,
            'slug' => 'readiness',
            'name' => 'Readiness',
            'meta' => $meta,
        ],
    ];
}

/** @return list<array<string,mixed>> */
function tec_readiness_tree(?array $meta = null, ?array $termMeta = null): array {
    return [
        tec_readiness_post(TEC_EVENT_UUID, 'tribe_events', $meta ?? tec_readiness_meta(), 'production-readiness-event'),
        tec_readiness_post(TEC_VENUE_UUID, 'tribe_venue', [], 'readiness-hall'),
        tec_readiness_post(TEC_ORGANIZER_UUID, 'tribe_organizer', [], 'readiness-team'),
        tec_readiness_term($termMeta ?? [
            'tec-events-cat-colors-primary' => '#123abc',
            'tec-events-cat-colors-secondary' => '#abcdef',
            'tec-events-cat-colors-text' => '#ffffff',
            'tec-events-cat-colors-priority' => '17',
            'tec-events-cat-colors-hidden' => '0',
        ]),
    ];
}

/** @return list<string> */
function tec_readiness_messages(TheEventsCalendar $interpreter, array $tree): array {
    return array_map(
        static fn(array $diagnostic): string => (string) ($diagnostic['message'] ?? ''),
        $interpreter->repository_diagnostics($tree)
    );
}

function tec_readiness_refuses(TheEventsCalendar $interpreter, array $tree, string $needle, string $message): void {
    duo_check(
        str_contains(implode(' | ', tec_readiness_messages($interpreter, $tree)), $needle),
        $message
    );
}

$root = dirname(__DIR__, 4);
$manifest = json_decode(
    (string) file_get_contents($root . '/manifests/the-events-calendar.json'),
    true,
    flags: JSON_THROW_ON_ERROR
);
$artifacts = json_decode(
    (string) file_get_contents($root . '/sandbox/conformance/artifacts.lock.json'),
    true,
    flags: JSON_THROW_ON_ERROR
)['plugins']['the-events-calendar'];

duo_check_same(
    ['min' => '6.17.2', 'max' => '6.17.3.1'],
    $manifest['version_range'],
    'the exclusive range admits exactly the two reviewed published TEC releases'
);
duo_check_same(
    ['6.17.1', '6.17.2', '6.17.3'],
    array_keys($artifacts),
    'the artifact lock carries one real adjacent refusal and both exact boundaries'
);
duo_check_same('refusal-fixture', $artifacts['6.17.1']['role'], '6.17.1 is an adjacent refusal artifact');
duo_check_same('certified-boundary', $artifacts['6.17.2']['role'], '6.17.2 is the lower certified artifact');
duo_check_same('certified-boundary', $artifacts['6.17.3']['role'], '6.17.3 is the upper certified artifact');
duo_check_same(
    '2db436c929797bfc5311be942158c474716e61c2f289f7d05c3a08d29b2ad687',
    $artifacts['6.17.3']['sha256'],
    'the upper-bound official ZIP digest is immutable review input'
);

$policy = Policy::load(null, ['the-events-calendar']);
$interpreter = $policy->interpreters()['the-events-calendar'];
duo_check($interpreter instanceof TheEventsCalendar, 'the manifest resolves its digest-bound TEC interpreter');
duo_check_same([], $interpreter->repository_diagnostics(tec_readiness_tree()), 'a long UTF-8 timed event graph and category colors are schema-clean');

$unlinked = tec_readiness_meta();
unset($unlinked['_EventVenueID'], $unlinked['_EventOrganizerID']);
duo_check_same([], $interpreter->repository_diagnostics(tec_readiness_tree($unlinked)), 'legitimately absent venue and organizer references stay clean');

$allDay = tec_readiness_meta();
$allDay['_EventAllDay'] = 'yes';
$allDay['_EventStartDate'] = '2026-09-05 00:00:00';
$allDay['_EventEndDate'] = '2026-09-05 23:59:59';
$allDay['_EventStartDateUTC'] = '2026-09-04 18:15:00';
$allDay['_EventEndDateUTC'] = '2026-09-05 18:14:59';
$allDay['_EventDuration'] = '86399';
duo_check_same([], $interpreter->repository_diagnostics(tec_readiness_tree($allDay)), 'the exact all-day yes wire shape and day bounds are clean');

foreach (['_EventStartDate', '_EventEndDate', '_EventStartDateUTC', '_EventEndDateUTC', '_EventDuration', '_EventTimezone'] as $key) {
    $bad = tec_readiness_meta();
    unset($bad[$key]);
    tec_readiness_refuses($interpreter, tec_readiness_tree($bad), 'requires a non-empty string', "missing required $key refuses");
}

$bad = tec_readiness_meta();
$bad['_EventStartDate'] = '2026-02-30 17:00:00';
tec_readiness_refuses($interpreter, tec_readiness_tree($bad), 'exact real Y-m-d H:i:s', 'an impossible local date refuses');
$bad = tec_readiness_meta();
$bad['_EventTimezone'] = 'Asia/Not_A_Real_Zone';
tec_readiness_refuses($interpreter, tec_readiness_tree($bad), 'timezone identifier', 'an unknown event timezone refuses');
$bad = tec_readiness_meta();
$bad['_EventStartDateUTC'] = '2026-09-05 11:16:00';
tec_readiness_refuses($interpreter, tec_readiness_tree($bad), 'UTC start instants disagree', 'contradictory local/UTC start values refuse');
$bad = tec_readiness_meta();
$bad['_EventEndDate'] = '2026-09-05 16:00:00';
$bad['_EventEndDateUTC'] = '2026-09-05 10:15:00';
$bad['_EventDuration'] = '0';
tec_readiness_refuses($interpreter, tec_readiness_tree($bad), 'end must not precede', 'negative event intervals refuse');
foreach (['010800', '-1', '1.5', 'not-seconds'] as $duration) {
    $bad = tec_readiness_meta();
    $bad['_EventDuration'] = $duration;
    tec_readiness_refuses($interpreter, tec_readiness_tree($bad), 'canonical non-negative decimal', "malformed duration '$duration' refuses");
}
$bad = tec_readiness_meta();
$bad['_EventDuration'] = '10801';
tec_readiness_refuses($interpreter, tec_readiness_tree($bad), 'disagrees with the authored UTC interval', 'duration/date inconsistency refuses');

$bad = tec_readiness_meta();
$bad['_EventVenueID'] = '7000000001';
tec_readiness_refuses($interpreter, tec_readiness_tree($bad), 'canonical post UUID token', 'a huge raw local venue ID refuses');
$bad = tec_readiness_meta();
$bad['_EventVenueID'] = '{{post:' . TEC_ORGANIZER_UUID . '}}';
tec_readiness_refuses($interpreter, tec_readiness_tree($bad), 'post type tribe_venue', 'a venue reference resolving to an organizer refuses');
$bad = tec_readiness_meta();
$bad['_EventOrganizerID'] = '{{post:' . TEC_VENUE_UUID . '}}';
tec_readiness_refuses($interpreter, tec_readiness_tree($bad), 'post type tribe_organizer', 'an organizer reference resolving to a venue refuses');

foreach (['_EventShowMap', '_EventShowMapLink'] as $key) {
    $bad = tec_readiness_meta();
    $bad[$key] = 'yes';
    tec_readiness_refuses($interpreter, tec_readiness_tree($bad), 'exact 0/1 wire value', "$key rejects a non-native boolean spelling");
}
$bad = tec_readiness_meta();
$bad['_EventAllDay'] = 'true';
tec_readiness_refuses($interpreter, tec_readiness_tree($bad), 'exact yes wire value', 'all-day rejects a non-native boolean spelling');
$bad = tec_readiness_meta();
$bad['_tribe_featured'] = 'yes';
tec_readiness_refuses($interpreter, tec_readiness_tree($bad), 'exact 1 wire value', 'featured rejects a non-native boolean spelling');
$bad = tec_readiness_meta();
$bad['_EventCurrencyPosition'] = 'suffix';
tec_readiness_refuses($interpreter, tec_readiness_tree($bad), 'prefix or postfix', 'an unsupported currency position refuses');
$bad = tec_readiness_meta();
$bad['_EventRecurrence'] = ['rules' => [['type' => 'Every Week']]];
tec_readiness_refuses($interpreter, tec_readiness_tree($bad), 'Pro recurrence state', 'free-adapter recurrence state refuses before deploy');
$bad = tec_readiness_meta();
$bad['_EventCost'] = ['serialized' => 'future schema'];
tec_readiness_refuses($interpreter, tec_readiness_tree($bad), 'one scalar string', 'structured event cost refuses instead of being serialized into native meta');
$linkedTree = tec_readiness_tree();
$linkedTree[1]['data']['meta']['_VenueURL'] = (object) ['url' => 'https://invalid.example.test'];
tec_readiness_refuses($interpreter, $linkedTree, 'one scalar string', 'structured venue metadata refuses before native code consumes it');

foreach ([
    ['tec-events-cat-colors-primary', 'red', 'six-digit hex color'],
    ['tec-events-cat-colors-priority', '-1', 'canonical non-negative decimal'],
    ['tec-events-cat-colors-hidden', 'yes', 'empty/0/1 wire value'],
] as [$key, $value, $needle]) {
    $termMeta = [
        'tec-events-cat-colors-primary' => '#123abc',
        'tec-events-cat-colors-priority' => '7',
        'tec-events-cat-colors-hidden' => '0',
    ];
    $termMeta[$key] = $value;
    tec_readiness_refuses($interpreter, tec_readiness_tree(null, $termMeta), $needle, "malformed category color field $key refuses");
}

$eventMetaKeys = [
    '_EventAllDay', '_EventCost', '_EventCostMax', '_EventCostMin', '_EventCurrencyCode',
    '_EventCurrencyPosition', '_EventCurrencySymbol', '_EventDuration', '_EventEndDate',
    '_EventEndDateUTC', '_EventHideFromUpcoming', '_EventOrganizerID', '_EventOrigin', '_EventPhone',
    '_EventShowMap', '_EventShowMapLink', '_EventStartDate', '_EventStartDateUTC', '_EventTimezone',
    '_EventTimezoneAbbr', '_EventURL', '_EventVenueID', '_tribe_featured',
];
foreach ($eventMetaKeys as $key) {
    duo_check_same('authored', $policy->post_meta_rule($key)['class'] ?? null, "$key is reviewed authored TEC state");
}
foreach (['_tribe_events_errors', '_tribe_modified_fields'] as $key) {
    duo_check_same('runtime', $policy->post_meta_rule($key)['class'] ?? null, "$key remains target-runtime state");
}
foreach (['_VenueURL', '_VenueProvince', '_VenueShowMap', '_VenueShowMapLink', '_OrganizerWebsite'] as $key) {
    duo_check_same('authored', $policy->post_meta_rule($key)['class'] ?? null, "$key closes the free venue/organizer API surface");
}
foreach (['_tribe_featured', '_VenueShowMap', '_VenueShowMapLink'] as $key) {
    duo_check_same(
        true,
        $policy->post_meta_rule($key)['lint_ok'] ?? null,
        "$key is an explicitly reviewed boolean rather than a coincidental local post reference"
    );
}

$options = $policy->option_rule('tribe_events_calendar_options');
duo_check_same('env', $options['class'] ?? null, 'the mixed TEC option remains target-owned as a whole');
duo_check_same('preserve', $options['autoload'] ?? null, 'the mixed TEC option preserves live autoload semantics');
foreach (['eventsSlug', 'tribeEnableViews', 'category-color-enable-frontend', 'tec_seo_out_of_range_behavior'] as $key) {
    duo_check_same('authored', $options['sub_keys'][$key]['class'] ?? null, "$key is one reviewed portable setting sub-key");
}
duo_check_same('post', $options['sub_keys']['eventsDefaultVenueID']['ref'] ?? null, 'the default venue setting rewrites through the post ledger');
duo_check_same('post', $options['sub_keys']['eventsDefaultOrganizerID']['ref'] ?? null, 'the default organizer setting rewrites through the post ledger');
foreach (['google_maps_js_api_key', 'eb_security_key', 'meetup_api_key', 'fb_token', 'schema-version', 'earliest_date', 'trash-past-events'] as $key) {
    duo_check(!isset($options['sub_keys'][$key]), "$key remains target-owned rather than leaking or replaying integration/runtime state");
}
duo_check_same(
    'derived',
    $policy->option_rule('tec_events_category_color_css')['class'] ?? null,
    'native Category Colors CSS is regenerated rather than captured as authored state'
);
$rewriteActions = $policy->actions_for(['option:tribe_events_calendar_options']);
duo_check_same(2, count($rewriteActions), 'changing portable TEC settings selects rewrite and dropdown-cache repairs');
$nativeRewriteActions = array_values(array_filter(
    $rewriteActions,
    static fn(array $action): bool => ($action['kind'] ?? null) === 'native'
));
$optionColorActions = array_values(array_filter(
    $rewriteActions,
    static fn(array $action): bool => ($action['kind'] ?? null) === 'provider'
));
duo_check_same(1, count($nativeRewriteActions), 'portable TEC settings select exactly one native rewrite action');
duo_check_same('rewrite.flush', $nativeRewriteActions[0]['action'] ?? null, 'TEC uses the closed engine-owned soft rewrite flush');
duo_check_same(1, count($optionColorActions), 'portable TEC settings select exactly one Category Colors cache repair');
duo_check_same(
    'the-events-calendar-category-colors',
    $optionColorActions[0]['provider'] ?? null,
    'the portable show-hidden setting cannot leave the native dropdown cache stale'
);
duo_check_same([], $policy->actions_for(['post:tribe_events']), 'event-only writes do not trigger an unrelated global rewrite flush');
$colorActions = $policy->actions_for(['term:tribe_events_cat']);
duo_check_same(1, count($colorActions), 'an event-category write selects one bounded native CSS repair');
duo_check_same('provider', $colorActions[0]['kind'] ?? null, 'Category Colors repair uses a structured provider action');
duo_check_same(
    'the-events-calendar-category-colors',
    $colorActions[0]['provider'] ?? null,
    'the Category Colors action binds the digest-owned provider identity'
);
duo_check_same('regenerate_css', $colorActions[0]['capability'] ?? null, 'the action selects only native CSS regeneration');
duo_check_same(
    [
        ['id' => 'tec-category-colors-css', 'kind' => 'database', 'mode' => 'restorable', 'selector' => [
            'scope' => 'database_checkpoint', 'type' => 'option', 'value' => 'tec_events_category_color_css',
        ]],
        ['id' => 'tec-category-colors-dropdown-cache', 'kind' => 'cache', 'mode' => 'irreversible', 'selector' => [
            'scope' => 'external', 'type' => 'provider_resource',
            'value' => 'the-events-calendar-category-colors:v1:dropdown-cache',
        ]],
    ],
    $colorActions[0]['effects'] ?? null,
    'the generated option is rollback-restorable while TEC owns its external dropdown cache'
);

$providerDeclarations = $policy->provider_declarations();
$colorDeclaration = $providerDeclarations['the-events-calendar-category-colors'] ?? null;
duo_check_same(
    [
        'functions' => [
            'get_option',
            'get_term_meta',
            'get_terms',
            'is_wp_error',
            'sanitize_html_class',
            'sanitize_title',
            'tribe',
            'tribe_get_option',
        ],
        'classes' => [
            'TEC\\Events\\Category_Colors\\CSS\\Controller',
            'TEC\\Events\\Category_Colors\\CSS\\Generator',
            'TEC\\Events\\Category_Colors\\Repositories\\Category_Color_Dropdown_Provider',
            'Tribe__Utils__Color',
        ],
    ],
    $colorDeclaration['requires'] ?? null,
    'provider negotiation refuses before mutation when the exact 6.17.x native CSS path disappears'
);

$GLOBALS['tec_readiness_options'] = ['tec_events_category_color_css' => '.tribe_events_cat-readiness{--tec-color-category-primary:#000000}'];
$GLOBALS['tec_readiness_terms'] = [
    (object) ['term_id' => 71, 'slug' => 'readiness'],
    (object) ['term_id' => 72, 'slug' => 'plain-category'],
];
$GLOBALS['tec_readiness_term_meta'] = [
    71 => [
        'tec-events-cat-colors-primary' => '#123ABC',
        'tec-events-cat-colors-secondary' => '#fedcba',
        'tec-events-cat-colors-text' => '#ffffff',
        'tec-events-cat-colors-priority' => '17',
        'tec-events-cat-colors-hidden' => '0',
    ],
    72 => [],
];
$GLOBALS['tec_readiness_generated_css'] = '.tribe_events_cat-readiness{'
    . '--tec-color-category-primary:#123abc;'
    . '--tec-color-category-secondary:#fedcba;'
    . '--tec-color-category-text:#ffffff}';
$GLOBALS['tec_readiness_generated_dropdown_rows'] = [[
    'slug' => 'readiness',
    'name' => 'Readiness',
    'priority' => 17,
    'primary' => '#123ABC',
    'hidden' => false,
]];
$GLOBALS['tec_readiness_dropdown_rows'] = [[
    'slug' => 'readiness',
    'name' => 'Readiness',
    'priority' => 99,
    'primary' => '#000000',
    'hidden' => false,
]];
$GLOBALS['tec_readiness_tribe_options'] = ['category-color-show-hidden-categories' => false];
$GLOBALS['tec_readiness_cache_busts'] = 0;
$GLOBALS['tec_readiness_category_color_controller'] = new TecReadinessCategoryColorController();
$GLOBALS['tec_readiness_category_color_dropdown'] = new TecReadinessCategoryColorDropdown();

$colorProvider = new TheEventsCalendarCategoryColors($policy);
duo_check_same(
    [
        'id' => 'the-events-calendar-category-colors',
        'plugin' => 'the-events-calendar/the-events-calendar.php',
        'version' => '1.0.0',
    ],
    $colorProvider->identity(),
    'the executable provider identity matches the manifest declaration exactly'
);
$colorCapability = $colorProvider->capabilities()['regenerate_css'] ?? null;
duo_check_same('site', $colorCapability['scope'] ?? null, 'native CSS regeneration is honestly site-scoped');
duo_check_same(true, $colorCapability['idempotent'] ?? null, 'native CSS regeneration declares idempotence');
duo_check_same(120, $colorCapability['timeout_seconds'] ?? null, 'the native CSS/category scan has a bounded large-taxonomy timeout claim');
$firstColorReceipt = $colorProvider->invoke('regenerate_css', []);
duo_check_same(true, $firstColorReceipt['verified'] ?? null, 'native CSS regeneration returns a verified structured receipt');
duo_check_same(1, $GLOBALS['tec_readiness_cache_busts'], 'the provider invokes TEC native controller semantics including cache busting');
duo_check_same(0, $firstColorReceipt['after']['css_selector_mismatch_count'] ?? null, 'readback rejects missing or orphan native selectors');
duo_check_same(0, $firstColorReceipt['after']['css_value_mismatch_count'] ?? null, 'readback carries every native selector color value');
duo_check_same(0, $firstColorReceipt['after']['dropdown_mismatch_count'] ?? null, 'readback proves the native dropdown cache matches live category metadata');
duo_check_same(
    $firstColorReceipt['after']['dropdown_expected_sha256'] ?? null,
    $firstColorReceipt['after']['dropdown_actual_sha256'] ?? null,
    'the native dropdown projection has an exact digest-level postcondition'
);
duo_check_same(1, $firstColorReceipt['after']['colored_category_count'] ?? null, 'the receipt is bounded to counts and digests, not authored payload');
duo_check(
    !str_contains(json_encode($firstColorReceipt, JSON_UNESCAPED_SLASHES), 'readiness')
        && !str_contains(json_encode($firstColorReceipt, JSON_UNESCAPED_SLASHES), '#123'),
    'the provider receipt contains no authored slug or color payload'
);
duo_check(
    ($firstColorReceipt['before']['css_sha256'] ?? null) !== ($firstColorReceipt['after']['css_sha256'] ?? null),
    'a hostile stale generated option visibly converges in the receipt'
);
$secondColorReceipt = $colorProvider->invoke('regenerate_css', []);
duo_check_same(
    $firstColorReceipt['after']['css_sha256'] ?? null,
    $secondColorReceipt['after']['css_sha256'] ?? null,
    'a retry is idempotent at the generated CSS projection'
);
$operation = ['format' => 'duo-provider-operation/v1', 'id' => 'tec-offline-reconcile'];
$reconciled = $colorProvider->reconcile_scoped('regenerate_css', [], $operation);
duo_check_same($operation, $reconciled['operation'] ?? null, 'reconciliation binds its caller-supplied operation envelope');
duo_check_same(true, $reconciled['verified'] ?? null, 'reconciliation verifies without replaying the native write');
$GLOBALS['tec_readiness_options']['tec_events_category_color_css'] =
    '.tribe_events_cat-readiness{--tec-color-category-primary:#123abc}';
duo_check_throws(
    static fn() => $colorProvider->reconcile_scoped('regenerate_css', [], $operation),
    RuntimeException::class,
    'reconciliation refuses stale partial native CSS rather than certifying an ambiguous effect',
    'recovery_required'
);
$GLOBALS['tec_readiness_options']['tec_events_category_color_css'] = $GLOBALS['tec_readiness_generated_css'];
$GLOBALS['tec_readiness_term_meta'][71]['tec-events-cat-colors-secondary'] = '';
duo_check_throws(
    static fn() => $colorProvider->reconcile_scoped('regenerate_css', [], $operation),
    RuntimeException::class,
    'reconciliation refuses a stale recognized property removed from live category metadata',
    'value mismatch'
);
$GLOBALS['tec_readiness_term_meta'][71]['tec-events-cat-colors-secondary'] = '#fedcba';
$GLOBALS['tec_readiness_options']['tec_events_category_color_css'] = str_replace(
    '--tec-color-category-secondary:',
    '--tec-color-category-primary:#123abc;--tec-color-category-secondary:',
    $GLOBALS['tec_readiness_generated_css']
);
duo_check_throws(
    static fn() => $colorProvider->reconcile_scoped('regenerate_css', [], $operation),
    RuntimeException::class,
    'reconciliation refuses a duplicate recognized property even when both values are current',
    'value mismatch'
);
$GLOBALS['tec_readiness_options']['tec_events_category_color_css'] = str_replace(
    '}',
    ';background-color:transparent}',
    $GLOBALS['tec_readiness_generated_css']
);
duo_check_same(
    true,
    $colorProvider->reconcile_scoped('regenerate_css', [], $operation)['verified'] ?? null,
    'reconciliation preserves unrelated CSS declarations outside TEC category color properties'
);
$GLOBALS['tec_readiness_options']['tec_events_category_color_css'] = $GLOBALS['tec_readiness_generated_css'];
$GLOBALS['tec_readiness_options']['tec_events_category_color_css'] .=
    '.tribe_events_cat-orphan{--tec-color-category-primary:#111111}';
duo_check_throws(
    static fn() => $colorProvider->reconcile_scoped('regenerate_css', [], $operation),
    RuntimeException::class,
    'reconciliation refuses an orphan generated CSS selector',
    'selector-set mismatch'
);
$GLOBALS['tec_readiness_options']['tec_events_category_color_css'] = $GLOBALS['tec_readiness_generated_css'];
$GLOBALS['tec_readiness_dropdown_rows'] = [];
duo_check_throws(
    static fn() => $colorProvider->reconcile_scoped('regenerate_css', [], $operation),
    RuntimeException::class,
    'reconciliation refuses a missing native dropdown row',
    'dropdown readback'
);
$GLOBALS['tec_readiness_dropdown_rows'] = $GLOBALS['tec_readiness_generated_dropdown_rows'];
$GLOBALS['tec_readiness_dropdown_rows'][0]['primary'] = '#000000';
duo_check_throws(
    static fn() => $colorProvider->reconcile_scoped('regenerate_css', [], $operation),
    RuntimeException::class,
    'reconciliation refuses a stale native dropdown color',
    'dropdown readback'
);
$GLOBALS['tec_readiness_dropdown_rows'] = $GLOBALS['tec_readiness_generated_dropdown_rows'];
$GLOBALS['tec_readiness_dropdown_rows'][] = [
    'slug' => 'orphan',
    'name' => 'Orphan',
    'priority' => 9,
    'primary' => '#111111',
    'hidden' => false,
];
duo_check_throws(
    static fn() => $colorProvider->reconcile_scoped('regenerate_css', [], $operation),
    RuntimeException::class,
    'reconciliation refuses an orphan native dropdown row',
    'dropdown readback'
);
$GLOBALS['tec_readiness_dropdown_rows'] = $GLOBALS['tec_readiness_generated_dropdown_rows'];
duo_check_same(2, $GLOBALS['tec_readiness_cache_busts'], 'reconciliation probes never replay the native mutation');
duo_check_throws(
    static fn() => $colorProvider->invoke('invented_capability', []),
    RuntimeException::class,
    'the provider capability surface is closed',
    'does not implement capability'
);

duo_check(in_array('tribe_events', $policy->declared_post_types(), true), 'events are in adapter post scope');
duo_check(in_array('tribe_venue', $policy->declared_post_types(), true), 'venues are in adapter post scope');
duo_check(in_array('tribe_organizer', $policy->declared_post_types(), true), 'organizers are in adapter post scope');
duo_check_same('authored', $policy->taxonomy_rule_details('tribe_events_cat')['rule']['class'] ?? null, 'event categories are authored taxonomy state');
foreach (['primary', 'secondary', 'text', 'priority', 'hidden'] as $suffix) {
    duo_check_same('authored', $policy->term_meta_rule('tec-events-cat-colors-' . $suffix)['class'] ?? null, "category color $suffix is authored term metadata");
}
duo_check_same('derived', $policy->table_rule('tec_events')['class'] ?? null, 'tec_events is derived rather than duplicated authored state');
duo_check_same('derived', $policy->table_rule('tec_occurrences')['class'] ?? null, 'tec_occurrences is derived and regenerated');
duo_check_same('runtime', $policy->table_rule('tec_kv_cache')['class'] ?? null, 'tec_kv_cache remains target-runtime state');

$regenerator = new TheEventsCalendarRegenerator($policy);
duo_check_throws(
    static fn() => $regenerator->regenerate_batch([], [[
        'identity' => 'post:' . TEC_EVENT_UUID,
        'local_id' => 77,
        'post_type' => 'tribe_events',
    ]]),
    RuntimeException::class,
    'TEC deletion context refuses before plugin code or database mutation',
    'deletion regeneration is unsupported'
);

duo_check_summary('The Events Calendar production-readiness contract');
