<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

require_once dirname(__DIR__, 3) . '/agent/src/Kernel/Canon.php';
require_once dirname(__DIR__, 3) . '/agent/src/Kernel/CommandRefusal.php';
require_once dirname(__DIR__) . '/Plan/PlanContract.php';
require_once dirname(__DIR__) . '/Plan/HumanViewLimit.php';
require_once dirname(__DIR__) . '/Contract/ProjectionVocabulary.php';
require_once __DIR__ . '/RehearsalDisclosure.php';

use Duo\Canon;
use Duo\CommandRefusalException;

/**
 * "What a release would touch" — the rehearsal preview (round-3 MUP §2.2,
 * §4.4, §4.6; product spec *Core product workflows → 2. Rehearse*, "expose
 * the exact code, authored state, generated state, and effects a release
 * would touch").
 *
 * Two documents meet here and neither is re-computed:
 *
 *  - the **plan** of the rehearsal environment after convergence, i.e. the
 *    `wp duo plan --format=json` envelope the target agent emitted, whose
 *    additive `category_summary` projection (`duo-plan-category-summary/v1`,
 *    `\Duo\PlanCategorySummary`) carries the closed category identifiers and
 *    non-negative counts a release plan will use;
 *  - the **assess-style projection rows** (`duo-assess-report/v1`'s
 *    `surfaces[]`, produced by the Contract/Assess modules), each carrying
 *    the six spec dimensions per operation.
 *
 * The preview is the join of the two, restricted to the plan's actual scope.
 *
 * ## Why the categories are copied, never derived
 *
 * `PlanView`'s own docblock states the rule this class obeys: *"It never
 * derives category/entity classification from a detailed row: only the agent
 * has the compiled tree/tombstone context to do that honestly."* A host that
 * counted `create`/`update` rows itself would turn an attachment into an
 * ordinary post the first time a path heuristic was wrong, and would then
 * disagree with the release plan it claims to be previewing. So the
 * categories in this document are the agent's own numbers, validated through
 * `PlanContract::categorySummaryViolations()` and copied verbatim. A plan
 * that carries no valid `category_summary` is a **refusal**
 * (`rehearsal_categories_unavailable`), not a preview with the classification
 * quietly filled in by the host: an operator reading "what a release would
 * touch" is entitled to the same numbers the release will use.
 *
 * ## Why scope restriction is at the KIND level, and why that is honest
 *
 * MUP §2.2 asks for "the surface rows from §2.1 restricted to the plan's
 * actual scope". The only value-free scope evidence the agent publishes is
 * the category summary's `contained_entities` facets — entity KINDS (post,
 * attachment, term, menu, sidebar, options, user_meta, typed_table) and code
 * kinds (plugin, theme, other) with counts. It does not publish which
 * *named* post type or table a row belongs to, and it must not: those are
 * values, and the detailed rows that carry them are exactly what the host is
 * forbidden to classify.
 *
 * So the restriction this class performs is: a surface row is in scope when
 * the plan touches its **kind**. That is a superset of the true scope — a
 * plan touching one post type puts every `post_type:` surface in scope — and
 * the document says so, on every row, in `in_scope_because`. A coarse bound
 * that names its own evidence is honest; a precise-looking bound the host
 * invented would not be. Narrowing it is a later change to the *agent's*
 * projection, not to this renderer.
 *
 * Three kinds have no entity facet and are decided differently, each stated:
 *
 *  - a surface annotated `ProjectionVocabulary::ANNOTATION_MANAGED` is the
 *    code lifecycle window (`active_plugins`, `template`, `stylesheet`), so
 *    it is in scope when the `code` or `lifecycle` categories are non-empty;
 *  - a surface whose state class is `environment-bound` is in scope when the
 *    `environment_state` category is non-empty — that category is where
 *    `env_missing` and the drift counters land;
 *  - `media:attachment` maps to the `attachment` entity kind, which is what
 *    the `media` category counts, so it needs no special case.
 *
 * ## Bounds and redaction (MUP §4.6)
 *
 * The machine document carries every in-scope row: it is bounded by
 * DECLARATIONS (one row per declared post type, taxonomy, table, option
 * group, …), the same bound `duo-assess-inventory/v1` already lives under,
 * not by the size of the site. The human projection bounds each section at
 * `DEFAULT_LIMIT` rows, accepts `--limit=1..200` with the closed grammar
 * `duo status` and `duo assess` already parse, and prints an
 * `N more (use --format=json)` tail whenever it cut one. Counts beside a
 * truncated list are always the true totals.
 *
 * No value is printed or stored: category metrics are counts, surface rows
 * are names and projected words. That is `PlanCategorySummary`'s own
 * `values_omitted` discipline, carried through unchanged.
 *
 * ## What this class is not
 *
 * It is mechanism, not a verb: no I/O, no clock, no environment, no
 * provider. `RehearseCommand` (cli/src/Command/) drives
 * `EnvironmentLifecycle`, fetches the plan and the projection rows, and
 * hands both in as data — which is what keeps `cli/src/Rehearse/` free of
 * edges to Environment, Assess, Release and Recovery (module map rule 9).
 */
