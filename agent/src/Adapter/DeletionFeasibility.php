<?php
declare(strict_types=1);

namespace WPrism;

require_once __DIR__ . '/../Kernel/Canon.php';
require_once __DIR__ . '/../Kernel/CommandRefusal.php';
require_once __DIR__ . '/../Delete/DeleteGuardEvaluator.php';

/**
 * Can each PROPOSED deletion guard lock? (`wprism-deletion-feasibility/v2`)
 *
 * A manifest's deletion contract is only as strong as its guards' lock
 * boundaries: `DeleteGuardReferenceScanner` runs each guard's final
 * `SELECT … FOR UPDATE` behind `FORCE INDEX (<lock_index>)` and refuses the
 * whole deletion when `DeleteGuardEvaluator::lock_index()` answers null —
 * "guard table 'X' has no complete indexed lock boundary for Y"
 * (`agent/src/Delete/DeleteGuardReferenceScanner.php:135-142`). Today an
 * author learns that at DELETION time, on a live site, after the selector is
 * already declared and pinned. This emitter runs the identical computation at
 * AUTHORING time, over a proposal that is not declared anywhere yet.
 *
 * `manifests/ninja-forms.json:17`'s issue #3328 note is a prose transcription of
 * exactly this function's output: "Ninja Forms 3.14.11 ships
 * nf3_actions.parent_id and nf3_fields.parent_id without complete indexes, so
 * InnoDB cannot take the next-key/gap locks required … WPrism therefore does not
 * advertise table:nf3_forms deletion." That paragraph was written by a human
 * reading a live schema by hand. This class computes the null it records.
 *
 * ## Why a sibling document and not `wprism-adapter-probe/v1`
 *
 * The probe is the natural carrier — it already reports per-column index
 * coverage in `lock_index()`'s terms (`AdapterProbe::indexes_of():232-292`)
 * — and it is exactly the wrong one. Its first structural boundary is that it
 * "carries no `class`, `identity`, `deletion` or capability word anywhere"
 * (`AdapterProbe.php:33-38`), and `AdapterDraft::read_probe()` enforces that
 * from the consumer side against a CLOSED per-table key set. A feasibility
 * row is keyed by a DELETION SELECTOR, so joining it to that document would
 * either put deletion vocabulary into the draft's evidence stream — the one
 * thing WP-2.1 walled off — or force `wprism-adapter-probe/v2` on every existing
 * consumer to carry a section the draft must then refuse. A sibling format is
 * the honest shape: one document, one question, its own hash, consumed by a
 * human rather than spliced into a fragment.
 *
 * ## What it is not
 *
 * A covering index is a NECESSARY condition for a deletion selector, never a
 * sufficient one. "This guard can lock" does not mean "declare this
 * selector": the honest answer for an unindexed plugin schema stays "do not
 * advertise it", and that decision is a human's. So this document proposes no
 * manifest fragment, names no cascade set, and refuses a proposal that hands
 * it one — a report that echoed a capability back would be read as endorsing
 * it. `authority: false` is declared in its own bytes, exactly like the probe.
 *
 * Nor is it a manifest validator. `DeletionCapabilityResolver::capability()`
 * owns the guard grammar (`:47-162`) and `wprism manifest-validate` runs it; this
 * report reads a proposal no manifest has adopted yet, so "is this guard
 * well-formed" is a question it deliberately leaves to the tool that already
 * answers it, and a proposal that would fail there still gets a lock answer
 * here.
 *
 * ## The verdict is the engine's, not a copy of it
 *
 * `guard_row()` calls `DeleteGuardEvaluator::lock_index()` and publishes ITS
 * return value; the walk beside it only EXPLAINS that verdict. When the two
 * disagree — a second reader of the same inventory reaching a different
 * conclusion, or the index set moving between the two reads — the report
 * refuses rather than publishing a reason that does not belong to the answer.
 * Two implementations of one rule is the drift this class exists to prevent,
 * so it is checked on every guard rather than assumed.
 */
final class DeletionFeasibility {
    public const FORMAT = 'wprism-deletion-feasibility/v2';

    /** Same word `AdapterProbe`/`AdapterObservation` publish: values never enter the document. */
    public const REDACTION = 'values_omitted';

    /** An authoring step over one adapter's proposed deletion contract, not a schema sweep. */
    public const MAX_SELECTORS = 32;

