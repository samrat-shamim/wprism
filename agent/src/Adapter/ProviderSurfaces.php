<?php
declare(strict_types=1);

namespace WPrism;

// The sanctioned checked-read path (`$wpdb->last_error` cleared, run, and the
// failure shape refused) and the one place its message hygiene is stated. The
// engine's reading of a provider's declared surface is subject to exactly the
// contract ProviderSdk exists to enforce, so it goes through that file rather
// than restating the twin predicate here. Same-module require, matching how
// every other file under agent/src names what it loads.
require_once __DIR__ . '/ProviderSdk.php';

/**
 * Engine-side observation of the surfaces a provider capability declared.
 *
 * `Providers::invoke()` refuses a receipt whose `verified` is not exactly true
 * — "a receipt must prove the state it wrote, not that a call returned"
 * (Providers.php:1050-1054). Until this file existed that refusal could only
 * check that third-party code SAID so: the receipt's `before`/`after` are
 * provider bytes, and the engine's own docblock conceded that nothing there
 * compared them, so bounding could not reach a verification verdict. This is
 * the missing half — the engine reads the capability's OWN declared surfaces
 * either side of the call and compares the two readings, so `verified: true`
 * is checked against something the provider did not author.
 *
 * WHAT IS OBSERVABLE, AND WHY THE LIST IS THIS SHORT
 *
 * A declared surface is one of `post|term|table|option|entity:<name>`
 * (Policy::SURFACE_PATTERN, Policy.php:217). Only `option:<name>` names a
 * BOUNDED extent — one row, addressable by name — and only a bounded extent
 * has a reading that is both complete and affordable:
 *
 *   - complete, because a partial witness (a row count, a max id) cannot tell
 *     "nothing changed" from "an update I cannot see", and the refusal below
 *     for a write the surface does not show is exactly a claim that nothing
 *     changed. A witness that under-detects would refuse honest providers.
 *   - affordable, because `table:postmeta`, `post:product` and their siblings
 *     name whole tables and whole post types. A complete witness over one of
 *     those is a full scan of a core table, twice per invocation, on every
 *     apply — a cost the engine may not impose on a target to check somebody
 *     else's receipt. `entity:<name>` is worse than unaffordable: the name is
 *     minted by the adapter (`entity:woocommerce-cache-groups`,
 *     `entity:code-snippets-flat-files`) and the engine has no reader for it
 *     at all.
 *
 * So everything except `option:` is UNOBSERVABLE by this engine, and saying so
 * is the point: observation_plan() is pure and is published by
 * Providers::negotiate() under `surface_observation`, which makes an
 * unobservable surface a fact an operator can read BEFORE the apply mutates
 * anything, rather than a surprise the engine discovers mid-write. Nothing
 * here refuses a capability for declaring one; the check simply does not
 * reach it, and the negotiation output says which surfaces it did reach.
 *
 * Widening observable() past `option:` is a real design change, not a config
 * tweak: a new kind must come with a reader that is complete over the
 * surface's whole extent and bounded in rows returned, or the refusals in
 * Providers::invoke() stop being sound.
 */
final class ProviderSurfaces {
    /**
     * One target read plus bind, immediate pre-transport, and terminal session
     * observations per observable surface per pass. The suite pins the full
     * server-query cost, including direct mysqli proofs absent from wpdb logs.
     */
    public const QUERIES_PER_SURFACE_PER_PASS = 4;

    /** Two passes: one before the provider call, one after. */
    public const PASSES_PER_INVOKE = 2;

    /**
     * The observable surface grammar, deliberately restated rather than
     * imported. Policy::SURFACE_PATTERN is the authority on what a
     * declaration may contain (Policy.php:217) and validate_capability_
     * declaration() has already enforced it for anything that reached
     * negotiation; this narrower pattern answers a different question — which
     * of those surfaces THIS file has a reader for — and keeping it local
     * means the observer loads without the policy layer.
     */
    private const OPTION_SURFACE_PATTERN = '/^option:([a-z0-9_][a-z0-9._-]{0,127})$/D';

    /** A surface whose reader found no row at all, as distinct from any digest. */
    private const ABSENT = 'absent';

