<?php
declare(strict_types=1);

namespace WPrismTest;

use WPrism\ApplyPlanner;
use WPrism\ApplyPreparationCoordinator;
use WPrism\ApplyPreparationRequest;
use WPrism\ApplyRequestCoordinator;
use WPrism\ApplyServiceCallbacks;
use WPrism\ApplyServices;
use WPrism\Canon;
use WPrism\CompiledRepository;
use WPrism\Db;
use WPrism\DeleteGuardEvaluator;
use WPrism\DeleteGuardReferenceScanner;
use WPrism\Deletion;
use WPrism\NativeDatabaseProfile;
use WPrism\Policy;
use WPrism\RebuildSelection;
use WPrism\ScopedApplyWorkflow;

/**
 * The real force-preparation / mutation-admission / failure-reporting path.
 *
 * Native schema and comment rows are observations supplied to the shared
 * wpdb interpreter. Lease renewal and fresh-plan transport are the only
 * non-mutating callbacks; this fixture does not mint signed delete authority
 * or claim that a delete executed. The separately allocated SSH run owns that.
 */
final class CoreSshRefusalProduct {
    /** @return array<string,mixed> */
    public static function produce(string $root, array $context, bool $escaping = true): array {
        $policy = Policy::load(null, ['core'], adapterLibrary: \WPrism\AdapterLibrary::fromSourceTree($root));
        $db = FakeWpdb::install()->enableInformationSchema()->enableFullApplySqlExtensions();
        $db->seedTable('wp_posts', [['ID' => $context['ids']['post']]])
            ->setColumns('wp_posts', ['ID' => 'bigint unsigned'])->setTableEngine('wp_posts', 'InnoDB');
        $db->seedTable('wp_comments', [['comment_ID' => $context['comment'], 'comment_post_ID' => $context['ids']['page']]])
            ->setColumns('wp_comments', ['comment_ID' => 'bigint unsigned', 'comment_post_ID' => 'bigint unsigned'])
            ->setTableEngine('wp_comments', 'InnoDB')->setIndexes('wp_comments', [[
                'Key_name' => 'comment_post_ID', 'Column_name' => 'comment_post_ID', 'Seq_in_index' => 1,
                'Sub_part' => null, 'Non_unique' => 1, 'Index_type' => 'BTREE',
            ], [
                'Key_name' => 'PRIMARY', 'Column_name' => 'comment_ID', 'Seq_in_index' => 1,
                'Sub_part' => null, 'Non_unique' => 0, 'Index_type' => 'BTREE',
            ]]);
        $db->seedTable('wp_wprism_map', array_map(static fn(string $uuid, int $id): array => [
            'uuid' => $uuid, 'entity_type' => 'post', 'id_kind' => 'post', 'local_id' => $id,
        ], $context['uuids'], array_values($context['ids'])));
        $previousTree = [];
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
        $blob = hash('sha256', $png) . '.png';
        foreach (array_keys($context['ids']) as $index => $type) {
            $uuid = $context['uuids'][$index];
            $previousTree[$uuid] = ['type' => 'post', 'hash' => str_repeat('a', 64),
                'path' => 'posts/' . $type . '/' . $uuid . '.md', 'data' => ['uuid' => $uuid, 'type' => $type]];
            if ($type === 'attachment') $previousTree[$uuid]['data'] += ['file' => 'fixture.png', 'mime' => 'image/png', 'media' => $blob];
        }
        $previous = CompiledRepository::create(['revision_hash' => str_repeat('b', 64), 'tree' => $previousTree,
            'media' => [$blob => ['sha256' => hash('sha256', $png), 'base64' => base64_encode($png)]]]);
        $deletions = [];
        $plan = array_fill_keys(['create', 'update', 'adopt', 'unchanged', 'drift', 'conflict', 'collision',
            'delete', 'delete_conflict', 'deleted', 'code_mismatch', 'code_drift', 'missing_user',
            'incomplete_apply', 'regen_pending', 'regen_context', 'skipped_user_meta', 'uploads_inventory', 'effects_inventory'], []);
        $capabilities = [];
        foreach (Deletion::capture_tombstones($previous, [], $policy) as $tombstone) {
            $data = Canon::decode($tombstone['content']);
            $uuid = $tombstone['uuid'];
            $deletions[$uuid] = $tombstone + ['data' => $data, 'hash' => hash('sha256', $tombstone['content'])];
            $row = ['uuid' => $uuid, 'type' => $data['kind'], 'path' => $tombstone['path'],
                'deletion_kind' => $data['kind'], 'deletion_type' => $data['type'],
                'expected_hash' => $data['expected_hash'], 'receipt_hash' => $deletions[$uuid]['hash']];
            $classified = ApplyPlanner::classify_deletion($row,
                ['hash' => str_repeat($uuid === $context['uuids'][1] ? 'c' : 'a', 64)],
                ['entity_type' => 'post', 'content_hash' => str_repeat('a', 64)]);
            $plan[$classified['bucket']][] = $classified['row'];
            // Only the comment guard's native witness is needed to exercise
            // its warning. Other guards and actual row deletion are live work,
            // not fictional zero-result callbacks in this preparation test.
            $capability = Deletion::capability($policy, $data['kind'], $data['type']);
            $capability['guards'] = array_values(array_filter($capability['guards'],
                static fn(array $guard): bool => $guard['table'] === 'comments'));
            $capabilities[$uuid] = $capability;
        }
        $scanner = new DeleteGuardReferenceScanner($policy);
        $plan = DeleteGuardEvaluator::annotate_plan_guard_findings($plan, $capabilities,
            static fn(array $guard, string $uuid, bool $forUpdate): array =>
                $scanner->count($guard, $uuid, array_fill_keys($context['uuids'], true), $deletions, forUpdate: $forUpdate),
            static fn(string $table): bool => false, str_repeat('d', 64));
        $compiled = CompiledRepository::create(['tree' => [], 'deletions' => $deletions]);
        $unused = static function (): never { throw new \LogicException('mutation callback reached by refusal-only fixture'); };
        $services = new ApplyServices($policy, $compiled, new ApplyServiceCallbacks(
            taxonomyOwnership: static fn(): array => [], renewPromotionLock: static function (string $phase): void {},
            renewRegenerationLease: $unused, renewProviderLease: $unused, lockDeleteGuards: $unused,
            deletionDatabaseProfile: $unused, recheckDeleteGuard: $unused,
            selectionDeclaresChannelFor: static fn(): bool => false,
            selectionDeclaresEntityBatchFor: static fn(): bool => false,
            selectionTriggersProviderActionFor: static fn(): bool => false,
            pinnedProviderActionOwns: static fn(): bool => false, upsertMeta: $unused,
        ), '/fixture/core-refusal');
        $preparation = new ApplyPreparationCoordinator('/fixture/core-refusal', $policy, $services,
            new RebuildSelection($policy), new ScopedApplyWorkflow(), static fn(): array => $plan,
            static function (string $phase): void {});
        $warnings = $evidence = [];
        $prepared = $preparation->prepare(new ApplyPreparationRequest(
            options: ['with_deletes' => true, 'force_theirs' => true, 'force_delete_referenced' => true],
            compiled: $compiled, plan: $plan, tree: [], scoped: false, scopedPromotion: false,
            recoveringScoped: false, retryingIncompleteApply: false,
            promotionOwner: 'core-refusal-fixture', promotionArtifact: str_repeat('e', 64),
        ), $warnings, $evidence);
        if ($escaping) $db->addForeignKey('wordpress/core_ssh_delete_fk', 'wp_core_ssh_delete_fk', 'wp_posts', 'RESTRICT', 'CASCADE');
        $db->resetLog();
        $leaf = null;
        try {
            Db::start_repeatable_read('apply transaction start', new NativeDatabaseProfile([], ['wp_posts']));
            Db::rollback('core SSH refusal healthy control');
        } catch (\Throwable $failure) {
            $leaf = $failure;
        }
        return ['db' => $db, 'plan' => $plan, 'prepared' => $prepared, 'warnings' => $warnings,
            'evidence' => $evidence, 'leaf' => $leaf,
            'failure' => $leaf === null ? null : self::wrap($leaf, $warnings, $evidence)];
    }

    public static function wrap(\Throwable $leaf, array $warnings, array $evidence): \Throwable {
        $coordinator = (new \ReflectionClass(ApplyRequestCoordinator::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($coordinator, 'warnings'))->setValue($coordinator, $warnings);
        (new \ReflectionProperty($coordinator, 'forcedOverrideEvidence'))->setValue($coordinator, $evidence);
        return (new \ReflectionMethod(ApplyRequestCoordinator::class, 'failure_with_forced_warnings'))
            ->invoke(null, $leaf, $coordinator);
    }
}
