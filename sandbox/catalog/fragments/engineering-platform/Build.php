<?php

declare(strict_types=1);

namespace Duo\EngineeringPlatform;

/**
 * @phpstan-type BuildFile array{source:string,path:string,bytes:string,mode:int}
 * @phpstan-type ManifestFile array{path:string,sha256:string,size:int,mode:string}
 */
final class Build
{
    private const FORMAT = 'duo-candidate-build/v1';

    /** @var array<string,array<string,mixed>> */
    private const COMPATIBILITY = [
        'host-cli' => [
            'php' => ['minimum' => '8.1.0', 'maximum_exclusive' => null],
            'extensions' => ['json'],
            'conditional_extensions' => ['sodium' => 'signed recovery paths'],
            'functions' => ['array_is_list', 'flock', 'proc_open'],
            'binaries' => ['git', 'tar'],
            'conditional_binaries' => ['docker' => 'docker transport', 'scp' => 'SSH transport', 'ssh' => 'SSH transport'],
        ],
        'agent' => [
            'php' => ['loader_minimum' => '8.0.0', 'engine_minimum' => '8.2.0', 'certified' => '>=8.3,<8.4'],
            'extensions' => ['json'],
            'conditional_extensions' => ['sodium' => 'evidence and signature paths'],
            'functions' => ['flock', 'fsync', 'proc_open'],
            'binaries' => [],
            'runtime' => ['WordPress', 'WP-CLI'],
        ],
        'recovery' => [
            'php' => ['minimum' => '8.1.0', 'maximum_exclusive' => null],
            'extensions' => ['json', 'sodium'],
            'functions' => ['flock', 'fsync', 'proc_open'],
            'binaries' => [],
        ],
        'declarations' => [
            'php' => null,
            'extensions' => ['json'],
            'functions' => [],
            'binaries' => [],
            'consumer_contract' => 'manifest-declared schema version',
        ],
    ];

    public function __construct(private readonly string $root) {}

    /** @return array<string,mixed> */
    public function build(string $output, bool $requireClean = true): array
    {
        $candidate = $this->candidateSha();
        if ($requireClean && $this->gitStatus() !== '') {
            throw new CatalogException('release build requires a clean Git worktree');
        }
        $output = $this->safeOutput($output);
        if (file_exists($output) || is_link($output)) {
            $this->removeTree($output);
        }
        if (!mkdir($output, 0755, true) && !is_dir($output)) {
            throw new CatalogException('cannot create build output');
        }

        $components = [];
        foreach ($this->componentSources() as $component => $files) {
            $components[$component] = $this->writeComponent($output, $component, $files, $candidate);
        }
        $target = [];
        foreach (['agent', 'recovery', 'declarations'] as $component) {
            $target[$component] = [
                'archive_sha256' => $components[$component]['archive_sha256'],
                'content_sha256' => $components[$component]['content_sha256'],
                'manifest_sha256' => $components[$component]['manifest_sha256'],
                'root' => $components[$component]['root'],
            ];
        }
        $targetInstallPath = $output . '/archives/target-install.tar';
        $this->publish($targetInstallPath, DeterministicArchive::encode($this->targetArchiveFiles($output)), 0644);
        ksort($target, SORT_STRING);
        $candidateSet = [
            'format' => 'duo-candidate-release-set/v1',
            'candidate_sha' => $candidate,
            'components' => $target,
            'overlay_roots' => [],
            'target_install_sha256' => $this->digest($targetInstallPath),
        ];
        $candidateSetPath = $output . '/manifests/candidate-release-set.json';
        $this->publish($candidateSetPath, $this->canonical($candidateSet) . "\n", 0644);

        $receipt = [
            'format' => self::FORMAT,
            'state' => 'pass',
            'candidate_sha' => $candidate,
            'candidate_dirty' => $this->gitStatus() !== '',
            'build_version' => '1',
            'builder_sha256' => $this->builderDigest(),
            'builder_php_sha256' => $this->digest(PHP_BINARY),
            'builder_php_version' => PHP_VERSION,
            'dependency_lock_sha256' => $this->digest($this->root . '/composer.lock'),
            'components' => $components,
            'candidate_release_set' => [
                'path' => 'manifests/candidate-release-set.json',
                'sha256' => $this->digest($candidateSetPath),
            ],
            'target_install' => [
                'path' => 'archives/target-install.tar',
                'sha256' => $this->digest($targetInstallPath),
            ],
        ];
        $this->publish($output . '/build-receipt.json', $this->canonical($receipt) . "\n", 0644);
        return $receipt;
    }

