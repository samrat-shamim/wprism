<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

require_once __DIR__ . '/../Transport/Transport.php';

/**
 * `duo doctor <env>` — eleven rendered rows over exactly four target round
 * trips, because a row and a round trip are not the same thing.
 *
 * Two of the four are true gates: the raw reachability echo (nothing
 * downstream means anything through a broken transport) and `wp core
 * is-installed` (no WordPress-side fact is readable without it). The other
 * two are compositions. SITE_FACTS answers agent presence,
 * DISALLOW_FILE_MODS, the site topology and the PHP/database/filesystem/process/WordPress facts
 * in ONE `wp eval`:
 * those three were never gates on each other, only siblings under the same
 * `if ($installed)`. One raw script answers the repo-path row and the
 * `.duo-env-values.json` tracked-status row that genuinely IS gated on it —
 * one call because that gate is a shell test, not a decision this host has
 * to make in between. DUO-3511 measured what the old one-call-per-row shape
 * cost: a per-call transport floor of ~1.24 s on the docker-compose.yml
 * estate (~0.73 s on pair.yml), paid seven times for four answers.
 *
 * Rows still fail independently, and their order here is the render order.
 * Every compatibility axis is blocking (it folds into the overall `ok`);
 * `.duo-env-values.json` is blocking when it can be checked and advisory
 * when the target ships no git binary; DISALLOW_FILE_MODS (DUO-3231), the
 * coverage pointer (DUO-3290) is always advisory — each check below states
 * its own reason.
 */
final class Doctor {
    /**
     * The one composed `wp eval` an installed target answers.
     *
     * Composing three evals into one is only safe because every field is
     * computed inside its OWN try/catch. `$wpdb->db_version()` and
     * `$wpdb->db_server_info()` throw on a target whose database handle is
     * gone — `class_exists()` and `defined()` cannot — and one shared try
     * (or none at all) would let that single failure erase the
     * agent-presence and DISALLOW_FILE_MODS answers with it, printing
     * "agent class not found" at an operator whose agent is installed and
     * fine. That is a false diagnosis the three separate evals could not
     * produce, so the isolation is not a nicety: it is the property that
     * makes the composition equivalent. A field that throws stays null and
     * sinks only the row that needed it (self::compatibility_facts()).
     *
     * The `(string)` casts are the pre-composition semantics preserved
     * verbatim: the old snippet concatenated each fact into a
     * pipe-separated line, so a null return arrived as '' and was compared
     * as ''. It still is — is_string(), not a truthiness test, is what
     * separates a value from the thrown-field sentinel below.
     */
    private const SITE_FACTS = 'global $wpdb; '
        . '$duo = ["agent" => null, "file_mods" => null, "php" => null, '
        . '"db_version" => null, "db_engine" => null, "filesystem" => null, '
        . '"process" => null, "wp" => null, "site_mode" => null]; '
        . 'try { $duo["agent"] = class_exists("\\Duo\\Capture") ? "duo-ok" : "duo-missing"; } '
        . 'catch (\Throwable $e) {} '
        . 'try { $duo["file_mods"] = (defined("DISALLOW_FILE_MODS") && DISALLOW_FILE_MODS) '
        . '? "duo-set" : "duo-unset"; } catch (\Throwable $e) {} '
        . 'try { $duo["php"] = PHP_VERSION; } catch (\Throwable $e) {} '
        . 'try { $duo["db_version"] = (string) $wpdb->db_version(); } catch (\Throwable $e) {} '
        . 'try { $duo["db_engine"] = stripos((string) $wpdb->db_server_info(), "mariadb") !== false '
        . '? "mariadb" : "mysql"; } catch (\Throwable $e) {} '
        . 'try { $duo["filesystem"] = ["directory_separator" => DIRECTORY_SEPARATOR, '
        . '"os_family" => PHP_OS_FAMILY, "functions" => ['
        . '"chmod" => function_exists("chmod"), "flock" => function_exists("flock"), '
        . '"fsync" => function_exists("fsync"), "lstat" => function_exists("lstat"), '
        . '"rename" => function_exists("rename")]]; } catch (\Throwable $e) {} '
        . 'try { $duo["process"] = ["os_family" => PHP_OS_FAMILY, "functions" => ['
        . '"pcntl_exec" => function_exists("pcntl_exec"), "posix_kill" => function_exists("posix_kill"), '
        . '"posix_setsid" => function_exists("posix_setsid"), "proc_close" => function_exists("proc_close"), '
        . '"proc_get_status" => function_exists("proc_get_status"), "proc_open" => function_exists("proc_open"), '
        . '"proc_terminate" => function_exists("proc_terminate")], "shell" => ['
        . '"executable" => function_exists("is_executable") && @is_executable("/bin/sh"), '
        . '"path" => "/bin/sh"]]; } catch (\Throwable $e) {} '
        . 'try { $duo["wp"] = (string) get_bloginfo("version"); } catch (\Throwable $e) {} '
        // Its own try/catch like every sibling above, and function_exists()
        // rather than a bare call: this snippet also runs under the isolated
        // control bootstrap, where a caller can reach it before WordPress has
        // defined is_multisite().
        . 'try { $duo["site_mode"] = (function_exists("is_multisite") && is_multisite()) '
        . '? "multisite" : "single-site"; } catch (\Throwable $e) {} '
        . 'echo json_encode($duo);';

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