    /**
     * Deletion guards are hand-written reverse-reference checks: the widest
     * set any shipped selector declares is 2 (`core.json`'s `post:post`), so
     * this is a loud ceiling on a hand-authored list, not a budget to fill.
     */
    public const MAX_GUARDS = 32;

    /** No index leads with the column the guard would lock on. */
    public const NO_LEADING_INDEX = 'no index leads with this column';

    /** The guard table itself is missing, which the scanner reports before any index question. */
    public const TABLE_ABSENT = 'guard table is absent on this target';

    /**
     * The portable identifier grammar every name in the document must match.
     * `lock_index()` strips exotic characters from a server identifier
     * (`DeleteGuardEvaluator.php:433`) and the scanner then interpolates the
     * SURVIVING name into `FORCE INDEX (…)`
     * (`DeleteGuardReferenceScanner.php:145`), so a name outside this class is
     * one the report could only describe by silently rewriting it — the same
     * refusal `AdapterProbe::assert_identifier()` makes for the same reason.
     */
    private const IDENTIFIER = '/^[A-Za-z0-9_]{1,64}$/D';

    /** `Deletion::selector()` (`:31`) is `<kind>:<type>`; the four kinds are `Deletion::capability()`'s own match arms (`:49-52`). */
    private const SELECTOR = '/^(post|term|menu|table):[A-Za-z0-9_-]{1,64}$/D';

    /**
     * The guard fields this report models — `DeletionCapabilityResolver::
     * capability()`'s own guard grammar (`:47-162`: `table`/`column`/`id_kind`
     * required, `meta_key`+`ref` paired, `option_name_ref`, `identity_column`,
     * `source_id_kind`+`source_pk` paired, `where`, `exclude_where`, `cast`),
     * plus `reason`, the authored prose the evaluator prints when a guard
     * blocks (`DeleteGuardEvaluator.php:263`).
     *
     * A field outside this set is refused rather than ignored: which column a
     * guard locks on is decided by exactly `meta_key`/`ref`/`option_name_ref`/
     * `column` (`DeleteGuardEvaluator.php:427-429`), so an unrecognized field
     * could be a future selector rule, and silently dropping it would produce
     * a confident answer about the wrong column. The set is copied from that
     * resolver rather than narrowed to the fields this report READS, because
     * refusing `cast` — a shape the manifest grammar accepts
     * (`DeletionCapabilityResolver.php:123-127`) — would refuse a legitimate
     * proposal over a field that cannot change which index answers it.
     */
    private const GUARD_KEYS = [
        'cast', 'column', 'exclude_where', 'id_kind', 'identity_column', 'meta_key',
        'option_name_ref', 'reason', 'ref', 'source_id_kind', 'source_pk', 'table', 'table_absence', 'where',
    ];

    /**
     * @param array<string,mixed> $proposal `<selector> => {guards: [...]}` — the shape a
     *   manifest's `deletions` section has, minus `cascades`, which is refused
     * @return array<string,mixed> a `wprism-deletion-feasibility/v2` document
     */
    public static function report(array $proposal): array {
        global $wpdb;
        if (!is_object($wpdb) || !method_exists($wpdb, 'get_results')) {
            // There is no schema outside a loaded WordPress, and a report full
            // of nulls would be indistinguishable from a real unindexed one —
            // which is precisely the conclusion that must never be invented.
            throw new \RuntimeException('wprism: deletion feasibility needs a live target; $wpdb is unavailable');
        }
        if ($proposal === []) {
            throw self::refuse(
                'deletion feasibility needs at least one proposed deletion selector',
                'name at least one `<post|term|menu|table>:<type>` selector in --proposal, then rerun adapter-deletion-feasibility'
            );
        }
        if (count($proposal) > self::MAX_SELECTORS) {
            throw self::refuse(
                'deletion feasibility refuses more than ' . self::MAX_SELECTORS . ' selectors in one document',
                'split the proposal into documents of at most ' . self::MAX_SELECTORS
                    . ' selectors, then rerun adapter-deletion-feasibility'
            );
        }

        $selectors = [];
        foreach ($proposal as $selector => $body) {
            $selector = (string) $selector;
            if (preg_match(self::SELECTOR, $selector) !== 1) {
                // The selector is never echoed: it is the one string here that
                // nothing has vetted.
                throw self::refuse(
                    'deletion feasibility refused a selector outside the <post|term|menu|table>:<type> grammar',
                    'spell every key `<post|term|menu|table>:<type>`, exactly as Deletion::selector() does, '
                        . 'then rerun adapter-deletion-feasibility'
                );
            }
            $selectors[$selector] = [
                'guards' => self::guard_rows($selector, self::declared_guards($selector, $body)),
            ];
        }
        ksort($selectors, SORT_STRING);

        $document = [
            'authority' => false,
            'deferred' => self::deferred(),
            'format' => self::FORMAT,
            'redaction' => self::REDACTION,
            'selectors' => $selectors,
            'target' => [
                'agent_version' => self::agent_version(),
                'spec_version' => self::spec_version(),
            ],
        ];
        $document['feasibility_hash'] = self::hash_document($document);
        return $document;
    }

