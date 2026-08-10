<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

/**
 * Private byte-span projector for Refresh's redacted field-level contract.
 *
 * The public projection intentionally carries opaque selectors, closed engine
 * labels, change categories, and closed B/P/W presence/equality relations
 * only. It never contains a record id, path, arbitrary field name, value, or
 * per-value hash. The companion bundle is
 * process-local and contains the exact B/P/W byte spans required to
 * materialize an accepted choice. In particular, this class never turns a
 * decoded document back into JSON: branch bytes are the scaffold and every
 * replacement is copied verbatim from one verified source document.
 */
final class RefreshFieldDiff {
    public const DIFF_FORMAT = 'duo-refresh-field-diff/v1';
    public const RESOLUTION_FORMAT = 'duo-refresh-field-resolution/v1';
    public const POLICY_FORMAT = 'duo-refresh-field-policy/v1';
    /** Private, process-local only: never journaled or returned by Refresh. */
    public const PRESENTATION_FORMAT = 'duo-refresh-field-presentation/v1';
    public const ALGORITHM = 'duo-refresh-field-diff/v1';

    /**
     * The closed, understandable engine vocabulary. A group is one atomic
     * field decision: timestamps/status and the modification pair may never
     * be cross-composed into a state WordPress did not export.
     *
     * @var array<string,list<string>>
     */
    private const POST_GROUPS = [
        'post.author' => ['author'],
        'post.comment_status' => ['comment_status'],
        'post.excerpt' => ['excerpt'],
        'post.menu_order' => ['menu_order'],
        'post.parent' => ['parent'],
        'post.ping_status' => ['ping_status'],
        'post.publication' => ['status', 'date', 'date_gmt'],
        'post.title' => ['title'],
        'post.modification' => ['modified', 'modified_gmt'],
    ];

    /** @var array<string,list<string>> */
    private const TERM_GROUPS = [
        'term.description' => ['description'],
        'term.name' => ['name'],
        'term.parent' => ['parent'],
    ];

    /** @var list<string> */
    private const DERIVABLE_POST_FIELDS = ['title', 'modified', 'modified_gmt'];

    /** @var list<string> */
    private const PUBLIC_ENTITIES = [
        'post', 'term', 'attachment', 'menu', 'sidebar', 'options', 'user_meta', 'typed_table', 'record',
    ];

    /** @var list<string> */
    private const PUBLIC_REASONS = [
        'eligible_engine_fields', 'scoped_record', 'production_omitted', 'absence_or_tombstone',
        'option_or_state_witness', 'opaque_record_type', 'routing_changed', 'unsupported_document_shape',
        'attachment_media', 'document_structure_changed', 'opaque_or_structural_field',
        'derived_field_policy', 'opaque_container', 'body_changed',
    ];

    /** @var list<string> */
    private const CHANGE_CATEGORIES = [
        'unchanged', 'production-only', 'branch-only', 'compatible', 'conflicting',
    ];

    /** @var list<string> */
    private const ROLE_STATES = ['present', 'tombstone', 'absent'];

    /** @var list<string> Canonical, closed key order for value-free role evidence. */
    private const RELATION_KEYS = [
        'base', 'branch', 'branch_vs_base', 'branch_vs_production', 'production', 'production_vs_base',
    ];

    /**
     * Build a value-free public diff and a private, immutable-in-memory span
     * bundle. `$policyProjections` is keyed by base, production, branch and
     * contains small hash-bound policy projections made by the Git compiler.
     * Missing evidence never defaults a post field to authored.
     *
     * @return array{diff:array<string,mixed>,bundle:array<string,mixed>}
     */
    public static function project(array $plan, array $policyProjections = []): array {
        $planHash = (string) ($plan['plan_hash'] ?? '');
        if (!self::isHash($planHash) || !is_array($plan['entries'] ?? null)) {
            throw new \RuntimeException('field diff requires a normalized refresh plan');
        }
        if (is_array($plan['context'] ?? null) && array_key_exists('scope_contract', $plan['context'])) {
            throw new \RuntimeException('field-level resolution is unavailable for scoped refresh plans');
        }
        $snapshotHash = $plan['context']['production_snapshot_hash'] ?? null;
        if (!self::isHash($snapshotHash)) {
            throw new \RuntimeException('field-level resolution requires an exact production snapshot hash');
        }
        $policy = self::normalizePolicyProjections($policyProjections);
        if (count($policy) !== 3) {
            throw new \RuntimeException('field-level resolution requires complete policy evidence');
        }
        if (!hash_equals((string) $policy['branch']['projection_hash'], (string) $policy['production']['projection_hash'])) {
            throw new \RuntimeException('field-level resolution requires matching branch and production policy evidence');
        }
        $publicRecords = [];
        $privateRecords = [];

        foreach ($plan['entries'] as $index => $entry) {
            if (!is_array($entry) || ($entry['category'] ?? null) !== 'conflicting') {
                continue;
            }
            $versions = is_array($entry['versions'] ?? null) ? $entry['versions'] : [];
            $base = is_array($versions['base'] ?? null) ? $versions['base'] : null;
            $production = is_array($versions['production'] ?? null) ? $versions['production'] : null;
            $branch = is_array($versions['branch'] ?? null) ? $versions['branch'] : null;
            // A missing live side is not a deletion request. Do not publish a
            // field-mode prompt that could select destructive absence without
            // the ordinary whole-record deletion authority.
            if (self::unsafeAbsence($base, $production) || self::unsafeAbsence($base, $branch)) {
                throw new \RuntimeException('field-level resolution is unavailable for unsafe live-record absence');
            }
            if (($entry['in_scope'] ?? true) !== true) {
                continue;
            }
            $recordSelector = self::recordSelector((string) ($entry['id'] ?? ''));
            $projected = self::projectEntry($entry, (int) $index, $recordSelector, $policy);
            $publicRecords[] = $projected['public'];
            $privateRecords[$recordSelector] = $projected['private'];
        }
        usort($publicRecords, static fn(array $a, array $b): int =>
            (string) $a['record_selector_sha256'] <=> (string) $b['record_selector_sha256']);

        $required = 0;
        $fieldCount = 0;
        $atomic = 0;
        foreach ($publicRecords as $record) {
            if (($record['mode'] ?? null) === 'record') {
                $atomic++;
            }
            foreach ((array) ($record['changes'] ?? []) as $change) {
                $fieldCount++;
                if (($change['category'] ?? null) === 'conflicting') {
                    $required++;
                }
            }
        }
        $diff = [
            'algorithm' => self::ALGORITHM,
            'authority' => false,
            'choices' => ['ours' => 'branch', 'theirs' => 'production'],
            'format' => self::DIFF_FORMAT,
            'plan_hash' => $planHash,
            'policy_projection_hashes' => [
                'base' => $policy['base']['projection_hash'] ?? null,
                'branch' => $policy['branch']['projection_hash'] ?? null,
                'production' => $policy['production']['projection_hash'] ?? null,
            ],
            'production_snapshot_hash' => (string) $snapshotHash,
            'redaction' => 'values_omitted',
            'records' => $publicRecords,
            'roles' => ['base' => 'merge_base', 'ours' => 'branch', 'theirs' => 'production'],
            'summary' => [
                'atomic_records' => $atomic,
                'changes' => $fieldCount,
                'conflicting_choices' => $required,
                'records' => count($publicRecords),
            ],
        ];
        $diff['diff_hash'] = self::hashDocument($diff, 'diff_hash');
        self::assertDiff($diff);
        return [
            'diff' => $diff,
            'bundle' => [
                'algorithm' => self::ALGORITHM,
                'diff' => $diff,
                'diff_hash' => $diff['diff_hash'],
                'plan_hash' => $planHash,
                'policy' => $policy,
                'records' => $privateRecords,
            ],
        ];
    }

    /** Validate the closed public projection before it reaches a journal or TTY. */
    public static function validateDiff(array $diff): array {
        self::assertDiff($diff);
        return $diff;
    }

    /**
     * Create a canonical value-free resolution from raw choice rows. This is
     * useful to the TTY adapter and offline tests; callers normally use
     * readResolutionFile() followed by normalizeResolution().
     *
     * @param list<array<string,mixed>> $choices
     * @return array<string,mixed>
     */
    public static function resolution(array $diff, array $choices): array {
        $raw = [
            'algorithm' => self::ALGORITHM,
            'choices' => $choices,
            'diff_hash' => $diff['diff_hash'] ?? null,
            'format' => self::RESOLUTION_FORMAT,
            'plan_hash' => $diff['plan_hash'] ?? null,
            'production_snapshot_hash' => $diff['production_snapshot_hash'] ?? null,
        ];
        // The public normalizer and materializer accept only the final,
        // immutable contract.  This helper is the one internal place allowed
        // to construct the pre-hash basis for a TTY choice list.
        $raw['resolution_hash'] = self::hashDocument($raw, 'resolution_hash');
        return self::normalizeResolution($raw, $diff);
    }

    /**
     * Build the one deliberately local reveal surface used by CLI
     * `rebase --interactive`. This is derived from the exact private plan and
     * private span bundle, remains in memory, and is never part of a public
     * diff, resolution, journal, receipt, or Refresh result.
     *
     * The only free text is an authored title/name or path fallback after
     * bounded terminal-safe sanitization. All selectors, entities, relations,
     * categories, and automatic outcomes are closed/recomputed here.
     *
     * @return array<string,mixed>
     */
    public static function interactivePresentation(array $plan, array $diff, array $bundle): array {
        self::assertDiff($diff);
        if (!self::isHash($plan['plan_hash'] ?? null)
            || !hash_equals((string) $plan['plan_hash'], (string) $diff['plan_hash'])
            || !hash_equals((string) $plan['plan_hash'], self::hashDocument($plan, 'plan_hash'))
            || !is_array($plan['entries'] ?? null)
            || (is_array($plan['context'] ?? null) && array_key_exists('scope_contract', $plan['context']))) {
            throw new \RuntimeException('field interactive presentation does not bind this refresh plan');
        }
        // This re-projects the actual conflicting B/P/W rows under the exact
        // private policy/span bundle. A rehashed public diff cannot invent an
        // entity, reason, relation, or field for local display.
        self::assertBundleMatchesDiff($plan, $bundle, $diff);

        $public = [];
        foreach ($diff['records'] as $record) {
            $public[(string) $record['record_selector_sha256']] = $record;
        }
        $labels = [];
        $auto = [];
        $seen = [];
        foreach ($plan['entries'] as $entry) {
            if (!is_array($entry) || !is_string($entry['id'] ?? null) || $entry['id'] === ''
                || ($entry['in_scope'] ?? true) !== true) {
                throw new \RuntimeException('field interactive presentation has an invalid refresh plan entry');
            }
            $category = $entry['category'] ?? null;
            if (!in_array($category, self::CHANGE_CATEGORIES, true)) {
                throw new \RuntimeException('field interactive presentation has an invalid refresh plan entry');
            }
            $selector = self::recordSelector($entry['id']);
            if (isset($seen[$selector])) {
                throw new \RuntimeException('field interactive presentation has duplicate record selectors');
            }
            $seen[$selector] = true;
            $versions = is_array($entry['versions'] ?? null) ? $entry['versions'] : [];
            $base = is_array($versions['base'] ?? null) ? $versions['base'] : null;
            $production = is_array($versions['production'] ?? null) ? $versions['production'] : null;
            $branch = is_array($versions['branch'] ?? null) ? $versions['branch'] : null;
            if ($category === 'unchanged') {
                if (isset($public[$selector])) {
                    throw new \RuntimeException('field interactive presentation has an unexpected unchanged record');
                }
                continue;
            }
            $labels[$selector] = self::localRecordLabel($entry, $base, $production, $branch);
            if ($category === 'conflicting') {
                if (!isset($public[$selector])) {
                    throw new \RuntimeException('field interactive presentation is missing a conflicting record');
                }
                if (($entry['selected_source'] ?? null) !== null || ($entry['selected'] ?? null) !== null) {
                    throw new \RuntimeException('field interactive presentation has a pre-resolved conflict');
                }
                if (($public[$selector]['entity'] ?? null) !== self::publicEntity($entry, $base, $production, $branch)) {
                    throw new \RuntimeException('field interactive presentation has a mismatched conflicting entity');
                }
                unset($public[$selector]);
                continue;
            }
            if (isset($public[$selector]) || !in_array($category, ['production-only', 'branch-only', 'compatible'], true)) {
                throw new \RuntimeException('field interactive presentation has an invalid automatic record');
            }
            $relation = self::recordRelation($base, $production, $branch);
            if (!self::relationMatchesCategory($relation, $category)) {
                throw new \RuntimeException('field interactive presentation has an invalid automatic relation');
            }
            $selectedRole = $category === 'production-only' ? 'production' : 'branch';
            if (($entry['selected_source'] ?? null) !== $selectedRole
                || !is_array($versions[$selectedRole] ?? null)
                || ($entry['selected'] ?? null) !== $versions[$selectedRole]) {
                throw new \RuntimeException('field interactive presentation has an inconsistent automatic selection');
            }
            $auto[] = [
                'category' => $category,
                'entity' => self::publicEntity($entry, $base, $production, $branch),
                'record_selector_sha256' => $selector,
                'relation' => $relation,
                'selected_role' => $selectedRole,
            ];
        }
        if ($public !== []) {
            throw new \RuntimeException('field interactive presentation has an unknown conflicting record');
        }
        ksort($labels, SORT_STRING);
        usort($auto, static fn(array $a, array $b): int =>
            (string) $a['record_selector_sha256'] <=> (string) $b['record_selector_sha256']);
        return self::normalizeInteractivePresentation([
            'auto' => $auto,
            'diff_hash' => (string) $diff['diff_hash'],
            'format' => self::PRESENTATION_FORMAT,
            'labels' => $labels,
            'plan_hash' => (string) $diff['plan_hash'],
        ], $diff);
    }

