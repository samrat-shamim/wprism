<?php
declare(strict_types=1);

namespace WPrism\Orchestrator;

require_once __DIR__ . '/../Transport/Transport.php';

/** Ownership-checked, evidence-preserving removal of the adopted control plane. */
final class Unadopt {
    public const PLAN_FORMAT = 'wprism-unadopt-plan/v1';
    public const RECEIPT_FORMAT = 'wprism-unadopt-archive/v1';

    /** @return array<string,mixed> */
    public static function plan(AdoptionTransport $transport, string $archive): array {
        $archive = self::normalizeArchive($archive);
        $repo = rtrim($transport->repoPath(), '/');
        if ($repo === '' || $repo === '/' || !str_starts_with($repo, '/')) {
            throw new \RuntimeException('wprism: unadopt requires an absolute non-root repository path');
        }
        $muResult = $transport->captureWp(['eval', 'echo WPMU_PLUGIN_DIR;']);
        $mu = rtrim(trim($muResult['stdout']), '/');
        if ($muResult['exit'] !== 0 || $mu === '' || $mu === '/' || !str_starts_with($mu, '/')) {
            throw new \RuntimeException('wprism: unadopt could not discover the installed MU-plugin directory');
        }
        self::assertDisjointArchive($archive, $repo, $mu);

        $probe = $transport->captureRaw(self::probeScript($mu, $repo, $archive));
        if ($probe['exit'] !== 0) {
            $detail = trim($probe['stderr'] !== '' ? $probe['stderr'] : $probe['stdout']);
            throw new \RuntimeException(
                'wprism: unadopt ownership preflight failed'
                . ($detail !== '' ? ': ' . $detail : '')
            );
        }
        $rows = [];
        foreach (preg_split('/\r?\n/', trim($probe['stdout'])) ?: [] as $line) {
            if (preg_match('/^([a-z0-9_]+)=([^\r\n]+)$/D', $line, $match) === 1) {
                $rows[$match[1]] = $match[2];
            }
        }
        foreach (['agent_version', 'agent_sha256', 'loader_sha256', 'control_sha256', 'site_identity'] as $key) {
            if (!isset($rows[$key]) || trim($rows[$key]) === '') {
                throw new \RuntimeException("wprism: unadopt ownership probe omitted $key");
            }
        }
        foreach (['agent_sha256', 'loader_sha256', 'control_sha256'] as $key) {
            if (preg_match('/^[a-f0-9]{64}$/D', $rows[$key]) !== 1) {
                throw new \RuntimeException("wprism: unadopt ownership probe returned malformed $key");
            }
        }
        if ($rows['site_identity'] !== 'absent'
            && preg_match('/^[a-f0-9]{64}$/D', $rows['site_identity']) !== 1) {
            throw new \RuntimeException('wprism: unadopt ownership probe returned a malformed site boundary');
        }

        $plan = [
            'format' => self::PLAN_FORMAT,
            'agent_version' => $rows['agent_version'],
            'archive' => $archive,
            'repository' => $repo,
            'mu_plugins' => $mu,
            'surfaces' => [
                ['kind' => 'directory', 'name' => 'agent', 'path' => $mu . '/wprism', 'sha256' => $rows['agent_sha256']],
                ['kind' => 'file', 'name' => 'loader', 'path' => $mu . '/wprism-loader.php', 'sha256' => $rows['loader_sha256']],
                ['kind' => 'directory', 'name' => 'control', 'path' => $repo . '/.wprism', 'sha256' => $rows['control_sha256']],
            ],
            'preserved_in_place' => [
                $repo . '/site.wprism.json',
                $repo . '/code',
                $repo . '/media',
                $repo . '/state',
                $repo . '/.git',
                $repo . '/.gitattributes',
                $repo . '/.gitignore',
                $mu . '/wprism-control',
            ],
            'site_identity' => $rows['site_identity'],
        ];
        $plan['digest'] = hash('sha256', self::encode($plan));
        return $plan;
    }

