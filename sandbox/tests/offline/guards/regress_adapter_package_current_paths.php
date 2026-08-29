<?php
/**
 * Current contributor and authoring surfaces must describe the package layout.
 * Historical migration fixtures deliberately keep retired flat paths; this
 * closed list excludes them so archaeology cannot mask a stale instruction.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../lib/check.php';

$root = dirname(__DIR__, 4);
$currentSurfaces = [
    'AGENTS.md',
    'CONTRIBUTING.md',
    'README.md',
    'agent/wprism.php',
    'agent/src/Adapter/AdapterCertification.php',
    'cli/src/Adapter/AdapterBoundary.php',
    'cli/src/Assess/AssessReport.php',
    'cli/src/Command/AssessCommand.php',
    'cli/src/Contract/ContractAttestation.php',
    'cli/src/Onboarding/Doctor.php',
    'cli/wprism',
    'docs/README.md',
    'docs/adapter-grades.md',
    'docs/capabilities.md',
    'docs/compatibility-baseline.json',
    'docs/dev-setup.md',
    'docs/agents/adapter-production-readiness.md',
    'docs/guides/README.md',
    'docs/guides/adapter-authoring.md',
    'docs/guides/capabilities-and-limits.md',
    'docs/grind/adapter-walk.md',
    'sandbox/tests/lib/agent_version.php',
    'sandbox/tests/offline/adapter/regress_adapter_boundary_search.php',
    'tools/doctor.sh',
    'tools/reference-env-provider.php',
];
$retiredInstructions = [
    'manifests/capabilities/platform.json',
    'manifests/capabilities/adapter-authorities.json',
    'manifests/dispositions',
    'manifests/dispositions/',
    'sandbox/conformance/production-readiness.json',
    'sandbox/tests/certify/matrix.d/',
    'cp manifests/',
    'agent manifests recovery',
    'tools/capability-doc.php generate',
    'tools/adapter-grade.php generate',
    'BEGIN GENERATED CAPABILITY SUMMARY',
];

foreach ($currentSurfaces as $relative) {
    $path = $root . '/' . $relative;
    wprism_check(is_file($path), "$relative is a readable current authoring surface");
    $bytes = (string) file_get_contents($path);
    foreach ($retiredInstructions as $token) {
        wprism_check(!str_contains($bytes, $token), "$relative does not instruct authors to use retired '$token'");
    }
}

$retiredTopologyClaims = [
    'cli/wprism' => [
        "Bootstrap this checkout's agent + manifests",
    ],
    'docs/guides/quickstart.md' => [
        'agent, manifest library',
        'replaces the agent and manifest trees',
        'agent and manifest library',
    ],
    'spec/repo-format.md' => [
        'agent/loader/manifest/`.wprism` control plane',
        'fixed `agent`, `manifests`,',
        'agent/loader/manifests',
    ],
    'sandbox/tests/offline/policy/regress_spec_v3_dry_run.php' => [
        '`manifests/capabilities/platform.json` restates',
        '`manifests/*.json`',
        'The three trees are exactly what `Adopt.php',
    ],
    'sandbox/tests/offline/guards/spec_migration_estate.php' => [
        'agent/ or manifests/',
        "this state's agent under the OTHER state's",
        'the shipped `excluded` fixture (manifests/dispositions.json)',
    ],
    'sandbox/tests/offline/cli/regress_adapter_distribution.php' => [
        'under the shipped `manifests/`',
        'wrote one into `manifests/`',
        "'/manifests/capabilities/adapter-revocations.json'",
    ],
    'cli/src/Adapter/AdapterDistribution.php' => [
        'manifests/woocommerce.json',
    ],
    'sandbox/tests/offline/policy/regress_policy_load_scale.php' => [
        'manifests/classic-editor.json',
    ],
    'sandbox/tests/live/regress_env_set.sh' => [
        'SHIPPED manifests/core.json',
    ],
    'sandbox/tests/offline/adapter/regress_adapter_sources.php' => [
        'shipped manifests/dispositions/ bytes reassembled',
    ],
    'tests/Tooling/AdapterKitTest.php' => [
        "Adopt's tar is still `agent manifests",
    ],
];
foreach ($retiredTopologyClaims as $relative => $claims) {
    $path = $root . '/' . $relative;
    wprism_check(is_file($path), "$relative is a readable current topology surface");
    $bytes = (string) file_get_contents($path);
    foreach ($claims as $claim) {
        wprism_check(!str_contains($bytes, $claim), "$relative contains no retired current-topology claim '$claim'");
    }
}

$currentTopologyEvidence = [
    'cli/wprism' => [
        "Assemble this checkout's adapter library into its",
        'agent, then bootstrap that agent, recovery runtime',
    ],
    'docs/guides/quickstart.md' => [
        'agent with its embedded adapter library',
        'freshly assembled `agent recovery` archive',
    ],
    'spec/repo-format.md' => [
        '`adapter-packages/*/package/`',
        '`platform/adapter-library/`',
        'staged `agent/adapter-library/`',
        'exactly `agent recovery`',
    ],
    'sandbox/tests/offline/policy/regress_spec_v3_dry_run.php' => [
        'AdapterPackageProjection::plan($repo)',
        'archiving exactly `agent recovery`',
    ],
    'sandbox/tests/offline/guards/spec_migration_estate.php' => [
        'projected adapter library',
        'archives exactly `agent recovery`',
        '`adapter-packages/wprism-agency-cpt/package/disposition.json`',
    ],
    'sandbox/tests/offline/cli/regress_adapter_distribution.php' => [
        '`platform/adapter-library/` would dirty',
        "!is_file(\$wprismRoot . '/platform/adapter-library/capabilities/adapter-revocations.json')",
    ],
    'cli/src/Adapter/AdapterDistribution.php' => [
        '`adapter-packages/woocommerce/package/manifest.json`',
    ],
    'sandbox/tests/offline/policy/regress_policy_load_scale.php' => [
        '`adapter-packages/classic-editor/package/manifest.json`',
    ],
    'sandbox/tests/live/regress_env_set.sh' => [
        'SHIPPED platform/adapter-library/core/manifest.json',
    ],
    'sandbox/tests/offline/adapter/regress_adapter_sources.php' => [
        'shipped adapter-package and platform disposition/profile bytes',
    ],
    'tests/Tooling/AdapterKitTest.php' => [
        'embeds the adapter library',
        'archiving exactly `agent recovery`',
    ],
];
foreach ($currentTopologyEvidence as $relative => $claims) {
    $bytes = (string) file_get_contents($root . '/' . $relative);
    foreach ($claims as $claim) {
        wprism_check(str_contains($bytes, $claim), "$relative states current topology evidence '$claim'");
    }
}

$adoptSource = (string) file_get_contents($root . '/cli/src/Onboarding/Adopt.php');
foreach ([". '/adapter-packages'", ". '/platform/adapter-library'", 'agent/adapter-library'] as $claim) {
    wprism_check(str_contains($adoptSource, $claim), "Adopt.php names current assembly source or target '$claim'");
}
ob_start();
require_once $root . '/tools/adapter-kit.php';
ob_end_clean();
try {
    wprism_check_same(
        ['agent', 'recovery'],
        \WPrism\Tooling\AdapterKit::adoptionTar($root),
        'Adopt.php archive composition resolves to only the assembled agent and recovery runtime'
    );
} catch (Throwable $error) {
    wprism_check(false, 'Adopt.php archive composition resolves to only the assembled agent and recovery runtime');
    wprism_check_detail($error->getMessage());
}

$currentPathEvidence = [
    'agent/src/Adapter/AdapterCertification.php' => 'adapter-packages/<name>/package/disposition.json',
    'cli/src/Assess/AssessReport.php' => 'package-owned disposition documents',
    'cli/src/Command/AssessCommand.php' => 'adapter-packages/*/package/disposition.json',
    'cli/src/Contract/ContractAttestation.php' => 'platform/adapter-library/capabilities/platform.json',
    'cli/wprism' => 'adapter-packages/<name>/package/disposition.json',
];
foreach ($currentPathEvidence as $relative => $token) {
    $bytes = (string) file_get_contents($root . '/' . $relative);
    wprism_check(str_contains($bytes, $token), "$relative names current package-layout remediation '$token'");
}

$runtimePathEvidence = [
    'agent/src/Adapter/AdapterSources.php' => [
        'current' => [
            'platform/adapter-library/core/manifest.json',
            'platform/adapter-library/core/runtime/providers/',
            'platform/adapter-library/capabilities/platform.json',
        ],
        'retired_remediation' => [
            'declarations exactly as manifests/$name.json carries them',
            "the agent's own manifests/providers/ tree",
        ],
    ],
    'agent/src/Adapter/Providers.php' => [
        'current' => [
            'platform/adapter-library/core',
            'adapter-packages/{$failure->manifest()}/package',
        ],
        'retired_remediation' => [
            'repair manifests/providers/',
        ],
    ],
    'agent/src/Repository/SidebarState.php' => [
        'current' => [
            'platform/adapter-library/core/manifest.json',
        ],
        'retired_remediation' => [
            "see manifests/core.json's",
        ],
    ],
];
foreach ($runtimePathEvidence as $relative => $evidence) {
    $bytes = (string) file_get_contents($root . '/' . $relative);
    foreach ($evidence['current'] as $token) {
        wprism_check(str_contains($bytes, $token), "$relative names current runtime remediation '$token'");
    }
    foreach ($evidence['retired_remediation'] as $token) {
        wprism_check(!str_contains($bytes, $token), "$relative does not publish retired remediation '$token'");
    }
}

require_once $root . '/agent/src/Adapter/AdapterSources.php';
require_once $root . '/agent/src/Adapter/Providers.php';
$contractRefusal = static function (string $name): string {
    try {
        \WPrism\AdapterSources::assert_out_of_tree_contract(
            ['spec_version' => 2, 'interpreter' => 'changed'],
            $name,
            "adapters/$name.json",
            'site adapter',
            false,
            ['interpreter' => 'shipped', 'regenerators' => [], 'providers' => []]
        );
    } catch (Throwable $failure) {
        return $failure->getMessage();
    }
    return '';
};
$coreRefusal = $contractRefusal('core');
wprism_check(
    str_contains($coreRefusal, 'platform/adapter-library/core/manifest.json')
        && !str_contains($coreRefusal, 'adapter-packages/core/'),
    'a core override refusal points only to its platform-owned manifest'
);
$adapterRefusal = $contractRefusal('acf');
wprism_check(
    str_contains($adapterRefusal, 'adapter-packages/acf/package/manifest.json')
        && !str_contains($adapterRefusal, 'platform/adapter-library/core/'),
    'an adapter override refusal points only to its capsule-owned manifest'
);
$coreProvider = \WPrism\Providers::packaging_problem(new \WPrism\ProviderPackagingException(
    'core-probe',
    'core',
    'missing provider fixture'
));
wprism_check(
    str_contains((string) ($coreProvider['remediation'] ?? ''), 'platform/adapter-library/core/runtime/providers/')
        && !str_contains((string) ($coreProvider['remediation'] ?? ''), 'adapter-packages/core/'),
    'a core provider packaging refusal points only to its platform-owned runtime'
);
$adapterProvider = \WPrism\Providers::packaging_problem(new \WPrism\ProviderPackagingException(
    'acf-probe',
    'acf',
    'missing provider fixture'
));
wprism_check(
    str_contains((string) ($adapterProvider['remediation'] ?? ''), 'adapter-packages/acf/package/runtime/providers/')
        && !str_contains((string) ($adapterProvider['remediation'] ?? ''), 'platform/adapter-library/core/'),
    'an adapter provider packaging refusal points only to its capsule-owned runtime'
);

foreach (['capability-doc.php', 'adapter-grade.php'] as $tool) {
    $bytes = (string) file_get_contents($root . '/tools/' . $tool);
    wprism_check(str_contains($bytes, "=== 'render'"), "tools/$tool exposes an on-demand render command");
    wprism_check(!str_contains($bytes, 'file_put_contents('), "tools/$tool cannot rewrite a central adapter projection");
}

$spec = (string) file_get_contents($root . '/spec/repo-format.md');
wprism_check(
    !str_contains($spec, 'tools/adapter-executable-inventory.json'),
    'the normative spec derives runtime ownership instead of requiring a central executable inventory'
);

$releaseGate = (string) file_get_contents($root . '/Makefile');
wprism_check(
    str_contains($releaseGate, "\tphp tools/capability-doc.php --check\n")
        && str_contains($releaseGate, "\tphp tools/adapter-grade.php --check\n"),
    'release-gate validates capability and grade sources without stored aggregate projections'
);
wprism_check(
    preg_match('/^\t[^\n]*adapter-packages\/[a-z0-9-]+\/tests\//m', $releaseGate) !== 1,
    'Makefile recipes contain no literal adapter-package test path'
);
wprism_check(
    str_contains(
        $releaseGate,
        'regress-%: export WPRISM_ADAPTER_PACKAGE_MAKE_TARGET = ' . '$@' . "\n"
    )
        && str_contains($releaseGate, "regress-%: adapter-package-make-force\n")
        && str_contains(
            $releaseGate,
            "\tphp tools/adapter-package-make-target.php --target-from-make\n"
        )
        && !str_contains($releaseGate, '--target=' . '$@'),
    'package suite targets use checked discovery without interpolating a make goal into shell syntax'
);

foreach ([
    'sandbox/tests/certify/certify_ssh_adoption_roundtrip.sh',
    'sandbox/tests/certify/certify_ssh_rollback.sh',
] as $relative) {
    $bytes = (string) file_get_contents($root . '/' . $relative);
    wprism_check(
        str_contains($bytes, 'platform/adapter-library/capabilities/platform.json'),
        "$relative reads the package-layout platform boundary"
    );
}

wprism_check_summary('regress-adapter-package-current-paths');
