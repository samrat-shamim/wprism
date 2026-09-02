<?php
declare(strict_types=1);

namespace WPrism\Orchestrator;

/**
 * Bootstrap WPrism onto a pre-existing WordPress target through an explicitly
 * authorized adoption transport.
 *
 * The host-side CLI assembles the source capsules into agent/adapter-library
 * in disposable local staging. Only that closed agent plus recovery/ travel
 * to the target. The agent and its embedded adapter bytes therefore publish
 * as one atomic directory. A fresh wp-cli process verifies the embedded
 * library before this operation can report green.
 */
final class Adopt {
    private const SEED = [
        'manifests' => ['core'],
        'policy' => [
            'options' => [],
            'post_meta' => [],
            'term_meta' => [],
            'post_types' => ['post', 'page', 'attachment'],
            'taxonomies' => ['category', 'post_tag'],
        ],
        // WP-4.12: the seed declares the version this agent publishes. A
        // literal here is the same restatement AGENTS.md rule 8 governs for
        // platform.json — it moved 2 -> 3 with the two defines, in the same
        // commit, because a seed one version behind would hand every newly
        // adopted site a repository the adopting agent's own compiler then
        // judges against the window instead of matching exactly.
        'spec_version' => 3,
    ];

    /**
     * The one first-contact repository seed used on both sides of adoption.
     *
     * Connect publishes these bytes locally before assessment, while install
     * publishes them at the target before init. Keeping both readers on this
     * method prevents the historical split where `adopt` created a remote
     * seed but the immediately recommended `assess` refused because no local
     * site.wprism.json existed.
     *
     * @return array<string,mixed>
     */
    public static function repositorySeed(): array {
        return self::SEED;
    }