    /**
     * @param array<string,mixed> $reviewedPlan
     * @return array{exit:int,phase:string,stdout:string,stderr:string,receipt?:array<string,mixed>}
     */
    public static function execute(AdoptionTransport $transport, array $reviewedPlan): array {
        $expectedDigest = (string) ($reviewedPlan['digest'] ?? '');
        $archive = (string) ($reviewedPlan['archive'] ?? '');
        $fresh = self::plan($transport, $archive);
        if ($expectedDigest === '' || !hash_equals($expectedDigest, (string) $fresh['digest'])) {
            return self::failure(
                'stale plan',
                'target control-plane or repository evidence changed after review; request and confirm a fresh unadopt plan'
            );
        }
        $mu = (string) $fresh['mu_plugins'];
        $repo = (string) $fresh['repository'];
        $token = bin2hex(random_bytes(12));
        $receipt = self::receipt($fresh);
        $receiptBytes = self::encode($receipt);
        $staged = false;
        $interrupted = null;
        try {
            $stage = $transport->captureRaw(self::stageScript($fresh, $token, $receiptBytes));
            if ($stage['exit'] !== 0) {
                return self::fromTransport('archive and stage', $stage);
            }
            $staged = true;

            $wordpress = $transport->captureWp(['core', 'is-installed']);
            if ($wordpress['exit'] !== 0) {
                self::rollback($transport, $fresh, $token, $wordpress);
                $staged = false;
                return self::fromTransport('WordPress verification', $wordpress);
            }
            $absence = $transport->captureWp([
                'eval',
                'echo defined("WPRISM_AGENT_VERSION") ? "wprism-present" : "wprism-absent";',
            ]);
            if ($absence['exit'] !== 0 || trim($absence['stdout']) !== 'wprism-absent') {
                $absence['stderr'] .= ($absence['stderr'] !== '' ? "\n" : '')
                    . 'WPrism remained loaded after its MU loader was staged out';
                self::rollback($transport, $fresh, $token, $absence);
                $staged = false;
                return self::fromTransport('control-plane absence verification', $absence);
            }

            $commit = $transport->captureRaw(self::commitScript($fresh, $token, $receiptBytes));
            if ($commit['exit'] !== 0) {
                self::rollback($transport, $fresh, $token, $commit);
                $staged = false;
                return self::fromTransport('commit', $commit);
            }
            $staged = false;
            return [
                'exit' => 0,
                'phase' => 'complete',
                'stdout' => $commit['stdout'],
                'stderr' => $commit['stderr'],
                'receipt' => $receipt,
            ];
        } catch (\Throwable $error) {
            $interrupted = $error;
            throw $error;
        } finally {
            if ($staged) {
                $failure = ['exit' => 255, 'stdout' => '', 'stderr' => ''];
                try {
                    self::rollback($transport, $fresh, $token, $failure);
                } catch (\Throwable $rollbackError) {
                    $detail = trim($rollbackError->getMessage());
                    throw new \RuntimeException(
                        ($interrupted instanceof \Throwable ? $interrupted->getMessage() . "\n" : '')
                        . 'wprism: unadopt rollback could not be confirmed'
                        . ($detail !== '' ? ': ' . $detail : ''),
                        0,
                        $interrupted
                    );
                }
            }
        }
    }

    /** @param array<string,mixed> $plan @return array<string,mixed> */
    private static function receipt(array $plan): array {
        return [
            'format' => self::RECEIPT_FORMAT,
            'agent_version' => $plan['agent_version'],
            'archive' => $plan['archive'],
            'plan_digest' => $plan['digest'],
            'preserved_in_place' => $plan['preserved_in_place'],
            'repository' => $plan['repository'],
            'surfaces' => array_map(
                static fn(array $surface): array => [
                    'archive_path' => match ($surface['name']) {
                        'agent' => 'mu-plugins/wprism',
                        'loader' => 'mu-plugins/wprism-loader.php',
                        'control' => 'repository/.wprism',
                    },
                    'name' => $surface['name'],
                    'sha256' => $surface['sha256'],
                    'source_path' => $surface['path'],
                ],
                $plan['surfaces']
            ),
        ];
    }

