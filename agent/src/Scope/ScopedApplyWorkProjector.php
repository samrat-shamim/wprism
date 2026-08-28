<?php
namespace Duo;

if (!class_exists(CompiledRepository::class, false)) {
    require_once __DIR__ . '/../Repository/CompiledArtifact.php';
}
require_once __DIR__ . '/ScopedApplySession.php';
require_once __DIR__ . '/../Kernel/OptionState.php';

require_once __DIR__ . '/../Apply/ApplyPlanner.php';
require_once __DIR__ . '/../Apply/ConvergenceVerifier.php';
require_once __DIR__ . '/ScopedApply.php';

/** Reconstructs the immutable scoped work selection during crash recovery. */
final class ScopedApplyWorkProjector {
    /**
     * @return array{work:list<array<string,mixed>>,delete_work:list<array<string,mixed>>,rebuild_delete_work:list<array<string,mixed>>}
     */
    public static function project(
        array $plan,
        CompiledRepository $compiled,
        ScopedApplySession $session,
        ?array $scopeContract,
        ApplyPlanner $planner
    ): array {
        $selection = (array) ($session->authority()['selection'] ?? []);
        $planRows = [];
        foreach (['create', 'adopt', 'update', 'unchanged', 'drift', 'conflict'] as $bucket) {
            foreach ((array) ($plan[$bucket] ?? []) as $row) {
                $planRows[(string) ($row['uuid'] ?? '')] = $row;
            }
        }

        $treeByHash = [];
        foreach ($compiled->tree() as $identity => $entity) {
            $treeByHash[hash('sha256', (string) $identity)] = [(string) $identity, $entity];
        }
        if (ScopedApply::has_record_scoped_options($scopeContract)) {
            $options = $compiled->tree()['options/core'] ?? null;
            if (is_array($options)) {
                foreach (ScopedApply::selected_option_records($options['data'], $scopeContract) as $name => $record) {
                    $treeByHash[hash('sha256', 'options/core#' . $name)] = [
                        'options/core', $options, 'option:' . $name, $record,
                    ];
                }
            }
        }

        $work = [];
        $optionRecoveryNames = [];
        foreach ((array) ($selection['work_items'] ?? []) as $item) {
            $resolved = $treeByHash[(string) ($item['identity_hash'] ?? '')] ?? null;
            if (!is_array($resolved)) {
                throw new \RuntimeException('duo: scoped recovery work identity is absent from the frozen artifact');
            }
            [$identity, $entity] = $resolved;
            $isOptionRecord = count($resolved) === 4;
            $desiredHash = $isOptionRecord
                ? OptionState::record_hash((array) $resolved[3])
                : ConvergenceVerifier::hash($entity);
            $type = $isOptionRecord ? 'option' : (string) ($entity['type'] ?? '');
            if (!hash_equals((string) ($item['type'] ?? ''), $type)
                || !hash_equals((string) ($item['desired_hash'] ?? ''), $desiredHash)) {
                throw new \RuntimeException('duo: scoped recovery work identity no longer matches its authority');
            }
            if ($isOptionRecord) {
                $optionRecoveryNames[] = substr((string) $resolved[2], strlen('option:'));
                continue;
            }
            $work[] = $planRows[$identity] ?? [
                'uuid' => $identity,
                'type' => (string) ($entity['type'] ?? ''),
                'path' => (string) ($entity['path'] ?? ''),
                'retry' => true,
            ];
        }
        if ($optionRecoveryNames !== []) {
            $work[] = ScopedApply::recovery_option_row(
                $planRows['options/core'] ?? null,
                $optionRecoveryNames,
                (array) ($compiled->tree()['options/core'] ?? [])
            );
        }
        usort($work, fn(array $a, array $b): int =>
            $planner->phase2_rank($compiled->tree()[(string) $a['uuid']])
                <=> $planner->phase2_rank($compiled->tree()[(string) $b['uuid']])
        );

        $deletionPlanRows = [];
        foreach (['delete', 'delete_conflict', 'deleted'] as $bucket) {
            foreach ((array) ($plan[$bucket] ?? []) as $row) {
                $deletionPlanRows[(string) ($row['uuid'] ?? '')] = $row;
            }
        }
        $deletionsByHash = [];
        foreach ($compiled->deletions() as $identity => $deletion) {
            $deletionsByHash[hash('sha256', (string) $identity)] = [(string) $identity, $deletion];
        }
        $deleteWork = [];
        foreach ((array) ($selection['deletion_items'] ?? []) as $item) {
            $resolved = $deletionsByHash[(string) ($item['identity_hash'] ?? '')] ?? null;
            if (!is_array($resolved)) {
                throw new \RuntimeException('duo: scoped recovery deletion identity is absent from the frozen artifact');
            }
            [$identity, $deletion] = $resolved;
            $data = (array) ($deletion['data'] ?? []);
            if (!hash_equals((string) ($item['receipt_hash'] ?? ''), (string) ($deletion['hash'] ?? ''))
                || !hash_equals((string) ($item['deletion_kind'] ?? ''), (string) ($data['kind'] ?? ''))
                || !hash_equals((string) ($item['deletion_type'] ?? ''), (string) ($data['type'] ?? ''))) {
                throw new \RuntimeException('duo: scoped recovery deletion no longer matches its authority');
            }
            $deleteWork[] = $deletionPlanRows[$identity] ?? [
                'uuid' => $identity,
                'type' => (string) (($data['kind'] ?? '') === 'table'
                    ? ($data['type'] ?? '')
                    : ($data['kind'] ?? '')),
                'deletion_kind' => (string) ($data['kind'] ?? ''),
                'deletion_type' => (string) ($data['type'] ?? ''),
                'path' => (string) ($deletion['path'] ?? ''),
                'expected_hash' => (string) ($data['expected_hash'] ?? ''),
                'receipt_hash' => (string) ($deletion['hash'] ?? ''),
                'retry' => true,
            ];
        }
        usort($deleteWork, fn(array $a, array $b): int =>
            $planner->deletion_rank($b) <=> $planner->deletion_rank($a)
                ?: ((string) $a['uuid'] <=> (string) $b['uuid'])
        );
        return [
            'work' => $work,
            'delete_work' => $deleteWork,
            'rebuild_delete_work' => $deleteWork,
        ];
    }
}
