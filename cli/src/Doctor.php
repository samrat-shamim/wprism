<?php
namespace Duo\Orchestrator;

/**
 * `duo doctor <env>` — four checks, each gated on the previous one so a
 * broken transport doesn't produce a wall of confusing downstream failures.
 */
final class Doctor {
    /** @return array{ok:bool, checks: list<array{label:string, ok:bool, detail:string}>} */
    public static function run(Transport $t): array {
        $checks = [];

        $r = $t->captureRaw('echo duo-reachable');
        $reachable = $r['exit'] === 0 && trim($r['stdout']) === 'duo-reachable';
        $checks[] = self::check('transport reachable', $reachable, $reachable ? '' : self::reason($r));

        $installed = false;
        if ($reachable) {
            $r = $t->captureWp(['core', 'is-installed']);
            $installed = $r['exit'] === 0;
            $checks[] = self::check('WordPress installed', $installed, $installed ? '' : self::reason($r));
        } else {
            $checks[] = self::check('WordPress installed', false, 'skipped: transport unreachable');
        }

        $agentPresent = false;
        if ($installed) {
            $snippet = 'echo class_exists("\\Duo\\Capture") ? "duo-ok" : "duo-missing";';
            $r = $t->captureWp(['eval', $snippet]);
            $out = trim($r['stdout']);
            $agentPresent = $r['exit'] === 0 && $out === 'duo-ok';
            $detail = $agentPresent ? '' : ($r['exit'] !== 0 ? self::reason($r) : "agent class not found (wp eval returned '$out')");
            $checks[] = self::check('duo agent present', $agentPresent, $detail);
        } else {
            $checks[] = self::check('duo agent present', false, 'skipped: WordPress not installed');
        }

        $repo = $t->repoPath();
        if ($reachable) {
            $repoEsc = escapeshellarg($repo);
            $fileEsc = escapeshellarg(rtrim($repo, '/') . '/site.duo.json');
            $script = "[ -d $repoEsc ] && [ -f $fileEsc ] && echo duo-repo-ok || echo duo-repo-missing";
            $r = $t->captureRaw($script);
            $out = trim($r['stdout']);
            $repoOk = $r['exit'] === 0 && $out === 'duo-repo-ok';
            $detail = $repoOk ? '' : ($out === 'duo-repo-missing' ? "$repo: path missing or no site.duo.json" : self::reason($r));
            $checks[] = self::check("repo path has site.duo.json ($repo)", $repoOk, $detail);
        } else {
            $checks[] = self::check("repo path has site.duo.json ($repo)", false, 'skipped: transport unreachable');
        }

        $ok = true;
        foreach ($checks as $c) {
            $ok = $ok && $c['ok'];
        }
        return ['ok' => $ok, 'checks' => $checks];
    }

    private static function check(string $label, bool $ok, string $detail): array {
        return ['label' => $label, 'ok' => $ok, 'detail' => $detail];
    }

    /** @param array{exit:int, stdout:string, stderr:string} $r */
    private static function reason(array $r): string {
        $out = trim($r['stderr'] !== '' ? $r['stderr'] : $r['stdout']);
        return $out !== '' ? $out : "exit code {$r['exit']}";
    }
}
