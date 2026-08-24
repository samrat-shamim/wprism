<?php
/**
 * The flag-day rehearsal estate and its driver — a synthetic fleet on disk,
 * driven against two agent states by a child process per state.
 *
 * WHAT A "STATE" IS, AND WHY THIS FILE IS A CHILD PROCESS
 * ------------------------------------------------------
 * The flag day moves exactly two things together: the two `define()` lines in
 * `agent/duo.php` and `manifests/capabilities/platform.json`, which restates
 * them (AGENTS.md rule 8; `ManifestDispositions::platform_boundary()` throws
 * "platform version disagrees with the loaded agent" the moment they diverge).
 * `cli/src/Onboarding/Adopt.php:147-150` tars `agent manifests recovery` as ONE
 * archive, so a site never sees half of that pair. A rehearsal state is
 * therefore the pair `(defines, manifest library)` — and since a PHP process
 * can define `DUO_AGENT_VERSION` exactly once, each state must be its own
 * process. That is the whole reason this file exists beside the suite instead
 * of inside it: the suite orchestrates, this file IS the agent-under-test at
 * one state.
 *
 * State A is the tree's own pair, read out of `agent/duo.php` rather than
 * written here as a literal (the same regex `AdapterCertify::boot()` uses at
 * :1151-1166, for the same reason: a literal drifts). State B is A with the
 * agent version bumped one minor and `platform.json` restating it — the
 * minimum shape a release bump can have. State B deliberately does NOT move
 * `spec_version`: `AdapterContractGrammar` is exact equality today, so a v3
 * agent refuses every v2 manifest by name, which is a rule set that does not
 * exist until WP-4.2's acceptance window ships. Rehearsing it here would be
 * rehearsing an engine nobody has written; the suite records that as a named
 * scope limit rather than a silent one.
 *
 * WHY THE ENGINE SOURCE IS THE SAME IN BOTH STATES. The flag day's digest
 * neutrality claim is about the manifest LIBRARY, not the engine: nothing
 * under `agent/src` is an input to `ArtifactPolicyIdentity::manifest_rows()`.
 * The rehearsal moves the data half — defines plus `platform.json` — against
 * the engine of the tree it is run from, so when Phase 4's riders land, the
 * same driver rehearses them without being rewritten.
 *
 * WHAT IS ON DISK (estate root; `sandbox/tmp/` or a mktemp -d — never under
 * agent/ or manifests/, rule 3, because `sandbox/bin/pair.sh:355` refuses on an
 * untracked file there and every concurrently running live package would block)
 *
 *   libs/A          the shipped manifest library, byte for byte
 *   libs/B          libs/A with ONLY capabilities/platform.json restated
 *   keys/           the operator Ed25519 keys the estate certifies under
 *   sites/<id>/     nine site repositories: bare-name pins, digest pins,
 *                   `source:"site"` overrides, certified site adapters under
 *                   two authority keys, and three deliberate controls
 *   holdings/<id>/  what a promoted site is holding: compiled artifact, frozen
 *                   policy snapshot, scope contract, identity sidecar, recovery
 *                   checkpoint inputs
 *   scratch/        per-probe copies; no probe mutates a cohort site, which is
 *                   what makes the A→B→A comparison a rollback proof rather
 *                   than a comparison of two different estates
 *
 * OUTPUT CONTRACT. One canonical JSON document on STDOUT, progress on STDERR.
 * Every value in it is a FACT the engine produced (a digest, a refusal message,
 * a typed exception class); no verdict is computed here. The suite owns every
 * judgement, including which sites are controls and which gates are allowed to
 * be unexercised — a driver that graded its own output would be the false green
 * this work package exists to prevent.
 *
 * Deliberately NOT named regress_* : this is a helper the suite runs, and
 * `regress_suite_wiring.php` reserves the four class prefixes for files a
 * Makefile target runs directly.
 */
declare(strict_types=1);

// ---------------------------------------------------------------------------
// Filesystem and canonical-write helpers. Kept local rather than pulled from
// offline/adapter/certification_fixture.php: that file's helpers assert the
// SHIPPED library's premises, which is one of the things this estate must be
// able to violate on purpose (libs/B is not the shipped library).
// ---------------------------------------------------------------------------

function rehearsal_mkdir(string $path): void {
    if (!is_dir($path) && !mkdir($path, 0777, true) && !is_dir($path)) {
        throw new RuntimeException("rehearsal estate: cannot create $path");
    }
}

function rehearsal_write(string $path, string $bytes): void {
    rehearsal_mkdir(dirname($path));
    if (file_put_contents($path, $bytes) === false) {
        throw new RuntimeException("rehearsal estate: cannot write $path");
    }
}

/** @param mixed $value */
function rehearsal_write_canon(string $path, $value): void {
    rehearsal_write($path, \Duo\Canon::encode($value));
}

function rehearsal_copy_tree(string $from, string $to): void {
    rehearsal_mkdir($to);
    foreach (new FilesystemIterator($from) as $item) {
        $target = $to . '/' . $item->getBasename();
        if ($item->isDir() && !$item->isLink()) {
            rehearsal_copy_tree($item->getPathname(), $target);
        } elseif (!copy($item->getPathname(), $target)) {
            throw new RuntimeException('rehearsal estate: cannot copy ' . $item->getPathname());
        }
    }
}

/** Every file of a directory tree, keyed by relative path, valued by sha256. */
function rehearsal_tree_hashes(string $dir): array {
    $dir = rtrim($dir, '/');
    $out = [];
    $walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($walk as $file) {
        if ($file->isFile()) {
            $out[substr($file->getPathname(), strlen($dir) + 1)] = (string) hash_file('sha256', $file->getPathname());
        }
    }
    ksort($out, SORT_STRING);
    return $out;
}

/**
 * The two defines for a state, resolved from `agent/duo.php`'s own source.
 *
 * @return array{agent_version:string,spec_version:int}
 */
function rehearsal_state_versions(string $repoRoot, string $state): array {
    $source = (string) file_get_contents($repoRoot . '/agent/duo.php');
    if (preg_match("/define\('DUO_AGENT_VERSION', '([^']+)'\)/", $source, $agent) !== 1
        || preg_match("/define\('DUO_SPEC_VERSION', ([0-9]+)\)/", $source, $spec) !== 1) {
        throw new RuntimeException('rehearsal estate: cannot resolve the agent defines from agent/duo.php');
    }
    if ($state === 'A') {
        return ['agent_version' => $agent[1], 'spec_version' => (int) $spec[1]];
    }
    // B is one MINOR release ahead, the smallest shape a release bump can take.
    // The patch component is reset the way a real minor release resets it, so
    // the string is a version an operator could actually be handed.
    $parts = explode('.', $agent[1]);
    if (count($parts) !== 3 || !ctype_digit($parts[1])) {
        throw new RuntimeException("rehearsal estate: DUO_AGENT_VERSION '{$agent[1]}' is not major.minor.patch");
    }
    $parts[1] = (string) (((int) $parts[1]) + 1);
    $parts[2] = '0';
    return ['agent_version' => implode('.', $parts), 'spec_version' => (int) $spec[1]];
}

/**
 * The platform boundary document a state ships.
 *
 * Built by restating the SHIPPED document's own bytes with the state's two
 * versions substituted, never by authoring a boundary here: a hand-written
 * boundary would stop being evidence about the document certificates actually
 * bind (`platform_sha256` covers this object verbatim).
 */
function rehearsal_platform_document(string $shippedLib, array $versions): array {
    $document = \Duo\Canon::decode(\Duo\Canon::read_file($shippedLib . '/capabilities/platform.json'));
    $document['platform']['agent_version'] = $versions['agent_version'];
    $document['platform']['spec_version'] = $versions['spec_version'];
    return $document;
}

// ---------------------------------------------------------------------------
// The estate definition. Pin shapes are the ones real sites carry: bare names
// (`site.duo.json` "manifests": ["core"]), content pins ({name, digest}), and
// the `source:"site"` override a certified site adapter needs. The three
// controls exist so the rehearsal can tell "this gate did not fire because the
// flag day is neutral" from "this gate cannot fire at all" — a rehearsal with
// no negative controls proves only that nothing was connected.
// ---------------------------------------------------------------------------

/** @return array<string,array<string,mixed>> */
function rehearsal_site_plan(): array {
    return [
        'core-only' => [
            'kind' => 'cohort',
            'pins' => ['bare'],
            'shipped' => ['core'],
            'about' => 'the smallest real site: one bare-name pin, no adapters of its own',
        ],
        'editorial' => [
            'kind' => 'cohort',
            'pins' => ['bare'],
            'shipped' => ['core', 'acf', 'classic-editor'],
            'about' => 'bare-name pins across three shipped adapters, the shape most sites carry',
        ],
        'pinned-shop' => [
            'kind' => 'cohort',
            'pins' => ['digest'],
            'shipped' => ['core', 'woocommerce'],
            'holds' => ['scope_contract', 'identity_sidecar', 'checkpoint'],
            'about' => 'exact content pins — the shape a flag day would break first if any digest moved',
        ],
        'multilingual' => [
            'kind' => 'cohort',
            'pins' => ['digest'],
            'shipped' => ['core', 'polylang', 'yoast'],
            'about' => 'a second digest-pinned set, so a single adapter cannot carry the neutrality claim',
        ],
        'certified-alpha' => [
            'kind' => 'cohort',
            'pins' => ['bare', 'site-certified'],
            'shipped' => ['core'],
            'adapter' => 'estate-forms',
            'key' => 'site-key-alpha',
            'holds' => ['scope_contract'],
            'about' => 'a certified site adapter under the first operator key, pinned by digest via `certify --pin`',
        ],
        'certified-beta' => [
            'kind' => 'cohort',
            'pins' => ['bare', 'site-override'],
            'shipped' => ['core', 'contact-form-7'],
            'adapter' => 'estate-shop',
            'key' => 'site-key-beta',
            'about' => 'a certified site adapter under a SECOND operator key, held by a source-only override pin',
        ],
        'promoted-frozen' => [
            'kind' => 'cohort',
            'pins' => ['bare', 'site-certified'],
            'shipped' => ['core'],
            'adapter' => 'estate-catalog',
            'key' => 'site-key-alpha',
            'holds' => ['snapshot_only'],
            'about' => 'a promoted site that verifies from its frozen snapshot — the path a host walks',
        ],
        'drifted-pin' => [
            'kind' => 'control',
            'pins' => ['digest-drifted'],
            'shipped' => ['core'],
            'about' => 'CONTROL: a content pin whose digest was taken against other bytes; must refuse in both states',
        ],
        'artifact-drift' => [
            'kind' => 'control',
            'pins' => ['bare'],
            'shipped' => ['core'],
            'holds' => ['foreign_artifact'],
            'about' => 'CONTROL: holds an artifact compiled against a different pin set (rule 2\'s refusal)',
        ],
    ];
}

