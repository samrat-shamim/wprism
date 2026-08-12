#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Build and verify one adapter's independently current certification record.
 *
 * Unlike certification-bundle.php's release-wide record, this command binds
 * only the named adapter, its reviewed disposition, the exact artifacts used
 * by that adapter's lane, and an intentionally conservative closure supplied
 * by the lane runner.  Its output is evidence, never a product claim: the
 * capability-registry importer remains the sole publication boundary.
 *
 * Usage:
 *   php sandbox/bin/adapter-certification-bundle.php build <spec.json> <out-dir>
 *   php sandbox/bin/adapter-certification-bundle.php verify <bundle-dir|bundle.json> <repo-root>
 */

$repo = dirname(__DIR__, 2);
define('DUO_AGENT_VERSION', preg_match("/define\\('DUO_AGENT_VERSION', '([^']+)'\\)/", (string) file_get_contents($repo . '/agent/duo.php'), $m) ? $m[1] : '');
define('DUO_SPEC_VERSION', preg_match("/define\\('DUO_SPEC_VERSION', ([0-9]+)\\)/", (string) file_get_contents($repo . '/agent/duo.php'), $m) ? (int) $m[1] : 0);
function is_multisite(): bool { return false; }

require $repo . '/agent/src/Canon.php';
require $repo . '/agent/src/ManifestDispositions.php';
require $repo . '/agent/src/CapabilityRegistry.php';
require $repo . '/agent/src/ScopedCertificationBundle.php';

use Duo\Canon;
use Duo\CapabilityRegistry;
use Duo\ManifestDispositions;
use Duo\ScopedCertificationBundle;

function adapter_bundle_json(mixed $value, bool $pretty = false): string {
    $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;
    if ($pretty) {
        $flags |= JSON_PRETTY_PRINT;
    }
    return json_encode(Canon::normalize($value), $flags) . "\n";
}

function adapter_bundle_emit(array $result, int $exit): never {
    fwrite(STDOUT, adapter_bundle_json($result));
    exit($exit);
}

function adapter_bundle_read(string $path): array {
    $raw = @file_get_contents($path);
    if ($raw === false) {
        throw new RuntimeException("cannot read JSON: $path");
    }
    $value = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($value) || array_is_list($value)) {
        throw new RuntimeException("JSON root must be an object: $path");
    }
    return $value;
}

function adapter_bundle_safe_relative(mixed $raw, string $label): string {
    $path = is_string($raw) ? $raw : '';
    if ($path === '' || str_starts_with($path, '/') || str_contains($path, "\0") || str_contains($path, '\\')
        || str_contains($path, '//') || str_ends_with($path, '/')
        || in_array('.', explode('/', $path), true) || in_array('..', explode('/', $path), true)) {
        throw new RuntimeException("$label must be one canonical relative path");
    }
    return $path;
}

/** @return array{path:string,sha256:string,size:int} */
function adapter_bundle_asset(string $path, string $relative): array {
    if (!is_file($path) || is_link($path)) {
        throw new RuntimeException("asset is absent, not a regular file, or a symbolic link: $relative");
    }
    $hash = hash_file('sha256', $path);
    $size = filesize($path);
    if ($hash === false || $size === false) {
        throw new RuntimeException("could not hash asset: $relative");
    }
    return ['path' => $relative, 'sha256' => $hash, 'size' => $size];
}

/** @return array{name:string,manifest:array,disposition:array,dir:string} */
function adapter_bundle_subject(string $root, string $name): array {
    if (!preg_match('/^[a-z][a-z0-9-]*$/D', $name)) {
        throw new RuntimeException('manifest must be a canonical manifest name');
    }
    $dir = rtrim($root, '/') . '/manifests';
    $path = "$dir/$name.json";
    if (!is_file($path) || is_link($path)) {
        throw new RuntimeException("manifest '$name' is absent or unsafe");
    }
    $manifest = Canon::decode(Canon::read_file($path));
    if (!is_array($manifest) || ($manifest['name'] ?? null) !== $name) {
        throw new RuntimeException("manifest '$name' has an invalid identity");
    }
    $dispositions = ManifestDispositions::load($dir);
    $disposition = $dispositions?->entry($name);
    if ($dispositions === null || !is_array($disposition)) {
        throw new RuntimeException("manifest '$name' has no reviewed disposition");
    }
    return ['name' => $name, 'manifest' => $manifest, 'disposition' => $disposition, 'dir' => $dir];
}

