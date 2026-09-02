<?php
/**
 * Offline regression for ActionProviderGrammar (issue #3348 slice 6: the
 * action/provider/effect declaration grammar extracted from Policy.php).
 *
 * The existing regress_actions_providers.php/regress_manifest_validate.php/
 * regress_vocabulary_ownership.php suites already exhaustively exercise this
 * grammar's actual refusal behavior through Policy::load() with real
 * manifests — that coverage is unchanged by this move and stays the primary
 * behavioral proof. This file is new characterization in the same spirit as
 * regress_manifest_grammar.php (issue #3348 slice 1): direct-API-call coverage
 * proving the moved methods work identically reached directly on the new
 * class, plus an exact byte-for-byte check that closed_vocabularies() and
 * grammar_patterns() publish the identical values they did before the move
 * — the strongest possible proof that nothing was silently dropped or
 * renamed crossing the file boundary.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../../agent/src/Kernel/OptionState.php';
require_once __DIR__ . '/../../../../agent/src/Policy/Policy.php';
require_once __DIR__ . '/../../../../agent/src/Adapter/ActionProviderGrammar.php';

use WPrism\ActionProviderGrammar;
use WPrism\Policy;

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    echo ($ok ? 'ok: ' : 'FAIL: ') . $message . "\n";
    if (!$ok) {
        $failures[] = $message;
    }
};

/** Runs $fn, asserts it threw, and that the message contains $needle. */
$assertThrows = static function (callable $fn, string $needle, string $label) use ($check): void {
    try {
        $fn();
        $check(false, "$label: expected a refusal, none thrown");
    } catch (\RuntimeException $e) {
        $check(
            str_contains($e->getMessage(), $needle),
            "$label: refusal names \"$needle\" (got: {$e->getMessage()})"
        );
    }
};

// ------------------------------------------------------------- validate_actions

