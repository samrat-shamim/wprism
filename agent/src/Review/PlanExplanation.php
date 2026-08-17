<?php
namespace Duo;

/**
 * Stable, value-free explanation of one entity row in a freshly-observed plan.
 *
 * This is a projection only. Apply owns planning, exact rebuild-surface
 * derivation, and action selection; Policy and ReferenceGraph own adapter
 * semantics and declared dependency edges. Keeping those decisions outside
 * this class prevents an explanation from becoming a second planner.
 */
final class PlanExplanation {
    public const FORMAT = 'duo-explain/v1';

    /** @var list<string> */
    private const BUCKETS = [
        'create', 'update', 'adopt', 'unchanged', 'drift', 'conflict',
        'collision', 'delete', 'delete_conflict', 'deleted',
    ];

    /**
     * Resolve a selector against exactly one current plan row.
     *
     * The key is the row's canonical identity (`uuid` in the established plan
     * schema). Synthetic identities such as options/core, sidebar/<id>, and
     * the SHA-256 user-meta key are first-class; no login or canonical value is
     * accepted as a selector.
     *
     * @return array{bucket:string,key:string,row:array<string,mixed>}
     */
    public static function select(array $plan, string $selector): array {
        [$bucket, $key] = self::parse($selector);
        $matches = [];
        $hashedKey = preg_match('/^sha256:([0-9a-f]{64})$/D', $key, $m) === 1 ? $m[1] : null;
        foreach ((array) ($plan[$bucket] ?? []) as $row) {
            $rowKey = is_array($row) ? (string) ($row['uuid'] ?? '') : '';
            if ($rowKey !== '' && ($rowKey === $key
                || ($hashedKey !== null && hash_equals($hashedKey, hash('sha256', $rowKey))))) {
                $matches[] = $row;
            }
        }
        if ($matches === []) {
            throw new CommandRefusalException(
                'plan_action_not_found',
                'the selected entity action is not present in the current plan',
                'rerun plan, copy the current bucket and entity key, then rerun explain',
                [[
                    'code' => 'plan_action_not_found',
                    'message' => 'the selector did not resolve against the freshly-observed plan',
                    'remediation' => 'do not reuse a selector from a different repository or target state',
                ]],
                'duo: explain selector did not match a current plan row'
            );
        }
        if (count($matches) !== 1) {
            throw new CommandRefusalException(
                'plan_action_ambiguous',
                'the selected entity action is not unique in the current plan',
                'repair the duplicate plan identity before relying on an explanation',
                [[
                    'code' => 'plan_action_ambiguous',
                    'message' => 'more than one current plan row has the selected identity',
                    'remediation' => 'repair repository or identity state so the plan has one canonical owner',
                ]],
                'duo: explain selector matched more than one current plan row'
            );
        }
        return [
            'bucket' => $bucket,
            'key' => (string) ($matches[0]['uuid'] ?? ''),
            'row' => $matches[0],
        ];
    }

    /** @return array{0:string,1:string} */
    public static function parse(string $selector): array {
        if (!str_contains($selector, ':')) {
            throw self::invalidSelector();
        }
        [$bucket, $key] = explode(':', $selector, 2);
        if (!in_array($bucket, self::BUCKETS, true)) {
            if (preg_match('/^[a-z][a-z0-9_]{1,63}$/D', $bucket) === 1) {
                throw new CommandRefusalException(
                    'unsupported_plan_selector',
                    'the selected plan section is not an entity action explain can trace',
                    'use plan/status for global code, lifecycle, environment, or capability findings; select an entity row from a supported plan bucket',
                    [[
                        'code' => 'unsupported_plan_selector',
                        'message' => 'explain accepts entity action rows only',
                        'remediation' => 'select create, update, adopt, unchanged, drift, conflict, collision, delete, delete_conflict, or deleted',
                    ]],
                    'duo: explain selector names an unsupported plan bucket'
                );
            }
            throw self::invalidSelector();
        }
        // Canonical entity keys are opaque. In particular, a registered
        // sidebar id is not constrained to the UUID/options/user-meta
        // spellings most rows use. Never reinterpret the key as a path: cap
        // bytes, require valid UTF-8, reject controls, then compare it exactly
        // with the in-memory plan row.
        if ($key === '' || strlen($key) > 256 || preg_match('//u', $key) !== 1
            || preg_match('/[\x00-\x1F\x7F]/', $key) === 1) {
            throw self::invalidSelector();
        }
        return [$bucket, $key];
    }