    /** @return array<string,mixed> */
    public function verify(string $output, bool $requireClean = true): array
    {
        if ($requireClean && $this->gitStatus() !== '') {
            throw new CatalogException('payload verification requires a clean Git worktree');
        }
        $output = $this->safeOutput($output);
        $receipt = $this->readJson($output . '/build-receipt.json');
        if (($receipt['format'] ?? null) !== self::FORMAT
            || ($receipt['state'] ?? null) !== 'pass'
            || ($receipt['candidate_sha'] ?? null) !== $this->candidateSha()
            || ($receipt['candidate_dirty'] ?? null) !== ($this->gitStatus() !== '')
            || ($receipt['builder_sha256'] ?? null) !== $this->builderDigest()
            || ($receipt['builder_php_sha256'] ?? null) !== $this->digest(PHP_BINARY)
            || ($receipt['builder_php_version'] ?? null) !== PHP_VERSION
            || ($receipt['dependency_lock_sha256'] ?? null) !== $this->digest($this->root . '/composer.lock')) {
            throw new CatalogException('build receipt does not bind this clean candidate and builder');
        }
        $components = $receipt['components'] ?? null;
        if (!is_array($components) || array_keys($components) !== ['agent', 'declarations', 'host-cli', 'recovery']) {
            throw new CatalogException('build receipt has an unexpected component set');
        }
        foreach ($components as $component => $binding) {
            if (!is_string($component) || !is_array($binding)) {
                throw new CatalogException('build receipt component binding is malformed');
            }
            $this->verifyComponent($output, $component, $this->stringKeyed($binding, 'component binding'));
        }
        $setBinding = $receipt['candidate_release_set'] ?? null;
        if (!is_array($setBinding)
            || ($setBinding['path'] ?? null) !== 'manifests/candidate-release-set.json'
            || !is_string($setBinding['sha256'] ?? null)
            || $setBinding['sha256'] !== $this->digest($output . '/manifests/candidate-release-set.json')) {
            throw new CatalogException('candidate release-set digest mismatch');
        }
        $set = $this->readJson($output . '/manifests/candidate-release-set.json');
        if (($set['format'] ?? null) !== 'duo-candidate-release-set/v1'
            || ($set['candidate_sha'] ?? null) !== $this->candidateSha()
            || ($set['overlay_roots'] ?? null) !== []
            || ($set['target_install_sha256'] ?? null) !== $this->digest($output . '/archives/target-install.tar')
            || !is_array($set['components'] ?? null)
            || array_keys($set['components']) !== ['agent', 'declarations', 'recovery']) {
            throw new CatalogException('candidate release set is malformed or admits review overlays');
        }
        $targetBinding = $receipt['target_install'] ?? null;
        if (!is_array($targetBinding)
            || ($targetBinding['path'] ?? null) !== 'archives/target-install.tar'
            || ($targetBinding['sha256'] ?? null) !== $this->digest($output . '/archives/target-install.tar')
            || $this->digest($output . '/archives/target-install.tar') !== 'sha256:' . hash('sha256', DeterministicArchive::encode($this->targetArchiveFiles($output)))) {
            throw new CatalogException('target-install archive disagrees with the manifested payload roots');
        }
        $this->smokeOutsideCheckout($output);
        return [
            'format' => 'duo-payload-dist-check/v1',
            'state' => 'pass',
            'candidate_sha' => $this->candidateSha(),
            'build_receipt_sha256' => $this->digest($output . '/build-receipt.json'),
            'candidate_release_set_sha256' => $setBinding['sha256'],
            'components' => array_keys($components),
            'source_independent_smoke' => 'pass',
        ];
    }

