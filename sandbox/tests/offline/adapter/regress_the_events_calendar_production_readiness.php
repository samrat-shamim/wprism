<?php
declare(strict_types=1);

/** Exact TEC 6.17.2/6.17.3 schema, identity, and refusal boundary. */

if (!defined('DUO_SPEC_VERSION')) {
    define('DUO_SPEC_VERSION', 2);
}

require_once __DIR__ . '/../../lib/check.php';
require_once __DIR__ . '/../../lib/FakeWpdb.php';
require_once __DIR__ . '/../../support/wp-block-parser-stub.php';
require_once __DIR__ . '/../../../../agent/src/Kernel/Canon.php';
require_once __DIR__ . '/../../../../agent/src/Policy/Policy.php';
require_once __DIR__ . '/../../../../agent/src/Adapter/Providers.php';
require_once __DIR__ . '/../../../../agent/src/Capture/EntityMetaCapture.php';
require_once __DIR__ . '/../../../../manifests/interpreters/the-events-calendar.php';
require_once __DIR__ . '/../../../../manifests/providers/the-events-calendar-category-colors.php';
require_once __DIR__ . '/../../../../manifests/regenerators/the-events-calendar.php';

use Duo\Interpreters\TheEventsCalendar;
use Duo\EntityMetaCapture;
use Duo\Policy;
use Duo\Providers\TheEventsCalendarCategoryColors;
use Duo\Regenerators\TheEventsCalendar as TheEventsCalendarRegenerator;
use DuoTest\FakeWpdb;

const TEC_EVENT_UUID = '11111111-1111-4111-8111-111111111111';
const TEC_VENUE_UUID = '22222222-2222-4222-8222-222222222222';
const TEC_ORGANIZER_UUID = '33333333-3333-4333-8333-333333333333';
const TEC_ORGANIZER_TWO_UUID = '55555555-5555-4555-8555-555555555555';
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
        ++$GLOBALS['tec_readiness_color_controller_calls'];
        tec_readiness_set_css($GLOBALS['tec_readiness_generated_css']);
        $mode = $GLOBALS['tec_readiness_color_controller_mode'] ?? '';
        if ($mode === 'throw_after_css') {
            $GLOBALS['tec_readiness_color_controller_mode'] = '';
            throw new RuntimeException('injected native Category Colors failure after CSS write');
        }
        $GLOBALS['tec_readiness_dropdown_rows'] = $GLOBALS['tec_readiness_generated_dropdown_rows'];
        ++$GLOBALS['tec_readiness_cache_busts'];
        if ($mode === 'throw_after_cache_bust') {
            $GLOBALS['tec_readiness_color_controller_mode'] = '';
            throw new RuntimeException('injected native Category Colors failure after cache bust');
        }
    }
}

final class TecReadinessCategoryColorDropdown {
    /** @return mixed */
    public function get_dropdown_categories(): mixed {
        return $GLOBALS['tec_readiness_dropdown_rows'];
    }
}

final class TecReadinessEventModel {
    public mixed $event_id;
    public int $post_id;
    public string $start_date;
    public string $end_date;
    public string $start_date_utc;
    public string $end_date_utc;
    public string $timezone;
    public string $duration;

    /** @param array<string,mixed> $row */
    public function __construct(array $row) {
        $this->event_id = $row['event_id'] ?? null;
        $this->post_id = (int) ($row['post_id'] ?? 0);
        $this->start_date = (string) ($row['start_date'] ?? '');
        $this->end_date = (string) ($row['end_date'] ?? '');
        $this->start_date_utc = (string) ($row['start_date_utc'] ?? '');
        $this->end_date_utc = (string) ($row['end_date_utc'] ?? '');
        $this->timezone = (string) ($row['timezone'] ?? '');
        $this->duration = (string) ($row['duration'] ?? '');
    }

    /** @return mixed */
    public static function data_from_post(int $postId): mixed {
        if (($GLOBALS['tec_readiness_regen_mode'] ?? '') === 'non_array_data') {
            return 'credential-shaped-AKIAABCDEFGHIJKLMNOP';
        }
        $data = [
            'post_id' => $postId,
            'start_date' => get_post_meta($postId, '_EventStartDate', true),
            'end_date' => get_post_meta($postId, '_EventEndDate', true),
            'start_date_utc' => get_post_meta($postId, '_EventStartDateUTC', true),
            'end_date_utc' => get_post_meta($postId, '_EventEndDateUTC', true),
            'timezone' => get_post_meta($postId, '_EventTimezone', true),
            'duration' => get_post_meta($postId, '_EventDuration', true),
            'hash' => '',
        ];
        if ($data['duration'] === '' || $data['duration'] === '0') {
            $start = new DateTimeImmutable((string) $data['start_date_utc'], new DateTimeZone('UTC'));
            $end = new DateTimeImmutable((string) $data['end_date_utc'], new DateTimeZone('UTC'));
            $data['duration'] = (string) ($end->getTimestamp() - $start->getTimestamp());
        }
        if (($GLOBALS['tec_readiness_regen_mode'] ?? '') === 'filtered_event_timezone') {
            $data['timezone'] = 'UTC';
        }
        return $data;
    }

    /** @param list<string> $uniqueBy @param array<string,mixed>|null $data */
    public static function upsert(array $uniqueBy, ?array $data = null): int|false {
        if (($GLOBALS['tec_readiness_regen_mode'] ?? '') === 'upsert_false') {
            return false;
        }
        if ($uniqueBy !== ['post_id'] || $data === null) {
            throw new RuntimeException('unexpected fake TEC upsert contract');
        }
        /** @var FakeWpdb $wpdb */
        $wpdb = $GLOBALS['wpdb'];
        $table = $wpdb->prefix . 'tec_events';
        $eventId = 7000000001;
        foreach ($wpdb->rows($table) as $row) {
            if ((int) ($row['post_id'] ?? 0) === (int) $data['post_id']) {
                $eventId = (int) $row['event_id'];
                break;
            }
        }
        $wpdb->delete($table, ['post_id' => (int) $data['post_id']]);
        $row = ['event_id' => $eventId] + $data + ['updated_at' => '2026-08-24 00:00:00'];
        if (($GLOBALS['tec_readiness_regen_mode'] ?? '') === 'invalid_event_updated_at') {
            $row['updated_at'] = '2026-02-31 25:99:99';
        }
        if ($wpdb->insert($table, $row) === false) {
            return false;
        }
        $GLOBALS['tec_readiness_regen_calls'][] = (int) $data['post_id'];
        if (($GLOBALS['tec_readiness_regen_mode'] ?? '') === 'upsert_throw_after_write') {
            throw new RuntimeException('injected native event upsert failure after derived write');
        }
        return 1;
    }

    /** @return list<string> */
    public static function last_errors(): array {
        return [
            'hostile model error AKIAABCDEFGHIJKLMNOP',
            str_repeat('x', 4096),
        ];
    }

    public static function find(int $postId, string $column): ?self {
        if (($GLOBALS['tec_readiness_regen_mode'] ?? '') === 'find_missing') {
            return null;
        }
        if ($column !== 'post_id') {
            throw new RuntimeException('unexpected fake TEC find contract');
        }
        /** @var FakeWpdb $wpdb */
        $wpdb = $GLOBALS['wpdb'];
        foreach ($wpdb->rows($wpdb->prefix . 'tec_events') as $row) {
            if ((int) ($row['post_id'] ?? 0) === $postId) {
                if (($GLOBALS['tec_readiness_regen_mode'] ?? '') === 'invalid_event_id') {
                    $row['event_id'] = '7000000001';
                }
                return new self($row);
            }
        }
        return null;
    }

    public function occurrences(): TecReadinessOccurrenceSaver {
        return new TecReadinessOccurrenceSaver($this);
    }
}

final class TecReadinessOccurrenceSaver {
    public function __construct(private TecReadinessEventModel $event) {}