/**
 * The option document a site carries, derived from its own loaded policy.
 *
 * The required set is not a list this fixture may hold an opinion about:
 * `RepositoryEntityParser` (:113-130) refuses a repository whose options
 * document omits any authored-exact, sub-keyed or managed option the pinned
 * adapters declare, and the estate pins different adapter sets per site. Asking
 * the policy for its own answer is what lets a site pin `classic-editor` — whose
 * two authored options this fixture had never heard of — without the fixture
 * growing a per-adapter table that would go stale on the next manifest edit.
 */
function rehearsal_options_document(\Duo\Policy $policy, string $blogname): string {
    $rows = [];
    foreach (array_keys($policy->authored_options()) as $name) {
        $rows[(string) $name] = \Duo\OptionState::absent();
    }
    foreach (array_keys($policy->sub_keyed_options()) as $name) {
        $rows[(string) $name] = \Duo\OptionState::absent();
    }
    foreach (['active_plugins', 'template', 'stylesheet'] as $managed) {
        if (($policy->option_rule($managed)['class'] ?? null) === 'managed') {
            $rows[$managed] = \Duo\OptionState::absent();
        }
    }
    $rows['blogname'] = \Duo\OptionState::present($blogname, 'yes');
    ksort($rows, SORT_STRING);
    return \Duo\Canon::encode(\Duo\OptionState::document($rows));
}

/** A site adapter manifest: declarative, plugin-blind, and deliberately tiny. */
function rehearsal_site_adapter(string $name): array {
    return [
        'name' => $name,
        'option_autoload' => 'preserve',
        'post_types' => [],
        'spec_version' => DUO_SPEC_VERSION,
        'tables' => [],
    ];
}

// ---------------------------------------------------------------------------
// Materialization (state A only). The estate is BUILT by the pre-flag agent,
// because that is what a real fleet is: sites adopted, certified and compiled
// before the upgrade nobody has run yet.
// ---------------------------------------------------------------------------

function rehearsal_materialize(string $repoRoot, string $estate, array $versions): array {
    $record = ['format' => 'duo-rehearsal-estate/v1', 'sites' => [], 'keys' => [], 'libraries' => []];

    // libs/A is the shipped library byte for byte; libs/B differs in exactly
    // one path. The suite asserts that "exactly one" — it is the premise the
    // whole digest-neutrality claim rests on, and it is cheaper to prove than
    // to trust.
    rehearsal_copy_tree($repoRoot . '/manifests', $estate . '/libs/A');
    rehearsal_copy_tree($estate . '/libs/A', $estate . '/libs/B');
    rehearsal_write_canon(
        $estate . '/libs/B/capabilities/platform.json',
        rehearsal_platform_document($repoRoot . '/manifests', rehearsal_state_versions($repoRoot, 'B'))
    );
    $record['libraries'] = [
        'A' => rehearsal_tree_hashes($estate . '/libs/A'),
        'B' => rehearsal_tree_hashes($estate . '/libs/B'),
        'shipped' => rehearsal_tree_hashes($repoRoot . '/manifests'),
    ];
    putenv('DUO_MANIFESTS_DIR=' . $estate . '/libs/A');

    // Operator keys. Two of them, because the site trust root is a registry an
    // operator grows: a fleet with one key cannot show that a certificate under
    // key alpha is unaffected by anything done under key beta.
    foreach (['site-key-alpha', 'site-key-beta', 'platform-review-key'] as $index => $keyId) {
        $keypair = sodium_crypto_sign_seed_keypair(str_repeat(chr(65 + $index), SODIUM_CRYPTO_SIGN_SEEDBYTES));
        $path = $estate . '/keys/' . $keyId . '.key';
        rehearsal_write($path, base64_encode(sodium_crypto_sign_secretkey($keypair)) . "\n");
        chmod($path, 0600);
        $record['keys'][$keyId] = base64_encode(sodium_crypto_sign_publickey($keypair));
    }

    foreach (rehearsal_site_plan() as $id => $plan) {
        $repo = $estate . '/sites/' . $id;
        $holdings = $estate . '/holdings/' . $id;
        rehearsal_mkdir($holdings);
        rehearsal_mkdir($repo . '/state');

        $pins = [];
        foreach ($plan['shipped'] as $shipped) {
            $pins[] = $shipped;
        }
        $adapter = $plan['adapter'] ?? null;
        if (is_string($adapter)) {
            rehearsal_write_canon($repo . '/adapters/' . $adapter . '.json', rehearsal_site_adapter($adapter));
            $pins[] = ['name' => $adapter, 'source' => 'site'];
        }
        rehearsal_write_canon($repo . '/site.duo.json', [
            'manifests' => $pins,
            'policy' => [
                'options' => (object) [],
                'post_types' => ['post', 'page'],
                'taxonomies' => ['category'],
            ],
            'spec_version' => DUO_SPEC_VERSION,
        ]);

        // Certification runs through the OPERATOR'S OWN command, not through
        // AdapterCertification::sign_site() directly: `duo adapter certify
        // --pin` is what a site actually holds, it registers the key in the
        // site trust root itself, and it emits the pin object — so the estate's
        // pin shapes are the command's, not this fixture's opinion of them.
        if (is_string($adapter)) {
            $certifyArgs = [
                'certify',
                $repo,
                '--name=' . $adapter,
                '--key-id=' . $plan['key'],
                '--secret-key-file=' . $estate . '/keys/' . $plan['key'] . '.key',
                '--reason=The estate operator reviewed this declarative adapter for the rehearsal fleet.',
            ];
            if (in_array('site-certified', $plan['pins'], true)) {
                $certifyArgs[] = '--pin';
            }
            $exit = rehearsal_run_certify($certifyArgs);
            if ($exit !== 0) {
                throw new RuntimeException("rehearsal estate: `duo adapter certify` failed for $id (exit $exit)");
            }
        }

        // Options are written from the site's OWN policy (see
        // rehearsal_options_document()), which means the repository has to load
        // before its state can be complete — including for the drift control,
        // whose state has to be valid so that its refusal is about the pin.
        rehearsal_write(
            $repo . '/state/options/core.json',
            rehearsal_options_document(\Duo\Policy::load($repo), 'Estate ' . $id)
        );

        // The deliberate drift, applied AFTER a good load so the control is a
        // one-value edit to a working site rather than a differently built one.
        if ($plan['kind'] === 'control' && in_array('digest-drifted', $plan['pins'], true)) {
            rehearsal_write_canon($repo . '/site.duo.json', [
                'manifests' => [['name' => 'core', 'digest' => str_repeat('d', 64)]],
                'policy' => [
                    'options' => (object) [],
                    'post_types' => ['post', 'page'],
                    'taxonomies' => ['category'],
                ],
                'spec_version' => DUO_SPEC_VERSION,
            ]);
        }

        $siteRecord = ['about' => $plan['about'], 'kind' => $plan['kind'], 'holdings' => []];
        if ($plan['kind'] !== 'control' || !in_array('digest-drifted', $plan['pins'], true)) {
            $policy = \Duo\Policy::load($repo);
            $compiled = \Duo\RepositoryCompiler::compile($repo, $policy);

            // Exact content pins, taken from the same
            // RepositoryCompiler::resolved_adapters() call `wp duo
            // manifest-pin` reads (Cli.php:2920-2923) — which is what makes a
            // moved digest visible at state B as a REFUSAL rather than as a
            // silent difference. Deliberately the SOURCE-LESS `{name, digest}`
            // short form here, while the certified sites carry the three-key
            // object `certify --pin` writes: both spellings are in the estate
            // because WP-1.1's F3 turned on exactly that difference.
            if (in_array('digest', $plan['pins'], true)) {
                $pinned = [];
                foreach (\Duo\RepositoryCompiler::resolved_adapters($policy) as $row) {
                    $pinned[] = ['digest' => (string) $row['digest'], 'name' => (string) $row['name']];
                }
                $site = \Duo\Canon::decode(\Duo\Canon::read_file($repo . '/site.duo.json'));
                $site['manifests'] = $pinned;
                rehearsal_write_canon($repo . '/site.duo.json', $site);
                $policy = \Duo\Policy::load($repo);
                $compiled = \Duo\RepositoryCompiler::compile($repo, $policy);
            }

            $compiled->write($holdings . '/artifact.json');
            rehearsal_write_canon($holdings . '/snapshot.json', $policy->export_snapshot());
            $siteRecord['holdings'][] = 'artifact';
            $siteRecord['holdings'][] = 'snapshot';

            $holds = $plan['holds'] ?? [];
            if (in_array('scope_contract', $holds, true)) {
                rehearsal_write_canon(
                    $holdings . '/scope-contract.json',
                    \Duo\ScopeContract::resolve($compiled, $policy, ['option:blogname'])
                );
                $siteRecord['holdings'][] = 'scope_contract';
            }
            if (in_array('identity_sidecar', $holds, true)) {
                // The three values `IdentityBackup::restore()` compares before
                // it opens the import transaction, and nothing else: the rest
                // of a sidecar is ledger rows a live target owns. Written here
                // so the rehearsal can answer "does the sidecar a promoted site
                // is holding still bind after the bump", which is a manifest
                // identity question and needs no database to ask.
                rehearsal_write_canon($holdings . '/identity-sidecar.json', [
                    'format' => 'duo-rehearsal-identity-binding/v1',
                    'manifest_hash' => $compiled->manifest_hash(),
                    'repository_revision' => $compiled->revision_hash(),
                    'site_hash' => $compiled->site_hash(),
                ]);
                $siteRecord['holdings'][] = 'identity_sidecar';
            }
            if (in_array('checkpoint', $holds, true)) {
                // A recovery checkpoint's prior-verifier inputs bind the
                // manifest inputs the checkpoint was taken over
                // (recovery/CheckpointBundle.php:536-545). The VALUE is minted
                // by the recovery provider, so the estate records the binding
                // the agent can compute for it and re-checks that binding after
                // the move; the two CheckpointBundle validators themselves are
                // a declared gap in the suite, with the reason.
                rehearsal_write_canon($holdings . '/checkpoint-inputs.json', [
                    'format' => 'duo-rehearsal-checkpoint-binding/v1',
                    'manifest_inputs_sha256' => $compiled->manifest_hash(),
                    'policy_sha256' => $compiled->site_hash(),
                ]);
                $siteRecord['holdings'][] = 'checkpoint';
            }
            if (in_array('foreign_artifact', $holds, true)) {
                // Rule 2's exact scenario, reproduced as the smallest thing
                // that causes it: THIS site's own repository compiled against a
                // library whose reviewed disposition for `core` carries one
                // extra sentence. `manifest_rows()` folds the disposition into
                // the row it hashes (ArtifactPolicyIdentity.php:60-115), so the
                // adapter digest and `manifest_hash` move while `site.duo.json`
                // — and therefore `site_hash` — does not. That ordering matters:
                // a foreign PIN SET would move site_hash too and refuse one
                // check earlier, as `compiled_artifact_policy_mismatch`, which
                // is a different gate answering a different question.
                $movedLib = $estate . '/scratch/moved-disposition-lib';
                rehearsal_copy_tree($estate . '/libs/A', $movedLib);
                $dispositions = \Duo\Canon::decode(\Duo\Canon::read_file($movedLib . '/dispositions.json'));
                $dispositions['manifests']['core']['reason'] =
                    (string) $dispositions['manifests']['core']['reason']
                    . ' Re-reviewed for the rehearsal estate.';
                rehearsal_write_canon($movedLib . '/dispositions.json', $dispositions);
                putenv('DUO_MANIFESTS_DIR=' . $movedLib);
                $movedPolicy = \Duo\Policy::load($repo);
                \Duo\RepositoryCompiler::compile($repo, $movedPolicy)->write($holdings . '/artifact.json');
                putenv('DUO_MANIFESTS_DIR=' . $estate . '/libs/A');
                $siteRecord['holdings'] = ['artifact'];
            }
            if (in_array('snapshot_only', $holds, true)) {
                @unlink($holdings . '/artifact.json');
                $siteRecord['holdings'] = ['snapshot'];
            }
        }
        $record['sites'][$id] = $siteRecord;
    }

    return $record;
}

