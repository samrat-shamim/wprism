<?php
declare(strict_types=1);

/**
 * Build the offline fixture environment the three `duo assess` / `duo
 * contract` suites drive (round-3 MUP §6.2).
 *
 * Usage: php make-fixture.php <dir> [--surfaces=<n>] [--pending=<n>]
 *
 * The fixture is a real site repository, a real `.duo-envs.json`-shaped
 * registry, and a fake `wp` on PATH — so the suites exercise `php cli/duo`
 * itself over a `local` transport, dispatch and preflight included, rather
 * than calling a command class directly. That matters here more than usual:
 * three of the things under test (the verb reaching the dispatcher, the
 * environment preflight accepting it, and the driver capability report
 * knowing the operation) live in `cli/duo` and its collaborators, and a
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
 * `$DUO_CALLS` for the composition-order assertion, and honours three
 * failure-injection switches:
 *
 *   DUO_DOCTOR_FAIL=1        `core is-installed` fails -> assess must refuse
 *   DUO_MULTISITE=1          the inventory reports multisite
 *   DUO_MUTATE_CONTRACT=<f>  copy <f> over the site's contract.json during
 *                            the capabilities call — a concurrent reviewer
 *                            landing a contract inside accept's own
 *                            read-modify-write window, which is the only
 *                            way to reach ContractStore's compare-and-swap
 *                            from the command line.
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

file_put_contents("$dir/repo/site.duo.json", json_encode([
    'code' => ['format' => 1, 'layout' => 'wp-content', 'source' => 'code/wp-content'],
    'manifests' => ['core'],
    'policy' => new stdClass(),
    'spec_version' => 2,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

// A Git worktree root: `duo contract accept` refuses to write review
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
    'format' => 'duo-assess-inventory/v1',
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
        'format' => 'duo-coverage/v1',
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

/**
 * One `CapabilityRegistry::report()['manifests'][]` row.
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
            'subject' => 'manifests.' . $name,
            'bundle_schema' => 'duo-subject-certification-bundle/v1',
            'bundle_digest' => str_repeat('c', 64),
            'git_revision' => str_repeat('d', 40),
            'status' => 'current',
            'tests' => ['conformance-' . $name],
        ],
        'platform' => ['compatibility' => [
            'wordpress' => ['last_verified' => '7.0.3'],
            'php' => ['min' => '8.3.0', 'max' => '8.4.0'],
            'database' => ['engine' => 'MariaDB', 'min' => '11.0.0', 'max' => '12.0.0'],
        ]],
        'evidence_scope' => 'subject_record',
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

$report = static function (string $operation) use ($claim, $coreSurfaces, $adapterSurfaces, $adapterUnsupported): array {
    $adapterReasons = [[
        'code' => 'plugin_version_mismatch',
        'message' => 'sample-adapter 10.4.2 is outside the certified range',
    ]];
    if ($operation === 'delete') {
        $adapterReasons[] = [
            'code' => 'operation_not_certified',
            'message' => "delete is not certified for 'sample-adapter'",
        ];
    }

    return [
        'schema_version' => 'duo-capability-report/v2',
        'registry_sha256' => str_repeat('e', 64),
        'platform' => new stdClass(),
        'evidence' => null,
        'query' => ['operation' => $operation, 'surface' => null, 'revision' => null],
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
    file_put_contents("$dir/fixtures/caps-$operation.json", json_encode($report($operation), JSON_UNESCAPED_SLASHES));
}

$fakeWp = <<<'SH'
#!/usr/bin/env bash
# The offline `wp` the assess suites put on PATH. It answers exactly the
# calls Doctor::run(), Init::proposal() and AssessCommand::assess() make,
# records each one for the composition-order assertion, and injects the
# failures the suites need.
set -u
printf '%s\n' "$*" >> "$DUO_CALLS"
op=""
for a in "$@"; do
  case "$a" in --operation=*) op="${a#--operation=}" ;; esac
done
case " $* " in
  *" core is-installed "*)
      if [ "${DUO_DOCTOR_FAIL:-0}" = 1 ]; then
        echo "This does not seem to be a WordPress installation." >&2
        exit 1
      fi
      exit 0 ;;
  *class_exists*) echo duo-ok; exit 0 ;;
  *DISALLOW_FILE_MODS*) echo duo-set; exit 0 ;;
  *db_server_info*) echo "8.3.33|11.8.8|mariadb|7.0.3"; exit 0 ;;
  *" duo assess-inventory "*)
      if [ "${DUO_MULTISITE:-0}" = 1 ]; then
        cat "$DUO_FIXTURES/inventory-multisite.json"
      else
        cat "$DUO_FIXTURES/inventory.json"
      fi
      exit 0 ;;
  *" duo capabilities "*)
      if [ -n "${DUO_MUTATE_CONTRACT:-}" ] && [ -f "$DUO_MUTATE_CONTRACT" ]; then
        # A concurrent reviewer landing a contract inside accept's own
        # read-modify-write window. Done once, then disarmed.
        cp "$DUO_MUTATE_CONTRACT" "$DUO_SITE_REPO/.duo/contract/contract.json"
        rm -f "$DUO_MUTATE_CONTRACT"
      fi
      cat "$DUO_FIXTURES/caps-$op.json"; exit 0 ;;
  *" duo init "*)
      printf '%s\n' '{"format":"duo-command-refusal/v1","ok":false,"command":"init","error":"repository_owned","reason_code":"repository_owned","message":"the repository is already owned by duo","remediation":"nothing to do"}'
      exit 1 ;;
esac
echo "fake wp: unhandled invocation: $*" >&2
exit 90
SH;
file_put_contents("$dir/bin/wp", $fakeWp . "\n");
chmod("$dir/bin/wp", 0700);

echo "fixture ready: $dir\n";
