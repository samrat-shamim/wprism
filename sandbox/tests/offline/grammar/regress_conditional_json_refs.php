<?php
declare(strict_types=1);

// A native redirect writer stores either a decimal page id or a URL at the
// same coordinate. An unconditional json_refs apply converted the URL to '0'.
$root = dirname(__DIR__, 4);
require_once __DIR__ . '/../../lib/check.php';
require_once $root . '/agent/src/Kernel/StructuredReferenceCodec.php';

use WPrism\StructuredReferenceCodec;

$uuid = '11111111-1111-4111-8111-111111111111';
$token = '{{post:' . $uuid . '}}';
$rules = [];
foreach (['login', 'logout'] as $event) {
    $rules[] = [
        'path' => '$.' . $event . '_target_value',
        'kind' => 'post',
        'cast' => 'string',
        'when' => ['key' => $event . '_target_type', 'equals' => 'page', 'otherwise' => ['custom']],
    ];
}
$native = [
    ['login_target_type' => 'page', 'login_target_value' => '4',
        'logout_target_type' => 'custom', 'logout_target_value' => 'https://source.test/signed-out/'],
    ['login_target_type' => 'custom', 'login_target_value' => 'https://source.test/dashboard/',
        'logout_target_type' => 'page', 'logout_target_value' => '4'],
];
$lookups = [];
$captureId = static function (int $id, string $kind) use (&$lookups, $token): ?string {
    $lookups[] = [$id, $kind];
    return $id === 4 && $kind === 'post' ? $token : null;
};
$warnings = [];
$warn = static function (string $warning) use (&$warnings): void { $warnings[] = $warning; };
$captured = StructuredReferenceCodec::capture($native, $rules, null, $captureId, $warn);
$applied = StructuredReferenceCodec::apply($captured, $rules, null, static fn(string $value): int => 804);
$expected = $native;
$expected[0]['login_target_value'] = $expected[1]['logout_target_value'] = '804';
wprism_check_same($expected, $applied, 'conditional references remap only page branches and preserve URL branches');
wprism_check_same([[4, 'post'], [4, 'post']], $lookups, 'ordinary values never consult the identity ledger');
wprism_check_same([], $warnings, 'a fully resolved conditional graph has no reference warnings');
wprism_check_same('4', $native[0]['login_target_value'], 'capture and apply preserve the native input');

require_once $root . '/agent/src/Kernel/ReferenceShapeGrammar.php';
require_once $root . '/agent/src/Review/StructuredReferenceScanner.php';
require_once __DIR__ . '/../../lib/agent_version.php';
require_once __DIR__ . '/../../lib/frozen_policy.php';
require_once $root . '/agent/src/Policy/Policy.php';
wprism_test_define_agent_versions();

use WPrism\ReferenceCondition;
use WPrism\ReferenceShapeGrammar;
use WPrism\StructuredReferenceScanner;
use WPrismTest\FrozenPolicy;

$optionRule = ['class' => 'authored', 'json_refs' => $rules];
$manifest = ['name' => 'conditional-fixture', 'spec_version' => 3,
    'engine_features' => [ReferenceCondition::FEATURE, 'spec-window/v1'],
    'option_autoload' => 'preserve', 'options' => ['fixture_routes' => $optionRule]];
