<?php
namespace Duo\Orchestrator;

/** Host wrapper for the target agent's digest-bound initialization protocol. */
final class Init {
    /** @return array<string,mixed> */
    public static function proposal(EnvironmentDriver $transport): array {
        return self::request($transport, [
            'duo', 'init', '--repo=' . $transport->repoPath(), '--format=json',
        ], 'proposal');
    }

    /** @return array<string,mixed> */
    public static function confirm(EnvironmentDriver $transport, string $digest): array {
        return self::request($transport, [
            'duo', 'init', '--repo=' . $transport->repoPath(),
            '--confirm=' . $digest, '--format=json',
        ], 'confirmation');
    }

    /** @return list<string> */
    public static function render(array $proposal): array {
        $lines = [];
        $state = $proposal['state'] ?? [];
        $code = $proposal['code'] ?? [];
        $env = $proposal['environment'] ?? [];
        $media = $state['media'] ?? [];
        $lines[] = 'Duo initialization proposal ' . ($proposal['digest'] ?? '(missing digest)');
        $lines[] = '  WordPress ' . ($env['wordpress'] ?? '?') . ' / PHP ' . ($env['php'] ?? '?')
            . ' / database ' . ($env['database']['server'] ?? '?');
        $lines[] = '  URL: ' . ($env['home'] ?? '(unknown)');
        $lines[] = '  code: ' . ($code['management'] ?? 'unknown')
            . ' (separate ' . (int) ($code['files'] ?? 0) . '-file / ' . (int) ($code['bytes'] ?? 0)
            . '-byte payload; source ' . ($code['source_revision'] ?? '?') . ')';
        foreach (($code['roots'] ?? []) as $kind => $path) {
            $lines[] = "    $kind root: " . ($path ?? '(unavailable)');
        }
        foreach (($code['active_plugins'] ?? []) as $plugin) {
            $lines[] = '    active plugin: ' . ($plugin['basename'] ?? '?') . ' ' . ($plugin['version'] ?? '(unknown version)');
        }
        $theme = $code['active_theme'] ?? [];
        $lines[] = '    active theme: stylesheet=' . ($theme['stylesheet'] ?? '?') . ', template=' . ($theme['template'] ?? '?');
        $lines[] = '  state: ' . ($state['repository'] ?? '?') . ' (site.duo.json + canonical capture baseline)';
        $lines[] = '  Git: ' . ($state['git']['mode'] ?? 'unknown') . ' (' . ($state['git']['version'] ?? 'unknown') . ')';
        $adapterNames = array_map(static fn(array $row): string => (string) ($row['name'] ?? '?'), $state['adapters'] ?? []);
        $lines[] = '  adapters: ' . ($adapterNames ? implode(', ', $adapterNames) : '(none)');
        $lines[] = '  media: ' . ($media['strategy'] ?? 'unknown') . ' (' . (int) ($media['attachments'] ?? 0)
            . ' attachment(s), ' . (int) ($media['unavailable'] ?? 0) . ' unavailable)';
        $risk = $state['risk_surfaces'] ?? [];
        $lines[] = '  redacted risk surfaces: ' . array_sum((array) ($risk['options'] ?? []))
            . ' secret-shaped option value(s), ' . array_sum((array) ($risk['user_meta'] ?? []))
            . ' PII-shaped user-meta value(s); values are never included';
        if (!empty($risk['truncated'])) {
            $lines[] = '    risk discovery reached its bounded scan limit; these redacted counts are incomplete';
        }
        foreach (($proposal['unsupported'] ?? []) as $row) {
            $lines[] = '  UNSUPPORTED ' . strtoupper((string) ($row['kind'] ?? 'capability')) . ' '
                . ($row['extension'] ?? '?') . ' [' . ($row['code'] ?? 'unsupported') . ']: '
                . ($row['reason'] ?? 'unsupported') . '. ' . ($row['remediation'] ?? '');
        }
        foreach (($proposal['advisories'] ?? []) as $row) {
            $lines[] = '  ADVISORY ' . strtoupper((string) ($row['kind'] ?? 'coverage')) . ' '
                . ($row['extension'] ?? '?') . ' [' . ($row['code'] ?? 'advisory') . ']: '
                . ($row['reason'] ?? 'coverage is incomplete') . '. ' . ($row['remediation'] ?? '');
        }
        $lines[] = !empty($proposal['ready'])
            ? '  result: ready for explicit confirmation'
            : '  result: blocked; no configuration, state, identity, or ledger mutation was made';
        return $lines;
    }

    /** @return list<string> */
    public static function nextSteps(string $env, string $repo): array {
        $gitRepo = escapeshellarg($repo);
        return [
            'Managed state scope is clean. Coverage outside the selected adapters remains advisory, not a whole-site guarantee.',
            "The Git worktree is ready at target path $repo; run the following Git commands inside that target environment.",
            'Next steps:',
            "  1. git -C $gitRepo add .gitignore site.duo.json code state media && git -C $gitRepo commit -m \"duo: initial code and state baselines\"",
            "  2. git -C $gitRepo switch -c <branch>             # branch",
            "  3. duo capture $env                              # capture authored state",
            "  4. duo plan $env                                 # preview",
            "  5. duo promote $env                              # promote with a DB checkpoint",
            '  6. follow the exact checkpoint receipt on failure # rollback',
            'Executable code remains a separate content-addressed half under code/wp-content; review its descriptor and ownership boundary independently from state.',
        ];
    }

    /** @return array<string,mixed> */
    private static function request(EnvironmentDriver $transport, array $args, string $phase): array {
        $result = $transport->captureWp($args);
        if ($result['exit'] !== 0) {
            $detail = trim($result['stderr'] !== '' ? $result['stderr'] : $result['stdout']);
            throw new \RuntimeException(
                "duo init $phase failed for '{$transport->name()}' (exit {$result['exit']})"
                . ($detail !== '' ? ": $detail" : '')
            );
        }
        $decoded = json_decode(trim($result['stdout']), true);
        if (!is_array($decoded)) {
            throw new \RuntimeException("duo init $phase returned invalid JSON for '{$transport->name()}'");
        }
        return $decoded;
    }
}