    /**
     * @param list<string> $surfaces exact Apply-derived surfaces for this row
     * @param list<array<string,mixed>> $actions Policy::actions_for($surfaces)
     * @return array<string,mixed>
     */
    public static function build(
        string $selector,
        string $bucket,
        array $row,
        CompiledRepository $compiled,
        Policy $policy,
        array $surfaces,
        array $actions,
        array $planOptions = []
    ): array {
        $key = (string) ($row['uuid'] ?? '');
        $tree = $compiled->tree();
        $deletions = $compiled->deletions();
        $isDeletion = in_array($bucket, ['delete', 'delete_conflict', 'deleted'], true);
        $entity = !$isDeletion && is_array($tree[$key] ?? null) ? $tree[$key] : null;
        $deletion = $isDeletion && is_array($deletions[$key] ?? null) ? $deletions[$key] : null;
        if ($entity === null && $deletion === null) {
            throw new CommandRefusalException(
                'plan_action_source_missing',
                'the selected plan action has no matching compiled source record',
                'recompile the repository and rerun plan before requesting an explanation',
                [[
                    'code' => 'plan_action_source_missing',
                    'message' => 'the plan row and immutable compiled repository disagree',
                    'remediation' => 'discard stale plan output and explain only a freshly-compiled current row',
                ]],
                'duo: explain selector has no compiled entity or tombstone source'
            );
        }

        $source = self::source($key, $entity, $deletion);
        $rules = self::rules($row, $entity, $deletion, $policy, $compiled);
        $references = self::references($key, $tree, $policy);
        $actionRows = self::actions($actions, $surfaces);
        $verification = self::verification($bucket, $source, $actionRows, $entity, $compiled);

        return [
            'format' => self::FORMAT,
            'ok' => true,
            'selector' => [
                'bucket' => $bucket,
                'entity_identity_sha256' => hash('sha256', $key),
                'copyable' => self::selectorForOutput($bucket, $key),
            ],
            'basis' => [
                'artifact_sha256' => $compiled->artifact_hash(),
                'revision_sha256' => $compiled->revision_hash(),
                'manifest_sha256' => $compiled->manifest_hash(),
                'site_sha256' => $compiled->site_hash(),
                'plan_options' => $planOptions,
            ],
            'action' => self::action($bucket, $row, $source),
            'source' => $source,
            'rules' => $rules,
            'references' => $references,
            'execution' => [
                'mutation' => self::mutation($bucket, $row),
                'rebuild_surfaces' => array_values($surfaces),
                'actions' => $actionRows,
                'provider_negotiation' => 'not_performed',
                'action_invocation' => 'not_performed',
            ],
            'verification' => $verification,
            'redaction' => [
                'canonical_values' => 'omitted',
                'target_local_ids' => 'omitted',
                'provider_arguments' => 'omitted',
                'operator_exception_detail' => 'omitted',
            ],
        ];
    }

