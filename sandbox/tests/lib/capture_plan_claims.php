<?php

declare(strict_types=1);

use WPrism\AdapterLibrary;
use WPrism\AdapterSources;
use WPrism\Canon;
use WPrism\ManifestDispositions;

require_once __DIR__ . '/agent_version.php';
require_once __DIR__ . '/../../../agent/src/Policy/AdapterLibrary.php';
require_once __DIR__ . '/../../../agent/src/Adapter/AdapterSources.php';
require_once __DIR__ . '/../../../agent/src/Kernel/Canon.php';
require_once __DIR__ . '/../../../agent/src/Policy/ManifestDispositions.php';

// A capability answer cannot be its own status oracle: a false certified/ready
// report would otherwise pass capture-plan. Read only the selected declarations
// through the existing layout/claim owners, including their derived promote
// operation; target verdicts and report construction are deliberately not used.
try {
    if ($argc !== 3) {
        throw new RuntimeException('expected a source tree and its fixture pin list');
    }
    wprism_test_define_agent_versions();
    $pins = json_decode($argv[2], true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($pins) || !array_is_list($pins) || $pins === []) {
        throw new RuntimeException('fixture pins must be a nonempty list');
    }
    $library = AdapterLibrary::fromSourceTree($argv[1]);
    $platform = ManifestDispositions::platform_boundary_library($library);
    $claims = [];
    foreach ($pins as $pin) {
        $name = is_string($pin) ? $pin : (is_array($pin) ? ($pin['name'] ?? null) : null);
        if (!is_string($name) || preg_match('/^[a-z][a-z0-9-]*$/D', $name) !== 1
            || isset($claims[$name])) {
            throw new RuntimeException('fixture pin names must be valid and unique');
        }
        $package = $library->package($name);
        if ($package === null) {
            throw new RuntimeException("fixture pin '$name' has no shipped package");
        }
        $manifest = Canon::decode(Canon::read_file($package->manifestPath()));
        $disposition = Canon::decode(Canon::read_file($package->dispositionPath()));
        ManifestDispositions::assert_entry($name, $disposition, $manifest);
        $claim = ManifestDispositions::claim_from_disposition($manifest, $disposition, [], $platform);
        $claims[$name] = [
            'name' => $name,
            'status' => $claim['status'],
            'operations' => $claim['operations'],
            'trust_tier' => AdapterSources::trust_tier($manifest),
        ];
    }
    ksort($claims, SORT_STRING);
    echo json_encode(array_values($claims), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
} catch (Throwable $failure) {
    fwrite(STDERR, 'capture-plan declarations: ' . $failure->getMessage() . "\n");
    exit(1);
}
