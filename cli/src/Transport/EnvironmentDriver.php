<?php
declare(strict_types=1);

namespace WPrism\Orchestrator;

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

/** Finite target-control protocol required by newcomer-facing compositions. */
interface BoundedControlDriver extends EnvironmentDriver {
    /** @return array{exit:int, stdout:string, stderr:string} */
    public function captureRawBounded(
        string $script,
        int $timeoutMilliseconds,
        int $maxStdoutBytes,
        int $maxStderrBytes
    ): array;

    /** @return array{exit:int, stdout:string, stderr:string} */
    public function captureWpBounded(
        array $wpArgs,
        int $timeoutMilliseconds,
        int $maxStdoutBytes,
        int $maxStderrBytes
    ): array;
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
    public const BOUNDED_CONTROL = 'control.bounded';
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
            self::WP_CONTROL, self::RAW_CONTROL, self::BOUNDED_CONTROL,
            self::CODE_TRANSFER, self::CODE_MATERIALIZE,
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
    public const FORMAT = 'wprism-environment-driver-capabilities/v1';
    public const DRIVER_PROTOCOL = 1;

    /**
     * @param array<string,bool> $supported
     * @param array<string,array{reason:string,remediation:string}> $unsupportedDetails
     */
    public static function forDriver(
        string $environment,
        string $driverId,
        string $operation,
        array $supported,
        array $unsupportedDetails = []
    ): self {
        $known = DriverCapability::all();
        $unknown = array_diff(array_keys($supported), $known);
        if ($unknown !== []) {
            throw new \RuntimeException(
                "driver '$driverId' declared unknown capability '" . reset($unknown) . "'"
            );
        }
        $unknownDetails = array_diff(array_keys($unsupportedDetails), $known);
        if ($unknownDetails !== []) {
            throw new \RuntimeException(
                "driver '$driverId' described unknown capability '" . reset($unknownDetails) . "'"
            );
        }
        foreach ($unsupportedDetails as $capability => $detail) {
            $keys = is_array($detail) ? array_keys($detail) : [];
            sort($keys, SORT_STRING);
            if ($keys !== ['reason', 'remediation']
                || !is_string($detail['reason'] ?? null)
                || trim((string) ($detail['reason'] ?? '')) === ''
                || !is_string($detail['remediation'] ?? null)
                || trim((string) ($detail['remediation'] ?? '')) === ''
                || !empty($supported[$capability])) {
                throw new \RuntimeException(
                    "driver '$driverId' returned malformed unsupported detail for '$capability'"
                );
            }
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
            $detail = $unsupportedDetails[$capability] ?? null;
            $checks[] = [
                'capability' => $capability,
                'state' => $ok ? 'supported' : 'unsupported',
                'reason' => $ok
                    ? 'driver declares the exact required capability'
                    : (is_array($detail)
                        ? $detail['reason']
                        : "driver '$driverId' does not implement '$capability'; no emulation is permitted"),
                'remediation' => $ok
                    ? ''
                    : (is_array($detail)
                        ? $detail['remediation']
                        : 'select or configure a driver that explicitly implements this capability'),
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

    /** @return list<array{capability:string,state:string,reason:string,remediation:string}> */
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
            // issue #3500. `code-resolve` resolves on the HOST — always, on every
            // transport — so attach is the whole of its demand. Requiring
            // WP-CLI or raw control would refuse a resolution on a target that
            // is merely asleep, and on local and docker the bytes never leave
            // the host at all. issue #3514 added the ssh arm, which does contact
            // the target to push and to verify; that stays out of this
            // requirement set on purpose. The requirements are the driver's
            // DECLARED capabilities, and an ssh driver declares raw and WP
            // control unconditionally, so listing them here would refuse
            // nothing new while moving every driver's capability report
            // digest.
            'code-resolve' => [DriverCapability::ATTACH],
            'doctor' => [
                DriverCapability::ATTACH, DriverCapability::RAW_CONTROL, DriverCapability::WP_CONTROL,
            ],
            // `assess` and `contract` are read-only over WP-CLI: assess runs
            // `wp wprism assess-inventory` and `wp wprism capabilities`, and every
            // contract subcommand that contacts the target does so by
            // running an assessment. They demand exactly what the other
            // read-only WP-CLI passthroughs demand and nothing more —
            // asking for raw control would refuse on drivers that can
            // legitimately answer the question (issue #3344).
            // `verify` joins the read-only WP-CLI set (MUP §2.4): it
            // re-reads the plan and probes declared journeys over HTTP from
            // the HOST. It writes nothing to the target, so demanding raw
            // control or a snapshot capability for it would refuse a
            // verification on a driver that can honestly answer it.
            'init', 'status', 'capabilities', 'adapter-observe', 'capture', 'lint', 'plan', 'explain', 'apply', 'env-set',
            // `code-classify` (issue #3499) joins the same read-only WP-CLI set:
            // it runs `wp wprism code-inventory` and writes only into the LOCAL
            // checkout. Demanding raw control would refuse a migration on a
            // driver that can honestly answer the one question it asks.
            'pending', 'classify', 'coverage', 'scope', 'assess', 'contract', 'verify', 'code-classify' => [
                DriverCapability::ATTACH, DriverCapability::WP_CONTROL,
            ],
            'refresh', 'rebase' => [
                DriverCapability::ATTACH, DriverCapability::RAW_CONTROL, DriverCapability::WP_CONTROL,
            ],
            // Staging writes only target-private Git control state. Prepare
            // reads that state and the existing WP planning surface, so it
            // must not inherit promote's checkpoint/materialization demands.
            'stage-source', 'authority-policy' => [DriverCapability::ATTACH, DriverCapability::RAW_CONTROL],
            'release-prepare' => [
                DriverCapability::ATTACH, DriverCapability::RAW_CONTROL, DriverCapability::WP_CONTROL,
            ],
            'adopt' => [
                DriverCapability::ATTACH, DriverCapability::BOOTSTRAP,
                DriverCapability::CODE_TRANSFER, DriverCapability::RAW_CONTROL, DriverCapability::WP_CONTROL,
            ],
            'unadopt' => [
                DriverCapability::ATTACH, DriverCapability::BOOTSTRAP,
                DriverCapability::RAW_CONTROL, DriverCapability::WP_CONTROL,
            ],
            'onboard' => [
                DriverCapability::ATTACH, DriverCapability::BOOTSTRAP,
                DriverCapability::CODE_TRANSFER, DriverCapability::RAW_CONTROL,
                DriverCapability::WP_CONTROL, DriverCapability::BOUNDED_CONTROL,
            ],
            // A resumed handoff runs only target Git over captureRaw(). It
            // must remain usable after init even on a control plane, such as
            // Docker, that cannot deliver the agent itself.
            'onboard-handoff' => [
                DriverCapability::ATTACH, DriverCapability::RAW_CONTROL, DriverCapability::BOUNDED_CONTROL,
            ],
            'deploy' => [
                DriverCapability::ATTACH, DriverCapability::CODE_MATERIALIZE,
                DriverCapability::RAW_CONTROL, DriverCapability::WP_CONTROL,
            ],
            // `release` composes `promote` rather than forking it (MUP §2.3
            // step 4), so it demands exactly what promote demands. Asking
            // for more here would refuse a target promote can already reach,
            // and asking for less would let release reach the mutation gate
            // on a driver that cannot take the checkpoint the frozen plan's
            // recovery claim promises.
            'promote', 'release' => [
                DriverCapability::ATTACH, DriverCapability::CODE_MATERIALIZE,
                DriverCapability::DB_SNAPSHOT_CREATE, DriverCapability::DB_SNAPSHOT_RESTORE,
                DriverCapability::RAW_CONTROL, DriverCapability::WP_CONTROL,
            ],
            // `recover` restores, it does not create: it drives the rollback
            // authority runtime over the raw control path and imports a
            // checkpoint that already exists. Requiring DB_SNAPSHOT_CREATE
            // would refuse recovery on a target that can only be restored,
            // which is the one moment that refusal would be most expensive.
            'recover' => [
                DriverCapability::ATTACH, DriverCapability::DB_SNAPSHOT_RESTORE,
                DriverCapability::RAW_CONTROL, DriverCapability::WP_CONTROL,
            ],
            // `rehearse` materializes a disposable environment from one
            // coherent source snapshot and then converges it, so it is
            // `env materialize`'s driver-side demand: promote's set plus the
            // snapshot READ that copying a source into a target needs. The
            // provider-side capabilities MUP §2.2 lists
            // (`snapshot.set.*`, `environment.attach|create`,
            // `repository.materialize`, `environment.url.discover`,
            // `operation.receipts`) are a different, provider-owned
            // vocabulary and are negotiated by `CommandEnvironmentProvider`,
            // never inferred from a driver capability.
            'rehearse' => [
                DriverCapability::ATTACH, DriverCapability::CODE_MATERIALIZE,
                DriverCapability::DB_SNAPSHOT_CREATE, DriverCapability::DB_SNAPSHOT_READ,
                DriverCapability::DB_SNAPSHOT_RESTORE,
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
                "unknown driver operation '$operation' (expected attach, doctor, init, status, capabilities, capture, "
                . 'lint, plan, explain, apply, env-set, pending, classify, coverage, scope, assess, contract, verify, '
                . 'refresh, rebase, adapter-observe, adopt, onboard, deploy, promote, stage-source, authority-policy, '
                . 'release-prepare, release, '
                . 'recover, rehearse, create, destroy, '
                . 'ttl, media-snapshot, maintenance, or url)'
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
