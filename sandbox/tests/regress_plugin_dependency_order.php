<?php
/** Offline proof that retirement follows Requires Plugins, not list order. */

$root = dirname(__DIR__, 2);
require_once $root . '/agent/src/Deploy.php';

use Duo\Deploy;

$check = static function (bool $ok, string $message): void {
    if (!$ok) {
        throw new RuntimeException('FAIL: ' . $message);
    }
};
$throws = static function (callable $operation, string $needle, string $message) use ($check): void {
    try {
        $operation();
    } catch (RuntimeException $e) {
        $check(str_contains($e->getMessage(), $needle), $message . ': ' . $e->getMessage());
        return;
    }
    throw new RuntimeException('FAIL: ' . $message . ': operation unexpectedly succeeded');
};

$order = new ReflectionMethod(Deploy::class, 'order_deactivations');
$order->setAccessible(true);

// Deliberately put the provider after its dependent in active_plugins. A
// simple array_reverse() would retire the provider first; the graph must win.
$plugins = [
    'dependent/dependent.php',
    'independent/independent.php',
    'provider/provider.php',
];
$ordered = $order->invoke(null, $plugins, [
    'dependent/dependent.php' => ['provider/provider.php'],
    'independent/independent.php' => [],
    'provider/provider.php' => [],
]);
$check(
    array_search('dependent/dependent.php', $ordered, true)
        < array_search('provider/provider.php', $ordered, true),
    'dependent was not retired before its provider'
);
$check(
    $ordered === [
        'independent/independent.php',
        'dependent/dependent.php',
        'provider/provider.php',
    ],
    'independent tie-breaking did not remain reverse active order'
);

$chain = $order->invoke(null, ['leaf/leaf.php', 'root/root.php', 'middle/middle.php'], [
    'leaf/leaf.php' => ['middle/middle.php'],
    'middle/middle.php' => ['root/root.php'],
    'root/root.php' => [],
]);
$check(
    $chain === ['leaf/leaf.php', 'middle/middle.php', 'root/root.php'],
    'transitive dependency teardown was not topological'
);

$throws(
    static fn() => $order->invoke(null, ['a/a.php', 'b/b.php'], [
        'a/a.php' => ['b/b.php'],
        'b/b.php' => ['a/a.php'],
    ]),
    'dependency cycle prevents safe teardown',
    'dependency cycle did not fail closed'
);
$throws(
    static fn() => $order->invoke(null, ['self/self.php'], [
        'self/self.php' => ['self/self.php'],
    ]),
    'dependency cycle prevents safe teardown',
    'self-dependency did not fail closed'
);

echo "ok: plugin retirement is reverse-topological with stable independent ordering\n";
