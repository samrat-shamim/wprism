<?php
declare(strict_types=1);

$root = dirname(__DIR__, 4);
foreach (['check.php', 'wp_stubs.php', 'agent_version.php', 'frozen_policy.php', 'FakeWpdb.php'] as $file) {
    require_once "$root/sandbox/tests/lib/$file";
}
require_once "$root/agent/src/Kernel/KeyBoundStrings.php";
require_once "$root/agent/src/Grammar/Tokens.php";
require_once "$root/agent/src/Repository/Ledger.php";
require_once "$root/agent/src/Repository/RepositoryCompiler.php";
require_once "$root/agent/src/Review/StructuredReferenceScanner.php";
wprism_test_define_agent_versions();

use WPrism\Canon;
use WPrism\KeyBoundStrings;
use WPrism\OptionState;
use WPrism\PhpContainerValue;
use WPrism\ReferenceShapeGrammar;
use WPrism\RepositoryCompiler;
use WPrism\StructuredReferenceCodec;
use WPrism\StructuredReferenceScanner;
use WPrism\Tokens;
use WPrismTest\FakeWpdb;
use WPrismTest\FrozenPolicy;
use WPrismTest\WpStore;

$uuid13 = '11111111-1111-4111-8111-111111111113';
$uuid23 = '22222222-2222-4222-8222-222222222223';
$token13 = '{{post:' . $uuid13 . '}}';
$token23 = '{{post:' . $uuid23 . '}}';
$keyRule = ['path' => '$.root.items.entries', 'kind' => 'post', 'container' => 'php', 'bound_strings' => [
    ['path' => '$.items.selector', 'prefix' => 'body#', 'suffix' => '#'],
    ['path' => '$.items.href', 'prefix' => '?p=', 'suffix' => '&'],
]];
$jsonRefs = [['path' => '$.root.items.entries.items.*.items.related', 'kind' => 'post']];
$rule = ['class' => 'authored', 'php_containers' => true, 'key_refs' => $keyRule, 'json_refs' => $jsonRefs];
$manifest = ['name' => 'key-bound-fixture', 'spec_version' => 3, 'option_autoload' => 'preserve',
    'engine_features' => [KeyBoundStrings::FEATURE, PhpContainerValue::FEATURE, 'spec-window/v1'],
    'post_types' => ['page' => ['class' => 'authored']], 'options' => ['fixture_links' => $rule]];
