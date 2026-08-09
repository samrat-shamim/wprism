<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

/**
 * The host boundary used by orchestration workflows.
 *
 * A driver selects how commands reach one WordPress environment. It does not
 * interpret canonical state or plugin semantics; those stay in the target
 * agent, manifests, native actions, and plugin-owned providers.
 */
interface EnvironmentDriver {
    public function name(): string;
    public function driverId(): string;
    public function repoPath(): string;
    public function describe(): string;

    /** @return array{exit:int, stdout:string, stderr:string} */
    public function captureRaw(string $script): array;

    /** @return array{exit:int, stdout:string, stderr:string} */
    public function captureWp(array $wpArgs): array;

    public function streamWp(array $wpArgs): int;
    public function wpInstruction(array $wpArgs): string;
    public function capabilityReport(string $operation): DriverCapabilityReport;
}

/** Closed, versioned vocabulary for environment-driver capabilities. */
final class DriverCapability {
    public const ATTACH = 'environment.attach';
    public const BOOTSTRAP = 'environment.bootstrap';
    public const CREATE = 'environment.create';
    public const DESTROY = 'environment.destroy';
    public const TTL = 'environment.ttl';
    public const WP_CONTROL = 'control.wp_cli';
    public const RAW_CONTROL = 'control.raw';
    public const CODE_TRANSFER = 'code.transfer';
    public const CODE_MATERIALIZE = 'code.materialize';
    public const DB_SNAPSHOT_CREATE = 'snapshot.database.create';
    public const DB_SNAPSHOT_READ = 'snapshot.database.read';
    public const DB_SNAPSHOT_RESTORE = 'snapshot.database.restore';
    public const MEDIA_SNAPSHOT_CREATE = 'snapshot.media.create';
    public const MEDIA_SNAPSHOT_READ = 'snapshot.media.read';
    public const MEDIA_SNAPSHOT_RESTORE = 'snapshot.media.restore';
    public const MAINTENANCE_ENTER = 'maintenance.enter';
    public const MAINTENANCE_EXIT = 'maintenance.exit';
    public const URL_DISCOVER = 'environment.url.discover';
    public const URL_SET = 'environment.url.set';
    public const OPERATION_RECEIPTS = 'operation.receipts';

    /** @return list<string> */
    public static function all(): array {
        $capabilities = [
            self::ATTACH, self::BOOTSTRAP, self::CREATE, self::DESTROY, self::TTL,
            self::WP_CONTROL, self::RAW_CONTROL, self::CODE_TRANSFER, self::CODE_MATERIALIZE,
            self::DB_SNAPSHOT_CREATE, self::DB_SNAPSHOT_READ, self::DB_SNAPSHOT_RESTORE,
            self::MEDIA_SNAPSHOT_CREATE, self::MEDIA_SNAPSHOT_READ, self::MEDIA_SNAPSHOT_RESTORE,
            self::MAINTENANCE_ENTER, self::MAINTENANCE_EXIT,
            self::URL_DISCOVER, self::URL_SET, self::OPERATION_RECEIPTS,
        ];
        sort($capabilities, SORT_STRING);
        return $capabilities;
    }
}

/**
 * Canonical, target-free capability negotiation result.
 *
 * This report is computed before a workflow contacts its target. A capability
 * is never inferred from another capability: attach is not create, WP-CLI is
 * not media backup, and a raw command path is not a maintenance-mode API.
 */
final class DriverCapabilityReport {
    public const FORMAT = 'duo-environment-driver-capabilities/v1';
    public const DRIVER_PROTOCOL = 1;

    /** @param array<string,bool> $supported */
    public static function forDriver(
        string $environment,
        string $driverId,
        string $operation,
        array $supported
    ): self {
        $known = DriverCapability::all();
        $unknown = array_diff(array_keys($supported), $known);
        if ($unknown !== []) {
            throw new \RuntimeException(
                "driver '$driverId' declared unknown capability '" . reset($unknown) . "'"
            );
        }

        $requirements = self::requirements($operation);
        $capabilities = [];
        foreach ($known as $capability) {
            $capabilities[$capability] = !empty($supported[$capability]) ? 'supported' : 'unsupported';
        }

        $checks = [];
        $ready = true;
        foreach ($requirements as $capability) {
            $ok = $capabilities[$capability] === 'supported';
            $ready = $ready && $ok;
            $checks[] = [
                'capability' => $capability,
                'state' => $ok ? 'supported' : 'unsupported',
                'reason' => $ok
                    ? 'driver declares the exact required capability'
                    : "driver '$driverId' does not implement '$capability'; no emulation is permitted",
            ];
        }

        $body = [
            'format' => self::FORMAT,
            'driver' => [
                'environment' => $environment,
                'id' => $driverId,
                'protocol' => self::DRIVER_PROTOCOL,
            ],
            'operation' => $operation,
            'ready' => $ready,
            'requirements' => $checks,
            'capabilities' => $capabilities,
        ];
        return new self($body);
    }