    /**
     * Resolver for the explicit local TTY surface. Streams stay injected for
     * offline tests; CLI enforces TTY stdin/stdout before it calls here and
     * never opens /dev/tty. `q` or EOF returns null; callers must stop before
     * creating a journal/worktree/ref.
     *
     * @param resource $in
     * @param resource $out
     * @return ?array<string,mixed>
     */
    public static function interactiveResolution(array $diff, $in, $out, ?array $presentation = null): ?array {
        self::assertDiff($diff);
        $presentation = self::normalizeInteractivePresentation($presentation, $diff);
        $labels = $presentation['labels'];
        $preview = [];
        $items = [];
        foreach ($presentation['auto'] as $automatic) {
            $preview[] = [
                'category' => $automatic['category'],
                'field' => 'record',
                'field_selector_sha256' => '',
                'record_selector_sha256' => $automatic['record_selector_sha256'],
                'relation' => $automatic['relation'],
                'scope' => 'record',
                '_automatic' => true,
                '_entity' => $automatic['entity'],
                '_label' => $labels[$automatic['record_selector_sha256']],
            ];
        }
        foreach ((array) $diff['records'] as $record) {
            foreach ((array) ($record['changes'] ?? []) as $change) {
                if (($change['category'] ?? null) === 'unchanged') continue;
                // Keep only the enclosing closed vocabulary needed to make an
                // atomic prompt actionable. The public projection has already
                // been schema-validated, so this cannot carry a path, id, or
                // source literal into TTY output.
                $item = $change + [
                    '_entity' => (string) ($record['entity'] ?? 'record'),
                    '_label' => $labels[(string) ($record['record_selector_sha256'] ?? '')] ?? null,
                    '_record_reason' => (string) ($record['reason'] ?? 'opaque_record_type'),
                ];
                $preview[] = $item;
                if (($change['category'] ?? null) === 'conflicting') {
                    $items[] = $item;
                }
            }
        }
        usort($preview, static fn(array $a, array $b): int =>
            self::choiceKey((string) $a['record_selector_sha256'], (string) $a['field_selector_sha256'])
            <=> self::choiceKey((string) $b['record_selector_sha256'], (string) $b['field_selector_sha256']));
        usort($items, static fn(array $a, array $b): int =>
            self::choiceKey((string) $a['record_selector_sha256'], (string) $a['field_selector_sha256'])
            <=> self::choiceKey((string) $b['record_selector_sha256'], (string) $b['field_selector_sha256']));
        fwrite($out, "refresh field resolution preview (closed choices; ours=branch, theirs=production; local labels are not persisted)\n");
        if ($preview === []) {
            fwrite($out, "no changes\n");
            return self::resolution($diff, []);
        }
        foreach ($preview as $item) {
            $record = (string) $item['record_selector_sha256'];
            $entity = (string) ($item['_entity'] ?? 'record');
            $field = (string) ($item['field'] ?? 'record');
            $scope = (string) ($item['scope'] ?? 'record');
            $category = (string) ($item['category'] ?? 'conflicting');
            $reason = (string) ($item['_record_reason'] ?? 'opaque_record_type');
            $relation = self::relationSummary((array) ($item['relation'] ?? []));
            $label = self::presentationLabelSuffix($item['_label'] ?? null);
            $outcome = match ($category) {
                'production-only' => 'auto=production',
                'branch-only', 'compatible' => 'auto=branch',
                'conflicting' => 'manual=choice_required',
                default => 'manual=choice_required',
            };
            $reasonPart = ($item['_automatic'] ?? false) === true ? '' : ' ' . $reason;
            fwrite($out, "preview $entity $scope $record$label $field $category$reasonPart $relation $outcome\n");
        }
        if ($items === []) {
            fwrite($out, "0 manual choices; continuing\n");
            return self::resolution($diff, []);
        }
        fwrite($out, "refresh field resolution (values omitted; b=branch, p=production, q=cancel)\n");
        $choices = [];
        $total = count($items);
        foreach ($items as $position => $item) {
            // Selectors are opaque, but a truncated unqualified prefix is not
            // a stable identity. Keep the complete selector in the prompt so
            // a human transcript cannot become ambiguous under collision.
            $record = (string) $item['record_selector_sha256'];
            $entity = (string) ($item['_entity'] ?? 'record');
            $field = (string) ($item['field'] ?? 'record');
            $scope = (string) ($item['scope'] ?? 'record');
            $category = (string) ($item['category'] ?? 'conflicting');
            $reason = (string) ($item['_record_reason'] ?? 'opaque_record_type');
            $relation = self::relationSummary((array) ($item['relation'] ?? []));
            $label = self::presentationLabelSuffix($item['_label'] ?? null);
            while (true) {
                fwrite($out, '[' . ($position + 1) . '/' . $total . "] $entity $scope $record$label $field $category $reason $relation: branch or production? [b/p/q] ");
                $line = fgets($in);
                if ($line === false) return null;
                $answer = strtolower(trim($line));
                $choice = match ($answer) {
                    'b', 'branch', 'ours' => 'ours',
                    'p', 'production', 'theirs' => 'theirs',
                    'q', 'quit', 'cancel' => null,
                    default => false,
                };
                if ($choice === null) return null;
                if ($choice === false) {
                    fwrite($out, "  enter b/branch, p/production, or q/cancel; values are omitted\n");
                    continue;
                }
                $choices[] = [
                    'choice' => $choice,
                    'field_selector_sha256' => (string) $item['field_selector_sha256'],
                    'record_selector_sha256' => (string) $item['record_selector_sha256'],
                    'scope' => $scope,
                ];
                break;
            }
        }
        return self::resolution($diff, $choices);
    }

    /**
     * Validate the immutable, redacted resolution against this exact diff.
     * It returns a normalized value including resolution_hash and never
     * consults the private B/P/W spans.
     *
     * @return array<string,mixed>
     */
    public static function normalizeResolution(array $resolution, array $diff): array {
        self::assertDiff($diff);
        $keys = array_keys($resolution);
        sort($keys, SORT_STRING);
        $expectedTop = [
            'algorithm', 'choices', 'diff_hash', 'format', 'plan_hash',
            'production_snapshot_hash', 'resolution_hash',
        ];
        sort($expectedTop, SORT_STRING);
        if ($keys !== $expectedTop) {
            throw new \RuntimeException('field resolution has an unsupported shape');
        }
        if (($resolution['format'] ?? null) !== self::RESOLUTION_FORMAT
            || ($resolution['algorithm'] ?? null) !== self::ALGORITHM
            || !hash_equals((string) $diff['plan_hash'], (string) ($resolution['plan_hash'] ?? ''))
            || !hash_equals((string) $diff['diff_hash'], (string) ($resolution['diff_hash'] ?? ''))
            || !hash_equals(
                (string) ($diff['production_snapshot_hash'] ?? ''),
                (string) ($resolution['production_snapshot_hash'] ?? '')
            )) {
            throw new \RuntimeException('field resolution does not bind this exact refresh diff');
        }
        if (!is_array($resolution['choices'] ?? null) || !array_is_list($resolution['choices'])) {
            throw new \RuntimeException('field resolution choices must be a canonical list');
        }

        $expected = self::expectedChoices($diff);
        $normalizedChoices = [];
        $prior = null;
        foreach ($resolution['choices'] as $choice) {
            if (!is_array($choice) || array_is_list($choice)) {
                throw new \RuntimeException('field resolution choice is malformed');
            }
            $choiceKeys = array_keys($choice);
            sort($choiceKeys, SORT_STRING);
            $want = ['choice', 'field_selector_sha256', 'record_selector_sha256', 'scope'];
            sort($want, SORT_STRING);
            if ($choiceKeys !== $want) {
                throw new \RuntimeException('field resolution choice has an unsupported shape');
            }
            foreach (['record_selector_sha256', 'field_selector_sha256'] as $field) {
                if (!self::isHash($choice[$field] ?? null)) {
                    throw new \RuntimeException('field resolution choice has an invalid selector');
                }
            }
            if (!in_array($choice['scope'] ?? null, ['field', 'record'], true)
                || !in_array($choice['choice'] ?? null, ['ours', 'theirs'], true)) {
                throw new \RuntimeException('field resolution choice must select ours or theirs');
            }
            $key = self::choiceKey((string) $choice['record_selector_sha256'], (string) $choice['field_selector_sha256']);
            if ($prior !== null && $key <= $prior) {
                throw new \RuntimeException('field resolution choices must be unique and selector-sorted');
            }
            $prior = $key;
            $actual = $expected[$key] ?? null;
            if ($actual === null || $actual['scope'] !== $choice['scope']) {
                throw new \RuntimeException('field resolution choice is stale or does not name a conflicting leaf');
            }
            $normalizedChoices[] = [
                'choice' => (string) $choice['choice'],
                'field_selector_sha256' => (string) $choice['field_selector_sha256'],
                'record_selector_sha256' => (string) $choice['record_selector_sha256'],
                'scope' => (string) $choice['scope'],
            ];
        }
        if (count($normalizedChoices) !== count($expected)) {
            throw new \RuntimeException('field resolution is incomplete for this refresh diff');
        }
        $normalized = [
            'algorithm' => self::ALGORITHM,
            'choices' => $normalizedChoices,
            'diff_hash' => (string) $diff['diff_hash'],
            'format' => self::RESOLUTION_FORMAT,
            'plan_hash' => (string) $diff['plan_hash'],
            'production_snapshot_hash' => $diff['production_snapshot_hash'],
        ];
        $actualHash = self::hashDocument($normalized, 'resolution_hash');
        if (!self::isHash($resolution['resolution_hash'] ?? null)
            || !hash_equals($actualHash, (string) $resolution['resolution_hash'])) {
            throw new \RuntimeException('field resolution hash does not verify');
        }
        $normalized['resolution_hash'] = $actualHash;
        return $normalized;
    }

