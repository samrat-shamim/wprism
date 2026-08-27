<?php
declare(strict_types=1);

/** Closed offline boundary for Ninja Forms 3.x table and add-on content. */

if (!defined('DUO_SPEC_VERSION')) {
    define('DUO_SPEC_VERSION', 3);
}

require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/check.php';
require_once __DIR__ . '/../../../../agent/src/Kernel/Canon.php';
require_once __DIR__ . '/../../../../agent/src/Kernel/Secrets.php';
require_once __DIR__ . '/../../../../agent/src/Policy/Policy.php';
require_once __DIR__ . '/../../../../adapter-packages/ninja-forms/package/runtime/interpreters/ninja-forms.php';

use Duo\Interpreters\NinjaForms;
use Duo\Policy;
use Duo\Secrets;

/** @return array<string,mixed> */
function ninja_readiness_entity(
    string $table,
    array $columns = [],
    array $meta = [],
    string $uuid = '11111111-1111-4111-8111-111111111111'
): array {
    return [
        'type' => $table,
        'path' => "tables/$table/$uuid--readiness.json",
        'data' => [
            'table' => $table,
            'uuid' => $uuid,
            'columns' => $columns,
            'meta' => $meta,
        ],
    ];
}

/** @return list<array<string,mixed>> */
function ninja_readiness_diagnostics(NinjaForms $interpreter, array $entities): array {
    return $interpreter->repository_diagnostics($entities);
}

/** @return list<string> */
function ninja_readiness_messages(array $diagnostics): array {
    return array_map(static fn(array $row): string => (string) ($row['message'] ?? ''), $diagnostics);
}

/** @return array<string,mixed> */
function ninja_readiness_one_problem(
    NinjaForms $interpreter,
    string $table,
    array $columns,
    array $meta = []
): array {
    $diagnostics = ninja_readiness_diagnostics(
        $interpreter,
        [ninja_readiness_entity($table, $columns, $meta)]
    );
    duo_check_same(1, count($diagnostics), 'hostile Ninja Forms entity emits one bounded diagnostic');
    return $diagnostics[0];
}

$policy = Policy::load(null, ['ninja-forms']);
$interpreter = $policy->interpreters()['ninja-forms'] ?? null;
duo_check($interpreter instanceof NinjaForms, 'shipped manifest resolves its digest-bound Ninja Forms interpreter');

$fieldTypes = [
    'address', 'address2', 'button', 'checkbox', 'city', 'confirm',
    'creditcard', 'creditcardcvc', 'creditcardexpiration',
    'creditcardfullname', 'creditcardnumber', 'creditcardzip', 'date',
    'email', 'firstname', 'hcaptcha', 'hidden', 'hr', 'html', 'lastname',
    'listcheckbox', 'listcountry', 'listimage', 'listmultiselect',
    'listradio', 'listselect', 'liststate', 'note', 'number', 'password',
    'passwordconfirm', 'phone', 'product', 'quantity', 'recaptcha',
    'recaptcha_v3', 'repeater', 'shipping', 'signature', 'spam',
    'starrating', 'submit', 'terms', 'textarea', 'textbox', 'total',
    'turnstile', 'unknown', 'zip',
];
$actionTypes = [
    'akismet', 'collectpayment', 'custom', 'deletedatarequest', 'email',
    'exportdatarequest', 'googleanalytics', 'recaptcha', 'redirect', 'save',
    'successmessage',
];

$builtins = [];
$counter = 1;
foreach ($fieldTypes as $type) {
    $builtins[] = ninja_readiness_entity(
        'nf3_fields',
        ['type' => $type, 'label' => "Core field $type", 'default_value' => ''],
        ['label' => "Core field $type"],
        sprintf('11111111-1111-4111-8111-%012d', $counter++)
    );
}
foreach ($actionTypes as $type) {
    $builtins[] = ninja_readiness_entity(
        'nf3_actions',
        ['type' => $type, 'label' => "Core action $type"],
        ['label' => "Core action $type"],
        sprintf('22222222-2222-4222-8222-%012d', $counter++)
    );
}
duo_check_same(
    [],
    ninja_readiness_diagnostics($interpreter, $builtins),
    'source-reviewed built-in type union across exact minimum/current artifacts compiles cleanly'
);