$site = FrozenPolicy::site([$manifest], WPRISM_SPEC_VERSION);
$site['policy']['post_types'] = $site['policy']['taxonomies'] = [];
$policy = FrozenPolicy::policy([$manifest], $site);
wprism_check_same($rules, $policy->option_rule('fixture_routes')['json_refs'], 'the real policy loader retains the negotiated declarations');
foreach (['feature', 'spec', 'site'] as $fault) {
    $bad = $manifest;
    if ($fault === 'feature') $bad['engine_features'] = ['spec-window/v1'];
    if ($fault === 'spec') $bad['spec_version'] = 2;
    wprism_check_throws(static fn() => ReferenceShapeGrammar::validate_reference_shapes($bad, 'fixture', $fault !== 'site'),
        RuntimeException::class, "unnegotiated $fault condition refuses", ReferenceCondition::FEATURE);
}
foreach ([null, [], ['key' => 'type'],
    ['key' => 'login_target_type', 'equals' => 'page', 'otherwise' => ['page']],
    ['key' => 'login_target_type', 'equals' => 'page', 'otherwise' => ['custom', 'custom']],
    ['key' => 'login_target_type', 'equals' => 'page', 'otherwise' => [false]],
    ['key' => 'login_target_type', 'equals' => 'page', 'otherwise' => []],
    ['key' => 'login_target_value', 'equals' => 'page', 'otherwise' => ['custom']],
    ['key' => 'bad.key', 'equals' => 'page', 'otherwise' => ['custom']],
    ['key' => 'login_target_type', 'equals' => str_repeat('x', 129), 'otherwise' => ['custom']],
    ['key' => 'login_target_type', 'equals' => 'page', 'otherwise' => ['custom'], 'callback' => 'anything'],
] as $invalid) {
    $bad = $manifest;
    $bad['options']['fixture_routes']['json_refs'][0]['when'] = $invalid;
    wprism_check_throws(static fn() => ReferenceShapeGrammar::validate_reference_shapes($bad, 'fixture', true),
        RuntimeException::class, 'malformed or ambiguous condition refuses during declaration validation');
}
$bad = $manifest;
$bad['options']['fixture_routes']['json_refs'][] = ['path' => '$.login_target_type', 'kind' => 'post'];
wprism_check_throws(static fn() => ReferenceShapeGrammar::validate_reference_shapes($bad, 'fixture', true),
    RuntimeException::class, 'a discriminator cannot itself be rewritten', 'discriminator');
foreach ([null, false, 1, [], 'future-variant'] as $invalid) {
    $bad = $native;
    $bad[0]['login_target_type'] = $invalid;
    wprism_check_throws(static fn() => StructuredReferenceCodec::capture($bad, $rules, null, $captureId, $warn),
        RuntimeException::class, 'capture refuses an unreviewed native variant', 'discriminator');
    $bad = $captured;
    $bad[0]['login_target_type'] = $invalid;
    wprism_check_throws(static fn() => StructuredReferenceCodec::apply($bad, $rules, null, static fn(): int => 804),
        RuntimeException::class, 'apply refuses an unreviewed canonical variant', 'discriminator');
}
foreach ([-1, '-1', 1.5, true, [], '04', '4x', '4.0', 'https://source.test/', (string) PHP_INT_MAX . '0'] as $invalid) {
    $bad = $native;
    $bad[0]['login_target_value'] = $invalid;
    wprism_check_throws(static fn() => StructuredReferenceCodec::capture($bad, $rules, null, $captureId, $warn),
        RuntimeException::class, 'selected malformed native ids refuse before coercion', 'canonical positive integer');
}
foreach (['4', 4, [], '{{term:' . $uuid . '}}', '{{post:private-invalid-input}}'] as $invalid) {
    $bad = $captured;
    $bad[0]['login_target_value'] = $invalid;
    wprism_check_throws(static fn() => StructuredReferenceCodec::apply($bad, $rules, null, static fn(): int => 804),
        RuntimeException::class, 'selected malformed canonical refs refuse before lookup', 'declared keyspace');
}
$findings = StructuredReferenceScanner::scan($captured, 'fixture', 'option', $rules, null, static fn(): ?array => null);
wprism_check_same([], $findings, 'lint recognizes selected canonical references and untouched URL branches');
$bad = $captured;
$bad[0]['login_target_value'] = '4';
$findings = StructuredReferenceScanner::scan($bad, 'fixture', 'option', $rules, null, static fn(): ?array => null);
wprism_check_same(['unrewritten_registered_ref'], array_column($findings, 'class'), 'lint names an unrewritten selected reference');

