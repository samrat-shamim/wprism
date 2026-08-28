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
require_once __DIR__ . '/AdapterProductionReadiness.php';
require_once __DIR__ . '/ArtifactLibrary.php';

/** Validate one adapter capsule without reading or executing a sibling adapter. */
final class AdapterPackageValidator
{
    public const FORMAT = 'duo-adapter-package-validation/v1';
    public const RUNTIME_SDK_FORMAT = 'duo-adapter-runtime-sdk/v1';
    private const EXTERNAL_EVIDENCE_FORMAT = 'duo-adapter-external-evidence/v1';
    private const INTEGRATION_SCENARIO_FORMAT = 'duo-adapter-integration-scenario/v1';

    /** @var list<string> */
    private const SHARED_EVIDENCE_PREFIXES = [
        'agent/src/',
        'platform/adapter-library/',
        'sandbox/conformance/',
        'sandbox/tests/live/',
        'sandbox/tests/offline/',
    ];

    /**
     * Public engine symbols used by the current manifest-bound runtime.
     *
     * This closed set is the versioned adapter ABI. Adding a dependency is an
     * engine SDK decision; merely importing another Duo class from a capsule is
     * not enough to make that internal class public.
     *
     * @var list<string>
     */
    private const RUNTIME_SDK_SYMBOLS = [
        'Duo\\CacheInvalidationTransaction',
        'Duo\\Canon',
        'Duo\\IdentityTokenCodec',
        'Duo\\Ledger',
        'Duo\\ManifestProviderRuntime',
        'Duo\\NativeActions',
        'Duo\\NativeRewriteEffects',
        'Duo\\PlainData',
        'Duo\\Policy',
        'Duo\\ProviderSdk',
        'Duo\\Providers',
        'Duo\\Secrets',
        'Duo\\SidebarState',
        'Duo\\Tokens',
        'Duo\\WpCliChildProcess',
    ];

    /** @return array{format:string,symbols:list<string>} */
    public static function runtimeSdk(): array
    {
        return [
            'format' => self::RUNTIME_SDK_FORMAT,
            'symbols' => self::RUNTIME_SDK_SYMBOLS,
        ];
    }

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
        $capsule = $root . '/adapter-packages/' . $slug;
        $checks = ['closed-library', 'manifest-grammar', 'reviewed-disposition', 'adapter-identity'];
        self::dependencyBoundary($root, $capsule, $slug, $checks);

