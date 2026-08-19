<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

require_once dirname(__DIR__, 3) . '/agent/src/Kernel/CommandRefusal.php';
require_once __DIR__ . '/GapActions.php';

use Duo\CommandRefusalException;

/**
 * The human projection of `duo-assess-report/v1` — the five sections MUP
 * §2.1 prints, bounded per MUP §4.6 (round-3 MUP §2.1, §4.1, §4.6).
 *
 * The human view is a *projection of the same document* `--format=json`
 * emits, never a second computation: every word printed here is read out of
 * the report, and the only thing this class decides is layout and how much
 * of a long list to show. That is what makes `regress_assess_bounds.sh` a
 * real check rather than a check of a second renderer.
 *
 * ## The bound
 *
 * `DEFAULT_LIMIT` rows per section, `--limit=1..200` (the same closed
 * grammar `PlanView` already parses for `duo status`, deliberately spelled
 * the same way so an operator learns one rule), and a
 * `N more (use --format=json)` tail whenever a section was cut. No new
 * command may print an unbounded list, and the counts printed beside a
 * truncated list are always the true totals — a truncated *sample* is
 * honest, a truncated *count* is a lie about the site.
 *
 * ## Two things deliberately not printed
 *
 * **Values.** Names and counts only, exactly `Coverage`'s discipline: this
 * output is read over a shoulder and pasted into tickets.
 *
 * **Internal identifiers.** MUP §5.2's rule is that a human view prints an
 * internal identifier only when a documented command consumes it. Surface
 * ids qualify — they are the keys of the contract's `surface_labels` map
 * and the subjects of `duo contract <env> accept` — so they print. Adapter
 * digests, bundle digests, registry hashes and the assess digest do not:
 * they are in `--format=json`, which is named on every line that hides one.
 */
final class AssessRenderer {
    /** MUP §4.6: default rows per section. */
    public const DEFAULT_LIMIT = 50;

    /** MUP §4.6 / `PlanView::MAX_LIMIT`: the same closed ceiling. */
    public const MAX_LIMIT = 200;

    /** The columns of the per-surface table, in MUP §2.1's own order. */
    public const COLUMNS = [
        'surface' => 'surface',
        'class' => 'state_class',
        'handling' => 'handling',
        'readiness' => 'readiness',
        'certification' => 'certification_provenance',
        'containment' => 'effect_containment',
        'recovery' => 'effect_recovery_semantics',
    ];

    /**
     * Parse the one flag this renderer owns.
     *
     * The grammar is closed and the refusal is typed, because a mistyped
     * bound must not silently become the default: an operator who asked for
     * 20 rows and got 50 would read the tail line as "there are no more".
     *
     * @param list<string> $args
     */
    public static function limitFromArgs(array $args): int {
        $limit = self::DEFAULT_LIMIT;
        $seen = false;
        foreach ($args as $arg) {
            if (!is_string($arg) || !str_starts_with($arg, '--limit')) {
                continue;
            }
            if ($seen || !str_starts_with($arg, '--limit=')) {
                throw self::refuse();
            }
            $seen = true;
            $raw = substr($arg, strlen('--limit='));
            // Exactly `PlanView::parseLimit()`'s grammar: 1..200, decimal,
            // no leading zeros, no sign, no whitespace.
            if (preg_match('/^(?:[1-9]|[1-9][0-9]|1[0-9]{2}|200)$/D', $raw) !== 1) {
                throw self::refuse();
            }
            $limit = (int) $raw;
        }

        return $limit;
    }