/**
 * Run `duo adapter certify` in this process, at THIS state's defines.
 *
 * `AdapterCertify::boot()` resolves the two defines only when they are not
 * already defined (:1156-1166), so the command runs under the rehearsal state
 * rather than under the tree's — which is what makes a certificate minted at
 * state B a B certificate. Output is captured because this process's STDOUT is
 * the observation document.
 *
 * @param list<string> $args
 */
/**
 * Run the shipped `duo` executable and return its exit code and both streams.
 *
 * @param list<string> $args
 */
function rehearsal_run_duo(string $repoRoot, string $manifestDir, array $args): string {
    $pipes = [];
    $process = proc_open(
        array_merge([PHP_BINARY, $repoRoot . '/cli/duo'], $args),
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        ['DUO_MANIFESTS_DIR' => $manifestDir, 'PATH' => getenv('PATH') ?: '/usr/bin:/bin']
    );
    if (!is_resource($process)) {
        throw new RuntimeException('rehearsal estate: cannot start the duo executable');
    }
    fclose($pipes[0]);
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    return 'exit ' . $exit . ': ' . trim($stdout . "\n" . $stderr);
}

function rehearsal_run_certify(array $args): int {
    ob_start();
    try {
        $exit = \Duo\Orchestrator\AdapterCertify::run(array_values($args));
    } finally {
        $output = (string) ob_get_clean();
    }
    fwrite(STDERR, $output);
    return $exit;
}

// ---------------------------------------------------------------------------
// Observation. Read-only by construction: nothing here writes inside sites/ or
// holdings/, which is what lets the suite compare the second state-A pass
// against the first byte for byte and call the difference a rollback proof.
// ---------------------------------------------------------------------------

function rehearsal_observe(string $estate, string $state): array {
    $lib = $estate . '/libs/' . $state;
    putenv('DUO_MANIFESTS_DIR=' . $lib);
    $out = [
        'state' => $state,
        'agent_version' => DUO_AGENT_VERSION,
        'spec_version' => DUO_SPEC_VERSION,
        'platform_sha256' => hash('sha256', \Duo\Canon::encode(
            \Duo\Canon::decode(\Duo\Canon::read_file($lib . '/capabilities/platform.json'))['platform']
        )),
        'sites' => [],
    ];

    foreach (array_keys(rehearsal_site_plan()) as $id) {
        $out['sites'][$id] = rehearsal_observe_site($estate, $id);
    }
    return $out;
}

/** @return array<string,mixed> */
function rehearsal_observe_site(string $estate, string $id): array {
    $repo = $estate . '/sites/' . $id;
    $holdings = $estate . '/holdings/' . $id;
    $row = ['load' => 'refused', 'refusal' => '', 'adapters' => []];

    $policy = null;
    try {
        $policy = \Duo\Policy::load($repo);
        $row['load'] = 'ok';
    } catch (Throwable $t) {
        $row['refusal'] = rehearsal_scrub($t->getMessage(), $estate);
        $row['refusal_class'] = get_class($t);
        return $row;
    }

    $row['manifest_hash'] = \Duo\ArtifactPolicyIdentity::manifest_hash($policy);
    $row['site_hash'] = \Duo\ArtifactPolicyIdentity::site_hash($policy);
    // Recompiled from the repository at THIS state, which is what makes the
    // four identity values comparable across states: `manifest_hash` and
    // `site_hash` are what pins and artifacts bind, `revision_hash` is what the
    // state tree hashes to, and `artifact_hash` covers the whole compiled
    // document — including each resolved adapter's capability claim, which
    // carries the platform boundary. They do not move together, and the whole
    // point of recording all four is that the rehearsal can say which did.
    try {
        $freshlyCompiled = \Duo\RepositoryCompiler::compile($repo, $policy);
        $row['artifact_hash'] = $freshlyCompiled->artifact_hash();
        $row['revision_hash'] = $freshlyCompiled->revision_hash();
    } catch (Throwable $t) {
        $row['artifact_hash'] = 'refused';
        $row['revision_hash'] = rehearsal_scrub($t->getMessage(), $estate);
    }
    $sources = $policy->adapter_sources();
    foreach (\Duo\RepositoryCompiler::resolved_adapters($policy) as $resolved) {
        $name = (string) $resolved['name'];
        $provenance = $sources->provenance($name);
        $row['adapters'][] = [
            'capability' => is_array($resolved['capability'] ?? null)
                ? (string) ($resolved['capability']['status'] ?? '?')
                : 'none',
            'certified' => $sources->is_certified($name),
            'digest' => (string) $resolved['digest'],
            'name' => $name,
            'reason' => rehearsal_scrub((string) ($provenance['reason'] ?? ''), $estate),
            'source' => (string) ($provenance['source'] ?? 'shipped'),
        ];
    }

    // The compiled artifact a promoted site is holding.
    if (is_file($holdings . '/artifact.json')) {
        try {
            \Duo\CompiledArtifactReader::read_artifact($holdings . '/artifact.json', $policy);
            $row['artifact'] = 'verified';
        } catch (Throwable $t) {
            $row['artifact'] = 'refused';
            $row['artifact_reason'] = $t instanceof \Duo\CommandRefusalException
                ? $t->reasonCode
                : rehearsal_scrub($t->getMessage(), $estate);
        }
    }

    // The frozen policy snapshot — the path a promoted host walks, which may
    // not reopen the mutable repository.
    if (is_file($holdings . '/snapshot.json')) {
        try {
            $frozen = \Duo\Policy::from_snapshot(
                \Duo\Canon::decode(\Duo\Canon::read_file($holdings . '/snapshot.json'))
            );
            $frozenSources = $frozen->adapter_sources();
            $row['snapshot'] = 'rehydrated';
            $row['snapshot_manifest_hash'] = \Duo\ArtifactPolicyIdentity::manifest_hash($frozen);
            $row['snapshot_adapters'] = [];
            foreach (\Duo\RepositoryCompiler::resolved_adapters($frozen) as $resolved) {
                $row['snapshot_adapters'][] = [
                    'certified' => $frozenSources->is_certified((string) $resolved['name']),
                    'digest' => (string) $resolved['digest'],
                    'name' => (string) $resolved['name'],
                ];
            }
        } catch (Throwable $t) {
            $row['snapshot'] = 'refused';
            $row['snapshot_reason'] = rehearsal_scrub($t->getMessage(), $estate);
        }
    }

    if (is_file($holdings . '/scope-contract.json')) {
        try {
            $contract = \Duo\ScopeContract::from_array(
                \Duo\Canon::decode(\Duo\Canon::read_file($holdings . '/scope-contract.json'))
            );
            \Duo\ScopeContract::assert_associated(
                $contract,
                \Duo\RepositoryCompiler::compile($repo, $policy),
                $policy
            );
            $row['scope_contract'] = 'associated';
        } catch (Throwable $t) {
            $row['scope_contract'] = 'refused';
            $row['scope_contract_reason'] = rehearsal_scrub($t->getMessage(), $estate);
        }
    }

    if (is_file($holdings . '/identity-sidecar.json')) {
        $sidecar = \Duo\Canon::decode(\Duo\Canon::read_file($holdings . '/identity-sidecar.json'));
        $compiled = \Duo\RepositoryCompiler::compile($repo, $policy);
        $row['identity_sidecar'] = hash_equals((string) $sidecar['manifest_hash'], $compiled->manifest_hash())
            && hash_equals((string) $sidecar['site_hash'], $compiled->site_hash())
            && hash_equals((string) $sidecar['repository_revision'], $compiled->revision_hash())
                ? 'binds' : 'stale';
    }

    if (is_file($holdings . '/checkpoint-inputs.json')) {
        $checkpoint = \Duo\Canon::decode(\Duo\Canon::read_file($holdings . '/checkpoint-inputs.json'));
        $row['checkpoint'] = hash_equals(
            (string) $checkpoint['manifest_inputs_sha256'],
            \Duo\ArtifactPolicyIdentity::manifest_hash($policy)
        ) ? 'binds' : 'stale';
    }

    // The readiness projections every promote/deploy caller funnels through.
    try {
        $report = $policy->capability_report();
        $row['capability_report'] = ($report['ready'] ?? null) === true ? 'ready' : 'blocked';
        $row['capability_blockers'] = count((array) ($report['blockers'] ?? []));
    } catch (Throwable $t) {
        $row['capability_report'] = 'refused';
        $row['capability_report_reason'] = rehearsal_scrub($t->getMessage(), $estate);
    }

    return $row;
}

/**
 * Replace the estate root with a stable token.
 *
 * Refusal messages name absolute paths and the estate root carries a random
 * suffix, so an unscrubbed message would differ between two runs of the same
 * suite and could never be pinned. Only the root is replaced; every other byte
 * of the engine's own sentence survives, because those bytes are the evidence.
 */
function rehearsal_scrub(string $message, string $estate): string {
    return str_replace($estate, '<estate>', $message);
}

// ---------------------------------------------------------------------------
// Gate probes. Each one drives a REAL entry point at one state and records what
// came back. `gate` is the key of a `tools/platform-move-gates.json` row with
// verdict `gate`; the suite joins on it and refuses a gate that no probe
// matched unless the gate is on its reviewed gap list.
// ---------------------------------------------------------------------------

/**
 * Run one probe and record what came back.
 *
 * `$expect` is a substring of the engine's own sentence, and it must be a
 * substring the NEGATIVE answer cannot contain — the probe's whole value is
 * that it fails when the gate stops firing. Measured, not assumed: an earlier
 * revision of the provider-readiness probe expected `blocker` while its
 * negative answer was the string "no blockers", so an empty projection matched
 * and the gate read as exercised. Every `$expect` below is now either a typed
 * reason code or a clause that only the refusal path emits.
 *
 * @param callable():string $fn
 * @return array<string,mixed>
 */
