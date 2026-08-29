<?php
/**
 * Offline characterization for RepositoryStateFileCatalog (issue #3348 slice
 * 44). The catalog owns recursive state-partition enumeration and link
 * refusal only; RepositoryCompiler retains the state-root preflight,
 * parsing/routing, and aggregate refusal/sort.
 */
declare(strict_types=1);

$root = dirname(__DIR__, 4);
$catalogPath = "$root/agent/src/Repository/RepositoryStateFileCatalog.php";
$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    echo ($ok ? 'ok: ' : 'FAIL: ') . $message . "\n";
    if (!$ok) {
        $failures[] = $message;
    }
};

$child = proc_open(
    [PHP_BINARY, '-r', 'require $argv[1]; echo class_exists(\WPrism\RepositoryStateFileCatalog::class, false) && !class_exists(\WPrism\RepositoryCompiler::class, false) && !class_exists(\WPrism\Policy::class, false) && !function_exists("get_option") ? "loaded\n" : "broken\n";', $catalogPath],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes
);
$childOut = is_resource($child) ? stream_get_contents($pipes[1]) : '';
$childErr = is_resource($child) ? stream_get_contents($pipes[2]) : '';
if (is_resource($child)) {
    fclose($pipes[1]);
    fclose($pipes[2]);
    $childExit = proc_close($child);
} else {
    $childExit = 1;
}
$check(
    $childExit === 0 && $childOut === "loaded\n" && $childErr === '',
    'direct catalog loading has no compiler, Policy, or WordPress dependency'
);

require_once $catalogPath;

use WPrism\RepositoryStateFileCatalog;

$tmp = sys_get_temp_dir() . '/wprism-state-file-catalog-' . bin2hex(random_bytes(6));
$remove = static function (string $path) use (&$remove): void {
    if (is_link($path) || is_file($path)) {
        @unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $entry) {
        $remove($entry->getPathname());
    }
    @rmdir($path);
};
register_shutdown_function(static function () use ($tmp, $remove): void { $remove($tmp); });
mkdir($tmp . '/state/nested', 0777, true);
mkdir($tmp . '/outside', 0777, true);
file_put_contents($tmp . '/state/z.json', 'z');
file_put_contents($tmp . '/state/.hidden', 'hidden');
file_put_contents($tmp . '/state/nested/a.json', 'a');
file_put_contents($tmp . '/outside/sentinel.json', 'outside');
symlink($tmp . '/outside/sentinel.json', $tmp . '/state/file-link');
symlink($tmp . '/outside', $tmp . '/state/directory-link');
symlink($tmp . '/outside/missing.json', $tmp . '/state/dangling-link');

$diagnostics = [];
$catalog = new RepositoryStateFileCatalog(
    $tmp . '/state',
    static function (string $code, string $path, string $locator, string $message, ?string $relatedPath = null) use (&$diagnostics): void {
        $diagnostics[] = compact('code', 'path', 'locator', 'message', 'relatedPath');
    }
);
$files = $catalog->files();
$check(
    $files === [
        '.hidden' => $tmp . '/state/.hidden',
        'nested/a.json' => $tmp . '/state/nested/a.json',
        'z.json' => $tmp . '/state/z.json',
    ],
    'all and only regular state files are globally lexically ordered with exact relative and absolute paths'
);
$byPath = [];
foreach ($diagnostics as $diagnostic) {
    $byPath[$diagnostic['path']] = $diagnostic;
}
ksort($byPath, SORT_STRING);
$check(
    count($diagnostics) === 3
    && array_keys($byPath) === ['dangling-link', 'directory-link', 'file-link']
    && array_reduce($diagnostics, static fn(bool $ok, array $d): bool => $ok
        && $d['code'] === 'unsafe_repository_path'
        && $d['locator'] === ''
        && $d['message'] === 'symbolic links are not valid canonical entities'
        && $d['relatedPath'] === null, true)
    && !isset($files['directory-link/sentinel.json']),
    'file, directory, and dangling links are reported while directory links are never traversed'
);

$empty = $tmp . '/empty';
mkdir($empty, 0777, true);
$diagnostics = [];
$emptyCatalog = new RepositoryStateFileCatalog(
    $empty,
    static function (string $code, string $path, string $locator, string $message, ?string $relatedPath = null) use (&$diagnostics): void {
        $diagnostics[] = compact('code', 'path', 'locator', 'message', 'relatedPath');
    }
);
$check($emptyCatalog->files() === [] && $diagnostics === [], 'an existing empty state root remains an empty catalog');

symlink($tmp . '/state', $tmp . '/state-root-link');
$diagnostics = [];
$rootLinkCatalog = new RepositoryStateFileCatalog(
    $tmp . '/state-root-link',
    static function (string $code, string $path, string $locator, string $message, ?string $relatedPath = null) use (&$diagnostics): void {
        $diagnostics[] = compact('code', 'path', 'locator', 'message', 'relatedPath');
    }
);
$rootLinkFiles = $rootLinkCatalog->files();
$check(
    $rootLinkFiles === [
        '.hidden' => $tmp . '/state-root-link/.hidden',
        'nested/a.json' => $tmp . '/state-root-link/nested/a.json',
        'z.json' => $tmp . '/state-root-link/z.json',
    ] && count($diagnostics) === 3,
    'a symlinked state root retains the historical followed-root behavior while entry links still refuse'
);

$compiler = (string) file_get_contents("$root/agent/src/Repository/RepositoryCompiler.php");
$source = (string) file_get_contents($catalogPath);
$preflight = strpos($compiler, 'if (!is_dir($this->stateDir)) {');
$catalog = strpos($compiler, '$this->stateFileCatalog->files()');
$check(
    substr_count($compiler, "require_once __DIR__ . '/RepositoryStateFileCatalog.php';") === 1
    && substr_count($compiler, 'new RepositoryStateFileCatalog(') === 1
    && substr_count($compiler, '->stateFileCatalog->files()') === 1
    && $preflight !== false && $catalog !== false && $preflight < $catalog
    && !str_contains($compiler, 'private function state_files(')
    && str_contains($source, 'new \\RecursiveDirectoryIterator($this->stateDir, \\FilesystemIterator::SKIP_DOTS)')
    && str_contains($source, 'ksort($out, SORT_STRING);'),
    'RepositoryCompiler retains state-root preflight and delegates exactly one recursive catalog without retaining the moved loop'
);

if ($failures !== []) {
    fwrite(STDERR, "\nFAILED " . count($failures) . " assertion(s)\n");
    exit(1);
}
echo "\nALL PASSED\n";