    private static function probeScript(string $mu, string $repo, string $archive): string {
        $q = static fn(string $value): string => escapeshellarg($value);
        $fingerprint = self::fingerprintPhp();
        $recognize = '$loader = @file_get_contents($argv[1]); $agent = @file_get_contents($argv[2]); '
            . '$runtime = $argv[3]; if (!is_string($loader) || !is_string($agent) '
            . '|| strpos($loader, "require_once __DIR__ . \'/wprism/wprism.php\';") === false '
            . '|| preg_match("/define\\(\\s*\'WPRISM_AGENT_VERSION\'\\s*,\\s*\'([^\']+)\'\\s*\\)/", $agent, $m) !== 1 '
            . '|| !is_file($runtime) || is_link($runtime)) { exit(1); } echo $m[1];';
        $archiveParent = dirname($archive);
        return 'set -eu' . "\n"
            . 'mu=' . $q($mu) . '; repo=' . $q($repo) . '; archive=' . $q($archive) . "\n"
            . 'agent="$mu/wprism"; loader="$mu/wprism-loader.php"; control="$repo/.wprism"' . "\n"
            . '[ -d "$agent" ] && [ ! -L "$agent" ] && [ -f "$loader" ] && [ ! -L "$loader" ] '
                . '&& [ -d "$control" ] && [ ! -L "$control" ] || { echo "installed WPrism surfaces are absent or unsafe" >&2; exit 1; }' . "\n"
            . '[ ! -e "$mu/.wprism-adopt-lock" ] && [ ! -L "$mu/.wprism-adopt-lock" ] '
                . '&& [ ! -e "$mu/.wprism-unadopt-lock" ] && [ ! -L "$mu/.wprism-unadopt-lock" ] '
                . '|| { echo "a control-plane transaction is active or requires recovery" >&2; exit 1; }' . "\n"
            . '[ ! -e "$archive" ] && [ ! -L "$archive" ] || { echo "archive destination already exists" >&2; exit 1; }' . "\n"
            . 'php -r ' . $q('$p = $argv[1]; $r = realpath($p); exit(is_string($r) && $r === $p && is_dir($p) && !is_link($p) ? 0 : 1);')
                . ' ' . $q($archiveParent) . ' || { echo "archive parent must be an existing normalized ordinary directory" >&2; exit 1; }' . "\n"
            . 'version=$(php -r ' . $q($recognize) . ' "$loader" "$agent/wprism.php" "$control/control/recovery-runtime/rollback-control.php") '
                . '|| { echo "the installed paths do not identify a complete WPrism control plane" >&2; exit 1; }' . "\n"
            . 'agent_hash=$(php -r ' . $q($fingerprint) . ' "$agent" directory) || { echo "agent tree contains an unreadable, linked, or special node" >&2; exit 1; }' . "\n"
            . 'loader_hash=$(php -r ' . $q($fingerprint) . ' "$loader" file) || { echo "loader is unreadable" >&2; exit 1; }' . "\n"
            . 'control_hash=$(php -r ' . $q($fingerprint) . ' "$control" directory) || { echo "control tree contains an unreadable, linked, or special node" >&2; exit 1; }' . "\n"
            . 'site_identity=absent; if [ -e "$repo/site.wprism.json" ] || [ -L "$repo/site.wprism.json" ]; then '
                . '[ -f "$repo/site.wprism.json" ] && [ ! -L "$repo/site.wprism.json" ] || { echo "site.wprism.json boundary is unsafe" >&2; exit 1; }; '
                . 'site_identity=$(php -r ' . $q($fingerprint) . ' "$repo/site.wprism.json" file); fi' . "\n"
            . 'printf "agent_version=%s\\nagent_sha256=%s\\nloader_sha256=%s\\ncontrol_sha256=%s\\nsite_identity=%s\\n" '
                . '"$version" "$agent_hash" "$loader_hash" "$control_hash" "$site_identity"';
    }