    /** Canonical self-hash, on the same terms as `AdapterProbe::hash_document()`. */
    public static function hash_document(array $document): string {
        unset($document['feasibility_hash']);
        return 'sha256:' . hash('sha256', Canon::encode($document));
    }

    /**
     * One selector's guard list, with the closed body key set enforced.
     *
     * `cascades` is refused BY NAME rather than dropped. It is the field that
     * carries destructive authority: a selector must declare one
     * (`DeletionCapabilityResolver.php:38-42`) and `Deletion::capability()`
     * refuses one that omits a required effect (`:55-60`). A document that
     * accepted it would be one paste away from looking like the capability
     * itself, with a hash over it, which is worse.
     *
     * @return list<array<string,mixed>>
     */
    private static function declared_guards(string $selector, mixed $body): array {
        if (!is_array($body)) {
            throw self::refuse(
                "deletion feasibility received a malformed proposal body for '$selector'",
                'give every selector an object of `{"guards": [...]}`, then rerun adapter-deletion-feasibility'
            );
        }
        foreach (array_keys($body) as $key) {
            if ($key === 'cascades') {
                throw self::refuse(
                    "deletion feasibility refuses the cascade set declared for '$selector': this report answers "
                        . 'guard lock feasibility only and owns no destructive authority',
                    'drop `cascades` from the proposal and declare it in the manifest a human ratifies, '
                        . 'then rerun adapter-deletion-feasibility'
                );
            }
            if ($key !== 'guards') {
                throw self::refuse(
                    'deletion feasibility received the proposal field ' . self::quoted_key($key)
                        . " for '$selector', which it does not model: this report reads `guards` only",
                    'give every selector exactly one key, `guards`, then rerun adapter-deletion-feasibility'
                );
            }
        }
        $guards = $body['guards'] ?? null;
        if (!is_array($guards) || !array_is_list($guards)) {
            throw self::refuse(
                "deletion feasibility needs a guard list for '$selector'",
                'spell `guards` as a JSON LIST of guard objects, exactly as a manifest `deletions` section does, '
                    . 'then rerun adapter-deletion-feasibility'
            );
        }
        if ($guards === []) {
            // A selector with no guards is a real proposal — core's
            // `menu:nav_menu` ships exactly that — and its answer is an empty
            // guard list, not a refusal. It is also the answer that says the
            // LOCK question was never the open one for this selector.
            return [];
        }
        if (count($guards) > self::MAX_GUARDS) {
            throw self::refuse(
                'deletion feasibility refuses more than ' . self::MAX_GUARDS . " guards for '$selector'",
                'a hand-authored guard list wider than ' . self::MAX_GUARDS
                    . ' is a different question; narrow it, then rerun adapter-deletion-feasibility'
            );
        }
        return array_values($guards);
    }

    /**
     * @param list<array<string,mixed>> $guards
     * @return list<array<string,mixed>> in the author's own order: the rows map
     *   1:1 onto the fragment being reviewed
     */
    private static function guard_rows(string $selector, array $guards): array {
        $rows = [];
        foreach ($guards as $position => $guard) {
            if (!is_array($guard)) {
                throw self::refuse(
                    'deletion feasibility received a malformed guard at position ' . (int) $position
                        . " of '$selector'",
                    'spell every guard as a JSON object of the manifest guard grammar\'s own fields ('
                        . implode(', ', self::GUARD_KEYS) . '), then rerun adapter-deletion-feasibility'
                );
            }
            foreach (array_keys($guard) as $key) {
                if (!in_array((string) $key, self::GUARD_KEYS, true)) {
                    // NAMING the offender is the whole remedy here. `note` is
                    // the obvious thing an author annotating a guard writes,
                    // `wp help wprism adapter-deletion-feasibility` documents
                    // `--proposal` only as "`<selector>: {"guards": [...]}` —
                    // minus `cascades`" and lists none of the 14 legal keys,
                    // and the sentence that would have explained it was being
                    // swallowed (see refuse() below). So the refusal names the
                    // key, its position, and the closed set it is missing from.
                    throw self::refuse(
                        'deletion feasibility received the guard field ' . self::quoted_key($key)
                            . ' at position ' . (int) $position . " of '$selector', which it does not model; "
                            . 'it would answer for the wrong column',
                        'use only the guard fields the manifest deletion grammar defines ('
                            . implode(', ', self::GUARD_KEYS) . '), then rerun adapter-deletion-feasibility'
                    );
                }
            }
            $rows[] = self::guard_row($guard);
        }
        return $rows;
    }

