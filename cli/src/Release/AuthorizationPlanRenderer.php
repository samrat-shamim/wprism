<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

require_once dirname(__DIR__, 3) . '/agent/src/Kernel/CommandRefusal.php';
require_once __DIR__ . '/AuthorizationPlan.php';

use Duo\CommandRefusalException;

/**
 * The human rendering of `duo-authorization-plan/v1` — "a page of WordPress
 * language ending in a single question" (round-3 MUP §2.3.1, §4.3, §4.6).
 *
 * ## The order is the specification
 *
 * The six sections are the product spec's own six bullets under *Release and
 * verify*, in the order it lists them, because that order is an argument:
 * scope says what you asked for, capabilities say what it rests on, may-change
 * says what moves, the recovery profile says what comes back, the effects
 * section says what does not, and authority says what is still missing. A
 * renderer that regrouped them would be answering a different question.
 *
 * | # | section | spec bullet |
 * |---|---|---|
 * | 1 | `requested scope` | "the exact requested scope" |
 * | 2 | `capabilities and conditions` | "every capability and condition the release depends on" |
 * | 3 | `what may change` | "what code, authored state, runtime-adjacent state, and external systems may change" |
 * | 4 | `recovery` | "the selected recovery profile and what it does not restore" |
 * | 5 | `effects` | "known irreversible effects and any unknown effect that blocks release" |
 * | 6 | `authority still required` | "any authority still required" |
 *
 * ## A projection, never a second computation
 *
 * Every word printed is read out of the document `--format=json` emits. The
 * only decisions here are layout and how much of a long list to show, which
 * is what makes an offline bounds check a real check rather than a check of a
 * second implementation of the plan.
 *
 * ## The bound
 *
 * MUP §4.6: `DEFAULT_LIMIT` rows per section, `--limit=1..200`, and an
 * `N more (use --format=json)` tail whenever a section was cut. The grammar
 * is spelled exactly as `PlanView` and `AssessRenderer` spell it so an
 * operator learns one rule; it is re-implemented rather than shared because
 * `cli:Release` may not reference `cli:Assess` (module map rule 9), and a
 * duplicated seven-line parser is a smaller price than an upward module edge.
 */
final class AuthorizationPlanRenderer {
    /** MUP §4.6: default rows per section. */
    public const DEFAULT_LIMIT = 50;

    /** MUP §4.6 / `PlanView::MAX_LIMIT`: the same closed ceiling. */
    public const MAX_LIMIT = 200;

    /**
     * The section order, which is the product spec's own bullet order.
     *
     * @var list<string>
     */
    public const SECTIONS = [
        'requested scope',
        'capabilities and conditions',
        'what may change',
        'recovery',
        'effects',
        'authority still required',
    ];

    /**
     * Parse the one flag this renderer owns.
     *
     * Closed grammar, typed refusal: an operator who asked for 20 rows and
     * silently got 50 would read the tail line as "there are no more".
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
                throw self::refuseLimit();
            }
            $seen = true;
            if (preg_match('/^(?:[1-9]|[1-9][0-9]|1[0-9]{2}|200)$/D', substr($arg, strlen('--limit='))) !== 1) {
                throw self::refuseLimit();
            }
            $limit = (int) substr($arg, strlen('--limit='));
        }

        return $limit;
    }

    /**
     * Render the whole plan.
     *
     * @param array<string,mixed> $document a `duo-authorization-plan/v1`
     * @return list<string>
     */
    public static function render(array $document, int $limit = self::DEFAULT_LIMIT): array {
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw self::refuseLimit();
        }
        AuthorizationPlan::validate($document);

        $lines = self::header($document);
        foreach ([
            self::scopeSection($document, $limit),
            self::capabilitySection($document, $limit),
            self::mayChangeSection($document, $limit),
            self::recoverySection($document, $limit),
            self::effectsSection($document, $limit),
            self::authoritySection($document, $limit),
        ] as $section) {
            $lines[] = '';
            foreach ($section as $line) {
                $lines[] = $line;
            }
        }
        $lines[] = '';
        $lines[] = self::question($document);

