<?php

declare(strict_types=1);

namespace Duo\EngineeringPlatform;

/**
 * @phpstan-type AuthorityRequirements array{
 *     provisioning_reference_id:string,
 *     environment_role_reference_id:string,
 *     data_profile:string,
 *     credential_reference_id:string,
 *     effect_policy_reference_id:string,
 *     sandbox_destination_reference_id:string,
 *     output_authority:string,
 *     output_adoptability:string
 * }
 * @phpstan-type Suite array{
 *     id:string,
 *     command:list<string>,
 *     layer:string,
 *     owner:string,
 *     timeout_seconds:int,
 *     parallel_safe:bool,
 *     resource_locks:list<string>,
 *     temporary_directory:string,
 *     workspace_mode:string,
 *     required_tools:list<string>,
 *     required_services:list<string>,
 *     covered_paths:list<string>,
 *     covered_contracts:list<string>,
 *     evidence_inputs:list<string>,
 *     evidence_role:string,
 *     required_review_gate:?string,
 *     environment_class:string,
 *     expected_outputs:list<string>,
 *     authority_requirements?:AuthorityRequirements
 * }
 * @phpstan-type InventoryEntry array{kind:string,name:string,role:string,suite_ids:list<string>}
 * @phpstan-type Profile array{
 *     id:string,
 *     owner:string,
 *     suite_ids:list<string>,
 *     environment_class:string,
 *     blocking:bool,
 *     expected_outputs:list<string>,
 *     evidence_staleness:string
 * }
 * @phpstan-type Fragment array{
 *     format:string,
 *     owner:string,
 *     suites:list<Suite>,
 *     inventory:list<InventoryEntry>,
 *     profiles:list<Profile>
 * }
 * @phpstan-type CatalogAggregate array{
 *     format:string,
 *     suites:list<Suite>,
 *     inventory:list<InventoryEntry>,
 *     profiles:list<Profile>,
 *     source_fragments:list<array{path:string,sha256:string}>
 * }
 */
final class Catalog
{
    private const OWNER_DIRECTORIES = [
        'thread-1' => 'engineering-platform',
        'thread-2' => 'host-cli',
        'thread-3' => 'wordpress-agent',
        'thread-4' => 'capability-policy-evidence',
        'thread-5' => 'mutation-recovery',
    ];

    private const REQUIRED_PROFILES = [
        'platform-p0',
        'component-engineering-platform',
        'thread-1-engineering-platform',
        'thread-2-host-cli',
        'thread-3-wordpress-agent',
        'thread-4-capability-evidence',
        'thread-5-mutation-recovery',
        'component-host-cli',
        'component-wordpress-agent',
        'component-capability-policy-evidence',
        'component-mutation-recovery',
        'pr',
        'frozen-candidate',
        'evidence-child',
        'release-validation',
    ];

    /** @var list<string> */
    private const SUITE_KEYS = [
        'id', 'command', 'layer', 'owner', 'timeout_seconds', 'parallel_safe',
        'resource_locks', 'temporary_directory', 'workspace_mode', 'required_tools',
        'required_services', 'covered_paths', 'covered_contracts', 'evidence_inputs',
        'evidence_role', 'required_review_gate', 'environment_class', 'expected_outputs',
    ];

    /** @var list<string> */
    private const PROFILE_KEYS = [
        'id', 'owner', 'suite_ids', 'environment_class', 'blocking',
        'expected_outputs', 'evidence_staleness',
    ];

    public function __construct(private readonly string $root)
    {
        if (!is_file($this->root . '/Makefile')) {
            throw new CatalogException('repository root does not contain Makefile');
        }
    }

