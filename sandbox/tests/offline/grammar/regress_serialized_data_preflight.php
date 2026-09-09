<?php
declare(strict_types=1);
$root = dirname(__DIR__, 4);
require_once $root . '/sandbox/tests/lib/check.php';
require_once $root . '/sandbox/tests/lib/wp_stubs.php';
require_once $root . '/sandbox/tests/lib/agent_version.php';
require_once $root . '/sandbox/tests/lib/frozen_policy.php';
require_once $root . '/sandbox/tests/lib/FakeWpdb.php';
require_once $root . '/agent/src/Capture/OptionsCapture.php';
wprism_test_define_agent_versions();
$manifest = ['name' => 'enum-fixture','spec_version' => 3,'option_autoload' => 'preserve',
    'options' => ['fixture_blob' => ['class' => 'authored','plain_data' => true]]];
$policy = WPrismTest\FrozenPolicy::policy([$manifest], WPrismTest\FrozenPolicy::site([$manifest], WPRISM_SPEC_VERSION));
$autoloads = [];
$autoload = static function (string $name) use (&$autoloads): void { if (str_starts_with($name, 'PortableEnumProbe'))$autoloads[] = $name; };
spl_autoload_register($autoload);
$payload = 'PortableEnumProbeMissing:Case';
$raw = 'a:1:{s:5:"value";E:'.strlen($payload).':"'.$payload.'";}';
$wpdb = WPrismTest\FakeWpdb::install()->seedTable('wp_options', [
    ['option_id' => 1,'option_name' => 'fixture_blob','option_value' => $raw,'autoload' => 'no']
]);
$before = $wpdb->tables;
$capture = new WPrism\OptionsCapture($policy, new WPrism\Tokens('https://source.test', 'https://source.test/wp-content/uploads'),
    static fn(): null => null, static fn(): null => null, static fn(): bool => false);
try {$capture->capture(false);
throw new RuntimeException('Missing malformed serialized enum refusal');} catch (RuntimeException $failure){echo 'capture refused: ', $failure->getMessage(),"\n";}
spl_autoload_unregister($autoload);
wprism_check_same([], $autoloads, 'real authored-option capture refuses enum before any autoload');
wprism_check_same($before, $wpdb->tables, 'refused enum capture preserves raw database state');


require_once $root . '/agent/src/Delete/DeleteGuardValueCodec.php';
require_once $root . '/agent/src/Delete/ExecutableOwnerBoundary.php';
require_once $root . '/agent/src/Review/Pending.php';
require_once $root . '/agent/src/Review/SerializedTermDescriptionScanner.php';
require_once $root . '/agent/src/Init/InitSiteProbe.php';
require_once $root . '/agent/src/Rebuild/NativeActions.php';

use WPrism\PlainData;
use WPrism\SerializedDataPreflight;

// The marker bytes are ordinary string data when their length framing owns
// them. A substring blacklist would refuse valid source text and option keys.
$literal = 'E:28:"PortableEnumProbeMissing:Case";';
$values = [null, false, true, 0, -17, 1.25, INF, -INF, NAN, '', 'বাংলা', $literal,
    ['string' => $literal, 'array' => [null, false, true, -2, 9.4]],
    [$literal => 'literal enum-shaped key'], str_repeat($literal, 10000),
];
foreach ($values as $i => $value) {
    $wire = serialize($value);
    wprism_check_same($wire, serialize(SerializedDataPreflight::decode($wire, 'plain fixture')),
        "ordinary serialized value $i retains its native meaning");
    wprism_check_same($wire, serialize(PlainData::decode($wire, 'plain fixture')),
        "strict plain-data value $i retains its native meaning");
}
$nativeObject = (object) ['text' => $literal, 'nested' => (object) [], 'sequence' => []];
wprism_check_same(serialize($nativeObject), serialize(SerializedDataPreflight::decode(serialize($nativeObject), 'plain object', true)),
    'the explicit stdClass-only primitive retains nested object and array distinctions');