    /** @return array<string,mixed> */
    public function reproducibility(): array
    {
        if ($this->gitStatus() !== '') {
            throw new CatalogException('reproducibility check requires a clean Git worktree');
        }
        $first = sys_get_temp_dir() . '/duo-build-a-' . bin2hex(random_bytes(8));
        $second = sys_get_temp_dir() . '/duo-build-b-' . bin2hex(random_bytes(8));
        try {
            $this->build($first);
            $this->build($second);
            $left = $this->treeDigests($first);
            $right = $this->treeDigests($second);
            if ($left !== $right) {
                throw new CatalogException('candidate builds differ across absolute roots');
            }
            return [
                'format' => 'duo-payload-reproducibility/v1',
                'state' => 'pass',
                'candidate_sha' => $this->candidateSha(),
                'tree_sha256' => 'sha256:' . hash('sha256', $this->canonical($left)),
                'file_count' => count($left),
                'absolute_roots_differed' => true,
            ];
        } finally {
            $this->removeTree($first);
            $this->removeTree($second);
        }
    }

    /** @return array<string,list<BuildFile>> */
    private function componentSources(): array
    {
        $tracked = $this->trackedFiles();
        $components = ['agent' => [], 'declarations' => [], 'host-cli' => [], 'recovery' => []];
        foreach ($tracked as $source) {
            $selected = null;
            if ($source === 'agent/duo-loader.php') {
                $selected = ['agent', 'duo-loader.php'];
            } elseif ($source === 'agent/duo.php') {
                $selected = ['agent', 'duo/duo.php'];
            } elseif (preg_match('~^agent/src/.+\.php$~D', $source) === 1) {
                $selected = ['agent', 'duo/' . substr($source, strlen('agent/'))];
            } elseif ($source === 'cli/duo' || preg_match('~^cli/src/.+\.php$~D', $source) === 1) {
                $selected = ['host-cli', $source];
            } elseif (preg_match('~^recovery/[^/]+\.php$~D', $source) === 1) {
                $selected = ['recovery', substr($source, strlen('recovery/'))];
            } elseif (preg_match('~^docs/contracts/(?:[^/]+\.json|commands/(?:agent|host)/[^/]+\.json)$~D', $source) === 1) {
                $selected = ['declarations', 'contracts/' . substr($source, strlen('docs/contracts/'))];
            }
            if ($selected === null) {
                continue;
            }
            [$component, $destination] = $selected;
            $path = $this->root . '/' . $source;
            if (!is_file($path) || is_link($path)) {
                throw new CatalogException("artifact input is absent, non-regular, or a symlink: $source");
            }
            $bytes = file_get_contents($path);
            if (!is_string($bytes)) {
                throw new CatalogException("cannot read artifact input: $source");
            }
            $mode = in_array($source, ['cli/duo', 'recovery/rollback-control.php'], true) ? 0755 : 0644;
            $components[$component][] = ['source' => $source, 'path' => $destination, 'bytes' => $bytes, 'mode' => $mode];
            // The current host CLI has explicit host-local validation and
            // planning paths into agent/src, including dynamic file closure.
            // Copy that complete first-party source prefix into the separately
            // manifested host artifact instead of assuming a sibling checkout.
            if ($component === 'agent' && str_starts_with($source, 'agent/src/')) {
                $components['host-cli'][] = ['source' => $source, 'path' => $source, 'bytes' => $bytes, 'mode' => 0644];
            }
        }
        foreach ($components as $component => &$files) {
            usort($files, static fn(array $left, array $right): int => strcmp($left['path'], $right['path']));
            if ($files === []) {
                throw new CatalogException("artifact allowlist selected no files for $component");
            }
        }
        unset($files);
        ksort($components, SORT_STRING);
        return $components;
    }

