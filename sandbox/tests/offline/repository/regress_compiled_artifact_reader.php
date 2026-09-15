<?php
/**
 * Offline regression for CompiledArtifactReader (issue #3348 slice 35).
 *
 * Persisted artifacts are validated against a complete active Policy before
 * they can prime repository-derived interpreter facts. The reader is a
 * compiler-builder-independent boundary; RepositoryCompiler retains only the
 * established static compatibility facade for production callers.
 */
declare(strict_types=1);

namespace WPrism {
    final class Canon {
        public static function encode(mixed $value): string {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        }

        /** @return array<string,mixed> */
        public static function decode(string $bytes): array {
            $decoded = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) {
                throw new \RuntimeException('test Canon expects an object');
            }
            return $decoded;
        }

        public static function read_file(string $path): string {
            $bytes = @file_get_contents($path);
            if (!is_string($bytes)) {
                throw new \RuntimeException("could not read $path");
            }
            return $bytes;
        }
    }

    // CompiledRepository validates descriptors through this caller-provided
    // contract. Keep it fake here so the reader test can separately prove the
    // optional CodeStateContract branch below.
    final class Code {
        /** @param array<string,mixed> $descriptor */
        public static function assert_descriptor(array $descriptor): void {}
    }

    final class Policy {
        /** @var array<string,mixed> */
        public array $site;
        /** @var list<array<string,mixed>> */
        public array $manifests = [];
        /** @var list<array<string,mixed>> */
        public array $effects = [];
        /** @var ?array<string,mixed> */
        public ?array $code;
        public int $primeCalls = 0;
        /** @var list<array<string,mixed>> */
        public array $primedTrees = [];
        /** @var list<array{artifact_hash:string,site_hash:string,manifest_hash:string,resolved_adapters:list<array<string,mixed>>}> */
        public array $boundIdentities = [];

        /** @param ?array<string,mixed> $code */
        public function __construct(?array $code = null) {
            $this->site = ['spec_version' => 2, 'policy' => []];
            $this->code = $code;
        }

        public function manifest_disposition(string $name): ?array {
            return null;
        }

        /** @return list<array<string,mixed>> */
        public function effects_inventory(): array {
            return $this->effects;
        }

        /** @return ?array<string,mixed> */
        public function code_config(): ?array {
            return $this->code;
        }

        /** @param list<array<string,mixed>> $resolvedAdapters */
        public function bind_execution_artifact_identity(
            string $artifactHash,
            string $siteHash,
            string $manifestHash,
            array $resolvedAdapters
        ): void {
            $this->boundIdentities[] = [
                'artifact_hash' => $artifactHash,
                'site_hash' => $siteHash,
                'manifest_hash' => $manifestHash,
                'resolved_adapters' => $resolvedAdapters,
            ];
        }

        /** @param array<string,mixed> $tree */
        public function prime_interpreters_from_repository(array $tree): void {
            $this->primeCalls++;
            $this->primedTrees[] = $tree;
        }
    }
}

namespace {
    use WPrism\ArtifactPolicyIdentity;
    use WPrism\Canon;
    use WPrism\CompiledArtifactReader;
    use WPrism\CompiledRepository;
    use WPrism\Policy;
    use WPrism\CommandRefusalException;
    use WPrism\RepositoryCompilationException;
    use WPrism\StoragePrerequisiteSettlement;

    if (!defined('WPRISM_TEST_MODE')) {
        define('WPRISM_TEST_MODE', true);
    }
    $reader = __DIR__ . '/../../../../agent/src/Repository/CompiledArtifactReader.php';
    require_once $reader;

    $failures = [];
    $check = static function (bool $ok, string $message) use (&$failures): void {
        echo ($ok ? 'ok: ' : 'FAIL: ') . $message . "\n";
        if (!$ok) {
            $failures[] = $message;
        }
    };

    $check(
        class_exists(CompiledArtifactReader::class, false)
            && class_exists(CompiledRepository::class, false)
            && class_exists(ArtifactPolicyIdentity::class, false)
            && !class_exists(\WPrism\RepositoryCompiler::class, false),
        'CompiledArtifactReader directly loads the persisted-artifact collaborators without the repository-tree compiler'
    );

