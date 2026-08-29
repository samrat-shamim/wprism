<?php
declare(strict_types=1);

namespace WPrism\Orchestrator;

require_once dirname(__DIR__, 3) . '/agent/src/Kernel/Canon.php';
require_once dirname(__DIR__, 3) . '/agent/src/Kernel/CommandRefusal.php';

use WPrism\Canon;
use WPrism\CommandRefusalException;

/** Immutable identity for one source revision staged outside the live checkout. */
final class SourceStageReceipt {
    public const FORMAT = 'wprism-source-stage-receipt/v1';

    /** @var list<string> */
    private const KEYS = [
        'base', 'created_at', 'environment', 'format', 'operation_id', 'receipt_sha256',
        'request', 'source', 'stage', 'target',
    ];

    /**
     * @param array<string,mixed> $facts
     * @return array<string,mixed>
     */
    public static function build(array $facts): array {
        $document = [
            'base' => [
                'commit' => (string) ($facts['base_commit'] ?? ''),
                'tree' => (string) ($facts['base_tree'] ?? ''),
            ],
            'created_at' => (string) ($facts['created_at'] ?? ''),
            'environment' => (string) ($facts['environment'] ?? ''),
            'format' => self::FORMAT,
            'operation_id' => (string) ($facts['operation_id'] ?? ''),
            'request' => [
                'advertised_ref' => (string) ($facts['advertised_ref'] ?? ''),
                'advertised_source_ref' => (string) ($facts['advertised_source_ref'] ?? ''),
                'expected_commit' => (string) ($facts['source_commit'] ?? ''),
            ],
            'source' => [
                'commit' => (string) ($facts['source_commit'] ?? ''),
                'tree' => (string) ($facts['source_tree'] ?? ''),
            ],
            'stage' => [
                'ref' => self::stageRef((string) ($facts['operation_id'] ?? '')),
                'repository_path' => (string) ($facts['stage_repository_path'] ?? ''),
            ],
            'target' => [
                'id' => (string) ($facts['target_id'] ?? ''),
                'identity_sha256' => self::targetIdentityDigest((string) ($facts['target_id'] ?? '')),
                'repo_path' => (string) ($facts['target_repo_path'] ?? ''),
            ],
        ];
        $document['receipt_sha256'] = self::digest($document);
        self::validate($document);

        return $document;
    }

    /** @param array<string,mixed> $document */
    public static function validate(array $document): void {
        self::closedKeys($document, self::KEYS, 'source stage receipt');
        if (($document['format'] ?? null) !== self::FORMAT) {
            throw self::refuse('release_stage_receipt_invalid', 'the source stage receipt format is unsupported');
        }
        $environment = $document['environment'] ?? null;
        if (!is_string($environment) || $environment === '' || self::hasControl($environment)) {
            throw self::refuse('release_stage_receipt_invalid', 'the source stage receipt environment is invalid');
        }
        $operationId = $document['operation_id'] ?? null;
        if (!is_string($operationId)) {
            throw self::refuse('release_stage_receipt_invalid', 'the source stage receipt operation id is invalid');
        }
        self::assertOperationId($operationId);
        if (!is_string($document['created_at'] ?? null)
            || !self::canonicalTimestamp($document['created_at'])) {
            throw self::refuse('release_stage_receipt_invalid', 'the source stage receipt created_at is invalid');
        }

        $base = self::object($document['base'] ?? null, ['commit', 'tree'], 'base');
        $source = self::object($document['source'] ?? null, ['commit', 'tree'], 'source');
        foreach ([$base['commit'], $base['tree'], $source['commit'], $source['tree']] as $objectId) {
            self::assertObjectId($objectId);
        }

        $request = self::object(
            $document['request'] ?? null,
            ['advertised_ref', 'advertised_source_ref', 'expected_commit'],
            'request'
        );
        foreach (['advertised_ref', 'advertised_source_ref'] as $key) {
            if (!is_string($request[$key]) || $request[$key] === '' || self::hasControl($request[$key])) {
                throw self::refuse('release_stage_receipt_invalid', "the source stage receipt request.$key is invalid");
            }
        }
        self::assertObjectId($request['expected_commit']);
        if (!hash_equals($source['commit'], $request['expected_commit'])) {
            throw self::refuse('release_stage_receipt_invalid', 'the source stage receipt request does not bind its source commit');
        }

        $stage = self::object($document['stage'] ?? null, ['ref', 'repository_path'], 'stage');
        if ($stage['ref'] !== self::stageRef($operationId)
            || !is_string($stage['repository_path']) || $stage['repository_path'] === ''
            || self::hasControl($stage['repository_path'])) {
            throw self::refuse('release_stage_receipt_invalid', 'the source stage receipt stage identity is invalid');
        }

        $target = self::object($document['target'] ?? null, ['id', 'identity_sha256', 'repo_path'], 'target');
        if (!is_string($target['id']) || preg_match('/^wprism-target:[a-f0-9]{64}$/D', $target['id']) !== 1
            || !is_string($target['repo_path']) || $target['repo_path'] === '' || self::hasControl($target['repo_path'])
            || !is_string($target['identity_sha256'])
            || !hash_equals(self::targetIdentityDigest($target['id']), $target['identity_sha256'])) {
            throw self::refuse('release_stage_receipt_invalid', 'the source stage receipt target identity is invalid');
        }

        $receiptDigest = $document['receipt_sha256'] ?? null;
        if (!is_string($receiptDigest) || preg_match('/^sha256:[a-f0-9]{64}$/D', $receiptDigest) !== 1
            || !hash_equals(self::digest($document), $receiptDigest)) {
            throw self::refuse('release_stage_receipt_invalid', 'the source stage receipt digest does not match its canonical contents');
        }
    }

