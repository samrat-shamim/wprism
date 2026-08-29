<?php

declare(strict_types=1);

namespace WPrism\Tooling;

use JsonException;
use RuntimeException;

/**
 * Discover the closed cross-adapter scenario set and its participant index.
 *
 * A participant record is executable gate metadata, not descriptive prose:
 * misspelling a package would otherwise make an adapter edit silently skip the
 * only evidence that exercises its interaction with another capsule.
 *
 * @phpstan-type Gate array{class:string,path:string,command:non-empty-list<string>}
 * @phpstan-type Scenario array{name:string,participants:non-empty-list<string>,gates:non-empty-list<Gate>}
 * @phpstan-type Catalog array{
 *     repository:string,
 *     scenarios:array<string,Scenario>,
 *     by_participant:array<string,non-empty-list<string>>
 * }
 */
final class AdapterIntegrationScenarios
{
    public const FORMAT = 'wprism-adapter-integration-scenario/v1';

    /** @return Catalog */
    public static function discover(string $repoRoot, bool $validateParticipantPackages = true): array
    {
        $repository = self::repository($repoRoot);
        $root = $repository . '/integration-scenarios';
        self::ordinaryDirectory($root, $repository, 'integration scenario root');

        $scenarios = [];
        $byParticipant = [];
        foreach (self::entries($root) as $name) {
            if (!self::slug($name)) {
                throw new RuntimeException("Integration scenario name is not canonical: $name");
            }
            $directory = $root . '/' . $name;
            self::ordinaryDirectory($directory, $root, "integration scenario '$name'");
            $record = self::record($repository, $directory, $name, $validateParticipantPackages);
            $scenarios[$name] = $record;
            foreach ($record['participants'] as $participant) {
                $byParticipant[$participant][] = $name;
            }
        }
        if ($scenarios === []) {
            throw new RuntimeException("Integration scenario root contains no scenarios: $root");
        }
        ksort($scenarios, SORT_STRING);
        ksort($byParticipant, SORT_STRING);
        foreach ($byParticipant as &$names) {
            sort($names, SORT_STRING);
        }
        unset($names);

        return [
            'repository' => $repository,
            'scenarios' => $scenarios,
            'by_participant' => $byParticipant,
        ];
    }

    /**
     * @param Catalog $catalog
     * @return list<string>
     */
    public static function forParticipant(array $catalog, string $participant): array
    {
        return $catalog['by_participant'][$participant] ?? [];
    }

    /**
     * @return Scenario
     */
    private static function record(
        string $repository,
        string $directory,
        string $name,
        bool $validateParticipantPackages
    ): array
    {
        $path = $directory . '/scenario.json';
        self::ordinaryFile($path, $directory, "integration scenario '$name' participant record");
        try {
            $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $failure) {
            throw new RuntimeException("Integration scenario '$name' participant record is not valid JSON", 0, $failure);
        }
        if (!is_array($decoded)
            || array_is_list($decoded)
            || array_keys($decoded) !== ['format', 'participants']
            || ($decoded['format'] ?? null) !== self::FORMAT
            || !is_array($decoded['participants'] ?? null)
            || !array_is_list($decoded['participants'])
            || count($decoded['participants']) < 2) {
            throw new RuntimeException(
                "Integration scenario '$name' participant record must contain exactly format and at least two participants"
            );
        }

        $participants = [];
        foreach ($decoded['participants'] as $participant) {
            if (!is_string($participant) || !self::slug($participant)) {
                throw new RuntimeException("Integration scenario '$name' has a non-canonical participant slug");
            }
            if ($validateParticipantPackages) {
                self::assertParticipantPackage($repository, $participant);
            }
            if (isset($participants[$participant])) {
                throw new RuntimeException("Integration scenario '$name' repeats participant '$participant'");
            }
            $participants[$participant] = true;
        }
        $participantNames = array_keys($participants);
        $sortedParticipants = $participantNames;
        sort($sortedParticipants, SORT_STRING);
        if ($participantNames !== $sortedParticipants) {
            throw new RuntimeException("Integration scenario '$name' participants must be sorted");
        }

        $gates = self::gates($repository, $directory, $name);
        return ['name' => $name, 'participants' => $participantNames, 'gates' => $gates];
    }

