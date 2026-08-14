<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

require_once __DIR__ . '/RedactedEvidenceIdentity.php';
require_once __DIR__ . '/ArtifactTrustVerifier.php';

/** Pure validator for the approved exact-input certification closure v2. */
final class CertificationClosureV2 {
    public const FORMAT = 'duo-certification-closure/v2';

    private const DIGEST_PATTERN = '/^sha256:[a-f0-9]{64}$/D';

    /** @param array<string,mixed> $closure */
    public static function assertValid(array $closure): void {
        $keys = array_keys($closure);
        sort($keys, SORT_STRING);
        $expected = [
            'build_definition', 'candidate_commit', 'declaration_payloads', 'environment',
            'format', 'harness', 'helpers', 'oracles', 'platform', 'providers',
            'run_receipts', 'runtime_payloads', 'test_plan', 'toolchain',
        ];
        sort($expected, SORT_STRING);
        if ($keys !== $expected || ($closure['format'] ?? null) !== self::FORMAT
            || !is_string($closure['candidate_commit'] ?? null)
            || preg_match('/^[a-f0-9]{40}$/D', $closure['candidate_commit']) !== 1) {
            throw new \RuntimeException('duo evidence: closure-v2 has an unsupported or malformed root');
        }
        foreach (['runtime_payloads', 'declaration_payloads', 'harness', 'oracles', 'helpers'] as $field) {
            self::assertDigestMap($closure[$field] ?? null, $field);
        }
        foreach (['build_definition', 'test_plan'] as $field) {
            self::assertDigest($closure[$field] ?? null, $field);
        }
        foreach (['toolchain', 'platform', 'providers', 'environment'] as $field) {
            if (!is_array($closure[$field] ?? null) || array_is_list($closure[$field])) {
                throw new \RuntimeException("duo evidence: closure-v2 $field identity is malformed");
            }
            RedactedEvidenceIdentity::assertValid($closure[$field]);
        }
        self::assertPublic($closure['toolchain'], 'toolchain', 'toolchain_profile');
        self::assertPublic($closure['platform'], 'platform', 'platform_profile');
        self::assertKeyed($closure['providers'], 'providers');
        self::assertKeyed($closure['environment'], 'environment');
        if (!is_array($closure['run_receipts']) || !array_is_list($closure['run_receipts'])
            || $closure['run_receipts'] === []) {
            throw new \RuntimeException('duo evidence: closure-v2 run_receipts must be non-empty');
        }
        foreach ($closure['run_receipts'] as $receipt) {
            self::assertDigest($receipt, 'closure-v2 run receipt');
        }
    }

    /** @param array<string,mixed> $closure */
    public static function canonical(array $closure): string {
        self::assertValid($closure);
        return ArtifactTrustVerifier::canonical($closure);
    }

    public static function digest(string $bytes): string {
        return 'sha256:' . hash('sha256', $bytes);
    }

    /**
     * Assemble a closure from exact bytes and already-redacted identities.
     * Raw bytes are reduced to content digests and never retained in the
     * resulting authority document.
     *
     * @param array<string,string> $runtimePayloadBytes
     * @param array<string,string> $declarationPayloadBytes
     * @param array<string,string> $harnessBytes
     * @param array<string,string> $oracleBytes
     * @param array<string,string> $helperBytes
     * @param list<string> $runReceiptBytes
     * @param array<string,mixed> $toolchain
     * @param array<string,mixed> $platform
     * @param array<string,mixed> $providers
     * @param array<string,mixed> $environment
     * @return array<string,mixed>
     */
    public static function assemble(
        string $candidateCommit,
        array $runtimePayloadBytes,
        array $declarationPayloadBytes,
        string $buildDefinitionBytes,
        string $testPlanBytes,
        array $harnessBytes,
        array $oracleBytes,
        array $helperBytes,
        array $toolchain,
        array $platform,
        array $providers,
        array $environment,
        array $runReceiptBytes
    ): array {
        $map = static function (array $bytes, string $label): array {
            if ($bytes === [] || array_is_list($bytes)) {
                throw new \RuntimeException("duo evidence: closure-v2 $label bytes are absent or malformed");
            }
            $out = [];
            foreach ($bytes as $name => $value) {
                if (!is_string($name) || !is_string($value)) {
                    throw new \RuntimeException("duo evidence: closure-v2 $label bytes are malformed");
                }
                $out[$name] = self::digest($value);
            }
            ksort($out, SORT_STRING);
            return $out;
        };
        if (preg_match('/^[a-f0-9]{40}$/D', $candidateCommit) !== 1 || $runReceiptBytes === []) {
            throw new \RuntimeException('duo evidence: closure-v2 candidate or run receipts are malformed');
        }
        foreach ($runReceiptBytes as $bytes) {
            if (!is_string($bytes)) {
                throw new \RuntimeException('duo evidence: closure-v2 run receipt bytes are malformed');
            }
        }
        $closure = [
            'format' => self::FORMAT,
            'candidate_commit' => $candidateCommit,
            'runtime_payloads' => $map($runtimePayloadBytes, 'runtime payload'),
            'declaration_payloads' => $map($declarationPayloadBytes, 'declaration payload'),
            'build_definition' => self::digest($buildDefinitionBytes),
            'test_plan' => self::digest($testPlanBytes),
            'harness' => $map($harnessBytes, 'harness'),
            'oracles' => $map($oracleBytes, 'oracle'),
            'helpers' => $map($helperBytes, 'helper'),
            'toolchain' => $toolchain,
            'platform' => $platform,
            'providers' => $providers,
            'environment' => $environment,
            'run_receipts' => array_map(static fn(string $bytes): string => self::digest($bytes), $runReceiptBytes),
        ];
        self::assertValid($closure);
        return $closure;
    }

    /** @param mixed $value */
    private static function assertDigest(mixed $value, string $label): void {
        if (!is_string($value) || preg_match(self::DIGEST_PATTERN, $value) !== 1) {
            throw new \RuntimeException("duo evidence: $label must be a sha256 digest");
        }
    }

    /** @param mixed $value */
    private static function assertDigestMap(mixed $value, string $label): void {
        if (!is_array($value) || array_is_list($value) || $value === []) {
            throw new \RuntimeException("duo evidence: closure-v2 $label must be a non-empty digest map");
        }
        foreach ($value as $name => $digest) {
            if (!is_string($name) || $name === '' || str_starts_with($name, '/')
                || str_ends_with($name, '/') || str_contains($name, '//')
                || str_contains($name, "\0") || str_contains($name, '\\')
                || in_array('.', explode('/', $name), true)
                || in_array('..', explode('/', $name), true)) {
                throw new \RuntimeException("duo evidence: closure-v2 $label has an invalid identity key");
            }
            self::assertDigest($digest, "$label.$name");
        }
    }

    /** @param array<string,mixed> $identity */
    private static function assertPublic(array $identity, string $label, string $key): void {
        if (!is_array($identity['public'] ?? null) || !array_key_exists($key, $identity['public'])) {
            throw new \RuntimeException("duo evidence: closure-v2 $label identity lacks public $key");
        }
    }

    /** @param array<string,mixed> $identity */
    private static function assertKeyed(array $identity, string $label): void {
        if (!is_array($identity['keyed_bindings'] ?? null) || $identity['keyed_bindings'] === []) {
            throw new \RuntimeException("duo evidence: closure-v2 $label identity lacks keyed bindings");
        }
    }
}
