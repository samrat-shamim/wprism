<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

require_once __DIR__ . '/../Environment/Registry.php';
require_once __DIR__ . '/../Environment/EnvironmentProviderProtocol.php';
require_once __DIR__ . '/../Environment/EnvironmentLifecycle.php';
require_once __DIR__ . '/../Transport/Transport.php';
// The concrete transports, in the same order EnvironmentCommand.php:10-13
// pulls them: `--cycle` needs the target's repo_path for
// `repository-materialize`, and Transport::make() resolves the class by
// registry `transport` value rather than autoloading it.
require_once __DIR__ . '/../Transport/LocalTransport.php';
require_once __DIR__ . '/../Transport/DockerTransport.php';
require_once __DIR__ . '/../Transport/SshTransport.php';

/**
 * `duo env provider-check <env>` — diagnose a branch-environment provider
 * before it is trusted with a production snapshot.
 *
 * WHY THIS COMMAND EXISTS
 * -----------------------
 * Duo orchestrates providers; it does not supply hosting, so every customer
 * writes their own (tools/reference-env-provider.php:2-11 is DEV-ONLY, and
 * Adopt.php tars only `agent manifests recovery`). Before this command the
 * only way to exercise a provider was `duo env materialize`, whose second
 * provider action is `snapshot-prepare` — the one that FREEZES the named
 * production source (EnvironmentLifecycle.php:1013-1021). Learning the
 * contract one refusal at a time therefore meant learning it against prod.
 * This is the same posture `duo doctor` already takes toward a target:
 * diagnose before you trust.
 *
 * WHY IT CANNOT DRIFT FROM THE ORCHESTRATOR
 * -----------------------------------------
 * Every provider byte this command sends or accepts goes through the REAL
 * `CommandEnvironmentProvider` — `fromEnvironment()` for the machine-local
 * config rules, `capabilities()` for negotiation, `perform()` for every
 * action. There is no second client here, so the harness structurally cannot
 * send a request the orchestrator would not send, nor accept a response the
 * orchestrator would refuse. The capability sets it diffs and the field types
 * it names both come from EnvironmentProviderProtocol, which the orchestrator
 * itself reads (EnvironmentLifecycle.php assertAction/validateActionResult).
 *
 * TWO TIERS
 * ---------
 *  - negotiate (default): non-mutating. Config validation, ONE `capabilities`
 *    call, ONE `inspect`, then set arithmetic. It contacts no source and
 *    prepares no snapshot.
 *  - `--cycle --from=<src> --confirm-disposable`: the full synthetic
 *    materialize/reap cycle across every non-rehearsal action, with the snapshot ABORTED
 *    rather than retained and the target destroyed/detached in a finally.
 *    `--confirm-disposable` is mandatory because `snapshot-prepare` freezes
 *    the named source; the harness never infers disposability from a name, a
 *    TTL, or the absence of a site.duo.json entry. Same posture as
 *    `duo recover --prune-retained --confirm-prune`.
 */
final class EnvironmentProviderCheckCommand {
    public const FORMAT = 'duo-branch-environment-provider-check/v1';
    /** Bounded, provider-owned, and reaped in the same run; never an operator input. */
    private const CYCLE_TTL_SECONDS = 300;

    /** @var list<array{check:string,state:string,detail:string,remediation:string}> */
    private array $checks = [];
    /** @var list<array{action:string,field:string,expected:string,observed:string}> */
    private array $findings = [];

    /**
     * @param array{from:?string,cycle:bool,confirm:bool,create:bool,role:string,branch:?string,json:bool} $options
     */
    public static function run(string $targetName, array $options, ?string $envsFileOverride): int {
        $command = new self();
        try {
            $body = $options['cycle']
                ? $command->cycle($targetName, $options, $envsFileOverride)
                : $command->negotiate($targetName, $options, $envsFileOverride);
        } catch (\Throwable $e) {
            fwrite(STDERR, "duo: env provider-check: {$e->getMessage()}\n");
            return 1;
        }
        $command->render($body, $options['json']);
        return $body['verdict'] === 'READY' ? 0 : 1;
    }

