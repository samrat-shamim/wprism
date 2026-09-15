<?php
declare(strict_types=1);

namespace WPrism;

require_once __DIR__ . '/../Kernel/StoragePrerequisites.php';
require_once __DIR__ . '/../Kernel/StoragePrerequisiteSettlement.php';
require_once __DIR__ . '/../Policy/Policy.php';
require_once __DIR__ . '/../Repository/RepositoryCompiler.php';

/** Read-only target status for the host's checkpointed prerequisite settlement decision. */
final class StoragePrerequisiteStatus {
    public const FORMAT = 'wprism-storage-prerequisite-status/v1';

    /** @return array<string,mixed> */
    public static function inspect(string $repo, string $artifactPath, string $artifactHash): array {
        if (preg_match('/^[a-f0-9]{64}$/D', $artifactHash) !== 1) {
            throw new \InvalidArgumentException(
                'wprism: storage-prerequisite-status requires a valid artifact hash'
            );
        }
        $policy = Policy::load($repo);
        $compiled = RepositoryCompiler::read_artifact($artifactPath, $policy);
        if (!hash_equals($artifactHash, $compiled->artifact_hash())) {
            throw new \RuntimeException(
                'wprism: storage-prerequisite-status artifact does not match the host-compiled artifact hash'
            );
        }

        return self::for_policy($policy);
    }

    /** @return array<string,mixed> */
    public static function for_policy(Policy $policy): array {
        $inventory = StoragePrerequisiteSettlement::inventory($policy->manifests);
        $readiness = StoragePrerequisites::readiness($policy->manifests);
        if (count($inventory) !== count($readiness)) {
            throw new \RuntimeException('wprism: storage prerequisite projections disagree');
        }
        $rows = [];
        $required = false;
        foreach ($inventory as $index => $declaration) {
            $observed = $readiness[$index];
            if ($declaration['manifest'] !== $observed['manifest']
                || $declaration['option'] !== $observed['option']) {
                throw new \RuntimeException('wprism: storage prerequisite projections disagree');
            }
            $ready = $observed['ready'];
            $required = $required || !$ready;
            $rows[] = [
                'manifest' => $declaration['manifest'],
                'option' => $declaration['option'],
                'ready' => $ready,
                'settlement' => $declaration['settlement'],
            ];
        }
        return [
            'declared' => $rows !== [],
            'format' => self::FORMAT,
            'prerequisites' => $rows,
            'required' => $required,
            'state' => $rows === [] ? 'none' : ($required ? 'required' : 'ready'),
        ];
    }
}