$assertThrows(
    static fn() => ActionProviderGrammar::validate_actions(['name' => 'm', 'rebuilders' => []]),
    'retired free-form `rebuilders` channel',
    'actions: retired rebuilders channel'
);
$check(
    (static function (): bool {
        ActionProviderGrammar::validate_actions(['name' => 'm']);
        return true;
    })(),
    'actions: absent actions key is accepted (nothing to validate)'
);
$assertThrows(
    static fn() => ActionProviderGrammar::validate_actions(['name' => 'm', 'actions' => 'not-a-list']),
    'actions must be a list',
    'actions: non-list actions'
);
$assertThrows(
    static fn() => ActionProviderGrammar::validate_actions(['name' => 'm', 'actions' => [['kind' => 'bogus']]]),
    'kind must be "native" or "provider"',
    'actions: unknown kind'
);
$assertThrows(
    static fn() => ActionProviderGrammar::validate_actions([
        'name' => 'm',
        'actions' => [['kind' => 'native', 'action' => 'noop', 'args' => [], 'unexpected' => true]],
    ]),
    'contains unknown key(s): unexpected',
    'actions: unknown key'
);
$assertThrows(
    static fn() => ActionProviderGrammar::validate_actions([
        'name' => 'm',
        'actions' => [[
            'kind' => 'native', 'action' => 'transient.delete', 'args' => ['name' => 'x'],
            'triggers' => ['not-a-surface'],
        ]],
    ]),
    'must be one exact canonical surface',
    'actions: malformed trigger surface'
);
$assertThrows(
    static fn() => ActionProviderGrammar::validate_actions([
        'name' => 'm',
        'actions' => [[
            'kind' => 'native', 'action' => 'transient.delete', 'args' => ['name' => 'x'],
            'triggers' => ['post:x', 'post:x'],
        ]],
    ]),
    'repeats exact surface',
    'actions: repeated trigger surface'
);
$schemaEffect = [
    'id' => 'schema-a', 'kind' => 'database', 'mode' => 'restorable',
    'selector' => ['scope' => 'database_checkpoint', 'type' => 'table', 'value' => 'adapter_a'],
];
$schemaManifest = [
    'engine_features' => ['schema-settlement/v1'],
    'name' => 'm',
    'providers' => [[
        'id' => 'schema',
        'capabilities' => ['inspect_schema', 'prepare_schema'],
    ]],
    'tables' => ['adapter_a' => ['class' => 'derived']],
    'actions' => [[
        'args' => [], 'capability' => 'prepare_schema', 'effects' => [$schemaEffect],
        'kind' => 'provider', 'phase' => 'schema_settle', 'prepares' => ['adapter_a'],
        'provider' => 'schema', 'readiness' => 'inspect_schema',
    ]],
];
$check(
    (static function () use ($schemaManifest): bool {
        ActionProviderGrammar::validate_actions($schemaManifest);
        return true;
    })(),
    'actions: plugin-sourced schema settlement defers its executable contract to live negotiation'
);
$manifestOwnedSchema = $schemaManifest;
$manifestOwnedSchema['providers'][0]['contracts'] = [
    'inspect_schema' => [
        'args' => [], 'idempotent' => true, 'reads' => ['table:adapter_a'],
        'scope' => 'site', 'timeout_seconds' => 30, 'writes' => [],
    ],
    'prepare_schema' => [
        'args' => [], 'idempotent' => true, 'reads' => ['table:adapter_a'],
        'scope' => 'site', 'timeout_seconds' => 60, 'writes' => ['table:adapter_a'],
    ],
];
$check(
    (static function () use ($manifestOwnedSchema): bool {
        ActionProviderGrammar::validate_actions($manifestOwnedSchema);
        return true;
    })(),
    'actions: manifest-owned schema preparation binds its exact table contract offline'
);
$prepareContractMutations = [
    'argument schema' => static function (array $manifest): array {
        $manifest['providers'][0]['contracts']['prepare_schema']['args'] = ['mode' => 'string'];
        return $manifest;
    },
    'idempotence' => static function (array $manifest): array {
        $manifest['providers'][0]['contracts']['prepare_schema']['idempotent'] = false;
        return $manifest;
    },
    'scope' => static function (array $manifest): array {
        $manifest['providers'][0]['contracts']['prepare_schema']['scope'] = 'entity';
        return $manifest;
    },
    'read surface' => static function (array $manifest): array {
        $manifest['providers'][0]['contracts']['prepare_schema']['reads'] = [];
        return $manifest;
    },
    'write surface' => static function (array $manifest): array {
        $manifest['providers'][0]['contracts']['prepare_schema']['writes'] = [];
        return $manifest;
    },
];
foreach ($prepareContractMutations as $field => $mutate) {
    $assertThrows(
        static fn() => ActionProviderGrammar::validate_actions($mutate($manifestOwnedSchema)),
        'capability must be an idempotent argument-free site capability which reads and writes exactly prepares',
        "actions: manifest-owned schema preparation refuses a mutated $field offline"
    );
}
$assertThrows(
    static function () use ($schemaManifest): void {
        $invalid = $schemaManifest;
        unset($invalid['actions'][0]['prepares']);
        ActionProviderGrammar::validate_actions($invalid);
    },
    'prepares must be a non-empty sorted list',
    'actions: schema settlement cannot omit its exact table boundary'
);
$assertThrows(
    static function () use ($schemaManifest): void {
        $invalid = $schemaManifest;
        $invalid['actions'][0]['prepares'] = ['undeclared'];
        ActionProviderGrammar::validate_actions($invalid);
    },
    "names undeclared table 'undeclared'",
    'actions: schema settlement cannot prepare an undeclared table'
);
$assertThrows(
    static function () use ($schemaManifest): void {
        $invalid = $schemaManifest;
        $invalid['tables']['adapter_b'] = ['class' => 'derived'];
        $invalid['actions'][0]['prepares'] = ['adapter_b', 'adapter_a'];
        ActionProviderGrammar::validate_actions($invalid);
    },
    'must be sorted lexically',
    'actions: schema settlement table authority is canonical-order stable'
);
$assertThrows(
    static function () use ($schemaManifest): void {
        $invalid = $schemaManifest;
        $invalid['actions'][0]['effects'] = [];
        ActionProviderGrammar::validate_actions($invalid);
    },
    'schema_settle effects must exactly cover prepares tables',
    'actions: schema settlement cannot exceed its rollback effect witness'
);
$assertThrows(
    static function () use ($schemaManifest): void {
        $invalid = $schemaManifest;
        $invalid['actions'][0]['triggers'] = ['option:probe'];
        ActionProviderGrammar::validate_actions($invalid);
    },
    'selected by an exact compiled policy, not state triggers',
    'actions: schema settlement is a policy phase rather than a state trigger'
);

