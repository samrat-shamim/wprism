<?php
/**
 * The frozen-policy envelope for suites whose subject is NOT the snapshot wire.
 *
 * Eighteen suites needed a `Policy` instance to test something else — a
 * grammar, an action selection, an effects inventory — and built one by handing
 * `Policy::from_snapshot()` the smallest envelope it would accept. That
 * envelope used to be `duo-policy-snapshot/v4`, whose
 * `duo-adapter-sources/v1` record let a manifest absent from `out_of_tree` take
 * shipped authority with no proof at all, so any synthetic manifest name
 * loaded for free. v4 is now refused by name
 * (`Policy::from_snapshot()`), and v2 makes that shipped claim fail-closed:
 * `AdapterSources::from_snapshot()` compares the frozen manifest canonically
 * against `<manifests_dir>/<name>.json` and refuses with "frozen provenance
 * cannot relabel site content as shipped" when there is nothing to compare to.
 *
 * So a synthetic manifest now needs a trusted library that really holds its
 * bytes. `envelope()` writes exactly that into caller-owned scratch and
 * `adapterLibrary()` closes the fixture into an explicit AdapterLibrary. No
 * process environment selects it: `policy()` and `fromEnvelope()` hand that
 * same object to `Policy::from_snapshot()`.
 *
 * Suites that publish their OWN flat fixture library pass its directory to
 * `envelope()` and `fromEnvelope()`; this is an explicit historical-layout
 * test input, never production discovery.
 */
declare(strict_types=1);

namespace DuoTest;

final class FrozenPolicy
{
    /** The one wire generation `Policy::from_snapshot()` reads. */
    public const SNAPSHOT_FORMAT = 'duo-policy-snapshot/v6';
    public const ADAPTER_SOURCES_FORMAT = 'duo-adapter-sources/v2';

    private static ?string $library = null;