    /** @return CatalogAggregate */
    public function validate(?string $onlyOwner = null, bool $requireComplete = true): array
    {
        if ($onlyOwner !== null && !isset(self::OWNER_DIRECTORIES[$onlyOwner])) {
            throw new CatalogException("unknown owner: $onlyOwner");
        }

        $fragments = $this->loadFragments($onlyOwner);
        if ($fragments === []) {
            throw new CatalogException('no catalog fragments found');
        }

        $suites = [];
        $inventory = [];
        $profiles = [];
        $sources = [];
        foreach ($fragments as $path => $fragment) {
            $owner = $fragment['owner'];
            $relativePath = $this->relative($path);
            $sources[] = [
                'path' => $relativePath,
                'sha256' => 'sha256:' . hash_file('sha256', $path),
            ];

            foreach ($fragment['suites'] as $suite) {
                $id = $suite['id'];
                if (isset($suites[$id])) {
                    throw new CatalogException("duplicate suite id $id in $relativePath");
                }
                if ($suite['owner'] !== $owner) {
                    throw new CatalogException("suite $id owner differs from fragment owner");
                }
                $suites[$id] = $suite;
            }
            foreach ($fragment['inventory'] as $entry) {
                $key = $entry['kind'] . ':' . $entry['name'];
                if (isset($inventory[$key])) {
                    throw new CatalogException("duplicate inventory entry $key in $relativePath");
                }
                $inventory[$key] = $entry;
            }
            foreach ($fragment['profiles'] as $profile) {
                $id = $profile['id'];
                if (isset($profiles[$id])) {
                    throw new CatalogException("duplicate profile id $id in $relativePath");
                }
                if ($profile['owner'] !== $owner) {
                    throw new CatalogException("profile $id owner differs from fragment owner");
                }
                $profiles[$id] = $profile;
            }
        }

        foreach ($inventory as $key => $entry) {
            foreach ($entry['suite_ids'] as $suiteId) {
                if (!isset($suites[$suiteId])) {
                    throw new CatalogException("inventory entry $key references unknown suite $suiteId");
                }
            }
        }
        foreach ($profiles as $id => $profile) {
            foreach ($profile['suite_ids'] as $suiteId) {
                if (!isset($suites[$suiteId])) {
                    throw new CatalogException("profile $id references unknown suite $suiteId");
                }
                if ($profile['environment_class'] === 'offline'
                    && $suites[$suiteId]['environment_class'] !== 'offline') {
                    throw new CatalogException("offline profile $id contains non-offline suite $suiteId");
                }
            }
        }

        if ($requireComplete) {
            $this->assertComplete($suites, $inventory, $profiles);
        }

        ksort($suites, SORT_STRING);
        ksort($inventory, SORT_STRING);
        ksort($profiles, SORT_STRING);
        usort($sources, static fn(array $a, array $b): int => $a['path'] <=> $b['path']);

        return [
            'format' => 'duo-test-catalog/v1',
            'suites' => array_values($suites),
            'inventory' => array_values($inventory),
            'profiles' => array_values($profiles),
            'source_fragments' => $sources,
        ];
    }

    /** @param CatalogAggregate $catalog */
    public function encode(array $catalog): string
    {
        return json_encode(
            $this->canonicalize($catalog),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ) . "\n";
    }

    public function root(): string
    {
        return $this->root;
    }

    /** @return array<string,Fragment> */
    private function loadFragments(?string $onlyOwner): array
    {
        $pattern = $this->root . '/sandbox/catalog/fragments/*/*.catalog.json';
        $paths = glob($pattern);
        if ($paths === false) {
            throw new CatalogException('cannot enumerate catalog fragments');
        }
        sort($paths, SORT_STRING);
        $fragments = [];
        foreach ($paths as $path) {
            $raw = file_get_contents($path);
            if (!is_string($raw)) {
                throw new CatalogException('cannot read ' . $this->relative($path));
            }
            try {
                $fragment = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                throw new CatalogException($this->relative($path) . ': invalid JSON: ' . $exception->getMessage());
            }
            $fragment = $this->normalizeFragment($fragment, $path);
            if ($onlyOwner === null || $fragment['owner'] === $onlyOwner) {
                $fragments[$path] = $fragment;
            }
        }
        return $fragments;
    }

    /** @return Fragment */
    private function normalizeFragment(mixed $fragment, string $path): array
    {
        $relative = $this->relative($path);
        if (!is_array($fragment) || array_is_list($fragment)) {
            throw new CatalogException($relative . ': fragment must be an object');
        }
        $this->assertExactKeys($fragment, ['format', 'owner', 'suites', 'inventory', 'profiles'], $relative);
        $format = $fragment['format'] ?? null;
        if ($format !== 'duo-test-catalog-fragment/v1') {
            throw new CatalogException("$relative: invalid fragment format");
        }
        $owner = $fragment['owner'] ?? null;
        if (!is_string($owner) || !isset(self::OWNER_DIRECTORIES[$owner])) {
            throw new CatalogException("$relative: invalid owner");
        }
        $expectedDirectory = '/sandbox/catalog/fragments/' . self::OWNER_DIRECTORIES[$owner] . '/';
        if (!str_contains(str_replace('\\', '/', $path), $expectedDirectory)) {
            throw new CatalogException("$relative: owner does not match fragment directory");
        }
        $rawSuites = $fragment['suites'] ?? null;
        $rawInventory = $fragment['inventory'] ?? null;
        $rawProfiles = $fragment['profiles'] ?? null;
        if (!is_array($rawSuites) || !array_is_list($rawSuites)
            || !is_array($rawInventory) || !array_is_list($rawInventory)
            || !is_array($rawProfiles) || !array_is_list($rawProfiles)) {
            throw new CatalogException("$relative: suites, inventory, and profiles must be lists");
        }
        if ($rawSuites === [] || $rawInventory === [] || $rawProfiles === []) {
            throw new CatalogException("$relative: suites, inventory, and profiles must be nonempty");
        }
        $suites = [];
        foreach ($rawSuites as $index => $suite) {
            $suites[] = $this->normalizeSuite($suite, "$relative: suite $index");
        }
        $inventory = [];
        foreach ($rawInventory as $index => $entry) {
            $inventory[] = $this->normalizeInventory($entry, "$relative: inventory $index");
        }
        $profiles = [];
        foreach ($rawProfiles as $index => $profile) {
            $profiles[] = $this->normalizeProfile($profile, "$relative: profile $index");
        }
        return [
            'format' => $format,
            'owner' => $owner,
            'suites' => $suites,
            'inventory' => $inventory,
            'profiles' => $profiles,
        ];
    }

