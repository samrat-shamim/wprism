<?php
declare(strict_types=1);

/**
 * Adversarial offline boundary for ACF's schema-driven adapter. This suite
 * exercises the exact free-plugin 6.8.7 field/location roster, hostile PHP
 * serialization, local-schema sovereignty, user-attached values, conditional
 * reference shapes, and length-safe URL rebinding through the product seams.
 */

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}
if (!defined('WPRISM_SPEC_VERSION')) {
    define('WPRISM_SPEC_VERSION', 2);
}

if (!function_exists('untrailingslashit')) {
    function untrailingslashit(string $value): string {
        return rtrim($value, '/\\');
    }
}
if (!function_exists('wp_upload_dir')) {
    function wp_upload_dir($time = null, bool $createDir = true): array {
        return ['baseurl' => 'https://unused.example/uploads', 'basedir' => sys_get_temp_dir()];
    }
}
if (!function_exists('acf_is_local_field')) {
    function acf_is_local_field(string $key): bool {
        return in_array($key, $GLOBALS['acf_readiness_local_fields'] ?? [], true);
    }
}
if (!function_exists('acf_is_local_field_group')) {
    function acf_is_local_field_group(string $key): bool {
        return in_array($key, $GLOBALS['acf_readiness_local_groups'] ?? [], true);
    }
}

$repoRoot = dirname(__DIR__, 4);
require_once $repoRoot . '/sandbox/tests/lib/check.php';
require_once $repoRoot . '/agent/src/Kernel/Canon.php';
require_once $repoRoot . '/agent/src/Kernel/PlainData.php';
require_once $repoRoot . '/agent/src/Kernel/Secrets.php';
require_once $repoRoot . '/agent/src/Policy/Policy.php';
require_once $repoRoot . '/agent/src/Grammar/Tokens.php';
require_once $repoRoot . '/agent/src/Capture/EntityMetaCapture.php';
require_once $repoRoot . '/agent/src/Capture/MediaCapture.php';
require_once $repoRoot . '/agent/src/Capture/PostCapture.php';
require_once $repoRoot . '/agent/src/Repository/RepositoryPortableShapeValidator.php';

use WPrism\Canon;
use WPrism\EntityMetaCapture;
use WPrism\Interpreters\Acf;
use WPrism\MediaCapture;
use WPrism\PlainData;
use WPrism\Policy;
use WPrism\PostCapture;
use WPrism\RepositoryPortableShapeValidator;
use WPrism\ReferenceRules;
use WPrism\Tokens;

/** @return array<string,mixed> */
function acf_readiness_field(string $key, string $type, array $extra = [], ?string $body = null): array {
    return [
        'type' => 'post',
        'path' => "state/posts/acf-field/$key.md",
        'data' => ['type' => 'acf-field', 'slug' => $key, 'meta' => []],
        'body' => $body ?? serialize(array_merge(['type' => $type], $extra)),
    ];
}

/** @return array<string,mixed> */
function acf_readiness_group(string $key, array $locations, ?string $body = null): array {
    return [
        'type' => 'post',
        'path' => "state/posts/acf-field-group/$key.md",
        'data' => ['type' => 'acf-field-group', 'slug' => $key, 'meta' => []],
        'body' => $body ?? serialize(['location' => $locations]),
    ];
}

/** @return list<array<string,mixed>> */
function acf_readiness_diagnostics(Acf $acf, array $tree): array {
    $acf->prime_repository($tree);
    return $acf->repository_diagnostics($tree);
}

/** @return list<array<string,mixed>> */
function acf_readiness_code(array $diagnostics, string $code): array {
    return array_values(array_filter(
        $diagnostics,
        static fn(array $diagnostic): bool => ($diagnostic['code'] ?? null) === $code
    ));
}

final class AcfReadinessWpdb {
    public string $postmeta = 'wp_postmeta';
    public string $posts = 'wp_posts';
    public string $users = 'wp_users';

    public function prepare(string $query, ...$args): string {
        return $query;
    }

    public function get_results(string $query, $mode = null): array {
        return [];
    }

    public function get_var($query) {
        return null;
    }
}

