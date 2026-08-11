<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

/**
 * Bootstrap Duo onto a pre-existing WordPress target through an explicitly
 * authorized adoption transport.
 *
 * The host-side CLI is the source artifact: agent/ and manifests/ travel in
 * one archive, are staged before any live path changes, and replace the prior
 * installation under a rollback trap. The target needs neither git nor a
 * DUO_MANIFESTS_DIR login-shell environment variable. Manifests deliberately
 * land beside the installed duo/ directory, using Policy's built-in sibling
 * fallback; this avoids both an ephemeral environment variable and a
 * root-owned /duo-manifests prerequisite. A fresh wp-cli process verifies the
 * selected directory before this operation can report green.
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
        'spec_version' => 2,
    ];

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
        $manifestsDir = rtrim($sourceRoot, '/') . '/manifests';
        $version = self::agentVersion($agentDir . '/duo.php');
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
        if ($version === null || !is_file($agentDir . '/duo-loader.php') || !is_dir($manifestsDir)
            || !is_file($canonical) || !is_file($atomic) || !is_file($protocolLock) || !is_file($providerClient)
            || !is_file($runtime) || !is_file($executor) || !is_file($checkpoint) || !is_file($codeRelease)
            || !is_file($uploadBundle) || !is_file($effectBundle)) {
            return self::failure('local artifact', 'Duo source tree is incomplete: expected agent/, manifests/, and the complete recovery runtime', $version ?? 'unknown');
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
            $reachable = $transport->captureRaw('echo duo-reachable');
            if ($reachable['exit'] !== 0 || trim($reachable['stdout']) !== 'duo-reachable') {
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
        $localArchive = tempnam(sys_get_temp_dir(), 'duo-adopt-');
        if ($localArchive === false) {
            return self::failure('local artifact', 'could not allocate a temporary archive', $version);
        }
        $remoteArchive = '/tmp/duo-adopt-' . $token . '.tar';
        $remoteArchiveOwned = false;
        $remoteArchiveIdentity = null;
        $isolatedLocal = $eligibility !== null;
        $swapped = false;
        $interrupted = null;

        try {
            $archive = self::runLocal(
                'tar -C ' . escapeshellarg(rtrim($sourceRoot, '/'))
                . ' -cf ' . escapeshellarg($localArchive) . ' agent manifests recovery'
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

            $versionArgs = ['eval', 'echo defined("DUO_AGENT_VERSION") ? DUO_AGENT_VERSION : "duo-missing";'];
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
            $manifestPhp = var_export(rtrim($muDir, '/') . '/manifests', true);
            $policyArgs = [
                'eval',
                '$dir = \\Duo\\Policy::manifests_dir(); '
                    . 'if ($dir !== ' . $manifestPhp . ') { fwrite(STDERR, "unexpected manifests dir: $dir"); exit(71); } '
                    . 'try { \\Duo\\Policy::load(' . $repoPhp . '); } '
                    . 'catch (\\Throwable $e) { fwrite(STDERR, $e->getMessage()); exit(72); } '
                    . 'echo "duo-policy-ok";',
            ];
            $policy = $transport->captureWp(
                $isolatedLocal ? CodeDeploy::controlArgs($policyArgs) : $policyArgs
            );
            if ($policy['exit'] !== 0 || trim($policy['stdout']) !== 'duo-policy-ok') {
                self::rollback($transport, $muDir, $transport->repoPath(), $token, $policy);
                $swapped = false;
                return self::fromTransport('policy verification', $policy, $version);
            }

            $authority = $transport->captureRaw(
                'php ' . escapeshellarg(rtrim($transport->repoPath(), '/') . '/.duo/control/recovery-runtime/rollback-control.php')
                . ' status --root=' . escapeshellarg(rtrim($transport->repoPath(), '/') . '/.duo/control')
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
                'repo_created' => str_contains($install['stdout'], 'duo-repo-created'),
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
        $agent = rtrim($muDir, '/') . '/duo';
        $loader = rtrim($muDir, '/') . '/duo-loader.php';
        $manifest = rtrim($muDir, '/') . '/manifests';
        $seed = json_encode(self::SEED, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($seed)) {
            throw new \RuntimeException('could not encode adoption site-repo seed');
        }
        $seed .= "\n";
        $recovery = $recoveryConfig === null ? null : \Duo\Recovery\RollbackControl::canonical($recoveryConfig) . "\n";

        $q = static fn(string $value): string => escapeshellarg($value);
        $stage = '/tmp/duo-adopt-' . $token;
        $agentNew = rtrim($muDir, '/') . '/.duo-new-' . $token;
        $loaderNew = rtrim($muDir, '/') . '/.duo-loader-new-' . $token;
        $manifestNew = rtrim($muDir, '/') . '/.duo-manifests-new-' . $token;
        $agentOld = rtrim($muDir, '/') . '/.duo-old-' . $token;
        $loaderOld = rtrim($muDir, '/') . '/.duo-loader-old-' . $token;
        $manifestOld = rtrim($muDir, '/') . '/.duo-manifests-old-' . $token;
        $duoState = rtrim($repo, '/') . '/.duo';
        $duoNew = rtrim($repo, '/') . '/.duo-new-' . $token;
        $duoOld = rtrim($repo, '/') . '/.duo-old-' . $token;
        $control = $duoState . '/control';
        $controlNew = $duoNew . '/control';
        $runtime = $control . '/recovery-runtime';
        $runtimeNew = $controlNew . '/recovery-runtime';
        $scopedPromotionControl = $rollbackKeyId !== null && $recoveryConfig !== null
            ? \Duo\Recovery\RollbackControl::canonical([
                'control_root' => $control,
                'format' => 'duo-scoped-promotion-control/v1',
            ]) . "\n"
            : null;
        $site = rtrim($repo, '/') . '/site.duo.json';
        $siteNew = rtrim($repo, '/') . '/.site.duo.new-' . $token;
        $txn = rtrim($muDir, '/') . '/.duo-adopt-txn-' . $token;
        $lock = rtrim($muDir, '/') . '/.duo-adopt-lock';
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
            . 'manifest=' . $q($manifest) . "\n"
            . 'repo=' . $q($repo) . "\n"
            . 'site=' . $q($site) . "\n"
            . 'agent_new=' . $q($agentNew) . "\n"
            . 'loader_new=' . $q($loaderNew) . "\n"
            . 'manifest_new=' . $q($manifestNew) . "\n"
            . 'agent_old=' . $q($agentOld) . "\n"
            . 'loader_old=' . $q($loaderOld) . "\n"
            . 'manifest_old=' . $q($manifestOld) . "\n"
            . 'duo_state=' . $q($duoState) . "\n"
            . 'duo_new=' . $q($duoNew) . "\n"
            . 'duo_old=' . $q($duoOld) . "\n"
            . 'control=' . $q($control) . "\n"
            . 'control_new=' . $q($controlNew) . "\n"
            . 'runtime=' . $q($runtime) . "\n"
            . 'runtime_new=' . $q($runtimeNew) . "\n"
            . 'site_new=' . $q($siteNew) . "\n"
            . 'txn=' . $q($txn) . "\n"
            . 'lock=' . $q($lock) . "\n"
            . "had_agent=0; had_loader=0; had_manifest=0; had_duo=0; touched_agent=0; touched_loader=0; touched_manifest=0; touched_duo=0; seed_created=0; mu_created=0; mu_identity=''; repo_created=0; stage_created=0; agent_new_created=0; loader_new_created=0; manifest_new_created=0; duo_new_created=0; site_new_created=0; txn_created=0; lock_acquired=0; success=0\n"
            . "identity() { php -r " . $q($identityPhp) . " \"\$1\"; }\n"
            . "record_identity() { identity \"\$1\" > \"\$2\" || { echo 'duo adopt: could not record transaction identity' >&2; exit 1; }; }\n"
            . "assert_identity() { [ -f \"\$2\" ] || return 1; actual=\$(identity \"\$1\") || return 1; expected=\$(cat \"\$2\") || return 1; [ \"\$actual\" = \"\$expected\" ]; }\n"
            . "remove_owned() { path=\"\$1\"; proof=\"\$2\"; kind=\"\$3\"; [ ! -e \"\$path\" ] && [ ! -L \"\$path\" ] && return 0; assert_identity \"\$path\" \"\$proof\" || return 1; if [ \"\$kind\" = dir ]; then rm -rf \"\$path\"; else rm -f \"\$path\"; fi; }\n"
            . "restore_owned() { old=\"\$1\"; live=\"\$2\"; proof=\"\$3\"; assert_identity \"\$old\" \"\$proof\" || return 1; [ ! -e \"\$live\" ] && [ ! -L \"\$live\" ] || return 1; mv \"\$old\" \"\$live\"; }\n"
            . "finish() {\n"
            . "  status=\$?\n"
            . "  set +e; cleanup_failed=0; mu_proof=\"\$mu_identity\"\n"
            . "  if [ \"\$success\" -ne 1 ]; then\n"
            . "    if [ \"\$touched_duo\" -eq 1 ]; then remove_owned \"\$duo_state\" \"\$txn/duo_new.id\" dir || cleanup_failed=1; if [ \"\$had_duo\" -eq 1 ]; then restore_owned \"\$duo_old\" \"\$duo_state\" \"\$txn/duo_old.id\" || cleanup_failed=1; fi; fi\n"
            . "    if [ \"\$touched_manifest\" -eq 1 ]; then remove_owned \"\$manifest\" \"\$txn/manifest_new.id\" dir || cleanup_failed=1; if [ \"\$had_manifest\" -eq 1 ]; then restore_owned \"\$manifest_old\" \"\$manifest\" \"\$txn/manifest_old.id\" || cleanup_failed=1; fi; fi\n"
            . "    if [ \"\$touched_loader\" -eq 1 ]; then remove_owned \"\$loader\" \"\$txn/loader_new.id\" file || cleanup_failed=1; if [ \"\$had_loader\" -eq 1 ]; then restore_owned \"\$loader_old\" \"\$loader\" \"\$txn/loader_old.id\" || cleanup_failed=1; fi; fi\n"
            . "    if [ \"\$touched_agent\" -eq 1 ]; then remove_owned \"\$agent\" \"\$txn/agent_new.id\" dir || cleanup_failed=1; if [ \"\$had_agent\" -eq 1 ]; then restore_owned \"\$agent_old\" \"\$agent\" \"\$txn/agent_old.id\" || cleanup_failed=1; fi; fi\n"
            . "    if [ \"\$seed_created\" -eq 1 ]; then remove_owned \"\$site\" \"\$txn/site.id\" file || cleanup_failed=1; fi\n"
            . "  fi\n"
            . "  if [ \"\$stage_created\" -eq 1 ]; then remove_owned \"\$stage\" \"\$txn/stage.id\" dir || cleanup_failed=1; fi\n"
            . "  if [ \"\$agent_new_created\" -eq 1 ]; then remove_owned \"\$agent_new\" \"\$txn/agent_new.id\" dir || cleanup_failed=1; fi\n"
            . "  if [ \"\$loader_new_created\" -eq 1 ]; then remove_owned \"\$loader_new\" \"\$txn/loader_new.id\" file || cleanup_failed=1; fi\n"
            . "  if [ \"\$manifest_new_created\" -eq 1 ]; then remove_owned \"\$manifest_new\" \"\$txn/manifest_new.id\" dir || cleanup_failed=1; fi\n"
            . "  if [ \"\$duo_new_created\" -eq 1 ]; then remove_owned \"\$duo_new\" \"\$txn/duo_new.id\" dir || cleanup_failed=1; fi\n"
            . "  if [ \"\$site_new_created\" -eq 1 ]; then remove_owned \"\$site_new\" \"\$txn/site_new.id\" file || cleanup_failed=1; fi\n"
            . "  if [ \"\$success\" -ne 1 ]; then\n"
            . "    if [ \"\$repo_created\" -eq 1 ]; then assert_identity \"\$repo\" \"\$lock/repo.id\" && rmdir \"\$repo\" || cleanup_failed=1; fi\n"
            . "    if [ \"\$txn_created\" -eq 1 ]; then assert_identity \"\$txn\" \"\$lock/txn.id\" && rm -rf \"\$txn\" || cleanup_failed=1; fi\n"
            . "    if [ \"\$lock_acquired\" -eq 1 ]; then assert_identity \"\$lock\" \"\$lock/lock.id\" || cleanup_failed=1; if [ \"\$cleanup_failed\" -eq 0 ]; then mu_proof=\$(cat \"\$lock/mu.id\" 2>/dev/null || true); rm -f \"\$lock/lock.id\" \"\$lock/txn.id\" \"\$lock/repo.id\" \"\$lock/mu.id\"; rmdir \"\$lock\" || cleanup_failed=1; fi; fi\n"
            . "    if [ \"\$mu_created\" -eq 1 ]; then actual_mu=\$(identity " . $q($muDir) . " 2>/dev/null || true); [ -n \"\$mu_proof\" ] && [ \"\$actual_mu\" = \"\$mu_proof\" ] && rmdir " . $q($muDir) . " || cleanup_failed=1; fi\n"
            . "  fi\n"
            . "  if [ -e \"\$archive\" ] || [ -L \"\$archive\" ]; then if [ -n \"\$archive_identity\" ]; then actual_archive=\$(identity \"\$archive\" 2>/dev/null || true); [ \"\$actual_archive\" = \"\$archive_identity\" ] && rm -f \"\$archive\" || cleanup_failed=1; else rm -f \"\$archive\" || cleanup_failed=1; fi; fi\n"
            . "  if [ \"\$cleanup_failed\" -ne 0 ]; then echo 'duo adopt: transaction cleanup identity changed; retained evidence for operator recovery' >&2; status=1; fi\n"
            . "  exit \"\$status\"\n"
            . "}\n"
            . "trap finish EXIT\n"
            . "for command in php tar cp mv rm mkdir rmdir chmod find dirname ln cat; do command -v \"\$command\" >/dev/null 2>&1 || { echo 'duo adopt: target lacks a transaction command' >&2; exit 1; }; done\n"
            . "for path in \"\$stage\" \"\$agent_new\" \"\$loader_new\" \"\$manifest_new\" \"\$duo_new\" \"\$duo_old\" \"\$agent_old\" \"\$loader_old\" \"\$manifest_old\" \"\$site_new\" \"\$txn\"; do [ ! -e \"\$path\" ] && [ ! -L \"\$path\" ] || { echo 'duo adopt: transaction path collision' >&2; exit 1; }; done\n"
            . "for path in \"\$agent\" \"\$loader\" \"\$manifest\" \"\$site\" \"\$duo_state\" \"\$control\" \"\$runtime\"; do [ ! -L \"\$path\" ] || { echo \"duo adopt: refusing symlink destination: \$path\" >&2; exit 1; }; done\n"
            . "[ ! -e \"\$agent\" ] || [ -d \"\$agent\" ] || { echo \"duo adopt: expected directory destination: \$agent\" >&2; exit 1; }\n"
            . "[ ! -e \"\$loader\" ] || [ -f \"\$loader\" ] || { echo \"duo adopt: expected file destination: \$loader\" >&2; exit 1; }\n"
            . "[ ! -e \"\$manifest\" ] || [ -d \"\$manifest\" ] || { echo \"duo adopt: expected directory destination: \$manifest\" >&2; exit 1; }\n"
            . "[ ! -e \"\$site\" ] || [ -f \"\$site\" ] || { echo \"duo adopt: expected file destination: \$site\" >&2; exit 1; }\n"
            . "[ ! -e \"\$duo_state\" ] || [ -d \"\$duo_state\" ] || { echo \"duo adopt: expected directory destination: \$duo_state\" >&2; exit 1; }\n"
            . "[ ! -e \"\$control\" ] || [ -d \"\$control\" ] || { echo \"duo adopt: expected directory destination: \$control\" >&2; exit 1; }\n"
            . "[ ! -e \"\$runtime\" ] || [ -d \"\$runtime\" ] || { echo \"duo adopt: expected directory destination: \$runtime\" >&2; exit 1; }\n"
            . "if [ ! -e " . $q($muDir) . " ]; then mkdir " . $q($muDir) . "; mu_created=1; mu_identity=\$(identity " . $q($muDir) . "); fi\n"
            . "if ! mkdir \"\$lock\"; then echo 'duo adopt: another adoption is active or requires operator recovery (.duo-adopt-lock exists)' >&2; exit 1; fi; lock_acquired=1; record_identity \"\$lock\" \"\$lock/lock.id\"; if [ \"\$mu_created\" -eq 1 ]; then record_identity " . $q($muDir) . " \"\$lock/mu.id\"; fi\n"
            . "if [ ! -e \"\$repo\" ]; then mkdir \"\$repo\"; repo_created=1; record_identity \"\$repo\" \"\$lock/repo.id\"; fi\n"
            . "mkdir \"\$txn\"; txn_created=1; record_identity \"\$txn\" \"\$lock/txn.id\"; [ \"\$mu_created\" -eq 0 ] || : > \"\$txn/mu_created\"; [ \"\$repo_created\" -eq 0 ] || : > \"\$txn/repo_created\"\n"
            . "mkdir \"\$stage\"; stage_created=1; record_identity \"\$stage\" \"\$txn/stage.id\"\n"
            . ($archiveIdentity !== null
                ? "php -r " . $q($archiveCopyPhp) . " \"\$archive\" \"\$archive_identity\" \"\$stage/archive.tar\" || { echo 'duo adopt: local archive identity changed before staging' >&2; exit 1; }\n"
                    . "actual_archive=\$(identity \"\$archive\" 2>/dev/null || true); [ \"\$actual_archive\" = \"\$archive_identity\" ] || { echo 'duo adopt: local archive path changed before cleanup' >&2; exit 1; }; rm -f \"\$archive\"\n"
                    . "tar --no-same-owner -xf \"\$stage/archive.tar\" -C \"\$stage\"\n"
                : "tar --no-same-owner -xf \"\$archive\" -C \"\$stage\"\n")
            . "special=\$(find \"\$stage\" ! -type d ! -type f -print -quit 2>/dev/null) || { echo 'duo adopt: staged artifact could not be inspected' >&2; exit 1; }; [ -z \"\$special\" ] || { echo 'duo adopt: staged artifact contains a link or special node' >&2; exit 1; }\n"
            . "unreadable=\$(find \"\$stage\" -type f ! -exec test -r '{}' \; -print -quit 2>/dev/null) || { echo 'duo adopt: staged artifact could not be inspected' >&2; exit 1; }; [ -z \"\$unreadable\" ] || { echo 'duo adopt: staged artifact contains an unreadable file' >&2; exit 1; }\n"
            . "[ -f \"\$stage/agent/duo.php\" ] && [ -f \"\$stage/agent/duo-loader.php\" ] && [ -f \"\$stage/manifests/core.json\" ] || { echo 'duo adopt: uploaded artifact is incomplete' >&2; exit 1; }\n"
            . "[ -f \"\$stage/recovery/CanonicalJson.php\" ] && [ -f \"\$stage/recovery/AtomicStore.php\" ] && [ -f \"\$stage/recovery/ProtocolLock.php\" ] && [ -f \"\$stage/recovery/ProviderClient.php\" ] && [ -f \"\$stage/recovery/rollback-control.php\" ] && [ -f \"\$stage/recovery/RecoveryExecutor.php\" ] && [ -f \"\$stage/recovery/CheckpointBundle.php\" ] && [ -f \"\$stage/recovery/CodeRelease.php\" ] && [ -f \"\$stage/recovery/UploadBundle.php\" ] && [ -f \"\$stage/recovery/EffectBundle.php\" ] || { echo 'duo adopt: recovery runtime is missing' >&2; exit 1; }\n"
            . "mkdir \"\$agent_new\"; agent_new_created=1; record_identity \"\$agent_new\" \"\$txn/agent_new.id\"; cp -R \"\$stage/agent/.\" \"\$agent_new/\"\n"
            . "[ ! -e \"\$agent_new/scoped-promotion-control.json\" ] && [ ! -L \"\$agent_new/scoped-promotion-control.json\" ] || { echo 'duo adopt: source artifact contains target-local scoped promotion configuration' >&2; exit 1; }\n"
            . ($scopedPromotionControl !== null
                ? "printf '%s' " . $q($scopedPromotionControl) . " > \"\$agent_new/scoped-promotion-control.json\"; chmod 600 \"\$agent_new/scoped-promotion-control.json\"\n"
                : '')
            . "if (set -C; umask 077; : > \"\$loader_new\"); then loader_new_created=1; else echo 'duo adopt: loader staging collision' >&2; exit 1; fi; record_identity \"\$loader_new\" \"\$txn/loader_new.id\"; cp \"\$stage/agent/duo-loader.php\" \"\$loader_new\"\n"
            . "mkdir \"\$manifest_new\"; manifest_new_created=1; record_identity \"\$manifest_new\" \"\$txn/manifest_new.id\"; cp -R \"\$stage/manifests/.\" \"\$manifest_new/\"\n"
            . "mkdir \"\$duo_new\"; duo_new_created=1; record_identity \"\$duo_new\" \"\$txn/duo_new.id\"; if [ -e \"\$duo_state\" ]; then special=\$(find \"\$duo_state\" ! -type d ! -type f -print -quit 2>/dev/null) || { echo 'duo adopt: prior authority became unreadable' >&2; exit 1; }; [ -z \"\$special\" ] || { echo 'duo adopt: prior authority contains a link or special node' >&2; exit 1; }; unreadable=\$(find \"\$duo_state\" -type f ! -exec test -r '{}' \; -print -quit 2>/dev/null) || { echo 'duo adopt: prior authority became unreadable' >&2; exit 1; }; [ -z \"\$unreadable\" ] || { echo 'duo adopt: prior authority became unreadable' >&2; exit 1; }; cp -Rp \"\$duo_state/.\" \"\$duo_new/\"; fi\n"
            . "mkdir -p \"\$control_new\"; chmod 700 \"\$control_new\"; rm -rf \"\$runtime_new\"; cp -R \"\$stage/recovery\" \"\$runtime_new\"\n"
            . "php \"\$runtime_new/rollback-control.php\" init --root=\"\$control_new\" >/dev/null\n"
            . ($rollbackKeyId !== null
                ? "php \"\$runtime_new/rollback-control.php\" install-key --root=\"\$control_new\" --key-id=" . $q($rollbackKeyId) . ' --public-key=' . $q((string) $rollbackPublicKey) . " >/dev/null\n"
                : '')
            . ($recovery !== null
                ? "printf '%s' " . $q($recovery) . " > \"\$stage/recovery-config.json\"; chmod 600 \"\$stage/recovery-config.json\"\n"
                    . "php \"\$runtime_new/rollback-control.php\" configure-recovery --root=\"\$control_new\" --config=\"\$stage/recovery-config.json\" >/dev/null\n"
                    . "php \"\$runtime_new/rollback-control.php\" recovery-probe --root=\"\$control_new\" >/dev/null\n"
                : '')
            . "if [ ! -e \"\$site\" ]; then if (set -C; umask 077; : > \"\$site_new\"); then site_new_created=1; else echo 'duo adopt: seed staging collision' >&2; exit 1; fi; record_identity \"\$site_new\" \"\$txn/site_new.id\"; printf '%s' " . $q($seed) . " > \"\$site_new\"; if ln \"\$site_new\" \"\$site\"; then record_identity \"\$site\" \"\$txn/site.id\"; rm -f \"\$site_new\"; site_new_created=0; seed_created=1; : > \"\$txn/seed_created\"; else echo 'duo adopt: site policy appeared during bootstrap' >&2; exit 1; fi; fi\n"
            . "if [ -e \"\$agent\" ]; then record_identity \"\$agent\" \"\$txn/agent_old.id\"; mv \"\$agent\" \"\$agent_old\"; had_agent=1; : > \"\$txn/had_agent\"; fi; touched_agent=1; mv \"\$agent_new\" \"\$agent\"\n"
            . "if [ -e \"\$loader\" ]; then record_identity \"\$loader\" \"\$txn/loader_old.id\"; mv \"\$loader\" \"\$loader_old\"; had_loader=1; : > \"\$txn/had_loader\"; fi; touched_loader=1; mv \"\$loader_new\" \"\$loader\"\n"
            . "if [ -e \"\$manifest\" ]; then record_identity \"\$manifest\" \"\$txn/manifest_old.id\"; mv \"\$manifest\" \"\$manifest_old\"; had_manifest=1; : > \"\$txn/had_manifest\"; fi; touched_manifest=1; mv \"\$manifest_new\" \"\$manifest\"\n"
            . "if [ -e \"\$duo_state\" ]; then record_identity \"\$duo_state\" \"\$txn/duo_old.id\"; mv \"\$duo_state\" \"\$duo_old\"; had_duo=1; : > \"\$txn/had_duo\"; fi; touched_duo=1; mv \"\$duo_new\" \"\$duo_state\"\n"
            . "success=1\n"
            . "if [ \"\$seed_created\" -eq 1 ]; then echo duo-repo-created; else echo duo-repo-retained; fi\n"
            . "echo duo-install-complete\n";
    }

    private static function commitBarrierScript(string $muDir, string $repo, string $token): string {
        $q = static fn(string $value): string => escapeshellarg($value);
        $root = rtrim($muDir, '/');
        $txn = $root . '/.duo-adopt-txn-' . $token;
        $lock = $root . '/.duo-adopt-lock';
        $identityPhp = '$s = @lstat($argv[1]); if (!is_array($s)) { exit(1); } '
            . 'echo (string) $s["dev"], ":", (string) $s["ino"], ":", (string) ($s["mode"] & 0170000);';
        $pairs = [
            ['had_agent', $root . '/.duo-old-' . $token, 'agent_old.id', 'dir'],
            ['had_loader', $root . '/.duo-loader-old-' . $token, 'loader_old.id', 'file'],
            ['had_manifest', $root . '/.duo-manifests-old-' . $token, 'manifest_old.id', 'dir'],
            ['had_duo', rtrim($repo, '/') . '/.duo-old-' . $token, 'duo_old.id', 'dir'],
        ];
        $script = 'set -eu' . "\n"
            . 'txn=' . $q($txn) . '; lock=' . $q($lock) . "\n"
            . "identity() { php -r " . $q($identityPhp) . " \"\$1\"; }\n"
            . "assert_identity() { [ -f \"\$2\" ] || return 1; actual=\$(identity \"\$1\") || return 1; expected=\$(cat \"\$2\") || return 1; [ \"\$actual\" = \"\$expected\" ]; }\n"
            . "assert_identity \"\$lock\" \"\$lock/lock.id\" && assert_identity \"\$txn\" \"\$lock/txn.id\" || { echo 'duo adopt: transaction identity changed before commit' >&2; exit 1; }\n";
        foreach ($pairs as [$marker, $path, $proof, $kind]) {
            $quoted = $q($path);
            $script .= "if [ -f \"\$txn/$marker\" ]; then assert_identity $quoted \"\$txn/$proof\" || { echo 'duo adopt: rollback copy identity changed before commit' >&2; exit 1; }; "
                . "else [ ! -e $quoted ] && [ ! -L $quoted ] || { echo 'duo adopt: unexpected rollback copy before commit' >&2; exit 1; }; fi\n";
        }
        return $script
            . "[ ! -e \"\$txn/commit_started\" ] && [ ! -L \"\$txn/commit_started\" ] || { echo 'duo adopt: commit marker collision' >&2; exit 1; }\n"
            . "if (set -C; umask 077; : > \"\$txn/commit_started\"); then echo duo-adopt-commit-barrier; else echo 'duo adopt: could not publish commit barrier' >&2; exit 1; fi";
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
        $txn = $root . '/.duo-adopt-txn-' . $token;
        $lock = $root . '/.duo-adopt-lock';
        $identityPhp = '$s = @lstat($argv[1]); if (!is_array($s)) { exit(1); } '
            . 'echo (string) $s["dev"], ":", (string) $s["ino"], ":", (string) ($s["mode"] & 0170000);';
        $pairs = [
            ['had_agent', $root . '/.duo-old-' . $token, 'agent_old.id', 'dir'],
            ['had_loader', $root . '/.duo-loader-old-' . $token, 'loader_old.id', 'file'],
            ['had_manifest', $root . '/.duo-manifests-old-' . $token, 'manifest_old.id', 'dir'],
            ['had_duo', rtrim($repo, '/') . '/.duo-old-' . $token, 'duo_old.id', 'dir'],
        ];
        $script = 'set -u' . "\n"
            . 'txn=' . $q($txn) . '; lock=' . $q($lock) . "\n"
            . "identity() { php -r " . $q($identityPhp) . " \"\$1\"; }\n"
            . "assert_identity() { [ -f \"\$2\" ] || return 1; actual=\$(identity \"\$1\") || return 1; expected=\$(cat \"\$2\") || return 1; [ \"\$actual\" = \"\$expected\" ]; }\n"
            . "assert_identity \"\$lock\" \"\$lock/lock.id\" && assert_identity \"\$txn\" \"\$lock/txn.id\" && [ -f \"\$txn/commit_started\" ] || { echo 'duo adopt: committed cleanup evidence is incomplete' >&2; exit 1; }\n";
        foreach ($pairs as [$marker, $path, $proof, $kind]) {
            $quoted = $q($path);
            $script .= "if [ -f \"\$txn/$marker\" ]; then assert_identity $quoted \"\$txn/$proof\" || { echo 'duo adopt: rollback copy identity changed before committed cleanup' >&2; exit 1; }; "
                . "else [ ! -e $quoted ] && [ ! -L $quoted ] || { echo 'duo adopt: unexpected rollback copy before committed cleanup' >&2; exit 1; }; fi\n";
        }
        $script .= "cleanup_failed=0\n";
        foreach ($pairs as [$marker, $path, $proof, $kind]) {
            $quoted = $q($path);
            $remove = $kind === 'dir' ? 'rm -rf ' : 'rm -f ';
            $script .= "if [ -f \"\$txn/$marker\" ]; then $remove$quoted || cleanup_failed=1; fi\n";
        }
        return $script
            . "if [ \"\$cleanup_failed\" -ne 0 ]; then echo 'duo adopt: committed install retained partial backup cleanup evidence' >&2; exit 1; fi\n"
            . "assert_identity \"\$txn\" \"\$lock/txn.id\" || { echo 'duo adopt: transaction identity changed before committed cleanup' >&2; exit 1; }\n"
            . "rm -rf \"\$txn\" || { echo 'duo adopt: committed install retained transaction cleanup evidence' >&2; exit 1; }\n"
            . "assert_identity \"\$lock\" \"\$lock/lock.id\" || { echo 'duo adopt: adoption lock identity changed before committed cleanup' >&2; exit 1; }\n"
            . "rm -f \"\$lock/lock.id\" \"\$lock/txn.id\" \"\$lock/repo.id\" \"\$lock/mu.id\" || { echo 'duo adopt: committed install retained lock cleanup evidence' >&2; exit 1; }\n"
            . "rmdir \"\$lock\" || { echo 'duo adopt: committed install retained adoption lock evidence' >&2; exit 1; }";
    }

    private static function rollbackScript(string $muDir, string $repo, string $token): string {
        $q = static fn(string $value): string => escapeshellarg($value);
        $root = rtrim($muDir, '/');
        $txn = $root . '/.duo-adopt-txn-' . $token;
        $agent = $root . '/duo';
        $loader = $root . '/duo-loader.php';
        $manifest = $root . '/manifests';
        $agentOld = $root . '/.duo-old-' . $token;
        $loaderOld = $root . '/.duo-loader-old-' . $token;
        $manifestOld = $root . '/.duo-manifests-old-' . $token;
        $duoState = rtrim($repo, '/') . '/.duo';
        $duoOld = rtrim($repo, '/') . '/.duo-old-' . $token;
        $site = rtrim($repo, '/') . '/site.duo.json';
        $lock = $root . '/.duo-adopt-lock';
        $identityPhp = '$s = @lstat($argv[1]); if (!is_array($s)) { exit(1); } '
            . 'echo (string) $s["dev"], ":", (string) $s["ino"], ":", (string) ($s["mode"] & 0170000);';

        return 'set -eu' . "\n"
            . 'txn=' . $q($txn) . '; lock=' . $q($lock) . '; [ -d "$txn" ] || exit 0' . "\n"
            . "identity() { php -r " . $q($identityPhp) . " \"\$1\"; }\n"
            . "assert_identity() { [ -f \"\$2\" ] || return 1; actual=\$(identity \"\$1\") || return 1; expected=\$(cat \"\$2\") || return 1; [ \"\$actual\" = \"\$expected\" ]; }\n"
            . "remove_owned() { assert_identity \"\$1\" \"\$2\" || { echo 'duo adopt: live transaction identity changed before rollback' >&2; exit 1; }; if [ \"\$3\" = dir ]; then rm -rf \"\$1\"; else rm -f \"\$1\"; fi; }\n"
            . "restore_owned() { assert_identity \"\$1\" \"\$3\" || { echo 'duo adopt: rollback copy identity changed' >&2; exit 1; }; [ ! -e \"\$2\" ] && [ ! -L \"\$2\" ] || { echo 'duo adopt: rollback destination changed' >&2; exit 1; }; mv \"\$1\" \"\$2\"; }\n"
            . "assert_identity \"\$lock\" \"\$lock/lock.id\" && assert_identity \"\$txn\" \"\$lock/txn.id\" || { echo 'duo adopt: transaction identity changed before rollback' >&2; exit 1; }\n"
            . 'remove_owned ' . $q($duoState) . ' "$txn/duo_new.id" dir; if [ -f "$txn/had_duo" ]; then restore_owned ' . $q($duoOld) . ' ' . $q($duoState) . ' "$txn/duo_old.id"; fi' . "\n"
            . 'remove_owned ' . $q($manifest) . ' "$txn/manifest_new.id" dir; if [ -f "$txn/had_manifest" ]; then restore_owned ' . $q($manifestOld) . ' ' . $q($manifest) . ' "$txn/manifest_old.id"; fi' . "\n"
            . 'remove_owned ' . $q($loader) . ' "$txn/loader_new.id" file; if [ -f "$txn/had_loader" ]; then restore_owned ' . $q($loaderOld) . ' ' . $q($loader) . ' "$txn/loader_old.id"; fi' . "\n"
            . 'remove_owned ' . $q($agent) . ' "$txn/agent_new.id" dir; if [ -f "$txn/had_agent" ]; then restore_owned ' . $q($agentOld) . ' ' . $q($agent) . ' "$txn/agent_old.id"; fi' . "\n"
            . 'if [ -f "$txn/seed_created" ]; then remove_owned ' . $q($site) . ' "$txn/site.id" file; fi' . "\n"
            . 'repo_created=0; mu_created=0; [ ! -f "$txn/repo_created" ] || repo_created=1; [ ! -f "$txn/mu_created" ] || mu_created=1' . "\n"
            . 'repo_proof=$(cat "$lock/repo.id" 2>/dev/null || true); mu_proof=$(cat "$lock/mu.id" 2>/dev/null || true)' . "\n"
            . 'assert_identity "$txn" "$lock/txn.id"; rm -rf "$txn"' . "\n"
            . 'assert_identity "$lock" "$lock/lock.id"; rm -f "$lock/lock.id" "$lock/txn.id" "$lock/repo.id" "$lock/mu.id"; rmdir "$lock"' . "\n"
            . 'if [ "$repo_created" -eq 1 ]; then actual=$(identity ' . $q(rtrim($repo, '/')) . '); [ -n "$repo_proof" ] && [ "$actual" = "$repo_proof" ] || { echo "duo adopt: created repository identity changed" >&2; exit 1; }; rmdir ' . $q(rtrim($repo, '/')) . '; fi' . "\n"
            . 'if [ "$mu_created" -eq 1 ]; then actual=$(identity ' . $q($root) . '); [ -n "$mu_proof" ] && [ "$actual" = "$mu_proof" ] || { echo "duo adopt: created control root identity changed" >&2; exit 1; }; rmdir ' . $q($root) . '; fi';
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
            || preg_match("/define\\(\\s*'DUO_AGENT_VERSION'\\s*,\\s*'([^']+)'\\s*\\)/", $source, $m) !== 1) {
            return null;
        }
        return $m[1];
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
