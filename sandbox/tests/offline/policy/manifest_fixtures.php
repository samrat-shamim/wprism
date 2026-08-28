<?php
/**
 * The two-adapter manifest fixture pair, shared by every offline suite that
 * needs a well-formed manifest to break in one specific way.
 *
 * Established by sandbox/tests/offline/policy/regress_vocabulary_ownership.php (DUO-3318) and
 * moved here unchanged when sandbox/tests/offline/policy/regress_manifest_validate.php
 * (DUO-3327) needed the identical shapes: the offline authoring aid and the
 * load-time validators must be exercised against ONE set of declarations, or
 * the two suites drift into disagreeing about what a valid manifest looks like
 * — which is the exact failure the shared-fixture requirement exists to stop.
 *
 * Deliberately NOT named regress_*: this file is a fixture source, not a suite,
 * and sandbox/tests/offline/guards/regress_bundle_coverage.sh's survey (rightly) expects every
 * regress_* file to be either a runnable suite or invoked as `php <file>` by
 * one. A require'd helper is neither.
 *
 * Both builders read DUO_SPEC_VERSION at CALL time, so a suite that defines a
 * different supported version before requiring this file still gets fixtures
 * its own Policy::load() will accept.
 *
 * A manifest is not only JSON. Adapter A names a REGENERATOR, and a declared
 * regenerator name is a promise about a file — so manifest_fixture_code() below
 * writes that file, and any harness materializing these fixtures into a scratch
 * manifests dir must call it. See its own docblock.
 */

/**
 * Materialize the CODE half of these fixtures into a scratch manifests dir.
 *
 * manifest_a() declares `post_types.acme_thing.regen_dependency.regenerator =
 * "acme-a"`, and Policy::regenerators() resolves that name to
 * <manifests_dir>/regenerators/acme-a.php, requiring it to define
 * \Duo\Regenerators\AcmeA with regenerate(int $localId): void. Until DUO-3327's
 * offline check resolved that lazily-loaded half, a fixture could name a
 * regenerator it did not ship and nothing noticed; now it is a load-time fact,
 * so the fixture ships it.
 *
 * Minimal on purpose, and NOT a stub of anything: the loading contract is the
 * class and the method, nothing offline calls regenerate(), and a file with a
 * body would be claiming behavior no check here exercises. The constructor
 * takes the Policy the engine hands every regenerator (`new $class($this)`) and
 * types it loosely so this file needs no engine class at parse time.
 */
function manifest_fixture_code(string $dir): void {
    $regenerators = rtrim($dir, '/') . '/regenerators';
    if (!is_dir($regenerators) && !mkdir($regenerators, 0777, true) && !is_dir($regenerators)) {
        throw new \RuntimeException("could not create fixture regenerators dir $regenerators");
    }
    file_put_contents($regenerators . '/acme-a.php', <<<'PHP'
<?php
namespace Duo\Regenerators;

/** Fixture regenerator for manifest_a(): the loading contract, nothing more. */
final class AcmeA {
    public function __construct(private object $policy) {}

    public function regenerate(int $localId): void {}
}
PHP);
}

