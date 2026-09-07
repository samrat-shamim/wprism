<?php
declare(strict_types=1);

// No WordPress stubs: the live four-adapter comparison failed at this exact
// host compiler boundary because the interpreter called wp_kses to classify.
$root = dirname(__DIR__, 4);
$runtime = $argv[1] ?? $root;
require_once $root . '/sandbox/tests/lib/check.php';
require_once $root . '/sandbox/tests/lib/agent_version.php';
require_once $runtime . '/agent/src/Policy/Policy.php';
require_once $runtime . '/agent/src/Code/Code.php';
require_once $runtime . '/agent/src/Code/CodeStateContract.php';
require_once $runtime . '/agent/src/Repository/RepositoryAuthorization.php';
require_once $runtime . '/agent/src/Repository/RepositoryCompiler.php';
wprism_test_define_agent_versions();

use WPrism\AdapterLibrary;
use WPrism\Canon;
use WPrism\Policy;
use WPrism\RepositoryCompiler;
use WPrism\UserMetaState;

$scratch = sys_get_temp_dir() . '/wprism-polylang-compiler-' . bin2hex(random_bytes(8));
mkdir($scratch . '/state/user-meta', 0700, true);
$remove = static function (string $path) use (&$remove): void {
    if (is_dir($path) && !is_link($path)) {
        foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $entry) $remove($entry->getPathname());
        rmdir($path);
    } elseif (file_exists($path) || is_link($path)) unlink($path);
};
register_shutdown_function(static fn() => $remove($scratch));
Canon::write_file($scratch . '/site.wprism.json', Canon::encode([
    'spec_version' => WPRISM_SPEC_VERSION, 'manifests' => ['polylang'],
    'policy' => ['post_types' => [], 'taxonomies' => []],
]));
$policy = Policy::load($scratch, adapterLibrary: AdapterLibrary::fromSourceTree($runtime));
$path = $scratch . '/state/' . UserMetaState::path('editor');
$compile = static fn() => RepositoryCompiler::compile($scratch, $policy);
wprism_check(!function_exists('wp_kses'), 'standalone compiler process has no WordPress sanitizer or substitute');
foreach (['', 'Biography 東京 বাংলা', '<a href="https://example.test/about">About</a>'] as $biography) {
    Canon::write_file($path, Canon::encode(UserMetaState::document('editor', ['description' => $biography])));
    try {
        $artifact = $compile();
        wprism_check_same($biography, $artifact->tree()[UserMetaState::key('editor')]['data']['meta']['description'],
            'real shipped Polylang policy compiles exact portable biography without WordPress');
    } catch (Throwable $failure) {
        wprism_check(false, 'standalone Polylang compiler refused: ' . $failure->getMessage());
    }
}
foreach ([23, [], "bad\x01text", str_repeat('x', 1048577)] as $invalid) {
    Canon::write_file($path, Canon::encode(UserMetaState::document('editor', ['description' => $invalid])));
    wprism_check_throws($compile, RuntimeException::class, 'portable biography shape/size remains a compiler refusal');
}
Canon::write_file($path, Canon::encode(UserMetaState::document('editor', ['description_zz' => 'Unknown language'])));
wprism_check_throws($compile, RuntimeException::class, 'language suffix still requires its repository language term', 'repository language term');
wprism_check(!function_exists('wp_kses'), 'compilation never installed WordPress or a sanitizer shim');
wprism_check_summary('Polylang standalone compiler boundary');