    /**
     * Non-mutating tier. Nothing here writes provider state, and no source
     * environment is contacted at all.
     *
     * @param array{from:?string,cycle:bool,confirm:bool,create:bool,role:string,branch:?string,json:bool} $options
     * @return array<string,mixed>
     */
    private function negotiate(string $targetName, array $options, ?string $envsFileOverride): array {
        $operationId = self::operationId();
        $provider = $this->openProvider($targetName, $envsFileOverride);
        $body = [
            'capabilities' => null,
            'environment' => $targetName,
            'format' => self::FORMAT,
            'operation_id' => $operationId,
            'pin' => null,
            'profiles' => [],
            'provider' => null,
            'role' => $options['role'],
            'tier' => 'negotiate',
        ];
        if ($provider === null) {
            return $this->finish($body);
        }
        $report = $this->negotiateCapabilities($provider, $operationId);
        if ($report === null) {
            return $this->finish($body);
        }
        $pin = $report->pin();
        $body['capabilities'] = $report->toArray()['capabilities'];
        $body['pin'] = $pin;
        $body['provider'] = $pin['provider'];
        $body['profiles'] = $this->diffRequirementSets($report, $options['role']);
        $this->probeInspect($provider, $operationId, $options['role']);
        return $this->finish($body);
    }

