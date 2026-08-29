<?php
namespace WPrism\Providers;

use WPrism\ManifestProviderRuntime;
use WPrism\WpCliChildProcess;

if (!class_exists(WpCliChildProcess::class, false)) {
    $wprismLayoutRoot = dirname(__DIR__, 5);
    $wprismAgentRoot = is_dir($wprismLayoutRoot . '/agent/src')
        ? $wprismLayoutRoot . '/agent'
        : (basename($wprismLayoutRoot) === 'agent' && is_dir($wprismLayoutRoot . '/src') ? $wprismLayoutRoot : null);
    if ($wprismAgentRoot === null) {
        throw new \RuntimeException('wprism: Yoast provider cannot resolve the explicit source or embedded agent layout');
    }
    require_once $wprismAgentRoot . '/src/Kernel/WpCliChildProcess.php';
    unset($wprismLayoutRoot, $wprismAgentRoot);
}

/**
 * Yoast SEO indexable rebuild provider.
 *
 * Yoast's exact 28.x command owns four projections: indexables, hierarchy,
 * primary terms, and SEO links. The command truncates the first two before it
 * rebuilds anything, so this provider verifies all four schemas before that
 * boundary and binds the receipt to relational postconditions afterward.
 */
final class YoastIndex extends ManifestProviderRuntime {
    private const COMMAND = 'yoast index --reindex --skip-confirmation';
    private const INDEXABLE_TABLE = 'yoast_indexable';
    private const HIERARCHY_TABLE = 'yoast_indexable_hierarchy';
    private const PRIMARY_TERM_TABLE = 'yoast_primary_term';
    private const SEO_LINKS_TABLE = 'yoast_seo_links';

    /** @var array<string,list<string>> */
    private const REQUIRED_COLUMNS = [
        self::INDEXABLE_TABLE => [
            'id', 'object_id', 'object_type', 'object_sub_type', 'permalink', 'permalink_hash', 'link_count',
        ],
        self::HIERARCHY_TABLE => ['indexable_id', 'ancestor_id', 'depth'],
        self::PRIMARY_TERM_TABLE => ['post_id', 'term_id', 'taxonomy', 'blog_id'],
        self::SEO_LINKS_TABLE => [
            'post_id', 'indexable_id', 'target_indexable_id', 'target_post_id', 'type', 'url',
        ],
    ];

    /** @return array{before:array,after:array,verified:true} */
    protected function invoke_reindex(array $args): array {
        if (!class_exists('\WP_CLI')) {
            throw new \RuntimeException(
                "wprism: Yoast reindex runs the plugin's own '" . self::COMMAND
                . "' command and is unavailable outside wp-cli"
            );
        }

        // Validate before Yoast's --reindex path truncates indexables and
        // hierarchy. Schema drift is an incompatible migration state, not an
        // empty derived cache that may be guessed safe.
        $before = $this->projection_snapshot(false);
        try {
            $result = WpCliChildProcess::capture(self::COMMAND, 600, 524288, 131072);
        } catch (\Throwable $t) {
            throw new \RuntimeException(
                "wprism: Yoast '" . self::COMMAND . "' could not start",
                0,
                $t
            );
        }
        if ($result['return_code'] !== 0) {
            throw new \RuntimeException(
                "wprism: Yoast '" . self::COMMAND . "' exited {$result['return_code']}"
            );
        }
        if (trim($result['stderr']) !== '') {
            throw new \RuntimeException(
                "wprism: Yoast '" . self::COMMAND . "' emitted stderr despite exit 0; recovery_required"
            );
        }

        $after = $this->projection_snapshot(true);
        if ($before['indexing_enabled'] !== $after['indexing_enabled']) {
            throw new \RuntimeException(
                'wprism: Yoast indexability changed during reindex; recovery_required'
            );
        }
        return ['before' => $before, 'after' => $after, 'verified' => true];
    }

    /** @return array<string,mixed> */
    protected function reconcile_reindex(array $args): array {
        return $this->projection_snapshot(true);
    }

