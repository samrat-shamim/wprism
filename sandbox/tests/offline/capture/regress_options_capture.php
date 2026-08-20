<?php
declare(strict_types=1);

/**
 * Direct offline characterization for DUO-3349's options read boundary.
 * The fake WordPress database exercises real Policy, Tokens, PlainData, and
 * OptionState behavior without loading Capture, Snapshot, or WordPress.
 */

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

$GLOBALS['options_capture_rows'] = [];

function untrailingslashit($value): string { return rtrim((string) $value, '/\\'); }
function wp_upload_dir($time = null, $create = true, $refresh = false): array {
    return ['baseurl' => 'https://source.test/wp-content/uploads', 'basedir' => '/tmp/uploads'];
}
function get_option($name, $default = false) {
    if ($name === 'home') {
        return 'https://source.test';
    }
    $row = $GLOBALS['options_capture_rows'][(string) $name] ?? null;
    return $row === null ? $default : maybe_unserialize($row['option_value']);
}
function apply_filters($tag, $value, ...$args) { return $value; }
function is_serialized($value, $strict = true): bool {
    if (!is_string($value)) return false;
    $value = trim($value);
    if ($value === 'N;' || $value === 'b:0;') return true;
    $length = strlen($value);
    if ($length < 4 || $value[1] !== ':') return false;
    if ($strict && !in_array($value[$length - 1], [';', '}'], true)) return false;
    return in_array($value[0], ['a', 'O', 'C', 'E', 'd', 'i', 'b', 's'], true);
}
function maybe_unserialize($value) {
    if (!is_serialized($value)) return $value;
    $decoded = @unserialize($value, ['allowed_classes' => false]);
    return $decoded === false && $value !== 'b:0;' ? $value : $decoded;
}

final class OptionsCaptureFakeWpdb {
    public string $prefix = 'wp_';
    public string $options = 'wp_options';
    public string $last_error = '';
    /** @var array<string,array{option_value:string,autoload:string}> */
    public array $rows = [];
    /** @var array<string,array<int,string>> */
    public array $uuids = [];
    /** @var list<array{sql:string,args:array}> */
    public array $reads = [];

    public function prepare($sql, ...$args): array {
        if (count($args) === 1 && is_array($args[0])) $args = $args[0];
        return ['sql' => (string) $sql, 'args' => $args];
    }

    public function get_row($query, $output = ARRAY_A) {
        [$sql, $args] = $this->unwrap($query);
        $this->reads[] = ['sql' => $sql, 'args' => $args];
        if (str_contains($sql, 'option_value, autoload')) {
            return $this->rows[(string) ($args[0] ?? '')] ?? null;
        }
        return null;
    }

    public function get_results($query, $output = ARRAY_A): array {
        [$sql, $args] = $this->unwrap($query);
        $this->reads[] = ['sql' => $sql, 'args' => $args];
        if (str_contains($sql, 'option_name, option_value')) {
            $out = [];
            foreach ($this->rows as $name => $row) {
                $out[] = ['option_name' => $name, 'option_value' => $row['option_value']];
            }
            return $out;
        }
        return [];
    }

    public function get_var($query) {
        [$sql, $args] = $this->unwrap($query);
        $this->reads[] = ['sql' => $sql, 'args' => $args];
        if (str_contains($sql, 'SELECT uuid FROM wp_duo_map')) {
            return $this->uuids[(string) ($args[0] ?? '')][(int) ($args[1] ?? 0)] ?? null;
        }
        return null;
    }

    private function unwrap($query): array {
        return is_array($query)
            ? [(string) ($query['sql'] ?? ''), (array) ($query['args'] ?? [])]
            : [(string) $query, []];
    }
}

require_once __DIR__ . '/../../../../agent/src/Policy/Policy.php';
require_once __DIR__ . '/../../../../agent/src/Grammar/Tokens.php';
require_once __DIR__ . '/../../../../agent/src/Repository/Ledger.php';
require_once __DIR__ . '/../../../../agent/src/Kernel/OptionState.php';
require_once __DIR__ . '/../../../../agent/src/Capture/OptionsCapture.php';