    /**
     * @param list<BuildFile> $files
     * @return array<string,mixed>
     */
    private function writeComponent(string $output, string $component, array $files, string $candidate): array
    {
        $this->assertSyntaxFloor($component, $files);
        $root = $component === 'host-cli' ? 'host' : 'payload/' . $component;
        $manifestFiles = [];
        $archiveFiles = [];
        foreach ($files as $file) {
            $destination = $output . '/' . $root . '/' . $file['path'];
            $this->publish($destination, $file['bytes'], $file['mode']);
            $manifestFiles[] = [
                'path' => $file['path'],
                'sha256' => 'sha256:' . hash('sha256', $file['bytes']),
                'size' => strlen($file['bytes']),
                'mode' => sprintf('%04o', $file['mode']),
            ];
            $archiveFiles[] = [
                'path' => $root . '/' . $file['path'],
                'bytes' => $file['bytes'],
                'mode' => $file['mode'],
            ];
        }
        $contentDigest = 'sha256:' . hash('sha256', $this->canonical($manifestFiles));
        $archivePath = $output . '/archives/' . $component . '.tar';
        $this->publish($archivePath, DeterministicArchive::encode($archiveFiles), 0644);
        $manifest = [
            'format' => 'duo-artifact-manifest/v1',
            'component' => $component,
            'source_commit' => $candidate,
            'build_version' => '1',
            'root' => $root,
            'content_sha256' => $contentDigest,
            'archive_sha256' => $this->digest($archivePath),
            'compatibility' => self::COMPATIBILITY[$component],
            'files' => $manifestFiles,
        ];
        $manifestPath = $output . '/manifests/' . $component . '.json';
        $this->publish($manifestPath, $this->canonical($manifest) . "\n", 0644);
        return [
            'root' => $root,
            'archive_path' => 'archives/' . $component . '.tar',
            'archive_sha256' => $manifest['archive_sha256'],
            'manifest_path' => 'manifests/' . $component . '.json',
            'manifest_sha256' => $this->digest($manifestPath),
            'content_sha256' => $contentDigest,
            'file_count' => count($files),
        ];
    }

