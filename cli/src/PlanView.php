<?php
namespace Duo\Orchestrator;

require_once __DIR__ . '/PlanContract.php';

/** A public, static refusal for host-side filtered status preflight. */
final class PlanViewException extends \RuntimeException {
    public function __construct(
        public string $reasonCode,
        public string $publicMessage,
        public string $remediation
    ) {
        parent::__construct($publicMessage);
    }
}

/**
 * Host twin of agent/src/PlanView.php.
 *
 * The two components deploy separately, so this file deliberately owns a
 * closed parser and validator instead of including the agent source.  It
 * never derives category/entity classification from a detailed row: only the
 * agent has the compiled tree/tombstone context to do that honestly.  The
 * host does prove every reference, selector, safety row, full-plan counter,
 * normalized request, and readiness bit against the one complete plan JSON
 * snapshot it already fetched for status.
 */
final class PlanView {
    public const FORMAT = 'duo-plan-view/v1';
    public const MAX_LIMIT = 200;

    private const REDACTION = 'values_omitted';
    private const ORDER = 'fixed_action_then_uuid_byte';

    /** @var list<string> */
    private const CATEGORIES = [
        'code', 'lifecycle', 'authored_state', 'generated_effects', 'media',
        'secrets', 'environment_state', 'capabilities', 'deletions',
    ];

    /** @var list<string> */
    private const ACTIONS = [
        'create', 'update', 'adopt', 'unchanged', 'drift', 'conflict',
        'collision', 'delete', 'delete_conflict', 'deleted',
    ];