final class AcfReadinessWakeupProbe {
    public static bool $woke = false;

    public function __wakeup(): void {
        self::$woke = true;
    }
}

$manifest = Canon::decode(Canon::read_file(dirname(__DIR__, 2) . '/package/manifest.json'));
wprism_check_same('serialized', $manifest['post_types']['acf-field']['body'] ?? null, 'ACF field schemas use the strict serialized body codec');
wprism_check_same('serialized', $manifest['post_types']['acf-field-group']['body'] ?? null, 'ACF field-group schemas use the strict serialized body codec');

$plain = ['url' => 'https://source.example/path', 'nested' => ['uploads' => 'https://source.example/content/files/a.png']];
wprism_check_same($plain, PlainData::decode_serialized(serialize($plain), 'ordinary schema'), 'strict serialized decoding preserves ordinary arrays and scalar types');
wprism_check_throws(
    static fn() => PlainData::decode_serialized(serialize($plain) . 'TRAILING', 'trailing schema'),
    RuntimeException::class,
    'a valid serialized prefix plus trailing payload refuses',
    'trailing or noncanonical'
);
wprism_check_throws(
    static fn() => PlainData::decode_serialized('ordinary text', 'unserialized schema'),
    RuntimeException::class,
    'an unserialized field body refuses instead of being treated as a schema',
    'must be canonical PHP-serialized plain data'
);

AcfReadinessWakeupProbe::$woke = false;
$objectPayload = serialize(new AcfReadinessWakeupProbe());
wprism_check_throws(
    static fn() => PlainData::decode_serialized($objectPayload, 'object schema'),
    RuntimeException::class,
    'serialized objects refuse without entering plugin-controlled wakeup code',
    'PHP object'
);
wprism_check(!AcfReadinessWakeupProbe::$woke, 'serialized object rejection never invoked __wakeup');

$shared = ['value'];
$referencePayload = ['first' => &$shared, 'second' => &$shared];
wprism_check_throws(
    static fn() => PlainData::decode_serialized(serialize($referencePayload), 'reference schema'),
    RuntimeException::class,
    'serialized PHP references refuse as non-portable graph structure',
    'reference or recursive array'
);
$deep = 'leaf';
for ($i = 0; $i < PlainData::MAX_DEPTH + 2; $i++) {
    $deep = [$deep];
}
wprism_check_throws(
    static fn() => PlainData::decode_serialized(serialize($deep), 'deep schema'),
    RuntimeException::class,
    'over-depth serialized schemas refuse before recursive work becomes unbounded',
    'nested too deeply'
);

$sourceTokens = new Tokens('https://source.example', 'https://source.example/content/files');
$targetTokens = new Tokens('https://target.example/subdir', 'https://cdn.target.example/media');
$longUtf8 = str_repeat('界🙂', 3000) . "\n---\n";
$sourceValue = [
    'instructions' => $longUtf8 . 'https://source.example/help',
    'wrapper' => ['asset' => 'https://source.example/content/files/icon.png?x=1&y=two'],
];
$canonicalValue = $sourceTokens->plain_data_capture($sourceValue);
$canonicalWire = serialize($canonicalValue);
wprism_check(str_contains($canonicalWire, '{{home}}/help'), 'nested schema URLs tokenize without corrupting PHP string lengths');
wprism_check(str_contains($canonicalWire, '{{uploads}}/icon.png?x=1&y=two'), 'nested uploads URLs use the distinct uploads token');
wprism_check_same($canonicalValue, PlainData::decode_serialized($canonicalWire, 'canonical tokenized schema'), 'tokenized long UTF-8 and delimiter text remains canonical serialized data');
$targetValue = $targetTokens->plain_data_apply($canonicalValue);
wprism_check_same($longUtf8 . 'https://target.example/subdir/help', $targetValue['instructions'], 'target rebinding preserves long UTF-8 and delimiter bytes exactly');
wprism_check_same('https://cdn.target.example/media/icon.png?x=1&y=two', $targetValue['wrapper']['asset'], 'target uploads rebinding is length-safe and environment-specific');
wprism_check_throws(
    static fn() => $targetTokens->user_token_to_id('user:'),
    RuntimeException::class,
    'an empty user login token never falls back to the default administrator',
    'non-empty-login'
);
ReferenceRules::value_rule(['class' => 'authored', 'plain_data' => true], 'fixture.plain');
wprism_check(true, 'plain_data is a validated value-rule codec, not an interpreter-only untyped flag');
wprism_check_throws(
    static fn() => ReferenceRules::value_rule(
        ['class' => 'authored', 'plain_data' => true, 'ref' => 'post'],
        'fixture.ambiguous'
    ),
    RuntimeException::class,
    'plain_data cannot silently compete with a scalar reference codec',
    'ownership is ambiguous'
);
wprism_check_throws(
    static fn() => ReferenceRules::value_rule(
        ['class' => 'authored', 'plain_data' => true, 'json_encoded' => true],
        'fixture.wrong-wire'
    ),
    RuntimeException::class,
    'plain_data cannot silently accept an incompatible wire codec',
    'preserves native PHP plain data'
);

