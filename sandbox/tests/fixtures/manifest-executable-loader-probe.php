<?php

declare(strict_types=1);

$repositoryRoot = dirname(__DIR__, 3);
require_once $repositoryRoot . '/sandbox/tests/lib/agent_version.php';
wprism_test_define_agent_versions();

function is_multisite(): bool
{
    return false;
}

require_once $repositoryRoot . '/agent/src/Policy/ArtifactPolicyIdentity.php';
require_once $repositoryRoot . '/agent/src/Adapter/Providers.php';
require_once $repositoryRoot . '/sandbox/tests/offline/policy/manifest_fixtures.php';

use WPrism\AdapterLibrary;
use WPrism\ArtifactPolicyIdentity;
use WPrism\Canon;
use WPrism\ManifestExecutableLoader;
use WPrism\Policy;
use WPrism\Providers;

/** @var list<string> $scratchRoots */
$scratchRoots = [];
register_shutdown_function(static function () use (&$scratchRoots): void {
    foreach (array_reverse($scratchRoots) as $scratchRoot) {
        if (file_exists($scratchRoot)) {
            manifest_fixture_remove_tree($scratchRoot);
        }
    }
});

function probe_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function probe_runtime_source(string $kind, string $class, string $marker): string
{
    $separator = strrpos($class, '\\');
    probe_assert(is_int($separator), "runtime class has no namespace: $class");
    $namespace = ltrim(substr($class, 0, $separator), '\\');
    $leaf = substr($class, $separator + 1);
    $mark = '\\file_put_contents(' . var_export($marker, true) . ", 'x', FILE_APPEND);\n";
    $contract = match ($kind) {
        'interpreters' => <<<'PHP'
    public function __construct(\WPrism\Policy $policy) {}
    public function post_meta_rule(string $key, array $allMeta): ?array { return null; }
PHP,
        'providers' => <<<'PHP'
    public function __construct(\WPrism\Policy $policy) {}
    public function identity(): array { return ['id' => 'fixture', 'plugin' => 'fixture/fixture.php', 'version' => '1.0.0']; }
    public function capabilities(): array { return []; }
    public function invoke(string $capability, array $args): array { return ['before' => [], 'after' => [], 'verified' => true]; }
PHP,
        'regenerators' => <<<'PHP'
    public function __construct(\WPrism\Policy $policy) {}
    public function regenerate(int $localId): void {}
PHP,
        default => throw new RuntimeException("unknown fixture runtime kind: $kind"),
    };
    return "<?php\nnamespace $namespace;\n$mark\nfinal class $leaf {\n$contract\n}\n";
}

/** @return array<string,mixed> */
function probe_manifest(string $adapter, string $kind, string $id): array
{
    $manifest = [
        'name' => $adapter,
        'spec_version' => WPRISM_SPEC_VERSION,
    ];
    if ($kind === 'interpreters') {
        $manifest['interpreter'] = $id;
    } elseif ($kind === 'providers') {
        $manifest['plugin'] = 'fixture/fixture.php';
        $manifest['version_range'] = ['min' => '1.0.0', 'max' => '2.0.0'];
        $manifest['providers'] = [[
            'id' => $id,
            'version' => '1.0.0',
            'source' => 'manifest',
            'plugin' => 'fixture/fixture.php',
            'capabilities' => ['run'],
        ]];
        $manifest['actions'] = [[
            'kind' => 'provider',
            'provider' => $id,
            'capability' => 'run',
            'args' => [],
        ]];
    } elseif ($kind === 'regenerators') {
        $manifest['post_types'] = [
            'fixture' => [
                'regen_dependency' => [
                    'regenerator' => $id,
                    'verify' => ['table' => 'fixture_rows', 'column' => 'post_id'],
                ],
            ],
        ];
    } else {
        throw new RuntimeException("unknown fixture runtime kind: $kind");
    }
    return $manifest;
}

/**
 * @return array{root:string,marker:string,manifest:array<string,mixed>,policy:Policy,path:string,class:class-string}
 */