function adapter_bundle_platform(string $root): array {
    $compatibility = adapter_bundle_read(rtrim($root, '/') . '/docs/compatibility-baseline.json');
    unset($compatibility['_comment']);
    return [
        'agent_version' => DUO_AGENT_VERSION,
        'branchable_state' => 'only exact certified registry surfaces and operations',
        'compatibility' => $compatibility,
        'plugin_execution' => 'unmodified',
        'site_mode' => 'single-site',
        'spec_version' => DUO_SPEC_VERSION,
    ];
}

/** @return list<array{name:string,role:string,sha256:string,url:string,version:string}> */
function adapter_bundle_artifacts(string $root, array $manifest): array {
    $plugin = $manifest['plugin'] ?? null;
    if (!is_string($plugin) || !str_contains($plugin, '/')) {
        throw new RuntimeException('adapter certification currently requires a plugin-backed manifest');
    }
    $slug = strstr($plugin, '/', true);
    $lock = adapter_bundle_read(rtrim($root, '/') . '/sandbox/conformance/artifacts.lock.json');
    $entries = $lock['plugins'][$slug] ?? null;
    if (!is_array($entries) || array_is_list($entries)) {
        throw new RuntimeException("no typed artifact lock entries exist for '$slug'");
    }
    $out = [];
    foreach ($entries as $version => $entry) {
        if (!is_array($entry) || !in_array($entry['role'] ?? null, ['certified-boundary', 'refusal-fixture'], true)) {
            continue;
        }
        $out[] = [
            'name' => $slug,
            'role' => $entry['role'],
            'sha256' => $entry['sha256'] ?? null,
            'url' => $entry['url'] ?? null,
            'version' => (string) $version,
        ];
    }
    if ($out === []) {
        throw new RuntimeException("no certified/refusal artifact boundaries exist for '$slug'");
    }
    usort($out, static fn(array $a, array $b): int => strcmp(
        $a['name'] . "\0" . $a['role'] . "\0" . $a['version'],
        $b['name'] . "\0" . $b['role'] . "\0" . $b['version']
    ));
    return $out;
}

/** @return list<array{path:string,sha256:string,size:int}> */
function adapter_bundle_inputs(string $root, mixed $paths): array {
    if (!is_array($paths) || !array_is_list($paths) || $paths === []) {
        throw new RuntimeException('bound_inputs must be a non-empty list');
    }
    $out = [];
    $seen = [];
    foreach ($paths as $i => $raw) {
        $relative = adapter_bundle_safe_relative($raw, "bound_inputs[$i]");
        if (isset($seen[$relative])) {
            throw new RuntimeException("duplicate bound input: $relative");
        }
        $seen[$relative] = true;
        $out[] = adapter_bundle_asset(rtrim($root, '/') . '/' . $relative, $relative);
    }
    usort($out, static fn(array $a, array $b): int => strcmp($a['path'], $b['path']));
    return $out;
}

/** @return list<array{path:string,sha256:string,size:int}> */
function adapter_bundle_current_inputs(string $root, array $recorded): array {
    return adapter_bundle_inputs($root, array_map(static fn(array $input): string => (string) ($input['path'] ?? ''), $recorded));
}

function adapter_bundle_copy(string $source, string $destination): void {
    if (!is_file($source) || is_link($source)) {
        throw new RuntimeException("test evidence is absent or unsafe: $source");
    }
    if (!is_dir(dirname($destination)) && !mkdir(dirname($destination), 0777, true) && !is_dir(dirname($destination))) {
        throw new RuntimeException('could not create bundle evidence directory');
    }
    if (!copy($source, $destination)) {
        throw new RuntimeException("could not copy test evidence: $source");
    }
}