    /** @param array<string,mixed> $binding */
    private function verifyComponent(string $output, string $component, array $binding): void
    {
        $expectedRoot = $component === 'host-cli' ? 'host' : 'payload/' . $component;
        foreach (['archive_path', 'archive_sha256', 'manifest_path', 'manifest_sha256', 'content_sha256'] as $key) {
            if (!is_string($binding[$key] ?? null)) {
                throw new CatalogException("component $component has a malformed $key binding");
            }
        }
        if (($binding['root'] ?? null) !== $expectedRoot
            || $binding['archive_path'] !== 'archives/' . $component . '.tar'
            || $binding['manifest_path'] !== 'manifests/' . $component . '.json') {
            throw new CatalogException("component $component uses an unexpected artifact path");
        }
        if ($binding['archive_sha256'] !== $this->digest($output . '/' . $binding['archive_path'])
            || $binding['manifest_sha256'] !== $this->digest($output . '/' . $binding['manifest_path'])) {
            throw new CatalogException("component $component artifact digest mismatch");
        }
        $manifest = $this->readJson($output . '/' . $binding['manifest_path']);
        if (($manifest['format'] ?? null) !== 'duo-artifact-manifest/v1'
            || ($manifest['component'] ?? null) !== $component
            || ($manifest['source_commit'] ?? null) !== $this->candidateSha()
            || ($manifest['root'] ?? null) !== $expectedRoot
            || ($manifest['content_sha256'] ?? null) !== $binding['content_sha256']
            || ($manifest['archive_sha256'] ?? null) !== $binding['archive_sha256']
            || !is_array($manifest['compatibility'] ?? null)
            || $this->canonical($manifest['compatibility']) !== $this->canonical(self::COMPATIBILITY[$component])
            || !is_array($manifest['files'] ?? null)
            || !array_is_list($manifest['files'])) {
            throw new CatalogException("component $component manifest is malformed");
        }
        $seen = [];
        $archiveFiles = [];
        foreach ($manifest['files'] as $file) {
            if (!is_array($file)
                || !is_string($file['path'] ?? null)
                || !is_string($file['sha256'] ?? null)
                || !is_int($file['size'] ?? null)
                || !is_string($file['mode'] ?? null)
                || isset($seen[$file['path']])) {
                throw new CatalogException("component $component file manifest is malformed");
            }
            $seen[$file['path']] = true;
            $actual = $output . '/' . $expectedRoot . '/' . $file['path'];
            if (!is_file($actual) || is_link($actual)
                || filesize($actual) !== $file['size']
                || $this->digest($actual) !== $file['sha256']
                || sprintf('%04o', fileperms($actual) & 0777) !== $file['mode']) {
                throw new CatalogException("component $component file bytes or mode disagree: {$file['path']}");
            }
            if (preg_match('~(?:^|/)(?:vendor|tests?|\.git|\.cache)(?:/|$)~iD', $file['path']) === 1) {
                throw new CatalogException("component $component contains forbidden runtime membership: {$file['path']}");
            }
            $bytes = file_get_contents($actual);
            if (!is_string($bytes)) {
                throw new CatalogException("component $component file cannot be read: {$file['path']}");
            }
            $archiveFiles[] = [
                'path' => $expectedRoot . '/' . $file['path'],
                'bytes' => $bytes,
                'mode' => intval($file['mode'], 8),
            ];
        }
        $actualFiles = $this->relativeFiles($output . '/' . $expectedRoot);
        if (array_keys($seen) !== $actualFiles
            || 'sha256:' . hash('sha256', $this->canonical($manifest['files'])) !== $binding['content_sha256']) {
            throw new CatalogException("component $component membership or content digest mismatch");
        }
        if ('sha256:' . hash('sha256', DeterministicArchive::encode($archiveFiles)) !== $binding['archive_sha256']) {
            throw new CatalogException("component $component archive bytes disagree with manifested files");
        }
        $this->assertSyntaxFloor($component, $archiveFiles);
    }

    /** @param list<array{path:string,bytes:string,mode:int}> $files */
    private function assertSyntaxFloor(string $component, array $files): void
    {
        if ($component === 'declarations') {
            return;
        }
        if (!class_exists(\PhpParser\ParserFactory::class) || !class_exists(\PhpParser\PhpVersion::class)) {
            throw new CatalogException('nikic/php-parser from the locked development toolchain is required for artifact syntax contracts');
        }
        $floor = $component === 'agent' ? '8.2' : '8.1';
        $parser = (new \PhpParser\ParserFactory())->createForVersion(\PhpParser\PhpVersion::fromString($floor));
        $loaderParser = $component === 'agent'
            ? (new \PhpParser\ParserFactory())->createForVersion(\PhpParser\PhpVersion::fromString('8.0'))
            : null;
        foreach ($files as $file) {
            if (!str_ends_with($file['path'], '.php') && !str_ends_with($file['path'], 'cli/duo')) {
                continue;
            }
            try {
                $parser->parse($file['bytes']);
                if ($loaderParser !== null && str_ends_with($file['path'], 'duo-loader.php')) {
                    $loaderParser->parse($file['bytes']);
                }
            } catch (\PhpParser\Error $exception) {
                throw new CatalogException("component $component file {$file['path']} violates its PHP syntax floor: {$exception->getMessage()}");
            }
        }
    }