function probe_policy(string $adapter, string $kind, string $id): array
{
    global $scratchRoots;
    $root = sys_get_temp_dir() . '/wprism-manifest-loader-' . bin2hex(random_bytes(8));
    $scratchRoots[] = $root;
    foreach (['interpreters', 'providers', 'regenerators'] as $directory) {
        mkdir("$root/$directory", 0700, true);
    }
    $manifest = probe_manifest($adapter, $kind, $id);
    Canon::write_file("$root/$adapter.json", Canon::encode($manifest));
    $marker = "$root/marker";
    $class = AdapterLibrary::runtimeClassName($kind, $id);
    $path = "$root/$kind/$id.php";
    file_put_contents($path, probe_runtime_source($kind, $class, $marker));
    $library = manifest_fixture_adapter_library($root);
    $policy = Policy::load(null, [$adapter], adapterLibrary: $library);
    return compact('root', 'marker', 'manifest', 'policy', 'path', 'class');
}

function probe_load_route(Policy $policy, string $kind, string $id): object
{
    if ($kind === 'interpreters') {
        return $policy->interpreters()[$id];
    }
    if ($kind === 'regenerators') {
        return $policy->regenerators()[$id];
    }
    $declaration = $policy->provider_declarations()[$id] ?? null;
    probe_assert(is_array($declaration), "provider declaration $id is absent");
    $loader = new ReflectionMethod(Providers::class, 'manifest_provider');
    $provider = $loader->invoke(null, $policy, $declaration);
    probe_assert(is_object($provider), "provider route $id returned no object");
    return $provider;
}

function probe_bind_execution(Policy $policy): void
{
    $resolved = ArtifactPolicyIdentity::resolved_adapters($policy);
    $policy->bind_execution_artifact_identity(
        str_repeat('a', 64),
        ArtifactPolicyIdentity::site_hash($policy),
        ArtifactPolicyIdentity::manifest_hash($policy),
        $resolved
    );
}

function probe_routes(): void
{
    foreach (['interpreters', 'providers', 'regenerators'] as $kind) {
        $id = 'route-' . substr($kind, 0, -1);
        $fixture = probe_policy('route-' . substr($kind, 0, -1), $kind, $id);
        $instance = probe_load_route($fixture['policy'], $kind, $id);
        probe_assert(get_class($instance) === ltrim($fixture['class'], '\\'), "$kind route loaded the wrong class");
        probe_assert(file_get_contents($fixture['marker']) === 'x', "$kind route did not execute its exact source once");
    }
}

function probe_drift(string $kind): void
{
    $stem = substr($kind, 0, -1);
    $fixture = probe_policy("drift-$stem", $kind, "drift-$stem");
    probe_bind_execution($fixture['policy']);
    file_put_contents(
        $fixture['path'],
        "\n\\file_put_contents(" . var_export($fixture['marker'], true) . ", 'drift', FILE_APPEND);\n",
        FILE_APPEND
    );
    $message = '';
    try {
        probe_load_route($fixture['policy'], $kind, "drift-$stem");
    } catch (Throwable $failure) {
        $message = $failure->getMessage();
    }
    probe_assert(str_contains($message, 'no longer matches its validated adapter identity'), "$kind drift was not identity-refused: $message");
    probe_assert(!file_exists($fixture['marker']), "$kind drift executed package bytes before refusal");
}

function probe_occupancy(string $symbolKind): void
{
    $runtimeKind = match ($symbolKind) {
        'class', 'enum' => 'providers',
        'interface' => 'interpreters',
        'trait' => 'regenerators',
        default => throw new RuntimeException("unknown symbol kind: $symbolKind"),
    };
    $id = "occupied-$symbolKind";
    $fixture = probe_policy("occupied-$symbolKind", $runtimeKind, $id);
    $separator = strrpos($fixture['class'], '\\');
    probe_assert(is_int($separator), 'occupied class has no namespace');
    $namespace = ltrim(substr($fixture['class'], 0, $separator), '\\');
    $leaf = substr($fixture['class'], $separator + 1);
    $declaration = match ($symbolKind) {
        'class' => "final class $leaf {}",
        'enum' => "enum $leaf {}",
        'interface' => "interface $leaf {}",
        'trait' => "trait $leaf {}",
    };
    eval("namespace $namespace; $declaration");
    $message = '';
    try {
        probe_load_route($fixture['policy'], $runtimeKind, $id);
    } catch (Throwable $failure) {
        $message = $failure->getMessage();
    }
    probe_assert(str_contains($message, "preloaded as $symbolKind outside its validated engine loader"), "occupied $symbolKind was not refused: $message");
    probe_assert(!file_exists($fixture['marker']), "occupied $symbolKind allowed package marker execution");
}