/** @return list<array<string,mixed>> */
function adapter_bundle_tests(array $specTests, string $stage): array {
    if (!array_is_list($specTests) || $specTests === []) {
        throw new RuntimeException('tests must be a non-empty list');
    }
    $out = [];
    $seen = [];
    foreach ($specTests as $i => $test) {
        if (!is_array($test) || array_is_list($test) || !is_string($test['id'] ?? null)
            || preg_match('/^[a-z][a-z0-9-]*$/D', $test['id']) !== 1 || isset($seen[$test['id']])) {
            throw new RuntimeException("tests[$i] has an invalid or duplicate id");
        }
        $seen[$test['id']] = true;
        foreach (['result', 'diff', 'log'] as $kind) {
            if (!is_string($test[$kind] ?? null) || $test[$kind] === '') {
                throw new RuntimeException("tests[$i].$kind must name an evidence file");
            }
        }
        $result = adapter_bundle_read($test['result']);
        if (($result['test'] ?? null) !== $test['id'] || ($result['verdict'] ?? null) !== 'pass'
            || ($result['exit_code'] ?? null) !== 0) {
            throw new RuntimeException("tests[$i] does not contain a passing result for '{$test['id']}'");
        }
        $id = $test['id'];
        $resultRelative = "results/$id.json";
        $diffRelative = "diffs/$id.json";
        $logRelative = "logs/$id.txt";
        adapter_bundle_copy($test['result'], "$stage/$resultRelative");
        adapter_bundle_copy($test['diff'], "$stage/$diffRelative");
        adapter_bundle_copy($test['log'], "$stage/$logRelative");
        $assets = [
            adapter_bundle_asset("$stage/$resultRelative", $resultRelative),
            adapter_bundle_asset("$stage/$diffRelative", $diffRelative),
            adapter_bundle_asset("$stage/$logRelative", $logRelative),
        ];
        $out[] = [
            'diff' => $assets[1],
            'evidence_sha256' => ScopedCertificationBundle::evidenceDigest($assets),
            'exit_code' => 0,
            'id' => $id,
            'log' => $assets[2],
            'result' => $assets[0],
            'verdict' => 'pass',
        ];
    }
    return $out;
}

function adapter_bundle_build(string $specPath, string $outputRoot): never {
    $stage = null;
    try {
        $spec = adapter_bundle_read($specPath);
        $root = realpath((string) ($spec['repo_root'] ?? ''));
        if ($root === false || !is_dir($root)) {
            throw new RuntimeException('repo_root must name a real repository directory');
        }
        $manifestName = (string) ($spec['manifest'] ?? '');
        $subject = adapter_bundle_subject($root, $manifestName);
        $createdAt = $spec['created_at'] ?? null;
        $revision = $spec['git_revision'] ?? null;
        if (!is_string($createdAt) || strtotime($createdAt) === false
            || !is_string($revision) || preg_match('/^[0-9a-f]{40}$/D', $revision) !== 1) {
            throw new RuntimeException('created_at or git_revision is malformed');
        }
        $hatches = $spec['force_hatches'] ?? [];
        if (!is_array($hatches) || !array_is_list($hatches) || $hatches !== []) {
            throw new RuntimeException('adapter certification must run without force hatches');
        }
        $outputRoot = rtrim($outputRoot, '/');
        if ($outputRoot === '' || (!is_dir($outputRoot) && !mkdir($outputRoot, 0777, true) && !is_dir($outputRoot))) {
            throw new RuntimeException('could not create output root');
        }
        $stage = $outputRoot . '/.building-' . bin2hex(random_bytes(6));
        if (!mkdir($stage, 0777, true)) {
            throw new RuntimeException('could not create bundle staging directory');
        }
        $inputs = adapter_bundle_inputs($root, $spec['bound_inputs'] ?? null);
        $tests = adapter_bundle_tests($spec['tests'] ?? [], $stage);
        $adapterDigest = CapabilityRegistry::adapter_digest($subject['manifest'], $subject['disposition'], $subject['dir']);
        $bundle = [
            'adapter_digest' => $adapterDigest,
            'artifacts' => adapter_bundle_artifacts($root, $subject['manifest']),
            'bundle_digest' => str_repeat('0', 64),
            'claims' => ['manifests.' . $manifestName => array_column($tests, 'id')],
            'closure' => ['digest' => ScopedCertificationBundle::closureDigest($inputs), 'inputs' => $inputs],
            'created_at' => $createdAt,
            'force_hatches' => [],
            'format' => ScopedCertificationBundle::FORMAT,
            'git_revision' => $revision,
            'platform' => adapter_bundle_platform($root),
            'ratification' => [
                'disposition' => $subject['disposition'],
                'manifest' => $manifestName,
                'sha256' => hash('sha256', Canon::encode($subject['disposition'])),
            ],
            'subject' => ['manifest' => $manifestName],
            'tests' => $tests,
            'verdict' => 'pass',
        ];
        $bundle['bundle_digest'] = ScopedCertificationBundle::digest($bundle);
        ScopedCertificationBundle::validate($bundle);
        file_put_contents("$stage/bundle.json", Canon::encode($bundle));
        $final = "$outputRoot/{$bundle['bundle_digest']}";
        if (file_exists($final)) {
            adapter_bundle_remove($stage);
        } elseif (!rename($stage, $final)) {
            throw new RuntimeException('could not atomically publish adapter bundle');
        }
        adapter_bundle_emit([
            'bundle' => $final,
            'bundle_digest' => $bundle['bundle_digest'],
            'format' => ScopedCertificationBundle::FORMAT,
            'manifest' => $manifestName,
            'verdict' => 'pass',
        ], 0);
    } catch (Throwable $e) {
        if ($stage !== null) {
            adapter_bundle_remove($stage);
        }
        fwrite(STDERR, 'adapter certification bundle build failed: ' . $e->getMessage() . "\n");
        adapter_bundle_emit(['format' => ScopedCertificationBundle::FORMAT, 'reason' => 'bundle_build_error', 'verdict' => 'failed'], 1);
    }
}