$policy = Policy::load(
    null,
    ['acf'],
    adapterLibrary: \WPrism\AdapterLibrary::fromSourcePackage($repoRoot, 'acf')
);
// Use the production digest/provenance boundary for the first class load;
// subsequent direct instances exercise behavior without inventing a preload.
$acf = $policy->interpreters()['acf'];
$fieldTree = [
    acf_readiness_field('field_image', 'image'),
    acf_readiness_field('field_file', 'file'),
    acf_readiness_field('field_post_one', 'post_object'),
    acf_readiness_field('field_post_many', 'post_object', ['multiple' => 1]),
    acf_readiness_field('field_relationship', 'relationship'),
    acf_readiness_field('field_tax_one', 'taxonomy', ['field_type' => 'radio']),
    acf_readiness_field('field_tax_many', 'taxonomy', ['field_type' => 'checkbox']),
    acf_readiness_field('field_user_one', 'user'),
    acf_readiness_field('field_user_many', 'user', ['multiple' => 1]),
    acf_readiness_field('field_page_one', 'page_link'),
    acf_readiness_field('field_page_many', 'page_link', ['multiple' => 1]),
    acf_readiness_field('field_icon', 'icon_picker'),
    acf_readiness_field('field_link', 'link'),
];
$acf->prime_repository($fieldTree);
$rule = static fn(string $name, string $field, $value) => $acf->post_meta_rule(
    $name,
    ['_' . $name => $field, $name => $value]
);
wprism_check_same(['class' => 'authored', 'ref' => 'post', 'cast' => 'string'], $rule('image', 'field_image', '11'), 'image is a scalar post reference');
wprism_check_same(['class' => 'authored', 'ref' => 'post', 'cast' => 'string'], $rule('post_one', 'field_post_one', '12'), 'single post_object is a scalar post reference');
wprism_check_same(['class' => 'authored', 'ref' => 'post[]', 'cast' => 'string'], $rule('post_many', 'field_post_many', serialize(['12', '13'])), 'multiple post_object is a string-cast post list');
wprism_check_same(['class' => 'authored', 'ref' => 'post[]', 'cast' => 'string'], $rule('relationship', 'field_relationship', serialize(['12', '13'])), 'relationship is a string-cast post list');
wprism_check_same(['class' => 'authored', 'ref' => 'term'], $rule('tax_one', 'field_tax_one', '3'), 'single taxonomy is a scalar term reference');
wprism_check_same(['class' => 'authored', 'ref' => 'term[]'], $rule('tax_many', 'field_tax_many', serialize([3, 4])), 'multi taxonomy preserves integer list storage');
wprism_check_same(['class' => 'authored', 'ref' => 'user', 'cast' => 'string'], $rule('user_one', 'field_user_one', '1'), 'single user field binds by exact login');
wprism_check_same(['class' => 'authored', 'ref' => 'user[]', 'cast' => 'string'], $rule('user_many', 'field_user_many', serialize(['1', '2'])), 'multi user field binds an exact-login list and restores string storage');
wprism_check_same(['class' => 'authored', 'ref' => 'post', 'cast' => 'string'], $acf->user_meta_rule('avatar', ['_avatar' => 'field_image', 'avatar' => '11']), 'user-attached field values enter the authored user-meta sidecar path');
wprism_check_same(['class' => 'authored', 'ref' => 'post', 'cast' => 'string'], $rule('page_one', 'field_page_one', '12'), 'single page_link is no longer leaked as a raw local id');
wprism_check_same(['class' => 'authored', 'ref' => 'post[]', 'cast' => 'string'], $rule('page_many', 'field_page_many', serialize(['12', '13'])), 'multiple page_link is a portable post list');
wprism_check_same(
    ['class' => 'authored', 'json_refs' => [['path' => '$.value', 'kind' => 'post']]],
    $rule('icon', 'field_icon', serialize(['type' => 'media_library', 'value' => 11])),
    'media-library icon_picker tokenizes its conditional attachment id'
);
wprism_check_same(
    ['class' => 'authored', 'plain_data' => true],
    $rule('icon', 'field_icon', serialize(['type' => 'dashicons', 'value' => 'admin-site'])),
    'non-media icon_picker remains plain structured authored data'
);
wprism_check_same(['class' => 'authored', 'plain_data' => true], $rule('link', 'field_link', serialize(['url' => 'https://source.example'])), 'Link field nested URLs use the recursive plain-data codec');