function rehearsal_probe(string $gate, string $entry, string $expect, callable $fn, string $estate): array {
    $row = ['entry' => $entry, 'expect' => $expect, 'gate' => $gate];
    try {
        $row['observed'] = rehearsal_scrub($fn(), $estate);
        $row['threw'] = '';
    } catch (Throwable $t) {
        $row['observed'] = rehearsal_scrub($t->getMessage(), $estate);
        $row['threw'] = get_class($t);
    }
    $row['matched'] = $expect !== '' && str_contains($row['observed'], $expect);
    return $row;
}

/**
 * Every gate probe for one state.
 *
 * Each probe drives a real entry point over estate bytes and records the
 * engine's own sentence. Probes that must MUTATE something work on a copy under
 * `scratch/<state>/`: a probe that edited a cohort site would make the A→B→A
 * comparison meaningless, since the second state-A pass would then be reading a
 * different estate rather than the same one after a rollback.
 *
 * @return list<array<string,mixed>>
 */
function rehearsal_probes(string $estate, string $state): array {
    $lib = $estate . '/libs/' . $state;
    $other = $estate . '/libs/' . ($state === 'A' ? 'B' : 'A');
    $scratch = $estate . '/scratch/' . $state;
    rehearsal_mkdir($scratch);
    putenv('DUO_MANIFESTS_DIR=' . $lib);

    $rows = [];
    $probe = static function (string $gate, string $entry, string $expect, callable $fn) use (&$rows, $estate): void {
        $rows[] = rehearsal_probe($gate, $entry, $expect, $fn, $estate);
    };

    $certSite = $estate . '/sites/certified-alpha';
    $certName = 'estate-forms';
    $certFile = $certSite . '/adapters/certifications/' . $certName . '.json';
    $certManifest = \Duo\Canon::decode(\Duo\Canon::read_file($certSite . '/adapters/' . $certName . '.json'));
    $alphaSecret = trim((string) file_get_contents($estate . '/keys/site-key-alpha.key'));

    // --- platform axis ----------------------------------------------------

    // The hand-mixed bundle: this state's agent under the OTHER state's
    // manifests. `Adopt::install()` ships the two as one archive, so this state
    // is unreachable through the supported path — which is exactly why the
    // refusal has to be proven rather than assumed, and why a partial rollback
    // is refused instead of survived.
    $probe(
        'agent/src/Policy/ManifestDispositions.php::platform_boundary',
        'ManifestDispositions::platform_boundary(libs/other) under agent ' . DUO_AGENT_VERSION,
        'platform version disagrees with the loaded agent',
        static fn(): string => \Duo\ManifestDispositions::platform_boundary($other) === null
            ? 'no boundary' : 'boundary accepted'
    );
    $probe(
        'agent/src/Adapter/AdapterCertification.php::currentPlatform',
        'AdapterCertification::verifyFile(libs/other, certified-alpha)',
        'agent capability platform boundary disagrees with the loaded agent',
        static fn(): string => (string) (\Duo\AdapterCertification::verifyFile(
            $other, $certSite, $certName, $certManifest, $certFile
        )['disposition']['certification'] ?? '?')
    );
    // The shape gate under the boundary reader. `assert_supported()` takes its
    // facts as an argument precisely so a caller can ask the question without a
    // live target, which is what makes this reachable offline while
    // Policy::assert_supported_platform() (its only product caller) is not.
    $probe(
        'agent/src/Policy/PlatformCompatibility.php::assert_boundary_shape',
        'PlatformCompatibility::assert_supported(<boundary with no compatibility object>, <facts>)',
        'platform_boundary_invalid',
        static function (): string {
            try {
                \Duo\PlatformCompatibility::assert_supported(
                    ['site_mode' => 'single-site', 'compatibility' => []],
                    [
                        'php' => '8.3.33',
                        'database' => ['engine' => 'MariaDB', 'version' => '11.8.8'],
                        'filesystem' => [
                            'directory_separator' => '/',
                            'functions' => [
                                'chmod' => true, 'flock' => true, 'fsync' => true,
                                'lstat' => true, 'rename' => true,
                            ],
                            'os_family' => PHP_OS_FAMILY,
                        ],
                        'wordpress' => '7.1',
                        'site_mode' => 'single-site',
                    ]
                );
            } catch (\Duo\CommandRefusalException $refusal) {
                return $refusal->reasonCode;
            }
            return 'accepted';
        }
    );

    // --- the certificate boundary (the one fleet-visible consequence) ------

    $probe(
        'agent/src/Adapter/AdapterCertification.php::verifyCertificate',
        'AdapterCertification::verifyFile(libs/' . $state . ', certified-alpha)',
        $state === 'A'
            ? 'certified'
            : 'certification platform boundary disagrees with the current agent-owned platform',
        static fn(): string => (string) (\Duo\AdapterCertification::verifyFile(
            $lib, $certSite, $certName, $certManifest, $certFile
        )['disposition']['certification'] ?? '?')
    );

    // --- authority axis ---------------------------------------------------

    // The signing key RENAMED rather than deleted: an empty trust root answers
    // "no authorities are installed at all", which is a different sentence
    // about a different condition. What this gate owns is the lookup MISS in a
    // populated root — the state an operator reaches by rotating a key id.
    $unknownKey = $scratch . '/authority-unknown';
    rehearsal_copy_tree($certSite, $unknownKey);
    $authorities = \Duo\Canon::decode(\Duo\Canon::read_file($unknownKey . '/adapters/authorities.json'));
    $keptKeys = $authorities['keys'];
    $authorities['keys'] = (object) ['site-key-gamma' => $keptKeys['site-key-alpha']];
    rehearsal_write_canon($unknownKey . '/adapters/authorities.json', $authorities);
    $probe(
        'agent/src/Adapter/AdapterCertification.php::authority',
        'AdapterCertification::verifyFile(<site whose trust root no longer carries the signing key>)',
        "authority key 'site-key-alpha' is not installed in",
        static fn(): string => (string) (\Duo\AdapterCertification::verifyFile(
            $lib,
            $unknownKey,
            $certName,
            $certManifest,
            $unknownKey . '/adapters/certifications/' . $certName . '.json'
        )['disposition']['certification'] ?? '?')
    );

    $malformedRoot = $scratch . '/authority-malformed';
    rehearsal_copy_tree($certSite, $malformedRoot);
    rehearsal_write_canon($malformedRoot . '/adapters/authorities.json', [
        'format' => 'duo-adapter-authorities/v2',
        'keys' => (object) $keptKeys,
    ]);
    $probe(
        'agent/src/Adapter/AdapterCertification.php::authorityKeys',
        'AdapterCertification::verifyFile(<site whose trust root declares an unknown root format>)',
        'have an unsupported or malformed root',
        static fn(): string => (string) (\Duo\AdapterCertification::verifyFile(
            $lib,
            $malformedRoot,
            $certName,
            $certManifest,
            $malformedRoot . '/adapters/certifications/' . $certName . '.json'
        )['disposition']['certification'] ?? '?')
    );

    // The key IDENTITY moved under a valid signature. `authorityIdentity()`
    // drops only the scope lists, so `status` is inside the binding: flipping
    // it is the smallest edit that separates this gate from the scope check
    // that runs right after it, and the two refusals say different things.
    $reboundKey = $scratch . '/authority-rebound';
    rehearsal_copy_tree($certSite, $reboundKey);
    $rebound = \Duo\Canon::decode(\Duo\Canon::read_file($reboundKey . '/adapters/authorities.json'));
    $rebound['keys']['site-key-alpha']['status'] = 'revoked';
    $rebound['keys'] = (object) $rebound['keys'];
    rehearsal_write_canon($reboundKey . '/adapters/authorities.json', $rebound);
    $probe(
        'agent/src/Adapter/AdapterCertification.php::assertAuthorityBinding',
        'AdapterCertification::verifyFile(<site whose trust-root record was edited after signing>)',
        'does not match the current site authority record',
        static fn(): string => (string) (\Duo\AdapterCertification::verifyFile(
            $lib,
            $reboundKey,
            $certName,
            $certManifest,
            $reboundKey . '/adapters/certifications/' . $certName . '.json'
        )['disposition']['certification'] ?? '?')
    );

    // The frozen path re-binds a site certificate to the record its own
    // signature covers, so the shipped library winning the key-id namespace is
    // the one authority check that must still run there. Asked with a library
    // that reviews the operator's key id.
    $collidingLib = $scratch . '/colliding-lib';
    rehearsal_copy_tree($lib, $collidingLib);
    $shippedKeys = \Duo\Canon::decode(
        \Duo\Canon::read_file($collidingLib . '/capabilities/adapter-authorities.json')
    );
    $shippedKeys['keys'] = (object) ['site-key-alpha' => $keptKeys['site-key-alpha']];
    rehearsal_write_canon($collidingLib . '/capabilities/adapter-authorities.json', $shippedKeys);
    $frozenSnapshot = \Duo\Canon::decode(
        \Duo\Canon::read_file($estate . '/holdings/promoted-frozen/snapshot.json')
    );
    $frozenEnvelope = (array) ($frozenSnapshot['adapter_sources']['certificates']['estate-catalog'] ?? []);
    $frozenManifest = \Duo\Canon::decode(
        \Duo\Canon::read_file($estate . '/sites/promoted-frozen/adapters/estate-catalog.json')
    );
    $probe(
        'agent/src/Adapter/AdapterCertification.php::assertKeyIdNotPlatformOwned',
        'AdapterCertification::verifyFrozen(<library that reviews the operator key id>)',
        'is reviewed and shipped by this agent, so a site trust root cannot claim it',
        static fn(): string => (string) (\Duo\AdapterCertification::verifyFrozen(
            $collidingLib, 'estate-catalog', $frozenManifest, $frozenEnvelope
        )['disposition']['certification'] ?? '?')
    );

    // Minting under a revoked platform-rooted key. The authority resolves and
    // its scope is checked BEFORE any bundle byte is read, which is why this
    // needs no bundle directory: a revoked key cannot produce a certificate at
    // all.
    $revokedLib = $scratch . '/revoked-platform-key';
    rehearsal_copy_tree($lib, $revokedLib);
    $platformSecret = trim((string) file_get_contents($estate . '/keys/platform-review-key.key'));
    rehearsal_write_canon($revokedLib . '/capabilities/adapter-authorities.json', [
        'format' => 'duo-adapter-authorities/v1',
        'keys' => (object) ['platform-review-key' => [
            'adapter_names' => [$certName],
            'algorithm' => 'ed25519',
            'public_key' => base64_encode(sodium_crypto_sign_publickey_from_secretkey(
                (string) base64_decode($platformSecret, true)
            )),
            'scope' => 'site_adapter_certification',
            'status' => 'revoked',
            'trust_tiers' => ['declarative_manifest'],
        ]],
    ]);
    $probe(
        'agent/src/Adapter/AdapterCertification.php::sign',
        'AdapterCertification::sign(<library whose reviewed key is revoked>)',
        "authority key 'platform-review-key' is revoked and cannot certify adapters",
        static fn(): string => substr(\Duo\AdapterCertification::sign(
            $revokedLib,
            $certSite,
            $certName,
            $scratch . '/no-such-bundle',
            $certSite,
            'platform-review-key',
            $platformSecret
        ), 0, 32)
    );
    $probe(
        'agent/src/Adapter/AdapterCertification.php::sign_site',
        'AdapterCertification::sign_site(<empty operator reason>)',
        'must state its basis',
        static fn(): string => substr(\Duo\AdapterCertification::sign_site(
            $lib, $certSite, $certName, 'site-key-alpha', $alphaSecret, '   '
        ), 0, 32)
    );

    // --- manifest axis: pins, snapshots, artifacts ------------------------

    $probe(
        // WP-1.3 moved load()'s body into the private load_with() and the
        // register row moved with it; the public entry this probe CALLS is
        // unchanged, only the gate's registered site name is.
        'agent/src/Policy/Policy.php::load_with',
        'Policy::load(sites/editorial)',
        'loaded',
        static function () use ($estate): string {
            $policy = \Duo\Policy::load($estate . '/sites/editorial');
            return 'loaded ' . count($policy->manifests) . ' manifests';
        }
    );
    $probe(
        'agent/src/Policy/PinResolver.php::validate_manifest_pins',
        'Policy::load(sites/drifted-pin)',
        'digest mismatch',
        static fn(): string => 'loaded ' . count(\Duo\Policy::load($estate . '/sites/drifted-pin')->manifests)
    );
    $probe(
        'agent/src/Repository/CompiledArtifactReader.php::read_artifact',
        'CompiledArtifactReader::read_artifact(holdings/artifact-drift)',
        'compiled_artifact_manifest_mismatch',
        static function () use ($estate): string {
            $policy = \Duo\Policy::load($estate . '/sites/artifact-drift');
            try {
                \Duo\CompiledArtifactReader::read_artifact(
                    $estate . '/holdings/artifact-drift/artifact.json',
                    $policy
                );
            } catch (\Duo\CommandRefusalException $refusal) {
                return $refusal->reasonCode;
            }
            return 'verified';
        }
    );
    // The retired snapshot wire, refused BY NAME rather than read on trust:
    // v4's source record granted shipped authority without proving bytes.
    $probe(
        'agent/src/Policy/Policy.php::from_snapshot',
        'Policy::from_snapshot(<promoted-frozen snapshot restamped to the retired v4 wire>)',
        'duo-policy-snapshot/v4 is retired',
        static function () use ($estate): string {
            $snapshot = \Duo\Canon::decode(
                \Duo\Canon::read_file($estate . '/holdings/promoted-frozen/snapshot.json')
            );
            $snapshot['format'] = 'duo-policy-snapshot/v4';
            return 'rehydrated ' . count(\Duo\Policy::from_snapshot($snapshot)->manifests) . ' manifests';
        }
    );

    // --- scope contract, scoped mutation authority ------------------------

    $contractPath = $estate . '/holdings/pinned-shop/scope-contract.json';
    $probe(
        'agent/src/Policy/ScopeContract.php::assert_source',
        'ScopeContract::from_array(<contract whose source.manifest_hash is not a hash>)',
        'scope contract source.manifest_hash must be a lowercase SHA-256 hash',
        static function () use ($contractPath): string {
            $contract = \Duo\Canon::decode(\Duo\Canon::read_file($contractPath));
            $contract['source']['manifest_hash'] = 'not-a-hash';
            \Duo\ScopeContract::from_array($contract);
            return 'accepted';
        }
    );
    $probe(
        'agent/src/Policy/ScopeContract.php::assert_associated',
        'ScopeContract::assert_associated(pinned-shop contract, certified-alpha artifact)',
        'not associated with this exact compiled artifact/policy',
        static function () use ($contractPath, $estate): string {
            $foreignPolicy = \Duo\Policy::load($estate . '/sites/certified-alpha');
            \Duo\ScopeContract::assert_associated(
                \Duo\Canon::decode(\Duo\Canon::read_file($contractPath)),
                \Duo\RepositoryCompiler::compile($estate . '/sites/certified-alpha', $foreignPolicy),
                $foreignPolicy
            );
            return 'associated';
        }
    );
    $probe(
        'agent/src/Scope/ScopedApplySession.php::assert_source',
        'ScopedApplySession::validate_authority(<authority whose source omits manifest_hash>)',
        'scoped mutation authority source has an unexpected schema',
        static function (): string {
            $hash = str_repeat('a', 64);
            \Duo\ScopedApplySession::validate_authority([
                'authority_hash' => $hash,
                'code_witness_hash' => $hash,
                'format' => \Duo\ScopedApplySession::AUTHORITY_FORMAT,
                'lease' => ['artifact_hash' => $hash, 'owner' => 'rehearsal', 'session_id' => 'rehearsal'],
                'plan' => [],
                'scope_hash' => $hash,
                'selection' => [],
                'source' => ['artifact_hash' => $hash, 'state_revision_hash' => $hash],
                'target' => [],
            ]);
            return 'accepted';
        }
    );

    return $rows;
}