    private function smokeOutsideCheckout(string $output): void
    {
        $temporary = sys_get_temp_dir() . '/duo-dist-smoke-' . bin2hex(random_bytes(8));
        try {
            $this->copyTree($output, $temporary);
            $this->run([PHP_BINARY, $temporary . '/host/cli/duo', '--help'], $temporary);
            $this->run([
                PHP_BINARY,
                '-r',
                'define("ABSPATH", __DIR__ . "/"); require $argv[1];',
                $temporary . '/payload/agent/duo/duo.php',
            ], $temporary);
            foreach ($this->relativeFiles($temporary . '/payload/recovery') as $file) {
                $this->run([PHP_BINARY, '-l', $temporary . '/payload/recovery/' . $file], $temporary);
            }
            foreach ($this->relativeFiles($temporary . '/payload/declarations') as $file) {
                $bytes = file_get_contents($temporary . '/payload/declarations/' . $file);
                if (!is_string($bytes)) {
                    throw new CatalogException("cannot read declaration during dist smoke: $file");
                }
                json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
            }
        } catch (\JsonException $exception) {
            throw new CatalogException('dist declaration is not valid JSON: ' . $exception->getMessage());
        } finally {
            $this->removeTree($temporary);
        }
    }

    /** @return list<BuildFile> */
    private function targetArchiveFiles(string $output): array
    {
        $files = [];
        foreach ($this->relativeFiles($output . '/payload') as $path) {
            $absolute = $output . '/payload/' . $path;
            $bytes = file_get_contents($absolute);
            $mode = fileperms($absolute);
            if (!is_string($bytes) || !is_int($mode)) {
                throw new CatalogException("cannot read target-install member: $path");
            }
            $files[] = [
                'source' => 'payload/' . $path,
                'path' => 'payload/' . $path,
                'bytes' => $bytes,
                'mode' => $mode & 0777,
            ];
        }
        return $files;
    }

    /** @return list<string> */
    private function trackedFiles(): array
    {
        $bytes = $this->capture(['git', 'ls-files', '-z'], $this->root);
        $files = array_values(array_filter(explode("\0", $bytes), static fn(string $path): bool => $path !== ''));
        sort($files, SORT_STRING);
        return $files;
    }

    /** @return array<string,string> */
    private function treeDigests(string $root): array
    {
        $digests = [];
        foreach ($this->relativeFiles($root) as $path) {
            $digests[$path] = $this->digest($root . '/' . $path);
        }
        return $digests;
    }

    /** @return list<string> */
    private function relativeFiles(string $root): array
    {
        if (!is_dir($root) || is_link($root)) {
            throw new CatalogException("artifact root is absent or unsafe: $root");
        }
        $files = $this->walkFiles($root, '');
        sort($files, SORT_STRING);
        return $files;
    }