    /**
     * What the engine will watch for one capability, decided from the
     * declaration alone — no query, no target, no provider call. Purity is
     * what lets negotiation publish this answer and invoke() re-derive the
     * identical one, instead of the two agreeing by convention.
     *
     * `read_only` is the surfaces the capability declared under `reads` and
     * NOT under `writes`. Its own declaration is what makes a change there a
     * defect: the capability said it would read them.
     *
     * @param array<string,mixed> $capabilityDecl one advertised declaration
     * @return array{watched:list<string>, writes:list<string>, read_only:list<string>, unobservable:list<string>, writes_fully_observable:bool, queries_per_invoke:int}
     */
    public static function observation_plan(array $capabilityDecl): array {
        $declaredWrites = self::surfaces($capabilityDecl['writes'] ?? null);
        $declaredReads = self::surfaces($capabilityDecl['reads'] ?? null);
        $writes = [];
        $readOnly = [];
        $unobservable = [];
        // An empty `writes` list declares no write at all, so there is no
        // claim for a surface to contradict: false keeps the unshown-write
        // refusal off a capability that never promised one.
        $writesFullyObservable = $declaredWrites !== [];
        foreach ($declaredWrites as $surface) {
            if (self::observable($surface)) {
                $writes[$surface] = true;
            } else {
                $unobservable[$surface] = true;
                $writesFullyObservable = false;
            }
        }
        foreach ($declaredReads as $surface) {
            if (!self::observable($surface)) {
                $unobservable[$surface] = true;
                continue;
            }
            if (!isset($writes[$surface])) {
                $readOnly[$surface] = true;
            }
        }
        $watched = $writes + $readOnly;
        ksort($watched, SORT_STRING);
        ksort($writes, SORT_STRING);
        ksort($readOnly, SORT_STRING);
        ksort($unobservable, SORT_STRING);
        return [
            'watched' => array_keys($watched),
            'writes' => array_keys($writes),
            'read_only' => array_keys($readOnly),
            'unobservable' => array_keys($unobservable),
            'writes_fully_observable' => $writesFullyObservable,
            'queries_per_invoke' => count($watched) * self::QUERIES_PER_SURFACE_PER_PASS * self::PASSES_PER_INVOKE,
        ];
    }

    /** Whether this engine has a complete, bounded reader for one surface. */
    public static function observable(mixed $surface): bool {
        return is_string($surface) && preg_match(self::OPTION_SURFACE_PATTERN, $surface) === 1;
    }

    /**
     * Read every watched surface once, as CHECKED reads.
     *
     * A read that failed must never read as "unchanged": that is the exact
     * shape of a silent pass, so a driver error, a malformed row, or a missing
     * database handle throws rather than producing a witness. The BEFORE pass
     * runs ahead of the provider call, which is what keeps a target that
     * cannot answer these reads a refusal before mutation rather than after.
     *
     * @param list<string> $surfaces from observation_plan()['watched']
     * @return array<string,string> surface => witness, in the given order
     */
    public static function observe(array $surfaces, string $id, string $capability): array {
        if ($surfaces === []) {
            return [];
        }
        global $wpdb;
        // Checked before the first read rather than after a null dereference:
        // ProviderSdk's twin predicate distinguishes a failed read from an
        // empty one, but it cannot distinguish either from a $wpdb that was
        // never there, and this refusal has to name that separately.
        if (!is_object($wpdb) || !method_exists($wpdb, 'prepare') || !method_exists($wpdb, 'get_results')) {
            throw new \RuntimeException(
                "wprism: provider '$id' capability '$capability' declares surfaces the engine must observe either "
                . 'side of the call, but this process has no WordPress database handle — run the capability '
                . 'through the ordinary apply path'
            );
        }
        $witnesses = [];
        foreach ($surfaces as $surface) {
            if (preg_match(self::OPTION_SURFACE_PATTERN, (string) $surface, $match) !== 1) {
                // Unreachable from observation_plan(), whose watched list is
                // filtered by the same pattern. Fail closed rather than skip:
                // a surface silently dropped here would read downstream as a
                // surface that did not change.
                throw new \RuntimeException(
                    "wprism: provider '$id' capability '$capability' asked the engine to observe a surface it has "
                    . 'no reader for'
                );
            }
            $witnesses[(string) $surface] = self::option_witness($match[1], $id, $capability);
        }
        return $witnesses;
    }

