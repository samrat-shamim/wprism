<?php
declare(strict_types=1);

// DUO-3350 slice 3: the deterministic descriptor boundary has its own
// collaborator. This fixture intentionally bypasses Code's materializer and
// ledger paths, then proves the historical Code facade is byte-identical.
$root = dirname(__DIR__, 2);
require_once $root . '/agent/src/CodeDescriptorCompiler.php';
require_once $root . '/agent/src/Code.php';

use Duo\Canon;
use Duo\Code;
use Duo\CodeCompilationException;
use Duo\CodeDescriptorCompiler;

function descriptor_fail(string $message): never {
    throw new RuntimeException('FAIL: ' . $message);
}

function descriptor_assert(bool $condition, string $message): void {
    if (!$condition) {
        descriptor_fail($message);
    }
}

function descriptor_write(string $path, string $bytes): void {
    $parent = dirname($path);
    if (!is_dir($parent) && !mkdir($parent, 0777, true) && !is_dir($parent)) {
        descriptor_fail('cannot create ' . $parent);
    }
    if (file_put_contents($path, $bytes) === false) {
        descriptor_fail('cannot write ' . $path);
    }
}

function descriptor_remove(string $path): void {
    if (is_link($path) || is_file($path)) {
        @unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $child) {
        if ($child !== '.' && $child !== '..') {
            descriptor_remove($path . '/' . $child);
        }
    }
    @rmdir($path);
}

$source = sys_get_temp_dir() . '/duo-descriptor-compiler-' . bin2hex(random_bytes(6));
register_shutdown_function(static function () use ($source): void {
    descriptor_remove($source);
});

descriptor_write($source . '/plugins/example/example.php', "<?php\n/*\nPlugin Name: Example\n*/\n");
descriptor_write($source . '/plugins/example/include.php', "<?php\n");
descriptor_write($source . '/themes/example/style.css', "/*\nTheme Name: Example\n*/\n");
descriptor_write($source . '/themes/example/index.php', "<?php\n");
descriptor_write($source . '/mu-plugins/bootstrap.php', "<?php\n");

$direct = CodeDescriptorCompiler::descriptor_from_source($source);
$facade = Code::descriptor_from_source($source);
descriptor_assert(Canon::encode($direct) === Canon::encode($facade), 'Code facade changed descriptor bytes');
descriptor_assert(
    CodeDescriptorCompiler::revision_for(array_diff_key($direct, ['code_revision' => true])) === $direct['code_revision'],
    'compiler revision does not verify its descriptor'
);
descriptor_assert(
    Code::revision_for(array_diff_key($direct, ['code_revision' => true])) === CodeDescriptorCompiler::revision_for(array_diff_key($direct, ['code_revision' => true])),
    'Code revision facade diverged from the compiler'
);

CodeDescriptorCompiler::assert_config(['format' => 1, 'layout' => 'wp-content', 'source' => 'code/wp-content']);
try {
    CodeDescriptorCompiler::assert_config(['format' => 1, 'layout' => 'custom', 'source' => 'code/wp-content']);
    descriptor_fail('compiler accepted a non-standard code layout');
} catch (RuntimeException $e) {
    descriptor_assert(str_contains($e->getMessage(), 'site.duo.json code must contain exactly'), 'config refusal lost its stable diagnostic');
}

$bad = $direct;
$bad['code_revision'] = str_repeat('0', 64);
try {
    CodeDescriptorCompiler::assert_descriptor($bad);
    descriptor_fail('compiler accepted a descriptor with a forged revision');
} catch (RuntimeException $e) {
    descriptor_assert(str_contains($e->getMessage(), 'revision does not verify'), 'revision refusal lost its stable diagnostic');
}

$missing = $source . '-missing';
try {
    CodeDescriptorCompiler::descriptor_from_source($missing);
    descriptor_fail('compiler accepted a missing source root');
} catch (CodeCompilationException $e) {
    descriptor_assert($e->diagnostics[0]['code'] === 'code_source_missing', 'missing source did not produce structured diagnostics');
    descriptor_assert($e->payload()['error'] === 'code_compilation_failed', 'compiler exception payload changed');
}

echo "ok: CodeDescriptorCompiler owns deterministic descriptor construction and Code remains a compatible facade\n";
