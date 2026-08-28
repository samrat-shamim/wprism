<?php
/**
 * The two agent defines, taken from `agent/duo.php` rather than retyped.
 *
 * WHY THIS FILE EXISTS (WP-4.12 — THE FLIP)
 * -----------------------------------------
 * An offline suite that never loads `agent/duo.php` still has to satisfy the
 * files that read `DUO_AGENT_VERSION` / `DUO_SPEC_VERSION`, so ~69 suites open
 * with a `if (!defined(...)) define(..., 2)` guard. Every one of those literals
 * is a private copy of a number AGENTS.md rule 8 says moves in exactly one
 * commit, together with `platform/adapter-library/capabilities/platform.json`.
 *
 * That was free while the number never moved. The flip moved it, and the cost
 * was measured: 15 of the 28 suites that went red on the bumped tree failed
 * with one sentence — `ManifestDispositions::platform_boundary()`'s "platform
 * version disagrees with the loaded agent" — because the suite had defined 2
 * and then read the shipped `platform.json`, which now restates 3. None of
 * those suites is ABOUT the version; each was asserting something else and
 * carrying a literal that had rotted.
 *
 * Retyping 3 in all of them would buy exactly one release of quiet and leave
 * the identical trap armed for the next reader. So the guard reads the source
 * of record instead. This is the same regex `AdapterCertify::boot()` (:1463-
 * 1481, the two `preg_match` calls at :1471 and :1477) and
 * `tools/wire-surface.php` (:128-136) already use, for the same stated reason:
 * a literal drifts, and a drifted literal here does not read as a version
 * problem — it reads as whatever the suite was really testing.
 *
 * WHAT IT DELIBERATELY DOES NOT DO. It never redefines a constant a caller
 * already set. A suite that must run AT a version other than the tree's — the
 * flag-day rehearsal's state B, `regress_spec_window.php`'s N+1 child, the
 * platform-compatibility disagreement cases — defines its own value first and
 * this helper leaves it alone. Deriving-by-default and overriding-on-purpose is
 * the split that keeps both kinds of suite honest.
 */
declare(strict_types=1);

if (!function_exists('duo_test_define_agent_versions')) {
    /**
     * Define `DUO_AGENT_VERSION` and `DUO_SPEC_VERSION` from `agent/duo.php`
     * unless the caller already defined them.
     *
     * Refuses loudly rather than falling back to a literal: a suite that
     * silently ran at a guessed version would produce exactly the false green
     * this file exists to remove.
     *
     * @return array{0:string,1:int} the pair now in force, derived or inherited
     */
    function duo_test_define_agent_versions(): array {
        static $derived = null;
        if ($derived === null) {
            $duo = dirname(__DIR__, 3) . '/agent/duo.php';
            $source = @file_get_contents($duo);
            if (!is_string($source)) {
                throw new RuntimeException("duo test harness: cannot read $duo for the agent defines");
            }
            if (preg_match("/define\('DUO_AGENT_VERSION', '([^']+)'\)/", $source, $agent) !== 1
                || preg_match("/define\('DUO_SPEC_VERSION', ([0-9]+)\)/", $source, $spec) !== 1) {
                throw new RuntimeException(
                    "duo test harness: $duo no longer declares both agent defines in the form this helper reads"
                );
            }
            $derived = [$agent[1], (int) $spec[1]];
        }
        if (!defined('DUO_AGENT_VERSION')) {
            define('DUO_AGENT_VERSION', $derived[0]);
        }
        if (!defined('DUO_SPEC_VERSION')) {
            define('DUO_SPEC_VERSION', $derived[1]);
        }
        return [(string) DUO_AGENT_VERSION, (int) DUO_SPEC_VERSION];
    }
}