use Duo\OptionState;
use Duo\OptionsCapture;
use Duo\Policy;
use Duo\Tokens;

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    echo ($ok ? 'ok' : 'FAIL') . ": $message\n";
    if (!$ok) $failures[] = $message;
};

$check(class_exists(OptionsCapture::class), 'OptionsCapture loads as a direct offline boundary');
$check(!class_exists(Duo\Capture::class, false), 'OptionsCapture does not load Capture');
$check(!class_exists(Duo\Snapshot::class, false), 'OptionsCapture does not load Snapshot');

$wpdb = new OptionsCaptureFakeWpdb();
$GLOBALS['wpdb'] = $wpdb;

$postUuid = '11111111-1111-7111-8111-111111111111';
$methodUuid = '22222222-2222-7222-8222-222222222222';
$wpdb->uuids = [
    'post' => [5 => $postUuid],
    'method' => [7 => $methodUuid],
];
$wpdb->rows = [
    'plain_setting' => ['option_value' => 'https://source.test/path', 'autoload' => 'yes'],
    'post_ref' => ['option_value' => '5', 'autoload' => 'yes'],
    'outside_ref' => ['option_value' => '99', 'autoload' => 'yes'],
    'blob_setting' => ['option_value' => serialize([
        'authored_key' => 'https://source.test/blob',
        'runtime_key' => 'local-only',
    ]), 'autoload' => 'yes'],
    'theme_mods_source' => ['option_value' => serialize(['background_color' => 'source-blue']), 'autoload' => 'yes'],
    'theme_mods_target' => ['option_value' => serialize([
        'background_color' => 'target-green',
        'runtime_key' => 'target-local',
    ]), 'autoload' => 'yes'],
    'method_7_settings' => ['option_value' => serialize(['title' => 'Mapped']), 'autoload' => 'yes'],
    'method_8_settings' => ['option_value' => serialize(['title' => 'Unminted']), 'autoload' => 'yes'],
    'active_plugins' => ['option_value' => serialize([17 => 'fixture/main.php']), 'autoload' => 'yes'],
    'template' => ['option_value' => 'target-theme', 'autoload' => 'yes'],
    'stylesheet' => ['option_value' => 'target-theme', 'autoload' => 'yes'],
    'acme_unknown' => ['option_value' => 'needs-review', 'autoload' => 'yes'],
];
$GLOBALS['options_capture_rows'] =& $wpdb->rows;

$policy = new Policy();
$policy->site = ['policy' => [
    'post_types' => ['page'],
    'taxonomies' => [],
    'options' => [
        'plain_setting' => ['class' => 'authored', 'autoload' => 'yes'],
        'post_ref' => ['class' => 'authored', 'ref' => 'post', 'autoload' => 'yes'],
        'outside_ref' => ['class' => 'authored', 'ref' => 'post', 'autoload' => 'yes'],
        'missing_setting' => ['class' => 'authored', 'autoload' => 'yes'],
        'blob_setting' => [
            'class' => 'env',
            'autoload' => 'yes',
            'sub_keys' => [
                'authored_key' => ['class' => 'authored'],
                'runtime_key' => ['class' => 'runtime'],
            ],
        ],
        'active_plugins' => ['class' => 'managed', 'autoload' => 'yes'],
        'template' => ['class' => 'managed', 'autoload' => 'yes'],
        'stylesheet' => ['class' => 'managed', 'autoload' => 'yes'],
    ],
]];
$policy->manifests = [[
    'name' => 'fixture',
    'option_namespaces' => [['match' => '^acme_']],
    'dynamic_options' => [
        'theme_mods' => [
            'prefix' => 'theme_mods_',
            'resolver' => 'active_stylesheet',
            'autoload' => 'yes',
            'sub_keys' => [
                'background_color' => ['class' => 'authored'],
                'runtime_key' => ['class' => 'runtime'],
            ],
        ],
    ],
    'option_name_refs' => [[
        'class' => 'authored',
        'id_kind' => 'method',
        'match' => '^method_(?<id>[1-9][0-9]*)_settings$',
        'autoload' => 'yes',
    ]],
    'tables' => [],
]];