    public function save_occurrences(): void {
        /** @var FakeWpdb $wpdb */
        $wpdb = $GLOBALS['wpdb'];
        $table = $wpdb->prefix . 'tec_occurrences';
        $existingId = 8000000001;
        foreach ($wpdb->rows($table) as $row) {
            if ((int) ($row['post_id'] ?? 0) === $this->event->post_id) {
                $existingId = (int) $row['occurrence_id'];
                break;
            }
        }
        // Free TEC updates the first physical occurrence for this post and
        // does not silently erase hostile extra rows. Verification below is
        // what refuses that unsupported dirty shape.
        $wpdb->delete($table, ['occurrence_id' => $existingId]);
        $row = [
            'occurrence_id' => $existingId,
            'event_id' => $this->event->event_id,
            'post_id' => $this->event->post_id,
            'start_date' => $this->event->start_date,
            'end_date' => $this->event->end_date,
            'start_date_utc' => $this->event->start_date_utc,
            'end_date_utc' => $this->event->end_date_utc,
            'duration' => $this->event->duration,
            'updated_at' => '2026-08-24 00:00:01',
        ];
        $row['hash'] = sha1(implode(':', [
            $row['post_id'],
            $row['start_date'],
            $row['end_date'],
            $row['start_date_utc'],
            $row['end_date_utc'],
            $row['duration'],
        ]));
        $mode = $GLOBALS['tec_readiness_regen_mode'] ?? '';
        if ($mode === 'partial_occurrence') {
            $row['end_date_utc'] = '2001-01-01 00:00:00';
        } elseif ($mode === 'wrong_event_link') {
            $row['event_id'] = (int) $row['event_id'] + 1;
        } elseif ($mode === 'invalid_occurrence_id') {
            $row['occurrence_id'] = 0;
        } elseif ($mode === 'oversized_occurrence_id') {
            $row['occurrence_id'] = '18446744073709551616';
        } elseif ($mode === 'invalid_occurrence_updated_at') {
            $row['updated_at'] = '2026-02-31 25:99:99';
        } elseif ($mode === 'save_throw') {
            $row['end_date_utc'] = '2001-01-01 00:00:00';
        }
        $wpdb->insert($table, $row);
        if ($mode === 'orphan_extra') {
            $orphan = $row;
            $orphan['occurrence_id'] = 8000000002;
            $orphan['post_id'] = $this->event->post_id + 999;
            $wpdb->insert($table, $orphan);
        }
        if ($mode === 'save_throw') {
            throw new RuntimeException('injected native occurrence save failure');
        }
        if ($mode === 'stale_driver_error') {
            $wpdb->last_error = 'handled stale error AKIAABCDEFGHIJKLMNOP';
        }
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
class_alias(TecReadinessEventModel::class, 'TEC\Events\Custom_Tables\V1\Models\Event');

/** @return mixed */
function get_option(string $name, mixed $default = false): mixed {
    return $GLOBALS['tec_readiness_options'][$name] ?? $default;
}

/** @return mixed */
function get_term_meta(int $termId, string $key, bool $single = false): mixed {
    return $GLOBALS['tec_readiness_term_meta'][$termId][$key] ?? ($single ? '' : []);
}

/** @return mixed */
function get_post_meta(int $postId, string $key, bool $single = false): mixed {
    return $GLOBALS['tec_readiness_post_meta'][$postId][$key] ?? ($single ? '' : []);
}

/** @return list<object> */
function get_terms(array $args = []): array {
    return $GLOBALS['tec_readiness_terms'];
}

function has_filter(string $hookName, callable|false $callback = false): bool|int {
    return in_array($hookName, $GLOBALS['tec_readiness_filters'] ?? [], true);
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

function tec_readiness_set_css(string $css): void {
    $GLOBALS['tec_readiness_options']['tec_events_category_color_css'] = $css;
    $wpdb = $GLOBALS['wpdb'] ?? null;
    if ($wpdb instanceof FakeWpdb && $wpdb->hasTable($wpdb->options)) {
        $updated = $wpdb->update(
            $wpdb->options,
            ['option_value' => $css],
            ['option_name' => 'tec_events_category_color_css']
        );
        if ($updated === false) {
            throw new RuntimeException('offline Category Colors CSS row update failed');
        }
    }
}

/** @param list<array<string,mixed>> $extraRows */
function tec_readiness_seed_color_options(array $extraRows = []): void {
    $wpdb = $GLOBALS['wpdb'] ?? null;
    if (!$wpdb instanceof FakeWpdb) {
        throw new RuntimeException('offline Category Colors database is unavailable');
    }
    $wpdb->seedTable($wpdb->options, array_merge([[
        'option_id' => 1,
        'option_name' => 'tec_events_category_color_css',
        'option_value' => (string) ($GLOBALS['tec_readiness_options']['tec_events_category_color_css'] ?? ''),
        'autoload' => 'on',
    ]], $extraRows));
}

function tec_readiness_sync_color_db(): void {
    $wpdb = $GLOBALS['wpdb'] ?? null;
    if (!$wpdb instanceof FakeWpdb) {
        throw new RuntimeException('offline Category Colors database is unavailable');
    }
    $terms = [];
    $taxonomy = [];
    $meta = [];
    $metaId = 1;
    foreach ($GLOBALS['tec_readiness_terms'] ?? [] as $term) {
        $termId = (int) ($term->term_id ?? 0);
        $terms[] = [
            'term_id' => $termId,
            'slug' => (string) ($term->slug ?? ''),
            'name' => (string) ($term->name ?? ''),
        ];
        $taxonomy[] = [
            'term_taxonomy_id' => $termId,
            'term_id' => $termId,
            'taxonomy' => 'tribe_events_cat',
        ];
        foreach (($GLOBALS['tec_readiness_term_meta'][$termId] ?? []) as $key => $value) {
            $meta[] = [
                'meta_id' => $metaId++,
                'term_id' => $termId,
                'meta_key' => (string) $key,
                'meta_value' => (string) $value,
            ];
        }
    }
    $wpdb->seedTable($wpdb->terms, $terms);
    $wpdb->seedTable($wpdb->term_taxonomy, $taxonomy);
    $wpdb->seedTable($wpdb->termmeta, $meta);
    tec_readiness_seed_color_options();
}

/** @return array<string,mixed> */
function tec_readiness_meta(): array {
    return [
        '_EventCostDescription' => 'Admission details 東京 — bring ID',
        '_EventDateTimeSeparator' => ' · at · ',
        '_EventDuration' => '10800',
        '_EventEndDate' => '2026-09-05 20:00:00',
        '_EventEndDateUTC' => '2026-09-05 14:15:00',
        '_EventOrganizerID' => [
            '{{post:' . TEC_ORGANIZER_UUID . '}}',
            '{{post:' . TEC_ORGANIZER_TWO_UUID . '}}',
        ],
        '_EventOrigin' => 'events-calendar',
        '_EventShowMap' => '1',
        '_EventShowMapLink' => '1',
        '_EventStartDate' => '2026-09-05 17:00:00',
        '_EventStartDateUTC' => '2026-09-05 11:15:00',
        '_EventTimezone' => 'Asia/Kathmandu',
        '_EventTimezoneAbbr' => '+0545',
        '_EventTimeRangeSeparator' => ' · until · ',
        '_EventVenueID' => '{{post:' . TEC_VENUE_UUID . '}}',
    ];
}

/** @return array<string,mixed> */
function tec_readiness_post(
    string $uuid,
    string $type,
    array $meta = [],
    string $slug = '',
    ?string $body = null
): array {
    return [
        'type' => 'post',
        'path' => "state/posts/$type/$uuid--" . ($slug !== '' ? $slug : $type) . '.md',
        'data' => [
            'type' => $type,
            'uuid' => $uuid,
            'slug' => $slug !== '' ? $slug : $type,
            'meta' => $meta,
        ],
        'body' => $body ?? str_repeat('Long UTF-8 event boundary — বাংলা — こんにちは. ', 600),
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
function tec_readiness_tree(
    ?array $meta = null,
    ?array $termMeta = null,
    ?array $venueMeta = null,
    ?string $eventBody = null
): array {
    return [
        tec_readiness_post(
            TEC_EVENT_UUID,
            'tribe_events',
            $meta ?? tec_readiness_meta(),
            'production-readiness-event',
            $eventBody
        ),
        tec_readiness_post(TEC_VENUE_UUID, 'tribe_venue', $venueMeta ?? [
            '_EventShowMap' => 'false',
            '_EventShowMapLink' => 'false',
            '_VenueShowMap' => 'false',
            '_VenueShowMapLink' => 'false',
        ], 'readiness-hall'),
        tec_readiness_post(TEC_ORGANIZER_UUID, 'tribe_organizer', [], 'readiness-team'),
        tec_readiness_post(TEC_ORGANIZER_TWO_UUID, 'tribe_organizer', [], 'readiness-team-two'),
        tec_readiness_term($termMeta ?? [
            'tec-events-cat-colors-primary' => '#123abc',
            'tec-events-cat-colors-secondary' => '#abcdef',
            'tec-events-cat-colors-text' => '#ffffff',
            'tec-events-cat-colors-priority' => '17',
            'tec-events-cat-colors-hidden' => '0',
        ]),
    ];
}

/** @param list<string> $tokens */
function tec_readiness_organizer_blocks(array $tokens, bool $prependEmpty = false): string {
    $blocks = $prependEmpty ? ['<!-- wp:tribe/event-organizer /-->'] : [];
    foreach ($tokens as $token) {
        $blocks[] = '<!-- wp:tribe/event-organizer '
            . json_encode(['organizer' => $token], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
            . ' /-->';
    }
    return implode("\n", $blocks);
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
$disposition = json_decode(
    (string) file_get_contents($root . '/manifests/dispositions.json'),
    true,
    flags: JSON_THROW_ON_ERROR
)['manifests']['the-events-calendar'];

duo_check_same(
    ['min' => '6.17.2', 'max' => '6.17.4'],
    $manifest['version_range'],
    'the exclusive range admits the reviewed 6.17.2/6.17.3 artifact family without admitting 6.17.4'
);
duo_check_same(
    ['6.17.1', '6.17.2', '6.17.3'],
    array_keys($artifacts),
    'the artifact lock carries one real adjacent refusal and both exact boundaries'
);
duo_check_same('refusal-fixture', $artifacts['6.17.1']['role'], '6.17.1 is an adjacent refusal artifact');
duo_check_same('exercise-fixture', $artifacts['6.17.2']['role'], '6.17.2 remains a candidate exercise artifact until final evidence is green');
duo_check_same('exercise-fixture', $artifacts['6.17.3']['role'], '6.17.3 remains a candidate exercise artifact until final evidence is green');
duo_check_same(
    '2db436c929797bfc5311be942158c474716e61c2f289f7d05c3a08d29b2ad687',
    $artifacts['6.17.3']['sha256'],
    'the upper-bound official ZIP digest is immutable review input'
);
duo_check_same(
    ['post:tribe_events', 'post:tribe_organizer', 'post:tribe_venue', 'term:tribe_events_cat'],
    $disposition['capabilities']['deletion_semantics']['unsupported'] ?? null,
    'the reviewed claim names every free TEC entity whose native deletion effects remain unsupported'
);

$policy = Policy::load(null, ['the-events-calendar']);
$interpreter = $policy->interpreters()['the-events-calendar'];
duo_check($interpreter instanceof TheEventsCalendar, 'the manifest resolves its digest-bound TEC interpreter');
duo_check_same(
    [['kind' => 'post', 'path' => 'organizer', 'type' => 'int']],
    $policy->block_attr_rules()['tribe/event-organizer'] ?? null,
    'the shipped TEC policy declares the registered scalar organizer block reference exactly'
);
duo_check_same(
    [],
    $interpreter->repository_diagnostics(tec_readiness_tree()),
    'a classic-editor long UTF-8 timed event graph without organizer blocks remains schema-clean'
);

$unlinked = tec_readiness_meta();
unset($unlinked['_EventVenueID'], $unlinked['_EventOrganizerID']);
duo_check_same([], $interpreter->repository_diagnostics(tec_readiness_tree($unlinked)), 'legitimately absent venue and organizer references stay clean');

$singleOrganizer = tec_readiness_meta();
$singleOrganizer['_EventOrganizerID'] = ['{{post:' . TEC_ORGANIZER_UUID . '}}'];
duo_check_same(
    [],
    $interpreter->repository_diagnostics(tec_readiness_tree(
        $singleOrganizer,
        null,
        null,
        tec_readiness_organizer_blocks($singleOrganizer['_EventOrganizerID'])
    )),
    'one Gutenberg organizer block matches its one-row canonical metadata list'
);
$reorderedOrganizers = tec_readiness_meta();
$reorderedOrganizers['_EventOrganizerID'] = array_reverse($reorderedOrganizers['_EventOrganizerID']);
duo_check_same(
    [],
    $interpreter->repository_diagnostics(tec_readiness_tree(
        $reorderedOrganizers,
        null,
        null,
        tec_readiness_organizer_blocks($reorderedOrganizers['_EventOrganizerID'], true)
    )),
    'multiple unique organizer blocks preserve native physical order while an empty editor placeholder is harmless'
);
$emptyOrganizerMeta = tec_readiness_meta();
unset($emptyOrganizerMeta['_EventOrganizerID']);
duo_check_same(
    [],
    $interpreter->repository_diagnostics(tec_readiness_tree(
        $emptyOrganizerMeta,
        null,
        null,
        '<!-- wp:tribe/event-organizer /-->'
    )),
    'the registered empty Gutenberg organizer placeholder matches absent organizer metadata'
);
foreach ([
    'A literal wp:tribe/event-organizer marker is ordinary classic/freeform text.',
    'A literal <!-- wp:tribe/event-organizer text prefix is not a complete block comment.',
    '<!-- wp:code --><pre class="wp-block-code"><code>&lt;!-- wp:tribe/event-organizer /--&gt;</code></pre><!-- /wp:code -->',
    '<!-- wp:tribe/event-organizer-preview {"organizer":7000000001} /-->',
    '<!-- wp:block {"ref":"{{post:' . TEC_ORGANIZER_UUID . '}}"} /-->',
] as $nonOrganizerBody) {
    duo_check_same(
        [],
        $interpreter->repository_diagnostics(tec_readiness_tree(null, null, null, $nonOrganizerBody)),
        'literal/code, prefix-named, and reusable blocks are not mistaken for exact TEC organizer blocks'
    );
}
$nestedOrganizerBody = '<!-- wp:group --><div class="wp-block-group">'
    . tec_readiness_organizer_blocks(tec_readiness_meta()['_EventOrganizerID'])
    . '</div><!-- /wp:group -->';
duo_check_same(
    [],
    $interpreter->repository_diagnostics(tec_readiness_tree(null, null, null, $nestedOrganizerBody)),
    'nested organizer blocks remain coherent because their attributes and authoritative repeated rows agree despite the native top-level-only supplemental reorder scan'
);
$nestedReorderedMeta = tec_readiness_meta();
$nestedReorderedMeta['_EventOrganizerID'] = array_reverse($nestedReorderedMeta['_EventOrganizerID']);
tec_readiness_refuses(
    $interpreter,
    tec_readiness_tree($nestedReorderedMeta, null, null, $nestedOrganizerBody),
    'must exactly match _EventOrganizerID row order',
    'nested organizer blocks are inspected recursively and refuse when physical metadata order diverges'
);
$markerCounter = new ReflectionMethod($interpreter, 'organizer_block_marker_count');
$markerFloodCount = 2048;
$markerFlood = str_repeat('<!-- wp:tribe/event-organizer /-->', $markerFloodCount)
    . '<!-- wp:tribe/event-organizer literal incomplete tail '
    . str_repeat('x', 1024 * 1024);
$oldBacktrackLimit = ini_set('pcre.backtrack_limit', '1');
try {
    duo_check_same(
        $markerFloodCount,
        $markerCounter->invoke($interpreter, $markerFlood),
        'the exact linear marker counter handles many complete comments and a huge incomplete tail independently of PCRE limits'
    );
} finally {
    if ($oldBacktrackLimit !== false) {
        ini_set('pcre.backtrack_limit', $oldBacktrackLimit);
    }
}
$manyEmptyMeta = tec_readiness_meta();
unset($manyEmptyMeta['_EventOrganizerID']);
duo_check_same(
    [],
    $interpreter->repository_diagnostics(tec_readiness_tree(
        $manyEmptyMeta,
        null,
        null,
        str_repeat('<!-- wp:tribe/event-organizer /-->', 512)
    )),
    'very many complete empty organizer placeholders traverse the real parser without false mismatch or diagnostics'
);
$manyMalformed = $interpreter->repository_diagnostics(tec_readiness_tree(
    null,
    null,
    null,
    str_repeat('<!-- wp:tribe/event-organizer ??? -->', 512)
));
duo_check(
    count($manyMalformed) === 1
        && strlen((string) ($manyMalformed[0]['message'] ?? '')) < 160,
    'a hostile flood of malformed exact comments produces one bounded schema diagnostic'
);
$hostileParent = 'hostile/' . str_repeat('p', 8192);
$hostileNestedBody = '<!-- wp:' . $hostileParent . ' -->'
    . '<!-- wp:tribe/event-organizer {"organizer":0} /-->'
    . '<!-- /wp:' . $hostileParent . ' -->'
    . "\xc3\x28";
$hostileNestedDiagnostics = $interpreter->repository_diagnostics(tec_readiness_tree(
    null,
    null,
    null,
    $hostileNestedBody
));
duo_check(
    $hostileNestedDiagnostics !== []
        && !str_contains(json_encode($hostileNestedDiagnostics, JSON_THROW_ON_ERROR), $hostileParent)
        && strlen((string) ($hostileNestedDiagnostics[0]['locator'] ?? '')) < 128,
    'nested diagnostics use a fixed locator and never expose a huge authored parent name or invalid UTF-8 body bytes'
);

$emptyBlockWithMeta = '<!-- wp:tribe/event-organizer /-->';
tec_readiness_refuses(
    $interpreter,
    tec_readiness_tree(null, null, null, $emptyBlockWithMeta),
    'must exactly match _EventOrganizerID row order',
    'an empty-only organizer placeholder refuses when populated metadata would render differently'
);
$missingOrganizerUuid = '66666666-6666-4666-8666-666666666666';
$missingOrganizerMeta = tec_readiness_meta();
$missingOrganizerMeta['_EventOrganizerID'] = ['{{post:' . $missingOrganizerUuid . '}}'];
tec_readiness_refuses(
    $interpreter,
    tec_readiness_tree(
        $missingOrganizerMeta,
        null,
        null,
        tec_readiness_organizer_blocks($missingOrganizerMeta['_EventOrganizerID'])
    ),
    'UUID must resolve to one captured tribe_organizer post',
    'an organizer block UUID absent from the captured graph refuses'
);
tec_readiness_refuses(
    $interpreter,
    tec_readiness_tree(
        null,
        null,
        null,
        '<!-- wp:tribe/event-organizer ??? -->'
    ),
    'markup must parse as exact registered blocks',
    'a malformed exact organizer comment dropped by parse_blocks refuses rather than becoming freeform content'
);

$blockBoundaryCases = [
    [
        '<!-- wp:tribe/event-organizer {"organizer":0} /-->',
        tec_readiness_meta(),
        'canonical post UUID token',
        'a raw zero organizer default refuses because canonical capture must remove the attribute',
    ],
    [
        '<!-- wp:tribe/event-organizer {"organizer":7000000001} /-->',
        tec_readiness_meta(),
        'canonical post UUID token',
        'a huge raw local organizer block ID refuses after structural capture should have tokenized it',
    ],
    [
        '<!-- wp:tribe/event-organizer {"organizer":{"id":"{{post:' . TEC_ORGANIZER_UUID . '}}"}} /-->',
        tec_readiness_meta(),
        'canonical post UUID token',
        'a nested organizer attribute shape refuses rather than being cast to a local ID',
    ],
    [
        '<!-- wp:tribe/event-organizer {"organizers":["{{post:' . TEC_ORGANIZER_UUID . '}}"]} /-->',
        tec_readiness_meta(),
        'meta-sourced and must not be serialized',
        'the registered meta-sourced organizers list cannot leak into block content',
    ],
    [
        tec_readiness_organizer_blocks([
            '{{post:' . TEC_ORGANIZER_UUID . '}}',
            '{{post:' . TEC_ORGANIZER_UUID . '}}',
        ]),
        tec_readiness_meta(),
        'must be unique in editor order',
        'duplicate populated organizer blocks refuse before native array_unique can hide the defect',
    ],
    [
        tec_readiness_organizer_blocks(array_reverse(tec_readiness_meta()['_EventOrganizerID'])),
        tec_readiness_meta(),
        'must exactly match _EventOrganizerID row order',
        'reordered organizer blocks refuse when the repeated metadata order disagrees',
    ],
    [
        '<!-- wp:tribe/event-organizer {"organizer":"{{post:' . TEC_ORGANIZER_UUID . '}}"} -->'
            . '<!-- wp:paragraph --><p>not native organizer content</p><!-- /wp:paragraph -->'
            . '<!-- /wp:tribe/event-organizer -->',
        tec_readiness_meta(),
        'must not carry nested blocks',
        'nested content in the dynamic organizer block refuses',
    ],
];
foreach ($blockBoundaryCases as [$body, $meta, $needle, $message]) {
    tec_readiness_refuses($interpreter, tec_readiness_tree($meta, null, null, $body), $needle, $message);
}
$wrongBlockOwner = tec_readiness_meta();
$wrongBlockOwner['_EventOrganizerID'] = ['{{post:' . TEC_VENUE_UUID . '}}'];
tec_readiness_refuses(
    $interpreter,
    tec_readiness_tree(
        $wrongBlockOwner,
        null,
        null,
        tec_readiness_organizer_blocks($wrongBlockOwner['_EventOrganizerID'])
    ),
    'organizer block must resolve to post type tribe_organizer',
    'an organizer block resolving to a venue refuses independently of scalar token validity'
);

foreach ([
    ['canceled', 'Doors closed because of <strong>weather</strong>.'],
    ['postponed', ''],
] as [$status, $reason]) {
    $statusMeta = tec_readiness_meta();
    $statusMeta['_tribe_events_status'] = $status;
    $statusMeta['_tribe_events_status_reason'] = $reason;
    duo_check_same(
        [],
        $interpreter->repository_diagnostics(tec_readiness_tree($statusMeta)),
        "$status status accepts its native paired arbitrary-string reason shape"
    );
}
$statusDeleted = tec_readiness_meta();
unset($statusDeleted['_tribe_events_status'], $statusDeleted['_tribe_events_status_reason']);
duo_check_same(
    [],
    $interpreter->repository_diagnostics(tec_readiness_tree($statusDeleted)),
    'scheduled status and explicit status deletion both use native absence of both rows'
);

$optionalEditorMetaAbsent = tec_readiness_meta();
unset(
    $optionalEditorMetaAbsent['_EventCostDescription'],
    $optionalEditorMetaAbsent['_EventDateTimeSeparator'],
    $optionalEditorMetaAbsent['_EventTimeRangeSeparator']
);
duo_check_same(
    [],
    $interpreter->repository_diagnostics(tec_readiness_tree($optionalEditorMetaAbsent)),
    'absent or deleted optional Gutenberg-authored event metadata stays clean'
);
$optionalEditorMetaUpdated = tec_readiness_meta();
$optionalEditorMetaUpdated['_EventCostDescription'] = 'Updated plain description বাংলা';
$optionalEditorMetaUpdated['_EventDateTimeSeparator'] = "\nthrough\t";
$optionalEditorMetaUpdated['_EventTimeRangeSeparator'] = ' & through & ';
duo_check_same(
    [],
    $interpreter->repository_diagnostics(tec_readiness_tree($optionalEditorMetaUpdated)),
    'native-sanitized Gutenberg metadata accepts updates and separator whitespace'
);
$nativeFalseEvent = tec_readiness_meta();
$nativeFalseEvent['_EventShowMap'] = '';
$nativeFalseEvent['_EventShowMapLink'] = '';
duo_check_same(
    [],
    $interpreter->repository_diagnostics(tec_readiness_tree($nativeFalseEvent)),
    'event repository false uses exact empty postmeta values'
);
duo_check_same(
    [],
    $interpreter->repository_diagnostics(tec_readiness_tree(null, null, [
        '_VenueShowMap' => '',
        '_VenueShowMapLink' => '1',
    ])),
    'venue repository empty/1 map values and absent legacy mirrors stay clean'
);
duo_check_same(
    [],
    $interpreter->repository_diagnostics(tec_readiness_tree(null, null, [])),
    'repository venue map rows may be absent when the owning API does not write them'
);

$allDay = tec_readiness_meta();
$allDay['_EventAllDay'] = 'yes';
$allDay['_EventStartDate'] = '2026-09-05 00:00:00';
$allDay['_EventEndDate'] = '2026-09-05 23:59:59';
$allDay['_EventStartDateUTC'] = '2026-09-04 18:15:00';
$allDay['_EventEndDateUTC'] = '2026-09-05 18:14:59';
$allDay['_EventDuration'] = '86399';
duo_check_same([], $interpreter->repository_diagnostics(tec_readiness_tree($allDay)), 'the exact all-day yes wire shape and day bounds are clean');

$registeredAllDay = $allDay;
$registeredAllDay['_EventAllDay'] = '1';
duo_check_same(
    [],
    $interpreter->repository_diagnostics(tec_readiness_tree($registeredAllDay)),
    'the registered Gutenberg all-day true wire is clean'
);
foreach (['', 'no'] as $falseWire) {
    $timed = tec_readiness_meta();
    $timed['_EventAllDay'] = $falseWire;
    duo_check_same(
        [],
        $interpreter->repository_diagnostics(tec_readiness_tree($timed)),
        "the native all-day false wire '$falseWire' is clean"
    );
}
foreach (['', '1', 'yes'] as $hideWire) {
    $hidden = tec_readiness_meta();
    $hidden['_EventHideFromUpcoming'] = $hideWire;
    duo_check_same(
        [],
        $interpreter->repository_diagnostics(tec_readiness_tree($hidden)),
        "the native hide-from-upcoming wire '$hideWire' is clean"
    );
}

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
$bad['_EventOrganizerID'] = ['{{post:' . TEC_VENUE_UUID . '}}'];
tec_readiness_refuses($interpreter, tec_readiness_tree($bad), 'post type tribe_organizer', 'an organizer reference resolving to a venue refuses');
foreach ([
    ['{{post:' . TEC_ORGANIZER_UUID . '}}', 'ordered list'],
    [[], 'ordered list'],
    [['7000000001'], 'canonical post UUID token'],
    [[
        '{{post:' . TEC_ORGANIZER_UUID . '}}',
        '{{post:' . TEC_ORGANIZER_UUID . '}}',
    ], 'must be unique'],
] as [$badOrganizerRows, $needle]) {
    $bad = tec_readiness_meta();
    $bad['_EventOrganizerID'] = $badOrganizerRows;
    tec_readiness_refuses(
        $interpreter,
        tec_readiness_tree($bad),
        $needle,
        'malformed scalar, empty, raw-id, and duplicate organizer row shapes refuse before deploy'
    );
}

foreach (['scheduled', 'cancelled', '', 7] as $badStatus) {
    $bad = tec_readiness_meta();
    $bad['_tribe_events_status'] = $badStatus;
    $bad['_tribe_events_status_reason'] = 'reason';
    tec_readiness_refuses(
        $interpreter,
        tec_readiness_tree($bad),
        'scheduled is represented by absence',
        'unknown, scheduled-as-row, empty, and non-string event statuses refuse'
    );
}
$bad = tec_readiness_meta();
$bad['_tribe_events_status'] = 'canceled';
tec_readiness_refuses($interpreter, tec_readiness_tree($bad), 'present or absent together', 'status without a reason row refuses');
$bad = tec_readiness_meta();
$bad['_tribe_events_status_reason'] = 'orphan reason';
tec_readiness_refuses($interpreter, tec_readiness_tree($bad), 'present or absent together', 'reason without a status row refuses');
$bad = tec_readiness_meta();
$bad['_tribe_events_status'] = 'postponed';
$bad['_tribe_events_status_reason'] = ['not' => 'a string'];
tec_readiness_refuses($interpreter, tec_readiness_tree($bad), 'reason must remain one scalar string', 'structured status reason refuses');

foreach (['_EventShowMap', '_EventShowMapLink'] as $key) {
    foreach (['0', 'false', 'yes'] as $value) {
        $bad = tec_readiness_meta();
        $bad[$key] = $value;
        tec_readiness_refuses(
            $interpreter,
            tec_readiness_tree($bad),
            'exact empty/1 wire value',
            "$key rejects non-native event repository spelling '$value'"
        );
    }
}
foreach (['_EventShowMap', '_EventShowMapLink', '_VenueShowMap', '_VenueShowMapLink'] as $key) {
    foreach (['0', 'true', 'yes'] as $value) {
        $bad = tec_readiness_tree();
        $bad[1]['data']['meta'][$key] = $value;
        tec_readiness_refuses(
            $interpreter,
            $bad,
            'native empty/1/false wire value',
            "$key rejects non-native venue spelling '$value'"
        );
    }
}
foreach (['0', 'false', 'true', 1, false, true] as $value) {
    $bad = tec_readiness_meta();
    $bad['_EventAllDay'] = $value;
    tec_readiness_refuses(
        $interpreter,
        tec_readiness_tree($bad),
        'exact current empty/1/no/yes wire value',
        'all-day rejects non-native persisted boolean spellings and non-string values'
    );
}
foreach (['0', 'false', 'no', 'true', 1, false, true] as $value) {
    $bad = tec_readiness_meta();
    $bad['_EventHideFromUpcoming'] = $value;
    tec_readiness_refuses(
        $interpreter,
        tec_readiness_tree($bad),
        'exact current empty/1/yes wire value',
        'hide-from-upcoming rejects non-native persisted spellings and non-string values'
    );
}
$bad = tec_readiness_meta();
$bad['_tribe_featured'] = 'yes';
tec_readiness_refuses($interpreter, tec_readiness_tree($bad), 'exact 1 wire value', 'featured rejects a non-native boolean spelling');
$bad = tec_readiness_meta();
$bad['_EventCurrencyPosition'] = 'suffix';
tec_readiness_refuses($interpreter, tec_readiness_tree($bad), 'prefix or postfix', 'an unsupported currency position refuses');
$bad = tec_readiness_meta();
$bad['_EventRecurrence'] = ['rules' => [['type' => 'Every Week']]];
tec_readiness_refuses($interpreter, tec_readiness_tree($bad), 'Pro recurrence state', 'free-adapter recurrence state refuses before deploy');
foreach (['_EventRecurrenceRRULE', '_tribe_aggregator_global_id', '_tribe_legacy_ignored_event'] as $key) {
    $bad = tec_readiness_meta();
    $bad[$key] = 'outside-free-contract';
    tec_readiness_refuses(
        $interpreter,
        tec_readiness_tree($bad),
        str_starts_with($key, '_EventRecurrence') ? 'Pro recurrence state' : 'Event Aggregator/import state',
        "$key remains a loud licensed/import boundary"
    );
}
$bad = tec_readiness_meta();
$bad['_EventCost'] = ['serialized' => 'future schema'];
tec_readiness_refuses($interpreter, tec_readiness_tree($bad), 'one scalar string', 'structured event cost refuses instead of being serialized into native meta');
$bad = tec_readiness_meta();
$bad['_EventCostDescription'] = ['serialized' => 'future schema'];
tec_readiness_refuses($interpreter, tec_readiness_tree($bad), 'one scalar string', 'structured cost description refuses before registered-meta consumption');
foreach ([' leading', 'trailing ', 'two  spaces', "line\nbreak", '<b>markup</b>', 'pay%20now'] as $value) {
    $bad = tec_readiness_meta();
    $bad['_EventCostDescription'] = $value;
    tec_readiness_refuses(
        $interpreter,
        tec_readiness_tree($bad),
        'native sanitize_text_field shape',
        'cost description refuses values the native registered-meta sanitizer would rewrite'
    );
}
foreach (['_EventDateTimeSeparator', '_EventTimeRangeSeparator'] as $key) {
    foreach (['<em>until</em>', '&amp;'] as $value) {
        $bad = tec_readiness_meta();
        $bad[$key] = $value;
        tec_readiness_refuses(
            $interpreter,
            tec_readiness_tree($bad),
            'native separator sanitizer shape',
            "$key refuses values the plugin's entity/tag sanitizer would rewrite"
        );
    }
}
$linkedTree = tec_readiness_tree();
$linkedTree[1]['data']['meta']['_VenueURL'] = (object) ['url' => 'https://invalid.example.test'];
tec_readiness_refuses($interpreter, $linkedTree, 'one scalar string', 'structured venue metadata refuses before native code consumes it');
$wrongOwner = tec_readiness_tree();
$wrongOwner[0]['data']['meta']['_VenueShowMap'] = '1';
tec_readiness_refuses($interpreter, $wrongOwner, 'belongs only to a tribe_venue', 'venue map flags refuse on event posts');
$wrongOwner = tec_readiness_tree();
$wrongOwner[2]['data']['meta']['_EventShowMap'] = '1';
tec_readiness_refuses($interpreter, $wrongOwner, 'does not belong to a tribe_organizer', 'map flags refuse on organizer posts');
foreach (['_VenueLat', '_VenueLng'] as $key) {
    $coordinateTree = tec_readiness_tree();
    $coordinateTree[1]['data']['meta'][$key] = $key === '_VenueLat' ? '27.7172' : '85.3240';
    tec_readiness_refuses(
        $interpreter,
        $coordinateTree,
        'Pro/Event Aggregator coordinate state',
        "$key refuses loudly because free TEC has no coordinate writer or reader"
    );
}

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
    '_EventAllDay', '_EventCost', '_EventCostDescription', '_EventCostMax', '_EventCostMin',
    '_EventCurrencyCode', '_EventCurrencyPosition', '_EventCurrencySymbol', '_EventDateTimeSeparator',
    '_EventDuration', '_EventEndDate', '_EventEndDateUTC', '_EventHideFromUpcoming',
    '_EventOrganizerID', '_EventOrigin', '_EventPhone', '_EventShowMap', '_EventShowMapLink',
    '_EventStartDate', '_EventStartDateUTC', '_EventTimezone', '_EventTimezoneAbbr',
    '_EventTimeRangeSeparator', '_EventURL', '_EventVenueID', '_tribe_events_status',
    '_tribe_events_status_reason', '_tribe_featured',
];
foreach ($eventMetaKeys as $key) {
    duo_check_same('authored', $policy->post_meta_rule($key)['class'] ?? null, "$key is reviewed authored TEC state");
}
foreach (['_preview_organizers', '_preview_venues', '_tribe_events_errors', '_tribe_modified_fields'] as $key) {
    duo_check_same('runtime', $policy->post_meta_rule($key)['class'] ?? null, "$key remains target-runtime state");
}
duo_check_same(
    [
        'cardinality' => 'one_or_more',
        'duplicates' => 'forbid',
        'order' => 'preserve',
    ],
    $policy->post_meta_rule('_EventOrganizerID')['repeated_rows'] ?? null,
    'organizer storage declares the exact reviewed ordered unique physical-row grammar'
);
duo_check_same(
    'string',
    $policy->post_meta_rule('_EventOrganizerID')['cast'] ?? null,
    'organizer row refs restore TEC native digit-string postmeta bytes'
);
$runtimeCapture = new EntityMetaCapture(
    $policy,
    new stdClass(),
    static function (): void {},
    static function (): void {},
    static function (): void {}
);
foreach (['_preview_organizers', '_preview_venues'] as $key) {
    [$storePreview] = $runtimeCapture->classifyValue(
        $key,
        ['a:2:{i:0;i:700000001;i:1;i:800000003;}'],
        [$key => 'fixture'],
        'post preview draft',
        'post_meta'
    );
    duo_check_same(false, $storePreview, "$key is excluded before canonical state can retain local preview ids");
}
foreach (['_VenueURL', '_VenueProvince', '_VenueShowMap', '_VenueShowMapLink', '_OrganizerWebsite'] as $key) {
    duo_check_same('authored', $policy->post_meta_rule($key)['class'] ?? null, "$key closes the free venue/organizer API surface");
}
foreach ([
    '_EventAllDay',
    '_EventHideFromUpcoming',
    '_EventShowMap',
    '_EventShowMapLink',
    '_tribe_featured',
    '_VenueShowMap',
    '_VenueShowMapLink',
] as $key) {
    duo_check_same(
        true,
        $policy->post_meta_rule($key)['lint_ok'] ?? null,
        "$key is an explicitly reviewed boolean rather than a coincidental local post reference"
    );
}
foreach (['_VenueLat', '_VenueLng'] as $key) {
    duo_check_same(null, $policy->post_meta_rule($key), "$key remains outside the free-plugin manifest contract");
}

$options = $policy->option_rule('tribe_events_calendar_options');
duo_check_same('env', $options['class'] ?? null, 'the mixed TEC option remains target-owned as a whole');
duo_check_same('preserve', $options['autoload'] ?? null, 'the mixed TEC option preserves live autoload semantics');
foreach (['eventsSlug', 'tribeEnableViews', 'category-color-enable-frontend', 'tec_seo_out_of_range_behavior'] as $key) {
    duo_check_same('authored', $options['sub_keys'][$key]['class'] ?? null, "$key is one reviewed portable setting sub-key");
}
$expectedMainOptionClasses = [
    'authored' => [
        'category-color-custom-css',
        'category-color-enable-frontend',
        'category-color-legend-show',
        'category-color-legend-superpowers',
        'category-color-reset-button',
        'category-color-show-hidden-categories',
        'dateTimeSeparator',
        'dateWithYearFormat',
        'dateWithoutYearFormat',
        'datepickerFormat',
        'defaultCurrencyCode',
        'defaultCurrencySymbol',
        'disable_metabox_custom_fields',
        'donate-link',
        'embedGoogleMaps',
        'embedGoogleMapsZoom',
        'eventsSlug',
        'monthAndYearFormat',
        'monthEventAmount',
        'multiDayCutoff',
        'postsPerPage',
        'posts_per_page',
        'remove_event_end_time',
        'reverseCurrencyPosition',
        'showComments',
        'showEventsInMainLoop',
        'singleEventSlug',
        'stylesheetOption',
        'stylesheet_mode',
        'tec_seo_disabled_view_404',
        'tec_seo_noindex_dated_list_urls',
        'tec_seo_out_of_range_behavior',
        'timeRangeSeparator',
        'toggle_blocks_editor',
        'tribeDisableTribeBar',
        'tribeEnableViews',
        'tribeEventsAfterHTML',
        'tribeEventsBeforeHTML',
        'tribeEventsTemplate',
        'tribe_events_timezone_mode',
        'tribe_events_timezones_show_zone',
        'viewOption',
    ],
    'derived' => [
        'earliest_date',
        'earliest_date_markers',
        'latest_date',
        'latest_date_markers',
    ],
    'env' => [
        'allow_duplicate_venues',
        'custom-fields',
        'debugEvents',
        'delete-past-events',
        'did_init',
        'eb_security_key',
        'enable_month_view_cache',
        'event-automator-schema-version',
        'eventsDefaultOrganizerID',
        'eventsDefaultVenueID',
        'fb_auto_frequency',
        'fb_auto_import',
        'fb_enable_GoogleMaps',
        'fb_token',
        'fb_token_expires',
        'fb_token_scopes',
        'fb_uids',
        'google_maps_js_api_key',
        'geoloc_default_unit',
        'ian-notifications-opt-in',
        'imported_post_status',
        'latest_ecp_version',
        'logging_class',
        'logging_engine',
        'logging_level',
        'liveFiltersUpdate',
        'meetup_api_key',
        'meetup_security_key',
        'opt-in-status',
        'opt_in_status',
        'previous_ecp_versions',
        'recurrenceMaxMonthsAfter',
        'rest-v1-disabled',
        'schema-version',
        'tec-schema-version',
        'tec-tickets-emails-rsvp-add-event-ics',
        'tec-tickets-emails-rsvp-add-event-links',
        'tec-tickets-emails-ticket-add-event-ics',
        'tec-tickets-emails-ticket-add-event-links',
        'tec_events_rcp_hide_on_views',
        'trash-past-events',
        'tribe_aggregator_default_category',
        'tribe_aggregator_default_import_limit_number',
        'tribe_aggregator_default_import_limit_range',
        'tribe_aggregator_default_import_limit_type',
        'tribe_aggregator_default_post_status',
        'tribe_aggregator_default_show_map',
        'tribe_aggregator_default_update_authority',
        'tribe_aggregator_default_url_import_event',
        'tribe_aggregator_default_url_import_range',
        'tribe_aggregator_disable',
        'tribe_aggregator_import_process_system',
        'tribe_ext_tec_tweaks_remove_event_end_time',
        'tribeEventsCountries',
    ],
    'runtime' => [
        'front_page_event_archive',
        'imported_encoding_status',
        'last-update-message-the-events-calendar',
        'mobile_default_view',
        'skip_welcome',
        'spEventsAfterHTML',
        'spEventsBeforeHTML',
        'spEventsTemplate',
        'tec_admin_page_dismissed',
        'tec_events_onboarding_page_dismissed',
        'tec_onboarding_wizard_visited_guided_setup',
        'tribe_events_enable_timezones',
        'tribe_onboarding_views',
        'tribe_queue_sync',
        'views_v2_enabled',
    ],
];
$nativeAggregatorOrigins = ['csv', 'eventbrite', 'gcal', 'ical', 'ics', 'meetup', 'url'];
foreach ($nativeAggregatorOrigins as $origin) {
    foreach (['category', 'import_event_settings', 'post_status', 'show_map', 'update_authority'] as $suffix) {
        $expectedMainOptionClasses['env'][] = "tribe_aggregator_default_{$origin}_{$suffix}";
    }
}
$actualMainOptionClasses = [];
foreach ((array) ($options['sub_keys'] ?? []) as $key => $rule) {
    $actualMainOptionClasses[(string) ($rule['class'] ?? '')][] = (string) $key;
}
foreach ($expectedMainOptionClasses as $class => &$keys) {
    sort($keys, SORT_STRING);
    sort($actualMainOptionClasses[$class], SORT_STRING);
    duo_check_same(
        $keys,
        $actualMainOptionClasses[$class],
        "the exact 6.17.2/6.17.3 main-option $class inventory is closed and source-auditable"
    );
}
unset($keys);
duo_check_same(
    null,
    $options['sub_keys']['tribe_aggregator_default_webcal_post_status'] ?? null,
    'a filter-added Event Aggregator origin is not silently blessed as one of the seven free-core origins'
);
foreach ([
    'debugEvents',
    'delete-past-events',
    'eb_security_key',
    'enable_month_view_cache',
    'fb_token',
    'google_maps_js_api_key',
    'meetup_api_key',
    'recurrenceMaxMonthsAfter',
    'rest-v1-disabled',
    'schema-version',
    'trash-past-events',
] as $key) {
    duo_check_same(
        'env',
        $options['sub_keys'][$key]['class'] ?? null,
        "$key is explicitly classified and preserved as target operational/integration state"
    );
}
foreach (['earliest_date', 'latest_date', 'earliest_date_markers', 'latest_date_markers'] as $key) {
    duo_check_same('derived', $options['sub_keys'][$key]['class'] ?? null, "$key is exact target-derived date state");
}
foreach (['posts_per_page', 'stylesheetOption'] as $key) {
    duo_check_same('authored', $options['sub_keys'][$key]['class'] ?? null, "$key preserves upgraded alias precedence");
}
foreach (['allow_duplicate_venues', 'custom-fields', 'eventsDefaultOrganizerID', 'eventsDefaultVenueID', 'geoloc_default_unit', 'liveFiltersUpdate', 'tribeEventsCountries'] as $key) {
    duo_check_same(
        'env',
        $options['sub_keys'][$key]['class'] ?? null,
        "$key is a target-owned extension or legacy input with no exact free-plugin authoring path"
    );
    duo_check_same(null, $options['sub_keys'][$key]['ref'] ?? null, "$key does not claim a free-plugin reference grammar");
    duo_check_same(null, $options['sub_keys'][$key]['plain_data'] ?? null, "$key does not claim an unbounded portable data grammar");
}
duo_check_same(
    'runtime',
    $options['sub_keys']['front_page_event_archive']['class'] ?? null,
    'the TEC homepage flag remains the target-owned runtime half of the core page_on_front pair'
);

$expectedTopLevelOptionClasses = [
    'derived' => ['tec_events_category_color_css'],
    'env' => [
        'external_updates-event-aggregator',
        'pue_install_key_event_aggregator',
        'stellar_schema_version_stellarwp-shepherd-tec-tasks',
        'stellar_schema_version_tec-kv-cache',
        'stellarwp_telemetry_user_info',
        'tec_automator_power_automate_secret_key',
        'tec_automator_zapier_secret_key',
        'tec_ct1_events_table_schema_version',
        'tec_ct1_migration_state',
        'tec_ct1_occurrences_table_schema_version',
        'tec_custom_tables_v1_active',
        'tec_freemius_accounts_archive',
        'tec_freemius_accounts_data_archive',
        'tec_freemius_plugins_archive',
        'tec_power_automate_connections',
        'tec_timed_tec_custom_tables_v1_initialized',
        'tec_timed_tribe_supports_async_process',
        'tec_zapier_api_keys',
        'tribe_events_calendar_options',
        'tribe_promoter_auth_key',
        'tribe_systeminfo_optin',
        'wpml_tec_did_set_defaults',
    ],
    'runtime' => [
        'sp_events_calendar_options',
        'stellarwp_telemetry',
        'stellarwp_telemetry_last_send',
        'stellarwp_telemetry_the-events-calendar_show_optin',
        'teccc_options',
        'tec_category_colors_migration_data',
        'tec_category_colors_migration_processing',
        'tec_events_category_colors_migration_batch',
        'tec_events_category_colors_migration_status',
        'tec_onboarding_wizard_data',
        'tec_timed_events_hide_from_upcoming_ids',
        'tec_timed_events_is_rest_api_blocked',
        'tec_timed_events_timezone_update_needed',
        'tribe-aggregator-legacy-ical-migrated',
        'tribe-events-importexport-ical-importer-saved-imports',
        'tribe_events_import_column_mapping',
        'tribe_events_import_column_mapping_events',
        'tribe_events_import_column_mapping_organizers',
        'tribe_events_import_column_mapping_venues',
        'tribe_events_import_encoded_rows',
        'tribe_events_import_failed_rows',
        'tribe_events_import_log',
        'tribe_events_import_type',
        'tribe_events_importer_offset',
        'tribe_events_pro_customizer',
        'tribe_last_generate_rewrite_rules',
        'tribe_last_save_post',
        'tribe_last_updated_option',
        'tribe_pue_key_notices',
        'tribe_settings_errors',
        'tribe_settings_major_error',
        'tribe_settings_sent_data',
        'tribe_skip_welcome',
    ],
];
$manifest = json_decode((string) file_get_contents($root . '/manifests/the-events-calendar.json'), true, 512, JSON_THROW_ON_ERROR);
$actualTopLevelOptionClasses = [];
foreach ((array) ($manifest['options'] ?? []) as $key => $rule) {
    $actualTopLevelOptionClasses[(string) ($rule['class'] ?? '')][] = (string) $key;
}
foreach ($expectedTopLevelOptionClasses as $class => $keys) {
    sort($keys, SORT_STRING);
    sort($actualTopLevelOptionClasses[$class], SORT_STRING);
    duo_check_same(
        $keys,
        $actualTopLevelOptionClasses[$class],
        "the exact free/Common top-level $class option inventory is explicit without claiming shared extension state"
    );
}
duo_check_same(
    [
        '^_tec_power_automate_endpoint_details_',
        '^_tec_zapier_endpoint_details_',
        '^tec_power_automate_connection_',
        '^tec_zapier_api_key_',
        '^tribe_events_import_column_mapping(?:_|$)',
    ],
    array_column((array) ($manifest['option_namespaces'] ?? []), 'match'),
    'only exact computed option families receive namespace ownership and hostile suffixes remain visible'
);
duo_check_same(
    [
        '^_tec_power_automate_endpoint_details_(?:attendees|canceled_events|checkin|create_events|new_events|orders|refunded_orders|updated_attendees|updated_events)$',
        '^_tec_zapier_endpoint_details_(?:attendees|authorize|canceled_events|checkin|create_events|find_attendees|find_events|find_tickets|new_events|orders|refunded_orders|update_events|updated_attendees|updated_events)$',
        '^tec_power_automate_connection_[a-f0-9]{64}$',
        '^tec_zapier_api_key_[a-f0-9]{64}$',
    ],
    array_column((array) ($manifest['option_patterns'] ?? []), 'match'),
    'computed Event Automator rows are bounded to the exact 6.17.x native IDs and hash grammar'
);
foreach ([
    'tribe_events_import_column_mapping' => 'runtime',
    'tribe_events_import_column_mapping_events' => 'runtime',
    '_tec_power_automate_endpoint_details_updated_events' => 'runtime',
    '_tec_zapier_endpoint_details_find_tickets' => 'runtime',
    'tec_power_automate_connection_' . str_repeat('a', 64) => 'env',
    'tec_zapier_api_key_' . str_repeat('f', 64) => 'env',
] as $name => $class) {
    duo_check_same(
        $class,
        $interpreter->option_rule($name, [])['class'] ?? null,
        "$name matches one exact source-derived computed option classification"
    );
}
foreach ([
    'tribe_events_import_column_mapping_event-tickets',
    '_tec_power_automate_endpoint_details_find_tickets',
    '_tec_zapier_endpoint_details_find_events_extension',
    'tec_power_automate_connection_' . str_repeat('a', 63),
    'tec_zapier_api_key_' . str_repeat('A', 64),
    'tec_zapier_api_key_' . str_repeat('x', 4096) . "\0AKIAABCDEFGHIJKLMNOP",
    "_tec_zapier_endpoint_details_\xff\xfecredential-secret",
] as $hostileName) {
    $computedRefusal = '';
    try {
        $interpreter->option_rule($hostileName, []);
    } catch (RuntimeException $e) {
        $computedRefusal = $e->getMessage();
    }
    duo_check(
        str_contains($computedRefusal, 'computed-name registry is closed')
            && str_contains($computedRefusal, 'string:' . strlen($hostileName) . ':')
            && strlen($computedRefusal) < 300
            && preg_match('//u', $computedRefusal) === 1
            && !str_contains($computedRefusal, 'AKIA')
            && !str_contains($computedRefusal, 'credential-secret'),
        'unknown computed option names refuse with one bounded UTF-8-safe fingerprint and no authored/secret bytes'
    );
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
            'has_filter',
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
    (object) ['term_id' => 71, 'slug' => 'readiness', 'name' => 'Readiness'],
    (object) ['term_id' => 72, 'slug' => 'plain-category', 'name' => 'Plain Category'],
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
$GLOBALS['tec_readiness_filters'] = [];
$GLOBALS['tec_readiness_cache_busts'] = 0;
$GLOBALS['tec_readiness_color_controller_calls'] = 0;
$GLOBALS['tec_readiness_color_controller_mode'] = '';
$GLOBALS['tec_readiness_category_color_controller'] = new TecReadinessCategoryColorController();
$GLOBALS['tec_readiness_category_color_dropdown'] = new TecReadinessCategoryColorDropdown();
$colorDb = FakeWpdb::install();
tec_readiness_sync_color_db();

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

tec_readiness_set_css('.tribe_events_cat-readiness{--tec-color-category-primary:#000000}');
$GLOBALS['tec_readiness_dropdown_rows'] = [[
    'slug' => 'readiness',
    'name' => 'Readiness',
    'priority' => 99,
    'primary' => '#000000',
    'hidden' => false,
]];
$GLOBALS['tec_readiness_color_controller_mode'] = 'throw_after_css';
duo_check_throws(
    static fn() => $colorProvider->invoke('regenerate_css', []),
    RuntimeException::class,
    'a failure after the native CSS option write surfaces without certifying the stale dropdown cache',
    'injected native Category Colors failure after CSS write'
);
duo_check_same(
    $GLOBALS['tec_readiness_generated_css'],
    get_option('tec_events_category_color_css'),
    'the first native failure witness contains the completed CSS write'
);
duo_check_same(
    '#000000',
    $GLOBALS['tec_readiness_dropdown_rows'][0]['primary'] ?? null,
    'the first native failure witness retains the stale pre-bust dropdown cache'
);
duo_check_same(
    2,
    $GLOBALS['tec_readiness_cache_busts'],
    'a failure before the native dropdown-cache bust does not claim that effect'
);
$afterCssRetry = $colorProvider->invoke('regenerate_css', []);
duo_check_same(true, $afterCssRetry['verified'] ?? null, 'same-process retry after the partial CSS write converges');
duo_check_same(
    $GLOBALS['tec_readiness_generated_dropdown_rows'],
    $GLOBALS['tec_readiness_dropdown_rows'],
    'same-process retry after the partial CSS write repairs the native dropdown projection'
);

tec_readiness_set_css('.tribe_events_cat-readiness{--tec-color-category-primary:#000000}');
$GLOBALS['tec_readiness_dropdown_rows'][0]['primary'] = '#000000';
$GLOBALS['tec_readiness_color_controller_mode'] = 'throw_after_cache_bust';
duo_check_throws(
    static fn() => $colorProvider->invoke('regenerate_css', []),
    RuntimeException::class,
    'a failure after both native writes surfaces before Duo can issue a verified receipt',
    'injected native Category Colors failure after cache bust'
);
duo_check_same(
    $GLOBALS['tec_readiness_generated_css'],
    get_option('tec_events_category_color_css'),
    'the second native failure witness contains the completed CSS write'
);
duo_check_same(
    $GLOBALS['tec_readiness_generated_dropdown_rows'],
    $GLOBALS['tec_readiness_dropdown_rows'],
    'the second native failure witness contains the completed dropdown-cache bust'
);
$afterCacheBustRetry = $colorProvider->invoke('regenerate_css', []);
duo_check_same(true, $afterCacheBustRetry['verified'] ?? null, 'same-process retry after both native writes remains idempotent');
$cacheBustsAfterPartialRetries = $GLOBALS['tec_readiness_cache_busts'];
duo_check_same(5, $cacheBustsAfterPartialRetries, 'only completed native dropdown-cache busts are counted across both retries');
duo_check_same(6, $GLOBALS['tec_readiness_color_controller_calls'], 'both partial attempts and both retries crossed the native controller boundary');

$operation = ['format' => 'duo-provider-operation/v1', 'id' => 'tec-offline-reconcile'];
$reconciled = $colorProvider->reconcile_scoped('regenerate_css', [], $operation);
duo_check_same($operation, $reconciled['operation'] ?? null, 'reconciliation binds its caller-supplied operation envelope');
duo_check_same(true, $reconciled['verified'] ?? null, 'reconciliation verifies without replaying the native write');
$settingsOptionRow = static fn(int $id, string $name, string $value): array => [
    'option_id' => $id,
    'option_name' => $name,
    'option_value' => $value,
    'autoload' => 'on',
];
tec_readiness_seed_color_options([
    $settingsOptionRow(2, 'tribe_events_calendar_options', serialize([
        'category-color-show-hidden-categories' => false,
    ])),
]);
duo_check_same(
    true,
    $colorProvider->reconcile_scoped('regenerate_css', [], $operation)['verified'] ?? null,
    'the exact bounded serialized main settings row agrees with native show-hidden behavior'
);
tec_readiness_seed_color_options([
    $settingsOptionRow(2, 'tribe_events_calendar_options', serialize([])),
    $settingsOptionRow(3, 'tribe_events_calendar_options', serialize([])),
]);
duo_check_throws(
    static fn() => $colorProvider->reconcile_scoped('regenerate_css', [], $operation),
    RuntimeException::class,
    'a duplicate physical main settings identity refuses before native category traversal',
    'identity is duplicated'
);
tec_readiness_seed_color_options();
$colorDb->returnNextGetResultsAs([[
    'option_id' => '2',
    'option_name' => 'TRIBE_EVENTS_CALENDAR_OPTIONS',
    'value_bytes' => '6',
    'value_prefix' => 'a:0:{}',
]]);
duo_check_throws(
    static fn() => $colorProvider->reconcile_scoped('regenerate_css', [], $operation),
    RuntimeException::class,
    'a collation-equivalent but byte-aliased main settings identity refuses',
    'malformed or aliased'
);
tec_readiness_seed_color_options([
    $settingsOptionRow(
        2,
        'tribe_events_calendar_options',
        "not-serialized-AKIAABCDEFGHIJKLMNOP\0" . str_repeat('x', 256)
    ),
]);
$malformedSettingsRefusal = '';
try {
    $colorProvider->reconcile_scoped('regenerate_css', [], $operation);
} catch (RuntimeException $e) {
    $malformedSettingsRefusal = $e->getMessage();
}
duo_check(
    str_contains($malformedSettingsRefusal, 'must be canonical PHP-serialized plain data')
        && !str_contains($malformedSettingsRefusal, 'AKIA')
        && strlen($malformedSettingsRefusal) < 300,
    'malformed main settings bytes refuse with one bounded secret-safe diagnostic'
);
tec_readiness_seed_color_options([
    $settingsOptionRow(2, 'tribe_events_calendar_options', str_repeat('x', 1048577)),
]);
duo_check_throws(
    static fn() => $colorProvider->reconcile_scoped('regenerate_css', [], $operation),
    RuntimeException::class,
    'an oversized main settings blob refuses after only a bounded prefix transfer',
    'exceeds the bounded byte frontier'
);
tec_readiness_seed_color_options([
    $settingsOptionRow(2, 'tribe_events_calendar_options', serialize([
        'category-color-show-hidden-categories' => '1',
    ])),
]);
duo_check_throws(
    static fn() => $colorProvider->reconcile_scoped('regenerate_css', [], $operation),
    RuntimeException::class,
    'a non-boolean stored show-hidden setting refuses before native truthiness can drift',
    'not a native boolean'
);
tec_readiness_seed_color_options([
    $settingsOptionRow(2, 'tribe_events_calendar_options', serialize([
        'category-color-show-hidden-categories' => true,
    ])),
]);
duo_check_throws(
    static fn() => $colorProvider->reconcile_scoped('regenerate_css', [], $operation),
    RuntimeException::class,
    'a stale native settings cache refuses even when the bounded raw row is valid',
    'disagrees with bounded raw storage'
);
tec_readiness_seed_color_options();
$colorDb->returnNextGetResultsAs(false);
duo_check_throws(
    static fn() => $colorProvider->reconcile_scoped('regenerate_css', [], $operation),
    RuntimeException::class,
    'a false main settings read from a non-core compatible driver refuses before native traversal',
    'main settings option value is unreadable'
);
$cacheBustsBeforeFilterRefusal = $GLOBALS['tec_readiness_cache_busts'];
$GLOBALS['tec_readiness_filters'] = ['tec_events_category_color_generator_final_css'];
duo_check_throws(
    static fn() => $colorProvider->invoke('regenerate_css', []),
    RuntimeException::class,
    'a native final-CSS output filter refuses before controller or dropdown-cache mutation',
    'does not admit filter'
);
duo_check_same(
    $cacheBustsBeforeFilterRefusal,
    $GLOBALS['tec_readiness_cache_busts'],
    'output-filter refusal happens before every native Category Colors side effect'
);
$GLOBALS['tec_readiness_filters'] = [];
tec_readiness_seed_color_options();
tec_readiness_set_css('.tribe_events_cat-readiness{--tec-color-category-primary:#123abc}');
duo_check_throws(
    static fn() => $colorProvider->reconcile_scoped('regenerate_css', [], $operation),
    RuntimeException::class,
    'reconciliation refuses stale partial native CSS rather than certifying an ambiguous effect',
    'recovery_required'
);
$GLOBALS['tec_readiness_term_meta'][71]['tec-events-cat-colors-secondary'] = '';
tec_readiness_sync_color_db();
tec_readiness_set_css($GLOBALS['tec_readiness_generated_css']);
duo_check_throws(
    static fn() => $colorProvider->reconcile_scoped('regenerate_css', [], $operation),
    RuntimeException::class,
    'reconciliation refuses a stale recognized property removed from live category metadata',
    'value mismatch'
);
$GLOBALS['tec_readiness_term_meta'][71]['tec-events-cat-colors-secondary'] = '#fedcba';
tec_readiness_sync_color_db();
tec_readiness_set_css(str_replace(
    '--tec-color-category-secondary:',
    '--tec-color-category-primary:#123abc;--tec-color-category-secondary:',
    $GLOBALS['tec_readiness_generated_css']
));
duo_check_throws(
    static fn() => $colorProvider->reconcile_scoped('regenerate_css', [], $operation),
    RuntimeException::class,
    'reconciliation refuses a duplicate recognized property even when both values are current',
    'value mismatch'
);
tec_readiness_set_css(str_replace(
    '}',
    ';background-color:transparent}',
    $GLOBALS['tec_readiness_generated_css']
));
duo_check_throws(
    static fn() => $colorProvider->reconcile_scoped('regenerate_css', [], $operation),
    RuntimeException::class,
    'reconciliation refuses a filter-injected declaration outside the exact native CSS bytes',
    'exact-byte grammar mismatch'
);
tec_readiness_set_css('/* injected */' . $GLOBALS['tec_readiness_generated_css']);
duo_check_throws(
    static fn() => $colorProvider->reconcile_scoped('regenerate_css', [], $operation),
    RuntimeException::class,
    'reconciliation refuses a filter-injected CSS comment before the native selector stream',
    'exact-byte grammar mismatch'
);
tec_readiness_set_css(
    $GLOBALS['tec_readiness_generated_css']
        . '.foreign-selector{display:block}'
);
duo_check_throws(
    static fn() => $colorProvider->reconcile_scoped('regenerate_css', [], $operation),
    RuntimeException::class,
    'reconciliation refuses an arbitrary extra selector outside the native output grammar',
    'exact-byte grammar mismatch'
);
tec_readiness_set_css(
    '.tribe_events_cat-readiness{'
        . '--tec-color-category-secondary:#fedcba;'
        . '--tec-color-category-primary:#123abc;'
        . '--tec-color-category-text:#ffffff}'
);
duo_check_throws(
    static fn() => $colorProvider->reconcile_scoped('regenerate_css', [], $operation),
    RuntimeException::class,
    'reconciliation refuses reordered properties that the exact native generator cannot emit',
    'value mismatch'
);
tec_readiness_set_css(
    $GLOBALS['tec_readiness_generated_css']
        . '.tribe_events_cat-orphan{--tec-color-category-primary:#111111}'
);
duo_check_throws(
    static fn() => $colorProvider->reconcile_scoped('regenerate_css', [], $operation),
    RuntimeException::class,
    'reconciliation refuses an orphan generated CSS selector',
    'selector-set mismatch'
);
tec_readiness_set_css(str_repeat('x', 8388609));
duo_check_throws(
    static fn() => $colorProvider->reconcile_scoped('regenerate_css', [], $operation),
    RuntimeException::class,
    'an oversized CSS option refuses after a bounded single-statement prefix read',
    'CSS option exceeds the bounded byte frontier'
);
tec_readiness_set_css($GLOBALS['tec_readiness_generated_css']);
$GLOBALS['tec_readiness_term_meta'][72] = [
    'tec-events-cat-colors-primary' => '#ABCDEF',
    'tec-events-cat-colors-priority' => '5',
    'tec-events-cat-colors-hidden' => '0',
];
$GLOBALS['tec_readiness_dropdown_rows'] = [
    $GLOBALS['tec_readiness_generated_dropdown_rows'][0],
    [
        'slug' => 'plain-category',
        'name' => 'Plain Category',
        'priority' => 5,
        'primary' => '#ABCDEF',
        'hidden' => false,
    ],
];
tec_readiness_sync_color_db();
tec_readiness_set_css(
    $GLOBALS['tec_readiness_generated_css']
        . '.tribe_events_cat-plain-category{--tec-color-category-primary:#abcdef}'
);
duo_check_throws(
    static fn() => $colorProvider->reconcile_scoped('regenerate_css', [], $operation),
    RuntimeException::class,
    'reconciliation refuses selectors emitted against native ascending priority order',
    'priority-order mismatch'
);
tec_readiness_set_css(
    '.tribe_events_cat-plain-category{--tec-color-category-primary:#abcdef}'
        . $GLOBALS['tec_readiness_generated_css']
);
duo_check_same(
    true,
    $colorProvider->reconcile_scoped('regenerate_css', [], $operation)['verified'] ?? null,
    'multiple selectors verify only in native priority and per-property byte order'
);
$GLOBALS['tec_readiness_term_meta'][72] = [];
$GLOBALS['tec_readiness_dropdown_rows'] = $GLOBALS['tec_readiness_generated_dropdown_rows'];
tec_readiness_sync_color_db();
tec_readiness_set_css($GLOBALS['tec_readiness_generated_css']);
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
$GLOBALS['tec_readiness_dropdown_rows'] = array_fill(
    0,
    10001,
    $GLOBALS['tec_readiness_generated_dropdown_rows'][0]
);
duo_check_throws(
    static fn() => $colorProvider->reconcile_scoped('regenerate_css', [], $operation),
    RuntimeException::class,
    'an oversized native dropdown refuses at its hard row frontier',
    'exceeds the bounded row frontier'
);
$GLOBALS['tec_readiness_dropdown_rows'] = $GLOBALS['tec_readiness_generated_dropdown_rows'];
duo_check_same(
    $cacheBustsAfterPartialRetries,
    $GLOBALS['tec_readiness_cache_busts'],
    'reconciliation probes never replay the native mutation'
);
duo_check_throws(
    static fn() => $colorProvider->invoke('invented_capability', []),
    RuntimeException::class,
    'the provider capability surface is closed',
    'does not implement capability'
);

$GLOBALS['tec_readiness_term_meta'][71]['tec-events-cat-colors-primary'] = str_repeat('a', 1025);
tec_readiness_sync_color_db();
duo_check_throws(
    static fn() => $colorProvider->reconcile_scoped('regenerate_css', [], $operation),
    RuntimeException::class,
    'an oversized relevant term-meta value refuses after a bounded prefix witness',
    'malformed or oversized'
);
$GLOBALS['tec_readiness_term_meta'][71]['tec-events-cat-colors-primary'] = '#123ABC';
tec_readiness_sync_color_db();
$colorDb->insert($colorDb->termmeta, [
    'term_id' => 71,
    'meta_key' => 'tec-events-cat-colors-primary',
    'meta_value' => '#123ABC',
]);
duo_check_throws(
    static fn() => $colorProvider->reconcile_scoped('regenerate_css', [], $operation),
    RuntimeException::class,
    'duplicate physical category-color metadata refuses before last-row-wins native behavior',
    'duplicated or ownerless'
);
tec_readiness_sync_color_db();
$colorDb->failNextQuery(
    'injected taxonomy read failure AKIAABCDEFGHIJKLMNOP',
    'FROM wp_term_taxonomy'
);
$categoryReadRefusal = '';
try {
    $colorProvider->reconcile_scoped('regenerate_css', [], $operation);
} catch (RuntimeException $e) {
    $categoryReadRefusal = $e->getMessage();
}
duo_check(
    str_contains($categoryReadRefusal, 'taxonomy identities is unreadable')
        && !str_contains($categoryReadRefusal, 'AKIA')
        && strlen($categoryReadRefusal) < 300,
    'a category identity driver failure refuses with a bounded redacted diagnostic'
);
$taxonomyFlood = [];
for ($termId = 1; $termId <= 10001; ++$termId) {
    $taxonomyFlood[] = [
        'term_taxonomy_id' => $termId,
        'term_id' => $termId,
        'taxonomy' => 'tribe_events_cat',
    ];
}
$colorDb->seedTable($colorDb->term_taxonomy, $taxonomyFlood);
$colorDb->seedTable($colorDb->terms, []);
$colorDb->seedTable($colorDb->termmeta, []);
tec_readiness_seed_color_options();
duo_check_throws(
    static fn() => $colorProvider->reconcile_scoped('regenerate_css', [], $operation),
    RuntimeException::class,
    'a hostile category taxonomy refuses before native get_terms or metadata traversal',
    'exceeds the bounded category frontier'
);
unset($taxonomyFlood);
tec_readiness_sync_color_db();
$taxonomyReadCount = 0;
$colorDb->onQuery(static function (string $sql, string $method, FakeWpdb $db) use (&$taxonomyReadCount): null {
    if ($method !== 'get_results' || !str_contains($sql, 'FROM wp_term_taxonomy')) {
        return null;
    }
    ++$taxonomyReadCount;
    if ($taxonomyReadCount !== 2) {
        return null;
    }
    $rows = $db->rows($db->termmeta);
    foreach ($rows as &$row) {
        if (($row['term_id'] ?? null) === 71
            && ($row['meta_key'] ?? null) === 'tec-events-cat-colors-primary') {
            $row['meta_value'] = '#654321';
        }
    }
    unset($row);
    $db->seedTable($db->termmeta, $rows);
    return null;
});
duo_check_throws(
    static fn() => $colorProvider->reconcile_scoped('regenerate_css', [], $operation),
    RuntimeException::class,
    'a concurrent category-color change between fresh witnesses refuses instead of blessing mixed generations',
    'changed during verification'
);
$colorDb->onQuery(null);
tec_readiness_sync_color_db();

$GLOBALS['tec_readiness_terms'] = [];
$GLOBALS['tec_readiness_term_meta'] = [];
tec_readiness_set_css('.tribe_events_cat-orphan{--tec-color-category-primary:#111111}');
$GLOBALS['tec_readiness_dropdown_rows'] = [[
    'slug' => 'orphan',
    'name' => 'Orphan',
    'priority' => 9,
    'primary' => '#111111',
    'hidden' => false,
]];
$GLOBALS['tec_readiness_generated_css'] = '';
$GLOBALS['tec_readiness_generated_dropdown_rows'] = [];
tec_readiness_sync_color_db();
$emptyColorReceipt = $colorProvider->invoke('regenerate_css', []);
duo_check_same(true, $emptyColorReceipt['verified'] ?? null, 'zero event categories converge through the native empty CSS/dropdown path');
duo_check_same(0, $emptyColorReceipt['after']['colored_category_count'] ?? null, 'zero-category receipt stays bounded at zero colored categories');
duo_check_same(0, $emptyColorReceipt['after']['css_selector_count'] ?? null, 'zero categories store TEC native empty CSS without a synthetic selector');
duo_check_same(0, $emptyColorReceipt['after']['dropdown_actual_count'] ?? null, 'zero categories store TEC native empty dropdown projection');
duo_check_same(
    true,
    $colorProvider->reconcile_scoped('regenerate_css', [], $operation)['verified'] ?? null,
    'zero-category reconciliation verifies the native empty state without mutation'
);

$GLOBALS['tec_readiness_terms'] = [(object) ['term_id' => 72, 'slug' => 'plain-category', 'name' => 'Plain Category']];
$GLOBALS['tec_readiness_term_meta'] = [72 => []];
tec_readiness_sync_color_db();
$plainColorReceipt = $colorProvider->invoke('regenerate_css', []);
duo_check_same(true, $plainColorReceipt['verified'] ?? null, 'categories with no color metadata converge through native empty generated state');
duo_check_same(0, $plainColorReceipt['after']['colored_category_count'] ?? null, 'an uncolored native category is not invented as a colored selector');
duo_check_same(0, $plainColorReceipt['after']['dropdown_actual_count'] ?? null, 'an uncolored native category is absent from the color dropdown cache');
duo_check_same(
    $cacheBustsAfterPartialRetries + 2,
    $GLOBALS['tec_readiness_cache_busts'],
    'only explicit provider invocations replayed native cache mutation'
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
foreach (['post:tribe_events', 'post:tribe_venue', 'post:tribe_organizer', 'term:tribe_events_cat'] as $selector) {
    duo_check_same(
        null,
        $policy->deletion_capability($selector),
        "$selector has no inferred deletion authority before its native cascades and reverse references are closed"
    );
}
$deletionSeed = (string) file_get_contents($root . '/sandbox/conformance/seeds/the-events-calendar.sh');
$deletionCheck = (string) file_get_contents($root . '/sandbox/conformance/checks/the-events-calendar.sh');
foreach ([
    'Duo Unsupported Delete Probe',
    'Duo Unsupported Delete Venue',
    'Duo Unsupported Delete Organizer',
    'duo-unsupported-delete-category',
] as $fixtureIdentity) {
    duo_check(
        str_contains($deletionSeed, $fixtureIdentity),
        "the exact live seed carries independent unreferenced deletion fixture $fixtureIdentity"
    );
}
foreach ([
    "tec_refuse_post_deletion tribe_events 'Duo Unsupported Delete Probe' post:tribe_events",
    "tec_refuse_post_deletion tribe_venue 'Duo Unsupported Delete Venue' post:tribe_venue",
    "tec_refuse_post_deletion tribe_organizer 'Duo Unsupported Delete Organizer' post:tribe_organizer",
    'tec_refuse_term_deletion tribe_events_cat duo-unsupported-delete-category term:tribe_events_cat',
] as $probe) {
    duo_check(str_contains($deletionCheck, $probe), "the exact live matrix executes $probe");
}
foreach (['postmeta', 'termmeta', 'term_relationships', 'tec_events', 'tec_occurrences', 'category_css'] as $witness) {
    duo_check(
        str_contains($deletionCheck, '"' . $witness . '"=>'),
        "deletion refusal fingerprints $witness before and after capture"
    );
}

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

$tecEventId = 6100000001;
$GLOBALS['tec_readiness_post_meta'] = [
    $tecEventId => [
        '_EventStartDate' => '2026-11-02 18:30:00',
        '_EventEndDate' => '2026-11-02 21:00:00',
        '_EventStartDateUTC' => '2026-11-02 12:45:00',
        '_EventEndDateUTC' => '2026-11-02 15:15:00',
        '_EventTimezone' => 'Asia/Kathmandu',
        '_EventDuration' => '9000',
    ],
];
$tecDb = FakeWpdb::install();
$eventTable = $tecDb->prefix . 'tec_events';
$occurrenceTable = $tecDb->prefix . 'tec_occurrences';
$tecDb->setColumns($eventTable, [
    'event_id' => 'bigint(20) unsigned',
    'post_id' => 'bigint(20) unsigned',
    'start_date' => 'varchar(19)',
    'end_date' => 'varchar(19)',
    'timezone' => 'varchar(30)',
    'start_date_utc' => 'varchar(19)',
    'end_date_utc' => 'varchar(19)',
    'duration' => 'mediumint(30)',
    'updated_at' => 'timestamp',
    'hash' => 'varchar(40)',
]);
$tecDb->setColumns($occurrenceTable, [
    'occurrence_id' => 'bigint(20) unsigned',
    'event_id' => 'bigint(20) unsigned',
    'post_id' => 'bigint(20) unsigned',
    'start_date' => 'datetime',
    'end_date' => 'datetime',
    'start_date_utc' => 'datetime',
    'end_date_utc' => 'datetime',
    'duration' => 'mediumint(30)',
    'updated_at' => 'timestamp',
    'hash' => 'varchar(40)',
]);
$tecDb->setPrimaryKey($eventTable, 'event_id')->setPrimaryKey($occurrenceTable, 'occurrence_id');
$resetTecDerived = static function () use ($tecDb, $eventTable, $occurrenceTable, $tecEventId): void {
    $tecDb->seedTable($eventTable, [
        [
            'event_id' => 7000000001,
            'post_id' => $tecEventId,
            'start_date' => '1999-01-01 00:00:00',
            'end_date' => '1999-01-01 00:30:00',
            'timezone' => 'UTC',
            'start_date_utc' => '1999-01-01 00:00:00',
            'end_date_utc' => '1999-01-01 00:30:00',
            'duration' => 1800,
            'updated_at' => '1999-01-01 00:00:00',
            'hash' => 'stale-event-hash',
        ],
        [
            'event_id' => 7000000099,
            'post_id' => 6100000099,
            'start_date' => '2028-01-01 00:00:00',
            'end_date' => '2028-01-01 01:00:00',
            'timezone' => 'UTC',
            'start_date_utc' => '2028-01-01 00:00:00',
            'end_date_utc' => '2028-01-01 01:00:00',
            'duration' => 3600,
            'updated_at' => '2028-01-01 00:00:00',
            'hash' => 'target-runtime-event',
        ],
    ]);
    $tecDb->seedTable($occurrenceTable, [
        [
            'occurrence_id' => 8000000001,
            'event_id' => 7000000001,
            'post_id' => $tecEventId,
            'start_date' => '1999-01-01 00:00:00',
            'end_date' => '1999-01-01 00:30:00',
            'start_date_utc' => '1999-01-01 00:00:00',
            'end_date_utc' => '1999-01-01 00:30:00',
            'duration' => 1800,
            'updated_at' => '1999-01-01 00:00:00',
            'hash' => 'stale-occurrence-hash',
        ],
        [
            'occurrence_id' => 8000000099,
            'event_id' => 7000000099,
            'post_id' => 6100000099,
            'start_date' => '2028-01-01 00:00:00',
            'end_date' => '2028-01-01 01:00:00',
            'start_date_utc' => '2028-01-01 00:00:00',
            'end_date_utc' => '2028-01-01 01:00:00',
            'duration' => 3600,
            'updated_at' => '2028-01-01 00:00:00',
            'hash' => 'target-runtime-occurrence',
        ],
    ]);
    $tecDb->onQuery(null);
    $tecDb->resetLog();
    $GLOBALS['tec_readiness_regen_mode'] = 'ok';
    $GLOBALS['tec_readiness_regen_calls'] = [];
};
$tecFailure = static function (callable $call): string {
    try {
        $call();
    } catch (RuntimeException $e) {
        return $e->getMessage();
    }
    duo_check(false, 'expected TEC regenerator failure did not occur');
    return '';
};

$resetTecDerived();
$beforeFilteredRegeneration = [$tecDb->rows($eventTable), $tecDb->rows($occurrenceTable)];
$GLOBALS['tec_readiness_filters'] = ['tec_events_custom_tables_v1_event_data_from_post'];
duo_check_same(
    'duo: TEC free-plugin derived-state contract does not admit the event-data filter',
    $tecFailure(static fn() => $regenerator->regenerate($tecEventId)),
    'the exact native event-data filter topology refuses before any derived write'
);
duo_check_same(
    $beforeFilteredRegeneration,
    [$tecDb->rows($eventTable), $tecDb->rows($occurrenceTable)],
    'a malicious same-shape event-data filter cannot mutate either custom table before refusal'
);
duo_check_same([], $GLOBALS['tec_readiness_regen_calls'], 'event-data filter refusal never crosses the native upsert boundary');
$GLOBALS['tec_readiness_filters'] = [];

$resetTecDerived();
$regenerator->regenerate($tecEventId);
$boundedVerificationQueries = array_values(array_filter(
    $tecDb->queries(),
    static fn(string $sql): bool => str_contains($sql, 'WHERE post_id = 6100000001 OR event_id = 7000000001')
));
duo_check(
    count($boundedVerificationQueries) === 2
        && str_ends_with($boundedVerificationQueries[0], 'ORDER BY event_id LIMIT 3')
        && str_ends_with($boundedVerificationQueries[1], 'ORDER BY occurrence_id LIMIT 3'),
    'both exact custom-table verification reads have a three-row proof limit before transfer'
);
$expectedOccurrenceHash = sha1(implode(':', [
    (string) $tecEventId,
    '2026-11-02 18:30:00',
    '2026-11-02 21:00:00',
    '2026-11-02 12:45:00',
    '2026-11-02 15:15:00',
    '9000',
]));
$nativeEventRow = $tecDb->get_row($tecDb->prepare(
    'SELECT event_id, post_id, start_date, end_date, start_date_utc, end_date_utc, timezone, duration, hash '
    . "FROM `$eventTable` WHERE post_id = %d",
    $tecEventId
), ARRAY_A);
duo_check_same([
    'event_id' => '7000000001',
    'post_id' => (string) $tecEventId,
    'start_date' => '2026-11-02 18:30:00',
    'end_date' => '2026-11-02 21:00:00',
    'start_date_utc' => '2026-11-02 12:45:00',
    'end_date_utc' => '2026-11-02 15:15:00',
    'timezone' => 'Asia/Kathmandu',
    'duration' => '9000',
    'hash' => '',
], $nativeEventRow, 'regeneration verifies every deterministic tec_events field on a huge post identity');
$nativeOccurrenceRow = $tecDb->get_row($tecDb->prepare(
    'SELECT event_id, post_id, start_date, end_date, start_date_utc, end_date_utc, duration, hash '
    . "FROM `$occurrenceTable` WHERE post_id = %d",
    $tecEventId
), ARRAY_A);
duo_check_same([
    'event_id' => '7000000001',
    'post_id' => (string) $tecEventId,
    'start_date' => '2026-11-02 18:30:00',
    'end_date' => '2026-11-02 21:00:00',
    'start_date_utc' => '2026-11-02 12:45:00',
    'end_date_utc' => '2026-11-02 15:15:00',
    'duration' => '9000',
    'hash' => $expectedOccurrenceHash,
], $nativeOccurrenceRow, 'regeneration verifies every deterministic occurrence field and event linkage');
duo_check_same(
    'target-runtime-event',
    $tecDb->get_var("SELECT hash FROM `$eventTable` WHERE post_id = 6100000099"),
    'event regeneration preserves unrelated target-derived rows'
);
duo_check_same(
    'target-runtime-occurrence',
    $tecDb->get_var("SELECT hash FROM `$occurrenceTable` WHERE post_id = 6100000099"),
    'occurrence regeneration preserves unrelated target-derived rows'
);
$beforeRetry = [$nativeEventRow, $nativeOccurrenceRow];
$regenerator->regenerate($tecEventId);
$afterRetry = [
    $tecDb->get_row($tecDb->prepare(
        'SELECT event_id, post_id, start_date, end_date, start_date_utc, end_date_utc, timezone, duration, hash '
        . "FROM `$eventTable` WHERE post_id = %d",
        $tecEventId
    ), ARRAY_A),
    $tecDb->get_row($tecDb->prepare(
        'SELECT event_id, post_id, start_date, end_date, start_date_utc, end_date_utc, duration, hash '
        . "FROM `$occurrenceTable` WHERE post_id = %d",
        $tecEventId
    ), ARRAY_A),
];
duo_check_same($beforeRetry, $afterRetry, 'a repeated native regeneration is idempotent at every deterministic field');
$heartbeats = 0;
$regenerator->regenerate_batch(
    [$tecEventId, 0, $tecEventId, -1],
    [],
    static function () use (&$heartbeats): void {
        ++$heartbeats;
    }
);
duo_check_same(1, $heartbeats, 'batch regeneration deduplicates live IDs before heartbeat and native mutation');
duo_check_same([$tecEventId, $tecEventId, $tecEventId], $GLOBALS['tec_readiness_regen_calls'], 'batch retry invokes only the one canonical positive live ID');

$resetTecDerived();
$GLOBALS['tec_readiness_post_meta'][$tecEventId]['_EventDuration'] = '';
$regenerator->regenerate($tecEventId);
duo_check_same(
    ['9000', '9000'],
    [
        $tecDb->get_var($tecDb->prepare("SELECT duration FROM `$eventTable` WHERE post_id = %d", $tecEventId)),
        $tecDb->get_var($tecDb->prepare("SELECT duration FROM `$occurrenceTable` WHERE post_id = %d", $tecEventId)),
    ],
    'empty native duration metadata derives exact seconds from UTC endpoints in both custom tables'
);
$GLOBALS['tec_readiness_post_meta'][$tecEventId]['_EventDuration'] = '9000';

$resetTecDerived();
$GLOBALS['tec_readiness_regen_mode'] = 'stale_driver_error';
$regenerator->regenerate($tecEventId);
duo_check_same('', $tecDb->last_error, 'handled stale native model errors cannot poison either exact verification read');

$resetTecDerived();
$GLOBALS['tec_readiness_regen_mode'] = 'non_array_data';
$failure = $tecFailure(static fn() => $regenerator->regenerate($tecEventId));
duo_check(str_contains($failure, 'non-array') && !str_contains($failure, 'AKIA'), 'filtered non-array event data refuses with a bounded redacted diagnostic');

$resetTecDerived();
$GLOBALS['tec_readiness_regen_mode'] = 'upsert_false';
$failure = $tecFailure(static fn() => $regenerator->regenerate($tecEventId));
duo_check(str_contains($failure, '2 model error(s)') && strlen($failure) < 200 && !str_contains($failure, 'AKIA'), 'native upsert errors are counted without leaking hostile plugin payloads');

$resetTecDerived();
$GLOBALS['tec_readiness_regen_mode'] = 'find_missing';
duo_check(
    str_contains($tecFailure(static fn() => $regenerator->regenerate($tecEventId)), 'could not locate'),
    'a partial upsert whose native model cannot be read back refuses'
);

$resetTecDerived();
$GLOBALS['tec_readiness_regen_mode'] = 'invalid_event_id';
duo_check(
    str_contains($tecFailure(static fn() => $regenerator->regenerate($tecEventId)), 'positive integer event_id'),
    'a native model with a non-integer generated identity refuses before occurrence mutation'
);

$resetTecDerived();
$GLOBALS['tec_readiness_regen_mode'] = 'filtered_event_timezone';
duo_check_same(
    'duo: TEC derived-state verification failed for tec_events fields: timezone',
    $tecFailure(static fn() => $regenerator->regenerate($tecEventId)),
    'a filter-corrupted deterministic event timezone refuses by fixed field name without authored values'
);

$resetTecDerived();
$GLOBALS['tec_readiness_regen_mode'] = 'invalid_event_updated_at';
duo_check_same(
    'duo: TEC derived-state verification failed for tec_events fields: updated_at',
    $tecFailure(static fn() => $regenerator->regenerate($tecEventId)),
    'an invalid generated event update timestamp refuses without treating it as authored state'
);

$resetTecDerived();
$GLOBALS['tec_readiness_regen_mode'] = 'invalid_occurrence_id';
duo_check_same(
    'duo: TEC derived-state verification failed for tec_occurrences fields: occurrence_id',
    $tecFailure(static fn() => $regenerator->regenerate($tecEventId)),
    'an occurrence without one positive generated identity refuses'
);

$resetTecDerived();
$GLOBALS['tec_readiness_regen_mode'] = 'oversized_occurrence_id';
duo_check_same(
    'duo: TEC derived-state verification failed for tec_occurrences fields: occurrence_id',
    $tecFailure(static fn() => $regenerator->regenerate($tecEventId)),
    'an occurrence identity above the exact unsigned bigint frontier refuses'
);

$resetTecDerived();
$GLOBALS['tec_readiness_regen_mode'] = 'invalid_occurrence_updated_at';
duo_check_same(
    'duo: TEC derived-state verification failed for tec_occurrences fields: updated_at',
    $tecFailure(static fn() => $regenerator->regenerate($tecEventId)),
    'an invalid generated occurrence update timestamp refuses without copying it'
);

$validEventDriverRow = [
    'event_id' => '7000000001',
    'post_id' => (string) $tecEventId,
    'start_date' => '2026-11-02 18:30:00',
    'end_date' => '2026-11-02 21:00:00',
    'start_date_utc' => '2026-11-02 12:45:00',
    'end_date_utc' => '2026-11-02 15:15:00',
    'timezone' => 'Asia/Kathmandu',
    'duration' => '9000',
    'updated_at' => '2026-08-24 00:00:00',
    'hash' => '',
];
$resetTecDerived();
$tecDb->returnNextGetResultsAs(null);
duo_check_same(
    'duo: TEC derived-state verification query returned a non-array for tec_events',
    $tecFailure(static fn() => $regenerator->regenerate($tecEventId)),
    'a null event read from a non-core wpdb-compatible driver refuses explicitly'
);

$resetTecDerived();
$tecDb->returnNextGetResultsAs([7 => $validEventDriverRow]);
duo_check_same(
    'duo: TEC derived-state verification query returned a non-list for tec_events',
    $tecFailure(static fn() => $regenerator->regenerate($tecEventId)),
    'an associative compatible-driver result refuses before event value verification'
);

$resetTecDerived();
$tecDb->returnNextGetResultsAs([array_diff_key($validEventDriverRow, ['hash' => true])]);
duo_check_same(
    'duo: TEC derived-state verification query returned a malformed driver row for tec_events',
    $tecFailure(static fn() => $regenerator->regenerate($tecEventId)),
    'an incomplete event driver row refuses before missing fields can be conflated with empty values'
);

$resetTecDerived();
$nonStringEventDriverRow = $validEventDriverRow;
$nonStringEventDriverRow['event_id'] = 7000000001;
$tecDb->returnNextGetResultsAs([$nonStringEventDriverRow]);
duo_check_same(
    'duo: TEC derived-state verification query returned a non-string driver value for tec_events',
    $tecFailure(static fn() => $regenerator->regenerate($tecEventId)),
    'a native-integer compatible-driver value refuses outside mysqli text-protocol evidence'
);

$resetTecDerived();
$tecDb->returnNextGetResultsAs([$validEventDriverRow])->returnNextGetResultsAs(false);
duo_check_same(
    'duo: TEC derived-state verification query returned a non-array for tec_occurrences',
    $tecFailure(static fn() => $regenerator->regenerate($tecEventId)),
    'a false occurrence read from a non-core wpdb-compatible driver refuses explicitly'
);

$resetTecDerived();
$tecDb->returnNextGetResultsAs([$validEventDriverRow])->returnNextGetResultsAs([
    9 => [
        'occurrence_id' => '8000000001',
        'event_id' => '7000000001',
        'post_id' => (string) $tecEventId,
        'start_date' => '2026-11-02 18:30:00',
        'end_date' => '2026-11-02 21:00:00',
        'start_date_utc' => '2026-11-02 12:45:00',
        'end_date_utc' => '2026-11-02 15:15:00',
        'duration' => '9000',
        'updated_at' => '2026-08-24 00:00:01',
        'hash' => $expectedOccurrenceHash,
    ],
]);
duo_check_same(
    'duo: TEC derived-state verification query returned a non-list for tec_occurrences',
    $tecFailure(static fn() => $regenerator->regenerate($tecEventId)),
    'an associative occurrence result refuses before generated-row verification'
);

$resetTecDerived();
$tecDb->failNextQuery('credential SQL failure AKIAABCDEFGHIJKLMNOP', 'SELECT event_id, post_id');
$failure = $tecFailure(static fn() => $regenerator->regenerate($tecEventId));
duo_check_same('duo: TEC derived-state verification query failed for tec_events', $failure, 'event verification query failure is explicit and redacted');

$resetTecDerived();
$tecDb->failNextQuery('credential SQL failure AKIAABCDEFGHIJKLMNOP', 'SELECT occurrence_id, event_id');
$failure = $tecFailure(static fn() => $regenerator->regenerate($tecEventId));
duo_check_same('duo: TEC derived-state verification query failed for tec_occurrences', $failure, 'occurrence verification query failure is explicit and redacted');

$resetTecDerived();
$GLOBALS['tec_readiness_regen_mode'] = 'upsert_throw_after_write';
duo_check_same(
    'injected native event upsert failure after derived write',
    $tecFailure(static fn() => $regenerator->regenerate($tecEventId)),
    'a native exception after the tec_events upsert surfaces before occurrence synthesis'
);
duo_check_same(
    '',
    $tecDb->get_var($tecDb->prepare("SELECT hash FROM `$eventTable` WHERE post_id = %d", $tecEventId)),
    'the event-upsert failure witness contains the completed first derived write'
);
duo_check_same(
    'stale-occurrence-hash',
    $tecDb->get_var($tecDb->prepare("SELECT hash FROM `$occurrenceTable` WHERE post_id = %d", $tecEventId)),
    'the event-upsert failure witness leaves the second derived row untouched'
);
$GLOBALS['tec_readiness_regen_mode'] = 'ok';
$regenerator->regenerate($tecEventId);
duo_check_same(
    $expectedOccurrenceHash,
    $tecDb->get_var($tecDb->prepare("SELECT hash FROM `$occurrenceTable` WHERE post_id = %d", $tecEventId)),
    'same-process retry after the partial event upsert converges both native rows'
);
duo_check_same(
    ['target-runtime-event', 'target-runtime-occurrence'],
    [
        $tecDb->get_var("SELECT hash FROM `$eventTable` WHERE post_id = 6100000099"),
        $tecDb->get_var("SELECT hash FROM `$occurrenceTable` WHERE post_id = 6100000099"),
    ],
    'partial event-upsert failure and retry preserve unrelated target-derived rows'
);

$resetTecDerived();
$GLOBALS['tec_readiness_regen_mode'] = 'partial_occurrence';
duo_check_same(
    'duo: TEC derived-state verification failed for tec_occurrences fields: end_date_utc',
    $tecFailure(static fn() => $regenerator->regenerate($tecEventId)),
    'a partially written occurrence refuses on its missing deterministic postcondition'
);
duo_check_same(
    '2001-01-01 00:00:00',
    $tecDb->get_var($tecDb->prepare("SELECT end_date_utc FROM `$occurrenceTable` WHERE post_id = %d", $tecEventId)),
    'the failed verification leaves an explicit partial-occurrence witness for engine rollback or retry'
);
$GLOBALS['tec_readiness_regen_mode'] = 'ok';
$regenerator->regenerate($tecEventId);
duo_check_same(
    '2026-11-02 15:15:00',
    $tecDb->get_var($tecDb->prepare("SELECT end_date_utc FROM `$occurrenceTable` WHERE post_id = %d", $tecEventId)),
    'same-process retry after a value-level verification failure repairs the exact occurrence field'
);

$resetTecDerived();
$GLOBALS['tec_readiness_regen_mode'] = 'wrong_event_link';
duo_check_same(
    'duo: TEC derived-state verification failed for tec_occurrences fields: event_id',
    $tecFailure(static fn() => $regenerator->regenerate($tecEventId)),
    'an occurrence linked to the wrong native event row refuses'
);

$resetTecDerived();
$occurrenceFlood = $tecDb->rows($occurrenceTable);
$occurrencePrototype = $occurrenceFlood[0];
for ($index = 0; $index < 1000; ++$index) {
    $extraOccurrence = $occurrencePrototype;
    $extraOccurrence['occurrence_id'] = 8100000000 + $index;
    $extraOccurrence['event_id'] = 7000000001;
    $extraOccurrence['post_id'] = 6200000000 + $index;
    $occurrenceFlood[] = $extraOccurrence;
}
$tecDb->seedTable($occurrenceTable, $occurrenceFlood);
unset($occurrenceFlood, $occurrencePrototype, $extraOccurrence);
duo_check_same(
    'duo: TEC derived-state verification failed for tec_occurrences fields: row_count',
    $tecFailure(static fn() => $regenerator->regenerate($tecEventId)),
    'a hostile cross-linked occurrence flood refuses without transferring its complete result set'
);
duo_check(
    $tecDb->num_rows === 3 && str_ends_with($tecDb->last_query, 'ORDER BY occurrence_id LIMIT 3'),
    'the hostile occurrence flood transfers only the three-row proof witness'
);

$resetTecDerived();
$GLOBALS['tec_readiness_regen_mode'] = 'orphan_extra';
duo_check_same(
    'duo: TEC derived-state verification failed for tec_occurrences fields: row_count',
    $tecFailure(static fn() => $regenerator->regenerate($tecEventId)),
    'an orphan occurrence sharing the native event identity refuses rather than earning success'
);

$resetTecDerived();
$GLOBALS['tec_readiness_regen_mode'] = 'save_throw';
duo_check_same(
    'injected native occurrence save failure',
    $tecFailure(static fn() => $regenerator->regenerate($tecEventId)),
    'a native occurrence exception surfaces and leaves retry authority to the engine'
);
duo_check_same(
    '2001-01-01 00:00:00',
    $tecDb->get_var($tecDb->prepare("SELECT end_date_utc FROM `$occurrenceTable` WHERE post_id = %d", $tecEventId)),
    'the native occurrence exception is injected after the second derived row is physically written'
);
$GLOBALS['tec_readiness_regen_mode'] = 'ok';
$regenerator->regenerate($tecEventId);
duo_check_same(
    $expectedOccurrenceHash,
    $tecDb->get_var($tecDb->prepare(
        "SELECT hash FROM `$occurrenceTable` WHERE post_id = %d",
        $tecEventId
    )),
    'retry after a partial native occurrence failure converges to the exact deterministic row'
);

duo_check_summary('The Events Calendar production-readiness contract');