    /**
     * One bounded projection of every table the exact command writes. Counts
     * are context; the verification is the missing/invalid row inventory that
     * binds each derived row back to posts, primary-term metadata, hierarchy,
     * and Yoast's own link-index completion marker.
     *
     * @return array<string,int|string|bool>
     */
    private function projection_snapshot(bool $verify): array {
        $this->assert_projection_schema();
        $enabled = $this->indexing_enabled();
        $postTypes = $this->indexable_post_types();
        $snapshot = [
            'environment_type' => function_exists('wp_get_environment_type')
                ? (string) wp_get_environment_type()
                : 'unknown',
            'indexing_enabled' => $enabled,
            'public_posts' => $this->public_post_count($postTypes),
            'indexables' => $this->table_count(self::INDEXABLE_TABLE),
            'indexed_public_posts' => $this->indexed_public_post_count($postTypes),
            'hierarchy_rows' => $this->table_count(self::HIERARCHY_TABLE),
            'invalid_hierarchy_rows' => $this->invalid_hierarchy_count(),
            'expected_primary_terms' => $this->expected_primary_term_count(),
            'primary_term_rows' => $this->table_count(self::PRIMARY_TERM_TABLE),
            'missing_primary_terms' => $this->missing_primary_term_count(),
            'invalid_primary_terms' => $this->invalid_primary_term_count(),
            'seo_link_rows' => $this->table_count(self::SEO_LINKS_TABLE),
            'unindexed_public_post_links' => $this->unindexed_public_post_link_count($postTypes),
            'invalid_seo_link_rows' => $this->invalid_seo_link_count(),
            'outcome' => $enabled
                ? 'reindexed'
                : 'no-op (Yoast indexables are disabled by the plugin on this request)',
        ];

        if ($verify && $enabled) {
            if ($snapshot['indexed_public_posts'] !== $snapshot['public_posts']) {
                throw new \RuntimeException(
                    "wprism: Yoast indexable readback covers {$snapshot['indexed_public_posts']} of "
                    . "{$snapshot['public_posts']} public post(s); recovery_required"
                );
            }
            foreach ([
                'invalid_hierarchy_rows',
                'missing_primary_terms',
                'invalid_primary_terms',
                'unindexed_public_post_links',
                'invalid_seo_link_rows',
            ] as $field) {
                if ($snapshot[$field] !== 0) {
                    throw new \RuntimeException(
                        "wprism: Yoast projection readback found {$snapshot[$field]} $field; recovery_required"
                    );
                }
            }
        }
        return $snapshot;
    }

    private function assert_projection_schema(): void {
        global $wpdb;
        foreach (self::REQUIRED_COLUMNS as $suffix => $required) {
            $table = $wpdb->prefix . $suffix;
            $wpdb->last_error = '';
            $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
            if ((string) ($wpdb->last_error ?? '') !== '') {
                throw new \RuntimeException("wprism: Yoast projection table probe failed for $table");
            }
            if ((string) $exists !== $table) {
                throw new \RuntimeException("wprism: Yoast projection table $table is missing; recovery_required");
            }
            $wpdb->last_error = '';
            $columns = $wpdb->get_col("SHOW COLUMNS FROM `$table`");
            if ((string) ($wpdb->last_error ?? '') !== '' || !is_array($columns)) {
                throw new \RuntimeException("wprism: Yoast projection schema probe failed for $table");
            }
            $missing = array_values(array_diff($required, array_map('strval', $columns)));
            if ($missing !== []) {
                throw new \RuntimeException(
                    "wprism: Yoast projection table $table is missing required column(s): "
                    . implode(', ', $missing) . '; recovery_required'
                );
            }
        }
    }

    private function indexing_enabled(): bool {
        if (!function_exists('YoastSEO')) {
            throw new \RuntimeException('wprism: Yoast indexable helper is unavailable; recovery_required');
        }
        try {
            $helper = \YoastSEO()->helpers->indexable;
        } catch (\Throwable $t) {
            throw new \RuntimeException('wprism: Yoast indexable helper is unavailable; recovery_required', 0, $t);
        }
        if (!is_object($helper) || !is_callable([$helper, 'should_index_indexables'])) {
            throw new \RuntimeException('wprism: Yoast indexable helper has an incompatible API; recovery_required');
        }
        return (bool) $helper->should_index_indexables();
    }

    /** @return list<string> */
    private function indexable_post_types(): array {
        try {
            $helper = \YoastSEO()->helpers->post_type;
            $types = $helper->get_indexable_post_types();
        } catch (\Throwable $t) {
            throw new \RuntimeException('wprism: Yoast post-type helper is unavailable; recovery_required', 0, $t);
        }
        if (!is_array($types) || $types === []) {
            throw new \RuntimeException('wprism: Yoast post-type helper returned no indexable post types; recovery_required');
        }
        $types = array_values(array_unique(array_map('strval', $types)));
        foreach ($types as $type) {
            if (preg_match('/^[a-z0-9_-]+$/', $type) !== 1) {
                throw new \RuntimeException('wprism: Yoast post-type helper returned an invalid post type; recovery_required');
            }
        }
        sort($types, SORT_STRING);
        return $types;
    }

    private function table_count(string $suffix): int {
        global $wpdb;
        return $this->checked_count(
            "SELECT COUNT(*) FROM `{$wpdb->prefix}$suffix`",
            "$suffix row count"
        );
    }

