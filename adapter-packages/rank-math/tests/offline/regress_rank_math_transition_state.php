<?php
declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/ShellProbe.php';
require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/wp_stubs.php';
require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/FakeWpdb.php';

use WPrismTest\FakeWpdb;
use WPrismTest\ShellProbe;
use WPrismTest\WpStore;

$root = dirname(__DIR__, 4);
$sourceRoot = $argv[1] ?? $root;
$matrix = (string) file_get_contents($sourceRoot . '/adapter-packages/rank-math/tests/certify/version-matrix.sh');
$check = (string) file_get_contents($sourceRoot . '/adapter-packages/rank-math/fixtures/native-observer.sh');

$transitionStart = strpos($matrix, 'assert_rank_math_transition_content() {');
$transitionEnd = $transitionStart === false ? false : strpos($matrix, 'rank_math_private_evidence() {', $transitionStart);
wprism_check(is_int($transitionStart) && is_int($transitionEnd),
    'the matrix owns one dedicated read-only transition assertion boundary');
if (!is_int($transitionStart) || !is_int($transitionEnd)) {
    wprism_check_summary('regress_rank_math_transition_state');
}
$transitionFunction = substr($matrix, $transitionStart, $transitionEnd - $transitionStart);

/** @param array<string,int> $ids @param array<string,mixed> $targetOwned @return array<string,mixed> */
function rank_transition_observation(
    string $version,
    array $ids,
    array $targetOwned,
    string $port,
    string $description = 'Scoped Rank Math description 東京 🚀 with exact recovery.',
    string $hits = '38',
    string $lastAccessed = '2026-09-05 09:53:56'
): array {
    $modules = ['link-counter', 'redirections', 'rich-snippet', 'image-seo'];
    return [
        'version' => $version,
        'ids' => $ids,
        'modules' => $modules,
        'setup' => ['configured' => '1', 'registration_skip' => '1'],
        'options' => [
            'breadcrumbs_home_label' => 'Origin 東京 🚀',
            'plain_large_bytes' => 200000,
            'plain_nested' => ['enabled' => true, 'nullable' => null, 'threshold' => 0],
            'homepage_image_id' => $ids['attachment'],
            'local_seo_about_page' => $ids['hub'],
            'local_seo_contact_page' => $ids['post'],
            'logo_id' => $ids['attachment'],
            'open_graph_image_id' => $ids['attachment'],
        ],
        'post' => [
            'title' => 'Portable Rank Math Title 東京 🚀 %sep% %sitename%',
            'description' => $description,
            'canonical' => "http://localhost:$port/rank-math-canonical/東京/?a=1&b=two",
            'facebook_image_id' => $ids['attachment'],
            'primary_category' => $ids['category'],
            'processed' => true,
        ],
        'term' => [
            'description' => 'Portable category SEO description 東京 🚀.',
            'facebook_image_id' => $ids['attachment'],
            'title' => 'Portable Category Title 東京 🚀 %sep% %sitename%',
        ],
        'redirection' => [
            'id' => '9400001',
            'sources' => 'fixture-serialized-sources',
            'url_to' => "http://localhost:$port/rank-math-hub/?from=redirect",
            'header_code' => '302',
            'hits' => $hits,
            'status' => 'active',
            'created' => '2020-01-01 00:00:00',
            'updated' => '2020-01-02 00:00:00',
            'last_accessed' => $lastAccessed,
            'sources_shape' => [['ignore' => '', 'pattern' => 'rank-math-old', 'comparison' => 'exact']],
        ],
        'redirection_count' => 1,
        'redirection_cache_count' => 1,
        'links' => [
            [
                'url' => 'https://external.example.test/rank-math?x=1&amp;y=2',
                'post_id' => (string) $ids['post'],
                'target_post_id' => '0',
                'type' => 'external',
            ],
            [
                'url' => "http://localhost:$port/rank-math-hub/",
                'post_id' => (string) $ids['post'],
                'target_post_id' => (string) $ids['hub'],
                'type' => 'internal',
            ],
        ],
        'post_counts' => ['internal_link_count' => '1', 'external_link_count' => '1', 'incoming_link_count' => '0'],
        'hub_counts' => ['internal_link_count' => '0', 'external_link_count' => '0', 'incoming_link_count' => '1'],
        'schema' => [
            'rank_math_internal_links' => [
                'present' => true,
                'columns' => ['id', 'url', 'post_id', 'target_post_id', 'type'],
            ],
            'rank_math_internal_meta' => [
                'present' => true,
                'columns' => ['object_id', 'internal_link_count', 'external_link_count', 'incoming_link_count'],
            ],
            'rank_math_redirections' => [
                'present' => true,
                'columns' => ['id', 'sources', 'url_to', 'header_code', 'hits', 'status', 'created', 'updated', 'last_accessed'],
            ],
            'rank_math_redirections_cache' => [
                'present' => true,
                'columns' => ['id', 'from_url', 'redirection_id', 'object_id', 'object_type', 'is_redirected'],
            ],
        ],
        'target_owned' => $targetOwned,
    ];
}

