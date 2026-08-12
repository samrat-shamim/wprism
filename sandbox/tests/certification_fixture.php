<?php
/**
 * The certification fixture: the REAL shipped manifest library — every
 * manifest, disposition, interpreter, provider, and regenerator byte for byte —
 * under a scratch directory whose certification attestation has been RE-SEALED
 * against the working tree it was built from.
 *
 * Why this exists (DUO-3379). The checked-in evidence attestation binds the
 * exact bytes of every certification-bound repository input, so a branch that
 * legitimately edits one — engine source, the Makefile, a shipped manifest —
 * carries EXPIRED evidence until the protocol's final reference bundle is
 * imported, and the regenerated registry reads `candidate` until then. The
 * runtime then attaches the evidence_not_current blocker to every claim,
 * certified ones included. A fixture that wants to exercise behavior BEHIND
 * that gate would otherwise pass or fail on where in the certification cycle
 * the branch happens to sit rather than on the behavior under test, which is
 * the coupling this fixture removes.
 *
 * It removes the coupling without inventing a synthetic library, because a
 * synthetic one cannot demonstrate a claim about the real shipped adapters at
 * all: the reviewed facts stay the real generated ones — dispositions,
 * statuses, adapter digests, operations, surfaces, profiles — and ONLY the
 * attestation is re-derived, exactly as re-certifying this tree would derive
 * it. Nothing here writes to the shipped library; every mutated directory is a
 * scratch copy.
 *
 * Three callers, two languages, ONE re-seal (DUO-3421). The re-seal has to agree
 * with the bundle builder (sandbox/bin/certification-bundle.php::cert_json) and
 * the importer (scripts/capability-registry.php::cap_bundle_digest) on the
 * exact compact-canonical bundle-identity basis; a second implementation of
 * that agreement is a third notion of bundle identity waiting to drift, so
 * all callers share this one:
 *
 *   - sandbox/tests/regress_adapter_sources.php (DUO-3379, the original)
 *     requires this file; its certified_library() is now the scratch-root
 *     wrapper around duo_cert_seal_library(), and its fixture group is still
 *     the visible proof that the re-seal is genuinely current for these bytes.
 *   - sandbox/tests/regress_duo_init.sh (DUO-3421) RUNS this file
 *     (`php sandbox/tests/certification_fixture.php <root>`), which prints the
 *     sealed manifests directory on stdout, and mounts that directory into its
 *     live pair as DUO_MANIFESTS_SRC. `wp duo init` proposals in the pair then
 *     see current evidence for the shipped adapters no matter what the live
 *     tree's attestation says — which, on every bundle-owing branch and on
 *     pristine main between refreshes, is "expired". Without it the suite's
 *     paused confirmations refuse instantly with an unsupported-row proposal
 *     and the swap-link TOCTOU cases can never acquire the init lease.
 *   - sandbox/tests/regress_scoped_certification_bundle.php (DUO-3454)
 *     requires this file to build a sealed source fixture, then strips it to
 *     the deployed agent/manifests layout before exercising runtime checks.
 *
 * Deliberately NOT named regress_*: this file is a fixture source, not a suite,
 * and sandbox/tests/regress_bundle_coverage.sh's survey (rightly) expects every
 * regress_* file to be either a runnable suite or invoked as `php <file>` by
 * one. This one is a require'd helper AND a fixture builder, neither of which
 * is a regression suite.
 *
 * Every failure here is FIXTURE MANUFACTURE, never a product verdict: the
 * messages carry the "certification fixture manufacture failed:" prefix so a
 * broken fixture can never be read as an accusation against the engine
 * (DUO-3381's premise-before-behavior family).
 */

if (!class_exists('Duo\\Canon', false)) {
    require_once dirname(__DIR__, 2) . '/agent/src/Canon.php';
}
require_once dirname(__DIR__, 2) . '/agent/src/ManifestDispositions.php';
require_once dirname(__DIR__, 2) . '/agent/src/CapabilityRegistry.php';

use Duo\Canon;
use Duo\CapabilityRegistry;
use Duo\ManifestDispositions;
use Duo\ScopedCertificationBundle;