$longUtf8 = str_repeat('Long UTF-8 — مرحبا — こんにちは — ', 50000);
$validPlainData = [
    'scalars' => [null, false, PHP_INT_MAX, 12345.678, $longUtf8],
    'nested' => [
        'choices' => [['label' => 'A', 'value' => 0], ['label' => '界', 'value' => true]],
        'empty' => [],
        'url' => 'https://source.example.test/ninja?form=1',
    ],
];
duo_check_same(
    [],
    ninja_readiness_diagnostics($interpreter, [
        ninja_readiness_entity(
            'nf3_fields',
            ['type' => 'listselect'],
            ['options' => $validPlainData, 'image_options' => [], 'shipping_options' => []]
        ),
        ninja_readiness_entity(
            'nf3_forms',
            ['title' => 'Plain arrays'],
            ['calculations' => [], 'formContentData' => ['field_key']],
            '33333333-3333-4333-8333-333333333333'
        ),
        ninja_readiness_entity(
            'nf3_actions',
            ['type' => 'save'],
            ['exception_fields' => [['form_field' => 'email']]],
            '44444444-4444-4444-8444-444444444444'
        ),
    ]),
    'all source-reviewed core array keys admit bounded native scalar, nested and large UTF-8 plain data'
);
$rawCanonical = ninja_readiness_one_problem(
    $interpreter,
    'nf3_fields',
    ['type' => 'listselect'],
    ['options' => serialize($validPlainData)]
);
duo_check(
    str_contains((string) $rawCanonical['message'], 'raw PHP serialization'),
    'canonical state refuses valid storage serialization that escaped the manifest plain-data codec'
);
$unknownArray = ninja_readiness_one_problem(
    $interpreter,
    'nf3_fields',
    ['type' => 'textbox'],
    ['vendor_options' => ['url' => 'https://source.example.test']]
);
duo_check(
    str_contains((string) $unknownArray['message'], 'optional add-on metadata'),
    'an undeclared structured add-on setting stays outside the built-in adapter'
);
duo_check_same(
    [],
    ninja_readiness_diagnostics($interpreter, [ninja_readiness_entity(
        'nf3_fields',
        ['type' => 'textbox', 'default_value' => 's:not actually PHP serialization'],
        ['instructions' => 'a: plain prose beginning with a serialization token']
    )]),
    'ordinary authored text beginning with token-like prose is not falsely treated as serialization'
);

foreach ([
    'vendor/file-upload' => ['nf3_fields', ['type' => 'file_upload']],
    'vendor/conditional-action' => ['nf3_actions', ['type' => 'conditional_logic']],
    'empty field type' => ['nf3_fields', ['type' => '']],
    'structured field type' => ['nf3_fields', ['type' => ['textbox']]],
] as $label => [$table, $columns]) {
    $problem = ninja_readiness_one_problem($interpreter, $table, $columns);
    duo_check_same('adapter_schema_content_mismatch', $problem['code'] ?? null, "$label uses the blocking adapter diagnostic");
    duo_check_same('columns.type', $problem['locator'] ?? null, "$label points to the exact type column");
    duo_check(str_contains((string) ($problem['message'] ?? ''), 'outside this adapter'), "$label stays loud at the optional add-on boundary");
}

$malformed = ninja_readiness_one_problem(
    $interpreter,
    'nf3_fields',
    ['type' => 'textbox'],
    ['options' => 'a:1:{i:0;s:5:"short";']
);
duo_check(str_contains((string) $malformed['message'], 'malformed'), 'malformed serialized settings refuse');

$trailing = ninja_readiness_one_problem(
    $interpreter,
    'nf3_fields',
    ['type' => 'textbox', 'default_value' => serialize(['clean']) . 'hidden-tail'],
    []
);
duo_check_same('columns.default_value', $trailing['locator'] ?? null, 'serialized column trailing bytes name the exact column');
duo_check(str_contains((string) $trailing['message'], 'trailing bytes'), 'serialized trailing bytes refuse instead of being ignored by unserialize');

$object = ninja_readiness_one_problem(
    $interpreter,
    'nf3_actions',
    ['type' => 'email'],
    ['settings' => 'O:8:"stdClass":1:{s:5:"token";s:6:"hidden";}']
);
duo_check(str_contains((string) $object['message'], 'object'), 'serialized PHP objects refuse with allowed_classes disabled');

$shared = 'shared';
$referenced = [&$shared, &$shared];
$reference = ninja_readiness_one_problem(
    $interpreter,
    'nf3_fields',
    ['type' => 'textbox'],
    ['options' => serialize($referenced)]
);
unset($referenced, $shared);
duo_check(str_contains((string) $reference['message'], 'reference'), 'serialized aliases and reference graphs refuse');

$deep = 'leaf';
for ($i = 0; $i < 66; $i++) {
    $deep = [$deep];
}
$depth = ninja_readiness_one_problem(
    $interpreter,
    'nf3_fields',
    ['type' => 'textbox'],
    ['options' => serialize($deep)]
);
unset($deep);
duo_check(
    str_contains((string) $depth['message'], 'malformed')
        || str_contains((string) $depth['message'], 'excessive depth'),
    'serialized depth beyond 64 refuses independently of PHP recursion behavior'
);

$manyNodes = array_fill(0, 200001, 'x');
$nodes = ninja_readiness_one_problem(
    $interpreter,
    'nf3_fields',
    ['type' => 'textbox'],
    ['options' => serialize($manyNodes)]
);
unset($manyNodes);
duo_check(str_contains((string) $nodes['message'], 'node count'), 'serialized node count beyond 200,000 refuses');

$oversize = 's:16777217:"' . str_repeat('x', 16777217) . '";';
$size = ninja_readiness_one_problem(
    $interpreter,
    'nf3_fields',
    ['type' => 'textbox'],
    ['options' => $oversize]
);
unset($oversize);
duo_check(str_contains((string) $size['message'], '16 MiB'), 'serialized value larger than 16 MiB refuses before allocation by unserialize');