    /** @return list<string> */
    private function walkFiles(string $root, string $relative): array
    {
        $directory = $relative === '' ? $root : $root . '/' . $relative;
        $entries = scandir($directory);
        if (!is_array($entries)) {
            throw new CatalogException("cannot enumerate artifact directory: $directory");
        }
        $files = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $childRelative = $relative === '' ? $entry : $relative . '/' . $entry;
            $path = $root . '/' . $childRelative;
            if (is_link($path)) {
                throw new CatalogException("artifact tree contains a symlink: $path");
            }
            if (is_dir($path)) {
                array_push($files, ...$this->walkFiles($root, $childRelative));
            } elseif (is_file($path)) {
                $files[] = $childRelative;
            } else {
                throw new CatalogException("artifact tree contains a non-regular file: $path");
            }
        }
        return $files;
    }

    private function copyTree(string $source, string $destination): void
    {
        foreach ($this->relativeFiles($source) as $path) {
            $bytes = file_get_contents($source . '/' . $path);
            if (!is_string($bytes)) {
                throw new CatalogException("cannot read dist smoke input: $path");
            }
            $this->publish($destination . '/' . $path, $bytes, fileperms($source . '/' . $path) & 0777);
        }
    }

    private function builderDigest(): string
    {
        return 'sha256:' . hash(
            'sha256',
            (string) file_get_contents(__FILE__) . "\0"
                . (string) file_get_contents(__DIR__ . '/DeterministicArchive.php') . "\0"
                . $this->digest(PHP_BINARY) . "\0" . PHP_VERSION . "\0"
                . $this->digest($this->root . '/composer.lock'),
        );
    }

    private function candidateSha(): string
    {
        $sha = trim($this->capture(['git', 'rev-parse', 'HEAD'], $this->root));
        if (preg_match('/^[a-f0-9]{40}$/D', $sha) !== 1) {
            throw new CatalogException('candidate commit is not a full SHA');
        }
        return $sha;
    }

    private function gitStatus(): string
    {
        return $this->capture(['git', 'status', '--porcelain=v1', '--untracked-files=all'], $this->root);
    }

    private function safeOutput(string $output): string
    {
        $absolute = str_starts_with($output, '/') ? $output : $this->root . '/' . $output;
        $parent = realpath(dirname($absolute));
        if ($parent === false) {
            $ancestor = dirname($absolute);
            while (!is_dir($ancestor)) {
                $next = dirname($ancestor);
                if ($next === $ancestor) {
                    throw new CatalogException('build output has no existing ancestor');
                }
                $ancestor = $next;
            }
            $parent = realpath($ancestor);
        }
        if (!is_string($parent) || $absolute === $this->root || $absolute === '/') {
            throw new CatalogException('unsafe build output path');
        }
        return rtrim($absolute, '/');
    }

    private function publish(string $path, string $bytes, int $mode): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new CatalogException("cannot create artifact directory: $directory");
        }
        if (is_link($path) || file_put_contents($path, $bytes) !== strlen($bytes) || !chmod($path, $mode)) {
            throw new CatalogException("cannot publish artifact: $path");
        }
    }

    /** @return array<string,mixed> */
    private function readJson(string $path): array
    {
        $bytes = file_get_contents($path);
        if (!is_string($bytes)) {
            throw new CatalogException("cannot read JSON artifact: $path");
        }
        try {
            $decoded = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new CatalogException("invalid JSON artifact: $path: {$exception->getMessage()}");
        }
        if (!is_array($decoded)) {
            throw new CatalogException("JSON artifact is not an object: $path");
        }
        return $this->stringKeyed($decoded, "JSON artifact $path");
    }

    /**
     * @param array<mixed,mixed> $value
     * @return array<string,mixed>
     */
    private function stringKeyed(array $value, string $label): array
    {
        $normalized = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new CatalogException("$label must be an object");
            }
            $normalized[$key] = $item;
        }
        return $normalized;
    }

    private function digest(string $path): string
    {
        $digest = is_file($path) && !is_link($path) ? hash_file('sha256', $path) : false;
        if (!is_string($digest)) {
            throw new CatalogException("cannot digest artifact: $path");
        }
        return 'sha256:' . $digest;
    }

    private function canonical(mixed $value): string
    {
        if (is_array($value)) {
            if (array_is_list($value)) {
                return '[' . implode(',', array_map(fn(mixed $item): string => $this->canonical($item), $value)) . ']';
            }
            ksort($value, SORT_STRING);
            $pairs = [];
            foreach ($value as $key => $item) {
                $pairs[] = json_encode((string) $key, JSON_THROW_ON_ERROR) . ':' . $this->canonical($item);
            }
            return '{' . implode(',', $pairs) . '}';
        }
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /** @param list<string> $command */
    private function run(array $command, string $cwd): void
    {
        $this->capture($command, $cwd);
    }

    /** @param list<string> $command */
    private function capture(array $command, string $cwd): string
    {
        $process = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $cwd,
            ['PATH' => (string) getenv('PATH'), 'LC_ALL' => 'C', 'LANG' => 'C', 'TZ' => 'UTC'],
            ['bypass_shell' => true],
        );
        if (!is_resource($process)) {
            throw new CatalogException('cannot start subprocess: ' . implode(' ', $command));
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        if ($exit !== 0 || !is_string($stdout)) {
            throw new CatalogException('subprocess failed: ' . implode(' ', $command) . ': ' . trim((string) $stderr));
        }
        return $stdout;
    }

    private function removeTree(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        if (!is_dir($path) || is_link($path)) {
            if (!unlink($path)) {
                throw new CatalogException("cannot remove artifact path: $path");
            }
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeTree($path . '/' . $entry);
            }
        }
        if (!rmdir($path)) {
            throw new CatalogException("cannot remove artifact directory: $path");
        }
    }
}