$plainTypes = [
    'text', 'textarea', 'number', 'range', 'email', 'url', 'wysiwyg', 'oembed', 'select', 'checkbox',
    'radio', 'button_group', 'true_false', 'link', 'google_map', 'date_picker', 'date_time_picker',
    'time_picker', 'color_picker', 'message', 'accordion', 'tab', 'group',
];
foreach ($plainTypes as $index => $type) {
    $field = acf_readiness_field('field_plain_' . $index, $type);
    $plainAcf = new Acf($policy);
    $plainAcf->prime_repository([$field]);
    wprism_check_same(
        ['class' => 'authored', 'plain_data' => true],
        $plainAcf->post_meta_rule('plain', ['_plain' => 'field_plain_' . $index, 'plain' => 'value']),
        "built-in ACF $type uses the bounded plain-data codec"
    );
}

$unsupportedTree = [
    acf_readiness_field('field_password', 'password'),
    acf_readiness_field('field_gallery', 'gallery'),
    acf_readiness_field('field_custom', 'third_party_relation'),
    acf_readiness_field('field_archive_link', 'page_link', ['allow_archives' => 1]),
];
$unsupportedDiagnostics = acf_readiness_diagnostics(new Acf($policy), $unsupportedTree);
wprism_check_same(4, count(acf_readiness_code($unsupportedDiagnostics, 'acf_field_type_unsupported')), 'password, PRO-only, custom, and mixed archive/id field shapes all refuse explicitly');

$supportedParams = [
    'attachment', 'current_user', 'current_user_role', 'nav_menu_item', 'page', 'page_parent',
    'page_template', 'page_type', 'post', 'post_category', 'post_format', 'post_status',
    'post_taxonomy', 'post_template', 'post_type', 'taxonomy', 'user_form', 'user_role',
];
$supportedLocation = [[array_map(
    static fn(string $param): array => ['param' => $param, 'operator' => '==', 'value' => 'fixture'],
    $supportedParams
)]];
// ACF's matrix is OR groups containing AND rules; remove one wrapper added
// above so every exact built-in supported parameter lives in one AND group.
$supportedLocation = [$supportedLocation[0][0]];
wprism_check_same([], acf_readiness_diagnostics(new Acf($policy), [acf_readiness_group('group_supported', $supportedLocation)]), 'every supported built-in location parameter passes the repository contract');

