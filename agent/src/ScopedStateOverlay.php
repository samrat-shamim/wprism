<?php
namespace Duo;

/**
 * Contract-bound state projection shared by scoped capture and refresh.
 *
 * A scope contract is evidence, not mutation authority. The command using
 * this projection still owns its target transaction and deletion gates. This
 * class only enforces the filesystem half: selected identities may be
 * replaced, every excluded live row/tombstone is copied byte-for-byte from
 * the associated source revision, and the resulting closure cannot escape
 * the identities named by that immutable contract.
 */
final class ScopedStateOverlay {
    /** @return array<string,mixed> */
    public static function load_contract(string $path): array {
        if ($path === '' || str_contains($path, "\0") || !is_file($path)) {
            throw new \RuntimeException('duo: --scope-contract must name a readable canonical contract file');
        }
        $size = filesize($path);
        if (!is_int($size) || $size <= 0 || $size > 4 * 1024 * 1024) {
            throw new \RuntimeException('duo: --scope-contract must be a non-empty file no larger than 4 MiB');
        }
        $decoded = Canon::decode(Canon::read_file($path));
        if (!is_array($decoded)) {
            throw new \RuntimeException('duo: --scope-contract must contain one JSON object');
        }
        return ScopeContract::from_array($decoded);
    }

    /**
     * Reconstruct the complete immutable contract from the compact proof a
     * host transport can safely carry to the target command.
     *
     * @param list<string> $selectors
     * @return array<string,mixed>
     */
    public static function resolve_request(
        CompiledRepository $compiled,
        Policy $policy,
        array $selectors,
        string $scopeHash
    ): array {
        if (preg_match('/^[a-f0-9]{64}$/D', $scopeHash) !== 1) {
            throw new \RuntimeException('duo: scoped operation has no valid scope_hash');
        }
        $contract = ScopeContract::resolve($compiled, $policy, $selectors);
        if (!hash_equals($scopeHash, (string) $contract['scope_hash'])) {
            throw new \RuntimeException(
                'duo: scoped operation contract is stale, tampered, or associated with a different repository/policy'
            );
        }
        return $contract;
    }

    /** @return list<string> */
    public static function selected_identities(array $contract): array {
        ScopeContract::from_array($contract);
        $selected = [];
        foreach (['roots', 'closure'] as $field) {
            foreach ((array) ($contract['live'][$field] ?? []) as $row) {
                $selected[(string) ($row['entity'] ?? '')] = true;
            }
        }
        foreach ((array) ($contract['tombstones'] ?? []) as $row) {
            $selected[(string) ($row['uuid'] ?? '')] = true;
        }
        unset($selected['']);
        $out = array_keys($selected);
        sort($out, SORT_STRING);
        return $out;
    }

    public static function is_all(array $contract): bool {
        ScopeContract::from_array($contract);
        return ($contract['selectors'] ?? null) === [ScopeClosure::SELECTOR_ALL];
    }

    /**
     * Projection entrypoint after ScopeContract::assert_associated().
     *
     * @param list<array<string,mixed>> $observedEntities
     * @param list<array<string,mixed>> $selectedDeletions
     * @return array{entities:list<array<string,mixed>>,deletions:list<array<string,mixed>>}
     */
    public static function project_capture_associated(
        CompiledRepository $previous,
        array $contract,
        array $observedEntities,
        array $selectedDeletions
    ): array {
        ScopeContract::from_array($contract);
        $selected = array_fill_keys(self::selected_identities($contract), true);
        $live = self::index_rows($observedEntities, 'observed live');
        $deleted = self::index_rows($selectedDeletions, 'selected deletion');
        $entities = [];
        $deletions = [];

        foreach ($previous->tree() as $identity => $row) {
            $identity = (string) $identity;
            if (!isset($selected[$identity])) {
                $entities[] = self::compiled_row($identity, $row);
                continue;
            }
            if (isset($live[$identity])) {
                $entities[] = $live[$identity];
            } elseif (isset($deleted[$identity])) {
                foreach ((array) ($contract['live']['inbound'] ?? []) as $inbound) {
                    if ((string) ($inbound['target'] ?? '') === $identity) {
                        throw new \RuntimeException(
                            "duo: selected deletion '$identity' would strand an out-of-scope inbound reference"
                        );
                    }
                }
                $deletions[] = $deleted[$identity];
            } else {
                throw new \RuntimeException(
                    "duo: selected live entity '$identity' disappeared without bounded deletion evidence"
                );
            }
        }
        foreach ($previous->deletions() as $identity => $row) {
            $identity = (string) $identity;
            if (!isset($selected[$identity])) {
                $deletions[] = self::compiled_row($identity, $row, 'deletion');
                continue;
            }
            if (isset($live[$identity])) {
                throw new \RuntimeException(
                    "duo: selected tombstone '$identity' reappeared; duo-scope-contract/v1 grants no resurrection authority"
                );
            }
            if (isset($deleted[$identity])) {
                $deletions[] = $deleted[$identity];
            } else {
                throw new \RuntimeException("duo: selected tombstone '$identity' disappeared from the scoped candidate");
            }
        }

        self::assert_no_new_identities($previous, $live, $selected);
        self::assert_unique_paths(array_merge($entities, $deletions));
        usort($entities, static fn(array $a, array $b): int => (string) $a['path'] <=> (string) $b['path']);
        usort($deletions, static fn(array $a, array $b): int => (string) $a['path'] <=> (string) $b['path']);
        return ['entities' => $entities, 'deletions' => $deletions];
    }