$tokens = new Tokens();
$tokens->policy = $policy;
$secretCalls = [];
$guardSecret = static function (string $section, string $key, $value, array $rule) use (&$secretCalls): void {
    $secretCalls[] = [$section, $key, $value, $rule['class'] ?? null];
};
$classifyScope = static fn(int $id, string $kind, bool $force): ?string =>
    !$force && $kind === 'post' && $id === 99 ? 'private_page' : null;
$rowExists = static fn(Policy $unusedPolicy, string $kind, int $id): bool => $kind === 'method' && $id === 8;

$capture = new OptionsCapture($policy, $tokens, $guardSecret, $classifyScope, $rowExists);
$previous = OptionState::document([
    'missing_setting' => OptionState::present('previous-value', 'yes'),
]);
$result = $capture->capture(
    false,
    false,
    $previous,
    ['active_stylesheet' => 'target'],
    false,
    true
);
$records = OptionState::records($result['document']);

$check(
    ($records['plain_setting']['value'] ?? null) === '{{home}}/path'
        && ($records['post_ref']['value'] ?? null) === '{{post:' . $postUuid . '}}',
    'exact authored values preserve text tokenization and mapped scalar references'
);
$check(
    ($records['blob_setting']['value'] ?? null) === ['authored_key' => '{{home}}/blob']
        && !array_key_exists('runtime_key', $records['blob_setting']['value'] ?? []),
    'sub-key capture includes only authored keys through the shared value codec'
);
$check(
    ($records['theme_mods_target']['value'] ?? null) === ['background_color' => 'target-green']
        && !isset($records['theme_mods_source']),
    'dynamic capture resolves exactly the frozen target name and excludes prefix residue'
);
$canonicalMethod = 'method_{{method:' . $methodUuid . '}}_settings';
$check(
    ($records[$canonicalMethod]['value']['title'] ?? null) === 'Mapped',
    'option-name references rewrite the embedded local id and structurally capture the value'
);
$check(
    ($records['active_plugins']['value'] ?? null) === ['fixture/main.php']
        && ($records['template']['value'] ?? null) === 'target-theme'
        && ($records['stylesheet']['value'] ?? null) === 'target-theme',
    'managed lifecycle records preserve normalized plugin and theme values'
);
$check(
    ($records['missing_setting']['state'] ?? null) === 'deleted'
        && ($records['outside_ref']['state'] ?? null) === 'absent',
    'previous authored absence becomes a tombstone while a live dropped ref remains nonportable'
);
$check(
    $result['unclassified'] === [
        'options:acme_unknown (owner candidate fixture; namespace matched without a classification)',
    ],
    'namespace-owned unknown state is returned to Capture as an explicit gate side channel'
);
$check(
    $result['unscoped_refs'] === [[
        'option' => 'outside_ref', 'kind' => 'post', 'id' => 99, 'target_type' => 'private_page',
    ]],
    'real out-of-scope scalar refs are returned with exact gate evidence'
);
$check(
    $result['unscoped_option_name_refs'] === [[
        'option' => 'method_8_settings', 'id_kind' => 'method', 'id' => 8,
    ]],
    'strict observation reports a real unminted option-name row instead of warning it away'
);
$check(
    array_column($secretCalls, 1) === [
        'outside_ref',
        'plain_setting',
        'post_ref',
        'blob_setting.authored_key',
        'theme_mods_target.background_color',
    ],
    'central secret callback runs in deterministic authored-value order before encoding; actual='
        . json_encode(array_column($secretCalls, 1))
);
$check(
    count(array_filter($wpdb->reads, static fn(array $r): bool =>
        str_contains($r['sql'], 'SELECT option_name, option_value FROM wp_options')))
        === 1,
    'one complete option-name/value scan feeds namespace and option-name discovery'
);

