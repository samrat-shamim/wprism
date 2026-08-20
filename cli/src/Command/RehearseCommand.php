<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

require_once __DIR__ . '/../Transport/EnvironmentDriver.php';
require_once __DIR__ . '/../Plan/PlanContract.php';
require_once __DIR__ . '/../Rehearse/RehearsalDisclosure.php';
require_once __DIR__ . '/../Rehearse/RehearsalPlanPreview.php';
require_once __DIR__ . '/AssessCommand.php';
require_once __DIR__ . '/CommandOutput.php';
require_once __DIR__ . '/EnvironmentCommand.php';
require_once __DIR__ . '/EnvironmentCommandOptions.php';

use Duo\CommandRefusalException;

/**
 * `duo rehearse <env> --from <production-env>` — the rehearsal preview
 * (round-3 MUP §2.2).
 *
 * A thin composition over the shipped lifecycle, and deliberately nothing
 * more: `EnvironmentCommand::run()` performs the materialization exactly as
 * `duo env materialize` does — the same option grammar, the same
 * `CommandEnvironmentProvider` capability negotiation against the
 * machine-local registry, the same `EnvironmentMaterializer`, the same
 * journal, and the same frozen-promotion handoff that converges the
 * candidate through the existing deploy+apply path. `--reap` is
 * `duo env reap` with the same exact resource/lease/ownership compare, which
 * is what makes a repeated reap idempotent and a stale identity a refusal.
 *
 * This class therefore owns exactly three things:
 *
 *  1. **The disclosure, printed once, first.** MUP §2.2 requires the
 *     containment banner at the TOP of the report — before the provider is
 *     contacted, not after the environment exists — because an operator who
 *     reads it after their preview is already talking to a live payment
 *     gateway has read it too late. `RehearsalDisclosure::lines()` owns the
 *     literal text and asserts it against `ProjectionVocabulary`.
 *  2. **The translation from this verb's grammar to `env materialize`'s.**
 *     `--branch` is optional here and required there; omitted, it resolves to
 *     the local site repository's current branch, which is the ref an
 *     operator rehearsing "what I have now" means.
 *  3. **The preview.** After convergence, one read-only plan of the REHEARSAL
 *     environment plus one assessment of it feed `RehearsalPlanPreview`,
 *     which states what a release would touch in the plan's own value-free
 *     category numbers. The host classifies no detailed plan row.
 *
 * Exit status: `0` a completed rehearsal (or reap), `1` a refusal or a
 * failed materialization.
 */
final class RehearseCommand {
    /**
     * The complete flag grammar. `--reap` is exclusive with the
     * materialization flags: reaping an environment and building one are two
     * requests, and accepting both in one invocation would have to guess
     * which the operator meant.
     *
     * @var list<string>
     */
    public const FLAGS = ['--from', '--branch', '--create', '--ttl', '--reap', '--format', '--limit'];