/** @return array<string,mixed> */
function rank_transition_receipt(): array {
    $hashes = [
        'link_hash' => str_repeat('a', 64),
        'meta_hash' => str_repeat('b', 64),
        'marker_hash' => str_repeat('c', 64),
        'dependency_hash' => str_repeat('d', 64),
        'dependency_state_hash' => str_repeat('e', 64),
    ];
    return [
        'warnings' => ['provider capability fired: rank-math-state (verified)'],
        'canary' => 'clean',
        'verification' => ['result' => 'pass'],
        'actions' => [[
            'source' => 'provider:rank-math-state/rebuild_all_link_state',
            'verified' => true,
            'before' => array_merge(['enabled' => true, 'link_count' => 2, 'meta_count' => 2, 'marker_count' => 2], $hashes),
            'after' => array_merge(['enabled' => true, 'link_count' => 2, 'meta_count' => 2, 'marker_count' => 2], $hashes),
        ]],
    ];
}

/** @return array{int,string,string} */
function rank_transition_shell(
    string $root,
    string $function,
    string $version,
    array $source,
    array $before,
    array $target,
    array $receipt
): array {
    $script = <<<'SH'
set -euo pipefail
fail() { printf 'TRANSITION_REFUSED:%s\n' "$1" >&2; exit 1; }
require_observed_nonempty() { [ -n "$2" ] || fail "$1 is empty"; }
mutation_attempt() { printf 'MUTATION_ATTEMPT:%s\n' "$*" >&2; exit 90; }
wp_conf1() { mutation_attempt wp_conf1 "$@"; }
wp_conf2() { mutation_attempt wp_conf2 "$@"; }
wp1() { mutation_attempt wp1 "$@"; }
wp2() { mutation_attempt wp2 "$@"; }
host_wprism() { mutation_attempt host_wprism "$@"; }
commit_rank_math_source() { mutation_attempt commit_rank_math_source "$@"; }
git() { mutation_attempt git "$@"; }
CONF2_PORT=9321
SH;
    return ShellProbe::run(
        $script . "\n" . $function . <<<'SH'

assert_rank_math_transition_content fixture "$1" "$2" "$3" "$4" "$5"
printf 'TRANSITION_READY\n'
SH,
        [
            $version,
            json_encode($source, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            json_encode($before, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            json_encode($target, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            json_encode($receipt, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ],
        $root
    );
}

$sourceIds = ['attachment' => 3100003, 'category' => 3200001, 'hub' => 3100001,
    'post' => 3100002, 'secondary' => 3200002, 'tag' => 3200003];
$targetIds = ['attachment' => 9100003, 'category' => 9200001, 'hub' => 9100001,
    'post' => 9100002, 'secondary' => 9200002, 'tag' => 9200003];
$targetOwned = [
    'instant_key' => 'PRIVATE_TARGET_CREDENTIAL',
    'indexnow_log' => [[
        'url' => 'http://localhost:9321/target-indexnow-history-must-survive/',
        'status' => 202,
        'manual_submission' => true,
        'message' => 'target runtime submission history',
        'time' => 1800000002,
    ]],
    'sitemap_posts' => (string) $targetIds['post'],
    'notifications' => [[
        'message' => 'Target runtime notification must survive',
        'options' => ['id' => 'wprism-target-runtime', 'classes' => 'rank-math-notice',
            'type' => 'success', 'screen' => 'any', 'capability' => ''],
    ]],
    'neighbor' => 'PRIVATE_TARGET_NEIGHBOR',
];
$before = rank_transition_observation('1.0.277', $targetIds, $targetOwned, '9321');
$upgradeSource = rank_transition_observation('1.0.277.2', $sourceIds, ['source' => true], '9320');
$upgradeTarget = rank_transition_observation('1.0.277.2', $targetIds, $targetOwned, '9321');
$downgradeSource = rank_transition_observation('1.0.277.1', $sourceIds, ['source' => true], '9320');
$downgradeTarget = rank_transition_observation('1.0.277.1', $targetIds, $targetOwned, '9321');
$receipt = rank_transition_receipt();

[$status, $stdout, $stderr] = rank_transition_shell(
    $root,
    $transitionFunction,
    '1.0.277.2',
    $upgradeSource,
    $before,
    $upgradeTarget,
    $receipt
);
wprism_check($status === 0 && str_contains($stdout, "TRANSITION_READY\n") && $stderr === '',
    'the actual transition helper accepts the evolved post-scope upgrade state without mutating WordPress');
if ($status !== 0 || !str_contains($stdout, "TRANSITION_READY\n") || $stderr !== '') {
    wprism_check_detail("upgrade transition exit=$status; stdout=$stdout; stderr=$stderr");
}
[$status, $stdout, $stderr] = rank_transition_shell(
    $root,
    $transitionFunction,
    '1.0.277.1',
    $downgradeSource,
    $upgradeTarget,
    $downgradeTarget,
    $receipt
);
wprism_check($status === 0 && str_contains($stdout, "TRANSITION_READY\n") && $stderr === '',
    'the same read-only helper accepts the sequential downgrade without resetting evolved state');
if ($status !== 0 || !str_contains($stdout, "TRANSITION_READY\n") || $stderr !== '') {
    wprism_check_detail("downgrade transition exit=$status; stdout=$stdout; stderr=$stderr");
}

$mutations = [
    'stale-description' => static function (array &$source, array &$before, array &$target, array &$receipt): void {
        $target['post']['description'] = 'Portable Rank Math description 東京 🚀 with | = : delimiters.';
    },
    'stale-description-baseline' => static function (array &$source, array &$before, array &$target, array &$receipt): void {
        $before['post']['description'] = 'Portable Rank Math description 東京 🚀 with | = : delimiters.';
    },
    'reset-runtime-counter' => static function (array &$source, array &$before, array &$target, array &$receipt): void {
        $target['redirection']['hits'] = '37';
    },
    'corrupt-target-owned' => static function (array &$source, array &$before, array &$target, array &$receipt): void {
        $target['target_owned']['neighbor'] = 'PRIVATE_CORRUPTED_NEIGHBOR';
    },
    'empty-links' => static function (array &$source, array &$before, array &$target, array &$receipt): void {
        $target['links'] = [];
    },
    'failed-schema-read' => static function (array &$source, array &$before, array &$target, array &$receipt): void {
        $target['schema']['rank_math_internal_links']['present'] = false;
        $target['schema']['rank_math_internal_links']['columns'] = [];
    },
    'empty-count-read' => static function (array &$source, array &$before, array &$target, array &$receipt): void {
        $target['post_counts'] = null;
    },
    'wrong-version' => static function (array &$source, array &$before, array &$target, array &$receipt): void {
        $target['version'] = '1.0.277';
    },
    'missing-module' => static function (array &$source, array &$before, array &$target, array &$receipt): void {
        array_pop($target['modules']);
    },
    'unverified-provider' => static function (array &$source, array &$before, array &$target, array &$receipt): void {
        $receipt['actions'][0]['verified'] = false;
    },
];
foreach ($mutations as $name => $mutate) {
    $candidateSource = $upgradeSource;
    $candidateBefore = $before;
    $candidateTarget = $upgradeTarget;
    $candidateReceipt = $receipt;
    $mutate($candidateSource, $candidateBefore, $candidateTarget, $candidateReceipt);
    [$status, $stdout, $stderr] = rank_transition_shell(
        $root,
        $transitionFunction,
        '1.0.277.2',
        $candidateSource,
        $candidateBefore,
        $candidateTarget,
        $candidateReceipt
    );
    wprism_check($status !== 0 && !str_contains($stdout, 'TRANSITION_READY')
        && str_contains($stderr, 'TRANSITION_REFUSED:')
        && !str_contains($stderr, 'PRIVATE_'),
        "the actual transition helper fails closed and keeps diagnostics value-free: $name");
}

$mutatingFunction = preg_replace(
    '/\n  local label=/',
    "\n  wp_conf2 option update forbidden value\n  local label=",
    $transitionFunction,
    1
);
wprism_check(is_string($mutatingFunction) && $mutatingFunction !== $transitionFunction,
    'the mutation control modifies the exact transition function body');
if (is_string($mutatingFunction)) {
    [$status, $stdout, $stderr] = rank_transition_shell(
        $root,
        $mutatingFunction,
        '1.0.277.2',
        $upgradeSource,
        $before,
        $upgradeTarget,
        $receipt
    );
    wprism_check($status !== 0 && !str_contains($stdout, 'TRANSITION_READY')
        && str_contains($stderr, 'MUTATION_ATTEMPT:wp_conf2')
        && !str_contains($stderr, 'PRIVATE_'),
        'the actual-shell boundary refuses a transition assertion that attempts WordPress mutation');
}

preg_match_all('/^[ \t]*check_rank_math_boundary_content[ \t]*$/m', $matrix, $fullBoundaryCalls);
wprism_check(count($fullBoundaryCalls[0] ?? []) === 1,
    'the mutation-heavy boundary check runs only once per freshly reset matrix cell');
wprism_check(substr_count($matrix, 'assert_rank_math_transition_content ') === 2,
    'upgrade and downgrade each select the dedicated read-only transition oracle');

$observeStart = strpos($check, 'observe_rank_math() {');
$programMarker = $observeStart === false ? false : strpos($check, "cat > \"\$file\" <<'PHPEOF'\n", $observeStart);
$programStart = $programMarker === false ? false : $programMarker + strlen("cat > \"\$file\" <<'PHPEOF'\n");
$programEnd = $programStart === false ? false : strpos($check, "\nPHPEOF", $programStart);
wprism_check(is_int($programStart) && is_int($programEnd),
    'the conformance observer retains one extractable actual PHP program');
if (!is_int($programStart) || !is_int($programEnd)) {
    wprism_check_summary('regress_rank_math_transition_state');
}
$observerProgram = substr($check, $programStart, $programEnd - $programStart);
$observerProgram = preg_replace('/^<\?php\R/', '', $observerProgram, 1);
if (!is_string($observerProgram)) {
    throw new RuntimeException('could not extract Rank Math conformance observer');
}

if (!class_exists('WP_Post', false)) {
    class WP_Post {
        public function __construct(public int $ID) {
        }
    }
}
if (!class_exists('WP_Term', false)) {
    class WP_Term {
        public function __construct(public int $term_id) {
        }
    }
}

/** @return object|false */
function get_page_by_path(string $slug, string $output = OBJECT, string $postType = 'page') {
    return $GLOBALS['rank_transition_posts'][$postType . ':' . $slug] ?? false;
}

/** @return object|false */
function get_term_by(string $field, string $value, string $taxonomy) {
    return $GLOBALS['rank_transition_terms'][$taxonomy . ':' . $value] ?? false;
}

function get_post_meta(int $postId, string $key, bool $single = false): mixed {
    return $GLOBALS['rank_transition_postmeta'][$postId][$key] ?? ($single ? '' : []);
}

function get_term_meta(int $termId, string $key, bool $single = false): mixed {
    return $GLOBALS['rank_transition_termmeta'][$termId][$key] ?? ($single ? '' : []);
}

function maybe_unserialize(mixed $value): mixed {
    if (!is_string($value)) {
        return $value;
    }
    $decoded = @unserialize($value);
    return $decoded === false && $value !== 'b:0;' ? $value : $decoded;
}

/** Build native rows rather than pre-transcribed answers to the observer SQL. */
function rank_transition_database(): FakeWpdb {
    WpStore::reset()->seedOptions([
        'rank_math_modules' => ['link-counter', 'redirections', 'rich-snippet', 'image-seo'],
        'rank_math_is_configured' => '1',
        'rank_math_registration_skip' => '1',
        'rank-math-options-general' => [
            'breadcrumbs_home_label' => 'Origin 東京 🚀',
            'wprism_plain_data' => [
                'large' => str_repeat('x', 200000),
                'nested' => ['enabled' => true, 'nullable' => null, 'threshold' => 0],
            ],
        ],
        'rank-math-options-titles' => [
            'homepage_facebook_image_id' => 9100003,
            'local_seo_about_page' => 9100001,
            'local_seo_contact_page' => 9100002,
            'knowledgegraph_logo_id' => 9100003,
            'open_graph_image_id' => 9100003,
        ],
        'rank-math-options-instant-indexing' => ['indexnow_api_key' => 'PRIVATE_TARGET_CREDENTIAL'],
        'rank-math-options-sitemap' => ['exclude_posts' => '9100002'],
        'rank_math_indexnow_log' => [['status' => 202]],
        'rank_math_notifications' => [['message' => 'target runtime']],
        'wprism_rank_math_target_neighbor' => 'PRIVATE_TARGET_NEIGHBOR',
    ]);
    $GLOBALS['rank_transition_posts'] = [
        'post:rank-math-article' => new WP_Post(9100002),
        'page:rank-math-hub' => new WP_Post(9100001),
        'attachment:wprism-rank-math-social' => new WP_Post(9100003),
    ];
    $GLOBALS['rank_transition_terms'] = [
        'category:rank-math-primary' => new WP_Term(9200001),
        'category:rank-math-secondary' => new WP_Term(9200002),
        'post_tag:rank-math-portable' => new WP_Term(9200003),
    ];
    $GLOBALS['rank_transition_postmeta'] = [
        9100002 => [
            'rank_math_title' => 'Portable Rank Math Title 東京 🚀 %sep% %sitename%',
            'rank_math_description' => 'Scoped Rank Math description 東京 🚀 with exact recovery.',
            'rank_math_canonical_url' => 'http://localhost:9321/rank-math-canonical/東京/?a=1&b=two',
            'rank_math_facebook_image_id' => '9100003',
            'rank_math_primary_category' => '9200001',
            'rank_math_internal_links_processed' => '1',
        ],
    ];
    $GLOBALS['rank_transition_termmeta'] = [
        9200001 => [
            'rank_math_description' => 'Portable category SEO description 東京 🚀.',
            'rank_math_facebook_image_id' => '9100003',
            'rank_math_title' => 'Portable Category Title 東京 🚀 %sep% %sitename%',
        ],
    ];
    $db = FakeWpdb::install();
    $db->seedTable('wp_rank_math_internal_links', [
        ['id' => 1, 'url' => 'https://external.example.test/rank-math?x=1&amp;y=2',
            'post_id' => 9100002, 'target_post_id' => 0, 'type' => 'external'],
        ['id' => 2, 'url' => 'http://localhost:9321/rank-math-hub/',
            'post_id' => 9100002, 'target_post_id' => 9100001, 'type' => 'internal'],
    ])->setColumns('wp_rank_math_internal_links', [
        'id' => 'bigint', 'url' => 'varchar(255)', 'post_id' => 'bigint',
        'target_post_id' => 'bigint', 'type' => 'varchar(8)',
    ]);
    $db->seedTable('wp_rank_math_internal_meta', [
        ['object_id' => 9100001, 'internal_link_count' => 0, 'external_link_count' => 0, 'incoming_link_count' => 1],
        ['object_id' => 9100002, 'internal_link_count' => 1, 'external_link_count' => 1, 'incoming_link_count' => 0],
    ])->setColumns('wp_rank_math_internal_meta', [
        'object_id' => 'bigint', 'internal_link_count' => 'int',
        'external_link_count' => 'int', 'incoming_link_count' => 'int',
    ]);
    $sources = serialize([['ignore' => '', 'pattern' => 'rank-math-old', 'comparison' => 'exact']]);
    $db->seedTable('wp_rank_math_redirections', [[
        'id' => 9400001,
        'sources' => $sources,
        'url_to' => 'http://localhost:9321/rank-math-hub/?from=redirect',
        'header_code' => 302,
        'hits' => 38,
        'status' => 'active',
        'created' => '2020-01-01 00:00:00',
        'updated' => '2020-01-02 00:00:00',
        'last_accessed' => '2026-09-05 09:53:56',
    ]])->setColumns('wp_rank_math_redirections', [
        'id' => 'bigint', 'sources' => 'longtext', 'url_to' => 'longtext',
        'header_code' => 'smallint', 'hits' => 'bigint', 'status' => 'varchar(25)',
        'created' => 'datetime', 'updated' => 'datetime', 'last_accessed' => 'datetime',
    ]);
    $db->seedTable('wp_rank_math_redirections_cache', [[
        'id' => 1, 'from_url' => 'rank-math-old', 'redirection_id' => 9400001,
        'object_id' => 0, 'object_type' => 'post', 'is_redirected' => 1,
    ]])->setColumns('wp_rank_math_redirections_cache', [
        'id' => 'bigint', 'from_url' => 'longtext', 'redirection_id' => 'bigint',
        'object_id' => 'bigint', 'object_type' => 'varchar(10)', 'is_redirected' => 'tinyint',
    ]);
    return $db;
}

/** @return array{output:string,failure:?Throwable,queries:list<string>} */
function rank_transition_observer_run(string $program): array {
    global $wpdb;
    ob_start();
    $failure = null;
    try {
        eval("declare(strict_types=0);\n" . $program);
    } catch (Throwable $caught) {
        $failure = $caught;
    } finally {
        $output = (string) ob_get_clean();
    }
    return ['output' => $output, 'failure' => $failure, 'queries' => $wpdb->queries()];
}

if (!defined('RANK_MATH_VERSION')) {
    define('RANK_MATH_VERSION', '1.0.277.2');
}
$db = rank_transition_database();
$healthy = rank_transition_observer_run($observerProgram);
$healthyJson = json_decode(trim($healthy['output']), true);
wprism_check($healthy['failure'] === null && is_array($healthyJson)
    && ($healthyJson['post']['description'] ?? null) === 'Scoped Rank Math description 東京 🚀 with exact recovery.'
    && ($healthyJson['links'] ?? null) !== [],
    'the actual conformance observer publishes one complete evolved native state');
wprism_check(count($healthy['queries']) === 13,
    'the conformance observer checks every row, count, table-presence, and schema-column read');

foreach ($healthy['queries'] as $offset => $query) {
    $db = rank_transition_database();
    $db->failNextQuery('PRIVATE_DATABASE_DRIVER_PAYLOAD', $query);
    $failed = rank_transition_observer_run($observerProgram);
    wprism_check($failed['failure'] instanceof RuntimeException && $failed['output'] === ''
        && !str_contains($failed['failure']?->getMessage() ?? '', 'PRIVATE_DATABASE_DRIVER_PAYLOAD'),
        'the actual observer refuses and redacts a failed database read: statement ' . ($offset + 1));
}

$emptyReads = [
    'redirections' => static function (FakeWpdb $db): void {
        $db->returnNextGetResultsAs([], 'SELECT id,sources,url_to');
    },
    'links' => static function (FakeWpdb $db): void {
        $db->returnNextGetResultsAs([], 'SELECT url,post_id,target_post_id,type');
    },
    'post counts' => static function (FakeWpdb $db): void {
        $db->returnNextGetRowAs(null, 'object_id=9100002');
    },
];
foreach ($emptyReads as $name => $emptyRead) {
    $db = rank_transition_database();
    $emptyRead($db);
    $failed = rank_transition_observer_run($observerProgram);
    wprism_check($failed['failure'] instanceof RuntimeException && $failed['output'] === '',
        "the actual observer refuses an empty required native read: $name");
}

$db = rank_transition_database();
$db->query('DROP TABLE wp_rank_math_internal_links');
$missing = rank_transition_observer_run($observerProgram);
wprism_check($missing['failure'] instanceof RuntimeException && $missing['output'] === '',
    'the exact conformance observer refuses an absent required schema instead of publishing hollow state');

wprism_check_summary('regress_rank_math_transition_state');