unset(
    $wpdb->rows['theme_mods_target'],
    $wpdb->rows['acme_unknown'],
    $wpdb->rows['outside_ref'],
    $wpdb->rows['method_8_settings']
);
$GLOBALS['options_capture_rows'] =& $wpdb->rows;
$dynamicPrevious = OptionState::document([
    'theme_mods_target' => OptionState::present(['background_color' => 'desired-green'], 'yes'),
]);
$bound = $capture->capture(
    false,
    false,
    $dynamicPrevious,
    ['active_stylesheet' => 'target'],
    true,
    false
);
$boundRecords = OptionState::records($bound['document']);
$check(
    ($boundRecords['theme_mods_target']['state'] ?? null) === 'deleted'
        && hash_equals(
            (string) ($boundRecords['theme_mods_target']['expected_hash'] ?? ''),
            OptionState::record_hash(OptionState::present(['background_color' => 'desired-green'], 'yes'))
        ),
    'lifecycle missing-dynamic binding carries the exact frozen desired-state witness'
);
$check(
    $bound['unclassified'] === []
        && $bound['unscoped_refs'] === []
        && $bound['unscoped_option_name_refs'] === [],
    'each capture resets every collaborator side channel'
);

$captureSource = file_get_contents(__DIR__ . '/../../../../agent/src/Capture/Capture.php');
$workflowSource = file_get_contents(__DIR__ . '/../../../../agent/src/Capture/CapturePublicationWorkflow.php');
$candidateSource = file_get_contents(__DIR__ . '/../../../../agent/src/Capture/CaptureCandidateBuilder.php');
$optionsSource = file_get_contents(__DIR__ . '/../../../../agent/src/Capture/OptionsCapture.php');
$buildStart = strpos((string) $candidateSource, 'private function buildOptions(');
$buildEnd = strpos((string) $candidateSource, "\n    private function", (int) $buildStart + 1);
$buildBody = substr((string) $candidateSource, (int) $buildStart, (int) $buildEnd - (int) $buildStart);
$check(
    str_contains((string) $captureSource, "require_once __DIR__ . '/CapturePublicationWorkflow.php';")
        && str_contains((string) $workflowSource, "require_once __DIR__ . '/CaptureCandidateBuilder.php';")
        && str_contains((string) $workflowSource, '$c = new CaptureCandidateBuilder(')
        && str_contains((string) $candidateSource, "require_once __DIR__ . '/OptionsCapture.php';")
        && str_contains((string) $candidateSource, 'private OptionsCapture $optionsCapture;')
        && str_contains((string) $candidateSource, '$this->optionsCapture = new OptionsCapture('),
    'Capture delegates candidate assembly and the builder binds the extracted options collaborator'
);
$check(
    str_contains($buildBody, '$this->optionsCapture->capture(')
        && str_contains($buildBody, 'array_merge($this->unclassified')
        && !str_contains($buildBody, 'authored_options()')
        && !str_contains($buildBody, 'option_name_ref_match_details'),
    'candidate option assembly is a thin adapter that merges result side channels'
);
$check(
    substr_count((string) $optionsSource, 'SELECT option_name, option_value FROM {$wpdb->options}') === 1
        && !str_contains((string) $optionsSource, 'Snapshot::')
        && !str_contains((string) $optionsSource, 'Capture::'),
    'option read/discovery logic has one owner with no hidden Capture or Snapshot dependency'
);

if ($failures) {
    fwrite(STDERR, "\nREGRESS_OPTIONS_CAPTURE FAILED: " . count($failures) . " assertion(s)\n");
    exit(1);
}

echo "\nREGRESS_OPTIONS_CAPTURE PASSED\n";