    /**
     * Read an operator-supplied resolution without exposing its path or bytes
     * in errors. The exact canonical JSON check prevents a second, semantically
     * equivalent encoding from becoming an unbound input format.
     *
     * @return array<string,mixed>
     */
    public static function readResolutionFile(string $path, array $diff): array {
        if ($path === '' || strlen($path) > 4096 || str_contains($path, "\0")) {
            throw new \RuntimeException('field resolution input is invalid');
        }
        // Check the directory entry before opening it. In particular, opening
        // a FIFO first could block this non-interactive input boundary forever.
        // lstat() does not follow symlinks, so only an ordinary regular file
        // is even eligible to reach fopen().
        $entry = @lstat($path);
        if (!is_array($entry) || (($entry['mode'] ?? 0) & 0170000) !== 0100000
            || (int) ($entry['size'] ?? -1) < 1 || (int) ($entry['size'] ?? 0) > 1048576) {
            throw new \RuntimeException('field resolution input must be a bounded regular file');
        }
        $handle = @fopen($path, 'rb');
        if ($handle === false) throw new \RuntimeException('field resolution input cannot be read');
        try {
            $stat = fstat($handle);
            if (!is_array($stat) || (($stat['mode'] ?? 0) & 0170000) !== 0100000
                || (int) ($stat['size'] ?? -1) < 1 || (int) ($stat['size'] ?? 0) > 1048576
                || (int) ($stat['dev'] ?? -1) !== (int) ($entry['dev'] ?? -2)
                || (int) ($stat['ino'] ?? -1) !== (int) ($entry['ino'] ?? -2)) {
                throw new \RuntimeException('field resolution input must be a bounded regular file');
            }
            $size = (int) $stat['size'];
            $bytes = stream_get_contents($handle, $size + 1);
            $after = fstat($handle);
            if (!is_string($bytes) || strlen($bytes) !== $size || !is_array($after)
                || (int) ($after['dev'] ?? -1) !== (int) ($stat['dev'] ?? -2)
                || (int) ($after['ino'] ?? -1) !== (int) ($stat['ino'] ?? -2)
                || (int) ($after['size'] ?? -1) !== $size) {
                throw new \RuntimeException('field resolution input cannot be read');
            }
        } finally {
            fclose($handle);
        }
        try {
            $decoded = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new \RuntimeException('field resolution input is not valid JSON');
        }
        if (!is_array($decoded) || array_is_list($decoded) || self::encode($decoded) !== $bytes) {
            throw new \RuntimeException('field resolution input must use canonical JSON');
        }
        if (!array_key_exists('resolution_hash', $decoded)) {
            throw new \RuntimeException('field resolution input must bind its resolution_hash');
        }
        return self::normalizeResolution($decoded, $diff);
    }

    /**
     * Apply a validated field/record resolution to a transient plan copy.
     * No decoded hybrid is ever serialized: replacements are copied raw in
     * descending span order into the exact branch document.
     *
     * @return array<string,mixed>
     */
    public static function apply(array $plan, array $bundle, array $resolution): array {
        if (($bundle['algorithm'] ?? null) !== self::ALGORITHM
            || !hash_equals((string) ($plan['plan_hash'] ?? ''), (string) ($bundle['plan_hash'] ?? ''))
            || !is_array($bundle['records'] ?? null)) {
            throw new \RuntimeException('field resolution bundle does not match the refresh plan');
        }
        $diff = self::diffFromBundle($bundle, $plan);
        $resolution = self::normalizeResolution($resolution, $diff);
        self::assertBundleMatchesDiff($plan, $bundle, $diff);
        $choiceMap = [];
        foreach ($resolution['choices'] as $choice) {
            $choiceMap[self::choiceKey($choice['record_selector_sha256'], $choice['field_selector_sha256'])] = $choice['choice'];
        }
        foreach ($bundle['records'] as $recordSelector => $record) {
            $entry =& $plan['entries'][$record['entry_index']];
            if (($record['mode'] ?? null) === 'record') {
                $choice = $choiceMap[self::choiceKey($recordSelector, $record['record_field_selector'])] ?? null;
                if (!in_array($choice, ['ours', 'theirs'], true)) {
                    throw new \RuntimeException('field resolution is incomplete for an atomic record');
                }
                $source = $choice === 'ours' ? 'branch' : 'production';
                $selected = $entry['versions'][$source] ?? null;
                if (self::unsafeAbsence(
                    is_array($entry['versions']['base'] ?? null) ? $entry['versions']['base'] : null,
                    is_array($selected) ? $selected : null
                )) {
                    throw new \RuntimeException('field resolution cannot select absence without deletion authority');
                }
                $entry['selected_source'] = $source;
                $entry['selected'] = $selected;
                $entry['resolution'] = 'field-resolution';
                unset($entry);
                continue;
            }
            if (($record['mode'] ?? null) !== 'fields'
                || !is_array($record['branch_layout'] ?? null)
                || !is_array($entry['versions']['branch'] ?? null)) {
                throw new \RuntimeException('field resolution bundle has an unsupported merge record');
            }
            $replacements = [];
            foreach ((array) ($record['fields'] ?? []) as $field) {
                if (!is_array($field) || ($field['category'] ?? null) === 'unchanged') {
                    continue;
                }
                $chosen = match ($field['category']) {
                    'production-only' => 'production',
                    'branch-only', 'compatible' => 'branch',
                    'conflicting' => match ($choiceMap[self::choiceKey($recordSelector, (string) $field['field_selector'])] ?? null) {
                        'ours' => 'branch',
                        'theirs' => 'production',
                        default => null,
                    },
                    default => null,
                };
                if (!in_array($chosen, ['branch', 'production'], true)) {
                    throw new \RuntimeException('field resolution is incomplete for a conflicting field');
                }
                $spans = $field['spans']['branch'] ?? null;
                $bytesByMember = $field['values'][$chosen] ?? null;
                if (!is_array($spans) || !is_array($bytesByMember)) {
                    throw new \RuntimeException('field resolution bundle has an invalid byte span');
                }
                if ($chosen !== 'branch') {
                    foreach ($bytesByMember as $member => $bytes) {
                        $span = $spans[$member] ?? null;
                        if (!is_array($span) || !is_int($span['start'] ?? null) || !is_int($span['end'] ?? null)
                            || !is_string($bytes)) {
                            throw new \RuntimeException('field resolution bundle has an invalid byte span');
                        }
                        $replacements[] = ['start' => $span['start'], 'end' => $span['end'], 'bytes' => $bytes];
                    }
                }
            }
            $content = self::splice((string) $record['branch_layout']['content'], $replacements);
            $selected = $entry['versions']['branch'];
            $selected['content'] = $content;
            // The original row hash is repository semantic evidence. A raw
            // splice must never relabel its content SHA-256 as that hash.
            unset($selected['hash']);
            $entry['selected_source'] = 'field-resolution';
            $entry['selected'] = $selected;
            $entry['resolution'] = 'field-resolution';
            unset($entry);
        }
        foreach ($plan['entries'] as $entry) {
            if (($entry['category'] ?? null) === 'conflicting'
                && ($entry['in_scope'] ?? true) === true
                && ($entry['selected_source'] ?? null) === null) {
                throw new \RuntimeException('field resolution left a semantic conflict unresolved');
            }
        }
        return $plan;
    }

    /** Candidate policy must be the same reviewable W/P projection. */
    public static function assertCandidatePolicy(array $bundle, array $candidateProjection): void {
        $candidate = self::normalizePolicyProjection($candidateProjection);
        try {
            $branch = self::normalizePolicyProjection((array) ($bundle['policy']['branch'] ?? []));
            $production = self::normalizePolicyProjection((array) ($bundle['policy']['production'] ?? []));
        } catch (\Throwable) {
            throw new \RuntimeException('field resolution policy evidence changed before materialization');
        }
        if (!hash_equals((string) $branch['projection_hash'], (string) $production['projection_hash'])
            || !hash_equals((string) $branch['projection_hash'], (string) $candidate['projection_hash'])) {
            throw new \RuntimeException('field resolution policy evidence changed before materialization');
        }
    }

    /** @return array{public:array<string,mixed>,private:array<string,mixed>} */
    private static function projectEntry(array $entry, int $index, string $recordSelector, array $policy): array {
        $versions = is_array($entry['versions'] ?? null) ? $entry['versions'] : [];
        $base = is_array($versions['base'] ?? null) ? $versions['base'] : null;
        $production = is_array($versions['production'] ?? null) ? $versions['production'] : null;
        $branch = is_array($versions['branch'] ?? null) ? $versions['branch'] : null;
        $entity = self::publicEntity($entry, $base, $production, $branch);
        $atomic = static function (string $reason) use ($entry, $index, $recordSelector, $base, $production, $branch, $entity): array {
            $fieldSelector = self::fieldSelector($recordSelector, 'record');
            $change = self::publicChange($recordSelector, $fieldSelector, 'record', 'conflicting', $base, $production, $branch, $reason);
            return [
                'public' => [
                    'changes' => [$change],
                    'entity' => $entity,
                    'mode' => 'record',
                    'record_selector_sha256' => $recordSelector,
                    'reason' => $reason,
                ],
                'private' => [
                    'entry_index' => $index,
                    'mode' => 'record',
                    'record_field_selector' => $fieldSelector,
                    'source_content_hashes' => self::sourceContentHashes($base, $production, $branch),
                ],
            ];
        };
        if (($entry['in_scope'] ?? true) !== true) {
            return $atomic('scoped_record');
        }
        if (($entry['production_omitted'] ?? false) === true) {
            return $atomic('production_omitted');
        }
        if ($base === null || $production === null || $branch === null) {
            return $atomic('absence_or_tombstone');
        }
        if (($base['type'] ?? null) === 'deletion' || ($production['type'] ?? null) === 'deletion' || ($branch['type'] ?? null) === 'deletion') {
            return $atomic('absence_or_tombstone');
        }
        if (($base['virtual'] ?? null) === 'option' || ($production['virtual'] ?? null) === 'option'
            || ($branch['virtual'] ?? null) === 'option' || ($base['type'] ?? null) === 'option'
            || ($production['type'] ?? null) === 'option' || ($branch['type'] ?? null) === 'option') {
            return $atomic('option_or_state_witness');
        }
        $type = (string) ($entry['type'] ?? $branch['type'] ?? '');
        if ($type === 'post') {
            $projected = self::projectPost($entry, $index, $recordSelector, $base, $production, $branch, $policy, $atomic);
        } elseif ($type === 'term') {
            $projected = self::projectTerm($index, $recordSelector, $base, $production, $branch, $atomic);
        } else {
            $projected = $atomic('opaque_record_type');
        }
        $projected['private']['source_content_hashes'] = self::sourceContentHashes($base, $production, $branch);
        $projected['public']['entity'] = $entity;
        return $projected;
    }