/** Recursive byte-for-byte directory copy into a (created) destination. */
function duo_cert_copy_tree(string $from, string $to): void {
    if (!is_dir($to) && !mkdir($to, 0777, true) && !is_dir($to)) {
        throw new \RuntimeException("certification fixture manufacture failed: cannot create $to");
    }
    foreach (scandir($from) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        if (is_dir("$from/$entry")) {
            duo_cert_copy_tree("$from/$entry", "$to/$entry");
        } elseif (!copy("$from/$entry", "$to/$entry")) {
            throw new \RuntimeException(
                "certification fixture manufacture failed: cannot copy $from/$entry"
            );
        }
    }
}

/**
 * The re-seal hash basis of a certification bundle. The bundle builder
 * (sandbox/bin/certification-bundle.php::cert_json) and the importer
 * (scripts/capability-registry.php::cap_bundle_digest) already agree on this
 * exact compact canonical form, deliberately distinct from Canon::encode()'s
 * pretty-printed repository representation. A fixture that re-seals a bundle
 * has to use the same basis or it would be inventing a third notion of bundle
 * identity; regress_adapter_sources.php's fixture group pins that agreement by
 * reproducing the SHIPPED bundle's own recorded digest through this function.
 */
function duo_cert_bundle_digest(array $bundle): string {
    unset($bundle['bundle_digest']);
    return hash('sha256', json_encode(
        Canon::normalize($bundle),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    ) . "\n");
}

/**
 * Every library byte the re-seal must NOT touch, keyed by repository-relative
 * path: the manifests themselves, the reviewed dispositions, and every file the
 * adapter digest reaches for. capabilities/ is excluded — that IS the
 * attestation being re-derived.
 *
 * @return array<string,string>
 */
function duo_cert_library_bytes(string $manifestDir): array {
    $out = [];
    foreach (glob("$manifestDir/*.json") ?: [] as $file) {
        $out[basename($file)] = hash_file('sha256', $file);
    }
    foreach (['interpreters', 'providers', 'regenerators'] as $sub) {
        foreach (glob("$manifestDir/$sub/*") ?: [] as $file) {
            $out["$sub/" . basename($file)] = hash_file('sha256', $file);
        }
    }
    ksort($out, SORT_STRING);
    return $out;
}

/** @return list<array{name:string,role:string,sha256:string,url:string,version:string}> */
function duo_cert_scoped_artifacts(string $root, array $manifest): array {
    $plugin = $manifest['plugin'] ?? null;
    if (!is_string($plugin) || !str_contains($plugin, '/')) {
        throw new \RuntimeException('certification fixture manufacture failed: scoped subject is not plugin-backed');
    }
    $slug = strstr($plugin, '/', true);
    $lock = Canon::decode(Canon::read_file("$root/sandbox/conformance/artifacts.lock.json"));
    $entries = is_array($lock) ? ($lock['plugins'][$slug] ?? null) : null;
    if (!is_array($entries) || array_is_list($entries)) {
        throw new \RuntimeException("certification fixture manufacture failed: scoped subject '$slug' has no artifact lock entries");
    }
    $out = [];
    foreach ($entries as $version => $entry) {
        if (!is_array($entry) || !in_array($entry['role'] ?? null, ['certified-boundary', 'refusal-fixture'], true)) {
            continue;
        }
        $out[] = [
            'name' => $slug,
            'role' => $entry['role'],
            'sha256' => $entry['sha256'] ?? null,
            'url' => $entry['url'] ?? null,
            'version' => (string) $version,
        ];
    }
    usort($out, static fn(array $a, array $b): int => strcmp(
        $a['name'] . "\0" . $a['role'] . "\0" . $a['version'],
        $b['name'] . "\0" . $b['role'] . "\0" . $b['version']
    ));
    if ($out === []) {
        throw new \RuntimeException("certification fixture manufacture failed: scoped subject '$slug' has no certified artifact boundary");
    }
    return $out;
}

/**
 * Re-derive every scoped record on the fixture root while retaining its real,
 * passing durable assets.  A generic bound-input edit expires the checked-in
 * record deliberately; a hermetic fixture must refresh its closure, platform,
 * reviewed ratification, and content address or its synthetic `current` claim
 * would be the very stale projection runtime validation is meant to reject.
 */
