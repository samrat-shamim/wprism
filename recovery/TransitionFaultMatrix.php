<?php
declare(strict_types=1);

namespace Duo\Recovery;

require_once __DIR__ . '/RecoveryTransitionPolicy.php';

/** Closed validator for the recovery transition/fault inventory. */
final class TransitionFaultMatrix {
    public const FORMAT = 'duo-recovery-transition-fault-matrix/v1';

    /** @return array<string,mixed> */
    public static function load(string $path): array {
        if (!is_file($path) || is_link($path)) {
            throw new \RuntimeException('duo recovery: transition matrix is not a regular file');
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('duo recovery: transition matrix is not valid JSON');
        }
        self::validate($decoded);
        $testsRoot = dirname($path, 2) . '/sandbox/tests';
        foreach (array_merge($decoded['transitions'], $decoded['resource_operations']) as $row) {
            $test = (string) $row['test'];
            if (preg_match('/^regress_[A-Za-z0-9_]+\.(?:php|sh)$/D', $test) !== 1
                || !is_file($testsRoot . '/' . $test)) {
                throw new \RuntimeException("duo recovery: matrix test reference is not a repository suite: $test");
            }
        }
        return $decoded;
    }

    /** @param array<string,mixed> $matrix */
    public static function validate(array $matrix): void {
        $keys = array_keys($matrix);
        sort($keys, SORT_STRING);
        if ($keys !== ['deferrals', 'format', 'resource_operations', 'transitions', 'version']
            || ($matrix['format'] ?? null) !== self::FORMAT
            || ($matrix['version'] ?? null) !== 1
            || !is_array($matrix['deferrals'] ?? null)
            || $matrix['deferrals'] !== []) {
            throw new \InvalidArgumentException('duo recovery: transition matrix envelope is not closed or has a deferral');
        }
        if (!is_array($matrix['transitions'] ?? null)
            || !is_array($matrix['resource_operations'] ?? null)
            || !array_is_list($matrix['transitions'])
            || !array_is_list($matrix['resource_operations'])) {
            throw new \InvalidArgumentException('duo recovery: transition matrix sections must be lists');
        }
        $transitions = $matrix['transitions'];
        $resources = $matrix['resource_operations'];
        self::rows($transitions, true);
        self::rows($resources, false);
        self::assertCoverage(
            array_map(static fn(array $row): string => $row['id'], $transitions),
            [
                'ordinary.prepared.promoting',
                'ordinary.promoting.verifying_new',
                'ordinary.verifying_new.committed',
                'ordinary.prepared.rollback_pending',
                'ordinary.promoting.rollback_pending',
                'ordinary.rollback_pending.rolling_back',
                'ordinary.rolling_back.verifying_prior',
                'ordinary.verifying_prior.rolled_back',
                'scoped.prepared.promoting',
                'scoped.promoting.verifying_new',
                'scoped.verifying_new.committed',
                'scoped.prepared.rollback_pending',
                'scoped.promoting.rollback_pending',
                'scoped.rollback_pending.rolling_back',
                'scoped.rolling_back.verifying_prior',
                'scoped.verifying_prior.rolled_back',
            ],
            'transition'
        );
        self::assertCoverage(
            array_map(static fn(array $row): string => $row['id'], $resources),
            [
                'exclusion.reserve',
                'exclusion.adopt',
                'exclusion.release',
                'checkpoint.prepare',
                'checkpoint.restore',
                'checkpoint.delete',
                'code-release.prepare',
                'code-release.restore',
                'upload.prepare',
                'upload.restore',
                'effect.prepare',
                'effect.restore',
            ],
            'resource'
        );
    }

    /** @param list<array<string,mixed>> $rows */
    private static function rows(array $rows, bool $transition): void {
        if ($rows === []) {
            throw new \InvalidArgumentException('duo recovery: transition matrix has an empty required section');
        }
        $ids = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !is_string($row['id'] ?? null) || $row['id'] === ''
                || in_array($row['id'], $ids, true)) {
                throw new \InvalidArgumentException('duo recovery: transition matrix row id is missing or duplicated');
            }
            $ids[] = $row['id'];
            $allowed = $transition
                ? ['after_fault', 'before_fault', 'corruption', 'durable', 'from', 'id', 'profile', 'retry', 'terminal_verification', 'test', 'to']
                : ['after_fault', 'before_fault', 'corruption', 'durable', 'id', 'retry', 'terminal_verification', 'test'];
            $keys = array_keys($row);
            sort($keys, SORT_STRING);
            $expectedKeys = $allowed;
            sort($expectedKeys, SORT_STRING);
            if ($keys !== $expectedKeys) {
                throw new \InvalidArgumentException("duo recovery: matrix row {$row['id']} has an open or incomplete schema");
            }
            $required = $transition
                ? ['profile', 'from', 'to', 'durable', 'before_fault', 'after_fault', 'retry', 'corruption', 'terminal_verification', 'test']
                : ['durable', 'before_fault', 'after_fault', 'retry', 'corruption', 'terminal_verification', 'test'];
            foreach ($required as $key) {
                if (!is_string($row[$key] ?? null) || trim($row[$key]) === '') {
                    throw new \InvalidArgumentException("duo recovery: matrix row {$row['id']} lacks $key evidence");
                }
            }
            if ($transition && !in_array((string) $row['profile'], ['ordinary', 'scoped-checkpoint-v1'], true)) {
                throw new \InvalidArgumentException("duo recovery: matrix row {$row['id']} has an unknown profile");
            }
            if ($transition && !RecoveryTransitionPolicy::allows(
                (string) $row['from'],
                (string) $row['to'],
                (string) $row['profile']
            )) {
                throw new \InvalidArgumentException("duo recovery: matrix row {$row['id']} is not an allowed transition");
            }
        }
    }

    /** @param list<string> $actual @param list<string> $expected */
    private static function assertCoverage(array $actual, array $expected, string $section): void {
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new \InvalidArgumentException("duo recovery: $section matrix coverage is incomplete or has an unknown row");
        }
    }
}