$unsupportedGroups = [];
foreach (['comment', 'widget', 'nav_menu', 'custom_owner'] as $index => $param) {
    $unsupportedGroups[] = acf_readiness_group(
        'group_bad_' . $index,
        [[['param' => $param, 'operator' => '==', 'value' => 'fixture']]]
    );
}
$unsupportedGroups[] = acf_readiness_group('group_malformed', [['not-a-rule']]);
$locationDiagnostics = acf_readiness_diagnostics(new Acf($policy), $unsupportedGroups);
wprism_check_same(5, count(acf_readiness_code($locationDiagnostics, 'acf_field_location_unsupported')), 'comment, widget, menu-term, custom, and malformed location owners refuse before publication');

$GLOBALS['acf_readiness_local_fields'] = ['field_local'];
$GLOBALS['acf_readiness_local_groups'] = ['group_local'];
$localDiagnostics = acf_readiness_diagnostics(new Acf($policy), [
    acf_readiness_field('field_local', 'text'),
    acf_readiness_group('group_local', [[['param' => 'post_type', 'operator' => '==', 'value' => 'post']]]),
]);
wprism_check_same(2, count(acf_readiness_code($localDiagnostics, 'acf_local_schema_collision')), 'local PHP/JSON field and group overrides both refuse a repository DB schema collision');
$GLOBALS['acf_readiness_local_fields'] = [];
$GLOBALS['acf_readiness_local_groups'] = [];

$hostileTree = [
    acf_readiness_field('field_trailing', 'text', [], serialize(['type' => 'text']) . 'suffix'),
    acf_readiness_field('field_object', 'text', [], $objectPayload),
    acf_readiness_field('field_reference', 'text', [], serialize($referencePayload)),
    acf_readiness_field('field_deep', 'text', [], serialize($deep)),
];
$hostileDiagnostics = acf_readiness_diagnostics(new Acf($policy), $hostileTree);
wprism_check_same(4, count(acf_readiness_code($hostileDiagnostics, 'adapter_schema_content_mismatch')), 'trailing, object, reference, and over-depth field schemas all become batched blocking diagnostics');

$GLOBALS['wpdb'] = new AcfReadinessWpdb();
$capturePolicy = Policy::load(
    null,
    ['acf'],
    adapterLibrary: \WPrism\AdapterLibrary::fromSourcePackage($repoRoot, 'acf')
);
$captureTokens = new Tokens('https://source.example', 'https://source.example/content/files');
$metaCapture = new EntityMetaCapture(
    $capturePolicy,
    $captureTokens,
    static function (): void {},
    static function (): void {},
    static function (): void {}
);
$postCapture = new PostCapture($capturePolicy, $captureTokens, $metaCapture, new MediaCapture());
$post = (object) [
    'ID' => 41,
    'post_type' => 'acf-field',
    'post_password' => '',
    'post_parent' => 0,
    'post_author' => 0,
    'post_name' => 'field_capture_roundtrip',
    'post_title' => 'Capture Roundtrip',
    'post_status' => 'publish',
    'post_date' => '2026-08-23 00:00:00',
    'post_date_gmt' => '2026-08-23 00:00:00',
    'post_modified' => '2026-08-23 00:00:00',
    'post_modified_gmt' => '2026-08-23 00:00:00',
    'menu_order' => 0,
    'comment_status' => 'closed',
    'ping_status' => 'closed',
    'post_excerpt' => 'capture_roundtrip',
    'post_mime_type' => '',
    'post_content' => serialize(['type' => 'text', 'instructions' => $longUtf8 . 'https://source.example/help']),
];
$captured = $postCapture->capture($post, '11111111-1111-4111-8111-111111111111', []);
[, $capturedBody] = Canon::parse_post_file($captured['entity']['content']);
$capturedSchema = PlainData::decode_serialized($capturedBody, 'captured ACF schema');
wprism_check_same($longUtf8 . '{{home}}/help', $capturedSchema['instructions'], 'the real PostCapture product seam emits a strict length-safe tokenized ACF body');

