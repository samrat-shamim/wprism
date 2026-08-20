<?php
declare(strict_types=1);

namespace Duo\Cloud;

require_once dirname(__DIR__) . '/src/CanonicalJson.php';
require_once dirname(__DIR__) . '/src/ControlRefusal.php';

/** Digest-pinned closed registry for one host's production workers. */
final class ProductionFleetConfig {
    public const FORMAT = 'duo-cloud-production-fleet-config/v1';
    public const MAINTENANCE_STATE_FILE = 'fleet-maintenance.json';
    private const LIMIT = 1048576;
    private const CONFIGURATION_LIMIT = 64;
    private const WORKER_LIMIT = 32;

    /**
     * @param list<array{
     *     configuration_file:string,
     *     configuration_sha256:string,
     *     retiring_configuration:array{configuration_file:string,configuration_sha256:string}|null,
     *     worker_id:string
     * }> $workers
     */
    private function __construct(private array $workers) {}

    public static function load(string $path, string $expectedSha256): self {
        if ($path === '' || $path[0] !== '/'
            || preg_match('/\A[a-f0-9]{64}\z/D', $expectedSha256) !== 1) {
            throw new ControlRefusal('production fleet configuration identity is invalid');
        }
        clearstatcache(true, $path);
        $before = @lstat($path);
        $handle = @fopen($path, 'rb');
        if (!is_array($before) || is_link($path) || ($before['mode'] & 0170000) !== 0100000
            || ($before['mode'] & 0022) !== 0 || !is_resource($handle)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new ControlRefusal('production fleet configuration is not protected');
        }
        try {
            $opened = fstat($handle);
            $bytes = stream_get_contents($handle, self::LIMIT + 1);
            $after = @lstat($path);
        } finally {
            fclose($handle);
        }
        if (!is_array($opened) || !is_array($after) || !is_string($bytes)
            || strlen($bytes) > self::LIMIT || !self::sameFile($before, $opened)
            || !self::sameFile($before, $after)
            || !hash_equals($expectedSha256, hash('sha256', $bytes))) {
            throw new ControlRefusal('production fleet configuration changed or differs from its pin');
        }
        $document = CanonicalJson::decodeObject($bytes, self::LIMIT);
        self::exactKeys($document, ['format', 'workers']);
        if ($bytes !== CanonicalJson::encode($document) . "\n"
            || ($document['format'] ?? null) !== self::FORMAT
            || !is_array($document['workers'] ?? null) || !array_is_list($document['workers'])
            || $document['workers'] === [] || count($document['workers']) > self::WORKER_LIMIT) {
            throw new ControlRefusal('production fleet configuration is not canonical and bounded');
        }
        $workers = [];
        $previous = null;
        $files = [];
        $digests = [];
        $identities = [];
        $configurationCount = 0;
        foreach ($document['workers'] as $worker) {
            if (!is_array($worker) || array_is_list($worker)) {
                throw new ControlRefusal('production fleet worker is malformed');
            }
            self::exactKeys($worker, [
                'configuration_file', 'configuration_sha256',
                'retiring_configuration', 'worker_id',
            ]);
            $file = $worker['configuration_file'] ?? null;
            $sha256 = $worker['configuration_sha256'] ?? null;
            $workerId = $worker['worker_id'] ?? null;
            if (!is_string($file) || $file === '' || $file[0] !== '/' || str_contains($file, "\0")
                || !is_string($sha256) || preg_match('/\A[a-f0-9]{64}\z/D', $sha256) !== 1
                || !is_string($workerId)
                || preg_match('/\A[a-z0-9][a-z0-9-]{0,31}\z/D', $workerId) !== 1
                || ($previous !== null && strcmp($previous, $workerId) >= 0)) {
                throw new ControlRefusal('production fleet worker registry is not canonical and unique');
            }

            $retiring = $worker['retiring_configuration'];
            if ($retiring !== null) {
                if (!is_array($retiring) || array_is_list($retiring)) {
                    throw new ControlRefusal('production fleet retiring configuration is malformed');
                }
                self::exactKeys($retiring, ['configuration_file', 'configuration_sha256']);
                $retiringFile = $retiring['configuration_file'] ?? null;
                $retiringSha256 = $retiring['configuration_sha256'] ?? null;
                if (!is_string($retiringFile) || $retiringFile === ''
                    || $retiringFile[0] !== '/' || str_contains($retiringFile, "\0")
                    || !is_string($retiringSha256)
                    || preg_match('/\A[a-f0-9]{64}\z/D', $retiringSha256) !== 1) {
                    throw new ControlRefusal(
                        'production fleet retiring configuration identity is invalid'
                    );
                }
                $retiring = [
                    'configuration_file' => $retiringFile,
                    'configuration_sha256' => $retiringSha256,
                ];
            }

            $configurations = [[
                'configuration_file' => $file,
                'configuration_sha256' => $sha256,
            ]];
            if ($retiring !== null) {
                $configurations[] = $retiring;
            }
            foreach ($configurations as $configuration) {
                $configurationFile = $configuration['configuration_file'];
                $configurationSha256 = $configuration['configuration_sha256'];
                $configurationCount++;
                if ($configurationCount > self::CONFIGURATION_LIMIT
                    || isset($files[$configurationFile])
                    || isset($digests[$configurationSha256])) {
                    throw new ControlRefusal(
                        'production fleet configuration identities are not bounded and unique'
                    );
                }
                clearstatcache(true, $configurationFile);
                $stat = @lstat($configurationFile);
                $real = realpath($configurationFile);
                $identity = is_array($stat)
                    ? ($stat['dev'] ?? '') . ':' . ($stat['ino'] ?? '')
                    : '';
                if (!is_array($stat) || is_link($configurationFile)
                    || ($stat['mode'] & 0170000) !== 0100000
                    || !is_string($real) || isset($identities[$identity])) {
                    throw new ControlRefusal(
                        'production fleet worker file identity is invalid or duplicated'
                    );
                }
                $files[$configurationFile] = true;
                $digests[$configurationSha256] = true;
                $identities[$identity] = true;
            }
            $workers[] = [
                'configuration_file' => $file,
                'configuration_sha256' => $sha256,
                'retiring_configuration' => $retiring,
                'worker_id' => $workerId,
            ];
            $previous = $workerId;
        }
        return new self($workers);
    }

    /**
     * @return list<array{
     *     configuration_file:string,
     *     configuration_sha256:string,
     *     retiring_configuration:array{configuration_file:string,configuration_sha256:string}|null,
     *     worker_id:string
     * }>
     */
    public function workers(): array {
        return $this->workers;
    }

    /** @param array<string,mixed> $value @param list<string> $expected */
    private static function exactKeys(array $value, array $expected): void {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new ControlRefusal('production fleet configuration has missing or unknown fields');
        }
    }

    /** @param array<string,mixed> $left @param array<string,mixed> $right */
    private static function sameFile(array $left, array $right): bool {
        return ($left['dev'] ?? null) === ($right['dev'] ?? null)
            && ($left['ino'] ?? null) === ($right['ino'] ?? null)
            && ($left['mode'] ?? null) === ($right['mode'] ?? null)
            && ($left['size'] ?? null) === ($right['size'] ?? null);
    }
}
