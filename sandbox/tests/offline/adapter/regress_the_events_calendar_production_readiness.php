<?php
declare(strict_types=1);

/** Exact TEC 6.17.2/6.17.3 schema, identity, and refusal boundary. */

if (!defined('DUO_SPEC_VERSION')) {
    define('DUO_SPEC_VERSION', 2);
}

require_once __DIR__ . '/../../lib/check.php';
require_once __DIR__ . '/../../../../agent/src/Kernel/Canon.php';
require_once __DIR__ . '/../../../../agent/src/Policy/Policy.php';
require_once __DIR__ . '/../../../../manifests/interpreters/the-events-calendar.php';
require_once __DIR__ . '/../../../../manifests/regenerators/the-events-calendar.php';

use Duo\Interpreters\TheEventsCalendar;
use Duo\Policy;
use Duo\Regenerators\TheEventsCalendar as TheEventsCalendarRegenerator;

const TEC_EVENT_UUID = '11111111-1111-4111-8111-111111111111';
const TEC_VENUE_UUID = '22222222-2222-4222-8222-222222222222';
const TEC_ORGANIZER_UUID = '33333333-3333-4333-8333-333333333333';
const TEC_CATEGORY_UUID = '44444444-4444-4444-8444-444444444444';

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
$rewriteActions = $policy->actions_for(['option:tribe_events_calendar_options']);
duo_check_same(1, count($rewriteActions), 'changing portable TEC settings selects one bounded rewrite repair');
duo_check_same('rewrite.flush', $rewriteActions[0]['action'] ?? null, 'TEC uses the closed engine-owned soft rewrite flush');
duo_check_same([], $policy->actions_for(['post:tribe_events']), 'event-only writes do not trigger an unrelated global rewrite flush');

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