$validate = static fn(array $source, bool $manifestSource = true) => ReferenceShapeGrammar::validate_reference_shapes($source, 'fixture', $manifestSource);
$validate($manifest);
wprism_check(true, 'a static typed option negotiates key-bound strings');
$pattern = $manifest;
unset($pattern['options']);
$pattern['option_patterns'] = [$rule + ['match' => '^fixture_links$']];
$validate($pattern);
wprism_check(true, 'a static whole-option pattern has the same key-bound string contract');
foreach (['feature', 'spec', 'site', 'container-feature', 'native-container', 'untyped', 'meta', 'pattern-meta', 'name-ref', 'dynamic', 'subkey', 'runtime'] as $fault) {
    $bad = $manifest;
    if ($fault === 'feature') $bad['engine_features'] = [PhpContainerValue::FEATURE, 'spec-window/v1'];
    if ($fault === 'container-feature') $bad['engine_features'] = [KeyBoundStrings::FEATURE, 'spec-window/v1'];
    if ($fault === 'spec') $bad['spec_version'] = 2;
    if ($fault === 'native-container') unset($bad['options']['fixture_links']['php_containers']);
    if ($fault === 'untyped') unset($bad['options']['fixture_links']['key_refs']['container']);
    if ($fault === 'meta') $bad['post_meta'] = ['fixture' => $rule];
    if ($fault === 'pattern-meta') $bad['post_meta_patterns'] = [$rule + ['match' => '^fixture$']];
    if ($fault === 'name-ref') $bad['option_name_refs'] = [$rule + ['match' => '^fixture_([0-9]+)$', 'kind' => 'post']];
    if ($fault === 'dynamic') $bad['dynamic_options'] = ['fixture' => $rule];
    if ($fault === 'subkey') $bad['options']['fixture_links'] = ['class' => 'authored', 'sub_keys' => ['inner' => $rule]];
    if ($fault === 'runtime') $bad['options']['fixture_links']['class'] = 'runtime';
    wprism_check_throws(static fn() => $validate($bad, $fault !== 'site'), RuntimeException::class,
        "key-bound string declarations reject ineligible $fault authority");
}
$invalid = [
    'empty' => [], 'too-many' => array_fill(0, KeyBoundStrings::MAX_RULES + 1, $keyRule['bound_strings'][0]),
    'open' => [['path' => '$.items.selector', 'prefix' => '#', 'suffix' => '#', 'regex' => true]],
    'bare-path' => [['path' => '$', 'prefix' => '#', 'suffix' => '#']],
    'untrimmed-path' => [['path' => ' $.items.selector', 'prefix' => '#', 'suffix' => '#']],
    'duplicate' => [$keyRule['bound_strings'][0], $keyRule['bound_strings'][0]],
    'recursive-overlap' => [$keyRule['bound_strings'][0], ['path' => '$..selector', 'prefix' => '#', 'suffix' => '#']],
    'wildcard-overlap' => [$keyRule['bound_strings'][0], ['path' => '$.items.*', 'prefix' => '#', 'suffix' => '#']],
];
foreach (['', str_repeat('x', 257), "line\n", "\x00", "\xff", '{{', '}}'] as $literal) {
    foreach (['prefix', 'suffix'] as $part) {
        $binding = $keyRule['bound_strings'][0];
        $binding[$part] = $literal;
        $invalid[$part . '-' . bin2hex($literal)] = [$binding];
    }
}
foreach ($invalid as $fault => $bindings) {
    $bad = $manifest;
    $bad['options']['fixture_links']['key_refs']['bound_strings'] = $bindings;
    wprism_check_throws(static fn() => $validate($bad), RuntimeException::class, "closed string grammar rejects $fault");
}
foreach (['scalar', 'ancestor', 'recursive', 'discriminator'] as $fault) {
    $bad = $manifest;
    $ref = ['path' => '$.root.items.entries.items.*.items.selector', 'kind' => 'post'];
    if ($fault === 'ancestor') $ref['path'] = '$.root.items.entries.items.*.items';
    if ($fault === 'recursive') $ref['path'] = '$..selector';
    if ($fault === 'discriminator') {
        $bad['engine_features'][] = 'conditional-json-refs/v1';
        $ref['path'] = '$.root.items.entries.items.*.items.related';
        $ref['when'] = ['key' => 'selector', 'equals' => 'post', 'otherwise' => ['other']];
    }
    $bad['options']['fixture_links']['json_refs'] = [$ref];
    wprism_check_throws(static fn() => $validate($bad), RuntimeException::class, "bound strings reject $fault structural ownership overlap");
}

$native = static function (string $home, int $offset): array {
    $a = 13 + $offset; $b = 23 + $offset;
    return ['entries' => [$b => (object) ['selector' => "body#$b# .b", 'href' => "$home/?p=$b&view=full", 'related' => $a],
        $a => (object) ['selector' => "body#$a# .a, body#$a# .tail", 'href' => "$home/?p=$a&view=full", 'related' => $b]]];
};
$database = static function (int $offset) use ($uuid13, $uuid23): FakeWpdb {
    return FakeWpdb::install()->seedTable('wp_wprism_map', [
        ['uuid' => $uuid13, 'entity_type' => 'post:page', 'id_kind' => 'post', 'local_id' => 13 + $offset],
        ['uuid' => $uuid23, 'entity_type' => 'post:page', 'id_kind' => 'post', 'local_id' => 23 + $offset],
    ]);
};
$sourceDb = $database(0);
WpStore::reset()->seedOptions(['home' => 'https://source.test']);
$tokens = new Tokens('https://source.test', 'https://source.test/wp-content/uploads');
$packed = PhpContainerValue::capture_raw(serialize($native('https://source.test', 0)), 'fixture');
$canonical = $tokens->struct_capture($packed, $jsonRefs, $keyRule);
wprism_check_same([$token23, $token13], array_column($canonical['root']['items']['entries']['order'], 'key'),
    'capture preserves native map order while binding its strings to the same tokens');