function probe_autoload(): void
{
    $fixture = probe_policy('autoload-probe', 'interpreters', 'autoload-probe');
    $calls = 0;
    spl_autoload_register(static function (string $requested) use (&$calls, $fixture): void {
        if (strcasecmp(ltrim($requested, '\\'), ltrim($fixture['class'], '\\')) !== 0) {
            return;
        }
        ++$calls;
        $separator = strrpos($fixture['class'], '\\');
        if (!is_int($separator)) {
            return;
        }
        $namespace = ltrim(substr($fixture['class'], 0, $separator), '\\');
        $leaf = substr($fixture['class'], $separator + 1);
        eval("namespace $namespace; final class $leaf {}");
    });
    $instance = probe_load_route($fixture['policy'], 'interpreters', 'autoload-probe');
    probe_assert($calls === 0, 'loader triggered an ambient autoloader for its expected class');
    $definedIn = (new ReflectionClass($instance))->getFileName();
    probe_assert(is_string($definedIn) && realpath($definedIn) === realpath($fixture['path']), 'autoload candidate displaced the descriptor source');
}

function probe_reuse(): void
{
    $first = probe_policy('reuse-one', 'interpreters', 'reuse-probe');
    $firstDescriptor = ArtifactPolicyIdentity::runtime_component_descriptor(
        $first['policy'],
        'reuse-one',
        'interpreters',
        'reuse-probe'
    );
    $loaded = ManifestExecutableLoader::load($firstDescriptor);
    $reused = ManifestExecutableLoader::load($firstDescriptor);
    probe_assert($loaded === $reused, 'same executable descriptor did not reuse its class');
    probe_assert(file_get_contents($first['marker']) === 'x', 'same descriptor executed its source more than once');

    $second = probe_policy('reuse-two', 'interpreters', 'reuse-probe');
    $secondDescriptor = ArtifactPolicyIdentity::runtime_component_descriptor(
        $second['policy'],
        'reuse-two',
        'interpreters',
        'reuse-probe'
    );
    $message = '';
    try {
        ManifestExecutableLoader::load($secondDescriptor);
    } catch (Throwable $failure) {
        $message = $failure->getMessage();
    }
    probe_assert(str_contains($message, 'already bound to a different digest-bound component'), "different descriptor was not refused: $message");
    probe_assert(!file_exists($second['marker']), 'different descriptor executed before its class-binding refusal');
}

function probe_opcode_seam(): void
{
    $source = (string) file_get_contents(dirname(__DIR__, 3) . '/agent/src/Kernel/ManifestExecutableLoader.php');
    probe_assert(!str_contains($source, 'opcache_get_status'), 'loader still trusts status visibility for opcode-cache enablement');
    $invalidate = new ReflectionMethod(ManifestExecutableLoader::class, 'invalidateOpcodeCache');
    $message = '';
    try {
        $invalidate->invoke(
            null,
            __FILE__,
            'opcode-seam',
            'provider',
            'opcode-seam',
            true,
            static fn(string $path): bool => false
        );
    } catch (Throwable $failure) {
        $message = $failure->getMessage();
    }
    probe_assert(
        str_contains($message, 'opcode cache could not be invalidated before loading'),
        "configured opcode cache with unavailable/failed invalidation did not refuse: $message"
    );
}