        return $lines;
    }

    /**
     * The single question the page ends in.
     *
     * One question, not a checklist: the operator has just read six sections
     * and the confirmation must be about the whole of them.
     *
     * @param array<string,mixed> $document
     */
    public static function question(array $document): string {
        return 'Authorize this release to ' . (string) $document['environment'] . '?';
    }

    /**
     * @param array<string,mixed> $document
     * @return list<string>
     */
    private static function header(array $document): array {
        $digest = (string) $document['plan_digest'];
        $contract = $document['contract_digest'] ?? null;
        $lines = [
            'authorization plan for ' . (string) $document['environment']
                . ' — frozen ' . (string) $document['frozen_at'],
            '  plan ' . self::shortDigest($digest),
        ];
        $lines[] = '  application contract: '
            . (is_string($contract) && $contract !== ''
                ? self::shortDigest($contract)
                : 'none accepted for this site — readiness is projected from the registry alone');
        $revision = (string) ($document['code_revision_from'] ?? '');
        if ($revision !== '') {
            $lines[] = '  releasing code revision ' . $revision;
        }

        return $lines;
    }

    /**
     * @param array<string,mixed> $document
     * @return list<string>
     */
    private static function scopeSection(array $document, int $limit): array {
        /** @var array<string,mixed> $scope */
        $scope = $document['scope'];
        /** @var array<string,mixed> $entities */
        $entities = $scope['entities'];
        /** @var array<string,mixed> $code */
        $code = $scope['code'];
        $lines = ['requested scope'];
        foreach (self::bounded(self::strings($scope['surfaces'] ?? []), $limit) as $surface) {
            $lines[] = '  surface: ' . $surface;
        }
        if (self::strings($scope['surfaces'] ?? []) === []) {
            $lines[] = '  surface: none named';
        }
        $lines[] = '  entities: ' . (int) $entities['create'] . ' to create, '
            . (int) $entities['update'] . ' to update, ' . (int) $entities['delete'] . ' to delete';
        $lines[] = '  code: ' . (int) $code['plugins_changed'] . ' plugin(s), '
            . (int) $code['themes_changed'] . ' theme(s)';
        $phases = self::strings($code['lifecycle_phases'] ?? []);
        $lines[] = '  lifecycle phases: ' . ($phases === [] ? 'none' : implode(' → ', $phases));

        return $lines;
    }

    /**
     * @param array<string,mixed> $document
     * @return list<string>
     */
    private static function capabilitySection(array $document, int $limit): array {
        $rows = is_array($document['capabilities'] ?? null) ? array_values($document['capabilities']) : [];
        $lines = ['capabilities and conditions'];
        if ($rows === []) {
            $lines[] = '  none recorded for this release';

            return $lines;
        }
        foreach (array_slice($rows, 0, $limit) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $lines[] = '  ' . (string) ($row['name'] ?? '') . ' / ' . (string) ($row['operation'] ?? '')
                . ': ' . (string) ($row['readiness'] ?? '')
                . ' (' . (string) ($row['certification_provenance'] ?? '') . ')';
            $conditions = is_array($row['conditions'] ?? null) ? array_values($row['conditions']) : [];
            foreach (array_slice($conditions, 0, $limit) as $condition) {
                $lines[] = '    condition: ' . self::conditionLine($condition);
            }
            if (count($conditions) > $limit) {
                $lines[] = '    ' . (count($conditions) - $limit) . ' more (use --format=json)';
            }
        }
        if (count($rows) > $limit) {
            $lines[] = '  ' . (count($rows) - $limit) . ' more (use --format=json)';
        }

        return $lines;
    }

    /**
     * @param array<string,mixed> $document
     * @return list<string>
     */
    private static function mayChangeSection(array $document, int $limit): array {
        /** @var array<string,mixed> $may */
        $may = $document['may_change'];
        $lines = ['what may change'];
        foreach ([
            'code' => 'code',
            'authored_state' => 'authored state',
            'runtime_adjacent' => 'runtime-adjacent state',
        ] as $key => $label) {
            $rows = self::strings($may[$key] ?? []);
            $lines[] = '  ' . $label . ':' . ($rows === [] ? ' nothing' : '');
            foreach (self::bounded($rows, $limit) as $row) {
                $lines[] = '    ' . $row;
            }
        }
        $external = is_array($may['external'] ?? null) ? array_values($may['external']) : [];
        $lines[] = '  external systems:' . ($external === [] ? ' none declared for this scope' : '');
        foreach (array_slice($external, 0, $limit) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $lines[] = '    ' . (string) ($row['id'] ?? '') . ' — containment '
                . (string) ($row['containment'] ?? '') . ', recovery '
                . (string) ($row['effect_recovery_semantics'] ?? '');
        }
        if (count($external) > $limit) {
            $lines[] = '    ' . (count($external) - $limit) . ' more (use --format=json)';
        }

        return $lines;
    }

    /**
     * @param array<string,mixed> $document
     * @return list<string>
     */
    private static function recoverySection(array $document, int $limit): array {
        /** @var array<string,mixed> $recovery */
        $recovery = $document['recovery_profile'];
        /** @var array<string,mixed> $claim */
        $claim = $recovery['claim'];
        $lines = ['recovery'];
        $lines[] = '  selected: ' . (string) $recovery['selected'];
        $lines[] = '  because: ' . (string) $recovery['selected_because'];
        $lines[] = '  restores:' . (self::strings($claim['restores'] ?? []) === [] ? ' nothing' : '');
        foreach (self::bounded(self::strings($claim['restores'] ?? []), $limit) as $row) {
            $lines[] = '    ' . $row;
        }
        // The does-not-restore list is the honesty of the whole page, so it
        // is printed under the same bound as everything else but never
        // collapsed to a count: a truncated sample still names the first
        // rows and says how many were withheld.
        $lines[] = '  does NOT restore:';
        foreach (self::bounded(self::strings($claim['does_not_restore'] ?? []), $limit) as $row) {
            $lines[] = '    ' . $row;
        }
        $exclusion = is_array($claim['writer_exclusion'] ?? null) ? $claim['writer_exclusion'] : [];
        $lines[] = '  writer exclusion: '
            . (($exclusion['required'] ?? false) === true ? 'required — ' : 'held by Duo — ')
            . (string) ($exclusion['mechanism'] ?? '');
        $lines[] = '  maximum loss boundary: ' . (string) ($claim['maximum_loss_boundary'] ?? '');
        // The boundary sentence names the checkpoint without naming its
        // instant, because the claim is digested into `plan_digest` and a
        // clock value there makes the plan's identity change every second
        // (AuthorizationPlan::digest()). The instant is printed on the next
        // line instead — same page, same breath, outside the digest — and
        // `duo recover` prints the same pair before it acts.
        // The absent case is split rather than smoothed over: under the
        // `none` profile there is no checkpoint to date, and under any other
        // profile an absent instant is a plan that did not record one. Saying
        // "no checkpoint is taken" for the second case would be a claim about
        // the release that this document does not support.
        $checkpointAt = $recovery['checkpoint_at'] ?? null;
        if (is_string($checkpointAt) && $checkpointAt !== '') {
            $lines[] = '  checkpoint at: ' . $checkpointAt;
        } elseif ((string) $recovery['selected'] === 'none') {
            $lines[] = '  checkpoint at: none is taken under this profile';
        } else {
            $lines[] = '  checkpoint at: not recorded in this plan';
        }

        return $lines;
    }

    /**
     * @param array<string,mixed> $document
     * @return list<string>
     */
    private static function effectsSection(array $document, int $limit): array {
        /** @var array<string,mixed> $effects */
        $effects = $document['effects'];
        $lines = ['effects'];
        $lines[] = '  containment: ' . (string) $effects['containment']
            . ' — ' . (string) $effects['containment_basis'];
        $window = $effects['lifecycle_window'] ?? null;
        if (is_array($window)) {
            $lines[] = '  lifecycle window: live, declared in the contract ('
                . (string) ($window['declared_in'] ?? '') . ')';
            $lines[] = '    reviewed reason: ' . (string) ($window['reviewed_reason'] ?? '');
            $lines[] = '    recovery semantics: ' . (string) ($window['effect_recovery_semantics'] ?? '')
                . (is_string($window['restored_by'] ?? null)
                    ? ', restored by ' . (string) $window['restored_by']
                    : '');
        } else {
            $lines[] = '  lifecycle window: not entered by this release';
        }
        $buckets = [
            'known_irreversible' => 'known irreversible',
            'unknown_blocking' => 'unknown and blocking',
        ];
        foreach ($buckets as $key => $label) {
            $rows = is_array($effects[$key] ?? null) ? array_values($effects[$key]) : [];
            $lines[] = '  ' . $label . ':' . ($rows === [] ? ' none' : '');
            foreach (array_slice($rows, 0, $limit) as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $lines[] = '    ' . (string) ($row['surface'] ?? '') . ' — ' . (string) ($row['reason'] ?? '');
            }
            if (count($rows) > $limit) {
                $lines[] = '    ' . (count($rows) - $limit) . ' more (use --format=json)';
            }
        }

        return $lines;
    }

    /**
     * @param array<string,mixed> $document
     * @return list<string>
     */
    private static function authoritySection(array $document, int $limit): array {
        $rows = is_array($document['authority_still_required'] ?? null)
            ? array_values($document['authority_still_required'])
            : [];
        $lines = ['authority still required'];
        if ($rows === []) {
            $lines[] = '  none';

            return $lines;
        }
        foreach (array_slice($rows, 0, $limit) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $lines[] = '  ' . (string) ($row['kind'] ?? '') . ': ' . (string) ($row['reason'] ?? '');
        }
        if (count($rows) > $limit) {
            $lines[] = '  ' . (count($rows) - $limit) . ' more (use --format=json)';
        }

        return $lines;
    }

    /** @param mixed $condition */
    private static function conditionLine($condition): string {
        if (is_string($condition)) {
            return $condition;
        }
        if (!is_array($condition)) {
            return 'unreadable condition (use --format=json)';
        }
        $parts = [];
        foreach (['code', 'check', 'observed', 'rechecked_at'] as $key) {
            if (is_string($condition[$key] ?? null) && $condition[$key] !== '') {
                $parts[] = (string) $condition[$key];
            }
        }

        return $parts === [] ? 'unreadable condition (use --format=json)' : implode(' — ', $parts);
    }

    /**
     * MUP §5.2: a human view prints an internal identifier only when a
     * documented command consumes one. `duo verify --plan=<digest>` does, so
     * the digest prints — abbreviated, because the full 64 hex characters
     * are for the JSON view and the abbreviation still names the file under
     * `.duo/releases/`.
     */
    private static function shortDigest(string $digest): string {
        $hex = str_starts_with($digest, 'sha256:') ? substr($digest, 7) : $digest;

        return 'sha256:' . substr($hex, 0, 12) . '…';
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private static function strings($value): array {
        if (!is_array($value)) {
            return [];
        }
        $rows = [];
        foreach ($value as $row) {
            if (is_string($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @param list<string> $rows
     * @return list<string>
     */
    private static function bounded(array $rows, int $limit): array {
        if (count($rows) <= $limit) {
            return $rows;
        }
        $shown = array_slice($rows, 0, $limit);
        $shown[] = (count($rows) - $limit) . ' more (use --format=json)';

        return $shown;
    }

    private static function refuseLimit(): CommandRefusalException {
        return new CommandRefusalException(
            'invalid_arguments',
            '--limit must be given once as --limit=N with N between 1 and ' . self::MAX_LIMIT,
            'rerun with --limit=N in that range, or drop --limit for the default of ' . self::DEFAULT_LIMIT
        );
    }
}
