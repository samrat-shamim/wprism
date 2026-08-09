<?php
/**
 * The two-adapter manifest fixture pair, shared by every offline suite that
 * needs a well-formed manifest to break in one specific way.
 *
 * Established by sandbox/tests/regress_vocabulary_ownership.php (DUO-3318) and
 * moved here unchanged when sandbox/tests/regress_manifest_validate.php
 * (DUO-3327) needed the identical shapes: the offline authoring aid and the
 * load-time validators must be exercised against ONE set of declarations, or
 * the two suites drift into disagreeing about what a valid manifest looks like
 * — which is the exact failure the shared-fixture requirement exists to stop.
 *
 * Deliberately NOT named regress_*: this file is a fixture source, not a suite,
 * and sandbox/tests/regress_bundle_coverage.sh's survey (rightly) expects every
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
