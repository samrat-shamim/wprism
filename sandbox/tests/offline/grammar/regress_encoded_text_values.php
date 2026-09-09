<?php
declare(strict_types=1);

// A native editor can URI-encode authored CSS before persistence. Treating
// that storage as ordinary text hides URLs and privacy findings. Exercise
// the negotiated scalar codec through capture, pure compilation and SQL.
$root = dirname(__DIR__, 4);
if (($argv[1] ?? '') === '--compile-without-wordpress') {
    require_once __DIR__ . '/../../lib/agent_version.php';
    require_once __DIR__ . '/../../lib/frozen_policy.php';
    require_once $root . '/agent/src/Repository/RepositoryCompiler.php';
    wprism_test_define_agent_versions();
    $input = json_decode(file_get_contents($argv[2] . '/probe.json'), true, flags: JSON_THROW_ON_ERROR);
    $policy = WPrismTest\FrozenPolicy::policy([$input['manifest']], $input['site']);
    $artifact = WPrism\RepositoryCompiler::compile($argv[2], $policy);
    echo json_encode(['entities' => count($artifact->tree()), 'wordpress' => function_exists('parse_blocks'),
        'database' => isset($GLOBALS['wpdb'])], JSON_THROW_ON_ERROR), "\n";
    exit(0);
}
require_once __DIR__ . '/../../lib/check.php';
require_once __DIR__ . '/../../lib/wp_stubs.php';
require_once __DIR__ . '/../../lib/FakeWpdb.php';
require_once __DIR__ . '/../../lib/agent_version.php';
require_once __DIR__ . '/../../lib/frozen_policy.php';
require_once __DIR__ . '/../../support/wp-block-parser-stub.php';
require_once __DIR__ . '/../../support/wp-shortcode-stub.php';
require_once $root . '/agent/src/Kernel/EncodedText.php';
require_once $root . '/agent/src/Capture/PostCapture.php';
require_once $root . '/agent/src/Capture/TermCapture.php';
require_once $root . '/agent/src/Capture/UserMetaCapture.php';
require_once $root . '/agent/src/Capture/OptionsCapture.php';
require_once $root . '/agent/src/Capture/CaptureSafetyGates.php';
require_once $root . '/agent/src/Apply/PostMaterializer.php';
require_once $root . '/agent/src/Apply/UserMetaMaterializer.php';
require_once $root . '/agent/src/Apply/OptionsMaterializer.php';
require_once $root . '/agent/src/Repository/RepositoryCompiler.php';
wprism_test_define_agent_versions();

use WPrism\AdapterContractGrammar;
use WPrism\ApplyFieldMaterializer;
use WPrism\AttachmentMaterializer;
use WPrism\BlockValueGrammar;
use WPrism\Blocks;
use WPrism\CacheInvalidationTransaction;
use WPrism\Canon;
use WPrism\CaptureSafetyGates;
use WPrism\Db;
use WPrism\EncodedText;
use WPrism\EntityMetaCapture;
use WPrism\MediaCapture;
use WPrism\NativeDatabaseProfile;
use WPrism\OptionsCapture;
use WPrism\OptionsMaterializer;
use WPrism\OptionState;
use WPrism\Policy;
use WPrism\PostCapture;
use WPrism\PostMaterializer;
use WPrism\ReferenceShapeGrammar;
use WPrism\RelationshipMaterializer;
use WPrism\RepositoryCompiler;
use WPrism\SidebarState;
use WPrism\TermCapture;
use WPrism\Tokens;
use WPrism\UserMetaCapture;
use WPrism\UserMetaMaterializer;
use WPrism\UserMetaState;
use WPrismTest\FakeWpdb;
use WPrismTest\FrozenPolicy;
use WPrismTest\WpStore;

$rule = ['class' => 'authored', 'text_encoding' => ['codec' => 'uri-component',
    'escape' => ['text' => '--', 'wire' => '_u002d__u002d_']]];
$uriRule = ['class' => 'authored', 'text_encoding' => ['codec' => 'uri-component']];
$runtime = ['class' => 'runtime'];
$manifest = ['name' => 'encoded-text-fixture', 'spec_version' => 3,
    'engine_features' => [BlockValueGrammar::FEATURE, EncodedText::FEATURE, 'spec-window/v1'],
    'option_autoload' => 'preserve', 'post_types' => ['page' => ['class' => 'authored']],
    'taxonomies' => ['category' => ['class' => 'authored']],
    'post_meta' => ['fixture_text' => $rule, 'fixture_runtime' => $runtime],
    'term_meta' => ['fixture_text' => $rule, 'fixture_runtime' => $runtime],
    'user_meta' => ['fixture_text' => $rule + ['missing_user' => 'block'], 'fixture_runtime' => $runtime],
    'options' => ['fixture_text' => $rule, 'fixture_runtime' => $runtime,
        'fixture_settings' => ['class' => 'env', 'required' => false,
            'sub_keys' => ['style' => $rule, 'runtime' => $runtime]]],
    'option_patterns' => [$rule + ['match' => '^fixture_pattern$']],
    'option_namespaces' => [['match' => '^fixture_']],
    'block_values' => ['fixture/style' => ['css' => $rule]],
    'widgets' => ['block' => ['settings' => ['content' => ['class' => 'authored', 'codec' => 'blocks']]]]];
$site = FrozenPolicy::site([$manifest], WPRISM_SPEC_VERSION);
$site['policy']['post_types'] = ['page'];
$site['policy']['taxonomies'] = ['category'];
$policy = FrozenPolicy::policy([$manifest], $site);
wprism_check_same(EncodedText::declaration_grammar(), AdapterContractGrammar::implemented_feature_rows()[EncodedText::FEATURE]['value_constraint'],
    'the public feature schema exposes the runtime-owned codec grammar');