wprism_check_same('body#' . $token13 . '# .a, body#' . $token13 . '# .tail',
    $canonical['root']['items']['entries']['items'][$token13]['items']['selector'], 'every repeated frame receives its owning token');
wprism_check_same('{{home}}/?p=' . $token13 . '&view=full',
    $canonical['root']['items']['entries']['items'][$token13]['items']['href'], 'declared URL frames compose with home tokenization');
wprism_check_same([], StructuredReferenceScanner::scan($canonical, 'fixture', 'option', $jsonRefs, $keyRule, static fn(): ?array => null),
    'reference scanning accepts consistently bound identities');
$targetDb = $database(800);
$targetHome = 'https://longer-target.example.test/subdirectory';
$targetTokens = new Tokens($targetHome, $targetHome . '/wp-content/uploads');
$applied = $targetTokens->struct_apply($canonical, $jsonRefs, $keyRule);
wprism_check_same(serialize($native($targetHome, 800)), serialize(PhpContainerValue::restore($applied, 'target')),
    'Tokens restores divergent keys, repeated strings, scalar references and query URLs without consuming a frame prematurely');
wprism_check_same(Canon::encode($canonical), Canon::encode($targetTokens->struct_capture($applied, $jsonRefs, $keyRule)),
    'native target recapture is exactly canonical despite changed IDs and home length');

$site = FrozenPolicy::site([$manifest], WPRISM_SPEC_VERSION);
$site['policy']['post_types'] = ['page']; $site['policy']['taxonomies'] = [];
$policy = FrozenPolicy::policy([$manifest], $site);
$scratch = sys_get_temp_dir() . '/wprism-key-bound-' . bin2hex(random_bytes(8));
mkdir($scratch . '/state/options', 0700, true); mkdir($scratch . '/state/posts/page', 0700, true);
$remove = static function (string $path) use (&$remove): void {
    if (!is_dir($path) || is_link($path)) { if (file_exists($path) || is_link($path)) unlink($path); return; }
    foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $entry) $remove($entry->getPathname());
    rmdir($path);
};
register_shutdown_function(static fn() => $remove($scratch));
Canon::write_file($scratch . '/site.wprism.json', Canon::encode($site));
foreach ([$uuid13, $uuid23] as $uuid) {
    $front = ['author' => 'user:admin', 'comment_status' => 'closed', 'date' => '2026-09-08 00:00:00', 'date_gmt' => '2026-09-08 00:00:00',
        'excerpt' => '', 'menu_order' => 0, 'meta' => (object) [], 'modified' => '2026-09-08 00:00:00', 'modified_gmt' => '2026-09-08 00:00:00',
        'parent' => null, 'ping_status' => 'closed', 'slug' => $uuid, 'status' => 'publish', 'terms' => (object) [], 'title' => 'Page', 'type' => 'page', 'uuid' => $uuid];
    Canon::write_file($scratch . '/state/posts/page/' . $uuid . '--' . $uuid . '.md', Canon::post_file($front, 'Page'));
}
$compile = static function (array $value) use ($scratch, $policy) {
    Canon::write_file($scratch . '/state/options/core.json', Canon::encode(OptionState::document(['fixture_links' => OptionState::present($value, 'off')])));
    return RepositoryCompiler::compile($scratch, $policy);
};
$before = $targetDb->queries();
$compiled = $compile($canonical);
wprism_check_same($before, $targetDb->queries(), 'valid bound-string compilation uses no database or identity lookup');
wprism_check_same(Canon::encode($canonical), Canon::encode(OptionState::values($compiled->tree()['options/core']['data'])['fixture_links']),
    'immutable compiled state retains the exact frame and map identity');
