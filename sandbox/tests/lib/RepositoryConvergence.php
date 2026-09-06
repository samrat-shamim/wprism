<?php
declare(strict_types=1);

namespace WPrismTest;

require_once dirname(__DIR__, 3) . '/agent/src/Kernel/Canon.php';
require_once dirname(__DIR__, 3) . '/agent/src/Repository/CompiledArtifact.php';

use WPrism\Canon;
use WPrism\CompiledRepository;

/** Compare compiler-validated intent; fixtures separately prove any exact target-only identities. */
final class RepositoryConvergence {
    /** @return array<string,array{type:string,path:string,hash:string}> */
    public static function signatures(CompiledRepository $repository): array {
        $out = [];
        foreach ($repository->tree() as $key => $entity) {
            if (!is_string($key) || $key === '' || !is_array($entity)
                || !is_string($entity['type'] ?? null) || $entity['type'] === ''
                || !is_string($entity['path'] ?? null) || $entity['path'] === ''
                || !is_string($entity['hash'] ?? null) || preg_match('/^[0-9a-f]{64}$/D', $entity['hash']) !== 1) {
                throw new \RuntimeException('repository convergence received an incomplete compiled entity');
            }
            $out[$key] = ['type' => $entity['type'], 'path' => $entity['path'], 'hash' => $entity['hash']];
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    /**
     * Every extra signature must come from a separate fixture-owned native
     * preservation proof, never an observed path glob. The empty default is
     * strict full equality. Derived-field semantics belong to the compiler's
     * hash, not another timestamp/title normalizer in an evidence helper.
     *
     * @param array<string,array{type:string,path:string,hash:string}> $targetOnly
     */
    public static function assertSame(CompiledRepository $source, CompiledRepository $target, array $targetOnly = []): void {
        foreach (['manifest_hash', 'site_hash', 'resolved_adapters', 'code_descriptor', 'effects_inventory', 'deletions'] as $method) {
            if (Canon::encode($source->$method()) !== Canon::encode($target->$method())) {
                throw new \RuntimeException("repository convergence differs in $method");
            }
        }
        $expected = self::signatures($source);
        foreach ($targetOnly as $key => $signature) {
            if (!is_string($key) || $key === '' || isset($expected[$key]) || !is_array($signature)
                || array_keys($signature) !== ['type', 'path', 'hash']
                || !is_string($signature['type']) || $signature['type'] === ''
                || !is_string($signature['path']) || $signature['path'] === ''
                || !is_string($signature['hash']) || preg_match('/^[0-9a-f]{64}$/D', $signature['hash']) !== 1) {
                throw new \RuntimeException('repository convergence target-only proof is incomplete or overlaps managed intent');
            }
            $expected[$key] = $signature;
        }
        ksort($expected, SORT_STRING);
        if ($expected !== self::signatures($target)) {
            throw new \RuntimeException('repository convergence has missing, changed or unproved target-only entities');
        }
        // The compiler has already checked referenced payload hashes and media
        // grammar. Extra metadata-free files are not implicit permission to
        // change the repository's complete content-addressed media catalog.
        if (Canon::encode($source->export()['media_catalog'] ?? null) !== Canon::encode($target->export()['media_catalog'] ?? null)) {
            throw new \RuntimeException('repository convergence differs in its complete media catalog');
        }
    }
}