function duo_cert_reseal_scoped_evidence(array &$evidence, array &$registry, string $root): void {
    $scopedEntries = $evidence['scoped'] ?? [];
    if (!is_array($scopedEntries) || array_is_list($scopedEntries)) {
        throw new \RuntimeException('certification fixture manufacture failed: scoped evidence collection is malformed');
    }
    $dispositions = ManifestDispositions::load("$root/manifests");
    if ($dispositions === null) {
        throw new \RuntimeException('certification fixture manufacture failed: fixture dispositions are absent');
    }
    foreach ($scopedEntries as $name => $entry) {
        $record = is_array($entry) ? ($entry['bundle'] ?? null) : null;
        $oldPath = is_array($entry) ? ($entry['path'] ?? null) : null;
        if (!is_string($name) || !is_array($record) || !is_string($oldPath)) {
            throw new \RuntimeException('certification fixture manufacture failed: scoped evidence entry is malformed');
        }
        ScopedCertificationBundle::validate($record, "fixture scoped certification '$name'");
        if (!hash_equals('scoped/' . $name . '/' . $record['bundle_digest'], $oldPath)) {
            throw new \RuntimeException("certification fixture manufacture failed: scoped evidence path for '$name' is not canonical");
        }
        $manifest = Canon::decode(Canon::read_file("$root/manifests/$name.json"));
        $disposition = $dispositions->entry($name);
        if (!is_array($manifest) || !is_array($disposition)) {
            throw new \RuntimeException("certification fixture manufacture failed: scoped subject '$name' is absent");
        }
        $claim = $registry['manifests'][$name] ?? null;
        if (!is_array($claim)) {
            throw new \RuntimeException("certification fixture manufacture failed: scoped claim '$name' is absent");
        }
        $requiredTests = $disposition['evidence']['tests'] ?? null;
        if (!is_array($requiredTests) || !array_is_list($requiredTests)) {
            throw new \RuntimeException("certification fixture manufacture failed: scoped test citations for '$name' are malformed");
        }
        $record['adapter_digest'] = CapabilityRegistry::adapter_digest($manifest, $disposition, "$root/manifests");
        $record['artifacts'] = duo_cert_scoped_artifacts($root, $manifest);
        $record['platform'] = $registry['platform'];
        $record['ratification'] = [
            'disposition' => $disposition,
            'manifest' => $name,
            'sha256' => hash('sha256', Canon::encode($disposition)),
        ];
        $inputs = ScopedCertificationBundle::currentInputs($root, $record['closure']['inputs']);
        $record['closure'] = [
            'digest' => ScopedCertificationBundle::closureDigest($inputs),
            'inputs' => $inputs,
        ];
        $record['claims'] = ['manifests.' . $name => $requiredTests];
        $record['bundle_digest'] = ScopedCertificationBundle::digest($record);
        ScopedCertificationBundle::validate($record, "fixture scoped certification '$name'");

        $oldDir = "$root/manifests/capabilities/$oldPath";
        $newPath = 'scoped/' . $name . '/' . $record['bundle_digest'];
        $newDir = "$root/manifests/capabilities/$newPath";
        if (!is_dir($oldDir) || is_link($oldDir)) {
            throw new \RuntimeException("certification fixture manufacture failed: durable scoped evidence for '$name' is absent");
        }
        // A fixture built from a currently certified checkout re-derives the
        // same content address. The complete durable directory was copied
        // with the manifest library above, so copying it onto itself would
        // make PHP's copy() reject source === destination. Only a changed
        // closure needs a second durable directory.
        if ($oldPath !== $newPath) {
            duo_cert_copy_tree($oldDir, $newDir);
        }
        Canon::write_file("$newDir/bundle.json", Canon::encode($record));
        ScopedCertificationBundle::assertEvidenceAssets($record, $newDir);
        ScopedCertificationBundle::assertCurrent(
            $record,
            $name,
            $record['adapter_digest'],
            $registry['platform'],
            ScopedCertificationBundle::currentInputs($root, $record['closure']['inputs']),
            $requiredTests,
            $disposition,
            duo_cert_scoped_artifacts($root, $manifest)
        );
        $evidence['scoped'][$name] = ['bundle' => $record, 'path' => $newPath];
        $claim['evidence']['adapter_digest'] = $record['adapter_digest'];
        $claim['evidence']['bundle_digest'] = $record['bundle_digest'];
        $claim['evidence']['closure_digest'] = $record['closure']['digest'];
        $claim['evidence']['force_hatches'] = [];
        $claim['evidence']['status'] = 'current';
        $claim['evidence']['subject'] = $name;
        $claim['evidence']['tests'] = $requiredTests;
        $registry['manifests'][$name] = $claim;
    }
}

