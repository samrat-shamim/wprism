<?php

declare(strict_types=1);

namespace Duo\EngineeringPlatform;

/**
 * @phpstan-import-type CatalogAggregate from Catalog
 * @phpstan-type SelectionDocument array{
 *     format:string,
 *     selector_version:string,
 *     authority:string,
 *     state:string,
 *     reason:string,
 *     base_sha:?string,
 *     head_sha:?string,
 *     merge_base_sha:?string,
 *     catalog_sha256:string,
 *     changed_paths:list<string>,
 *     changed_paths_sha256:string,
 *     selected_suite_ids:list<string>
 * }
 */
final class Selection
{
    /** @param CatalogAggregate $catalog */
    public function __construct(
        private readonly string $root,
        private readonly array $catalog,
    ) {}

    /** @return SelectionDocument */
    public function changed(string $baseSha, string $headSha): array
    {
        $this->assertCommit($baseSha, 'base');
        $this->assertCommit($headSha, 'head');
        if (trim($this->capture(['git', 'rev-parse', '--is-shallow-repository'])) !== 'false') {
            throw new CatalogException('changed selection refuses shallow history');
        }
        $mergeBase = trim($this->capture(['git', 'merge-base', $baseSha, $headSha]));
        if (preg_match('/^[a-f0-9]{40}$/D', $mergeBase) !== 1) {
            throw new CatalogException('base and head do not have a valid merge base');
        }
        $changed = array_values(array_filter(
            explode("\0", $this->capture([
                'git', 'diff', '--name-only', '-z', '--diff-filter=ACDMRTUXB', $mergeBase, $headSha, '--',
            ])),
            static fn(string $path): bool => $path !== '',
        ));
        sort($changed, SORT_STRING);
        if (count($changed) !== count(array_unique($changed))) {
            throw new CatalogException('Git returned duplicate changed paths');
        }

        $suiteIds = [];
        $unmapped = [];
        foreach ($changed as $path) {
            $mapped = false;
            foreach ($this->catalog['suites'] as $suite) {
                foreach ($suite['covered_paths'] as $pattern) {
                    if ($this->matches($pattern, $path)) {
                        $suiteIds[$suite['id']] = true;
                        $mapped = true;
                        break;
                    }
                }
            }
            if (!$mapped) {
                $unmapped[] = $path;
            }
        }
        $reason = 'changed paths mapped exactly to declared suite coverage';
        if ($unmapped !== []) {
            foreach ($this->profileSuiteIds('legacy-offline-compatibility') as $suiteId) {
                $suiteIds[$suiteId] = true;
            }
            $reason = 'one or more changed paths were unmapped; selection broadened to the complete offline compatibility profile';
        }
        $selected = array_keys($suiteIds);
        sort($selected, SORT_STRING);
        $state = $changed === [] ? 'not_applicable' : 'selected';
        if ($state === 'not_applicable') {
            $reason = 'base and head resolve to an empty merge-base diff';
        }
        return $this->document(
            'changed-paths/v1',
            'advisory',
            $state,
            $reason,
            $baseSha,
            $headSha,
            $mergeBase,
            $changed,
            $selected,
        );
    }

    /**
     * @param list<string> $ids
     * @return SelectionDocument
     */
    public function explicit(string $kind, array $ids): array
    {
        if (!in_array($kind, ['integration', 'conformance'], true)) {
            throw new CatalogException("unknown explicit selection kind: $kind");
        }
        if ($ids === [] || count($ids) !== count(array_unique($ids))) {
            throw new CatalogException('explicit suite selection is empty or contains duplicates');
        }
        $suiteMap = [];
        foreach ($this->catalog['suites'] as $suite) {
            $suiteMap[$suite['id']] = $suite;
        }
        $selected = [];
        foreach ($ids as $id) {
            if (preg_match('/^[a-z0-9][a-z0-9.-]*$/D', $id) !== 1) {
                throw new CatalogException("invalid suite or subject id: $id");
            }
            $suiteId = $kind === 'conformance' ? 'legacy-conformance-' . $id : $id;
            if (!isset($suiteMap[$suiteId])) {
                throw new CatalogException("unknown suite or subject: $id");
            }
            if ($kind === 'conformance' && $suiteMap[$suiteId]['layer'] !== 'conformance') {
                throw new CatalogException("suite is not conformance: $id");
            }
            $selected[] = $suiteId;
        }
        sort($selected, SORT_STRING);
        return $this->document(
            'explicit-diagnostic/v1',
            'diagnostic',
            'selected',
            'operator supplied an explicit non-authorizing diagnostic selection',
            null,
            null,
            null,
            [],
            $selected,
        );
    }

