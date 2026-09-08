<?php
declare(strict_types=1);

namespace WPrism;

if (!class_exists(CompiledRepository::class, false)) require_once __DIR__ . '/../Repository/CompiledArtifact.php';
require_once __DIR__ . '/../Repository/RepositoryMediaDerivatives.php';
require_once __DIR__ . '/../Kernel/MediaDerivativeRecipe.php';
if (!class_exists(Canon::class, false)) require_once __DIR__ . '/../Kernel/Canon.php';
if (!class_exists(Policy::class, false)) require_once __DIR__ . '/../Policy/Policy.php';

/** Pure selection of attachment effects for the exact authored work, including preserved consumers. */
final class MediaDerivativeWorkset {
    private function __construct(
        public readonly array $attachments,
        public readonly array $recipes,
        private readonly string $artifactHash,
        private readonly string $targetFingerprint
    ) {}

    public function assert_artifact(CompiledRepository $compiled): void {
        if (!hash_equals($this->artifactHash, $compiled->artifact_hash())) {
            throw new \RuntimeException('wprism: derivative work belongs to another compiled artifact');
        }
    }

    /** The transaction owner supplies a fresh observation under its native locks. */
    public function assert_target(Policy $policy, array $targetTree): void {
        $target = RepositoryMediaDerivatives::snapshot($targetTree, $policy);
        if (!hash_equals($this->targetFingerprint, self::fingerprint($target, $this->attachments))) {
            throw new \RuntimeException('wprism: derivative consumers or original bindings changed since work selection');
        }
    }

    private static function fingerprint(array $snapshot, array $attachments): string {
        $selected = array_fill_keys($attachments, true);
        return hash('sha256', Canon::encode([
            'attachments' => array_intersect_key($snapshot['attachments'], $selected),
            'recipes' => array_values(array_filter($snapshot['recipes'], static fn(array $row): bool => isset($selected[$row['attachment_uuid']]))),
        ]));
    }

    public static function select(CompiledRepository $compiled, Policy $policy, array $targetTree, array $work, array $deleted): self {
        $tree = $compiled->tree();
        $desired = $compiled->media_derivatives();
        if ($desired !== RepositoryMediaDerivatives::derive($tree, $policy)) {
            throw new \RuntimeException('wprism: media work selection lacks an exact compiled derivative declaration');
        }
        $target = RepositoryMediaDerivatives::snapshot($targetTree, $policy);
        $changed = [];
        $attachmentWrites = [];
        foreach ($work as $entry) {
            $uuid = $entry['uuid'] ?? null;
            if (!is_string($uuid) || !isset($tree[$uuid])) throw new \RuntimeException('wprism: derivative work selection has an unbound authored identity');
            $changed[$uuid] = true;
            if (($tree[$uuid]['type'] ?? '') === 'post' && ($tree[$uuid]['data']['type'] ?? '') === 'attachment') $attachmentWrites[$uuid] = true;
        }
        $deletions = [];
        foreach ($deleted as $uuid) {
            if (!is_string($uuid)) throw new \RuntimeException('wprism: derivative work deletion identity is malformed');
            $deletions[$uuid] = true;
            $changed[$uuid] = true;
        }
        // An authored original also protects the absence of consumers. A crop
        // added after selection must not escape the transaction's recheck.
        $affected = array_diff_key($attachmentWrites, $deletions);
        foreach ([$target['recipes'], $desired] as $source) {
            foreach ($source as $row) {
                $uuid = $row['attachment_uuid'];
                if (isset($deletions[$uuid]) || !isset($tree[$uuid])) continue;
                if (isset($attachmentWrites[$uuid]) || array_intersect_key($changed, array_flip($row['consumers'])) !== []) $affected[$uuid] = true;
            }
        }
        $rows = [];
        foreach ([$target['recipes'], $desired] as $index => $source) {
            foreach ($source as $row) {
                $uuid = $row['attachment_uuid'];
                if (!isset($affected[$uuid])) continue;
                $consumers = array_values(array_filter($row['consumers'], static fn(string $consumer): bool =>
                    $index === 0 ? !isset($changed[$consumer]) : isset($changed[$consumer]) && !isset($deletions[$consumer])));
                if ($consumers === []) continue;
                $front = $tree[$uuid]['data'];
                if ($row['original_path'] !== $front['file']) {
                    throw new \RuntimeException('wprism: attachment path change conflicts with a preserved derivative consumer');
                }
                $row['media_blob'] = $front['media'];
                $row['consumers'] = $consumers;
                $row['recipe_id'] = MediaDerivativeRecipe::identity($row);
                $key = $uuid . ':' . $row['target_path'];
                if (isset($rows[$key])) {
                    if ($rows[$key]['recipe_id'] !== $row['recipe_id']) {
                        throw new \RuntimeException('wprism: selected and preserved consumers require conflicting derivative transforms');
                    }
                    $rows[$key]['consumers'] = array_values(array_unique([...$rows[$key]['consumers'], ...$consumers]));
                } else $rows[$key] = $row;
            }
        }
        foreach (array_keys($affected) as $uuid) {
            if (isset($attachmentWrites[$uuid])) continue;
            $front = $tree[$uuid]['data'];
            $observed = $target['attachments'][$uuid] ?? null;
            if (!is_array($observed) || ($observed['file'] ?? null) !== $front['file']
                || ($observed['media'] ?? null) !== $front['media'] || ($observed['mime'] ?? null) !== $front['mime']) {
                throw new \RuntimeException('wprism: content-only derivative work requires its unchanged observed attachment original');
            }
        }
        ksort($affected, SORT_STRING);
        ksort($rows, SORT_STRING);
        foreach ($rows as &$row) sort($row['consumers'], SORT_STRING);
        unset($row);
        $rows = array_values($rows);
        // Target-only consumers retain their observed identities. Their authored
        // bodies do not become desired repository state or enter the write set.
        $authorityTree = $tree + array_map(static fn(array $entity): array => ['type' => $entity['type']], $targetTree);
        MediaDerivativeRecipe::assert_inventory($rows, $authorityTree);
        return new self(array_keys($affected), $rows, $compiled->artifact_hash(), self::fingerprint($target, array_keys($affected)));
    }
}