function probe_opcode_live(): void
{
    if (!function_exists('opcache_invalidate')) {
        return;
    }
    probe_assert(ini_get('opcache.enable') === '1', 'live opcode probe did not enable opcache.enable');
    probe_assert(ini_get('opcache.enable_cli') === '1', 'live opcode probe did not enable opcache.enable_cli');
    $fixture = probe_policy('opcode-live', 'interpreters', 'opcode-live');
    $instance = probe_load_route($fixture['policy'], 'interpreters', 'opcode-live');
    probe_assert(get_class($instance) === ltrim($fixture['class'], '\\'), 'opcode-enabled loader returned the wrong class');
    probe_assert(file_get_contents($fixture['marker']) === 'x', 'opcode-enabled loader did not execute the exact source');
}

function probe_opcode_restricted(): void
{
    $fixture = probe_policy('opcode-restricted', 'interpreters', 'opcode-restricted');
    $message = '';
    try {
        probe_load_route($fixture['policy'], 'interpreters', 'opcode-restricted');
    } catch (Throwable $failure) {
        $message = $failure->getMessage();
    }
    probe_assert(
        str_contains($message, 'opcode cache could not be invalidated before loading'),
        "restricted opcode API did not produce the controlled loader refusal: $message"
    );
    probe_assert(!file_exists($fixture['marker']), 'restricted opcode API executed package bytes before refusal');
}

/** @param list<string> $order */
function probe_collision_message(string $kind, array $order): string
{
    global $scratchRoots;
    $root = sys_get_temp_dir() . '/wprism-manifest-collision-' . bin2hex(random_bytes(8));
    $scratchRoots[] = $root;
    mkdir($root, 0700, true);
    $ids = match ($kind) {
        'interpreters', 'regenerators' => ['alpha' => 'shared-name', 'beta' => 'shared_name'],
        'providers' => ['alpha' => 'shared-name', 'beta' => 'shared--name'],
        default => throw new RuntimeException("unknown collision kind: $kind"),
    };
    foreach ($order as $adapter) {
        Canon::write_file("$root/$adapter.json", Canon::encode(probe_manifest($adapter, $kind, $ids[$adapter])));
    }
    try {
        manifest_fixture_adapter_library($root);
    } catch (Throwable $failure) {
        return $failure->getMessage();
    }
    throw new RuntimeException("$kind normalized class collision was accepted");
}

function probe_collisions(): void
{
    foreach (['interpreters', 'providers', 'regenerators'] as $kind) {
        $forward = probe_collision_message($kind, ['alpha', 'beta']);
        $reverse = probe_collision_message($kind, ['beta', 'alpha']);
        probe_assert($forward === $reverse, "$kind collision refusal depends on discovery order");
        probe_assert(str_contains($forward, 'normalize to the same PHP class'), "$kind collision did not name the normalized PHP class: $forward");
        probe_assert(str_contains($forward, 'alpha/shared-name') && str_contains($forward, 'beta/shared'), "$kind collision did not name both claims: $forward");
    }
}

$scenario = $argv[1] ?? '';
try {
    if ($scenario === 'routes') {
        probe_routes();
    } elseif (str_starts_with($scenario, 'drift-')) {
        probe_drift(substr($scenario, strlen('drift-')));
    } elseif (str_starts_with($scenario, 'occupancy-')) {
        probe_occupancy(substr($scenario, strlen('occupancy-')));
    } elseif ($scenario === 'autoload') {
        probe_autoload();
    } elseif ($scenario === 'reuse') {
        probe_reuse();
    } elseif ($scenario === 'opcache-seam') {
        probe_opcode_seam();
    } elseif ($scenario === 'opcache-live') {
        probe_opcode_live();
    } elseif ($scenario === 'opcache-restricted') {
        probe_opcode_restricted();
    } elseif ($scenario === 'collisions') {
        probe_collisions();
    } else {
        throw new RuntimeException("unknown scenario: $scenario");
    }
    fwrite(STDOUT, "PASS $scenario\n");
} catch (Throwable $failure) {
    fwrite(STDERR, "FAIL $scenario: {$failure->getMessage()}\n{$failure->getTraceAsString()}\n");
    exit(1);
}
