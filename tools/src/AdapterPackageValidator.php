<?php

declare(strict_types=1);

namespace Duo\Tooling;

use Duo\AdapterLibrary;
use Duo\ArtifactPolicyIdentity;
use Duo\Canon;
use Duo\ManifestDispositions;
use Duo\Policy;
use RuntimeException;

require_once dirname(__DIR__, 2) . '/agent/src/Policy/AdapterLibrary.php';
require_once dirname(__DIR__, 2) . '/agent/src/Policy/Policy.php';
require_once dirname(__DIR__, 2) . '/agent/src/Policy/ArtifactPolicyIdentity.php';

/** Validate one adapter capsule without reading or executing a sibling adapter. */
final class AdapterPackageValidator
{
    public const FORMAT = 'duo-adapter-package-validation/v1';
    private const EXTERNAL_EVIDENCE_FORMAT = 'duo-adapter-external-evidence/v1';

    /**
     * @return array{
     *     format:string,
     *     adapter:string,
     *     digest:string,
     *     manifest_sha256:string,
     *     checks:list<string>,
     *     evidence_tests:list<string>
     * }
     */
    public static function validate(string $repoRoot, string $slug): array
    {
        $root = self::repositoryRoot($repoRoot);
        self::defineVersions($root);
        $library = AdapterLibrary::fromSourcePackage($root, $slug);
        $package = $library->package($slug);
        if ($package === null) {
            throw new RuntimeException("Adapter package '$slug' did not enter its own closed library");
        }

        $manifest = Canon::decode(Canon::read_file($package->manifestPath()));
        if (!is_array($manifest)) {
            throw new RuntimeException("Adapter package '$slug' manifest is not an object");
        }
        $registry = ManifestDispositions::load_library($library);
        $registry->assert_covers([$manifest]);

        $policy = Policy::load(null, ['core', $slug], adapterLibrary: $library);
        $identity = null;
        foreach (ArtifactPolicyIdentity::resolved_adapters($policy) as $row) {
            if (($row['name'] ?? null) === $slug) {
                $identity = $row;
                break;
            }
        }
        if (!is_array($identity) || !is_string($identity['digest'] ?? null)) {
            throw new RuntimeException("Adapter package '$slug' produced no identity row");
        }

        $capsule = $root . '/adapter-packages/' . $slug;
        $checks = ['closed-library', 'manifest-grammar', 'reviewed-disposition', 'adapter-identity'];
        self::syntax($capsule, $checks);
        $evidenceTests = self::evidence($root, $capsule, $slug, $manifest, $package->dispositionPath(), $checks);

        return [
            'format' => self::FORMAT,
            'adapter' => $slug,
            'digest' => $identity['digest'],
            'manifest_sha256' => hash('sha256', Canon::encode($manifest)),
            'checks' => $checks,
            'evidence_tests' => $evidenceTests,
        ];
    }