        // DUO-3511: one round trip, three rows. $facts is null whenever the
        // call failed or its payload was not a JSON object — the exact
        // condition each of those rows already had to survive when it owned
        // an eval of its own, so each one falls back to the bytes it printed
        // then (self::field(), self::reason()). $factsCall is kept alive past
        // the raw probe below because two of the three rows are rendered
        // after it, in render order.
        $factsCall = ['exit' => 0, 'stdout' => '', 'stderr' => ''];
        $facts = null;
        if ($installed) {
            $factsCall = $captureWp(['eval', self::SITE_FACTS]);
            $facts = self::site_facts($factsCall);
        }

        $agentPresent = false;
        if ($installed) {
            $out = self::field($facts, 'agent', $factsCall);
            $agentPresent = $factsCall['exit'] === 0 && $out === 'duo-ok';
            if ($agentPresent) {
                $detail = '';
            } elseif ($factsCall['exit'] !== 0) {
                $detail = self::reason($factsCall);
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
        $repoOk = false;
        $gitOut = '';
        if ($reachable) {
            $repoEsc = escapeshellarg($repo);
            $fileEsc = escapeshellarg(rtrim($repo, '/') . '/site.duo.json');
            // DUO-3511: one script, two answers — line 1 the repo-path
            // answer, line 2 the tracked-status answer the block below reads.
            // These two compose (where the three evals merely coexisted)
            // because the git half is genuinely gated on the repo half, and
            // that gate is a shell test: running it target-side costs nothing
            // and decides nothing this host needed to see first. A
            // repo-missing target therefore prints line 1 alone and its git
            // row renders the same `skipped: repo path unavailable` it
            // rendered when these were two calls. Exit status stays 0 in
            // every non-error case (each half already ended in an `echo`), so
            // a non-zero exit still means the transport failed rather than
            // that some answer was "no".
            $script = "if [ -d $repoEsc ] && [ -f $fileEsc ]; then echo duo-repo-ok; cd $repoEsc && "
                . '{ command -v git >/dev/null 2>&1 || { echo duo-nogit; exit 0; }; } && '
                . 'git ls-files --error-unmatch .duo-env-values.json >/dev/null 2>&1 '
                . '&& echo duo-tracked || echo duo-untracked; '
                . 'else echo duo-repo-missing; fi';
            $r = $t->captureRaw($script);
            // trim() first, then split: the pre-composition read was
            // trim($r['stdout']), so a transport that pads the payload with a
            // blank line still resolves to the same first answer it did then.
            $lines = explode("\n", trim($r['stdout']));
            $out = trim($lines[0]);
            $gitOut = trim($lines[1] ?? '');
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
        //
        // DUO-3511: that answer now arrives as line 2 of the composed repo
        // script above instead of from a probe of its own, so every branch
        // below switches on $gitOut — line 2 — and never on $out, which holds
        // the repo half's own `duo-repo-ok`. DUO-3512's sibling branch below
        // is what closes the "probe never ran" false PASS; under composition
        // its reachable trigger is exactly "line 1 said duo-repo-ok but line 2
        // is none of the three sentinels", because a non-zero exit sinks
        // $repoOk above and this block is gated on it — a failed call renders
        // the repo row's FAIL plus `skipped: repo path unavailable`, both
        // blocking, which is louder than this WARN and byte-identical to what
        // a failed repo probe rendered before either issue.
        if ($reachable && $repoOk) {
            if ($gitOut === 'duo-nogit') {
                $checks[] = self::check(
                    '.duo-env-values.json not git-tracked', false,
                    'could not verify — this environment has no git binary, so tracked-status cannot be '
                        . 'checked from inside it. Verify manually (from a machine with a checkout of this '
                        . 'repo): git -C <checkout> ls-files --error-unmatch .duo-env-values.json (should '
                        . 'exit non-zero, meaning untracked/absent).',
                    true
                );
            } elseif ($r['exit'] !== 0 || ($gitOut !== 'duo-tracked' && $gitOut !== 'duo-untracked')) {
                // DUO-3512: the script above is built entirely from shell && / ||, so a
                // transport/shell failure (non-zero exit, empty or truncated stdout) reaches
                // here having produced none of the three sentinels the two branches around
                // this one assume. Before this branch existed, `$tracked = ($out ===
                // 'duo-tracked')` read that as false and this whole check rendered [PASS] —
                // a false clean bill of health from a probe that never actually ran, exactly
                // the failure mode the duo-nogit branch's own comment above (:98-110) exists
                // to rule out for the git-absent case specifically. That comment's "when it
                // CAN be checked" covers "git is missing"; it does not cover "the probe
                // errored out or returned garbage", so this is a second, sibling branch
                // rather than a fold-in: same non-blocking WARN vocabulary and manual-
                // verification remedy, naming that the probe didn't complete rather than
                // that git is absent.
                //
                // DUO-3511: the sentinel is line 2 of the composed repo/git
                // script now, so the reason is built from the git half's OWN
                // answer. self::reason($r) would otherwise return the whole
                // payload — `duo-repo-ok` and a newline folded into this
                // one-line detail — where DUO-3512 rendered just what the
                // tracked-status probe printed. stderr still wins over stdout
                // exactly as before, so a transport error keeps naming itself.
                // The `$r['exit'] !== 0` clause is kept verbatim and is
                // belt-and-braces here: see this block's DUO-3511 note above
                // for why a failed call cannot reach it.
                $checks[] = self::check(
                    '.duo-env-values.json not git-tracked', false,
                    'could not verify — the tracked-status probe did not run ('
                        . self::reason(['exit' => $r['exit'], 'stdout' => $gitOut, 'stderr' => $r['stderr']])
                        . '). Verify manually (from a machine with a checkout of this repo): git -C <checkout> '
                        . 'ls-files --error-unmatch .duo-env-values.json (should exit non-zero, meaning '
                        . 'untracked/absent).',
                    true
                );
            } else {
                $tracked = $gitOut === 'duo-tracked';
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

        // DUO-3231 (docs/code-half.md risk register #1):
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
            $out = self::field($facts, 'file_mods', $factsCall);
            $set = $factsCall['exit'] === 0 && $out === 'duo-set';
            $detail = $set ? '' : 'DISALLOW_FILE_MODS is not set (or false) in wp-config.php — wp-admin plugin/theme '
                . 'install/update/delete UI stays open, so a one-click update can silently drift this '
                . "environment's code out from under git (docs/code-half.md risk #1). Recommended: "
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
        // forbids. WordPress and PHP are both bounded ranges narrowed to the
        // exercised series in their own `verified` map rather than fabricated
        // open ranges, and these rows reproduce the agent's predicate exactly
        // — inside [min, max) AND the observed MAJOR.MINOR present in
        // `verified` (agent/src/Policy/PlatformCompatibility.php:
        // exercised_supported(), which is one shared implementation for both
        // axes). Both halves matter here for the same reason they matter
        // there: doctor must never label a runtime compatible that the direct
        // product path refuses, and a bare range would do exactly that for a
        // minor line inside the window that nobody exercised.
        //
        // The database row is per-engine for the same honesty reason in a
        // different shape: `engines` maps each claimed engine to its own
        // range, so an engine the map does not name is refused outright
        // rather than measured against another product's numbers, and the
        // range this row applies is the one belonging to the engine actually
        // observed (PlatformCompatibility::valid_database_axis()). The
        // comparison stays case-insensitive because the fact this row reads
        // is the target's own lower-case `db_engine`, while the claim spells
        // engines the way their vendors do (MariaDB, MySQL).
        if ($installed) {
            $baseline = self::read_baseline();
            if ($baseline === null) {
                $checks[] = self::check(
                    'compatibility baseline (docs/compatibility-baseline.json)', false,
                    'baseline file missing or malformed — cannot verify platform compatibility'
                );
            } else {
                // DUO-3511: the same refusal the pipe-separated read produced,
                // now reached by three routes that were one route before — a
                // failed call, an undecodable payload, and a field whose
                // target-side try/catch caught a throw. reason() quotes what
                // the target actually printed, which for the third route is
                // the payload naming exactly which facts came back null.
                $parts = self::compatibility_facts($facts);
                if ($parts === null) {
                    $checks[] = self::check(
                        'compatibility baseline (docs/compatibility-baseline.json)', false,
                        'could not read PHP/database/WordPress facts from the environment: ' . self::reason($factsCall)
                    );
                } else {
                    [$phpVersion, $dbVersion, $dbEngine, $wpVersion] = $parts;
                    $php = $baseline['php'] ?? null;
                    $phpSeries = is_array($php) && is_array($php['verified'] ?? null)
                        ? array_map('strval', array_keys($php['verified']))
                        : [];
                    usort($phpSeries, static fn(string $a, string $b): int => version_compare($a, $b));
                    // The same dotted-shape guard the WordPress row carries
                    // below, and for the same reason: the agent refuses an
                    // observed value that is not a plain dotted version
                    // (PlatformCompatibility::inside_range()), so a
                    // pre-release engine ('8.5.0RC1') must not pass here
                    // while `wp duo` refuses it.
                    $phpOk = is_array($php)
                        && preg_match('/^\d+(?:\.\d+){1,3}$/D', $phpVersion) === 1
                        && self::in_range($phpVersion, (string) $php['min'], (string) $php['max'])
                        && in_array(self::series($phpVersion), $phpSeries, true);
                    $checks[] = self::check(
                        "PHP version ($phpVersion)", $phpOk,
                        $phpOk ? '' : 'outside the exercised platform matrix (>=' . ($php['min'] ?? '?') . ' <'
                            . ($php['max'] ?? '?') . ', exercised series '
                            . ($phpSeries === [] ? '?' : implode(', ', $phpSeries))
                            . ' — docs/compatibility-baseline.json). Classification and '
                            . 'apply behavior are only tested inside this matrix.'
                    );
                    $db = $baseline['database'] ?? null;
                    $dbEngines = is_array($db) && is_array($db['engines'] ?? null) ? $db['engines'] : [];
                    $dbClaimed = array_map('strval', array_keys($dbEngines));
                    sort($dbClaimed, SORT_STRING);
                    $dbRange = null;
                    $dbClaimedName = '';
                    foreach ($dbEngines as $engine => $range) {
                        if (is_array($range) && strcasecmp((string) $engine, $dbEngine) === 0) {
                            $dbRange = $range;
                            $dbClaimedName = (string) $engine;
                        }
                    }
                    $dbOk = $dbRange !== null
                        && self::in_range($dbVersion, (string) ($dbRange['min'] ?? ''), (string) ($dbRange['max'] ?? ''));
                    $checks[] = self::check(
                        "database ($dbEngine $dbVersion)", $dbOk,
                        $dbOk ? '' : ($dbRange === null
                            ? 'claimed engines are ' . ($dbClaimed === [] ? '?' : implode(', ', $dbClaimed))
                                . ", found $dbEngine — a different database engine is "
                                . 'genuinely untested, not merely unpinned (docs/compatibility-baseline.json).'
                            : "outside the declared baseline for $dbClaimedName (>=" . ($dbRange['min'] ?? '?')
                                . ' <' . ($dbRange['max'] ?? '?')
                                . ' — docs/compatibility-baseline.json).')
                    );
                    $wordpress = $baseline['wordpress'] ?? null;
                    $verifiedSeries = is_array($wordpress) && is_array($wordpress['verified'] ?? null)
                        ? array_map('strval', array_keys($wordpress['verified']))
                        : [];
                    usort($verifiedSeries, static fn(string $a, string $b): int => version_compare($a, $b));
                    // The dotted-shape guard is the agent's, not decoration:
                    // PlatformCompatibility::inside_range() requires
                    // /^\d+(?:\.\d+){1,3}$/D of the OBSERVED value, so a
                    // pre-release core ('7.1-alpha-59000') is refused there.
                    // Without the same guard here version_compare would rank
                    // it inside the window and doctor would call a core
                    // compatible that a direct `wp duo` command refuses.
                    $wordpressOk = is_array($wordpress)
                        && preg_match('/^\d+(?:\.\d+){1,3}$/D', $wpVersion) === 1
                        && self::in_range($wpVersion, (string) ($wordpress['min'] ?? ''), (string) ($wordpress['max'] ?? ''))
                        && in_array(self::series($wpVersion), $verifiedSeries, true);
                    $checks[] = self::check(
                        "WordPress core ($wpVersion)",
                        $wordpressOk,
                        $wordpressOk ? '' : 'outside the exercised core matrix (>=' . ($wordpress['min'] ?? '?')
                            . ' <' . ($wordpress['max'] ?? '?') . ', exercised series '
                            . ($verifiedSeries === [] ? '?' : implode(', ', $verifiedSeries))
                            . ' — docs/compatibility-baseline.json).'
                    );
                }

                // Unlike the composed version/database/core tuple above,
                // these profiles each carry their own closed fact object.
                // A database probe failure must not hide whether process
                // prerequisites are independently safe or unsafe.
                $filesystem = $baseline['filesystem'];
                $filesystemFacts = self::filesystem_facts($facts);
                if ($filesystemFacts === null) {
                    $checks[] = self::check(
                        'filesystem process profile (unknown)',
                        false,
                        'could not read the OS/separator/function facts required by the durable filesystem profile'
                    );
                } else {
                    [$osFamily, $directorySeparator, $functions] = $filesystemFacts;
                    $missing = [];
                    foreach ($filesystem['required_functions'] as $function) {
                        if (($functions[$function] ?? false) !== true) {
                            $missing[] = $function;
                        }
                    }
                    $filesystemOk = in_array($osFamily, $filesystem['os_families'], true)
                        && hash_equals($filesystem['directory_separator'], $directorySeparator)
                        && $missing === [];
                    $checks[] = self::check(
                        "filesystem process profile ($osFamily)",
                        $filesystemOk,
                        $filesystemOk ? '' : 'requires OS '
                            . implode(', ', $filesystem['os_families'])
                            . ', separator ' . json_encode($filesystem['directory_separator'])
                            . ', and functions ' . implode(', ', $filesystem['required_functions'])
                            . ($missing === [] ? '' : '; missing ' . implode(', ', $missing))
                            . ' — docs/compatibility-baseline.json. Actual mutation roots are checked again before writes.'
                    );
                }

                $process = $baseline['process'];
                $processFacts = self::process_facts($facts);
                if ($processFacts === null) {
                    $checks[] = self::check(
                        'process group profile (unknown)',
                        false,
                        'could not read the OS/function/shell facts required by the bounded WP-CLI process-group profile'
                    );
                } else {
                    [$osFamily, $functions, $shellPath, $shellExecutable] = $processFacts;
                    $missing = [];
                    foreach ($process['required_functions'] as $function) {
                        if (($functions[$function] ?? false) !== true) {
                            $missing[] = $function;
                        }
                    }
                    $processOk = in_array($osFamily, $process['os_families'], true)
                        && $missing === []
                        && hash_equals($process['shell'], $shellPath)
                        && $shellExecutable;
                    $checks[] = self::check(
                        "process group profile ($osFamily)",
                        $processOk,
                        $processOk ? '' : 'requires OS ' . implode(', ', $process['os_families'])
                            . ' and functions ' . implode(', ', $process['required_functions'])
                            . ($missing === [] ? '' : '; missing ' . implode(', ', $missing))
                            . ', plus executable shell ' . $process['shell']
                            . ($shellPath === $process['shell'] ? '' : '; observed shell '
                                . json_encode($shellPath, JSON_UNESCAPED_SLASHES))
                            . ($shellExecutable ? '' : '; shell is not executable')
                            . ' — docs/compatibility-baseline.json. The child transport still validates its own cleanup.'
                    );
                }
            }
        } else {
            $checks[] = self::check('compatibility baseline (docs/compatibility-baseline.json)', false, 'skipped: WordPress not installed');
        }

        // Deliberately OUTSIDE the `if ($installed)` and baseline nesting
        // above: this is the row a PRE-ADOPTION target needs most, and a
        // network must never read as an all-green screen before anything is
        // installed. Sourced from SITE_FACTS' own key rather than from
        // compatibility_facts(): that helper returns null if ANY of its four
        // keys is missing and its [$php,$db,$engine,$wp] destructure is
        // load-bearing, so a fifth key there would sink three unrelated rows.
        //
        // A FAIL, not an advisory, because docs/compatibility-baseline.json's
        // own _comment already claims "the agent pre-policy gate and duo doctor
        // block outside these values" and until now that sentence was false for
        // topology. 'single-site' is hard-coded rather than read from that file:
        // tools/capability-doc.php:192-199 byte-compares the baseline object
        // against manifests/capabilities/platform.json's `compatibility` (which
        // declares database/filesystem/php/process/wordpress), so a
        // `site_mode` key there would fail `make release-gate`. The declared value lives in that platform
        // boundary instead, as `"site_mode": "single-site"`
        // (manifests/capabilities/platform.json:24), and the agent enforces it
        // through SiteTopology::assert_single_site().
        $siteMode = $facts['site_mode'] ?? null;
        $checks[] = self::check(
            'site topology (' . (is_string($siteMode) ? $siteMode : 'unknown') . ')',
            $siteMode === 'single-site',
            $siteMode === 'single-site' ? '' : 'the certified v1 contract is single-site only; the agent pre-policy '
                . 'gate refuses every mutating command on a network (docs/compatibility-baseline.json).'
        );

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

    /**
     * The decoded SITE_FACTS payload, or null when there is none to read.
     *
     * @param array{exit:int, stdout:string, stderr:string} $r
     * @return ?array<string, mixed>
     */
    private static function site_facts(array $r): ?array {
        if ($r['exit'] !== 0) {
            return null;
        }
        $decoded = json_decode(trim($r['stdout']), true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * One field of the composed payload, or — when the payload could not be
     * decoded or does not carry that field as a string — the raw stdout the
     * row read when it owned its own eval. So `agent class not found (wp eval
     * returned 'duo-missing')` stays byte-identical on the decoded path, and
     * a target that printed something else still gets that something quoted
     * back at its operator instead of a blob it never sent.
     *
     * @param ?array<string, mixed> $facts
     * @param array{exit:int, stdout:string, stderr:string} $r
     */
    private static function field(?array $facts, string $key, array $r): string {
        $value = $facts[$key] ?? null;
        return is_string($value) ? $value : trim($r['stdout']);
    }

    /**
     * The four compatibility facts, or null when ANY of them is missing —
     * the sentinel a target-side try/catch leaves behind for the one field
     * that threw. An empty string is a VALUE here, not a sentinel: the
     * pre-composition snippet concatenated a null return into the payload as
     * '' and compared it as '', and is_string() keeps that path intact while
     * still catching the null a throw produces.
     *
     * @param ?array<string, mixed> $facts
     * @return ?list<string>
     */
    private static function compatibility_facts(?array $facts): ?array {
        $parts = [];
        foreach (['php', 'db_version', 'db_engine', 'wp'] as $key) {
            $value = $facts[$key] ?? null;
            if (!is_string($value)) {
                return null;
            }
            $parts[] = $value;
        }
        return $parts;
    }

    /** @return ?array{string,string,array<string,bool>} */
    private static function filesystem_facts(?array $facts): ?array {
        $filesystem = is_array($facts) ? ($facts['filesystem'] ?? null) : null;
        $functions = is_array($filesystem) ? ($filesystem['functions'] ?? null) : null;
        if (!is_array($filesystem)
            || !is_string($filesystem['os_family'] ?? null)
            || $filesystem['os_family'] === ''
            || !is_string($filesystem['directory_separator'] ?? null)
            || $filesystem['directory_separator'] === ''
            || !is_array($functions)) {
            return null;
        }
        $keys = array_keys($functions);
        sort($keys, SORT_STRING);
        if ($keys !== ['chmod', 'flock', 'fsync', 'lstat', 'rename']) {
            return null;
        }
        foreach ($functions as $available) {
            if (!is_bool($available)) {
                return null;
            }
        }
        return [$filesystem['os_family'], $filesystem['directory_separator'], $functions];
    }

    /** @return ?array{string,array<string,bool>,string,bool} */
    private static function process_facts(?array $facts): ?array {
        $process = is_array($facts) ? ($facts['process'] ?? null) : null;
        $functions = is_array($process) ? ($process['functions'] ?? null) : null;
        $shell = is_array($process) ? ($process['shell'] ?? null) : null;
        if (!is_array($process)
            || !is_string($process['os_family'] ?? null)
            || $process['os_family'] === ''
            || !is_array($functions)
            || !is_array($shell)
            || !is_string($shell['path'] ?? null)
            || $shell['path'] === ''
            || !is_bool($shell['executable'] ?? null)) {
            return null;
        }
        $keys = array_keys($functions);
        sort($keys, SORT_STRING);
        $shellKeys = array_keys($shell);
        sort($shellKeys, SORT_STRING);
        if ($keys !== [
            'pcntl_exec',
            'posix_kill',
            'posix_setsid',
            'proc_close',
            'proc_get_status',
            'proc_open',
            'proc_terminate',
        ] || $shellKeys !== ['executable', 'path']) {
            return null;
        }
        foreach ($functions as $available) {
            if (!is_bool($available)) {
                return null;
            }
        }
        return [$process['os_family'], $functions, $shell['path'], $shell['executable']];
    }

    /**
     * The baseline, or null when it cannot state a whole boundary. Every
     * axis's narrowing half is required alongside its bounds — `verified` for
     * PHP and WordPress, `engines` for the database — for the same reason the
     * bounds themselves are: a truncated axis must sink the row into
     * "baseline file missing or malformed" rather than let a missing key
     * evaluate to a silent pass. A missing `php.verified` would leave the
     * series list empty and refuse everything, which is loud but blames the
     * runtime instead of the file; a missing `database.engines` would refuse
     * every engine with the same misdirection.
     *
     * @return ?array{php:array{min:string,max:string,verified:array<string,string>}, database:array{engines:array<string,array{min:string,max:string}>}, filesystem:array{directory_separator:string,os_families:list<string>,profile:string,required_functions:list<string>}, process:array{os_families:list<string>,profile:string,required_functions:list<string>,shell:string}, wordpress:array{min:string,max:string,verified:array<string,string>,last_verified:string}}
     */
    private static function read_baseline(): ?array {
        $file = dirname(__DIR__, 3) . '/docs/compatibility-baseline.json';
        if (!is_file($file)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($file), true);
        if (!is_array($data)
            || !isset($data['php']['min'], $data['php']['max'])
            || !is_array($data['php']['verified'] ?? null) || $data['php']['verified'] === []
            || !is_array($data['database']['engines'] ?? null) || $data['database']['engines'] === []
            || !is_array($data['filesystem'] ?? null)
            || ($data['filesystem']['directory_separator'] ?? null) !== '/'
            || ($data['filesystem']['profile'] ?? null) !== 'local-posix-atomic-rename-flock-fsync/v1'
            || ($data['filesystem']['os_families'] ?? null) !== ['Darwin', 'Linux']
            || ($data['filesystem']['required_functions'] ?? null) !== ['chmod', 'flock', 'fsync', 'lstat', 'rename']
            || !is_array($data['process'] ?? null)
            || ($data['process']['profile'] ?? null) !== 'local-posix-process-group-exec/v1'
            || ($data['process']['os_families'] ?? null) !== ['Darwin', 'Linux']
            || ($data['process']['required_functions'] ?? null) !== [
                'pcntl_exec', 'posix_kill', 'posix_setsid', 'proc_close',
                'proc_get_status', 'proc_open', 'proc_terminate',
            ]
            || ($data['process']['shell'] ?? null) !== '/bin/sh'
            || !isset($data['wordpress']['min'], $data['wordpress']['max'])
            || !is_array($data['wordpress']['verified'] ?? null) || $data['wordpress']['verified'] === []) {
            return null;
        }
        return $data;
    }

    /** The MAJOR.MINOR series of a dotted version — the agent's own PlatformCompatibility::series(), kept as an independent copy here for the reason in_range() is (see below). */
    private static function series(string $version): string {
        return preg_match('/^(\d+\.\d+)/', $version, $match) === 1 ? $match[1] : '';
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