    /** @param callable(string):array{public:array<string,mixed>,private:array<string,mixed>} $atomic */
    private static function projectPost(
        array $entry,
        int $index,
        string $recordSelector,
        array $base,
        array $production,
        array $branch,
        array $policy,
        callable $atomic
    ): array {
        if (!self::sameRouting($base, $production, $branch)) {
            return $atomic('routing_changed');
        }
        $layouts = [];
        foreach (['base' => $base, 'production' => $production, 'branch' => $branch] as $role => $row) {
            try {
                $layouts[$role] = self::postLayout((string) $row['content']);
            } catch (\Throwable) {
                return $atomic('unsupported_document_shape');
            }
        }
        $postTypes = [];
        foreach ($layouts as $layout) {
            $raw = $layout['fields']['type']['raw'] ?? null;
            $value = is_string($raw) ? self::decodeString($raw) : null;
            if ($value === null || $value === '') {
                return $atomic('unsupported_document_shape');
            }
            $postTypes[] = $value;
        }
        if (count(array_unique($postTypes, SORT_STRING)) !== 1) {
            return $atomic('routing_changed');
        }
        if ($postTypes[0] === 'attachment') {
            return $atomic('attachment_media');
        }
        if (!self::groupsAreCompleteOrSafelyAbsent($layouts, self::POST_GROUPS)) {
            return $atomic('unsupported_document_shape');
        }
        if (!self::sameFieldStructure($layouts)) {
            return $atomic('document_structure_changed');
        }
        $postFields = self::groupMembers(self::POST_GROUPS);
        foreach (array_keys($layouts['branch']['fields']) as $name) {
            if (in_array($name, $postFields, true)) {
                continue;
            }
            if (!self::sameRaw($layouts['base']['fields'][$name]['raw'], $layouts['production']['fields'][$name]['raw'], $layouts['branch']['fields'][$name]['raw'])) {
                return $atomic('opaque_or_structural_field');
            }
        }
        foreach (self::DERIVABLE_POST_FIELDS as $field) {
            if (!isset($layouts['branch']['fields'][$field])) {
                continue;
            }
            if (!self::sameRaw($layouts['base']['fields'][$field]['raw'], $layouts['production']['fields'][$field]['raw'], $layouts['branch']['fields'][$field]['raw'])
                && !self::postFieldAuthored($policy, $postTypes[0], $field)) {
                return $atomic('derived_field_policy');
            }
        }
        if (!self::groupsAreScalarWhenChanged($layouts, self::POST_GROUPS)) {
            return $atomic('opaque_container');
        }
        if ($layouts['base']['body']['raw'] !== $layouts['production']['body']['raw']
            || $layouts['base']['body']['raw'] !== $layouts['branch']['body']['raw']) {
            return $atomic('body_changed');
        }
        $fields = self::makeFields($recordSelector, $layouts, self::POST_GROUPS);
        return self::fieldRecord($index, $recordSelector, $fields, $layouts['branch']);
    }

    /** @param callable(string):array{public:array<string,mixed>,private:array<string,mixed>} $atomic */
    private static function projectTerm(
        int $index,
        string $recordSelector,
        array $base,
        array $production,
        array $branch,
        callable $atomic
    ): array {
        if (!self::sameRouting($base, $production, $branch)) {
            return $atomic('routing_changed');
        }
        $layouts = [];
        foreach (['base' => $base, 'production' => $production, 'branch' => $branch] as $role => $row) {
            try {
                $layouts[$role] = self::jsonObjectLayout((string) $row['content']);
            } catch (\Throwable) {
                return $atomic('unsupported_document_shape');
            }
        }
        if (!self::groupsAreCompleteOrSafelyAbsent($layouts, self::TERM_GROUPS)) {
            return $atomic('unsupported_document_shape');
        }
        if (!self::sameFieldStructure($layouts)) {
            return $atomic('document_structure_changed');
        }
        $termFields = self::groupMembers(self::TERM_GROUPS);
        foreach (array_keys($layouts['branch']['fields']) as $name) {
            if (in_array($name, $termFields, true)) {
                continue;
            }
            if (!self::sameRaw($layouts['base']['fields'][$name]['raw'], $layouts['production']['fields'][$name]['raw'], $layouts['branch']['fields'][$name]['raw'])) {
                return $atomic('opaque_or_structural_field');
            }
        }
        if (!self::groupsAreScalarWhenChanged($layouts, self::TERM_GROUPS)) {
            return $atomic('opaque_container');
        }
        $fields = self::makeFields($recordSelector, $layouts, self::TERM_GROUPS);
        return self::fieldRecord($index, $recordSelector, $fields, $layouts['branch']);
    }

    /** @return array{public:array<string,mixed>,private:array<string,mixed>} */
    private static function fieldRecord(int $index, string $recordSelector, array $fields, array $branchLayout): array {
        usort($fields, static fn(array $a, array $b): int => (string) $a['field_selector'] <=> (string) $b['field_selector']);
        $changes = [];
        foreach ($fields as $field) {
            if ($field['category'] === 'unchanged') {
                continue;
            }
            $changes[] = [
                'category' => $field['category'],
                'field' => $field['label'],
                'field_selector_sha256' => $field['field_selector'],
                'hash_status' => 'withheld',
                'relation' => self::fieldRelation((string) $field['category']),
                'record_selector_sha256' => $recordSelector,
                'scope' => 'field',
            ];
        }
        return [
            'public' => [
                'changes' => $changes,
                'mode' => 'fields',
                'record_selector_sha256' => $recordSelector,
                'reason' => 'eligible_engine_fields',
            ],
            'private' => [
                'branch_layout' => $branchLayout,
                'entry_index' => $index,
                'fields' => $fields,
                'mode' => 'fields',
            ],
        ];
    }

    /** @return list<array<string,mixed>> */
    private static function makeFields(string $recordSelector, array $layouts, array $groups): array {
        $out = [];
        foreach ($groups as $label => $members) {
            $values = ['base' => [], 'production' => [], 'branch' => []];
            $spans = ['base' => [], 'production' => [], 'branch' => []];
            foreach ($members as $name) {
                if (!isset($layouts['base']['fields'][$name], $layouts['production']['fields'][$name], $layouts['branch']['fields'][$name])) {
                    // Repository schemas can predate an optional field; a
                    // group absent identically on all three roles is not a
                    // newly invented merge surface.
                    continue 2;
                }
                foreach (['base', 'production', 'branch'] as $role) {
                    $values[$role][$name] = $layouts[$role]['fields'][$name]['raw'];
                    $spans[$role][$name] = self::span($layouts[$role]['fields'][$name]);
                }
            }
            $out[] = [
                'category' => self::groupCategory($values['base'], $values['production'], $values['branch']),
                'field_selector' => self::fieldSelector($recordSelector, $label),
                'label' => $label,
                'spans' => $spans,
                'values' => $values,
            ];
        }
        return $out;
    }

    /** @return list<string> */
    private static function groupMembers(array $groups): array {
        $out = [];
        foreach ($groups as $members) foreach ($members as $member) $out[] = $member;
        return $out;
    }

    /**
     * An optional legacy group member may be absent only identically. In that
     * case the entire coupled group is omitted, but only if every retained
     * member is also identical. Otherwise a skipped member could conceal a
     * change that the group semantics require us to keep coupled.
     */
    private static function groupsAreCompleteOrSafelyAbsent(array $layouts, array $groups): bool {
        foreach ($groups as $members) {
            $partial = false;
            foreach ($members as $name) {
                $basePresent = isset($layouts['base']['fields'][$name]);
                $productionPresent = isset($layouts['production']['fields'][$name]);
                $branchPresent = isset($layouts['branch']['fields'][$name]);
                if ($basePresent !== $productionPresent || $basePresent !== $branchPresent) {
                    return false;
                }
                if (!$basePresent) {
                    $partial = true;
                }
            }
            if (!$partial) {
                continue;
            }
            // The absent member can appear after retained members in the
            // group, so make the equality check independent of member order.
            foreach ($members as $name) {
                if (!isset($layouts['base']['fields'][$name])) {
                    continue;
                }
                if (!self::sameRaw(
                    $layouts['base']['fields'][$name]['raw'],
                    $layouts['production']['fields'][$name]['raw'],
                    $layouts['branch']['fields'][$name]['raw']
                )) {
                    return false;
                }
            }
        }
        return true;
    }

    private static function groupsAreScalarWhenChanged(array $layouts, array $groups): bool {
        foreach ($groups as $members) {
            $values = ['base' => [], 'production' => [], 'branch' => []];
            foreach ($members as $name) {
                if (!isset($layouts['base']['fields'][$name], $layouts['production']['fields'][$name], $layouts['branch']['fields'][$name])) {
                    continue 2;
                }
                foreach (['base', 'production', 'branch'] as $role) {
                    $values[$role][$name] = $layouts[$role]['fields'][$name]['raw'];
                }
            }
            if (self::groupCategory($values['base'], $values['production'], $values['branch']) === 'unchanged') {
                continue;
            }
            foreach ($values as $roleValues) {
                foreach ($roleValues as $raw) {
                    if (!self::isScalarJson($raw)) return false;
                }
            }
        }
        return true;
    }

    /** @return array<string,mixed> */
    private static function publicChange(
        string $recordSelector,
        string $fieldSelector,
        string $scope,
        string $category,
        ?array $base,
        ?array $production,
        ?array $branch,
        string $reason
    ): array {
        return [
            'category' => $category,
            'field' => 'record',
            'field_selector_sha256' => $fieldSelector,
            'hash_status' => 'withheld',
            'relation' => self::recordRelation($base, $production, $branch),
            'reason' => $reason,
            'record_selector_sha256' => $recordSelector,
            'scope' => $scope,
        ];
    }

    /** Every field role is present; categories encode only equality relations. */
    private static function fieldRelation(string $category): array {
        return match ($category) {
            'unchanged' => self::relation('present', 'present', 'present', true, true, true),
            'production-only' => self::relation('present', 'present', 'present', true, false, false),
            'branch-only' => self::relation('present', 'present', 'present', false, false, true),
            'compatible' => self::relation('present', 'present', 'present', false, true, false),
            'conflicting' => self::relation('present', 'present', 'present', false, false, false),
            default => throw new \RuntimeException('field relation has an unknown category'),
        };
    }

    /** Atomic relation derives only from state presence/type plus semantic equality. */
    private static function recordRelation(?array $base, ?array $production, ?array $branch): array {
        return self::relation(
            self::roleState($base),
            self::roleState($production),
            self::roleState($branch),
            self::sameRecordEvidence($branch, $base),
            self::sameRecordEvidence($branch, $production),
            self::sameRecordEvidence($production, $base),
        );
    }

    /** @return array<string,string> */
    private static function relation(
        string $base,
        string $production,
        string $branch,
        bool $branchBase,
        bool $branchProduction,
        bool $productionBase
    ): array {
        return [
            'base' => $base,
            'branch' => $branch,
            'branch_vs_base' => $branchBase ? 'same' : 'different',
            'branch_vs_production' => $branchProduction ? 'same' : 'different',
            'production' => $production,
            'production_vs_base' => $productionBase ? 'same' : 'different',
        ];
    }