/**
 * Copy $repo's shipped library into $root and re-seal its attestation against
 * $repo's working tree. Returns the sealed manifests directory ("$root/manifests").
 *
 * $root is created if absent and is expected to be scratch: this writes
 * $root/manifests (the library) and $root/docs/compatibility-baseline.json
 * (which CapabilityRegistry::validate() re-verifies generated_from against
 * whenever it is present beside the library, so copying it keeps that check
 * live on the fixture instead of silently skipped).
 */
function duo_cert_seal_library(string $repo, string $root): string {
    $repo = rtrim($repo, '/');
    $root = rtrim($root, '/');
    if (!is_dir("$repo/manifests")) {
        throw new \RuntimeException(
            "certification fixture manufacture failed: no shipped library at $repo/manifests"
        );
    }
    if (!is_dir($root) && !mkdir($root, 0777, true) && !is_dir($root)) {
        throw new \RuntimeException("certification fixture manufacture failed: cannot create $root");
    }
    duo_cert_copy_tree("$repo/manifests", "$root/manifests");
    if (!is_dir("$root/docs") && !mkdir("$root/docs", 0777, true) && !is_dir("$root/docs")) {
        throw new \RuntimeException("certification fixture manufacture failed: cannot create $root/docs");
    }
    if (!copy("$repo/docs/compatibility-baseline.json", "$root/docs/compatibility-baseline.json")) {
        throw new \RuntimeException(
            'certification fixture manufacture failed: cannot copy docs/compatibility-baseline.json'
        );
    }

    $evidence = Canon::decode(Canon::read_file("$repo/manifests/capabilities/evidence.json"));
    $registry = Canon::decode(Canon::read_file("$repo/manifests/capabilities/registry.json"));
    $bundle = $evidence['bundle'];

    // A current scoped claim is revalidated from the root inferred from its
    // durable evidence path, rather than trusting the generated projection.
    // Preserve that invariant in this hermetic fixture: copy every declared
    // scoped closure byte from the real source tree beside the copied manifest
    // library before re-sealing its raw record below.
    foreach ($evidence['scoped'] ?? [] as $name => $entry) {
        $scoped = is_array($entry) ? ($entry['bundle'] ?? null) : null;
        $inputs = is_array($scoped) ? ($scoped['closure']['inputs'] ?? null) : null;
        if (!is_string($name) || !is_array($inputs) || !array_is_list($inputs)) {
            throw new \RuntimeException("certification fixture manufacture failed: malformed scoped evidence for '$name'");
        }
        foreach ($inputs as $input) {
            $relative = is_array($input) ? ($input['path'] ?? null) : null;
            if (!is_string($relative) || $relative === '' || str_starts_with($relative, '/')
                || str_contains($relative, '\\') || in_array('..', explode('/', $relative), true)) {
                throw new \RuntimeException("certification fixture manufacture failed: unsafe scoped input for '$name'");
            }
            $source = "$repo/$relative";
            $target = "$root/$relative";
            if (!is_file($source) || is_link($source)) {
                throw new \RuntimeException("certification fixture manufacture failed: scoped input '$relative' for '$name' is absent or unsafe");
            }
            if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0777, true) && !is_dir(dirname($target))) {
                throw new \RuntimeException("certification fixture manufacture failed: cannot create scoped input directory for '$relative'");
            }
            if (!copy($source, $target)) {
                throw new \RuntimeException("certification fixture manufacture failed: cannot copy scoped input '$relative'");
            }
        }
    }

    duo_cert_reseal_scoped_evidence($evidence, $registry, $root);

    // Bind the attestation to the bytes this tree actually has — what
    // re-running certification on it would record. A bound input the branch
    // deleted is simply no longer bound.
    $bound = [];
    foreach ($bundle['bound_inputs'] as $input) {
        $file = $repo . '/' . (string) $input['path'];
        if (is_file($file)) {
            $bound[] = [
                'path' => (string) $input['path'],
                'sha256' => hash_file('sha256', $file),
                'size' => filesize($file),
            ];
        }
    }
    $bundle['bound_inputs'] = $bound;

    // A `current` registry refuses any certified claim citing evidence the
    // bundle does not carry — correctly, and regress_adapter_sources.php's
    // fail-closed group asserts it. A branch mid-recertification cites IDs no
    // already-imported bundle can contain, so the fixture supplies them: this
    // attestation describes a library whose evidence covers its own citations.
    $carried = [];
    foreach ($bundle['tests'] as $test) {
        $carried[(string) ($test['id'] ?? '')] = true;
    }
    foreach ([$registry['manifests'], $registry['profiles']] as $claims) {
        foreach ($claims as $claim) {
            foreach ($claim['evidence']['tests'] ?? [] as $cited) {
                if (!isset($carried[(string) $cited])) {
                    $bundle['tests'][] = ['id' => (string) $cited, 'verdict' => 'pass'];
                    $carried[(string) $cited] = true;
                }
            }
        }
    }
    $bundle['bundle_digest'] = duo_cert_bundle_digest($bundle);

    $evidence['status'] = 'current';
    $evidence['bundle'] = $bundle;
    $evidenceFile = "$root/manifests/capabilities/evidence.json";
    Canon::write_file($evidenceFile, Canon::encode($evidence));

    // Exactly the fields cap_build_registry() derives from the attestation and
    // nothing else, so every reviewed column stays the shipped generated one.
    $registry['generated_from']['evidence_sha256'] = hash_file('sha256', $evidenceFile);
    $registry['evidence']['status'] = 'current';
    $registry['evidence']['bundle_digest'] = $bundle['bundle_digest'];
    $registry['evidence']['tests'] = array_map(
        fn(array $test): array => [
            'id' => (string) ($test['id'] ?? ''),
            'verdict' => (string) ($test['verdict'] ?? ''),
        ],
        $bundle['tests']
    );
    foreach (['manifests', 'profiles'] as $section) {
        foreach ($registry[$section] as $name => $claim) {
            // A scoped record has its own content-addressed authority.  The
            // global fixture re-seal must not overwrite that digest with the
            // synthetic release-wide one, or the runtime cross-check rightly
            // detects a split attestation.
            if (($claim['evidence']['bundle_schema'] ?? null) === 'duo-adapter-certification-bundle/v1') {
                continue;
            }
            $claim['evidence']['status'] = 'current';
            $claim['evidence']['bundle_digest'] = $bundle['bundle_digest'];
            $registry[$section][$name] = $claim;
        }
    }
    Canon::write_file("$root/manifests/capabilities/registry.json", Canon::encode($registry));

    duo_cert_assert_sealed($repo, "$root/manifests");
    return "$root/manifests";
}

