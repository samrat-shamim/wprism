<?php
/** Participant-owned Rank Math/Yoast fail-closed composition contract. */
declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/check.php';
require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/wp_stubs.php';
require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/agent_version.php';

$root = dirname(__DIR__, 4);
wprism_test_define_agent_versions();

require_once $root . '/agent/src/Kernel/Canon.php';
require_once $root . '/agent/src/Policy/Policy.php';

use WPrism\AdapterLibrary;
use WPrism\Canon;
use WPrism\Policy;

$scenarioPath = $root . '/integration-scenarios/rank-math-yoast-incompatibility/scenario.json';
$scenarioBytes = (string) file_get_contents($scenarioPath);
$scenario = Canon::decode($scenarioBytes);
wprism_check_same($scenarioBytes, Canon::encode($scenario),
    'the Rank Math/Yoast participant record is canonical');
wprism_check_same(['rank-math', 'yoast'], $scenario['participants'] ?? null,
    'the incompatibility scenario declares both exact adapter owners in sorted order');

$liveRelative = 'integration-scenarios/rank-math-yoast-incompatibility/tests/live/'
    . 'regress_rank_math_yoast_incompatibility.sh';
foreach (['rank-math', 'yoast'] as $participant) {
    $external = Canon::decode(Canon::read_file(
        $root . "/adapter-packages/$participant/evidence/external-tests.json"
    ));
    wprism_check_same(
        $liveRelative,
        $external['tests']['regress-rank-math-yoast-incompatibility'] ?? null,
        "$participant cites the participant-owned live incompatibility gate"
    );
}

$site = sys_get_temp_dir() . '/wprism_rank_yoast_' . bin2hex(random_bytes(8));
if (!mkdir($site, 0700, true) && !is_dir($site)) {
    throw new RuntimeException("could not create scratch site repository '$site'");
}
register_shutdown_function(static function () use ($site): void {
    @unlink($site . '/site.wprism.json');
    @rmdir($site);
});
$library = AdapterLibrary::fromSourceTree($root);
$expected = "wprism: manifest 'rank-math' for plugin 'seo-by-rank-math/rank-math.php' declares plugin "
    . "'wordpress-seo/wp-seo.php' incompatible, and pinned manifest(s) {'yoast'} claim that plugin — "
    . 'incompatible plugin adapters cannot share one policy; pin only one';
foreach ([
    ['core', 'rank-math', 'yoast'],
    ['core', 'yoast', 'rank-math'],
] as $pins) {
    Canon::write_file($site . '/site.wprism.json', Canon::encode([
        'manifests' => array_map(
            static fn(string $name): array => ['name' => $name, 'source' => 'shipped'],
            array_slice($pins, 1)
        ),
        'policy' => new stdClass(),
        'spec_version' => WPRISM_SPEC_VERSION,
    ]));
    wprism_check_throws(
        static fn() => Policy::load($site, $pins, adapterLibrary: $library),
        RuntimeException::class,
        'the participant policy refuses before composition in order ' . implode(',', $pins),
        $expected
    );
}

$live = (string) file_get_contents($root . '/' . $liveRelative);
foreach ([
    'candidate SHA',
    'clean candidate checkout',
    'seo-by-rank-math 1.0.277.2',
    'wordpress-seo 28.3',
    'core,rank-math,yoast',
    'core,yoast,rank-math',
    $expected,
    'capture refused before repository publication',
    'deploy refused before promotion-begin or provider settlement',
    'no lease, apply session, or provider intent survived',
] as $witness) {
    wprism_check(str_contains($live, $witness), "the candidate-bound live refusal pins: $witness");
}

wprism_check_summary('regress_rank_math_yoast_incompatibility');