    /**
     * One guard's answer.
     *
     * The guard's own `reason` prose is deliberately NOT echoed: it explains
     * why the reference matters, this row's `reason` explains why the lock is
     * impossible, and two different meanings under one key in one document is
     * how a reviewer misreads it.
     *
     * @param array<string,mixed> $guard
     * @return array<string,mixed>
     */
    private static function guard_row(array $guard): array {
        global $wpdb;
        // These three names come out of the AUTHOR's proposal, not off the
        // server, so their refusal is one the author has to be able to read —
        // hence the typed form. The name itself is still never echoed.
        $table = (string) ($guard['table'] ?? '');
        self::assert_authored_identifier($table, 'table');
        $column = (string) ($guard['column'] ?? '');
        self::assert_authored_identifier($column, 'column');
        $lockColumn = self::lock_column($guard);
        self::assert_authored_identifier($lockColumn, 'column');
        $absenceMeansEmpty = ($guard['table_absence'] ?? null) === 'empty';

        $row = [
            'absence_means_empty' => $absenceMeansEmpty,
            'column' => $column,
            'index' => null,
            'leading' => [],
            'lock_column' => $lockColumn,
            'prefix' => null,
            'reason' => self::TABLE_ABSENT,
            'table' => $table,
            'table_present' => false,
        ];

        // The scanner probes existence before it asks any index question and
        // reports "required guard table 'X' is absent" as its own refusal
        // (`DeleteGuardReferenceScanner.php:41-43`); reporting that as an
        // index verdict would blame the wrong thing.
        //
        // `$wpdb->prefix`, not `base_prefix`, because that is the one the
        // scanner builds the guard table from (`:39`) — a guard on a
        // network-wide table would be resolved against the wrong table by BOTH
        // halves, and answering that question differently here would report a
        // lock boundary the deletion would never use.
        $prefixed = $wpdb->prefix . $table;
        $topology = DeleteGuardEvaluator::guard_table_topology(
            [$prefixed],
            'deletion feasibility guard table topology'
        );
        if (($topology[$prefixed] ?? null) === 'absent') {
            if ($absenceMeansEmpty) {
                $row['reason'] = null;
            }
            return $row;
        }
        $row['table_present'] = true;

        // The VERDICT is the engine's own return value, never a second
        // implementation of it (see the class comment).
        $verdict = DeleteGuardEvaluator::lock_index($guard, $prefixed);
        if ($verdict !== null) {
            self::assert_identifier($verdict, 'index');
        }

        $leading = self::leading_indexes($prefixed, $lockColumn);
        $row['leading'] = $leading;

        // `lock_index()` compares the DECLARED key length against `Sub_part`
        // only when the guard literally carries `meta_key`
        // (`DeleteGuardEvaluator.php:452-454`). A guard that resolves onto
        // `meta_key` through `ref` alone therefore has no key literal to
        // measure, and any prefix covers it — a shape the manifest grammar
        // refuses (`meta_key` and `ref` are paired,
        // `DeletionCapabilityResolver.php:56-62`) but a PROPOSAL can still
        // carry, which is exactly when an author needs the real rule rather
        // than the one the shipped manifests happen to exercise.
        // `strlen()` is bytes, matching the engine's own comparison.
        $declared = array_key_exists('meta_key', $guard) ? strlen((string) $guard['meta_key']) : null;

        $accepted = null;
        $widestRejected = null;
        foreach ($leading as $candidate) {
            $prefix = $candidate['prefix'];
            if ($prefix === null || $declared === null || $declared <= $prefix) {
                $accepted = $candidate;
                break;
            }
            $widestRejected = $widestRejected === null ? $prefix : max($widestRejected, $prefix);
        }

        if ($accepted !== null) {
            $row['index'] = $accepted['index'];
            $row['prefix'] = $accepted['prefix'];
            $row['reason'] = null;
        } elseif ($leading === []) {
            $row['reason'] = self::NO_LEADING_INDEX;
        } else {
            // The WIDEST rejected prefix is the closest miss: if the widest
            // leading index cannot cover the key, none of the narrower ones
            // can either, and naming a narrower one would understate what the
            // schema would have to grow.
            $row['reason'] = sprintf(
                'prefix index of %d bytes cannot cover a declared key of %d',
                (int) $widestRejected,
                (int) $declared
            );
        }

        self::assert_agrees($verdict, $row);
        return $row;
    }

