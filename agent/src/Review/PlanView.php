<?php
namespace Duo;

require_once __DIR__ . '/../Kernel/CommandRefusal.php';
require_once __DIR__ . '/PlanCategorySummary.php';
require_once __DIR__ . '/PlanExplanation.php';

/**
 * Bounded, observation-only projection for a requested large-plan view.
 *
 * Apply's detailed buckets remain the only mutation/readiness authority.  A
 * caller asks for this class explicitly through Cli::plan(); Apply::plan()
 * then attaches the resulting object beside, never instead of, the complete
 * plan envelope from the same observation.  In particular this class never
 * changes a bucket, a row order, or Apply::run().
 */
final class PlanView {
    public const FORMAT = 'duo-plan-view/v1';
    public const MAX_LIMIT = 200;

    private const REDACTION = 'values_omitted';
    private const ORDER = 'fixed_action_then_uuid_byte';

    /** @var list<string> */
    private const SAFETY_BUCKETS = ['drift', 'conflict', 'collision', 'delete_conflict'];

    /** @var list<string> */
    private const SAFETY_COUNT_KEYS = [
        'drift', 'conflict', 'collision', 'delete_conflict', 'blocked_delete',
    ];

    /** @var list<string> */
    private const GLOBAL_COUNT_KEYS = [
        'code_mismatch', 'code_drift', 'incomplete_apply', 'incomplete_lifecycle',
        'regen_pending', 'regen_context', 'env_missing', 'required_env_missing',
        'missing_user', 'skipped_user_meta', 'adapter_dispositions',
        'provider_problems', 'uploads_inventory', 'effects_inventory', 'warnings',
    ];

    /**
     * Parse the only four view flags accepted by direct `wp duo plan`.
     * WP-CLI passes values as an associative map, so duplicate complete flags
     * have already been normalized by its dispatcher; token duplicates are
     * intentionally harmless and canonicalized into closed-vocabulary order.
     *
     * @return array{category:list<string>,action:list<string>,entity:list<string>,limit:int}|null
     */
    public static function requestFromAssoc(array $assoc): ?array {
        $hasView = false;
        foreach (['category', 'action', 'entity', 'limit'] as $key) {
            $hasView = $hasView || array_key_exists($key, $assoc);
        }
        if (!$hasView) {
            return null;
        }

        return [
            'category' => array_key_exists('category', $assoc)
                ? self::parseCsv($assoc['category'], PlanCategorySummary::categoryIds()) : [],
            'action' => array_key_exists('action', $assoc)
                ? self::parseCsv($assoc['action'], PlanCategorySummary::actionBuckets()) : [],
            'entity' => array_key_exists('entity', $assoc)
                ? self::parseCsv($assoc['entity'], PlanCategorySummary::entityKinds()) : [],
            'limit' => array_key_exists('limit', $assoc)
                ? self::parseLimit($assoc['limit']) : self::MAX_LIMIT,
        ];
    }

