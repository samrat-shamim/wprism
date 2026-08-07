<?php
/**
 * Offline regression for DUO-3262's interpreter contract. Fake manifest-
 * shipped interpreters prove that post_meta_rule() remains required while
 * term_meta_rule()/user_meta_rule() are optional, context-aware hooks. The
 * test exercises the real Policy loader and dispatch; no WordPress bootstrap
 * or plugin code is needed because classification is the mechanism in scope.
 */

require __DIR__ . '/../../agent/src/Canon.php';
require __DIR__ . '/../../agent/src/OptionState.php';
require __DIR__ . '/../../agent/src/Policy.php';

use Duo\Canon;
use Duo\Policy;

if (!defined('DUO_SPEC_VERSION')) {
    define('DUO_SPEC_VERSION', 0);
}

$failures = 0;
function check(bool $condition, string $message): void {
    global $failures;
    if ($condition) {
        echo "ok: $message\n";
        return;
    }
    echo "FAIL: $message\n";
    $failures++;
}

function check_throws(callable $fn, string $needle, string $message): void {
    try {
        $fn();
        check(false, "$message (did not throw)");
    } catch (\Throwable $e) {
        check(str_contains($e->getMessage(), $needle), "$message (threw: {$e->getMessage()})");
    }
}

$root = sys_get_temp_dir() . '/duo_regress_interpreter_policy_' . bin2hex(random_bytes(4));
mkdir($root . '/interpreters', 0777, true);
register_shutdown_function(function () use ($root) {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($root);
});
putenv("DUO_MANIFESTS_DIR=$root");

file_put_contents($root . '/interpreters/legacy-post-only.php', <<<'PHP'
<?php
namespace Duo\Interpreters;
final class LegacyPostOnly {
    public function __construct($policy) {}
    public function post_meta_rule(string $key, array $allMeta): ?array {
        return $key === 'post_dynamic' && ($allMeta['_post_dynamic'] ?? null) === 'field_1'
            ? ['class' => 'authored']
            : null;
    }
}
PHP
);

file_put_contents($root . '/interpreters/all-meta-hooks.php', <<<'PHP'
<?php
namespace Duo\Interpreters;
final class AllMetaHooks {
    public function __construct($policy) {}
    public function post_meta_rule(string $key, array $allMeta): ?array {
        return $key === 'post_dynamic' ? ['class' => 'authored'] : null;
    }
    public function term_meta_rule(string $key, array $allMeta): ?array {
        return $key === 'term_dynamic' && ($allMeta['_term_dynamic'] ?? null) === 'field_2'
            ? ['class' => 'authored', 'ref' => 'term']
            : null;
    }
    public function user_meta_rule(string $key, array $allMeta): ?array {
        return $key === 'user_dynamic' && ($allMeta['_user_dynamic'] ?? null) === 'field_3'
            ? ['class' => 'authored', 'ref' => 'user']
            : null;
    }
}
PHP
);

file_put_contents($root . '/interpreters/missing-post-hook.php', <<<'PHP'
<?php
namespace Duo\Interpreters;
final class MissingPostHook {
    public function __construct($policy) {}
    public function term_meta_rule(string $key, array $allMeta): ?array { return null; }
}
PHP
);

function write_manifest(string $root, string $name, array $extra): void {
    Canon::write_file("$root/$name.json", Canon::encode(array_merge([
        'name' => $name,
        'spec_version' => DUO_SPEC_VERSION,
    ], $extra)));
}

write_manifest($root, 'legacy', [
    'interpreter' => 'legacy-post-only',
    'post_meta' => ['post_static' => ['class' => 'runtime']],
    'term_meta' => ['term_static' => ['class' => 'runtime']],
    'user_meta' => ['user_static' => ['class' => 'env']],
    'meta_patterns' => [['match' => '^pattern_', 'class' => 'derived']],
]);