    /** @param array<string,mixed> $plan */
    private static function stageScript(array $plan, string $token, string $receiptBytes): string {
        $q = static fn(string $value): string => escapeshellarg($value);
        $mu = (string) $plan['mu_plugins'];
        $repo = (string) $plan['repository'];
        $archive = (string) $plan['archive'];
        $agentHash = (string) $plan['surfaces'][0]['sha256'];
        $loaderHash = (string) $plan['surfaces'][1]['sha256'];
        $controlHash = (string) $plan['surfaces'][2]['sha256'];
        $fingerprint = self::fingerprintPhp();
        return 'set -eu' . "\n"
            . 'mu=' . $q($mu) . '; repo=' . $q($repo) . '; archive=' . $q($archive) . '; token=' . $q($token) . "\n"
            . 'agent="$mu/wprism"; loader="$mu/wprism-loader.php"; control="$repo/.wprism"' . "\n"
            . 'agent_old="$mu/.wprism-unadopt-agent-$token"; loader_old="$mu/.wprism-loader-unadopt-$token"; control_old="$repo/.wprism-unadopt-$token"' . "\n"
            . 'lock="$mu/.wprism-unadopt-lock"; txn="$lock/transaction"' . "\n"
            . 'fingerprint() { php -r ' . $q($fingerprint) . ' "$1" "$2"; }' . "\n"
            . 'expected_agent=' . $q($agentHash) . '; expected_loader=' . $q($loaderHash)
                . '; expected_control=' . $q($controlHash) . "\n"
            . 'success=0; moved_agent=0; moved_loader=0; moved_control=0' . "\n"
            . 'finish() { rc=$?; set +e; if [ "$success" -ne 1 ]; then failed=0; '
                . 'if [ "$moved_control" -eq 1 ]; then [ ! -e "$control" ] && [ ! -L "$control" ] && [ "$(fingerprint "$control_old" directory)" = "$expected_control" ] && mv "$control_old" "$control" || failed=1; fi; '
                . 'if [ "$moved_agent" -eq 1 ]; then [ ! -e "$agent" ] && [ ! -L "$agent" ] && [ "$(fingerprint "$agent_old" directory)" = "$expected_agent" ] && mv "$agent_old" "$agent" || failed=1; fi; '
                . 'if [ "$moved_loader" -eq 1 ]; then [ ! -e "$loader" ] && [ ! -L "$loader" ] && [ "$(fingerprint "$loader_old" file)" = "$expected_loader" ] && mv "$loader_old" "$loader" || failed=1; fi; '
                . 'if [ "$failed" -eq 0 ]; then rm -rf "$lock"; else echo "wprism unadopt: rollback retained exact backups and lock for operator recovery" >&2; rc=1; fi; '
                . 'echo "wprism unadopt: archive retained at $archive" >&2; fi; exit "$rc"; }' . "\n"
            . 'trap finish EXIT' . "\n"
            . 'for path in "$archive" "$agent_old" "$loader_old" "$control_old" "$lock"; do [ ! -e "$path" ] && [ ! -L "$path" ] || { echo "transaction path collision: $path" >&2; exit 1; }; done' . "\n"
            . '[ "$(fingerprint "$agent" directory)" = "$expected_agent" ] '
                . '&& [ "$(fingerprint "$loader" file)" = "$expected_loader" ] '
                . '&& [ "$(fingerprint "$control" directory)" = "$expected_control" ] '
                . '|| { echo "reviewed control-plane bytes changed before archive" >&2; exit 1; }' . "\n"
            . 'mkdir "$lock"; mkdir "$txn"; printf "%s\\n" "$token" > "$txn/token"; printf "%s\\n" "$archive" > "$txn/archive"' . "\n"
            . 'mkdir "$archive"; chmod 700 "$archive"; mkdir "$archive/mu-plugins" "$archive/repository"' . "\n"
            . 'cp -Rp "$agent" "$archive/mu-plugins/wprism"; cp -p "$loader" "$archive/mu-plugins/wprism-loader.php"; cp -Rp "$control" "$archive/repository/.wprism"' . "\n"
            . '[ "$(fingerprint "$archive/mu-plugins/wprism" directory)" = "$expected_agent" ] '
                . '&& [ "$(fingerprint "$archive/mu-plugins/wprism-loader.php" file)" = "$expected_loader" ] '
                . '&& [ "$(fingerprint "$archive/repository/.wprism" directory)" = "$expected_control" ] '
                . '|| { echo "archive copy did not preserve the reviewed control-plane bytes" >&2; exit 1; }' . "\n"
            . 'printf %s ' . $q($receiptBytes) . ' > "$archive/receipt.json"; chmod 600 "$archive/receipt.json"; sync' . "\n"
            . 'printf "%s\\n" "$expected_agent" > "$txn/agent.sha256"; printf "%s\\n" "$expected_loader" > "$txn/loader.sha256"; printf "%s\\n" "$expected_control" > "$txn/control.sha256"; : > "$txn/archive-ready"; sync' . "\n"
            . 'mv "$loader" "$loader_old"; moved_loader=1; mv "$agent" "$agent_old"; moved_agent=1; mv "$control" "$control_old"; moved_control=1; : > "$txn/staged"; sync' . "\n"
            . 'success=1; echo wprism-unadopt-staged';
    }