    /**
     * @param list<string> $extra everything after `<env>`
     * @param string $sourceRoot this checkout's root, for the composed assessment
     * @param callable(EnvironmentDriver,array<string,mixed>):(array<string,mixed>|int) $promote
     *        the EXISTING frozen-promotion handoff, injected by `cli/duo`'s
     *        `cmd_rehearse()` exactly as `cmd_environment()` injects it
     * @param ?callable():string $clock null reads the wall clock
     * @param ?callable(array):array $hostCatalog the assess injection seam
     */
    public static function run(
        EnvironmentDriver $driver,
        array $extra,
        ?string $envsFileOverride,
        string $sourceRoot,
        callable $promote,
        ?callable $clock = null,
        ?callable $hostCatalog = null
    ): int {
        $json = AssessCommand::wantsJson($extra);
        try {
            $flags = self::flags($extra);
        } catch (CommandRefusalException $refusal) {
            return AssessCommand::renderRefusal($refusal, $json, 'rehearse');
        }

        // Line 1 and line 2, before anything is negotiated or provisioned.
        foreach (RehearsalDisclosure::lines() as $line) {
            echo $line . "\n";
        }

        if ($flags['reap']) {
            return EnvironmentCommand::run(
                array_merge(['reap', $driver->name()], $json ? ['--format=json'] : []),
                $envsFileOverride,
                $promote
            );
        }

        try {
            $branch = $flags['branch'] ?? self::currentBranch();
            $arguments = ['materialize', $driver->name(), '--from', (string) $flags['from'], '--branch', $branch];
            if ($flags['ttl'] !== null) {
                $arguments[] = '--ttl=' . $flags['ttl'];
            }
            if ($flags['create']) {
                $arguments[] = '--create';
            }
        } catch (CommandRefusalException $refusal) {
            return AssessCommand::renderRefusal($refusal, $json, 'rehearse');
        }

        $observation = null;
        $materialized = EnvironmentCommand::run(
            $arguments,
            $envsFileOverride,
            $promote,
            static function (EnvironmentDriver $boundDriver, ?array $replayed) use (
                &$observation,
                $flags,
                $branch,
                $sourceRoot,
                $clock,
                $hostCatalog
            ): array {
                if ($replayed !== null) {
                    $observation = $replayed;
                    return $replayed;
                }
                try {
                    $observation = [
                        'format' => 'duo-rehearsal-observation/v1',
                        'preview' => self::preview(
                            $boundDriver,
                            $flags,
                            $branch,
                            $sourceRoot,
                            $clock,
                            $hostCatalog
                        ),
                        'status' => 'ok',
                    ];
                } catch (CommandRefusalException $refusal) {
                    $observation = [
                        'diagnostics' => $refusal->diagnostics,
                        'format' => 'duo-rehearsal-observation/v1',
                        'message' => $refusal->publicMessage,
                        'reason_code' => $refusal->reasonCode,
                        'remediation' => $refusal->remediation,
                        'status' => 'refused',
                    ];
                }
                return $observation;
            }
        );
        if ($materialized !== 0) {
            // `EnvironmentCommand` has already printed the provider's own
            // refusal, including a missing capability by its id. Re-wording
            // it here would replace a negotiated fact with a summary.
            return $materialized;
        }
        try {
            if (!is_array($observation)
                || ($observation['format'] ?? null) !== 'duo-rehearsal-observation/v1') {
                throw new CommandRefusalException(
                    'rehearsal_preview_unavailable',
                    'the environment converged without replayable observation evidence from its exact provider-held target fence',
                    'reap the rehearsal environment, then retry with a current Duo controller'
                );
            }
            if (($observation['status'] ?? null) === 'refused') {
                throw new CommandRefusalException(
                    (string) ($observation['reason_code'] ?? 'rehearsal_preview_unavailable'),
                    (string) ($observation['message'] ?? 'the rehearsal preview was refused'),
                    (string) ($observation['remediation'] ?? 'repair and retry the rehearsal'),
                    is_array($observation['diagnostics'] ?? null)
                        ? array_values(array_filter($observation['diagnostics'], 'is_array'))
                        : []
                );
            }
            $preview = $observation['preview'] ?? null;
            if (($observation['status'] ?? null) !== 'ok' || !is_array($preview) || array_is_list($preview)) {
                throw new CommandRefusalException(
                    'rehearsal_preview_unavailable',
                    'the replayed rehearsal observation is malformed',
                    'reap the rehearsal environment, then retry with a current Duo controller'
                );
            }
        } catch (CommandRefusalException $refusal) {
            return AssessCommand::renderRefusal($refusal, $json, 'rehearse');
        }

        if ($json) {
            echo RehearsalPlanPreview::encode($preview);

            return 0;
        }
        // The disclosure was printed first, above, before the provider ran;
        // the preview follows without repeating it.
        foreach (RehearsalPlanPreview::render($preview, $flags['limit'], false) as $line) {
            echo $line . "\n";
        }

        return 0;
    }

    /**
     * Build the "what a release would touch" preview for the converged
     * rehearsal environment.
     *
     * @param array<string,mixed> $flags
     * @return array<string,mixed>
     */
    public static function preview(
        EnvironmentDriver $driver,
        array $flags,
        string $branch,
        string $sourceRoot,
        ?callable $clock = null,
        ?callable $hostCatalog = null
    ): array {
        $now = ($clock ?? static fn (): string => gmdate('Y-m-d\TH:i:s\Z'))();
        $plan = self::targetPlan($driver);
        $assessment = AssessCommand::assess($driver, [
            'operations' => ['release'],
            'source_root' => $sourceRoot,
            'host_catalog' => $hostCatalog,
            'generated_at' => $now,
        ]);
        $surfaces = is_array($assessment['report']['surfaces'] ?? null)
            ? array_values($assessment['report']['surfaces'])
            : [];

        return RehearsalPlanPreview::build($plan, $surfaces, [
            'branch' => $branch,
            'env' => $driver->name(),
            'generated_at' => $now,
            'operation' => 'release',
            'source_env' => (string) $flags['from'],
        ]);
    }

    /**
     * One complete read-only plan of the rehearsal environment.
     *
     * @return array<string,mixed>
     */
    private static function targetPlan(EnvironmentDriver $driver): array {
        $result = $driver->captureWp(['duo', 'plan', '--repo=' . $driver->repoPath(), '--format=json']);
        if (($result['exit'] ?? 1) !== 0) {
            throw new CommandRefusalException(
                'rehearsal_plan_unavailable',
                'the rehearsal environment converged but could not produce a plan, so what a release would '
                    . 'touch cannot be previewed',
                'run duo status against the rehearsal environment, repair it, then re-run duo rehearse'
            );
        }
        $decoded = json_decode(trim((string) ($result['stdout'] ?? '')), true);
        if (!is_array($decoded)) {
            throw new CommandRefusalException(
                'rehearsal_plan_unavailable',
                'the rehearsal environment returned plan output this build could not parse as JSON',
                'upgrade the agent on the rehearsal environment, then re-run duo rehearse'
            );
        }

        return $decoded;
    }

