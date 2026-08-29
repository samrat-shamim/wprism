<?php
declare(strict_types=1);

namespace WPrism\Orchestrator;

require_once __DIR__ . '/Transport.php';
require_once __DIR__ . '/CodePushTransport.php';
require_once __DIR__ . '/RecoveryTransport.php';
require_once __DIR__ . '/RecoveryConfig.php';

/** Runs wp-cli over ssh: `ssh -T <host> 'cd <wp_path> && wp …'`. */
final class SshTransport extends Transport implements AdoptionTransport, CodePushTransport, RecoveryTransport {
    private string $host;
    private string $wpPath;
    private ?string $configFile;
    private RecoveryConfig $recovery;

    public function __construct(string $name, array $cfg) {
        parent::__construct($name, $cfg);
        $this->host = self::requireKey($cfg, $name, 'host');
        $this->wpPath = self::requireKey($cfg, $name, 'wp_path');
        $config = $cfg['ssh_config'] ?? null;
        if ($config !== null && (!is_string($config) || $config === '')) {
            throw new \RuntimeException("env '$name': optional key 'ssh_config' must be a non-empty path string");
        }
        $this->configFile = is_string($config)
            ? self::resolvePath((string) ($cfg['_dir'] ?? '.'), $config)
            : null;

        // The four rollback-authority keys and their refusal strings moved to
        // RecoveryConfig verbatim so a second transport accepts exactly what
        // SSH accepts; the check order there is the order this constructor
        // used, because an environment with several mistakes must keep
        // reporting the same first one.
        $this->recovery = RecoveryConfig::parse($name, $cfg, (string) ($cfg['_dir'] ?? '.'));
    }

    public function describe(): string {
        $config = $this->configFile !== null ? " ssh_config={$this->configFile}" : '';
        $recovery = $this->recovery->describeSuffix();
        return "ssh    host={$this->host} wp_path={$this->wpPath} repo_path={$this->repoPath}{$config}{$recovery}";
    }

    public function wpPath(): string {
        return $this->wpPath;
    }

    /** @return array{supported:bool,reason:string,remediation:string} */
    public function bootstrapCapability(): array {
        return [
            'supported' => true,
            'reason' => 'the SSH driver implements explicit control-plane transfer and bootstrap',
            'remediation' => '',
        ];
    }

    /**
     * Adoption provisions the rollback authority runtime on every SSH target
     * (cli/src/Onboarding/Adopt.php), and `wprism status` has printed its
     * authority line for every SSH environment since issue #3293 whether or not
     * this controller holds a signing key. Answering unconditionally is what
     * keeps that output byte-identical now that the predicate exists.
     */
    public function carriesRollbackAuthority(): bool {
        return true;
    }

    public function rollbackConfigured(): bool {
        return $this->recovery->configured();
    }

    public function rollbackKeyId(): ?string {
        return $this->recovery->keyId();
    }

    public function rollbackSigningKeyPath(): ?string {
        return $this->recovery->signingKeyPath();
    }

    public function recoveryConfigured(): bool {
        return $this->recovery->recoveryConfigured();
    }

    public function checkpointConfigured(): bool {
        return $this->recovery->providerConfigured('checkpoint_provider');
    }

    public function codeReleaseConfigured(): bool {
        return $this->recovery->providerConfigured('code_release_provider');
    }

    public function uploadProviderConfigured(): bool {
        return $this->recovery->providerConfigured('upload_provider');
    }

    public function effectProviderConfigured(): bool {
        return $this->recovery->providerConfigured('effect_provider');
    }

    public function verifiedRollbackConfigured(): bool {
        return $this->recovery->verified() !== null;
    }

    /** @return ?array{claim_ttl_seconds:int,encryption_key_id:string,retention_seconds:int} */
    public function verifiedRollbackConfig(): ?array {
        return $this->recovery->verified();
    }

    /** @return ?array<string,mixed> */
    public function recoveryConfig(): ?array {
        return $this->recovery->recovery();
    }