foreach (['raw', 'other-owner', 'other-kind', 'missing-frame', 'partial-frame', 'mixed-frames', 'non-string'] as $fault) {
    $bad = $canonical;
    $value = match ($fault) {
        'raw' => 'body#13# .stale', 'other-owner' => 'body#' . $token23 . '#',
        'other-kind' => 'body#{{term:' . $uuid13 . '}}#', 'missing-frame' => '.nothing',
        'partial-frame' => 'body#' . $token13, 'mixed-frames' => 'body#' . $token13 . '#,body#' . $token23 . '#',
        'non-string' => null,
    };
    $bad['root']['items']['entries']['items'][$token13]['items']['selector'] = $value;
    $before = $targetDb->queries();
    wprism_check_throws(static fn() => $compile($bad), RuntimeException::class, "immutable compiler refuses $fault key/frame disagreement");
    wprism_check_same($before, $targetDb->queries(), 'malformed bound-string compilation performs no database query');
    wprism_check_throws(static fn() => StructuredReferenceScanner::scan($bad, 'fixture', 'option', [], $keyRule, static fn(): ?array => null),
        RuntimeException::class, "reference scanner refuses $fault key/frame disagreement");
    wprism_check_throws(static fn() => $targetTokens->struct_apply($bad, $jsonRefs, $keyRule),
        RuntimeException::class, "apply refuses $fault before publishing a rewritten container");
}
$lookupCalls = 0; $warnings = [];
$lookup = static function (int $id) use (&$lookupCalls, $token13, $token23): ?string { ++$lookupCalls; return $id === 13 ? $token13 : $token23; };
$warn = static function (string $value) use (&$warnings): void { $warnings[] = $value; };
foreach (['body#130#', 'body#13', 'body#13#,body#23#', 'body#013#', '', null, false, ['body#13#']] as $value) {
    $raw = $native('https://source.test', 0); $raw['entries'][13]->selector = $value;
    $bad = PhpContainerValue::capture_raw(serialize($raw), 'fixture');
    $lookupCalls = 0;
    wprism_check_throws(static fn() => StructuredReferenceCodec::capture($bad, [], $keyRule, $lookup, $warn), RuntimeException::class,
        'native contradictory frames refuse instead of being silently rebound or dropped');
    wprism_check_same(0, $lookupCalls, 'every bound entry is validated before key lookup');
}
$before = $packed;
wprism_check_throws(static fn() => StructuredReferenceCodec::capture($packed, [], $keyRule, static fn(): string => $token13, $warn),
    RuntimeException::class, 'colliding owning identities refuse atomically', 'collided');
wprism_check_same($before, $packed, 'failed key/entry rewriting leaves the caller value unchanged');
$dropped = StructuredReferenceCodec::capture($packed, [], $keyRule, static fn(int $id): ?string => $id === 13 ? $token13 : null, $warn);
wprism_check_same([['key' => $token13]], $dropped['root']['items']['entries']['order'], 'dangling ownership removes its order entry');
wprism_check_same([$token13], array_keys($dropped['root']['items']['entries']['items']), 'dangling ownership removes every bound string with its value');
wprism_check_same(1, count($warnings), 'valid dangling ownership retains the existing one-warning contract');
$empty = PhpContainerValue::capture_raw(serialize(['entries' => [13 => new stdClass()]]), 'fixture');
$emptyPortable = StructuredReferenceCodec::capture($empty, [], $keyRule, $lookup, $warn);
wprism_check_same($token13, $emptyPortable['root']['items']['entries']['order'][0]['key'], 'absent optional string paths do not prevent key transport');
$suffixRule = $keyRule; $suffixRule['bound_strings'] = [['path' => '$.items.selector', 'prefix' => 'owner=', 'suffix' => '-']];
$withSuffix = PhpContainerValue::capture_raw(serialize(['entries' => [13 => (object) ['selector' => 'owner=13-tail']]]), 'fixture');
$withSuffix = StructuredReferenceCodec::capture($withSuffix, [], $suffixRule, $lookup, $warn);
$withSuffix = StructuredReferenceCodec::apply($withSuffix, [], $suffixRule, static fn(): int => 813);
wprism_check_same('owner=813-tail', PhpContainerValue::restore($withSuffix, 'fixture')['entries'][813]->selector,
    'a frame suffix occurring inside the token UUID cannot truncate the identity');
