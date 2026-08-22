<?php
/**
 * Offline regression for MenuMaterializer (DUO-3347 slice 4: the menu entity
 * materializer extracted from Apply.php). Deliberately narrow: the full
 * behavioral proof of finalize_menu()/assign_locations() -- exact serialized
 * merge semantics, scalar/object rejection, menu-item field/meta
 * reconciliation -- already lives in
 * sandbox/tests/offline/code-half/regress_lifecycle_options_snapshot.php, a Reflection-based
 * test against Apply's own facade with a complete $wpdb stub (confirmed
 * still green, unchanged, through this extraction), and in this project's
 * live conformance sweeps (every fixture with a menu exercises
 * finalize_menu() end to end through a real WordPress + MySQL target). This
 * file does not re-implement or re-assert that behavior -- doing so from a
 * hand-copied twin of the merge logic would only add a second copy that
 * could silently drift from the real one, exactly the anti-pattern
 * regress_conflict_view.php was fixed to stop doing earlier in this same
 * decomposition effort. It proves the one thing genuinely new here instead:
 * MenuMaterializer is a real, directly constructible, standalone public API.
 */
declare(strict_types=1);

/**
 * Each extracted materializer must be loadable without relying on duo.php's
 * bootstrap order.  Run the probes in fresh PHP processes so the classes
 * loaded by this test's own fixture setup cannot mask a missing require_once.
 */
