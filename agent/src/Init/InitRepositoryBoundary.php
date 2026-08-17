<?php
namespace Duo;

require_once __DIR__ . '/../Kernel/Canon.php';
require_once __DIR__ . '/InitOwnedArtifacts.php';
require_once __DIR__ . '/InitProtocol.php';

/** Repository path binding, Git readiness, and first-init ignore policy. */
final class InitRepositoryBoundary {
    private const ATTEMPT_FILE = InitProtocol::ATTEMPT_FILE;
    private const ATTEMPT_NEXT_FILE = InitProtocol::ATTEMPT_NEXT_FILE;

    public static function normalize(string $repo): string {
        $repo = rtrim(trim($repo), '/');
        if ($repo === '' || $repo === '/' || !str_starts_with($repo, '/')) {
            throw new \RuntimeException('duo: init requires an absolute, non-root repository path');
        }
        if (preg_match('#(?:^|/)\.{1,2}(?:/|$)#', $repo) === 1
            || str_contains($repo, '//')
            || preg_match('/[\x00-\x1F\x7F]/', $repo) === 1) {
            throw new \RuntimeException('duo: init requires a normalized repository path without traversal or control bytes');
        }
        return $repo;
    }

    /** @return ?array<string,string> */
    public static function root_blocker(string $repo): ?array {
        $current = '';
        $parts = explode('/', ltrim($repo, '/'));
        foreach ($parts as $index => $part) {
            $current .= '/' . $part;
            $stat = self::freshLstat($current);
            if ($stat === false) {
                return [
                    'code' => 'repository_root_missing', 'extension' => $repo, 'kind' => 'repository',
                    'reason' => 'the repository root and every parent must already exist before init can bind it safely',
                    'remediation' => 'create the ordinary repository root through the environment bootstrap/adopt path, then rerun init',
                ];
            }
            $type = ((int) $stat['mode']) & 0170000;
            if ($type === 0120000) {
                return [
                    'code' => 'unsafe_repository_root', 'extension' => $current, 'kind' => 'repository',
                    'reason' => 'the repository path contains a symbolic-link boundary',
                    'remediation' => 'use an existing ordinary directory reached through ordinary parent directories only',
                ];
            }
            if ($type !== 0040000) {
                return [
                    'code' => 'unsafe_repository_root', 'extension' => $current, 'kind' => 'repository',
                    'reason' => $index === count($parts) - 1
                        ? 'the repository root is not an ordinary directory'
                        : 'a repository parent is not an ordinary directory',
                    'remediation' => 'use an existing ordinary directory reached through ordinary parent directories only',
                ];
            }
        }
        return null;
    }

    /** @return array{previous_cwd:string,stat:array<string|int,mixed>,identity:string} */
    public static function bind(string $repo): array {
        $blocker = self::root_blocker($repo);
        if ($blocker !== null) {
            throw new \RuntimeException('duo: init cannot bind repository root: ' . $blocker['reason']);
        }
        $before = self::freshLstat($repo);
        $previous = getcwd();
        if ($before === false || !is_string($previous) || $previous === '') {
            throw new \RuntimeException('duo: init could not inspect its repository process boundary');
        }
        if (!@chdir($repo)) {
            throw new \RuntimeException('duo: init could not bind the reviewed repository directory');
        }
        try {
            $bound = self::freshLstat('.');
            if ($bound === false || !self::sameDirectoryIdentity($before, $bound)) {
                throw new \RuntimeException('duo: init repository root changed while it was being bound');
            }
            self::assert_binding($repo, $bound);
            return [
                'previous_cwd' => $previous,
                'stat' => $bound,
                'identity' => 'sha256:' . hash('sha256', Canon::encode([
                    'device' => (string) $bound['dev'],
                    'inode' => (string) $bound['ino'],
                ])),
            ];
        } catch (\Throwable $error) {
            @chdir($previous);
            throw $error;
        }
    }

    /** @param array<string|int,mixed> $expected */
    public static function assert_binding(string $repo, array $expected): void {
        $blocker = self::root_blocker($repo);
        $lexical = $blocker === null ? self::freshLstat($repo) : false;
        $bound = self::freshLstat('.');
        if ($blocker !== null || $lexical === false || $bound === false
            || !self::sameDirectoryIdentity($expected, $lexical)
            || !self::sameDirectoryIdentity($expected, $bound)) {
            throw new \RuntimeException('duo: init repository root changed after review; no lexical child path will be followed');
        }
    }

