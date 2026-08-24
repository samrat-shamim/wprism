<?php
namespace Duo;

if (!class_exists(Policy::class, false)) {
    require_once __DIR__ . '/../Policy/Policy.php';
}
if (!class_exists(Ledger::class, false)) {
    require_once __DIR__ . '/../Repository/Ledger.php';
}
if (!class_exists(Db::class, false)) {
    require_once __DIR__ . '/../Kernel/Db.php';
}
if (!class_exists(AttachmentMaterializer::class, false)) {
    require_once __DIR__ . '/../Apply/AttachmentMaterializer.php';
}

/**
 * Rebuilds WordPress-owned derived state after the authored transaction:
 * future-post schedules, taxonomy counts, and attachment metadata.
 */
final class NativeRebuildExecutor {
    /** @var \Closure(string,string,int,string,?string,?string,string):void */
    private readonly \Closure $upsertMeta;

    public function __construct(
        private readonly Policy $policy,
        \Closure $upsertMeta,
        private readonly ?AttachmentMaterializer $attachmentMaterializer = null
    ) {
        $this->upsertMeta = $upsertMeta;
    }

    public function run(
        array $attachmentIds,
        array $work,
        array $tree,
        array $appliedDeletions,
        bool $suppressExternalEffects
    ): void {
        global $wpdb;

        foreach ($work as $entry) {
            $entity = $tree[$entry['uuid']] ?? null;
            if (($entity['type'] ?? '') !== 'post') {
                continue;
            }
            $front = $entity['data'];
            $postId = Ledger::id_for($entry['uuid'], Ledger::KIND_POST);
            if ($postId === null) {
                continue;
            }
            $cleared = wp_clear_scheduled_hook('publish_future_post', [$postId]);
            if ($cleared === false) {
                throw new \RuntimeException("duo: failed to clear prior publication schedule for post $postId");
            }
            if (($front['status'] ?? '') !== 'future') {
                continue;
            }
            $timestamp = strtotime((string) $front['date_gmt'] . ' UTC');
            if ($timestamp === false || !wp_schedule_single_event($timestamp, 'publish_future_post', [$postId])) {
                throw new \RuntimeException("duo: failed to schedule future post $postId at {$front['date_gmt']} UTC");
            }
            if (wp_next_scheduled('publish_future_post', [$postId]) !== $timestamp) {
                throw new \RuntimeException("duo: future-post schedule verification failed for post $postId");
            }
        }

        $needsTaxonomyRecount = !$suppressExternalEffects
            || self::needs_taxonomy_recount($work, $tree, $appliedDeletions);
        $taxes = $needsTaxonomyRecount
            ? array_merge($this->policy->taxonomies(), ['nav_menu'])
            : [];
        if ($taxes !== []) {
            Db::checkpoint('rebuild term counts');
        }
        foreach (array_unique($taxes) as $taxonomy) {
            $termTaxonomyIds = $wpdb->get_col($wpdb->prepare(
                "SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s",
                $taxonomy
            )) ?: [];
            if (!$termTaxonomyIds) {
                continue;
            }
            $termTaxonomyIds = array_map('intval', $termTaxonomyIds);
            if (taxonomy_exists($taxonomy)) {
                if (wp_update_term_count_now($termTaxonomyIds, $taxonomy) === false) {
                    throw new \RuntimeException("duo: registered recount callback failed for taxonomy '$taxonomy'");
                }
                continue;
            }

            $callback = $this->policy->declared_update_count_callback($taxonomy);
            $objectTypes = $this->policy->declared_object_type($taxonomy);
            if ($callback === null || $objectTypes === null || !is_callable($callback)) {
                throw new \RuntimeException(
                    "duo: required taxonomy '$taxonomy' is not registered during recount and has no callable manifest count contract"
                );
            }
            $taxonomyObject = new \WP_Taxonomy($taxonomy, $objectTypes, [
                'update_count_callback' => $callback,
            ]);
            try {
                call_user_func($callback, $termTaxonomyIds, $taxonomyObject);
            } catch (\Throwable $t) {
                throw new \RuntimeException("duo: manifest recount callback failed for taxonomy '$taxonomy'", 0, $t);
            }
        }

        if (array_filter($attachmentIds) !== []) {
            if ($this->attachmentMaterializer === null) {
                throw new \RuntimeException('duo: attachment metadata rebuild lacks its durable materializer');
            }
            Db::checkpoint('rebuild attachment metadata');
            $this->attachmentMaterializer->finalize_native_metadata($attachmentIds);
        }
    }

    public static function needs_taxonomy_recount(array $work, array $tree, array $deletions): bool {
        foreach ($work as $entry) {
            $type = (string) ($tree[(string) ($entry['uuid'] ?? '')]['type'] ?? '');
            if (in_array($type, ['post', 'term', 'menu'], true)) {
                return true;
            }
        }
        foreach ($deletions as $entry) {
            $kind = (string) ($entry['deletion_kind'] ?? $entry['kind'] ?? $entry['type'] ?? '');
            if (in_array($kind, ['post', 'term', 'menu'], true)) {
                return true;
            }
        }
        return false;
    }
}