    /** @param array<string,mixed> $relation */
    private static function relationSummary(array $relation): string {
        // assertDiff() has already checked the exact closed relation schema.
        return 'B=' . ($relation['base'] ?? '?')
            . ' P=' . ($relation['production'] ?? '?')
            . ' W=' . ($relation['branch'] ?? '?')
            . ' W/B=' . ($relation['branch_vs_base'] ?? '?')
            . ' P/B=' . ($relation['production_vs_base'] ?? '?')
            . ' W/P=' . ($relation['branch_vs_production'] ?? '?');
    }

    /** The plan's automatic category is exactly its B/P/W equality shape. */
    private static function relationMatchesCategory(array $relation, string $category): bool {
        if (!self::isPublicRelation($relation)) return false;
        return match ($category) {
            'production-only' => $relation['branch_vs_base'] === 'same'
                && $relation['production_vs_base'] === 'different'
                && $relation['branch_vs_production'] === 'different',
            'branch-only' => $relation['production_vs_base'] === 'same'
                && $relation['branch_vs_base'] === 'different'
                && $relation['branch_vs_production'] === 'different',
            'compatible' => $relation['branch_vs_production'] === 'same'
                && $relation['branch_vs_base'] === 'different'
                && $relation['production_vs_base'] === 'different',
            default => false,
        };
    }

    /**
     * Validate the private local presentation before anything reaches a
     * terminal. This is deliberately separate from the public diff schema:
     * it is process-local, but still closed apart from a bounded safe label.
     *
     * @return array{auto:list<array<string,mixed>>,diff_hash:string,format:string,labels:array<string,string>,plan_hash:string}
     */
    private static function normalizeInteractivePresentation(?array $presentation, array $diff): array {
        if ($presentation === null) {
            return [
                'auto' => [],
                'diff_hash' => (string) $diff['diff_hash'],
                'format' => self::PRESENTATION_FORMAT,
                'labels' => [],
                'plan_hash' => (string) $diff['plan_hash'],
            ];
        }
        $keys = array_keys($presentation);
        sort($keys, SORT_STRING);
        $expectedKeys = ['auto', 'diff_hash', 'format', 'labels', 'plan_hash'];
        sort($expectedKeys, SORT_STRING);
        if ($keys !== $expectedKeys
            || ($presentation['format'] ?? null) !== self::PRESENTATION_FORMAT
            || !hash_equals((string) $diff['plan_hash'], (string) ($presentation['plan_hash'] ?? ''))
            || !hash_equals((string) $diff['diff_hash'], (string) ($presentation['diff_hash'] ?? ''))
            || !is_array($presentation['labels'] ?? null)
            || !is_array($presentation['auto'] ?? null)
            || !array_is_list($presentation['auto'])) {
            throw new \RuntimeException('field interactive presentation is malformed');
        }
        $diffSelectors = [];
        foreach ($diff['records'] as $record) {
            $selector = (string) $record['record_selector_sha256'];
            $diffSelectors[$selector] = true;
        }
        $auto = [];
        $autoSelectors = [];
        $prior = null;
        foreach ($presentation['auto'] as $record) {
            if (!is_array($record) || array_is_list($record)) {
                throw new \RuntimeException('field interactive presentation is malformed');
            }
            $recordKeys = array_keys($record);
            sort($recordKeys, SORT_STRING);
            $want = ['category', 'entity', 'record_selector_sha256', 'relation', 'selected_role'];
            sort($want, SORT_STRING);
            if ($recordKeys !== $want
                || !self::isHash($record['record_selector_sha256'] ?? null)
                || !in_array($record['entity'] ?? null, self::PUBLIC_ENTITIES, true)
                || !in_array($record['category'] ?? null, ['production-only', 'branch-only', 'compatible'], true)
                || !self::isPublicRelation($record['relation'] ?? null)
                || !self::relationMatchesCategory($record['relation'], (string) $record['category'])
                || ($record['selected_role'] ?? null) !== (($record['category'] ?? null) === 'production-only' ? 'production' : 'branch')) {
                throw new \RuntimeException('field interactive presentation is malformed');
            }
            $selector = (string) $record['record_selector_sha256'];
            if (isset($diffSelectors[$selector]) || isset($autoSelectors[$selector])
                || ($prior !== null && $selector <= $prior)) {
                throw new \RuntimeException('field interactive presentation has duplicate or unordered records');
            }
            $prior = $selector;
            $autoSelectors[$selector] = true;
            $auto[] = [
                'category' => (string) $record['category'],
                'entity' => (string) $record['entity'],
                'record_selector_sha256' => $selector,
                'relation' => $record['relation'],
                'selected_role' => (string) $record['selected_role'],
            ];
        }
        $expectedLabels = $diffSelectors + $autoSelectors;
        ksort($expectedLabels, SORT_STRING);
        $labels = [];
        $priorLabel = null;
        foreach ($presentation['labels'] as $selector => $label) {
            if (!is_string($selector) || !self::isHash($selector) || !is_string($label)
                || $label === '' || !hash_equals(self::sanitizeLocalLabel($label), $label)
                || ($priorLabel !== null && $selector <= $priorLabel)
                || !isset($expectedLabels[$selector])) {
                throw new \RuntimeException('field interactive presentation has an invalid local label');
            }
            $priorLabel = $selector;
            $labels[$selector] = $label;
        }
        if (array_keys($labels) !== array_keys($expectedLabels)) {
            throw new \RuntimeException('field interactive presentation is missing a local record label');
        }
        return [
            'auto' => $auto,
            'diff_hash' => (string) $diff['diff_hash'],
            'format' => self::PRESENTATION_FORMAT,
            'labels' => $labels,
            'plan_hash' => (string) $diff['plan_hash'],
        ];
    }