    /** @param list<string> $postTypes */
    private function public_post_count(array $postTypes): int {
        global $wpdb;
        $in = implode(', ', array_fill(0, count($postTypes), '%s'));
        return $this->checked_count(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ($in)",
                ...$postTypes
            ),
            'public post count'
        );
    }

    /** @param list<string> $postTypes */
    private function indexed_public_post_count(array $postTypes): int {
        global $wpdb;
        $indexable = $wpdb->prefix . self::INDEXABLE_TABLE;
        $in = implode(', ', array_fill(0, count($postTypes), '%s'));
        return $this->checked_count(
            $wpdb->prepare(
                "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p INNER JOIN `$indexable` i "
                . "ON i.object_id = p.ID AND i.object_type = 'post' "
                . "WHERE p.post_status = 'publish' AND p.post_type IN ($in)",
                ...$postTypes
            ),
            'indexed public post count'
        );
    }

    private function invalid_hierarchy_count(): int {
        global $wpdb;
        $hierarchy = $wpdb->prefix . self::HIERARCHY_TABLE;
        $indexable = $wpdb->prefix . self::INDEXABLE_TABLE;
        return $this->checked_count(
            "SELECT COUNT(*) FROM `$hierarchy` h LEFT JOIN `$indexable` child ON child.id = h.indexable_id "
            . "LEFT JOIN `$indexable` ancestor ON ancestor.id = h.ancestor_id "
            . 'WHERE child.id IS NULL OR (h.ancestor_id <> 0 AND ancestor.id IS NULL)',
            'invalid hierarchy count'
        );
    }

    private function expected_primary_term_count(): int {
        global $wpdb;
        return $this->checked_count(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id "
            . "WHERE pm.meta_key LIKE '\\_yoast\\_wpseo\\_primary\\_%' "
            . "AND pm.meta_value REGEXP '^[1-9][0-9]*$'",
            'expected primary-term count'
        );
    }

    private function missing_primary_term_count(): int {
        global $wpdb;
        $primary = $wpdb->prefix . self::PRIMARY_TERM_TABLE;
        return $this->checked_count(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id "
            . "WHERE pm.meta_key LIKE '\\_yoast\\_wpseo\\_primary\\_%' "
            . "AND pm.meta_value REGEXP '^[1-9][0-9]*$' AND NOT EXISTS ("
            . "SELECT 1 FROM `$primary` pt WHERE pt.post_id = pm.post_id "
            . "AND pt.term_id = CAST(pm.meta_value AS UNSIGNED) "
            . "AND pt.taxonomy = SUBSTRING(pm.meta_key, CHAR_LENGTH('_yoast_wpseo_primary_') + 1))",
            'missing primary-term count'
        );
    }

    private function invalid_primary_term_count(): int {
        global $wpdb;
        $primary = $wpdb->prefix . self::PRIMARY_TERM_TABLE;
        return $this->checked_count(
            "SELECT COUNT(*) FROM `$primary` pt LEFT JOIN {$wpdb->posts} p ON p.ID = pt.post_id "
            . "LEFT JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = pt.term_id AND tt.taxonomy = pt.taxonomy "
            . 'WHERE p.ID IS NULL OR tt.term_taxonomy_id IS NULL',
            'invalid primary-term count'
        );
    }

    /** @param list<string> $postTypes */
    private function unindexed_public_post_link_count(array $postTypes): int {
        global $wpdb;
        $indexable = $wpdb->prefix . self::INDEXABLE_TABLE;
        $in = implode(', ', array_fill(0, count($postTypes), '%s'));
        return $this->checked_count(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->posts} p INNER JOIN `$indexable` i "
                . "ON i.object_id = p.ID AND i.object_type = 'post' "
                . "WHERE p.post_status = 'publish' AND p.post_type IN ($in) AND i.link_count IS NULL",
                ...$postTypes
            ),
            'unindexed public-post link count'
        );
    }

    private function invalid_seo_link_count(): int {
        global $wpdb;
        $links = $wpdb->prefix . self::SEO_LINKS_TABLE;
        $indexable = $wpdb->prefix . self::INDEXABLE_TABLE;
        return $this->checked_count(
            "SELECT COUNT(*) FROM `$links` links LEFT JOIN `$indexable` source ON source.id = links.indexable_id "
            . 'WHERE links.indexable_id IS NULL OR source.id IS NULL',
            'invalid SEO-link count'
        );
    }

    private function checked_count(string $query, string $context): int {
        global $wpdb;
        $wpdb->last_error = '';
        $count = $wpdb->get_var($query);
        if ($count === null || (string) ($wpdb->last_error ?? '') !== '') {
            throw new \RuntimeException("wprism: Yoast $context query failed; recovery_required");
        }
        return (int) $count;
    }
}