wprism_check_throws(static fn() => PlainData::decode(serialize($nativeObject), 'ordinary option'), RuntimeException::class,
    'existing plain-data callers still refuse stdClass without opting in', 'PHP object');

$autoloads = [];
spl_autoload_register($autoload);
$enum = 'E:28:"PortableEnumProbeMissing:Case";';
foreach ([$enum, 'a:1:{s:5:"value";' . $enum . '}',
    'O:8:"stdClass":1:{s:5:"value";' . $enum . '}',
    'a:2:{i:0;s:' . strlen($literal) . ':"' . $literal . '";i:1;' . $enum . '}',
] as $i => $wire) {
    foreach ([false, true] as $objects) {
        wprism_check_throws(static fn() => SerializedDataPreflight::decode($wire, 'enum fixture', $objects), RuntimeException::class,
            "enum record $i refuses before PHP decoding with stdClass=" . (int) $objects, 'PHP enum');
    }
}
wprism_check_same([], $autoloads, 'neither object mode autoloads nested or root enum records');

$advisoryReaders = [
    'pending review' => static fn() => WPrism\Pending::current_value('options', 'fixture_blob'),
    'term-description lint' => static fn() => WPrism\SerializedTermDescriptionScanner::scan($raw, 'fixture', 'fixture.md', static fn(): null => null),
    'initial metadata risk reader' => static fn() => (new ReflectionMethod(WPrism\InitSiteProbe::class, 'safeRiskValue'))->invoke(null, $raw),
    'locked executable owners' => static fn() => (new ReflectionMethod(WPrism\ExecutableOwnerBoundary::class, 'decode_array'))->invoke(null, $raw, 'active_plugins'),
];
foreach ($advisoryReaders as $name => $reader) {
    wprism_check_throws($reader, RuntimeException::class, "$name refuses unsupported enum data", 'PHP enum');
}
wprism_check_same(null, WPrism\DeleteGuardValueCodec::meta_value_ids($raw, ['ref' => 'post[]']),
    'deletion guards keep their unknown/unsafe result instead of guessing no references');
$GLOBALS['wp_rewrite'] = (object) ['permalink_structure' => ''];
$wpdb->seedTable('wp_options', [['option_id' => 1, 'option_name' => 'rewrite_rules', 'option_value' => $raw, 'autoload' => 'no']]);
wprism_check_throws(static fn() => WPrism\NativeActions::rewrite_evidence(), RuntimeException::class,
    'the real native rewrite observation also refuses enum data before PHP decoding', 'PHP enum');
wprism_check_same([], $autoloads, 'every engine serialization entry point leaves autoload untouched');
spl_autoload_unregister($autoload);

// Class filtering still owns O/C records. Enum preflight is additive: it
// must not activate ordinary object hooks or admit them into plain data.
final class PortableEnumProbeWakeup {
    public static int $calls = 0;
    public function __wakeup(): void { ++self::$calls; }
}
$objectWire = serialize(new PortableEnumProbeWakeup());
wprism_check_throws(static fn() => PlainData::decode($objectWire, 'object fixture'), RuntimeException::class,
    'ordinary executable objects still refuse', 'PHP object');
wprism_check_same(0, PortableEnumProbeWakeup::$calls, 'ordinary object wakeup never executes');

foreach ([$enum . 'trailing', 'a:1:{s:5:"value";' . $enum, 's:99999999999999999:"' . $literal . '";',
    'a:99999999999999999:{' . $literal . '}', 's:1:"' . $literal . '";',
] as $wire) {
    wprism_check_throws(static fn() => SerializedDataPreflight::decode($wire, 'malformed enum framing'), RuntimeException::class,
        'malformed enum-bearing framing refuses without unsafe parser entry');
}
$deep = $literal;
for ($i = 0; $i < 258; ++$i) $deep = [$deep];
wprism_check_throws(static fn() => PlainData::decode(serialize($deep), 'deep fixture'), RuntimeException::class,
    'enum-like strings cannot bypass the existing bounded nesting contract', 'nested too deeply');

wprism_check_summary('serialized data preflight and product readers');