$dynamicPolicy = static function (array $declaration, array $answer): WPrism\Policy {
    unset($declaration['options']);
    $declaration['interpreter'] = 'conditional-fixture';
    $loaded = FrozenPolicy::policy([$declaration], FrozenPolicy::site([$declaration], WPRISM_SPEC_VERSION));
    $interpreter = new class($answer) {
        public function __construct(private array $rule) {}
        public function post_meta_rule(string $key, array $all): ?array { return $key === 'routes' ? $this->rule : null; }
        public function term_meta_rule(string $key, array $all): ?array { return $this->post_meta_rule($key, $all); }
        public function user_meta_rule(string $key, array $all): ?array { return $this->post_meta_rule($key, $all); }
        public function option_rule(string $key, array $all): ?array { return $this->post_meta_rule($key, $all); }
    };
    (new ReflectionProperty(WPrism\Policy::class, 'interpreterInstances'))->setValue($loaded, ['conditional-fixture' => $interpreter]);
    return $loaded;
};
foreach (['meta_rule_for_post', 'meta_rule_for_term', 'meta_rule_for_user', 'meta_rule_for_option'] as $hook) {
    $dynamic = $dynamicPolicy($manifest, $optionRule);
    wprism_check_same($rules, $dynamic->$hook('routes', [])['json_refs'], "$hook retains exact owner-negotiated conditional refs");
    $unenrolled = $manifest;
    $unenrolled['engine_features'] = ['spec-window/v1'];
    $dynamic = $dynamicPolicy($unenrolled, $optionRule);
    wprism_check_throws(static fn() => $dynamic->$hook('routes', []), RuntimeException::class,
        "$hook cannot return a conditional rule without owner negotiation", ReferenceCondition::FEATURE);
}
$composed = $manifest;
$composed['engine_features'][] = WPrism\NativeValueValidation::FEATURE;
sort($composed['engine_features'], SORT_STRING);
$composedRule = $optionRule + [WPrism\NativeValueValidation::FIELD => WPrism\NativeValueValidation::KSES];
$dynamic = $dynamicPolicy($composed, $composedRule);
wprism_check_same($composedRule, $dynamic->meta_rule_for_post('routes', []),
    'runtime reference validation retains the complete negotiated feature context');
$missingOwner = $dynamicPolicy($manifest, $optionRule);
(new ReflectionProperty(WPrism\Policy::class, 'manifests'))->setValue($missingOwner, []);
wprism_check_throws(static fn() => $missingOwner->meta_rule_for_post('routes', []), RuntimeException::class,
    'an ownerless interpreter cannot introduce a conditional reference', 'exact v3 owner');

require_once __DIR__ . '/../../lib/wp_stubs.php';
require_once __DIR__ . '/../../lib/FakeWpdb.php';
require_once $root . '/agent/src/Capture/OptionsCapture.php';
require_once $root . '/agent/src/Capture/CaptureSafetyGates.php';
require_once $root . '/agent/src/Apply/ApplyFieldMaterializer.php';
require_once $root . '/agent/src/Apply/OptionsMaterializer.php';
require_once $root . '/agent/src/Repository/RepositoryCompiler.php';

use WPrism\ApplyFieldMaterializer;
use WPrism\CacheInvalidationTransaction;
use WPrism\Canon;
use WPrism\CaptureSafetyGates;
use WPrism\Db;
use WPrism\NativeDatabaseProfile;
use WPrism\OptionsCapture;
use WPrism\OptionsMaterializer;
use WPrism\OptionState;
use WPrism\RepositoryCompiler;
use WPrism\Tokens;
use WPrismTest\FakeWpdb;
use WPrismTest\WpStore;

$manifest['post_types'] = ['page' => ['class' => 'authored']];
$site['policy']['post_types'] = ['page'];
$policy = FrozenPolicy::policy([$manifest], $site);
$scratch = sys_get_temp_dir() . '/wprism-conditional-refs-' . bin2hex(random_bytes(8));
mkdir($scratch . '/state/posts/page', 0700, true);
mkdir($scratch . '/state/options', 0700, true);
$remove = static function (string $path) use (&$remove): void {
    if (!is_dir($path) || is_link($path)) { if (file_exists($path) || is_link($path)) unlink($path); return; }
    foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $entry) $remove($entry->getPathname());
    rmdir($path);
};
register_shutdown_function(static fn() => $remove($scratch));
$front = ['author' => 'user:admin', 'comment_status' => 'closed', 'date' => '2026-09-08 00:00:00',
    'date_gmt' => '2026-09-08 00:00:00', 'excerpt' => '', 'menu_order' => 0, 'meta' => (object) [],
    'modified' => '2026-09-08 00:00:00', 'modified_gmt' => '2026-09-08 00:00:00', 'parent' => null,
    'ping_status' => 'closed', 'slug' => 'destination', 'status' => 'publish', 'terms' => (object) [],
    'title' => 'Destination', 'type' => 'page', 'uuid' => $uuid];