    /** @return array<string,mixed> */
    public static function fromBytes(string $bytes): array {
        try {
            $decoded = Canon::decode($bytes);
        } catch (\Throwable $error) {
            throw self::refuse('release_stage_receipt_invalid', 'the source stage receipt is malformed JSON', $error);
        }
        if (!is_array($decoded) || array_is_list($decoded) || self::encode($decoded) !== $bytes) {
            throw self::refuse('release_stage_receipt_invalid', 'the source stage receipt is not canonical JSON');
        }
        self::validate($decoded);

        return $decoded;
    }

    /** @return array<string,mixed> */
    public static function read(string $path): array {
        $bytes = @file_get_contents($path);
        if (!is_string($bytes)) {
            throw self::refuse(
                'release_stage_receipt_unavailable',
                'the requested source stage receipt could not be read',
                null,
                'save the exact stage-source output to a readable file and pass that file to release prepare'
            );
        }

        return self::fromBytes($bytes);
    }

    /** @param array<string,mixed> $document */
    public static function encode(array $document): string {
        return Canon::encode($document);
    }

    /** @param array<string,mixed> $document */
    public static function digest(array $document): string {
        unset($document['receipt_sha256']);

        return 'sha256:' . hash('sha256', Canon::encode($document));
    }

    public static function targetIdentityDigest(string $targetId): string {
        return 'sha256:' . hash('sha256', Canon::encode(['target_id' => $targetId]));
    }

    public static function stageRef(string $operationId): string {
        self::assertOperationId($operationId);

        return 'refs/wprism/release-stages/' . $operationId;
    }

    public static function assertOperationId(string $operationId): void {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/D', $operationId) !== 1
            || str_contains($operationId, '..') || str_contains($operationId, '@{')
            || str_ends_with($operationId, '.')) {
            throw self::refuse(
                'release_stage_operation_invalid',
                'the release operation id is not a safe immutable lineage identifier',
                null,
                'use 1-128 letters, digits, dot, underscore or hyphen, without dot-dot, @{ or a trailing dot'
            );
        }
    }

    private static function assertObjectId(mixed $value): void {
        if (!is_string($value) || preg_match('/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/D', $value) !== 1) {
            throw self::refuse('release_stage_receipt_invalid', 'the source stage receipt carries an invalid Git object id');
        }
    }

    /** @return array<string,mixed> */
    private static function object(mixed $value, array $keys, string $label): array {
        if (!is_array($value) || array_is_list($value)) {
            throw self::refuse('release_stage_receipt_invalid', "the source stage receipt $label block is invalid");
        }
        self::closedKeys($value, $keys, "source stage receipt $label");

        return $value;
    }

    /** @param list<string> $keys */
    private static function closedKeys(array $value, array $keys, string $label): void {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($keys, SORT_STRING);
        if ($actual !== $keys) {
            throw self::refuse('release_stage_receipt_invalid', "$label has an unexpected key set");
        }
    }

    private static function hasControl(string $value): bool {
        return preg_match('/[\x00-\x1f\x7f]/D', $value) === 1;
    }

    private static function canonicalTimestamp(string $value): bool {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new \DateTimeZone('UTC'));
        $errors = \DateTimeImmutable::getLastErrors();

        return $date instanceof \DateTimeImmutable
            && ($errors === false || (($errors['warning_count'] ?? 0) === 0 && ($errors['error_count'] ?? 0) === 0))
            && $date->format('Y-m-d\TH:i:s\Z') === $value;
    }

    private static function refuse(
        string $code,
        string $message,
        ?\Throwable $previous = null,
        string $remediation = 'discard the receipt and run stage-source again with a new operation id'
    ): CommandRefusalException {
        return new CommandRefusalException($code, $message, $remediation, [], $previous?->getMessage());
    }
}