final class RehearsalPlanPreview {
    public const FORMAT = 'duo-rehearsal-preview/v1';

    /** MUP §4.6: default rows per section. One source (DUO-3521). */
    public const DEFAULT_LIMIT = HumanViewLimit::DEFAULT_LIMIT;

    /** MUP §4.6: the same closed ceiling every human view publishes. */
    public const MAX_LIMIT = HumanViewLimit::MAX_LIMIT;

    /**
     * `duo-assess-inventory/v1`'s `policy.surface_groups[].kind` vocabulary
     * (`\Duo\AssessInventory::SURFACE_KINDS`) mapped onto the plan category
     * summary's `contained_entities` vocabulary
     * (`\Duo\PlanCategorySummary::entityKinds()`).
     *
     * Both are closed sets owned elsewhere, and the map between them is the
     * one piece of judgement this class contributes. `option_group` covers
     * the flat option sections the inventory groups by declarant and class;
     * `widget` maps to `sidebar` because a widget instance is stored as
     * sidebar state, which is the entity kind the compiled tree records.
     *
     * @var array<string,string>
     */
    public const SURFACE_KIND_ENTITY = [
        'post_type' => 'post',
        'media' => 'attachment',
        'taxonomy' => 'term',
        'table' => 'typed_table',
        'option_group' => 'options',
        'user_meta' => 'user_meta',
        'menu' => 'menu',
        'widget' => 'sidebar',
    ];

    /**
     * The product spec's four touch classes ("the exact code, authored
     * state, generated state, and effects a release would touch") mapped to
     * the category identifiers that carry each one.
     *
     * The counts under a touch class are reported per category and are
     * deliberately NOT summed: `PlanCategorySummary`'s facets overlap by
     * construction (an attachment deletion is media evidence and deletion
     * evidence), and its own docblock forbids consumers from adding category
     * totals. `summable: false` travels in the document for the same reason.
     *
     * @var array<string,list<string>>
     */
    public const TOUCH_CLASSES = [
        'code' => ['code', 'lifecycle'],
        'authored_state' => ['authored_state', 'media'],
        'generated_state' => ['generated_effects'],
        'effects' => ['generated_effects', 'environment_state', 'capabilities', 'deletions'],
    ];

    /** Human labels for the four touch classes, in the spec's own words. */
    public const TOUCH_LABELS = [
        'code' => 'code',
        'authored_state' => 'authored state',
        'generated_state' => 'generated state',
        'effects' => 'effects',
    ];

    /** The closed context keys `build()` accepts. */
    private const CONTEXT_KEYS = ['branch', 'env', 'generated_at', 'operation', 'source_env'];

