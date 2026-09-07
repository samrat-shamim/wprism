<?php
declare(strict_types=1);

namespace WPrismTest\RankMathCombination;

require_once dirname(__DIR__, 3) . '/sandbox/tests/lib/RepositoryConvergence.php';
require_once dirname(__DIR__, 3) . '/agent/src/Kernel/PlainData.php';
require_once dirname(__DIR__, 3) . '/agent/src/Kernel/IdentityTokenCodec.php';

use WPrism\Canon;
use WPrism\CompiledRepository;
use WPrism\IdentityTokenCodec;
use WPrism\PlainData;
use WPrism\Policy;
use WPrismTest\RepositoryConvergence;

/** Fixture-owned native preservation proof; no plugin exception enters the compiler. */
final class RecaptureConvergence {
    private static function demand(bool $condition, string $what): void {
        if (!$condition) throw new \RuntimeException('combined convergence: ' . $what);
    }

    private static function same(mixed $a, mixed $b, string $what): void {
        // Canon's decoder has the same empty-object normalization as its post
        // hash basis. Raw SQL inventories are compared without this projection.
        self::demand(Canon::decode(Canon::encode($a)) === Canon::decode(Canon::encode($b)), $what);
    }

    private static function index(array $rows, string $key): array {
        self::demand(array_is_list($rows), 'native inventory is not a list');
        $out = [];
        foreach ($rows as $row) {
            $id = $row[$key] ?? null;
            self::demand(is_string($id) && preg_match('/^[1-9][0-9]*$/D', $id) === 1 && !isset($out[$id]), 'native identity is absent or duplicated');
            $out[$id] = $row;
        }
        return $out;
    }

    /** Only the declared derived marker may change during the provider pass. */
    private static function metadata(array $before, array $after, ?string $mintedUuid, bool $processed = false): void {
        $left = self::index($before, 'meta_id');
        $original = $left;
        $right = self::index($after, 'meta_id');
        $special = $mintedUuid === null ? 'rank_math_internal_links_processed' : '_wprism_uuid';
        if ($processed) {
            // The provider's clear_derived_link_state() deletes markers before
            // native regeneration. Their row IDs are derived too; every other
            // metadata identity and byte remains subject to exact equality.
            foreach ($left as $id => $row) if ($row['meta_key'] === $special) unset($left[$id]);
        }
        $values = [];
        foreach ($right as $id => $row) {
            self::demand(array_keys($row) === ['meta_id', 'meta_key', 'meta_value'], 'metadata row is incomplete');
            if (($mintedUuid !== null || $processed) && $row['meta_key'] === $special) {
                $values[] = $row['meta_value'];
                if (isset($original[$id])) {
                    self::demand($processed && $original[$id]['meta_key'] === $special, 'metadata identity was replaced');
                    unset($left[$id]);
                } else {
                    self::demand($before === [] || (int)$id > max(array_map('intval', array_keys($original))), 'metadata identity was reused');
                }
                unset($right[$id]);
            }
        }
        self::demand($left === $right, 'undeclared native metadata changed');
        if ($mintedUuid !== null || $processed) self::demand($values === [$mintedUuid ?? '1'], 'identity mint or derived marker is not exact');
    }

    /** This fixture contains scalar metadata, not an alternative reference interpreter. */
    private static function authoredMeta(array $rows, Policy $policy, bool $term): object {
        $flat = [];
        foreach ($rows as $row) $flat[$row['meta_key']] ??= $row['meta_value'];
        $out = [];
        foreach ($rows as $row) {
            $key = $row['meta_key'];
            $rule = $term ? $policy->meta_rule_for_term($key, $flat) : $policy->meta_rule_for_post($key, $flat);
            if (($rule['class'] ?? null) !== 'authored') continue;
            self::demand(!isset($out[$key]) && empty($rule['ref']) && empty($rule['json_refs']) && empty($rule['key_refs'])
                && empty($rule['plain_data']) && empty($rule['order_preserving']) && !isset($rule['repeated_rows']), 'target-only fixture gained unsupported authored metadata semantics');
            $value = PlainData::decode($row['meta_value'], 'target-only metadata evidence');
            self::demand(is_scalar($value) && (!is_string($value) || (!str_contains($value, '://') && !str_contains($value, '{{'))), 'target-only fixture metadata is no longer plain scalar data');
            $out[$key] = $value;
        }
        return (object)$out;
    }