$secret = serialize(['api_key' => 'sk_live_1234567890ABCDEFGHIJ']);
duo_check_same('stripe key', Secrets::hard_match_deep($secret), 'typed-table capture secret guard detects a credential inside serialized settings bytes');
duo_check_same(null, Secrets::hard_match_deep($longUtf8), 'large ordinary UTF-8 settings do not become false secret positives');
unset($longUtf8);

final class NinjaReadinessRuntimePlugin {
    /** @var array<string,object> */
    public array $fields;
    /** @var array<string,object> */
    public array $actions;

    public function __construct() {
        $this->fields = ['textbox' => new stdClass(), 'addon_upload' => new stdClass()];
        $this->actions = ['email' => new stdClass(), 'addon_action' => new stdClass()];
    }
}

$GLOBALS['ninja_readiness_runtime_plugin'] = new NinjaReadinessRuntimePlugin();
eval('function Ninja_Forms(): object { return $GLOBALS["ninja_readiness_runtime_plugin"]; }');

duo_check_same(
    [],
    ninja_readiness_diagnostics($interpreter, [
        ninja_readiness_entity('nf3_fields', ['type' => 'textbox']),
        ninja_readiness_entity('nf3_actions', ['type' => 'email'], [], '33333333-3333-4333-8333-333333333333'),
    ]),
    'built-in types registered by the installed exact runtime remain admitted'
);
$notRegistered = ninja_readiness_one_problem($interpreter, 'nf3_fields', ['type' => 'hcaptcha']);
duo_check(str_contains((string) $notRegistered['message'], 'not registered by the installed exact'), 'source-union type absent from the installed exact release refuses');
$addonRegistered = ninja_readiness_one_problem($interpreter, 'nf3_fields', ['type' => 'addon_upload']);
duo_check(str_contains((string) $addonRegistered['message'], 'outside this adapter'), 'runtime add-on registration cannot widen the digest-bound built-in allowlist');

$manifest = json_decode(
    (string) file_get_contents(__DIR__ . '/../../../../adapter-packages/ninja-forms/package/manifest.json'),
    true,
    32,
    JSON_THROW_ON_ERROR
);
duo_check_same(['min' => '3.4.34.2', 'max' => '4.0.0'], $manifest['version_range'] ?? null, 'version range is exact-minimum inclusive and 4.0-exclusive');
duo_check_same('ninja-forms', $manifest['interpreter'] ?? null, 'manifest binds the content interpreter into adapter identity');
duo_check_same(
    ['table:nf3_actions', 'table:nf3_fields'],
    array_keys($manifest['deletions'] ?? []),
    'only independently lock-safe child deletions are advertised'
);
duo_check(!isset($manifest['deletions']['table:nf3_forms']), 'unsupported parent form deletion stays absent and loud');
duo_check_same('runtime', $manifest['post_types']['nf_sub']['class'] ?? null, 'visitor submissions remain target-runtime sovereign');
duo_check_same('env', $manifest['tables']['nf3_upgrades']['class'] ?? null, 'derived cache table never enters canonical authored state');
duo_check_same(
    [
        'nf3_action_meta' => ['exception_fields'],
        'nf3_field_meta' => ['image_options', 'options', 'shipping_options'],
        'nf3_form_meta' => ['calculations', 'formContentData'],
    ],
    array_map(
        static fn(string $table): array => array_values(array_keys(array_filter(
            $manifest['tables'][$table]['keys'] ?? [],
            static fn(array $rule): bool => ($rule['plain_data'] ?? false) === true
        ))),
        ['nf3_action_meta' => 'nf3_action_meta', 'nf3_field_meta' => 'nf3_field_meta', 'nf3_form_meta' => 'nf3_form_meta']
    ),
    'every source-reviewed core serialized-array key binds the typed-table plain-data codec'
);
duo_check_same('2.2.0', $manifest['providers'][0]['version'] ?? null, 'manifest requires the cross-process fingerprint-bound provider identity');
duo_check_same(
    ['Ninja_Forms'],
    $manifest['providers'][0]['requires']['functions'] ?? null,
    'provider contract names the exact plugin function dependency'
);
duo_check_same(
    ['WP_CLI', 'WPN_Helper'],
    $manifest['providers'][0]['requires']['classes'] ?? null,
    'provider contract names the exact isolated command and native cache APIs'
);
duo_check_same(
    [
        'table:nf3_action_meta', 'table:nf3_actions', 'table:nf3_field_meta',
        'table:nf3_fields', 'table:nf3_form_meta', 'table:nf3_forms',
    ],
    $manifest['actions'][0]['triggers'] ?? null,
    'any authored graph mutation or child deletion triggers cache repair'
);
duo_check_same(
    ['nf3_upgrades', 'options'],
    array_map(
        static fn(array $effect): string => (string) ($effect['selector']['value'] ?? ''),
        $manifest['actions'][0]['effects'] ?? []
    ),
    'cache table plus legacy options are recovery-declared database effects'
);

duo_check_summary('Ninja Forms production readiness');