foreach (['options', 'post_meta', 'term_meta', 'user_meta'] as $section) {
    foreach (['feature', 'spec', 'site'] as $fault) {
        $bad = ['spec_version' => 3, 'engine_features' => [EncodedText::FEATURE], $section => ['fixture_text' => $rule]];
        if ($fault === 'feature') unset($bad['engine_features']);
        if ($fault === 'spec') $bad['spec_version'] = 2;
        wprism_check_throws(static fn() => ReferenceShapeGrammar::validate_reference_shapes($bad, 'fixture', $fault !== 'site'),
            RuntimeException::class, "$section refuses unnegotiated $fault text encoding", EncodedText::FEATURE);
    }
}
foreach (['option_name_refs', 'dynamic_options', 'dynamic-subkey', 'attached-meta', 'meta-subkey', 'nested-subkey'] as $scope) {
    $bad = ['spec_version' => 3, 'engine_features' => [EncodedText::FEATURE]];
    if ($scope === 'option_name_refs') $bad[$scope] = [$rule + ['match' => '^fixture_(\d+)$']];
    if ($scope === 'dynamic_options') $bad[$scope] = ['fixture' => $rule];
    if ($scope === 'dynamic-subkey') $bad['dynamic_options'] = ['fixture' => ['sub_keys' => ['css' => $rule]]];
    if ($scope === 'attached-meta') $bad['tables'] = ['fixture' => ['class' => 'authored_snapshot_meta', 'keys' => ['css' => $rule]]];
    if ($scope === 'meta-subkey') $bad['post_meta'] = ['fixture' => ['class' => 'authored', 'sub_keys' => ['css' => $rule]]];
    if ($scope === 'nested-subkey') $bad['options'] = ['fixture' => ['class' => 'authored', 'sub_keys' => [
        'nested' => ['class' => 'authored', 'sub_keys' => ['css' => $rule]]]]];
    wprism_check_throws(static fn() => ReferenceShapeGrammar::validate_reference_shapes($bad, 'fixture', true),
        RuntimeException::class, "unimplemented $scope transport is not admitted");
}
foreach (['feature', 'block-feature', 'spec'] as $fault) {
    $bad = $manifest;
    if ($fault === 'feature') $bad['engine_features'] = [BlockValueGrammar::FEATURE, 'spec-window/v1'];
    if ($fault === 'block-feature') $bad['engine_features'] = [EncodedText::FEATURE, 'spec-window/v1'];
    if ($fault === 'spec') $bad['spec_version'] = 2;
    wprism_check_throws(static fn() => FrozenPolicy::policy([$bad], FrozenPolicy::site([$bad], WPRISM_SPEC_VERSION)),
        RuntimeException::class, "block loader refuses missing $fault negotiation");
}
foreach (['ref', 'cast', 'json_refs', 'key_refs', 'json_encoded', 'plain_data', 'php_containers', 'record_fields',
    'sub_keys', 'repeated_rows', 'order_preserving', 'native_value_validation'] as $field) {
    wprism_check_throws(static fn() => EncodedText::assert_rule($rule + [$field => null], 'fixture', true),
        RuntimeException::class, "codec exclusivity checks $field presence even when null", "cannot combine with $field");
}
$badProfiles = [null, false, [], ['codec' => 'base64'], ['codec' => 'uri-component', 'callback' => 'decode'],
    ['codec' => 'uri-component', 'escape' => null], ['codec' => 'uri-component', 'escape' => ['text' => '--']],
    ['codec' => 'uri-component', 'escape' => ['text' => '', 'wire' => 'marker']],
    ['codec' => 'uri-component', 'escape' => ['text' => '--', 'wire' => '-']],
    ['codec' => 'uri-component', 'escape' => ['text' => 'x', 'wire' => 'xx']],
    ['codec' => 'uri-component', 'escape' => ['text' => "\0", 'wire' => 'marker']],
    ['codec' => 'uri-component', 'escape' => ['text' => '--', 'wire' => '%encoded%']],
    ['codec' => 'uri-component', 'escape' => ['text' => '--', 'wire' => str_repeat('x', 129)]],
    ['codec' => 'uri-component', 'escape' => ['text' => '--', 'wire' => 'marker', 'other' => true]]];
foreach ($badProfiles as $profile) {
    wprism_check_throws(static fn() => EncodedText::assert_rule(['class' => 'authored', 'text_encoding' => $profile], 'fixture', true),
        RuntimeException::class, 'the codec and literal escape are bounded closed declarations');
}
wprism_check_throws(static fn() => EncodedText::assert_rule(array_replace($rule, $runtime), 'fixture', true),
    RuntimeException::class, 'non-authored values cannot declare a text codec');

// Exact outputs from the 3.8.1 editor's pure gutenberg/utils/encode-decode/index.js;
// the PHP helper uses urlencode (space -> +), a different persistence contract.
$vectors = [
    ['', ''],
    ['selector { --hero: url("https://source.example.test/wp-content/uploads/hero.png"); }',
        'selector%20%7B%20_u002d__u002d_hero%3A%20url(%22https%3A%2F%2Fsource.example.test%2Fwp-content%2Fuploads%2Fhero.png%22)%3B%20%7D'],
    ['selector { content: "বাংলা + 100% !()* café"; }',
        'selector%20%7B%20content%3A%20%22%E0%A6%AC%E0%A6%BE%E0%A6%82%E0%A6%B2%E0%A6%BE%20%2B%20100%25%20!()*%20caf%C3%A9%22%3B%20%7D'],
    ["selector::after { content: 'line one\\nline two'; }", "selector%3A%3Aafter%20%7B%20content%3A%20'line%20one%5Cnline%20two'%3B%20%7D"],
];
foreach ($vectors as [$plain, $wire]) {
    wprism_check_same($wire, EncodedText::encode($plain, $rule, 'fixture'), 'the shared writer matches independent native JavaScript bytes');
    wprism_check_same($plain, EncodedText::decode($wire, $rule, 'fixture'), 'the shared reader reverses native JavaScript bytes exactly');
}
wprism_check_same("a\t\r\nb + % !'()*~", EncodedText::decode('a%09%0D%0Ab%20%2B%20%25%20!' . "'()*~", $uriRule, 'fixture'),
    'bare URI encoding preserves whitespace, plus, percent and the JavaScript unescaped set');
