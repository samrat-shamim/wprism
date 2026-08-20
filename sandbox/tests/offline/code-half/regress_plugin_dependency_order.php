<?php
/** Offline proof that lifecycle topology follows Requires Plugins, not list order. */

$root = dirname(__DIR__, 4);
require_once $root . '/agent/src/Promotion/Deploy.php';

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

// Exercise the source-header path too. It must share the same slug
// validation as CodeCompatibility: uppercase and slash-containing values
// are ignored, while a lowercase valid token orders the provider first for
// activation and the dependent first for retirement.
$tmp = sys_get_temp_dir() . '/duo-plugin-dependency-' . bin2hex(random_bytes(6));
define('WP_PLUGIN_DIR', $tmp . '/plugins');
mkdir(WP_PLUGIN_DIR . '/dependent', 0777, true);
mkdir(WP_PLUGIN_DIR . '/provider', 0777, true);
file_put_contents(WP_PLUGIN_DIR . '/dependent/dependent.php', "<?php\n");
file_put_contents(WP_PLUGIN_DIR . '/provider/provider.php', "<?php\n");
file_put_contents(WP_PLUGIN_DIR . '/foo.php-bar.php', "<?php\n");
$GLOBALS['duo_dependency_headers'] = [
    WP_PLUGIN_DIR . '/dependent/dependent.php' => ['RequiresPlugins' => ' Provider, not/a-slug '],
    WP_PLUGIN_DIR . '/provider/provider.php' => ['RequiresPlugins' => ''],
    WP_PLUGIN_DIR . '/foo.php-bar.php' => ['RequiresPlugins' => ''],
];
if (!function_exists('get_plugin_data')) {
    function get_plugin_data(string $file, bool $markup = true, bool $translate = true): array {
        return $GLOBALS['duo_dependency_headers'][$file] ?? ['RequiresPlugins' => ''];
    }
}
$retire = new ReflectionMethod(Deploy::class, 'dependency_ordered_deactivations');
$retired = $retire->invoke(null, [
    'dependent/dependent.php',
    'provider/provider.php',
]);
$check(
    $retired === ['provider/provider.php', 'dependent/dependent.php'],
    'uppercase dependency token was not ignored during retirement ordering'
);
$GLOBALS['duo_dependency_headers'][WP_PLUGIN_DIR . '/dependent/dependent.php'] = [
    'RequiresPlugins' => 'provider',
];
$retiredLowercase = $retire->invoke(null, [
    'dependent/dependent.php',
    'provider/provider.php',
]);
$check(
    $retiredLowercase === ['dependent/dependent.php', 'provider/provider.php'],
    'lowercase dependency token did not order the provider after its dependent'
);
$activate = new ReflectionMethod(Deploy::class, 'dependency_ordered_activations');
$GLOBALS['duo_dependency_headers'][WP_PLUGIN_DIR . '/dependent/dependent.php'] = [
    'RequiresPlugins' => 'provider',
];
$nativeDesired = [
    'dependent/dependent.php',
    'provider/provider.php',
];
$activationNative = $activate->invoke(null, $nativeDesired);
$check(
    $activationNative === ['provider/provider.php', 'dependent/dependent.php'],
    'provider was not activated before dependent when desired order was native/alphabetical'
);
$activationAuthored = $activate->invoke(null, [
    'provider/provider.php',
    'dependent/dependent.php',
]);
$check(
    $activationAuthored === ['provider/provider.php', 'dependent/dependent.php'],
    'provider-first activation topology changed for an authored provider-first order'
);
$check(
    $nativeDesired === ['dependent/dependent.php', 'provider/provider.php'],
    'native/alphabetical desired active_plugins order fixture was unexpectedly rewritten'
);
$GLOBALS['duo_dependency_headers'][WP_PLUGIN_DIR . '/dependent/dependent.php'] = [
    'RequiresPlugins' => 'not/a-slug',
];
$retiredInvalid = $retire->invoke(null, [
    'dependent/dependent.php',
    'provider/provider.php',
]);
$check(
    $retiredInvalid === ['provider/provider.php', 'dependent/dependent.php'],
    'invalid slash dependency token was not ignored during retirement ordering'
);
$GLOBALS['duo_dependency_headers'][WP_PLUGIN_DIR . '/dependent/dependent.php'] = [
    'RequiresPlugins' => 'foo-bar',
];
$retiredStrange = $retire->invoke(null, [
    'dependent/dependent.php',
    'foo.php-bar.php',
]);
$check(
    $retiredStrange === ['dependent/dependent.php', 'foo.php-bar.php'],
    'plugin slug mapping did not remove every .php occurrence for retirement ordering'
);
@unlink(WP_PLUGIN_DIR . '/dependent/dependent.php');
@unlink(WP_PLUGIN_DIR . '/provider/provider.php');
@unlink(WP_PLUGIN_DIR . '/foo.php-bar.php');
@rmdir(WP_PLUGIN_DIR . '/dependent');
@rmdir(WP_PLUGIN_DIR . '/provider');
@rmdir(WP_PLUGIN_DIR);
@rmdir($tmp);

echo "ok: plugin activation is provider-first while authored order remains independent; retirement is reverse-topological\n";
