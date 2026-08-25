<?php
/**
 * Build a hermetic, byte-identical copy of the shipped manifest library under
 * a caller-owned scratch root, and prove it loads before any consumer mounts
 * it.
 *
 * WHAT THIS USED TO BE, and why the difference matters to every consumer. Until
 * the evidence chain was retired this file MANUFACTURED something: it re-sealed
 * per-subject certification bundles against the working tree, because the
 * checked-in attestation bound the exact bytes of every certification-bound
 * repository input and was therefore EXPIRED by construction on any branch that
 * owed a bundle — which made `evidence_not_current` ride on every certified
 * claim and blocked suites on the evidence they existed to mint.
 *
 * There is no such attestation any more. `manifests/capabilities/` holds only
 * `platform.json` (the agent's own runtime boundary) and
 * `adapter-authorities.json` (the shipped Ed25519 trust root); the reviewed
 * dispositions are the whole authored claim source and they are checked-in
 * bytes that no branch state can expire. So the fixture no longer derives
 * anything — a hermetic COPY is now exactly as current as the shipped library,
 * and saying otherwise would be prose about a mechanism that is gone.
 *
 * What it is still for: the live pair suites mount a manifest library into
 * Docker and must not mount the primary checkout's own directory, and the
 * offline overlay suites need a mutable library they can edit per variant.
 * Both want one thing — a directory that IS the shipped library and that
 * provably loads — which is what duo_cert_hermetic_library() returns.
 */
declare(strict_types=1);

if (!class_exists('Duo\\Canon', false)) {
    require_once dirname(__DIR__, 4) . '/agent/src/Kernel/Canon.php';
}
require_once dirname(__DIR__, 4) . '/agent/src/Policy/ManifestDispositions.php';

use Duo\ManifestDispositions;

function duo_cert_copy_tree(string $from, string $to): void {
    if (!is_dir($to) && !mkdir($to, 0777, true) && !is_dir($to)) {
        throw new RuntimeException("certification fixture manufacture failed: cannot create $to");
    }
    foreach (scandir($from) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        if (is_dir("$from/$entry")) {
            duo_cert_copy_tree("$from/$entry", "$to/$entry");
        } elseif (!copy("$from/$entry", "$to/$entry")) {
            throw new RuntimeException("certification fixture manufacture failed: cannot copy $from/$entry");
        }
    }
}

function duo_cert_remove_tree(string $path): void {
    if (!file_exists($path) && !is_link($path)) {
        return;
    }
    if (is_file($path) || is_link($path)) {
        unlink($path);
        return;
    }
    foreach (new FilesystemIterator($path) as $item) {
        duo_cert_remove_tree($item->getPathname());
    }
    rmdir($path);
}

/**
 * Every file of a manifest library, keyed by its library-relative path.
 *
 * The whole tree, `capabilities/` included: with the generated attestation
 * gone there is no directory the fixture is entitled to differ in, so the
 * comparison that used to exclude one is now the strongest one available.
 *
 * @return array<string,string>
 */