    /** @return list<string> */
    public static function render(array $report): array {
        if (($report['format'] ?? null) !== self::FORMAT) {
            throw new \RuntimeException('duo: cannot render an unsupported explanation format');
        }
        $action = (array) ($report['action'] ?? []);
        $source = (array) ($report['source'] ?? []);
        $mutation = (array) ($report['execution']['mutation'] ?? []);
        $lines = [
            'EXPLAIN ' . strtoupper((string) ($action['bucket'] ?? '?'))
                . ' ' . (string) ($action['entity']['path'] ?? 'unknown'),
            '  selector: ' . (string) ($report['selector']['copyable'] ?? ''),
            '  source: ' . (string) ($source['kind'] ?? 'unknown')
                . ' ' . (string) ($source['path'] ?? 'unknown')
                . ' (' . (string) ($source['content_binding'] ?? 'unbound') . ')',
            '  intent: ' . (string) ($mutation['intent'] ?? 'none')
                . ' (' . (string) ($mutation['apply_eligibility'] ?? 'unknown') . ')',
        ];
        foreach ((array) ($report['rules'] ?? []) as $rule) {
            $lines[] = '  rule: ' . (string) ($rule['role'] ?? 'state') . ' '
                . (string) ($rule['locator'] ?? '?') . ' => '
                . (string) ($rule['classification'] ?? 'unknown') . ' ('
                . implode(', ', (array) ($rule['declared_by'] ?? [])) . ')';
        }
        $edges = (array) ($report['references']['edges'] ?? []);
        if ($edges === []) {
            $lines[] = '  references: none declared for this canonical entity';
        } else {
            $lines[] = '  references: ' . count($edges) . ' declared edge(s)';
            foreach ($edges as $edge) {
                $lines[] = '    ' . strtoupper((string) ($edge['direction'] ?? '?')) . ' '
                    . (string) ($edge['relation'] ?? 'reference') . ' '
                    . (string) ($edge['locator'] ?? '?') . ' -> '
                    . (string) ($edge['other_kind'] ?? 'entity') . ' sha256:'
                    . substr((string) ($edge['other_identity_sha256'] ?? ''), 0, 12);
            }
        }
        $surfaces = (array) ($report['execution']['rebuild_surfaces'] ?? []);
        $lines[] = '  rebuild surfaces: ' . ($surfaces === [] ? 'none' : implode(', ', $surfaces));
        $actions = (array) ($report['execution']['actions'] ?? []);
        if ($actions === []) {
            $lines[] = '  structured actions: none selected by this row';
        } else {
            foreach ($actions as $decl) {
                $lines[] = '  structured action: ' . (string) ($decl['source'] ?? '?')
                    . ' (' . (string) ($decl['trigger_mode'] ?? 'unknown')
                    . '; declared, availability not checked — apply negotiates before writes)';
            }
        }
        foreach ((array) ($report['verification'] ?? []) as $verify) {
            $lines[] = '  verify: ' . (string) ($verify['verifier'] ?? '?')
                . ' (' . (string) ($verify['when'] ?? 'not_scheduled') . ')';
        }
        $lines[] = '  values: omitted (hashes, policy coordinates, and declared edges only)';
        return $lines;
    }

    private static function invalidSelector(): CommandRefusalException {
        return new CommandRefusalException(
            'invalid_plan_selector',
            'the explain selector is malformed',
            'use <bucket>:<entity-key> from the current plan JSON; do not supply paths, values, or target-local ids',
            [[
                'code' => 'invalid_plan_selector',
                'message' => 'the selector must contain one supported bucket and one bounded canonical entity key',
                'remediation' => 'copy the row bucket and uuid from a freshly-observed plan',
            ]],
            'duo: explain selector must be <bucket>:<entity-key>'
        );
    }

    /** Safe, copyable selector for human output and integrations. */
    public static function selectorForOutput(string $bucket, string $key): string {
        return $bucket . ':sha256:' . hash('sha256', $key);
    }

    /** @return array<string,mixed> */
    private static function source(string $key, ?array $entity, ?array $deletion): array {
        if ($deletion !== null) {
            return [
                'kind' => 'canonical_tombstone',
                'path' => 'deletions/<identity>.json',
                'identity_sha256' => hash('sha256', $key),
                'content_binding' => 'compiled_artifact',
            ];
        }
        $type = (string) ($entity['type'] ?? 'unknown');
        $data = (array) ($entity['data'] ?? []);
        $path = match ($type) {
            'post' => 'posts/' . self::safeName((string) ($data['type'] ?? 'unknown')) . '/<identity>.md',
            'term' => 'terms/' . self::safeName((string) ($data['taxonomy'] ?? 'unknown')) . '/<identity>.json',
            'menu' => 'menus/<identity>.json',
            'options' => 'options/core.json',
            'user-meta' => 'user-meta/<identity>.json',
            'sidebar' => 'sidebars/<identity>.json',
            default => 'tables/' . self::safeName($type) . '/<identity>.json',
        };
        return [
            'kind' => 'canonical_entity',
            'path' => $path,
            'identity_sha256' => hash('sha256', $key),
            'content_binding' => 'compiled_artifact',
        ];
    }

    /** @return array<string,mixed> */
    private static function action(string $bucket, array $row, array $source): array {
        $type = (string) ($row['type'] ?? ($row['deletion_type'] ?? 'unknown'));
        return [
            'bucket' => $bucket,
            'reason_code' => self::reasonCode($bucket, $row),
            'entity' => [
                'type' => self::safeName($type),
                'path' => (string) $source['path'],
                'identity_sha256' => (string) $source['identity_sha256'],
            ],
        ];
    }

