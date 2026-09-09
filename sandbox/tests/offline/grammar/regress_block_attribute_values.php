<?php
declare(strict_types=1);

// The native editor stores nested media objects, CSV query selectors and
// preview caches in one block. Exercise its transport through real policy,
// Blocks, immutable compilation and the shared reference ledger.
$root = dirname(__DIR__, 4);
if (($argv[1] ?? '') === '--compile-without-wordpress') {
    require_once __DIR__ . '/../../lib/agent_version.php';
    require_once __DIR__ . '/../../lib/frozen_policy.php';
    require_once $root . '/agent/src/Repository/RepositoryCompiler.php';
    wprism_test_define_agent_versions();
    $input = json_decode(file_get_contents($argv[2] . '/probe.json'), true, flags: JSON_THROW_ON_ERROR);
    $purePolicy = WPrismTest\FrozenPolicy::policy([$input['manifest']], $input['site']);
    $pureArtifact = WPrism\RepositoryCompiler::compile($argv[2], $purePolicy);
    echo json_encode(['entities' => count($pureArtifact->tree()), 'wordpress' => function_exists('parse_blocks'),
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
require_once $root . '/agent/src/Grammar/Blocks.php';
require_once $root . '/agent/src/Kernel/BlockValueGrammar.php';
require_once $root . '/agent/src/Repository/RepositoryCompiler.php';
require_once $root . '/agent/src/Repository/Ledger.php';
require_once $root . '/agent/src/Review/BlockReferenceScanner.php';
require_once $root . '/agent/src/Review/Lint.php';
require_once $root . '/agent/src/Capture/PostCapture.php';
require_once $root . '/agent/src/Capture/CaptureSafetyGates.php';
require_once $root . '/agent/src/Apply/PostMaterializer.php';
wprism_test_define_agent_versions();

use WPrism\BlockAttributeReader;
use WPrism\Blocks;
use WPrism\BlockValueCodec;
use WPrism\BlockValueGrammar;
use WPrism\BlockReferenceScanner;
use WPrism\Canon;
use WPrism\RepositoryCompiler;
use WPrism\RecordFields;
use WPrism\Tokens;
use WPrismTest\FakeWpdb;
use WPrismTest\FrozenPolicy;

$uuid4 = '11111111-1111-4111-8111-111111111111';
$uuid9 = '99999999-9999-4999-8999-999999999999';
$token4 = '{{post:' . $uuid4 . '}}';
$token9 = '{{post:' . $uuid9 . '}}';
$values = [
    'image' => ['class' => 'authored', 'json_refs' => [['path' => '$.id', 'kind' => 'post']]],
    'slides' => ['class' => 'authored', 'json_refs' => [['path' => '$.image.id', 'kind' => 'post']]],
    'postIds' => ['class' => 'authored', 'ref' => 'post[]', 'cast' => 'csv'],
    'formID' => ['class' => 'authored', 'ref' => 'post', 'cast' => 'string'],
    'editorID' => ['class' => 'authored', 'ref' => 'user', 'cast' => 'string'],
    'selected' => ['class' => 'authored', 'ref' => 'post[]'],
    'map' => ['class' => 'authored', 'key_refs' => ['kind' => 'post']],
    'styles' => ['class' => 'authored', 'plain_data' => true],
    'preview' => ['class' => 'derived'],
    'gallery' => ['class' => 'authored', 'json_refs' => [['path' => '$.id', 'kind' => 'post']],
        'record_fields' => ['container' => 'list', 'fields' => ['id', 'url', 'alt', 'caption']]],
    'card' => ['class' => 'authored', 'plain_data' => true,
        'record_fields' => ['container' => 'object', 'fields' => ['label', 'payload']]],
];
$manifest = ['name' => 'block-value-fixture', 'spec_version' => 3,
    'engine_features' => [BlockValueGrammar::FEATURE, RecordFields::FEATURE, 'spec-window/v1'], 'option_autoload' => 'preserve',
    'post_types' => ['page' => ['class' => 'authored']],
    'post_meta' => ['_fixture_runtime' => ['class' => 'runtime']],
    'widgets' => ['block' => ['settings' => ['content' => ['class' => 'authored', 'codec' => 'blocks']]]],
    'block_values' => ['fixture/media' => $values],
    'block_attrs' => ['fixture/media' => [['path' => 'caption', 'tokenize' => 'text']]]];
$load = static fn(array $m) => FrozenPolicy::policy([$m], FrozenPolicy::site([$m], WPRISM_SPEC_VERSION));
$site = FrozenPolicy::site([$manifest], WPRISM_SPEC_VERSION);
$site['policy']['post_types'] = ['page'];
$site['policy']['taxonomies'] = [];
$policy = FrozenPolicy::policy([$manifest], $site);
$override = $site;
$override['policy']['block_values'] = $manifest['block_values'];
wprism_check_throws(static fn() => FrozenPolicy::policy([$manifest], $override), RuntimeException::class,
    'site policy cannot introduce or replace manifest-owned block values');
wprism_check_same(count($values) + 1, count($policy->block_attr_rules()['fixture/media']), 'value rules and disjoint legacy text share one effective block registry');
foreach (['v2', 'feature', 'class', 'field', 'csv', 'ambiguous', 'derived', 'path', 'unknown-kind', 'overlap', 'codec', 'legacy-injection'] as $fault) {
    $bad = $manifest;
    if ($fault === 'v2') $bad['spec_version'] = 2;
    if ($fault === 'feature') $bad['engine_features'] = ['spec-window/v1'];
    if ($fault === 'class') $bad['block_values']['fixture/media']['image']['class'] = 'runtime';
    if ($fault === 'field') $bad['block_values']['fixture/media']['image']['php_containers'] = true;
    if ($fault === 'csv') $bad['block_values']['fixture/media']['postIds']['ref'] = 'post';
    if ($fault === 'ambiguous') $bad['block_values']['fixture/media']['image']['plain_data'] = true;
    if ($fault === 'derived') $bad['block_values']['fixture/media']['preview']['plain_data'] = true;
    if ($fault === 'path') $bad['block_values']['fixture/media']['image']['json_refs'][0]['path'] = 'image.id';
    if ($fault === 'unknown-kind') $bad['block_values']['fixture/media']['image']['json_refs'][0]['kind'] = 'missing';
    if ($fault === 'overlap') $bad['block_attrs']['fixture/media'][] = ['path' => 'image', 'tokenize' => 'text'];
    if ($fault === 'codec') {
        $bad['interpreter'] = 'fixture';
        $bad['block_attrs']['fixture/media'] = [['path' => 'caption', 'codec' => 'fixture']];
    }
    if ($fault === 'legacy-injection') {
        unset($bad['block_values'], $bad['engine_features']);
        $bad['spec_version'] = 2;
        $bad['block_attrs']['fixture/media'] = [['path' => 'image', 'lint_ok' => true, 'value' => $values['image']]];
    }
    wprism_check_throws(static fn() => $load($bad), RuntimeException::class, "loader refuses $fault declaration");
}
foreach (['feature', 'null', 'extra', 'container', 'empty', 'duplicate', 'wildcard', 'field-type', 'field-length',
    'field-limit', 'scalar-ref', 'discarded-ref', 'wild-ref', 'recursive-ref', 'root-key-ref', 'discarded-key-ref'] as $fault) {
    $bad = $manifest;
    $rule =& $bad['block_values']['fixture/media']['gallery'];
    if ($fault === 'feature') $bad['engine_features'] = [BlockValueGrammar::FEATURE, 'spec-window/v1'];
    if ($fault === 'null') $rule['record_fields'] = null;
    if ($fault === 'extra') $rule['record_fields']['unknown'] = true;
    if ($fault === 'container') $rule['record_fields']['container'] = 'auto';
    if ($fault === 'empty') $rule['record_fields']['fields'] = [];
    if ($fault === 'duplicate') $rule['record_fields']['fields'][] = 'id';
    if ($fault === 'wildcard') $rule['record_fields']['fields'][] = '*';
    if ($fault === 'field-type') $rule['record_fields']['fields'][] = 3;
    if ($fault === 'field-length') $rule['record_fields']['fields'][] = str_repeat('x', 129);
    if ($fault === 'field-limit') $rule['record_fields']['fields'] = array_map(static fn(int $i): string => 'field' . $i, range(0, RecordFields::MAX_FIELDS));
    if ($fault === 'scalar-ref') { unset($rule['json_refs']); $rule['ref'] = 'post'; }
    if ($fault === 'discarded-ref') $rule['json_refs'][0]['path'] = '$.author';
    if ($fault === 'wild-ref') $rule['json_refs'][0]['path'] = '$.*';
    if ($fault === 'recursive-ref') $rule['json_refs'][0]['path'] = '$..id';
    if ($fault === 'root-key-ref') $rule['key_refs'] = ['kind' => 'post'];
    if ($fault === 'discarded-key-ref') $rule['key_refs'] = ['kind' => 'post', 'path' => '$.cache'];
    unset($rule);
    wprism_check_throws(static fn() => $load($bad), RuntimeException::class, "loader refuses record projection $fault");
}
$nestedRecords = $manifest;
$nestedRecords['block_values']['fixture/media']['card'] = ['class' => 'authored',
    'record_fields' => ['container' => 'object', 'fields' => ['links']],
    'key_refs' => ['kind' => 'post', 'path' => '$.links']];
$nestedPolicy = $load($nestedRecords);
wprism_check(true, 'record projection composes nested key references wholly inside retained fields');
foreach (['options', 'post_meta', 'term_meta', 'user_meta'] as $section) {
    $bad = $manifest;
    $bad[$section]['fixture_record'] = $values['card'];
    wprism_check_throws(static fn() => $load($bad), RuntimeException::class, "record projection cannot silently grant $section transport");
}
$foreign = ['name' => 'foreign', 'spec_version' => 3, 'option_autoload' => 'preserve',
    'block_attrs' => ['fixture/media' => [['path' => 'other', 'lint_ok' => true]]]];
foreach ([[$manifest, $foreign], [$foreign, $manifest]] as $manifests) {
    wprism_check_throws(static fn() => FrozenPolicy::policy($manifests, FrozenPolicy::site($manifests, WPRISM_SPEC_VERSION)),
        RuntimeException::class, 'two block owners refuse in either pin order');
}
// The groups suite reuses this complete product-path proof: compiler isolation,
// checked SQL, rollback, repeat apply and widget content must share the same
// normalized contract as capture. Keep declaration-refusal cases above exact.
if (($argv[1] ?? '') === '--grouped-attributes') {
    $manifest['engine_features'][] = BlockValueGrammar::GROUP_FEATURE;
    sort($manifest['engine_features'], SORT_STRING);
    $manifest['block_values'] = ['groups' => []];
    foreach ($values as $attribute => $value) {
        $manifest['block_values']['groups'][] = ['blocks' => ['fixture/media'], 'attributes' => [$attribute], 'value' => $value];
    }
    $policy = FrozenPolicy::policy([$manifest], $site);
}
$database = static function (int $offset) use ($uuid4, $uuid9): FakeWpdb {
    return FakeWpdb::install()->seedTable('wp_wprism_map', [
        ['uuid' => $uuid4, 'entity_type' => 'post:page', 'id_kind' => 'post', 'local_id' => 4 + $offset],
        ['uuid' => $uuid9, 'entity_type' => 'post:page', 'id_kind' => 'post', 'local_id' => 9 + $offset],
    ])->seedTable('wp_users', [['ID' => 1 + $offset, 'user_login' => 'admin']]);
};
$attrs = ['image' => ['id' => 4, 'url' => 'https://source.test/image.png'],
    'slides' => [['image' => ['id' => 9, 'url' => 'https://source.test/second.png']], ['image' => ['id' => 4]]],
    'postIds' => '4,9,4', 'formID' => '9', 'editorID' => '1', 'selected' => [9, 4],
    'map' => [9 => ['url' => 'https://source.test/map']],
    'styles' => ['background' => 'https://source.test/style.png', 'width' => 32],
    'preview' => ['post_id' => 4, 'email' => 'editor@example.test', 'html' => '<form data-source="4"></form>'],
    'gallery' => [
        ['caption' => 'Caption', 'id' => 4, 'nonces' => ['edit' => 'fixture-native-nonce'], 'url' => 'https://source.test/gallery.png'],
        ['caption' => 'Caption', 'id' => 4, 'nonces' => ['edit' => 'fixture-native-nonce'], 'url' => 'https://source.test/gallery.png'],
        ['id' => 9, 'authorEmail' => 'private@example.test'],
    ],
    'card' => ['payload' => [null, false, 0, 1.5, ['nested' => 'https://source.test/card']],
        'cache' => ['secret' => 'sk_live_' . str_repeat('a', 32)], 'label' => 'Card'],
    'caption' => 'https://source.test/caption', 'localCounter' => 3];
$block = static fn(array $a): string => '<!-- wp:fixture/media ' . serialize_block_attributes($a) . ' /-->';
$native = '<!-- wp:group --><div>' . $block($attrs) . '<img class="before&#13;wp-image-4"/></div><!-- /wp:group -->';
$database(0);
$sourceTokens = new Tokens('https://source.test', 'https://source.test/wp-content/uploads');
$nestedNative = $block(['card' => ['links' => [4 => ['url' => 'https://source.test/link']], 'cache' => 'discarded']]);
$nestedCanonical = Blocks::capture_rewrite($nestedNative, $nestedPolicy, $sourceTokens);
wprism_check_same(['card' => ['links' => [$token4 => ['url' => '{{home}}/link']]]], parse_blocks($nestedCanonical)[0]['attrs'],
    'nested key references capture through the shared structured codec after projection');
$captured = Blocks::capture_rewrite($native, $policy, $sourceTokens);
$a = parse_blocks($captured)[0]['innerBlocks'][0]['attrs'];
wprism_check(str_contains($captured, 'class="before&#13;wp-image-' . $token4 . '"'), 'saved media outside the core image allowlist captures its reserved class');
wprism_check_same($token4, $a['image']['id'], 'nested image identity is tokenized');
wprism_check_same($token9, $a['slides'][0]['image']['id'], 'shared JSON paths transparently traverse repeaters');
wprism_check_same([$token4, $token9, $token4], $a['postIds'], 'CSV capture keeps order, every selection and duplicate selections');
wprism_check_same([$token9, $token4], $a['selected'], 'native ID list uses the same scalar reference codec');
wprism_check_same([$token9], array_keys($a['map']), 'reference-keyed attribute map is portable');
wprism_check_same(false, array_key_exists('preview', $a), 'derived preview content is absent before publication');
wprism_check_same('{{home}}/style.png', $a['styles']['background'], 'plain data uses the shared text tokenizer');
wprism_check_same('{{home}}/caption', $a['caption'], 'legacy disjoint text still rewrites');
wprism_check_same([], $sourceTokens->warnings, 'valid native fixture has no dangling warnings');
wprism_check_same([
    ['caption' => 'Caption', 'id' => $token4, 'url' => '{{home}}/gallery.png'],
    ['caption' => 'Caption', 'id' => $token4, 'url' => '{{home}}/gallery.png'], ['id' => $token9],
], $a['gallery'], 'record projection preserves original key order, absent fields and repeated list members while rebinding references');
wprism_check_same(['payload' => [null, false, 0, 1.5, ['nested' => '{{home}}/card']], 'label' => 'Card'], $a['card'],
    'record projection preserves retained JSON types and composes the shared text codec');
$reader = BlockAttributeReader::read($captured, ['fixture/media']);
wprism_check_same($a, $reader[0]['attrs'], 'pure immutable reader agrees with the official WordPress parser on nested block attributes');
$flatten = static function (array $blocks) use (&$flatten): array {
    $out = [];
    foreach ($blocks as $parsed) {
        if ($parsed['blockName'] === 'fixture/media') $out[] = $parsed['attrs'];
        array_push($out, ...$flatten($parsed['innerBlocks']));
    }
    return $out;
};
foreach ([
    $native,
    '<p>Classic prefix</p>' . $block(['label' => 'quoted " } /--> <!-- wp:fixture/media {} /-->']) . '<p>suffix</p>',
    "<!--\twp:fixture/media\n{\"nested\":{\"list\":[1,[],{}]},\"text\":\"a}b\"}\t/-->",
    '<!-- wp:fixture/media --><div>' . $block(['label' => 'inner']) . '</div><!-- /wp:fixture/media -->',
    '<!-- wp:unknown/type {"marker":"<!-- wp:fixture/media {} /-->"} /-->' . $block(['label' => 'after']),
    $block(['text' => str_repeat('A}B {" unicode Ω ', 10000)]),
    '<!-- wp:fixture/media {"label":"outer"} -->orphaned opener',
] as $document) {
    wprism_check_same($flatten(parse_blocks($document)), array_column(BlockAttributeReader::read($document, ['fixture/media']), 'attrs'),
        'pure attribute reader follows native delimiter boundaries, escaping and nesting');
}
foreach (['<!-- wp:fixture/media {"x":broken} /-->', '<!-- wp:fixture/media {"x":4}',
    '<!-- wp:fixture/media [4] /-->'] as $badDelimiter) {
    wprism_check_throws(static fn() => BlockAttributeReader::read($badDelimiter, ['fixture/media']), RuntimeException::class,
        'declared malformed attribute framing refuses without a permissive fallback');
}
wprism_check_throws(static fn() => BlockAttributeReader::read(str_repeat('x', 16777217), ['fixture/media']), RuntimeException::class,
    'immutable attribute document has an explicit byte bound');
foreach ([['x' => INF], ['x' => new stdClass()], ['x' => "\xff"], array_fill(0, 100001, null)] as $badJson) {
    wprism_check_throws(static fn() => BlockValueCodec::assert_value($badJson, $values['styles'], false, 'fixture'), RuntimeException::class,
        'block values refuse non-JSON or unbounded containers');
}

$database(800);
$targetTokens = new Tokens('https://target.example.test/longer-prefix', 'https://target.example.test/longer-prefix/wp-content/uploads');
$applied = Blocks::apply_rewrite($captured, $policy, $targetTokens);
wprism_check_same(['card' => ['links' => [804 => ['url' => 'https://target.example.test/longer-prefix/link']]]],
    parse_blocks(Blocks::apply_rewrite($nestedCanonical, $nestedPolicy, $targetTokens))[0]['attrs'],
    'retained nested map keys and URLs use the actual target ledger');
$appliedAttrs = parse_blocks($applied)[0]['innerBlocks'][0]['attrs'];
wprism_check(str_contains($applied, 'class="before&#13;wp-image-804"'), 'saved media class follows the target identity alongside structured attributes');
wprism_check_same(804, $appliedAttrs['image']['id'], 'target attachment identity is resolved from its ledger');
wprism_check_same('804,809,804', $appliedAttrs['postIds'], 'target query selector restores native CSV storage');
wprism_check_same('809', $appliedAttrs['formID'], 'selected form keeps its declared native string type');
wprism_check_same('801', $appliedAttrs['editorID'], 'user reference rebinds by login through the shared user codec');
wprism_check_same([809], array_keys($appliedAttrs['map']), 'target map keys use target identities');
wprism_check_same('https://target.example.test/longer-prefix/image.png', $appliedAttrs['image']['url'], 'nested image URL rebinds to the longer target URL');
wprism_check_same($captured, Blocks::capture_rewrite($applied, $policy, $targetTokens), 'cross-environment recapture is an exact fixed point');
wprism_check_same([], BlockReferenceScanner::scan(parse_blocks($captured), $policy->block_attr_rules(), 'fixture.md', 'https://source.test', static fn(int $id) => null),
    'lint accepts canonical structured block values without treating layout numbers as IDs');
foreach (['raw', 'wrong-kind', 'trailing-token', 'csv-string', 'derived', 'array-ref', 'excluded-record'] as $fault) {
    $bad = $a;
    if ($fault === 'raw') $bad['image']['id'] = 4;
    if ($fault === 'wrong-kind') $bad['image']['id'] = str_replace('post:', 'term:', $token4);
    if ($fault === 'trailing-token') $bad['image']['id'] = $token4 . "\n";
    if ($fault === 'csv-string') $bad['postIds'] = $token4 . ',' . $token9;
    if ($fault === 'derived') $bad['preview'] = null;
    if ($fault === 'array-ref') $bad['image']['id'] = [];
    if ($fault === 'excluded-record') $bad['gallery'][0]['nonces'] = ['edit' => 'canonical-injection'];
    wprism_check_throws(static fn() => Blocks::apply_rewrite($block($bad), $policy, $targetTokens), RuntimeException::class,
        "apply refuses malformed $fault canonical value");
    wprism_check(BlockReferenceScanner::scan(parse_blocks($block($bad)), $policy->block_attr_rules(), 'fixture.md',
        'https://source.test', static fn(int $id) => null) !== [], "lint reports malformed $fault canonical value");
}
foreach ([null, false, 'record', ['id' => 4], [[]], [[4]], [['cache' => 'only excluded fields']], [[[['id' => 4]]]]] as $badRecords) {
    wprism_check_throws(static fn() => Blocks::capture_rewrite($block(['gallery' => $badRecords]), $policy, $sourceTokens),
        RuntimeException::class, 'record projection refuses ambiguous or malformed list records');
}
foreach ([[], [['label' => 'nested record']], ['cache' => 'only excluded']] as $badRecord) {
    wprism_check_throws(static fn() => Blocks::capture_rewrite($block(['card' => $badRecord]), $policy, $sourceTokens),
        RuntimeException::class, 'object projection refuses an empty record or an undeclared list container');
}
foreach ([new stdClass(), INF, "\xff"] as $badJson) {
    wprism_check_throws(static fn() => BlockValueCodec::capture([['id' => 4, 'cache' => $badJson]], $values['gallery'],
        $sourceTokens, static function (): void {}, 'fixture'), RuntimeException::class,
        'native JSON bounds apply even inside discarded record fields');
}
foreach (['4, 9', '04,9', '4,,9', '4.5', '9999999999999999999999', 4, [4, 9]] as $badCsv) {
    wprism_check_throws(static fn() => Blocks::capture_rewrite($block(['postIds' => $badCsv]), $policy, $sourceTokens),
        RuntimeException::class, 'capture refuses lossy CSV coercion');
}
foreach ([4.5, '4', false, -1, [], ['hidden' => 4]] as $badId) {
    wprism_check_throws(static fn() => Blocks::capture_rewrite($block(['image' => ['id' => $badId]]), $policy, $sourceTokens),
        RuntimeException::class, 'capture refuses a nested ID outside its declared native type');
}
foreach ([null, false, 4, '4'] as $badContainer) {
    wprism_check_throws(static fn() => Blocks::capture_rewrite($block(['image' => $badContainer]), $policy, $sourceTokens),
        RuntimeException::class, 'structured reference parent cannot silently become a scalar');
}
foreach (['postIds' => '', 'selected' => [], 'image' => ['id' => 0], 'formID' => '0', 'gallery' => []] as $key => $unset) {
    $unsetBody = $block([$key => $unset]);
    wprism_check_same($unsetBody, Blocks::apply_rewrite(Blocks::capture_rewrite($unsetBody, $policy, $sourceTokens), $policy, $targetTokens),
        "unset $key round trips without spurious dangling references");
}

$scratch = sys_get_temp_dir() . '/wprism-block-values-' . bin2hex(random_bytes(8));
mkdir($scratch . '/state/posts/page', 0700, true);
$remove = static function (string $path) use (&$remove): void {
    if (!is_dir($path) || is_link($path)) { if (file_exists($path) || is_link($path)) unlink($path); return; }
    foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $entry) $remove($entry->getPathname());
    rmdir($path);
};
register_shutdown_function(static fn() => $remove($scratch));
Canon::write_file($scratch . '/site.wprism.json', Canon::encode($site));
foreach ([$uuid4 => 'first', $uuid9 => 'second'] as $uuid => $slug) {
    $front = ['author' => 'user:admin', 'comment_status' => 'closed', 'date' => '2026-09-08 00:00:00',
        'date_gmt' => '2026-09-08 00:00:00', 'excerpt' => '', 'menu_order' => 0, 'meta' => (object) [],
        'modified' => '2026-09-08 00:00:00', 'modified_gmt' => '2026-09-08 00:00:00', 'parent' => null,
        'ping_status' => 'closed', 'slug' => $slug, 'status' => 'publish', 'terms' => (object) [],
        'title' => $slug, 'type' => 'page', 'uuid' => $uuid];
    Canon::write_file($scratch . '/state/posts/page/' . $uuid . '--' . $slug . '.md', Canon::post_file($front, $slug === 'first' ? $captured : $slug));
    if ($slug === 'first') $firstFront = $front;
}
$databaseBefore = $GLOBALS['wpdb']->queries();
$artifact = RepositoryCompiler::compile($scratch, $policy);
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
wprism_check_same(0, $childStatus, 'immutable block compilation succeeds in an isolated process');
wprism_check_same('', $childError, 'isolated immutable compiler emits no diagnostics');
wprism_check_same(['entities' => 2, 'wordpress' => false, 'database' => false], json_decode($childOut, true),
    'complete immutable compiler needs neither WordPress parsing nor a database object');
wprism_check_same($databaseBefore, $GLOBALS['wpdb']->queries(), 'immutable compiler validates block references without database contact');
wprism_check($artifact->tree() !== [], 'real immutable compiler admits the complete canonical post graph');
foreach (['raw', 'derived', 'malformed-json', 'raw-html', 'encoded-html', 'excluded-record'] as $fault) {
    $bad = $a;
    if ($fault === 'raw') $bad['image']['id'] = 804;
    if ($fault === 'derived') $bad['preview'] = ['source' => 4];
    if ($fault === 'excluded-record') $bad['gallery'][0]['nonces'] = ['edit' => 'canonical-injection'];
    $badBody = $fault === 'malformed-json' ? '<!-- wp:fixture/media {"image":broken} /-->' : $block($bad);
    if ($fault === 'raw-html') $badBody = str_replace('wp-image-' . $token4, 'wp-image-804', $captured);
    if ($fault === 'encoded-html') $badBody = str_replace('wp-image-{{', 'wp-image-&#123;&#123;', $captured);
    Canon::write_file($scratch . '/state/posts/page/' . $uuid4 . '--first.md', Canon::post_file($firstFront, $badBody));
    wprism_check_throws(static fn() => RepositoryCompiler::compile($scratch, $policy), RuntimeException::class,
        "immutable compiler refuses $fault before any apply");
}
wprism_check_same($databaseBefore, $GLOBALS['wpdb']->queries(), 'all immutable refusals leave the database untouched');

// A real post transaction proves the codec participates before checked SQL,
// while the existing transaction owner handles a failure after prior writes.
$nativeRow = static function (int $id, string $slug, string $body): array {
    return ['ID' => $id, 'post_author' => 1, 'post_date' => '2026-09-08 00:00:00', 'post_date_gmt' => '2026-09-08 00:00:00',
        'post_content' => $body, 'post_title' => $slug, 'post_excerpt' => '', 'post_status' => 'publish',
        'comment_status' => 'closed', 'ping_status' => 'closed', 'post_password' => '', 'post_name' => $slug,
        'post_modified' => '2026-09-08 00:00:00', 'post_modified_gmt' => '2026-09-08 00:00:00',
        'post_parent' => 0, 'menu_order' => 0, 'post_type' => 'page', 'post_mime_type' => '', 'guid' => 'local-' . $id];
};
$postDatabase = static function (int $offset, array $posts, array $meta = []) use ($database): FakeWpdb {
    $db = $database($offset)->enableInformationSchema()->seedTable('wp_posts', $posts)->seedTable('wp_postmeta', $meta)
        ->seedTable('wp_users', [['ID' => 1 + $offset, 'user_login' => 'admin']]);
    foreach (['wp_posts', 'wp_postmeta', 'wp_wprism_map', 'wp_users'] as $table) $db->setTableEngine($table, 'InnoDB');
    $db->setColumns('wp_wprism_map', ['uuid' => 'varchar(36)', 'id_kind' => 'varchar(64)', 'local_id' => 'bigint unsigned', 'entity_type' => 'varchar(64)'])
        ->setUniqueKey('wp_wprism_map', ['uuid', 'id_kind'])->setUniqueKey('wp_wprism_map', ['id_kind', 'local_id']);
    $db->setColumns('wp_posts', ['ID' => 'bigint unsigned', 'post_content' => 'longtext', 'post_type' => 'varchar(20)'])
        ->setColumns('wp_users', ['ID' => 'bigint unsigned', 'user_login' => 'varchar(60)'])
        ->setColumns('wp_postmeta', ['meta_id' => 'bigint unsigned', 'post_id' => 'bigint unsigned', 'meta_key' => 'varchar(255)', 'meta_value' => 'longtext'])
        ->setIndexes('wp_postmeta', [['Key_name' => 'post_id', 'Column_name' => 'post_id', 'Seq_in_index' => 1,
            'Non_unique' => 1, 'Sub_part' => null, 'Index_type' => 'BTREE']]);
    return $db;
};
$capturePost = static function (array $row, string $uuid, Tokens $tokens) use ($policy): array {
    $meta = new WPrism\EntityMetaCapture($policy, $tokens,
        static function (): never { throw new LogicException('the fixture owns no authored metadata'); },
        static function (): void {}, static function (): never { throw new LogicException('unclassified fixture metadata'); });
    return (new WPrism\PostCapture($policy, $tokens, $meta, new WPrism\MediaCapture()))->capture((object) $row, $uuid, []);
};
$sourceRows = [$nativeRow(4, 'first', $native), $nativeRow(9, 'second', '<p>Second source page</p>')];
$sourceDb = $postDatabase(0, $sourceRows);
$sourceTokens = new Tokens('https://source.test', 'https://source.test/wp-content/uploads');
$sourceEntities = [];
foreach ([$uuid4, $uuid9] as $i => $uuid) {
    $capture = $capturePost($sourceRows[$i], $uuid, $sourceTokens);
    $sourceEntities[$uuid] = $capture['entity'];
    Canon::write_file($scratch . '/state/' . $capture['entity']['path'], $capture['entity']['content']);
}
wprism_check_same($sourceRows, $sourceDb->rows('wp_posts'), 'native post capture is read-only');
$artifact = RepositoryCompiler::compile($scratch, $policy);
$targetHome = 'https://target.example.test/longer-prefix';
WPrismTest\WpStore::reset()->seedOptions(['home' => $targetHome]);
$runtimeMeta = [['meta_id' => 1, 'post_id' => 804, 'meta_key' => '_fixture_runtime', 'meta_value' => 'target runtime']];
$targetDb = $postDatabase(800, [$nativeRow(804, 'first', 'old target first'), $nativeRow(809, 'second', 'old target second'),
    $nativeRow(900, 'target-only', 'preserved target content')], $runtimeMeta);
$targetTokens = new Tokens($targetHome, $targetHome . '/wp-content/uploads');
$fields = new WPrism\ApplyFieldMaterializer($policy, $targetTokens);
$materializer = new WPrism\PostMaterializer($policy, $targetTokens, $fields,
    new WPrism\RelationshipMaterializer($policy, $fields),
    new WPrism\AttachmentMaterializer($policy, $fields, $artifact, $scratch));
$writePosts = static function (array $tree) use ($fields, $materializer): void {
    WPrism\Db::start_repeatable_read('block value fixture', new WPrism\NativeDatabaseProfile(
        ['wp_posts', 'wp_postmeta', 'wp_wprism_map', 'wp_users'], ['wp_posts', 'wp_postmeta']));
    $fields->begin_authored_transaction();
    WPrism\CacheInvalidationTransaction::begin();
    try {
        $warnings = [];
        foreach ($tree as $entity) {
            $materializer->finalize_post($entity['data'], $entity['body'], 1, $warnings, []);
        }
        WPrism\Db::commit('block value fixture');
        WPrism\CacheInvalidationTransaction::finish();
        wprism_check_same([], $warnings, 'native post materialization has no warnings');
    } catch (Throwable $failure) {
        WPrism\Db::rollback('block value fixture');
        throw $failure;
    } finally {
        $fields->end_authored_transaction();
        WPrism\CacheInvalidationTransaction::end();
    }
};
$targetBefore = $targetDb->rows('wp_posts');
$mapBefore = $targetDb->rows('wp_wprism_map');
$sawEarlierPost = false;
$postUpdates = 0;
$targetDb->onQuery(static function (string $sql, string $method, FakeWpdb $db) use (&$sawEarlierPost, &$postUpdates): ?string {
    if (str_starts_with($sql, 'UPDATE `wp_posts` ') && ++$postUpdates === 2) {
        $sawEarlierPost = str_contains($db->rows('wp_posts')[0]['post_content'], '804,809,804');
        return 'injected second post update failure';
    }
    return null;
});
wprism_check_throws(static fn() => $writePosts($artifact->tree()), RuntimeException::class, 'late post SQL failure is loud');
wprism_check($sawEarlierPost, 'late failure occurs after the earlier post contains remapped block references');
wprism_check_same($targetBefore, $targetDb->rows('wp_posts'), 'late failure rolls back every earlier post update');
wprism_check_same($runtimeMeta, $targetDb->rows('wp_postmeta'), 'rollback retains target runtime metadata');
wprism_check_same($mapBefore, $targetDb->rows('wp_wprism_map'), 'rollback retains the complete identity map');
$targetDb->onQuery(null);
$writePosts($artifact->tree());
$after = $targetDb->rows('wp_posts');
wprism_check_same($applied, $after[0]['post_content'], 'checked SQL stores the complete correctly rebound native block body');
wprism_check_same($targetBefore[2], $after[2], 'target-only post remains byte-identical');
wprism_check_same($runtimeMeta, $targetDb->rows('wp_postmeta'), 'successful apply retains target runtime metadata');
$writePosts($artifact->tree());
wprism_check_same($after, $targetDb->rows('wp_posts'), 'repeated full post materialization is idempotent');
foreach ([$uuid4, $uuid9] as $i => $uuid) {
    $recaptured = $capturePost($after[$i], $uuid, $targetTokens);
    wprism_check_same($sourceEntities[$uuid]['content'], $recaptured['entity']['content'], 'full native post recapture is canonical byte equality');
}
$clearance = new WPrism\CaptureSafetyGates($scratch);
$clearance->assertCanonicalContent(array_values($sourceEntities), $policy);
wprism_check(true, 'removed native preview data does not enter the publication privacy boundary');
foreach (['secret' => 'sk_live_' . str_repeat('a', 32), 'pii' => 'person@example.test'] as $kind => $unsafe) {
    $badBody = $block(['styles' => ['value' => $unsafe]]);
    $badEntity = $sourceEntities[$uuid4];
    [$badFront] = Canon::parse_post_file($badEntity['content']);
    $badEntity['content'] = Canon::post_file($badFront, $badBody);
    wprism_check_throws(static fn() => $clearance->assertCanonicalContent([$badEntity], $policy), RuntimeException::class,
        "block value declarations cannot bypass $kind publication clearance");
    Canon::write_file($scratch . '/state/' . $badEntity['path'], $badEntity['content']);
    wprism_check_throws(static fn() => RepositoryCompiler::compile($scratch, $policy), RuntimeException::class,
        "immutable block compilation independently refuses $kind");
    $badEntity['content'] = Canon::post_file($badFront, $block(['card' => ['label' => $unsafe]]));
    wprism_check_throws(static fn() => $clearance->assertCanonicalContent([$badEntity], $policy), RuntimeException::class,
        "retained record fields cannot bypass $kind publication clearance");
    Canon::write_file($scratch . '/state/' . $badEntity['path'], $badEntity['content']);
    wprism_check_throws(static fn() => RepositoryCompiler::compile($scratch, $policy), RuntimeException::class,
        "retained record fields cannot bypass immutable $kind clearance");
}
Canon::write_file($scratch . '/state/' . $sourceEntities[$uuid4]['path'], $sourceEntities[$uuid4]['content']);
$postDatabase(0, [...$sourceRows, $nativeRow(77, 'outside-scope', '')]);
$outside = $GLOBALS['wpdb']->rows('wp_posts');
$outside[2]['post_type'] = 'unmanaged';
$GLOBALS['wpdb']->seedTable('wp_posts', $outside);
foreach ([false, true] as $force) {
    $scopeTokens = new Tokens('https://source.test', 'https://source.test/wp-content/uploads');
    $scopeBody = Blocks::capture_rewrite($block(['image' => ['id' => 77], 'postIds' => '4,404']),
        $policy, $scopeTokens, $force, "page 'fixture'");
    $scopeAttrs = parse_blocks($scopeBody)[0]['attrs'];
    wprism_check_same(null, $scopeAttrs['image']['id'], 'unmapped nested identity never survives as a raw ID');
    wprism_check_same([$token4], $scopeAttrs['postIds'], 'dangling CSV member is dropped while valid selection remains');
    wprism_check_same(2, count($scopeTokens->warnings), 'one warning per missing reference, without duplicate callback warnings');
    wprism_check_same($force ? 0 : 1, count($scopeTokens->unscopedBlockRefs), 'real unscoped reference uses the existing force boundary');
    $gates = new WPrism\CaptureSafetyGates($scratch);
    if ($force) {
        $gates->assertContentReferences($scopeTokens);
    } else {
        wprism_check_throws(static fn() => $gates->assertContentReferences($scopeTokens), RuntimeException::class,
            'ordinary capture publication refuses the unscoped structured block reference');
    }
}
mkdir($scratch . '/state/sidebars', 0700, true);
$sidebar = ['widgets' => [['uuid' => '33333333-3333-4333-8333-333333333333', 'type' => 'block',
    'settings' => ['content' => $captured]]]];
Canon::write_file($scratch . '/state/sidebars/main.json', Canon::encode($sidebar));
wprism_check_same(3, count(RepositoryCompiler::compile($scratch, $policy)->tree()),
    'complete compiler accepts the same canonical block inside a widget');
foreach (['raw', 'derived', 'raw-html', 'encoded-html', 'excluded-record'] as $fault) {
    $bad = $a;
    if ($fault === 'raw') $bad['image']['id'] = 4;
    if ($fault === 'derived') $bad['preview'] = ['source' => 4];
    if ($fault === 'excluded-record') $bad['gallery'][0]['nonces'] = ['edit' => 'canonical-injection'];
    $sidebar['widgets'][0]['settings']['content'] = $fault === 'raw-html' ? str_replace('wp-image-' . $token4, 'wp-image-804', $captured) : $block($bad);
    if ($fault === 'encoded-html') $sidebar['widgets'][0]['settings']['content'] = str_replace('wp-image-{{', 'wp-image-&#123;&#123;', $captured);
    Canon::write_file($scratch . '/state/sidebars/main.json', Canon::encode($sidebar));
    wprism_check_throws(static fn() => RepositoryCompiler::compile($scratch, $policy), RuntimeException::class,
        "immutable widget block content refuses $fault just like a post body");
}
$badHtml = str_replace('wp-image-' . $token4, 'wp-image-804', $captured);
Canon::write_file($scratch . '/state/posts/page/' . $uuid4 . '--first.md', Canon::post_file($firstFront, $badHtml));
$sidebar['widgets'][0]['settings']['content'] = $badHtml;
Canon::write_file($scratch . '/state/sidebars/main.json', Canon::encode($sidebar));
$lintEnvironment = WPrism\LintEnvironment::recorded(['format' => WPrism\LintEnvironment::FORMAT,
    'home' => 'https://source.test', 'entities' => [['id' => 804, 'resolved' => null]],
    'scanned' => ['blocks' => false, 'shortcodes' => false],
    'state_hash' => WPrism\LintEnvironment::state_hash($scratch . '/state')]);
$beforeLint = $GLOBALS['wpdb']->queries();
$findings = WPrism\Lint::scan_tree($scratch . '/state', $policy, $lintEnvironment);
$htmlFindings = array_values(array_filter($findings, static fn(array $finding) => str_ends_with($finding['locator'], '.class')));
wprism_check_same([804, 804], array_column($htmlFindings, 'value'), 'public lint finds raw media IDs in posts and widgets even when block parsing is unavailable');
wprism_check_same($beforeLint, $GLOBALS['wpdb']->queries(), 'recorded HTML lint needs no database reads');
$encodedHtml = str_replace('wp-image-{{', 'wp-image-&#123;&#123;', $captured);
wprism_check_throws(static fn() => Blocks::apply_rewrite($encodedHtml, $policy, $targetTokens), RuntimeException::class, 'direct apply refuses an encoded canonical HTML token before returning native content');
Canon::write_file($scratch . '/state/posts/page/' . $uuid4 . '--first.md', Canon::post_file($firstFront, $encodedHtml));
$sidebar['widgets'][0]['settings']['content'] = $encodedHtml;
Canon::write_file($scratch . '/state/sidebars/main.json', Canon::encode($sidebar));
$lintEnvironment = WPrism\LintEnvironment::recorded(['format' => WPrism\LintEnvironment::FORMAT,
    'home' => 'https://source.test', 'entities' => [], 'scanned' => ['blocks' => false, 'shortcodes' => false],
    'state_hash' => WPrism\LintEnvironment::state_hash($scratch . '/state')]);
$findings = WPrism\Lint::scan_tree($scratch . '/state', $policy, $lintEnvironment);
wprism_check_same(2, count(array_filter($findings, static fn(array $finding) => $finding['class'] === 'invalid_html_media_reference')), 'public lint names hidden encoded identity edges in posts and widgets');
wprism_check_summary('block attribute values');