// ---------------------------------------------------------- validate_providers

$check(
    (static function (): bool {
        ActionProviderGrammar::validate_providers(['name' => 'm']);
        return true;
    })(),
    'providers: absent providers key is accepted'
);
$assertThrows(
    static fn() => ActionProviderGrammar::validate_providers(['name' => 'm', 'providers' => 'not-a-list']),
    'providers must be a list',
    'providers: non-list providers'
);
$assertThrows(
    static fn() => ActionProviderGrammar::validate_providers(['name' => 'm', 'providers' => [['id' => 'x']]]),
    'must declare exactly',
    'providers: missing required keys'
);
$assertThrows(
    static fn() => ActionProviderGrammar::validate_providers([
        'name' => 'm',
        'providers' => [[
            'id' => 'BAD ID', 'version' => '1.0.0', 'source' => 'manifest',
            'plugin' => 'x/x.php', 'capabilities' => ['a'],
        ]],
    ]),
    'id must match',
    'providers: malformed provider id'
);
$assertThrows(
    static fn() => ActionProviderGrammar::validate_providers([
        'name' => 'm',
        'providers' => [[
            'id' => 'x', 'version' => 'latest', 'source' => 'manifest',
            'plugin' => 'x/x.php', 'capabilities' => ['a'],
        ]],
    ]),
    'version must be an exact',
    'providers: non-exact version'
);
$assertThrows(
    static fn() => ActionProviderGrammar::validate_providers([
        'name' => 'm',
        'providers' => [[
            'id' => 'x', 'version' => '1.0.0', 'source' => 'nowhere',
            'plugin' => 'x/x.php', 'capabilities' => ['a'],
        ]],
    ]),
    'source must be "manifest" or "plugin"',
    'providers: unknown source'
);
$assertThrows(
    static fn() => ActionProviderGrammar::validate_providers([
        'name' => 'm',
        'providers' => [
            ['id' => 'dup', 'version' => '1.0.0', 'source' => 'manifest', 'plugin' => 'x/x.php', 'capabilities' => ['a']],
            ['id' => 'dup', 'version' => '1.0.0', 'source' => 'manifest', 'plugin' => 'x/x.php', 'capabilities' => ['a']],
        ],
    ]),
    'declares provider id \'dup\' more than once',
    'providers: duplicate provider id'
);

// ------------------------------------------------ validate_no_conflicting_provider_ids

$assertThrows(
    static fn() => ActionProviderGrammar::validate_no_conflicting_provider_ids([
        ['name' => 'a', 'providers' => [['id' => 'shared']]],
        ['name' => 'b', 'providers' => [['id' => 'shared']]],
    ]),
    "both declare provider id 'shared'",
    'no-conflicting-provider-ids: cross-manifest collision'
);
$check(
    (static function (): bool {
        ActionProviderGrammar::validate_no_conflicting_provider_ids([
            ['name' => 'a', 'providers' => [['id' => 'one']]],
            ['name' => 'b', 'providers' => [['id' => 'two']]],
        ]);
        return true;
    })(),
    'no-conflicting-provider-ids: distinct ids across manifests are accepted'
);

// -------------------------------------------------------- validate_effect_contracts