/**
 * The projections and orchestrator-side gates, for one state.
 *
 * These live in `cli/` and `recovery/`, which the flag day ships beside the
 * agent (`Adopt.php:147-150` tars `agent manifests recovery`; `cli/` is the
 * operator's own checkout). They are driven in-process at this state's defines
 * rather than through the `duo` executable: that shell resolves the defines
 * from the checkout's `agent/duo.php` (`AdapterCertify::boot()`:1151-1166) and
 * would therefore always run at state A, which is the one thing a rehearsal of
 * state B must not do.
 *
 * @return list<array<string,mixed>>
 */
function rehearsal_cli_probes(string $repoRoot, string $estate, string $state): array {
    $lib = $estate . '/libs/' . $state;
    $scratch = $estate . '/scratch/' . $state;
    putenv('DUO_MANIFESTS_DIR=' . $lib);

    $rows = [];
    $probe = static function (string $gate, string $entry, string $expect, callable $fn) use (&$rows, $estate): void {
        $rows[] = rehearsal_probe($gate, $entry, $expect, $fn, $estate);
    };

    // --- the operator's certify command -----------------------------------
    //
    // These two run the SHIPPED `duo` executable as a child process, and only
    // at state A. Two facts force both halves. `AdapterCertify::fail()` writes
    // its refusal to STDERR, which an in-process call cannot capture (PHP
    // cannot rebind the STDERR constant), so the message — the evidence — is
    // only reachable through a child. And that child resolves its defines from
    // the checkout's own `agent/duo.php` (:1151-1166), so it is an A-state
    // agent by construction; running it against the B library would produce the
    // mixed-bundle refusal instead of the gate under test. Neither condition
    // here is about the boundary: a manifest that does not load and a key id
    // already bound to another key refuse identically at both states, and the
    // suite requires only that a gate be exercised at SOME state.
    if ($state === 'A') {
        $brokenSite = $scratch . '/certify-broken';
        rehearsal_copy_tree($estate . '/sites/certified-beta', $brokenSite);
        $broken = \Duo\Canon::decode(\Duo\Canon::read_file($brokenSite . '/adapters/estate-shop.json'));
        unset($broken['spec_version']);
        rehearsal_write_canon($brokenSite . '/adapters/estate-shop.json', $broken);
        $probe(
            'cli/src/Adapter/AdapterCertify.php::certify',
            'duo adapter certify <site whose adapter no longer loads>',
            'does not load',
            static fn(): string => rehearsal_run_duo($repoRoot, $lib, [
                'adapter',
                'certify',
                $brokenSite,
                '--name=estate-shop',
                '--key-id=site-key-beta',
                '--secret-key-file=' . $estate . '/keys/site-key-beta.key',
                '--reason=rehearsal probe',
            ])
        );

        $collidingSite = $scratch . '/certify-key-collision';
        rehearsal_copy_tree($estate . '/sites/certified-alpha', $collidingSite);
        $probe(
            'cli/src/Adapter/AdapterCertify.php::registerAuthority',
            'duo adapter certify --key-id=<an id the site root already binds to another key>',
            'is already registered in',
            static fn(): string => rehearsal_run_duo($repoRoot, $lib, [
                'adapter',
                'certify',
                $collidingSite,
                '--name=estate-forms',
                '--key-id=site-key-alpha',
                '--secret-key-file=' . $estate . '/keys/site-key-beta.key',
                '--reason=rehearsal probe',
            ])
        );
    }

    // --- contract attestation: the most operator-visible platform move -----

    $contractSite = $estate . '/contract-site';
    $signed = \Duo\Canon::decode(\Duo\Canon::read_file($estate . '/holdings/_contract/attested.json'));

    $probe(
        'cli/src/Contract/ContractAttestation.php::currentPlatformDigest',
        'ContractAttestation::currentPlatformDigest(libs/' . $state . ')',
        'platform digest ',
        static fn(): string => 'platform digest '
            . \Duo\Orchestrator\ContractAttestation::currentPlatformDigest($lib)
    );
    $probe(
        'cli/src/Contract/ContractAttestation.php::verify',
        'ContractAttestation::verify(<contract attested at state A>, libs/' . $state . ')',
        $state === 'A' ? 'verified for ' : 'contract_attestation_platform_moved',
        static function () use ($signed, $contractSite, $lib): string {
            try {
                $proof = \Duo\Orchestrator\ContractAttestation::verify($signed, $contractSite, $lib);
            } catch (\Duo\CommandRefusalException $refusal) {
                return $refusal->reasonCode;
            }
            return 'verified for ' . (string) $proof['principal'];
        }
    );
    $probe(
        'cli/src/Contract/ContractAttestation.php::sign',
        'ContractAttestation::sign(<contract>, libs/' . $state . ')',
        'attestation binds ',
        static function () use ($estate, $contractSite, $lib): string {
            $document = \Duo\Canon::decode(\Duo\Canon::read_file($estate . '/holdings/_contract/unsigned.json'));
            $resigned = \Duo\Orchestrator\ContractAttestation::sign(
                $document,
                $contractSite,
                'contract-key',
                (string) base64_decode(
                    trim((string) file_get_contents($estate . '/keys/contract-key.key')),
                    true
                ),
                rehearsal_contract_claim(),
                $lib
            );
            return 'attestation binds ' . (string) $resigned['attestation']['platform_sha256'];
        }
    );
    $probe(
        'cli/src/Contract/ContractAttestation.php::authorities',
        'ContractAttestation::authorities(<site whose attestation trust root is not the v1 document>)',
        'contract_attestation_authorities_invalid',
        static function () use ($scratch, $contractSite): string {
            $malformed = $scratch . '/contract-authorities-malformed';
            rehearsal_copy_tree($contractSite, $malformed);
            rehearsal_write(
                \Duo\Orchestrator\ContractAttestation::authoritiesPath($malformed),
                '{"format":"duo-contract-authorities/v2","keys":{}}'
            );
            try {
                \Duo\Orchestrator\ContractAttestation::authorities($malformed);
            } catch (\Duo\CommandRefusalException $refusal) {
                return $refusal->reasonCode;
            }
            return 'accepted';
        }
    );
    $probe(
        'cli/src/Contract/ContractAttestation.php::refuseAuthorities',
        'ContractAttestation::verify(<site whose attestation trust root cannot be read>)',
        'unreadable authority bytes are a broken trust root',
        static function () use ($scratch, $contractSite, $signed, $lib): string {
            $unreadable = $scratch . '/contract-authorities-unreadable';
            rehearsal_copy_tree($contractSite, $unreadable);
            rehearsal_write(
                \Duo\Orchestrator\ContractAttestation::authoritiesPath($unreadable),
                'this is not a JSON object'
            );
            try {
                \Duo\Orchestrator\ContractAttestation::verify($signed, $unreadable, $lib);
            } catch (\Duo\CommandRefusalException $refusal) {
                return $refusal->reasonCode . ": " . $refusal->publicMessage . " — " . $refusal->remediation;
            }
            return 'verified';
        }
    );
    $probe(
        'cli/src/Contract/ContractAttestation.php::refuseUnsignedAnchor',
        'ContractAttestation::verify(<site that has provisioned no attestation trust root>)',
        'contract_attestation_unsigned_anchor',
        static function () use ($scratch, $signed, $lib): string {
            $anchorless = $scratch . '/contract-no-anchor';
            rehearsal_mkdir($anchorless);
            try {
                \Duo\Orchestrator\ContractAttestation::verify($signed, $anchorless, $lib);
            } catch (\Duo\CommandRefusalException $refusal) {
                return $refusal->reasonCode;
            }
            return 'verified';
        }
    );
    $probe(
        'cli/src/Contract/ContractAttestation.php::refuseKeyUnknown',
        'ContractAttestation::verify(<attestation naming a key the trust root does not carry>)',
        'contract_attestation_key_unknown',
        static function () use ($scratch, $contractSite, $signed, $lib): string {
            $stranger = $scratch . '/contract-key-unknown';
            rehearsal_copy_tree($contractSite, $stranger);
            $document = \Duo\Orchestrator\ContractAttestation::authorities($stranger);
            rehearsal_write(
                \Duo\Orchestrator\ContractAttestation::authoritiesPath($stranger),
                \Duo\Canon::encode([
                    'format' => \Duo\Orchestrator\ContractAttestation::AUTHORITIES_FORMAT,
                    'keys' => (object) ['contract-other-key' => $document['contract-key']],
                ])
            );
            try {
                \Duo\Orchestrator\ContractAttestation::verify($signed, $stranger, $lib);
            } catch (\Duo\CommandRefusalException $refusal) {
                return $refusal->reasonCode;
            }
            return 'verified';
        }
    );
    $probe(
        'cli/src/Contract/ContractAttestation.php::refuseKeyRevoked',
        'ContractAttestation::verify(<attestation under a revoked key>)',
        'contract_attestation_key_revoked',
        static function () use ($scratch, $contractSite, $signed, $lib): string {
            $revoked = $scratch . '/contract-key-revoked';
            rehearsal_copy_tree($contractSite, $revoked);
            $keys = \Duo\Orchestrator\ContractAttestation::authorities($revoked);
            $keys['contract-key']['status'] = 'revoked';
            rehearsal_write(
                \Duo\Orchestrator\ContractAttestation::authoritiesPath($revoked),
                \Duo\Canon::encode([
                    'format' => \Duo\Orchestrator\ContractAttestation::AUTHORITIES_FORMAT,
                    'keys' => (object) $keys,
                ])
            );
            try {
                \Duo\Orchestrator\ContractAttestation::verify($signed, $revoked, $lib);
            } catch (\Duo\CommandRefusalException $refusal) {
                return $refusal->reasonCode;
            }
            return 'verified';
        }
    );
    $probe(
        'cli/src/Contract/ContractAttestation.php::registerAuthority',
        'ContractAttestation::registerAuthority(<key id already bound to another key>)',
        'contract_attestation_key_mismatch',
        static function () use ($scratch, $contractSite): string {
            $conflict = $scratch . '/contract-register-conflict';
            rehearsal_copy_tree($contractSite, $conflict);
            $other = sodium_crypto_sign_publickey(
                sodium_crypto_sign_seed_keypair(str_repeat('Z', SODIUM_CRYPTO_SIGN_SEEDBYTES))
            );
            try {
                \Duo\Orchestrator\ContractAttestation::registerAuthority($conflict, 'contract-key', $other);
            } catch (\Duo\CommandRefusalException $refusal) {
                return $refusal->reasonCode;
            }
            return 'registered';
        }
    );
    // The contract cannot name an adapter without binding its identity: the pin
    // key set is closed at exactly {name, source, adapter_digest}
    // (ApplicationContract.php:241). Dropping the digest is the edit that turns
    // a contract into one that names an adapter it cannot prove.
    $probe(
        'cli/src/Contract/ApplicationContract.php::validateDeclarations',
        'ApplicationContract::validate(<contract whose manifest pin drops adapter_digest>)',
        'manifest_pins[0]',
        static function () use ($estate): string {
            $document = \Duo\Canon::decode(\Duo\Canon::read_file($estate . '/holdings/_contract/unsigned.json'));
            $document['declarations']['manifest_pins'] = [['name' => 'core', 'source' => 'shipped']];
            \Duo\Orchestrator\ApplicationContract::validate(
                \Duo\Orchestrator\ApplicationContract::withDigest($document)
            );
            return 'accepted';
        }
    );

    $probe(
        'cli/src/Contract/ContractProjection.php::validateFacts',
        'ContractProjection::generate(<facts with no registry_sha256>)',
        "projection facts are missing 'registry_sha256'",
        static function () use ($estate): string {
            $document = \Duo\Canon::decode(\Duo\Canon::read_file($estate . '/holdings/_contract/unsigned.json'));
            try {
                \Duo\Orchestrator\ContractProjection::generate(
                    $document,
                    ['operations' => ['apply'], 'surfaces' => []],
                    [],
                    [],
                    '2026-08-24T00:00:00Z'
                );
            } catch (\Duo\CommandRefusalException $refusal) {
                return $refusal->reasonCode . ': ' . $refusal->publicMessage;
            }
            return 'projected';
        }
    );

    // --- refresh, deploy and rollback projections -------------------------

    $probe(
        'cli/src/Transport/CodeDeploy.php::dispositionBlockers',
        'CodeDeploy::dispositionBlockers(<compiled summary carrying certified-beta\'s site adapter>)',
        // Both branches of this gate, one per state, and the difference IS the
        // flag day: at A the adapter carries a signed-but-unpinned claim and
        // the blocker is about the pin shape; at B the certificate is withdrawn,
        // so the claim is gone and the blocker becomes the missing-claim one.
        $state === 'A'
            ? 'the repository pin does not bind both source'
            : 'no reviewed capability claim is bound to this compiled adapter',
        static function () use ($estate, $lib): string {
            putenv('DUO_MANIFESTS_DIR=' . $lib);
            $policy = \Duo\Policy::load($estate . '/sites/certified-beta');
            $summary = ['resolved_adapters' => []];
            foreach (\Duo\RepositoryCompiler::resolved_adapters($policy) as $row) {
                $summary['resolved_adapters'][] = [
                    'name' => $row['name'],
                    'disposition' => is_array($row['disposition'] ?? null) ? $row['disposition'] : ['source' => 'site'],
                    'capability' => $row['capability'] ?? null,
                ];
            }
            $blockers = \Duo\Orchestrator\CodeDeploy::dispositionBlockers($summary);
            return $blockers === []
                ? 'no blockers'
                : implode('; ', array_map(
                    static fn(array $row): string => $row['name'] . ': ' . $row['reason'],
                    $blockers
                ));
        }
    );
    $probe(
        'cli/src/Refresh/RefreshFieldDiff.php::normalizePolicyProjection',
        'RefreshFieldDiff::normalizePolicyProjection(<projection whose manifest_hash is not a hash>)',
        'policy projection',
        static function (): string {
            \Duo\Orchestrator\RefreshFieldDiff::normalizePolicyProjection([
                'derived_post_fields' => [],
                'format' => 'duo-policy-projection/v1',
                'manifest_hash' => 'not-a-hash',
                'projection_hash' => str_repeat('a', 64),
                'resolved_adapters_sha256' => str_repeat('b', 64),
                'state_site_hash' => str_repeat('c', 64),
            ]);
            return 'accepted';
        }
    );
    $probe(
        'cli/src/Recovery/ScopedRollbackProfile.php::claimFields',
        'ScopedRollbackProfile::claimFields(<scoped plan with no resolved adapter identity>)',
        'scoped plan has no resolved adapter identity',
        static function (): string {
            $hash = str_repeat('a', 64);
            $scopeHash = str_repeat('b', 64);
            \Duo\Orchestrator\ScopedRollbackProfile::claimFields(
                [
                    'artifact_hash' => $hash,
                    'format' => 'duo-scoped-plan/v1',
                    'scope' => [
                        'format' => 'duo-scope-contract/v1',
                        'scope_hash' => $scopeHash,
                        'source_artifact_hash' => $hash,
                    ],
                    'selected_actions' => [],
                    'selected_surfaces' => [],
                    'target' => [
                        'ledger_map_root' => $hash,
                        'protected_ledger_map_root' => $hash,
                        'protected_out_of_scope_root' => $hash,
                        'selected_before_root' => $hash,
                        'selected_ledger_map_root' => $hash,
                        'target_observation_hash' => $hash,
                    ],
                ],
                ['claim_ttl_seconds' => 300, 'encryption_key_id' => 'rehearsal-key', 'retention_seconds' => 86400],
                'rehearsal-owner',
                $scopeHash,
                false,
                '2026-08-24T00:00:00Z'
            );
            return 'accepted';
        }
    );

    return $rows;
}