    /**
     * The local site repository's current branch.
     *
     * `--branch` names the code+state ref materialized into the disposable
     * environment. Omitted, the honest default is the branch the operator is
     * standing on: a rehearsal is a preview of the change in front of them,
     * and defaulting to a fixed name like `main` would rehearse something
     * else and say nothing about it.
     */
    private static function currentBranch(): string {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = @proc_open(
            ['git', 'rev-parse', '--abbrev-ref', 'HEAD'],
            $descriptors,
            $pipes,
            getcwd() ?: null,
            null,
            ['bypass_shell' => true]
        );
        if (!is_resource($process)) {
            throw self::branchRefusal();
        }
        $stdout = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        $branch = trim($stdout);
        if ($exit !== 0 || $branch === '' || $branch === 'HEAD'
            || preg_match('~^[A-Za-z0-9][A-Za-z0-9._/-]{0,127}$~D', $branch) !== 1) {
            throw self::branchRefusal();
        }

        return $branch;
    }

    private static function branchRefusal(): CommandRefusalException {
        return new CommandRefusalException(
            'rehearsal_branch_unresolved',
            'no --branch was given and the current directory is not on a named Git branch, so there is no ref '
                . 'to materialize',
            'pass --branch <ref> explicitly, or run duo rehearse from a checked-out branch of the site repository'
        );
    }

    /**
     * The closed flag grammar.
     *
     * @param list<string> $extra
     * @return array<string,mixed>
     */
    private static function flags(array $extra): array {
        $out = [
            'branch' => null,
            'create' => false,
            'from' => null,
            'limit' => RehearsalPlanPreview::DEFAULT_LIMIT,
            'reap' => false,
            'ttl' => null,
        ];
        $limitSeen = false;
        for ($index = 0, $count = count($extra); $index < $count; $index++) {
            $arg = $extra[$index];
            if (!is_string($arg)) {
                throw self::invalidArguments('rehearse received a non-string argument');
            }
            $name = str_contains($arg, '=') ? explode('=', $arg, 2)[0] : $arg;
            $value = str_contains($arg, '=') ? substr($arg, strlen($name) + 1) : null;
            switch ($name) {
                case '--from':
                    // `--from prod` and `--from=prod` both, because MUP §2.2
                    // writes the spaced form and `duo env materialize`
                    // already accepts both.
                    $value ??= $extra[++$index] ?? null;
                    if ($out['from'] !== null || !is_string($value) || $value === '' || str_starts_with($value, '-')) {
                        throw self::invalidArguments('--from requires exactly one production environment name');
                    }
                    $out['from'] = $value;
                    break;
                case '--branch':
                    $value ??= $extra[++$index] ?? null;
                    if ($out['branch'] !== null || !is_string($value) || $value === '' || str_starts_with($value, '-')) {
                        throw self::invalidArguments('--branch requires exactly one Git ref');
                    }
                    $out['branch'] = $value;
                    break;
                case '--ttl':
                    $value ??= $extra[++$index] ?? null;
                    if ($out['ttl'] !== null || !is_string($value) || preg_match('/^[0-9]+$/D', $value) !== 1) {
                        throw self::invalidArguments('--ttl requires whole seconds');
                    }
                    $out['ttl'] = $value;
                    break;
                case '--create':
                    $out['create'] = true;
                    break;
                case '--reap':
                    $out['reap'] = true;
                    break;
                case '--limit':
                    if ($limitSeen
                        || $value === null
                        || preg_match('/^(?:[1-9]|[1-9][0-9]|1[0-9]{2}|200)$/D', $value) !== 1) {
                        throw self::invalidArguments('--limit must be a single value between 1 and 200');
                    }
                    $limitSeen = true;
                    $out['limit'] = (int) $value;
                    break;
                case '--format':
                case '--json':
                    break;
                default:
                    throw self::invalidArguments("rehearse received an option it does not define: '$name'");
            }
        }
        if ($out['reap']) {
            if ($out['from'] !== null || $out['branch'] !== null || $out['create'] || $out['ttl'] !== null) {
                throw self::invalidArguments('--reap destroys or detaches an environment and takes no other flags');
            }

            return $out;
        }
        if ($out['from'] === null) {
            throw self::invalidArguments('rehearse requires --from <production-env>');
        }

        return $out;
    }

    private static function invalidArguments(string $message): CommandRefusalException {
        return new CommandRefusalException(
            'invalid_arguments',
            $message,
            'duo rehearse <env> --from <production-env> [--branch <ref>] [--create] [--ttl <seconds>] '
                . '[--limit=<1..200>] [--format=json], or duo rehearse <env> --reap'
        );
    }
}
