<?php
/**
 * Offline regression for DUO-3350 slice 1: PathSafety extracted from Code's
 * path-traversal/symlink-crossing guards (the same guards a materialization
 * bug would let escape WP_CONTENT_DIR, so this suite is deliberately more
 * exhaustive than a typical facade check).
 *
 * Proves: the moved predicates behave exactly as Code's prior inline logic
 * -- including real filesystem symlink detection, not just string shape --
 * every one of Code's ten kept methods delegates rather than reimplements,
 * and safe_component_root()'s new explicit $roots parameter reproduces the
 * old self::ROOTS-implicit behavior byte-for-byte through the facade.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../agent/src/PathSafety.php';
require_once __DIR__ . '/../../agent/src/Code.php';

use Duo\Code;
use Duo\PathSafety;

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    echo ($ok ? 'ok: ' : 'FAIL: ') . $message . "\n";
    if (!$ok) {
        $failures[] = $message;
    }
};

// ---- 1. safe_relative(): traversal, absolute paths, separators, and
// control/null bytes all refuse; ordinary relative segments pass.
$relativeCases = [
    'plugins/foo' => true,
    'plugins/foo/bar.php' => true,
    '' => false,
    '/plugins/foo' => false,
    'a\\b' => false,
    "a\0b" => false,
    "a\tb" => false,
    "a\x7fb" => false,
    'a//b' => false,
    './a' => false,
    '../a' => false,
    'a/../b' => false,
    'a/b/..' => false,
];
foreach ($relativeCases as $path => $want) {
    $check(PathSafety::safe_relative($path) === $want, "safe_relative(" . addcslashes($path, "\0..\x1f\x7f") . ") === " . ($want ? 'true' : 'false'));
}

// ---- 2. safe_component(): a single path segment, no separators.
$check(
    PathSafety::safe_component('twentytwentyone') === true
        && PathSafety::safe_component('@woocommerce') === true
        && PathSafety::safe_component('') === false
        && PathSafety::safe_component('a/b') === false
        && PathSafety::safe_component('..') === false,
    'safe_component() accepts single safe segments, refuses empty/separator/traversal'
);

// ---- 3. safe_component_root(): the new explicit $roots parameter
// reproduces the old self::ROOTS-implicit grammar (exactly two segments,
// first segment in the caller's declared roots, second segment safe).
$roots = ['mu-plugins', 'plugins', 'themes'];
$check(
    PathSafety::safe_component_root('plugins/woocommerce', $roots) === true
        && PathSafety::safe_component_root('themes/twentytwentyone', $roots) === true
        && PathSafety::safe_component_root('uploads/evil', $roots) === false // not a declared root
        && PathSafety::safe_component_root('plugins', $roots) === false // missing component segment
        && PathSafety::safe_component_root('plugins/a/b', $roots) === false // too many segments
        && PathSafety::safe_component_root('plugins/..', $roots) === false, // unsafe component
    'safe_component_root() with an explicit roots list matches the prior self::ROOTS-implicit grammar'
);
$check(
    PathSafety::safe_component_root('plugins/x', ['plugins']) === true
        && PathSafety::safe_component_root('plugins/x', ['themes']) === false,
    'safe_component_root() is driven entirely by its parameter, not a hidden default root list'
);

// ---- 4. plugin_main_candidate(): WordPress get_plugins() shape -- a root
// PHP file or one directory deep under plugins/.
$check(
    PathSafety::plugin_main_candidate('plugins/hello.php') === true
        && PathSafety::plugin_main_candidate('plugins/hello/hello.php') === true
        && PathSafety::plugin_main_candidate('plugins/a/b/hello.php') === false
        && PathSafety::plugin_main_candidate('plugins/hello.txt') === false
        && PathSafety::plugin_main_candidate('themes/hello.php') === false,
    'plugin_main_candidate() matches only root or one-level-deep plugin PHP files'
);

// ---- 5. has_current_path_at_or_below() / owned_path(): membership by
// exact match or path-prefix-with-separator (not a naive substring match).
$current = ['plugins/foo' => true, 'plugins/bar/baz.php' => true];
$check(
    PathSafety::has_current_path_at_or_below('plugins/foo', $current) === true
        && PathSafety::has_current_path_at_or_below('plugins/bar', $current) === true
        && PathSafety::has_current_path_at_or_below('plugins/barbados', $current) === false // prefix, not path-prefix
        && PathSafety::has_current_path_at_or_below('plugins/qux', $current) === false,
    'has_current_path_at_or_below() matches exact or path-below, not a naive string prefix'
);
$owned = ['plugins/foo' => true];
$check(
    PathSafety::owned_path('plugins/foo', $owned) === true
        && PathSafety::owned_path('plugins/foo/bar.php', $owned) === true
        && PathSafety::owned_path('plugins/foobar', $owned) === false
        && PathSafety::owned_path('plugins/other', $owned) === false,
    'owned_path() matches exact or path-below an owned root, not a naive string prefix'
);

// ---- 6. reserved_path(): Duo's own mu-plugin identity is reserved,
// case-insensitively; nothing else is.
$check(
    PathSafety::reserved_path('mu-plugins/duo') === true
        && PathSafety::reserved_path('MU-PLUGINS/DUO') === true
        && PathSafety::reserved_path('mu-plugins/duo/x.php') === true
        && PathSafety::reserved_path('mu-plugins/duo-loader.php') === true
        && PathSafety::reserved_path('mu-plugins/duo-other') === false
        && PathSafety::reserved_path('plugins/duo') === false,
    'reserved_path() protects exactly Duo\'s own mu-plugin identity, case-insensitively'
);

// ---- 7. safe_join(): joins a safe relative path, refuses an unsafe one
// before ever touching the filesystem.
$check(
    PathSafety::safe_join('/var/www/wp-content', 'plugins/foo.php') === '/var/www/wp-content/plugins/foo.php'
        && PathSafety::safe_join('/var/www/wp-content/', 'plugins/foo.php') === '/var/www/wp-content/plugins/foo.php',
    'safe_join() joins root and relative, trimming a trailing slash on root'
);
try {
    PathSafety::safe_join('/var/www/wp-content', '../etc/passwd');
    $check(false, 'safe_join() must refuse a traversal relative path');
} catch (\RuntimeException $e) {
    $check(str_contains($e->getMessage(), 'unsafe code path'), 'safe_join() refuses a traversal relative path before joining');
}

// ---- 8. same_target_path(): exact string match after trailing-slash
// trim, OR realpath-equal (so a symlinked-but-legitimate alias -- e.g. a
// host's own /var -> /private/var, DUO-3438's finding -- still compares
// equal). Uses real temp directories, not string fixtures.
$tmp = sys_get_temp_dir() . '/duo-path-safety-' . bin2hex(random_bytes(8));
mkdir($tmp, 0777, true);
mkdir("$tmp/real-target", 0777, true);
symlink("$tmp/real-target", "$tmp/alias-target");
$check(
    PathSafety::same_target_path("$tmp/real-target", "$tmp/real-target/") === true // trailing-slash trim
        && PathSafety::same_target_path("$tmp/alias-target", "$tmp/real-target") === true // realpath-equal through a symlink
        && PathSafety::same_target_path("$tmp/real-target", "$tmp/does-not-exist") === false,
    'same_target_path() matches on exact string or realpath equality, not just string identity'
);

// ---- 9. assert_no_symlinked_target_path(): the real filesystem check --
// define WP_CONTENT_DIR against a temp tree, prove a clean path passes and
// a symlinked intermediate segment refuses, both for the leaf itself and
// for an ancestor of the leaf.
define('WP_CONTENT_DIR', "$tmp/wp-content");
mkdir(WP_CONTENT_DIR . '/plugins/clean', 0777, true);
mkdir("$tmp/outside-content", 0777, true);
symlink("$tmp/outside-content", WP_CONTENT_DIR . '/plugins/linked');
$check(
    (function () {
        try {
            PathSafety::assert_no_symlinked_target_path('plugins/clean', true, 'test');
            return true;
        } catch (\RuntimeException $e) {
            return false;
        }
    })(),
    'assert_no_symlinked_target_path() passes a real, non-symlinked target path'
);
$check(
    (function () {
        try {
            PathSafety::assert_no_symlinked_target_path('plugins/linked', true, 'test');
            return false;
        } catch (\RuntimeException $e) {
            return str_contains($e->getMessage(), 'symbolic-link target path');
        }
    })(),
    'assert_no_symlinked_target_path() refuses when the leaf segment itself is a symlink'
);
$check(
    (function () {
        try {
            PathSafety::assert_no_symlinked_target_path('plugins/linked/nested.php', true, 'test');
            return false;
        } catch (\RuntimeException $e) {
            return str_contains($e->getMessage(), 'symbolic-link target path');
        }
    })(),
    'assert_no_symlinked_target_path() refuses when an ANCESTOR segment (not the leaf) is a symlink'
);
$check(
    (function () {
        try {
            PathSafety::assert_no_symlinked_target_path('plugins/linked', false, 'test');
            return true;
        } catch (\RuntimeException $e) {
            return false;
        }
    })(),
    'assert_no_symlinked_target_path() with includeLeaf=false does not check the leaf itself'
);

// ---- 10. Code's ten kept methods delegate rather than reimplement --
// source-scraped, since these are private static methods invoked through
// Code's own runtime paths, and a Reflection call proves only that the
// public CONTRACT still exists, not that the body stayed a delegate.
$codeSource = (string) file_get_contents(__DIR__ . '/../../agent/src/Code.php');
$delegations = [
    'safe_join' => 'return PathSafety::safe_join($root, $relative);',
    'same_target_path' => 'return PathSafety::same_target_path($actual, $expected);',
    'safe_relative' => 'return PathSafety::safe_relative($path);',
    'safe_component' => 'return PathSafety::safe_component($name);',
    'plugin_main_candidate' => 'return PathSafety::plugin_main_candidate($path);',
    'has_current_path_at_or_below' => 'return PathSafety::has_current_path_at_or_below($path, $currentPaths);',
    'owned_path' => 'return PathSafety::owned_path($path, $ownedRoots);',
    'safe_component_root' => 'return PathSafety::safe_component_root($path, self::ROOTS);',
    'reserved_path' => 'return PathSafety::reserved_path($path);',
];
foreach ($delegations as $method => $expectedBody) {
    $check(
        str_contains($codeSource, $expectedBody),
        "Code::$method() must delegate to PathSafety, not reimplement it"
    );
}
$check(
    str_contains($codeSource, 'PathSafety::assert_no_symlinked_target_path($relative, $includeLeaf, $operation);'),
    'Code::assert_no_symlinked_target_path() must delegate to PathSafety, not reimplement it'
);

// ---- 11. Behavioral cross-check: Code's facades and PathSafety itself
// agree on real inputs, proving the delegation is wired correctly end to
// end (not just present in the source).
$codeReflection = new ReflectionClass(Code::class);
$crossCheck = static function (string $method, array $args) use ($codeReflection): void {
    $rm = $codeReflection->getMethod($method);
    $rm->setAccessible(true);
    $viaCode = $rm->invokeArgs(null, $args);
    $viaPathSafety = call_user_func_array(['Duo\\PathSafety', $method === 'safe_component_root' ? $method : $method], $args);
    if ($viaCode !== $viaPathSafety) {
        throw new \RuntimeException("Code::$method() and PathSafety::$method() disagree for the same input");
    }
};
try {
    $crossCheck('safe_relative', ['plugins/../../etc/passwd']);
    $crossCheck('safe_component', ['@woocommerce']);
    $crossCheck('plugin_main_candidate', ['plugins/a/hello.php']);
    $crossCheck('has_current_path_at_or_below', ['plugins/foo', $current]);
    $crossCheck('owned_path', ['plugins/foo/bar.php', $owned]);
    $crossCheck('reserved_path', ['mu-plugins/duo']);
    $crossCheck('same_target_path', ["$tmp/alias-target", "$tmp/real-target"]);
    // safe_component_root takes an explicit second arg on PathSafety but not
    // on Code's facade (which supplies self::ROOTS internally) -- checked
    // separately rather than through the generic helper above.
    $rm = $codeReflection->getMethod('safe_component_root');
    $rm->setAccessible(true);
    $viaCode = $rm->invoke(null, 'plugins/woocommerce');
    $viaPathSafety = PathSafety::safe_component_root('plugins/woocommerce', ['mu-plugins', 'plugins', 'themes']);
    $check(
        $viaCode === true && $viaPathSafety === true && $viaCode === $viaPathSafety,
        'Code::safe_component_root() (implicit ROOTS) and PathSafety::safe_component_root() (explicit roots) agree'
    );
    $check(true, 'Code facades and PathSafety agree on every cross-checked input');
} catch (\RuntimeException $e) {
    $check(false, $e->getMessage());
}

// Cleanup.
$rm = static function (string $path) use (&$rm): void {
    if (is_link($path) || is_file($path)) {
        unlink($path);
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $rm("$path/$entry");
    }
    rmdir($path);
};
$rm($tmp);

if ($failures) {
    fwrite(STDERR, "FAIL\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}

echo "ok: PathSafety extraction preserves traversal/symlink safety and Code's facades delegate rather than duplicate\n";