    /**
     * The three raw inventories straddle Apply/retry and final Capture. Exact
     * SQL identities establish lineage before any target-only hash is admitted.
     * Former translation groups may lose only source-adopted members; their
     * authored rows and descriptions remain target-owned, including empty groups.
     */
    public static function verify(CompiledRepository $source, CompiledRepository $target, Policy $policy,
        array $hostile, array $final, array $before, array $precapture, array $after): array {
        $sourceTree = $source->tree();
        $targetTree = $target->tree();
        $extras = array_diff_key($targetTree, $sourceTree);
        self::demand(count($extras) === 7, 'the declared one-neighbor/six-term preservation witness is incomplete');
        self::demand($before['post'] === $precapture['post'] && $before['post'] === $after['post']
            && $before['author_login'] === $precapture['author_login'] && $before['author_login'] === $after['author_login'], 'target-only physical post or author changed');
        self::demand(($policy->meta_rule_for_post('rank_math_internal_links_processed', [])['class'] ?? null) === 'derived', 'marker exception lacks declared derived ownership');
        self::metadata($before['postmeta'], $precapture['postmeta'], null, true);

        $oldTerms = self::index($hostile['taxonomy_graph']['terms'], 'term_id');
        $terms = self::index($final['taxonomy_graph']['terms'], 'term_id');
        $oldTts = self::index($hostile['taxonomy_graph']['term_taxonomy'], 'term_taxonomy_id');
        $tts = self::index($final['taxonomy_graph']['term_taxonomy'], 'term_taxonomy_id');
        self::demand(array_diff_key($oldTerms, $terms) === [] && array_diff_key($oldTts, $tts) === [], 'pre-existing native taxonomy identity disappeared');
        $oldMeta = self::index($before['termmeta'], 'term_id');
        $midMeta = self::index($precapture['termmeta'], 'term_id');
        $newMeta = self::index($after['termmeta'], 'term_id');
        self::demand(array_keys($oldTerms) === array_keys($oldMeta) && array_keys($terms) === array_keys($midMeta)
            && array_keys($terms) === array_keys($newMeta), 'term metadata inventory lacks complete native ownership');
        $termUuids = $ttByTerm = $fronts = $managedTerms = $postUuids = $managedPosts = [];
        foreach ($targetTree as $uuid => $entity) {
            if ($entity['type'] === 'term') {
                $front = Canon::decode($entity['content']);
                $matches = array_filter($tts, static fn(array $tt): bool => $tt['taxonomy'] === $front['taxonomy']
                    && ($terms[$tt['term_id']]['slug'] ?? null) === $front['slug']);
                self::demand(count($matches) === 1, 'canonical term lacks one exact native taxonomy identity');
                $tt = reset($matches);
                $id = $tt['term_id'];
                self::demand(!isset($termUuids[$id]), 'native term has multiple canonical owners');
                $termUuids[$id] = $uuid;
                $ttByTerm[$id] = $tt;
                $fronts[$uuid] = $front;
                if (isset($sourceTree[$uuid])) $managedTerms[$id] = true;
            } elseif ($entity['type'] === 'post') {
                [$front] = Canon::parse_post_file($entity['content']);
                $native = match ($front['slug']) {
                    'rmcombo-product-en' => $final['products']['en']['id'],
                    'rmcombo-product-de' => $final['products']['de']['id'],
                    'rmcombo-book' => $final['book']['id'],
                    'rmcombo-target-neighbor' => (int)$after['post']['ID'],
                    default => null,
                };
                if ($native !== null) {
                    self::demand(!isset($postUuids[$native]), 'native post has multiple canonical owners');
                    $postUuids[$native] = $uuid;
                    if (isset($sourceTree[$uuid])) $managedPosts[$native] = true;
                }
            }
        }
        $relationships = static function (array $graph, string $column, string $id): array {
            $rows = array_values(array_filter($graph['term_relationships'], static fn(array $row): bool => $row[$column] === $id));
            usort($rows, static fn(array $a, array $b): int => strcmp(Canon::encode($a), Canon::encode($b)));
            return $rows;
        };
        $canonicalRelationships = static function (string $id, string $keyspace) use ($final, $tts, $termUuids, $relationships, $policy): array {
            $map = $orders = [];
            foreach ($relationships($final['taxonomy_graph'], 'object_id', $id) as $row) {
                $tt = $tts[$row['term_taxonomy_id']] ?? null;
                self::demand(is_array($tt), 'relationship points outside the native inventory');
                if ($policy->taxonomy_object_keyspace($tt['taxonomy']) !== $keyspace) continue;
                $uuid = $termUuids[$tt['term_id']] ?? null;
                self::demand(is_string($uuid), 'target-only relationship points outside canonical scope');
                $map[$tt['taxonomy']][] = $uuid;
                $orders[$tt['taxonomy']][$uuid] = (int)$row['term_order'];
            }
            foreach ($map as &$list) sort($list, SORT_STRING);
            unset($list);
            return [(object)$map, (object)array_map(static fn(array $values): object => (object)$values, $orders)];
        };
        $signatures = RepositoryConvergence::signatures($target);
        $proved = [];
        $neighborCount = $termCount = 0;
        foreach ($extras as $uuid => $entity) {
            if ($entity['type'] === 'post') {
                ++$neighborCount;
                [$front, $body] = Canon::parse_post_file($entity['content']);
                $post = $before['post'];
                self::demand($front['type'] === 'product' && $front['slug'] === 'rmcombo-target-neighbor'
                    && ($postUuids[$post['ID']] ?? null) === $uuid && $post['post_parent'] === '0'
                    && $post['post_password'] === '' && $post['post_content'] === '' && $post['post_excerpt'] === '', 'target-only post is not the declared neighbor fixture');
                self::metadata($precapture['postmeta'], $after['postmeta'], $uuid);
                self::demand($relationships($hostile['taxonomy_graph'], 'object_id', $post['ID'])
                    === $relationships($final['taxonomy_graph'], 'object_id', $post['ID']), 'neighbor native memberships changed');
                [$postTerms, $orders] = $canonicalRelationships($post['ID'], 'post');
                $expected = ['uuid'=>$uuid, 'type'=>'product', 'slug'=>$post['post_name'], 'title'=>$post['post_title'],
                    'status'=>$post['post_status'], 'date'=>$post['post_date'], 'date_gmt'=>$post['post_date_gmt'],
                    'modified'=>$post['post_modified'], 'modified_gmt'=>$post['post_modified_gmt'],
                    'author'=>$before['author_login'] === null ? null : 'user:' . $before['author_login'],
                    'parent'=>null, 'menu_order'=>(int)$post['menu_order'], 'comment_status'=>$post['comment_status'],
                    'ping_status'=>$post['ping_status'], 'excerpt'=>'', 'meta'=>self::authoredMeta($before['postmeta'], $policy, false),
                    'terms'=>$postTerms, 'term_orders'=>$orders];
                self::same($expected, $front, 'canonical neighbor does not represent its complete preserved authored preimage');
                self::demand($body === '', 'canonical neighbor body changed');
            } elseif ($entity['type'] === 'term') {
                ++$termCount;
                $front = $fronts[$uuid];
                $id = array_search($uuid, $termUuids, true);
                $tt = $ttByTerm[$id];
                $ttId = $tt['term_taxonomy_id'];
                self::demand(isset($oldTerms[$id], $oldTts[$ttId]) && $oldTerms[$id] === $terms[$id], 'target-only term did not predate Apply unchanged');
                $oldTt = $oldTts[$ttId];
                $currentTt = $tt;
                unset($oldTt['count'], $currentTt['count']);
                self::demand($oldTt === $currentTt, 'target-only taxonomy authored row changed');
                self::metadata($oldMeta[$id]['rows'], $midMeta[$id]['rows'], null);
                self::metadata($midMeta[$id]['rows'], $newMeta[$id]['rows'], $uuid);
                $members = $relationships($hostile['taxonomy_graph'], 'term_taxonomy_id', $ttId);
                $currentMembers = $relationships($final['taxonomy_graph'], 'term_taxonomy_id', $ttId);
                if (in_array($tt['taxonomy'], ['post_translations', 'term_translations'], true)) {
                    $managed = $tt['taxonomy'] === 'post_translations' ? $managedPosts : $managedTerms;
                    $members = array_values(array_filter($members, static fn(array $row): bool => !isset($managed[$row['object_id']])));
                    self::demand((int)$tt['count'] === count($members), 'former translation group count disagrees with its remaining members');
                } else {
                    self::demand($tt['taxonomy'] === 'product_cat' && $oldTts[$ttId]['count'] === $tt['count'], 'unexpected target-only taxonomy or count change');
                }
                self::demand($members === $currentMembers, 'target-only membership changed outside source adoption');
                self::demand($relationships($hostile['taxonomy_graph'], 'object_id', (string)$id)
                    === $relationships($final['taxonomy_graph'], 'object_id', (string)$id), 'target-only term-object relationships changed');
                $description = $oldTt['description'];
                if ($tt['taxonomy'] !== 'product_cat') {
                    $refs = PlainData::decode_serialized($description, 'preserved native translation map');
                    self::demand(is_array($refs) && $refs !== [], 'preserved translation description is not a map');
                    $mapping = $tt['taxonomy'] === 'post_translations' ? $postUuids : $termUuids;
                    $kind = $tt['taxonomy'] === 'post_translations' ? 'post' : 'term';
                    foreach ($refs as &$nativeId) {
                        self::demand(is_int($nativeId) && isset($mapping[$nativeId]), 'preserved description has an unresolved native reference');
                        $nativeId = IdentityTokenCodec::encode($kind, $mapping[$nativeId]);
                    }
                    unset($nativeId);
                    $description = (object)$refs;
                }
                [$termRelationships] = $canonicalRelationships((string)$id, 'term');
                $expected = ['uuid'=>$uuid, 'taxonomy'=>$tt['taxonomy'], 'name'=>$oldTerms[$id]['name'],
                    'slug'=>$oldTerms[$id]['slug'], 'description'=>$description,
                    'parent'=>$tt['parent'] === '0' ? null : ($termUuids[$tt['parent']] ?? throw new \RuntimeException('preserved term parent is unresolved')),
                    'meta'=>self::authoredMeta($oldMeta[$id]['rows'], $policy, true), 'relationships'=>$termRelationships];
                if ($policy->taxonomy_term_group_is_authored($tt['taxonomy'])) $expected['term_group'] = (int)$oldTerms[$id]['term_group'];
                self::same($expected, $front, 'canonical extra term differs from its complete preserved native preimage');
            } else {
                throw new \RuntimeException('combined convergence has an undeclared extra entity kind');
            }
            $proved[$uuid] = $signatures[$uuid];
        }
        self::demand($neighborCount === 1 && $termCount === 6, 'target-only witness roles are incomplete');
        RepositoryConvergence::assertSame($source, $target, $proved);
        return ['managed_entities'=>count($sourceTree), 'preserved_posts'=>$neighborCount, 'preserved_terms'=>$termCount];
    }
}