/** The reviewer claim every rehearsal attestation carries. */
function rehearsal_contract_claim(): array {
    return [
        'approving_principal' => 'Rehearsal Ops',
        'expires_at' => '2099-01-01T00:00:00Z',
        'policy_version' => '2026-08',
        'reason' => 'attested before the flag day, so the rehearsal can ask what the bump does to it',
    ];
}

/**
 * The readiness projections, for one state.
 *
 * These are the gates that never throw: a moved disposition or boundary turns
 * an allowed promotion into a blocked one by returning a row, so the evidence
 * here is a row's content rather than a refusal message. `duo-agency-cpt` is
 * the shipped `excluded` fixture (manifests/dispositions.json), which is what
 * lets the blocker projections be driven from the real reviewed library rather
 * than from an invented disposition.
 *
 * @return list<array<string,mixed>>
 */
function rehearsal_registry_probes(string $estate, string $state): array {
    $lib = $estate . '/libs/' . $state;
    $scratch = $estate . '/scratch/' . $state;
    putenv('DUO_MANIFESTS_DIR=' . $lib);

    $rows = [];
    $probe = static function (string $gate, string $entry, string $expect, callable $fn) use (&$rows, $estate): void {
        $rows[] = rehearsal_probe($gate, $entry, $expect, $fn, $estate);
    };

    $excluded = $scratch . '/excluded-pin';
    rehearsal_mkdir($excluded . '/state');
    rehearsal_write_canon($excluded . '/site.duo.json', [
        'manifests' => ['core', 'duo-agency-cpt'],
        'policy' => [
            'options' => (object) [],
            'post_types' => ['post', 'page'],
            'taxonomies' => ['category'],
        ],
        'spec_version' => DUO_SPEC_VERSION,
    ]);
    rehearsal_write(
        $excluded . '/state/options/core.json',
        rehearsal_options_document(\Duo\Policy::load($excluded), 'Excluded')
    );

    // The projection every readiness caller funnels through binds each row to
    // the boundary this state ships, so at state B the rows carry B's agent
    // version — the reason a claim cannot outlive the boundary it was made on.
    $probe(
        'agent/src/Adapter/AdapterRegistry.php::report',
        'Policy::capability_report(sites/editorial) rows under agent ' . DUO_AGENT_VERSION,
        'rows bind agent ' . DUO_AGENT_VERSION,
        static function () use ($estate): string {
            $report = \Duo\Policy::load($estate . '/sites/editorial')->capability_report();
            $rows = (array) ($report['manifests'] ?? []);
            return 'rows bind agent ' . (string) ($report['platform']['agent_version'] ?? '?')
                . ' across ' . count($rows) . ' claims';
        }
    );
    $probe(
        'agent/src/Adapter/AdapterRegistry.php::capability_report',
        'Policy::capability_report(<site pinning the reviewed-excluded adapter>)',
        'ready=false',
        static function () use ($excluded): string {
            $report = \Duo\Policy::load($excluded)->capability_report();
            return 'ready=' . (($report['ready'] ?? null) === true ? 'true' : 'false')
                . ' blockers=' . count((array) ($report['blockers'] ?? []));
        }
    );
    $probe(
        'agent/src/Adapter/AdapterRegistry.php::certification_readiness_blockers',
        'Policy::certification_readiness_blockers(<site pinning the reviewed-excluded adapter>)',
        'duo-agency-cpt',
        static function () use ($excluded): string {
            $blockers = \Duo\Policy::load($excluded)->certification_readiness_blockers();
            return $blockers === []
                ? 'no blockers'
                : implode('; ', array_map(
                    static fn(array $row): string => (string) ($row['name'] ?? '?')
                        . '=' . (string) ($row['status'] ?? '?'),
                    $blockers
                ));
        }
    );
    // A provider action whose manifest-shipped code is NOT in the library. The
    // library is the one this state ships minus one provider file, which is
    // also why the site here pins by bare name: deleting the file moves that
    // manifest's row (rule 2), so a content pin would refuse at load and the
    // probe would never reach the projection it is about.
    $probe(
        'agent/src/Adapter/AdapterRegistry.php::provider_readiness_blockers',
        'Policy::provider_readiness_blockers(<action whose manifest-shipped provider file is missing>)',
        'provider_code_unavailable',
        static function () use ($lib, $scratch): string {
            $providerLib = $scratch . '/provider-missing-lib';
            rehearsal_copy_tree($lib, $providerLib);
            @unlink($providerLib . '/providers/woocommerce-cache.php');
            $providerSite = $scratch . '/provider-missing-site';
            rehearsal_mkdir($providerSite . '/state');
            rehearsal_write_canon($providerSite . '/site.duo.json', [
                'manifests' => ['core', 'woocommerce'],
                'policy' => [
                    'options' => (object) [],
                    'post_types' => ['post', 'page'],
                    'taxonomies' => ['category'],
                ],
                'spec_version' => DUO_SPEC_VERSION,
            ]);
            putenv('DUO_MANIFESTS_DIR=' . $providerLib);
            try {
                $blockers = \Duo\Policy::load($providerSite)->provider_readiness_blockers([[
                    'kind' => 'provider',
                    'provider' => 'woocommerce-cache',
                    'capability' => 'flush_caches',
                    'args' => [],
                    'triggers' => ['post:product'],
                ]]);
            } finally {
                putenv('DUO_MANIFESTS_DIR=' . $lib);
            }
            return $blockers === []
                ? 'the projection returned no rows'
                : implode('; ', array_map(
                    static fn(array $row): string => (string) ($row['code'] ?? '?')
                        . ' on ' . (string) ($row['manifest'] ?? '?'),
                    $blockers
                ));
        }
    );

    return $rows;
}