    /** @var list<string> */
    private const ENTITIES = [
        'post', 'attachment', 'term', 'menu', 'sidebar', 'options',
        'user_meta', 'typed_table',
    ];

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
     * Parse the only status flags this host owns.  The direct `duo plan`
     * passthrough deliberately leaves parsing to the target agent, but status
     * must canonicalize before forwarding so it can verify the returned view.
     *
     * @param list<string> $args
     * @return array{category:list<string>,action:list<string>,entity:list<string>,limit:int}|null
     */
    public static function requestFromArgs(array $args): ?array {
        if ($args === []) {
            return null;
        }
        $values = [];
        foreach ($args as $arg) {
            if (!is_string($arg)) {
                throw self::invalidRequest();
            }
            $matched = false;
            foreach (['category', 'action', 'entity', 'limit'] as $key) {
                $prefix = '--' . $key . '=';
                if (str_starts_with($arg, $prefix)) {
                    if (array_key_exists($key, $values)) {
                        throw self::invalidRequest();
                    }
                    $values[$key] = substr($arg, strlen($prefix));
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                throw self::invalidRequest();
            }
        }
        return [
            'category' => array_key_exists('category', $values)
                ? self::parseCsv($values['category'], self::CATEGORIES) : [],
            'action' => array_key_exists('action', $values)
                ? self::parseCsv($values['action'], self::ACTIONS) : [],
            'entity' => array_key_exists('entity', $values)
                ? self::parseCsv($values['entity'], self::ENTITIES) : [],
            'limit' => array_key_exists('limit', $values)
                ? self::parseLimit($values['limit']) : self::MAX_LIMIT,
        ];
    }

    /**
     * @param array{category:list<string>,action:list<string>,entity:list<string>,limit:int} $request
     * @return list<string>
     */
    public static function agentArgs(array $request): array {
        $request = self::normaliseRequest($request);
        $args = [];
        foreach (['category', 'action', 'entity'] as $key) {
            if ($request[$key] !== []) {
                $args[] = '--' . $key . '=' . implode(',', $request[$key]);
            }
        }
        // Always forward the derived default too: both sides now bind the
        // exact normalized request rather than treating an omitted limit as a
        // legacy-agent-compatible ambiguity.
        $args[] = '--limit=' . $request['limit'];
        return $args;
    }

    /**
     * Name structural or semantic failures without echoing agent data. A
     * requested filtered status treats ANY failure as plan_view_unavailable;
     * callers deliberately do not print these internal labels.
     *
     * @param array<string,mixed> $plan
     * @param array{category:list<string>,action:list<string>,entity:list<string>,limit:int} $request
     * @return list<string>
     */
    public static function violations(array $plan, mixed $view, array $request): array {
        try {
            $request = self::normaliseRequest($request);
        } catch (PlanViewException) {
            return ['request is malformed'];
        }
        if (!is_array($view) || array_is_list($view)) {
            return ['view is not an object'];
        }
        $violations = [];
        $expectedTop = [
            'format', 'authoritative', 'redaction', 'order', 'filters',
            'counts', 'full_plan', 'rows',
        ];
        if (array_keys($view) !== $expectedTop) {
            $violations[] = 'view has unexpected or out-of-order top-level keys';
        }
        if (($view['format'] ?? null) !== self::FORMAT) {
            $violations[] = 'view format is invalid';
        }
        if (($view['authoritative'] ?? null) !== false) {
            $violations[] = 'view authority bit is invalid';
        }
        if (($view['redaction'] ?? null) !== self::REDACTION) {
            $violations[] = 'view redaction is invalid';
        }
        if (($view['order'] ?? null) !== self::ORDER) {
            $violations[] = 'view order is invalid';
        }
        if (($view['filters'] ?? null) !== $request) {
            $violations[] = 'view filters do not match the requested canonical filters';
        }
        if ($request['category'] !== []
            && !PlanContract::validCategorySummary($plan['category_summary'] ?? null)) {
            $violations[] = 'category-filtered view lacks a valid category summary';
        }
        $violations = array_merge(
            $violations,
            self::countMapViolations(
                $view['counts'] ?? null,
                ['full', 'matching', 'shown', 'omitted', 'forced_safety'],
                'view counts'
            )
        );

        $full = $view['full_plan'] ?? null;
        if (!is_array($full) || array_keys($full) !== [
            'readiness', 'action_counts', 'safety_counts', 'global_counts',
        ]) {
            $violations[] = 'view full-plan evidence is malformed';
        } else {
            if (!in_array($full['readiness'] ?? null, ['ready', 'blocked'], true)) {
                $violations[] = 'view readiness is invalid';
            }
            $violations = array_merge(
                $violations,
                self::countMapViolations($full['action_counts'] ?? null, self::ACTIONS, 'view action counts'),
                self::countMapViolations($full['safety_counts'] ?? null, self::SAFETY_COUNT_KEYS, 'view safety counts'),
                self::countMapViolations($full['global_counts'] ?? null, self::GLOBAL_COUNT_KEYS, 'view global counts')
            );
        }

        $evidence = self::fullEvidence($plan);
        if ($evidence === null) {
            return array_merge($violations, ['full plan cannot be correlated to a view']);
        }
        if (is_array($full)
            && array_keys($full) === ['readiness', 'action_counts', 'safety_counts', 'global_counts']) {
            if (($full['action_counts'] ?? null) !== $evidence['action_counts']) {
                $violations[] = 'view action counts disagree with the full plan';
            }
            if (($full['safety_counts'] ?? null) !== $evidence['safety_counts']) {
                $violations[] = 'view safety counts disagree with the full plan';
            }
            if (($full['global_counts'] ?? null) !== $evidence['global_counts']) {
                $violations[] = 'view global counts disagree with the full plan';
            }
            if (($full['readiness'] ?? null) !== ($evidence['ready'] ? 'ready' : 'blocked')) {
                $violations[] = 'view readiness disagrees with the full plan';
            }
        }

        $rows = $view['rows'] ?? null;
        if (!is_array($rows) || !array_is_list($rows)) {
            return array_merge($violations, ['view rows are not an ordered list']);
        }
        $seenRefs = [];
        $actualSafety = [];
        $actualOrdinary = [];
        $shown = 0;
        $previous = null;
        foreach ($rows as $row) {
            if (!is_array($row) || array_keys($row) !== [
                'bucket', 'selector', 'entity', 'categories', 'safety',
            ]) {
                $violations[] = 'view row has an invalid shape';
                continue;
            }
            $bucket = $row['bucket'] ?? null;
            $selector = $row['selector'] ?? null;
            if (!is_string($bucket) || !in_array($bucket, self::ACTIONS, true)
                || !is_string($selector)) {
                $violations[] = 'view row reference is invalid';
                continue;
            }
            $source = self::resolveSourceRow($plan, $bucket, $selector);
            if ($source === null) {
                $violations[] = 'view row does not resolve to a full-plan entity';
                continue;
            }
            $refKey = self::referenceKey($bucket, $selector);
            if (isset($seenRefs[$refKey])) {
                $violations[] = 'view row reference is duplicated';
                continue;
            }
            $seenRefs[$refKey] = true;
            if (!is_string($row['entity'] ?? null) || !in_array($row['entity'], self::ENTITIES, true)) {
                $violations[] = 'view row entity is outside the closed vocabulary';
            }
            if (!self::validCanonicalSubset($row['categories'] ?? null, self::CATEGORIES, false)) {
                $violations[] = 'view row categories are not a canonical closed subset';
            }
            if (!is_bool($row['safety'] ?? null)) {
                $violations[] = 'view row safety bit is invalid';
                continue;
            }
            $safety = self::isSafety($bucket, $source);
            if ($row['safety'] !== $safety) {
                $violations[] = 'view row safety bit disagrees with the full plan';
            }
            if ($safety) {
                $actualSafety[$refKey] = true;
            } else {
                $shown++;
                $actualOrdinary[] = $refKey;
                if (!self::matches($row, $request)) {
                    $violations[] = 'ordinary view row does not match requested filters';
                }
            }
            $orderKey = [array_search($bucket, self::ACTIONS, true), $source['uuid']];
            if ($previous !== null && self::compareOrder($previous, $orderKey) >= 0) {
                $violations[] = 'view rows are not in fixed action/UUID order';
            }
            $previous = $orderKey;
        }
        // Projection rows are action/UUID-sorted while the full plan retains
        // authoritative source order. Compare the forced-safety references as
        // sets, never incidental PHP insertion order.
        $expectedSafety = $evidence['safety_refs'];
        ksort($actualSafety, SORT_STRING);
        ksort($expectedSafety, SORT_STRING);
        if ($actualSafety !== $expectedSafety) {
            $violations[] = 'view omitted or invented a forced safety row';
        }
        $counts = $view['counts'] ?? [];
        if (is_array($counts)) {
            if (($counts['full'] ?? null) !== $evidence['full_ordinary']) {
                $violations[] = 'view ordinary full count disagrees with the full plan';
            }
            if (($counts['forced_safety'] ?? null) !== count($evidence['safety_refs'])) {
                $violations[] = 'view forced-safety count disagrees with the full plan';
            }
            if (($counts['shown'] ?? null) !== $shown || ($counts['shown'] ?? 0) > $request['limit']) {
                $violations[] = 'view shown count is invalid';
            }
            if (!is_int($counts['matching'] ?? null) || !is_int($counts['omitted'] ?? null)
                || $counts['matching'] < $shown
                || $counts['matching'] > $evidence['full_ordinary']
                || $counts['omitted'] !== $counts['matching'] - $shown
                || $shown !== min($counts['matching'], $request['limit'])) {
                $violations[] = 'view matching/omitted evidence is inconsistent';
            }
        }
        // Category/entity classification needs compiled tree/tombstone
        // context which only the agent owns. With neither predicate present,
        // however, the host can derive the exact action-filtered ordinary
        // prefix from the complete plan itself. Do so rather than accepting
        // an internally consistent but underinclusive agent page.
        $derivable = self::derivableOrdinaryReferences($plan, $request);
        if ($derivable !== null) {
            $expectedShown = array_slice($derivable, 0, $request['limit']);
            if ($actualOrdinary !== $expectedShown) {
                $violations[] = 'view omitted or invented an action-filtered ordinary row';
            }
            if (!is_array($counts)
                || ($counts['matching'] ?? null) !== count($derivable)
                || ($counts['shown'] ?? null) !== count($expectedShown)
                || ($counts['omitted'] ?? null) !== count($derivable) - count($expectedShown)) {
                $violations[] = 'view action-filtered ordinary counts disagree with the full plan';
            }
        }
        return $violations;
    }

    /**
     * @param array<string,mixed> $plan
     * @param array<string,mixed> $view
     * @return list<string>
     */
    public static function ordinaryHumanLines(array $plan, array $view): array {
        $lines = [];
        foreach ((array) ($view['rows'] ?? []) as $reference) {
            if (!is_array($reference) || ($reference['safety'] ?? null) !== false) {
                continue;
            }
            $bucket = $reference['bucket'] ?? null;
            $selector = $reference['selector'] ?? null;
            if (!is_string($bucket) || !is_string($selector)) {
                continue;
            }
            $row = self::resolveSourceRow($plan, $bucket, $selector);
            if ($row === null) {
                continue;
            }
            $lines[] = 'VIEW ' . strtoupper($bucket) . ' ' . self::humanLabel($row);
            if ($selector !== '') {
                $lines[] = '  EXPLAIN wp duo explain ' . $selector . ' --repo=<repo>';
            }
        }
        return $lines;
    }

    /** @param array<string,mixed> $view @return list<string> */
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

    /** @return list<string> */
    public static function categoryIds(): array {
        return self::CATEGORIES;
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
            throw self::invalidRequest();
        }
        $out = [];
        foreach (['category' => self::CATEGORIES, 'action' => self::ACTIONS, 'entity' => self::ENTITIES] as $key => $vocabulary) {
            $value = $request[$key] ?? null;
            if (!self::validCanonicalSubset($value, $vocabulary, true)) {
                throw self::invalidRequest();
            }
            $out[$key] = $value;
        }
        $limit = $request['limit'] ?? null;
        if (!is_int($limit) || $limit < 1 || $limit > self::MAX_LIMIT) {
            throw self::invalidRequest();
        }
        $out['limit'] = $limit;
        /** @var array{category:list<string>,action:list<string>,entity:list<string>,limit:int} $out */
        return $out;
    }