    /** @return array<string,mixed> */
    private static function mutation(string $bucket, array $row): array {
        $eligibility = match ($bucket) {
            'create', 'update', 'adopt' => 'eligible',
            'delete' => 'explicit_flags_required',
            'unchanged', 'deleted' => 'noop',
            default => 'blocked',
        };
        $intent = match ($bucket) {
            'create' => 'create_target_authored_state',
            'update', 'adopt' => 'converge_target_authored_state',
            'delete', 'delete_conflict', 'deleted' => 'delete_target_authored_state',
            default => 'preserve_target_state',
        };
        $required = [];
        if ($bucket === 'delete') {
            $required[] = '--with-deletes';
        }
        if (in_array($bucket, ['conflict', 'delete_conflict'], true)) {
            foreach ((array) ($row['conflict_view']['choices'] ?? []) as $choice) {
                if (($choice['id'] ?? null) === 'apply_repository') {
                    foreach ((array) ($choice['requires'] ?? []) as $flag) {
                        if (is_string($flag) && preg_match('/^--[a-z][a-z0-9-]*$/D', $flag) === 1) {
                            $required[] = $flag;
                        }
                    }
                }
            }
        }
        $guarded = isset($row['blocked']) || !empty($row['guard_refs']);
        if ($guarded && in_array($bucket, ['delete', 'delete_conflict'], true)) {
            $required[] = '--force-delete-referenced';
        }
        $required = array_values(array_unique($required));
        return [
            'intent' => $intent,
            'apply_eligibility' => $eligibility,
            'required_flags' => $required,
            'guarded' => $guarded,
            'guard_count' => count((array) ($row['guard_refs'] ?? [])),
        ];
    }

    private static function reasonCode(string $bucket, array $row): string {
        $fromConflict = (string) ($row['conflict_view']['reason_code'] ?? '');
        if ($fromConflict !== '' && preg_match('/^[a-z][a-z0-9_]{2,95}$/D', $fromConflict) === 1) {
            return $fromConflict;
        }
        return match ($bucket) {
            'create' => 'target_absent',
            'update' => !empty($row['retry']) ? 'incomplete_apply_retry' : 'target_needs_repository_state',
            'adopt' => 'natural_identity_adoption_selected',
            'unchanged' => 'target_matches_repository',
            'drift' => 'target_changed_repository_unchanged',
            'conflict' => 'repository_target_conflict',
            'collision' => 'unmanaged_natural_identity_collision',
            'delete' => 'tombstone_matches_target_base',
            'delete_conflict' => 'tombstone_target_conflict',
            'deleted' => 'tombstone_target_already_absent',
            default => 'unknown_plan_action',
        };
    }