    public static function repositorySeedBytes(): string {
        $seed = json_encode(self::repositorySeed(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($seed)) {
            throw new \RuntimeException('could not encode adoption site-repo seed');
        }
        return $seed . "\n";
    }

    /**
     * Root-anchored local-only paths required before a connection registry is
     * written. InitRepositoryBoundary::ensure_gitignore() owns the same list
     * on the target; this is the pre-init source-workspace projection.
     */
    public static function repositoryGitignoreBytes(): string {
        return <<<'IGNORE'
# WPrism local publication and environment artifacts
/.tmp*
/.wprism/*
!/.wprism/authority/
/.wprism/authority/*
!/.wprism/authority/authorities.json
/.wprism-envs.json
/.wprism-init-code-*
/.wprism-init-attempt
/.wprism-init-attempt.next
/.*.wprism-init-*
/state.capture.lock
/state.capture-staging/
/state.capture-backup/
/state.capture-intent
/state.capture-receipt
/state.capture-intent.tmp.*
/state.capture-receipt.tmp.*
/state.capture-intent.previous
/state.capture-intent.next
/state.capture-receipt.previous
/state.capture-receipt.next
/.wprism-env-values.json
IGNORE
            . "\n";
    }

    /**
     * @return array{exit:int, phase:string, stdout:string, stderr:string, version:string, repo_created:bool}
     */
    public static function install(
        AdoptionTransport $transport,
        string $sourceRoot,
        ?string $rollbackKeyId = null,
        ?string $rollbackPublicKey = null,
        ?array $recoveryConfig = null,
        ?BootstrapEligibilityReport $eligibility = null,
        ?callable $postSwapVerifier = null
    ): array {
        $agentDir = rtrim($sourceRoot, '/') . '/agent';
        $adapterPackagesDir = rtrim($sourceRoot, '/') . '/adapter-packages';
        $platformLibraryDir = rtrim($sourceRoot, '/') . '/platform/adapter-library';
        $assembler = rtrim($sourceRoot, '/') . '/tools/src/AdapterLibraryAssembler.php';
        $version = self::agentVersion($agentDir . '/wprism.php');
        $canonical = rtrim($sourceRoot, '/') . '/recovery/CanonicalJson.php';
        $atomic = rtrim($sourceRoot, '/') . '/recovery/AtomicStore.php';
        $protocolLock = rtrim($sourceRoot, '/') . '/recovery/ProtocolLock.php';
        $providerClient = rtrim($sourceRoot, '/') . '/recovery/ProviderClient.php';
        $runtime = rtrim($sourceRoot, '/') . '/recovery/rollback-control.php';
        $executor = rtrim($sourceRoot, '/') . '/recovery/RecoveryExecutor.php';
        $checkpoint = rtrim($sourceRoot, '/') . '/recovery/CheckpointBundle.php';
        $codeRelease = rtrim($sourceRoot, '/') . '/recovery/CodeRelease.php';
        $uploadBundle = rtrim($sourceRoot, '/') . '/recovery/UploadBundle.php';
        $effectBundle = rtrim($sourceRoot, '/') . '/recovery/EffectBundle.php';
        if ($version === null || !is_file($agentDir . '/wprism-loader.php')
            || !is_dir($adapterPackagesDir) || !is_dir($platformLibraryDir) || !is_file($assembler)
            || !is_file($canonical) || !is_file($atomic) || !is_file($protocolLock) || !is_file($providerClient)
            || !is_file($runtime) || !is_file($executor) || !is_file($checkpoint) || !is_file($codeRelease)
            || !is_file($uploadBundle) || !is_file($effectBundle)) {
            return self::failure(
                'local artifact',
                'WPrism source tree is incomplete: expected agent/, adapter-packages/, platform/adapter-library/, and the complete recovery runtime',
                $version ?? 'unknown'
            );
        }
        if (($rollbackKeyId === null) !== ($rollbackPublicKey === null)) {
            return self::failure('local artifact', 'rollback key id and public key must be supplied together', $version);
        }
        if ($recoveryConfig !== null && $rollbackKeyId === null) {
            return self::failure('local artifact', 'recovery configuration requires a rollback verification key', $version);
        }

        if ($eligibility !== null) {
            try {
                $eligibility->assertMatches($transport);
                $freshEligibility = BootstrapEligibilityReport::inspect(
                    $transport,
                    'transaction',
                    'local',
                    $sourceRoot
                );
                if (!$freshEligibility->ready()) {
                    throw new \RuntimeException('target eligibility changed before staging');
                }
                $muDir = $freshEligibility->muDir();
            } catch (\Throwable) {
                return self::failure(
                    'target eligibility',
                    'the supplied read-only eligibility proof does not authorize this target',
                    $version
                );
            }
        } else {
            $reachable = $transport->captureRaw('echo wprism-reachable');
            if ($reachable['exit'] !== 0 || trim($reachable['stdout']) !== 'wprism-reachable') {
                return self::fromTransport('transport preflight', $reachable, $version);
            }
            $wordpress = $transport->captureWp(['core', 'is-installed']);
            if ($wordpress['exit'] !== 0) {
                return self::fromTransport('WordPress preflight', $wordpress, $version);
            }
            $mu = $transport->captureWp(['eval', 'echo WPMU_PLUGIN_DIR;']);
            $muDir = trim($mu['stdout']);
            if ($mu['exit'] !== 0 || $muDir === '' || $muDir[0] !== '/') {
                return self::fromTransport('mu-plugin path discovery', $mu, $version);
            }
        }

        $token = bin2hex(random_bytes(12));
        $localArchive = tempnam(sys_get_temp_dir(), 'wprism-adopt-');
        if ($localArchive === false) {
            return self::failure('local artifact', 'could not allocate a temporary archive', $version);
        }
        $remoteArchive = '/tmp/wprism-adopt-' . $token . '.tar';
        $remoteArchiveOwned = false;
        $remoteArchiveIdentity = null;
        $localStage = null;
        $isolatedLocal = $eligibility !== null;
        $swapped = false;
        $interrupted = null;

        try {
            // Asked BEFORE the swap, because the swap is what makes the
            // question unanswerable safely. mu-plugins are network-wide, so the
            // window between the install script below and the post-swap Policy
            // probe loads the drop-in on every blog of every request; on a
            // WPRISM_JOURNAL target that window created per-blog `wp_N_wprism_*`
            // tables that rollbackScript() cannot remove (it restores
            // filesystem paths only, and the shipped tree has no DROP TABLE).
            // Refusing here leaves $swapped false: nothing installed, nothing
            // to roll back, no journal residue possible.
            //
            // Plain `wp eval` in BOTH branches, unlike the post-swap probes
            // below: CodeDeploy::CONTROL_BOOTSTRAP requires the installed agent
            // at wp-content/mu-plugins/wprism/wprism.php and throws "could not find
            // the protected agent" without it
            // (cli/src/Transport/CodeDeploy.php:77-78, reached from
            // controlArgs() at :403-410), so controlArgs() cannot answer a
            // pre-swap question at all.
            $topologyArgs = ['eval', 'echo is_multisite() ? "wprism-multisite" : "wprism-single-site";'];
            $topology = $transport->captureWp($topologyArgs);
            if ($topology['exit'] !== 0 || trim($topology['stdout']) !== 'wprism-single-site') {
                // Fail closed on an unreadable answer: an adoption that cannot
                // establish the topology is an adoption that must not swap.
                $topology['stderr'] .= ($topology['stderr'] !== '' ? "\n" : '')
                    . 'wprism: multisite is unsupported by the certified v1 contract; this command is single-site '
                    . 'only and refuses before loading policy or mutating state';
                return self::fromTransport('topology probe', $topology, $version);
            }

            try {
                $localStage = self::stageLocalArtifact($sourceRoot, $token);
                require_once $assembler;
                \WPrism\Tooling\AdapterLibraryAssembler::assemble($sourceRoot, $localStage . '/agent');
            } catch (\Throwable $error) {
                return self::failure(
                    'local artifact',
                    'could not assemble the embedded adapter library: ' . $error->getMessage(),
                    $version
                );
            }
            $archive = self::runLocal(
                'COPYFILE_DISABLE=1 tar -C ' . escapeshellarg($localStage)
                . ' -cf ' . escapeshellarg($localArchive) . ' agent recovery'
            );
            if ($archive['exit'] !== 0) {
                return self::fromTransport('local artifact', $archive, $version);
            }

            $upload = $transport->uploadFile($localArchive, $remoteArchive);
            if ($upload['exit'] !== 0) {
                return self::fromTransport('archive upload', $upload, $version);
            }
            $remoteArchiveOwned = true;
            if ($transport instanceof LocalTransport) {
                $remoteArchiveIdentity = $transport->uploadedFileIdentity($upload);
            }

            $install = $transport->captureRaw(self::installScript(
                $remoteArchive,
                $muDir,
                $transport->repoPath(),
                $token,
                $rollbackKeyId,
                $rollbackPublicKey,
                $recoveryConfig,
                $remoteArchiveIdentity
            ));
            if ($install['exit'] !== 0) {
                return self::fromTransport('remote install', $install, $version);
            }
            $swapped = true;

            $versionArgs = ['eval', 'echo defined("WPRISM_AGENT_VERSION") ? WPRISM_AGENT_VERSION : "wprism-missing";'];
            $remoteVersion = $transport->captureWp(
                $isolatedLocal ? CodeDeploy::controlArgs($versionArgs) : $versionArgs
            );
            if ($remoteVersion['exit'] !== 0 || trim($remoteVersion['stdout']) !== $version) {
                $remoteVersion['stderr'] .= ($remoteVersion['stderr'] !== '' ? "\n" : '')
                    . "installed agent version mismatch: expected $version, got '" . trim($remoteVersion['stdout']) . "'";
                self::rollback($transport, $muDir, $transport->repoPath(), $token, $remoteVersion);
                $swapped = false;
                return self::fromTransport('agent version verification', $remoteVersion, $version);
            }

            $repoPhp = var_export($transport->repoPath(), true);
            $libraryPhp = var_export(rtrim($muDir, '/') . '/wprism/adapter-library', true);
            $policyArgs = [
                'eval',
                '$library = \\WPrism\\Policy::adapter_library_context(); '
                    . 'if (!$library instanceof \\WPrism\\AdapterLibrary || $library->root() !== ' . $libraryPhp . ') '
                    . '{ fwrite(STDERR, "unexpected embedded adapter library"); exit(71); } '
                    . 'try { \\WPrism\\Policy::load(' . $repoPhp . '); } '
                    . 'catch (\\Throwable $e) { fwrite(STDERR, $e->getMessage()); exit(72); } '
                    . 'echo "wprism-policy-ok";',
            ];
            $policy = $transport->captureWp(
                $isolatedLocal ? CodeDeploy::controlArgs($policyArgs) : $policyArgs
            );
            if ($policy['exit'] !== 0 || trim($policy['stdout']) !== 'wprism-policy-ok') {
                self::rollback($transport, $muDir, $transport->repoPath(), $token, $policy);
                $swapped = false;
                return self::fromTransport('policy verification', $policy, $version);
            }

            $authority = $transport->captureRaw(
                'php ' . escapeshellarg(rtrim($transport->repoPath(), '/') . '/.wprism/control/recovery-runtime/rollback-control.php')
                . ' status --root=' . escapeshellarg(rtrim($transport->repoPath(), '/') . '/.wprism/control')
            );
            if ($authority['exit'] !== 0) {
                self::rollback($transport, $muDir, $transport->repoPath(), $token, $authority);
                $swapped = false;
                return self::fromTransport('rollback authority verification', $authority, $version);
            }

            if ($postSwapVerifier !== null) {
                try {
                    $verified = $postSwapVerifier();
                } catch (\Throwable) {
                    $verified = false;
                }
                if ($verified !== true) {
                    $failure = [
                        'exit' => 1,
                        'stdout' => '',
                        'stderr' => 'post-install doctor reported a blocking or unreadable check',
                    ];
                    self::rollback($transport, $muDir, $transport->repoPath(), $token, $failure);
                    $swapped = false;
                    return self::fromTransport('doctor verification', $failure, $version);
                }
            }

            $commit = $transport->captureRaw(self::commitBarrierScript($muDir, $transport->repoPath(), $token));
            if ($commit['exit'] !== 0) {
                self::rollback($transport, $muDir, $transport->repoPath(), $token, $commit);
                $swapped = false;
                return self::fromTransport('install commit', $commit, $version);
            }
            // The target has crossed a mutation-free commit barrier while all
            // rollback copies are still intact. Cleanup is deliberately
            // outside the rollbackable phase: a partial backup deletion must
            // never be restored as though it were the exact prior install.
            $swapped = false;

            try {
                $cleanup = $transport->captureRaw(
                    self::cleanupCommittedScript($muDir, $transport->repoPath(), $token)
                );
            } catch (\Throwable) {
                $cleanup = ['exit' => 255, 'stdout' => '', 'stderr' => ''];
            }
            $cleanupWarning = $cleanup['exit'] === 0
                ? ''
                : 'committed install retained adoption cleanup evidence for operator recovery';

            return [
                'exit' => 0,
                'phase' => 'complete',
                'stdout' => $install['stdout'],
                'stderr' => $install['stderr']
                    . ($cleanupWarning !== ''
                        ? ($install['stderr'] !== '' ? "\n" : '') . $cleanupWarning
                        : ''),
                'version' => $version,
                'repo_created' => str_contains($install['stdout'], 'wprism-repo-created'),
            ];
        } catch (\Throwable $error) {
            $interrupted = $error;
            throw $error;
        } finally {
            $rollbackFailure = null;
            if ($swapped) {
                // Exceptions and interrupted host-side verification retain the
                // same fail-closed boundary as explicit verification errors.
                try {
                    $rollbackFailure = self::rollbackFailure(
                        $transport->captureRaw(self::rollbackScript($muDir, $transport->repoPath(), $token))
                    );
                } catch (\Throwable $rollbackError) {
                    $detail = trim($rollbackError->getMessage());
                    $rollbackFailure = 'adoption rollback could not be confirmed'
                        . ($detail !== '' ? ': ' . $detail : ' (rollback transport threw without a diagnostic)');
                }
            }
            @unlink($localArchive);
            if (is_string($localStage)) {
                self::removeLocalStage($localStage);
            }
            // An upload followed by a lost SSH session must not strand the
            // source archive. This cleanup is idempotent; the remote install
            // trap normally removed it already.
            if ($remoteArchiveOwned) {
                if ($transport instanceof LocalTransport && is_string($remoteArchiveIdentity)) {
                    $transport->cleanupUploadedFile($remoteArchive, $remoteArchiveIdentity);
                } elseif (!$transport instanceof LocalTransport) {
                    $transport->captureRaw('rm -f ' . escapeshellarg($remoteArchive));
                }
            }
            if ($rollbackFailure !== null) {
                $original = $interrupted instanceof \Throwable ? trim($interrupted->getMessage()) : '';
                throw new \RuntimeException(
                    ($original !== '' ? $original . "\n" : '') . $rollbackFailure,
                    0,
                    $interrupted
                );
            }
        }
    }

    /**
     * Render the immutable move-journal predicate shared by the install trap,
     * rollback, commit barrier, and committed cleanup.  A transaction may only
     * mutate a published surface after every surface has a durable post-move
     * proof and the one rollback-ready marker.
     */
    private static function journalHelpers(string $muDir, string $repo, string $token, string $identityPhp): string {
        $q = static fn(string $value): string => escapeshellarg($value);
        $root = rtrim($muDir, '/');
        $surfaces = [
            ['agent', $root . '/wprism', $root . '/.wprism-old-' . $token, 'dir'],
            ['loader', $root . '/wprism-loader.php', $root . '/.wprism-loader-old-' . $token, 'file'],
            ['wprism', rtrim($repo, '/') . '/.wprism', rtrim($repo, '/') . '/.wprism-old-' . $token, 'dir'],
        ];
        $ready = static function (string $name, string $live, string $old, string $kind) use ($q): string {
            return '    assert_surface_ready "$txn/' . $name . '_move_intent" "$txn/' . $name
                . '_move_complete" ' . $q($live) . ' "$txn/' . $name . '_live_post.id" "$txn/'
                . $name . '_new.id" ' . $q($old) . ' "$txn/' . $name . '_old_post.id" "$txn/'
                . $name . '_old.id" "$txn/had_' . $name . '" "$txn/' . $name . '_old_absent" '
                . $kind . " || return 1\n";
        };
        $allSurfaces = '';
        foreach ($surfaces as [$name, $live, $old, $kind]) {
            $allSurfaces .= $ready($name, $live, $old, $kind);
        }

        return 'identity() { php -r ' . $q($identityPhp) . " \"\$1\"; }\n"
            . "assert_marker() { [ -f \"\$1\" ] && [ ! -L \"\$1\" ]; }\n"
            . "assert_proof() { [ -f \"\$1\" ] && [ ! -L \"\$1\" ] || return 1; proof_value=\$(cat \"\$1\") || return 1; [ -n \"\$proof_value\" ]; }\n"
            . "assert_identity() { assert_proof \"\$2\" || return 1; actual=\$(identity \"\$1\") || return 1; expected=\$(cat \"\$2\") || return 1; [ \"\$actual\" = \"\$expected\" ]; }\n"
            . "destination_has_kind() { path=\"\$1\"; kind=\"\$2\"; case \"\$kind\" in dir) [ -d \"\$path\" ] && [ ! -L \"\$path\" ] ;; file) [ -f \"\$path\" ] && [ ! -L \"\$path\" ] ;; *) return 1 ;; esac; }\n"
            . "assert_surface_post() { intent=\"\$1\"; live=\"\$2\"; live_post=\"\$3\"; new_pre=\"\$4\"; old=\"\$5\"; old_post=\"\$6\"; old_pre=\"\$7\"; had=\"\$8\"; old_absent=\"\$9\"; kind=\"\${10}\"; assert_marker \"\$txn/swap_intent_started\" && assert_marker \"\$intent\" && assert_proof \"\$new_pre\" && destination_has_kind \"\$live\" \"\$kind\" && assert_identity \"\$live\" \"\$live_post\" || return 1; if [ -e \"\$had\" ] || [ -L \"\$had\" ]; then assert_marker \"\$had\" && assert_proof \"\$old_pre\" && destination_has_kind \"\$old\" \"\$kind\" && assert_identity \"\$old\" \"\$old_post\"; else [ ! -e \"\$had\" ] && [ ! -L \"\$had\" ] && assert_marker \"\$old_absent\" && [ ! -e \"\$old\" ] && [ ! -L \"\$old\" ]; fi; }\n"
            . "assert_surface_ready() { intent=\"\$1\"; complete=\"\$2\"; assert_marker \"\$complete\" && assert_surface_post \"\$intent\" \"\$3\" \"\$4\" \"\$5\" \"\$6\" \"\$7\" \"\$8\" \"\$9\" \"\${10}\" \"\${11}\"; }\n"
            . "assert_all_surfaces_ready() {\n"
            . $allSurfaces
            . "}\n"
            . "assert_journal_ready() { assert_marker \"\$txn/rollback_ready\" && assert_all_surfaces_ready; }\n";
    }

    private static function installScript(
        string $archive,
        string $muDir,
        string $repo,
        string $token,
        ?string $rollbackKeyId,
        ?string $rollbackPublicKey,
        ?array $recoveryConfig,
        ?string $archiveIdentity
    ): string {
        $agent = rtrim($muDir, '/') . '/wprism';
        $loader = rtrim($muDir, '/') . '/wprism-loader.php';
        $durableControl = rtrim($muDir, '/') . '/wprism-control';
        $durableRevocations = $durableControl . '/adapter-revocations.json';
        $seed = self::repositorySeedBytes();
        $recovery = $recoveryConfig === null ? null : \WPrism\Recovery\RollbackControl::canonical($recoveryConfig) . "\n";

        $q = static fn(string $value): string => escapeshellarg($value);
        $stage = '/tmp/wprism-adopt-' . $token;
        $agentNew = rtrim($muDir, '/') . '/.wprism-new-' . $token;
        $loaderNew = rtrim($muDir, '/') . '/.wprism-loader-new-' . $token;
        $agentOld = rtrim($muDir, '/') . '/.wprism-old-' . $token;
        $loaderOld = rtrim($muDir, '/') . '/.wprism-loader-old-' . $token;
        $wprismState = rtrim($repo, '/') . '/.wprism';
        $wprismNew = rtrim($repo, '/') . '/.wprism-new-' . $token;
        $wprismOld = rtrim($repo, '/') . '/.wprism-old-' . $token;
        $control = $wprismState . '/control';
        $controlNew = $wprismNew . '/control';
        $runtime = $control . '/recovery-runtime';
        $runtimeNew = $controlNew . '/recovery-runtime';
        $scopedPromotionControl = $rollbackKeyId !== null && $recoveryConfig !== null
            ? \WPrism\Recovery\RollbackControl::canonical([
                'control_root' => $control,
                'format' => 'wprism-scoped-promotion-control/v1',
            ]) . "\n"
            : null;
        $site = rtrim($repo, '/') . '/site.wprism.json';
        $siteNew = rtrim($repo, '/') . '/.site.wprism.new-' . $token;
        $txn = rtrim($muDir, '/') . '/.wprism-adopt-txn-' . $token;
        $lock = rtrim($muDir, '/') . '/.wprism-adopt-lock';
        $identityPhp = '$s = @lstat($argv[1]); if (!is_array($s)) { exit(1); } '
            . 'echo (string) $s["dev"], ":", (string) $s["ino"], ":", (string) ($s["mode"] & 0170000);';
        if ($archiveIdentity !== null && preg_match('/^[0-9]+:[0-9]+:32768$/D', $archiveIdentity) !== 1) {
            throw new \RuntimeException('local adoption archive identity is malformed');
        }
        $archiveCopyPhp = '$identity = static fn(array $s): string => (string) $s["dev"] . ":" . (string) $s["ino"] . ":" . (string) ($s["mode"] & 0170000); '
            . '$path = $argv[1]; $expected = $argv[2]; $target = $argv[3]; $before = @lstat($path); '
            . 'if (!is_array($before) || $identity($before) !== $expected) { exit(1); } '
            . '$in = @fopen($path, "rb"); if (!is_resource($in)) { exit(2); } $opened = fstat($in); '
            . 'if (!is_array($opened) || $identity($opened) !== $expected) { fclose($in); exit(3); } '
            . '$out = @fopen($target, "xb"); if (!is_resource($out)) { fclose($in); exit(4); } '
            . '$copied = stream_copy_to_stream($in, $out); $ok = is_int($copied) && $copied === (int) $opened["size"] && fflush($out); '
            . 'fclose($in); fclose($out); if (!$ok) { exit(5); }';

        return 'set -eu' . "\n"
            . 'archive=' . $q($archive) . "\n"
            . 'archive_identity=' . $q($archiveIdentity ?? '') . "\n"
            . 'stage=' . $q($stage) . "\n"
            . 'agent=' . $q($agent) . "\n"
            . 'loader=' . $q($loader) . "\n"
            . 'durable_control=' . $q($durableControl) . "\n"
            . 'durable_revocations=' . $q($durableRevocations) . "\n"
            . 'repo=' . $q($repo) . "\n"
            . 'site=' . $q($site) . "\n"
            . 'agent_new=' . $q($agentNew) . "\n"
            . 'loader_new=' . $q($loaderNew) . "\n"
            . 'agent_old=' . $q($agentOld) . "\n"
            . 'loader_old=' . $q($loaderOld) . "\n"
            . 'wprism_state=' . $q($wprismState) . "\n"
            . 'wprism_new=' . $q($wprismNew) . "\n"
            . 'wprism_old=' . $q($wprismOld) . "\n"
            . 'control=' . $q($control) . "\n"
            . 'control_new=' . $q($controlNew) . "\n"
            . 'runtime=' . $q($runtime) . "\n"
            . 'runtime_new=' . $q($runtimeNew) . "\n"
            . 'site_new=' . $q($siteNew) . "\n"
            . 'txn=' . $q($txn) . "\n"
            . 'lock=' . $q($lock) . "\n"
            . "had_agent=0; had_loader=0; had_wprism=0; touched_agent=0; touched_loader=0; touched_wprism=0; seed_created=0; mu_created=0; mu_identity=''; repo_created=0; stage_created=0; stage_materialized=0; agent_new_created=0; agent_new_materialized=0; loader_new_created=0; loader_new_materialized=0; wprism_new_created=0; wprism_new_materialized=0; site_new_created=0; site_new_materialized=0; txn_created=0; lock_acquired=0; success=0\n"
            . self::journalHelpers($muDir, $repo, $token, $identityPhp)
            . "publish_marker() { marker=\"\$1\"; label=\"\$2\"; [ ! -e \"\$marker\" ] && [ ! -L \"\$marker\" ] || { echo \"wprism adopt: \$label marker collision\" >&2; exit 1; }; (umask 077; set -C; : > \"\$marker\") || { echo \"wprism adopt: could not publish \$label marker\" >&2; exit 1; }; assert_marker \"\$marker\" || { echo \"wprism adopt: published \$label marker is unsafe\" >&2; exit 1; }; }\n"
            . "record_identity() { path=\"\$1\"; proof=\"\$2\"; label=\"\${3:-transaction root}\"; actual=\$(identity \"\$path\") || { echo \"wprism adopt: could not read \$label identity\" >&2; exit 1; }; proof_dir=\$(dirname \"\$proof\") || { echo 'wprism adopt: could not resolve a transaction proof directory' >&2; exit 1; }; [ -d \"\$proof_dir\" ] && [ ! -L \"\$proof_dir\" ] || { echo 'wprism adopt: transaction proof directory is unsafe' >&2; exit 1; }; [ ! -e \"\$proof\" ] && [ ! -L \"\$proof\" ] || { echo \"wprism adopt: immutable \$label proof already exists\" >&2; exit 1; }; proof_tmp=\"\${proof}.new-\$\$\"; [ ! -e \"\$proof_tmp\" ] && [ ! -L \"\$proof_tmp\" ] || { echo 'wprism adopt: transaction proof staging collision' >&2; exit 1; }; (umask 077; set -C; printf '%s\\n' \"\$actual\" > \"\$proof_tmp\") || { echo 'wprism adopt: could not stage a transaction identity proof' >&2; exit 1; }; [ -f \"\$proof_tmp\" ] && [ ! -L \"\$proof_tmp\" ] || { echo 'wprism adopt: staged transaction identity proof is unsafe' >&2; exit 1; }; [ ! -e \"\$proof\" ] && [ ! -L \"\$proof\" ] || { echo \"wprism adopt: immutable \$label proof appeared during publish\" >&2; exit 1; }; mv \"\$proof_tmp\" \"\$proof\" || { echo 'wprism adopt: could not publish a transaction identity proof' >&2; exit 1; }; assert_proof \"\$proof\" || { echo 'wprism adopt: published transaction identity proof is unsafe' >&2; exit 1; }; recorded=\$(cat \"\$proof\") || { echo 'wprism adopt: could not read a published transaction identity proof' >&2; exit 1; }; [ \"\$recorded\" = \"\$actual\" ] || { echo \"wprism adopt: published \$label proof disagrees with its root\" >&2; exit 1; }; }\n"
            . "record_absence() { path=\"\$1\"; marker=\"\$2\"; label=\"\$3\"; [ ! -e \"\$path\" ] && [ ! -L \"\$path\" ] || { echo \"wprism adopt: \$label unexpectedly exists before publish\" >&2; exit 1; }; publish_marker \"\$marker\" \"\$label absence\"; [ ! -e \"\$path\" ] && [ ! -L \"\$path\" ] || { echo \"wprism adopt: \$label appeared during absence proof\" >&2; exit 1; }; }\n"
            . "begin_surface() { intent=\"\$1\"; label=\"\$2\"; if [ ! -e \"\$txn/swap_intent_started\" ] && [ ! -L \"\$txn/swap_intent_started\" ]; then publish_marker \"\$txn/swap_intent_started\" 'surface-move intent'; else assert_marker \"\$txn/swap_intent_started\" || { echo 'wprism adopt: surface-move intent marker is unsafe' >&2; exit 1; }; fi; publish_marker \"\$intent\" \"\$label move intent\"; }\n"
            . "move_owned() { source=\"\$1\"; destination=\"\$2\"; source_proof=\"\$3\"; destination_proof=\"\$4\"; kind=\"\$5\"; label=\"\$6\"; intent=\"\$7\"; fault_phase=\"\$8\"; assert_marker \"\$intent\" || { echo \"wprism adopt: \$label move intent is unsafe\" >&2; exit 1; }; destination_has_kind \"\$source\" \"\$kind\" && assert_identity \"\$source\" \"\$source_proof\" || { echo \"wprism adopt: \$label source identity changed before publish\" >&2; exit 1; }; [ ! -e \"\$destination\" ] && [ ! -L \"\$destination\" ] || { echo \"wprism adopt: \$label publish destination is not absent\" >&2; exit 1; }; mv \"\$source\" \"\$destination\" || { echo \"wprism adopt: could not publish \$label\" >&2; exit 1; }; [ ! -e \"\$source\" ] && [ ! -L \"\$source\" ] || { echo \"wprism adopt: \$label source remained after publish\" >&2; exit 1; }; destination_has_kind \"\$destination\" \"\$kind\" || { echo \"wprism adopt: published \$label is not the expected ordinary root\" >&2; exit 1; }; if [ \"\${WPRISM_TEST_MODE:-}\" = 1 ] && [ \"\${WPRISM_TEST_ADOPT_FAIL_PHASE:-}\" = \"\$fault_phase\" ]; then echo \"wprism adopt: injected transaction interruption at \$fault_phase\" >&2; exit 1; fi; record_identity \"\$destination\" \"\$destination_proof\" \"\$label published root\"; destination_has_kind \"\$destination\" \"\$kind\" && assert_identity \"\$destination\" \"\$destination_proof\" || { echo \"wprism adopt: published \$label identity could not be bound\" >&2; exit 1; }; }\n"
            . "complete_surface() { intent=\"\$1\"; complete=\"\$2\"; live=\"\$3\"; live_post=\"\$4\"; new_pre=\"\$5\"; old=\"\$6\"; old_post=\"\$7\"; old_pre=\"\$8\"; had=\"\$9\"; old_absent=\"\${10}\"; kind=\"\${11}\"; label=\"\${12}\"; assert_surface_post \"\$intent\" \"\$live\" \"\$live_post\" \"\$new_pre\" \"\$old\" \"\$old_post\" \"\$old_pre\" \"\$had\" \"\$old_absent\" \"\$kind\" || { echo \"wprism adopt: \$label post-move journal is incomplete\" >&2; exit 1; }; publish_marker \"\$complete\" \"\$label move complete\"; }\n"
            . "remove_owned() { path=\"\$1\"; proof=\"\$2\"; kind=\"\$3\"; label=\"\$4\"; destination_has_kind \"\$path\" \"\$kind\" && assert_identity \"\$path\" \"\$proof\" || { echo \"wprism adopt: live \$label identity changed before rollback\" >&2; return 1; }; if [ \"\$kind\" = dir ]; then rm -rf \"\$path\"; else rm -f \"\$path\"; fi; }\n"
            . "remove_if_owned() { path=\"\$1\"; proof=\"\$2\"; kind=\"\$3\"; [ ! -e \"\$path\" ] && [ ! -L \"\$path\" ] && return 0; destination_has_kind \"\$path\" \"\$kind\" && assert_identity \"\$path\" \"\$proof\" || return 1; if [ \"\$kind\" = dir ]; then rm -rf \"\$path\"; else rm -f \"\$path\"; fi; }\n"
            // A source proof cannot be bound until the root's final bytes
            // exist: Docker Desktop may replace the inode while cp/tar/printf
            // populate a freshly-created target.  Construction proofs retain
            // the narrow pre-materialization cleanup authority.  If population
            // rebounded the root before its final proof published, cleanup
            // refuses and leaves the journal/lock for operator recovery.
            . "remove_constructed_owned() { path=\"\$1\"; materialized_proof=\"\$2\"; construction_proof=\"\$3\"; kind=\"\$4\"; materialized=\"\$5\"; if [ \"\$materialized\" -eq 1 ] || [ -e \"\$materialized_proof\" ] || [ -L \"\$materialized_proof\" ]; then remove_if_owned \"\$path\" \"\$materialized_proof\" \"\$kind\"; else remove_if_owned \"\$path\" \"\$construction_proof\" \"\$kind\"; fi; }\n"
            . "restore_owned() { old=\"\$1\"; live=\"\$2\"; proof=\"\$3\"; kind=\"\$4\"; label=\"\$5\"; destination_has_kind \"\$old\" \"\$kind\" && assert_identity \"\$old\" \"\$proof\" || { echo \"wprism adopt: rollback \$label identity changed before restore\" >&2; return 1; }; [ ! -e \"\$live\" ] && [ ! -L \"\$live\" ] || { echo \"wprism adopt: rollback \$label destination changed before restore\" >&2; return 1; }; mv \"\$old\" \"\$live\" || return 1; [ ! -e \"\$old\" ] && [ ! -L \"\$old\" ] && destination_has_kind \"\$live\" \"\$kind\"; }\n"
            . "finish() {\n"
            . "  status=\$?\n"
            . "  set +e; cleanup_failed=0; mu_proof=\"\$mu_identity\"\n"
            . "  if [ \"\$success\" -ne 1 ] && { [ -e \"\$txn/swap_intent_started\" ] || [ -L \"\$txn/swap_intent_started\" ]; }; then\n"
            . "    if ! assert_journal_ready; then echo 'wprism adopt: incomplete surface-move journal retained for operator recovery' >&2; exit 1; fi\n"
            . "    remove_owned \"\$wprism_state\" \"\$txn/wprism_live_post.id\" dir wprism_state || cleanup_failed=1; if [ \"\$had_wprism\" -eq 1 ]; then restore_owned \"\$wprism_old\" \"\$wprism_state\" \"\$txn/wprism_old_post.id\" dir wprism_state || cleanup_failed=1; fi\n"
            . "    remove_owned \"\$loader\" \"\$txn/loader_live_post.id\" file loader || cleanup_failed=1; if [ \"\$had_loader\" -eq 1 ]; then restore_owned \"\$loader_old\" \"\$loader\" \"\$txn/loader_old_post.id\" file loader || cleanup_failed=1; fi\n"
            . "    remove_owned \"\$agent\" \"\$txn/agent_live_post.id\" dir agent || cleanup_failed=1; if [ \"\$had_agent\" -eq 1 ]; then restore_owned \"\$agent_old\" \"\$agent\" \"\$txn/agent_old_post.id\" dir agent || cleanup_failed=1; fi\n"
            . "  fi\n"
            . "  if [ \"\$success\" -ne 1 ]; then\n"
            . "    if [ \"\$seed_created\" -eq 1 ]; then remove_if_owned \"\$site\" \"\$txn/site.id\" file || cleanup_failed=1; fi\n"
            . "  fi\n"
            . "  if [ \"\$stage_created\" -eq 1 ]; then remove_constructed_owned \"\$stage\" \"\$txn/stage.id\" \"\$txn/stage_construction.id\" dir \"\$stage_materialized\" || cleanup_failed=1; fi\n"
            . "  if [ \"\$agent_new_created\" -eq 1 ]; then remove_constructed_owned \"\$agent_new\" \"\$txn/agent_new.id\" \"\$txn/agent_new_construction.id\" dir \"\$agent_new_materialized\" || cleanup_failed=1; fi\n"
            . "  if [ \"\$loader_new_created\" -eq 1 ]; then remove_constructed_owned \"\$loader_new\" \"\$txn/loader_new.id\" \"\$txn/loader_new_construction.id\" file \"\$loader_new_materialized\" || cleanup_failed=1; fi\n"
            . "  if [ \"\$wprism_new_created\" -eq 1 ]; then remove_constructed_owned \"\$wprism_new\" \"\$txn/wprism_new.id\" \"\$txn/wprism_new_construction.id\" dir \"\$wprism_new_materialized\" || cleanup_failed=1; fi\n"
            . "  if [ \"\$site_new_created\" -eq 1 ]; then remove_constructed_owned \"\$site_new\" \"\$txn/site_new.id\" \"\$txn/site_new_construction.id\" file \"\$site_new_materialized\" || cleanup_failed=1; fi\n"
            . "  if [ \"\$success\" -ne 1 ] && [ \"\$cleanup_failed\" -eq 0 ] && [ \"\$repo_created\" -eq 1 ]; then assert_identity \"\$repo\" \"\$lock/repo.id\" && rmdir \"\$repo\" || cleanup_failed=1; fi\n"
            . "  if [ \"\$success\" -ne 1 ] && [ \"\$cleanup_failed\" -eq 0 ] && [ \"\$txn_created\" -eq 1 ]; then assert_identity \"\$txn\" \"\$lock/txn.id\" && rm -rf \"\$txn\" || cleanup_failed=1; fi\n"
            . "  if [ \"\$success\" -ne 1 ] && [ \"\$cleanup_failed\" -eq 0 ] && [ \"\$lock_acquired\" -eq 1 ]; then assert_identity \"\$lock\" \"\$lock/lock.id\" || cleanup_failed=1; if [ \"\$cleanup_failed\" -eq 0 ]; then mu_proof=\$(cat \"\$lock/mu.id\" 2>/dev/null || true); rm -f \"\$lock/lock.id\" \"\$lock/txn.id\" \"\$lock/repo.id\" \"\$lock/mu.id\"; rmdir \"\$lock\" || cleanup_failed=1; fi; fi\n"
            . '  if [ "$success" -ne 1 ] && [ "$cleanup_failed" -eq 0 ] && [ "$mu_created" -eq 1 ]; then actual_mu=$(identity ' . $q($muDir) . ' 2>/dev/null || true); [ -n "$mu_proof" ] && [ "$actual_mu" = "$mu_proof" ] && rmdir ' . $q($muDir) . " || cleanup_failed=1; fi\n"
            . "  if [ -e \"\$archive\" ] || [ -L \"\$archive\" ]; then if [ -n \"\$archive_identity\" ]; then actual_archive=\$(identity \"\$archive\" 2>/dev/null || true); [ \"\$actual_archive\" = \"\$archive_identity\" ] && rm -f \"\$archive\" || cleanup_failed=1; else rm -f \"\$archive\" || cleanup_failed=1; fi; fi\n"
            . "  if [ \"\$cleanup_failed\" -ne 0 ]; then echo 'wprism adopt: transaction cleanup identity changed; retained evidence for operator recovery' >&2; status=1; fi\n"
            . "  exit \"\$status\"\n"
            . "}\n"
            . "trap finish EXIT\n"
            . "for command in php tar cp mv rm mkdir rmdir chmod find dirname ln cat; do command -v \"\$command\" >/dev/null 2>&1 || { echo 'wprism adopt: target lacks a transaction command' >&2; exit 1; }; done\n"
            . "for path in \"\$stage\" \"\$agent_new\" \"\$loader_new\" \"\$wprism_new\" \"\$wprism_old\" \"\$agent_old\" \"\$loader_old\" \"\$site_new\" \"\$txn\"; do [ ! -e \"\$path\" ] && [ ! -L \"\$path\" ] || { echo 'wprism adopt: transaction path collision' >&2; exit 1; }; done\n"
            . "for path in \"\$agent\" \"\$loader\" \"\$durable_control\" \"\$durable_revocations\" \"\$site\" \"\$wprism_state\" \"\$control\" \"\$runtime\"; do [ ! -L \"\$path\" ] || { echo \"wprism adopt: refusing symlink destination: \$path\" >&2; exit 1; }; done\n"
            . "[ ! -e \"\$agent\" ] || [ -d \"\$agent\" ] || { echo \"wprism adopt: expected directory destination: \$agent\" >&2; exit 1; }\n"
            . "[ ! -e \"\$loader\" ] || [ -f \"\$loader\" ] || { echo \"wprism adopt: expected file destination: \$loader\" >&2; exit 1; }\n"
            . "[ ! -e \"\$durable_control\" ] || [ -d \"\$durable_control\" ] || { echo \"wprism adopt: expected directory destination: \$durable_control\" >&2; exit 1; }\n"
            . "[ ! -e \"\$durable_revocations\" ] || [ -f \"\$durable_revocations\" ] || { echo \"wprism adopt: expected file destination: \$durable_revocations\" >&2; exit 1; }\n"
            . "[ ! -e \"\$site\" ] || [ -f \"\$site\" ] || { echo \"wprism adopt: expected file destination: \$site\" >&2; exit 1; }\n"
            . "[ ! -e \"\$wprism_state\" ] || [ -d \"\$wprism_state\" ] || { echo \"wprism adopt: expected directory destination: \$wprism_state\" >&2; exit 1; }\n"
            . "[ ! -e \"\$control\" ] || [ -d \"\$control\" ] || { echo \"wprism adopt: expected directory destination: \$control\" >&2; exit 1; }\n"
            . "[ ! -e \"\$runtime\" ] || [ -d \"\$runtime\" ] || { echo \"wprism adopt: expected directory destination: \$runtime\" >&2; exit 1; }\n"
            . 'if [ ! -e ' . $q($muDir) . ' ]; then mkdir ' . $q($muDir) . '; mu_created=1; mu_identity=$(identity ' . $q($muDir) . "); fi\n"
            . "if ! mkdir \"\$lock\"; then echo 'wprism adopt: another adoption is active or requires operator recovery (.wprism-adopt-lock exists)' >&2; exit 1; fi; lock_acquired=1; record_identity \"\$lock\" \"\$lock/lock.id\"; if [ \"\$mu_created\" -eq 1 ]; then record_identity " . $q($muDir) . " \"\$lock/mu.id\"; fi\n"
            . "if [ ! -e \"\$repo\" ]; then mkdir \"\$repo\"; repo_created=1; record_identity \"\$repo\" \"\$lock/repo.id\"; fi\n"
            . "mkdir \"\$txn\"; txn_created=1; record_identity \"\$txn\" \"\$lock/txn.id\"; [ \"\$mu_created\" -eq 0 ] || : > \"\$txn/mu_created\"; [ \"\$repo_created\" -eq 0 ] || : > \"\$txn/repo_created\"\n"
            . "mkdir \"\$stage\"; stage_created=1; record_identity \"\$stage\" \"\$txn/stage_construction.id\" 'staged artifact construction root'\n"
            . ($archiveIdentity !== null
                ? 'php -r ' . $q($archiveCopyPhp) . " \"\$archive\" \"\$archive_identity\" \"\$stage/archive.tar\" || { echo 'wprism adopt: local archive identity changed before staging' >&2; exit 1; }\n"
                    . "actual_archive=\$(identity \"\$archive\" 2>/dev/null || true); [ \"\$actual_archive\" = \"\$archive_identity\" ] || { echo 'wprism adopt: local archive path changed before cleanup' >&2; exit 1; }; rm -f \"\$archive\"\n"
                    . "tar --no-same-owner -xf \"\$stage/archive.tar\" -C \"\$stage\"\n"
                : "tar --no-same-owner -xf \"\$archive\" -C \"\$stage\"\n")
            . "special=\$(find \"\$stage\" ! -type d ! -type f -print -quit 2>/dev/null) || { echo 'wprism adopt: staged artifact could not be inspected' >&2; exit 1; }; [ -z \"\$special\" ] || { echo 'wprism adopt: staged artifact contains a link or special node' >&2; exit 1; }\n"
            . "unreadable=\$(find \"\$stage\" -type f ! -exec test -r '{}' \; -print -quit 2>/dev/null) || { echo 'wprism adopt: staged artifact could not be inspected' >&2; exit 1; }; [ -z \"\$unreadable\" ] || { echo 'wprism adopt: staged artifact contains an unreadable file' >&2; exit 1; }\n"
            . "[ -f \"\$stage/agent/wprism.php\" ] && [ -f \"\$stage/agent/wprism-loader.php\" ] && [ -f \"\$stage/agent/adapter-library/platform/core/manifest.json\" ] || { echo 'wprism adopt: uploaded artifact is incomplete' >&2; exit 1; }\n"
            . "[ -f \"\$stage/recovery/CanonicalJson.php\" ] && [ -f \"\$stage/recovery/AtomicStore.php\" ] && [ -f \"\$stage/recovery/ProtocolLock.php\" ] && [ -f \"\$stage/recovery/ProviderClient.php\" ] && [ -f \"\$stage/recovery/rollback-control.php\" ] && [ -f \"\$stage/recovery/RecoveryExecutor.php\" ] && [ -f \"\$stage/recovery/CheckpointBundle.php\" ] && [ -f \"\$stage/recovery/CodeRelease.php\" ] && [ -f \"\$stage/recovery/UploadBundle.php\" ] && [ -f \"\$stage/recovery/EffectBundle.php\" ] || { echo 'wprism adopt: recovery runtime is missing' >&2; exit 1; }\n"
            . "mkdir \"\$agent_new\"; agent_new_created=1; record_identity \"\$agent_new\" \"\$txn/agent_new_construction.id\" 'agent construction root'; cp -R \"\$stage/agent/.\" \"\$agent_new/\"\n"
            . "[ ! -e \"\$agent_new/scoped-promotion-control.json\" ] && [ ! -L \"\$agent_new/scoped-promotion-control.json\" ] || { echo 'wprism adopt: source artifact contains target-local scoped promotion configuration' >&2; exit 1; }\n"
            . ($scopedPromotionControl !== null
                ? "printf '%s' " . $q($scopedPromotionControl) . " > \"\$agent_new/scoped-promotion-control.json\"; chmod 600 \"\$agent_new/scoped-promotion-control.json\"\n"
                : '')
            . "record_identity \"\$agent_new\" \"\$txn/agent_new.id\" 'agent publish source'; agent_new_materialized=1\n"
            . "if (set -C; umask 077; : > \"\$loader_new\"); then loader_new_created=1; else echo 'wprism adopt: loader staging collision' >&2; exit 1; fi; record_identity \"\$loader_new\" \"\$txn/loader_new_construction.id\" 'loader construction root'; cp \"\$stage/agent/wprism-loader.php\" \"\$loader_new\"\n"
            . "record_identity \"\$loader_new\" \"\$txn/loader_new.id\" 'loader publish source'; loader_new_materialized=1\n"
            . "mkdir \"\$wprism_new\"; wprism_new_created=1; record_identity \"\$wprism_new\" \"\$txn/wprism_new_construction.id\" 'WPrism authority construction root'; if [ -e \"\$wprism_state\" ]; then special=\$(find \"\$wprism_state\" ! -type d ! -type f -print -quit 2>/dev/null) || { echo 'wprism adopt: prior authority became unreadable' >&2; exit 1; }; [ -z \"\$special\" ] || { echo 'wprism adopt: prior authority contains a link or special node' >&2; exit 1; }; unreadable=\$(find \"\$wprism_state\" -type f ! -exec test -r '{}' \; -print -quit 2>/dev/null) || { echo 'wprism adopt: prior authority became unreadable' >&2; exit 1; }; [ -z \"\$unreadable\" ] || { echo 'wprism adopt: prior authority became unreadable' >&2; exit 1; }; cp -Rp \"\$wprism_state/.\" \"\$wprism_new/\"; fi\n"
            . "mkdir -p \"\$control_new\"; chmod 700 \"\$control_new\"; rm -rf \"\$runtime_new\"; cp -R \"\$stage/recovery\" \"\$runtime_new\"\n"
            . "php \"\$runtime_new/rollback-control.php\" init --root=\"\$control_new\" >/dev/null\n"
            . ($rollbackKeyId !== null
                ? 'php "$runtime_new/rollback-control.php" install-key --root="$control_new" --key-id=' . $q($rollbackKeyId) . ' --public-key=' . $q((string) $rollbackPublicKey) . " >/dev/null\n"
                : '')
            . ($recovery !== null
                ? "printf '%s' " . $q($recovery) . " > \"\$stage/recovery-config.json\"; chmod 600 \"\$stage/recovery-config.json\"\n"
                    . "php \"\$runtime_new/rollback-control.php\" configure-recovery --root=\"\$control_new\" --config=\"\$stage/recovery-config.json\" >/dev/null\n"
                    . "php \"\$runtime_new/rollback-control.php\" recovery-probe --root=\"\$control_new\" >/dev/null\n"
                : '')
            . "record_identity \"\$stage\" \"\$txn/stage.id\" 'staged artifact final root'; stage_materialized=1\n"
            . "record_identity \"\$wprism_new\" \"\$txn/wprism_new.id\" 'WPrism authority publish source'; wprism_new_materialized=1\n"
            . "if [ ! -e \"\$site\" ]; then if (set -C; umask 077; : > \"\$site_new\"); then site_new_created=1; else echo 'wprism adopt: seed staging collision' >&2; exit 1; fi; record_identity \"\$site_new\" \"\$txn/site_new_construction.id\" 'site seed construction root'; printf '%s' " . $q($seed) . " > \"\$site_new\"; record_identity \"\$site_new\" \"\$txn/site_new.id\" 'site seed publish source'; site_new_materialized=1; if ln \"\$site_new\" \"\$site\"; then record_identity \"\$site\" \"\$txn/site.id\"; rm -f \"\$site_new\"; site_new_created=0; seed_created=1; publish_marker \"\$txn/seed_created\" 'site seed'; else echo 'wprism adopt: site policy appeared during bootstrap' >&2; exit 1; fi; fi\n"
            . "begin_surface \"\$txn/agent_move_intent\" agent; touched_agent=1; if [ -e \"\$agent\" ]; then record_identity \"\$agent\" \"\$txn/agent_old.id\" 'agent previous root'; publish_marker \"\$txn/had_agent\" 'agent previous root'; had_agent=1; move_owned \"\$agent\" \"\$agent_old\" \"\$txn/agent_old.id\" \"\$txn/agent_old_post.id\" dir 'agent backup' \"\$txn/agent_move_intent\" agent-old-after-move; else record_absence \"\$agent\" \"\$txn/agent_old_absent\" 'agent previous root'; fi; move_owned \"\$agent_new\" \"\$agent\" \"\$txn/agent_new.id\" \"\$txn/agent_live_post.id\" dir agent \"\$txn/agent_move_intent\" agent-live-after-move; complete_surface \"\$txn/agent_move_intent\" \"\$txn/agent_move_complete\" \"\$agent\" \"\$txn/agent_live_post.id\" \"\$txn/agent_new.id\" \"\$agent_old\" \"\$txn/agent_old_post.id\" \"\$txn/agent_old.id\" \"\$txn/had_agent\" \"\$txn/agent_old_absent\" dir agent\n"
            . "begin_surface \"\$txn/loader_move_intent\" loader; touched_loader=1; if [ -e \"\$loader\" ]; then record_identity \"\$loader\" \"\$txn/loader_old.id\" 'loader previous root'; publish_marker \"\$txn/had_loader\" 'loader previous root'; had_loader=1; move_owned \"\$loader\" \"\$loader_old\" \"\$txn/loader_old.id\" \"\$txn/loader_old_post.id\" file 'loader backup' \"\$txn/loader_move_intent\" loader-old-after-move; else record_absence \"\$loader\" \"\$txn/loader_old_absent\" 'loader previous root'; fi; move_owned \"\$loader_new\" \"\$loader\" \"\$txn/loader_new.id\" \"\$txn/loader_live_post.id\" file loader \"\$txn/loader_move_intent\" loader-live-after-move; complete_surface \"\$txn/loader_move_intent\" \"\$txn/loader_move_complete\" \"\$loader\" \"\$txn/loader_live_post.id\" \"\$txn/loader_new.id\" \"\$loader_old\" \"\$txn/loader_old_post.id\" \"\$txn/loader_old.id\" \"\$txn/had_loader\" \"\$txn/loader_old_absent\" file loader\n"
            . "begin_surface \"\$txn/wprism_move_intent\" wprism_state; touched_wprism=1; if [ -e \"\$wprism_state\" ]; then record_identity \"\$wprism_state\" \"\$txn/wprism_old.id\" 'wprism_state previous root'; publish_marker \"\$txn/had_wprism\" 'wprism_state previous root'; had_wprism=1; move_owned \"\$wprism_state\" \"\$wprism_old\" \"\$txn/wprism_old.id\" \"\$txn/wprism_old_post.id\" dir 'wprism_state backup' \"\$txn/wprism_move_intent\" wprism_state-old-after-move; else record_absence \"\$wprism_state\" \"\$txn/wprism_old_absent\" 'wprism_state previous root'; fi; move_owned \"\$wprism_new\" \"\$wprism_state\" \"\$txn/wprism_new.id\" \"\$txn/wprism_live_post.id\" dir wprism_state \"\$txn/wprism_move_intent\" wprism_state-live-after-move; complete_surface \"\$txn/wprism_move_intent\" \"\$txn/wprism_move_complete\" \"\$wprism_state\" \"\$txn/wprism_live_post.id\" \"\$txn/wprism_new.id\" \"\$wprism_old\" \"\$txn/wprism_old_post.id\" \"\$txn/wprism_old.id\" \"\$txn/had_wprism\" \"\$txn/wprism_old_absent\" dir wprism_state\n"
            . "assert_all_surfaces_ready || { echo 'wprism adopt: all surface post-move proofs were not published' >&2; exit 1; }; publish_marker \"\$txn/rollback_ready\" 'rollback-ready swap'\n"
            . "success=1\n"
            . "if [ \"\$seed_created\" -eq 1 ]; then echo wprism-repo-created; else echo wprism-repo-retained; fi\n"
            . "echo wprism-install-complete\n";
    }

    private static function commitBarrierScript(string $muDir, string $repo, string $token): string {
        $q = static fn(string $value): string => escapeshellarg($value);
        $root = rtrim($muDir, '/');
        $txn = $root . '/.wprism-adopt-txn-' . $token;
        $lock = $root . '/.wprism-adopt-lock';
        $identityPhp = '$s = @lstat($argv[1]); if (!is_array($s)) { exit(1); } '
            . 'echo (string) $s["dev"], ":", (string) $s["ino"], ":", (string) ($s["mode"] & 0170000);';
        return 'set -eu' . "\n"
            . 'txn=' . $q($txn) . '; lock=' . $q($lock) . "\n"
            . self::journalHelpers($muDir, $repo, $token, $identityPhp)
            . "assert_identity \"\$lock\" \"\$lock/lock.id\" && assert_identity \"\$txn\" \"\$lock/txn.id\" || { echo 'wprism adopt: transaction identity changed before commit' >&2; exit 1; }\n"
            . "assert_journal_ready || { echo 'wprism adopt: transaction journal is incomplete before commit' >&2; exit 1; }\n"
            . "[ ! -e \"\$txn/commit_started\" ] && [ ! -L \"\$txn/commit_started\" ] || { echo 'wprism adopt: commit marker collision' >&2; exit 1; }\n"
            . "if (set -C; umask 077; : > \"\$txn/commit_started\"); then assert_marker \"\$txn/commit_started\" || { echo 'wprism adopt: published commit barrier is unsafe' >&2; exit 1; }; echo wprism-adopt-commit-barrier; else echo 'wprism adopt: could not publish commit barrier' >&2; exit 1; fi";
    }

    /**
     * Remove rollback copies only after install() has observed the commit
     * barrier and permanently left its rollbackable phase. A cleanup error
     * retains the transaction/lock as operator evidence; it is never grounds
     * for restoring a backup that deletion may already have changed.
     */
    private static function cleanupCommittedScript(string $muDir, string $repo, string $token): string {
        $q = static fn(string $value): string => escapeshellarg($value);
        $root = rtrim($muDir, '/');
        $txn = $root . '/.wprism-adopt-txn-' . $token;
        $lock = $root . '/.wprism-adopt-lock';
        $identityPhp = '$s = @lstat($argv[1]); if (!is_array($s)) { exit(1); } '
            . 'echo (string) $s["dev"], ":", (string) $s["ino"], ":", (string) ($s["mode"] & 0170000);';
        $agentOld = $root . '/.wprism-old-' . $token;
        $loaderOld = $root . '/.wprism-loader-old-' . $token;
        $wprismOld = rtrim($repo, '/') . '/.wprism-old-' . $token;

        return 'set -u' . "\n"
            . 'txn=' . $q($txn) . '; lock=' . $q($lock) . "\n"
            . self::journalHelpers($muDir, $repo, $token, $identityPhp)
            . "assert_identity \"\$lock\" \"\$lock/lock.id\" && assert_identity \"\$txn\" \"\$lock/txn.id\" && assert_marker \"\$txn/commit_started\" || { echo 'wprism adopt: committed cleanup evidence is incomplete' >&2; exit 1; }\n"
            . "assert_journal_ready || { echo 'wprism adopt: committed cleanup journal is incomplete' >&2; exit 1; }\n"
            . "cleanup_failed=0\n"
            . 'if [ -e "$txn/had_wprism" ] || [ -L "$txn/had_wprism" ]; then assert_marker "$txn/had_wprism" && rm -rf ' . $q($wprismOld) . " || cleanup_failed=1; fi\n"
            . 'if [ -e "$txn/had_loader" ] || [ -L "$txn/had_loader" ]; then assert_marker "$txn/had_loader" && rm -f ' . $q($loaderOld) . " || cleanup_failed=1; fi\n"
            . 'if [ -e "$txn/had_agent" ] || [ -L "$txn/had_agent" ]; then assert_marker "$txn/had_agent" && rm -rf ' . $q($agentOld) . " || cleanup_failed=1; fi\n"
            . "if [ \"\$cleanup_failed\" -ne 0 ]; then echo 'wprism adopt: committed install retained partial backup cleanup evidence' >&2; exit 1; fi\n"
            . "assert_identity \"\$txn\" \"\$lock/txn.id\" || { echo 'wprism adopt: transaction identity changed before committed cleanup' >&2; exit 1; }\n"
            . "rm -rf \"\$txn\" || { echo 'wprism adopt: committed install retained transaction cleanup evidence' >&2; exit 1; }\n"
            . "assert_identity \"\$lock\" \"\$lock/lock.id\" || { echo 'wprism adopt: adoption lock identity changed before committed cleanup' >&2; exit 1; }\n"
            . "rm -f \"\$lock/lock.id\" \"\$lock/txn.id\" \"\$lock/repo.id\" \"\$lock/mu.id\" || { echo 'wprism adopt: committed install retained lock cleanup evidence' >&2; exit 1; }\n"
            . "rmdir \"\$lock\" || { echo 'wprism adopt: committed install retained adoption lock evidence' >&2; exit 1; }";
    }

    private static function rollbackScript(string $muDir, string $repo, string $token): string {
        $q = static fn(string $value): string => escapeshellarg($value);
        $root = rtrim($muDir, '/');
        $txn = $root . '/.wprism-adopt-txn-' . $token;
        $agent = $root . '/wprism';
        $loader = $root . '/wprism-loader.php';
        $agentOld = $root . '/.wprism-old-' . $token;
        $loaderOld = $root . '/.wprism-loader-old-' . $token;
        $wprismState = rtrim($repo, '/') . '/.wprism';
        $wprismOld = rtrim($repo, '/') . '/.wprism-old-' . $token;
        $site = rtrim($repo, '/') . '/site.wprism.json';
        $lock = $root . '/.wprism-adopt-lock';
        $identityPhp = '$s = @lstat($argv[1]); if (!is_array($s)) { exit(1); } '
            . 'echo (string) $s["dev"], ":", (string) $s["ino"], ":", (string) ($s["mode"] & 0170000);';

        return 'set -eu' . "\n"
            . 'txn=' . $q($txn) . '; lock=' . $q($lock) . "\n"
            . 'if [ ! -e "$txn" ] && [ ! -L "$txn" ]; then exit 0; fi' . "\n"
            . '[ -d "$txn" ] && [ ! -L "$txn" ] || { echo "wprism adopt: transaction journal is unsafe before rollback" >&2; exit 1; }' . "\n"
            . self::journalHelpers($muDir, $repo, $token, $identityPhp)
            . "remove_owned() { path=\"\$1\"; proof=\"\$2\"; kind=\"\$3\"; label=\"\$4\"; destination_has_kind \"\$path\" \"\$kind\" && assert_identity \"\$path\" \"\$proof\" || { echo \"wprism adopt: live \$label identity changed before rollback\" >&2; exit 1; }; if [ \"\$kind\" = dir ]; then rm -rf \"\$path\"; else rm -f \"\$path\"; fi; }\n"
            . "restore_owned() { old=\"\$1\"; live=\"\$2\"; proof=\"\$3\"; kind=\"\$4\"; label=\"\$5\"; destination_has_kind \"\$old\" \"\$kind\" && assert_identity \"\$old\" \"\$proof\" || { echo \"wprism adopt: rollback \$label identity changed before restore\" >&2; exit 1; }; [ ! -e \"\$live\" ] && [ ! -L \"\$live\" ] || { echo \"wprism adopt: rollback \$label destination changed before restore\" >&2; exit 1; }; mv \"\$old\" \"\$live\" || exit 1; [ ! -e \"\$old\" ] && [ ! -L \"\$old\" ] && destination_has_kind \"\$live\" \"\$kind\" || { echo \"wprism adopt: rollback \$label restore did not complete\" >&2; exit 1; }; }\n"
            . "assert_identity \"\$lock\" \"\$lock/lock.id\" || { echo 'wprism adopt: transaction lock identity changed before rollback' >&2; exit 1; }\n"
            . "assert_identity \"\$txn\" \"\$lock/txn.id\" || { echo 'wprism adopt: transaction journal identity changed before rollback' >&2; exit 1; }\n"
            . "[ ! -e \"\$txn/commit_started\" ] && [ ! -L \"\$txn/commit_started\" ] || { echo 'wprism adopt: transaction has crossed the commit barrier; retained evidence for operator recovery' >&2; exit 1; }\n"
            . "assert_journal_ready || { echo 'wprism adopt: transaction journal is incomplete before rollback; retained evidence for operator recovery' >&2; exit 1; }\n"
            . 'remove_owned ' . $q($wprismState) . ' "$txn/wprism_live_post.id" dir wprism_state; if [ -e "$txn/had_wprism" ] || [ -L "$txn/had_wprism" ]; then assert_marker "$txn/had_wprism" || exit 1; restore_owned ' . $q($wprismOld) . ' ' . $q($wprismState) . ' "$txn/wprism_old_post.id" dir wprism_state; fi' . "\n"
            . 'remove_owned ' . $q($loader) . ' "$txn/loader_live_post.id" file loader; if [ -e "$txn/had_loader" ] || [ -L "$txn/had_loader" ]; then assert_marker "$txn/had_loader" || exit 1; restore_owned ' . $q($loaderOld) . ' ' . $q($loader) . ' "$txn/loader_old_post.id" file loader; fi' . "\n"
            . 'remove_owned ' . $q($agent) . ' "$txn/agent_live_post.id" dir agent; if [ -e "$txn/had_agent" ] || [ -L "$txn/had_agent" ]; then assert_marker "$txn/had_agent" || exit 1; restore_owned ' . $q($agentOld) . ' ' . $q($agent) . ' "$txn/agent_old_post.id" dir agent; fi' . "\n"
            . 'if [ -e "$txn/seed_created" ] || [ -L "$txn/seed_created" ]; then assert_marker "$txn/seed_created" || exit 1; remove_owned ' . $q($site) . ' "$txn/site.id" file site_seed; fi' . "\n"
            . 'repo_created=0; mu_created=0; if [ -e "$txn/repo_created" ] || [ -L "$txn/repo_created" ]; then assert_marker "$txn/repo_created" || exit 1; repo_created=1; fi; if [ -e "$txn/mu_created" ] || [ -L "$txn/mu_created" ]; then assert_marker "$txn/mu_created" || exit 1; mu_created=1; fi' . "\n"
            . 'repo_proof=$(cat "$lock/repo.id" 2>/dev/null || true); mu_proof=$(cat "$lock/mu.id" 2>/dev/null || true)' . "\n"
            . 'assert_identity "$txn" "$lock/txn.id"; rm -rf "$txn"' . "\n"
            . 'assert_identity "$lock" "$lock/lock.id"; rm -f "$lock/lock.id" "$lock/txn.id" "$lock/repo.id" "$lock/mu.id"; rmdir "$lock"' . "\n"
            . 'if [ "$repo_created" -eq 1 ]; then actual=$(identity ' . $q(rtrim($repo, '/')) . '); [ -n "$repo_proof" ] && [ "$actual" = "$repo_proof" ] || { echo "wprism adopt: created repository identity changed" >&2; exit 1; }; rmdir ' . $q(rtrim($repo, '/')) . '; fi' . "\n"
            . 'if [ "$mu_created" -eq 1 ]; then actual=$(identity ' . $q($root) . '); [ -n "$mu_proof" ] && [ "$actual" = "$mu_proof" ] || { echo "wprism adopt: created control root identity changed" >&2; exit 1; }; rmdir ' . $q($root) . '; fi';
    }

    /**
     * Roll back a swapped release and append any rollback failure to the
     * original diagnostic so a failed recovery can never be hidden.
     *
     * @param array{exit:int, stdout:string, stderr:string} &$original
     */
    private static function rollback(
        AdoptionTransport $transport,
        string $muDir,
        string $repo,
        string $token,
        array &$original
    ): void {
        $rollback = $transport->captureRaw(self::rollbackScript($muDir, $repo, $token));
        $failure = self::rollbackFailure($rollback);
        if ($failure === null) {
            return;
        }
        $original['stderr'] .= ($original['stderr'] !== '' ? "\n" : '')
            . $failure;
    }

    /** @param array{exit:int, stdout:string, stderr:string} $rollback */
    private static function rollbackFailure(array $rollback): ?string {
        if ($rollback['exit'] === 0) {
            return null;
        }
        $detail = trim($rollback['stderr'] !== '' ? $rollback['stderr'] : $rollback['stdout']);
        return 'adoption rollback could not be confirmed'
            . ($detail !== '' ? ': ' . $detail : ' (exit ' . $rollback['exit'] . ')');
    }

    /** @return ?string */
    private static function agentVersion(string $file): ?string {
        $source = @file_get_contents($file);
        if (!is_string($source)
            || preg_match("/define\\(\\s*'WPRISM_AGENT_VERSION'\\s*,\\s*'([^']+)'\\s*\\)/", $source, $m) !== 1) {
            return null;
        }
        return $m[1];
    }

    private static function stageLocalArtifact(string $sourceRoot, string $token): string {
        $temporaryRoot = realpath(sys_get_temp_dir());
        if (!is_string($temporaryRoot) || $temporaryRoot === '' || $temporaryRoot === DIRECTORY_SEPARATOR) {
            throw new \RuntimeException('could not resolve the local adoption staging root');
        }
        $stage = rtrim($temporaryRoot, DIRECTORY_SEPARATOR) . '/wprism-adopt-source-' . $token;
        if (!mkdir($stage, 0700)) {
            throw new \RuntimeException('could not allocate local adoption staging');
        }

        $copy = self::runLocal(
            'cp -R ' . escapeshellarg(rtrim($sourceRoot, '/') . '/agent') . ' ' . escapeshellarg($stage . '/agent')
            . ' && cp -R ' . escapeshellarg(rtrim($sourceRoot, '/') . '/recovery') . ' ' . escapeshellarg($stage . '/recovery')
        );
        if ($copy['exit'] !== 0) {
            self::removeLocalStage($stage);
            throw new \RuntimeException(
                'could not copy the local adoption artifact into staging'
                . ($copy['stderr'] !== '' ? ': ' . trim($copy['stderr']) : '')
            );
        }

        // A reviewed distribution is expected to be mounted or copied
        // read-only by its controller. `cp -R` correctly preserves those
        // directory modes, but this entire tree is a newly allocated,
        // process-owned staging copy. Normalize only its directories so the
        // assembler can replace generated descendants and both local and
        // remote transaction cleanup can remove the copied tree. Files retain
        // their reviewed modes and bytes; the source distribution remains
        // immutable.
        try {
            self::makeLocalStageDirectoriesWritable($stage);
        } catch (\Throwable $error) {
            self::removeLocalStage($stage);
            throw new \RuntimeException(
                'could not make the local adoption staging directories writable: ' . $error->getMessage()
            );
        }

        $resolved = realpath($stage);
        if (!is_string($resolved) || $resolved !== $stage) {
            self::removeLocalStage($stage);
            throw new \RuntimeException('local adoption staging changed identity after copy');
        }
        return $resolved;
    }

    private static function makeLocalStageDirectoriesWritable(string $path): void {
        $stat = @lstat($path);
        if (!is_array($stat) || ($stat['mode'] & 0170000) !== 0040000 || is_link($path)) {
            throw new \RuntimeException('staging contains an unexpected directory boundary');
        }
        if (!chmod($path, 0700)) {
            throw new \RuntimeException('could not normalize a staging directory');
        }
        $children = @scandir($path);
        if (!is_array($children)) {
            throw new \RuntimeException('could not inspect a staging directory');
        }
        foreach ($children as $child) {
            if ($child === '.' || $child === '..') {
                continue;
            }
            $childPath = $path . '/' . $child;
            $childStat = @lstat($childPath);
            if (is_array($childStat) && ($childStat['mode'] & 0170000) === 0040000 && !is_link($childPath)) {
                self::makeLocalStageDirectoriesWritable($childPath);
            }
        }
    }

    private static function removeLocalStage(string $stage): void {
        $temporaryRoot = realpath(sys_get_temp_dir());
        if (!is_string($temporaryRoot)
            || !str_starts_with($stage, rtrim($temporaryRoot, DIRECTORY_SEPARATOR) . '/wprism-adopt-source-')) {
            return;
        }
        self::removeLocalNode($stage);
    }

    private static function removeLocalNode(string $path): void {
        $stat = @lstat($path);
        if (!is_array($stat)) {
            return;
        }
        if (($stat['mode'] & 0170000) !== 0040000 || is_link($path)) {
            @unlink($path);
            return;
        }
        $children = @scandir($path);
        if (!is_array($children)) {
            return;
        }
        foreach ($children as $child) {
            if ($child !== '.' && $child !== '..') {
                self::removeLocalNode($path . '/' . $child);
            }
        }
        @rmdir($path);
    }

    /** @return array{exit:int, stdout:string, stderr:string} */
    private static function runLocal(string $command): array {
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($command, $descriptors, $pipes);
        if (!is_resource($process)) {
            return ['exit' => 255, 'stdout' => '', 'stderr' => 'failed to start local process'];
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['exit' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }

    /** @param array{exit:int, stdout:string, stderr:string} $result */
    private static function fromTransport(string $phase, array $result, string $version): array {
        return [
            'exit' => $result['exit'] !== 0 ? $result['exit'] : 1,
            'phase' => $phase,
            'stdout' => $result['stdout'],
            'stderr' => $result['stderr'],
            'version' => $version,
            'repo_created' => false,
        ];
    }

    private static function failure(string $phase, string $message, string $version): array {
        return [
            'exit' => 1,
            'phase' => $phase,
            'stdout' => '',
            'stderr' => $message,
            'version' => $version,
            'repo_created' => false,
        ];
    }
}