wprism_check_same('%20+_u002d__u002d_', EncodedText::decode_if_declared('%20+_u002d__u002d_', ['class' => 'authored'], 'fixture'),
    'undeclared legacy strings retain their exact bytes');
foreach (['a+b', '%', '%2', '%GG', '%2f', '%41', '%21', '--', 'raw space', '%00', '%7F', '%FF',
    false, 4, [], str_repeat('x', EncodedText::MAX_WIRE_BYTES + 1)] as $bad) {
    wprism_check_throws(static fn() => EncodedText::decode($bad, $rule, 'fixture'), RuntimeException::class,
        'native noncanonical spelling, malformed text and excess bounds refuse');
}
foreach ([null, false, 4, [], "\xff", "private\0value", str_repeat('x', EncodedText::MAX_BYTES + 1),
    '_u002d__u002d_', '_u002d_--'] as $bad) {
    wprism_check_throws(static fn() => EncodedText::assert_canonical($bad, $rule, 'fixture'), RuntimeException::class,
        'canonical scalar, UTF-8, byte and literal-boundary constraints are shared with compilation');
}
$expanding = ['class' => 'authored', 'text_encoding' => ['codec' => 'uri-component', 'escape' => ['text' => '-', 'wire' => str_repeat('x', 128)]]];
foreach ([str_repeat('-', 65537), str_repeat('-', 60000) . str_repeat(' ', 300000)] as $large) {
    wprism_check_throws(static fn() => EncodedText::assert_canonical($large, $expanding, 'fixture'), RuntimeException::class,
        'immutable canonical validation refuses both literal and subsequent URI expansion beyond the native bound');
}
foreach (['options' => 'meta_rule_for_option', 'post_meta' => 'meta_rule_for_post',
    'term_meta' => 'meta_rule_for_term', 'user_meta' => 'meta_rule_for_user'] as $section => $hook) {
    foreach (['authored', 'runtime', 'derived', 'env'] as $classification) {
        $override = $site;
        $override['policy'][$section]['fixture_text'] = ['class' => $classification];
        if ($classification === 'authored') $override['policy'][$section]['fixture_text']['autoload'] = 'preserve';
        if ($classification === 'env') $override['policy'][$section]['fixture_text']['required'] = false;
        $lookup = static function () use ($manifest, $override, $hook) { return FrozenPolicy::policy([$manifest], $override)->$hook('fixture_text', []); };
        if ($classification === 'authored') {
            wprism_check_throws($lookup, RuntimeException::class, "$section site policy cannot strip a manifest codec", 'cannot replace');
        } else {
            wprism_check_same($classification, $lookup()['class'], "$section can explicitly exclude the whole encoded value");
        }
    }
    foreach (['exact', 'pattern'] as $scope) {
        if ($scope === 'pattern' && $section === 'user_meta') continue;
        $declared = $manifest;
        $other = ['name' => 'other-text-fixture', 'spec_version' => 3, 'option_autoload' => 'preserve',
            $section => ['fixture_text' => ['class' => 'authored']]];
        if ($scope === 'pattern') {
            unset($declared[$section]['fixture_text']);
            $patternSection = ['options' => 'option_patterns', 'post_meta' => 'post_meta_patterns', 'term_meta' => 'meta_patterns'][$section];
            $declared[$patternSection][] = $rule + ['match' => '^fixture_text$'];
        }
        foreach ([[$declared, $other], [$other, $declared]] as $pinOrder) {
            $lookup = static function () use ($pinOrder, $hook) {
                return FrozenPolicy::policy($pinOrder, FrozenPolicy::site($pinOrder, WPRISM_SPEC_VERSION))->$hook('fixture_text', []);
            };
            wprism_check_throws($lookup, RuntimeException::class, "$section $scope codec cannot be hidden by either pin order",
                $section === 'options' && $scope === 'exact' ? 'contradictory rules' : 'conflicting classification owners');
        }
    }
}
$dynamicPolicy = static function (array $answer, bool $staticOwner) use ($manifest): Policy {
    $declared = $manifest;
    $declared['interpreter'] = 'encoded-text-fixture';
    if (!$staticOwner) foreach (['options', 'post_meta', 'term_meta', 'user_meta'] as $section) unset($declared[$section]['fixture_text']);
    $loaded = FrozenPolicy::policy([$declared], FrozenPolicy::site([$declared], WPRISM_SPEC_VERSION));
    $interpreter = new class($answer) {
        public function __construct(private array $rule) {}
        public function post_meta_rule(string $key, array $all): ?array { return $key === 'fixture_text' ? $this->rule : null; }
        public function term_meta_rule(string $key, array $all): ?array { return $this->post_meta_rule($key, $all); }
        public function user_meta_rule(string $key, array $all): ?array { return $this->post_meta_rule($key, $all); }
        public function option_rule(string $key, array $all): ?array { return $this->post_meta_rule($key, $all); }
    };
    (new ReflectionProperty(Policy::class, 'interpreterInstances'))->setValue($loaded, ['encoded-text-fixture' => $interpreter]);
    return $loaded;
};
foreach ([$rule, ['class' => 'authored'], $runtime] as $answer) {
    foreach (['meta_rule_for_option', 'meta_rule_for_post', 'meta_rule_for_term', 'meta_rule_for_user'] as $hook) {
        $dynamic = $dynamicPolicy($answer, true);
        wprism_check_throws(static fn() => $dynamic->$hook('fixture_text', []), RuntimeException::class,
            "$hook cannot replace static text encoding through an interpreter", 'static text');
        $dynamic = $dynamicPolicy($rule, false);
        wprism_check_throws(static fn() => $dynamic->$hook('fixture_text', []), RuntimeException::class,
            "$hook cannot introduce executable text encoding", 'static text');
    }
}