    /**
     * Build the `duo-rehearsal-preview/v1` document.
     *
     * @param array<string,mixed> $plan the decoded `wp duo plan
     *        --format=json` envelope of the rehearsal environment AFTER
     *        convergence, carrying its additive `category_summary`
     * @param list<array<string,mixed>> $surfaces `duo-assess-report/v1`
     *        `surfaces[]` rows for the same environment
     * @param array<string,mixed> $context `env`, `source_env`, `branch`,
     *        `generated_at` (RFC3339 UTC) and optional `operation` (the
     *        projected operation whose words the preview shows; defaults to
     *        `release`, which is the operation a rehearsal previews)
     * @return array<string,mixed> canonical through `\Duo\Canon`
     */
    public static function build(array $plan, array $surfaces, array $context): array {
        $context = self::context($context);
        // The complete-envelope check first, and through the same trust
        // boundary branch convergence uses: a `{}` renders as a CLEAN plan
        // in every tolerant renderer (PlanContract's own docblock), so a
        // preview built from one would confidently report that a release
        // touches nothing.
        $violations = PlanContract::violations($plan);
        if ($violations !== []) {
            throw self::refuse(
                'rehearsal_plan_incomplete',
                'the rehearsal environment did not return a complete agent plan envelope',
                'rerun the rehearsal: convergence must produce a complete `wp duo plan --format=json` '
                    . 'document before a preview can say what a release would touch'
            );
        }
        $summary = $plan['category_summary'] ?? null;
        if (PlanContract::categorySummaryViolations($summary) !== []) {
            throw self::refuse(
                'rehearsal_categories_unavailable',
                'the rehearsal plan carries no valid category projection, so what a release would '
                    . 'touch cannot be stated from the agent\'s own numbers',
                'upgrade the target agent to one that emits duo-plan-category-summary/v1; the host '
                    . 'deliberately does not classify detailed plan rows itself'
            );
        }
        /** @var array<string,mixed> $summary */
        $categories = self::categories($summary);
        $entities = self::entitiesInScope($categories);

        $rows = [];
        $skipped = 0;
        foreach ($surfaces as $index => $surface) {
            if (!is_array($surface) || array_is_list($surface)) {
                throw self::refuse(
                    'rehearsal_preview_unbuildable',
                    "projection row $index is not an object",
                    'regenerate the projection rows with duo assess before rehearsing'
                );
            }
            $reasons = self::inScopeBecause($surface, $categories, $entities, $context['operation']);
            if ($reasons === []) {
                $skipped++;
                continue;
            }
            $rows[] = self::row($surface, $reasons, $context['operation']);
        }
        usort($rows, static fn (array $a, array $b): int => strcmp((string) $a['id'], (string) $b['id']));

        $preview = [
            'format' => self::FORMAT,
            'generated_at' => $context['generated_at'],
            'env' => $context['env'],
            'source_env' => $context['source_env'],
            'branch' => $context['branch'],
            'operation' => $context['operation'],
            'disclosure' => RehearsalDisclosure::block(),
            'categories' => $categories,
            'touch_classes' => self::touchClasses($categories),
            'scope' => [
                'entity_kinds' => $entities,
                'restriction' => 'kind',
                'summable' => false,
                'surfaces_in_scope' => count($rows),
                'surfaces_out_of_scope' => $skipped,
                'surfaces_total' => count($rows) + $skipped,
            ],
            'surfaces' => $rows,
            'preview_digest' => '',
        ];
        $preview['preview_digest'] = self::digest($preview);

        return $preview;
    }

    /**
     * The digest a preview binds itself with: sha256 over the canonical
     * encoding of everything except the digest field — exactly the rule
     * `assess_digest` and `contract_digest` follow, so one reader checks all
     * three the same way.
     *
     * @param array<string,mixed> $preview
     */
    public static function digest(array $preview): string {
        unset($preview['preview_digest']);

        return 'sha256:' . hash('sha256', Canon::encode($preview));
    }

    /** Canonical bytes, exactly as `--format=json` emits them. */
    public static function encode(array $preview): string {
        return Canon::encode($preview);
    }

    /**
     * Parse the one flag this renderer owns.
     *
     * The grammar is closed and the refusal is typed, for
     * `AssessRenderer::limitFromArgs()`'s reason: an operator who asked for
     * 20 rows and silently got 50 reads the missing tail line as "there are
     * no more rows".
     *
     * @param list<string> $args
     */
    public static function limitFromArgs(array $args): int {
        // One grammar (`HumanViewLimit`), this class's own refusal bytes.
        return HumanViewLimit::parse($args, static fn(): CommandRefusalException => self::limitRefusal());
    }