$post->post_content = serialize(['type' => 'text', 'instructions' => 'sk_live_1234567890ABCDEFGHIJ']);
wprism_check_throws(
    static fn() => $postCapture->capture($post, '11111111-1111-4111-8111-111111111111', []),
    RuntimeException::class,
    'secret-shaped schema leaves refuse capture rather than producing a warning-only commit',
    'refusing to capture serialized authored configuration'
);
$post->post_content = serialize([
    'type' => 'text',
    'integration' => ['Authorization' => 'GeneratedValue-2026-Blocked'],
]);
wprism_check_throws(
    static fn() => $postCapture->capture($post, '11111111-1111-4111-8111-111111111111', []),
    RuntimeException::class,
    'decoded serialized body keys receive heuristic credential clearance before capture',
    'credential-shaped value'
);
$post->post_content = serialize([
    'type' => 'text',
    'customerProfile' => ['firstName' => 'Private Customer'],
]);
wprism_check_throws(
    static fn() => $postCapture->capture($post, '11111111-1111-4111-8111-111111111111', []),
    RuntimeException::class,
    'decoded serialized body keys receive personal-data clearance before capture',
    'personal name'
);
$post->post_content = serialize(['type' => 'text']) . 'suffix';
wprism_check_throws(
    static fn() => $postCapture->capture($post, '11111111-1111-4111-8111-111111111111', []),
    RuntimeException::class,
    'the real PostCapture product seam refuses trailing serialized payloads',
    'trailing or noncanonical'
);

$compilerDiagnostics = [];
$compilerPolicy = Policy::load(
    null,
    ['acf'],
    adapterLibrary: \WPrism\AdapterLibrary::fromSourcePackage($repoRoot, 'acf')
);
$compilerTree = [
    'field' => acf_readiness_field(
        'field_compiler_secret',
        'text',
        ['instructions' => 'sk_live_1234567890ABCDEFGHIJ']
    ),
];
$compilerPolicy->prime_interpreters_from_repository($compilerTree);
(new RepositoryPortableShapeValidator(
    $compilerPolicy,
    static function (string $code, string $path, string $locator, string $message, ?string $relatedPath) use (&$compilerDiagnostics): void {
        $compilerDiagnostics[] = compact('code', 'path', 'locator', 'message', 'relatedPath');
    }
))->validate($compilerTree);
wprism_check_same(1, count(acf_readiness_code($compilerDiagnostics, 'repository_serialized_body_secret_not_allowed')), 'repository compilation rejects a hand-edited secret-shaped serialized schema');

$piiCompilerDiagnostics = [];
$piiCompilerTree = [
    'field' => acf_readiness_field(
        'field_compiler_pii',
        'text',
        ['customerProfile' => ['firstName' => 'Private Customer']]
    ),
];
$compilerPolicy->prime_interpreters_from_repository($piiCompilerTree);
(new RepositoryPortableShapeValidator(
    $compilerPolicy,
    static function (string $code, string $path, string $locator, string $message, ?string $relatedPath) use (&$piiCompilerDiagnostics): void {
        $piiCompilerDiagnostics[] = compact('code', 'path', 'locator', 'message', 'relatedPath');
    }
))->validate($piiCompilerTree);
wprism_check_same(
    1,
    count(acf_readiness_code($piiCompilerDiagnostics, 'repository_serialized_body_pii_not_allowed')),
    'repository compilation rejects hand-edited personal data hidden inside a serialized schema'
);

$malformedCompilerDiagnostics = [];
$malformedTree = ['field' => acf_readiness_field('field_compiler_bad', 'text', [], serialize(['type' => 'text']) . 'suffix')];
$malformedPolicy = Policy::load(
    null,
    ['acf'],
    adapterLibrary: \WPrism\AdapterLibrary::fromSourcePackage($repoRoot, 'acf')
);
$malformedPolicy->prime_interpreters_from_repository($malformedTree);
(new RepositoryPortableShapeValidator(
    $malformedPolicy,
    static function (string $code, string $path, string $locator, string $message, ?string $relatedPath) use (&$malformedCompilerDiagnostics): void {
        $malformedCompilerDiagnostics[] = compact('code', 'path', 'locator', 'message', 'relatedPath');
    }
))->validate($malformedTree);
wprism_check_same(1, count(acf_readiness_code($malformedCompilerDiagnostics, 'schema_content_mismatch')), 'repository compilation rejects a malformed serialized body before apply');

wprism_check_summary('ACF production readiness');
