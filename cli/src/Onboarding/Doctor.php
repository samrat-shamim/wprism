<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

require_once __DIR__ . '/../Transport/Transport.php';

/**
 * `duo doctor <env>` — five checks, each gated on the previous one so a
 * broken transport doesn't produce a wall of confusing downstream failures.
 * Four are blocking (fold into the overall `ok`); the fifth (DISALLOW_
 * FILE_MODS, DUO-3231) is advisory-only — see its own check below for why.
 */
final class Doctor {
    /** @return array{ok:bool, checks: list<array{label:string, ok:bool, detail:string, advisory?:bool}>} */
    public static function run(EnvironmentDriver $t, ?callable $wpArgs = null): array {
        $captureWp = static function (array $args) use ($t, $wpArgs): array {
            return $t->captureWp($wpArgs === null ? $args : $wpArgs($args));
        };
        $checks = [];

        $r = $t->captureRaw('echo duo-reachable');
        $reachable = $r['exit'] === 0 && trim($r['stdout']) === 'duo-reachable';
        $checks[] = self::check('transport reachable', $reachable, $reachable ? '' : self::reason($r));

        $installed = false;
        if ($reachable) {
            $r = $captureWp(['core', 'is-installed']);
            $installed = $r['exit'] === 0;
            $checks[] = self::check('WordPress installed', $installed, $installed ? '' : self::reason($r));
        } else {
            $checks[] = self::check('WordPress installed', false, 'skipped: transport unreachable');
        }

        $agentPresent = false;
        if ($installed) {
            $snippet = 'echo class_exists("\\Duo\\Capture") ? "duo-ok" : "duo-missing";';
            $r = $captureWp(['eval', $snippet]);
            $out = trim($r['stdout']);
            $agentPresent = $r['exit'] === 0 && $out === 'duo-ok';
            if ($agentPresent) {
                $detail = '';
            } elseif ($r['exit'] !== 0) {
                $detail = self::reason($r);
            } else {
                $adoption = $t instanceof AdoptionTransport ? $t->capabilityReport('adopt') : null;
                if ($adoption !== null && $adoption->ready()) {
                    $detail = "agent class not found (wp eval returned '$out'); next step: duo adopt "
                        . escapeshellarg($t->name());
                } elseif ($adoption !== null) {
                    $remediation = array_values(array_unique(array_map(
                        static fn(array $row): string => (string) $row['remediation'],
                        $adoption->blockers()
                    )));
                    $detail = "agent class not found (wp eval returned '$out'); host-side duo adopt is not ready "
                        . "for driver '" . $t->driverId() . "': " . implode('; ', $remediation)
                        . '; then rerun duo doctor ' . escapeshellarg($t->name());
                } else {
                    $detail = "agent class not found (wp eval returned '$out'); host-side duo adopt is unavailable "
                        . "for driver '" . $t->driverId() . "'; install or mount the Duo agent through that "
                        . "environment's control plane, then rerun duo doctor " . escapeshellarg($t->name());
                }
            }
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

        // DUO-3232: .duo-env-values.json is this environment's own optional,
        // gitignored scratch file for values provisioned via `wp duo
        // env-set` (see sandbox/site-repo.gitignore.template and
        // cli/README.md) — a secrets-bearing file living right next to
        // site.duo.json inside the repo checkout. When it CAN be checked,
        // this is BLOCKING, not advisory: a tracked secrets file isn't a
        // hardening gap to note for later, it is already-committed (and
        // possibly already-pushed) secret material the moment `git
        // ls-files` shows it tracked. `git ls-files --error-unmatch`
        // deliberately covers both "never existed" and "exists but
        // untracked" with the same non-zero exit — this check only cares
        // about the one bad case, tracked, never about whether the file
        // exists at all (most environments will have no such file, which
        // is a perfectly ordinary pass).
        //
        // "When it CAN be checked" is doing real work above, not hedging:
        // verified live against this project's OWN sandbox images
        // (wordpress:cli-php8.3) that they ship with NO git binary at all
        // — a target environment materializing `wp duo` commands has no
        // structural reason to need one (the agent itself never shells out
        // to git; only an operator's own machine or CI runner does). A
        // naive "git ls-files || echo untracked" would silently read
        // "command not found" as "untracked" — a false PASS on a target
        // that was never actually checked, exactly the failure mode this
        // whole file exists to avoid elsewhere (see the file's own
        // docblock). So this checks for git's presence FIRST and reports
        // "could not verify" honestly (advisory, not a false clean bill of
        // health) rather than silently trusting an absent tool.
        if ($reachable && $repoOk) {
            $repoEsc = escapeshellarg($repo);
            $script = "cd $repoEsc && "
                . '{ command -v git >/dev/null 2>&1 || { echo duo-nogit; exit 0; }; } && '
                . 'git ls-files --error-unmatch .duo-env-values.json >/dev/null 2>&1 '
                . '&& echo duo-tracked || echo duo-untracked';
            $r = $t->captureRaw($script);
            $out = trim($r['stdout']);
            if ($out === 'duo-nogit') {
                $checks[] = self::check(
                    '.duo-env-values.json not git-tracked', false,
                    'could not verify — this environment has no git binary, so tracked-status cannot be '
                        . 'checked from inside it. Verify manually (from a machine with a checkout of this '
                        . 'repo): git -C <checkout> ls-files --error-unmatch .duo-env-values.json (should '
                        . 'exit non-zero, meaning untracked/absent).',
                    true
                );
            } else {
                $tracked = $out === 'duo-tracked';
                $detail = $tracked
                    ? '.duo-env-values.json is committed to this repo. It exists to hold provisioned secret '
                        . 'values and must never be tracked. Run `git rm --cached .duo-env-values.json`, add it '
                        . 'to .gitignore if missing, commit that removal, and rotate any value it may have held '
                        . '— removing it from the working tree alone does not remove it from git history.'
                    : '';
                $checks[] = self::check('.duo-env-values.json not git-tracked', !$tracked, $detail);
            }
        } else {
            $checks[] = self::check(
                '.duo-env-values.json not git-tracked', false,
                $reachable ? 'skipped: repo path unavailable' : 'skipped: transport unreachable'
            );
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
            $r = $captureWp(['eval', $snippet]);
            $out = trim($r['stdout']);
            $set = $r['exit'] === 0 && $out === 'duo-set';
            $detail = $set ? '' : 'DISALLOW_FILE_MODS is not set (or false) in wp-config.php — wp-admin plugin/theme '
                . 'install/update/delete UI stays open, so a one-click update can silently drift this '
                . "environment's code out from under git (docs/proposals/code-half.md risk #1). Recommended: "
                . "define('DISALLOW_FILE_MODS', true); — `duo deploy`'s code_drift check still catches an update "
                . 'after the fact, but this closes the hole at the source.';
            $checks[] = self::check('DISALLOW_FILE_MODS set', $set, $detail, true);
        } else {
            $checks[] = self::check('DISALLOW_FILE_MODS set', false, 'skipped: WordPress not installed', true);
        }

        // DUO-3222: the ONE project-level WP/PHP/database compatibility
        // statement, per that issue's adversarial review's reshape ruling —
        // deliberately NOT a per-adapter manifest field (combinatorial cost,
        // no matching risk; the manifest-treadmill risk DESIGN.md names is
        // plugin-version drift specifically). docs/compatibility-baseline.
        // json is the single source of truth this check reads — never a
        // second, independently-maintained copy of these numbers. php and
        // database are BLOCKING (fold into $ok below), matching
        // Deploy::code_mismatch()'s own severity for a real compatibility
        // violation, not DISALLOW_FILE_MODS's advisory-hardening-gap
        // treatment above — an environment genuinely outside the tested
        // PHP/database range is exactly the "unproven behavior hidden
        // behind a broad compatibility claim" DESIGN.md's vision invariant
        // forbids. wordpress core has no real pin anywhere in this
        // project's own Docker tags (see the baseline file's own note), so
        // it is reported, never compared — a fabricated range with no pin
        // behind it would be an untested guess, exactly what this
        // project's discipline avoids elsewhere.
        if ($installed) {
            $baseline = self::read_baseline();
            if ($baseline === null) {
                $checks[] = self::check(
                    'compatibility baseline (docs/compatibility-baseline.json)', false,
                    'baseline file missing or malformed — cannot verify PHP/database compatibility'
                );
            } else {
                $snippet = 'global $wpdb; echo PHP_VERSION . "|" . $wpdb->db_version() . "|" '
                    . '. (stripos($wpdb->db_server_info(), "mariadb") !== false ? "mariadb" : "mysql") . "|" '
                    . '. get_bloginfo("version");';
                $r = $captureWp(['eval', $snippet]);
                $parts = $r['exit'] === 0 ? explode('|', trim($r['stdout'])) : [];
                if (count($parts) !== 4) {
                    $checks[] = self::check(
                        'compatibility baseline (docs/compatibility-baseline.json)', false,
                        'could not read PHP/database/WordPress facts from the environment: ' . self::reason($r)
                    );
                } else {
                    [$phpVersion, $dbVersion, $dbEngine, $wpVersion] = $parts;
                    $php = $baseline['php'] ?? null;
                    $phpOk = is_array($php) && self::in_range($phpVersion, (string) $php['min'], (string) $php['max']);
                    $checks[] = self::check(
                        "PHP version ($phpVersion)", $phpOk,
                        $phpOk ? '' : 'outside the declared baseline (>=' . ($php['min'] ?? '?') . ' <'
                            . ($php['max'] ?? '?') . ' — docs/compatibility-baseline.json). Classification and '
                            . 'apply behavior are only tested inside this range.'
                    );
                    $db = $baseline['database'] ?? null;
                    $dbEngineOk = is_array($db) && strcasecmp((string) ($db['engine'] ?? ''), $dbEngine) === 0;
                    $dbRangeOk = is_array($db) && self::in_range($dbVersion, (string) $db['min'], (string) $db['max']);
                    $dbOk = $dbEngineOk && $dbRangeOk;
                    $checks[] = self::check(
                        "database ($dbEngine $dbVersion)", $dbOk,
                        $dbOk ? '' : (!$dbEngineOk
                            ? 'expected ' . ($db['engine'] ?? '?') . ", found $dbEngine — a different database engine is "
                                . 'genuinely untested, not merely unpinned (docs/compatibility-baseline.json).'
                            : 'outside the declared baseline (>=' . ($db['min'] ?? '?') . ' <' . ($db['max'] ?? '?')
                                . ' — docs/compatibility-baseline.json).')
                    );
                    // Informational only — see this block's own header comment for why WordPress
                    // core gets no enforced range.
                    $checks[] = self::check("WordPress core ($wpVersion)", true, '', true);
                }
            }
        } else {
            $checks[] = self::check('compatibility baseline (docs/compatibility-baseline.json)', false, 'skipped: WordPress not installed');
        }

        // DUO-3290: surfaced, not run — doctor stays fast and never
        // triggers coverage's own table-enumeration/row-count queries on
        // every routine health check. Purely a discoverability pointer,
        // always present, never affects doctor's own pass/fail.
        $checks[] = self::check(
            'coverage report available', true,
            'run `wp duo coverage --repo=<repo>` to see what this site has vs what Duo actually captures (options, custom tables) — never blocking, purely informational',
            true
        );

        $ok = true;
        foreach ($checks as $c) {
            if (!empty($c['advisory'])) {
                continue; // never affects the overall pass/fail — see the check above for why
            }
            $ok = $ok && $c['ok'];
        }
        return ['ok' => $ok, 'checks' => $checks];
    }

    /**
     * Run the same public doctor checks through the installed out-of-band Duo
     * control plane. Local adoption uses this before transaction commit so
     * ordinary plugins, themes, MU plugins, and the provenance journal cannot
     * turn verification into an unrollbackable application/ledger mutation.
     *
     * @return array{ok:bool, checks: list<array{label:string, ok:bool, detail:string, advisory?:bool}>}
     */
    public static function runIsolated(EnvironmentDriver $t): array {
        return self::run(
            $t,
            static fn(array $args): array => CodeDeploy::controlArgs($args)
        );
    }

    /** @return ?array{php:array{min:string,max:string}, database:array{engine:string,min:string,max:string}, wordpress:array{last_verified:string}} */
    private static function read_baseline(): ?array {
        $file = dirname(__DIR__, 3) . '/docs/compatibility-baseline.json';
        if (!is_file($file)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($file), true);
        if (!is_array($data) || !isset($data['php']['min'], $data['php']['max'], $data['database']['min'], $data['database']['max'])) {
            return null;
        }
        return $data;
    }

    /** Same {min inclusive, max exclusive} + version_compare() convention as Deploy::in_range() (agent-side) — kept as an independent copy here rather than a cross-tree include: cli/ and agent/src/ are deliberately separate deployables (cli/ never ships into wp-content/), so sharing code between them would be a new coupling, not a reuse of an existing one. */
    private static function in_range(string $installed, string $min, string $max): bool {
        return version_compare($installed, $min, '>=') && version_compare($installed, $max, '<');
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
