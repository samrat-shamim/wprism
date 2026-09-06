<?php
declare(strict_types=1);

/**
 * Build the offline fixture environment the three `wprism assess` / `wprism
 * contract` suites drive (round-3 MUP §6.2).
 *
 * Usage: php make-fixture.php <dir> [--surfaces=<n>] [--pending=<n>]
 *
 * The fixture is a real site repository, a real `.wprism-envs.json`-shaped
 * registry, and a fake `wp` on PATH — so the suites exercise `php cli/wprism`
 * itself over a `local` transport, dispatch and preflight included, rather
 * than calling a command class directly. That matters here more than usual:
 * three of the things under test (the verb reaching the dispatcher, the
 * environment preflight accepting it, and the driver capability report
 * knowing the operation) live in `cli/wprism` and its collaborators, and a
 * suite that constructed the command object by hand would pass with all
 * three broken.
 *
 * **Every name in this fixture is invented.** The engine-adapter boundary
 * forbids a plugin slug in `cli/src/Assess`, `cli/src/Contract` and
 * `agent/src/Assess`; a fixture that used a real ecosystem's names would
 * make the grep gate that proves it unreadable, and would tempt the next
 * author to "just match the fixture" in production code.
 *
 * The fake `wp` answers exactly the calls `Doctor::run()`, `Init::proposal()`
 * and `AssessCommand::assess()` make, records every invocation to
 * `$WPRISM_CALLS` for the composition-order assertion, and honours these
 * failure-injection switches:
 *
 *   WPRISM_DOCTOR_FAIL=1        `core is-installed` fails -> assess must refuse
 *   WPRISM_MULTISITE=1          the inventory reports multisite
 *   WPRISM_LIBRARY_SKEW=1       the target answers from a DIFFERENT reviewed
 *                            library than this checkout ships — the
 *                            mid-upgrade window of docs/adoption.md, where an
 *                            operator has pulled a revision that edited an
 *                            adapter disposition and has not
 *                            re-adopted the site yet (issue #3484)
 *   WPRISM_MUTATE_CONTRACT=<f>  copy <f> over the site's contract.json during
 *                            the capabilities call — a concurrent reviewer
 *                            landing a contract inside accept's own
 *                            read-modify-write window, which is the only
 *                            way to reach ContractStore's compare-and-swap
 *                            from the command line.
 *   WPRISM_CAPS_AFTER=<name>    from the Nth `wprism capabilities` call onward,
 *                            answer with `caps-<op>.<name>.json` instead —
 *                            the target as it is AFTER the operator started
 *                            reading the authorization page. The window the
 *                            mutation gate exists to close; the variants and
 *                            what each one models are documented beside the
 *                            files they generate.
 *   WPRISM_CAPS_AFTER_CALL=<n>  the 1-based call index WPRISM_CAPS_AFTER starts at
 *                            (default 2: call 1 is the freeze-time read,
 *                            call 2 is the gate's own re-probe)
 */

// register_argc_argv is on for the CLI SAPI, but static analysis cannot
// know that from the file alone; read the superglobal explicitly so the
// contract of this script is visible rather than assumed.
/** @var list<string> $argvList */
$argvList = $_SERVER['argv'] ?? [];
array_shift($argvList);
$dir = null;
$surfaces = 0;
$pending = 3;
foreach ($argvList as $arg) {
    if (str_starts_with($arg, '--surfaces=')) {
        $surfaces = (int) substr($arg, strlen('--surfaces='));
        continue;
    }
    if (str_starts_with($arg, '--pending=')) {
        $pending = (int) substr($arg, strlen('--pending='));
        continue;
    }
    if ($dir === null) {
        $dir = $arg;
        continue;
    }
    fwrite(STDERR, "make-fixture: unexpected argument '$arg'\n");
    exit(2);
}
if ($dir === null || $dir === '') {
    fwrite(STDERR, "usage: make-fixture.php <dir> [--surfaces=<n>] [--pending=<n>]\n");
    exit(2);
}
foreach (['repo', 'wordpress', 'bin', 'fixtures'] as $child) {
    if (!is_dir("$dir/$child") && !mkdir("$dir/$child", 0700, true) && !is_dir("$dir/$child")) {
        fwrite(STDERR, "make-fixture: could not create $dir/$child\n");
        exit(2);
    }
}