    /**
     * A scratch shipped manifest library, created once per process and removed
     * at shutdown. Callers select it only through adapterLibrary().
     */
    public static function library(): string
    {
        if (self::$library !== null) {
            return self::$library;
        }
        $dir = sys_get_temp_dir() . '/duo_frozen_policy_' . bin2hex(random_bytes(6));
        if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
            fwrite(STDERR, "FAIL: cannot create scratch manifest library $dir\n");
            exit(1);
        }
        self::$library = $dir;
        register_shutdown_function(static function () use ($dir): void {
            self::removeTree($dir);
        });
        return $dir;
    }

    /**
     * Publish `$manifests` as shipped bytes so the v2 membership proof has
     * something to compare against, and return the v6 envelope naming them.
     *
     * The bytes are rewritten on every call because refusal cases mutate a
     * manifest and reuse its name: the proof is against THESE bytes, not
     * against whatever an earlier case happened to leave behind.
     *
     * `$library` names an existing scratch manifest directory the CALLER
     * already owns — the case for a suite that also drives `Policy::load()`
     * against its own fixture library. It is never checked-in adapter source:
     * publishing there would alter adapter identity.
     *
     * @param list<array<string,mixed>> $manifests
     * @param array<string,mixed>       $site
     * @return array<string,mixed>
     */
    public static function envelope(array $manifests, array $site, ?string $library = null): array
    {
        $dir = $library;
        if ($dir === null) {
            $dir = self::library();
        }
        foreach ($manifests as $manifest) {
            $name = (string) ($manifest['name'] ?? '');
            if ($name === '') {
                // A nameless manifest is a case about manifest validation, which
                // refuses before the provenance proof runs. Nothing to publish.
                continue;
            }
            file_put_contents($dir . '/' . $name . '.json', \Duo\Canon::encode($manifest));
        }
        return [
            'adapter_sources' => self::adapterSources(),
            'dispositions' => null,
            'format' => self::SNAPSHOT_FORMAT,
            'manifests' => $manifests,
            'site' => $site,
        ];
    }

    /**
     * Close the published fixture bytes into one explicit strict library.
     *
     * The platform documents are copied from the active library; synthetic
     * manifests receive deliberately non-product dispositions. Declared hook
     * bytes come from the real package when that package owns the same id,
     * otherwise an inert placeholder closes the physical inventory for tests
     * that exercise policy grammar rather than hook behavior.
     */
    public static function adapterLibrary(?string $library = null): \Duo\AdapterLibrary
    {
        $dir = rtrim($library ?? self::library(), '/');
        foreach (['capabilities', 'dispositions', 'interpreters', 'providers', 'regenerators'] as $relative) {
            $path = "$dir/$relative";
            if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
                throw new \RuntimeException("cannot create frozen-policy library directory $path");
            }
        }

        $active = \Duo\Policy::adapter_library_context();
        copy($active->platformBoundaryPath(), "$dir/capabilities/platform.json");
        copy($active->authoritiesPath(), "$dir/capabilities/adapter-authorities.json");
        file_put_contents("$dir/dispositions/profiles.json", "{}\n");

        $anchor = "$dir/frozen-fixture-anchor.json";
        if (!is_file($anchor)) {
            file_put_contents($anchor, \Duo\Canon::encode([
                'name' => 'frozen-fixture-anchor',
                'option_autoload' => 'preserve',
                'options' => [],
                'spec_version' => defined('DUO_SPEC_VERSION') ? DUO_SPEC_VERSION : 3,
            ]));
        }

        $expectedRuntime = ['interpreters' => [], 'providers' => [], 'regenerators' => []];
        foreach (glob("$dir/*.json") ?: [] as $manifestPath) {
            $manifest = \Duo\Canon::decode(\Duo\Canon::read_file($manifestPath));
            if (!is_array($manifest) || !is_string($manifest['name'] ?? null) || $manifest['name'] === '') {
                continue;
            }
            $name = $manifest['name'];
            file_put_contents(
                "$dir/dispositions/$name.json",
                \Duo\Canon::encode(self::disposition($manifest))
            );
            try {
                $package = $active->package($name);
            } catch (\Throwable) {
                $package = null;
            }

            $interpreter = $manifest['interpreter'] ?? null;
            if (is_string($interpreter) && $interpreter !== '') {
                $source = $package?->interpreterPath();
                $expectedRuntime['interpreters'][$interpreter] = is_string($source) ? $source : null;
            }
            foreach (($manifest['providers'] ?? []) as $provider) {
                $id = is_array($provider) ? ($provider['id'] ?? null) : null;
                if (!is_string($id) || $id === '' || ($provider['source'] ?? null) !== 'manifest') {
                    continue;
                }
                try {
                    $expectedRuntime['providers'][$id] = $package?->providerPath($id);
                } catch (\Throwable) {
                    $expectedRuntime['providers'][$id] = null;
                }
            }
            foreach (($manifest['post_types'] ?? []) as $postType) {
                $id = is_array($postType) ? ($postType['regen_dependency']['regenerator'] ?? null) : null;
                if (!is_string($id) || $id === '') {
                    continue;
                }
                try {
                    $expectedRuntime['regenerators'][$id] = $package?->regeneratorPath($id);
                } catch (\Throwable) {
                    $expectedRuntime['regenerators'][$id] = null;
                }
            }
        }

        foreach ($expectedRuntime as $kind => $members) {
            foreach (glob("$dir/$kind/*.php") ?: [] as $path) {
                if (!array_key_exists(basename($path, '.php'), $members)) {
                    unlink($path);
                }
            }
            foreach ($members as $id => $source) {
                $target = "$dir/$kind/$id.php";
                if (is_file($target)) {
                    continue;
                }
                if (is_string($source) && is_file($source)) {
                    copy($source, $target);
                } else {
                    file_put_contents($target, "<?php\n");
                }
            }
        }

        return \Duo\AdapterLibrary::fromLegacyFlatDirectory($dir);
    }

    /** Build and load the common frozen-policy vehicle in one explicit step. */
    public static function policy(array $manifests, array $site, ?string $library = null): \Duo\Policy
    {
        return self::fromEnvelope(self::envelope($manifests, $site, $library), $library);
    }

    /** Load a caller-adjusted envelope against the fixture bytes it names. */
    public static function fromEnvelope(array $snapshot, ?string $library = null): \Duo\Policy
    {
        return \Duo\Policy::from_snapshot($snapshot, self::adapterLibrary($library));
    }

    /**
     * The site half most vehicles want: pin exactly the manifests being frozen,
     * declare no authored policy.
     *
     * @param list<array<string,mixed>> $manifests
     * @return array<string,mixed>
     */
    public static function site(array $manifests, int $specVersion = 2): array
    {
        return [
            'manifests' => array_map(
                static fn(array $manifest): string => (string) ($manifest['name'] ?? ''),
                $manifests
            ),
            'policy' => ['options' => [], 'post_meta' => [], 'term_meta' => [], 'user_meta' => []],
            'spec_version' => $specVersion,
        ];
    }

    /** @return array<string,mixed> */
    public static function adapterSources(): array
    {
        return [
            'certificates' => [],
            'format' => self::ADAPTER_SOURCES_FORMAT,
            'out_of_tree' => [],
        ];
    }

    /** @param array<string,mixed> $manifest */
    private static function disposition(array $manifest): array
    {
        $unsupported = [[
            'operation' => 'apply',
            'reason' => 'synthetic frozen-policy fixture supports no product capability claim',
            'surface' => 'fixture.synthetic',
        ]];
        $defaultKeyspaces = [];
        foreach (($manifest['tables'] ?? []) as $table => $rule) {
            if (($rule['class'] ?? null) === 'authored_typed_snapshot_post_v1') {
                $unsupported[] = [
                    'operation' => 'apply',
                    'reason' => 'intent-only fixture tables are deliberately unsupported',
                    'surface' => "tables.$table",
                ];
            }
            if (($rule['default_class'] ?? null) === 'authored') {
                $defaultKeyspaces[] = [
                    'reason' => 'synthetic fixture covers its declared default-authored keyspace',
                    'status' => 'justified',
                    'table' => $table,
                ];
            }
        }
        return [
            'capabilities' => [
                'deletion_semantics' => [
                    'supported' => [],
                    'unsupported' => ['nothing is deletable in this synthetic fixture'],
                ],
                'entity_sections' => [],
                'field_sections' => [],
                'lifecycle_phases' => [],
                'operations' => ['apply'],
            ],
            'default_authored_keyspaces' => $defaultKeyspaces,
            'reason' => 'Synthetic frozen-policy fixture: policy mechanics, not a product claim.',
            'status' => 'experimental',
            'supported_versions' => ['fixture' => true],
            'unsupported' => $unsupported,
        ];
    }

    private static function removeTree(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::removeTree("$path/$entry");
                }
            }
            @rmdir($path);
            return;
        }
        @unlink($path);
    }
}