    /**
     * Attach a plan-only view.  Invalid/missing compiled provenance is an
     * unavailable view rather than a guessed classification; the complete
     * detailed plan stays available to an unfiltered caller.
     *
     * @param array<string,mixed> $plan
     * @param array<string,array<string,mixed>> $tree
     * @param array<string,array<string,mixed>> $deletions
     * @param array{category:list<string>,action:list<string>,entity:list<string>,limit:int} $request
     * @return array<string,mixed>
     */
    public static function build(array $plan, array $tree, array $deletions, array $request): array {
        $request = self::normaliseRequest($request);
        $actions = PlanCategorySummary::actionBuckets();
        $actionRank = array_flip($actions);
        $seenUuids = [];
        $ordinary = [];
        $forcedSafety = [];
        $actionCounts = [];

        foreach ($actions as $bucket) {
            $rows = $plan[$bucket] ?? null;
            if (!is_array($rows) || !array_is_list($rows)) {
                throw self::unavailable();
            }
            $actionCounts[$bucket] = count($rows);
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    throw self::unavailable();
                }
                $uuid = $row['uuid'] ?? null;
                // UUID is the established plan-row identity name, but some
                // valid engine identities are synthetic (for example
                // options/core). Require an exact nonempty string, not an RFC
                // UUID spelling, and reject duplicates across ALL buckets.
                if (!is_string($uuid) || $uuid === '' || isset($seenUuids[$uuid])) {
                    throw self::unavailable();
                }
                $seenUuids[$uuid] = true;
                $entity = PlanCategorySummary::entityKindForPlanRow(
                    $row,
                    $tree,
                    $deletions,
                    $bucket
                );
                $categories = $entity === null
                    ? null
                    : PlanCategorySummary::categoriesForPlanRow($bucket, $entity);
                if ($entity === null || $categories === null || $categories === []) {
                    throw self::unavailable();
                }
                $safety = self::isSafety($bucket, $row);
                $entry = [
                    'bucket' => $bucket,
                    'selector' => PlanExplanation::selectorForOutput($bucket, $uuid),
                    'entity' => $entity,
                    'categories' => $categories,
                    'safety' => $safety,
                    // Never serialized; retained only while sorting this
                    // display copy. The public row above contains no identity.
                    '_uuid' => $uuid,
                ];
                if ($safety) {
                    $forcedSafety[] = $entry;
                } elseif (self::matches($entry, $request)) {
                    $ordinary[] = $entry;
                }
            }
        }

        $fullOrdinary = 0;
        foreach ($actions as $bucket) {
            foreach ($plan[$bucket] as $row) {
                if (is_array($row) && !self::isSafety($bucket, $row)) {
                    $fullOrdinary++;
                }
            }
        }
        $matching = count($ordinary);
        usort($ordinary, static function (array $a, array $b) use ($actionRank): int {
            $rank = $actionRank[$a['bucket']] <=> $actionRank[$b['bucket']];
            return $rank !== 0 ? $rank : strcmp($a['_uuid'], $b['_uuid']);
        });
        $shownOrdinary = array_slice($ordinary, 0, $request['limit']);
        $rows = array_merge($shownOrdinary, $forcedSafety);
        usort($rows, static function (array $a, array $b) use ($actionRank): int {
            $rank = $actionRank[$a['bucket']] <=> $actionRank[$b['bucket']];
            return $rank !== 0 ? $rank : strcmp($a['_uuid'], $b['_uuid']);
        });

        $publicRows = [];
        foreach ($rows as $entry) {
            unset($entry['_uuid']);
            $publicRows[] = $entry;
        }

        $safetyCounts = self::safetyCounts($plan);
        $globalCounts = self::globalCounts($plan);
        $ready = self::ready($actionCounts, $safetyCounts, $globalCounts);

        return [
            'format' => self::FORMAT,
            'authoritative' => false,
            'redaction' => self::REDACTION,
            'order' => self::ORDER,
            'filters' => $request,
            'counts' => [
                'full' => $fullOrdinary,
                'matching' => $matching,
                'shown' => count($shownOrdinary),
                'omitted' => $matching - count($shownOrdinary),
                'forced_safety' => count($forcedSafety),
            ],
            'full_plan' => [
                'readiness' => $ready ? 'ready' : 'blocked',
                'action_counts' => $actionCounts,
                'safety_counts' => $safetyCounts,
                'global_counts' => $globalCounts,
            ],
            'rows' => $publicRows,
        ];
    }

    /**
     * The banner is shared by the direct agent's detailed row renderer and
     * the host's filtered status renderer.  The direct agent intentionally
     * dereferences refs through its existing row loop so blocked reasons,
     * annotations, nested deletes, and conflict evidence stay intact.
     *
     * @param array<string,mixed> $view
     * @return list<string>
     */
    public static function humanHeaderLines(array $view): array {
        $filters = (array) ($view['filters'] ?? []);
        $counts = (array) ($view['counts'] ?? []);
        $fullPlan = (array) ($view['full_plan'] ?? []);
        return [
            'PLAN VIEW [' . self::FORMAT . '] authoritative=false order=' . self::ORDER,
            '  filters: category=' . self::filterLabel($filters['category'] ?? [])
                . ' action=' . self::filterLabel($filters['action'] ?? [])
                . ' entity=' . self::filterLabel($filters['entity'] ?? [])
                . ' limit=' . (int) ($filters['limit'] ?? self::MAX_LIMIT),
            '  ordinary: full=' . (int) ($counts['full'] ?? 0)
                . ' matching=' . (int) ($counts['matching'] ?? 0)
                . ' shown=' . (int) ($counts['shown'] ?? 0)
                . ' omitted=' . (int) ($counts['omitted'] ?? 0)
                . '; forced_safety=' . (int) ($counts['forced_safety'] ?? 0),
            '  full-plan readiness: ' . (string) ($fullPlan['readiness'] ?? 'blocked')
                . '; global diagnostics remain unfiltered',
        ];
    }

    /**
     * @param array<string,mixed> $plan
     * @param array<string,mixed> $view
     * @return list<array{bucket:string,row:array<string,mixed>,safety:bool}>
     */
    public static function referencedRows(array $plan, array $view): array {
        $out = [];
        foreach ((array) ($view['rows'] ?? []) as $reference) {
            if (!is_array($reference)) {
                continue;
            }
            $bucket = $reference['bucket'] ?? null;
            $selector = $reference['selector'] ?? null;
            if (!is_string($bucket) || !is_string($selector)
                || !is_array($plan[$bucket] ?? null)) {
                continue;
            }
            $matches = [];
            foreach ($plan[$bucket] as $candidate) {
                if (!is_array($candidate) || !is_string($candidate['uuid'] ?? null)) {
                    continue;
                }
                if (hash_equals($selector, PlanExplanation::selectorForOutput($bucket, $candidate['uuid']))) {
                    $matches[] = $candidate;
                }
            }
            if (count($matches) !== 1) {
                continue;
            }
            $row = $matches[0];
            $out[] = [
                'bucket' => $bucket,
                'row' => $row,
                'safety' => ($reference['safety'] ?? false) === true,
            ];
        }
        return $out;
    }

    /** @param mixed $raw @param list<string> $vocabulary @return list<string> */
    private static function parseCsv(mixed $raw, array $vocabulary): array {
        if (!is_string($raw) || $raw === '') {
            throw self::invalidRequest();
        }
        $selected = [];
        foreach (explode(',', $raw) as $token) {
            if ($token === '' || !in_array($token, $vocabulary, true)) {
                throw self::invalidRequest();
            }
            $selected[$token] = true;
        }
        return self::canonical($selected, $vocabulary);
    }

    private static function parseLimit(mixed $raw): int {
        if (!is_string($raw)
            || preg_match('/^(?:[1-9]|[1-9][0-9]|1[0-9]{2}|200)$/D', $raw) !== 1) {
            throw self::invalidRequest();
        }
        return (int) $raw;
    }

    /**
     * @param array{category:list<string>,action:list<string>,entity:list<string>,limit:int} $request
     * @return array{category:list<string>,action:list<string>,entity:list<string>,limit:int}
     */
    private static function normaliseRequest(array $request): array {
        if (array_keys($request) !== ['category', 'action', 'entity', 'limit']) {
            throw self::unavailable();
        }
        $out = [];
        foreach ([
            'category' => PlanCategorySummary::categoryIds(),
            'action' => PlanCategorySummary::actionBuckets(),
            'entity' => PlanCategorySummary::entityKinds(),
        ] as $key => $vocabulary) {
            $value = $request[$key] ?? null;
            if (!is_array($value) || !array_is_list($value)) {
                throw self::unavailable();
            }
            $selected = [];
            foreach ($value as $token) {
                if (!is_string($token) || !in_array($token, $vocabulary, true)) {
                    throw self::unavailable();
                }
                $selected[$token] = true;
            }
            $out[$key] = self::canonical($selected, $vocabulary);
            if ($out[$key] !== $value) {
                // Cli canonicalizes every request before Apply sees it. Do not
                // silently reinterpret a malformed internal caller here.
                throw self::unavailable();
            }
        }
        $limit = $request['limit'] ?? null;
        if (!is_int($limit) || $limit < 1 || $limit > self::MAX_LIMIT) {
            throw self::unavailable();
        }
        $out['limit'] = $limit;
        /** @var array{category:list<string>,action:list<string>,entity:list<string>,limit:int} $out */
        return $out;
    }

    /** @param array<string,bool> $selected @param list<string> $vocabulary @return list<string> */
    private static function canonical(array $selected, array $vocabulary): array {
        $out = [];
        foreach ($vocabulary as $token) {
            if (isset($selected[$token])) {
                $out[] = $token;
            }
        }
        return $out;
    }

    /** @param array<string,mixed> $entry @param array{category:list<string>,action:list<string>,entity:list<string>,limit:int} $request */
    private static function matches(array $entry, array $request): bool {
        if ($request['category'] !== []
            && array_intersect($request['category'], (array) $entry['categories']) === []) {
            return false;
        }
        if ($request['action'] !== [] && !in_array($entry['bucket'], $request['action'], true)) {
            return false;
        }
        return $request['entity'] === [] || in_array($entry['entity'], $request['entity'], true);
    }

    /** @param array<string,mixed> $row */
    private static function isSafety(string $bucket, array $row): bool {
        return in_array($bucket, self::SAFETY_BUCKETS, true)
            || ($bucket === 'delete' && isset($row['blocked']));
    }

    /** @param array<string,mixed> $plan @return array<string,int> */
    private static function safetyCounts(array $plan): array {
        $counts = [
            'drift' => self::listCount($plan, 'drift'),
            'conflict' => self::listCount($plan, 'conflict'),
            'collision' => self::listCount($plan, 'collision'),
            'delete_conflict' => self::listCount($plan, 'delete_conflict'),
            'blocked_delete' => 0,
        ];
        foreach (['delete', 'delete_conflict'] as $bucket) {
            $rows = $plan[$bucket] ?? null;
            if (!is_array($rows) || !array_is_list($rows)) {
                throw self::unavailable();
            }
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    throw self::unavailable();
                }
                if (isset($row['blocked'])) {
                    $counts['blocked_delete']++;
                }
            }
        }
        return $counts;
    }

    /** @param array<string,mixed> $plan @return array<string,int> */
    private static function globalCounts(array $plan): array {
        $counts = [];
        foreach (self::GLOBAL_COUNT_KEYS as $key) {
            if ($key === 'required_env_missing') {
                $env = $plan['env_missing'] ?? null;
                if (!is_array($env) || !array_is_list($env)) {
                    throw self::unavailable();
                }
                $count = 0;
                foreach ($env as $row) {
                    if (!is_array($row)) {
                        throw self::unavailable();
                    }
                    if (!empty($row['required'])) {
                        $count++;
                    }
                }
                $counts[$key] = $count;
                continue;
            }
            $counts[$key] = self::listCount($plan, $key);
        }
        return $counts;
    }

    /** @param array<string,mixed> $plan */
    private static function listCount(array $plan, string $key): int {
        $rows = $plan[$key] ?? null;
        if (!is_array($rows) || !array_is_list($rows)) {
            throw self::unavailable();
        }
        return count($rows);
    }

    /** @param array<string,int> $actions @param array<string,int> $safety @param array<string,int> $global */
    private static function ready(array $actions, array $safety, array $global): bool {
        return $safety['conflict'] === 0
            && $safety['delete_conflict'] === 0
            && $safety['collision'] === 0
            && $global['code_mismatch'] === 0
            && $global['code_drift'] === 0
            && $global['incomplete_apply'] === 0
            && $global['incomplete_lifecycle'] === 0
            && $global['regen_pending'] === 0
            && $global['regen_context'] === 0
            && $global['required_env_missing'] === 0
            && $global['missing_user'] === 0
            && $global['adapter_dispositions'] === 0
            && $safety['blocked_delete'] === 0
            && $safety['drift'] === 0;
    }

    /** @param mixed $value */
    private static function filterLabel(mixed $value): string {
        return is_array($value) && $value !== [] ? implode(',', $value) : 'all';
    }

    /**
     * One-line label for newly itemized filtered rows.  Keep it public so
     * Cli can retain its established detailed evidence renderer while using
     * the same C0/DEL hardening as the host's filtered status path.
     *
     * @param array<string,mixed> $row
     */
    public static function humanLabel(array $row): string {
        $label = is_string($row['path'] ?? null)
            ? (string) $row['path']
            : ((string) ($row['type'] ?? '?') . ' ' . (string) ($row['uuid'] ?? '?'));
        $label = self::oneLine($label);
        if ($label === '') {
            $label = '?';
        }
        if (is_string($row['title'] ?? null)) {
            $title = self::oneLine((string) $row['title']);
            if ($title !== '') {
                $label .= " '" . $title . "'";
            }
        }
        return $label;
    }

    private static function oneLine(string $value): string {
        $value = (string) preg_replace('/[\x00-\x1F\x7F]/', ' ', $value);
        $value = (string) preg_replace('/\s+/', ' ', $value);
        return trim($value);
    }

    private static function invalidRequest(): CommandRefusalException {
        return new CommandRefusalException(
            'invalid_arguments',
            'plan view filters are invalid',
            'use comma-separated closed category, action, or entity identifiers and a canonical limit from 1 through 200',
            [],
            'duo: invalid plan view filter'
        );
    }

    private static function unavailable(): CommandRefusalException {
        return new CommandRefusalException(
            'plan_view_unavailable',
            'the requested plan view is unavailable for this plan',
            'rerun the complete plan without view filters or repair the plan identity/provenance inconsistency before retrying',
            [],
            'duo: requested plan view unavailable'
        );
    }
}