    /**
     * The column `lock_index()` resolves the guard onto — copied from
     * `DeleteGuardEvaluator.php:427-429` because it is that function's rule,
     * not this one's. The copy is safe only because `assert_agrees()` catches
     * the moment the two stop matching.
     *
     * @param array<string,mixed> $guard
     */
    private static function lock_column(array $guard): string {
        return array_key_exists('meta_key', $guard) || array_key_exists('ref', $guard)
            ? 'meta_key'
            : (!empty($guard['option_name_ref']) ? 'option_name' : (string) ($guard['column'] ?? ''));
    }

    /**
     * Every index whose FIRST physical column is the lock column, in
     * `SHOW INDEX` order — the order `lock_index()` itself walks, so "the
     * first one that passes" means the same thing in both places.
     *
     * @return list<array{index:string,prefix:?int}>
     */
    private static function leading_indexes(string $prefixed, string $lockColumn): array {
        global $wpdb;
        $wpdb->last_error = '';
        $rows = $wpdb->get_results("SHOW INDEX FROM `$prefixed`", ARRAY_A);
        self::assert_read_ok('index inventory');
        if (!is_array($rows)) {
            throw new \RuntimeException('wprism: deletion feasibility could not read a guard table index inventory');
        }

        $indexes = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new \RuntimeException('wprism: deletion feasibility read a malformed index row');
            }
            $name = (string) ($row['Key_name'] ?? '');
            self::assert_identifier($name, 'index');
            $seq = (int) ($row['Seq_in_index'] ?? 0);
            if ($seq <= 0) {
                throw new \RuntimeException('wprism: deletion feasibility read a malformed index ordinal');
            }
            $part = (string) ($row['Column_name'] ?? '');
            self::assert_identifier($part, 'column');
            $indexes[$name][$seq] = [
                'column' => $part,
                'prefix' => isset($row['Sub_part']) && $row['Sub_part'] !== null ? (int) $row['Sub_part'] : null,
            ];
        }

        $leading = [];
        foreach ($indexes as $name => $parts) {
            ksort($parts, SORT_NUMERIC);
            $first = reset($parts);
            if (($first['column'] ?? '') !== $lockColumn) {
                continue;
            }
            $leading[] = ['index' => (string) $name, 'prefix' => $first['prefix']];
        }
        return $leading;
    }

    /**
     * The anti-drift check. A published reason must be the reason for the
     * published verdict, and the verdict belongs to `lock_index()`.
     *
     * @param array<string,mixed> $row
     */
    private static function assert_agrees(?string $verdict, array $row): void {
        $reported = $row['index'];
        if ($verdict === $reported && ($verdict !== null) === ($row['reason'] === null)) {
            return;
        }
        // Neither half is echoed: an index name is a server identifier, and
        // the useful fact is that the two readings disagreed at all.
        throw new \RuntimeException(
            'wprism: deletion feasibility refuses to publish an explanation that disagrees with '
                . "DeleteGuardEvaluator::lock_index()'s own verdict for a guard on '{$row['table']}'"
        );
    }

    private static function assert_identifier(string $value, string $kind): void {
        if (preg_match(self::IDENTIFIER, $value) !== 1) {
            throw new \RuntimeException(
                "wprism: deletion feasibility refused a $kind name outside the portable identifier grammar"
            );
        }
    }

    /**
     * The same grammar, for a name the AUTHOR wrote rather than one the server
     * returned. One regex, two refusal classes: a server identifier arriving
     * malformed is an operator-evidence event, while a malformed name in
     * `--proposal` is the author's own typo and has to reach the terminal.
     */
    private static function assert_authored_identifier(string $value, string $kind): void {
        if (preg_match(self::IDENTIFIER, $value) !== 1) {
            throw self::refuse(
                "deletion feasibility refused a $kind name outside the portable identifier grammar",
                "spell every guard $kind with the portable identifier characters [A-Za-z0-9_] the deletion "
                    . 'guard itself would use, then rerun adapter-deletion-feasibility'
            );
        }
    }

    /**
     * A key from the author's proposal, quoted for a refusal — or described
     * rather than echoed when it is outside the identifier grammar.
     *
     * The screen is the one this class already applies to a selector (`:161`):
     * a key nothing has vetted is the one string in a refusal that could carry
     * anything, and naming it is worth doing only when naming it is safe.
     */
    private static function quoted_key(mixed $key): string {
        $key = (string) $key;
        return preg_match(self::IDENTIFIER, $key) === 1
            ? "`$key`"
            : '(a key outside the portable identifier grammar, not echoed)';
    }

    /**
     * A refusal about the PROPOSAL — the document the author just wrote.
     *
     * Typed, rather than the bare `\RuntimeException` these all used to be,
     * because `Cli::halt_json_failure()` publishes a message only from a typed
     * refusal: every other Throwable is private operator evidence and comes
     * back as "adapter-deletion-feasibility refused at an unclassified safety
     * gate" with `details_redacted: true`
     * (`agent/src/Command/Cli.php:88-104`). That classification is correct —
     * an ordinary message can carry a path or a server identifier — and these
     * sentences are exactly the exception it describes: each one is about the
     * author's own input, every value they interpolate is vetted against
     * `SELECTOR`/`IDENTIFIER` first, and the author cannot act without them.
     *
     * Measured on a live WPForms Lite pair: a guard carrying a `note` key —
     * the obvious thing an author annotating a guard writes, and a key
     * `wp help wprism adapter-deletion-feasibility` never lists — returned the
     * unclassified envelope with remediation "inspect the proposed deletion
     * selectors and their guards", while the sentence that names the actual
     * problem ("…a guard field it does not model; it would answer for the
     * wrong column") was thrown here and dropped.
     *
     * `invalid_arguments` is this verb's own reason code for a `--proposal` it
     * cannot read (`Cli.php:3409`), so one command answers "your proposal is
     * wrong" with one code whether the file was unreadable or its contents
     * were. The operator message keeps the `wprism: ` prefix these refusals have
     * always carried, so the human/exception form is unchanged.
     */
    private static function refuse(string $publicMessage, string $remediation): CommandRefusalException {
        return new CommandRefusalException(
            'invalid_arguments',
            $publicMessage,
            $remediation,
            [],
            'wprism: ' . $publicMessage
        );
    }

    /**
     * `wpdb::get_results()` answers `[]` on a failed read, so an empty array
     * is not evidence of an unindexed table — and "unindexed" is exactly the
     * conclusion this report must never reach by inference.
     */
    private static function assert_read_ok(string $what): void {
        global $wpdb;
        if (trim((string) ($wpdb->last_error ?? '')) !== '') {
            throw new \RuntimeException("wprism: deletion feasibility could not read the $what; refusing to infer it");
        }
    }

    /** @return list<string> the closed limitations, stated in the document itself */
    private static function deferred(): array {
        return [
            'this report answers ONE question — whether the deletion guard\'s own SHOW INDEX walk finds a covering '
                . 'index for each guard a human proposed — and it answers it for guards a human wrote',
            'a covering index is a necessary condition and never a sufficient one: an indexed guard says nothing '
                . 'about whether removing this entity is right for a site, and nothing here decides that',
            'the verdict per guard is DeleteGuardEvaluator::lock_index()\'s own return value; the reason beside it '
                . 'explains that verdict, and a disagreement between the two is refused rather than published',
            'row values are never read: the only statements issued are the exact information_schema table census '
                . 'and SHOW INDEX, the same topology and index evidence the guard scanner uses before it locks',
            'a guard table this target does not have is answered rather than inferred: required absence remains '
                . 'a blocker, while declared table_absence=empty is feasible without inventing an index',
            'a null is a live fact about THIS target\'s schema today: a plugin that ships an index tomorrow changes '
                . 'the answer, and so does a site that added one by hand',
        ];
    }

    private static function agent_version(): string {
        return defined('WPRISM_AGENT_VERSION') ? (string) WPRISM_AGENT_VERSION : 'unknown';
    }

    private static function spec_version(): int {
        return defined('WPRISM_SPEC_VERSION') ? (int) WPRISM_SPEC_VERSION : 0;
    }
}