$standaloneProbes = [
    [
        'label' => 'ApplyFieldMaterializer self-requires Policy and Tokens',
        'file' => __DIR__ . '/../../../../agent/src/Apply/ApplyFieldMaterializer.php',
        'classes' => ['Duo\\Policy', 'Duo\\Tokens'],
    ],
    [
        'label' => 'MenuMaterializer self-requires its constructor dependencies',
        'file' => __DIR__ . '/../../../../agent/src/Apply/MenuMaterializer.php',
        'classes' => ['Duo\\Policy', 'Duo\\Tokens', 'Duo\\ApplyFieldMaterializer'],
    ],
];
$standaloneFailures = [];
$standaloneResults = [];
foreach ($standaloneProbes as $probe) {
    $classLiterals = implode(', ', array_map(
        static fn(string $class): string => var_export($class, true),
        $probe['classes']
    ));
    $code = 'require_once ' . var_export($probe['file'], true) . ';'
        . 'foreach ([' . $classLiterals . '] as $class) {'
        . ' if (!class_exists($class, false)) { fwrite(STDERR, "missing:" . $class . "\\n"); exit(1); }'
        . '}';
    $pipes = [];
    $process = proc_open([PHP_BINARY, '-d', 'display_errors=1', '-r', $code], [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);
    if (!is_resource($process)) {
        $standaloneResults[$probe['label']] = false;
        $standaloneFailures[] = $probe['label'] . ' (could not start PHP subprocess)';
        continue;
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    $standaloneResults[$probe['label']] = $exitCode === 0;
    if ($exitCode !== 0) {
        $standaloneFailures[] = $probe['label'] . ' (exit ' . $exitCode . ': ' . trim($stderr . $stdout) . ')';
    }
}

$standaloneCheck = static function (bool $ok, string $message) use (&$standaloneFailures): void {
    echo ($ok ? 'ok: ' : 'FAIL: ') . $message . "\n";
    if (!$ok) {
        $standaloneFailures[] = $message;
    }
};
foreach ($standaloneProbes as $probe) {
    $standaloneCheck(
        $standaloneResults[$probe['label']] ?? false,
        $probe['label']
    );
}
if ($standaloneFailures) {
    echo "\n" . count($standaloneFailures) . " standalone-load failure(s):\n";
    foreach ($standaloneFailures as $failure) {
        echo "  - $failure\n";
    }
    exit(1);
}

require_once __DIR__ . '/../../../../agent/src/Kernel/Canon.php';
require_once __DIR__ . '/../../../../agent/src/Kernel/OptionState.php';
require_once __DIR__ . '/../../../../agent/src/Policy/Policy.php';
require_once __DIR__ . '/../../../../agent/src/Repository/Ledger.php';
require_once __DIR__ . '/../../../../agent/src/Grammar/Tokens.php';
require_once __DIR__ . '/../../../../agent/src/Apply/ApplyFieldMaterializer.php';
require_once __DIR__ . '/../../../../agent/src/Apply/MenuMaterializer.php';

use Duo\ApplyFieldMaterializer;
use Duo\MenuMaterializer;
use Duo\Policy;
use Duo\Tokens;

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    echo ($ok ? 'ok: ' : 'FAIL: ') . $message . "\n";
    if (!$ok) {
        $failures[] = $message;
    }
};

// newInstanceWithoutConstructor(): this is a wiring/shape test, not a
// behavioral one (see the file docblock) -- a real Tokens needs a live
// WordPress runtime (untrailingslashit(), site options) to construct, which
// this offline suite deliberately does not stand up.
$policy = (new ReflectionClass(Policy::class))->newInstanceWithoutConstructor();
$tokens = (new ReflectionClass(Tokens::class))->newInstanceWithoutConstructor();
$fieldMaterializer = new ApplyFieldMaterializer($policy, $tokens);
$menuMaterializer = new MenuMaterializer($policy, $tokens, $fieldMaterializer);

$check($menuMaterializer instanceof MenuMaterializer, 'MenuMaterializer is directly constructible with (Policy, Tokens, ApplyFieldMaterializer)');

$finalizeMenu = new ReflectionMethod(MenuMaterializer::class, 'finalize_menu');
$assignLocations = new ReflectionMethod(MenuMaterializer::class, 'assign_locations');
$check($finalizeMenu->isPublic(), 'finalize_menu() is public on MenuMaterializer (was private on Apply)');
$check($assignLocations->isPublic(), 'assign_locations() is public on MenuMaterializer (was private on Apply)');
$check(
    array_map(static fn(ReflectionParameter $p): string => $p->getName(), $assignLocations->getParameters()) === ['menuTermId', 'locations'],
    'assign_locations() keeps its exact original parameter names and order'
);
$check(
    array_map(static fn(ReflectionParameter $p): string => $p->getName(), $finalizeMenu->getParameters()) === ['front'],
    'finalize_menu() keeps its exact original parameter name'
);

// The constructor takes exactly these three collaborators, in this order --
// a narrow, explicit data contract rather than a whole Apply instance.
$constructorParams = (new ReflectionClass(MenuMaterializer::class))->getConstructor()->getParameters();
$check(
    array_map(static fn(ReflectionParameter $p): string => (string) $p->getType(), $constructorParams)
        === ['Duo\\Policy', 'Duo\\Tokens', 'Duo\\ApplyFieldMaterializer'],
    'constructor depends on exactly Policy, Tokens, and ApplyFieldMaterializer -- no Apply instance'
);

// Core dirty-target conformance found that an explicitly adopted menu kept a
// target-only custom item because the item had no _duo_uuid and the cleanup
// projection indexed UUID-bearing rows only. Exercise the real private
// projection used by finalize_menu(): every physical id is retained, while
// only UUID-bearing rows enter the canonical lookup.
$indexEnvironmentItems = new ReflectionMethod(MenuMaterializer::class, 'index_environment_items');
$obsoleteEnvironmentItems = new ReflectionMethod(MenuMaterializer::class, 'obsolete_environment_items');
$environment = $indexEnvironmentItems->invoke($menuMaterializer, [
    ['ID' => '11', 'uuid' => '11111111-1111-4111-8111-111111111111'],
    ['ID' => '12', 'uuid' => '22222222-2222-4222-8222-222222222222'],
    ['ID' => '13', 'uuid' => null],
    ['ID' => '14', 'uuid' => ''],
], 'main');
$check(
    $environment === [
        'by_uuid' => [
            '11111111-1111-4111-8111-111111111111' => 11,
            '22222222-2222-4222-8222-222222222222' => 12,
        ],
        'by_id' => [11 => '11111111-1111-4111-8111-111111111111', 12 => '22222222-2222-4222-8222-222222222222', 13 => null, 14 => null],
    ],
    'menu observation retains sidecarless target items by physical id'
);
$obsolete = $obsoleteEnvironmentItems->invoke($menuMaterializer, $environment['by_id'], [
    '11111111-1111-4111-8111-111111111111' => 11,
]);
$check(
    $obsolete === [12 => '22222222-2222-4222-8222-222222222222', 13 => null, 14 => null],
    'menu-scoped cleanup removes stale canonical and sidecarless target items while keeping the desired item'
);

foreach ([
    'one identity on multiple target items' => [
        ['ID' => '21', 'uuid' => '33333333-3333-4333-8333-333333333333'],
        ['ID' => '22', 'uuid' => '33333333-3333-4333-8333-333333333333'],
    ],
    'contradictory identities on one target item' => [
        ['ID' => '23', 'uuid' => '44444444-4444-4444-8444-444444444444'],
        ['ID' => '23', 'uuid' => '55555555-5555-4555-8555-555555555555'],
    ],
] as $label => $rows) {
    try {
        $indexEnvironmentItems->invoke($menuMaterializer, $rows, 'main');
        $check(false, "$label refuses before choosing an owner");
    } catch (RuntimeException $failure) {
        $check(
            str_contains($failure->getMessage(), 'duo: menu main:'),
            "$label refuses before choosing an owner"
        );
    }
}

if ($failures) {
    echo "\n" . count($failures) . " failure(s):\n";
    foreach ($failures as $f) {
        echo "  - $f\n";
    }
    exit(1);
}
echo "\nall MenuMaterializer checks passed\n";
exit(0);