    /**
     * The remote handoff path, named exactly as RollbackAuthority named it
     * before the seam existed: an offline fixture's fake `scp` matches
     * `/tmp/wprism-rollback-request-*.json` by name
     * (sandbox/tests/fixtures/scoped-promote-unit.php:364), so the
     * label is wire, not decoration.
     */
    public function allocateControlInput(string $label): string {
        if (preg_match('/^[a-z][a-z0-9-]*$/D', $label) !== 1) {
            throw new \RuntimeException('wprism rollback: invalid control handoff label');
        }
        return '/tmp/wprism-rollback-' . $label . '-' . bin2hex(random_bytes(16)) . '.json';
    }

    /** @return array{exit:int, stdout:string, stderr:string} */
    public function putControlInput(string $localPath, string $targetPath): array {
        return $this->uploadFile($localPath, $targetPath);
    }

    /** @return array{exit:int, stdout:string, stderr:string} */
    public function removeControlInput(string $targetPath): array {
        return $this->captureRaw('rm -f ' . escapeshellarg($targetPath));
    }

    /**
     * The code-push archive path (issue #3514), named with the same shape and
     * the same label validation as the rollback handoff above.
     *
     * A distinct `wprism-code-push-` prefix rather than a shared one, for the
     * reason the `input`/`request` split already established: the live ssh
     * fixture asserts that a run which transfers nothing leaves no
     * `/tmp/wprism-code-push-*` behind, and a prefix shared with the rollback
     * handoff would make that assertion answer for two protocols.
     */
    public function allocateCodePushInput(string $label): string {
        if (preg_match('/^[a-z][a-z0-9-]*$/D', $label) !== 1) {
            throw new \RuntimeException('wprism code-resolve: invalid code push label');
        }
        return '/tmp/wprism-code-push-' . $label . '-' . bin2hex(random_bytes(16)) . '.tar';
    }

    /** @return array{exit:int, stdout:string, stderr:string} */
    public function putCodePushInput(string $localPath, string $targetPath): array {
        return $this->uploadFile($localPath, $targetPath);
    }

    /** @return array{exit:int, stdout:string, stderr:string} */
    public function removeCodePushInput(string $targetPath): array {
        return $this->captureRaw('rm -f ' . escapeshellarg($targetPath));
    }

    protected function wpCommand(array $wpArgs): string {
        $remote = 'cd ' . self::esc($this->wpPath) . ' && wp ' . self::tokens($wpArgs);
        return $this->sshPrefix() . ' ' . self::esc($this->host) . ' ' . self::esc($remote);
    }

    protected function rawCommand(string $script): string {
        // ssh already hands a single command-line argument to the remote
        // login shell for interpretation, same as the mission's `wp …`
        // pattern — no extra `bash -c` wrapper needed.
        return $this->sshPrefix() . ' ' . self::esc($this->host) . ' ' . self::esc($script);
    }

    /**
     * Copy one host-side file to an exact remote path using the same SSH
     * destination as command transport. Adoption intentionally owns this
     * narrow primitive instead of teaching every transport how to install
     * itself: only SSH describes a pre-existing host with no shared volume.
     *
     * @return array{exit:int, stdout:string, stderr:string}
     */
    public function uploadFile(string $localPath, string $remotePath): array {
        $destination = $this->host . ':' . $remotePath;
        $config = $this->configFile !== null ? ' -F ' . self::esc($this->configFile) : '';
        return self::runCapturing('scp' . $config . ' ' . self::esc($localPath) . ' ' . self::esc($destination));
    }

    private function sshPrefix(): string {
        // WPrism commands are non-interactive protocol calls. Explicitly disable
        // PTY allocation so a user's RequestTTY=force SSH configuration
        // cannot turn piped env-set input back into terminal-visible bytes.
        return 'ssh -T' . ($this->configFile !== null ? ' -F ' . self::esc($this->configFile) : '');
    }
}