    /**
     * Only media referenced by a selected candidate row may be added.
     * Existing repository media is content-addressed and is never removed by
     * capture, so excluded blobs remain byte-identical without appearing in
     * this returned write set.
     *
     * @param list<array<string,mixed>> $selectedEntities
     * @param array<string,array<string,mixed>> $media
     * @return array<string,array<string,mixed>>
     */
    public static function selected_media(array $selectedEntities, array $media): array {
        $out = [];
        foreach ($selectedEntities as $entity) {
            if (($entity['type'] ?? null) !== 'post' || !is_string($entity['content'] ?? null)) {
                continue;
            }
            try {
                [$front] = Canon::parse_post_file((string) $entity['content']);
            } catch (\Throwable $failure) {
                throw new \RuntimeException('duo: selected media projection received malformed canonical post state', 0, $failure);
            }
            if (($front['type'] ?? null) !== 'attachment') {
                continue;
            }
            $name = (string) ($front['media'] ?? '');
            if ($name !== '' && array_key_exists($name, $media)) {
                $out[$name] = $media[$name];
            }
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    /**
     * Build an immutable compiler view of repo/media plus candidate blobs.
     * The real repository is not changed until every staged candidate check
     * has passed.
     *
     * @param array<string,array<string,mixed>> $additions
     */
    public static function stage_candidate_media_view(string $repo, array $additions): string {
        $view = self::new_media_view();
        try {
            self::copy_repository_media($repo, $view);
            foreach ($additions as $name => $source) {
                $bytes = self::candidate_media_bytes((string) $name, $source);
                $path = $view . '/' . (string) $name;
                if (is_file($path) && !hash_equals(Canon::read_file($path), $bytes)) {
                    throw new \RuntimeException("duo: candidate media '$name' conflicts with an existing blob");
                }
                if (!is_file($path)) {
                    Canon::write_file($path, $bytes);
                }
            }
            return $view;
        } catch (\Throwable $failure) {
            self::discard_media_view($view);
            throw $failure;
        }
    }

    /**
     * Complete target observation used only for boundary validation. It
     * retains old tombstones that the live target cannot itself enumerate,
     * but a live row wins over an old tombstone so target resurrection is
     * visible to the candidate-bound checker.
     *
     * @param list<array<string,mixed>> $observedEntities
     * @param list<array<string,mixed>> $selectedDeletions
     * @return list<array<string,mixed>>
     */
    public static function target_probe_rows(
        CompiledRepository $previous,
        array $observedEntities,
        array $selectedDeletions
    ): array {
        $live = self::index_rows($observedEntities, 'target observation');
        $deleted = self::index_rows($selectedDeletions, 'target deletion');
        $rows = array_values($live);
        foreach ($previous->deletions() as $identity => $row) {
            $identity = (string) $identity;
            if (!isset($live[$identity]) && !isset($deleted[$identity])) {
                $rows[] = self::compiled_row($identity, $row, 'deletion');
            }
        }
        foreach ($deleted as $identity => $row) {
            if (isset($live[$identity])) {
                throw new \RuntimeException("duo: target observation is both live and deleted for '$identity'");
            }
            $rows[] = $row;
        }
        self::assert_unique_paths($rows);
        return $rows;
    }

    /** @param list<array<string,mixed>> $rows */
    public static function stage_state_view(array $rows): string {
        $path = rtrim(sys_get_temp_dir(), '/') . '/duo-scope-state-' . bin2hex(random_bytes(12));
        if (!mkdir($path, 0700)) {
            throw new \RuntimeException('duo: cannot stage immutable scoped target evidence');
        }
        try {
            foreach ($rows as $row) {
                $relative = (string) ($row['path'] ?? '');
                if ($relative === '' || str_contains($relative, "\0") || str_starts_with($relative, '/')
                    || preg_match('#(^|/)\.\.(/|$)#', $relative) === 1
                    || !is_string($row['content'] ?? null)) {
                    throw new \RuntimeException('duo: scoped target evidence has an unsafe canonical path');
                }
                Canon::write_file($path . '/' . $relative, (string) $row['content']);
            }
            return $path;
        } catch (\Throwable $failure) {
            self::discard_state_view($path);
            throw $failure;
        }
    }

    public static function discard_state_view(string $view): void {
        $tmp = realpath(sys_get_temp_dir());
        $parent = realpath(dirname($view));
        if ($tmp === false || $parent === false || !hash_equals($tmp, $parent)
            || !str_starts_with(basename($view), 'duo-scope-state-') || !is_dir($view)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($view, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($view);
    }

    /**
     * Reconstruct the exact contract-source media catalog after this run has
     * written its verified candidate blobs. Any other addition/removal or
     * changed original blob is source drift; candidate additions are omitted
     * from the view so the original artifact can be re-associated exactly.
     *
     * @param array<string,string> $expectedCatalog
     * @param array<string,array<string,mixed>> $allowedAdditions
     */
    public static function stage_associated_source_media_view(
        string $repo,
        array $expectedCatalog,
        array $allowedAdditions
    ): string {
        $view = self::new_media_view();
        $allowed = [];
        foreach ($allowedAdditions as $name => $source) {
            $allowed[(string) $name] = self::candidate_media_bytes((string) $name, $source);
        }
        $seen = [];
        try {
            $dir = rtrim($repo, '/') . '/media';
            if (is_dir($dir)) {
                foreach (new \FilesystemIterator($dir, \FilesystemIterator::SKIP_DOTS) as $file) {
                    $name = $file->getFilename();
                    $bytes = self::verified_media_file($file->getPathname(), $name);
                    if (array_key_exists($name, $expectedCatalog)) {
                        if (!hash_equals((string) $expectedCatalog[$name], hash('sha256', $bytes))) {
                            throw new \RuntimeException("duo: scoped capture source media '$name' changed");
                        }
                        Canon::write_file($view . '/' . $name, $bytes);
                        $seen[$name] = true;
                        continue;
                    }
                    if (!array_key_exists($name, $allowed) || !hash_equals($allowed[$name], $bytes)) {
                        throw new \RuntimeException('duo: scoped capture source media inventory changed before publication');
                    }
                    $seen[$name] = true;
                }
            }
            foreach ($expectedCatalog as $name => $expected) {
                if (!isset($seen[(string) $name])) {
                    throw new \RuntimeException("duo: scoped capture source media '$name' disappeared before publication");
                }
                if (preg_match('/^[a-f0-9]{64}\.[A-Za-z0-9]+$/D', (string) $name) !== 1
                    || !is_string($expected) || preg_match('/^[a-f0-9]{64}$/D', $expected) !== 1) {
                    throw new \RuntimeException('duo: scoped capture source media catalog is malformed');
                }
            }
            foreach ($allowed as $name => $_bytes) {
                if (!isset($seen[$name])) {
                    throw new \RuntimeException("duo: scoped capture candidate media '$name' was not written durably");
                }
            }
            return $view;
        } catch (\Throwable $failure) {
            self::discard_media_view($view);
            throw $failure;
        }
    }

    public static function discard_media_view(string $view): void {
        $tmp = realpath(sys_get_temp_dir());
        $parent = realpath(dirname($view));
        if ($tmp === false || $parent === false || !hash_equals($tmp, $parent)
            || !str_starts_with(basename($view), 'duo-scope-media-') || !is_dir($view)) {
            return;
        }
        foreach (new \FilesystemIterator($view, \FilesystemIterator::SKIP_DOTS) as $file) {
            if ($file->isFile() && !$file->isLink()) {
                @unlink($file->getPathname());
            }
        }
        @rmdir($view);
    }

    /** Every excluded canonical row/tombstone must survive unchanged. */
    public static function assert_excluded_preserved(
        CompiledRepository $source,
        CompiledRepository $candidate,
        array $contract
    ): void {
        $selected = array_fill_keys(self::selected_identities($contract), true);
        $before = self::compiled_index($source);
        $after = self::compiled_index($candidate);
        foreach ($before as $identity => $row) {
            if (isset($selected[$identity])) {
                continue;
            }
            if (!isset($after[$identity])
                || (string) $after[$identity]['kind'] !== (string) $row['kind']
                || (string) $after[$identity]['path'] !== (string) $row['path']
                || (string) $after[$identity]['content'] !== (string) $row['content']) {
                throw new \RuntimeException(
                    "duo: scoped overlay changed or removed excluded canonical row '$identity'"
                );
            }
        }
        foreach ($after as $identity => $_row) {
            if (!isset($before[$identity]) && !isset($selected[$identity])) {
                throw new \RuntimeException(
                    "duo: scoped overlay introduced out-of-contract identity '$identity'"
                );
            }
        }
    }

    /** @param list<array<string,mixed>> $rows @return array<string,array<string,mixed>> */
    private static function index_rows(array $rows, string $label): array {
        $out = [];
        foreach ($rows as $row) {
            $identity = (string) ($row['uuid'] ?? '');
            if ($identity === '' || isset($out[$identity])
                || !is_string($row['path'] ?? null) || !is_string($row['content'] ?? null)) {
                throw new \RuntimeException("duo: scoped overlay has malformed or duplicate $label row");
            }
            $out[$identity] = $row;
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private static function compiled_row(string $identity, array $row, ?string $type = null): array {
        return [
            'uuid' => $identity,
            'type' => $type ?? (string) ($row['type'] ?? ''),
            'path' => (string) ($row['path'] ?? ''),
            'content' => (string) ($row['content'] ?? ''),
        ];
    }

    /** @return array<string,array{kind:string,path:string,content:string}> */
    private static function compiled_index(CompiledRepository $compiled): array {
        $out = [];
        foreach ($compiled->tree() as $identity => $row) {
            $out[(string) $identity] = [
                'kind' => 'live', 'path' => (string) $row['path'], 'content' => (string) $row['content'],
            ];
        }
        foreach ($compiled->deletions() as $identity => $row) {
            $out[(string) $identity] = [
                'kind' => 'deletion', 'path' => (string) $row['path'], 'content' => (string) $row['content'],
            ];
        }
        return $out;
    }

    /** @param array<string,array<string,mixed>> $live @param array<string,bool> $selected */
    private static function assert_no_new_identities(
        CompiledRepository $previous,
        array $live,
        array $selected
    ): void {
        $known = array_fill_keys(array_merge(
            array_keys($previous->tree()),
            array_keys($previous->deletions())
        ), true);
        foreach ($live as $identity => $_row) {
            if (isset($selected[$identity]) && !isset($known[$identity])) {
                throw new \RuntimeException("duo: scoped overlay cannot mint new identity '$identity'");
            }
        }
    }

    /** @param list<array<string,mixed>> $rows */
    private static function assert_unique_paths(array $rows): void {
        $paths = [];
        foreach ($rows as $row) {
            $path = (string) ($row['path'] ?? '');
            if ($path === '' || isset($paths[$path])) {
                throw new \RuntimeException('duo: scoped overlay produced an empty or duplicate canonical path');
            }
            $paths[$path] = true;
        }
    }

    private static function new_media_view(): string {
        $path = rtrim(sys_get_temp_dir(), '/') . '/duo-scope-media-' . bin2hex(random_bytes(12));
        if (!mkdir($path, 0700)) {
            throw new \RuntimeException('duo: cannot stage immutable scoped media evidence');
        }
        return $path;
    }

    private static function copy_repository_media(string $repo, string $view): void {
        $dir = rtrim($repo, '/') . '/media';
        if (!is_dir($dir)) {
            return;
        }
        foreach (new \FilesystemIterator($dir, \FilesystemIterator::SKIP_DOTS) as $file) {
            $name = $file->getFilename();
            Canon::write_file($view . '/' . $name, self::verified_media_file($file->getPathname(), $name));
        }
    }

    private static function verified_media_file(string $path, string $name): string {
        if (is_link($path) || !is_file($path)
            || preg_match('/^([a-f0-9]{64})\.[A-Za-z0-9]+$/D', $name, $match) !== 1) {
            throw new \RuntimeException("duo: scoped media inventory contains unsafe entry '$name'");
        }
        $bytes = Canon::read_file($path);
        if (!hash_equals($match[1], hash('sha256', $bytes))) {
            throw new \RuntimeException("duo: scoped media blob '$name' does not match its content address");
        }
        return $bytes;
    }

    /** @param array<string,mixed> $source */
    private static function candidate_media_bytes(string $name, array $source): string {
        if (preg_match('/^([a-f0-9]{64})\.[A-Za-z0-9]+$/D', $name, $match) !== 1) {
            throw new \RuntimeException("duo: candidate media name '$name' is not content-addressed");
        }
        $bytes = array_key_exists('bytes', $source)
            ? (string) $source['bytes']
            : Canon::read_file((string) ($source['path'] ?? ''));
        if (!hash_equals($match[1], hash('sha256', $bytes))) {
            throw new \RuntimeException("duo: candidate media '$name' does not match its content address");
        }
        return $bytes;
    }

}