    /** @return Suite */
    private function normalizeSuite(mixed $suite, string $where): array
    {
        if (!is_array($suite) || array_is_list($suite)) {
            throw new CatalogException("$where must be an object");
        }
        $requiredKeys = self::SUITE_KEYS;
        if (array_key_exists('authority_requirements', $suite)) {
            $requiredKeys[] = 'authority_requirements';
        }
        $this->assertExactKeys($suite, $requiredKeys, $where);
        $id = $suite['id'] ?? null;
        $layer = $suite['layer'] ?? null;
        $owner = $suite['owner'] ?? null;
        $timeout = $suite['timeout_seconds'] ?? null;
        $parallelSafe = $suite['parallel_safe'] ?? null;
        $temporaryDirectory = $suite['temporary_directory'] ?? null;
        $workspaceMode = $suite['workspace_mode'] ?? null;
        $evidenceRole = $suite['evidence_role'] ?? null;
        $reviewGate = $suite['required_review_gate'] ?? null;
        $environmentClass = $suite['environment_class'] ?? null;
        if (!is_string($id) || preg_match('/^[a-z][a-z0-9.-]*$/D', $id) !== 1) {
            throw new CatalogException("$where has invalid id");
        }
        $command = $this->stringList($suite['command'] ?? null, "$where command", false);
        if (!is_string($layer) || !in_array($layer, ['unit', 'offline', 'integration', 'conformance', 'certification', 'grind', 'platform', 'architecture'], true)
            || !is_string($owner) || !isset(self::OWNER_DIRECTORIES[$owner])
            || !is_int($timeout) || $timeout < 1 || $timeout > 86400
            || !is_bool($parallelSafe)
            || !is_string($temporaryDirectory) || !in_array($temporaryDirectory, ['none', 'unique', 'suite_managed'], true)
            || !is_string($workspaceMode) || !in_array($workspaceMode, ['read_only', 'isolated_copy', 'exclusive'], true)
            || !is_string($evidenceRole) || !in_array($evidenceRole, ['none', 'characterization', 'qualification', 'authority_input'], true)
            || !is_string($environmentClass) || !in_array($environmentClass, ['offline', 'live', 'destructive', 'credentialed', 'evidence-producing'], true)) {
            throw new CatalogException("$where has an invalid enum, boolean, or timeout");
        }
        $lists = [];
        foreach (['resource_locks', 'required_tools', 'required_services', 'covered_paths', 'covered_contracts', 'evidence_inputs', 'expected_outputs'] as $key) {
            $lists[$key] = $this->stringList($suite[$key] ?? null, "$where $key", true);
        }
        if (!is_null($reviewGate) && (!is_string($reviewGate) || $reviewGate === '')) {
            throw new CatalogException("$where required_review_gate must be null or a nonempty string");
        }
        $authorityClass = $environmentClass !== 'offline';
        if ($authorityClass !== array_key_exists('authority_requirements', $suite)) {
            throw new CatalogException("$where authority_requirements must exist exactly for non-offline suites");
        }
        $normalized = [
            'id' => $id,
            'command' => $command,
            'layer' => $layer,
            'owner' => $owner,
            'timeout_seconds' => $timeout,
            'parallel_safe' => $parallelSafe,
            'resource_locks' => $lists['resource_locks'],
            'temporary_directory' => $temporaryDirectory,
            'workspace_mode' => $workspaceMode,
            'required_tools' => $lists['required_tools'],
            'required_services' => $lists['required_services'],
            'covered_paths' => $lists['covered_paths'],
            'covered_contracts' => $lists['covered_contracts'],
            'evidence_inputs' => $lists['evidence_inputs'],
            'evidence_role' => $evidenceRole,
            'required_review_gate' => $reviewGate,
            'environment_class' => $environmentClass,
            'expected_outputs' => $lists['expected_outputs'],
        ];
        if ($authorityClass) {
            $normalized['authority_requirements'] = $this->normalizeAuthority(
                $suite['authority_requirements'] ?? null,
                "$where authority_requirements",
            );
        }
        return $normalized;
    }