    /** @param array<string,mixed> $plan */
    private static function commitScript(array $plan, string $token, string $receiptBytes): string {
        $q = static fn(string $value): string => escapeshellarg($value);
        $mu = (string) $plan['mu_plugins'];
        $repo = (string) $plan['repository'];
        $archive = (string) $plan['archive'];
        $fingerprint = self::fingerprintPhp();
        $agentHash = (string) $plan['surfaces'][0]['sha256'];
        $loaderHash = (string) $plan['surfaces'][1]['sha256'];
        $controlHash = (string) $plan['surfaces'][2]['sha256'];
        $siteIdentity = (string) $plan['site_identity'];
        return 'set -eu' . "\n"
            . 'mu=' . $q($mu) . '; repo=' . $q($repo) . '; archive=' . $q($archive) . '; token=' . $q($token) . "\n"
            . 'agent="$mu/wprism"; loader="$mu/wprism-loader.php"; control="$repo/.wprism"; lock="$mu/.wprism-unadopt-lock"; txn="$lock/transaction"' . "\n"
            . 'agent_old="$mu/.wprism-unadopt-agent-$token"; loader_old="$mu/.wprism-loader-unadopt-$token"; control_old="$repo/.wprism-unadopt-$token"' . "\n"
            . 'fingerprint() { php -r ' . $q($fingerprint) . ' "$1" "$2"; }' . "\n"
            . '[ -d "$txn" ] && [ ! -L "$txn" ] && [ -f "$txn/staged" ] && [ ! -L "$txn/staged" ] || { echo "staged unadopt journal is missing or unsafe" >&2; exit 1; }' . "\n"
            . '[ ! -e "$agent" ] && [ ! -L "$agent" ] && [ ! -e "$loader" ] && [ ! -L "$loader" ] && [ ! -e "$control" ] && [ ! -L "$control" ] || { echo "a live control-plane path reappeared before commit" >&2; exit 1; }' . "\n"
            . '[ "$(fingerprint "$agent_old" directory)" = ' . $q($agentHash) . ' ] '
                . '&& [ "$(fingerprint "$loader_old" file)" = ' . $q($loaderHash) . ' ] '
                . '&& [ "$(fingerprint "$control_old" directory)" = ' . $q($controlHash) . ' ] '
                . '|| { echo "staged control-plane backup changed before commit" >&2; exit 1; }' . "\n"
            . '[ "$(fingerprint "$archive/mu-plugins/wprism" directory)" = ' . $q($agentHash) . ' ] '
                . '&& [ "$(fingerprint "$archive/mu-plugins/wprism-loader.php" file)" = ' . $q($loaderHash) . ' ] '
                . '&& [ "$(fingerprint "$archive/repository/.wprism" directory)" = ' . $q($controlHash) . ' ] '
                . '&& [ "$(cat "$archive/receipt.json")" = ' . $q(rtrim($receiptBytes, "\n")) . ' ] '
                . '|| { echo "selected evidence archive changed before commit" >&2; exit 1; }' . "\n"
            . self::siteAssertionShell($repo, $siteIdentity, $fingerprint, $q)
            . 'rm -rf "$control_old" "$agent_old"; rm -f "$loader_old"; rm -rf "$lock"; sync; echo wprism-unadopt-complete';
    }

