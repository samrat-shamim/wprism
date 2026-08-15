<?php

declare(strict_types=1);

namespace Duo\EngineeringPlatform;

/**
 * Validates the public developer-command surface and its generated aggregate.
 *
 * @phpstan-type CommandRow array{
 *     name:string,
 *     prerequisites:list<string>,
 *     network:string,
 *     writes:list<string>,
 *     result:?string,
 *     exit_semantics:array<string,string>
 * }
 * @phpstan-type CommandDocument array{format:string,commands:list<CommandRow>}
 */
final class CommandContract
{
    /** @var list<string> */
    private const REQUIRED_COMMANDS = [
        'assembly-reproducibility-check',
        'audit',
        'bootstrap-dev',
        'build',
        'candidate-adoption-check',
        'canonical-contract-check',
        'check',
        'contracts-check',
        'doctor',
        'evidence-impact',
        'evidence-staleness-check',
        'final-integration-close-gate',
        'format-check',
        'foundation-check',
        'guide-check',
        'lint',
        'loader-check',
        'ownership-check',
        'payload-dist-check',
        'payload-reproducibility-check',
        'perf-budget',
        'perf-smoke',
        'recovery-transition-check',
        'release-family-check',
        'release-validation',
        'test-changed',
        'test-component',
        'test-conformance',
        'test-integration',
        'test-offline',
        'test-unit',
        'thread-1-gate',
        'verify-generated',
    ];

    public function __construct(private readonly string $root) {}

    /** @return CommandDocument */
    public function load(): array
    {
        $path = $this->root . '/sandbox/catalog/fragments/engineering-platform/developer-commands.json';
        $bytes = file_get_contents($path);
        if (!is_string($bytes)) {
            throw new CatalogException('developer command contract is absent');
        }
        try {
            $document = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new CatalogException('developer command contract is invalid JSON: ' . $exception->getMessage());
        }
        if (!is_array($document) || array_keys($document) !== ['format', 'commands']) {
            throw new CatalogException('developer command contract has unknown or missing top-level fields');
        }
        if ($document['format'] !== 'duo-developer-commands/v1' || !is_array($document['commands']) || !array_is_list($document['commands'])) {
            throw new CatalogException('developer command contract has an invalid format or command list');
        }

        /** @var list<CommandRow> $commands */
        $commands = [];
        $names = [];
        foreach ($document['commands'] as $index => $row) {
            if (!is_array($row)) {
                throw new CatalogException("developer command row $index is not an object");
            }
            $expectedKeys = ['exit_semantics', 'name', 'network', 'prerequisites', 'result', 'writes'];
            $keys = array_keys($row);
            sort($keys, SORT_STRING);
            if ($keys !== $expectedKeys) {
                throw new CatalogException("developer command row $index has unknown or missing fields");
            }
            $name = $row['name'] ?? null;
            $prerequisites = $row['prerequisites'] ?? null;
            $network = $row['network'] ?? null;
            $writes = $row['writes'] ?? null;
            $result = $row['result'] ?? null;
            $exitSemantics = $row['exit_semantics'] ?? null;
            if (!is_string($name) || preg_match('/^[a-z][a-z0-9-]*$/D', $name) !== 1) {
                throw new CatalogException("developer command row $index has an invalid name");
            }
            if (isset($names[$name])) {
                throw new CatalogException("developer command is duplicated: $name");
            }
            $names[$name] = true;
            if (!$this->stringList($prerequisites, true)) {
                throw new CatalogException("developer command $name has invalid prerequisites");
            }
            if (!is_string($network) || !in_array($network, ['forbidden', 'required'], true)) {
                throw new CatalogException("developer command $name has an invalid network policy");
            }
            if (!$this->stringList($writes, false)) {
                throw new CatalogException("developer command $name has invalid permitted writes");
            }
            if ($result !== null && (!is_string($result) || $result === '')) {
                throw new CatalogException("developer command $name has an invalid result path");
            }
            if (!is_array($exitSemantics) || $exitSemantics === []) {
                throw new CatalogException("developer command $name has no exit semantics");
            }
            $normalizedExitSemantics = [];
            foreach ($exitSemantics as $code => $meaning) {
                $code = (string) $code;
                if (preg_match('/^(?:0|1|2|69|128\+signal)$/D', $code) !== 1 || !is_string($meaning) || $meaning === '') {
                    throw new CatalogException("developer command $name has invalid exit semantics");
                }
                $normalizedExitSemantics[$code] = $meaning;
            }
            /** @var list<string> $prerequisites */
            /** @var list<string> $writes */
            $commands[] = [
                'name' => $name,
                'prerequisites' => $prerequisites,
                'network' => $network,
                'writes' => $writes,
                'result' => $result,
                'exit_semantics' => $normalizedExitSemantics,
            ];
        }
        $actual = array_keys($names);
        sort($actual, SORT_STRING);
        if ($actual !== self::REQUIRED_COMMANDS) {
            $missing = array_values(array_diff(self::REQUIRED_COMMANDS, $actual));
            $extra = array_values(array_diff($actual, self::REQUIRED_COMMANDS));
            throw new CatalogException(sprintf(
                'developer command set differs from the proposal; missing=%s extra=%s',
                implode(',', $missing),
                implode(',', $extra),
            ));
        }
        $makeTargets = $this->makeTargets();
        foreach ($actual as $name) {
            if (!isset($makeTargets[$name])) {
                throw new CatalogException("developer command has no Make target: $name");
            }
        }
        usort($commands, static fn(array $left, array $right): int => strcmp($left['name'], $right['name']));
        return ['format' => 'duo-developer-commands/v1', 'commands' => $commands];
    }

    public function canonical(): string
    {
        return json_encode($this->load(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    }

    /** @return array<string,true> */
    private function makeTargets(): array
    {
        $bytes = file_get_contents($this->root . '/Makefile');
        if (!is_string($bytes)) {
            throw new CatalogException('Makefile is unavailable');
        }
        $logical = preg_replace('/\\\\\r?\n[\t ]*/', ' ', $bytes);
        if (!is_string($logical)) {
            throw new CatalogException('Makefile cannot be normalized');
        }
        $targets = [];
        foreach (preg_split('/\r?\n/', $logical) ?: [] as $line) {
            if ($line === '' || $line[0] === "\t" || $line[0] === '#' || str_starts_with($line, '.PHONY:')) {
                continue;
            }
            if (preg_match('/^([^:=]+):(?:\s|$)/', $line, $match) !== 1) {
                continue;
            }
            foreach (preg_split('/\s+/', trim($match[1])) ?: [] as $target) {
                if ($target !== '' && !str_contains($target, '%') && !str_contains($target, '$')) {
                    $targets[$target] = true;
                }
            }
        }
        return $targets;
    }

    private function stringList(mixed $value, bool $emptyAllowed): bool
    {
        if (!is_array($value) || !array_is_list($value) || (!$emptyAllowed && $value === [])) {
            return false;
        }
        $seen = [];
        foreach ($value as $item) {
            if (!is_string($item) || $item === '' || isset($seen[$item])) {
                return false;
            }
            $seen[$item] = true;
        }
        return true;
    }
}