    /** @param mixed $value @param list<string> $vocabulary */
    private static function validCanonicalSubset(mixed $value, array $vocabulary, bool $allowEmpty): bool {
        if (!is_array($value) || !array_is_list($value) || (!$allowEmpty && $value === [])) {
            return false;
        }
        $selected = [];
        foreach ($value as $token) {
            if (!is_string($token) || !in_array($token, $vocabulary, true)) {
                return false;
            }
            $selected[$token] = true;
        }
        return self::canonical($selected, $vocabulary) === $value;
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

    /** @param array<string,mixed> $row @param array{category:list<string>,action:list<string>,entity:list<string>,limit:int} $request */
    private static function matches(array $row, array $request): bool {
        if ($request['category'] !== []
            && array_intersect($request['category'], (array) ($row['categories'] ?? [])) === []) {
            return false;
        }
        if ($request['action'] !== [] && !in_array($row['bucket'] ?? null, $request['action'], true)) {
            return false;
        }
        return $request['entity'] === [] || in_array($row['entity'] ?? null, $request['entity'], true);
    }

    /** @param array<string,mixed> $row */
    private static function isSafety(string $bucket, array $row): bool {
        return in_array($bucket, self::SAFETY_BUCKETS, true)
            || ($bucket === 'delete' && isset($row['blocked']));
    }

    /**
     * @param array<string,mixed> $plan
     * @return array{action_counts:array<string,int>,safety_counts:array<string,int>,global_counts:array<string,int>,ready:bool,full_ordinary:int,safety_refs:array<string,bool>}|null
     */
    private static function fullEvidence(array $plan): ?array {
        $actions = [];
        $safetyRefs = [];
        $seenUuids = [];
        $fullOrdinary = 0;
        foreach (self::ACTIONS as $bucket) {
            $rows = $plan[$bucket] ?? null;
            if (!is_array($rows) || !array_is_list($rows)) {
                return null;
            }
            $actions[$bucket] = count($rows);
            foreach ($rows as $row) {
                if (!is_array($row) || !is_string($row['uuid'] ?? null) || $row['uuid'] === '') {
                    return null;
                }
                $uuid = $row['uuid'];
                if (isset($seenUuids[$uuid])) {
                    return null;
                }
                $seenUuids[$uuid] = true;
                if (self::isSafety($bucket, $row)) {
                    $safetyRefs[self::referenceKey($bucket, self::selectorForUuid($bucket, $uuid))] = true;
                } else {
                    $fullOrdinary++;
                }
            }
        }
        $safety = [
            'drift' => $actions['drift'],
            'conflict' => $actions['conflict'],
            'collision' => $actions['collision'],
            'delete_conflict' => $actions['delete_conflict'],
            'blocked_delete' => 0,
        ];
        foreach (['delete', 'delete_conflict'] as $bucket) {
            foreach ($plan[$bucket] as $row) {
                if (!is_array($row)) {
                    return null;
                }
                if (isset($row['blocked'])) {
                    $safety['blocked_delete']++;
                }
            }
        }
        $global = [];
        foreach (self::GLOBAL_COUNT_KEYS as $key) {
            if ($key === 'required_env_missing') {
                $env = $plan['env_missing'] ?? null;
                if (!is_array($env) || !array_is_list($env)) {
                    return null;
                }
                $count = 0;
                foreach ($env as $row) {
                    if (!is_array($row)) {
                        return null;
                    }
                    if (!empty($row['required'])) {
                        $count++;
                    }
                }
                $global[$key] = $count;
                continue;
            }
            $rows = $plan[$key] ?? null;
            if (!is_array($rows) || !array_is_list($rows)) {
                return null;
            }
            $global[$key] = count($rows);
        }
        $ready = $safety['conflict'] === 0
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
        return [
            'action_counts' => $actions,
            'safety_counts' => $safety,
            'global_counts' => $global,
            'ready' => $ready,
            'full_ordinary' => $fullOrdinary,
            'safety_refs' => $safetyRefs,
        ];
    }

    /**
     * Resolve a value-free selector against the complete same-snapshot plan.
     * The public view deliberately has no source position: authoritative
     * bucket order may vary between otherwise equivalent observations. A
     * selector must bind exactly one unique UUID within its stated bucket.
     *
     * @param array<string,mixed> $plan
     * @return array<string,mixed>|null
     */
    private static function resolveSourceRow(array $plan, string $bucket, string $selector): ?array {
        $rows = $plan[$bucket] ?? null;
        if (!is_array($rows) || !array_is_list($rows)) {
            return null;
        }
        $matches = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !is_string($row['uuid'] ?? null) || $row['uuid'] === '') {
                return null;
            }
            if (hash_equals($selector, self::selectorForUuid($bucket, $row['uuid']))) {
                $matches[] = $row;
            }
        }
        return count($matches) === 1 ? $matches[0] : null;
    }

    private static function selectorForUuid(string $bucket, string $uuid): string {
        return $bucket . ':sha256:' . hash('sha256', $uuid);
    }

    private static function referenceKey(string $bucket, string $selector): string {
        return $bucket . "\0" . $selector;
    }

    /**
     * Exact host-side selection is possible when the request depends only on
     * action buckets and the cap. Entity/category facets require compiled
     * provenance and remain agent-owned.
     *
     * @param array<string,mixed> $plan
     * @param array{category:list<string>,action:list<string>,entity:list<string>,limit:int} $request
     * @return list<string>|null
     */
    private static function derivableOrdinaryReferences(array $plan, array $request): ?array {
        if ($request['category'] !== [] || $request['entity'] !== []) {
            return null;
        }
        $rank = array_flip(self::ACTIONS);
        $rows = [];
        foreach (self::ACTIONS as $bucket) {
            if ($request['action'] !== [] && !in_array($bucket, $request['action'], true)) {
                continue;
            }
            foreach ($plan[$bucket] as $row) {
                if (!is_array($row) || !is_string($row['uuid'] ?? null) || $row['uuid'] === '') {
                    return null;
                }
                if (self::isSafety($bucket, $row)) {
                    continue;
                }
                $selector = self::selectorForUuid($bucket, $row['uuid']);
                $rows[] = [
                    'rank' => $rank[$bucket],
                    'uuid' => $row['uuid'],
                    'reference' => self::referenceKey($bucket, $selector),
                ];
            }
        }
        usort($rows, static function (array $a, array $b): int {
            $bucket = $a['rank'] <=> $b['rank'];
            return $bucket !== 0 ? $bucket : strcmp($a['uuid'], $b['uuid']);
        });
        return array_column($rows, 'reference');
    }

    /** @param mixed $value @param list<string> $keys @return list<string> */
    private static function countMapViolations(mixed $value, array $keys, string $where): array {
        if (!is_array($value) || array_keys($value) !== $keys) {
            return ["$where is not a closed ordered count map"];
        }
        foreach ($keys as $key) {
            if (!is_int($value[$key]) || $value[$key] < 0) {
                return ["$where contains a non-count"];
            }
        }
        return [];
    }

    /** @param array{0:int,1:string} $a @param array{0:int,1:string} $b */
    private static function compareOrder(array $a, array $b): int {
        $rank = $a[0] <=> $b[0];
        return $rank !== 0 ? $rank : strcmp($a[1], $b[1]);
    }

    /** @param array<string,mixed> $row */
    private static function humanLabel(array $row): string {
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

    /** @param mixed $value */
    private static function filterLabel(mixed $value): string {
        return is_array($value) && $value !== [] ? implode(',', $value) : 'all';
    }

    private static function invalidRequest(): PlanViewException {
        return new PlanViewException(
            'invalid_arguments',
            'plan view filters are invalid',
            'use comma-separated closed category, action, or entity identifiers and a canonical limit from 1 through 200'
        );
    }
}
