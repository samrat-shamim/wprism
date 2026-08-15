<?php
declare(strict_types=1);

/**
 * Verify the Thread 3 canonical call-site matrix against the current source,
 * ownership ledger, catalog profile, and checked-in characterization inputs.
 * This is deliberately static: it does not rewrite canonical bytes or touch
 * WordPress, and it makes a missing classification fail before refactoring.
 */

$root = dirname(__DIR__, 4);
$matrixPath = __DIR__ . '/canonical-call-site-matrix.json';
$catalogPath = __DIR__ . '/initial.catalog.json';
$ledgerPath = $root . '/docs/proposals/refactor-ownership.json';
$matrix = json_decode((string) file_get_contents($matrixPath), true);
$catalog = json_decode((string) file_get_contents($catalogPath), true);
$ledger = json_decode((string) file_get_contents($ledgerPath), true);
$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if ($condition) {
        fwrite(STDOUT, "ok: $message\n");
        return;
    }
    $failures[] = $message;
    fwrite(STDERR, "FAIL: $message\n");
};

$check(is_array($matrix) && ($matrix['format'] ?? null) === 'duo-canonical-call-site-matrix/v1',
    'canonical call-site matrix has the versioned format');
$check(is_array($matrix) && ($matrix['owner'] ?? null) === 'thread-3',
    'canonical call-site matrix is owned by thread-3');
$domains = is_array($matrix) ? ($matrix['domains'] ?? []) : [];
$check(is_array($domains) && count($domains) >= 5, 'canonical matrix has multiple explicit domains');

$profileIds = [];
foreach ((array) ($catalog['profiles'] ?? []) as $profile) {
    if (is_array($profile) && is_string($profile['id'] ?? null)) {
        $profileIds[(string) $profile['id']] = array_fill_keys((array) ($profile['suite_ids'] ?? []), true);
    }
}
$catalogSuites = array_fill_keys(array_map(
    static fn(array $suite): string => (string) ($suite['id'] ?? ''),
    array_values(array_filter((array) ($catalog['suites'] ?? []), 'is_array'))
), true);
$ledgerOwners = [];
foreach ((array) ($ledger['files'] ?? []) as $row) {
    if (is_array($row) && is_string($row['path'] ?? null) && is_string($row['owner'] ?? null)) {
        $ledgerOwners[(string) $row['path']] = (string) $row['owner'];
    }
}

$listedSources = [];
$listedDomains = [];
foreach ($domains as $domain) {
    if (!is_array($domain)) {
        $check(false, 'canonical matrix domain is an object');
        continue;
    }
    $id = (string) ($domain['id'] ?? '');
    $check($id !== '' && !isset($listedDomains[$id]), "$id is a unique canonical domain");
    $listedDomains[$id] = true;
    $profileId = (string) ($domain['profile_id'] ?? '');
    $check(isset($profileIds[$profileId]), "$id points at a catalog profile");
    foreach ((array) ($domain['source_paths'] ?? []) as $path) {
        $path = (string) $path;
        $listedSources[$path] = ($listedSources[$path] ?? 0) + 1;
        $absolute = $root . '/' . $path;
        $source = is_file($absolute) ? (string) file_get_contents($absolute) : '';
        $check(is_file($absolute), "$id source exists: $path");
        $check(($ledgerOwners[$path] ?? null) === 'thread-3', "$id source is Thread 3-owned: $path");
        $check(preg_match('/(?:\\\\Duo\\\\)?Canon::(?:encode|decode|read_file)\s*\(/', $source) === 1,
            "$id source retains an explicit canonical call-site: $path");
    }
    foreach ((array) ($domain['suite_ids'] ?? []) as $suiteId) {
        $suiteId = (string) $suiteId;
        $check(isset($catalogSuites[$suiteId]), "$id fixture suite is cataloged: $suiteId");
        $check(isset($profileIds[$profileId][$suiteId]), "$id fixture suite belongs to $profileId: $suiteId");
    }
    foreach ((array) ($domain['fixtures'] ?? []) as $fixture) {
        $fixture = (string) $fixture;
        $check(is_file($root . '/' . $fixture), "$id fixture exists: $fixture");
    }
}

$actualSources = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/agent/src', FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $entry) {
    if (!$entry->isFile() || $entry->getExtension() !== 'php') {
        continue;
    }
    $path = substr($entry->getPathname(), strlen($root) + 1);
    if (($ledgerOwners[$path] ?? null) !== 'thread-3') {
        continue;
    }
    $source = (string) file_get_contents($entry->getPathname());
    if (preg_match('/(?:\\\\Duo\\\\)?Canon::(?:encode|decode|read_file)\s*\(/', $source) === 1) {
        $actualSources[$path] = true;
    }
}
ksort($actualSources, SORT_STRING);
$listedSourceSet = array_fill_keys(array_keys($listedSources), true);
ksort($listedSourceSet, SORT_STRING);
$check($actualSources === $listedSourceSet,
    'every Thread 3-owned Canon encode/decode/read_file call-site has exactly one matrix domain');
foreach ($listedSources as $path => $count) {
    $check($count === 1, "canonical source is assigned to one domain: $path");
}

$check(in_array('sandbox/tests/regress_order_preserving.php', array_merge(...array_map(
    static fn(array $domain): array => (array) ($domain['fixtures'] ?? []),
    array_values(array_filter($domains, 'is_array'))
)), true), 'matrix includes the byte-level order-preserving fixture');

if ($failures !== []) {
    fwrite(STDERR, sprintf("REGRESS_CANONICAL_CALL_SITE_MATRIX FAILED (%d failures)\n", count($failures)));
    exit(1);
}
fwrite(STDOUT, "REGRESS_CANONICAL_CALL_SITE_MATRIX PASSED\n");
