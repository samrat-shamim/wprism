<?php
declare(strict_types=1);

namespace WPrism;

require_once __DIR__ . '/CommandRefusal.php';

/**
 * The single pre-mutation topology gate.
 *
 * V1 is deliberately single-site. The throw lived inside
 * `Policy::assert_single_site()` and was therefore only reachable by verbs that
 * build a Policy — but `journal-reset` (Cli.php: `Ledger::ensure()` and then
 * the provenance journal's own truncation), the four promotion-lease verbs
 * (`Ledger::ensure()` then `PromotionLock::*`), and `classify --set` never do,
 * so on a network they created a per-blog `wp_N_wprism_*` table set and took
 * leases with no gate at any layer. A Kernel file with one dependency
 * (Kernel/CommandRefusal.php) is the cheapest home that every one of those
 * doors can reach without loading the policy machinery.
 *
 * The refusal is TYPED, not a bare \RuntimeException. `Cli::halt_json_failure()`
 * publishes `CommandRefusalException` on its own reason code and does not
 * redact it (agent/src/Command/Cli.php:60-64); a bare RuntimeException is not
 * in `PUBLIC_REFUSAL_CLASSES` (:397-399) and collapsed to
 * `<command>_failed` / "refused at an unclassified safety gate" with
 * `details_redacted: true` (:84-85, :98), so the word "multisite" never reached
 * a `--format=json` caller.
 *
 * The reason code `multisite_unsupported` is REUSED from
 * `InitPlanner::…` (agent/src/Init/InitPlanner.php:372-379), which already
 * emits it for the same whole-target fact, so an orchestrator that branches on
 * it keeps working. It is NOT a registry blocker code: the host retired it from
 * that vocabulary (cli/src/Contract/ProjectionVocabulary.php:219-235) and it
 * must never be added to a `V::BLOCKERS_*` array — agent refusal reason codes
 * and registry blocker codes are separate namespaces, which is why
 * `assess_topology_unsupported` and `platform_unsupported` already live in the
 * former without appearing in the latter.
 */
final class SiteTopology {
    /**
     * Refuse a network before policy load or any state mutation.
     *
     * The `function_exists` guard keeps the pure offline policy validators
     * usable outside WordPress while the real product path always has
     * `is_multisite()`.
     */
    public static function assert_single_site(): void {
        if (!function_exists('is_multisite') || !is_multisite()) {
            return;
        }
        throw new CommandRefusalException(
            'multisite_unsupported',
            'multisite is unsupported by the certified v1 contract; this command is single-site only and refuses before loading policy or mutating state',
            'run this command against a single-site WordPress installation; multisite is outside the certified v1 contract',
            [],
            // The 5th argument is the operator message. CommandRefusal.php:52
            // passes `$operatorMessage ?? $publicMessage` to
            // parent::__construct, so getMessage() -- and therefore
            // `WP_CLI::error($t->getMessage())` -- stays byte-identical to the
            // sentence Policy.php has printed since issue #3223 (rule 8; the live
            // greps at sandbox/tests/live/regress_multisite_refusal.sh:63,65
            // read exactly these bytes).
            'wprism: multisite is unsupported by the certified v1 contract; this command is single-site only and refuses before loading policy or mutating state'
        );
    }
}