    /** @return list<array<string,mixed>> */
    private static function rules(
        array $row,
        ?array $entity,
        ?array $deletion,
        Policy $policy,
        CompiledRepository $compiled
    ): array {
        $rules = [];
        $append = static function (
            string $role,
            string $locator,
            string $classification,
            array $declaredBy,
            array $details = []
        ) use (&$rules, $compiled): void {
            $declaredBy = array_values(array_unique(array_filter(array_map('strval', $declaredBy))));
            sort($declaredBy, SORT_STRING);
            $entry = [
                'role' => $role,
                'locator' => self::publicCoordinate($locator),
                'classification' => self::safeName($classification),
                'declared_by' => $declaredBy === [] ? ['repo-format'] : $declaredBy,
                'effective' => true,
            ];
            $adapters = self::adaptersFor($entry['declared_by'], $compiled);
            if ($adapters !== []) {
                $entry['adapters'] = $adapters;
            }
            if ($details !== []) {
                $entry['details'] = $details;
            }
            $rules[] = $entry;
        };

        if ($deletion !== null) {
            $kind = (string) ($deletion['data']['kind'] ?? $row['deletion_kind'] ?? '');
            $type = (string) ($deletion['data']['type'] ?? $row['deletion_type'] ?? '');
            $selector = Deletion::selector($kind, $type);
            $capability = $policy->deletion_capability($selector) ?? [];
            $cascades = array_values(array_map('strval', (array) ($capability['cascades'] ?? [])));
            sort($cascades, SORT_STRING);
            $append(
                'deletion',
                'deletions.' . self::safeName($selector),
                'authored_tombstone',
                (array) ($capability['declared_by'] ?? []),
                ['cascades' => $cascades, 'guard_declarations' => count((array) ($capability['guards'] ?? []))]
            );
            return $rules;
        }

        $type = (string) ($entity['type'] ?? '');
        $data = (array) ($entity['data'] ?? []);
        if ($type === 'post') {
            $postType = (string) ($data['type'] ?? '');
            $details = $policy->post_type_rule_details($postType);
            if ($details['rule'] === null && in_array($postType, (array) ($policy->site['policy']['post_types'] ?? []), true)) {
                $details = ['rule' => ['class' => 'authored'], 'source' => 'site.duo.json'];
            }
            $append('entity', 'post_types.' . self::safeName($postType),
                (string) ($details['rule']['class'] ?? 'authored'), [(string) ($details['source'] ?? 'repo-format')]);
            $meta = (array) ($data['meta'] ?? []);
            foreach (array_keys($meta) as $key) {
                $d = $policy->meta_rule_details_for_post((string) $key, $meta);
                $append('field', 'post_meta.' . self::safeName((string) $key),
                    (string) ($d['rule']['class'] ?? 'authored'), [(string) ($d['source'] ?? 'repo-format')]);
            }
            foreach (array_keys(Policy::DERIVABLE_FIELD_COLUMNS) as $field) {
                if (!array_key_exists($field, $data)) {
                    continue;
                }
                $d = $policy->field_rule_details($postType, $field);
                if (($d['class'] ?? 'authored') !== 'authored' || ($d['source'] ?? 'repo-format') !== 'repo-format') {
                    $append('field', 'post_fields.' . self::safeName($field),
                        (string) ($d['class'] ?? 'authored'), [(string) ($d['source'] ?? 'repo-format')]);
                }
            }
        } elseif ($type === 'term') {
            $taxonomy = (string) ($data['taxonomy'] ?? '');
            $details = $policy->taxonomy_rule_details($taxonomy);
            if ($details['rule'] === null && in_array($taxonomy, (array) ($policy->site['policy']['taxonomies'] ?? []), true)) {
                $details = ['rule' => ['class' => 'authored'], 'source' => 'site.duo.json'];
            }
            $append('entity', 'taxonomies.' . self::safeName($taxonomy),
                (string) ($details['rule']['class'] ?? 'authored'), [(string) ($details['source'] ?? 'repo-format')]);
            $meta = (array) ($data['meta'] ?? []);
            foreach (array_keys($meta) as $key) {
                $d = $policy->meta_rule_details_for_term((string) $key, $meta);
                $append('field', 'term_meta.' . self::safeName((string) $key),
                    (string) ($d['rule']['class'] ?? 'authored'), [(string) ($d['source'] ?? 'repo-format')]);
            }
        } elseif ($type === 'menu') {
            $append('entity', 'menus', 'authored', ['repo-format']);
            foreach (['locations'] as $field) {
                if (!array_key_exists($field, $data)) {
                    continue;
                }
                $d = $policy->menu_field_rule_details($field);
                $append('field', 'menu_fields.' . $field,
                    (string) ($d['rule']['class'] ?? 'authored'), [(string) ($d['source'] ?? 'repo-format')]);
            }
        } elseif ($type === 'options') {
            $append('entity', 'options', 'authored_document', ['repo-format']);
            $names = array_values(array_unique(array_merge(
                (array) ($row['rebuild_option_names'] ?? []),
                (array) ($row['option_deletes'] ?? [])
            )));
            sort($names, SORT_STRING);
            $values = OptionState::classification_values($data);
            foreach ($names as $name) {
                $name = (string) $name;
                $d = str_contains($name, '{{')
                    ? $policy->canonical_option_name_ref_details($name)
                    : $policy->option_rule_details_for_option($name, $values);
                if ($d['rule'] === null) {
                    $dynamic = $policy->dynamic_option_rule_for_prefix($name);
                    if ($dynamic !== null) {
                        $d = ['rule' => $dynamic, 'source' => 'pinned manifest dynamic_options'];
                    }
                }
                $append('record', 'options.' . self::safeName($name),
                    (string) ($d['rule']['class'] ?? 'authored'), [(string) ($d['source'] ?? 'repo-format')]);
            }
        } elseif ($type === 'user-meta') {
            $append('entity', 'user_meta_document', 'authored', ['repo-format']);
            $meta = (array) ($data['meta'] ?? []);
            foreach (array_keys($meta) as $key) {
                $d = $policy->meta_rule_details_for_user((string) $key, $meta);
                $append('field', 'user_meta.' . self::safeName((string) $key),
                    (string) ($d['rule']['class'] ?? 'authored'), [(string) ($d['source'] ?? 'repo-format')]);
            }
        } elseif ($type === SidebarState::ENTITY_TYPE) {
            $append('entity', 'sidebars', 'authored', ['repo-format']);
            $widgetTypes = [];
            foreach ((array) ($data['widgets'] ?? []) as $widget) {
                $widgetTypes[(string) ($widget['type'] ?? '')] = true;
            }
            ksort($widgetTypes, SORT_STRING);
            foreach (array_keys($widgetTypes) as $widgetType) {
                $d = $policy->widget_type_rule_details($widgetType);
                $append('record', 'widgets.' . self::safeName($widgetType), 'authored',
                    [(string) ($d['source'] ?? 'repo-format')]);
            }
        } else {
            $d = $policy->declared_table_details($type);
            $append('entity', 'tables.' . self::safeName($type),
                (string) ($d['rule']['class'] ?? 'authored_snapshot'), [(string) ($d['source'] ?? 'repo-format')]);
        }

        usort($rules, static fn(array $a, array $b): int => strcmp(
            implode("\0", [$a['role'], $a['locator'], implode(',', $a['declared_by'])]),
            implode("\0", [$b['role'], $b['locator'], implode(',', $b['declared_by'])])
        ));
        return $rules;
    }