    $normalLoader = <<<'PHP'
define('WPRISM_SPEC_VERSION', 2);
function is_multisite(): bool { return false; }
require_once %s;
$classes = [
    WPrism\Canon::class,
    WPrism\Policy::class,
    WPrism\ArtifactPolicyIdentity::class,
    WPrism\CompiledRepository::class,
    WPrism\CompiledArtifactReader::class,
    WPrism\AdapterSources::class,
    WPrism\ManifestDispositions::class,
];
foreach ($classes as $class) {
    if (!class_exists($class, false)) {
        fwrite(STDERR, "missing $class\n");
        exit(1);
    }
}
if (class_exists(WPrism\RepositoryCompiler::class, false)) {
    fwrite(STDERR, "reader loaded RepositoryCompiler\n");
    exit(1);
}
echo "normal reader load closed\n";
PHP;
    $normalLoader = sprintf($normalLoader, var_export($reader, true));
    $normalOutput = [];
    $normalRc = 0;
    exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($normalLoader) . ' 2>&1', $normalOutput, $normalRc);
    $check(
        $normalRc === 0 && implode("\n", $normalOutput) === 'normal reader load closed',
        'a normal fresh reader load closes the complete Canon/Policy identity stack without loading RepositoryCompiler'
    );

    $tmp = sys_get_temp_dir() . '/wprism-compiled-artifact-reader-' . bin2hex(random_bytes(6));
    if (!mkdir($tmp, 0777, true) && !is_dir($tmp)) {
        throw new RuntimeException("could not create $tmp");
    }
    register_shutdown_function(static function () use ($tmp): void {
        foreach (glob($tmp . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($tmp);
    });

    /** @param ?array<string,mixed> $code @param list<array<string,mixed>> $effects @return array<string,mixed> */
    $payload = static function (Policy $policy, array $effects = [], ?array $code = null): array {
        $out = [
            'compiler_version' => 1,
            'spec_version' => 2,
            'site_hash' => ArtifactPolicyIdentity::site_hash($policy),
            'manifest_hash' => ArtifactPolicyIdentity::manifest_hash($policy),
            'revision_hash' => str_repeat('a', 64),
            'resolved_adapters' => [],
            'effects_inventory' => $effects,
            'media_catalog' => [],
            'media' => [],
            'tree' => [],
            'deletions' => [],
        ];
        if ($code !== null) {
            $out['code'] = $code;
        }
        return $out;
    };
    /** @param array<string,mixed> $payload */
    $writeArtifact = static function (string $path, array $payload): CompiledRepository {
        $artifact = CompiledRepository::create($payload);
        file_put_contents($path, Canon::encode($artifact->export()));
        return $artifact;
    };
    // Every gate in read_artifact() now refuses with a CommandRefusalException
    // whose reasonCode IS the artifact code, so a machine caller reads it at
    // the top level of the JSON envelope instead of walking diagnostics
    // (agent/src/Repository/CompiledArtifactReader.php:86-91). The
    // RepositoryCompilationException arm is kept because it is what a genuine
    // compiler batch still throws, and reading diagnostics[0] the same way in
    // both arms is what keeps every expected code below unchanged.
    $diagnostic = static function (callable $run): ?string {
        try {
            $run();
        } catch (CommandRefusalException $e) {
            return $e->reasonCode;
        } catch (RepositoryCompilationException $e) {
            return (string) ($e->diagnostics[0]['code'] ?? '');
        }
        return null;
    };

    $stateOnly = new Policy();
    $validPath = "$tmp/valid.json";
    $expected = $writeArtifact($validPath, $payload($stateOnly));
    $actual = CompiledArtifactReader::read_artifact($validPath, $stateOnly);
    $check(
        $actual->export() === $expected->export()
            && $stateOnly->primeCalls === 1
            && $stateOnly->primedTrees === [[]]
            && $stateOnly->boundIdentities === [[
                'artifact_hash' => $expected->artifact_hash(),
                'site_hash' => $expected->site_hash(),
                'manifest_hash' => $expected->manifest_hash(),
                'resolved_adapters' => $expected->resolved_adapters(),
            ]],
        'a valid state-only artifact binds execution identity and primes interpreters only after validation'
    );

    $linkedPath = "$tmp/linked.json";
    symlink($validPath, $linkedPath);
    $linkedPolicy = new Policy();
    $check(
        $diagnostic(static fn() => CompiledArtifactReader::read_artifact($linkedPath, $linkedPolicy)) === 'compiled_artifact_invalid'
            && $linkedPolicy->primeCalls === 0,
        'a final-symlink artifact path is refused before its document is read or interpreter facts are primed'
    );

    $mutatingPath = "$tmp/mutating.json";
    $writeArtifact($mutatingPath, $payload(new Policy()));
    $GLOBALS['wprism_compiled_artifact_observation_interleave'] = static function (string $path): void {
        $bytes = file_get_contents($path);
        if (!is_string($bytes) || $bytes === '') {
            throw new RuntimeException('test could not rewrite the observed artifact');
        }
        $bytes[0] = $bytes[0] === '{' ? '[' : '{';
        file_put_contents($path, $bytes);
    };
    $mutatingPolicy = new Policy();
    $mutatingCode = $diagnostic(static fn() => CompiledArtifactReader::read_artifact($mutatingPath, $mutatingPolicy));
    unset($GLOBALS['wprism_compiled_artifact_observation_interleave']);
    $check(
        $mutatingCode === 'compiled_artifact_invalid' && $mutatingPolicy->primeCalls === 0,
        'an equal-length artifact rewrite between observations is refused before Canon can decode it'
    );

    $oversizedPath = "$tmp/oversized.json";
    $oversized = fopen($oversizedPath, 'wb');
    if (!is_resource($oversized) || !ftruncate($oversized, \WPrism\MediaPayloadAuthority::MAX_ARTIFACT_DOCUMENT_BYTES + 1)) {
        throw new RuntimeException('could not create sparse oversized artifact fixture');
    }
    fclose($oversized);
    $oversizedPolicy = new Policy();
    $check(
        $diagnostic(static fn() => CompiledArtifactReader::read_artifact($oversizedPath, $oversizedPolicy)) === 'compiled_artifact_invalid'
            && $oversizedPolicy->primeCalls === 0,
        'an oversized sparse artifact is refused from its regular-file shape before a whole-document allocation'
    );

    $densePath = "$tmp/dense.json";
    $denseDocument = '[' . str_repeat('0,', 2999999) . '0]';
    file_put_contents($densePath, $denseDocument);
    unset($denseDocument);
    $denseChild = <<<'PHP'
namespace WPrism {
    final class Canon {
        public static int $decodeCalls = 0;
        public static function encode(mixed $value): string { return json_encode($value, JSON_THROW_ON_ERROR); }
        public static function decode(string $bytes): array { self::$decodeCalls++; return json_decode($bytes, true, 512, JSON_THROW_ON_ERROR); }
    }
    final class Code { public static function assert_descriptor(array $descriptor): void {} }
    final class Policy {}
}
namespace {
    require $argv[1];
    try {
        WPrism\CompiledArtifactReader::read_artifact($argv[2], new WPrism\Policy());
        exit(2);
    } catch (WPrism\CommandRefusalException $error) {
        echo $error->reasonCode . ':' . WPrism\Canon::$decodeCalls;
    }
}
PHP;
    $denseOutput = [];
    $denseRc = 0;
    exec(
        escapeshellarg(PHP_BINARY) . ' -d memory_limit=128M -r ' . escapeshellarg($denseChild)
        . ' ' . escapeshellarg($reader) . ' ' . escapeshellarg($densePath) . ' 2>&1',
        $denseOutput,
        $denseRc
    );
    $check(
        $denseRc === 0 && implode("\n", $denseOutput) === 'compiled_artifact_invalid:0',
        'a 3,000,000-scalar 6,000,001-byte artifact is refused in a 128 MiB child before json_decode can allocate its dense PHP array'
    );

    $refreshDenseChild = <<<'PHP'
require $argv[1];
$raw = file_get_contents($argv[2]);
try {
    WPrism\MediaPayloadAuthority::assertRefreshEnvelope($raw);
    exit(2);
} catch (RuntimeException $error) {
    echo $error->getMessage();
}
PHP;
    $refreshDenseOutput = [];
    $refreshDenseRc = 0;
    $authority = __DIR__ . '/../../../../agent/src/Kernel/MediaPayloadAuthority.php';
    exec(
        escapeshellarg(PHP_BINARY) . ' -d memory_limit=128M -r ' . escapeshellarg($refreshDenseChild)
        . ' ' . escapeshellarg($authority) . ' ' . escapeshellarg($densePath) . ' 2>&1',
        $refreshDenseOutput,
        $refreshDenseRc
    );
    $refreshSource = (string) file_get_contents(__DIR__ . '/../../../../cli/src/Refresh/Refresh.php');
    $check(
        $refreshDenseRc === 0
            && str_contains(implode("\n", $refreshDenseOutput), 'bounded PHP memory headroom')
            && str_contains($refreshSource, 'MediaPayloadAuthority::assertRefreshEnvelope($rawOutput)'),
        'the host refresh envelope applies the same bounded dense-JSON preflight before its remote json_decode'
    );

    $largeTree = [];
    for ($index = 0; $index < 10000; $index++) {
        $data = ['type' => 'page'];
        for ($field = 0; $field < 30; $field++) {
            $data['field-' . $field] = $field;
        }
        $largeTree['page-' . $index] = ['type' => 'post', 'data' => $data];
    }
    $largePath = "$tmp/large-legitimate.json";
    $largePayload = $payload(new Policy());
    $largePayload['tree'] = $largeTree;
    $writeArtifact($largePath, $largePayload);
    unset($largeTree, $largePayload);
    $largeChild = <<<'PHP'
namespace WPrism {
    final class Canon {
        public static function encode(mixed $value): string { return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES); }
        public static function decode(string $bytes): array { return json_decode($bytes, true, 512, JSON_THROW_ON_ERROR); }
    }
    final class Code { public static function assert_descriptor(array $descriptor): void {} }
    final class Policy {
        public array $site = ['spec_version' => 2, 'policy' => []];
        public array $manifests = [];
        public array $effects = [];
        public ?array $code = null;
        public function manifest_disposition(string $name): ?array { return null; }
        public function effects_inventory(): array { return $this->effects; }
        public function code_config(): ?array { return $this->code; }
        public function bind_execution_artifact_identity(
            string $artifactHash,
            string $siteHash,
            string $manifestHash,
            array $resolvedAdapters
        ): void {}
        public function prime_interpreters_from_repository(array $tree): void {}
    }
}
namespace {
    require $argv[1];
    try {
        WPrism\CompiledArtifactReader::read_artifact($argv[2], new WPrism\Policy());
        echo 'ok';
    } catch (Throwable $error) {
        fwrite(STDERR, $error->getMessage());
        exit(1);
    }
}
PHP;
    $largeOutput = [];
    $largeRc = 0;
    exec(
        escapeshellarg(PHP_BINARY) . ' -d memory_limit=1G -r ' . escapeshellarg($largeChild)
        . ' ' . escapeshellarg($reader) . ' ' . escapeshellarg($largePath) . ' 2>&1',
        $largeOutput,
        $largeRc
    );
    $check(
        $largeRc === 0 && implode("\n", $largeOutput) === 'ok',
        'a 10,000-record artifact with 300,000 ordinary metadata fields is admitted on a 1 GiB worker by dynamic structural headroom'
    );

    $malformedPath = "$tmp/malformed.json";
    file_put_contents($malformedPath, '{}');
    $malformedPolicy = new Policy();
    $check(
        $diagnostic(static fn() => CompiledArtifactReader::read_artifact($malformedPath, $malformedPolicy)) === 'compiled_artifact_invalid'
            && $malformedPolicy->primeCalls === 0,
        'a malformed persisted artifact is refused before interpreter priming'
    );

    $sitePath = "$tmp/site-mismatch.json";
    $sitePayload = $payload(new Policy());
    $sitePayload['site_hash'] = str_repeat('0', 64);
    $writeArtifact($sitePath, $sitePayload);
    $sitePolicy = new Policy();
    $check(
        $diagnostic(static fn() => CompiledArtifactReader::read_artifact($sitePath, $sitePolicy)) === 'compiled_artifact_policy_mismatch'
            && $sitePolicy->primeCalls === 0,
        'a site-policy identity mismatch is refused before interpreter priming'
    );

    $manifestPath = "$tmp/manifest-mismatch.json";
    $manifestPayload = $payload(new Policy());
    $manifestPayload['manifest_hash'] = str_repeat('1', 64);
    $writeArtifact($manifestPath, $manifestPayload);
    $manifestPolicy = new Policy();
    $check(
        $diagnostic(static fn() => CompiledArtifactReader::read_artifact($manifestPath, $manifestPolicy)) === 'compiled_artifact_manifest_mismatch'
            && $manifestPolicy->primeCalls === 0,
        'a manifest identity mismatch is refused before interpreter priming'
    );

    $effectsPath = "$tmp/effects-mismatch.json";
    $writeArtifact($effectsPath, $payload(new Policy(), [['effect' => 'different']]));
    $effectsPolicy = new Policy();
    $check(
        $diagnostic(static fn() => CompiledArtifactReader::read_artifact($effectsPath, $effectsPolicy)) === 'compiled_artifact_invalid'
            && $effectsPolicy->primeCalls === 0,
        'a canonical effect-inventory mismatch is refused before interpreter priming'
    );

    $storagePolicy = new Policy();
    $storagePolicy->manifests = [[
        'name' => 'storage-reader-fixture',
        'spec_version' => 3,
        'engine_features' => ['spec-window/v1', 'storage-prerequisites/v1'],
        'options' => ['fixture_version' => ['class' => 'runtime']],
        'storage_prerequisites' => [['equals' => '1.0.0', 'option' => 'fixture_version']],
    ]];
    $storagePayload = $payload($storagePolicy);
    $storagePayload['storage_prerequisites_inventory'] = StoragePrerequisiteSettlement::inventory(
        $storagePolicy->manifests
    );
    $storagePath = "$tmp/storage-valid.json";
    $storageExpected = $writeArtifact($storagePath, $storagePayload);
    $storageActual = CompiledArtifactReader::read_artifact($storagePath, $storagePolicy);
    $check(
        $storageActual->storage_prerequisites_inventory()
            === $storageExpected->storage_prerequisites_inventory(),
        'a compiled storage prerequisite projection remains bound to the active manifest contract'
    );
    $missingStoragePath = "$tmp/storage-missing.json";
    $writeArtifact($missingStoragePath, $payload($storagePolicy));
    $missingStoragePolicy = clone $storagePolicy;
    $missingStoragePolicy->primeCalls = 0;
    $check(
        $diagnostic(static fn() => CompiledArtifactReader::read_artifact(
            $missingStoragePath,
            $missingStoragePolicy
        )) === 'compiled_artifact_invalid'
            && $missingStoragePolicy->primeCalls === 0,
        'a missing compiled storage prerequisite projection refuses before interpreter priming'
    );

    $precedencePolicy = new Policy();
    $precedencePath = "$tmp/precedence-site.json";
    $precedencePayload = $payload($precedencePolicy, [['effect' => 'different']], ['code_revision' => 'test']);
    $precedencePayload['site_hash'] = str_repeat('0', 64);
    $precedencePayload['manifest_hash'] = str_repeat('1', 64);
    $writeArtifact($precedencePath, $precedencePayload);
    $check(
        $diagnostic(static fn() => CompiledArtifactReader::read_artifact($precedencePath, $precedencePolicy)) === 'compiled_artifact_policy_mismatch'
            && $precedencePolicy->primeCalls === 0,
        'site identity remains the first persisted-artifact refusal when every later validation also disagrees'
    );

    $manifestPrecedencePolicy = new Policy();
    $manifestPrecedencePath = "$tmp/precedence-manifest.json";
    $manifestPrecedencePayload = $payload($manifestPrecedencePolicy, [['effect' => 'different']], ['code_revision' => 'test']);
    $manifestPrecedencePayload['manifest_hash'] = str_repeat('1', 64);
    $writeArtifact($manifestPrecedencePath, $manifestPrecedencePayload);
    $check(
        $diagnostic(static fn() => CompiledArtifactReader::read_artifact($manifestPrecedencePath, $manifestPrecedencePolicy)) === 'compiled_artifact_manifest_mismatch'
            && $manifestPrecedencePolicy->primeCalls === 0,
        'manifest identity remains before effect and code validation for a valid site identity'
    );

    $effectsPrecedencePolicy = new Policy();
    $effectsPrecedencePath = "$tmp/precedence-effects.json";
    $writeArtifact($effectsPrecedencePath, $payload($effectsPrecedencePolicy, [['effect' => 'different']], ['code_revision' => 'test']));
    $check(
        $diagnostic(static fn() => CompiledArtifactReader::read_artifact($effectsPrecedencePath, $effectsPrecedencePolicy)) === 'compiled_artifact_invalid'
            && $effectsPrecedencePolicy->primeCalls === 0,
        'effect inventory remains before code-presence validation for matching policy identities'
    );

    $presencePath = "$tmp/code-presence-mismatch.json";
    $presencePolicy = new Policy();
    $writeArtifact($presencePath, $payload($presencePolicy, [], ['code_revision' => 'test']));
    $check(
        $diagnostic(static fn() => CompiledArtifactReader::read_artifact($presencePath, $presencePolicy)) === 'compiled_artifact_code_mismatch'
            && $presencePolicy->primeCalls === 0,
        'a policy/artifact code-presence mismatch is refused before code-state loading or interpreter priming'
    );

    $codePolicy = new Policy(['enabled' => true]);
    $codePath = "$tmp/code.json";
    $writeArtifact($codePath, $payload($codePolicy, [], ['code_revision' => 'test']));
    $check(
        $diagnostic(static fn() => CompiledArtifactReader::read_artifact($codePath, $codePolicy)) === 'compiled_artifact_code_state_contract_unavailable'
            && $codePolicy->primeCalls === 0,
        'a code-bearing artifact retains the stable refusal when the optional code/state bridge is absent'
    );

    eval(<<<'PHP'
namespace WPrism;
final class CodeStateContract {
    public static bool $fail = true;
    public static int $calls = 0;
    public static function validate(CompiledRepository $artifact, array $descriptor): void {
        self::$calls++;
        if (self::$fail) {
            throw new \RuntimeException('test code/state bridge mismatch');
        }
    }
}
PHP);
    $mismatchPolicy = new Policy(['enabled' => true]);
    $check(
        $diagnostic(static fn() => CompiledArtifactReader::read_artifact($codePath, $mismatchPolicy)) === 'compiled_artifact_code_state_mismatch'
            && $mismatchPolicy->primeCalls === 0,
        'a throwing code/state bridge is normalized to the stable mismatch diagnostic before interpreter priming'
    );

    \WPrism\CodeStateContract::$fail = false;
    $codeSuccessPolicy = new Policy(['enabled' => true]);
    $codeSuccess = CompiledArtifactReader::read_artifact($codePath, $codeSuccessPolicy);
    $check(
        $codeSuccess->code_descriptor() === ['code_revision' => 'test']
            && \WPrism\CodeStateContract::$calls === 2
            && $codeSuccessPolicy->primeCalls === 1
            && count($codeSuccessPolicy->boundIdentities) === 1,
        'a code-bearing artifact binds execution identity and primes interpreters only after the optional bridge accepts its descriptor'
    );

    $compilerSource = (string) file_get_contents(__DIR__ . '/../../../../agent/src/Repository/RepositoryCompiler.php');
    $readerSource = (string) file_get_contents(__DIR__ . '/../../../../agent/src/Repository/CompiledArtifactReader.php');
    $check(
        substr_count($compilerSource, 'CompiledArtifactReader::read_artifact($path, $policy)') === 1
            && !str_contains($compilerSource, 'private static function artifact_exception')
            && substr_count($readerSource, 'MediaPayloadAuthority::readArtifactDocument($path)') === 1
            && !str_contains($readerSource, 'Canon::read_file($path)'),
        'RepositoryCompiler keeps one reader facade while persisted artifacts prove bounded physical bytes before Canon decode'
    );

    if ($failures !== []) {
        fwrite(STDERR, implode("\n", $failures) . "\n");
        exit(1);
    }
    echo "compiled artifact reader regression passed\n";
}