        $manifest = Canon::decode(Canon::read_file($package->manifestPath()));
        if (!is_array($manifest)) {
            throw new RuntimeException("Adapter package '$slug' manifest is not an object");
        }
        $disposition = Canon::decode(Canon::read_file($package->dispositionPath()));
        if (!is_array($disposition)) {
            throw new RuntimeException("Adapter package '$slug' disposition is not an object");
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

        self::syntax($capsule, $checks);
        self::packageEvidence($root, $slug, $manifest, $disposition, $checks);
        $evidenceTests = self::evidence($root, $capsule, $slug, $manifest, $disposition, $checks);

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
    private static function dependencyBoundary(string $root, string $capsule, string $slug, array &$checks): void
    {
        $agentSource = realpath($root . '/agent/src');
        if ($agentSource === false || !is_dir($agentSource)) {
            throw new RuntimeException("Adapter package '$slug' cannot resolve the repository agent/src contract");
        }

        $scanned = 0;
        foreach ([$capsule . '/package/runtime', $capsule . '/tests'] as $boundaryRoot) {
            if (!is_dir($boundaryRoot)) {
                continue;
            }
            $tests = $boundaryRoot === $capsule . '/tests';
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($boundaryRoot, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $entry) {
                if (!$entry->isFile() || $entry->isLink()) {
                    continue;
                }
                $path = $entry->getPathname();
                if (!in_array($entry->getExtension(), ['json', 'php', 'sh'], true)) {
                    continue;
                }
                $source = file_get_contents($path);
                if ($source === false) {
                    throw new RuntimeException("Adapter package '$slug' cannot read dependency source $path");
                }
                self::assertNoGlobalLibrarySelection($capsule, $path, $source, $tests, $slug);
                self::assertRelativeAgentDependencies($root, $agentSource, $capsule, $path, $source, $tests, $slug);
                if (!$tests && $entry->getExtension() === 'php') {
                    self::assertRuntimeSdk($capsule, $path, $source, $slug);
                }
                $scanned++;
            }
        }
        $checks[] = "dependency-boundary:$scanned";
        $checks[] = 'runtime-sdk:' . self::RUNTIME_SDK_FORMAT;
    }

    private static function assertRuntimeSdk(string $capsule, string $path, string $source, string $slug): void
    {
        $tokens = token_get_all($source);
        $previous = null;
        $useStatement = null;
        $useLine = 0;
        foreach ($tokens as $token) {
            if (!is_array($token)) {
                if ($useStatement !== null) {
                    if ($token === '(' && trim($useStatement) === '') {
                        // Closure capture, not an import declaration.
                        $useStatement = null;
                    } elseif ($token === ';') {
                        self::assertResolvableDuoImport($capsule, $path, $slug, $useStatement, $useLine);
                        $useStatement = null;
                    } else {
                        $useStatement .= $token;
                    }
                }
                continue;
            }
            [$kind, $bytes, $line] = $token;
            if ($kind === T_USE) {
                $useStatement = '';
                $useLine = $line;
            } elseif ($useStatement !== null && !in_array($kind, [T_COMMENT, T_DOC_COMMENT], true)) {
                $useStatement .= $bytes;
            }
            if (in_array($kind, [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                $symbol = ltrim($bytes, '\\');
                if ($previous !== T_NAMESPACE && str_starts_with($symbol, 'Duo\\')) {
                    self::assertRuntimeSdkSymbol($capsule, $path, $slug, $symbol, $line);
                }
            }
            if (!in_array($kind, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $previous = $kind;
            }
        }
    }

    private static function assertResolvableDuoImport(
        string $capsule,
        string $path,
        string $slug,
        string $statement,
        int $line
    ): void {
        $normalized = preg_replace('/\s+/', '', $statement);
        if (!is_string($normalized)) {
            return;
        }
        foreach (['function', 'const'] as $kind) {
            if (str_starts_with(strtolower($normalized), $kind)) {
                $normalized = substr($normalized, strlen($kind));
                break;
            }
        }
        $normalized = ltrim($normalized, '\\');
        $grouped = str_starts_with($normalized, 'Duo\\{');
        $rootAlias = $normalized === 'Duo'
            || preg_match('/^Duoas[A-Za-z_][A-Za-z0-9_]*$/i', $normalized) === 1;
        if (!$grouped && !$rootAlias) {
            return;
        }
        $relative = substr($path, strlen($capsule) + 1);
        throw new RuntimeException(
            "Adapter package '$slug' uses an unresolved grouped or root Duo import at $relative:$line; "
            . 'runtime dependencies must name exact symbols from ' . self::RUNTIME_SDK_FORMAT
        );
    }

    private static function assertRuntimeSdkSymbol(
        string $capsule,
        string $path,
        string $slug,
        string $symbol,
        int $line
    ): void {
        if (in_array($symbol, self::RUNTIME_SDK_SYMBOLS, true)) {
            return;
        }
        $relative = substr($path, strlen($capsule) + 1);
        throw new RuntimeException(
            "Adapter package '$slug' depends on non-SDK Duo symbol '$symbol' at $relative:$line; "
            . 'allowed surface is ' . self::RUNTIME_SDK_FORMAT
        );
    }

    private static function assertNoGlobalLibrarySelection(
        string $capsule,
        string $path,
        string $source,
        bool $tests,
        string $slug
    ): void {
        foreach (preg_split('/\R/', $source) ?: [] as $offset => $line) {
            $normalized = preg_replace(
                '/DUO_[\'".\s]*MANIFESTS_DIR/',
                'DUO_MANIFESTS_DIR',
                $line
            );
            if (!is_string($normalized) || !str_contains($normalized, 'DUO_MANIFESTS_DIR')) {
                continue;
            }
            if ($tests
                && substr_count($normalized, 'DUO_MANIFESTS_DIR') === 1
                && self::isNegativeTextAssertion($normalized, 'DUO_MANIFESTS_DIR')) {
                continue;
            }
            $relative = substr($path, strlen($capsule) + 1);
            throw new RuntimeException(
                "Adapter package '$slug' selects a manifest library through DUO_MANIFESTS_DIR at "
                . "$relative:" . ($offset + 1)
            );
        }
    }

    private static function assertRelativeAgentDependencies(
        string $root,
        string $agentSource,
        string $capsule,
        string $path,
        string $source,
        bool $tests,
        string $slug
    ): void {
        foreach (preg_split('/\R/', $source) ?: [] as $offset => $line) {
            preg_match_all(
                '~(?<relative>(?:\.\./)+agent/src)(?:/|(?=[\'\"]))~',
                $line,
                $matches
            );
            foreach ($matches['relative'] ?? [] as $relativeAgent) {
                if ($tests
                    && substr_count($line, $relativeAgent) === 1
                    && self::isNegativeTextAssertion($line, $relativeAgent)) {
                    continue;
                }
                $resolved = realpath(dirname($path) . '/' . $relativeAgent);
                if ($resolved === $agentSource) {
                    continue;
                }
                $relative = substr($path, strlen($capsule) + 1);
                $target = $resolved === false ? dirname($path) . '/' . $relativeAgent : $resolved;
                throw new RuntimeException(
                    "Adapter package '$slug' has a legacy relative agent dependency at $relative:"
                    . ($offset + 1) . "; '$relativeAgent' does not resolve to $root/agent/src (resolved $target)"
                );
            }
        }
    }

    private static function isNegativeTextAssertion(string $line, string $needle): bool
    {
        $quotedNeedle = preg_quote($needle, '~');
        return preg_match(
            '~!\s*str_(?:contains|starts_with|ends_with)\s*\([^;]*[\'\"][^\'\"]*' . $quotedNeedle
                . '|str_(?:contains|starts_with|ends_with)\s*\([^;]*[\'\"][^\'\"]*' . $quotedNeedle
                . '[^;]*\)\s*===\s*false'
                . '|(?:^|[;&|]\s*)!\s*(?:grep|rg)\b[^;]*' . $quotedNeedle . '~D',
            $line
        ) === 1;
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
        array $disposition,
        array &$checks
    ): array {
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

    /**
     * @param array<string,mixed> $manifest
     * @param array<string,mixed> $disposition
     * @param list<string> $checks
     */
    private static function packageEvidence(
        string $root,
        string $slug,
        array $manifest,
        array $disposition,
        array &$checks
    ): void {
        if (($disposition['status'] ?? null) === 'excluded') {
            $checks[] = 'package-evidence:excluded';
            return;
        }

        $artifacts = ArtifactLibrary::loadPackage($root, $slug);
        $plugin = $manifest['plugin'] ?? null;
        if (is_string($plugin)) {
            $subject = explode('/', $plugin, 2)[0];
            if (!isset($artifacts['plugins'][$subject])) {
                throw new RuntimeException(
                    "Adapter package '$slug' artifact evidence does not own its manifest plugin '$subject'"
                );
            }
        }
        AdapterProductionReadiness::record(
            $root,
            $slug,
            static function (string $repo, string $adapter, string $evidence): void {
                self::assertReadinessEvidenceOwnership($repo, $adapter, $evidence);
            }
        );
        $checks[] = 'artifact-evidence';
        $checks[] = 'production-readiness';
    }

    private static function assertReadinessEvidenceOwnership(
        string $root,
        string $slug,
        string $evidence
    ): void {
        $path = $root . '/' . $evidence;
        if (realpath($path) !== $path) {
            throw new RuntimeException(
                "Adapter package '$slug' readiness evidence escapes its declared ownership path: $evidence"
            );
        }

        if (str_starts_with($evidence, "adapter-packages/$slug/")) {
            return;
        }
        if (str_starts_with($evidence, 'adapter-packages/')) {
            throw new RuntimeException(
                "Adapter package '$slug' readiness evidence cites a sibling adapter capsule: $evidence"
            );
        }
        if (str_starts_with($evidence, 'integration-scenarios/')) {
            self::assertScenarioEvidenceOwnership($root, $slug, $evidence);
            return;
        }
        foreach (self::SHARED_EVIDENCE_PREFIXES as $prefix) {
            if (str_starts_with($evidence, $prefix)) {
                return;
            }
        }

        throw new RuntimeException(
            "Adapter package '$slug' readiness evidence is outside the closed shared evidence roots: $evidence"
        );
    }

    private static function assertScenarioEvidenceOwnership(string $root, string $slug, string $evidence): void
    {
        if (preg_match(
            '~^integration-scenarios/([a-z][a-z0-9]*(?:-[a-z0-9]+)*)/tests/(offline|live|certify|spike)/([^/]+)\\.(php|sh)$~D',
            $evidence,
            $match
        ) !== 1) {
            throw new RuntimeException(
                "Adapter package '$slug' readiness evidence is not a recognized integration scenario gate: $evidence"
            );
        }

        $scenario = $match[1];
        $class = $match[2];
        $filename = $match[3] . '.' . $match[4];
        $prefix = $class === 'spike' ? 'spike' : ($class === 'certify' ? '(?:certify|regress)' : 'regress');
        if (preg_match('/^' . $prefix . '_[a-z0-9][a-z0-9._-]*\\.(?:php|sh)$/D', $filename) !== 1) {
            throw new RuntimeException(
                "Adapter package '$slug' readiness evidence is not a recognized integration scenario gate: $evidence"
            );
        }
        $directory = $root . '/integration-scenarios/' . $scenario;
        $recordPath = $directory . '/scenario.json';
        if (realpath($directory) !== $directory
            || !is_dir($directory)
            || is_link($directory)
            || realpath($recordPath) !== $recordPath
            || !is_file($recordPath)
            || is_link($recordPath)) {
            throw new RuntimeException(
                "Adapter package '$slug' readiness evidence scenario has no ordinary participant record: $scenario"
            );
        }

        $record = Canon::decode(Canon::read_file($recordPath));
        if (!is_array($record)
            || array_is_list($record)
            || array_keys($record) !== ['format', 'participants']
            || ($record['format'] ?? null) !== self::INTEGRATION_SCENARIO_FORMAT
            || !is_array($record['participants'] ?? null)
            || !array_is_list($record['participants'])
            || count($record['participants']) < 2) {
            throw new RuntimeException(
                "Adapter package '$slug' readiness evidence scenario '$scenario' has a malformed participant record"
            );
        }

        $participants = [];
        foreach ($record['participants'] as $participant) {
            if (!is_string($participant)
                || preg_match('/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/D', $participant) !== 1
                || isset($participants[$participant])) {
                throw new RuntimeException(
                    "Adapter package '$slug' readiness evidence scenario '$scenario' has invalid participants"
                );
            }
            $participants[$participant] = true;
        }
        $ordered = array_keys($participants);
        $sorted = $ordered;
        sort($sorted, SORT_STRING);
        if ($ordered !== $sorted) {
            throw new RuntimeException(
                "Adapter package '$slug' readiness evidence scenario '$scenario' participants must be sorted"
            );
        }
        if (!isset($participants[$slug])) {
            throw new RuntimeException(
                "Adapter package '$slug' readiness evidence cites undeclared integration scenario '$scenario'"
            );
        }
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
