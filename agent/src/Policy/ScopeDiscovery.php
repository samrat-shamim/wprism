<?php
namespace Duo;

/**
 * Read-only discovery of the live WordPress surfaces Capture may observe.
 *
 * ScopeDiscovery owns the database queries and runtime-taxonomy walk that
 * establish the posts, terms, authored-looking scope gaps, and relationship
 * ownership for one capture build. It deliberately knows nothing about
 * identity minting, canonical serialization, publication, or scope-contract
 * closure: ScopeClosure/ScopeContract remain the sole owners of repository
 * dependency closure. CaptureCandidateBuilder injects the two observable side
 * channels this read boundary needs -- an immediate read-error checkpoint and
 * its warning collector.
 */
final class ScopeDiscovery {
    private object $policy;
    private \Closure $readCheckpoint;
    private \Closure $warn;

    public function __construct(
        object $policy,
        ?\Closure $readCheckpoint = null,
        ?\Closure $warn = null
    ) {
        $this->policy = $policy;
        $this->readCheckpoint = $readCheckpoint ?? static function (): void {};
        $this->warn = $warn ?? static function (string $_warning): void {};
    }

    /**
     * Discover the complete live scope input in Capture's frozen read order.
     *
     * @return array{
     *   gaps: array<string,array{entities:int}>,
     *   posts: array,
     *   terms: array,
     *   by_post_type: array<string,string[]>,
     *   term_object: string[]
     * }
     */
    public function discover(bool $strictReadOnly = false, ?\Closure $assertGaps = null): array {
        $gaps = $this->gaps();
        if ($assertGaps !== null) {
            $assertGaps($gaps);
        }
        $posts = $this->posts();
        $terms = $this->terms();
        $taxonomies = $this->taxonomyOwnership(
            $this->policy->taxonomies(),
            $this->policy->post_types(),
            $strictReadOnly
        );
        return [
            'gaps' => $gaps,
            'posts' => $posts,
            'terms' => $terms,
            'by_post_type' => $taxonomies['by_post_type'],
            'term_object' => $taxonomies['term_object'],
        ];
    }

    /** @return array<int,object> */
    public function posts(): array {
        global $wpdb;
        $types = $this->policy->post_types();
        $nonAttach = array_values(array_diff($types, ['attachment']));
        $statuses = ['publish', 'draft', 'pending', 'private', 'future'];
        $conditions = [];
        if ($nonAttach) {
            $conditions[] = "(post_type IN ('" . implode("','", array_map('esc_sql', $nonAttach)) . "')"
                . " AND post_status IN ('" . implode("','", $statuses) . "'))";
        }
        if (in_array('attachment', $types, true)) {
            $conditions[] = "(post_type = 'attachment' AND post_status = 'inherit')";
        }
        if (!$conditions) {
            return [];
        }
        $rows = $wpdb->get_results(
            "SELECT * FROM {$wpdb->posts} WHERE " . implode(' OR ', $conditions) . ' ORDER BY ID ASC'
        ) ?: [];
        ($this->readCheckpoint)();
        return $rows;
    }

    /**
     * Find live authored-looking entities that current policy scope would
     * silently omit. Explicit non-authored whole-type rules are exclusions;
     * missing dispositions remain blocking gaps.
     *
     * @return array<string,array{entities:int}> keyed post_type:<name> or taxonomy:<name>
     */
    public function gaps(): array {
        global $wpdb;

        $publicPostTypes = array_values(get_post_types(['public' => true], 'names'));
        $postCandidates = array_fill_keys(array_unique(array_merge(
            $publicPostTypes,
            $this->policy->declared_post_types()
        )), true);
        $scopedPostTypes = array_fill_keys($this->policy->post_types(), true);
        $postCounts = $wpdb->get_results(
            "SELECT post_type, COUNT(*) AS entities FROM {$wpdb->posts}
             WHERE (post_status IN ('publish','draft','pending','private','future')
                    OR (post_type = 'attachment' AND post_status = 'inherit'))
             GROUP BY post_type",
            ARRAY_A
        ) ?: [];
        ($this->readCheckpoint)();

        $out = [];
        foreach ($postCounts as $row) {
            $name = (string) $row['post_type'];
            if (!isset($postCandidates[$name]) || isset($scopedPostTypes[$name])) {
                continue;
            }
            $class = $this->policy->post_type_rule_details($name)['rule']['class'] ?? null;
            if ($class !== null && $class !== 'authored') {
                continue;
            }
            $out["post_type:$name"] = ['entities' => (int) $row['entities']];
        }

        $publicTaxonomies = array_values(get_taxonomies(['public' => true], 'names'));
        $taxCandidates = array_fill_keys(array_unique(array_merge(
            $publicTaxonomies,
            $this->policy->declared_taxonomies()
        )), true);
        $scopedTaxonomies = array_fill_keys($this->policy->taxonomies(), true);
        $taxCounts = $wpdb->get_results(
            "SELECT taxonomy, COUNT(*) AS entities FROM {$wpdb->term_taxonomy} GROUP BY taxonomy",
            ARRAY_A
        ) ?: [];
        ($this->readCheckpoint)();
        foreach ($taxCounts as $row) {
            $name = (string) $row['taxonomy'];
            if (!isset($taxCandidates[$name]) || isset($scopedTaxonomies[$name])) {
                continue;
            }
            $class = $this->policy->taxonomy_rule_details($name)['rule']['class'] ?? null;
            if ($class !== null && $class !== 'authored') {
                continue;
            }
            $out["taxonomy:$name"] = ['entities' => (int) $row['entities']];
        }

        ksort($out, SORT_STRING);
        return $out;
    }

