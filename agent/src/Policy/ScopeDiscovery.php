<?php
namespace WPrism;

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
    private const MAX_SCOPE_TYPES = 4096;
    private const MAX_POSTS = 1000000;
    private const MAX_TERMS = 1000000;
    private const MAX_ENTITY_ROW_BYTES = 16777216;
    private const MAX_POST_BYTES = 268435456;
    private const MAX_TERM_BYTES = 134217728;
    private const MAX_CAPTURE_ALLOCATION_BYTES = 268435456;
    private const ESTIMATED_ROW_OVERHEAD_BYTES = 4096;
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
        $where = implode(' OR ', $conditions);
        $postBytes = 'OCTET_LENGTH(CAST(ID AS CHAR)) + OCTET_LENGTH(CAST(post_author AS CHAR)) '
            . '+ OCTET_LENGTH(COALESCE(post_date,\'\')) + OCTET_LENGTH(COALESCE(post_date_gmt,\'\')) '
            . '+ OCTET_LENGTH(COALESCE(post_content,\'\')) + OCTET_LENGTH(COALESCE(post_title,\'\')) '
            . '+ OCTET_LENGTH(COALESCE(post_excerpt,\'\')) + OCTET_LENGTH(COALESCE(post_status,\'\')) '
            . '+ OCTET_LENGTH(COALESCE(comment_status,\'\')) + OCTET_LENGTH(COALESCE(ping_status,\'\')) '
            . '+ OCTET_LENGTH(COALESCE(post_password,\'\')) + OCTET_LENGTH(COALESCE(post_name,\'\')) '
            . '+ OCTET_LENGTH(COALESCE(post_modified,\'\')) + OCTET_LENGTH(COALESCE(post_modified_gmt,\'\')) '
            . '+ OCTET_LENGTH(CAST(post_parent AS CHAR)) + OCTET_LENGTH(CAST(menu_order AS CHAR)) '
            . '+ OCTET_LENGTH(COALESCE(post_type,\'\')) + OCTET_LENGTH(COALESCE(post_mime_type,\'\'))';
        $stats = $this->checkedRows(
            'SELECT COUNT(*) AS row_count, '
            . "COALESCE(SUM($postBytes), 0) AS total_bytes, "
            . "COALESCE(MAX($postBytes), 0) AS max_row_bytes "
            . "FROM {$wpdb->posts} WHERE $where",
            'post discovery size preflight',
            ARRAY_A
        );
        [$rowCount, $totalBytes, $maxRowBytes] = $this->statistics($stats, 'post discovery');
        if ($rowCount > self::MAX_POSTS
            || $totalBytes > self::MAX_POST_BYTES
            || $maxRowBytes > self::MAX_ENTITY_ROW_BYTES) {
            throw new \RuntimeException('wprism: post discovery exceeds its bounded row/byte frontier');
        }
        self::assertAllocationFrontier($rowCount, $totalBytes, 'post discovery');
        $rows = $this->checkedRows(
            'SELECT ID, post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt, '
            . 'post_status, comment_status, ping_status, post_password, post_name, post_modified, '
            . "post_modified_gmt, post_parent, menu_order, post_type, post_mime_type FROM {$wpdb->posts} "
            . "WHERE $where ORDER BY ID ASC LIMIT " . (self::MAX_POSTS + 1),
            'post discovery'
        );
        if (count($rows) !== $rowCount) {
            throw new \RuntimeException('wprism: post discovery changed after its bounded size preflight');
        }
        $seen = [];
        foreach ($rows as $position => $row) {
            $id = is_object($row) ? self::positiveInteger($row->ID ?? null) : null;
            $stringFields = [
                'post_date', 'post_date_gmt', 'post_content', 'post_title', 'post_excerpt', 'post_status',
                'comment_status', 'ping_status', 'post_password', 'post_name', 'post_modified',
                'post_modified_gmt', 'post_type', 'post_mime_type',
            ];
            $validStrings = is_object($row);
            foreach ($stringFields as $field) {
                $validStrings = $validStrings && is_string($row->{$field} ?? null);
            }
            if ($id === null
                || self::nonnegativeInteger(is_object($row) ? ($row->post_author ?? null) : null) === null
                || self::nonnegativeInteger(is_object($row) ? ($row->post_parent ?? null) : null) === null
                || self::integer(is_object($row) ? ($row->menu_order ?? null) : null) === null
                || !$validStrings
                || isset($seen[$id])) {
                throw new \RuntimeException("wprism: post discovery returned a malformed/duplicate row at position $position");
            }
            $seen[$id] = true;
        }
        return $rows;
    }

    /**
     * Find live authored-looking entities that current policy scope would
     * silently omit. Explicit non-authored whole-type rules are exclusions;
     * missing dispositions remain blocking gaps.
     *
     * A candidate is a registered type that is `public`, OR any type a
     * plugin registered at all (`_builtin` false), plus every declared type.
     * `public` alone was the original rule and it missed the commonest
     * authored plugin store: WPForms keeps its forms in `wpforms`
     * (public:false, show_ui:false — its UI is a custom admin page), and the
     * T6 adapter walk found capture accepting a site with two forms in it and
     * no gap (grind_adapter_walk.sh S1). Whether a plugin type is authored is
     * exactly what WPrism does not know until a rule names it, so it is a gap
     * until one does; a manifest or site policy that classes it `runtime`
     * excludes it in one line. Core's own non-public types (revisions,
     * changesets, oembed caches…) are `_builtin` and stay out of the gate.
     *
     * @return array<string,array{entities:int}> keyed post_type:<name> or taxonomy:<name>
     */
    public function gaps(): array {
        global $wpdb;

        $publicPostTypes = self::runtimeRoster(
            get_post_types(['public' => true], 'names'),
            'public post-type registry',
            20
        );
        $pluginPostTypes = self::runtimeRoster(
            get_post_types(['_builtin' => false], 'names'),
            'plugin post-type registry',
            20
        );
        $postCandidates = array_fill_keys(array_unique(array_merge(
            $publicPostTypes,
            $pluginPostTypes,
            $this->policy->declared_post_types()
        )), true);
        $scopedPostTypes = array_fill_keys($this->policy->post_types(), true);
        $postCounts = $this->checkedRows(
            "SELECT post_type, COUNT(*) AS entities FROM {$wpdb->posts}
             WHERE (post_status IN ('publish','draft','pending','private','future')
                    OR (post_type = 'attachment' AND post_status = 'inherit'))
             GROUP BY post_type ORDER BY post_type ASC LIMIT " . (self::MAX_SCOPE_TYPES + 1),
            'post-type gap discovery',
            ARRAY_A
        );
        if (count($postCounts) > self::MAX_SCOPE_TYPES) {
            throw new \RuntimeException('wprism: post-type gap discovery exceeds the bounded type limit');
        }

        $out = [];
        $seenPostTypes = [];
        foreach ($postCounts as $position => $row) {
            $name = is_array($row) ? ($row['post_type'] ?? null) : null;
            $entities = is_array($row) ? self::positiveInteger($row['entities'] ?? null) : null;
            if (!is_string($name)
                || preg_match('/^[A-Za-z0-9_-]{1,20}$/D', $name) !== 1
                || $entities === null
                || isset($seenPostTypes[$name])) {
                throw new \RuntimeException(
                    "wprism: post-type gap discovery returned a malformed/duplicate row at position $position"
                );
            }
            $seenPostTypes[$name] = true;
            if (!isset($postCandidates[$name]) || isset($scopedPostTypes[$name])) {
                continue;
            }
            $class = $this->policy->post_type_rule_details($name)['rule']['class'] ?? null;
            if ($class !== null && $class !== 'authored') {
                continue;
            }
            $out["post_type:$name"] = ['entities' => $entities];
        }

        $publicTaxonomies = self::runtimeRoster(
            get_taxonomies(['public' => true], 'names'),
            'public taxonomy registry',
            32
        );
        $pluginTaxonomies = self::runtimeRoster(
            get_taxonomies(['_builtin' => false], 'names'),
            'plugin taxonomy registry',
            32
        );
        $taxCandidates = array_fill_keys(array_unique(array_merge(
            $publicTaxonomies,
            $pluginTaxonomies,
            $this->policy->declared_taxonomies()
        )), true);
        $scopedTaxonomies = array_fill_keys($this->policy->taxonomies(), true);
        $taxCounts = $this->checkedRows(
            "SELECT taxonomy, COUNT(*) AS entities FROM {$wpdb->term_taxonomy} "
            . 'GROUP BY taxonomy ORDER BY taxonomy ASC LIMIT ' . (self::MAX_SCOPE_TYPES + 1),
            'taxonomy gap discovery',
            ARRAY_A
        );
        if (count($taxCounts) > self::MAX_SCOPE_TYPES) {
            throw new \RuntimeException('wprism: taxonomy gap discovery exceeds the bounded type limit');
        }
        $seenTaxonomies = [];
        foreach ($taxCounts as $position => $row) {
            $name = is_array($row) ? ($row['taxonomy'] ?? null) : null;
            $entities = is_array($row) ? self::positiveInteger($row['entities'] ?? null) : null;
            if (!is_string($name)
                || preg_match('/^[A-Za-z0-9_-]{1,32}$/D', $name) !== 1
                || $entities === null
                || isset($seenTaxonomies[$name])) {
                throw new \RuntimeException(
                    "wprism: taxonomy gap discovery returned a malformed/duplicate row at position $position"
                );
            }
            $seenTaxonomies[$name] = true;
            if (!isset($taxCandidates[$name]) || isset($scopedTaxonomies[$name])) {
                continue;
            }
            $class = $this->policy->taxonomy_rule_details($name)['rule']['class'] ?? null;
            if ($class !== null && $class !== 'authored') {
                continue;
            }
            $out["taxonomy:$name"] = ['entities' => $entities];
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
        $termBytes = 'OCTET_LENGTH(CAST(t.term_id AS CHAR)) + OCTET_LENGTH(COALESCE(t.name,\'\')) '
            . '+ OCTET_LENGTH(COALESCE(t.slug,\'\')) + OCTET_LENGTH(CAST(t.term_group AS CHAR)) '
            . '+ OCTET_LENGTH(CAST(tt.term_taxonomy_id AS CHAR)) + OCTET_LENGTH(COALESCE(tt.taxonomy,\'\')) '
            . '+ OCTET_LENGTH(COALESCE(tt.description,\'\')) + OCTET_LENGTH(CAST(tt.parent AS CHAR))';
        $stats = $this->checkedRows(
            'SELECT COUNT(*) AS row_count, '
            . "COALESCE(SUM($termBytes), 0) AS total_bytes, "
            . "COALESCE(MAX($termBytes), 0) AS max_row_bytes "
            . "FROM {$wpdb->terms} t JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id "
            . "WHERE tt.taxonomy IN ($in)",
            'term discovery size preflight',
            ARRAY_A
        );
        [$rowCount, $totalBytes, $maxRowBytes] = $this->statistics($stats, 'term discovery');
        if ($rowCount > self::MAX_TERMS
            || $totalBytes > self::MAX_TERM_BYTES
            || $maxRowBytes > self::MAX_ENTITY_ROW_BYTES) {
            throw new \RuntimeException('wprism: term discovery exceeds its bounded row/byte frontier');
        }
        self::assertAllocationFrontier($rowCount, $totalBytes, 'term discovery');
        $rows = $this->checkedRows(
            "SELECT t.term_id, t.name, t.slug, t.term_group, tt.term_taxonomy_id, tt.taxonomy, tt.description, tt.parent
             FROM {$wpdb->terms} t JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
             WHERE tt.taxonomy IN ($in) ORDER BY t.term_id ASC, tt.term_taxonomy_id ASC LIMIT "
                . (self::MAX_TERMS + 1),
            'term discovery'
        );
        if (count($rows) !== $rowCount) {
            throw new \RuntimeException('wprism: term discovery changed after its bounded size preflight');
        }
        $seen = [];
        foreach ($rows as $position => $row) {
            $termId = is_object($row) ? self::positiveInteger($row->term_id ?? null) : null;
            $ttId = is_object($row) ? self::positiveInteger($row->term_taxonomy_id ?? null) : null;
            $parent = is_object($row) ? self::nonnegativeInteger($row->parent ?? null) : null;
            if ($termId === null
                || $ttId === null
                || $parent === null
                || !is_string($row->name ?? null)
                || !is_string($row->slug ?? null)
                || !is_string($row->taxonomy ?? null)
                || !is_string($row->description ?? null)
                || isset($seen[$ttId])) {
                throw new \RuntimeException("wprism: term discovery returned a malformed/duplicate row at position $position");
            }
            $seen[$ttId] = true;
        }
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
            // A taxonomy can be in policy scope without being registered in
            // this request: a pattern-matched name before its plugin's
            // `init` caught up, or — under the isolated control bootstrap
            // refresh-export runs in — every plugin taxonomy, because no
            // plugin is loaded there at all. The manifest-owned registration
            // declaration (`taxonomies.<tax>.object_type`, then a matching
            // `taxonomy_patterns` object_type) is the only accepted
            // fallback; names without that evidence remain unknown below.
            $objectTypes = $taxonomyObject !== false
                ? (array) $taxonomyObject->object_type
                : $this->policy->declared_object_type($taxonomy);
            if ($objectTypes === null) {
                if ($strictReadOnly) {
                    throw new \RuntimeException(
                        "wprism: refresh export refused — taxonomy '$taxonomy' is in policy scope but is not registered "
                        . 'under the isolated control bootstrap, and no plugin-owned taxonomies.<name>.object_type '
                        . 'or taxonomy_patterns object_type declaration can prove which post or term relationships '
                        . 'belong to it; add that manifest/provider contract before refreshing production'
                    );
                }
                ($this->warn)(
                    "taxonomy '$taxonomy' is in policy scope but not registered on this environment"
                    . ' (plugin inactive?) — cannot determine which object type its relationships'
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

    /** @return list<mixed> */
    private function checkedRows(string $sql, string $purpose, mixed $mode = null): array {
        global $wpdb;
        $wpdb->last_error = '';
        $rows = $mode === null ? $wpdb->get_results($sql) : $wpdb->get_results($sql, $mode);
        $queryError = trim((string) ($wpdb->last_error ?? ''));
        ($this->readCheckpoint)();
        if (!is_array($rows)
            || !array_is_list($rows)
            || $queryError !== '') {
            throw new \RuntimeException("wprism: $purpose read failed");
        }
        return $rows;
    }

    /** @return array{int,int,int} */
    private function statistics(array $rows, string $purpose): array {
        if (count($rows) !== 1 || !is_array($rows[0])) {
            throw new \RuntimeException("wprism: $purpose size preflight returned malformed statistics");
        }
        $row = $rows[0];
        $count = self::nonnegativeInteger($row['row_count'] ?? null);
        $total = self::nonnegativeInteger($row['total_bytes'] ?? null);
        $max = self::nonnegativeInteger($row['max_row_bytes'] ?? null);
        if (array_keys($row) !== ['row_count', 'total_bytes', 'max_row_bytes']
            || $count === null || $total === null || $max === null) {
            throw new \RuntimeException("wprism: $purpose size preflight returned malformed statistics");
        }
        return [$count, $total, $max];
    }

    private static function positiveInteger(mixed $value): ?int {
        $integer = self::nonnegativeInteger($value);
        return $integer !== null && $integer > 0 ? $integer : null;
    }

    private static function nonnegativeInteger(mixed $value): ?int {
        if (is_int($value)) return $value >= 0 ? $value : null;
        if (!is_string($value) || preg_match('/^(?:0|[1-9][0-9]*)$/D', $value) !== 1) return null;
        $integer = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        return is_int($integer) ? $integer : null;
    }

    private static function integer(mixed $value): ?int {
        if (is_int($value)) return $value;
        if (!is_string($value) || preg_match('/^(?:0|-?[1-9][0-9]*)$/D', $value) !== 1) return null;
        $integer = filter_var($value, FILTER_VALIDATE_INT);
        return is_int($integer) ? $integer : null;
    }

    /** @return list<string> */
    private static function runtimeRoster(mixed $value, string $purpose, int $maxBytes): array {
        if (!is_array($value) || count($value) > self::MAX_SCOPE_TYPES) {
            throw new \RuntimeException("wprism: $purpose is malformed or exceeds the bounded type limit");
        }
        $seen = [];
        foreach ($value as $position => $name) {
            // WordPress's documented `names` output is name=>name, while
            // focused fakes historically returned a list. Both are admitted
            // only after exact key/value identity is established; a mixed or
            // aliased registry can never silently widen capture scope.
            if (!array_is_list($value)
                && (!is_string($position) || !is_string($name) || !hash_equals($position, $name))) {
                throw new \RuntimeException("wprism: $purpose contains a malformed native name map");
            }
            if (!is_string($name)
                || preg_match('/^[A-Za-z0-9_-]{1,' . $maxBytes . '}$/D', $name) !== 1
                || isset($seen[$name])) {
                throw new \RuntimeException(
                    "wprism: $purpose contains a malformed/duplicate name at position $position"
                );
            }
            $seen[$name] = true;
        }
        return array_keys($seen);
    }

    /**
     * `get_results()` materializes both wpdb row objects and their scalar
     * fields before this class can inspect them. SQL byte totals alone are
     * therefore not an allocation bound. Budget twice the transferred bytes
     * plus 4 KiB per row (measured comfortably above the pinned wpdb object's
     * zval/hash-table footprint), then require both an absolute 256 MiB cap
     * and half of the PHP process's currently available memory. This rejects
     * million-row/low-memory sources before the payload SELECT.
     */
    private static function assertAllocationFrontier(int $rowCount, int $totalBytes, string $purpose): void {
        if ($rowCount < 0 || $totalBytes < 0
            || $rowCount > intdiv(PHP_INT_MAX, self::ESTIMATED_ROW_OVERHEAD_BYTES)
            || $totalBytes > intdiv(PHP_INT_MAX, 2)) {
            throw new \RuntimeException("wprism: $purpose allocation frontier overflowed");
        }
        $estimated = ($rowCount * self::ESTIMATED_ROW_OVERHEAD_BYTES) + ($totalBytes * 2);
        $budget = self::MAX_CAPTURE_ALLOCATION_BYTES;
        $limit = self::memoryLimitBytes((string) ini_get('memory_limit'));
        if ($limit !== null) {
            $available = max(0, $limit - memory_get_usage(true));
            $budget = min($budget, intdiv($available, 2));
        }
        if ($estimated > $budget) {
            throw new \RuntimeException("wprism: $purpose exceeds the bounded PHP allocation frontier");
        }
    }

    private static function memoryLimitBytes(string $raw): ?int {
        $raw = trim($raw);
        if ($raw === '' || $raw === '-1') return null;
        if (preg_match('/^([1-9][0-9]*)([KMG]?)$/Di', $raw, $match) !== 1) return 0;
        $base = filter_var($match[1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!is_int($base)) return 0;
        $powers = ['' => 1, 'K' => 1024, 'M' => 1048576, 'G' => 1073741824];
        $factor = $powers[strtoupper($match[2])] ?? null;
        if (!is_int($factor) || $base > intdiv(PHP_INT_MAX, $factor)) return 0;
        return $base * $factor;
    }
}