    /**
     * Render the whole assessment.
     *
     * @param array<string,mixed> $report a `duo-assess-report/v1` document
     * @param array<string,mixed> $context `proposal_path` (the path written,
     *        relative to the site repository), `contract_present` (bool),
     *        `unpinned_subjects` (int) and `operation` (the operation whose
     *        projection the table's columns show)
     * @return list<string>
     */
    public static function render(array $report, int $limit, array $context = []): array {
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw self::refuse();
        }
        $lines = self::header($report, $context);
        $lines[] = '';
        foreach (self::surfaceTable($report, $limit, $context) as $line) {
            $lines[] = $line;
        }
        $lines[] = '';
        foreach (self::unknownSection($report, $limit) as $line) {
            $lines[] = $line;
        }
        $lines[] = '';
        foreach (self::gapSection($report) as $line) {
            $lines[] = $line;
        }
        foreach (self::evidenceSection($report, $context) as $line) {
            $lines[] = $line;
        }
        foreach (self::proposalSection($report, $context) as $line) {
            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * @param array<string,mixed> $report
     * @param array<string,mixed> $context
     * @return list<string>
     */
    private static function header(array $report, array $context): array {
        $target = is_array($report['target'] ?? null) ? $report['target'] : [];
        $authority = is_array($report['authority'] ?? null) ? $report['authority'] : [];
        $installed = is_array($authority['installed'] ?? null) ? $authority['installed'] : [];
        $database = is_array($target['database'] ?? null) ? $target['database'] : [];

        $lines = [];
        $lines[] = 'stack: WordPress ' . self::safe($target['wordpress'] ?? '?')
            . ' · PHP ' . self::safe($target['php'] ?? '?')
            . ' · ' . self::safe($database['engine'] ?? '?') . ' ' . self::safe($database['version'] ?? '?')
            . ' · ' . self::safe($target['site_mode'] ?? '?');
        // The driver id, not the driver's full self-description: that
        // string names both the WordPress root and the repository root, so
        // printing it beside `repo` would repeat one path and truncate the
        // line. The full description stays in --format=json.
        $lines[] = 'authority: ' . self::safe($authority['driver'] ?? '?')
            . ' · ' . self::safe($authority['access'] ?? '?')
            . ' · repo ' . self::safe($authority['repo_path'] ?? '?');
        $lines[] = 'installed: ' . (int) ($installed['plugins'] ?? 0) . ' plugin(s) ('
            . (int) ($installed['plugins_active'] ?? 0) . ' active), '
            . (int) ($installed['themes'] ?? 0) . ' theme(s) ('
            . (int) ($installed['themes_active'] ?? 0) . ' active), '
            . (int) ($installed['attachments'] ?? 0) . ' attachment(s)'
            . (($installed['media_bytes'] ?? null) === null ? ' · media storage not measured' : '');

        $doctor = is_array($authority['doctor'] ?? null) ? $authority['doctor'] : [];
        $failed = is_array($doctor['failed'] ?? null) ? $doctor['failed'] : [];
        $advisory = is_array($doctor['advisory'] ?? null) ? $doctor['advisory'] : [];
        $lines[] = 'checks: ' . (int) ($doctor['checks_passed'] ?? 0) . '/'
            . (int) ($doctor['checks_total'] ?? 0) . ' passed'
            . ($failed === [] ? '' : ' · failed: ' . implode(', ', array_map(self::safe(...), $failed)))
            . ($advisory === [] ? '' : ' · advisory: ' . implode(', ', array_map(self::safe(...), $advisory)));

        // An adoption seed is assessed as `duo init` would propose it, and the
        // human view says so on its own line — before any surface row, so
        // "Ready / Platform-certified" under it reads as a preview of adoption
        // rather than a repository in force. The adapters and the types left
        // local are named; the proposal's own advisories and unsupported rows
        // are counted (they are what `duo init` will print in full).
        $adoption = is_array($authority['adoption'] ?? null) ? $authority['adoption'] : null;
        if ($adoption !== null) {
            $adapters = is_array($adoption['adapters'] ?? null) ? $adoption['adapters'] : [];
            $scope = is_array($adoption['scope'] ?? null) ? $adoption['scope'] : [];
            $leftLocal = is_array($scope['left_local'] ?? null) ? $scope['left_local'] : [];
            $advisories = is_array($adoption['advisories'] ?? null) ? $adoption['advisories'] : [];
            $unsupported = is_array($adoption['unsupported'] ?? null) ? $adoption['unsupported'] : [];
            if (($adoption['preview'] ?? null) === 'init-proposal') {
                $lines[] = 'adoption: this repository is an adoption seed — assessed as duo init would propose it: '
                    . 'adapters ' . ($adapters === [] ? '(none)' : implode(', ', array_map(self::safe(...), $adapters)))
                    . ($leftLocal === [] ? '' : ' · left local: ' . implode(', ', array_map(self::safe(...), $leftLocal)))
                    . ' · init advisories: ' . count($advisories)
                    . ' · init would refuse: ' . count($unsupported)
                    . (($adoption['ready'] ?? false) === true ? ' · init is ready' : '');
            } else {
                $lines[] = 'adoption: this repository is an adoption seed, assessed against the seed itself — '
                    . 'the init proposal was unavailable (' . self::safe($adoption['reason'] ?? 'no reason') . ')';
            }
        }

        $operation = (string) ($context['operation'] ?? 'release');
        $lines[] = 'showing the ' . self::safe($operation)
            . ' projection; every operation is in --format=json';

        return $lines;
    }

    /**
     * @param array<string,mixed> $report
     * @param array<string,mixed> $context
     * @return list<string>
     */
    private static function surfaceTable(array $report, int $limit, array $context): array {
        /** @var list<array<string,mixed>> $rows */
        $rows = is_array($report['surfaces'] ?? null) ? $report['surfaces'] : [];
        if ($rows === []) {
            return ['surfaces: none reported'];
        }
        $operation = (string) ($context['operation'] ?? 'release');
        $shown = array_slice($rows, 0, $limit);

        $cells = [];
        foreach ($shown as $row) {
            $cells[] = self::cells($row, $operation);
        }
        $widths = [];
        foreach (array_keys(self::COLUMNS) as $index => $heading) {
            $widths[$index] = strlen($heading);
        }
        foreach ($cells as $line) {
            foreach ($line as $index => $value) {
                $widths[$index] = max($widths[$index], strlen($value));
            }
        }

        $lines = [rtrim(self::row(array_keys(self::COLUMNS), $widths))];
        foreach ($shown as $position => $row) {
            $lines[] = rtrim(self::row($cells[$position], $widths));
            foreach (self::detail($row, $operation) as $detail) {
                $lines[] = '  ' . $detail;
            }
        }
        $remaining = count($rows) - count($shown);
        if ($remaining > 0) {
            $lines[] = $remaining . ' more (use --format=json)';
        }

        return $lines;
    }

    /**
     * @param array<string,mixed> $row
     * @return list<string>
     */
    private static function cells(array $row, string $operation): array {
        $projection = self::projection($row, $operation);

        $values = [];
        foreach (self::COLUMNS as $key) {
            if ($key === 'surface') {
                $values[] = self::safe($row['label'] ?? $row['id'] ?? '?');
                continue;
            }
            if ($key === 'state_class' || $key === 'handling') {
                // The row-level word is the restrictive reduction across
                // every projected operation; the per-operation word lives
                // beside it in JSON. Printing the row-level one keeps the
                // table's summary and the report's own summary identical.
                $values[] = self::safe($row[$key] ?? '?');
                continue;
            }
            $values[] = $projection === null ? '—' : self::safe($projection[$key] ?? '?');
        }

        return $values;
    }

    /**
     * The indented lines under a surface row: its conditions, why it is
     * blocked, and what to do next. Bounded to the first two conditions —
     * a claim can carry one per platform axis and the rest are in JSON.
     *
     * @param array<string,mixed> $row
     * @return list<string>
     */
    private static function detail(array $row, string $operation): array {
        $projection = self::projection($row, $operation);
        $lines = [];
        if ($projection !== null) {
            $conditions = is_array($projection['conditions'] ?? null) ? $projection['conditions'] : [];
            foreach (array_slice($conditions, 0, 2) as $condition) {
                $lines[] = 'condition: ' . self::safe($condition);
            }
            if (count($conditions) > 2) {
                $lines[] = (count($conditions) - 2) . ' more condition(s) (use --format=json)';
            }
            if (is_string($projection['remediation'] ?? null) && $projection['remediation'] !== '') {
                $lines[] = 'reason: ' . self::safe($projection['remediation']);
            }
        }
        $lines[] = 'meaning: ' . self::safe($row['meaning'] ?? '');
        $next = (string) ($row['next_action'] ?? '');
        if ($next !== '' && $next !== 'nothing — supported') {
            // Name the operations that produced it. The columns show one
            // operation; the action reduces over all of them, and without
            // the attribution a next action beside a `Ready` row reads as a
            // contradiction rather than as a fact about `delete`.
            $detailed = GapActions::forSurfaceDetailed(
                is_array($row['operations'] ?? null) ? $row['operations'] : []
            );
            $lines[] = 'next action: ' . self::safe($next)
                . ($detailed['operations'] === []
                    ? ''
                    : ' (' . self::safe(implode(', ', $detailed['operations'])) . ')');
        }

        return $lines;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>|null
     */
    private static function projection(array $row, string $operation): ?array {
        $operations = is_array($row['operations'] ?? null) ? $row['operations'] : [];
        $projection = $operations[$operation] ?? null;

        return is_array($projection) ? $projection : null;
    }

    /**
     * @param array<string,mixed> $report
     * @return list<string>
     */
    private static function unknownSection(array $report, int $limit): array {
        $unknown = is_array($report['unknown'] ?? null) ? $report['unknown'] : [];
        $sample = is_array($unknown['names_sample'] ?? null) ? $unknown['names_sample'] : [];
        $invisible = (int) ($unknown['invisible_names_count'] ?? 0);
        $pending = (int) ($unknown['pending_count'] ?? 0);
        $environment = self::safe($report['env'] ?? '?');

        // T6 §3.7 item 2: the third finding MUP §2.1 item 3 always named and
        // this block never printed. On a site with an unmanaged plugin it is
        // usually the largest of the three, and its absence here was the
        // difference between an operator seeing "Duo cannot see this part of
        // your database" and seeing nothing.
        $tables = (int) ($unknown['undeclared_tables_count'] ?? 0);

        $lines = [];
        $lines[] = 'unknown: ' . $invisible . ' option name(s) invisible to every installed adapter';
        $lines[] = '         ' . $pending . ' pending classification(s) (duo pending ' . $environment . ')';
        $lines[] = '         ' . $tables . ' undeclared table(s) (no installed adapter declares them)';
        $shown = array_slice($sample, 0, $limit);
        foreach ($shown as $name) {
            $lines[] = '         - ' . self::safe($name);
        }
        $remaining = count($sample) - count($shown);
        if ($remaining > 0) {
            $lines[] = '         ' . $remaining . ' more (use --format=json)';
        }

        return $lines;
    }

    /**
     * @param array<string,mixed> $report
     * @return list<string>
     */
    private static function gapSection(array $report): array {
        /** @var list<array<string,mixed>> $rows */
        $rows = is_array($report['surfaces'] ?? null) ? $report['surfaces'] : [];
        $unknown = is_array($report['unknown'] ?? null) ? $report['unknown'] : [];
        $counts = GapActions::summarise($rows, [
            'pending' => (int) ($unknown['pending_count'] ?? 0),
            'invisible_option' => (int) ($unknown['invisible_names_count'] ?? 0),
            'undeclared_table' => self::uncountedTables($report),
        ]);

        $lines = ['next actions:'];
        foreach ($counts as $action => $count) {
            $lines[] = '  ' . str_pad((string) $count, 5, ' ', STR_PAD_LEFT) . '  ' . $action;
        }

        return $lines;
    }

    /**
     * Undeclared tables the surface table did NOT already account for.
     *
     * The three unknown-section kinds are not alike, and this is where that
     * matters. A pending classification and an invisible option name are
     * findings with no surface row — the inventory groups options by
     * declarant precisely so a row per option name cannot appear (MUP §4.6),
     * so the roll-up is the only place they can be counted. An undeclared
     * table is different: `SurfaceCatalog` mints a `table:<name>` ROW for
     * each one, and that row already contributes its own next action. Adding
     * the coverage total on top would count the same table twice and print a
     * number that is not true of the site — in a section whose entire
     * doctrine is that the count IS the signal.
     *
     * So what is passed is the residual: undeclared tables coverage found
     * that produced no row. That is normally zero, and it is not always: T6
     * §3.7 item 1 is the live bug where `Coverage::tables_report()` drops
     * `logical_name`, and a row without it cannot be turned into a surface
     * id. Those tables exist on the site and would otherwise be counted
     * nowhere at all, which is the failure this whole section exists to
     * prevent.
     *
     * @param array<string,mixed> $report
     */
    private static function uncountedTables(array $report): int {
        $total = (int) ($report['unknown']['undeclared_tables_count'] ?? 0);
        $rows = is_array($report['surfaces'] ?? null) ? $report['surfaces'] : [];
        $counted = 0;
        foreach ($rows as $row) {
            // `unclassified` is the discriminator, not the row's source: a
            // table a manifest declares carries a policy class and is never
            // unclassified, and a table no manifest declares can never carry
            // one. So this counts exactly the undeclared tables that became
            // rows, without the report having to publish where each row
            // came from.
            if (is_array($row) && ($row['kind'] ?? null) === 'table'
                && ($row['state_class'] ?? null) === 'unclassified'
            ) {
                $counted++;
            }
        }

        return max(0, $total - $counted);
    }

    /**
     * @param array<string,mixed> $report
     * @param array<string,mixed> $context
     * @return list<string>
     */
    private static function evidenceSection(array $report, array $context): array {
        $bundles = is_array($report['evidence']['bundles'] ?? null) ? $report['evidence']['bundles'] : [];
        $unpinned = (int) ($context['unpinned_subjects'] ?? 0);
        $lines = ['evidence: ' . count($bundles) . ' certification subject(s) pinned'];
        if ($unpinned > 0) {
            $lines[] = '          ' . $unpinned . ' subject(s) carry no bundle digest and cannot be pinned; '
                . 'their surfaces read Requalification required';
        }
        foreach (self::siteCertifiedPrincipals($report) as $line) {
            $lines[] = '          ' . $line;
        }

        return $lines;
    }

    /**
     * T6 §3.6's `certified by <principal> (<root> trust root); contract
     * attestation unsigned`, printed ONCE.
     *
     * Placement is the evidence section, not the surface rows, and that is
     * the whole decision. The sentence is a statement about who vouched for
     * this site's adapters and about what the CONTRACT is — one fact about
     * the assessment, not a per-surface fact. Repeating it under each of the
     * eleven surfaces a certified adapter governs would bury the second half,
     * which is the half that says the contract itself is still unsigned; the
     * evidence block is already where "what backs these claims" is answered,
     * and it is three lines long.
     *
     * One line per distinct (principal, trust root), sorted, because "once"
     * means once per statement and a site may legitimately trust two
     * organizations' keys. The per-surface `certification` column still says
     * `Site-certified` on every row it applies to, so nothing is hidden by
     * not repeating the sentence.
     *
     * @param array<string,mixed> $report
     * @return list<string>
     */
    private static function siteCertifiedPrincipals(array $report): array {
        $seen = [];
        foreach ((is_array($report['surfaces'] ?? null) ? $report['surfaces'] : []) as $row) {
            $operations = is_array($row['operations'] ?? null) ? $row['operations'] : [];
            foreach ($operations as $projection) {
                if (!is_array($projection)
                    || ($projection['certification_provenance'] ?? null) !== 'Site-certified') {
                    continue;
                }
                $principal = is_string($projection['certification_principal'] ?? null)
                    && $projection['certification_principal'] !== ''
                        ? $projection['certification_principal']
                        : 'an unnamed site authority';
                $root = is_string($projection['certification_trust_root'] ?? null)
                    && $projection['certification_trust_root'] !== ''
                        ? $projection['certification_trust_root']
                        : 'site';
                $seen[$principal . "\0" . $root] = 'certified by ' . self::safe($principal)
                    . ' (' . self::safe($root) . ' trust root); contract attestation unsigned';
            }
        }
        ksort($seen, SORT_STRING);

        return array_values($seen);
    }

    /**
     * @param array<string,mixed> $report
     * @param array<string,mixed> $context
     * @return list<string>
     */
    private static function proposalSection(array $report, array $context): array {
        $path = (string) ($context['proposal_path'] ?? '');
        if ($path === '') {
            return [];
        }
        $environment = self::safe($report['env'] ?? '?');
        $lines = ['proposed contract written: ' . self::safe($path)
            . ' (accept with duo contract ' . $environment . ' accept)'];
        if (($context['contract_present'] ?? false) === true) {
            $lines[] = 'projection regenerated from the accepted contract';
        }

        return $lines;
    }

    /**
     * @param list<string> $values
     * @param array<int,int> $widths
     */
    private static function row(array $values, array $widths): string {
        $out = '';
        foreach (array_values($values) as $index => $value) {
            $out .= str_pad($value, ($widths[$index] ?? strlen($value)) + 2);
        }

        return $out;
    }

    /**
     * A terminal-safe rendering of a string this process did not author.
     *
     * Surface ids, labels and registry condition sentences all originate on
     * a target or in a reviewed file, so a control byte or an escape
     * sequence in one of them would reach the operator's terminal
     * unmediated. Control bytes become `?` and the value is bounded; unlike
     * `AdapterSources::render_untrusted()` this does not quote or hex-dump,
     * because these values are table cells rather than diagnostics and the
     * table is unreadable with quotes around every word.
     *
     * @param mixed $value
     */
    private static function safe($value): string {
        if (!is_string($value)) {
            return is_scalar($value) ? (string) $value : '?';
        }
        $bounded = strlen($value) > 160 ? substr($value, 0, 160) . '…' : $value;

        return (string) preg_replace('/[\x00-\x1f\x7f]/', '?', $bounded);
    }

    private static function refuse(): CommandRefusalException {
        return new CommandRefusalException(
            'invalid_arguments',
            'assess accepts at most one canonical --limit=<1..200>',
            'supply a single --limit between 1 and 200, or omit it for the default of '
                . self::DEFAULT_LIMIT . ' rows per section'
        );
    }
}
