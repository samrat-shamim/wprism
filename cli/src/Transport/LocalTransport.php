<?php
declare(strict_types=1);

namespace WPrism\Orchestrator;

require_once __DIR__ . '/Transport.php';
require_once __DIR__ . '/RecoveryTransport.php';
require_once __DIR__ . '/RecoveryConfig.php';

/** Runs wp-cli directly on this machine: `wp --path=<wp_path> …`. */
final class LocalTransport extends Transport implements AdoptionTransport, RecoveryTransport {
    /** A local environment's `repo_path` IS a host path; nothing is derived. */
    public function hostRepoPath(): ?string {
        return rtrim($this->repoPath, '/');
    }

    public function hostRepoBoundaryPath(): ?string {
        return rtrim($this->repoPath, '/');
    }

    public const BOOTSTRAP_FORMAT = 'wprism-local-control-plane/v1';

    /**
     * The closed set of control-handoff paths this transport will create or
     * remove. `RollbackAuthority` sends exactly two shapes — `input` for
     * `execute --input=` and `request` for a signed receipt/event — and the
     * names match what SSH puts on the wire, which an offline fixture already
     * pins (sandbox/tests/fixtures/scoped-promote-unit.php:364).
     */
    private const CONTROL_INPUT_PATH = '#^/tmp/wprism-rollback-(?:input|request)-[a-f0-9]{32}\.json$#D';

    private string $wpPath;
    private bool $bootstrapAuthorized;
    private RecoveryConfig $recovery;

    /**
     * Reserved control-handoff paths, mapped to the dev:ino:type identity the
     * exclusive create observed — null until the bytes are placed. Removal
     * checks the recorded identity for the same reason
     * `cleanupUploadedFile()` does: on a shared /tmp, unlinking a path whose
     * inode was replaced would delete something this process never created.
     *
     * @var array<string,?string>
     */
    private array $controlInputs = [];

    public function __construct(string $name, array $cfg) {
        parent::__construct($name, $cfg);
        $this->wpPath = self::requireKey($cfg, $name, 'wp_path');
        $hasBootstrap = array_key_exists('bootstrap', $cfg);
        $bootstrap = $hasBootstrap ? $cfg['bootstrap'] : null;
        if ($hasBootstrap) {
            $keys = is_array($bootstrap) ? array_keys($bootstrap) : [];
            sort($keys, SORT_STRING);
            if ($keys !== ['format'] || ($bootstrap['format'] ?? null) !== self::BOOTSTRAP_FORMAT) {
                throw new \RuntimeException(
                    "env '$name': local bootstrap must be exactly "
                    . '{"format":"' . self::BOOTSTRAP_FORMAT . '"}'
                );
            }
            if (!self::safeAbsolutePath($this->wpPath) || !self::safeAbsolutePath($this->repoPath)) {
                throw new \RuntimeException(
                    "env '$name': local bootstrap requires absolute, normalized, non-root wp_path and repo_path"
                );
            }
        }
        $this->bootstrapAuthorized = $hasBootstrap && ($cfg['_machine_local'] ?? null) === true;

        // On a local target the controller IS the target, so the Ed25519
        // secret and the control root share one machine: the signature keeps
        // its tamper-evidence against a compromised recovery runtime or a
        // corrupted journal, but not against a compromised controller — a
        // strictly weaker property than the SSH case RollbackAuthority's
        // docblock states. Arming it therefore needs the same machine-local
        // provenance the local bootstrap needs (:63 above), which only the
        // machine-local overlay carries: Registry passes machineLocal=true for
        // an untracked `.wprism-envs.json` and for an explicitly selected
        // `--envs-file` (Environment/Registry.php:69/:125), and false for a
        // Git-tracked site.wprism.json (:39).
        if (RecoveryConfig::declaredIn($cfg) && ($cfg['_machine_local'] ?? null) !== true) {
            throw new \RuntimeException(
                "env '$name': local rollback authority is privileged and has no machine-local authorization"
            );
        }
        $this->recovery = RecoveryConfig::parse($name, $cfg, (string) ($cfg['_dir'] ?? '.'));
    }

    public function describe(): string {
        $bootstrap = $this->bootstrapAuthorized ? ' bootstrap=authorized' : '';
        // Suffixes appear only for an environment that opted in, the same
        // shape DockerTransport's `mode` suffix uses: `wprism envs` stays
        // byte-identical for every environment that never configured a
        // rollback authority (AGENTS.md rule 8).
        $recovery = $this->recovery->describeSuffix();
        return "local  wp_path={$this->wpPath} repo_path={$this->repoPath}{$bootstrap}{$recovery}";
    }