    /** @param array<string,mixed> $body */
    private function __construct(private array $body) {
        $canonical = self::canonicalJson($body);
        $this->body['digest'] = 'sha256:' . hash('sha256', $canonical);
    }

    public function ready(): bool {
        return $this->body['ready'] === true;
    }

    public function operation(): string {
        return (string) $this->body['operation'];
    }

    /** @return array<string,mixed> */
    public function toArray(): array {
        return $this->body;
    }

    /** @return list<array{capability:string,state:string,reason:string}> */
    public function blockers(): array {
        return array_values(array_filter(
            $this->body['requirements'],
            static fn(array $check): bool => $check['state'] !== 'supported'
        ));
    }

    /** @return list<string> */
    private static function requirements(string $operation): array {
        $requirements = match ($operation) {
            'attach' => [DriverCapability::ATTACH],
            'doctor' => [
                DriverCapability::ATTACH, DriverCapability::RAW_CONTROL, DriverCapability::WP_CONTROL,
            ],
            'status', 'capabilities', 'capture', 'plan', 'apply', 'env-set',
            'pending', 'classify', 'coverage' => [
                DriverCapability::ATTACH, DriverCapability::WP_CONTROL,
            ],
            'refresh', 'rebase' => [
                DriverCapability::ATTACH, DriverCapability::RAW_CONTROL, DriverCapability::WP_CONTROL,
            ],
            'adopt' => [
                DriverCapability::ATTACH, DriverCapability::BOOTSTRAP,
                DriverCapability::CODE_TRANSFER, DriverCapability::RAW_CONTROL, DriverCapability::WP_CONTROL,
            ],
            'deploy' => [
                DriverCapability::ATTACH, DriverCapability::CODE_MATERIALIZE,
                DriverCapability::RAW_CONTROL, DriverCapability::WP_CONTROL,
            ],
            'promote' => [
                DriverCapability::ATTACH, DriverCapability::CODE_MATERIALIZE,
                DriverCapability::DB_SNAPSHOT_CREATE, DriverCapability::DB_SNAPSHOT_RESTORE,
                DriverCapability::RAW_CONTROL, DriverCapability::WP_CONTROL,
            ],
            'create' => [DriverCapability::CREATE, DriverCapability::ATTACH],
            'destroy' => [DriverCapability::DESTROY, DriverCapability::OPERATION_RECEIPTS],
            'ttl' => [DriverCapability::TTL, DriverCapability::OPERATION_RECEIPTS],
            'media-snapshot' => [
                DriverCapability::MEDIA_SNAPSHOT_CREATE, DriverCapability::MEDIA_SNAPSHOT_READ,
                DriverCapability::MEDIA_SNAPSHOT_RESTORE,
            ],
            'maintenance' => [DriverCapability::MAINTENANCE_ENTER, DriverCapability::MAINTENANCE_EXIT],
            'url' => [DriverCapability::URL_DISCOVER, DriverCapability::URL_SET],
            default => throw new \RuntimeException(
                "unknown driver operation '$operation' (expected attach, doctor, status, capabilities, capture, "
                . 'plan, apply, env-set, pending, classify, coverage, refresh, rebase, adopt, deploy, promote, '
                . 'create, destroy, ttl, media-snapshot, maintenance, or url)'
            ),
        };
        sort($requirements, SORT_STRING);
        return $requirements;
    }

    /** @param mixed $value */
    private static function canonicalize($value) {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $child) {
            $value[$key] = self::canonicalize($child);
        }
        return $value;
    }

    /** @param array<string,mixed> $value */
    private static function canonicalJson(array $value): string {
        $json = json_encode(self::canonicalize($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            throw new \RuntimeException('could not encode driver capability report');
        }
        return $json;
    }
}