/** Remove what manifest_fixture_code() wrote, so a scratch dir can be rmdir'd. */
function manifest_fixture_code_cleanup(string $dir): void {
    $regenerators = rtrim($dir, '/') . '/regenerators';
    foreach (glob("$regenerators/*") ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($regenerators);
}

/**
 * Close a deliberately flat fixture into the explicit legacy-library shape.
 *
 * Production no longer reads DUO_MANIFESTS_DIR. Tests that intentionally
 * exercise flat authoring bytes therefore hand Policy one AdapterLibrary
 * object, including the platform-owned files and one reviewed entry per
 * manifest. Runtime placeholders close only the physical inventory; suites
 * that exercise a hook's class contract still write that hook themselves.
 */
function manifest_fixture_adapter_library(string $dir): \Duo\AdapterLibrary {
    $dir = rtrim($dir, '/');
    foreach (['capabilities', 'dispositions', 'interpreters', 'providers', 'regenerators'] as $relative) {
        $path = "$dir/$relative";
        if (!is_dir($path) && !mkdir($path, 0777, true) && !is_dir($path)) {
            throw new \RuntimeException("could not create fixture adapter-library directory $path");
        }
    }

    $source = \Duo\AdapterLibrary::fromSourceTree(dirname(__DIR__, 4));
    $platform = \Duo\Canon::decode(\Duo\Canon::read_file($source->platformBoundaryPath()));
    $platform['platform']['agent_version'] = defined('DUO_AGENT_VERSION') ? DUO_AGENT_VERSION : '0.6.0';
    $platform['platform']['spec_version'] = defined('DUO_SPEC_VERSION') ? DUO_SPEC_VERSION : 3;
    \Duo\Canon::write_file("$dir/capabilities/platform.json", \Duo\Canon::encode($platform));
    copy($source->authoritiesPath(), "$dir/capabilities/adapter-authorities.json");
    file_put_contents("$dir/dispositions/profiles.json", "{}\n");

    foreach (glob("$dir/*.json") ?: [] as $manifestFile) {
        $manifest = \Duo\Canon::decode(\Duo\Canon::read_file($manifestFile));
        $name = (string) ($manifest['name'] ?? '');
        if ($name === '') {
            continue;
        }
        $unsupported = [[
            'operation' => 'apply',
            'reason' => 'synthetic fixture adapter supports no product capability claim',
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
        $disposition = [
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
            'reason' => 'Synthetic fixture entry: this library tests policy mechanics, not a product claim.',
            'status' => 'experimental',
            'supported_versions' => ['fixture' => true],
            'unsupported' => $unsupported,
        ];
        \Duo\Canon::write_file("$dir/dispositions/$name.json", \Duo\Canon::encode($disposition));

        $interpreter = $manifest['interpreter'] ?? null;
        if (is_string($interpreter) && $interpreter !== '' && !is_file("$dir/interpreters/$interpreter.php")) {
            file_put_contents("$dir/interpreters/$interpreter.php", "<?php\n");
        }
        foreach (($manifest['providers'] ?? []) as $provider) {
            $id = $provider['id'] ?? null;
            if (($provider['source'] ?? null) === 'manifest' && is_string($id) && $id !== ''
                && !is_file("$dir/providers/$id.php")) {
                file_put_contents("$dir/providers/$id.php", "<?php\n");
            }
        }
        foreach (($manifest['post_types'] ?? []) as $postType) {
            $regenerator = $postType['regen_dependency']['regenerator'] ?? null;
            if (is_string($regenerator) && $regenerator !== ''
                && !is_file("$dir/regenerators/$regenerator.php")) {
                file_put_contents("$dir/regenerators/$regenerator.php", "<?php\n");
            }
        }
    }

    return \Duo\AdapterLibrary::fromLegacyFlatDirectory($dir);
}

/** Remove one caller-owned scratch tree materialized by this fixture helper. */
function manifest_fixture_remove_tree(string $path): void {
    if (is_dir($path) && !is_link($path)) {
        foreach (new \FilesystemIterator($path) as $item) {
            manifest_fixture_remove_tree($item->getPathname());
        }
        rmdir($path);
        return;
    }
    if (file_exists($path) || is_link($path)) {
        unlink($path);
    }
}

/** Load a FrozenPolicy envelope against the explicit library that holds it. */
function manifest_fixture_policy_from_snapshot(array $snapshot): \Duo\Policy {
    $library = manifest_fixture_adapter_library(\DuoTest\FrozenPolicy::library());
    return \Duo\Policy::from_snapshot($snapshot, $library);
}

/** Load live policy bytes from one explicit, deliberately flat fixture. */
function manifest_fixture_policy_load(string $dir, ?string $repo, ?array $manifestNames = null): \Duo\Policy {
    return \Duo\Policy::load(
        $repo,
        $manifestNames,
        adapterLibrary: manifest_fixture_adapter_library($dir)
    );
}

/**
 * Adapter A: an ordinary, well-formed plugin manifest. It owns a post type
 * (with a body mode, a phase, and a derived-field claim), an option
 * namespace, a table keyspace, and a provider. Everything manifest B tries
 * in the ownership suite is an attempt to reach into one of those.
 */
function manifest_a(array $overrides = []): array {
    return array_merge([
        'name' => 'a',
        'spec_version' => DUO_SPEC_VERSION,
        'plugin' => 'acme-a/acme-a.php',
        'version_range' => ['min' => '1.0.0', 'max' => '2.0.0'],
        'option_namespaces' => [['match' => '^acme_a_']],
        'options' => ['acme_a_setting' => ['class' => 'authored', 'autoload' => 'yes']],
        'post_types' => [
            'acme_thing' => [
                'class' => 'authored',
                'body' => 'verbatim',
                'phase' => 'early',
                'fields' => ['modified' => ['class' => 'derived']],
                'regen_dependency' => [
                    'regenerator' => 'acme-a',
                    'verify' => ['table' => 'acme_a_index', 'column' => 'room_id'],
                ],
            ],
        ],
        'providers' => [[
            'id' => 'acme-a-cache',
            'version' => '1.0.0',
            'source' => 'manifest',
            'plugin' => 'acme-a/acme-a.php',
            'capabilities' => ['flush'],
        ]],
        'tables' => [
            'acme_a_rooms' => [
                'class' => 'authored_snapshot',
                'id_kind' => 'acme_room',
                'pk' => 'room_id',
                'slug_column' => 'room_code',
                'columns' => ['room_code' => ['class' => 'authored']],
                'refs' => [],
                'identity' => ['mode' => 'natural_key', 'column' => 'room_code'],
            ],
        ],
    ], $overrides);
}

/**
 * Adapter B: the second plugin. Its POSITIVE form is the intended extension
 * path in full — it declares its own post type with its own body/phase/field
 * claims, its own provider, and a child table whose authored key is unique
 * only WITHIN its parent room, expressed as a parent-scoped natural key whose
 * first component is a ref into a table adapter A owns.
 */
function manifest_b(array $overrides = []): array {
    return array_merge([
        'name' => 'b',
        'spec_version' => DUO_SPEC_VERSION,
        'plugin' => 'acme-b/acme-b.php',
        'version_range' => ['min' => '1.0.0', 'max' => '2.0.0'],
        'option_namespaces' => [['match' => '^acme_b_']],
        'post_types' => [
            'acme_widget' => [
                'class' => 'authored',
                'body' => 'verbatim',
                'phase' => 'early',
                'fields' => ['title' => ['class' => 'derived']],
            ],
        ],
        'providers' => [[
            'id' => 'acme-b-cache',
            'version' => '1.0.0',
            'source' => 'manifest',
            'plugin' => 'acme-b/acme-b.php',
            'capabilities' => ['flush'],
        ]],
        'tables' => [
            'acme_b_slots' => [
                'class' => 'authored_snapshot',
                'id_kind' => 'acme_slot',
                'pk' => 'slot_id',
                'slug_column' => 'slot_code',
                'columns' => ['slot_code' => ['class' => 'authored']],
                'refs' => [['column' => 'room_id', 'kind' => 'acme_room']],
                'identity' => ['mode' => 'natural_key', 'columns' => ['room_id', 'slot_code']],
                'invalidate' => [['table' => 'acme_b_cache', 'column' => 'slot_id'], ['option_pattern' => 'acme_b_slot_{id}']],
            ],
        ],
    ], $overrides);
}