    /**
     * Mutating tier: every action the orchestrator would send, in its order,
     * against a source the operator has explicitly declared disposable.
     *
     * @param array{from:?string,cycle:bool,confirm:bool,create:bool,role:string,branch:?string,json:bool} $options
     * @return array<string,mixed>
     */
    private function cycle(string $targetName, array $options, ?string $envsFileOverride): array {
        $sourceName = (string) $options['from'];
        $mode = $options['create'] ? 'create' : 'attach';
        $reapAction = $mode === 'create' ? 'destroy' : 'detach';
        $operationId = self::operationId();
        $body = [
            'actions' => [],
            'environment' => $targetName,
            'format' => self::FORMAT,
            'mode' => $mode,
            'operation_id' => $operationId,
            'pin' => null,
            'profiles' => [],
            'provider' => null,
            'source_environment' => $sourceName,
            'tier' => 'cycle',
        ];
        $sourceProvider = $this->openProvider($sourceName, $envsFileOverride, 'source ');
        $targetProvider = $this->openProvider($targetName, $envsFileOverride, 'target ');
        if ($sourceProvider === null || $targetProvider === null) {
            return $this->finish($body);
        }
        $sourceReport = $this->negotiateCapabilities($sourceProvider, $operationId, 'source ');
        $targetReport = $this->negotiateCapabilities($targetProvider, $operationId, 'target ');
        if ($sourceReport === null || $targetReport === null) {
            return $this->finish($body);
        }
        $body['pin'] = $targetReport->pin();
        $body['provider'] = $body['pin']['provider'];
        $body['profiles'] = array_merge(
            $this->diffRequirementSets($sourceReport, 'source'),
            $this->diffRequirementSets($targetReport, 'target')
        );

        $branch = $this->branchPin($targetName, $options, $envsFileOverride);
        $intent = hash('sha256', EnvironmentLifecycleCanon::encode([
            'format' => self::FORMAT,
            'mode' => $mode,
            'operation_id' => $operationId,
            'source_environment' => $sourceName,
            'target_environment' => $targetName,
        ]));
        $owner = 'duo-provider-check-materialize-' . $operationId;
        $reapOwner = 'duo-provider-check-reap-' . $operationId;
        $prepared = null;
        $identity = null;
        $fence = null;
        $reaped = false;

        try {
            $source = $this->act($sourceProvider, 'inspect', $operationId, ['role' => 'source']);
            if ($source === null) {
                return $this->finish($body);
            }
            $prepared = $this->act($sourceProvider, 'snapshot-prepare', $operationId,
                self::identityInput($source) + ['snapshot_session_id' => 'duo-provider-check-session-' . $operationId]);
            if ($prepared === null) {
                return $this->finish($body);
            }
            $session = self::sessionInput($prepared);
            $snapshot = $this->act($sourceProvider, 'snapshot-create', $operationId, $session + [
                'expected_semantic_snapshot_sha256' => hash('sha256', 'duo-provider-check-semantic:' . $operationId),
                'production_commit' => $branch['commit'],
            ]);
            if ($snapshot === null) {
                return $this->finish($body);
            }
            $readback = $this->act($sourceProvider, 'snapshot-read', $operationId, $session + [
                'expected_snapshot_set_id' => $snapshot['snapshot_set_id'],
                'expected_snapshot_set_receipt_sha256' => $snapshot['snapshot_set_receipt_sha256'],
            ]);
            if ($readback === null) {
                return $this->finish($body);
            }
            $identity = $this->act($targetProvider, $mode, $operationId, [
                'intent_sha256' => $intent,
                'mode' => $mode,
                'target_environment' => $targetName,
            ]);
            if ($identity === null) {
                return $this->finish($body);
            }
            $fence = $this->act($targetProvider, 'mutation-acquire', $operationId,
                self::identityInput($identity) + ['mutation_owner' => $owner]);
            if ($fence === null) {
                return $this->finish($body);
            }
            $fenced = self::identityInput($identity) + self::mutationInput($fence);
            $steps = [
                ['mutation-read', $fenced],
                ['snapshot-restore', $fenced + [
                    'database_sha256' => $snapshot['database_sha256'],
                    'media_sha256' => $snapshot['media_sha256'],
                    'snapshot_set_id' => $snapshot['snapshot_set_id'],
                ]],
                ['repository-materialize', $fenced + [
                    'branch_commit' => $branch['commit'],
                    'branch_ref' => $branch['ref'],
                    'repo_path' => $branch['repo_path'],
                ]],
                ['url-set', $fenced + ['url' => $identity['url']]],
                ['ttl-set', $fenced + ['ttl_seconds' => self::CYCLE_TTL_SECONDS]],
            ];
            $ttl = null;
            foreach ($steps as [$action, $input]) {
                $result = $this->act($targetProvider, $action, $operationId, $input);
                if ($result === null) {
                    return $this->finish($body);
                }
                if ($action === 'ttl-set') {
                    $ttl = $result;
                }
            }
            if ($this->act($targetProvider, 'ttl-read', $operationId, $fenced + self::ttlInput((array) $ttl)) === null) {
                return $this->finish($body);
            }
            if ($this->act($targetProvider, 'inspect', $operationId,
                self::identityInput($identity) + ['role' => 'target']) === null) {
                return $this->finish($body);
            }
            if ($this->act($targetProvider, 'mutation-release', $operationId, $fenced) === null) {
                return $this->finish($body);
            }
            $fence = null;
            // The terminal reap takes its OWN fence under its OWN operation
            // id, exactly as the orchestrator's `reap-acquire` path does when
            // the materialization fence is no longer held
            // (EnvironmentLifecycle.php:1700 mints a fresh operation id;
            // :1719 and :1737 perform the acquire and the reap under it).
            // The reference provider keys each fence to
            // resource|generation|operation, so re-using the materialize
            // operation id here made a conformant provider refuse the second
            // owner with 'mutation acquire is not idempotent/exclusive' — the
            // first live run of regress_env_provider_conformance_live.sh
            // blocked on exactly that.
            $reapOperationId = self::operationId();
            $reapFence = $this->act($targetProvider, 'mutation-acquire', $reapOperationId,
                self::identityInput($identity) + ['mutation_owner' => $reapOwner]);
            if ($reapFence === null) {
                return $this->finish($body);
            }
            $fence = $reapFence;
            $reap = $this->act($targetProvider, $reapAction, $reapOperationId,
                self::identityInput($identity) + self::mutationInput($reapFence) + ['compare_and_reap' => true]);
            if ($reap === null) {
                return $this->finish($body);
            }
            $reaped = true;
            $fence = null;
            $identity = null;
        } finally {
            // Teardown is the point of the tier, not an afterthought: a
            // harness that can leave a live target or a frozen source behind
            // is worse than no harness at all.
            $this->teardown($targetProvider, $sourceProvider, $operationId, [
                'identity' => $reaped ? null : $identity,
                'fence' => $fence,
                'reap_action' => $reapAction,
                'reap_owner' => $reapOwner,
                'prepared' => $prepared,
            ]);
            $body['actions'] = array_values(array_map(
                static fn(array $check): string => $check['check'],
                array_filter(
                    $this->checks,
                    static fn(array $check): bool => str_starts_with($check['check'], 'action ')
                        || str_starts_with($check['check'], 'teardown action ')
                )
            ));
        }
        return $this->finish($body);
    }

