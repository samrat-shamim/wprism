<?php
namespace Duo\Orchestrator;

/**
 * `duo doctor <env>` — five checks, each gated on the previous one so a
 * broken transport doesn't produce a wall of confusing downstream failures.
 * Four are blocking (fold into the overall `ok`); the fifth (DISALLOW_
 * FILE_MODS, DUO-3231) is advisory-only — see its own check below for why.
 */
final class Doctor {
    /** @return array{ok:bool, checks: list<array{label:string, ok:bool, detail:string, advisory?:bool}>} */
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

        // DUO-3231 (docs/proposals/code-half.md risk register #1):
        // DISALLOW_FILE_MODS closes wp-admin's file-mod UI at the source —
        // the recommended mitigation for silent code drift, alongside (not
        // instead of) code_drift's after-the-fact DETECTION
        // (Deploy::code_drift()). Deliberately ADVISORY, never blocking:
        // unlike the four checks above (a broken transport/install/agent/
        // repo makes every subsequent `duo` command fail outright), a
        // missing DISALLOW_FILE_MODS is a real but non-fatal hardening gap
        // — plenty of environments run without it today, and doctor's job
        // is to surface that honestly, not manufacture a false "broken"
        // reading that would make operators route around doctor entirely
        // (the exact failure mode DESIGN.md's posture section warns
        // against: friction that teaches people to ignore the check).
        if ($installed) {
            $snippet = 'echo (defined("DISALLOW_FILE_MODS") && DISALLOW_FILE_MODS) ? "duo-set" : "duo-unset";';
            $r = $t->captureWp(['eval', $snippet]);
            $out = trim($r['stdout']);
            $set = $r['exit'] === 0 && $out === 'duo-set';
            $detail = $set ? '' : "DISALLOW_FILE_MODS is not set (or false) in wp-config.php — wp-admin plugin/theme "
                . 'install/update/delete UI stays open, so a one-click update can silently drift this '
                . "environment's code out from under git (docs/proposals/code-half.md risk #1). Recommended: "
                . "define('DISALLOW_FILE_MODS', true); — `duo deploy`'s code_drift check still catches an update "
                . 'after the fact, but this closes the hole at the source.';
            $checks[] = self::check('DISALLOW_FILE_MODS set', $set, $detail, true);
        } else {
            $checks[] = self::check('DISALLOW_FILE_MODS set', false, 'skipped: WordPress not installed', true);
        }

        $ok = true;
        foreach ($checks as $c) {
            if (!empty($c['advisory'])) {
                continue; // never affects the overall pass/fail — see the check above for why
            }
            $ok = $ok && $c['ok'];
        }
        return ['ok' => $ok, 'checks' => $checks];
    }

    private static function check(string $label, bool $ok, string $detail, bool $advisory = false): array {
        $row = ['label' => $label, 'ok' => $ok, 'detail' => $detail];
        if ($advisory) {
            $row['advisory'] = true;
        }
        return $row;
    }

    /** @param array{exit:int, stdout:string, stderr:string} $r */
    private static function reason(array $r): string {
        $out = trim($r['stderr'] !== '' ? $r['stderr'] : $r['stdout']);
        return $out !== '' ? $out : "exit code {$r['exit']}";
    }
}
