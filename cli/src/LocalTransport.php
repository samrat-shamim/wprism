<?php
namespace Duo\Orchestrator;

/** Runs wp-cli directly on this machine: `wp --path=<wp_path> …`. */
final class LocalTransport extends Transport implements AdoptionTransport {
    public const BOOTSTRAP_FORMAT = 'duo-local-control-plane/v1';

    private string $wpPath;
    private bool $bootstrapAuthorized;

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
    }

    public function describe(): string {
        $bootstrap = $this->bootstrapAuthorized ? ' bootstrap=authorized' : '';
        return "local  wp_path={$this->wpPath} repo_path={$this->repoPath}{$bootstrap}";
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
                . '"} in this environment\'s untracked .duo-envs.json entry, then retry',
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
        if (preg_match('#^/tmp/duo-adopt-[a-f0-9]{24}\.tar$#D', $remotePath) !== 1) {
            return ['exit' => 64, 'stdout' => '', 'stderr' => 'local adoption destination is outside the closed temporary path'];
        }
        $sourceLstat = @lstat($localPath);
        if (!is_array($sourceLstat) || ($sourceLstat['mode'] & 0170000) !== 0100000) {
            return ['exit' => 65, 'stdout' => '', 'stderr' => 'local adoption source is not a regular file'];
        }
        $source = @fopen($localPath, 'rb');
        if (!is_resource($source)) {
            return ['exit' => 66, 'stdout' => '', 'stderr' => 'local adoption source could not be opened'];
        }
        $priorUmask = umask(0077);
        $destination = @fopen($remotePath, 'xb');
        umask($priorUmask);
        if (!is_resource($destination)) {
            fclose($source);
            return ['exit' => 67, 'stdout' => '', 'stderr' => 'local adoption destination already exists or cannot be created'];
        }

        $exit = 0;
        $error = '';
        $sourceStat = fstat($source);
        $destinationStat = fstat($destination);
        if (!is_array($sourceStat)
            || (int) $sourceStat['dev'] !== (int) $sourceLstat['dev']
            || (int) $sourceStat['ino'] !== (int) $sourceLstat['ino']
            || ($sourceStat['mode'] & 0170000) !== 0100000) {
            $exit = 68;
            $error = 'local adoption source changed while opening';
        } elseif (!is_array($destinationStat) || ($destinationStat['mode'] & 0170000) !== 0100000) {
            $exit = 68;
            $error = 'local adoption destination is not the exclusively created regular file';
        } else {
            $sourceHash = hash_init('sha256');
            $destinationHash = hash_init('sha256');
            $written = 0;
            while (!feof($source)) {
                $chunk = fread($source, 1048576);
                if (!is_string($chunk)) {
                    $exit = 69;
                    $error = 'local adoption source read failed';
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
                        $error = 'local adoption destination write failed';
                        break 2;
                    }
                    $piece = substr($chunk, $offset, $count);
                    hash_update($destinationHash, $piece);
                    $offset += $count;
                    $written += $count;
                }
            }
            if ($exit === 0 && (!fflush($destination)
                || $written !== (int) $sourceStat['size']
                || hash_final($sourceHash) !== hash_final($destinationHash))) {
                $exit = 71;
                $error = 'local adoption transfer verification failed';
            }
        }
        fclose($source);
        fclose($destination);
        if ($exit !== 0) {
            if (is_array($destinationStat) && self::pathMatchesIdentity($remotePath, $destinationStat)) {
                @unlink($remotePath);
            } else {
                $error .= '; destination identity changed and was retained for operator review';
            }
            return ['exit' => $exit, 'stdout' => '', 'stderr' => $error];
        }
        if (!is_array($destinationStat) || !self::pathMatchesIdentity($remotePath, $destinationStat)) {
            return [
                'exit' => 72,
                'stdout' => '',
                'stderr' => 'local adoption destination identity changed after transfer and was retained for operator review',
            ];
        }
        return ['exit' => 0, 'stdout' => self::identity($destinationStat), 'stderr' => ''];
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
        if (preg_match('#^/tmp/duo-adopt-[a-f0-9]{24}\.tar$#D', $path) !== 1
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