    /**
     * The bounded human projection of the same document.
     *
     * Every word printed here is read out of the preview; the only thing
     * this method decides is layout and how much of a long list to show.
     * That is what makes a bounds regression a real check rather than a
     * check of a second renderer.
     *
     * `$withDisclosure` — the report standing alone opens with MUP §2.2's
     * containment banner. `RehearseCommand::run()` has ALREADY printed that
     * banner at the top of its output, before the provider was invoked (the
     * spec wants it read before anything happens), so the command renders
     * its tail with `false`: the banner is printed once per page, first,
     * never twice (grind_mup.sh step 5 read it twice before this flag).
     *
     * @param array<string,mixed> $preview a `build()` result
     * @return list<string>
     */
    public static function render(
        array $preview,
        int $limit = self::DEFAULT_LIMIT,
        bool $withDisclosure = true
    ): array {
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw self::limitRefusal();
        }
        /** @var list<string> $lines */
        $lines = $withDisclosure ? RehearsalDisclosure::lines() : [];
        $lines[] = '';
        $lines[] = 'rehearsal: ' . self::safe($preview['env'] ?? '?')
            . ' from ' . self::safe($preview['source_env'] ?? '?')
            . ' at ' . self::safe($preview['branch'] ?? '?');
        $lines[] = 'what a release would touch (' . self::safe($preview['operation'] ?? 'release')
            . ' projection; facets overlap, so category counts are not summable):';

        /** @var array<string,mixed> $touch */
        $touch = is_array($preview['touch_classes'] ?? null) ? $preview['touch_classes'] : [];
        foreach ($touch as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $parts = [];
            foreach ((is_array($entry['counts'] ?? null) ? $entry['counts'] : []) as $id => $count) {
                $parts[] = self::safe((string) $id) . '=' . (int) $count;
            }
            $lines[] = '  ' . self::safe($entry['label'] ?? '?') . ': '
                . ($parts === [] ? 'none' : implode(', ', $parts));
        }

        $scope = is_array($preview['scope'] ?? null) ? $preview['scope'] : [];
        $total = (int) ($scope['surfaces_total'] ?? 0);
        $inScope = (int) ($scope['surfaces_in_scope'] ?? 0);
        $lines[] = '';
        $lines[] = 'surfaces in scope: ' . $inScope . ' of ' . $total
            . ' (restricted by entity kind — the only value-free scope evidence the plan carries)';

        /** @var list<array<string,mixed>> $rows */
        $rows = is_array($preview['surfaces'] ?? null) ? $preview['surfaces'] : [];
        $shown = array_slice($rows, 0, $limit);
        foreach ($shown as $row) {
            $lines[] = '  ' . self::safe($row['label'] ?? $row['id'] ?? '?')
                . '  ' . self::safe($row['state_class'] ?? '?')
                . '  ' . self::safe($row['handling'] ?? '?')
                . '  ' . self::safe($row['readiness'] ?? '?')
                . '  ' . self::safe($row['effect_containment'] ?? '?')
                . '  ' . self::safe($row['effect_recovery_semantics'] ?? '?');
            $because = is_array($row['in_scope_because'] ?? null) ? $row['in_scope_because'] : [];
            $lines[] = '    in scope because: '
                . self::safe(implode(', ', array_map(self::safe(...), $because)));
            if (($row['authorization_blocked'] ?? false) === true) {
                $lines[] = '    this rehearsal cannot authorize this capability (see the consequence above)';
            }
        }
        $remaining = count($rows) - count($shown);
        if ($remaining > 0) {
            $lines[] = '  ' . $remaining . ' more (use --format=json)';
        }

