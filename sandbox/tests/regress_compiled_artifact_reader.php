<?php
/**
 * Offline regression for CompiledArtifactReader (DUO-3348 slice 35).
 *
 * Persisted artifacts are validated against a complete active Policy before
 * they can prime repository-derived interpreter facts. The reader is a
 * compiler-builder-independent boundary; RepositoryCompiler retains only the
 * established static compatibility facade for production callers.
 */
declare(strict_types=1);

namespace Duo {
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

        /** @param array<string,mixed> $tree */
        public function prime_interpreters_from_repository(array $tree): void {
            $this->primeCalls++;
            $this->primedTrees[] = $tree;
        }
    }
}

namespace {
    use Duo\ArtifactPolicyIdentity;
    use Duo\Canon;
    use Duo\CompiledArtifactReader;
    use Duo\CompiledRepository;
    use Duo\Policy;
    use Duo\RepositoryCompilationException;

    $reader = __DIR__ . '/../../agent/src/Repository/CompiledArtifactReader.php';
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
            && !class_exists(\Duo\RepositoryCompiler::class, false),
        'CompiledArtifactReader directly loads the persisted-artifact collaborators without the repository-tree compiler'
    );

    $normalLoader = <<<'PHP'
define('DUO_SPEC_VERSION', 2);
function is_multisite(): bool { return false; }
require_once %s;
$classes = [
    Duo\Canon::class,
    Duo\Policy::class,
    Duo\ArtifactPolicyIdentity::class,
    Duo\CompiledRepository::class,
    Duo\CompiledArtifactReader::class,
    Duo\AdapterSources::class,
    Duo\ManifestDispositions::class,
    Duo\CapabilityRegistry::class,
];
foreach ($classes as $class) {
    if (!class_exists($class, false)) {
        fwrite(STDERR, "missing $class\n");
        exit(1);
    }
}
if (class_exists(Duo\RepositoryCompiler::class, false)) {
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

    $tmp = sys_get_temp_dir() . '/duo-compiled-artifact-reader-' . bin2hex(random_bytes(6));
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
    $diagnostic = static function (callable $run): ?string {
        try {
            $run();
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
            && $stateOnly->primedTrees === [[]],
        'a valid state-only artifact round-trips exactly and primes interpreters only after validation'
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
namespace Duo;
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

    \Duo\CodeStateContract::$fail = false;
    $codeSuccessPolicy = new Policy(['enabled' => true]);
    $codeSuccess = CompiledArtifactReader::read_artifact($codePath, $codeSuccessPolicy);
    $check(
        $codeSuccess->code_descriptor() === ['code_revision' => 'test']
            && \Duo\CodeStateContract::$calls === 2
            && $codeSuccessPolicy->primeCalls === 1,
        'a code-bearing artifact primes interpreters only after the optional bridge accepts its descriptor'
    );

    $compilerSource = (string) file_get_contents(__DIR__ . '/../../agent/src/Repository/RepositoryCompiler.php');
    $readerSource = (string) file_get_contents(__DIR__ . '/../../agent/src/Repository/CompiledArtifactReader.php');
    $check(
        substr_count($compilerSource, 'CompiledArtifactReader::read_artifact($path, $policy)') === 1
            && !str_contains($compilerSource, 'private static function artifact_exception')
            && substr_count($readerSource, 'CompiledRepository::from_array(Canon::decode(Canon::read_file($path)))') === 1,
        'RepositoryCompiler keeps one exact reader facade and no duplicate persisted-artifact validation body'
    );

    if ($failures !== []) {
        fwrite(STDERR, implode("\n", $failures) . "\n");
        exit(1);
    }
    echo "compiled artifact reader regression passed\n";
}