    /**
     * A local environment prints and fences its rollback authority only once
     * its keys are configured. SSH answers true unconditionally because
     * adoption has always provisioned the runtime there; making local
     * unconditional would put a new `[WARN] rollback authority: unavailable`
     * line into `wprism status` for every existing local environment.
     */
    public function carriesRollbackAuthority(): bool {
        return $this->recovery->configured();
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
     * Reserve, but do not create, one handoff path. The controller and the
     * target are the same filesystem here, so `/tmp` is reachable from both
     * sides exactly as it is over scp — what makes this safe is the exclusive
     * create in putControlInput(), not the directory.
     */
    public function allocateControlInput(string $label): string {
        $path = '/tmp/wprism-rollback-' . $label . '-' . bin2hex(random_bytes(16)) . '.json';
        if (preg_match(self::CONTROL_INPUT_PATH, $path) !== 1) {
            throw new \RuntimeException('wprism rollback: local control handoff label is outside the closed set');
        }
        $this->controlInputs[$path] = null;
        return $path;
    }

    /**
     * Place the canonical-JSON handoff at a path this transport reserved,
     * through the same verified exclusive copy adoption uses: `x` mode
     * refuses a symlink or a collision, umask 0077 makes it mode 0600, and
     * the bytes are proved by digest and by descriptor identity before the
     * path is bound.
     *
     * @return array{exit:int,stdout:string,stderr:string}
     */
    public function putControlInput(string $localPath, string $targetPath): array {
        if (!array_key_exists($targetPath, $this->controlInputs)
            || $this->controlInputs[$targetPath] !== null) {
            return [
                'exit' => 64,
                'stdout' => '',
                'stderr' => 'local control input destination was not reserved by this transport',
            ];
        }
        $result = self::exclusiveCopy($localPath, $targetPath, 'local control input');
        if ($result['exit'] === 0) {
            $this->controlInputs[$targetPath] = trim($result['stdout']);
        }
        return $result;
    }

    /**
     * Remove one placed handoff. Absence is success: the target-side
     * `trap finish EXIT; rm -f "$input"` in RollbackAuthority's script
     * normally wins the race, and this call is the crash-edge backstop that
     * runs on every observed exit.
     *
     * @return array{exit:int,stdout:string,stderr:string}
     */
    public function removeControlInput(string $targetPath): array {
        if (!array_key_exists($targetPath, $this->controlInputs)) {
            return [
                'exit' => 64,
                'stdout' => '',
                'stderr' => 'local control input path was not reserved by this transport',
            ];
        }
        $identity = $this->controlInputs[$targetPath];
        $stat = @lstat($targetPath);
        if ($stat === false) {
            unset($this->controlInputs[$targetPath]);
            return ['exit' => 0, 'stdout' => '', 'stderr' => ''];
        }
        if (!is_string($identity) || !is_array($stat) || self::identity($stat) !== $identity) {
            return [
                'exit' => 73,
                'stdout' => '',
                'stderr' => 'local control input identity changed and the path was retained for operator review',
            ];
        }
        if (!@unlink($targetPath)) {
            return ['exit' => 74, 'stdout' => '', 'stderr' => 'local control input could not be removed'];
        }
        unset($this->controlInputs[$targetPath]);
        return ['exit' => 0, 'stdout' => '', 'stderr' => ''];
    }

    public function wpPath(): string {
        return $this->wpPath;
    }

    /** @return array{supported:bool,reason:string,remediation:string} */
    public function bootstrapCapability(): array {
        if ($this->bootstrapAuthorized) {
            return [
                'supported' => true,
                'reason' => 'machine-local configuration authorizes the local bootstrap mechanism; adopt still proves target eligibility read-only before install',
                'remediation' => '',
            ];
        }
        return [
            'supported' => false,
            'reason' => 'local control-plane bootstrap is privileged and has no machine-local authorization',
            'remediation' => 'put bootstrap {"format":"' . self::BOOTSTRAP_FORMAT
                . '"} in this environment\'s untracked .wprism-envs.json entry, then retry',
        ];
    }

    /**
     * Copy one controller artifact to the unpredictable adoption path on the
     * same machine. Exclusive creation refuses symlinks and collisions; the
     * bytes are read and written once through verified descriptors.
     *
     * @return array{exit:int,stdout:string,stderr:string}
     */
    public function uploadFile(string $localPath, string $remotePath): array {
        if (preg_match('#^/tmp/wprism-adopt-[a-f0-9]{24}\.tar$#D', $remotePath) !== 1) {
            return ['exit' => 64, 'stdout' => '', 'stderr' => 'local adoption destination is outside the closed temporary path'];
        }
        return self::exclusiveCopy($localPath, $remotePath, 'local adoption');
    }

    /**
     * One verified exclusive copy on this machine, shared by the adoption
     * archive and the rollback-authority control handoff.
     *
     * `$subject` is the leading noun of every diagnostic below, so the
     * adoption strings stay byte-for-byte what they were before the second
     * caller existed (AGENTS.md rule 8) while the handoff names itself.
     *
     * @return array{exit:int,stdout:string,stderr:string}
     */
    private static function exclusiveCopy(string $localPath, string $remotePath, string $subject): array {
        $sourceLstat = @lstat($localPath);
        if (!is_array($sourceLstat) || ($sourceLstat['mode'] & 0170000) !== 0100000) {
            return ['exit' => 65, 'stdout' => '', 'stderr' => "$subject source is not a regular file"];
        }
        $source = @fopen($localPath, 'rb');
        if (!is_resource($source)) {
            return ['exit' => 66, 'stdout' => '', 'stderr' => "$subject source could not be opened"];
        }
        $priorUmask = umask(0077);
        $destination = @fopen($remotePath, 'xb');
        umask($priorUmask);
        if (!is_resource($destination)) {
            fclose($source);
            return ['exit' => 67, 'stdout' => '', 'stderr' => "$subject destination already exists or cannot be created"];
        }

        $exit = 0;
        $error = '';
        $sourceStat = fstat($source);
        $openedDestinationStat = fstat($destination);
        $postWriteDestinationStat = null;
        $sourceDigest = null;
        if (!is_array($sourceStat)
            || (int) $sourceStat['dev'] !== (int) $sourceLstat['dev']
            || (int) $sourceStat['ino'] !== (int) $sourceLstat['ino']
            || ($sourceStat['mode'] & 0170000) !== 0100000) {
            $exit = 68;
            $error = "$subject source changed while opening";
        } elseif (!is_array($openedDestinationStat) || ($openedDestinationStat['mode'] & 0170000) !== 0100000) {
            $exit = 68;
            $error = "$subject destination is not the exclusively created regular file";
        } else {
            $sourceHash = hash_init('sha256');
            $destinationHash = hash_init('sha256');
            $written = 0;
            while (!feof($source)) {
                $chunk = fread($source, 1048576);
                if (!is_string($chunk)) {
                    $exit = 69;
                    $error = "$subject source read failed";
                    break;
                }
                if ($chunk === '') {
                    continue;
                }
                hash_update($sourceHash, $chunk);
                $offset = 0;
                $length = strlen($chunk);
                while ($offset < $length) {
                    $count = fwrite($destination, substr($chunk, $offset));
                    if (!is_int($count) || $count < 1) {
                        $exit = 70;
                        $error = "$subject destination write failed";
                        break 2;
                    }
                    $piece = substr($chunk, $offset, $count);
                    hash_update($destinationHash, $piece);
                    $offset += $count;
                    $written += $count;
                }
            }
            if ($exit === 0) {
                $sourceDigest = hash_final($sourceHash);
                $destinationDigest = hash_final($destinationHash);
                if (!fflush($destination)) {
                    $exit = 71;
                    $error = "$subject transfer verification failed";
                } else {
                    $postWriteDestinationStat = fstat($destination);
                    if (!is_array($postWriteDestinationStat)
                        || ($postWriteDestinationStat['mode'] & 0170000) !== 0100000
                        || (int) $postWriteDestinationStat['size'] !== $written
                        || $written !== (int) $sourceStat['size']
                        || !hash_equals($sourceDigest, $destinationDigest)) {
                        $exit = 71;
                        $error = "$subject transfer verification failed";
                    }
                }
            }
        }
        fclose($source);
        $destinationClosed = fclose($destination);
        if ($exit === 0 && !$destinationClosed) {
            $exit = 71;
            $error = "$subject transfer verification failed";
        }
        if ($exit !== 0) {
            if (is_array($openedDestinationStat) && self::pathMatchesIdentity($remotePath, $openedDestinationStat)) {
                @unlink($remotePath);
            } else {
                $error .= '; destination identity changed and was retained for operator review';
            }
            return ['exit' => $exit, 'stdout' => '', 'stderr' => $error];
        }
        // COW filesystems may replace the path inode while the exclusively
        // opened descriptor is populated.  Keep the descriptor's post-write
        // byte/size proof above, then bind the token to the completed path
        // only after its own bytes and identity remain stable through a final
        // readback.  A foreign replacement is retained rather than deleted.
        $finalDestinationStat = @lstat($remotePath);
        $finalDigest = is_array($finalDestinationStat)
            && ($finalDestinationStat['mode'] & 0170000) === 0100000
            && (int) $finalDestinationStat['size'] === $written
            ? @hash_file('sha256', $remotePath)
            : false;
        $finalPathStable = is_array($finalDestinationStat)
            && is_string($sourceDigest)
            && is_string($finalDigest)
            && hash_equals($sourceDigest, $finalDigest)
            && self::pathMatchesIdentity($remotePath, $finalDestinationStat);
        if (!$finalPathStable) {
            return [
                'exit' => 72,
                'stdout' => '',
                'stderr' => "$subject destination final path could not be verified after transfer and was retained for operator review",
            ];
        }
        return ['exit' => 0, 'stdout' => self::identity($finalDestinationStat), 'stderr' => ''];
    }

    /**
     * Extract the filesystem identity returned by a successful exclusive
     * upload. Adopt binds both archive consumption and cleanup to this token.
     *
     * @param array{exit:int,stdout:string,stderr:string} $upload
     */
    public function uploadedFileIdentity(array $upload): string {
        $identity = trim($upload['stdout']);
        if ($upload['exit'] !== 0 || preg_match('/^[0-9]+:[0-9]+:32768$/D', $identity) !== 1) {
            throw new \RuntimeException('local adoption upload did not return a valid ownership identity');
        }
        return $identity;
    }

    /** @return array{exit:int,stdout:string,stderr:string} */
    public function cleanupUploadedFile(string $path, string $identity): array {
        if (preg_match('#^/tmp/wprism-adopt-[a-f0-9]{24}\.tar$#D', $path) !== 1
            || preg_match('/^[0-9]+:[0-9]+:32768$/D', $identity) !== 1) {
            return ['exit' => 64, 'stdout' => '', 'stderr' => 'local adoption cleanup identity is invalid'];
        }
        $stat = @lstat($path);
        if ($stat === false) {
            return ['exit' => 0, 'stdout' => '', 'stderr' => ''];
        }
        if (!is_array($stat) || self::identity($stat) !== $identity) {
            return [
                'exit' => 73,
                'stdout' => '',
                'stderr' => 'local adoption archive identity changed and the path was retained for operator review',
            ];
        }
        if (!@unlink($path)) {
            return ['exit' => 74, 'stdout' => '', 'stderr' => 'local adoption archive could not be removed'];
        }
        return ['exit' => 0, 'stdout' => '', 'stderr' => ''];
    }

    protected function wpCommand(array $wpArgs): string {
        return self::tokens(array_merge(['wp', '--path=' . $this->wpPath], $wpArgs));
    }

    protected function rawCommand(string $script): string {
        // proc_open()/passthru() already run string commands via the system
        // shell, so a raw snippet needs no extra wrapping here.
        return $script;
    }

    private static function safeAbsolutePath(string $path): bool {
        return $path !== ''
            && $path !== '/'
            && $path[0] === '/'
            && preg_match('/[\x00-\x1f\x7f]/', $path) !== 1
            && !str_contains($path, '//')
            && preg_match('#/(?:\.|\.\.)(?:/|$)#', $path) !== 1
            && !str_ends_with($path, '/');
    }

    /** @param array<string,int|float|string> $stat */
    private static function identity(array $stat): string {
        return (string) ((int) $stat['dev']) . ':'
            . (string) ((int) $stat['ino']) . ':'
            . (string) (((int) $stat['mode']) & 0170000);
    }

    /** @param array<string,int|float|string> $expected */
    private static function pathMatchesIdentity(string $path, array $expected): bool {
        $current = @lstat($path);
        return is_array($current) && self::identity($current) === self::identity($expected);
    }
}