/**
 * WP-1.1's brick, reproduced as a RECORDED EXPECTATION rather than by checking
 * out the engine that had it.
 *
 * The fix is merged, so the pre-fix behaviour cannot be observed directly. What
 * CAN be observed, on the identical fixture, is every input the pre-fix routing
 * consumed:
 *
 *   - the exception the platform comparison raises (`verifyFile()` is the exact
 *     call `scan_site_source()` makes, AdapterSources.php:1115-1122), and
 *   - whether the PRE-FIX catch set would have caught it. Before WP-1.1 that
 *     set was one class, `SupersededSiteAdapterCertificate`; anything else
 *     escaped the guarded closure, and `guarded()` re-throws at SCOPE_SOURCE
 *     when not collecting (AdapterSources.php:1953-1962), so the whole site
 *     source refused and `Policy::load()` propagated it uncaught
 *     (Policy.php:400).
 *
 * So the reproduction records: the message, the class, whether the pre-fix
 * catch set covers it, and — as the control that keeps this from being an
 * argument about ordering — a FORGED companion driven through `Policy::load()`
 * on the same site at the same state, which still refuses the whole source
 * today. That is the refusal channel the pre-fix exception took, observed
 * rather than asserted.
 *
 * @return array<string,mixed>
 */
function rehearsal_prefix_reproduction(string $estate, string $state): array {
    $lib = $estate . '/libs/' . $state;
    $scratch = $estate . '/scratch/' . $state;
    putenv('DUO_MANIFESTS_DIR=' . $lib);
    $site = $estate . '/sites/certified-alpha';
    $name = 'estate-forms';
    $manifest = \Duo\Canon::decode(\Duo\Canon::read_file($site . '/adapters/' . $name . '.json'));
    $certificate = $site . '/adapters/certifications/' . $name . '.json';

    $out = ['state' => $state];
    try {
        \Duo\AdapterCertification::verifyFile($lib, $site, $name, $manifest, $certificate);
        $out['verify'] = 'accepted';
        $out['verify_class'] = '';
        $out['caught_by_prefix_catch_set'] = false;
        $out['typed_withdrawal'] = false;
    } catch (Throwable $t) {
        $out['verify'] = rehearsal_scrub($t->getMessage(), $estate);
        $out['verify_class'] = get_class($t);
        // The pre-fix catch set, stated as the class it actually was.
        $out['caught_by_prefix_catch_set'] = $t instanceof \Duo\SupersededSiteAdapterCertificate;
        $out['typed_withdrawal'] = $t instanceof \Duo\StalePlatformSiteAdapterCertificate;
    }

    // What the site does TODAY under the same condition, through the product
    // path an operator actually runs.
    try {
        $policy = \Duo\Policy::load($site);
        $sources = $policy->adapter_sources();
        $out['load'] = 'ok';
        $out['certified'] = $sources->is_certified($name);
        $out['reason'] = rehearsal_scrub((string) ($sources->provenance($name)['reason'] ?? ''), $estate);
    } catch (Throwable $t) {
        $out['load'] = 'refused';
        $out['certified'] = false;
        $out['reason'] = rehearsal_scrub($t->getMessage(), $estate);
    }

    // The control: a forged companion under the identical conditions. The
    // signature is checked before the platform comparison, so this can never
    // reach the typed withdrawal — it takes the whole-source refusal the
    // pre-fix stale boundary took.
    $forgedSite = $scratch . '/prefix-forged';
    rehearsal_copy_tree($site, $forgedSite);
    $forged = \Duo\Canon::decode(\Duo\Canon::read_file($forgedSite . '/adapters/certifications/' . $name . '.json'));
    $forged['statement']['bundle']['git_revision'] = str_repeat('f', 40);
    rehearsal_write_canon($forgedSite . '/adapters/certifications/' . $name . '.json', $forged);
    try {
        \Duo\Policy::load($forgedSite);
        $out['forged_source'] = 'loaded';
    } catch (Throwable $t) {
        $out['forged_source'] = 'refused';
        $out['forged_reason'] = rehearsal_scrub($t->getMessage(), $estate);
    }

    return $out;
}

/**
 * The remedy half of the transition: re-certify at B, then look at what a
 * rollback to A does to a certificate minted while the fleet was at B.
 *
 * This is the ONLY durable act either observation pass performs, and it happens
 * on a COPY under `scratch/`, never on a cohort site — the cohort has to be
 * untouched for the second state-A pass to mean "the same estate after a
 * rollback" rather than "a different estate".
 *
 * The plan's rollback section says certificates are what must be re-done
 * symmetrically. This measures both directions of that sentence: an operator
 * CAN re-certify at B (the remedy is invocable on the degraded fleet), and a
 * certificate minted at B is withdrawn again after the rollback, because it
 * binds B's boundary exactly as the original bound A's.
 *
 * @return array<string,mixed>
 */