    private static function assertParticipantPackage(string $repository, string $participant): void
    {
        $package = $repository . '/adapter-packages/' . $participant;
        self::ordinaryDirectory($package, $repository . '/adapter-packages', "scenario participant '$participant'");
        $manifestPath = $package . '/package/manifest.json';
        self::ordinaryFile($manifestPath, $package, "scenario participant '$participant' manifest");
        try {
            $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $failure) {
            throw new RuntimeException("Scenario participant '$participant' manifest is not valid JSON", 0, $failure);
        }
        if (!is_array($manifest) || ($manifest['name'] ?? null) !== $participant) {
            throw new RuntimeException("Scenario participant '$participant' manifest identity does not agree with its slug");
        }
    }

    /** @return non-empty-list<Gate> */
    private static function gates(string $repository, string $directory, string $name): array
    {
        $tests = $directory . '/tests';
        self::ordinaryDirectory($tests, $directory, "integration scenario '$name' tests");
        $gates = [];
        foreach (self::entries($tests) as $class) {
            if (!in_array($class, ['offline', 'live', 'certify', 'spike'], true)) {
                throw new RuntimeException("Integration scenario '$name' has unknown test class '$class'");
            }
            $classRoot = $tests . '/' . $class;
            self::ordinaryDirectory($classRoot, $tests, "integration scenario '$name' tests/$class");
            foreach (self::entries($classRoot) as $entry) {
                $file = $classRoot . '/' . $entry;
                self::ordinaryFile($file, $classRoot, "integration scenario '$name' test");
                $prefix = $class === 'spike' ? 'spike' : ($class === 'certify' ? '(?:certify|regress)' : 'regress');
                if (preg_match('/^' . $prefix . '_[a-z0-9][a-z0-9._-]*\.(?:php|sh)$/D', $entry) !== 1) {
                    throw new RuntimeException("Integration scenario '$name' has an unrecognized tests/$class entry: $entry");
                }
                $relative = substr($file, strlen($repository) + 1);
                $runtime = str_ends_with($entry, '.php') ? 'php' : 'bash';
                $gates[] = [
                    'class' => $class,
                    'path' => $relative,
                    'command' => [$runtime, $relative],
                ];
            }
        }
        if ($gates === []) {
            throw new RuntimeException("Integration scenario '$name' has no executable gates");
        }
        usort($gates, static fn(array $left, array $right): int => strcmp($left['path'], $right['path']));

        return $gates;
    }

    private static function repository(string $path): string
    {
        if ($path === '' || str_contains($path, "\0") || is_link($path)) {
            throw new RuntimeException("Repository root is not an ordinary directory: $path");
        }
        $canonical = realpath($path);
        if ($canonical === false || !is_dir($canonical)) {
            throw new RuntimeException("Repository root is not an ordinary directory: $path");
        }
        return rtrim($canonical, '/');
    }

    private static function ordinaryDirectory(string $path, string $boundary, string $label): void
    {
        $stat = @lstat($path);
        if ($stat === false || ($stat['mode'] & 0170000) !== 0040000 || is_link($path) || !is_readable($path)) {
            throw new RuntimeException("$label is not an ordinary readable directory: $path");
        }
        self::contained($path, $boundary, $label);
    }

    private static function ordinaryFile(string $path, string $boundary, string $label): void
    {
        $stat = @lstat($path);
        if ($stat === false || ($stat['mode'] & 0170000) !== 0100000 || is_link($path) || !is_readable($path)) {
            throw new RuntimeException("$label is not an ordinary readable file: $path");
        }
        self::contained($path, $boundary, $label);
    }

    private static function contained(string $path, string $boundary, string $label): void
    {
        $canonical = realpath($path);
        $canonicalBoundary = realpath($boundary);
        if ($canonical === false
            || $canonicalBoundary === false
            || ($canonical !== $canonicalBoundary
                && !str_starts_with($canonical, rtrim($canonicalBoundary, '/') . '/'))) {
            throw new RuntimeException("$label escapes its ownership boundary: $path");
        }
    }

    /** @return list<string> */
    private static function entries(string $directory): array
    {
        $entries = @scandir($directory);
        if ($entries === false) {
            throw new RuntimeException("Cannot read integration scenario directory: $directory");
        }
        $entries = array_values(array_diff($entries, ['.', '..']));
        sort($entries, SORT_STRING);
        return $entries;
    }

    private static function slug(string $value): bool
    {
        return preg_match('/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/D', $value) === 1;
    }
}