    /** @param array<string,mixed> $plan */
    private static function rollbackScript(array $plan, string $token): string {
        $q = static fn(string $value): string => escapeshellarg($value);
        $mu = (string) $plan['mu_plugins'];
        $repo = (string) $plan['repository'];
        $fingerprint = self::fingerprintPhp();
        $agentHash = (string) $plan['surfaces'][0]['sha256'];
        $loaderHash = (string) $plan['surfaces'][1]['sha256'];
        $controlHash = (string) $plan['surfaces'][2]['sha256'];
        return 'set -eu' . "\n"
            . 'mu=' . $q($mu) . '; repo=' . $q($repo) . '; token=' . $q($token) . "\n"
            . 'agent="$mu/wprism"; loader="$mu/wprism-loader.php"; control="$repo/.wprism"; lock="$mu/.wprism-unadopt-lock"; txn="$lock/transaction"' . "\n"
            . 'agent_old="$mu/.wprism-unadopt-agent-$token"; loader_old="$mu/.wprism-loader-unadopt-$token"; control_old="$repo/.wprism-unadopt-$token"' . "\n"
            . 'fingerprint() { php -r ' . $q($fingerprint) . ' "$1" "$2"; }' . "\n"
            . '[ -d "$txn" ] && [ ! -L "$txn" ] && [ -f "$txn/staged" ] && [ ! -L "$txn/staged" ] || { echo "unadopt rollback journal is missing or unsafe" >&2; exit 1; }' . "\n"
            . '[ ! -e "$control" ] && [ "$(fingerprint "$control_old" directory)" = ' . $q($controlHash) . ' ] && mv "$control_old" "$control" || { echo "control evidence changed before rollback" >&2; exit 1; }' . "\n"
            . '[ ! -e "$agent" ] && [ "$(fingerprint "$agent_old" directory)" = ' . $q($agentHash) . ' ] && mv "$agent_old" "$agent" || { echo "agent changed before rollback" >&2; exit 1; }' . "\n"
            . '[ ! -e "$loader" ] && [ "$(fingerprint "$loader_old" file)" = ' . $q($loaderHash) . ' ] && mv "$loader_old" "$loader" || { echo "loader changed before rollback" >&2; exit 1; }' . "\n"
            . 'rm -rf "$lock"; sync; echo wprism-unadopt-rolled-back';
    }

    /** @param array<string,mixed> $plan @param array{exit:int,stdout:string,stderr:string} &$failure */
    private static function rollback(
        AdoptionTransport $transport,
        array $plan,
        string $token,
        array &$failure
    ): void {
        $rollback = $transport->captureRaw(self::rollbackScript($plan, $token));
        if ($rollback['exit'] === 0) {
            return;
        }
        $detail = trim($rollback['stderr'] !== '' ? $rollback['stderr'] : $rollback['stdout']);
        $failure['stderr'] .= ($failure['stderr'] !== '' ? "\n" : '')
            . 'wprism: unadopt rollback could not be confirmed'
            . ($detail !== '' ? ': ' . $detail : '');
    }