Canon::write_file($scratch . '/site.wprism.json', Canon::encode($site));
Canon::write_file($scratch . '/state/posts/page/' . $uuid . '--destination.md', Canon::post_file($front, 'Destination'));
$database = static function (int $localId, mixed $value) use ($uuid): FakeWpdb {
    return FakeWpdb::install()->enableInformationSchema()
        ->seedTable('wp_options', [['option_id' => 1, 'option_name' => 'fixture_routes', 'option_value' => serialize($value), 'autoload' => 'yes']])
        ->setColumns('wp_options', ['option_id' => 'bigint unsigned', 'option_name' => 'varchar(191)', 'option_value' => 'longtext', 'autoload' => 'varchar(20)'])
        ->setAutoIncrement('wp_options', 2, 'option_id')->setUniqueKey('wp_options', ['option_name'])
        ->setIndexes('wp_options', [['Key_name' => 'option_name', 'Column_name' => 'option_name', 'Seq_in_index' => 1,
            'Sub_part' => null, 'Non_unique' => 0, 'Index_type' => 'BTREE']])->setTableEngine('wp_options', 'InnoDB')
        ->seedTable('wp_wprism_map', [['uuid' => $uuid, 'entity_type' => 'post:page', 'id_kind' => 'post', 'local_id' => $localId]])
        ->setColumns('wp_wprism_map', ['uuid' => 'varchar(36)', 'entity_type' => 'varchar(64)', 'id_kind' => 'varchar(64)', 'local_id' => 'bigint unsigned'])
        ->setUniqueKey('wp_wprism_map', ['uuid', 'id_kind'])->setUniqueKey('wp_wprism_map', ['id_kind', 'local_id'])
        ->setTableEngine('wp_wprism_map', 'InnoDB');
};
$captureOptions = static function (string $home) use ($policy, $scratch): array {
    WpStore::reset()->seedOptions(['home' => $home]);
    $tokens = new Tokens($home, $home . '/wp-content/uploads');
    $gates = new CaptureSafetyGates($scratch);
    $reader = new OptionsCapture($policy, $tokens,
        static function (string $section, string $key, mixed $value, array $rule) use ($gates): void {
            $gates->guardSecret($section, $key, $value, $rule);
            $gates->guardPersonalData($section, $key, $value, $rule);
        }, static function (): never { throw new LogicException('conditional refs do not use scalar option scope'); },
        static function (): never { throw new LogicException('conditional refs do not use scalar option existence'); });
    $result = $reader->capture(false);
    $gates->assertOptions($result['unclassified'], $result['unscoped_refs'], $result['unscoped_option_name_refs'], $tokens);
    return $result['document'];
};
$sourceDb = $database(4, $native);
$sourceBefore = $sourceDb->rows('wp_options');
$document = $captureOptions('https://source.test');
$portable = OptionState::values($document)['fixture_routes'];
wprism_check_same($token, $portable[0]['login_target_value'], 'real option capture tokenizes the selected page reference');
wprism_check_same('{{home}}/signed-out/', $portable[0]['logout_target_value'], 'ordinary text codec rebinds the non-reference URL branch');
wprism_check_same($sourceBefore, $sourceDb->rows('wp_options'), 'option capture leaves native serialized bytes unchanged');
$statePath = $scratch . '/state/options/core.json';
$compile = static function (array $value) use ($statePath, $scratch, $policy) {
    Canon::write_file($statePath, Canon::encode($value));
    return RepositoryCompiler::compile($scratch, $policy);
};
$artifact = $compile($document);
wprism_check_same($portable, OptionState::values($artifact->tree()['options/core']['data'])['fixture_routes'],
    'complete repository compilation retains the typed-reference and URL composition');