    /**
     * One option row, as a value-level witness.
     *
     * The query is the shape CacheInvalidationTransaction::lock_option_row()
     * already reads a bounded option witness with
     * (CacheInvalidationTransaction.php:249-256): SHA2(..., 256) rather than
     * the value, because the comparison needs equality and not the bytes, and
     * an option value can be megabytes of serialized state that must not be
     * pulled into engine memory — or into a refusal message — to answer
     * whether it moved.
     *
     * `autoload` is folded in because flipping it is a write to the row: an
     * apply that left an option's value alone and made it autoload is a change
     * to the surface, and a witness that ignored it would report the surface
     * unchanged. `option_name` is folded in for the same reason — under a
     * stock utf8mb4_* collation `WHERE option_name = %s` still matches a row
     * whose case was changed, and a rename is a change to the surface.
     *
     * Two collation-equal rows REFUSE rather than fold, the same verdict
     * lock_option_row() reaches at CacheInvalidationTransaction.php:233
     * ("ambiguous collation-equal option rows"): option_name is UNIQUE in the
     * core schema, so a second row means the extent this witness claims to
     * cover completely is not the extent it read, and completeness is the
     * whole justification for the unshown-write refusal downstream. LIMIT 2
     * bounds the read to what that verdict needs.
     */
    private static function option_witness(string $name, string $id, string $capability): string {
        global $wpdb;
        $rows = ProviderSdk::checked_get_results(
            $wpdb->prepare(
                'SELECT option_name, SHA2(option_value, 256) AS option_value_sha256, '
                . "SHA2(autoload, 256) AS autoload_sha256 FROM {$wpdb->options} WHERE option_name = %s "
                . 'ORDER BY option_id ASC LIMIT 2',
                $name
            ),
            self::read_context($id, $capability),
            $wpdb
        );
        if (!array_is_list($rows) || count($rows) > 1) {
            throw self::unreadable_surface($id, $capability);
        }
        if ($rows === []) {
            return self::ABSENT;
        }
        $row = $rows[0];
        if (!is_array($row)
            || array_keys($row) !== ['option_name', 'option_value_sha256', 'autoload_sha256']
            || !is_string($row['option_name'])
            || !self::is_sha256_or_null($row['option_value_sha256'])
            || !self::is_sha256_or_null($row['autoload_sha256'])) {
            throw self::unreadable_surface($id, $capability);
        }
        // NULL and the empty string are different states of the column and must
        // digest differently; a NULL column's SHA2 is NULL, so it is spelled
        // rather than cast. The separators are outside the hashed values, which
        // are fixed-width hex, so no two rows can collide by concatenation.
        return 'sha256:' . hash('sha256', $row['option_name'] . '|'
            . ($row['option_value_sha256'] === null ? 'null' : $row['option_value_sha256']) . '|'
            . ($row['autoload_sha256'] === null ? 'null' : $row['autoload_sha256']));
    }

    /**
     * ProviderSdk's context is OPERATION-level and reaches the operator
     * verbatim, so it carries only what the provider already declared
     * (ProviderSdk.php:17-23). Named "surface observation" rather than a
     * capability's own read, because the caller here is the engine checking
     * the receipt, not the adapter making a decision.
     */
    private static function read_context(string $id, string $capability): string {
        return "surface observation for provider '$id' capability '$capability'";
    }

    /**
     * Value-free by construction, like every other refusal this contract
     * emits: the option's name is a declaration fact the provider already
     * published, but the row it failed to read is target state.
     */
    private static function unreadable_surface(string $id, string $capability): \RuntimeException {
        return new \RuntimeException(
            "wprism: provider '$id' capability '$capability' could not be checked against its own declared "
            . 'surfaces — a checked read of the target failed, and an unread surface must not pass as an '
            . 'unchanged one; retry the apply once the database is answering'
        );
    }

    /**
     * The declared list, defensively narrowed. invoke() may be handed a
     * declaration that never went through validate_capability_declaration()
     * (a direct caller, a future seam), and a malformed entry must degrade to
     * "the engine has no reader for it" rather than to a fatal inside the
     * observer.
     *
     * @return list<string>
     */
    private static function surfaces(mixed $declared): array {
        if (!is_array($declared)) {
            return [];
        }
        $out = [];
        foreach ($declared as $surface) {
            if (is_string($surface) && $surface !== '') {
                $out[] = $surface;
            }
        }
        return $out;
    }

    private static function is_sha256_or_null(mixed $value): bool {
        return $value === null || (is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1);
    }
}