file_put_contents("$dir/repo/site.wprism.json", json_encode([
    'code' => ['format' => 1, 'layout' => 'wp-content', 'source' => 'code/wp-content'],
    'manifests' => ['core'],
    'policy' => new stdClass(),
    'spec_version' => 2,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

// A Git worktree root: `wprism contract accept` refuses to write review
// artifacts anywhere else, because they exist to be committed.
exec('git -C ' . escapeshellarg("$dir/repo") . ' init -q 2>/dev/null');
exec('git -C ' . escapeshellarg("$dir/repo") . ' config user.email fixture@example.invalid 2>/dev/null');
exec('git -C ' . escapeshellarg("$dir/repo") . ' config user.name fixture 2>/dev/null');

file_put_contents("$dir/envs.json", json_encode([
    'envs' => [
        'fixture' => [
            'transport' => 'local',
            'wp_path' => realpath("$dir/wordpress") ?: "$dir/wordpress",
            'repo_path' => realpath("$dir/repo") ?: "$dir/repo",
        ],
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

/** One policy surface group. */
$group = static fn (string $id, string $kind, ?string $class, string $by, ?int $count): array => [
    'id' => $id, 'kind' => $kind, 'class' => $class, 'declared_by' => $by, 'count' => $count,
];

$surfaceGroups = [
    $group('post_type:page', 'post_type', 'authored', 'core', 4),
    $group('post_type:widget_item', 'post_type', 'authored', 'sample-adapter', 3),
    $group('taxonomy:category', 'taxonomy', 'authored', 'core', 1),
    $group('table:sample_ledger', 'table', 'runtime', 'sample-adapter', 7),
    $group('table:sample_lookup', 'table', 'derived', 'sample-adapter', 9),
    $group('option_group:core:managed', 'option_group', 'managed', 'core', null),
    $group('option_group:sample-adapter:env', 'option_group', 'env', 'sample-adapter', null),
    $group('media:attachment', 'media', 'authored', 'core', 2),
];
// Padding groups exist only to push the human renderer past its row bound.
// They are ordinary authored post types, so they add rows without adding a
// second kind of row the bounds suite would then also have to reason about.
for ($i = 0; $i < $surfaces; $i++) {
    $surfaceGroups[] = $group(
        sprintf('post_type:bulk_%03d', $i),
        'post_type',
        'authored',
        'core',
        1
    );
}

$pendingRows = [];
for ($i = 0; $i < $pending; $i++) {
    $pendingRows[] = [
        'section' => 'options',
        'key' => sprintf('sample_queued_%03d', $i),
        'proposal' => null,
        'evidence' => new stdClass(),
    ];
}

$inventory = [
    'format' => 'wprism-assess-inventory/v1',
    'spec_version' => 2,
    'agent_version' => '0.5.0',
    'target' => [
        'wordpress' => '7.0.3',
        'php' => '8.3.33',
        'database' => ['engine' => 'MariaDB', 'version' => '11.8.8'],
        'site_mode' => 'single-site',
        'home' => 'https://fixture.example.test',
        'siteurl' => 'https://fixture.example.test',
    ],
    'plugins' => [
        ['basename' => 'sample-adapter/sample-adapter.php', 'name' => 'Sample Adapter',
            'version' => '10.4.2', 'active' => true],
        ['basename' => 'inactive-thing.php', 'name' => 'Inactive Thing', 'version' => '1.0', 'active' => false],
        ['basename' => 'unmanaged-widget/unmanaged-widget.php', 'name' => 'Unmanaged Widget',
            'version' => '3.1.0', 'active' => true],
    ],
    // T6 §3.6. An ACTIVE plugin no pinned manifest declares — the single
    // largest thing WPrism cannot version on a real site, and the thing assess
    // said nothing at all about before this. The agent publishes all three
    // identity parts (AssessInventory::plugins_without_adapter()), so the
    // host splits nothing.
    'plugins_without_adapter' => [
        ['basename' => 'unmanaged-widget/unmanaged-widget.php', 'file' => 'unmanaged-widget.php',
            'slug' => 'unmanaged-widget'],
    ],
    'themes' => [['stylesheet' => 'sample-theme', 'name' => 'Sample Theme', 'version' => '2.0.1', 'active' => true]],
    'media' => ['count' => 2, 'bytes' => null],
    'policy' => [
        'manifests' => [
            ['name' => 'core', 'source' => 'shipped', 'adapter_digest' => str_repeat('a', 64),
                'status' => 'certified'],
            ['name' => 'sample-adapter', 'source' => 'shipped', 'adapter_digest' => str_repeat('b', 64),
                'status' => 'certified'],
        ],
        'surface_groups' => $surfaceGroups,
    ],
    'coverage' => [
        'format' => 'wprism-coverage/v1',
        'options' => [
            'total' => 100, 'captured' => 20, 'pending' => 2,
            'invisible_total' => 41, 'invisible_transient' => 0, 'invisible_other' => 41,
            'invisible_groups' => [['prefix' => 'sample_', 'count' => 41, 'probable_owner' => null]],
        ],
        'tables' => [
            'live_total' => 20, 'core_total' => 12, 'declared_total' => 2, 'undeclared_total' => 1,
            'undeclared' => [
                ['table' => 'wp_sample_log', 'logical_name' => 'sample_log',
                    'row_count' => 5, 'probable_owner' => null],
            ],
        ],
    ],
    'pending' => ['count' => $pending, 'rows' => $pendingRows, 'truncated' => false],
    'adapter_survey' => ['adapters' => [], 'not_installed' => [], 'refusals' => [], 'sources' => []],
];
file_put_contents("$dir/fixtures/inventory.json", json_encode($inventory, JSON_UNESCAPED_SLASHES));
// The unsupported-topology variant. Written as a second document rather than
// switched at generation time so one fixture tree can drive both runs: the
// switch belongs at `wp` invocation, which is where a real site's topology
// would differ between two commands.
$multisite = $inventory;
$multisite['target']['site_mode'] = 'multisite';
file_put_contents("$dir/fixtures/inventory-multisite.json", json_encode($multisite, JSON_UNESCAPED_SLASHES));
// The adoption-seed variant (T7 grind A3): the agent projected the inventory
// against the init proposal and says so in an `adoption` block. Selected at
// `wp` invocation like the multisite variant (WPRISM_ADOPTION_SEED=1).
$seed = $inventory;
$seed['adoption'] = [
    'mode' => 'seed',
    'preview' => 'init-proposal',
    'reason' => 'the repository is an adoption seed; surfaces are projected against the policy wprism init would propose',
    'adapters' => ['core', 'fixture-shop'],
    'scope' => [
        'post_types' => ['attachment', 'page', 'post', 'fixture_item'],
        'taxonomies' => ['category', 'post_tag'],
        'left_local' => ['post_type:fixture_log'],
    ],
    'advisories' => [[
        'code' => 'unmanaged_scope_left_local', 'extension' => 'post_type:fixture_log', 'kind' => 'scope',
        'reason' => 'registered by no selected adapter and holding 3 row(s); left local (class runtime) until an adapter declares it or wprism classify decides it',
        'remediation' => 'to manage it later, run wprism classify and decide scope:post_type:fixture_log, or install an adapter that declares it',
    ]],
    'unsupported' => [],
    'ready' => true,
];
file_put_contents("$dir/fixtures/inventory-adoption-seed.json", json_encode($seed, JSON_UNESCAPED_SLASHES));

/**
 * One `AdapterRegistry::report()['manifests'][]` row.
 *
 * Faithful to what the agent emits, key for key. That matters more here than
 * convenience: this fixture is the only capability report the assess suites
 * ever see, so a row shaped like the OLD generated registry would let them pass
 * against a document no agent produces. `evidence` is the authored citation
 * verbatim — a bundle schema and named tests, with no digest, no git revision
 * and no status word — and `evidence_scope` is `authored_disposition` because
 * the claim is projected from `manifests/dispositions.json` rather than read
 * out of a generated record. There is no `adapter_digest` on a row for the same
 * reason: nothing generates one.
 *
 * @param list<string> $claimSurfaces
 * @param list<array<string,mixed>> $unsupported
 * @param list<array<string,mixed>> $reasons
 * @param list<string> $operations
 */
$claim = static function (
    string $name,
    array $claimSurfaces,
    array $unsupported,
    array $reasons,
    array $operations
): array {
    return [
        'name' => $name,
        'status' => 'certified',
        'reason' => '',
        'plugin_execution' => ['mode' => 'unmodified', 'status' => 'verified'],
        'authored_state' => ['status' => 'certified', 'scope' => 'only the exact registered surfaces below'],
        'supported_versions' => $name === 'core'
            ? new stdClass()
            : ['plugin' => 'sample-adapter', 'range' => ['min' => '10.0.0', 'max' => '11.0.0']],
        'environment_assumptions' => new stdClass(),
        'operations' => $operations,
        'surfaces' => $claimSurfaces,
        'lifecycle_phases' => [],
        'deletion_semantics' => new stdClass(),
        'unsupported' => $unsupported,
        'evidence' => [
            'bundle_schema' => 'wprism-subject-certification-bundle/v1',
            'tests' => ['conformance-' . $name],
        ],
        'platform' => ['compatibility' => [
            'wordpress' => ['last_verified' => '7.0.3'],
            'php' => ['min' => '8.3.0', 'max' => '8.4.0'],
            // The shipped shape: one range per claimed engine. A fixture left
            // on the retired `engine` scalar would render NO database
            // dependency at all through SurfaceCatalog::expiry(), which is
            // the silent narrowing that shape change had to be checked for.
            'database' => ['engines' => [
                'MariaDB' => ['min' => '11.0.0', 'max' => '12.0.0'],
                'MySQL' => ['min' => '8.4.0', 'max' => '8.5.0'],
            ]],
        ]],
        'evidence_scope' => 'authored_disposition',
        'source' => [
            'source' => 'shipped', 'certification' => 'registry',
            'trust_tier' => 'declarative_manifest', 'path' => null, 'remediation' => '',
        ],
        'verdict' => ['status' => 'certified', 'reasons' => $reasons],
    ];
};

// core governs `tables` and `taxonomies` by name and says nothing about
// post_types, which is why every core post type in this fixture reads Ready
// rather than `surface_not_registered`.
$coreSurfaces = ['options', 'menu_fields', 'widgets', 'tables', 'tables.commentmeta',
    'taxonomies', 'taxonomies.category'];
$adapterSurfaces = ['options', 'post_types', 'post_types.widget_item', 'tables',
    'tables.sample_ledger', 'tables.sample_lookup'];
$adapterUnsupported = [
    ['surface' => 'tables.sample_lookup', 'operation' => 'apply',
        'reason' => 'no bounded independent value oracle exists for this table'],
    ['surface' => 'post_types.widget_item', 'operation' => 'delete',
        'reason' => 'the open extension graph is not enumerable; deletion refuses before repository mutation'],
];

/**
 * The content address of the reviewed dispositions THIS CHECKOUT ships,
 * computed exactly as both producers compute it — sha256 over
 * `Canon::encode()` of the decoded document, which is
 * `ManifestDispositions::sha256()` on the target and
 * `AssessCommand::registryProvenance()` on the host.
 *
 * A real target reports the hash of the library it was adopted with, so an
 * agreeing fixture has to carry the real number rather than a memorable one:
 * `wprism assess` reads the host half from the live source adapter library and
 * there is no flag that redirects it. Before issue #3484 this fixture
 * reported a hand-written `eeee…` and the suites still passed, which is the
 * defect: nothing compared the two numbers.
 */
$root = dirname(__DIR__, 4);
require_once $root . '/agent/src/Kernel/Canon.php';
require_once $root . '/agent/src/Policy/AdapterLibrary.php';
// Reassembled exactly as ManifestDispositions::data() does — one document per
// package plus the platform-owned profiles map — so `registry_sha256` here is
// still the number a running agent computes.
$library = \WPrism\AdapterLibrary::fromSourceTree($root);
$dispositions = ['format' => 'wprism-manifest-dispositions/v1', 'manifests' => [], 'profiles' => []];
foreach ($library->packages() as $package) {
    $document = $package->dispositionPath();
    $decoded = json_decode((string) file_get_contents($document), true);
    if (!is_array($decoded)) {
        fwrite(STDERR, "make-fixture: $document is unreadable\n");
        exit(2);
    }
    $dispositions['manifests'][$package->name()] = $decoded;
}
if ($dispositions['manifests'] === []) {
    fwrite(STDERR, "make-fixture: adapter package dispositions are unreadable\n");
    exit(2);
}
$profiles = json_decode((string) file_get_contents($library->profilesPath()), true);
if (!is_array($profiles)) {
    fwrite(STDERR, "make-fixture: adapter disposition profiles are unreadable\n");
    exit(2);
}
$dispositions['profiles'] = $profiles;
ksort($dispositions['manifests'], SORT_STRING);
$hostRegistrySha = hash('sha256', \WPrism\Canon::encode($dispositions));
// The skewed library: a different content address, and nothing else. What
// makes the mid-upgrade window legitimate is precisely that the target is
// answering correctly — from an older reviewed library — so its verdicts stay
// identical here and the hash is the only thing that moves.
$skewRegistrySha = hash('sha256', 'older-reviewed-library an older reviewed library');

$report = static function (string $operation, string $registrySha) use (
    $claim,
    $coreSurfaces,
    $adapterSurfaces,
    $adapterUnsupported
): array {
    // `check`/`observed`/`subject` ride beside `message`, exactly as
    // AdapterRegistry::reason() emits them (agent/src/Adapter/AdapterRegistry
    // .php's target_reasons()). They are what SurfaceCatalog::conditionRows()
    // mints a machine-checkable condition row from, and therefore what the
    // mutation gate re-observes; a fixture carrying prose only would let the
    // gate suite pass against a document no agent produces. `message` bytes
    // are unchanged, so no assess output moves.
    $adapterReasons = [[
        'check' => 'sample-adapter in 10.0.0-11.0.0',
        'code' => 'plugin_version_mismatch',
        'message' => 'sample-adapter 10.4.2 is outside the certified range',
        'observed' => '10.4.2',
        'subject' => 'sample-adapter',
    ]];
    if ($operation === 'delete') {
        $adapterReasons[] = [
            'code' => 'operation_not_certified',
            'message' => "delete is not certified for 'sample-adapter'",
        ];
    }

    return [
        'schema_version' => 'wprism-capability-report/v1',
        'registry_sha256' => $registrySha,
        'platform' => new stdClass(),
        'evidence' => null,
        // No `revision`: the query used to select a git revision to evaluate a
        // generated evidence record against, and there is no such record.
        'query' => ['operation' => $operation, 'surface' => null],
        'target' => ['wordpress' => '7.0.3', 'php' => '8.3.33'],
        'ready' => true,
        'blockers' => [],
        'manifests' => [
            $claim('core', $coreSurfaces, [], [], [
                'apply', 'capture', 'compile', 'delete', 'deploy', 'plan', 'promote', 'recapture',
            ]),
            $claim('sample-adapter', $adapterSurfaces, $adapterUnsupported, $adapterReasons, [
                'apply', 'capture', 'compile', 'deploy', 'plan', 'promote', 'recapture',
            ]),
        ],
        'profiles' => [],
        'evidence_scope' => 'per_subject',
    ];
};
foreach (['capture', 'plan', 'promote', 'delete'] as $operation) {
    file_put_contents(
        "$dir/fixtures/caps-$operation.json",
        json_encode($report($operation, $hostRegistrySha), JSON_UNESCAPED_SLASHES)
    );
    file_put_contents(
        "$dir/fixtures/caps-$operation.skew.json",
        json_encode($report($operation, $skewRegistrySha), JSON_UNESCAPED_SLASHES)
    );
}

/**
 * The target as it is AFTER the operator started reading the authorization
 * page — the confirmation window the mutation gate exists to close.
 *
 * Selected by `WPRISM_CAPS_AFTER` from the Nth `wprism capabilities` call onward
 * (`WPRISM_CAPS_AFTER_CALL`, default 2), mirroring the release fixture's own
 * `WPRISM_PLAN_AFTER` counter. `promote` only, because that is the one registry
 * operation `wprism release` reads (`SurfaceCatalog::REGISTRY_OPERATION`).
 *
 * Each variant is one drift shape the gate must name, and each one RELEASES
 * against a build whose gate re-probes only plan/HEAD/artifact:
 *
 *   moved     the plugin was downgraded: same condition, different `observed`
 *   inactive  the plugin was deactivated: a `plugin_not_active` row APPEARS
 *   withdrawn the plugin was upgraded into its window: the row disappears
 *   gone      the manifest is absent from the report: nothing to re-observe
 *   blind     the reason carries no machine facts: an uncheckable condition
 *   skew      handled by caps-promote.skew.json, whose registry_sha256 moved
 */
$promote = $report('promote', $hostRegistrySha);
$sampleIndex = null;
foreach ($promote['manifests'] as $index => $manifest) {
    if (($manifest['name'] ?? null) === 'sample-adapter') {
        $sampleIndex = $index;
    }
}
if ($sampleIndex === null) {
    fwrite(STDERR, "make-fixture: the promote report names no sample-adapter claim to drift\n");
    exit(2);
}

$moved = $promote;
$moved['manifests'][$sampleIndex]['verdict']['reasons'][0]['observed'] = '9.9.0';
$moved['manifests'][$sampleIndex]['verdict']['reasons'][0]['message'] =
    'sample-adapter 9.9.0 is outside the certified range';

$inactive = $promote;
$inactive['manifests'][$sampleIndex]['verdict']['reasons'][] = [
    'check' => 'sample-adapter active',
    'code' => 'plugin_not_active',
    'message' => 'sample-adapter is not active on the evaluated target',
    'observed' => 'inactive',
    'subject' => 'sample-adapter',
];

$withdrawn = $promote;
$withdrawn['manifests'][$sampleIndex]['verdict']['reasons'] = [];

$gone = $promote;
$gone['manifests'] = array_values(array_filter(
    $gone['manifests'],
    static fn (array $manifest): bool => ($manifest['name'] ?? null) !== 'sample-adapter'
));

$blind = $promote;
foreach (['check', 'observed', 'subject'] as $fact) {
    unset($blind['manifests'][$sampleIndex]['verdict']['reasons'][0][$fact]);
}

foreach (compact('moved', 'inactive', 'withdrawn', 'gone', 'blind') as $name => $variant) {
    file_put_contents(
        "$dir/fixtures/caps-promote.$name.json",
        json_encode($variant, JSON_UNESCAPED_SLASHES)
    );
}

$fakeWp = <<<'SH'
#!/usr/bin/env bash
# The offline `wp` the assess suites put on PATH. It answers exactly the
# calls Doctor::run(), Init::proposal() and AssessCommand::assess() make,
# records each one for the composition-order assertion, and injects the
# failures the suites need.
set -u
printf '%s\n' "$*" >> "$WPRISM_CALLS"
op=""
for a in "$@"; do
  case "$a" in --operation=*) op="${a#--operation=}" ;; esac
done
case " $* " in
  *" core is-installed "*)
      if [ "${WPRISM_DOCTOR_FAIL:-0}" = 1 ]; then
        echo "This does not seem to be a WordPress installation." >&2
        exit 1
      fi
      exit 0 ;;
  *" eval echo is_multisite() "*)
      printf '%s\n' 'single-site'
      exit 0 ;;
  *class_exists*DISALLOW_FILE_MODS*db_server_info*)
      # issue #3511: Doctor::run() asks for agent presence, DISALLOW_FILE_MODS and
      # the PHP/database/WordPress facts in ONE eval, so this answers with the
      # one JSON object it decodes. This case is FIRST-MATCH-WINS against the
      # three narrower patterns it replaced -- a *class_exists* case would have
      # matched the composed snippet too and answered "wprism-ok", which Doctor
      # cannot decode, so there is deliberately no such case left to shadow it.
      # site_mode joins the same composed payload and honours WPRISM_MULTISITE,
      # so a fixture that tells assess-inventory it is a network does not tell
      # doctor it is a single site.
      if [ "${WPRISM_MULTISITE:-0}" = 1 ]; then
        printf '%s\n' '{"agent":"wprism-ok","file_mods":"wprism-set","php":"8.3.33","db_version":"11.8.8","db_engine":"mariadb","wp":"7.0.3","site_mode":"multisite","filesystem":{"directory_separator":"/","os_family":"Linux","functions":{"chmod":true,"flock":true,"fsync":true,"lstat":true,"rename":true}},"process":{"os_family":"Linux","functions":{"passthru":true,"posix_kill":true,"posix_setsid":true,"proc_close":true,"proc_get_status":true,"proc_open":true,"proc_terminate":true},"shell":{"executable":true,"path":"/bin/sh"},"wp_cli_opcache_enabled":false}}'
      else
        printf '%s\n' '{"agent":"wprism-ok","file_mods":"wprism-set","php":"8.3.33","db_version":"11.8.8","db_engine":"mariadb","wp":"7.0.3","site_mode":"single-site","filesystem":{"directory_separator":"/","os_family":"Linux","functions":{"chmod":true,"flock":true,"fsync":true,"lstat":true,"rename":true}},"process":{"os_family":"Linux","functions":{"passthru":true,"posix_kill":true,"posix_setsid":true,"proc_close":true,"proc_get_status":true,"proc_open":true,"proc_terminate":true},"shell":{"executable":true,"path":"/bin/sh"},"wp_cli_opcache_enabled":false}}'
      fi
      exit 0 ;;
  *" wprism assess-inventory "*)
      if [ "${WPRISM_MULTISITE:-0}" = 1 ]; then
        cat "$WPRISM_FIXTURES/inventory-multisite.json"
      elif [ "${WPRISM_ADOPTION_SEED:-0}" = 1 ]; then
        cat "$WPRISM_FIXTURES/inventory-adoption-seed.json"
      else
        cat "$WPRISM_FIXTURES/inventory.json"
      fi
      exit 0 ;;
  *" wprism capabilities "*)
      if [ -n "${WPRISM_MUTATE_CONTRACT:-}" ] && [ -f "$WPRISM_MUTATE_CONTRACT" ]; then
        # A concurrent reviewer landing a contract inside accept's own
        # read-modify-write window. Done once, then disarmed.
        cp "$WPRISM_MUTATE_CONTRACT" "$WPRISM_SITE_REPO/.wprism/contract/contract.json"
        rm -f "$WPRISM_MUTATE_CONTRACT"
      fi
      # WPRISM mutation gate: from the Nth call onward, answer with the target
      # as it is AFTER the operator started reading the plan. Inert unless
      # WPRISM_CAPS_AFTER names a variant that exists for this operation, so
      # every suite that does not set it sees exactly the base report.
      caps="caps-$op"
      if [ -n "${WPRISM_CAPS_AFTER:-}" ]; then
        seen=$(cat "$WPRISM_FIXTURES/caps-calls" 2>/dev/null || echo 0)
        seen=$((seen + 1))
        printf '%s' "$seen" > "$WPRISM_FIXTURES/caps-calls"
        if [ "$seen" -ge "${WPRISM_CAPS_AFTER_CALL:-2}" ] \
          && [ -f "$WPRISM_FIXTURES/caps-$op.${WPRISM_CAPS_AFTER}.json" ]; then
          caps="caps-$op.${WPRISM_CAPS_AFTER}"
        fi
      fi
      if [ "${WPRISM_LIBRARY_SKEW:-0}" = 1 ]; then
        cat "$WPRISM_FIXTURES/caps-$op.skew.json"
      else
        cat "$WPRISM_FIXTURES/$caps.json"
      fi
      exit 0 ;;
  *" wprism init "*)
      printf '%s\n' '{"format":"wprism-command-refusal/v1","ok":false,"command":"init","error":"repository_owned","reason_code":"repository_owned","message":"the repository is already owned by wprism","remediation":"nothing to do"}'
      exit 1 ;;
esac
echo "fake wp: unhandled invocation: $*" >&2
exit 90
SH;
file_put_contents("$dir/bin/wp", $fakeWp . "\n");
chmod("$dir/bin/wp", 0700);

echo "fixture ready: $dir\n";