    /** @return array{mode:string,version:string,blockers:list<array<string,string>>} */
    public static function git_probe(string $repo): array {
        $versionResult = self::runProcess(['git', '--version']);
        $version = $versionResult['exit'] === 0 ? trim($versionResult['stdout']) : 'unavailable';
        $blockers = [];
        if ($versionResult['exit'] !== 0) {
            $blockers[] = [
                'code' => 'git_unavailable', 'extension' => 'git', 'kind' => 'repository',
                'reason' => 'Git is unavailable on the target that owns the site repository',
                'remediation' => 'install Git on the target, then rerun duo init',
            ];
            return ['mode' => 'unavailable', 'version' => $version, 'blockers' => $blockers];
        }
        if (is_link($repo)) {
            $blockers[] = [
                'code' => 'unsafe_repository_root', 'extension' => $repo, 'kind' => 'repository',
                'reason' => 'the repository root is not an ordinary directory',
                'remediation' => 'choose a non-symlinked directory owned by this site',
            ];
            return ['mode' => 'invalid', 'version' => $version, 'blockers' => $blockers];
        }
        if (!file_exists($repo)) {
            return ['mode' => 'initialize-on-confirm', 'version' => $version, 'blockers' => []];
        }
        if (!is_dir($repo)) {
            $blockers[] = [
                'code' => 'unsafe_repository_root', 'extension' => $repo, 'kind' => 'repository',
                'reason' => 'the repository root is not an ordinary directory',
                'remediation' => 'choose a non-symlinked directory owned by this site',
            ];
            return ['mode' => 'invalid', 'version' => $version, 'blockers' => $blockers];
        }
        if (is_link($repo . '/.gitignore')
            || (file_exists($repo . '/.gitignore') && !is_file($repo . '/.gitignore'))) {
            $blockers[] = [
                'code' => 'unsafe_gitignore', 'extension' => '.gitignore', 'kind' => 'repository',
                'reason' => 'the repository ignore path is not an ordinary regular file',
                'remediation' => 'replace it with an ordinary repository-owned file',
            ];
        }
        if (is_link($repo . '/.git')) {
            $blockers[] = [
                'code' => 'unsafe_git_metadata', 'extension' => '.git', 'kind' => 'repository',
                'reason' => 'the repository Git metadata root is a symbolic link',
                'remediation' => 'replace it with an ordinary worktree-owned .git directory or gitfile',
            ];
        }
        $captureLock = $repo . '/state.capture.lock';
        if (is_link($captureLock) || (file_exists($captureLock) && !is_file($captureLock))) {
            $blockers[] = [
                'code' => 'unsafe_capture_lock', 'extension' => 'state.capture.lock', 'kind' => 'repository',
                'reason' => 'the state publication lock is present but is not an ordinary regular file',
                'remediation' => 'remove the link or special file before initialization',
            ];
        }

        $allowed = array_fill_keys([
            '.', '..', '.duo', '.duo-env-values.json', '.duo-envs.json', '.git', '.gitignore', 'adapters',
            'code', 'media', 'site.duo.json', 'state', 'state.capture.lock', 'state.capture-receipt',
            self::ATTEMPT_FILE, self::ATTEMPT_NEXT_FILE,
        ], true);
        $unexpected = [];
        $entries = @scandir($repo);
        if ($entries === false) {
            $blockers[] = [
                'code' => 'unreadable_repository_root', 'extension' => $repo, 'kind' => 'repository',
                'reason' => 'the repository root cannot be enumerated, so init cannot prove that it contains only owned paths',
                'remediation' => 'restore read and directory-enumeration permission, then rerun init',
            ];
        } else {
            foreach ($entries as $entry) {
                if (!isset($allowed[$entry])) {
                    $unexpected[] = $entry;
                }
            }
        }
        sort($unexpected, SORT_STRING);
        if ($unexpected !== []) {
            $blockers[] = [
                'code' => 'repository_not_empty', 'extension' => implode(', ', array_slice($unexpected, 0, 8)),
                'kind' => 'repository',
                'reason' => 'the repository contains files that init does not own',
                'remediation' => 'move the foreign files or choose an empty/adoption-seed site repository',
            ];
        }

        $rootResult = self::runProcess(['git', '-C', $repo, 'rev-parse', '--show-toplevel']);
        if ($rootResult['exit'] !== 0) {
            if (file_exists($repo . '/.git')) {
                $blockers[] = [
                    'code' => 'invalid_git_worktree', 'extension' => '.git', 'kind' => 'repository',
                    'reason' => 'the repository contains Git metadata but is not a usable worktree',
                    'remediation' => 'repair or remove the invalid Git metadata, then rerun duo init',
                ];
                return ['mode' => 'invalid', 'version' => $version, 'blockers' => $blockers];
            }
            return ['mode' => 'initialize-on-confirm', 'version' => $version, 'blockers' => $blockers];
        }
        $actual = realpath(trim($rootResult['stdout']));
        $expectedRoot = realpath($repo);
        if ($actual === false || $expectedRoot === false || $actual !== $expectedRoot) {
            $blockers[] = [
                'code' => 'repository_not_git_root', 'extension' => $repo, 'kind' => 'repository',
                'reason' => 'the site repository resolves inside a different Git worktree',
                'remediation' => 'use a dedicated Git worktree whose top level is the site repository',
            ];
            return ['mode' => 'invalid', 'version' => $version, 'blockers' => $blockers];
        }
        return ['mode' => 'existing-worktree', 'version' => $version, 'blockers' => $blockers];
    }

