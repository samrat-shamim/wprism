<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

/**
 * Bootstrap Duo onto a pre-existing WordPress host reached only through SSH.
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
        SshTransport $transport,
        string $sourceRoot,
        ?string $rollbackKeyId = null,
        ?string $rollbackPublicKey = null
    ): array {
        $agentDir = rtrim($sourceRoot, '/') . '/agent';
        $manifestsDir = rtrim($sourceRoot, '/') . '/manifests';
        $version = self::agentVersion($agentDir . '/duo.php');
        $runtime = rtrim($sourceRoot, '/') . '/recovery/rollback-control.php';
        if ($version === null || !is_file($agentDir . '/duo-loader.php') || !is_dir($manifestsDir)
            || !is_file($runtime)) {
            return self::failure('local artifact', 'Duo source tree is incomplete: expected agent/, manifests/, and recovery/rollback-control.php', $version ?? 'unknown');
        }
        if (($rollbackKeyId === null) !== ($rollbackPublicKey === null)) {
            return self::failure('local artifact', 'rollback key id and public key must be supplied together', $version);
        }

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

        $token = bin2hex(random_bytes(12));
        $localArchive = tempnam(sys_get_temp_dir(), 'duo-adopt-');
        if ($localArchive === false) {
            return self::failure('local artifact', 'could not allocate a temporary archive', $version);
        }
        $remoteArchive = '/tmp/duo-adopt-' . $token . '.tar';
        $swapped = false;

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

            $install = $transport->captureRaw(self::installScript(
                $remoteArchive,
                $muDir,
                $transport->repoPath(),
                $token,
                $rollbackKeyId,
                $rollbackPublicKey
            ));
            if ($install['exit'] !== 0) {
                return self::fromTransport('remote install', $install, $version);
            }
            $swapped = true;

            $remoteVersion = $transport->captureWp(['eval', 'echo defined("DUO_AGENT_VERSION") ? DUO_AGENT_VERSION : "duo-missing";']);
            if ($remoteVersion['exit'] !== 0 || trim($remoteVersion['stdout']) !== $version) {
                $remoteVersion['stderr'] .= ($remoteVersion['stderr'] !== '' ? "\n" : '')
                    . "installed agent version mismatch: expected $version, got '" . trim($remoteVersion['stdout']) . "'";
                self::rollback($transport, $muDir, $transport->repoPath(), $token, $remoteVersion);
                $swapped = false;
                return self::fromTransport('agent version verification', $remoteVersion, $version);
            }

            $repoPhp = var_export($transport->repoPath(), true);
            $manifestPhp = var_export(rtrim($muDir, '/') . '/manifests', true);
            $policy = $transport->captureWp([
                'eval',
                '$dir = \\Duo\\Policy::manifests_dir(); '
                    . 'if ($dir !== ' . $manifestPhp . ') { fwrite(STDERR, "unexpected manifests dir: $dir"); exit(71); } '
                    . 'try { \\Duo\\Policy::load(' . $repoPhp . '); } '
                    . 'catch (\\Throwable $e) { fwrite(STDERR, $e->getMessage()); exit(72); } '
                    . 'echo "duo-policy-ok";',
            ]);
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

            $commit = $transport->captureRaw(self::commitScript($muDir, $transport->repoPath(), $token));
            if ($commit['exit'] !== 0) {
                self::rollback($transport, $muDir, $transport->repoPath(), $token, $commit);
                $swapped = false;
                return self::fromTransport('install commit', $commit, $version);
            }
            $swapped = false;

            return [
                'exit' => 0,
                'phase' => 'complete',
                'stdout' => $install['stdout'],
                'stderr' => $install['stderr'],
                'version' => $version,
                'repo_created' => str_contains($install['stdout'], 'duo-repo-created'),
            ];
        } finally {
            if ($swapped) {
                // Exceptions and interrupted host-side verification retain the
                // same fail-closed boundary as explicit verification errors.
                $transport->captureRaw(self::rollbackScript($muDir, $transport->repoPath(), $token));
            }
            @unlink($localArchive);
            // An upload followed by a lost SSH session must not strand the
            // source archive. This cleanup is idempotent; the remote install
            // trap normally removed it already.
            $transport->captureRaw('rm -f ' . escapeshellarg($remoteArchive));
        }
    }

    private static function installScript(
        string $archive,
        string $muDir,
        string $repo,
        string $token,
        ?string $rollbackKeyId,
        ?string $rollbackPublicKey
    ): string {
        $agent = rtrim($muDir, '/') . '/duo';
        $loader = rtrim($muDir, '/') . '/duo-loader.php';
        $manifest = rtrim($muDir, '/') . '/manifests';
        $seed = json_encode(self::SEED, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($seed)) {
            throw new \RuntimeException('could not encode adoption site-repo seed');
        }
        $seed .= "\n";

        $q = static fn(string $value): string => escapeshellarg($value);
        $stage = '/tmp/duo-adopt-' . $token;
        $agentNew = rtrim($muDir, '/') . '/.duo-new-' . $token;
        $loaderNew = rtrim($muDir, '/') . '/.duo-loader-new-' . $token;
        $manifestNew = rtrim($muDir, '/') . '/.duo-manifests-new-' . $token;
        $agentOld = rtrim($muDir, '/') . '/.duo-old-' . $token;
        $loaderOld = rtrim($muDir, '/') . '/.duo-loader-old-' . $token;
        $manifestOld = rtrim($muDir, '/') . '/.duo-manifests-old-' . $token;
        $control = rtrim($repo, '/') . '/.duo/control';
        $duoState = rtrim($repo, '/') . '/.duo';
        $runtime = $control . '/recovery-runtime';
        $runtimeNew = $control . '/.recovery-runtime-new-' . $token;
        $runtimeOld = $control . '/.recovery-runtime-old-' . $token;
        $site = rtrim($repo, '/') . '/site.duo.json';
        $siteNew = rtrim($repo, '/') . '/.site.duo.new-' . $token;
        $txn = rtrim($muDir, '/') . '/.duo-adopt-txn-' . $token;
        $lock = rtrim($muDir, '/') . '/.duo-adopt-lock';

        return 'set -eu' . "\n"
            . 'archive=' . $q($archive) . "\n"
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
            . 'control=' . $q($control) . "\n"
            . 'duo_state=' . $q($duoState) . "\n"
            . 'runtime=' . $q($runtime) . "\n"
            . 'runtime_new=' . $q($runtimeNew) . "\n"
            . 'runtime_old=' . $q($runtimeOld) . "\n"
            . 'site_new=' . $q($siteNew) . "\n"
            . 'txn=' . $q($txn) . "\n"
            . 'lock=' . $q($lock) . "\n"
            . "had_agent=0; had_loader=0; had_manifest=0; had_runtime=0; touched_agent=0; touched_loader=0; touched_manifest=0; touched_runtime=0; seed_created=0; lock_acquired=0; success=0\n"
            . "finish() {\n"
            . "  status=\$?\n"
            . "  if [ \"\$success\" -ne 1 ]; then\n"
            . "    if [ \"\$touched_agent\" -eq 1 ]; then rm -rf \"\$agent\"; [ \"\$had_agent\" -eq 0 ] || mv \"\$agent_old\" \"\$agent\"; fi\n"
            . "    if [ \"\$touched_loader\" -eq 1 ]; then rm -f \"\$loader\"; [ \"\$had_loader\" -eq 0 ] || mv \"\$loader_old\" \"\$loader\"; fi\n"
            . "    if [ \"\$touched_manifest\" -eq 1 ]; then rm -rf \"\$manifest\"; [ \"\$had_manifest\" -eq 0 ] || mv \"\$manifest_old\" \"\$manifest\"; fi\n"
            . "    if [ \"\$touched_runtime\" -eq 1 ]; then rm -rf \"\$runtime\"; [ \"\$had_runtime\" -eq 0 ] || mv \"\$runtime_old\" \"\$runtime\"; fi\n"
            . "    [ \"\$seed_created\" -eq 0 ] || rm -f \"\$site\"\n"
            . "  fi\n"
            . "  rm -rf \"\$stage\" \"\$agent_new\" \"\$loader_new\" \"\$manifest_new\" \"\$runtime_new\" \"\$site_new\"\n"
            . "  if [ \"\$success\" -ne 1 ]; then rm -rf \"\$txn\"; [ \"\$lock_acquired\" -eq 0 ] || rmdir \"\$lock\"; fi\n"
            . "  rm -f \"\$archive\"\n"
            . "  exit \"\$status\"\n"
            . "}\n"
            . "trap finish EXIT\n"
            . "for path in \"\$agent\" \"\$loader\" \"\$manifest\" \"\$site\" \"\$duo_state\" \"\$control\" \"\$runtime\"; do [ ! -L \"\$path\" ] || { echo \"duo adopt: refusing symlink destination: \$path\" >&2; exit 1; }; done\n"
            . "[ ! -e \"\$agent\" ] || [ -d \"\$agent\" ] || { echo \"duo adopt: expected directory destination: \$agent\" >&2; exit 1; }\n"
            . "[ ! -e \"\$loader\" ] || [ -f \"\$loader\" ] || { echo \"duo adopt: expected file destination: \$loader\" >&2; exit 1; }\n"
            . "[ ! -e \"\$manifest\" ] || [ -d \"\$manifest\" ] || { echo \"duo adopt: expected directory destination: \$manifest\" >&2; exit 1; }\n"
            . "[ ! -e \"\$site\" ] || [ -f \"\$site\" ] || { echo \"duo adopt: expected file destination: \$site\" >&2; exit 1; }\n"
            . "[ ! -e \"\$duo_state\" ] || [ -d \"\$duo_state\" ] || { echo \"duo adopt: expected directory destination: \$duo_state\" >&2; exit 1; }\n"
            . "[ ! -e \"\$control\" ] || [ -d \"\$control\" ] || { echo \"duo adopt: expected directory destination: \$control\" >&2; exit 1; }\n"
            . "[ ! -e \"\$runtime\" ] || [ -d \"\$runtime\" ] || { echo \"duo adopt: expected directory destination: \$runtime\" >&2; exit 1; }\n"
            . "mkdir -p \"\$stage\" " . $q($muDir) . " \"\$repo\"\n"
            . "if ! mkdir \"\$lock\"; then echo 'duo adopt: another adoption is active or requires operator recovery (.duo-adopt-lock exists)' >&2; exit 1; fi; lock_acquired=1\n"
            . "mkdir \"\$txn\"\n"
            . "tar --no-same-owner -xf \"\$archive\" -C \"\$stage\"\n"
            . "[ -f \"\$stage/agent/duo.php\" ] && [ -f \"\$stage/agent/duo-loader.php\" ] && [ -f \"\$stage/manifests/core.json\" ] || { echo 'duo adopt: uploaded artifact is incomplete' >&2; exit 1; }\n"
            . "[ -f \"\$stage/recovery/rollback-control.php\" ] || { echo 'duo adopt: rollback runtime is missing' >&2; exit 1; }\n"
            . "cp -R \"\$stage/agent\" \"\$agent_new\"\n"
            . "cp \"\$stage/agent/duo-loader.php\" \"\$loader_new\"\n"
            . "cp -R \"\$stage/manifests\" \"\$manifest_new\"\n"
            . "mkdir -p \"\$control\"; chmod 700 \"\$control\"; cp -R \"\$stage/recovery\" \"\$runtime_new\"\n"
            . "if [ ! -e \"\$site\" ]; then printf '%s' " . $q($seed) . " > \"\$site_new\"; mv \"\$site_new\" \"\$site\"; seed_created=1; : > \"\$txn/seed_created\"; fi\n"
            . "if [ -e \"\$agent\" ]; then mv \"\$agent\" \"\$agent_old\"; had_agent=1; : > \"\$txn/had_agent\"; fi; touched_agent=1; mv \"\$agent_new\" \"\$agent\"\n"
            . "if [ -e \"\$loader\" ]; then mv \"\$loader\" \"\$loader_old\"; had_loader=1; : > \"\$txn/had_loader\"; fi; touched_loader=1; mv \"\$loader_new\" \"\$loader\"\n"
            . "if [ -e \"\$manifest\" ]; then mv \"\$manifest\" \"\$manifest_old\"; had_manifest=1; : > \"\$txn/had_manifest\"; fi; touched_manifest=1; mv \"\$manifest_new\" \"\$manifest\"\n"
            . "if [ -e \"\$runtime\" ]; then mv \"\$runtime\" \"\$runtime_old\"; had_runtime=1; : > \"\$txn/had_runtime\"; fi; touched_runtime=1; mv \"\$runtime_new\" \"\$runtime\"\n"
            . "php \"\$runtime/rollback-control.php\" init --root=\"\$control\" >/dev/null\n"
            . ($rollbackKeyId !== null
                ? "php \"\$runtime/rollback-control.php\" install-key --root=\"\$control\" --key-id=" . $q($rollbackKeyId) . ' --public-key=' . $q((string) $rollbackPublicKey) . " >/dev/null\n"
                : '')
            . "success=1\n"
            . "if [ \"\$seed_created\" -eq 1 ]; then echo duo-repo-created; else echo duo-repo-retained; fi\n"
            . "echo duo-install-complete\n";
    }

    private static function commitScript(string $muDir, string $repo, string $token): string {
        $q = static fn(string $value): string => escapeshellarg($value);
        $root = rtrim($muDir, '/');
        return 'set -eu; rm -rf '
            . $q($root . '/.duo-old-' . $token) . ' '
            . $q($root . '/.duo-loader-old-' . $token) . ' '
            . $q($root . '/.duo-manifests-old-' . $token) . ' '
            . $q(rtrim($repo, '/') . '/.duo/control/.recovery-runtime-old-' . $token) . ' '
            . $q($root . '/.duo-adopt-txn-' . $token)
            . '; rmdir ' . $q($root . '/.duo-adopt-lock');
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
        $runtime = rtrim($repo, '/') . '/.duo/control/recovery-runtime';
        $runtimeOld = rtrim($repo, '/') . '/.duo/control/.recovery-runtime-old-' . $token;
        $site = rtrim($repo, '/') . '/site.duo.json';

        return 'set -eu' . "\n"
            . 'txn=' . $q($txn) . '; [ -d "$txn" ] || exit 0' . "\n"
            . 'rm -rf ' . $q($agent) . '; if [ -f "$txn/had_agent" ]; then mv ' . $q($agentOld) . ' ' . $q($agent) . '; fi' . "\n"
            . 'rm -f ' . $q($loader) . '; if [ -f "$txn/had_loader" ]; then mv ' . $q($loaderOld) . ' ' . $q($loader) . '; fi' . "\n"
            . 'rm -rf ' . $q($manifest) . '; if [ -f "$txn/had_manifest" ]; then mv ' . $q($manifestOld) . ' ' . $q($manifest) . '; fi' . "\n"
            . 'rm -rf ' . $q($runtime) . '; if [ -f "$txn/had_runtime" ]; then mv ' . $q($runtimeOld) . ' ' . $q($runtime) . '; fi' . "\n"
            . 'if [ -f "$txn/seed_created" ]; then rm -f ' . $q($site) . '; fi' . "\n"
            . 'rm -rf "$txn"' . "\n"
            . 'rmdir ' . $q($root . '/.duo-adopt-lock');
    }

    /**
     * Roll back a swapped release and append any rollback failure to the
     * original diagnostic so a failed recovery can never be hidden.
     *
     * @param array{exit:int, stdout:string, stderr:string} &$original
     */
    private static function rollback(
        SshTransport $transport,
        string $muDir,
        string $repo,
        string $token,
        array &$original
    ): void {
        $rollback = $transport->captureRaw(self::rollbackScript($muDir, $repo, $token));
        if ($rollback['exit'] === 0) {
            return;
        }
        $detail = trim($rollback['stderr'] !== '' ? $rollback['stderr'] : $rollback['stdout']);
        $original['stderr'] .= ($original['stderr'] !== '' ? "\n" : '')
            . 'adoption rollback could not be confirmed'
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
