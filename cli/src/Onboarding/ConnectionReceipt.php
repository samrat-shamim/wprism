<?php
declare(strict_types=1);

namespace WPrism\Orchestrator;

require_once dirname(__DIR__, 3) . '/agent/src/Kernel/Canon.php';

use WPrism\Canon;

/** Canonical evidence for the inspection-only half of fresh-site onboarding. */
final class ConnectionReceipt {
    public const FORMAT = 'wprism-connection-receipt/v1';

    /**
     * @return array<string,mixed>
     */
    public static function build(
        string $environment,
        string $workspace,
        string $transport,
        string $environmentConfigBytes,
        string $createdAt
    ): array {
        $document = [
            'created_at' => $createdAt,
            'environment' => $environment,
            'format' => self::FORMAT,
            'inspection' => [
                'target_transport' => 'reachable',
                'topology' => 'single_site',
                'wordpress' => 'installed',
            ],
            'mutation' => [
                'explicit' => false,
                'startup_code_may_have_run' => true,
            ],
            'next_action' => 'onboard',
            'transport' => $transport,
            'workspace' => [
                'environment_config_sha256' => self::digestBytes($environmentConfigBytes),
                'path' => $workspace,
            ],
        ];
        $document['receipt_sha256'] = self::digest($document);
        self::validate($document);

        return Canon::normalize($document);
    }

    /** @param array<string,mixed> $document */
    public static function validate(array $document): void {
        self::keys($document, [
            'created_at', 'environment', 'format', 'inspection', 'mutation',
            'next_action', 'receipt_sha256', 'transport', 'workspace',
        ], 'connection receipt');
        if (($document['format'] ?? null) !== self::FORMAT
            || !self::timestamp($document['created_at'] ?? null)
            || !self::environment($document['environment'] ?? null)
            || !in_array($document['transport'] ?? null, ['ssh', 'local', 'docker'], true)
            || ($document['next_action'] ?? null) !== 'onboard') {
            throw new \InvalidArgumentException('connection receipt has invalid identity fields');
        }
        $inspection = self::object($document['inspection'] ?? null, 'inspection');
        self::keys($inspection, ['target_transport', 'topology', 'wordpress'], 'inspection');
        if ($inspection !== [
            'target_transport' => 'reachable',
            'topology' => 'single_site',
            'wordpress' => 'installed',
        ]) {
            throw new \InvalidArgumentException('connection receipt does not prove the closed inspection result');
        }
        $mutation = self::object($document['mutation'] ?? null, 'mutation');
        self::keys($mutation, ['explicit', 'startup_code_may_have_run'], 'mutation');
        if (($mutation['explicit'] ?? null) !== false || ($mutation['startup_code_may_have_run'] ?? null) !== true) {
            throw new \InvalidArgumentException('connection receipt misstates the inspection mutation boundary');
        }
        $workspace = self::object($document['workspace'] ?? null, 'workspace');
        self::keys($workspace, ['environment_config_sha256', 'path'], 'workspace');
        if (!is_string($workspace['path'] ?? null) || !str_starts_with($workspace['path'], '/')
            || !self::digestValue($workspace['environment_config_sha256'] ?? null)) {
            throw new \InvalidArgumentException('connection receipt workspace binding is invalid');
        }
        $receiptDigest = $document['receipt_sha256'] ?? null;
        $body = $document;
        unset($body['receipt_sha256']);
        if (!self::digestValue($receiptDigest) || !hash_equals(self::digest($body), (string) $receiptDigest)) {
            throw new \InvalidArgumentException('connection receipt digest does not bind its canonical contents');
        }
    }

    /** @param array<string,mixed> $document */
    private static function digest(array $document): string {
        return self::digestBytes(Canon::encode($document));
    }

    private static function digestBytes(string $bytes): string {
        return 'sha256:' . hash('sha256', $bytes);
    }

    private static function timestamp(mixed $value): bool {
        return is_string($value)
            && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $value) === 1;
    }

    private static function environment(mixed $value): bool {
        return is_string($value)
            && preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D', $value) === 1;
    }

    private static function digestValue(mixed $value): bool {
        return is_string($value) && preg_match('/^sha256:[a-f0-9]{64}$/D', $value) === 1;
    }

    /** @return array<string,mixed> */
    private static function object(mixed $value, string $label): array {
        if (!is_array($value) || array_is_list($value)) {
            throw new \InvalidArgumentException("connection receipt $label is not an object");
        }
        return $value;
    }

    /** @param array<string,mixed> $value @param list<string> $expected */
    private static function keys(array $value, array $expected, string $label): void {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new \InvalidArgumentException("connection receipt $label has an unexpected shape");
        }
    }
}
