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
    'agent/duo.php',
    'agent/src/Adapter/AdapterCertification.php',
    'cli/src/Adapter/AdapterBoundary.php',
    'cli/src/Assess/AssessReport.php',
    'cli/src/Command/AssessCommand.php',
    'cli/src/Contract/ContractAttestation.php',
    'cli/src/Onboarding/Doctor.php',
    'cli/duo',
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
    duo_check(is_file($path), "$relative is a readable current authoring surface");
    $bytes = (string) file_get_contents($path);
    foreach ($retiredInstructions as $token) {
        duo_check(!str_contains($bytes, $token), "$relative does not instruct authors to use retired '$token'");
    }
}

$currentPathEvidence = [
    'agent/src/Adapter/AdapterCertification.php' => 'adapter-packages/<name>/package/disposition.json',
    'cli/src/Assess/AssessReport.php' => 'package-owned disposition documents',
    'cli/src/Command/AssessCommand.php' => 'adapter-packages/*/package/disposition.json',
    'cli/src/Contract/ContractAttestation.php' => 'platform/adapter-library/capabilities/platform.json',
    'cli/duo' => 'adapter-packages/<name>/package/disposition.json',
];
foreach ($currentPathEvidence as $relative => $token) {
    $bytes = (string) file_get_contents($root . '/' . $relative);
    duo_check(str_contains($bytes, $token), "$relative names current package-layout remediation '$token'");
}

foreach (['capability-doc.php', 'adapter-grade.php'] as $tool) {
    $bytes = (string) file_get_contents($root . '/tools/' . $tool);
    duo_check(str_contains($bytes, "=== 'render'"), "tools/$tool exposes an on-demand render command");
    duo_check(!str_contains($bytes, 'file_put_contents('), "tools/$tool cannot rewrite a central adapter projection");
}

$spec = (string) file_get_contents($root . '/spec/repo-format.md');
duo_check(
    !str_contains($spec, 'tools/adapter-executable-inventory.json'),
    'the normative spec derives runtime ownership instead of requiring a central executable inventory'
);

$releaseGate = (string) file_get_contents($root . '/Makefile');
duo_check(
    str_contains($releaseGate, "\tphp tools/capability-doc.php --check\n")
        && str_contains($releaseGate, "\tphp tools/adapter-grade.php --check\n"),
    'release-gate validates capability and grade sources without stored aggregate projections'
);
duo_check(
    preg_match('/^\t[^\n]*adapter-packages\/[a-z0-9-]+\/tests\//m', $releaseGate) !== 1,
    'Makefile recipes contain no literal adapter-package test path'
);
duo_check(
    str_contains($releaseGate, "regress-%: adapter-package-make-force\n")
        && str_contains($releaseGate, "\tphp tools/adapter-package-make-target.php --target=" . '$@' . "\n"),
    'legacy and newly added package suite targets resolve through checked discovery'
);

foreach ([
    'sandbox/tests/certify/certify_ssh_adoption_roundtrip.sh',
    'sandbox/tests/certify/certify_ssh_rollback.sh',
] as $relative) {
    $bytes = (string) file_get_contents($root . '/' . $relative);
    duo_check(
        str_contains($bytes, 'platform/adapter-library/capabilities/platform.json'),
        "$relative reads the package-layout platform boundary"
    );
}

duo_check_summary('regress-adapter-package-current-paths');
