<?php
declare(strict_types=1);

/**
 * Direct offline characterization for issue #3349's options read boundary.
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
    public string $exactResultMode = 'normal';
    public string $discoveryResultMode = 'normal';
    /** @var ?list<mixed> */
    public ?array $exactRowsOverride = null;
    /** @var ?list<mixed> */
    public ?array $discoveryRowsOverride = null;
    public bool $mutateExactSameLengthAfterPreflight = false;

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

    public function get_results($query, $output = ARRAY_A): mixed {
        [$sql, $args] = $this->unwrap($query);
        $this->reads[] = ['sql' => $sql, 'args' => $args];
        if (str_contains($sql, 'COUNT(*) AS row_count')) {
            if ($this->discoveryResultMode === 'false') return false;
            if ($this->discoveryResultMode === 'null') return null;
            if ($this->discoveryResultMode === 'error') {
                $this->last_error = 'simulated option discovery preflight failure';
                return [];
            }
            if ($this->discoveryResultMode === 'associative') {
                return ['not-a-list' => ['row_count' => '0']];
            }
            $rows = $this->discoveryRowsOverride ?? array_map(
                static fn(array $row, string $name): array => [
                    'option_name' => $name, 'option_value' => $row['option_value'],
                ],
                array_values($this->rows),
                array_keys($this->rows)
            );
            $total = 0;
            $maxName = 0;
            $maxNameCharacters = 0;
            $maxValue = 0;
            foreach ($rows as $row) {
                $name = is_array($row) ? ($row['option_name'] ?? '') : '';
                $value = is_array($row) ? ($row['option_value'] ?? '') : '';
                $nameBytes = is_string($name) ? strlen($name) : 0;
                $valueBytes = is_string($value) ? strlen($value) : 0;
                $total += $nameBytes + $valueBytes;
                $maxName = max($maxName, $nameBytes);
                $characters = is_string($name) ? preg_match_all('/./us', $name) : 0;
                $maxNameCharacters = max($maxNameCharacters, is_int($characters) ? $characters : 0);
                $maxValue = max($maxValue, $valueBytes);
            }
            return [[
                'row_count' => (string) count($rows),
                'total_bytes' => (string) $total,
                'max_name_bytes' => (string) $maxName,
                'max_name_characters' => (string) $maxNameCharacters,
                'max_value_bytes' => (string) $maxValue,
            ]];
        }
        if (str_contains($sql, 'autoload_bytes')) {
            if ($this->exactResultMode === 'false') return false;
            if ($this->exactResultMode === 'null') return null;
            if ($this->exactResultMode === 'error') {
                $this->last_error = 'simulated exact option preflight failure';
                return [];
            }
            if ($this->exactResultMode === 'associative') {
                return ['not-a-list' => ['option_name' => 'x', 'option_value_bytes' => '1', 'autoload_bytes' => '3']];
            }
            $name = (string) ($args[0] ?? '');
            $rows = $this->exactRowsOverride;
            if ($rows === null) {
                $row = $this->rows[$name] ?? null;
                $rows = $row === null ? [] : [['option_name' => $name] + $row];
            }
            $projected = array_map(static function ($row) {
                if (!is_array($row)
                    || !array_key_exists('option_name', $row)
                    || !array_key_exists('option_value', $row)
                    || !array_key_exists('autoload', $row)) {
                    return $row;
                }
                return [
                    'option_name' => $row['option_name'],
                    'option_value_bytes' => is_string($row['option_value'])
                        ? (string) strlen($row['option_value'])
                        : '1',
                    'autoload_bytes' => is_string($row['autoload'])
                        ? (string) strlen($row['autoload'])
                        : '1',
                    'option_value_sha256' => is_string($row['option_value'])
                        ? hash('sha256', $row['option_value'])
                        : hash('sha256', ''),
                    'autoload_sha256' => is_string($row['autoload'])
                        ? hash('sha256', $row['autoload'])
                        : hash('sha256', ''),
                ];
            }, $rows);
            if ($this->mutateExactSameLengthAfterPreflight && isset($this->rows[$name])) {
                $this->mutateExactSameLengthAfterPreflight = false;
                $this->rows[$name]['option_value'] = str_repeat(
                    'X',
                    strlen($this->rows[$name]['option_value'])
                );
            }
            return $projected;
        }
        if (str_contains($sql, 'option_value, autoload')) {
            if ($this->exactResultMode === 'false') return false;
            if ($this->exactResultMode === 'null') return null;
            if ($this->exactResultMode === 'error') {
                $this->last_error = 'simulated exact option read failure';
                return [];
            }
            if ($this->exactResultMode === 'associative') {
                return ['not-a-list' => ['option_name' => 'x', 'option_value' => 'x', 'autoload' => 'yes']];
            }
            if ($this->exactRowsOverride !== null) return $this->exactRowsOverride;
            $name = (string) ($args[0] ?? '');
            $row = $this->rows[$name] ?? null;
            return $row === null ? [] : [['option_name' => $name] + $row];
        }
        if (str_contains($sql, 'option_name, option_value')) {
            if ($this->discoveryResultMode === 'false') return false;
            if ($this->discoveryResultMode === 'null') return null;
            if ($this->discoveryResultMode === 'error') {
                $this->last_error = 'simulated option discovery failure';
                return [];
            }
            if ($this->discoveryResultMode === 'associative') {
                return ['not-a-list' => ['option_name' => 'x', 'option_value' => 'x']];
            }
            if ($this->discoveryRowsOverride !== null) return $this->discoveryRowsOverride;
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
        if (str_contains($sql, 'SELECT uuid FROM wp_wprism_map')) {
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

use WPrism\OptionState;
use WPrism\OptionsCapture;
use WPrism\Policy;
use WPrism\Tokens;

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    echo ($ok ? 'ok' : 'FAIL') . ": $message\n";
    if (!$ok) $failures[] = $message;
};

$check(class_exists(OptionsCapture::class), 'OptionsCapture loads as a direct offline boundary');
$check(!class_exists(WPrism\Capture::class, false), 'OptionsCapture does not load Capture');
$check(!class_exists(WPrism\Snapshot::class, false), 'OptionsCapture does not load Snapshot');

$wpdb = new OptionsCaptureFakeWpdb();
$GLOBALS['wpdb'] = $wpdb;

$postUuid = '11111111-1111-7111-8111-111111111111';
$secondPostUuid = '33333333-3333-7333-8333-333333333333';
$methodUuid = '22222222-2222-7222-8222-222222222222';
$wpdb->uuids = [
    'post' => [5 => $postUuid, 6 => $secondPostUuid],
    'method' => [7 => $methodUuid],
];
$wpdb->rows = [
    'plain_setting' => ['option_value' => 'https://source.test/path', 'autoload' => 'yes'],
    'post_ref' => ['option_value' => '5', 'autoload' => 'yes'],
    'csv_post_refs' => ['option_value' => '5, 6', 'autoload' => 'yes'],
    'zero_ref' => ['option_value' => '0', 'autoload' => 'yes'],
    'outside_ref' => ['option_value' => '99', 'autoload' => 'yes'],
    'blob_setting' => ['option_value' => serialize([
        'authored_key' => 'https://source.test/blob',
        'unset_ref' => 0,
        'missing_ref_sentinel' => -1,
        'runtime_key' => 'local-only',
    ]), 'autoload' => 'yes'],
    'native_blob' => ['option_value' => serialize([
        'existing' => 'https://source.test/native',
        'runtime_key' => 'local-native',
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
        'csv_post_refs' => ['class' => 'authored', 'ref' => 'post[]', 'cast' => 'csv', 'autoload' => 'yes'],
        'zero_ref' => ['class' => 'authored', 'ref' => 'post', 'autoload' => 'yes'],
        'outside_ref' => ['class' => 'authored', 'ref' => 'post', 'autoload' => 'yes'],
        'missing_setting' => ['class' => 'authored', 'autoload' => 'yes'],
        'blob_setting' => [
            'class' => 'env',
            'autoload' => 'yes',
            'closed_sub_keys' => true,
            'sub_keys' => [
                'authored_key' => ['class' => 'authored'],
                'unset_ref' => ['class' => 'authored', 'ref' => 'post'],
                'missing_ref_sentinel' => ['class' => 'authored', 'ref' => 'post'],
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
    'interpreter' => 'fixture-native',
    'options' => [
        'native_blob' => [
            'class' => 'env',
            'autoload' => 'yes',
            'absent_autoload' => 'yes',
            'closed_sub_keys' => true,
            'sub_keys' => [
                'existing' => ['class' => 'authored'],
                'added_ref' => ['class' => 'authored', 'ref' => 'post'],
                'added_text' => ['class' => 'authored'],
                'runtime_key' => ['class' => 'runtime'],
            ],
        ],
    ],
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
$nativeCaptureState = (object) ['raw' => null];
$nativeCaptureInterpreter = new class ($nativeCaptureState) {
    public function __construct(private object $state) {}
    public function normalize_captured_option_sub_keys(
        string $name,
        array $raw,
        array $subKeys,
        array $rawOptionSnapshot
    ): array {
        $this->state->raw = $raw;
        return $raw + [
            'added_ref' => 5,
            'added_text' => 'https://source.test/native-default',
        ];
    }
};
$interpreterInstances = new ReflectionProperty(Policy::class, 'interpreterInstances');
$interpreterInstances->setValue($policy, ['fixture-native' => $nativeCaptureInterpreter]);

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
$initialSecretCalls = $secretCalls;
$initialReads = $wpdb->reads;

$check(
    ($records['plain_setting']['value'] ?? null) === '{{home}}/path'
        && ($records['post_ref']['value'] ?? null) === '{{post:' . $postUuid . '}}'
        && array_key_exists('value', $records['zero_ref'] ?? [])
        && $records['zero_ref']['value'] === 0,
    'exact authored values preserve text tokenization, mapped refs, and scalar zero as explicit unset intent'
);
$check(
    ($records['csv_post_refs']['value'] ?? null) === [
        '{{post:' . $postUuid . '}}',
        '{{post:' . $secondPostUuid . '}}',
    ],
    'CSV option refs capture every comma-delimited id instead of coercing only the leading integer'
);
$check(
    ($records['blob_setting']['value'] ?? null) === ['authored_key' => '{{home}}/blob']
        && !array_key_exists('runtime_key', $records['blob_setting']['value'] ?? []),
    'sub-key capture omits zero/negative no-object sentinels while including only authored portable keys'
);
$check(
    $nativeCaptureState->raw === ['existing' => 'https://source.test/native']
        && ($records['native_blob']['value'] ?? null) === [
            'existing' => '{{home}}/native',
            'added_ref' => '{{post:' . $postUuid . '}}',
            'added_text' => '{{home}}/native-default',
        ],
    'native normalization receives raw authored siblings before every returned/defaulted value crosses the ordinary ref/text capture codec'
);

$wpdb->rows['blob_setting']['option_value'] = serialize(['runtime_key' => 'source-local']);
$emptyMixed = OptionState::records($capture->capture(
    false,
    false,
    null,
    ['active_stylesheet' => 'target']
)['document']);
$check(
    ($emptyMixed['blob_setting']['state'] ?? null) === 'present'
        && ($emptyMixed['blob_setting']['value'] ?? null) === [],
    'a present mixed row with zero authored siblings emits explicit empty removal intent'
);
$wpdb->rows['blob_setting']['option_value'] = serialize([
    'authored_key' => 'https://source.test/blob',
    'unset_ref' => 0,
    'missing_ref_sentinel' => -1,
    'runtime_key' => 'local-only',
]);

$nativeRow = $wpdb->rows['native_blob'];
unset($wpdb->rows['native_blob']);
$absentNative = OptionState::records($capture->capture(
    false,
    false,
    null,
    ['active_stylesheet' => 'target']
)['document']);
$check(
    ($absentNative['native_blob']['state'] ?? null) === 'present'
        && ($absentNative['native_blob']['autoload'] ?? null) === 'yes'
        && ($absentNative['native_blob']['value']['added_ref'] ?? null)
            === '{{post:' . $postUuid . '}}',
    'an absent native mixed row projects registered defaults with an exact insertion-storage declaration'
);
$wpdb->rows['native_blob'] = $nativeRow;
$emptyNormalizationInterpreter = new class {
    public function normalize_captured_option_sub_keys(
        string $name,
        array $raw,
        array $subKeys,
        array $rawOptionSnapshot
    ): array {
        return $raw;
    }
};
$interpreterInstances->setValue($policy, ['fixture-native' => $emptyNormalizationInterpreter]);
unset($wpdb->rows['native_blob']);
$absentEmptyNative = OptionState::records($capture->capture(
    false,
    false,
    null,
    ['active_stylesheet' => 'target']
)['document']);
$check(
    ($absentEmptyNative['native_blob']['state'] ?? null) === 'absent',
    'an absent native mixed row with empty normalization stays absent despite absent_autoload'
);
$wpdb->rows['native_blob'] = [
    'option_value' => serialize([]),
    'autoload' => 'yes',
];
$presentEmptyNative = OptionState::records($capture->capture(
    false,
    false,
    null,
    ['active_stylesheet' => 'target']
)['document']);
$check(
    ($presentEmptyNative['native_blob']['state'] ?? null) === 'present'
        && ($presentEmptyNative['native_blob']['value'] ?? null) === [],
    'a physically present empty native mixed row remains explicit present-empty intent'
);
$interpreterInstances->setValue($policy, ['fixture-native' => $nativeCaptureInterpreter]);
$wpdb->rows['native_blob'] = $nativeRow;
$check(
    !array_filter($tokens->warnings, static fn(string $warning): bool => str_contains($warning, 'id -1')),
    'negative sub-key no-object sentinels do not emit false unmanaged-id warnings'
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
    array_column($initialSecretCalls, 1) === [
        'csv_post_refs',
        'outside_ref',
        'plain_setting',
        'post_ref',
        'zero_ref',
        'blob_setting.authored_key',
        'blob_setting.unset_ref',
        'blob_setting.missing_ref_sentinel',
        'native_blob.existing',
        'native_blob.added_ref',
        'native_blob.added_text',
        'theme_mods_target.background_color',
        'method_7_settings',
    ],
    'central secret callback runs in deterministic authored-value order before encoding; actual='
        . json_encode(array_column($initialSecretCalls, 1))
);
$check(
    count(array_filter($initialReads, static fn(array $r): bool =>
        str_contains($r['sql'], 'SELECT option_name, option_value FROM wp_options')))
        === 1,
    'one complete option-name/value scan feeds namespace and option-name discovery'
);

$wpdb->rows['blob_setting']['option_value'] = serialize([
    'authored_key' => 'site',
    'unset_ref' => 0,
    'missing_ref_sentinel' => -1,
    'runtime_key' => 'local-only',
    'future_flag' => false,
]);
$closedSourceRefused = false;
try {
    $capture->capture(false, false, null, ['active_stylesheet' => 'target']);
} catch (RuntimeException $failure) {
    $closedSourceRefused = str_contains($failure->getMessage(), '1 undeclared sibling key(s)')
        && !str_contains($failure->getMessage(), 'future_flag');
}
$check(
    $closedSourceRefused,
    'closed mixed-option capture rejects even a false-valued unknown sibling before publication'
);
$wpdb->rows['blob_setting']['option_value'] = serialize([
    'authored_key' => 'https://source.test/blob',
    'unset_ref' => 0,
    'missing_ref_sentinel' => -1,
    'runtime_key' => 'local-only',
]);

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

foreach (['false', 'null', 'error', 'associative'] as $mode) {
    $wpdb->exactResultMode = $mode;
    try {
        $capture->capture(false, false, null, ['active_stylesheet' => 'target']);
        $exactReadRefused = false;
    } catch (RuntimeException $failure) {
        $exactReadRefused = str_contains($failure->getMessage(), 'exact option');
    }
    $check($exactReadRefused, "$mode exact-option DB result fails closed instead of becoming absence");
    $wpdb->exactResultMode = 'normal';
}

foreach ([
    'duplicate' => [
        ['option_name' => 'plain_setting', 'option_value' => 'one', 'autoload' => 'yes'],
        ['option_name' => 'PLAIN_SETTING', 'option_value' => 'two', 'autoload' => 'yes'],
    ],
    'malformed shape' => [['option_name' => 'plain_setting', 'option_value' => 'one']],
    'malformed type' => [['option_name' => 'plain_setting', 'option_value' => 7, 'autoload' => 'yes']],
    'oversized value' => [[
        'option_name' => 'plain_setting',
        'option_value' => str_repeat('x', 16777217),
        'autoload' => 'yes',
    ]],
] as $label => $rows) {
    $beforeFullReads = count(array_filter($wpdb->reads, static fn(array $read): bool =>
        str_contains($read['sql'], 'SELECT option_name, option_value, autoload')));
    $wpdb->exactRowsOverride = $rows;
    try {
        $capture->capture(false, false, null, ['active_stylesheet' => 'target']);
        $exactShapeRefused = false;
    } catch (RuntimeException $failure) {
        $exactShapeRefused = str_contains($failure->getMessage(), 'exact option');
    }
    $check($exactShapeRefused, "$label exact-option row fails closed without coercion");
    if ($label === 'oversized value') {
        $check(count(array_filter($wpdb->reads, static fn(array $read): bool =>
            str_contains($read['sql'], 'SELECT option_name, option_value, autoload'))) === $beforeFullReads,
            'oversized exact option refuses from compact length evidence before a full-value query');
    }
    $wpdb->exactRowsOverride = null;
}

$wpdb->exactRowsOverride = [[
    'option_name' => 'PLAIN_SETTING',
    'option_value' => 'alias-value',
    'autoload' => 'yes',
]];
try {
    $capture->capture(false, false, null, ['active_stylesheet' => 'target']);
    $caseAliasRefused = false;
} catch (RuntimeException $failure) {
    $caseAliasRefused = str_contains($failure->getMessage(), 'collation-equal option_name alias');
}
$check($caseAliasRefused, 'single-row case alias cannot impersonate the exact logical option identity');
$wpdb->exactRowsOverride = null;

$beforeSameLengthRows = $wpdb->rows;
$wpdb->mutateExactSameLengthAfterPreflight = true;
try {
    $capture->capture(false, false, null, ['active_stylesheet' => 'target']);
    $sameLengthExactRefused = false;
} catch (RuntimeException $failure) {
    $sameLengthExactRefused = str_contains($failure->getMessage(), 'exact option read returned');
}
$check(
    $sameLengthExactRefused,
    'same-name same-length option rewrites cannot cross the compact/full exact-row witness'
);
$wpdb->rows = $beforeSameLengthRows;

foreach (['false', 'null', 'error', 'associative'] as $mode) {
    $wpdb->discoveryResultMode = $mode;
    try {
        $capture->capture(false, false, null, ['active_stylesheet' => 'target']);
        $discoveryReadRefused = false;
    } catch (RuntimeException $failure) {
        $discoveryReadRefused = str_contains($failure->getMessage(), 'option namespace');
    }
    $check($discoveryReadRefused, "$mode option-discovery DB result fails closed instead of becoming empty");
    $wpdb->discoveryResultMode = 'normal';
}

foreach ([
    'duplicate' => [
        ['option_name' => 'duplicate', 'option_value' => 'one'],
        ['option_name' => 'duplicate', 'option_value' => 'two'],
    ],
    'malformed shape' => [['option_name' => 'one']],
    'malformed type' => [['option_name' => 7, 'option_value' => 'one']],
    'control-byte name' => [["option_name" => "bad\nname", 'option_value' => 'one']],
    'oversized ASCII name' => [['option_name' => str_repeat('n', 192), 'option_value' => 'one']],
    'oversized multibyte name' => [['option_name' => str_repeat('🙂', 192), 'option_value' => 'one']],
    'oversized value' => [[
        'option_name' => 'large',
        'option_value' => str_repeat('x', 16777217),
    ]],
] as $label => $rows) {
    $beforeFullScans = count(array_filter($wpdb->reads, static fn(array $read): bool =>
        str_contains($read['sql'], 'SELECT option_name, option_value FROM wp_options')));
    $wpdb->discoveryRowsOverride = $rows;
    try {
        $capture->capture(false, false, null, ['active_stylesheet' => 'target']);
        $discoveryShapeRefused = false;
    } catch (RuntimeException $failure) {
        $discoveryShapeRefused = str_contains($failure->getMessage(), 'option namespace discovery');
    }
    $check($discoveryShapeRefused, "$label option-discovery row fails closed without omission or coercion");
    if (in_array($label, ['oversized ASCII name', 'oversized multibyte name', 'oversized value'], true)) {
        $check(count(array_filter($wpdb->reads, static fn(array $read): bool =>
            str_contains($read['sql'], 'SELECT option_name, option_value FROM wp_options'))) === $beforeFullScans,
            "$label refuses from aggregate size evidence before a full namespace-value scan");
    }
    $wpdb->discoveryRowsOverride = null;
}

$wpdb->discoveryRowsOverride = [[
    'option_name' => str_repeat('🙂', 191),
    'option_value' => 'valid-unrelated-option',
]];
try {
    $capture->capture(false, false, null, ['active_stylesheet' => 'target']);
    $multibyteBoundaryAccepted = true;
} catch (Throwable) {
    $multibyteBoundaryAccepted = false;
}
$check(
    $multibyteBoundaryAccepted,
    'option discovery accepts the wp_options varchar(191) multibyte character boundary (764 UTF-8 bytes)'
);
$wpdb->discoveryRowsOverride = null;

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