function adapter_bundle_remove(string $path): void {
    if (!file_exists($path) && !is_link($path)) {
        return;
    }
    if (is_file($path) || is_link($path)) {
        @unlink($path);
        return;
    }
    foreach (new FilesystemIterator($path) as $item) {
        adapter_bundle_remove($item->getPathname());
    }
    @rmdir($path);
}

function adapter_bundle_verify(string $input, string $root): never {
    try {
        $root = realpath($root);
        if ($root === false || !is_dir($root)) {
            throw new RuntimeException('repository root is invalid');
        }
        $file = is_dir($input) ? rtrim($input, '/') . '/bundle.json' : $input;
        $dir = dirname($file);
        $bundle = adapter_bundle_read($file);
        ScopedCertificationBundle::validate($bundle);
        if (!hash_equals(basename($dir), (string) $bundle['bundle_digest'])) {
            throw new RuntimeException('bundle directory does not match the content-addressed digest');
        }
        foreach ($bundle['tests'] as $test) {
            foreach (['result', 'diff', 'log'] as $kind) {
                $asset = $test[$kind];
                $relative = adapter_bundle_safe_relative($asset['path'] ?? null, "test {$test['id']} $kind path");
                $actual = adapter_bundle_asset("$dir/$relative", $relative);
                if (Canon::encode($actual) !== Canon::encode($asset)) {
                    throw new RuntimeException("bundle test asset is missing or corrupt: $relative");
                }
            }
        }
        $name = (string) $bundle['subject']['manifest'];
        $subject = adapter_bundle_subject($root, $name);
        $actualDigest = CapabilityRegistry::adapter_digest($subject['manifest'], $subject['disposition'], $subject['dir']);
        ScopedCertificationBundle::assertCurrent(
            $bundle,
            $name,
            $actualDigest,
            adapter_bundle_platform($root),
            adapter_bundle_current_inputs($root, $bundle['closure']['inputs']),
            $subject['disposition']['evidence']['tests'] ?? [],
            $subject['disposition'],
            adapter_bundle_artifacts($root, $subject['manifest'])
        );
        adapter_bundle_emit([
            'bundle_digest' => $bundle['bundle_digest'],
            'format' => ScopedCertificationBundle::FORMAT,
            'manifest' => $name,
            'verdict' => 'valid',
        ], 0);
    } catch (Throwable $e) {
        fwrite(STDERR, 'adapter certification bundle verify failed: ' . $e->getMessage() . "\n");
        adapter_bundle_emit(['format' => ScopedCertificationBundle::FORMAT, 'reason' => 'bundle_verify_error', 'verdict' => 'failed'], 1);
    }
}

try {
    $command = $argv[1] ?? '';
    if ($command === 'build' && isset($argv[2], $argv[3])) {
        adapter_bundle_build($argv[2], $argv[3]);
    }
    if ($command === 'verify' && isset($argv[2], $argv[3])) {
        adapter_bundle_verify($argv[2], $argv[3]);
    }
    throw new RuntimeException('usage: adapter-certification-bundle.php build <spec.json> <out-dir> | verify <bundle> <repo-root>');
} catch (Throwable $e) {
    fwrite(STDERR, 'adapter certification bundle: ' . $e->getMessage() . "\n");
    exit(1);
}