        return $lines;
    }

    /**
     * @param array<string,mixed> $context
     * @return array{branch:string,env:string,generated_at:string,operation:string,source_env:string}
     */
    private static function context(array $context): array {
        $unknown = array_diff(array_keys($context), self::CONTEXT_KEYS);
        if ($unknown !== []) {
            throw self::refuse(
                'rehearsal_preview_unbuildable',
                'the rehearsal preview context carries an unknown key',
                'the accepted keys are ' . implode(', ', self::CONTEXT_KEYS)
            );
        }
        $operation = $context['operation'] ?? 'release';
        if (!is_string($operation) || !in_array($operation, ProjectionVocabulary::OPERATIONS, true)) {
            throw self::refuse(
                'rehearsal_preview_unbuildable',
                'the rehearsal preview was asked for an operation this profile does not project',
                'request one of ' . implode(', ', ProjectionVocabulary::OPERATIONS)
            );
        }
        $out = ['operation' => $operation];
        foreach (['branch', 'env', 'generated_at', 'source_env'] as $key) {
            $value = $context[$key] ?? null;
            if (!is_string($value) || $value === '') {
                throw self::refuse(
                    'rehearsal_preview_unbuildable',
                    "the rehearsal preview context is missing '$key'",
                    'a preview names the environment it previewed, the production environment it was '
                        . 'taken from, the ref it materialized, and when it was generated'
                );
            }
            $out[$key] = $value;
        }

        return $out;
    }

    /**
     * The agent's own category rows, copied verbatim with their count lifted
     * to the top level so a reader (and the touch-class block) does not have
     * to reach into `metrics` for the one number every category has.
     *
     * @param array<string,mixed> $summary a validated category summary
     * @return list<array<string,mixed>>
     */
    private static function categories(array $summary): array {
        $out = [];
        /** @var list<array<string,mixed>> $rows */
        $rows = $summary['categories'];
        foreach ($rows as $category) {
            $metrics = is_array($category['metrics'] ?? null) ? $category['metrics'] : [];
            $out[] = [
                'id' => (string) $category['id'],
                'count' => (int) ($metrics['count'] ?? 0),
                'metrics' => $metrics,
                'entity_actions' => is_array($category['entity_actions'] ?? null) ? $category['entity_actions'] : [],
                'contained_entities' => is_array($category['contained_entities'] ?? null)
                    ? $category['contained_entities']
                    : [],
            ];
        }

        return $out;
    }

    /**
     * The entity kinds the plan actually touches, read out of every
     * category's `contained_entities` facet.
     *
     * A kind is in scope when ANY category counts it: the facets overlap, so
     * asking each one separately and unioning the answers is the only
     * reading that does not silently drop a kind that appears in `deletions`
     * but not in `authored_state`.
     *
     * @param list<array<string,mixed>> $categories
     * @return list<string>
     */
    private static function entitiesInScope(array $categories): array {
        $kinds = [];
        foreach ($categories as $category) {
            /** @var array<string,mixed> $contained */
            $contained = $category['contained_entities'];
            foreach ($contained as $kind => $count) {
                if (is_int($count) && $count > 0) {
                    $kinds[(string) $kind] = true;
                }
            }
        }
        $out = array_keys($kinds);
        sort($out, SORT_STRING);

        return $out;
    }

    /**
     * @param list<array<string,mixed>> $categories
     * @return list<array<string,mixed>>
     */
    private static function touchClasses(array $categories): array {
        $byId = [];
        foreach ($categories as $category) {
            $byId[(string) $category['id']] = (int) $category['count'];
        }
        $out = [];
        foreach (self::TOUCH_CLASSES as $id => $members) {
            $counts = [];
            foreach ($members as $member) {
                $counts[$member] = $byId[$member] ?? 0;
            }
            $out[] = [
                'id' => $id,
                'label' => self::TOUCH_LABELS[$id],
                'categories' => $members,
                'counts' => $counts,
            ];
        }

        return $out;
    }

    /**
     * Why this surface is in the plan's scope — or `[]` when it is not.
     *
     * Each reason is a stated piece of evidence rather than a verdict, so a
     * reader can check the claim against the same document's `categories`
     * block. That is the whole defence of a kind-level restriction: it says
     * exactly what it knows.
     *
     * @param array<string,mixed> $surface
     * @param list<array<string,mixed>> $categories
     * @param list<string> $entities
     * @return list<string>
     */
    private static function inScopeBecause(
        array $surface,
        array $categories,
        array $entities,
        string $operation
    ): array {
        $reasons = [];
        $kind = is_string($surface['kind'] ?? null) ? $surface['kind'] : '';
        $entity = self::SURFACE_KIND_ENTITY[$kind] ?? null;
        if ($entity !== null && in_array($entity, $entities, true)) {
            $reasons[] = "the plan touches the '$entity' entity kind";
        }
        $counts = [];
        foreach ($categories as $category) {
            $counts[(string) $category['id']] = (int) $category['count'];
        }
        $projection = self::projection($surface, $operation);
        $annotations = is_array($projection['annotations'] ?? null) ? $projection['annotations'] : [];
        if (in_array(ProjectionVocabulary::ANNOTATION_MANAGED, $annotations, true)) {
            foreach (['code', 'lifecycle'] as $id) {
                if (($counts[$id] ?? 0) > 0) {
                    $reasons[] = "the plan's '$id' category is non-empty and this surface is the code lifecycle window";
                }
            }
        }
        if (($projection['state_class'] ?? $surface['state_class'] ?? null) === 'environment-bound'
            && ($counts['environment_state'] ?? 0) > 0) {
            $reasons[] = "the plan's 'environment_state' category is non-empty and this surface is environment-bound";
        }

        return $reasons;
    }

    /**
     * @param array<string,mixed> $surface
     * @param list<string> $reasons
     * @return array<string,mixed>
     */
    private static function row(array $surface, array $reasons, string $operation): array {
        $projection = self::projection($surface, $operation);

        return [
            'id' => (string) ($surface['id'] ?? '?'),
            'label' => (string) ($surface['label'] ?? $surface['id'] ?? '?'),
            'kind' => (string) ($surface['kind'] ?? 'unknown'),
            'state_class' => (string) ($projection['state_class'] ?? $surface['state_class'] ?? '?'),
            'handling' => (string) ($projection['handling'] ?? $surface['handling'] ?? '?'),
            'readiness' => (string) ($projection['readiness'] ?? '?'),
            'certification_provenance' => (string) ($projection['certification_provenance'] ?? '?'),
            'effect_containment' => (string) ($projection['effect_containment'] ?? '?'),
            'effect_recovery_semantics' => (string) ($projection['effect_recovery_semantics'] ?? '?'),
            'meaning' => (string) ($surface['meaning'] ?? ($projection['meaning'] ?? '')),
            'authorization_blocked' => RehearsalDisclosure::blocksAuthorization($projection),
            'in_scope_because' => $reasons,
        ];
    }

    /**
     * @param array<string,mixed> $surface
     * @return array<string,mixed>
     */
    private static function projection(array $surface, string $operation): array {
        $operations = is_array($surface['operations'] ?? null) ? $surface['operations'] : [];
        $projection = $operations[$operation] ?? null;

        return is_array($projection) && !array_is_list($projection) ? $projection : [];
    }

    /**
     * A terminal-safe rendering of a string this process did not author.
     *
     * Surface ids, labels and registry condition sentences originate on a
     * target or in a reviewed file, so a control byte in one of them would
     * reach the operator's terminal unmediated. Same rule, same bound, as
     * `AssessRenderer::safe()`.
     */
    private static function safe(mixed $value): string {
        if (!is_string($value)) {
            return is_scalar($value) ? (string) $value : '?';
        }
        $bounded = strlen($value) > 160 ? substr($value, 0, 160) . '…' : $value;

        return (string) preg_replace('/[\x00-\x1f\x7f]/', '?', $bounded);
    }

    private static function limitRefusal(): CommandRefusalException {
        return new CommandRefusalException(
            'invalid_arguments',
            'rehearse accepts at most one canonical --limit=<1..200>',
            'supply a single --limit between 1 and 200, or omit it for the default of '
                . self::DEFAULT_LIMIT . ' rows per section'
        );
    }

    private static function refuse(string $code, string $message, string $remediation): CommandRefusalException {
        return new CommandRefusalException($code, $message, $remediation);
    }
}
