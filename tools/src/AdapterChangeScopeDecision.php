<?php

declare(strict_types=1);

namespace WPrism\Tooling;

require_once __DIR__ . '/AdapterChangeScope.php';

/**
 * Turn closed ownership classification into package-gate authority.
 *
 * AdapterChangeScope retains useful engine and multi-adapter inventory. This
 * decision is intentionally stricter: the only narrow answer is one adapter,
 * and every other input selects the full project gate. A future package runner
 * can therefore trust `gate=adapter` without reproducing path policy.
 *
 * @phpstan-import-type Change from AdapterChangeScope
 * @phpstan-import-type ScopeResult from AdapterChangeScope
 * @phpstan-import-type ScenarioGate from AdapterChangeScope
 * @phpstan-type Decision array{
 *     format:'wprism-adapter-change-scope/v1',
 *     gate:'adapter'|'full',
 *     adapter:?string,
 *     command:non-empty-list<string>,
 *     scenario_gates:list<ScenarioGate>,
 *     reason_code:string,
 *     classification:ScopeResult
 * }
 */
final class AdapterChangeScopeDecision
{
    public const FORMAT = 'wprism-adapter-change-scope/v1';
    public const GATE_ADAPTER = 'adapter';
    public const GATE_FULL = 'full';

    /**
     * @param list<string|array{from:string,to:string}> $changes
     * @return Decision
     */
    public static function decide(array $changes): array
    {
        $classification = AdapterChangeScope::classify($changes);
        $adapter = $classification['scope'] === AdapterChangeScope::SCOPE_ADAPTERS
            && count($classification['adapters']) === 1
            ? $classification['adapters'][0]
            : null;

        if ($adapter !== null) {
            return self::result(
                self::GATE_ADAPTER,
                $adapter,
                ['php', 'tools/adapter-package-tests.php', '--adapter=' . $adapter],
                'single_adapter',
                $classification
            );
        }

        return self::result(
            self::GATE_FULL,
            null,
            ['make', 'regress-offline-all'],
            self::fullReason($changes, $classification),
            $classification
        );
    }

    /**
     * @param list<string|array{from:string,to:string}> $changes
     * @param ScopeResult $classification
     */
    private static function fullReason(array $changes, array $classification): string
    {
        if ($changes === []) {
            return 'empty_change_set';
        }
        if ($classification['cross_root_rename']) {
            return 'cross_root_rename';
        }
        foreach ($classification['owners'] as $owner) {
            if ($owner['kind'] === 'scenario') {
                return 'integration_scenario_change';
            }
        }
        if (count($classification['adapters']) > 1) {
            return 'cross_adapter_change';
        }
        if ($classification['scope'] === AdapterChangeScope::SCOPE_ENGINE) {
            return 'engine_change';
        }

        $kinds = [];
        foreach ($classification['owners'] as $owner) {
            $kinds[$owner['kind']] = true;
        }
        if (count($kinds) > 1) {
            return 'mixed_ownership';
        }

        foreach ($classification['owners'] as $owner) {
            return match ($owner['root']) {
                'platform' => 'platform_change',
                'agent/adapter-library' => 'adapter_library_change',
                'package-infrastructure' => 'shared_package_infrastructure',
                'unknown', 'invalid-path', 'malformed' => 'uncovered_path',
                default => 'full_scope_required',
            };
        }

        return 'full_scope_required';
    }

    /**
     * @param 'adapter'|'full' $gate
     * @param non-empty-list<string> $command
     * @param ScopeResult $classification
     * @return Decision
     */
    private static function result(
        string $gate,
        ?string $adapter,
        array $command,
        string $reason,
        array $classification
    ): array {
        return [
            'format' => self::FORMAT,
            'gate' => $gate,
            'adapter' => $adapter,
            'command' => $command,
            'scenario_gates' => $classification['scenario_gates'],
            'reason_code' => $reason,
            'classification' => $classification,
        ];
    }
}