    /**
     * Release, reap and unfreeze whatever this run still owns.
     *
     * @param array{identity:?array<string,mixed>,fence:?array<string,mixed>,reap_action:string,reap_owner:string,prepared:?array<string,mixed>} $held
     */
    private function teardown(
        CommandEnvironmentProvider $target,
        CommandEnvironmentProvider $source,
        string $operationId,
        array $held
    ): void {
        if ($held['identity'] !== null) {
            $fence = $held['fence'];
            // A fresh operation id for the same reason the cycle's own reap
            // uses one: a fence is keyed per operation, and the fence this
            // teardown wants may follow a released materialize fence.
            $teardownOperationId = self::operationId();
            if ($fence === null) {
                $fence = $this->act($target, 'mutation-acquire', $teardownOperationId,
                    self::identityInput($held['identity']) + ['mutation_owner' => $held['reap_owner']], 'teardown ');
            }
            if ($fence !== null) {
                $this->act($target, $held['reap_action'], $teardownOperationId,
                    self::identityInput($held['identity']) + self::mutationInput($fence)
                        + ['compare_and_reap' => true], 'teardown ');
            }
        }
        if ($held['prepared'] !== null) {
            // Aborting is what UNFREEZES the source and discards the immutable
            // set this run created; the tier deliberately retains nothing.
            $this->act($source, 'snapshot-abort', $operationId, self::sessionInput($held['prepared']), 'teardown ');
        }
    }

    /**
     * One provider action through the real client, with a typed re-diagnosis
     * of the captured response when the orchestrator refuses it.
     *
     * @param array<string,mixed> $input
     * @return ?array<string,mixed>
     */
    private function act(
        CommandEnvironmentProvider $provider,
        string $action,
        string $operationId,
        array $input,
        string $prefix = ''
    ): ?array {
        try {
            $result = $provider->perform($action, $operationId, $input);
            $this->pass($prefix . 'action ' . $action, 'result conforms to ' . CommandEnvironmentProvider::RESPONSE_FORMAT);
            return $result;
        } catch (\Throwable $e) {
            $response = $provider->lastResponse();
            $finding = EnvironmentProviderProtocol::diagnose($action, $response['result'] ?? null);
            if ($finding !== null) {
                $this->findings[] = $finding;
            }
            $this->block(
                $prefix . 'action ' . $action,
                $e->getMessage(),
                $finding === null
                    ? 'Compare this action against docs/branch-environment-provider.md; the orchestrator refuses the whole operation here.'
                    : "In the $action result, `{$finding['field']}` must be {$finding['expected']}; the provider sent {$finding['observed']}."
            );
            return null;
        }
    }

    /** Construct through the product's own machine-local config rules. */
    private function openProvider(string $environment, ?string $envsFileOverride, string $prefix = ''): ?CommandEnvironmentProvider {
        try {
            $envs = Registry::load($envsFileOverride, getcwd() ?: '.');
            $provider = CommandEnvironmentProvider::fromEnvironment($environment, Registry::get($envs, $environment));
        } catch (\Throwable $e) {
            $this->block(
                $prefix . 'environment_provider configuration',
                $e->getMessage(),
                'environment_provider is privileged host configuration: put {"command": ["/absolute/path", ...],'
                . ' "timeout_seconds": 1..3600} under this environment in .duo-envs.json, never in site.duo.json.'
            );
            return null;
        }
        $this->pass($prefix . 'environment_provider configuration', "machine-local argv accepted for env '$environment'");
        return $provider;
    }