    /** @return array<int,object> */
    public function terms(): array {
        global $wpdb;
        $taxonomies = $this->policy->taxonomies();
        if (!$taxonomies) {
            return [];
        }
        $in = "'" . implode("','", array_map('esc_sql', $taxonomies)) . "'";
        $rows = $wpdb->get_results(
            "SELECT t.term_id, t.name, t.slug, tt.term_taxonomy_id, tt.taxonomy, tt.description, tt.parent
             FROM {$wpdb->terms} t JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
             WHERE tt.taxonomy IN ($in) ORDER BY t.term_id ASC"
        ) ?: [];
        ($this->readCheckpoint)();
        return $rows;
    }

    /**
     * Resolve relationship ownership from the taxonomy registry and the
     * policy's generic object-keyspace declarations.
     *
     * @param string[] $taxonomies
     * @param string[] $postTypes
     * @return array{by_post_type: array<string,string[]>, term_object: string[]}
     */
    public function taxonomyOwnership(
        array $taxonomies,
        array $postTypes,
        bool $strictReadOnly = false
    ): array {
        $byPostType = array_fill_keys($postTypes, []);
        $termObject = [];
        foreach ($taxonomies as $taxonomy) {
            $taxonomyObject = get_taxonomy($taxonomy);
            // A pattern-matched taxonomy can be in policy scope before its
            // plugin has registered it in this request. The manifest-owned
            // pattern contract is the only accepted fallback; exact names
            // without that evidence remain unknown below.
            $objectTypes = $taxonomyObject !== false
                ? (array) $taxonomyObject->object_type
                : $this->policy->pattern_object_type($taxonomy);
            if ($objectTypes === null) {
                if ($strictReadOnly) {
                    throw new \RuntimeException(
                        "duo: refresh export refused — taxonomy '$taxonomy' is in policy scope but is not registered "
                        . 'under the isolated control bootstrap, and no plugin-owned taxonomy_patterns '
                        . 'object_type declaration can prove which post or term relationships belong to it; '
                        . 'add that manifest/provider contract before refreshing production'
                    );
                }
                ($this->warn)(
                    "taxonomy '$taxonomy' is in policy scope but not registered on this environment"
                    . " (plugin inactive?) — cannot determine which object type its relationships"
                    . ' belong to, so its relationships are skipped for every post and term'
                );
                continue;
            }
            // Post and term ids use independent numeric spaces. Resolve the
            // declared keyspace before mapping runtime object types so equal
            // integers can never make one object's relationships look like
            // another's. Capture intentionally has no Apply-style desired-
            // option supplement: every capture is a fresh process observing
            // only committed live registration facts.
            $keyspace = $this->policy->taxonomy_object_keyspace($taxonomy, $objectTypes);
            if ($keyspace === 'term') {
                $termObject[] = $taxonomy;
                continue;
            }
            foreach ($objectTypes as $objectType) {
                if (isset($byPostType[$objectType])) {
                    $byPostType[$objectType][] = $taxonomy;
                }
            }
        }
        return ['by_post_type' => $byPostType, 'term_object' => $termObject];
    }
}