    /** @param list<string> $checks */
    private static function syntax(string $capsule, array &$checks): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($capsule, \FilesystemIterator::SKIP_DOTS)
        );
        $php = 0;
        $shell = 0;
        $json = 0;
        foreach ($iterator as $entry) {
            $path = $entry->getPathname();
            if ($entry->isLink() || !$entry->isFile() || realpath($path) !== $path) {
                throw new RuntimeException("Adapter capsule contains a non-ordinary file: $path");
            }
            if (str_ends_with($path, '.php')) {
                self::command([PHP_BINARY, '-l', $path], "PHP syntax failed for $path");
                $php++;
            } elseif (str_ends_with($path, '.sh')) {
                self::command(['bash', '-n', $path], "shell syntax failed for $path");
                $shell++;
            } elseif (str_ends_with($path, '.json')) {
                Canon::decode(Canon::read_file($path));
                $json++;
            }
        }
        $checks[] = "php-syntax:$php";
        $checks[] = "shell-syntax:$shell";
        $checks[] = "json-syntax:$json";
    }

    /**
     * @param array<string,mixed> $manifest
     * @param list<string> $checks
     * @return list<string>
     */
    private static function evidence(
        string $root,
        string $capsule,
        string $slug,
        array $manifest,
        string $dispositionPath,
        array &$checks
    ): array {
        $disposition = Canon::decode(Canon::read_file($dispositionPath));
        if (!is_array($disposition)) {
            throw new RuntimeException("Adapter package '$slug' disposition is not an object");
        }
        $tests = $disposition['evidence']['tests'] ?? [];
        if (!is_array($tests) || !array_is_list($tests)) {
            throw new RuntimeException("Adapter package '$slug' disposition evidence tests are malformed");
        }

        $available = [];
        $testsRoot = $capsule . '/tests';
        if (is_dir($testsRoot)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($testsRoot, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $entry) {
                if (!$entry->isFile()) {
                    continue;
                }
                $basename = $entry->getBasename('.' . $entry->getExtension());
                if (preg_match('/^(?:regress|certify|spike)_[a-z0-9][a-z0-9._-]*$/D', $basename) === 1) {
                    $available[str_replace('_', '-', $basename)] = true;
                }
            }
        }
        $conformance = $testsRoot . '/conformance';
        if (is_file($conformance . '/entry.json')) {
            $entry = Canon::decode(Canon::read_file($conformance . '/entry.json'));
            if (!is_array($entry)
                || ($entry['manifest'] ?? null) !== $slug
                || !is_array($entry['entry'] ?? null)
                || (!is_file($conformance . '/seed.sh') && !is_file($conformance . '/check.sh'))) {
                throw new RuntimeException("Adapter package '$slug' conformance fixture is incomplete or misnamed");
            }
            $available['conformance-' . $slug] = true;
        }
        if (is_file($testsRoot . '/certify/version-matrix.sh')) {
            $available['exact-artifact-version-matrix'] = true;
        }
        foreach (self::externalEvidence($root, $capsule) as $test => $_path) {
            $available[$test] = true;
        }

        $normalized = [];
        foreach ($tests as $test) {
            if (!is_string($test) || $test === '' || !isset($available[$test])) {
                throw new RuntimeException("Adapter package '$slug' cites undiscoverable evidence test " . var_export($test, true));
            }
            $normalized[] = $test;
        }
        if (($disposition['status'] ?? null) === 'certified' && $normalized === []) {
            throw new RuntimeException("Certified adapter package '$slug' has no discoverable evidence tests");
        }
        if (($manifest['name'] ?? null) !== $slug) {
            throw new RuntimeException("Adapter package '$slug' manifest identity changed during evidence validation");
        }
        $checks[] = 'evidence-wiring:' . count($normalized);
        return $normalized;
    }

    /** @return array<string,string> */
    private static function externalEvidence(string $root, string $capsule): array
    {
        $path = $capsule . '/evidence/external-tests.json';
        if (!file_exists($path)) {
            return [];
        }
        $document = Canon::decode(Canon::read_file($path));
        if (!is_array($document)
            || ($document['format'] ?? null) !== self::EXTERNAL_EVIDENCE_FORMAT
            || !is_array($document['tests'] ?? null)
            || array_keys($document) !== ['format', 'tests']) {
            throw new RuntimeException("Adapter external evidence document is malformed: $path");
        }
        $tests = [];
        foreach ($document['tests'] as $test => $relative) {
            if (!is_string($test)
                || preg_match('/^[a-z0-9][a-z0-9._-]*$/D', $test) !== 1
                || !is_string($relative)
                || !str_starts_with($relative, 'integration-scenarios/')
                || str_contains($relative, '..')
                || realpath($root . '/' . $relative) !== $root . '/' . $relative
                || !is_file($root . '/' . $relative)) {
                throw new RuntimeException("Adapter external evidence entry is invalid: $path");
            }
            $tests[$test] = $relative;
        }
        ksort($tests, SORT_STRING);
        return $tests;
    }

    /** @param non-empty-list<string> $command */
    private static function command(array $command, string $message): void
    {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException("$message: process could not start");
        }
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        if ($status !== 0) {
            $detail = trim($stderr !== '' ? $stderr : $stdout);
            throw new RuntimeException($message . ($detail === '' ? '' : ": $detail"));
        }
    }

    private static function defineVersions(string $root): void
    {
        $source = (string) file_get_contents($root . '/agent/duo.php');
        if (!defined('DUO_AGENT_VERSION')) {
            if (preg_match("/define\\('DUO_AGENT_VERSION', '([^']+)'\\)/D", $source, $match) !== 1) {
                throw new RuntimeException('Could not resolve DUO_AGENT_VERSION for adapter validation');
            }
            define('DUO_AGENT_VERSION', $match[1]);
        }
        if (!defined('DUO_SPEC_VERSION')) {
            if (preg_match("/define\\('DUO_SPEC_VERSION', ([0-9]+)\\)/D", $source, $match) !== 1) {
                throw new RuntimeException('Could not resolve DUO_SPEC_VERSION for adapter validation');
            }
            define('DUO_SPEC_VERSION', (int) $match[1]);
        }
    }

    private static function repositoryRoot(string $path): string
    {
        if ($path === '' || str_contains($path, "\0") || is_link($path)) {
            throw new RuntimeException("Repository root is not an ordinary directory: $path");
        }
        $root = realpath($path);
        if ($root === false || !is_dir($root) || !is_readable($root)) {
            throw new RuntimeException("Repository root is not an ordinary directory: $path");
        }
        return rtrim($root, '/');
    }
}