    private function negotiateCapabilities(
        CommandEnvironmentProvider $provider,
        string $operationId,
        string $prefix = ''
    ): ?EnvironmentProviderCapabilityReport {
        try {
            $report = $provider->capabilities($operationId);
        } catch (\Throwable $e) {
            $finding = EnvironmentProviderProtocol::diagnose('capabilities', $provider->lastResponse()['result'] ?? null);
            if ($finding !== null) {
                $this->findings[] = $finding;
            }
            $this->block(
                $prefix . 'capability negotiation',
                $e->getMessage(),
                'Answer the `capabilities` action with {"capabilities": [ids...]} drawn from the vocabulary in'
                . ' docs/branch-environment-provider.md, and keep provider.id/provider.protocol stable for the whole operation.'
            );
            return null;
        }
        $pin = $report->pin();
        $this->pass(
            $prefix . 'capability negotiation',
            "provider {$pin['provider']['id']} protocol {$pin['provider']['protocol']} pinned at {$pin['capabilities_sha256']}"
        );
        return $report;
    }

    /**
     * Diff the advertised set against every requirement set for this side,
     * naming missing ids exactly as EnvironmentProviderCapabilityReport
     * ::require() names them (EnvironmentLifecycle.php:92-105).
     *
     * @return array<string,bool>
     */
    private function diffRequirementSets(EnvironmentProviderCapabilityReport $report, string $side): array {
        $profiles = [];
        foreach (EnvironmentProviderProtocol::requirementSets() as $set) {
            if ($set['side'] !== $side) {
                continue;
            }
            // Containment is a host-specific rehearsal proof and cannot be
            // exercised honestly by this synthetic materialize/reap cycle.
            // The protocol document still publishes that requirement set;
            // rehearsal itself negotiates and invokes it before restore.
            if (str_starts_with($set['id'], 'rehearse-target-')) {
                continue;
            }
            $profiles[$set['id']] = $this->requireSet($report, $set['id'], $set['capabilities'], $set['operation']);
            if ($set['conditional'] !== []) {
                $this->requireSet(
                    $report,
                    $set['id'] . ' (' . $set['conditional_when'] . ')',
                    array_merge($set['capabilities'], $set['conditional']),
                    $set['operation']
                );
            }
        }
        return $profiles;
    }

    /** @param list<string> $required */
    private function requireSet(
        EnvironmentProviderCapabilityReport $report,
        string $label,
        array $required,
        string $operation
    ): bool {
        try {
            $report->require($required, $operation);
        } catch (\Throwable $e) {
            $this->block(
                'capability set ' . $label,
                $e->getMessage(),
                'Advertise the missing ids from the `capabilities` action and implement their actions, or accept that'
                . " this provider cannot $operation."
            );
            return false;
        }
        $this->pass('capability set ' . $label, 'all ' . count($required) . " ids advertised to $operation");
        return true;
    }

    private function probeInspect(CommandEnvironmentProvider $provider, string $operationId, string $role): void {
        // `inspect` is the one action the orchestrator runs before it has
        // decided anything (EnvironmentLifecycle.php:1005), which is exactly
        // why it is the only action safe to run in the non-mutating tier.
        $this->act($provider, 'inspect', $operationId, ['role' => $role]);
    }

    /**
     * The commit/ref/repo triple `repository-materialize` is given.
     *
     * @param array{from:?string,cycle:bool,confirm:bool,create:bool,role:string,branch:?string,json:bool} $options
     * @return array{commit:string,ref:string,repo_path:string}
     */
    private function branchPin(string $targetName, array $options, ?string $envsFileOverride): array {
        $ref = $options['branch'] ?? trim(self::git(['symbolic-ref', '--quiet', '--short', 'HEAD']));
        if ($ref === '') {
            throw new \RuntimeException('provider-check --cycle needs --branch <ref> when HEAD is detached');
        }
        $commit = trim(self::git(['rev-parse', '--verify', $ref . '^{commit}']));
        if (preg_match('/^[a-f0-9]{40}(?:[a-f0-9]{24})?$/D', $commit) !== 1) {
            throw new \RuntimeException("provider-check could not resolve '$ref' to a commit");
        }
        $envs = Registry::load($envsFileOverride, getcwd() ?: '.');
        return [
            'commit' => $commit,
            'ref' => $ref,
            'repo_path' => Transport::make($targetName, Registry::get($envs, $targetName))->repoPath(),
        ];
    }