$check(
    (static function (): bool {
        ActionProviderGrammar::validate_effect_contracts(['name' => 'm']);
        return true;
    })(),
    'effect contracts: a manifest with no effect-bearing keys is accepted'
);
$assertThrows(
    static fn() => ActionProviderGrammar::validate_effect_contracts([
        'name' => 'm', 'lifecycle_effects' => 'not-a-list',
    ]),
    'must be a non-empty list',
    'effect contracts: non-list lifecycle_effects'
);
$validEffect = [
    'id' => 'e1', 'kind' => 'database', 'mode' => 'restorable',
    'selector' => ['scope' => 'database_checkpoint', 'type' => 'table', 'value' => 'wp_options'],
];
$check(
    (static function () use ($validEffect): bool {
        ActionProviderGrammar::validate_effect_contracts(['name' => 'm', 'lifecycle_effects' => [$validEffect]]);
        return true;
    })(),
    'effect contracts: a well-formed restorable database effect is accepted'
);
$check(
    (static function (): bool {
        ActionProviderGrammar::validate_effect_contracts(['name' => 'm', 'actions' => [[
            'kind' => 'provider', 'provider' => 'read-only', 'capability' => 'inspect',
            'args' => [], 'effects' => [],
        ]]]);
        return true;
    })(),
    'effect contracts: an explicit empty provider effect list is accepted as a negotiation-checked read-only claim'
);
$assertThrows(
    static fn() => ActionProviderGrammar::validate_effect_contracts(['name' => 'm', 'actions' => [[
        'kind' => 'native', 'action' => 'transient.delete', 'args' => ['name' => 'probe'], 'effects' => [],
    ]]]),
    'may be empty only for a provider action',
    'effect contracts: a native action may not erase its effect obligation with an empty list'
);
$assertThrows(
    static fn() => ActionProviderGrammar::validate_effect_contracts([
        'name' => 'm',
        'lifecycle_effects' => [
            ['id' => 'dup'] + $validEffect,
            ['id' => 'dup'] + $validEffect,
        ],
    ]),
    "repeats effect id 'dup'",
    'effect contracts: duplicate effect id'
);
$assertThrows(
    static fn() => ActionProviderGrammar::validate_effect_contracts([
        'name' => 'm',
        'lifecycle_effects' => [['id' => 'e1', 'kind' => 'bogus', 'mode' => 'restorable', 'selector' => $validEffect['selector']]],
    ]),
    'not one of the engine-owned effect kinds',
    'effect contracts: unknown effect kind'
);
$assertThrows(
    static fn() => ActionProviderGrammar::validate_effect_contracts([
        'name' => 'm',
        'lifecycle_effects' => [['id' => 'e1', 'kind' => 'database', 'mode' => 'bogus', 'selector' => $validEffect['selector']]],
    ]),
    'not one of the engine-owned reversibility',
    'effect contracts: unknown effect mode'
);
$assertThrows(
    static fn() => ActionProviderGrammar::validate_effect_contracts([
        'name' => 'm',
        'lifecycle_effects' => [[
            'id' => 'e1', 'kind' => 'database', 'mode' => 'restorable',
            'selector' => ['scope' => 'database_checkpoint', 'type' => 'table', 'value' => 'has secret in it'],
        ]],
    ]),
    'is secret-shaped',
    'effect contracts: secret-shaped selector value'
);
$assertThrows(
    static fn() => ActionProviderGrammar::validate_effect_contracts([
        'name' => 'm',
        'lifecycle_effects' => [[
            'id' => 'e1', 'kind' => 'external', 'mode' => 'reversible',
            'selector' => ['scope' => 'external', 'type' => 'hook', 'value' => 'some_hook'],
            'adapter' => ['id' => 'a', 'inverse' => 'b', 'inverse_inputs' => ['x'], 'verifier' => 'c', 'verifier_inputs' => ['y'], 'version' => 'not-a-version'],
        ]],
    ]),
    'version must be exact, never latest/wildcard/unbounded',
    'effect contracts: reversible adapter with non-exact version'
);

// -------------------------------------------------------------- provider requires