    /** @param mixed $label */
    private static function presentationLabelSuffix(mixed $label): string {
        if (!is_string($label) || $label === '') return '';
        // Spaces and punctuation are safe bytes but can resemble the closed
        // prompt grammar. JSON quoting keeps the transient label visually
        // distinct without widening any persisted contract.
        return ' label=' . json_encode(
            $label,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    }

    private static function roleState(?array $row): string {
        if ($row === null) return 'absent';
        return ($row['type'] ?? null) === 'deletion' ? 'tombstone' : 'present';
    }

    private static function sameRecordEvidence(?array $left, ?array $right): bool {
        if (self::roleState($left) !== self::roleState($right)) return false;
        if ($left === null && $right === null) return true;
        if (!is_string($left['type'] ?? null) || !is_string($right['type'] ?? null)
            || !hash_equals((string) $left['type'], (string) $right['type'])) {
            return false;
        }
        $leftHash = $left['hash'] ?? null;
        $rightHash = $right['hash'] ?? null;
        return self::isHash($leftHash) && self::isHash($rightHash)
            && hash_equals((string) $leftHash, (string) $rightHash);
    }

    /** @return array{content:string,fields:array<string,array{raw:string,start:int,end:int}>,body:array{raw:string,start:int,end:int}} */
    private static function postLayout(string $content): array {
        if (!str_starts_with($content, "---\n")) {
            throw new \RuntimeException('not a post document');
        }
        $end = strpos($content, "\n---\n", 3);
        if ($end === false) {
            throw new \RuntimeException('unterminated post front matter');
        }
        $frontStart = 4;
        $front = substr($content, $frontStart, $end - 3);
        $json = self::parseObject($front);
        $fields = [];
        foreach ($json['fields'] as $name => $span) {
            $fields[$name] = [
                'raw' => $span['raw'],
                'start' => $frontStart + $span['start'],
                'end' => $frontStart + $span['end'],
            ];
        }
        $bodyStart = $end + 5;
        return [
            'content' => $content,
            'fields' => $fields,
            'body' => [
                'raw' => substr($content, $bodyStart),
                'start' => $bodyStart,
                'end' => strlen($content),
            ],
        ];
    }

    /** @return array{content:string,fields:array<string,array{raw:string,start:int,end:int}}> */
    private static function jsonObjectLayout(string $content): array {
        $json = self::parseObject($content);
        return ['content' => $content, 'fields' => $json['fields']];
    }

    /**
     * A token/range reader, not a semantic decoder. It locates exact source
     * slices for members of a JSON object and checks enough grammar to reject
     * malformed or duplicate-key documents. Object/list values are scanned as
     * opaque tokens and are never rebuilt.
     *
     * @return array{fields:array<string,array{raw:string,start:int,end:int}>,order:list<string>}
     */
    private static function parseObject(string $source): array {
        $length = strlen($source);
        $pos = self::skipWhitespace($source, 0);
        if ($pos >= $length || $source[$pos] !== '{') {
            throw new \RuntimeException('document root is not an object');
        }
        $pos++;
        $fields = [];
        $order = [];
        $pos = self::skipWhitespace($source, $pos);
        if ($pos < $length && $source[$pos] === '}') {
            $pos = self::skipWhitespace($source, $pos + 1);
            if ($pos !== $length) throw new \RuntimeException('trailing JSON bytes');
            return ['fields' => $fields, 'order' => $order];
        }
        while (true) {
            if ($pos >= $length || $source[$pos] !== '"') throw new \RuntimeException('JSON object key is invalid');
            $keyStart = $pos;
            $keyEnd = self::scanString($source, $pos);
            try {
                $name = json_decode(substr($source, $keyStart, $keyEnd - $keyStart), true, 512, JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                throw new \RuntimeException('JSON object key is invalid');
            }
            if (!is_string($name) || isset($fields[$name])) throw new \RuntimeException('JSON object key is duplicate');
            $pos = self::skipWhitespace($source, $keyEnd);
            if ($pos >= $length || $source[$pos] !== ':') throw new \RuntimeException('JSON object lacks colon');
            $pos = self::skipWhitespace($source, $pos + 1);
            $valueStart = $pos;
            $valueEnd = self::scanValue($source, $pos);
            $fields[$name] = [
                'raw' => substr($source, $valueStart, $valueEnd - $valueStart),
                'start' => $valueStart,
                'end' => $valueEnd,
            ];
            $order[] = $name;
            $pos = self::skipWhitespace($source, $valueEnd);
            if ($pos >= $length) throw new \RuntimeException('unterminated JSON object');
            if ($source[$pos] === '}') {
                $pos = self::skipWhitespace($source, $pos + 1);
                if ($pos !== $length) throw new \RuntimeException('trailing JSON bytes');
                return ['fields' => $fields, 'order' => $order];
            }
            if ($source[$pos] !== ',') throw new \RuntimeException('JSON object separator is invalid');
            $pos = self::skipWhitespace($source, $pos + 1);
        }
    }

    private static function scanValue(string $source, int $pos): int {
        $length = strlen($source);
        if ($pos >= $length) throw new \RuntimeException('missing JSON value');
        $char = $source[$pos];
        if ($char === '"') return self::scanString($source, $pos);
        if ($char === '{') return self::scanCompound($source, $pos, '{', '}');
        if ($char === '[') return self::scanCompound($source, $pos, '[', ']');
        $start = $pos;
        while ($pos < $length && !str_contains(" \t\r\n,]}", $source[$pos])) $pos++;
        $literal = substr($source, $start, $pos - $start);
        if (!preg_match('/^(?:true|false|null|-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?)$/D', $literal)) {
            throw new \RuntimeException('invalid JSON scalar');
        }
        return $pos;
    }

    private static function scanString(string $source, int $pos): int {
        $length = strlen($source);
        if ($pos >= $length || $source[$pos] !== '"') throw new \RuntimeException('invalid JSON string');
        $pos++;
        while ($pos < $length) {
            $ord = ord($source[$pos]);
            if ($source[$pos] === '"') return $pos + 1;
            if ($source[$pos] === '\\') {
                $pos += 2;
                continue;
            }
            if ($ord < 0x20) throw new \RuntimeException('invalid JSON string');
            $pos++;
        }
        throw new \RuntimeException('unterminated JSON string');
    }

    private static function scanCompound(string $source, int $pos, string $open, string $close): int {
        $length = strlen($source);
        $stack = [$close];
        $pos++;
        while ($pos < $length) {
            $char = $source[$pos];
            if ($char === '"') {
                $pos = self::scanString($source, $pos);
                continue;
            }
            if ($char === '{') {
                $stack[] = '}';
            } elseif ($char === '[') {
                $stack[] = ']';
            } elseif ($char === '}' || $char === ']') {
                $want = array_pop($stack);
                if ($want !== $char) throw new \RuntimeException('mismatched JSON container');
                if ($stack === []) return $pos + 1;
            }
            $pos++;
        }
        throw new \RuntimeException('unterminated JSON container');
    }

    private static function skipWhitespace(string $source, int $pos): int {
        $length = strlen($source);
        while ($pos < $length && str_contains(" \t\r\n", $source[$pos])) $pos++;
        return $pos;
    }

    /** @return array<string,array{scope:string}> */
    private static function expectedChoices(array $diff): array {
        $out = [];
        foreach ((array) ($diff['records'] ?? []) as $record) {
            foreach ((array) ($record['changes'] ?? []) as $change) {
                if (($change['category'] ?? null) !== 'conflicting') continue;
                $key = self::choiceKey((string) $change['record_selector_sha256'], (string) $change['field_selector_sha256']);
                $out[$key] = [
                    'scope' => (string) $change['scope'],
                ];
            }
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    /** Rebuild the public shape from private bundle data without leaking bytes. */
    private static function diffFromBundle(array $bundle, array $plan): array {
        // The caller normally retains the original diff. The bundle deliberately
        // stores no public copy, so this helper is only used by apply() and is
        // filled by the integration layer before it calls us.
        if (!is_array($bundle['diff'] ?? null)) {
            throw new \RuntimeException('field resolution bundle lacks its redacted diff');
        }
        $diff = $bundle['diff'];
        self::assertDiff($diff);
        if (!hash_equals((string) $bundle['diff_hash'], (string) $diff['diff_hash'])
            || !hash_equals((string) $plan['plan_hash'], (string) $diff['plan_hash'])) {
            throw new \RuntimeException('field resolution bundle does not bind its redacted diff');
        }
        return $diff;
    }

    /**
     * The public relation authorizes exactly one process-local span bundle.
     * Validate that binding before even looking at a private field's span, so
     * an alternate planner or in-process caller cannot retarget a selector or
     * smuggle an auto-applied leaf that the redacted diff never described.
     */
    private static function assertBundleMatchesDiff(array $plan, array $bundle, array $diff): void {
        $entries = $plan['entries'] ?? null;
        $privateRecords = $bundle['records'] ?? null;
        if (!is_array($entries) || !is_array($privateRecords)) {
            throw new \RuntimeException('field resolution bundle is malformed');
        }
        $publicRecords = [];
        foreach ($diff['records'] as $publicRecord) {
            $selector = (string) $publicRecord['record_selector_sha256'];
            $publicRecords[$selector] = $publicRecord;
        }
        // The private policy projection decides whether a post row is field
        // eligible or record-atomic (in particular for derived fields). Bind
        // it to the public policy hashes before re-projecting any record, so
        // a process-local bundle cannot use a different policy to make a
        // closed-but-false public description appear to fit the plan.
        try {
            $policy = self::normalizePolicyProjections(is_array($bundle['policy'] ?? null) ? $bundle['policy'] : []);
        } catch (\Throwable) {
            throw new \RuntimeException('field resolution bundle is malformed');
        }
        foreach (['base', 'branch', 'production'] as $role) {
            if (!hash_equals(
                (string) ($diff['policy_projection_hashes'][$role] ?? ''),
                (string) ($policy[$role]['projection_hash'] ?? '')
            )) {
                throw new \RuntimeException('field resolution bundle is malformed');
            }
        }
        $privateSelectors = [];
        $entryIndices = [];
        foreach ($privateRecords as $recordSelector => $record) {
            if (!is_string($recordSelector) || !self::isHash($recordSelector)
                || !isset($publicRecords[$recordSelector])
                || !is_array($record) || array_is_list($record)
                || !is_int($record['entry_index'] ?? null)) {
                throw new \RuntimeException('field resolution bundle is malformed');
            }
            $entryIndex = $record['entry_index'];
            if (isset($entryIndices[$entryIndex]) || !array_key_exists($entryIndex, $entries)
                || !is_array($entries[$entryIndex])) {
                throw new \RuntimeException('field resolution bundle is malformed');
            }
            $entry = $entries[$entryIndex];
            if (($entry['category'] ?? null) !== 'conflicting' || ($entry['in_scope'] ?? true) !== true
                || !is_string($entry['id'] ?? null) || $entry['id'] === ''
                || !hash_equals($recordSelector, self::recordSelector($entry['id']))) {
                throw new \RuntimeException('field resolution bundle is malformed');
            }
            $versions = is_array($entry['versions'] ?? null) ? $entry['versions'] : [];
            $base = is_array($versions['base'] ?? null) ? $versions['base'] : null;
            $production = is_array($versions['production'] ?? null) ? $versions['production'] : null;
            $branch = is_array($versions['branch'] ?? null) ? $versions['branch'] : null;
            if (self::unsafeAbsence($base, $production) || self::unsafeAbsence($base, $branch)) {
                throw new \RuntimeException('field-level resolution is unavailable for unsafe live-record absence');
            }
            if (!self::sourceContentHashesMatch($versions, $record['source_content_hashes'] ?? null)) {
                throw new \RuntimeException('field resolution source evidence changed before materialization');
            }
            $publicRecord = $publicRecords[$recordSelector];
            try {
                $expectedPublic = self::projectEntry($entry, $entryIndex, $recordSelector, $policy)['public'];
            } catch (\Throwable) {
                throw new \RuntimeException('field resolution bundle is malformed');
            }
            // Canonical equality avoids trusting an otherwise schema-valid
            // atomic entity/reason/relation merely because its selector is
            // right. It also keeps fields-mode public categories tied to the
            // same verified B/P/W rows and policy as their private spans.
            if (!hash_equals(self::encode($expectedPublic), self::encode($publicRecord))) {
                throw new \RuntimeException('field resolution bundle is malformed');
            }
            if (($record['mode'] ?? null) !== ($publicRecord['mode'] ?? null)) {
                throw new \RuntimeException('field resolution bundle is malformed');
            }
            if ($record['mode'] === 'record') {
                $changes = $publicRecord['changes'] ?? null;
                if (!is_array($changes) || count($changes) !== 1
                    || !is_string($record['record_field_selector'] ?? null)
                    || !hash_equals(
                        (string) ($changes[0]['field_selector_sha256'] ?? ''),
                        $record['record_field_selector']
                    )) {
                    throw new \RuntimeException('field resolution bundle is malformed');
                }
            } elseif ($record['mode'] === 'fields') {
                self::assertFieldsBundleRecord($record, $publicRecord, $entry);
            } else {
                throw new \RuntimeException('field resolution bundle is malformed');
            }
            $entryIndices[$entryIndex] = true;
            $privateSelectors[] = $recordSelector;
        }
        $publicSelectors = array_keys($publicRecords);
        sort($privateSelectors, SORT_STRING);
        sort($publicSelectors, SORT_STRING);
        if ($privateSelectors !== $publicSelectors) {
            throw new \RuntimeException('field resolution bundle is malformed');
        }
    }

    /** Bind every private changed field to one and only one public change. */
    private static function assertFieldsBundleRecord(array $record, array $publicRecord, array $entry): void {
        $versions = $entry['versions'] ?? null;
        $branch = is_array($versions) && is_array($versions['branch'] ?? null) ? $versions['branch'] : null;
        if (!is_array($record['fields'] ?? null) || !array_is_list($record['fields'])
            || !is_array($record['branch_layout'] ?? null)
            || !is_array($branch) || !is_string($branch['content'] ?? null)
            || !is_string($record['branch_layout']['content'] ?? null)
            || !hash_equals($branch['content'], $record['branch_layout']['content'])) {
            throw new \RuntimeException('field resolution bundle is malformed');
        }
        $expected = [];
        foreach ($publicRecord['changes'] as $change) {
            if (($change['category'] ?? null) === 'unchanged') {
                continue;
            }
            $selector = (string) ($change['field_selector_sha256'] ?? '');
            $expected[$selector] = [
                'category' => (string) ($change['category'] ?? ''),
                'label' => (string) ($change['field'] ?? ''),
                'scope' => (string) ($change['scope'] ?? ''),
            ];
        }
        $seen = [];
        foreach ($record['fields'] as $field) {
            if (!is_array($field) || array_is_list($field)) {
                throw new \RuntimeException('field resolution bundle is malformed');
            }
            if (($field['category'] ?? null) === 'unchanged') {
                // Unchanged private fields have no write path and therefore
                // remain deliberately absent from the public projection.
                continue;
            }
            $selector = $field['field_selector'] ?? null;
            if (!is_string($selector) || isset($seen[$selector]) || !isset($expected[$selector])) {
                throw new \RuntimeException('field resolution bundle is malformed');
            }
            $public = $expected[$selector];
            // Fields-mode has one fixed private scope; bind it to the public
            // field scope rather than adding another untrusted private label.
            if (($field['label'] ?? null) !== $public['label']
                || ($field['category'] ?? null) !== $public['category']
                || $public['scope'] !== 'field') {
                throw new \RuntimeException('field resolution bundle is malformed');
            }
            self::assertPrivateFieldSource($field, $public['label'], $entry);
            $seen[$selector] = true;
        }
        if (count($seen) !== count($expected)) {
            throw new \RuntimeException('field resolution bundle is malformed');
        }
    }

    /** Ensure a changed field's spans and bytes name its exact source members. */
    private static function assertPrivateFieldSource(array $field, string $label, array $entry): void {
        $isPost = isset(self::POST_GROUPS[$label]);
        $members = $isPost ? self::POST_GROUPS[$label] : (self::TERM_GROUPS[$label] ?? null);
        if (!is_array($members) || ($entry['type'] ?? null) !== ($isPost ? 'post' : 'term')) {
            throw new \RuntimeException('field resolution bundle has an invalid byte span');
        }
        $values = $field['values'] ?? null;
        $spans = $field['spans'] ?? null;
        $roles = ['base', 'production', 'branch'];
        if (!is_array($values) || !is_array($spans)
            || array_keys($values) !== $roles || array_keys($spans) !== $roles) {
            throw new \RuntimeException('field resolution bundle has an invalid byte span');
        }
        $sourceValues = ['base' => [], 'production' => [], 'branch' => []];
        foreach ($roles as $role) {
            $row = $entry['versions'][$role] ?? null;
            if (!is_array($row) || !is_string($row['content'] ?? null)
                || !is_array($values[$role]) || !is_array($spans[$role])
                || array_keys($values[$role]) !== $members || array_keys($spans[$role]) !== $members) {
                throw new \RuntimeException('field resolution bundle has an invalid byte span');
            }
            try {
                $layout = $isPost ? self::postLayout($row['content']) : self::jsonObjectLayout($row['content']);
            } catch (\Throwable) {
                throw new \RuntimeException('field resolution bundle has an invalid byte span');
            }
            foreach ($members as $member) {
                $source = $layout['fields'][$member] ?? null;
                if (!is_array($source) || !is_string($source['raw'] ?? null)
                    || !is_string($values[$role][$member] ?? null)
                    || !is_array($spans[$role][$member] ?? null)
                    || !self::isScalarJson($source['raw'])
                    || !hash_equals($source['raw'], $values[$role][$member])
                    || $spans[$role][$member] !== self::span($source)) {
                    throw new \RuntimeException('field resolution bundle has an invalid byte span');
                }
                $sourceValues[$role][$member] = $source['raw'];
            }
        }
        if (($field['category'] ?? null) !== self::groupCategory(
            $sourceValues['base'], $sourceValues['production'], $sourceValues['branch']
        )) {
            throw new \RuntimeException('field resolution bundle has an invalid byte span');
        }
    }

    private static function splice(string $content, array $replacements): string {
        usort($replacements, static fn(array $a, array $b): int => $b['start'] <=> $a['start']);
        $last = strlen($content) + 1;
        foreach ($replacements as $replacement) {
            $start = $replacement['start'];
            $end = $replacement['end'];
            if ($start < 0 || $end < $start || $end > strlen($content) || $end > $last) {
                throw new \RuntimeException('field resolution spans overlap or escape the branch document');
            }
            $content = substr($content, 0, $start) . $replacement['bytes'] . substr($content, $end);
            $last = $start;
        }
        return $content;
    }

    private static function sameRouting(array $base, array $production, array $branch): bool {
        foreach (['type', 'path'] as $field) {
            if (!is_string($base[$field] ?? null) || !hash_equals((string) $base[$field], (string) ($production[$field] ?? ''))
                || !hash_equals((string) $base[$field], (string) ($branch[$field] ?? ''))) {
                return false;
            }
        }
        return true;
    }

    private static function publicEntity(array $entry, ?array $base, ?array $production, ?array $branch): string {
        $type = (string) ($entry['type'] ?? $branch['type'] ?? $production['type'] ?? $base['type'] ?? '');
        if ($type === 'post') {
            foreach ([$base, $production, $branch] as $row) {
                if (self::isAttachmentPostRow($row)) return 'attachment';
            }
            return 'post';
        }
        return match ($type) {
            'attachment' => 'attachment',
            'term' => 'term',
            'menu' => 'menu',
            'sidebar' => 'sidebar',
            'options', 'option' => 'options',
            'user-meta' => 'user_meta',
            default => str_starts_with((string) ($branch['path'] ?? $production['path'] ?? $base['path'] ?? ''), 'tables/')
                ? 'typed_table'
                : 'record',
        };
    }

    /**
     * A local-only human hint. Prefer an engine-authored display field from
     * the current branch, then production/base, and finally a safe path. It
     * deliberately never touches body, meta, options, user meta, or opaque
     * document members.
     */
    private static function localRecordLabel(array $entry, ?array $base, ?array $production, ?array $branch): string {
        // An automatic production-only row will materialize production bytes;
        // show that role's ordinary display label first. Every other path is
        // branch-scaffolded, including compatible rows and conflicts.
        $orderedRows = ($entry['category'] ?? null) === 'production-only'
            ? [$production, $branch, $base]
            : [$branch, $production, $base];
        foreach ($orderedRows as $row) {
            $label = self::localAuthoredLabel($row);
            if ($label !== '') return $label;
        }
        foreach ($orderedRows as $row) {
            if (is_array($row) && is_string($row['path'] ?? null)) {
                $path = self::sanitizeLocalLabel('path:' . $row['path']);
                if ($path !== '') return $path;
            }
        }
        // This final fallback is closed vocabulary, not a private value.
        return 'record';
    }

    private static function localAuthoredLabel(?array $row): string {
        if ($row === null || !is_string($row['type'] ?? null) || !is_string($row['content'] ?? null)) {
            return '';
        }
        try {
            $layout = match ($row['type']) {
                'post' => self::postLayout($row['content']),
                'term', 'menu' => self::jsonObjectLayout($row['content']),
                default => null,
            };
        } catch (\Throwable) {
            return '';
        }
        if (!is_array($layout)) return '';
        $field = match ($row['type']) {
            'post' => 'title',
            'term', 'menu' => 'name',
            default => null,
        };
        $raw = $field === null ? null : ($layout['fields'][$field]['raw'] ?? null);
        return is_string($raw) ? self::sanitizeLocalLabel(self::decodeString($raw) ?? '') : '';
    }

    /** Remove terminal controls/invalid UTF-8 and cap the local-only hint. */
    private static function sanitizeLocalLabel(string $label): string {
        if ($label === '' || preg_match('//u', $label) !== 1) return '';
        $label = preg_replace('/\\p{C}+/u', ' ', $label);
        $label = is_string($label) ? preg_replace('/\\s+/u', ' ', $label) : null;
        if (!is_string($label)) return '';
        $label = trim($label);
        if ($label === '') return '';
        if (strlen($label) <= 160) return $label;
        $cut = 157;
        while ($cut > 0 && (ord($label[$cut]) & 0xc0) === 0x80) $cut--;
        return substr($label, 0, $cut) . '...';
    }

    /** A public attachment label requires an independently canonical signal. */
    private static function isAttachmentPostRow(?array $row): bool {
        if ($row === null) return false;
        $path = $row['path'] ?? null;
        if (is_string($path) && str_starts_with($path, 'posts/attachment/')) {
            return true;
        }
        if (!is_string($row['content'] ?? null)) return false;
        try {
            $layout = self::postLayout($row['content']);
            $raw = $layout['fields']['type']['raw'] ?? null;
            return is_string($raw) && self::decodeString($raw) === 'attachment';
        } catch (\Throwable) {
            return false;
        }
    }

    private static function unsafeAbsence(?array $base, ?array $side): bool {
        return $base !== null && ($base['type'] ?? null) !== 'deletion' && $side === null;
    }

    /** @return array{base:?string,production:?string,branch:?string} */
    private static function sourceContentHashes(?array $base, ?array $production, ?array $branch): array {
        $hash = static function (?array $row): ?string {
            return $row === null || !is_string($row['content'] ?? null)
                ? null
                : hash('sha256', $row['content']);
        };
        return ['base' => $hash($base), 'production' => $hash($production), 'branch' => $hash($branch)];
    }

    private static function sourceContentHashesMatch(mixed $versions, mixed $expected): bool {
        if (!is_array($versions) || !is_array($expected)) return false;
        return self::sourceContentHashes(
            is_array($versions['base'] ?? null) ? $versions['base'] : null,
            is_array($versions['production'] ?? null) ? $versions['production'] : null,
            is_array($versions['branch'] ?? null) ? $versions['branch'] : null,
        ) === $expected;
    }

    private static function sameFieldStructure(array $layouts): bool {
        $base = array_keys($layouts['base']['fields']);
        return $base === array_keys($layouts['production']['fields']) && $base === array_keys($layouts['branch']['fields']);
    }

    private static function sameRaw(string $base, string $production, string $branch): bool {
        return hash_equals($base, $production) && hash_equals($base, $branch);
    }

    private static function groupCategory(array $base, array $production, array $branch): string {
        // The Refresh plan compares canonical semantic state, not source
        // token spellings.  Keep that same meaning for scalar engine fields
        // (for example `1` and `1.0`, or an escaped string and its literal
        // spelling), while retaining the original raw source spans for any
        // later byte-exact splice.  Containers deliberately do not take this
        // path: canonicalizing one could reorder an order-preserved subtree.
        $canonicalBase = self::canonicalScalarGroup($base);
        $canonicalProduction = self::canonicalScalarGroup($production);
        $canonicalBranch = self::canonicalScalarGroup($branch);
        if ($canonicalBase !== null && $canonicalProduction !== null && $canonicalBranch !== null) {
            return self::categoryFromEvidence($canonicalBase, $canonicalProduction, $canonicalBranch);
        }
        return self::categoryFromEvidence($base, $production, $branch);
    }

    /**
     * @param array<string,string> $values
     * @return ?array<string,string> Canon-style scalar evidence, or null for an opaque container.
     */
    private static function canonicalScalarGroup(array $values): ?array {
        $out = [];
        foreach ($values as $name => $raw) {
            if (!is_string($name) || !is_string($raw) || !self::isScalarJson($raw)) {
                return null;
            }
            try {
                // Do not use this encoding as materialization data. It is
                // evidence only; `values` and `spans` keep the exact tokens.
                $out[$name] = self::encode(json_decode($raw, true, 512, JSON_THROW_ON_ERROR));
            } catch (\Throwable) {
                return null;
            }
        }
        return $out;
    }

    private static function categoryFromEvidence(array $base, array $production, array $branch): string {
        if ($base === $production && $base === $branch) return 'unchanged';
        if ($base === $branch) return 'production-only';
        if ($base === $production) return 'branch-only';
        if ($production === $branch) return 'compatible';
        return 'conflicting';
    }

    private static function isScalarJson(string $raw): bool {
        $first = $raw[0] ?? '';
        return $first !== '{' && $first !== '[';
    }

    /** @return array{start:int,end:int} */
    private static function span(array $field): array {
        return ['start' => (int) $field['start'], 'end' => (int) $field['end']];
    }

    private static function recordSelector(string $id): string {
        if ($id === '') throw new \RuntimeException('refresh plan entry has no stable identity');
        return hash('sha256', "duo-refresh-field-record/v1\0" . $id);
    }

    private static function fieldSelector(string $recordSelector, string $field): string {
        return hash('sha256', "duo-refresh-field-selector/v1\0" . $recordSelector . "\0" . $field);
    }

    private static function choiceKey(string $recordSelector, string $fieldSelector): string {
        return $recordSelector . ':' . $fieldSelector;
    }

    private static function decodeString(string $raw): ?string {
        try {
            $value = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }
        return is_string($value) ? $value : null;
    }

    private static function postFieldAuthored(array $policy, string $postType, string $field): bool {
        foreach (['base', 'production', 'branch'] as $role) {
            $projection = $policy[$role] ?? null;
            if (!is_array($projection)) return false;
            $derived = $projection['derived_post_fields'][$postType] ?? null;
            if (!is_array($derived) || in_array($field, $derived, true)) return false;
        }
        return true;
    }

    /** @return array<string,array<string,mixed>> */
    private static function normalizePolicyProjections(array $input): array {
        $out = [];
        foreach (['base', 'production', 'branch'] as $role) {
            if (isset($input[$role]) && is_array($input[$role])) {
                try {
                    $out[$role] = self::normalizePolicyProjection($input[$role]);
                } catch (\Throwable) {
                    // Field mode requires all three complete projections; the
                    // caller refuses rather than inferring authored status.
                }
            }
        }
        return $out;
    }

    /** @return array<string,mixed> */
    public static function normalizePolicyProjection(array $projection): array {
        $keys = array_keys($projection);
        sort($keys, SORT_STRING);
        $expected = [
            'derived_post_fields', 'format', 'manifest_hash', 'projection_hash',
            'resolved_adapters_sha256', 'state_site_hash',
        ];
        sort($expected, SORT_STRING);
        if ($keys !== $expected || ($projection['format'] ?? null) !== self::POLICY_FORMAT
            || !is_array($projection['derived_post_fields'] ?? null)
            || !self::isHash($projection['projection_hash'] ?? null)
            || !self::isHash($projection['state_site_hash'] ?? null)
            || !self::isHash($projection['manifest_hash'] ?? null)
            || !self::isHash($projection['resolved_adapters_sha256'] ?? null)) {
            throw new \RuntimeException('field policy projection is malformed');
        }
        $basis = $projection;
        unset($basis['projection_hash']);
        if (!hash_equals((string) $projection['projection_hash'], self::hashDocument($basis, 'projection_hash'))) {
            throw new \RuntimeException('field policy projection hash does not verify');
        }
        $types = array_keys($basis['derived_post_fields']);
        $sortedTypes = $types;
        sort($sortedTypes, SORT_STRING);
        if ($types !== $sortedTypes) {
            throw new \RuntimeException('field policy projection post types are not canonical');
        }
        foreach ($basis['derived_post_fields'] as $type => $fields) {
            if (!is_string($type) || $type === '' || strlen($type) > 191 || str_contains($type, "\0")
                || !is_array($fields) || !array_is_list($fields)) {
                throw new \RuntimeException('field policy projection has an invalid derived-field set');
            }
            $sortedFields = $fields;
            sort($sortedFields, SORT_STRING);
            if ($fields !== array_values(array_unique($sortedFields, SORT_STRING))) {
                throw new \RuntimeException('field policy projection derived fields are not canonical');
            }
            foreach ($fields as $field) {
                if (!is_string($field) || !in_array($field, self::DERIVABLE_POST_FIELDS, true)) {
                    throw new \RuntimeException('field policy projection has an invalid derived field');
                }
            }
        }
        return $projection;
    }

    private static function assertDiff(array $diff): void {
        $keys = array_keys($diff);
        sort($keys, SORT_STRING);
        $expectedTop = [
            'algorithm', 'authority', 'choices', 'diff_hash', 'format', 'plan_hash',
            'policy_projection_hashes', 'production_snapshot_hash', 'records', 'redaction', 'roles', 'summary',
        ];
        sort($expectedTop, SORT_STRING);
        if ($keys !== $expectedTop
            || ($diff['format'] ?? null) !== self::DIFF_FORMAT
            || ($diff['algorithm'] ?? null) !== self::ALGORITHM
            || ($diff['authority'] ?? null) !== false
            || ($diff['redaction'] ?? null) !== 'values_omitted'
            || !self::isHash($diff['plan_hash'] ?? null)
            || !self::isHash($diff['production_snapshot_hash'] ?? null)
            || !self::isHash($diff['diff_hash'] ?? null)
            || ($diff['choices'] ?? null) !== ['ours' => 'branch', 'theirs' => 'production']
            || ($diff['roles'] ?? null) !== ['base' => 'merge_base', 'ours' => 'branch', 'theirs' => 'production']
            || !is_array($diff['records'] ?? null)
            || !array_is_list($diff['records'])) {
            throw new \RuntimeException('field diff is malformed');
        }
        $policyHashes = $diff['policy_projection_hashes'] ?? null;
        if (!is_array($policyHashes) || array_is_list($policyHashes)
            || array_keys($policyHashes) !== ['base', 'branch', 'production']) {
            throw new \RuntimeException('field diff policy evidence is malformed');
        }
        foreach ($policyHashes as $hash) {
            if (!self::isHash($hash)) throw new \RuntimeException('field diff policy evidence is malformed');
        }
        if (!hash_equals((string) $policyHashes['branch'], (string) $policyHashes['production'])) {
            throw new \RuntimeException('field diff policy evidence is not eligible for field resolution');
        }
        $summary = $diff['summary'] ?? null;
        if (!is_array($summary) || array_is_list($summary)
            || array_keys($summary) !== ['atomic_records', 'changes', 'conflicting_choices', 'records']) {
            throw new \RuntimeException('field diff summary is malformed');
        }
        foreach ($summary as $count) {
            if (!is_int($count) || $count < 0) throw new \RuntimeException('field diff summary is malformed');
        }

        $recordCount = 0;
        $atomicCount = 0;
        $changeCount = 0;
        $conflictCount = 0;
        $priorRecord = null;
        foreach ($diff['records'] as $record) {
            if (!is_array($record) || array_is_list($record)) {
                throw new \RuntimeException('field diff record is malformed');
            }
            $recordKeys = array_keys($record);
            sort($recordKeys, SORT_STRING);
            $expectedRecordKeys = ['changes', 'entity', 'mode', 'reason', 'record_selector_sha256'];
            sort($expectedRecordKeys, SORT_STRING);
            if ($recordKeys !== $expectedRecordKeys
                || !in_array($record['entity'] ?? null, self::PUBLIC_ENTITIES, true)
                || !in_array($record['mode'] ?? null, ['fields', 'record'], true)
                || !in_array($record['reason'] ?? null, self::PUBLIC_REASONS, true)
                || !self::isHash($record['record_selector_sha256'] ?? null)
                || !is_array($record['changes'] ?? null)
                || !array_is_list($record['changes'])
                || $record['changes'] === []) {
                throw new \RuntimeException('field diff record is malformed');
            }
            $recordSelector = (string) $record['record_selector_sha256'];
            if ($priorRecord !== null && $recordSelector <= $priorRecord) {
                throw new \RuntimeException('field diff records must be selector-sorted and unique');
            }
            $priorRecord = $recordSelector;
            if ($record['mode'] === 'fields' && $record['reason'] !== 'eligible_engine_fields') {
                throw new \RuntimeException('field diff field record reason is malformed');
            }
            if ($record['mode'] === 'fields' && !in_array($record['entity'], ['post', 'term'], true)) {
                throw new \RuntimeException('field diff field record entity is malformed');
            }
            if ($record['mode'] === 'record' && $record['reason'] === 'eligible_engine_fields') {
                throw new \RuntimeException('field diff atomic record reason is malformed');
            }
            $priorChange = null;
            foreach ($record['changes'] as $change) {
                if (!is_array($change) || array_is_list($change)) {
                    throw new \RuntimeException('field diff change is malformed');
                }
                $changeKeys = array_keys($change);
                sort($changeKeys, SORT_STRING);
                $expectedChangeKeys = $record['mode'] === 'record'
                    ? ['category', 'field', 'field_selector_sha256', 'hash_status', 'reason', 'record_selector_sha256', 'relation', 'scope']
                    : ['category', 'field', 'field_selector_sha256', 'hash_status', 'record_selector_sha256', 'relation', 'scope'];
                sort($expectedChangeKeys, SORT_STRING);
                if ($changeKeys !== $expectedChangeKeys
                    || !in_array($change['category'] ?? null, self::CHANGE_CATEGORIES, true)
                    || !self::isPublicField($change['field'] ?? null)
                    || !self::isHash($change['field_selector_sha256'] ?? null)
                    || ($change['hash_status'] ?? null) !== 'withheld'
                    || !self::isPublicRelation($change['relation'] ?? null)
                    || !hash_equals($recordSelector, (string) ($change['record_selector_sha256'] ?? ''))
                    || !hash_equals(
                        self::fieldSelector($recordSelector, (string) ($change['field'] ?? '')),
                        (string) ($change['field_selector_sha256'] ?? '')
                    )
                    || ($change['scope'] ?? null) !== ($record['mode'] === 'record' ? 'record' : 'field')) {
                    throw new \RuntimeException('field diff change is malformed');
                }
                if ($record['mode'] === 'record') {
                    if (($change['field'] ?? null) !== 'record'
                        || ($change['category'] ?? null) !== 'conflicting'
                        || ($change['reason'] ?? null) !== $record['reason']
                        || (($change['relation']['branch_vs_production'] ?? null) !== 'different')) {
                        throw new \RuntimeException('field diff atomic change is malformed');
                    }
                } elseif (($change['field'] ?? null) === 'record' || ($change['category'] ?? null) === 'unchanged'
                    || !str_starts_with((string) $change['field'], (string) $record['entity'] . '.')
                    || $change['relation'] !== self::fieldRelation((string) $change['category'])) {
                    throw new \RuntimeException('field diff field change is malformed');
                }
                $changeSelector = (string) $change['field_selector_sha256'];
                if ($priorChange !== null && $changeSelector <= $priorChange) {
                    throw new \RuntimeException('field diff changes must be selector-sorted and unique');
                }
                $priorChange = $changeSelector;
                $changeCount++;
                if (($change['category'] ?? null) === 'conflicting') $conflictCount++;
            }
            $recordCount++;
            if ($record['mode'] === 'record') $atomicCount++;
        }
        if ($summary !== [
            'atomic_records' => $atomicCount,
            'changes' => $changeCount,
            'conflicting_choices' => $conflictCount,
            'records' => $recordCount,
        ]) {
            throw new \RuntimeException('field diff summary does not match records');
        }
        $basis = $diff;
        unset($basis['diff_hash']);
        if (!hash_equals((string) $diff['diff_hash'], self::hashDocument($basis, 'diff_hash'))) {
            throw new \RuntimeException('field diff hash does not verify');
        }
    }

    private static function isPublicField(mixed $field): bool {
        return is_string($field) && ($field === 'record'
            || isset(self::POST_GROUPS[$field]) || isset(self::TERM_GROUPS[$field]));
    }

    private static function isPublicRelation(mixed $relation): bool {
        if (!is_array($relation) || array_is_list($relation)
            || array_keys($relation) !== self::RELATION_KEYS) {
            return false;
        }
        foreach (['base', 'branch', 'production'] as $role) {
            if (!in_array($relation[$role] ?? null, self::ROLE_STATES, true)) return false;
        }
        foreach (['branch_vs_base', 'branch_vs_production', 'production_vs_base'] as $comparison) {
            if (!in_array($relation[$comparison] ?? null, ['same', 'different'], true)) return false;
        }
        foreach ([
            ['branch', 'base', 'branch_vs_base'],
            ['branch', 'production', 'branch_vs_production'],
            ['production', 'base', 'production_vs_base'],
        ] as [$left, $right, $comparison]) {
            if ($relation[$comparison] === 'same' && $relation[$left] !== $relation[$right]) {
                return false;
            }
            if ($relation[$left] === 'absent' && $relation[$right] === 'absent'
                && $relation[$comparison] !== 'same') {
                return false;
            }
        }
        $samePairs = 0;
        foreach (['branch_vs_base', 'branch_vs_production', 'production_vs_base'] as $comparison) {
            if ($relation[$comparison] === 'same') $samePairs++;
        }
        // Equality over B/P/W is transitive: three roles can have no equal
        // pair, one equal pair, or all three equal pairs, never exactly two.
        if ($samePairs === 2) return false;
        return true;
    }

    private static function isHash(mixed $value): bool {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1;
    }

    /** Canonical bytes without importing a decoded source document. */
    private static function hashDocument(array $value, string $excluded): string {
        unset($value[$excluded]);
        return hash('sha256', self::encode($value));
    }

    private static function encode(mixed $value): string {
        if (class_exists(\Duo\Canon::class)) {
            return \Duo\Canon::encode($value);
        }
        $encoded = json_encode(self::canonicalize($value), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        return $encoded . "\n";
    }

    private static function canonicalize(mixed $value): mixed {
        if (!is_array($value)) return $value;
        if (array_is_list($value)) return array_map([self::class, 'canonicalize'], $value);
        ksort($value, SORT_STRING);
        foreach ($value as $key => $child) $value[$key] = self::canonicalize($child);
        return $value;
    }
}