    /** @param list<string> $argv */
    private static function git(array $argv): string {
        $process = proc_open(
            array_merge(['git'], $argv),
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            getcwd() ?: null,
            null,
            ['bypass_shell' => true]
        );
        if (!is_resource($process)) {
            throw new \RuntimeException('provider-check could not run git');
        }
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        return $stdout;
    }

    /** @param array<string,mixed> $identity @return array<string,mixed> */
    private static function identityInput(array $identity): array {
        return [
            'expected_environment_identity' => $identity['environment_identity'],
            'expected_lease_generation' => $identity['lease_generation'],
            'expected_lease_id' => $identity['lease_id'],
            'expected_ownership_receipt_sha256' => $identity['ownership_receipt_sha256'],
            'expected_resource_id' => $identity['resource_id'],
        ];
    }

    /** @param array<string,mixed> $fence @return array<string,mixed> */
    private static function mutationInput(array $fence): array {
        return [
            'expected_mutation_generation' => $fence['mutation_generation'],
            'expected_mutation_id' => $fence['mutation_id'],
            'expected_mutation_owner' => $fence['mutation_owner'],
            'expected_mutation_receipt_sha256' => $fence['mutation_receipt_sha256'],
        ];
    }

    /** @param array<string,mixed> $ttl @return array<string,mixed> */
    private static function ttlInput(array $ttl): array {
        return [
            'expected_expires_at' => $ttl['expires_at'] ?? null,
            'expected_ttl_generation' => $ttl['ttl_generation'] ?? null,
            'expected_ttl_lease_id' => $ttl['ttl_lease_id'] ?? null,
            'expected_ttl_receipt_sha256' => $ttl['ttl_receipt_sha256'] ?? null,
        ];
    }

    /** @param array<string,mixed> $prepared @return array<string,mixed> */
    private static function sessionInput(array $prepared): array {
        return [
            'expected_snapshot_session_id' => $prepared['snapshot_session_id'],
            'expected_source_identity' => $prepared['source_identity'],
            'expected_source_lease_generation' => $prepared['lease_generation'],
            'expected_source_lease_id' => $prepared['lease_id'],
            'expected_source_lease_receipt_sha256' => $prepared['lease_receipt_sha256'],
        ];
    }

    /** Long enough for CommandEnvironmentProvider::assertIdentifier's 8..256 bound. */
    private static function operationId(): string {
        return 'duo-provider-check-' . bin2hex(random_bytes(8));
    }

    private function pass(string $check, string $detail): void {
        $this->checks[] = ['check' => $check, 'detail' => $detail, 'remediation' => '', 'state' => 'pass'];
    }

    private function block(string $check, string $detail, string $remediation): void {
        $this->checks[] = ['check' => $check, 'detail' => $detail, 'remediation' => $remediation, 'state' => 'blocked'];
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    private function finish(array $body): array {
        $blocked = array_filter($this->checks, static fn(array $check): bool => $check['state'] !== 'pass');
        $body['checks'] = $this->checks;
        $body['findings'] = $this->findings;
        $body['verdict'] = $blocked === [] ? 'READY' : 'BLOCKED';
        ksort($body, SORT_STRING);
        return $body;
    }

    /** @param array<string,mixed> $body */
    private function render(array $body, bool $json): void {
        if ($json) {
            echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
            return;
        }
        echo "provider-check {$body['environment']} tier {$body['tier']}: {$body['verdict']}\n";
        foreach ($body['checks'] as $check) {
            $mark = $check['state'] === 'pass' ? 'PASS' : 'BLOCKED';
            echo "[$mark] {$check['check']} — {$check['detail']}\n";
            if ($check['state'] !== 'pass') {
                echo "  remediation: {$check['remediation']}\n";
            }
        }
        foreach ($body['findings'] as $finding) {
            echo "finding {$finding['action']}.{$finding['field']}: expected {$finding['expected']},"
                . " observed {$finding['observed']}\n";
        }
        $complete = array_keys(array_filter($body['profiles']));
        echo 'complete requirement sets: ' . ($complete === [] ? 'none' : implode(', ', $complete)) . "\n";
    }
}