function rehearsal_remedy(string $estate, string $state): array {
    $lib = $estate . '/libs/' . $state;
    putenv('DUO_MANIFESTS_DIR=' . $lib);
    $remedied = $estate . '/scratch/remedied-at-B';
    $out = ['state' => $state];

    if ($state === 'B') {
        if (!is_dir($remedied)) {
            rehearsal_copy_tree($estate . '/sites/certified-alpha', $remedied);
        }
        $out['certify_exit'] = rehearsal_run_certify([
            'certify',
            $remedied,
            '--name=estate-forms',
            '--key-id=site-key-alpha',
            '--secret-key-file=' . $estate . '/keys/site-key-alpha.key',
            '--reason=Re-certified against the post-flag boundary during the rehearsal.',
            '--pin',
        ]);
    }
    if (!is_dir($remedied)) {
        // The first state-A pass runs before anything was remedied; saying so
        // is the honest answer, and it is what makes the second pass's row
        // about the rollback rather than about an absent fixture.
        $out['observed'] = 'not yet remedied';
        return $out;
    }

    try {
        $policy = \Duo\Policy::load($remedied);
        $sources = $policy->adapter_sources();
        $out['observed'] = 'loaded';
        $out['certified'] = $sources->is_certified('estate-forms');
        $out['reason'] = rehearsal_scrub((string) ($sources->provenance('estate-forms')['reason'] ?? ''), $estate);
        foreach (\Duo\RepositoryCompiler::resolved_adapters($policy) as $row) {
            if ((string) $row['name'] === 'estate-forms') {
                $out['digest'] = (string) $row['digest'];
            }
        }
    } catch (Throwable $t) {
        $out['observed'] = 'refused';
        $out['certified'] = false;
        $out['reason'] = rehearsal_scrub($t->getMessage(), $estate);
    }

    // And the symmetric re-mint, on its own copy: after the rollback the
    // operator re-runs the same command and the adapter is certified again.
    if ($state === 'A' && ($out['certified'] ?? null) === false) {
        $reRemedied = $estate . '/scratch/re-certified-at-A';
        if (!is_dir($reRemedied)) {
            rehearsal_copy_tree($remedied, $reRemedied);
        }
        $out['re_certify_exit'] = rehearsal_run_certify([
            'certify',
            $reRemedied,
            '--name=estate-forms',
            '--key-id=site-key-alpha',
            '--secret-key-file=' . $estate . '/keys/site-key-alpha.key',
            '--reason=Re-certified against the restored pre-flag boundary during the rehearsal.',
            '--pin',
        ]);
        try {
            $out['re_certified'] = \Duo\Policy::load($reRemedied)->adapter_sources()->is_certified('estate-forms');
        } catch (Throwable $t) {
            $out['re_certified'] = false;
            $out['re_certify_reason'] = rehearsal_scrub($t->getMessage(), $estate);
        }
    }

    return $out;
}

/**
 * The contract site and its attestation, minted at state A.
 *
 * Reuses the shipped contract fixture rather than authoring a contract here:
 * `ApplicationContract::validate()` is the grammar, and a hand-built document
 * would be testing this file's reading of it.
 */
function rehearsal_materialize_contract(string $repoRoot, string $estate): array {
    $site = $estate . '/contract-site';
    rehearsal_mkdir($site . '/.duo/contract');
    rehearsal_write($site . '/site.duo.json', \Duo\Canon::encode([
        'envs' => ['production' => ['transport' => 'local']],
    ]));

    $document = json_decode(
        (string) file_get_contents($repoRoot . '/sandbox/tests/fixtures/contract/contract-unbound.json'),
        true
    );
    if (!is_array($document)) {
        throw new RuntimeException('rehearsal estate: the shipped contract fixture is not a JSON object');
    }
    // The fixture ships one deliberately unreviewed external effect so the
    // review gate has something to refuse; attestation is a question about a
    // contract that already passed review, so resolve it as a reviewer would.
    foreach (($document['declarations']['external_effects'] ?? []) as $index => $effect) {
        if (($effect['decided_by'] ?? null) === \Duo\Orchestrator\ApplicationContract::UNREVIEWED_DECIDED_BY) {
            $document['declarations']['external_effects'][$index]['decided_by'] = 'operator';
        }
    }
    $document = \Duo\Orchestrator\ApplicationContract::withDigest($document);
    \Duo\Orchestrator\ApplicationContract::validate($document);

    $keypair = sodium_crypto_sign_seed_keypair(str_repeat('C', SODIUM_CRYPTO_SIGN_SEEDBYTES));
    $secret = sodium_crypto_sign_secretkey($keypair);
    rehearsal_write($estate . '/keys/contract-key.key', base64_encode($secret) . "\n");
    chmod($estate . '/keys/contract-key.key', 0600);
    \Duo\Orchestrator\ContractAttestation::registerAuthority(
        $site,
        'contract-key',
        sodium_crypto_sign_publickey($keypair)
    );

    $signed = \Duo\Orchestrator\ContractAttestation::sign(
        $document,
        $site,
        'contract-key',
        $secret,
        rehearsal_contract_claim(),
        $estate . '/libs/A'
    );
    rehearsal_write_canon($estate . '/holdings/_contract/unsigned.json', $document);
    rehearsal_write_canon($estate . '/holdings/_contract/attested.json', $signed);

    return [
        'attested_platform_sha256' => (string) $signed['attestation']['platform_sha256'],
        'key_id' => 'contract-key',
    ];
}

// ---------------------------------------------------------------------------
// Entry point. One state per process, because a state IS a pair of defines.
// ---------------------------------------------------------------------------

if (PHP_SAPI !== 'cli' || !isset($argv[0]) || realpath($argv[0]) !== __FILE__) {
    fwrite(STDERR, "spec_migration_estate.php is a child entry point, not a library\n");
    exit(2);
}
if (($argc ?? 0) !== 4 || !in_array($argv[2], ['A', 'B'], true)
    || !in_array($argv[3], ['materialize', 'observe'], true)) {
    fwrite(STDERR, 'usage: php ' . basename(__FILE__) . " <estate-root> <A|B> <materialize|observe>\n");
    exit(2);
}

$rehearsalRepoRoot = dirname(__DIR__, 4);
// Absolute by construction: `MediaPayloadAuthority::readArtifactDocument()`
// refuses a relative artifact path, so a relative estate root would turn every
// compiled-artifact observation into `compiled_artifact_invalid` — a fixture
// defect that reads exactly like the refusal this rehearsal exists to measure.
rehearsal_mkdir(rtrim($argv[1], '/'));
$rehearsalEstate = (string) realpath(rtrim($argv[1], '/'));
$rehearsalState = $argv[2];
$rehearsalMode = $argv[3];
$rehearsalVersions = rehearsal_state_versions($rehearsalRepoRoot, $rehearsalState);
define('DUO_AGENT_VERSION', $rehearsalVersions['agent_version']);
define('DUO_SPEC_VERSION', $rehearsalVersions['spec_version']);

$rehearsalClassmap = require $rehearsalRepoRoot . '/agent/duo-classmap.php';
if (!is_array($rehearsalClassmap)) {
    fwrite(STDERR, "spec_migration_estate.php: agent/duo-classmap.php did not return a map\n");
    exit(2);
}
$rehearsalFiles = [];
foreach ($rehearsalClassmap as $rehearsalPath) {
    $rehearsalFiles[basename((string) $rehearsalPath, '.php')] = (string) $rehearsalPath;
}
// The engine surface a real command reaches, named file by file the way every
// other WordPress-free entry point in this tree does it (rule 1: each file
// requires its own dependencies, and there is no autoloader on the product
// path).
foreach ([
    'Uuid', 'OrderPreserved', 'Canon', 'OptionState', 'UserMetaState', 'Db', 'Secrets', 'PersonalData',
    'CommandRefusal', 'ManifestDispositions', 'PlatformCompatibility', 'AdapterSources', 'TargetProbe',
    'AdapterRegistry', 'NativeActions', 'ReferenceRules', 'Policy', 'Providers', 'Ledger', 'Deletion',
    'JsonRefs', 'PlainData', 'StructuredValue', 'SidebarState', 'Snapshot', 'RepositoryAuthorization',
    'CodeCompatibility', 'Code', 'CodeStateContract', 'ReferenceGraph', 'RepositoryCompiler',
    'ArtifactPolicyIdentity', 'CompiledArtifactReader', 'ScopeClosure', 'CanonicalSurfaces', 'ScopeContract',
    'ScopedStateOverlay', 'ScopedApplySession', 'AdapterCertification', 'PinResolver',
] as $rehearsalClass) {
    $rehearsalFile = $rehearsalFiles[$rehearsalClass] ?? null;
    if (!is_string($rehearsalFile)) {
        fwrite(STDERR, "spec_migration_estate.php: $rehearsalClass.php is absent from agent/duo-classmap.php\n");
        exit(2);
    }
    require_once $rehearsalRepoRoot . '/agent/' . $rehearsalFile;
}
require_once $rehearsalRepoRoot . '/cli/src/Adapter/AdapterCertify.php';
require_once $rehearsalRepoRoot . '/cli/src/Contract/ApplicationContract.php';
require_once $rehearsalRepoRoot . '/cli/src/Contract/ContractAttestation.php';
require_once $rehearsalRepoRoot . '/cli/src/Contract/ContractProjection.php';
require_once $rehearsalRepoRoot . '/cli/src/Transport/CodeDeploy.php';
require_once $rehearsalRepoRoot . '/cli/src/Refresh/RefreshFieldDiff.php';
require_once $rehearsalRepoRoot . '/recovery/rollback-control.php';
require_once $rehearsalRepoRoot . '/cli/src/Recovery/ScopedRollbackProfile.php';

// The two WordPress seams a policy load can touch. They REFUSE rather than
// answer: an estate that reached a target would stop being an offline
// rehearsal, and a silent stub would hide the reach.
if (!function_exists('get_option')) {
    function get_option($name, $default = false) {
        throw new RuntimeException("rehearsal estate: target contact get_option($name)");
    }
}
if (!function_exists('wp_upload_dir')) {
    function wp_upload_dir(...$args): array {
        throw new RuntimeException('rehearsal estate: target contact wp_upload_dir');
    }
}
if (!function_exists('apply_filters')) {
    function apply_filters($tag, $value = null) {
        return $value;
    }
}
if (!function_exists('is_multisite')) {
    function is_multisite(): bool {
        return false;
    }
}

if (!function_exists('sodium_crypto_sign_seed_keypair')) {
    fwrite(STDERR, "spec_migration_estate.php: the PHP sodium extension is required\n");
    exit(2);
}

try {
    if ($rehearsalMode === 'materialize') {
        $document = rehearsal_materialize($rehearsalRepoRoot, $rehearsalEstate, $rehearsalVersions);
        $document['contract'] = rehearsal_materialize_contract($rehearsalRepoRoot, $rehearsalEstate);
    } else {
        $document = rehearsal_observe($rehearsalEstate, $rehearsalState);
        $document['gates'] = array_merge(
            rehearsal_probes($rehearsalEstate, $rehearsalState),
            rehearsal_cli_probes($rehearsalRepoRoot, $rehearsalEstate, $rehearsalState),
            rehearsal_registry_probes($rehearsalEstate, $rehearsalState)
        );
        $document['prefix_reproduction'] = rehearsal_prefix_reproduction($rehearsalEstate, $rehearsalState);
        $document['remedy'] = rehearsal_remedy($rehearsalEstate, $rehearsalState);
    }
} catch (Throwable $rehearsalFailure) {
    fwrite(STDERR, 'spec_migration_estate.php: ' . get_class($rehearsalFailure) . ': '
        . $rehearsalFailure->getMessage() . "\n" . $rehearsalFailure->getTraceAsString() . "\n");
    exit(1);
}

fwrite(STDOUT, \Duo\Canon::encode($document) . "\n");
exit(0);