    /** @return SelectionDocument */
    public function load(string $path): array
    {
        $absolute = self::artifactPath($this->root, $path);
        $bytes = file_get_contents($absolute);
        if (!is_string($bytes)) {
            throw new CatalogException('selection receipt is unavailable');
        }
        try {
            $row = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new CatalogException('selection receipt is invalid JSON: ' . $exception->getMessage());
        }
        if (!is_array($row)) {
            throw new CatalogException('selection receipt is not an object');
        }
        $expectedKeys = [
            'authority', 'base_sha', 'catalog_sha256', 'changed_paths', 'changed_paths_sha256',
            'format', 'head_sha', 'merge_base_sha', 'reason', 'selected_suite_ids', 'selector_version', 'state',
        ];
        $keys = array_keys($row);
        sort($keys, SORT_STRING);
        if ($keys !== $expectedKeys) {
            throw new CatalogException('selection receipt has unknown or missing fields');
        }
        $format = $row['format'] ?? null;
        $version = $row['selector_version'] ?? null;
        $authority = $row['authority'] ?? null;
        $state = $row['state'] ?? null;
        $reason = $row['reason'] ?? null;
        $base = $row['base_sha'] ?? null;
        $head = $row['head_sha'] ?? null;
        $mergeBase = $row['merge_base_sha'] ?? null;
        $catalogDigest = $row['catalog_sha256'] ?? null;
        $paths = $row['changed_paths'] ?? null;
        $pathsDigest = $row['changed_paths_sha256'] ?? null;
        $ids = $row['selected_suite_ids'] ?? null;
        if ($format !== 'duo-test-selection/v1'
            || !is_string($version) || !in_array($version, ['changed-paths/v1', 'explicit-diagnostic/v1'], true)
            || !is_string($authority) || !in_array($authority, ['advisory', 'diagnostic'], true)
            || !is_string($state) || !in_array($state, ['selected', 'not_applicable'], true)
            || !is_string($reason) || $reason === ''
            || !$this->nullableSha($base) || !$this->nullableSha($head) || !$this->nullableSha($mergeBase)
            || !is_string($catalogDigest) || preg_match('/^sha256:[a-f0-9]{64}$/D', $catalogDigest) !== 1
            || !$this->stringList($paths, true) || !$this->stringList($ids, true)
            || !is_string($pathsDigest) || preg_match('/^sha256:[a-f0-9]{64}$/D', $pathsDigest) !== 1) {
            throw new CatalogException('selection receipt has invalid fields');
        }
        /** @var list<string> $paths */
        /** @var list<string> $ids */
        if ('sha256:' . hash('sha256', $this->canonical($paths)) !== $pathsDigest) {
            throw new CatalogException('selection changed-path digest does not match its paths');
        }
        if ($catalogDigest !== $this->catalogDigest()) {
            throw new CatalogException('selection receipt catalog digest is stale');
        }
        $suiteIds = array_column($this->catalog['suites'], 'id');
        foreach ($ids as $id) {
            if (!in_array($id, $suiteIds, true)) {
                throw new CatalogException("selection names an unknown suite: $id");
            }
        }
        if (($state === 'selected') === ($ids === [])) {
            throw new CatalogException('selection state disagrees with the selected suite set');
        }
        if ($version === 'changed-paths/v1'
            && ($authority !== 'advisory' || $base === null || $head === null || $mergeBase === null)) {
            throw new CatalogException('changed-path selection lacks resolved commit identity');
        }
        if ($version === 'explicit-diagnostic/v1'
            && ($authority !== 'diagnostic' || $base !== null || $head !== null || $mergeBase !== null || $paths !== [])) {
            throw new CatalogException('explicit selection claims changed-path authority');
        }
        /** @var SelectionDocument $row */
        return $row;
    }