    /** @return list<array<string,mixed>> */
    private static function adaptersFor(array $sources, CompiledRepository $compiled): array {
        $wanted = [];
        foreach ($sources as $source) {
            $name = preg_replace('/\s+\(interpreter [^)]+\)$/', '', (string) $source);
            if (is_string($name) && $name !== '') {
                $wanted[$name] = true;
            }
        }
        $out = [];
        foreach ($compiled->resolved_adapters() as $adapter) {
            $name = (string) ($adapter['name'] ?? '');
            if (!isset($wanted[$name])) {
                continue;
            }
            $row = ['manifest' => $name];
            foreach (['plugin', 'theme'] as $kind) {
                if (is_string($adapter[$kind] ?? null) && $adapter[$kind] !== '') {
                    $row[$kind] = (string) $adapter[$kind];
                }
            }
            foreach (['version_range', 'theme_version_range'] as $range) {
                if (is_array($adapter[$range] ?? null)) {
                    $row[$range] = [
                        'min' => (string) ($adapter[$range]['min'] ?? ''),
                        'max' => (string) ($adapter[$range]['max'] ?? ''),
                    ];
                }
            }
            $out[] = $row;
        }
        usort($out, static fn(array $a, array $b): int => strcmp($a['manifest'], $b['manifest']));
        return $out;
    }

    /** @return array{status:string,edges:list<array<string,mixed>>} */
    private static function references(string $key, array $tree, Policy $policy): array {
        $owners = ReferenceGraph::owners($tree);
        $edges = [];
        foreach (ReferenceGraph::edges($tree, $policy) as $edge) {
            $from = (string) ($edge['from'] ?? '');
            $target = (string) ($edge['target'] ?? '');
            $fromOwner = (string) ($owners[$from]['entity'] ?? $from);
            $targetOwner = (string) ($owners[$target]['entity'] ?? $target);
            if ($fromOwner !== $key) {
                continue;
            }
            $other = $targetOwner;
            $otherKind = (string) ($owners[$target]['kind'] ?? ($tree[$targetOwner]['type'] ?? 'unknown'));
            $edges[] = [
                'direction' => 'outbound',
                'relation' => self::safeName((string) ($edge['relation'] ?? 'reference')),
                'locator' => self::publicCoordinate((string) ($edge['locator'] ?? '$')),
                'check' => self::safeName((string) ($edge['check'] ?? 'none')),
                'other_kind' => self::safeName($otherKind),
                'other_identity_sha256' => hash('sha256', $other),
                'declared_by' => 'compiled_reference_graph',
            ];
        }
        usort($edges, static fn(array $a, array $b): int => strcmp(
            implode("\0", [$a['direction'], $a['relation'], $a['locator'], $a['other_identity_sha256']]),
            implode("\0", [$b['direction'], $b['relation'], $b['locator'], $b['other_identity_sha256']])
        ));
        return ['status' => $edges === [] ? 'none_declared' : 'declared', 'edges' => $edges];
    }