$assertThrows(
    static fn() => ActionProviderGrammar::validate_providers([
        'name' => 'm',
        'providers' => [[
            'id' => 'x', 'version' => '1.0.0', 'source' => 'manifest', 'plugin' => 'x/x.php',
            'capabilities' => ['a'], 'requires' => ['php_version' => ['min' => '8.0', 'max' => '7.0']],
        ]],
    ]),
    'has a malformed range',
    'provider requires: min >= max is refused (proves Policy::assert_min_max_range is reachable)'
);

// ----------------------------------------------- closed vocabularies stay byte-identical

$vocab = Policy::closed_vocabularies();
$check($vocab['action_kinds'] === ['native', 'provider'], 'closed_vocabularies(): action_kinds unchanged');
$check(
    $vocab['action_phases'] === ['lifecycle_settle', 'schema_settle'],
    'closed_vocabularies(): action_phases publishes the two provider-only phases'
);
$check($vocab['provider_sources'] === ['manifest', 'plugin'], 'closed_vocabularies(): provider_sources unchanged');
$check(
    $vocab['effect_kinds'] === ['database', 'filesystem', 'schedule', 'cache', 'queue', 'mail', 'http', 'external'],
    'closed_vocabularies(): effect_kinds unchanged'
);
$check(
    $vocab['effect_modes'] === ['restorable', 'reversible', 'prevented', 'irreversible'],
    'closed_vocabularies(): effect_modes unchanged'
);
$check(
    $vocab['effect_selector_scopes'] === ['database_checkpoint', 'external'],
    'closed_vocabularies(): effect_selector_scopes unchanged'
);
$check(
    $vocab['effect_selector_types'] === ['table', 'option', 'path', 'hook', 'namespace', 'queue', 'mail_subject', 'url_prefix', 'provider_resource', 'plugin_lifecycle'],
    'closed_vocabularies(): effect_selector_types unchanged'
);
$check(
    $vocab['provider_resource_placeholders'] === ['positive_uint', 'slug'],
    'closed_vocabularies(): provider_resource_placeholders unchanged'
);

$patterns = Policy::grammar_patterns();
$check($patterns['capability_name'] === '/^[a-z0-9_]{1,64}$/D', 'grammar_patterns(): capability_name unchanged');
$check($patterns['effect_id'] === '/^[a-z][a-z0-9._:-]{0,127}$/', 'grammar_patterns(): effect_id unchanged');
$check($patterns['provider_id'] === '/^[a-z][a-z0-9-]{0,63}$/D', 'grammar_patterns(): provider_id unchanged');
$check($patterns['provider_version'] === '/^[0-9]+\.[0-9]+\.[0-9]+$/D', 'grammar_patterns(): provider_version unchanged');

// --------------------------------------------------- structural: moved, not duplicated

$check(
    !(new ReflectionClass(Policy::class))->hasMethod('validate_actions'),
    'Policy.php no longer defines validate_actions() itself (moved to ActionProviderGrammar.php)'
);
$check(
    !(new ReflectionClass(Policy::class))->hasMethod('validate_providers'),
    'Policy.php no longer defines validate_providers() itself (moved to ActionProviderGrammar.php)'
);
$check(
    !(new ReflectionClass(Policy::class))->hasMethod('validate_effect_contracts'),
    'Policy.php no longer defines validate_effect_contracts() itself (moved to ActionProviderGrammar.php)'
);
$check(
    !(new ReflectionClass(Policy::class))->hasMethod('validate_no_conflicting_provider_ids'),
    'Policy.php no longer defines validate_no_conflicting_provider_ids() itself (moved to ActionProviderGrammar.php)'
);
$check(
    (new ReflectionClass(Policy::class))->hasMethod('assert_min_max_range')
        && (new ReflectionMethod(Policy::class, 'assert_min_max_range'))->isPublic(),
    'assert_min_max_range() stayed on Policy (genuinely shared by discovery-contract and adapter-contract validators too, not exclusive to this cluster) and is now public so ActionProviderGrammar can reach it'
);

if ($failures) {
    echo "\n" . count($failures) . " failure(s):\n";
    foreach ($failures as $f) {
        echo "  - $f\n";
    }
    exit(1);
}
echo "\nall ActionProviderGrammar checks passed\n";
exit(0);
