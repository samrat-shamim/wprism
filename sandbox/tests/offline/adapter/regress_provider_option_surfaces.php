<?php
declare(strict_types=1);

// Private option names are ordinary exact WordPress keys. Declaration and
// observation must agree without granting a wildcard or another namespace.
$root = dirname(__DIR__, 4);
$runtime = isset($argv[1]) ? realpath($argv[1]) : $root;
if (!is_string($runtime) || !is_file($runtime . '/agent/src/Adapter/ProviderSurfaces.php')) {
    throw new RuntimeException('fixture requires one complete runtime tree');
}
require_once $root . '/sandbox/tests/lib/check.php';
require_once $root . '/sandbox/tests/lib/wp_stubs.php';
require_once $root . '/sandbox/tests/lib/FakeWpdb.php';
require_once $runtime . '/agent/src/Policy/Policy.php';
require_once $runtime . '/agent/src/Adapter/ActionProviderGrammar.php';
require_once $runtime . '/agent/src/Adapter/Providers.php';
require_once $runtime . '/agent/src/Adapter/ProviderSurfaces.php';

use WPrism\ActionProviderGrammar;
use WPrism\Policy;
use WPrism\Providers;
use WPrism\ProviderSurfaces;
use WPrismTest\FakeWpdb;
use WPrismTest\WpStore;

$contract = ['args' => [], 'idempotent' => true, 'reads' => [],
    'scope' => 'site', 'timeout_seconds' => 30, 'writes' => []];
$action = static fn(string $surface): array => ['name' => 'surface-probe', 'actions' => [[
    'kind' => 'native', 'action' => 'transient.delete', 'args' => ['name' => 'probe'],
    'triggers' => [$surface],
]]];
foreach (['_', '_private', '__private', '_0.a-b', 'a', '0', 'ordinary_key', '_' . str_repeat('x', 127)] as $name) {
    $surface = 'option:' . $name;
    $failure = null;
    try {
        Providers::validate_capability_declaration(array_replace($contract,
            ['reads' => [$surface], 'writes' => [$surface]]), 'private option contract');
        ActionProviderGrammar::validate_actions($action($surface));
    } catch (Throwable $caught) {
        $failure = $caught;
    }
    wprism_check($failure === null, 'native option spelling passes capability and trigger admission: ' . $name);
    wprism_check(ProviderSurfaces::observable($surface), 'the admitted exact option also has an engine reader: ' . $name);
    $match = [];
    wprism_check(preg_match(Policy::grammar_patterns()['action_trigger_surface'], $surface, $match) === 1
        && ($match[1] ?? null) === 'option', 'published grammar preserves the exact option kind capture: ' . $name);
}

$bad = ['option:', 'option:*', 'option:_*', 'option:Upper', 'option:_Upper', 'option:_with space',
    "option:_nul\0", "option:_line\n", 'option:_quote\'', 'option:../x', 'option:_slash/x',
    'option:' . str_repeat('_', 129), 'post:_private', 'term:_private', 'table:_private', 'entity:_private'];
foreach ($bad as $surface) {
    wprism_check_throws(static fn() => Providers::validate_capability_declaration(
        array_replace($contract, ['writes' => [$surface]]), 'private option contract'),
        RuntimeException::class, 'malformed or different-namespace surface remains refused: ' . bin2hex($surface),
        'exact canonical surfaces');
    wprism_check_throws(static fn() => ActionProviderGrammar::validate_actions($action($surface)),
        RuntimeException::class, 'trigger admission refuses the same malformed surface: ' . bin2hex($surface),
        'one exact canonical surface');
    wprism_check(!ProviderSurfaces::observable($surface), 'the reader cannot broaden malformed authority: ' . bin2hex($surface));
}
foreach (['post:page', 'term:category', 'table:options', 'entity:local-cache'] as $surface) {
    Providers::validate_capability_declaration(array_replace($contract, ['reads' => [$surface]]), 'old surface');
    wprism_check(!ProviderSurfaces::observable($surface), 'existing non-option declaration is still not a bounded option extent: ' . $surface);
}

$name = '_private_option';
$surface = 'option:' . $name;
$declaration = array_replace($contract, ['reads' => ['option:_input'], 'writes' => [$surface]]);
$plan = ProviderSurfaces::observation_plan($declaration);
wprism_check_same(['option:_input', $surface], $plan['watched'], 'both private-key extents are independently watched');
wprism_check_same([$surface], $plan['writes'], 'write observation retains the exact private key');
wprism_check_same(['option:_input'], $plan['read_only'], 'read-only observation remains a distinct exact key');
wprism_check_same(true, $plan['writes_fully_observable'], 'a private option is not mislabeled opaque');
wprism_check_same(16, $plan['queries_per_invoke'], 'private option reads retain the ordinary bounded observation cost');

WpStore::reset();
$db = FakeWpdb::install();
$db->setColumns('options', ['option_id', 'option_name', 'option_value', 'autoload']);
$db->setTableEngine('options', 'InnoDB');
$db->seedTable('options', []);
$read = static fn(): array => ProviderSurfaces::observe([$surface], 'surface-probe', 'repair');
$absent = null;
try {
    $absent = $read();
} catch (Throwable $failure) {
    wprism_check(false, 'private option observation unexpectedly refused: ' . $failure->getMessage());
}
if (is_array($absent)) {
    wprism_check_same([$surface => 'absent'], $absent, 'exact native absence is distinct from a content digest');
    $baseline = ['option_id' => 1, 'option_name' => $name, 'option_value' => "private-value\0\xff", 'autoload' => 'off'];
    $db->seedTable('options', [$baseline]);
    $present = $read();
    wprism_check($present !== $absent && !str_contains(json_encode($present, JSON_THROW_ON_ERROR), 'private-value'),
        'binary private option values become only the engine content witness');
    foreach (['option_value' => 'other', 'autoload' => 'on', 'option_name' => '_PRIVATE_OPTION'] as $column => $value) {
        $db->seedTable('options', [array_replace($baseline, [$column => $value])]);
        wprism_check($read() !== $present, 'same-count private option ' . $column . ' drift changes its exact witness');
    }
    $db->seedTable('options', [$baseline, array_replace($baseline, ['option_id' => 2, 'option_name' => '_PRIVATE_OPTION'])]);
    wprism_check_throws($read, RuntimeException::class, 'collation-equal private keys refuse an ambiguous extent', 'could not be checked');
    $db->seedTable('options', [$baseline]);
    $db->failNextQuery('private_driver_value', 'SELECT option_name, SHA2');
    $failure = null;
    try {
        $read();
    } catch (Throwable $caught) {
        $failure = $caught;
    }
    wprism_check($failure instanceof RuntimeException && !str_contains($failure->getMessage(), 'private_driver_value'),
        'failed private-option transport cannot become absence or leak a driver value');
    wprism_check_same([$baseline], $db->rows('options'), 'observation and all refusals preserve the original native row');
}
wprism_check_summary('provider option surfaces');