    /** @return InventoryEntry */
    private function normalizeInventory(mixed $entry, string $where): array
    {
        if (!is_array($entry) || array_is_list($entry)) {
            throw new CatalogException("$where must be an object");
        }
        $this->assertExactKeys($entry, ['kind', 'name', 'role', 'suite_ids'], $where);
        $kind = $entry['kind'] ?? null;
        $name = $entry['name'] ?? null;
        $role = $entry['role'] ?? null;
        if (!is_string($kind) || !in_array($kind, ['file', 'make_target'], true)
            || !is_string($name) || $name === ''
            || !is_string($role) || !in_array($role, ['suite', 'helper', 'self_test', 'fixture', 'aggregate', 'diagnostic'], true)) {
            throw new CatalogException("$where has invalid fields");
        }
        return [
            'kind' => $kind,
            'name' => $name,
            'role' => $role,
            'suite_ids' => $this->stringList($entry['suite_ids'] ?? null, "$where suite_ids", false),
        ];
    }

    /** @return Profile */
    private function normalizeProfile(mixed $profile, string $where): array
    {
        if (!is_array($profile) || array_is_list($profile)) {
            throw new CatalogException("$where must be an object");
        }
        $this->assertExactKeys($profile, self::PROFILE_KEYS, $where);
        $id = $profile['id'] ?? null;
        $owner = $profile['owner'] ?? null;
        $environmentClass = $profile['environment_class'] ?? null;
        $blocking = $profile['blocking'] ?? null;
        $evidenceStaleness = $profile['evidence_staleness'] ?? null;
        if (!is_string($id) || preg_match('/^[a-z][a-z0-9.-]*$/D', $id) !== 1
            || !is_string($owner) || !isset(self::OWNER_DIRECTORIES[$owner])
            || !is_string($environmentClass) || !in_array($environmentClass, ['offline', 'live', 'destructive', 'credentialed', 'evidence-producing', 'mixed'], true)
            || !is_bool($blocking)
            || !is_string($evidenceStaleness) || !in_array($evidenceStaleness, ['forbidden', 'permitted', 'required'], true)) {
            throw new CatalogException("$where has invalid fields");
        }
        return [
            'id' => $id,
            'owner' => $owner,
            'suite_ids' => $this->stringList($profile['suite_ids'] ?? null, "$where suite_ids", false),
            'environment_class' => $environmentClass,
            'blocking' => $blocking,
            'expected_outputs' => $this->stringList($profile['expected_outputs'] ?? null, "$where expected_outputs", false),
            'evidence_staleness' => $evidenceStaleness,
        ];
    }

    /** @return AuthorityRequirements */
    private function normalizeAuthority(mixed $authority, string $where): array
    {
        if (!is_array($authority) || array_is_list($authority)) {
            throw new CatalogException("$where must be an object");
        }
        $keys = [
            'provisioning_reference_id', 'environment_role_reference_id', 'data_profile',
            'credential_reference_id', 'effect_policy_reference_id',
            'sandbox_destination_reference_id', 'output_authority', 'output_adoptability',
        ];
        $this->assertExactKeys($authority, $keys, $where);
        $values = [];
        foreach ($keys as $key) {
            $value = $authority[$key] ?? null;
            if (!is_string($value)) {
                throw new CatalogException("$where field $key must be a string");
            }
            $values[$key] = $value;
        }
        foreach (array_slice($keys, 0, 6) as $key) {
            if (preg_match('/^[a-z][a-z0-9._\/-]*$/D', $values[$key]) !== 1) {
                throw new CatalogException("$where field $key is not a reference id");
            }
        }
        if (!in_array($values['data_profile'], ['synthetic', 'approved_minimized'], true)
            || $values['output_authority'] !== 'non_authorizing'
            || $values['output_adoptability'] !== 'forbidden') {
            throw new CatalogException("$where output/data policy is invalid");
        }
        return [
            'provisioning_reference_id' => $values['provisioning_reference_id'],
            'environment_role_reference_id' => $values['environment_role_reference_id'],
            'data_profile' => $values['data_profile'],
            'credential_reference_id' => $values['credential_reference_id'],
            'effect_policy_reference_id' => $values['effect_policy_reference_id'],
            'sandbox_destination_reference_id' => $values['sandbox_destination_reference_id'],
            'output_authority' => $values['output_authority'],
            'output_adoptability' => $values['output_adoptability'],
        ];
    }