foreach (['unreviewed', 'bare-id', 'wrong-kind', 'secret', 'pii'] as $fault) {
    $bad = $portable;
    if ($fault === 'unreviewed') $bad[0]['login_target_type'] = 'future';
    if ($fault === 'bare-id') $bad[0]['login_target_value'] = '4';
    if ($fault === 'wrong-kind') $bad[0]['login_target_value'] = '{{term:' . $uuid . '}}';
    if ($fault === 'secret') $bad[0]['credential'] = 'sk_live_' . str_repeat('a', 32);
    if ($fault === 'pii') $bad[0]['email'] = 'person@example.test';
    $badDocument = OptionState::document(['fixture_routes' => OptionState::present($bad, 'yes')]);
    wprism_check_throws(static fn() => $compile($badDocument), RuntimeException::class, "compiler refuses $fault without target contact");
}
$artifact = $compile($document);
$targetDb = $database(804, []);
WpStore::reset()->seedOptions(['home' => 'https://target.test']);
$targetTokens = new Tokens('https://target.test', 'https://target.test/wp-content/uploads');
$fields = new ApplyFieldMaterializer($policy, $targetTokens);
$materializer = new OptionsMaterializer($policy, $targetTokens, $fields);
$write = static function (array $desired) use ($materializer, $fields): void {
    Db::start_repeatable_read('conditional fixture transaction', new NativeDatabaseProfile(['wp_options', 'wp_wprism_map'], ['wp_options']));
    $fields->begin_authored_transaction();
    $materializer->begin_authored_transaction();
    CacheInvalidationTransaction::begin();
    try {
        $warnings = [];
        $materializer->apply_options($desired, false, $warnings);
        Db::commit('conditional fixture commit');
        $materializer->commit_authored_transaction();
        CacheInvalidationTransaction::finish();
        wprism_check_same([], $warnings, 'checked option apply has no warnings');
    } catch (Throwable $failure) {
        Db::rollback('conditional fixture rollback');
        throw $failure;
    } finally {
        $materializer->end_authored_transaction();
        $fields->end_authored_transaction();
        CacheInvalidationTransaction::end();
    }
};
$write($artifact->tree()['options/core']['data']);
$targetExpected = $expected;
$targetExpected[0]['logout_target_value'] = 'https://target.test/signed-out/';
$targetExpected[1]['login_target_value'] = 'https://target.test/dashboard/';
wprism_check_same($targetExpected, unserialize($targetDb->rows('wp_options')[0]['option_value'], ['allowed_classes' => false]),
    'checked SQL applies divergent ids, URL rebinding and valid native serialization together');
$beforeRepeat = $targetDb->rows('wp_options');
$write($document);
wprism_check_same($beforeRepeat, $targetDb->rows('wp_options'), 'repeated SQL apply preserves exact target option bytes');
wprism_check_same($document, $captureOptions('https://target.test'), 'real target recapture returns the complete source canonical document');
foreach (['unreviewed', 'bare-id', 'container', 'wrong-kind'] as $fault) {
    $bad = $portable;
    if ($fault === 'unreviewed') $bad[0]['login_target_type'] = 'future';
    if ($fault === 'bare-id') $bad[0]['login_target_value'] = '4';
    if ($fault === 'container') $bad[0]['login_target_value'] = ['hidden' => $token];
    if ($fault === 'wrong-kind') $bad[0]['login_target_value'] = '{{term:' . $uuid . '}}';
    $badDocument = OptionState::document(['fixture_routes' => OptionState::present($bad, 'yes')]);
    wprism_check_throws(static fn() => $write($badDocument), RuntimeException::class, "SQL materialization refuses $fault at its own boundary");
    wprism_check_same($beforeRepeat, $targetDb->rows('wp_options'), 'failed SQL materialization preserves the complete native row');
}

wprism_check_summary('conditional json references');