    /** @param SelectionDocument $document */
    public function publish(array $document, string $path): void
    {
        $absolute = self::artifactPath($this->root, $path);
        if (!is_dir(dirname($absolute)) && !mkdir(dirname($absolute), 0700, true) && !is_dir(dirname($absolute))) {
            throw new CatalogException('cannot create selection output directory');
        }
        $bytes = $this->canonical($document) . "\n";
        $temporary = tempnam(dirname($absolute), '.selection.');
        if (!is_string($temporary)) {
            throw new CatalogException('cannot create selection temporary file');
        }
        try {
            if (file_put_contents($temporary, $bytes) !== strlen($bytes) || !chmod($temporary, 0600) || !rename($temporary, $absolute)) {
                throw new CatalogException('cannot publish selection receipt');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    /**
     * @param list<string> $paths
     * @param list<string> $suiteIds
     * @return SelectionDocument
     */
    private function document(
        string $version,
        string $authority,
        string $state,
        string $reason,
        ?string $base,
        ?string $head,
        ?string $mergeBase,
        array $paths,
        array $suiteIds,
    ): array {
        return [
            'format' => 'duo-test-selection/v1',
            'selector_version' => $version,
            'authority' => $authority,
            'state' => $state,
            'reason' => $reason,
            'base_sha' => $base,
            'head_sha' => $head,
            'merge_base_sha' => $mergeBase,
            'catalog_sha256' => $this->catalogDigest(),
            'changed_paths' => $paths,
            'changed_paths_sha256' => 'sha256:' . hash('sha256', $this->canonical($paths)),
            'selected_suite_ids' => $suiteIds,
        ];
    }

    /** @return list<string> */
    private function profileSuiteIds(string $profileId): array
    {
        foreach ($this->catalog['profiles'] as $profile) {
            if ($profile['id'] === $profileId) {
                return $profile['suite_ids'];
            }
        }
        throw new CatalogException("fallback profile is absent: $profileId");
    }

    private function assertCommit(string $sha, string $label): void
    {
        if (preg_match('/^[a-f0-9]{40}$/D', $sha) !== 1) {
            throw new CatalogException("$label SHA must be 40 lowercase hexadecimal characters");
        }
        $this->capture(['git', 'cat-file', '-e', $sha . '^{commit}']);
    }

    /** @param list<string> $command */
    private function capture(array $command): string
    {
        $process = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->root,
            ['LC_ALL' => 'C', 'LANG' => 'C', 'PATH' => (string) getenv('PATH')],
            ['bypass_shell' => true],
        );
        if (!is_resource($process)) {
            throw new CatalogException('cannot start Git for selection');
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        if ($exit !== 0 || !is_string($stdout)) {
            $detail = is_string($stderr) ? trim($stderr) : 'unknown Git failure';
            throw new CatalogException('Git selection command failed: ' . $detail);
        }
        return $stdout;
    }

    private function catalogDigest(): string
    {
        return 'sha256:' . hash('sha256', (new Catalog($this->root))->encode($this->catalog));
    }

    private function matches(string $pattern, string $path): bool
    {
        $expression = preg_quote($pattern, '~');
        $expression = str_replace(['\\*\\*', '\\*'], ['.*', '[^/]*'], $expression);
        return preg_match('~^' . $expression . '$~D', $path) === 1;
    }

    private function nullableSha(mixed $value): bool
    {
        return $value === null || (is_string($value) && preg_match('/^[a-f0-9]{40}$/D', $value) === 1);
    }

    private function stringList(mixed $value, bool $allowEmpty): bool
    {
        if (!is_array($value) || !array_is_list($value) || (!$allowEmpty && $value === [])) {
            return false;
        }
        $seen = [];
        foreach ($value as $item) {
            if (!is_string($item) || $item === '' || str_contains($item, "\0") || isset($seen[$item])) {
                return false;
            }
            $seen[$item] = true;
        }
        return true;
    }

    private function canonical(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private static function artifactPath(string $root, string $path): string
    {
        if (preg_match('~^artifacts/(?:[A-Za-z0-9._-]+/)*[A-Za-z0-9._-]+$~D', $path) !== 1 || str_contains($path, '..')) {
            throw new CatalogException('selection path must be a safe artifacts-relative path');
        }
        return $root . '/' . $path;
    }
}