/**
 * The fixture's own post-condition, read back from the bytes on disk.
 *
 * The point of re-sealing rather than flipping a flag is that the attestation
 * stays a real one, so this runs the release gate's own expiry predicate over
 * what was just written and requires it to find nothing — if a future edit
 * reduced duo_cert_seal_library() to "declare it current", this is what fails,
 * for BOTH callers, before either of them can build anything on top of it.
 */
function duo_cert_assert_sealed(string $repo, string $manifestDir): void {
    $repo = rtrim($repo, '/');
    $fail = static function (string $why): void {
        throw new \RuntimeException("certification fixture manufacture failed: $why");
    };

    $shipped = duo_cert_library_bytes("$repo/manifests");
    $sealed = duo_cert_library_bytes($manifestDir);
    if ($sealed !== $shipped || $sealed === []) {
        $fail('the sealed library is not the shipped library byte for byte outside capabilities/');
    }

    $evidence = Canon::decode(Canon::read_file("$manifestDir/capabilities/evidence.json"));
    $registry = Canon::decode(Canon::read_file("$manifestDir/capabilities/registry.json"));
    if (($evidence['status'] ?? null) !== 'current') {
        $fail('the sealed attestation does not read current');
    }
    $digest = (string) ($evidence['bundle']['bundle_digest'] ?? '');
    if (!hash_equals($digest, duo_cert_bundle_digest($evidence['bundle']))) {
        $fail('the sealed attestation does not carry its own recomputed bundle digest');
    }
    if (!hash_equals(
        (string) ($registry['generated_from']['evidence_sha256'] ?? ''),
        (string) hash_file('sha256', "$manifestDir/capabilities/evidence.json")
    )) {
        $fail('the sealed registry is stale against the attestation beside it');
    }
    if (($registry['evidence']['status'] ?? null) !== 'current'
        || !hash_equals((string) ($registry['evidence']['bundle_digest'] ?? ''), $digest)) {
        $fail('the sealed registry does not cite the sealed attestation as current');
    }
    foreach (['manifests', 'profiles'] as $section) {
        foreach ($registry[$section] as $name => $claim) {
            $claimEvidence = $claim['evidence'] ?? [];
            if (($claimEvidence['bundle_schema'] ?? null) === 'duo-adapter-certification-bundle/v1') {
                $scoped = $evidence['scoped'][$name]['bundle'] ?? null;
                if (!is_array($scoped)
                    || ($claimEvidence['status'] ?? null) !== 'current'
                    || !hash_equals((string) ($claimEvidence['bundle_digest'] ?? ''), (string) ($scoped['bundle_digest'] ?? ''))) {
                    $fail("the sealed registry claim '$name' does not cite its current scoped attestation");
                }
                continue;
            }
            if (($claimEvidence['status'] ?? null) !== 'current'
                || !hash_equals((string) ($claimEvidence['bundle_digest'] ?? ''), $digest)) {
                $fail("the sealed registry claim '$name' does not cite the sealed attestation as current");
            }
        }
    }

    $bound = $evidence['bundle']['bound_inputs'] ?? [];
    $expired = [];
    foreach ($bound as $input) {
        $file = $repo . '/' . (string) $input['path'];
        if (!is_file($file)
            || !hash_equals((string) $input['sha256'], (string) hash_file('sha256', $file))
            || (int) $input['size'] !== filesize($file)) {
            $expired[] = (string) $input['path'];
        }
    }
    if ($expired !== [] || count($bound) < 2) {
        $fail('the sealed attestation does not bind this working tree ('
            . ($expired === [] ? count($bound) . ' bound inputs' : 'expired: ' . implode(', ', $expired)) . ')');
    }

    foreach ($evidence['scoped'] ?? [] as $name => $entry) {
        $scoped = is_array($entry) ? ($entry['bundle'] ?? null) : null;
        $inputs = is_array($scoped) ? ($scoped['closure']['inputs'] ?? null) : null;
        if (!is_string($name) || !is_array($inputs) || !array_is_list($inputs)) {
            $fail('the sealed library has malformed scoped evidence');
        }
        foreach ($inputs as $input) {
            $relative = is_array($input) ? ($input['path'] ?? null) : null;
            $expectedHash = is_array($input) ? ($input['sha256'] ?? null) : null;
            $expectedSize = is_array($input) ? ($input['size'] ?? null) : null;
            $file = is_string($relative) ? dirname($manifestDir) . "/$relative" : '';
            if (!is_file($file)
                || !is_string($expectedHash) || !hash_equals($expectedHash, (string) hash_file('sha256', $file))
                || !is_int($expectedSize) || $expectedSize !== filesize($file)) {
                $fail("the sealed library is missing current scoped closure input for '$name'");
            }
        }
    }
}

// --------------------------------------------------------------------------
// Script mode: `php sandbox/tests/certification_fixture.php <root>` builds the
// fixture under <root> and prints the sealed manifests directory on stdout.
// Diagnostics go to stderr so a shell caller can capture the path alone.
// --------------------------------------------------------------------------
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    if (($argc ?? 0) !== 2 || $argv[1] === '') {
        fwrite(STDERR, "usage: php " . basename(__FILE__) . " <scratch-root>\n");
        exit(2);
    }
    try {
        $repoRoot = dirname(__DIR__, 2);
        $manifestDir = duo_cert_seal_library($repoRoot, $argv[1]);
        $evidence = Canon::decode(Canon::read_file("$manifestDir/capabilities/evidence.json"));
        fwrite(STDERR, sprintf(
            "certification fixture sealed: %d library files, %d bound inputs, %d tests, bundle %s\n",
            count(duo_cert_library_bytes($manifestDir)),
            count($evidence['bundle']['bound_inputs']),
            count($evidence['bundle']['tests']),
            substr((string) $evidence['bundle']['bundle_digest'], 0, 16)
        ));
    } catch (\Throwable $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }
    fwrite(STDOUT, $manifestDir . "\n");
    exit(0);
}