    /** @param list<array<string,mixed>> $actions @param list<string> $surfaces */
    private static function actions(array $actions, array $surfaces): array {
        $surfaceSet = array_fill_keys($surfaces, true);
        $out = [];
        foreach ($actions as $action) {
            $triggers = array_values(array_map('strval', (array) ($action['triggers'] ?? [])));
            $unscoped = !array_key_exists('triggers', $action);
            $matched = $unscoped
                ? $surfaces
                : array_values(array_filter($triggers, static fn(string $s): bool => isset($surfaceSet[$s])));
            sort($matched, SORT_STRING);
            if (!$unscoped && $matched === []) {
                continue;
            }
            $index = (int) ($action['index'] ?? 0);
            $row = [
                'kind' => self::safeName((string) ($action['kind'] ?? 'unknown')),
                'manifest' => self::safeName((string) ($action['manifest'] ?? '?')),
                'declaration_index' => $index,
                'source' => self::actionSource($action),
                'trigger_mode' => $unscoped ? 'unscoped' : 'exact',
                'matched_surfaces' => $matched,
                'readiness' => 'not_checked',
            ];
            if (($action['kind'] ?? null) === 'native') {
                $row['native_action'] = self::safeName((string) ($action['action'] ?? '?'));
                $row['verification'] = 'native_value_readback_receipt';
            } elseif (($action['kind'] ?? null) === 'provider') {
                $row['provider'] = self::safeName((string) ($action['provider'] ?? '?'));
                $row['capability'] = self::safeName((string) ($action['capability'] ?? '?'));
                $row['verification'] = 'provider_verified_value_readback_receipt';
            }
            $out[] = $row;
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    private static function verification(
        string $bucket,
        array $source,
        array $actions,
        ?array $entity,
        CompiledRepository $compiled
    ): array {
        $mutating = in_array($bucket, ['create', 'update', 'adopt', 'delete'], true);
        $out = [];
        if (in_array($bucket, ['delete', 'delete_conflict', 'deleted'], true)) {
            $out[] = [
                'verifier' => 'canonical_target_absence/v1',
                'when' => $bucket === 'delete' ? 'after_authorized_mutation' : 'not_scheduled',
            ];
        } else {
            $out[] = [
                'verifier' => 'canonical-recapture/v1',
                'when' => $mutating ? 'after_required_rebuild_actions' : 'not_scheduled',
            ];
        }
        foreach ($actions as $action) {
            $out[] = [
                'verifier' => (string) ($action['verification'] ?? 'value_readback_receipt'),
                'action_source' => (string) ($action['source'] ?? '?'),
                'when' => 'during_authorized_rebuild',
            ];
        }
        if (($entity['type'] ?? null) === 'post' && ($entity['data']['type'] ?? null) === 'attachment') {
            foreach ($compiled->uploads_inventory() as $media) {
                if ((string) ($media['attachment_uuid'] ?? '') !== (string) ($entity['data']['uuid'] ?? '')) {
                    continue;
                }
                $out[] = [
                    'verifier' => 'compiled_upload_inventory_readback/v1',
                    'when' => $mutating ? 'after_attachment_metadata_rebuild' : 'not_scheduled',
                ];
            }
        }
        return $out;
    }

    private static function safeName(string $value): string {
        if ($value !== '' && preg_match('/^[A-Za-z0-9_][A-Za-z0-9._:\/-]{0,191}$/D', $value) === 1
            && !CommandRefusalException::containsSensitivePublicDetail($value)) {
            return $value;
        }
        return 'sha256:' . hash('sha256', $value);
    }

    private static function actionSource(array $action): string {
        if (($action['kind'] ?? null) === 'native') {
            return 'native:' . self::safeName((string) ($action['action'] ?? '?'));
        }
        if (($action['kind'] ?? null) === 'provider') {
            return 'provider:' . self::safeName((string) ($action['provider'] ?? '?'))
                . '/' . self::safeName((string) ($action['capability'] ?? '?'));
        }
        return 'unknown';
    }

    private static function publicCoordinate(string $value): string {
        if ($value !== '' && !CommandRefusalException::containsSensitivePublicDetail($value)) {
            return $value;
        }
        return 'sha256:' . hash('sha256', $value);
    }
}