    /**
     * @param array<string,Suite> $suites
     * @param array<string,InventoryEntry> $inventory
     * @param array<string,Profile> $profiles
     */
    private function assertComplete(array $suites, array $inventory, array $profiles): void
    {
        foreach (self::REQUIRED_PROFILES as $profile) {
            if (!isset($profiles[$profile])) {
                throw new CatalogException("required profile is missing: $profile");
            }
        }
        foreach ($this->discoveredFiles() as $path) {
            if (!isset($inventory['file:' . $path])) {
                throw new CatalogException("discovered test file is not classified: $path");
            }
        }
        foreach ($this->discoveredMakeTargets() as $target) {
            if (!isset($inventory['make_target:' . $target])) {
                throw new CatalogException("discovered test Make target is not classified: $target");
            }
        }
        if ($suites === []) {
            throw new CatalogException('catalog has no suites');
        }
    }

    /** @return list<string> */
    private function discoveredFiles(): array
    {
        $output = $this->process(['git', 'ls-files', '-z', '--', 'sandbox/tests', 'sandbox/conformance']);
        $files = array_values(array_filter(explode("\0", $output), static fn(string $path): bool => $path !== ''));
        sort($files, SORT_STRING);
        return $files;
    }

    /** @return list<string> */
    private function discoveredMakeTargets(): array
    {
        $bytes = file_get_contents($this->root . '/Makefile');
        if (!is_string($bytes)) {
            throw new CatalogException('cannot read Makefile');
        }
        $logical = preg_replace('/\\\\\r?\n[\t ]*/', ' ', $bytes);
        if (!is_string($logical)) {
            throw new CatalogException('cannot normalize Makefile');
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
                if (preg_match('/^(regress-|certify-|grind-|conformance-)/', $target) === 1
                    || in_array($target, ['code-half-unit', 'cli-smoke', 'cli-triage-smoke', 'lint-smoke', 'adapter-authoring-exercise', 'release-gate'], true)) {
                    $targets[$target] = true;
                }
            }
        }
        $result = array_keys($targets);
        sort($result, SORT_STRING);
        return $result;
    }

    /** @param list<string> $argv */
    private function process(array $argv): string
    {
        $process = proc_open(
            $argv,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->root,
            null,
            ['bypass_shell' => true],
        );
        if (!is_resource($process)) {
            throw new CatalogException('cannot execute ' . $argv[0]);
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        if ($exit !== 0 || !is_string($stdout)) {
            throw new CatalogException($argv[0] . ' failed: ' . trim((string) $stderr));
        }
        return $stdout;
    }

    /**
     * @param array<mixed,mixed> $value
     * @param list<string> $keys
     */
    private function assertExactKeys(array $value, array $keys, string $where): void
    {
        $actual = [];
        foreach (array_keys($value) as $key) {
            if (!is_string($key)) {
                throw new CatalogException("$where contains a non-string object key");
            }
            $actual[] = $key;
        }
        sort($actual, SORT_STRING);
        sort($keys, SORT_STRING);
        if ($actual !== $keys) {
            throw new CatalogException("$where keys differ; expected " . implode(', ', $keys));
        }
    }

    /** @return list<string> */
    private function stringList(mixed $value, string $where, bool $allowEmpty): array
    {
        if (!is_array($value) || !array_is_list($value) || (!$allowEmpty && $value === [])) {
            throw new CatalogException("$where must be " . ($allowEmpty ? 'a' : 'a nonempty') . ' list');
        }
        $seen = [];
        foreach ($value as $item) {
            if (!is_string($item) || $item === '' || isset($seen[$item])) {
                throw new CatalogException("$where must contain unique nonempty strings");
            }
            $seen[$item] = true;
        }
        return array_keys($seen);
    }

    private function relative(string $path): string
    {
        return str_starts_with($path, $this->root . '/') ? substr($path, strlen($this->root) + 1) : $path;
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn(mixed $item): mixed => $this->canonicalize($item), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }
        return $value;
    }
}