echo "\n== post-only interpreter remains compatible ==\n";
$legacy = Policy::load(null, ['legacy']);
check(
    ($legacy->meta_rule_for_post('post_dynamic', ['_post_dynamic' => 'field_1'])['class'] ?? null) === 'authored',
    'required post hook still receives the owning post meta map'
);
check(
    ($legacy->meta_rule_for_term('term_static', [])['class'] ?? null) === 'runtime',
    'missing optional term hook defers to the static term_meta rule without error'
);
check(
    ($legacy->meta_rule_for_user('user_static', [])['class'] ?? null) === 'env',
    'missing optional user hook defers to the static user_meta rule without error'
);
check(
    $legacy->meta_rule_for_term('unclassified', ['shadow' => 'value']) === null,
    'no interpreter/static match stays null so Capture\'s loud unclassified gate remains armed'
);
check(
    ($legacy->meta_rule_for_term('pattern_versioned', [])['class'] ?? null) === 'derived',
    'existing meta_patterns fallback remains active for term meta'
);
check(
    $legacy->meta_rule_for_user('pattern_versioned', []) === null,
    'existing meta_patterns do not silently widen onto the new user_meta surface'
);
check(
    $legacy->user_meta_capture_blocker('user_static', []) === null,
    'non-authored static user_meta classifications remain usable without arming capture refusal'
);

write_manifest($root, 'full', [
    'interpreter' => 'all-meta-hooks',
    'post_meta' => ['post_static' => ['class' => 'runtime']],
    'term_meta' => [
        'term_dynamic' => ['class' => 'runtime'],
        'term_static' => ['class' => 'derived'],
    ],
    'user_meta' => [
        'user_dynamic' => ['class' => 'runtime'],
        'user_static' => ['class' => 'env'],
    ],
]);

echo "\n== optional hooks win, then defer exactly like post meta ==\n";
$full = Policy::load(null, ['full']);
$termRule = $full->meta_rule_for_term('term_dynamic', [
    '_term_dynamic' => 'field_2',
    'term_dynamic' => '17',
]);
check(
    ($termRule['class'] ?? null) === 'authored' && ($termRule['ref'] ?? null) === 'term',
    'term hook sees the full term meta map and wins over a conflicting static rule'
);
$userRule = $full->meta_rule_for_user('user_dynamic', [
    '_user_dynamic' => 'field_3',
    'user_dynamic' => '4',
]);
check(
    ($userRule['class'] ?? null) === 'authored' && ($userRule['ref'] ?? null) === 'user',
    'user hook sees the full user meta map and wins over a conflicting static rule'
);
check(
    str_contains((string) $full->user_meta_capture_blocker('user_dynamic', [
        '_user_dynamic' => 'field_3',
        'user_dynamic' => '4',
    ]), 'capture is blocked pending DUO-3268'),
    'interpreter-classified authored user meta arms the DUO-3268 capture blocker instead of becoming inert'
);
check(
    ($full->meta_rule_for_term('term_static', [])['class'] ?? null) === 'derived',
    'null from term hook falls through to the static term_meta rule'
);
check(
    ($full->meta_rule_for_user('user_static', [])['class'] ?? null) === 'env',
    'null from user hook falls through to the static user_meta rule'
);
check(
    $full->meta_rule_for_user('unclassified', ['anything' => 'else']) === null,
    'neither user hook nor static policy silently invents a classification'
);

echo "\n== user_meta is a first-class policy section ==\n";
$siteRepo = $root . '/site';
mkdir($siteRepo);
Canon::write_file($siteRepo . '/site.duo.json', Canon::encode([
    'manifests' => ['legacy'],
    'policy' => new stdClass(),
]));
Policy::set_rule($siteRepo, 'user_meta', 'profile_owner', ['class' => 'authored', 'ref' => 'user']);
$sitePolicy = Policy::load($siteRepo);
check(
    ($sitePolicy->meta_rule_for_user('profile_owner', [])['ref'] ?? null) === 'user',
    'wp duo classify write path accepts user_meta and Policy loads the site override'
);
check(
    str_contains((string) $sitePolicy->user_meta_capture_blocker('profile_owner', []), 'DUO-3268'),
    'static authored user_meta policy arms the same capture blocker'
);
$export = Policy::export_manifest($siteRepo, '^profile_', 'profile-fixture');
$exportedUserMeta = (array) $export['user_meta'];
check(
    (($exportedUserMeta['profile_owner']['class'] ?? null) === 'authored')
        && is_object($export['term_meta']),
    'policy-to-manifest export carries user_meta while preserving empty-section objects'
);

write_manifest($root, 'broken', ['interpreter' => 'missing-post-hook']);
$broken = Policy::load(null, ['broken']);
check_throws(
    fn() => $broken->meta_rule_for_term('anything', []),
    'must define',
    'post_meta_rule remains mandatory even when an optional term hook exists'
);

echo "\n";
if ($failures > 0) {
    echo "FAIL: $failures check(s) failed\n";
    exit(1);
}
echo "ALL PASSED\n";