foreach (['input', 'growth', 'occurrences'] as $fault) {
    $string = $fault === 'input' ? str_repeat('x', KeyBoundStrings::MAX_STRING_BYTES) . 'body#13#'
        : ($fault === 'growth' ? str_repeat('body#13#', 25000) : str_repeat('body#13#', 50001));
    $raw = ['entries' => [13 => (object) ['selector' => $fault === 'occurrences' ? [(object) ['selector' => $string], (object) ['selector' => $string]] : $string]]];
    $boundedRule = $keyRule; $boundedRule['bound_strings'] = [$keyRule['bound_strings'][0]];
    if ($fault === 'occurrences') $boundedRule['bound_strings'][0]['path'] = '$.items.selector.items.items.selector';
    $bad = PhpContainerValue::capture_raw(serialize($raw), 'bounded fixture');
    wprism_check_throws(static fn() => StructuredReferenceCodec::capture($bad, [], $boundedRule, $lookup, $warn), RuntimeException::class,
        "bound-string $fault limits refuse through capture");
}
$objectEntries = new stdClass();
$objectEntries->{'13'} = (object) ['selector' => 'body#13#'];
$objectPacked = PhpContainerValue::capture_raw(serialize(['entries' => $objectEntries]), 'object fixture');
$objectPortable = StructuredReferenceCodec::capture($objectPacked, [], $keyRule, $lookup, $warn);
$objectApplied = StructuredReferenceCodec::apply($objectPortable, [], $keyRule, static fn(): int => 813);
$objectNative = PhpContainerValue::restore($objectApplied, 'object fixture');
wprism_check($objectNative['entries'] instanceof stdClass && $objectNative['entries']->{'813'}->selector === 'body#813#',
    'bound strings preserve an ID-keyed stdClass and string property identity');
foreach (['term', 'tt', 'fixture_item'] as $kind) {
    $kindRule = $keyRule; $kindRule['kind'] = $kind;
    $withKind = StructuredReferenceCodec::capture($objectPacked, [], $kindRule, static fn(): string => '{{' . $kind . ':' . $uuid13 . '}}', $warn);
    $withKind = StructuredReferenceCodec::apply($withKind, [], $kindRule, static fn(): int => 913);
    wprism_check_same('body#913#', PhpContainerValue::restore($withKind, 'kind fixture')['entries']->{'913'}->selector,
        "bound strings use the declared $kind keyspace without post-specific rules");
}
wprism_check_throws(static fn() => StructuredReferenceCodec::capture($objectPacked, [], $keyRule,
    static fn(): string => '{{term:' . $uuid13 . '}}', $warn), RuntimeException::class,
    'capture refuses a lookup result in another keyspace');
foreach ([true, false] as $staticOwner) {
    $declared = $manifest; $declared['interpreter'] = 'key-bound-fixture';
    if (!$staticOwner) unset($declared['options']['fixture_links']);
    $loaded = FrozenPolicy::policy([$declared], FrozenPolicy::site([$declared], WPRISM_SPEC_VERSION));
    $answer = ['class' => 'authored', 'key_refs' => ['kind' => 'post', 'bound_strings' => $keyRule['bound_strings']]];
    $interpreter = new class($answer) {
        public function __construct(private array $rule) {}
        public function option_rule(string $key, array $all): ?array { return $key === 'fixture_links' ? $this->rule : null; }
    };
    (new ReflectionProperty(\WPrism\Policy::class, 'interpreterInstances'))->setValue($loaded, ['key-bound-fixture' => $interpreter]);
    wprism_check_throws(static fn() => $loaded->meta_rule_for_option('fixture_links', []), RuntimeException::class,
        'an interpreter cannot introduce bound-string authority even without parent container flags', 'static PHP container');
}
if (wprism_check_failed() > 0) exit(1);
