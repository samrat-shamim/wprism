<?php

declare(strict_types=1);

namespace WPrism\Tooling;

require_once __DIR__ . '/AdapterIntegrationScenarios.php';

/**
 * Classify changed paths by their closed repository ownership boundary.
 *
 * This is deliberately smaller than tools/affected.php. It does not infer
 * source dependencies: it names the authoritative package gate and the checked
 * participant-scenario gates allowed to make that choice. A path outside a recognized ownership boundary,
 * a mixed-owner change, an integration-scenario edit, or a cross-root
 * rename always escalates to the full gate. That default is the property this
 * class exists to preserve; an uncovered path can never become a green run.
 *
 * Ordinary changes are strings. Renames are explicit `{from, to}` records so
 * the old and new ownership roots cannot be lost before classification.
 *
 * @phpstan-type Change string|array{from:string,to:string}
 * @phpstan-type Owner array{kind:'adapter'|'engine'|'full'|'scenario',name:?string,root:string}
 * @phpstan-type OwnerRow array{path:string,kind:'adapter'|'engine'|'full'|'scenario',name:?string,root:string}
 * @phpstan-type ScenarioGate array{scenario:string,class:string,path:string,command:non-empty-list<string>}
 * @phpstan-type ScopeResult array{
 *     scope:'adapters'|'engine'|'full',
 *     adapters:list<string>,
 *     scenarios:list<string>,
 *     scenario_gates:list<ScenarioGate>,
 *     requires_participant_resolution:bool,
 *     cross_root_rename:bool,
 *     owners:list<OwnerRow>
 * }
 */
final class AdapterChangeScope
{
    public const SCOPE_ADAPTERS = 'adapters';
    public const SCOPE_ENGINE = 'engine';
    public const SCOPE_FULL = 'full';

    /**
     * These paths define or execute the package contract shared by every
     * adapter. They are named for diagnostics only: unknown paths also select
     * the full gate, so forgetting a future spelling cannot weaken the answer.
     *
     * @var list<string>
     */
    private const PACKAGE_INFRASTRUCTURE_PREFIXES = [
        // The package CLIs and their shared implementation are the only
        // current package-test/validation topology; the retired synthetic
        // adapter-package-{kit,runner,schema} roots do not exist in this tree.
        'tools/adapter-package-',
        'tools/src/AdapterPackage',
        // adapter-kit.php and its generated inventory are one authority, and
        // sandbox/conformance/ is the shared harness every package invokes.
        'tools/adapter-kit.',
        'sandbox/conformance/',
        'sandbox/bin/fetch-artifact.sh',
        'sandbox/tests/lib/',
        'tests/Tooling/AdapterChangeScopeTest.php',
        'tests/Tooling/AdapterChangeScopeCliTest.php',
        'tools/adapter-change-scope.php',
        'tools/src/AdapterChangeScope.php',
        'tools/src/AdapterChangeScopeCommand.php',
        'tools/src/AdapterChangeScopeDecision.php',
    ];

    /**
     * @param list<string|array{from:string,to:string}> $changes
     * @return array{
     *     scope:'adapters'|'engine'|'full',
     *     adapters:list<string>,
     *     scenarios:list<string>,
     *     scenario_gates:list<ScenarioGate>,
     *     requires_participant_resolution:bool,
     *     cross_root_rename:bool,
     *     owners:list<array{path:string,kind:'adapter'|'engine'|'full'|'scenario',name:?string,root:string}>
     * }
     */
    public static function classify(array $changes, ?string $repoRoot = null): array
    {
        // Selection needs the complete scenario metadata but must not make one
        // adapter's iteration depend on sibling package bytes. The unconditional
        // regress-adapter-packages gate performs participant-package resolution.
        $catalog = AdapterIntegrationScenarios::discover($repoRoot ?? dirname(__DIR__, 2), false);
        /** @var list<array{path:string,kind:'adapter'|'engine'|'full'|'scenario',name:?string,root:string}> $owners */
        $owners = [];
        $crossRootRename = false;

        foreach ($changes as $change) {
            if (is_string($change)) {
                $owner = self::ownerOf($change);
                if ($owner['kind'] === 'scenario'
                    && ($owner['name'] === null || !isset($catalog['scenarios'][$owner['name']]))) {
                    $owner = ['kind' => 'full', 'name' => null, 'root' => 'integration-scenarios'];
                }
                $owners[] = ['path' => $change, ...$owner];

                continue;
            }

            if (!self::isRename($change)) {
                // A malformed input is itself uncovered. Preserve it in the
                // explanation without trying to stringify caller data.
                $owners[] = [
                    'path' => '<malformed-change>',
                    'kind' => 'full',
                    'name' => null,
                    'root' => 'malformed',
                ];

                continue;
            }

            $from = self::ownerOf($change['from']);
            $to = self::ownerOf($change['to']);
            if ($from['kind'] === 'scenario'
                && ($from['name'] === null || !isset($catalog['scenarios'][$from['name']]))) {
                $from = ['kind' => 'full', 'name' => null, 'root' => 'integration-scenarios'];
            }
            if ($to['kind'] === 'scenario'
                && ($to['name'] === null || !isset($catalog['scenarios'][$to['name']]))) {
                $to = ['kind' => 'full', 'name' => null, 'root' => 'integration-scenarios'];
            }
            $owners[] = ['path' => $change['from'], ...$from];
            $owners[] = ['path' => $change['to'], ...$to];
            if (self::ownershipKey($from) !== self::ownershipKey($to)) {
                $crossRootRename = true;
            }
        }

        $adapters = [];
        $scenarios = [];
        $scopeKinds = [];
        $requiresFull = $changes === [] || $crossRootRename;

        foreach ($owners as $owner) {
            $scopeKinds[$owner['kind']] = true;
            if ($owner['kind'] === 'adapter' && $owner['name'] !== null) {
                $adapters[$owner['name']] = true;
            } elseif ($owner['kind'] === 'scenario' && $owner['name'] !== null) {
                $scenarios[$owner['name']] = true;
                // A scenario is shared evidence, not an adapter-owned source.
                // Its checked participant record tells adapter edits which
                // scenario gates to select, but editing the scenario itself
                // remains a global aggregate change.
                $requiresFull = true;
            } elseif ($owner['kind'] === 'full') {
                $requiresFull = true;
            }
        }

        // Mixing engine and adapter ownership is a boundary change, not the
        // union of two narrow gates. The same applies to any future mixture
        // involving a scenario or full-owned path.
        if (count($scopeKinds) > 1) {
            $requiresFull = true;
        }

        $adapterNames = array_keys($adapters);
        foreach ($adapterNames as $adapter) {
            foreach (AdapterIntegrationScenarios::forParticipant($catalog, $adapter) as $scenario) {
                $scenarios[$scenario] = true;
            }
        }
        $scenarioNames = array_keys($scenarios);
        sort($adapterNames, SORT_STRING);
        sort($scenarioNames, SORT_STRING);

        $scenarioGates = [];
        foreach ($scenarioNames as $scenario) {
            foreach ($catalog['scenarios'][$scenario]['gates'] as $gate) {
                $scenarioGates[] = ['scenario' => $scenario, ...$gate];
            }
        }

        $scope = self::SCOPE_FULL;
        if (!$requiresFull && isset($scopeKinds['adapter']) && count($scopeKinds) === 1) {
            $scope = self::SCOPE_ADAPTERS;
        } elseif (!$requiresFull && isset($scopeKinds['engine']) && count($scopeKinds) === 1) {
            $scope = self::SCOPE_ENGINE;
        }

        return [
            'scope' => $scope,
            'adapters' => $adapterNames,
            'scenarios' => $scenarioNames,
            'scenario_gates' => $scenarioGates,
            'requires_participant_resolution' => false,
            'cross_root_rename' => $crossRootRename,
            'owners' => $owners,
        ];
    }