function duo_cert_library_bytes(string $manifestDir): array {
    $manifestDir = rtrim($manifestDir, '/');
    if (!is_dir($manifestDir)) {
        throw new RuntimeException("certification fixture manufacture failed: no manifest library at $manifestDir");
    }
    $out = [];
    $walk = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($manifestDir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($walk as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $relative = substr($file->getPathname(), strlen($manifestDir) + 1);
        $out[$relative] = (string) hash_file('sha256', $file->getPathname());
    }
    ksort($out, SORT_STRING);
    return $out;
}

/**
 * Every manifest of a library, decoded, keyed by FILE basename.
 *
 * Keyed by basename rather than by the manifest's own `name` on purpose: a
 * disagreement between the two keyings is not this helper's rule to state
 * (AdapterSources::assert_declared_name() owns it), and keying by basename
 * makes such a manifest surface as an uncovered name in assert_covers()
 * instead of being silently filed under a key the registry never used.
 *
 * @return array<string,array<string,mixed>>
 */
function duo_cert_library_manifests(string $manifestDir): array {
    $out = [];
    foreach (glob(rtrim($manifestDir, '/') . '/*.json') ?: [] as $file) {
        // No `dispositions` skip: WP-4.4 moved the reviewed claim source into
        // manifests/dispositions/, which this glob does not match.
        $out[basename($file, '.json')] = \Duo\Canon::decode(\Duo\Canon::read_file($file));
    }
    ksort($out, SORT_STRING);
    return $out;
}

/**
 * Copy the shipped manifest library to $root/manifests and return that path.
 *
 * Asserted, not assumed, before the caller gets it back: byte-identical to the
 * shipped tree, and loadable — the dispositions parse and cover the shipped
 * manifests one for one, and the platform boundary agrees with the agent this
 * process loaded. A fixture whose manufacture silently failed would report the
 * ENGINE as broken to whatever mounts it.
 */
function duo_cert_hermetic_library(string $repo, string $root): string {
    $repo = rtrim($repo, '/');
    $root = rtrim($root, '/');
    if (!is_dir($root) && !mkdir($root, 0777, true) && !is_dir($root)) {
        throw new RuntimeException("certification fixture manufacture failed: cannot create $root");
    }
    duo_cert_copy_tree("$repo/manifests", "$root/manifests");
    duo_cert_assert_loadable("$repo/manifests", "$root/manifests");
    return "$root/manifests";
}

/**
 * The fixture's own premise check.
 *
 * `ManifestDispositions::load()` plus `assert_covers()` is the real loader,
 * not a re-implementation: they are what refuses a malformed entry or a
 * certified claim with no evidence citation, so running them here is what
 * makes "this library loads" mean the same thing to the fixture as to the
 * product. `assert_covers()` is handed EVERY manifest in the directory
 * because that is the fixture's premise (a whole, complete library) where
 * `Policy::load()`'s premise is narrower (the pinned shipped subset) — the
 * split WP-1.2 made; the reverse direction, a reviewed entry whose manifest
 * is gone, is asserted right after it for the same reason.
 */
function duo_cert_assert_loadable(string $shippedDir, string $manifestDir): void {
    $shippedBytes = duo_cert_library_bytes($shippedDir);
    if ($shippedBytes === []) {
        throw new RuntimeException('certification fixture manufacture failed: the shipped library is empty');
    }
    if ($shippedBytes !== duo_cert_library_bytes($manifestDir)) {
        throw new RuntimeException(
            'certification fixture manufacture failed: the hermetic library is not the shipped library byte for byte'
        );
    }
    $dispositions = ManifestDispositions::load($manifestDir);
    if ($dispositions === null) {
        throw new RuntimeException('certification fixture manufacture failed: dispositions are absent');
    }
    if ($dispositions->data() !== ManifestDispositions::load($shippedDir)?->data()) {
        throw new RuntimeException(
            'certification fixture manufacture failed: the hermetic dispositions differ from the shipped ones'
        );
    }
    $manifests = duo_cert_library_manifests($manifestDir);
    $dispositions->assert_covers(array_values($manifests));
    $reviewed = array_keys($dispositions->data()['manifests']);
    $present = array_keys($manifests);
    sort($reviewed, SORT_STRING);
    sort($present, SORT_STRING);
    if ($reviewed !== $present) {
        throw new RuntimeException(
            'certification fixture manufacture failed: reviewed entries with no manifest: ['
            . implode(',', array_diff($reviewed, $present)) . ']'
        );
    }
    // Reads capabilities/platform.json and refuses a boundary naming a
    // different agent/spec version than this process is running, which is the
    // exact document a signed site-adapter certificate binds as
    // `platform_sha256`. A fixture library that cannot answer it would fail
    // every certification assertion in the mounting suite for a reason nothing
    // there names.
    ManifestDispositions::platform_boundary($manifestDir);
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    if (($argc ?? 0) !== 2 || $argv[1] === '') {
        fwrite(STDERR, 'usage: php ' . basename(__FILE__) . " <scratch-root>\n");
        exit(2);
    }
    try {
        $manifestDir = duo_cert_hermetic_library(dirname(__DIR__, 4), $argv[1]);
        $dispositions = ManifestDispositions::load($manifestDir);
        fwrite(STDERR, sprintf(
            "hermetic manifest library built: %d files, %d reviewed dispositions, %d profiles\n",
            count(duo_cert_library_bytes($manifestDir)),
            count($dispositions?->data()['manifests'] ?? []),
            count($dispositions?->profiles() ?? [])
        ));
        fwrite(STDOUT, $manifestDir . "\n");
    } catch (Throwable $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }
}