    public static function initialize_git(string $repo): void {
        $result = self::runProcess(['git', 'init', '--initial-branch=main', $repo]);
        if ($result['exit'] !== 0 || self::git_probe($repo)['mode'] !== 'existing-worktree') {
            throw new \RuntimeException('duo: init could not create and verify the target Git worktree');
        }
    }

    /** @return ?array{previous:?string,published:string} */
    public static function ensure_gitignore(string $repo, string $expectedIdentity): ?array {
        $path = $repo . '/.gitignore';
        if (is_link($path) || (file_exists($path) && !is_file($path))) {
            throw new \RuntimeException('duo: init refuses a non-file .gitignore boundary');
        }
        $previous = is_file($path) ? Canon::read_file($path) : null;
        $identity = $previous === null ? 'absent' : InitOwnedArtifacts::regular_file_identity($path, '.gitignore');
        if ($expectedIdentity === '' || !hash_equals($expectedIdentity, $identity)) {
            throw new \RuntimeException('duo: init .gitignore boundary changed after proposal review');
        }
        $required = [
            '/.tmp*', '/.duo/', '/.duo-envs.json', '/.duo-init-code-*', '/.*.duo-init-*',
            '/' . self::ATTEMPT_FILE, '/' . self::ATTEMPT_NEXT_FILE,
            '/state.capture.lock', '/state.capture-staging/', '/state.capture-backup/',
            '/state.capture-intent', '/state.capture-receipt', '/state.capture-intent.tmp.*',
            '/state.capture-receipt.tmp.*', '/state.capture-intent.previous',
            '/state.capture-intent.next', '/state.capture-receipt.previous',
            '/state.capture-receipt.next', '/.duo-env-values.json',
        ];
        $next = $previous ?? '';
        $legacyRules = [
            '.tmp*', '.duo/', '.duo-envs.json', '.duo-init-code-*', '.*.duo-init-*',
            self::ATTEMPT_FILE, self::ATTEMPT_NEXT_FILE,
            'state.capture.lock', 'state.capture-staging/', 'state.capture-backup/',
            'state.capture-intent', 'state.capture-receipt', 'state.capture-intent.tmp.*',
            'state.capture-receipt.tmp.*', 'state.capture-intent.previous',
            'state.capture-intent.next', 'state.capture-receipt.previous',
            'state.capture-receipt.next', '.duo-env-values.json',
        ];
        foreach ($legacyRules as $legacyRule) {
            $next = (string) preg_replace(
                '/^' . preg_quote($legacyRule, '/') . '(?=\r?$)/m',
                '/' . $legacyRule,
                $next
            );
        }
        $migrated = $previous !== null && $next !== $previous;
        $lines = $next === '' ? [] : preg_split('/\r?\n/', $next);
        $known = array_fill_keys(is_array($lines) ? $lines : [], true);
        $missing = array_values(array_filter($required, static fn(string $line): bool => !isset($known[$line])));
        if ($missing === [] && !$migrated) {
            return null;
        }
        if ($missing !== []) {
            if ($next !== '' && !str_ends_with($next, "\n")) {
                $next .= "\n";
            }
            if ($next !== '') {
                $next .= "\n";
            }
            $next .= "# Duo local publication and environment artifacts\n" . implode("\n", $missing) . "\n";
        }
        return InitOwnedArtifacts::publish_owned_file($path, $next, $identity, '.gitignore');
    }

    /** @param array<string|int,mixed> $left @param array<string|int,mixed> $right */
    private static function sameDirectoryIdentity(array $left, array $right): bool {
        return (((int) ($left['mode'] ?? 0)) & 0170000) === 0040000
            && (((int) ($right['mode'] ?? 0)) & 0170000) === 0040000
            && (string) ($left['dev'] ?? '') === (string) ($right['dev'] ?? '')
            && (string) ($left['ino'] ?? '') === (string) ($right['ino'] ?? '');
    }

    /** @return array<string|int,mixed>|false */
    private static function freshLstat(string $path): array|false {
        clearstatcache(true, $path);
        return @lstat($path);
    }

    /** @return array{exit:int,stdout:string,stderr:string} */
    private static function runProcess(array $args): array {
        if (!function_exists('proc_open')) {
            return ['exit' => 127, 'stdout' => '', 'stderr' => ''];
        }
        $pipes = [];
        $process = @proc_open($args, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            return ['exit' => 127, 'stdout' => '', 'stderr' => ''];
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [
            'exit' => proc_close($process),
            'stdout' => is_string($stdout) ? $stdout : '',
            'stderr' => is_string($stderr) ? $stderr : '',
        ];
    }
}