    /** @param callable(string):string $q */
    private static function siteAssertionShell(
        string $repo,
        string $siteIdentity,
        string $fingerprint,
        callable $q
    ): string {
        if ($siteIdentity === 'absent') {
            return '[ ! -e "$repo/site.wprism.json" ] && [ ! -L "$repo/site.wprism.json" ] || { echo "site.wprism.json appeared before commit" >&2; exit 1; }' . "\n";
        }
        return '[ -f "$repo/site.wprism.json" ] && [ ! -L "$repo/site.wprism.json" ] '
            . '&& [ "$(php -r ' . $q($fingerprint) . ' "$repo/site.wprism.json" file)" = '
            . $q($siteIdentity) . ' ] || { echo "site.wprism.json changed before commit" >&2; exit 1; }' . "\n";
    }

    private static function fingerprintPhp(): string {
        return <<<'PHP'
$root = $argv[1]; $kind = $argv[2];
$hash = hash_init('sha256');
$walk = function (string $path, string $relative) use (&$walk, $hash): void {
    $stat = @lstat($path);
    if (!is_array($stat)) exit(2);
    $type = ((int) $stat['mode']) & 0170000;
    if ($type === 0040000 && !is_link($path)) {
        hash_update($hash, "D\0$relative\0" . (((int) $stat['mode']) & 0777) . "\0");
        $entries = @scandir($path);
        if (!is_array($entries)) exit(3);
        $entries = array_values(array_filter($entries, static fn(string $name): bool => $name !== '.' && $name !== '..'));
        sort($entries, SORT_STRING);
        foreach ($entries as $entry) $walk($path . '/' . $entry, $relative === '.' ? $entry : $relative . '/' . $entry);
        return;
    }
    if ($type === 0100000 && is_file($path) && !is_link($path)) {
        $digest = @hash_file('sha256', $path);
        if (!is_string($digest)) exit(4);
        hash_update($hash, "F\0$relative\0" . (((int) $stat['mode']) & 0777) . "\0" . (string) $stat['size'] . "\0$digest\0");
        return;
    }
    exit(5);
};
if (($kind === 'directory' && (!is_dir($root) || is_link($root)))
    || ($kind === 'file' && (!is_file($root) || is_link($root)))) exit(1);
$walk($root, '.'); echo hash_final($hash);
PHP;
    }

    private static function normalizeArchive(string $archive): string {
        $archive = rtrim(trim($archive), '/');
        if ($archive === '' || $archive === '/' || !str_starts_with($archive, '/')
            || str_contains($archive, '//')
            || preg_match('#(?:^|/)\.{1,2}(?:/|$)#', $archive) === 1
            || preg_match('/[\x00-\x1F\x7F]/', $archive) === 1) {
            throw new \RuntimeException('wprism: unadopt --archive-to must be a normalized absolute non-root path');
        }
        return $archive;
    }

    private static function assertDisjointArchive(string $archive, string $repo, string $mu): void {
        foreach ([$repo, $mu] as $root) {
            $root = rtrim($root, '/');
            if ($archive === $root || str_starts_with($archive . '/', $root . '/')) {
                throw new \RuntimeException('wprism: unadopt archive must be outside the repository and MU-plugin roots');
            }
        }
    }

    /** @param array<string,mixed> $value */
    private static function encode(array $value): string {
        $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return $json . "\n";
    }

    /** @return array{exit:int,phase:string,stdout:string,stderr:string} */
    private static function failure(string $phase, string $message): array {
        return ['exit' => 1, 'phase' => $phase, 'stdout' => '', 'stderr' => $message];
    }

    /** @param array{exit:int,stdout:string,stderr:string} $result @return array{exit:int,phase:string,stdout:string,stderr:string} */
    private static function fromTransport(string $phase, array $result): array {
        return [
            'exit' => $result['exit'] === 0 ? 1 : $result['exit'],
            'phase' => $phase,
            'stdout' => $result['stdout'],
            'stderr' => $result['stderr'],
        ];
    }
}