$scratch = sys_get_temp_dir() . '/wprism-encoded-text-' . bin2hex(random_bytes(8));
foreach (['posts/page', 'terms/category', 'options', 'user-meta'] as $directory) mkdir($scratch . '/state/' . $directory, 0700, true);
$remove = static function (string $path) use (&$remove): void {
    if (!is_dir($path) || is_link($path)) { if (file_exists($path) || is_link($path)) unlink($path); return; }
    foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $entry) $remove($entry->getPathname());
    rmdir($path);
};
register_shutdown_function(static fn() => $remove($scratch));
Canon::write_file($scratch . '/site.wprism.json', Canon::encode($site));
$uuid4 = '11111111-1111-4111-8111-111111111111';
$uuid9 = '99999999-9999-4999-8999-999999999999';
$termUuid = '77777777-7777-4777-8777-777777777777';
$sourceHome = 'https://source.test';
$targetHome = 'https://a-longer-target.example.test';
$style = static fn(string $home, int $id): string => 'selector { --hero: url("' . $home . '/wp-content/uploads/hero.png"); background: url("'
    . $home . '/?p=' . $id . '"); content: "বাংলা + 100% !()* café"; }';
$block = static fn(mixed $css): string => '<!-- wp:fixture/style ' . json_encode(['css' => $css], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . ' /-->';
$sourcePlain = $style($sourceHome, 4);
$sourceWire = EncodedText::encode($sourcePlain, $rule, 'fixture');
$targetWire = EncodedText::encode($style($targetHome, 804), $rule, 'fixture');
$canonicalPlain = $style('{{home}}', 4);
$canonicalPlain = str_replace(['{{home}}/wp-content/uploads', '?p=4'], ['{{uploads}}', '?p={{post:' . $uuid4 . '}}'], $canonicalPlain);
$postRow = static function (int $id, int $author, string $slug, string $body): array {
    return ['ID' => $id, 'post_author' => $author, 'post_date' => '2026-09-09 00:00:00', 'post_date_gmt' => '2026-09-09 00:00:00',
        'post_content' => $body, 'post_title' => $slug, 'post_excerpt' => '', 'post_status' => 'publish',
        'comment_status' => 'closed', 'ping_status' => 'closed', 'post_password' => '', 'post_name' => $slug,
        'post_modified' => '2026-09-09 00:00:00', 'post_modified_gmt' => '2026-09-09 00:00:00',
        'post_parent' => 0, 'menu_order' => 0, 'post_type' => 'page', 'post_mime_type' => '', 'guid' => 'local-' . $id];
};
$termRow = static fn(int $offset): object => (object) ['term_id' => 7 + $offset, 'taxonomy' => 'category',
    'parent' => 0, 'name' => 'Category', 'slug' => 'category', 'description' => 'Description'];
$index = static fn(string $name, string $column, bool $unique = false): array => ['Key_name' => $name, 'Column_name' => $column,
    'Seq_in_index' => 1, 'Sub_part' => null, 'Non_unique' => $unique ? 0 : 1, 'Index_type' => 'BTREE'];
$database = static function (int $offset, string $wire, string $local) use ($uuid4, $uuid9, $termUuid, $postRow, $block, $index): FakeWpdb {
    $db = FakeWpdb::install()->enableInformationSchema()->enableJoinedCaptureSql()
        ->seedTable('wp_posts', [$postRow(4 + $offset, 1 + $offset, 'first', $block($wire)),
            $postRow(9 + $offset, 1 + $offset, 'second', '<p>Second page</p>')])
        ->seedTable('wp_users', [['ID' => 1 + $offset, 'user_login' => 'admin']])
        ->setColumns('wp_posts', ['ID' => 'bigint unsigned', 'post_content' => 'longtext', 'post_type' => 'varchar(20)'])
        ->setColumns('wp_users', ['ID' => 'bigint unsigned', 'user_login' => 'varchar(60)'])
        ->setIndexes('wp_users', [$index('user_login_key', 'user_login')]);
    foreach (['wp_postmeta' => ['post_id', 'meta_id', 4], 'wp_termmeta' => ['term_id', 'meta_id', 7],
        'wp_usermeta' => ['user_id', 'umeta_id', 1]] as $table => [$owner, $identity, $id]) {
        $db->seedTable($table, [[$identity => 1, $owner => $id + $offset, 'meta_key' => 'fixture_text', 'meta_value' => $wire],
            [$identity => 2, $owner => $id + $offset, 'meta_key' => 'fixture_runtime', 'meta_value' => $local]])
            ->setColumns($table, [$identity => 'bigint unsigned', $owner => 'bigint unsigned', 'meta_key' => 'varchar(255)', 'meta_value' => 'longtext'])
            ->setIndexes($table, [$index($owner, $owner)])->setAutoIncrement($table, 10, $identity)->setTableEngine($table, 'InnoDB');
    }
    $db->seedTable('wp_options', [
        ['option_id' => 1, 'option_name' => 'fixture_text', 'option_value' => $wire, 'autoload' => 'auto'],
        ['option_id' => 2, 'option_name' => 'fixture_pattern', 'option_value' => $wire, 'autoload' => 'off'],
        ['option_id' => 3, 'option_name' => 'fixture_settings', 'option_value' => serialize(['style' => $wire, 'runtime' => $local]), 'autoload' => 'yes'],
        ['option_id' => 4, 'option_name' => 'fixture_runtime', 'option_value' => $local, 'autoload' => 'no']])
        ->setColumns('wp_options', ['option_id' => 'bigint unsigned', 'option_name' => 'varchar(191)', 'option_value' => 'longtext', 'autoload' => 'varchar(20)'])
        ->setAutoIncrement('wp_options', 10, 'option_id')->setUniqueKey('wp_options', ['option_name'])
        ->setIndexes('wp_options', [$index('option_name', 'option_name', true)])
        ->seedTable('wp_wprism_map', [
            ['uuid' => $uuid4, 'id_kind' => 'post', 'entity_type' => 'post:page', 'local_id' => 4 + $offset],
            ['uuid' => $uuid9, 'id_kind' => 'post', 'entity_type' => 'post:page', 'local_id' => 9 + $offset],
            ['uuid' => $termUuid, 'id_kind' => 'term', 'entity_type' => 'term:category', 'local_id' => 7 + $offset]])
        ->setColumns('wp_wprism_map', ['uuid' => 'varchar(36)', 'id_kind' => 'varchar(64)', 'entity_type' => 'varchar(64)', 'local_id' => 'bigint unsigned'])
        ->setUniqueKey('wp_wprism_map', ['uuid', 'id_kind'])->setUniqueKey('wp_wprism_map', ['id_kind', 'local_id']);
    foreach (['wp_posts', 'wp_users', 'wp_options', 'wp_wprism_map'] as $table) $db->setTableEngine($table, 'InnoDB');
    return $db;
};
$gates = new CaptureSafetyGates($scratch);
$guard = static function (string $section, string $key, mixed $value, array $rule) use ($gates): void {
    $gates->guardSecret($section, $key, $value, $rule);
    $gates->guardPersonalData($section, $key, $value, $rule);
};
$nothing = static function (): void {};
$capture = static function (string $home, int $offset) use ($policy, $uuid4, $uuid9, $termUuid, $termRow, $guard, $nothing, $gates): array {
    global $wpdb;
    WpStore::reset()->seedOptions(['home' => $home]);
    $tokens = new Tokens($home, $home . '/wp-content/uploads');
    $tokens->policy = $policy;
    $meta = new EntityMetaCapture($policy, $tokens, $guard, $nothing,
        static function (string $key): never { throw new LogicException('unclassified fixture metadata: ' . $key); });
    $reader = new PostCapture($policy, $tokens, $meta, new MediaCapture());
    $entities = [];
    foreach ([$uuid4, $uuid9] as $i => $uuid) $entities[] = $reader->capture((object) $wpdb->rows('wp_posts')[$i], $uuid, [])['entity'];
    $entities[] = (new TermCapture($policy, $tokens, $meta))->capture($termRow($offset), $termUuid, []);
    array_push($entities, ...(new UserMetaCapture($policy, $tokens, $guard, $nothing, $nothing))->capture([]));
    $options = (new OptionsCapture($policy, $tokens, $guard,
        static function (): never { throw new LogicException('no scalar reference declaration'); },
        static function (): never { throw new LogicException('no scalar reference existence premise'); }))->capture(false);
    $gates->assertOptions($options['unclassified'], $options['unscoped_refs'], $options['unscoped_option_name_refs'], $tokens);
    $gates->assertCanonicalContent($entities, $policy);
    $gates->assertContentReferences($tokens);
    $entities[] = ['uuid' => 'options/core', 'type' => 'options', 'path' => 'options/core.json', 'content' => Canon::encode($options['document'])];
    wprism_check_same([], $tokens->warnings, 'complete encoded-text capture has no warnings');
    return $entities;
};
$tables = ['wp_posts', 'wp_postmeta', 'wp_termmeta', 'wp_users', 'wp_usermeta', 'wp_options', 'wp_wprism_map'];
$rows = static function (FakeWpdb $db) use ($tables): array { return array_combine($tables, array_map($db->rows(...), $tables)); };
$publish = static function (array $entities) use ($scratch): void {
    foreach ($entities as $entity) Canon::write_file($scratch . '/state/' . $entity['path'], $entity['content']);
};
$sourceDb = $database(0, $sourceWire, 'source-local');
$sourceBefore = $rows($sourceDb);
$entities = $capture($sourceHome, 0);
wprism_check_same($sourceBefore, $rows($sourceDb), 'complete native capture is read-only across every source table');
$publish($entities);
$beforeCompile = $sourceDb->queries();
$artifact = RepositoryCompiler::compile($scratch, $policy);
$tree = $artifact->tree();
wprism_check_same(5, count($tree), 'pure compilation admits the complete post, term, user-meta and options graph');
wprism_check_same($canonicalPlain, $tree[$uuid4]['data']['meta']['fixture_text'], 'post metadata exposes decoded text to URL and query-reference capture');
wprism_check_same($canonicalPlain, $tree[$termUuid]['data']['meta']['fixture_text'], 'term metadata shares the decoded text transport');
wprism_check_same($canonicalPlain, $tree[UserMetaState::key('admin')]['data']['meta']['fixture_text'], 'login-keyed user metadata shares the decoded text transport');
$optionValues = OptionState::values($tree['options/core']['data']);
foreach (['fixture_text', 'fixture_pattern'] as $key) wprism_check_same($canonicalPlain, $optionValues[$key], "$key uses ordinary URL and query-reference tokens");
wprism_check_same($canonicalPlain, $optionValues['fixture_settings']['style'], 'static option subkeys share the codec and retain mixed ownership');
wprism_check_same($canonicalPlain, parse_blocks($tree[$uuid4]['body'])[0]['attrs']['css'], 'block attributes enter canonical state as decoded portable text');
wprism_check_same($beforeCompile, $sourceDb->queries(), 'immutable compilation makes no database query');
Canon::write_file($scratch . '/probe.json', Canon::encode(['manifest' => $manifest, 'site' => $site]));
$child = proc_open([PHP_BINARY, __FILE__, '--compile-without-wordpress', $scratch],
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
if (!is_resource($child)) throw new RuntimeException('cannot start the pure compiler fixture');
fclose($pipes[0]);
$childOut = stream_get_contents($pipes[1]);
$childError = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$childStatus = proc_close($child);
wprism_check_same(0, $childStatus, 'complete isolated compilation succeeds');
wprism_check_same('', $childError, 'isolated compilation emits no diagnostics');
wprism_check_same(['entities' => 5, 'wordpress' => false, 'database' => false], json_decode($childOut, true),
    'encoded text compilation needs neither WordPress nor a database object');

// Native byte framing must not conceal plaintext from publication clearance.
foreach (['secret' => 'sk_live_' . str_repeat('a', 32), 'pii' => 'person@example.test', 'malformed' => null] as $kind => $value) {
    $badWire = $kind === 'malformed' ? 'invalid%GG' : EncodedText::encode('selector { content: "' . $value . '"; }', $rule, 'fixture');
    foreach (['post', 'term', 'user', 'option', 'subkey', 'block'] as $surface) {
        foreach ($sourceBefore as $table => $data) $sourceDb->seedTable($table, $data);
        $table = ['post' => 'wp_postmeta', 'term' => 'wp_termmeta', 'user' => 'wp_usermeta',
            'option' => 'wp_options', 'subkey' => 'wp_options', 'block' => 'wp_posts'][$surface];
        $data = $sourceDb->rows($table);
        if ($surface === 'block') $data[0]['post_content'] = $block($badWire);
        elseif ($surface === 'subkey') $data[2]['option_value'] = serialize(['style' => $badWire, 'runtime' => 'source-local']);
        else $data[0][$surface === 'option' ? 'option_value' : 'meta_value'] = $badWire;
        $sourceDb->seedTable($table, $data);
        $badRows = $rows($sourceDb);
        wprism_check_throws(static fn() => $capture($sourceHome, 0), RuntimeException::class, "$surface capture refuses decoded $kind");
        wprism_check_same($badRows, $rows($sourceDb), "$surface rejected capture leaves every table unchanged");
    }
}
foreach ($sourceBefore as $table => $data) $sourceDb->seedTable($table, $data);
$poison = static function (string $surface, mixed $value) use ($entities, $scratch, $block): void {
    $entity = $entities[match ($surface) { 'post', 'block' => 0, 'term' => 2, 'user' => 3, default => 4 }];
    if (in_array($surface, ['post', 'block'], true)) {
        [$front, $body] = Canon::parse_post_file($entity['content']);
        if ($surface === 'post') $front['meta']['fixture_text'] = $value;
        else $body = $block($value);
        $front['meta'] = (object) $front['meta'];
        $front['terms'] = (object) $front['terms'];
        $content = Canon::post_file($front, $body);
    } else {
        $document = Canon::decode($entity['content']);
        if (in_array($surface, ['term', 'user'], true)) $document['meta']['fixture_text'] = $value;
        elseif ($surface === 'subkey') $document['records']['fixture_settings']['value']['style'] = $value;
        else $document['records']['fixture_text']['value'] = $value;
        $content = Canon::encode($document);
    }
    Canon::write_file($scratch . '/state/' . $entity['path'], $content);
};
foreach (['post', 'term', 'user', 'option', 'subkey', 'block'] as $surface) {
    foreach (['secret' => 'sk_live_' . str_repeat('b', 32), 'pii' => 'person@example.test', 'shape' => [],
        'control' => "private\x01text", 'collision' => '_u002d_--'] as $kind => $value) {
        $publish($entities);
        if (in_array($kind, ['secret', 'pii'], true)) $value = 'selector { content: "' . $value . '"; }';
        $poison($surface, $value);
        $before = $sourceDb->queries();
        wprism_check_throws(static fn() => RepositoryCompiler::compile($scratch, $policy), RuntimeException::class,
            "immutable compiler independently refuses $surface $kind");
        wprism_check_same($before, $sourceDb->queries(), 'invalid canonical input is rejected without target contact');
    }
}
$publish($entities);

$targetDb = $database(800, EncodedText::encode('selector { color: gray; }', $rule, 'fixture'), 'target-local');
WpStore::reset()->seedOptions(['home' => $targetHome]);
$targetTokens = new Tokens($targetHome, $targetHome . '/wp-content/uploads');
$targetTokens->policy = $policy;
$fields = new ApplyFieldMaterializer($policy, $targetTokens);
$posts = new PostMaterializer($policy, $targetTokens, $fields, new RelationshipMaterializer($policy, $fields),
    new AttachmentMaterializer($policy, $fields, $artifact, $scratch));
$options = new OptionsMaterializer($policy, $targetTokens, $fields);
$users = new UserMetaMaterializer($policy, $targetTokens, $fields);
$write = static function (array $desired) use ($tables, $fields, $posts, $options, $users): void {
    Db::start_repeatable_read('encoded text fixture', new NativeDatabaseProfile($tables,
        ['wp_posts', 'wp_postmeta', 'wp_termmeta', 'wp_usermeta', 'wp_options']));
    $fields->begin_authored_transaction();
    $options->begin_authored_transaction();
    CacheInvalidationTransaction::begin();
    try {
        $warnings = [];
        foreach ($desired as $entity) {
            if ($entity['type'] === 'post') $posts->finalize_post($entity['data'], $entity['body'], 801, $warnings, []);
            elseif ($entity['type'] === 'term') $fields->reconcile_authored_term_meta(807, $entity['data']['meta']);
            elseif ($entity['type'] === 'user-meta') $users->finalize_user_meta($entity['data']);
            elseif ($entity['type'] === 'options') $options->apply_options($entity['data'], false, $warnings);
            else throw new LogicException('unhandled fixture entity');
        }
        Db::commit('encoded text fixture');
        $options->commit_authored_transaction();
        CacheInvalidationTransaction::finish();
        wprism_check_same([], $warnings, 'complete encoded text SQL transaction has no warnings');
    } catch (Throwable $failure) {
        $options->rollback_authored_transaction();
        Db::rollback('encoded text fixture');
        throw $failure;
    } finally {
        $options->end_authored_transaction();
        $fields->end_authored_transaction();
        CacheInvalidationTransaction::end();
    }
};
$targetBefore = $rows($targetDb);
$sawEarlierWrite = false;
$postUpdates = 0;
$targetDb->onQuery(static function (string $sql, string $method, FakeWpdb $db) use (&$sawEarlierWrite, &$postUpdates, $targetWire): ?string {
    if (str_starts_with($sql, 'UPDATE `wp_posts` ') && ++$postUpdates === 2) {
        $sawEarlierWrite = str_contains($db->rows('wp_posts')[0]['post_content'], $targetWire);
        return 'injected late encoded text write failure';
    }
    return null;
});
wprism_check_throws(static fn() => $write($tree), RuntimeException::class, 'late checked SQL failure is loud', 'database mutation failed');
wprism_check($sawEarlierWrite, 'the injected failure occurs after earlier decoded text has been rebound and natively encoded');
wprism_check_same($targetBefore, $rows($targetDb), 'late failure rolls back every table and preserves the complete identity map');
$targetDb->onQuery(null);
$write($tree);
wprism_check_same($block($targetWire), $targetDb->rows('wp_posts')[0]['post_content'], 'checked SQL stores editor-compatible encoded CSS with target URLs and IDs');
foreach (['wp_postmeta', 'wp_termmeta', 'wp_usermeta'] as $table) {
    wprism_check_same($targetWire, $targetDb->rows($table)[0]['meta_value'], "$table stores the exact native target encoding");
    wprism_check_same('target-local', $targetDb->rows($table)[1]['meta_value'], "$table preserves unrelated target runtime state");
}
$nativeOptions = array_column($targetDb->rows('wp_options'), 'option_value', 'option_name');
foreach (['fixture_text', 'fixture_pattern'] as $key) wprism_check_same($targetWire, $nativeOptions[$key], "$key stores the exact native target encoding");
wprism_check_same(serialize(['style' => $targetWire, 'runtime' => 'target-local']), $nativeOptions['fixture_settings'],
    'mixed option merge preserves target runtime siblings and recomputes native serialization lengths');
wprism_check_same('target-local', $nativeOptions['fixture_runtime'], 'whole runtime option remains target-local');
$after = $rows($targetDb);
$write($tree);
wprism_check_same($after, $rows($targetDb), 'repeated Apply preserves every target row exactly');
wprism_check_same($entities, $capture($targetHome, 800), 'full target recapture equals every canonical source byte');

foreach (['post', 'term', 'user', 'option', 'subkey'] as $surface) {
    foreach (['malformed', 'omitted', 'duplicate'] as $case) {
        if (in_array($surface, ['option', 'subkey'], true) && $case !== 'malformed') continue;
        foreach ($after as $table => $data) $targetDb->seedTable($table, $data);
        $table = ['post' => 'wp_postmeta', 'term' => 'wp_termmeta', 'user' => 'wp_usermeta',
            'option' => 'wp_options', 'subkey' => 'wp_options'][$surface];
        $data = $targetDb->rows($table);
        if ($surface === 'subkey') $data[2]['option_value'] = serialize(['style' => 'invalid%GG', 'runtime' => 'target-local']);
        elseif ($case === 'duplicate') {
            $duplicate = $data[0];
            $duplicate[$surface === 'user' ? 'umeta_id' : 'meta_id'] = 3;
            $duplicate['meta_value'] = 'invalid%GG';
            $data[] = $duplicate;
        } else $data[0][$surface === 'option' ? 'option_value' : 'meta_value'] = 'invalid%GG';
        $targetDb->seedTable($table, $data);
        $badBefore = $rows($targetDb);
        $desired = $tree;
        if ($case === 'omitted') {
            $owner = ['post' => $uuid4, 'term' => $termUuid, 'user' => UserMetaState::key('admin')][$surface];
            $desired[$owner]['data']['meta'] = [];
        }
        wprism_check_throws(static fn() => $write($desired), RuntimeException::class,
            "$surface rejects $case encoded native preimages before replacement or removal", 'text encoding');
        wprism_check_same($badBefore, $rows($targetDb), 'rejected native preimages preserve the complete target');
    }
}
foreach ($after as $table => $data) $targetDb->seedTable($table, $data);
foreach (['post', 'term', 'user', 'option', 'subkey', 'block'] as $surface) {
    $desired = $tree;
    if ($surface === 'block') $desired[$uuid4]['body'] = $block('_u002d_--');
    elseif ($surface === 'option') $desired['options/core']['data']['records']['fixture_text']['value'] = '_u002d_--';
    elseif ($surface === 'subkey') $desired['options/core']['data']['records']['fixture_settings']['value']['style'] = '_u002d_--';
    else $desired[['post' => $uuid4, 'term' => $termUuid, 'user' => UserMetaState::key('admin')][$surface]]['data']['meta']['fixture_text'] = '_u002d_--';
    wprism_check_throws(static fn() => $write($desired), RuntimeException::class,
        "$surface SQL boundary independently rejects unrepresentable canonical text", 'literal escape boundaries');
    wprism_check_same($after, $rows($targetDb), 'invalid materialization preserves every target row');
}
$collidingTokens = new Tokens('https://target.test/_u002d__u002d_', 'https://target.test/uploads');
wprism_check_throws(static fn() => Blocks::apply_rewrite($tree[$uuid4]['body'], $policy, $collidingTokens), RuntimeException::class,
    'target URL rebinding cannot introduce a native escape collision', 'collides');

// Block framing clearance applies to ordinary and undeclared blocks too.
// Native quote escaping is independent of this feature's URI persistence.
$publish($entities);
$private = 'selector { content: "sk_live_' . str_repeat('c', 32) . '"; }';
$unknownBody = '<!-- wp:unknown/style ' . serialize_block_attributes(['nested' => ['css' => $private]]) . ' /-->';
wprism_check(str_contains($unknownBody, '\u0022sk_live_'), 'fixture contains WordPress native quote escaping before the secret');
$badEntity = $entities[0];
[$front] = Canon::parse_post_file($badEntity['content']);
$front['meta'] = (object) $front['meta'];
$front['terms'] = (object) $front['terms'];
$badEntity['content'] = Canon::post_file($front, $unknownBody);
wprism_check_throws(static fn() => $gates->assertCanonicalContent([$badEntity], $policy), RuntimeException::class,
    'native escaped quotes in undeclared block attributes cannot bypass capture clearance', 'secret');
Canon::write_file($scratch . '/state/' . $badEntity['path'], $badEntity['content']);
wprism_check_throws(static fn() => RepositoryCompiler::compile($scratch, $policy), RuntimeException::class,
    'immutable compilation independently opens undeclared block framing for clearance');
$publish($entities);

// The complete native Qi fixtures exposed mobile image/unit roles only once
// JSON framing was opened. Their values receive no privacy exemption.
$responsiveAttrs = ['backgroundImageMobile' => ['alt' => 'Public landscape'],
    'items' => [['itemImageWidthUnitMobile' => 'px', 'itemYOffsetUnitMobile' => '%']]];
$responsiveEntity = $entities[0];
$responsiveEntity['content'] = Canon::post_file($front,
    '<!-- wp:unknown/responsive ' . serialize_block_attributes($responsiveAttrs) . ' /-->');
$gates->assertCanonicalContent([$responsiveEntity], $policy);
wprism_check(true, 'responsive mobile image and unit roles pass decoded block publication clearance');
Canon::write_file($scratch . '/state/' . $responsiveEntity['path'], $responsiveEntity['content']);
wprism_check_same(5, count(RepositoryCompiler::compile($scratch, $policy)->tree()),
    'immutable compilation accepts responsive control roles without a privacy exception');
foreach ([
    ['contactMobile' => '14155552671'],
    ['mobileNumber' => 14155552671],
    ['backgroundImageMobile' => ['alt' => 'Call +1 (415) 555-2671']],
    ['backgroundImageMobile' => ['contactPhone' => '14155552671']],
    ['imageWidthUnitMobile' => 'private@example.test'],
    ['imageWidthUnitMobile' => 'Call +1 (415) 555-2671'],
] as $privateAttrs) {
    $responsiveEntity['content'] = Canon::post_file($front,
        '<!-- wp:unknown/responsive ' . serialize_block_attributes($privateAttrs) . ' /-->');
    wprism_check_throws(static fn() => $gates->assertCanonicalContent([$responsiveEntity], $policy), RuntimeException::class,
        'contact roles and private responsive-control values still refuse publication', 'personal data');
    Canon::write_file($scratch . '/state/' . $responsiveEntity['path'], $responsiveEntity['content']);
    wprism_check_throws(static fn() => RepositoryCompiler::compile($scratch, $policy), RuntimeException::class,
        'immutable block clearance independently refuses contact roles and private responsive-control values');
}
$publish($entities);

$widgetUuid = '33333333-3333-4333-8333-333333333333';
$sourceDb = $database(0, $sourceWire, 'source-local');
WpStore::reset()->seedOptions(['home' => $sourceHome]);
$widgetTokens = new Tokens($sourceHome, $sourceHome . '/wp-content/uploads');
$widgetTokens->policy = $policy;
$baseOptions = $sourceDb->rows('wp_options');
$sourceDb->seedTable('wp_wprism_map', [...$sourceDb->rows('wp_wprism_map'),
    ['uuid' => $widgetUuid, 'id_kind' => 'widget_block', 'entity_type' => 'widget', 'local_id' => 17]]);
$seedWidget = static function (string $body) use ($sourceDb, $baseOptions): void {
    $sourceDb->seedTable('wp_options', [...$baseOptions,
        ['option_id' => 7, 'option_name' => 'widget_block', 'option_value' => serialize([17 => ['content' => $body], '_multiwidget' => 1]), 'autoload' => 'on'],
        ['option_id' => 8, 'option_name' => 'sidebars_widgets', 'option_value' => serialize(['main' => ['block-17'], 'array_version' => 3]), 'autoload' => 'yes']]);
};
$seedWidget($block($sourceWire));
$widgetBefore = $rows($sourceDb);
$capturedSidebar = SidebarState::capture($policy, $widgetTokens, false, false, true);
wprism_check_same([], $capturedSidebar['warnings'], 'real widget capture has no warnings');
$sidebarEntity = $capturedSidebar['entities'][0];
wprism_check_same($canonicalPlain, parse_blocks(Canon::decode($sidebarEntity['content'])['widgets'][0]['settings']['content'])[0]['attrs']['css'],
    'real widget capture shares decoded text and reference transport');
wprism_check_same($widgetBefore, $rows($sourceDb), 'real widget capture changes no table');
mkdir($scratch . '/state/sidebars', 0700, true);
Canon::write_file($scratch . '/state/' . $sidebarEntity['path'], $sidebarEntity['content']);
wprism_check_same(6, count(RepositoryCompiler::compile($scratch, $policy)->tree()), 'immutable compiler accepts encoded text in canonical widget content');
foreach (['secret' => $private, 'pii' => 'selector { content: "person@example.test"; }', 'unknown-block' => null] as $kind => $plain) {
    $seedWidget($kind === 'unknown-block' ? $unknownBody : $block(EncodedText::encode($plain, $rule, 'fixture')));
    $widgetBefore = $rows($sourceDb);
    wprism_check_throws(static fn() => SidebarState::capture($policy, $widgetTokens, false, false, true), RuntimeException::class,
        "real widget capture refuses decoded $kind before publication");
    wprism_check_same($widgetBefore, $rows($sourceDb), 'refused widget publication preserves complete source rows');
    $canonicalSidebar = Canon::decode($sidebarEntity['content']);
    $canonicalSidebar['widgets'][0]['settings']['content'] = $kind === 'unknown-block' ? $unknownBody : $block($plain);
    Canon::write_file($scratch . '/state/' . $sidebarEntity['path'], Canon::encode($canonicalSidebar));
    $before = $sourceDb->queries();
    wprism_check_throws(static fn() => RepositoryCompiler::compile($scratch, $policy), RuntimeException::class,
        "immutable widget compilation independently refuses $kind");
    wprism_check_same($before, $sourceDb->queries(), 'widget compiler refusal requires no target contact');
}

wprism_check_summary('encoded text values');