    /**
     * @param mixed $change
     * @phpstan-assert-if-true array{from:string,to:string} $change
     */
    private static function isRename(mixed $change): bool
    {
        return is_array($change)
            && count($change) === 2
            && array_key_exists('from', $change)
            && array_key_exists('to', $change)
            && is_string($change['from'])
            && is_string($change['to']);
    }

    /**
     * @return array{kind:'adapter'|'engine'|'full'|'scenario',name:?string,root:string}
     */
    private static function ownerOf(string $path): array
    {
        if (!self::isCanonicalRelativePath($path)) {
            return ['kind' => 'full', 'name' => null, 'root' => 'invalid-path'];
        }

        $segments = explode('/', $path);
        $root = $segments[0];

        if ($root === 'adapter-packages') {
            $slug = $segments[1] ?? '';
            if (self::isCanonicalName($slug)) {
                return ['kind' => 'adapter', 'name' => $slug, 'root' => 'adapter-packages/' . $slug];
            }

            return ['kind' => 'full', 'name' => null, 'root' => 'adapter-packages'];
        }

        if ($root === 'integration-scenarios') {
            $scenario = $segments[1] ?? '';
            if (self::isCanonicalName($scenario)) {
                return [
                    'kind' => 'scenario',
                    'name' => $scenario,
                    'root' => 'integration-scenarios/' . $scenario,
                ];
            }

            return ['kind' => 'full', 'name' => null, 'root' => 'integration-scenarios'];
        }

        if ($root === 'platform') {
            return ['kind' => 'full', 'name' => null, 'root' => 'platform'];
        }

        if ($root === 'agent' && ($segments[1] ?? '') === 'adapter-library') {
            return ['kind' => 'full', 'name' => null, 'root' => 'agent/adapter-library'];
        }

        if (in_array($root, ['agent', 'cli', 'recovery'], true)) {
            return ['kind' => 'engine', 'name' => null, 'root' => $root];
        }

        foreach (self::PACKAGE_INFRASTRUCTURE_PREFIXES as $prefix) {
            if ($path === rtrim($prefix, '/') || str_starts_with($path, $prefix)) {
                return ['kind' => 'full', 'name' => null, 'root' => 'package-infrastructure'];
            }
        }

        return ['kind' => 'full', 'name' => null, 'root' => 'unknown'];
    }

    private static function isCanonicalRelativePath(string $path): bool
    {
        if ($path === '' || $path[0] === '/' || str_contains($path, "\0") || str_contains($path, '\\')) {
            return false;
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }

    private static function isCanonicalName(string $name): bool
    {
        return preg_match('/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/', $name) === 1;
    }

    /**
     * Renames compare concrete ownership roots, not only their eventual gate.
     * `agent/** -> cli/**` remains engine-scoped as two ordinary edits, but as
     * one rename it crosses a root and therefore needs the full structural gate.
     *
     * @param array{kind:'adapter'|'engine'|'full'|'scenario',name:?string,root:string} $owner
     */
    private static function ownershipKey(array $owner): string
    {
        return $owner['kind'] . ':' . $owner['root'];
    }
}
